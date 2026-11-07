<?php
// app/models/PayrollRun.php

namespace App\Models;

use App\Config\Database;
use App\Services\AuditService;
use PDO;

class PayrollRun {
    public static function getByMonth(int $month, int $year): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM payroll_runs WHERE month = :m AND year = :y LIMIT 1");
        $stmt->execute([':m' => $month, ':y' => $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function getById(int $id): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM payroll_runs WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function getAll(int $limit = 24): array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT pr.*, u.name as creator_name,
                   (SELECT COUNT(*) FROM payslips WHERE run_id = pr.id) as total_payslips,
                   (SELECT COALESCE(SUM(net_pay), 0) FROM payslips WHERE run_id = pr.id) as total_net,
                   (SELECT COALESCE(SUM(gross), 0) FROM payslips WHERE run_id = pr.id) as total_gross,
                   (SELECT COALESCE(SUM(total_deductions), 0) FROM payslips WHERE run_id = pr.id) as total_deductions,
                   (SELECT COALESCE(SUM(lop_days), 0) FROM payslips WHERE run_id = pr.id) as total_lop_days
            FROM payroll_runs pr
            LEFT JOIN users u ON pr.created_by = u.id
            ORDER BY pr.year DESC, pr.month DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create(int $month, int $year, int $actorId): int {
        $existing = self::getByMonth($month, $year);
        if ($existing) {
            throw new \Exception("A payroll run for " . date('F Y', strtotime("$year-$month-01")) . " already exists.");
        }

        $db = Database::getConnection();
        $settings = Setting::getAllAsMap();
        $paydayDay = min(31, max(1, (int)($settings['payroll_payday'] ?? 30)));
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $paydayDay = min($paydayDay, $daysInMonth);
        $paydayDate = sprintf('%04d-%02d-%02d', $year, $month, $paydayDay);

        $stmt = $db->prepare("
            INSERT INTO payroll_runs (month, year, status, payday_date, created_by, created_at, updated_at)
            VALUES (:m, :y, 'draft', :payday, :creator, NOW(), NOW())
        ");
        $stmt->execute([
            ':m'       => $month,
            ':y'       => $year,
            ':payday'  => $paydayDate,
            ':creator' => $actorId,
        ]);

        $newId = (int)$db->lastInsertId();
        $new = self::getById($newId);
        AuditService::log($actorId, 'payroll_run_create', 'payroll_run', $newId, null, $new);

        return $newId;
    }

    public static function finalize(int $id, int $actorId): bool {
        $run = self::getById($id);
        if (!$run) {
            return false;
        }
        if ($run['status'] === 'finalized' || $run['status'] === 'paid') {
            return true;
        }

        $db = Database::getConnection();
        $settings = Setting::getAllAsMap();
        $visibility = $settings['payslip_visibility'] ?? 'on_finalize';
        $publishNow = ($visibility === 'on_finalize');

        $startedTx = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $startedTx = true;
        }
        try {
            // Lock run
            $stmt = $db->prepare("UPDATE payroll_runs SET status = 'finalized', finalized_at = NOW(), updated_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $id]);

            if ($publishNow) {
                // Publish payslips
                $pStmt = $db->prepare("UPDATE payslips SET status = 'published', published_at = NOW(), updated_at = NOW() WHERE run_id = :rid");
                $pStmt->execute([':rid' => $id]);

                // Insert notifications_queue per employee
                $empStmt = $db->prepare("SELECT id, user_id, net_pay, month, year FROM payslips WHERE run_id = :rid");
                $empStmt->execute([':rid' => $id]);
                $slips = $empStmt->fetchAll(PDO::FETCH_ASSOC);

                $qStmt = $db->prepare("
                    INSERT INTO notifications_queue (event, user_id, payload, status, attempts, next_try_at, created_at)
                    VALUES ('payslip_published', :uid, :payload, 'pending', 0, NOW(), NOW())
                ");

                foreach ($slips as $s) {
                    $payload = json_encode([
                        'payslip_id' => $s['id'],
                        'month'      => $s['month'],
                        'year'       => $s['year'],
                        'net_pay'    => $s['net_pay'],
                        'message'    => 'Your payslip for ' . date('F Y', strtotime("{$s['year']}-{$s['month']}-01")) . ' is now available.',
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                    $qStmt->execute([
                        ':uid'     => $s['user_id'],
                        ':payload' => $payload,
                    ]);
                }
            }

            if ($startedTx && $db->inTransaction()) {
                $db->commit();
            }

            $new = self::getById($id);
            AuditService::log($actorId, 'payroll_run_finalize', 'payroll_run', $id, $run, $new);

            return true;
        } catch (\Throwable $e) {
            if ($startedTx && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Finalize error: " . $e->getMessage());
            return false;
        }
    }

    public static function markPaid(int $id, int $actorId): bool {
        $run = self::getById($id);
        if (!$run) {
            return false;
        }

        $db = Database::getConnection();
        $today = date('Y-m-d');
        $settings = Setting::getAllAsMap();
        $visibility = $settings['payslip_visibility'] ?? 'on_finalize';

        $startedTx = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $startedTx = true;
        }
        try {
            // Update run status to paid
            $stmt = $db->prepare("UPDATE payroll_runs SET status = 'paid', updated_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $id]);

            // Set paid_on for all payslips
            if ($visibility === 'on_paid') {
                $pStmt = $db->prepare("
                    UPDATE payslips
                    SET paid_on = :paid_on,
                        status = 'published',
                        published_at = COALESCE(published_at, NOW()),
                        updated_at = NOW()
                    WHERE run_id = :rid
                ");
            } else {
                $pStmt = $db->prepare("
                    UPDATE payslips
                    SET paid_on = :paid_on,
                        updated_at = NOW()
                    WHERE run_id = :rid
                ");
            }
            $pStmt->execute([
                ':paid_on' => $today,
                ':rid'     => $id,
            ]);

            // If visibility was on_paid, enqueue payslip_published notifications
            if ($visibility === 'on_paid') {
                $empStmt = $db->prepare("SELECT id, user_id, net_pay, month, year FROM payslips WHERE run_id = :rid");
                $empStmt->execute([':rid' => $id]);
                $slips = $empStmt->fetchAll(PDO::FETCH_ASSOC);

                $qStmt = $db->prepare("
                    INSERT INTO notifications_queue (event, user_id, payload, status, attempts, next_try_at, created_at)
                    VALUES ('payslip_published', :uid, :payload, 'pending', 0, NOW(), NOW())
                ");

                foreach ($slips as $s) {
                    $payload = json_encode([
                        'payslip_id' => $s['id'],
                        'month'      => $s['month'],
                        'year'       => $s['year'],
                        'net_pay'    => $s['net_pay'],
                        'paid_on'    => $today,
                        'message'    => 'Your salary for ' . date('F Y', strtotime("{$s['year']}-{$s['month']}-01")) . ' has been paid.',
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                    $qStmt->execute([
                        ':uid'     => $s['user_id'],
                        ':payload' => $payload,
                    ]);
                }
            }

            if ($startedTx && $db->inTransaction()) {
                $db->commit();
            }

            $new = self::getById($id);
            AuditService::log($actorId, 'payroll_run_paid', 'payroll_run', $id, $run, $new);

            return true;
        } catch (\Throwable $e) {
            if ($startedTx && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Mark paid error: " . $e->getMessage());
            return false;
        }
    }

    public static function reopen(int $id, int $actorId): array {
        $run = self::getById($id);
        if (!$run) {
            return ['success' => false, 'error' => 'Payroll run not found.'];
        }

        if ($run['status'] === 'draft') {
            return ['success' => true, 'message' => 'Run is already in draft status.'];
        }

        $db = Database::getConnection();

        // Admin can reopen a finalized run only if no payslip has been marked paid
        $paidCheck = $db->prepare("SELECT COUNT(*) FROM payslips WHERE run_id = :rid AND paid_on IS NOT NULL");
        $paidCheck->execute([':rid' => $id]);
        $paidCount = (int)$paidCheck->fetchColumn();

        if ($paidCount > 0 || $run['status'] === 'paid') {
            return ['success' => false, 'error' => 'Cannot reopen payroll run after payslips have been marked as paid.'];
        }

        $startedTx = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $startedTx = true;
        }
        try {
            $stmt = $db->prepare("UPDATE payroll_runs SET status = 'draft', finalized_at = NULL, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $id]);

            // Revert published payslips back to draft
            $pStmt = $db->prepare("UPDATE payslips SET status = 'draft', published_at = NULL, updated_at = NOW() WHERE run_id = :rid");
            $pStmt->execute([':rid' => $id]);

            if ($startedTx && $db->inTransaction()) {
                $db->commit();
            }

            $new = self::getById($id);
            AuditService::log($actorId, 'payroll_run_reopen', 'payroll_run', $id, $run, $new);

            return ['success' => true, 'message' => 'Payroll run reopened to draft status.'];
        } catch (\Throwable $e) {
            if ($startedTx && $db->inTransaction()) {
                $db->rollBack();
            }
            return ['success' => false, 'error' => 'Failed to reopen payroll run: ' . $e->getMessage()];
        }
    }

    public static function delete(int $id, int $actorId): bool {
        $run = self::getById($id);
        if (!$run || $run['status'] !== 'draft') {
            return false;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM payroll_runs WHERE id = :id AND status = 'draft'");
        $res = $stmt->execute([':id' => $id]);

        if ($res) {
            AuditService::log($actorId, 'payroll_run_delete', 'payroll_run', $id, $run, null);
        }

        return $res;
    }
}
