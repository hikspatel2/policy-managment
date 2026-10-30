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

// already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$notice = '';
$csrf_token = generateCSRFToken();
$mode = in_array($_GET['mode'] ?? '', ['forgot', 'reset'], true) ? $_GET['mode'] : 'login';
// forgot password only when mail can actually go out + links have a fixed site url
$canReset = getSetting('smtp_enabled', '0') === '1' && appUrl() !== '';
$resetTok = (string)($_POST['token'] ?? $_GET['token'] ?? '');

// live reset row for an emailed token: unused, not expired, active user
function resetRow($tok) {
    if (!preg_match('/^[a-f0-9]{64}$/', $tok)) return null;
    try {
        $conn = getDBConnection();
        $h = hash('sha256', $tok);
        $st = $conn->prepare("SELECT pr.id, pr.user_id, u.email, u.name FROM password_resets pr JOIN users u ON u.id = pr.user_id
                              WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.is_active = 1");
        $st->bind_param("s", $h);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        $conn->close();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log("Reset lookup error: " . $e->getMessage());
        return null;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $form = $_POST['form'] ?? 'login';
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
        $mode = in_array($form, ['forgot', 'reset'], true) ? $form : 'login';
    } elseif ($form === 'forgot') {
        // same reply whether or not the email exists — no account probing
        $mode = 'forgot';
        $email = trim($_POST['email'] ?? '');
        if (!$canReset) {
            $error = 'Password reset by email is not available. Contact your administrator.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } else {
            try {
                $conn = getDBConnection();
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $st = $conn->prepare("SELECT (SELECT COUNT(*) FROM password_resets WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS by_ip,
                                             u.id, u.name, (SELECT COUNT(*) FROM password_resets WHERE user_id = u.id AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)) AS recent
                                      FROM users u WHERE u.email = ? AND u.is_active = 1");
                $st->bind_param("ss", $ip, $email);
                $st->execute();
                $u = $st->get_result()->fetch_assoc();
                $st->close();
                // 5 links / hour per ip, one per user every 2 minutes
                if ($u && $u['by_ip'] < 5 && !$u['recent']) {
                    $tok = bin2hex(random_bytes(32));
                    $h = hash('sha256', $tok);
                    $uid = (int)$u['id'];
                    $st = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, ip_address) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), ?)");
                    $st->bind_param("iss", $uid, $h, $ip);
                    $st->execute();
                    $st->close();
                    $link = htmlspecialchars(appUrl('login.php?mode=reset&token=' . $tok));
                    $r = sendEmail($email, 'Reset your password', emailLayout('Reset your password',
                        '<p>Hi ' . htmlspecialchars($u['name']) . ',</p><p>We received a request to reset the password for your account. The link below works once and expires in 1 hour.</p>'
                        . '<p><a href="' . $link . '" style="display:inline-block;background:#0074D9;color:#ffffff;padding:10px 18px;text-decoration:none;font-weight:bold">Reset password</a></p>'
                        . '<p style="font-size:13px;color:#6b7280">If the button does not open, copy this link into your browser:<br>' . $link . '</p>'
                        . '<p>If you did not ask for this, ignore this email. Your password stays the same.</p>'));
                    if (!$r['success']) error_log("Reset mail failed for user #$uid: " . $r['message']);
                    logActivity($uid, $u['name'], 'Password Reset Requested', $r['success'] ? 'Reset link emailed' : 'Reset email could not be sent');
                }
                $conn->close();
            } catch (Throwable $e) {
                error_log("Forgot password error: " . $e->getMessage());
            }
            $notice = 'If that email belongs to an account, a reset link is on its way. It expires in 1 hour.';
        }
    } elseif ($form === 'reset') {
        $mode = 'reset';
        $row = resetRow($resetTok);
        $pwd = (string)($_POST['password'] ?? '');
        if (!$row) {
            $error = 'This reset link is invalid or has expired. Request a new one.';
        } elseif (strlen($pwd) < 8 || strlen($pwd) > 255) {
            $error = 'Use at least 8 characters for the new password.';
        } elseif (!hash_equals($pwd, (string)($_POST['password_confirm'] ?? ''))) {
            $error = 'The two passwords do not match.';
        } else {
            try {
                $conn = getDBConnection();
                $conn->begin_transaction();
                $hash = password_hash($pwd, PASSWORD_DEFAULT);
                $uid = (int)$row['user_id'];
                $st = $conn->prepare("UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?");
                $st->bind_param("si", $hash, $uid);
                $st->execute();
                $st->close();
                // single use — and every other open link for this user dies too
                $st = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL");
                $st->bind_param("i", $uid);
                $st->execute();
                $st->close();
                $conn->commit();
                $conn->close();
                clearLoginAttempts($row['email']);
                logActivity($uid, $row['name'], 'Password Reset', 'Password changed with an emailed link');
                $mode = 'login';
                $notice = 'Password changed. Sign in with your new password.';
            } catch (Throwable $e) {
                error_log("Reset password error: " . $e->getMessage());
                $error = 'Could not change the password. Please try again.';
            }
        }
    } else {
        $loginInput = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $loginRole = isset($_POST['login_role']) ? trim($_POST['login_role']) : 'all';

        $isEmail = filter_var($loginInput, FILTER_VALIDATE_EMAIL);
        $isPhone = preg_match('/^[0-9+\s-]{10,15}$/', $loginInput);
        $isCode = preg_match('/^[A-Za-z0-9_-]{3,30}$/', $loginInput);

        if (!$isEmail && !$isPhone && !$isCode) {
            $error = 'Enter a valid email address, mobile number or agent code.';
        } elseif (validatePassword($password) === false) {
            $error = 'Invalid password format.';
        } elseif (checkLoginAttempts($loginInput)) {
            $error = 'Too many login attempts. Please try again in 15 minutes.';
        } else {
            try {
                $conn = getDBConnection();
                $cleanPhone = preg_replace('/[^0-9]/', '', $loginInput);
                $stmt = $conn->prepare("SELECT u.id, u.name, u.email, u.phone, u.password_hash, u.role, u.customer_id, u.is_active 
                                        FROM users u 
                                        LEFT JOIN agents a ON a.user_id = u.id 
                                        WHERE u.email = ? 
                                           OR u.phone = ? 
                                           OR (u.phone IS NOT NULL AND u.phone != '' AND ? != '' AND REPLACE(REPLACE(u.phone, ' ', ''), '-', '') = ?)
                                           OR a.agent_code = ? 
                                        LIMIT 1");
                $stmt->bind_param("sssss", $loginInput, $loginInput, $cleanPhone, $cleanPhone, $loginInput);
                $stmt->execute();
                $result = $stmt->get_result();
            } catch (Exception $e) {
                if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Unknown column") !== false) {
                    $error = 'Database not set up. Please run <a href="setup.php" class="login-link">setup.php</a> first.';
                } else {
                    $error = 'Database error. Please contact administrator.';
                    error_log("Login error: " . $e->getMessage());
                }
                if (isset($stmt)) $stmt->close();
                if (isset($conn)) $conn->close();
                $result = null;
            }

            if ($result && $result->num_rows == 1) {
                $user = $result->fetch_assoc();

                if (!$user['is_active']) {
                    $error = 'Account is deactivated. Contact administrator.';
                } elseif ($loginRole === 'agent' && $user['role'] !== 'agent') {
                    $error = 'This account is not registered as an Agent. Please choose Staff or Customer tab.';
                } elseif ($loginRole === 'customer' && $user['role'] !== 'customer') {
                    $error = 'This account is not registered as a Customer. Please choose Staff or Agent tab.';
                } elseif (password_verify($password, $user['password_hash'])) {
                    clearLoginAttempts($loginInput);
                    recordLoginAttempt($loginInput, true); // success trail
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['name']; // compat key
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['customer_id'] = !empty($user['customer_id']) ? (int)$user['customer_id'] : null;
                    $_SESSION['LAST_ACTIVITY'] = time();

                    if ($user['role'] === 'customer' && empty($_SESSION['customer_id'])) {
                        $cSt = $conn->prepare("SELECT id FROM customers WHERE email = ? OR mobile = ? LIMIT 1");
                        $cSt->bind_param("ss", $user['email'], $user['phone']);
                        $cSt->execute();
                        $cRow = $cSt->get_result()->fetch_assoc();
                        $cSt->close();
                        if ($cRow) {
                            $_SESSION['customer_id'] = (int)$cRow['id'];
                            $conn->query("UPDATE users SET customer_id = " . (int)$cRow['id'] . " WHERE id = " . (int)$user['id']);
                        }
                    }

                    // update last login
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                    $upd = $conn->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
                    $upd->bind_param("si", $ip, $user['id']);
                    $upd->execute();
                    $upd->close();

                    logActivity($user['id'], $user['name'], 'Login', 'User logged in successfully (' . ucfirst($user['role']) . ')');

                    $stmt->close();
                    $conn->close();

                    header("Location: dashboard.php");
                    exit();
                } else {
                    recordLoginAttempt($loginInput);
                    $error = 'Invalid credentials or password';
                }
            } elseif ($result && $result->num_rows == 0) {
                recordLoginAttempt($loginInput);
                $error = 'Invalid credentials or password';
            }

            if (isset($stmt) && $stmt) $stmt->close();
            if (isset($conn) && $conn) $conn->close();
        }
    }
}
// reset link opened: check it before showing the form
$resetOk = $mode === 'reset' && resetRow($resetTok) !== null;
if ($mode === 'reset' && !$resetOk && !$error) $error = 'This reset link is invalid or has expired. Request a new one.';
$branding = getBranding();
$titles = ['login' => 'Login', 'forgot' => 'Forgot Password', 'reset' => 'Set a New Password'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer"><!-- reset tokens never leak in a Referer header -->
    <link rel="icon" href="<?php echo htmlspecialchars($branding['site_logo']); ?>"><!-- tab icon, stops the browser asking for /favicon.ico -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title><?php echo $titles[$mode]; ?> - <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=16.0">
    <style>
        .role-tabs {
            display: flex;
            background: #e2e8f0;
            padding: 4px;
            border-radius: 10px;
            margin-bottom: 20px;
            gap: 4px;
        }
        .role-tab {
            flex: 1;
            padding: 8px 6px;
            border: none;
            background: transparent;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .role-tab:hover {
            color: #0b2239;
        }
        .role-tab.active {
            background: #ffffff;
            color: #0b2239;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        body.dark-mode .role-tabs {
            background: #1e293b;
        }
        body.dark-mode .role-tab {
            color: #94a3b8;
        }
        body.dark-mode .role-tab:hover {
            color: #f1f5f9;
        }
        body.dark-mode .role-tab.active {
            background: #0f172a;
            color: #38bdf8;
            box-shadow: 0 2px 6px rgba(0,0,0,0.3);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" class="login-logo" onerror="this.onerror=null;this.src='uploads/logos/site_logo.png';">
            <h2><?php echo htmlspecialchars($branding['site_name']); ?></h2>

            <?php if ($error): ?>
                <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($notice): ?>
                <div class="success"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($notice); ?></div>
            <?php endif; ?>

            <?php if ($mode === 'forgot'): ?>
            <p class="login-subtitle"><i class="fas fa-key"></i> Forgot password</p>
            <?php if (!$notice): ?>
            <form method="POST" action="login.php?mode=forgot">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="form" value="forgot">
                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> Account Email</label>
                    <input type="email" id="email" name="email" required autofocus autocomplete="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-paper-plane"></i> Send Reset Link</button>
            </form>
            <?php endif; ?>
            <p class="login-footer-link"><a class="login-link" href="login.php"><i class="fas fa-arrow-left"></i> Back to login</a></p>

            <?php elseif ($mode === 'reset'): ?>
            <p class="login-subtitle"><i class="fas fa-lock"></i> Set a new password</p>
            <?php if ($resetOk): ?>
            <form method="POST" action="login.php?mode=reset">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="form" value="reset">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($resetTok); ?>">
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> New Password</label>
                    <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
                </div>
                <div class="form-group">
                    <label for="password_confirm"><i class="fas fa-lock"></i> Confirm Password</label>
                    <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-check"></i> Change Password</button>
            </form>
            <?php else: ?>
            <p class="login-footer-link"><a class="login-link" href="login.php?mode=forgot"><i class="fas fa-rotate"></i> Request a new link</a></p>
            <?php endif; ?>
            <p class="login-footer-link"><a class="login-link" href="login.php"><i class="fas fa-arrow-left"></i> Back to login</a></p>

            <?php else: ?>
            <form method="POST" action="login.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="form" value="login">
                <input type="hidden" name="login_role" id="loginRole" value="all">

                <div class="role-tabs">
                    <button type="button" class="role-tab active" data-role="all"><i class="fas fa-shield-halved"></i> Staff</button>
                    <button type="button" class="role-tab" data-role="agent"><i class="fas fa-user-tie"></i> Agent</button>
                    <button type="button" class="role-tab" data-role="customer"><i class="fas fa-user"></i> Customer</button>
                </div>

                <div class="form-group">
                    <label for="email" id="loginLabel"><i class="fas fa-user"></i> Email or Mobile</label>
                    <input type="text" id="email" name="email" placeholder="Email address, mobile or agent code" required autofocus autocomplete="username">
                </div>

                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> Password</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password" minlength="6">
                </div>

                <?php if ($canReset): ?>
                <div class="login-options-row login-options-end"><a class="login-link" href="login.php?mode=forgot">Forgot password?</a></div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </form>
            <?php endif; ?>

            <div class="login-footer">
                <p><?php echo htmlspecialchars($branding['site_footer'] ?: '© ' . date('Y') . ' ' . $branding['site_name']); ?></p>
            </div>
            <div class="auth-contact">
                <span class="auth-contact-title"><i class="fas fa-handshake"></i> Let's Work Together</span>
                <div class="auth-contact-links">
                    <a href="https://wa.me/918160140848" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
                    <a href="mailto:contact@rudeesdigital.com"><i class="fa-solid fa-envelope"></i> contact@rudeesdigital.com</a>
                    <a href="https://rudeesdigital.com" target="_blank" rel="noopener"><i class="fa-solid fa-globe"></i> Rudees Digital</a>
                </div>
            </div>
        </div>

        <!-- Theme Toggle Button -->
        <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme" aria-label="Toggle theme">
            <i class="fas fa-moon" id="themeIcon"></i>
        </button>
    </div>

    <script>
    // Theme Toggle for Login Page
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
        if (icon) {
            icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
    }

    initTheme();

    // Role Tab Switching
    document.querySelectorAll('.role-tab').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.role-tab').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            var role = this.getAttribute('data-role');
            var roleInput = document.getElementById('loginRole');
            if (roleInput) roleInput.value = role;

            var label = document.getElementById('loginLabel');
            var input = document.getElementById('email');
            if (!label || !input) return;

            if (role === 'agent') {
                label.innerHTML = '<i class="fas fa-id-badge"></i> Agent Code, Email or Mobile';
                input.setAttribute('placeholder', 'Agent Code (e.g. AGT-001), Email or Mobile');
            } else if (role === 'customer') {
                label.innerHTML = '<i class="fas fa-user"></i> Mobile or Email';
                input.setAttribute('placeholder', '10-digit mobile or registered email');
            } else {
                label.innerHTML = '<i class="fas fa-user"></i> Email or Mobile';
                input.setAttribute('placeholder', 'Email address, mobile or agent code');
            }
        });
    });
    </script>
</body>
</html>
