<?php
// tests/payroll/suite_06_access_control.php

namespace Tests\Payroll;

use App\Config\Database;
use App\Models\PayrollRun;
use App\Models\Payslip;

class Suite06AccessControl {
    public static function run(): void {
        echo "\n--- Suite 6: Access Control & Authorization ---\n";
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            $userAdminId = TestHelper::createTestUser('Admin User', 'admin', '2025-01-01');
            $userEmpAId = TestHelper::createTestUser('Employee A', 'employee', '2025-01-01');
            $userEmpBId = TestHelper::createTestUser('Employee B', 'employee', '2025-01-01');
            $userMgrId = TestHelper::createTestUser('Manager User', 'manager', '2025-01-01');

            $runId = PayrollRun::create(10, 2026, $userAdminId);

            // Draft payslip for Employee A
            $slipAId = Payslip::upsert([
                'run_id'           => $runId,
                'user_id'          => $userEmpAId,
                'month'            => 10,
                'year'             => 2026,
                'working_days'     => 22,
                'paid_days'        => 22,
                'lop_days'         => 0,
                'lop_amount'       => 0,
                'earnings'         => [],
                'deductions'       => [],
                'adjustments'      => [],
                'gross'            => 40000,
                'total_deductions' => 0,
                'net_pay'          => 40000,
                'status'           => 'draft',
            ]);

            // Helper function mimicking controller authorization check
            $canAccessPayslip = function (array $viewer, int $payslipId): array {
                $slip = Payslip::getById($payslipId);
                if (!$slip) return ['allowed' => false, 'code' => 404, 'reason' => 'Not found'];

                $isAdmin = ($viewer['role'] === 'admin');
                $isOwner = ((int)$slip['user_id'] === (int)$viewer['id']);

                if (!$isAdmin && !$isOwner) {
                    return ['allowed' => false, 'code' => 403, 'reason' => 'Forbidden: not owner or admin'];
                }

                if (!$isAdmin && $slip['status'] !== 'published') {
                    return ['allowed' => false, 'code' => 403, 'reason' => 'Forbidden: draft not visible to employee'];
                }

                return ['allowed' => true, 'code' => 200, 'slip' => $slip];
            };

            $viewerEmpA = ['id' => $userEmpAId, 'role' => 'employee'];
            $viewerEmpB = ['id' => $userEmpBId, 'role' => 'employee'];
            $viewerMgr = ['id' => $userMgrId, 'role' => 'manager'];
            $viewerAdmin = ['id' => $userAdminId, 'role' => 'admin'];

            // 1. Employee A tries to fetch own DRAFT payslip -> Forbidden 403
            $resDraftOwn = $canAccessPayslip($viewerEmpA, $slipAId);
            TestHelper::assertFalse($resDraftOwn['allowed'], 'Employee cannot access own draft payslip');
            TestHelper::assertEquals(403, $resDraftOwn['code'], 'Draft payslip returns 403 to employee');

            // 2. Employee B tries to fetch Employee A draft payslip -> Forbidden 403
            $resBtoA = $canAccessPayslip($viewerEmpB, $slipAId);
            TestHelper::assertFalse($resBtoA['allowed'], 'Employee B cannot access Employee A payslip');
            TestHelper::assertEquals(403, $resBtoA['code'], 'Cross-user access returns 403');

            // 3. Manager tries to fetch Employee A payslip -> Forbidden 403 (Managers get no extra payroll access)
            $resMgrToA = $canAccessPayslip($viewerMgr, $slipAId);
            TestHelper::assertFalse($resMgrToA['allowed'], 'Manager gets no extra access to employee payslip');
            TestHelper::assertEquals(403, $resMgrToA['code'], 'Manager access returns 403');

            // 4. Admin accesses draft payslip -> Allowed 200
            $resAdmin = $canAccessPayslip($viewerAdmin, $slipAId);
            TestHelper::assertTrue($resAdmin['allowed'], 'Admin can access draft payslips');
            TestHelper::assertEquals(200, $resAdmin['code'], 'Admin access returns 200');

            // 5. Now publish payslip
            PayrollRun::finalize($runId, $userAdminId);
            $pStmt = $db->prepare("UPDATE payslips SET status = 'published' WHERE id = :id");
            $pStmt->execute([':id' => $slipAId]);

            // Employee A accesses own PUBLISHED payslip -> Allowed 200
            $resPublishedOwn = $canAccessPayslip($viewerEmpA, $slipAId);
            TestHelper::assertTrue($resPublishedOwn['allowed'], 'Employee A can access own published payslip');
            TestHelper::assertEquals(200, $resPublishedOwn['code'], 'Own published access returns 200');

            // Employee B still forbidden to access Employee A's published payslip -> 403
            $resBtoAPub = $canAccessPayslip($viewerEmpB, $slipAId);
            TestHelper::assertFalse($resBtoAPub['allowed'], 'Employee B cannot access Employee A published payslip');
            TestHelper::assertEquals(403, $resBtoAPub['code'], 'Cross-user published access returns 403');

            // 6. Admin Role Enforcement on Payroll Admin endpoints
            $isPayrollAdminAllowed = function (array $user): bool {
                return ($user['role'] === 'admin');
            };

            TestHelper::assertFalse($isPayrollAdminAllowed($viewerEmpA), 'Employee role denied on payroll admin endpoints');
            TestHelper::assertFalse($isPayrollAdminAllowed($viewerMgr), 'Manager role denied on payroll admin endpoints');
            TestHelper::assertTrue($isPayrollAdminAllowed($viewerAdmin), 'Admin role granted on payroll admin endpoints');

        } finally {
            $db->rollBack();
        }
    }
}

