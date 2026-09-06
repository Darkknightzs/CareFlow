<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Error: This setup script can only be run from the command line (CLI).\n");
}

$host = 'localhost';
$port = '3306';
$user = 'root';
$password = 'admin';

try {
    // Connect to MySQL without specifying a database to create it
    $pdo = new PDO("mysql:host=$host;port=$port", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Check if database exists
    $stmt = $pdo->query("SHOW DATABASES LIKE 'hospital_queue'");
    if (!$stmt->fetch()) {
        $pdo->exec("CREATE DATABASE hospital_queue");
        echo "Database 'hospital_queue' created successfully.\n";
    } else {
        echo "Database 'hospital_queue' already exists.\n";
    }
    
    // Now connect to the new database
    $pdo_hq = new PDO("mysql:host=$host;port=$port;dbname=hospital_queue;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // Run schema
    $sql = file_get_contents(__DIR__ . '/database/schema.sql');
    
    // Disable foreign key checks for the schema import to prevent cascade drop errors
    $pdo_hq->exec("SET FOREIGN_KEY_CHECKS = 0;");
    
    // Split SQL by statements for MySQL (it handles multi-statements differently sometimes)
    $pdo_hq->exec($sql);
    
    $pdo_hq->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "Schema and default users imported successfully from schema.sql!\n";
} catch (PDOException $e) {
    die("DB Setup Error: " . $e->getMessage() . "\n");
}
?>
