<?php
// app/services/PayrollService.php

namespace App\Services;

use App\Config\Database;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditService;
use PDO;
use ZipArchive;

class PayrollService {
    /**
     * Compute working days, paid days, and LOP days from real attendance and leave requests.
     */
    public static function calculateAttendanceAndLop(int $userId, int $month, int $year, ?array $settings = null): array {
        $db = Database::getConnection();
        if ($settings === null) {
            $settings = Setting::getAllAsMap();
        }

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);
        $today = date('Y-m-d');

        // User joined date
        $uStmt = $db->prepare("SELECT joined_date, status FROM users WHERE id = :uid LIMIT 1");
        $uStmt->execute([':uid' => $userId]);
        $user = $uStmt->fetch(PDO::FETCH_ASSOC);
        $joinedDate = $user['joined_date'] ?? '2000-01-01';

        // Work week setting
        $workWeek = $settings['work_week'] ?? 'Monday to Friday';
        $sixDayWeek = (stripos($workWeek, 'saturday') !== false);

        // Fetch holidays in month
        $hStmt = $db->prepare("SELECT `date` FROM holidays WHERE `date` BETWEEN :s AND :e");
        $hStmt->execute([':s' => $startDate, ':e' => $endDate]);
        $holidays = $hStmt->fetchAll(PDO::FETCH_COLUMN);
        $holidaysMap = array_flip($holidays);

        // Fetch approved leave requests for user
        $lStmt = $db->prepare("
            SELECT lr.start_date, lr.end_date, lr.days_count, lr.duration_type, lr.leave_type,
                   COALESCE(lt.is_paid, 1) as is_paid
            FROM leave_requests lr
            LEFT JOIN leave_types lt ON lr.leave_type = lt.code
            WHERE lr.user_id = :uid AND lr.status = 'approved'
              AND lr.start_date <= :e AND lr.end_date >= :s
        ");
        $lStmt->execute([':uid' => $userId, ':s' => $startDate, ':e' => $endDate]);
        $approvedLeaves = $lStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch attendance records for user
        $aStmt = $db->prepare("
            SELECT `date`, `status` FROM attendance_records
            WHERE user_id = :uid AND `date` BETWEEN :s AND :e
        ");
        $aStmt->execute([':uid' => $userId, ':s' => $startDate, ':e' => $endDate]);
        $attendanceRows = $aStmt->fetchAll(PDO::FETCH_ASSOC);
        $attendanceMap = [];
        foreach ($attendanceRows as $row) {
            $attendanceMap[$row['date']] = $row['status'];
        }

        $workingDays = 0.0;
        $lopDays = 0.0;

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $dayOfWeek = (int)date('N', strtotime($dateStr)); // 1=Mon ... 7=Sun

            // Weekly off check
            $isWeeklyOff = $sixDayWeek ? ($dayOfWeek === 7) : ($dayOfWeek === 6 || $dayOfWeek === 7);
            $isHoliday = isset($holidaysMap[$dateStr]);

            if ($isWeeklyOff || $isHoliday) {
                // Weekly offs and holidays are non-working days; they do NOT incur LOP
                continue;
            }

            // If user joined after this date, they were not employed yet
            if ($dateStr < $joinedDate) {
                continue;
            }

            $workingDays += 1.0;

            // Check if day is in past or today
            $isPastOrToday = ($dateStr <= $today);

            // Check for approved leave on this date
            $matchedLeave = null;
            foreach ($approvedLeaves as $al) {
                if ($dateStr >= $al['start_date'] && $dateStr <= $al['end_date']) {
                    $matchedLeave = $al;
                    break;
                }
            }

            if ($matchedLeave !== null) {
                $isPaid = (int)$matchedLeave['is_paid'];
                if ($isPaid === 0) {
                    // Approved unpaid leave counts towards LOP
                    if ($matchedLeave['duration_type'] === 'half_day') {
                        $lopDays += 0.5;
                    } else {
                        $lopDays += 1.0;
                    }
                }
                // Approved paid leave does NOT count towards LOP
                continue;
            }

            // Check attendance record
            if (isset($attendanceMap[$dateStr])) {
                $attStatus = $attendanceMap[$dateStr];
                if ($attStatus === 'present' || $attStatus === 'weekly_off' || $attStatus === 'holiday') {
                    // No LOP
                } elseif ($attStatus === 'half_day') {
                    $lopDays += 0.5;
                } elseif ($attStatus === 'absent') {
                    $lopDays += 1.0;
                }
            } else {
                // No punch and no approved leave on a past scheduled work day = absent
                if ($isPastOrToday) {
                    $lopDays += 1.0;
                }
            }
        }

        $paidDays = max(0.0, $workingDays - $lopDays);

        return [
            'working_days' => $workingDays,
            'paid_days'    => $paidDays,
            'lop_days'     => $lopDays,
        ];
    }

    /**
     * Compute full payroll for an employee for a specific month/year.
     */
    public static function computeEmployeePayroll(
        int $userId,
        int $month,
        int $year,
        ?float $overrideLop = null,
        array $adjustments = [],
        ?array $settings = null
    ): array {
        if ($settings === null) {
            $settings = Setting::getAllAsMap();
        }

        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $asOfDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

        // Fetch effective structure
        $structure = SalaryStructure::getForUser($userId, $asOfDate);
        $overrides = $structure ? ($structure['component_values'] ?? []) : [];

        // Fetch active components
        $components = SalaryComponent::getAll(null, true);

        // Settings
        $pfRate = (float)($settings['pf_rate'] ?? 12.0);
        $pfWageCeilingEnabled = (int)($settings['pf_wage_ceiling_enabled'] ?? 1);
        $pfWageCeiling = (float)($settings['pf_wage_ceiling'] ?? 15000.0);

        $esiRate = (float)($settings['esi_rate'] ?? 0.75);
        $esiGrossThreshold = (float)($settings['esi_gross_threshold'] ?? 21000.0);

        $ptDefault = (float)($settings['default_professional_tax'] ?? 200.0);
        $divisorMode = $settings['lop_divisor'] ?? 'calendar_days';
        $roundingMode = $settings['payroll_rounding'] ?? 'nearest_rupee';

        // 1. First pass: Basic calculation
        $basicAmount = 0.0;
        foreach ($components as $c) {
            if ($c['type'] === 'earning' && stripos($c['name'], 'basic') !== false) {
                $cid = (string)$c['id'];
                $val = isset($overrides[$cid]) ? (float)$overrides[$cid] : (float)$c['default_value'];
                $basicAmount = $val;
                break;
            }
        }

        // 2. Compute Earnings
        $earnings = [];
        $gross = 0.0;
        foreach ($components as $c) {
            if ($c['type'] !== 'earning') {
                continue;
            }
            $cid = (string)$c['id'];
            $ruleVal = isset($overrides[$cid]) ? (float)$overrides[$cid] : (float)$c['default_value'];
            $amt = 0.0;

            if ($c['calc_type'] === 'fixed') {
                $amt = $ruleVal;
            } elseif ($c['calc_type'] === 'percent_of_basic') {
                $amt = round(($ruleVal / 100.0) * $basicAmount, 2);
            }
            // (percent_of_gross earnings if any can be calculated on initial gross or base)

            $earnings[] = [
                'component_id' => $c['id'],
                'name'         => $c['name'],
                'amount'       => $amt,
                'calc_type'    => $c['calc_type'],
                'rule_value'   => $ruleVal,
                'is_statutory' => $c['is_statutory'],
            ];
            $gross += $amt;
        }

        // Handle any earnings that are percent_of_gross
        foreach ($earnings as &$e) {
            if ($e['calc_type'] === 'percent_of_gross') {
                $calcAmt = round(($e['rule_value'] / 100.0) * $gross, 2);
                $gross += $calcAmt;
                $e['amount'] = $calcAmt;
            }
        }
        unset($e);

        // 3. Compute Deductions
        $deductions = [];
        $totalDeductions = 0.0;
        foreach ($components as $c) {
            if ($c['type'] !== 'deduction') {
                continue;
            }
            $cid = (string)$c['id'];
            $ruleVal = isset($overrides[$cid]) ? (float)$overrides[$cid] : (float)$c['default_value'];
            $amt = 0.0;
            $stat = strtolower(trim($c['is_statutory']));

            if ($stat === 'pf') {
                // Provident fund
                $wageBase = $basicAmount;
                if ($pfWageCeilingEnabled === 1) {
                    $wageBase = min($wageBase, $pfWageCeiling);
                }
                $rate = isset($overrides[$cid]) ? (float)$overrides[$cid] : $pfRate;
                $amt = round($wageBase * ($rate / 100.0), 2);
            } elseif ($stat === 'esi') {
                // ESI: applies at or below threshold, 0 above threshold
                if ($gross <= $esiGrossThreshold) {
                    $rate = isset($overrides[$cid]) ? (float)$overrides[$cid] : $esiRate;
                    $amt = round($gross * ($rate / 100.0), 2);
                } else {
                    $amt = 0.00;
                }
            } elseif ($stat === 'pt') {
                // Professional Tax
                $amt = isset($overrides[$cid]) ? (float)$overrides[$cid] : $ptDefault;
            } elseif ($stat === 'tds') {
                // TDS
                $amt = isset($overrides[$cid]) ? (float)$overrides[$cid] : (float)$c['default_value'];
            } else {
                // Non-statutory deduction
                if ($c['calc_type'] === 'fixed') {
                    $amt = $ruleVal;
                } elseif ($c['calc_type'] === 'percent_of_basic') {
                    $amt = round(($ruleVal / 100.0) * $basicAmount, 2);
                } elseif ($c['calc_type'] === 'percent_of_gross') {
                    $amt = round(($ruleVal / 100.0) * $gross, 2);
                }
            }

            $deductions[] = [
                'component_id' => $c['id'],
                'name'         => $c['name'],
                'amount'       => $amt,
                'calc_type'    => $c['calc_type'],
                'rule_value'   => $ruleVal,
                'is_statutory' => $c['is_statutory'],
            ];
            $totalDeductions += $amt;
        }

        // 4. Attendance & LOP
        if ($overrideLop !== null) {
            $att = self::calculateAttendanceAndLop($userId, $month, $year, $settings);
            $workingDays = (float)$att['working_days'];
            $lopDays = (float)$overrideLop;
            $paidDays = max(0.0, $workingDays - $lopDays);
        } else {
            $att = self::calculateAttendanceAndLop($userId, $month, $year, $settings);
            $workingDays = (float)$att['working_days'];
            $lopDays = (float)$att['lop_days'];
            $paidDays = (float)$att['paid_days'];
        }

        // LOP divisor
        $divisor = ($divisorMode === 'working_days') ? max(1.0, $workingDays) : max(1.0, (float)$daysInMonth);
        $dailyRate = $gross / $divisor;
        $lopAmount = round($lopDays * $dailyRate, 2);

        // 5. Adjustments
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

        // 6. Net Pay calculation
        $net = ($gross + $adjEarnings) - $lopAmount - ($totalDeductions + $adjDeductions);
        if ($roundingMode === 'nearest_rupee') {
            $net = round($net);
        } else {
            $net = round($net, 2);
        }

        return [
            'user_id'          => $userId,
            'month'            => $month,
            'year'             => $year,
            'working_days'     => $workingDays,
            'paid_days'        => $paidDays,
            'lop_days'         => $lopDays,
            'lop_amount'       => $lopAmount,
            'earnings'         => $earnings,
            'deductions'       => $deductions,
            'adjustments'      => $adjustments,
            'gross'            => round($gross, 2),
            'total_deductions' => round($totalDeductions, 2),
            'net_pay'          => $net,
        ];
    }

    /**
     * Generate or re-run draft payroll for an entire month.
     */
    public static function generateRun(int $month, int $year, int $actorId): int {
        $existing = PayrollRun::getByMonth($month, $year);
        if ($existing) {
            if ($existing['status'] === 'finalized' || $existing['status'] === 'paid') {
                throw new \Exception("Cannot regenerate a finalized or paid payroll run.");
            }
            $runId = (int)$existing['id'];
        } else {
            $runId = PayrollRun::create($month, $year, $actorId);
        }

        $db = Database::getConnection();
        $settings = Setting::getAllAsMap();

        // Get all active employees
        $stmt = $db->query("SELECT id FROM users WHERE status = 'active' ORDER BY id ASC");
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($users as $uid) {
            $uid = (int)$uid;
            $payroll = self::computeEmployeePayroll($uid, $month, $year, null, [], $settings);

            Payslip::upsert([
                'run_id'           => $runId,
                'user_id'          => $uid,
                'month'            => $month,
                'year'             => $year,
                'working_days'     => $payroll['working_days'],
                'paid_days'        => $payroll['paid_days'],
                'lop_days'         => $payroll['lop_days'],
                'lop_amount'       => $payroll['lop_amount'],
                'earnings'         => $payroll['earnings'],
                'deductions'       => $payroll['deductions'],
                'adjustments'      => $payroll['adjustments'],
                'gross'            => $payroll['gross'],
                'total_deductions' => $payroll['total_deductions'],
                'net_pay'          => $payroll['net_pay'],
                'status'           => 'draft',
            ]);
        }

        AuditService::log($actorId, 'payroll_run_generated', 'payroll_run', $runId, null, [
            'month'     => $month,
            'year'      => $year,
            'employees' => count($users),
        ]);

        return $runId;
    }

    /**
     * Regenerate payroll for a single employee in a draft run.
     */
    public static function regenerateEmployee(int $runId, int $userId, int $actorId): ?array {
        $run = PayrollRun::getById($runId);
        if (!$run || $run['status'] !== 'draft') {
            throw new \Exception("Can only regenerate payslips in a draft payroll run.");
        }

        $existingSlip = Payslip::getByRunAndUser($runId, $userId);
        $adjustments = $existingSlip ? ($existingSlip['adjustments'] ?? []) : [];
        $overrideLop = null; // Recompute fresh from attendance

        $settings = Setting::getAllAsMap();
        $month = (int)$run['month'];
        $year = (int)$run['year'];

        $payroll = self::computeEmployeePayroll($userId, $month, $year, $overrideLop, $adjustments, $settings);

        Payslip::upsert([
            'run_id'           => $runId,
            'user_id'          => $userId,
            'month'            => $month,
            'year'             => $year,
            'working_days'     => $payroll['working_days'],
            'paid_days'        => $payroll['paid_days'],
            'lop_days'         => $payroll['lop_days'],
            'lop_amount'       => $payroll['lop_amount'],
            'earnings'         => $payroll['earnings'],
            'deductions'       => $payroll['deductions'],
            'adjustments'      => $payroll['adjustments'],
            'gross'            => $payroll['gross'],
            'total_deductions' => $payroll['total_deductions'],
            'net_pay'          => $payroll['net_pay'],
            'status'           => 'draft',
        ]);

        $updated = Payslip::getByRunAndUser($runId, $userId);
        AuditService::log($actorId, 'payslip_employee_regenerated', 'payslip', (int)$updated['id'], $existingSlip, $updated);

        return $updated;
    }

    /**
     * Convert number to words using Indian number format (Lakhs, Crores, Paise).
     */
    public static function numberToWordsIndian(float $amount): string {
        $amount = round($amount, 2);
        $whole = (int)floor($amount);
        $paise = (int)round(($amount - $whole) * 100);

        if ($whole === 0 && $paise === 0) {
            return "Zero Rupees Only";
        }

        $words = "";
        if ($whole > 0) {
            $crore = (int)floor($whole / 10000000);
            $rem = $whole % 10000000;
            $lakh = (int)floor($rem / 100000);
            $rem = $rem % 100000;
            $thousand = (int)floor($rem / 1000);
            $rem = $rem % 1000;
            $hundredsAndBelow = $rem;

            $parts = [];
            if ($crore > 0) {
                if ($crore >= 100) {
                    $parts[] = self::convertThreeDigits($crore) . " Crore";
                } elseif ($crore >= 20) {
                    $parts[] = self::convertTwoDigits($crore) . " Crore";
                } else {
                    $units = ["", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine", "Ten", "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen", "Eighteen", "Nineteen"];
                    $parts[] = $units[$crore] . " Crore";
                }
            }
            if ($lakh > 0) {
                $parts[] = self::convertTwoDigits($lakh) . " Lakh";
            }
            if ($thousand > 0) {
                $parts[] = self::convertTwoDigits($thousand) . " Thousand";
            }
            if ($hundredsAndBelow > 0) {
                $parts[] = self::convertThreeDigits($hundredsAndBelow);
            }

            $rupeeStr = ($whole === 1) ? "Rupee" : "Rupees";
            $words = implode(" ", $parts) . " " . $rupeeStr;
        }

        if ($paise > 0) {
            $paiseWord = self::convertTwoDigits($paise) . " Paise";
            if ($whole > 0) {
                $words .= " and " . $paiseWord;
            } else {
                $words = $paiseWord;
            }
        }

        return trim($words) . " Only";
    }

    private static function convertTwoDigits(int $n): string {
        $units = ["", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine", "Ten", "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen", "Eighteen", "Nineteen"];
        $tens = ["", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety"];
        if ($n < 20) {
            return $units[$n];
        }
        $t = (int)($n / 10);
        $u = $n % 10;
        return $u > 0 ? $tens[$t] . "-" . $units[$u] : $tens[$t];
    }

    private static function convertThreeDigits(int $n): string {
        $h = (int)($n / 100);
        $rem = $n % 100;
        $parts = [];
        if ($h > 0) {
            $units = ["", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine"];
            $parts[] = $units[$h] . " Hundred";
        }
        if ($rem > 0) {
            $parts[] = self::convertTwoDigits($rem);
        }
        return implode(" ", $parts);
    }

    /**
     * Generate Bank Transfer CSV string with UTF-8 BOM.
     */
    public static function exportBankCsv(int $runId): string {
        $slips = Payslip::getByRun($runId);
        $settings = Setting::getAllAsMap();
        $currency = $settings['currency_symbol'] ?? 'INR';

        $output = fopen('php://temp', 'r+');
        // UTF-8 BOM
        fputs($output, "\xEF\xBB\xBF");

        // Headers
        fputcsv($output, ['Employee ID', 'Employee Name', 'Work Email', 'Net Pay', 'Currency']);

        foreach ($slips as $s) {
            $empId = 'EMP-' . str_pad((string)$s['user_id'], 4, '0', STR_PAD_LEFT);
            fputcsv($output, [
                $empId,
                $s['employee_name'] ?? '',
                $s['employee_email'] ?? '',
                number_format((float)($s['net_pay'] ?? 0), 2, '.', ''),
                $currency,
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Generate Payroll Summary CSV string with UTF-8 BOM.
     */
    public static function exportSummaryCsv(int $runId): string {
        $run = PayrollRun::getById($runId);
        $slips = Payslip::getByRun($runId);
        $settings = Setting::getAllAsMap();
        $currency = $settings['currency_symbol'] ?? 'INR';

        $output = fopen('php://temp', 'r+');
        fputs($output, "\xEF\xBB\xBF");

        fputcsv($output, [
            'Payroll Period',
            'Employee ID',
            'Employee Name',
            'Work Email',
            'Department',
            'Job Title',
            'Working Days',
            'Paid Days',
            'LOP Days',
            'Gross (' . $currency . ')',
            'LOP Deduction (' . $currency . ')',
            'Total Deductions (' . $currency . ')',
            'Net Pay (' . $currency . ')',
            'Status',
        ]);

        $period = $run ? date('F Y', strtotime(sprintf('%04d-%02d-01', $run['year'], $run['month']))) : '';

        foreach ($slips as $s) {
            $empId = 'EMP-' . str_pad((string)$s['user_id'], 4, '0', STR_PAD_LEFT);
            fputcsv($output, [
                $period,
                $empId,
                $s['employee_name'] ?? '',
                $s['employee_email'] ?? '',
                $s['team_name'] ?? ($s['department'] ?? ''),
                $s['job_title'] ?? '',
                $s['working_days'] ?? 0,
                $s['paid_days'] ?? 0,
                $s['lop_days'] ?? 0,
                number_format((float)($s['gross'] ?? 0), 2, '.', ''),
                number_format((float)($s['lop_amount'] ?? 0), 2, '.', ''),
                number_format((float)($s['total_deductions'] ?? 0), 2, '.', ''),
                number_format((float)($s['net_pay'] ?? 0), 2, '.', ''),
                ucfirst($s['status'] ?? 'draft'),
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Generate a ZIP archive containing all payslip PDFs for the run.
     */
    public static function exportPayslipsZip(int $runId): string {
        $run = PayrollRun::getById($runId);
        $slips = Payslip::getByRun($runId);
        $settings = Setting::getAllAsMap();

        $monthStr = $run ? sprintf('%04d_%02d', $run['year'], $run['month']) : 'payroll';

        $tempZip = tempnam(sys_get_temp_dir(), 'payslips_zip_');
        $zip = new ZipArchive();
        if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("Cannot create temporary zip archive.");
        }

        foreach ($slips as $s) {
            $empId = 'EMP-' . str_pad((string)$s['user_id'], 4, '0', STR_PAD_LEFT);
            $cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $s['employee_name'] ?? 'emp');
            $pdfFilename = "Payslip_{$empId}_{$cleanName}_{$monthStr}.pdf";

            $pdfBinary = PayrollPdfService::generatePdfBinary($s, $settings);
            $zip->addFromString($pdfFilename, $pdfBinary);
        }

        $zip->close();

        $zipContent = file_get_contents($tempZip);
        @unlink($tempZip);

        return $zipContent;
    }
}
