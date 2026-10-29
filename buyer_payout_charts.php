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

// auth check
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout()) { header("Location: login.php"); exit(); }

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'buyer_payouts';

// agent cant access
if ($role === 'agent') { header("Location: dashboard.php"); exit(); }

// valid basis values
$validBasis = ['premium_pct', 'tp_pct', 'od_pct', 'net_pct', 'fixed'];
$basisLabels = [
    'premium_pct' => 'Premium %',
    'tp_pct' => 'TP %',
    'od_pct' => 'OD %',
    'net_pct' => 'Net %',
    'fixed' => 'Fixed'
];
$monthNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getBuyers':
                $stmt = $conn->prepare("SELECT id, company_name, company_code FROM buyers WHERE is_active = 1 ORDER BY company_name");
                $stmt->execute();
                $res = $stmt->get_result();
                $buyers = [];
                while ($row = $res->fetch_assoc()) {
                    $buyers[] = [
                        'id' => (int)$row['id'],
                        'company_name' => $row['company_name'],
                        'company_code' => $row['company_code']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $buyers]);
                exit();

            case 'getCharts':
                $buyerFilter = isset($_GET['buyer_id']) ? (int)$_GET['buyer_id'] : 0;
                $monthFilter = isset($_GET['month']) ? (int)$_GET['month'] : 0;
                $yearFilter = isset($_GET['year']) ? (int)$_GET['year'] : 0;

                $sql = "SELECT bpc.*, b.company_name, b.company_code
                        FROM buyer_payout_charts bpc
                        JOIN buyers b ON bpc.buyer_id = b.id
                        WHERE 1=1";
                $params = [];
                $types = '';

                if ($buyerFilter > 0) {
                    $sql .= " AND bpc.buyer_id = ?";
                    $types .= 'i';
                    $params[] = $buyerFilter;
                }
                if ($monthFilter > 0 && $monthFilter <= 12) {
                    $sql .= " AND bpc.month = ?";
                    $types .= 'i';
                    $params[] = $monthFilter;
                }
                if ($yearFilter > 0) {
                    $sql .= " AND bpc.year = ?";
                    $types .= 'i';
                    $params[] = $yearFilter;
                }

                $sql .= " ORDER BY bpc.year DESC, bpc.month DESC, b.company_name, bpc.vehicle_type";

                $stmt = $conn->prepare($sql);
                if (!empty($params)) {
                    $stmt->bind_param($types, ...$params);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $charts = [];
                while ($row = $res->fetch_assoc()) {
                    $charts[] = [
                        'id' => (int)$row['id'],
                        'buyer_id' => (int)$row['buyer_id'],
                        'company_name' => $row['company_name'],
                        'company_code' => $row['company_code'],
                        'vehicle_type' => $row['vehicle_type'],
                        'month' => (int)$row['month'],
                        'year' => (int)$row['year'],
                        'commission_basis' => $row['commission_basis'],
                        'commission_rate' => (float)$row['commission_rate'],
                        'is_active' => (int)$row['is_active'],
                        'uploaded_via' => $row['uploaded_via'],
                        'created_at' => $row['created_at']
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
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $buyerId = (int)($_POST['buyer_id'] ?? 0);
                $vehicleType = trim($_POST['vehicle_type'] ?? '');
                $month = (int)($_POST['month'] ?? 0);
                $year = (int)($_POST['year'] ?? 0);
                $basis = trim($_POST['commission_basis'] ?? '');
                $rate = (float)($_POST['commission_rate'] ?? 0);

                // validate
                if ($buyerId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Select a buyer']);
                    exit();
                }
                if (empty($vehicleType) || strlen($vehicleType) > 50) {
                    echo json_encode(['success' => false, 'message' => 'Vehicle type is required (max 50 chars)']);
                    exit();
                }
                if ($month < 1 || $month > 12) {
                    echo json_encode(['success' => false, 'message' => 'Invalid month']);
                    exit();
                }
                if ($year < 2020 || $year > 2099) {
                    echo json_encode(['success' => false, 'message' => 'Invalid year']);
                    exit();
                }
                if (!in_array($basis, $validBasis)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid commission basis']);
                    exit();
                }
                if ($rate <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Commission rate must be > 0']);
                    exit();
                }

                // check unique
                $stmt = $conn->prepare("SELECT id FROM buyer_payout_charts WHERE buyer_id = ? AND vehicle_type = ? AND month = ? AND year = ?");
                $stmt->bind_param("isii", $buyerId, $vehicleType, $month, $year);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Entry already exists for this buyer/vehicle/month/year']);
                    exit();
                }
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO buyer_payout_charts (buyer_id, vehicle_type, month, year, commission_basis, commission_rate, is_active, uploaded_via, created_by) VALUES (?, ?, ?, ?, ?, ?, 1, 'manual', ?)");
                $stmt->bind_param("isiisdi", $buyerId, $vehicleType, $month, $year, $basis, $rate, $user_id);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add chart entry']);
                    exit();
                }
                $insertId = $stmt->insert_id;
                $stmt->close();

                logActivity($user_id, $username, 'Payout Chart Created', "Buyer #$buyerId, $vehicleType, $month/$year, $basis=$rate");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Chart entry added', 'data' => ['id' => $insertId]]);
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
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $cid = (int)($_POST['id'] ?? 0);
                $buyerId = (int)($_POST['buyer_id'] ?? 0);
                $vehicleType = trim($_POST['vehicle_type'] ?? '');
                $month = (int)($_POST['month'] ?? 0);
                $year = (int)($_POST['year'] ?? 0);
                $basis = trim($_POST['commission_basis'] ?? '');
                $rate = (float)($_POST['commission_rate'] ?? 0);
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                if ($cid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid chart ID']);
                    exit();
                }
                if ($buyerId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Select a buyer']);
                    exit();
                }
                if (empty($vehicleType) || strlen($vehicleType) > 50) {
                    echo json_encode(['success' => false, 'message' => 'Vehicle type is required (max 50 chars)']);
                    exit();
                }
                if ($month < 1 || $month > 12) {
                    echo json_encode(['success' => false, 'message' => 'Invalid month']);
                    exit();
                }
                if ($year < 2020 || $year > 2099) {
                    echo json_encode(['success' => false, 'message' => 'Invalid year']);
                    exit();
                }
                if (!in_array($basis, $validBasis)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid commission basis']);
                    exit();
                }
                if ($rate <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Commission rate must be > 0']);
                    exit();
                }

                // check unique (exclude self)
                $stmt = $conn->prepare("SELECT id FROM buyer_payout_charts WHERE buyer_id = ? AND vehicle_type = ? AND month = ? AND year = ? AND id != ?");
                $stmt->bind_param("isiii", $buyerId, $vehicleType, $month, $year, $cid);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Duplicate entry for this buyer/vehicle/month/year']);
                    exit();
                }
                $stmt->close();

                $stmt = $conn->prepare("UPDATE buyer_payout_charts SET buyer_id=?, vehicle_type=?, month=?, year=?, commission_basis=?, commission_rate=?, is_active=? WHERE id=?");
                $stmt->bind_param("isiisdii", $buyerId, $vehicleType, $month, $year, $basis, $rate, $isActive, $cid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update chart entry']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Payout Chart Updated', "Chart #$cid, Buyer #$buyerId, $vehicleType, $month/$year");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Chart entry updated']);
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
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $cid = (int)($_POST['id'] ?? 0);
                if ($cid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid chart ID']);
                    exit();
                }

                // get info before delete
                $stmt = $conn->prepare("SELECT bpc.vehicle_type, bpc.month, bpc.year, b.company_name FROM buyer_payout_charts bpc JOIN buyers b ON bpc.buyer_id=b.id WHERE bpc.id=?");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $delRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $delInfo = $delRow ? "{$delRow['company_name']} - {$delRow['vehicle_type']} ({$delRow['month']}/{$delRow['year']})" : "Unknown #$cid";

                $stmt = $conn->prepare("DELETE FROM buyer_payout_charts WHERE id = ?");
                $stmt->bind_param("i", $cid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete chart entry']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Payout Chart Deleted', "Deleted: $delInfo");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Chart entry deleted']);
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
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $bulkMonth = (int)($_POST['bulk_month'] ?? 0);
                $bulkYear = (int)($_POST['bulk_year'] ?? 0);

                if ($bulkMonth < 1 || $bulkMonth > 12 || $bulkYear < 2020 || $bulkYear > 2099) {
                    echo json_encode(['success' => false, 'message' => 'Invalid month/year for bulk upload']);
                    exit();
                }

                if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'message' => 'CSV file is required']);
                    exit();
                }

                $file = $_FILES['csv_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($ext !== 'csv') {
                    echo json_encode(['success' => false, 'message' => 'Only CSV files accepted']);
                    exit();
                }

                // read csv
                $handle = fopen($file['tmp_name'], 'r');
                if (!$handle) {
                    echo json_encode(['success' => false, 'message' => 'Could not read CSV file']);
                    exit();
                }

                // skip header
                $header = fgetcsv($handle);
                if (!$header || count($header) < 4) {
                    fclose($handle);
                    echo json_encode(['success' => false, 'message' => 'CSV must have columns: company_code, vehicle_type, commission_basis, commission_rate']);
                    exit();
                }

                // cache buyer lookup
                $buyerStmt = $conn->prepare("SELECT id, company_name FROM buyers WHERE company_code = ? AND is_active = 1");
                $buyerCache = [];

                $processed = 0;
                $failed = 0;
                $errors = [];
                $rowNum = 1; // after header

                while (($csvRow = fgetcsv($handle)) !== false) {
                    $rowNum++;

                    // skip empty rows
                    if (!$csvRow || (count($csvRow) === 1 && trim($csvRow[0]) === '')) continue;

                    if (count($csvRow) < 4) {
                        $failed++;
                        $errors[] = "Row $rowNum: insufficient columns";
                        continue;
                    }

                    $compCode = trim($csvRow[0]);
                    $vType = trim($csvRow[1]);
                    $cBasis = trim($csvRow[2]);
                    $cRate = (float)trim($csvRow[3]);

                    // validate company_code
                    if (empty($compCode)) {
                        $failed++;
                        $errors[] = "Row $rowNum: empty company_code";
                        continue;
                    }

                    // lookup buyer (cached)
                    if (!isset($buyerCache[$compCode])) {
                        $buyerStmt->bind_param("s", $compCode);
                        $buyerStmt->execute();
                        $bRes = $buyerStmt->get_result()->fetch_assoc();
                        $buyerCache[$compCode] = $bRes ?: false;
                    }

                    if (!$buyerCache[$compCode]) {
                        $failed++;
                        $errors[] = "Row $rowNum: buyer '$compCode' not found";
                        continue;
                    }

                    $bId = (int)$buyerCache[$compCode]['id'];

                    // validate vehicle type
                    if (empty($vType) || strlen($vType) > 50) {
                        $failed++;
                        $errors[] = "Row $rowNum: invalid vehicle_type";
                        continue;
                    }

                    // validate basis
                    if (!in_array($cBasis, $validBasis)) {
                        $failed++;
                        $errors[] = "Row $rowNum: invalid basis '$cBasis'";
                        continue;
                    }

                    // validate rate
                    if ($cRate <= 0) {
                        $failed++;
                        $errors[] = "Row $rowNum: rate must be > 0";
                        continue;
                    }

                    // upsert
                    $upsert = $conn->prepare("INSERT INTO buyer_payout_charts (buyer_id, vehicle_type, month, year, commission_basis, commission_rate, is_active, uploaded_via, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, 1, 'bulk_upload', ?)
                        ON DUPLICATE KEY UPDATE commission_basis=VALUES(commission_basis), commission_rate=VALUES(commission_rate), uploaded_via='bulk_upload', updated_at=NOW()");
                    $upsert->bind_param("isiisdi", $bId, $vType, $bulkMonth, $bulkYear, $cBasis, $cRate, $user_id);

                    if ($upsert->execute()) {
                        $processed++;
                    } else {
                        $failed++;
                        $errors[] = "Row $rowNum: DB error";
                    }
                    $upsert->close();
                }

                fclose($handle);
                $buyerStmt->close();

                // save upload record
                $fileName = $file['name'];
                $upStatus = $processed > 0 ? 'completed' : 'failed';
                $errorLog = $errors ? implode("\n", $errors) : null;
                $stmt = $conn->prepare("INSERT INTO payout_chart_uploads (file_name, file_path, chart_type, month, year, records_processed, records_failed, status, error_log, uploaded_by, completed_at) VALUES (?, '', 'buyer_payout', ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("siiiissi", $fileName, $bulkMonth, $bulkYear, $processed, $failed, $upStatus, $errorLog, $user_id);
                $stmt->execute();
                $stmt->close();

                logActivity($user_id, $username, 'Payout Chart Bulk Upload', "Month: $bulkMonth/$bulkYear, Processed: $processed, Failed: $failed");
                $conn->close();
                echo json_encode([
                    'success' => true,
                    'message' => "Bulk upload complete",
                    'processed' => $processed,
                    'failed' => $failed,
                    'errors' => array_slice($errors, 0, 20) // limit errors shown
                ]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("buyer_payout_charts.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
        exit();
    }
}

$isAdmin = ($role === 'admin');

// deep-link: pre-select buyer filter when arriving from buyers.php
$preBuyerId = isset($_GET['buyer_id']) && ctype_digit((string)$_GET['buyer_id']) ? (int)$_GET['buyer_id'] : 0;
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
    <title>Buyer Payout Charts - Insurance Management System</title>

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
            $pageIcon    = 'fa-chart-bar';
            $pageTitle   = 'Buyer Payout Charts';
            $crumbs      = [['label' => 'Payout', 'icon' => 'fa-chart-line']];
            $pageActions = [];
            if ($isAdmin) $pageActions[] = ['label' => 'Add Entry', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
            $pageActions[] = ['label' => 'Print Chart', 'icon' => 'fa-file-invoice', 'onclick' => 'printChart()'];
            $pageActions[] = ['label' => 'Chart PDF', 'icon' => 'fa-file-arrow-down', 'onclick' => "printChart('pdf')"];
            $pageActions[] = ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(chartsTable,'csv')"];
            $pageActions[] = ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(chartsTable,'pdf')"];
            $pageActions[] = ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(chartsTable,'print')"];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadCharts()'];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="chartsSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="chartsSearch" placeholder="Search charts…" autocomplete="off"></div>
                <div class="hf-field"><label for="selMonth" title="Month"><i class="fas fa-calendar"></i></label><select id="selMonth" class="filter-input searchable">
                    <option value="1">January</option>
                    <option value="2">February</option>
                    <option value="3">March</option>
                    <option value="4">April</option>
                    <option value="5">May</option>
                    <option value="6">June</option>
                    <option value="7">July</option>
                    <option value="8">August</option>
                    <option value="9">September</option>
                    <option value="10">October</option>
                    <option value="11">November</option>
                    <option value="12">December</option>
                </select></div>
                <div class="hf-field"><label for="selYear" title="Year"><i class="fas fa-calendar-check"></i></label><select id="selYear" class="filter-input searchable"></select></div>
                <div class="hf-field"><label for="filterBuyer" title="Buyer"><i class="fas fa-building"></i></label><select id="filterBuyer" class="filter-input searchable">
                    <option value="">All Buyers</option>
                </select></div>
                <div class="hf-field"><label for="filterVehicle" title="Vehicle Type"><i class="fas fa-car"></i></label><select id="filterVehicle" class="filter-input searchable">
                    <option value="">All Vehicles</option>
                    <?php echo vehicleCategoryOptionsHtml(); ?>
                </select></div>
                <button type="button" class="btn btn-icon" onclick="clearFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>

            <!-- Charts Table -->
            <div class="data-section">
                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.4"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.6"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.6"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.6"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.4"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.6"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.6"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.6"></div>
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

            <?php if ($isAdmin): ?>
            <!-- Bulk Upload Section -->
            <div class="data-section">
                <div class="section-header">
                    <h2><i class="fas fa-file-upload"></i> Bulk Upload</h2>
                </div>
                <div class="filters-grid">
                    <div class="filter-group">
                        <label><i class="fas fa-calendar"></i> Month</label>
                        <select id="bulkMonth" class="filter-input searchable">
                            <option value="1">January</option>
                            <option value="2">February</option>
                            <option value="3">March</option>
                            <option value="4">April</option>
                            <option value="5">May</option>
                            <option value="6">June</option>
                            <option value="7">July</option>
                            <option value="8">August</option>
                            <option value="9">September</option>
                            <option value="10">October</option>
                            <option value="11">November</option>
                            <option value="12">December</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-check"></i> Year</label>
                        <select id="bulkYear" class="filter-input searchable"></select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-file-excel"></i> Excel / CSV File</label>
                        <input type="file" id="csvFile" accept=".csv,.xlsx,.xls" class="file-input">
                    </div>
                    <div class="filter-group" style="display: flex; align-items: flex-end; gap: 8px;">
                        <button class="btn btn-primary" onclick="bulkUpload()">
                            <i class="fas fa-upload"></i> Upload
                        </button>
                        <button class="btn btn-secondary" onclick="downloadChartTemplate()">
                            <i class="fas fa-download"></i> Template
                        </button>
                    </div>
                </div>
                <div class="filter-group" style="margin-top: 8px;">
                    <small><i class="fas fa-info-circle"></i> CSV format: <code>company_code, vehicle_type, commission_basis, commission_rate</code></small>
                    <br><small>Valid basis values: <code>premium_pct, tp_pct, od_pct, net_pct, fixed</code></small>
                </div>
                <div id="bulkResults" style="display: none; margin-top: 12px;"></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- Chart Entry Modal -->
    <div class="modal-overlay" id="chartModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-chart-bar"></i> Add Payout Entry</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="chartForm">
                    <input type="hidden" id="chartId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Buyer *</label>
                            <select id="chartBuyer" name="buyer_id" class="searchable" required>
                                <option value="">-- Select Buyer --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-car"></i> Vehicle Type *</label>
                            <select id="chartVehicle" name="vehicle_type" class="searchable" required>
                                <option value="">-- Select --</option>
                                <?php echo vehicleCategoryOptionsHtml(); ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Month *</label>
                            <select id="chartMonth" name="month" class="searchable" required>
                                <option value="1">January</option>
                                <option value="2">February</option>
                                <option value="3">March</option>
                                <option value="4">April</option>
                                <option value="5">May</option>
                                <option value="6">June</option>
                                <option value="7">July</option>
                                <option value="8">August</option>
                                <option value="9">September</option>
                                <option value="10">October</option>
                                <option value="11">November</option>
                                <option value="12">December</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check"></i> Year *</label>
                            <input type="number" id="chartYear" name="year" required min="2020" max="2099">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-percentage"></i> Commission Basis *</label>
                            <select id="chartBasis" name="commission_basis" class="searchable" required>
                                <option value="">-- Select --</option>
                                <option value="premium_pct">Premium %</option>
                                <option value="tp_pct">TP %</option>
                                <option value="od_pct">OD %</option>
                                <option value="net_pct">Net %</option>
                                <option value="fixed">Fixed</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-coins"></i> Commission Rate *</label>
                            <input type="number" id="chartRate" name="commission_rate" required step="0.0001" min="0.0001" placeholder="e.g. 12.5000">
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
    <?php endif; ?>

    <script>
        /** Developed by Rudees Digital */
        const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        const PRE_BUYER_ID = <?php echo $preBuyerId; ?>;
        const MONTH_NAMES = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const BASIS_LABELS = {
            'premium_pct': 'Premium %',
            'tp_pct': 'TP %',
            'od_pct': 'OD %',
            'net_pct': 'Net %',
            'fixed': 'Fixed'
        };

        let chartsTable = null;
        let chartsData = [];
        let buyersList = [];
        let isEditMode = false;

        $(document).ready(function() {
            initYearDropdowns();
            loadBuyers(function() { loadCharts(); });
        });

        // populate year selects
        function initYearDropdowns() {
            const now = new Date();
            const curMonth = now.getMonth() + 1;
            const curYear = now.getFullYear();
            const years = [curYear - 1, curYear, curYear + 1];

            ['selYear', 'bulkYear'].forEach(function(id) {
                const sel = document.getElementById(id);
                if (!sel) return;
                sel.innerHTML = '';
                years.forEach(function(y) {
                    const opt = document.createElement('option');
                    opt.value = y;
                    opt.textContent = y;
                    if (y === curYear) opt.selected = true;
                    sel.appendChild(opt);
                });
            });

            // default month
            $('#selMonth').val(curMonth);
            if (document.getElementById('bulkMonth')) $('#bulkMonth').val(curMonth);
            // show picked period, not the placeholder
            ['selMonth', 'selYear', 'bulkMonth', 'bulkYear'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) { initSearchableDropdown(el); el.sdRefresh(); }
            });
        }

        function loadBuyers(cb) {
            $.ajax({
                url: '?action=getBuyers',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        buyersList = res.data;
                        populateBuyerDropdowns();
                    }
                    if (cb) cb();
                },
                error: function() { if (cb) cb(); }
            });
        }

        function populateBuyerDropdowns() {
            // filter dropdown
            const filterSel = $('#filterBuyer');
            filterSel.find('option:not(:first)').remove();
            buyersList.forEach(function(b) {
                filterSel.append('<option value="' + escHtml(b.company_name) + '">' + escHtml(b.company_name) + ' (' + escHtml(b.company_code) + ')</option>');
            });

            // modal dropdown
            const modalSel = $('#chartBuyer');
            if (modalSel.length) {
                modalSel.find('option:not(:first)').remove();
                buyersList.forEach(function(b) {
                    modalSel.append('<option value="' + b.id + '">' + escHtml(b.company_name) + ' (' + escHtml(b.company_code) + ')</option>');
                });
            }
            setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
        }

        function loadCharts() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();

            const m = $('#selMonth').val();
            const y = $('#selYear').val();

            $.ajax({
                url: '?action=getCharts&month=' + m + '&year=' + y,
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        chartsData = res.data;
                        $('#loadingSkeleton').hide();
                        $('#tableContainer').show();

                        // empty-state CTA when no charts
                        if (!res.data.length) {
                            rsTbData(chartsTable, []); // keep the table, just empty it
                            $('#emptyState').html(emptyStateHtml({
                                icon: 'fa-chart-bar',
                                title: 'No payout charts found',
                                text: 'No buyer payout charts match the selected period and filters.',
                                btnText: IS_ADMIN ? 'Add Entry' : '',
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

                        setTimeout(function() {
                            initDataTable(res.data);
                            // honor deep-link buyer filter from buyers.php
                            if (PRE_BUYER_ID > 0 && !window._preBuyerApplied) {
                                window._preBuyerApplied = true;
                                var b = buyersList.find(function(x) { return x.id === PRE_BUYER_ID; });
                                if (b) {
                                    $('#filterBuyer').val(b.company_name);
                                    if ($('#filterBuyer')[0] && $('#filterBuyer')[0].sdRefresh) $('#filterBuyer')[0].sdRefresh();
                                    setTimeout(applyFilters, 150);
                                }
                            }
                        }, 100);
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

        function initDataTable(data) {
            data.forEach(function(r) { r._period = (+r.year) * 100 + (+r.month); }); // month/year sort key
            if (chartsTable) return rsTbData(chartsTable, data); // build once, swap rows

            let cols = [
                { data: 'id', title: 'ID' },
                {
                    data: 'company_name',
                    title: 'Company',
                    render: function(d, type, row) {
                        if (type !== 'display') return (row.company_name || '') + ' (' + (row.company_code || '') + ')'; // export/search text
                        return escHtml(row.company_name) + '<br><small style="color:#888;">' + escHtml(row.company_code) + '</small>';
                    }
                },
                { data: 'vehicle_type', title: 'Vehicle Type', render: function(d) { return escHtml(d); } },
                {
                    data: '_period',
                    title: 'Month/Year',
                    render: function(d, type, row) {
                        return MONTH_NAMES[row.month] + ' ' + row.year;
                    }
                },
                {
                    data: 'commission_basis',
                    title: 'Basis',
                    render: function(d) {
                        return BASIS_LABELS[d] || d;
                    }
                },
                {
                    data: 'commission_rate',
                    title: 'Rate',
                    render: function(d) {
                        return parseFloat(d).toFixed(4);
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
                    title: 'Via',
                    render: function(d) {
                        if (d === 'bulk_upload') return '<span class="status-badge status-active">Bulk</span>';
                        return '<span class="status-badge status-inactive">Manual</span>';
                    }
                }
            ];

            // action hub for admin - edit/delete in ⋮ More (no detail view)
            if (IS_ADMIN) {
                cols.push({
                    data: null,
                    title: 'Actions',
                    orderable: false,
                    render: function(d, type, row) {
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
                columns: cols, data: data, title: 'Buyer Payout Charts', file: 'buyer_payout_charts', sort: ['id', 'desc'],
                // row color: inactive entries muted
                rowClass: function(r) { return r.is_active ? '' : 'dt-row-muted'; },
                info: function() {
                    return [['Period', MONTH_NAMES[parseInt($('#selMonth').val(), 10)] + ' ' + $('#selYear').val()],
                        ['Buyer', $('#filterBuyer').val() || 'All'], ['Vehicle', $('#filterVehicle').val() || 'All']];
                }
            });
            rsTbSearch(chartsTable, $('#chartsSearch').val()); // keep head search

            // single delegated handler for the action hub
            $('#chartsTable').off('click', '[data-act]').on('click', '[data-act]', function() {
                var act = $(this).data('act'), id = $(this).data('id');
                if (act === 'edit') editChart(id);
                else if (act === 'delete') deleteChart(id);
            });

            // bind filter events
            $('#filterBuyer, #filterVehicle').off('change').on('change', applyFilters);
            applyFilters();
        }

        function applyFilters() {
            if (!chartsTable) return;
            const buyer = $('#filterBuyer').val(), vehicle = $('#filterVehicle').val();
            rsTbWhere(chartsTable, 'buyer', buyer ? function(r) { return r.company_name === buyer; } : null);
            rsTbWhere(chartsTable, 'vehicle', vehicle ? function(r) { return r.vehicle_type === vehicle; } : null);
        }

        function clearFilters() {
            $('#filterBuyer').val('');
            $('#filterVehicle').val('');
            $('#chartsSearch').val('');
            ['filterBuyer', 'filterVehicle'].forEach(function(id) { var el = document.getElementById(id); if (el && el.sdSetVal) el.sdSetVal(''); });
            rsTbClear(chartsTable);
        }

        // header search -> table; period pick reloads
        $('#chartsSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(chartsTable, v); },200); });
        $('#selMonth, #selYear').on('change', function(){ loadCharts(); });

        function escHtml(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        <?php if ($isAdmin): ?>
        function openAddModal() {
            isEditMode = false;
            $('#modalTitle').html('<i class="fas fa-chart-bar"></i> Add Payout Entry');
            $('#chartForm')[0].reset();
            $('#chartId').val('');
            $('#chartIsActive').prop('checked', true);
            $('#statusToggleGroup').hide();

            // default month/year from selector
            $('#chartMonth').val($('#selMonth').val());
            $('#chartYear').val($('#selYear').val());

            $('#chartModal').addClass('active');
        }

        function editChart(cid) {
            const row = chartsData.find(function(c) { return c.id === cid; });
            if (!row) return;
            isEditMode = true;
            $('#modalTitle').html('<i class="fas fa-edit"></i> Edit Payout Entry');
            $('#chartId').val(row.id);
            $('#chartBuyer').val(row.buyer_id);
            $('#chartVehicle').val(row.vehicle_type);
            $('#chartMonth').val(row.month);
            $('#chartYear').val(row.year);
            $('#chartBasis').val(row.commission_basis);
            $('#chartRate').val(row.commission_rate);
            $('#chartIsActive').prop('checked', !!row.is_active);
            $('#statusToggleGroup').show();
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
            $('#chartModal').addClass('active');
        }

        function closeModal() {
            $('#chartModal').removeClass('active');
            $('#chartForm')[0].reset();
        }

        // close on overlay click
        document.getElementById('chartModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // form submit
        $('#chartForm').on('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const action = isEditMode ? 'updateChart' : 'addChart';

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
                title: 'Delete Entry?',
                text: 'This action cannot be undone',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) return;

                const fd = new FormData();
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

        // bulk upload handler
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
            // cols are positional on the server: company_code, vehicle_type, commission_basis, commission_rate
            var ws = XLSX.utils.aoa_to_sheet([
                ['company_code', 'vehicle_type', 'commission_basis', 'commission_rate'],
                ['BUY-001', 'Car', 'premium_pct', 12],
                ['BUY-001', 'Two Wheeler', 'od_pct', 18]
            ]);
            ws['!cols'] = [{ wch: 14 }, { wch: 18 }, { wch: 18 }, { wch: 16 }];
            XLSX.utils.book_append_sheet(wb, ws, 'Buyer Payout');
            XLSX.writeFile(wb, 'buyer_payout_chart_template.xlsx');
        }

        function bulkUpload() {
            const fileInput = document.getElementById('csvFile');
            if (!fileInput.files.length) {
                Swal.fire({ icon: 'warning', title: 'No File', text: 'Select an Excel or CSV file first' });
                return;
            }
            sheetToCsvFile(fileInput.files[0], postBulkChart);
        }

        function postBulkChart(csvFile) {
            const fd = new FormData();
            fd.append('csv_file', csvFile); // excel rows arrive as csv
            fd.append('bulk_month', $('#bulkMonth').val());
            fd.append('bulk_year', $('#bulkYear').val());
            fd.append('csrf_token', window.CSRF_TOKEN);

            showLoading('Uploading...');

            $.ajax({
                url: '?action=bulkUpload',
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    hideLoading();
                    const resultsDiv = $('#bulkResults');
                    resultsDiv.show();

                    if (res.success) {
                        let html = '<div class="status-badge status-active" style="display:inline-block; margin-bottom:8px;">Upload Complete</div>';
                        html += '<p><strong>Processed:</strong> ' + res.processed + ' | <strong>Failed:</strong> ' + res.failed + '</p>';

                        if (res.errors && res.errors.length > 0) {
                            html += '<details><summary style="cursor:pointer; color:#ea4335;">View Errors (' + res.errors.length + ')</summary><ul style="margin-top:6px;">';
                            res.errors.forEach(function(err) {
                                html += '<li style="color:#ea4335; font-size:13px;">' + escHtml(err) + '</li>';
                            });
                            html += '</ul></details>';
                        }

                        resultsDiv.html(html);
                        fileInput.value = '';

                        // sync selectors and reload
                        $('#selMonth').val($('#bulkMonth').val());
                        $('#selYear').val($('#bulkYear').val());
                        setTimeout(loadCharts, 300);
                    } else {
                        resultsDiv.html('<span style="color:#ea4335;">' + escHtml(res.message) + '</span>');
                    }
                },
                error: function(xhr, status, err) {
                    hideLoading();
                    Swal.fire({ icon: 'error', title: 'Upload Error', text: 'Connection error: ' + err });
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
                title: 'Buyer Payout Chart', number: 'BPC-' + year + String(month).padStart(2, '0'),
                info: [['Period', period]].concat(base.info.map(function(i) { return i[0] === 'Filters' ? [i[0], i[1].split(' · ').filter(function(f) { return f.indexOf('Period:') !== 0; }).join(' · ') || 'None'] : i; })),
                cols: [
                    { title: '#', field: '#', align: 'center' },
                    { title: 'Buyer', get: function(r) { return r.company_name || ''; }, bold: true, sub: function(r) { return r.company_code || ''; } },
                    { title: 'Vehicle type', field: 'vehicle_type' },
                    { title: 'Basis', get: function(r) { return BASIS_LABELS[r.commission_basis] || r.commission_basis; } },
                    { title: 'Rate', get: function(r) { return parseFloat(r.commission_rate).toFixed(4); }, align: 'right' },
                    { title: 'Status', get: function(r) { return r.is_active ? 'Active' : 'Inactive'; } }
                ],
                rows: rows
            }, 'buyer_payout_chart_' + year + '_' + String(month).padStart(2, '0'));
        }
    </script>
</body>
</html>
