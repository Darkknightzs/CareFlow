<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Kolkata');

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
        } else {
            // Self-heal: ensure appointment booking columns and nullable token_number exist in SQLite
            try {
                $cols_info = $pdo->query("PRAGMA table_info(queue_tokens)")->fetchAll(PDO::FETCH_ASSOC);
                $cols = array_column($cols_info, 'name');
                $token_col = array_values(array_filter($cols_info, fn($c) => $c['name'] === 'token_number'));
                $needs_table_rebuild = !empty($token_col) && $token_col[0]['notnull'] == 1;

                if ($needs_table_rebuild) {
                    $pdo->exec("PRAGMA foreign_keys = OFF;");
                    $pdo->exec("CREATE TABLE IF NOT EXISTS queue_tokens_v2 (
                        token_id INTEGER PRIMARY KEY AUTOINCREMENT,
                        patient_id INTEGER NOT NULL REFERENCES patients(patient_id) ON DELETE CASCADE,
                        doctor_id INTEGER NOT NULL REFERENCES doctors(doctor_id) ON DELETE CASCADE,
                        token_number TEXT NULL,
                        booking_ref TEXT NULL,
                        booking_type TEXT NOT NULL DEFAULT 'Walk-in',
                        scheduled_time TIMESTAMP NULL,
                        status TEXT NOT NULL DEFAULT 'Waiting',
                        arrival_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        arrival_date DATE NOT NULL DEFAULT (DATE('now')),
                        service_start_time TIMESTAMP NULL,
                        service_end_time TIMESTAMP NULL,
                        estimated_wait_time INTEGER NOT NULL DEFAULT 0,
                        UNIQUE (token_number, arrival_date)
                    );");
                    $has_btype = in_array('booking_type', $cols);
                    if ($has_btype) {
                        $pdo->exec("INSERT INTO queue_tokens_v2 (token_id, patient_id, doctor_id, token_number, booking_ref, booking_type, scheduled_time, status, arrival_time, arrival_date, service_start_time, service_end_time, estimated_wait_time)
                                    SELECT token_id, patient_id, doctor_id, token_number, booking_ref, booking_type, scheduled_time, status, arrival_time, arrival_date, service_start_time, service_end_time, estimated_wait_time FROM queue_tokens;");
                    } else {
                        $pdo->exec("INSERT INTO queue_tokens_v2 (token_id, patient_id, doctor_id, token_number, status, arrival_time, arrival_date, service_start_time, service_end_time, estimated_wait_time)
                                    SELECT token_id, patient_id, doctor_id, token_number, status, arrival_time, arrival_date, service_start_time, service_end_time, estimated_wait_time FROM queue_tokens;");
                    }
                    $pdo->exec("DROP TABLE queue_tokens;");
                    $pdo->exec("ALTER TABLE queue_tokens_v2 RENAME TO queue_tokens;");
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_queue_status ON queue_tokens(status);");
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_queue_date ON queue_tokens(arrival_time);");
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_booking_ref ON queue_tokens(booking_ref);");
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_scheduled_time ON queue_tokens(scheduled_time);");
                    $pdo->exec("PRAGMA foreign_keys = ON;");
                } elseif (!in_array('booking_type', $cols)) {
                    @$pdo->exec("ALTER TABLE doctors ADD COLUMN working_start_time TEXT DEFAULT '09:00:00'");
                    @$pdo->exec("ALTER TABLE doctors ADD COLUMN working_end_time TEXT DEFAULT '13:00:00'");
                    @$pdo->exec("ALTER TABLE doctors ADD COLUMN evening_start_time TEXT DEFAULT '17:00:00'");
                    @$pdo->exec("ALTER TABLE doctors ADD COLUMN evening_end_time TEXT DEFAULT '20:00:00'");
                    @$pdo->exec("ALTER TABLE doctors ADD COLUMN booking_slot_percentage INTEGER DEFAULT 70");
                    @$pdo->exec("ALTER TABLE queue_tokens ADD COLUMN booking_ref TEXT NULL");
                    @$pdo->exec("ALTER TABLE queue_tokens ADD COLUMN booking_type TEXT DEFAULT 'Walk-in'");
                    @$pdo->exec("ALTER TABLE queue_tokens ADD COLUMN scheduled_time TIMESTAMP NULL");
                }

                // Ensure doctors table has new shift columns
                $doc_cols = $pdo->query("PRAGMA table_info(doctors)")->fetchAll(PDO::FETCH_COLUMN, 1);
                if (!in_array('working_start_time', $doc_cols)) {
                    @$pdo->exec("ALTER TABLE doctors ADD COLUMN working_start_time TEXT DEFAULT '09:00:00'");
                    @$pdo->exec("ALTER TABLE doctors ADD COLUMN working_end_time TEXT DEFAULT '13:00:00'");
                    @$pdo->exec("ALTER TABLE doctors ADD COLUMN evening_start_time TEXT DEFAULT '17:00:00'");
                    @$pdo->exec("ALTER TABLE doctors ADD COLUMN evening_end_time TEXT DEFAULT '20:00:00'");
                    @$pdo->exec("ALTER TABLE doctors ADD COLUMN booking_slot_percentage INTEGER DEFAULT 70");
                }
            } catch (Exception $e) {}
        }

        $GLOBALS['DB_ENGINE'] = 'sqlite';
    } catch (PDOException $e) {
        die("Database Connection failed (MySQL & SQLite): " . $e->getMessage());
    }
}
?>
