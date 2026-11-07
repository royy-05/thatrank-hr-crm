<?php
// tests/roles/run.php

require_once __DIR__ . '/../../config/bootstrap.php';

use App\Config\Database;
use App\Models\Role;
use App\Models\RolePermission;
use App\Middleware\AuthMiddleware;

class RolesTestSuite {
    private int $passes = 0;
    private int $fails = 0;
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    private function assert(string $label, bool $condition, string $detail = ''): void {
        if ($condition) {
            $this->passes++;
            echo "  [PASS] {$label}\n";
        } else {
            $this->fails++;
            echo "  [FAIL] {$label}" . ($detail ? " - {$detail}" : '') . "\n";
        }
    }

    public function run(): void {
        echo "=========================================================\n";
        echo "  ThatRank HR Roles & Permissions Automated Test Suite   \n";
        echo "=========================================================\n\n";

        $this->testCatalogIntegrity();
        $this->testRolesTableIntegrity();
        $this->testAdminFullAccess();
        $this->testManagerDefaultPermissions();
        $this->testEmployeeDefaultPermissions();
        $this->testCustomRoleLifecycle();
        $this->testPermissionSavingAndAuditLog();
        $this->testPermissionRevocationWorkflow();
        $this->testRoleResetDefaults();

        echo "\n=========================================================\n";
        echo "  Test Summary\n";
        echo "=========================================================\n";
        echo "  Total Assertions: " . ($this->passes + $this->fails) . "\n";
        echo "  Passed:           {$this->passes}\n";
        echo "  Failed:           {$this->fails}\n";

        if ($this->fails > 0) {
            exit(1);
        }
        echo "\nAll roles and permissions tests PASSED successfully!\n";
    }

    private function testCatalogIntegrity(): void {
        echo "--- Suite 1: Permission Catalog Integrity ---\n";
        $catalog = RolePermission::getPermissionCatalog();

        $this->assert("Catalog contains exactly 13 modules", count($catalog) === 13);
        $expectedModules = [
            'dashboard', 'attendance', 'leave', 'community', 'finances',
            'liveboard', 'approvals', 'reports', 'payroll', 'employees',
            'teams', 'audit', 'settings'
        ];
        foreach ($expectedModules as $mod) {
            $this->assert("Module '{$mod}' exists in catalog", isset($catalog[$mod]));
            $this->assert("Module '{$mod}' has title and description", !empty($catalog[$mod]['title']) && !empty($catalog[$mod]['description']));
            $this->assert("Module '{$mod}' has granular permissions array", !empty($catalog[$mod]['permissions']) && is_array($catalog[$mod]['permissions']));
        }

        $this->assert("Approvals contains approve_leaves permission", isset($catalog['approvals']['permissions']['approve_leaves']));
        $this->assert("Approvals contains approve_corrections permission", isset($catalog['approvals']['permissions']['approve_corrections']));
        $this->assert("Approvals contains approve_compoff permission", isset($catalog['approvals']['permissions']['approve_compoff']));
        $this->assert("Live board contains view_live_board permission", isset($catalog['liveboard']['permissions']['view_live_board']));
        $this->assert("Payroll contains view_payroll permission", isset($catalog['payroll']['permissions']['view_payroll']));
    }

    private function testRolesTableIntegrity(): void {
        echo "\n--- Suite 2: Roles Table & Metadata Integrity ---\n";
        $roles = Role::getAllWithCounts();

        $this->assert("Roles list returns at least 3 roles", count($roles) >= 3);
        $slugs = array_column($roles, 'slug');
        $this->assert("Roles include 'admin'", in_array('admin', $slugs, true));
        $this->assert("Roles include 'manager'", in_array('manager', $slugs, true));
        $this->assert("Roles include 'employee'", in_array('employee', $slugs, true));

        foreach ($roles as $r) {
            if ($r['slug'] === 'admin') {
                $this->assert("Admin role is marked as system role", $r['is_system'] === true);
                $this->assert("Admin role has all permissions granted", $r['granted_permissions'] === $r['total_permissions']);
            }
        }
    }

    private function testAdminFullAccess(): void {
        echo "\n--- Suite 3: Administrator Permanent Full Access ---\n";
        $catalog = RolePermission::getPermissionCatalog();
        $adminPerms = RolePermission::getPermissionsForRole('admin');

        $allGranted = true;
        foreach ($catalog as $mod) {
            foreach ($mod['permissions'] as $k => $info) {
                if (empty($adminPerms[$k])) {
                    $allGranted = false;
                    break 2;
                }
            }
        }
        $this->assert("Admin role has all permissions granted", $allGranted);
        $this->assert("Admin hasPermission 'approve_leaves' is true", RolePermission::hasPermission('admin', 'approve_leaves'));
        $this->assert("Admin hasPermission 'manage_salary_components' is true", RolePermission::hasPermission('admin', 'manage_salary_components'));
        $this->assert("Admin canUser check returns true", RolePermission::canUser(['role' => 'admin'], 'offboard_employees'));
    }

    private function testManagerDefaultPermissions(): void {
        echo "\n--- Suite 4: Manager Role Default Permissions ---\n";
        $mgrPerms = RolePermission::getPermissionsForRole('manager');

        $this->assert("Manager can view live board by default", !empty($mgrPerms['view_live_board']));
        $this->assert("Manager can view approvals by default", !empty($mgrPerms['view_approvals']));
        $this->assert("Manager can approve leaves by default", !empty($mgrPerms['approve_leaves']));
        $this->assert("Manager can approve corrections by default", !empty($mgrPerms['approve_corrections']));
        $this->assert("Manager can view reports by default", !empty($mgrPerms['view_reports']));
        $this->assert("Manager cannot view payroll by default", empty($mgrPerms['view_payroll']));
        $this->assert("Manager cannot manage salary components by default", empty($mgrPerms['manage_salary_components']));
        $this->assert("Manager cannot edit company settings by default", empty($mgrPerms['edit_settings']));
    }

    private function testEmployeeDefaultPermissions(): void {
        echo "\n--- Suite 5: Employee Role Default Permissions ---\n";
        $empPerms = RolePermission::getPermissionsForRole('employee');

        $this->assert("Employee can view dashboard", !empty($empPerms['view_dashboard']));
        $this->assert("Employee can clock in/out", !empty($empPerms['clock_in_out']));
        $this->assert("Employee can request leave", !empty($empPerms['request_leave']));
        $this->assert("Employee can view finances", !empty($empPerms['view_finances']));
        $this->assert("Employee cannot view live board by default", empty($empPerms['view_live_board']));
        $this->assert("Employee cannot approve leaves by default", empty($empPerms['approve_leaves']));
        $this->assert("Employee cannot view reports by default", empty($empPerms['view_reports']));
        $this->assert("Employee cannot view payroll by default", empty($empPerms['view_payroll']));
    }

    private function testCustomRoleLifecycle(): void {
        echo "\n--- Suite 6: Custom Role Lifecycle (Create, View, Edit, Delete) ---\n";
        $testAdminId = 1;
        $testRoleSlug = 'test_qa_lead';

        // Clean up previous test run if needed
        $this->db->prepare("DELETE FROM role_permissions WHERE role = :s")->execute([':s' => $testRoleSlug]);
        $this->db->prepare("DELETE FROM roles WHERE slug = :s")->execute([':s' => $testRoleSlug]);

        // 1. Create custom role
        $createRes = Role::create(
            'Test QA Lead',
            'Lead of quality assurance team',
            $testRoleSlug,
            [
                'view_dashboard'  => true,
                'view_attendance' => true,
                'approve_leaves'  => true,
                'view_reports'    => true,
            ],
            $testAdminId
        );
        $this->assert("Role::create returns success", $createRes['success'] === true);
        $this->assert("Role::create returns correct slug", ($createRes['slug'] ?? '') === $testRoleSlug);

        // 2. View created role
        $fetched = Role::findBySlug($testRoleSlug);
        $this->assert("Role was found by slug", !empty($fetched));
        $this->assert("Role name matches 'Test QA Lead'", ($fetched['name'] ?? '') === 'Test QA Lead');
        $this->assert("Role is not system role", empty($fetched['is_system']));

        $perms = RolePermission::getPermissionsForRole($testRoleSlug);
        $this->assert("Role has approve_leaves enabled", !empty($perms['approve_leaves']));
        $this->assert("Role has view_reports enabled", !empty($perms['view_reports']));
        $this->assert("Role has view_payroll disabled", empty($perms['view_payroll']));

        // 3. Edit custom role
        $updateRes = Role::update(
            $testRoleSlug,
            'Senior QA Lead',
            'Updated lead description',
            [
                'view_dashboard'  => true,
                'view_attendance' => true,
                'approve_leaves'  => false, // revoked
                'view_payroll'    => true,  // granted
            ],
            $testAdminId
        );
        $this->assert("Role::update returns success", $updateRes['success'] === true);

        $updatedFetched = Role::findBySlug($testRoleSlug);
        $this->assert("Role name updated to 'Senior QA Lead'", ($updatedFetched['name'] ?? '') === 'Senior QA Lead');
        $updatedPerms = RolePermission::getPermissionsForRole($testRoleSlug);
        $this->assert("Updated role has approve_leaves disabled", empty($updatedPerms['approve_leaves']));
        $this->assert("Updated role has view_payroll enabled", !empty($updatedPerms['view_payroll']));

        // 4. System role delete prevention
        $sysDelRes = Role::delete('admin', $testAdminId);
        $this->assert("Deleting 'admin' system role is blocked", $sysDelRes['success'] === false);

        $mgrDelRes = Role::delete('manager', $testAdminId);
        $this->assert("Deleting 'manager' system role is blocked", $mgrDelRes['success'] === false);

        // 5. Delete custom role
        $delRes = Role::delete($testRoleSlug, $testAdminId);
        $this->assert("Deleting custom role returns success", $delRes['success'] === true);

        $afterDelete = Role::findBySlug($testRoleSlug);
        $this->assert("Custom role no longer exists in database", $afterDelete === null);

        // Verify audit log row for role_delete
        $stmt = $this->db->prepare("
            SELECT action, target_type FROM audit_log
            WHERE action = 'role_delete' AND target_type = 'role'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute();
        $audit = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assert("Audit log row created for role_delete", !empty($audit));
    }

    private function testPermissionSavingAndAuditLog(): void {
        echo "\n--- Suite 7: Dynamic Permission Saving & Audit Log ---\n";
        $testAdminId = 1;

        // Manager currently has approve_leaves = true. Let's toggle approve_leaves to false.
        $newPerms = RolePermission::getPermissionsForRole('manager');
        $newPerms['approve_leaves'] = false;

        $saved = RolePermission::saveRolePermissions('manager', $newPerms, $testAdminId);
        $this->assert("RolePermission::saveRolePermissions returned true", $saved);

        $checkPerms = RolePermission::getPermissionsForRole('manager');
        $this->assert("Manager approve_leaves is now false in DB", empty($checkPerms['approve_leaves']));
        $this->assert("hasPermission('manager', 'approve_leaves') returns false", !RolePermission::hasPermission('manager', 'approve_leaves'));

        // Verify audit log row
        $stmt = $this->db->prepare("
            SELECT action, target_type, target_id, old_values, new_values
            FROM audit_log
            WHERE action = 'role_permissions_update' AND target_type = 'role_permission'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute();
        $audit = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assert("Audit log row created for role_permissions_update", !empty($audit));
        $oldJson = json_decode($audit['old_values'] ?? '{}', true);
        $newJson = json_decode($audit['new_values'] ?? '{}', true);
        $this->assert("Audit new values records role as manager", ($newJson['role'] ?? '') === 'manager');
        $this->assert("Audit old values recorded approve_leaves true", !empty($oldJson['approve_leaves']));
        $this->assert("Audit new values recorded approve_leaves false", empty($newJson['permissions']['approve_leaves']));
    }

    private function testPermissionRevocationWorkflow(): void {
        echo "\n--- Suite 8: Permission Enforcement Workflow ---\n";
        // User with manager role has approve_leaves = false
        $mgrUser = ['id' => 99, 'role' => 'manager'];
        $canApprove = RolePermission::canUser($mgrUser, 'approve_leaves');
        $this->assert("canUser check correctly denies approve_leaves when turned off", !$canApprove);

        // Turn approve_leaves back on
        $perms = RolePermission::getPermissionsForRole('manager');
        $perms['approve_leaves'] = true;
        RolePermission::saveRolePermissions('manager', $perms, 1);

        $canApproveAfter = RolePermission::canUser($mgrUser, 'approve_leaves');
        $this->assert("canUser check grants approve_leaves after re-enabling", $canApproveAfter);
    }

    private function testRoleResetDefaults(): void {
        echo "\n--- Suite 9: Role Reset to Default Presets ---\n";
        // Modify employee to have view_finances = false
        $empPerms = RolePermission::getPermissionsForRole('employee');
        $empPerms['view_finances'] = false;
        RolePermission::saveRolePermissions('employee', $empPerms, 1);
        $this->assert("Employee view_finances was modified to false", !RolePermission::hasPermission('employee', 'view_finances'));

        // Reset employee to system defaults
        $reset = RolePermission::resetRoleDefaults('employee', 1);
        $this->assert("RolePermission::resetRoleDefaults returned true", $reset);

        $afterReset = RolePermission::getPermissionsForRole('employee');
        $this->assert("Employee view_finances is restored to true after reset", !empty($afterReset['view_finances']));
        $this->assert("Employee approve_leaves remains false as per preset", empty($afterReset['approve_leaves']));

        // Verify audit log row for reset
        $stmt = $this->db->prepare("
            SELECT action, target_type, new_values FROM audit_log
            WHERE action = 'role_permissions_reset' AND target_type = 'role_permission'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute();
        $audit = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assert("Audit log row created for role_permissions_reset", !empty($audit));
        $newJson = json_decode($audit['new_values'] ?? '{}', true);
        $this->assert("Audit reset records target role employee", ($newJson['role'] ?? '') === 'employee');
    }
}

$suite = new RolesTestSuite();
$suite->run();
