<?php
// public/process_booking.php
require_once '../config/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'Receptionist') {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $age = (int)$_POST['age'];
    $gender = trim($_POST['gender']);
    $doctor_id = (int)($_POST['doctor_id'] ?? 0);
    
    if ($age <= 0 || $age > 150) {
        header("Location: checkin.php?error=Invalid age. Please enter a valid age.");
        exit;
    }

    if (!$doctor_id) {
        header("Location: checkin.php?error=Please select a department.");
        exit;
    }

    if (!preg_match('/^[0-9]{10}$/', $phone)) {
        header("Location: checkin.php?error=Invalid phone number format. Must be 10 digits.");
        exit;
    }

    if (isPatientAlreadyInQueueForDoctor($pdo, $phone, $doctor_id)) {
        header("Location: checkin.php?error=This patient already has an active token for this doctor. Please wait for the current turn to complete.");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Insert or update patient
        $check_pt = $pdo->prepare("SELECT patient_id FROM patients WHERE phone = ? LIMIT 1");
        $check_pt->execute([$phone]);
        $patient_id = $check_pt->fetchColumn();

        if ($patient_id) {
            $update = $pdo->prepare("UPDATE patients SET name = ?, age = ?, gender = ? WHERE patient_id = ?");
            $update->execute([$name, $age, $gender, $patient_id]);
        } else {
            $insert = $pdo->prepare("INSERT INTO patients (name, phone, age, gender) VALUES (?, ?, ?, ?)");
            $insert->execute([$name, $phone, $age, $gender]);
            $patient_id = $pdo->lastInsertId();
        }

        // 2. Calculate Estimated Wait and generate next unique Token number for this department today
        $doc_stmt = $pdo->prepare("SELECT specialization FROM doctors WHERE doctor_id = ?");
        $doc_stmt->execute([$doctor_id]);
        $doc = $doc_stmt->fetch();
        
        $prefix = strtoupper(substr($doc['specialization'] ?? 'GEN', 0, 3));
        
        // Find the maximum existing sequence number for this prefix today to prevent any duplicate key collision
        $seq_stmt = $pdo->prepare("
            SELECT token_number 
            FROM queue_tokens 
            WHERE doctor_id = ? AND (arrival_date = CURRENT_DATE OR DATE(arrival_time) = CURRENT_DATE)
            ORDER BY token_id DESC
        ");
        $seq_stmt->execute([$doctor_id]);
        $all_existing = $seq_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $max_num = 0;
        foreach ($all_existing as $tn) {
            $parts = explode('-', $tn);
            if (isset($parts[1]) && is_numeric($parts[1])) {
                $n = (int)$parts[1];
                if ($n > $max_num) $max_num = $n;
            }
        }
        $token_number = sprintf("%s-%03d", $prefix, $max_num + 1);

        $wait_data = calculateDynamicWait($pdo, $doctor_id);
        $est_wait = $wait_data['total_wait'];

        // 4. Insert Queue Token
        $insertToken = $pdo->prepare("
            INSERT INTO queue_tokens 
            (patient_id, doctor_id, token_number, estimated_wait_time, arrival_date) 
            VALUES (?, ?, ?, ?, CURRENT_DATE)
        ");
        $insertToken->execute([$patient_id, $doctor_id, $token_number, $est_wait]);

        $pdo->commit();

        header("Location: checkin.php?success=1&token=" . urlencode($token_number) . "&wait=" . urlencode($est_wait));
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Booking Error: " . $e->getMessage());
        header("Location: checkin.php?error=An unexpected system error occurred while generating the token. Please try again.");
        exit;
    }
}
?>
