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

                // If doctor currently has no in-progress patient, promote immediately
                autoPromoteNextPatient($pdo, (int)$booking['doctor_id']);

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

<div class="w-full max-w-5xl mx-auto">
    
    <div class="card p-6 md:p-8 flex flex-col lg:flex-row gap-8 items-start shadow-sm border border-slate-200/80 dark:border-slate-800">
        
        <!-- Left Side: Check-in Tabs and Forms -->
        <div class="w-full lg:w-7/12">
            
            <div class="mb-5 flex justify-between items-start">
                <div>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-emerald-50 text-emerald-700 dark:bg-emerald-950/80 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 mb-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Front Desk Reception
                    </span>
                    <h2 class="text-2xl font-bold font-display text-slate-900 dark:text-white tracking-tight">Patient Check-in Desk</h2>
                    <p class="text-slate-500 dark:text-slate-400 text-xs mt-0.5">Issue live tokens for spot walk-ins or check in pre-booked patients</p>
                </div>
            </div>

            <!-- Tab Navigation (Sleek Segmented Controller) -->
            <div class="p-1 bg-slate-100 dark:bg-slate-800/80 rounded-xl mb-6 flex gap-1 border border-slate-200/80 dark:border-slate-700">
                <button type="button" onclick="switchTab('walkin')" id="tab-btn-walkin" class="flex-1 py-2 px-3 font-bold text-xs rounded-lg transition-all flex items-center justify-center gap-2 <?= $active_tab === 'walkin' ? 'bg-white dark:bg-slate-700 text-blue-600 dark:text-blue-300 shadow-xs' : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white' ?>">
                    <i class="ph-bold ph-user-plus text-sm"></i>
                    <span>1. Walk-in Registration</span>
                </button>
                <button type="button" onclick="switchTab('booking')" id="tab-btn-booking" class="flex-1 py-2 px-3 font-bold text-xs rounded-lg transition-all flex items-center justify-center gap-2 relative <?= $active_tab === 'booking' ? 'bg-white dark:bg-slate-700 text-blue-600 dark:text-blue-300 shadow-xs' : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white' ?>">
                    <i class="ph-bold ph-calendar-check text-sm"></i>
                    <span>2. Convert Pre-Booking</span>
                    <?php if (count($pending_bookings) > 0): ?>
                        <span class="w-4 h-4 rounded-full bg-blue-600 text-white text-[9px] font-black inline-flex items-center justify-center ml-1"><?= count($pending_bookings) ?></span>
                    <?php endif; ?>
                </button>
            </div>
            
            <?php if ($action_error): ?>
                <div class="bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 p-3 mb-5 rounded-xl flex items-center gap-2.5 text-xs">
                    <i class="ph-bold ph-warning-circle text-base flex-shrink-0"></i>
                    <p class="font-medium"><?= htmlspecialchars($action_error) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($action_msg): ?>
                <div class="bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 p-3 mb-5 rounded-xl flex items-center gap-2.5 text-xs">
                    <i class="ph-bold ph-check-circle text-base flex-shrink-0"></i>
                    <p class="font-medium"><?= htmlspecialchars($action_msg) ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['success'])): ?>
                <!-- Success Token Card -->
                <div class="bg-gradient-to-tr from-blue-50 to-indigo-50 dark:from-slate-800 dark:to-slate-900 border border-blue-200/80 dark:border-slate-700 p-6 rounded-2xl text-center mb-5 shadow-sm">
                    <span class="inline-block py-1 px-3 rounded-full text-[10px] font-bold uppercase tracking-wider bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 mb-2">
                        <?= isset($_GET['converted']) ? 'Pre-Booking Converted' : 'Walk-in Registered' ?>
                    </span>
                    
                    <div class="my-3">
                        <p class="text-6xl font-black font-mono tracking-tight text-blue-600 dark:text-blue-400"><?= htmlspecialchars($_GET['token']) ?></p>
                    </div>

                    <?php if (!empty($_GET['ref'])): ?>
                        <p class="text-xs text-slate-500 mb-2 font-medium">Booking Ref: <strong class="text-slate-800 dark:text-slate-200 font-mono"><?= htmlspecialchars($_GET['ref']) ?></strong></p>
                    <?php endif; ?>

                    <div class="inline-flex items-center gap-2 bg-white dark:bg-slate-800 px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-xs text-slate-700 dark:text-slate-300 font-semibold shadow-xs">
                        <i class="ph-bold ph-clock text-blue-600 dark:text-blue-400"></i>
                        <span>Estimated Wait: <strong class="text-blue-600 dark:text-blue-400"><?= (int)$_GET['wait'] ?> mins</strong></span>
                    </div>

                    <div class="mt-6">
                        <a href="checkin.php" class="btn-primary inline-flex items-center gap-2 px-5 py-2.5 text-xs font-bold">
                            <i class="ph-bold ph-plus text-sm"></i>
                            <span>Issue Next Token</span>
                        </a>
                    </div>
                </div>

            <?php else: ?>
                
                <!-- TAB 1: Walk-in Registration -->
                <div id="tab-content-walkin" class="<?= $active_tab === 'walkin' ? '' : 'hidden' ?>">
                    <form action="process_booking.php" method="POST" class="grid grid-cols-2 gap-4">
                        <div class="col-span-2 sm:col-span-1">
                            <label for="name" class="form-label">Patient Full Name</label>
                            <div class="relative">
                                <i class="ph-bold ph-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <input type="text" id="name" name="name" required class="form-input" placeholder="e.g. Ramesh Kumar">
                            </div>
                        </div>
                        
                        <div class="col-span-2 sm:col-span-1">
                            <label for="phone" class="form-label">10-Digit Mobile Number</label>
                            <div class="relative">
                                <i class="ph-bold ph-phone absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <input type="tel" id="phone" name="phone" pattern="[0-9]{10}" required class="form-input" placeholder="9876543210" title="10-digit number">
                            </div>
                        </div>
                        
                        <div class="col-span-2 sm:col-span-1">
                            <label for="age" class="form-label">Age (Years)</label>
                            <input type="number" id="age" name="age" required min="1" max="150" class="form-input !pl-3.5" placeholder="e.g. 42">
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <label for="gender" class="form-label">Gender</label>
                            <div class="relative">
                                <select id="gender" name="gender" required class="form-input !pl-3.5 appearance-none font-medium">
                                    <option value="" disabled selected>Select gender...</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                                <i class="ph-bold ph-caret-down absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-xs"></i>
                            </div>
                        </div>

                        <div class="col-span-2">
                            <label for="doctor_id" class="form-label">Department Specialist Doctor</label>
                            <div class="relative">
                                <i class="ph-bold ph-stethoscope absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <select id="doctor_id" name="doctor_id" required class="form-input appearance-none font-medium">
                                    <option value="" disabled selected>Select department specialist...</option>
                                    <?php foreach ($doctors as $doc): ?>
                                        <option value="<?= $doc['doctor_id'] ?>">
                                            <?= htmlspecialchars($doc['specialization']) ?> — <?= htmlspecialchars($doc['name']) ?> (<?= htmlspecialchars($doc['room_number']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="ph-bold ph-caret-down absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-xs"></i>
                            </div>
                        </div>

                        <div class="col-span-2 pt-2">
                            <button type="submit" class="btn-primary w-full text-xs py-3 flex justify-center items-center gap-2 font-bold">
                                <span>Generate & Print Token</span>
                                <i class="ph-bold ph-arrow-right text-xs"></i>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- TAB 2: Convert Pre-Booking to Live Token -->
                <div id="tab-content-booking" class="<?= $active_tab === 'booking' ? '' : 'hidden' ?> space-y-4">
                    
                    <!-- Search Input -->
                    <form method="POST" class="flex gap-2.5">
                        <input type="hidden" name="action" value="search_booking">
                        <div class="relative flex-1">
                            <i class="ph-bold ph-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                            <input type="text" name="search_query" required class="form-input uppercase font-bold" placeholder="Search by BK-001 or Phone">
                        </div>
                        <button type="submit" class="btn-primary px-5 py-2 text-xs font-bold flex items-center gap-1.5 whitespace-nowrap">
                            <span>Search</span>
                        </button>
                    </form>

                    <!-- Searched Booking Card (if query was matched) -->
                    <?php if ($searched_booking): ?>
                        <div class="bg-gradient-to-tr from-blue-50/70 to-indigo-50/70 dark:from-slate-800 dark:to-slate-900 border border-blue-200 dark:border-slate-700 p-4 rounded-xl shadow-xs">
                            <div class="flex justify-between items-start mb-2">
                                <div>
                                    <span class="text-[9px] font-black uppercase tracking-widest text-blue-600 dark:text-blue-400 block">Matching Reservation</span>
                                    <h4 class="text-base font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($searched_booking['patient_name']) ?></h4>
                                    <p class="text-xs text-slate-500"><?= htmlspecialchars($searched_booking['patient_phone']) ?> • <?= htmlspecialchars($searched_booking['age']) ?> yrs • <?= htmlspecialchars($searched_booking['gender']) ?></p>
                                </div>
                                <div class="text-right">
                                    <span class="text-sm font-black font-mono text-blue-600 dark:text-blue-400"><?= htmlspecialchars($searched_booking['booking_ref']) ?></span>
                                    <span class="text-xs font-bold block text-slate-700 dark:text-slate-300"><?= date('h:i A', strtotime($searched_booking['scheduled_time'])) ?></span>
                                </div>
                            </div>

                            <p class="text-xs text-slate-600 dark:text-slate-300 mb-3">
                                <strong>Consultant:</strong> <?= htmlspecialchars($searched_booking['doctor_name']) ?> (<?= htmlspecialchars($searched_booking['specialization']) ?>)
                            </p>

                            <?php 
                            $scheduled_ts = strtotime($searched_booking['scheduled_time']);
                            $is_late = (time() - $scheduled_ts) > 900;
                            ?>

                            <div class="flex gap-2">
                                <form method="POST" class="flex-1">
                                    <input type="hidden" name="action" value="convert_booking">
                                    <input type="hidden" name="token_id" value="<?= $searched_booking['token_id'] ?>">
                                    <button type="submit" class="btn-primary w-full py-2 text-xs font-bold flex items-center justify-center gap-1.5">
                                        <i class="ph-bold ph-check text-xs"></i> Check In & Promote to Queue
                                    </button>
                                </form>

                                <?php if ($is_late): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="noshow_booking">
                                        <input type="hidden" name="token_id" value="<?= $searched_booking['token_id'] ?>">
                                        <button type="submit" class="bg-amber-100 hover:bg-amber-200 text-amber-800 font-bold px-3 py-2 rounded-xl text-xs" title="Patient is > 15 mins late">
                                            No-Show
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Today's Pending Pre-Bookings List -->
                    <div>
                        <div class="flex justify-between items-center mb-2.5">
                            <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">Today's Scheduled Appointments (<?= count($pending_bookings) ?>)</span>
                        </div>

                        <?php if (count($pending_bookings) > 0): ?>
                            <div class="space-y-2 max-h-64 overflow-y-auto pr-1">
                                <?php foreach ($pending_bookings as $pb): ?>
                                    <?php 
                                    $pb_time_ts = strtotime($pb['scheduled_time']);
                                    $pb_is_late = (time() - $pb_time_ts) > 900;
                                    ?>
                                    <div class="bg-white dark:bg-slate-900 p-3 rounded-xl border border-slate-200/80 dark:border-slate-800 flex justify-between items-center gap-3 shadow-xs">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="font-bold font-mono text-xs text-blue-600 dark:text-blue-400"><?= htmlspecialchars($pb['booking_ref']) ?></span>
                                                <span class="text-xs font-bold text-slate-900 dark:text-white truncate"><?= htmlspecialchars($pb['patient_name']) ?></span>
                                            </div>
                                            <p class="text-[11px] text-slate-500 mt-0.5 truncate">
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
                                                <button type="submit" class="btn-primary py-1.5 px-3 text-[11px] font-bold flex items-center gap-1">
                                                    Check In
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-6 bg-slate-50/50 dark:bg-slate-900/40 rounded-xl border border-slate-200/80 dark:border-slate-800 text-slate-400 text-xs">
                                No pending pre-booked appointments for today
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

            <?php endif; ?>
        </div>
        
        <!-- Right Side: Recent Tokens List -->
        <div class="w-full lg:w-5/12 bg-slate-50/70 dark:bg-slate-900/70 p-5 rounded-2xl border border-slate-200/80 dark:border-slate-800 flex flex-col h-full">
            <div class="flex justify-between items-center mb-3.5">
                <h3 class="font-bold text-xs uppercase tracking-wider text-slate-700 dark:text-slate-300 flex items-center gap-2">
                    <i class="ph-bold ph-list-numbers text-blue-600 text-base"></i>
                    <span>Live Reception Feed</span>
                </h3>
                <span class="text-[10px] text-slate-400 font-semibold uppercase">Today</span>
            </div>
            
            <?php if (count($recent_tokens) > 0): ?>
                <div class="flex-1 space-y-2">
                    <?php foreach ($recent_tokens as $t): ?>
                        <div class="bg-white dark:bg-slate-900 p-3 rounded-xl border border-slate-200/80 dark:border-slate-800 flex justify-between items-center shadow-xs">
                            <div>
                                <div class="flex items-center gap-1.5">
                                    <span class="font-black font-mono text-xs text-slate-900 dark:text-white"><?= htmlspecialchars($t['token_number']) ?></span>
                                    <?php if ($t['booking_type'] === 'Pre-Booked'): ?>
                                        <span class="text-[9px] px-1.5 py-0.2 rounded bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300 font-bold uppercase tracking-wider">
                                            Pre-Booked
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <span class="text-xs font-semibold text-slate-600 dark:text-slate-300"><?= htmlspecialchars($t['patient_name']) ?></span>
                            </div>
                            <?php 
                                $badge_color = 'bg-slate-100 text-slate-600 border-slate-200';
                                if ($t['status'] === 'Waiting') $badge_color = 'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800';
                                elseif ($t['status'] === 'In-Progress') $badge_color = 'bg-blue-50 text-blue-800 border-blue-200 dark:bg-blue-950/60 dark:text-blue-300 dark:border-blue-800';
                                elseif ($t['status'] === 'Completed') $badge_color = 'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800';
                            ?>
                            <span class="text-[10px] font-bold uppercase tracking-wider px-2.5 py-0.5 rounded-full border <?= $badge_color ?>">
                                <?= htmlspecialchars($t['status']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="flex-1 flex flex-col justify-center items-center opacity-40 py-10 text-xs text-slate-500">
                    No check-ins registered yet today
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

    const activeClass = 'flex-1 py-1.5 px-3 font-semibold text-xs rounded-md transition-all flex items-center justify-center gap-1.5 bg-white dark:bg-slate-700 text-blue-600 dark:text-blue-300 shadow-xs';
    const inactiveClass = 'flex-1 py-1.5 px-3 font-semibold text-xs rounded-md transition-all flex items-center justify-center gap-1.5 text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white';

    if (tab === 'walkin') {
        walkinBtn.className = activeClass;
        bookingBtn.className = inactiveClass + ' relative';
        walkinContent.classList.remove('hidden');
        bookingContent.classList.add('hidden');
    } else {
        bookingBtn.className = activeClass + ' relative';
        walkinBtn.className = inactiveClass;
        bookingContent.classList.remove('hidden');
        walkinContent.classList.add('hidden');
    }
}
</script>

    </div> <!-- Close container from header -->
</body>
</html>
