<?php
// public/lookup.php
require_once '../config/db.php';
require_once '../includes/functions.php';

$search_result = null;
$error = '';
$queue_position = null;

$incoming_phone = $_POST['phone'] ?? $_GET['phone'] ?? '';
$incoming_token = $_POST['token_number'] ?? $_GET['token_number'] ?? '';

if (!empty($incoming_phone) || !empty($incoming_token)) {
    $phone = preg_replace('/[^0-9]/', '', trim($incoming_phone));
    $token_number = strtoupper(trim($incoming_token));
    
    if (empty($phone) && empty($token_number)) {
        $error = "Please enter your 10-digit registered mobile number or booking reference.";
    } elseif (!empty($phone) && !empty($token_number)) {
        // Search by exact Phone + (Token Number OR Booking Reference)
        $stmt = $pdo->prepare("
            SELECT qt.*, d.name as doctor_name, d.specialization as department, d.room_number, p.name as patient_name 
            FROM queue_tokens qt
            JOIN patients p ON qt.patient_id = p.patient_id
            JOIN doctors d ON qt.doctor_id = d.doctor_id
            WHERE p.phone = ? AND (UPPER(qt.token_number) = ? OR UPPER(qt.booking_ref) = ?) 
            AND (qt.arrival_date >= CURRENT_DATE OR DATE(qt.scheduled_time) >= CURRENT_DATE OR DATE(qt.arrival_time) = CURRENT_DATE)
            ORDER BY qt.token_id DESC LIMIT 1
        ");
        $stmt->execute([$phone, $token_number, $token_number]);
        $search_result = $stmt->fetch();
        
        if (!$search_result) {
            $error = "No active appointment or token found matching '$token_number' and Phone '$phone'.";
        }
    } elseif (!empty($token_number)) {
        // Search by Token Number or Booking Reference directly
        $stmt = $pdo->prepare("
            SELECT qt.*, d.name as doctor_name, d.specialization as department, d.room_number, p.name as patient_name 
            FROM queue_tokens qt
            JOIN patients p ON qt.patient_id = p.patient_id
            JOIN doctors d ON qt.doctor_id = d.doctor_id
            WHERE (UPPER(qt.token_number) = ? OR UPPER(qt.booking_ref) = ?) 
            AND (qt.arrival_date >= CURRENT_DATE OR DATE(qt.scheduled_time) >= CURRENT_DATE OR DATE(qt.arrival_time) = CURRENT_DATE)
            ORDER BY qt.token_id DESC LIMIT 1
        ");
        $stmt->execute([$token_number, $token_number]);
        $search_result = $stmt->fetch();
        
        if (!$search_result) {
            $error = "No active record found for Reference / Token '$token_number'.";
        }
    } else {
        // User entered only Phone -> find their latest token or booking automatically
        $stmt = $pdo->prepare("
            SELECT qt.*, d.name as doctor_name, d.specialization as department, d.room_number, p.name as patient_name 
            FROM queue_tokens qt
            JOIN patients p ON qt.patient_id = p.patient_id
            JOIN doctors d ON qt.doctor_id = d.doctor_id
            WHERE p.phone = ? 
            AND (qt.arrival_date >= CURRENT_DATE OR DATE(qt.scheduled_time) >= CURRENT_DATE OR DATE(qt.arrival_time) = CURRENT_DATE)
            ORDER BY qt.token_id DESC LIMIT 1
        ");
        $stmt->execute([$phone]);
        $search_result = $stmt->fetch();
        
        if (!$search_result) {
            $error = "No active check-in or booking found for phone number '$phone'.";
        }
    }

    if ($search_result && $search_result['status'] === 'Waiting') {
        $wait_data = calculateDynamicWait($pdo, $search_result['doctor_id']);
        $search_result['dynamic_wait'] = $wait_data['total_wait'];
        
        // Calculate how many patients are ahead of this user
        $pos_stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM queue_tokens 
            WHERE doctor_id = ? AND status = 'Waiting' AND token_number IS NOT NULL
            AND COALESCE(scheduled_time, arrival_time) < COALESCE(?, ?) 
            AND (arrival_date = CURRENT_DATE OR DATE(arrival_time) = CURRENT_DATE)
        ");
        $pos_stmt->execute([
            $search_result['doctor_id'],
            $search_result['scheduled_time'],
            $search_result['arrival_time']
        ]);
        $queue_position = (int)$pos_stmt->fetchColumn();
    }
}

include '../includes/header.php';
?>

<div class="w-full <?= $search_result ? 'max-w-xl' : 'max-w-md' ?> mx-auto my-auto py-8">
    <div class="card p-7 shadow-lg border border-slate-200/80 dark:border-slate-800">
        
        <div class="flex items-center gap-3.5 mb-6">
            <div class="w-11 h-11 rounded-xl bg-blue-50 dark:bg-blue-950/80 text-blue-600 dark:text-blue-400 flex items-center justify-center border border-blue-100 dark:border-blue-900 shadow-xs">
                <i class="ph-bold ph-ticket text-xl"></i>
            </div>
            <div>
                <h2 class="text-xl font-bold font-display text-slate-900 dark:text-white tracking-tight leading-tight">Queue Status Tracker</h2>
                <p class="text-slate-500 dark:text-slate-400 text-xs">Check your live turn position & estimated wait time</p>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 p-3 mb-5 rounded-xl flex items-center gap-2.5 text-xs">
                <i class="ph-bold ph-warning-circle text-base flex-shrink-0"></i>
                <p class="font-medium"><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($search_result): ?>
            <!-- Status Card -->
            <div class="bg-slate-50/80 dark:bg-slate-900/80 border border-slate-200/80 dark:border-slate-800 p-5 rounded-2xl mb-5 space-y-4 shadow-xs">
                <div class="flex justify-between items-center pb-3.5 border-b border-slate-200 dark:border-slate-800">
                    <div>
                        <span class="text-[10px] uppercase font-bold text-slate-400 block tracking-widest">
                            <?= $search_result['status'] === 'Booked' ? 'Booking Reference' : 'Queue Token' ?>
                        </span>
                        <span class="text-3xl font-black font-mono tracking-tight text-blue-600 dark:text-blue-400">
                            <?= htmlspecialchars($search_result['status'] === 'Booked' ? $search_result['booking_ref'] : $search_result['token_number']) ?>
                        </span>
                    </div>
                    <div>
                        <?php if ($search_result['status'] === 'Waiting'): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-100/90 text-amber-800 dark:bg-amber-950 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                                <i class="ph-bold ph-clock"></i> Waiting
                            </span>
                        <?php elseif ($search_result['status'] === 'In-Progress'): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-blue-600 text-white animate-pulse shadow-xs">
                                <i class="ph-bold ph-pulse"></i> Now Serving
                            </span>
                        <?php elseif ($search_result['status'] === 'Booked'): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                                <i class="ph-bold ph-calendar"></i> Pre-Booked
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                                <?= htmlspecialchars($search_result['status']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="space-y-2 text-xs">
                    <div class="flex justify-between">
                        <span class="text-slate-500 font-medium">Patient Name:</span>
                        <span class="font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($search_result['patient_name']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500 font-medium">Department:</span>
                        <span class="font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($search_result['department']) ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500 font-medium">Attending Doctor:</span>
                        <span class="font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($search_result['doctor_name']) ?> (<?= htmlspecialchars($search_result['room_number']) ?>)</span>
                    </div>
                </div>

                <?php if ($search_result['status'] === 'Waiting'): ?>
                    <div class="grid grid-cols-2 gap-3 pt-3 border-t border-slate-200 dark:border-slate-800">
                        <div class="bg-white dark:bg-slate-800/80 p-3 rounded-xl border border-slate-200/80 dark:border-slate-700 text-center shadow-xs">
                            <span class="text-[10px] text-slate-400 block uppercase font-bold tracking-wider">Patients Ahead</span>
                            <span class="text-2xl font-black font-mono text-slate-900 dark:text-white"><?= $queue_position ?></span>
                        </div>
                        <div class="bg-white dark:bg-slate-800/80 p-3 rounded-xl border border-slate-200/80 dark:border-slate-700 text-center shadow-xs">
                            <span class="text-[10px] text-slate-400 block uppercase font-bold tracking-wider">Estimated Wait</span>
                            <span class="text-2xl font-black font-mono text-blue-600 dark:text-blue-400"><?= $search_result['dynamic_wait'] ?> mins</span>
                        </div>
                    </div>
                <?php elseif ($search_result['status'] === 'In-Progress'): ?>
                    <div class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white p-4 rounded-xl text-center shadow-md">
                        <span class="block text-xs uppercase font-bold tracking-wider text-blue-100">It's Your Turn Now</span>
                        <span class="block font-black text-lg mt-0.5">Please proceed to Room <?= htmlspecialchars($search_result['room_number']) ?></span>
                    </div>
                <?php elseif ($search_result['status'] === 'Booked'): ?>
                    <div class="bg-indigo-50/80 dark:bg-indigo-950/40 p-4 rounded-xl text-center border border-indigo-200/80 dark:border-indigo-800 text-xs">
                        <span class="block text-slate-500 font-medium">Appointment Slot</span>
                        <span class="block font-bold text-indigo-900 dark:text-indigo-200 text-base mt-0.5"><?= date('h:i A', strtotime($search_result['scheduled_time'])) ?> • <?= date('M d, Y', strtotime($search_result['scheduled_time'])) ?></span>
                        <span class="text-[11px] text-slate-500 mt-1 block">Please arrive 10 minutes prior and check in at the reception desk to enter the live queue.</span>
                    </div>
                <?php endif; ?>
            </div>
            
            <a href="lookup.php" class="block text-center bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold text-xs py-2.5 rounded-xl transition-all">
                <span>Check Another Token</span>
            </a>
        <?php else: ?>
            <form method="POST" class="space-y-4">
                <div>
                    <label for="phone" class="form-label">Registered Phone Number</label>
                    <div class="relative">
                        <i class="ph-bold ph-phone absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                        <input type="tel" id="phone" name="phone" pattern="[0-9]{10}" class="form-input" placeholder="10-digit mobile number">
                    </div>
                </div>
                <div>
                    <label for="token_number" class="form-label">Or Token / Booking Reference</label>
                    <div class="relative">
                        <i class="ph-bold ph-ticket absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                        <input type="text" id="token_number" name="token_number" class="form-input uppercase font-bold" placeholder="e.g. CAR-001 or BK-001">
                    </div>
                </div>
                <div class="pt-2">
                    <button type="submit" class="btn-primary w-full text-xs py-2.5 flex justify-center items-center gap-2 font-bold">
                        <span>Check Live Position</span>
                        <i class="ph-bold ph-arrow-right text-xs"></i>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
