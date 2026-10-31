-- ============================================================
-- Insurance Management System - Full Database Schema & Seed Data
-- Database: u456035299_policy_manage
-- Generated: 2026-10-29
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ------------------------------------------------------------
-- Table: branches
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `branches`;
CREATE TABLE `branches` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `branch_code` VARCHAR(20) NULL,
  `name` VARCHAR(150) NOT NULL,
  `mobile` VARCHAR(15) NOT NULL,
  `email` VARCHAR(200) NULL,
  `address` TEXT NOT NULL,
  `city` VARCHAR(100) NULL,
  `pincode` VARCHAR(10) NULL,
  `state` VARCHAR(100) NOT NULL,
  `gst_number` VARCHAR(15) NULL,
  `wallet_balance` DECIMAL(14,2) DEFAULT 0.00,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_branch_code` (`branch_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: users
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(200) NOT NULL UNIQUE,
  `phone` VARCHAR(15) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','manager','agent','customer') NOT NULL DEFAULT 'agent',
  `branch_id` BIGINT NULL,
  `customer_id` BIGINT NULL,
  `is_active` BOOLEAN DEFAULT TRUE,
  `profile_image` VARCHAR(255) NULL,
  `status` ENUM('pending_verification','verification_completed','pending_subscription','active','suspended','blocked') NOT NULL DEFAULT 'active',
  `profile_setup_completed` TINYINT(1) NOT NULL DEFAULT 0,
  `mobile_verified_at` DATETIME NULL,
  `email_verified_at` DATETIME NULL,
  `theme_primary` VARCHAR(20) DEFAULT '#001f3f',
  `theme_secondary` VARCHAR(20) DEFAULT '#003366',
  `theme_accent` VARCHAR(20) DEFAULT '#0074D9',
  `theme_mode` VARCHAR(10) DEFAULT 'light',
  `theme_style` VARCHAR(10) NULL,
  `theme_shape` VARCHAR(10) NULL,
  `theme_sidebar` VARCHAR(10) NULL,
  `created_by` BIGINT NULL,
  `last_login_at` DATETIME NULL,
  `last_login_ip` VARCHAR(45) NULL,
  `password_changed_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_email` (`email`),
  INDEX `idx_role` (`role`),
  INDEX `idx_branch_id` (`branch_id`),
  INDEX `idx_customer_id` (`customer_id`),
  INDEX `idx_user_status` (`status`),
  INDEX `idx_is_active` (`is_active`),
  FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: agents
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `agents`;
CREATE TABLE `agents` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL UNIQUE,
  `branch_id` BIGINT NULL,
  `agent_code` VARCHAR(30) NOT NULL UNIQUE,
  `name` VARCHAR(150) NOT NULL,
  `agency_name` VARCHAR(200) NULL,
  `mobile` VARCHAR(15) NOT NULL,
  `email` VARCHAR(200) NULL,
  `address` TEXT NULL,
  `city` VARCHAR(100) NULL,
  `pincode` VARCHAR(10) NULL,
  `state` VARCHAR(100) NULL,
  `pan_number` VARCHAR(10) NULL,
  `aadhar_number` VARCHAR(12) NULL,
  `license_number` VARCHAR(30) NULL,
  `license_expiry_date` DATE NULL,
  `tds_rate` DECIMAL(5,2) NOT NULL DEFAULT 5.00,
  `joining_date` DATE NULL,
  `bank_name` VARCHAR(100) NULL,
  `bank_account_no` VARCHAR(20) NULL,
  `ifsc_code` VARCHAR(11) NULL,
  `referral_code` VARCHAR(50) NULL,
  `referred_by_agent_id` BIGINT NULL,
  `logo_path` VARCHAR(255) NULL,
  `whatsapp_consent` TINYINT(1) NOT NULL DEFAULT 1,
  `email_consent` TINYINT(1) NOT NULL DEFAULT 1,
  `terms_accepted_at` DATETIME NULL,
  `active_subscription_id` BIGINT NULL,
  `upi_id` VARCHAR(60) NULL,
  `wallet_balance` DECIMAL(14,2) DEFAULT 0.00,
  `outstanding_balance` DECIMAL(14,2) DEFAULT 0.00,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE SET NULL,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_branch_id` (`branch_id`),
  INDEX `idx_agent_code` (`agent_code`),
  INDEX `idx_license_expiry` (`license_expiry_date`),
  INDEX `idx_ag_active_sub` (`active_subscription_id`),
  INDEX `idx_ag_ref_code` (`referral_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: customers
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `mobile` VARCHAR(15) NOT NULL,
  `secondary_mobile` VARCHAR(15) NULL,
  `whatsapp_number` VARCHAR(15) NULL,
  `date_of_birth` DATE NULL,
  `dob` DATE NULL,
  `gender` ENUM('male','female','other') NULL,
  `email` VARCHAR(200) NULL,
  `address` TEXT NULL,
  `city` VARCHAR(100) NULL,
  `pincode` VARCHAR(10) NULL,
  `state` VARCHAR(100) NULL,
  `primary_wa_consent` TINYINT(1) NOT NULL DEFAULT 1,
  `secondary_wa_consent` TINYINT(1) NOT NULL DEFAULT 0,
  `email_consent` TINYINT(1) NOT NULL DEFAULT 1,
  `consent_timestamp` DATETIME NULL,
  `consent_source` VARCHAR(50) DEFAULT 'agent_portal',
  `ckyc_number` VARCHAR(20) NULL,
  `referral_code` VARCHAR(20) NULL UNIQUE,
  `referred_by` BIGINT NULL,
  `agent_id` BIGINT NULL,
  `family_head_id` BIGINT NULL,
  `family_relation` VARCHAR(50) NULL DEFAULT 'Self',
  `portal_user_id` BIGINT NULL,
  `points_balance` INT DEFAULT 0,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_by` BIGINT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`referred_by`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
  INDEX `idx_referral_code` (`referral_code`),
  INDEX `idx_referred_by` (`referred_by`),
  INDEX `idx_cust_agent` (`agent_id`),
  INDEX `idx_family_head_id` (`family_head_id`),
  INDEX `idx_portal_user_id` (`portal_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: bank_accounts
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `bank_accounts`;
CREATE TABLE `bank_accounts` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `bank_name` VARCHAR(100) NOT NULL,
  `account_holder_name` VARCHAR(150) NULL,
  `account_number` VARCHAR(20) NOT NULL UNIQUE,
  `ifsc_code` VARCHAR(11) NOT NULL,
  `branch_name` VARCHAR(150) NULL,
  `account_type` VARCHAR(30) NOT NULL DEFAULT 'Current',
  `opening_balance` DECIMAL(14,2) DEFAULT 0.00,
  `opening_balance_date` DATE NULL,
  `current_balance` DECIMAL(14,2) DEFAULT 0.00,
  `branch_id` BIGINT NULL,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE SET NULL,
  INDEX `idx_account_number` (`account_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: vehicles
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `vehicles`;
CREATE TABLE `vehicles` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `vehicle_number` VARCHAR(20) NOT NULL UNIQUE,
  `vehicle_type` VARCHAR(50) NOT NULL,
  `make` VARCHAR(100) NULL,
  `model` VARCHAR(100) NULL,
  `year_of_manufacture` YEAR NULL,
  `fuel_type` VARCHAR(20) NULL,
  `chassis_number` VARCHAR(30) NULL,
  `engine_number` VARCHAR(30) NULL,
  `cubic_capacity` SMALLINT NULL,
  `seating_capacity` TINYINT UNSIGNED NULL,
  `gvw_kg` INT NULL,
  `registration_date` DATE NULL,
  `customer_id` BIGINT NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  INDEX `idx_vehicle_number` (`vehicle_number`),
  INDEX `idx_customer_id` (`customer_id`),
  INDEX `idx_chassis` (`chassis_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: insurance_categories
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `insurance_categories`;
CREATE TABLE `insurance_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(30) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `icon` VARCHAR(50) NOT NULL DEFAULT 'fa-shield',
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: policy_entries
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `policy_entries`;
CREATE TABLE `policy_entries` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `insurance_category` VARCHAR(30) NOT NULL DEFAULT 'motor',
  `entry_date` DATE NOT NULL,
  `branch_id` BIGINT NOT NULL,
  `vehicle_id` BIGINT NULL,
  `vehicle_number` VARCHAR(20) NULL,
  `vehicle_type` VARCHAR(50) NULL,
  `customer_id` BIGINT NOT NULL,
  `customer_name` VARCHAR(150) NOT NULL,
  `agent_id` BIGINT NULL,
  `agent_name` VARCHAR(150) NULL,
  `company_name` VARCHAR(200) NOT NULL,
  `company_code` VARCHAR(30) NOT NULL,
  `policy_number` VARCHAR(50) NOT NULL UNIQUE,
  `policy_type` ENUM('comprehensive','third_party','own_damage') NULL,
  `business_type` ENUM('new','renewal','rollover') NULL,
  `previous_policy_id` BIGINT NULL,
  `proposal_number` VARCHAR(50) NULL,
  `total_premium_amount` DECIMAL(12,2) NOT NULL,
  `idv` DECIMAL(12,2) NULL,
  `tp_amount` DECIMAL(12,2) DEFAULT 0.00,
  `od_amount` DECIMAL(12,2) DEFAULT 0.00,
  `net_premium_amount` DECIMAL(12,2) NOT NULL,
  `gst_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `ncb_percent` DECIMAL(5,2) NULL,
  -- Health Insurance specific fields
  `sum_insured` DECIMAL(14,2) NULL,
  `plan_name` VARCHAR(150) NULL,
  `tpa_name` VARCHAR(150) NULL,
  `health_policy_type` ENUM('individual','family_floater','senior_citizen','critical_illness','top_up') NULL,
  `members_count` TINYINT UNSIGNED NULL DEFAULT 1,
  `members_details` TEXT NULL,
  `ped_details` TEXT NULL,
  -- Life Insurance specific fields
  `sum_assured` DECIMAL(14,2) NULL,
  `life_plan_type` ENUM('term','endowment','money_back','ulip','whole_life','child_plan','pension') NULL,
  `policy_term` SMALLINT NULL,
  `premium_paying_term` SMALLINT NULL,
  `payment_frequency` ENUM('yearly','half_yearly','quarterly','monthly','single') NULL,
  `nominee_name` VARCHAR(150) NULL,
  `nominee_relation` VARCHAR(50) NULL,
  `nominee_dob` DATE NULL,
  `bank_account_id` BIGINT NULL,
  `bank_name` VARCHAR(100) NULL,
  `payment_in` DECIMAL(12,2) DEFAULT 0.00,
  `payment_out` DECIMAL(12,2) DEFAULT 0.00,
  `payment_in_bank_account_id` BIGINT NULL,
  `payment_out_bank_account_id` BIGINT NULL,
  `payment_mode` VARCHAR(30) NULL,
  `policy_start_date` DATE NOT NULL,
  `policy_end_date` DATE NOT NULL,
  `status` ENUM('active','expired','cancelled') DEFAULT 'active',
  `cancelled_at` DATETIME NULL,
  `cancellation_reason` VARCHAR(255) NULL,
  `remarks` TEXT NULL,
  `renewal_status` ENUM('original','renewed','pending_renewal') NOT NULL DEFAULT 'original',
  `document_reference` VARCHAR(100) NULL,
  `verification_status` ENUM('pending_verification','verified','rejected') NOT NULL DEFAULT 'verified',
  `verified_by` BIGINT NULL,
  `verified_at` DATETIME NULL,
  `last_reminder_at` DATETIME NULL,
  `reminder_count` SMALLINT NOT NULL DEFAULT 0,
  `created_by` BIGINT NOT NULL,
  `updated_by` BIGINT NULL,
  `deleted_at` DATETIME NULL,
  `deleted_by` BIGINT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_insurance_category` (`insurance_category`),
  INDEX `idx_policy_number` (`policy_number`),
  INDEX `idx_entry_date` (`entry_date`),
  INDEX `idx_branch_id` (`branch_id`),
  INDEX `idx_customer_id` (`customer_id`),
  INDEX `idx_agent_id` (`agent_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_policy_dates` (`policy_start_date`, `policy_end_date`),
  INDEX `idx_prev_policy` (`previous_policy_id`),
  INDEX `idx_renewal_status` (`renewal_status`),
  INDEX `idx_verif_status` (`verification_status`),
  INDEX `idx_pe_pay_in_bank` (`payment_in_bank_account_id`),
  INDEX `idx_pe_pay_out_bank` (`payment_out_bank_account_id`),
  INDEX `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: policy_documents
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `policy_documents`;
CREATE TABLE `policy_documents` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `policy_entry_id` BIGINT NOT NULL,
  `document_type` ENUM('policy_pdf','vehicle_image','other') NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size_kb` INT NULL,
  `mime_type` VARCHAR(50) NULL,
  `uploaded_by` BIGINT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`policy_entry_id`) REFERENCES `policy_entries`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_policy_entry_id` (`policy_entry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: expense_categories
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `expense_categories`;
CREATE TABLE `expense_categories` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `description` VARCHAR(255) NULL,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: expenses
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `expenses`;
CREATE TABLE `expenses` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `expense_category_id` BIGINT NOT NULL,
  `branch_id` BIGINT NOT NULL,
  `expense_date` DATE NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `payee_name` VARCHAR(150) NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `gst_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_mode` VARCHAR(30) NULL,
  `bank_account_id` BIGINT NULL,
  `reference_number` VARCHAR(50) NULL,
  `receipt_file_path` VARCHAR(500) NULL,
  `approval_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `approved_by` BIGINT NULL,
  `approval_note` VARCHAR(255) NULL,
  `created_by` BIGINT NOT NULL,
  `updated_by` BIGINT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_expense_date` (`expense_date`),
  INDEX `idx_branch_id` (`branch_id`),
  INDEX `idx_category_id` (`expense_category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: other_income
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `other_income`;
CREATE TABLE `other_income` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `branch_id` BIGINT NOT NULL,
  `income_date` DATE NOT NULL,
  `source` VARCHAR(200) NOT NULL,
  `remarks` VARCHAR(500) NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `payment_mode` VARCHAR(30) NULL,
  `bank_account_id` BIGINT NULL,
  `reference_number` VARCHAR(50) NULL,
  `created_by` BIGINT NOT NULL,
  `updated_by` BIGINT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_income_date` (`income_date`),
  INDEX `idx_branch_id` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: bank_transactions
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `bank_transactions`;
CREATE TABLE `bank_transactions` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `bank_account_id` BIGINT NOT NULL,
  `transaction_date` DATE NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `reference_number` VARCHAR(50) NULL,
  `transaction_type` ENUM('credit','debit') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `payment_mode` VARCHAR(30) NULL,
  `running_balance` DECIMAL(14,2) NOT NULL,
  `policy_entry_id` BIGINT NULL,
  `expense_id` BIGINT NULL,
  `other_income_id` BIGINT NULL,
  `category` VARCHAR(50) NULL,
  `agent_id` BIGINT NULL,
  `link_txn_id` BIGINT NULL,
  `is_linked_mirror` TINYINT(1) NOT NULL DEFAULT 0,
  `is_reconciled` BOOLEAN NOT NULL DEFAULT FALSE,
  `reconciled_at` DATETIME NULL,
  `created_by` BIGINT NOT NULL,
  `updated_by` BIGINT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`policy_entry_id`) REFERENCES `policy_entries`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`expense_id`) REFERENCES `expenses`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_bank_account_id` (`bank_account_id`),
  INDEX `idx_transaction_date` (`transaction_date`),
  INDEX `idx_bt_agent` (`agent_id`),
  INDEX `idx_other_income` (`other_income_id`),
  INDEX `idx_reconciled` (`is_reconciled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: notifications
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `type` ENUM('policy_expiry','payment_pending','commission_update','general') NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `policy_entry_id` BIGINT NULL,
  `agent_id` BIGINT NULL,
  `is_read` BOOLEAN DEFAULT FALSE,
  `read_at` DATETIME NULL,
  `is_email_sent` BOOLEAN DEFAULT FALSE,
  `scheduled_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`policy_entry_id`) REFERENCES `policy_entries`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE SET NULL,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_is_read` (`is_read`),
  INDEX `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: referrals
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `referrals`;
CREATE TABLE `referrals` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `referrer_id` BIGINT NOT NULL,
  `referred_id` BIGINT NOT NULL,
  `policy_entry_id` BIGINT NULL,
  `points_earned` INT DEFAULT 0,
  `status` ENUM('pending','completed','expired') DEFAULT 'pending',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  FOREIGN KEY (`referrer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`referred_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`policy_entry_id`) REFERENCES `policy_entries`(`id`) ON DELETE SET NULL,
  INDEX `idx_referrer_id` (`referrer_id`),
  INDEX `idx_referred_id` (`referred_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: point_transactions
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `point_transactions`;
CREATE TABLE `point_transactions` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` BIGINT NOT NULL,
  `transaction_type` ENUM('earned','redeemed') NOT NULL,
  `points` INT NOT NULL,
  `rupee_value` DECIMAL(10,2) DEFAULT 0.00,
  `description` VARCHAR(255) NOT NULL,
  `referral_id` BIGINT NULL,
  `policy_entry_id` BIGINT NULL,
  `balance_after` INT NOT NULL,
  `created_by` BIGINT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`referral_id`) REFERENCES `referrals`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`policy_entry_id`) REFERENCES `policy_entries`(`id`) ON DELETE SET NULL,
  INDEX `idx_customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: payout_chart_uploads
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `payout_chart_uploads`;
CREATE TABLE `payout_chart_uploads` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `chart_type` ENUM('buyer_payout','agent_payout') NOT NULL,
  `month` TINYINT NOT NULL,
  `year` SMALLINT NOT NULL,
  `records_processed` INT DEFAULT 0,
  `records_failed` INT DEFAULT 0,
  `status` ENUM('pending','processing','completed','failed') DEFAULT 'pending',
  `error_log` TEXT NULL,
  `uploaded_by` BIGINT NOT NULL,
  `completed_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: activity_logs
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `username` VARCHAR(150) NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT NULL,
  `table_name` VARCHAR(100) NULL,
  `record_id` BIGINT NULL,
  `old_values` JSON NULL,
  `new_values` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(500) NULL,
  `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_action` (`action`),
  INDEX `idx_timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: system_settings
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT NOT NULL,
  `updated_by` BIGINT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: login_attempts
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(200) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `is_success` BOOLEAN NOT NULL DEFAULT FALSE,
  `attempt_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_username` (`username`),
  INDEX `idx_attempt_time` (`attempt_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: todos
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `todos`;
CREATE TABLE `todos` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `priority` ENUM('low','medium','high','urgent') DEFAULT 'medium',
  `status` ENUM('pending','in_progress','completed') DEFAULT 'pending',
  `due_date` DATE NULL,
  `assigned_to` BIGINT NULL,
  `policy_entry_id` BIGINT NULL,
  `created_by` BIGINT NOT NULL,
  `completed_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_status` (`status`),
  INDEX `idx_priority` (`priority`),
  INDEX `idx_due_date` (`due_date`),
  INDEX `idx_assigned` (`assigned_to`, `status`),
  INDEX `idx_todo_policy` (`policy_entry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: policy_trash
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `policy_trash`;
CREATE TABLE `policy_trash` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `policy_entry_id` BIGINT NOT NULL,
  `policy_number` VARCHAR(50) NOT NULL,
  `customer_name` VARCHAR(150) NULL,
  `vehicle_number` VARCHAR(20) NULL,
  `company_name` VARCHAR(200) NULL,
  `total_premium_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `snapshot` LONGTEXT NOT NULL,
  `deleted_by` BIGINT NULL,
  `deleted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pt_deleted` (`deleted_at`),
  INDEX `idx_pt_number` (`policy_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: policy_endorsements
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `policy_endorsements`;
CREATE TABLE `policy_endorsements` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `policy_entry_id` BIGINT NOT NULL,
  `endorsement_number` VARCHAR(50) NULL,
  `endorsement_type` ENUM('owner_transfer','vehicle_details','addon_cover','hypothecation','correction','other') NOT NULL DEFAULT 'other',
  `endorsement_date` DATE NOT NULL,
  `effective_date` DATE NULL,
  `description` VARCHAR(500) NOT NULL,
  `premium_change` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `fee_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `other_income_id` BIGINT NULL,
  `created_by` BIGINT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`policy_entry_id`) REFERENCES `policy_entries`(`id`) ON DELETE CASCADE,
  INDEX `idx_end_policy` (`policy_entry_id`, `endorsement_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: claims
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `claims`;
CREATE TABLE `claims` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `claim_number` VARCHAR(30) NOT NULL UNIQUE,
  `policy_entry_id` BIGINT NOT NULL,
  `claim_category` ENUM('motor','health','life') NOT NULL DEFAULT 'motor',
  `insurer_claim_number` VARCHAR(50) NULL,
  `claim_type` VARCHAR(50) NOT NULL DEFAULT 'own_damage',
  `incident_date` DATE NOT NULL,
  `intimation_date` DATE NOT NULL,
  `incident_place` VARCHAR(150) NULL,
  `description` TEXT NULL,
  -- Motor Claim specific
  `fir_number` VARCHAR(50) NULL,
  `surveyor_name` VARCHAR(150) NULL,
  `surveyor_mobile` VARCHAR(15) NULL,
  `survey_date` DATE NULL,
  `garage_name` VARCHAR(150) NULL,
  -- Health Claim specific
  `hospital_name` VARCHAR(150) NULL,
  `admission_date` DATE NULL,
  `discharge_date` DATE NULL,
  `doctor_name` VARCHAR(150) NULL,
  `diagnosis` TEXT NULL,
  -- Life Claim specific
  `life_claim_type` ENUM('death','maturity','surrender','critical_illness','disability') NULL,
  `date_of_event` DATE NULL,
  -- Settlement & Status
  `settlement_mode` ENUM('cashless','reimbursement') NULL,
  `amount_claimed` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `amount_approved` DECIMAL(12,2) NULL,
  `amount_settled` DECIMAL(12,2) NULL,
  `settlement_date` DATE NULL,
  `status` ENUM('intimated','survey','approved','settled','rejected','withdrawn') NOT NULL DEFAULT 'intimated',
  `rejection_reason` VARCHAR(255) NULL,
  `remarks` VARCHAR(500) NULL,
  `created_by` BIGINT NOT NULL,
  `updated_by` BIGINT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`policy_entry_id`) REFERENCES `policy_entries`(`id`) ON DELETE RESTRICT,
  INDEX `idx_claim_category` (`claim_category`),
  INDEX `idx_claim_status` (`status`),
  INDEX `idx_claim_policy` (`policy_entry_id`),
  INDEX `idx_claim_intimation` (`intimation_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: claim_documents
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `claim_documents`;
CREATE TABLE `claim_documents` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `claim_id` BIGINT NOT NULL,
  `document_type` ENUM('claim_form','fir','estimate','survey_report','repair_invoice','photo','settlement_letter','other') NOT NULL DEFAULT 'other',
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size_kb` INT NULL,
  `mime_type` VARCHAR(50) NULL,
  `uploaded_by` BIGINT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`claim_id`) REFERENCES `claims`(`id`) ON DELETE CASCADE,
  INDEX `idx_cd_claim` (`claim_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: password_resets
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `token_hash` CHAR(64) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_pr_user` (`user_id`, `created_at`),
  INDEX `idx_pr_ip` (`ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: vehicle_categories
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `vehicle_categories`;
CREATE TABLE `vehicle_categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(60) NOT NULL UNIQUE,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Insurance Categories
INSERT INTO `insurance_categories` (`id`, `code`, `name`, `icon`, `display_order`, `is_active`) VALUES
(1, 'motor', 'Motor Insurance', 'fa-car', 1, 1),
(2, 'health', 'Health Insurance', 'fa-heart-pulse', 2, 1),
(3, 'life', 'Life Insurance', 'fa-shield-heart', 3, 1);

-- Vehicle Categories
INSERT INTO `vehicle_categories` (`id`, `name`, `is_active`) VALUES
(1, 'Two Wheeler', 1),
(2, 'Car', 1),
(3, 'Commercial', 1),
(4, 'Other', 1);

-- System Settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('site_name', 'Insurance Management System'),
('site_logo', 'uploads/logos/site_logo.png'),
('currency_symbol', '₹'),
('site_footer', '© 2025 Insurance Management System'),
('allow_user_profile_uploads', '1'),
('points_to_rupees_ratio', '10'),
('auto_backup', '1'),
('wa_country_code', '91'),
('smtp_enabled', '0'),
('smtp_port', '587'),
('smtp_encryption', 'tls');

-- Branches
INSERT INTO `branches` (`id`, `branch_code`, `name`, `mobile`, `email`, `address`, `city`, `pincode`, `state`, `wallet_balance`, `is_active`) VALUES
(1, 'BR-01', 'Branch 1', '0421000001', 'branch1@demo.com', 'House 1, Street 1, Demo City', 'Demo City', '110001', 'State A', 0.00, 1),
(2, 'BR-02', 'Branch 2', '0421000002', 'branch2@demo.com', 'House 2, Street 2, Demo City', 'Demo City', '110002', 'State B', 0.00, 1),
(3, 'BR-03', 'Branch 3', '0421000003', 'branch3@demo.com', 'House 3, Street 3, Demo City', 'Demo City', '110003', 'State A', 0.00, 1),
(4, 'BR-04', 'Branch 4', '0421000004', 'branch4@demo.com', 'House 4, Street 4, Demo City', 'Demo City', '110004', 'State C', 0.00, 1),
(5, 'BR-05', 'Branch 5', '0421000005', 'branch5@demo.com', 'House 5, Street 5, Demo City', 'Demo City', '110005', 'State B', 0.00, 1);

-- Users (Password for all accounts is: admin123)
INSERT INTO `users` (`id`, `name`, `email`, `phone`, `password_hash`, `role`, `branch_id`, `is_active`, `profile_image`) VALUES
(1, 'Admin User', 'admin@demo.com', '03001000001', '$2y$10$xX3NV4KaLJcFN1DUK9h5WOPGBvzxxK7B/6WJk24kzlQxy5Qq6saPy', 'admin', 1, 1, 'uploads/profiles/profile_admin.png'),
(2, 'Manager 1', 'manager1@demo.com', '03001000002', '$2y$10$xX3NV4KaLJcFN1DUK9h5WOPGBvzxxK7B/6WJk24kzlQxy5Qq6saPy', 'manager', 1, 1, 'uploads/profiles/default_avatar.png'),
(3, 'Manager 2', 'manager2@demo.com', '03001000003', '$2y$10$xX3NV4KaLJcFN1DUK9h5WOPGBvzxxK7B/6WJk24kzlQxy5Qq6saPy', 'manager', 2, 1, 'uploads/profiles/default_avatar.png'),
(4, 'Agent 1', 'agent1@demo.com', '03001000004', '$2y$10$xX3NV4KaLJcFN1DUK9h5WOPGBvzxxK7B/6WJk24kzlQxy5Qq6saPy', 'agent', 1, 1, 'uploads/profiles/default_avatar.png'),
(5, 'Agent 2', 'agent2@demo.com', '03001000005', '$2y$10$xX3NV4KaLJcFN1DUK9h5WOPGBvzxxK7B/6WJk24kzlQxy5Qq6saPy', 'agent', 2, 1, 'uploads/profiles/default_avatar.png'),
(6, 'Agent 3', 'agent3@demo.com', '03001000006', '$2y$10$xX3NV4KaLJcFN1DUK9h5WOPGBvzxxK7B/6WJk24kzlQxy5Qq6saPy', 'agent', 3, 1, 'uploads/profiles/default_avatar.png');

-- Agents
INSERT INTO `agents` (`id`, `user_id`, `branch_id`, `agent_code`, `name`, `mobile`, `email`, `state`, `wallet_balance`, `outstanding_balance`) VALUES
(1, 4, 1, 'AGT-0001', 'Agent 1', '03001000004', 'agent1@demo.com', 'State A', 0.00, 0.00),
(2, 5, 2, 'AGT-0002', 'Agent 2', '03001000005', 'agent2@demo.com', 'State B', 0.00, 0.00),
(3, 6, 3, 'AGT-0003', 'Agent 3', '03001000006', 'agent3@demo.com', 'State A', 0.00, 0.00);

-- Customers
INSERT INTO `customers` (`id`, `name`, `mobile`, `email`, `state`, `referral_code`, `referred_by`, `points_balance`) VALUES
(1, 'Customer 1', '03002000001', 'customer1@demo.com', 'State A', 'REF-0001', NULL, 100),
(2, 'Customer 2', '03002000002', 'customer2@demo.com', 'State B', 'REF-0002', NULL, 100),
(3, 'Customer 3', '03002000003', 'customer3@demo.com', 'State A', 'REF-0003', NULL, 0),
(4, 'Customer 4', '03002000004', 'customer4@demo.com', 'State C', 'REF-0004', NULL, 0),
(5, 'Customer 5', '03002000005', 'customer5@demo.com', 'State B', 'REF-0005', 1, 0),
(6, 'Customer 6', '03002000006', 'customer6@demo.com', 'State A', 'REF-0006', NULL, 0),
(7, 'Customer 7', '03002000007', 'customer7@demo.com', 'State C', 'REF-0007', NULL, 0),
(8, 'Customer 8', '03002000008', 'customer8@demo.com', 'State B', 'REF-0008', NULL, 0),
(9, 'Customer 9', '03002000009', 'customer9@demo.com', 'State A', 'REF-0009', NULL, 0),
(10, 'Customer 10', '03002000010', 'customer10@demo.com', 'State C', 'REF-0010', 2, 0),
(11, 'Customer 11', '03002000011', 'customer11@demo.com', 'State B', 'REF-0011', NULL, 0),
(12, 'Customer 12', '03002000012', 'customer12@demo.com', 'State A', 'REF-0012', NULL, 0),
(13, 'Customer 13', '03002000013', 'customer13@demo.com', 'State C', 'REF-0013', NULL, 0),
(14, 'Customer 14', '03002000014', 'customer14@demo.com', 'State B', 'REF-0014', NULL, 0),
(15, 'Customer 15', '03002000015', 'customer15@demo.com', 'State A', 'REF-0015', 3, 0),
(16, 'Customer 16', '03002000016', 'customer16@demo.com', 'State C', 'REF-0016', NULL, 0),
(17, 'Customer 17', '03002000017', 'customer17@demo.com', 'State B', 'REF-0017', NULL, 0),
(18, 'Customer 18', '03002000018', 'customer18@demo.com', 'State A', 'REF-0018', NULL, 0),
(19, 'Customer 19', '03002000019', 'customer19@demo.com', 'State C', 'REF-0019', NULL, 0),
(20, 'Customer 20', '03002000020', 'customer20@demo.com', 'State B', 'REF-0020', NULL, 0);

-- Expense Categories
INSERT INTO `expense_categories` (`id`, `name`, `description`) VALUES
(1, 'Salary', 'Employee salaries'),
(2, 'Rent', 'Office rent'),
(3, 'Electricity', 'Electricity bills'),
(4, 'Internet', 'Internet and phone bills'),
(5, 'Stationery', 'Office supplies'),
(6, 'Travel', 'Travel and conveyance'),
(7, 'Maintenance', 'Office maintenance');

-- Bank Accounts
INSERT INTO `bank_accounts` (`id`, `bank_name`, `account_number`, `ifsc_code`, `branch_name`, `account_type`, `opening_balance`, `current_balance`, `branch_id`) VALUES
(1, 'Bank A', '1000000001', 'BNKA0001', 'Bank Branch 1', 'Current', 100000.00, 117750.00, 1),
(2, 'Bank B', '1000000002', 'BNKB0001', 'Bank Branch 2', 'Savings', 50000.00, 44400.00, 1),
(3, 'Bank C', '1000000003', 'BNKC0001', 'Bank Branch 3', 'Current', 75000.00, 104000.00, 2),
(4, 'Bank D', '1000000004', 'BNKD0001', 'Bank Branch 4', 'Savings', 25000.00, 38500.00, 3);

-- Vehicles
INSERT INTO `vehicles` (`id`, `vehicle_number`, `vehicle_type`, `make`, `model`, `year_of_manufacture`, `fuel_type`, `customer_id`) VALUES
(1, 'VH-0001', 'Car', 'Make A', 'Model 1', 2022, 'Petrol', 1),
(2, 'VH-0002', 'Two Wheeler', 'Make B', 'Model 2', 2023, 'Petrol', 2),
(3, 'VH-0003', 'Commercial', 'Make C', 'Model 3', 2021, 'Diesel', 3),
(4, 'VH-0004', 'Car', 'Make A', 'Model 4', 2024, 'Petrol', 4),
(5, 'VH-0005', 'Two Wheeler', 'Make D', 'Model 5', 2022, 'Petrol', 5),
(6, 'VH-0006', 'Commercial', 'Make E', 'Model 6', 2023, 'Diesel', 6),
(7, 'VH-0007', 'Car', 'Make B', 'Model 7', 2021, 'CNG', 7),
(8, 'VH-0008', 'Two Wheeler', 'Make A', 'Model 8', 2024, 'Petrol', 8),
(9, 'VH-0009', 'Commercial', 'Make C', 'Model 9', 2022, 'Diesel', 9),
(10, 'VH-0010', 'Car', 'Make D', 'Model 10', 2023, 'Petrol', 10),
(11, 'VH-0011', 'Two Wheeler', 'Make E', 'Model 11', 2021, 'Petrol', 11),
(12, 'VH-0012', 'Commercial', 'Make A', 'Model 12', 2024, 'Diesel', 12),
(13, 'VH-0013', 'Car', 'Make B', 'Model 13', 2022, 'CNG', 13),
(14, 'VH-0014', 'Two Wheeler', 'Make C', 'Model 14', 2023, 'Petrol', 14),
(15, 'VH-0015', 'Commercial', 'Make D', 'Model 15', 2021, 'Diesel', 15),
(16, 'VH-0016', 'Car', 'Make E', 'Model 16', 2024, 'Petrol', 16),
(17, 'VH-0017', 'Two Wheeler', 'Make A', 'Model 17', 2022, 'Petrol', 17),
(18, 'VH-0018', 'Commercial', 'Make B', 'Model 18', 2023, 'Diesel', 18),
(19, 'VH-0019', 'Car', 'Make C', 'Model 19', 2021, 'CNG', 19),
(20, 'VH-0020', 'Two Wheeler', 'Make D', 'Model 20', 2024, 'Petrol', 20);

-- Policy Entries
INSERT INTO `policy_entries` (`id`, `entry_date`, `branch_id`, `vehicle_id`, `vehicle_number`, `vehicle_type`, `customer_id`, `customer_name`, `agent_id`, `agent_name`, `buyer_id`, `company_name`, `company_code`, `policy_number`, `total_premium_amount`, `tp_amount`, `od_amount`, `net_premium_amount`, `buyer_commission_basis`, `buyer_commission_rate`, `buyer_commission_amount`, `agent_commission_basis`, `agent_commission_rate`, `agent_commission_amount`, `policy_start_date`, `policy_end_date`, `status`, `created_by`) VALUES
(1, DATE_SUB(CURDATE(), INTERVAL 100 DAY), 1, 1, 'VH-0001', 'Car', 1, 'Customer 1', 1, 'Agent 1', 1, 'Company 1', 'CMP1', 'POL-2025-001', 15000.00, 5000.00, 8000.00, 13000.00, 'premium_pct', 15.0000, 2250.00, 'premium_pct', 10.0000, 1500.00, DATE_SUB(CURDATE(), INTERVAL 100 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 100 DAY), INTERVAL 1 YEAR), 'active', 1),
(2, DATE_SUB(CURDATE(), INTERVAL 95 DAY), 2, 2, 'VH-0002', 'Two Wheeler', 2, 'Customer 2', 2, 'Agent 2', 2, 'Company 2', 'CMP2', 'POL-2025-002', 5000.00, 2000.00, 2500.00, 4500.00, 'premium_pct', 12.0000, 600.00, 'premium_pct', 8.0000, 400.00, DATE_SUB(CURDATE(), INTERVAL 95 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 95 DAY), INTERVAL 1 YEAR), 'active', 1),
(3, DATE_SUB(CURDATE(), INTERVAL 90 DAY), 3, 3, 'VH-0003', 'Commercial', 3, 'Customer 3', 3, 'Agent 3', 3, 'Company 3', 'CMP3', 'POL-2025-003', 25000.00, 10000.00, 12000.00, 22000.00, 'premium_pct', 18.0000, 4500.00, 'premium_pct', 12.0000, 3000.00, DATE_SUB(CURDATE(), INTERVAL 90 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 90 DAY), INTERVAL 1 YEAR), 'active', 1),
(4, DATE_SUB(CURDATE(), INTERVAL 85 DAY), 4, 4, 'VH-0004', 'Car', 4, 'Customer 4', 1, 'Agent 1', 4, 'Company 4', 'CMP4', 'POL-2025-004', 12000.00, 4000.00, 6500.00, 10500.00, 'premium_pct', 14.0000, 1680.00, 'premium_pct', 10.0000, 1200.00, DATE_SUB(CURDATE(), INTERVAL 85 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 85 DAY), INTERVAL 1 YEAR), 'active', 1),
(5, DATE_SUB(CURDATE(), INTERVAL 80 DAY), 5, 5, 'VH-0005', 'Two Wheeler', 5, 'Customer 5', 2, 'Agent 2', 5, 'Company 5', 'CMP5', 'POL-2025-005', 8000.00, 3000.00, 4000.00, 7000.00, 'premium_pct', 13.0000, 1040.00, 'premium_pct', 9.0000, 720.00, DATE_SUB(CURDATE(), INTERVAL 80 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 80 DAY), INTERVAL 1 YEAR), 'active', 1),
(6, DATE_SUB(CURDATE(), INTERVAL 75 DAY), 1, 6, 'VH-0006', 'Commercial', 6, 'Customer 6', 3, 'Agent 3', 1, 'Company 1', 'CMP1', 'POL-2025-006', 20000.00, 8000.00, 10000.00, 18000.00, 'premium_pct', 16.0000, 3200.00, 'premium_pct', 11.0000, 2200.00, DATE_SUB(CURDATE(), INTERVAL 75 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 75 DAY), INTERVAL 1 YEAR), 'active', 1),
(7, DATE_SUB(CURDATE(), INTERVAL 70 DAY), 2, 7, 'VH-0007', 'Car', 7, 'Customer 7', NULL, NULL, 2, 'Company 2', 'CMP2', 'POL-2025-007', 10000.00, 3500.00, 5500.00, 9000.00, 'premium_pct', 15.0000, 1500.00, NULL, NULL, 0.00, DATE_SUB(CURDATE(), INTERVAL 70 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 70 DAY), INTERVAL 1 YEAR), 'active', 1),
(8, DATE_SUB(CURDATE(), INTERVAL 65 DAY), 3, 8, 'VH-0008', 'Two Wheeler', 8, 'Customer 8', 1, 'Agent 1', 3, 'Company 3', 'CMP3', 'POL-2025-008', 5000.00, 2000.00, 2500.00, 4500.00, 'premium_pct', 12.0000, 600.00, 'premium_pct', 8.0000, 400.00, DATE_SUB(CURDATE(), INTERVAL 65 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 65 DAY), INTERVAL 1 YEAR), 'active', 1),
(9, DATE_SUB(CURDATE(), INTERVAL 60 DAY), 4, 9, 'VH-0009', 'Commercial', 9, 'Customer 9', 2, 'Agent 2', 4, 'Company 4', 'CMP4', 'POL-2025-009', 25000.00, 10000.00, 12000.00, 22000.00, 'premium_pct', 17.0000, 4250.00, 'premium_pct', 12.0000, 3000.00, DATE_SUB(CURDATE(), INTERVAL 60 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 60 DAY), INTERVAL 1 YEAR), 'active', 1),
(10, DATE_SUB(CURDATE(), INTERVAL 55 DAY), 5, 10, 'VH-0010', 'Car', 10, 'Customer 10', 3, 'Agent 3', 5, 'Company 5', 'CMP5', 'POL-2025-010', 15000.00, 5000.00, 8000.00, 13000.00, 'premium_pct', 14.0000, 2100.00, 'premium_pct', 10.0000, 1500.00, DATE_SUB(CURDATE(), INTERVAL 55 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 55 DAY), INTERVAL 1 YEAR), 'active', 1),
(11, DATE_SUB(CURDATE(), INTERVAL 50 DAY), 1, 11, 'VH-0011', 'Two Wheeler', 11, 'Customer 11', 1, 'Agent 1', 1, 'Company 1', 'CMP1', 'POL-2025-011', 8000.00, 3000.00, 4000.00, 7000.00, 'premium_pct', 13.0000, 1040.00, 'premium_pct', 9.0000, 720.00, DATE_SUB(CURDATE(), INTERVAL 50 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 50 DAY), INTERVAL 1 YEAR), 'active', 1),
(12, DATE_SUB(CURDATE(), INTERVAL 45 DAY), 2, 12, 'VH-0012', 'Commercial', 12, 'Customer 12', NULL, NULL, 2, 'Company 2', 'CMP2', 'POL-2025-012', 20000.00, 8000.00, 10000.00, 18000.00, 'premium_pct', 16.0000, 3200.00, NULL, NULL, 0.00, DATE_SUB(CURDATE(), INTERVAL 45 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 45 DAY), INTERVAL 1 YEAR), 'active', 1),
(13, DATE_SUB(CURDATE(), INTERVAL 40 DAY), 3, 13, 'VH-0013', 'Car', 13, 'Customer 13', 2, 'Agent 2', 3, 'Company 3', 'CMP3', 'POL-2025-013', 12000.00, 4000.00, 6500.00, 10500.00, 'premium_pct', 15.0000, 1800.00, 'premium_pct', 10.0000, 1200.00, DATE_SUB(CURDATE(), INTERVAL 40 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 40 DAY), INTERVAL 1 YEAR), 'active', 1),
(14, DATE_SUB(CURDATE(), INTERVAL 35 DAY), 4, 14, 'VH-0014', 'Two Wheeler', 14, 'Customer 14', 3, 'Agent 3', 4, 'Company 4', 'CMP4', 'POL-2025-014', 5000.00, 2000.00, 2500.00, 4500.00, 'premium_pct', 12.0000, 600.00, 'premium_pct', 8.0000, 400.00, DATE_SUB(CURDATE(), INTERVAL 35 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 35 DAY), INTERVAL 1 YEAR), 'active', 1),
(15, DATE_SUB(CURDATE(), INTERVAL 30 DAY), 5, 15, 'VH-0015', 'Commercial', 15, 'Customer 15', 1, 'Agent 1', 5, 'Company 5', 'CMP5', 'POL-2025-015', 25000.00, 10000.00, 12000.00, 22000.00, 'premium_pct', 18.0000, 4500.00, 'premium_pct', 12.0000, 3000.00, DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 30 DAY), INTERVAL 1 YEAR), 'active', 1),
(16, DATE_SUB(CURDATE(), INTERVAL 25 DAY), 1, 16, 'VH-0016', 'Car', 16, 'Customer 16', 2, 'Agent 2', 1, 'Company 1', 'CMP1', 'POL-2025-016', 10000.00, 3500.00, 5500.00, 9000.00, 'premium_pct', 14.0000, 1400.00, 'premium_pct', 9.0000, 900.00, DATE_SUB(CURDATE(), INTERVAL 25 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 25 DAY), INTERVAL 1 YEAR), 'active', 1),
(17, DATE_SUB(CURDATE(), INTERVAL 20 DAY), 2, 17, 'VH-0017', 'Two Wheeler', 17, 'Customer 17', 3, 'Agent 3', 2, 'Company 2', 'CMP2', 'POL-2025-017', 8000.00, 3000.00, 4000.00, 7000.00, 'premium_pct', 13.0000, 1040.00, 'premium_pct', 8.0000, 640.00, DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 20 DAY), INTERVAL 1 YEAR), 'active', 1),
(18, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 3, 18, 'VH-0018', 'Commercial', 18, 'Customer 18', NULL, NULL, 3, 'Company 3', 'CMP3', 'POL-2025-018', 20000.00, 8000.00, 10000.00, 18000.00, 'premium_pct', 17.0000, 3400.00, NULL, NULL, 0.00, DATE_SUB(CURDATE(), INTERVAL 15 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 15 DAY), INTERVAL 1 YEAR), 'active', 1),
(19, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 4, 19, 'VH-0019', 'Car', 19, 'Customer 19', 1, 'Agent 1', 4, 'Company 4', 'CMP4', 'POL-2025-019', 15000.00, 5000.00, 8000.00, 13000.00, 'premium_pct', 15.0000, 2250.00, 'premium_pct', 10.0000, 1500.00, DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 10 DAY), INTERVAL 1 YEAR), 'active', 1),
(20, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 5, 20, 'VH-0020', 'Two Wheeler', 20, 'Customer 20', 2, 'Agent 2', 5, 'Company 5', 'CMP5', 'POL-2025-020', 5000.00, 2000.00, 2500.00, 4500.00, 'premium_pct', 12.0000, 600.00, 'premium_pct', 8.0000, 400.00, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 5 DAY), INTERVAL 1 YEAR), 'active', 1);

-- Bank Transactions
INSERT INTO `bank_transactions` (`id`, `bank_account_id`, `transaction_date`, `description`, `reference_number`, `transaction_type`, `amount`, `running_balance`, `category`, `created_by`) VALUES
(1, 1, DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'Premium received for POL-2025-001', 'TXN-001', 'credit', 15000.00, 115000.00, 'policy_payment', 1),
(2, 1, DATE_SUB(CURDATE(), INTERVAL 19 DAY), 'Agent payout - Agent 1', 'TXN-002', 'debit', 5000.00, 110000.00, 'agent_payout', 1),
(3, 1, DATE_SUB(CURDATE(), INTERVAL 18 DAY), 'Premium received for POL-2025-004', 'TXN-003', 'credit', 12000.00, 122000.00, 'policy_payment', 1),
(4, 1, DATE_SUB(CURDATE(), INTERVAL 17 DAY), 'Salary expense', 'TXN-004', 'debit', 25000.00, 97000.00, 'expense', 1),
(5, 1, DATE_SUB(CURDATE(), INTERVAL 16 DAY), 'Premium received for POL-2025-006', 'TXN-005', 'credit', 20000.00, 117000.00, 'policy_payment', 1),
(6, 2, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'Premium received for POL-2025-002', 'TXN-006', 'credit', 5000.00, 55000.00, 'policy_payment', 1),
(7, 2, DATE_SUB(CURDATE(), INTERVAL 14 DAY), 'Agent payout - Agent 2', 'TXN-007', 'debit', 3000.00, 52000.00, 'agent_payout', 1),
(8, 2, DATE_SUB(CURDATE(), INTERVAL 13 DAY), 'Rent payment', 'TXN-008', 'debit', 15000.00, 37000.00, 'expense', 1),
(9, 3, DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'Premium received for POL-2025-003', 'TXN-009', 'credit', 25000.00, 100000.00, 'policy_payment', 1),
(10, 3, DATE_SUB(CURDATE(), INTERVAL 11 DAY), 'Agent payout - Agent 3', 'TXN-010', 'debit', 3000.00, 97000.00, 'agent_payout', 1),
(11, 1, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'Buyer commission - Company 1', 'TXN-011', 'debit', 2250.00, 114750.00, 'buyer_payout', 1),
(12, 1, DATE_SUB(CURDATE(), INTERVAL 9 DAY), 'Premium received for POL-2025-011', 'TXN-012', 'credit', 8000.00, 122750.00, 'policy_payment', 1),
(13, 2, DATE_SUB(CURDATE(), INTERVAL 8 DAY), 'Premium received for POL-2025-005', 'TXN-013', 'credit', 8000.00, 45000.00, 'policy_payment', 1),
(14, 3, DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'Electricity payment', 'TXN-014', 'debit', 5000.00, 92000.00, 'expense', 1),
(15, 4, DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'Premium received for POL-2025-010', 'TXN-015', 'credit', 15000.00, 40000.00, 'policy_payment', 1),
(16, 4, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'Agent payout - Agent 1', 'TXN-016', 'debit', 1500.00, 38500.00, 'agent_payout', 1),
(17, 1, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'Internet bill', 'TXN-017', 'debit', 3000.00, 119750.00, 'expense', 1),
(18, 2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Buyer commission - Company 2', 'TXN-018', 'debit', 600.00, 44400.00, 'buyer_payout', 1),
(19, 3, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Premium received for POL-2025-013', 'TXN-019', 'credit', 12000.00, 104000.00, 'policy_payment', 1),
(20, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Stationery purchase', 'TXN-020', 'debit', 2000.00, 117750.00, 'expense', 1);

-- Expenses
INSERT INTO `expenses` (`id`, `expense_category_id`, `branch_id`, `expense_date`, `description`, `amount`, `payment_mode`, `bank_account_id`, `reference_number`, `created_by`) VALUES
(1, 1, 1, DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'Staff salary for month', 5000.00, 'Bank Transfer', 1, 'EXP-001', 1),
(2, 2, 1, DATE_SUB(CURDATE(), INTERVAL 19 DAY), 'Office rent payment', 3000.00, 'Bank Transfer', 1, 'EXP-002', 1),
(3, 3, 1, DATE_SUB(CURDATE(), INTERVAL 18 DAY), 'Monthly electricity bill', 1000.00, 'Bank Transfer', 1, 'EXP-003', 1),
(4, 4, 2, DATE_SUB(CURDATE(), INTERVAL 17 DAY), 'Internet and phone bill', 600.00, 'Bank Transfer', 2, 'EXP-004', 1),
(5, 5, 1, DATE_SUB(CURDATE(), INTERVAL 16 DAY), 'Printer ink and paper', 400.00, 'Cash', NULL, 'EXP-005', 1),
(6, 6, 2, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'Client visit travel cost', 700.00, 'Cash', NULL, 'EXP-006', 1),
(7, 7, 1, DATE_SUB(CURDATE(), INTERVAL 14 DAY), 'AC maintenance', 800.00, 'Bank Transfer', 1, 'EXP-007', 1);

-- Other Income
INSERT INTO `other_income` (`id`, `branch_id`, `income_date`, `source`, `amount`, `payment_mode`, `bank_account_id`, `reference_number`, `created_by`) VALUES
(1, 1, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'Consultation fee', 5000.00, 'Cash', NULL, 'INC-001', 1),
(2, 2, DATE_SUB(CURDATE(), INTERVAL 14 DAY), 'Processing fee', 3000.00, 'Bank Transfer', 2, 'INC-002', 1),
(3, 1, DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'Late payment charge', 1500.00, 'Cash', NULL, 'INC-003', 1),
(4, 3, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'Document handling fee', 2000.00, 'Bank Transfer', 3, 'INC-004', 1);

-- Notifications
INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `is_read`) VALUES
(1, 1, 'general', 'Welcome to the system', 'Your account has been set up successfully', 1),
(2, 1, 'policy_expiry', 'Policy expiring soon', 'POL-2025-018 is expiring soon', 0),
(3, 1, 'payment_pending', 'Payment pending', 'Agent 1 payout is pending', 0),
(4, 1, 'commission_update', 'Commission updated', 'Buyer payout chart updated for this month', 1);

-- Referrals
INSERT INTO `referrals` (`id`, `referrer_id`, `referred_id`, `points_earned`, `status`, `completed_at`) VALUES
(1, 1, 5, 100, 'completed', NOW()),
(2, 2, 10, 100, 'completed', NOW()),
(3, 3, 15, 0, 'pending', NULL);

-- Point Transactions
INSERT INTO `point_transactions` (`id`, `customer_id`, `transaction_type`, `points`, `rupee_value`, `description`, `referral_id`, `balance_after`) VALUES
(1, 1, 'earned', 100, 0.00, 'Referral reward for Customer 5', 1, 100),
(2, 2, 'earned', 100, 0.00, 'Referral reward for Customer 10', 2, 100),
(3, 1, 'redeemed', 50, 5.00, 'Redeemed for policy discount', NULL, 50);

-- Todos
INSERT INTO `todos` (`id`, `title`, `description`, `priority`, `status`, `due_date`, `assigned_to`, `created_by`) VALUES
(1, 'Call customers with lapsed policies', 'Follow up on policies that expired this month', 'high', 'pending', DATE_ADD(CURDATE(), INTERVAL 1 DAY), 2, 1),
(2, 'Send renewal quotes for next week', 'Quotes for policies expiring in the next 7 days', 'urgent', 'in_progress', CURDATE(), 2, 1),
(3, 'Reconcile Bank A statement', 'Match premium receipts with bank entries', 'medium', 'pending', DATE_ADD(CURDATE(), INTERVAL 3 DAY), 1, 1);

-- ------------------------------------------------------------
-- Table: subscription_plans
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `subscription_plans`;
CREATE TABLE `subscription_plans` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL UNIQUE,
  `description` TEXT NULL,
  `monthly_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `quarterly_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `yearly_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `validity_days` INT NOT NULL DEFAULT 30,
  `max_customers` INT NOT NULL DEFAULT 100,
  `max_policies` INT NOT NULL DEFAULT 200,
  `monthly_ai_scans` INT NOT NULL DEFAULT 50,
  `team_members_limit` INT NOT NULL DEFAULT 1,
  `whatsapp_limit` INT NOT NULL DEFAULT 500,
  `reports_access` BOOLEAN NOT NULL DEFAULT TRUE,
  `advanced_features` BOOLEAN NOT NULL DEFAULT FALSE,
  `is_trial` BOOLEAN NOT NULL DEFAULT FALSE,
  `trial_days` INT NOT NULL DEFAULT 0,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `display_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_plan_slug` (`slug`),
  INDEX `idx_plan_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `subscription_plans` (`id`, `name`, `slug`, `description`, `monthly_price`, `quarterly_price`, `yearly_price`, `validity_days`, `max_customers`, `max_policies`, `monthly_ai_scans`, `team_members_limit`, `whatsapp_limit`, `reports_access`, `advanced_features`, `is_trial`, `trial_days`, `display_order`) VALUES
(1, 'Starter Agent', 'starter-agent', 'Ideal for independent agents starting their insurance portfolio.', 499.00, 1299.00, 4499.00, 30, 100, 200, 30, 1, 300, 1, 0, 0, 0, 1),
(2, 'Professional Agent', 'professional-agent', 'Most popular plan for growing insurance agents with automated reminders and AI scanner.', 999.00, 2699.00, 8999.00, 30, 500, 1000, 150, 3, 1500, 1, 1, 0, 0, 2),
(3, 'Agency Enterprise', 'agency-enterprise', 'Unlimited capacity for established agencies and top insurance performers.', 1999.00, 5499.00, 17999.00, 30, 999999, 999999, 500, 10, 5000, 1, 1, 0, 0, 3),
(4, '14-Day Free Trial', 'free-trial', 'Experience the full power of AI policy scanning and WhatsApp automated reminders risk-free.', 0.00, 0.00, 0.00, 14, 25, 50, 15, 1, 100, 1, 1, 1, 14, 0);

-- ------------------------------------------------------------
-- Table: subscriptions
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `subscriptions`;
CREATE TABLE `subscriptions` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `agent_id` BIGINT NOT NULL,
  `plan_id` BIGINT NOT NULL,
  `billing_cycle` ENUM('monthly','quarterly','yearly','trial') NOT NULL DEFAULT 'monthly',
  `start_date` DATE NOT NULL,
  `expiry_date` DATE NOT NULL,
  `status` ENUM('pending','active','expired','cancelled','suspended','payment_failed') NOT NULL DEFAULT 'pending',
  `customer_count` INT NOT NULL DEFAULT 0,
  `policy_count` INT NOT NULL DEFAULT 0,
  `ai_scan_count` INT NOT NULL DEFAULT 0,
  `whatsapp_count` INT NOT NULL DEFAULT 0,
  `auto_renew` BOOLEAN NOT NULL DEFAULT TRUE,
  `cancelled_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans`(`id`),
  INDEX `idx_sub_agent` (`agent_id`),
  INDEX `idx_sub_status` (`status`),
  INDEX `idx_sub_expiry` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: subscription_payments
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `subscription_payments`;
CREATE TABLE `subscription_payments` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `subscription_id` BIGINT NULL,
  `agent_id` BIGINT NOT NULL,
  `plan_id` BIGINT NOT NULL,
  `order_id` VARCHAR(100) NULL,
  `payment_id` VARCHAR(100) NULL,
  `payment_signature` VARCHAR(255) NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'INR',
  `billing_cycle` ENUM('monthly','quarterly','yearly','trial') NOT NULL DEFAULT 'monthly',
  `payment_method` VARCHAR(50) NOT NULL DEFAULT 'razorpay',
  `status` ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  `invoice_number` VARCHAR(50) NULL UNIQUE,
  `receipt_url` VARCHAR(255) NULL,
  `webhook_payload` LONGTEXT NULL,
  `paid_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans`(`id`),
  INDEX `idx_pay_order` (`order_id`),
  INDEX `idx_pay_payment` (`payment_id`),
  INDEX `idx_pay_agent` (`agent_id`),
  INDEX `idx_pay_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: policy_extractions
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `policy_extractions`;
CREATE TABLE `policy_extractions` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `document_id` BIGINT NULL,
  `policy_entry_id` BIGINT NULL,
  `agent_id` BIGINT NULL,
  `customer_id` BIGINT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `raw_text` LONGTEXT NULL,
  `extracted_data` LONGTEXT NULL,
  `confidence_scores` TEXT NULL,
  `ai_provider` VARCHAR(50) NOT NULL DEFAULT 'gemini',
  `scanning_status` ENUM('uploaded','processing','completed','failed','manual_review') NOT NULL DEFAULT 'uploaded',
  `error_message` TEXT NULL,
  `processing_time_ms` INT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE SET NULL,
  INDEX `idx_pe_agent` (`agent_id`),
  INDEX `idx_pe_status` (`scanning_status`),
  INDEX `idx_pe_policy` (`policy_entry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: reminder_schedules
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `reminder_schedules`;
CREATE TABLE `reminder_schedules` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `policy_entry_id` BIGINT NOT NULL,
  `agent_id` BIGINT NULL,
  `customer_id` BIGINT NOT NULL,
  `reminder_stage` ENUM('30_days','14_days','7_days','2_days','expiry_day','post_expiry') NOT NULL,
  `scheduled_date` DATE NOT NULL,
  `scheduled_channel` ENUM('whatsapp','email','both') NOT NULL DEFAULT 'both',
  `status` ENUM('pending','sent','failed','cancelled','skipped') NOT NULL DEFAULT 'pending',
  `sent_at` DATETIME NULL,
  `failure_reason` TEXT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`policy_entry_id`) REFERENCES `policy_entries`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  INDEX `idx_rs_status_date` (`status`, `scheduled_date`),
  INDEX `idx_rs_policy` (`policy_entry_id`),
  INDEX `idx_rs_agent` (`agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: notification_logs
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `notification_logs`;
CREATE TABLE `notification_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `reminder_schedule_id` BIGINT NULL,
  `policy_entry_id` BIGINT NULL,
  `agent_id` BIGINT NULL,
  `customer_id` BIGINT NOT NULL,
  `channel` ENUM('whatsapp','email','sms') NOT NULL DEFAULT 'whatsapp',
  `recipient_contact` VARCHAR(200) NOT NULL,
  `message_content` TEXT NOT NULL,
  `template_id` VARCHAR(100) NULL,
  `status` ENUM('queued','sent','delivered','read','failed') NOT NULL DEFAULT 'sent',
  `provider_message_id` VARCHAR(150) NULL,
  `provider_response` TEXT NULL,
  `error_message` TEXT NULL,
  `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  INDEX `idx_nl_agent` (`agent_id`),
  INDEX `idx_nl_status` (`status`),
  INDEX `idx_nl_sent` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: customer_followups
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `customer_followups`;
CREATE TABLE `customer_followups` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `policy_entry_id` BIGINT NULL,
  `customer_id` BIGINT NOT NULL,
  `agent_id` BIGINT NULL,
  `followup_stage` ENUM('new_renewal','contacted','interested','quote_sent','negotiation','renewed','lost') NOT NULL DEFAULT 'new_renewal',
  `call_notes` TEXT NULL,
  `next_followup_date` DATE NULL,
  `assigned_agent_id` BIGINT NULL,
  `is_completed` BOOLEAN NOT NULL DEFAULT FALSE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE CASCADE,
  INDEX `idx_cf_agent` (`agent_id`),
  INDEX `idx_cf_stage` (`followup_stage`),
  INDEX `idx_cf_next` (`next_followup_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: support_tickets
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `support_tickets`;
CREATE TABLE `support_tickets` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `ticket_number` VARCHAR(30) NOT NULL UNIQUE,
  `agent_id` BIGINT NOT NULL,
  `subject` VARCHAR(200) NOT NULL,
  `category` ENUM('billing','ai_scanner','whatsapp','policy','general') NOT NULL DEFAULT 'general',
  `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status` ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  `last_reply_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE CASCADE,
  INDEX `idx_st_agent` (`agent_id`),
  INDEX `idx_st_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: ticket_messages
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `ticket_messages`;
CREATE TABLE `ticket_messages` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` BIGINT NOT NULL,
  `sender_user_id` BIGINT NOT NULL,
  `sender_role` ENUM('agent','admin','support') NOT NULL DEFAULT 'agent',
  `message` TEXT NOT NULL,
  `attachment_path` VARCHAR(255) NULL,
  `is_internal` BOOLEAN NOT NULL DEFAULT FALSE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets`(`id`) ON DELETE CASCADE,
  INDEX `idx_tm_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: agent_verifications
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `agent_verifications`;
CREATE TABLE `agent_verifications` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL,
  `verification_type` ENUM('mobile_otp','email_token') NOT NULL,
  `token_or_otp` VARCHAR(100) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `is_verified` BOOLEAN NOT NULL DEFAULT FALSE,
  `verified_at` DATETIME NULL,
  `attempts_count` INT NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_av_user` (`user_id`, `verification_type`),
  INDEX `idx_av_token` (`token_or_otp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Sample Portfolio Data (3 Active Agents, Customers, Vehicles & 10 Realistic Policies)
-- ------------------------------------------------------------
INSERT INTO `branches` (`id`, `branch_code`, `name`, `mobile`, `email`, `address`, `city`, `pincode`, `state`, `wallet_balance`, `is_active`) VALUES
(1, 'BR-01', 'Ahmedabad Main Branch', '9876500001', 'ahmedabad@demo.com', 'Ashram Road', 'Ahmedabad', '380009', 'Gujarat', 0.00, 1),
(2, 'BR-02', 'Surat Ring Road Branch', '9876500002', 'surat@demo.com', 'Ring Road', 'Surat', '395002', 'Gujarat', 0.00, 1),
(3, 'BR-03', 'Vadodara Alkapuri Branch', '9876500003', 'baroda@demo.com', 'Alkapuri', 'Vadodara', '390007', 'Gujarat', 0.00, 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

INSERT INTO `users` (`id`, `branch_id`, `name`, `email`, `phone`, `password_hash`, `role`, `is_active`, `status`) VALUES
(101, 1, 'Rahul Sharma', 'rahul.agent@demo.com', '9876543210', '$2y$10$wT8K8U1Qz/j5p9oW8V.v3OMYfWqB2rK8s7V1f6c7C0hJ4nZ2n4y8K', 'agent', 1, 'active'),
(102, 2, 'Priya Patel', 'priya.agent@demo.com', '9876543211', '$2y$10$wT8K8U1Qz/j5p9oW8V.v3OMYfWqB2rK8s7V1f6c7C0hJ4nZ2n4y8K', 'agent', 1, 'active'),
(103, 3, 'Amit Verma', 'amit.agent@demo.com', '9876543212', '$2y$10$wT8K8U1Qz/j5p9oW8V.v3OMYfWqB2rK8s7V1f6c7C0hJ4nZ2n4y8K', 'agent', 1, 'active')
ON DUPLICATE KEY UPDATE `role`='agent', `status`='active', `is_active`=1;

INSERT INTO `agents` (`id`, `user_id`, `branch_id`, `agent_code`, `name`, `agency_name`, `mobile`, `email`, `city`, `pincode`, `state`, `wallet_balance`, `outstanding_balance`) VALUES
(1, 101, 1, 'AGT-1001', 'Rahul Sharma', 'Sharma Insurance Advisory', '9876543210', 'rahul.agent@demo.com', 'Ahmedabad', '380015', 'Gujarat', 0.00, 0.00),
(2, 102, 2, 'AGT-1002', 'Priya Patel', 'Patel Care & Protection', '9876543211', 'priya.agent@demo.com', 'Surat', '395007', 'Gujarat', 0.00, 0.00),
(3, 103, 3, 'AGT-1003', 'Amit Verma', 'Verma Financial Services', '9876543212', 'amit.agent@demo.com', 'Vadodara', '390020', 'Gujarat', 0.00, 0.00)
ON DUPLICATE KEY UPDATE `agent_code`=VALUES(`agent_code`), `name`=VALUES(`name`);

INSERT INTO `customers` (`id`, `agent_id`, `name`, `mobile`, `email`, `city`, `reference_code`, `family_relation`, `created_at`) VALUES
(1, 1, 'Mukesh Patel', '9825012345', 'mukesh.patel@example.com', 'Ahmedabad', 'REF-PATEL1', NULL, NOW()),
(2, 1, 'Meena Patel', '9825012346', 'meena.patel@example.com', 'Ahmedabad', 'REF-PATEL2', 'Wife', NOW()),
(3, 1, 'Yash Patel', '9825012347', 'yash.patel@example.com', 'Ahmedabad', 'REF-PATEL3', 'Son', NOW()),
(4, 2, 'Rajesh Mehta', '9825023456', 'rajesh.mehta@example.com', 'Surat', 'REF-MEHTA1', NULL, NOW()),
(5, 2, 'Anita Mehta', '9825023457', 'anita.mehta@example.com', 'Surat', 'REF-MEHTA2', 'Wife', NOW()),
(6, 3, 'Suresh Joshi', '9825034567', 'suresh.joshi@example.com', 'Vadodara', 'REF-JOSHI1', NULL, NOW()),
(7, 2, 'Bhavna Shah', '9825045678', 'bhavna.shah@example.com', 'Rajkot', 'REF-SHAH1', NULL, NOW())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

UPDATE `customers` SET `family_head_id` = 1 WHERE `id` IN (2, 3);
UPDATE `customers` SET `family_head_id` = 4 WHERE `id` = 5;

INSERT INTO `vehicles` (`id`, `customer_id`, `vehicle_number`, `vehicle_type`, `make`, `model`, `fuel_type`, `created_at`) VALUES
(1, 1, 'GJ-01-AB-1234', 'private_car', 'Hyundai', 'Creta 1.5 SX', 'Petrol', NOW()),
(2, 2, 'GJ-01-CD-5678', 'two_wheeler', 'Honda', 'Activa 6G', 'Petrol', NOW()),
(3, 4, 'GJ-05-EF-9012', 'commercial_goods', 'Tata Motors', 'Ace EV Cargo', 'Electric', NOW()),
(4, 6, 'GJ-06-GH-3456', 'private_car', 'Maruti Suzuki', 'Brezza ZXi', 'Petrol', NOW()),
(5, 7, 'GJ-03-JK-7890', 'two_wheeler', 'Royal Enfield', 'Classic 350', 'Petrol', NOW())
ON DUPLICATE KEY UPDATE `model`=VALUES(`model`);

INSERT INTO `policy_entries` (
  `id`, `insurance_category`, `entry_date`, `branch_id`, `vehicle_id`, `vehicle_number`, `vehicle_type`,
  `customer_id`, `customer_name`, `agent_id`, `agent_name`, `company_name`, `company_code`,
  `policy_number`, `policy_type`, `business_type`, `total_premium_amount`, `idv`,
  `tp_amount`, `od_amount`, `net_premium_amount`, `gst_amount`,
  `sum_insured`, `plan_name`, `tpa_name`, `health_policy_type`, `members_count`, `members_details`,
  `sum_assured`, `life_plan_type`, `policy_term`, `payment_frequency`, `nominee_name`, `nominee_relation`,
  `policy_start_date`, `policy_end_date`, `status`, `created_by`
) VALUES
(1, 'motor', CURDATE(), 1, 1, 'GJ-01-AB-1234', 'private_car', 1, 'Mukesh Patel', 1, 'Rahul Sharma', 'HDFC ERGO General Insurance', 'HDFC-ERGO', 'HDFC-MOT-2025-98124', 'comprehensive', 'rollover', 18450.00, 850000.00, 3416.00, 12220.00, 15636.00, 2814.00, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, DATE_SUB(CURDATE(), INTERVAL 362 DAY), DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'active', 1),
(2, 'health', CURDATE(), 1, NULL, NULL, NULL, 1, 'Mukesh Patel', 1, 'Rahul Sharma', 'Star Health and Allied Insurance', 'STAR-HEALTH', 'STAR-HLT-2025-44219', NULL, 'renewal', 24600.00, NULL, 0.00, 0.00, 20847.00, 3753.00, 1000000.00, 'Family Health Optima', 'In-House TPA', 'floater', 3, 'Mukesh Patel (Self, 42), Meena Patel (Spouse, 39), Yash Patel (Son, 14)', NULL, NULL, NULL, NULL, 'Meena Patel', 'Wife', DATE_SUB(CURDATE(), INTERVAL 358 DAY), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'active', 1),
(3, 'life', CURDATE(), 1, NULL, NULL, NULL, 1, 'Mukesh Patel', 1, 'Rahul Sharma', 'Life Insurance Corporation of India (LIC)', 'LIC-INDIA', 'LIC-LIFE-904128456', NULL, 'renewal', 45000.00, NULL, 0.00, 0.00, 42857.00, 2143.00, NULL, 'LIC Jeevan Umang', NULL, NULL, NULL, NULL, 2500000.00, 'whole_life', 20, 'yearly', 'Meena Patel', 'Wife', DATE_SUB(CURDATE(), INTERVAL 351 DAY), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'active', 1),
(4, 'motor', CURDATE(), 1, 2, 'GJ-01-CD-5678', 'two_wheeler', 2, 'Meena Patel', 1, 'Rahul Sharma', 'ICICI Lombard General Insurance', 'ICICI-LOMBARD', 'ICICI-MOT-TW-78412', 'comprehensive', 'renewal', 2850.00, 58000.00, 814.00, 1601.00, 2415.00, 435.00, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, DATE_SUB(CURDATE(), INTERVAL 344 DAY), DATE_ADD(CURDATE(), INTERVAL 21 DAY), 'active', 1),
(5, 'health', CURDATE(), 2, NULL, NULL, NULL, 4, 'Rajesh Mehta', 2, 'Priya Patel', 'Care Health Insurance', 'CARE-HEALTH', 'CARE-HLT-2025-77123', NULL, 'new', 19800.00, NULL, 0.00, 0.00, 16780.00, 3020.00, 750000.00, 'Care Supreme Floater', 'Care In-House', 'floater', 2, 'Rajesh Mehta (Self, 48), Anita Mehta (Spouse, 45)', NULL, NULL, NULL, NULL, 'Anita Mehta', 'Wife', DATE_SUB(CURDATE(), INTERVAL 337 DAY), DATE_ADD(CURDATE(), INTERVAL 28 DAY), 'active', 1),
(6, 'motor', CURDATE(), 2, 3, 'GJ-05-EF-9012', 'commercial_goods', 4, 'Rajesh Mehta', 2, 'Priya Patel', 'Tata AIG General Insurance', 'TATA-AIG', 'TATA-COM-2025-33981', 'comprehensive', 'renewal', 28500.00, 620000.00, 12800.00, 11352.00, 24152.00, 4348.00, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, DATE_SUB(CURDATE(), INTERVAL 320 DAY), DATE_ADD(CURDATE(), INTERVAL 45 DAY), 'active', 1),
(7, 'life', CURDATE(), 2, NULL, NULL, NULL, 4, 'Rajesh Mehta', 2, 'Priya Patel', 'HDFC Life Insurance', 'HDFC-LIFE', 'HDFC-LIFE-TERM-5512', NULL, 'new', 28000.00, NULL, 0.00, 0.00, 23728.00, 4272.00, NULL, 'Click 2 Protect Super', NULL, NULL, NULL, NULL, 10000000.00, 'term', 30, 'yearly', 'Anita Mehta', 'Wife', DATE_SUB(CURDATE(), INTERVAL 305 DAY), DATE_ADD(CURDATE(), INTERVAL 60 DAY), 'active', 1),
(8, 'motor', CURDATE(), 3, 4, 'GJ-06-GH-3456', 'private_car', 6, 'Suresh Joshi', 3, 'Amit Verma', 'Bajaj Allianz General Insurance', 'BAJAJ-ALLIANZ', 'BAJAJ-PVT-2025-88124', 'comprehensive', 'renewal', 14200.00, 720000.00, 3416.00, 8618.00, 12034.00, 2166.00, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, DATE_SUB(CURDATE(), INTERVAL 275 DAY), DATE_ADD(CURDATE(), INTERVAL 90 DAY), 'active', 1),
(9, 'health', CURDATE(), 3, NULL, NULL, NULL, 6, 'Suresh Joshi', 3, 'Amit Verma', 'Niva Bupa Health Insurance', 'NIVA-BUPA', 'NIVA-HLT-IND-66321', NULL, 'renewal', 11500.00, NULL, 0.00, 0.00, 9746.00, 1754.00, 500000.00, 'ReAssure 2.0 Individual', 'Niva Direct', 'individual', 1, 'Suresh Joshi (Self, 54)', NULL, NULL, NULL, NULL, 'Varun Joshi', 'Son', DATE_SUB(CURDATE(), INTERVAL 245 DAY), DATE_ADD(CURDATE(), INTERVAL 120 DAY), 'active', 1),
(10, 'motor', CURDATE(), 2, 5, 'GJ-03-JK-7890', 'two_wheeler', 7, 'Bhavna Shah', 2, 'Priya Patel', 'New India Assurance', 'NEW-INDIA', 'NIA-TW-2024-11892', 'tp_only', 'rollover', 1014.00, NULL, 859.00, 0.00, 859.00, 155.00, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, DATE_SUB(CURDATE(), INTERVAL 370 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'expired', 1)
ON DUPLICATE KEY UPDATE `policy_number`=VALUES(`policy_number`);

INSERT INTO `reminder_schedules` (`policy_entry_id`, `reminder_type`, `scheduled_date`, `status`) VALUES
(1, '7_days_before', DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 3 DAY), INTERVAL 7 DAY), 'pending'),
(1, '2_days_before', DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 3 DAY), INTERVAL 2 DAY), 'pending'),
(1, 'on_expiry', DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'pending'),
(2, '14_days_before', DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 7 DAY), INTERVAL 14 DAY), 'pending'),
(2, '7_days_before', DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 7 DAY), INTERVAL 7 DAY), 'pending'),
(2, 'on_expiry', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'pending'),
(3, '30_days_before', DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 14 DAY), INTERVAL 30 DAY), 'pending'),
(3, '14_days_before', DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 14 DAY), INTERVAL 14 DAY), 'pending'),
(4, '30_days_before', DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 21 DAY), INTERVAL 30 DAY), 'pending'),
(4, '7_days_before', DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 21 DAY), INTERVAL 7 DAY), 'pending'),
(5, '30_days_before', DATE_SUB(DATE_ADD(CURDATE(), INTERVAL 28 DAY), INTERVAL 30 DAY), 'pending')
ON DUPLICATE KEY UPDATE `status`=VALUES(`status`);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- End of database.sql
-- ============================================================
