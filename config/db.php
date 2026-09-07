<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Driver Selection & Fallback Logic
// Priority: 1. MySQL (as per project requirements) 2. Standalone SQLite (Zero-config fallback)

$db_driver = getenv('DB_DRIVER') ?: ($_SESSION['cached_db_driver'] ?? 'auto');
$host = getenv('DB_HOST') ?: '127.0.0.1'; // Use IP to prevent Windows localhost DNS resolution delay
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'hospital_queue';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: 'admin';

$pdo = null;

// Support direct DATABASE_URL / Aiven Service URI if provided
$database_url = getenv('DATABASE_URL') ?: getenv('MYSQL_URL');
if ($database_url) {
    $db_parts = parse_url($database_url);
    $host = $db_parts['host'] ?? $host;
    $port = $db_parts['port'] ?? $port;
    $user = $db_parts['user'] ?? $user;
    $password = $db_parts['pass'] ?? $password;
    $dbname = isset($db_parts['path']) ? ltrim($db_parts['path'], '/') : $dbname;
}

// Try MySQL if not explicitly forced to sqlite and not cached as unavailable
if ($db_driver !== 'sqlite') {
    try {
        // Fast socket probe (1.0s timeout for remote cloud dbs)
        $fp = @fsockopen($host, (int)$port, $errno, $errstr, 1.0);
        if ($fp) {
            fclose($fp);
            $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
            $pdo_options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ];
            // Support SSL mode for cloud services like Aiven
            if (strpos($host, 'aivencloud.com') !== false || getenv('DB_SSL') === 'true') {
                $pdo_options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
            $pdo = new PDO($dsn, $user, $password, $pdo_options);
            $GLOBALS['DB_ENGINE'] = 'mysql';
            $_SESSION['cached_db_driver'] = 'mysql';

            // Check if tables exist, if not auto-initialize schema and seed doctors
            $check_tbl = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
            if (!$check_tbl && file_exists(__DIR__ . '/../database/schema.sql')) {
                $schema_sql = file_get_contents(__DIR__ . '/../database/schema.sql');
                $pdo->exec($schema_sql);
            }
        } else {
            $_SESSION['cached_db_driver'] = 'sqlite';
        }
    } catch (PDOException $e) {
        $pdo = null;
        $_SESSION['cached_db_driver'] = 'sqlite';
    }
}

// Fallback to SQLite if MySQL is unavailable or selected
if ($pdo === null) {
    try {
        $sqlite_dir = __DIR__ . '/../database';
        if (!is_dir($sqlite_dir)) {
            mkdir($sqlite_dir, 0777, true);
        }
        $sqlite_path = $sqlite_dir . '/hospital_queue.sqlite';
        $is_new = !file_exists($sqlite_path) || filesize($sqlite_path) === 0;

        $pdo = new PDO("sqlite:" . $sqlite_path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("PRAGMA foreign_keys = ON;");

        // Register custom functions to make SQLite 100% compatible with MySQL syntax
        $pdo->sqliteCreateFunction('CURRENT_DATE', function() {
            return date('Y-m-d');
        });
        $pdo->sqliteCreateFunction('TIMESTAMPDIFF', function($unit, $start, $end) {
            if (!$start || !$end) return null;
            $diff_seconds = strtotime($end) - strtotime($start);
            if (strtoupper($unit) === 'MINUTE') {
                return round($diff_seconds / 60);
            }
            return $diff_seconds;
        });
        $pdo->sqliteCreateFunction('DATE', function($date_str) {
            if (!$date_str) return null;
            return substr($date_str, 0, 10);
        });

        // Initialize schema on first run
        if ($is_new && file_exists(__DIR__ . '/../database/schema_sqlite.sql')) {
            $schema = file_get_contents(__DIR__ . '/../database/schema_sqlite.sql');
            $pdo->exec($schema);
        }

        $GLOBALS['DB_ENGINE'] = 'sqlite';
    } catch (PDOException $e) {
        die("Database Connection failed (MySQL & SQLite): " . $e->getMessage());
    }
}
?>
