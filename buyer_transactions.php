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
$current_page = 'buyer_transactions';

// agent cant access
if ($role === 'agent') { header("Location: dashboard.php"); exit(); }

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getBuyers':
                $stmt = $conn->prepare("SELECT id, company_name, company_code, tds_rate FROM buyers WHERE is_active = 1 ORDER BY company_name");
                $stmt->execute();
                $res = $stmt->get_result();
                $buyers = [];
                while ($row = $res->fetch_assoc()) {
                    $buyers[] = [
                        'id' => (int)$row['id'],
                        'company_name' => $row['company_name'],
                        'company_code' => $row['company_code'],
                        'tds_rate' => (float)$row['tds_rate']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $buyers]);
                exit();

            case 'getTransactions':
                $buyerFilter = isset($_GET['buyer_id']) ? (int)$_GET['buyer_id'] : 0;

                $sql = "SELECT bt.*, b.company_name,
                        (SELECT bank_name FROM bank_accounts WHERE id = bt.bank_account_id) as linked_bank_name
                        FROM buyer_transactions bt
                        JOIN buyers b ON bt.buyer_id = b.id";
                if ($buyerFilter > 0) {
                    $sql .= " WHERE bt.buyer_id = ?";
                }
                $sql .= " ORDER BY bt.transaction_date DESC, bt.id DESC";

                $stmt = $conn->prepare($sql);
                if ($buyerFilter > 0) {
                    $stmt->bind_param("i", $buyerFilter);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $txns = [];
                while ($row = $res->fetch_assoc()) {
                    $txns[] = [
                        'id' => (int)$row['id'],
                        'buyer_id' => (int)$row['buyer_id'],
                        'company_name' => $row['company_name'],
                        'transaction_date' => $row['transaction_date'],
                        'description' => $row['description'],
                        'transaction_type' => $row['transaction_type'],
                        'credit_amount' => (float)$row['credit_amount'],
                        'debit_amount' => (float)$row['debit_amount'],
                        'tds_amount' => (float)$row['tds_amount'],
                        'gst_amount' => (float)$row['gst_amount'],
                        'invoice_number' => $row['invoice_number'],
                        'running_balance' => (float)$row['running_balance'],
                        'policy_entry_id' => $row['policy_entry_id'] ? (int)$row['policy_entry_id'] : null,
                        'payment_mode' => $row['payment_mode'],
                        'reference_number' => $row['reference_number'],
                        'bank_account_id' => $row['bank_account_id'] ? (int)$row['bank_account_id'] : null,
                        'bank_txn_id' => $row['bank_txn_id'] ? (int)$row['bank_txn_id'] : null,
                        'is_linked_mirror' => (int)$row['is_linked_mirror'],
                        'linked_bank_name' => $row['linked_bank_name'],
                        'created_by' => (int)$row['created_by'],
                        'created_at' => $row['created_at']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $txns]);
                exit();

            case 'addTransaction':
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

                $buyerId = (int)($_POST['buyer_id'] ?? 0);
                $txnDate = trim($_POST['transaction_date'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $txnType = trim($_POST['transaction_type'] ?? '');
                $creditAmt = (float)($_POST['credit_amount'] ?? 0);
                $debitAmt = (float)($_POST['debit_amount'] ?? 0);
                $policyId = !empty($_POST['policy_entry_id']) ? (int)$_POST['policy_entry_id'] : null;
                $payMode = trim($_POST['payment_mode'] ?? '');
                $refNo = trim($_POST['reference_number'] ?? '');
                $bankAccId = (int)($_POST['bank_account_id'] ?? 0);
                $tds = round((float)($_POST['tds_amount'] ?? 0), 2);
                $gst = round((float)($_POST['gst_amount'] ?? 0), 2);
                $invNo = trim($_POST['invoice_number'] ?? '') ?: null;

                // validate
                if ($buyerId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Buyer is required']);
                    exit();
                }
                if (empty($txnDate)) {
                    echo json_encode(['success' => false, 'message' => 'Transaction date is required']);
                    exit();
                }
                if (empty($desc) || strlen($desc) > 500) {
                    echo json_encode(['success' => false, 'message' => 'Description is required (max 500 chars)']);
                    exit();
                }
                if (!in_array($txnType, ['commission_receivable', 'payment_received', 'payment_made', 'adjustment'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid transaction type']);
                    exit();
                }
                if ($creditAmt <= 0 && $debitAmt <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Credit or debit amount required']);
                    exit();
                }

                // normalize sides by type (buyer ledger): receivable/payment_made = credit-side, payment_received = debit-side
                if ($txnType === 'commission_receivable' || $txnType === 'payment_made') { $debitAmt = 0; }
                elseif ($txnType === 'payment_received') { $creditAmt = 0; }
                if ($e = buyerTaxError($tds, $gst, $invNo, $debitAmt, $txnType)) {
                    echo json_encode(['success' => false, 'message' => $e]);
                    exit();
                }

                $payModeVal = !empty($payMode) ? $payMode : null;
                $refNoVal = !empty($refNo) ? $refNo : null;

                $conn->begin_transaction();
                try {
                    // running_balance set by recalc; insert 0 placeholder
                    $zero = 0.00;
                    $stmt = $conn->prepare("INSERT INTO buyer_transactions (buyer_id, transaction_date, description, transaction_type, credit_amount, debit_amount, tds_amount, gst_amount, running_balance, policy_entry_id, payment_mode, reference_number, invoice_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("isssdddddisssi", $buyerId, $txnDate, $desc, $txnType, $creditAmt, $debitAmt, $tds, $gst, $zero, $policyId, $payModeVal, $refNoVal, $invNo, $user_id);
                    $stmt->execute();
                    $insertId = $stmt->insert_id;
                    $stmt->close();

                    // bank leg for real cash payments (payment_received/made)
                    $dir = buyerBankDir($txnType);
                    if ($bankAccId > 0 && $dir !== null) {
                        $amt = $creditAmt + $debitAmt - $tds; // bank = net of TDS
                        $bankTxnId = insertBankMirror($conn, $bankAccId, 'buyer', $buyerId, $insertId, $txnDate, $dir, $amt, bankLegDesc($desc, $tds), $refNoVal, $user_id);
                        $lnk = $conn->prepare("UPDATE buyer_transactions SET bank_account_id=?, bank_txn_id=? WHERE id=?");
                        $lnk->bind_param("iii", $bankAccId, $bankTxnId, $insertId);
                        $lnk->execute(); $lnk->close();
                        recalcBankBalance($conn, $bankAccId);
                    }

                    // recalc rewrites running_balance + wallet/outstanding
                    recalcBuyerBalances($conn, $buyerId);

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add transaction']);
                    exit();
                }

                logActivity($user_id, $username, 'Buyer Txn Created', "Added $txnType txn #$insertId, buyer: $buyerId, cr: $creditAmt, dr: $debitAmt" . ($tds > 0 ? ", tds: $tds" : '') . ($gst > 0 ? ", gst: $gst" : '') . ($invNo ? ", inv: $invNo" : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Transaction added successfully', 'data' => ['id' => $insertId]]);
                exit();

            case 'bulkAddBuyerTxns':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows) || count($rows) === 0) { echo json_encode(['success'=>false,'message'=>'No transactions to add']); exit(); }
                $off = max(0, (int)($_POST['row_offset'] ?? 0)); // client posts in chunks - keep row numbers global

                // buyer by company code, then by company name (name only when unique)
                $buyerMap = []; $nameCount = [];
                $res = $conn->query("SELECT id, company_code, company_name FROM buyers");
                while ($b = $res->fetch_assoc()) {
                    $bid = (int)$b['id'];
                    $buyerMap[strtolower(trim($b['company_code']))] = $bid;
                    $nk = strtolower(trim($b['company_name']));
                    $nameCount[$nk] = ($nameCount[$nk] ?? 0) + 1;
                    if ($nameCount[$nk] === 1 && !isset($buyerMap[$nk])) $buyerMap[$nk] = $bid;
                }

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

                $inserted = 0; $errors = []; $touchedBuyers = []; $touchedBanks = [];
                foreach ($rows as $idx => $r) {
                    $line = $off + $idx + 1;
                    $txnDate = trim($r['transaction_date'] ?? '');
                    $buyerRaw = trim($r['buyer'] ?? '');
                    $desc = trim($r['description'] ?? '');
                    $type = strtolower(trim($r['transaction_type'] ?? ''));
                    $creditAmt = (float)($r['credit_amount'] ?? 0);
                    $debitAmt = (float)($r['debit_amount'] ?? 0);
                    $payMode = trim($r['payment_mode'] ?? '') ?: null;
                    $bankRaw = trim($r['bank_account'] ?? '');
                    $refNo = trim($r['reference_number'] ?? '') ?: null;
                    $tds = round((float)($r['tds_amount'] ?? 0), 2);
                    $gst = round((float)($r['gst_amount'] ?? 0), 2);
                    $invNo = trim($r['invoice_number'] ?? '') ?: null;

                    if ($buyerRaw === '' && $desc === '' && $creditAmt == 0 && $debitAmt == 0) continue; // trailing blank line

                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnDate)) { $errors[] = "Row $line: date must be YYYY-MM-DD"; continue; }
                    $bk2 = strtolower($buyerRaw);
                    if ($buyerRaw === '' || !isset($buyerMap[$bk2])) { $errors[] = "Row $line: buyer '$buyerRaw' not found"; continue; }
                    if (($nameCount[$bk2] ?? 0) > 1) { $errors[] = "Row $line: '$buyerRaw' matches several buyers — use the company code"; continue; }
                    if ($desc === '' || strlen($desc) > 500) { $errors[] = "Row $line: description is required (max 500 chars)"; continue; }
                    if (!in_array($type, ['commission_receivable', 'payment_received', 'payment_made', 'adjustment'], true)) { $errors[] = "Row $line: invalid type '$type'"; continue; }
                    if ($creditAmt < 0 || $debitAmt < 0) { $errors[] = "Row $line: amounts cannot be negative"; continue; }
                    if ($creditAmt <= 0 && $debitAmt <= 0) { $errors[] = "Row $line: credit or debit amount required"; continue; }

                    // normalize sides by type, same rules as the single add
                    if ($type === 'commission_receivable' || $type === 'payment_made') $debitAmt = 0;
                    elseif ($type === 'payment_received') $creditAmt = 0;
                    if ($creditAmt <= 0 && $debitAmt <= 0) { $errors[] = "Row $line: '$type' needs an amount on the " . ($type === 'payment_received' ? 'debit' : 'credit') . " side"; continue; }
                    if ($e = buyerTaxError($tds, $gst, $invNo, $debitAmt, $type)) { $errors[] = "Row $line: $e"; continue; }

                    $dir = buyerBankDir($type);
                    $bankId = 0;
                    if ($bankRaw !== '' && $dir !== null) {
                        $bkk = strtolower($bankRaw);
                        if (!isset($bankMap[$bkk])) { $errors[] = "Row $line: bank account '$bankRaw' not found"; continue; }
                        if (($bankNameCount[$bkk] ?? 0) > 1) { $errors[] = "Row $line: '$bankRaw' matches several accounts — use the account number"; continue; }
                        $bankId = $bankMap[$bkk];
                    }

                    $buyerId = $buyerMap[$bk2];
                    $conn->begin_transaction();
                    try {
                        $zero = 0.00; $noPolicy = null;
                        $stmt = $conn->prepare("INSERT INTO buyer_transactions (buyer_id, transaction_date, description, transaction_type, credit_amount, debit_amount, tds_amount, gst_amount, running_balance, policy_entry_id, payment_mode, reference_number, invoice_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("isssdddddisssi", $buyerId, $txnDate, $desc, $type, $creditAmt, $debitAmt, $tds, $gst, $zero, $noPolicy, $payMode, $refNo, $invNo, $user_id);
                        $stmt->execute(); $newId = (int)$stmt->insert_id; $stmt->close();

                        // bank leg for real cash movements, linked both ways like the single add
                        if ($bankId > 0 && $dir !== null) {
                            $amt = $creditAmt + $debitAmt - $tds; // bank = net of TDS
                            $bankTxnId = insertBankMirror($conn, $bankId, 'buyer', $buyerId, $newId, $txnDate, $dir, $amt, bankLegDesc($desc, $tds), $refNo, $user_id);
                            $lnk = $conn->prepare("UPDATE buyer_transactions SET bank_account_id=?, bank_txn_id=? WHERE id=?");
                            $lnk->bind_param("iii", $bankId, $bankTxnId, $newId);
                            $lnk->execute(); $lnk->close();
                            $touchedBanks[$bankId] = 1;
                        }

                        $conn->commit();
                        $touchedBuyers[$buyerId] = 1;
                        $inserted++;
                    } catch (Exception $ex) {
                        $conn->rollback();
                        $errors[] = "Row $line: failed to post '$desc'";
                    }
                }

                // recalc once per touched party/account, not per row
                foreach (array_keys($touchedBuyers) as $bid) recalcBuyerBalances($conn, $bid);
                foreach (array_keys($touchedBanks) as $aid) recalcBankBalance($conn, $aid);

                logActivity($user_id, $username, 'Bulk Buyer Txn Add', "Posted $inserted buyer transactions, " . count($errors) . " errors");
                $conn->close();
                $msg = "$inserted transaction(s) posted." . (count($touchedBuyers) ? " " . count($touchedBuyers) . " buyer(s) recalculated." : "");
                echo json_encode(['success'=>true, 'message'=>$msg, 'inserted'=>$inserted, 'errors'=>$errors]);
                exit();

            case 'updateTransaction':
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

                $tid = (int)($_POST['id'] ?? 0);
                $newBuyerId = (int)($_POST['buyer_id'] ?? 0);
                $txnDate = trim($_POST['transaction_date'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $txnType = trim($_POST['transaction_type'] ?? '');
                $credit = floatval($_POST['credit_amount'] ?? 0);
                $debit = floatval($_POST['debit_amount'] ?? 0);
                $payMode = trim($_POST['payment_mode'] ?? '') ?: null;
                $refNum = trim($_POST['reference_number'] ?? '') ?: null;
                $tds = round((float)($_POST['tds_amount'] ?? 0), 2);
                $gst = round((float)($_POST['gst_amount'] ?? 0), 2);
                $invNo = trim($_POST['invoice_number'] ?? '') ?: null;

                if ($tid <= 0 || empty($txnDate) || empty($desc) || empty($txnType)) {
                    echo json_encode(['success' => false, 'message' => 'Required fields missing']);
                    exit();
                }

                $bankAccId = (int)($_POST['bank_account_id'] ?? 0);

                // get current row (buyer + any linked bank leg)
                $stmt = $conn->prepare("SELECT buyer_id, bank_txn_id, is_linked_mirror, credit_amount, debit_amount, tds_amount, gst_amount, invoice_number FROM buyer_transactions WHERE id = ?");
                $stmt->bind_param("i", $tid);
                $stmt->execute();
                $oldTxn = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$oldTxn) {
                    echo json_encode(['success' => false, 'message' => 'Transaction not found']);
                    exit();
                }
                // linked mirror rows are managed from the Bank screen
                if ((int)$oldTxn['is_linked_mirror'] === 1) {
                    echo json_encode(['success' => false, 'message' => 'This entry is linked from a Bank transaction. Edit it on the Bank Transactions screen.']);
                    exit();
                }
                $oldBuyerId = (int)$oldTxn['buyer_id'];
                $oldBankTxnId = (int)$oldTxn['bank_txn_id'];
                // keep old buyer if none passed
                $buyerId = ($newBuyerId > 0) ? $newBuyerId : $oldBuyerId;
                // normalize sides by type
                if ($txnType === 'commission_receivable' || $txnType === 'payment_made') { $debit = 0; }
                elseif ($txnType === 'payment_received') { $credit = 0; }
                if ($e = buyerTaxError($tds, $gst, $invNo, $debit, $txnType)) {
                    echo json_encode(['success' => false, 'message' => $e]);
                    exit();
                }
                $dir = buyerBankDir($txnType);

                $conn->begin_transaction();
                try {
                    // drop any previous bank leg; re-post fresh below
                    $oldMirrorAcc = $oldBankTxnId > 0 ? deleteBankMirror($conn, $oldBankTxnId) : 0;

                    $newBankAccId = ($bankAccId > 0 && $dir !== null) ? $bankAccId : null;

                    $stmt = $conn->prepare("UPDATE buyer_transactions SET buyer_id=?, transaction_date=?, description=?, transaction_type=?, credit_amount=?, debit_amount=?, tds_amount=?, gst_amount=?, payment_mode=?, reference_number=?, invoice_number=?, bank_account_id=?, bank_txn_id=NULL, updated_by=? WHERE id=?");
                    $stmt->bind_param("isssddddsssiii", $buyerId, $txnDate, $desc, $txnType, $credit, $debit, $tds, $gst, $payMode, $refNum, $invNo, $newBankAccId, $user_id, $tid);
                    $stmt->execute();
                    $stmt->close();

                    // re-post bank leg for cash payments
                    if ($newBankAccId !== null) {
                        $amt = $credit + $debit - $tds; // bank = net of TDS
                        $newBankTxnId = insertBankMirror($conn, $bankAccId, 'buyer', $buyerId, $tid, $txnDate, $dir, $amt, bankLegDesc($desc, $tds), $refNum, $user_id);
                        $lnk = $conn->prepare("UPDATE buyer_transactions SET bank_txn_id=? WHERE id=?");
                        $lnk->bind_param("ii", $newBankTxnId, $tid);
                        $lnk->execute(); $lnk->close();
                    }

                    // recalc buyer(s)
                    recalcBuyerBalances($conn, $buyerId);
                    if ($oldBuyerId !== $buyerId) recalcBuyerBalances($conn, $oldBuyerId);
                    // recalc any bank account touched
                    if ($oldMirrorAcc > 0) recalcBankBalance($conn, $oldMirrorAcc);
                    if ($newBankAccId !== null && $bankAccId !== $oldMirrorAcc) recalcBankBalance($conn, $bankAccId);

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update transaction']);
                    exit();
                }

                logActivity($user_id, $username, 'Buyer Transaction Updated', "Updated transaction #$tid: cr {$oldTxn['credit_amount']}->$credit, dr {$oldTxn['debit_amount']}->$debit, tds {$oldTxn['tds_amount']}->$tds, gst {$oldTxn['gst_amount']}->$gst, inv " . ($oldTxn['invoice_number'] ?? '-') . '->' . ($invNo ?? '-'));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Transaction updated']);
                exit();

            case 'deleteTransaction':
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

                $tid = (int)($_POST['id'] ?? 0);
                if ($tid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid transaction ID']);
                    exit();
                }

                // get buyer before delete
                $stmt = $conn->prepare("SELECT buyer_id, bank_txn_id, is_linked_mirror, transaction_type, credit_amount, debit_amount FROM buyer_transactions WHERE id = ?");
                $stmt->bind_param("i", $tid);
                $stmt->execute();
                $delRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$delRow) {
                    echo json_encode(['success' => false, 'message' => 'Transaction not found']);
                    exit();
                }
                // linked mirror rows are managed from the Bank screen
                if ((int)$delRow['is_linked_mirror'] === 1) {
                    echo json_encode(['success' => false, 'message' => 'This entry is linked from a Bank transaction. Delete it on the Bank Transactions screen.']);
                    $conn->close();
                    exit();
                }

                $bId = (int)$delRow['buyer_id'];
                $delBankTxnId = (int)$delRow['bank_txn_id'];

                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("DELETE FROM buyer_transactions WHERE id = ?");
                    $stmt->bind_param("i", $tid);
                    $stmt->execute();
                    $stmt->close();

                    // drop linked bank leg too
                    $mirrorAcc = $delBankTxnId > 0 ? deleteBankMirror($conn, $delBankTxnId) : 0;

                    // recalc rewrites running_balance + wallet/outstanding
                    recalcBuyerBalances($conn, $bId);
                    if ($mirrorAcc > 0) recalcBankBalance($conn, $mirrorAcc);

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete transaction']);
                    exit();
                }

                logActivity($user_id, $username, 'Buyer Txn Deleted', "Deleted txn #$tid (type: {$delRow['transaction_type']}, cr: {$delRow['credit_amount']}, dr: {$delRow['debit_amount']})");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Transaction deleted successfully']);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("buyer_transactions.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
        exit();
    }
}

// balance recalc handled by recalcBuyerBalances() in config.php (single source of truth)

$isAdmin = ($role === 'admin');

// active bank accounts for the payment -> bank link dropdown
$bankAccts = [];
$cxnBA = getDBConnection();
$rsBA = $cxnBA->query("SELECT id, bank_name, account_number FROM bank_accounts WHERE is_active = 1 ORDER BY bank_name");
if ($rsBA) { while ($baRow = $rsBA->fetch_assoc()) $bankAccts[] = $baRow; }
$cxnBA->close();

// deep-link: pre-select buyer filter
$preBuyerId = isset($_GET['buyer_id']) ? (int)$_GET['buyer_id'] : 0;
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
    <title>Buyer Transactions - Insurance Management System</title>

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
            $pageIcon    = 'fa-handshake';
            $pageTitle   = 'Buyer Transactions';
            $crumbs      = [['label' => 'Buyers', 'icon' => 'fa-building']];
            $pageActions = [];
            if ($isAdmin) {
                $pageActions[] = ['label' => 'Add Transaction', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
                $pageActions[] = ['label' => 'Bulk Add / Import', 'icon' => 'fa-file-excel', 'onclick' => 'buyerTxnImport.open()'];
            }
            $pageActions[] = ['label' => 'Print Statement', 'icon' => 'fa-file-invoice', 'onclick' => 'printStatement()'];
            $pageActions[] = ['label' => 'Statement PDF', 'icon' => 'fa-file-arrow-down', 'onclick' => "printStatement('pdf')"];
            $pageActions[] = ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(txnTable,'csv')"];
            $pageActions[] = ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(txnTable,'pdf')"];
            $pageActions[] = ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(txnTable,'print')"];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadTransactions()'];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="txnSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="txnSearch" placeholder="Search transactions…" autocomplete="off"></div>
                <div class="hf-field"><label for="filterBuyer" title="Buyer"><i class="fas fa-building"></i></label><select id="filterBuyer" class="filter-input searchable">
                    <option value="">All Buyers</option>
                </select></div>
                <div class="hf-field"><label for="filterType" title="Type"><i class="fas fa-exchange-alt"></i></label><select id="filterType" class="filter-input searchable">
                    <option value="">All Types</option>
                    <option value="commission_receivable">Commission Receivable</option>
                    <option value="payment_received">Payment Received</option>
                    <option value="payment_made">Payment Made</option>
                    <option value="adjustment">Adjustment</option>
                </select></div>
                <div class="hf-field hf-range" title="Date range"><label for="filterDateFrom"><i class="fas fa-calendar-days"></i></label><input type="date" id="filterDateFrom" aria-label="From"><span class="hf-sep"><i class="fas fa-arrow-right-long"></i></span><input type="date" id="filterDateTo" aria-label="To"></div>
                <div class="hf-seg" role="group" aria-label="Quick range"><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',0,this)">Today</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',6,this)">7 days</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',29,this)">30 days</button></div>
                <button type="button" class="btn btn-icon" onclick="clearFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>

            <div class="data-section">
                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.4"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.4"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- table -->
                <div id="tableContainer" style="display: none;">
                    <div id="txnTable"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Detail Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="viewModalTitle"><i class="fas fa-eye"></i> Transaction Details</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- Transaction Modal -->
    <div class="modal-overlay" id="txnModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus-circle"></i> Add Transaction</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="txnForm">
                    <input type="hidden" id="txnId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Buyer *</label>
                            <select id="txnBuyer" name="buyer_id" class="searchable" required>
                                <option value="">Select Buyer</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Date *</label>
                            <input type="date" id="txnDate" name="transaction_date" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Description *</label>
                        <input type="text" id="txnDesc" name="description" required maxlength="500" placeholder="Transaction description">
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-exchange-alt"></i> Transaction Type *</label>
                            <select id="txnType" name="transaction_type" class="searchable" required onchange="handleTypeChange()">
                                <option value="">Select Type</option>
                                <option value="commission_receivable">Commission Receivable</option>
                                <option value="payment_received">Payment Received</option>
                                <option value="payment_made">Payment Made</option>
                                <option value="adjustment">Adjustment</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-credit-card"></i> Payment Mode</label>
                            <select id="txnPayMode" name="payment_mode" class="searchable">
                                <option value="">Select Mode</option>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="upi">UPI</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-arrow-down"></i> Credit Amount</label>
                            <input type="number" id="txnCredit" name="credit_amount" min="0" step="0.01" value="0" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-arrow-up"></i> Debit Amount</label>
                            <input type="number" id="txnDebit" name="debit_amount" min="0" step="0.01" value="0" placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-grid form-hidden" id="tdsRow">
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> TDS Deducted by Buyer <small id="tdsHint"></small></label>
                            <input type="number" id="txnTds" name="tds_amount" min="0" step="0.01" value="0" placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-grid form-hidden" id="gstRow">
                        <div class="form-group">
                            <label><i class="fas fa-file-invoice"></i> Invoice Number</label>
                            <input type="text" id="txnInvoiceNo" name="invoice_number" maxlength="50" placeholder="Commission invoice no.">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-receipt"></i> GST on Invoice</label>
                            <input type="number" id="txnGst" name="gst_amount" min="0" step="0.01" value="0" placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-grid form-hidden" id="bankLegRow">
                        <div class="form-group">
                            <label><i class="fas fa-university"></i> Bank Account <small>(posts to bank statement)</small></label>
                            <select id="txnBankAccount" name="bank_account_id" class="searchable">
                                <option value="">&mdash; Not through a bank &mdash;</option>
                                <?php foreach ($bankAccts as $ba): ?>
                                <option value="<?php echo (int)$ba['id']; ?>"><?php echo htmlspecialchars($ba['bank_name'] . ' - ' . $ba['account_number']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Reference Number</label>
                            <input type="text" id="txnRefNo" name="reference_number" placeholder="Ref / Cheque No.">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-file-alt"></i> Policy Entry ID</label>
                            <input type="number" id="txnPolicyId" name="policy_entry_id" placeholder="Link to policy (optional)">
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
        let txnTable = null;
        let txnData = [];
        let buyersList = [];
        var isEditMode = false;

        $(document).ready(function() {
            loadBuyers();
            loadTransactions();
        });

        // load buyers for filter + modal
        function loadBuyers() {
            $.ajax({
                url: '?action=getBuyers',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (!res.success) return;
                    buyersList = res.data;
                    var filterSel = $('#filterBuyer');
                    filterSel.find('option:not(:first)').remove();
                    res.data.forEach(function(b) {
                        var label = escapeHtml(b.company_name + ' (' + b.company_code + ')');
                        filterSel.append('<option value="' + b.id + '">' + label + '</option>');
                    });

                    // modal dropdown
                    var modalSel = $('#txnBuyer');
                    modalSel.find('option:not(:first)').remove();
                    res.data.forEach(function(b) {
                        var label = escapeHtml(b.company_name + ' (' + b.company_code + ')');
                        modalSel.append('<option value="' + b.id + '">' + label + '</option>');
                    });
                    setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
                }
            });
        }

        function loadTransactions() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();

            $.ajax({
                url: '?action=getTransactions',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        txnData = res.data;
                        $('#loadingSkeleton').hide();
                        $('#tableContainer').show();
                        setTimeout(function() { initDataTable(res.data); }, 100);
                    } else {
                        $('#loadingSkeleton').hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load transactions' });
                    }
                },
                error: function() {
                    $('#loadingSkeleton').hide();
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
                }
            });
        }

        function fmtAmount(val) {
            return '\u20B9' + parseFloat(val).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function fmtTxnType(t) {
            var map = {
                commission_receivable: 'Commission Receivable',
                payment_received: 'Payment Received',
                payment_made: 'Payment Made',
                adjustment: 'Adjustment'
            };
            return map[t] || t;
        }

        function txnTypeBadge(t) {
            var cls = 'status-badge ';
            switch (t) {
                case 'commission_receivable': cls += 'status-active'; break;
                case 'payment_received': cls += 'status-active'; break;
                case 'payment_made': cls += 'status-inactive'; break;
                default: cls += 'status-pending'; break;
            }
            return '<span class="' + cls + '">' + fmtTxnType(t) + '</span>';
        }

        // view txn detail modal
        function viewTxn(d) {
            var crVal = d.credit_amount > 0 ? '<span style="color:#27ae60;">' + fmtAmount(d.credit_amount) + '</span>' : '-';
            var drVal = d.debit_amount > 0 ? '<span style="color:#e74c3c;">' + fmtAmount(d.debit_amount) + '</span>' : '-';
            var policy = d.policy_entry_id
                ? '<a href="policy_entries.php?id=' + d.policy_entry_id + '" title="Open policy"><span class="badge-code"><i class="fas fa-file-alt"></i> Policy #' + d.policy_entry_id + '</span></a>'
                : '-';
            var html = '<table class="view-detail-table">';
            html += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">' + escapeHtml(d.id) + '</td>';
            html += '<td class="vd-label"><i class="fas fa-calendar"></i> Date</td><td class="vd-value">' + escapeHtml(d.transaction_date || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-building"></i> Company</td><td class="vd-value">' + escapeHtml(d.company_name || '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-exchange-alt"></i> Type</td><td class="vd-value">' + txnTypeBadge(d.transaction_type) + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-arrow-down"></i> Credit</td><td class="vd-value">' + crVal + '</td>';
            html += '<td class="vd-label"><i class="fas fa-arrow-up"></i> Debit</td><td class="vd-value">' + drVal + '</td></tr>';
            if (d.tds_amount > 0) {
                html += '<tr><td class="vd-label"><i class="fas fa-percent"></i> TDS Deducted</td><td class="vd-value">' + fmtAmount(d.tds_amount) + '</td>';
                html += '<td class="vd-label"><i class="fas fa-hand-holding-dollar"></i> Net Received</td><td class="vd-value">' + fmtAmount(d.debit_amount - d.tds_amount) + '</td></tr>';
            }
            if (d.invoice_number || d.gst_amount > 0) {
                html += '<tr><td class="vd-label"><i class="fas fa-file-invoice"></i> Invoice No.</td><td class="vd-value">' + escapeHtml(d.invoice_number || '-') + '</td>';
                html += '<td class="vd-label"><i class="fas fa-receipt"></i> GST</td><td class="vd-value">' + (d.gst_amount > 0 ? fmtAmount(d.gst_amount) : '-') + '</td></tr>';
            }
            html += '<tr><td class="vd-label"><i class="fas fa-balance-scale"></i> Balance</td><td class="vd-value">' + fmtAmount(d.running_balance) + '</td>';
            html += '<td class="vd-label"><i class="fas fa-credit-card"></i> Payment Mode</td><td class="vd-value">' + escapeHtml(d.payment_mode || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> Reference</td><td class="vd-value">' + escapeHtml(d.reference_number || '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-file-alt"></i> Policy</td><td class="vd-value">' + policy + '</td></tr>';
            if (d.bank_account_id) {
                html += '<tr><td class="vd-label"><i class="fas fa-university"></i> Bank Leg</td><td class="vd-value" colspan="3"><span class="badge-code"><i class="fas fa-link"></i> Posted to ' + escapeHtml(d.linked_bank_name || 'bank') + '</span></td></tr>';
            }
            html += '<tr><td class="vd-label"><i class="fas fa-align-left"></i> Description</td><td class="vd-value" colspan="3">' + escapeHtml(d.description || '-') + '</td></tr>';
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

        // delegated action handlers - one per table
        $('#txnTable').on('click', '.act-view, .more-edit, .more-del', function() {
            var id = parseInt($(this).attr('data-id'), 10);
            var row = txnData.find(function(r) { return r.id === id; });
            if (!row) return;
            if ($(this).hasClass('act-view')) viewTxn(row);
            else if ($(this).hasClass('more-edit')) editTxn(row);
            else if ($(this).hasClass('more-del')) deleteTxn(id);
        });

        function initDataTable(data) {
            if (txnTable) return rsTbData(txnTable, data); // build once, swap rows

            var cols = [
                { data: 'id', title: 'ID' },
                {
                    data: 'transaction_date',
                    title: 'Date',
                    render: function(d) {
                        if (!d) return '-';
                        var dt = new Date(d);
                        return dt.toLocaleDateString('en-IN', { year: 'numeric', month: 'short', day: '2-digit' });
                    }
                },
                {
                    data: 'company_name',
                    title: 'Company',
                    responsivePriority: 0,
                    render: function(d) { return escapeHtml(d); }
                },
                {
                    data: 'description',
                    title: 'Description',
                    render: function(d) {
                        if (!d) return '-';
                        var esc = escapeHtml(d);
                        return d.length > 40 ? '<span title="' + esc + '">' + escapeHtml(d.substring(0, 40)) + '...</span>' : esc;
                    }
                },
                {
                    data: 'transaction_type',
                    title: 'Type',
                    render: function(d) { return txnTypeBadge(d); }
                },
                {
                    data: 'credit_amount',
                    title: 'Credit',
                    render: function(d) {
                        if (!d || parseFloat(d) === 0) return '<em style="color:#999;">-</em>';
                        return '<span class="wallet-amount" style="color: #27ae60;">' + fmtAmount(d) + '</span>';
                    }
                },
                {
                    data: 'debit_amount',
                    title: 'Debit',
                    render: function(d, t, row) {
                        if (!d || parseFloat(d) === 0) return '<em style="color:#999;">-</em>';
                        var v = '<span class="wallet-amount" style="color: #e74c3c;">' + fmtAmount(d) + '</span>';
                        return (t === 'display' && row.tds_amount > 0) ? v + '<br><small>TDS ' + fmtAmount(row.tds_amount) + '</small>' : v;
                    }
                },
                {
                    data: 'running_balance',
                    title: 'Balance',
                    render: function(d) { return '<span class="wallet-amount">' + fmtAmount(d) + '</span>'; }
                },
                {
                    data: 'payment_mode',
                    title: 'Payment Mode',
                    render: function(d, t, row) {
                        var m = d ? escapeHtml(d.charAt(0).toUpperCase() + d.slice(1).replace('_', ' ')) : '<em style="color:#999;">-</em>';
                        if (row.bank_account_id) {
                            m += ' <span class="badge-code" title="Posted to bank statement"><i class="fas fa-university"></i> ' + escapeHtml(row.linked_bank_name || 'Bank') + '</span>';
                        }
                        return m;
                    }
                },
                {
                    data: 'reference_number',
                    title: 'Reference',
                    render: function(d, t, row) {
                        var v = d ? escapeHtml(d) : '<em style="color:#999;">-</em>';
                        return (t === 'display' && row.invoice_number) ? v + '<br><small>Inv ' + escapeHtml(row.invoice_number) + '</small>' : v;
                    }
                },
                // export-only cols
                { data: 'tds_amount', title: 'TDS', visible: false, render: function(d) { return d > 0 ? fmtAmount(d) : '-'; } },
                { data: 'gst_amount', title: 'GST', visible: false, render: function(d) { return d > 0 ? fmtAmount(d) : '-'; } },
                { data: 'invoice_number', title: 'Invoice No', visible: false, render: function(d) { return d ? escapeHtml(d) : '-'; } }
            ];

            // Policy link col
            cols.push({
                data: 'policy_entry_id',
                title: 'Policy',
                render: function(d) {
                    return d
                        ? '<a href="policy_entries.php?id=' + d + '" title="Open policy"><span class="badge-code"><i class="fas fa-file-alt"></i> Policy #' + d + '</span></a>'
                        : '<em style="color:#999;">-</em>';
                }
            });

            // action hub col (last) - view for all, edit/delete for admin
            cols.push({
                data: null,
                title: 'Actions',
                orderable: false,
                render: function(d, type, row) {
                    var more = '';
                    if (IS_ADMIN && row.is_linked_mirror != 1) {
                        more = '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button>' +
                            '<div class="more-dropdown">' +
                            '<button class="more-item more-edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> Edit</button>' +
                            '<div class="more-divider"></div>' +
                            '<button class="more-item danger more-del" data-id="' + row.id + '"><i class="fas fa-trash"></i> Delete</button>' +
                            '</div></div>';
                    } else if (IS_ADMIN && row.is_linked_mirror == 1) {
                        more = '<a class="action-icon" href="bank_transactions.php" title="Linked from a Bank transaction — manage it on the Bank screen"><i class="fas fa-link"></i></a>';
                    }
                    return '<div class="action-hub">' +
                        '<button class="action-icon view-icon act-view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button>' +
                        more +
                    '</div>';
                }
            });

            txnTable = rsTable('#txnTable', {
                columns: cols, data: data, title: 'Buyer Transactions', file: 'buyer_transactions', sort: ['id', 'desc'],
                rowClass: function(r) { return parseFloat(r.credit_amount) > 0 ? 'dt-row-success' : (parseFloat(r.debit_amount) > 0 ? 'dt-row-danger' : ''); },
                info: function() {
                    return [['Buyer', $('#filterBuyer').val() ? $('#filterBuyer option:selected').text() : 'All'],
                        ['Type', $('#filterType').val() ? fmtTxnType($('#filterType').val()) : 'All'],
                        ['Date', ($('#filterDateFrom').val() || '…') + ' to ' + ($('#filterDateTo').val() || '…')]];
                }
            });
            rsTbSearch(txnTable, $('#txnSearch').val()); // keep head search

            // bind filters
            $('#filterBuyer, #filterType').off('change').on('change', applyFilters);
            $('#filterDateFrom, #filterDateTo').off('change').on('change', applyFilters);

            // deep-link pre-filter
            <?php if ($preBuyerId > 0): ?>
            if (!window._buyerPrefilled) {
                window._buyerPrefilled = true;
                $('#filterBuyer').val('<?php echo $preBuyerId; ?>');
                var sd = document.getElementById('filterBuyer');
                if (sd && sd.sdRefresh) sd.sdRefresh();
                applyFilters();
            }
            <?php endif; ?>
        }

        function applyFilters() {
            if (!txnTable) return;
            var from = $('#filterDateFrom').val(), to = $('#filterDateTo').val(),
                buyer = $('#filterBuyer').val(), type = $('#filterType').val();
            rsTbWhere(txnTable, 'date', (from || to) ? function(r) {
                var d = r.transaction_date ? String(r.transaction_date).substring(0, 10) : '';
                return !(from && d < from) && !(to && d > to);
            } : null);
            rsTbWhere(txnTable, 'buyer', buyer ? function(r) { return String(r.buyer_id) === String(buyer); } : null);
            rsTbWhere(txnTable, 'type', type ? function(r) { return r.transaction_type === type; } : null);
        }

        function clearFilters() {
            $('#filterBuyer').val('');
            $('#filterDateFrom').val('');
            $('#filterDateTo').val('');
            $('#filterType').val('');
            $('#txnSearch').val('');
            ['filterBuyer', 'filterType'].forEach(function(id) { var el = document.getElementById(id); if (el && el.sdSetVal) el.sdSetVal(''); });
            $('.hf-seg button').removeClass('is-on');

            rsTbClear(txnTable);
        }

        // header search -> table
        $('#txnSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(txnTable, v); },200); });

        <?php if ($isAdmin): ?>
        function handleTypeChange() {
            var type = $('#txnType').val();
            if (type === 'commission_receivable' || type === 'payment_made') {
                // credit only
                $('#txnCredit').prop('disabled', false).val('');
                $('#txnDebit').prop('disabled', true).val(0);
            } else if (type === 'payment_received') {
                // debit only
                $('#txnCredit').prop('disabled', true).val(0);
                $('#txnDebit').prop('disabled', false).val('');
            } else {
                // adjustment or empty - both enabled
                $('#txnCredit').prop('disabled', false).val('');
                $('#txnDebit').prop('disabled', false).val('');
            }
            // tds only on receipts; gst/invoice on commission + payment rows
            $('#tdsRow').toggleClass('form-hidden', type !== 'payment_received');
            if (type !== 'payment_received') $('#txnTds').val(0);
            var hasInv = !!type && type !== 'adjustment';
            $('#gstRow').toggleClass('form-hidden', !hasInv);
            if (!hasInv) { $('#txnGst').val(0); $('#txnInvoiceNo').val(''); }
            suggestTds();
            // bank leg only for real cash payments
            var isPay = (type === 'payment_received' || type === 'payment_made');
            $('#bankLegRow').toggleClass('form-hidden', !isPay);
            if (!isPay) {
                $('#txnBankAccount').val('');
                var bs = document.getElementById('txnBankAccount');
                if (bs && bs.sdRefresh) bs.sdRefresh();
            }
        }

        // tds = received x buyer tds_rate / 100 until the user types their own
        var tdsTouched = false;
        function suggestTds() {
            if ($('#txnType').val() !== 'payment_received') { $('#tdsHint').text(''); return; }
            var b = buyersList.find(function(x) { return String(x.id) === String($('#txnBuyer').val()); });
            var rate = b ? parseFloat(b.tds_rate) || 0 : 0, dr = parseFloat($('#txnDebit').val()) || 0;
            if (!tdsTouched) $('#txnTds').val((Math.round(dr * rate) / 100).toFixed(2));
            var tds = parseFloat($('#txnTds').val()) || 0;
            $('#tdsHint').text('(' + rate + '% · bank receives ' + fmtAmount(Math.max(dr - tds, 0)) + ')');
        }
        $('#txnDebit, #txnBuyer').on('input change', suggestTds);
        $('#txnTds').on('input', function() { tdsTouched = true; suggestTds(); });

        function openAddModal() {
            isEditMode = false;
            tdsTouched = false;
            $('#modalTitle').html('<i class="fas fa-plus-circle"></i> Add Transaction');
            $('#txnForm')[0].reset();
            $('#txnId').val('');
            $('#txnCredit').val('0');
            $('#txnDebit').val('0');
            // default today
            $('#txnDate').val(new Date().toISOString().substring(0, 10));
            // re-enable buyer select
            $('#txnBuyer').prop('disabled', false);
            handleTypeChange(); // hide bank leg until a payment type is picked
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
            $('#txnModal').addClass('active');
        }

        // edit txn - populate modal
        function editTxn(row) {
            isEditMode = true;
            $('#modalTitle').html('<i class="fas fa-edit"></i> Edit Transaction');
            $('#txnId').val(row.id);
            $('#txnBuyer').val(row.buyer_id);
            $('#txnBuyer').prop('disabled', true);
            $('#txnDate').val(row.transaction_date ? row.transaction_date.substring(0, 10) : '');
            $('#txnDesc').val(row.description);
            $('#txnType').val(row.transaction_type);
            $('#txnBankAccount').val(row.bank_account_id || '');
            tdsTouched = true; // keep the stored tds
            handleTypeChange();
            $('#txnCredit').val(row.credit_amount || 0);
            $('#txnDebit').val(row.debit_amount || 0);
            $('#txnTds').val(row.tds_amount || 0);
            $('#txnGst').val(row.gst_amount || 0);
            $('#txnInvoiceNo').val(row.invoice_number || '');
            suggestTds();
            $('#txnPayMode').val(row.payment_mode || '');
            $('#txnRefNo').val(row.reference_number || '');
            $('#txnPolicyId').val(row.policy_entry_id || '');
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
            $('#txnModal').addClass('active');
        }

        function closeModal() {
            $('#txnModal').removeClass('active');
            $('#txnForm')[0].reset();
        }

        // close on overlay click
        document.getElementById('txnModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // form submit
        $('#txnForm').on('submit', function(e) {
            e.preventDefault();

            // re-enable buyer so FormData picks it up
            if (isEditMode) $('#txnBuyer').prop('disabled', false);
            var formData = new FormData(this);
            formData.set('csrf_token', window.CSRF_TOKEN);
            if ($('#tdsRow').hasClass('form-hidden')) formData.set('tds_amount', 0);
            var tdsV = parseFloat(formData.get('tds_amount') || 0);
            if (tdsV > 0 && tdsV >= (parseFloat(formData.get('debit_amount') || 0))) {
                if (isEditMode) $('#txnBuyer').prop('disabled', true);
                Swal.fire({ icon: 'warning', title: 'Invalid', text: 'TDS must be less than the amount received' });
                return;
            }
            var action = isEditMode ? 'updateTransaction' : 'addTransaction';

            showLoading('Saving transaction...');

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
                        setTimeout(loadTransactions, 100);
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

        function deleteTxn(tid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Transaction?',
                text: 'This will recalculate buyer balances',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) return;

                var fd = new FormData();
                fd.append('id', tid);
                fd.append('csrf_token', window.CSRF_TOKEN);

                showLoading('Deleting transaction...');
                $.ajax({
                    url: '?action=deleteTransaction',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        hideLoading();
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                            setTimeout(loadTransactions, 100);
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
        <?php endif; ?>

        // filtered statement -> A4 print / PDF
        function printStatement(kind) {
            if (!txnTable) { Swal.fire('Info','No data loaded','info'); return; }
            var rows = rsTbRows(txnTable);
            if (!rows.length) { Swal.fire('Info','No data to print','info'); return; }

            var buyerSel = document.getElementById('filterBuyer');
            var buyerName = (buyerSel && buyerSel.value) ? (buyerSel.options[buyerSel.selectedIndex]?.text || 'All Buyers') : 'All Buyers';
            // running balance only meaningful for a single buyer
            if (!buyerSel || !buyerSel.value) {
                Swal.fire({ icon: 'warning', title: 'Select a Buyer', text: 'Please choose a specific buyer before printing a statement.' });
                return;
            }
            var dateFrom = document.getElementById('filterDateFrom')?.value || '';
            var dateTo = document.getElementById('filterDateTo')?.value || '';

            var typeLbl = function(r) { return String(r.transaction_type || '').replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); }); };
            var selTxt = function(id) { var e = document.getElementById(id); return e && e.value ? e.options[e.selectedIndex].text : ''; };
            rsStatement(kind || 'print', {
                title: 'Buyer Statement', number: 'BST-' + rsStamp(),
                party: { label: 'Statement for', name: buyerName },
                period: (dateFrom ? rsPaperDate(dateFrom) : 'Start') + ' to ' + (dateTo ? rsPaperDate(dateTo) : 'Today'),
                rows: rows, file: 'buyer_statement',
                filters: [selTxt('filterType') ? 'Type: ' + selTxt('filterType') : '', $('#txnSearch').val() ? 'Search "' + $('#txnSearch').val() + '"' : ''].filter(Boolean),
                cr: function(r) { return parseFloat(r.credit_amount) || 0; },
                dr: function(r) { return parseFloat(r.debit_amount) || 0; },
                bal: function(r) { return parseFloat(r.running_balance) || 0; },
                desc: function(r) { return r.description || typeLbl(r); },
                sub: function(r) {
                    return [r.description ? typeLbl(r) : '', r.invoice_number ? 'Inv ' + r.invoice_number : '',
                        r.gst_amount > 0 ? 'GST ' + rsMoney(r.gst_amount) : '', r.tds_amount > 0 ? 'TDS ' + rsMoney(r.tds_amount) : ''].filter(Boolean).join(' · ');
                }
            });
        }

        <?php if ($isAdmin): ?>
        // bulk add / excel import — engine lives in sidebar.php
        var BT_BANKS = <?php echo json_encode($bankAccts); ?>;
        var buyerTxnImport = RSImport({
            key: 'buyer_transactions', title: 'Bulk Add / Import Buyer Transactions', url: '?action=bulkAddBuyerTxns',
            hint: 'Post many buyer ledger entries at once — upload an Excel/CSV file or type the rows. <strong>Date, buyer, description and type</strong> are required, plus a credit or debit amount. The type fixes the side automatically: <strong>commission receivable</strong> and <strong>payment made</strong> are credits, <strong>payment received</strong> is a debit, <strong>adjustment</strong> takes whichever you fill. A <strong>bank account</strong> only links on payment received/made. <strong>TDS</strong> is allowed on payment received only: the ledger keeps the full debit, the bank leg posts debit − TDS. GST and invoice number are for reference. Buyers are matched on company code, then on company name.',
            onDone: function() { setTimeout(loadTransactions, 100); },
            fields: [
                { key: 'transaction_date', label: 'Date *', cls: 'bg-date', type: 'date', req: true, aliases: ['transactiondate', 'date', 'txndate', 'entrydate'], demo: '2026-07-17', demo2: '2026-07-18' },
                { key: 'buyer', label: 'Buyer *', cls: 'bg-buyer', type: 'select', req: true,
                  opts: function() { return [{ v: '', t: '— Select —' }].concat((buyersList || []).map(function(b) { return { v: b.company_code, t: b.company_name + ' (' + b.company_code + ')' }; })); },
                  aliases: ['buyer', 'buyercode', 'companycode', 'company', 'companyname', 'buyername'], demo: '', demo2: '' },
                { key: 'description', label: 'Description *', cls: 'bg-desc', req: true, max: 500, ph: 'What this entry is for', aliases: ['description', 'desc', 'particulars', 'narration', 'details', 'remarks'], demo: 'Demo commission receivable', demo2: 'Demo payment received' },
                { key: 'transaction_type', label: 'Type *', cls: 'bg-type', type: 'select', req: true, def: 'adjustment',
                  opts: [{ v: 'commission_receivable', t: 'Commission Receivable' }, { v: 'payment_received', t: 'Payment Received' }, { v: 'payment_made', t: 'Payment Made' }, { v: 'adjustment', t: 'Adjustment' }],
                  aliases: ['transactiontype', 'type', 'txntype', 'entrytype'], demo: 'commission_receivable', demo2: 'payment_received' },
                { key: 'credit_amount', label: 'Credit (₹)', cls: 'bg-cr', type: 'number', aliases: ['creditamount', 'credit', 'cr', 'creditamt'], demo: 8000, demo2: 0 },
                { key: 'debit_amount', label: 'Debit (₹)', cls: 'bg-dr', type: 'number', aliases: ['debitamount', 'debit', 'dr', 'debitamt'], demo: 0, demo2: 8000 },
                { key: 'tds_amount', label: 'TDS (₹)', cls: 'bg-dr', type: 'number', aliases: ['tdsamount', 'tds', 'tdsdeducted'], demo: 0, demo2: 160 },
                { key: 'payment_mode', label: 'Mode', cls: 'bg-mode', type: 'select',
                  opts: [{ v: '', t: '— None —' }, { v: 'Cash', t: 'Cash' }, { v: 'Bank Transfer', t: 'Bank Transfer' }, { v: 'UPI', t: 'UPI' }, { v: 'Cheque', t: 'Cheque' }],
                  aliases: ['paymentmode', 'mode', 'paymode', 'paymenttype'], demo: '', demo2: 'Bank Transfer' },
                { key: 'bank_account', label: 'Bank Account', cls: 'bg-bank', type: 'select',
                  opts: function() { return [{ v: '', t: '— None —' }].concat(BT_BANKS.map(function(a) { return { v: a.account_number, t: a.bank_name + ' - ' + a.account_number }; })); },
                  aliases: ['bankaccount', 'bank', 'account', 'accountnumber', 'bankname'], demo: '', demo2: '' },
                { key: 'reference_number', label: 'Reference', cls: 'bg-ref', max: 50, ph: 'optional', aliases: ['referencenumber', 'reference', 'refno', 'chequeno', 'utr', 'voucherno'], demo: '', demo2: 'REF-301' },
                { key: 'invoice_number', label: 'Invoice No', cls: 'bg-ref', max: 50, ph: 'optional', aliases: ['invoicenumber', 'invoiceno', 'invoice', 'billno'], demo: 'INV-2026-001', demo2: '' },
                { key: 'gst_amount', label: 'GST (₹)', cls: 'bg-cr', type: 'number', aliases: ['gstamount', 'gst', 'gstoninvoice'], demo: 1440, demo2: 0 }
            ]
        });
        <?php endif; ?>
    </script>
</body>
</html>
