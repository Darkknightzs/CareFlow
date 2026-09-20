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
            AND (qt.arrival_date = CURRENT_DATE OR DATE(qt.scheduled_time) = CURRENT_DATE OR DATE(qt.arrival_time) = CURRENT_DATE)
            ORDER BY qt.token_id DESC LIMIT 1
        ");
        $stmt->execute([$phone, $token_number, $token_number]);
        $search_result = $stmt->fetch();
        
        if (!$search_result) {
            $error = "No active appointment or token found for today matching '$token_number' and Phone '$phone'.";
        }
    } elseif (!empty($token_number)) {
        // Search by Token Number or Booking Reference directly
        $stmt = $pdo->prepare("
            SELECT qt.*, d.name as doctor_name, d.specialization as department, d.room_number, p.name as patient_name 
            FROM queue_tokens qt
            JOIN patients p ON qt.patient_id = p.patient_id
            JOIN doctors d ON qt.doctor_id = d.doctor_id
            WHERE (UPPER(qt.token_number) = ? OR UPPER(qt.booking_ref) = ?) 
            AND (qt.arrival_date = CURRENT_DATE OR DATE(qt.scheduled_time) = CURRENT_DATE OR DATE(qt.arrival_time) = CURRENT_DATE)
            ORDER BY qt.token_id DESC LIMIT 1
        ");
        $stmt->execute([$token_number, $token_number]);
        $search_result = $stmt->fetch();
        
        if (!$search_result) {
            $error = "No active record found for Reference / Token '$token_number' today.";
        }
    } else {
        // User entered only Phone -> find their latest token or booking today automatically
        $stmt = $pdo->prepare("
            SELECT qt.*, d.name as doctor_name, d.specialization as department, d.room_number, p.name as patient_name 
            FROM queue_tokens qt
            JOIN patients p ON qt.patient_id = p.patient_id
            JOIN doctors d ON qt.doctor_id = d.doctor_id
            WHERE p.phone = ? 
            AND (qt.arrival_date = CURRENT_DATE OR DATE(qt.scheduled_time) = CURRENT_DATE OR DATE(qt.arrival_time) = CURRENT_DATE)
            ORDER BY qt.token_id DESC LIMIT 1
        ");
        $stmt->execute([$phone]);
        $search_result = $stmt->fetch();
        
        if (!$search_result) {
            $error = "No active check-in or booking found for phone number '$phone' today.";
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

<div class="w-full <?= $search_result ? 'max-w-2xl' : 'max-w-md' ?> mx-auto my-auto py-4">
    <div class="glass-card p-6 md:p-8 rounded-[2rem] shadow-xl">
        
        <div class="text-center mb-6">
            <div class="w-12 h-12 rounded-xl bg-brand-100 dark:bg-brand-950/60 text-brand-600 dark:text-brand-400 flex items-center justify-center mx-auto mb-3 shadow-inner border border-transparent dark:border-slate-700">
                <i class="ph ph-ticket text-2xl"></i>
            </div>
            <h2 class="text-2xl md:text-3xl font-black text-slate-800 dark:text-white tracking-tight">Check Status</h2>
            <p class="text-slate-500 dark:text-slate-400 text-xs font-medium mt-1">Track your live position and wait time in the queue</p>
        </div>
        
        <?php if ($error): ?>
            <div class="bg-red-50/80 border border-red-200 text-red-700 p-3 mb-5 rounded-xl flex items-start gap-2 text-xs animate-pulse-slow">
                <i class="ph-fill ph-warning-circle text-lg text-red-500 flex-shrink-0"></i>
                <p class="font-bold"><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($search_result): ?>
            <!-- Horizontal Split Status Card -->
            <div class="bg-slate-50/80 border border-slate-200 p-4 md:p-5 rounded-xl mb-5 grid grid-cols-1 md:grid-cols-12 gap-4 items-center">
                
                <!-- Left Column: Token / Booking Badge -->
                <div class="md:col-span-5 bg-white border border-brand-100 rounded-xl p-4 text-center flex flex-col justify-center items-center shadow-xs">
                    <?php if ($search_result['status'] === 'Booked'): ?>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Booking Reference</span>
                        <span class="text-4xl md:text-5xl font-black text-indigo-600 tracking-tight my-1.5"><?= htmlspecialchars($search_result['booking_ref']) ?></span>
                        <div class="mt-1.5 w-full">
                            <span class="inline-flex items-center justify-center gap-1 w-full bg-indigo-100 text-indigo-800 border border-indigo-200 font-extrabold text-xs py-1.5 px-2 rounded-lg uppercase tracking-wider">
                                <i class="ph-fill ph-calendar text-xs"></i> Booked
                            </span>
                        </div>
                    <?php else: ?>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Token Number</span>
                        <span class="text-4xl md:text-5xl font-black text-brand-600 tracking-tight my-1.5"><?= htmlspecialchars($search_result['token_number']) ?></span>
                        <div class="mt-1.5 w-full">
                            <?php if ($search_result['status'] === 'Waiting'): ?>
                                <span class="inline-flex items-center justify-center gap-1 w-full bg-yellow-100 text-yellow-800 border border-yellow-300 font-extrabold text-xs py-1.5 px-2 rounded-lg uppercase tracking-wider">
                                    <i class="ph-fill ph-clock text-xs"></i> Waiting
                                </span>
                            <?php elseif ($search_result['status'] === 'In-Progress'): ?>
                                <span class="inline-flex items-center justify-center gap-1 w-full bg-brand-600 text-white font-extrabold text-xs py-1.5 px-2 rounded-lg uppercase tracking-wider animate-pulse">
                                    <i class="ph-fill ph-check-circle text-xs"></i> It's Your Turn!
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center justify-center gap-1 w-full bg-slate-200 text-slate-700 font-extrabold text-xs py-1.5 px-2 rounded-lg uppercase tracking-wider">
                                    <?= htmlspecialchars($search_result['status']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Right Column: Patient & Queue Details -->
                <div class="md:col-span-7 bg-white border border-slate-200/80 rounded-xl p-4 space-y-3 shadow-xs">
                    <div class="flex justify-between items-center border-b border-slate-100 pb-2">
                        <span class="text-slate-400 font-bold text-[10px] uppercase tracking-wider">Patient</span>
                        <span class="text-slate-900 font-black text-sm"><?= htmlspecialchars($search_result['patient_name']) ?></span>
                    </div>
                    
                    <div class="flex justify-between items-center border-b border-slate-100 pb-2">
                        <span class="text-slate-400 font-bold text-[10px] uppercase tracking-wider">Department</span>
                        <span class="text-slate-800 font-bold text-xs"><?= htmlspecialchars($search_result['department']) ?> • <?= htmlspecialchars($search_result['doctor_name']) ?></span>
                    </div>
                    
                    <?php if ($search_result['status'] === 'Booked'): ?>
                        <div class="bg-indigo-50/80 border border-indigo-200 p-3 rounded-lg text-center">
                            <span class="text-[10px] font-bold text-indigo-700 uppercase tracking-wider block mb-0.5">Scheduled Time Slot</span>
                            <span class="text-2xl font-black text-indigo-900"><?= date('h:i A', strtotime($search_result['scheduled_time'])) ?></span>
                            <span class="text-[11px] text-indigo-700 block mt-1">Please arrive 10m before your slot and check in at the reception desk.</span>
                        </div>
                    <?php elseif ($search_result['status'] === 'Waiting'): ?>
                        <div class="grid grid-cols-2 gap-2 pt-0.5">
                            <div class="bg-slate-50 p-2.5 rounded-lg border border-slate-200 text-center">
                                <span class="text-[9px] font-bold text-slate-500 uppercase tracking-wider block mb-0.5">Ahead</span>
                                <span class="text-xl font-black text-slate-900"><?= $queue_position ?></span>
                            </div>
                            <div class="bg-brand-50/80 p-2.5 rounded-lg border border-brand-200 text-center">
                                <span class="text-[9px] font-bold text-brand-700 uppercase tracking-wider block mb-0.5">Est. Wait</span>
                                <span class="text-xl font-black text-brand-700"><?= $search_result['dynamic_wait'] ?>m</span>
                            </div>
                        </div>
                    <?php elseif ($search_result['status'] === 'In-Progress'): ?>
                        <div class="bg-brand-600 text-white p-3 rounded-lg text-center shadow-sm">
                            <span class="block text-brand-200 font-bold text-[10px] uppercase tracking-widest">Please proceed to</span>
                            <span class="block font-black text-lg"><?= htmlspecialchars($search_result['room_number']) ?></span>
                        </div>
                    <?php elseif ($search_result['status'] === 'Completed'): ?>
                        <div class="bg-emerald-50 text-emerald-800 p-3 rounded-lg border border-emerald-200 text-center">
                            <span class="block font-bold text-sm">Consultation Completed</span>
                            <span class="block text-[10px] text-emerald-600">Thank you for your visit.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <a href="lookup.php" class="block text-center bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs py-2.5 rounded-lg transition-colors shadow-xs">
                <i class="ph ph-arrow-counter-clockwise mr-1"></i> Check Another Token
            </a>
        <?php else: ?>
            <form method="POST" class="space-y-4 text-left">
                <div>
                    <label for="phone" class="form-label !text-xs !mb-1.5">Registered Mobile Number</label>
                    <div class="relative">
                        <i class="ph ph-phone absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg"></i>
                        <input type="tel" id="phone" name="phone" pattern="[0-9]{10}" class="form-input !pl-10 !py-2.5 !text-sm" placeholder="9876543210" title="10-digit number">
                    </div>
                </div>
                <div>
                    <label for="token_number" class="form-label !text-xs !mb-1.5">Token Number or Booking Reference</label>
                    <div class="relative">
                        <i class="ph ph-ticket absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg"></i>
                        <input type="text" id="token_number" name="token_number" class="form-input !pl-10 !py-2.5 !text-sm font-bold uppercase" placeholder="e.g. CAR-001 or BK-001 (Optional)">
                    </div>
                </div>
                <div class="pt-2">
                    <button type="submit" class="w-full btn-primary text-sm !py-2.5 rounded-lg flex justify-center items-center gap-2 shadow-md">
                        <span>Check Status</span>
                        <i class="ph ph-arrow-right text-xs"></i>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
