<?php
/**
 * Agent Verification Page (Mobile OTP & Email Token)
 * Insurance Management System
 */
require_once 'config.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

// If accessed via email link with a GET token
$getToken = trim($_GET['token'] ?? '');
$conn = getDBConnection();
$branding = getBranding();
$error = '';
$notice = '';

// Handle direct email verification link click
if (!empty($getToken)) {
    $eStmt = $conn->prepare("SELECT v.id, v.user_id, u.email, u.name, u.role, u.status 
                             FROM agent_verifications v 
                             JOIN users u ON u.id = v.user_id 
                             WHERE v.verification_type = 'email_token' AND v.token_or_otp = ? AND v.is_verified = 0 AND v.expires_at > NOW() 
                             LIMIT 1");
    if ($eStmt) {
        $eStmt->bind_param("s", $getToken);
        $eStmt->execute();
        $vRes = $eStmt->get_result();
        if ($vRes && $vRow = $vRes->fetch_assoc()) {
            $eUserId = (int)$vRow['user_id'];
            $vId = (int)$vRow['id'];

            // Mark email as verified
            $conn->query("UPDATE agent_verifications SET is_verified = 1, verified_at = NOW() WHERE id = $vId");
            $conn->query("UPDATE users SET email_verified_at = NOW() WHERE id = $eUserId");

            // Check if mobile is also verified, or advance status
            $conn->query("UPDATE users SET status = 'pending_subscription' WHERE id = $eUserId AND status = 'pending_verification'");

            // Log user into session if not already
            if (!$userId) {
                $_SESSION['user_id'] = $eUserId;
                $_SESSION['username'] = $vRow['name'];
                $_SESSION['email'] = $vRow['email'];
                $_SESSION['role'] = $vRow['role'];
                $_SESSION['status'] = 'pending_subscription';
                $userId = $eUserId;
            }
            $notice = 'Email successfully verified! You can now proceed to select your Subscription Plan.';
        } else {
            $error = 'Invalid or expired email verification link.';
        }
        $eStmt->close();
    }
}

// Require login for form OTP actions
if (!$userId && empty($notice)) {
    header("Location: login.php");
    exit();
}

// Fetch current user details
$user = null;
$uStmt = $conn->prepare("SELECT id, name, email, phone, role, status, mobile_verified_at, email_verified_at FROM users WHERE id = ? LIMIT 1");
if ($uStmt) {
    $uStmt->bind_param("i", $userId);
    $uStmt->execute();
    $user = $uStmt->get_result()->fetch_assoc();
    $uStmt->close();
}

if (!$user || $user['role'] !== 'agent') {
    header("Location: dashboard.php");
    exit();
}

// If already verified and active, send to plans or dashboard
if ($user['status'] === 'active') {
    header("Location: dashboard.php");
    exit();
} elseif ($user['status'] === 'verification_completed' || $user['status'] === 'pending_subscription') {
    header("Location: agent_plans.php");
    exit();
}

// Handle OTP submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } elseif ($action === 'verify_otp') {
        $enteredOtp = preg_replace('/[^0-9]/', '', trim($_POST['otp'] ?? ''));
        if (strlen($enteredOtp) !== 6) {
            $error = 'Please enter a valid 6-digit OTP.';
        } else {
            $vStmt = $conn->prepare("SELECT id, expires_at FROM agent_verifications 
                                     WHERE user_id = ? AND verification_type = 'mobile_otp' AND token_or_otp = ? AND is_verified = 0 
                                     ORDER BY id DESC LIMIT 1");
            $vStmt->bind_param("is", $userId, $enteredOtp);
            $vStmt->execute();
            $vRow = $vStmt->get_result()->fetch_assoc();
            $vStmt->close();

            if ($vRow) {
                if (strtotime($vRow['expires_at']) < time()) {
                    $error = 'OTP has expired. Please click "Resend OTP" to receive a new code.';
                } else {
                    $vId = (int)$vRow['id'];
                    $conn->query("UPDATE agent_verifications SET is_verified = 1, verified_at = NOW() WHERE id = $vId");
                    $conn->query("UPDATE users SET mobile_verified_at = NOW(), status = 'pending_subscription' WHERE id = $userId");
                    $_SESSION['status'] = 'pending_subscription';
                    unset($_SESSION['temp_demo_otp']);

                    logActivity($userId, $user['name'], 'Agent Verified', 'Mobile number verified via OTP');

                    $conn->close();
                    header("Location: agent_plans.php");
                    exit();
                }
            } else {
                $error = 'Invalid OTP entered. Please check and try again.';
                $conn->query("UPDATE agent_verifications SET attempts_count = attempts_count + 1 WHERE user_id = $userId AND verification_type = 'mobile_otp' ORDER BY id DESC LIMIT 1");
            }
        }
    } elseif ($action === 'resend_otp') {
        // Generate new OTP
        $newOtp = (string)rand(100000, 999999);
        $newExpiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $rStmt = $conn->prepare("INSERT INTO agent_verifications (user_id, verification_type, token_or_otp, expires_at) VALUES (?, 'mobile_otp', ?, ?)");
        $rStmt->bind_param("iss", $userId, $newOtp, $newExpiry);
        $rStmt->execute();
        $rStmt->close();

        $_SESSION['temp_demo_otp'] = $newOtp;

        // Dispatch WhatsApp / SMS
        @sendWhatsAppCloudMessage($user['phone'], "Your new OTP for " . $branding['site_name'] . " is: " . $newOtp . ".");

        $notice = 'A new 6-digit OTP has been sent to your registered mobile number.';
    }
}

$csrf_token = generateCSRFToken();
$demoOtp = $_SESSION['temp_demo_otp'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Agent Account — <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="styles.css?v=5.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .otp-input-wrap {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 20px 0;
        }
        .otp-digit {
            width: 48px;
            height: 52px;
            text-align: center;
            font-size: 24px;
            font-weight: 700;
            border: 2px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            color: #001f3f;
            outline: none;
            transition: all 0.2s;
        }
        .otp-digit:focus {
            border-color: #0074D9;
            box-shadow: 0 0 0 3px rgba(0, 116, 217, 0.2);
        }
        .demo-badge {
            background: #e0f2fe;
            border: 1px dashed #0284c7;
            color: #0369a1;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13.5px;
            margin-bottom: 15px;
            text-align: center;
        }
    </style>
</head>
<body class="login-page">
    <div class="login-container" style="max-width: 480px;">
        <div class="login-box" style="padding: 30px;">
            <div class="login-logo">
                <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="Logo" onerror="this.src='uploads/logos/site_logo.png'">
            </div>
            <h2 style="font-size: 22px; margin-bottom: 6px;">Verify Mobile Number</h2>
            <p style="color: #64748b; font-size: 13.5px; margin-bottom: 18px; text-align: center;">
                We sent a 6-digit verification code to<br>
                <strong style="color: #1e293b;"><?php echo htmlspecialchars($user['phone'] ?? ''); ?></strong> and <strong style="color: #1e293b;"><?php echo htmlspecialchars($user['email'] ?? ''); ?></strong>
            </p>

            <?php if ($error): ?>
                <div class="error" style="margin-bottom: 15px;"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($notice): ?>
                <div class="success" style="margin-bottom: 15px;"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($notice); ?></div>
            <?php endif; ?>

            <?php if ($demoOtp): ?>
                <div class="demo-badge">
                    <i class="fas fa-key"></i> Onboarding OTP: <strong style="font-size:16px;letter-spacing:2px;"><?php echo htmlspecialchars($demoOtp); ?></strong>
                </div>
            <?php endif; ?>

            <form method="POST" action="agent_verify.php" id="otpForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="verify_otp">
                <input type="hidden" name="otp" id="fullOtp">

                <div class="otp-input-wrap">
                    <input type="text" maxlength="1" class="otp-digit" inputmode="numeric" pattern="[0-9]" autofocus>
                    <input type="text" maxlength="1" class="otp-digit" inputmode="numeric" pattern="[0-9]">
                    <input type="text" maxlength="1" class="otp-digit" inputmode="numeric" pattern="[0-9]">
                    <input type="text" maxlength="1" class="otp-digit" inputmode="numeric" pattern="[0-9]">
                    <input type="text" maxlength="1" class="otp-digit" inputmode="numeric" pattern="[0-9]">
                    <input type="text" maxlength="1" class="otp-digit" inputmode="numeric" pattern="[0-9]">
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="padding: 12px; font-size: 15px; font-weight: 600;">
                    <i class="fas fa-shield-check"></i> Verify & Continue to Plans
                </button>
            </form>

            <form method="POST" action="agent_verify.php" style="margin-top: 15px; text-align: center;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="resend_otp">
                <button type="submit" style="background: none; border: none; color: #0074D9; font-weight: 600; cursor: pointer; font-size: 13.5px;">
                    <i class="fas fa-rotate"></i> Didn't receive code? Resend OTP
                </button>
            </form>

            <div style="margin-top: 20px; text-align: center; font-size: 13px; color: #94a3b8;">
                <a href="login.php" style="color: #64748b; text-decoration: none;"><i class="fas fa-arrow-left"></i> Back to Login</a>
            </div>

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
    const digits = document.querySelectorAll('.otp-digit');
    const fullOtpInput = document.getElementById('fullOtp');
    const otpForm = document.getElementById('otpForm');

    digits.forEach((input, index) => {
        input.addEventListener('input', (e) => {
            const val = e.target.value.replace(/[^0-9]/g, '');
            e.target.value = val;
            if (val && index < digits.length - 1) {
                digits[index + 1].focus();
            }
            updateFullOtp();
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !e.target.value && index > 0) {
                digits[index - 1].focus();
            }
        });

        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '').slice(0, 6);
            if (pasted.length) {
                pasted.split('').forEach((char, i) => {
                    if (digits[i]) digits[i].value = char;
                });
                const focusIdx = Math.min(pasted.length, digits.length - 1);
                digits[focusIdx].focus();
                updateFullOtp();
            }
        });
    });

    function updateFullOtp() {
        let code = '';
        digits.forEach(d => code += d.value);
        fullOtpInput.value = code;
    }

    otpForm.addEventListener('submit', (e) => {
        updateFullOtp();
        if (fullOtpInput.value.length !== 6) {
            e.preventDefault();
            alert('Please enter all 6 digits of your OTP.');
        }
    });

    // Theme Toggle
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
