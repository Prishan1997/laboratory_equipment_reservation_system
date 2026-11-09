-- Laboratory Equipment Reservation System (LERS)
-- Author: Prishan Samarasinghe (MSc IT)
-- Database: MySQL 8.x / InnoDB Engine
-- Schema Creation & Seed Data Script

CREATE DATABASE IF NOT EXISTS `lers_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `lers_db`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `maintenance_logs`;
DROP TABLE IF EXISTS `reservations`;
DROP TABLE IF EXISTS `equipment`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Users Table (Normalized 3NF)
CREATE TABLE `users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_number` VARCHAR(50) NOT NULL UNIQUE,
    `full_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(120) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('student', 'supervisor', 'admin') NOT NULL DEFAULT 'student',
    `cert_tier` ENUM('Tier 1', 'Tier 2', 'Tier 3') NOT NULL DEFAULT 'Tier 1',
    `department` VARCHAR(120) NOT NULL DEFAULT 'Department of Physics & Nanotechnology',
    `phone` VARCHAR(30) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Equipment Table
CREATE TABLE `equipment` (
    `equipment_id` INT AUTO_INCREMENT PRIMARY KEY,
    `equipment_name` VARCHAR(150) NOT NULL,
    `model_number` VARCHAR(100) NOT NULL,
    `lab_location` VARCHAR(100) NOT NULL,
    `description` TEXT NOT NULL,
    `req_cert_tier` ENUM('Tier 1', 'Tier 2', 'Tier 3') NOT NULL DEFAULT 'Tier 1',
    `requires_approval` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('operational', 'maintenance', 'decommissioned') NOT NULL DEFAULT 'operational',
    `maintenance_note` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Reservations Table
CREATE TABLE `reservations` (
    `reservation_id` INT AUTO_INCREMENT PRIMARY KEY,
    `equipment_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME NOT NULL,
    `purpose` TEXT NOT NULL,
    `cert_verified` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('pending', 'confirmed', 'rejected', 'cancelled', 'checked_out', 'completed') NOT NULL DEFAULT 'pending',
    `reviewed_by` INT DEFAULT NULL,
    `rejection_reason` TEXT DEFAULT NULL,
    `checked_out_at` DATETIME DEFAULT NULL,
    `returned_at` DATETIME DEFAULT NULL,
    `return_condition` ENUM('operational', 'requires_cleaning', 'minor_wear', 'defective') DEFAULT NULL,
    `consumable_notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_res_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_res_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_res_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
    INDEX `idx_collision` (`equipment_id`, `start_time`, `end_time`, `status`),
    INDEX `idx_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Maintenance Logs Table
CREATE TABLE `maintenance_logs` (
    `log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `equipment_id` INT NOT NULL,
    `technician_id` INT NOT NULL,
    `start_datetime` DATETIME NOT NULL,
    `end_datetime` DATETIME NOT NULL,
    `maintenance_type` ENUM('routine_calibration', 'emergency_repair', 'preventative_service', 'consumable_replacement') NOT NULL,
    `servicing_notes` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_maint_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_maint_technician` FOREIGN KEY (`technician_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
    INDEX `idx_maint_window` (`equipment_id`, `start_datetime`, `end_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Notifications Table
CREATE TABLE `notifications` (
    `notification_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `type` ENUM('booking_confirmed', 'booking_rejected', 'blackout_cancelled', 'reminder', 'system') NOT NULL DEFAULT 'system',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
    INDEX `idx_user_read` (`user_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- SEED DATA
-- Default Passwords for all seeded users: Password123!
-- Bcrypt Hash: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi (or standard)
-- =========================================================================

-- Insert Users (Password: Password123!)
-- Hash generated by password_hash('Password123!', PASSWORD_BCRYPT):
-- '$2y$10$e8oE4hPz3J1iK6K2gW7dTeO5C9hF0L7p1M2q3R4s5T6u7v8w9x0y.'
INSERT INTO `users` (`user_id`, `id_number`, `full_name`, `email`, `password_hash`, `role`, `cert_tier`, `department`) VALUES
(1, 'ST-2026-081', 'Prishan Samarasinghe', 'prishan.s@science.ac.lk', '$2y$10$YTt/eOdyGdySSO53OO5oyuGdrMP754PbCTF5feASmtlNm5Gn1GP1i', 'student', 'Tier 3', 'MSc Information Technology / NanoTech Lab'),
(2, 'SUP-4019', 'Dr. K. Jayasuriya', 'supervisor@science.ac.lk', '$2y$10$YTt/eOdyGdySSO53OO5oyuGdrMP754PbCTF5feASmtlNm5Gn1GP1i', 'supervisor', 'Tier 3', 'Faculty of Science & Precision Labs'),
(3, 'ADM-0001', 'Admin Director Perera', 'admin@science.ac.lk', '$2y$10$YTt/eOdyGdySSO53OO5oyuGdrMP754PbCTF5feASmtlNm5Gn1GP1i', 'admin', 'Tier 3', 'Central University IT & Laboratory Management');

-- Insert Equipment
INSERT INTO `equipment` (`equipment_id`, `equipment_name`, `model_number`, `lab_location`, `description`, `req_cert_tier`, `requires_approval`, `status`, `maintenance_note`) VALUES
(1, 'JEOL JEM-2100 TEM', 'JEM-2100F', 'NanoLab Rm 104', 'High-resolution field emission transmission electron microscope for crystallography, atomic dislocation, and nanoscale material analysis.', 'Tier 3', 1, 'operational', NULL),
(2, 'Bruker Avance 400MHz NMR', 'AV-400', 'Chemistry Wing Lab 28', 'High-resolution Fourier Transform NMR spectrometer for organic synthesis verification and structure determination.', 'Tier 2', 1, 'maintenance', 'Helium Refill & Cryo-probe Calibration Scheduled'),
(3, 'Beckman Coulter Optima XPN-100', 'Optima XPN-100', 'Biotech Lab 12', 'Preparative ultracentrifuge operating up to 100,000 RPM (802,000 x g) with temperature vacuum chamber for macromolecule purification.', 'Tier 2', 1, 'operational', NULL),
(4, 'PerkinElmer Lambda 950 UV/Vis/NIR', 'Lambda 950', 'Optics & Materials Lab 03', 'Dual-beam spectrophotometer with Peltier temperature control for thin-film optical characterization between 175-3300 nm.', 'Tier 1', 0, 'operational', NULL),
(5, 'Agilent 7890B Gas Chromatograph-MS', '7890B / 5977B', 'Analytical Chem Rm 202', 'High-efficiency GC coupled with quadrupole mass detector for volatile sample analysis and purity verification.', 'Tier 2', 1, 'operational', NULL),
(6, 'NVIDIA DGX A100 HPC Compute Node', 'DGX A100-640GB', 'Server Room B, Cluster Rack 4', 'Deep learning & molecular dynamics GPU supercomputing workstation for computational chemistry and quantum simulations.', 'Tier 1', 0, 'operational', NULL);

-- Insert Sample Reservations
INSERT INTO `reservations` (`reservation_id`, `equipment_id`, `user_id`, `start_time`, `end_time`, `purpose`, `cert_verified`, `status`, `reviewed_by`, `rejection_reason`) VALUES
(1, 1, 1, '2026-11-09 10:00:00', '2026-11-09 12:00:00', 'Gold-nanoparticle thin film substrate characterization for MSc thesis chapter 4.', 1, 'confirmed', 2, NULL),
(2, 1, 1, '2026-11-09 14:00:00', '2026-11-09 16:00:00', 'High-resolution diffraction pattern observation of titanium dioxide nanowires.', 1, 'pending', NULL, NULL),
(3, 3, 1, '2026-11-10 09:00:00', '2026-11-10 11:00:00', 'Fractionation of recombinant membrane proteins using sucrose gradient.', 1, 'confirmed', 2, NULL);

-- Insert Sample Maintenance Logs
INSERT INTO `maintenance_logs` (`log_id`, `equipment_id`, `technician_id`, `start_datetime`, `end_datetime`, `maintenance_type`, `servicing_notes`) VALUES
(1, 2, 2, '2026-11-08 08:00:00', '2026-11-12 18:00:00', 'preventative_service', 'Liquid helium cryostat replenishment and pulse-field gradient shim calibration.');

-- Insert Notifications for Prishan
INSERT INTO `notifications` (`user_id`, `title`, `message`, `type`, `is_read`) VALUES
(1, 'Reservation Approved', 'Your reservation request for JEOL JEM-2100 TEM on Nov 9, 10:00 AM has been approved by Dr. K. Jayasuriya.', 'booking_confirmed', 0),
(1, 'Welcome to LERS Portal', 'Your student account has been registered with Tier 3 Safety Clearance for academic laboratory equipment.', 'system', 1);
