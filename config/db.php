<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Kolkata');

// Default connection: Local XAMPP MySQL
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'hospital_queue';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'admin';

// Support direct DATABASE_URL / MYSQL_URL (Render, TiDB, Aiven, etc.)
$database_url = getenv('DATABASE_URL') ?: getenv('MYSQL_URL');
if ($database_url) {
    $db_parts = parse_url($database_url);
    $host = $db_parts['host'] ?? $host;
    $port = $db_parts['port'] ?? $port;
    $user = isset($db_parts['user']) ? urldecode($db_parts['user']) : $user;
    $password = isset($db_parts['pass']) ? urldecode($db_parts['pass']) : $password;
    if (isset($db_parts['path'])) {
        $path_db = ltrim($db_parts['path'], '/');
        // 'sys' is MySQL internal read-only schema on TiDB; use 'test' for app tables
        if (!empty($path_db) && $path_db !== 'sys') {
            $dbname = $path_db;
        } else {
            $dbname = 'test';
        }
    }
}

$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
$pdo_options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 10,
];

// SSL configuration for Cloud MySQL providers (TiDB, Aiven, etc.)
$is_cloud = (strpos($host, 'tidbcloud.com') !== false || strpos($host, 'aivencloud.com') !== false || getenv('DB_SSL') === 'true');
if ($is_cloud) {
    $pdo_options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    // On Linux / Docker containers, verify standard CA bundle path
    if (file_exists('/etc/ssl/certs/ca-certificates.crt')) {
        $pdo_options[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
    }
}

try {
    $pdo = new PDO($dsn, $user, $password, $pdo_options);
    $GLOBALS['DB_ENGINE'] = 'mysql';

    // Auto-create tables if this is a fresh database
    $check_tbl = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
    if (!$check_tbl && file_exists(__DIR__ . '/../database/schema.sql')) {
        $schema_sql = file_get_contents(__DIR__ . '/../database/schema.sql');
        try {
            $pdo->exec($schema_sql);
        } catch (Exception $e) {
            // Ignore non-fatal warnings
        }
    } else {
        // Self-heal: ensure appointment booking columns exist in existing MySQL databases
        try {
            $has_col = $pdo->query("SHOW COLUMNS FROM queue_tokens LIKE 'booking_type'")->fetch();
            if (!$has_col) {
                @$pdo->exec("ALTER TABLE doctors ADD COLUMN working_start_time TIME NOT NULL DEFAULT '09:00:00'");
                @$pdo->exec("ALTER TABLE doctors ADD COLUMN working_end_time TIME NOT NULL DEFAULT '13:00:00'");
                @$pdo->exec("ALTER TABLE doctors ADD COLUMN evening_start_time TIME NULL DEFAULT '17:00:00'");
                @$pdo->exec("ALTER TABLE doctors ADD COLUMN evening_end_time TIME NULL DEFAULT '20:00:00'");
                @$pdo->exec("ALTER TABLE doctors ADD COLUMN booking_slot_percentage INT NOT NULL DEFAULT 70");
                @$pdo->exec("ALTER TABLE queue_tokens ADD COLUMN booking_ref VARCHAR(20) NULL");
                @$pdo->exec("ALTER TABLE queue_tokens ADD COLUMN booking_type VARCHAR(20) NOT NULL DEFAULT 'Walk-in'");
                @$pdo->exec("ALTER TABLE queue_tokens ADD COLUMN scheduled_time DATETIME NULL");
                @$pdo->exec("ALTER TABLE queue_tokens MODIFY COLUMN token_number VARCHAR(20) NULL");
            }
        } catch (Exception $e) {}
    }
} catch (PDOException $e) {
    die("Database Connection failed: " . $e->getMessage());
}
?>
