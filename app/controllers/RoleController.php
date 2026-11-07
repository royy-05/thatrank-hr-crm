<?php
// app/controllers/RoleController.php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Models\Role;
use App\Models\RolePermission;

class RoleController {
    /**
     * Get permission catalog and all roles with user counts and granted counts.
     */
    public static function index(): void {
        AuthMiddleware::requireAdmin();

        $catalog = RolePermission::getPermissionCatalog();
        $roles = Role::getAllWithCounts();

        jsonResponse([
            'success' => true,
            'catalog' => $catalog,
            'roles'   => $roles,
        ]);
    }

    /**
     * Get details for a single role (info, permissions, and assigned users).
     */
    public static function get(): void {
        AuthMiddleware::requireAdmin();

        $slug = trim($_GET['slug'] ?? '');
        if ($slug === '') {
            jsonResponse(['success' => false, 'error' => 'Role slug parameter is required.'], 400);
        }

        $role = Role::findBySlug($slug);
        if (!$role) {
            jsonResponse(['success' => false, 'error' => 'Role not found.'], 404);
        }

        $catalog = RolePermission::getPermissionCatalog();
        $permissions = RolePermission::getPermissionsForRole($slug);
        $assignedUsers = Role::getAssignedUsers($slug);

        jsonResponse([
            'success'        => true,
            'role'           => $role,
            'catalog'        => $catalog,
            'permissions'    => $permissions,
            'assigned_users' => $assignedUsers,
        ]);
    }

    /**
     * Create a new custom role.
     */
    public static function create(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $rawInput = file_get_contents('php://input');
        $json = json_decode($rawInput, true);

        $name = trim($_POST['name'] ?? $json['name'] ?? '');
        $description = trim($_POST['description'] ?? $json['description'] ?? '');
        $slug = trim($_POST['slug'] ?? $json['slug'] ?? '');
        $perms = $_POST['permissions'] ?? $json['permissions'] ?? [];

        if (!is_array($perms)) {
            $perms = [];
        }

        $res = Role::create($name, $description, $slug, $perms, (int)$admin['id']);
        if (!$res['success']) {
            jsonResponse(['success' => false, 'error' => $res['error']], 400);
        }

        jsonResponse($res);
    }

    /**
     * Update an existing role and its permissions.
     */
    public static function update(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $rawInput = file_get_contents('php://input');
        $json = json_decode($rawInput, true);

        $slug = trim($_POST['slug'] ?? $json['slug'] ?? '');
        $name = trim($_POST['name'] ?? $json['name'] ?? '');
        $description = trim($_POST['description'] ?? $json['description'] ?? '');
        $perms = $_POST['permissions'] ?? $json['permissions'] ?? [];

        if ($slug === '') {
            jsonResponse(['success' => false, 'error' => 'Role slug is required.'], 400);
        }

        if (!is_array($perms)) {
            $perms = [];
        }

        $res = Role::update($slug, $name, $description, $perms, (int)$admin['id']);
        if (!$res['success']) {
            jsonResponse(['success' => false, 'error' => $res['error']], 400);
        }

        jsonResponse($res);
    }

    /**
     * Delete a custom role.
     */
    public static function delete(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $rawInput = file_get_contents('php://input');
        $json = json_decode($rawInput, true);

        $slug = trim($_POST['slug'] ?? $json['slug'] ?? '');
        if ($slug === '') {
            jsonResponse(['success' => false, 'error' => 'Role slug is required.'], 400);
        }

        $res = Role::delete($slug, (int)$admin['id']);
        if (!$res['success']) {
            jsonResponse(['success' => false, 'error' => $res['error']], 400);
        }

        jsonResponse($res);
    }

    /**
     * Save permissions for a given role.
     */
    public static function save(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $rawInput = file_get_contents('php://input');
        $json = json_decode($rawInput, true);

        $role = trim($_POST['role'] ?? $json['role'] ?? '');
        $perms = $_POST['permissions'] ?? $json['permissions'] ?? null;

        if ($role === '' || $role === 'admin') {
            jsonResponse(['success' => false, 'error' => 'Invalid role specified or administrator role cannot be altered.'], 400);
        }

        if (!is_array($perms)) {
            jsonResponse(['success' => false, 'error' => 'Permissions must be provided as an object or map.'], 400);
        }

        $res = RolePermission::saveRolePermissions($role, $perms, (int)$admin['id']);
        if (!$res) {
            jsonResponse(['success' => false, 'error' => 'Failed to save role permissions.'], 500);
        }

        jsonResponse([
            'success'     => true,
            'message'     => ucfirst($role) . ' permissions updated successfully.',
            'permissions' => RolePermission::getPermissionsForRole($role),
        ]);
    }

    /**
     * Reset role permissions to system defaults.
     */
    public static function reset(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $rawInput = file_get_contents('php://input');
        $json = json_decode($rawInput, true);

        $role = trim($_POST['role'] ?? $json['role'] ?? '');

        if ($role === '' || $role === 'admin') {
            jsonResponse(['success' => false, 'error' => 'Invalid role specified.'], 400);
        }

        $res = RolePermission::resetRoleDefaults($role, (int)$admin['id']);
        if (!$res) {
            jsonResponse(['success' => false, 'error' => 'Failed to reset role permissions.'], 500);
        }

        jsonResponse([
            'success'     => true,
            'message'     => ucfirst($role) . ' permissions reset to default presets.',
            'permissions' => RolePermission::getPermissionsForRole($role),
        ]);
    }

    /**
     * Get current authenticated user permissions.
     */
    public static function myPermissions(): void {
        $user = AuthMiddleware::requireLogin();
        $role = $user['role'] ?? 'employee';

        jsonResponse([
            'success'     => true,
            'role'        => $role,
            'permissions' => RolePermission::getPermissionsForRole($role),
        ]);
    }
}
