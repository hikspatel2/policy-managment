<?php
/**
 * Policy Verification Workbench (Split-Screen Document Review & Commit)
 * Insurance Management System
 */
require_once 'config.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

if (!$userId || !in_array($role, ['admin', 'manager', 'agent'], true)) {
    header("Location: login.php");
    exit();
}

$conn = getDBConnection();
$branding = getBranding();
$aid = ($role === 'agent') ? (int)(agentIdForUser($conn, $userId) ?? 0) : 0;
$currency = $branding['currency_symbol'] ?? '₹';

$extId = (int)($_GET['ext_id'] ?? 0);
$category = trim($_GET['category'] ?? 'motor');

if ($extId <= 0) {
    header("Location: policy_upload.php");
    exit();
}

// Fetch extraction record
$ext = null;
try {
    $eStmt = $conn->prepare("SELECT pe.*, c.name AS cust_name, c.mobile AS cust_mobile, c.email AS cust_email 
                             FROM policy_extractions pe 
                             LEFT JOIN customers c ON c.id = pe.customer_id 
                             WHERE pe.id = ? LIMIT 1");
    if ($eStmt) {
        $eStmt->bind_param("i", $extId);
        $eStmt->execute();
        $ext = $eStmt->get_result()->fetch_assoc();
        $eStmt->close();
    }
} catch (\Throwable $e) {}

if (!$ext) {
    header("Location: policy_upload.php?error=not_found");
    exit();
}

if ($role === 'agent' && !empty($ext['agent_id']) && $aid > 0 && (int)$ext['agent_id'] !== $aid) {
    die("Unauthorized access to document verification.");
}

$extracted = [];
if (!empty($ext['extracted_data'])) {
    $extracted = json_decode($ext['extracted_data'], true) ?: [];
}
$confidence = [];
if (!empty($ext['confidence_scores'])) {
    $confidence = json_decode($ext['confidence_scores'], true) ?: [];
}

$error = '';
$notice = '';

// Handle Verification Form Submission (Save Verified Policy)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $policyNumber = trim($_POST['policy_number'] ?? '');
            $companyName = trim($_POST['company_name'] ?? '');
            $companyCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $companyName), 0, 10)) ?: 'INS';
            $customerId = (int)($_POST['customer_id'] ?? $ext['customer_id']);
            $custName = trim($_POST['customer_name'] ?? ($ext['cust_name'] ?? 'Customer'));
            $startDate = trim($_POST['policy_start_date'] ?? date('Y-m-d'));
            $endDate = trim($_POST['policy_end_date'] ?? date('Y-m-d', strtotime('+1 year')));
            $entryDate = date('Y-m-d');
            $totalPremium = (float)($_POST['total_premium_amount'] ?? 0);
            $netPremium = (float)($_POST['net_premium_amount'] ?? $totalPremium);
            $gstAmount = (float)($_POST['gst_amount'] ?? 0);
            $policyType = trim($_POST['policy_type'] ?? 'comprehensive');
            $businessType = trim($_POST['business_type'] ?? 'new');
            $insuranceCat = trim($_POST['insurance_category'] ?? $category);

            // Vertical specific
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

            // Validation
            if (empty($policyNumber)) {
                $error = 'Policy Number is required.';
            } elseif (empty($companyName)) {
                $error = 'Insurance Company Name is required.';
            } elseif (empty($customerId)) {
                $error = 'Customer assignment is required.';
            } elseif ($totalPremium <= 0) {
                $error = 'Total Premium Amount must be greater than zero.';
            } else {
                // Check if policy number already exists in system
                $chkPol = $conn->prepare("SELECT id FROM policy_entries WHERE policy_number = ? AND deleted_at IS NULL LIMIT 1");
                $chkPol->bind_param("s", $policyNumber);
                $chkPol->execute();
                if ($chkPol->get_result()->num_rows > 0) {
                    $error = "A policy with Policy Number '{$policyNumber}' is already registered in the system.";
                }
                $chkPol->close();

                if (!$error) {
                    // If motor insurance and vehicle number provided, create or find vehicle record
                    $vehicleId = null;
                    if ($insuranceCat === 'motor' && !empty($vehicleNumber)) {
                        $vClean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vehicleNumber));
                        $vChk = $conn->prepare("SELECT id FROM vehicles WHERE REPLACE(vehicle_number, ' ', '') = ? LIMIT 1");
                        $vChk->bind_param("s", $vClean);
                        $vChk->execute();
                        $vRow = $vChk->get_result()->fetch_assoc();
                        $vChk->close();

                        if ($vRow) {
                            $vehicleId = (int)$vRow['id'];
                        } else {
                            $vIns = $conn->prepare("INSERT INTO vehicles (vehicle_number, customer_id, make, model, is_active) VALUES (?, ?, ?, ?, 1)");
                            $vIns->bind_param("siss", $vehicleNumber, $customerId, $make, $model);
                            $vIns->execute();
                            $vehicleId = $conn->insert_id;
                            $vIns->close();
                        }
                    }

                    // Default branch
                    $branchId = 1;

                    // Insert into policy_entries
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

                    $agentName = $_SESSION['username'] ?? 'Staff';
                    $vehicleType = ($insuranceCat === 'motor') ? 'Motor' : null;
                    $aidParam = ($aid > 0) ? $aid : (!empty($ext['agent_id']) ? (int)$ext['agent_id'] : null);

                    $insStmt = $conn->prepare($insSql);
                    $insStmt->bind_param(
                        "ssiisssisissssddddddsssisssssii",
                        $insuranceCat, $entryDate, $branchId, $vehicleId, $vehicleNumber, $vehicleType,
                        $customerId, $custName, $aidParam, $agentName, $companyName, $companyCode,
                        $policyNumber, $policyType, $businessType, $totalPremium, $netPremium, $gstAmount,
                        $idv, $ncbPercent, $sumInsured, $planName, $tpaName, $membersCount, $membersDetails,
                        $nomineeName, $nomineeRelation, $startDate, $endDate, $userId, $userId
                    );
                    $insStmt->execute();
                    $newPolicyId = $conn->insert_id;
                    $insStmt->close();

                    // Insert into policy_documents
                    $docName = basename($ext['file_path']);
                    $docSql = $conn->prepare("INSERT INTO policy_documents (policy_entry_id, document_type, file_name, file_path, uploaded_by) VALUES (?, 'policy_pdf', ?, ?, ?)");
                    $docSql->bind_param("issi", $newPolicyId, $docName, $ext['file_path'], $userId);
                    $docSql->execute();
                    $docSql->close();

                    // Update extraction record
                    $conn->query("UPDATE policy_extractions SET policy_entry_id = $newPolicyId, scanning_status = 'completed' WHERE id = $extId");

                    // Automatically schedule the 5-Stage Expiry Reminders
                    schedulePolicyReminders($conn, $newPolicyId, $aidParam, $customerId, $endDate);

                    // Initialize Customer Followup in Renewal CRM Pipeline
                    $fuDate = date('Y-m-d', strtotime('-30 days', strtotime($endDate)));
                    if ($aidParam) {
                        $fuStmt = $conn->prepare("INSERT INTO customer_followups (policy_entry_id, customer_id, agent_id, followup_stage, next_followup_date, call_notes) VALUES (?, ?, ?, 'new_renewal', ?, 'Policy verified and scheduled for renewal follow-up')");
                        $fuStmt->bind_param("iiis", $newPolicyId, $customerId, $aidParam, $fuDate);
                    } else {
                        $fuStmt = $conn->prepare("INSERT INTO customer_followups (policy_entry_id, customer_id, agent_id, followup_stage, next_followup_date, call_notes) VALUES (?, ?, NULL, 'new_renewal', ?, 'Policy verified and scheduled for renewal follow-up')");
                        $fuStmt->bind_param("iis", $newPolicyId, $customerId, $fuDate);
                    }
                    if ($fuStmt) {
                        $fuStmt->execute();
                        $fuStmt->close();
                    }

                    // Increment agent subscription policy count if an agent is attached
                    if ($aidParam) {
                        $conn->query("UPDATE subscriptions SET policy_count = policy_count + 1 WHERE agent_id = $aidParam AND status = 'active' ORDER BY id DESC LIMIT 1");
                    }

                    logActivity($userId, $agentName, 'Policy Verified', "Verified policy #{$policyNumber} for customer #{$customerId}");

                    $conn->close();

                    header("Location: policy_entries.php?msg=verified&pid=" . $newPolicyId);
                    exit();
                }
            }
        } catch (Throwable $e) {
            error_log("Policy verification error: " . $e->getMessage());
            $error = "Failed to save verified policy: " . $e->getMessage();
        }
    }
}

$csrf_token = generateCSRFToken();

// Determine file type for preview pane
$fileExt = strtolower(pathinfo($ext['file_path'], PATHINFO_EXTENSION));
$isImage = in_array($fileExt, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Policy Verification Workbench — <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="styles.css?v=5.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { margin: 0; padding: 0; background: #0f172a; height: 100vh; overflow: hidden; font-family: inherit; }
        .workbench-layout {
            display: flex;
            height: calc(100vh - 54px);
        }
        .workbench-header {
            height: 54px;
            background: #001f3f;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .doc-preview-pane {
            flex: 1;
            background: #1e293b;
            border-right: 1px solid #334155;
            position: relative;
            display: flex;
            flex-direction: column;
            overflow: auto;
        }
        .doc-preview-frame {
            width: 100%;
            height: 100%;
            border: none;
            background: #334155;
        }
        .verify-form-pane {
            width: 580px;
            background: #ffffff;
            overflow-y: auto;
            padding: 25px 30px;
            display: flex;
            flex-direction: column;
        }
        .conf-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 10px;
            margin-left: 6px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }
        .conf-badge.high { background: #dcfce7; color: #15803d; }
        .conf-badge.med { background: #fef3c7; color: #b45309; }
        .conf-badge.low { background: #fee2e2; color: #b91c1c; }
        .field-label-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }
        .field-label-row label {
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="workbench-header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <a href="dashboard.php" style="color: #93c5fd; text-decoration: none; font-size: 14px;">
                <i class="fas fa-arrow-left"></i> Exit Workbench
            </a>
            <span style="color: rgba(255,255,255,0.3);">|</span>
            <strong style="font-size: 16px;"><i class="fas fa-microscope" style="color: #38bdf8;"></i> AI Policy Verification Workbench</strong>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
            <a href="ai_scanner.php?ext_id=<?php echo $extId; ?>&category=<?php echo urlencode($category); ?>&auto=1" class="btn btn-secondary" style="padding: 6px 14px; font-size: 13px; border-radius: 6px; text-decoration: none;">
                <i class="fas fa-rotate"></i> Re-Scan with AI
            </a>
            <button type="submit" form="verifyForm" class="btn btn-primary" style="padding: 7px 18px; font-size: 13.5px; font-weight: 600; border-radius: 6px; background: #10b981; border-color: #059669;">
                <i class="fas fa-check-circle"></i> Save Verified Policy
            </button>
        </div>
    </div>

    <div class="workbench-layout">
        <!-- LEFT PANE: Document Preview -->
        <div class="doc-preview-pane">
            <?php if ($isImage): ?>
                <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box;">
                    <img src="stream_doc.php?ext_id=<?php echo $extId; ?>" alt="Policy Document" style="max-width: 100%; max-height: 100%; object-fit: contain; box-shadow: 0 4px 20px rgba(0,0,0,0.5); border-radius: 8px;">
                </div>
            <?php else: ?>
                <iframe src="stream_doc.php?ext_id=<?php echo $extId; ?>#toolbar=1&navpanes=0" class="doc-preview-frame"></iframe>
            <?php endif; ?>
        </div>

        <!-- RIGHT PANE: Extracted Data Review Form -->
        <div class="verify-form-pane">
            <div style="margin-bottom: 15px; padding-bottom: 12px; border-bottom: 1px solid #f1f5f9;">
                <h3 style="margin: 0 0 4px; color: #001f3f; font-size: 18px;">
                    <i class="fas fa-clipboard-check" style="color: #0074D9;"></i> Extracted Data Review
                </h3>
                <p style="margin: 0; font-size: 13px; color: #64748b;">
                    Compare extracted values against the original document on the left. Edit or fill any values before finalizing.
                </p>
            </div>

            <?php if ($error): ?>
                <div class="error" style="margin-bottom: 15px;"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (isset($_GET['scan_success'])): ?>
                <div class="success" style="margin-bottom: 15px;"><i class="fas fa-check-circle"></i> AI extraction completed! Review and verify details below.</div>
            <?php elseif (isset($_GET['scan_error'])): ?>
                <div class="warning" style="margin-bottom: 15px; background: #fffbeb; border: 1px solid #fef3c7; color: #92400e; padding: 10px; border-radius: 6px;">
                    <i class="fas fa-info-circle"></i> Manual review mode: <?php echo htmlspecialchars($_GET['scan_error']); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="policy_verify.php?ext_id=<?php echo $extId; ?>&category=<?php echo urlencode($category); ?>" id="verifyForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="customer_id" value="<?php echo $ext['customer_id']; ?>">
                <input type="hidden" name="customer_name" value="<?php echo htmlspecialchars($ext['cust_name'] ?? ''); ?>">
                <input type="hidden" name="insurance_category" value="<?php echo htmlspecialchars($category); ?>">

                <!-- Client Info Card -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 18px;">
                    <div style="font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">Customer Assigned</div>
                    <div style="font-size: 15px; font-weight: 700; color: #0f172a; margin-top: 2px;">
                        <?php echo htmlspecialchars($ext['cust_name'] ?? 'Customer'); ?>
                    </div>
                    <div style="font-size: 13px; color: #64748b;">
                        Mobile: <?php echo htmlspecialchars($ext['cust_mobile'] ?? 'N/A'); ?> | Email: <?php echo htmlspecialchars($ext['cust_email'] ?? 'N/A'); ?>
                    </div>
                </div>

                <!-- Policy Identifiers -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div class="form-group">
                        <div class="field-label-row">
                            <label for="company_name">Insurance Company *</label>
                            <?php if (isset($confidence['insurance_company'])): ?>
                                <span class="conf-badge <?php echo $confidence['insurance_company'] > 0.7 ? 'high' : 'med'; ?>">
                                    <i class="fas fa-check"></i> <?php echo round($confidence['insurance_company'] * 100); ?>%
                                </span>
                            <?php endif; ?>
                        </div>
                        <input type="text" id="company_name" name="company_name" required value="<?php echo htmlspecialchars($extracted['insurance_company'] ?? ''); ?>" placeholder="e.g. HDFC ERGO / ICICI Lombard">
                    </div>

                    <div class="form-group">
                        <div class="field-label-row">
                            <label for="policy_number">Policy Number *</label>
                            <?php if (isset($confidence['policy_number'])): ?>
                                <span class="conf-badge <?php echo $confidence['policy_number'] > 0.7 ? 'high' : 'med'; ?>">
                                    <i class="fas fa-check"></i> <?php echo round($confidence['policy_number'] * 100); ?>%
                                </span>
                            <?php endif; ?>
                        </div>
                        <input type="text" id="policy_number" name="policy_number" required value="<?php echo htmlspecialchars($extracted['policy_number'] ?? ''); ?>" placeholder="e.g. 2312/1234567/00/000">
                    </div>
                </div>

                <!-- Coverage Dates -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div class="form-group">
                        <div class="field-label-row">
                            <label for="policy_start_date">Policy Start Date *</label>
                        </div>
                        <input type="date" id="policy_start_date" name="policy_start_date" required value="<?php echo htmlspecialchars($extracted['policy_start_date'] ?? date('Y-m-d')); ?>">
                    </div>

                    <div class="form-group">
                        <div class="field-label-row">
                            <label for="policy_end_date">Policy Expiry Date *</label>
                            <?php if (isset($confidence['policy_end_date'])): ?>
                                <span class="conf-badge <?php echo $confidence['policy_end_date'] > 0.7 ? 'high' : 'med'; ?>">
                                    <i class="fas fa-check"></i> <?php echo round($confidence['policy_end_date'] * 100); ?>%
                                </span>
                            <?php endif; ?>
                        </div>
                        <input type="date" id="policy_end_date" name="policy_end_date" required value="<?php echo htmlspecialchars($extracted['policy_end_date'] ?? date('Y-m-d', strtotime('+1 year'))); ?>">
                    </div>
                </div>

                <!-- Premium Figures -->
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 14px;">
                    <div class="form-group">
                        <div class="field-label-row">
                            <label for="net_premium_amount">Net Premium</label>
                        </div>
                        <input type="number" step="0.01" id="net_premium_amount" name="net_premium_amount" value="<?php echo htmlspecialchars($extracted['net_premium'] ?? $extracted['total_premium'] ?? '0.00'); ?>" oninput="calcTotalPremium()">
                    </div>

                    <div class="form-group">
                        <div class="field-label-row">
                            <label for="gst_amount">GST (18%)</label>
                        </div>
                        <input type="number" step="0.01" id="gst_amount" name="gst_amount" value="<?php echo htmlspecialchars($extracted['gst_amount'] ?? '0.00'); ?>" oninput="calcTotalPremium()">
                    </div>

                    <div class="form-group">
                        <div class="field-label-row">
                            <label for="total_premium_amount">Total Premium *</label>
                            <?php if (isset($confidence['total_premium'])): ?>
                                <span class="conf-badge <?php echo $confidence['total_premium'] > 0.7 ? 'high' : 'med'; ?>">
                                    <i class="fas fa-check"></i> <?php echo round($confidence['total_premium'] * 100); ?>%
                                </span>
                            <?php endif; ?>
                        </div>
                        <input type="number" step="0.01" id="total_premium_amount" name="total_premium_amount" required value="<?php echo htmlspecialchars($extracted['total_premium'] ?? '0.00'); ?>" style="font-weight: 700; color: #001f3f;">
                    </div>
                </div>

                <!-- Motor Specific Fields -->
                <?php if ($category === 'motor'): ?>
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 14px; margin-bottom: 16px;">
                        <div style="font-size: 13px; font-weight: 700; color: #1e40af; margin-bottom: 10px;">
                            <i class="fas fa-car"></i> Motor Vehicle Details
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                            <div class="form-group">
                                <label style="font-size: 12.5px;">Vehicle Number</label>
                                <input type="text" name="vehicle_number" placeholder="e.g. GJ01AB1234" value="<?php echo htmlspecialchars($extracted['vehicle_number'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label style="font-size: 12.5px;">Policy Cover Type</label>
                                <select name="policy_type">
                                    <option value="comprehensive" <?php echo strtolower($extracted['policy_type'] ?? '') === 'comprehensive' ? 'selected' : ''; ?>>Comprehensive</option>
                                    <option value="third_party" <?php echo strtolower($extracted['policy_type'] ?? '') === 'third party' ? 'selected' : ''; ?>>Third Party Only</option>
                                    <option value="own_damage" <?php echo strtolower($extracted['policy_type'] ?? '') === 'own damage' ? 'selected' : ''; ?>>Own Damage</option>
                                </select>
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                            <div class="form-group">
                                <label style="font-size: 12.5px;">Make</label>
                                <input type="text" name="make" placeholder="e.g. Maruti / Hyundai" value="<?php echo htmlspecialchars($extracted['make'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label style="font-size: 12.5px;">Model</label>
                                <input type="text" name="model" placeholder="e.g. Swift / Creta" value="<?php echo htmlspecialchars($extracted['model'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label style="font-size: 12.5px;">IDV (Insured Value)</label>
                                <input type="number" step="0.01" name="idv" placeholder="e.g. 500000" value="<?php echo htmlspecialchars($extracted['idv'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Health Specific Fields -->
                <?php if ($category === 'health'): ?>
                    <div style="background: #fdf2f8; border: 1px solid #fbcfe8; border-radius: 8px; padding: 14px; margin-bottom: 16px;">
                        <div style="font-size: 13px; font-weight: 700; color: #9d174d; margin-bottom: 10px;">
                            <i class="fas fa-heart-pulse"></i> Health Coverage Details
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                            <div class="form-group">
                                <label style="font-size: 12.5px;">Sum Insured</label>
                                <input type="number" step="0.01" name="sum_insured" placeholder="e.g. 500000" value="<?php echo htmlspecialchars($extracted['sum_insured'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label style="font-size: 12.5px;">Plan Name</label>
                                <input type="text" name="plan_name" placeholder="e.g. Optima Restore" value="<?php echo htmlspecialchars($extracted['plan_name'] ?? ''); ?>">
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="form-group">
                                <label style="font-size: 12.5px;">TPA Name</label>
                                <input type="text" name="tpa_name" placeholder="e.g. Medi Assist" value="<?php echo htmlspecialchars($extracted['tpa_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label style="font-size: 12.5px;">Members Count</label>
                                <input type="number" name="members_count" min="1" value="<?php echo htmlspecialchars($extracted['members_count'] ?? '1'); ?>">
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Nominee Details -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 18px;">
                    <div class="form-group">
                        <label for="nominee_name" style="font-size: 13px;">Nominee Name</label>
                        <input type="text" id="nominee_name" name="nominee_name" placeholder="Nominee Name" value="<?php echo htmlspecialchars($extracted['nominee_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="nominee_relation" style="font-size: 13px;">Nominee Relationship</label>
                        <input type="text" id="nominee_relation" name="nominee_relation" placeholder="e.g. Spouse / Son / Father" value="<?php echo htmlspecialchars($extracted['nominee_relation'] ?? ''); ?>">
                    </div>
                </div>

                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px; margin-bottom: 20px; font-size: 13px; color: #166534;">
                    <i class="fas fa-calendar-check"></i> <strong>Automated Reminders:</strong> Saving this verified policy will automatically schedule customer notifications at <strong>30, 14, 7, 2, and 0 days</strong> prior to expiry.
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="width: 100%; padding: 14px; font-size: 16px; font-weight: 700; border-radius: 8px; background: #10b981; border-color: #059669;">
                    <i class="fas fa-check-double"></i> Save Verified Policy & Schedule Reminders
                </button>
            </form>
        </div>
    </div>

    <script>
    function calcTotalPremium() {
        const net = parseFloat(document.getElementById('net_premium_amount').value) || 0;
        const gst = parseFloat(document.getElementById('gst_amount').value) || 0;
        if (net > 0 && gst > 0) {
            document.getElementById('total_premium_amount').value = (net + gst).toFixed(2);
        }
    }
    </script>
</body>
</html>
