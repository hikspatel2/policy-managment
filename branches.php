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
$current_page = 'branches';

// agent cant access
if ($role === 'agent') { header("Location: dashboard.php"); exit(); }

// code/gst/city/pin -> [vals] or error msg; code unique (skip $exId)
function branchExtras(array $src, $conn, int $exId = 0) {
    $code = strtoupper(trim($src['branch_code'] ?? ''));
    $gst = strtoupper(trim($src['gst_number'] ?? ''));
    $city = trim($src['city'] ?? '');
    $pin = trim($src['pincode'] ?? '');
    if ($code !== '' && !preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) return 'Branch code must be 2-20 letters, digits, - or _';
    if ($gst !== '' && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gst)) return 'Invalid GSTIN (15 chars, e.g. 27ABCDE1234F1Z5)';
    if (strlen($city) > 100) return 'City max 100 characters';
    if ($pin !== '' && !preg_match('/^[1-9][0-9]{5}$/', $pin)) return 'Pincode must be 6 digits';
    if ($code !== '') {
        $st = $conn->prepare("SELECT id FROM branches WHERE branch_code = ? AND id <> ? LIMIT 1");
        $st->bind_param("si", $code, $exId); $st->execute();
        $dup = $st->get_result()->num_rows > 0; $st->close();
        if ($dup) return "Branch code '$code' already exists";
    }
    return [$code ?: null, $gst ?: null, $city ?: null, $pin ?: null];
}

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getBranches':
                $stmt = $conn->prepare("
                    SELECT b.id, b.name, b.branch_code, b.mobile, b.email, b.address, b.city, b.pincode, b.state, b.gst_number, b.wallet_balance, b.is_active,
                           DATE_FORMAT(b.created_at, '%b %d, %Y') as created_at,
                           (SELECT COUNT(*) FROM policy_entries pe WHERE pe.branch_id = b.id) as policy_count,
                           (SELECT COALESCE(SUM(pe.total_premium_amount),0) FROM policy_entries pe WHERE pe.branch_id = b.id) as total_premium,
                           (SELECT COUNT(*) FROM agents a WHERE a.branch_id = b.id) as agent_count
                    FROM branches b ORDER BY b.id DESC
                ");
                $stmt->execute();
                $res = $stmt->get_result();
                $branches = [];
                while ($row = $res->fetch_assoc()) {
                    $branches[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'mobile' => $row['mobile'],
                        'email' => $row['email'],
                        'address' => $row['address'],
                        'state' => $row['state'],
                        'branch_code' => $row['branch_code'],
                        'city' => $row['city'],
                        'pincode' => $row['pincode'],
                        'gst_number' => $row['gst_number'],
                        'wallet_balance' => (float)$row['wallet_balance'],
                        'is_active' => (int)$row['is_active'],
                        'created_at' => $row['created_at'],
                        'policy_count' => (int)$row['policy_count'],
                        'total_premium' => (float)$row['total_premium'],
                        'agent_count' => (int)$row['agent_count']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $branches]);
                exit();

            case 'getBranchPolicies':
                // policy count + recent policies for view modal
                $bid = (int)($_GET['id'] ?? 0);
                if ($bid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid branch ID']); exit(); }
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total_premium_amount),0) as total FROM policy_entries WHERE branch_id = ?");
                $stmt->bind_param("i", $bid);
                $stmt->execute();
                $agg = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                // raw entry_date so ORDER BY is a real date, not the formatted alias
                $stmt = $conn->prepare("SELECT policy_number, customer_name, total_premium_amount, status, entry_date,
                                        DATE_FORMAT(entry_date, '%b %d, %Y') as entry_fmt
                                        FROM policy_entries WHERE branch_id = ? ORDER BY entry_date DESC, id DESC LIMIT 15");
                $stmt->bind_param("i", $bid);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) $rows[] = $r;
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'count' => (int)$agg['cnt'], 'total' => (float)$agg['total'], 'data' => $rows]);
                exit();

            case 'getBranchAgents':
                // agents within this branch
                $bid = (int)($_GET['id'] ?? 0);
                if ($bid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid branch ID']); exit(); }
                $stmt = $conn->prepare("SELECT agent_code, name, mobile, email, is_active
                                        FROM agents WHERE branch_id = ? ORDER BY name ASC");
                $stmt->bind_param("i", $bid);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) $rows[] = $r;
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();

            case 'addBranch':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $name = trim($_POST['name'] ?? '');
                $mobile = trim($_POST['mobile'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $state = trim($_POST['state'] ?? '');

                // validate
                if (strlen($name) < 2 || strlen($name) > 150) {
                    echo json_encode(['success' => false, 'message' => 'Name must be 2-150 characters']);
                    exit();
                }
                if (!preg_match('/^\d{10,15}$/', $mobile)) {
                    echo json_encode(['success' => false, 'message' => 'Mobile must be 10-15 digits']);
                    exit();
                }
                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }
                if (empty($address)) {
                    echo json_encode(['success' => false, 'message' => 'Address is required']);
                    exit();
                }
                if (empty($state)) {
                    echo json_encode(['success' => false, 'message' => 'State is required']);
                    exit();
                }

                $emailVal = !empty($email) ? $email : null;
                $ex = branchExtras($_POST, $conn);
                if (is_string($ex)) { echo json_encode(['success' => false, 'message' => $ex]); exit(); }
                [$code, $gst, $city, $pin] = $ex;

                $stmt = $conn->prepare("INSERT INTO branches (name, branch_code, mobile, email, address, city, pincode, state, gst_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssssss", $name, $code, $mobile, $emailVal, $address, $city, $pin, $state, $gst);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add branch']);
                    exit();
                }
                $insertId = $stmt->insert_id;
                $stmt->close();

                logActivity($user_id, $username, 'Branch Created', "Created branch: $name" . ($code ? " ($code)" : '') . ($gst ? ", GSTIN $gst" : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Branch added successfully', 'data' => ['id' => $insertId]]);
                exit();

            case 'bulkAddBranches':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows) || count($rows) === 0) { echo json_encode(['success'=>false,'message'=>'No branches to add']); exit(); }
                $off = max(0, (int)($_POST['row_offset'] ?? 0)); // client posts in chunks - keep row numbers global

                $inserted = 0; $errors = [];
                $seenName = []; $seenCode = []; // within-batch dedup
                foreach ($rows as $idx => $r) {
                    $line = $off + $idx + 1;
                    $name = trim($r['name'] ?? '');
                    $mobile = trim($r['mobile'] ?? '');
                    $email = trim($r['email'] ?? '');
                    $addr = trim($r['address'] ?? '');
                    $state = trim($r['state'] ?? '');

                    if ($name === '' && $mobile === '' && $addr === '') continue; // trailing blank line

                    if (strlen($name) < 2 || strlen($name) > 150) { $errors[] = "Row $line: name must be 2-150 characters"; continue; }
                    if (!preg_match('/^\d{10,15}$/', $mobile)) { $errors[] = "Row $line: mobile must be 10-15 digits"; continue; }
                    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "Row $line: invalid email '$email'"; continue; }
                    if ($addr === '') { $errors[] = "Row $line: address is required"; continue; }
                    if ($state === '' || strlen($state) > 100) { $errors[] = "Row $line: state is required (max 100 chars)"; continue; }

                    $nk = strtolower($name);
                    if (isset($seenName[$nk])) { $errors[] = "Row $line: duplicate branch name '$name' in list"; continue; }
                    $stmt = $conn->prepare("SELECT id FROM branches WHERE name = ?");
                    $stmt->bind_param("s", $name); $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) { $stmt->close(); $errors[] = "Row $line: branch '$name' already exists"; continue; }
                    $stmt->close();

                    $ex = branchExtras($r, $conn);
                    if (is_string($ex)) { $errors[] = "Row $line: $ex"; continue; }
                    [$code, $gst, $city, $pin] = $ex;
                    if ($code && isset($seenCode[$code])) { $errors[] = "Row $line: duplicate branch code '$code' in list"; continue; }

                    $emailVal = $email ?: null;
                    $stmt = $conn->prepare("INSERT INTO branches (name, branch_code, mobile, email, address, city, pincode, state, gst_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssssssss", $name, $code, $mobile, $emailVal, $addr, $city, $pin, $state, $gst);
                    if ($stmt->execute()) { $seenName[$nk] = 1; if ($code) $seenCode[$code] = 1; $inserted++; }
                    else { $errors[] = "Row $line: failed to add '$name'"; }
                    $stmt->close();
                }

                logActivity($user_id, $username, 'Bulk Branch Add', "Added $inserted branches, " . count($errors) . " errors");
                $conn->close();
                echo json_encode(['success'=>true, 'message'=>"$inserted branch(es) added.", 'inserted'=>$inserted, 'errors'=>$errors]);
                exit();

            case 'updateBranch':
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
                $name = trim($_POST['name'] ?? '');
                $mobile = trim($_POST['mobile'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $state = trim($_POST['state'] ?? '');
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                if ($bid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid branch ID']);
                    exit();
                }

                // validate
                if (strlen($name) < 2 || strlen($name) > 150) {
                    echo json_encode(['success' => false, 'message' => 'Name must be 2-150 characters']);
                    exit();
                }
                if (!preg_match('/^\d{10,15}$/', $mobile)) {
                    echo json_encode(['success' => false, 'message' => 'Mobile must be 10-15 digits']);
                    exit();
                }
                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }
                if (empty($address)) {
                    echo json_encode(['success' => false, 'message' => 'Address is required']);
                    exit();
                }
                if (empty($state)) {
                    echo json_encode(['success' => false, 'message' => 'State is required']);
                    exit();
                }

                $emailVal = !empty($email) ? $email : null;
                $ex = branchExtras($_POST, $conn, $bid);
                if (is_string($ex)) { echo json_encode(['success' => false, 'message' => $ex]); exit(); }
                [$code, $gst, $city, $pin] = $ex;

                $stmt = $conn->prepare("UPDATE branches SET name=?, branch_code=?, mobile=?, email=?, address=?, city=?, pincode=?, state=?, gst_number=?, is_active=? WHERE id=?");
                $stmt->bind_param("sssssssssii", $name, $code, $mobile, $emailVal, $address, $city, $pin, $state, $gst, $isActive, $bid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update branch']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Branch Updated', "Updated branch #$bid: $name" . ($code ? " ($code)" : '') . ($gst ? ", GSTIN $gst" : '') . ($city ? ", $city" : '') . ($pin ? " $pin" : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Branch updated successfully']);
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
                    // skip if linked policies/expenses/income (same check as single delete)
                    $stmt = $conn->prepare("SELECT
                        (SELECT COUNT(*) FROM policy_entries WHERE branch_id = ?) +
                        (SELECT COUNT(*) FROM expenses WHERE branch_id = ?) +
                        (SELECT COUNT(*) FROM other_income WHERE branch_id = ?) as cnt");
                    $stmt->bind_param("iii", $bid, $bid, $bid);
                    $stmt->execute();
                    $linked = $stmt->get_result()->fetch_assoc()['cnt'];
                    $stmt->close();
                    if ($linked > 0) { $skipped++; continue; }

                    $stmt = $conn->prepare("DELETE FROM branches WHERE id = ?");
                    $stmt->bind_param("i", $bid);
                    if ($stmt->execute() && $stmt->affected_rows > 0) $deleted++;
                    $stmt->close();
                }

                logActivity($user_id, $username, 'Bulk delete', "Deleted $deleted branch(es), skipped $skipped");
                $conn->close();
                $msg = "$deleted record(s) deleted";
                if ($skipped > 0) $msg .= " — $skipped skipped (linked records)";
                echo json_encode(['success' => true, 'message' => $msg]);
                exit();

            case 'deleteBranch':
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
                    echo json_encode(['success' => false, 'message' => 'Invalid branch ID']);
                    exit();
                }

                // get name before delete
                $stmt = $conn->prepare("SELECT name FROM branches WHERE id = ?");
                $stmt->bind_param("i", $bid);
                $stmt->execute();
                $delRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $delName = $delRow ? $delRow['name'] : 'Unknown';

                // check linked records
                $stmt = $conn->prepare("SELECT
                    (SELECT COUNT(*) FROM policy_entries WHERE branch_id = ?) +
                    (SELECT COUNT(*) FROM expenses WHERE branch_id = ?) +
                    (SELECT COUNT(*) FROM other_income WHERE branch_id = ?) as cnt");
                $stmt->bind_param("iii", $bid, $bid, $bid);
                $stmt->execute();
                $linked = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();
                if ($linked > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete branch — it has $linked linked records (policies, expenses, or income)"]);
                    exit();
                }

                $stmt = $conn->prepare("DELETE FROM branches WHERE id = ?");
                $stmt->bind_param("i", $bid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete branch']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Branch Deleted', "Deleted branch: $delName (#$bid)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Branch deleted successfully']);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("branches.php error: " . $e->getMessage());
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
    <title>Branch Management - Insurance Management System</title>

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
            $pageTitle   = 'Branch Management';
            $crumbs      = [['label' => 'Accounting', 'icon' => 'fa-calculator']];
            $pageActions = [];
            if ($isAdmin) {
                $pageActions[] = ['label' => 'Add Branch', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
                $pageActions[] = ['label' => 'Bulk Add / Import', 'icon' => 'fa-file-excel', 'onclick' => 'branchesImport.open()'];
            }
            $pageActions[] = ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(branchesTable,'csv')"];
            $pageActions[] = ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(branchesTable,'pdf')"];
            $pageActions[] = ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(branchesTable,'print')"];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadBranches()'];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="branchesSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="branchesSearch" placeholder="Search branches…" autocomplete="off"></div>
                <div class="hf-field"><label for="filterState" title="State"><i class="fas fa-map-marker-alt"></i></label><select id="filterState" class="filter-input searchable"><option value="">All States</option></select></div>
                <div class="hf-field"><label for="filterStatus" title="Status"><i class="fas fa-toggle-on"></i></label><select id="filterStatus" class="filter-input searchable"><option value="">All Status</option><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
                <button type="button" class="btn btn-icon" onclick="clearFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>

            <div class="data-section">

                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Empty state -->
                <div id="emptyStateBox" style="display: none;"></div>

                <!-- table -->
                <div id="tableContainer" style="display: none;">
                    <div id="branchesTable"></div>
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
    <!-- Branch Modal -->
    <div class="modal-overlay" id="branchModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-building"></i> Add Branch</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="branchForm">
                    <input type="hidden" id="branchId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Name *</label>
                            <input type="text" id="branchName" name="name" required minlength="2" maxlength="150" placeholder="Branch name">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Mobile *</label>
                            <input type="tel" id="branchMobile" name="mobile" required minlength="10" maxlength="15" placeholder="10-15 digits" pattern="\d{10,15}">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-barcode"></i> Branch Code</label>
                            <input type="text" id="branchCode" name="branch_code" maxlength="20" pattern="[A-Za-z0-9_\-]{2,20}" placeholder="e.g. MUM01" class="text-uppercase">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-file-invoice"></i> GSTIN</label>
                            <input type="text" id="branchGst" name="gst_number" maxlength="15" minlength="15" pattern="[0-9]{2}[A-Za-z]{5}[0-9]{4}[A-Za-z][1-9A-Za-z][Zz][0-9A-Za-z]" placeholder="27ABCDE1234F1Z5" class="text-uppercase">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" id="branchEmail" name="email" placeholder="email@example.com">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-marker-alt"></i> State *</label>
                            <input type="text" id="branchState" name="state" required placeholder="State">
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-map-signs"></i> Address *</label>
                        <textarea id="branchAddress" name="address" required rows="3" placeholder="Full address"></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-city"></i> City</label>
                            <input type="text" id="branchCity" name="city" maxlength="100" placeholder="City">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-pin"></i> Pincode</label>
                            <input type="text" id="branchPincode" name="pincode" inputmode="numeric" maxlength="6" pattern="[1-9][0-9]{5}" placeholder="6 digits">
                        </div>
                    </div>

                    <div class="form-group" id="statusToggleGroup" style="display: none;">
                        <label for="branchIsActive"><i class="fas fa-toggle-on"></i> Active</label>
                        <input type="checkbox" id="branchIsActive" name="is_active" class="toggle" checked>
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
        /** Rudees Digital */
        const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        let branchesTable = null;
        let branchesData = [];
        let isEditMode = false;
        let bulkSel = null;

        $(document).ready(function() {
            loadBranches();
            // head search -> table
            $('#branchesSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(branchesTable, v); },200); });
        });

        function loadBranches() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();
            $('#filtersSection').hide();

            $.ajax({
                url: '?action=getBranches',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        branchesData = res.data;

                        // empty state when no branches
                        if (!res.data.length) {
                            $('#loadingSkeleton').hide();
                            $('#filtersSection').hide();
                            $('#tableContainer').hide();
                            var es = emptyStateHtml({
                                icon: 'fa-building', title: 'No branches yet',
                                text: 'Add your first branch to get started.',
                                btnText: IS_ADMIN ? 'Add Branch' : '', btnId: IS_ADMIN ? 'emptyAddBranch' : ''
                            });
                            $('#emptyStateBox').html(es).show();
                            $('#emptyAddBranch').off('click').on('click', openAddModal);
                            return;
                        }
                        $('#emptyStateBox').hide();

                        // populate state filter
                        const states = [...new Set(res.data.map(r => r.state).filter(Boolean))].sort();
                        const filterSel = $('#filterState');
                        filterSel.find('option:not(:first)').remove();
                        states.forEach(function(s) {
                            filterSel.append('<option value="' + escapeHtml(s) + '">' + escapeHtml(s) + '</option>');
                        });
                        if (filterSel[0] && filterSel[0].sdRefresh) filterSel[0].sdRefresh();

                        $('#loadingSkeleton').hide();
                        $('#tableContainer').show();
                        $('#filtersSection').show();

                        setTimeout(function() { initializeDataTable(res.data); }, 100);
                    } else {
                        $('#loadingSkeleton').hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load branches' });
                    }
                },
                error: function(xhr, status, err) {
                    $('#loadingSkeleton').hide();
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
                }
            });
        }

        function initializeDataTable(data) {
            if (branchesTable) return rsTbData(branchesTable, data); // build once, swap rows

            let cols = [];
            // bulk checkbox col - admin only
            if (IS_ADMIN) {
                cols.push({ data:'id', title:'<input type="checkbox" class="bulk-check-all">', orderable:false, searchable:false, className:'bulk-col', render:function(d){ return '<input type="checkbox" class="bulk-check" data-id="'+d+'">'; } });
            }
            cols = cols.concat([
                { data: 'id', title: 'ID' },
                { data: 'name', title: 'Name', render: function(d) { return escapeHtml(d); } },
                { data: 'branch_code', title: 'Code', render: function(d) { return d ? escapeHtml(d) : '<em class="text-muted">-</em>'; } },
                { data: 'mobile', title: 'Mobile', render: function(d) { return escapeHtml(d); } },
                {
                    data: 'email',
                    title: 'Email',
                    render: function(d) { return d ? escapeHtml(d) : '<em class="text-muted">-</em>'; }
                },
                { data: 'state', title: 'State', render: function(d) { return escapeHtml(d); } },
                { data: 'city', title: 'City', visible: false },
                { data: 'pincode', title: 'Pincode', visible: false },
                { data: 'gst_number', title: 'GSTIN', visible: false },
                {
                    data: 'wallet_balance',
                    title: 'Wallet Balance',
                    render: function(d) {
                        return '<span class="wallet-amount">\u20B9' + parseFloat(d).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '</span>';
                    }
                },
                { data: 'policy_count', title: '# Policies' },
                {
                    data: 'total_premium',
                    title: 'Total Premium',
                    render: function(d) {
                        return '\u20B9' + parseFloat(d || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                    }
                },
                { data: 'agent_count', title: '# Agents' },
                {
                    data: 'is_active',
                    title: 'Status',
                    render: function(d) {
                        return d
                            ? '<span class="status-badge status-active">Active</span>'
                            : '<span class="status-badge status-inactive">Inactive</span>';
                    }
                },
                { data: 'created_at', title: 'Created' }
            ]);

            // actions col - view for all, more menu for admin
            cols.push({
                data: null,
                title: 'Actions',
                orderable: false,
                render: function(d, type, row) {
                    var html = '<div class="action-hub">';
                    html += '<button class="action-icon view-icon" data-act="view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button>';
                    if (IS_ADMIN) {
                        html += '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button>';
                        html += '<div class="more-dropdown">';
                        html += '<button class="more-item" data-act="edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> Edit</button>';
                        html += '<div class="more-divider"></div>';
                        html += '<button class="more-item danger" data-act="delete" data-id="' + row.id + '"><i class="fas fa-trash"></i> Delete</button>';
                        html += '</div></div>';
                    }
                    return html + '</div>';
                }
            });

            branchesTable = rsTable('#branchesTable', {
                columns: cols, data: data, title: 'Branches', file: 'branches', sort: ['id', 'desc'],
                rowClass: function(r) { return r.is_active ? '' : 'dt-row-muted'; }, // inactive branches
                info: function() { return [['State', $('#filterState').val() || 'All'], ['Status', $('#filterStatus').val() || 'All']]; }
            });
            rsTbSearch(branchesTable, $('#branchesSearch').val());

            // bulk row-select - admin only
            if (IS_ADMIN) {
                bulkSel = initBulkSelect({
                    table: branchesTable,
                    label: 'branches',
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
                                    loadBranches();
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

            // bind filter events
            $('#filterState, #filterStatus').off('change').on('change', applyFilters);

            // one delegated handler for all row actions
            $('#branchesTable').off('click', '[data-act]').on('click', '[data-act]', function() {
                var id = parseInt($(this).data('id'), 10);
                var act = $(this).data('act');
                var row = branchesData.find(function(r) { return r.id === id; });
                if (!row) return;
                if (act === 'view') viewRecord(row);
                else if (act === 'edit' && IS_ADMIN) editBranch(row);
                else if (act === 'delete' && IS_ADMIN) deleteBranch(id);
            });
            applyFilters();
        }

        function applyFilters() {
            const state = $('#filterState').val(), status = $('#filterStatus').val();
            rsTbWhere(branchesTable, 'state', state ? function(r) { return r.state === state; } : null);
            rsTbWhere(branchesTable, 'status', status ? function(r) { return !!Number(r.is_active) === (status === 'Active'); } : null);
        }

        function clearFilters() {
            $('#filterState').val('');
            $('#filterStatus').val('');
            $('#branchesSearch').val('');
            ['filterState', 'filterStatus'].forEach(function(id) { var el = document.getElementById(id); if (el && el.sdRefresh) el.sdRefresh(); });
            rsTbClear(branchesTable);
        }

        var moneyFmt = function(n) { return '\u20B9' + parseFloat(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); };

        // tabbed view modal \u2014 Profile / Policies / Agents
        function viewRecord(d) {
            var statusCls = d.is_active ? 'status-active' : 'status-inactive';

            var profile = '<table class="view-detail-table">';
            profile += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">' + d.id + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-building"></i> Name</td><td class="vd-value">' + escapeHtml(d.name || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-phone"></i> Mobile</td><td class="vd-value">' + escapeHtml(d.mobile || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-envelope"></i> Email</td><td class="vd-value">' + escapeHtml(d.email || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-barcode"></i> Branch Code</td><td class="vd-value">' + escapeHtml(d.branch_code || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-file-invoice"></i> GSTIN</td><td class="vd-value">' + escapeHtml(d.gst_number || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-city"></i> City</td><td class="vd-value">' + escapeHtml(d.city || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-map-pin"></i> Pincode</td><td class="vd-value">' + escapeHtml(d.pincode || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-map-marker-alt"></i> State</td><td class="vd-value">' + escapeHtml(d.state || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-wallet"></i> Wallet Balance</td><td class="vd-value">' + moneyFmt(d.wallet_balance) + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-toggle-on"></i> Status</td><td class="vd-value"><span class="status-badge ' + statusCls + '">' + (d.is_active ? 'Active' : 'Inactive') + '</span></td>';
            profile += '<td class="vd-label"><i class="fas fa-calendar"></i> Created</td><td class="vd-value">' + escapeHtml(d.created_at || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-map-signs"></i> Address</td><td class="vd-value" colspan="3">' + escapeHtml(d.address || '-') + '</td></tr>';
            profile += '</table>';

            var html = '<div class="tab-nav">';
            html += '<button type="button" class="tab-btn active" data-tab="profile"><i class="fas fa-building"></i> Profile</button>';
            html += '<button type="button" class="tab-btn" data-tab="policies"><i class="fas fa-file-contract"></i> Policies</button>';
            html += '<button type="button" class="tab-btn" data-tab="agents"><i class="fas fa-user-tie"></i> Agents</button>';
            html += '</div>';
            html += '<div class="tab-content active" data-tab="profile">' + profile + '</div>';
            html += '<div class="tab-content" data-tab="policies" id="branchPoliciesPane"><em class="text-muted">Loading policies...</em></div>';
            html += '<div class="tab-content" data-tab="agents" id="branchAgentsPane"><em class="text-muted">Loading agents...</em></div>';

            document.getElementById('viewModalTitle').innerHTML = '<i class="fas fa-eye"></i> Branch Details';
            document.getElementById('viewModalBody').innerHTML = html;
            initModalTabs('#viewModalBody');
            document.getElementById('viewModal').classList.add('active');
            loadBranchPolicies(d.id);
            loadBranchAgents(d.id);
        }

        function loadBranchPolicies(bid) {
            $.ajax({
                url: '?action=getBranchPolicies&id=' + bid, method: 'GET', dataType: 'json',
                success: function(res) {
                    if (!res.success) { $('#branchPoliciesPane').html('<em class="text-muted">Failed to load.</em>'); return; }
                    var t = '<div class="info-banner"><i class="fas fa-file-contract"></i> Policies: <b>' + res.count
                        + '</b> &nbsp;|&nbsp; Total Premium: <b>' + moneyFmt(res.total) + '</b></div>';
                    $('#branchPoliciesPane').html(t + '<div class="tab-table"></div>');
                    rsTabTable($('#branchPoliciesPane .tab-table')[0], {
                        data: res.data, title: 'Branch Policies', file: 'branch_policies', sort: ['entry_date', 'desc'], empty: 'No policies for this branch yet',
                        columns: [
                            { data: 'policy_number', title: 'Policy #', render: function(d) { return escapeHtml(d || '-'); } },
                            { data: 'customer_name', title: 'Customer', render: function(d) { return escapeHtml(d || '-'); } },
                            { data: 'total_premium_amount', title: 'Premium', render: function(d, t) { return t === 'export' ? (+d || 0) : moneyFmt(d); } },
                            { data: 'entry_date', title: 'Date', render: function(d, t, r) { return escapeHtml(r.entry_fmt || '-'); } }
                        ]
                    });
                },
                error: function() { $('#branchPoliciesPane').html('<em class="text-muted">Failed to load.</em>'); }
            });
        }

        function loadBranchAgents(bid) {
            $.ajax({
                url: '?action=getBranchAgents&id=' + bid, method: 'GET', dataType: 'json',
                success: function(res) {
                    $('#branchAgentsPane').html('<div class="tab-table"></div>');
                    rsTabTable($('#branchAgentsPane .tab-table')[0], {
                        data: res.success ? res.data : [], title: 'Branch Agents', file: 'branch_agents', empty: 'No agents in this branch yet',
                        columns: [
                            { data: 'agent_code', title: 'Code', render: function(d) { return escapeHtml(d || '-'); } },
                            { data: 'name', title: 'Name', render: function(d) { return escapeHtml(d || '-'); } },
                            { data: 'mobile', title: 'Mobile', render: function(d) { return escapeHtml(d || '-'); } },
                            { data: 'is_active', title: 'Status', render: function(d, t) {
                                var on = d == 1, txt = on ? 'Active' : 'Inactive';
                                return t === 'display' ? '<span class="status-badge ' + (on ? 'status-active' : 'status-inactive') + '">' + txt + '</span>' : txt; } }
                        ]
                    });
                },
                error: function() { $('#branchAgentsPane').html('<em class="text-muted">Failed to load.</em>'); }
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
            $('#modalTitle').html('<i class="fas fa-building"></i> Add Branch');
            $('#branchForm')[0].reset();
            $('#branchId').val('');
            $('#branchIsActive').prop('checked', true);
            $('#statusToggleGroup').hide();
            $('#branchModal').addClass('active');
        }

        function editBranch(branch) {
            isEditMode = true;
            $('#modalTitle').html('<i class="fas fa-edit"></i> Edit Branch');
            $('#branchId').val(branch.id);
            $('#branchName').val(branch.name);
            $('#branchMobile').val(branch.mobile);
            $('#branchEmail').val(branch.email || '');
            $('#branchAddress').val(branch.address);
            $('#branchState').val(branch.state);
            $('#branchCode').val(branch.branch_code || '');
            $('#branchGst').val(branch.gst_number || '');
            $('#branchCity').val(branch.city || '');
            $('#branchPincode').val(branch.pincode || '');
            $('#branchIsActive').prop('checked', !!branch.is_active);
            $('#statusToggleGroup').show();
            $('#branchModal').addClass('active');
        }

        function closeModal() {
            $('#branchModal').removeClass('active');
            $('#branchForm')[0].reset();
        }

        // close on overlay click
        document.getElementById('branchModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // form submit
        $('#branchForm').on('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const action = isEditMode ? 'updateBranch' : 'addBranch';

            // checkbox handling
            if (!$('#branchIsActive').is(':checked')) {
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
                        setTimeout(loadBranches, 100);
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

        function deleteBranch(bid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Branch?',
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

                $.ajax({
                    url: '?action=deleteBranch',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                            setTimeout(loadBranches, 100);
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

        // bulk add / excel import — engine lives in sidebar.php
        var branchesImport = RSImport({
            key: 'branches', title: 'Bulk Add / Import Branches', url: '?action=bulkAddBranches',
            hint: 'Add many branches at once — upload an Excel/CSV file or type the rows. <strong>Name, mobile, address and state</strong> are required. A branch name that already exists is skipped, so re-uploading the same sheet will not duplicate branches.',
            onDone: function() { setTimeout(loadBranches, 100); },
            fields: [
                { key: 'name', label: 'Branch Name *', cls: 'bg-name', req: true, max: 150, ph: 'Main Branch', aliases: ['name', 'branchname', 'branch', 'office', 'officename'], demo: 'Demo Main Branch', demo2: 'Demo City Branch' },
                { key: 'mobile', label: 'Mobile *', cls: 'bg-mobile', req: true, max: 15, digits: true, ph: '10-15 digits', aliases: ['mobile', 'mobileno', 'phone', 'phoneno', 'contact', 'contactno'], demo: '9876543210', demo2: '9876543211' },
                { key: 'email', label: 'Email', cls: 'bg-email', ph: 'optional', aliases: ['email', 'emailaddress', 'emailid', 'mail'], demo: 'branch@example.com', demo2: '' },
                { key: 'address', label: 'Address *', cls: 'bg-addr', req: true, ph: 'Street, city', aliases: ['address', 'addr', 'location'], demo: 'Demo Street, Mumbai', demo2: 'Demo Road, Pune' },
                { key: 'state', label: 'State *', cls: 'bg-state', req: true, max: 100, ph: 'Maharashtra', aliases: ['state', 'province', 'region'], demo: 'Maharashtra', demo2: 'Maharashtra' },
                { key: 'branch_code', label: 'Branch Code', cls: 'bg-code', max: 20, ph: 'optional, unique', aliases: ['branchcode', 'code', 'branchid'], demo: 'DMB01', demo2: 'DCB02' },
                { key: 'city', label: 'City', cls: 'bg-city', max: 100, ph: 'optional', aliases: ['city', 'town'], demo: 'Mumbai', demo2: 'Pune' },
                { key: 'pincode', label: 'Pincode', cls: 'bg-pin', max: 6, digits: true, ph: '6 digits', aliases: ['pincode', 'pin', 'zip', 'zipcode', 'postalcode'], demo: '400001', demo2: '411001' },
                { key: 'gst_number', label: 'GSTIN', cls: 'bg-gst', max: 15, ph: 'optional', aliases: ['gstnumber', 'gstin', 'gst', 'gstno'], demo: '27ABCDE1234F1Z5', demo2: '' }
            ]
        });
        <?php endif; ?>
    </script>
</body>
</html>
