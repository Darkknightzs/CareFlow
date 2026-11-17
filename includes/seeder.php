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
 * If $target_hour_minute is provided (e.g. "11:00", "now", or "full"),
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
    $col_map = array_flip($header);

    // Dynamically query doctors from DB to guarantee doctor_id and avg_time mapping
    $doc_stmt = $pdo->query("SELECT doctor_id, specialization, avg_service_time_in_minutes FROM doctors");
    $doc_map = [];
    $doc_avg_time = [];
    while ($d = $doc_stmt->fetch(PDO::FETCH_ASSOC)) {
        $doc_map[$d['specialization']] = (int)$d['doctor_id'];
        $doc_avg_time[(int)$d['doctor_id']] = (int)$d['avg_service_time_in_minutes'];
    }
    // Fallback if DB had no rows
    if (empty($doc_map)) {
        $doc_map = ['General OPD' => 1, 'Cardiology' => 2, 'Orthopedics' => 3];
        $doc_avg_time = [1 => 15, 2 => 20, 3 => 15];
    }

    $spec_prefix = [
        'General OPD' => 'GEN',
        'Cardiology' => 'CAR',
        'Orthopedics' => 'ORT'
    ];

    $today = date('Y-m-d');
    
    // Smart time parsing: supports "now", "11:00", "11:00 AM", "17:15", or "full"
    $cutoff_ts = null;
    $time_label = 'Full Timeline';
    if ($simulate_time && strtolower($simulate_time) !== 'full') {
        if (strtolower($simulate_time) === 'now') {
            $cutoff_ts = time();
            $time_label = date('h:i A', $cutoff_ts);
        } else {
            $parsed = strtotime("$today $simulate_time");
            if ($parsed === false) {
                $parsed = strtotime("$today $simulate_time:00");
            }
            if ($parsed !== false) {
                $cutoff_ts = $parsed;
                $time_label = date('h:i A', $cutoff_ts);
            }
        }
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

        $token_counters = [];
        $waiting_depth = [];
        $doctor_in_prog = [];
        foreach ($doc_map as $sp => $did) {
            $token_counters[$did] = 0;
            $waiting_depth[$did] = 0;
            $doctor_in_prog[$did] = null;
        }

        $booking_counter = 0;
        $inserted_count = 0;
        $stats = ['Completed' => 0, 'In-Progress' => 0, 'Waiting' => 0, 'Booked' => 0, 'No-Show' => 0];

        $tokens_to_insert = [];

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
            $consult_mins = (int)($row[$col_map['consultation_minutes']] ?? ($doc_avg_time[$doc_map[$dept] ?? 1] ?? 15));
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

            // Determine status based on cutoff_ts (if simulated time is active)
            $status = $raw_status;
            if ($cutoff_ts) {
                $arrival_ts = $arrival_time ? strtotime($arrival_time) : null;
                $start_ts = $service_start ? strtotime($service_start) : null;
                $end_ts = $service_end ? strtotime($service_end) : null;

                if ($raw_status === 'Booked') {
                    $status = 'Booked';
                } elseif ($raw_status === 'No-Show') {
                    $status = ($cutoff_ts >= strtotime("$today 13:00:00")) ? 'No-Show' : 'Booked';
                } elseif ($end_ts && $cutoff_ts >= $end_ts) {
                    $status = 'Completed';
                } elseif ($start_ts && $cutoff_ts >= $start_ts && ($end_ts === null || $cutoff_ts < $end_ts)) {
                    if ($doctor_in_prog[$doctor_id] === null) {
                        $status = 'In-Progress';
                        $doctor_in_prog[$doctor_id] = $patient_id;
                        $service_end = null; // Active consultation
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
                    $status = ($booking_type === 'Pre-Booked') ? 'Booked' : 'Waiting';
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
                $token_counters[$doctor_id] = ($token_counters[$doctor_id] ?? 0) + 1;
                $prefix = $spec_prefix[$dept] ?? 'DOC';
                $token_number = sprintf("%s-%03d", $prefix, $token_counters[$doctor_id]);
                if (!$arrival_time) $arrival_time = "$today 09:00:00";
            }

            if (!$arrival_time) {
                $arrival_time = "$today " . date('H:i:s');
            }

            // Realistic Dynamic Estimated Wait
            $avg_m = $doc_avg_time[$doctor_id] ?? 15;
            if ($status === 'Waiting') {
                $waiting_depth[$doctor_id] = ($waiting_depth[$doctor_id] ?? 0) + 1;
                $est_wait = ($waiting_depth[$doctor_id] * $avg_m) + 5;
            } elseif ($status === 'In-Progress') {
                $est_wait = max(3, $avg_m - 5);
            } else {
                $est_wait = 0;
            }

            $tokens_to_insert[] = [
                'patient_id' => $patient_id,
                'doctor_id' => $doctor_id,
                'token_number' => $token_number,
                'booking_ref' => $booking_ref,
                'booking_type' => $booking_type,
                'scheduled_time' => $scheduled_time,
                'status' => $status,
                'arrival_time' => $arrival_time,
                'service_start' => $service_start,
                'service_end' => $service_end,
                'est_wait' => $est_wait,
            ];
        }

        // Smart ensure: if cutoff time is during clinic hours and a doctor has waiting patients but no In-Progress,
        // promote the topmost waiting patient so all clinic rooms are active for demo!
        if ($cutoff_ts) {
            foreach ($doc_map as $dept_name => $d_id) {
                if ($doctor_in_prog[$d_id] === null) {
                    foreach ($tokens_to_insert as &$tok) {
                        if ($tok['doctor_id'] === $d_id && $tok['status'] === 'Waiting' && !empty($tok['token_number'])) {
                            $tok['status'] = 'In-Progress';
                            $tok['service_start'] = date('Y-m-d H:i:s', max($cutoff_ts - (7 * 60), strtotime("$today 09:00:00")));
                            $tok['service_end'] = null;
                            $tok['est_wait'] = 5;
                            $doctor_in_prog[$d_id] = $tok['patient_id'];
                            break;
                        }
                    }
                    unset($tok);
                }
            }
        }

        // Execute batch insert
        $ins_tok = $pdo->prepare("
            INSERT INTO queue_tokens 
            (patient_id, doctor_id, token_number, booking_ref, booking_type, scheduled_time, status, arrival_time, arrival_date, service_start_time, service_end_time, estimated_wait_time)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($tokens_to_insert as $t) {
            $ins_tok->execute([
                $t['patient_id'],
                $t['doctor_id'],
                $t['token_number'],
                $t['booking_ref'],
                $t['booking_type'],
                $t['scheduled_time'],
                $t['status'],
                $t['arrival_time'],
                $today,
                $t['service_start'],
                $t['service_end'],
                $t['est_wait']
            ]);
            $stats[$t['status']] = ($stats[$t['status']] ?? 0) + 1;
            $inserted_count++;
        }

        $pdo->commit();

        return [
            'success' => true,
            'count' => $inserted_count,
            'stats' => $stats,
            'time_anchored' => $time_label
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// CLI direct execution support (e.g. php includes/seeder.php [HH:MM])
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $time_arg = $argv[1] ?? '11:00';
    $res = seedPatientDataset($pdo, null, $time_arg);
    if ($res['success']) {
        echo "[OK] Successfully seeded " . $res['count'] . " patients from dataset (Time Anchored: " . $res['time_anchored'] . ")\n";
        foreach ($res['stats'] as $st => $cnt) {
            echo "   - $st: $cnt\n";
        }
    } else {
        echo "[ERROR] Seeding failed: " . ($res['error'] ?? 'Unknown error') . "\n";
        exit(1);
    }
}
