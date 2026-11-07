<?php
// app/models/SalaryComponent.php

namespace App\Models;

use App\Config\Database;
use App\Services\AuditService;
use PDO;

class SalaryComponent {
    public static function getAll(?string $type = null, bool $onlyActive = false): array {
        $db = Database::getConnection();
        $sql = "SELECT * FROM salary_components WHERE 1=1";
        $params = [];

        if ($type !== null && in_array($type, ['earning', 'deduction'], true)) {
            $sql .= " AND type = :type";
            $params[':type'] = $type;
        }

        if ($onlyActive) {
            $sql .= " AND active = 1";
        }

        $sql .= " ORDER BY sort_order ASC, id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getById(int $id): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM salary_components WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function create(array $data, int $actorId): int {
        $db = Database::getConnection();

        $maxSort = (int)$db->query("SELECT COALESCE(MAX(sort_order), 0) FROM salary_components")->fetchColumn();

        $stmt = $db->prepare("
            INSERT INTO salary_components
            (name, type, calc_type, default_value, is_statutory, applies_to_all, show_on_payslip, sort_order, active, created_at, updated_at)
            VALUES (:name, :type, :calc_type, :default_val, :statutory, :applies_all, :show_slip, :sort, :active, NOW(), NOW())
        ");

        $params = [
            ':name'        => trim($data['name'] ?? 'Component'),
            ':type'        => in_array($data['type'] ?? '', ['earning', 'deduction'], true) ? $data['type'] : 'earning',
            ':calc_type'   => in_array($data['calc_type'] ?? '', ['fixed', 'percent_of_basic', 'percent_of_gross'], true) ? $data['calc_type'] : 'fixed',
            ':default_val' => (float)($data['default_value'] ?? 0.0),
            ':statutory'   => trim($data['is_statutory'] ?? 'none'),
            ':applies_all' => !empty($data['applies_to_all']) ? 1 : 0,
            ':show_slip'   => isset($data['show_on_payslip']) ? (!empty($data['show_on_payslip']) ? 1 : 0) : 1,
            ':sort'        => isset($data['sort_order']) ? (int)$data['sort_order'] : ($maxSort + 1),
            ':active'      => isset($data['active']) ? (!empty($data['active']) ? 1 : 0) : 1,
        ];

        $stmt->execute($params);
        $newId = (int)$db->lastInsertId();

        $new = self::getById($newId);
        AuditService::log($actorId, 'salary_component_create', 'salary_component', $newId, null, $new);

        return $newId;
    }

    public static function update(int $id, array $data, int $actorId): bool {
        $db = Database::getConnection();
        $old = self::getById($id);
        if (!$old) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        if (isset($data['name'])) {
            $fields[] = "`name` = :name";
            $params[':name'] = trim($data['name']);
        }
        if (isset($data['type']) && in_array($data['type'], ['earning', 'deduction'], true)) {
            $fields[] = "`type` = :type";
            $params[':type'] = $data['type'];
        }
        if (isset($data['calc_type']) && in_array($data['calc_type'], ['fixed', 'percent_of_basic', 'percent_of_gross'], true)) {
            $fields[] = "`calc_type` = :calc_type";
            $params[':calc_type'] = $data['calc_type'];
        }
        if (isset($data['default_value'])) {
            $fields[] = "`default_value` = :default_value";
            $params[':default_value'] = (float)$data['default_value'];
        }
        if (isset($data['is_statutory'])) {
            $fields[] = "`is_statutory` = :is_statutory";
            $params[':is_statutory'] = trim($data['is_statutory']);
        }
        if (isset($data['applies_to_all'])) {
            $fields[] = "`applies_to_all` = :applies_to_all";
            $params[':applies_to_all'] = !empty($data['applies_to_all']) ? 1 : 0;
        }
        if (isset($data['show_on_payslip'])) {
            $fields[] = "`show_on_payslip` = :show_on_payslip";
            $params[':show_on_payslip'] = !empty($data['show_on_payslip']) ? 1 : 0;
        }
        if (isset($data['sort_order'])) {
            $fields[] = "`sort_order` = :sort_order";
            $params[':sort_order'] = (int)$data['sort_order'];
        }
        if (isset($data['active'])) {
            $fields[] = "`active` = :active";
            $params[':active'] = !empty($data['active']) ? 1 : 0;
        }

        if (empty($fields)) {
            return true;
        }

        $sql = "UPDATE salary_components SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $db->prepare($sql);
        $res = $stmt->execute($params);

        if ($res) {
            $new = self::getById($id);
            AuditService::log($actorId, 'salary_component_update', 'salary_component', $id, $old, $new);
        }

        return $res;
    }

    public static function toggleActive(int $id, int $actorId): bool {
        $old = self::getById($id);
        if (!$old) {
            return false;
        }
        $newActive = $old['active'] ? 0 : 1;
        return self::update($id, ['active' => $newActive], $actorId);
    }

    public static function reorder(array $orders, int $actorId): bool {
        $db = Database::getConnection();
        $startedTx = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $startedTx = true;
        }
        try {
            $stmt = $db->prepare("UPDATE salary_components SET sort_order = :sort WHERE id = :id");
            foreach ($orders as $item) {
                if (!empty($item['id'])) {
                    $stmt->execute([
                        ':sort' => (int)($item['sort_order'] ?? 0),
                        ':id'   => (int)$item['id'],
                    ]);
                }
            }
            if ($startedTx && $db->inTransaction()) {
                $db->commit();
            }
            AuditService::log($actorId, 'salary_components_reorder', 'salary_component', 0, null, ['order' => $orders]);
            return true;
        } catch (\Throwable $e) {
            if ($startedTx && $db->inTransaction()) {
                $db->rollBack();
            }
            return false;
        }
    }
}
