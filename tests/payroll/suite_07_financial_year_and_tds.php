<?php
// tests/payroll/suite_07_financial_year_and_tds.php

namespace Tests\Payroll;

use App\Config\Database;
use App\Models\PayrollRun;
use App\Models\Payslip;

class Suite07FinancialYearAndTds {
    public static function run(): void {
        echo "\n--- Suite 7: Financial Year Grouping & TDS Projection Math ---\n";
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $userId = TestHelper::createTestUser('Tax Tester', 'employee', '2025-01-01');

            // Helper to create published payslip with TDS
            $addPublishedSlip = function (int $month, int $year, float $tds) use ($userId): int {
                $run = PayrollRun::getByMonth($month, $year);
                $runId = $run ? (int)$run['id'] : PayrollRun::create($month, $year, 1);
                $deductions = [
                    ['name' => 'TDS', 'amount' => $tds, 'is_statutory' => 'tds']
                ];
                return Payslip::upsert([
                    'run_id'           => $runId,
                    'user_id'          => $userId,
                    'month'            => $month,
                    'year'             => $year,
                    'working_days'     => 22,
                    'paid_days'        => 22,
                    'lop_days'         => 0,
                    'lop_amount'       => 0,
                    'earnings'         => [['name' => 'Basic', 'amount' => 50000]],
                    'deductions'       => $deductions,
                    'adjustments'      => [],
                    'gross'            => 50000,
                    'total_deductions' => $tds,
                    'net_pay'          => 50000 - $tds,
                    'status'           => 'published',
                    'published_at'     => date('Y-m-d H:i:s'),
                ]);
            };

            // Scenario 1: April FY start (fyStartMonth = 4)
            // FY 2026 = 2026-04-01 to 2027-03-31
            $addPublishedSlip(2, 2026, 1500.0); // Belongs to FY 2025
            $addPublishedSlip(4, 2026, 3000.0); // FY 2026
            $addPublishedSlip(5, 2026, 3000.0); // FY 2026
            $addPublishedSlip(6, 2026, 3500.0); // FY 2026

            // Fetch published payslips for FY 2026 with April start
            $slipsFy2026 = Payslip::getPublishedForUser($userId, 2026, 4);
            TestHelper::assertEquals(3, count($slipsFy2026), 'April FY 2026 correctly includes April, May, June 2026 (excludes February 2026)');

            // Fetch published payslips for FY 2025 with April start
            $slipsFy2025 = Payslip::getPublishedForUser($userId, 2025, 4);
            TestHelper::assertEquals(1, count($slipsFy2025), 'February 2026 is grouped into FY 2025');

            // TDS Projection calculation for FY 2026 (April start):
            // Deducted so far = 3000 + 3000 + 3500 = 9500.00
            // Months completed = 3
            // Months remaining = 12 - 3 = 9
            // Latest monthly TDS = 3500.00
            // Projected = 9500 + (3500 * 9) = 9500 + 31500 = 41000.00
            $taxSummaryApril = Payslip::getTaxSummary($userId, 2026, 4);
            TestHelper::assertEquals(9500.00, $taxSummaryApril['deducted_so_far'], 'TDS deducted so far matches sum of published payslips (9500.00)');
            TestHelper::assertEquals(3, $taxSummaryApril['months_completed'], 'Months completed in FY is 3');
            TestHelper::assertEquals(9, $taxSummaryApril['months_remaining'], 'Months remaining in FY is 9');
            TestHelper::assertEquals(41000.00, $taxSummaryApril['projected_total'], 'Projected FY tax matches (9500 + 3500*9 = 41000.00)');

            // Scenario 2: January FY start (fyStartMonth = 1)
            // FY 2026 = 2026-01-01 to 2026-12-31
            // All 4 payslips (Feb, Apr, May, Jun 2026) fall in FY 2026
            $slipsJanFy = Payslip::getPublishedForUser($userId, 2026, 1);
            TestHelper::assertEquals(4, count($slipsJanFy), 'January FY 2026 correctly includes all 4 slips from calendar year 2026');

            // Tax Summary for January FY:
            // Deducted so far = 1500 + 3000 + 3000 + 3500 = 11000.00
            // Months completed = 4, remaining = 8
            // Projected = 11000 + (3500 * 8) = 11000 + 28000 = 39000.00
            $taxSummaryJan = Payslip::getTaxSummary($userId, 2026, 1);
            TestHelper::assertEquals(11000.00, $taxSummaryJan['deducted_so_far'], 'January FY TDS deducted so far is 11000.00');
            TestHelper::assertEquals(4, $taxSummaryJan['months_completed'], 'January FY months completed is 4');
            TestHelper::assertEquals(8, $taxSummaryJan['months_remaining'], 'January FY months remaining is 8');
            TestHelper::assertEquals(39000.00, $taxSummaryJan['projected_total'], 'January FY projected TDS is 39000.00');

        } finally {
            $db->rollBack();
        }
    }
}

