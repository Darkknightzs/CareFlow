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

<div class="w-full max-w-6xl mx-auto">
    
    <!-- Doctor Header Card -->
    <div class="card p-6 rounded-2xl flex flex-col md:flex-row justify-between items-center mb-6 shadow-sm border border-slate-200/80 dark:border-slate-800">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white flex items-center justify-center shadow-lg shadow-blue-500/25 flex-shrink-0">
                <i class="ph-bold ph-stethoscope text-2xl"></i>
            </div>
            <div>
                <div class="flex items-center gap-2 mb-0.5">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Cabin Active
                    </span>
                    <span class="text-xs text-slate-400 font-medium">•</span>
                    <span class="text-xs font-semibold text-slate-500 dark:text-slate-400"><?= htmlspecialchars($specialization) ?></span>
                </div>
                <h2 class="text-xl font-bold font-display text-slate-900 dark:text-white leading-tight"><?= htmlspecialchars($doc_name) ?></h2>
                <p class="text-xs text-blue-600 dark:text-blue-400 font-semibold mt-0.5">
                    Assigned: <span class="text-slate-800 dark:text-slate-200 font-bold"><?= htmlspecialchars($room_number) ?></span>
                </p>
            </div>
        </div>
        
        <div class="flex items-center mt-4 md:mt-0 bg-slate-50 dark:bg-slate-800/60 px-4 py-2 rounded-xl border border-slate-200/60 dark:border-slate-700">
            <div class="text-right">
                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">Clinic Clock</p>
                <p class="text-sm font-bold font-mono text-slate-800 dark:text-slate-200" id="live-clock"><?= date('h:i A') ?></p>
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

    <!-- Active Patient Banner (Clinical Priority Card) -->
    <div class="card p-6 rounded-2xl mb-6 shadow-md border border-slate-200/90 dark:border-slate-800 relative overflow-hidden">
        <div class="absolute top-0 left-0 w-1.5 h-full bg-blue-600"></div>
        
        <?php if ($current_patient): ?>
            <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-6">
                
                <!-- Left: Patient Demographics & Token -->
                <div class="flex items-center gap-5">
                    <div class="px-4 py-3 rounded-xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white flex flex-col items-center justify-center flex-shrink-0 shadow-md shadow-blue-500/25">
                        <span class="text-[9px] font-black uppercase tracking-widest text-blue-200">NOW SERVING</span>
                        <span class="text-2xl font-black font-mono tracking-tight"><?= htmlspecialchars($current_patient['token_number']) ?></span>
                    </div>

                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-blue-100/90 text-blue-800 dark:bg-blue-950 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                <span class="w-1.5 h-1.5 rounded-full bg-blue-600 animate-ping"></span> In Consultation
                            </span>
                            <?php if (($current_patient['booking_type'] ?? '') === 'Pre-Booked'): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                                    <i class="ph-bold ph-calendar-check"></i> Slot: <?= !empty($current_patient['scheduled_time']) ? date('h:i A', strtotime($current_patient['scheduled_time'])) : '' ?>
                                </span>
                            <?php endif; ?>
                            <span class="text-xs text-slate-400 font-medium">Started: <?= date('h:i A', strtotime($current_patient['service_start_time'] ?? 'now')) ?></span>
                        </div>
                        <h3 class="text-xl font-bold font-display text-slate-900 dark:text-white leading-tight"><?= htmlspecialchars($current_patient['patient_name']) ?></h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 font-medium mt-0.5">
                            <?= htmlspecialchars($current_patient['patient_age']) ?> years old • <?= htmlspecialchars($current_patient['patient_gender']) ?>
                            <?php if (!empty($current_patient['patient_phone'])): ?>
                                • <span class="font-mono text-slate-600 dark:text-slate-300"><?= htmlspecialchars($current_patient['patient_phone']) ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <!-- Right: Action Buttons -->
                <form method="POST" class="flex items-center gap-3 w-full lg:w-auto justify-end">
                    <input type="hidden" name="token_id" value="<?= $current_patient['token_id'] ?>">
                    
                    <!-- Finish & Auto Promote -->
                    <button type="submit" name="action" value="finish" class="btn-primary py-2.5 px-6 text-xs font-bold flex items-center justify-center gap-2 shadow-md">
                        <i class="ph-bold ph-check-circle text-base"></i>
                        <span>Finish & Call Next</span>
                    </button>

                    <!-- Absent / No Show -->
                    <button type="submit" name="action" value="absent" class="bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/60 dark:hover:bg-amber-900/80 text-amber-800 dark:text-amber-300 border border-amber-300 dark:border-amber-800 font-bold text-xs py-2.5 px-4 rounded-xl transition-all flex items-center gap-1.5" title="Mark patient absent and auto-promote next">
                        <i class="ph-bold ph-user-minus text-sm"></i>
                        <span>No-Show</span>
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="flex items-center justify-between py-2 text-slate-600 dark:text-slate-300 text-xs">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-blue-50 dark:bg-slate-800 flex items-center justify-center text-blue-600 dark:text-blue-400">
                        <i class="ph-bold ph-door text-lg"></i>
                    </div>
                    <div>
                        <p class="font-bold text-slate-800 dark:text-white text-sm">Doctor Cabin Ready</p>
                        <p class="text-slate-500 text-xs">No active consultation. Next checked-in patient will automatically be promoted to your screen.</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold">
                    <span class="w-2 h-2 rounded-full <?= $waiting > 0 ? 'bg-amber-500 animate-ping' : 'bg-slate-400' ?>"></span>
                    <span><?= $waiting ?> waiting outside</span>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Live Queue Table -->
    <div class="card rounded-2xl overflow-hidden border border-slate-200/80 dark:border-slate-800 shadow-sm mb-6">
        <div class="px-6 py-4 border-b border-slate-200/80 dark:border-slate-800 flex justify-between items-center bg-slate-50/60 dark:bg-slate-900/60">
            <div class="flex items-center gap-2.5">
                <h2 class="text-sm font-bold font-display text-slate-900 dark:text-white uppercase tracking-wider">Today's Department Queue</h2>
                <span class="px-2.5 py-0.5 rounded-full bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300 text-xs font-bold"><?= $total_patients ?> total</span>
            </div>
            <div class="flex items-center gap-2 text-xs font-semibold text-slate-500">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span>Auto-Updating</span>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/50 text-slate-500 text-[10px] uppercase tracking-wider font-bold border-b border-slate-200 dark:border-slate-800">
                        <th class="py-3 px-6">Token</th>
                        <th class="py-3 px-6">Patient Info</th>
                        <th class="py-3 px-6">Booking Type</th>
                        <th class="py-3 px-6">Turn Status</th>
                        <th class="py-3 px-6 text-right">Arrival Time</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800/80">
                    <?php foreach ($tokens as $token): ?>
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/60 transition-colors">
                            <td class="py-3.5 px-6">
                                <span class="font-black font-mono text-base text-slate-900 dark:text-white">
                                    <?= htmlspecialchars($token['token_number']) ?>
                                </span>
                            </td>
                            
                            <td class="py-3.5 px-6">
                                <p class="text-slate-900 dark:text-white font-bold text-sm"><?= htmlspecialchars($token['patient_name']) ?></p>
                                <p class="text-slate-500 dark:text-slate-400 text-[11px] font-medium">
                                    <?= htmlspecialchars($token['patient_age']) ?> yrs • <?= htmlspecialchars($token['patient_gender'][0]) ?>
                                </p>
                            </td>
                            
                            <td class="py-3.5 px-6">
                                <?php if ($token['booking_type'] === 'Pre-Booked'): ?>
                                    <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-blue-600 dark:text-blue-400">
                                        <i class="ph-bold ph-calendar text-xs"></i> <?= !empty($token['scheduled_time']) ? date('h:i A', strtotime($token['scheduled_time'])) : 'Booked' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-[11px] text-slate-500 font-medium">Spot Walk-in</span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="py-3.5 px-6">
                                <?php if ($token['status'] === 'Waiting'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-100/80 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                                        <i class="ph-bold ph-clock"></i> Waiting
                                    </span>
                                <?php elseif ($token['status'] === 'In-Progress'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300 border border-blue-200 dark:border-blue-800 animate-pulse">
                                        <i class="ph-bold ph-pulse"></i> In Progress
                                    </span>
                                <?php elseif ($token['status'] === 'Completed'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-100/80 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                                        <i class="ph-bold ph-check"></i> Completed
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-medium bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                                        <?= htmlspecialchars($token['status']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="py-3.5 px-6 text-right font-mono text-[11px] text-slate-500">
                                <?= date('h:i A', strtotime($token['arrival_time'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (count($tokens) === 0): ?>
                        <tr>
                            <td colspan="5" class="py-10 text-center text-xs text-slate-400">
                                No consultations queued for today
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
