-- SQLite Schema for Hospital Queue Management System
-- Provides standalone schema support when running on SQLite

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS doctors (
    doctor_id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    specialization TEXT NOT NULL,
    room_number TEXT,
    avg_service_time_in_minutes INTEGER NOT NULL DEFAULT 15
);

CREATE TABLE IF NOT EXISTS users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    role TEXT NOT NULL,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    doctor_id INTEGER REFERENCES doctors(doctor_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS patients (
    patient_id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    phone TEXT NOT NULL,
    age INTEGER,
    gender TEXT
);

CREATE TABLE IF NOT EXISTS queue_tokens (
    token_id INTEGER PRIMARY KEY AUTOINCREMENT,
    patient_id INTEGER NOT NULL REFERENCES patients(patient_id) ON DELETE CASCADE,
    doctor_id INTEGER NOT NULL REFERENCES doctors(doctor_id) ON DELETE CASCADE,
    token_number TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'Waiting',
    arrival_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    arrival_date DATE NOT NULL DEFAULT (DATE('now')),
    service_start_time TIMESTAMP NULL,
    service_end_time TIMESTAMP NULL,
    estimated_wait_time INTEGER NOT NULL DEFAULT 0,
    UNIQUE (token_number, arrival_date)
);

CREATE INDEX IF NOT EXISTS idx_queue_status ON queue_tokens(status);
CREATE INDEX IF NOT EXISTS idx_queue_date ON queue_tokens(arrival_time);
CREATE INDEX IF NOT EXISTS idx_patient_phone ON patients(phone);

-- Seed Doctors
INSERT OR IGNORE INTO doctors (doctor_id, name, specialization, room_number, avg_service_time_in_minutes) VALUES
(1, 'Dr. Smith', 'General OPD', 'Room 1', 15),
(2, 'Dr. Andrew', 'Cardiology', 'Room 2', 20),
(3, 'Dr. Shawn', 'Orthopedics', 'Room 3', 15);

-- Seed Default Staff Users
INSERT OR IGNORE INTO users (user_id, name, role, username, password_hash, doctor_id) VALUES
(1, 'Receptionist', 'Receptionist', 'reception', '$2y$10$/sDFv5eHuxx9qp73JfnPmezQK4yL1C5ExUJcqP9zR0Y/p3k1VOK7q', NULL),
(2, 'Dr. Smith', 'Doctor', 'dr.smith', '$2y$10$0vGh7K5ZMMd6QLbRRbTNjuWzWVtyC8Rpa254L4KiV51gg2kB0AOZ.', 1),
(3, 'Dr. Andrew', 'Doctor', 'dr.andrew', '$2y$10$ZiPeUz0W6WlpFa4gNbt1EuxkSZEIFAgjfT2tBkxsP6MwjtwomGxIS', 2),
(4, 'System Administrator', 'Admin', 'admin', '$2y$10$dsfbQKeFgFL7EWc0MMOcDe63IF/Taex/92e.UKqNxUcsQtIN6uBLO', NULL);
