<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Error: This setup script can only be run from the command line (CLI).\n");
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USER') ?: 'root';

// Passwords to attempt: environment variable first, then standard defaults ('admin', '')
$passwords = [];
if (getenv('DB_PASS') !== false) {
    $passwords[] = getenv('DB_PASS');
} else {
    $passwords = ['admin', ''];
}

$pdo = null;
$connected_pwd = null;
$last_error = null;

foreach ($passwords as $pwd) {
    try {
        $pdo = new PDO("mysql:host=$host;port=$port", $user, $pwd, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]);
        $connected_pwd = $pwd;
        break;
    } catch (PDOException $e) {
        $last_error = $e;
        if ($e->getCode() != 1045 && strpos($e->getMessage(), 'Access denied') === false) {
            // Non-auth error (e.g. server down / connection refused)
            break;
        }
    }
}

if (!$pdo) {
    die("DB Setup Error: Could not connect to MySQL server at $host:$port (" . ($last_error ? $last_error->getMessage() : 'Access denied') . ")\n");
}

try {
    // Check if database exists
    $stmt = $pdo->query("SHOW DATABASES LIKE 'hospital_queue'");
    if (!$stmt->fetch()) {
        $pdo->exec("CREATE DATABASE hospital_queue CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "[OK] Database 'hospital_queue' created successfully.\n";
    } else {
        echo "[OK] Database 'hospital_queue' ready.\n";
    }
    
    // Connect directly to hospital_queue
    $pdo_hq = new PDO("mysql:host=$host;port=$port;dbname=hospital_queue;charset=utf8mb4", $user, $connected_pwd, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    // Import schema
    $sql_file = __DIR__ . '/database/schema.sql';
    if (!file_exists($sql_file)) {
        die("DB Setup Error: Schema file not found at $sql_file\n");
    }
    
    $sql = file_get_contents($sql_file);
    $pdo_hq->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo_hq->exec($sql);
    $pdo_hq->exec("SET FOREIGN_KEY_CHECKS = 1;");
    
    echo "[OK] Schema and default users imported successfully from schema.sql!\n";
} catch (PDOException $e) {
    die("DB Setup Error: " . $e->getMessage() . "\n");
}
?>
