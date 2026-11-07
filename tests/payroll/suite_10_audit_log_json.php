<?php
// tests/payroll/suite_10_audit_log_json.php

namespace Tests\Payroll;

use App\Config\Database;
use App\Models\PayrollRun;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Models\Setting;
use PDO;

class Suite10AuditLogJson {
    public static function run(): void {
        echo "\n--- Suite 10: Audit Log Rows with Old & New JSON ---\n";
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $adminId = TestHelper::createTestUser('Audit Admin', 'admin', '2025-01-01');
            $empId = TestHelper::createTestUser('Audit Employee', 'employee', '2025-01-01');

            // 1. Component Create & Update
            $compId = SalaryComponent::create([
                'name'          => 'Audit Test Component',
                'type'          => 'earning',
                'calc_type'     => 'fixed',
                'default_value' => 1234.00,
            ], $adminId);

            $stmtCompCreate = $db->prepare("SELECT * FROM audit_log WHERE action = 'salary_component_create' AND target_id = :id ORDER BY id DESC LIMIT 1");
            $stmtCompCreate->execute([':id' => $compId]);
            $logCreate = $stmtCompCreate->fetch(PDO::FETCH_ASSOC);

            TestHelper::assert($logCreate !== false, 'Audit log row created for salary_component_create');
            $newCompJson = json_decode($logCreate['new_values'], true);
            TestHelper::assert(is_array($newCompJson), 'salary_component_create has valid new_values JSON');
            TestHelper::assertEquals('Audit Test Component', $newCompJson['name'], 'New values JSON contains component name');

            SalaryComponent::update($compId, ['default_value' => 5678.00], $adminId);
            $stmtCompUpdate = $db->prepare("SELECT * FROM audit_log WHERE action = 'salary_component_update' AND target_id = :id ORDER BY id DESC LIMIT 1");
            $stmtCompUpdate->execute([':id' => $compId]);
            $logCompUpdate = $stmtCompUpdate->fetch(PDO::FETCH_ASSOC);

            TestHelper::assert($logCompUpdate !== false, 'Audit log row created for salary_component_update');
            $oldCompJson = json_decode($logCompUpdate['old_values'], true);
            $newCompUpdateJson = json_decode($logCompUpdate['new_values'], true);
            TestHelper::assertEquals(1234.00, (float)$oldCompJson['default_value'], 'Old values JSON captures prior default_value');
            TestHelper::assertEquals(5678.00, (float)$newCompUpdateJson['default_value'], 'New values JSON captures updated default_value');

            // 2. Structure Update
            $structId = SalaryStructure::setStructure($empId, '2026-05-01', ['1' => 42000.00], $adminId);
            $stmtStruct = $db->prepare("SELECT * FROM audit_log WHERE action = 'salary_structure_update' AND target_id = :id ORDER BY id DESC LIMIT 1");
            $stmtStruct->execute([':id' => $structId]);
            $logStruct = $stmtStruct->fetch(PDO::FETCH_ASSOC);

            TestHelper::assert($logStruct !== false, 'Audit log row created for salary_structure_update');
            $newStructJson = json_decode($logStruct['new_values'], true);
            TestHelper::assert(is_array($newStructJson), 'salary_structure_update has valid new_values JSON');
            TestHelper::assertEquals(42000.00, (float)$newStructJson['component_values']['1'], 'New values JSON records component values override');

            // 3. Settings Update
            Setting::updateBatch(['company_legal_name' => 'Audit Test Legal Name Inc'], $adminId);
            $stmtSettings = $db->prepare("SELECT * FROM audit_log WHERE action = 'settings_update' ORDER BY id DESC LIMIT 1");
            $stmtSettings->execute();
            $logSettings = $stmtSettings->fetch(PDO::FETCH_ASSOC);

            TestHelper::assert($logSettings !== false, 'Audit log row created for settings_update');
            $newSettingsJson = json_decode($logSettings['new_values'], true);
            TestHelper::assertEquals('Audit Test Legal Name Inc', $newSettingsJson['company_legal_name'], 'Settings update new JSON captures modified legal name');

            // 4. Payroll Run Finalize & Paid
            $runId = PayrollRun::create(12, 2026, $adminId);
            PayrollRun::finalize($runId, $adminId);

            $stmtFinalize = $db->prepare("SELECT * FROM audit_log WHERE action = 'payroll_run_finalize' AND target_id = :id ORDER BY id DESC LIMIT 1");
            $stmtFinalize->execute([':id' => $runId]);
            $logFinalize = $stmtFinalize->fetch(PDO::FETCH_ASSOC);

            TestHelper::assert($logFinalize !== false, 'Audit log row created for payroll_run_finalize');
            $oldFinJson = json_decode($logFinalize['old_values'], true);
            $newFinJson = json_decode($logFinalize['new_values'], true);
            TestHelper::assertEquals('draft', $oldFinJson['status'], 'Finalize old JSON captures draft status');
            TestHelper::assertEquals('finalized', $newFinJson['status'], 'Finalize new JSON captures finalized status');

            PayrollRun::markPaid($runId, $adminId);
            $stmtPaid = $db->prepare("SELECT * FROM audit_log WHERE action = 'payroll_run_paid' AND target_id = :id ORDER BY id DESC LIMIT 1");
            $stmtPaid->execute([':id' => $runId]);
            $logPaid = $stmtPaid->fetch(PDO::FETCH_ASSOC);

            TestHelper::assert($logPaid !== false, 'Audit log row created for payroll_run_paid');
            $oldPaidJson = json_decode($logPaid['old_values'], true);
            $newPaidJson = json_decode($logPaid['new_values'], true);
            TestHelper::assertEquals('finalized', $oldPaidJson['status'], 'Paid action old JSON captures finalized status');
            TestHelper::assertEquals('paid', $newPaidJson['status'], 'Paid action new JSON captures paid status');

        } finally {
            $db->rollBack();
        }
    }
}

