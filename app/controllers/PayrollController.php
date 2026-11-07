<?php
// app/controllers/PayrollController.php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PayrollPdfService;
use App\Services\PayrollService;
use PDO;

class PayrollController {
    /**
     * Get overview list of payroll runs and active run details.
     */
    public static function index(): void {
        $user = AuthMiddleware::requireAdmin();

        $runs = PayrollRun::getAll(24);
        $settings = Setting::getAllAsMap();

        // Selected run
        $runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
        $selectedRun = null;
        $payslips = [];

        if ($runId > 0) {
            $selectedRun = PayrollRun::getById($runId);
        } elseif (!empty($runs)) {
            $selectedRun = $runs[0];
            $runId = (int)$selectedRun['id'];
        }

        if ($runId > 0) {
            $payslips = Payslip::getByRun($runId);
        }

        // Calculate next payday from settings
        $paydayDay = min(31, max(1, (int)($settings['payroll_payday'] ?? 30)));
        $todayObj = new \DateTime('today');
        $thisMonthDays = (int)$todayObj->format('t');
        $thisMonthPaydayDay = min($paydayDay, $thisMonthDays);
        $thisMonthPayday = new \DateTime($todayObj->format('Y-m-') . str_pad((string)$thisMonthPaydayDay, 2, '0', STR_PAD_LEFT));

        if ($todayObj <= $thisMonthPayday) {
            $nextPaydayObj = $thisMonthPayday;
        } else {
            $nextMonthObj = (clone $todayObj)->modify('first day of next month');
            $nextMonthDays = (int)$nextMonthObj->format('t');
            $nextMonthPaydayDay = min($paydayDay, $nextMonthDays);
            $nextPaydayObj = new \DateTime($nextMonthObj->format('Y-m-') . str_pad((string)$nextMonthPaydayDay, 2, '0', STR_PAD_LEFT));
        }

        if ($selectedRun && !empty($selectedRun['payday_date'])) {
            $selectedRun['formatted_payday'] = date('d M Y', strtotime($selectedRun['payday_date']));
        }

        jsonResponse([
            'success'      => true,
            'runs'         => $runs,
            'selected_run' => $selectedRun,
            'payslips'     => $payslips,
            'settings'     => $settings,
            'next_payday'  => $nextPaydayObj->format('d M Y'),
            'payday_day'   => $paydayDay,
        ]);
    }

    /**
     * Generate or re-run draft payroll for a specific month.
     */
    public static function generate(): void {
        $user = AuthMiddleware::requireAdmin();
        AuthMiddleware::requireCsrf();

        $raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $month = isset($raw['month']) ? (int)$raw['month'] : (int)date('n');
        $year = isset($raw['year']) ? (int)$raw['year'] : (int)date('Y');

        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            jsonResponse(['success' => false, 'error' => 'Invalid month or year.'], 400);
        }

        try {
            $runId = PayrollService::generateRun($month, $year, (int)$user['id']);
            $run = PayrollRun::getById($runId);
            $payslips = Payslip::getByRun($runId);

            jsonResponse([
                'success'  => true,
                'message'  => 'Payroll generated successfully for ' . date('F Y', strtotime("$year-$month-01")),
                'run'      => $run,
                'payslips' => $payslips,
            ]);
        } catch (\Throwable $e) {
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Finalize payroll run: locks run, publishes payslips per setting, enqueues notifications.
     */
    public static function finalize(): void {
        $user = AuthMiddleware::requireAdmin();
        AuthMiddleware::requireCsrf();

        $raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $runId = (int)($raw['run_id'] ?? 0);

        $run = PayrollRun::getById($runId);
        if (!$run) {
            jsonResponse(['success' => false, 'error' => 'Payroll run not found.'], 404);
        }

        if ($run['status'] === 'finalized' || $run['status'] === 'paid') {
            jsonResponse(['success' => false, 'error' => 'This payroll run is already finalized.'], 400);
        }

        $res = PayrollRun::finalize($runId, (int)$user['id']);
        if ($res) {
            $updatedRun = PayrollRun::getById($runId);
            $payslips = Payslip::getByRun($runId);
            jsonResponse([
                'success'  => true,
                'message'  => 'Payroll run finalized successfully.',
                'run'      => $updatedRun,
                'payslips' => $payslips,
            ]);
        } else {
            jsonResponse(['success' => false, 'error' => 'Failed to finalize payroll run.'], 500);
        }
    }

    /**
     * Mark payroll run as paid.
     */
    public static function markPaid(): void {
        $user = AuthMiddleware::requireAdmin();
        AuthMiddleware::requireCsrf();

        $raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $runId = (int)($raw['run_id'] ?? 0);

        $run = PayrollRun::getById($runId);
        if (!$run) {
            jsonResponse(['success' => false, 'error' => 'Payroll run not found.'], 404);
        }

        $res = PayrollRun::markPaid($runId, (int)$user['id']);
        if ($res) {
            $updatedRun = PayrollRun::getById($runId);
            $payslips = Payslip::getByRun($runId);
            jsonResponse([
                'success'  => true,
                'message'  => 'Payroll marked as paid successfully.',
                'run'      => $updatedRun,
                'payslips' => $payslips,
            ]);
        } else {
            jsonResponse(['success' => false, 'error' => 'Failed to mark payroll as paid.'], 500);
        }
    }

    /**
     * Reopen a finalized payroll run back to draft. Blocked if marked paid.
     */
    public static function reopen(): void {
        $user = AuthMiddleware::requireAdmin();
        AuthMiddleware::requireCsrf();

        $raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $runId = (int)($raw['run_id'] ?? 0);

        $result = PayrollRun::reopen($runId, (int)$user['id']);
        if ($result['success']) {
            $updatedRun = PayrollRun::getById($runId);
            $payslips = Payslip::getByRun($runId);
            jsonResponse([
                'success'  => true,
                'message'  => $result['message'],
                'run'      => $updatedRun,
                'payslips' => $payslips,
            ]);
        } else {
            jsonResponse(['success' => false, 'error' => $result['error']], 400);
        }
    }

    /**
     * Update LOP and adjustments for a single employee's payslip in a draft run.
     */
    public static function updatePayslip(): void {
        $user = AuthMiddleware::requireAdmin();
        AuthMiddleware::requireCsrf();

        $raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $payslipId = (int)($raw['payslip_id'] ?? 0);
        $lopDays = isset($raw['lop_days']) ? (float)$raw['lop_days'] : null;
        $adjustments = isset($raw['adjustments']) && is_array($raw['adjustments']) ? $raw['adjustments'] : [];

        $payslip = Payslip::getById($payslipId);
        if (!$payslip) {
            jsonResponse(['success' => false, 'error' => 'Payslip not found.'], 404);
        }

        $res = Payslip::updateAdjustments($payslipId, $adjustments, $lopDays, (int)$user['id']);
        if ($res) {
            $updated = Payslip::getById($payslipId);
            jsonResponse([
                'success' => true,
                'message' => 'Payslip adjustments updated successfully.',
                'payslip' => $updated,
            ]);
        } else {
            jsonResponse(['success' => false, 'error' => 'Cannot edit payslip in finalized or paid payroll run.'], 400);
        }
    }

    /**
     * Regenerate a single employee in draft run.
     */
    public static function regenerateEmployee(): void {
        $user = AuthMiddleware::requireAdmin();
        AuthMiddleware::requireCsrf();

        $raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $runId = (int)($raw['run_id'] ?? 0);
        $userId = (int)($raw['user_id'] ?? 0);

        try {
            $updated = PayrollService::regenerateEmployee($runId, $userId, (int)$user['id']);
            jsonResponse([
                'success' => true,
                'message' => 'Employee recalculation completed.',
                'payslip' => $updated,
            ]);
        } catch (\Throwable $e) {
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Bank Transfer CSV Export.
     */
    public static function exportBankCsv(): void {
        AuthMiddleware::requireAdmin();
        $runId = (int)($_GET['run_id'] ?? 0);
        $run = PayrollRun::getById($runId);
        if (!$run) {
            jsonResponse(['success' => false, 'error' => 'Payroll run not found.'], 404);
        }

        $csv = PayrollService::exportBankCsv($runId);
        $filename = 'bank_transfer_' . sprintf('%04d_%02d', $run['year'], $run['month']) . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
        exit;
    }

    /**
     * Payroll Summary CSV Export.
     */
    public static function exportSummaryCsv(): void {
        AuthMiddleware::requireAdmin();
        $runId = (int)($_GET['run_id'] ?? 0);
        $run = PayrollRun::getById($runId);
        if (!$run) {
            jsonResponse(['success' => false, 'error' => 'Payroll run not found.'], 404);
        }

        $csv = PayrollService::exportSummaryCsv($runId);
        $filename = 'payroll_summary_' . sprintf('%04d_%02d', $run['year'], $run['month']) . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
        exit;
    }

    /**
     * Payslips ZIP Export.
     */
    public static function exportZip(): void {
        AuthMiddleware::requireAdmin();
        $runId = (int)($_GET['run_id'] ?? 0);
        $run = PayrollRun::getById($runId);
        if (!$run) {
            jsonResponse(['success' => false, 'error' => 'Payroll run not found.'], 404);
        }

        try {
            $zip = PayrollService::exportPayslipsZip($runId);
            $filename = 'payslips_' . sprintf('%04d_%02d', $run['year'], $run['month']) . '.zip';

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($zip));
            echo $zip;
            exit;
        } catch (\Throwable $e) {
            jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Salary Components CRUD.
     */
    public static function getComponents(): void {
        AuthMiddleware::requireAdmin();
        $components = SalaryComponent::getAll();
        jsonResponse([
            'success'    => true,
            'components' => $components,
        ]);
    }

    public static function saveComponent(): void {
        $user = AuthMiddleware::requireAdmin();
        AuthMiddleware::requireCsrf();

        $raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($raw['id'] ?? 0);

        if (empty($raw['name'])) {
            jsonResponse(['success' => false, 'error' => 'Component name is required.'], 400);
        }

        if ($id > 0) {
            $ok = SalaryComponent::update($id, $raw, (int)$user['id']);
            $componentId = $id;
        } else {
            $componentId = SalaryComponent::create($raw, (int)$user['id']);
            $ok = ($componentId > 0);
        }

        if ($ok) {
            jsonResponse([
                'success'   => true,
                'message'   => 'Salary component saved successfully.',
                'component' => SalaryComponent::getById($componentId),
            ]);
        } else {
            jsonResponse(['success' => false, 'error' => 'Failed to save component.'], 400);
        }
    }

    public static function toggleComponentActive(): void {
        $user = AuthMiddleware::requireAdmin();
        AuthMiddleware::requireCsrf();

        $raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $id = (int)($raw['id'] ?? 0);

        $ok = SalaryComponent::toggleActive($id, (int)$user['id']);
        if ($ok) {
            jsonResponse([
                'success'   => true,
                'message'   => 'Status updated successfully.',
                'component' => SalaryComponent::getById($id),
            ]);
        } else {
            jsonResponse(['success' => false, 'error' => 'Component not found.'], 404);
        }
    }

    public static function reorderComponents(): void {
        $user = AuthMiddleware::requireAdmin();
        AuthMiddleware::requireCsrf();

        $raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $orders = $raw['orders'] ?? [];

        $ok = SalaryComponent::reorder($orders, (int)$user['id']);
        if ($ok) {
            jsonResponse([
                'success'    => true,
                'message'    => 'Order updated successfully.',
                'components' => SalaryComponent::getAll(),
            ]);
        } else {
            jsonResponse(['success' => false, 'error' => 'Failed to reorder components.'], 400);
        }
    }

    /**
     * Salary Structures.
     */
    public static function getStructures(): void {
        AuthMiddleware::requireAdmin();

        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT u.id, u.name, u.email, u.job_title, u.department, u.avatar_initials, u.avatar_color,
                   t.name as team_name
            FROM users u
            LEFT JOIN teams t ON u.team_id = t.id
            WHERE u.status = 'active'
            ORDER BY u.name ASC
        ");
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $components = SalaryComponent::getAll(null, true);

        foreach ($employees as &$emp) {
            $breakdown = SalaryStructure::computeEmployeeBreakdown((int)$emp['id']);
            $emp['structure'] = $breakdown['structure'];
            $emp['breakdown'] = $breakdown;
            $emp['current_ctc'] = $breakdown['annual_ctc'];
            $emp['monthly_gross'] = $breakdown['monthly_gross'];
            $emp['net_estimate'] = $breakdown['estimated_net'];
        }
        unset($emp);

        jsonResponse([
            'success'    => true,
            'employees'  => $employees,
            'components' => $components,
        ]);
    }

    public static function getStructureHistory(): void {
        AuthMiddleware::requireAdmin();
        $userId = (int)($_GET['user_id'] ?? 0);
        if ($userId <= 0) {
            jsonResponse(['success' => false, 'error' => 'User ID is required.'], 400);
        }

        $history = SalaryStructure::getHistory($userId);
        jsonResponse([
            'success' => true,
            'history' => $history,
        ]);
    }

    public static function saveStructure(): void {
        $user = AuthMiddleware::requireAdmin();
        AuthMiddleware::requireCsrf();

        $raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $userId = (int)($raw['user_id'] ?? 0);
        $effectiveFrom = trim($raw['effective_from'] ?? date('Y-m-01'));
        $componentValues = $raw['component_values'] ?? [];

        if ($userId <= 0) {
            jsonResponse(['success' => false, 'error' => 'Valid user ID is required.'], 400);
        }

        if (empty($effectiveFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
            jsonResponse(['success' => false, 'error' => 'Valid effective date (YYYY-MM-DD) is required.'], 400);
        }

        $newId = SalaryStructure::setStructure($userId, $effectiveFrom, $componentValues, (int)$user['id']);
        jsonResponse([
            'success'   => true,
            'message'   => 'Salary structure saved successfully.',
            'breakdown' => SalaryStructure::computeEmployeeBreakdown($userId),
        ]);
    }

    public static function bulkSaveStructure(): void {
        $user = AuthMiddleware::requireAdmin();
        AuthMiddleware::requireCsrf();

        $raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $userIds = $raw['user_ids'] ?? [];
        $effectiveFrom = trim($raw['effective_from'] ?? date('Y-m-01'));
        $componentValues = $raw['component_values'] ?? [];

        if (empty($userIds) || !is_array($userIds)) {
            jsonResponse(['success' => false, 'error' => 'Select at least one employee.'], 400);
        }

        if (empty($effectiveFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
            jsonResponse(['success' => false, 'error' => 'Valid effective date (YYYY-MM-DD) is required.'], 400);
        }

        $count = SalaryStructure::bulkSetStructure($userIds, $effectiveFrom, $componentValues, (int)$user['id']);
        jsonResponse([
            'success' => true,
            'message' => "Salary structure assigned to {$count} employees successfully.",
            'count'   => $count,
        ]);
    }

    /**
     * Payroll Settings API.
     */
    public static function getSettings(): void {
        AuthMiddleware::requireAdmin();
        $settings = Setting::getAllAsMap();
        jsonResponse([
            'success'  => true,
            'settings' => $settings,
        ]);
    }

    public static function updateSettings(): void {
        $user = AuthMiddleware::requireAdmin();
        AuthMiddleware::requireCsrf();

        $raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $settingsData = $raw['settings'] ?? $raw;

        // Whitelist allowed settings
        $allowed = [
            'company_legal_name',
            'company_address',
            'payroll_logo',
            'payslip_footer_text',
            'payroll_payday',
            'financial_year_start_month',
            'pf_rate',
            'pf_wage_ceiling_enabled',
            'pf_wage_ceiling',
            'esi_rate',
            'esi_gross_threshold',
            'default_professional_tax',
            'lop_divisor',
            'payroll_rounding',
            'currency_symbol',
            'payslip_visibility',
            'tds_label',
        ];

        $toUpdate = [];
        foreach ($allowed as $k) {
            if (isset($settingsData[$k])) {
                $toUpdate[$k] = trim((string)$settingsData[$k]);
            }
        }

        if (isset($toUpdate['payroll_payday'])) {
            $toUpdate['payroll_payday'] = (string)min(31, max(1, (int)$toUpdate['payroll_payday']));
        }
        if (isset($toUpdate['financial_year_start_month'])) {
            $toUpdate['financial_year_start_month'] = (string)min(12, max(1, (int)$toUpdate['financial_year_start_month']));
        }
        if (isset($toUpdate['lop_divisor']) && !in_array($toUpdate['lop_divisor'], ['calendar_days', 'working_days'], true)) {
            $toUpdate['lop_divisor'] = 'calendar_days';
        }
        if (isset($toUpdate['payroll_rounding']) && !in_array($toUpdate['payroll_rounding'], ['nearest_rupee', 'none'], true)) {
            $toUpdate['payroll_rounding'] = 'nearest_rupee';
        }
        if (isset($toUpdate['payslip_visibility']) && !in_array($toUpdate['payslip_visibility'], ['on_finalize', 'on_paid'], true)) {
            $toUpdate['payslip_visibility'] = 'on_finalize';
        }

        Setting::updateBatch($toUpdate, (int)$user['id']);

        jsonResponse([
            'success'  => true,
            'message'  => 'Payroll settings updated successfully.',
            'settings' => Setting::getAllAsMap(),
        ]);
    }
}

