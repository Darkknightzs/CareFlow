<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Error: This setup script can only be run from the command line (CLI).\n");
}
require_once 'config/db.php';

echo "Seeding dummy data...\n";

// Fetch both doctors
$stmt = $pdo->query("SELECT doctor_id, specialization FROM doctors ORDER BY doctor_id");
$doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($doctors) < 2) {
    die("Please run db_migrate.php first to set up the two doctors.");
}

$dr_gen_opd = null;
$dr_cardio = null;
$dr_ortho = null;

foreach ($doctors as $d) {
    if ($d['specialization'] === 'General OPD') $dr_gen_opd = $d['doctor_id'];
    if ($d['specialization'] === 'Cardiology') $dr_cardio = $d['doctor_id'];
    if ($d['specialization'] === 'Orthopedics') $dr_ortho = $d['doctor_id'];
}

// Realistic dummy patient data strictly following real clinic timeline:
// [Completed / Past No-Shows] -> [Currently In-Progress] -> [Waiting Patients]
$dummy_patients = [
    // General OPD (Dr. Smith)
    ['name' => 'Aarav Sharma',      'phone' => '9876543201', 'age' => 34, 'gender' => 'Male',   'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Completed'],
    ['name' => 'Priya Patel',       'phone' => '9876543202', 'age' => 28, 'gender' => 'Female', 'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Completed'],
    ['name' => 'Kunal Kapoor',      'phone' => '9876543203', 'age' => 39, 'gender' => 'Male',   'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'No-Show'],
    ['name' => 'Rohan Mehta',       'phone' => '9876543204', 'age' => 45, 'gender' => 'Male',   'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Completed'],
    ['name' => 'Ananya Verma',      'phone' => '9876543205', 'age' => 26, 'gender' => 'Female', 'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'In-Progress'],
    ['name' => 'Vikram Malhotra',   'phone' => '9876543206', 'age' => 52, 'gender' => 'Male',   'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Waiting'],
    ['name' => 'Sunita Rao',        'phone' => '9876543207', 'age' => 41, 'gender' => 'Female', 'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Waiting'],
    ['name' => 'Meera Joshi',       'phone' => '9876543208', 'age' => 31, 'gender' => 'Female', 'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Waiting'],

    // Cardiology (Dr. Andrew)
    ['name' => 'Michael Wilson',    'phone' => '9876543211', 'age' => 58, 'gender' => 'Male',   'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'Completed'],
    ['name' => 'Robert Johnson',    'phone' => '9876543212', 'age' => 49, 'gender' => 'Male',   'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'No-Show'],
    ['name' => 'David Miller',      'phone' => '9876543213', 'age' => 63, 'gender' => 'Male',   'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'Completed'],
    ['name' => 'Sarah Brown',       'phone' => '9876543214', 'age' => 60, 'gender' => 'Female', 'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'In-Progress'],
    ['name' => 'James Taylor',      'phone' => '9876543215', 'age' => 55, 'gender' => 'Male',   'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'Waiting'],
    ['name' => 'Susan White',       'phone' => '9876543216', 'age' => 62, 'gender' => 'Female', 'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'Waiting'],

    // Orthopedics (Dr. Shawn)
    ['name' => 'Alexander Scott',   'phone' => '9876543221', 'age' => 35, 'gender' => 'Male',   'doc' => $dr_ortho,   'prefix' => 'ORT', 'status' => 'Completed'],
    ['name' => 'Sophia Turner',     'phone' => '9876543222', 'age' => 42, 'gender' => 'Female', 'doc' => $dr_ortho,   'prefix' => 'ORT', 'status' => 'Completed'],
    ['name' => 'Daniel Craig',      'phone' => '9876543223', 'age' => 38, 'gender' => 'Male',   'doc' => $dr_ortho,   'prefix' => 'ORT', 'status' => 'In-Progress'],
    ['name' => 'Emily Watson',      'phone' => '9876543224', 'age' => 29, 'gender' => 'Female', 'doc' => $dr_ortho,   'prefix' => 'ORT', 'status' => 'Waiting'],
    ['name' => 'Lucas Garcia',      'phone' => '9876543225', 'age' => 27, 'gender' => 'Male',   'doc' => $dr_ortho,   'prefix' => 'ORT', 'status' => 'Waiting']
];

if ($GLOBALS['DB_ENGINE'] === 'sqlite') {
    $pdo->exec("DELETE FROM queue_tokens");
    $pdo->exec("DELETE FROM patients");
} else {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("TRUNCATE TABLE queue_tokens");
    $pdo->exec("TRUNCATE TABLE patients");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
}

$current_date = date('Y-m-d');
$base_time = strtotime("$current_date 09:00:00");

$counters = ['GEN' => 1, 'CAR' => 1, 'ORT' => 1];

foreach ($dummy_patients as $index => $p) {
    // Insert Patient
    $stmt = $pdo->prepare("INSERT INTO patients (name, phone, age, gender) VALUES (?, ?, ?, ?)");
    $stmt->execute([$p['name'], $p['phone'], $p['age'], $p['gender']]);
    $patient_id = $pdo->lastInsertId();
    
    // Create Token
    $token_number = $p['prefix'] . '-' . str_pad($counters[$p['prefix']]++, 3, '0', STR_PAD_LEFT);
    $status = $p['status'];
    
    $arrival_time = date('Y-m-d H:i:s', $base_time + ($index * 1200)); 
    $service_start = null;
    $service_end = null;
    
    if ($status === 'Completed' || $status === 'In-Progress') {
        $service_start = date('Y-m-d H:i:s', $base_time + ($index * 1200) + rand(120, 420));
    }
    if ($status === 'Completed') {
        $service_end = date('Y-m-d H:i:s', strtotime($service_start) + rand(480, 1320));
    }
    
    $token_stmt = $pdo->prepare("
        INSERT INTO queue_tokens (patient_id, doctor_id, token_number, estimated_wait_time, status, arrival_time, arrival_date, service_start_time, service_end_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $wait_time = ($status === 'Waiting') ? 15 : 0;
    
    $token_stmt->execute([
        $patient_id,
        $p['doc'],
        $token_number,
        $wait_time,
        $status,
        $arrival_time,
        $current_date,
        $service_start,
        $service_end
    ]);
}

echo "Successfully seeded dummy patients and queue tokens for today!\n";
?>
