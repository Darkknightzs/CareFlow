-- MySQL Schema for Hospital Queue Management System
-- Safe schema initialization without DROP TABLE

-- Users Table
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    role VARCHAR(20) NOT NULL, -- Receptionist, Doctor, Admin
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    doctor_id INT -- References doctors(doctor_id), added later
);

-- Doctors Table
CREATE TABLE IF NOT EXISTS doctors (
    doctor_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    specialization VARCHAR(100) NOT NULL,
    room_number VARCHAR(20),
    avg_service_time_in_minutes INT NOT NULL DEFAULT 15,
    working_start_time TIME NOT NULL DEFAULT '09:00:00',
    working_end_time TIME NOT NULL DEFAULT '13:00:00',
    evening_start_time TIME NULL DEFAULT '17:00:00',
    evening_end_time TIME NULL DEFAULT '20:00:00',
    booking_slot_percentage INT NOT NULL DEFAULT 70
);

-- Patients Table
CREATE TABLE IF NOT EXISTS patients (
    patient_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(10) NOT NULL,
    age INT,
    gender VARCHAR(10)
);

-- Queue Tokens Table
CREATE TABLE IF NOT EXISTS queue_tokens (
    token_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT,
    doctor_id INT,
    token_number VARCHAR(20) NULL,
    booking_ref VARCHAR(20) NULL,
    booking_type VARCHAR(20) NOT NULL DEFAULT 'Walk-in',
    scheduled_time DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Waiting',
    arrival_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    arrival_date DATE NOT NULL,
    service_start_time TIMESTAMP NULL,
    service_end_time TIMESTAMP NULL,
    estimated_wait_time INT NOT NULL DEFAULT 0,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES doctors(doctor_id) ON DELETE CASCADE,
    CONSTRAINT unique_token_per_day UNIQUE (token_number, arrival_date)
);

-- Indexes for faster lookups
CREATE INDEX idx_queue_status ON queue_tokens(status);
CREATE INDEX idx_queue_date ON queue_tokens(arrival_time);
CREATE INDEX idx_patient_phone ON patients(phone);
CREATE INDEX idx_booking_ref ON queue_tokens(booking_ref);
CREATE INDEX idx_scheduled_time ON queue_tokens(scheduled_time);

-- Insert real doctors matching the UI/login demo
INSERT INTO doctors (name, specialization, room_number, avg_service_time_in_minutes) VALUES
('Dr. Rajesh Sharma', 'General OPD', 'Room 1', 15),
('Dr. Ananya Mukherjee', 'Cardiology', 'Room 2', 20),
('Dr. Vikram Verma', 'Orthopedics', 'Room 3', 15);

-- Insert real users matching the UI/login demo (passwords hashed)
-- recept123
INSERT INTO users (name, role, username, password_hash, doctor_id) VALUES
('Receptionist', 'Receptionist', 'reception', '$2y$10$/sDFv5eHuxx9qp73JfnPmezQK4yL1C5ExUJcqP9zR0Y/p3k1VOK7q', NULL);

-- rajesh123 (and alias dr.rajesh / dr.smith)
INSERT INTO users (name, role, username, password_hash, doctor_id) VALUES
('Dr. Rajesh Sharma', 'Doctor', 'dr.rajesh', '$2y$10$0vGh7K5ZMMd6QLbRRbTNjuWzWVtyC8Rpa254L4KiV51gg2kB0AOZ.', 1);

-- ananya123 (and alias dr.ananya / dr.andrew)
INSERT INTO users (name, role, username, password_hash, doctor_id) VALUES
('Dr. Ananya Mukherjee', 'Doctor', 'dr.ananya', '$2y$10$ZiPeUz0W6WlpFa4gNbt1EuxkSZEIFAgjfT2tBkxsP6MwjtwomGxIS', 2);

-- vikram123 (Dr. Vikram Verma)
INSERT INTO users (name, role, username, password_hash, doctor_id) VALUES
('Dr. Vikram Verma', 'Doctor', 'dr.vikram', '$2y$10$58.e66F/pC2zD9W4s/zH5uP1s1uU904XW8y9Q8i2g1h3j4k5l6m7n', 3);

-- admin123
INSERT INTO users (name, role, username, password_hash, doctor_id) VALUES
('System Administrator', 'Admin', 'admin', '$2y$10$dsfbQKeFgFL7EWc0MMOcDe63IF/Taex/92e.UKqNxUcsQtIN6uBLO', NULL);
