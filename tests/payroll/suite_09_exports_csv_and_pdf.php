<?php
// tests/payroll/suite_09_exports_csv_and_pdf.php

namespace Tests\Payroll;

use App\Config\Database;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\PayrollPdfService;
use App\Services\PayrollService;

class Suite09ExportsCsvAndPdf {
    public static function run(): void {
        echo "\n--- Suite 9: CSV Exports (BOM) & PDF Header Verification ---\n";
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $user1Id = TestHelper::createTestUser('Export Employee One', 'employee', '2025-01-01');
            $user2Id = TestHelper::createTestUser('Export Employee Two', 'employee', '2025-01-01');

            $runId = PayrollRun::create(10, 2026, 1);

            Payslip::upsert([
                'run_id'           => $runId,
                'user_id'          => $user1Id,
                'month'            => 10,
                'year'             => 2026,
                'working_days'     => 22,
                'paid_days'        => 22,
                'lop_days'         => 0,
                'lop_amount'       => 0,
                'earnings'         => [['name' => 'Basic', 'amount' => 45000]],
                'deductions'       => [['name' => 'PF', 'amount' => 1800]],
                'adjustments'      => [],
                'gross'            => 45000,
                'total_deductions' => 1800,
                'net_pay'          => 43200,
                'status'           => 'published',
            ]);

            Payslip::upsert([
                'run_id'           => $runId,
                'user_id'          => $user2Id,
                'month'            => 10,
                'year'             => 2026,
                'working_days'     => 22,
                'paid_days'        => 20,
                'lop_days'         => 2,
                'lop_amount'       => 2000,
                'earnings'         => [['name' => 'Basic', 'amount' => 31000]],
                'deductions'       => [['name' => 'PF', 'amount' => 1800]],
                'adjustments'      => [],
                'gross'            => 31000,
                'total_deductions' => 1800,
                'net_pay'          => 27200,
                'status'           => 'published',
            ]);

            // 1. Bank Transfer CSV
            $bankCsv = PayrollService::exportBankCsv($runId);
            $hasBom = str_starts_with($bankCsv, "\xEF\xBB\xBF");
            TestHelper::assertTrue($hasBom, 'Bank transfer CSV starts with UTF-8 BOM (\\xEF\\xBB\\xBF)');
            TestHelper::assertContains('Employee ID', $bankCsv, 'Bank transfer CSV contains Employee ID header');
            TestHelper::assertContains('Export Employee One', $bankCsv, 'Bank transfer CSV contains Employee One row');
            TestHelper::assertContains('43200.00', $bankCsv, 'Bank transfer CSV contains correct Net Pay');
            TestHelper::assertContains('Export Employee Two', $bankCsv, 'Bank transfer CSV contains Employee Two row');

            // 2. Summary CSV
            $summaryCsv = PayrollService::exportSummaryCsv($runId);
            $hasSummaryBom = str_starts_with($summaryCsv, "\xEF\xBB\xBF");
            TestHelper::assertTrue($hasSummaryBom, 'Summary CSV starts with UTF-8 BOM');
            TestHelper::assertContains('Payroll Period', $summaryCsv, 'Summary CSV contains Payroll Period header');
            TestHelper::assertContains('October 2026', $summaryCsv, 'Summary CSV contains October 2026 period');
            TestHelper::assertContains('45000.00', $summaryCsv, 'Summary CSV contains Gross earnings');

            // 3. PDF Generation & Header Verification
            $slip = Payslip::getByRunAndUser($runId, $user1Id);
            $pdfBinary = PayrollPdfService::generatePdfBinary($slip);

            $isPdfHeaderValid = str_starts_with($pdfBinary, '%PDF-');
            TestHelper::assertTrue($isPdfHeaderValid, 'Generated payslip PDF starts with valid PDF header (%PDF-)');
            TestHelper::assert(strlen($pdfBinary) > 1000, 'Generated PDF has substantial content (> 1000 bytes)');

        } finally {
            $db->rollBack();
        }
    }
}

