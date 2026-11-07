<?php
// app/controllers/SettingsController.php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Models\Setting;

class SettingsController {
    public static function publicSettings(): void {
        $settings = Setting::getAllAsMap();
        jsonResponse([
            'success'                   => true,
            'delete_selfies_after_days' => (int)($settings['delete_selfies_after_days'] ?? 90),
            'heartbeat_interval_seconds'=> (int)($settings['heartbeat_interval_seconds'] ?? 120),
            'idle_threshold_seconds'    => (int)($settings['idle_threshold_seconds'] ?? 300),
            'work_week'                 => $settings['work_week'] ?? 'Monday to Friday',
            'daily_target_hours'        => $settings['daily_target_hours'] ?? '9',
            'shift_start_time'          => $settings['shift_start_time'] ?? '10:00:00',
            'shift_end_time'            => $settings['shift_end_time'] ?? '19:00:00',
            'late_grace_minutes'        => (int)($settings['late_grace_minutes'] ?? 15),
            'auto_clock_out_time'       => $settings['auto_clock_out_time'] ?? '23:00:00',
            'late_fine_enabled'         => $settings['late_fine_enabled'] ?? '0',
            'carry_forward_leave'       => $settings['carry_forward_leave'] ?? '1',
            'manager_approval_required' => $settings['manager_approval_required'] ?? '1',
        ]);
    }

    public static function index(): void {
        AuthMiddleware::requireAdmin();
        $settings = Setting::getAllAsMap();

        jsonResponse([
            'success'  => true,
            'settings' => $settings,
        ]);
    }

    public static function update(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $data = $_POST;
        unset($data['csrf_token'], $data['action']);

        if (empty($data)) {
            jsonResponse(['success' => false, 'error' => 'No settings provided.'], 400);
        }

        Setting::updateBatch($data, (int)$admin['id']);

        jsonResponse([
            'success' => true,
            'message' => 'Settings updated successfully.'
        ]);
    }
}

