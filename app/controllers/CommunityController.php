<?php
// app/controllers/CommunityController.php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Models\Community;
use App\Models\User;

class CommunityController {
    public static function feed(): void {
        $user = AuthMiddleware::requireLogin();
        $feed = Community::getFeed((int)$user['id'], 30);
        $celebrations = User::getCelebrations();

        jsonResponse([
            'success'      => true,
            'posts'        => $feed,
            'celebrations' => $celebrations,
        ]);
    }

    public static function create(): void {
        AuthMiddleware::requireCsrf();
        $user = AuthMiddleware::requireLogin();

        $content = trim($_POST['content'] ?? '');
        $type = trim($_POST['type'] ?? 'post');
        $isPinned = !empty($_POST['is_pinned']);

        // Only admins can pin or post official announcements
        if ($isPinned || $type === 'announcement') {
            if ($user['role'] !== 'admin') {
                $isPinned = false;
                $type = 'post';
            }
        }

        if ($content === '' && empty($_POST['image_base64']) && empty($_POST['poll_question'])) {
            jsonResponse(['success' => false, 'error' => 'Post cannot be empty.'], 400);
        }

        $pollOptions = [];
        if (!empty($_POST['poll_options']) && is_array($_POST['poll_options'])) {
            $pollOptions = $_POST['poll_options'];
        }

        $recipientId = !empty($_POST['recipient_id']) ? (int)$_POST['recipient_id'] : null;

        $id = Community::createPost((int)$user['id'], [
            'type'          => $type,
            'recipient_id'  => $recipientId,
            'title'         => !empty($_POST['title']) ? trim($_POST['title']) : null,
            'content'       => $content,
            'image_base64'  => $_POST['image_base64'] ?? null,
            'is_pinned'     => $isPinned ? 1 : 0,
            'poll_question' => $_POST['poll_question'] ?? null,
            'poll_options'  => $pollOptions,
        ]);

        jsonResponse([
            'success' => true,
            'id'      => $id,
            'message' => 'Post published successfully.'
        ]);
    }

    public static function like(): void {
        AuthMiddleware::requireCsrf();
        $user = AuthMiddleware::requireLogin();
        $postId = (int)($_POST['post_id'] ?? 0);

        if (!$postId) {
            jsonResponse(['success' => false, 'error' => 'Invalid post ID.'], 400);
        }

        $res = Community::toggleLike($postId, (int)$user['id']);
        jsonResponse([
            'success'     => true,
            'liked'       => $res['liked'],
            'likes_count' => $res['likes_count'],
        ]);
    }

    public static function vote(): void {
        AuthMiddleware::requireCsrf();
        $user = AuthMiddleware::requireLogin();

        $pollId = (int)($_POST['poll_id'] ?? 0);
        $optionId = (int)($_POST['option_id'] ?? 0);

        if (!$pollId || !$optionId) {
            jsonResponse(['success' => false, 'error' => 'Invalid poll parameters.'], 400);
        }

        $res = Community::votePoll($pollId, $optionId, (int)$user['id']);
        if (!$res) {
            jsonResponse(['success' => false, 'error' => 'You have already voted or poll is closed.'], 400);
        }

        jsonResponse(['success' => true, 'message' => 'Vote recorded.']);
    }

    public static function activePoll(): void {
        $user = AuthMiddleware::requireLogin();
        $poll = Community::getActivePoll((int)$user['id']);
        jsonResponse([
            'success' => true,
            'poll'    => $poll,
        ]);
    }

    public static function celebrations(): void {
        AuthMiddleware::requireLogin();
        $days = min(60, max(7, (int)($_GET['days'] ?? 30)));
        $data = \App\Models\User::getCelebrations($days);
        jsonResponse([
            'success'       => true,
            'birthdays'     => $data['birthdays'],
            'anniversaries' => $data['anniversaries'],
        ]);
    }
}

