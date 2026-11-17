<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Kolkata');

// Default connection: Local MySQL
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'hospital_queue';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'admin';

// Support direct DATABASE_URL / MYSQL_URL if provided
$database_url = getenv('DATABASE_URL') ?: getenv('MYSQL_URL');
$url_password = null;
if ($database_url) {
    $db_parts = parse_url($database_url);
    $host = $db_parts['host'] ?? $host;
    $port = $db_parts['port'] ?? $port;
    $user = isset($db_parts['user']) ? urldecode($db_parts['user']) : $user;
    $url_password = isset($db_parts['pass']) ? urldecode($db_parts['pass']) : null;
    if (isset($db_parts['path'])) {
        $path_db = ltrim($db_parts['path'], '/');
        if (!empty($path_db)) {
            $dbname = $path_db;
        }
    }
}

// Auto-default TiDB Cloud port to 4000 if not specified
if (stripos($host, 'tidbcloud.com') !== false && empty($db_parts['port']) && getenv('DB_PORT') === false) {
    $port = '4000';
}

$passwords_to_try = [];
if (getenv('DB_PASS') !== false) {
    $passwords_to_try[] = getenv('DB_PASS');
} elseif ($url_password !== null) {
    $passwords_to_try[] = $url_password;
} else {
    $passwords_to_try = ['admin', ''];
}

$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
$pdo_options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 10,
    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
];

// Determine if SSL is required (TiDB Cloud, Aiven, remote cloud DB, or DB_SSL=true)
$is_remote = ($host !== '127.0.0.1' && $host !== 'localhost' && $host !== '::1');
$query_has_ssl = isset($db_parts['query']) && (stripos($db_parts['query'], 'ssl') !== false);
$env_ssl = getenv('DB_SSL');
$enable_ssl = ($env_ssl === 'true') || ($env_ssl !== 'false' && ($is_remote || $query_has_ssl));

if ($enable_ssl) {
    $ca_cert_paths = [
        '/etc/ssl/certs/ca-certificates.crt',       // Debian/Ubuntu/Render Docker
        '/etc/pki/tls/certs/ca-bundle.crt',         // RHEL/CentOS
        '/etc/ssl/ca-bundle.pem',                   // SUSE
        '/etc/ssl/cert.pem',                        // Alpine/macOS
        'C:\xampp\apache\bin\curl-ca-bundle.crt',   // Windows
    ];
    $found_ca = null;
    foreach ($ca_cert_paths as $ca_file) {
        if (file_exists($ca_file)) {
            $found_ca = $ca_file;
            break;
        }
    }

    $pdo_options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    if ($found_ca) {
        $pdo_options[PDO::MYSQL_ATTR_SSL_CA] = $found_ca;
    } elseif (is_dir('/etc/ssl/certs')) {
        $pdo_options[PDO::MYSQL_ATTR_SSL_CAPATH] = '/etc/ssl/certs';
    }
}

$pdo = null;
$last_error = null;
foreach ($passwords_to_try as $pwd) {
    try {
        $pdo = new PDO($dsn, $user, $pwd, $pdo_options);
        $password = $pwd;
        break;
    } catch (PDOException $e) {
        $last_error = $e;
        if ($e->getCode() != 1045 && strpos($e->getMessage(), 'Access denied') === false) {
            break;
        }
    }
}

// Fallback: If SSL attempt failed on non-SSL remote host, retry plain
if (!$pdo && $enable_ssl && $env_ssl !== 'true') {
    $plain_options = $pdo_options;
    unset($plain_options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
    unset($plain_options[PDO::MYSQL_ATTR_SSL_CA]);
    unset($plain_options[PDO::MYSQL_ATTR_SSL_CAPATH]);
    foreach ($passwords_to_try as $pwd) {
        try {
            $pdo = new PDO($dsn, $user, $pwd, $plain_options);
            $password = $pwd;
            break;
        } catch (PDOException $e) {
            $last_error = $e;
        }
    }
}

if (!$pdo) {
    die("Database Connection failed: " . ($last_error ? $last_error->getMessage() : 'Unknown error'));
}

$GLOBALS['DB_ENGINE'] = 'mysql';

    // Synchronize MySQL connection session to IST (+05:30)
    @$pdo->exec("SET time_zone = '+05:30'");

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
        // Self-heal: ensure appointment booking columns exist in existing databases
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
?>
