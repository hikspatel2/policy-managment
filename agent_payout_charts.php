<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 * TikTok: https://www.tiktok.com/@aiwithrameez (Follow for more!)
 */
require_once 'config.php';

// auth check
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout()) { header("Location: login.php"); exit(); }

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'agent_payouts';

$isAdmin = ($role === 'admin');
$isManager = ($role === 'manager');
$isAgent = ($role === 'agent');

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getAgents':
                $stmt = $conn->prepare("SELECT id, agent_code, name FROM agents WHERE is_active=1 ORDER BY name");
                $stmt->execute();
                $res = $stmt->get_result();
                $agents = [];
                while ($row = $res->fetch_assoc()) $agents[] = $row;
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $agents]);
                exit();

            case 'getBuyers':
                $stmt = $conn->prepare("SELECT id, company_name, company_code FROM buyers WHERE is_active=1 ORDER BY company_name");
                $stmt->execute();
                $res = $stmt->get_result();
                $buyers = [];
                while ($row = $res->fetch_assoc()) $buyers[] = $row;
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $buyers]);
                exit();

            case 'getCharts':
                $month = (int)($_GET['month'] ?? date('n'));
                $year = (int)($_GET['year'] ?? date('Y'));
                $filterAgent = $_GET['agent_id'] ?? '';
                $filterBuyer = $_GET['buyer_id'] ?? '';

                if ($isAgent) {
                    // agent sees default + own custom
                    $sql = "SELECT apc.*, a.name as agent_name, a.agent_code, b.company_name
                            FROM agent_payout_charts apc
                            LEFT JOIN agents a ON apc.agent_id=a.id
                            LEFT JOIN buyers b ON apc.buyer_id=b.id
                            WHERE apc.month=? AND apc.year=?
                            AND (apc.agent_id IS NULL OR apc.agent_id = (SELECT id FROM agents WHERE user_id=?))";
                    $params = [$month, $year, $user_id];
                    $types = "iii";

                    if (!empty($filterBuyer)) {
                        $sql .= " AND (apc.buyer_id IS NULL OR apc.buyer_id=?)";
                        $params[] = (int)$filterBuyer;
                        $types .= "i";
                    }

                    $sql .= " ORDER BY apc.id DESC";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param($types, ...$params);
                } else {
                    // admin/manager
                    $sql = "SELECT apc.*, a.name as agent_name, a.agent_code, b.company_name
                            FROM agent_payout_charts apc
                            LEFT JOIN agents a ON apc.agent_id=a.id
                            LEFT JOIN buyers b ON apc.buyer_id=b.id
                            WHERE apc.month=? AND apc.year=?";
                    $params = [$month, $year];
                    $types = "ii";

                    if (!empty($filterAgent)) {
                        if ($filterAgent === 'default') {
                            $sql .= " AND apc.agent_id IS NULL";
                        } else {
                            $sql .= " AND apc.agent_id=?";
                            $params[] = (int)$filterAgent;
                            $types .= "i";
                        }
                    }
                    if (!empty($filterBuyer)) {
                        if ($filterBuyer === 'all') {
                            $sql .= " AND apc.buyer_id IS NULL";
                        } else {
                            $sql .= " AND apc.buyer_id=?";
                            $params[] = (int)$filterBuyer;
                            $types .= "i";
                        }
                    }

                    $sql .= " ORDER BY apc.id DESC";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param($types, ...$params);
                }

                $stmt->execute();
                $res = $stmt->get_result();
                $charts = [];
                while ($row = $res->fetch_assoc()) {
                    $charts[] = [
                        'id' => (int)$row['id'],
                        'agent_id' => $row['agent_id'] ? (int)$row['agent_id'] : null,
                        'buyer_id' => $row['buyer_id'] ? (int)$row['buyer_id'] : null,
                        'agent_name' => $row['agent_name'] ?? null,
                        'agent_code' => $row['agent_code'] ?? null,
                        'company_name' => $row['company_name'] ?? null,
                        'vehicle_type' => $row['vehicle_type'],
                        'month' => (int)$row['month'],
                        'year' => (int)$row['year'],
                        'commission_basis' => $row['commission_basis'],
                        'commission_rate' => (float)$row['commission_rate'],
                        'is_custom' => (int)$row['is_custom'],
                        'is_active' => (int)$row['is_active'],
                        'uploaded_via' => $row['uploaded_via']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $charts]);
                exit();

            case 'addChart':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                    echo json_encode(['success' => false, 'message' => 'Invalid request']);
                    exit;
                }
                if (!$isAdmin) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $agentId = !empty($_POST['agent_id']) ? (int)$_POST['agent_id'] : null;
                $buyerId = !empty($_POST['buyer_id']) ? (int)$_POST['buyer_id'] : null;
                $vehicleType = trim($_POST['vehicle_type'] ?? '');
                $month = (int)($_POST['month'] ?? 0);
                $year = (int)($_POST['year'] ?? 0);
                $basis = trim($_POST['commission_basis'] ?? '');
                $rate = (float)($_POST['commission_rate'] ?? 0);
                $isCustom = $agentId ? 1 : 0;

                // validate
                if (empty($vehicleType)) {
                    echo json_encode(['success' => false, 'message' => 'Vehicle type is required']);
                    exit();
                }
                if ($month < 1 || $month > 12) {
                    echo json_encode(['success' => false, 'message' => 'Invalid month']);
                    exit();
                }
                if ($year < 2020 || $year > 2050) {
                    echo json_encode(['success' => false, 'message' => 'Invalid year']);
                    exit();
                }
                $validBasis = ['premium_pct','tp_pct','od_pct','net_pct','fixed'];
                if (!in_array($basis, $validBasis)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid commission basis']);
                    exit();
                }
                if ($rate <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Commission rate must be greater than 0']);
                    exit();
                }

                // unique check (NULL-safe)
                $uSql = "SELECT id FROM agent_payout_charts WHERE vehicle_type=? AND month=? AND year=?";
                $uTypes = "sii";
                $uParams = [$vehicleType, $month, $year];
                if ($agentId === null) {
                    $uSql .= " AND agent_id IS NULL";
                } else {
                    $uSql .= " AND agent_id=?";
                    $uTypes .= "i";
                    $uParams[] = $agentId;
                }
                if ($buyerId === null) {
                    $uSql .= " AND buyer_id IS NULL";
                } else {
                    $uSql .= " AND buyer_id=?";
                    $uTypes .= "i";
                    $uParams[] = $buyerId;
                }
                $stmt = $conn->prepare($uSql);
                $stmt->bind_param($uTypes, ...$uParams);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'A chart entry with this combination already exists']);
                    exit();
                }
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO agent_payout_charts (agent_id, buyer_id, vehicle_type, month, year, commission_basis, commission_rate, is_custom, uploaded_via, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?)");
                $stmt->bind_param("iisiisdii", $agentId, $buyerId, $vehicleType, $month, $year, $basis, $rate, $isCustom, $user_id);

                if (!$stmt->execute()) {
                    $err = $stmt->error;
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add chart: ' . $err]);
                    exit();
                }
                $insertId = $stmt->insert_id;
                $stmt->close();

                $agentLabel = $agentId ? "Agent #$agentId" : "Default";
                logActivity($user_id, $username, 'Agent Payout Chart Created', "Added $agentLabel $vehicleType $basis=$rate for $month/$year");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Payout chart added successfully', 'data' => ['id' => $insertId]]);
                exit();

            case 'updateChart':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                    echo json_encode(['success' => false, 'message' => 'Invalid request']);
                    exit;
                }
                if (!$isAdmin) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $cid = (int)($_POST['id'] ?? 0);
                $agentId = !empty($_POST['agent_id']) ? (int)$_POST['agent_id'] : null;
                $buyerId = !empty($_POST['buyer_id']) ? (int)$_POST['buyer_id'] : null;
                $vehicleType = trim($_POST['vehicle_type'] ?? '');
                $month = (int)($_POST['month'] ?? 0);
                $year = (int)($_POST['year'] ?? 0);
                $basis = trim($_POST['commission_basis'] ?? '');
                $rate = (float)($_POST['commission_rate'] ?? 0);
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                $isCustom = $agentId ? 1 : 0;

                if ($cid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid chart ID']);
                    exit();
                }
                if (empty($vehicleType)) {
                    echo json_encode(['success' => false, 'message' => 'Vehicle type is required']);
                    exit();
                }
                if ($month < 1 || $month > 12) {
                    echo json_encode(['success' => false, 'message' => 'Invalid month']);
                    exit();
                }
                if ($year < 2020 || $year > 2050) {
                    echo json_encode(['success' => false, 'message' => 'Invalid year']);
                    exit();
                }
                $validBasis = ['premium_pct','tp_pct','od_pct','net_pct','fixed'];
                if (!in_array($basis, $validBasis)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid commission basis']);
                    exit();
                }
                if ($rate <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Commission rate must be greater than 0']);
                    exit();
                }

                // unique check excluding self (NULL-safe)
                $uSql = "SELECT id FROM agent_payout_charts WHERE vehicle_type=? AND month=? AND year=? AND id!=?";
                $uTypes = "siii";
                $uParams = [$vehicleType, $month, $year, $cid];
                if ($agentId === null) {
                    $uSql .= " AND agent_id IS NULL";
                } else {
                    $uSql .= " AND agent_id=?";
                    $uTypes .= "i";
                    $uParams[] = $agentId;
                }
                if ($buyerId === null) {
                    $uSql .= " AND buyer_id IS NULL";
                } else {
                    $uSql .= " AND buyer_id=?";
                    $uTypes .= "i";
                    $uParams[] = $buyerId;
                }
                $stmt = $conn->prepare($uSql);
                $stmt->bind_param($uTypes, ...$uParams);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'A chart entry with this combination already exists']);
                    exit();
                }
                $stmt->close();

                $stmt = $conn->prepare("UPDATE agent_payout_charts SET agent_id=?, buyer_id=?, vehicle_type=?, month=?, year=?, commission_basis=?, commission_rate=?, is_custom=?, is_active=? WHERE id=?");
                $stmt->bind_param("iisiisdiii", $agentId, $buyerId, $vehicleType, $month, $year, $basis, $rate, $isCustom, $isActive, $cid);

                if (!$stmt->execute()) {
                    $err = $stmt->error;
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update chart: ' . $err]);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Agent Payout Chart Updated', "Updated chart #$cid: $vehicleType $basis=$rate");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Payout chart updated successfully']);
                exit();

            case 'deleteChart':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                    echo json_encode(['success' => false, 'message' => 'Invalid request']);
                    exit;
                }
                if (!$isAdmin) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $cid = (int)($_POST['id'] ?? 0);
                if ($cid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid chart ID']);
                    exit();
                }

                // get info before delete
                $stmt = $conn->prepare("SELECT vehicle_type, month, year FROM agent_payout_charts WHERE id=?");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $delRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $delInfo = $delRow ? "{$delRow['vehicle_type']} {$delRow['month']}/{$delRow['year']}" : 'Unknown';

                $stmt = $conn->prepare("DELETE FROM agent_payout_charts WHERE id=?");
                $stmt->bind_param("i", $cid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete chart']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Agent Payout Chart Deleted', "Deleted chart #$cid: $delInfo");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Payout chart deleted successfully']);
                exit();

            case 'bulkUpload':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                    echo json_encode(['success' => false, 'message' => 'Invalid request']);
                    exit;
                }
                if (!$isAdmin) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'message' => 'Please upload a valid CSV file']);
                    exit();
                }

                $month = (int)($_POST['bulk_month'] ?? 0);
                $year = (int)($_POST['bulk_year'] ?? 0);
                if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
                    echo json_encode(['success' => false, 'message' => 'Invalid month/year']);
                    exit();
                }

                $file = $_FILES['csv_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($ext !== 'csv') {
                    echo json_encode(['success' => false, 'message' => 'Only CSV files are allowed']);
                    exit();
                }

                // mime check
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                $okMimes = ['text/csv', 'text/plain', 'application/csv'];
                if (!in_array($mime, $okMimes)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid file type — upload a real CSV']);
                    exit();
                }

                // save upload record
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $fileName = 'agent_payout_' . date('Ymd_His') . '.csv';
                $filePath = $uploadDir . $fileName;
                move_uploaded_file($file['tmp_name'], $filePath);

                // log upload
                $stmt = $conn->prepare("INSERT INTO payout_chart_uploads (file_name, file_path, chart_type, month, year, status, uploaded_by) VALUES (?, ?, 'agent_payout', ?, ?, 'processing', ?)");
                $relPath = 'uploads/' . $fileName;
                $stmt->bind_param("ssiii", $fileName, $relPath, $month, $year, $user_id);
                $stmt->execute();
                $uploadId = $stmt->insert_id;
                $stmt->close();

                // parse CSV: agent_code, company_code, vehicle_type, commission_basis, commission_rate
                $handle = fopen($filePath, 'r');
                $header = fgetcsv($handle); // skip header row
                $validBasis = ['premium_pct','tp_pct','od_pct','net_pct','fixed'];
                $processed = 0;
                $failed = 0;
                $errors = [];

                // cache agents and buyers
                $agentMap = [];
                $stmt = $conn->prepare("SELECT id, agent_code FROM agents WHERE is_active=1");
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) $agentMap[$r['agent_code']] = (int)$r['id'];
                $stmt->close();

                $buyerMap = [];
                $stmt = $conn->prepare("SELECT id, company_code FROM buyers WHERE is_active=1");
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) $buyerMap[$r['company_code']] = (int)$r['id'];
                $stmt->close();

                $lineNum = 1;
                while (($row = fgetcsv($handle)) !== false) {
                    $lineNum++;
                    if (count($row) < 5) { $failed++; $errors[] = "Line $lineNum: insufficient columns"; continue; }

                    $agentCode = trim($row[0]);
                    $companyCode = trim($row[1]);
                    $vType = trim($row[2]);
                    $cBasis = trim($row[3]);
                    $cRate = (float)trim($row[4]);

                    // resolve agent
                    $aId = null;
                    $isC = 0;
                    if (!empty($agentCode)) {
                        if (!isset($agentMap[$agentCode])) { $failed++; $errors[] = "Line $lineNum: agent '$agentCode' not found"; continue; }
                        $aId = $agentMap[$agentCode];
                        $isC = 1;
                    }

                    // resolve buyer
                    $bId = null;
                    if (!empty($companyCode)) {
                        if (!isset($buyerMap[$companyCode])) { $failed++; $errors[] = "Line $lineNum: buyer '$companyCode' not found"; continue; }
                        $bId = $buyerMap[$companyCode];
                    }

                    if (empty($vType)) { $failed++; $errors[] = "Line $lineNum: vehicle type empty"; continue; }
                    if (!in_array($cBasis, $validBasis)) { $failed++; $errors[] = "Line $lineNum: invalid basis '$cBasis'"; continue; }
                    if ($cRate <= 0) { $failed++; $errors[] = "Line $lineNum: invalid rate"; continue; }

                    // upsert
                    $stmt = $conn->prepare("INSERT INTO agent_payout_charts (agent_id, buyer_id, vehicle_type, month, year, commission_basis, commission_rate, is_custom, is_active, uploaded_via, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'bulk_upload', ?)
                        ON DUPLICATE KEY UPDATE commission_basis=VALUES(commission_basis), commission_rate=VALUES(commission_rate), is_custom=VALUES(is_custom), uploaded_via='bulk_upload', is_active=1");
                    $stmt->bind_param("iisiisdii", $aId, $bId, $vType, $month, $year, $cBasis, $cRate, $isC, $user_id);

                    if ($stmt->execute()) {
                        $processed++;
                    } else {
                        $failed++;
                        $errors[] = "Line $lineNum: " . $stmt->error;
                    }
                    $stmt->close();
                }
                fclose($handle);

                // update upload record
                $status = ($failed === 0) ? 'completed' : (($processed > 0) ? 'completed' : 'failed');
                $errorLog = !empty($errors) ? implode("\n", $errors) : null;
                $stmt = $conn->prepare("UPDATE payout_chart_uploads SET records_processed=?, records_failed=?, status=?, error_log=?, completed_at=NOW() WHERE id=?");
                $stmt->bind_param("iissi", $processed, $failed, $status, $errorLog, $uploadId);
                $stmt->execute();
                $stmt->close();

                logActivity($user_id, $username, 'Agent Payout Bulk Upload', "Uploaded $fileName: $processed ok, $failed failed for $month/$year");
                $conn->close();

                $msg = "Processed: $processed, Failed: $failed";
                if (!empty($errors)) $msg .= "\n" . implode("\n", array_slice($errors, 0, 10));
                echo json_encode(['success' => true, 'message' => $msg, 'data' => ['processed' => $processed, 'failed' => $failed, 'errors' => array_slice($errors, 0, 10)]]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("agent_payout_charts.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
        exit();
    }
}

// month names for display
$monthNames = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

// deep-link: pre-select agent filter when arriving from agents.php
$preAgentId = isset($_GET['agent_id']) && ctype_digit((string)$_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
?>
<!--
  Developed by Mohammad Rameez Imdad (Rameez Scripts)
  WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
  YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
-->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="icon" href="<?php echo htmlspecialchars(getBranding()['site_logo']); ?>"><!-- tab icon, stops the browser asking for /favicon.ico -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Agent Payout Charts - Insurance Management System</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.1/dist/css/tabulator.min.css">
    <link rel="stylesheet" href="styles.css?v=16.0">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="app.js?v=2.3"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.1/dist/js/tabulator.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
</head>
<body>
<script>/* theme before paint */(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme:dark)').matches))document.body.classList.add('dark-mode');})();</script>
    <?php echo getSearchableDropdownJS(); ?>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <?php
            $pageIcon    = 'fa-hand-holding-usd';
            $pageTitle   = $isAgent ? 'My Payout Chart' : 'Agent Payout Charts';
            $crumbs      = $isAgent ? [] : [['label' => 'Payout', 'icon' => 'fa-money-check-alt']];
            $pageActions = [];
            if ($isAdmin) {
                $pageActions[] = ['label' => 'Add Chart', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
                $pageActions[] = ['label' => 'Bulk Upload', 'icon' => 'fa-file-upload', 'onclick' => 'openBulkModal()'];
            }
            $pageActions[] = ['label' => 'Print Chart', 'icon' => 'fa-file-invoice', 'onclick' => 'printChart()', 'class' => $isAdmin ? '' : 'primary'];
            $pageActions[] = ['label' => 'Chart PDF', 'icon' => 'fa-file-arrow-down', 'onclick' => "printChart('pdf')"];
            $pageActions[] = ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(chartsTable,'csv')"];
            $pageActions[] = ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(chartsTable,'pdf')"];
            $pageActions[] = ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(chartsTable,'print')"];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadCharts()'];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="chartsSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="chartsSearch" placeholder="Search charts…" autocomplete="off"></div>
                <div class="hf-field"><label for="selMonth" title="Month"><i class="fas fa-calendar-alt"></i></label><select id="selMonth" class="filter-input searchable">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo ($m == date('n')) ? 'selected' : ''; ?>><?php echo $monthNames[$m]; ?></option>
                    <?php endfor; ?>
                </select></div>
                <div class="hf-field"><label for="selYear" title="Year"><i class="fas fa-calendar"></i></label><select id="selYear" class="filter-input searchable">
                    <?php for ($y = date('Y') - 2; $y <= date('Y') + 2; $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo ($y == date('Y')) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select></div>
                <?php if (!$isAgent): ?>
                <div class="hf-field"><label for="filterAgent" title="Agent"><i class="fas fa-user-tie"></i></label><select id="filterAgent" class="filter-input searchable"><option value="">All Agents</option><option value="default">Default Only</option></select></div>
                <?php endif; ?>
                <div class="hf-field"><label for="filterBuyer" title="Buyer"><i class="fas fa-building"></i></label><select id="filterBuyer" class="filter-input searchable"><option value="">All Buyers</option><option value="all">All Buyers (Default)</option></select></div>
                <div class="hf-field"><label for="filterVehicle" title="Vehicle Type"><i class="fas fa-car"></i></label><select id="filterVehicle" class="filter-input searchable"><option value="">All Vehicle Types</option><?php echo vehicleCategoryOptionsHtml(); ?></select></div>
                <div class="hf-field"><label for="filterCustom" title="Custom"><i class="fas fa-star"></i></label><select id="filterCustom" class="filter-input searchable"><option value="">Custom: All</option><option value="Yes">Yes</option><option value="No">No</option></select></div>
                <button type="button" class="btn btn-icon" onclick="clearFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>

            <?php if ($isAgent): ?>
            <!-- Agent summary header -->
            <div class="data-section" id="agentSummarySection" style="display:none;">
                <div class="section-header">
                    <h2 id="agentSummaryTitle"><i class="fas fa-chart-bar"></i> Your Payout Rates</h2>
                </div>
            </div>
            <?php endif; ?>

            <div class="data-section">
                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- table -->
                <div id="tableContainer" style="display: none;">
                    <div id="emptyState" style="display: none;"></div>
                    <div id="chartsTableWrap">
                        <div id="chartsTable"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- Add/Edit Modal -->
    <div class="modal-overlay" id="chartModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-hand-holding-usd"></i> Add Payout Chart</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="chartForm">
                    <input type="hidden" id="chartId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Agent</label>
                            <select id="chartAgent" name="agent_id" class="searchable">
                                <option value="">Default for All Agents</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Buyer</label>
                            <select id="chartBuyer" name="buyer_id" class="searchable">
                                <option value="">All Buyers</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-car"></i> Vehicle Type *</label>
                            <select id="chartVehicle" name="vehicle_type" class="searchable" required>
                                <option value="">Select Type</option>
                                <?php echo vehicleCategoryOptionsHtml(); ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Month *</label>
                            <select id="chartMonth" name="month" class="searchable" required>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>"><?php echo $monthNames[$m]; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Year *</label>
                            <select id="chartYear" name="year" class="searchable" required>
                                <?php for ($y = date('Y') - 2; $y <= date('Y') + 2; $y++): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percentage"></i> Commission Basis *</label>
                            <select id="chartBasis" name="commission_basis" class="searchable" required>
                                <option value="">Select Basis</option>
                                <option value="premium_pct">Premium %</option>
                                <option value="tp_pct">TP %</option>
                                <option value="od_pct">OD %</option>
                                <option value="net_pct">Net %</option>
                                <option value="fixed">Fixed Amount</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-rupee-sign"></i> Commission Rate *</label>
                            <input type="number" id="chartRate" name="commission_rate" required step="0.0001" min="0.0001" placeholder="e.g. 5.5000">
                        </div>
                    </div>

                    <div class="form-group" id="statusToggleGroup" style="display: none;">
                        <div class="toggle-switch">
                            <input type="checkbox" class="toggle" id="chartIsActive" name="is_active" checked>
                            <label for="chartIsActive">Active</label>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Upload Modal -->
    <div class="modal-overlay" id="bulkModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-file-upload"></i> Bulk Upload Agent Payout Charts</h3>
                <button class="close-btn" onclick="closeBulkModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="bulkForm" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Month *</label>
                            <select id="bulkMonth" name="bulk_month" class="searchable" required>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo ($m == date('n')) ? 'selected' : ''; ?>>
                                    <?php echo $monthNames[$m]; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Year *</label>
                            <select id="bulkYear" name="bulk_year" class="searchable" required>
                                <?php for ($y = date('Y') - 2; $y <= date('Y') + 2; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo ($y == date('Y')) ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-file-csv"></i> CSV File *</label>
                        <input type="file" id="csvFile" name="csv_file" required accept=".csv,.xlsx,.xls" class="file-input">
                        <small>Excel (.xlsx/.xls) or CSV. Columns: agent_code (empty=default), company_code (empty=all buyers), vehicle_type, commission_basis, commission_rate</small>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-upload"></i> Upload
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="downloadChartTemplate()">
                            <i class="fas fa-download"></i> Template
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeBulkModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        /** Developed by Mohammad Rameez Imdad (Rameez Scripts) */
        const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        const IS_AGENT = <?php echo $isAgent ? 'true' : 'false'; ?>;
        const PRE_AGENT_ID = <?php echo $preAgentId; ?>;
        const MONTH_NAMES = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

        let chartsTable = null;
        let chartsData = [];
        let agentsList = [];
        let buyersList = [];
        let isEditMode = false;

        function escHtml(s) {
            if (!s) return '';
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        $(document).ready(function() {
            loadDropdowns();
            loadCharts();

            // bind month/year change
            $('#selMonth, #selYear').on('change', loadCharts);
            <?php if (!$isAgent): ?>
            $('#filterAgent').on('change', loadCharts);
            <?php endif; ?>
            $('#filterBuyer').on('change', loadCharts);
            // head search -> table
            // widget shows the placeholder until refreshed -> show the preselected period
            setTimeout(function(){ ['selMonth', 'selYear'].forEach(function(id) { var el = document.getElementById(id); if (el && el.sdRefresh) el.sdRefresh(); }); }, 300);
            $('#chartsSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(chartsTable, v); },200); });
        });

        // clear-filters icon: period stays, the rest resets
        function clearFilters() {
            $('#chartsSearch').val('');
            ['filterAgent', 'filterBuyer', 'filterVehicle', 'filterCustom'].forEach(function(id) { var el = document.getElementById(id); if (!el) return; el.value = ''; if (el.sdRefresh) el.sdRefresh(); });
            rsTbClear(chartsTable);
            loadCharts();
        }

        function loadDropdowns() {
            // agents
            $.ajax({
                url: '?action=getAgents',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (!res.success) return;
                    agentsList = res.data;
                    <?php if (!$isAgent): ?>
                    const sel = $('#filterAgent');
                    res.data.forEach(function(a) {
                        sel.append('<option value="' + a.id + '">' + escHtml(a.name) + ' (' + escHtml(a.agent_code) + ')</option>');
                    });
                    // honor deep-link agent filter from agents.php
                    if (PRE_AGENT_ID > 0 && sel.find('option[value="' + PRE_AGENT_ID + '"]').length) {
                        sel.val(PRE_AGENT_ID);
                        loadCharts();
                    }
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                    const mSel = $('#chartAgent');
                    res.data.forEach(function(a) {
                        mSel.append('<option value="' + a.id + '">' + escHtml(a.name) + ' (' + escHtml(a.agent_code) + ')</option>');
                    });
                    <?php endif; ?>
                    setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
                }
            });

            // buyers
            $.ajax({
                url: '?action=getBuyers',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (!res.success) return;
                    buyersList = res.data;
                    const sel = $('#filterBuyer');
                    res.data.forEach(function(b) {
                        sel.append('<option value="' + b.id + '">' + escHtml(b.company_name) + ' (' + escHtml(b.company_code) + ')</option>');
                    });
                    <?php if ($isAdmin): ?>
                    const mSel = $('#chartBuyer');
                    res.data.forEach(function(b) {
                        mSel.append('<option value="' + b.id + '">' + escHtml(b.company_name) + ' (' + escHtml(b.company_code) + ')</option>');
                    });
                    <?php endif; ?>
                    setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
                }
            });
        }

        function loadCharts() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();

            const params = {
                action: 'getCharts',
                month: $('#selMonth').val(),
                year: $('#selYear').val(),
                buyer_id: $('#filterBuyer').val()
            };
            <?php if (!$isAgent): ?>
            params.agent_id = $('#filterAgent').val();
            <?php endif; ?>

            $.ajax({
                url: '?' + $.param(params),
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        chartsData = res.data;
                        $('#loadingSkeleton').hide();
                        $('#tableContainer').show();

                        <?php if ($isAgent): ?>
                        // update agent summary
                        var m = MONTH_NAMES[parseInt($('#selMonth').val())];
                        var y = $('#selYear').val();
                        $('#agentSummaryTitle').html('<i class="fas fa-chart-bar"></i> Your Payout Rates for ' + m + ' ' + y);
                        $('#agentSummarySection').show();
                        <?php endif; ?>

                        // empty-state CTA when no charts
                        if (!res.data.length) {
                            rsTbData(chartsTable, []); // keep the table, just empty it
                            $('#emptyState').html(emptyStateHtml({
                                icon: 'fa-hand-holding-usd',
                                title: 'No payout charts found',
                                text: 'No agent payout charts match the selected period and filters.',
                                btnText: IS_ADMIN ? 'Add Chart' : '',
                                btnId: IS_ADMIN ? 'emptyAddBtn' : ''
                            })).show();
                            $('#chartsTableWrap').hide();
                            <?php if ($isAdmin): ?>
                            $('#emptyAddBtn').off('click').on('click', openAddModal);
                            <?php endif; ?>
                            return;
                        }
                        $('#emptyState').hide();
                        $('#chartsTableWrap').show();

                        setTimeout(function() { initDataTable(res.data); }, 100);
                    } else {
                        $('#loadingSkeleton').hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load charts' });
                    }
                },
                error: function() {
                    $('#loadingSkeleton').hide();
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
                }
            });
        }

        function basisLabel(b) {
            var map = { 'premium_pct': 'Premium %', 'tp_pct': 'TP %', 'od_pct': 'OD %', 'net_pct': 'Net %', 'fixed': 'Fixed' };
            return map[b] || b;
        }

        function initDataTable(data) {
            if (chartsTable) { rsTbData(chartsTable, data); chartsTable.redraw(); return; } // build once, swap rows

            var cols = [
                { data: 'id', title: 'ID' },
                {
                    data: 'agent_name',
                    title: 'Agent',
                    render: function(d, t, row) {
                        if (!row.agent_id) return '<span class="status-badge status-active">Default</span>';
                        return escHtml(d) + ' <small>(' + escHtml(row.agent_code) + ')</small>';
                    }
                },
                {
                    data: 'company_name',
                    title: 'Buyer',
                    render: function(d, t, row) {
                        if (!row.buyer_id) return '<span class="status-badge status-active">All</span>';
                        return escHtml(d);
                    }
                },
                { data: 'vehicle_type', title: 'Vehicle Type', render: function(d) { return escHtml(d); } },
                {
                    data: null,
                    title: 'Month/Year',
                    render: function(d, t, row) {
                        return MONTH_NAMES[row.month] + ' ' + row.year;
                    }
                },
                {
                    data: 'commission_basis',
                    title: 'Basis',
                    render: function(d) { return basisLabel(d); }
                },
                {
                    data: 'commission_rate',
                    title: 'Rate',
                    render: function(d) { return parseFloat(d).toFixed(4); }
                },
                {
                    data: 'is_custom',
                    title: 'Custom',
                    render: function(d) {
                        return d
                            ? '<span class="status-badge status-active">Yes</span>'
                            : '<span class="status-badge status-inactive">No</span>';
                    }
                },
                {
                    data: 'is_active',
                    title: 'Status',
                    render: function(d) {
                        return d
                            ? '<span class="status-badge status-active">Active</span>'
                            : '<span class="status-badge status-inactive">Inactive</span>';
                    }
                },
                {
                    data: 'uploaded_via',
                    title: 'Uploaded Via',
                    render: function(d) {
                        return d === 'bulk_upload' ? 'Bulk Upload' : 'Manual';
                    }
                }
            ];

            // action hub for admin - edit/delete in ⋮ More (no detail view)
            if (IS_ADMIN) {
                cols.push({
                    data: null,
                    title: 'Actions',
                    orderable: false,
                    render: function(d, t, row) {
                        return '<div class="action-hub">' +
                            '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button>' +
                            '<div class="more-dropdown">' +
                            '<button class="more-item" data-act="edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> Edit</button>' +
                            '<div class="more-divider"></div>' +
                            '<button class="more-item danger" data-act="delete" data-id="' + row.id + '"><i class="fas fa-trash"></i> Delete</button>' +
                            '</div></div></div>';
                    }
                });
            }

            chartsTable = rsTable('#chartsTable', {
                columns: cols, data: data, title: 'Agent Payout Charts', file: 'agent_payout_charts', sort: ['id', 'desc'],
                // inactive muted, custom highlighted
                rowClass: function(r) { return !r.is_active ? 'dt-row-muted' : (r.is_custom ? 'dt-row-success' : ''); },
                info: function() {
                    return [['Period', $('#selMonth option:selected').text() + ' ' + $('#selYear').val()],
                        ['Vehicle Type', $('#filterVehicle').val() || 'All'], ['Custom', $('#filterCustom').val() || 'All']];
                }
            });
            rsTbSearch(chartsTable, $('#chartsSearch').val());

            // single delegated handler for the action hub
            $('#chartsTable').off('click', '[data-act]').on('click', '[data-act]', function() {
                var act = $(this).data('act'), id = $(this).data('id');
                if (act === 'edit') editChart(id);
                else if (act === 'delete') deleteChart(id);
            });

            // client-side filters
            $('#filterVehicle, #filterCustom').off('change').on('change', applyLocalFilters);
            applyLocalFilters();
        }

        function applyLocalFilters() {
            var vType = $('#filterVehicle').val(), custom = $('#filterCustom').val();
            rsTbWhere(chartsTable, 'vehicle', vType ? function(r) { return r.vehicle_type === vType; } : null);
            rsTbWhere(chartsTable, 'custom', custom ? function(r) { return !!Number(r.is_custom) === (custom === 'Yes'); } : null);
        }

        <?php if ($isAdmin): ?>
        function openAddModal() {
            isEditMode = false;
            $('#modalTitle').html('<i class="fas fa-hand-holding-usd"></i> Add Payout Chart');
            $('#chartForm')[0].reset();
            $('#chartId').val('');
            $('#chartMonth').val($('#selMonth').val());
            $('#chartYear').val($('#selYear').val());
            $('#chartIsActive').prop('checked', true);
            $('#statusToggleGroup').hide();
            $('#chartModal').addClass('active');
        }

        function editChart(cid) {
            var chart = chartsData.find(function(c) { return c.id === cid; });
            if (!chart) return;
            isEditMode = true;
            $('#modalTitle').html('<i class="fas fa-edit"></i> Edit Payout Chart');
            $('#chartId').val(chart.id);
            $('#chartAgent').val(chart.agent_id || '');
            $('#chartBuyer').val(chart.buyer_id || '');
            $('#chartVehicle').val(chart.vehicle_type);
            $('#chartMonth').val(chart.month);
            $('#chartYear').val(chart.year);
            $('#chartBasis').val(chart.commission_basis);
            $('#chartRate').val(chart.commission_rate);
            $('#chartIsActive').prop('checked', !!chart.is_active);
            $('#statusToggleGroup').show();
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
            $('#chartModal').addClass('active');
        }

        function closeModal() {
            $('#chartModal').removeClass('active');
            $('#chartForm')[0].reset();
        }

        document.getElementById('chartModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // form submit
        $('#chartForm').on('submit', function(e) {
            e.preventDefault();

            var formData = new FormData(this);
            var action = isEditMode ? 'updateChart' : 'addChart';

            if (!$('#chartIsActive').is(':checked')) {
                formData.delete('is_active');
            }
            formData.append('csrf_token', window.CSRF_TOKEN);

            showLoading('Processing...');

            $.ajax({
                url: '?action=' + action,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    hideLoading();
                    if (res.success) {
                        Swal.fire({ icon: 'success', title: 'Success!', text: res.message, timer: 2000, showConfirmButton: false });
                        closeModal();
                        setTimeout(loadCharts, 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                    }
                },
                error: function(xhr, status, err) {
                    hideLoading();
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + err });
                }
            });
        });

        function deleteChart(cid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Chart Entry?',
                text: 'This action cannot be undone',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) return;

                var fd = new FormData();
                fd.append('id', cid);
                fd.append('csrf_token', window.CSRF_TOKEN);

                $.ajax({
                    url: '?action=deleteChart',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                            setTimeout(loadCharts, 100);
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                        }
                    },
                    error: function(xhr, status, err) {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + err });
                    }
                });
            });
        }

        // bulk upload
        function openBulkModal() {
            $('#bulkForm')[0].reset();
            $('#bulkMonth').val($('#selMonth').val());
            $('#bulkYear').val($('#selYear').val());
            $('#bulkModal').addClass('active');
        }

        function closeBulkModal() {
            $('#bulkModal').removeClass('active');
            $('#bulkForm')[0].reset();
        }

        document.getElementById('bulkModal').addEventListener('click', function(e) {
            if (e.target === this) closeBulkModal();
        });

        // excel -> csv client-side, so the server keeps its existing csv pipeline + upload audit row
        function sheetToCsvFile(file, done) {
            if (!/\.(xlsx|xls)$/i.test(file.name)) { done(file); return; }
            if (typeof XLSX === 'undefined') { Swal.fire({ icon: 'error', title: 'Excel library not loaded', text: 'Check your internet connection and reload the page.' }); return; }
            var rd = new FileReader();
            rd.onload = function(e) {
                try {
                    var wb = XLSX.read(e.target.result, { type: 'array' });
                    var csv = XLSX.utils.sheet_to_csv(wb.Sheets[wb.SheetNames[0]]);
                    done(new File([csv], file.name.replace(/\.(xlsx|xls)$/i, '') + '.csv', { type: 'text/csv' }));
                } catch (err) { Swal.fire({ icon: 'error', title: 'Parse Error', text: 'Could not read file: ' + err.message }); }
            };
            rd.readAsArrayBuffer(file);
        }

        function downloadChartTemplate() {
            if (typeof XLSX === 'undefined') { Swal.fire({ icon: 'error', title: 'Excel library not loaded', text: 'Check your internet connection and reload the page.' }); return; }
            var wb = XLSX.utils.book_new();
            // cols are positional on the server: agent_code, company_code, vehicle_type, commission_basis, commission_rate
            var ws = XLSX.utils.aoa_to_sheet([
                ['agent_code', 'company_code', 'vehicle_type', 'commission_basis', 'commission_rate'],
                ['', '', 'Car', 'premium_pct', 15],
                ['AGT-0001', '', 'Two Wheeler', 'od_pct', 20]
            ]);
            ws['!cols'] = [{ wch: 14 }, { wch: 14 }, { wch: 18 }, { wch: 18 }, { wch: 16 }];
            XLSX.utils.book_append_sheet(wb, ws, 'Agent Payout');
            XLSX.writeFile(wb, 'agent_payout_chart_template.xlsx');
        }

        $('#bulkForm').on('submit', function(e) {
            e.preventDefault();

            var form = this, picked = $('#csvFile')[0].files[0];
            if (!picked) { Swal.fire({ icon: 'info', text: 'Choose a CSV or Excel file' }); return; }
            sheetToCsvFile(picked, function(csvFile) { postBulk(form, csvFile); });
        });

        function postBulk(form, csvFile) {
            var formData = new FormData(form);
            formData.set('csv_file', csvFile); // excel rows arrive as csv
            formData.append('csrf_token', window.CSRF_TOKEN);

            showLoading('Uploading...');

            $.ajax({
                url: '?action=bulkUpload',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    hideLoading();
                    if (res.success) {
                        var msg = 'Processed: ' + res.data.processed + ', Failed: ' + res.data.failed;
                        if (res.data.errors && res.data.errors.length > 0) {
                            msg += '\n\nErrors:\n' + res.data.errors.join('\n');
                        }
                        Swal.fire({ icon: 'success', title: 'Upload Complete', text: msg });
                        closeBulkModal();
                        setTimeout(loadCharts, 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                    }
                },
                error: function(xhr, status, err) {
                    hideLoading();
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + err });
                }
            });
        }
        <?php endif; ?>

        // filtered chart -> A4 print / PDF
        function printChart(kind) {
            if (!chartsTable) { Swal.fire('Info','No data to print','info'); return; }
            var rows = rsTbRows(chartsTable);
            if (!rows.length) { Swal.fire('Info','No data to print','info'); return; }

            var month = document.getElementById('selMonth')?.value || '';
            var year = document.getElementById('selYear')?.value || '';
            var period = MONTH_NAMES[parseInt(month)] + ' ' + year;
            var base = rsTbDoc(chartsTable); // rows / filters / generated by
            rsReportExport(kind || 'print', {
                title: 'Agent Payout Chart', number: 'APC-' + year + String(month).padStart(2, '0'),
                info: [['Period', period]].concat(base.info.map(function(i) { return i[0] === 'Filters' ? [i[0], i[1].split(' · ').filter(function(f) { return f.indexOf('Period:') !== 0; }).join(' · ') || 'None'] : i; })),
                cols: [
                    { title: '#', field: '#', align: 'center' },
                    { title: 'Agent', get: function(r) { return r.agent_id ? r.agent_name : 'Default'; }, bold: true, sub: function(r) { return r.agent_id ? r.agent_code : ''; } },
                    { title: 'Buyer', get: function(r) { return r.buyer_id ? r.company_name : 'All'; } },
                    { title: 'Vehicle type', field: 'vehicle_type' },
                    { title: 'Basis', get: function(r) { return basisLabel(r.commission_basis); } },
                    { title: 'Rate', get: function(r) { return parseFloat(r.commission_rate).toFixed(4); }, align: 'right' },
                    { title: 'Custom', get: function(r) { return r.is_custom ? 'Yes' : 'No'; }, align: 'center' },
                    { title: 'Status', get: function(r) { return r.is_active ? 'Active' : 'Inactive'; } }
                ],
                rows: rows
            }, 'agent_payout_chart_' + year + '_' + String(month).padStart(2, '0'));
        }
    </script>
</body>
</html>
