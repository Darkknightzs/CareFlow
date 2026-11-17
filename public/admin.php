<?php
require_once '../config/db.php';
require_once '../includes/seeder.php';

$is_ajax = isset($_GET['ajax']);

if (!isset($_SESSION['user_logged_in']) || !in_array($_SESSION['user_role'], ['Admin', 'SuperAdmin'])) {
    if ($is_ajax) {
        echo "<script>window.location.href = 'login.php';</script>";
        exit;
    }
    header('Location: login.php');
    exit;
}

$is_dark = (isset($_SESSION['user_name']) && strtolower($_SESSION['user_name']) === 'dark');

// Handle Dark's Demo Seeding and Reset actions
if ($is_dark && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'clear_queue') {
        $res = clearQueueData($pdo);
        if ($res['success']) {
            $_SESSION['demo_msg'] = "Queue cleared successfully! Database reset to 0 patients for a fresh demo.";
            $_SESSION['demo_type'] = "info";
        } else {
            $_SESSION['demo_msg'] = "Error clearing queue: " . $res['error'];
            $_SESSION['demo_type'] = "error";
        }
    } elseif ($action === 'seed_full') {
        $res = seedPatientDataset($pdo);
        if ($res['success']) {
            $_SESSION['demo_msg'] = "Full dataset seeded! Loaded {$res['count']} patients (Completed: {$res['stats']['Completed']}, In-Progress: {$res['stats']['In-Progress']}, Waiting: {$res['stats']['Waiting']}, Booked: {$res['stats']['Booked']}, No-Show: {$res['stats']['No-Show']}).";
            $_SESSION['demo_type'] = "success";
        } else {
            $_SESSION['demo_msg'] = "Error seeding dataset: " . $res['error'];
            $_SESSION['demo_type'] = "error";
        }
    } elseif ($action === 'seed_time_aware') {
        $preset = $_POST['time_preset'] ?? 'now';
        $time_anchor = ($preset === 'now') ? date('H:i') : $preset;
        $res = seedPatientDataset($pdo, null, $time_anchor);
        if ($res['success']) {
            $formatted_time = date('h:i A', strtotime(date('Y-m-d') . " $time_anchor:00"));
            $_SESSION['demo_msg'] = "Time-Aware Simulation successfully injected up to {$formatted_time}! (Completed: {$res['stats']['Completed']}, Active in Cabin: {$res['stats']['In-Progress']}, Waiting in Lounge: {$res['stats']['Waiting']}, Pre-Booked: {$res['stats']['Booked']}).";
            $_SESSION['demo_type'] = "success";
        } else {
            $_SESSION['demo_msg'] = "Error running simulation: " . $res['error'];
            $_SESSION['demo_type'] = "error";
        }
    }

    header("Location: admin.php");
    exit;
}

$demo_msg = $_SESSION['demo_msg'] ?? null;
$demo_type = $_SESSION['demo_type'] ?? 'info';
unset($_SESSION['demo_msg'], $_SESSION['demo_type']);

// Fetch all queue tokens for today across ALL doctors
$stmt = $pdo->prepare("
    SELECT qt.*, p.name as patient_name, p.age as patient_age, p.gender as patient_gender, d.name as doctor_name, d.specialization
    FROM queue_tokens qt
    JOIN patients p ON qt.patient_id = p.patient_id
    JOIN doctors d ON qt.doctor_id = d.doctor_id
    WHERE (DATE(qt.arrival_time) = CURRENT_DATE OR qt.arrival_date = CURRENT_DATE)
    AND qt.token_number IS NOT NULL
    ORDER BY qt.arrival_time ASC
");
$stmt->execute();
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
        fetch('admin.php?ajax=1')
            .then(res => res.text())
            .then(html => {
                document.getElementById('dashboard-content').innerHTML = html;
            });
    }, 10000);
</script>

<div class="w-full max-w-6xl mx-auto">
    
    <!-- Admin Header -->
    <div class="card p-5 rounded-xl flex flex-col md:flex-row justify-between items-center mb-6 border border-slate-200 dark:border-slate-800 shadow-xs">
        <div class="flex items-center gap-3.5">
            <div class="w-11 h-11 rounded-lg bg-slate-900 dark:bg-slate-800 text-white flex items-center justify-center shadow-xs">
                <i class="ph-bold ph-shield-check text-xl"></i>
            </div>
            <div>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white leading-tight">Hospital Administration</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    Central Queue Monitoring • <span class="text-slate-700 dark:text-slate-300 font-medium">All OPD Units</span>
                </p>
            </div>
        </div>
        
        <div class="flex items-center gap-4 mt-3 md:mt-0">
            <a href="export.php" target="_blank" class="flex items-center gap-1.5 text-slate-700 dark:text-slate-200 font-medium text-xs bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 transition-colors">
                <i class="ph ph-download-simple text-sm"></i>
                Export CSV
            </a>
            
            <div class="w-px h-6 bg-slate-200 dark:border-slate-700"></div>
            
            <div class="text-right">
                <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Clock</p>
                <p class="text-sm font-bold font-mono text-slate-800 dark:text-slate-200" id="live-clock"><?= date('h:i A') ?></p>
            </div>
        </div>
        
        <script>
            if (!window.clockIntervalSet) {
                setInterval(() => {
                    const clock = document.getElementById('live-clock');
                    if(clock) clock.innerText = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                }, 1000);
                window.clockIntervalSet = true;
            }
        </script>
    </div>

    <!-- Alert / Feedback Banner for Demo Actions -->
    <?php if ($demo_msg): ?>
        <?php 
        $alert_bg = match($demo_type) {
            'success' => 'bg-emerald-50 dark:bg-emerald-950/50 border-emerald-300 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200',
            'error' => 'bg-rose-50 dark:bg-rose-950/50 border-rose-300 dark:border-rose-800 text-rose-800 dark:text-rose-200',
            default => 'bg-blue-50 dark:bg-blue-950/50 border-blue-300 dark:border-blue-800 text-blue-800 dark:text-blue-200'
        };
        $alert_icon = match($demo_type) {
            'success' => 'ph-check-circle',
            'error' => 'ph-x-circle',
            default => 'ph-info'
        };
        ?>
        <div class="mb-6 p-4 rounded-xl border <?= $alert_bg ?> flex items-center justify-between shadow-sm animate-fade-in">
            <div class="flex items-center gap-3">
                <i class="ph-bold <?= $alert_icon ?> text-xl shrink-0"></i>
                <p class="text-xs sm:text-sm font-semibold"><?= htmlspecialchars($demo_msg) ?></p>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-current opacity-70 hover:opacity-100 p-1">
                <i class="ph-bold ph-x text-sm"></i>
            </button>
        </div>
    <?php endif; ?>

    <?php if ($is_dark): ?>
    <!-- Dark's Exclusive Demo Simulation Control Studio -->
    <div class="mb-7 rounded-2xl bg-gradient-to-br from-[#0c1322] via-[#0f172a] to-[#1e1b4b] border border-indigo-500/40 p-6 shadow-2xl relative overflow-hidden text-white">
        
        <!-- Glow accent in background -->
        <div class="absolute -right-20 -top-20 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <!-- Studio Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 pb-5 border-b border-indigo-900/60 relative z-10">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-blue-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30">
                    <i class="ph-bold ph-sliders-horizontal text-xl"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-base sm:text-lg font-black tracking-tight font-display text-white">Dark's Demo Simulation Studio</h2>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-indigo-500/20 text-indigo-300 border border-indigo-400/30">
                            SuperAdmin Access
                        </span>
                    </div>
                    <p class="text-xs text-slate-300 mt-0.5">
                        Interactive clinical simulation injector &mdash; seed realistic patient timeline or reset live queue on demand.
                    </p>
                </div>
            </div>

            <!-- Fast Reset / Clear Action -->
            <form method="POST" onsubmit="return confirm('Reset all active tokens and demo patients for today? (Staff logins and doctors are preserved)');" class="shrink-0">
                <input type="hidden" name="action" value="clear_queue">
                <button type="submit" class="px-3.5 py-2 rounded-xl bg-rose-950/60 hover:bg-rose-900 text-rose-300 hover:text-white border border-rose-700/60 text-xs font-bold transition-all shadow-sm flex items-center gap-1.5 group">
                    <i class="ph-bold ph-trash text-sm group-hover:scale-110 transition-transform"></i>
                    <span>Clear &amp; Reset Queue</span>
                </button>
            </form>
        </div>

        <!-- Action Grid: Smart Time Seeder & Full Dataset -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 mt-5 relative z-10">
            
            <!-- Option 1: Smart Time-Aware Seeding (Anchor to Current Clock or Specific Hour) -->
            <div class="lg:col-span-8 bg-slate-900/80 rounded-xl p-4.5 border border-indigo-900/50 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2">
                            <i class="ph-bold ph-clock-countdown text-indigo-400 text-base"></i>
                            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-200">Smart Time-Aware Simulation</h4>
                        </div>
                        <span class="text-[11px] text-indigo-400 font-medium">Auto-anchors In-Progress &amp; Waiting to selected hour</span>
                    </div>
                    <p class="text-xs text-slate-400 mb-3.5 leading-relaxed">
                        Seeds patients with early-morning pre-opening surge (08:45 AM). Patients before the anchor are marked <strong class="text-emerald-400">Completed</strong>, the current turn is set <strong class="text-cyan-400">In-Progress</strong>, and future patients wait in the lounge or pre-booked queue.
                    </p>
                </div>

                <form method="POST" class="flex flex-col sm:flex-row gap-2.5 items-stretch sm:items-center">
                    <input type="hidden" name="action" value="seed_time_aware">
                    
                    <div class="relative flex-1">
                        <select name="time_preset" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-xs text-slate-100 font-semibold focus:outline-none focus:border-indigo-500 appearance-none pr-8 cursor-pointer">
                            <option value="now" selected>Current Real Clock (<?= date('h:i A') ?>) &bull; Dynamic Live</option>
                            <option value="08:45">08:45 AM &bull; Pre-Clinic Crowd Waiting Surge</option>
                            <option value="09:30">09:30 AM &bull; Morning Peak (First turns serving)</option>
                            <option value="11:00">11:00 AM &bull; Mid-Day Showcase (19 Completed, 2 In-Progress)</option>
                            <option value="12:30">12:30 PM &bull; Morning Shift Wrap-up</option>
                            <option value="17:15">05:15 PM &bull; Evening OPD Session (Active Now)</option>
                            <option value="19:00">07:00 PM &bull; Evening Peak Influx</option>
                        </select>
                        <i class="ph-bold ph-caret-down text-slate-400 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-xs"></i>
                    </div>

                    <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white font-bold text-xs transition-all shadow-md shadow-indigo-600/30 flex items-center justify-center gap-1.5 shrink-0">
                        <i class="ph-bold ph-lightning text-sm"></i>
                        <span>Inject Timeline</span>
                    </button>
                </form>
            </div>

            <!-- Option 2: Full Dataset Injector -->
            <div class="lg:col-span-4 bg-slate-900/80 rounded-xl p-4.5 border border-indigo-900/50 flex flex-col justify-between">
                <div>
                    <div class="flex items-center gap-2 mb-2">
                        <i class="ph-bold ph-database text-blue-400 text-base"></i>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-slate-200">Full 45-Patient Dataset</h4>
                    </div>
                    <p class="text-xs text-slate-400 mb-3.5 leading-relaxed">
                        Seeds all 45 patients from the clinical CSV dataset (<code class="text-blue-300">careflow_patient_dataset.csv</code>) across all 3 departments.
                    </p>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="seed_full">
                    <button type="submit" class="w-full px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 active:bg-blue-700 text-white font-bold text-xs transition-all shadow-md shadow-blue-600/30 flex items-center justify-center gap-1.5">
                        <i class="ph-bold ph-cloud-arrow-up text-sm"></i>
                        <span>Seed Full Dataset</span>
                    </button>
                </form>
            </div>

        </div>

    </div>
    <?php endif; ?>

    <div id="dashboard-content">
<?php endif; ?>
    
    <!-- Stats Row -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="card p-4 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs">
            <p class="text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Total Registered</p>
            <p class="text-2xl font-bold font-mono text-slate-900 dark:text-white mt-1"><?= $total_patients ?></p>
        </div>
        <div class="card p-4 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs">
            <p class="text-[10px] font-semibold text-amber-600 dark:text-amber-400 uppercase tracking-wider">Currently Waiting</p>
            <p class="text-2xl font-bold font-mono text-amber-600 dark:text-amber-400 mt-1"><?= $waiting ?></p>
        </div>
        <div class="card p-4 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs">
            <p class="text-[10px] font-semibold text-blue-600 dark:text-blue-400 uppercase tracking-wider">In Consultation</p>
            <p class="text-2xl font-bold font-mono text-blue-600 dark:text-blue-400 mt-1"><?= $in_progress ?></p>
        </div>
        <div class="card p-4 rounded-xl border border-slate-200 dark:border-slate-800 shadow-xs">
            <p class="text-[10px] font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">Completed Today</p>
            <p class="text-2xl font-bold font-mono text-emerald-600 dark:text-emerald-400 mt-1"><?= $completed ?></p>
        </div>
    </div>

    <!-- Master Table -->
    <div class="card rounded-xl overflow-hidden border border-slate-200 dark:border-slate-800 shadow-xs mb-6">
        <div class="px-5 py-3.5 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center bg-slate-50/70 dark:bg-slate-900/60">
            <h2 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                <span>Master OPD Queue</span>
            </h2>
            <div class="flex items-center gap-1.5 text-[11px] font-medium text-slate-500">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                Auto-Updating
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/40 text-slate-500 text-[10px] uppercase tracking-wider font-semibold border-b border-slate-200 dark:border-slate-800">
                        <th class="py-2.5 px-5">Token</th>
                        <th class="py-2.5 px-5">Patient Details</th>
                        <th class="py-2.5 px-5">Department & Doctor</th>
                        <th class="py-2.5 px-5">Status</th>
                        <th class="py-2.5 px-5 text-right">Arrival Time</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60">
                    <?php foreach ($tokens as $token): ?>
                        <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors">
                            <td class="py-3 px-5">
                                <span class="font-bold font-mono text-sm text-slate-900 dark:text-white">
                                    <?= htmlspecialchars($token['token_number'] ?? $token['booking_ref'] ?? 'N/A') ?>
                                </span>
                            </td>
                            
                            <td class="py-3 px-5">
                                <p class="text-slate-900 dark:text-slate-100 font-semibold"><?= htmlspecialchars($token['patient_name']) ?></p>
                                <p class="text-slate-500 dark:text-slate-400 text-[11px]">
                                    <?= htmlspecialchars($token['patient_age']) ?>y • <?= htmlspecialchars($token['patient_gender'][0]) ?>
                                </p>
                            </td>
                            
                            <td class="py-3 px-5">
                                <span class="text-slate-800 dark:text-slate-200 font-medium"><?= htmlspecialchars($token['specialization']) ?></span>
                                <span class="text-slate-400 text-[11px] block"><?= htmlspecialchars($token['doctor_name']) ?></span>
                            </td>
                            
                            <td class="py-3 px-5">
                                <?php if ($token['status'] === 'Waiting'): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                                        Waiting
                                    </span>
                                <?php elseif ($token['status'] === 'In-Progress'): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                        Active
                                    </span>
                                <?php elseif ($token['status'] === 'Completed'): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                                        Completed
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                                        <?= htmlspecialchars($token['status']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="py-3 px-5 text-right font-mono text-[11px] text-slate-500">
                                <?= date('h:i A', strtotime($token['arrival_time'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (count($tokens) === 0): ?>
                        <tr>
                            <td colspan="5" class="py-8 text-center text-xs text-slate-400">
                                No records found for today
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