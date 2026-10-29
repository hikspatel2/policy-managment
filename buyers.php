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
$current_page = 'buyers';

// agent cant access
if ($role === 'agent') { header("Location: dashboard.php"); exit(); }

// pan/tds/terms/state -> [vals] or error msg
function buyerExtras(array $src) {
    $pan = strtoupper(trim($src['pan_number'] ?? ''));
    $tds = trim((string)($src['tds_rate'] ?? ''));
    $terms = trim((string)($src['payment_terms_days'] ?? ''));
    $state = trim($src['state'] ?? '');
    if ($pan !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) return 'Invalid PAN (e.g. ABCDE1234F)';
    if ($tds === '') $tds = '2';
    if (!is_numeric($tds) || $tds < 0 || $tds > 30) return 'TDS rate must be 0-30 %';
    if ($terms !== '' && (!ctype_digit($terms) || (int)$terms > 365)) return 'Payment terms must be 0-365 days';
    if (strlen($state) > 100) return 'State max 100 characters';
    return [$pan ?: null, round((float)$tds, 2), $terms === '' ? null : (int)$terms, $state ?: null];
}

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getBuyers':
                $stmt = $conn->prepare("SELECT id, company_name, company_code, contact_person, mobile, email, address, state, gst_number, pan_number, tds_rate, payment_terms_days, wallet_balance, outstanding_balance, is_active, DATE_FORMAT(created_at, '%b %d, %Y') as created_at FROM buyers ORDER BY id DESC");
                $stmt->execute();
                $res = $stmt->get_result();
                $buyers = [];
                while ($row = $res->fetch_assoc()) {
                    $buyers[] = [
                        'id' => (int)$row['id'],
                        'company_name' => $row['company_name'],
                        'company_code' => $row['company_code'],
                        'contact_person' => $row['contact_person'],
                        'mobile' => $row['mobile'],
                        'email' => $row['email'],
                        'address' => $row['address'],
                        'gst_number' => $row['gst_number'],
                        'state' => $row['state'],
                        'pan_number' => $row['pan_number'],
                        'tds_rate' => (float)$row['tds_rate'],
                        'payment_terms_days' => $row['payment_terms_days'] === null ? null : (int)$row['payment_terms_days'],
                        'wallet_balance' => (float)$row['wallet_balance'],
                        'outstanding_balance' => (float)$row['outstanding_balance'],
                        'is_active' => (int)$row['is_active'],
                        'created_at' => $row['created_at']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $buyers]);
                exit();

            case 'getBuyerLedger':
                $bid = (int)($_GET['buyer_id'] ?? 0);
                if ($bid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid buyer']); exit(); }
                $stmt = $conn->prepare("SELECT id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, reference_number FROM buyer_transactions WHERE buyer_id = ? ORDER BY transaction_date ASC, id ASC");
                $stmt->bind_param("i", $bid);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'id' => (int)$r['id'],
                        'transaction_date' => $r['transaction_date'],
                        'description' => $r['description'],
                        'transaction_type' => $r['transaction_type'],
                        'credit_amount' => (float)$r['credit_amount'],
                        'debit_amount' => (float)$r['debit_amount'],
                        'running_balance' => (float)$r['running_balance'],
                        'reference_number' => $r['reference_number']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();

            case 'getBuyerPolicies':
                $bid = (int)($_GET['buyer_id'] ?? 0);
                if ($bid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid buyer']); exit(); }
                $stmt = $conn->prepare("SELECT id, entry_date, policy_number, customer_name, vehicle_number, agent_name, total_premium_amount, buyer_commission_amount, status FROM policy_entries WHERE buyer_id = ? ORDER BY entry_date DESC, id DESC");
                $stmt->bind_param("i", $bid);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'id' => (int)$r['id'],
                        'entry_date' => $r['entry_date'],
                        'policy_number' => $r['policy_number'],
                        'customer_name' => $r['customer_name'],
                        'vehicle_number' => $r['vehicle_number'],
                        'agent_name' => $r['agent_name'],
                        'total_premium_amount' => (float)$r['total_premium_amount'],
                        'buyer_commission_amount' => (float)$r['buyer_commission_amount'],
                        'status' => $r['status']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();

            case 'getBuyerCharts':
                $bid = (int)($_GET['buyer_id'] ?? 0);
                if ($bid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid buyer']); exit(); }
                $stmt = $conn->prepare("SELECT id, vehicle_type, month, year, commission_basis, commission_rate, is_active FROM buyer_payout_charts WHERE buyer_id = ? ORDER BY year DESC, month DESC, id DESC");
                $stmt->bind_param("i", $bid);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'id' => (int)$r['id'],
                        'vehicle_type' => $r['vehicle_type'],
                        'month' => (int)$r['month'],
                        'year' => (int)$r['year'],
                        'commission_basis' => $r['commission_basis'],
                        'commission_rate' => (float)$r['commission_rate'],
                        'is_active' => (int)$r['is_active']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();

            case 'addBuyer':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $companyName = trim($_POST['company_name'] ?? '');
                $companyCode = trim($_POST['company_code'] ?? '');
                $contactPerson = trim($_POST['contact_person'] ?? '');
                $mobile = trim($_POST['mobile'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $gstNumber = trim($_POST['gst_number'] ?? '');

                // validate required
                if (strlen($companyName) < 2 || strlen($companyName) > 200) {
                    echo json_encode(['success' => false, 'message' => 'Company name must be 2-200 characters']);
                    exit();
                }
                if (empty($companyCode)) {
                    echo json_encode(['success' => false, 'message' => 'Company code is required']);
                    exit();
                }

                $ex = buyerExtras($_POST);
                if (is_string($ex)) { echo json_encode(['success' => false, 'message' => $ex]); exit(); }
                [$panNo, $tdsRate, $terms, $state] = $ex;

                // unique company_code check
                $stmt = $conn->prepare("SELECT id FROM buyers WHERE company_code = ?");
                $stmt->bind_param("s", $companyCode);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Company code already exists']);
                    exit();
                }
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO buyers (company_name, company_code, contact_person, mobile, email, address, state, gst_number, pan_number, tds_rate, payment_terms_days) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssssssdi", $companyName, $companyCode, $contactPerson, $mobile, $email, $address, $state, $gstNumber, $panNo, $tdsRate, $terms);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add buyer']);
                    exit();
                }
                $insertId = $stmt->insert_id;
                $stmt->close();

                logActivity($user_id, $username, 'Buyer Created', "Created buyer: $companyName ($companyCode), TDS $tdsRate%" . ($panNo ? ", PAN $panNo" : '') . ($terms !== null ? ", terms $terms days" : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Buyer added successfully', 'data' => ['id' => $insertId]]);
                exit();

            case 'bulkAddBuyers':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows) || count($rows) === 0) { echo json_encode(['success'=>false,'message'=>'No buyers to add']); exit(); }

                $obDate = trim($_POST['opening_date'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $obDate)) $obDate = date('Y-m-d');
                $off = max(0, (int)($_POST['row_offset'] ?? 0)); // client posts in chunks - keep row numbers global

                $inserted = 0; $errors = [];
                $seenCode = []; // within-batch dedup
                foreach ($rows as $idx => $r) {
                    $line = $off + $idx + 1;
                    $code = trim($r['company_code'] ?? '');
                    $cname = trim($r['company_name'] ?? '');
                    $contact = trim($r['contact_person'] ?? '');
                    $mobile = trim($r['mobile'] ?? '');
                    $email = trim($r['email'] ?? '');
                    $gst = trim($r['gst_number'] ?? '');
                    $addr = trim($r['address'] ?? '');
                    $opening = (float)($r['opening_balance'] ?? 0);

                    if ($code === '' && $cname === '') continue; // trailing blank line

                    if (strlen($cname) < 2 || strlen($cname) > 200) { $errors[] = "Row $line: company name must be 2-200 characters"; continue; }
                    if ($code === '') { $errors[] = "Row $line: company code is required"; continue; }
                    if (strlen($code) > 30) { $errors[] = "Row $line: company code must be 30 characters or less"; continue; }
                    if ($mobile !== '' && !preg_match('/^\d{10,15}$/', $mobile)) { $errors[] = "Row $line: mobile must be 10-15 digits"; continue; }
                    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "Row $line: invalid email '$email'"; continue; }

                    $ex = buyerExtras($r);
                    if (is_string($ex)) { $errors[] = "Row $line: $ex"; continue; }
                    [$panNo, $tdsRate, $terms, $state] = $ex;

                    $ck = strtolower($code);
                    if (isset($seenCode[$ck])) { $errors[] = "Row $line: duplicate company code '$code' in list"; continue; }

                    $stmt = $conn->prepare("SELECT id FROM buyers WHERE company_code = ?");
                    $stmt->bind_param("s", $code); $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) { $stmt->close(); $errors[] = "Row $line: company code '$code' already exists"; continue; }
                    $stmt->close();

                    $conn->begin_transaction();
                    try {
                        $stmt = $conn->prepare("INSERT INTO buyers (company_name, company_code, contact_person, mobile, email, address, state, gst_number, pan_number, tds_rate, payment_terms_days) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("sssssssssdi", $cname, $code, $contact, $mobile, $email, $addr, $state, $gst, $panNo, $tdsRate, $terms);
                        $stmt->execute(); $newBuyerId = (int)$stmt->insert_id; $stmt->close();

                        // opening balance -> adjustment txn (+ = buyer owes us = credit, - = we owe buyer = debit); recalc fixes running_balance + wallet
                        if (abs($opening) > 0.001) {
                            $cr = $opening > 0 ? $opening : 0.00;
                            $dr = $opening < 0 ? -$opening : 0.00;
                            $desc = 'Opening Balance'; $tt = 'adjustment';
                            $s = $conn->prepare("INSERT INTO buyer_transactions (buyer_id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, created_by) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
                            $s->bind_param("isssddi", $newBuyerId, $obDate, $desc, $tt, $cr, $dr, $user_id);
                            $s->execute(); $s->close();
                            recalcBuyerBalances($conn, $newBuyerId);
                        }

                        $conn->commit();
                        $seenCode[$ck] = 1;
                        $inserted++;
                    } catch (Exception $ex) {
                        $conn->rollback();
                        $errors[] = "Row $line: failed to add '$code'";
                    }
                }

                logActivity($user_id, $username, 'Bulk Buyer Add', "Added $inserted buyers, " . count($errors) . " errors");
                $conn->close();
                echo json_encode(['success'=>true, 'message'=>"$inserted buyer(s) added.", 'inserted'=>$inserted, 'errors'=>$errors]);
                exit();

            case 'updateBuyer':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $bid = (int)($_POST['id'] ?? 0);
                $companyName = trim($_POST['company_name'] ?? '');
                $companyCode = trim($_POST['company_code'] ?? '');
                $contactPerson = trim($_POST['contact_person'] ?? '');
                $mobile = trim($_POST['mobile'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $gstNumber = trim($_POST['gst_number'] ?? '');
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                if ($bid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid buyer ID']);
                    exit();
                }

                // validate required
                if (strlen($companyName) < 2 || strlen($companyName) > 200) {
                    echo json_encode(['success' => false, 'message' => 'Company name must be 2-200 characters']);
                    exit();
                }
                if (empty($companyCode)) {
                    echo json_encode(['success' => false, 'message' => 'Company code is required']);
                    exit();
                }

                $ex = buyerExtras($_POST);
                if (is_string($ex)) { echo json_encode(['success' => false, 'message' => $ex]); exit(); }
                [$panNo, $tdsRate, $terms, $state] = $ex;

                // unique company_code excluding self
                $stmt = $conn->prepare("SELECT id FROM buyers WHERE company_code = ? AND id != ?");
                $stmt->bind_param("si", $companyCode, $bid);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Company code already exists']);
                    exit();
                }
                $stmt->close();

                $stmt = $conn->prepare("UPDATE buyers SET company_name=?, company_code=?, contact_person=?, mobile=?, email=?, address=?, state=?, gst_number=?, pan_number=?, tds_rate=?, payment_terms_days=?, is_active=? WHERE id=?");
                $stmt->bind_param("sssssssssdiii", $companyName, $companyCode, $contactPerson, $mobile, $email, $address, $state, $gstNumber, $panNo, $tdsRate, $terms, $isActive, $bid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update buyer']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Buyer Updated', "Updated buyer #$bid: $companyName ($companyCode), TDS $tdsRate%" . ($panNo ? ", PAN $panNo" : '') . ($terms !== null ? ", terms $terms days" : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Buyer updated successfully']);
                exit();

            case 'bulkDelete':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
                if (empty($ids)) {
                    echo json_encode(['success' => false, 'message' => 'No records selected']);
                    exit();
                }

                $deleted = 0; $skipped = 0;
                foreach ($ids as $bid) {
                    // skip if linked policies (same check as single delete)
                    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM policy_entries WHERE buyer_id = ?");
                    $stmt->bind_param("i", $bid);
                    $stmt->execute();
                    $linked = $stmt->get_result()->fetch_assoc()['cnt'];
                    $stmt->close();
                    if ($linked > 0) { $skipped++; continue; }

                    $stmt = $conn->prepare("DELETE FROM buyers WHERE id = ?");
                    $stmt->bind_param("i", $bid);
                    if ($stmt->execute() && $stmt->affected_rows > 0) $deleted++;
                    $stmt->close();
                }

                logActivity($user_id, $username, 'Bulk delete', "Deleted $deleted buyer(s), skipped $skipped");
                $conn->close();
                $msg = "$deleted record(s) deleted";
                if ($skipped > 0) $msg .= " — $skipped skipped (linked policies)";
                echo json_encode(['success' => true, 'message' => $msg]);
                exit();

            case 'deleteBuyer':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $bid = (int)($_POST['id'] ?? 0);
                if ($bid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid buyer ID']);
                    exit();
                }

                // get name before delete
                $stmt = $conn->prepare("SELECT company_name FROM buyers WHERE id = ?");
                $stmt->bind_param("i", $bid);
                $stmt->execute();
                $delRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $delName = $delRow ? $delRow['company_name'] : 'Unknown';

                // check linked policies
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM policy_entries WHERE buyer_id = ?");
                $stmt->bind_param("i", $bid);
                $stmt->execute();
                $linked = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();
                if ($linked > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete buyer — it has $linked linked policy entries"]);
                    exit();
                }

                $stmt = $conn->prepare("DELETE FROM buyers WHERE id = ?");
                $stmt->bind_param("i", $bid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete buyer']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Buyer Deleted', "Deleted buyer: $delName (#$bid)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Buyer deleted successfully']);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("buyers.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
        exit();
    }
}

$isAdmin = ($role === 'admin');
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
    <title>Buyer Management - Insurance Management System</title>

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
            $pageIcon    = 'fa-building';
            $pageTitle   = 'Buyer Management';
            $crumbs      = [['label' => 'Buyers', 'icon' => 'fa-building']];
            $pageActions = [];
            if ($isAdmin) {
                $pageActions[] = ['label' => 'Add Buyer', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
                $pageActions[] = ['label' => 'Bulk Add / Import', 'icon' => 'fa-file-excel', 'onclick' => 'buyersImport.open()'];
            }
            $pageActions[] = ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(buyersTable,'csv')"];
            $pageActions[] = ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(buyersTable,'pdf')"];
            $pageActions[] = ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(buyersTable,'print')"];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadBuyers()'];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="buyersSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="buyersSearch" placeholder="Search buyers…" autocomplete="off"></div>
                <div class="hf-field"><label for="filterStatus" title="Status"><i class="fas fa-toggle-on"></i></label><select id="filterStatus" class="filter-input searchable">
                    <option value="">All Status</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select></div>
                <button type="button" class="btn btn-icon" onclick="clearFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>

            <div class="data-section">
                <!-- loading skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <?php for ($i = 0; $i < 9; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.6"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.9"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.3"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.9"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="tableContainer" style="display: none;">
                    <div id="buyersTable"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Detail Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="viewModalTitle"><i class="fas fa-eye"></i> View Details</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- Buyer Modal -->
    <div class="modal-overlay" id="buyerModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-building"></i> Add Buyer</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="buyerForm">
                    <input type="hidden" id="buyerId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Company Name *</label>
                            <input type="text" id="companyName" name="company_name" required minlength="2" maxlength="200" placeholder="Company name">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-barcode"></i> Company Code *</label>
                            <input type="text" id="companyCode" name="company_code" required maxlength="30" placeholder="Unique code">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Contact Person</label>
                            <input type="text" id="contactPerson" name="contact_person" maxlength="150" placeholder="Contact person name">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Mobile</label>
                            <input type="tel" id="buyerMobile" name="mobile" maxlength="15" placeholder="Mobile number">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" id="buyerEmail" name="email" maxlength="200" placeholder="email@example.com">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-id-card"></i> GST Number</label>
                            <input type="text" id="gstNumber" name="gst_number" maxlength="15" placeholder="GST number">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-id-badge"></i> PAN Number</label>
                            <input type="text" id="buyerPan" name="pan_number" maxlength="10" pattern="[A-Za-z]{5}[0-9]{4}[A-Za-z]" placeholder="ABCDE1234F" class="text-uppercase">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> TDS Rate (%)</label>
                            <input type="number" id="buyerTds" name="tds_rate" step="0.01" min="0" max="30" value="2.00" placeholder="2.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hourglass-half"></i> Payment Terms (days)</label>
                            <input type="number" id="buyerTerms" name="payment_terms_days" step="1" min="0" max="365" placeholder="e.g. 30">
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt"></i> Address</label>
                        <textarea id="buyerAddress" name="address" rows="3" placeholder="Full address"></textarea>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-map"></i> State</label>
                        <input type="text" id="buyerState" name="state" maxlength="100" placeholder="State">
                    </div>

                    <div class="form-group" id="statusToggleGroup" style="display: none;">
                        <div class="toggle-switch">
                            <input type="checkbox" class="toggle" id="buyerIsActive" name="is_active" checked>
                            <label for="buyerIsActive">Active</label>
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
        let buyersTable = null;
        let buyersData = [];
        let isEditMode = false;
        let bulkSel = null;

        $(document).ready(function() {
            loadBuyers();
        });

        // signed-color wallet helper
        function formatWallet(val) {
            var n = parseFloat(val) || 0;
            var cls = n > 0 ? 'wallet-positive' : n < 0 ? 'wallet-negative' : 'wallet-zero';
            return '<span class="wallet-amount ' + cls + '">' + n.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</span>';
        }

        // outstanding helper - red when owed (>0)
        function formatOutstanding(val) {
            var n = parseFloat(val) || 0;
            var cls = n > 0 ? 'wallet-negative' : 'wallet-zero';
            return '<span class="wallet-amount ' + cls + '">' + n.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '</span>';
        }

        function inrAmt(v) {
            return '₹' + (parseFloat(v) || 0).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        function tabLoading(msg) {
            return '<div class="empty-state"><i class="fas fa-spinner fa-spin empty-state-icon"></i>' +
                '<div class="empty-state-text">' + escapeHtml(msg) + '</div></div>';
        }

        function loadBuyers() {
            $.ajax({
                url: '?action=getBuyers',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        buyersData = res.data;
                        $('#loadingSkeleton').hide();
                        $('#tableContainer').show();
                        initDataTable(res.data);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load buyers' });
                    }
                },
                error: function(xhr, status, err) {
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
                }
            });
        }

        function initDataTable(data) {
            if (buyersTable) return rsTbData(buyersTable, data); // build once, swap rows

            let cols = [];
            // bulk checkbox col - admin only
            if (IS_ADMIN) {
                cols.push({ data:'id', title:'<input type="checkbox" class="bulk-check-all">', orderable:false, searchable:false, className:'bulk-col', render:function(d){ return '<input type="checkbox" class="bulk-check" data-id="'+d+'">'; } });
            }
            cols = cols.concat([
                { data: 'id', title: 'ID' },
                { data: 'company_name', title: 'Company Name' },
                {
                    data: 'company_code',
                    title: 'Code',
                    render: function(d) {
                        return '<span class="badge-code">' + escapeHtml(d) + '</span>';
                    }
                },
                { data: 'contact_person', title: 'Contact Person', render: function(d) { return escapeHtml(d) || '-'; } },
                { data: 'mobile', title: 'Mobile', render: function(d) { return escapeHtml(d) || '-'; } },
                { data: 'state', title: 'State', visible: false },
                { data: 'pan_number', title: 'PAN', visible: false },
                { data: 'gst_number', title: 'GST No', visible: false },
                { data: 'tds_rate', title: 'TDS %', visible: false },
                { data: 'payment_terms_days', title: 'Payment Terms (days)', visible: false },
                {
                    data: 'wallet_balance',
                    title: 'Wallet',
                    render: function(d) { return formatWallet(d); }
                },
                {
                    data: 'outstanding_balance',
                    title: 'Outstanding',
                    render: function(d) { return formatOutstanding(d); }
                },
                {
                    data: 'is_active',
                    title: 'Status',
                    render: function(d) {
                        return d
                            ? '<span class="status-badge status-active">Active</span>'
                            : '<span class="status-badge status-inactive">Inactive</span>';
                    }
                }
            ]);

            // action hub col - last
            cols.push({
                data: null,
                title: 'Actions',
                orderable: false,
                render: function(d, type, row) {
                    var html = '<div class="action-hub">';
                    html += '<button class="action-icon view-icon" data-act="view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button>';
                    html += '<button class="action-icon" data-act="ledger" data-id="' + row.id + '" title="Ledger"><i class="fas fa-book"></i></button>';
                    if (IS_ADMIN) {
                        html += '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button>' +
                                '<div class="more-dropdown">' +
                                '<button class="more-item" data-act="charts" data-id="' + row.id + '"><i class="fas fa-chart-line"></i> Payout Charts</button>' +
                                '<div class="more-divider"></div>' +
                                '<button class="more-item" data-act="edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> Edit</button>' +
                                '<div class="more-divider"></div>' +
                                '<button class="more-item danger" data-act="delete" data-id="' + row.id + '"><i class="fas fa-trash"></i> Delete</button>' +
                                '</div></div>';
                    } else {
                        html += '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button>' +
                                '<div class="more-dropdown">' +
                                '<button class="more-item" data-act="charts" data-id="' + row.id + '"><i class="fas fa-chart-line"></i> Payout Charts</button>' +
                                '</div></div>';
                    }
                    html += '</div>';
                    return html;
                }
            });

            buyersTable = rsTable('#buyersTable', {
                columns: cols, data: data, title: 'Buyers', file: 'buyers', sort: ['id', 'desc'],
                // row color: inactive muted, outstanding>0 warn
                rowClass: function(r) { return !r.is_active ? 'dt-row-muted' : (parseFloat(r.outstanding_balance) > 0 ? 'dt-row-warning' : ''); },
                info: function() { return [['Status', $('#filterStatus').val() || 'All']]; }
            });
            rsTbSearch(buyersTable, $('#buyersSearch').val()); // keep head search

            // bulk row-select - admin only
            if (IS_ADMIN) {
                bulkSel = initBulkSelect({
                    table: buyersTable,
                    label: 'buyers',
                    onDelete: function(ids) {
                        showLoading('Deleting...');
                        $.ajax({
                            url: '?action=bulkDelete',
                            method: 'POST',
                            dataType: 'json',
                            data: { csrf_token: window.CSRF_TOKEN, ids: ids },
                            success: function(res) {
                                hideLoading();
                                if (res.success) {
                                    bulkSel.clear();
                                    loadBuyers();
                                    Swal.fire('Deleted', res.message, 'success');
                                } else {
                                    Swal.fire('Error', res.message, 'error');
                                }
                            },
                            error: function() {
                                hideLoading();
                                Swal.fire('Error', 'Request failed', 'error');
                            }
                        });
                    }
                });
            }

            // bind filter
            $('#filterStatus').off('change').on('change', applyFilters);

            // one delegated handler for the action hub
            $('#buyersTable').off('click', '[data-act]').on('click', '[data-act]', function() {
                var act = $(this).data('act'), id = $(this).data('id');
                var rec = buyersData.find(function(b) { return b.id == id; });
                if (act === 'view') { if (rec) viewRecord(rec); }
                else if (act === 'ledger') { window.location.href = 'buyer_transactions.php?buyer_id=' + id; }
                else if (act === 'charts') { window.location.href = 'buyer_payout_charts.php?buyer_id=' + id; }
                else if (act === 'edit') { if (rec) editBuyer(rec); }
                else if (act === 'delete') { deleteBuyer(id); }
            });
            applyFilters();
        }

        function applyFilters() {
            if (!buyersTable) return;
            const status = $('#filterStatus').val();
            rsTbWhere(buyersTable, 'status', status ? function(r) { return !!Number(r.is_active) === (status === 'Active'); } : null);
        }

        function clearFilters() {
            var fs = document.getElementById('filterStatus'); fs.sdSetVal ? fs.sdSetVal('') : (fs.value = '');
            $('#buyersSearch').val('');
            rsTbClear(buyersTable);
        }

        // header search -> table
        $('#buyersSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(buyersTable, v); },200); });

        // tabbed view modal \u2014 profile / ledger / linked policies / charts
        function viewRecord(d) {
            var statusCls = d.is_active ? 'status-active' : 'status-inactive';
            var wallet = '\u20B9' + parseFloat(d.wallet_balance || 0).toLocaleString('en-IN', {minimumFractionDigits: 2});
            var outstanding = '\u20B9' + parseFloat(d.outstanding_balance || 0).toLocaleString('en-IN', {minimumFractionDigits: 2});

            var profile = '<table class="view-detail-table">';
            profile += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">' + d.id + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-building"></i> Company Name</td><td class="vd-value">' + (escapeHtml(d.company_name) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-barcode"></i> Code</td><td class="vd-value"><span class="badge-code">' + (escapeHtml(d.company_code) || '-') + '</span></td>';
            profile += '<td class="vd-label"><i class="fas fa-user"></i> Contact Person</td><td class="vd-value">' + (escapeHtml(d.contact_person) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-phone"></i> Mobile</td><td class="vd-value">' + (escapeHtml(d.mobile) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-envelope"></i> Email</td><td class="vd-value">' + (escapeHtml(d.email) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-id-card"></i> GST Number</td><td class="vd-value">' + (escapeHtml(d.gst_number) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-toggle-on"></i> Status</td><td class="vd-value"><span class="status-badge ' + statusCls + '">' + (d.is_active ? 'Active' : 'Inactive') + '</span></td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-id-badge"></i> PAN</td><td class="vd-value">' + (escapeHtml(d.pan_number) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-percent"></i> TDS Rate</td><td class="vd-value">' + parseFloat(d.tds_rate || 0).toFixed(2) + '%</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-hourglass-half"></i> Payment Terms</td><td class="vd-value">' + (d.payment_terms_days != null ? d.payment_terms_days + ' days' : '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-map"></i> State</td><td class="vd-value">' + (escapeHtml(d.state) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-wallet"></i> Wallet</td><td class="vd-value">' + wallet + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-file-invoice-dollar"></i> Outstanding</td><td class="vd-value">' + outstanding + '</td></tr>';
            profile += '</table>';

            var html = '<div class="tab-nav">' +
                '<button class="tab-btn active" data-tab="profile"><i class="fas fa-building"></i> Profile</button>' +
                '<button class="tab-btn" data-tab="ledger"><i class="fas fa-book"></i> Ledger</button>' +
                '<button class="tab-btn" data-tab="policies"><i class="fas fa-file-contract"></i> Linked Policies</button>' +
                '<button class="tab-btn" data-tab="charts"><i class="fas fa-chart-line"></i> Payout Charts</button>' +
                '</div>' +
                '<div class="tab-content active" data-tab="profile">' + profile + '</div>' +
                '<div class="tab-content" data-tab="ledger" id="vmLedger">' + tabLoading('Loading ledger...') + '</div>' +
                '<div class="tab-content" data-tab="policies" id="vmPolicies">' + tabLoading('Loading policies...') + '</div>' +
                '<div class="tab-content" data-tab="charts" id="vmCharts">' + tabLoading('Loading payout charts...') + '</div>';

            document.getElementById('viewModalTitle').innerHTML = '<i class="fas fa-eye"></i> Buyer Details';
            document.getElementById('viewModalBody').innerHTML = html;
            initModalTabs('#viewModalBody');
            document.getElementById('viewModal').classList.add('active');

            loadBuyerLedger(d.id);
            loadBuyerPolicies(d.id);
            loadBuyerCharts(d.id);
        }

        // list table into a view tab pane (built when the tab shows)
        function paneTable(pane, o) {
            return rsTabTable($('<div class="tab-table"></div>').appendTo($(pane).empty())[0], o);
        }
        // money col — text on screen, number in CSV; dash = '-' for zero
        function amtCol(field, title, dash) {
            return { data: field, title: title, render: function(d, t) { return t === 'export' ? (+d || 0) : (dash && !(d > 0) ? '-' : inrAmt(d)); } };
        }
        // status pill — flag = 0/1 col, else policy status text
        function stCol(field, flag) {
            return { data: field, title: 'Status', render: function(d, t) {
                var ok = flag ? !!+d : d === 'active', txt = flag ? (ok ? 'Active' : 'Inactive') : String(d || '');
                return t === 'display' ? '<span class="status-badge status-' + (ok ? 'active' : 'inactive') + '">' + escapeHtml(txt) + '</span>' : txt; } };
        }

        // ledger tab — newest first
        function loadBuyerLedger(bid) {
            $.ajax({
                url: '?action=getBuyerLedger&buyer_id=' + bid, method: 'GET', dataType: 'json',
                success: function(res) {
                    paneTable('#vmLedger', {
                        data: res.success ? res.data.slice().reverse() : [], title: 'Buyer Ledger', file: 'buyer_ledger',
                        sort: ['transaction_date', 'desc'], empty: 'No transactions yet',
                        columns: [
                            { data: 'transaction_date', title: 'Date' },
                            { data: 'description', title: 'Description', defaultContent: '-' },
                            { data: 'transaction_type', title: 'Type' },
                            amtCol('credit_amount', 'Credit', true),
                            amtCol('debit_amount', 'Debit', true),
                            amtCol('running_balance', 'Balance')
                        ]
                    });
                },
                error: function() { $('#vmLedger').html(tabLoading('Failed to load ledger.')); }
            });
        }

        // linked policies tab
        function loadBuyerPolicies(bid) {
            $.ajax({
                url: '?action=getBuyerPolicies&buyer_id=' + bid, method: 'GET', dataType: 'json',
                success: function(res) {
                    paneTable('#vmPolicies', {
                        data: res.success ? res.data : [], title: 'Linked Policies', file: 'buyer_policies',
                        sort: ['entry_date', 'desc'], empty: 'No policy entries reference this buyer yet',
                        columns: [
                            { data: 'entry_date', title: 'Date' },
                            { data: 'policy_number', title: 'Policy #' },
                            { data: 'customer_name', title: 'Customer', defaultContent: '-' },
                            { data: 'vehicle_number', title: 'Vehicle', defaultContent: '-' },
                            { data: 'agent_name', title: 'Agent', render: function(d) { return d ? escapeHtml(d) : '-'; } },
                            amtCol('total_premium_amount', 'Premium'),
                            amtCol('buyer_commission_amount', 'Commission'),
                            stCol('status')
                        ]
                    });
                },
                error: function() { $('#vmPolicies').html(tabLoading('Failed to load policies.')); }
            });
        }

        // payout charts tab
        function loadBuyerCharts(bid) {
            var basisMap = { 'premium_pct':'Premium %', 'tp_pct':'TP %', 'od_pct':'OD %', 'net_pct':'Net %', 'fixed':'Fixed' };
            var mNames = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            $.ajax({
                url: '?action=getBuyerCharts&buyer_id=' + bid, method: 'GET', dataType: 'json',
                success: function(res) {
                    // ym = sortable month key
                    var rows = (res.success ? res.data : []).map(function(c) { return Object.assign({ ym: c.year * 100 + +c.month }, c); });
                    paneTable('#vmCharts', {
                        data: rows, title: 'Buyer Payout Charts', file: 'buyer_payout_charts',
                        sort: ['ym', 'desc'], empty: 'No payout rates configured for this buyer yet',
                        columns: [
                            { data: 'ym', title: 'Month/Year', render: function(d, t, c) { return escapeHtml(mNames[c.month] + ' ' + c.year); } },
                            { data: 'vehicle_type', title: 'Vehicle Type' },
                            { data: 'commission_basis', title: 'Basis', render: function(d) { return escapeHtml(basisMap[d] || d); } },
                            { data: 'commission_rate', title: 'Rate', render: function(d, t) { return t === 'export' ? (+d || 0) : (parseFloat(d) || 0).toFixed(4); } },
                            stCol('is_active', true)
                        ]
                    });
                },
                error: function() { $('#vmCharts').html(tabLoading('Failed to load payout charts.')); }
            });
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
        }

        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) closeViewModal();
        });

        <?php if ($isAdmin): ?>
        function openAddModal() {
            isEditMode = false;
            $('#modalTitle').html('<i class="fas fa-building"></i> Add Buyer');
            $('#buyerForm')[0].reset();
            $('#buyerId').val('');
            $('#buyerIsActive').prop('checked', true);
            $('#statusToggleGroup').hide();
            $('#buyerModal').addClass('active');
        }

        function editBuyer(buyer) {
            isEditMode = true;
            $('#modalTitle').html('<i class="fas fa-edit"></i> Edit Buyer');
            $('#buyerId').val(buyer.id);
            $('#companyName').val(buyer.company_name);
            $('#companyCode').val(buyer.company_code);
            $('#contactPerson').val(buyer.contact_person || '');
            $('#buyerMobile').val(buyer.mobile || '');
            $('#buyerEmail').val(buyer.email || '');
            $('#buyerAddress').val(buyer.address || '');
            $('#gstNumber').val(buyer.gst_number || '');
            $('#buyerPan').val(buyer.pan_number || '');
            $('#buyerTds').val(buyer.tds_rate != null ? parseFloat(buyer.tds_rate).toFixed(2) : '2.00');
            $('#buyerTerms').val(buyer.payment_terms_days != null ? buyer.payment_terms_days : '');
            $('#buyerState').val(buyer.state || '');
            $('#buyerIsActive').prop('checked', !!buyer.is_active);
            $('#statusToggleGroup').show();
            $('#buyerModal').addClass('active');
        }

        function closeModal() {
            $('#buyerModal').removeClass('active');
            $('#buyerForm')[0].reset();
        }

        // close on overlay click
        document.getElementById('buyerModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // form submit
        $('#buyerForm').on('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const action = isEditMode ? 'updateBuyer' : 'addBuyer';

            // checkbox handling
            if (!$('#buyerIsActive').is(':checked')) {
                formData.delete('is_active');
            }
            formData.append('csrf_token', window.CSRF_TOKEN);

            showLoading('Saving buyer...');

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
                        setTimeout(loadBuyers, 100);
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

        function deleteBuyer(bid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Buyer?',
                text: 'This action cannot be undone',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) return;

                const fd = new FormData();
                fd.append('id', bid);
                fd.append('csrf_token', window.CSRF_TOKEN);

                showLoading('Deleting buyer...');
                $.ajax({
                    url: '?action=deleteBuyer',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        hideLoading();
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                            setTimeout(loadBuyers, 100);
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
        }

        // bulk add / excel import — engine lives in sidebar.php
        var buyersImport = RSImport({
            key: 'buyers', title: 'Bulk Add / Import Buyers', url: '?action=bulkAddBuyers',
            hint: 'Add many buyers at once with their <strong>opening balance</strong> — upload an Excel/CSV file or type the rows. Only company name &amp; code are required. Opening balance sign: <strong>+</strong> = buyer owes us (outstanding), <strong>&minus;</strong> = we owe the buyer / advance already paid.',
            controls: '<div class="bulk-grid-actions"><div class="form-group bulk-date">' +
                '<label><i class="fas fa-calendar"></i> Opening balance as of</label>' +
                '<input type="date" class="rsi-obdate"></div></div>',
            onOpen: function($m) { var d = new Date(); $m.find('.rsi-obdate').val(d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2)); },
            extra: function() { return { opening_date: $('#rsImp_buyers .rsi-obdate').val() || '' }; },
            onDone: function() { setTimeout(loadBuyers, 100); },
            fields: [
                { key: 'company_code', label: 'Company Code *', cls: 'bg-code', req: true, max: 30, ph: 'BUY-001', aliases: ['companycode', 'code', 'buyercode', 'buyerid'], demo: 'BUY-001', demo2: 'BUY-002' },
                { key: 'company_name', label: 'Company Name *', cls: 'bg-cname', req: true, max: 200, ph: 'Company name', aliases: ['companyname', 'name', 'company', 'buyer', 'buyername', 'firm'], demo: 'Demo Insurance Co', demo2: 'Sample Brokers Ltd' },
                { key: 'contact_person', label: 'Contact Person', cls: 'bg-contact', max: 150, ph: 'optional', aliases: ['contactperson', 'contact', 'person', 'contactname'], demo: 'Demo Contact', demo2: '' },
                { key: 'mobile', label: 'Mobile', cls: 'bg-mobile', max: 15, digits: true, ph: '10-15 digits', aliases: ['mobile', 'mobileno', 'mobilenumber', 'phone', 'phoneno', 'contactno', 'whatsapp'], demo: '9876543210', demo2: '9876543211' },
                { key: 'email', label: 'Email', cls: 'bg-email', ph: 'optional', aliases: ['email', 'emailaddress', 'emailid', 'mail'], demo: 'demo@example.com', demo2: '' },
                { key: 'gst_number', label: 'GST No', cls: 'bg-gst', max: 15, ph: 'optional', aliases: ['gstnumber', 'gst', 'gstno', 'gstin'], demo: '27AAAAA0000A1Z5', demo2: '' },
                { key: 'address', label: 'Address', cls: 'bg-addr', ph: 'optional', aliases: ['address', 'addr', 'location', 'city'], demo: 'Demo Street, Mumbai', demo2: '' },
                { key: 'state', label: 'State', cls: 'bg-state', max: 100, ph: 'optional', aliases: ['state', 'province', 'region'], demo: 'Maharashtra', demo2: '' },
                { key: 'pan_number', label: 'PAN', cls: 'bg-pan', max: 10, ph: 'ABCDE1234F', aliases: ['pannumber', 'pan', 'panno'], demo: 'ABCDE1234F', demo2: '' },
                { key: 'tds_rate', label: 'TDS %', cls: 'bg-tds', max: 5, ph: 'blank = 2', aliases: ['tdsrate', 'tds', 'tdspercent'], demo: '2', demo2: '' },
                { key: 'payment_terms_days', label: 'Terms (days)', cls: 'bg-terms', max: 3, digits: true, ph: '0-365', aliases: ['paymenttermsdays', 'paymentterms', 'terms', 'creditdays', 'termsdays'], demo: '30', demo2: '' },
                { key: 'opening_balance', label: 'Opening Bal (₹)', cls: 'bg-open', type: 'number', aliases: ['openingbalance', 'openingbal', 'opening', 'obal', 'balance', 'openingamount'], demo: 25000, demo2: -5000 }
            ]
        });
        <?php endif; ?>
    </script>
</body>
</html>
