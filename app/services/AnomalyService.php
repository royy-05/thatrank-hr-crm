<?php
// app/services/AnomalyService.php

namespace App\Services;

use App\Config\Database;
use PDO;

class AnomalyService {
    /**
     * Compute distance in kilometers using the Haversine formula.
     */
    public static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    /**
     * Detect anomalies for a given attendance record and its punches.
     */
    public static function detectRecordAnomalies(array $record, ?array $punches = null): array {
        $db = Database::getConnection();
        $anomalies = [];
        $userId = (int)$record['user_id'];
        $date = $record['date'];

        // 1. Auto closed check
        if (!empty($record['auto_clocked_out'])) {
            $anomalies[] = [
                'type'     => 'auto_closed',
                'severity' => 'warn',
                'label'    => 'Auto closed',
                'detail'   => 'Shift was automatically closed at midnight cutoff'
            ];
        }

        // 2. Break > 90 minutes check (5400 seconds)
        if (!empty($record['break_seconds']) && (int)$record['break_seconds'] > 5400) {
            $mins = round((int)$record['break_seconds'] / 60);
            $anomalies[] = [
                'type'     => 'long_break',
                'severity' => 'warn',
                'label'    => 'Break >90m',
                'detail'   => "Total break duration was {$mins} minutes"
            ];
        }

        // 3. 3 or more late marks in the same week
        $startOfWeek = date('Y-m-d', strtotime('monday this week', strtotime($date)));
        $endOfWeek = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
        $lateStmt = $db->prepare("
            SELECT COUNT(*) FROM attendance_records
            WHERE user_id = :uid AND is_late = 1 AND date BETWEEN :start AND :end
        ");
        $lateStmt->execute([':uid' => $userId, ':start' => $startOfWeek, ':end' => $endOfWeek]);
        $weekLateCount = (int)$lateStmt->fetchColumn();
        if ($weekLateCount >= 3) {
            $anomalies[] = [
                'type'     => 'frequent_late',
                'severity' => 'crit',
                'label'    => '3+ late this week',
                'detail'   => "Accumulated {$weekLateCount} late arrivals this week"
            ];
        }

        // Fetch punches if not supplied
        if ($punches === null && !empty($record['id'])) {
            $pStmt = $db->prepare("SELECT * FROM punch_logs WHERE attendance_record_id = :id ORDER BY punched_at ASC");
            $pStmt->execute([':id' => $record['id']]);
            $punches = $pStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!empty($punches)) {
            $hasNoSelfie = false;
            $hasNoLocation = false;
            $hasFarLocation = false;
            $sharedDevice = false;

            // Fetch user flags and company settings
            $user = \App\Models\User::findById($userId);
            $companySelfie = (int)(\App\Models\Setting::get('selfie_on_punch', '1')) === 1;
            $companyLocation = (int)(\App\Models\Setting::get('location_on_punch', '1')) === 1;

            $userCamReq = $companySelfie && (!isset($user['require_camera']) || (int)$user['require_camera'] === 1);
            $userLocReq = $companyLocation && (!isset($user['require_location']) || (int)$user['require_location'] === 1);

            $distThresholdKm = (float)(\App\Models\Setting::get('anomaly_distance_threshold_km', '100'));
            if ($distThresholdKm <= 0) {
                $distThresholdKm = 100.0;
            }

            foreach ($punches as $p) {
                // Check selfie only if required
                if ($userCamReq && empty($p['selfie_path'])) {
                    $hasNoSelfie = true;
                }

                // Check location only if required
                if ($userLocReq && (empty($p['latitude']) || empty($p['longitude']))) {
                    $hasNoLocation = true;
                }

                // Check shared device on same day
                if (!empty($p['device_info']) && !empty($p['ip_address'])) {
                    $devStmt = $db->prepare("
                        SELECT COUNT(DISTINCT user_id) 
                        FROM punch_logs 
                        WHERE DATE(punched_at) = :d 
                          AND user_id != :uid 
                          AND (device_info = :dev OR ip_address = :ip)
                    ");
                    $devStmt->execute([
                        ':d'   => $date,
                        ':uid' => $userId,
                        ':dev' => $p['device_info'],
                        ':ip'  => $p['ip_address'],
                    ]);
                    if ((int)$devStmt->fetchColumn() > 0) {
                        $sharedDevice = true;
                    }
                }
            }

            // Distance check:
            // Compute usual location only from past punches with valid lat/lng, excluding current record, using median of last 10
            // Skip check entirely until user has at least 5 prior punches with location; never flag if require_location = 0 or punch has no location
            if ($userLocReq) {
                $currentRecId = (int)($record['id'] ?? 0);
                $pastStmt = $db->prepare("
                    SELECT id, latitude, longitude
                    FROM punch_logs
                    WHERE user_id = :uid
                      AND attendance_record_id != :rec_id
                      AND latitude IS NOT NULL
                      AND longitude IS NOT NULL
                      AND NOT (latitude = 0 AND longitude = 0)
                    ORDER BY punched_at DESC
                    LIMIT 20
                ");
                $pastStmt->execute([':uid' => $userId, ':rec_id' => $currentRecId]);
                $pastRows = $pastStmt->fetchAll(PDO::FETCH_ASSOC);

                $validPast = [];
                foreach ($pastRows as $pr) {
                    $pLat = (float)$pr['latitude'];
                    $pLon = (float)$pr['longitude'];
                    if ($pLat == 0.0 && $pLon == 0.0) continue;
                    if (isset($pr['accuracy']) && (float)$pr['accuracy'] > 1000) continue;
                    $validPast[] = ['lat' => $pLat, 'lon' => $pLon];
                    if (count($validPast) === 10) break;
                }

                if (count($validPast) >= 5) {
                    $lats = array_column($validPast, 'lat');
                    $lons = array_column($validPast, 'lon');
                    sort($lats);
                    sort($lons);
                    $n = count($lats);
                    $mid = (int)floor($n / 2);
                    $medianLat = ($n % 2 === 1) ? $lats[$mid] : (($lats[$mid - 1] + $lats[$mid]) / 2.0);
                    $medianLon = ($n % 2 === 1) ? $lons[$mid] : (($lons[$mid - 1] + $lons[$mid]) / 2.0);

                    foreach ($punches as $p) {
                        if (empty($p['latitude']) || empty($p['longitude'])) continue;
                        $curLat = (float)$p['latitude'];
                        $curLon = (float)$p['longitude'];
                        if ($curLat == 0.0 && $curLon == 0.0) continue;
                        if (isset($p['accuracy']) && (float)$p['accuracy'] > 1000) continue;

                        $dist = self::distanceKm($medianLat, $medianLon, $curLat, $curLon);
                        if ($dist > $distThresholdKm) {
                            $hasFarLocation = true;
                            break;
                        }
                    }
                }
            }

            if ($hasNoSelfie) {
                $anomalies[] = [
                    'type'     => 'no_selfie',
                    'severity' => 'crit',
                    'label'    => 'No selfie',
                    'detail'   => 'At least one punch was recorded without a camera verification selfie'
                ];
            }

            if ($hasNoLocation) {
                $anomalies[] = [
                    'type'     => 'no_location',
                    'severity' => 'warn',
                    'label'    => 'No location',
                    'detail'   => 'At least one punch lacked GPS geolocation coordinates'
                ];
            }

            if ($hasFarLocation) {
                $anomalies[] = [
                    'type'     => 'location_far',
                    'severity' => 'warn',
                    'label'    => '>' . round($distThresholdKm) . ' km away',
                    'detail'   => 'Punch recorded over ' . round($distThresholdKm) . ' km from usual location'
                ];
            }

            if ($sharedDevice) {
                $anomalies[] = [
                    'type'     => 'shared_device',
                    'severity' => 'crit',
                    'label'    => 'Shared device',
                    'detail'   => 'Same device or IP was used by another teammate on this date'
                ];
            }
        }

        return $anomalies;
    }
}

