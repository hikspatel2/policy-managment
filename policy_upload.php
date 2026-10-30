<?php
/**
 * Insurance Policy Document Upload & AI Scanner Launcher
 * Developed for Insurance Management System
 */
require_once 'config.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

if (!$userId || !in_array($role, ['admin', 'manager', 'agent'], true)) {
    header("Location: login.php");
    exit();
}

$conn = getDBConnection();
$branding = getBranding();
$aid = ($role === 'agent') ? (int) (agentIdForUser($conn, $userId) ?? 0) : 0;

// Self-healing: ensure policy_extractions table exists and has nullable agent_id
try {
    $conn->query("CREATE TABLE IF NOT EXISTS `policy_extractions` (
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
      INDEX `idx_pe_agent` (`agent_id`),
      INDEX `idx_pe_status` (`scanning_status`),
      INDEX `idx_pe_policy` (`policy_entry_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @$conn->query("ALTER TABLE `policy_extractions` MODIFY COLUMN `agent_id` BIGINT NULL");
} catch (\Throwable $e) {
}

// Self-healing: ensure customers table has agent_id column
try {
    $chkCol = $conn->query("SHOW COLUMNS FROM customers LIKE 'agent_id'");
    if ($chkCol && $chkCol->num_rows === 0) {
        $conn->query("ALTER TABLE customers ADD COLUMN agent_id BIGINT NULL AFTER id");
    }
} catch (\Throwable $e) {
}

// Enforce Agent Subscription Limit
if ($role === 'agent') {
    try {
        $subLimit = checkAgentSubscriptionLimit($conn, $aid, 'policy');
        if (!empty($subLimit) && isset($subLimit['allowed']) && !$subLimit['allowed']) {
            die("<div style='max-width:600px;margin:50px auto;padding:25px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-family:sans-serif;'>
                <h3 style='color:#991b1b;margin-top:0;'><i class='fas fa-ban'></i> Subscription Limit Reached</h3>
                <p style='color:#7f1d1d;'>" . htmlspecialchars($subLimit['message'] ?? 'Subscription required') . "</p>
                <a href='agent_plans.php' style='display:inline-block;background:#001f3f;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;'>Upgrade Subscription Plan</a>
                <a href='dashboard.php' style='margin-left:10px;color:#64748b;text-decoration:none;'>Return to Dashboard</a>
            </div>");
        }
    } catch (\Throwable $e) {
    }
}

$error = '';
$notice = '';

// Pre-selected customer if passed via GET
$selectedCustId = (int) ($_GET['customer_id'] ?? 0);

// Fetch agents list for staff assignment dropdown
$agentsList = [];
if ($role !== 'agent') {
    try {
        $agRes = $conn->query("SELECT id, agent_code, name FROM agents WHERE is_active = 1 ORDER BY name ASC");
        if ($agRes) {
            while ($ag = $agRes->fetch_assoc())
                $agentsList[] = $ag;
        }
    } catch (\Throwable $e) {
        try {
            $agRes = $conn->query("SELECT id, agent_code, name FROM agents ORDER BY name ASC");
            if ($agRes) {
                while ($ag = $agRes->fetch_assoc())
                    $agentsList[] = $ag;
            }
        } catch (\Throwable $e2) {
            $agentsList = [];
        }
    }
}

// Fetch customers list for this agent (or all for staff)
$customers = [];
try {
    if ($role === 'agent' && $aid > 0) {
        $custQuery = "SELECT id, name, mobile, email FROM customers WHERE agent_id = $aid AND (is_active = 1 OR is_active IS NULL) ORDER BY name ASC";
    } else {
        $custQuery = "SELECT id, name, mobile, email FROM customers WHERE (is_active = 1 OR is_active IS NULL) ORDER BY name ASC";
    }
    $custRes = $conn->query($custQuery);
    if ($custRes) {
        while ($r = $custRes->fetch_assoc())
            $customers[] = $r;
    }
} catch (\Throwable $e) {
    try {
        $custRes = $conn->query("SELECT id, name, mobile, email FROM customers ORDER BY name ASC");
        if ($custRes) {
            while ($r = $custRes->fetch_assoc())
                $customers[] = $r;
        }
    } catch (\Throwable $e2) {
        $customers = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $newCustName = trim($_POST['new_customer_name'] ?? '');
        $newCustMobile = preg_replace('/[^0-9]/', '', trim($_POST['new_customer_mobile'] ?? ''));
        $newCustEmail = trim($_POST['new_customer_email'] ?? '');
        $category = trim($_POST['insurance_category'] ?? 'motor');
        $runAiScan = isset($_POST['run_ai_scan']) ? 1 : 0;

        // If creating a new customer on the fly
        if ($customerId === 0 && !empty($newCustName)) {
            if (strlen($newCustMobile) < 10) {
                $error = 'Please enter a valid 10-digit mobile number for the new customer.';
            } else {
                // Check agent customer limits
                if ($role === 'agent') {
                    try {
                        $cLimit = checkAgentSubscriptionLimit($conn, $aid, 'customer');
                        if (!$cLimit['allowed']) {
                            $error = $cLimit['message'];
                        }
                    } catch (\Throwable $e) {
                    }
                }

                if (!$error) {
                    try {
                        $agentVal = ($aid > 0) ? $aid : null;
                        $insCust = $conn->prepare("INSERT INTO customers (name, mobile, email, agent_id, primary_wa_consent, consent_timestamp, consent_source, is_active) VALUES (?, ?, ?, ?, 1, NOW(), 'policy_upload', 1)");
                        if ($insCust) {
                            $insCust->bind_param("sssi", $newCustName, $newCustMobile, $newCustEmail, $agentVal);
                            $insCust->execute();
                            $customerId = $conn->insert_id;
                            $insCust->close();
                        }
                    } catch (\Throwable $e) {
                        try {
                            $insCust = $conn->prepare("INSERT INTO customers (name, mobile, email) VALUES (?, ?, ?)");
                            if ($insCust) {
                                $insCust->bind_param("sss", $newCustName, $newCustMobile, $newCustEmail);
                                $insCust->execute();
                                $customerId = $conn->insert_id;
                                $insCust->close();
                            }
                        } catch (\Throwable $e2) {
                            $error = 'Failed to register customer: ' . $e2->getMessage();
                        }
                    }
                }
            }
        }

        if (!$customerId && !$error) {
            $error = 'Please select an existing customer or enter new customer details.';
        } elseif (!isset($_FILES['policy_document']) || $_FILES['policy_document']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please select a valid policy document (PDF or image).';
        } else {
            // Validate file extension and MIME type
            $origName = $_FILES['policy_document']['name'];
            $fileSize = $_FILES['policy_document']['size'];
            $tmpPath = $_FILES['policy_document']['tmp_name'];

            if ($fileSize > 15 * 1024 * 1024) {
                $error = 'Policy document exceeds maximum allowed size of 15 MB.';
            } else {
                $fileExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if ($fileExt === 'jpeg')
                    $fileExt = 'jpg';
                $allowedExts = ['pdf', 'jpg', 'png', 'webp'];

                if (!in_array($fileExt, $allowedExts, true)) {
                    $error = 'Invalid file type (.' . htmlspecialchars($fileExt) . '). Only PDF, JPG, and PNG documents are supported.';
                } else {
                    $ext = $fileExt;
                    $storedName = storedName('pol', $ext);
                    $relPath = 'uploads/policies/' . $storedName;
                    $fullPath = __DIR__ . '/' . $relPath;

                    if (!is_dir(__DIR__ . '/uploads/policies')) {
                        mkdir(__DIR__ . '/uploads/policies', 0755, true);
                    }

                    if (!move_uploaded_file($tmpPath, $fullPath)) {
                        $error = 'Failed to save uploaded file to storage. Check folder permissions.';
                    } else {
                        // Safe agent assignment: use logged-in agent ID, or selected agent for staff, or NULL
                        $assignedAgentId = ($role === 'agent') ? $aid : (!empty($_POST['agent_id']) ? (int) $_POST['agent_id'] : null);
                        if ($assignedAgentId && $assignedAgentId <= 0)
                            $assignedAgentId = null;

                        // Insert policy extraction record
                        try {
                            if ($assignedAgentId) {
                                $insExt = $conn->prepare("INSERT INTO policy_extractions (agent_id, customer_id, file_path, scanning_status) VALUES (?, ?, ?, 'uploaded')");
                                $insExt->bind_param("iis", $assignedAgentId, $customerId, $relPath);
                            } else {
                                $insExt = $conn->prepare("INSERT INTO policy_extractions (agent_id, customer_id, file_path, scanning_status) VALUES (NULL, ?, ?, 'uploaded')");
                                $insExt->bind_param("is", $customerId, $relPath);
                            }
                            $insExt->execute();
                            $extId = $conn->insert_id;
                            $insExt->close();

                            logActivity($userId, $_SESSION['username'] ?? '', 'Upload Policy', "Uploaded document for Customer #{$customerId}, Extraction #{$extId}");

                            $conn->close();

                            if ($runAiScan) {
                                header("Location: ai_scanner.php?ext_id=" . $extId . "&category=" . urlencode($category) . "&auto=1");
                            } else {
                                header("Location: policy_verify.php?ext_id=" . $extId . "&category=" . urlencode($category));
                            }
                            exit();
                        } catch (\Throwable $e) {
                            $error = 'Failed to create policy extraction record: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}

$username = $_SESSION['username'] ?? 'User';
$current_page = 'policy_upload';
$user_id = $userId;

// Fetch recent 5 policy extractions for quick history widget
$recentExtractions = [];
try {
    $rSql = "SELECT pe.id, pe.file_path, pe.scanning_status, pe.created_at, pe.policy_entry_id,
                    c.name AS customer_name, c.mobile AS customer_mobile
             FROM policy_extractions pe
             LEFT JOIN customers c ON c.id = pe.customer_id ";
    if ($role === 'agent' && $aid > 0) {
        $rSql .= " WHERE pe.agent_id = $aid ";
    }
    $rSql .= " ORDER BY pe.id DESC LIMIT 5";
    $rRes = $conn->query($rSql);
    if ($rRes) {
        while ($row = $rRes->fetch_assoc()) $recentExtractions[] = $row;
    }
} catch (\Throwable $e) {}

$csrf_token = generateCSRFToken();
$categories = getInsuranceCategories($conn, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="icon" href="<?php echo htmlspecialchars(getBranding()['site_logo']); ?>">
    <title>Upload & Scan Policy — <?php echo htmlspecialchars($branding['site_name']); ?></title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=16.0">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="app.js?v=2.3"></script>

    <style>
        /* ===== MODERN SAAS UPLOAD & SCAN UI ===== */
        .upload-page-wrapper {
            padding: 8px 0 30px;
            max-width: 1280px;
            margin: 0 auto;
        }

        /* Workflow Stepper */
        .stepper-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 18px 24px;
            margin-bottom: 24px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }
        body.dark-mode .stepper-card {
            background: #1e293b;
            border-color: #334155;
        }
        .step-pill {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }
        .step-icon-badge {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
            background: #f1f5f9;
            color: #64748b;
            transition: all 0.25s ease;
            flex-shrink: 0;
        }
        .step-pill.active .step-icon-badge {
            background: linear-gradient(135deg, #0074D9 0%, #001f3f 100%);
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(0, 116, 217, 0.35);
        }
        .step-pill.next .step-icon-badge {
            background: #f8fafc;
            color: #94a3b8;
            border: 1px dashed #cbd5e1;
        }
        body.dark-mode .step-pill.next .step-icon-badge {
            background: #0f172a;
            border-color: #475569;
            color: #64748b;
        }
        .step-content {
            display: flex;
            flex-direction: column;
        }
        .step-name {
            font-weight: 700;
            font-size: 14px;
            color: #0f172a;
        }
        body.dark-mode .step-name { color: #f8fafc; }
        .step-desc {
            font-size: 12px;
            color: #64748b;
        }
        .step-divider {
            color: #cbd5e1;
            font-size: 14px;
            padding: 0 10px;
        }
        body.dark-mode .step-divider { color: #475569; }

        /* Main 2-Column Grid */
        .upload-grid {
            display: grid;
            grid-template-columns: 1.8fr 1.2fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 991px) {
            .upload-grid {
                grid-template-columns: 1fr;
            }
            .stepper-card {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
            .step-divider {
                display: none;
            }
        }

        /* Card styling */
        .modern-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.03);
            overflow: hidden;
            margin-bottom: 24px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        body.dark-mode .modern-card {
            background: #1e293b;
            border-color: #334155;
            box-shadow: 0 4px 20px rgba(0,0,0,0.25);
        }
        .modern-card-header {
            padding: 18px 24px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        body.dark-mode .modern-card-header {
            border-bottom-color: #334155;
        }
        .modern-card-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        body.dark-mode .modern-card-title { color: #f8fafc; }
        .modern-card-body {
            padding: 24px;
        }

        /* Interactive Category Tiles */
        .category-tiles-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-top: 6px;
        }
        @media (max-width: 640px) {
            .category-tiles-grid { grid-template-columns: 1fr; }
        }
        .category-tile {
            position: relative;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px 14px;
            text-align: center;
            cursor: pointer;
            transition: all 0.22s ease-in-out;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        body.dark-mode .category-tile {
            background: #0f172a;
            border-color: #334155;
        }
        .category-tile:hover {
            transform: translateY(-2px);
            border-color: #93c5fd;
            box-shadow: 0 6px 16px rgba(0, 116, 217, 0.08);
        }
        .category-tile input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .category-tile.active {
            border-color: #0074D9;
            background: #eff6ff;
            box-shadow: 0 0 0 3px rgba(0, 116, 217, 0.15);
        }
        body.dark-mode .category-tile.active {
            background: rgba(0, 116, 217, 0.15);
            border-color: #38bdf8;
        }
        .category-tile-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 2px;
            transition: transform 0.2s ease;
        }
        .category-tile:hover .category-tile-icon {
            transform: scale(1.1);
        }
        .cat-icon-motor { background: #e0f2fe; color: #0284c7; }
        .cat-icon-health { background: #dcfce7; color: #16a34a; }
        .cat-icon-life { background: #f3e8ff; color: #9333ea; }
        .cat-icon-default { background: #fef3c7; color: #d97706; }

        .category-tile-label {
            font-weight: 700;
            font-size: 14px;
            color: #1e293b;
        }
        body.dark-mode .category-tile-label { color: #f8fafc; }
        .category-tile-check {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 14px;
            color: #0074D9;
            opacity: 0;
            transform: scale(0.6);
            transition: all 0.2s ease;
        }
        .category-tile.active .category-tile-check {
            opacity: 1;
            transform: scale(1);
        }

        /* Segmented Customer Tab */
        .customer-seg-tabs {
            display: flex;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 10px;
            margin-bottom: 18px;
            border: 1px solid #e2e8f0;
        }
        body.dark-mode .customer-seg-tabs {
            background: #0f172a;
            border-color: #334155;
        }
        .cust-tab-btn {
            flex: 1;
            padding: 9px 14px;
            text-align: center;
            font-size: 13.5px;
            font-weight: 600;
            color: #64748b;
            border-radius: 8px;
            cursor: pointer;
            border: none;
            background: transparent;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        body.dark-mode .cust-tab-btn { color: #94a3b8; }
        .cust-tab-btn.active {
            background: #ffffff;
            color: #001f3f;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        body.dark-mode .cust-tab-btn.active {
            background: #1e293b;
            color: #f8fafc;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }

        /* Form Inputs */
        .form-input-box {
            position: relative;
        }
        .form-input-box i.input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 14px;
        }
        .form-input-box input, .form-input-box select {
            width: 100%;
            padding: 11px 14px 11px 40px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            font-size: 14px;
            color: #1e293b;
            background: #ffffff;
            transition: all 0.2s ease;
            outline: none;
            box-sizing: border-box;
        }
        body.dark-mode .form-input-box input, body.dark-mode .form-input-box select {
            background: #0f172a;
            border-color: #334155;
            color: #f8fafc;
        }
        .form-input-box input:focus, .form-input-box select:focus {
            border-color: #0074D9;
            box-shadow: 0 0 0 3px rgba(0, 116, 217, 0.12);
        }

        /* Modern Upload Dropzone */
        .modern-dropzone {
            border: 2px dashed #94a3b8;
            border-radius: 14px;
            padding: 38px 20px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.22s ease-in-out;
            position: relative;
        }
        body.dark-mode .modern-dropzone {
            background: #0f172a;
            border-color: #475569;
        }
        .modern-dropzone:hover, .modern-dropzone.dragover {
            border-color: #0074D9;
            background: #eff6ff;
            transform: scale(1.005);
        }
        body.dark-mode .modern-dropzone:hover, body.dark-mode .modern-dropzone.dragover {
            background: rgba(0, 116, 217, 0.12);
            border-color: #38bdf8;
        }
        .dropzone-icon-wrap {
            width: 64px;
            height: 64px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: #e0f2fe;
            color: #0284c7;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            transition: all 0.25s ease;
        }
        .modern-dropzone:hover .dropzone-icon-wrap {
            transform: translateY(-4px);
            background: #0074D9;
            color: #ffffff;
            box-shadow: 0 6px 18px rgba(0, 116, 217, 0.3);
        }

        /* File Selected Banner */
        .file-selected-card {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 12px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 14px;
            animation: fadeIn 0.3s ease;
        }
        body.dark-mode .file-selected-card {
            background: rgba(22, 163, 74, 0.15);
            border-color: #166534;
        }

        /* AI Feature Highlight Card */
        .ai-banner-box {
            background: linear-gradient(135deg, #f0fdf4 0%, #e0f2fe 100%);
            border: 1px solid #bbf7d0;
            border-radius: 14px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }
        body.dark-mode .ai-banner-box {
            background: linear-gradient(135deg, #064e3b 0%, #0c4a6e 100%);
            border-color: #047857;
        }
        .switch-toggle {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 26px;
            flex-shrink: 0;
        }
        .switch-toggle input { opacity: 0; width: 0; height: 0; }
        .switch-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            transition: .3s;
            border-radius: 34px;
        }
        .switch-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        .switch-toggle input:checked + .switch-slider {
            background-color: #16a34a;
        }
        .switch-toggle input:checked + .switch-slider:before {
            transform: translateX(22px);
        }

        /* Recent Extractions Table/List */
        .extraction-item {
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #f1f5f9;
            background: #ffffff;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            transition: background 0.2s ease;
        }
        body.dark-mode .extraction-item {
            background: #0f172a;
            border-color: #334155;
        }
        .extraction-item:hover {
            background: #f8fafc;
        }
        body.dark-mode .extraction-item:hover {
            background: #1e293b;
        }

        /* Submit Button */
        .btn-upload-submit {
            background: linear-gradient(135deg, #0074D9 0%, #001f3f 100%);
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            padding: 14px 28px;
            border-radius: 10px;
            border: none;
            width: 100%;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(0, 116, 217, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.25s ease;
        }
        .btn-upload-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 116, 217, 0.45);
            background: linear-gradient(135deg, #0284c7 0%, #001f3f 100%);
        }
        .btn-upload-submit:active {
            transform: translateY(0);
        }
    </style>
</head>
<body>
<script>/* theme before paint */(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme:dark)').matches))document.body.classList.add('dark-mode');})();</script>
<?php include 'mobile-menu.php'; ?>

<div class="app-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <?php
        $pageIcon = 'fa-file-arrow-up';
        $pageTitle = 'Upload & Scan Policy';
        $crumbs = [
            ['label' => 'Policies', 'icon' => 'fa-file-shield', 'href' => 'policy_entries.php']
        ];
        $pageActions = [
            ['label' => 'Policy List', 'icon' => 'fa-list', 'onclick' => "window.location='policy_entries.php'"],
            ['label' => 'Dashboard', 'icon' => 'fa-gauge-high', 'onclick' => "window.location='dashboard.php'"]
        ];
        include 'page_header.php';
        ?>

        <div class="upload-page-wrapper">
            <!-- 3-STEP PROGRESS BANNER -->
            <div class="stepper-card">
                <div class="step-pill active">
                    <div class="step-icon-badge"><i class="fas fa-file-arrow-up"></i></div>
                    <div class="step-content">
                        <span class="step-name">1. Upload & Client</span>
                        <span class="step-desc">Document & customer details</span>
                    </div>
                </div>
                <div class="step-divider"><i class="fas fa-arrow-right"></i></div>
                <div class="step-pill next">
                    <div class="step-icon-badge"><i class="fas fa-microchip"></i></div>
                    <div class="step-content">
                        <span class="step-name">2. AI OCR Scanning</span>
                        <span class="step-desc">Instant data extraction</span>
                    </div>
                </div>
                <div class="step-divider"><i class="fas fa-arrow-right"></i></div>
                <div class="step-pill next">
                    <div class="step-icon-badge"><i class="fas fa-clipboard-check"></i></div>
                    <div class="step-content">
                        <span class="step-name">3. Verify & Remind</span>
                        <span class="step-desc">Commit policy & schedules</span>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="error" style="margin-bottom: 20px; border-radius: 10px; padding: 14px 18px; display: flex; align-items: center; gap: 10px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 18px;"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error']) && $_GET['error'] === 'not_found'): ?>
                <div class="error" style="margin-bottom: 20px; border-radius: 10px; padding: 14px 18px; display: flex; align-items: center; gap: 10px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">
                    <i class="fas fa-info-circle" style="font-size: 18px;"></i>
                    <div>The requested extraction record was not found or has been completed.</div>
                </div>
            <?php endif; ?>

            <form method="POST" action="policy_upload.php" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="upload-grid">
                    <!-- LEFT COLUMN: PRIMARY INPUT FORM -->
                    <div class="upload-left-col">
                        <!-- Insurance Category Selection -->
                        <div class="modern-card">
                            <div class="modern-card-header">
                                <h3 class="modern-card-title">
                                    <i class="fas fa-shield-halved" style="color: #0074D9;"></i>
                                    1. Select Insurance Category
                                </h3>
                                <span class="badge" style="font-size: 11px; background: #e0f2fe; color: #0369a1; padding: 4px 10px; border-radius: 20px;">Required</span>
                            </div>
                            <div class="modern-card-body">
                                <div class="category-tiles-grid">
                                    <?php foreach ($categories as $cat):
                                        $catCode = htmlspecialchars($cat['code']);
                                        $isMotor = ($cat['code'] === 'motor');
                                        $isHealth = ($cat['code'] === 'health');
                                        $isLife = ($cat['code'] === 'life');
                                        $iconClass = $isMotor ? 'cat-icon-motor' : ($isHealth ? 'cat-icon-health' : ($isLife ? 'cat-icon-life' : 'cat-icon-default'));
                                    ?>
                                        <label class="category-tile <?php echo $isMotor ? 'active' : ''; ?>" id="tile_<?php echo $catCode; ?>" onclick="selectCategoryTile('<?php echo $catCode; ?>')">
                                            <input type="radio" name="insurance_category" value="<?php echo $catCode; ?>" <?php echo $isMotor ? 'checked' : ''; ?>>
                                            <div class="category-tile-check"><i class="fas fa-circle-check"></i></div>
                                            <div class="category-tile-icon <?php echo $iconClass; ?>">
                                                <i class="fas <?php echo htmlspecialchars($cat['icon']); ?>"></i>
                                            </div>
                                            <div class="category-tile-label"><?php echo htmlspecialchars($cat['name']); ?></div>
                                            <small style="font-size: 11px; color: #64748b;">
                                                <?php
                                                if ($isMotor) echo 'Car, Bike, CV';
                                                elseif ($isHealth) echo 'Mediclaim, Floater';
                                                elseif ($isLife) echo 'Term, ULIP, Life';
                                                else echo 'General Cover';
                                                ?>
                                            </small>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Customer Details & Agent Assignment -->
                        <div class="modern-card">
                            <div class="modern-card-header">
                                <h3 class="modern-card-title">
                                    <i class="fas fa-user-circle" style="color: #0074D9;"></i>
                                    2. Client & Assignment Details
                                </h3>
                            </div>
                            <div class="modern-card-body">
                                <?php if ($role !== 'agent'): ?>
                                    <!-- Staff Agent Assignment -->
                                    <div class="form-group" style="margin-bottom: 20px;">
                                        <label style="font-weight: 600; font-size: 13.5px; margin-bottom: 6px; display: block;">
                                            <i class="fas fa-user-tie" style="color: #64748b;"></i> Assign to Agent (Optional)
                                        </label>
                                        <div class="form-input-box">
                                            <i class="fas fa-id-badge input-icon"></i>
                                            <select name="agent_id" id="agent_id">
                                                <option value="">-- Direct Company / In-House Policy (No Agent) --</option>
                                                <?php foreach ($agentsList as $ag): ?>
                                                    <option value="<?php echo $ag['id']; ?>">
                                                        <?php echo htmlspecialchars($ag['name']); ?> (<?php echo htmlspecialchars($ag['agent_code']); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Segmented Tab: Existing Customer vs New Customer -->
                                <div class="customer-seg-tabs">
                                    <button type="button" class="cust-tab-btn active" id="tabExisting" onclick="switchCustTab('existing')">
                                        <i class="fas fa-users"></i> Select Existing Customer
                                    </button>
                                    <button type="button" class="cust-tab-btn" id="tabNew" onclick="switchCustTab('new')">
                                        <i class="fas fa-user-plus"></i> + Add New Customer
                                    </button>
                                </div>

                                <!-- Existing Customer Dropdown Section -->
                                <div id="secExistingCustomer">
                                    <div class="form-group" style="margin-bottom: 0;">
                                        <label for="customer_id" style="font-weight: 600; font-size: 13.5px; margin-bottom: 6px; display: block;">
                                            Customer Account *
                                        </label>
                                        <div class="form-input-box">
                                            <i class="fas fa-magnifying-glass input-icon"></i>
                                            <select id="customer_id" name="customer_id">
                                                <option value="">-- Search and select registered client --</option>
                                                <?php foreach ($customers as $c): ?>
                                                    <option value="<?php echo $c['id']; ?>" <?php echo ($selectedCustId === (int)$c['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($c['name']); ?> — <?php echo htmlspecialchars($c['mobile']); ?> <?php if (!empty($c['email'])) echo '(' . htmlspecialchars($c['email']) . ')'; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Quick New Customer Form Section -->
                                <div id="secNewCustomer" style="display: none; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 20px;">
                                    <div style="font-size: 13px; font-weight: 700; color: #001f3f; margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
                                        <i class="fas fa-bolt" style="color: #f59e0b;"></i> Quick Client Registration
                                    </div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px;">
                                        <div class="form-group" style="margin: 0;">
                                            <label style="font-size: 12.5px; font-weight: 600; margin-bottom: 4px; display: block;">Full Name *</label>
                                            <div class="form-input-box">
                                                <i class="fas fa-user input-icon"></i>
                                                <input type="text" name="new_customer_name" id="new_customer_name" placeholder="e.g. Mukesh Patel">
                                            </div>
                                        </div>
                                        <div class="form-group" style="margin: 0;">
                                            <label style="font-size: 12.5px; font-weight: 600; margin-bottom: 4px; display: block;">10-Digit Mobile *</label>
                                            <div class="form-input-box">
                                                <i class="fas fa-phone input-icon"></i>
                                                <input type="tel" name="new_customer_mobile" id="new_customer_mobile" placeholder="9876543210" maxlength="10">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group" style="margin: 0;">
                                        <label style="font-size: 12.5px; font-weight: 600; margin-bottom: 4px; display: block;">Email Address (Optional)</label>
                                        <div class="form-input-box">
                                            <i class="fas fa-envelope input-icon"></i>
                                            <input type="email" name="new_customer_email" placeholder="client@example.com">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Policy Document File Dropzone -->
                        <div class="modern-card">
                            <div class="modern-card-header">
                                <h3 class="modern-card-title">
                                    <i class="fas fa-file-pdf" style="color: #dc2626;"></i>
                                    3. Policy Document File *
                                </h3>
                                <span style="font-size: 12px; color: #64748b;">PDF, JPG, PNG (Max 15MB)</span>
                            </div>
                            <div class="modern-card-body">
                                <div class="modern-dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()">
                                    <div class="dropzone-icon-wrap">
                                        <i class="fas fa-cloud-arrow-up"></i>
                                    </div>
                                    <h4 style="margin: 0 0 6px; font-size: 16px; color: #0f172a;" id="dropTitle">
                                        Click to browse or drag & drop policy document here
                                    </h4>
                                    <p style="margin: 0; font-size: 13px; color: #64748b;">
                                        Supports scanned PDFs, multi-page documents, and high-resolution camera photos
                                    </p>
                                    <input type="file" id="fileInput" name="policy_document" accept="application/pdf,image/jpeg,image/png,image/webp" style="display:none;" onchange="handleFileSelected(this)">
                                </div>

                                <!-- Selected File Info Badge -->
                                <div id="fileSelectedBadge" style="display: none;" class="file-selected-card">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div style="width: 38px; height: 38px; border-radius: 8px; background: #dcfce7; color: #15803d; display: flex; align-items: center; justify-content: center; font-size: 18px;">
                                            <i class="fas fa-file-lines" id="fileIconBadge"></i>
                                        </div>
                                        <div>
                                            <div style="font-weight: 700; font-size: 14px; color: #166534;" id="selectedFileName">Document.pdf</div>
                                            <div style="font-size: 12px; color: #15803d;" id="selectedFileSize">1.25 MB</div>
                                        </div>
                                    </div>
                                    <button type="button" onclick="clearSelectedFile()" style="background: none; border: 1px solid #bbf7d0; color: #15803d; border-radius: 6px; padding: 6px 12px; font-size: 12px; cursor: pointer; font-weight: 600;">
                                        <i class="fas fa-rotate"></i> Change File
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- AI Automation Banner & Submit Button -->
                        <div class="ai-banner-box">
                            <label class="switch-toggle">
                                <input type="checkbox" id="run_ai_scan" name="run_ai_scan" value="1" checked>
                                <span class="switch-slider"></span>
                            </label>
                            <div>
                                <strong style="color: #166534; font-size: 14.5px; display: flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-wand-magic-sparkles" style="color: #15803d;"></i>
                                    Automatic AI Policy Extraction
                                </strong>
                                <p style="margin: 3px 0 0; color: #15803d; font-size: 12.5px; line-height: 1.4;">
                                    AI extracts Policy No, Insurer, Premium, Expiry, Coverages & schedules WhatsApp renewal reminders automatically.
                                </p>
                            </div>
                        </div>

                        <button type="submit" class="btn-upload-submit" id="btnSubmit">
                            <i class="fas fa-cloud-arrow-up"></i>
                            <span>Upload & Start AI Verification</span>
                            <i class="fas fa-arrow-right" style="font-size: 13px; margin-left: 4px;"></i>
                        </button>
                    </div>

                    <!-- RIGHT COLUMN: SIDEBAR WIDGETS -->
                    <div class="upload-right-col">
                        <!-- What AI Scans Card -->
                        <div class="modern-card">
                            <div class="modern-card-header">
                                <h3 class="modern-card-title">
                                    <i class="fas fa-sparkles" style="color: #f59e0b;"></i>
                                    How AI Policy Scan Works
                                </h3>
                            </div>
                            <div class="modern-card-body" style="padding: 18px 20px;">
                                <div style="display: flex; flex-direction: column; gap: 14px;">
                                    <div style="display: flex; gap: 12px; align-items: flex-start;">
                                        <div style="width: 32px; height: 32px; border-radius: 8px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0;">
                                            <i class="fas fa-bolt"></i>
                                        </div>
                                        <div>
                                            <strong style="font-size: 13.5px; color: #0f172a; display: block;">Instant Optical Extraction</strong>
                                            <span style="font-size: 12.5px; color: #64748b;">Reads PDF text, tables, and images without manual typing.</span>
                                        </div>
                                    </div>

                                    <div style="display: flex; gap: 12px; align-items: flex-start;">
                                        <div style="width: 32px; height: 32px; border-radius: 8px; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0;">
                                            <i class="fas fa-shield-cat"></i>
                                        </div>
                                        <div>
                                            <strong style="font-size: 13.5px; color: #0f172a; display: block;">Field Auto-Fill</strong>
                                            <span style="font-size: 12.5px; color: #64748b;">Insurer, Policy Number, OD/TP Premium, GST, IDV & NCB%.</span>
                                        </div>
                                    </div>

                                    <div style="display: flex; gap: 12px; align-items: flex-start;">
                                        <div style="width: 32px; height: 32px; border-radius: 8px; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0;">
                                            <i class="fas fa-bell"></i>
                                        </div>
                                        <div>
                                            <strong style="font-size: 13.5px; color: #0f172a; display: block;">Automated Reminders</strong>
                                            <span style="font-size: 12.5px; color: #64748b;">Schedules WhatsApp & Email renewal alerts at 30, 15, 7, and 1 days before expiry.</span>
                                        </div>
                                    </div>

                                    <div style="display: flex; gap: 12px; align-items: flex-start;">
                                        <div style="width: 32px; height: 32px; border-radius: 8px; background: #f3e8ff; color: #9333ea; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0;">
                                            <i class="fas fa-user-check"></i>
                                        </div>
                                        <div>
                                            <strong style="font-size: 13.5px; color: #0f172a; display: block;">Human-in-the-Loop</strong>
                                            <span style="font-size: 12.5px; color: #64748b;">You can review, edit, or adjust all extracted fields side-by-side with original document before saving.</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Policy Extractions Widget -->
                        <div class="modern-card">
                            <div class="modern-card-header">
                                <h3 class="modern-card-title">
                                    <i class="fas fa-clock-rotate-left" style="color: #64748b;"></i>
                                    Recent Extractions
                                </h3>
                                <a href="policy_entries.php" style="font-size: 12px; color: #0074D9; text-decoration: none; font-weight: 600;">View All</a>
                            </div>
                            <div class="modern-card-body" style="padding: 14px 18px;">
                                <?php if (empty($recentExtractions)): ?>
                                    <div style="text-align: center; padding: 25px 10px; color: #94a3b8;">
                                        <i class="fas fa-file-circle-question" style="font-size: 32px; margin-bottom: 8px; opacity: 0.6;"></i>
                                        <p style="margin: 0; font-size: 13px;">No recent policy extractions yet.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recentExtractions as $rx):
                                        $status = $rx['scanning_status'] ?? 'uploaded';
                                        $badgeBg = '#f1f5f9'; $badgeColor = '#475569';
                                        if ($status === 'completed') { $badgeBg = '#dcfce7'; $badgeColor = '#15803d'; }
                                        elseif ($status === 'processing') { $badgeBg = '#e0f2fe'; $badgeColor = '#0369a1'; }
                                        elseif ($status === 'manual_review') { $badgeBg = '#fef3c7'; $badgeColor = '#b45309'; }
                                    ?>
                                        <div class="extraction-item">
                                            <div style="min-width: 0; flex: 1;">
                                                <div style="font-weight: 700; font-size: 13.5px; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                    <?php echo htmlspecialchars($rx['customer_name'] ?? 'Direct Upload'); ?>
                                                </div>
                                                <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">
                                                    #<?php echo $rx['id']; ?> • <?php echo date('d M, h:i A', strtotime($rx['created_at'])); ?>
                                                </div>
                                            </div>
                                            <div style="text-align: right; flex-shrink: 0;">
                                                <span style="display: inline-block; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 3px 8px; border-radius: 6px; background: <?php echo $badgeBg; ?>; color: <?php echo $badgeColor; ?>;">
                                                    <?php echo htmlspecialchars($status); ?>
                                                </span>
                                                <div style="margin-top: 4px;">
                                                    <a href="policy_verify.php?ext_id=<?php echo $rx['id']; ?>" style="font-size: 11.5px; color: #0074D9; text-decoration: none; font-weight: 600;">
                                                        Review <i class="fas fa-chevron-right" style="font-size: 9px;"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function selectCategoryTile(code) {
    document.querySelectorAll('.category-tile').forEach(t => t.classList.remove('active'));
    const tile = document.getElementById('tile_' + code);
    if (tile) {
        tile.classList.add('active');
        const radio = tile.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
    }
}

function switchCustTab(tab) {
    const tabExist = document.getElementById('tabExisting');
    const tabNew = document.getElementById('tabNew');
    const secExist = document.getElementById('secExistingCustomer');
    const secNew = document.getElementById('secNewCustomer');
    const custSelect = document.getElementById('customer_id');

    if (tab === 'existing') {
        tabExist.classList.add('active');
        tabNew.classList.remove('active');
        secExist.style.display = 'block';
        secNew.style.display = 'none';
        const nName = document.getElementById('new_customer_name');
        if (nName) nName.value = '';
        const nMob = document.getElementById('new_customer_mobile');
        if (nMob) nMob.value = '';
    } else {
        tabNew.classList.add('active');
        tabExist.classList.remove('active');
        secExist.style.display = 'none';
        secNew.style.display = 'block';
        if (custSelect) custSelect.value = '';
    }
}

// Drag & Drop interactions
const dropzone = document.getElementById('dropzone');
['dragenter', 'dragover'].forEach(event => {
    dropzone.addEventListener(event, (e) => { e.preventDefault(); dropzone.classList.add('dragover'); });
});
['dragleave', 'drop'].forEach(event => {
    dropzone.addEventListener(event, (e) => { e.preventDefault(); dropzone.classList.remove('dragover'); });
});
dropzone.addEventListener('drop', (e) => {
    const files = e.dataTransfer.files;
    if (files.length) {
        document.getElementById('fileInput').files = files;
        handleFileSelected(document.getElementById('fileInput'));
    }
});

function handleFileSelected(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        document.getElementById('selectedFileName').textContent = file.name;
        document.getElementById('selectedFileSize').textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
        document.getElementById('fileSelectedBadge').style.display = 'flex';
        document.getElementById('dropTitle').textContent = 'Selected: ' + file.name;

        // Change icon badge based on file type
        const ext = file.name.split('.').pop().toLowerCase();
        const iconBadge = document.getElementById('fileIconBadge');
        if (ext === 'pdf') {
            iconBadge.className = 'fas fa-file-pdf';
        } else if (['jpg', 'jpeg', 'png', 'webp'].includes(ext)) {
            iconBadge.className = 'fas fa-file-image';
        } else {
            iconBadge.className = 'fas fa-file-lines';
        }
    }
}

function clearSelectedFile() {
    const input = document.getElementById('fileInput');
    input.value = '';
    document.getElementById('fileSelectedBadge').style.display = 'none';
    document.getElementById('dropTitle').textContent = 'Click to browse or drag & drop policy document here';
}

// Loading state on form submit
document.getElementById('uploadForm').addEventListener('submit', function() {
    const btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading & Analyzing Document...';
});
</script>
</body>
</html>