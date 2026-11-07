<?php
// app/models/Role.php

namespace App\Models;

use App\Config\Database;
use App\Services\AuditService;
use PDO;

class Role {
    /**
     * Get all roles with assigned user count and granted permissions count.
     */
    public static function getAllWithCounts(): array {
        $db = Database::getConnection();

        // Fetch all roles
        $stmt = $db->query("
            SELECT r.id, r.slug, r.name, r.description, r.is_system, r.created_at, r.updated_at,
                   COUNT(u.id) AS user_count
            FROM roles r
            LEFT JOIN users u ON u.role = r.slug AND u.status != 'offboarded'
            GROUP BY r.id, r.slug, r.name, r.description, r.is_system, r.created_at, r.updated_at
            ORDER BY r.is_system DESC, r.name ASC
        ");
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $catalog = RolePermission::getPermissionCatalog();
        $totalCatalogPerms = 0;
        foreach ($catalog as $mod) {
            $totalCatalogPerms += count($mod['permissions']);
        }

        foreach ($roles as &$role) {
            $role['user_count'] = (int)$role['user_count'];
            $role['is_system'] = (bool)$role['is_system'];
            $role['total_permissions'] = $totalCatalogPerms;

            if ($role['slug'] === 'admin') {
                $role['granted_permissions'] = $totalCatalogPerms;
            } else {
                $pStmt = $db->prepare("SELECT COUNT(*) FROM role_permissions WHERE role = :r AND granted = 1");
                $pStmt->execute([':r' => $role['slug']]);
                $role['granted_permissions'] = (int)$pStmt->fetchColumn();
            }
        }
        unset($role);

        return $roles;
    }

    /**
     * Find a role by its unique slug.
     */
    public static function findBySlug(string $slug): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM roles WHERE slug = :s LIMIT 1");
        $stmt->execute([':s' => $slug]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            return null;
        }

        $role['is_system'] = (bool)$role['is_system'];
        return $role;
    }

    /**
     * Get users assigned to a specific role.
     */
    public static function getAssignedUsers(string $slug): array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT id, name, email, job_title, department, status, avatar_initials, avatar_color
            FROM users
            WHERE role = :s AND status != 'offboarded'
            ORDER BY name ASC
        ");
        $stmt->execute([':s' => $slug]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create a new custom role with permissions.
     */
    public static function create(string $name, ?string $description, ?string $slug, array $permissions, int $actorId): array {
        $db = Database::getConnection();

        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'error' => 'Role name is required.'];
        }

        // Generate or sanitize slug
        $slug = trim($slug ?? '');
        if ($slug === '') {
            $slug = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $name));
            $slug = trim($slug, '_');
        } else {
            $slug = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $slug));
        }

        if ($slug === '') {
            $slug = 'custom_role_' . time();
        }

        // Check if slug already exists
        $check = self::findBySlug($slug);
        if ($check) {
            return ['success' => false, 'error' => "A role with key '{$slug}' already exists."];
        }

        $stmt = $db->prepare("
            INSERT INTO roles (slug, name, description, is_system, created_at, updated_at)
            VALUES (:s, :n, :d, 0, NOW(), NOW())
        ");
        $stmt->execute([
            ':s' => $slug,
            ':n' => $name,
            ':d' => $description ?: null,
        ]);

        $roleId = (int)$db->lastInsertId();

        // Save permissions
        RolePermission::saveRolePermissions($slug, $permissions, $actorId, 'role_create');

        AuditService::log($actorId, 'role_create', 'role', $roleId, null, [
            'slug'        => $slug,
            'name'        => $name,
            'description' => $description,
            'permissions' => $permissions,
        ]);

        return [
            'success' => true,
            'message' => "Role '{$name}' created successfully.",
            'slug'    => $slug,
            'id'      => $roleId,
        ];
    }

    /**
     * Update an existing role.
     */
    public static function update(string $slug, string $name, ?string $description, array $permissions, int $actorId): array {
        $db = Database::getConnection();

        $existing = self::findBySlug($slug);
        if (!$existing) {
            return ['success' => false, 'error' => 'Role not found.'];
        }

        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'error' => 'Role name is required.'];
        }

        $oldValues = [
            'name'        => $existing['name'],
            'description' => $existing['description'],
            'permissions' => RolePermission::getPermissionsForRole($slug),
        ];

        // Update role details in roles table
        $stmt = $db->prepare("UPDATE roles SET name = :n, description = :d, updated_at = NOW() WHERE slug = :s");
        $stmt->execute([
            ':n' => $name,
            ':d' => $description ?: null,
            ':s' => $slug,
        ]);

        // Save permissions (admin retains all permissions)
        if ($slug !== 'admin') {
            RolePermission::saveRolePermissions($slug, $permissions, $actorId, 'role_update');
        }

        AuditService::log($actorId, 'role_update', 'role', (int)$existing['id'], $oldValues, [
            'slug'        => $slug,
            'name'        => $name,
            'description' => $description,
            'permissions' => RolePermission::getPermissionsForRole($slug),
        ]);

        return [
            'success' => true,
            'message' => "Role '{$name}' updated successfully.",
            'slug'    => $slug,
        ];
    }

    /**
     * Delete a custom role.
     */
    public static function delete(string $slug, int $actorId): array {
        $db = Database::getConnection();

        $existing = self::findBySlug($slug);
        if (!$existing) {
            return ['success' => false, 'error' => 'Role not found.'];
        }

        if (!empty($existing['is_system'])) {
            return ['success' => false, 'error' => 'System roles (Administrator, Manager, Employee) cannot be deleted.'];
        }

        // Check if any users are currently assigned to this role
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = :s AND status != 'offboarded'");
        $stmt->execute([':s' => $slug]);
        $assignedCount = (int)$stmt->fetchColumn();

        if ($assignedCount > 0) {
            return [
                'success' => false,
                'error'   => "Cannot delete role because {$assignedCount} active user(s) are currently assigned to it. Please reassign them first.",
            ];
        }

        // Delete permissions and role record
        $db->prepare("DELETE FROM role_permissions WHERE role = :s")->execute([':s' => $slug]);
        $db->prepare("DELETE FROM roles WHERE slug = :s")->execute([':s' => $slug]);

        AuditService::log($actorId, 'role_delete', 'role', (int)$existing['id'], $existing, null);

        return [
            'success' => true,
            'message' => "Role '{$existing['name']}' deleted successfully.",
        ];
    }
}

