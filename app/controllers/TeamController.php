<?php
// app/controllers/TeamController.php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Models\Team;

class TeamController {
    public static function index(): void {
        AuthMiddleware::requireLogin();
        $teams = Team::all();
        jsonResponse(['success' => true, 'teams' => $teams]);
    }

    public static function get(): void {
        AuthMiddleware::requireLogin();
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid team ID.'], 400);
        }
        $team = Team::findById($id);
        if (!$team) {
            jsonResponse(['success' => false, 'error' => 'Team not found.'], 404);
        }
        $db = \App\Config\Database::getConnection();
        $stmt = $db->prepare("SELECT id, name, email, job_title, role, avatar_initials, avatar_color, status FROM users WHERE team_id = :id ORDER BY name ASC");
        $stmt->execute([':id' => $id]);
        $team['members'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        jsonResponse(['success' => true, 'team' => $team]);
    }

    public static function create(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $name = trim($_POST['name'] ?? '');
        $managerId = !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null;

        if ($name === '') {
            jsonResponse(['success' => false, 'error' => 'Please provide a team name.'], 400);
        }

        $id = Team::create($name, $managerId, (int)$admin['id']);
        jsonResponse(['success' => true, 'id' => $id, 'message' => 'Team created successfully.']);
    }

    public static function update(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $managerId = !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null;

        if ($id <= 0 || $name === '') {
            jsonResponse(['success' => false, 'error' => 'Invalid team data.'], 400);
        }

        $ok = Team::update($id, $name, $managerId, (int)$admin['id']);
        if (!$ok) {
            jsonResponse(['success' => false, 'error' => 'Failed to update team.'], 500);
        }

        jsonResponse(['success' => true, 'message' => 'Team updated successfully.']);
    }

    public static function delete(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid team ID.'], 400);
        }

        $ok = Team::delete($id, (int)$admin['id']);
        if (!$ok) {
            jsonResponse(['success' => false, 'error' => 'Failed to delete team.'], 500);
        }

        jsonResponse(['success' => true, 'message' => 'Team deleted successfully.']);
    }

    public static function assign(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $teamId = (int)($_POST['team_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($teamId <= 0 || $userId <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid team or employee selection.'], 400);
        }

        $ok = Team::assignMember($teamId, $userId, (int)$admin['id']);
        if (!$ok) {
            jsonResponse(['success' => false, 'error' => 'Failed to assign team member.'], 500);
        }

        jsonResponse(['success' => true, 'message' => 'Team member assigned successfully.']);
    }
}

