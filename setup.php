<?php
/**
 * Developed by Rudees Digital
 * Website: https://rudeesdigital.com
 * Email: contact@rudeesdigital.com
 * Website: https://rudeesdigital.com
  Email: contact@rudeesdigital.com
  WhatsApp: +91 8160140848
 *
 * Database Setup — dual mode (update / reset)
 */

session_start();

// standalone db creds — Hostinger
define('DB_HOST', 'localhost');
define('DB_NAME', 'u456035299_policy_manage');
define('DB_USER', 'u456035299_rudees_digital');
define('DB_PASS', 'Rudees@2026');

// raw connection helper
function setupConn()
{
    if (!class_exists('mysqli')) {
        die('<div class="log-item log-error"><i class="fas fa-times-circle"></i> PHP MySQLi extension is not enabled in your PHP configuration.</div>');
    }
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            die('<div class="log-item log-error"><i class="fas fa-times-circle"></i> Connection failed: ' . htmlspecialchars($conn->connect_error) . '</div>');
        }
        $conn->set_charset('utf8mb4');
        return $conn;
    } catch (Throwable $e) {
        die('<div class="log-item log-error"><i class="fas fa-times-circle"></i> Database Connection Error: ' . htmlspecialchars($e->getMessage()) . '<br><small style="opacity:0.8;">Host: ' . htmlspecialchars(DB_HOST) . ' | DB: ' . htmlspecialchars(DB_NAME) . ' | User: ' . htmlspecialchars(DB_USER) . '</small></div>');
    }
}

// is this a fresh install? (no users table, or zero users)
function isFreshInstall()
{
    if (!class_exists('mysqli'))
        return true;
    try {
        $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error)
            return true;
        $t = $conn->query("SHOW TABLES LIKE 'users'");
        if (!$t || $t->num_rows == 0) {
            $conn->close();
            return true;
        }
        $c = $conn->query("SELECT COUNT(*) AS n FROM users");
        $n = ($c && ($row = $c->fetch_assoc())) ? (int) $row['n'] : 0;
        $conn->close();
        return $n === 0;
    } catch (Throwable $e) {
        return true;
    }
}

$fresh = isFreshInstall();
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$force = isset($_GET['force']) && $_GET['force'] === '1';

// auth gate — admin only, except on fresh install or ?force=1
if (!$fresh && !$isAdmin && !$force) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>System Already Configured</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">';
    echo '<link rel="stylesheet" href="styles.css?v=5.0"></head><body>';
    echo '<div class="setup-wrapper"><div class="setup-container" style="max-width:540px;margin:60px auto;padding:30px;background:#fff;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.1);">';
    echo '<h2 style="margin-top:0;"><i class="fas fa-check-circle" style="color:#28a745;"></i> System Already Installed</h2><hr style="margin:16px 0;border:0;border-top:1px solid #eee;">';
    echo '<p style="color:#555;font-size:15px;line-height:1.6;">Database tables already exist in <strong>' . htmlspecialchars(DB_NAME) . '</strong>. Setup has already been completed.</p>';
    echo '<div style="margin:25px 0 10px;display:flex;gap:12px;flex-wrap:wrap;">';
    echo '<a href="login.php" class="btn btn-primary" style="padding:10px 20px;text-decoration:none;border-radius:6px;display:inline-flex;align-items:center;gap:8px;"><i class="fas fa-sign-in-alt"></i> Go to Login Page</a>';
    echo '<a href="setup.php?force=1" class="btn btn-secondary" style="padding:10px 20px;text-decoration:none;border-radius:6px;display:inline-flex;align-items:center;gap:8px;"><i class="fas fa-wrench"></i> Run Setup / Reset</a>';
    echo '</div>';
    echo '</div></div></body></html>';
    exit;
}

$action = $_GET['action'] ?? '';

// helper: add column if not exists
function addColumnIfNotExists($conn, $table, $column, $definition)
{
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($check && $check->num_rows == 0) {
        if ($conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Added column "' . $column . '" to "' . $table . '"</div>';
        } else {
            echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Failed to add "' . $column . '" to "' . $table . '": ' . $conn->error . '</div>';
        }
    } else {
        echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Column "' . $column . '" already exists in "' . $table . '"</div>';
    }
}

// helper: add index if not exists
function addIndexIfNotExists($conn, $table, $indexName, $columns)
{
    $check = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
    if ($check && $check->num_rows == 0) {
        if ($conn->query("ALTER TABLE `$table` ADD INDEX `$indexName` ($columns)")) {
            echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Added index "' . $indexName . '" on "' . $table . '"</div>';
        } else {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Index "' . $indexName . '" skipped: ' . $conn->error . '</div>';
        }
    } else {
        echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Index "' . $indexName . '" already exists on "' . $table . '"</div>';
    }
}

// helper: run a CREATE TABLE IF NOT EXISTS, log result
function createTable($conn, $name, $sql)
{
    if ($conn->query($sql)) {
        echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Table "' . $name . '" ready</div>';
    } else {
        echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Error on "' . $name . '": ' . $conn->error . '</div>';
    }
}

// schema definitions — all CREATE TABLE IF NOT EXISTS (idempotent)
function setupSchema($conn)
{
    echo '<br><div class="log-item log-info"><i class="fas fa-cogs"></i> <strong>Creating / verifying tables...</strong></div>';

    createTable($conn, 'branches', "CREATE TABLE IF NOT EXISTS branches (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        mobile VARCHAR(15) NOT NULL,
        email VARCHAR(200) NULL,
        address TEXT NOT NULL,
        state VARCHAR(100) NOT NULL,
        wallet_balance DECIMAL(14,2) DEFAULT 0.00,
        is_active BOOLEAN DEFAULT TRUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'users', "CREATE TABLE IF NOT EXISTS users (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(200) NOT NULL UNIQUE,
        phone VARCHAR(15) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin','manager','agent','customer') NOT NULL DEFAULT 'agent',
        branch_id BIGINT NULL,
        customer_id BIGINT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        profile_image VARCHAR(255) NULL,
        theme_primary VARCHAR(20) DEFAULT '#001f3f',
        theme_secondary VARCHAR(20) DEFAULT '#003366',
        theme_accent VARCHAR(20) DEFAULT '#0074D9',
        theme_mode VARCHAR(10) DEFAULT 'light',
        last_login_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_role (role),
        INDEX idx_branch_id (branch_id),
        INDEX idx_customer_id (customer_id),
        INDEX idx_is_active (is_active),
        FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'agents', "CREATE TABLE IF NOT EXISTS agents (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL UNIQUE,
        branch_id BIGINT NULL,
        agent_code VARCHAR(30) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        mobile VARCHAR(15) NOT NULL,
        email VARCHAR(200) NULL,
        address TEXT NULL,
        state VARCHAR(100) NULL,
        pan_number VARCHAR(10) NULL,
        aadhar_number VARCHAR(12) NULL,
        bank_name VARCHAR(100) NULL,
        bank_account_no VARCHAR(20) NULL,
        ifsc_code VARCHAR(11) NULL,
        wallet_balance DECIMAL(14,2) DEFAULT 0.00,
        outstanding_balance DECIMAL(14,2) DEFAULT 0.00,
        is_active BOOLEAN DEFAULT TRUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
        INDEX idx_user_id (user_id),
        INDEX idx_branch_id (branch_id),
        INDEX idx_agent_code (agent_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'customers', "CREATE TABLE IF NOT EXISTS customers (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        mobile VARCHAR(15) NOT NULL,
        email VARCHAR(200) NULL,
        address TEXT NULL,
        state VARCHAR(100) NULL,
        referral_code VARCHAR(20) NULL UNIQUE,
        referred_by BIGINT NULL,
        agent_id BIGINT NULL,
        family_head_id BIGINT NULL,
        family_relation VARCHAR(50) NULL DEFAULT 'Self',
        portal_user_id BIGINT NULL,
        points_balance INT DEFAULT 0,
        is_active BOOLEAN DEFAULT TRUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (referred_by) REFERENCES customers(id) ON DELETE SET NULL,
        INDEX idx_referral_code (referral_code),
        INDEX idx_referred_by (referred_by),
        INDEX idx_family_head_id (family_head_id),
        INDEX idx_portal_user_id (portal_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'bank_accounts', "CREATE TABLE IF NOT EXISTS bank_accounts (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        bank_name VARCHAR(100) NOT NULL,
        account_number VARCHAR(20) NOT NULL UNIQUE,
        ifsc_code VARCHAR(11) NOT NULL,
        branch_name VARCHAR(150) NULL,
        account_type VARCHAR(30) NOT NULL DEFAULT 'Current',
        opening_balance DECIMAL(14,2) DEFAULT 0.00,
        current_balance DECIMAL(14,2) DEFAULT 0.00,
        branch_id BIGINT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
        INDEX idx_account_number (account_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'vehicles', "CREATE TABLE IF NOT EXISTS vehicles (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        vehicle_number VARCHAR(20) NOT NULL UNIQUE,
        vehicle_type VARCHAR(50) NOT NULL,
        make VARCHAR(100) NULL,
        model VARCHAR(100) NULL,
        year_of_manufacture YEAR NULL,
        fuel_type VARCHAR(20) NULL,
        customer_id BIGINT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        INDEX idx_vehicle_number (vehicle_number),
        INDEX idx_customer_id (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'insurance_categories', "CREATE TABLE IF NOT EXISTS insurance_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(30) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        icon VARCHAR(50) NOT NULL DEFAULT 'fa-shield',
        display_order INT NOT NULL DEFAULT 0,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'policy_entries', "CREATE TABLE IF NOT EXISTS policy_entries (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        insurance_category VARCHAR(30) NOT NULL DEFAULT 'motor',
        entry_date DATE NOT NULL,
        branch_id BIGINT NOT NULL,
        vehicle_id BIGINT NULL,
        vehicle_number VARCHAR(20) NULL,
        vehicle_type VARCHAR(50) NULL,
        customer_id BIGINT NOT NULL,
        customer_name VARCHAR(150) NOT NULL,
        agent_id BIGINT NULL,
        agent_name VARCHAR(150) NULL,
        company_name VARCHAR(200) NOT NULL,
        company_code VARCHAR(30) NOT NULL,
        policy_number VARCHAR(50) NOT NULL UNIQUE,
        policy_type ENUM('comprehensive','third_party','own_damage') NULL,
        business_type ENUM('new','renewal','rollover') NULL,
        previous_policy_id BIGINT NULL,
        proposal_number VARCHAR(50) NULL,
        total_premium_amount DECIMAL(12,2) NOT NULL,
        idv DECIMAL(12,2) NULL,
        tp_amount DECIMAL(12,2) DEFAULT 0.00,
        od_amount DECIMAL(12,2) DEFAULT 0.00,
        net_premium_amount DECIMAL(12,2) NOT NULL,
        gst_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        ncb_percent DECIMAL(5,2) NULL,
        -- Health insurance fields
        sum_insured DECIMAL(14,2) NULL,
        plan_name VARCHAR(150) NULL,
        tpa_name VARCHAR(150) NULL,
        health_policy_type ENUM('individual','family_floater','senior_citizen','critical_illness','top_up') NULL,
        members_count TINYINT UNSIGNED NULL DEFAULT 1,
        members_details TEXT NULL,
        ped_details TEXT NULL,
        -- Life insurance fields
        sum_assured DECIMAL(14,2) NULL,
        life_plan_type ENUM('term','endowment','money_back','ulip','whole_life','child_plan','pension') NULL,
        policy_term SMALLINT NULL,
        premium_paying_term SMALLINT NULL,
        payment_frequency ENUM('yearly','half_yearly','quarterly','monthly','single') NULL,
        nominee_name VARCHAR(150) NULL,
        nominee_relation VARCHAR(50) NULL,
        nominee_dob DATE NULL,
        bank_account_id BIGINT NULL,
        bank_name VARCHAR(100) NULL,
        payment_in DECIMAL(12,2) DEFAULT 0.00,
        payment_out DECIMAL(12,2) DEFAULT 0.00,
        payment_in_bank_account_id BIGINT NULL,
        payment_out_bank_account_id BIGINT NULL,
        payment_mode VARCHAR(30) NULL,
        policy_start_date DATE NOT NULL,
        policy_end_date DATE NOT NULL,
        status ENUM('active','expired','cancelled') DEFAULT 'active',
        remarks TEXT NULL,
        created_by BIGINT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE SET NULL,
        FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
        INDEX idx_insurance_category (insurance_category),
        INDEX idx_policy_number (policy_number),
        INDEX idx_entry_date (entry_date),
        INDEX idx_branch_id (branch_id),
        INDEX idx_customer_id (customer_id),
        INDEX idx_agent_id (agent_id),
        INDEX idx_buyer_id (buyer_id),
        INDEX idx_status (status),
        INDEX idx_policy_dates (policy_start_date, policy_end_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'policy_documents', "CREATE TABLE IF NOT EXISTS policy_documents (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        policy_entry_id BIGINT NOT NULL,
        document_type ENUM('policy_pdf','vehicle_image','other') NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_size_kb INT NULL,
        mime_type VARCHAR(50) NULL,
        uploaded_by BIGINT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (policy_entry_id) REFERENCES policy_entries(id) ON DELETE CASCADE,
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
        INDEX idx_policy_entry_id (policy_entry_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'expense_categories', "CREATE TABLE IF NOT EXISTS expense_categories (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(255) NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'expenses', "CREATE TABLE IF NOT EXISTS expenses (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        expense_category_id BIGINT NOT NULL,
        branch_id BIGINT NOT NULL,
        expense_date DATE NOT NULL,
        description VARCHAR(500) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        payment_mode VARCHAR(30) NULL,
        bank_account_id BIGINT NULL,
        reference_number VARCHAR(50) NULL,
        receipt_file_path VARCHAR(500) NULL,
        created_by BIGINT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (expense_category_id) REFERENCES expense_categories(id) ON DELETE RESTRICT,
        FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
        FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
        INDEX idx_expense_date (expense_date),
        INDEX idx_branch_id (branch_id),
        INDEX idx_category_id (expense_category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'bank_transactions', "CREATE TABLE IF NOT EXISTS bank_transactions (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        bank_account_id BIGINT NOT NULL,
        transaction_date DATE NOT NULL,
        description VARCHAR(500) NOT NULL,
        reference_number VARCHAR(50) NULL,
        transaction_type ENUM('credit','debit') NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        running_balance DECIMAL(14,2) NOT NULL,
        policy_entry_id BIGINT NULL,
        expense_id BIGINT NULL,
        category VARCHAR(50) NULL,
        agent_id BIGINT NULL,
        buyer_id BIGINT NULL,
        link_txn_id BIGINT NULL,
        is_linked_mirror TINYINT(1) NOT NULL DEFAULT 0,
        created_by BIGINT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE RESTRICT,
        FOREIGN KEY (policy_entry_id) REFERENCES policy_entries(id) ON DELETE SET NULL,
        FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
        INDEX idx_bank_account_id (bank_account_id),
        INDEX idx_transaction_date (transaction_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'other_income', "CREATE TABLE IF NOT EXISTS other_income (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        branch_id BIGINT NOT NULL,
        income_date DATE NOT NULL,
        source VARCHAR(200) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        payment_mode VARCHAR(30) NULL,
        bank_account_id BIGINT NULL,
        reference_number VARCHAR(50) NULL,
        created_by BIGINT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
        FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
        INDEX idx_income_date (income_date),
        INDEX idx_branch_id (branch_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'notifications', "CREATE TABLE IF NOT EXISTS notifications (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        type ENUM('policy_expiry','payment_pending','commission_update','general') NOT NULL,
        title VARCHAR(200) NOT NULL,
        message TEXT NOT NULL,
        policy_entry_id BIGINT NULL,
        agent_id BIGINT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        is_email_sent BOOLEAN DEFAULT FALSE,
        scheduled_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (policy_entry_id) REFERENCES policy_entries(id) ON DELETE SET NULL,
        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE SET NULL,
        INDEX idx_user_id (user_id),
        INDEX idx_is_read (is_read),
        INDEX idx_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'referrals', "CREATE TABLE IF NOT EXISTS referrals (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        referrer_id BIGINT NOT NULL,
        referred_id BIGINT NOT NULL,
        policy_entry_id BIGINT NULL,
        points_earned INT DEFAULT 0,
        status ENUM('pending','completed','expired') DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        FOREIGN KEY (referrer_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (referred_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (policy_entry_id) REFERENCES policy_entries(id) ON DELETE SET NULL,
        INDEX idx_referrer_id (referrer_id),
        INDEX idx_referred_id (referred_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'point_transactions', "CREATE TABLE IF NOT EXISTS point_transactions (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        customer_id BIGINT NOT NULL,
        transaction_type ENUM('earned','redeemed') NOT NULL,
        points INT NOT NULL,
        rupee_value DECIMAL(10,2) DEFAULT 0.00,
        description VARCHAR(255) NOT NULL,
        referral_id BIGINT NULL,
        policy_entry_id BIGINT NULL,
        balance_after INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE SET NULL,
        FOREIGN KEY (policy_entry_id) REFERENCES policy_entries(id) ON DELETE SET NULL,
        INDEX idx_customer_id (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'payout_chart_uploads', "CREATE TABLE IF NOT EXISTS payout_chart_uploads (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        chart_type ENUM('buyer_payout','agent_payout') NOT NULL,
        month TINYINT NOT NULL,
        year SMALLINT NOT NULL,
        records_processed INT DEFAULT 0,
        records_failed INT DEFAULT 0,
        status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
        error_log TEXT NULL,
        uploaded_by BIGINT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'activity_logs', "CREATE TABLE IF NOT EXISTS activity_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        username VARCHAR(150) NOT NULL,
        action VARCHAR(100) NOT NULL,
        details TEXT NULL,
        table_name VARCHAR(100) NULL,
        record_id BIGINT NULL,
        old_values JSON NULL,
        new_values JSON NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(500) NULL,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_action (action),
        INDEX idx_timestamp (timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'system_settings', "CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_setting_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'login_attempts', "CREATE TABLE IF NOT EXISTS login_attempts (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(200) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        attempt_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_username (username),
        INDEX idx_attempt_time (attempt_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'todos', "CREATE TABLE IF NOT EXISTS todos (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
        status ENUM('pending','in_progress','completed') DEFAULT 'pending',
        due_date DATE NULL,
        assigned_to BIGINT NULL,
        created_by BIGINT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_priority (priority),
        INDEX idx_due_date (due_date),
        INDEX idx_assigned (assigned_to, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // deleted policies — full snapshot (policy + ledger lines + documents + links) until restored or purged
    createTable($conn, 'policy_trash', "CREATE TABLE IF NOT EXISTS policy_trash (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        policy_entry_id BIGINT NOT NULL,
        policy_number VARCHAR(50) NOT NULL,
        customer_name VARCHAR(150) NULL,
        vehicle_number VARCHAR(20) NULL,
        company_name VARCHAR(200) NULL,
        total_premium_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        snapshot LONGTEXT NOT NULL,
        deleted_by BIGINT NULL,
        deleted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pt_deleted (deleted_at),
        INDEX idx_pt_number (policy_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // mid-term changes on a policy; a fee can post to other income
    createTable($conn, 'policy_endorsements', "CREATE TABLE IF NOT EXISTS policy_endorsements (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        policy_entry_id BIGINT NOT NULL,
        endorsement_number VARCHAR(50) NULL,
        endorsement_type ENUM('owner_transfer','vehicle_details','addon_cover','hypothecation','correction','other') NOT NULL DEFAULT 'other',
        endorsement_date DATE NOT NULL,
        effective_date DATE NULL,
        description VARCHAR(500) NOT NULL,
        premium_change DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        other_income_id BIGINT NULL,
        created_by BIGINT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (policy_entry_id) REFERENCES policy_entries(id) ON DELETE CASCADE,
        INDEX idx_end_policy (policy_entry_id, endorsement_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // claims — one policy, many claims (supports motor, health and life claims)
    createTable($conn, 'claims', "CREATE TABLE IF NOT EXISTS claims (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        claim_number VARCHAR(30) NOT NULL UNIQUE,
        policy_entry_id BIGINT NOT NULL,
        claim_category ENUM('motor','health','life') NOT NULL DEFAULT 'motor',
        insurer_claim_number VARCHAR(50) NULL,
        claim_type VARCHAR(50) NOT NULL DEFAULT 'own_damage',
        incident_date DATE NOT NULL,
        intimation_date DATE NOT NULL,
        incident_place VARCHAR(150) NULL,
        description TEXT NULL,
        -- Motor Claim
        fir_number VARCHAR(50) NULL,
        surveyor_name VARCHAR(150) NULL,
        surveyor_mobile VARCHAR(15) NULL,
        survey_date DATE NULL,
        garage_name VARCHAR(150) NULL,
        -- Health Claim
        hospital_name VARCHAR(150) NULL,
        admission_date DATE NULL,
        discharge_date DATE NULL,
        doctor_name VARCHAR(150) NULL,
        diagnosis TEXT NULL,
        -- Life Claim
        life_claim_type ENUM('death','maturity','surrender','critical_illness','disability') NULL,
        date_of_event DATE NULL,
        -- Settlement
        settlement_mode ENUM('cashless','reimbursement') NULL,
        amount_claimed DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        amount_approved DECIMAL(12,2) NULL,
        amount_settled DECIMAL(12,2) NULL,
        settlement_date DATE NULL,
        status ENUM('intimated','survey','approved','settled','rejected','withdrawn') NOT NULL DEFAULT 'intimated',
        rejection_reason VARCHAR(255) NULL,
        remarks VARCHAR(500) NULL,
        created_by BIGINT NOT NULL,
        updated_by BIGINT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (policy_entry_id) REFERENCES policy_entries(id) ON DELETE RESTRICT,
        INDEX idx_claim_category (claim_category),
        INDEX idx_claim_status (status),
        INDEX idx_claim_policy (policy_entry_id),
        INDEX idx_claim_intimation (intimation_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    createTable($conn, 'claim_documents', "CREATE TABLE IF NOT EXISTS claim_documents (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        claim_id BIGINT NOT NULL,
        document_type ENUM('claim_form','fir','estimate','survey_report','repair_invoice','photo','settlement_letter','other') NOT NULL DEFAULT 'other',
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_size_kb INT NULL,
        mime_type VARCHAR(50) NULL,
        uploaded_by BIGINT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (claim_id) REFERENCES claims(id) ON DELETE CASCADE,
        INDEX idx_cd_claim (claim_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // forgot password — sha256 of the emailed token, 1 hour, single use
    createTable($conn, 'password_resets', "CREATE TABLE IF NOT EXISTS password_resets (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        ip_address VARCHAR(45) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_pr_user (user_id, created_at),
        INDEX idx_pr_ip (ip_address, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Agent Subscription Plans
    createTable($conn, 'subscription_plans', "CREATE TABLE IF NOT EXISTS subscription_plans (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(100) NOT NULL UNIQUE,
        description TEXT NULL,
        monthly_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        quarterly_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        yearly_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        validity_days INT NOT NULL DEFAULT 30,
        max_customers INT NOT NULL DEFAULT 100,
        max_policies INT NOT NULL DEFAULT 200,
        monthly_ai_scans INT NOT NULL DEFAULT 50,
        team_members_limit INT NOT NULL DEFAULT 1,
        whatsapp_limit INT NOT NULL DEFAULT 500,
        reports_access BOOLEAN NOT NULL DEFAULT TRUE,
        advanced_features BOOLEAN NOT NULL DEFAULT FALSE,
        is_trial BOOLEAN NOT NULL DEFAULT FALSE,
        trial_days INT NOT NULL DEFAULT 0,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        display_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_plan_slug (slug),
        INDEX idx_plan_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Agent Subscriptions
    createTable($conn, 'subscriptions', "CREATE TABLE IF NOT EXISTS subscriptions (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        agent_id BIGINT NOT NULL,
        plan_id BIGINT NOT NULL,
        billing_cycle ENUM('monthly','quarterly','yearly','trial') NOT NULL DEFAULT 'monthly',
        start_date DATE NOT NULL,
        expiry_date DATE NOT NULL,
        status ENUM('pending','active','expired','cancelled','suspended','payment_failed') NOT NULL DEFAULT 'pending',
        customer_count INT NOT NULL DEFAULT 0,
        policy_count INT NOT NULL DEFAULT 0,
        ai_scan_count INT NOT NULL DEFAULT 0,
        whatsapp_count INT NOT NULL DEFAULT 0,
        auto_renew BOOLEAN NOT NULL DEFAULT TRUE,
        cancelled_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
        FOREIGN KEY (plan_id) REFERENCES subscription_plans(id),
        INDEX idx_sub_agent (agent_id),
        INDEX idx_sub_status (status),
        INDEX idx_sub_expiry (expiry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Subscription Payment Records
    createTable($conn, 'subscription_payments', "CREATE TABLE IF NOT EXISTS subscription_payments (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        subscription_id BIGINT NULL,
        agent_id BIGINT NOT NULL,
        plan_id BIGINT NOT NULL,
        order_id VARCHAR(100) NULL,
        payment_id VARCHAR(100) NULL,
        payment_signature VARCHAR(255) NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        currency VARCHAR(10) NOT NULL DEFAULT 'INR',
        billing_cycle ENUM('monthly','quarterly','yearly','trial') NOT NULL DEFAULT 'monthly',
        payment_method VARCHAR(50) NOT NULL DEFAULT 'razorpay',
        status ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
        invoice_number VARCHAR(50) NULL UNIQUE,
        receipt_url VARCHAR(255) NULL,
        webhook_payload LONGTEXT NULL,
        paid_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
        FOREIGN KEY (plan_id) REFERENCES subscription_plans(id),
        INDEX idx_pay_order (order_id),
        INDEX idx_pay_payment (payment_id),
        INDEX idx_pay_agent (agent_id),
        INDEX idx_pay_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Policy Extractions (AI Policy Scanner Records)
    createTable($conn, 'policy_extractions', "CREATE TABLE IF NOT EXISTS policy_extractions (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        document_id BIGINT NULL,
        policy_entry_id BIGINT NULL,
        agent_id BIGINT NULL,
        customer_id BIGINT NULL,
        file_path VARCHAR(255) NOT NULL,
        raw_text LONGTEXT NULL,
        extracted_data LONGTEXT NULL,
        confidence_scores TEXT NULL,
        ai_provider VARCHAR(50) NOT NULL DEFAULT 'gemini',
        scanning_status ENUM('uploaded','processing','completed','failed','manual_review') NOT NULL DEFAULT 'uploaded',
        error_message TEXT NULL,
        processing_time_ms INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE SET NULL,
        INDEX idx_pe_agent (agent_id),
        INDEX idx_pe_status (scanning_status),
        INDEX idx_pe_policy (policy_entry_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 5-Stage Policy Expiry Reminder Schedules
    createTable($conn, 'reminder_schedules', "CREATE TABLE IF NOT EXISTS reminder_schedules (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        policy_entry_id BIGINT NOT NULL,
        agent_id BIGINT NULL,
        customer_id BIGINT NOT NULL,
        reminder_stage ENUM('30_days','14_days','7_days','2_days','expiry_day','post_expiry') NOT NULL,
        scheduled_date DATE NOT NULL,
        scheduled_channel ENUM('whatsapp','email','both') NOT NULL DEFAULT 'both',
        status ENUM('pending','sent','failed','cancelled','skipped') NOT NULL DEFAULT 'pending',
        sent_at DATETIME NULL,
        failure_reason TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (policy_entry_id) REFERENCES policy_entries(id) ON DELETE CASCADE,
        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE SET NULL,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        INDEX idx_rs_status_date (status, scheduled_date),
        INDEX idx_rs_policy (policy_entry_id),
        INDEX idx_rs_agent (agent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Notification Logs
    createTable($conn, 'notification_logs', "CREATE TABLE IF NOT EXISTS notification_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        reminder_schedule_id BIGINT NULL,
        policy_entry_id BIGINT NULL,
        agent_id BIGINT NOT NULL,
        customer_id BIGINT NOT NULL,
        channel ENUM('whatsapp','email','sms') NOT NULL DEFAULT 'whatsapp',
        recipient_contact VARCHAR(200) NOT NULL,
        message_content TEXT NOT NULL,
        template_id VARCHAR(100) NULL,
        status ENUM('queued','sent','delivered','read','failed') NOT NULL DEFAULT 'sent',
        provider_message_id VARCHAR(150) NULL,
        provider_response TEXT NULL,
        error_message TEXT NULL,
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE SET NULL,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        INDEX idx_nl_agent (agent_id),
        INDEX idx_nl_status (status),
        INDEX idx_nl_sent (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Customer Followups CRM Pipeline
    createTable($conn, 'customer_followups', "CREATE TABLE IF NOT EXISTS customer_followups (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        policy_entry_id BIGINT NULL,
        customer_id BIGINT NOT NULL,
        agent_id BIGINT NULL,
        followup_stage ENUM('new_renewal','contacted','interested','quote_sent','negotiation','renewed','lost') NOT NULL DEFAULT 'new_renewal',
        call_notes TEXT NULL,
        next_followup_date DATE NULL,
        assigned_agent_id BIGINT NULL,
        is_completed BOOLEAN NOT NULL DEFAULT FALSE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
        INDEX idx_cf_agent (agent_id),
        INDEX idx_cf_stage (followup_stage),
        INDEX idx_cf_next (next_followup_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Support Desk Tickets
    createTable($conn, 'support_tickets', "CREATE TABLE IF NOT EXISTS support_tickets (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        ticket_number VARCHAR(30) NOT NULL UNIQUE,
        agent_id BIGINT NOT NULL,
        subject VARCHAR(200) NOT NULL,
        category ENUM('billing','ai_scanner','whatsapp','policy','general') NOT NULL DEFAULT 'general',
        priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
        status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
        last_reply_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
        INDEX idx_st_agent (agent_id),
        INDEX idx_st_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ticket Conversation Messages
    createTable($conn, 'ticket_messages', "CREATE TABLE IF NOT EXISTS ticket_messages (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        ticket_id BIGINT NOT NULL,
        sender_user_id BIGINT NOT NULL,
        sender_role ENUM('agent','admin','support') NOT NULL DEFAULT 'agent',
        message TEXT NOT NULL,
        attachment_path VARCHAR(255) NULL,
        is_internal BOOLEAN NOT NULL DEFAULT FALSE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
        INDEX idx_tm_ticket (ticket_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Agent Mobile & Email Verification Tokens / OTPs
    createTable($conn, 'agent_verifications', "CREATE TABLE IF NOT EXISTS agent_verifications (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        verification_type ENUM('mobile_otp','email_token') NOT NULL,
        token_or_otp VARCHAR(100) NOT NULL,
        expires_at DATETIME NOT NULL,
        is_verified BOOLEAN NOT NULL DEFAULT FALSE,
        verified_at DATETIME NULL,
        attempts_count INT NOT NULL DEFAULT 0,
        ip_address VARCHAR(45) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_av_user (user_id, verification_type),
        INDEX idx_av_token (token_or_otp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Safe removal of Brokerage, Buyers & Commission schema for pure Policy Management
function cleanupBrokerageAndCommissionSchema($conn)
{
    echo '<br><div class="log-item log-info"><i class="fas fa-trash-alt"></i> <strong>Cleaning up Brokerage, Buyers & Commission schema...</strong></div>';

    // 1. Drop foreign key constraints referencing buyers or on policy_entries.buyer_id
    try {
        $dbRes = $conn->query("SELECT DATABASE() as db");
        $dbName = ($dbRes && ($dbRow = $dbRes->fetch_assoc())) ? $dbRow['db'] : '';
        if ($dbName) {
            $fkQ = "SELECT TABLE_NAME, CONSTRAINT_NAME 
                    FROM information_schema.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = '$dbName' 
                      AND (REFERENCED_TABLE_NAME = 'buyers' OR (TABLE_NAME = 'policy_entries' AND COLUMN_NAME = 'buyer_id'))";
            $fkRes = $conn->query($fkQ);
            if ($fkRes) {
                while ($fk = $fkRes->fetch_assoc()) {
                    $tbl = $fk['TABLE_NAME'];
                    $cName = $fk['CONSTRAINT_NAME'];
                    @$conn->query("ALTER TABLE `$tbl` DROP FOREIGN KEY `$cName`");
                }
            }
        }
    } catch (\Throwable $e) {}

    // 2. Drop foreign keys on tables being removed
    try {
        if (!empty($dbName)) {
            $fkQ2 = "SELECT TABLE_NAME, CONSTRAINT_NAME 
                     FROM information_schema.KEY_COLUMN_USAGE 
                     WHERE TABLE_SCHEMA = '$dbName' 
                       AND TABLE_NAME IN ('buyer_transactions', 'agent_transactions', 'buyer_payout_charts', 'agent_payout_charts') 
                       AND CONSTRAINT_NAME != 'PRIMARY'";
            $fkRes2 = $conn->query($fkQ2);
            if ($fkRes2) {
                while ($fk = $fkRes2->fetch_assoc()) {
                    $tbl = $fk['TABLE_NAME'];
                    $cName = $fk['CONSTRAINT_NAME'];
                    @$conn->query("ALTER TABLE `$tbl` DROP FOREIGN KEY `$cName`");
                }
            }
        }
    } catch (\Throwable $e) {}

    // 3. Drop tables: buyer_transactions, buyer_payout_charts, agent_payout_charts, agent_transactions, buyers
    $dropTables = ['buyer_transactions', 'buyer_payout_charts', 'agent_payout_charts', 'agent_transactions', 'buyers'];
    foreach ($dropTables as $tbl) {
        try {
            $chk = $conn->query("SHOW TABLES LIKE '$tbl'");
            if ($chk && $chk->num_rows > 0) {
                $conn->query("DROP TABLE IF EXISTS `$tbl`");
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Successfully dropped table: <code>' . htmlspecialchars($tbl) . '</code></div>';
            }
        } catch (\Throwable $e) {
            echo '<div class="log-item log-warning"><i class="fas fa-exclamation-triangle"></i> Notice dropping ' . htmlspecialchars($tbl) . ': ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }

    // 4. Drop brokerage and commission columns from policy_entries
    $colsToDrop = [
        'buyer_id',
        'buyer_commission_basis',
        'buyer_commission_rate',
        'buyer_commission_amount',
        'agent_commission_basis',
        'agent_commission_rate',
        'agent_commission_amount',
        'is_commission_manual'
    ];

    $existingCols = [];
    try {
        $cRes = $conn->query("SHOW COLUMNS FROM policy_entries");
        if ($cRes) {
            while ($cRow = $cRes->fetch_assoc()) {
                $existingCols[] = $cRow['Field'];
            }
        }
    } catch (\Throwable $e) {}

    foreach ($colsToDrop as $col) {
        if (in_array($col, $existingCols, true)) {
            try {
                // Drop index if any exists on this column
                $idxRes = $conn->query("SHOW INDEX FROM policy_entries WHERE Column_name = '$col'");
                if ($idxRes) {
                    while ($idxRow = $idxRes->fetch_assoc()) {
                        $keyName = $idxRow['Key_name'];
                        if ($keyName !== 'PRIMARY') {
                            @$conn->query("ALTER TABLE policy_entries DROP INDEX `$keyName`");
                        }
                    }
                }
                $conn->query("ALTER TABLE policy_entries DROP COLUMN `$col`");
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Successfully dropped column: <code>policy_entries.' . htmlspecialchars($col) . '</code></div>';
            } catch (\Throwable $e) {
                echo '<div class="log-item log-warning"><i class="fas fa-exclamation-triangle"></i> Notice dropping column ' . htmlspecialchars($col) . ': ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }
}

// additive column/index/seed-setting fixups for existing installs
function setupColumnFixups($conn)
{
    echo '<br><div class="log-item log-info"><i class="fas fa-wrench"></i> <strong>Verifying columns on existing tables...</strong></div>';

    addColumnIfNotExists($conn, 'agents', 'outstanding_balance', "DECIMAL(14,2) DEFAULT 0.00 AFTER wallet_balance");

    addColumnIfNotExists($conn, 'vehicles', 'make', "VARCHAR(100) NULL AFTER vehicle_type");
    addColumnIfNotExists($conn, 'vehicles', 'model', "VARCHAR(100) NULL AFTER make");
    addColumnIfNotExists($conn, 'vehicles', 'year_of_manufacture', "YEAR NULL AFTER model");
    addColumnIfNotExists($conn, 'vehicles', 'fuel_type', "VARCHAR(20) NULL AFTER year_of_manufacture");

    addColumnIfNotExists($conn, 'policy_entries', 'tp_amount', "DECIMAL(12,2) DEFAULT 0.00 AFTER total_premium_amount");
    addColumnIfNotExists($conn, 'policy_entries', 'od_amount', "DECIMAL(12,2) DEFAULT 0.00 AFTER tp_amount");
    addColumnIfNotExists($conn, 'policy_entries', 'net_premium_amount', "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER od_amount");
    addColumnIfNotExists($conn, 'policy_entries', 'is_commission_manual', "BOOLEAN DEFAULT FALSE AFTER agent_commission_amount");
    addColumnIfNotExists($conn, 'policy_entries', 'bank_account_id', "BIGINT NULL AFTER is_commission_manual");
    addColumnIfNotExists($conn, 'policy_entries', 'bank_name', "VARCHAR(100) NULL AFTER bank_account_id");
    addColumnIfNotExists($conn, 'policy_entries', 'payment_in', "DECIMAL(12,2) DEFAULT 0.00 AFTER bank_name");
    addColumnIfNotExists($conn, 'policy_entries', 'payment_out', "DECIMAL(12,2) DEFAULT 0.00 AFTER payment_in");
    // separate in/out bank accounts (agent may pay to a different account than we pay out from)
    addColumnIfNotExists($conn, 'policy_entries', 'payment_in_bank_account_id', "BIGINT NULL AFTER payment_out");
    addColumnIfNotExists($conn, 'policy_entries', 'payment_out_bank_account_id', "BIGINT NULL AFTER payment_in_bank_account_id");
    addColumnIfNotExists($conn, 'policy_entries', 'payment_mode', "VARCHAR(30) NULL AFTER payment_out_bank_account_id");
    addIndexIfNotExists($conn, 'policy_entries', 'idx_pe_pay_in_bank', 'payment_in_bank_account_id');
    addIndexIfNotExists($conn, 'policy_entries', 'idx_pe_pay_out_bank', 'payment_out_bank_account_id');
    addColumnIfNotExists($conn, 'policy_entries', 'remarks', "TEXT NULL AFTER status");

    addColumnIfNotExists($conn, 'bank_transactions', 'category', "VARCHAR(50) NULL AFTER expense_id");
    addColumnIfNotExists($conn, 'bank_transactions', 'agent_id', "BIGINT NULL AFTER category");
    addIndexIfNotExists($conn, 'bank_transactions', 'idx_bt_agent', 'agent_id');

    addColumnIfNotExists($conn, 'users', 'theme_primary', "VARCHAR(20) DEFAULT '#001f3f' AFTER profile_image");
    addColumnIfNotExists($conn, 'users', 'theme_secondary', "VARCHAR(20) DEFAULT '#003366' AFTER theme_primary");
    addColumnIfNotExists($conn, 'users', 'theme_accent', "VARCHAR(20) DEFAULT '#0074D9' AFTER theme_secondary");
    addColumnIfNotExists($conn, 'users', 'theme_mode', "VARCHAR(10) DEFAULT 'light' AFTER theme_accent");
    addColumnIfNotExists($conn, 'users', 'theme_style', "VARCHAR(10) NULL AFTER theme_mode");     // soft / classic
    addColumnIfNotExists($conn, 'users', 'theme_shape', "VARCHAR(10) NULL AFTER theme_style");    // squared / rounded / glass
    addColumnIfNotExists($conn, 'users', 'theme_sidebar', "VARCHAR(10) NULL AFTER theme_shape");  // navy / theme / light

    // ---- production columns (2026-10-07) ----
    $cols = [
        ['branches', 'branch_code', "VARCHAR(20) NULL AFTER name"],
        ['branches', 'gst_number', "VARCHAR(15) NULL AFTER state"],
        ['branches', 'city', "VARCHAR(100) NULL AFTER address"],
        ['branches', 'pincode', "VARCHAR(10) NULL AFTER city"],
        ['users', 'created_by', "BIGINT NULL"],
        ['users', 'last_login_ip', "VARCHAR(45) NULL AFTER last_login_at"],
        ['users', 'password_changed_at', "DATETIME NULL"],
        ['agents', 'license_number', "VARCHAR(30) NULL AFTER aadhar_number"],
        ['agents', 'license_expiry_date', "DATE NULL AFTER license_number"],
        ['agents', 'tds_rate', "DECIMAL(5,2) NOT NULL DEFAULT 5.00 AFTER license_expiry_date"],
        ['agents', 'joining_date', "DATE NULL AFTER tds_rate"],
        ['agents', 'upi_id', "VARCHAR(60) NULL AFTER ifsc_code"],
        ['customers', 'agent_id', "BIGINT NULL AFTER referred_by"],
        ['customers', 'ckyc_number', "VARCHAR(20) NULL AFTER state"],
        ['customers', 'whatsapp_number', "VARCHAR(15) NULL AFTER mobile"],
        ['customers', 'date_of_birth', "DATE NULL AFTER whatsapp_number"],
        ['customers', 'city', "VARCHAR(100) NULL AFTER address"],
        ['customers', 'pincode', "VARCHAR(10) NULL AFTER city"],
        ['customers', 'created_by', "BIGINT NULL"],
        ['bank_accounts', 'opening_balance_date', "DATE NULL AFTER opening_balance"],
        ['bank_accounts', 'account_holder_name', "VARCHAR(150) NULL AFTER bank_name"],
        ['vehicles', 'chassis_number', "VARCHAR(30) NULL AFTER fuel_type"],
        ['vehicles', 'engine_number', "VARCHAR(30) NULL AFTER chassis_number"],
        ['vehicles', 'cubic_capacity', "SMALLINT NULL AFTER engine_number"],
        ['vehicles', 'seating_capacity', "TINYINT UNSIGNED NULL AFTER cubic_capacity"],
        ['vehicles', 'gvw_kg', "INT NULL AFTER seating_capacity"],
        ['vehicles', 'registration_date', "DATE NULL AFTER gvw_kg"],
        ['vehicles', 'is_active', "BOOLEAN NOT NULL DEFAULT TRUE AFTER customer_id"],
        ['policy_entries', 'policy_type', "ENUM('comprehensive','third_party','own_damage') NULL AFTER policy_number"],
        ['policy_entries', 'business_type', "ENUM('new','renewal','rollover') NULL AFTER policy_type"],
        ['policy_entries', 'previous_policy_id', "BIGINT NULL AFTER business_type"],
        ['policy_entries', 'proposal_number', "VARCHAR(50) NULL AFTER previous_policy_id"],
        ['policy_entries', 'idv', "DECIMAL(12,2) NULL AFTER total_premium_amount"],
        ['policy_entries', 'gst_amount', "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER net_premium_amount"],
        ['policy_entries', 'ncb_percent', "DECIMAL(5,2) NULL AFTER gst_amount"],
        ['policy_entries', 'cancelled_at', "DATETIME NULL AFTER status"],
        ['policy_entries', 'cancellation_reason', "VARCHAR(255) NULL AFTER cancelled_at"],
        ['policy_entries', 'updated_by', "BIGINT NULL AFTER created_by"],
        ['policy_entries', 'deleted_at', "DATETIME NULL"],
        ['policy_entries', 'deleted_by', "BIGINT NULL"],
        ['expenses', 'payee_name', "VARCHAR(150) NULL AFTER description"],
        ['expenses', 'gst_amount', "DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER amount"],
        ['expenses', 'approval_status', "ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER receipt_file_path"],
        ['expenses', 'approved_by', "BIGINT NULL AFTER approval_status"],
        ['expenses', 'updated_by', "BIGINT NULL AFTER created_by"],
        ['bank_transactions', 'other_income_id', "BIGINT NULL AFTER expense_id"],
        ['bank_transactions', 'payment_mode', "VARCHAR(30) NULL AFTER amount"],
        ['bank_transactions', 'is_reconciled', "BOOLEAN NOT NULL DEFAULT FALSE AFTER is_linked_mirror"],
        ['bank_transactions', 'reconciled_at', "DATETIME NULL AFTER is_reconciled"],
        ['bank_transactions', 'updated_at', "DATETIME NULL ON UPDATE CURRENT_TIMESTAMP"],
        ['bank_transactions', 'updated_by', "BIGINT NULL"],
        ['other_income', 'remarks', "VARCHAR(500) NULL AFTER source"],
        ['other_income', 'updated_by', "BIGINT NULL AFTER created_by"],
        ['notifications', 'read_at', "DATETIME NULL AFTER is_read"],
        ['point_transactions', 'created_by', "BIGINT NULL"],
        ['system_settings', 'updated_by', "BIGINT NULL"],
        ['login_attempts', 'is_success', "BOOLEAN NOT NULL DEFAULT FALSE"],
        ['todos', 'completed_at', "DATETIME NULL"],
        ['todos', 'policy_entry_id', "BIGINT NULL"],
        ['policy_entries', 'last_reminder_at', "DATETIME NULL"],
        ['policy_entries', 'reminder_count', "SMALLINT NOT NULL DEFAULT 0"],
        ['expenses', 'approval_note', "VARCHAR(255) NULL AFTER approved_by"],
        // Multi-line insurance verticals (Motor, Health, Life)
        ['policy_entries', 'insurance_category', "VARCHAR(30) NOT NULL DEFAULT 'motor' AFTER branch_id"],
        ['policy_entries', 'sum_insured', "DECIMAL(14,2) NULL AFTER ncb_percent"],
        ['policy_entries', 'plan_name', "VARCHAR(150) NULL AFTER sum_insured"],
        ['policy_entries', 'tpa_name', "VARCHAR(150) NULL AFTER plan_name"],
        ['policy_entries', 'health_policy_type', "ENUM('individual','family_floater','senior_citizen','critical_illness','top_up') NULL AFTER tpa_name"],
        ['policy_entries', 'members_count', "TINYINT UNSIGNED NULL DEFAULT 1 AFTER health_policy_type"],
        ['policy_entries', 'members_details', "TEXT NULL AFTER members_count"],
        ['policy_entries', 'ped_details', "TEXT NULL AFTER members_details"],
        ['policy_entries', 'sum_assured', "DECIMAL(14,2) NULL AFTER ped_details"],
        ['policy_entries', 'life_plan_type', "ENUM('term','endowment','money_back','ulip','whole_life','child_plan','pension') NULL AFTER sum_assured"],
        ['policy_entries', 'policy_term', "SMALLINT NULL AFTER life_plan_type"],
        ['policy_entries', 'premium_paying_term', "SMALLINT NULL AFTER policy_term"],
        ['policy_entries', 'payment_frequency', "ENUM('yearly','half_yearly','quarterly','monthly','single') NULL AFTER premium_paying_term"],
        ['policy_entries', 'nominee_name', "VARCHAR(150) NULL AFTER payment_frequency"],
        ['policy_entries', 'nominee_relation', "VARCHAR(50) NULL AFTER nominee_name"],
        ['policy_entries', 'nominee_dob', "DATE NULL AFTER nominee_relation"],
        ['claims', 'claim_category', "ENUM('motor','health','life') NOT NULL DEFAULT 'motor' AFTER policy_entry_id"],
        ['claims', 'hospital_name', "VARCHAR(150) NULL AFTER garage_name"],
        ['claims', 'admission_date', "DATE NULL AFTER hospital_name"],
        ['claims', 'discharge_date', "DATE NULL AFTER admission_date"],
        ['claims', 'doctor_name', "VARCHAR(150) NULL AFTER discharge_date"],
        ['claims', 'diagnosis', "TEXT NULL AFTER doctor_name"],
        ['claims', 'life_claim_type', "ENUM('death','maturity','surrender','critical_illness','disability') NULL AFTER diagnosis"],
        ['claims', 'date_of_event', "DATE NULL AFTER life_claim_type"],
        // Customer & Family Portal enhancements
        ['users', 'customer_id', "BIGINT NULL AFTER branch_id"],
        ['users', 'status', "ENUM('pending_verification','verification_completed','pending_subscription','active','suspended','blocked') NOT NULL DEFAULT 'active' AFTER is_active"],
        ['users', 'profile_setup_completed', "TINYINT(1) NOT NULL DEFAULT 0 AFTER status"],
        ['users', 'mobile_verified_at', "DATETIME NULL AFTER profile_setup_completed"],
        ['users', 'email_verified_at', "DATETIME NULL AFTER mobile_verified_at"],

        ['agents', 'agency_name', "VARCHAR(200) NULL AFTER name"],
        ['agents', 'referral_code', "VARCHAR(50) NULL AFTER ifsc_code"],
        ['agents', 'referred_by_agent_id', "BIGINT NULL AFTER referral_code"],
        ['agents', 'logo_path', "VARCHAR(255) NULL AFTER referred_by_agent_id"],
        ['agents', 'city', "VARCHAR(100) NULL AFTER state"],
        ['agents', 'pincode', "VARCHAR(10) NULL AFTER city"],
        ['agents', 'whatsapp_consent', "TINYINT(1) NOT NULL DEFAULT 1 AFTER pincode"],
        ['agents', 'email_consent', "TINYINT(1) NOT NULL DEFAULT 1 AFTER whatsapp_consent"],
        ['agents', 'terms_accepted_at', "DATETIME NULL AFTER email_consent"],
        ['agents', 'active_subscription_id', "BIGINT NULL AFTER terms_accepted_at"],

        ['customers', 'agent_id', "BIGINT NULL AFTER referred_by"],
        ['customers', 'family_head_id', "BIGINT NULL AFTER agent_id"],
        ['customers', 'family_relation', "VARCHAR(50) NULL DEFAULT 'Self' AFTER family_head_id"],
        ['customers', 'portal_user_id', "BIGINT NULL AFTER family_relation"],
        ['customers', 'secondary_mobile', "VARCHAR(15) NULL AFTER mobile"],
        ['customers', 'dob', "DATE NULL AFTER email"],
        ['customers', 'gender', "ENUM('male','female','other') NULL AFTER dob"],
        ['customers', 'city', "VARCHAR(100) NULL AFTER state"],
        ['customers', 'pincode', "VARCHAR(10) NULL AFTER city"],
        ['customers', 'primary_wa_consent', "TINYINT(1) NOT NULL DEFAULT 1 AFTER pincode"],
        ['customers', 'secondary_wa_consent', "TINYINT(1) NOT NULL DEFAULT 0 AFTER primary_wa_consent"],
        ['customers', 'email_consent', "TINYINT(1) NOT NULL DEFAULT 1 AFTER secondary_wa_consent"],
        ['customers', 'consent_timestamp', "DATETIME NULL AFTER email_consent"],
        ['customers', 'consent_source', "VARCHAR(50) DEFAULT 'agent_portal' AFTER consent_timestamp"],

        ['policy_entries', 'previous_policy_id', "BIGINT NULL AFTER customer_id"],
        ['policy_entries', 'renewal_status', "ENUM('original','renewed','pending_renewal') NOT NULL DEFAULT 'original' AFTER remarks"],
        ['policy_entries', 'document_reference', "VARCHAR(100) NULL AFTER renewal_status"],
        ['policy_entries', 'verification_status', "ENUM('pending_verification','verified','rejected') NOT NULL DEFAULT 'verified' AFTER document_reference"],
        ['policy_entries', 'verified_by', "BIGINT NULL AFTER verification_status"],
        ['policy_entries', 'verified_at', "DATETIME NULL AFTER verified_by"],
    ];
    foreach ($cols as [$t, $c, $def])
        addColumnIfNotExists($conn, $t, $c, $def);

    // Update users role enum to include customer
    @$conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin','manager','agent','customer') NOT NULL DEFAULT 'agent'");

    // Allow in-house / staff policies without required agent_id
    @$conn->query("ALTER TABLE policy_extractions MODIFY COLUMN agent_id BIGINT NULL");
    @$conn->query("ALTER TABLE reminder_schedules MODIFY COLUMN agent_id BIGINT NULL");
    @$conn->query("ALTER TABLE customer_followups MODIFY COLUMN agent_id BIGINT NULL");

    // Make vehicle fields nullable for health/life policies
    @$conn->query("ALTER TABLE policy_entries MODIFY COLUMN vehicle_id BIGINT NULL");
    @$conn->query("ALTER TABLE policy_entries MODIFY COLUMN vehicle_number VARCHAR(20) NULL");
    @$conn->query("ALTER TABLE policy_entries MODIFY COLUMN vehicle_type VARCHAR(50) NULL");

    // Clean up brokerage, buyers, and commission tables/columns
    cleanupBrokerageAndCommissionSchema($conn);

    addIndexIfNotExists($conn, 'policy_entries', 'idx_insurance_category', 'insurance_category');
    addIndexIfNotExists($conn, 'claims', 'idx_claim_category', 'claim_category');
    addIndexIfNotExists($conn, 'users', 'idx_customer_id', 'customer_id');
    addIndexIfNotExists($conn, 'users', 'idx_user_status', 'status');
    addIndexIfNotExists($conn, 'customers', 'idx_cust_agent', 'agent_id');
    addIndexIfNotExists($conn, 'customers', 'idx_family_head_id', 'family_head_id');
    addIndexIfNotExists($conn, 'customers', 'idx_portal_user_id', 'portal_user_id');
    addIndexIfNotExists($conn, 'branches', 'idx_branch_code', 'branch_code');
    addIndexIfNotExists($conn, 'policy_entries', 'idx_prev_policy', 'previous_policy_id');
    addIndexIfNotExists($conn, 'policy_entries', 'idx_renewal_status', 'renewal_status');
    addIndexIfNotExists($conn, 'policy_entries', 'idx_verif_status', 'verification_status');
    addIndexIfNotExists($conn, 'agents', 'idx_ag_active_sub', 'active_subscription_id');
    addIndexIfNotExists($conn, 'agents', 'idx_ag_ref_code', 'referral_code');
    addIndexIfNotExists($conn, 'policy_entries', 'idx_deleted_at', 'deleted_at');
    addIndexIfNotExists($conn, 'bank_transactions', 'idx_other_income', 'other_income_id');
    addIndexIfNotExists($conn, 'bank_transactions', 'idx_reconciled', 'is_reconciled');
    addIndexIfNotExists($conn, 'agents', 'idx_license_expiry', 'license_expiry_date');
    addIndexIfNotExists($conn, 'todos', 'idx_todo_policy', 'policy_entry_id');
    addIndexIfNotExists($conn, 'vehicles', 'idx_chassis', 'chassis_number');
    // older other-income bank legs only carry the [OI#id] marker — link them by id once (idempotent)
    $conn->query("UPDATE bank_transactions SET other_income_id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(description, '[OI#', -1), ']', 1) AS UNSIGNED)
                  WHERE category = 'other_income' AND other_income_id IS NULL AND description LIKE '%[OI#%]%'");
    addIndexIfNotExists($conn, 'todos', 'idx_assigned', 'assigned_to, status'); // sidebar badge

    // dynamic insurance categories — Motor, Health, Life (extensible)
    createTable($conn, 'insurance_categories', "CREATE TABLE IF NOT EXISTS insurance_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(30) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        icon VARCHAR(50) NOT NULL DEFAULT 'fa-shield',
        display_order INT NOT NULL DEFAULT 0,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("INSERT IGNORE INTO insurance_categories (id, code, name, icon, display_order, is_active) VALUES
        (1, 'motor', 'Motor Insurance', 'fa-car', 1, 1),
        (2, 'health', 'Health Insurance', 'fa-heart-pulse', 2, 1),
        (3, 'life', 'Life Insurance', 'fa-shield-heart', 3, 1)");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Insurance categories verified</div>';

    // dynamic vehicle categories — admin-editable; seed the legacy 4
    createTable($conn, 'vehicle_categories', "CREATE TABLE IF NOT EXISTS vehicle_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(60) NOT NULL UNIQUE,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("INSERT IGNORE INTO vehicle_categories (name) VALUES ('Two Wheeler'),('Car'),('Commercial'),('Other')");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Vehicle categories verified</div>';

    // Seed default subscription plans (Starter, Professional, Enterprise, Free Trial)
    $plans = [
        [
            'name' => 'Starter Agent',
            'slug' => 'starter-agent',
            'description' => 'Ideal for independent agents starting their insurance portfolio.',
            'monthly_price' => 499.00,
            'quarterly_price' => 1299.00,
            'yearly_price' => 4499.00,
            'validity_days' => 30,
            'max_customers' => 100,
            'max_policies' => 200,
            'monthly_ai_scans' => 30,
            'team_members_limit' => 1,
            'whatsapp_limit' => 300,
            'reports_access' => 1,
            'advanced_features' => 0,
            'is_trial' => 0,
            'trial_days' => 0,
            'display_order' => 1
        ],
        [
            'name' => 'Professional Agent',
            'slug' => 'professional-agent',
            'description' => 'Most popular plan for growing insurance agents with automated reminders and AI scanner.',
            'monthly_price' => 999.00,
            'quarterly_price' => 2699.00,
            'yearly_price' => 8999.00,
            'validity_days' => 30,
            'max_customers' => 500,
            'max_policies' => 1000,
            'monthly_ai_scans' => 150,
            'team_members_limit' => 3,
            'whatsapp_limit' => 1500,
            'reports_access' => 1,
            'advanced_features' => 1,
            'is_trial' => 0,
            'trial_days' => 0,
            'display_order' => 2
        ],
        [
            'name' => 'Agency Enterprise',
            'slug' => 'agency-enterprise',
            'description' => 'Unlimited capacity for established agencies and top insurance performers.',
            'monthly_price' => 1999.00,
            'quarterly_price' => 5499.00,
            'yearly_price' => 17999.00,
            'validity_days' => 30,
            'max_customers' => 999999,
            'max_policies' => 999999,
            'monthly_ai_scans' => 500,
            'team_members_limit' => 10,
            'whatsapp_limit' => 5000,
            'reports_access' => 1,
            'advanced_features' => 1,
            'is_trial' => 0,
            'trial_days' => 0,
            'display_order' => 3
        ],
        [
            'name' => '14-Day Free Trial',
            'slug' => 'free-trial',
            'description' => 'Experience the full power of AI policy scanning and WhatsApp automated reminders risk-free.',
            'monthly_price' => 0.00,
            'quarterly_price' => 0.00,
            'yearly_price' => 0.00,
            'validity_days' => 14,
            'max_customers' => 25,
            'max_policies' => 50,
            'monthly_ai_scans' => 15,
            'team_members_limit' => 1,
            'whatsapp_limit' => 100,
            'reports_access' => 1,
            'advanced_features' => 1,
            'is_trial' => 1,
            'trial_days' => 14,
            'display_order' => 0
        ]
    ];
    $pStmt = $conn->prepare("INSERT IGNORE INTO subscription_plans (name, slug, description, monthly_price, quarterly_price, yearly_price, validity_days, max_customers, max_policies, monthly_ai_scans, team_members_limit, whatsapp_limit, reports_access, advanced_features, is_trial, trial_days, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($pStmt) {
        foreach ($plans as $p) {
            $pStmt->bind_param("sssdddiiiiiiiiiii",
                $p['name'], $p['slug'], $p['description'],
                $p['monthly_price'], $p['quarterly_price'], $p['yearly_price'],
                $p['validity_days'], $p['max_customers'], $p['max_policies'],
                $p['monthly_ai_scans'], $p['team_members_limit'], $p['whatsapp_limit'],
                $p['reports_access'], $p['advanced_features'], $p['is_trial'],
                $p['trial_days'], $p['display_order']
            );
            $pStmt->execute();
        }
        $pStmt->close();
    }
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Subscription plans verified</div>';

    // seed settings — INSERT IGNORE
    $settings = [
        'site_name' => 'Insurance Management System',
        'site_logo' => 'uploads/logos/site_logo.png',
        'currency_symbol' => '₹',
        'site_footer' => '© 2025 Insurance Management System',
        'allow_user_profile_uploads' => '1',
        'points_to_rupees_ratio' => '10',
        'auto_backup' => '1',
        'wa_country_code' => '91',
        'smtp_enabled' => '0',
        'smtp_port' => '587',
        'smtp_encryption' => 'tls',
        'razorpay_enabled' => '0',
        'razorpay_key_id' => '',
        'razorpay_key_secret' => '',
        'razorpay_webhook_secret' => '',
        'payment_offline_enabled' => '1',
        'ai_provider' => 'gemini',
        'gemini_api_key' => '',
        'claude_api_key' => '',
        'ai_model' => 'gemini-1.5-flash',
        'wa_cloud_enabled' => '0',
        'wa_cloud_api_token' => '',
        'wa_phone_number_id' => '',
        'wa_business_account_id' => ''
    ];
    $stmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settings as $k => $v) {
        $stmt->bind_param("ss", $k, $v);
        $stmt->execute();
    }
    $stmt->close();
    // ensure legacy external blogger profile logo is replaced with clean local logo & admin avatar
    $conn->query("UPDATE system_settings SET setting_value = 'uploads/logos/site_logo.png' WHERE setting_key = 'site_logo' AND (setting_value LIKE '%channels4_profile%' OR setting_value LIKE '%blogger.googleusercontent.com%' OR setting_value = '')");
    $conn->query("UPDATE users SET profile_image = 'uploads/profiles/profile_admin.png' WHERE id = 1 AND (profile_image IS NULL OR profile_image = '' OR profile_image LIKE '%channels4_profile%')");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> System settings verified</div>';
}

// create upload directories
function setupDirectories()
{
    echo '<br><div class="log-item log-info"><i class="fas fa-folder"></i> <strong>Checking upload directories...</strong></div>';
    $dirs = ['uploads', 'uploads/profiles', 'uploads/policies', 'uploads/payouts', 'uploads/receipts', 'uploads/claims', 'uploads/logos', 'uploads/invoices', 'uploads/tickets'];
    foreach ($dirs as $name) {
        $dir = __DIR__ . '/' . $name;
        if (!file_exists($dir)) {
            if (mkdir($dir, 0755, true)) {
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Directory "' . $name . '/" created</div>';
            } else {
                echo '<div class="log-item log-error"><i class="fas fa-times-circle"></i> Failed to create "' . $name . '/"</div>';
            }
        } else {
            echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Directory "' . $name . '/" exists</div>';
        }
    }
    // no scripts anywhere in uploads; documents / receipts / claims / payouts are private — the app streams them after a login check
    $deny = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
    $ok = file_put_contents(__DIR__ . '/uploads/.htaccess', "# no scripts in uploads\n<FilesMatch \"\\.(php[0-9]?|phtml|phar|pl|py|jsp|asp|html?|shtml|sh|cgi)$\">\n" . $deny . "</FilesMatch>\n") !== false;
    foreach (['policies', 'receipts', 'payouts', 'claims', 'invoices', 'tickets'] as $d) {
        $ok = file_put_contents(__DIR__ . "/uploads/$d/.htaccess", "# private — served only through the app\n" . $deny) !== false && $ok;
    }
    if ($ok) {
        echo '<div class="log-item log-success"><i class="fas fa-shield-alt"></i> Upload folders protected (documents, receipts and claims are login-only)</div>';
    }
}

// drop all tables (reverse-FK order)
function dropAllTables($conn)
{
    $dropTables = ['claim_documents', 'claims', 'policy_endorsements', 'policy_trash', 'password_resets', 'todos', 'payout_chart_uploads', 'point_transactions', 'referrals', 'notifications', 'other_income', 'buyer_transactions', 'agent_transactions', 'agent_payout_charts', 'buyer_payout_charts', 'bank_transactions', 'expenses', 'expense_categories', 'policy_documents', 'policy_entries', 'vehicles', 'bank_accounts', 'customers', 'buyers', 'agents', 'login_attempts', 'activity_logs', 'system_settings', 'users', 'branches'];
    foreach ($dropTables as $t) {
        $conn->query("DROP TABLE IF EXISTS `$t`");
    }
    echo '<div class="log-item log-success"><i class="fas fa-trash"></i> Dropped all existing tables</div>';
}

// full demo seed (used by reset only)
function seedDemoData($conn)
{
    echo '<br><div class="log-item log-info"><i class="fas fa-seedling"></i> <strong>Seeding demo data...</strong></div>';

    $conn->query("INSERT INTO branches (name, mobile, email, address, state) VALUES
        ('Branch 1', '0421000001', 'branch1@demo.com', 'House 1, Street 1, Demo City', 'State A'),
        ('Branch 2', '0421000002', 'branch2@demo.com', 'House 2, Street 2, Demo City', 'State B'),
        ('Branch 3', '0421000003', 'branch3@demo.com', 'House 3, Street 3, Demo City', 'State A'),
        ('Branch 4', '0421000004', 'branch4@demo.com', 'House 4, Street 4, Demo City', 'State C'),
        ('Branch 5', '0421000005', 'branch5@demo.com', 'House 5, Street 5, Demo City', 'State B')");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 5 branches</div>';

    $pwd = password_hash('admin123', PASSWORD_DEFAULT);
    $users = [
        ['Admin User', 'admin@demo.com', '03001000001', 'admin', 1],
        ['Manager 1', 'manager1@demo.com', '03001000002', 'manager', 1],
        ['Manager 2', 'manager2@demo.com', '03001000003', 'manager', 2],
        ['Agent 1', 'agent1@demo.com', '03001000004', 'agent', 1],
        ['Agent 2', 'agent2@demo.com', '03001000005', 'agent', 2],
        ['Agent 3', 'agent3@demo.com', '03001000006', 'agent', 3],
    ];
    $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, role, branch_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
    foreach ($users as $u) {
        $stmt->bind_param("sssssi", $u[0], $u[1], $u[2], $pwd, $u[3], $u[4]);
        $stmt->execute();
    }
    $stmt->close();
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 6 users</div>';

    $conn->query("INSERT INTO agents (user_id, branch_id, agent_code, name, mobile, email, state) VALUES
        (4, 1, 'AGT-0001', 'Agent 1', '03001000004', 'agent1@demo.com', 'State A'),
        (5, 2, 'AGT-0002', 'Agent 2', '03001000005', 'agent2@demo.com', 'State B'),
        (6, 3, 'AGT-0003', 'Agent 3', '03001000006', 'agent3@demo.com', 'State A')");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 3 agents</div>';

    $conn->query("INSERT INTO buyers (company_name, company_code, contact_person, mobile, email) VALUES
        ('Company 1', 'CMP1', 'Contact 1', '0211000001', 'company1@demo.com'),
        ('Company 2', 'CMP2', 'Contact 2', '0211000002', 'company2@demo.com'),
        ('Company 3', 'CMP3', 'Contact 3', '0211000003', 'company3@demo.com'),
        ('Company 4', 'CMP4', 'Contact 4', '0211000004', 'company4@demo.com'),
        ('Company 5', 'CMP5', 'Contact 5', '0211000005', 'company5@demo.com')");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 5 buyers</div>';

    $conn->query("INSERT INTO customers (name, mobile, email, state, referral_code) VALUES
        ('Customer 1', '03002000001', 'customer1@demo.com', 'State A', 'REF-0001'),
        ('Customer 2', '03002000002', 'customer2@demo.com', 'State B', 'REF-0002'),
        ('Customer 3', '03002000003', 'customer3@demo.com', 'State A', 'REF-0003'),
        ('Customer 4', '03002000004', 'customer4@demo.com', 'State C', 'REF-0004'),
        ('Customer 5', '03002000005', 'customer5@demo.com', 'State B', 'REF-0005'),
        ('Customer 6', '03002000006', 'customer6@demo.com', 'State A', 'REF-0006'),
        ('Customer 7', '03002000007', 'customer7@demo.com', 'State C', 'REF-0007'),
        ('Customer 8', '03002000008', 'customer8@demo.com', 'State B', 'REF-0008'),
        ('Customer 9', '03002000009', 'customer9@demo.com', 'State A', 'REF-0009'),
        ('Customer 10', '03002000010', 'customer10@demo.com', 'State C', 'REF-0010'),
        ('Customer 11', '03002000011', 'customer11@demo.com', 'State B', 'REF-0011'),
        ('Customer 12', '03002000012', 'customer12@demo.com', 'State A', 'REF-0012'),
        ('Customer 13', '03002000013', 'customer13@demo.com', 'State C', 'REF-0013'),
        ('Customer 14', '03002000014', 'customer14@demo.com', 'State B', 'REF-0014'),
        ('Customer 15', '03002000015', 'customer15@demo.com', 'State A', 'REF-0015'),
        ('Customer 16', '03002000016', 'customer16@demo.com', 'State C', 'REF-0016'),
        ('Customer 17', '03002000017', 'customer17@demo.com', 'State B', 'REF-0017'),
        ('Customer 18', '03002000018', 'customer18@demo.com', 'State A', 'REF-0018'),
        ('Customer 19', '03002000019', 'customer19@demo.com', 'State C', 'REF-0019'),
        ('Customer 20', '03002000020', 'customer20@demo.com', 'State B', 'REF-0020')");
    $conn->query("UPDATE customers SET referred_by = 1 WHERE id = 5");
    $conn->query("UPDATE customers SET referred_by = 2 WHERE id = 10");
    $conn->query("UPDATE customers SET referred_by = 3 WHERE id = 15");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 20 customers</div>';

    $conn->query("INSERT INTO expense_categories (name, description) VALUES
        ('Salary', 'Employee salaries'),
        ('Rent', 'Office rent'),
        ('Electricity', 'Electricity bills'),
        ('Internet', 'Internet and phone bills'),
        ('Stationery', 'Office supplies'),
        ('Travel', 'Travel and conveyance'),
        ('Maintenance', 'Office maintenance')");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 7 expense categories</div>';

    $conn->query("INSERT INTO bank_accounts (bank_name, account_number, ifsc_code, branch_name, account_type, opening_balance, current_balance, branch_id) VALUES
        ('Bank A', '1000000001', 'BNKA0001', 'Bank Branch 1', 'Current', 100000.00, 100000.00, 1),
        ('Bank B', '1000000002', 'BNKB0001', 'Bank Branch 2', 'Savings', 50000.00, 50000.00, 1),
        ('Bank C', '1000000003', 'BNKC0001', 'Bank Branch 3', 'Current', 75000.00, 75000.00, 2),
        ('Bank D', '1000000004', 'BNKD0001', 'Bank Branch 4', 'Savings', 25000.00, 25000.00, 3)");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 4 bank accounts</div>';

    $conn->query("INSERT INTO vehicles (vehicle_number, vehicle_type, make, model, year_of_manufacture, fuel_type, customer_id) VALUES
        ('VH-0001', 'Car', 'Make A', 'Model 1', 2022, 'Petrol', 1),
        ('VH-0002', 'Two Wheeler', 'Make B', 'Model 2', 2023, 'Petrol', 2),
        ('VH-0003', 'Commercial', 'Make C', 'Model 3', 2021, 'Diesel', 3),
        ('VH-0004', 'Car', 'Make A', 'Model 4', 2024, 'Petrol', 4),
        ('VH-0005', 'Two Wheeler', 'Make D', 'Model 5', 2022, 'Petrol', 5),
        ('VH-0006', 'Commercial', 'Make E', 'Model 6', 2023, 'Diesel', 6),
        ('VH-0007', 'Car', 'Make B', 'Model 7', 2021, 'CNG', 7),
        ('VH-0008', 'Two Wheeler', 'Make A', 'Model 8', 2024, 'Petrol', 8),
        ('VH-0009', 'Commercial', 'Make C', 'Model 9', 2022, 'Diesel', 9),
        ('VH-0010', 'Car', 'Make D', 'Model 10', 2023, 'Petrol', 10),
        ('VH-0011', 'Two Wheeler', 'Make E', 'Model 11', 2021, 'Petrol', 11),
        ('VH-0012', 'Commercial', 'Make A', 'Model 12', 2024, 'Diesel', 12),
        ('VH-0013', 'Car', 'Make B', 'Model 13', 2022, 'CNG', 13),
        ('VH-0014', 'Two Wheeler', 'Make C', 'Model 14', 2023, 'Petrol', 14),
        ('VH-0015', 'Commercial', 'Make D', 'Model 15', 2021, 'Diesel', 15),
        ('VH-0016', 'Car', 'Make E', 'Model 16', 2024, 'Petrol', 16),
        ('VH-0017', 'Two Wheeler', 'Make A', 'Model 17', 2022, 'Petrol', 17),
        ('VH-0018', 'Commercial', 'Make B', 'Model 18', 2023, 'Diesel', 18),
        ('VH-0019', 'Car', 'Make C', 'Model 19', 2021, 'CNG', 19),
        ('VH-0020', 'Two Wheeler', 'Make D', 'Model 20', 2024, 'Petrol', 20)");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 20 vehicles</div>';

    $polCols = "(entry_date, branch_id, vehicle_id, vehicle_number, vehicle_type, customer_id, customer_name, agent_id, agent_name, buyer_id, company_name, company_code, policy_number, total_premium_amount, tp_amount, od_amount, net_premium_amount, buyer_commission_basis, buyer_commission_rate, buyer_commission_amount, agent_commission_basis, agent_commission_rate, agent_commission_amount, policy_start_date, policy_end_date, status, created_by)";
    $conn->query("INSERT INTO policy_entries $polCols VALUES
        (DATE_SUB(CURDATE(), INTERVAL 100 DAY), 1, 1, 'VH-0001', 'Car', 1, 'Customer 1', 1, 'Agent 1', 1, 'Company 1', 'CMP1', 'POL-2025-001', 15000.00, 5000.00, 8000.00, 13000.00, 'premium_pct', 15.0000, 2250.00, 'premium_pct', 10.0000, 1500.00, DATE_SUB(CURDATE(), INTERVAL 100 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 100 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 95 DAY), 2, 2, 'VH-0002', 'Two Wheeler', 2, 'Customer 2', 2, 'Agent 2', 2, 'Company 2', 'CMP2', 'POL-2025-002', 5000.00, 2000.00, 2500.00, 4500.00, 'premium_pct', 12.0000, 600.00, 'premium_pct', 8.0000, 400.00, DATE_SUB(CURDATE(), INTERVAL 95 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 95 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 90 DAY), 3, 3, 'VH-0003', 'Commercial', 3, 'Customer 3', 3, 'Agent 3', 3, 'Company 3', 'CMP3', 'POL-2025-003', 25000.00, 10000.00, 12000.00, 22000.00, 'premium_pct', 18.0000, 4500.00, 'premium_pct', 12.0000, 3000.00, DATE_SUB(CURDATE(), INTERVAL 90 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 90 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 85 DAY), 4, 4, 'VH-0004', 'Car', 4, 'Customer 4', 1, 'Agent 1', 4, 'Company 4', 'CMP4', 'POL-2025-004', 12000.00, 4000.00, 6500.00, 10500.00, 'premium_pct', 14.0000, 1680.00, 'premium_pct', 10.0000, 1200.00, DATE_SUB(CURDATE(), INTERVAL 85 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 85 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 80 DAY), 5, 5, 'VH-0005', 'Two Wheeler', 5, 'Customer 5', 2, 'Agent 2', 5, 'Company 5', 'CMP5', 'POL-2025-005', 8000.00, 3000.00, 4000.00, 7000.00, 'premium_pct', 13.0000, 1040.00, 'premium_pct', 9.0000, 720.00, DATE_SUB(CURDATE(), INTERVAL 80 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 80 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 75 DAY), 1, 6, 'VH-0006', 'Commercial', 6, 'Customer 6', 3, 'Agent 3', 1, 'Company 1', 'CMP1', 'POL-2025-006', 20000.00, 8000.00, 10000.00, 18000.00, 'premium_pct', 16.0000, 3200.00, 'premium_pct', 11.0000, 2200.00, DATE_SUB(CURDATE(), INTERVAL 75 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 75 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 70 DAY), 2, 7, 'VH-0007', 'Car', 7, 'Customer 7', NULL, NULL, 2, 'Company 2', 'CMP2', 'POL-2025-007', 10000.00, 3500.00, 5500.00, 9000.00, 'premium_pct', 15.0000, 1500.00, NULL, NULL, 0.00, DATE_SUB(CURDATE(), INTERVAL 70 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 70 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 65 DAY), 3, 8, 'VH-0008', 'Two Wheeler', 8, 'Customer 8', 1, 'Agent 1', 3, 'Company 3', 'CMP3', 'POL-2025-008', 5000.00, 2000.00, 2500.00, 4500.00, 'premium_pct', 12.0000, 600.00, 'premium_pct', 8.0000, 400.00, DATE_SUB(CURDATE(), INTERVAL 65 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 65 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 60 DAY), 4, 9, 'VH-0009', 'Commercial', 9, 'Customer 9', 2, 'Agent 2', 4, 'Company 4', 'CMP4', 'POL-2025-009', 25000.00, 10000.00, 12000.00, 22000.00, 'premium_pct', 17.0000, 4250.00, 'premium_pct', 12.0000, 3000.00, DATE_SUB(CURDATE(), INTERVAL 60 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 60 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 55 DAY), 5, 10, 'VH-0010', 'Car', 10, 'Customer 10', 3, 'Agent 3', 5, 'Company 5', 'CMP5', 'POL-2025-010', 15000.00, 5000.00, 8000.00, 13000.00, 'premium_pct', 14.0000, 2100.00, 'premium_pct', 10.0000, 1500.00, DATE_SUB(CURDATE(), INTERVAL 55 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 55 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 50 DAY), 1, 11, 'VH-0011', 'Two Wheeler', 11, 'Customer 11', 1, 'Agent 1', 1, 'Company 1', 'CMP1', 'POL-2025-011', 8000.00, 3000.00, 4000.00, 7000.00, 'premium_pct', 13.0000, 1040.00, 'premium_pct', 9.0000, 720.00, DATE_SUB(CURDATE(), INTERVAL 50 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 50 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 45 DAY), 2, 12, 'VH-0012', 'Commercial', 12, 'Customer 12', NULL, NULL, 2, 'Company 2', 'CMP2', 'POL-2025-012', 20000.00, 8000.00, 10000.00, 18000.00, 'premium_pct', 16.0000, 3200.00, NULL, NULL, 0.00, DATE_SUB(CURDATE(), INTERVAL 45 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 45 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 40 DAY), 3, 13, 'VH-0013', 'Car', 13, 'Customer 13', 2, 'Agent 2', 3, 'Company 3', 'CMP3', 'POL-2025-013', 12000.00, 4000.00, 6500.00, 10500.00, 'premium_pct', 15.0000, 1800.00, 'premium_pct', 10.0000, 1200.00, DATE_SUB(CURDATE(), INTERVAL 40 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 40 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 35 DAY), 4, 14, 'VH-0014', 'Two Wheeler', 14, 'Customer 14', 3, 'Agent 3', 4, 'Company 4', 'CMP4', 'POL-2025-014', 5000.00, 2000.00, 2500.00, 4500.00, 'premium_pct', 12.0000, 600.00, 'premium_pct', 8.0000, 400.00, DATE_SUB(CURDATE(), INTERVAL 35 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 35 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 30 DAY), 5, 15, 'VH-0015', 'Commercial', 15, 'Customer 15', 1, 'Agent 1', 5, 'Company 5', 'CMP5', 'POL-2025-015', 25000.00, 10000.00, 12000.00, 22000.00, 'premium_pct', 18.0000, 4500.00, 'premium_pct', 12.0000, 3000.00, DATE_SUB(CURDATE(), INTERVAL 30 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 30 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 25 DAY), 1, 16, 'VH-0016', 'Car', 16, 'Customer 16', 2, 'Agent 2', 1, 'Company 1', 'CMP1', 'POL-2025-016', 10000.00, 3500.00, 5500.00, 9000.00, 'premium_pct', 14.0000, 1400.00, 'premium_pct', 9.0000, 900.00, DATE_SUB(CURDATE(), INTERVAL 25 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 25 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 20 DAY), 2, 17, 'VH-0017', 'Two Wheeler', 17, 'Customer 17', 3, 'Agent 3', 2, 'Company 2', 'CMP2', 'POL-2025-017', 8000.00, 3000.00, 4000.00, 7000.00, 'premium_pct', 13.0000, 1040.00, 'premium_pct', 8.0000, 640.00, DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 20 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 15 DAY), 3, 18, 'VH-0018', 'Commercial', 18, 'Customer 18', NULL, NULL, 3, 'Company 3', 'CMP3', 'POL-2025-018', 20000.00, 8000.00, 10000.00, 18000.00, 'premium_pct', 17.0000, 3400.00, NULL, NULL, 0.00, DATE_SUB(CURDATE(), INTERVAL 15 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 15 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 10 DAY), 4, 19, 'VH-0019', 'Car', 19, 'Customer 19', 1, 'Agent 1', 4, 'Company 4', 'CMP4', 'POL-2025-019', 15000.00, 5000.00, 8000.00, 13000.00, 'premium_pct', 15.0000, 2250.00, 'premium_pct', 10.0000, 1500.00, DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 10 DAY), INTERVAL 1 YEAR), 'active', 1),
        (DATE_SUB(CURDATE(), INTERVAL 5 DAY), 5, 20, 'VH-0020', 'Two Wheeler', 20, 'Customer 20', 2, 'Agent 2', 5, 'Company 5', 'CMP5', 'POL-2025-020', 5000.00, 2000.00, 2500.00, 4500.00, 'premium_pct', 12.0000, 600.00, 'premium_pct', 8.0000, 400.00, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(DATE_SUB(CURDATE(), INTERVAL 5 DAY), INTERVAL 1 YEAR), 'active', 1)");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 20 policy entries</div>';

    $conn->query("INSERT INTO buyer_payout_charts (buyer_id, vehicle_type, month, year, commission_basis, commission_rate, created_by) VALUES
        (1, 'Car', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 15.0000, 1),
        (1, 'Two Wheeler', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 12.0000, 1),
        (1, 'Commercial', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 18.0000, 1),
        (2, 'Car', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 14.0000, 1),
        (2, 'Two Wheeler', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 11.0000, 1),
        (2, 'Commercial', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 17.0000, 1),
        (3, 'Car', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 15.0000, 1),
        (3, 'Two Wheeler', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 12.0000, 1),
        (3, 'Commercial', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 18.0000, 1),
        (4, 'Car', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 13.0000, 1),
        (4, 'Two Wheeler', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 10.0000, 1),
        (4, 'Commercial', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 16.0000, 1),
        (5, 'Car', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 14.0000, 1),
        (5, 'Two Wheeler', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 11.0000, 1),
        (5, 'Commercial', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 17.0000, 1),
        (1, 'Car', MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 'premium_pct', 14.0000, 1),
        (1, 'Two Wheeler', MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 'premium_pct', 11.0000, 1),
        (2, 'Car', MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 'premium_pct', 13.0000, 1),
        (3, 'Commercial', MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 'premium_pct', 17.0000, 1),
        (4, 'Car', MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 'premium_pct', 12.0000, 1)");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 20 buyer payout charts</div>';

    $conn->query("INSERT INTO agent_payout_charts (agent_id, buyer_id, vehicle_type, month, year, commission_basis, commission_rate, is_custom, is_active, uploaded_via, created_by) VALUES
        (NULL, NULL, 'Car', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 10.0000, 0, 1, 'manual', 1),
        (NULL, NULL, 'Two Wheeler', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 8.0000, 0, 1, 'manual', 1),
        (NULL, NULL, 'Commercial', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 12.0000, 0, 1, 'manual', 1),
        (1, NULL, 'Car', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 12.0000, 1, 1, 'manual', 1),
        (1, NULL, 'Two Wheeler', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 10.0000, 1, 1, 'manual', 1),
        (1, NULL, 'Commercial', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 14.0000, 1, 1, 'manual', 1),
        (2, NULL, 'Car', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 11.0000, 1, 1, 'manual', 1),
        (2, NULL, 'Two Wheeler', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 9.0000, 1, 1, 'manual', 1),
        (2, NULL, 'Commercial', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 13.0000, 1, 1, 'manual', 1),
        (3, NULL, 'Car', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 10.0000, 1, 1, 'manual', 1),
        (3, NULL, 'Two Wheeler', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 8.0000, 1, 1, 'manual', 1),
        (3, NULL, 'Commercial', MONTH(CURDATE()), YEAR(CURDATE()), 'premium_pct', 12.0000, 1, 1, 'manual', 1),
        (NULL, NULL, 'Car', MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 'premium_pct', 9.0000, 0, 1, 'manual', 1),
        (NULL, NULL, 'Two Wheeler', MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 'premium_pct', 7.0000, 0, 1, 'manual', 1),
        (NULL, NULL, 'Commercial', MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 'premium_pct', 11.0000, 0, 1, 'manual', 1),
        (1, NULL, 'Car', MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 'premium_pct', 11.0000, 1, 1, 'manual', 1),
        (1, NULL, 'Two Wheeler', MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 'premium_pct', 9.0000, 1, 1, 'manual', 1),
        (2, NULL, 'Car', MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 'premium_pct', 10.0000, 1, 1, 'manual', 1),
        (2, NULL, 'Commercial', MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 'premium_pct', 12.0000, 1, 1, 'manual', 1),
        (3, NULL, 'Commercial', MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 'premium_pct', 11.0000, 1, 1, 'manual', 1)");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 20 agent payout charts</div>';

    $conn->query("INSERT INTO bank_transactions (bank_account_id, transaction_date, description, reference_number, transaction_type, amount, running_balance, category, created_by) VALUES
        (1, DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'Premium received for POL-2025-001', 'TXN-001', 'credit', 15000.00, 115000.00, 'policy_payment', 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 19 DAY), 'Agent payout - Agent 1', 'TXN-002', 'debit', 5000.00, 110000.00, 'agent_payout', 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 18 DAY), 'Premium received for POL-2025-004', 'TXN-003', 'credit', 12000.00, 122000.00, 'policy_payment', 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 17 DAY), 'Salary expense', 'TXN-004', 'debit', 25000.00, 97000.00, 'expense', 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 16 DAY), 'Premium received for POL-2025-006', 'TXN-005', 'credit', 20000.00, 117000.00, 'policy_payment', 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'Premium received for POL-2025-002', 'TXN-006', 'credit', 5000.00, 55000.00, 'policy_payment', 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 14 DAY), 'Agent payout - Agent 2', 'TXN-007', 'debit', 3000.00, 52000.00, 'agent_payout', 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 13 DAY), 'Rent payment', 'TXN-008', 'debit', 15000.00, 37000.00, 'expense', 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'Premium received for POL-2025-003', 'TXN-009', 'credit', 25000.00, 100000.00, 'policy_payment', 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 11 DAY), 'Agent payout - Agent 3', 'TXN-010', 'debit', 3000.00, 97000.00, 'agent_payout', 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'Buyer commission - Company 1', 'TXN-011', 'debit', 2250.00, 114750.00, 'buyer_payout', 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 9 DAY), 'Premium received for POL-2025-011', 'TXN-012', 'credit', 8000.00, 122750.00, 'policy_payment', 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 8 DAY), 'Premium received for POL-2025-005', 'TXN-013', 'credit', 8000.00, 45000.00, 'policy_payment', 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'Electricity payment', 'TXN-014', 'debit', 5000.00, 92000.00, 'expense', 1),
        (4, DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'Premium received for POL-2025-010', 'TXN-015', 'credit', 15000.00, 40000.00, 'policy_payment', 1),
        (4, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'Agent payout - Agent 1', 'TXN-016', 'debit', 1500.00, 38500.00, 'agent_payout', 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'Internet bill', 'TXN-017', 'debit', 3000.00, 119750.00, 'expense', 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Buyer commission - Company 2', 'TXN-018', 'debit', 600.00, 44400.00, 'buyer_payout', 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Premium received for POL-2025-013', 'TXN-019', 'credit', 12000.00, 104000.00, 'policy_payment', 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Stationery purchase', 'TXN-020', 'debit', 2000.00, 117750.00, 'expense', 1)");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 20 bank transactions</div>';

    $conn->query("INSERT INTO agent_transactions (agent_id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, payment_mode, reference_number, created_by) VALUES
        (1, DATE_SUB(CURDATE(), INTERVAL 18 DAY), 'Commission for POL-2025-001', 'commission', 1500.00, 0.00, 1500.00, NULL, NULL, 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 16 DAY), 'Commission for POL-2025-004', 'commission', 1200.00, 0.00, 2700.00, NULL, NULL, 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 14 DAY), 'Payment to Agent 1', 'payment_out', 0.00, 2000.00, 700.00, 'NEFT', 'PAY-001', 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'Commission for POL-2025-008', 'commission', 400.00, 0.00, 1100.00, NULL, NULL, 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'Commission for POL-2025-011', 'commission', 720.00, 0.00, 1820.00, NULL, NULL, 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 8 DAY), 'Commission for POL-2025-015', 'commission', 3000.00, 0.00, 4820.00, NULL, NULL, 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'Payment to Agent 1', 'payment_out', 0.00, 3000.00, 1820.00, 'NEFT', 'PAY-004', 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 17 DAY), 'Commission for POL-2025-002', 'commission', 400.00, 0.00, 400.00, NULL, NULL, 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'Commission for POL-2025-005', 'commission', 720.00, 0.00, 1120.00, NULL, NULL, 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 13 DAY), 'Payment to Agent 2', 'payment_out', 0.00, 500.00, 620.00, 'Cash', 'PAY-002', 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 11 DAY), 'Commission for POL-2025-009', 'commission', 3000.00, 0.00, 3620.00, NULL, NULL, 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 9 DAY), 'Commission for POL-2025-013', 'commission', 1200.00, 0.00, 4820.00, NULL, NULL, 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'Payment to Agent 2', 'payment_out', 0.00, 2000.00, 2820.00, 'NEFT', 'PAY-005', 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'Commission for POL-2025-016', 'commission', 900.00, 0.00, 3720.00, NULL, NULL, 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 16 DAY), 'Commission for POL-2025-003', 'commission', 3000.00, 0.00, 3000.00, NULL, NULL, 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 14 DAY), 'Commission for POL-2025-006', 'commission', 2200.00, 0.00, 5200.00, NULL, NULL, 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'Payment to Agent 3', 'payment_out', 0.00, 3000.00, 2200.00, 'NEFT', 'PAY-003', 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'Commission for POL-2025-010', 'commission', 1500.00, 0.00, 3700.00, NULL, NULL, 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 8 DAY), 'Commission for POL-2025-014', 'commission', 400.00, 0.00, 4100.00, NULL, NULL, 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'Commission for POL-2025-017', 'commission', 640.00, 0.00, 4740.00, NULL, NULL, 1)");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 20 agent transactions</div>';

    $conn->query("INSERT INTO buyer_transactions (buyer_id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, payment_mode, reference_number, created_by) VALUES
        (1, DATE_SUB(CURDATE(), INTERVAL 18 DAY), 'Commission for POL-2025-001', 'commission_receivable', 2250.00, 0.00, 2250.00, NULL, NULL, 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'Payment received from Company 1', 'payment_received', 0.00, 2000.00, 250.00, 'NEFT', 'RCV-001', 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'Commission for POL-2025-011', 'commission_receivable', 1040.00, 0.00, 1290.00, NULL, NULL, 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'Commission for POL-2025-016', 'commission_receivable', 1400.00, 0.00, 2690.00, NULL, NULL, 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 17 DAY), 'Commission for POL-2025-002', 'commission_receivable', 600.00, 0.00, 600.00, NULL, NULL, 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 13 DAY), 'Commission for POL-2025-007', 'commission_receivable', 1500.00, 0.00, 2100.00, NULL, NULL, 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 9 DAY), 'Commission for POL-2025-012', 'commission_receivable', 3200.00, 0.00, 5300.00, NULL, NULL, 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'Payment received from Company 2', 'payment_received', 0.00, 3000.00, 2300.00, 'NEFT', 'RCV-002', 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Commission for POL-2025-017', 'commission_receivable', 1040.00, 0.00, 3340.00, NULL, NULL, 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 16 DAY), 'Commission for POL-2025-003', 'commission_receivable', 4500.00, 0.00, 4500.00, NULL, NULL, 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'Payment received from Company 3', 'payment_received', 0.00, 4000.00, 500.00, 'Cash', 'RCV-003', 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 8 DAY), 'Commission for POL-2025-008', 'commission_receivable', 600.00, 0.00, 1100.00, NULL, NULL, 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'Commission for POL-2025-013', 'commission_receivable', 1800.00, 0.00, 2900.00, NULL, NULL, 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Commission for POL-2025-018', 'commission_receivable', 3400.00, 0.00, 6300.00, NULL, NULL, 1),
        (4, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'Commission for POL-2025-004', 'commission_receivable', 1680.00, 0.00, 1680.00, NULL, NULL, 1),
        (4, DATE_SUB(CURDATE(), INTERVAL 11 DAY), 'Commission for POL-2025-009', 'commission_receivable', 4250.00, 0.00, 5930.00, NULL, NULL, 1),
        (4, DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'Commission for POL-2025-014', 'commission_receivable', 600.00, 0.00, 6530.00, NULL, NULL, 1),
        (4, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Payment received from Company 4', 'payment_received', 0.00, 5000.00, 1530.00, 'NEFT', 'RCV-004', 1),
        (5, DATE_SUB(CURDATE(), INTERVAL 14 DAY), 'Commission for POL-2025-005', 'commission_receivable', 1040.00, 0.00, 1040.00, NULL, NULL, 1),
        (5, DATE_SUB(CURDATE(), INTERVAL 9 DAY), 'Commission for POL-2025-010', 'commission_receivable', 2100.00, 0.00, 3140.00, NULL, NULL, 1)");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 20 buyer transactions</div>';

    $conn->query("INSERT INTO expenses (expense_category_id, branch_id, expense_date, description, amount, payment_mode, bank_account_id, reference_number, created_by) VALUES
        (1, 1, DATE_SUB(CURDATE(), INTERVAL 20 DAY), 'Staff salary for month', 5000.00, 'Bank Transfer', 1, 'EXP-001', 1),
        (2, 1, DATE_SUB(CURDATE(), INTERVAL 19 DAY), 'Office rent payment', 3000.00, 'Bank Transfer', 1, 'EXP-002', 1),
        (3, 1, DATE_SUB(CURDATE(), INTERVAL 18 DAY), 'Monthly electricity bill', 1000.00, 'Bank Transfer', 1, 'EXP-003', 1),
        (4, 2, DATE_SUB(CURDATE(), INTERVAL 17 DAY), 'Internet and phone bill', 600.00, 'Bank Transfer', 2, 'EXP-004', 1),
        (5, 1, DATE_SUB(CURDATE(), INTERVAL 16 DAY), 'Printer ink and paper', 400.00, 'Cash', NULL, 'EXP-005', 1),
        (6, 2, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'Client visit travel cost', 700.00, 'Cash', NULL, 'EXP-006', 1),
        (7, 1, DATE_SUB(CURDATE(), INTERVAL 14 DAY), 'AC maintenance', 800.00, 'Bank Transfer', 1, 'EXP-007', 1),
        (1, 2, DATE_SUB(CURDATE(), INTERVAL 13 DAY), 'Staff salary for month', 4000.00, 'Bank Transfer', 2, 'EXP-008', 1),
        (2, 3, DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'Office rent payment', 2400.00, 'Bank Transfer', 3, 'EXP-009', 1),
        (3, 2, DATE_SUB(CURDATE(), INTERVAL 11 DAY), 'Monthly electricity bill', 900.00, 'Bank Transfer', 2, 'EXP-010', 1),
        (4, 1, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'Internet bill', 600.00, 'Bank Transfer', 1, 'EXP-011', 1),
        (5, 3, DATE_SUB(CURDATE(), INTERVAL 9 DAY), 'Office supplies', 300.00, 'Cash', NULL, 'EXP-012', 1),
        (6, 1, DATE_SUB(CURDATE(), INTERVAL 8 DAY), 'Agent meeting travel', 500.00, 'Cash', NULL, 'EXP-013', 1),
        (7, 2, DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'Plumbing repair', 600.00, 'Bank Transfer', 2, 'EXP-014', 1),
        (1, 3, DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'Staff salary for month', 3600.00, 'Bank Transfer', 3, 'EXP-015', 1),
        (2, 1, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'Office rent payment', 3000.00, 'Bank Transfer', 1, 'EXP-016', 1),
        (3, 3, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'Monthly electricity bill', 800.00, 'Bank Transfer', 3, 'EXP-017', 1),
        (6, 2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Site inspection travel', 400.00, 'Cash', NULL, 'EXP-018', 1),
        (5, 1, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Stationery purchase', 200.00, 'Cash', NULL, 'EXP-019', 1),
        (7, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Generator servicing', 1000.00, 'Bank Transfer', 1, 'EXP-020', 1)");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 20 expenses</div>';

    $conn->query("INSERT INTO other_income (branch_id, income_date, source, amount, payment_mode, bank_account_id, reference_number, created_by) VALUES
        (1, DATE_SUB(CURDATE(), INTERVAL 15 DAY), 'Consultation fee', 5000.00, 'Cash', NULL, 'INC-001', 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 14 DAY), 'Processing fee', 3000.00, 'Bank Transfer', 2, 'INC-002', 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'Late payment charge', 1500.00, 'Cash', NULL, 'INC-003', 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'Document handling fee', 2000.00, 'Bank Transfer', 3, 'INC-004', 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 8 DAY), 'Endorsement fee', 1000.00, 'Cash', NULL, 'INC-005', 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'Service charge', 2500.00, 'Bank Transfer', 2, 'INC-006', 1),
        (3, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'Inspection fee', 3500.00, 'Cash', NULL, 'INC-007', 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'Cancellation charge', 2000.00, 'Bank Transfer', 1, 'INC-008', 1),
        (2, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Processing fee', 1500.00, 'Cash', NULL, 'INC-009', 1),
        (1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Miscellaneous income', 4000.00, 'Bank Transfer', 1, 'INC-010', 1)");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 10 other income records</div>';

    $conn->query("INSERT INTO notifications (user_id, type, title, message, is_read) VALUES
        (1, 'general', 'Welcome to the system', 'Your account has been set up successfully', 1),
        (1, 'policy_expiry', 'Policy expiring soon', 'POL-2025-018 is expiring soon', 0),
        (1, 'payment_pending', 'Payment pending', 'Agent 1 payout is pending', 0),
        (1, 'commission_update', 'Commission updated', 'Buyer payout chart updated for this month', 1),
        (2, 'general', 'Welcome Manager 1', 'Your manager account is active', 1),
        (2, 'policy_expiry', 'Policy expiring soon', 'POL-2025-019 is expiring soon', 0),
        (2, 'payment_pending', 'Expense approval needed', 'New expense submitted for approval', 0),
        (3, 'general', 'Welcome Manager 2', 'Your manager account is active', 1),
        (3, 'policy_expiry', 'Policy expiring soon', 'POL-2025-020 is expiring soon', 0),
        (3, 'commission_update', 'Commission rates changed', 'Agent payout rates updated for this month', 0),
        (4, 'general', 'Welcome Agent 1', 'Your agent account is active', 1),
        (4, 'commission_update', 'Commission credited', 'Commission of 1500 credited for POL-2025-001', 1),
        (4, 'payment_pending', 'Payment processed', 'Payment of 2000 has been processed', 0),
        (5, 'general', 'Welcome Agent 2', 'Your agent account is active', 1),
        (5, 'commission_update', 'Commission credited', 'Commission of 400 credited for POL-2025-002', 0),
        (5, 'payment_pending', 'Payment pending', 'Your payout is being processed', 0),
        (6, 'general', 'Welcome Agent 3', 'Your agent account is active', 1),
        (6, 'commission_update', 'Commission credited', 'Commission of 3000 credited for POL-2025-003', 1),
        (1, 'general', 'System update', 'System maintenance completed successfully', 0),
        (1, 'policy_expiry', 'Multiple policies expiring', '3 policies are expiring this month', 0)");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 20 notifications</div>';

    $conn->query("INSERT INTO referrals (referrer_id, referred_id, points_earned, status, completed_at) VALUES
        (1, 5, 100, 'completed', NOW()),
        (2, 10, 100, 'completed', NOW()),
        (3, 15, 0, 'pending', NULL),
        (1, 8, 50, 'completed', NOW()),
        (2, 12, 0, 'pending', NULL)");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 5 referrals</div>';

    $conn->query("INSERT INTO point_transactions (customer_id, transaction_type, points, rupee_value, description, referral_id, balance_after) VALUES
        (1, 'earned', 100, 0.00, 'Referral reward for Customer 5', 1, 100),
        (2, 'earned', 100, 0.00, 'Referral reward for Customer 10', 2, 100),
        (1, 'redeemed', 50, 5.00, 'Redeemed for policy discount', NULL, 50),
        (1, 'earned', 50, 0.00, 'Referral reward for Customer 8', 4, 100),
        (2, 'earned', 0, 0.00, 'Pending referral for Customer 12', 5, 100)");
    $conn->query("UPDATE customers SET points_balance = 100 WHERE id = 1");
    $conn->query("UPDATE customers SET points_balance = 100 WHERE id = 2");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded 5 point transactions</div>';

    seedDemoHistory($conn);
    seedDemoExtras($conn);

    // recompute balances from ledgers — single source of truth
    foreach ([1, 2, 3] as $aid) {
        recalcLedger($conn, 'agent_transactions', 'agents', 'agent_id', $aid);
    }
    foreach ([1, 2, 3, 4, 5] as $bid) {
        recalcLedger($conn, 'buyer_transactions', 'buyers', 'buyer_id', $bid);
    }
    foreach ([1, 2, 3, 4] as $bk) {
        recalcBankLedger($conn, $bk);
    }
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Recomputed agent / buyer / bank balances from ledgers</div>';
}

// customer dues, endorsements, claims, reminders and the expense approval queue — so every screen has something to show
function seedDemoExtras($conn)
{
    $one = fn($sql) => $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    $leg = $conn->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, description, transaction_type, amount, running_balance, policy_entry_id, category, created_by) VALUES (?, ?, ?, ?, ?, 0, ?, ?, 1)");
    $setPay = $conn->prepare("UPDATE policy_entries SET payment_out = ?, payment_out_bank_account_id = 1, payment_in = ?, payment_in_bank_account_id = ?, payment_mode = 'Bank Transfer' WHERE id = ?");
    // dues: we paid the insurer, the customer paid part or nothing
    foreach ($one("SELECT id, policy_number, customer_name, net_premium_amount, entry_date FROM policy_entries WHERE status = 'active' ORDER BY entry_date DESC, id DESC LIMIT 5") as $i => $p) {
        $out = (float) $p['net_premium_amount'];
        $in = round($out * [0, 0.5, 0.3, 0, 0.6][$i] / 100) * 100;
        $inBank = $in > 0 ? 2 : null;
        $pid = (int) $p['id'];
        $setPay->bind_param('ddii', $out, $in, $inBank, $pid);
        $setPay->execute();
        $d = "Payment Out - {$p['policy_number']} ({$p['customer_name']})";
        $bk = 1;
        $t = 'debit';
        $cat = 'policy_payment_out';
        $leg->bind_param('isssdis', $bk, $p['entry_date'], $d, $t, $out, $pid, $cat);
        $leg->execute();
        if ($in > 0) {
            $d = "Payment In - {$p['policy_number']} ({$p['customer_name']})";
            $bk = 2;
            $t = 'credit';
            $cat = 'policy_payment_in';
            $leg->bind_param('isssdis', $bk, $p['entry_date'], $d, $t, $in, $pid, $cat);
            $leg->execute();
        }
    }
    // endorsements; the hypothecation fee went to other income + bank 3
    $act = $one("SELECT id, policy_number, branch_id, policy_start_date FROM policy_entries WHERE status = 'active' AND policy_start_date <= CURDATE() - INTERVAL 20 DAY ORDER BY id LIMIT 3");
    $end = $conn->prepare("INSERT INTO policy_endorsements (policy_entry_id, endorsement_number, endorsement_type, endorsement_date, description, premium_change, fee_amount, other_income_id, created_by) VALUES (?, ?, ?, DATE_SUB(CURDATE(), INTERVAL ? DAY), ?, ?, ?, ?, 1)");
    foreach ([['hypothecation', 'Loan with Demo Bank noted on the policy', 0, 500], ['addon_cover', 'Zero depreciation add-on added', 1250, 0], ['correction', 'Engine number corrected as per RC', 0, 0]] as $k => [$type, $desc, $chg, $fee]) {
        if (!isset($act[$k]))
            break;
        $p = $act[$k];
        $pid = (int) $p['id'];
        $no = sprintf('END-%s-%03d', date('Y'), $k + 1);
        $ago = 10 + $k * 4;
        $oi = null;
        if ($fee > 0) {
            $src = "Endorsement fee - {$p['policy_number']}";
            $conn->query("INSERT INTO other_income (branch_id, income_date, source, amount, payment_mode, bank_account_id, reference_number, created_by) VALUES (" . (int) $p['branch_id'] . ", DATE_SUB(CURDATE(), INTERVAL $ago DAY), '" . $conn->real_escape_string($src) . "', $fee, 'Bank Transfer', 3, '$no', 1)");
            $oi = $conn->insert_id;
            $conn->query("INSERT INTO bank_transactions (bank_account_id, other_income_id, transaction_date, description, reference_number, transaction_type, amount, running_balance, category, created_by)
                          VALUES (3, $oi, DATE_SUB(CURDATE(), INTERVAL $ago DAY), '" . $conn->real_escape_string("Other Income: $src [OI#$oi]") . "', '$no', 'credit', $fee, 0, 'other_income', 1)");
        }
        $end->bind_param('issisddi', $pid, $no, $type, $ago, $desc, $chg, $fee, $oi);
        $end->execute();
    }
    // claims in every state of the pipeline
    $pols = $one("SELECT id, policy_start_date, policy_end_date, idv, total_premium_amount FROM policy_entries WHERE status != 'cancelled' AND policy_start_date <= CURDATE() - INTERVAL 40 DAY AND policy_end_date >= CURDATE() ORDER BY id DESC LIMIT 6");
    $cl = $conn->prepare("INSERT INTO claims (claim_number, policy_entry_id, insurer_claim_number, claim_type, incident_date, intimation_date, incident_place, description, fir_number, surveyor_name, surveyor_mobile, survey_date, garage_name, settlement_mode, amount_claimed, amount_approved, amount_settled, settlement_date, status, rejection_reason, created_by)
                          VALUES (?, ?, ?, ?, DATE_SUB(CURDATE(), INTERVAL ? DAY), DATE_SUB(CURDATE(), INTERVAL ? DAY), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
    $demo = [ // type, incident days ago, status, claimed, approved, settled, mode, reason
        ['own_damage', 3, 'intimated', 42000, null, null, null, null],
        ['own_damage', 9, 'survey', 65000, null, null, 'cashless', null],
        ['third_party', 16, 'approved', 30000, 27500, null, 'reimbursement', null],
        ['own_damage', 24, 'settled', 18000, 16500, 16500, 'cashless', null],
        ['natural_calamity', 31, 'settled', 54000, 48000, 48000, 'reimbursement', null],
        ['theft', 35, 'rejected', 90000, null, null, null, 'FIR filed after the allowed period'],
    ];
    foreach ($demo as $k => [$type, $ago, $st, $claimed, $appr, $paid, $mode, $why]) {
        if (!isset($pols[$k]))
            break;
        $pid = (int) $pols[$k]['id'];
        $no = sprintf('CLM-%s-%04d', date('Y', strtotime("-$ago days")), $k + 1);
        $ins = sprintf('INS/CL/%05d', 3100 + $k * 7);
        $intim = max(0, $ago - 1);
        $place = ['Demo City Ring Road', 'Main Market', 'Highway Toll Plaza', 'Station Road', 'River Bridge', 'Mall Parking'][$k];
        $desc = ['Rear bumper damaged in a parking hit', 'Front-end collision at a junction', 'Third-party two-wheeler damaged while reversing', 'Windshield cracked by a stone', 'Water entered the engine during heavy rain', 'Vehicle stolen from parking'][$k];
        $fir = in_array($type, ['theft', 'third_party'], true) ? sprintf('FIR-%04d/%s', 210 + $k, date('Y')) : null;
        $sv = $st === 'intimated' ? null : 'Surveyor ' . ($k + 1);
        $svm = $sv ? sprintf('0300%07d', 3000000 + $k) : null;
        $svd = $sv ? date('Y-m-d', strtotime('-' . max(0, $ago - 3) . ' days')) : null;
        $garage = $mode === 'cashless' ? 'Demo Motors Workshop' : null;
        $setDate = $paid ? date('Y-m-d', strtotime('-' . max(0, $ago - 12) . ' days')) : null;
        $cl->bind_param('sissiissssssssdddsss', $no, $pid, $ins, $type, $ago, $intim, $place, $desc, $fir, $sv, $svm, $svd, $garage, $mode, $claimed, $appr, $paid, $setDate, $st, $why);
        $cl->execute();
    }
    // renewal reminders already sent on the nearest expiries
    $conn->query("UPDATE policy_entries SET last_reminder_at = NOW() - INTERVAL 2 DAY, reminder_count = 1 WHERE status = 'active' AND policy_end_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY ORDER BY policy_end_date LIMIT 3");
    // expense approval queue: one waiting, one sent back (no bank leg for either)
    $conn->query("INSERT INTO expenses (expense_category_id, branch_id, expense_date, description, payee_name, amount, payment_mode, bank_account_id, approval_status, approved_by, approval_note, created_by) VALUES
        (1, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Printer toner for Branch 1', 'Demo Stationers', 3200, 'Bank Transfer', 1, 'pending', NULL, NULL, 2),
        (1, 2, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'Team lunch', 'Demo Restaurant', 4800, 'Cash', NULL, 'rejected', 1, 'Not a business expense', 2)");
    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded customer dues, endorsements, ' . min(6, count($pols)) . ' claims, reminders and the expense approval queue</div>';
}

// a year of business so the dashboard has real shape: last year's book coming up for renewal
// (some already lapsed), 8 months of growing new business, monthly running costs, a live activity trail
function seedDemoHistory($conn)
{
    mt_srand(2026); // same demo every reset
    $types = ['Car' => [9000, 18000, 15, 10, 'Petrol'], 'Two Wheeler' => [3500, 7500, 12, 8, 'Petrol'], 'Commercial' => [18000, 32000, 17, 12, 'Diesel']];
    $tNames = array_keys($types);
    $today = date('Y-m-d');
    $n = 20; // customers / vehicles / policies 1-20 already exist

    $cust = $conn->prepare("INSERT INTO customers (name, mobile, email, state, referral_code) VALUES (?, ?, ?, ?, ?)");
    $veh = $conn->prepare("INSERT INTO vehicles (vehicle_number, vehicle_type, make, model, year_of_manufacture, fuel_type, customer_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $pol = $conn->prepare("INSERT INTO policy_entries (entry_date, branch_id, vehicle_id, vehicle_number, vehicle_type, customer_id, customer_name, agent_id, agent_name, buyer_id, company_name, company_code, policy_number, total_premium_amount, tp_amount, od_amount, net_premium_amount, buyer_commission_basis, buyer_commission_rate, buyer_commission_amount, agent_commission_basis, agent_commission_rate, agent_commission_amount, policy_start_date, policy_end_date, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'premium_pct',?,?,'premium_pct',?,?,?,?,?,1)");
    $agTx = $conn->prepare("INSERT INTO agent_transactions (agent_id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, reference_number, created_by) VALUES (?, ?, ?, 'commission', ?, 0, 0, ?, 1)");
    $byTx = $conn->prepare("INSERT INTO buyer_transactions (buyer_id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, reference_number, created_by) VALUES (?, ?, ?, 'commission_receivable', ?, 0, 0, ?, 1)");

    // one customer + vehicle + policy (+ commission ledger lines)
    $add = function ($start, $status) use (&$n, $types, $tNames, $cust, $veh, $pol, $agTx, $byTx) {
        $n++;
        $type = $tNames[mt_rand(0, 2)];
        [$lo, $hi, $bRate, $aRate, $fuel] = $types[$type];
        $name = "Customer $n";
        $mob = sprintf('0300200%04d', $n);
        $mail = "customer$n@demo.com";
        $state = ['State A', 'State B', 'State C'][$n % 3];
        $ref = sprintf('REF-%04d', $n);
        $cust->bind_param('sssss', $name, $mob, $mail, $state, $ref);
        $cust->execute();
        $cid = $cust->insert_id;
        $vno = sprintf('VH-%04d', $n);
        $make = 'Make ' . chr(65 + $n % 5);
        $model = "Model $n";
        $yr = mt_rand(2018, 2025);
        $veh->bind_param('ssssisi', $vno, $type, $make, $model, $yr, $fuel, $cid);
        $veh->execute();
        $vid = $veh->insert_id;

        $total = round(mt_rand($lo, $hi) / 500) * 500;
        $tp = round($total * 0.33 / 10) * 10;
        $od = round($total * 0.53 / 10) * 10;
        $net = $tp + $od;
        $bAmt = round($total * $bRate / 100, 2);
        $aAmt = round($total * $aRate / 100, 2);
        $branch = 1 + $n % 5;
        $aid = 1 + $n % 3;
        $aName = "Agent $aid";
        $bid = 1 + mt_rand(0, 4);
        $comp = "Company $bid";
        $code = "CMP$bid";
        $polNo = 'POL-' . substr($start, 0, 4) . '-' . sprintf('%03d', $n);
        $end = date('Y-m-d', strtotime("$start +1 year"));
        $pol->bind_param(
            'siissisisisssddddddddsss',
            $start,
            $branch,
            $vid,
            $vno,
            $type,
            $cid,
            $name,
            $aid,
            $aName,
            $bid,
            $comp,
            $code,
            $polNo,
            $total,
            $tp,
            $od,
            $net,
            $bRate,
            $bAmt,
            $aRate,
            $aAmt,
            $start,
            $end,
            $status
        );
        $pol->execute();
        if ($status === 'cancelled')
            return;
        $desc = "Commission for $polNo";
        $agTx->bind_param('issds', $aid, $start, $desc, $aAmt, $polNo);
        $agTx->execute();
        $byTx->bind_param('issds', $bid, $start, $desc, $bAmt, $polNo);
        $byTx->execute();
    };

    // last year's book: end dates from 4 weeks overdue to 2 months ahead
    foreach ([-28, -22, -17, -12, -8, -5, -3, -1, 0, 1, 2, 3, 4, 6, 7, 9, 11, 13, 15, 18, 21, 24, 27, 29, 34, 41, 48, 55] as $off) {
        $end = date('Y-m-d', strtotime("$today $off days"));
        $add(date('Y-m-d', strtotime("$end -1 year")), $off < 0 ? 'expired' : 'active');
    }
    // new business, growing month on month (current month only up to today)
    foreach ([7 => 4, 6 => 5, 5 => 5, 4 => 6, 3 => 7, 2 => 7, 1 => 8, 0 => 6] as $ago => $cnt) {
        $first = strtotime(date('Y-m-01') . " -$ago months");
        $last = $ago ? (int) date('t', $first) : (int) date('j');
        for ($i = 0; $i < $cnt; $i++) {
            $day = 1 + ($i * 37 + $ago * 11) % $last;
            $add(date('Y-m-', $first) . sprintf('%02d', $day), $i === 3 && $ago % 3 === 1 ? 'cancelled' : 'active');
        }
    }
    foreach ([$cust, $veh, $pol, $agTx, $byTx] as $st)
        $st->close();

    // monthly running costs + side income, cash so no bank legs are needed
    $exp = $conn->prepare("INSERT INTO expenses (expense_category_id, branch_id, expense_date, description, amount, payment_mode, bank_account_id, reference_number, created_by) VALUES (?, 1, ?, ?, ?, 'Cash', NULL, ?, 1)");
    $inc = $conn->prepare("INSERT INTO other_income (branch_id, income_date, source, amount, payment_mode, bank_account_id, reference_number, created_by) VALUES (?, ?, ?, ?, 'Cash', NULL, ?, 1)");
    for ($ago = 7, $k = 0; $ago >= 1; $ago--) {
        $m = date('Y-m-', strtotime(date('Y-m-01') . " -$ago months"));
        foreach ([[1, '05', 'Staff salary', 4000], [2, '03', 'Office rent', 2500], [3, '12', 'Electricity bill', 600 + $ago * 40], [6, '18', 'Field visits', 400 + $ago * 60]] as [$cat, $d, $what, $amt]) {
            $k++;
            $date = $m . $d;
            $ref = sprintf('EXP-H%03d', $k);
            $amt = (float) $amt;
            $exp->bind_param('issds', $cat, $date, $what, $amt, $ref);
            $exp->execute();
        }
        $date = $m . '20';
        $src = $ago % 2 ? 'Processing fee' : 'Endorsement fee';
        $amt = 1500.0 + $ago * 150;
        $ref = sprintf('INC-H%03d', $ago);
        $br = 1 + $ago % 3;
        $inc->bind_param('issds', $br, $date, $src, $amt, $ref);
        $inc->execute();
    }
    $exp->close();
    $inc->close();

    // recent activity trail (logins are left out of the dashboard feed)
    $conn->query("INSERT INTO activity_logs (user_id, username, action, details, ip_address, timestamp) VALUES
        (1, 'Admin User', 'Policy Created', 'POL-" . date('Y') . "-0" . ($n - 1) . " for Customer " . ($n - 1) . "', '127.0.0.1', NOW() - INTERVAL 25 MINUTE),
        (2, 'Manager 1', 'Agent Txn Added', 'Paid commission to Agent 2: Dr=3000', '127.0.0.1', NOW() - INTERVAL 2 HOUR),
        (1, 'Admin User', 'Customer Updated', 'Updated mobile for Customer 14', '127.0.0.1', NOW() - INTERVAL 4 HOUR),
        (2, 'Manager 1', 'Expense Added', 'Electricity bill, Branch 1', '127.0.0.1', NOW() - INTERVAL 1 DAY - INTERVAL 3 HOUR),
        (1, 'Admin User', 'Buyer Payout Chart Updated', 'Company 3 Commercial premium 17%', '127.0.0.1', NOW() - INTERVAL 1 DAY - INTERVAL 6 HOUR),
        (1, 'Admin User', 'Policy Deleted', 'Removed duplicate entry POL-" . date('Y') . "-099', '127.0.0.1', NOW() - INTERVAL 2 DAY),
        (2, 'Manager 1', 'Bank Txn Added', 'Premium received from Customer 9', '127.0.0.1', NOW() - INTERVAL 3 DAY),
        (1, 'Admin User', 'Agent Payout Bulk Upload', 'Uploaded agent_chart.xlsx: 12 ok, 0 failed', '127.0.0.1', NOW() - INTERVAL 4 DAY)");

    // renewal reminders for the agents + a few open tasks
    $conn->query("INSERT INTO notifications (user_id, type, title, message, is_read, created_at) VALUES
        (4, 'policy_expiry', 'Renewal due this week', '2 of your policies expire in the next 7 days', 0, NOW() - INTERVAL 1 HOUR),
        (4, 'commission_update', 'Commission credited', 'Commission for your September policies has been posted', 0, NOW() - INTERVAL 1 DAY),
        (5, 'policy_expiry', 'Renewal due this week', 'A policy of yours expires in 3 days', 0, NOW() - INTERVAL 3 HOUR),
        (6, 'policy_expiry', 'Policy lapsed', 'One policy expired without renewal', 0, NOW() - INTERVAL 2 DAY)");
    $conn->query("INSERT INTO todos (title, description, priority, status, due_date, assigned_to, created_by) VALUES
        ('Call customers with lapsed policies', 'Follow up on policies that expired this month', 'high', 'pending', DATE_ADD(CURDATE(), INTERVAL 1 DAY), 2, 1),
        ('Send renewal quotes for next week', 'Quotes for policies expiring in the next 7 days', 'urgent', 'in_progress', CURDATE(), 2, 1),
        ('Reconcile Bank A statement', 'Match premium receipts with bank entries', 'medium', 'pending', DATE_ADD(CURDATE(), INTERVAL 3 DAY), 1, 1),
        ('Update payout charts for next month', 'Collect revised rates from all companies', 'medium', 'pending', DATE_ADD(CURDATE(), INTERVAL 6 DAY), 3, 1),
        ('Archive last quarter documents', '', 'low', 'completed', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 1, 1)");

    echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Seeded ' . ($n - 20) . ' more policies (renewal book + 8 months of new business), monthly costs, activity, renewal alerts and tasks</div>';
}

// inline ledger recalc for agents/buyers (standalone — config.php not loaded here)
function recalcLedger($conn, $txTable, $partyTable, $fkCol, $partyId)
{
    $partyId = (int) $partyId;
    $bal = 0.00;
    $res = $conn->query("SELECT id, credit_amount, debit_amount FROM `$txTable` WHERE `$fkCol` = $partyId ORDER BY transaction_date ASC, id ASC");
    while ($r = $res->fetch_assoc()) {
        $bal += (float) $r['credit_amount'] - (float) $r['debit_amount'];
        $conn->query("UPDATE `$txTable` SET running_balance = " . $bal . " WHERE id = " . (int) $r['id']);
    }
    $conn->query("UPDATE `$partyTable` SET wallet_balance = $bal, outstanding_balance = $bal WHERE id = $partyId");
}

// inline bank ledger recalc
function recalcBankLedger($conn, $bankId)
{
    $bankId = (int) $bankId;
    $opening = 0.00;
    $o = $conn->query("SELECT opening_balance FROM bank_accounts WHERE id = $bankId");
    if ($o && ($row = $o->fetch_assoc()))
        $opening = (float) $row['opening_balance'];
    $bal = $opening;
    $res = $conn->query("SELECT id, transaction_type, amount FROM bank_transactions WHERE bank_account_id = $bankId ORDER BY transaction_date ASC, id ASC");
    while ($r = $res->fetch_assoc()) {
        $bal += ($r['transaction_type'] === 'credit') ? (float) $r['amount'] : -(float) $r['amount'];
        $conn->query("UPDATE bank_transactions SET running_balance = " . $bal . " WHERE id = " . (int) $r['id']);
    }
    $conn->query("UPDATE bank_accounts SET current_balance = $bal WHERE id = $bankId");
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon"
        href="https://blogger.googleusercontent.com/img/b/R29vZ2xl/AVvXsEiGXxCe0WNNedmFqSWeF761f7Kshhc-NP5ChRQKz9fr97cO8VaarvD0KlCwqHojJVBWv-RAxfOqMI5rD4H78KnARyOc6QgwL1nRRFWf5xNQ1d9F9HfAoLPPGlTyP0GwNl4n-INMEsWLQ4Y7zJtz5bOdAnc2ePH9-uCRgshlo6BsS6gJEz6fhrxL-5U5O3sX/s160/channels4_profile.jpg">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Database Setup</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=5.0">
</head>

<body>
    <div class="setup-wrapper">
        <div class="setup-container">
            <?php
            if ($action === 'update') {
                echo '<h2><i class="fas fa-database"></i> Database Update</h2>';
                echo '<p class="subtitle">Safe additive update — no existing data is deleted or modified.</p><hr>';
                $conn = setupConn();
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Database connection successful</div>';
                setupSchema($conn);
                setupColumnFixups($conn);
                setupDirectories();
                $conn->close();
                echo '<br><div class="log-item log-success"><i class="fas fa-check-circle"></i> <strong>Update completed successfully. No data was lost.</strong></div>';
                echo '<a href="login.php" class="btn"><i class="fas fa-sign-in-alt"></i> Go to Login Page</a>';
                echo ' <a href="setup.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>';

            } elseif ($action === 'clean_brokerage_schema') {
                echo '<h2><i class="fas fa-trash-alt"></i> Database Cleanup</h2>';
                echo '<p class="subtitle">Safely dropping Brokerage, Buyers &amp; Commission tables and columns while preserving all policies and customers.</p><hr>';
                $conn = setupConn();
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Database connection successful</div>';
                cleanupBrokerageAndCommissionSchema($conn);
                $conn->close();
                echo '<br><div class="log-item log-success"><i class="fas fa-check-circle"></i> <strong>Brokerage and Commission cleanup completed successfully!</strong></div>';
                echo '<a href="dashboard.php" class="btn btn-primary"><i class="fas fa-gauge-high"></i> Go to Dashboard</a>';
                echo ' <a href="setup.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Setup</a>';

            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset' && ($_POST['confirm'] ?? '') === 'yes' && hash_equals($_SESSION['setup_tok'] ?? '', $_POST['tok'] ?? '')) {
                unset($_SESSION['setup_tok']); // single use
                echo '<h2><i class="fas fa-database"></i> Database Reset</h2>';
                echo '<p class="subtitle">Dropping all tables, recreating schema, seeding demo data.</p><hr>';
                $conn = setupConn();
                echo '<div class="log-item log-success"><i class="fas fa-check-circle"></i> Database connection successful</div>';
                dropAllTables($conn);
                setupSchema($conn);
                setupColumnFixups($conn);
                seedDemoData($conn);
                setupDirectories();
                $conn->close();
                echo '<div class="credentials-box"><strong><i class="fas fa-key"></i> Login Credentials:</strong><br><br>';
                echo '<strong>Admin:</strong> admin@demo.com / admin123<br>';
                echo '<strong>Manager 1:</strong> manager1@demo.com / admin123<br>';
                echo '<strong>Manager 2:</strong> manager2@demo.com / admin123<br>';
                echo '<strong>Agent 1:</strong> agent1@demo.com / admin123<br>';
                echo '<strong>Agent 2:</strong> agent2@demo.com / admin123<br>';
                echo '<strong>Agent 3:</strong> agent3@demo.com / admin123<br><br>';
                echo '<em class="text-warning"><i class="fas fa-exclamation-triangle"></i> Change passwords after first login!</em></div>';
                echo '<br><div class="log-item log-success"><i class="fas fa-check-circle"></i> <strong>Reset completed successfully.</strong></div>';
                echo '<a href="login.php" class="btn"><i class="fas fa-sign-in-alt"></i> Go to Login Page</a>';

            } else {
                // landing page — two action buttons
                echo '<h2><i class="fas fa-database"></i> Database Setup</h2>';
                echo '<p class="subtitle">Choose an action below.</p><hr>';
                if ($fresh) {
                    echo '<div class="log-item log-info"><i class="fas fa-info-circle"></i> Fresh install detected — unauthenticated setup is allowed once.</div><br>';
                }
                echo '<div class="setup-actions">';
                echo '<a href="setup.php?action=update" class="btn btn-primary"><i class="fas fa-sync-alt"></i> Run Update (Safe)</a>';
                echo '<a href="setup.php?action=clean_brokerage_schema" class="btn" style="background:#0284c7;color:#fff;"><i class="fas fa-trash-alt"></i> Clean Brokerage &amp; Commission Schema</a>';
                $_SESSION['setup_tok'] = bin2hex(random_bytes(16));
                echo '<form method="post" action="setup.php" id="resetForm"><input type="hidden" name="action" value="reset"><input type="hidden" name="confirm" value="yes"><input type="hidden" name="tok" value="' . $_SESSION['setup_tok'] . '"></form>';
                echo '<button type="button" class="btn btn-danger" onclick="confirmReset()"><i class="fas fa-triangle-exclamation"></i> Drop All &amp; Reseed (Destructive)</button>';
                echo '</div>';
                echo '<p class="subtitle setup-hint"><i class="fas fa-shield-alt"></i> Update is additive and safe to run on production. Reset deletes ALL data.</p>';
            }
            ?>
        </div>
        <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme">
            <i class="fas fa-moon" id="themeIcon"></i>
        </button>
    </div>

    <script>
        function confirmReset() {
            if (confirm('WARNING: This will DROP ALL TABLES and DELETE ALL DATA, then reseed demo data. This cannot be undone. Continue?')) {
                document.getElementById('resetForm').submit(); // POST only
            }
        }
        function initTheme() {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
                document.body.classList.add('dark-mode');
                updateThemeIcon(true);
            }
        }
        function toggleTheme() {
            const isDark = document.body.classList.toggle('dark-mode');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            updateThemeIcon(isDark);
        }
        function updateThemeIcon(isDark) {
            const icon = document.getElementById('themeIcon');
            if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
        initTheme();
    </script>
</body>

</html>