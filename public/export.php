<?php
// public/export.php
require_once '../config/db.php';

if (!isset($_SESSION['user_logged_in']) || !in_array($_SESSION['user_role'], ['Doctor', 'Admin'])) {
    header('Location: login.php');
    exit;
}


// Prepare query to get all records for today (or all history if preferred)
$stmt = $pdo->query("
    SELECT 
        COALESCE(qt.token_number, qt.booking_ref, 'N/A') as token_number, 
        p.name as patient_name,
        p.phone,
        p.age,
        p.gender,
        d.name as doctor_name,
        d.specialization as department,
        qt.booking_type,
        qt.booking_ref,
        qt.scheduled_time,
        qt.arrival_time, 
        qt.service_start_time, 
        qt.service_end_time,
        qt.status
    FROM queue_tokens qt
    JOIN doctors d ON qt.doctor_id = d.doctor_id
    JOIN patients p ON qt.patient_id = p.patient_id
    ORDER BY COALESCE(qt.scheduled_time, qt.arrival_time) ASC
");

$records = $stmt->fetchAll();

// Set headers to trigger download as CSV (robust Excel-compatible CSV)
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=queue_analytics_' . date('Y-m-d') . '.csv');

// Create a file pointer connected to the output stream
$output = fopen('php://output', 'w');

// Output the column headings
fputcsv($output, [
    'Token / Ref No', 
    'Booking Type',
    'Booking Ref',
    'Scheduled Time',
    'Patient Name',
    'Phone',
    'Age',
    'Gender',
    'Doctor Name',
    'Department', 
    'Arrival Time', 
    'Service Start Time', 
    'Service End Time', 
    'Total Wait Time (mins)',
    'Status'
]);

// Output the data rows
foreach ($records as $row) {
    $wait_time_display = 'N/A';
    if (!empty($row['service_start_time']) && !empty($row['arrival_time'])) {
        $wait_time_display = round((strtotime($row['service_start_time']) - strtotime($row['arrival_time'])) / 60);
    }
    fputcsv($output, [
        $row['token_number'],
        $row['booking_type'] ?? 'Walk-in',
        $row['booking_ref'] ?? 'N/A',
        !empty($row['scheduled_time']) ? date('h:i A', strtotime($row['scheduled_time'])) : 'N/A',
        $row['patient_name'],
        $row['phone'],
        $row['age'],
        $row['gender'],
        $row['doctor_name'],
        $row['department'],
        $row['arrival_time'],
        $row['service_start_time'] ?? 'N/A',
        $row['service_end_time'] ?? 'N/A',
        $wait_time_display,
        $row['status']
    ]);
}

fclose($output);
exit;
?>
