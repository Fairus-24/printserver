CREATE DATABASE IF NOT EXISTS `printserver`
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE `printserver`;

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nim_nipy` VARCHAR(30) NOT NULL UNIQUE,
    `full_name` VARCHAR(120) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` VARCHAR(30) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `print_jobs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `owner_user_id` INT UNSIGNED NOT NULL,
    `owner_nim_nipy` VARCHAR(30) NOT NULL,
    `owner_name` VARCHAR(120) NOT NULL,
    `stored_filename` VARCHAR(255) NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `hide_filename` TINYINT(1) NOT NULL DEFAULT 0,
    `print_mode` VARCHAR(15) NOT NULL DEFAULT 'color',
    `status` VARCHAR(20) NOT NULL DEFAULT 'ready',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `printed_at` DATETIME DEFAULT NULL,
    `deleted_at` DATETIME DEFAULT NULL,
    `last_error` VARCHAR(500) DEFAULT NULL,
    INDEX `idx_print_jobs_owner_status` (`owner_user_id`, `status`),
    INDEX `idx_print_jobs_status_created` (`status`, `created_at`),
    INDEX `idx_print_jobs_deleted_at` (`deleted_at`),
    INDEX `idx_print_jobs_filename` (`stored_filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`nim_nipy`, `full_name`, `password_hash`, `role`, `is_active`)
VALUES
    ('23123456', 'Mahasiswa Dummy', '$2y$12$L8sRwhNqdxM1BDnageRMN.UY/0m13Zh70vKLzUhuGXfW6.SoJy9J.', 'mahasiswa', 1),
    ('1987654321', 'Dosen Dummy', '$2y$12$vzadVHolVyEOtKVrcwQSNOjHUcUYfRdB9WlPA0sxY1eOF36xhmw/W', 'dosen', 1),
    ('19770001', 'Admin Dummy', '$2y$12$jvC7pvZh9Sf8YYazZfECmuJsqrlY4XYv7MMrt5o7qIh8tw0cmbqLW', 'admin', 1)
ON DUPLICATE KEY UPDATE
    `full_name` = VALUES(`full_name`),
    `password_hash` = VALUES(`password_hash`),
    `role` = VALUES(`role`),
    `is_active` = VALUES(`is_active`);

-- Password plaintext untuk testing:
-- dummy12345
-- admin12345 (untuk 19770001)
