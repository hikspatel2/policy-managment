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
$current_page = 'other_income';

// agent cant access
if ($role === 'agent') { header("Location: dashboard.php"); exit(); }

// bank-leg helpers oiLegId / oiDropLeg / oiPostLeg live in config.php (endorsement fees post here too)
// remarks: optional, max 500
function oiRemarks($v): array {
    $v = trim((string)$v);
    if ($v === '') return [null, null];
    return mb_strlen($v) > 500 ? ['Remarks max 500 chars', null] : [null, $v];
}

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getBranches':
                $stmt = $conn->prepare("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name");
                $stmt->execute();
                $res = $stmt->get_result();
                $branches = [];
                while ($row = $res->fetch_assoc()) {
                    $branches[] = ['id' => (int)$row['id'], 'name' => $row['name']];
                }
                $stmt->close(); $conn->close();
                echo json_encode(['success' => true, 'data' => $branches]);
                exit();

            case 'getBankAccounts':
                $stmt = $conn->prepare("SELECT id, bank_name, account_number FROM bank_accounts WHERE is_active=1 ORDER BY bank_name");
                $stmt->execute();
                $res = $stmt->get_result();
                $banks = [];
                while ($row = $res->fetch_assoc()) {
                    $banks[] = ['id' => (int)$row['id'], 'bank_name' => $row['bank_name'], 'account_number' => $row['account_number']];
                }
                $stmt->close(); $conn->close();
                echo json_encode(['success' => true, 'data' => $banks]);
                exit();

            case 'getIncome':
                $stmt = $conn->prepare("
                    SELECT oi.*, b.name as branch_name, ba.bank_name
                    FROM other_income oi
                    JOIN branches b ON oi.branch_id=b.id
                    LEFT JOIN bank_accounts ba ON oi.bank_account_id=ba.id
                    ORDER BY oi.income_date DESC, oi.id DESC
                ");
                $stmt->execute();
                $res = $stmt->get_result();
                $income = [];
                while ($row = $res->fetch_assoc()) {
                    $income[] = [
                        'id' => (int)$row['id'],
                        'branch_id' => (int)$row['branch_id'],
                        'branch_name' => $row['branch_name'],
                        'income_date' => $row['income_date'],
                        'source' => $row['source'],
                        'amount' => (float)$row['amount'],
                        'payment_mode' => $row['payment_mode'],
                        'bank_account_id' => $row['bank_account_id'] ? (int)$row['bank_account_id'] : null,
                        'bank_name' => $row['bank_name'],
                        'reference_number' => $row['reference_number'],
                        'remarks' => $row['remarks'] ?? null,
                        'created_by' => (int)$row['created_by'],
                        'created_at' => $row['created_at'],
                        'updated_at' => $row['updated_at']
                    ];
                }
                $stmt->close(); $conn->close();
                echo json_encode(['success' => true, 'data' => $income]);
                exit();

            case 'addIncome':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $branchId = (int)($_POST['branch_id'] ?? 0);
                $incDate = trim($_POST['income_date'] ?? '');
                $source = trim($_POST['source'] ?? '');
                $amt = (float)($_POST['amount'] ?? 0);
                $payMode = trim($_POST['payment_mode'] ?? '') ?: null;
                $bankId = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
                $refNo = trim($_POST['reference_number'] ?? '') ?: null;
                [$rmErr, $remarks] = oiRemarks($_POST['remarks'] ?? '');
                if ($rmErr) { echo json_encode(['success' => false, 'message' => $rmErr]); exit(); }

                // validate
                if ($branchId <= 0) { echo json_encode(['success' => false, 'message' => 'Branch is required']); exit(); }
                if (empty($incDate)) { echo json_encode(['success' => false, 'message' => 'Date is required']); exit(); }
                if (empty($source) || strlen($source) > 200) { echo json_encode(['success' => false, 'message' => 'Source is required (max 200 chars)']); exit(); }
                if ($amt <= 0) { echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']); exit(); }

                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("INSERT INTO other_income (branch_id, income_date, source, remarks, amount, payment_mode, bank_account_id, reference_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("isssdsisi", $branchId, $incDate, $source, $remarks, $amt, $payMode, $bankId, $refNo, $user_id);
                    if (!$stmt->execute()) { throw new Exception('insert failed'); }
                    $insertId = $stmt->insert_id;
                    $stmt->close();

                    // post to bank ledger if account set; leg carries other_income_id
                    if ($bankId) {
                        oiPostLeg($conn, $insertId, $bankId, $incDate, $source, $refNo, $amt, $user_id);
                        recalcBankBalance($conn, $bankId);
                    }

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add income']);
                    exit();
                }

                logActivity($user_id, $username, 'Other Income Created', "Added income #$insertId: $source ($amt)" . ($remarks ? " - remarks: " . mb_substr($remarks, 0, 80) : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Income added successfully', 'data' => ['id' => $insertId]]);
                exit();

            case 'bulkAddIncome':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows) || count($rows) === 0) { echo json_encode(['success'=>false,'message'=>'No income entries to add']); exit(); }
                $off = max(0, (int)($_POST['row_offset'] ?? 0)); // client posts in chunks - keep row numbers global

                $branchMap = []; $res = $conn->query("SELECT id, name FROM branches");
                while ($b = $res->fetch_assoc()) $branchMap[strtolower(trim($b['name']))] = (int)$b['id'];

                // bank: account number and "bank - number" always resolve; bare bank name only when unique
                $bankMap = []; $bankNameCount = [];
                $res = $conn->query("SELECT id, bank_name, account_number FROM bank_accounts WHERE is_active = 1");
                while ($a = $res->fetch_assoc()) {
                    $aid = (int)$a['id']; $bn = strtolower(trim($a['bank_name'])); $an = strtolower(trim($a['account_number']));
                    if ($an !== '') $bankMap[$an] = $aid;
                    $bankMap[$bn . ' - ' . $an] = $aid;
                    $bankNameCount[$bn] = ($bankNameCount[$bn] ?? 0) + 1;
                    if ($bankNameCount[$bn] === 1) $bankMap[$bn] = $aid;
                }

                $inserted = 0; $errors = []; $touched = []; // touched bank accounts -> recalc once at the end
                foreach ($rows as $idx => $r) {
                    $line = $off + $idx + 1;
                    $incDate = trim($r['income_date'] ?? '');
                    $branchName = trim($r['branch'] ?? '');
                    $source = trim($r['source'] ?? '');
                    $amt = (float)($r['amount'] ?? 0);
                    $payMode = trim($r['payment_mode'] ?? '') ?: null;
                    $bankRaw = trim($r['bank_account'] ?? '');
                    $refNo = trim($r['reference_number'] ?? '') ?: null;
                    [$rmErr, $remarks] = oiRemarks($r['remarks'] ?? '');

                    if ($source === '' && $branchName === '' && $amt == 0) continue; // trailing blank line
                    if ($rmErr) { $errors[] = "Row $line: $rmErr"; continue; }

                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $incDate)) { $errors[] = "Row $line: date must be YYYY-MM-DD"; continue; }
                    if ($branchName === '' || !isset($branchMap[strtolower($branchName)])) { $errors[] = "Row $line: unknown branch '$branchName'"; continue; }
                    if ($source === '' || strlen($source) > 200) { $errors[] = "Row $line: source is required (max 200 chars)"; continue; }
                    if ($amt <= 0) { $errors[] = "Row $line: amount must be greater than 0"; continue; }

                    $bankId = null;
                    if ($bankRaw !== '') {
                        $bk = strtolower($bankRaw);
                        if (!isset($bankMap[$bk])) { $errors[] = "Row $line: bank account '$bankRaw' not found"; continue; }
                        if (($bankNameCount[$bk] ?? 0) > 1) { $errors[] = "Row $line: '$bankRaw' matches several accounts — use the account number"; continue; }
                        $bankId = $bankMap[$bk];
                    }

                    $branchId = $branchMap[strtolower($branchName)];

                    $conn->begin_transaction();
                    try {
                        $stmt = $conn->prepare("INSERT INTO other_income (branch_id, income_date, source, remarks, amount, payment_mode, bank_account_id, reference_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("isssdsisi", $branchId, $incDate, $source, $remarks, $amt, $payMode, $bankId, $refNo, $user_id);
                        $stmt->execute(); $newId = (int)$stmt->insert_id; $stmt->close();

                        // bank leg as a credit, same as single add
                        if ($bankId) {
                            oiPostLeg($conn, $newId, $bankId, $incDate, $source, $refNo, $amt, $user_id);
                            $touched[$bankId] = 1;
                        }

                        $conn->commit();
                        $inserted++;
                    } catch (Exception $ex) {
                        $conn->rollback();
                        $errors[] = "Row $line: failed to add '$source'";
                    }
                }

                foreach (array_keys($touched) as $bid) recalcBankBalance($conn, $bid); // one pass per account, not per row

                logActivity($user_id, $username, 'Bulk Income Add', "Added $inserted income entries, " . count($errors) . " errors");
                $conn->close();
                $msg = "$inserted income entr(y/ies) added." . (count($touched) ? " " . count($touched) . " bank account(s) recalculated." : "");
                echo json_encode(['success'=>true, 'message'=>$msg, 'inserted'=>$inserted, 'errors'=>$errors]);
                exit();

            case 'updateIncome':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $oid = (int)($_POST['id'] ?? 0);
                $branchId = (int)($_POST['branch_id'] ?? 0);
                $incDate = trim($_POST['income_date'] ?? '');
                $source = trim($_POST['source'] ?? '');
                $amt = (float)($_POST['amount'] ?? 0);
                $payMode = trim($_POST['payment_mode'] ?? '') ?: null;
                $bankId = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
                $refNo = trim($_POST['reference_number'] ?? '') ?: null;
                [$rmErr, $remarks] = oiRemarks($_POST['remarks'] ?? '');
                if ($rmErr) { echo json_encode(['success' => false, 'message' => $rmErr]); exit(); }

                if ($oid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid income ID']); exit(); }
                if ($branchId <= 0) { echo json_encode(['success' => false, 'message' => 'Branch is required']); exit(); }
                if (empty($incDate)) { echo json_encode(['success' => false, 'message' => 'Date is required']); exit(); }
                if (empty($source) || strlen($source) > 200) { echo json_encode(['success' => false, 'message' => 'Source is required (max 200 chars)']); exit(); }
                if ($amt <= 0) { echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']); exit(); }

                // get old bank account
                $stmt = $conn->prepare("SELECT bank_account_id FROM other_income WHERE id = ?");
                $stmt->bind_param("i", $oid);
                $stmt->execute();
                $oldRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$oldRow) { $conn->close(); echo json_encode(['success' => false, 'message' => 'Income record not found']); exit(); }
                $oldBankId = $oldRow['bank_account_id'] ? (int)$oldRow['bank_account_id'] : null;

                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("UPDATE other_income SET branch_id=?, income_date=?, source=?, remarks=?, amount=?, payment_mode=?, bank_account_id=?, reference_number=?, updated_by=? WHERE id=?");
                    $stmt->bind_param("isssdsisii", $branchId, $incDate, $source, $remarks, $amt, $payMode, $bankId, $refNo, $user_id, $oid);
                    if (!$stmt->execute()) { throw new Exception('update failed'); }
                    $stmt->close();

                    // bank leg: update in place (keeps reconcile tick unless amount/account moved), else drop / post
                    $legId = $bankId ? oiLegId($conn, $oid) : 0;
                    if ($legId) {
                        $btDesc = "Other Income: $source [OI#$oid]";
                        $bt = $conn->prepare("UPDATE bank_transactions SET is_reconciled = IF(amount <> ? OR bank_account_id <> ?, 0, is_reconciled), reconciled_at = IF(is_reconciled = 1, reconciled_at, NULL), bank_account_id=?, other_income_id=?, transaction_date=?, description=?, reference_number=?, amount=?, updated_by=? WHERE id=?");
                        $bt->bind_param("diiisssdii", $amt, $bankId, $bankId, $oid, $incDate, $btDesc, $refNo, $amt, $user_id, $legId);
                        if (!$bt->execute()) { $bt->close(); throw new Exception('bank update failed'); }
                        $bt->close();
                    } else {
                        oiDropLeg($conn, $oid);
                        if ($bankId) oiPostLeg($conn, $oid, $bankId, $incDate, $source, $refNo, $amt, $user_id);
                    }

                    // recalc old + new account (bank may have changed)
                    if ($oldBankId) { recalcBankBalance($conn, $oldBankId); }
                    if ($bankId && $bankId !== $oldBankId) { recalcBankBalance($conn, $bankId); }

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update income']);
                    exit();
                }

                logActivity($user_id, $username, 'Other Income Updated', "Updated income #$oid: $source ($amt)" . ($remarks ? " - remarks: " . mb_substr($remarks, 0, 80) : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Income updated successfully']);
                exit();

            case 'deleteIncome':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $oid = (int)($_POST['id'] ?? 0);
                if ($oid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid income ID']); exit(); }

                // get info before delete
                $stmt = $conn->prepare("SELECT source, amount, bank_account_id FROM other_income WHERE id = ?");
                $stmt->bind_param("i", $oid);
                $stmt->execute();
                $delRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$delRow) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Income record not found']);
                    exit();
                }
                $delBankId = $delRow['bank_account_id'] ? (int)$delRow['bank_account_id'] : null;

                $conn->begin_transaction();
                try {
                    // drop linked bank row first (other_income_id, then legacy marker)
                    oiDropLeg($conn, $oid);

                    $stmt = $conn->prepare("DELETE FROM other_income WHERE id = ?");
                    $stmt->bind_param("i", $oid);
                    if (!$stmt->execute()) { throw new Exception('delete failed'); }
                    $stmt->close();

                    if ($delBankId) { recalcBankBalance($conn, $delBankId); }

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete income']);
                    exit();
                }

                logActivity($user_id, $username, 'Other Income Deleted', "Deleted income #$oid: {$delRow['source']} ({$delRow['amount']})");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Income deleted successfully']);
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

                $ids = $_POST['ids'] ?? [];
                if (!is_array($ids)) { $ids = [$ids]; }
                $ids = array_values(array_filter(array_map('intval', $ids), function($v){ return $v > 0; }));
                if (!$ids) { echo json_encode(['success' => false, 'message' => 'No records selected']); exit(); }

                $conn->begin_transaction();
                $deleted = 0;
                $banksToRecalc = [];
                try {
                    foreach ($ids as $oid) {
                        // grab bank before delete
                        $sel = $conn->prepare("SELECT bank_account_id FROM other_income WHERE id = ?");
                        $sel->bind_param("i", $oid);
                        $sel->execute();
                        $row = $sel->get_result()->fetch_assoc();
                        $sel->close();
                        if (!$row) continue; // skip missing

                        $bId = $row['bank_account_id'] ? (int)$row['bank_account_id'] : null;

                        // drop linked bank row first (other_income_id, then legacy marker)
                        oiDropLeg($conn, $oid);

                        $stmt = $conn->prepare("DELETE FROM other_income WHERE id = ?");
                        $stmt->bind_param("i", $oid);
                        if (!$stmt->execute()) { $stmt->close(); throw new Exception('delete failed'); }
                        $stmt->close();

                        if ($bId) { $banksToRecalc[$bId] = true; }
                        $deleted++;
                    }

                    // recalc each affected bank once
                    foreach (array_keys($banksToRecalc) as $bId) { recalcBankBalance($conn, $bId); }

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete income records']);
                    exit();
                }

                logActivity($user_id, $username, 'Bulk delete', "Deleted $deleted income record(s)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => "$deleted record(s) deleted"]);
                exit();

            case 'getIncomeRecord':
                $iid = (int)($_GET['id'] ?? 0);
                $stmt = $conn->prepare("SELECT oi.*, b.name as branch_name, ba.bank_name FROM other_income oi JOIN branches b ON oi.branch_id=b.id LEFT JOIN bank_accounts ba ON oi.bank_account_id=ba.id WHERE oi.id=?");
                $stmt->bind_param("i", $iid);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res->num_rows) {
                    $row = $res->fetch_assoc();
                    echo json_encode(['success'=>true,'data'=>$row]);
                } else {
                    echo json_encode(['success'=>false,'message'=>'Not found']);
                }
                $stmt->close(); $conn->close(); exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("other_income.php error: " . $e->getMessage());
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
    <title>Other Income - Insurance Management System</title>

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

        <div class="main-content">
            <?php
            $pageIcon    = 'fa-hand-holding-usd';
            $pageTitle   = 'Other Income';
            $crumbs      = [['label' => 'Accounting', 'icon' => 'fa-calculator']];
            $pageActions = [];
            if ($isAdmin) {
                $pageActions[] = ['label' => 'Add Income', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
                $pageActions[] = ['label' => 'Bulk Add / Import', 'icon' => 'fa-file-excel', 'onclick' => 'incomeImport.open()'];
            }
            $pageActions[] = ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(incomeTable,'csv')"];
            $pageActions[] = ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(incomeTable,'pdf')"];
            $pageActions[] = ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(incomeTable,'print')"];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadIncome()'];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="incomeSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="incomeSearch" placeholder="Search income…" autocomplete="off"></div>
                <div class="hf-field"><label for="filterBranch" title="Branch"><i class="fas fa-code-branch"></i></label><select id="filterBranch" class="filter-input searchable">
                    <option value="">All Branches</option>
                </select></div>
                <div class="hf-field"><label for="filterBank" title="Bank Account"><i class="fas fa-university"></i></label><select id="filterBank" class="filter-input searchable">
                    <option value="">All Bank Accounts</option>
                </select></div>
                <div class="hf-field hf-range" title="Date range"><label for="filterDateFrom"><i class="fas fa-calendar-days"></i></label><input type="date" id="filterDateFrom" aria-label="From"><span class="hf-sep"><i class="fas fa-arrow-right-long"></i></span><input type="date" id="filterDateTo" aria-label="To"></div>
                <div class="hf-seg" role="group" aria-label="Quick range"><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',0,this)">Today</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',6,this)">7 days</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',29,this)">30 days</button></div>
                <button type="button" class="btn btn-icon" onclick="clearFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>

            <div class="data-section">
                <!-- Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.3"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.3"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- table -->
                <div id="tableContainer" style="display: none;">
                    <div id="incomeTable"></div>
                </div>

                <!-- empty state -->
                <div id="emptyState" style="display: none;"></div>
            </div>
        </div>
    </div>

    <!-- View Detail Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="viewModalTitle"><i class="fas fa-eye"></i> Income Details</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- Income Modal -->
    <div class="modal-overlay" id="incomeModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-hand-holding-usd"></i> Add Income</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="incomeForm">
                    <input type="hidden" id="incomeId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-code-branch"></i> Branch *</label>
                            <select id="incBranch" name="branch_id" class="searchable" required>
                                <option value="">Select Branch</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Date *</label>
                            <input type="date" id="incDate" name="income_date" required>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Source *</label>
                            <input type="text" id="incSource" name="source" required maxlength="200" placeholder="Income source">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Amount *</label>
                            <input type="number" id="incAmount" name="amount" required min="0.01" step="0.01" placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-credit-card"></i> Payment Mode</label>
                            <select id="incPayMode" name="payment_mode" class="searchable">
                                <option value="">Select Mode</option>
                                <option value="Cash">Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="UPI">UPI</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-university"></i> Bank Account</label>
                            <select id="incBankAccount" name="bank_account_id" class="searchable">
                                <option value="">Select Bank Account</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Reference Number</label>
                        <input type="text" id="incRefNo" name="reference_number" maxlength="50" placeholder="Txn/Cheque ref">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-comment-dots"></i> Remarks</label>
                        <textarea id="incRemarks" name="remarks" rows="2" maxlength="500" placeholder="Optional notes"></textarea>
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
        let incomeTable = null;
        let bulkSel = null;
        let incomeData = [];
        let branchesData = [];
        let bankAccountsData = [];
        let isEditMode = false;

        $(document).ready(function() {
            loadDropdowns();
            loadIncome();

            // one delegated action handler for the whole table
            $('#incomeTable').on('click', '[data-act]', function() {
                var act = $(this).data('act'), id = parseInt($(this).data('id'), 10);
                if (act === 'view') viewIncome(id);
                else if (act === 'print') printReceipt(id);
                else if (act === 'pdf') printReceipt(id, 'pdf');
                else if (act === 'edit' && IS_ADMIN) editIncome(id);
                else if (act === 'delete' && IS_ADMIN) deleteIncome(id);
            });
        });

        function loadDropdowns() {
            $.ajax({ url: '?action=getBranches', dataType: 'json', success: function(res) {
                if (res.success) {
                    branchesData = res.data;
                    populateBranchDropdowns(res.data);
                }
            }});
            $.ajax({ url: '?action=getBankAccounts', dataType: 'json', success: function(res) {
                if (res.success) {
                    bankAccountsData = res.data;
                    populateBankDropdown(res.data);
                }
            }});
        }

        function populateBranchDropdowns(branches) {
            var filterSel = $('#filterBranch');
            filterSel.find('option:not(:first)').remove();
            branches.forEach(function(b) { filterSel.append('<option value="' + b.name + '">' + b.name + '</option>'); });

            if (IS_ADMIN) {
                var formSel = $('#incBranch');
                formSel.find('option:not(:first)').remove();
                branches.forEach(function(b) { formSel.append('<option value="' + b.id + '">' + b.name + '</option>'); });
                setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
            }
        }

        function populateBankDropdown(banks) {
            // filter dropdown - matches bank_name shown in table
            var filterSel = $('#filterBank');
            filterSel.find('option:not(:first)').remove();
            banks.forEach(function(b) { filterSel.append('<option value="' + b.bank_name + '">' + b.bank_name + ' - ' + b.account_number + '</option>'); });

            if (IS_ADMIN) {
                var sel = $('#incBankAccount');
                sel.find('option:not(:first)').remove();
                banks.forEach(function(b) { sel.append('<option value="' + b.id + '">' + b.bank_name + ' - ' + b.account_number + '</option>'); });
            }
            setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
        }

        function loadIncome() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();

            $.ajax({
                url: '?action=getIncome',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        incomeData = res.data;
                        $('#loadingSkeleton').hide();

                        // empty state when no records
                        if (!res.data.length) {
                            $('#tableContainer').hide();
                            $('#emptyState').html(emptyStateHtml({
                                icon: 'fa-hand-holding-usd',
                                title: 'No income records yet',
                                text: 'Record miscellaneous income to keep your books complete.',
                                btnText: IS_ADMIN ? 'Add Income' : '',
                                btnId: IS_ADMIN ? 'emptyAddBtn' : ''
                            })).show();
                            if (IS_ADMIN) $('#emptyAddBtn').off('click').on('click', openAddModal);
                            return;
                        }

                        $('#emptyState').hide();
                        $('#tableContainer').show();
                        setTimeout(function() { initializeDataTable(res.data); }, 100);
                    } else {
                        $('#loadingSkeleton').hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load income' });
                    }
                },
                error: function() {
                    $('#loadingSkeleton').hide();
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
                }
            });
        }

        function initializeDataTable(data) {
            if (incomeTable) return rsTbData(incomeTable, data); // build once, swap rows

            var cols = [
                { data:'id', title:'<input type="checkbox" class="bulk-check-all">', orderable:false, searchable:false, className:'bulk-col', render:function(d){ return '<input type="checkbox" class="bulk-check" data-id="'+d+'">'; } },
                { data: 'id', title: 'ID' },
                { data: 'income_date', title: 'Date' },
                {
                    data: 'branch_name', title: 'Branch',
                    render: function(d) { return escapeHtml(d || ''); }
                },
                {
                    data: 'source', title: 'Source',
                    render: function(d) {
                        var esc = escapeHtml(d || '');
                        return '<span title="' + esc + '">' + (esc.length > 40 ? esc.substring(0, 40) + '...' : esc) + '</span>';
                    }
                },
                {
                    data: 'amount', title: 'Amount',
                    render: function(d) {
                        return '<span class="wallet-amount">\u20B9' + parseFloat(d).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '</span>';
                    }
                },
                {
                    data: 'payment_mode', title: 'Mode',
                    render: function(d) { return d ? escapeHtml(d) : '<em style="color:#999;">-</em>'; }
                },
                {
                    data: 'bank_name', title: 'Bank',
                    render: function(d) { return d ? escapeHtml(d) : '<em style="color:#999;">-</em>'; }
                },
                { data: 'remarks', title: 'Remarks', visible: false, render: function(d) { return escapeHtml(d || '-'); } },
                {
                    data: 'reference_number', title: 'Reference',
                    render: function(d) { return d ? escapeHtml(d) : '<em style="color:#999;">-</em>'; }
                }
            ];

            // action hub - view + print primary, edit/delete in ⋮ more
            cols.push({
                field: '_act', title: 'Actions', headerSort: false, download: false, responsive: 0, resizable: false,
                hozAlign: 'right', headerHozAlign: 'right', minWidth: 140, widthGrow: 0, // 3 icons need room on phones
                formatter: function(cell) {
                    var row = cell.getRow().getData(), html = '<div class="action-hub">';
                    html += '<button class="action-icon view-icon" data-act="view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button>';
                    html += '<button class="action-icon view-icon" data-act="print" data-id="' + row.id + '" title="Print Receipt"><i class="fas fa-print"></i></button>';
                    html += '<div class="more-menu"><button class="more-btn" title="More" aria-label="More"><i class="fas fa-ellipsis-v"></i></button><div class="more-dropdown">' +
                            '<button class="more-item" data-act="pdf" data-id="' + row.id + '"><i class="fas fa-file-pdf"></i> Receipt PDF</button>';
                    if (IS_ADMIN) {
                        html += '<div class="more-divider"></div>' +
                                '<button class="more-item" data-act="edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> Edit</button>' +
                                '<button class="more-item danger" data-act="delete" data-id="' + row.id + '"><i class="fas fa-trash"></i> Delete</button>';
                    }
                    html += '</div></div>';
                    html += '</div>';
                    return html;
                }
            });

            incomeTable = rsTable('#incomeTable', {
                columns: cols, data: data, title: 'Other Income', file: 'other_income', sort: ['id', 'desc'], bulk: IS_ADMIN, // delete = admin only
                info: function() {
                    var f = $('#filterDateFrom').val(), t = $('#filterDateTo').val();
                    return [['Branch', $('#filterBranch').val() || 'All'], ['Bank', $('#filterBank').val() || 'All'],
                        ['Date', (f || t) ? (f || '…') + ' to ' + (t || '…') : 'All']];
                }
            });

            // bind filters
            $('#filterBranch, #filterBank').off('change').on('change', applyFilters);
            $('#filterDateFrom, #filterDateTo').off('change').on('change', applyFilters);
            rsTbSearch(incomeTable, $('#incomeSearch').val()); // keep head search

            if (IS_ADMIN) bulkSel = initBulkSelect({
                table: incomeTable,
                label: 'income records',
                onDelete: function(ids) {
                    showLoading('Deleting...');
                    $.ajax({
                        url: '?action=bulkDelete', method: 'POST', dataType: 'json',
                        data: { csrf_token: window.CSRF_TOKEN, ids: ids },
                        success: function(res) {
                            hideLoading();
                            if (res.success) { bulkSel.clear(); loadIncome(); Swal.fire('Deleted', res.message, 'success'); }
                            else { Swal.fire('Error', res.message, 'error'); }
                        },
                        error: function() { hideLoading(); Swal.fire('Error', 'Request failed', 'error'); }
                    });
                }
            });
            applyFilters();
        }

        function applyFilters() {
            if (!incomeTable) return;

            var branch = $('#filterBranch').val();
            var bank = $('#filterBank').val();
            var dateFrom = $('#filterDateFrom').val();
            var dateTo = $('#filterDateTo').val();

            rsTbWhere(incomeTable, 'branch', branch ? function(r) { return (r.branch_name || '') === branch; } : null);
            rsTbWhere(incomeTable, 'bank', bank ? function(r) { return (r.bank_name || '') === bank; } : null);
            rsTbWhere(incomeTable, 'date', (dateFrom || dateTo) ? function(r) {
                var d = String(r.income_date || '').slice(0, 10);
                return !(dateFrom && d < dateFrom) && !(dateTo && d > dateTo);
            } : null);
        }

        function clearFilters() {
            $('#filterBranch').val('');
            $('#filterBank').val('');
            $('#filterDateFrom').val('');
            $('#filterDateTo').val('');
            $('#incomeSearch').val('');
            ['filterBranch', 'filterBank'].forEach(function(id) { var el = document.getElementById(id); if (el && el.sdSetVal) el.sdSetVal(''); });
            $('.hf-seg button').removeClass('is-on');

            rsTbClear(incomeTable);
        }

        // header search -> table
        $('#incomeSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(incomeTable, v); },200); });

        // view detail modal
        function viewIncome(id) {
            var d = incomeData.find(function(r) { return r.id === id; });
            if (!d) return;

            var amtFmt = '₹' + parseFloat(d.amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

            // fk link to bank ledger when bank set
            var bankHtml = d.bank_name ? escapeHtml(d.bank_name) : '<em style="color:#999;">-</em>';
            if (d.bank_account_id) {
                bankHtml = '<a href="bank_transactions.php?bank_account_id=' + d.bank_account_id + '" title="Open bank ledger">' +
                    '<span class="badge-code"><i class="fas fa-university"></i> ' + escapeHtml(d.bank_name || 'Bank Account') + '</span></a>';
            }

            var html = '<table class="view-detail-table">';
            html += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">INC-' + String(d.id).padStart(4,'0') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-calendar"></i> Date</td><td class="vd-value">' + escapeHtml(d.income_date) + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-code-branch"></i> Branch</td><td class="vd-value">' + escapeHtml(d.branch_name || '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-money-bill-wave"></i> Amount</td><td class="vd-value"><strong>' + amtFmt + '</strong></td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-tag"></i> Source</td><td class="vd-value" colspan="3">' + escapeHtml(d.source || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-credit-card"></i> Payment Mode</td><td class="vd-value">' + escapeHtml(d.payment_mode || '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-hashtag"></i> Reference</td><td class="vd-value">' + escapeHtml(d.reference_number || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-university"></i> Bank</td><td class="vd-value" colspan="3">' + bankHtml + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-comment-dots"></i> Remarks</td><td class="vd-value" colspan="3">' + escapeHtml(d.remarks || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-clock"></i> Created</td><td class="vd-value">' + escapeHtml(d.created_at || '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-clock-rotate-left"></i> Updated</td><td class="vd-value">' + escapeHtml(d.updated_at || '-') + '</td></tr>';
            html += '</table>';

            document.getElementById('viewModalBody').innerHTML = html;
            document.getElementById('viewModal').classList.add('active');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
        }

        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) closeViewModal();
        });

        // income receipt -> A4 print / PDF
        function printReceipt(id, kind) {
            $.get('?action=getIncomeRecord&id=' + id, function(res) {
                if (!res.success) { Swal.fire('Error', res.message || 'Receipt not found', 'error'); return; }
                var d = res.data, no = 'INC-' + String(d.id).padStart(4, '0');
                rsReportExport(kind || 'print', {
                    title: 'Income Receipt', number: no,
                    party: { label: 'Received from', name: d.source || 'Other income', lines: [d.branch_name ? 'Branch: ' + d.branch_name : '', d.remarks ? 'Remarks: ' + d.remarks : ''].filter(Boolean) },
                    info: [['Receipt date', rsPaperDate(d.income_date)], ['Payment mode', d.payment_mode || '-'], ['Bank', d.bank_name || '-'], ['Reference', d.reference_number || '-']],
                    cols: [
                        { title: '#', field: '#', align: 'center' },
                        { title: 'Particulars', get: function(r) { return r.source || 'Other income'; }, bold: true },
                        { title: 'Amount', get: function(r) { return rsMoney(r.amount); }, align: 'right' }
                    ],
                    rows: [d],
                    totals: [['TOTAL RECEIVED', rsMoney(d.amount)]],
                    notesTitle: 'Authorisation', notes: 'Prepared by: ______________________          Received by: ______________________',
                    footNote: 'Computer-generated receipt.'
                }, no);
            }, 'json');
        }

        <?php if ($isAdmin): ?>
        function openAddModal() {
            isEditMode = false;
            $('#modalTitle').html('<i class="fas fa-hand-holding-usd"></i> Add Income');
            $('#incomeForm')[0].reset();
            $('#incomeId').val('');
            $('#incomeModal').addClass('active');
        }

        function editIncome(id) {
            var inc = incomeData.find(function(r) { return r.id === id; });
            if (!inc) return;
            isEditMode = true;
            $('#modalTitle').html('<i class="fas fa-edit"></i> Edit Income');
            $('#incomeId').val(inc.id);
            $('#incBranch').val(inc.branch_id);
            $('#incDate').val(inc.income_date);
            $('#incSource').val(inc.source);
            $('#incAmount').val(inc.amount);
            $('#incPayMode').val(inc.payment_mode || '');
            $('#incBankAccount').val(inc.bank_account_id || '');
            $('#incRefNo').val(inc.reference_number || '');
            $('#incRemarks').val(inc.remarks || '');
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
            $('#incomeModal').addClass('active');
        }

        function closeModal() {
            $('#incomeModal').removeClass('active');
            $('#incomeForm')[0].reset();
        }

        document.getElementById('incomeModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // form submit
        $('#incomeForm').on('submit', function(e) {
            e.preventDefault();

            var formData = new FormData(this);
            formData.append('csrf_token', window.CSRF_TOKEN);
            var action = isEditMode ? 'updateIncome' : 'addIncome';

            showLoading('Saving income...');

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
                        setTimeout(loadIncome, 100);
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

        function deleteIncome(oid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Income Record?',
                text: 'This action cannot be undone',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) return;

                var fd = new FormData();
                fd.append('id', oid);
                fd.append('csrf_token', window.CSRF_TOKEN);

                $.ajax({
                    url: '?action=deleteIncome',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                            setTimeout(loadIncome, 100);
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
        var incomeImport = RSImport({
            key: 'other_income', title: 'Bulk Add / Import Other Income', url: '?action=bulkAddIncome',
            hint: 'Add many income entries at once — upload an Excel/CSV file or type the rows. <strong>Date, branch, source and amount</strong> are required. Naming a <strong>bank account</strong> posts a matching <strong>credit</strong> to that account\'s ledger, exactly like the single Add Income form; each touched account is recalculated once at the end. Use the account number (or <code>Bank Name - AccountNumber</code>) — a plain bank name works only when one account carries it.',
            onDone: function() { setTimeout(loadIncome, 100); },
            fields: [
                { key: 'income_date', label: 'Date *', cls: 'bg-date', type: 'date', req: true, aliases: ['incomedate', 'date', 'txndate', 'transactiondate', 'receiptdate'], demo: '2026-07-17', demo2: '2026-07-18' },
                { key: 'branch', label: 'Branch *', cls: 'bg-branch', type: 'select', req: true,
                  opts: function() { return [{ v: '', t: '— Select —' }].concat((branchesData || []).map(function(b) { return { v: b.name, t: b.name }; })); },
                  aliases: ['branch', 'branchname', 'office', 'location'], demo: '', demo2: '' },
                { key: 'source', label: 'Source *', cls: 'bg-src', req: true, max: 200, ph: 'Where it came from', aliases: ['source', 'incomesource', 'description', 'desc', 'particulars', 'narration', 'details'], demo: 'Demo consultancy fee', demo2: 'Demo scrap sale' },
                { key: 'amount', label: 'Amount (₹) *', cls: 'bg-amt', type: 'number', req: true, aliases: ['amount', 'amt', 'value', 'total', 'credit'], demo: 12000, demo2: 3500 },
                { key: 'payment_mode', label: 'Mode', cls: 'bg-mode', type: 'select',
                  opts: [{ v: '', t: '— None —' }, { v: 'Cash', t: 'Cash' }, { v: 'Bank Transfer', t: 'Bank Transfer' }, { v: 'UPI', t: 'UPI' }, { v: 'Cheque', t: 'Cheque' }],
                  aliases: ['paymentmode', 'mode', 'paymode', 'paymenttype'], demo: 'Bank Transfer', demo2: 'Cash' },
                { key: 'bank_account', label: 'Bank Account', cls: 'bg-bank', type: 'select',
                  opts: function() { return [{ v: '', t: '— None —' }].concat((bankAccountsData || []).map(function(a) { return { v: a.account_number, t: a.bank_name + ' - ' + a.account_number }; })); },
                  aliases: ['bankaccount', 'bank', 'account', 'accountnumber', 'receivedin', 'bankname'], demo: '', demo2: '' },
                { key: 'reference_number', label: 'Reference', cls: 'bg-ref', max: 50, ph: 'optional', aliases: ['referencenumber', 'reference', 'refno', 'chequeno', 'utr', 'voucherno'], demo: 'REF-101', demo2: '' },
                { key: 'remarks', label: 'Remarks', cls: 'bg-remarks', max: 500, ph: 'optional', aliases: ['remarks', 'remark', 'notes', 'note', 'comments'], demo: 'Demo remark', demo2: '' }
            ]
        });
        <?php endif; ?>
    </script>
</body>
</html>
