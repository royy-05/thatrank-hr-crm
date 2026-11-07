<?php
// app/controllers/AuditController.php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Config\Database;
use PDO;

class AuditController {
    public static function index(): void {
        AuthMiddleware::requireAdmin();
        $db = Database::getConnection();

        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $stmt = $db->prepare("
            SELECT a.*, u.name as user_name, u.email as user_email
            FROM audit_log a
            LEFT JOIN users u ON a.user_id = u.id
            ORDER BY a.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();

        $count = (int)$db->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();

        jsonResponse([
            'success'     => true,
            'logs'        => $logs,
            'total_count' => $count,
        ]);
    }
}

