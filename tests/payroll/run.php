<?php
// tests/payroll/run.php
// ThatRank HR - Payroll & My Finances Test Suite Runner

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
require_once __DIR__ . '/TestHelper.php';
require_once __DIR__ . '/suite_01_statutory_and_net.php';
require_once __DIR__ . '/suite_02_lop_divisor_and_rounding.php';
require_once __DIR__ . '/suite_03_structure_history.php';
require_once __DIR__ . '/suite_04_component_changes.php';
require_once __DIR__ . '/suite_05_run_rules_and_visibility.php';
require_once __DIR__ . '/suite_06_access_control.php';
require_once __DIR__ . '/suite_07_financial_year_and_tds.php';
require_once __DIR__ . '/suite_08_amount_in_words.php';
require_once __DIR__ . '/suite_09_exports_csv_and_pdf.php';
require_once __DIR__ . '/suite_10_audit_log_json.php';

use Tests\Payroll\TestHelper;
use Tests\Payroll\Suite01StatutoryAndNet;
use Tests\Payroll\Suite02LopDivisorAndRounding;
use Tests\Payroll\Suite03StructureHistory;
use Tests\Payroll\Suite04ComponentChanges;
use Tests\Payroll\Suite05RunRulesAndVisibility;
use Tests\Payroll\Suite06AccessControl;
use Tests\Payroll\Suite07FinancialYearAndTds;
use Tests\Payroll\Suite08AmountInWords;
use Tests\Payroll\Suite09ExportsCsvAndPdf;
use Tests\Payroll\Suite10AuditLogJson;

echo "=========================================================\n";
echo "  ThatRank HR Payroll & My Finances Automated Test Suite \n";
echo "=========================================================\n";

$startTime = microtime(true);

Suite01StatutoryAndNet::run();
Suite02LopDivisorAndRounding::run();
Suite03StructureHistory::run();
Suite04ComponentChanges::run();
Suite05RunRulesAndVisibility::run();
Suite06AccessControl::run();
Suite07FinancialYearAndTds::run();
Suite08AmountInWords::run();
Suite09ExportsCsvAndPdf::run();
Suite10AuditLogJson::run();

$totalTime = round((microtime(true) - $startTime), 3);

echo "\n=========================================================\n";
echo "  Test Summary\n";
echo "=========================================================\n";
echo "  Total Assertions: " . (TestHelper::$passed + TestHelper::$failed) . "\n";
echo "  Passed:           " . TestHelper::$passed . "\n";
echo "  Failed:           " . TestHelper::$failed . "\n";
echo "  Time:             " . $totalTime . " seconds\n";

if (TestHelper::$failed > 0) {
    echo "\nFailures encountered:\n";
    foreach (TestHelper::$errors as $err) {
        echo "  - " . $err . "\n";
    }
    exit(1);
} else {
    echo "\nAll payroll and finances tests PASSED successfully!\n";
    exit(0);
}

