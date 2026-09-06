-- MySQL Schema for Hospital Queue Management System

-- Drop existing tables if re-running
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS queue_tokens;
DROP TABLE IF EXISTS patients;
DROP TABLE IF EXISTS doctors;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- Users Table
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    role VARCHAR(20) NOT NULL, -- Receptionist, Doctor, Admin
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    doctor_id INT -- References doctors(doctor_id), added later
);

-- Doctors Table
CREATE TABLE doctors (
    doctor_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    specialization VARCHAR(100) NOT NULL,
    room_number VARCHAR(20),
    avg_service_time_in_minutes INT NOT NULL DEFAULT 15
);

-- Add foreign key now that doctors table exists
ALTER TABLE users ADD CONSTRAINT fk_user_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(doctor_id) ON DELETE SET NULL;

-- Patients Table
CREATE TABLE patients (
    patient_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(10) NOT NULL,
    age INT,
    gender VARCHAR(10)
);

-- Queue Tokens Table
CREATE TABLE queue_tokens (
    token_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT,
    doctor_id INT,
    token_number VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Waiting', -- Status: Waiting, In-Progress, Completed, No-Show, Cancelled
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

-- Insert real doctors matching the UI/login demo
INSERT INTO doctors (name, specialization, room_number, avg_service_time_in_minutes) VALUES
('Dr. Smith', 'General OPD', 'Room 1', 15),
('Dr. Andrew', 'Cardiology', 'Room 2', 20),
('Dr. Shawn', 'Orthopedics', 'Room 3', 15);

-- Insert real users matching the UI/login demo (passwords hashed)
-- recept123
INSERT INTO users (name, role, username, password_hash, doctor_id) VALUES
('Receptionist', 'Receptionist', 'reception', '$2y$10$/sDFv5eHuxx9qp73JfnPmezQK4yL1C5ExUJcqP9zR0Y/p3k1VOK7q', NULL);

-- smith123
INSERT INTO users (name, role, username, password_hash, doctor_id) VALUES
('Dr. Smith', 'Doctor', 'dr.smith', '$2y$10$0vGh7K5ZMMd6QLbRRbTNjuWzWVtyC8Rpa254L4KiV51gg2kB0AOZ.', 1);

-- andrew123
INSERT INTO users (name, role, username, password_hash, doctor_id) VALUES
('Dr. Andrew', 'Doctor', 'dr.andrew', '$2y$10$ZiPeUz0W6WlpFa4gNbt1EuxkSZEIFAgjfT2tBkxsP6MwjtwomGxIS', 2);

-- admin123
INSERT INTO users (name, role, username, password_hash, doctor_id) VALUES
('System Administrator', 'Admin', 'admin', '$2y$10$dsfbQKeFgFL7EWc0MMOcDe63IF/Taex/92e.UKqNxUcsQtIN6uBLO', NULL);
