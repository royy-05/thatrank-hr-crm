<?php
// tests/payroll/suite_02_lop_divisor_and_rounding.php

namespace Tests\Payroll;

use App\Config\Database;
use App\Models\SalaryStructure;
use App\Services\PayrollService;
use PDO;

class Suite02LopDivisorAndRounding {
    public static function run(): void {
        echo "\n--- Suite 2: LOP Calculation, Divisors & Rounding ---\n";
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $userId = TestHelper::createTestUser('LOP Tester', 'employee', '2025-01-01');

            // Insert holiday on Wednesday 2026-10-07
            $hStmt = $db->prepare("INSERT INTO holidays (`name`, `date`, `year`, `is_optional`, `created_at`) VALUES ('Test Holiday', '2026-10-07', 2026, 0, NOW())");
            $hStmt->execute();

            // Insert approved paid leave (casual) on Thursday 2026-10-08
            $lStmt = $db->prepare("
                INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, days_count, duration_type, reason, status, actioned_by, actioned_at)
                VALUES (:uid, 'casual', '2026-10-08', '2026-10-08', 1.0, 'full_day', 'Personal', 'approved', 1, NOW())
            ");
            $lStmt->execute([':uid' => $userId]);

            // Insert approved unpaid leave type if not exists or use unpaid type
            $db->exec("INSERT IGNORE INTO leave_types (name, code, days_per_year, is_paid, is_active) VALUES ('Unpaid Leave', 'unpaid', 0, 0, 1)");

            // Insert approved unpaid leave on Friday 2026-10-09
            $lUnpaidStmt = $db->prepare("
                INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, days_count, duration_type, reason, status, actioned_by, actioned_at)
                VALUES (:uid, 'unpaid', '2026-10-09', '2026-10-09', 1.0, 'full_day', 'Emergency', 'approved', 1, NOW())
            ");
            $lUnpaidStmt->execute([':uid' => $userId]);

            // Insert attendance records for October 2026
            // Monday 2026-10-12: absent
            $aAbsent = $db->prepare("INSERT INTO attendance_records (user_id, `date`, `status`, created_at) VALUES (:uid, '2026-10-12', 'absent', NOW())");
            $aAbsent->execute([':uid' => $userId]);

            // Tuesday 2026-10-13: half_day
            $aHalf = $db->prepare("INSERT INTO attendance_records (user_id, `date`, `status`, created_at) VALUES (:uid, '2026-10-13', 'half_day', NOW())");
            $aHalf->execute([':uid' => $userId]);

            // Mark all other scheduled work days as present so unpunched days aren't counted as absent
            $daysInMonth = 31; // October
            $aPresent = $db->prepare("INSERT IGNORE INTO attendance_records (user_id, `date`, `status`, created_at) VALUES (:uid, :d, 'present', NOW())");
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dateStr = sprintf('2026-10-%02d', $d);
                $dow = (int)date('N', strtotime($dateStr));
                if ($dow < 6 && $dateStr !== '2026-10-07' && $dateStr !== '2026-10-08' && $dateStr !== '2026-10-09' && $dateStr !== '2026-10-12' && $dateStr !== '2026-10-13') {
                    $aPresent->execute([':uid' => $userId, ':d' => $dateStr]);
                }
            }

            $settings = [
                'work_week' => 'Monday to Friday',
            ];

            $att = PayrollService::calculateAttendanceAndLop($userId, 10, 2026, $settings);

            // Total LOP expected = 1.0 (unpaid leave) + 1.0 (absent) + 0.5 (half day) = 2.5
            // Paid leave & holiday & weekly offs do not count
            TestHelper::assertEquals(2.5, $att['lop_days'], 'Total LOP days is 2.5 (1 unpaid + 1 absent + 0.5 half day; holiday & paid leave excluded)');

            // Test Divisors: Gross = 62,000, 31 calendar days, 21 working days (22 weekdays minus 1 holiday)
            $structureValues = [
                '1' => 62000.00, // Basic
                '2' => 0.00,     // HRA override to 0 to isolate Basic
                '3' => 0.00,     // Special override to 0
                '4' => 0.00,     // Internet override to 0
                '7' => 0.00,
                '8' => 0.00,
            ];
            SalaryStructure::setStructure($userId, '2026-01-01', $structureValues, 1);

            // Divisor A: calendar_days (31 days)
            $settingsDivA = [
                'lop_divisor'             => 'calendar_days',
                'payroll_rounding'        => 'nearest_rupee',
                'pf_wage_ceiling_enabled' => '1',
                'pf_rate'                 => '0', // isolate LOP
                'esi_gross_threshold'     => '0',
                'default_professional_tax'=> '0',
            ];
            $payrollDivA = PayrollService::computeEmployeePayroll($userId, 10, 2026, 2.5, [], $settingsDivA);
            // Daily rate = 62000 / 31 = 2000. LOP amount = 2.5 * 2000 = 5000.00
            TestHelper::assertEquals(5000.00, $payrollDivA['lop_amount'], 'LOP divisor calendar_days gives 5000.00 LOP amount');

            // Divisor B: working_days
            $settingsDivB = $settingsDivA;
            $settingsDivB['lop_divisor'] = 'working_days';
            $payrollDivB = PayrollService::computeEmployeePayroll($userId, 10, 2026, 2.5, [], $settingsDivB);
            $workingDays = (float)$att['working_days'];
            $expectedLopB = round(2.5 * (62000.00 / $workingDays), 2);
            TestHelper::assertEquals($expectedLopB, $payrollDivB['lop_amount'], "LOP divisor working_days gives {$expectedLopB} LOP amount based on {$workingDays} working days");

            // Test Rounding:
            // Gross = 50000, LOP = 2.5 with daily rate = 50000/31 = 1612.9032...
            // LOP amount = 4032.26. Net = 50000 - 4032.26 = 45967.74.
            $settingsRoundNone = [
                'lop_divisor'             => 'calendar_days',
                'payroll_rounding'        => 'none',
                'pf_rate'                 => '0',
                'esi_gross_threshold'     => '0',
                'default_professional_tax'=> '0',
            ];
            $structureRound = [
                '1' => 50000.00,
                '2' => 0.00,
                '3' => 0.00,
                '4' => 0.00,
            ];
            SalaryStructure::setStructure($userId, '2026-01-01', $structureRound, 1);
            $payrollRoundNone = PayrollService::computeEmployeePayroll($userId, 10, 2026, 2.5, [], $settingsRoundNone);
            TestHelper::assertEquals(45967.74, $payrollRoundNone['net_pay'], 'Rounding mode "none" keeps exact paise (45967.74)');

            $settingsRoundNear = $settingsRoundNone;
            $settingsRoundNear['payroll_rounding'] = 'nearest_rupee';
            $payrollRoundNear = PayrollService::computeEmployeePayroll($userId, 10, 2026, 2.5, [], $settingsRoundNear);
            TestHelper::assertEquals(45968.0, $payrollRoundNear['net_pay'], 'Rounding mode "nearest_rupee" rounds to nearest rupee (45968)');

        } finally {
            $db->rollBack();
        }
    }
}
