<?php
/**
 * Developed by Rudees Digital
 * Website: https://rudeesdigital.com
 * Email: contact@rudeesdigital.com
 * Website: https://rudeesdigital.com
 * Email: contact@rudeesdigital.com
 * Website: https://rudeesdigital.com
  Email: contact@rudeesdigital.com
  WhatsApp: +91 8160140848
 */
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check session timeout
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'agent';
$user_id = $_SESSION['user_id'];
$current_page = 'settings';

// Only admins can access settings
if ($role !== 'admin') {
    header("Location: dashboard.php");
    exit();
}

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'getSettings') {
        try {
            $allowUserUploads = getSetting('allow_user_profile_uploads', '1');
            $siteName = getSetting('site_name', 'Insurance Management System');
            $siteLogo = getSetting('site_logo', '');
            $currencySymbol = getSetting('currency_symbol', '₹');
            $siteFooter = getSetting('site_footer', '');
            $pointsRatio = getSetting('points_to_rupees_ratio', '10');

            echo json_encode([
                'success' => true,
                'data' => [
                    'allow_user_profile_uploads' => $allowUserUploads,
                    'site_name' => $siteName,
                    'site_logo' => $siteLogo,
                    'currency_symbol' => $currencySymbol,
                    'site_footer' => $siteFooter,
                    'points_to_rupees_ratio' => $pointsRatio
                ] + array_map(fn($v) => (string)$v, getSettings(['smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_from_email', 'smtp_from_name', 'site_url',
                        'wa_country_code', 'wa_renewal_template', 'wa_due_template', 'auto_backup', 'last_backup_at', 'daily_jobs_date']))
                  + ['smtp_password_set' => getSetting('smtp_password', '') !== '' ? '1' : '0', 'wa_renewal_default' => waDefaultText('renewal'), 'wa_due_default' => waDefaultText('due')]
            ]);
            exit();
        } catch (Exception $e) {
            error_log($e->getMessage());
            echo json_encode(['success' => false, 'message' => 'A server error occurred']);
            exit();
        }
    }

    if ($_POST['action'] === 'saveSettings') {
        try {
            if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }

            $allowUserUploads = isset($_POST['allow_user_profile_uploads']) ? $_POST['allow_user_profile_uploads'] : '0';

            // Save setting
            $result = setSetting('allow_user_profile_uploads', $allowUserUploads);

            $siteName = isset($_POST['site_name']) ? trim($_POST['site_name']) : '';
            $currencySymbol = isset($_POST['currency_symbol']) ? trim($_POST['currency_symbol']) : '₹';
            $siteFooter = isset($_POST['site_footer']) ? trim($_POST['site_footer']) : '';

            if (!empty($siteName)) setSetting('site_name', $siteName);
            if (!empty($currencySymbol)) setSetting('currency_symbol', $currencySymbol);
            setSetting('site_footer', $siteFooter);

            $pointsRatio = isset($_POST['points_to_rupees_ratio']) ? trim($_POST['points_to_rupees_ratio']) : '10';
            if (is_numeric($pointsRatio) && $pointsRatio > 0) setSetting('points_to_rupees_ratio', $pointsRatio);

            // email (smtp) + reset-link url + whatsapp + backups — validated before anything is written
            $p = fn($k) => trim((string)($_POST[$k] ?? ''));
            $siteUrl = rtrim($p('site_url'), '/');
            $port = $p('smtp_port');
            $enc = $p('smtp_encryption');
            $from = $p('smtp_from_email');
            $cc = $p('wa_country_code');
            $err = $siteUrl !== '' && (!filter_var($siteUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $siteUrl)) ? 'Site URL must start with http:// or https://'
                : ($port !== '' && (!ctype_digit($port) || $port < 1 || $port > 65535) ? 'SMTP port must be 1-65535'
                : ($enc !== '' && !in_array($enc, ['tls', 'ssl', 'none'], true) ? 'Pick an encryption type'
                : ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL) ? 'From email is not valid'
                : ($cc !== '' && !preg_match('/^\d{1,4}$/', $cc) ? 'Country code = 1-4 digits, no +'
                : (mb_strlen($p('wa_renewal_template')) > 1000 || mb_strlen($p('wa_due_template')) > 1000 ? 'Message templates max 1000 characters' : null)))));
            if ($err) { echo json_encode(['success' => false, 'message' => $err]); exit(); }
            foreach (['smtp_host', 'smtp_username', 'smtp_from_name', 'wa_renewal_template', 'wa_due_template'] as $k) setSetting($k, $p($k));
            setSetting('site_url', $siteUrl);
            setSetting('smtp_port', $port ?: '587');
            setSetting('smtp_encryption', $enc ?: 'tls');
            setSetting('smtp_from_email', $from);
            setSetting('wa_country_code', $cc ?: '91');
            setSetting('smtp_enabled', $p('smtp_enabled') === '1' ? '1' : '0');
            setSetting('auto_backup', $p('auto_backup') === '1' ? '1' : '0');
            if ($p('smtp_password') !== '') setSetting('smtp_password', (string)$_POST['smtp_password']); // blank = keep the saved one

            if ($result) {
                // Log activity
                $settingText = $allowUserUploads === '1' ? 'enabled' : 'disabled';
                logActivity($user_id, $username, 'Settings Updated', "User profile uploads $settingText");

                echo json_encode([
                    'success' => true,
                    'message' => 'Settings saved successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to save settings'
                ]);
            }
            exit();
        } catch (Exception $e) {
            error_log($e->getMessage());
            echo json_encode(['success' => false, 'message' => 'A server error occurred']);
            exit();
        }
    }

    // test mail to the logged-in admin's address (save settings first)
    if ($_POST['action'] === 'testEmail') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'Invalid request']); exit(); }
        $to = trim($_POST['to'] ?? '') ?: ($_SESSION['email'] ?? '');
        $r = sendEmail($to, 'Test email from ' . getBranding()['site_name'], emailLayout('Email is working', '<p>This test was sent from Settings. Password reset emails will arrive the same way.</p>'));
        logActivity($user_id, $username, 'Settings Updated', 'Test email to ' . $to . ': ' . ($r['success'] ? 'sent' : 'failed'));
        echo json_encode($r + ['to' => $to]);
        exit();
    }

    // backups: list / save a copy now / download (fresh or stored) / delete a stored copy
    if ($_POST['action'] === 'listBackups') {
        echo json_encode(['success' => true, 'last' => getSetting('last_backup_at', ''), 'data' => array_map(fn($f) => ['name' => basename($f), 'size_kb' => round(filesize($f) / 1024, 1), 'at' => date('Y-m-d H:i', filemtime($f))], backupFiles())]);
        exit();
    }
    if (in_array($_POST['action'], ['backupNow', 'downloadBackup', 'getBackup', 'deleteBackup'], true)) {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'Invalid request']); exit(); }
        $act = $_POST['action'];
        $name = basename((string)($_POST['name'] ?? ''));
        $file = backupDir() . '/' . $name;
        if (in_array($act, ['getBackup', 'deleteBackup'], true) && (!preg_match('/^backup_[0-9_-]+_[a-f0-9]{8}\.sql\.gz$/', $name) || !is_file($file))) {
            echo json_encode(['success' => false, 'message' => 'Backup not found']); exit();
        }
        if ($act === 'backupNow') {
            $conn = getDBConnection();
            $res = autoBackupIfDue($conn, true);
            $conn->close();
            $ok = str_starts_with($res, 'backup_');
            logActivity($user_id, $username, 'Backup', $ok ? "Server copy saved: $res" : "Server copy failed: $res");
            echo json_encode(['success' => $ok, 'message' => $ok ? 'Backup saved on the server' : 'Could not write the backup folder']);
            exit();
        }
        if ($act === 'deleteBackup') {
            @unlink($file);
            logActivity($user_id, $username, 'Backup', "Deleted server copy $name");
            echo json_encode(['success' => true, 'message' => 'Backup deleted']);
            exit();
        }
        // downloads: raw gzip stream, no json
        while (ob_get_level()) ob_end_clean();
        header_remove('Content-Type');
        header('Content-Type: application/gzip');
        header('Cache-Control: private, no-store');
        if ($act === 'getBackup') {
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($file));
            logActivity($user_id, $username, 'Backup', "Downloaded server copy $name");
            readfile($file);
            exit();
        }
        @set_time_limit(300);
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '_', DB_NAME) . '_' . date('Y-m-d_His') . '.sql.gz"');
        logActivity($user_id, $username, 'Backup', 'Downloaded a full database backup');
        $conn = getDBConnection();
        $z = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
        dbDump($conn, function ($chunk) use ($z) { echo deflate_add($z, $chunk, ZLIB_NO_FLUSH); });
        echo deflate_add($z, '', ZLIB_FINISH);
        $conn->close();
        exit();
    }

    if ($_POST['action'] === 'uploadLogo') {
        try {
            if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }

            if (!isset($_FILES['logo_file']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'No file uploaded']);
                exit();
            }

            $file = $_FILES['logo_file'];
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $maxSize = 2 * 1024 * 1024; // 2MB

            if ($file['size'] > $maxSize) {
                echo json_encode(['success' => false, 'message' => 'File must be under 2MB']);
                exit();
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, $allowed)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Use JPG, PNG, GIF or WEBP']);
                exit();
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file extension']);
                exit();
            }
            $filename = 'site_logo_' . time() . '.' . $ext;
            $uploadDir = __DIR__ . '/uploads/';

            if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

            $filepath = $uploadDir . $filename;
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                // delete old logo file if it's a local file
                $oldLogo = getSetting('site_logo', '');
                if ($oldLogo && strpos($oldLogo, 'uploads/') === 0 && file_exists(__DIR__ . '/' . $oldLogo)) {
                    unlink(__DIR__ . '/' . $oldLogo);
                }

                $logoPath = 'uploads/' . $filename;
                setSetting('site_logo', $logoPath);
                logActivity($user_id, $username, 'Logo Updated', 'Site logo changed');

                echo json_encode(['success' => true, 'message' => 'Logo uploaded', 'logo_url' => $logoPath]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Upload failed']);
            }
            exit();
        } catch (Exception $e) {
            error_log($e->getMessage());
            echo json_encode(['success' => false, 'message' => 'A server error occurred']);
            exit();
        }
    }
}
?>
<!--
  Developed by Rudees Digital
  Website: https://rudeesdigital.com
  Email: contact@rudeesdigital.com
  Website: https://rudeesdigital.com
  Email: contact@rudeesdigital.com
  WhatsApp: +91 8160140848
-->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="icon" href="<?php echo htmlspecialchars(getBranding()['site_logo']); ?>"><!-- tab icon, stops the browser asking for /favicon.ico -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>System Settings - Dashboard System</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.1/dist/css/tabulator.min.css">
    <link rel="stylesheet" href="styles.css?v=16.0">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.1/dist/js/tabulator.min.js"></script>
    <script src="app.js?v=2.3"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<script>/* theme before paint */(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme:dark)').matches))document.body.classList.add('dark-mode');})();</script>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <?php
            $pageIcon    = 'fa-cog';
            $pageTitle   = 'System Settings';
            $crumbs      = [['label' => 'System', 'icon' => 'fa-server']];
            $pageActions = [
                // save only once the form is loaded
                ['label' => 'Save Settings', 'icon' => 'fa-save', 'onclick' => "if ($('#settingsContent').is(':visible')) saveSettings()", 'class' => 'primary'],
                ['label' => 'Reload', 'icon' => 'fa-rotate', 'onclick' => 'loadSettings()'],
            ];
            include 'page_header.php';
            ?>

            <!-- Loading Skeleton -->
            <div id="loadingSkeleton">
                <div class="skeleton-card" style="margin-bottom: 20px;">
                    <div class="skeleton skeleton-text-large" style="width: 60%; margin-bottom: 16px;"></div>
                    <div class="skeleton skeleton-text" style="width: 80%; margin-bottom: 12px;"></div>
                    <div class="skeleton skeleton-text" style="width: 70%;"></div>
                </div>
                <div class="dashboard-grid-4" style="margin-bottom: 30px;">
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon" style="margin-bottom: 16px;"></div>
                        <div class="skeleton skeleton-text" style="width: 60%;"></div>
                    </div>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon" style="margin-bottom: 16px;"></div>
                        <div class="skeleton skeleton-text" style="width: 60%;"></div>
                    </div>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon" style="margin-bottom: 16px;"></div>
                        <div class="skeleton skeleton-text" style="width: 60%;"></div>
                    </div>
                    <div class="skeleton-card">
                        <div class="skeleton skeleton-icon" style="margin-bottom: 16px;"></div>
                        <div class="skeleton skeleton-text" style="width: 60%;"></div>
                    </div>
                </div>
            </div>

            <!-- Settings Content -->
            <div id="settingsContent" style="display: none;">
                <!-- 2x2 Grid Layout -->
                <div class="settings-grid-2x2">

                    <!-- Card: Site Branding -->
                    <div class="settings-mega-card" style="grid-column: 1 / -1;">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, #e91e63 0%, #c2185b 100%);">
                                <i class="fas fa-paint-brush"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Site Branding</h3>
                                <p class="settings-card-subtitle">Customize your site name, logo & currency</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label><i class="fas fa-heading"></i> Site Name</label>
                                    <input type="text" id="siteName" placeholder="Insurance Management System" maxlength="150">
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-coins"></i> Currency Symbol</label>
                                    <input type="text" id="currencySymbol" placeholder="₹" maxlength="5">
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label><i class="fas fa-pen"></i> Footer Text</label>
                                    <input type="text" id="siteFooter" placeholder="© 2025 Your Company" maxlength="255">
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-star"></i> Points-to-Rupees Ratio</label>
                                    <input type="number" id="pointsRatio" placeholder="10" min="1" step="1">
                                    <small class="help-text">e.g. 10 = every 10 points = ₹1</small>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-image"></i> Site Logo</label>
                                <div class="control-group">
                                    <div class="control-group-header">
                                        <img id="logoPreview" src="" alt="Logo" class="sidebar-logo">
                                        <div class="control-info">
                                            <div class="control-title" id="logoFileName">Current Logo</div>
                                            <div class="control-desc">Max 2MB. JPG, PNG, GIF, WEBP</div>
                                        </div>
                                    </div>
                                    <div>
                                        <input type="file" id="logoFile" accept="image/*" hidden>
                                        <button class="btn btn-primary btn-sm" onclick="document.getElementById('logoFile').click()">
                                            <i class="fas fa-upload"></i> Upload Logo
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="info-banner">
                                <i class="fas fa-info-circle"></i>
                                <span>These settings apply across all pages including login screen, sidebar, and print templates.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Card: Email (SMTP) — password reset + test mail -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon sci-solid"><i class="fas fa-envelope"></i></div>
                            <div>
                                <h3 class="settings-card-title">Email (SMTP)</h3>
                                <p class="settings-card-subtitle">Password reset emails</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="control-group">
                                <div class="control-group-header">
                                    <div class="control-icon"><i class="fas fa-paper-plane"></i></div>
                                    <div class="control-info">
                                        <div class="control-title">Send emails</div>
                                        <div class="control-desc">Shows "Forgot password?" on the login page</div>
                                    </div>
                                </div>
                                <div class="control-toggle-wrapper"><input type="checkbox" id="smtpEnabled" class="toggle" aria-label="Send emails"></div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group"><label for="smtpHost"><i class="fas fa-server"></i> SMTP Host</label><input type="text" id="smtpHost" placeholder="smtp.hostinger.com" maxlength="150" autocomplete="off"></div>
                                <div class="form-group"><label for="smtpPort"><i class="fas fa-plug"></i> Port</label><input type="number" id="smtpPort" placeholder="587" min="1" max="65535"></div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group"><label for="smtpEncryption"><i class="fas fa-lock"></i> Encryption</label>
                                    <select id="smtpEncryption" class="searchable"><option value="tls">TLS (port 587)</option><option value="ssl">SSL (port 465)</option><option value="none">None</option></select></div>
                                <div class="form-group"><label for="smtpUser"><i class="fas fa-user"></i> Username</label><input type="text" id="smtpUser" maxlength="150" autocomplete="off"></div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group"><label for="smtpPass"><i class="fas fa-key"></i> Password</label><input type="password" id="smtpPass" maxlength="255" autocomplete="new-password"></div>
                                <div class="form-group"><label for="smtpFrom"><i class="fas fa-at"></i> From Email</label><input type="email" id="smtpFrom" maxlength="150" placeholder="no-reply@yourdomain.com"></div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group"><label for="smtpFromName"><i class="fas fa-signature"></i> From Name</label><input type="text" id="smtpFromName" maxlength="100"></div>
                                <div class="form-group"><label for="siteUrl"><i class="fas fa-link"></i> Site URL</label><input type="url" id="siteUrl" maxlength="255" placeholder="https://yourdomain.com/insurance"></div>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" id="testMailBtn" onclick="testEmail()"><i class="fas fa-paper-plane"></i> Send Test Email</button>
                        </div>
                    </div>

                    <!-- Card: WhatsApp reminder texts -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon sci-solid sci-success"><i class="fab fa-whatsapp"></i></div>
                            <div>
                                <h3 class="settings-card-title">WhatsApp Reminders</h3>
                                <p class="settings-card-subtitle">Renewal and dues messages</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="form-group"><label for="waCc"><i class="fas fa-globe"></i> Country Code</label><input type="text" id="waCc" maxlength="4" inputmode="numeric" placeholder="91"></div>
                            <div class="form-group"><label for="waRenewal"><i class="fas fa-rotate"></i> Renewal Message</label><textarea id="waRenewal" rows="4" maxlength="1000"></textarea></div>
                            <div class="form-group"><label for="waDue"><i class="fas fa-hand-holding-dollar"></i> Dues Message</label><textarea id="waDue" rows="3" maxlength="1000"></textarea>
                                <small class="help-text">{customer} {vehicle} {policy_no} {company} {expiry_date} {days_left} {premium} {due} {agent} {business}</small></div>
                        </div>
                    </div>

                    <!-- Card: Backups -->
                    <div class="settings-mega-card settings-card-wide">
                        <div class="settings-card-header">
                            <div class="settings-card-icon sci-solid"><i class="fas fa-database"></i></div>
                            <div>
                                <h3 class="settings-card-title">Backups</h3>
                                <p class="settings-card-subtitle" id="bkLast">Full database copies</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="bk-actions">
                                <button type="button" class="btn btn-primary btn-sm" onclick="downloadBackup()"><i class="fas fa-download"></i> Download Backup</button>
                                <button type="button" class="btn btn-secondary btn-sm" id="bkNowBtn" onclick="backupNow()"><i class="fas fa-server"></i> Save Copy on Server</button>
                                <div class="toggle-switch"><input type="checkbox" id="autoBackup" class="toggle"><label for="autoBackup">Weekly automatic copy (newest 8 kept)</label></div>
                            </div>
                            <div class="tab-table" id="bkTable"></div>
                            <form id="bkForm" method="post" hidden>
                                <input type="hidden" name="action"><input type="hidden" name="name"><input type="hidden" name="csrf_token">
                            </form>
                        </div>
                    </div>

                    <!-- Card 1: System Overview -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, var(--navy-primary) 0%, var(--navy-light) 100%);">
                                <i class="fas fa-server"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">System Overview</h3>
                                <p class="settings-card-subtitle">Core system configuration</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="stat-item-inline">
                                <div class="stat-item-icon" style="background: rgba(52, 168, 83, 0.1); color: var(--success);">
                                    <i class="fas fa-database"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Database</div>
                                    <div class="stat-item-value"><?php echo DB_NAME; ?></div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon" style="background: rgba(0, 116, 217, 0.1); color: var(--navy-accent);">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Session Timeout</div>
                                    <div class="stat-item-value"><?php echo SESSION_TIMEOUT / 60; ?> minutes</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon" style="background: rgba(251, 188, 4, 0.1); color: var(--warning);">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Max Login Attempts</div>
                                    <div class="stat-item-value"><?php echo MAX_LOGIN_ATTEMPTS; ?> attempts</div>
                                </div>
                            </div>
                            <div class="stat-item-inline">
                                <div class="stat-item-icon" style="background: rgba(234, 67, 53, 0.1); color: var(--danger);">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <div class="stat-item-content">
                                    <div class="stat-item-label">Lockout Duration</div>
                                    <div class="stat-item-value"><?php echo LOGIN_LOCKOUT_TIME / 60; ?> minutes</div>
                                </div>
                            </div>
                            <div class="control-group" style="margin-top: 16px;">
                                <div class="control-group-header">
                                    <div class="control-icon">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <div class="control-info">
                                        <div class="control-title">Daily Jobs</div>
                                        <div class="control-desc" id="djLast">Expiry alerts, expired status, weekly backup</div>
                                    </div>
                                </div>
                                <div>
                                    <button class="btn btn-primary btn-sm" onclick="runExpiryCheck()">
                                        <i class="fas fa-sync"></i> Run Now
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card 2: User Management -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, #34a853 0%, #2d9148 100%);">
                                <i class="fas fa-users-cog"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">User Management</h3>
                                <p class="settings-card-subtitle">Control user permissions</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="control-group">
                                <div class="control-group-header">
                                    <div class="control-icon">
                                        <i class="fas fa-image"></i>
                                    </div>
                                    <div class="control-info">
                                        <div class="control-title">Profile Image Uploads</div>
                                        <div class="control-desc">Allow users to upload profile pictures</div>
                                    </div>
                                </div>
                                <div class="control-toggle-wrapper">
                                    <input type="checkbox" id="allowUserUploads" class="toggle">
                                    <div class="toggle-status" id="uploadToggleStatus">
                                        <span class="status-dot status-disabled"></span>
                                        <span class="status-text">Disabled</span>
                                    </div>
                                </div>
                            </div>
                            <div class="info-banner">
                                <i class="fas fa-info-circle"></i>
                                <span>When disabled, only administrators can manage all profile images</span>
                            </div>
                        </div>
                    </div>

                    <!-- Card 3: Security Status -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, #0074D9 0%, #0056a8 100%);">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Security Status</h3>
                                <p class="settings-card-subtitle">Active security features</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Session Security</div>
                                    <div class="security-feature-desc">HTTPOnly cookies & Strict SameSite</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="fas fa-shield-alt"></i> Active
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Password Encryption</div>
                                    <div class="security-feature-desc">Bcrypt (PASSWORD_DEFAULT)</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="fas fa-shield-alt"></i> Active
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fas fa-user-clock"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">Rate Limiting</div>
                                    <div class="security-feature-desc">User & IP tracking enabled</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="fas fa-shield-alt"></i> Active
                                </div>
                            </div>
                            <div class="security-feature">
                                <div class="security-feature-icon">
                                    <i class="fas fa-code"></i>
                                </div>
                                <div class="security-feature-content">
                                    <div class="security-feature-name">CSRF Protection</div>
                                    <div class="security-feature-desc">Token-based validation</div>
                                </div>
                                <div class="security-feature-badge active">
                                    <i class="fas fa-shield-alt"></i> Active
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card 4: Server Information -->
                    <div class="settings-mega-card">
                        <div class="settings-card-header">
                            <div class="settings-card-icon" style="background: linear-gradient(135deg, #fbbc04 0%, #e0a800 100%);">
                                <i class="fas fa-server"></i>
                            </div>
                            <div>
                                <h3 class="settings-card-title">Server Information</h3>
                                <p class="settings-card-subtitle">Environment details</p>
                            </div>
                        </div>
                        <div class="settings-card-body">
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="fas fa-database"></i>
                                    <span>Database Host</span>
                                </div>
                                <div class="server-info-value"><?php echo DB_HOST; ?></div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="fas fa-user"></i>
                                    <span>Database User</span>
                                </div>
                                <div class="server-info-value"><?php echo DB_USER; ?></div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="fas fa-code"></i>
                                    <span>PHP Version</span>
                                </div>
                                <div class="server-info-value">
                                    <span class="version-badge"><?php echo phpversion(); ?></span>
                                </div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="fas fa-hdd"></i>
                                    <span>Max Upload</span>
                                </div>
                                <div class="server-info-value">
                                    <span class="upload-badge"><?php echo ini_get('upload_max_filesize'); ?></span>
                                </div>
                            </div>
                            <div class="server-info-item">
                                <div class="server-info-label">
                                    <i class="fas fa-clock"></i>
                                    <span>Server Time</span>
                                </div>
                                <div class="server-info-value time-value"><?php echo date('Y-m-d H:i:s'); ?></div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <?php echo getSearchableDropdownJS(); ?>
    <script>
        $(document).ready(function() {
            initAllSearchableDropdowns();
            loadSettings();
        });

        function loadSettings() {
            // Show skeleton, hide content
            $('#loadingSkeleton').show();
            $('#settingsContent').hide();

            $.ajax({
                url: '',
                method: 'POST',
                data: { action: 'getSettings' },
                dataType: 'json',
                success: function(response) {
                    // Hide skeleton, show content
                    setTimeout(() => {
                        $('#loadingSkeleton').hide();
                        $('#settingsContent').fadeIn(300);
                    }, 500);

                    if (response.success) {
                        const data = response.data;

                        // Set toggle states
                        const isEnabled = data.allow_user_profile_uploads === '1';
                        document.getElementById('allowUserUploads').checked = isEnabled;
                        updateToggleStatus(isEnabled);

                        // branding
                        document.getElementById('siteName').value = data.site_name || '';
                        document.getElementById('currencySymbol').value = data.currency_symbol || '₹';
                        document.getElementById('siteFooter').value = data.site_footer || '';
                        document.getElementById('pointsRatio').value = data.points_to_rupees_ratio || '10';
                        // email, whatsapp, backups
                        document.getElementById('smtpEnabled').checked = data.smtp_enabled === '1';
                        $('#smtpHost').val(data.smtp_host); $('#smtpPort').val(data.smtp_port || '587');
                        var enc = document.getElementById('smtpEncryption'); enc.sdSetVal ? enc.sdSetVal(data.smtp_encryption || 'tls') : (enc.value = data.smtp_encryption || 'tls');
                        $('#smtpUser').val(data.smtp_username); $('#smtpFrom').val(data.smtp_from_email); $('#smtpFromName').val(data.smtp_from_name);
                        $('#smtpPass').val('').attr('placeholder', data.smtp_password_set === '1' ? 'Saved — leave blank to keep' : '');
                        $('#siteUrl').val(data.site_url || '').attr('placeholder', location.origin + location.pathname.replace(/\/[^\/]*$/, ''));
                        $('#waCc').val(data.wa_country_code || '91');
                        $('#waRenewal').val(data.wa_renewal_template).attr('placeholder', data.wa_renewal_default);
                        $('#waDue').val(data.wa_due_template).attr('placeholder', data.wa_due_default);
                        document.getElementById('autoBackup').checked = data.auto_backup !== '0';
                        $('#djLast').text(data.daily_jobs_date ? 'Last ran ' + data.daily_jobs_date + ' · expiry alerts, expired status, weekly backup' : 'Expiry alerts, expired status, weekly backup');
                        loadBackups();
                        var logoSrc = data.site_logo || '';
                        document.getElementById('logoPreview').src = logoSrc;
                        document.getElementById('logoFileName').textContent = logoSrc ? 'Current Logo' : 'No logo set';
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load settings'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    $('#loadingSkeleton').hide();
                    $('#settingsContent').show();

                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not connect to server. Please check console for details.'
                    });
                }
            });
        }

        function saveSettings() {
            const allowUserUploads = document.getElementById('allowUserUploads').checked ? '1' : '0';

            showLoading('Saving Settings...');

            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'saveSettings',
                    csrf_token: window.CSRF_TOKEN,
                    allow_user_profile_uploads: allowUserUploads,
                    site_name: document.getElementById('siteName').value,
                    currency_symbol: document.getElementById('currencySymbol').value,
                    site_footer: document.getElementById('siteFooter').value,
                    points_to_rupees_ratio: document.getElementById('pointsRatio').value,
                    smtp_enabled: document.getElementById('smtpEnabled').checked ? '1' : '0',
                    smtp_host: $('#smtpHost').val(), smtp_port: $('#smtpPort').val(), smtp_encryption: $('#smtpEncryption').val(),
                    smtp_username: $('#smtpUser').val(), smtp_password: $('#smtpPass').val(), smtp_from_email: $('#smtpFrom').val(),
                    smtp_from_name: $('#smtpFromName').val(), site_url: $('#siteUrl').val(),
                    wa_country_code: $('#waCc').val(), wa_renewal_template: $('#waRenewal').val(), wa_due_template: $('#waDue').val(),
                    auto_backup: document.getElementById('autoBackup').checked ? '1' : '0'
                },
                dataType: 'json',
                success: function(response) {
                    hideLoading();
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    console.error('AJAX Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to save settings. Please try again.'
                    });
                }
            });
        }

        function updateToggleStatus(isEnabled) {
            const statusElement = document.getElementById('uploadToggleStatus');
            const statusDot = statusElement.querySelector('.status-dot');
            const statusText = statusElement.querySelector('.status-text');

            if (isEnabled) {
                statusDot.classList.remove('status-disabled');
                statusDot.classList.add('status-enabled');
                statusText.textContent = 'Enabled';
                statusText.style.color = 'var(--success)';
            } else {
                statusDot.classList.remove('status-enabled');
                statusDot.classList.add('status-disabled');
                statusText.textContent = 'Disabled';
                statusText.style.color = 'var(--text-muted)';
            }
        }

        // Add event listener to toggle
        $(document).on('change', '#allowUserUploads', function() {
            updateToggleStatus(this.checked);
        });

        // logo upload
        document.getElementById('logoFile').addEventListener('change', function() {
            if (!this.files[0]) return;
            if (this.files[0].size > 2 * 1024 * 1024) {
                Swal.fire('Error', 'File must be under 2MB', 'error');
                this.value = '';
                return;
            }

            var fd = new FormData();
            fd.append('action', 'uploadLogo');
            fd.append('logo_file', this.files[0]);
            fd.append('csrf_token', window.CSRF_TOKEN);

            showLoading('Uploading...');

            $.ajax({
                url: '', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
                success: function(res) {
                    hideLoading();
                    if (res.success) {
                        document.getElementById('logoPreview').src = res.logo_url;
                        document.getElementById('logoFileName').textContent = 'Logo updated!';
                        Swal.fire({ icon: 'success', title: 'Logo Updated!', timer: 2000, showConfirmButton: false });
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                },
                error: function() { hideLoading(); Swal.fire('Error', 'Upload failed', 'error'); }
            });
            this.value = '';
        });

        // test mail -> the logged-in admin (saves nothing; save settings first)
        function testEmail() {
            var btn = document.getElementById('testMailBtn'), ico = btn.querySelector('i');
            btn.disabled = true; ico.className = 'fas fa-spinner fa-spin';
            var done = rsOverlay('Sending…');
            $.post('', { action: 'testEmail', csrf_token: window.CSRF_TOKEN }, null, 'json')
                .done(function(r) { Swal.fire(r.success ? { icon: 'success', title: 'Email sent', text: 'Check the inbox of ' + r.to } : { icon: 'error', title: 'Not sent', text: r.message }); })
                .fail(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); })
                .always(function() { done(); btn.disabled = false; ico.className = 'fas fa-paper-plane'; });
        }

        // backups
        var bkTable = null;
        function bkPost(action, name) { // file downloads = plain form post (csrf in the body)
            var f = document.getElementById('bkForm');
            f.action.value = action; f.name.value = name || ''; f.csrf_token.value = window.CSRF_TOKEN;
            f.submit();
        }
        function downloadBackup() { bkPost('downloadBackup'); }
        function loadBackups() {
            $.post('', { action: 'listBackups' }, null, 'json').done(function(r) {
                if (!r.success) return;
                $('#bkLast').text(r.last ? 'Last copy ' + r.last : 'No copy saved yet');
                var cols = [
                    { data: 'at', title: 'Saved On' },
                    { data: 'name', title: 'File' },
                    { data: 'size_kb', title: 'Size', render: function(d, t) { return t === 'display' ? Number(d).toLocaleString('en-IN') + ' KB' : d; } },
                    { data: null, orderable: false, title: '', render: function(d, t, row) {
                        return '<div class="action-hub"><button type="button" class="action-icon view-icon" data-bk="get" data-name="' + escapeHtml(row.name) + '" title="Download" aria-label="Download"><i class="fas fa-download"></i></button>'
                            + '<button type="button" class="action-icon delete-icon" data-bk="del" data-name="' + escapeHtml(row.name) + '" title="Delete" aria-label="Delete"><i class="fas fa-trash"></i></button></div>'; } }
                ];
                if (bkTable && bkTable.t) rsTbData(bkTable.t, r.data);
                else bkTable = rsTabTable('#bkTable', { columns: cols, data: r.data, title: 'Backups', file: 'backups', sort: ['at', 'desc'], empty: 'No server copies yet' });
            });
        }
        function backupNow() {
            var btn = document.getElementById('bkNowBtn'), ico = btn.querySelector('i');
            btn.disabled = true; ico.className = 'fas fa-spinner fa-spin';
            var done = rsOverlay('Backing up…');
            $.post('', { action: 'backupNow', csrf_token: window.CSRF_TOKEN }, null, 'json')
                .done(function(r) { r.success ? loadBackups() : Swal.fire({ icon: 'error', title: 'Error', text: r.message }); })
                .fail(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); })
                .always(function() { done(); btn.disabled = false; ico.className = 'fas fa-server'; });
        }
        $(document).on('click', '#bkTable [data-bk]', function() {
            var b = this, name = b.dataset.name;
            if (b.dataset.bk === 'get') return bkPost('getBackup', name);
            Swal.fire({ icon: 'warning', title: 'Delete this backup?', text: name, showCancelButton: true, confirmButtonColor: '#ea4335', confirmButtonText: 'Delete' }).then(function(x) {
                if (!x.isConfirmed) return;
                var done = rsOverlay('Deleting…');
                $.post('', { action: 'deleteBackup', name: name, csrf_token: window.CSRF_TOKEN }, null, 'json')
                    .done(function(r) { r.success ? loadBackups() : Swal.fire({ icon: 'error', title: 'Error', text: r.message }); })
                    .always(done);
            });
        });

        function runExpiryCheck() {
            showLoading('Checking...');
            $.get('cron_expiry.php', function(res) {
                hideLoading();
                loadBackups();
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Expiry Check Complete',
                        html: '<strong>' + res.notifications_created + '</strong> notifications created<br><strong>' + res.policies_expired + '</strong> policies marked expired<br>Backup: ' + escapeHtml(res.backup || '-'),
                        timer: 4000,
                        showConfirmButton: true
                    });
                } else {
                    Swal.fire('Error', res.message || 'Failed', 'error');
                }
            }, 'json').fail(function() { hideLoading(); Swal.fire('Error', 'Connection failed', 'error'); });
        }
    </script>
</body>
</html>
