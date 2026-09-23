<?php
require_once '../config/db.php';
require_once '../includes/functions.php';

$is_ajax = isset($_GET['ajax']);

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'Doctor') {
    if ($is_ajax) {
        // Return a special JS script to force the parent page to redirect
        echo "<script>window.location.href = 'login.php';</script>";
        exit;
    }
    header('Location: login.php');
    exit;
}

$doctor_id = $_SESSION['doctor_id'] ?? 0;

// Handle doctor actions (Finish, Absent, Cancel) with immediate auto-promotion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $token_id = isset($_POST['token_id']) ? (int)$_POST['token_id'] : 0;

    if ($action === 'finish' || $action === 'complete_and_next' || $action === 'complete_only') {
        if ($token_id > 0) {
            $stmt = $pdo->prepare("UPDATE queue_tokens SET status = 'Completed', service_end_time = ? WHERE token_id = ? AND doctor_id = ?");
            $stmt->execute([date('Y-m-d H:i:s'), $token_id, $doctor_id]);
        }
        autoPromoteNextPatient($pdo, $doctor_id);
    } elseif ($action === 'absent' || $action === 'noshow_and_next' || $action === 'noshow') {
        if ($token_id > 0) {
            $stmt = $pdo->prepare("UPDATE queue_tokens SET status = 'No-Show' WHERE token_id = ? AND doctor_id = ?");
            $stmt->execute([$token_id, $doctor_id]);
        }
        autoPromoteNextPatient($pdo, $doctor_id);
    } elseif ($action === 'cancel') {
        if ($token_id > 0) {
            $stmt = $pdo->prepare("UPDATE queue_tokens SET status = 'Cancelled' WHERE token_id = ? AND doctor_id = ? AND status = 'Waiting'");
            $stmt->execute([$token_id, $doctor_id]);
        }
        autoPromoteNextPatient($pdo, $doctor_id);
    }
    header("Location: dashboard.php");
    exit;
}

// Self-healing auto-promotion: ensures first patient or fresh session is promoted automatically
autoPromoteNextPatient($pdo, $doctor_id);

// Fetch doctor details
$doc_stmt = $pdo->prepare("SELECT name, specialization, room_number FROM doctors WHERE doctor_id = ?");
$doc_stmt->execute([$doctor_id]);
$doctor_info = $doc_stmt->fetch();

$specialization = $doctor_info ? $doctor_info['specialization'] : 'Specialist Department';
$room_number = $doctor_info ? $doctor_info['room_number'] : 'Room TBD';
$doc_name = $doctor_info ? $doctor_info['name'] : ($_SESSION['user_name'] ?? 'Doctor');

// Fetch all checked-in queue tokens for today for THIS doctor only
// Pre-booked entries only join the doctor's live queue once converted at reception (token_number IS NOT NULL)
// Ordered by effective time: scheduled_time for Pre-Booked, arrival_time for Walk-in
$stmt = $pdo->prepare("
    SELECT qt.*, p.name as patient_name, p.phone as patient_phone, p.age as patient_age, p.gender as patient_gender, d.name as doctor_name, d.specialization
    FROM queue_tokens qt
    JOIN patients p ON qt.patient_id = p.patient_id
    JOIN doctors d ON qt.doctor_id = d.doctor_id
    WHERE (DATE(qt.arrival_time) = CURRENT_DATE OR qt.arrival_date = CURRENT_DATE)
    AND qt.doctor_id = ?
    AND qt.token_number IS NOT NULL
    ORDER BY 
        CASE 
            WHEN qt.status = 'In-Progress' THEN 1
            WHEN qt.status = 'Waiting' THEN 2
            WHEN qt.status = 'Completed' THEN 3
            ELSE 4
        END ASC,
        COALESCE(qt.scheduled_time, qt.arrival_time) ASC
");
$stmt->execute([$doctor_id]);
$tokens = $stmt->fetchAll();

// Statistics for Dashboard
$total_patients = count($tokens);
$waiting = count(array_filter($tokens, fn($t) => $t['status'] === 'Waiting'));
$in_progress = count(array_filter($tokens, fn($t) => $t['status'] === 'In-Progress'));
$completed = count(array_filter($tokens, fn($t) => $t['status'] === 'Completed'));

if (!$is_ajax):
    include '../includes/header.php';
?>

<!-- Auto refresh via AJAX to prevent full page flash -->
<script>
    setInterval(() => {
        fetch('dashboard.php?ajax=1')
            .then(res => res.text())
            .then(html => {
                document.getElementById('dashboard-content').innerHTML = html;
            });
    }, 10000);
</script>

<div class="w-full max-w-7xl mx-auto">
    
    <!-- Doctor Header -->
    <div class="glass-card p-6 rounded-[2rem] flex flex-col md:flex-row justify-between items-center mb-8 border border-white/60 dark:border-slate-700/50">
        <div class="flex items-center gap-4">
            <div class="w-16 h-16 rounded-2xl bg-brand-600 text-white flex items-center justify-center shadow-lg shadow-brand-500/30">
                <i class="ph ph-stethoscope text-3xl"></i>
            </div>
            <div>
                <h2 class="text-2xl font-black text-slate-800 dark:text-white leading-tight"><?= htmlspecialchars($doc_name) ?></h2>
                <p class="text-brand-600 dark:text-brand-400 font-semibold text-sm">
                    <?= htmlspecialchars($specialization) ?> • <span class="text-slate-500 dark:text-slate-400"><?= htmlspecialchars($room_number) ?></span>
                </p>
            </div>
        </div>
        
        <div class="flex items-center gap-4 sm:gap-6 mt-4 md:mt-0 bg-white/50 dark:bg-slate-800/50 px-4 sm:px-6 py-3 rounded-2xl border border-white/50 dark:border-slate-700">
            <a href="export.php" target="_blank" class="flex items-center gap-2 text-slate-700 dark:text-slate-200 font-bold text-sm bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 px-4 py-2 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-600 transition-colors shadow-sm whitespace-nowrap">
                <i class="ph ph-download-simple text-lg"></i>
                Export CSV
            </a>
            
            <div class="w-px h-8 bg-slate-300 dark:bg-slate-600 hidden sm:block"></div>
            
            <div class="text-right hidden sm:block">
                <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Current Time</p>
                <p class="text-lg font-bold text-slate-800 dark:text-white" id="live-clock"><?= date('h:i A') ?></p>
            </div>
            <div class="w-px h-8 bg-slate-300 dark:bg-slate-600"></div>
            <div class="text-left">
                <p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest">Date</p>
                <p class="text-lg font-bold text-slate-800 dark:text-white"><?= date('M d, Y') ?></p>
            </div>
        </div>
        
        <script>
            if (!window.clockIntervalSet) {
                setInterval(() => {
                    const clock = document.getElementById('live-clock');
                    if (clock) clock.innerText = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                }, 1000);
                window.clockIntervalSet = true;
            }
        </script>
    </div>

    <div id="dashboard-content">
<?php endif; ?>
    
    <?php
    $current_patient = null;
    foreach ($tokens as $t) {
        if ($t['status'] === 'In-Progress') {
            $current_patient = $t;
            break;
        }
    }
    ?>

    <!-- Active Consultation Action Banner -->
    <div class="glass-card p-6 rounded-[2rem] mb-8 border border-white/60 dark:border-slate-700/50 shadow-lg">
        <?php if ($current_patient): ?>
            <div class="flex flex-col lg:flex-row items-center justify-between gap-6">
                <div class="flex items-center gap-5">
                    <div class="min-w-[5.5rem] px-3 py-3 rounded-2xl bg-brand-600 text-white flex flex-col items-center justify-center shadow-md shadow-brand-500/30 flex-shrink-0">
                        <span class="text-[9px] font-black uppercase tracking-widest text-brand-200">TOKEN</span>
                        <span class="text-xl font-black tracking-tight whitespace-nowrap"><?= htmlspecialchars($current_patient['token_number']) ?></span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="inline-flex items-center gap-1.5 px-3 py-0.5 rounded-full bg-brand-100 dark:bg-brand-950/80 text-brand-700 dark:text-brand-300 text-[10px] font-extrabold uppercase tracking-wider">
                                <span class="w-2 h-2 rounded-full bg-brand-500 animate-pulse"></span> In Consultation
                            </span>
                            <?php if (($current_patient['booking_type'] ?? '') === 'Pre-Booked'): ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-indigo-100 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300 text-[10px] font-extrabold uppercase tracking-wider border border-indigo-200 dark:border-indigo-800">
                                    <i class="ph ph-calendar-check"></i> Slot: <?= !empty($current_patient['scheduled_time']) ? date('h:i A', strtotime($current_patient['scheduled_time'])) : '' ?>
                                </span>
                            <?php endif; ?>
                            <span class="text-xs text-slate-400 font-medium">Started: <?= date('h:i A', strtotime($current_patient['service_start_time'] ?? 'now')) ?></span>
                        </div>
                        <h3 class="text-2xl font-black text-slate-800 dark:text-white leading-tight"><?= htmlspecialchars($current_patient['patient_name']) ?></h3>
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 mt-1">
                            <?= htmlspecialchars($current_patient['patient_age']) ?> yrs • <?= htmlspecialchars($current_patient['patient_gender']) ?>
                            <?php if (!empty($current_patient['patient_phone'])): ?>
                                • <span class="text-slate-400 dark:text-slate-500 font-normal">Phone:</span> <?= htmlspecialchars($current_patient['patient_phone']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <!-- 1-Click Action Buttons -->
                <form method="POST" class="flex flex-wrap items-center gap-3 w-full lg:w-auto justify-end">
                    <input type="hidden" name="token_id" value="<?= $current_patient['token_id'] ?>">
                    
                    <!-- Primary: Finish (Auto-Promotes Next) -->
                    <button type="submit" name="action" value="finish" class="flex-1 sm:flex-initial btn-primary !py-3 !px-6 text-sm font-bold flex items-center justify-center gap-2 shadow-lg shadow-brand-500/30">
                        <i class="ph ph-check-circle text-lg font-bold"></i>
                        <span>Finish</span>
                    </button>

                    <!-- Secondary: Absent / No-Show (Auto-Promotes Next) -->
                    <button type="submit" name="action" value="absent" class="bg-amber-100 hover:bg-amber-200 dark:bg-amber-950/60 dark:hover:bg-amber-900/80 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-800 font-bold text-sm py-3 px-5 rounded-2xl transition-all flex items-center gap-2" title="Mark current patient as absent and auto-promote next waiting patient">
                        <i class="ph ph-user-minus text-base"></i>
                        <span>Absent</span>
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 py-2 text-center sm:text-left">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400">
                        <i class="ph ph-user-check text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-slate-800 dark:text-white">Waiting for Patients</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400"><?= $waiting ?> patient(s) waiting in queue • Auto-promotes on check-in</p>
                    </div>
                </div>
                
                <span class="text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest bg-slate-100 dark:bg-slate-800 px-4 py-2 rounded-xl">
                    <?= $waiting > 0 ? 'Queue Active' : 'No Patients Waiting' ?>
                </span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Single Focused Stat Card: Patients in Queue -->
    <div class="mb-8">
        <div class="glass-card p-6 rounded-[2rem] flex flex-col sm:flex-row items-center justify-between gap-6 border border-white/60 dark:border-slate-700/50 shadow-md">
            <div class="flex items-center gap-5">
                <div class="w-16 h-16 rounded-2xl bg-amber-500/10 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400 flex items-center justify-center border border-amber-500/20 shadow-inner">
                    <i class="ph ph-hourglass-high text-3xl"></i>
                </div>
                <div>
                    <span class="text-[11px] font-black uppercase tracking-widest text-slate-400 dark:text-slate-500 block mb-0.5">Patients In Queue</span>
                    <div class="flex items-baseline gap-3">
                        <span class="text-4xl font-black text-slate-800 dark:text-white tracking-tight"><?= $waiting ?></span>
                        <span class="text-sm font-bold text-slate-500 dark:text-slate-400">waiting for consultation</span>
                    </div>
                </div>
            </div>
            
            <div class="flex items-center gap-3 bg-slate-100/80 dark:bg-slate-800/80 px-5 py-3 rounded-2xl border border-slate-200/60 dark:border-slate-700/60">
                <span class="w-2.5 h-2.5 rounded-full <?= $waiting > 0 ? 'bg-amber-500 animate-ping' : 'bg-slate-400' ?>"></span>
                <span class="text-xs font-black uppercase tracking-wider text-slate-700 dark:text-slate-300">
                    <?= $waiting > 0 ? 'Queue Active' : 'No Pending Patients' ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Main Table -->
    <div class="glass-card rounded-[2rem] overflow-hidden border border-white/40 dark:border-slate-700/50 shadow-xl">
        <div class="px-8 py-5 border-b border-slate-200/50 dark:border-slate-700/50 flex justify-between items-center bg-white/40 dark:bg-slate-800/40">
            <h2 class="text-xl font-extrabold text-slate-800 dark:text-white flex items-center gap-2">
                <i class="ph ph-list-dashes text-brand-500"></i> Live Queue
            </h2>
            <div class="flex items-center gap-2 text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider bg-slate-100/50 dark:bg-slate-800/50 px-3 py-1.5 rounded-full">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                Auto-Updating
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50/50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 text-[10px] uppercase tracking-widest font-bold">
                        <th class="py-4 px-8">Token</th>
                        <th class="py-4 px-8">Patient Info</th>
                        <th class="py-4 px-8">Department</th>
                        <th class="py-4 px-8">Status</th>
                        <th class="py-4 px-8 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100/50 dark:divide-slate-700/50 bg-white/20 dark:bg-slate-900/20">
                    <?php foreach ($tokens as $token): ?>
                        <tr class="hover:bg-white/60 dark:hover:bg-slate-800/60 transition-colors duration-200 group">
                            
                            <!-- Token No & Type Badge -->
                            <td class="py-4 px-8">
                                <div class="flex flex-col gap-1 items-start">
                                    <span class="font-black text-slate-800 dark:text-white text-xl leading-none">
                                        <?= htmlspecialchars($token['token_number']) ?>
                                    </span>
                                    <?php if ($token['booking_type'] === 'Pre-Booked'): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-indigo-100 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300 text-[9px] font-black uppercase tracking-wider border border-indigo-200 dark:border-indigo-800/80" title="Pre-Booked for <?= !empty($token['scheduled_time']) ? date('h:i A', strtotime($token['scheduled_time'])) : '' ?>">
                                            <i class="ph ph-calendar-check text-[10px]"></i> Booked <?= !empty($token['scheduled_time']) ? date('h:i A', strtotime($token['scheduled_time'])) : '' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-500 text-[9px] font-bold uppercase tracking-wider">
                                            Walk-in
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- Patient Info -->
                            <td class="py-4 px-8">
                                <p class="text-slate-800 dark:text-slate-200 font-bold text-sm"><?= htmlspecialchars($token['patient_name']) ?></p>
                                <p class="text-slate-500 dark:text-slate-400 text-xs font-medium mt-0.5">
                                    <?= htmlspecialchars($token['patient_age']) ?>y • <?= htmlspecialchars($token['patient_gender'][0]) ?> • Arr: <?= date('h:i A', strtotime($token['arrival_time'])) ?>
                                </p>
                            </td>
                            
                            <!-- Department -->
                            <td class="py-4 px-8">
                                <div class="inline-flex items-center gap-2">
                                    <i class="ph ph-stethoscope text-brand-500"></i>
                                    <span class="text-slate-600 dark:text-slate-300 font-medium text-sm"><?= htmlspecialchars($token['specialization']) ?></span>
                                </div>
                            </td>
                            
                            <!-- Status -->
                            <td class="py-4 px-8">
                                <?php if ($token['status'] === 'Waiting'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md bg-yellow-100 dark:bg-yellow-900/60 text-yellow-700 dark:text-yellow-400 text-[10px] font-bold uppercase tracking-wider border border-yellow-200 dark:border-yellow-700/50">
                                        <i class="ph-fill ph-clock animate-pulse"></i> Waiting
                                    </span>
                                <?php elseif ($token['status'] === 'In-Progress'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md bg-brand-100 dark:bg-brand-900/60 text-brand-700 dark:text-brand-400 text-[10px] font-bold uppercase tracking-wider border border-brand-200 dark:border-brand-700/50">
                                        <i class="ph-fill ph-spinner-gap animate-spin"></i> In Progress
                                    </span>
                                <?php elseif ($token['status'] === 'Completed'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md bg-emerald-100 dark:bg-emerald-900/60 text-emerald-700 dark:text-emerald-400 text-[10px] font-bold uppercase tracking-wider border border-emerald-200 dark:border-emerald-700/50">
                                        <i class="ph-fill ph-check-circle"></i> Completed
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 text-[10px] font-bold uppercase tracking-wider border border-slate-200 dark:border-slate-700">
                                        <i class="ph-fill ph-x-circle"></i> <?= htmlspecialchars($token['status']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Actions / Queue Position -->
                            <td class="py-4 px-8 text-right">
                                <?php if ($token['status'] === 'Waiting'): ?>
                                    <span class="text-xs font-semibold text-slate-400 dark:text-slate-500">In Queue</span>
                                <?php elseif ($token['status'] === 'In-Progress'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-brand-50 dark:bg-brand-950/60 text-brand-600 dark:text-brand-400 text-xs font-bold border border-brand-200 dark:border-brand-800">
                                        <span class="w-1.5 h-1.5 rounded-full bg-brand-500 animate-ping"></span> Active Now
                                    </span>
                                <?php else: ?>
                                    <span class="text-[11px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider">Done</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (count($tokens) === 0): ?>
                        <tr>
                            <td colspan="5" class="py-12 text-center">
                                <div class="inline-flex flex-col items-center justify-center opacity-50">
                                    <i class="ph ph-ghost text-5xl mb-2 text-slate-400"></i>
                                    <p class="text-slate-500 dark:text-slate-400 text-sm font-bold uppercase tracking-widest">No patients today</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (!$is_ajax): ?>
    </div>
</div>
</body>
</html>
<?php endif; ?>
