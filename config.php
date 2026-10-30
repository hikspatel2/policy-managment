<?php
/**
 * Developed by Rudees Digital
 * Website: https://rudeesdigital.com
 * Email: contact@rudeesdigital.com
 * Website: https://rudeesdigital.com
  Email: contact@rudeesdigital.com
  WhatsApp: +91 8160140848
 *
 * Database Configuration File
 */

// Error handling - disable display_errors in production
error_reporting(E_ALL);
ini_set('display_errors', 0);  // Changed to 0 for production security
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

// Database credentials — Hostinger
define('DB_HOST', 'localhost');
define('DB_NAME', 'u456035299_policy_manage');
define('DB_USER', 'u456035299_rudees_digital');
define('DB_PASS', 'Rudees@2026');

// Security constants
define('SESSION_TIMEOUT', 1800); // 30 minutes
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// Secure session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    // secure cookie only when actually on HTTPS (local XAMPP runs http)
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.sid_length', 48);
    ini_set('session.cookie_lifetime', 0);
    session_start();
}

// Set HTTP security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

// Create database connection
function getDBConnection() {
    try {
        // shared host refuses a connect now and then (EPERM / too many) -> retry before failing
        for ($try = 0; ; $try++) {
            try {
                $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                break;
            } catch (mysqli_sql_exception $e) {
                if ($try >= 3 || in_array($e->getCode(), [1044, 1045, 1049], true)) throw $e; // bad creds / no db = no retry
                error_log("DB connect retry " . ($try + 1) . ": " . $e->getMessage());
                usleep([150000, 400000, 900000][$try]);
            }
        }

        if ($conn->connect_error) {
            error_log("Database connection failed: " . $conn->connect_error);
            die("Database connection failed. Please try again later.");
        }

        $conn->set_charset("utf8mb4");
        return $conn;
    } catch (Throwable $e) {
        error_log("Database error: " . $e->getMessage());
        $msg = htmlspecialchars($e->getMessage());
        die("<div style='font-family:sans-serif;max-width:620px;margin:50px auto;padding:25px;border:1px solid #f5c6cb;background:#f8d7da;color:#721c24;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.08);'>
            <h3 style='margin-top:0;color:#721c24;'>Database Error</h3>
            <p><strong>Cause:</strong> " . $msg . "</p>
            <p style='font-size:14px;color:#555;'>Please verify your credentials in <code>config.php</code> or initialize your database using <a href='setup.php' style='color:#0d6efd;font-weight:bold;'>setup.php</a>.</p>
        </div>");
    }
}

// CSRF Protection Functions
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// hidden csrf input for forms
function csrfInput() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken(), ENT_QUOTES) . '">';
}

// Session timeout check
function checkSessionTimeout() {
    if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_TIMEOUT)) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['LAST_ACTIVITY'] = time();
    return true;
}

// Input validation
function validatePassword($password) {
    if (strlen($password) < 6 || strlen($password) > 255) {
        return false;
    }
    return $password;
}

// Rate limiting functions
function checkLoginAttempts($username) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts
                           WHERE username = ? AND is_success = 0 AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)"); // failures only
    $lockout = LOGIN_LOCKOUT_TIME;
    $stmt->bind_param("si", $username, $lockout);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    return $row['attempts'] >= MAX_LOGIN_ATTEMPTS;
}

function recordLoginAttempt($username, $ok = false) {
    $conn = getDBConnection();
    $ip = $_SERVER['REMOTE_ADDR'];
    $okInt = $ok ? 1 : 0;
    $stmt = $conn->prepare("INSERT INTO login_attempts (username, ip_address, is_success) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $username, $ip, $okInt);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

function clearLoginAttempts($username) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE username = ? AND is_success = 0"); // keep success trail
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

// Activity Logging Functions
function logActivity($user_id, $username, $action, $details = '') {
    try {
        $conn = getDBConnection();
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, username, action, details, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $user_id, $username, $action, $details, $ip);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        return true;
    } catch (Exception $e) {
        error_log("Activity logging error: " . $e->getMessage());
        return false;
    }
}

// Balance Recalculation Helpers — recompute a party's balances from its ledger (single source of truth)

// agents: wallet_balance = Σcredit − Σdebit ; outstanding_balance = same ; rewrites running_balance per row
function recalcAgentBalances($conn, $agentId) {
    $agentId = (int)$agentId;
    $bal = 0.00;
    $stmt = $conn->prepare("SELECT id, credit_amount, debit_amount FROM agent_transactions WHERE agent_id = ? ORDER BY transaction_date ASC, id ASC");
    $stmt->bind_param("i", $agentId);
    $stmt->execute();
    $rows = $stmt->get_result();
    $upd = $conn->prepare("UPDATE agent_transactions SET running_balance = ? WHERE id = ?");
    while ($r = $rows->fetch_assoc()) {
        $bal += (float)$r['credit_amount'] - (float)$r['debit_amount'];
        $rid = (int)$r['id'];
        $upd->bind_param("di", $bal, $rid);
        $upd->execute();
    }
    $stmt->close();
    $upd->close();
    $a = $conn->prepare("UPDATE agents SET wallet_balance = ?, outstanding_balance = ? WHERE id = ?");
    $a->bind_param("ddi", $bal, $bal, $agentId);
    $a->execute();
    $a->close();
    return $bal;
}

// buyers: wallet_balance = Σcredit − Σdebit ; outstanding_balance = same ; rewrites running_balance per row
function recalcBuyerBalances($conn, $buyerId) {
    $buyerId = (int)$buyerId;
    $bal = 0.00;
    $stmt = $conn->prepare("SELECT id, credit_amount, debit_amount FROM buyer_transactions WHERE buyer_id = ? ORDER BY transaction_date ASC, id ASC");
    $stmt->bind_param("i", $buyerId);
    $stmt->execute();
    $rows = $stmt->get_result();
    $upd = $conn->prepare("UPDATE buyer_transactions SET running_balance = ? WHERE id = ?");
    while ($r = $rows->fetch_assoc()) {
        $bal += (float)$r['credit_amount'] - (float)$r['debit_amount'];
        $rid = (int)$r['id'];
        $upd->bind_param("di", $bal, $rid);
        $upd->execute();
    }
    $stmt->close();
    $upd->close();
    $b = $conn->prepare("UPDATE buyers SET wallet_balance = ?, outstanding_balance = ? WHERE id = ?");
    $b->bind_param("ddi", $bal, $bal, $buyerId);
    $b->execute();
    $b->close();
    return $bal;
}

// insurance verticals — Motor, Health, Life (extensible from insurance_categories table)
function getInsuranceCategories($conn = null, $activeOnly = true) {
    $close = false;
    if ($conn === null) { $conn = getDBConnection(); $close = true; }
    $out = [];
    $sql = "SELECT code, name, icon FROM insurance_categories" . ($activeOnly ? " WHERE is_active = 1" : "") . " ORDER BY display_order ASC, name ASC";
    try {
        $res = $conn->query($sql);
        if ($res) { while ($r = $res->fetch_assoc()) $out[$r['code']] = $r; }
    } catch (\Throwable $e) {}
    if ($close) $conn->close();
    if (!$out) {
        $out = [
            'motor'  => ['code' => 'motor',  'name' => 'Motor Insurance',  'icon' => 'fa-car'],
            'health' => ['code' => 'health', 'name' => 'Health Insurance', 'icon' => 'fa-heart-pulse'],
            'life'   => ['code' => 'life',   'name' => 'Life Insurance',   'icon' => 'fa-shield-heart'],
        ];
    }
    return $out;
}

function insuranceCategoryOptionsHtml($selected = 'motor', $conn = null) {
    $cats = getInsuranceCategories($conn, true);
    $html = '';
    foreach ($cats as $code => $c) {
        $sel = ($code === $selected) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($code, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($c['name'], ENT_QUOTES) . '</option>';
    }
    return $html;
}

function insuranceCategoryBadge($code) {
    $code = strtolower(trim((string)$code));
    $map = [
        'motor'  => ['name' => 'Motor',  'icon' => 'fa-car',          'cls' => 'chip-navy'],
        'health' => ['name' => 'Health', 'icon' => 'fa-heart-pulse', 'cls' => 'chip-emerald'],
        'life'   => ['name' => 'Life',   'icon' => 'fa-shield-heart', 'cls' => 'chip-purple'],
    ];
    $meta = $map[$code] ?? ['name' => ucfirst($code), 'icon' => 'fa-shield', 'cls' => 'chip-navy'];
    return '<span class="chip ' . $meta['cls'] . '"><i class="fas ' . $meta['icon'] . '"></i> ' . htmlspecialchars($meta['name']) . '</span>';
}

// vehicle categories - admin-editable list; falls back to legacy 4 if table missing/empty
function getVehicleCategories($conn = null, $activeOnly = true) {
    $close = false;
    if ($conn === null) { $conn = getDBConnection(); $close = true; }
    $out = [];
    $sql = "SELECT name FROM vehicle_categories" . ($activeOnly ? " WHERE is_active = 1" : "") . " ORDER BY name";
    try { // mysqli throws (exception mode) if the table doesn't exist yet — fall back, don't fatal
        $res = $conn->query($sql);
        if ($res) { while ($r = $res->fetch_assoc()) $out[] = $r['name']; }
    } catch (\Throwable $e) { /* pre-migration: table missing -> defaults below */ }
    if ($close) $conn->close();
    if (!$out) $out = ['Two Wheeler', 'Car', 'Commercial', 'Other']; // safe default pre-migration
    return $out;
}

// server-side <option> list for category selects; keeps a picked-but-inactive value so edit forms don't lose it
function vehicleCategoryOptionsHtml($selected = '', $conn = null) {
    $cats = getVehicleCategories($conn, true);
    if ($selected !== '' && !in_array($selected, $cats, true)) $cats[] = $selected;
    $html = '';
    foreach ($cats as $c) {
        $sel = ($c === $selected) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($c, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($c, ENT_QUOTES) . '</option>';
    }
    return $html;
}

function recalcBankBalance($conn, $bankAccountId) {
    $bankAccountId = (int)$bankAccountId;
    $opening = 0.00;
    $o = $conn->prepare("SELECT opening_balance FROM bank_accounts WHERE id = ?");
    $o->bind_param("i", $bankAccountId);
    $o->execute();
    $res = $o->get_result();
    if ($row = $res->fetch_assoc()) {
        $opening = (float)$row['opening_balance'];
    }
    $o->close();

    $bal = $opening;
    $stmt = $conn->prepare("SELECT id, transaction_type, amount FROM bank_transactions WHERE bank_account_id = ? ORDER BY transaction_date ASC, id ASC");
    $stmt->bind_param("i", $bankAccountId);
    $stmt->execute();
    $rows = $stmt->get_result();
    $upd = $conn->prepare("UPDATE bank_transactions SET running_balance = ? WHERE id = ?");
    while ($r = $rows->fetch_assoc()) {
        $bal += ($r['transaction_type'] === 'credit') ? (float)$r['amount'] : -(float)$r['amount'];
        $rid = (int)$r['id'];
        $upd->bind_param("di", $bal, $rid);
        $upd->execute();
    }
    $stmt->close();
    $upd->close();
    $b = $conn->prepare("UPDATE bank_accounts SET current_balance = ? WHERE id = ?");
    $b->bind_param("di", $bal, $bankAccountId);
    $b->execute();
    $b->close();
    return $bal;
}

// ---- Bank <-> Agent/Buyer interconnection ----
// only real cash payments cross a bank; commission/adjustment don't. cash out=debit, cash in=credit
function agentBankDir($t){ return $t === 'payment_out' ? 'debit' : ($t === 'payment_in' ? 'credit' : null); }
function buyerBankDir($t){ return $t === 'payment_made' ? 'debit' : ($t === 'payment_received' ? 'credit' : null); }
// reverse: bank credit/debit linked to a party -> the party txn type
function agentTypeForBankDir($d){ return $d === 'debit' ? 'payment_out' : 'payment_in'; }
function buyerTypeForBankDir($d){ return $d === 'debit' ? 'payment_made' : 'payment_received'; }

// TDS: party ledger keeps gross, bank leg = gross - tds. only on payment_out (agent) / payment_received (buyer)
function tdsError($tds, $gross, $tdsType, $type) {
    if ($tds < 0) return 'TDS cannot be negative';
    if ($tds > 0 && $type !== $tdsType) return 'TDS applies only to ' . str_replace('_', ' ', $tdsType) . ' entries';
    if ($tds > 0 && $tds >= $gross) return 'TDS must be less than the amount';
    return '';
}
// buyer row: gst + invoice no are informational (gst on our commission invoice)
function buyerTaxError($tds, $gst, $invNo, $gross, $type) {
    if ($gst < 0) return 'GST cannot be negative';
    if ($invNo !== null && mb_strlen($invNo) > 50) return 'Invoice number max 50 chars';
    return tdsError($tds, $gross, 'payment_received', $type);
}
// bank leg narration carries the withheld TDS so the statement reconciles
function bankLegDesc($desc, $tds) {
    return $tds > 0 ? mb_substr($desc . ' (TDS ' . number_format($tds, 2, '.', '') . ' deducted)', 0, 500) : $desc;
}

// bank leg (mirror) for a party payment -> returns new bank txn id
function insertBankMirror($conn, $bankAccId, $kind, $partyId, $partyTxnId, $date, $dir, $amt, $desc, $ref, $by) {
    $agentId = $kind === 'agent' ? (int)$partyId : null;
    $buyerId = $kind === 'buyer' ? (int)$partyId : null;
    $cat = $kind === 'agent' ? 'agent_payout' : 'buyer_payment';
    $stmt = $conn->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, description, reference_number, transaction_type, amount, running_balance, category, agent_id, buyer_id, link_txn_id, is_linked_mirror, created_by) VALUES (?,?,?,?,?,?,0.00,?,?,?,?,1,?)");
    $stmt->bind_param("issssdsiiii", $bankAccId, $date, $desc, $ref, $dir, $amt, $cat, $agentId, $buyerId, $partyTxnId, $by);
    $stmt->execute(); $id = (int)$stmt->insert_id; $stmt->close();
    return $id;
}
// agent leg (mirror) for a bank entry -> returns new agent txn id
function insertAgentMirror($conn, $agentId, $bankAccId, $bankTxnId, $date, $type, $cr, $dr, $desc, $ref, $by) {
    $stmt = $conn->prepare("INSERT INTO agent_transactions (agent_id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, reference_number, bank_account_id, bank_txn_id, is_linked_mirror, created_by) VALUES (?,?,?,?,?,?,0.00,?,?,?,1,?)");
    $stmt->bind_param("isssddsiii", $agentId, $date, $desc, $type, $cr, $dr, $ref, $bankAccId, $bankTxnId, $by);
    $stmt->execute(); $id = (int)$stmt->insert_id; $stmt->close();
    return $id;
}
// buyer leg (mirror) for a bank entry -> returns new buyer txn id
function insertBuyerMirror($conn, $buyerId, $bankAccId, $bankTxnId, $date, $type, $cr, $dr, $desc, $ref, $by) {
    $stmt = $conn->prepare("INSERT INTO buyer_transactions (buyer_id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, reference_number, bank_account_id, bank_txn_id, is_linked_mirror, created_by) VALUES (?,?,?,?,?,?,0.00,?,?,?,1,?)");
    $stmt->bind_param("isssddsiii", $buyerId, $date, $desc, $type, $cr, $dr, $ref, $bankAccId, $bankTxnId, $by);
    $stmt->execute(); $id = (int)$stmt->insert_id; $stmt->close();
    return $id;
}
// delete a mirror row by id -> returns parent id needed for recalc (0 if gone)
function deleteBankMirror($conn, $bankTxnId) {
    $bankTxnId = (int)$bankTxnId; if ($bankTxnId <= 0) return 0;
    $accId = 0;
    $q = $conn->prepare("SELECT bank_account_id FROM bank_transactions WHERE id = ?");
    $q->bind_param("i", $bankTxnId); $q->execute();
    if ($r = $q->get_result()->fetch_assoc()) $accId = (int)$r['bank_account_id'];
    $q->close();
    $d = $conn->prepare("DELETE FROM bank_transactions WHERE id = ?");
    $d->bind_param("i", $bankTxnId); $d->execute(); $d->close();
    return $accId;
}
function deleteAgentMirror($conn, $txnId) {
    $txnId = (int)$txnId; if ($txnId <= 0) return 0;
    $aid = 0;
    $q = $conn->prepare("SELECT agent_id FROM agent_transactions WHERE id = ?");
    $q->bind_param("i", $txnId); $q->execute();
    if ($r = $q->get_result()->fetch_assoc()) $aid = (int)$r['agent_id'];
    $q->close();
    $d = $conn->prepare("DELETE FROM agent_transactions WHERE id = ?");
    $d->bind_param("i", $txnId); $d->execute(); $d->close();
    return $aid;
}
function deleteBuyerMirror($conn, $txnId) {
    $txnId = (int)$txnId; if ($txnId <= 0) return 0;
    $bid = 0;
    $q = $conn->prepare("SELECT buyer_id FROM buyer_transactions WHERE id = ?");
    $q->bind_param("i", $txnId); $q->execute();
    if ($r = $q->get_result()->fetch_assoc()) $bid = (int)$r['buyer_id'];
    $q->close();
    $d = $conn->prepare("DELETE FROM buyer_transactions WHERE id = ?");
    $d->bind_param("i", $txnId); $d->execute(); $d->close();
    return $bid;
}
// party leg (mirror) for a bank entry -> returns [kind, partyTxnId] (['',0] if none)
function insertPartyMirrorForBank($conn, $linkType, $partyId, $bankAccId, $bankTxnId, $date, $bankType, $amt, $desc, $ref, $by) {
    $partyId = (int)$partyId;
    if ($partyId <= 0) return ['', 0];
    if ($linkType === 'agent') {
        $type = agentTypeForBankDir($bankType);            // credit->payment_in, debit->payment_out
        $cr = $bankType === 'credit' ? $amt : 0;
        $dr = $bankType === 'debit' ? $amt : 0;
        return ['agent', insertAgentMirror($conn, $partyId, $bankAccId, $bankTxnId, $date, $type, $cr, $dr, $desc, $ref, $by)];
    }
    if ($linkType === 'buyer') {
        $type = buyerTypeForBankDir($bankType);            // credit->payment_received, debit->payment_made
        $cr = $bankType === 'debit' ? $amt : 0;            // we paid buyer -> credit in buyer ledger
        $dr = $bankType === 'credit' ? $amt : 0;           // buyer paid us -> debit (receivable down)
        return ['buyer', insertBuyerMirror($conn, $partyId, $bankAccId, $bankTxnId, $date, $type, $cr, $dr, $desc, $ref, $by)];
    }
    return ['', 0];
}
// drop the party leg linked to a bank row + recalc that party ($agentId/$buyerId picks the table)
function dropLinkedPartyMirror($conn, $agentId, $buyerId, $linkTxnId) {
    $linkTxnId = (int)$linkTxnId;
    if ($linkTxnId <= 0) return;
    if ((int)$agentId > 0) { $aid = deleteAgentMirror($conn, $linkTxnId); if ($aid > 0) recalcAgentBalances($conn, $aid); }
    elseif ((int)$buyerId > 0) { $bid = deleteBuyerMirror($conn, $linkTxnId); if ($bid > 0) recalcBuyerBalances($conn, $bid); }
}

// System Settings Functions
function getSetting($key, $default = null) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $value = $row['setting_value'];
            $stmt->close();
            $conn->close();
            return $value;
        }

        $stmt->close();
        $conn->close();
        return $default;
    } catch (Exception $e) {
        error_log("Get setting error: " . $e->getMessage());
        return $default;
    }
}

function setSetting($key, $value) {
    try {
        $conn = getDBConnection();
        $by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; // who saved
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)");
        $stmt->bind_param("ssi", $key, $value, $by);
        $result = $stmt->execute();
        $stmt->close();
        $conn->close();
        return $result;
    } catch (Exception $e) {
        error_log("Set setting error: " . $e->getMessage());
        return false;
    }
}

// Profile Image Upload Functions
function uploadProfileImage($file, $user_id) {
    // Create uploads directory if it doesn't exist
    $upload_dir = __DIR__ . '/uploads/profiles/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Validate file
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $max_size = 2 * 1024 * 1024; // 2MB

    // Check file size
    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File size must be less than 2MB'];
    }

    // Check file type by MIME
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($file['tmp_name']);

    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WEBP allowed'];
    }

    // Check extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions)) {
        return ['success' => false, 'message' => 'Invalid file extension'];
    }

    // Generate unique filename
    $filename = 'profile_' . $user_id . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;

    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => 'uploads/profiles/' . $filename];
    }

    return ['success' => false, 'message' => 'Failed to upload file'];
}

function deleteProfileImage($filepath) {
    if ($filepath && file_exists(__DIR__ . '/' . $filepath)) {
        return unlink(__DIR__ . '/' . $filepath);
    }
    return false;
}

function getProfileImage($user_id) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT profile_image FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $profile_image = $row['profile_image'];
            $stmt->close();
            $conn->close();
            return !empty($profile_image) ? $profile_image : 'uploads/profiles/default_avatar.png';
        }

        $stmt->close();
        $conn->close();
        return 'uploads/profiles/default_avatar.png';
    } catch (Exception $e) {
        error_log("Get profile image error: " . $e->getMessage());
        return 'uploads/profiles/default_avatar.png';
    }
}

// User Theme Preferences Functions
// ui styles: soft = tinted ground + hairline cards (default), classic = bold navy headers & tiles
$UI_STYLES = [
    'soft'    => ['label' => 'Soft',    'icon' => 'fa-feather',            'hint' => 'Sidebar look everywhere'],
    'classic' => ['label' => 'Classic', 'icon' => 'fa-table-cells-large',  'hint' => 'Bold navy headers & tiles'],
];
// corners + surfaces across the app
$UI_SHAPES = [
    'squared' => ['label' => 'Squared', 'icon' => 'fa-square',             'hint' => 'Crisp 3–4px corners'],
    'rounded' => ['label' => 'Rounded', 'icon' => 'fa-circle-half-stroke', 'hint' => 'Soft curved corners'],
    'glass'   => ['label' => 'Glass',   'icon' => 'fa-droplet',            'hint' => 'Frosted glass, iOS-style'],
];
// sidebar colour: navy (deep fixed navy) · theme (palette primary) · light (tinted page ground)
$UI_SIDEBARS = [
    'navy'  => ['label' => 'Navy',  'icon' => 'fa-anchor',     'hint' => 'Deep navy sidebar'],
    'theme' => ['label' => 'Theme', 'icon' => 'fa-swatchbook', 'hint' => 'Sidebar takes the palette colour'],
    'light' => ['label' => 'Light', 'icon' => 'fa-sun',        'hint' => 'Light sidebar, page tint'],
];
// palette registry — this app's navy first, then the shared set (hexes as in colors.md)
$UI_PALETTES = [
    ['id' => 'NAVY', 'name' => 'Navy Blue',            'p' => '#001f3f', 's' => '#003366', 'a' => '#0074D9'],
    ['id' => 'UIv7', 'name' => 'Obsidian & Mint',      'p' => '#111827', 's' => '#374151', 'a' => '#34D399'],
    ['id' => 'UI1',  'name' => 'Enterprise Navy',      'p' => '#1E3A5F', 's' => '#4F6D8C', 'a' => '#5B8DEF'],
    ['id' => 'UI2',  'name' => 'Graphite & Cyan',      'p' => '#2C313C', 's' => '#49515F', 'a' => '#00B8D9'],
    ['id' => 'UI3',  'name' => 'Forest Executive',     'p' => '#234E52', 's' => '#52796F', 'a' => '#84A98C'],
    ['id' => 'UI4',  'name' => 'Walnut & Sand',        'p' => '#4E3D32', 's' => '#7B6855', 'a' => '#C49A6C'],
    ['id' => 'UI5',  'name' => 'Emerald Corporate',    'p' => '#0F766E', 's' => '#4CAF94', 'a' => '#22C55E'],
    ['id' => 'UI6',  'name' => 'Mocha Executive',      'p' => '#5B4636', 's' => '#8B7355', 'a' => '#D4A373'],
    ['id' => 'UI7',  'name' => 'Charcoal & Soft Gold', 'p' => '#2B2F36', 's' => '#4A4F57', 'a' => '#D4AF37'],
    ['id' => 'UIv1', 'name' => 'Zinc & Sky',           'p' => '#18181B', 's' => '#3F3F46', 'a' => '#0EA5E9'],
    ['id' => 'UIv2', 'name' => 'Ink & Violet',         'p' => '#0F0F12', 's' => '#27272A', 'a' => '#8B5CF6'],
    ['id' => 'UIv3', 'name' => 'Slate & Rose',         'p' => '#1E293B', 's' => '#475569', 'a' => '#F43F5E'],
    ['id' => 'UIv4', 'name' => 'Stone & Amber',        'p' => '#292524', 's' => '#57534E', 'a' => '#F59E0B'],
    ['id' => 'UIv5', 'name' => 'Mineral Teal',         'p' => '#134E4A', 's' => '#5F7A78', 'a' => '#2DD4BF'],
    ['id' => 'UIv6', 'name' => 'Paper & Copper',       'p' => '#3F2E24', 's' => '#6B5344', 'a' => '#C47B4A'],
    ['id' => 'UIv8', 'name' => 'Cloud & Indigo Soft',  'p' => '#312E81', 's' => '#4C51BF', 'a' => '#818CF8'],
];

function themeDefaults() {
    return ['theme_primary' => '#001f3f', 'theme_secondary' => '#003366', 'theme_accent' => '#0074D9', 'theme_mode' => 'light',
            'theme_style' => 'soft', 'theme_shape' => 'squared', 'theme_sidebar' => 'navy'];
}

// per-user look; a missing / unknown field falls back to the default
function getUserTheme($user_id, $reload = false) {
    static $cache = [];
    if (isset($cache[$user_id]) && !$reload) return $cache[$user_id];
    global $UI_STYLES, $UI_SHAPES, $UI_SIDEBARS;
    $t = themeDefaults();
    try {
        $conn = getDBConnection();
        // SELECT * on purpose: naming the newer columns would fail before setup.php?action=update has run
        $st = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $st->bind_param("i", $user_id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc() ?: [];
        $st->close();
        $conn->close();
        if (!empty($row['theme_primary']) && !empty($row['theme_secondary']) && !empty($row['theme_accent'])) {
            foreach (['theme_primary', 'theme_secondary', 'theme_accent'] as $k) $t[$k] = $row[$k];
        }
        if (!empty($row['theme_mode'])) $t['theme_mode'] = $row['theme_mode'];
        if (!empty($row['theme_style']) && isset($UI_STYLES[$row['theme_style']])) $t['theme_style'] = $row['theme_style'];
        if (!empty($row['theme_shape']) && isset($UI_SHAPES[$row['theme_shape']])) $t['theme_shape'] = $row['theme_shape'];
        if (!empty($row['theme_sidebar']) && isset($UI_SIDEBARS[$row['theme_sidebar']])) $t['theme_sidebar'] = $row['theme_sidebar'];
    } catch (Throwable $e) {
        error_log("Get user theme error: " . $e->getMessage());
    }
    return $cache[$user_id] = $t;
}

// users.theme_sidebar exists? (newest theme column) — a missing column makes every save fail
function themeSchemaReady() {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $conn = getDBConnection();
        $r = $conn->query("SHOW COLUMNS FROM users LIKE 'theme_sidebar'");
        $ok = $r && $r->num_rows > 0;
        $conn->close();
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}

// partial save — setUserTheme($uid, ['theme_style' => 'classic']) or the old 5-arg colour call
function setUserTheme($user_id, $fields, $secondary = null, $accent = null, $mode = null) {
    if (!is_array($fields)) $fields = ['theme_primary' => $fields, 'theme_secondary' => $secondary, 'theme_accent' => $accent, 'theme_mode' => $mode];
    $cols = array_filter(array_intersect_key($fields, array_flip(['theme_primary', 'theme_secondary', 'theme_accent', 'theme_mode', 'theme_style', 'theme_shape', 'theme_sidebar'])), function ($v) { return $v !== null; });
    if (!$cols) return false;
    try {
        $conn = getDBConnection();
        $st = $conn->prepare("UPDATE users SET " . implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($cols))) . " WHERE id = ?");
        $vals = array_values($cols);
        $vals[] = (int)$user_id;
        $st->bind_param(str_repeat('s', count($cols)) . 'i', ...$vals);
        $ok = $st->execute();
        $st->close();
        $conn->close();
        if ($ok) getUserTheme($user_id, true);
        return $ok;
    } catch (Throwable $e) {
        error_log("Set user theme error: " . $e->getMessage());
        return false;
    }
}

function generateUserThemeCSS($user_id) {
    $theme = getUserTheme($user_id);
    // hex -> "r, g, b" so css can tint with rgba(var(--navy-accent-rgb), .12)
    $rgb = function ($hex) {
        $h = ltrim(trim((string)$hex), '#');
        if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        $c = sscanf(substr($h . '000000', 0, 6), '%2x%2x%2x') ?: [0, 0, 0];
        return implode(', ', array_map('intval', $c));
    };
    [$p, $s, $a] = [$theme['theme_primary'], $theme['theme_secondary'], $theme['theme_accent']];

    return "<style id='user-theme-css'>\n:root {\n"
        . "    --navy-primary: {$p};\n    --navy-light: {$s};\n    --navy-accent: {$a};\n"
        . "    --navy-dark: {$p};\n    --navy-hover: {$s};\n"
        . "    --navy-primary-rgb: {$rgb($p)};\n    --navy-light-rgb: {$rgb($s)};\n"
        . "    --navy-accent-rgb: {$rgb($a)};\n}\n</style>\n"
        // look flags on <html>, set before the page paints
        . "<script>document.documentElement.setAttribute('data-ui','" . ($theme['theme_style'] === 'classic' ? 'classic' : 'soft') . "');"
        . "document.documentElement.setAttribute('data-shape','" . (in_array($theme['theme_shape'], ['rounded', 'glass'], true) ? $theme['theme_shape'] : 'squared') . "');"
        . "document.documentElement.setAttribute('data-sb','" . (in_array($theme['theme_sidebar'], ['theme', 'light'], true) ? $theme['theme_sidebar'] : 'navy') . "')</script>\n";
}

// sidebar / bottom-bar counts that clear once dealt with — one query, cached per request
// notif = my unread notifications · todo = open to-dos assigned to me (admin/manager only)
function navBadges($userId, $role = '') {
    static $cache = [];
    $key = $userId . '|' . $role;
    if (isset($cache[$key])) return $cache[$key];
    $uid = (int)$userId;
    $staff = in_array($role, ['admin', 'manager'], true);
    $isCustomer = ($role === 'customer');
    $open = "c.status IN ('intimated','survey','approved')"; // claims still waiting on someone

    if ($isCustomer) {
        $out = ['notif' => 0, 'todo' => 0, 'claims' => 0, 'exp' => 0];
        try {
            $conn = getDBConnection();
            $cid = customerIdForUser($conn, $uid);
            if (!$cid && !empty($_SESSION['customer_id'])) $cid = (int)$_SESSION['customer_id'];
            $famIds = $cid ? getFamilyCustomerIds($conn, $cid) : [];
            if (!empty($famIds)) {
                $in = implode(',', array_map('intval', $famIds));
                $res = $conn->query("SELECT COUNT(*) FROM claims c JOIN policy_entries pe ON pe.id = c.policy_entry_id WHERE pe.customer_id IN ($in) AND $open");
                if ($res) { $r = $res->fetch_row(); $out['claims'] = (int)$r[0]; }
            }
            $nRes = $conn->query("SELECT COUNT(*) FROM notifications WHERE user_id = $uid AND is_read = 0");
            if ($nRes) { $r = $nRes->fetch_row(); $out['notif'] = (int)$r[0]; }
            $conn->close();
        } catch (\Throwable $e) {}
        return $cache[$key] = $out;
    }

    // queues that clear when the work is done — [sql, takes the user id]
    $parts = array_filter([
        'notif' => ["SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0", true],
        'todo' => $staff ? ["SELECT COUNT(*) FROM todos WHERE assigned_to = ? AND status <> 'completed'", true] : null, // agents never open todo.php
        'claims' => $staff ? ["SELECT COUNT(*) FROM claims c WHERE $open", false]
                           : ["SELECT COUNT(*) FROM claims c JOIN policy_entries pe ON pe.id = c.policy_entry_id JOIN agents a ON a.id = pe.agent_id WHERE a.user_id = ? AND $open", true],
        'exp' => $role === 'admin' ? ["SELECT COUNT(*) FROM expenses WHERE approval_status = 'pending'", false] : null,
    ]);
    $out = ['notif' => 0, 'todo' => 0, 'claims' => 0, 'exp' => 0];
    try {
        $conn = getDBConnection();
        $run = function (array $ps) use ($conn, $uid) { // one round trip, scalar subqueries
            $st = $conn->prepare('SELECT ' . implode(', ', array_map(fn($k) => "({$ps[$k][0]}) AS `$k`", array_keys($ps))));
            $args = array_fill(0, count(array_filter(array_column($ps, 1))), $uid);
            if ($args) $st->bind_param(str_repeat('i', count($args)), ...$args);
            $st->execute();
            $r = $st->get_result()->fetch_assoc() ?: [];
            $st->close();
            return $r;
        };
        try {
            $row = $run($parts);
        } catch (Throwable $e) { // a table not built yet (before the database update) — count what exists
            $row = [];
            foreach ($parts as $k => $p) { try { $row += $run([$k => $p]); } catch (Throwable $e2) {} }
        }
        $conn->close();
        foreach ($row as $k => $v) $out[$k] = (int)$v;
    } catch (Throwable $e) {
        error_log("navBadges error: " . $e->getMessage());
    }
    return $cache[$key] = $out;
}

// bank leg of an income row: other_income_id first, [OI#id] marker for old rows
function oiLegId(mysqli $conn, int $oid): int {
    $st = $conn->prepare("SELECT id FROM bank_transactions WHERE other_income_id = ? LIMIT 1");
    $st->bind_param("i", $oid); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    if ($r) return (int)$r['id'];
    $mk = "%[OI#$oid]%";
    $st = $conn->prepare("SELECT id FROM bank_transactions WHERE other_income_id IS NULL AND category = 'other_income' AND description LIKE ? LIMIT 1");
    $st->bind_param("s", $mk); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return $r ? (int)$r['id'] : 0;
}
function oiDropLeg(mysqli $conn, int $oid): void {
    $st = $conn->prepare("DELETE FROM bank_transactions WHERE other_income_id = ?");
    $st->bind_param("i", $oid); $st->execute(); $st->close();
    $mk = "%[OI#$oid]%"; // legacy legs
    $st = $conn->prepare("DELETE FROM bank_transactions WHERE other_income_id IS NULL AND category = 'other_income' AND description LIKE ?");
    $st->bind_param("s", $mk); $st->execute(); $st->close();
}
// credit leg, keeps the marker in the text so old lookups still match
function oiPostLeg(mysqli $conn, int $oid, int $bankId, string $date, string $source, ?string $refNo, float $amt, int $uid): void {
    $desc = "Other Income: $source [OI#$oid]";
    $st = $conn->prepare("INSERT INTO bank_transactions (bank_account_id, other_income_id, transaction_date, description, reference_number, transaction_type, amount, running_balance, category, created_by) VALUES (?, ?, ?, ?, ?, 'credit', ?, 0, 'other_income', ?)");
    $st->bind_param("iisssdi", $bankId, $oid, $date, $desc, $refNo, $amt, $uid);
    if (!$st->execute()) { $st->close(); throw new Exception('bank post failed'); }
    $st->close();
}

// several settings, one query
function getSettings(array $keys) {
    $out = array_fill_keys($keys, null);
    if (!$keys) return $out;
    try {
        $conn = getDBConnection();
        $st = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (" . implode(',', array_fill(0, count($keys), '?')) . ")");
        $st->bind_param(str_repeat('s', count($keys)), ...$keys);
        $st->execute();
        foreach ($st->get_result()->fetch_all() as [$k, $v]) $out[$k] = $v;
        $st->close();
        $conn->close();
    } catch (Throwable $e) {
        error_log("Get settings error: " . $e->getMessage());
    }
    return $out;
}

// stream an upload once the caller has checked login + access — uploads are never public links
function sendStoredFile($relPath, $name = '') {
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    $base = realpath(__DIR__ . '/uploads');
    $full = realpath(__DIR__ . '/' . ltrim((string)$relPath, '/\\'));
    $ext = $full ? strtolower(pathinfo($full, PATHINFO_EXTENSION)) : '';
    if (!$base || !$full || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($full) || !isset($types[$ext])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('File not found');
    }
    $name = preg_replace('/[\x00-\x1f"\\\\]/', '', basename($name !== '' ? $name : $full));
    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . filesize($full));
    header("Content-Disposition: inline; filename=\"$name\"; filename*=UTF-8''" . rawurlencode($name));
    header('Cache-Control: private, max-age=600');
    readfile($full);
    exit;
}

// unguessable stored name, original kept in the db
function storedName($prefix, $ext) {
    return $prefix . '_' . bin2hex(random_bytes(16)) . '.' . strtolower($ext);
}

// agent row behind a login (null = staff / unlinked)
function agentIdForUser($conn, $uid) {
    static $memo = [];
    $uid = (int)$uid;
    if (array_key_exists($uid, $memo)) return $memo[$uid];
    $st = $conn->prepare("SELECT id FROM agents WHERE user_id = ? LIMIT 1");
    $st->bind_param('i', $uid);
    $st->execute();
    $r = $st->get_result()->fetch_row();
    $st->close();
    return $memo[$uid] = $r ? (int)$r[0] : null;
}

// customer row behind a login (null = staff / unlinked)
function customerIdForUser($conn, $uid) {
    static $memo = [];
    $uid = (int)$uid;
    if (array_key_exists($uid, $memo)) return $memo[$uid];
    try {
        $st = $conn->prepare("SELECT customer_id FROM users WHERE id = ? LIMIT 1");
        $st->bind_param('i', $uid);
        $st->execute();
        $r = $st->get_result()->fetch_row();
        $st->close();
        return $memo[$uid] = ($r && !empty($r[0])) ? (int)$r[0] : null;
    } catch (\Throwable $e) {
        return $memo[$uid] = null;
    }
}

// all customer IDs in the customer's family group (self + linked family members + shared phone)
function getFamilyCustomerIds($conn, $customerId) {
    $cid = (int)$customerId;
    if ($cid <= 0) return [];
    
    $ids = [$cid];
    try {
        $st = $conn->prepare("SELECT id, mobile, family_head_id FROM customers WHERE id = ? LIMIT 1");
        $st->bind_param('i', $cid);
        $st->execute();
        $cust = $st->get_result()->fetch_assoc();
        $st->close();
        
        if ($cust) {
            $headId = !empty($cust['family_head_id']) ? (int)$cust['family_head_id'] : $cid;
            $mobile = trim((string)($cust['mobile'] ?? ''));
            
            $ids[] = $headId;
            
            // All members whose head is headId or this cid
            $st2 = $conn->prepare("SELECT id FROM customers WHERE (family_head_id = ? OR family_head_id = ?) AND is_active = 1");
            $st2->bind_param('ii', $headId, $cid);
            $st2->execute();
            $res = $st2->get_result();
            while ($r = $res->fetch_assoc()) {
                $ids[] = (int)$r['id'];
            }
            $st2->close();
            
            // Shared primary mobile number
            if ($mobile !== '' && strlen($mobile) >= 10) {
                $st3 = $conn->prepare("SELECT id FROM customers WHERE mobile = ? AND is_active = 1");
                $st3->bind_param('s', $mobile);
                $st3->execute();
                $res3 = $st3->get_result();
                while ($r3 = $res3->fetch_assoc()) {
                    $ids[] = (int)$r3['id'];
                }
                $st3->close();
            }
        }
    } catch (\Throwable $e) {}
    
    return array_values(array_unique(array_filter($ids)));
}

// staff see every policy, an agent only its own book, customer only self & family
function canSeePolicy($conn, $pid) {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin' || $role === 'manager') return true;
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $pid = (int)$pid;

    if ($role === 'agent') {
        $aid = agentIdForUser($conn, $uid);
        if (!$aid) return false;
        $st = $conn->prepare("SELECT 1 FROM policy_entries WHERE id = ? AND agent_id = ?");
        $st->bind_param('ii', $pid, $aid);
        $st->execute();
        $ok = (bool)$st->get_result()->fetch_row();
        $st->close();
        return $ok;
    }

    if ($role === 'customer') {
        $cid = customerIdForUser($conn, $uid);
        if (!$cid && !empty($_SESSION['customer_id'])) $cid = (int)$_SESSION['customer_id'];
        if (!$cid) return false;
        $famIds = getFamilyCustomerIds($conn, $cid);
        if (empty($famIds)) return false;
        $in = implode(',', array_map('intval', $famIds));
        $res = $conn->query("SELECT 1 FROM policy_entries WHERE id = $pid AND customer_id IN ($in) LIMIT 1");
        return (bool)($res && $res->num_rows > 0);
    }

    return false;
}

// ============================================================
// AGENT SUBSCRIPTION, USAGE & LIMIT ENFORCEMENT HELPERS
// ============================================================

function getAgentSubscription($conn, $agentId) {
    $aid = (int)$agentId;
    if ($aid <= 0) return null;

    $stmt = $conn->prepare("SELECT s.*, p.name AS plan_name, p.slug AS plan_slug, p.description AS plan_description,
                                   p.max_customers, p.max_policies, p.monthly_ai_scans, p.whatsapp_limit,
                                   p.reports_access, p.advanced_features, p.team_members_limit,
                                   DATEDIFF(s.expiry_date, CURDATE()) AS days_left
                            FROM subscriptions s
                            JOIN subscription_plans p ON s.plan_id = p.id
                            WHERE s.agent_id = ?
                            ORDER BY (s.status = 'active' AND s.expiry_date >= CURDATE()) DESC, s.id DESC
                            LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $aid);
    $stmt->execute();
    $sub = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sub) return null;

    // Refresh actual customer and policy counts
    $cRes = $conn->query("SELECT COUNT(*) FROM customers WHERE agent_id = $aid AND is_active = 1");
    $sub['actual_customer_count'] = ($cRes && ($r = $cRes->fetch_row())) ? (int)$r[0] : 0;

    $pRes = $conn->query("SELECT COUNT(*) FROM policy_entries WHERE agent_id = $aid AND deleted_at IS NULL");
    $sub['actual_policy_count'] = ($pRes && ($r = $pRes->fetch_row())) ? (int)$r[0] : 0;

    $sub['is_valid'] = ($sub['status'] === 'active' && (int)$sub['days_left'] >= 0);
    return $sub;
}

function checkAgentSubscriptionLimit($conn, $agentId, $resource = 'general') {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin' || $role === 'manager') {
        return ['allowed' => true, 'current' => 0, 'limit' => 999999, 'message' => ''];
    }

    $aid = (int)$agentId;
    if ($aid <= 0) {
        return ['allowed' => false, 'message' => 'Invalid agent account.'];
    }

    $sub = getAgentSubscription($conn, $aid);
    if (!$sub || !$sub['is_valid']) {
        return [
            'allowed' => false,
            'reason' => 'subscription_expired',
            'message' => 'Active subscription plan required. Please select or renew your subscription.',
            'redirect' => 'agent_plans.php'
        ];
    }

    switch ($resource) {
        case 'customer':
            $current = (int)$sub['actual_customer_count'];
            $max = (int)$sub['max_customers'];
            if ($current >= $max) {
                return [
                    'allowed' => false,
                    'reason' => 'limit_exceeded',
                    'current' => $current,
                    'limit' => $max,
                    'message' => "Customer limit reached ($current / $max). Please upgrade your plan."
                ];
            }
            return ['allowed' => true, 'current' => $current, 'limit' => $max];

        case 'policy':
            $current = (int)$sub['actual_policy_count'];
            $max = (int)$sub['max_policies'];
            if ($current >= $max) {
                return [
                    'allowed' => false,
                    'reason' => 'limit_exceeded',
                    'current' => $current,
                    'limit' => $max,
                    'message' => "Policy limit reached ($current / $max). Please upgrade your plan."
                ];
            }
            return ['allowed' => true, 'current' => $current, 'limit' => $max];

        case 'ai_scan':
            $current = (int)$sub['ai_scan_count'];
            $max = (int)$sub['monthly_ai_scans'];
            if ($current >= $max) {
                return [
                    'allowed' => false,
                    'reason' => 'limit_exceeded',
                    'current' => $current,
                    'limit' => $max,
                    'message' => "Monthly AI Policy Scan limit reached ($current / $max). Please upgrade your plan."
                ];
            }
            return ['allowed' => true, 'current' => $current, 'limit' => $max];

        case 'whatsapp':
            $current = (int)$sub['whatsapp_count'];
            $max = (int)$sub['whatsapp_limit'];
            if ($current >= $max) {
                return [
                    'allowed' => false,
                    'reason' => 'limit_exceeded',
                    'current' => $current,
                    'limit' => $max,
                    'message' => "WhatsApp notification limit reached ($current / $max). Please upgrade your plan."
                ];
            }
            return ['allowed' => true, 'current' => $current, 'limit' => $max];

        default:
            return ['allowed' => true];
    }
}

function incrementAgentUsage($conn, $agentId, $resource, $qty = 1) {
    $aid = (int)$agentId;
    $qty = (int)$qty;
    if ($aid <= 0 || $qty <= 0) return false;

    $col = ($resource === 'ai_scan') ? 'ai_scan_count' : (($resource === 'whatsapp') ? 'whatsapp_count' : null);
    if (!$col) return false;

    $st = $conn->prepare("UPDATE subscriptions SET $col = $col + ? WHERE agent_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    if ($st) {
        $st->bind_param('ii', $qty, $aid);
        $st->execute();
        $st->close();
        return true;
    }
    return false;
}

function activateAgentSubscription($conn, $agentId, $planId, $billingCycle = 'monthly', $paymentId = null, $orderId = null, $method = 'razorpay', $amount = 0.0) {
    $aid = (int)$agentId;
    $pid = (int)$planId;
    if ($aid <= 0 || $pid <= 0) return false;

    // Fetch plan
    $pStmt = $conn->prepare("SELECT * FROM subscription_plans WHERE id = ? LIMIT 1");
    $pStmt->bind_param('i', $pid);
    $pStmt->execute();
    $plan = $pStmt->get_result()->fetch_assoc();
    $pStmt->close();
    if (!$plan) return false;

    // Calculate duration in days
    $days = 30;
    if ($billingCycle === 'quarterly') {
        $days = 90;
        if ($amount <= 0) $amount = (float)$plan['quarterly_price'];
    } elseif ($billingCycle === 'yearly') {
        $days = 365;
        if ($amount <= 0) $amount = (float)$plan['yearly_price'];
    } elseif ($billingCycle === 'trial' || $plan['is_trial']) {
        $days = (int)($plan['trial_days'] ?: 14);
        $amount = 0.00;
        $billingCycle = 'trial';
    } else {
        $days = (int)($plan['validity_days'] ?: 30);
        if ($amount <= 0) $amount = (float)$plan['monthly_price'];
    }

    $startDate = date('Y-m-d');
    $expiryDate = date('Y-m-d', strtotime("+$days days"));

    // Deactivate previous active subscriptions
    $conn->query("UPDATE subscriptions SET status = 'expired' WHERE agent_id = $aid AND status = 'active'");

    // Insert new subscription
    $subStmt = $conn->prepare("INSERT INTO subscriptions (agent_id, plan_id, billing_cycle, start_date, expiry_date, status, auto_renew) VALUES (?, ?, ?, ?, ?, 'active', 1)");
    $subStmt->bind_param('iisss', $aid, $pid, $billingCycle, $startDate, $expiryDate);
    $subStmt->execute();
    $subscriptionId = $conn->insert_id;
    $subStmt->close();

    // Create Payment Record & Invoice
    $invNum = 'INV-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6));
    if (!$orderId) $orderId = 'ORD-' . strtoupper(substr(uniqid(), -8));
    if (!$paymentId) $paymentId = 'PAY-' . strtoupper(substr(uniqid(), -8));

    $payStmt = $conn->prepare("INSERT INTO subscription_payments (subscription_id, agent_id, plan_id, order_id, payment_id, amount, billing_cycle, payment_method, status, invoice_number, paid_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, NOW())");
    $payStmt->bind_param('iiissdsss', $subscriptionId, $aid, $pid, $orderId, $paymentId, $amount, $billingCycle, $method, $invNum);
    $payStmt->execute();
    $payStmt->close();

    // Update agent's active_subscription_id and user status
    $conn->query("UPDATE agents SET active_subscription_id = $subscriptionId WHERE id = $aid");
    $aRow = $conn->query("SELECT user_id, email, name FROM agents WHERE id = $aid")->fetch_assoc();
    if ($aRow && !empty($aRow['user_id'])) {
        $uid = (int)$aRow['user_id'];
        $conn->query("UPDATE users SET status = 'active', is_active = 1 WHERE id = $uid");

        // Optional confirmation email
        if (!empty($aRow['email'])) {
            $subj = "Subscription Activated: " . $plan['name'];
            $body = "<p>Dear " . htmlspecialchars($aRow['name']) . ",</p>"
                  . "<p>Your subscription to <strong>" . htmlspecialchars($plan['name']) . "</strong> has been successfully activated!</p>"
                  . "<p><strong>Billing Cycle:</strong> " . ucfirst($billingCycle) . "<br>"
                  . "<strong>Valid Until:</strong> " . date('d M Y', strtotime($expiryDate)) . "<br>"
                  . "<strong>Invoice Number:</strong> " . $invNum . "<br>"
                  . "<strong>Amount Paid:</strong> " . number_format($amount, 2) . "</p>"
                  . "<p>You can now manage your customers, policies, and AI scans from your dashboard.</p>";
            sendEmail($aRow['email'], $subj, emailLayout($subj, $body));
        }
    }

    return $subscriptionId;
}

// 5-Stage Policy Expiry Reminders Scheduler
function schedulePolicyReminders($conn, $policyId, $agentId, $customerId, $expiryDate) {
    $pid = (int)$policyId;
    $aid = (int)$agentId;
    $cid = (int)$customerId;
    if ($pid <= 0 || empty($expiryDate)) return false;

    // Check if reminders already exist for this policy; cancel any pending old reminders
    $conn->query("UPDATE reminder_schedules SET status = 'cancelled' WHERE policy_entry_id = $pid AND status = 'pending'");

    $stages = [
        '30_days'    => '-30 days',
        '14_days'    => '-14 days',
        '7_days'     => '-7 days',
        '2_days'     => '-2 days',
        'expiry_day' => '0 days'
    ];

    $expTs = strtotime($expiryDate);
    $today = date('Y-m-d');
    $stmt = $conn->prepare("INSERT INTO reminder_schedules (policy_entry_id, agent_id, customer_id, reminder_stage, scheduled_date, scheduled_channel, status) VALUES (?, ?, ?, ?, ?, 'both', 'pending')");

    foreach ($stages as $stage => $offset) {
        $schedDate = date('Y-m-d', strtotime($offset, $expTs));
        // Only schedule if date is today or in future
        if ($schedDate >= $today) {
            $stmt->bind_param('iiiss', $pid, $aid, $cid, $stage, $schedDate);
            $stmt->execute();
        }
    }
    $stmt->close();
    return true;
}

// WhatsApp Cloud API Sender
function sendWhatsAppCloudMessage($toPhone, $messageText, $templateName = null, array $templateParams = []) {
    $enabled = getSetting('wa_cloud_enabled', '0');
    $token = getSetting('wa_cloud_api_token', '');
    $phoneId = getSetting('wa_phone_number_id', '');

    if ($enabled !== '1' || empty($token) || empty($phoneId)) {
        return ['success' => false, 'error' => 'WhatsApp Cloud API is not configured or disabled'];
    }

    $url = "https://graph.facebook.com/v20.0/{$phoneId}/messages";
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => waNumber($toPhone),
    ];

    if ($templateName) {
        $payload['type'] = 'template';
        $payload['template'] = [
            'name' => $templateName,
            'language' => ['code' => 'en'],
            'components' => $templateParams
        ];
    } else {
        $payload['type'] = 'text';
        $payload['text'] = ['body' => $messageText];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['success' => false, 'error' => $err];
    $json = json_decode($res, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($json['messages'][0]['id'])) {
        return ['success' => true, 'message_id' => $json['messages'][0]['id'], 'raw' => $json];
    }
    return ['success' => false, 'error' => $json['error']['message'] ?? 'API error ' . $httpCode, 'raw' => $json];
}

// expiry alerts to admins + the policy's agent, then active -> expired. renewed policies are skipped
function runExpiryCheck($conn) {
    $st = $conn->prepare("SELECT pe.id, pe.policy_number, pe.customer_name, pe.agent_id, pe.policy_end_date, pe.vehicle_number, pe.company_name, a.user_id AS agent_user_id
        FROM policy_entries pe LEFT JOIN agents a ON a.id = pe.agent_id
        WHERE pe.status = 'active' AND pe.policy_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND NOT EXISTS (SELECT 1 FROM policy_entries r WHERE r.previous_policy_id = pe.id)
          AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.policy_entry_id = pe.id AND n.type = 'policy_expiry' AND n.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY))");
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    $admins = array_map('intval', array_column($conn->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetch_all(MYSQLI_ASSOC), 'id'));
    $ins = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, policy_entry_id, agent_id) VALUES (?, 'policy_expiry', ?, ?, ?, ?)");
    $created = 0;
    foreach ($rows as $p) {
        $days = (int)round((strtotime($p['policy_end_date']) - strtotime(date('Y-m-d'))) / 86400);
        $title = "Policy Expiring: {$p['policy_number']}";
        $msg = "Policy {$p['policy_number']} for {$p['customer_name']} (Vehicle: {$p['vehicle_number']}, Company: {$p['company_name']}) expires on {$p['policy_end_date']} ($days days remaining).";
        $pid = (int)$p['id'];
        $aid = $p['agent_id'] ? (int)$p['agent_id'] : null;
        foreach (array_unique(array_merge($admins, $p['agent_user_id'] ? [(int)$p['agent_user_id']] : [])) as $uid) {
            $ins->bind_param('issii', $uid, $title, $msg, $pid, $aid);
            $ins->execute();
            $created++;
        }
    }
    $ins->close();
    $conn->query("UPDATE policy_entries SET status = 'expired' WHERE status = 'active' AND policy_end_date < CURDATE()");
    $remindersProcessed = processDueReminders($conn);
    return ['notifications_created' => $created, 'policies_expired' => $conn->affected_rows, 'reminders_sent' => $remindersProcessed];
}

function processDueReminders($conn) {
    $today = date('Y-m-d');
    $schedRes = $conn->query("
        SELECT rs.*, pe.policy_number, pe.company_name, pe.policy_end_date, pe.vehicle_number, pe.plan_name,
               c.name AS customer_name, c.mobile AS customer_mobile, c.email AS customer_email,
               c.primary_wa_consent, c.email_consent
        FROM reminder_schedules rs
        JOIN policy_entries pe ON pe.id = rs.policy_entry_id
        JOIN customers c ON c.id = rs.customer_id
        WHERE rs.status = 'pending' AND rs.scheduled_date <= CURDATE()
        ORDER BY rs.scheduled_date ASC
        LIMIT 100
    ");

    $processed = 0;
    if (!$schedRes) return 0;

    $branding = getBranding();
    $bizName = $branding['site_name'];

    while ($r = $schedRes->fetch_assoc()) {
        $rsId = (int)$r['id'];
        $pid = (int)$r['policy_entry_id'];
        $aid = (int)$r['agent_id'];
        $cid = (int)$r['customer_id'];
        $stage = $r['reminder_stage'];
        $stageDays = [
            '30_days' => '30 days',
            '14_days' => '14 days',
            '7_days'  => '7 days',
            '2_days'  => '2 days',
            'expiry_day' => 'TODAY'
        ][$stage] ?? 'soon';

        $text = "Dear {$r['customer_name']}, your {$r['company_name']} policy {$r['policy_number']} expires in {$stageDays} on {$r['policy_end_date']}. Please contact us to renew on time. Regards, {$bizName}";

        $waSent = false;
        $emailSent = false;

        // Check WhatsApp consent & agent limit
        if (!empty($r['customer_mobile']) && !empty($r['primary_wa_consent'])) {
            $limitChk = checkAgentSubscriptionLimit($conn, $aid, 'whatsapp');
            if ($limitChk['allowed']) {
                $waRes = sendWhatsAppCloudMessage($r['customer_mobile'], $text);
                $status = $waRes['success'] ? 'sent' : 'failed';
                $msgId = $waRes['message_id'] ?? null;
                $errMsg = $waRes['error'] ?? null;

                $nl = $conn->prepare("INSERT INTO notification_logs (reminder_schedule_id, policy_entry_id, agent_id, customer_id, channel, recipient_contact, message_content, status, provider_message_id, error_message) VALUES (?, ?, ?, ?, 'whatsapp', ?, ?, ?, ?, ?)");
                $nl->bind_param('iiiisssss', $rsId, $pid, $aid, $cid, $r['customer_mobile'], $text, $status, $msgId, $errMsg);
                $nl->execute();
                $nl->close();

                if ($waRes['success']) {
                    incrementAgentUsage($conn, $aid, 'whatsapp');
                    $waSent = true;
                }
            }
        }

        // Check Email consent
        if (!empty($r['customer_email']) && !empty($r['email_consent'])) {
            $subj = "Policy Expiry Notice: {$r['policy_number']} - {$bizName}";
            $body = emailLayout($subj, "
                <p>Dear " . htmlspecialchars($r['customer_name']) . ",</p>
                <p>This is a friendly reminder that your <strong>" . htmlspecialchars($r['company_name']) . "</strong> insurance policy <strong>" . htmlspecialchars($r['policy_number']) . "</strong> expires in <strong>{$stageDays}</strong> on <strong>" . date('d M Y', strtotime($r['policy_end_date'])) . "</strong>.</p>
                <p>To avoid coverage lapse and safeguard your No Claim Bonus (NCB), please reply to this email or contact your agent to renew.</p>
            ");
            $eOk = @sendEmail($r['customer_email'], $subj, $body);
            $eStatus = $eOk ? 'sent' : 'failed';

            $nl = $conn->prepare("INSERT INTO notification_logs (reminder_schedule_id, policy_entry_id, agent_id, customer_id, channel, recipient_contact, message_content, status) VALUES (?, ?, ?, ?, 'email', ?, ?, ?)");
            $nl->bind_param('iiiisss', $rsId, $pid, $aid, $cid, $r['customer_email'], $text, $eStatus);
            $nl->execute();
            $nl->close();
            $emailSent = true;
        }

        // Mark schedule record
        $finalStatus = ($waSent || $emailSent) ? 'sent' : 'failed';
        $conn->query("UPDATE reminder_schedules SET status = '{$finalStatus}', sent_at = NOW() WHERE id = {$rsId}");
        $processed++;
    }

    return $processed;
}

// once a day — cron_expiry.php, or the first page load of the day (sidebar.php)
function dailyJobsDue() {
    return ($_SESSION['dj_day'] ?? '') !== date('Y-m-d');
}
function runDailyJobs($force = false) {
    $today = date('Y-m-d');
    if (!$force && !dailyJobsDue()) return null;
    $_SESSION['dj_day'] = $today;
    try {
        $conn = getDBConnection();
        $conn->query("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('daily_jobs_date', '')");
        $st = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'daily_jobs_date' AND setting_value <> ?");
        $st->bind_param('ss', $today, $today);
        $st->execute();
        $claimed = $st->affected_rows === 1; // one request per day wins
        $st->close();
        if (!$claimed && !$force) { $conn->close(); return null; }
        $out = runExpiryCheck($conn);
        $out['backup'] = autoBackupIfDue($conn);
        $conn->close();
        return $out;
    } catch (Throwable $e) {
        error_log("Daily jobs error: " . $e->getMessage());
        return null;
    }
}

// sql dump, table by table (unbuffered) — the download and the weekly copy share it
function dbDump($conn, callable $w) {
    $w("-- " . getBranding()['site_name'] . " backup\n-- Database: " . DB_NAME . "\n-- Generated: " . date('c') . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
    foreach (array_column($conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetch_all(), 0) as $t) {
        $w("DROP TABLE IF EXISTS `$t`;\n" . $conn->query("SHOW CREATE TABLE `$t`")->fetch_row()[1] . ";\n\n");
        $res = $conn->query("SELECT * FROM `$t`", MYSQLI_USE_RESULT);
        $cols = implode(', ', array_map(fn($f) => "`$f->name`", $res->fetch_fields()));
        $batch = [];
        while ($r = $res->fetch_row()) {
            $batch[] = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'", $r)) . ')';
            if (count($batch) === 200) { $w("INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n"); $batch = []; }
        }
        if ($batch) $w("INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
        $res->free();
        $w("\n");
    }
    $w("SET FOREIGN_KEY_CHECKS=1;\n");
}

// stored backups live outside public reach (deny-all folder)
function backupDir() {
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}
function backupFiles() {
    $files = glob(backupDir() . '/backup_*.sql.gz') ?: [];
    rsort($files); // newest first (name starts with the date)
    return $files;
}

// gzip copy every 7 days, newest 8 kept
function autoBackupIfDue($conn, $force = false) {
    if (!$force && getSetting('auto_backup', '1') !== '1') return 'off';
    $last = (string)getSetting('last_backup_at', '');
    if (!$force && $last !== '' && strtotime($last) > strtotime('-7 days')) return 'not due';
    $file = backupDir() . '/backup_' . date('Y-m-d_His') . '_' . bin2hex(random_bytes(4)) . '.sql.gz';
    $gz = @gzopen($file, 'wb6');
    if (!$gz) return 'write failed';
    dbDump($conn, function ($s) use ($gz) { gzwrite($gz, $s); });
    gzclose($gz);
    setSetting('last_backup_at', date('Y-m-d H:i:s'));
    foreach (array_slice(backupFiles(), 8) as $old) @unlink($old);
    return basename($file);
}

// absolute link for emails — Settings › Site URL, never the request's Host header
function appUrl($path = '') {
    $base = rtrim(trim((string)getSetting('site_url', '')), '/');
    return $base === '' ? '' : $base . '/' . ltrim($path, '/');
}

// smtp mail, no library. settings: smtp_enabled / host / port / encryption / username / password / from_email / from_name
function sendEmail($to, $subject, $html) {
    $c = getSettings(['smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name']);
    if (($c['smtp_enabled'] ?? '0') !== '1') return ['success' => false, 'message' => 'Email is turned off in Settings'];
    $host = trim((string)$c['smtp_host']);
    $user = (string)$c['smtp_username'];
    $from = trim((string)$c['smtp_from_email']) ?: $user;
    if ($host === '' || $user === '' || (string)$c['smtp_password'] === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'message' => 'Email settings are incomplete'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'message' => 'Invalid recipient address'];
    $enc = in_array($c['smtp_encryption'], ['ssl', 'tls', 'none'], true) ? $c['smtp_encryption'] : 'tls';
    $port = (int)$c['smtp_port'] ?: ($enc === 'ssl' ? 465 : 587);
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
    $fp = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errNo, $errStr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return ['success' => false, 'message' => "Could not reach $host:$port" . ($errStr ? " ($errStr)" : '')];
    stream_set_timeout($fp, 20);
    $cmd = function ($line, $ok) use ($fp) {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $r = '';
        while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (!isset($l[3]) || $l[3] !== '-') break; }
        if (!in_array((int)substr($r, 0, 3), (array)$ok, true)) throw new RuntimeException(trim($r) ?: 'no reply from server');
        return $r;
    };
    $helo = preg_replace('/[^A-Za-z0-9.-]/', '', $_SERVER['SERVER_NAME'] ?? '') ?: 'localhost';
    try {
        $cmd(null, 220);
        $cmd("EHLO $helo", 250);
        if ($enc === 'tls') {
            $cmd('STARTTLS', 220);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('secure connection (TLS) failed');
            $cmd("EHLO $helo", 250);
        }
        $cmd('AUTH LOGIN', 334);
        $cmd(base64_encode($user), 334);
        $cmd(base64_encode((string)$c['smtp_password']), 235);
        $cmd("MAIL FROM:<$from>", 250);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', 354);
        $name = trim((string)$c['smtp_from_name']) ?: getBranding()['site_name'];
        $head = "Date: " . date('r') . "\r\nFrom: =?UTF-8?B?" . base64_encode($name) . "?= <$from>\r\nTo: <$to>\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
              . "Message-ID: <" . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@'), 1) . ">\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $cmd($head . rtrim(chunk_split(base64_encode($html), 76, "\r\n")) . "\r\n.", 250);
        fwrite($fp, "QUIT\r\n");
        return ['success' => true, 'message' => 'Email sent'];
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'Mail server: ' . $e->getMessage()];
    } finally {
        fclose($fp);
    }
}

// plain branded email shell (inline css — mail clients drop <style>)
function emailLayout($title, $bodyHtml) {
    $b = getBranding();
    return '<div style="font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#1f2937">'
         . '<p style="font-size:18px;font-weight:bold;margin:0 0 16px">' . htmlspecialchars($b['site_name']) . '</p>'
         . '<p style="font-size:16px;font-weight:bold;margin:0 0 12px">' . htmlspecialchars($title) . '</p>' . $bodyHtml
         . '<p style="font-size:12px;color:#6b7280;margin-top:24px">This is an automated message from ' . htmlspecialchars($b['site_name']) . '.</p></div>';
}

// whatsapp click-to-chat link for a policy — renewal or dues reminder, text from Settings
function waNumber($raw) {
    $d = ltrim(preg_replace('/\D/', '', (string)$raw), '0');
    if ($d === '') return '';
    $cc = preg_replace('/\D/', '', (string)getSetting('wa_country_code', '91')) ?: '91';
    return strlen($d) <= 10 ? $cc . $d : $d;
}
function waDefaultText($kind) {
    return $kind === 'due'
        ? "Dear {customer}, a balance of {due} is pending on your motor policy {policy_no} ({vehicle}). Kindly clear it at the earliest. Thank you, {business}"
        : "Dear {customer}, your {company} motor policy {policy_no} for vehicle {vehicle} expires on {expiry_date}. Renew on time to keep your cover and No Claim Bonus. Reply here or call us to renew. Thank you, {business}";
}
function waPolicyLink($conn, $pid, $kind = 'renewal') {
    $pid = (int)$pid;
    $st = $conn->prepare("SELECT pe.policy_number, pe.customer_name, pe.vehicle_number, pe.company_name, pe.policy_end_date, pe.total_premium_amount, pe.payment_in, pe.payment_out, pe.agent_name,
                                 c.whatsapp_number, c.mobile
                          FROM policy_entries pe LEFT JOIN customers c ON c.id = pe.customer_id WHERE pe.id = ?");
    $st->bind_param('i', $pid);
    $st->execute();
    $p = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$p) return null;
    $sym = getBranding()['currency_symbol'];
    $money = fn($v) => $sym . number_format((float)$v, 2);
    $tpl = trim((string)getSetting($kind === 'due' ? 'wa_due_template' : 'wa_renewal_template', '')) ?: waDefaultText($kind);
    $txt = strtr($tpl, [
        '{customer}' => $p['customer_name'], '{vehicle}' => $p['vehicle_number'], '{policy_no}' => $p['policy_number'],
        '{company}' => $p['company_name'], '{expiry_date}' => date('d M Y', strtotime($p['policy_end_date'])),
        '{days_left}' => (string)max(0, (int)round((strtotime($p['policy_end_date']) - strtotime(date('Y-m-d'))) / 86400)),
        '{premium}' => $money($p['total_premium_amount']), '{due}' => $money(max(0, $p['payment_out'] - $p['payment_in'])),
        '{agent}' => (string)$p['agent_name'], '{business}' => getBranding()['site_name'],
    ]);
    $num = waNumber($p['whatsapp_number'] ?: $p['mobile']);
    return ['url' => 'https://wa.me/' . $num . '?text=' . rawurlencode($txt), 'number' => $num, 'policy' => $p['policy_number']];
}

// site branding helper — cached per request
function getBranding() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = [
        'site_name' => 'Insurance Management System',
        'site_logo' => 'uploads/logos/site_logo.png',
        'currency_symbol' => '₹',
        'site_footer' => ''
    ];

    try {
        $logo = getSetting('site_logo', $defaults['site_logo']);
        if (empty($logo) || strpos($logo, 'channels4_profile') !== false || strpos($logo, 'blogger.googleusercontent.com') !== false) {
            $logo = $defaults['site_logo'];
        }

        $cache = [
            'site_name' => getSetting('site_name', $defaults['site_name']),
            'site_logo' => $logo,
            'currency_symbol' => getSetting('currency_symbol', $defaults['currency_symbol']),
            'site_footer' => getSetting('site_footer', $defaults['site_footer'])
        ];
    } catch (Exception $e) {
        $cache = $defaults;
    }
    return $cache;
}

// searchable dropdown vanilla JS helper
function getSearchableDropdownJS() {
    return '<script>
function initSearchableDropdown(selectEl, opts) {
    if (!selectEl || selectEl.dataset.sdInit) return;
    selectEl.dataset.sdInit = "1";
    selectEl.style.display = "none";

    var wrap = document.createElement("div");
    wrap.className = "searchable-dropdown";

    var input = document.createElement("input");
    input.type = "text";
    input.className = "searchable-dropdown-input";
    input.placeholder = selectEl.options[0] ? selectEl.options[0].text : "Select...";
    input.autocomplete = "off";

    var arrow = document.createElement("span");
    arrow.className = "searchable-dropdown-arrow";
    arrow.innerHTML = \'<i class="fas fa-chevron-down"></i>\';

    var list = document.createElement("div");
    list.className = "searchable-dropdown-list";

    wrap.appendChild(input);
    wrap.appendChild(arrow);
    wrap.appendChild(list);
    selectEl.parentNode.insertBefore(wrap, selectEl);

    var items = [];

    function buildItems() {
        items = [];
        list.innerHTML = "";
        for (var i = 0; i < selectEl.options.length; i++) {
            var o = selectEl.options[i];
            var div = document.createElement("div");
            div.className = "searchable-dropdown-item";
            div.textContent = o.text;
            div.dataset.val = o.value;
            div.dataset.idx = i;
            items.push({ el: div, text: o.text.toLowerCase(), val: o.value });
            list.appendChild(div);
        }
    }

    function setVal(val, txt) {
        selectEl.value = val;
        input.value = val ? txt : "";
        list.classList.remove("open");
        arrow.classList.remove("open");
        items.forEach(function(it) { it.el.classList.toggle("selected", it.val === val && val !== ""); });
        var evt = new Event("change", {bubbles: true});
        selectEl.dispatchEvent(evt);
    }

    function filterList(q) {
        var lq = q.toLowerCase();
        var found = false;
        items.forEach(function(it) {
            var show = !lq || it.text.includes(lq);
            it.el.style.display = show ? "" : "none";
            if (show) found = true;
        });
        var noRes = list.querySelector(".no-results");
        if (!found) {
            if (!noRes) {
                noRes = document.createElement("div");
                noRes.className = "searchable-dropdown-item no-results";
                noRes.textContent = "No results found";
                list.appendChild(noRes);
            }
            noRes.style.display = "";
        } else if (noRes) {
            noRes.style.display = "none";
        }
    }

    input.addEventListener("click", function(e) {
        e.stopPropagation();
        var isOpen = list.classList.contains("open");
        closeAllDropdowns();
        if (!isOpen) {
            buildItems();
            list.classList.add("open");
            arrow.classList.add("open");
            input.value = "";
            input.focus();
            filterList("");
            highlightSelected();
        }
    });

    input.addEventListener("input", function() {
        if (!list.classList.contains("open")) {
            buildItems();
            list.classList.add("open");
            arrow.classList.add("open");
        }
        filterList(this.value);
    });

    list.addEventListener("click", function(e) {
        var item = e.target.closest(".searchable-dropdown-item");
        if (!item || item.classList.contains("no-results")) return;
        setVal(item.dataset.val, item.textContent);
    });

    function highlightSelected() {
        items.forEach(function(it) { it.el.classList.toggle("selected", it.val === selectEl.value && it.val !== ""); });
    }

    // sync if select value changes programmatically
    var origVal = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, "value");
    // use MutationObserver instead for simplicity
    new MutationObserver(function() { buildItems(); syncDisplay(); }).observe(selectEl, { childList: true });

    function syncDisplay() {
        var idx = selectEl.selectedIndex;
        if (idx >= 0 && selectEl.options[idx]) {
            input.value = selectEl.value ? selectEl.options[idx].text : "";
        } else {
            input.value = "";
        }
    }

    // public method to refresh
    selectEl.sdRefresh = function() { buildItems(); syncDisplay(); };
    selectEl.sdSetVal = function(v) {
        selectEl.value = v;
        syncDisplay();
    };
    syncDisplay(); // show preselected value
}

function closeAllDropdowns() {
    document.querySelectorAll(".searchable-dropdown-list.open").forEach(function(l) { l.classList.remove("open"); });
    document.querySelectorAll(".searchable-dropdown-arrow.open").forEach(function(a) { a.classList.remove("open"); });
}

document.addEventListener("click", function(e) {
    if (!e.target.closest(".searchable-dropdown")) closeAllDropdowns();
});

// auto-init: convert all select elements inside modals with class "searchable"
function initAllSearchableDropdowns() {
    document.querySelectorAll("select.searchable").forEach(function(sel) {
        initSearchableDropdown(sel);
    });
}
</script>';
}
