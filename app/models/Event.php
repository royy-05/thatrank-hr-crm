<?php
// app/models/Event.php

namespace App\Models;

use App\Config\Database;
use App\Services\AuditService;
use PDO;

class Event {
    public static function getUpcoming(int $limit = 5): array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT e.*, 
                   DATE_FORMAT(e.event_date, '%e') as day_num,
                   DATE_FORMAT(e.event_date, '%b') as month_short,
                   DATE_FORMAT(e.event_time, '%l:%i %p') as time_str,
                   u.name as creator_name
            FROM events e
            JOIN users u ON e.created_by = u.id
            WHERE e.event_date >= CURDATE()
            ORDER BY e.event_date ASC, e.event_time ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function create(array $data, int $creatorId): int {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO events (title, event_date, event_time, link, created_by, created_at)
            VALUES (:title, :edate, :etime, :link, :uid, NOW())
        ");
        $stmt->execute([
            ':title' => trim($data['title']),
            ':edate' => $data['event_date'],
            ':etime' => !empty($data['event_time']) ? $data['event_time'] : null,
            ':link'  => !empty($data['link']) ? trim($data['link']) : null,
            ':uid'   => $creatorId,
        ]);
        $id = (int)$db->lastInsertId();

        AuditService::log($creatorId, 'create_event', 'event', $id, null, [
            'title'      => $data['title'],
            'event_date' => $data['event_date'],
        ]);

        return $id;
    }
}

