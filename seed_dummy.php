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
    die("Please run setup_db.php first to set up the doctors.\n");
}

$dr_gen_opd = null;
$dr_cardio = null;
$dr_ortho = null;

foreach ($doctors as $d) {
    if ($d['specialization'] === 'General OPD') $dr_gen_opd = $d['doctor_id'];
    if ($d['specialization'] === 'Cardiology') $dr_cardio = $d['doctor_id'];
    if ($d['specialization'] === 'Orthopedics') $dr_ortho = $d['doctor_id'];
}

$dr_gen_opd = $dr_gen_opd ?? $doctors[0]['doctor_id'];
$dr_cardio = $dr_cardio ?? ($doctors[1]['doctor_id'] ?? $doctors[0]['doctor_id']);
$dr_ortho = $dr_ortho ?? ($doctors[2]['doctor_id'] ?? $doctors[0]['doctor_id']);

// Realistic dummy patient data strictly following real clinic timeline:
// [Completed / Past No-Shows] -> [Currently In-Progress] -> [Waiting Patients]
$dummy_patients = [
    // General OPD (Dr. Rajesh Sharma)
    ['name' => 'Aarav Sharma',      'phone' => '9876543201', 'age' => 34, 'gender' => 'Male',   'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Completed'],
    ['name' => 'Priya Patel',       'phone' => '9876543202', 'age' => 28, 'gender' => 'Female', 'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Completed'],
    ['name' => 'Kunal Kapoor',      'phone' => '9876543203', 'age' => 39, 'gender' => 'Male',   'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'No-Show'],
    ['name' => 'Rohan Mehta',       'phone' => '9876543204', 'age' => 45, 'gender' => 'Male',   'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Completed'],
    ['name' => 'Ananya Verma',      'phone' => '9876543205', 'age' => 26, 'gender' => 'Female', 'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'In-Progress'],
    ['name' => 'Vikram Malhotra',   'phone' => '9876543206', 'age' => 52, 'gender' => 'Male',   'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Waiting'],
    ['name' => 'Sunita Rao',        'phone' => '9876543207', 'age' => 41, 'gender' => 'Female', 'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Waiting'],
    ['name' => 'Meera Joshi',       'phone' => '9876543208', 'age' => 31, 'gender' => 'Female', 'doc' => $dr_gen_opd, 'prefix' => 'GEN', 'status' => 'Waiting'],

    // Cardiology (Dr. Ananya Mukherjee)
    ['name' => 'Manish Tiwari',     'phone' => '9876543211', 'age' => 58, 'gender' => 'Male',   'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'Completed'],
    ['name' => 'Rajendra Prasad',   'phone' => '9876543212', 'age' => 61, 'gender' => 'Male',   'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'No-Show'],
    ['name' => 'Suresh Aggarwal',   'phone' => '9876543213', 'age' => 63, 'gender' => 'Male',   'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'Completed'],
    ['name' => 'Pooja Chawla',      'phone' => '9876543214', 'age' => 48, 'gender' => 'Female', 'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'In-Progress'],
    ['name' => 'Harish Chandra',    'phone' => '9876543215', 'age' => 55, 'gender' => 'Male',   'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'Waiting'],
    ['name' => 'Shobha Nambiar',    'phone' => '9876543216', 'age' => 62, 'gender' => 'Female', 'doc' => $dr_cardio,  'prefix' => 'CAR', 'status' => 'Waiting'],

    // Orthopedics (Dr. Vikram Verma)
    ['name' => 'Amitabh Saxena',    'phone' => '9876543221', 'age' => 35, 'gender' => 'Male',   'doc' => $dr_ortho,   'prefix' => 'ORT', 'status' => 'Completed'],
    ['name' => 'Deepika Padukone',  'phone' => '9876543222', 'age' => 36, 'gender' => 'Female', 'doc' => $dr_ortho,   'prefix' => 'ORT', 'status' => 'Completed'],
    ['name' => 'Gaurav Kulkarni',   'phone' => '9876543223', 'age' => 38, 'gender' => 'Male',   'doc' => $dr_ortho,   'prefix' => 'ORT', 'status' => 'In-Progress'],
    ['name' => 'Neha Singhania',    'phone' => '9876543224', 'age' => 29, 'gender' => 'Female', 'doc' => $dr_ortho,   'prefix' => 'ORT', 'status' => 'Waiting'],
    ['name' => 'Devendra Bisht',    'phone' => '9876543225', 'age' => 43, 'gender' => 'Male',   'doc' => $dr_ortho,   'prefix' => 'ORT', 'status' => 'Waiting']
];

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
$pdo->exec("TRUNCATE TABLE queue_tokens");
$pdo->exec("TRUNCATE TABLE patients");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

$current_date = date('Y-m-d');
$base_time = strtotime("$current_date 09:00:00");

// Update doctors with standard shift hours and booking percentage
$pdo->exec("
    UPDATE doctors 
    SET working_start_time = '09:00:00',
        working_end_time = '13:00:00',
        evening_start_time = '17:00:00',
        evening_end_time = '20:00:00',
        booking_slot_percentage = 70
");

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

    // Make some patients pre-booked to demonstrate interleaving
    $booking_type = ($index % 3 === 0) ? 'Pre-Booked' : 'Walk-in';
    $scheduled_time = ($booking_type === 'Pre-Booked') ? date('Y-m-d H:i:s', $base_time + ($index * 900)) : null;
    $booking_ref = ($booking_type === 'Pre-Booked') ? 'BK-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT) : null;
    
    $token_stmt = $pdo->prepare("
        INSERT INTO queue_tokens 
        (patient_id, doctor_id, token_number, booking_ref, booking_type, scheduled_time, estimated_wait_time, status, arrival_time, arrival_date, service_start_time, service_end_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $wait_time = ($status === 'Waiting') ? 15 : 0;
    
    $token_stmt->execute([
        $patient_id,
        $p['doc'],
        $token_number,
        $booking_ref,
        $booking_type,
        $scheduled_time,
        $wait_time,
        $status,
        $arrival_time,
        $current_date,
        $service_start,
        $service_end
    ]);
}

// Also Seed 2-3 Pending Pre-Bookings (Status 'Booked', Token Number NULL) for immediate reception check-in demonstration
$pending_prebookings = [
    ['name' => 'Kavita Singhania', 'phone' => '9876543231', 'age' => 44, 'gender' => 'Female', 'doc' => $dr_gen_opd, 'slot' => '10:30:00'],
    ['name' => 'Aditya Roy',        'phone' => '9876543232', 'age' => 31, 'gender' => 'Male',   'doc' => $dr_cardio,  'slot' => '11:20:00'],
    ['name' => 'Nandini Joshi',     'phone' => '9876543233', 'age' => 50, 'gender' => 'Female', 'doc' => $dr_ortho,   'slot' => '17:30:00']
];

$bk_ref_counter = 20;
foreach ($pending_prebookings as $pb) {
    $ins_p = $pdo->prepare("INSERT INTO patients (name, phone, age, gender) VALUES (?, ?, ?, ?)");
    $ins_p->execute([$pb['name'], $pb['phone'], $pb['age'], $pb['gender']]);
    $pt_id = $pdo->lastInsertId();

    $b_ref = 'BK-' . str_pad($bk_ref_counter++, 3, '0', STR_PAD_LEFT);
    $sched_dt = "$current_date " . $pb['slot'];

    $ins_b = $pdo->prepare("
        INSERT INTO queue_tokens 
        (patient_id, doctor_id, token_number, booking_ref, booking_type, scheduled_time, estimated_wait_time, status, arrival_time, arrival_date)
        VALUES (?, ?, NULL, ?, 'Pre-Booked', ?, 0, 'Booked', CURRENT_TIMESTAMP, ?)
    ");
    $ins_b->execute([$pt_id, $pb['doc'], $b_ref, $sched_dt, $current_date]);
}

echo "Successfully seeded dummy patients, live tokens, and pending advance bookings for today!\n";
?>
