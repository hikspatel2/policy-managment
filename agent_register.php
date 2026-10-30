<?php
/**
 * Agent Registration Page
 * Insurance Management System
 */
require_once 'config.php';

// If already logged in as active agent, go to dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'agent') {
        $uStatus = $_SESSION['status'] ?? 'active';
        if ($uStatus === 'pending_verification') {
            header("Location: agent_verify.php");
            exit();
        } elseif ($uStatus === 'verification_completed' || $uStatus === 'pending_subscription') {
            header("Location: agent_plans.php");
            exit();
        }
        header("Location: dashboard.php");
        exit();
    } elseif ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'manager') {
        header("Location: dashboard.php");
        exit();
    }
}

$error = '';
$notice = '';
$csrf_token = generateCSRFToken();
$branding = getBranding();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $mobile = preg_replace('/[^0-9]/', '', trim($_POST['mobile'] ?? ''));
        $email = strtolower(trim($_POST['email'] ?? ''));
        $agency = trim($_POST['agency_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $referralCode = trim($_POST['referral_code'] ?? '');

        // Validation
        if (empty($name) || strlen($name) < 3) {
            $error = 'Please enter your full name (minimum 3 characters).';
        } elseif (strlen($mobile) < 10 || strlen($mobile) > 12) {
            $error = 'Please enter a valid 10-digit mobile number.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (empty($agency)) {
            $error = 'Please enter your Agency / Business name.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Password confirmation does not match.';
        } else {
            try {
                $conn = getDBConnection();

                // Check duplicate email in users
                $chkEmail = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $chkEmail->bind_param("s", $email);
                $chkEmail->execute();
                if ($chkEmail->get_result()->num_rows > 0) {
                    $error = 'An account with this email address already exists. Please log in.';
                }
                $chkEmail->close();

                // Check duplicate mobile in users & agents
                if (!$error) {
                    $chkPhone = $conn->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
                    $chkPhone->bind_param("s", $mobile);
                    $chkPhone->execute();
                    if ($chkPhone->get_result()->num_rows > 0) {
                        $error = 'An account with this mobile number already exists. Please log in.';
                    }
                    $chkPhone->close();
                }

                if (!$error) {
                    // Check optional referral code
                    $referredByAgentId = null;
                    if (!empty($referralCode)) {
                        $chkRef = $conn->prepare("SELECT id FROM agents WHERE referral_code = ? OR agent_code = ? LIMIT 1");
                        $chkRef->bind_param("ss", $referralCode, $referralCode);
                        $chkRef->execute();
                        $refRes = $chkRef->get_result();
                        if ($refRes && $refRow = $refRes->fetch_assoc()) {
                            $referredByAgentId = (int)$refRow['id'];
                        }
                        $chkRef->close();
                    }

                    // Hash password
                    $passHash = password_hash($password, PASSWORD_BCRYPT);
                    $userStatus = 'pending_verification';

                    // 1. Insert into users table
                    $uStmt = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, role, status, is_active, profile_setup_completed) VALUES (?, ?, ?, ?, 'agent', ?, 1, 0)");
                    $uStmt->bind_param("sssss", $name, $email, $mobile, $passHash, $userStatus);
                    $uStmt->execute();
                    $userId = $conn->insert_id;
                    $uStmt->close();

                    // Generate unique agent code and personal referral code
                    $agentCode = 'AGT-' . date('Y') . '-' . strtoupper(substr(uniqid(), -4));
                    $agentRefCode = 'REF-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 4) . rand(1000, 9999));

                    // 2. Insert into agents table
                    $aStmt = $conn->prepare("INSERT INTO agents (user_id, agent_code, name, agency_name, mobile, email, referral_code, referred_by_agent_id, joining_date, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 1)");
                    $aStmt->bind_param("issssssi", $userId, $agentCode, $name, $agency, $mobile, $email, $agentRefCode, $referredByAgentId);
                    $aStmt->execute();
                    $agentId = $conn->insert_id;
                    $aStmt->close();

                    // 3. Generate 6-digit Mobile OTP
                    $mobileOtp = (string)rand(100000, 999999);
                    $otpExpires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                    $vStmt = $conn->prepare("INSERT INTO agent_verifications (user_id, verification_type, token_or_otp, expires_at) VALUES (?, 'mobile_otp', ?, ?)");
                    $vStmt->bind_param("iss", $userId, $mobileOtp, $otpExpires);
                    $vStmt->execute();
                    $vStmt->close();

                    // 4. Generate Email Verification Token
                    $emailToken = bin2hex(random_bytes(32));
                    $tokExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    $eStmt = $conn->prepare("INSERT INTO agent_verifications (user_id, verification_type, token_or_otp, expires_at) VALUES (?, 'email_token', ?, ?)");
                    $eStmt->bind_param("iss", $userId, $emailToken, $tokExpires);
                    $eStmt->execute();
                    $eStmt->close();

                    // Try sending email if SMTP enabled
                    $verifyUrl = appUrl("agent_verify.php?token=" . urlencode($emailToken));
                    $emailHtml = emailLayout("Verify Your Agent Account", "
                        <p>Dear " . htmlspecialchars($name) . ",</p>
                        <p>Thank you for registering as an Insurance Agent on <strong>" . htmlspecialchars($branding['site_name']) . "</strong>.</p>
                        <p>Your Mobile OTP is: <strong style='font-size:22px;letter-spacing:4px;color:#001f3f;'>{$mobileOtp}</strong> (valid for 15 minutes).</p>
                        <p>Please click the link below to verify your email address:</p>
                        <p style='margin:25px 0;'><a href='{$verifyUrl}' style='background:#001f3f;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;'>Verify Email Address</a></p>
                        <p style='font-size:12px;color:#888;'>If you did not request this registration, please ignore this email.</p>
                    ");
                    @sendEmail($email, "Verify Your Agent Account - " . $branding['site_name'], $emailHtml);

                    // Try WhatsApp OTP if WhatsApp Cloud API configured
                    $waText = "Welcome to " . $branding['site_name'] . "! Your verification OTP is: " . $mobileOtp . ". Valid for 15 minutes.";
                    @sendWhatsAppCloudMessage($mobile, $waText);

                    // Auto login into session in pending_verification status
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['username'] = $name;
                    $_SESSION['email'] = $email;
                    $_SESSION['role'] = 'agent';
                    $_SESSION['status'] = 'pending_verification';
                    $_SESSION['temp_demo_otp'] = $mobileOtp; // Helper for instant onboarding if SMS gateway offline

                    logActivity($userId, $name, 'Agent Registration', "New agent registration: Code {$agentCode}, Agency {$agency}");

                    $conn->close();

                    header("Location: agent_verify.php");
                    exit();
                }

                $conn->close();
            } catch (Throwable $e) {
                error_log("Registration error: " . $e->getMessage());
                $error = 'Registration failed: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Registration — <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="styles.css?v=5.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="login-page">
    <div class="login-container" style="max-width: 520px;">
        <div class="login-box" style="padding: 30px;">
            <div class="login-logo">
                <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" onerror="this.src='uploads/logos/site_logo.png'">
            </div>
            <h2 style="font-size: 22px; margin-bottom: 4px;">Agent Registration</h2>
            <p style="color: #64748b; font-size: 13.5px; margin-bottom: 20px; text-align: center;">Start managing insurance policies, renewals & AI scanning</p>

            <?php if ($error): ?>
                <div class="error" style="margin-bottom: 15px;"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="agent_register.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="form-group" style="margin-bottom: 12px;">
                    <label for="name"><i class="fas fa-user"></i> Full Name *</label>
                    <input type="text" id="name" name="name" placeholder="e.g. Rahul Sharma" required autofocus value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label for="mobile"><i class="fas fa-phone"></i> Mobile Number *</label>
                        <input type="tel" id="mobile" name="mobile" placeholder="10-digit mobile" required pattern="[0-9]{10,12}" value="<?php echo htmlspecialchars($_POST['mobile'] ?? ''); ?>">
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label for="email"><i class="fas fa-envelope"></i> Email Address *</label>
                        <input type="email" id="email" name="email" placeholder="rahul@example.com" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 12px;">
                    <label for="agency_name"><i class="fas fa-building"></i> Agency / Business Name *</label>
                    <input type="text" id="agency_name" name="agency_name" placeholder="e.g. Sharma Insurance Consultancy" required value="<?php echo htmlspecialchars($_POST['agency_name'] ?? ''); ?>">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label for="password"><i class="fas fa-lock"></i> Password *</label>
                        <input type="password" id="password" name="password" placeholder="At least 6 chars" required minlength="6">
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label for="confirm_password"><i class="fas fa-lock"></i> Confirm Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter password" required minlength="6">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 18px;">
                    <label for="referral_code"><i class="fas fa-ticket"></i> Referral Code <span style="font-weight: normal; color: #888;">(Optional)</span></label>
                    <input type="text" id="referral_code" name="referral_code" placeholder="Enter referral code if any" value="<?php echo htmlspecialchars($_POST['referral_code'] ?? ''); ?>">
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="padding: 12px; font-size: 15px; font-weight: 600;">
                    <i class="fas fa-user-plus"></i> Create Agent Account
                </button>

                <div style="margin-top: 15px; text-align: center; font-size: 13.5px;">
                    <span style="color: #64748b;">Already have an account?</span>
                    <a href="login.php" style="color: var(--primary-color, #001f3f); font-weight: 600; text-decoration: none; margin-left: 5px;"><i class="fas fa-sign-in-alt"></i> Login Here</a>
                </div>
            </form>

            <div class="login-footer" style="margin-top: 25px;">
                <p><?php echo htmlspecialchars($branding['site_footer'] ?: '© ' . date('Y') . ' ' . $branding['site_name']); ?></p>
            </div>
        </div>

        <!-- Theme Toggle Button -->
        <button class="login-theme-toggle" onclick="toggleTheme()" title="Toggle Theme" aria-label="Toggle theme">
            <i class="fas fa-moon" id="themeIcon"></i>
        </button>
    </div>

    <script>
    function initTheme() {
        const savedTheme = localStorage.getItem('theme');
        const themeIcon = document.getElementById('themeIcon');
        if (savedTheme === 'dark') {
            document.body.classList.add('dark-mode');
            if (themeIcon) themeIcon.className = 'fas fa-sun';
        } else {
            document.body.classList.remove('dark-mode');
            if (themeIcon) themeIcon.className = 'fas fa-moon';
        }
    }
    function toggleTheme() {
        const isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        const themeIcon = document.getElementById('themeIcon');
        if (themeIcon) themeIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }
    document.addEventListener('DOMContentLoaded', initTheme);
    </script>
</body>
</html>
