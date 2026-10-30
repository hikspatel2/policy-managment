<?php
/**
 * Insurance Policy Document Upload & AI Scanner Launcher
 * Developed for Insurance Management System
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
$aid = ($role === 'agent') ? agentIdForUser($conn, $userId) : 0;

// Enforce Agent Subscription Limit
if ($role === 'agent') {
    $subLimit = checkAgentSubscriptionLimit($conn, $aid, 'policy');
    if (!$subLimit['allowed']) {
        die("<div style='max-width:600px;margin:50px auto;padding:25px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;font-family:sans-serif;'>
            <h3 style='color:#991b1b;margin-top:0;'><i class='fas fa-ban'></i> Subscription Limit Reached</h3>
            <p style='color:#7f1d1d;'>" . htmlspecialchars($subLimit['message']) . "</p>
            <a href='agent_plans.php' style='display:inline-block;background:#001f3f;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-weight:600;'>Upgrade Subscription Plan</a>
            <a href='dashboard.php' style='margin-left:10px;color:#64748b;text-decoration:none;'>Return to Dashboard</a>
        </div>");
    }
}

$error = '';
$notice = '';

// Pre-selected customer if passed via GET
$selectedCustId = (int)($_GET['customer_id'] ?? 0);

// Fetch customers list for this agent (or all for staff)
$custQuery = ($role === 'agent')
    ? "SELECT id, name, mobile, email FROM customers WHERE agent_id = $aid AND is_active = 1 ORDER BY name ASC"
    : "SELECT id, name, mobile, email FROM customers WHERE is_active = 1 ORDER BY name ASC";
$custRes = $conn->query($custQuery);
$customers = [];
if ($custRes) {
    while ($r = $custRes->fetch_assoc()) $customers[] = $r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $customerId = (int)($_POST['customer_id'] ?? 0);
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
                    $cLimit = checkAgentSubscriptionLimit($conn, $aid, 'customer');
                    if (!$cLimit['allowed']) {
                        $error = $cLimit['message'];
                    }
                }

                if (!$error) {
                    $insCust = $conn->prepare("INSERT INTO customers (name, mobile, email, agent_id, primary_wa_consent, consent_timestamp, consent_source, is_active) VALUES (?, ?, ?, ?, 1, NOW(), 'policy_upload', 1)");
                    $insCust->bind_param("sssi", $newCustName, $newCustMobile, $newCustEmail, $aid);
                    $insCust->execute();
                    $customerId = $conn->insert_id;
                    $insCust->close();
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
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($tmpPath);
                $allowedMimes = [
                    'application/pdf' => 'pdf',
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp'
                ];

                if (!array_key_exists($mimeType, $allowedMimes)) {
                    $error = 'Invalid file type (' . htmlspecialchars($mimeType) . '). Only PDF and JPG/PNG documents are supported.';
                } else {
                    $ext = $allowedMimes[$mimeType];
                    $storedName = storedName('pol', $ext);
                    $relPath = 'uploads/policies/' . $storedName;
                    $fullPath = __DIR__ . '/' . $relPath;

                    if (!is_dir(__DIR__ . '/uploads/policies')) {
                        mkdir(__DIR__ . '/uploads/policies', 0755, true);
                    }

                    if (!move_uploaded_file($tmpPath, $fullPath)) {
                        $error = 'Failed to save uploaded file to storage. Check folder permissions.';
                    } else {
                        // Insert policy extraction record
                        $insExt = $conn->prepare("INSERT INTO policy_extractions (agent_id, customer_id, file_path, scanning_status) VALUES (?, ?, ?, 'uploaded')");
                        $insExt->bind_param("iis", $aid, $customerId, $relPath);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Insurance Policy — <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="styles.css?v=5.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .upload-card {
            max-width: 800px;
            margin: 30px auto;
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            padding: 35px 40px;
        }
        .dropzone {
            border: 2px dashed #94a3b8;
            border-radius: 12px;
            padding: 35px 20px;
            text-align: center;
            background: #f8fafc;
            cursor: pointer;
            transition: all 0.2s;
            margin: 15px 0 25px;
        }
        .dropzone:hover, .dropzone.dragover {
            border-color: #0074D9;
            background: #eff6ff;
        }
        .toggle-switch-card {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
    </style>
</head>
<body style="background: #f1f5f9; font-family: inherit;">
    <div style="padding: 20px;">
        <div style="max-width: 800px; margin: 0 auto 15px; display: flex; justify-content: space-between; align-items: center;">
            <a href="dashboard.php" class="btn btn-secondary" style="padding: 8px 16px; border-radius: 6px; text-decoration: none;">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <span style="font-size: 13.5px; color: #64748b;">
                <i class="fas fa-robot" style="color: #0074D9;"></i> AI Policy Extraction Ready
            </span>
        </div>

        <div class="upload-card">
            <div style="text-align: center; margin-bottom: 25px;">
                <div style="width: 50px; height: 50px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: inline-flex; align-items: center; justify-content: center; font-size: 22px; margin-bottom: 8px;">
                    <i class="fas fa-file-arrow-up"></i>
                </div>
                <h2 style="margin: 0; color: #001f3f; font-size: 24px;">Upload Insurance Policy</h2>
                <p style="color: #64748b; font-size: 14px; margin: 4px 0 0;">Upload any client policy PDF or image. AI will extract coverage and schedule expiry reminders.</p>
            </div>

            <?php if ($error): ?>
                <div class="error" style="margin-bottom: 20px;"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="policy_upload.php" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <!-- Insurance Category -->
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight: 600; color: #1e293b;"><i class="fas fa-shield"></i> Insurance Category</label>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-top: 6px;">
                        <?php foreach ($categories as $cat): ?>
                            <label style="border: 2px solid #cbd5e1; border-radius: 8px; padding: 12px; display: flex; align-items: center; gap: 8px; cursor: pointer; transition: all 0.2s;" class="cat-pill">
                                <input type="radio" name="insurance_category" value="<?php echo htmlspecialchars($cat['code']); ?>" <?php echo $cat['code'] === 'motor' ? 'checked' : ''; ?>>
                                <i class="fas <?php echo htmlspecialchars($cat['icon']); ?>"></i>
                                <span><?php echo htmlspecialchars($cat['name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Customer Selection -->
                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="customer_id" style="font-weight: 600; color: #1e293b;"><i class="fas fa-user"></i> Select Existing Customer</label>
                    <select id="customer_id" name="customer_id" onchange="toggleNewCustomer(this.value)" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1;">
                        <option value="">-- Choose Existing Customer or Add New Below --</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo ($selectedCustId === (int)$c['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name']); ?> (<?php echo htmlspecialchars($c['mobile']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Or Add New Customer Inline -->
                <div id="newCustBox" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin-bottom: 20px; <?php echo $selectedCustId ? 'display:none;' : ''; ?>">
                    <h4 style="margin: 0 0 12px; color: #0f172a; font-size: 14.5px;"><i class="fas fa-user-plus"></i> Or Add New Customer Quickly</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div class="form-group">
                            <label style="font-size: 13px;">Customer Full Name</label>
                            <input type="text" name="new_customer_name" placeholder="e.g. Mukesh Patel">
                        </div>
                        <div class="form-group">
                            <label style="font-size: 13px;">Mobile Number</label>
                            <input type="tel" name="new_customer_mobile" placeholder="10-digit mobile">
                        </div>
                    </div>
                    <div class="form-group" style="margin-top: 10px;">
                        <label style="font-size: 13px;">Email Address <span style="font-weight: normal; color: #888;">(Optional)</span></label>
                        <input type="email" name="new_customer_email" placeholder="mukesh@example.com">
                    </div>
                </div>

                <!-- Drag & Drop Upload Zone -->
                <div class="form-group">
                    <label style="font-weight: 600; color: #1e293b;"><i class="fas fa-file-pdf"></i> Policy Document File *</label>
                    <div class="dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()">
                        <i class="fas fa-cloud-arrow-up" style="font-size: 38px; color: #0284c7; margin-bottom: 10px;"></i>
                        <h4 style="margin: 0; color: #0f172a; font-size: 16px;" id="dropTitle">Click to browse or drag & drop policy file here</h4>
                        <p style="margin: 6px 0 0; color: #64748b; font-size: 13px;">Supported formats: PDF, JPG, JPEG, PNG (Max 15 MB)</p>
                        <input type="file" id="fileInput" name="policy_document" accept="application/pdf,image/jpeg,image/png,image/webp" style="display:none;" onchange="handleFileSelected(this)">
                    </div>
                    <div id="fileSelectedBadge" style="display: none; background: #e0f2fe; color: #0369a1; padding: 10px 14px; border-radius: 8px; font-size: 14px; margin-bottom: 20px;">
                        <i class="fas fa-paperclip"></i> <strong id="selectedFileName"></strong> (<span id="selectedFileSize"></span>)
                    </div>
                </div>

                <!-- AI Scanner Toggle -->
                <div class="toggle-switch-card">
                    <input type="checkbox" id="run_ai_scan" name="run_ai_scan" value="1" checked style="width: 22px; height: 22px; cursor: pointer;">
                    <label for="run_ai_scan" style="cursor: pointer;">
                        <strong style="color: #166534; font-size: 15px;"><i class="fas fa-sparkles"></i> Automatic AI Policy Scanning</strong>
                        <p style="margin: 2px 0 0; color: #15803d; font-size: 13px;">
                            Instantly extract Policy Number, Premium, Start/End Dates, Insurer, and Insured details for rapid verification.
                        </p>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="width: 100%; padding: 14px; font-size: 16px; font-weight: 700; border-radius: 8px;">
                    <i class="fas fa-cloud-upload-alt"></i> Upload & Start Policy Verification <i class="fas fa-arrow-right" style="font-size: 13px;"></i>
                </button>
            </form>
        </div>
    </div>

    <script>
    function toggleNewCustomer(val) {
        const box = document.getElementById('newCustBox');
        if (val) {
            box.style.display = 'none';
        } else {
            box.style.display = 'block';
        }
    }

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
            document.getElementById('fileSelectedBadge').style.display = 'block';
            document.getElementById('dropTitle').textContent = 'File selected: ' + file.name;
        }
    }
    </script>
</body>
</html>
