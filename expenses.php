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
$current_page = 'expenses';

// agent cant access
if ($role === 'agent') { header("Location: dashboard.php"); exit(); }
$canAddExp = in_array($role, ['admin', 'manager'], true); // manager entries wait for an admin

// bank leg follows approval: only an approved expense with an account debits the bank
function expSyncLeg(mysqli $conn, int $eid, int $uid): void {
    $st = $conn->prepare("SELECT bank_account_id, expense_date, description, reference_number, amount, approval_status FROM expenses WHERE id = ?");
    $st->bind_param("i", $eid); $st->execute();
    $e = $st->get_result()->fetch_assoc(); $st->close();
    $st = $conn->prepare("SELECT DISTINCT bank_account_id FROM bank_transactions WHERE expense_id = ? AND category = 'expense'");
    $st->bind_param("i", $eid); $st->execute();
    $banks = array_map('intval', array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'bank_account_id')); $st->close();
    $st = $conn->prepare("DELETE FROM bank_transactions WHERE expense_id = ? AND category = 'expense'");
    $st->bind_param("i", $eid); $st->execute(); $st->close();
    if ($e && $e['approval_status'] === 'approved' && $e['bank_account_id']) {
        $bankId = (int)$e['bank_account_id']; $desc = "Expense: {$e['description']}"; $amt = (float)$e['amount'];
        $st = $conn->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, description, reference_number, transaction_type, amount, running_balance, expense_id, category, created_by) VALUES (?, ?, ?, ?, 'debit', ?, 0, ?, 'expense', ?)");
        $st->bind_param("isssdii", $bankId, $e['expense_date'], $desc, $e['reference_number'], $amt, $eid, $uid);
        if (!$st->execute()) { $st->close(); throw new Exception('bank post failed'); }
        $st->close();
        $banks[] = $bankId;
    }
    foreach (array_unique($banks) as $b) recalcBankBalance($conn, $b);
}

// payee + gst (form + import) -> [err, payee, gst]; gst is inside amount
function expExtra(array $r, float $amt): array {
    $payee = trim((string)($r['payee_name'] ?? '')) ?: null;
    $gstRaw = trim((string)($r['gst_amount'] ?? ''));
    $gst = $gstRaw === '' ? 0.0 : round((float)$gstRaw, 2);
    if ($payee !== null && mb_strlen($payee) > 150) return ['Payee name max 150 chars', null, 0.0];
    if ($gstRaw !== '' && !is_numeric($gstRaw)) return ['GST amount must be a number', null, 0.0];
    if ($gst < 0 || $gst > $amt) return ['GST amount must be between 0 and the expense amount', null, 0.0];
    return [null, $payee, $gst];
}

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getCategories':
                $stmt = $conn->prepare("SELECT id, name, description FROM expense_categories WHERE is_active=1 ORDER BY name");
                $stmt->execute();
                $res = $stmt->get_result();
                $cats = [];
                while ($row = $res->fetch_assoc()) {
                    $cats[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'description' => $row['description']];
                }
                $stmt->close(); $conn->close();
                echo json_encode(['success' => true, 'data' => $cats]);
                exit();

            case 'getAllCategories':
                // for manage modal - include inactive
                $stmt = $conn->prepare("SELECT id, name, description, is_active FROM expense_categories ORDER BY name");
                $stmt->execute();
                $res = $stmt->get_result();
                $cats = [];
                while ($row = $res->fetch_assoc()) {
                    $cats[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'description' => $row['description'], 'is_active' => (int)$row['is_active']];
                }
                $stmt->close(); $conn->close();
                echo json_encode(['success' => true, 'data' => $cats]);
                exit();

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

            case 'getExpenses':
                $stmt = $conn->prepare("
                    SELECT e.*, ec.name as category_name, b.name as branch_name, ba.bank_name, au.name as approved_by_name
                    FROM expenses e
                    JOIN expense_categories ec ON e.expense_category_id=ec.id
                    JOIN branches b ON e.branch_id=b.id
                    LEFT JOIN bank_accounts ba ON e.bank_account_id=ba.id
                    LEFT JOIN users au ON e.approved_by=au.id
                    ORDER BY e.expense_date DESC, e.id DESC
                ");
                $stmt->execute();
                $res = $stmt->get_result();
                $expenses = [];
                while ($row = $res->fetch_assoc()) {
                    $expenses[] = [
                        'id' => (int)$row['id'],
                        'expense_category_id' => (int)$row['expense_category_id'],
                        'category_name' => $row['category_name'],
                        'branch_id' => (int)$row['branch_id'],
                        'branch_name' => $row['branch_name'],
                        'expense_date' => $row['expense_date'],
                        'description' => $row['description'],
                        'amount' => (float)$row['amount'],
                        'payment_mode' => $row['payment_mode'],
                        'bank_account_id' => $row['bank_account_id'] ? (int)$row['bank_account_id'] : null,
                        'bank_name' => $row['bank_name'],
                        'reference_number' => $row['reference_number'],
                        'receipt_file_path' => $row['receipt_file_path'],
                        'payee_name' => $row['payee_name'] ?? null,
                        'gst_amount' => (float)($row['gst_amount'] ?? 0),
                        'approval_status' => $row['approval_status'] ?? 'approved',
                        'approval_note' => $row['approval_note'] ?? null,
                        'approved_by' => $row['approved_by'] ? (int)$row['approved_by'] : null,
                        'approved_by_name' => $row['approved_by_name'],
                        'created_by' => (int)$row['created_by'],
                        'created_at' => $row['created_at'],
                        'updated_at' => $row['updated_at']
                    ];
                }
                $stmt->close(); $conn->close();
                echo json_encode(['success' => true, 'data' => $expenses]);
                exit();

            case 'addExpense':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if (!$canAddExp) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $catId = (int)($_POST['expense_category_id'] ?? 0);
                $branchId = (int)($_POST['branch_id'] ?? 0);
                $expDate = trim($_POST['expense_date'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $amt = (float)($_POST['amount'] ?? 0);
                $payMode = trim($_POST['payment_mode'] ?? '') ?: null;
                $bankId = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
                $refNo = trim($_POST['reference_number'] ?? '') ?: null;

                // validate required
                if ($catId <= 0) { echo json_encode(['success' => false, 'message' => 'Category is required']); exit(); }
                if ($branchId <= 0) { echo json_encode(['success' => false, 'message' => 'Branch is required']); exit(); }
                if (empty($expDate)) { echo json_encode(['success' => false, 'message' => 'Date is required']); exit(); }
                if (empty($desc) || strlen($desc) > 500) { echo json_encode(['success' => false, 'message' => 'Description is required (max 500 chars)']); exit(); }
                if ($amt <= 0) { echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']); exit(); }
                [$xErr, $payee, $gst] = expExtra($_POST, $amt);
                if ($xErr) { echo json_encode(['success' => false, 'message' => $xErr]); exit(); }
                // admin entries are approved by the creator, anyone else waits for an admin
                $appr = $role === 'admin' ? 'approved' : 'pending';
                $apprBy = $role === 'admin' ? (int)$user_id : null;

                // handle receipt upload
                $receiptPath = null;
                if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
                    $uploadRes = uploadReceipt($_FILES['receipt']);
                    if (!$uploadRes['success']) {
                        echo json_encode(['success' => false, 'message' => $uploadRes['message']]);
                        exit();
                    }
                    $receiptPath = $uploadRes['filename'];
                }

                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("INSERT INTO expenses (expense_category_id, branch_id, expense_date, description, payee_name, amount, gst_amount, payment_mode, bank_account_id, reference_number, receipt_file_path, approval_status, approved_by, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iisssddsisssii", $catId, $branchId, $expDate, $desc, $payee, $amt, $gst, $payMode, $bankId, $refNo, $receiptPath, $appr, $apprBy, $user_id);
                    if (!$stmt->execute()) { throw new Exception('insert failed'); }
                    $insertId = $stmt->insert_id;
                    $stmt->close();

                    // bank leg only once approved (admin entries are approved on save)
                    expSyncLeg($conn, (int)$insertId, (int)$user_id);

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add expense']);
                    exit();
                }

                logActivity($user_id, $username, 'Expense Created', "Added expense #$insertId: $desc ($amt" . ($gst > 0 ? ", GST $gst" : '') . ")" . ($payee ? ", payee $payee" : '') . ", $appr");
                $conn->close();
                echo json_encode(['success' => true, 'message' => $appr === 'approved' ? 'Expense added successfully' : 'Expense sent for admin approval', 'data' => ['id' => $insertId]]);
                exit();

            case 'bulkAddExpenses':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if (!$canAddExp) { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows) || count($rows) === 0) { echo json_encode(['success'=>false,'message'=>'No expenses to add']); exit(); }
                $off = max(0, (int)($_POST['row_offset'] ?? 0)); // client posts in chunks - keep row numbers global

                // lookups: name -> id (case-insensitive)
                $catMap = []; $res = $conn->query("SELECT id, name FROM expense_categories");
                while ($c = $res->fetch_assoc()) $catMap[strtolower(trim($c['name']))] = (int)$c['id'];
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
                $bAppr = $role === 'admin' ? 'approved' : 'pending'; // same rule as single add
                $bApprBy = $role === 'admin' ? (int)$user_id : null;
                foreach ($rows as $idx => $r) {
                    $line = $off + $idx + 1;
                    $expDate = trim($r['expense_date'] ?? '');
                    $catName = trim($r['category'] ?? '');
                    $branchName = trim($r['branch'] ?? '');
                    $desc = trim($r['description'] ?? '');
                    $amt = (float)($r['amount'] ?? 0);
                    $payMode = trim($r['payment_mode'] ?? '') ?: null;
                    $bankRaw = trim($r['bank_account'] ?? '');
                    $refNo = trim($r['reference_number'] ?? '') ?: null;

                    if ($desc === '' && $catName === '' && $amt == 0) continue; // trailing blank line

                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expDate)) { $errors[] = "Row $line: date must be YYYY-MM-DD"; continue; }
                    if ($catName === '' || !isset($catMap[strtolower($catName)])) { $errors[] = "Row $line: unknown category '$catName'"; continue; }
                    if ($branchName === '' || !isset($branchMap[strtolower($branchName)])) { $errors[] = "Row $line: unknown branch '$branchName'"; continue; }
                    if ($desc === '' || strlen($desc) > 500) { $errors[] = "Row $line: description is required (max 500 chars)"; continue; }
                    if ($amt <= 0) { $errors[] = "Row $line: amount must be greater than 0"; continue; }
                    [$xErr, $payee, $gst] = expExtra($r, $amt);
                    if ($xErr) { $errors[] = "Row $line: $xErr"; continue; }

                    $bankId = null;
                    if ($bankRaw !== '') {
                        $bk = strtolower($bankRaw);
                        if (!isset($bankMap[$bk])) { $errors[] = "Row $line: bank account '$bankRaw' not found"; continue; }
                        if (($bankNameCount[$bk] ?? 0) > 1) { $errors[] = "Row $line: '$bankRaw' matches several accounts — use the account number"; continue; }
                        $bankId = $bankMap[$bk];
                    }

                    $catId = $catMap[strtolower($catName)]; $branchId = $branchMap[strtolower($branchName)];

                    $conn->begin_transaction();
                    try {
                        $stmt = $conn->prepare("INSERT INTO expenses (expense_category_id, branch_id, expense_date, description, payee_name, amount, gst_amount, payment_mode, bank_account_id, reference_number, approval_status, approved_by, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("iisssddsissii", $catId, $branchId, $expDate, $desc, $payee, $amt, $gst, $payMode, $bankId, $refNo, $bAppr, $bApprBy, $user_id);
                        $stmt->execute(); $newId = (int)$stmt->insert_id; $stmt->close();

                        // mirror the single add: bank leg as a debit, approved entries only
                        if ($bankId && $bAppr === 'approved') {
                            $btDesc = "Expense: $desc";
                            $bt = $conn->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, description, reference_number, transaction_type, amount, running_balance, expense_id, category, created_by) VALUES (?, ?, ?, ?, 'debit', ?, 0, ?, 'expense', ?)");
                            $bt->bind_param("isssdii", $bankId, $expDate, $btDesc, $refNo, $amt, $newId, $user_id);
                            $bt->execute(); $bt->close();
                            $touched[$bankId] = 1;
                        }

                        $conn->commit();
                        $inserted++;
                    } catch (Exception $ex) {
                        $conn->rollback();
                        $errors[] = "Row $line: failed to add '$desc'";
                    }
                }

                foreach (array_keys($touched) as $bid) recalcBankBalance($conn, $bid); // one pass per account, not per row

                logActivity($user_id, $username, 'Bulk Expense Add', "Added $inserted expenses, " . count($errors) . " errors");
                $conn->close();
                $msg = "$inserted expense(s) added." . (count($touched) ? " " . count($touched) . " bank account(s) recalculated." : "");
                echo json_encode(['success'=>true, 'message'=>$msg, 'inserted'=>$inserted, 'errors'=>$errors]);
                exit();

            case 'updateExpense':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if (!$canAddExp) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $eid = (int)($_POST['id'] ?? 0);
                $catId = (int)($_POST['expense_category_id'] ?? 0);
                $branchId = (int)($_POST['branch_id'] ?? 0);
                $expDate = trim($_POST['expense_date'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $amt = (float)($_POST['amount'] ?? 0);
                $payMode = trim($_POST['payment_mode'] ?? '') ?: null;
                $bankId = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
                $refNo = trim($_POST['reference_number'] ?? '') ?: null;

                if ($eid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid expense ID']); exit(); }
                if ($catId <= 0) { echo json_encode(['success' => false, 'message' => 'Category is required']); exit(); }
                if ($branchId <= 0) { echo json_encode(['success' => false, 'message' => 'Branch is required']); exit(); }
                if (empty($expDate)) { echo json_encode(['success' => false, 'message' => 'Date is required']); exit(); }
                if (empty($desc) || strlen($desc) > 500) { echo json_encode(['success' => false, 'message' => 'Description is required (max 500 chars)']); exit(); }
                if ($amt <= 0) { echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']); exit(); }
                [$xErr, $payee, $gst] = expExtra($_POST, $amt);
                if ($xErr) { echo json_encode(['success' => false, 'message' => $xErr]); exit(); }

                // get old receipt path + old bank account
                $stmt = $conn->prepare("SELECT receipt_file_path, bank_account_id, created_by, approval_status FROM expenses WHERE id = ?");
                $stmt->bind_param("i", $eid);
                $stmt->execute();
                $oldRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$oldRow) { $conn->close(); echo json_encode(['success' => false, 'message' => 'Expense not found']); exit(); }
                // a manager edits only its own entry while it waits / after a rejection — saving resubmits it
                $resubmit = $role !== 'admin';
                if ($resubmit && ((int)$oldRow['created_by'] !== (int)$user_id || $oldRow['approval_status'] === 'approved')) {
                    $conn->close(); echo json_encode(['success' => false, 'message' => 'Only your own pending or rejected expenses can be edited']); exit();
                }
                $oldReceipt = $oldRow['receipt_file_path'];
                $oldBankId = $oldRow['bank_account_id'] ? (int)$oldRow['bank_account_id'] : null;

                // handle receipt upload replacement
                $receiptPath = $oldReceipt;
                if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
                    $uploadRes = uploadReceipt($_FILES['receipt']);
                    if (!$uploadRes['success']) {
                        echo json_encode(['success' => false, 'message' => $uploadRes['message']]);
                        exit();
                    }
                    // delete old receipt
                    if ($oldReceipt && file_exists(__DIR__ . '/' . $oldReceipt)) {
                        unlink(__DIR__ . '/' . $oldReceipt);
                    }
                    $receiptPath = $uploadRes['filename'];
                }

                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("UPDATE expenses SET expense_category_id=?, branch_id=?, expense_date=?, description=?, payee_name=?, amount=?, gst_amount=?, payment_mode=?, bank_account_id=?, reference_number=?, receipt_file_path=?, updated_by=? WHERE id=?");
                    $stmt->bind_param("iisssddsissii", $catId, $branchId, $expDate, $desc, $payee, $amt, $gst, $payMode, $bankId, $refNo, $receiptPath, $user_id, $eid);
                    if (!$stmt->execute()) { throw new Exception('update failed'); }
                    $stmt->close();

                    if ($resubmit) { // back in the admin's queue
                        $st = $conn->prepare("UPDATE expenses SET approval_status = 'pending', approved_by = NULL, approval_note = NULL WHERE id = ?");
                        $st->bind_param("i", $eid); $st->execute(); $st->close();
                    }
                    // re-post (or drop) the bank leg; old + new account recalculated inside
                    expSyncLeg($conn, $eid, (int)$user_id);

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update expense']);
                    exit();
                }

                logActivity($user_id, $username, 'Expense Updated', "Updated expense #$eid: $desc ($amt" . ($gst > 0 ? ", GST $gst" : '') . ")" . ($payee ? ", payee $payee" : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Expense updated successfully']);
                exit();

            case 'deleteExpense':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $eid = (int)($_POST['id'] ?? 0);
                if ($eid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid expense ID']); exit(); }

                // get info before delete
                $stmt = $conn->prepare("SELECT description, amount, receipt_file_path, bank_account_id FROM expenses WHERE id = ?");
                $stmt->bind_param("i", $eid);
                $stmt->execute();
                $delRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$delRow) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Expense not found']);
                    exit();
                }
                $delBankId = $delRow['bank_account_id'] ? (int)$delRow['bank_account_id'] : null;

                $conn->begin_transaction();
                try {
                    // drop linked bank row first
                    $del = $conn->prepare("DELETE FROM bank_transactions WHERE expense_id = ? AND category = 'expense'");
                    $del->bind_param("i", $eid);
                    $del->execute();
                    $del->close();

                    $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ?");
                    $stmt->bind_param("i", $eid);
                    if (!$stmt->execute()) { throw new Exception('delete failed'); }
                    $stmt->close();

                    if ($delBankId) { recalcBankBalance($conn, $delBankId); }

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete expense']);
                    exit();
                }

                // delete receipt file after commit
                if ($delRow['receipt_file_path'] && file_exists(__DIR__ . '/' . $delRow['receipt_file_path'])) {
                    unlink(__DIR__ . '/' . $delRow['receipt_file_path']);
                }

                logActivity($user_id, $username, 'Expense Deleted', "Deleted expense #$eid: {$delRow['description']} ({$delRow['amount']})");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Expense deleted successfully']);
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
                $receiptsToDel = [];
                $banksToRecalc = [];
                try {
                    foreach ($ids as $eid) {
                        // grab receipt + bank before delete
                        $sel = $conn->prepare("SELECT receipt_file_path, bank_account_id FROM expenses WHERE id = ?");
                        $sel->bind_param("i", $eid);
                        $sel->execute();
                        $row = $sel->get_result()->fetch_assoc();
                        $sel->close();
                        if (!$row) continue; // skip missing

                        $bId = $row['bank_account_id'] ? (int)$row['bank_account_id'] : null;

                        // drop linked bank row first
                        $del = $conn->prepare("DELETE FROM bank_transactions WHERE expense_id = ? AND category = 'expense'");
                        $del->bind_param("i", $eid);
                        $del->execute();
                        $del->close();

                        $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ?");
                        $stmt->bind_param("i", $eid);
                        if (!$stmt->execute()) { $stmt->close(); throw new Exception('delete failed'); }
                        $stmt->close();

                        if ($bId) { $banksToRecalc[$bId] = true; }
                        if ($row['receipt_file_path']) { $receiptsToDel[] = $row['receipt_file_path']; }
                        $deleted++;
                    }

                    // recalc each affected bank once
                    foreach (array_keys($banksToRecalc) as $bId) { recalcBankBalance($conn, $bId); }

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete expenses']);
                    exit();
                }

                // del receipt files after commit
                foreach ($receiptsToDel as $rp) {
                    if ($rp && file_exists(__DIR__ . '/' . $rp)) { unlink(__DIR__ . '/' . $rp); }
                }

                logActivity($user_id, $username, 'Bulk delete', "Deleted $deleted expense(s)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => "$deleted record(s) deleted"]);
                exit();

            case 'addCategory':
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
                $catDesc = trim($_POST['description'] ?? '') ?: null;

                if (empty($name) || strlen($name) > 100) {
                    echo json_encode(['success' => false, 'message' => 'Category name is required (max 100 chars)']);
                    exit();
                }

                // check duplicate
                $stmt = $conn->prepare("SELECT id FROM expense_categories WHERE name = ?");
                $stmt->bind_param("s", $name);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Category already exists']);
                    exit();
                }
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO expense_categories (name, description) VALUES (?, ?)");
                $stmt->bind_param("ss", $name, $catDesc);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add category']);
                    exit();
                }
                $insertId = $stmt->insert_id;
                $stmt->close();

                logActivity($user_id, $username, 'Expense Category Created', "Added category: $name");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Category added successfully', 'data' => ['id' => $insertId]]);
                exit();

            case 'updateCategory':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $cid = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $catDesc = trim($_POST['description'] ?? '') ?: null;
                $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

                if ($cid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid category ID']); exit(); }
                if (empty($name) || strlen($name) > 100) {
                    echo json_encode(['success' => false, 'message' => 'Category name is required (max 100 chars)']);
                    exit();
                }

                // check dup (exclude self)
                $stmt = $conn->prepare("SELECT id FROM expense_categories WHERE name = ? AND id != ?");
                $stmt->bind_param("si", $name, $cid);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Category name already exists']);
                    exit();
                }
                $stmt->close();

                $stmt = $conn->prepare("UPDATE expense_categories SET name=?, description=?, is_active=? WHERE id=?");
                $stmt->bind_param("ssii", $name, $catDesc, $isActive, $cid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update category']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Expense Category Updated', "Updated category #$cid: $name");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Category updated successfully']);
                exit();

            case 'deleteCategory':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $cid = (int)($_POST['id'] ?? 0);
                if ($cid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid category ID']); exit(); }

                // check if expenses reference it
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM expenses WHERE expense_category_id = ?");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();

                if ($cnt > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete: $cnt expense(s) use this category"]);
                    exit();
                }

                // get name before delete
                $stmt = $conn->prepare("SELECT name FROM expense_categories WHERE id = ?");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $catRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $catName = $catRow ? $catRow['name'] : 'Unknown';

                $stmt = $conn->prepare("DELETE FROM expense_categories WHERE id = ?");
                $stmt->bind_param("i", $cid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete category']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Expense Category Deleted', "Deleted category: $catName (#$cid)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Category deleted successfully']);
                exit();

            case 'setApproval':
                // approved = counts in totals + debits the bank; rejected / pending = neither
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'Invalid request']); exit(); }
                if ($role !== 'admin') { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
                $eid = (int)($_POST['id'] ?? 0);
                $st = trim($_POST['status'] ?? '');
                if ($eid <= 0 || !in_array($st, ['approved', 'rejected'], true)) { echo json_encode(['success' => false, 'message' => 'Invalid request']); exit(); }
                $stmt = $conn->prepare("SELECT approval_status, description FROM expenses WHERE id = ?");
                $stmt->bind_param("i", $eid); $stmt->execute();
                $cur = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$cur) { $conn->close(); echo json_encode(['success' => false, 'message' => 'Expense not found']); exit(); }
                $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null;
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("UPDATE expenses SET approval_status=?, approved_by=?, approval_note=?, updated_by=? WHERE id=?");
                    $stmt->bind_param("sisii", $st, $user_id, $note, $user_id, $eid);
                    $stmt->execute(); $stmt->close();
                    expSyncLeg($conn, $eid, (int)$user_id);
                    $conn->commit();
                } catch (Throwable $ex) {
                    $conn->rollback(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Could not update the expense']); exit();
                }
                logActivity($user_id, $username, $st === 'approved' ? 'Expense Approved' : 'Expense Rejected', "Expense #$eid: {$cur['description']} ({$cur['approval_status']} -> $st)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Expense ' . $st]);
                exit();

            case 'viewReceipt':
                // receipts are never public — staff login only (agents are bounced above)
                $eid = (int)($_GET['id'] ?? 0);
                $stmt = $conn->prepare("SELECT receipt_file_path FROM expenses WHERE id = ?");
                $stmt->bind_param("i", $eid);
                $stmt->execute();
                $rc = $stmt->get_result()->fetch_assoc();
                $stmt->close(); $conn->close();
                if (!$rc || !$rc['receipt_file_path']) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('File not found'); }
                sendStoredFile($rc['receipt_file_path'], 'receipt-' . $eid . '.' . pathinfo($rc['receipt_file_path'], PATHINFO_EXTENSION));

            case 'getExpense':
                $eid = (int)($_GET['id'] ?? 0);
                $stmt = $conn->prepare("SELECT e.*, ec.name as category_name, b.name as branch_name, ba.bank_name FROM expenses e JOIN expense_categories ec ON e.expense_category_id=ec.id JOIN branches b ON e.branch_id=b.id LEFT JOIN bank_accounts ba ON e.bank_account_id=ba.id WHERE e.id=?");
                $stmt->bind_param("i", $eid);
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
        error_log("expenses.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
        exit();
    }
}

// receipt upload helper
function uploadReceipt($file) {
    $uploadDir = __DIR__ . '/uploads/receipts/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    $maxSize = 2 * 1024 * 1024; // 2MB

    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'Receipt file must be less than 2MB'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'message' => 'Invalid file type. Only images and PDF allowed'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts)) {
        return ['success' => false, 'message' => 'Invalid file extension'];
    }

    $filename = storedName('receipt', $ext);
    $filepath = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => 'uploads/receipts/' . $filename];
    }

    return ['success' => false, 'message' => 'Failed to upload receipt'];
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
    <title>Expense Management - Insurance Management System</title>

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
            $pageIcon    = 'fa-receipt';
            $pageTitle   = 'Expense Management';
            $crumbs      = [['label' => 'Accounting', 'icon' => 'fa-calculator']];
            $pageActions = [];
            if ($canAddExp) {
                $pageActions[] = ['label' => 'Add Expense', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
                if ($isAdmin) $pageActions[] = ['label' => 'Manage Categories', 'icon' => 'fa-tags', 'onclick' => 'openCategoryModal()'];
                $pageActions[] = ['label' => 'Bulk Add / Import', 'icon' => 'fa-file-excel', 'onclick' => 'expensesImport.open()'];
            }
            $pageActions[] = ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(expensesTable,'csv')"];
            $pageActions[] = ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(expensesTable,'pdf')"];
            $pageActions[] = ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(expensesTable,'print')"];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadExpenses()'];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="expensesSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="expensesSearch" placeholder="Search expenses…" autocomplete="off"></div>
                <div class="hf-field"><label for="filterCategory" title="Category"><i class="fas fa-tags"></i></label><select id="filterCategory" class="filter-input searchable">
                    <option value="">All Categories</option>
                </select></div>
                <div class="hf-field"><label for="filterBranch" title="Branch"><i class="fas fa-code-branch"></i></label><select id="filterBranch" class="filter-input searchable">
                    <option value="">All Branches</option>
                </select></div>
                <div class="hf-field"><label for="filterPayMode" title="Payment mode"><i class="fas fa-credit-card"></i></label><select id="filterPayMode" class="filter-input searchable">
                    <option value="">All Modes</option>
                    <option value="Cash">Cash</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="UPI">UPI</option>
                    <option value="Cheque">Cheque</option>
                </select></div>
                <div class="hf-field"><label for="filterBank" title="Bank account"><i class="fas fa-university"></i></label><select id="filterBank" class="filter-input searchable">
                    <option value="">All Bank Accounts</option>
                </select></div>
                <div class="hf-field"><label for="filterApproval" title="Approval status"><i class="fas fa-clipboard-check"></i></label><select id="filterApproval" class="filter-input searchable">
                    <option value="">All Statuses</option>
                    <option value="approved">Approved</option>
                    <option value="pending">Pending</option>
                    <option value="rejected">Rejected</option>
                </select></div>
                <div class="hf-field hf-range" title="Date range"><label for="filterDateFrom"><i class="fas fa-calendar-days"></i></label><input type="date" id="filterDateFrom" class="filter-input" aria-label="From"><span class="hf-sep"><i class="fas fa-arrow-right-long"></i></span><input type="date" id="filterDateTo" class="filter-input" aria-label="To"></div>
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
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.3"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- table -->
                <div id="tableContainer" style="display: none;">
                    <div id="expensesTable"></div>
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
                <h3 id="viewModalTitle"><i class="fas fa-eye"></i> Expense Details</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>

    <?php if ($canAddExp): ?>
    <!-- Expense Modal -->
    <div class="modal-overlay" id="expenseModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-receipt"></i> Add Expense</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="expenseForm" enctype="multipart/form-data">
                    <input type="hidden" id="expenseId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tags"></i> Category *</label>
                            <select id="expCategory" name="expense_category_id" class="searchable" required>
                                <option value="">Select Category</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-code-branch"></i> Branch *</label>
                            <select id="expBranch" name="branch_id" class="searchable" required>
                                <option value="">Select Branch</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Date *</label>
                            <input type="date" id="expDate" name="expense_date" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Amount *</label>
                            <input type="number" id="expAmount" name="amount" required min="0.01" step="0.01" placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Description *</label>
                        <input type="text" id="expDesc" name="description" required maxlength="500" placeholder="Expense description">
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user-tag"></i> Payee Name</label>
                            <input type="text" id="expPayee" name="payee_name" maxlength="150" placeholder="Paid to (vendor / person)">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> GST Amount (incl. in amount)</label>
                            <input type="number" id="expGst" name="gst_amount" min="0" step="0.01" placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-credit-card"></i> Payment Mode</label>
                            <select id="expPayMode" name="payment_mode" class="searchable">
                                <option value="">Select Mode</option>
                                <option value="Cash">Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="UPI">UPI</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-university"></i> Bank Account</label>
                            <select id="expBankAccount" name="bank_account_id" class="searchable">
                                <option value="">Select Bank Account</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Reference Number</label>
                            <input type="text" id="expRefNo" name="reference_number" maxlength="50" placeholder="Txn/Cheque ref">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-file-upload"></i> Receipt (Image/PDF, max 2MB)</label>
                            <input type="file" id="expReceipt" name="receipt" accept="image/*,.pdf" class="file-input" data-max-mb="2" data-hint="Image or PDF · max 2MB">
                            <small id="currentReceiptInfo"></small>
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

    <!-- Manage Categories Modal -->
    <div class="modal-overlay" id="categoryModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-tags"></i> Manage Expense Categories</h3>
                <button class="close-btn" onclick="closeCategoryModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <!-- Add category form -->
                <form id="addCategoryForm" style="margin-bottom: 20px;">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Name *</label>
                            <input type="text" id="newCatName" name="name" required maxlength="100" placeholder="Category name">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-align-left"></i> Description</label>
                            <input type="text" id="newCatDesc" name="description" maxlength="255" placeholder="Optional description">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="fas fa-plus"></i> Add Category
                    </button>
                </form>

                <hr>

                <!-- Category list -->
                <div id="categoryList" style="margin-top: 15px;">
                    <p>Loading categories...</p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        /** Developed by Rameez Scripts */
        const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        const CAN_ADD = <?php echo $canAddExp ? 'true' : 'false'; ?>;
        const ME = <?php echo (int)$user_id; ?>;
        // manager: own entry, not yet approved
        const canEditExp = r => IS_ADMIN || (CAN_ADD && r && r.created_by === ME && (r.approval_status || 'approved') !== 'approved');
        const rcptUrl = id => '?action=viewReceipt&id=' + parseInt(id, 10); // receipts stream through the page, never a public path
        let expensesTable = null;
        let bulkSel = null;
        let expensesData = [];
        let categoriesData = [];
        let branchesData = [];
        let bankAccountsData = [];
        let isEditMode = false;

        $(document).ready(function() {
            loadDropdowns();
            loadExpenses();

            // one delegated action handler for the whole table
            $('#expensesTable').on('click', '[data-act]', function() {
                var act = $(this).data('act'), id = parseInt($(this).data('id'), 10);
                if (act === 'view') viewExpense(id);
                else if (act === 'print') printVoucher(id);
                else if (act === 'pdf') printVoucher(id, 'pdf');
                else if (act === 'edit' && canEditExp(expensesData.find(function(e) { return e.id === id; }))) editExpense(id);
                else if (act === 'delete' && IS_ADMIN) deleteExpense(id);
                else if ((act === 'approve' || act === 'reject') && IS_ADMIN) setApproval(id, act === 'approve' ? 'approved' : 'rejected');
            });
        });
        var EXP_STATUS_CLS = { approved: 'status-active', pending: 'status-pending', rejected: 'status-cancelled' };

        // load dropdown data
        function loadDropdowns() {
            $.ajax({ url: '?action=getCategories', dataType: 'json', success: function(res) {
                if (res.success) {
                    categoriesData = res.data;
                    populateCategoryDropdowns(res.data);
                }
            }});
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

        function populateCategoryDropdowns(cats) {
            // filter dropdown
            var filterSel = $('#filterCategory');
            filterSel.find('option:not(:first)').remove();
            cats.forEach(function(c) { filterSel.append('<option value="' + c.name + '">' + c.name + '</option>'); });

            // form dropdown
            if (CAN_ADD) {
                var formSel = $('#expCategory');
                formSel.find('option:not(:first)').remove();
                cats.forEach(function(c) { formSel.append('<option value="' + c.id + '">' + c.name + '</option>'); });
                setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
            }
        }

        function populateBranchDropdowns(branches) {
            var filterSel = $('#filterBranch');
            filterSel.find('option:not(:first)').remove();
            branches.forEach(function(b) { filterSel.append('<option value="' + b.name + '">' + b.name + '</option>'); });

            if (CAN_ADD) {
                var formSel = $('#expBranch');
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

            if (CAN_ADD) {
                var sel = $('#expBankAccount');
                sel.find('option:not(:first)').remove();
                banks.forEach(function(b) { sel.append('<option value="' + b.id + '">' + b.bank_name + ' - ' + b.account_number + '</option>'); });
            }
            setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
        }

        function loadExpenses() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();

            $.ajax({
                url: '?action=getExpenses',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        expensesData = res.data;
                        $('#loadingSkeleton').hide();

                        // empty state when no records
                        if (!res.data.length) {
                            $('#tableContainer').hide();
                            $('#emptyState').html(emptyStateHtml({
                                icon: 'fa-receipt',
                                title: 'No expenses yet',
                                text: 'Start tracking your spending by adding the first expense record.',
                                btnText: CAN_ADD ? 'Add Expense' : '',
                                btnId: CAN_ADD ? 'emptyAddBtn' : ''
                            })).show();
                            if (CAN_ADD) $('#emptyAddBtn').off('click').on('click', openAddModal);
                            return;
                        }

                        $('#emptyState').hide();
                        $('#tableContainer').show();
                        setTimeout(function() { initializeDataTable(res.data); }, 100);
                    } else {
                        $('#loadingSkeleton').hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load expenses' });
                    }
                },
                error: function() {
                    $('#loadingSkeleton').hide();
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
                }
            });
        }

        function initializeDataTable(data) {
            if (expensesTable) return rsTbData(expensesTable, data); // build once, swap rows

            var cols = [
                { data:'id', title:'<input type="checkbox" class="bulk-check-all">', orderable:false, searchable:false, className:'bulk-col', render:function(d){ return '<input type="checkbox" class="bulk-check" data-id="'+d+'">'; } },
                { data: 'id', title: 'ID' },
                { data: 'expense_date', title: 'Date' },
                {
                    data: 'category_name', title: 'Category',
                    render: function(d) { return '<span class="status-badge status-active">' + escapeHtml(d) + '</span>'; }
                },
                {
                    data: 'branch_name', title: 'Branch',
                    render: function(d) { return escapeHtml(d || ''); }
                },
                {
                    data: 'description', title: 'Description',
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
                    data: 'approval_status', title: 'Status',
                    render: function(d, type, row) {
                        var st = d || 'approved', lbl = st.charAt(0).toUpperCase() + st.slice(1);
                        if (type !== 'display') return lbl;
                        return '<span class="status-badge ' + EXP_STATUS_CLS[st] + '"' + (row.approval_note ? ' title="' + escapeHtml(row.approval_note) + '"' : '') + '>' + lbl + '</span>';
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
                {
                    data: 'reference_number', title: 'Reference',
                    render: function(d) { return d ? escapeHtml(d) : '<em style="color:#999;">-</em>'; }
                },
                { data: 'payee_name', title: 'Payee', visible: false, render: function(d) { return escapeHtml(d || '-'); } },
                { data: 'gst_amount', title: 'GST', visible: false, render: function(d) { return parseFloat(d || 0).toFixed(2); } },
                { data: 'approved_by_name', title: 'Approved By', visible: false, render: function(d) { return escapeHtml(d || '-'); } },
                {
                    data: 'receipt_file_path', title: 'Receipt',
                    orderable: false,
                    render: function(d, type, row) {
                        if (!d) return '<em style="color:#999;">-</em>';
                        var ext = d.split('.').pop().toLowerCase();
                        var icon = ext === 'pdf' ? 'fa-file-pdf' : 'fa-image';
                        return '<a href="' + rcptUrl(row.id) + '" target="_blank" rel="noopener" title="View Receipt"><i class="fas ' + icon + '" style="color:var(--navy-accent, #0074D9);font-size:16px;"></i></a>';
                    }
                }
            ];

            // action hub - view + print primary, edit/delete in ⋮ more
            cols.push({
                field: '_act', title: 'Actions', headerSort: false, download: false, responsive: 0, resizable: false,
                hozAlign: 'right', headerHozAlign: 'right', minWidth: 140, widthGrow: 0, // 3 icons need room on phones
                formatter: function(cell) {
                    var row = cell.getRow().getData(), html = '<div class="action-hub">';
                    html += '<button class="action-icon view-icon" data-act="view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button>';
                    html += '<button class="action-icon view-icon" data-act="print" data-id="' + row.id + '" title="Print Voucher"><i class="fas fa-print"></i></button>';
                    html += '<div class="more-menu"><button class="more-btn" title="More" aria-label="More"><i class="fas fa-ellipsis-v"></i></button><div class="more-dropdown">' +
                            '<button class="more-item" data-act="pdf" data-id="' + row.id + '"><i class="fas fa-file-pdf"></i> Voucher PDF</button>';
                    if (IS_ADMIN) {
                        var st = row.approval_status || 'approved';
                        html += '<div class="more-divider"></div>';
                        if (st !== 'approved') html += '<button class="more-item" data-act="approve" data-id="' + row.id + '"><i class="fas fa-circle-check"></i> Approve</button>';
                        if (st !== 'rejected') html += '<button class="more-item danger" data-act="reject" data-id="' + row.id + '"><i class="fas fa-circle-xmark"></i> Reject</button>';
                    }
                    if (canEditExp(row)) html += '<div class="more-divider"></div><button class="more-item" data-act="edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> ' + (IS_ADMIN ? 'Edit' : 'Edit & resubmit') + '</button>';
                    if (IS_ADMIN) html += '<button class="more-item danger" data-act="delete" data-id="' + row.id + '"><i class="fas fa-trash"></i> Delete</button>';
                    html += '</div></div>';
                    html += '</div>';
                    return html;
                }
            });

            expensesTable = rsTable('#expensesTable', {
                columns: cols, data: data, title: 'Expenses', file: 'expenses', sort: ['id', 'desc'], bulk: IS_ADMIN, // delete = admin only
                rowClass: function(r) { return parseFloat(r.amount) >= 50000 ? 'dt-row-warning' : ''; }, // big-ticket tint
                info: function() {
                    var f = $('#filterDateFrom').val(), t = $('#filterDateTo').val();
                    return [['Category', $('#filterCategory').val() || 'All'], ['Branch', $('#filterBranch').val() || 'All'],
                        ['Mode', $('#filterPayMode').val() || 'All'], ['Bank', $('#filterBank').val() || 'All'], ['Status', $('#filterApproval').val() || 'All'],
                        ['Date', (f || t) ? (f || '…') + ' to ' + (t || '…') : 'All']];
                }
            });

            // bind filters
            $('#filterCategory, #filterBranch, #filterPayMode, #filterBank, #filterApproval').off('change').on('change', applyFilters);
            $('#filterDateFrom, #filterDateTo').off('change').on('change', applyFilters);
            rsTbSearch(expensesTable, $('#expensesSearch').val());

            if (IS_ADMIN) bulkSel = initBulkSelect({
                table: expensesTable,
                label: 'expenses',
                onDelete: function(ids) {
                    showLoading('Deleting...');
                    $.ajax({
                        url: '?action=bulkDelete', method: 'POST', dataType: 'json',
                        data: { csrf_token: window.CSRF_TOKEN, ids: ids },
                        success: function(res) {
                            hideLoading();
                            if (res.success) { bulkSel.clear(); loadExpenses(); Swal.fire('Deleted', res.message, 'success'); }
                            else { Swal.fire('Error', res.message, 'error'); }
                        },
                        error: function() { hideLoading(); Swal.fire('Error', 'Request failed', 'error'); }
                    });
                }
            });
            applyFilters();
        }

        function applyFilters() {
            if (!expensesTable) return;

            var cat = $('#filterCategory').val();
            var branch = $('#filterBranch').val();
            var payMode = $('#filterPayMode').val();
            var bank = $('#filterBank').val();
            var dateFrom = $('#filterDateFrom').val();
            var dateTo = $('#filterDateTo').val();

            // category = contains, rest exact
            rsTbWhere(expensesTable, 'cat', cat ? function(r) { return String(r.category_name || '').indexOf(cat) > -1; } : null);
            rsTbWhere(expensesTable, 'branch', branch ? function(r) { return (r.branch_name || '') === branch; } : null);
            rsTbWhere(expensesTable, 'mode', payMode ? function(r) { return (r.payment_mode || '') === payMode; } : null);
            var appr = $('#filterApproval').val();
            rsTbWhere(expensesTable, 'appr', appr ? function(r) { return (r.approval_status || 'approved') === appr; } : null);
            rsTbWhere(expensesTable, 'bank', bank ? function(r) { return (r.bank_name || '') === bank; } : null);
            rsTbWhere(expensesTable, 'date', (dateFrom || dateTo) ? function(r) {
                var d = String(r.expense_date || '').slice(0, 10);
                return !(dateFrom && d < dateFrom) && !(dateTo && d > dateTo);
            } : null);
        }

        // header search -> table
        $('#expensesSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(expensesTable, v); },200); });

        function clearFilters() {
            $('#expensesSearch').val('');
            $('.hf-seg .is-on').removeClass('is-on');
            $('#filterCategory').val('');
            $('#filterBranch').val('');
            $('#filterDateFrom').val('');
            $('#filterDateTo').val('');
            $('#filterPayMode').val('');
            $('#filterBank').val('');
            $('#filterApproval').val('');

            ['filterCategory', 'filterBranch', 'filterPayMode', 'filterBank', 'filterApproval'].forEach(function(id) { var el = document.getElementById(id); if (el && el.sdRefresh) el.sdRefresh(); });
            rsTbClear(expensesTable);
        }

        // view detail modal
        function viewExpense(id) {
            var d = expensesData.find(function(e) { return e.id === id; });
            if (!d) return;

            var amtFmt = '₹' + parseFloat(d.amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

            // receipt preview - thumbnail for images, file link for pdf
            var receiptHtml = '<em style="color:#999;">No receipt</em>';
            if (d.receipt_file_path) {
                var ext = d.receipt_file_path.split('.').pop().toLowerCase();
                if (ext === 'pdf') {
                    receiptHtml = '<a href="' + rcptUrl(d.id) + '" target="_blank" rel="noopener"><span class="badge-code"><i class="fas fa-file-pdf"></i> Open receipt PDF</span></a>';
                } else {
                    receiptHtml = '<a href="' + rcptUrl(d.id) + '" target="_blank" rel="noopener"><img src="' +
                        rcptUrl(d.id) + '" alt="Receipt" class="receipt-thumb" width="150"></a>';
                }
            }

            // fk link to bank ledger when bank set
            var bankHtml = d.bank_name ? escapeHtml(d.bank_name) : '<em style="color:#999;">-</em>';
            if (d.bank_account_id) {
                bankHtml = '<a href="bank_transactions.php?bank_account_id=' + d.bank_account_id + '" title="Open bank ledger">' +
                    '<span class="badge-code"><i class="fas fa-university"></i> ' + escapeHtml(d.bank_name || 'Bank Account') + '</span></a>';
            }

            var html = '<table class="view-detail-table">';
            html += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">EXP-' + String(d.id).padStart(4,'0') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-calendar"></i> Date</td><td class="vd-value">' + escapeHtml(d.expense_date) + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-tags"></i> Category</td><td class="vd-value"><span class="status-badge status-active">' + escapeHtml(d.category_name || '-') + '</span></td>';
            html += '<td class="vd-label"><i class="fas fa-code-branch"></i> Branch</td><td class="vd-value">' + escapeHtml(d.branch_name || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-money-bill-wave"></i> Amount</td><td class="vd-value"><strong>' + amtFmt + '</strong></td>';
            html += '<td class="vd-label"><i class="fas fa-credit-card"></i> Payment Mode</td><td class="vd-value">' + escapeHtml(d.payment_mode || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-university"></i> Bank</td><td class="vd-value">' + bankHtml + '</td>';
            html += '<td class="vd-label"><i class="fas fa-hashtag"></i> Reference</td><td class="vd-value">' + escapeHtml(d.reference_number || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-align-left"></i> Description</td><td class="vd-value" colspan="3">' + escapeHtml(d.description || '-') + '</td></tr>';
            var st = d.approval_status || 'approved';
            html += '<tr><td class="vd-label"><i class="fas fa-user-tag"></i> Payee</td><td class="vd-value">' + escapeHtml(d.payee_name || '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-percent"></i> GST (incl.)</td><td class="vd-value">' + '₹' + parseFloat(d.gst_amount || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-clipboard-check"></i> Status</td><td class="vd-value"><span class="status-badge ' + EXP_STATUS_CLS[st] + '">' + escapeHtml(st.charAt(0).toUpperCase() + st.slice(1)) + '</span></td>';
            html += '<td class="vd-label"><i class="fas fa-user-check"></i> ' + (st === 'rejected' ? 'Rejected By' : 'Approved By') + '</td><td class="vd-value">' + escapeHtml(d.approved_by_name || '-') + '</td></tr>';
            if (d.approval_note) html += '<tr><td class="vd-label"><i class="fas fa-comment"></i> Approval Note</td><td class="vd-value" colspan="3">' + escapeHtml(d.approval_note) + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-file-image"></i> Receipt</td><td class="vd-value" colspan="3">' + receiptHtml + '</td></tr>';
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

        // expense voucher -> A4 print / PDF
        function printVoucher(id, kind) {
            $.get('?action=getExpense&id=' + id, function(res) {
                if (!res.success) { Swal.fire('Error', res.message || 'Voucher not found', 'error'); return; }
                var d = res.data, no = 'EXP-' + String(d.id).padStart(4, '0');
                rsReportExport(kind || 'print', {
                    title: 'Expense Voucher', number: no,
                    party: d.payee_name
                        ? { label: 'Paid to', name: d.payee_name, lines: ['For: ' + (d.category_name || 'Expense'), d.branch_name ? 'Branch: ' + d.branch_name : ''].filter(Boolean) }
                        : { label: 'Paid for', name: d.category_name || 'Expense', lines: [d.branch_name ? 'Branch: ' + d.branch_name : ''].filter(Boolean) },
                    info: [['Voucher date', rsPaperDate(d.expense_date)], ['Payment mode', d.payment_mode || '-'], ['Bank', d.bank_name || '-'], ['Reference', d.reference_number || '-'], ['GST (incl.)', rsMoney(d.gst_amount || 0)]],
                    cols: [
                        { title: '#', field: '#', align: 'center' },
                        { title: 'Particulars', get: function(r) { return r.category_name || 'Expense'; }, bold: true, sub: function(r) { return r.description || ''; } },
                        { title: 'Amount', get: function(r) { return rsMoney(r.amount); }, align: 'right' }
                    ],
                    rows: [d],
                    totals: (parseFloat(d.gst_amount) > 0 ? [['Taxable value', rsMoney(d.amount - d.gst_amount)], ['GST', rsMoney(d.gst_amount)]] : []).concat([['TOTAL PAID', rsMoney(d.amount)]]),
                    notesTitle: 'Authorisation', notes: 'Prepared by: ______________________          Approved by: ______________________',
                    footNote: 'Computer-generated voucher.'
                }, no);
            }, 'json');
        }

        <?php if ($canAddExp): ?>
        // approve / reject (admin) — approving posts the bank leg + counts in totals
        function setApproval(id, status) {
            var verb = status === 'approved' ? 'Approve' : 'Reject', ok = status === 'approved';
            Swal.fire({ icon: 'question', title: verb + ' this expense?', text: ok ? 'It counts in totals and its bank entry is posted.' : 'It drops out of totals and any bank entry is removed.',
                input: 'text', inputPlaceholder: ok ? 'Note (optional)' : 'Reason (optional)', inputAttributes: { maxlength: 255 },
                showCancelButton: true, confirmButtonText: verb }).then(function(r) {
                if (!r.isConfirmed) return;
                var done = rsOverlay(ok ? 'Approving…' : 'Rejecting…'), hideLoading = done;
                $.ajax({ url: '?action=setApproval', method: 'POST', dataType: 'json',
                    data: { csrf_token: window.CSRF_TOKEN, id: id, status: status, note: r.value || '' },
                    success: function(res) {
                        hideLoading();
                        if (res.success) { Swal.fire({ icon: 'success', text: res.message, timer: 1500, showConfirmButton: false }); loadExpenses(); }
                        else Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                    },
                    error: function() { hideLoading(); Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
                });
            });
        }

        function openAddModal() {
            isEditMode = false;
            $('#modalTitle').html('<i class="fas fa-receipt"></i> Add Expense');
            $('#expenseForm')[0].reset();
            $('#expenseId').val('');
            $('#currentReceiptInfo').html('');
            $('#expenseModal').addClass('active');
        }

        function editExpense(id) {
            var exp = expensesData.find(function(e) { return e.id === id; });
            if (!exp) return;
            isEditMode = true;
            $('#modalTitle').html('<i class="fas fa-edit"></i> Edit Expense');
            $('#expenseId').val(exp.id);
            $('#expCategory').val(exp.expense_category_id);
            $('#expBranch').val(exp.branch_id);
            $('#expDate').val(exp.expense_date);
            $('#expDesc').val(exp.description);
            $('#expAmount').val(exp.amount);
            $('#expPayMode').val(exp.payment_mode || '');
            $('#expBankAccount').val(exp.bank_account_id || '');
            $('#expRefNo').val(exp.reference_number || '');
            $('#expPayee').val(exp.payee_name || '');
            $('#expGst').val(parseFloat(exp.gst_amount) > 0 ? exp.gst_amount : '');
            $('#expReceipt').val('');

            if (exp.receipt_file_path) {
                $('#currentReceiptInfo').html('<a href="' + rcptUrl(exp.id) + '" target="_blank" rel="noopener">Current receipt</a> (upload new to replace)');
            } else {
                $('#currentReceiptInfo').html('');
            }

            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
            $('#expenseModal').addClass('active');
        }

        function closeModal() {
            $('#expenseModal').removeClass('active');
            $('#expenseForm')[0].reset();
            $('#currentReceiptInfo').html('');
        }

        document.getElementById('expenseModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // form submit
        $('#expenseForm').on('submit', function(e) {
            e.preventDefault();

            var formData = new FormData(this);
            formData.append('csrf_token', window.CSRF_TOKEN);
            var action = isEditMode ? 'updateExpense' : 'addExpense';

            showLoading('Saving expense...');

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
                        setTimeout(loadExpenses, 100);
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

        function deleteExpense(eid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Expense?',
                text: 'This action cannot be undone',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) return;

                var fd = new FormData();
                fd.append('id', eid);
                fd.append('csrf_token', window.CSRF_TOKEN);

                $.ajax({
                    url: '?action=deleteExpense',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                            setTimeout(loadExpenses, 100);
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

        // --- Category Management ---
        function openCategoryModal() {
            $('#categoryModal').addClass('active');
            loadCategoryList();
        }

        function closeCategoryModal() {
            $('#categoryModal').removeClass('active');
            $('#addCategoryForm')[0].reset();
        }

        document.getElementById('categoryModal').addEventListener('click', function(e) {
            if (e.target === this) closeCategoryModal();
        });

        function loadCategoryList() {
            $('#categoryList').html('<p>Loading...</p>');

            $.ajax({
                url: '?action=getAllCategories',
                dataType: 'json',
                success: function(res) {
                    if (!res.success || !res.data.length) {
                        $('#categoryList').html('<p>No categories found.</p>');
                        return;
                    }

                    var html = '<table class="display" style="width:100%;font-size:14px;">';
                    html += '<thead><tr><th>ID</th><th>Name</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead><tbody>';

                    res.data.forEach(function(c) {
                        var statusBadge = c.is_active
                            ? '<span class="status-badge status-active">Active</span>'
                            : '<span class="status-badge status-inactive">Inactive</span>';

                        html += '<tr id="catRow_' + c.id + '">';
                        html += '<td>' + c.id + '</td>';
                        html += '<td><span class="cat-name-' + c.id + '">' + $('<span>').text(c.name).html() + '</span></td>';
                        html += '<td><span class="cat-desc-' + c.id + '">' + (c.description ? $('<span>').text(c.description).html() : '-') + '</span></td>';
                        html += '<td>' + statusBadge + '</td>';
                        html += '<td>';
                        html += '<button class="action-icon edit-icon" onclick="editCategory(' + c.id + ', \'' + $('<span>').text(c.name).html().replace(/'/g, "\\'") + '\', \'' + (c.description ? $('<span>').text(c.description).html().replace(/'/g, "\\'") : '') + '\', ' + c.is_active + ')" title="Edit"><i class="fas fa-edit"></i></button> ';
                        html += '<button class="action-icon delete-icon" onclick="deleteCategory(' + c.id + ')" title="Delete"><i class="fas fa-trash"></i></button>';
                        html += '</td></tr>';
                    });

                    html += '</tbody></table>';
                    $('#categoryList').html(html);
                }
            });
        }

        // add category
        $('#addCategoryForm').on('submit', function(e) {
            e.preventDefault();

            var fd = new FormData(this);
            fd.append('csrf_token', window.CSRF_TOKEN);

            $.ajax({
                url: '?action=addCategory',
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        Swal.fire({ icon: 'success', text: res.message, timer: 1500, showConfirmButton: false });
                        $('#addCategoryForm')[0].reset();
                        loadCategoryList();
                        loadDropdowns(); // refresh category dropdowns
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                    }
                },
                error: function() {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' });
                }
            });
        });

        function editCategory(cid, name, desc, isActive) {
            Swal.fire({
                title: 'Edit Category',
                html:
                    '<input id="swalCatName" class="swal2-input" placeholder="Name" value="' + name + '" maxlength="100">' +
                    '<input id="swalCatDesc" class="swal2-input" placeholder="Description" value="' + desc + '" maxlength="255">' +
                    '<div style="margin-top:10px;text-align:left;padding:0 20px;"><label><input type="checkbox" id="swalCatActive" ' + (isActive ? 'checked' : '') + '> Active</label></div>',
                showCancelButton: true,
                confirmButtonText: 'Update',
                preConfirm: function() {
                    var n = document.getElementById('swalCatName').value.trim();
                    if (!n) { Swal.showValidationMessage('Name is required'); return false; }
                    return {
                        name: n,
                        description: document.getElementById('swalCatDesc').value.trim(),
                        is_active: document.getElementById('swalCatActive').checked ? 1 : 0
                    };
                }
            }).then(function(result) {
                if (!result.isConfirmed) return;

                var fd = new FormData();
                fd.append('id', cid);
                fd.append('name', result.value.name);
                fd.append('description', result.value.description);
                fd.append('is_active', result.value.is_active);
                fd.append('csrf_token', window.CSRF_TOKEN);

                $.ajax({
                    url: '?action=updateCategory',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 1500, showConfirmButton: false });
                            loadCategoryList();
                            loadDropdowns();
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                        }
                    }
                });
            });
        }

        function deleteCategory(cid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Category?',
                text: 'Only possible if no expenses reference it',
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
                    url: '?action=deleteCategory',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 1500, showConfirmButton: false });
                            loadCategoryList();
                            loadDropdowns();
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                        }
                    }
                });
            });
        }

        // bulk add / excel import — engine lives in sidebar.php
        var expensesImport = RSImport({
            key: 'expenses', title: 'Bulk Add / Import Expenses', url: '?action=bulkAddExpenses',
            hint: 'Add many expenses at once — upload an Excel/CSV file or type the rows. <strong>Date, category, branch, description and amount</strong> are required. Naming a <strong>bank account</strong> posts a matching <strong>debit</strong> to that account\'s ledger, exactly like the single Add Expense form; each touched account is recalculated once at the end. Use the account number (or <code>Bank Name - AccountNumber</code>) — a plain bank name works only when one account carries it.',
            onDone: function() { setTimeout(loadExpenses, 100); },
            fields: [
                { key: 'expense_date', label: 'Date *', cls: 'bg-date', type: 'date', req: true, aliases: ['expensedate', 'date', 'txndate', 'transactiondate', 'billdate'], demo: '2026-07-17', demo2: '2026-07-18' },
                { key: 'category', label: 'Category *', cls: 'bg-cat', type: 'select', req: true,
                  opts: function() { return [{ v: '', t: '— Select —' }].concat((categoriesData || []).map(function(c) { return { v: c.name, t: c.name }; })); },
                  aliases: ['category', 'expensecategory', 'categoryname', 'head', 'expensehead'], demo: '', demo2: '' },
                { key: 'branch', label: 'Branch *', cls: 'bg-branch', type: 'select', req: true,
                  opts: function() { return [{ v: '', t: '— Select —' }].concat((branchesData || []).map(function(b) { return { v: b.name, t: b.name }; })); },
                  aliases: ['branch', 'branchname', 'office', 'location'], demo: '', demo2: '' },
                { key: 'description', label: 'Description *', cls: 'bg-desc', req: true, max: 500, ph: 'What was paid for', aliases: ['description', 'desc', 'particulars', 'narration', 'details', 'remarks'], demo: 'Demo office rent', demo2: 'Demo stationery' },
                { key: 'amount', label: 'Amount (₹) *', cls: 'bg-amt', type: 'number', req: true, aliases: ['amount', 'amt', 'value', 'total', 'debit'], demo: 15000, demo2: 2500 },
                { key: 'payee_name', label: 'Payee', cls: 'bg-payee', max: 150, ph: 'optional', aliases: ['payeename', 'payee', 'paidto', 'vendor', 'supplier'], demo: 'Demo Vendor One', demo2: '' },
                { key: 'gst_amount', label: 'GST (₹)', cls: 'bg-gst', type: 'number', ph: 'incl. in amount', aliases: ['gstamount', 'gst', 'tax', 'taxamount'], demo: 2288.14, demo2: 0 },
                { key: 'payment_mode', label: 'Mode', cls: 'bg-mode', type: 'select',
                  opts: [{ v: '', t: '— None —' }, { v: 'Cash', t: 'Cash' }, { v: 'Bank Transfer', t: 'Bank Transfer' }, { v: 'UPI', t: 'UPI' }, { v: 'Cheque', t: 'Cheque' }],
                  aliases: ['paymentmode', 'mode', 'paymode', 'paymenttype'], demo: 'Bank Transfer', demo2: 'Cash' },
                { key: 'bank_account', label: 'Bank Account', cls: 'bg-bank', type: 'select',
                  opts: function() { return [{ v: '', t: '— None —' }].concat((bankAccountsData || []).map(function(a) { return { v: a.account_number, t: a.bank_name + ' - ' + a.account_number }; })); },
                  aliases: ['bankaccount', 'bank', 'account', 'accountnumber', 'paidfrom', 'bankname'], demo: '', demo2: '' },
                { key: 'reference_number', label: 'Reference', cls: 'bg-ref', max: 50, ph: 'optional', aliases: ['referencenumber', 'reference', 'refno', 'chequeno', 'utr', 'voucherno'], demo: 'REF-001', demo2: '' }
            ]
        });
        <?php endif; ?>
    </script>
</body>
</html>
