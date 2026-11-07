<?php
// app/controllers/HolidayController.php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Models\Holiday;

class HolidayController {
    public static function index(): void {
        AuthMiddleware::requireLogin();
        $year = !empty($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $holidays = Holiday::getAllForYear($year);
        jsonResponse(['success' => true, 'holidays' => $holidays]);
    }

    public static function create(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $name = trim($_POST['name'] ?? '');
        $date = trim($_POST['date'] ?? '');
        $isOptional = !empty($_POST['is_optional']);

        if ($name === '' || $date === '') {
            jsonResponse(['success' => false, 'error' => 'Please provide holiday name and date.'], 400);
        }

        $id = Holiday::create([
            'name'        => $name,
            'date'        => $date,
            'is_optional' => $isOptional,
        ], (int)$admin['id']);

        jsonResponse(['success' => true, 'id' => $id, 'message' => 'Holiday added successfully.']);
    }

    public static function update(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $date = trim($_POST['date'] ?? '');
        $isOptional = !empty($_POST['is_optional']);

        if ($id <= 0 || $name === '' || $date === '') {
            jsonResponse(['success' => false, 'error' => 'Invalid holiday data.'], 400);
        }

        $ok = Holiday::update($id, [
            'name'        => $name,
            'date'        => $date,
            'is_optional' => $isOptional,
        ], (int)$admin['id']);

        if (!$ok) {
            jsonResponse(['success' => false, 'error' => 'Failed to update holiday.'], 500);
        }

        jsonResponse(['success' => true, 'message' => 'Holiday updated successfully.']);
    }

    public static function delete(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid holiday id.'], 400);
        }

        $ok = Holiday::delete($id, (int)$admin['id']);
        if (!$ok) {
            jsonResponse(['success' => false, 'error' => 'Failed to delete holiday.'], 500);
        }

        jsonResponse(['success' => true, 'message' => 'Holiday deleted successfully.']);
    }
}

