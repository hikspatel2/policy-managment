<?php
/**
 * Insurance Policy Document Upload, AI Scanner & Direct Manual Entry
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
$username = $_SESSION['username'] ?? 'User';
$current_page = 'policy_upload';
$user_id = $userId;

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
} catch (\Throwable $e) {}

// Self-healing: ensure customers table has agent_id column
try {
    $chkCol = $conn->query("SHOW COLUMNS FROM customers LIKE 'agent_id'");
    if ($chkCol && $chkCol->num_rows === 0) {
        $conn->query("ALTER TABLE customers ADD COLUMN agent_id BIGINT NULL AFTER id");
    }
} catch (\Throwable $e) {}

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
    } catch (\Throwable $e) {}
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
            while ($ag = $agRes->fetch_assoc()) $agentsList[] = $ag;
        }
    } catch (\Throwable $e) {
        try {
            $agRes = $conn->query("SELECT id, agent_code, name FROM agents ORDER BY name ASC");
            if ($agRes) {
                while ($ag = $agRes->fetch_assoc()) $agentsList[] = $ag;
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
        while ($r = $custRes->fetch_assoc()) $customers[] = $r;
    }
} catch (\Throwable $e) {
    try {
        $custRes = $conn->query("SELECT id, name, mobile, email FROM customers ORDER BY name ASC");
        if ($custRes) {
            while ($r = $custRes->fetch_assoc()) $customers[] = $r;
        }
    } catch (\Throwable $e2) {
        $customers = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $entryMode = trim($_POST['entry_mode'] ?? 'scan');
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
                        if (!empty($cLimit) && isset($cLimit['allowed']) && !$cLimit['allowed']) {
                            $error = $cLimit['message'];
                        }
                    } catch (\Throwable $e) {}
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
        } elseif ($entryMode === 'manual') {
            // ==========================================
            // METHOD 2: DIRECT MANUAL POLICY ENTRY
            // ==========================================
            $policyNumber = trim($_POST['policy_number'] ?? '');
            $companyName = trim($_POST['company_name'] ?? '');
            $companyCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $companyName), 0, 10)) ?: 'INS';
            $startDate = trim($_POST['policy_start_date'] ?? date('Y-m-d'));
            $endDate = trim($_POST['policy_end_date'] ?? date('Y-m-d', strtotime('+1 year -1 day')));
            $entryDate = date('Y-m-d');
            $totalPremium = (float)($_POST['total_premium_amount'] ?? 0);
            $netPremium = (float)($_POST['net_premium_amount'] ?? $totalPremium);
            $gstAmount = (float)($_POST['gst_amount'] ?? max(0, round($totalPremium - $netPremium, 2)));
            $policyType = trim($_POST['policy_type'] ?? 'comprehensive');
            $businessType = trim($_POST['business_type'] ?? 'new');
            $insuranceCat = $category;

            // Vertical specific details
            $vehicleNumber = trim($_POST['vehicle_number'] ?? '');
            $make = trim($_POST['make'] ?? '');
            $model = trim($_POST['model'] ?? '');
            $idv = !empty($_POST['idv']) ? (float)$_POST['idv'] : 0.00;
            $ncbPercent = !empty($_POST['ncb_percent']) ? (float)$_POST['ncb_percent'] : 0.00;

            $sumInsured = !empty($_POST['sum_insured']) ? (float)$_POST['sum_insured'] : 0.00;
            $planName = trim($_POST['plan_name'] ?? '');
            $tpaName = trim($_POST['tpa_name'] ?? '');
            $membersCount = (int)($_POST['members_count'] ?? 1);
            $membersDetails = trim($_POST['members_details'] ?? '');
            $nomineeName = trim($_POST['nominee_name'] ?? '');
            $nomineeRelation = trim($_POST['nominee_relation'] ?? '');

            if (empty($policyNumber)) {
                $error = 'Policy Number is required for manual entry.';
            } elseif (empty($companyName)) {
                $error = 'Insurance Company Name is required.';
            } elseif ($totalPremium <= 0) {
                $error = 'Total Premium Amount must be greater than zero.';
            } else {
                // Check duplicate policy number
                $chkPol = $conn->prepare("SELECT id FROM policy_entries WHERE policy_number = ? AND deleted_at IS NULL LIMIT 1");
                if ($chkPol) {
                    $chkPol->bind_param("s", $policyNumber);
                    $chkPol->execute();
                    if ($chkPol->get_result()->num_rows > 0) {
                        $error = "A policy with Policy Number '{$policyNumber}' is already registered.";
                    }
                    $chkPol->close();
                }

                if (!$error) {
                    // Fetch customer name snapshot
                    $cNameQ = $conn->query("SELECT name FROM customers WHERE id = $customerId LIMIT 1");
                    $custName = ($cNameQ && ($cr = $cNameQ->fetch_assoc())) ? $cr['name'] : 'Customer';

                    // Vehicle record if motor
                    $vehicleId = null;
                    if ($insuranceCat === 'motor' && !empty($vehicleNumber)) {
                        $vClean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vehicleNumber));
                        $vChk = $conn->prepare("SELECT id FROM vehicles WHERE REPLACE(vehicle_number, ' ', '') = ? LIMIT 1");
                        if ($vChk) {
                            $vChk->bind_param("s", $vClean);
                            $vChk->execute();
                            $vRow = $vChk->get_result()->fetch_assoc();
                            $vChk->close();
                            if ($vRow) {
                                $vehicleId = (int)$vRow['id'];
                            } else {
                                $vIns = $conn->prepare("INSERT INTO vehicles (vehicle_number, customer_id, make, model, is_active) VALUES (?, ?, ?, ?, 1)");
                                if ($vIns) {
                                    $vIns->bind_param("siss", $vehicleNumber, $customerId, $make, $model);
                                    $vIns->execute();
                                    $vehicleId = $conn->insert_id;
                                    $vIns->close();
                                }
                            }
                        }
                    }

                    $branchId = 1;
                    $assignedAgentId = ($role === 'agent') ? $aid : (!empty($_POST['agent_id']) ? (int)$_POST['agent_id'] : null);
                    if ($assignedAgentId && $assignedAgentId <= 0) $assignedAgentId = null;
                    $agentName = $_SESSION['username'] ?? 'Staff';
                    $vehicleType = ($insuranceCat === 'motor') ? 'Motor' : null;

                    $insSql = "INSERT INTO policy_entries (
                        insurance_category, entry_date, branch_id, vehicle_id, vehicle_number, vehicle_type,
                        customer_id, customer_name, agent_id, agent_name, company_name, company_code,
                        policy_number, policy_type, business_type, total_premium_amount, net_premium_amount, gst_amount,
                        idv, ncb_percent, sum_insured, plan_name, tpa_name, members_count, members_details,
                        nominee_name, nominee_relation, policy_start_date, policy_end_date, status,
                        renewal_status, verification_status, verified_by, verified_at, created_by
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, 'active',
                        'original', 'verified', ?, NOW(), ?
                    )";

                    try {
                        $insStmt = $conn->prepare($insSql);
                        $insStmt->bind_param(
                            "ssiisssisissssddddddsssisssssii",
                            $insuranceCat, $entryDate, $branchId, $vehicleId, $vehicleNumber, $vehicleType,
                            $customerId, $custName, $assignedAgentId, $agentName, $companyName, $companyCode,
                            $policyNumber, $policyType, $businessType, $totalPremium, $netPremium, $gstAmount,
                            $idv, $ncbPercent, $sumInsured, $planName, $tpaName, $membersCount, $membersDetails,
                            $nomineeName, $nomineeRelation, $startDate, $endDate, $userId, $userId
                        );
                        $insStmt->execute();
                        $newPolicyId = $conn->insert_id;
                        $insStmt->close();

                        // Handle optional manual policy document upload
                        if (isset($_FILES['manual_document']) && $_FILES['manual_document']['error'] === UPLOAD_ERR_OK) {
                            $mOrig = $_FILES['manual_document']['name'];
                            $mSize = $_FILES['manual_document']['size'];
                            $mTmp = $_FILES['manual_document']['tmp_name'];
                            $mExt = strtolower(pathinfo($mOrig, PATHINFO_EXTENSION));
                            if (in_array($mExt, ['pdf', 'jpg', 'jpeg', 'png', 'webp'])) {
                                $safeDir = __DIR__ . '/uploads/policies/' . date('Y/m');
                                if (!is_dir($safeDir)) @mkdir($safeDir, 0755, true);
                                $mFileName = 'manual_pol_' . $newPolicyId . '_' . time() . '.' . $mExt;
                                $mFullPath = $safeDir . '/' . $mFileName;
                                $mRelPath = 'uploads/policies/' . date('Y/m') . '/' . $mFileName;
                                if (move_uploaded_file($mTmp, $mFullPath)) {
                                    $docStmt = $conn->prepare("INSERT INTO policy_documents (policy_entry_id, document_type, file_name, file_path, file_size, mime_type, uploaded_by) VALUES (?, 'policy_copy', ?, ?, ?, ?, ?)");
                                    if ($docStmt) {
                                        $mMime = function_exists('mime_content_type') ? @mime_content_type($mFullPath) : 'application/octet-stream';
                                        $docStmt->bind_param("issisi", $newPolicyId, $mOrig, $mRelPath, $mSize, $mMime, $userId);
                                        $docStmt->execute();
                                        $docStmt->close();
                                    }
                                }
                            }
                        }

                        // Schedule automated expiry reminders
                        if (function_exists('schedulePolicyReminders')) {
                            try {
                                schedulePolicyReminders($conn, $newPolicyId, $assignedAgentId, $customerId, $endDate);
                            } catch (\Throwable $re) {}
                        }

                        // Initialize CRM Renewal follow-up
                        try {
                            $fu = $conn->prepare("INSERT INTO customer_followups (policy_entry_id, customer_id, agent_id, followup_type, scheduled_date, status, notes) VALUES (?, ?, ?, 'renewal', DATE_SUB(?, INTERVAL 30 DAY), 'pending', 'Direct Manual Entry - Auto 30d renewal follow-up')");
                            if ($fu) {
                                $fu->bind_param("iiis", $newPolicyId, $customerId, $assignedAgentId, $endDate);
                                $fu->execute();
                                $fu->close();
                            }
                        } catch (\Throwable $fe) {}

                        // Increment agent policy count
                        if ($role === 'agent' && $assignedAgentId && function_exists('incrementAgentUsage')) {
                            try { incrementAgentUsage($conn, $assignedAgentId, 'policy'); } catch (\Throwable $e) {}
                        }

                        logActivity($userId, $_SESSION['username'] ?? '', 'Manual Policy Entry', "Created Policy #{$policyNumber} (ID: {$newPolicyId})");
                        $conn->close();

                        header("Location: policy_entries.php?msg=verified&pid=" . $newPolicyId);
                        exit();
                    } catch (\Throwable $e) {
                        $error = 'Failed to create manual policy entry: ' . $e->getMessage();
                    }
                }
            }
        } elseif (!isset($_FILES['policy_document']) || $_FILES['policy_document']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please select a valid policy document (PDF or image).';
        } else {
            // ==========================================
            // METHOD 1: AI PDF / DOCUMENT SCAN
            // ==========================================
            $origName = $_FILES['policy_document']['name'];
            $fileSize = $_FILES['policy_document']['size'];
            $tmpPath = $_FILES['policy_document']['tmp_name'];

            if ($fileSize > 15 * 1024 * 1024) {
                $error = 'Policy document exceeds maximum allowed size of 15 MB.';
            } else {
                $fileExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if ($fileExt === 'jpeg') $fileExt = 'jpg';
                $allowedExts = ['pdf', 'jpg', 'png', 'webp'];

                if (!in_array($fileExt, $allowedExts, true)) {
                    $error = 'Invalid file format. Only PDF, JPG, and PNG documents are supported.';
                } else {
                    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
                    $mimeType = $finfo ? finfo_file($finfo, $tmpPath) : (function_exists('mime_content_type') ? @mime_content_type($tmpPath) : '');
                    if ($finfo) finfo_close($finfo);

                    $allowedMimes = [
                        'application/pdf',
                        'image/jpeg',
                        'image/jpg',
                        'image/png',
                        'image/webp',
                        'application/octet-stream'
                    ];

                    if (!empty($mimeType) && !in_array($mimeType, $allowedMimes, true)) {
                        $error = 'Invalid document type. Please upload a genuine PDF or image file.';
                    } else {
                        $uploadSubdir = 'uploads/policies/' . date('Y/m');
                        $fullUploadDir = __DIR__ . '/' . $uploadSubdir;

                        if (!is_dir($fullUploadDir)) {
                            mkdir($fullUploadDir, 0755, true);
                        }

                        $safeFileName = 'policy_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $fileExt;
                        $fullPath = $fullUploadDir . '/' . $safeFileName;
                        $relPath = $uploadSubdir . '/' . $safeFileName;

                        if (!move_uploaded_file($tmpPath, $fullPath)) {
                            $error = 'Failed to save uploaded file to storage. Check folder permissions.';
                        } else {
                            $assignedAgentId = ($role === 'agent') ? $aid : (!empty($_POST['agent_id']) ? (int) $_POST['agent_id'] : null);
                            if ($assignedAgentId && $assignedAgentId <= 0) $assignedAgentId = null;

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
}

$csrf_token = generateCSRFToken();
$categories = getInsuranceCategories($conn, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Upload Insurance Policy — <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars(getBranding()['site_logo']); ?>">

    <!-- Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=16.0">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="app.js?v=2.3"></script>

    <style>
        /* Modern Centered Card Inside Main Content */
        .upload-card-wrapper {
            max-width: 780px;
            margin: 15px auto 40px;
            padding: 0 10px;
        }

        .upload-clean-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            padding: 36px 40px 32px;
            transition: all 0.25s ease;
        }
        body.dark-mode .upload-clean-card {
            background: #1e293b;
            border-color: #334155;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
        }
        @media (max-width: 600px) {
            .upload-clean-card {
                padding: 22px 18px 24px;
            }
        }

        /* Top Icon & Title */
        .icon-top-badge {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: #e0f2fe;
            color: #0284c7;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin: 0 auto 12px;
        }
        body.dark-mode .icon-top-badge {
            background: #0369a1;
            color: #e0f2fe;
        }
        .card-heading {
            margin: 0 0 6px;
            color: #001f3f;
            font-size: 23px;
            font-weight: 700;
            text-align: center;
            letter-spacing: -0.2px;
        }
        body.dark-mode .card-heading {
            color: #f8fafc;
        }
        .card-subheading {
            color: #64748b;
            font-size: 13.5px;
            margin: 0 auto 22px;
            text-align: center;
            line-height: 1.5;
            max-width: 520px;
        }
        body.dark-mode .card-subheading {
            color: #94a3b8;
        }

        /* Dual Entry Mode Tabs (Scan vs Manual) */
        .entry-mode-bar {
            display: flex;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 10px;
            margin-bottom: 22px;
            border: 1px solid #e2e8f0;
        }
        body.dark-mode .entry-mode-bar {
            background: #0f172a;
            border-color: #334155;
        }
        .mode-tab-btn {
            flex: 1;
            padding: 10px 14px;
            text-align: center;
            font-size: 13.5px;
            font-weight: 700;
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
        body.dark-mode .mode-tab-btn {
            color: #94a3b8;
        }
        .mode-tab-btn.active {
            background: #ffffff;
            color: #001f3f;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        body.dark-mode .mode-tab-btn.active {
            background: #1e293b;
            color: #38bdf8;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }

        /* Section Headings */
        .section-header {
            font-weight: 700;
            color: #0f172a;
            font-size: 13.5px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        body.dark-mode .section-header {
            color: #f1f5f9;
        }
        .section-header i {
            color: #0284c7;
            font-size: 14px;
        }

        /* 3 Category Tiles (Exact Match to Screenshot) */
        .category-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        @media (max-width: 600px) {
            .category-grid {
                grid-template-columns: 1fr;
            }
        }
        .cat-tile {
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.18s;
            background: #ffffff;
            user-select: none;
        }
        body.dark-mode .cat-tile {
            background: #0f172a;
            border-color: #334155;
        }
        .cat-tile:hover {
            border-color: #93c5fd;
            background: #f8fafc;
        }
        body.dark-mode .cat-tile:hover {
            background: #1e293b;
        }
        .cat-tile.active {
            border-color: #0284c7;
            box-shadow: 0 0 0 1px #0284c7;
            background: #f0f7ff;
        }
        body.dark-mode .cat-tile.active {
            background: rgba(2, 132, 199, 0.18);
            border-color: #38bdf8;
            box-shadow: 0 0 0 1px #38bdf8;
        }
        .cat-radio {
            width: 16px;
            height: 16px;
            border: 2px solid #94a3b8;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .cat-tile.active .cat-radio {
            border-color: #0284c7;
        }
        body.dark-mode .cat-tile.active .cat-radio {
            border-color: #38bdf8;
        }
        .cat-radio::after {
            content: '';
            width: 8px;
            height: 8px;
            background: #0284c7;
            border-radius: 50%;
            display: none;
        }
        body.dark-mode .cat-radio::after {
            background: #38bdf8;
        }
        .cat-tile.active .cat-radio::after {
            display: block;
        }
        .cat-icon {
            color: #0284c7;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .cat-title {
            font-size: 13.5px;
            font-weight: 600;
            color: #1e293b;
            white-space: nowrap;
        }
        body.dark-mode .cat-title {
            color: #f1f5f9;
        }

        /* Form Inputs */
        .form-control-input, .form-control-select {
            width: 100%;
            padding: 11px 14px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            font-size: 13.5px;
            color: #0f172a;
            background: #ffffff;
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        body.dark-mode .form-control-input, body.dark-mode .form-control-select {
            background: #0f172a;
            border-color: #334155;
            color: #f8fafc;
        }
        .form-control-input:focus, .form-control-select:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
        }

        /* Quick Add Customer Box */
        .new-cust-box {
            background: #fafbfc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 18px;
            margin-top: 10px;
            margin-bottom: 22px;
        }
        body.dark-mode .new-cust-box {
            background: #0f172a;
            border-color: #334155;
        }
        .new-cust-header {
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        body.dark-mode .new-cust-header {
            color: #e2e8f0;
        }

        /* Dropzone (Exact Match to Screenshot) */
        .dropzone {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 34px 20px;
            text-align: center;
            background: #ffffff;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 6px;
            margin-bottom: 16px;
        }
        body.dark-mode .dropzone {
            background: #0f172a;
            border-color: #475569;
        }
        .dropzone:hover, .dropzone.dragover {
            border-color: #0284c7;
            background: #f0f9ff;
        }
        body.dark-mode .dropzone:hover, body.dark-mode .dropzone.dragover {
            background: rgba(2, 132, 199, 0.12);
            border-color: #38bdf8;
        }
        .dropzone-cloud-icon {
            font-size: 36px;
            color: #0284c7;
            margin-bottom: 8px;
            display: inline-block;
        }
        .dropzone-title {
            margin: 0 0 4px;
            color: #0f172a;
            font-size: 14.5px;
            font-weight: 700;
        }
        body.dark-mode .dropzone-title {
            color: #f8fafc;
        }
        .dropzone-sub {
            margin: 0;
            color: #64748b;
            font-size: 12.5px;
        }

        /* AI Policy Scanning Alert Card */
        .toggle-switch-card {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        body.dark-mode .toggle-switch-card {
            background: rgba(22, 163, 74, 0.12);
            border-color: #166534;
        }
        .toggle-switch-card input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #0284c7;
            cursor: pointer;
            flex-shrink: 0;
        }
        .toggle-title {
            color: #166534;
            font-size: 13.5px;
            font-weight: 700;
            margin: 0 0 2px;
        }
        body.dark-mode .toggle-title {
            color: #86efac;
        }
        .toggle-desc {
            margin: 0;
            color: #15803d;
            font-size: 12px;
            line-height: 1.4;
        }
        body.dark-mode .toggle-desc {
            color: #bbf7d0;
        }

        /* Action Buttons */
        .btn-submit-action {
            width: 100%;
            background: #001f3f;
            color: #ffffff;
            padding: 14px 20px;
            font-size: 15px;
            font-weight: 700;
            border-radius: 9px;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(0, 31, 63, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-submit-action:hover {
            background: #032d5b;
            transform: translateY(-1px);
        }

        /* Manual Form Container */
        .manual-section-card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fafbfc;
            padding: 18px;
            margin-top: 6px;
            margin-bottom: 22px;
        }
        body.dark-mode .manual-section-card {
            background: #0f172a;
            border-color: #334155;
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

        <div class="upload-card-wrapper">
            <div class="upload-clean-card">
                <!-- Center Top Icon & Heading (Exact Match to Screenshot) -->
                <div class="icon-top-badge">
                    <i class="fas fa-file-arrow-up"></i>
                </div>
                <h1 class="card-heading">Upload Insurance Policy</h1>
                <p class="card-subheading">Upload any client policy PDF or image. AI will extract coverage and schedule expiry reminders.</p>

                <?php if ($error): ?>
                    <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; font-size: 13.5px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <!-- Dual Entry Mode Switcher Tabs -->
                <div class="entry-mode-bar">
                    <button type="button" class="mode-tab-btn active" id="btnModeScan" onclick="setEntryMode('scan')">
                        <i class="fas fa-file-arrow-up"></i> AI PDF / Document Scan
                    </button>
                    <button type="button" class="mode-tab-btn" id="btnModeManual" onclick="setEntryMode('manual')">
                        <i class="fas fa-keyboard"></i> Direct Manual Entry
                    </button>
                </div>

                <form method="POST" action="policy_upload.php" enctype="multipart/form-data" id="uploadForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="entry_mode" id="entry_mode" value="scan">
                    <input type="hidden" name="insurance_category" id="selected_insurance_category" value="motor">

                    <!-- 1. Insurance Category (Exact Match to Screenshot) -->
                    <div class="section-header">
                        <i class="fas fa-shield-halved"></i>
                        <span>Insurance Category</span>
                    </div>
                    <div class="category-grid">
                        <div class="cat-tile active" id="card_motor" onclick="selectCategory('motor')">
                            <div class="cat-radio"></div>
                            <div class="cat-icon"><i class="fas fa-car"></i></div>
                            <div class="cat-title">Motor Insurance</div>
                        </div>
                        <div class="cat-tile" id="card_health" onclick="selectCategory('health')">
                            <div class="cat-radio"></div>
                            <div class="cat-icon"><i class="fas fa-heart-pulse"></i></div>
                            <div class="cat-title">Health Insurance</div>
                        </div>
                        <div class="cat-tile" id="card_life" onclick="selectCategory('life')">
                            <div class="cat-radio"></div>
                            <div class="cat-icon"><i class="fas fa-shield-heart"></i></div>
                            <div class="cat-title">Life Insurance</div>
                        </div>
                    </div>

                    <!-- Staff Agent Assignment (Staff/Admin Only) -->
                    <?php if ($role !== 'agent'): ?>
                        <div style="margin-bottom: 18px;">
                            <div class="section-header">
                                <i class="fas fa-user-tie"></i>
                                <span>Assign to Agent (Optional)</span>
                            </div>
                            <select name="agent_id" id="agent_id" class="form-control-select">
                                <option value="">-- Direct Company / In-House Policy (No Agent) --</option>
                                <?php foreach ($agentsList as $ag): ?>
                                    <option value="<?php echo $ag['id']; ?>">
                                        <?php echo htmlspecialchars($ag['name']); ?> (<?php echo htmlspecialchars($ag['agent_code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <!-- 2. Select Existing Customer (Exact Match to Screenshot) -->
                    <div style="margin-bottom: 10px;">
                        <div class="section-header">
                            <i class="fas fa-user"></i>
                            <span>Select Existing Customer</span>
                        </div>
                        <select id="customer_id" name="customer_id" class="form-control-select" onchange="toggleNewCustomerBox(this.value)">
                            <option value="">-- Choose Existing Customer or Add New Below --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo ($selectedCustId === (int)$c['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['name']); ?> — <?php echo htmlspecialchars($c['mobile']); ?> <?php if (!empty($c['email'])) echo '(' . htmlspecialchars($c['email']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 2b. Quick Add Customer Box (Exact Match to Screenshot) -->
                    <div class="new-cust-box" id="newCustBox">
                        <div class="new-cust-header">
                            <i class="fas fa-user-plus" style="color: #0284c7;"></i>
                            <span>+ Or Add New Customer Quickly</span>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 10px;">
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Customer Full Name</label>
                                <input type="text" name="new_customer_name" id="new_customer_name" class="form-control-input" placeholder="e.g. Mukesh Patel">
                            </div>
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Mobile Number</label>
                                <input type="tel" name="new_customer_mobile" id="new_customer_mobile" class="form-control-input" placeholder="10-digit mobile" maxlength="10">
                            </div>
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Email Address (Optional)</label>
                            <input type="email" name="new_customer_email" id="new_customer_email" class="form-control-input" placeholder="mukesh@example.com">
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- MODE 1: AI PDF / DOCUMENT SCAN SECTION         -->
                    <!-- ============================================== -->
                    <div id="secScanMode">
                        <!-- 3. Policy Document File Dropzone (Exact Match to Screenshot) -->
                        <div class="section-header">
                            <i class="fas fa-file-pdf"></i>
                            <span>Policy Document File *</span>
                        </div>

                        <div class="dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()">
                            <i class="fas fa-cloud-arrow-up dropzone-cloud-icon"></i>
                            <h4 class="dropzone-title" id="dropTitle">Click to browse or drag & drop policy file here</h4>
                            <p class="dropzone-sub">Supported formats: PDF, JPG, JPEG, PNG (Max 15 MB)</p>
                            <input type="file" id="fileInput" name="policy_document" accept="application/pdf,image/jpeg,image/png,image/webp" style="display:none;" onchange="handleFileSelected(this)">
                        </div>

                        <!-- Selected File Banner -->
                        <div id="fileSelectedBadge" style="display: none; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px 14px; font-size: 13.5px; margin-bottom: 16px; align-items: center; justify-content: space-between;">
                            <div style="display: flex; align-items: center; gap: 8px; color: #166534; font-weight: 600;">
                                <i class="fas fa-paperclip"></i>
                                <span id="selectedFileName">file.pdf</span>
                                <span style="font-size: 12px; color: #15803d; font-weight: normal;">(<span id="selectedFileSize">0 MB</span>)</span>
                            </div>
                            <button type="button" onclick="clearSelectedFile()" style="background: none; border: 1px solid #86efac; color: #166534; border-radius: 5px; padding: 3px 8px; font-size: 11.5px; cursor: pointer;">
                                Change
                            </button>
                        </div>

                        <!-- Automatic AI Policy Scanning Card (Exact Match to Screenshot) -->
                        <div class="toggle-switch-card">
                            <input type="checkbox" id="run_ai_scan" name="run_ai_scan" value="1" checked>
                            <label for="run_ai_scan" style="cursor: pointer;">
                                <div class="toggle-title">Automatic AI Policy Scanning</div>
                                <div class="toggle-desc">
                                    Instantly extract Policy Number, Premium, Start/End Dates, Insurer, and Insured details for rapid verification.
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- MODE 2: DIRECT MANUAL POLICY ENTRY SECTION     -->
                    <!-- ============================================== -->
                    <div id="secManualMode" style="display: none;">
                        <div class="manual-section-card">
                            <div style="font-size: 13.5px; font-weight: 700; color: #001f3f; margin-bottom: 14px; display: flex; align-items: center; gap: 7px;">
                                <i class="fas fa-file-invoice" style="color: #0284c7;"></i>
                                <span>Policy & Coverage Details (Manual Entry)</span>
                            </div>

                            <!-- Insurer & Policy Number -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Insurance Company *</label>
                                    <input type="text" name="company_name" id="manual_company_name" list="insurerSuggestions" class="form-control-input" placeholder="e.g. HDFC ERGO / ICICI Lombard">
                                    <datalist id="insurerSuggestions">
                                        <option value="HDFC ERGO General Insurance">
                                        <option value="ICICI Lombard General Insurance">
                                        <option value="Bajaj Allianz General Insurance">
                                        <option value="Tata AIG General Insurance">
                                        <option value="Star Health and Allied Insurance">
                                        <option value="The New India Assurance Co. Ltd.">
                                        <option value="Care Health Insurance">
                                        <option value="Go Digit General Insurance">
                                        <option value="National Insurance Company Ltd.">
                                        <option value="United India Insurance Co. Ltd.">
                                        <option value="SBI General Insurance">
                                    </datalist>
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Policy Number *</label>
                                    <input type="text" name="policy_number" id="manual_policy_number" class="form-control-input" placeholder="e.g. 2312/1234567/00/000">
                                </div>
                            </div>

                            <!-- Policy Type & Business Type -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Policy Type</label>
                                    <select name="policy_type" id="manual_policy_type" class="form-control-select">
                                        <option value="comprehensive">Comprehensive Package</option>
                                        <option value="third_party">Third Party (TP) Only</option>
                                        <option value="own_damage">Standalone Own Damage (OD)</option>
                                        <option value="floater">Family Floater</option>
                                        <option value="individual">Individual Mediclaim</option>
                                        <option value="term_life">Term Life Plan</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Business Type</label>
                                    <select name="business_type" id="manual_business_type" class="form-control-select">
                                        <option value="new">New Policy</option>
                                        <option value="renewal">Renewal Policy</option>
                                        <option value="rollover">Rollover Policy</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Dates -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Policy Start Date *</label>
                                    <input type="date" name="policy_start_date" id="manual_start_date" class="form-control-input" value="<?php echo date('Y-m-d'); ?>" onchange="autoCalculateEndDate(this.value)">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">Policy Expiry Date *</label>
                                    <input type="date" name="policy_end_date" id="manual_end_date" class="form-control-input" value="<?php echo date('Y-m-d', strtotime('+1 year -1 day')); ?>">
                                </div>
                            </div>

                            <!-- Premium Calculation Breakdown -->
                            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                                <div style="font-size: 12px; font-weight: 700; color: #001f3f; margin-bottom: 8px;">
                                    <i class="fas fa-calculator" style="color: #0284c7;"></i> Premium Breakdown (₹)
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr 1.2fr; gap: 10px;">
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 3px;">Net Premium</label>
                                    <input type="number" step="0.01" name="net_premium_amount" id="manual_net_premium" class="form-control-input" placeholder="0.00" oninput="calcTotalPremium()">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 3px;">GST (18%)</label>
                                    <input type="number" step="0.01" name="gst_amount" id="manual_gst_amount" class="form-control-input" placeholder="0.00" oninput="calcTotalPremium()">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 700; color: #166534; margin-bottom: 3px;">Total Premium *</label>
                                    <input type="number" step="0.01" name="total_premium_amount" id="manual_total_premium" class="form-control-input" placeholder="0.00" style="font-weight: 700; color: #166534; border-color: #86efac;">
                                </div>
                            </div>
                        </div>

                        <!-- Vertical Specific: Motor -->
                        <div id="secCategoryMotor" style="border: 1px solid #bfdbfe; background: #eff6ff; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                            <div style="font-size: 12px; font-weight: 700; color: #1e40af; margin-bottom: 8px;">
                                <i class="fas fa-car"></i> Motor Vehicle Details
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 8px;">
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 3px;">Vehicle Number</label>
                                    <input type="text" name="vehicle_number" class="form-control-input" placeholder="e.g. GJ01AB1234" style="text-transform: uppercase;">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 3px;">Make & Model</label>
                                    <input type="text" name="make" class="form-control-input" placeholder="e.g. Maruti Swift Dzire">
                                </div>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 3px;">IDV (Insured Value) ₹</label>
                                    <input type="number" step="0.01" name="idv" class="form-control-input" placeholder="550000">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 3px;">NCB %</label>
                                    <input type="number" step="0.01" name="ncb_percent" class="form-control-input" placeholder="20" value="20">
                                </div>
                            </div>
                        </div>

                        <!-- Vertical Specific: Health -->
                        <div id="secCategoryHealth" style="display: none; border: 1px solid #bbf7d0; background: #f0fdf4; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                            <div style="font-size: 12px; font-weight: 700; color: #166534; margin-bottom: 8px;">
                                <i class="fas fa-heart-pulse"></i> Health Coverage Details
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 8px;">
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 3px;">Plan Name</label>
                                    <input type="text" name="plan_name" class="form-control-input" placeholder="e.g. Optima Secure">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 3px;">Sum Insured (₹)</label>
                                    <input type="number" step="0.01" name="sum_insured" class="form-control-input" placeholder="500000">
                                </div>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 3px;">TPA Name</label>
                                    <input type="text" name="tpa_name" class="form-control-input" placeholder="e.g. Medi Assist">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 3px;">Covered Members</label>
                                    <input type="number" name="members_count" class="form-control-input" value="1" min="1">
                                </div>
                            </div>
                        </div>

                        <!-- Vertical Specific: Life -->
                        <div id="secCategoryLife" style="display: none; border: 1px solid #e9d5ff; background: #faf5ff; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                            <div style="font-size: 12px; font-weight: 700; color: #6b21a8; margin-bottom: 8px;">
                                <i class="fas fa-shield-heart"></i> Life Insurance & Nominee Details
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 8px;">
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 3px;">Sum Assured (₹)</label>
                                    <input type="number" step="0.01" name="sum_insured" class="form-control-input" placeholder="5000000">
                                </div>
                                <div>
                                    <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 3px;">Nominee Name</label>
                                    <input type="text" name="nominee_name" class="form-control-input" placeholder="e.g. Sunita Patel">
                                </div>
                            </div>
                            <div>
                                <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 3px;">Nominee Relationship</label>
                                <input type="text" name="nominee_relation" class="form-control-input" placeholder="e.g. Spouse / Son / Daughter">
                            </div>
                        </div>

                        <!-- Optional Attachment -->
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px;">
                                <i class="fas fa-paperclip"></i> Attach Policy Copy (Optional)
                            </label>
                            <input type="file" name="manual_document" class="form-control-input" accept="application/pdf,image/jpeg,image/png,image/webp">
                        </div>
                    </div>
                </div>

                <!-- Submit Button (Exact Match to Screenshot) -->
                <button type="submit" class="btn-submit-action" id="btnSubmit">
                    <i class="fas fa-cloud-arrow-up" id="submitBtnIcon"></i>
                    <span id="submitBtnText">Upload & Start Policy Verification</span>
                    <i class="fas fa-arrow-right" style="font-size: 13px; margin-left: 2px;"></i>
                </button>
            </form>
        </div>
    </div>
</div>
</div>

<script>
let currentEntryMode = 'scan';
let currentCategory = 'motor';

function setEntryMode(mode) {
    currentEntryMode = mode;
    document.getElementById('entry_mode').value = mode;

    const btnScan = document.getElementById('btnModeScan');
    const btnManual = document.getElementById('btnModeManual');
    const secScan = document.getElementById('secScanMode');
    const secManual = document.getElementById('secManualMode');
    const btnSubmit = document.getElementById('btnSubmit');
    const btnText = document.getElementById('submitBtnText');
    const btnIcon = document.getElementById('submitBtnIcon');

    const manualComp = document.getElementById('manual_company_name');
    const manualPol = document.getElementById('manual_policy_number');
    const manualPrem = document.getElementById('manual_total_premium');

    if (mode === 'manual') {
        btnManual.classList.add('active');
        btnScan.classList.remove('active');
        secScan.style.display = 'none';
        secManual.style.display = 'block';

        btnText.textContent = 'Save & Activate Policy';
        btnIcon.className = 'fas fa-check-circle';
        btnSubmit.style.background = '#047857';

        if (manualComp) manualComp.required = true;
        if (manualPol) manualPol.required = true;
        if (manualPrem) manualPrem.required = true;
    } else {
        btnScan.classList.add('active');
        btnManual.classList.remove('active');
        secScan.style.display = 'block';
        secManual.style.display = 'none';

        btnText.textContent = 'Upload & Start Policy Verification';
        btnIcon.className = 'fas fa-cloud-arrow-up';
        btnSubmit.style.background = '#001f3f';

        if (manualComp) manualComp.required = false;
        if (manualPol) manualPol.required = false;
        if (manualPrem) manualPrem.required = false;
    }
}

function selectCategory(code) {
    currentCategory = code;
    document.getElementById('selected_insurance_category').value = code;

    document.querySelectorAll('.cat-tile').forEach(c => c.classList.remove('active'));
    const target = document.getElementById('card_' + code);
    if (target) target.classList.add('active');

    // Toggle category blocks in manual mode
    const secMotor = document.getElementById('secCategoryMotor');
    const secHealth = document.getElementById('secCategoryHealth');
    const secLife = document.getElementById('secCategoryLife');

    if (secMotor) secMotor.style.display = (code === 'motor') ? 'block' : 'none';
    if (secHealth) secHealth.style.display = (code === 'health') ? 'block' : 'none';
    if (secLife) secLife.style.display = (code === 'life') ? 'block' : 'none';
}

function toggleNewCustomerBox(val) {
    const box = document.getElementById('newCustBox');
    if (val && parseInt(val) > 0) {
        box.style.display = 'none';
    } else {
        box.style.display = 'block';
    }
}

function calcTotalPremium() {
    const net = parseFloat(document.getElementById('manual_net_premium').value) || 0;
    const gstInput = document.getElementById('manual_gst_amount');
    let gst = parseFloat(gstInput.value);

    if (isNaN(gst) || gst === 0) {
        if (net > 0) {
            gst = Math.round(net * 0.18 * 100) / 100;
            gstInput.value = gst.toFixed(2);
        } else {
            gst = 0;
        }
    }

    const total = net + gst;
    if (total > 0) {
        document.getElementById('manual_total_premium').value = total.toFixed(2);
    }
}

function autoCalculateEndDate(startVal) {
    if (!startVal) return;
    const dt = new Date(startVal);
    dt.setFullYear(dt.getFullYear() + 1);
    dt.setDate(dt.getDate() - 1);
    const y = dt.getFullYear();
    const m = String(dt.getMonth() + 1).padStart(2, '0');
    const d = String(dt.getDate()).padStart(2, '0');
    document.getElementById('manual_end_date').value = `${y}-${m}-${d}`;
}

// Drag & Drop
const dropzone = document.getElementById('dropzone');
if (dropzone) {
    ['dragenter', 'dragover'].forEach(evt => {
        dropzone.addEventListener(evt, (e) => { e.preventDefault(); dropzone.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(evt => {
        dropzone.addEventListener(evt, (e) => { e.preventDefault(); dropzone.classList.remove('dragover'); });
    });
    dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files && files.length) {
            const fileInput = document.getElementById('fileInput');
            fileInput.files = files;
            handleFileSelected(fileInput);
        }
    });
}

function handleFileSelected(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        document.getElementById('selectedFileName').textContent = file.name;
        document.getElementById('selectedFileSize').textContent = (file.size / (1024 * 1024)).toFixed(2) + ' MB';
        document.getElementById('dropzone').style.display = 'none';
        document.getElementById('fileSelectedBadge').style.display = 'flex';
    }
}

function clearSelectedFile() {
    const input = document.getElementById('fileInput');
    input.value = '';
    document.getElementById('dropzone').style.display = 'block';
    document.getElementById('fileSelectedBadge').style.display = 'none';
}

document.getElementById('uploadForm').addEventListener('submit', function() {
    const btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    if (currentEntryMode === 'manual') {
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving Policy & Scheduling Reminders...';
    } else {
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading & Analyzing Document...';
    }
});
</script>
</body>
</html>
