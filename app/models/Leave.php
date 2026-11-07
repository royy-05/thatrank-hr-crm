<?php
// app/models/Leave.php

namespace App\Models;

use App\Config\Database;
use App\Services\AuditService;
use PDO;

class Leave {
    public static function getLeaveTypes(bool $onlyActive = true): array {
        $db = Database::getConnection();
        $sql = "SELECT id, name, code, days_per_year, days_per_year AS yearly_quota, is_paid, is_active, created_at, updated_at FROM leave_types" . ($onlyActive ? " WHERE is_active = 1" : "") . " ORDER BY id ASC";
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getBalances(int $userId, ?int $year = null): array {
        $db = Database::getConnection();
        $year = $year ?: (int)date('Y');

        $stmt = $db->prepare("SELECT * FROM leave_balances WHERE user_id = :uid AND year = :yr LIMIT 1");
        $stmt->execute([':uid' => $userId, ':yr' => $year]);
        $bal = $stmt->fetch();

        // Fetch active leave types configured in settings
        $leaveTypes = self::getLeaveTypes(true);
        $typeQuotaMap = [];
        foreach ($leaveTypes as $lt) {
            $typeQuotaMap[$lt['code']] = (float)$lt['days_per_year'];
        }

        if (!$bal) {
            // Default initialization using configured leave_types
            $bal = [
                'user_id'         => $userId,
                'year'            => $year,
                'casual_total'    => $typeQuotaMap['casual'] ?? 0.0,
                'casual_used'     => 0.0,
                'casual_accrued'  => $typeQuotaMap['casual'] ?? 0.0,
                'sick_total'      => $typeQuotaMap['sick'] ?? 0.0,
                'sick_used'       => 0.0,
                'sick_accrued'    => $typeQuotaMap['sick'] ?? 0.0,
                'earned_total'    => $typeQuotaMap['earned'] ?? 0.0,
                'earned_used'     => 0.0,
                'earned_carried'  => 0.0,
                'comp_off_total'  => $typeQuotaMap['comp_off'] ?? 0.0,
                'comp_off_used'   => 0.0,
            ];
            $insert = $db->prepare("
                INSERT INTO leave_balances (user_id, year, casual_total, casual_used, casual_accrued, sick_total, sick_used, sick_accrued, earned_total, earned_used, earned_carried, comp_off_total, comp_off_used)
                VALUES (:uid, :yr, :ct, 0.0, :ca, :st, 0.0, :sa, :et, 0.0, 0.0, :cot, 0.0)
            ");
            $insert->execute([
                ':uid' => $userId,
                ':yr'  => $year,
                ':ct'  => $bal['casual_total'],
                ':ca'  => $bal['casual_accrued'],
                ':st'  => $bal['sick_total'],
                ':sa'  => $bal['sick_accrued'],
                ':et'  => $bal['earned_total'],
                ':cot' => $bal['comp_off_total'],
            ]);
            $bal['id'] = (int)$db->lastInsertId();
        } else {
            // Synchronize with active leave_types if admin updated the quota
            $needUpdate = false;
            if (isset($typeQuotaMap['casual']) && (float)$bal['casual_total'] !== $typeQuotaMap['casual']) {
                $bal['casual_total'] = $typeQuotaMap['casual'];
                $bal['casual_accrued'] = $typeQuotaMap['casual'];
                $needUpdate = true;
            }
            if (isset($typeQuotaMap['sick']) && (float)$bal['sick_total'] !== $typeQuotaMap['sick']) {
                $bal['sick_total'] = $typeQuotaMap['sick'];
                $bal['sick_accrued'] = $typeQuotaMap['sick'];
                $needUpdate = true;
            }
            if (isset($typeQuotaMap['earned']) && (float)$bal['earned_total'] !== $typeQuotaMap['earned']) {
                $bal['earned_total'] = $typeQuotaMap['earned'];
                $needUpdate = true;
            }
            if (isset($typeQuotaMap['comp_off']) && (float)$bal['comp_off_total'] !== $typeQuotaMap['comp_off']) {
                $bal['comp_off_total'] = $typeQuotaMap['comp_off'];
                $needUpdate = true;
            }

            if ($needUpdate && !empty($bal['id'])) {
                $up = $db->prepare("
                    UPDATE leave_balances
                    SET casual_total = :ct, casual_accrued = :ca, sick_total = :st, sick_accrued = :sa, earned_total = :et, comp_off_total = :cot
                    WHERE id = :id
                ");
                $up->execute([
                    ':ct'  => $bal['casual_total'],
                    ':ca'  => $bal['casual_accrued'],
                    ':st'  => $bal['sick_total'],
                    ':sa'  => $bal['sick_accrued'],
                    ':et'  => $bal['earned_total'],
                    ':cot' => $bal['comp_off_total'],
                    ':id'  => $bal['id'],
                ]);
            }
        }

        $bal['casual_available'] = max(0.0, (float)$bal['casual_total'] - (float)$bal['casual_used']);
        $bal['sick_available']   = max(0.0, (float)$bal['sick_total'] - (float)$bal['sick_used']);
        $bal['earned_available'] = max(0.0, ((float)$bal['earned_total'] + (float)$bal['earned_carried']) - (float)$bal['earned_used']);
        $bal['comp_off_available']= max(0.0, (float)$bal['comp_off_total'] - (float)$bal['comp_off_used']);
        $bal['total_available']  = $bal['casual_available'] + $bal['sick_available'] + $bal['earned_available'] + $bal['comp_off_available'];

        // Build dynamic cards from leave_types table
        $cards = [];
        foreach ($leaveTypes as $lt) {
            $code = $lt['code'];
            $name = $lt['name'];
            $quota = (float)$lt['days_per_year'];
            $used = 0.0;
            $accrued = $quota;
            $carried = 0.0;

            if ($code === 'casual') {
                $quota = (float)$bal['casual_total'];
                $used = (float)$bal['casual_used'];
                $accrued = (float)$bal['casual_accrued'];
                $avail = $bal['casual_available'];
                $color = 'var(--accent)';
            } elseif ($code === 'sick') {
                $quota = (float)$bal['sick_total'];
                $used = (float)$bal['sick_used'];
                $accrued = (float)$bal['sick_accrued'];
                $avail = $bal['sick_available'];
                $color = 'var(--warn)';
            } elseif ($code === 'earned') {
                $quota = (float)$bal['earned_total'];
                $used = (float)$bal['earned_used'];
                $carried = (float)$bal['earned_carried'];
                $accrued = $quota;
                $avail = $bal['earned_available'];
                $color = 'var(--ok)';
            } elseif ($code === 'comp_off') {
                $quota = (float)$bal['comp_off_total'];
                $used = (float)$bal['comp_off_used'];
                $accrued = $quota;
                $avail = $bal['comp_off_available'];
                $color = 'var(--accent)';
            } else {
                // Query used days for custom leave type
                $uStmt = $db->prepare("
                    SELECT COALESCE(SUM(days_count), 0)
                    FROM leave_requests
                    WHERE user_id = :uid AND leave_type = :code AND status = 'approved' AND YEAR(start_date) = :yr
                ");
                $uStmt->execute([':uid' => $userId, ':code' => $code, ':yr' => $year]);
                $used = (float)$uStmt->fetchColumn();
                $avail = max(0.0, $quota - $used);
                $color = 'var(--accent)';
            }

            $cards[] = [
                'code'      => $code,
                'name'      => $name,
                'quota'     => $quota,
                'total'     => $quota,
                'used'      => $used,
                'accrued'   => $accrued,
                'carried'   => $carried,
                'available' => $avail,
                'color'     => $color,
            ];
        }

        $bal['cards'] = $cards;
        return $bal;
    }

    public static function getStats(int $userId, ?int $year = null): array {
        $db = Database::getConnection();
        $year = $year ?: (int)date('Y');
        $startYear = "{$year}-01-01";
        $endYear = "{$year}-12-31";

        $stmt = $db->prepare("
            SELECT leave_type, start_date, end_date, days_count, duration_type
            FROM leave_requests
            WHERE user_id = :uid 
              AND status = 'approved'
              AND start_date BETWEEN :start AND :end
            ORDER BY start_date ASC
        ");
        $stmt->execute([':uid' => $userId, ':start' => $startYear, ':end' => $endYear]);
        $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $byType = ['casual' => 0.0, 'earned' => 0.0, 'sick' => 0.0, 'other' => 0.0];
        $monthly = array_fill(1, 12, 0.0);
        $weeklyPattern = ['Mon' => 0.0, 'Tue' => 0.0, 'Wed' => 0.0, 'Thu' => 0.0, 'Fri' => 0.0, 'Sat' => 0.0, 'Sun' => 0.0];
        $totalDays = 0.0;

        foreach ($leaves as $l) {
            $type = $l['leave_type'];
            $days = (float)$l['days_count'];
            $totalDays += $days;

            if (isset($byType[$type])) {
                $byType[$type] += $days;
            } else {
                $byType['other'] += $days;
            }

            $m = (int)date('n', strtotime($l['start_date']));
            if (isset($monthly[$m])) {
                $monthly[$m] += $days;
            }

            $dow = date('D', strtotime($l['start_date']));
            if (isset($weeklyPattern[$dow])) {
                $weeklyPattern[$dow] += $days;
            }
        }

        // If no individual requests logged yet, fallback to used quotas in balance
        $bal = self::getBalances($userId, $year);
        if ($byType['casual'] <= 0 && (float)$bal['casual_used'] > 0) $byType['casual'] = (float)$bal['casual_used'];
        if ($byType['sick'] <= 0 && (float)$bal['sick_used'] > 0) $byType['sick'] = (float)$bal['sick_used'];
        if ($byType['earned'] <= 0 && (float)$bal['earned_used'] > 0) $byType['earned'] = (float)$bal['earned_used'];
        if ($totalDays <= 0) $totalDays = $byType['casual'] + $byType['sick'] + $byType['earned'];

        return [
            'year'            => $year,
            'total_days_year' => $totalDays,
            'by_type'         => $byType,
            'monthly'         => $monthly,
            'weekly_pattern'  => $weeklyPattern,
        ];
    }

    public static function submitRequest(int $userId, array $data): int {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, days_count, duration_type, reason, status, created_at)
            VALUES (:uid, :type, :start, :end, :days, :dur, :reason, :status, NOW())
        ");

        $days = (float)($data['days_count'] ?? 1.0);
        if ($data['duration_type'] === 'half_day') {
            $days = 0.5;
        } elseif ($data['duration_type'] === 'short_leave') {
            $days = 0.25;
        }

        $status = !empty($data['is_draft']) ? 'draft' : 'pending';

        $params = [
            ':uid'    => $userId,
            ':type'   => $data['leave_type'] ?? 'casual',
            ':start'  => $data['start_date'] ?? date('Y-m-d'),
            ':end'    => $data['end_date'] ?? date('Y-m-d'),
            ':days'   => $days,
            ':dur'    => $data['duration_type'] ?? 'full_day',
            ':reason' => trim($data['reason'] ?? 'Personal'),
            ':status' => $status,
        ];

        $stmt->execute($params);
        $reqId = (int)$db->lastInsertId();

        AuditService::log($userId, 'leave_request_submit', 'leave_request', $reqId, null, $params);

        return $reqId;
    }

    public static function withdrawRequest(int $requestId, int $userId): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM leave_requests WHERE id = :id AND user_id = :uid LIMIT 1");
        $stmt->execute([':id' => $requestId, ':uid' => $userId]);
        $old = $stmt->fetch();
        if (!$old || in_array($old['status'], ['withdrawn', 'rejected'], true)) {
            return false;
        }

        // If previously approved, refund balance
        if ($old['status'] === 'approved') {
            self::adjustBalance($userId, $old['leave_type'], -(float)$old['days_count']);
        }

        $up = $db->prepare("UPDATE leave_requests SET status = 'withdrawn', updated_at = NOW() WHERE id = :id");
        $res = $up->execute([':id' => $requestId]);

        if ($res) {
            AuditService::log($userId, 'leave_request_withdraw', 'leave_request', $requestId, $old, ['status' => 'withdrawn']);
        }
        return $res;
    }

    public static function actionRequest(int $requestId, string $newStatus, int $actorId, ?string $reason = null): bool {
        if (!in_array($newStatus, ['approved', 'rejected'], true)) {
            return false;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM leave_requests WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $requestId]);
        $old = $stmt->fetch();
        if (!$old || $old['status'] !== 'pending') {
            return false;
        }

        $db->beginTransaction();
        try {
            $up = $db->prepare("
                UPDATE leave_requests
                SET status = :status, actioned_by = :actor, actioned_at = NOW(), rejection_reason = :reason, updated_at = NOW()
                WHERE id = :id
            ");
            $up->execute([
                ':status' => $newStatus,
                ':actor'  => $actorId,
                ':reason' => $reason,
                ':id'     => $requestId,
            ]);

            // If approved, update leave_balances and mark attendance_records
            if ($newStatus === 'approved') {
                self::adjustBalance((int)$old['user_id'], $old['leave_type'], (float)$old['days_count']);

                // Create attendance record for the leave days and close any open clock-in session
                $curr = strtotime($old['start_date']);
                $end  = strtotime($old['end_date']);
                while ($curr <= $end) {
                    $d = date('Y-m-d', $curr);
                    $attStmt = $db->prepare("
                        INSERT INTO attendance_records (user_id, date, status, current_status, last_out, notes, created_at)
                        VALUES (:uid, :date, 'on_leave', 'clocked_out', CURTIME(), :notes, NOW())
                        ON DUPLICATE KEY UPDATE 
                            status = IF(first_in IS NOT NULL OR work_seconds > 0, status, 'on_leave'),
                            notes = VALUES(notes), 
                            updated_at = NOW()
                    ");
                    $attStmt->execute([
                        ':uid'   => $old['user_id'],
                        ':date'  => $d,
                        ':notes' => ucfirst($old['leave_type']) . ' leave approved',
                    ]);
                    $curr = strtotime('+1 day', $curr);
                }

                // If leave covers today, ensure heartbeat is recorded as off
                $today = date('Y-m-d');
                if ($old['start_date'] <= $today && $old['end_date'] >= $today) {
                    Heartbeat::recordBeat((int)$old['user_id'], 'off');
                }
            }

            $stmt->execute([':id' => $requestId]);
            $new = $stmt->fetch();

            AuditService::log($actorId, 'leave_action_' . $newStatus, 'leave_request', $requestId, $old, $new);

            $db->commit();
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Leave action error: " . $e->getMessage());
            return false;
        }
    }

    private static function adjustBalance(int $userId, string $type, float $delta): void {
        $db = Database::getConnection();
        $year = (int)date('Y');
        $field = match ($type) {
            'sick'     => 'sick_used',
            'earned'   => 'earned_used',
            'comp_off' => 'comp_off_used',
            default    => 'casual_used',
        };

        $stmt = $db->prepare("
            UPDATE leave_balances
            SET `{$field}` = GREATEST(0.0, `{$field}` + :delta), updated_at = NOW()
            WHERE user_id = :uid AND year = :yr
        ");
        $stmt->execute([':delta' => $delta, ':uid' => $userId, ':yr' => $year]);
    }

    public static function getPendingRequestsForApprover(array $viewer): array {
        $db = Database::getConnection();
        if ($viewer['role'] === 'admin') {
            $stmt = $db->query("
                SELECT lr.*, u.name as employee_name, u.department, u.avatar_initials, u.avatar_color, u.job_title
                FROM leave_requests lr
                JOIN users u ON lr.user_id = u.id
                WHERE lr.status = 'pending'
                ORDER BY lr.created_at ASC
            ");
            return $stmt->fetchAll();
        }

        if ($viewer['role'] === 'manager') {
            $stmt = $db->prepare("
                SELECT lr.*, u.name as employee_name, u.department, u.avatar_initials, u.avatar_color, u.job_title
                FROM leave_requests lr
                JOIN users u ON lr.user_id = u.id
                WHERE lr.status = 'pending' AND u.manager_id = :mgr_id
                ORDER BY lr.created_at ASC
            ");
            $stmt->execute([':mgr_id' => $viewer['id']]);
            return $stmt->fetchAll();
        }

        return [];
    }

    public static function getHistory(int $userId, int $limit = 20, int $offset = 0, ?string $type = null, ?string $status = null, ?int $year = null): array {
        $db = Database::getConnection();
        $where = ["lr.user_id = :uid"];
        $params = [':uid' => $userId];

        if ($type && $type !== 'all' && $type !== 'All types') {
            $where[] = "lr.leave_type = :type";
            $params[':type'] = strtolower($type);
        }
        if ($status && $status !== 'any' && $status !== 'Any status') {
            $where[] = "lr.status = :status";
            $params[':status'] = strtolower($status);
        }
        if ($year && $year > 0) {
            $where[] = "YEAR(lr.start_date) = :year";
            $params[':year'] = $year;
        }

        $whereClause = implode(" AND ", $where);
        $stmt = $db->prepare("
            SELECT lr.*, a.name as approver_name
            FROM leave_requests lr
            LEFT JOIN users a ON lr.actioned_by = a.id
            WHERE {$whereClause}
            ORDER BY lr.start_date DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Leave requests for a set of users, with the filters on the Leave screen.
     * Filters: type, status, from, to, year, search, exclude_drafts.
     *
     * @return array{rows: array, total: int}
     */
    public static function listRequests(array $userIds, array $filters, int $limit, int $offset): array {
        if (empty($userIds)) {
            return ['rows' => [], 'total' => 0];
        }

        $db = Database::getConnection();
        $where = ['lr.user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')'];
        $params = array_values($userIds);

        if (!empty($filters['type'])) {
            $where[] = 'lr.leave_type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'lr.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['exclude_drafts'])) {
            $where[] = "lr.status <> 'draft'";
        }
        if (!empty($filters['from'])) {
            $where[] = 'lr.end_date >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'lr.start_date <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['year'])) {
            $where[] = 'YEAR(lr.start_date) = ?';
            $params[] = (int)$filters['year'];
        }
        if (!empty($filters['search'])) {
            $like = '%' . addcslashes($filters['search'], '%_\\') . '%';
            $where[] = '(lr.reason LIKE ? OR lr.rejection_reason LIKE ? OR lr.leave_type LIKE ? OR u.name LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM leave_requests lr
            JOIN users u ON lr.user_id = u.id
            WHERE {$whereSql}
        ");
        $countStmt->execute($params);

        $stmt = $db->prepare("
            SELECT lr.*, u.name as employee_name, u.job_title, u.avatar_initials, u.avatar_color,
                   a.name as approver_name, m.name as manager_name
            FROM leave_requests lr
            JOIN users u ON lr.user_id = u.id
            LEFT JOIN users a ON lr.actioned_by = a.id
            LEFT JOIN users m ON u.manager_id = m.id
            WHERE {$whereSql}
            ORDER BY lr.start_date DESC, lr.id DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset);
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => (int)$countStmt->fetchColumn()];
    }

    public static function getAvailableYears(int $userId): array {
        $db = Database::getConnection();
        $years = [(int)date('Y')];

        $stmt = $db->prepare("SELECT DISTINCT YEAR(start_date) as y FROM leave_requests WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['y'])) $years[] = (int)$row['y'];
        }

        $stmt2 = $db->prepare("SELECT DISTINCT year FROM leave_balances WHERE user_id = :uid");
        $stmt2->execute([':uid' => $userId]);
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['year'])) $years[] = (int)$row['year'];
        }

        $years = array_unique($years);
        rsort($years);
        return array_values($years);
    }

    public static function submitCompOff(int $userId, string $date, float $hours, string $reason): int {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO comp_off_requests (user_id, worked_date, hours_worked, reason, status, created_at)
            VALUES (:uid, :date, :hrs, :reason, 'pending', NOW())
        ");
        $stmt->execute([
            ':uid'    => $userId,
            ':date'   => $date,
            ':hrs'    => $hours,
            ':reason' => $reason
        ]);
        $id = (int)$db->lastInsertId();
        AuditService::log($userId, 'comp_off_request', 'comp_off_request', $id, null, ['worked_date' => $date, 'hours' => $hours, 'reason' => $reason]);
        return $id;
    }

    public static function getPendingCompOff(array $viewer): array {
        $db = Database::getConnection();
        if ($viewer['role'] === 'admin') {
            $stmt = $db->query("
                SELECT c.*, u.name as employee_name, u.department, u.avatar_initials, u.avatar_color
                FROM comp_off_requests c
                JOIN users u ON c.user_id = u.id
                WHERE c.status = 'pending'
                ORDER BY c.created_at ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($viewer['role'] === 'manager') {
            $stmt = $db->prepare("
                SELECT c.*, u.name as employee_name, u.department, u.avatar_initials, u.avatar_color
                FROM comp_off_requests c
                JOIN users u ON c.user_id = u.id
                WHERE c.status = 'pending' AND u.manager_id = :mgr_id
                ORDER BY c.created_at ASC
            ");
            $stmt->execute([':mgr_id' => $viewer['id']]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return [];
    }

    public static function actionCompOff(int $id, string $status, int $actorId): bool {
        if (!in_array($status, ['approved', 'rejected'], true)) return false;
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM comp_off_requests WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req || $req['status'] !== 'pending') return false;

        $db->beginTransaction();
        try {
            $up = $db->prepare("
                UPDATE comp_off_requests
                SET status = :status, actioned_by = :act, actioned_at = NOW()
                WHERE id = :id
            ");
            $up->execute([':status' => $status, ':act' => $actorId, ':id' => $id]);

            if ($status === 'approved') {
                $days = round($req['hours_worked'] / 8.0, 1);
                $year = (int)date('Y');
                $bUp = $db->prepare("
                    UPDATE leave_balances
                    SET comp_off_total = comp_off_total + :days
                    WHERE user_id = :uid AND year = :yr
                ");
                $bUp->execute([':days' => $days, ':uid' => $req['user_id'], ':yr' => $year]);
            }
            $db->commit();
            AuditService::log($actorId, 'comp_off_action', 'comp_off_request', $id, $req, ['status' => $status]);
            return true;
        } catch (\Exception $e) {
            $db->rollBack();
            return false;
        }
    }

    public static function getCount(int $userId): int {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) FROM leave_requests WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }
}
