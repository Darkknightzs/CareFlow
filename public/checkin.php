<?php
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_role'] !== 'Receptionist') {
    header("Location: login.php");
    exit;
}

// Fetch doctors for the dropdown
$stmt = $pdo->query("SELECT * FROM doctors ORDER BY name");
$doctors = $stmt->fetchAll();

// Fetch recent check-ins for the table
$recent_stmt = $pdo->query("
    SELECT qt.token_number, p.name as patient_name, qt.status 
    FROM queue_tokens qt
    JOIN patients p ON qt.patient_id = p.patient_id
    WHERE DATE(qt.arrival_time) = CURRENT_DATE
    ORDER BY qt.arrival_time DESC
    LIMIT 5
");
$recent_tokens = $recent_stmt->fetchAll();

include '../includes/header.php';
?>

<div class="w-full max-w-6xl mx-auto">
    
    <div class="glass-card p-6 lg:p-8 flex flex-col lg:flex-row gap-8 items-start shadow-2xl">
        
        <!-- Left Side: Title and Form -->
        <div class="w-full lg:w-1/2">
            <div class="mb-5">
                <span class="inline-block py-1 px-2.5 rounded-md bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300 font-bold text-[10px] tracking-widest uppercase border border-brand-200 dark:border-brand-700/50 mb-2">Front Desk</span>
                <h2 class="text-3xl lg:text-4xl font-black text-slate-800 dark:text-white tracking-tight leading-tight">Patient Check-in</h2>
                <p class="text-slate-500 dark:text-slate-400 mt-1.5 text-sm font-medium">Register a walk-in patient and issue a live queue token.</p>
            </div>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="bg-red-50/80 border border-red-200 text-red-700 p-3 mb-5 rounded-xl flex items-start gap-3 text-sm animate-pulse-slow backdrop-blur-sm shadow-sm">
                    <i class="ph ph-warning-circle text-xl flex-shrink-0"></i>
                    <p class="font-bold"><?= htmlspecialchars($_GET['error']) ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['success'])): ?>
                <div class="bg-gradient-to-br from-brand-50 to-white dark:from-slate-800 dark:to-slate-900 border border-brand-200 dark:border-brand-700/50 p-6 rounded-2xl shadow-lg text-center relative overflow-hidden mb-5">
                    <div class="absolute -right-4 -top-4 text-brand-100 dark:text-brand-900/30 transform rotate-12">
                        <i class="ph-fill ph-ticket text-9xl"></i>
                    </div>
                    
                    <h3 class="text-lg font-bold mb-1 relative z-10 text-brand-700 dark:text-brand-400 uppercase tracking-widest text-[10px]">Token Generated!</h3>
                    <div class="my-3 relative z-10">
                        <p class="text-5xl font-black text-transparent bg-clip-text bg-gradient-to-r from-brand-600 to-purple-600 dark:from-brand-400 dark:to-purple-400 tracking-wider"><?= htmlspecialchars($_GET['token']) ?></p>
                    </div>
                    <div class="flex justify-center items-center gap-2 relative z-10 mt-2 bg-brand-100 dark:bg-slate-800 rounded-lg py-2 px-4 inline-block mx-auto border border-brand-200 dark:border-slate-700">
                        <i class="ph ph-clock text-brand-600 dark:text-brand-400 text-lg"></i>
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Est. Wait: <strong class="font-bold text-brand-700 dark:text-brand-300"><?= (int)$_GET['wait'] ?> mins</strong></span>
                    </div>
                    <div class="mt-4 relative z-10 bg-blue-50/50 dark:bg-blue-900/20 p-2.5 rounded-lg border border-blue-100 dark:border-blue-800 text-[11px] text-blue-700 dark:text-blue-300 font-medium leading-snug">
                        Room number will be shown on the live display screen and patient lookup page when it is your turn.
                    </div>
                    <div class="mt-5 relative z-10">
                        <a href="checkin.php" class="btn-primary inline-flex items-center gap-2 px-6 py-2.5 text-sm">
                            <i class="ph ph-plus"></i> New Token
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <form action="process_booking.php" method="POST" class="grid grid-cols-2 gap-4">
                    <div class="col-span-2 sm:col-span-1">
                        <label for="name" class="form-label">Full Name</label>
                        <div class="relative">
                            <i class="ph ph-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 text-lg"></i>
                            <input type="text" id="name" name="name" required class="form-input" placeholder="Patient Name">
                        </div>
                    </div>
                    
                    <div class="col-span-2 sm:col-span-1">
                        <label for="phone" class="form-label">Mobile Number</label>
                        <div class="relative">
                            <i class="ph ph-phone absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 text-lg"></i>
                            <input type="tel" id="phone" name="phone" pattern="[0-9]{10}" required class="form-input" placeholder="9876543210" title="10 digits">
                        </div>
                    </div>
                    
                    <div class="col-span-2 sm:col-span-1">
                        <label for="age" class="form-label">Age</label>
                        <input type="number" id="age" name="age" required min="0" max="150" class="form-input !pl-4" placeholder="45">
                    </div>
                    <div class="col-span-2 sm:col-span-1">
                        <label for="gender" class="form-label">Gender</label>
                        <div class="relative">
                            <select id="gender" name="gender" required class="form-input !pl-4 appearance-none">
                                <option value="" disabled selected>---</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                            <i class="ph ph-caret-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"></i>
                        </div>
                    </div>

                    <div class="col-span-2">
                        <label for="doctor_id" class="form-label">Department / Doctor</label>
                        <div class="relative">
                            <i class="ph ph-stethoscope absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 text-lg"></i>
                            <select id="doctor_id" name="doctor_id" required class="form-input appearance-none !pl-10">
                                <option value="" disabled selected>Select a specialist...</option>
                                <?php foreach ($doctors as $doc): ?>
                                    <option value="<?= $doc['doctor_id'] ?>">
                                        <?= htmlspecialchars($doc['specialization']) ?> — <?= htmlspecialchars($doc['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <i class="ph ph-caret-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"></i>
                        </div>
                    </div>

                    <div class="col-span-2 pt-1">
                        <button type="submit" class="btn-primary w-full text-base py-3 flex justify-center items-center gap-2">
                            <span>Issue Token</span>
                            <i class="ph ph-arrow-right font-bold"></i>
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        
        <!-- Right Side: Recent Tokens List -->
        <div class="w-full lg:w-1/2 bg-white/40 dark:bg-slate-800/40 p-5 rounded-2xl border border-white/60 dark:border-slate-700/50 shadow-inner flex flex-col h-full">
            <h3 class="font-bold text-slate-700 dark:text-slate-300 mb-3 flex items-center gap-2">
                <i class="ph ph-list-numbers text-brand-500 text-xl"></i> 
                Today's Check-ins
            </h3>
            
            <?php if (count($recent_tokens) > 0): ?>
                <div class="flex-1 space-y-2.5">
                    <?php foreach ($recent_tokens as $t): ?>
                        <div class="bg-white/70 dark:bg-slate-800/70 p-3 rounded-xl border border-slate-100 dark:border-slate-700 flex justify-between items-center shadow-sm">
                            <div>
                                <span class="font-black text-slate-800 dark:text-white mr-2"><?= htmlspecialchars($t['token_number']) ?></span>
                                <span class="text-sm font-medium text-slate-500 dark:text-slate-400"><?= htmlspecialchars($t['patient_name']) ?></span>
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

    </div> <!-- Close container from header -->
</body>
</html>
