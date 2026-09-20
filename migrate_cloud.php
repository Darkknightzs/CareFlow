<?php
// migrate_cloud.php
// Run this script locally to initialize tables and dummy data on Aiven Cloud MySQL

$aiven_uri = 'mysql://avnadmin:AVNS_10wzEo2SaW2-M30uRtr@mysql-16d103a-strojincskubisy7535-4391.j.aivencloud.com:20229/defaultdb?ssl-mode=REQUIRED';

echo "Connecting to Aiven Cloud MySQL...\n";
$db_parts = parse_url($aiven_uri);
$host = $db_parts['host'];
$port = $db_parts['port'];
$user = $db_parts['user'];
$password = $db_parts['pass'];
$dbname = ltrim($db_parts['path'], '/');

$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
$pdo_options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    PDO::ATTR_TIMEOUT => 10,
];

try {
    $pdo = new PDO($dsn, $user, $password, $pdo_options);
    echo "Connected successfully!\n";

    // 1. Run Schema
    echo "Creating tables (users, doctors, patients, queue_tokens)...\n";
    $schema_sql = file_get_contents(__DIR__ . '/database/schema.sql');
    $pdo->exec($schema_sql);
    echo "Tables created successfully!\n";

    // 2. Fetch doctors
    $doc_stmt = $pdo->query("SELECT doctor_id, specialization FROM doctors");
    $doctors = $doc_stmt->fetchAll();
    $dr_gen_opd = 1; $dr_cardio = 2; $dr_ortho = 3;
    foreach ($doctors as $d) {
        if ($d['specialization'] === 'General OPD') $dr_gen_opd = $d['doctor_id'];
        if ($d['specialization'] === 'Cardiology') $dr_cardio = $d['doctor_id'];
        if ($d['specialization'] === 'Orthopedics') $dr_ortho = $d['doctor_id'];
    }

    // 3. Seed Realistic Dummy Patients & Tokens for Today
    echo "Seeding live patients and tokens into Aiven MySQL...\n";
    $dummy_patients = [
        ['name' => 'Aarav Sharma',      'phone' => '9876543201', 'age' => 34, 'gender' => 'Male',   'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Completed'],
        ['name' => 'Priya Patel',       'phone' => '9876543202', 'age' => 28, 'gender' => 'Female', 'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Completed'],
        ['name' => 'Kunal Kapoor',      'phone' => '9876543203', 'age' => 39, 'gender' => 'Male',   'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'No-Show'],
        ['name' => 'Rohan Mehta',       'phone' => '9876543204', 'age' => 45, 'gender' => 'Male',   'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Completed'],
        ['name' => 'Ananya Verma',      'phone' => '9876543205', 'age' => 26, 'gender' => 'Female', 'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'In-Progress'],
        ['name' => 'Vikram Malhotra',   'phone' => '9876543206', 'age' => 52, 'gender' => 'Male',   'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Waiting'],
        ['name' => 'Sunita Rao',        'phone' => '9876543207', 'age' => 41, 'gender' => 'Female', 'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Waiting'],
        ['name' => 'Meera Joshi',       'phone' => '9876543208', 'age' => 31, 'gender' => 'Female', 'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Waiting'],

        ['name' => 'Michael Wilson',    'phone' => '9876543211', 'age' => 58, 'gender' => 'Male',   'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'Completed'],
        ['name' => 'Robert Johnson',    'phone' => '9876543212', 'age' => 49, 'gender' => 'Male',   'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'No-Show'],
        ['name' => 'David Miller',      'phone' => '9876543213', 'age' => 63, 'gender' => 'Male',   'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'Completed'],
        ['name' => 'Sarah Brown',       'phone' => '9876543214', 'age' => 60, 'gender' => 'Female', 'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'In-Progress'],
        ['name' => 'James Taylor',      'phone' => '9876543215', 'age' => 55, 'gender' => 'Male',   'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'Waiting'],
        ['name' => 'Susan White',       'phone' => '9876543216', 'age' => 62, 'gender' => 'Female', 'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'Waiting'],

        ['name' => 'Alexander Scott',   'phone' => '9876543221', 'age' => 35, 'gender' => 'Male',   'doc' => $dr_ortho,   'prefix' => 'ORT', 'status' => 'Completed'],
        ['name' => 'Sophia Turner',     'phone' => '9876543222', 'age' => 42, 'gender' => 'Female', 'doc' => $dr_ortho,   'prefix' => 'ORT', 'status' => 'Completed'],
        ['name' => 'Daniel Craig',      'phone' => '9876543223', 'age' => 38, 'gender' => 'Male',   'doc' => $dr_ortho,   'prefix' => 'ORT', 'status' => 'In-Progress'],
        ['name' => 'Emily Watson',      'phone' => '9876543224', 'age' => 29, 'gender' => 'Female', 'doc' => $dr_ortho,   'prefix' => 'ORT', 'status' => 'Waiting'],
        ['name' => 'Lucas Garcia',      'phone' => '9876543225', 'age' => 27, 'gender' => 'Male',   'doc' => $dr_ortho,   'prefix' => 'ORT', 'status' => 'Waiting']
    ];

    $current_date = date('Y-m-d');
    $base_time = strtotime("$current_date 09:00:00");
    $counters = ['GEN' => 1, 'CAR' => 1, 'ORT' => 1];

    foreach ($dummy_patients as $index => $p) {
        $ins_p = $pdo->prepare("INSERT INTO patients (name, phone, age, gender) VALUES (?, ?, ?, ?)");
        $ins_p->execute([$p['name'], $p['phone'], $p['age'], $p['gender']]);
        $pt_id = $pdo->lastInsertId();

        $token_number = $p['prefix'] . '-' . str_pad($counters[$p['prefix']]++, 3, '0', STR_PAD_LEFT);
        $status = $p['status'];
        $arr_time = date('Y-m-d H:i:s', $base_time + ($index * 1200));
        $s_start = ($status === 'Completed' || $status === 'In-Progress') ? date('Y-m-d H:i:s', $base_time + ($index * 1200) + 180) : null;
        $s_end = ($status === 'Completed') ? date('Y-m-d H:i:s', strtotime($s_start) + 600) : null;

        $ins_t = $pdo->prepare("
            INSERT INTO queue_tokens (patient_id, doctor_id, token_number, booking_type, estimated_wait_time, status, arrival_time, arrival_date, service_start_time, service_end_time)
            VALUES (?, ?, ?, 'Walk-in', ?, ?, ?, ?, ?, ?)
        ");
        $ins_t->execute([$pt_id, $p['doc'], $token_number, ($status === 'Waiting' ? 15 : 0), $status, $arr_time, $current_date, $s_start, $s_end]);
    }

    echo "\nSUCCESS! All tables and live data have been pushed to Aiven MySQL database!\n";
} catch (PDOException $e) {
    echo "Connection Error: " . $e->getMessage() . "\n";
}
