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
        (SELECT COUNT(*) FROM queue_tokens qt WHERE qt.doctor_id = d.doctor_id AND qt.status = 'Waiting' AND (qt.arrival_date = CURRENT_DATE OR DATE(qt.arrival_time) = CURRENT_DATE) AND qt.token_number IS NOT NULL) as waiting_count
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
    $prefix = strtoupper(substr($specialization ?? 'GEN', 0, 3));
    
    // Find the maximum existing sequence number for this doctor and prefix today
    $stmt = $pdo->prepare("
        SELECT token_number 
        FROM queue_tokens 
        WHERE doctor_id = ? 
        AND token_number IS NOT NULL
        AND (arrival_date = CURRENT_DATE OR DATE(arrival_time) = CURRENT_DATE)
        ORDER BY token_id DESC
    ");
    $stmt->execute([$doctor_id]);
    $all_existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $max_num = 0;
    foreach ($all_existing as $tn) {
        $parts = explode('-', $tn);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $n = (int)$parts[1];
            if ($n > $max_num) $max_num = $n;
        }
    }
    
    return sprintf("%s-%03d", $prefix, $max_num + 1);
}

/**
 * Check if the patient with this phone number already has an active token or booking for THIS doctor on target date.
 */
function isPatientAlreadyInQueueForDoctor($pdo, $phone, $doctor_id, $target_date = null) {
    if (!$target_date) $target_date = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT qt.token_id, qt.status, qt.booking_ref, qt.token_number
        FROM queue_tokens qt
        JOIN patients p ON qt.patient_id = p.patient_id
        WHERE p.phone = ? 
        AND qt.status IN ('Waiting', 'In-Progress', 'Booked')
        AND (qt.arrival_date = ? OR DATE(qt.scheduled_time) = ? OR DATE(qt.arrival_time) = ?)
        AND qt.doctor_id = ?
    ");
    $stmt->execute([$phone, $target_date, $target_date, $target_date, $doctor_id]);
    return $stmt->fetch() !== false;
}

/**
 * Generate a unique Booking Reference like BK-001 for advance bookings
 */
function generateBookingReference($pdo, $target_date = null) {
    if (!$target_date) $target_date = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT booking_ref 
        FROM queue_tokens 
        WHERE booking_ref LIKE 'BK-%' 
        AND (arrival_date = ? OR DATE(scheduled_time) = ? OR DATE(arrival_time) = ?)
        ORDER BY token_id DESC
    ");
    $stmt->execute([$target_date, $target_date, $target_date]);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $max_num = 0;
    foreach ($existing as $ref) {
        $parts = explode('-', $ref);
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $n = (int)$parts[1];
            if ($n > $max_num) $max_num = $n;
        }
    }
    return sprintf("BK-%03d", $max_num + 1);
}

/**
 * Calculate available appointment slots for a doctor today.
 * Follows morning (9 AM - 1 PM) and evening (5 PM - 8 PM) sessions,
 * enforces the booking_slot_percentage capacity, and filters booked or passed slots.
 */
function getAvailableSlots($pdo, $doctor_id, $ignore_past_filter = false, $target_date = null) {
    $stmt = $pdo->prepare("
        SELECT doctor_id, name, specialization, room_number, 
               avg_service_time_in_minutes,
               COALESCE(working_start_time, '09:00:00') as working_start_time,
               COALESCE(working_end_time, '13:00:00') as working_end_time,
               COALESCE(evening_start_time, '17:00:00') as evening_start_time,
               COALESCE(evening_end_time, '20:00:00') as evening_end_time,
               COALESCE(booking_slot_percentage, 70) as booking_slot_percentage
        FROM doctors 
        WHERE doctor_id = ?
    ");
    $stmt->execute([$doctor_id]);
    $doc = $stmt->fetch();

    if (!$doc) return ['slots' => [], 'doc' => null, 'total_capacity' => 0, 'bookable_capacity' => 0];

    // Determine target date: If past 20:00 (8:00 PM, evening OPD closed), automatically target tomorrow morning!
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    if (!$target_date) {
        $target_date = ((int)date('H') >= 20) ? $tomorrow : $today;
    }

    $is_tomorrow = ($target_date === $tomorrow);
    $is_today = ($target_date === $today);

    $interval = max(5, (int)$doc['avg_service_time_in_minutes']);
    $pct = min(100, max(10, (int)$doc['booking_slot_percentage']));
    $now_ts = time();

    // Helper to generate slots for a time range
    $generateSlots = function($start_str, $end_str) use ($target_date, $interval) {
        $slots = [];
        $curr = strtotime("$target_date $start_str");
        $end = strtotime("$target_date $end_str");
        while (($curr + ($interval * 60)) <= $end) {
            $slots[] = date('H:i:s', $curr);
            $curr += ($interval * 60);
        }
        return $slots;
    };

    // 1. Generate full raw slots for morning and evening shifts
    $morning_slots = $generateSlots($doc['working_start_time'], $doc['working_end_time']);
    $evening_slots = !empty($doc['evening_start_time']) && !empty($doc['evening_end_time']) 
        ? $generateSlots($doc['evening_start_time'], $doc['evening_end_time']) 
        : [];

    $all_slots = array_merge($morning_slots, $evening_slots);
    $total_capacity = count($all_slots);

    // 2. Calculate bookable capacity based on percentage (round down)
    // Distribute proportionally across morning and evening
    $morning_bookable_count = (int)floor(count($morning_slots) * ($pct / 100));
    $evening_bookable_count = (int)floor(count($evening_slots) * ($pct / 100));

    $bookable_morning = array_slice($morning_slots, 0, $morning_bookable_count);
    $bookable_evening = array_slice($evening_slots, 0, $evening_bookable_count);
    $bookable_slots = array_merge($bookable_morning, $bookable_evening);
    $bookable_capacity = count($bookable_slots);

    // 3. Fetch already booked slots for this doctor on the target date
    $booked_stmt = $pdo->prepare("
        SELECT scheduled_time 
        FROM queue_tokens 
        WHERE doctor_id = ? 
        AND booking_type = 'Pre-Booked' 
        AND status NOT IN ('Cancelled', 'No-Show') 
        AND (arrival_date = ? OR DATE(scheduled_time) = ?)
        AND scheduled_time IS NOT NULL
    ");
    $booked_stmt->execute([$doctor_id, $target_date, $target_date]);
    $taken_raw = $booked_stmt->fetchAll(PDO::FETCH_COLUMN);

    $taken_times = [];
    foreach ($taken_raw as $t) {
        $taken_times[date('H:i:s', strtotime($t))] = true;
    }

    // 4. Filter slots: must be bookable, not taken, and in the future
    // If target date is tomorrow, ALL slots are in the future!
    $available_slots = [];
    foreach ($bookable_slots as $slot_time) {
        $slot_ts = strtotime("$target_date $slot_time");
        $is_taken = isset($taken_times[$slot_time]);
        $is_future = ($ignore_past_filter || !$is_today) ? true : ($slot_ts > $now_ts);

        if (!$is_taken && $is_future) {
            $is_morning = strtotime($slot_time) < strtotime('14:00:00');
            $available_slots[] = [
                'time_24' => $slot_time,
                'time_12' => date('h:i A', $slot_ts),
                'session' => $is_morning ? 'Morning OPD (09:00 AM – 01:00 PM)' : 'Evening OPD (05:00 PM – 08:00 PM)',
                'timestamp' => $slot_ts
            ];
        }
    }

    return [
        'target_date' => $target_date,
        'is_tomorrow' => $is_tomorrow,
        'date_label' => $is_tomorrow ? ('Tomorrow (' . date('M d, Y', strtotime($target_date)) . ')') : ('Today (' . date('M d, Y', strtotime($target_date)) . ')'),
        'slots' => $available_slots,
        'doc' => $doc,
        'total_capacity' => $total_capacity,
        'bookable_capacity' => $bookable_capacity,
        'walkin_capacity' => $total_capacity - $bookable_capacity
    ];
}

/**
 * Automatically promotes the next eligible 'Waiting' patient to 'In-Progress'
 * for a given doctor if the doctor is not currently serving any patient.
 * Uses atomic transaction locking to prevent race conditions or double-promotions.
 *
 * @param PDO $pdo
 * @param int $doctor_id
 * @return int|null The token_id promoted, or null if no promotion occurred.
 */
function autoPromoteNextPatient($pdo, $doctor_id) {
    $doctor_id = (int)$doctor_id;
    if ($doctor_id <= 0) return null;

    try {
        $started_transaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started_transaction = true;
        }

        // 1. Check if there is already an 'In-Progress' patient for this doctor today
        $in_prog_stmt = $pdo->prepare("
            SELECT token_id FROM queue_tokens 
            WHERE doctor_id = ? 
            AND status = 'In-Progress' 
            AND (arrival_date = CURRENT_DATE OR DATE(arrival_time) = CURRENT_DATE)
            LIMIT 1
        ");
        $in_prog_stmt->execute([$doctor_id]);
        $has_in_progress = $in_prog_stmt->fetchColumn();

        if ($has_in_progress) {
            if ($started_transaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return null;
        }

        // 2. Find the next eligible 'Waiting' patient by effective time
        // Only checked-in tokens (token_number IS NOT NULL) qualify
        $next_stmt = $pdo->prepare("
            SELECT token_id FROM queue_tokens 
            WHERE doctor_id = ? 
            AND status = 'Waiting' 
            AND token_number IS NOT NULL 
            AND (arrival_date = CURRENT_DATE OR DATE(arrival_time) = CURRENT_DATE)
            ORDER BY COALESCE(scheduled_time, arrival_time) ASC 
            LIMIT 1
        ");
        $next_stmt->execute([$doctor_id]);
        $next_token_id = $next_stmt->fetchColumn();

        if ($next_token_id) {
            // Atomic update: only update if STILL 'Waiting'
            $upd = $pdo->prepare("
                UPDATE queue_tokens 
                SET status = 'In-Progress', service_start_time = CURRENT_TIMESTAMP 
                WHERE token_id = ? AND status = 'Waiting'
            ");
            $upd->execute([$next_token_id]);
            
            if ($started_transaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return (int)$next_token_id;
        }

        if ($started_transaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
        return null;
    } catch (Exception $e) {
        if (isset($started_transaction) && $started_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("autoPromoteNextPatient error: " . $e->getMessage());
        return null;
    }
}
?>
