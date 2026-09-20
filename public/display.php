<?php
// public/display.php
require_once '../config/db.php';
require_once '../includes/functions.php';

$is_ajax = isset($_GET['ajax']);

// Fetch departments and their average service times
$dept_stmt = $pdo->query("SELECT doctor_id, specialization, avg_service_time_in_minutes as avg_time FROM doctors");
$departments_raw = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all tokens for today
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
    <title>Live Queue Display</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Outfit', 'sans-serif'] },
                    colors: {
                        brand: { 100: '#dbeafe', 400: '#60a5fa', 500: '#3b82f6', 600: '#2563eb' }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-900 text-white overflow-hidden">


<script>
    // Simple fetch polling to prevent full page flash
    setInterval(() => {
        fetch('display.php?ajax=1')
            .then(res => res.text())
            .then(html => {
                document.getElementById('live-grid').innerHTML = html;
            });
    }, 10000);
</script>

<div class="flex-1 flex flex-col min-h-screen">
    
    <!-- Full Width Header -->
    <div class="bg-brand-600 px-8 py-2 flex justify-between items-center shadow-md">
        <h1 class="text-2xl md:text-3xl font-black tracking-tight uppercase">Live Queue Status</h1>
        <div class="flex items-baseline gap-3">
            <p class="text-sm font-medium text-brand-200 hidden sm:block"><?= date('l, M d, Y') ?></p>
            <p class="text-xl md:text-2xl font-bold text-white tracking-wide" id="clock-display"><?= date('h:i A') ?></p>
        </div>
    </div>
    <script>
        setInterval(() => {
            document.getElementById('clock-display').innerText = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        }, 1000);
    </script>
    
    <!-- Main Content Area (AJAX Replaced) -->
    <div id="live-grid" class="flex-1 flex flex-col">
<?php endif; // End non-AJAX block ?>

        <div class="flex-1 grid grid-cols-<?= count($department_data) > 0 ? count($department_data) : 1 ?> divide-x divide-slate-800">
            <?php foreach ($department_data as $dept => $data): ?>
                <div class="flex flex-col">
                    <!-- Department Title -->
                    <div class="bg-slate-800 text-center py-4 flex flex-col justify-center items-center gap-1.5 border-b-2 border-slate-900">
                        <h2 class="text-3xl font-bold text-slate-300 uppercase tracking-widest"><?= htmlspecialchars($dept) ?></h2>
                        <div class="flex items-center gap-1.5 text-slate-400 bg-slate-900/60 px-3 py-1 rounded-full text-xs font-semibold shadow-inner">
                            <i class="ph-fill ph-clock text-brand-400"></i>
                            <span>Avg Wait: <span class="text-white"><?= $data['avg_wait'] ?> mins/patient</span></span>
                        </div>
                    </div>
                    
                    <!-- Now Serving -->
                    <div class="flex-1 flex flex-col items-center justify-center p-6 bg-slate-900 gap-6">
                        <div>
                            <span class="bg-brand-600 text-white px-6 py-2 rounded-full text-sm font-bold uppercase tracking-widest shadow-lg">Now Serving</span>
                        </div>
                        
                        <?php if ($data['serving']): ?>
                            <div class="text-center w-full px-2 flex flex-col items-center gap-4">
                                <p class="text-6xl lg:text-7xl xl:text-[5rem] font-black text-brand-400 leading-none tracking-tighter drop-shadow-[0_0_15px_rgba(59,130,246,0.3)] whitespace-nowrap"><?= htmlspecialchars($data['serving']['token_number']) ?></p>
                                <p class="text-lg lg:text-xl font-bold text-white bg-brand-600/20 py-2.5 px-6 rounded-xl border border-brand-500/30 inline-block">Please proceed to <?= htmlspecialchars($data['serving']['room_number']) ?></p>
                            </div>
                        <?php else: ?>
                            <div class="opacity-40 flex flex-col items-center gap-2">
                                <i class="ph ph-minus text-5xl"></i>
                                <p class="text-xl font-bold uppercase">No Patient</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Up Next -->
                    <div class="bg-slate-800/80 p-5 border-t-4 border-slate-800 flex flex-col justify-center gap-4">
                        <h3 class="text-sm font-bold text-slate-400 uppercase tracking-widest text-center">Up Next</h3>
                        <?php if (count($data['up_next']) > 0): ?>
                            <div class="flex flex-wrap justify-center gap-4">
                                <?php foreach ($data['up_next'] as $next): ?>
                                    <div class="bg-slate-700 px-8 py-4 rounded-xl text-center border-l-4 border-yellow-500 shadow-md">
                                        <p class="text-4xl font-black text-white"><?= htmlspecialchars($next['token_number']) ?></p>
                                        <p class="text-xs font-bold text-yellow-500 uppercase mt-1">Waiting</p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center opacity-50 mt-8">
                                <p class="text-xl font-bold uppercase">Queue Empty</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
<?php if (!$is_ajax): ?>
    </div>
    
    <!-- Footer -->
    <div class="bg-slate-950 px-8 py-4 flex justify-between items-center text-slate-500 font-bold text-lg">
        <p></p>
        <p class="flex items-center gap-2"><span class="w-3 h-3 bg-emerald-500 rounded-full animate-pulse"></span> System Auto-Updating</p>
    </div>
</div>
</body>
</html>
<?php endif; ?>
