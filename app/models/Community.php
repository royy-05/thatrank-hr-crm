<?php
// app/models/Community.php

namespace App\Models;

use App\Config\Database;
use PDO;

class Community {
    public static function getFeed(int $viewerId, int $limit = 20): array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT cp.*, u.name as author_name, u.department, u.avatar_initials, u.avatar_color, u.job_title,
                   r.name as recipient_name, r.avatar_initials as recipient_initials, r.avatar_color as recipient_color,
                   (SELECT COUNT(*) FROM community_post_reactions WHERE post_id = cp.id AND user_id = :uid) as user_has_liked
            FROM community_posts cp
            JOIN users u ON cp.user_id = u.id
            LEFT JOIN users r ON cp.recipient_id = r.id
            ORDER BY cp.is_pinned DESC, cp.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':uid', $viewerId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $posts = $stmt->fetchAll();

        // Attach poll data if type is poll
        foreach ($posts as &$post) {
            if ($post['type'] === 'poll') {
                $post['poll'] = self::getPollForPost((int)$post['id'], $viewerId);
            }
        }

        return $posts;
    }

    public static function getPollForPost(int $postId, int $viewerId): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM community_polls WHERE post_id = :pid LIMIT 1");
        $stmt->execute([':pid' => $postId]);
        $poll = $stmt->fetch();
        if (!$poll) return null;

        $optStmt = $db->prepare("
            SELECT po.*,
                   (SELECT COUNT(*) FROM community_poll_votes WHERE poll_id = :polid1 AND option_id = po.id AND user_id = :uid) as user_voted
            FROM community_poll_options po
            WHERE po.poll_id = :polid2
            ORDER BY po.id ASC
        ");
        $optStmt->execute([
            ':polid1' => $poll['id'],
            ':polid2' => $poll['id'],
            ':uid'    => $viewerId
        ]);
        $poll['options'] = $optStmt->fetchAll();

        // Calculate total votes and percentage
        $total = 0;
        foreach ($poll['options'] as $o) {
            $total += (int)$o['votes_count'];
        }
        $poll['total_votes'] = $total;
        foreach ($poll['options'] as &$o) {
            $o['percentage'] = $total > 0 ? (int)round(((int)$o['votes_count'] / $total) * 100) : 0;
        }

        return $poll;
    }

    public static function getActivePoll(int $viewerId): ?array {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT cp.id as post_id, cp.title, cp.content, p.id as poll_id, p.question
            FROM community_polls p
            JOIN community_posts cp ON p.post_id = cp.id
            ORDER BY cp.created_at DESC
            LIMIT 1
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $poll = self::getPollForPost((int)$row['post_id'], $viewerId);
        if ($poll) {
            $poll['title'] = $row['title'] ?: $row['question'];
        }
        return $poll;
    }

    public static function createPost(int $userId, array $data): int {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO community_posts (user_id, recipient_id, type, title, content, image_path, is_pinned, created_at)
            VALUES (:uid, :recip, :type, :title, :content, :img, :pinned, NOW())
        ");

        $imagePath = null;
        if (!empty($data['image_base64'])) {
            $imagePath = self::savePostImage($userId, $data['image_base64']);
        }

        $recipientId = !empty($data['recipient_id']) ? (int)$data['recipient_id'] : null;

        $stmt->execute([
            ':uid'     => $userId,
            ':recip'   => $recipientId,
            ':type'    => $data['type'] ?? 'post',
            ':title'   => $data['title'] ?? null,
            ':content' => trim($data['content']),
            ':img'     => $imagePath,
            ':pinned'  => !empty($data['is_pinned']) ? 1 : 0,
        ]);
        $postId = (int)$db->lastInsertId();

        // If it's a poll, create poll questions and options
        if (($data['type'] ?? '') === 'poll' && !empty($data['poll_question']) && !empty($data['poll_options'])) {
            $pStmt = $db->prepare("INSERT INTO community_polls (post_id, question, created_at) VALUES (:pid, :q, NOW())");
            $pStmt->execute([':pid' => $postId, ':q' => trim($data['poll_question'])]);
            $pollId = (int)$db->lastInsertId();

            $optStmt = $db->prepare("INSERT INTO community_poll_options (poll_id, option_text, votes_count) VALUES (:polid, :opt, 0)");
            foreach ($data['poll_options'] as $opt) {
                if (trim($opt) !== '') {
                    $optStmt->execute([':polid' => $pollId, ':opt' => trim($opt)]);
                }
            }
        }

        return $postId;
    }

    public static function toggleLike(int $postId, int $userId): array {
        $db = Database::getConnection();
        $check = $db->prepare("SELECT id FROM community_post_reactions WHERE post_id = :pid AND user_id = :uid LIMIT 1");
        $check->execute([':pid' => $postId, ':uid' => $userId]);
        $exists = $check->fetch();

        if ($exists) {
            $del = $db->prepare("DELETE FROM community_post_reactions WHERE id = :id");
            $del->execute([':id' => $exists['id']]);
            $liked = false;
        } else {
            $ins = $db->prepare("INSERT INTO community_post_reactions (post_id, user_id, reaction, created_at) VALUES (:pid, :uid, 'heart', NOW())");
            $ins->execute([':pid' => $postId, ':uid' => $userId]);
            $liked = true;
        }

        // Update count
        $cnt = $db->prepare("UPDATE community_posts SET likes_count = (SELECT COUNT(*) FROM community_post_reactions WHERE post_id = :pid1) WHERE id = :pid2");
        $cnt->execute([':pid1' => $postId, ':pid2' => $postId]);

        $getLikes = $db->prepare("SELECT likes_count FROM community_posts WHERE id = :pid");
        $getLikes->execute([':pid' => $postId]);
        $likesCount = (int)$getLikes->fetchColumn();

        return ['liked' => $liked, 'likes_count' => $likesCount];
    }

    public static function votePoll(int $pollId, int $optionId, int $userId): bool {
        $db = Database::getConnection();
        // Check if user already voted in this poll
        $check = $db->prepare("SELECT id FROM community_poll_votes WHERE poll_id = :polid AND user_id = :uid LIMIT 1");
        $check->execute([':polid' => $pollId, ':uid' => $userId]);
        if ($check->fetch()) {
            return false; // Already voted
        }

        $ins = $db->prepare("INSERT INTO community_poll_votes (poll_id, option_id, user_id, created_at) VALUES (:polid, :optid, :uid, NOW())");
        $ins->execute([':polid' => $pollId, ':optid' => $optionId, ':uid' => $userId]);

        // Update vote count on option
        $up = $db->prepare("UPDATE community_poll_options SET votes_count = (SELECT COUNT(*) FROM community_poll_votes WHERE option_id = :optid1) WHERE id = :optid2");
        $up->execute([':optid1' => $optionId, ':optid2' => $optionId]);

        return true;
    }

    private static function savePostImage(int $userId, string $base64Data): ?string {
        if (!preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
            return null;
        }
        $data = substr($base64Data, strpos($base64Data, ',') + 1);
        $decoded = base64_decode($data);
        if (!$decoded) {
            return null;
        }

        $ext = strtolower($type[1]);
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
            $ext = 'jpg';
        }

        $fileName = 'post_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dir = dirname(__DIR__, 2) . '/public/assets/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/' . $fileName, $decoded);
        return 'assets/uploads/' . $fileName;
    }
}
