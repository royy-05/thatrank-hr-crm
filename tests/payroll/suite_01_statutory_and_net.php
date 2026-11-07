<?php
// tests/payroll/suite_01_statutory_and_net.php

namespace Tests\Payroll;

use App\Config\Database;
use App\Models\SalaryStructure;
use App\Services\PayrollService;

class Suite01StatutoryAndNet {
    public static function run(): void {
        echo "\n--- Suite 1: Statutory Deductions & Net Pay ---\n";
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $userId = TestHelper::createTestUser('Statutory Tester', 'employee', '2025-01-01');

            // 1. Structure with Basic 30,000, HRA 50%, Special 5,000 => Gross 50,000
            $structureValues = [
                '1' => 30000.00, // Basic
                '2' => 50.00,    // HRA (50% of basic = 15000)
                '3' => 5000.00,  // Special allowance
                '4' => 0.00,     // Internet allowance
                '7' => 200.00,   // Professional tax
                '8' => 0.00,     // TDS
            ];
            SalaryStructure::setStructure($userId, '2026-01-01', $structureValues, 1);

            // Test A: Gross > 21,000 (Gross = 50,000) and PF ceiling enabled (15,000)
            $settingsA = [
                'pf_rate'                 => '12.0',
                'pf_wage_ceiling_enabled' => '1',
                'pf_wage_ceiling'         => '15000',
                'esi_rate'                => '0.75',
                'esi_gross_threshold'     => '21000',
                'default_professional_tax'=> '200',
                'lop_divisor'             => 'calendar_days',
                'payroll_rounding'        => 'nearest_rupee',
                'work_week'               => 'Monday to Friday',
            ];

            $payrollA = PayrollService::computeEmployeePayroll($userId, 10, 2026, 0.0, [], $settingsA);

            TestHelper::assertEquals(50000.00, $payrollA['gross'], 'Gross earnings sum to 50000.00');

            // PF with ceiling 15000 -> 12% of 15000 = 1800.00
            $pfDedA = null;
            $esiDedA = null;
            $ptDedA = null;
            foreach ($payrollA['deductions'] as $d) {
                if ($d['is_statutory'] === 'pf') $pfDedA = $d['amount'];
                if ($d['is_statutory'] === 'esi') $esiDedA = $d['amount'];
                if ($d['is_statutory'] === 'pt') $ptDedA = $d['amount'];
            }

            TestHelper::assertEquals(1800.00, $pfDedA, 'PF deduction capped at ceiling 15000 gives 1800.00');
            TestHelper::assertEquals(0.00, $esiDedA, 'ESI is 0.00 when gross exceeds 21000 threshold');
            TestHelper::assertEquals(200.00, $ptDedA, 'Professional tax is 200.00');

            $expectedNetA = 50000.00 - 0.0 - (1800.00 + 0.00 + 200.00); // 48000
            TestHelper::assertEquals($expectedNetA, $payrollA['net_pay'], 'Net pay equals gross minus deductions (48000.00)');

            // Test B: PF ceiling disabled -> PF on full 30,000 Basic = 3,600.00
            $settingsB = $settingsA;
            $settingsB['pf_wage_ceiling_enabled'] = '0';
            $payrollB = PayrollService::computeEmployeePayroll($userId, 10, 2026, 0.0, [], $settingsB);

            $pfDedB = null;
            foreach ($payrollB['deductions'] as $d) {
                if ($d['is_statutory'] === 'pf') $pfDedB = $d['amount'];
            }
            TestHelper::assertEquals(3600.00, $pfDedB, 'PF deduction on full Basic (ceiling disabled) gives 3600.00');

            // Test C: Gross <= 21,000 threshold -> ESI applies
            // Basic 12000, HRA 50% (6000), Special 2000 => Gross 20000
            $userLowId = TestHelper::createTestUser('Low Salary Tester', 'employee', '2025-01-01');
            $structureLow = [
                '1' => 12000.00,
                '2' => 50.00,
                '3' => 2000.00,
                '4' => 0.00,
                '7' => 200.00,
                '8' => 0.00,
            ];
            SalaryStructure::setStructure($userLowId, '2026-01-01', $structureLow, 1);

            $payrollC = PayrollService::computeEmployeePayroll($userLowId, 10, 2026, 0.0, [], $settingsA);
            TestHelper::assertEquals(20000.00, $payrollC['gross'], 'Low gross earnings sum to 20000.00');

            $esiDedC = null;
            foreach ($payrollC['deductions'] as $d) {
                if ($d['is_statutory'] === 'esi') $esiDedC = $d['amount'];
            }
            // 0.75% of 20000 = 150.00
            TestHelper::assertEquals(150.00, $esiDedC, 'ESI applies at 0.75% when gross (20000) is at or below threshold (21000)');

        } finally {
            $db->rollBack();
        }
    }
}

