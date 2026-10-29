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
$current_page = 'bank_accounts';

// agent cant access
if ($role === 'agent') { header("Location: dashboard.php"); exit(); }

// holder + opening date (form + import) -> [err, holder, obDate]
function baExtra(array $r): array {
    $holder = trim((string)($r['account_holder_name'] ?? '')) ?: null;
    $ob = trim((string)($r['opening_balance_date'] ?? '')) ?: null;
    if ($holder !== null && mb_strlen($holder) > 150) return ['Account holder name max 150 chars', null, null];
    if ($ob !== null && (!($d = DateTime::createFromFormat('Y-m-d', $ob)) || $d->format('Y-m-d') !== $ob)) return ['Opening balance date must be YYYY-MM-DD', null, null];
    return [null, $holder, $ob];
}

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getBranches':
                // office branches for dropdown
                $stmt = $conn->prepare("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name");
                $stmt->execute();
                $res = $stmt->get_result();
                $branches = [];
                while ($row = $res->fetch_assoc()) {
                    $branches[] = ['id' => (int)$row['id'], 'name' => $row['name']];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $branches]);
                exit();

            case 'getAccounts':
                $stmt = $conn->prepare("
                    SELECT ba.*, b.name as office_branch
                    FROM bank_accounts ba
                    LEFT JOIN branches b ON ba.branch_id = b.id
                    ORDER BY ba.id DESC
                ");
                $stmt->execute();
                $res = $stmt->get_result();
                $accounts = [];
                while ($row = $res->fetch_assoc()) {
                    $accounts[] = [
                        'id' => (int)$row['id'],
                        'bank_name' => $row['bank_name'],
                        'account_number' => $row['account_number'],
                        'ifsc_code' => $row['ifsc_code'],
                        'branch_name' => $row['branch_name'],
                        'account_type' => $row['account_type'],
                        'opening_balance' => (float)$row['opening_balance'],
                        'opening_balance_date' => $row['opening_balance_date'] ?? null,
                        'account_holder_name' => $row['account_holder_name'] ?? null,
                        'current_balance' => (float)$row['current_balance'],
                        'branch_id' => $row['branch_id'] ? (int)$row['branch_id'] : null,
                        'office_branch' => $row['office_branch'],
                        'is_active' => (int)$row['is_active'],
                        'created_at' => $row['created_at'],
                        'updated_at' => $row['updated_at']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $accounts]);
                exit();

            case 'addAccount':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                    echo json_encode(['success' => false, 'message' => 'Invalid request']);
                    exit();
                }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $bank_name = trim($_POST['bank_name'] ?? '');
                $account_number = trim($_POST['account_number'] ?? '');
                $ifsc_code = trim($_POST['ifsc_code'] ?? '');
                $branch_name = trim($_POST['branch_name'] ?? '');
                $account_type = trim($_POST['account_type'] ?? '');
                $opening_balance = (float)($_POST['opening_balance'] ?? 0);
                $branch_id = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
                [$xErr, $holder, $obDate] = baExtra($_POST);
                if ($xErr) { echo json_encode(['success' => false, 'message' => $xErr]); exit(); }

                // validate
                if (empty($bank_name) || strlen($bank_name) > 100) {
                    echo json_encode(['success' => false, 'message' => 'Bank name is required (max 100 chars)']);
                    exit();
                }
                if (empty($account_number) || strlen($account_number) > 20) {
                    echo json_encode(['success' => false, 'message' => 'Account number is required (max 20 chars)']);
                    exit();
                }
                if (empty($ifsc_code) || !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/i', $ifsc_code)) {
                    echo json_encode(['success' => false, 'message' => 'Valid IFSC code is required (e.g. SBIN0001234)']);
                    exit();
                }
                if (!in_array($account_type, ['Current', 'Savings'])) {
                    echo json_encode(['success' => false, 'message' => 'Account type must be Current or Savings']);
                    exit();
                }

                // check unique account number
                $chk = $conn->prepare("SELECT id FROM bank_accounts WHERE account_number = ?");
                $chk->bind_param("s", $account_number);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $chk->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Account number already exists']);
                    exit();
                }
                $chk->close();

                $branchNameVal = !empty($branch_name) ? $branch_name : null;
                $ifsc_code = strtoupper($ifsc_code);

                $stmt = $conn->prepare("INSERT INTO bank_accounts (bank_name, account_holder_name, account_number, ifsc_code, branch_name, account_type, opening_balance, opening_balance_date, current_balance, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssssdsdi", $bank_name, $holder, $account_number, $ifsc_code, $branchNameVal, $account_type, $opening_balance, $obDate, $opening_balance, $branch_id);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add bank account']);
                    exit();
                }
                $insertId = $stmt->insert_id;
                $stmt->close();

                logActivity($user_id, $username, 'Bank Account Created', "Created account: $bank_name - $account_number" . ($holder ? ", holder $holder" : '') . ($obDate ? ", opening as of $obDate" : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Bank account added successfully', 'data' => ['id' => $insertId]]);
                exit();

            case 'bulkAddAccounts':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows) || count($rows) === 0) { echo json_encode(['success'=>false,'message'=>'No accounts to add']); exit(); }
                $off = max(0, (int)($_POST['row_offset'] ?? 0)); // client posts in chunks - keep row numbers global

                // branch name -> id, case-insensitive
                $branchMap = [];
                $bres = $conn->query("SELECT id, name FROM branches");
                while ($b = $bres->fetch_assoc()) $branchMap[strtolower(trim($b['name']))] = (int)$b['id'];

                $inserted = 0; $errors = [];
                $seenAcc = []; // within-batch dedup
                foreach ($rows as $idx => $r) {
                    $line = $off + $idx + 1;
                    $bank = trim($r['bank_name'] ?? '');
                    $acno = trim($r['account_number'] ?? '');
                    $ifsc = strtoupper(trim($r['ifsc_code'] ?? ''));
                    $bbranch = trim($r['branch_name'] ?? '');
                    $atype = trim($r['account_type'] ?? '') ?: 'Current';
                    $opening = (float)($r['opening_balance'] ?? 0);
                    $branchName = trim($r['branch'] ?? '');

                    if ($bank === '' && $acno === '') continue; // trailing blank line

                    if ($bank === '' || strlen($bank) > 100) { $errors[] = "Row $line: bank name is required (max 100 chars)"; continue; }
                    if ($acno === '' || strlen($acno) > 20) { $errors[] = "Row $line: account number is required (max 20 chars)"; continue; }
                    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) { $errors[] = "Row $line: invalid IFSC '$ifsc' (e.g. SBIN0001234)"; continue; }
                    if (!in_array($atype, ['Current', 'Savings'], true)) { $errors[] = "Row $line: account type must be Current or Savings"; continue; }
                    [$xErr, $holder, $obDate] = baExtra($r);
                    if ($xErr) { $errors[] = "Row $line: $xErr"; continue; }

                    $branchId = null;
                    if ($branchName !== '') {
                        if (!isset($branchMap[strtolower($branchName)])) { $errors[] = "Row $line: branch '$branchName' not found"; continue; }
                        $branchId = $branchMap[strtolower($branchName)];
                    }

                    if (isset($seenAcc[$acno])) { $errors[] = "Row $line: duplicate account number '$acno' in list"; continue; }
                    $stmt = $conn->prepare("SELECT id FROM bank_accounts WHERE account_number = ?");
                    $stmt->bind_param("s", $acno); $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) { $stmt->close(); $errors[] = "Row $line: account '$acno' already exists"; continue; }
                    $stmt->close();

                    $bbranchVal = $bbranch ?: null;
                    // opening seeds current balance, same as single add (no txn posted)
                    $stmt = $conn->prepare("INSERT INTO bank_accounts (bank_name, account_holder_name, account_number, ifsc_code, branch_name, account_type, opening_balance, opening_balance_date, current_balance, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssssssdsdi", $bank, $holder, $acno, $ifsc, $bbranchVal, $atype, $opening, $obDate, $opening, $branchId);
                    if ($stmt->execute()) { $seenAcc[$acno] = 1; $inserted++; }
                    else { $errors[] = "Row $line: failed to add '$acno'"; }
                    $stmt->close();
                }

                logActivity($user_id, $username, 'Bulk Bank Account Add', "Added $inserted accounts, " . count($errors) . " errors");
                $conn->close();
                echo json_encode(['success'=>true, 'message'=>"$inserted account(s) added.", 'inserted'=>$inserted, 'errors'=>$errors]);
                exit();

            case 'updateAccount':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                    echo json_encode(['success' => false, 'message' => 'Invalid request']);
                    exit();
                }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $aid = (int)($_POST['id'] ?? 0);
                $bank_name = trim($_POST['bank_name'] ?? '');
                $account_number = trim($_POST['account_number'] ?? '');
                $ifsc_code = trim($_POST['ifsc_code'] ?? '');
                $branch_name = trim($_POST['branch_name'] ?? '');
                $account_type = trim($_POST['account_type'] ?? '');
                $opening_balance = (float)($_POST['opening_balance'] ?? 0);
                $branch_id = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                [$xErr, $holder, $obDate] = baExtra($_POST);
                if ($xErr) { echo json_encode(['success' => false, 'message' => $xErr]); exit(); }

                if ($aid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid account ID']);
                    exit();
                }

                // validate
                if (empty($bank_name) || strlen($bank_name) > 100) {
                    echo json_encode(['success' => false, 'message' => 'Bank name is required (max 100 chars)']);
                    exit();
                }
                if (empty($account_number) || strlen($account_number) > 20) {
                    echo json_encode(['success' => false, 'message' => 'Account number is required (max 20 chars)']);
                    exit();
                }
                if (empty($ifsc_code) || !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/i', $ifsc_code)) {
                    echo json_encode(['success' => false, 'message' => 'Valid IFSC code is required (e.g. SBIN0001234)']);
                    exit();
                }
                if (!in_array($account_type, ['Current', 'Savings'])) {
                    echo json_encode(['success' => false, 'message' => 'Account type must be Current or Savings']);
                    exit();
                }

                // unique check excluding self
                $chk = $conn->prepare("SELECT id FROM bank_accounts WHERE account_number = ? AND id != ?");
                $chk->bind_param("si", $account_number, $aid);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $chk->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Account number already exists']);
                    exit();
                }
                $chk->close();

                $branchNameVal = !empty($branch_name) ? $branch_name : null;
                $ifsc_code = strtoupper($ifsc_code);

                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("UPDATE bank_accounts SET bank_name=?, account_holder_name=?, account_number=?, ifsc_code=?, branch_name=?, account_type=?, opening_balance=?, opening_balance_date=?, branch_id=?, is_active=? WHERE id=?");
                    $stmt->bind_param("ssssssdsiii", $bank_name, $holder, $account_number, $ifsc_code, $branchNameVal, $account_type, $opening_balance, $obDate, $branch_id, $isActive, $aid);
                    $stmt->execute();
                    $stmt->close();

                    // opening_balance may have changed -> rewrite current_balance + running_balance rows
                    recalcBankBalance($conn, $aid);

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update bank account']);
                    exit();
                }

                logActivity($user_id, $username, 'Bank Account Updated', "Updated account #$aid: $bank_name - $account_number" . ($holder ? ", holder $holder" : '') . ", opening " . number_format($opening_balance, 2) . ($obDate ? " as of $obDate" : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Bank account updated successfully']);
                exit();

            case 'deleteAccount':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
                    echo json_encode(['success' => false, 'message' => 'Invalid request']);
                    exit();
                }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $aid = (int)($_POST['id'] ?? 0);
                if ($aid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid account ID']);
                    exit();
                }

                // get name before delete
                $stmt = $conn->prepare("SELECT bank_name, account_number FROM bank_accounts WHERE id = ?");
                $stmt->bind_param("i", $aid);
                $stmt->execute();
                $delRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $delName = $delRow ? $delRow['bank_name'] . ' - ' . $delRow['account_number'] : 'Unknown';

                // check linked transactions
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM bank_transactions WHERE bank_account_id = ?");
                $stmt->bind_param("i", $aid);
                $stmt->execute();
                $linked = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();
                if ($linked > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete account — it has $linked linked bank transactions"]);
                    exit();
                }

                $stmt = $conn->prepare("DELETE FROM bank_accounts WHERE id = ?");
                $stmt->bind_param("i", $aid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete bank account']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Bank Account Deleted', "Deleted account: $delName (#$aid)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Bank account deleted successfully']);
                exit();

            case 'getAccountTransactions':
                // recent bank txns for one account - View modal Transactions tab
                $accId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
                if ($accId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid account ID']);
                    exit();
                }
                $stmt = $conn->prepare("
                    SELECT id, DATE_FORMAT(transaction_date, '%Y-%m-%d') as transaction_date,
                           description, transaction_type, amount, running_balance, reference_number
                    FROM bank_transactions
                    WHERE bank_account_id = ?
                    ORDER BY transaction_date DESC, id DESC
                    LIMIT 25
                ");
                $stmt->bind_param("i", $accId);
                $stmt->execute();
                $res = $stmt->get_result();
                $txns = [];
                while ($row = $res->fetch_assoc()) {
                    $txns[] = [
                        'id' => (int)$row['id'],
                        'transaction_date' => $row['transaction_date'],
                        'description' => $row['description'],
                        'transaction_type' => $row['transaction_type'],
                        'amount' => (float)$row['amount'],
                        'running_balance' => (float)$row['running_balance'],
                        'reference_number' => $row['reference_number']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $txns]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("bank_accounts.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
        exit();
    }
}

$isAdmin = ($role === 'admin');
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
    <title>Bank Accounts - Insurance Management System</title>

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
            $pageIcon    = 'fa-university';
            $pageTitle   = 'Bank Accounts';
            $crumbs      = [['label' => 'Accounting', 'icon' => 'fa-calculator']];
            $pageActions = [];
            if ($isAdmin) {
                $pageActions[] = ['label' => 'Add Account', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
                $pageActions[] = ['label' => 'Bulk Add / Import', 'icon' => 'fa-file-excel', 'onclick' => 'accountsImport.open()'];
            }
            $pageActions[] = ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(accountsTable,'csv')"];
            $pageActions[] = ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(accountsTable,'pdf')"];
            $pageActions[] = ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(accountsTable,'print')"];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadAccounts()'];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="accountsSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="accountsSearch" placeholder="Search accounts…" autocomplete="off"></div>
                <div class="hf-field"><label for="filterType" title="Account Type"><i class="fas fa-credit-card"></i></label><select id="filterType" class="filter-input searchable"><option value="">All Types</option><option value="Current">Current</option><option value="Savings">Savings</option></select></div>
                <div class="hf-field"><label for="filterStatus" title="Status"><i class="fas fa-toggle-on"></i></label><select id="filterStatus" class="filter-input searchable"><option value="">All Status</option><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
                <button type="button" class="btn btn-icon" onclick="clearFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>

            <div class="data-section">

                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.3"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.6"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.3"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.6"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Empty state -->
                <div id="emptyState" style="display: none;"></div>

                <!-- table -->
                <div id="tableContainer" style="display: none;">
                    <div id="accountsTable"></div>
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
    <!-- Bank Account Modal -->
    <div class="modal-overlay" id="accountModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-university"></i> Add Bank Account</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="accountForm">
                    <input type="hidden" id="accountId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-university"></i> Bank Name *</label>
                            <input type="text" id="bankName" name="bank_name" required maxlength="100" placeholder="e.g. State Bank of India">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Account Number *</label>
                            <input type="text" id="accountNumber" name="account_number" required maxlength="20" placeholder="e.g. 12345678901234">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Account Holder Name</label>
                            <input type="text" id="accountHolder" name="account_holder_name" maxlength="150" placeholder="Name as per bank records">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-barcode"></i> IFSC Code *</label>
                            <input type="text" id="ifscCode" name="ifsc_code" required maxlength="11" placeholder="e.g. SBIN0001234" pattern="[A-Za-z]{4}0[A-Za-z0-9]{6}">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-marker-alt"></i> Branch Name</label>
                            <input type="text" id="branchName" name="branch_name" maxlength="150" placeholder="Bank branch name">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-credit-card"></i> Account Type *</label>
                            <select id="accountType" name="account_type" class="searchable" required>
                                <option value="">Select Type</option>
                                <option value="Current">Current</option>
                                <option value="Savings">Savings</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-wallet"></i> Opening Balance *</label>
                            <input type="number" id="openingBalance" name="opening_balance" required min="0" step="0.01" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Opening Balance Date</label>
                            <input type="date" id="openingBalanceDate" name="opening_balance_date">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-code-branch"></i> Office Branch</label>
                            <select id="officeBranch" name="branch_id" class="searchable">
                                <option value="">-- None --</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" id="statusToggleGroup" style="display: none;">
                        <div class="toggle-switch">
                            <input type="checkbox" id="accountIsActive" name="is_active" class="toggle" checked>
                            <label for="accountIsActive">Active</label>
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
        /** Developed by Rameez Scripts */
        const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        let accountsTable = null;
        let accountsData = [];
        let branchesDropdown = [];
        let isEditMode = false;

        $(document).ready(function() {
            loadAccounts();
            if (IS_ADMIN) loadBranchesDropdown();
            // head search -> table
            $('#accountsSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(accountsTable, v); },200); });
        });

        // load office branches for dropdown
        function loadBranchesDropdown() {
            $.ajax({
                url: '?action=getBranches',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        branchesDropdown = res.data;
                        const sel = $('#officeBranch');
                        sel.find('option:not(:first)').remove();
                        res.data.forEach(function(b) {
                            sel.append('<option value="' + b.id + '">' + escapeHtml(b.name) + '</option>');
                        });
                        setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
                    }
                }
            });
        }

        function loadAccounts() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();
            $('#filtersSection').hide();

            $.ajax({
                url: '?action=getAccounts',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        accountsData = res.data;
                        $('#loadingSkeleton').hide();

                        if (!res.data.length) {
                            // empty state CTA
                            $('#tableContainer').hide();
                            $('#filtersSection').hide();
                            $('#emptyState').html(emptyStateHtml({
                                icon: 'fa-university',
                                title: 'No Bank Accounts',
                                text: 'Add your first bank account to start tracking balances.',
                                btnText: IS_ADMIN ? 'Add Account' : '',
                                btnId: IS_ADMIN ? 'emptyAddBtn' : ''
                            })).show();
                            $('#emptyAddBtn').off('click').on('click', openAddModal);
                            return;
                        }

                        $('#emptyState').hide();
                        $('#tableContainer').show();
                        $('#filtersSection').show();

                        setTimeout(function() { initializeDataTable(res.data); }, 100);
                    } else {
                        $('#loadingSkeleton').hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load accounts' });
                    }
                },
                error: function(xhr, status, err) {
                    $('#loadingSkeleton').hide();
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
                }
            });
        }

        function formatCurrency(val) {
            return '\u20B9' + parseFloat(val).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function initializeDataTable(data) {
            if (accountsTable) return rsTbData(accountsTable, data); // build once, swap rows

            let cols = [
                { data: 'id', title: 'ID' },
                {
                    data: 'bank_name',
                    title: 'Bank Name',
                    render: function(d) { return escapeHtml(d); }
                },
                {
                    data: 'account_number',
                    title: 'Account #',
                    render: function(d) {
                        return '<span class="badge-code">' + escapeHtml(d) + '</span>';
                    }
                },
                {
                    data: 'ifsc_code',
                    title: 'IFSC',
                    render: function(d) { return escapeHtml(d); }
                },
                {
                    data: 'account_type',
                    title: 'Type',
                    render: function(d) { return escapeHtml(d); }
                },
                {
                    data: 'opening_balance',
                    title: 'Opening Balance',
                    render: function(d, type, row) {
                        var amt = '<span class="wallet-amount">' + formatCurrency(d) + '</span>';
                        if (type !== 'display') return amt;
                        return amt + (row.opening_balance_date ? '<br><small>as of ' + escapeHtml(row.opening_balance_date) + '</small>' : '');
                    }
                },
                { data: 'opening_balance_date', title: 'Opening As Of', visible: false, render: function(d) { return escapeHtml(d || '-'); } },
                { data: 'account_holder_name', title: 'Account Holder', visible: false, render: function(d) { return escapeHtml(d || '-'); } },
                {
                    data: 'current_balance',
                    title: 'Current Balance',
                    render: function(d) {
                        return '<span class="wallet-amount">' + formatCurrency(d) + '</span>';
                    }
                },
                {
                    data: 'office_branch',
                    title: 'Office Branch',
                    render: function(d) { return d ? escapeHtml(d) : '<em style="color:#999;">-</em>'; }
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
            ];

            // action hub col (last) - view + transactions for all, edit/delete in More for admin
            cols.push({
                data: null,
                title: 'Actions',
                orderable: false,
                width: '140', // 3 icons
                render: function(d, type, row) {
                    var more = '';
                    if (IS_ADMIN) {
                        more = '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button>' +
                            '<div class="more-dropdown">' +
                            '<button class="more-item more-edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> Edit</button>' +
                            '<div class="more-divider"></div>' +
                            '<button class="more-item danger more-del" data-id="' + row.id + '"><i class="fas fa-trash"></i> Delete</button>' +
                            '</div></div>';
                    }
                    return '<div class="action-hub">' +
                        '<button class="action-icon view-icon act-view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button>' +
                        '<a class="action-icon view-icon act-ledger" href="bank_transactions.php?bank_account_id=' + row.id + '" title="Transactions"><i class="fas fa-book"></i></a>' +
                        more +
                    '</div>';
                }
            });

            accountsTable = rsTable('#accountsTable', {
                columns: cols, data: data, title: 'Bank Accounts', file: 'bank_accounts', sort: ['id', 'desc'],
                // negative balance = danger, inactive = muted
                rowClass: function(r) { return parseFloat(r.current_balance) < 0 ? 'dt-row-danger' : (!r.is_active ? 'dt-row-muted' : ''); },
                info: function() { return [['Type', $('#filterType').val() || 'All'], ['Status', $('#filterStatus').val() || 'All']]; }
            });
            rsTbSearch(accountsTable, $('#accountsSearch').val());
            $('#filterType, #filterStatus').off('change').on('change', applyFilters);
            applyFilters();
        }

        function applyFilters() {
            var acType = $('#filterType').val(), status = $('#filterStatus').val();
            rsTbWhere(accountsTable, 'type', acType ? function(r) { return r.account_type === acType; } : null);
            rsTbWhere(accountsTable, 'status', status ? function(r) { return !!Number(r.is_active) === (status === 'Active'); } : null);
        }

        function clearFilters() {
            $('#filterType').val('');
            $('#filterStatus').val('');
            $('#accountsSearch').val('');
            ['filterType', 'filterStatus'].forEach(function(id) { var el = document.getElementById(id); if (el && el.sdRefresh) el.sdRefresh(); });
            rsTbClear(accountsTable);
        }

        // delegated action handlers - one per table
        $('#accountsTable').on('click', '.act-view, .more-edit, .more-del', function() {
            var id = parseInt($(this).attr('data-id'), 10);
            var row = accountsData.find(function(r) { return r.id === id; });
            if (!row) return;
            if ($(this).hasClass('act-view')) viewRecord(row);
            else if ($(this).hasClass('more-edit')) editAccount(row);
            else if ($(this).hasClass('more-del')) deleteAccount(id);
        });

        // tabbed view detail modal
        function viewRecord(d) {
            var statusCls = d.is_active ? 'status-active' : 'status-inactive';
            var det = '<table class="view-detail-table">';
            det += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">' + escapeHtml(d.id) + '</td>';
            det += '<td class="vd-label"><i class="fas fa-university"></i> Bank Name</td><td class="vd-value">' + escapeHtml(d.bank_name || '-') + '</td></tr>';
            det += '<tr><td class="vd-label"><i class="fas fa-credit-card"></i> Account #</td><td class="vd-value"><span class="badge-code">' + escapeHtml(d.account_number || '-') + '</span></td>';
            det += '<td class="vd-label"><i class="fas fa-barcode"></i> IFSC</td><td class="vd-value">' + escapeHtml(d.ifsc_code || '-') + '</td></tr>';
            det += '<tr><td class="vd-label"><i class="fas fa-map-marker-alt"></i> Branch Name</td><td class="vd-value">' + escapeHtml(d.branch_name || '-') + '</td>';
            det += '<td class="vd-label"><i class="fas fa-tags"></i> Type</td><td class="vd-value">' + escapeHtml(d.account_type || '-') + '</td></tr>';
            det += '<tr><td class="vd-label"><i class="fas fa-user-tie"></i> Account Holder</td><td class="vd-value" colspan="3">' + escapeHtml(d.account_holder_name || '-') + '</td></tr>';
            det += '<tr><td class="vd-label"><i class="fas fa-wallet"></i> Opening Balance</td><td class="vd-value">' + formatCurrency(d.opening_balance) + (d.opening_balance_date ? ' <small>as of ' + escapeHtml(d.opening_balance_date) + '</small>' : '') + '</td>';
            det += '<td class="vd-label"><i class="fas fa-coins"></i> Current Balance</td><td class="vd-value">' + formatCurrency(d.current_balance) + '</td></tr>';
            det += '<tr><td class="vd-label"><i class="fas fa-code-branch"></i> Office Branch</td><td class="vd-value">' + escapeHtml(d.office_branch || '-') + '</td>';
            det += '<td class="vd-label"><i class="fas fa-toggle-on"></i> Status</td><td class="vd-value"><span class="status-badge ' + statusCls + '">' + (d.is_active ? 'Active' : 'Inactive') + '</span></td></tr>';
            det += '</table>';
            det += '<div style="margin-top:14px;"><a class="btn btn-primary" href="bank_transactions.php?bank_account_id=' + d.id + '"><i class="fas fa-book"></i> Open Full Ledger</a></div>';

            var body = '<div class="tab-nav">' +
                    '<button class="tab-btn active" data-tab="details"><i class="fas fa-info-circle"></i> Details</button>' +
                    '<button class="tab-btn" data-tab="txns"><i class="fas fa-list"></i> Transactions</button>' +
                '</div>' +
                '<div class="tab-content active" data-tab="details">' + det + '</div>' +
                '<div class="tab-content" data-tab="txns" id="acctTxnsPane">' +
                    '<div style="text-align:center;padding:20px;color:#999;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>' +
                '</div>';

            document.getElementById('viewModalTitle').innerHTML = '<i class="fas fa-eye"></i> ' + escapeHtml(d.bank_name || 'Bank Account');
            document.getElementById('viewModalBody').innerHTML = body;
            initModalTabs('#viewModalBody');
            document.getElementById('viewModal').classList.add('active');
            loadAccountTxns(d.id);
        }

        // recent txns for the account - View modal Transactions tab
        function loadAccountTxns(accId) {
            $.ajax({
                url: '?action=getAccountTransactions&account_id=' + accId,
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (!res.success) {
                        $('#acctTxnsPane').html('<div style="padding:20px;color:#ea4335;">' + escapeHtml(res.message || 'Failed to load') + '</div>');
                        return;
                    }
                    // signed amt so credit/debit sort together
                    var rows = res.data.map(function(t) { return Object.assign({ amt: t.transaction_type === 'credit' ? +t.amount : -t.amount }, t); });
                    $('#acctTxnsPane').html('<div class="tab-table"></div>' +
                        '<div style="margin-top:10px;text-align:right;"><a class="btn btn-secondary btn-sm" href="bank_transactions.php?bank_account_id=' + parseInt(accId) + '"><i class="fas fa-external-link-alt"></i> View All</a></div>');
                    rsTabTable($('#acctTxnsPane .tab-table')[0], {
                        data: rows, title: 'Recent Bank Transactions', file: 'bank_account_txns', sort: ['transaction_date', 'desc'],
                        empty: 'This account has no bank transactions yet',
                        columns: [
                            { data: 'transaction_date', title: 'Date' },
                            { data: 'description', title: 'Description', render: function(d) { return escapeHtml(d || '-'); } },
                            { data: 'reference_number', title: 'Reference', render: function(d) { return escapeHtml(d || '-'); } },
                            { data: 'amt', title: 'Amount', render: function(d, t) {
                                if (t === 'export') return d;
                                var s = (d < 0 ? '-' : '+') + formatCurrency(Math.abs(d));
                                return t === 'display' ? '<span class="wallet-amount ' + (d < 0 ? 'wallet-negative' : 'wallet-positive') + '">' + s + '</span>' : s; } },
                            { data: 'running_balance', title: 'Balance', render: function(d, t) {
                                return t === 'export' ? d : t === 'display' ? '<span class="wallet-amount">' + formatCurrency(d) + '</span>' : formatCurrency(d); } }
                        ]
                    });
                },
                error: function() {
                    $('#acctTxnsPane').html('<div style="padding:20px;color:#ea4335;">Connection error</div>');
                }
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
            $('#modalTitle').html('<i class="fas fa-university"></i> Add Bank Account');
            $('#accountForm')[0].reset();
            $('#accountId').val('');
            $('#accountIsActive').prop('checked', true);
            $('#statusToggleGroup').hide();
            $('#accountModal').addClass('active');
        }

        function editAccount(acc) {
            isEditMode = true;
            $('#modalTitle').html('<i class="fas fa-edit"></i> Edit Bank Account');
            $('#accountId').val(acc.id);
            $('#bankName').val(acc.bank_name);
            $('#accountNumber').val(acc.account_number);
            $('#ifscCode').val(acc.ifsc_code);
            $('#branchName').val(acc.branch_name || '');
            $('#accountType').val(acc.account_type);
            $('#openingBalance').val(acc.opening_balance);
            $('#openingBalanceDate').val(acc.opening_balance_date || '');
            $('#accountHolder').val(acc.account_holder_name || '');
            $('#officeBranch').val(acc.branch_id || '');
            $('#accountIsActive').prop('checked', !!acc.is_active);
            $('#statusToggleGroup').show();
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
            $('#accountModal').addClass('active');
        }

        function closeModal() {
            $('#accountModal').removeClass('active');
            $('#accountForm')[0].reset();
        }

        // close on overlay click
        document.getElementById('accountModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // form submit
        $('#accountForm').on('submit', function(e) {
            e.preventDefault();

            var formData = new FormData(this);
            formData.set('csrf_token', window.CSRF_TOKEN);
            var action = isEditMode ? 'updateAccount' : 'addAccount';

            // checkbox handling
            if (!$('#accountIsActive').is(':checked')) {
                formData.delete('is_active');
            }

            showLoading('Saving bank account...');

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
                        setTimeout(loadAccounts, 100);
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

        function deleteAccount(aid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Bank Account?',
                text: 'This action cannot be undone',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) return;

                var fd = new FormData();
                fd.append('id', aid);
                fd.append('csrf_token', window.CSRF_TOKEN);

                showLoading('Deleting bank account...');
                $.ajax({
                    url: '?action=deleteAccount',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        hideLoading();
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                            setTimeout(loadAccounts, 100);
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
        var accountsImport = RSImport({
            key: 'bank_accounts', title: 'Bulk Add / Import Bank Accounts', url: '?action=bulkAddAccounts',
            hint: 'Add many bank accounts at once — upload an Excel/CSV file or type the rows. <strong>Bank name, account number and IFSC</strong> are required. <strong>Opening balance</strong> seeds the account\'s current balance directly (it is not posted as a transaction). <strong>Branch</strong> is your own branch, matched by name; <strong>Bank Branch</strong> is the bank\'s branch label.',
            onDone: function() { setTimeout(loadAccounts, 100); },
            fields: [
                { key: 'bank_name', label: 'Bank Name *', cls: 'bg-bank', req: true, max: 100, ph: 'HDFC Bank', aliases: ['bankname', 'bank'], demo: 'Demo Bank', demo2: 'Demo Bank' },
                { key: 'account_holder_name', label: 'Holder Name', cls: 'bg-holder', max: 150, ph: 'optional', aliases: ['accountholdername', 'accountholder', 'holdername', 'holder', 'accountname'], demo: 'Demo Insurance Services', demo2: 'Demo Insurance Services' },
                { key: 'account_number', label: 'Account No *', cls: 'bg-acno', req: true, max: 20, ph: '50100123456', aliases: ['accountnumber', 'accountno', 'acno', 'acctno', 'account'], demo: '50100123456', demo2: '50100123457' },
                { key: 'ifsc_code', label: 'IFSC *', cls: 'bg-ifsc', req: true, max: 11, ph: 'SBIN0001234', aliases: ['ifsccode', 'ifsc', 'ifsccode', 'rtgscode'], demo: 'SBIN0001234', demo2: 'SBIN0001234' },
                { key: 'branch_name', label: 'Bank Branch', cls: 'bg-bbranch', max: 150, ph: 'optional', aliases: ['branchname', 'bankbranch', 'bankbranchname'], demo: 'Demo Bank Branch', demo2: '' },
                { key: 'account_type', label: 'Type', cls: 'bg-atype', type: 'select', def: 'Current',
                  opts: [{ v: 'Current', t: 'Current' }, { v: 'Savings', t: 'Savings' }],
                  aliases: ['accounttype', 'type', 'actype'], demo: 'Current', demo2: 'Savings' },
                { key: 'opening_balance', label: 'Opening Bal (₹)', cls: 'bg-open', type: 'number', aliases: ['openingbalance', 'openingbal', 'opening', 'balance', 'obal'], demo: 100000, demo2: 25000 },
                { key: 'opening_balance_date', label: 'Opening As Of', cls: 'bg-obdate', type: 'date', aliases: ['openingbalancedate', 'openingdate', 'asof', 'balancedate'], demo: '2026-04-01', demo2: '2026-04-01' },
                { key: 'branch', label: 'Our Branch', cls: 'bg-branch', ph: 'branch name', aliases: ['branch', 'ourbranch', 'office', 'location', 'companybranch'], demo: '', demo2: '' }
            ]
        });
        <?php endif; ?>
    </script>
</body>
</html>
