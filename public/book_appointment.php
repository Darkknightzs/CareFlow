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
    } elseif (!in_array($gender, ['Male', 'Female'])) {
        $error = "Please select a valid gender (Male or Female).";
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

<div class="w-full max-w-2xl mx-auto py-4">

    <?php if ($booking_success): ?>
        <!-- Booking Confirmation Screen -->
        <div class="card p-6 sm:p-8 text-center max-w-lg mx-auto shadow-xs">
            <div class="w-12 h-12 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mx-auto mb-3 border border-emerald-200 dark:border-emerald-800">
                <i class="ph-bold ph-check text-2xl"></i>
            </div>
            
            <span class="inline-block py-0.5 px-2.5 rounded text-[10px] font-semibold uppercase tracking-wider bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 mb-1">
                Appointment Confirmed
            </span>
            <h2 class="text-xl font-bold text-slate-900 dark:text-white tracking-tight">Booking Successful</h2>
            <p class="text-slate-500 dark:text-slate-400 text-xs mt-0.5">Your OPD appointment slot is reserved</p>

            <!-- Booking Card -->
            <div class="bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4 rounded-xl my-5 text-left">
                <div class="flex justify-between items-start border-b border-slate-200 dark:border-slate-800 pb-3 mb-3">
                    <div>
                        <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-400 block">Booking Reference</span>
                        <span class="text-2xl font-bold font-mono text-blue-600 dark:text-blue-400"><?= htmlspecialchars($booking_success['booking_ref']) ?></span>
                    </div>
                    <div class="text-right">
                        <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-400 block">Scheduled Time</span>
                        <span class="text-base font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($booking_success['scheduled_time']) ?></span>
                        <span class="text-xs text-slate-500 block"><?= htmlspecialchars($booking_success['date']) ?></span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <span class="text-slate-400 text-[10px] block uppercase font-medium">Patient</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($booking_success['patient_name']) ?></span>
                    </div>
                    <div>
                        <span class="text-slate-400 text-[10px] block uppercase font-medium">Phone</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($booking_success['phone']) ?></span>
                    </div>
                    <div>
                        <span class="text-slate-400 text-[10px] block uppercase font-medium">Doctor</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($booking_success['doctor_name']) ?></span>
                    </div>
                    <div>
                        <span class="text-slate-400 text-[10px] block uppercase font-medium">Department</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200"><?= htmlspecialchars($booking_success['department']) ?> (<?= htmlspecialchars($booking_success['room_number']) ?>)</span>
                    </div>
                </div>
            </div>

            <!-- Reception Notice -->
            <div class="p-3 rounded-lg bg-blue-50/60 dark:bg-slate-800/80 border border-blue-200/80 dark:border-slate-700 text-xs text-slate-600 dark:text-slate-300 text-left mb-5">
                <p class="font-semibold text-slate-900 dark:text-white mb-0.5">Physical Check-in Required:</p>
                <p>Please arrive 10 minutes prior to your slot and present reference <strong><?= htmlspecialchars($booking_success['booking_ref']) ?></strong> at reception to receive your live queue token.</p>
            </div>

            <div class="flex gap-2.5 justify-center">
                <a href="lookup.php?phone=<?= urlencode($booking_success['phone']) ?>&token_number=<?= urlencode($booking_success['booking_ref']) ?>" class="btn-primary py-2 px-4 text-xs flex items-center gap-1.5">
                    <i class="ph ph-magnifying-glass"></i> Check Status
                </a>
                <a href="book_appointment.php" class="bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-semibold py-2 px-4 rounded-lg text-xs transition-colors flex items-center gap-1.5">
                    <i class="ph ph-plus"></i> Book Another
                </a>
            </div>
        </div>

    <?php else: ?>
        <!-- Booking Form Screen -->
        <div class="card p-6 sm:p-7 shadow-xs">
            <div class="mb-5">
                <h2 class="text-xl font-bold text-slate-900 dark:text-white tracking-tight">Book OPD Appointment</h2>
                <p class="text-slate-500 dark:text-slate-400 text-xs mt-0.5">
                    Select a specialist, reserve an appointment slot, and receive an instant booking slip
                </p>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 p-2.5 mb-4 rounded-lg flex items-center gap-2 text-xs">
                    <i class="ph-bold ph-warning-circle text-base flex-shrink-0"></i>
                    <p class="font-medium"><?= htmlspecialchars($error) ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" id="bookingForm" class="space-y-4">

                <!-- Step 1: Select Specialist / Department -->
                <div>
                    <label for="doctor_id" class="form-label">1. Specialist Department</label>
                    <div class="relative">
                        <i class="ph ph-stethoscope absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                        <select id="doctor_id" name="doctor_id" required class="form-input appearance-none" onchange="loadDoctorSlots(this.value)">
                            <option value="" disabled selected>Choose department specialist...</option>
                            <?php foreach ($doctors as $d): ?>
                                <option value="<?= $d['doctor_id'] ?>" <?= (isset($_POST['doctor_id']) && $_POST['doctor_id'] == $d['doctor_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['specialization']) ?> — <?= htmlspecialchars($d['name']) ?> (<?= htmlspecialchars($d['room_number']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <i class="ph ph-caret-down absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-xs"></i>
                    </div>
                </div>

                <!-- Step 2: Slot Selection Container -->
                <div id="slotsSection" class="p-3.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/40 <?= empty($_POST['doctor_id']) ? 'hidden' : '' ?>">
                    <div class="flex justify-between items-center mb-3">
                        <label class="form-label !mb-0">2. Select Time Slot</label>
                        <div class="flex items-center gap-1">
                            <button type="button" onclick="changeDate('<?= date('Y-m-d') ?>')" id="dateBtnToday" class="px-2.5 py-1 rounded text-[11px] font-semibold border <?= $active_booking_date === date('Y-m-d') ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700' ?>">
                                Today (<?= date('M d') ?>)
                            </button>
                            <button type="button" onclick="changeDate('<?= date('Y-m-d', strtotime('+1 day')) ?>')" id="dateBtnTomorrow" class="px-2.5 py-1 rounded text-[11px] font-semibold border <?= $active_booking_date === date('Y-m-d', strtotime('+1 day')) ? 'bg-blue-600 text-white border-blue-600' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700' ?>">
                                Tomorrow (<?= date('M d', strtotime('+1 day')) ?>)
                            </button>
                        </div>
                    </div>

                    <input type="hidden" id="booking_date" name="booking_date" value="<?= htmlspecialchars($active_booking_date) ?>">
                    <input type="hidden" id="slot_time" name="slot_time" value="<?= htmlspecialchars($_POST['slot_time'] ?? '') ?>" required>

                    <div id="slotsContainer" class="py-1">
                        <div class="text-center py-4 text-slate-400 text-xs">
                            Select a doctor above to load open slots
                        </div>
                    </div>

                    <div class="mt-2.5 pt-2 border-t border-slate-200 dark:border-slate-800 text-[11px] text-slate-400">
                        OPD Hours: Morning 9:00 AM – 1:00 PM &bull; Evening 5:00 PM – 8:00 PM
                    </div>
                </div>

                <!-- Step 3: Patient Information -->
                <div class="space-y-3 pt-1">
                    <label class="form-label">3. Patient Details</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <div class="relative">
                                <i class="ph ph-user absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <input type="text" id="name" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" class="form-input" placeholder="Full name">
                            </div>
                        </div>

                        <div>
                            <div class="relative">
                                <i class="ph ph-phone absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <input type="tel" id="phone" name="phone" pattern="[0-9]{10}" required value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" class="form-input" placeholder="10-digit mobile">
                            </div>
                        </div>

                        <div>
                            <input type="number" id="age" name="age" min="1" max="150" required value="<?= htmlspecialchars($_POST['age'] ?? '') ?>" class="form-input !pl-3" placeholder="Age (years)">
                        </div>

                        <div>
                            <div class="relative">
                                <select id="gender" name="gender" required class="form-input !pl-3 appearance-none">
                                    <option value="" disabled selected>Select gender</option>
                                    <option value="Male" <?= (isset($_POST['gender']) && $_POST['gender'] === 'Male') ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= (isset($_POST['gender']) && $_POST['gender'] === 'Female') ? 'selected' : '' ?>>Female</option>
                                </select>
                                <i class="ph ph-caret-down absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none text-xs"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="pt-2">
                    <button type="submit" id="submitBtn" class="btn-primary w-full py-2.5 text-xs font-semibold flex justify-center items-center gap-1.5">
                        <i class="ph-bold ph-calendar-check text-xs"></i>
                        <span>Confirm Appointment</span>
                    </button>
                    <p class="text-center text-slate-400 text-[11px] mt-2">
                        Walk-in patients without pre-booking are also welcomed directly at reception during OPD hours.
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

function loadDoctorSlots(doctorId) {
    if (!doctorId) return;
    
    const slotsSection = document.getElementById('slotsSection');
    const container = document.getElementById('slotsContainer');
    
    slotsSection.classList.remove('hidden');
    container.innerHTML = `
        <div class="text-center py-6 text-brand-600 dark:text-brand-400">
            <i class="ph ph-spinner animate-spin text-2xl mb-1"></i>
            <p class="text-xs font-semibold">Calculating open slots...</p>
        </div>
    `;

    fetch(`book_appointment.php?action=get_slots&doctor_id=${doctorId}&date=${currentTargetDate}`)
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
            ? 'bg-blue-600 text-white font-bold border-blue-600 ring-2 ring-blue-300 dark:ring-blue-800 shadow-sm scale-[1.02]'
            : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border-slate-200 dark:border-slate-700 hover:border-blue-500 hover:bg-blue-50/50 dark:hover:bg-slate-700/80';

        return `
            <button type="button" onclick="selectSlot('${s.time_24}', this)" class="slot-btn py-2 px-1.5 rounded-lg border text-xs font-semibold text-center transition-all ${btnClass}">
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
        btn.className = 'slot-btn py-2 px-1.5 rounded-lg border text-xs font-semibold text-center transition-all bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border-slate-200 dark:border-slate-700 hover:border-blue-500 hover:bg-blue-50/50 dark:hover:bg-slate-700/80';
    });

    // Highlight selected button
    btnElem.className = 'slot-btn py-2 px-1.5 rounded-lg border text-xs font-semibold text-center transition-all bg-blue-600 text-white font-bold border-blue-600 ring-2 ring-blue-300 dark:ring-blue-800 shadow-sm scale-[1.02]';
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
