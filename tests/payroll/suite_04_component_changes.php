<?php
// tests/payroll/suite_04_component_changes.php

namespace Tests\Payroll;

use App\Config\Database;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Services\PayrollService;

class Suite04ComponentChanges {
    public static function run(): void {
        echo "\n--- Suite 4: Component Changes & Finalized Preservation ---\n";
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $userId = TestHelper::createTestUser('Component Change Tester', 'employee', '2025-01-01');

            // Add component: Festival Allowance (5000.00)
            $compFestId = SalaryComponent::create([
                'name'          => 'Festival Allowance',
                'type'          => 'earning',
                'calc_type'     => 'fixed',
                'default_value' => 5000.00,
                'sort_order'    => 20,
                'active'        => 1,
            ], 1);

            // Assign base structure (Basic 30000)
            SalaryStructure::setStructure($userId, '2026-01-01', [
                '1'               => 30000.00,
                (string)$compFestId => 5000.00,
            ], 1);

            // Generate Run 1 (October 2026)
            $run1Id = PayrollRun::create(10, 2026, 1);
            $settings = [
                'lop_divisor'             => 'calendar_days',
                'payroll_rounding'        => 'nearest_rupee',
                'pf_rate'                 => '0',
                'esi_gross_threshold'     => '0',
                'default_professional_tax'=> '0',
            ];
            $payrollOct = PayrollService::computeEmployeePayroll($userId, 10, 2026, 0.0, [], $settings);

            Payslip::upsert([
                'run_id'           => $run1Id,
                'user_id'          => $userId,
                'month'            => 10,
                'year'             => 2026,
                'working_days'     => $payrollOct['working_days'],
                'paid_days'        => $payrollOct['paid_days'],
                'lop_days'         => $payrollOct['lop_days'],
                'lop_amount'       => $payrollOct['lop_amount'],
                'earnings'         => $payrollOct['earnings'],
                'deductions'       => $payrollOct['deductions'],
                'adjustments'      => [],
                'gross'            => $payrollOct['gross'],
                'total_deductions' => $payrollOct['total_deductions'],
                'net_pay'          => $payrollOct['net_pay'],
                'status'           => 'draft',
            ]);

            // Finalize Run 1
            PayrollRun::finalize($run1Id, 1);

            $octSlipBefore = Payslip::getByRunAndUser($run1Id, $userId);
            TestHelper::assert($octSlipBefore !== null, 'October payslip exists and is finalized');
            $hasFestivalInOct = false;
            foreach ($octSlipBefore['earnings'] as $e) {
                if ($e['name'] === 'Festival Allowance') $hasFestivalInOct = true;
            }
            TestHelper::assertTrue($hasFestivalInOct, 'October payslip includes Festival Allowance');

            // Now deactivate Festival Allowance
            SalaryComponent::update($compFestId, ['active' => 0], 1);

            // And create a new active component: Medical Allowance (2000.00)
            $compMedId = SalaryComponent::create([
                'name'          => 'Medical Allowance',
                'type'          => 'earning',
                'calc_type'     => 'fixed',
                'default_value' => 2000.00,
                'sort_order'    => 21,
                'active'        => 1,
            ], 1);

            // Generate Run 2 (November 2026)
            $run2Id = PayrollRun::create(11, 2026, 1);
            $payrollNov = PayrollService::computeEmployeePayroll($userId, 11, 2026, 0.0, [], $settings);

            Payslip::upsert([
                'run_id'           => $run2Id,
                'user_id'          => $userId,
                'month'            => 11,
                'year'             => 2026,
                'working_days'     => $payrollNov['working_days'],
                'paid_days'        => $payrollNov['paid_days'],
                'lop_days'         => $payrollNov['lop_days'],
                'lop_amount'       => $payrollNov['lop_amount'],
                'earnings'         => $payrollNov['earnings'],
                'deductions'       => $payrollNov['deductions'],
                'adjustments'      => [],
                'gross'            => $payrollNov['gross'],
                'total_deductions' => $payrollNov['total_deductions'],
                'net_pay'          => $payrollNov['net_pay'],
                'status'           => 'draft',
            ]);

            $novSlip = Payslip::getByRunAndUser($run2Id, $userId);

            // Check November payslip: has Medical Allowance, does NOT have Festival Allowance
            $hasFestivalInNov = false;
            $hasMedInNov = false;
            foreach ($novSlip['earnings'] as $e) {
                if ($e['name'] === 'Festival Allowance') $hasFestivalInNov = true;
                if ($e['name'] === 'Medical Allowance') $hasMedInNov = true;
            }
            TestHelper::assertFalse($hasFestivalInNov, 'Deactivated Festival Allowance does not flow into November run');
            TestHelper::assertTrue($hasMedInNov, 'Newly added Medical Allowance flows into November run');

            // Verify finalized October payslip was NOT mutated
            $octSlipAfter = Payslip::getByRunAndUser($run1Id, $userId);
            $hasFestivalInOctAfter = false;
            $hasMedInOctAfter = false;
            foreach ($octSlipAfter['earnings'] as $e) {
                if ($e['name'] === 'Festival Allowance') $hasFestivalInOctAfter = true;
                if ($e['name'] === 'Medical Allowance') $hasMedInOctAfter = true;
            }
            TestHelper::assertTrue($hasFestivalInOctAfter, 'Finalized October payslip retains Festival Allowance');
            TestHelper::assertFalse($hasMedInOctAfter, 'Finalized October payslip does not contain newly added Medical Allowance');

        } finally {
            $db->rollBack();
        }
    }
}

