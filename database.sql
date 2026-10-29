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
  `role` ENUM('admin','manager','agent') NOT NULL DEFAULT 'agent',
  `branch_id` BIGINT NULL,
  `is_active` BOOLEAN DEFAULT TRUE,
  `profile_image` VARCHAR(255) NULL,
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
  `mobile` VARCHAR(15) NOT NULL,
  `email` VARCHAR(200) NULL,
  `address` TEXT NULL,
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
  INDEX `idx_license_expiry` (`license_expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: buyers
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `buyers`;
CREATE TABLE `buyers` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `company_name` VARCHAR(200) NOT NULL,
  `company_code` VARCHAR(30) NOT NULL UNIQUE,
  `contact_person` VARCHAR(150) NULL,
  `mobile` VARCHAR(15) NULL,
  `email` VARCHAR(200) NULL,
  `address` TEXT NULL,
  `state` VARCHAR(100) NULL,
  `gst_number` VARCHAR(15) NULL,
  `pan_number` VARCHAR(10) NULL,
  `tds_rate` DECIMAL(5,2) NOT NULL DEFAULT 2.00,
  `payment_terms_days` SMALLINT NULL,
  `wallet_balance` DECIMAL(14,2) DEFAULT 0.00,
  `outstanding_balance` DECIMAL(14,2) DEFAULT 0.00,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_company_code` (`company_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: customers
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `mobile` VARCHAR(15) NOT NULL,
  `whatsapp_number` VARCHAR(15) NULL,
  `date_of_birth` DATE NULL,
  `email` VARCHAR(200) NULL,
  `address` TEXT NULL,
  `city` VARCHAR(100) NULL,
  `pincode` VARCHAR(10) NULL,
  `state` VARCHAR(100) NULL,
  `ckyc_number` VARCHAR(20) NULL,
  `referral_code` VARCHAR(20) NULL UNIQUE,
  `referred_by` BIGINT NULL,
  `agent_id` BIGINT NULL,
  `points_balance` INT DEFAULT 0,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_by` BIGINT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`referred_by`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
  INDEX `idx_referral_code` (`referral_code`),
  INDEX `idx_referred_by` (`referred_by`),
  INDEX `idx_cust_agent` (`agent_id`)
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
  `buyer_id` BIGINT NOT NULL,
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
  -- Commission & Accounting
  `buyer_commission_basis` ENUM('premium_pct','tp_pct','od_pct','net_pct','fixed') NOT NULL,
  `buyer_commission_rate` DECIMAL(8,4) NOT NULL,
  `buyer_commission_amount` DECIMAL(12,2) NOT NULL,
  `agent_commission_basis` ENUM('premium_pct','tp_pct','od_pct','net_pct','fixed') NULL,
  `agent_commission_rate` DECIMAL(8,4) NULL,
  `agent_commission_amount` DECIMAL(12,2) DEFAULT 0.00,
  `is_commission_manual` BOOLEAN DEFAULT FALSE,
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
  FOREIGN KEY (`buyer_id`) REFERENCES `buyers`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_insurance_category` (`insurance_category`),
  INDEX `idx_policy_number` (`policy_number`),
  INDEX `idx_entry_date` (`entry_date`),
  INDEX `idx_branch_id` (`branch_id`),
  INDEX `idx_customer_id` (`customer_id`),
  INDEX `idx_agent_id` (`agent_id`),
  INDEX `idx_buyer_id` (`buyer_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_policy_dates` (`policy_start_date`, `policy_end_date`),
  INDEX `idx_prev_policy` (`previous_policy_id`),
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
  `buyer_id` BIGINT NULL,
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
  INDEX `idx_bt_buyer` (`buyer_id`),
  INDEX `idx_other_income` (`other_income_id`),
  INDEX `idx_reconciled` (`is_reconciled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: buyer_payout_charts
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `buyer_payout_charts`;
CREATE TABLE `buyer_payout_charts` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `buyer_id` BIGINT NOT NULL,
  `vehicle_type` VARCHAR(50) NOT NULL,
  `month` TINYINT NOT NULL,
  `year` SMALLINT NOT NULL,
  `commission_basis` ENUM('premium_pct','tp_pct','od_pct','net_pct','fixed') NOT NULL,
  `commission_rate` DECIMAL(8,4) NOT NULL,
  `is_active` BOOLEAN DEFAULT TRUE,
  `uploaded_via` ENUM('manual','bulk_upload') DEFAULT 'manual',
  `created_by` BIGINT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`buyer_id`) REFERENCES `buyers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  UNIQUE KEY `uk_buyer_vtype_month_year` (`buyer_id`, `vehicle_type`, `month`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: agent_payout_charts
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `agent_payout_charts`;
CREATE TABLE `agent_payout_charts` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `agent_id` BIGINT NULL,
  `buyer_id` BIGINT NULL,
  `vehicle_type` VARCHAR(50) NOT NULL,
  `month` TINYINT NOT NULL,
  `year` SMALLINT NOT NULL,
  `commission_basis` ENUM('premium_pct','tp_pct','od_pct','net_pct','fixed') NOT NULL,
  `commission_rate` DECIMAL(8,4) NOT NULL,
  `is_custom` BOOLEAN DEFAULT FALSE,
  `is_active` BOOLEAN DEFAULT TRUE,
  `uploaded_via` ENUM('manual','bulk_upload') DEFAULT 'manual',
  `created_by` BIGINT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`buyer_id`) REFERENCES `buyers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  UNIQUE KEY `uk_agent_buyer_vtype_month_year` (`agent_id`, `buyer_id`, `vehicle_type`, `month`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: agent_transactions
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `agent_transactions`;
CREATE TABLE `agent_transactions` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `agent_id` BIGINT NOT NULL,
  `transaction_date` DATE NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `transaction_type` ENUM('commission','payment_in','payment_out','adjustment') NOT NULL,
  `credit_amount` DECIMAL(12,2) DEFAULT 0.00,
  `debit_amount` DECIMAL(12,2) DEFAULT 0.00,
  `tds_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `running_balance` DECIMAL(14,2) NOT NULL,
  `policy_entry_id` BIGINT NULL,
  `payment_mode` VARCHAR(30) NULL,
  `reference_number` VARCHAR(50) NULL,
  `bank_account_id` BIGINT NULL,
  `bank_txn_id` BIGINT NULL,
  `is_linked_mirror` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` BIGINT NOT NULL,
  `updated_by` BIGINT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`policy_entry_id`) REFERENCES `policy_entries`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_agent_id` (`agent_id`),
  INDEX `idx_transaction_date` (`transaction_date`),
  INDEX `idx_at_bank` (`bank_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table: buyer_transactions
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `buyer_transactions`;
CREATE TABLE `buyer_transactions` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `buyer_id` BIGINT NOT NULL,
  `transaction_date` DATE NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `transaction_type` ENUM('commission_receivable','payment_received','payment_made','adjustment') NOT NULL,
  `credit_amount` DECIMAL(12,2) DEFAULT 0.00,
  `debit_amount` DECIMAL(12,2) DEFAULT 0.00,
  `tds_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `gst_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `running_balance` DECIMAL(14,2) NOT NULL,
  `policy_entry_id` BIGINT NULL,
  `payment_mode` VARCHAR(30) NULL,
  `reference_number` VARCHAR(50) NULL,
  `invoice_number` VARCHAR(50) NULL,
  `bank_account_id` BIGINT NULL,
  `bank_txn_id` BIGINT NULL,
  `is_linked_mirror` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` BIGINT NOT NULL,
  `updated_by` BIGINT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`buyer_id`) REFERENCES `buyers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`policy_entry_id`) REFERENCES `policy_entries`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_buyer_id` (`buyer_id`),
  INDEX `idx_transaction_date` (`transaction_date`),
  INDEX `idx_but_bank` (`bank_account_id`)
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
('site_logo', 'https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg'),
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
INSERT INTO `users` (`id`, `name`, `email`, `phone`, `password_hash`, `role`, `branch_id`, `is_active`) VALUES
(1, 'Admin User', 'admin@demo.com', '03001000001', '$2y$10$xX3NV4KaLJcFN1DUK9h5WOPGBvzxxK7B/6WJk24kzlQxy5Qq6saPy', 'admin', 1, 1),
(2, 'Manager 1', 'manager1@demo.com', '03001000002', '$2y$10$xX3NV4KaLJcFN1DUK9h5WOPGBvzxxK7B/6WJk24kzlQxy5Qq6saPy', 'manager', 1, 1),
(3, 'Manager 2', 'manager2@demo.com', '03001000003', '$2y$10$xX3NV4KaLJcFN1DUK9h5WOPGBvzxxK7B/6WJk24kzlQxy5Qq6saPy', 'manager', 2, 1),
(4, 'Agent 1', 'agent1@demo.com', '03001000004', '$2y$10$xX3NV4KaLJcFN1DUK9h5WOPGBvzxxK7B/6WJk24kzlQxy5Qq6saPy', 'agent', 1, 1),
(5, 'Agent 2', 'agent2@demo.com', '03001000005', '$2y$10$xX3NV4KaLJcFN1DUK9h5WOPGBvzxxK7B/6WJk24kzlQxy5Qq6saPy', 'agent', 2, 1),
(6, 'Agent 3', 'agent3@demo.com', '03001000006', '$2y$10$xX3NV4KaLJcFN1DUK9h5WOPGBvzxxK7B/6WJk24kzlQxy5Qq6saPy', 'agent', 3, 1);

-- Agents
INSERT INTO `agents` (`id`, `user_id`, `branch_id`, `agent_code`, `name`, `mobile`, `email`, `state`, `wallet_balance`, `outstanding_balance`) VALUES
(1, 4, 1, 'AGT-0001', 'Agent 1', '03001000004', 'agent1@demo.com', 'State A', 0.00, 0.00),
(2, 5, 2, 'AGT-0002', 'Agent 2', '03001000005', 'agent2@demo.com', 'State B', 0.00, 0.00),
(3, 6, 3, 'AGT-0003', 'Agent 3', '03001000006', 'agent3@demo.com', 'State A', 0.00, 0.00);

-- Buyers
INSERT INTO `buyers` (`id`, `company_name`, `company_code`, `contact_person`, `mobile`, `email`, `wallet_balance`, `outstanding_balance`) VALUES
(1, 'Company 1', 'CMP1', 'Contact 1', '0211000001', 'company1@demo.com', 0.00, 0.00),
(2, 'Company 2', 'CMP2', 'Contact 2', '0211000002', 'company2@demo.com', 0.00, 0.00),
(3, 'Company 3', 'CMP3', 'Contact 3', '0211000003', 'company3@demo.com', 0.00, 0.00),
(4, 'Company 4', 'CMP4', 'Contact 4', '0211000004', 'company4@demo.com', 0.00, 0.00),
(5, 'Company 5', 'CMP5', 'Contact 5', '0211000005', 'company5@demo.com', 0.00, 0.00);

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

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- End of database.sql
-- ============================================================
