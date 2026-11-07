<?php
// app/models/RolePermission.php

namespace App\Models;

use App\Config\Database;
use App\Services\AuditService;
use PDO;

class RolePermission {
    /**
     * Complete catalog of all tabs, modules, and granular permissions.
     */
    public static function getPermissionCatalog(): array {
        return [
            'dashboard' => [
                'title'       => 'Dashboard',
                'description' => 'Main workspace dashboard and metrics',
                'permissions' => [
                    'view_dashboard' => ['name' => 'View Dashboard', 'desc' => 'Access the main dashboard overview', 'default_mgr' => 1, 'default_emp' => 1],
                ],
            ],
            'attendance' => [
                'title'       => 'My Attendance',
                'description' => 'Punching, timesheets, and correction requests',
                'permissions' => [
                    'view_attendance'    => ['name' => 'View Attendance', 'desc' => 'Access personal attendance log and hours', 'default_mgr' => 1, 'default_emp' => 1],
                    'clock_in_out'       => ['name' => 'Web Clock-in / Out', 'desc' => 'Record clock-in, break, and clock-out punches', 'default_mgr' => 1, 'default_emp' => 1],
                    'request_correction' => ['name' => 'Request Corrections', 'desc' => 'Submit attendance punch correction requests', 'default_mgr' => 1, 'default_emp' => 1],
                ],
            ],
            'leave' => [
                'title'       => 'Leave',
                'description' => 'Leave quotas, applications, and comp-off',
                'permissions' => [
                    'view_leave'     => ['name' => 'View Leave', 'desc' => 'Access leave balances and history', 'default_mgr' => 1, 'default_emp' => 1],
                    'request_leave'   => ['name' => 'Submit Leave Requests', 'desc' => 'Apply for casual, sick, or other leave', 'default_mgr' => 1, 'default_emp' => 1],
                    'request_compoff' => ['name' => 'Request Comp-off', 'desc' => 'Apply for credit for extra days worked', 'default_mgr' => 1, 'default_emp' => 1],
                ],
            ],
            'community' => [
                'title'       => 'Community',
                'description' => 'Company social hub, announcements, and polls',
                'permissions' => [
                    'view_community' => ['name' => 'View Community', 'desc' => 'Access community board and announcements', 'default_mgr' => 1, 'default_emp' => 1],
                    'create_post'    => ['name' => 'Create Posts', 'desc' => 'Publish posts and comments on community feed', 'default_mgr' => 1, 'default_emp' => 1],
                    'create_poll'    => ['name' => 'Create Polls', 'desc' => 'Create and run interactive team polls', 'default_mgr' => 1, 'default_emp' => 1],
                    'give_praise'    => ['name' => 'Send Peer Praise', 'desc' => 'Recognize and praise coworkers publicly', 'default_mgr' => 1, 'default_emp' => 1],
                ],
            ],
            'finances' => [
                'title'       => 'My Finances',
                'description' => 'Salary breakdown, payslips, and tax summary',
                'permissions' => [
                    'view_finances'        => ['name' => 'View Finances', 'desc' => 'Access personal payslips and breakdown', 'default_mgr' => 1, 'default_emp' => 1],
                    'download_payslip_pdf' => ['name' => 'Download Payslip PDF', 'desc' => 'Download monthly payslip PDF files', 'default_mgr' => 1, 'default_emp' => 1],
                ],
            ],
            'liveboard' => [
                'title'       => 'Live Board',
                'description' => 'Real-time team presence and idle tracking',
                'permissions' => [
                    'view_live_board' => ['name' => 'View Live Board', 'desc' => 'See who is currently working, idle, or on break', 'default_mgr' => 1, 'default_emp' => 0],
                ],
            ],
            'approvals' => [
                'title'       => 'Approvals',
                'description' => 'Review and action team leave and correction requests',
                'permissions' => [
                    'view_approvals'      => ['name' => 'View Approvals Tab', 'desc' => 'Access approvals queue', 'default_mgr' => 1, 'default_emp' => 0],
                    'approve_leaves'      => ['name' => 'Approve / Reject Leaves', 'desc' => 'Can approve or decline leave applications', 'default_mgr' => 1, 'default_emp' => 0],
                    'approve_corrections' => ['name' => 'Approve / Reject Corrections', 'desc' => 'Can approve or decline attendance correction requests', 'default_mgr' => 1, 'default_emp' => 0],
                    'approve_compoff'     => ['name' => 'Approve / Reject Comp-off', 'desc' => 'Can approve or decline comp-off requests', 'default_mgr' => 1, 'default_emp' => 0],
                ],
            ],
            'reports' => [
                'title'       => 'Reports',
                'description' => 'Weekly and monthly team hours reporting',
                'permissions' => [
                    'view_reports'   => ['name' => 'View Reports', 'desc' => 'Access work hours and team attendance reports', 'default_mgr' => 1, 'default_emp' => 0],
                    'export_reports' => ['name' => 'Export Reports', 'desc' => 'Download reports in CSV or PDF format', 'default_mgr' => 1, 'default_emp' => 0],
                ],
            ],
            'payroll' => [
                'title'       => 'Payroll',
                'description' => 'Company payroll runs, salary structures, and settings',
                'permissions' => [
                    'view_payroll'             => ['name' => 'View Payroll', 'desc' => 'Access payroll dashboard and preview table', 'default_mgr' => 0, 'default_emp' => 0],
                    'run_payroll'              => ['name' => 'Run & Finalize Payroll', 'desc' => 'Generate, recalculate, finalize, and pay runs', 'default_mgr' => 0, 'default_emp' => 0],
                    'manage_salary_components' => ['name' => 'Manage Components', 'desc' => 'Create, edit, reorder, and toggle components', 'default_mgr' => 0, 'default_emp' => 0],
                    'manage_salary_structures' => ['name' => 'Manage Structures', 'desc' => 'Assign and edit employee CTC structures', 'default_mgr' => 0, 'default_emp' => 0],
                    'export_payroll'           => ['name' => 'Export Payroll Files', 'desc' => 'Download bank transfer CSV, summary CSV, and ZIP', 'default_mgr' => 0, 'default_emp' => 0],
                ],
            ],
            'employees' => [
                'title'       => 'Employees',
                'description' => 'Employee staff directory and profile management',
                'permissions' => [
                    'view_employees'     => ['name' => 'View Employees', 'desc' => 'Access employee directory list', 'default_mgr' => 0, 'default_emp' => 0],
                    'manage_employees'   => ['name' => 'Manage Employees', 'desc' => 'Create and edit employee profiles', 'default_mgr' => 0, 'default_emp' => 0],
                    'offboard_employees' => ['name' => 'Offboard Employees', 'desc' => 'Deactivate or offboard staff accounts', 'default_mgr' => 0, 'default_emp' => 0],
                    'import_employees'   => ['name' => 'Import CSV', 'desc' => 'Bulk import employees from CSV files', 'default_mgr' => 0, 'default_emp' => 0],
                ],
            ],
            'teams' => [
                'title'       => 'Teams',
                'description' => 'Department and squad organizational management',
                'permissions' => [
                    'view_teams'   => ['name' => 'View Teams', 'desc' => 'View company teams and assignments', 'default_mgr' => 0, 'default_emp' => 0],
                    'manage_teams' => ['name' => 'Manage Teams', 'desc' => 'Create, edit, and assign team managers', 'default_mgr' => 0, 'default_emp' => 0],
                ],
            ],
            'audit' => [
                'title'       => 'Audit Log',
                'description' => 'Security and administrative change logs',
                'permissions' => [
                    'view_audit_log' => ['name' => 'View Audit Log', 'desc' => 'Access security audit trail and system changes', 'default_mgr' => 0, 'default_emp' => 0],
                ],
            ],
            'settings' => [
                'title'       => 'Settings',
                'description' => 'Work rules, leave types, and role permissions',
                'permissions' => [
                    'view_settings' => ['name' => 'View Settings', 'desc' => 'Access company settings and work rules', 'default_mgr' => 0, 'default_emp' => 0],
                    'edit_settings' => ['name' => 'Edit Settings', 'desc' => 'Modify timings, late fines, and leave types', 'default_mgr' => 0, 'default_emp' => 0],
                    'manage_roles'  => ['name' => 'Manage Roles', 'desc' => 'Configure role permissions and access control', 'default_mgr' => 0, 'default_emp' => 0],
                ],
            ],
        ];
    }

    /**
     * Get associative map of permission_key => bool for a specific role.
     */
    public static function getPermissionsForRole(string $role): array {
        $db = Database::getConnection();
        $catalog = self::getPermissionCatalog();

        // Admin always has full access
        if ($role === 'admin') {
            $all = [];
            foreach ($catalog as $mod) {
                foreach ($mod['permissions'] as $k => $info) {
                    $all[$k] = true;
                }
            }
            return $all;
        }

        $stmt = $db->prepare("SELECT permission_key, granted FROM role_permissions WHERE role = :r");
        $stmt->execute([':r' => $role]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $result = [];
        foreach ($catalog as $mod) {
            foreach ($mod['permissions'] as $k => $info) {
                if (isset($rows[$k])) {
                    $result[$k] = (bool)(int)$rows[$k];
                } else {
                    $defaultKey = ($role === 'manager') ? 'default_mgr' : 'default_emp';
                    $result[$k] = !empty($info[$defaultKey]);
                }
            }
        }

        return $result;
    }

    /**
     * Check if a role has a specific permission.
     */
    public static function hasPermission(string $role, string $permissionKey): bool {
        if ($role === 'admin') {
            return true;
        }

        $perms = self::getPermissionsForRole($role);
        return !empty($perms[$permissionKey]);
    }

    /**
     * Check if a user has a specific permission.
     */
    public static function canUser($user, string $permissionKey): bool {
        if (is_numeric($user)) {
            $userObj = User::findById((int)$user);
            if (!$userObj) return false;
            $role = $userObj['role'] ?? 'employee';
        } elseif (is_array($user)) {
            $role = $user['role'] ?? 'employee';
        } else {
            return false;
        }

        return self::hasPermission($role, $permissionKey);
    }

    /**
     * Save updated permissions for a role.
     */
    public static function saveRolePermissions(string $role, array $permissions, int $actorId, string $action = 'role_permissions_update'): bool {
        if ($role === 'admin') {
            // Admin role retains full access
            return true;
        }

        $db = Database::getConnection();
        $old = self::getPermissionsForRole($role);

        $stmt = $db->prepare("
            INSERT INTO role_permissions (role, permission_key, granted, created_at, updated_at)
            VALUES (:r, :k, :g, NOW(), NOW())
            ON DUPLICATE KEY UPDATE granted = VALUES(granted), updated_at = NOW()
        ");

        $catalog = self::getPermissionCatalog();
        $allKeys = [];
        foreach ($catalog as $mod) {
            foreach ($mod['permissions'] as $k => $info) {
                $allKeys[$k] = true;
            }
        }

        $updated = [];
        foreach ($allKeys as $k => $_) {
            $granted = !empty($permissions[$k]) ? 1 : 0;
            $stmt->execute([
                ':r' => $role,
                ':k' => $k,
                ':g' => $granted,
            ]);
            $updated[$k] = (bool)$granted;
        }

        AuditService::log($actorId, $action, 'role_permission', null, $old, [
            'role'        => $role,
            'permissions' => $updated,
        ]);

        return true;
    }

    /**
     * Reset permissions for a role to default catalog values.
     */
    public static function resetRoleDefaults(string $role, int $actorId): bool {
        if ($role === 'admin') {
            return true;
        }

        $catalog = self::getPermissionCatalog();
        $defaults = [];
        $defaultKey = ($role === 'manager') ? 'default_mgr' : 'default_emp';

        foreach ($catalog as $mod) {
            foreach ($mod['permissions'] as $k => $info) {
                $defaults[$k] = !empty($info[$defaultKey]);
            }
        }

        return self::saveRolePermissions($role, $defaults, $actorId, 'role_permissions_reset');
    }
}
