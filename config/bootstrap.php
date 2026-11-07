<?php
// config/bootstrap.php - ThatRank HR Bootstrap & Autoloader

// 1. Error reporting based on environment
error_reporting(E_ALL);

// 2. Load .env file
function loadEnv(string $path): void {
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            // Remove surrounding quotes if present
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            $_ENV[$key] = $val;
            putenv("{$key}={$val}");
        }
    }
}

$baseDir = dirname(__DIR__);
loadEnv($baseDir . '/.env');

// Set error display based on APP_ENV
$appEnv = $_ENV['APP_ENV'] ?? 'production';
if ($appEnv === 'local' || $appEnv === 'development') {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

// 3. Timezone: Force Asia/Kolkata everywhere
$timezone = $_ENV['TIMEZONE'] ?? 'Asia/Kolkata';
date_default_timezone_set($timezone);

// 4. Secure Cookie and Session Setup
function isHttps(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    return false;
}

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $secure = isHttps();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_name('THATRANK_HR_SESS');
    session_start();
}

// 5. CSRF Token helpers
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// 6. Automatically parse JSON request bodies into $_POST if sent as application/json
if (empty($_POST) && !empty($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) {
    $rawInput = json_decode(file_get_contents('php://input'), true);
    if (is_array($rawInput)) {
        $_POST = $rawInput;
    }
}

// 7. JSON Response helper
function jsonResponse(array $data, int $statusCode = 200): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// 7. Autoloader for App classes and Composer
spl_autoload_register(function ($class) use ($baseDir) {
    if (str_starts_with($class, 'App\\')) {
        $relative = substr($class, 4);
        $file = $baseDir . '/app/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }

        // Support case-sensitive Linux filesystems where subdirectories on disk are lowercase (controllers/, models/, middleware/, services/)
        $parts = explode('\\', $relative);
        if (count($parts) > 1) {
            $folder = strtolower($parts[0]);
            $rest = array_slice($parts, 1);
            $lowerFolderFile = $baseDir . '/app/' . $folder . '/' . implode('/', $rest) . '.php';
            if (file_exists($lowerFolderFile)) {
                require_once $lowerFolderFile;
                return;
            }

            // Also check config/ directory for App\Config\*
            if ($folder === 'config') {
                $configFile = $baseDir . '/config/' . implode('/', $rest) . '.php';
                if (file_exists($configFile)) {
                    require_once $configFile;
                    return;
                }
            }
        }
    }
});

// Require composer autoload if present (for dompdf)
if (file_exists($baseDir . '/vendor/autoload.php')) {
    require_once $baseDir . '/vendor/autoload.php';
}

require_once __DIR__ . '/database.php';
