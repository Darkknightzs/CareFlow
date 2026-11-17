<?php
// public/display.php
require_once '../config/db.php';
require_once '../includes/functions.php';

$is_ajax = isset($_GET['ajax']);

// Fetch departments and their average service times
$dept_stmt = $pdo->query("SELECT doctor_id, specialization, avg_service_time_in_minutes as avg_time FROM doctors");
$departments_raw = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

// Auto-promote next waiting patient for any doctor who is free right now
foreach ($departments_raw as $d_info) {
    autoPromoteNextPatient($pdo, (int)$d_info['doctor_id']);
}

// Fetch all tokens for today (after any promotions)
$stmt = $pdo->query("
    SELECT qt.*, d.specialization, d.room_number 
    FROM queue_tokens qt
    JOIN doctors d ON qt.doctor_id = d.doctor_id
    WHERE (DATE(qt.arrival_time) = CURRENT_DATE OR qt.arrival_date = CURRENT_DATE)
    AND qt.token_number IS NOT NULL
    ORDER BY COALESCE(qt.scheduled_time, qt.arrival_time) ASC
");
$all_tokens = $stmt->fetchAll();

$department_data = [];
foreach ($departments_raw as $doc) {
    $dept = $doc['specialization'];
    $avg_time = (int)$doc['avg_time'];
    
    // Get In-Progress token for this department
    $serving = array_filter($all_tokens, fn($t) => $t['specialization'] === $dept && $t['status'] === 'In-Progress');
    // Get Waiting tokens
    $waiting = array_filter($all_tokens, fn($t) => $t['specialization'] === $dept && $t['status'] === 'Waiting');
    
    // Reset keys
    $serving = array_values($serving);
    $waiting = array_values($waiting);
    
    // Calculate real average service time using the single source of truth
    $wait_data = calculateDynamicWait($pdo, $doc['doctor_id']);
    $display_wait = $wait_data['effective_avg'];
    
    $department_data[$dept] = [
        'serving' => count($serving) > 0 ? $serving[0] : null,
        'up_next' => array_slice($waiting, 0, 1),
        'avg_wait' => $display_wait
    ];
}

if (!$is_ajax):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live OPD Waiting Lounge Billboard — CareFlow</title>
    <!-- CareFlow Custom Favicon -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%232563eb'/%3E%3Cpath d='M32 16v32M16 32h32' stroke='white' stroke-width='6' stroke-linecap='round'/%3E%3C/svg%3E">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800;900&family=JetBrains+Mono:wght@700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 
                        sans: ['Inter', 'sans-serif'],
                        display: ['Outfit', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace'],
                    }
                }
            }
        }
    </script>
    <style>
        body { 
            font-family: 'Inter', sans-serif;
            background: #070b14;
            color: #f8fafc;
        }
        .token-glow {
            text-shadow: 0 0 30px rgba(56, 189, 248, 0.35);
        }
        .card-surface {
            background: rgba(14, 20, 36, 0.7);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(30, 41, 59, 0.9);
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.7);
        }
    </style>
</head>
<body class="bg-[#070b14] text-slate-100 overflow-hidden min-h-screen flex flex-col antialiased">

<script>
    setInterval(() => {
        fetch('display.php?ajax=1')
            .then(res => res.text())
            .then(html => {
                const el = document.getElementById('live-grid');
                if (el) el.innerHTML = html;
            });
    }, 10000);
</script>

<div class="flex-1 flex flex-col min-h-screen">
    
    <!-- Top Hospital Header -->
    <div class="bg-[#0b1020]/90 border-b border-slate-800/90 px-8 py-3.5 flex justify-between items-center backdrop-blur-md">
        <div class="flex items-center gap-3.5">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-blue-600 to-cyan-500 flex items-center justify-center text-white shadow-lg shadow-blue-500/20">
                <i class="ph-bold ph-heartbeat text-2xl"></i>
            </div>
            <div>
                <h1 class="text-xl font-black font-display tracking-tight text-white flex items-center gap-2">
                    CareFlow <span class="text-xs px-2.5 py-0.5 rounded-full bg-blue-950 text-cyan-400 border border-cyan-800/60 font-semibold normal-case">OPD Live Queue Board</span>
                </h1>
            </div>
        </div>
        <div class="flex items-center gap-8">
            <span class="text-xs font-semibold text-slate-400 tracking-wide uppercase hidden sm:inline"><?= date('l, M d, Y') ?></span>
            <div class="flex items-center gap-2.5 bg-slate-900/80 px-4 py-1.5 rounded-xl border border-slate-800">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                <span class="text-xl font-bold font-mono text-cyan-400 tracking-wider" id="clock-display"><?= date('h:i A') ?></span>
            </div>
        </div>
    </div>
    <script>
        setInterval(() => {
            const el = document.getElementById('clock-display');
            if (el) el.innerText = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        }, 1000);
    </script>
    
    <!-- Main Content Area (AJAX Replaced) -->
    <div id="live-grid" class="flex-1 flex flex-col p-6 lg:p-8">
<?php endif; // End non-AJAX block ?>

        <div class="flex-1 grid grid-cols-1 md:grid-cols-<?= max(1, count($department_data)) ?> gap-6">
            <?php foreach ($department_data as $dept => $data): ?>
                <div class="card-surface rounded-2xl flex flex-col overflow-hidden transition-all">
                    
                    <!-- Department Header -->
                    <div class="px-6 py-4.5 border-b border-slate-800/80 flex justify-between items-center bg-[#0d1326]/60">
                        <div>
                            <span class="text-[10px] font-black uppercase tracking-widest text-slate-400 block mb-0.5">Department</span>
                            <h2 class="text-2xl font-black font-display text-white tracking-tight"><?= htmlspecialchars($dept) ?></h2>
                        </div>
                        <div class="flex items-center gap-1.5 px-3 py-1 rounded-full bg-slate-900 border border-slate-700/80 text-xs text-slate-300">
                            <i class="ph-bold ph-clock text-cyan-400"></i>
                            <span class="font-bold text-white"><?= $data['avg_wait'] ?> min</span>
                            <span class="text-[10px] text-slate-400 uppercase">avg</span>
                        </div>
                    </div>
                    
                    <!-- Now Serving Main Hero -->
                    <div class="flex-1 flex flex-col items-center justify-center p-6 lg:p-8 bg-[#070b14]/60 w-full overflow-hidden">
                        <span class="text-xs font-black uppercase tracking-widest text-cyan-400 bg-cyan-950/70 border border-cyan-800/80 px-4 py-1.5 rounded-full mb-4 shadow-sm inline-flex items-center">
                            <span class="w-1.5 h-1.5 rounded-full bg-cyan-400 inline-block mr-2 animate-pulse"></span> Now Serving
                        </span>
                        
                        <?php if ($data['serving']): ?>
                            <div class="text-center w-full max-w-full px-2">
                                <p class="text-4xl sm:text-5xl xl:text-6xl font-black text-cyan-400 tracking-normal font-mono token-glow truncate select-none leading-none my-2">
                                    <?= htmlspecialchars($data['serving']['token_number']) ?>
                                </p>
                                <div class="mt-5 inline-flex items-center gap-2 bg-gradient-to-r from-blue-950/80 to-slate-900/80 border border-blue-800/60 text-slate-100 px-4 py-2.5 rounded-xl text-sm font-semibold shadow-md max-w-full">
                                    <i class="ph-bold ph-door-open text-lg text-cyan-400 shrink-0"></i>
                                    <span class="truncate">Please proceed to <strong class="text-white font-black uppercase"><?= htmlspecialchars($data['serving']['room_number']) ?></strong></span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 opacity-40">
                                <i class="ph ph-stethoscope text-6xl text-slate-500 mb-2"></i>
                                <p class="text-base font-bold text-slate-400 uppercase tracking-widest font-display">Cabin Ready</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Up Next Section -->
                    <div class="p-5 border-t border-slate-800/80 bg-[#0b1020]/80">
                        <div class="flex justify-between items-center mb-3">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Next in Line</p>
                            <span class="text-[10px] text-slate-500 font-medium">Please be ready</span>
                        </div>
                        <?php if (count($data['up_next']) > 0): ?>
                            <div class="flex items-center gap-3">
                                <?php foreach ($data['up_next'] as $next): ?>
                                    <div class="flex-1 bg-slate-900/90 border border-amber-500/30 px-5 py-3 rounded-xl flex items-center justify-between shadow-sm">
                                        <span class="text-2xl font-black font-mono text-amber-400"><?= htmlspecialchars($next['token_number']) ?></span>
                                        <span class="text-[10px] font-extrabold text-amber-300 uppercase px-2.5 py-0.5 rounded-full bg-amber-950/80 border border-amber-700/60">Waiting</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="py-2.5 text-center text-xs text-slate-500">
                                No further patients queued
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
<?php if (!$is_ajax): ?>
    </div>
    
    <!-- Footer -->
    <div class="bg-[#0b1020]/90 border-t border-slate-800/80 px-8 py-3 flex justify-between items-center text-xs text-slate-400">
        <span class="font-medium flex items-center gap-2">
            <i class="ph-bold ph-speaker-high text-cyan-400"></i> Turn notifications called automatically via OPD display
        </span>
        <span class="flex items-center gap-2 font-semibold">
            <span class="w-2 h-2 bg-emerald-400 rounded-full animate-pulse"></span> Live Sync Active
        </span>
    </div>
</div>
</body>
</html>
<?php endif; ?>
