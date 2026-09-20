<?php
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'Receptionist') {
    header("Location: login.php");
    exit;
}

$action_error = $_GET['error'] ?? '';
$action_msg = $_GET['msg'] ?? '';
$searched_booking = null;
$active_tab = $_GET['tab'] ?? 'walkin';

// Handle Booking Conversion to Live Queue Token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'convert_booking') {
        $token_id = (int)($_POST['token_id'] ?? 0);

        // Fetch the booked token row
        $stmt = $pdo->prepare("
            SELECT qt.*, d.specialization, p.name as patient_name, p.phone as patient_phone 
            FROM queue_tokens qt
            JOIN doctors d ON qt.doctor_id = d.doctor_id
            JOIN patients p ON qt.patient_id = p.patient_id
            WHERE qt.token_id = ? AND qt.status = 'Booked'
        ");
        $stmt->execute([$token_id]);
        $booking = $stmt->fetch();

        if ($booking) {
            try {
                $pdo->beginTransaction();

                // Generate actual queue token (e.g. GEN-005)
                $token_number = generateTokenNumber($pdo, $booking['doctor_id'], $booking['specialization']);
                $wait_data = calculateDynamicWait($pdo, $booking['doctor_id']);
                $est_wait = $wait_data['total_wait'];

                // Convert status to Waiting, set arrival_time to NOW
                $upd = $pdo->prepare("
                    UPDATE queue_tokens 
                    SET token_number = ?, status = 'Waiting', arrival_time = CURRENT_TIMESTAMP, arrival_date = CURRENT_DATE, estimated_wait_time = ?
                    WHERE token_id = ?
                ");
                $upd->execute([$token_number, $est_wait, $token_id]);

                $pdo->commit();

                header("Location: checkin.php?success=1&token=" . urlencode($token_number) . "&wait=" . urlencode($est_wait) . "&converted=1&ref=" . urlencode($booking['booking_ref']));
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $action_error = "Failed to convert booking: " . $e->getMessage();
            }
        } else {
            $action_error = "Selected booking not found or already checked in.";
        }
        $active_tab = 'booking';
    } elseif ($_POST['action'] === 'noshow_booking') {
        // Grace period safety net: mark pre-booked entry as No-Show
        $token_id = (int)($_POST['token_id'] ?? 0);
        $upd = $pdo->prepare("UPDATE queue_tokens SET status = 'No-Show' WHERE token_id = ? AND status = 'Booked'");
        $upd->execute([$token_id]);
        header("Location: checkin.php?tab=booking&msg=Booking marked as No-Show.");
        exit;
    } elseif ($_POST['action'] === 'search_booking') {
        $query = strtoupper(trim($_POST['search_query'] ?? ''));
        $digits = preg_replace('/[^0-9]/', '', $query);

        $search_stmt = $pdo->prepare("
            SELECT qt.*, d.name as doctor_name, d.specialization, d.room_number, p.name as patient_name, p.phone as patient_phone, p.age, p.gender
            FROM queue_tokens qt
            JOIN doctors d ON qt.doctor_id = d.doctor_id
            JOIN patients p ON qt.patient_id = p.patient_id
            WHERE (UPPER(qt.booking_ref) = ? OR p.phone = ?)
            AND qt.status = 'Booked'
            AND (qt.arrival_date >= CURRENT_DATE OR DATE(qt.scheduled_time) >= CURRENT_DATE OR DATE(qt.arrival_time) = CURRENT_DATE)
            ORDER BY qt.token_id DESC LIMIT 1
        ");
        $search_stmt->execute([$query, $digits]);
        $searched_booking = $search_stmt->fetch();

        if (!$searched_booking) {
            $action_error = "No pending booking found matching '$query'. You can register this patient as a walk-in.";
        }
        $active_tab = 'booking';
    }
}

// Fetch doctors for the walk-in dropdown
$stmt = $pdo->query("SELECT * FROM doctors ORDER BY name");
$doctors = $stmt->fetchAll();

// Fetch today's pending pre-bookings for quick conversion
$pending_bookings_stmt = $pdo->query("
    SELECT qt.*, d.name as doctor_name, d.specialization, d.room_number, p.name as patient_name, p.phone as patient_phone
    FROM queue_tokens qt
    JOIN doctors d ON qt.doctor_id = d.doctor_id
    JOIN patients p ON qt.patient_id = p.patient_id
    WHERE qt.status = 'Booked'
    AND (qt.arrival_date = CURRENT_DATE OR DATE(qt.scheduled_time) = CURRENT_DATE OR DATE(qt.arrival_time) = CURRENT_DATE)
    ORDER BY qt.scheduled_time ASC
");
$pending_bookings = $pending_bookings_stmt->fetchAll();

// Fetch recent check-ins for the right table
$recent_stmt = $pdo->query("
    SELECT qt.token_number, p.name as patient_name, qt.status, qt.booking_type, qt.booking_ref
    FROM queue_tokens qt
    JOIN patients p ON qt.patient_id = p.patient_id
    WHERE (DATE(qt.arrival_time) = CURRENT_DATE OR qt.arrival_date = CURRENT_DATE) AND qt.token_number IS NOT NULL
    ORDER BY qt.arrival_time DESC
    LIMIT 6
");
$recent_tokens = $recent_stmt->fetchAll();

include '../includes/header.php';
?>

<div class="w-full max-w-6xl mx-auto">
    
    <div class="glass-card p-6 lg:p-8 flex flex-col lg:flex-row gap-8 items-start shadow-2xl">
        
        <!-- Left Side: Check-in Tabs and Forms -->
        <div class="w-full lg:w-1/2">
            
            <div class="mb-5 flex justify-between items-start">
                <div>
                    <span class="inline-block py-1 px-2.5 rounded-md bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300 font-bold text-[10px] tracking-widest uppercase border border-brand-200 dark:border-brand-700/50 mb-2">Front Desk Portal</span>
                    <h2 class="text-3xl font-black text-slate-800 dark:text-white tracking-tight leading-tight">Reception Check-in</h2>
                    <p class="text-slate-500 dark:text-slate-400 mt-1 text-xs font-medium">Issue queue tokens for walk-ins or convert advance bookings.</p>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="flex border-b border-slate-200 dark:border-slate-700 mb-5 gap-2">
                <button type="button" onclick="switchTab('walkin')" id="tab-btn-walkin" class="py-2.5 px-4 font-bold text-xs rounded-t-xl transition-all flex items-center gap-1.5 <?= $active_tab === 'walkin' ? 'bg-white dark:bg-slate-800 text-brand-600 dark:text-brand-400 border-t-2 border-brand-600 shadow-xs' : 'text-slate-500 hover:text-slate-800 dark:hover:text-white' ?>">
                    <i class="ph ph-user-plus text-base"></i>
                    <span>1. Walk-in Check-in</span>
                </button>
                <button type="button" onclick="switchTab('booking')" id="tab-btn-booking" class="py-2.5 px-4 font-bold text-xs rounded-t-xl transition-all flex items-center gap-1.5 relative <?= $active_tab === 'booking' ? 'bg-white dark:bg-slate-800 text-brand-600 dark:text-brand-400 border-t-2 border-brand-600 shadow-xs' : 'text-slate-500 hover:text-slate-800 dark:hover:text-white' ?>">
                    <i class="ph ph-calendar-check text-base"></i>
                    <span>2. Convert Booking</span>
                    <?php if (count($pending_bookings) > 0): ?>
                        <span class="w-4 h-4 rounded-full bg-brand-600 text-white text-[9px] font-black inline-flex items-center justify-center"><?= count($pending_bookings) ?></span>
                    <?php endif; ?>
                </button>
            </div>
            
            <?php if ($action_error): ?>
                <div class="bg-red-50/80 border border-red-200 text-red-700 p-3 mb-5 rounded-xl flex items-start gap-3 text-xs animate-pulse-slow">
                    <i class="ph ph-warning-circle text-lg flex-shrink-0 mt-0.5"></i>
                    <p class="font-bold"><?= htmlspecialchars($action_error) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($action_msg): ?>
                <div class="bg-emerald-50/80 border border-emerald-200 text-emerald-700 p-3 mb-5 rounded-xl flex items-start gap-3 text-xs">
                    <i class="ph ph-check-circle text-lg flex-shrink-0 mt-0.5"></i>
                    <p class="font-bold"><?= htmlspecialchars($action_msg) ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['success'])): ?>
                <!-- Success Token Card -->
                <div class="bg-gradient-to-br from-brand-50 to-white dark:from-slate-800 dark:to-slate-900 border border-brand-200 dark:border-brand-700/50 p-6 rounded-2xl shadow-lg text-center relative overflow-hidden mb-5">
                    <div class="absolute -right-4 -top-4 text-brand-100 dark:text-brand-900/30 transform rotate-12">
                        <i class="ph-fill ph-ticket text-9xl"></i>
                    </div>
                    
                    <span class="inline-block py-0.5 px-3 rounded-full bg-brand-100 dark:bg-brand-900/60 text-brand-700 dark:text-brand-300 font-extrabold text-[10px] tracking-widest uppercase mb-1">
                        <?= isset($_GET['converted']) ? 'Booking Converted & Token Issued' : 'Walk-in Token Issued' ?>
                    </span>
                    <div class="my-3 relative z-10">
                        <p class="text-5xl font-black text-transparent bg-clip-text bg-gradient-to-r from-brand-600 to-purple-600 dark:from-brand-400 dark:to-purple-400 tracking-wider"><?= htmlspecialchars($_GET['token']) ?></p>
                    </div>

                    <?php if (!empty($_GET['ref'])): ?>
                        <p class="text-xs text-slate-500 font-semibold mb-2">Booking Ref: <strong class="text-slate-700 dark:text-slate-300"><?= htmlspecialchars($_GET['ref']) ?></strong></p>
                    <?php endif; ?>

                    <div class="flex justify-center items-center gap-2 relative z-10 mt-1 bg-brand-100 dark:bg-slate-800 rounded-lg py-1.5 px-4 inline-block mx-auto border border-brand-200 dark:border-slate-700">
                        <i class="ph ph-clock text-brand-600 dark:text-brand-400 text-base"></i>
                        <span class="text-xs font-medium text-slate-700 dark:text-slate-300">Est. Wait: <strong class="font-bold text-brand-700 dark:text-brand-300"><?= (int)$_GET['wait'] ?> mins</strong></span>
                    </div>

                    <div class="mt-4 relative z-10">
                        <a href="checkin.php" class="btn-primary inline-flex items-center gap-2 px-6 py-2.5 text-xs font-bold">
                            <i class="ph ph-plus"></i> Next Check-in
                        </a>
                    </div>
                </div>

            <?php else: ?>
                
                <!-- TAB 1: Walk-in Registration (Existing Form) -->
                <div id="tab-content-walkin" class="<?= $active_tab === 'walkin' ? '' : 'hidden' ?>">
                    <form action="process_booking.php" method="POST" class="grid grid-cols-2 gap-3.5">
                        <div class="col-span-2 sm:col-span-1">
                            <label for="name" class="form-label !text-[10px]">Full Name</label>
                            <div class="relative">
                                <i class="ph ph-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-base"></i>
                                <input type="text" id="name" name="name" required class="form-input !pl-9 !py-2 text-sm" placeholder="Patient Name">
                            </div>
                        </div>
                        
                        <div class="col-span-2 sm:col-span-1">
                            <label for="phone" class="form-label !text-[10px]">Mobile Number</label>
                            <div class="relative">
                                <i class="ph ph-phone absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-base"></i>
                                <input type="tel" id="phone" name="phone" pattern="[0-9]{10}" required class="form-input !pl-9 !py-2 text-sm" placeholder="9876543210" title="10 digits">
                            </div>
                        </div>
                        
                        <div class="col-span-2 sm:col-span-1">
                            <label for="age" class="form-label !text-[10px]">Age</label>
                            <input type="number" id="age" name="age" required min="1" max="150" class="form-input !pl-4 !py-2 text-sm" placeholder="45">
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <label for="gender" class="form-label !text-[10px]">Gender</label>
                            <div class="relative">
                                <select id="gender" name="gender" required class="form-input !pl-4 !py-2 text-sm appearance-none">
                                    <option value="" disabled selected>---</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                                <i class="ph ph-caret-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"></i>
                            </div>
                        </div>

                        <div class="col-span-2">
                            <label for="doctor_id" class="form-label !text-[10px]">Department / Doctor</label>
                            <div class="relative">
                                <i class="ph ph-stethoscope absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-base"></i>
                                <select id="doctor_id" name="doctor_id" required class="form-input appearance-none !pl-9 !py-2 text-sm">
                                    <option value="" disabled selected>Select a specialist...</option>
                                    <?php foreach ($doctors as $doc): ?>
                                        <option value="<?= $doc['doctor_id'] ?>">
                                            <?= htmlspecialchars($doc['specialization']) ?> — <?= htmlspecialchars($doc['name']) ?> (<?= htmlspecialchars($doc['room_number']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="ph ph-caret-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"></i>
                            </div>
                        </div>

                        <div class="col-span-2 pt-1">
                            <button type="submit" class="btn-primary w-full text-sm py-2.5 flex justify-center items-center gap-2">
                                <span>Issue Walk-in Token</span>
                                <i class="ph ph-arrow-right font-bold text-xs"></i>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- TAB 2: Convert Pre-Booking to Live Token -->
                <div id="tab-content-booking" class="<?= $active_tab === 'booking' ? '' : 'hidden' ?> space-y-4">
                    
                    <!-- Search Input -->
                    <form method="POST" class="flex gap-2">
                        <input type="hidden" name="action" value="search_booking">
                        <div class="relative flex-1">
                            <i class="ph ph-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-base"></i>
                            <input type="text" name="search_query" required class="form-input !pl-9 !py-2 text-sm uppercase font-bold" placeholder="Enter BK-001 or Phone Number">
                        </div>
                        <button type="submit" class="btn-primary px-4 py-2 text-xs font-bold flex items-center gap-1.5 whitespace-nowrap">
                            <span>Find Booking</span>
                        </button>
                    </form>

                    <!-- Searched Booking Card (if query was matched) -->
                    <?php if ($searched_booking): ?>
                        <div class="bg-brand-50/70 dark:bg-slate-800/70 border border-brand-200 dark:border-brand-700/60 p-4 rounded-xl shadow-sm">
                            <div class="flex justify-between items-start mb-3">
                                <div>
                                    <span class="text-[10px] font-black text-brand-600 dark:text-brand-400 uppercase tracking-wider block">Found Appointment</span>
                                    <h4 class="text-lg font-black text-slate-800 dark:text-white"><?= htmlspecialchars($searched_booking['patient_name']) ?></h4>
                                    <p class="text-xs text-slate-500"><?= htmlspecialchars($searched_booking['patient_phone']) ?> • <?= htmlspecialchars($searched_booking['age']) ?>y • <?= htmlspecialchars($searched_booking['gender']) ?></p>
                                </div>
                                <div class="text-right">
                                    <span class="text-xs font-black text-brand-700 dark:text-brand-300 block"><?= htmlspecialchars($searched_booking['booking_ref']) ?></span>
                                    <span class="text-[11px] font-bold text-slate-600 dark:text-slate-300"><?= date('h:i A', strtotime($searched_booking['scheduled_time'])) ?></span>
                                </div>
                            </div>

                            <p class="text-xs text-slate-600 dark:text-slate-300 mb-3">
                                <strong>Doctor:</strong> <?= htmlspecialchars($searched_booking['doctor_name']) ?> (<?= htmlspecialchars($searched_booking['specialization']) ?>)
                            </p>

                            <?php 
                            $scheduled_ts = strtotime($searched_booking['scheduled_time']);
                            $is_late = (time() - $scheduled_ts) > 900; // >15 mins late
                            ?>

                            <div class="flex gap-2">
                                <form method="POST" class="flex-1">
                                    <input type="hidden" name="action" value="convert_booking">
                                    <input type="hidden" name="token_id" value="<?= $searched_booking['token_id'] ?>">
                                    <button type="submit" class="btn-primary w-full py-2 text-xs font-bold flex items-center justify-center gap-1.5">
                                        <i class="ph ph-check-circle"></i> Check In & Issue Token
                                    </button>
                                </form>

                                <?php if ($is_late): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="noshow_booking">
                                        <input type="hidden" name="token_id" value="<?= $searched_booking['token_id'] ?>">
                                        <button type="submit" class="bg-amber-100 hover:bg-amber-200 text-amber-800 font-bold px-3 py-2 rounded-xl text-xs flex items-center gap-1" title="Patient is > 15 mins late">
                                            <i class="ph ph-user-minus"></i> Mark No-Show
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Today's Pending Pre-Bookings List -->
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Today's Pre-Booked Appointments (<?= count($pending_bookings) ?>)</span>
                        </div>

                        <?php if (count($pending_bookings) > 0): ?>
                            <div class="space-y-2 max-h-72 overflow-y-auto pr-1">
                                <?php foreach ($pending_bookings as $pb): ?>
                                    <?php 
                                    $pb_time_ts = strtotime($pb['scheduled_time']);
                                    $pb_is_late = (time() - $pb_time_ts) > 900;
                                    ?>
                                    <div class="bg-white/80 dark:bg-slate-800/80 p-3 rounded-xl border border-slate-200/80 dark:border-slate-700 flex justify-between items-center gap-3">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="font-extrabold text-xs text-brand-600 dark:text-brand-400"><?= htmlspecialchars($pb['booking_ref']) ?></span>
                                                <span class="text-xs font-bold text-slate-800 dark:text-white truncate"><?= htmlspecialchars($pb['patient_name']) ?></span>
                                            </div>
                                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 truncate">
                                                <?= date('h:i A', $pb_time_ts) ?> • <?= htmlspecialchars($pb['specialization']) ?>
                                                <?php if ($pb_is_late): ?>
                                                    <span class="text-amber-600 font-bold ml-1">(Late > 15m)</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>

                                        <div class="flex items-center gap-1.5 flex-shrink-0">
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="convert_booking">
                                                <input type="hidden" name="token_id" value="<?= $pb['token_id'] ?>">
                                                <button type="submit" class="btn-primary py-1.5 px-3 text-[11px] font-bold flex items-center gap-1 shadow-xs">
                                                    <i class="ph ph-check"></i> Check In
                                                </button>
                                            </form>

                                            <?php if ($pb_is_late): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="action" value="noshow_booking">
                                                    <input type="hidden" name="token_id" value="<?= $pb['token_id'] ?>">
                                                    <button type="submit" class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950/50" title="Mark No-Show">
                                                        <i class="ph ph-user-minus text-base"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-6 bg-slate-50/50 dark:bg-slate-900/40 rounded-xl border border-slate-200/60 dark:border-slate-800 text-slate-400 text-xs">
                                <i class="ph ph-calendar-blank text-2xl mb-1 block"></i>
                                No pending pre-booked appointments for today.
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

            <?php endif; ?>
        </div>
        
        <!-- Right Side: Recent Tokens List -->
        <div class="w-full lg:w-1/2 bg-white/40 dark:bg-slate-800/40 p-5 rounded-2xl border border-white/60 dark:border-slate-700/50 shadow-inner flex flex-col h-full">
            <h3 class="font-bold text-slate-700 dark:text-slate-300 mb-3 flex items-center gap-2">
                <i class="ph ph-list-numbers text-brand-500 text-xl"></i> 
                Today's Checked-in Queue
            </h3>
            
            <?php if (count($recent_tokens) > 0): ?>
                <div class="flex-1 space-y-2.5">
                    <?php foreach ($recent_tokens as $t): ?>
                        <div class="bg-white/70 dark:bg-slate-800/70 p-3 rounded-xl border border-slate-100 dark:border-slate-700 flex justify-between items-center shadow-sm">
                            <div>
                                <div class="flex items-center gap-1.5">
                                    <span class="font-black text-slate-800 dark:text-white"><?= htmlspecialchars($t['token_number']) ?></span>
                                    <?php if ($t['booking_type'] === 'Pre-Booked'): ?>
                                        <span class="text-[9px] font-extrabold px-1.5 py-0.5 rounded bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                            Pre-Booked
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <span class="text-xs font-medium text-slate-500 dark:text-slate-400"><?= htmlspecialchars($t['patient_name']) ?></span>
                            </div>
                            <?php 
                                $badge_color = 'bg-slate-100 text-slate-600 border-slate-200';
                                if ($t['status'] === 'Waiting') $badge_color = 'bg-yellow-100 text-yellow-700 border-yellow-200 dark:bg-yellow-900/30 dark:text-yellow-400 dark:border-yellow-700/50';
                                elseif ($t['status'] === 'In-Progress') $badge_color = 'bg-brand-100 text-brand-700 border-brand-200 dark:bg-brand-900/30 dark:text-brand-400 dark:border-brand-700/50';
                                elseif ($t['status'] === 'Completed') $badge_color = 'bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-400 dark:border-emerald-700/50';
                            ?>
                            <span class="text-[10px] uppercase font-bold px-2 py-1 rounded-md border <?= $badge_color ?>">
                                <?= htmlspecialchars($t['status']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="flex-1 flex flex-col justify-center items-center opacity-50 py-10">
                    <i class="ph ph-ghost text-4xl mb-2"></i>
                    <p class="text-sm font-medium">No check-ins yet today.</p>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
</div>

<script>
function switchTab(tab) {
    const walkinBtn = document.getElementById('tab-btn-walkin');
    const bookingBtn = document.getElementById('tab-btn-booking');
    const walkinContent = document.getElementById('tab-content-walkin');
    const bookingContent = document.getElementById('tab-content-booking');

    if (tab === 'walkin') {
        walkinBtn.className = 'py-2.5 px-4 font-bold text-xs rounded-t-xl transition-all flex items-center gap-1.5 bg-white dark:bg-slate-800 text-brand-600 dark:text-brand-400 border-t-2 border-brand-600 shadow-xs';
        bookingBtn.className = 'py-2.5 px-4 font-bold text-xs rounded-t-xl transition-all flex items-center gap-1.5 relative text-slate-500 hover:text-slate-800 dark:hover:text-white';
        walkinContent.classList.remove('hidden');
        bookingContent.classList.add('hidden');
    } else {
        bookingBtn.className = 'py-2.5 px-4 font-bold text-xs rounded-t-xl transition-all flex items-center gap-1.5 relative bg-white dark:bg-slate-800 text-brand-600 dark:text-brand-400 border-t-2 border-brand-600 shadow-xs';
        walkinBtn.className = 'py-2.5 px-4 font-bold text-xs rounded-t-xl transition-all flex items-center gap-1.5 text-slate-500 hover:text-slate-800 dark:hover:text-white';
        bookingContent.classList.remove('hidden');
        walkinContent.classList.add('hidden');
    }
}
</script>

    </div> <!-- Close container from header -->
</body>
</html>
