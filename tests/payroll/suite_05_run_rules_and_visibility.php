<?php
// tests/payroll/suite_05_run_rules_and_visibility.php

namespace Tests\Payroll;

use App\Config\Database;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Setting;

class Suite05RunRulesAndVisibility {
    public static function run(): void {
        echo "\n--- Suite 5: Run Rules, Finalize, Reopen & Visibility ---\n";
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $userId = TestHelper::createTestUser('Run Rules Tester', 'employee', '2025-01-01');

            // 1. Duplicate month blocked
            $run1Id = PayrollRun::create(7, 2026, 1);
            TestHelper::assert($run1Id > 0, 'Created initial run for July 2026');

            $duplicateBlocked = false;
            try {
                PayrollRun::create(7, 2026, 1);
            } catch (\Throwable $e) {
                $duplicateBlocked = true;
            }
            TestHelper::assertTrue($duplicateBlocked, 'Duplicate run for same month and year is blocked');

            // Insert sample payslip
            $slipId = Payslip::upsert([
                'run_id'           => $run1Id,
                'user_id'          => $userId,
                'month'            => 7,
                'year'             => 2026,
                'working_days'     => 22,
                'paid_days'        => 22,
                'lop_days'         => 0,
                'lop_amount'       => 0,
                'earnings'         => [['name' => 'Basic', 'amount' => 30000]],
                'deductions'       => [],
                'adjustments'      => [],
                'gross'            => 30000,
                'total_deductions' => 0,
                'net_pay'          => 30000,
                'status'           => 'draft',
            ]);

            // 2. Finalize locks the run and prevents edits
            $finRes = PayrollRun::finalize($run1Id, 1);
            TestHelper::assertTrue($finRes, 'Payroll run finalized successfully');

            // Attempt to edit finalized payslip
            $editRes = Payslip::updateAdjustments($slipId, [['type' => 'earning', 'name' => 'Bonus', 'amount' => 500]], 1.0, 1);
            TestHelper::assertFalse($editRes, 'Editing payslip in finalized run is blocked');

            // 3. Reopen allowed before paid
            $reopenBeforePaid = PayrollRun::reopen($run1Id, 1);
            TestHelper::assertTrue($reopenBeforePaid['success'], 'Reopening finalized run before payment is permitted');

            // Finalize again, then mark paid
            PayrollRun::finalize($run1Id, 1);
            $paidRes = PayrollRun::markPaid($run1Id, 1);
            TestHelper::assertTrue($paidRes, 'Marked payroll run as paid');

            // Reopen blocked after paid
            $reopenAfterPaid = PayrollRun::reopen($run1Id, 1);
            TestHelper::assertFalse($reopenAfterPaid['success'], 'Reopening run after payment is strictly blocked');

            // 4. Publish visibility setting honored
            // Test with 'on_finalize'
            Setting::updateBatch(['payslip_visibility' => 'on_finalize'], 1);
            $runVisA = PayrollRun::create(8, 2026, 1);
            $slipA = Payslip::upsert([
                'run_id'           => $runVisA,
                'user_id'          => $userId,
                'month'            => 8,
                'year'             => 2026,
                'working_days'     => 22,
                'paid_days'        => 22,
                'lop_days'         => 0,
                'lop_amount'       => 0,
                'earnings'         => [],
                'deductions'       => [],
                'adjustments'      => [],
                'gross'            => 30000,
                'total_deductions' => 0,
                'net_pay'          => 30000,
                'status'           => 'draft',
            ]);

            PayrollRun::finalize($runVisA, 1);
            $slipARec = Payslip::getById($slipA);
            TestHelper::assertEquals('published', $slipARec['status'], 'Visibility on_finalize publishes payslips immediately upon finalize');

            // Test with 'on_paid'
            Setting::updateBatch(['payslip_visibility' => 'on_paid'], 1);
            $runVisB = PayrollRun::create(9, 2026, 1);
            $slipB = Payslip::upsert([
                'run_id'           => $runVisB,
                'user_id'          => $userId,
                'month'            => 9,
                'year'             => 2026,
                'working_days'     => 22,
                'paid_days'        => 22,
                'lop_days'         => 0,
                'lop_amount'       => 0,
                'earnings'         => [],
                'deductions'       => [],
                'adjustments'      => [],
                'gross'            => 30000,
                'total_deductions' => 0,
                'net_pay'          => 30000,
                'status'           => 'draft',
            ]);

            PayrollRun::finalize($runVisB, 1);
            $slipBRecFin = Payslip::getById($slipB);
            TestHelper::assertEquals('draft', $slipBRecFin['status'], 'Visibility on_paid keeps payslips as draft upon finalize');

            PayrollRun::markPaid($runVisB, 1);
            $slipBRecPaid = Payslip::getById($slipB);
            TestHelper::assertEquals('published', $slipBRecPaid['status'], 'Visibility on_paid publishes payslips only after mark as paid');

        } finally {
            $db->rollBack();
        }
    }
}

