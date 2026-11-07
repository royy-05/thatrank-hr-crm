<?php
// app/controllers/EventController.php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Models\Event;

class EventController {
    public static function index(): void {
        AuthMiddleware::requireLogin();
        $limit = min(20, max(1, (int)($_GET['limit'] ?? 5)));
        $events = Event::getUpcoming($limit);
        jsonResponse(['success' => true, 'events' => $events]);
    }

    public static function create(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $title = trim($_POST['title'] ?? '');
        $date = trim($_POST['event_date'] ?? '');
        $time = trim($_POST['event_time'] ?? '');
        $link = trim($_POST['link'] ?? '');

        if ($title === '' || $date === '') {
            jsonResponse(['success' => false, 'error' => 'Please provide event title and date.'], 400);
        }

        $id = Event::create([
            'title'      => $title,
            'event_date' => $date,
            'event_time' => $time ?: null,
            'link'       => $link ?: null,
        ], (int)$admin['id']);

        jsonResponse(['success' => true, 'id' => $id, 'message' => 'Event scheduled successfully.']);
    }
}

