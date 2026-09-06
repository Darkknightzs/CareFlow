<?php
require_once '../config/db.php';

if (!isset($_SESSION['user_logged_in'])) {
    header("Location: lookup.php");
    exit;
}

if ($_SESSION['user_role'] === 'Doctor') {
    header("Location: dashboard.php");
    exit;
} elseif ($_SESSION['user_role'] === 'Receptionist') {
    header("Location: checkin.php");
    exit;
} elseif ($_SESSION['user_role'] === 'Admin') {
    header("Location: admin.php");
    exit;
}

header("Location: login.php");
exit;
?>
