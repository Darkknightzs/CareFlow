<?php
// includes/functions.php

/**
 * Calculate dynamic estimated wait time based on the number of 'Waiting' patients,
 * the doctor's historical average service time, and current delays.
 */
function calculateDynamicWait($pdo, $doctor_id) {
    // 1. Get static average and waiting count
    $stmt = $pdo->prepare("
        SELECT d.avg_service_time_in_minutes, 
        (SELECT COUNT(*) FROM queue_tokens qt WHERE qt.doctor_id = d.doctor_id AND qt.status = 'Waiting' AND DATE(qt.arrival_time) = CURRENT_DATE) as waiting_count
        FROM doctors d WHERE d.doctor_id = ?
    ");
    $stmt->execute([$doctor_id]);
    $data = $stmt->fetch();
    
    if (!$data) return ['effective_avg' => 0, 'total_wait' => 0];

    $static_avg = (int)$data['avg_service_time_in_minutes'];
    $waiting_count = (int)$data['waiting_count'];

    // 2. Get real average from completed tokens today
    $completed_stmt = $pdo->prepare("
        SELECT service_start_time, service_end_time 
        FROM queue_tokens 
        WHERE doctor_id = ? AND status = 'Completed' AND (arrival_date = CURRENT_DATE OR DATE(arrival_time) = CURRENT_DATE)
        AND service_start_time IS NOT NULL AND service_end_time IS NOT NULL
    ");
    $completed_stmt->execute([$doctor_id]);
    $completed_tokens = $completed_stmt->fetchAll();

    if (count($completed_tokens) > 0) {
        $total_duration = 0;
        foreach ($completed_tokens as $ct) {
            $diff = max(1, round((strtotime($ct['service_end_time']) - strtotime($ct['service_start_time'])) / 60));
            $total_duration += $diff;
        }
        $effective_avg = round($total_duration / count($completed_tokens));
    } else {
        $effective_avg = $static_avg;
    }
    $total_wait = $effective_avg * $waiting_count;

    return [
        'effective_avg' => $effective_avg,
        'total_wait' => $total_wait
    ];
}

/**
 * Generate a unique token number like CAR-001
 */
function generateTokenNumber($pdo, $doctor_id, $specialization) {
    $prefix = strtoupper(substr($specialization, 0, 3));
    
    // Get the count of tokens for today to determine the next number
    $stmt = $pdo->prepare("SELECT COUNT(*) as token_count FROM queue_tokens WHERE DATE(arrival_time) = CURRENT_DATE AND doctor_id = ?");
    $stmt->execute([$doctor_id]);
    $result = $stmt->fetch();
    
    $next_number = (int)$result['token_count'] + 1;
    
    return sprintf("%s-%03d", $prefix, $next_number);
}

/**
 * Check if the patient with this phone number already has an active token for THIS doctor today.
 */
function isPatientAlreadyInQueueForDoctor($pdo, $phone, $doctor_id) {
    $stmt = $pdo->prepare("
        SELECT qt.token_id 
        FROM queue_tokens qt
        JOIN patients p ON qt.patient_id = p.patient_id
        WHERE p.phone = ? 
        AND qt.status IN ('Waiting', 'In-Progress')
        AND DATE(qt.arrival_time) = CURRENT_DATE
        AND qt.doctor_id = ?
    ");
    $stmt->execute([$phone, $doctor_id]);
    return $stmt->fetch() !== false;
}
?>
