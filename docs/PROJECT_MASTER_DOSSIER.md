# 🏥 CareFlow — Complete Project Master Dossier & Presentation Blueprint

> **Project Name:** CareFlow — Intelligent OPD Queue Management & Smart Appointment System  
> **Domain:** Healthcare Informatics / Operations Research & Automation  
> **Target Outcome:** Minor Project Defense, External Viva & Scopus/UGC-CARE Research Paper Framework  
> **Repository Stack:** PHP 8.2 (Functional/Procedural) • MySQL 8.0 (PDO) • Tailwind CSS • Vanilla JS AJAX  

---

## 1. Executive Summary & Problem Statement

### The Problem
Indian Outpatient Departments (OPD) in public and private hospitals face acute structural inefficiencies:
1. **Uncontrolled Physical Crowding:** Walk-in patients and appointment holders crowd corridors simultaneously without clear pacing.
2. **Asymmetric Information / High Patient Anxiety:** Patients have zero visibility into their actual turn order or the real consultation speed of their doctor.
3. **Doctor-Patient Friction & Idle Time:** Manual calling causes gaps between consultations, while unexpected surges exhaust clinical staff.
4. **Nosocomial Exposure:** Extended waiting in enclosed hospital waiting rooms significantly increases secondary cross-infection risks (violating UN Sustainable Development Goal 3: Good Health & Well-being).

### The Proposed Solution (CareFlow)
CareFlow is a lightweight, zero-dependency, full-stack hospital queue automation system that:
- **Unifies Two Streams:** Merges pre-booked appointment slots and on-spot walk-in patients into a single, conflict-free prioritized queue.
- **Dynamic Rolling Wait Algorithm:** Automatically measures real consultation lengths of completed patients today to compute dynamically updating wait estimates instead of relying on naive static averages.
- **Zero-Click Queue Promotion:** Eliminates manual "Call Next" operations—when a doctor clicks **Finish** or **Absent**, the database atomically promotes the next patient to **In-Progress** and updates waiting hall monitors in real-time.
- **Public Billboard Mode:** Live department-wise TV screen display that automatically shows current serving tokens and room numbers without staff intervention.

---

## 2. Technology Stack & Architectural Decisions

| Layer | Technology | Engineering Rationale |
| :--- | :--- | :--- |
| **Backend** | **PHP 8.2+** | Native session security, zero server compilation overhead, fast execution, procedural/functional design following minimal-code principles. |
| **Database** | **MySQL 8.0 (PDO Engine)** | Full ACID transaction compliance, Foreign Key cascades, prepared statement security against SQL Injection. |
| **Hosting & Infra** | **Hybrid Cloud + Local XAMPP** | Cloud hosting on **Render** (via lightweight Docker Apache container) with **TiDB Cloud Serverless MySQL** (SSL/TLS encrypted), alongside full offline capability on local **XAMPP MySQL** (`127.0.0.1:3306`). |
| **Frontend Styling** | **Tailwind CSS + Custom Glassmorphism** | Modern glass-card UI, dark/light theme toggle with zero page flash, responsive across mobile, desktop, and wall-mounted TV displays. |
| **Icons & Typography** | **Phosphor Icons + Outfit & Inter Fonts** | High-aesthetic vector icon set with clean sans-serif typography for maximum readability from a distance. |
| **Real-time Engine** | **Vanilla JS Asynchronous Polling (Fetch API)** | Silent AJAX background heartbeats every 10 seconds to update TV displays and doctor dashboards without full page reloads. |

---

## 3. Role-Based Access Control (System Actors)

```
                            ┌─────────────────────────────────────────┐
                            │                CAREFLOW                 │
                            └────────────────────┬────────────────────┘
                                                 │
        ┌──────────────────┬─────────────────────┴──────────────────┬──────────────────┐
        │                  │                                        │                  │
        ▼                  ▼                                        ▼                  ▼
┌──────────────┐   ┌──────────────┐                         ┌──────────────┐   ┌──────────────┐
│   PATIENT    │   │ RECEPTIONIST │                         │    DOCTOR    │   │    ADMIN     │
│ (Public/No   │   │ (Front Desk) │                         │ (OPD Cabin)  │   │ (Operations) │
│    Login)    │   │              │                         │              │   │              │
└──────────────┘   └──────────────┘                         └──────────────┘   └──────────────┘
```

### 1. Patient Portal (Public — No Login Required)
- **Book Appointment (`book_appointment.php`):** 
  - Selects doctor/department, chooses Today or Tomorrow.
  - Dynamically views available 15-minute time slots (enforcing 30-minute arrival travel buffer and capacity guards).
  - Receives unique Booking Reference (e.g. `BK-001`).
- **Check Status (`lookup.php`):**
  - Enters mobile number or token number to see live turn status, queue position ahead, room number, and dynamic wait time.
- **Live Waiting Lounge Display (`display.php`):**
  - Fullscreen billboard for waiting hall TVs showing **NOW SERVING**, Room Numbers, and Next in line.

### 2. Receptionist Portal (`reception / recept123`)
- **Spot Walk-in Registration:** Collects Name, Phone, Age, Gender, Department and prints instantaneous live token (e.g. `GEN-001`, `CAR-001`, `ORT-001`).
- **Convert Booking Tab:** Search/verify pre-booked reference (`BK-XXX`) and convert into an active queue token upon physical arrival.

### 3. Doctor Cabin Dashboard (`dr.rajesh`, `dr.ananya`, `dr.vikram`)
- Single, high-focus banner: **"Patients In Queue"** showing exact pending count and queue health.
- Active Consultation Card: Displays patient demographic info, started time (IST), and simple 1-click actions:
  - **Finish:** Marks current consultation Completed with `service_end_time = NOW()` and atomically promotes the next patient.
  - **Absent:** Marks patient No-Show and instantly calls the next patient.
- Master queue table showing consultation history with clean `Active Now` indicators.

### 4. Administrator Portal (`admin / admin123`)
- Hospital-wide master overview across all OPD departments.
- Real-time aggregation cards: Total Patients Today, Waiting, In-Progress, and Completed.
- **Export CSV Engine (`export.php`):** Generates full research-grade audit data with timestamps for operations analysis.

---

## 4. End-to-End System Workflow (Lifecycle of a Patient)

```
[Patient Books Online]  ──>  Status: 'Booked' (Token: NULL, Ref: BK-001)
                                      │
                                      ▼ [Patient physically arrives at Clinic]
[Walk-in Arrives]       ──>  [Reception Desk Check-in]
        │                             │
        └─────────────────────────────┼──────────────────────────────┐
                                      ▼                              ▼
                          Status: 'Waiting'             (If Doctor Free NOW)
                          Token: GEN-001 / CAR-001      Status: 'In-Progress'
                                      │                 Token: NOW SERVING
                                      ▼                              │
                          [Waiting Room TV Display]                  │
                                      │                              ▼
                                      ▼                    [Doctor Consultation]
                          [Doctor Finishes Current]                  │
                                      │                              ▼
                                      └──────────────────>   Click "Finish"
                                                                     │
                                                                     ▼
                                                             Status: 'Completed'
                                                             Next Patient Auto-Promoted
```

---

## 5. Core Algorithmic Innovations

### A. Dynamic Rolling Wait-Time Algorithm (`calculateDynamicWait()`)
Standard systems use static multiplication (`Patients Ahead × 15 min`). If a doctor takes 25 minutes per patient, the static formula misleads everyone. 

CareFlow calculates dynamic wait using today's completed patient performance:
1. Queries all completed consultations today for that specific doctor:
   $$\text{Duration}_i = \text{service\_end\_time}_i - \text{service\_start\_time}_i \quad (\text{in minutes})$$
2. Computes the **Effective Average Service Time** ($\overline{S}$):
   $$\overline{S} = \frac{1}{N} \sum_{i=1}^{N} \text{Duration}_i$$
   *(If $N = 0$, falls back to doctor's contractual average, e.g. 15 or 20 mins).*
3. Multiplies by active waiting queue count ($W$):
   $$\text{Total Wait Time} = \overline{S} \times W$$

### B. Shift-End Over-Capacity Protection & Buffer Guard
- **30-Minute Transit Buffer:** For same-day appointments, slots within the next 30 minutes are locked out so patients do not book slots they cannot physically reach.
- **12:00 PM Shift-End Capacity Guard:** If current time is $\ge$ 12:00 PM and:
  $$\text{Current Time} + (\text{Waiting Patients} \times \text{Avg Service Time}) \ge \text{Morning End Time (01:00 PM)}$$
  The system marks Morning OPD full and automatically routes patient bookings to the **Evening Session (05:00 PM – 08:00 PM)**.
- **Evening Reserved Slots:** The first 2 slots of evening OPD (05:00 PM & 05:15 PM) are reserved for hospital emergency/walk-in spillover.

### C. Atomic Auto-Promotion Engine (`autoPromoteNextPatient()`)
To ensure zero double-booking and prevent race conditions:
1. Opens atomic transaction: `$pdo->beginTransaction()`.
2. Verifies doctor has no `In-Progress` patient today.
3. Retrieves next eligible patient prioritized by effective time:
   ```sql
   SELECT token_id FROM queue_tokens 
   WHERE doctor_id = ? AND status = 'Waiting' AND token_number IS NOT NULL
   ORDER BY COALESCE(scheduled_time, arrival_time) ASC 
   LIMIT 1;
   ```
4. Atomically updates status to `In-Progress` and sets `service_start_time = NOW()` in Indian Standard Time (IST).
5. Commits transaction safely.

---

## 6. Database Schema Design (Normalized Structure)

### Table 1: `users`
| Column | Type | Constraints / Purpose |
| :--- | :--- | :--- |
| `user_id` | INT (PK) | AUTO_INCREMENT |
| `name` | VARCHAR(100) | Full Name |
| `role` | VARCHAR(20) | `Admin`, `Doctor`, `Receptionist` |
| `username` | VARCHAR(50) | UNIQUE username |
| `password_hash` | VARCHAR(255) | Hashed via `password_hash(..., PASSWORD_DEFAULT)` |
| `doctor_id` | INT (FK) | References `doctors(doctor_id)` ON DELETE SET NULL |

### Table 2: `doctors`
| Column | Type | Constraints / Purpose |
| :--- | :--- | :--- |
| `doctor_id` | INT (PK) | AUTO_INCREMENT |
| `name` | VARCHAR(100) | e.g. Dr. Rajesh Sharma |
| `specialization` | VARCHAR(100) | `General OPD`, `Cardiology`, `Orthopedics` |
| `room_number` | VARCHAR(20) | e.g. Room 1, Room 2, Room 3 |
| `avg_service_time_in_minutes` | INT | Base consultation duration (default: 15) |
| `working_start_time` | TIME | Morning OPD Start (`09:00:00`) |
| `working_end_time` | TIME | Morning OPD End (`13:00:00`) |
| `evening_start_time` | TIME | Evening OPD Start (`17:00:00`) |
| `evening_end_time` | TIME | Evening OPD End (`20:00:00`) |
| `booking_slot_percentage` | INT | Capacity threshold (default: 70%) |

### Table 3: `patients`
| Column | Type | Constraints / Purpose |
| :--- | :--- | :--- |
| `patient_id` | INT (PK) | AUTO_INCREMENT |
| `name` | VARCHAR(100) | Patient Full Name |
| `phone` | VARCHAR(10) | Validated 10-digit mobile number |
| `age` | INT | 1 to 150 years |
| `gender` | VARCHAR(10) | `Male`, `Female` |

### Table 4: `queue_tokens`
| Column | Type | Constraints / Purpose |
| :--- | :--- | :--- |
| `token_id` | INT (PK) | AUTO_INCREMENT |
| `patient_id` | INT (FK) | References `patients` ON DELETE CASCADE |
| `doctor_id` | INT (FK) | References `doctors` ON DELETE CASCADE |
| `token_number` | VARCHAR(20) | e.g. `GEN-001`, `CAR-001` (NULL until check-in) |
| `booking_ref` | VARCHAR(20) | e.g. `BK-001` (for online bookings) |
| `booking_type` | VARCHAR(20) | `Walk-in` or `Pre-Booked` |
| `scheduled_time` | DATETIME | Selected appointment slot |
| `status` | VARCHAR(20) | `Waiting`, `In-Progress`, `Completed`, `No-Show`, `Cancelled`, `Booked` |
| `arrival_time` | TIMESTAMP | Physical check-in timestamp (IST) |
| `arrival_date` | DATE | Date of consultation |
| `service_start_time` | TIMESTAMP | Doctor started consultation |
| `service_end_time` | TIMESTAMP | Doctor finished consultation |
| `estimated_wait_time` | INT | Computed wait minutes at check-in |
| **INDEX / UNIQUE** | - | `UNIQUE (token_number, arrival_date)` |

---

## 7. Presentation Slide-by-Slide Blueprint (PPT Guide)

### Slide 1: Title & Identity
- **Title:** CareFlow: Intelligent OPD Queue Management & Smart Appointment System
- **Subtitle:** Operational Optimization & Real-Time Queue Automation in Healthcare
- **Author:** [Your Name / Roll No / College Name]
- **Supervisor:** [Mentor Name / Designation]

### Slide 2: Industry Problem & Motivation
- Physical crowding in OPD waiting areas leads to high stress and cross-infection risks.
- Disconnected online booking vs walk-in queues causing doctor idle time and queue conflicts.
- Lack of real-time wait duration visibility for visiting patients.

### Slide 3: Proposed Architecture & Core Objectives
- Blend online appointment slots with spot walk-ins into a single atomic queue.
- Implement dynamic rolling wait times based on live doctor consultation pacing.
- Zero-click queue promotion to eliminate manual calling delays.
- Public digital billboard for waiting lounges.

### Slide 4: System Architecture Diagram
- Visual flow: Public Patients ➡️ Booking/Lookup Portal ➡️ Reception Check-in Desk ➡️ MySQL 8.0 Central Engine ➡️ Doctor Cabin Dashboard ➡️ Lounge TV Screen.

### Slide 5: Mathematical Modeling & Algorithms
- Formula for Dynamic Rolling Wait Time ($\overline{S} \times W$).
- Shift-End Capacity Guard (12:00 PM cutoff to protect 1:00 PM OPD shift end).
- Effective Time Priority Sequence: `ORDER BY COALESCE(scheduled_time, arrival_time) ASC`.

### Slide 6: Departmental & Clinical Portals
- **Front Desk:** Walk-in token generator and 1-click booking converter.
- **Doctor Cabin:** Minimalist dashboard with single "Patients In Queue" badge and 1-click consultation completion.
- **Waiting Hall:** Standalone high-contrast TV billboard.

### Slide 7: Security & Data Integrity
- Prepared statements via PHP PDO for 100% SQL injection immunity.
- Password hashing using standard `bcrypt` algorithms.
- Complete session timezone synchronization locked to Indian Standard Time (`+05:30`).

### Slide 8: Research & Publication Value
- Built-in `export.php` analytics pipeline capturing exact timestamps (`arrival_time`, `service_start_time`, `service_end_time`).
- Direct correlation with **UN Sustainable Development Goal 3 (Good Health and Well-being)** by proving statistically significant wait-time reduction via two-sample T-Test ($p < 0.05$).

### Slide 9: Live Screenshots & Demo Highlights
- Screenshots of TV Display (`display.php`), Doctor Consultation Dashboard (`dashboard.php`), Front Desk Check-in (`checkin.php`), and Patient Booking (`book_appointment.php`).

### Slide 10: Conclusion & Future Enhancements
- Summary of efficiency gains.
- Future Scope: SMS/WhatsApp gateway integration, multi-counter pharmacy synchronization, doctor leave calendar.

---

## 8. Master Viva & Mentor Defense Q&A

**Q1: What happens if the internet goes down in the hospital?**  
> *"Sir, CareFlow is architected with a hybrid model. While deployed on Render + TiDB Cloud for public access, it runs natively on local XAMPP within the hospital's local area network (LAN), functioning 100% offline without internet dependency."*

**Q2: How do you prevent race conditions when multiple patients generate tokens simultaneously?**  
> *"We enforce transactional row locking using `$pdo->beginTransaction()` and commit patterns. Furthermore, the `queue_tokens` table enforces a strict database constraint: `UNIQUE (token_number, arrival_date)`, making duplicate token issuance mathematically impossible."*

**Q3: Between a walk-in patient and a pre-booked patient, who gets promoted first?**  
> *"CareFlow employs an Effective Timestamp sorting policy: `ORDER BY COALESCE(scheduled_time, arrival_time) ASC`. Pre-booked appointments that have checked in take priority for their designated time window, while walk-in patients are sequenced strictly by their arrival time."*

**Q4: How does your wait-time calculation differ from traditional hospital software?**  
> *"Traditional software uses a static multiplier (e.g. 15 mins per patient). CareFlow dynamically measures the actual elapsed time between `service_start_time` and `service_end_time` for all patients completed today, recalculating the doctor's real-time velocity to display accurate wait estimates."*

**Q5: Why did you eliminate the 'Start Service' button on the Doctor Dashboard?**  
> *"In real clinical operations, requiring a doctor to click 'Start', 'Finish', and 'Call Next' for every patient creates cognitive friction. CareFlow automates this: when the doctor clicks 'Finish' on patient $N$, patient $N+1$ is immediately promoted to 'In-Progress' and their consultation clock starts automatically."*
