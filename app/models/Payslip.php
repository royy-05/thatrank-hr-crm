<?php
// app/models/Payslip.php

namespace App\Models;

use App\Config\Database;
use App\Services\AuditService;
use PDO;

class Payslip {
    public static function getById(int $id): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT p.*,
                   u.name as employee_name, u.email as employee_email, u.job_title, u.department,
                   u.joined_date, u.phone, u.avatar_initials, u.avatar_color,
                   t.name as team_name,
                   r.status as run_status, r.payday_date
            FROM payslips p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN teams t ON u.team_id = t.id
            JOIN payroll_runs r ON p.run_id = r.id
            WHERE p.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row = self::decodeJsonFields($row);
        }
        return $row ?: null;
    }

    public static function getByRun(int $runId): array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT p.*,
                   u.name as employee_name, u.email as employee_email, u.job_title, u.department,
                   u.joined_date, u.avatar_initials, u.avatar_color,
                   t.name as team_name
            FROM payslips p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN teams t ON u.team_id = t.id
            WHERE p.run_id = :rid
            ORDER BY u.name ASC
        ");
        $stmt->execute([':rid' => $runId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r = self::decodeJsonFields($r);
        }
        return $rows;
    }

    public static function getByRunAndUser(int $runId, int $userId): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT p.*,
                   u.name as employee_name, u.email as employee_email, u.job_title, u.department,
                   u.joined_date, u.phone, u.avatar_initials, u.avatar_color,
                   t.name as team_name,
                   r.status as run_status, r.payday_date
            FROM payslips p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN teams t ON u.team_id = t.id
            JOIN payroll_runs r ON p.run_id = r.id
            WHERE p.run_id = :rid AND p.user_id = :uid
            LIMIT 1
        ");
        $stmt->execute([':rid' => $runId, ':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row = self::decodeJsonFields($row);
        }
        return $row ?: null;
    }

    public static function getByUserMonth(int $userId, int $month, int $year): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT p.*,
                   u.name as employee_name, u.email as employee_email, u.job_title, u.department,
                   u.joined_date, u.phone, u.avatar_initials, u.avatar_color,
                   t.name as team_name,
                   r.status as run_status, r.payday_date
            FROM payslips p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN teams t ON u.team_id = t.id
            JOIN payroll_runs r ON p.run_id = r.id
            WHERE p.user_id = :uid AND p.month = :m AND p.year = :y
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId, ':m' => $month, ':y' => $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row = self::decodeJsonFields($row);
        }
        return $row ?: null;
    }

    public static function getPublishedForUser(int $userId, ?int $fyStartYear = null, int $fyStartMonth = 4): array {
        $db = Database::getConnection();
        $sql = "
            SELECT p.*,
                   u.name as employee_name, u.email as employee_email, u.job_title,
                   r.payday_date
            FROM payslips p
            JOIN users u ON p.user_id = u.id
            JOIN payroll_runs r ON p.run_id = r.id
            WHERE p.user_id = :uid AND p.status = 'published'
        ";
        $params = [':uid' => $userId];

        if ($fyStartYear !== null) {
            // Determine date bounds for this financial year
            $startDate = sprintf('%04d-%02d-01', $fyStartYear, $fyStartMonth);
            $endDateObj = new \DateTime($startDate);
            $endDateObj->modify('+1 year -1 day');
            $endDate = $endDateObj->format('Y-m-d');

            $sql .= " AND STR_TO_DATE(CONCAT(p.year, '-', LPAD(p.month, 2, '00'), '-01'), '%Y-%m-%d') >= :start_date";
            $sql .= " AND STR_TO_DATE(CONCAT(p.year, '-', LPAD(p.month, 2, '00'), '-01'), '%Y-%m-%d') <= :end_date";
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        }

        $sql .= " ORDER BY p.year DESC, p.month DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r = self::decodeJsonFields($r);
        }
        return $rows;
    }

    public static function getLatestPublished(int $userId): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT p.*,
                   u.name as employee_name, u.email as employee_email, u.job_title, u.department,
                   u.joined_date, u.phone, u.avatar_initials, u.avatar_color,
                   t.name as team_name,
                   r.status as run_status, r.payday_date
            FROM payslips p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN teams t ON u.team_id = t.id
            JOIN payroll_runs r ON p.run_id = r.id
            WHERE p.user_id = :uid AND p.status = 'published'
            ORDER BY p.year DESC, p.month DESC
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row = self::decodeJsonFields($row);
        }
        return $row ?: null;
    }

    public static function getTaxSummary(int $userId, int $fyStartYear, int $fyStartMonth = 4): array {
        $slips = self::getPublishedForUser($userId, $fyStartYear, $fyStartMonth);
        $settings = Setting::getAllAsMap();
        $tdsLabel = $settings['tds_label'] ?? 'TDS';

        $totalTdsDeducted = 0.0;
        $latestMonthlyTds = 0.0;

        foreach ($slips as $s) {
            $deductions = $s['deductions'] ?? [];
            foreach ($deductions as $d) {
                $isTds = (!empty($d['is_statutory']) && $d['is_statutory'] === 'tds') ||
                         (stripos($d['name'] ?? '', 'tds') !== false) ||
                         (stripos($d['name'] ?? '', $tdsLabel) !== false);
                if ($isTds) {
                    $amt = (float)($d['amount'] ?? 0);
                    $totalTdsDeducted += $amt;
                    if ($latestMonthlyTds === 0.0) {
                        $latestMonthlyTds = $amt;
                    }
                }
            }
        }

        $monthsCount = count($slips);
        $remainingMonths = max(0, 12 - $monthsCount);
        $projectedTds = $totalTdsDeducted + ($latestMonthlyTds * $remainingMonths);

        return [
            'fy_year'           => $fyStartYear,
            'fy_label'          => sprintf('FY %d-%02d', $fyStartYear, ($fyStartYear + 1) % 100),
            'deducted_so_far'   => round($totalTdsDeducted, 2),
            'projected_total'   => round($projectedTds, 2),
            'monthly_tds'       => round($latestMonthlyTds, 2),
            'months_completed'  => $monthsCount,
            'months_remaining'  => $remainingMonths,
            'tds_label'         => $tdsLabel,
        ];
    }

    public static function upsert(array $data): int {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            INSERT INTO payslips (
                run_id, user_id, month, year, working_days, paid_days, lop_days, lop_amount,
                earnings, deductions, adjustments, gross, total_deductions, net_pay,
                status, published_at, paid_on, created_at, updated_at
            ) VALUES (
                :run_id, :user_id, :month, :year, :working_days, :paid_days, :lop_days, :lop_amount,
                :earnings, :deductions, :adjustments, :gross, :total_deductions, :net_pay,
                :status, :published_at, :paid_on, NOW(), NOW()
            ) ON DUPLICATE KEY UPDATE
                working_days = VALUES(working_days),
                paid_days = VALUES(paid_days),
                lop_days = VALUES(lop_days),
                lop_amount = VALUES(lop_amount),
                earnings = VALUES(earnings),
                deductions = VALUES(deductions),
                adjustments = VALUES(adjustments),
                gross = VALUES(gross),
                total_deductions = VALUES(total_deductions),
                net_pay = VALUES(net_pay),
                status = VALUES(status),
                published_at = VALUES(published_at),
                paid_on = VALUES(paid_on),
                updated_at = NOW()
        ");

        $earningsJson = is_array($data['earnings']) ? json_encode($data['earnings'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ($data['earnings'] ?? '[]');
        $deductionsJson = is_array($data['deductions']) ? json_encode($data['deductions'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ($data['deductions'] ?? '[]');
        $adjustmentsJson = isset($data['adjustments']) ? (is_array($data['adjustments']) ? json_encode($data['adjustments'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $data['adjustments']) : '[]';

        $stmt->execute([
            ':run_id'           => (int)$data['run_id'],
            ':user_id'          => (int)$data['user_id'],
            ':month'            => (int)$data['month'],
            ':year'             => (int)$data['year'],
            ':working_days'     => (float)($data['working_days'] ?? 0.0),
            ':paid_days'        => (float)($data['paid_days'] ?? 0.0),
            ':lop_days'         => (float)($data['lop_days'] ?? 0.0),
            ':lop_amount'       => (float)($data['lop_amount'] ?? 0.0),
            ':earnings'         => $earningsJson,
            ':deductions'       => $deductionsJson,
            ':adjustments'      => $adjustmentsJson,
            ':gross'            => (float)($data['gross'] ?? 0.0),
            ':total_deductions' => (float)($data['total_deductions'] ?? 0.0),
            ':net_pay'          => (float)($data['net_pay'] ?? 0.0),
            ':status'           => in_array($data['status'] ?? '', ['draft', 'published'], true) ? $data['status'] : 'draft',
            ':published_at'     => $data['published_at'] ?? null,
            ':paid_on'          => $data['paid_on'] ?? null,
        ]);

        $existing = self::getByRunAndUser((int)$data['run_id'], (int)$data['user_id']);
        return $existing ? (int)$existing['id'] : 0;
    }

    public static function updateAdjustments(int $id, array $adjustments, ?float $lopDays, int $actorId): bool {
        $payslip = self::getById($id);
        if (!$payslip) {
            return false;
        }

        // Verify run is not finalized or paid
        $run = PayrollRun::getById((int)$payslip['run_id']);
        if (!$run || $run['status'] === 'finalized' || $run['status'] === 'paid') {
            return false;
        }

        $settings = Setting::getAllAsMap();
        $divisorMode = $settings['lop_divisor'] ?? 'calendar_days';
        $roundingMode = $settings['payroll_rounding'] ?? 'nearest_rupee';

        $month = (int)$payslip['month'];
        $year = (int)$payslip['year'];
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

        $newLopDays = $lopDays !== null ? (float)$lopDays : (float)$payslip['lop_days'];
        $workingDays = (float)$payslip['working_days'];
        $newPaidDays = max(0.0, $workingDays - $newLopDays);

        $divisor = ($divisorMode === 'working_days') ? max(1.0, $workingDays) : max(1.0, (float)$daysInMonth);
        $gross = (float)$payslip['gross'];
        $dailyRate = $gross / $divisor;
        $newLopAmount = round($newLopDays * $dailyRate, 2);

        $totalDeductions = (float)$payslip['total_deductions'];

        // Apply adjustments
        $adjEarnings = 0.0;
        $adjDeductions = 0.0;
        foreach ($adjustments as $adj) {
            $amt = (float)($adj['amount'] ?? 0);
            if (($adj['type'] ?? 'earning') === 'earning') {
                $adjEarnings += $amt;
            } else {
                $adjDeductions += $amt;
            }
        }

        $net = ($gross + $adjEarnings) - $newLopAmount - ($totalDeductions + $adjDeductions);
        if ($roundingMode === 'nearest_rupee') {
            $net = round($net);
        } else {
            $net = round($net, 2);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE payslips
            SET lop_days = :lop_days,
                lop_amount = :lop_amt,
                paid_days = :paid_days,
                adjustments = :adj,
                net_pay = :net,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            ':lop_days'  => $newLopDays,
            ':lop_amt'   => $newLopAmount,
            ':paid_days' => $newPaidDays,
            ':adj'       => json_encode($adjustments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':net'       => $net,
            ':id'        => $id,
        ]);

        $updated = self::getById($id);
        AuditService::log($actorId, 'payslip_adjustments_update', 'payslip', $id, $payslip, $updated);

        return true;
    }

    private static function decodeJsonFields(array $row): array {
        if (isset($row['earnings']) && is_string($row['earnings'])) {
            $row['earnings'] = json_decode($row['earnings'], true) ?: [];
        }
        if (isset($row['deductions']) && is_string($row['deductions'])) {
            $row['deductions'] = json_decode($row['deductions'], true) ?: [];
        }
        if (isset($row['adjustments']) && is_string($row['adjustments'])) {
            $row['adjustments'] = json_decode($row['adjustments'], true) ?: [];
        } elseif (!isset($row['adjustments'])) {
            $row['adjustments'] = [];
        }
        return $row;
    }
}

