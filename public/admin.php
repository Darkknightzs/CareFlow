<?php
require_once '../config/db.php';

$is_ajax = isset($_GET['ajax']);

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'Admin') {
    if ($is_ajax) {
        echo "<script>window.location.href = 'login.php';</script>";
        exit;
    }
    header('Location: login.php');
    exit;
}

// Fetch all queue tokens for today across ALL doctors
$stmt = $pdo->prepare("
    SELECT qt.*, p.name as patient_name, p.age as patient_age, p.gender as patient_gender, d.name as doctor_name, d.specialization
    FROM queue_tokens qt
    JOIN patients p ON qt.patient_id = p.patient_id
    JOIN doctors d ON qt.doctor_id = d.doctor_id
    WHERE DATE(qt.arrival_time) = CURRENT_DATE
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

<div class="w-full max-w-7xl mx-auto">
    
    <!-- Admin Header -->
    <div class="glass-card p-6 rounded-[2rem] flex flex-col md:flex-row justify-between items-center mb-8 border border-white/60 dark:border-slate-700/50 shadow-sm">
        <div class="flex items-center gap-4">
            <div class="w-16 h-16 rounded-2xl bg-slate-800 dark:bg-slate-700 text-white flex items-center justify-center shadow-lg">
                <i class="ph ph-shield-check text-3xl"></i>
            </div>
            <div>
                <h2 class="text-2xl font-black text-slate-800 dark:text-white leading-tight">System Administrator</h2>
                <p class="text-brand-600 dark:text-brand-400 font-semibold text-sm">
                    Hospital Overview • <span class="text-slate-500 dark:text-slate-400">All Departments</span>
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
            
            <a href="logout.php" class="text-slate-500 dark:text-slate-400 hover:text-red-500 dark:hover:text-red-400 transition-colors" title="Logout">
                <i class="ph ph-sign-out text-2xl"></i>
            </a>
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

    <div id="dashboard-content">
<?php endif; ?>
    
    <!-- Stats Row -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mb-8">
        <div class="glass-card p-6 rounded-2xl flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center text-blue-600 dark:text-blue-400">
                <i class="ph ph-users text-2xl"></i>
            </div>
            <div>
                <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">Total Hospital</p>
                <p class="text-3xl font-black text-slate-800 dark:text-white"><?= $total_patients ?></p>
            </div>
        </div>
        <div class="glass-card p-6 rounded-2xl flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-yellow-100 dark:bg-yellow-900/50 flex items-center justify-center text-yellow-600 dark:text-yellow-400">
                <i class="ph ph-hourglass-high text-2xl"></i>
            </div>
            <div>
                <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">Waiting</p>
                <p class="text-3xl font-black text-slate-800 dark:text-white"><?= $waiting ?></p>
            </div>
        </div>
        <div class="glass-card p-6 rounded-2xl flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-brand-100 dark:bg-brand-900/50 flex items-center justify-center text-brand-600 dark:text-brand-400">
                <i class="ph ph-pulse text-2xl"></i>
            </div>
            <div>
                <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">In Progress</p>
                <p class="text-3xl font-black text-slate-800 dark:text-white"><?= $in_progress ?></p>
            </div>
        </div>
        <div class="glass-card p-6 rounded-2xl flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-emerald-100 dark:bg-emerald-900/50 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                <i class="ph ph-check-circle text-2xl"></i>
            </div>
            <div>
                <p class="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest">Completed</p>
                <p class="text-3xl font-black text-slate-800 dark:text-white"><?= $completed ?></p>
            </div>
        </div>
    </div>

    <!-- Main Table -->
    <div class="glass-card rounded-[2rem] overflow-hidden border border-white/40 dark:border-slate-700/50 shadow-xl">
        <div class="px-8 py-5 border-b border-slate-200/50 dark:border-slate-700/50 flex justify-between items-center bg-white/40 dark:bg-slate-800/40">
            <h2 class="text-xl font-extrabold text-slate-800 dark:text-white flex items-center gap-2">
                <i class="ph ph-list-dashes text-slate-500"></i> Master Queue
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
                        <th class="py-4 px-8">Attending Doctor</th>
                        <th class="py-4 px-8">Status</th>
                        <th class="py-4 px-8 text-right">Timing</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100/50 dark:divide-slate-700/50 bg-white/20 dark:bg-slate-900/20">
                    <?php foreach ($tokens as $token): ?>
                        <tr class="hover:bg-white/60 dark:hover:bg-slate-800/60 transition-colors duration-200 group">
                            
                            <!-- Token No -->
                            <td class="py-4 px-8">
                                <span class="font-black text-slate-800 dark:text-white text-xl">
                                    <?= htmlspecialchars($token['token_number']) ?>
                                </span>
                            </td>
                            
                            <!-- Patient Info -->
                            <td class="py-4 px-8">
                                <p class="text-slate-800 dark:text-slate-200 font-bold text-sm"><?= htmlspecialchars($token['patient_name']) ?></p>
                                <p class="text-slate-500 dark:text-slate-400 text-xs font-medium mt-0.5">
                                    <?= htmlspecialchars($token['patient_age']) ?>y • <?= htmlspecialchars($token['patient_gender'][0]) ?>
                                </p>
                            </td>
                            
                            <!-- Department/Doctor -->
                            <td class="py-4 px-8">
                                <p class="text-slate-800 dark:text-slate-200 font-bold text-sm"><?= htmlspecialchars($token['doctor_name']) ?></p>
                                <div class="inline-flex items-center gap-1.5 mt-0.5">
                                    <i class="ph ph-stethoscope text-brand-500 text-xs"></i>
                                    <span class="text-slate-500 dark:text-slate-400 font-medium text-xs"><?= htmlspecialchars($token['specialization']) ?></span>
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
                            
                            <!-- Timing -->
                            <td class="py-4 px-8 text-right text-xs font-medium text-slate-500 dark:text-slate-400">
                                <p>Arr: <?= date('h:i A', strtotime($token['arrival_time'])) ?></p>
                                <?php if ($token['service_start_time']): ?>
                                    <p>Start: <?= date('h:i A', strtotime($token['service_start_time'])) ?></p>
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