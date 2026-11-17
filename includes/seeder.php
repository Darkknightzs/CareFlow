<?php
// includes/seeder.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

/**
 * Clear all queue tokens and demo patients
 * Leaves doctors and staff users untouched.
 */
function clearQueueData($pdo) {
    try {
        // DDL causes implicit commit in MySQL; run deletes in transaction
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM queue_tokens");
        $pdo->exec("DELETE FROM patients");
        $pdo->commit();

        // Non-transactional reset
        @$pdo->exec("ALTER TABLE queue_tokens AUTO_INCREMENT = 1");
        @$pdo->exec("ALTER TABLE patients AUTO_INCREMENT = 1");
        
        return ['success' => true, 'message' => 'Queue data successfully cleared. Doctors and staff preserved.'];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Seed dataset from careflow_patient_dataset.csv
 * If $target_hour_minute is provided (e.g. "11:00" or null for as-is mapped to today),
 * it dynamically adjusts statuses based on the timeline.
 */
function seedPatientDataset($pdo, $csv_path = null, $simulate_time = null) {
    if (!$csv_path) {
        $csv_path = __DIR__ . '/../data/careflow_patient_dataset.csv';
    }
    
    if (!file_exists($csv_path)) {
        return ['success' => false, 'error' => "CSV dataset not found at $csv_path"];
    }

    $lines = file($csv_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($lines)) {
        return ['success' => false, 'error' => 'CSV file is empty'];
    }

    $header = str_getcsv(array_shift($lines));
    // Normalize header keys
    $col_map = array_flip($header);

    // Map department to doctor_id
    $doc_map = [
        'General OPD' => 1,
        'Cardiology' => 2,
        'Orthopedics' => 3
    ];
    $spec_prefix = [
        'General OPD' => 'GEN',
        'Cardiology' => 'CAR',
        'Orthopedics' => 'ORT'
    ];

    $today = date('Y-m-d');
    
    // Determine simulation cutoff timestamp if provided
    $cutoff_ts = null;
    if ($simulate_time) {
        // simulate_time e.g. "11:00" or "17:15"
        $cutoff_ts = strtotime("$today $simulate_time:00");
    }

    try {
        // Wipe existing queue tokens to avoid unique constraint collisions
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM queue_tokens");
        $pdo->exec("DELETE FROM patients");
        $pdo->commit();

        @$pdo->exec("ALTER TABLE queue_tokens AUTO_INCREMENT = 1");
        @$pdo->exec("ALTER TABLE patients AUTO_INCREMENT = 1");

        $pdo->beginTransaction();

        $token_counters = [1 => 0, 2 => 0, 3 => 0];
        $booking_counter = 0;
        
        $inserted_count = 0;
        $stats = ['Completed' => 0, 'In-Progress' => 0, 'Waiting' => 0, 'Booked' => 0, 'No-Show' => 0];

        // Group rows so we know in-progress per doctor
        $doctor_in_prog = [1 => null, 2 => null, 3 => null];

        foreach ($lines as $line) {
            $row = str_getcsv($line);
            if (count($row) < count($col_map)) continue;

            $name = trim($row[$col_map['patient_name']] ?? '');
            $phone = trim($row[$col_map['phone']] ?? '');
            $age = (int)($row[$col_map['age']] ?? 30);
            $gender = trim($row[$col_map['gender']] ?? 'Male');
            $dept = trim($row[$col_map['department']] ?? 'General OPD');
            $booking_type = trim($row[$col_map['booking_type']] ?? 'Walk-in');
            $raw_status = trim($row[$col_map['status']] ?? 'Waiting');
            $consult_mins = (int)($row[$col_map['consultation_minutes']] ?? 15);
            $raw_arrival = trim($row[$col_map['arrival_time']] ?? '');
            $raw_start = trim($row[$col_map['service_start_time']] ?? '');
            $raw_end = trim($row[$col_map['service_end_time']] ?? '');

            if (empty($name) || !isset($doc_map[$dept])) continue;
            $doctor_id = $doc_map[$dept];

            // 1. Insert patient
            $ins_p = $pdo->prepare("INSERT INTO patients (name, phone, age, gender) VALUES (?, ?, ?, ?)");
            $ins_p->execute([$name, $phone, $age, $gender]);
            $patient_id = $pdo->lastInsertId();

            // 2. Map timestamps to TODAY
            $map_to_today = function($raw_dt) use ($today) {
                if (empty($raw_dt)) return null;
                $t = substr($raw_dt, 11, 8); // Extract HH:MM or HH:MM:SS
                if (strlen($t) === 5) $t .= ':00';
                return "$today $t";
            };

            $arrival_time = $map_to_today($raw_arrival);
            $service_start = $map_to_today($raw_start);
            $service_end = $map_to_today($raw_end);

            // Determine status based on cutoff_ts (if simulated time is provided)
            $status = $raw_status;
            if ($cutoff_ts) {
                $arrival_ts = $arrival_time ? strtotime($arrival_time) : null;
                $start_ts = $service_start ? strtotime($service_start) : null;
                $end_ts = $service_end ? strtotime($service_end) : null;

                if ($raw_status === 'Booked') {
                    $status = 'Booked';
                } elseif ($raw_status === 'No-Show') {
                    // Only mark No-Show if after the scheduled morning slot
                    $status = ($cutoff_ts >= strtotime("$today 13:00:00")) ? 'No-Show' : 'Booked';
                } elseif ($end_ts && $cutoff_ts >= $end_ts) {
                    $status = 'Completed';
                } elseif ($start_ts && $cutoff_ts >= $start_ts && ($end_ts === null || $cutoff_ts < $end_ts)) {
                    // Currently being served
                    if ($doctor_in_prog[$doctor_id] === null) {
                        $status = 'In-Progress';
                        $doctor_in_prog[$doctor_id] = $patient_id;
                        $service_end = null; // Still in consultation
                    } else {
                        $status = 'Waiting';
                        $service_start = null;
                        $service_end = null;
                    }
                } elseif ($arrival_ts && $cutoff_ts >= $arrival_ts) {
                    $status = 'Waiting';
                    $service_start = null;
                    $service_end = null;
                } else {
                    // Future appointment not yet arrived
                    if ($booking_type === 'Pre-Booked') {
                        $status = 'Booked';
                    } else {
                        // Future walk-in not yet in clinic
                        $status = 'Waiting';
                    }
                    $service_start = null;
                    $service_end = null;
                }
            }

            // Assign Token Number or Booking Ref
            $token_number = null;
            $booking_ref = null;
            $scheduled_time = null;

            if ($booking_type === 'Pre-Booked') {
                $booking_counter++;
                $booking_ref = sprintf("BK-%03d", $booking_counter);
                $scheduled_time = $arrival_time ?: "$today 10:00:00";
            }

            // If checked-in (Waiting, In-Progress, Completed, or Walk-in)
            if ($status !== 'Booked') {
                $token_counters[$doctor_id]++;
                $token_number = sprintf("%s-%03d", $spec_prefix[$dept], $token_counters[$doctor_id]);
                if (!$arrival_time) $arrival_time = "$today 09:00:00";
            }

            // Default arrival fallback
            if (!$arrival_time) {
                $arrival_time = "$today " . date('H:i:s');
            }

            // Estimated wait
            $est_wait = max(5, $consult_mins);

            $ins_tok = $pdo->prepare("
                INSERT INTO queue_tokens 
                (patient_id, doctor_id, token_number, booking_ref, booking_type, scheduled_time, status, arrival_time, arrival_date, service_start_time, service_end_time, estimated_wait_time)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins_tok->execute([
                $patient_id,
                $doctor_id,
                $token_number,
                $booking_ref,
                $booking_type,
                $scheduled_time,
                $status,
                $arrival_time,
                $today,
                $service_start,
                $service_end,
                $est_wait
            ]);

            $stats[$status] = ($stats[$status] ?? 0) + 1;
            $inserted_count++;
        }

        $pdo->commit();

        return [
            'success' => true,
            'count' => $inserted_count,
            'stats' => $stats,
            'time_anchored' => $simulate_time ?: 'Full Timeline'
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
