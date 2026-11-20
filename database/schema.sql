-- FindSync schema — MySQL 8+/MariaDB, InnoDB, utf8mb4.
-- All DATETIME values are stored in UTC by the PHP application.

CREATE DATABASE IF NOT EXISTS findsync_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE findsync_db;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS import_errors;
DROP TABLE IF EXISTS import_batches;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS releases;
DROP TABLE IF EXISTS found_claim_locks;
DROP TABLE IF EXISTS claim_requests;
DROP TABLE IF EXISTS found_items;
DROP TABLE IF EXISTS lost_reports;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS students;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE students (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_number VARCHAR(32) NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  program VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_students_number (student_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NULL DEFAULT NULL,
  username VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('student', 'staff') NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_users_username (username),
  UNIQUE KEY uniq_users_student (student_id),
  CONSTRAINT fk_users_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lost_reports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  category VARCHAR(50) NOT NULL,
  description TEXT NOT NULL,
  location VARCHAR(80) NOT NULL,
  event_date DATE NOT NULL,
  status ENUM('open', 'resolved', 'cancelled') NOT NULL DEFAULT 'open',
  source ENUM('web', 'csv', 'staff') NOT NULL DEFAULT 'web',
  created_by_user_id INT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_lost_status (status),
  KEY idx_lost_category (category),
  KEY idx_lost_date (event_date),
  KEY idx_lost_student (student_id),
  KEY idx_lost_created_by (created_by_user_id),
  CONSTRAINT fk_lost_student FOREIGN KEY (student_id) REFERENCES students (id),
  CONSTRAINT fk_lost_creator FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE found_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  finder_student_id INT UNSIGNED NULL DEFAULT NULL,
  category VARCHAR(50) NOT NULL,
  description TEXT NOT NULL,
  location VARCHAR(80) NOT NULL,
  event_date DATE NOT NULL,
  status ENUM('available', 'claimed', 'disposed') NOT NULL DEFAULT 'available',
  source ENUM('web', 'csv', 'staff') NOT NULL DEFAULT 'web',
  created_by_user_id INT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_found_status (status),
  KEY idx_found_category (category),
  KEY idx_found_date (event_date),
  KEY idx_found_finder (finder_student_id),
  KEY idx_found_created_by (created_by_user_id),
  CONSTRAINT fk_found_finder FOREIGN KEY (finder_student_id) REFERENCES students (id) ON DELETE SET NULL,
  CONSTRAINT fk_found_creator FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE claim_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  lost_report_id INT UNSIGNED NOT NULL,
  found_item_id INT UNSIGNED NOT NULL,
  requester_user_id INT UNSIGNED NOT NULL,
  ownership_evidence TEXT NOT NULL,
  status ENUM('pending', 'approved', 'rejected', 'released') NOT NULL DEFAULT 'pending',
  staff_notes TEXT NULL DEFAULT NULL,
  reviewed_by_user_id INT UNSIGNED NULL DEFAULT NULL,
  reviewed_at DATETIME NULL DEFAULT NULL,
  released_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_claim_pair (lost_report_id, found_item_id),
  KEY idx_claim_status (status),
  KEY idx_claim_requester (requester_user_id),
  KEY idx_claim_found_status (found_item_id, status),
  KEY idx_claim_lost_status (lost_report_id, status),
  CONSTRAINT fk_claim_lost FOREIGN KEY (lost_report_id) REFERENCES lost_reports (id),
  CONSTRAINT fk_claim_found FOREIGN KEY (found_item_id) REFERENCES found_items (id),
  CONSTRAINT fk_claim_requester FOREIGN KEY (requester_user_id) REFERENCES users (id),
  CONSTRAINT fk_claim_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One active (pending/approved) claim per found item. Row is removed when a claim is rejected.
CREATE TABLE found_claim_locks (
  found_item_id INT UNSIGNED NOT NULL,
  claim_request_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (found_item_id),
  UNIQUE KEY uniq_lock_claim (claim_request_id),
  CONSTRAINT fk_lock_found FOREIGN KEY (found_item_id) REFERENCES found_items (id),
  CONSTRAINT fk_lock_claim FOREIGN KEY (claim_request_id) REFERENCES claim_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Strict allocation guard. UNIQUE columns prevent two releases for the same lost or found record.
CREATE TABLE releases (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  claim_request_id INT UNSIGNED NOT NULL,
  lost_report_id INT UNSIGNED NOT NULL,
  found_item_id INT UNSIGNED NOT NULL,
  released_by_user_id INT UNSIGNED NOT NULL,
  released_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_release_claim (claim_request_id),
  UNIQUE KEY uniq_release_lost (lost_report_id),
  UNIQUE KEY uniq_release_found (found_item_id),
  CONSTRAINT fk_release_claim FOREIGN KEY (claim_request_id) REFERENCES claim_requests (id),
  CONSTRAINT fk_release_lost FOREIGN KEY (lost_report_id) REFERENCES lost_reports (id),
  CONSTRAINT fk_release_found FOREIGN KEY (found_item_id) REFERENCES found_items (id),
  CONSTRAINT fk_release_user FOREIGN KEY (released_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_batches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_type ENUM('found', 'lost') NOT NULL,
  filename VARCHAR(255) NOT NULL,
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  accepted_rows INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_rows INT UNSIGNED NOT NULL DEFAULT 0,
  created_by_user_id INT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_import_created (created_at),
  CONSTRAINT fk_import_user FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE import_errors (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id INT UNSIGNED NOT NULL,
  row_number INT UNSIGNED NOT NULL,
  reason VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_import_error_batch (batch_id),
  CONSTRAINT fk_error_batch FOREIGN KEY (batch_id) REFERENCES import_batches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id INT UNSIGNED NULL DEFAULT NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id VARCHAR(64) NULL DEFAULT NULL,
  details TEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_action (action),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
