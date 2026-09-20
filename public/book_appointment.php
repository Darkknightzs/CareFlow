<?php
// public/book_appointment.php
require_once '../config/db.php';
require_once '../includes/functions.php';

// AJAX Endpoint: return available slots for a selected doctor
if (isset($_GET['action']) && $_GET['action'] === 'get_slots') {
    header('Content-Type: application/json');
    $doctor_id = (int)($_GET['doctor_id'] ?? 0);
    $demo = isset($_GET['demo']) && $_GET['demo'] == '1';
    
    $target_date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : null;
    
    if ($doctor_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid doctor selected.']);
        exit;
    }

    $slot_data = getAvailableSlots($pdo, $doctor_id, $demo, $target_date);
    echo json_encode(['success' => true, 'data' => $slot_data]);
    exit;
}

$error = '';
$booking_success = null;

$is_after_hours = ((int)date('H') >= 20);
$default_booking_date = $is_after_hours ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d');
$active_booking_date = $_POST['booking_date'] ?? $default_booking_date;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $doctor_id = (int)($_POST['doctor_id'] ?? 0);
    $booking_date = trim($_POST['booking_date'] ?? '');
    $slot_time = trim($_POST['slot_time'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $phone = preg_replace('/[^0-9]/', '', trim($_POST['phone'] ?? ''));
    $age = (int)($_POST['age'] ?? 0);
    $gender = trim($_POST['gender'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $booking_date)) {
        $booking_date = $default_booking_date;
    }

    // Validation
    if (!$doctor_id) {
        $error = "Please select a department / doctor.";
    } elseif (empty($slot_time)) {
        $error = "Please select an available appointment time slot.";
    } elseif (empty($name)) {
        $error = "Please enter the patient's full name.";
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $error = "Please enter a valid 10-digit mobile number.";
    } elseif ($age <= 0 || $age > 150) {
        $error = "Please enter a valid age between 1 and 150.";
    } elseif (empty($gender)) {
        $error = "Please select a gender.";
    } elseif (isPatientAlreadyInQueueForDoctor($pdo, $phone, $doctor_id, $booking_date)) {
        $date_display = ($booking_date === date('Y-m-d', strtotime('+1 day'))) ? 'Tomorrow (' . date('M d, Y', strtotime($booking_date)) . ')' : 'Today';
        $error = "This mobile number already has an active appointment or queue token for this doctor for $date_display. Please check your status on the Lookup page.";
    } else {
        // Concurrency Check: Verify slot is still open
        $scheduled_datetime = "$booking_date " . substr($slot_time, 0, 8);

        $check_slot = $pdo->prepare("
            SELECT token_id 
            FROM queue_tokens 
            WHERE doctor_id = ? 
            AND scheduled_time = ? 
            AND status NOT IN ('Cancelled', 'No-Show')
            AND (arrival_date = ? OR DATE(scheduled_time) = ?)
        ");
        $check_slot->execute([$doctor_id, $scheduled_datetime, $booking_date, $booking_date]);
        if ($check_slot->fetch()) {
            $error = "This appointment slot was just reserved by another patient. Please pick a different available slot.";
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Upsert Patient Record (match by phone)
                $pt_stmt = $pdo->prepare("SELECT patient_id FROM patients WHERE phone = ? LIMIT 1");
                $pt_stmt->execute([$phone]);
                $patient_id = $pt_stmt->fetchColumn();

                if ($patient_id) {
                    $upd_pt = $pdo->prepare("UPDATE patients SET name = ?, age = ?, gender = ? WHERE patient_id = ?");
                    $upd_pt->execute([$name, $age, $gender, $patient_id]);
                } else {
                    $ins_pt = $pdo->prepare("INSERT INTO patients (name, phone, age, gender) VALUES (?, ?, ?, ?)");
                    $ins_pt->execute([$name, $phone, $age, $gender]);
                    $patient_id = $pdo->lastInsertId();
                }

                // 2. Generate Booking Reference (e.g. BK-001) for the target date
                $booking_ref = generateBookingReference($pdo, $booking_date);

                // 3. Insert into queue_tokens with status 'Booked' and token_number = NULL
                $ins_tok = $pdo->prepare("
                    INSERT INTO queue_tokens 
                    (patient_id, doctor_id, token_number, booking_ref, booking_type, scheduled_time, status, arrival_date, estimated_wait_time)
                    VALUES (?, ?, NULL, ?, 'Pre-Booked', ?, 'Booked', ?, 0)
                ");
                $ins_tok->execute([$patient_id, $doctor_id, $booking_ref, $scheduled_datetime, $booking_date]);

                $pdo->commit();

                // Fetch doctor details for confirmation
                $doc_info_stmt = $pdo->prepare("SELECT name, specialization, room_number FROM doctors WHERE doctor_id = ?");
                $doc_info_stmt->execute([$doctor_id]);
                $doc_info = $doc_info_stmt->fetch();

                $booking_success = [
                    'booking_ref' => $booking_ref,
                    'patient_name' => $name,
                    'phone' => $phone,
                    'doctor_name' => $doc_info['name'],
                    'department' => $doc_info['specialization'],
                    'room_number' => $doc_info['room_number'],
                    'scheduled_time' => date('h:i A', strtotime($scheduled_datetime)),
                    'date' => date('l, M d, Y', strtotime($booking_date)),
                    'is_tomorrow' => ($booking_date === date('Y-m-d', strtotime('+1 day')))
                ];
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Advance Booking Error: " . $e->getMessage());
                $error = "A system error occurred while processing your booking. Please try again.";
            }
        }
    }
}

// Fetch active doctors for dropdown
$doctors_stmt = $pdo->query("SELECT doctor_id, name, specialization, room_number FROM doctors ORDER BY name");
$doctors = $doctors_stmt->fetchAll();

include '../includes/header.php';
?>

<div class="w-full max-w-4xl mx-auto py-2">

    <?php if ($booking_success): ?>
        <!-- Booking Confirmation Screen -->
        <div class="glass-card p-6 md:p-10 rounded-3xl shadow-2xl text-center max-w-xl mx-auto">
            <div class="w-16 h-16 rounded-2xl bg-emerald-100 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mx-auto mb-4 shadow-inner border border-emerald-200 dark:border-emerald-800/60">
                <i class="ph-fill ph-check-circle text-4xl"></i>
            </div>
            
            <span class="inline-block py-1 px-3 rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 font-bold text-[11px] tracking-widest uppercase mb-2">
                Appointment Confirmed
            </span>
            <h2 class="text-3xl font-black text-slate-800 dark:text-white tracking-tight">Booking Successful!</h2>
            <p class="text-slate-500 dark:text-slate-400 text-xs font-medium mt-1">Your same-day appointment slot has been reserved.</p>

            <!-- Booking Card -->
            <div class="bg-gradient-to-br from-brand-50/70 to-white dark:from-slate-800/70 dark:to-slate-900/70 border border-brand-200/80 dark:border-slate-700/80 p-6 rounded-2xl shadow-md my-6 text-left relative overflow-hidden">
                <div class="flex justify-between items-start border-b border-brand-100 dark:border-slate-700/60 pb-4 mb-4">
                    <div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest block">Booking Reference</span>
                        <span class="text-4xl font-black text-brand-600 dark:text-brand-400 tracking-wider"><?= htmlspecialchars($booking_success['booking_ref']) ?></span>
                    </div>
                    <div class="text-right">
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest block">Scheduled Slot</span>
                        <span class="text-xl font-extrabold text-slate-800 dark:text-white"><?= htmlspecialchars($booking_success['scheduled_time']) ?></span>
                        <span class="text-[11px] text-slate-500 dark:text-slate-400 block"><?= htmlspecialchars($booking_success['date']) ?></span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div>
                        <span class="text-slate-400 font-bold uppercase text-[9px] block">Patient Name</span>
                        <span class="font-extrabold text-slate-800 dark:text-slate-200 text-sm"><?= htmlspecialchars($booking_success['patient_name']) ?></span>
                    </div>
                    <div>
                        <span class="text-slate-400 font-bold uppercase text-[9px] block">Registered Phone</span>
                        <span class="font-bold text-slate-700 dark:text-slate-300"><?= htmlspecialchars($booking_success['phone']) ?></span>
                    </div>
                    <div>
                        <span class="text-slate-400 font-bold uppercase text-[9px] block">Attending Doctor</span>
                        <span class="font-bold text-slate-700 dark:text-slate-300"><?= htmlspecialchars($booking_success['doctor_name']) ?></span>
                    </div>
                    <div>
                        <span class="text-slate-400 font-bold uppercase text-[9px] block">Department / Room</span>
                        <span class="font-bold text-slate-700 dark:text-slate-300"><?= htmlspecialchars($booking_success['department']) ?> • <?= htmlspecialchars($booking_success['room_number']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Reception Arrival Notice -->
            <div class="bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/60 p-4 rounded-xl text-amber-800 dark:text-amber-200 text-xs text-left mb-6 flex items-start gap-3">
                <i class="ph-fill ph-info text-xl text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5"></i>
                <div>
                    <p class="font-bold mb-0.5">Next Step at the Hospital:</p>
                    <p class="leading-relaxed">
                        Please arrive <strong>10 minutes before</strong> your scheduled slot (<?= htmlspecialchars($booking_success['scheduled_time']) ?>) and show your booking reference <strong><?= htmlspecialchars($booking_success['booking_ref']) ?></strong> or mobile number at the reception desk to convert this booking into a live queue token.
                    </p>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <a href="lookup.php?phone=<?= urlencode($booking_success['phone']) ?>&token_number=<?= urlencode($booking_success['booking_ref']) ?>" class="btn-primary py-3 px-6 text-sm flex items-center justify-center gap-2">
                    <i class="ph ph-magnifying-glass font-bold"></i>
                    <span>Check Status in Lookup</span>
                </a>
                <a href="book_appointment.php" class="bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold py-3 px-6 rounded-xl text-sm transition-colors flex items-center justify-center gap-2">
                    <i class="ph ph-calendar-plus"></i>
                    <span>Book Another</span>
                </a>
            </div>
        </div>

    <?php else: ?>
        <!-- Booking Form Screen -->
        <div class="glass-card p-6 md:p-8 rounded-3xl shadow-xl">
            <div class="mb-6">
                <span class="inline-block py-1 px-3 rounded-full bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300 font-bold text-[10px] tracking-widest uppercase border border-brand-200 dark:border-brand-700/50 mb-2">
                    Advance OPD Scheduling
                </span>
                <h2 class="text-3xl font-black text-slate-800 dark:text-white tracking-tight">Book Same-Day Appointment</h2>
                <p class="text-slate-500 dark:text-slate-400 text-xs font-medium mt-1">
                    Select a doctor, pick a reserved time slot, and receive a Booking Reference. No password or login required.
                </p>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-50/90 border border-red-200 text-red-700 p-3 mb-6 rounded-xl flex items-start gap-3 text-xs animate-pulse-slow">
                    <i class="ph-fill ph-warning-circle text-lg flex-shrink-0 mt-0.5"></i>
                    <p class="font-bold"><?= htmlspecialchars($error) ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" id="bookingForm" class="space-y-6">

                <!-- Step 1: Select Specialist / Department -->
                <div class="bg-white/50 dark:bg-slate-800/50 p-4 rounded-2xl border border-slate-200/60 dark:border-slate-700/60">
                    <label for="doctor_id" class="form-label !text-xs !mb-2 flex items-center gap-1.5">
                        <span class="w-5 h-5 rounded-full bg-brand-600 text-white inline-flex items-center justify-center text-[10px] font-black">1</span>
                        Select Department & Specialist Doctor
                    </label>
                    <div class="relative">
                        <i class="ph ph-stethoscope absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-lg"></i>
                        <select id="doctor_id" name="doctor_id" required class="form-input !pl-10 !py-2.5 text-sm appearance-none font-medium" onchange="loadDoctorSlots(this.value)">
                            <option value="" disabled selected>Choose a department specialist...</option>
                            <?php foreach ($doctors as $d): ?>
                                <option value="<?= $d['doctor_id'] ?>" <?= (isset($_POST['doctor_id']) && $_POST['doctor_id'] == $d['doctor_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['specialization']) ?> — <?= htmlspecialchars($d['name']) ?> (<?= htmlspecialchars($d['room_number']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <i class="ph ph-caret-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"></i>
                    </div>
                </div>

                <!-- Step 2: Slot Selection Container -->
                <div id="slotsSection" class="bg-white/50 dark:bg-slate-800/50 p-4 rounded-2xl border border-slate-200/60 dark:border-slate-700/60 <?= empty($_POST['doctor_id']) ? 'hidden' : '' ?>">
                    
                    <?php if ($is_after_hours): ?>
                        <div class="bg-indigo-50/90 dark:bg-indigo-950/50 border border-indigo-200 dark:border-indigo-800/60 p-3 rounded-xl mb-3 flex items-center gap-2.5 text-xs text-indigo-800 dark:text-indigo-200">
                            <i class="ph-fill ph-moon-stars text-xl text-indigo-600 dark:text-indigo-400 flex-shrink-0"></i>
                            <div>
                                <span class="font-extrabold block">Today's clinic is closed (OPD hours: 9:00 AM – 1:00 PM & 5:00 PM – 8:00 PM).</span>
                                <span class="text-[11px]">Booking is now open for <strong>Tomorrow Morning & Evening (<?= date('M d, Y', strtotime('+1 day')) ?>)</strong>.</span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 mb-3">
                        <label class="form-label !text-xs !mb-0 flex items-center gap-1.5">
                            <span class="w-5 h-5 rounded-full bg-brand-600 text-white inline-flex items-center justify-center text-[10px] font-black">2</span>
                            Pick Appointment Slot (<span id="slotDateLabel"><?= ($active_booking_date === date('Y-m-d', strtotime('+1 day'))) ? 'Tomorrow: ' . date('M d, Y', strtotime('+1 day')) : 'Today: ' . date('M d, Y') ?></span>)
                        </label>
                        
                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick="changeDate('<?= date('Y-m-d') ?>')" id="dateBtnToday" class="date-tab px-2.5 py-1 rounded-lg text-[11px] font-bold transition-all border <?= $active_booking_date === date('Y-m-d') ? 'bg-brand-600 text-white border-brand-600 shadow-xs' : 'bg-white/80 dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:border-brand-400' ?>">
                                Today (<?= date('M d') ?>)
                            </button>
                            <button type="button" onclick="changeDate('<?= date('Y-m-d', strtotime('+1 day')) ?>')" id="dateBtnTomorrow" class="date-tab px-2.5 py-1 rounded-lg text-[11px] font-bold transition-all border <?= $active_booking_date === date('Y-m-d', strtotime('+1 day')) ? 'bg-brand-600 text-white border-brand-600 shadow-xs' : 'bg-white/80 dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:border-brand-400' ?>">
                                Tomorrow (<?= date('M d', strtotime('+1 day')) ?>)
                            </button>
                        </div>
                    </div>

                    <!-- Hidden inputs to store chosen date and slot -->
                    <input type="hidden" id="booking_date" name="booking_date" value="<?= htmlspecialchars($active_booking_date) ?>">
                    <input type="hidden" id="slot_time" name="slot_time" value="<?= htmlspecialchars($_POST['slot_time'] ?? '') ?>" required>

                    <!-- Slot loading spinner / container -->
                    <div id="slotsContainer" class="py-2">
                        <div class="text-center py-6 text-slate-400 text-xs">
                            <i class="ph ph-calendar text-3xl mb-1 block"></i>
                            Select a doctor above to load open slots.
                        </div>
                    </div>

                    <!-- Late hours note / Demo override -->
                    <div id="demoNotice" class="hidden mt-3 pt-3 border-t border-slate-200/60 dark:border-slate-700/60 text-[11px] text-slate-400 flex items-center justify-between">
                        <span>Clinic hours: 9:00 AM – 1:00 PM & 5:00 PM – 8:00 PM.</span>
                        <button type="button" onclick="loadDoctorSlots(document.getElementById('doctor_id').value, true)" class="text-brand-600 dark:text-brand-400 underline hover:text-brand-700 font-semibold">
                            [Demo Mode: Preview All Shift Slots]
                        </button>
                    </div>
                </div>

                <!-- Step 3: Patient Information -->
                <div class="bg-white/50 dark:bg-slate-800/50 p-4 rounded-2xl border border-slate-200/60 dark:border-slate-700/60">
                    <label class="form-label !text-xs !mb-3 flex items-center gap-1.5">
                        <span class="w-5 h-5 rounded-full bg-brand-600 text-white inline-flex items-center justify-center text-[10px] font-black">3</span>
                        Patient Contact & Demographics
                    </label>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="name" class="form-label !text-[10px]">Full Name</label>
                            <div class="relative">
                                <i class="ph ph-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-base"></i>
                                <input type="text" id="name" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" class="form-input !pl-9 !py-2 text-sm" placeholder="e.g. Rahul Sharma">
                            </div>
                        </div>

                        <div>
                            <label for="phone" class="form-label !text-[10px]">Mobile Number (10 Digits)</label>
                            <div class="relative">
                                <i class="ph ph-phone absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-base"></i>
                                <input type="tel" id="phone" name="phone" pattern="[0-9]{10}" required value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" class="form-input !pl-9 !py-2 text-sm" placeholder="9876543210" title="10 digits">
                            </div>
                        </div>

                        <div>
                            <label for="age" class="form-label !text-[10px]">Age</label>
                            <input type="number" id="age" name="age" min="1" max="150" required value="<?= htmlspecialchars($_POST['age'] ?? '') ?>" class="form-input !pl-4 !py-2 text-sm" placeholder="e.g. 35">
                        </div>

                        <div>
                            <label for="gender" class="form-label !text-[10px]">Gender</label>
                            <div class="relative">
                                <select id="gender" name="gender" required class="form-input !pl-4 !py-2 text-sm appearance-none">
                                    <option value="" disabled selected>Choose gender...</option>
                                    <option value="Male" <?= (isset($_POST['gender']) && $_POST['gender'] === 'Male') ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= (isset($_POST['gender']) && $_POST['gender'] === 'Female') ? 'selected' : '' ?>>Female</option>
                                    <option value="Other" <?= (isset($_POST['gender']) && $_POST['gender'] === 'Other') ? 'selected' : '' ?>>Other</option>
                                </select>
                                <i class="ph ph-caret-down absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="pt-2">
                    <button type="submit" id="submitBtn" class="btn-primary w-full py-3 text-sm font-bold flex justify-center items-center gap-2 shadow-lg shadow-brand-500/25">
                        <i class="ph ph-calendar-check text-lg"></i>
                        <span>Confirm & Reserve Appointment Slot</span>
                    </button>
                    <p class="text-center text-slate-400 text-[11px] mt-2 font-medium">
                        Walk-in patients without bookings are also welcomed directly at the reception desk during OPD hours.
                    </p>
                </div>
            </form>
        </div>
    <?php endif; ?>

</div>

<script>
let selectedSlotTime = "<?= htmlspecialchars($_POST['slot_time'] ?? '') ?>";
let currentTargetDate = "<?= htmlspecialchars($active_booking_date) ?>";

function changeDate(newDate) {
    currentTargetDate = newDate;
    document.getElementById('booking_date').value = newDate;
    document.getElementById('slot_time').value = '';
    selectedSlotTime = '';
    
    // Update button styling
    const todayBtn = document.getElementById('dateBtnToday');
    const tomorrowBtn = document.getElementById('dateBtnTomorrow');
    const todayStr = "<?= date('Y-m-d') ?>";
    
    if (newDate === todayStr) {
        todayBtn.className = "date-tab px-2.5 py-1 rounded-lg text-[11px] font-bold transition-all border bg-brand-600 text-white border-brand-600 shadow-xs";
        tomorrowBtn.className = "date-tab px-2.5 py-1 rounded-lg text-[11px] font-bold transition-all border bg-white/80 dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:border-brand-400";
    } else {
        tomorrowBtn.className = "date-tab px-2.5 py-1 rounded-lg text-[11px] font-bold transition-all border bg-brand-600 text-white border-brand-600 shadow-xs";
        todayBtn.className = "date-tab px-2.5 py-1 rounded-lg text-[11px] font-bold transition-all border bg-white/80 dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:border-brand-400";
    }
    
    const docSelect = document.getElementById('doctor_id');
    if (docSelect && docSelect.value) {
        loadDoctorSlots(docSelect.value);
    }
}

function loadDoctorSlots(doctorId, demoMode = false) {
    if (!doctorId) return;
    
    const slotsSection = document.getElementById('slotsSection');
    const container = document.getElementById('slotsContainer');
    const demoNotice = document.getElementById('demoNotice');
    
    slotsSection.classList.remove('hidden');
    container.innerHTML = `
        <div class="text-center py-6 text-brand-600 dark:text-brand-400">
            <i class="ph ph-spinner animate-spin text-2xl mb-1"></i>
            <p class="text-xs font-semibold">Calculating open slots...</p>
        </div>
    `;

    fetch(`book_appointment.php?action=get_slots&doctor_id=${doctorId}&date=${currentTargetDate}${demoMode ? '&demo=1' : ''}`)
        .then(res => res.json())
        .then(res => {
            if (!res.success) {
                container.innerHTML = `<div class="text-red-500 text-xs font-bold p-3">${res.error || 'Failed to load slots.'}</div>`;
                return;
            }

            const data = res.data;
            const slots = data.slots;
            
            if (document.getElementById('slotDateLabel') && data.date_label) {
                document.getElementById('slotDateLabel').innerText = data.date_label;
            }
            
            // Show demo notice if after hours
            demoNotice.classList.remove('hidden');

            if (slots.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-6 bg-slate-100/70 dark:bg-slate-900/60 rounded-xl p-4 border border-slate-200/80 dark:border-slate-800">
                        <i class="ph ph-clock-countdown text-3xl text-amber-500 mb-1"></i>
                        <p class="text-sm font-bold text-slate-700 dark:text-slate-300">No slots available for ${data.date_label}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">All pre-booking capacity is filled. You can switch dates above or visit reception directly for walk-in OPD.</p>
                    </div>
                `;
                return;
            }

            // Group by Session (Morning vs Evening)
            const morning = slots.filter(s => s.session.includes('Morning'));
            const evening = slots.filter(s => s.session.includes('Evening'));

            let html = '';

            if (morning.length > 0) {
                html += `
                    <div class="mb-4">
                        <div class="flex items-center gap-2 mb-2">
                            <i class="ph ph-sun text-amber-500 font-bold"></i>
                            <span class="text-[11px] font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">Morning OPD (09:00 AM – 01:00 PM)</span>
                        </div>
                        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-2">
                            ${renderSlotButtons(morning)}
                        </div>
                    </div>
                `;
            }

            if (evening.length > 0) {
                html += `
                    <div>
                        <div class="flex items-center gap-2 mb-2">
                            <i class="ph ph-moon text-indigo-400 font-bold"></i>
                            <span class="text-[11px] font-bold text-slate-600 dark:text-slate-300 uppercase tracking-wider">Evening OPD (05:00 PM – 08:00 PM)</span>
                        </div>
                        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-2">
                            ${renderSlotButtons(evening)}
                        </div>
                    </div>
                `;
            }

            container.innerHTML = html;
        })
        .catch(err => {
            container.innerHTML = `<div class="text-red-500 text-xs font-bold p-3">Error fetching slots. Please try again.</div>`;
        });
}

function renderSlotButtons(slotList) {
    return slotList.map(s => {
        const isSelected = selectedSlotTime === s.time_24;
        const btnClass = isSelected
            ? 'bg-brand-600 text-white font-black border-brand-600 ring-2 ring-brand-400 shadow-md scale-105'
            : 'bg-white/80 dark:bg-slate-800/80 text-slate-700 dark:text-slate-200 border-slate-200 dark:border-slate-700 hover:border-brand-500 hover:bg-brand-50 dark:hover:bg-slate-700';

        return `
            <button type="button" onclick="selectSlot('${s.time_24}', this)" class="slot-btn p-2.5 rounded-xl border text-xs font-bold text-center transition-all ${btnClass}">
                ${s.time_12}
            </button>
        `;
    }).join('');
}

function selectSlot(timeStr, btnElem) {
    selectedSlotTime = timeStr;
    document.getElementById('slot_time').value = timeStr;

    // Remove active styles from all slot buttons
    document.querySelectorAll('.slot-btn').forEach(btn => {
        btn.className = 'slot-btn p-2.5 rounded-xl border text-xs font-bold text-center transition-all bg-white/80 dark:bg-slate-800/80 text-slate-700 dark:text-slate-200 border-slate-200 dark:border-slate-700 hover:border-brand-500 hover:bg-brand-50 dark:hover:bg-slate-700';
    });

    // Highlight selected button
    btnElem.className = 'slot-btn p-2.5 rounded-xl border text-xs font-bold text-center transition-all bg-brand-600 text-white font-black border-brand-600 ring-2 ring-brand-400 shadow-md scale-105';
}

// Auto load if doctor was selected previously (e.g. after form validation error)
document.addEventListener('DOMContentLoaded', () => {
    const docSelect = document.getElementById('doctor_id');
    if (docSelect && docSelect.value) {
        loadDoctorSlots(docSelect.value);
    }
});
</script>

</div>
</body>
</html>
