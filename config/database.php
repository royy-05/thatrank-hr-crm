<?php
// config/database.php - PDO Connection Singleton

namespace App\Config;

use PDO;
use PDOException;

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
            $port = $_ENV['DB_PORT'] ?? '3306';
            $db   = $_ENV['DB_DATABASE'] ?? 'thatrank_hr_software';
            $user = $_ENV['DB_USERNAME'] ?? 'root';
            $pass = $_ENV['DB_PASSWORD'] ?? '';
            $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // Enforce real prepared statements
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
                // Set MySQL session timezone to Asia/Kolkata (+05:30)
                self::$instance->exec("SET time_zone = '+05:30'");
            } catch (PDOException $e) {
                if (($_ENV['APP_ENV'] ?? 'production') === 'local') {
                    die("Database connection failed: " . $e->getMessage());
                }
                error_log("Database connection error: " . $e->getMessage());
                die("A database error occurred. Please contact the administrator.");
            }
        }

        return self::$instance;
    }
}

