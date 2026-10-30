<?php
/**
 * Agent First-Time Setup Wizard
 * Insurance Management System
 */
require_once 'config.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

if (!$userId || $role !== 'agent') {
    header("Location: login.php");
    exit();
}

$conn = getDBConnection();
$branding = getBranding();
$aid = agentIdForUser($conn, $userId);

// Fetch current user and agent records
$uStmt = $conn->prepare("SELECT u.*, a.agency_name, a.address, a.city, a.state, a.pincode, a.logo_path,
                                a.whatsapp_consent, a.email_consent, a.terms_accepted_at
                         FROM users u
                         LEFT JOIN agents a ON a.user_id = u.id
                         WHERE u.id = ? LIMIT 1");
$uStmt->bind_param("i", $userId);
$uStmt->execute();
$data = $uStmt->get_result()->fetch_assoc();
$uStmt->close();

$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $agency = trim($_POST['agency_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $pincode = trim($_POST['pincode'] ?? '');
        $waConsent = isset($_POST['whatsapp_consent']) ? 1 : 0;
        $emailConsent = isset($_POST['email_consent']) ? 1 : 0;
        $acceptTerms = isset($_POST['accept_terms']) ? 1 : 0;

        if (empty($name)) {
            $error = 'Please enter your full name.';
        } elseif (empty($agency)) {
            $error = 'Please enter your agency/business name.';
        } elseif (!$acceptTerms) {
            $error = 'You must accept the Terms and Conditions and Privacy Policy to continue.';
        } else {
            // Handle profile photo upload
            $profilePath = $data['profile_image'];
            if (!empty($_FILES['profile_photo']['name']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $newFile = 'uploads/profiles/agent_' . $userId . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], __DIR__ . '/' . $newFile)) {
                        $profilePath = $newFile;
                    }
                }
            }

            // Handle business logo upload
            $logoPath = $data['logo_path'];
            if (!empty($_FILES['business_logo']['name']) && $_FILES['business_logo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['business_logo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'], true)) {
                    $newLogo = 'uploads/logos/agency_' . $aid . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['business_logo']['tmp_name'], __DIR__ . '/' . $newLogo)) {
                        $logoPath = $newLogo;
                    }
                }
            }

            // Update users table
            $updU = $conn->prepare("UPDATE users SET name = ?, profile_image = ?, profile_setup_completed = 1 WHERE id = ?");
            $updU->bind_param("ssi", $name, $profilePath, $userId);
            $updU->execute();
            $updU->close();

            // Update agents table
            $termsAt = date('Y-m-d H:i:s');
            $updA = $conn->prepare("UPDATE agents SET name = ?, agency_name = ?, address = ?, city = ?, state = ?, pincode = ?, logo_path = ?, whatsapp_consent = ?, email_consent = ?, terms_accepted_at = ? WHERE id = ?");
            $updA->bind_param("sssssssiisi", $name, $agency, $address, $city, $state, $pincode, $logoPath, $waConsent, $emailConsent, $termsAt, $aid);
            $updA->execute();
            $updA->close();

            $_SESSION['username'] = $name;

            logActivity($userId, $name, 'Setup Wizard Completed', 'First-time agent setup completed successfully');

            $conn->close();
            header("Location: dashboard.php?setup_done=1");
            exit();
        }
    }
}

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>First-Time Setup Wizard — <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="styles.css?v=5.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .wizard-container {
            max-width: 760px;
            margin: 40px auto;
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            padding: 35px 40px;
        }
        .wizard-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 35px;
            position: relative;
        }
        .wizard-steps::before {
            content: '';
            position: absolute;
            top: 18px;
            left: 30px;
            right: 30px;
            height: 3px;
            background: #e2e8f0;
            z-index: 1;
        }
        .step-item {
            position: relative;
            z-index: 2;
            background: white;
            padding: 0 8px;
            text-align: center;
        }
        .step-circle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 15px;
            margin: 0 auto 6px;
            transition: all 0.25s;
        }
        .step-item.active .step-circle {
            background: #001f3f;
            color: white;
            box-shadow: 0 0 0 4px rgba(0, 31, 63, 0.15);
        }
        .step-label {
            font-size: 12.5px;
            font-weight: 600;
            color: #64748b;
        }
        .step-item.active .step-label {
            color: #001f3f;
        }
        .wizard-panel {
            display: none;
        }
        .wizard-panel.active {
            display: block;
            animation: fadeIn 0.25s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body style="background: #f8fafc; font-family: inherit;">
    <div style="padding: 20px;">
        <div class="wizard-container">
            <div style="text-align: center; margin-bottom: 25px;">
                <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="" width="44" height="44" onerror="this.src='uploads/logos/site_logo.png'">
                <h1 style="font-size: 24px; color: #001f3f; margin: 10px 0 4px;">Welcome to Your Agent Portal!</h1>
                <p style="color: #64748b; font-size: 14px; margin: 0;">Complete this quick 4-step setup to customize your agency branding and notifications.</p>
            </div>

            <?php if ($error): ?>
                <div class="error" style="margin-bottom: 20px;"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="wizard-steps">
                <div class="step-item active" data-step="1">
                    <div class="step-circle">1</div>
                    <div class="step-label">Personal</div>
                </div>
                <div class="step-item" data-step="2">
                    <div class="step-circle">2</div>
                    <div class="step-label">Agency</div>
                </div>
                <div class="step-item" data-step="3">
                    <div class="step-circle">3</div>
                    <div class="step-label">Preferences</div>
                </div>
                <div class="step-item" data-step="4">
                    <div class="step-circle">4</div>
                    <div class="step-label">Consent</div>
                </div>
            </div>

            <form method="POST" action="agent_setup.php" enctype="multipart/form-data" id="wizardForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <!-- STEP 1: Personal Information -->
                <div class="wizard-panel active" id="step1">
                    <h3 style="margin-top: 0; color: #1e293b; font-size: 17px; margin-bottom: 15px;">
                        <i class="fas fa-user-circle"></i> Step 1: Personal Information
                    </h3>

                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="name">Full Name *</label>
                        <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($data['name'] ?? ''); ?>">
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div class="form-group">
                            <label>Mobile Number (Verified)</label>
                            <input type="text" value="<?php echo htmlspecialchars($data['phone'] ?? ''); ?>" disabled style="background: #f1f5f9; cursor: not-allowed;">
                        </div>
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="text" value="<?php echo htmlspecialchars($data['email'] ?? ''); ?>" disabled style="background: #f1f5f9; cursor: not-allowed;">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 25px;">
                        <label for="profile_photo">Profile Photo <span style="font-weight: normal; color: #888;">(JPG, PNG or WebP)</span></label>
                        <input type="file" id="profile_photo" name="profile_photo" accept="image/jpeg,image/png,image/webp">
                    </div>

                    <div style="display: flex; justify-content: flex-end;">
                        <button type="button" class="btn btn-primary" onclick="goToStep(2)" style="padding: 10px 24px;">
                            Next: Business Info <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- STEP 2: Business / Agency Information -->
                <div class="wizard-panel" id="step2">
                    <h3 style="margin-top: 0; color: #1e293b; font-size: 17px; margin-bottom: 15px;">
                        <i class="fas fa-building"></i> Step 2: Agency & Business Details
                    </h3>

                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="agency_name">Agency / Business Name *</label>
                        <input type="text" id="agency_name" name="agency_name" required value="<?php echo htmlspecialchars($data['agency_name'] ?? ''); ?>">
                    </div>

                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="address">Business Address</label>
                        <textarea id="address" name="address" rows="2" placeholder="Office / Shop Address"><?php echo htmlspecialchars($data['address'] ?? ''); ?></textarea>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 15px;">
                        <div class="form-group">
                            <label for="city">City</label>
                            <input type="text" id="city" name="city" placeholder="City" value="<?php echo htmlspecialchars($data['city'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="state">State</label>
                            <input type="text" id="state" name="state" placeholder="State" value="<?php echo htmlspecialchars($data['state'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="pincode">PIN Code</label>
                            <input type="text" id="pincode" name="pincode" placeholder="PIN Code" value="<?php echo htmlspecialchars($data['pincode'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 25px;">
                        <label for="business_logo">Agency Logo <span style="font-weight: normal; color: #888;">(Optional)</span></label>
                        <input type="file" id="business_logo" name="business_logo" accept="image/jpeg,image/png,image/webp,image/svg+xml">
                    </div>

                    <div style="display: flex; justify-content: space-between;">
                        <button type="button" class="btn btn-secondary" onclick="goToStep(1)">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                        <button type="button" class="btn btn-primary" onclick="goToStep(3)" style="padding: 10px 24px;">
                            Next: Preferences <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- STEP 3: Notification Preferences -->
                <div class="wizard-panel" id="step3">
                    <h3 style="margin-top: 0; color: #1e293b; font-size: 17px; margin-bottom: 15px;">
                        <i class="fas fa-bell"></i> Step 3: Notification Preferences
                    </h3>
                    <p style="color: #64748b; font-size: 13.5px; margin-bottom: 20px;">
                        Choose which channels you want our automated system to use for client renewal reminders and alerts.
                    </p>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; margin-bottom: 15px;">
                        <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                            <input type="checkbox" name="whatsapp_consent" value="1" <?php echo ($data['whatsapp_consent'] ?? 1) ? 'checked' : ''; ?> style="width: 20px; height: 20px; margin-top: 2px;">
                            <div>
                                <strong style="color: #0f172a; font-size: 15px;"><i class="fab fa-whatsapp" style="color: #25d366;"></i> WhatsApp Notifications</strong>
                                <p style="margin: 3px 0 0; color: #64748b; font-size: 13px;">
                                    Send automated WhatsApp reminders to customers 30, 14, 7, 2, and 0 days prior to policy expiry.
                                </p>
                            </div>
                        </label>
                    </div>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; margin-bottom: 25px;">
                        <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                            <input type="checkbox" name="email_consent" value="1" <?php echo ($data['email_consent'] ?? 1) ? 'checked' : ''; ?> style="width: 20px; height: 20px; margin-top: 2px;">
                            <div>
                                <strong style="color: #0f172a; font-size: 15px;"><i class="fas fa-envelope" style="color: #0074D9;"></i> Email Alerts & Statements</strong>
                                <p style="margin: 3px 0 0; color: #64748b; font-size: 13px;">
                                    Receive daily renewal summary emails and send renewal notices to customer email inboxes.
                                </p>
                            </div>
                        </label>
                    </div>

                    <div style="display: flex; justify-content: space-between;">
                        <button type="button" class="btn btn-secondary" onclick="goToStep(2)">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                        <button type="button" class="btn btn-primary" onclick="goToStep(4)" style="padding: 10px 24px;">
                            Next: Terms & Consent <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <!-- STEP 4: Terms and Consent -->
                <div class="wizard-panel" id="step4">
                    <h3 style="margin-top: 0; color: #1e293b; font-size: 17px; margin-bottom: 15px;">
                        <i class="fas fa-file-contract"></i> Step 4: Terms and Consent
                    </h3>

                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; height: 180px; overflow-y: auto; font-size: 13px; color: #475569; margin-bottom: 20px; line-height: 1.6;">
                        <p><strong>Agent Operating Agreement & Privacy Standards</strong></p>
                        <p>1. As an authorized insurance agent using this software, you agree to upload only legitimate insurance documents and customer records provided with explicit customer consent.</p>
                        <p>2. You represent that customer telephone and WhatsApp numbers uploaded have opted-in to receive policy renewal notices and compliance intimations.</p>
                        <p>3. AI Policy Scanning extracts information directly from uploaded documents. You remain responsible for reviewing and verifying the extracted values before final policy commitment.</p>
                        <p>4. All policy records, customer data, and uploaded documents are stored securely and remain confidential to your agency account.</p>
                    </div>

                    <div style="margin-bottom: 25px;">
                        <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="accept_terms" value="1" required style="width: 20px; height: 20px; margin-top: 2px;">
                            <span style="font-size: 14px; color: #1e293b;">
                                I agree to the <strong>Terms of Service</strong> and <strong>Privacy Policy</strong>, and certify that customer data is collected with proper consent.
                            </span>
                        </label>
                    </div>

                    <div style="display: flex; justify-content: space-between;">
                        <button type="button" class="btn btn-secondary" onclick="goToStep(3)">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                        <button type="submit" class="btn btn-primary" style="padding: 12px 28px; font-weight: 700; font-size: 15px;">
                            <i class="fas fa-check-double"></i> Complete Setup & Open Dashboard
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
    function goToStep(stepNumber) {
        document.querySelectorAll('.wizard-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.step-item').forEach(s => s.classList.remove('active'));

        const targetPanel = document.getElementById('step' + stepNumber);
        if (targetPanel) targetPanel.classList.add('active');

        document.querySelectorAll('.step-item').forEach(s => {
            if (parseInt(s.dataset.step) <= stepNumber) {
                s.classList.add('active');
            }
        });
    }
    </script>
</body>
</html>
