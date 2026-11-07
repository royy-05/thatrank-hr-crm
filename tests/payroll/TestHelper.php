<?php
// tests/payroll/TestHelper.php

namespace Tests\Payroll;

use App\Config\Database;
use PDO;

class TestHelper {
    public static int $passed = 0;
    public static int $failed = 0;
    public static array $errors = [];

    public static function assert(bool $condition, string $message): void {
        if ($condition) {
            self::$passed++;
            echo "  [PASS] {$message}\n";
        } else {
            self::$failed++;
            self::$errors[] = $message;
            echo "  [FAIL] {$message}\n";
        }
    }

    public static function assertEquals($expected, $actual, string $message): void {
        $condition = ($expected === $actual);
        if (!$condition && is_numeric($expected) && is_numeric($actual)) {
            $condition = (abs((float)$expected - (float)$actual) < 0.001);
        }

        if ($condition) {
            self::$passed++;
            echo "  [PASS] {$message}\n";
        } else {
            self::$failed++;
            $detail = "Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true);
            self::$errors[] = "{$message} ({$detail})";
            echo "  [FAIL] {$message} ({$detail})\n";
        }
    }

    public static function assertTrue($val, string $message): void {
        self::assert($val === true, $message);
    }

    public static function assertFalse($val, string $message): void {
        self::assert($val === false, $message);
    }

    public static function assertContains(string $needle, string $haystack, string $message): void {
        $condition = (strpos($haystack, $needle) !== false);
        self::assert($condition, $message);
    }

    public static function createTestUser(string $name = 'Test Emp', string $role = 'employee', string $joinedDate = '2025-01-01'): int {
        $db = Database::getConnection();
        $email = 'test_' . uniqid() . '@example.com';
        $stmt = $db->prepare("
            INSERT INTO users (name, email, password_hash, role, job_title, department, joined_date, status, created_at, updated_at)
            VALUES (:name, :email, 'hash', :role, 'Engineer', 'Tech', :joined, 'active', NOW(), NOW())
        ");
        $stmt->execute([
            ':name'   => $name,
            ':email'  => $email,
            ':role'   => $role,
            ':joined' => $joinedDate,
        ]);
        return (int)$db->lastInsertId();
    }
}

