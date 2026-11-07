<?php
// app/models/SalaryStructure.php

namespace App\Models;

use App\Config\Database;
use App\Services\AuditService;
use PDO;

class SalaryStructure {
    public static function getForUser(int $userId, ?string $asOfDate = null): ?array {
        $db = Database::getConnection();
        $date = $asOfDate ?: date('Y-m-d');

        $stmt = $db->prepare("
            SELECT s.*, u.name as creator_name
            FROM salary_structures s
            LEFT JOIN users u ON s.created_by = u.id
            WHERE s.user_id = :uid AND s.effective_from <= :as_of
            ORDER BY s.effective_from DESC, s.id DESC
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId, ':as_of' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['component_values'])) {
            $row['component_values'] = json_decode($row['component_values'], true) ?: [];
        }

        return $row ?: null;
    }

    public static function getHistory(int $userId): array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT s.*, u.name as creator_name
            FROM salary_structures s
            LEFT JOIN users u ON s.created_by = u.id
            WHERE s.user_id = :uid
            ORDER BY s.effective_from DESC, s.id DESC
        ");
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['component_values'] = json_decode($r['component_values'], true) ?: [];
        }

        return $rows;
    }

    public static function setStructure(int $userId, string $effectiveFrom, array $componentValues, int $actorId): int {
        $db = Database::getConnection();

        $old = self::getForUser($userId, $effectiveFrom);

        $jsonValues = json_encode($componentValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $db->prepare("
            INSERT INTO salary_structures (user_id, effective_from, component_values, created_by, created_at)
            VALUES (:uid, :eff, :vals, :creator, NOW())
        ");
        $stmt->execute([
            ':uid'     => $userId,
            ':eff'     => $effectiveFrom,
            ':vals'    => $jsonValues,
            ':creator' => $actorId,
        ]);
        $newId = (int)$db->lastInsertId();

        $new = [
            'id'               => $newId,
            'user_id'          => $userId,
            'effective_from'   => $effectiveFrom,
            'component_values' => $componentValues,
            'created_by'       => $actorId,
        ];

        AuditService::log($actorId, 'salary_structure_update', 'salary_structure', $newId, $old, $new);

        return $newId;
    }

    public static function bulkSetStructure(array $userIds, string $effectiveFrom, array $componentValues, int $actorId): int {
        $count = 0;
        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            if ($uid > 0) {
                self::setStructure($uid, $effectiveFrom, $componentValues, $actorId);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Compute estimated monthly gross and components breakdown for an employee based on structure
     */
    public static function computeEmployeeBreakdown(int $userId, ?string $asOfDate = null): array {
        $structure = self::getForUser($userId, $asOfDate);
        $overrides = $structure ? ($structure['component_values'] ?? []) : [];

        $allComponents = SalaryComponent::getAll(null, true);
        $earnings = [];
        $deductions = [];

        // 1. Separate components
        $basicAmount = 0.0;
        $totalGross = 0.0;

        // First pass: Basic component
        foreach ($allComponents as $c) {
            if ($c['type'] === 'earning') {
                $cid = (string)$c['id'];
                $val = isset($overrides[$cid]) ? (float)$overrides[$cid] : (float)$c['default_value'];

                if (strtolower($c['name']) === 'basic' || $c['id'] == 1) {
                    $basicAmount = $val;
                    $earnings[] = [
                        'id'        => $c['id'],
                        'name'      => $c['name'],
                        'type'      => 'earning',
                        'calc_type' => $c['calc_type'],
                        'amount'    => $basicAmount,
                    ];
                    $totalGross += $basicAmount;
                }
            }
        }

        // Second pass: Other earnings
        foreach ($allComponents as $c) {
            if ($c['type'] === 'earning' && strtolower($c['name']) !== 'basic' && $c['id'] != 1) {
                $cid = (string)$c['id'];
                $val = isset($overrides[$cid]) ? (float)$overrides[$cid] : (float)$c['default_value'];
                $amount = 0.0;

                if ($c['calc_type'] === 'percent_of_basic') {
                    $amount = round($basicAmount * ($val / 100), 2);
                } elseif ($c['calc_type'] === 'percent_of_gross') {
                    $amount = round($basicAmount * ($val / 100), 2);
                } else {
                    $amount = $val;
                }

                $earnings[] = [
                    'id'        => $c['id'],
                    'name'      => $c['name'],
                    'type'      => 'earning',
                    'calc_type' => $c['calc_type'],
                    'amount'    => $amount,
                ];
                $totalGross += $amount;
            }
        }

        // Third pass: Deductions
        $settings = Setting::getAllAsMap();
        $pfRate = (float)($settings['pf_rate'] ?? 12.0);
        $pfCeilingEnabled = !empty($settings['pf_wage_ceiling_enabled']);
        $pfCeiling = (float)($settings['pf_wage_ceiling'] ?? 15000.0);
        $esiRate = (float)($settings['esi_rate'] ?? 0.75);
        $esiThreshold = (float)($settings['esi_gross_threshold'] ?? 21000.0);
        $defaultPt = (float)($settings['default_professional_tax'] ?? 200.0);

        $totalDeductions = 0.0;
        foreach ($allComponents as $c) {
            if ($c['type'] === 'deduction') {
                $cid = (string)$c['id'];
                $amount = 0.0;

                if ($c['is_statutory'] === 'pf') {
                    $pfWage = ($pfCeilingEnabled && $basicAmount > $pfCeiling) ? $pfCeiling : $basicAmount;
                    $rate = isset($overrides[$cid]) ? (float)$overrides[$cid] : $pfRate;
                    $amount = round($pfWage * ($rate / 100), 2);
                } elseif ($c['is_statutory'] === 'esi') {
                    if ($totalGross <= $esiThreshold) {
                        $rate = isset($overrides[$cid]) ? (float)$overrides[$cid] : $esiRate;
                        $amount = round($totalGross * ($rate / 100), 2);
                    } else {
                        $amount = 0.0;
                    }
                } elseif ($c['is_statutory'] === 'pt') {
                    $amount = isset($overrides[$cid]) ? (float)$overrides[$cid] : $defaultPt;
                } else {
                    $val = isset($overrides[$cid]) ? (float)$overrides[$cid] : (float)$c['default_value'];
                    if ($c['calc_type'] === 'percent_of_basic') {
                        $amount = round($basicAmount * ($val / 100), 2);
                    } elseif ($c['calc_type'] === 'percent_of_gross') {
                        $amount = round($totalGross * ($val / 100), 2);
                    } else {
                        $amount = $val;
                    }
                }

                $deductions[] = [
                    'id'        => $c['id'],
                    'name'      => $c['name'],
                    'type'      => 'deduction',
                    'calc_type' => $c['calc_type'],
                    'amount'    => $amount,
                ];
                $totalDeductions += $amount;
            }
        }

        $net = max(0.0, $totalGross - $totalDeductions);

        return [
            'structure'        => $structure,
            'has_structure'    => ($structure !== null),
            'effective_from'   => $structure['effective_from'] ?? null,
            'earnings'         => $earnings,
            'deductions'       => $deductions,
            'gross'            => $totalGross,
            'monthly_gross'    => $totalGross,
            'total_deductions' => $totalDeductions,
            'net_pay'          => $net,
            'estimated_net'    => $net,
            'annual_ctc'       => round($totalGross * 12, 2),
        ];
    }
}

