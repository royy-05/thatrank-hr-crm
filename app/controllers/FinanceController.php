<?php
// app/controllers/FinanceController.php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\Payslip;
use App\Models\Setting;
use App\Services\PayrollPdfService;
use PDO;

class FinanceController {
    /**
     * Get finances dashboard data for the authenticated employee.
     */
    public static function getMyFinances(): void {
        $user = AuthMiddleware::requireLogin();
        $userId = (int)$user['id'];

        $settings = Setting::getAllAsMap();
        $currency = $settings['currency_symbol'] ?? 'INR';
        $fyStartMonth = (int)($settings['financial_year_start_month'] ?? 4);

        // Determine current Financial Year
        $currentMonth = (int)date('n');
        $currentYear = (int)date('Y');
        $defaultFy = ($currentMonth >= $fyStartMonth) ? $currentYear : ($currentYear - 1);

        $selectedFy = isset($_GET['fy']) ? (int)$_GET['fy'] : $defaultFy;

        // Fetch latest published payslip
        $latest = Payslip::getLatestPublished($userId);

        // Fetch published payslips for the selected FY
        $fyPayslips = Payslip::getPublishedForUser($userId, $selectedFy, $fyStartMonth);

        // Selected month breakdown payslip
        $selectedSlip = null;
        if (isset($_GET['payslip_id'])) {
            $slipId = (int)$_GET['payslip_id'];
            $cand = Payslip::getById($slipId);
            if ($cand && (int)$cand['user_id'] === $userId && $cand['status'] === 'published') {
                $selectedSlip = $cand;
            }
        } elseif (isset($_GET['month']) && isset($_GET['year'])) {
            $cand = Payslip::getByUserMonth($userId, (int)$_GET['month'], (int)$_GET['year']);
            if ($cand && $cand['status'] === 'published') {
                $selectedSlip = $cand;
            }
        }

        if (!$selectedSlip) {
            $selectedSlip = !empty($fyPayslips) ? $fyPayslips[0] : $latest;
        }

        // Top cards data
        $latestNet = $latest ? (float)$latest['net_pay'] : 0.0;
        $latestGross = $latest ? (float)$latest['gross'] : 0.0;
        $latestDeductions = $latest ? ((float)$latest['total_deductions'] + (float)$latest['lop_amount']) : 0.0;
        $latestMonthLabel = $latest ? date('M Y', strtotime(sprintf('%04d-%02d-01', $latest['year'], $latest['month']))) : 'No records';

        // Next payday calculation
        $paydaySetting = min(31, max(1, (int)($settings['payroll_payday'] ?? 30)));
        $todayObj = new \DateTime('today');
        $thisMonthDays = (int)$todayObj->format('t');
        $thisMonthPaydayDay = min($paydaySetting, $thisMonthDays);

        $thisMonthPayday = new \DateTime($todayObj->format('Y-m-') . str_pad((string)$thisMonthPaydayDay, 2, '0', STR_PAD_LEFT));

        if ($todayObj < $thisMonthPayday) {
            $nextPaydayObj = $thisMonthPayday;
        } else {
            // Next month payday
            $nextMonthObj = (clone $todayObj)->modify('first day of next month');
            $nextMonthDays = (int)$nextMonthObj->format('t');
            $nextMonthPaydayDay = min($paydaySetting, $nextMonthDays);
            $nextPaydayObj = new \DateTime($nextMonthObj->format('Y-m-') . str_pad((string)$nextMonthPaydayDay, 2, '0', STR_PAD_LEFT));
        }

        $daysRemaining = (int)$todayObj->diff($nextPaydayObj)->format('%r%a');
        $daysRemaining = max(0, $daysRemaining);

        // Tax summary
        $taxSummary = Payslip::getTaxSummary($userId, $selectedFy, $fyStartMonth);

        // Available FY list for dropdown
        $allSlips = Payslip::getPublishedForUser($userId);
        $availableFys = [];
        $availableFys[$defaultFy] = sprintf('FY %d-%02d', $defaultFy, ($defaultFy + 1) % 100);

        foreach ($allSlips as $s) {
            $sMonth = (int)$s['month'];
            $sYear = (int)$s['year'];
            $sFy = ($sMonth >= $fyStartMonth) ? $sYear : ($sYear - 1);
            $availableFys[$sFy] = sprintf('FY %d-%02d', $sFy, ($sFy + 1) % 100);
        }
        krsort($availableFys);

        jsonResponse([
            'success'          => true,
            'currency'         => $currency,
            'top_cards'        => [
                'latest_net'       => $latestNet,
                'latest_gross'     => $latestGross,
                'latest_deductions'=> $latestDeductions,
                'latest_month'     => $latestMonthLabel,
                'next_payday'      => $nextPaydayObj->format('d M Y'),
                'days_remaining'   => $daysRemaining,
            ],
            'selected_payslip' => $selectedSlip,
            'fy_payslips'      => $fyPayslips,
            'tax_summary'      => $taxSummary,
            'available_fys'    => $availableFys,
            'selected_fy'      => $selectedFy,
            'settings'         => [
                'currency_symbol' => $currency,
                'tds_label'       => $settings['tds_label'] ?? 'TDS',
            ],
        ]);
    }

    /**
     * Download payslip PDF on demand with strict ownership / admin authorization.
     */
    public static function downloadPdf(): void {
        $user = AuthMiddleware::requireLogin();
        $payslipId = (int)($_GET['id'] ?? 0);

        if ($payslipId <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid payslip ID.'], 400);
        }

        $payslip = Payslip::getById($payslipId);
        if (!$payslip) {
            jsonResponse(['success' => false, 'error' => 'Payslip not found.'], 404);
        }

        $isAdmin = ($user['role'] === 'admin');
        $isOwner = ((int)$payslip['user_id'] === (int)$user['id']);

        if (!$isAdmin && !$isOwner) {
            jsonResponse(['success' => false, 'error' => 'Forbidden: You do not have permission to access this payslip.'], 403);
        }

        // Non-admins can only see published payslips
        if (!$isAdmin && $payslip['status'] !== 'published') {
            jsonResponse(['success' => false, 'error' => 'Payslip is not yet published.'], 403);
        }

        $settings = Setting::getAllAsMap();
        $empId = 'EMP-' . str_pad((string)$payslip['user_id'], 4, '0', STR_PAD_LEFT);
        $period = sprintf('%04d_%02d', $payslip['year'], $payslip['month']);
        $filename = "Payslip_{$empId}_{$period}.pdf";

        PayrollPdfService::streamPdf($filename, $payslip, $settings);
    }
}

