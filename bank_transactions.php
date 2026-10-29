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
$current_page = 'bank_transactions';

// agent cant access
if ($role === 'agent') { header("Location: dashboard.php"); exit(); }

// payment modes (form, filter, import) - whitelist
const BT_MODES = ['Cash', 'NEFT', 'RTGS', 'IMPS', 'UPI', 'Cheque', 'Card', 'Other'];
// raw -> canonical mode | null (blank) | false (bad)
function btMode($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    foreach (BT_MODES as $m) if (strcasecmp($m, $v) === 0) return $m;
    return false;
}

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getAccounts':
                $stmt = $conn->prepare("SELECT id, bank_name, account_number, opening_balance, opening_balance_date FROM bank_accounts WHERE is_active = 1 ORDER BY bank_name");
                $stmt->execute();
                $res = $stmt->get_result();
                $accounts = [];
                while ($row = $res->fetch_assoc()) {
                    $accounts[] = [
                        'id' => (int)$row['id'],
                        'bank_name' => $row['bank_name'],
                        'account_number' => $row['account_number'],
                        'opening_balance' => (float)$row['opening_balance'],
                        'opening_balance_date' => $row['opening_balance_date']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $accounts]);
                exit();

            case 'getTransactions':
                $bankFilter = isset($_GET['bank_account_id']) ? (int)$_GET['bank_account_id'] : 0;

                $sql = "SELECT bt.*, ba.bank_name, ba.account_number,
                        (SELECT name FROM agents WHERE id = bt.agent_id) as linked_agent_name,
                        (SELECT company_name FROM buyers WHERE id = bt.buyer_id) as linked_buyer_name
                        FROM bank_transactions bt
                        JOIN bank_accounts ba ON bt.bank_account_id = ba.id";
                if ($bankFilter > 0) {
                    $sql .= " WHERE bt.bank_account_id = ?";
                }
                $sql .= " ORDER BY bt.transaction_date DESC, bt.id DESC";

                $stmt = $conn->prepare($sql);
                if ($bankFilter > 0) {
                    $stmt->bind_param("i", $bankFilter);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $txns = [];
                while ($row = $res->fetch_assoc()) {
                    $txns[] = [
                        'id' => (int)$row['id'],
                        'bank_account_id' => (int)$row['bank_account_id'],
                        'bank_name' => $row['bank_name'],
                        'account_number' => $row['account_number'],
                        'transaction_date' => $row['transaction_date'],
                        'description' => $row['description'],
                        'reference_number' => $row['reference_number'],
                        'transaction_type' => $row['transaction_type'],
                        'amount' => (float)$row['amount'],
                        'running_balance' => (float)$row['running_balance'],
                        'policy_entry_id' => $row['policy_entry_id'] ? (int)$row['policy_entry_id'] : null,
                        'expense_id' => $row['expense_id'] ? (int)$row['expense_id'] : null,
                        'category' => $row['category'],
                        'payment_mode' => $row['payment_mode'] ?? null,
                        'is_reconciled' => (int)($row['is_reconciled'] ?? 0),
                        'reconciled_at' => $row['reconciled_at'] ?? null,
                        'agent_id' => $row['agent_id'] ? (int)$row['agent_id'] : null,
                        'buyer_id' => $row['buyer_id'] ? (int)$row['buyer_id'] : null,
                        'link_txn_id' => $row['link_txn_id'] ? (int)$row['link_txn_id'] : null,
                        'is_linked_mirror' => (int)$row['is_linked_mirror'],
                        'linked_agent_name' => $row['linked_agent_name'],
                        'linked_buyer_name' => $row['linked_buyer_name'],
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

                $bankAccId = (int)($_POST['bank_account_id'] ?? 0);
                $txnDate = trim($_POST['transaction_date'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $txnType = trim($_POST['transaction_type'] ?? '');
                $amt = (float)($_POST['amount'] ?? 0);
                $refNo = trim($_POST['reference_number'] ?? '');
                $category = trim($_POST['category'] ?? '');
                $policyId = !empty($_POST['policy_entry_id']) ? (int)$_POST['policy_entry_id'] : null;
                $expenseId = !empty($_POST['expense_id']) ? (int)$_POST['expense_id'] : null;
                $mode = btMode($_POST['payment_mode'] ?? '');
                $recon = isset($_POST['is_reconciled']) ? 1 : 0;
                if ($mode === false) { echo json_encode(['success' => false, 'message' => 'Invalid payment mode']); exit(); }

                // validate
                if ($bankAccId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Bank account is required']);
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
                if (!in_array($txnType, ['credit', 'debit'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid transaction type']);
                    exit();
                }
                if ($amt <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
                    exit();
                }

                $refNoVal = !empty($refNo) ? $refNo : null;
                $catVal = !empty($category) ? $category : null;

                $linkType = trim($_POST['link_type'] ?? '');
                $linkPartyId = 0;
                if ($linkType === 'agent') $linkPartyId = (int)($_POST['link_party_id_agent'] ?? 0);
                elseif ($linkType === 'buyer') $linkPartyId = (int)($_POST['link_party_id_buyer'] ?? 0);

                $conn->begin_transaction();
                try {
                    // running_balance set by recalc; insert 0 placeholder
                    $zero = 0.00;
                    $stmt = $conn->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, description, reference_number, transaction_type, amount, payment_mode, running_balance, policy_entry_id, expense_id, category, is_reconciled, reconciled_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? = 1, NOW(), NULL), ?)");
                    $stmt->bind_param("issssdsdiisiii", $bankAccId, $txnDate, $desc, $refNoVal, $txnType, $amt, $mode, $zero, $policyId, $expenseId, $catVal, $recon, $recon, $user_id);
                    $stmt->execute();
                    $insertId = $stmt->insert_id;
                    $stmt->close();

                    // party leg (agent/buyer) for this bank entry
                    if (($linkType === 'agent' || $linkType === 'buyer') && $linkPartyId > 0) {
                        list($pkind, $ptxn) = insertPartyMirrorForBank($conn, $linkType, $linkPartyId, $bankAccId, $insertId, $txnDate, $txnType, $amt, $desc, $refNoVal, $user_id);
                        if ($ptxn > 0) {
                            $aId = $pkind === 'agent' ? $linkPartyId : null;
                            $bId = $pkind === 'buyer' ? $linkPartyId : null;
                            $lnk = $conn->prepare("UPDATE bank_transactions SET agent_id=?, buyer_id=?, link_txn_id=? WHERE id=?");
                            $lnk->bind_param("iiii", $aId, $bId, $ptxn, $insertId);
                            $lnk->execute(); $lnk->close();
                            if ($pkind === 'agent') recalcAgentBalances($conn, $linkPartyId);
                            else recalcBuyerBalances($conn, $linkPartyId);
                        }
                    }

                    // recalc rewrites running_balance + current_balance
                    recalcBankBalance($conn, $bankAccId);

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add transaction']);
                    exit();
                }

                logActivity($user_id, $username, 'Bank Txn Created', "Added $txnType txn #$insertId, amt: $amt, account: $bankAccId" . ($mode ? ", mode: $mode" : '') . ($recon ? ', reconciled' : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Transaction added successfully', 'data' => ['id' => $insertId]]);
                exit();

            case 'bulkUpload':
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

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows) || count($rows) === 0) {
                    echo json_encode(['success' => false, 'message' => 'No data found in file']);
                    exit();
                }

                // bank lookup by number + name (built once)
                $bankMap = [];
                $bres = $conn->query("SELECT id, bank_name, account_number FROM bank_accounts WHERE is_active = 1");
                while ($b = $bres->fetch_assoc()) {
                    $bid = (int)$b['id'];
                    if (trim($b['account_number']) !== '') $bankMap[strtolower(trim($b['account_number']))] = $bid;
                    $bankMap[strtolower(trim($b['bank_name']))] = $bid;
                }

                $inserted = 0;
                $errors = [];
                $banksTouched = [];
                $noPolicy = null; $noExp = null; $zero = 0.00;

                foreach ($rows as $idx => $row) {
                    $rowNum = $idx + 2; // header is row 1
                    $rowTxn = false;
                    try {
                        $bankRaw = trim($row['bank_account'] ?? '');
                        $txnDate = trim($row['transaction_date'] ?? '');
                        $desc = trim($row['description'] ?? '');
                        $txnType = strtolower(trim($row['transaction_type'] ?? ''));
                        $amt = (float)($row['amount'] ?? 0);
                        $refNo = trim($row['reference_number'] ?? '');
                        $cat = trim($row['category'] ?? '');
                        $mode = btMode($row['payment_mode'] ?? '');
                        if ($mode === false) { $errors[] = "Row $rowNum: payment mode must be one of " . implode(', ', BT_MODES); continue; }

                        // required fields
                        if ($bankRaw === '' || $txnDate === '' || $desc === '' || $txnType === '' || $amt <= 0) {
                            $errors[] = "Row $rowNum: Missing/invalid required field(s)";
                            continue;
                        }
                        if (!in_array($txnType, ['credit', 'debit'])) {
                            $errors[] = "Row $rowNum: Type must be 'credit' or 'debit'";
                            continue;
                        }
                        $bankId = $bankMap[strtolower($bankRaw)] ?? 0;
                        if (!$bankId) {
                            $errors[] = "Row $rowNum: Bank account '$bankRaw' not found";
                            continue;
                        }
                        $dt = date_create($txnDate);
                        if (!$dt) {
                            $errors[] = "Row $rowNum: Invalid date '$txnDate'";
                            continue;
                        }
                        $txnDate = $dt->format('Y-m-d');
                        if (strlen($desc) > 500) $desc = substr($desc, 0, 500);
                        $refVal = $refNo !== '' ? $refNo : null;
                        $catVal = $cat !== '' ? $cat : null;

                        $conn->begin_transaction();
                        $rowTxn = true;
                        $stmt = $conn->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, description, reference_number, transaction_type, amount, payment_mode, running_balance, policy_entry_id, expense_id, category, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("issssdsdiisi", $bankId, $txnDate, $desc, $refVal, $txnType, $amt, $mode, $zero, $noPolicy, $noExp, $catVal, $user_id);
                        if (!$stmt->execute()) {
                            $err = $stmt->error;
                            $stmt->close();
                            throw new Exception($err);
                        }
                        $stmt->close();
                        $conn->commit();
                        $rowTxn = false;
                        $banksTouched[$bankId] = 1;
                        $inserted++;
                    } catch (Exception $ex) {
                        if ($rowTxn) { $conn->rollback(); $rowTxn = false; }
                        $errors[] = "Row $rowNum: " . $ex->getMessage();
                    }
                }

                // recompute each affected account once (rows already committed)
                foreach (array_keys($banksTouched) as $bk) recalcBankBalance($conn, $bk);

                logActivity($user_id, $username, 'Bulk Bank Txn Upload', "Uploaded $inserted transactions, " . count($errors) . " errors");
                $conn->close();

                $msg = "$inserted transactions uploaded successfully.";
                if (count($errors) > 0) $msg .= " " . count($errors) . " row(s) had errors.";
                echo json_encode(['success' => true, 'message' => $msg, 'inserted' => $inserted, 'errors' => $errors]);
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
                $bankAccId = (int)($_POST['bank_account_id'] ?? 0);
                $txnDate = trim($_POST['transaction_date'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $txnType = trim($_POST['transaction_type'] ?? '');
                $amt = (float)($_POST['amount'] ?? 0);
                $refNo = trim($_POST['reference_number'] ?? '');
                $category = trim($_POST['category'] ?? '');
                $policyId = !empty($_POST['policy_entry_id']) ? (int)$_POST['policy_entry_id'] : null;
                $expenseId = !empty($_POST['expense_id']) ? (int)$_POST['expense_id'] : null;
                $mode = btMode($_POST['payment_mode'] ?? '');
                $recon = isset($_POST['is_reconciled']) ? 1 : 0;
                if ($mode === false) { echo json_encode(['success' => false, 'message' => 'Invalid payment mode']); exit(); }

                if ($tid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid transaction ID']);
                    exit();
                }
                if ($bankAccId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Bank account is required']);
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
                if (!in_array($txnType, ['credit', 'debit'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid transaction type']);
                    exit();
                }
                if ($amt <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
                    exit();
                }

                $refNoVal = !empty($refNo) ? $refNo : null;
                $catVal = !empty($category) ? $category : null;

                $linkType = trim($_POST['link_type'] ?? '');
                $linkPartyId = 0;
                if ($linkType === 'agent') $linkPartyId = (int)($_POST['link_party_id_agent'] ?? 0);
                elseif ($linkType === 'buyer') $linkPartyId = (int)($_POST['link_party_id_buyer'] ?? 0);

                // fetch old row (account + any linked party leg)
                $stmt = $conn->prepare("SELECT bank_account_id, agent_id, buyer_id, link_txn_id, is_linked_mirror FROM bank_transactions WHERE id = ?");
                $stmt->bind_param("i", $tid);
                $stmt->execute();
                $oldTxn = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$oldTxn) {
                    echo json_encode(['success' => false, 'message' => 'Transaction not found']);
                    $conn->close();
                    exit();
                }
                // linked mirror rows are managed from the Agent/Buyer screen
                if ((int)$oldTxn['is_linked_mirror'] === 1) {
                    echo json_encode(['success' => false, 'message' => 'This entry is linked from an Agent/Buyer payment. Edit it on that screen.']);
                    $conn->close();
                    exit();
                }
                $oldAccId = (int)$oldTxn['bank_account_id'];
                $oldAgentId = (int)$oldTxn['agent_id'];
                $oldBuyerId = (int)$oldTxn['buyer_id'];
                $oldLinkTxnId = (int)$oldTxn['link_txn_id'];

                $conn->begin_transaction();
                try {
                    // drop any previous party leg; re-post fresh below
                    dropLinkedPartyMirror($conn, $oldAgentId, $oldBuyerId, $oldLinkTxnId);

                    // reconciled_at: keep first stamp while ticked, clear when unticked
                    $stmt = $conn->prepare("UPDATE bank_transactions SET bank_account_id=?, transaction_date=?, description=?, reference_number=?, transaction_type=?, amount=?, payment_mode=?, policy_entry_id=?, expense_id=?, category=?, reconciled_at=IF(? = 1, COALESCE(reconciled_at, NOW()), NULL), is_reconciled=?, updated_by=?, agent_id=NULL, buyer_id=NULL, link_txn_id=NULL WHERE id=?");
                    $stmt->bind_param("issssdsiisiiii", $bankAccId, $txnDate, $desc, $refNoVal, $txnType, $amt, $mode, $policyId, $expenseId, $catVal, $recon, $recon, $user_id, $tid);
                    $stmt->execute();
                    $stmt->close();

                    // re-post party leg if linked
                    if (($linkType === 'agent' || $linkType === 'buyer') && $linkPartyId > 0) {
                        list($pkind, $ptxn) = insertPartyMirrorForBank($conn, $linkType, $linkPartyId, $bankAccId, $tid, $txnDate, $txnType, $amt, $desc, $refNoVal, $user_id);
                        if ($ptxn > 0) {
                            $aId = $pkind === 'agent' ? $linkPartyId : null;
                            $bId = $pkind === 'buyer' ? $linkPartyId : null;
                            $lnk = $conn->prepare("UPDATE bank_transactions SET agent_id=?, buyer_id=?, link_txn_id=? WHERE id=?");
                            $lnk->bind_param("iiii", $aId, $bId, $ptxn, $tid);
                            $lnk->execute(); $lnk->close();
                            if ($pkind === 'agent') recalcAgentBalances($conn, $linkPartyId);
                            else recalcBuyerBalances($conn, $linkPartyId);
                        }
                    }

                    // recalc new account; if account changed, recalc old one too
                    recalcBankBalance($conn, $bankAccId);
                    if ($oldAccId !== $bankAccId) {
                        recalcBankBalance($conn, $oldAccId);
                    }

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update transaction']);
                    exit();
                }

                logActivity($user_id, $username, 'Bank Txn Updated', "Updated txn #$tid, account: $bankAccId, amt: $amt" . ($mode ? ", mode: $mode" : '') . ', ' . ($recon ? 'reconciled' : 'unreconciled'));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Transaction updated successfully']);
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

                // get account + any linked party leg before delete
                $stmt = $conn->prepare("SELECT bank_account_id, transaction_type, amount, agent_id, buyer_id, link_txn_id, is_linked_mirror FROM bank_transactions WHERE id = ?");
                $stmt->bind_param("i", $tid);
                $stmt->execute();
                $delRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$delRow) {
                    echo json_encode(['success' => false, 'message' => 'Transaction not found']);
                    exit();
                }
                // linked mirror rows are managed from the Agent/Buyer screen
                if ((int)$delRow['is_linked_mirror'] === 1) {
                    echo json_encode(['success' => false, 'message' => 'This entry is linked from an Agent/Buyer payment. Delete it on that screen.']);
                    $conn->close();
                    exit();
                }

                $accId = (int)$delRow['bank_account_id'];
                $delAgentId = (int)$delRow['agent_id'];
                $delBuyerId = (int)$delRow['buyer_id'];
                $delLinkTxnId = (int)$delRow['link_txn_id'];

                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("DELETE FROM bank_transactions WHERE id = ?");
                    $stmt->bind_param("i", $tid);
                    $stmt->execute();
                    $stmt->close();

                    // drop linked party leg too
                    dropLinkedPartyMirror($conn, $delAgentId, $delBuyerId, $delLinkTxnId);

                    // recalc rewrites running_balance + current_balance
                    recalcBankBalance($conn, $accId);

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete transaction']);
                    exit();
                }

                logActivity($user_id, $username, 'Bank Txn Deleted', "Deleted txn #$tid ({$delRow['transaction_type']}: {$delRow['amount']})");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Transaction deleted successfully']);
                exit();

            case 'setReconciled':
                // quick tick from the list - any row incl. linked mirrors (flag only, no balance change)
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'Invalid request']); exit(); }
                if ($role !== 'admin') { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
                $tid = (int)($_POST['id'] ?? 0);
                $recon = (int)($_POST['is_reconciled'] ?? 0) ? 1 : 0;
                if ($tid <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid transaction ID']); exit(); }
                $stmt = $conn->prepare("SELECT id FROM bank_transactions WHERE id = ?");
                $stmt->bind_param("i", $tid); $stmt->execute();
                $found = $stmt->get_result()->num_rows > 0; $stmt->close();
                if (!$found) { $conn->close(); echo json_encode(['success' => false, 'message' => 'Transaction not found']); exit(); }
                $stmt = $conn->prepare("UPDATE bank_transactions SET is_reconciled=?, reconciled_at=IF(? = 1, NOW(), NULL), updated_by=? WHERE id=?");
                $stmt->bind_param("iiii", $recon, $recon, $user_id, $tid);
                $stmt->execute(); $stmt->close();
                logActivity($user_id, $username, 'Bank Txn Reconciled', "Txn #$tid marked " . ($recon ? 'reconciled' : 'unreconciled'));
                $stmt = $conn->prepare("SELECT reconciled_at FROM bank_transactions WHERE id = ?");
                $stmt->bind_param("i", $tid); $stmt->execute();
                $at = $stmt->get_result()->fetch_assoc()['reconciled_at'] ?? null; $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'message' => $recon ? 'Marked reconciled' : 'Marked unreconciled', 'data' => ['is_reconciled' => $recon, 'reconciled_at' => $at]]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("bank_transactions.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
        exit();
    }
}

// balance recalc handled by recalcBankBalance() in config.php (single source of truth)

$isAdmin = ($role === 'admin');

// deep-link: pre-select bank account filter
$preBankId = isset($_GET['bank_account_id']) ? (int)$_GET['bank_account_id'] : 0;

// agents + buyers for the bank -> party link dropdowns
$linkAgents = []; $linkBuyers = [];
$cxnLP = getDBConnection();
$rsA = $cxnLP->query("SELECT id, name, agent_code FROM agents WHERE is_active = 1 ORDER BY name");
if ($rsA) { while ($ar = $rsA->fetch_assoc()) $linkAgents[] = $ar; }
$rsB = $cxnLP->query("SELECT id, company_name, company_code FROM buyers WHERE is_active = 1 ORDER BY company_name");
if ($rsB) { while ($br = $rsB->fetch_assoc()) $linkBuyers[] = $br; }
$cxnLP->close();
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
    <title>Bank Transactions - Insurance Management System</title>

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
            $pageTitle   = 'Bank Transactions';
            $crumbs      = [['label' => 'Accounting', 'icon' => 'fa-calculator']];
            $pageActions = [];
            if ($isAdmin) {
                $pageActions[] = ['label' => 'Add Transaction', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
                $pageActions[] = ['label' => 'Bulk Upload', 'icon' => 'fa-file-excel', 'onclick' => 'openBulkModal()'];
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
                <div class="hf-field"><label for="bankTxnSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="bankTxnSearch" placeholder="Search transactions…" autocomplete="off"></div>
                <div class="hf-field"><label for="filterBank" title="Bank Account"><i class="fas fa-university"></i></label><select id="filterBank" class="filter-input searchable">
                    <option value="">All Accounts</option>
                </select></div>
                <div class="hf-field"><label for="filterType" title="Type"><i class="fas fa-exchange-alt"></i></label><select id="filterType" class="filter-input searchable">
                    <option value="">All Types</option>
                    <option value="credit">Credit</option>
                    <option value="debit">Debit</option>
                </select></div>
                <div class="hf-field"><label for="filterCategory" title="Category"><i class="fas fa-tag"></i></label><select id="filterCategory" class="filter-input searchable">
                    <option value="">All Categories</option>
                    <option value="policy_payment">Policy Payment</option>
                    <option value="agent_payout">Agent Payout</option>
                    <option value="expense">Expense</option>
                    <option value="other">Other</option>
                </select></div>
                <div class="hf-field"><label for="filterRecon" title="Reconciliation"><i class="fas fa-check-double"></i></label><select id="filterRecon" class="filter-input searchable">
                    <option value="">All (Reconciled + Unreconciled)</option>
                    <option value="1">Reconciled</option>
                    <option value="0">Unreconciled</option>
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
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.6"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.4"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.6"></div>
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
                            <label><i class="fas fa-university"></i> Bank Account *</label>
                            <select id="txnBankAccount" name="bank_account_id" class="searchable" required>
                                <option value="">Select Account</option>
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
                            <select id="txnType" name="transaction_type" class="searchable" required>
                                <option value="">Select Type</option>
                                <option value="credit">Credit</option>
                                <option value="debit">Debit</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-rupee-sign"></i> Amount *</label>
                            <input type="number" id="txnAmount" name="amount" required min="0.01" step="0.01" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-check-alt"></i> Payment Mode</label>
                            <select id="txnPayMode" name="payment_mode" class="searchable">
                                <option value="">Select Mode</option>
                                <?php foreach (BT_MODES as $m): ?>
                                <option value="<?php echo $m; ?>"><?php echo $m; ?></option>
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
                            <label><i class="fas fa-tag"></i> Category</label>
                            <select id="txnCategory" name="category" class="searchable">
                                <option value="">None</option>
                                <option value="policy_payment">Policy Payment</option>
                                <option value="agent_payout">Agent Payout</option>
                                <option value="expense">Expense</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-file-alt"></i> Policy Entry ID</label>
                            <input type="number" id="txnPolicyId" name="policy_entry_id" placeholder="Link to policy (optional)">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-receipt"></i> Expense ID</label>
                            <input type="number" id="txnExpenseId" name="expense_id" placeholder="Link to expense (optional)">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-link"></i> Link To <small>(posts to their ledger)</small></label>
                            <select id="txnLinkType" name="link_type" class="searchable" onchange="handleLinkTypeChange()">
                                <option value="">&mdash; Not linked &mdash;</option>
                                <option value="agent">Agent</option>
                                <option value="buyer">Buyer</option>
                            </select>
                        </div>
                        <div class="form-group form-hidden" id="linkAgentGroup">
                            <label><i class="fas fa-user-tie"></i> Agent</label>
                            <select id="txnLinkAgent" name="link_party_id_agent" class="searchable">
                                <option value="">Select Agent</option>
                                <?php foreach ($linkAgents as $ag): ?>
                                <option value="<?php echo (int)$ag['id']; ?>"><?php echo htmlspecialchars($ag['name'] . ' (' . $ag['agent_code'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group form-hidden" id="linkBuyerGroup">
                            <label><i class="fas fa-building"></i> Buyer</label>
                            <select id="txnLinkBuyer" name="link_party_id_buyer" class="searchable">
                                <option value="">Select Buyer</option>
                                <?php foreach ($linkBuyers as $by): ?>
                                <option value="<?php echo (int)$by['id']; ?>"><?php echo htmlspecialchars($by['company_name'] . ' (' . $by['company_code'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="toggle-switch">
                            <input type="checkbox" class="toggle" id="txnReconciled" name="is_reconciled">
                            <label for="txnReconciled">Reconciled with bank statement</label>
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
                <h3><i class="fas fa-file-excel"></i> Bulk Upload Transactions</h3>
                <button class="close-btn" onclick="closeBulkModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom:16px;">
                    <p style="color:var(--text-muted);margin-bottom:10px;">Upload an Excel (.xlsx) or CSV file of bank transactions. <strong>Bank Account</strong> = an existing account's name or number. <strong>Type</strong> = <code>credit</code> or <code>debit</code>. Date = YYYY-MM-DD. <strong>Payment Mode</strong> (optional) = <?php echo implode(', ', BT_MODES); ?>.</p>
                    <button class="btn btn-secondary btn-sm" onclick="downloadBulkTemplate()"><i class="fas fa-download"></i> Download Template</button>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-file-upload"></i> Select File *</label>
                    <input type="file" id="bulkTxnFile" class="file-input" accept=".xlsx,.xls,.csv">
                </div>
                <div id="bulkPreview" style="display:none;margin-top:16px;">
                    <h4 style="color:var(--navy-primary);margin-bottom:8px;"><i class="fas fa-table"></i> Preview (<span id="bulkPreviewCount">0</span> rows)</h4>
                    <div style="overflow-x:auto;max-height:300px;overflow-y:auto;border:1px solid var(--border-color);border-radius:4px;">
                        <table class="preview-table" id="bulkPreviewTable"></table>
                    </div>
                </div>
                <div class="form-actions" style="margin-top:16px;">
                    <button class="btn btn-success" id="btnProcessBulk" onclick="processBulkUpload()" disabled><i class="fas fa-upload"></i> Upload &amp; Process</button>
                    <button class="btn btn-secondary" onclick="closeBulkModal()"><i class="fas fa-times"></i> Cancel</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        /** Developed by Rameez Scripts */
        const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        let txnTable = null;
        let txnData = [];
        let accountsList = [];
        let isEditMode = false;

        $(document).ready(function() {
            loadAccounts();
            loadTransactions();
        });

        // load bank accounts for filter + modal
        function loadAccounts() {
            $.ajax({
                url: '?action=getAccounts',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (!res.success) return;
                    accountsList = res.data;
                    const filterSel = $('#filterBank');
                    filterSel.find('option:not(:first)').remove();
                    res.data.forEach(function(a) {
                        const label = escapeHtml(a.bank_name + ' - ' + a.account_number);
                        filterSel.append('<option value="' + a.id + '">' + label + '</option>');
                    });

                    // modal dropdown
                    const modalSel = $('#txnBankAccount');
                    modalSel.find('option:not(:first)').remove();
                    res.data.forEach(function(a) {
                        const label = escapeHtml(a.bank_name + ' - ' + a.account_number);
                        modalSel.append('<option value="' + a.id + '">' + label + '</option>');
                    });
                    setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
                }
            });
        }

        // ---- bulk upload ----
        var bulkTxnRows = [];
        var bulkTplCols = ['bank_account','transaction_date','description','transaction_type','amount','reference_number','category','payment_mode'];

        function openBulkModal() {
            bulkTxnRows = [];
            $('#bulkTxnFile').val('');
            $('#bulkPreview').hide();
            $('#btnProcessBulk').prop('disabled', true);
            $('#bulkModal').addClass('active');
        }
        function closeBulkModal() { $('#bulkModal').removeClass('active'); }

        function downloadBulkTemplate() {
            var wb = XLSX.utils.book_new();
            // bank_account = existing account name or number; type = credit/debit; category optional
            var ws = XLSX.utils.aoa_to_sheet([bulkTplCols, ['HDFC Bank','2026-07-17','Cash deposit','credit','15000','REF-001','other','Cash']]);
            XLSX.utils.book_append_sheet(wb, ws, 'Transactions');
            XLSX.writeFile(wb, 'bank_txn_upload_template.xlsx');
        }

        // file change -> parse & preview
        $(document).on('change', '#bulkTxnFile', function() {
            var file = this.files[0];
            if (!file) return;
            var reader = new FileReader();
            reader.onload = function(e) {
                try {
                    var wb = XLSX.read(e.target.result, { type: 'array', cellDates: true });
                    var ws = wb.Sheets[wb.SheetNames[0]];
                    var json = XLSX.utils.sheet_to_json(ws, { raw: false, defval: '' });
                    if (!json.length) { Swal.fire({ icon: 'warning', title: 'Empty File', text: 'No data rows found' }); return; }
                    bulkTxnRows = json;
                    $('#bulkPreviewCount').text(json.length);
                    var headers = Object.keys(json[0]);
                    var html = '<thead><tr>';
                    headers.forEach(function(hh) { html += '<th>' + escapeHtml(hh) + '</th>'; });
                    html += '</tr></thead><tbody>';
                    json.slice(0, 10).forEach(function(r) {
                        html += '<tr>';
                        headers.forEach(function(hh) { html += '<td>' + escapeHtml(r[hh] || '') + '</td>'; });
                        html += '</tr>';
                    });
                    if (json.length > 10) html += '<tr><td colspan="' + headers.length + '" style="text-align:center;color:var(--text-muted);">... and ' + (json.length - 10) + ' more rows</td></tr>';
                    html += '</tbody>';
                    $('#bulkPreviewTable').html(html);
                    $('#bulkPreview').show();
                    $('#btnProcessBulk').prop('disabled', false);
                } catch (err) {
                    Swal.fire({ icon: 'error', title: 'Parse Error', text: 'Could not read file: ' + err.message });
                }
            };
            reader.readAsArrayBuffer(file);
        });

        function processBulkUpload() {
            if (!bulkTxnRows.length) return;
            showLoading('Processing ' + bulkTxnRows.length + ' rows...');
            var fd = new FormData();
            fd.append('rows', JSON.stringify(bulkTxnRows));
            fd.append('csrf_token', window.CSRF_TOKEN);
            $.ajax({
                url: '?action=bulkUpload',
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    hideLoading();
                    if (res.success) {
                        var errHtml = '';
                        if (res.errors && res.errors.length > 0) {
                            errHtml = '<div style="max-height:200px;overflow-y:auto;text-align:left;font-size:13px;margin-top:10px;background:#fff3cd;padding:10px;border-radius:4px;">';
                            res.errors.forEach(function(e) { errHtml += '<div style="margin-bottom:4px;color:#856404;"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(e) + '</div>'; });
                            errHtml += '</div>';
                        }
                        Swal.fire({ icon: res.inserted > 0 ? 'success' : 'warning', title: res.inserted > 0 ? 'Upload Complete' : 'Upload Issues', html: '<p>' + res.message + '</p>' + errHtml });
                        if (res.inserted > 0) { closeBulkModal(); loadAccounts(); loadTransactions(); }
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                    }
                },
                error: function() { hideLoading(); Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        }

        // close bulk modal on outside click
        $(document).on('click', '#bulkModal', function(e) { if (e.target === this) closeBulkModal(); });

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

        function fmtCategory(cat) {
            if (!cat) return '<em style="color:#999;">-</em>';
            const map = { policy_payment: 'Policy Payment', agent_payout: 'Agent Payout', buyer_payment: 'Buyer Payment', expense: 'Expense', other: 'Other' };
            return map[cat] || cat;
        }

        // view txn detail modal
        function viewTxn(d) {
            var typeBadge = d.transaction_type === 'credit'
                ? '<span class="status-badge status-active">Credit</span>'
                : '<span class="status-badge status-inactive">Debit</span>';
            var policy = d.policy_entry_id
                ? '<a href="policy_entries.php?id=' + d.policy_entry_id + '" title="Open policy"><span class="badge-code"><i class="fas fa-file-alt"></i> Policy #' + d.policy_entry_id + '</span></a>'
                : '-';
            var expense = d.expense_id
                ? '<a href="expenses.php" title="Open expenses"><span class="badge-code"><i class="fas fa-receipt"></i> Expense #' + d.expense_id + '</span></a>'
                : '-';
            var html = '<table class="view-detail-table">';
            html += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">' + escapeHtml(d.id) + '</td>';
            html += '<td class="vd-label"><i class="fas fa-calendar"></i> Date</td><td class="vd-value">' + escapeHtml(d.transaction_date || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-university"></i> Account</td><td class="vd-value">' + escapeHtml((d.bank_name || '-') + ' - ' + (d.account_number || '')) + '</td>';
            html += '<td class="vd-label"><i class="fas fa-exchange-alt"></i> Type</td><td class="vd-value">' + typeBadge + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-rupee-sign"></i> Amount</td><td class="vd-value">' + fmtAmount(d.amount) + '</td>';
            html += '<td class="vd-label"><i class="fas fa-balance-scale"></i> Balance</td><td class="vd-value">' + fmtAmount(d.running_balance) + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> Reference</td><td class="vd-value">' + escapeHtml(d.reference_number || '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-tag"></i> Category</td><td class="vd-value">' + fmtCategory(d.category) + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-file-alt"></i> Policy</td><td class="vd-value">' + policy + '</td>';
            html += '<td class="vd-label"><i class="fas fa-receipt"></i> Expense</td><td class="vd-value">' + expense + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-money-check-alt"></i> Payment Mode</td><td class="vd-value">' + escapeHtml(d.payment_mode || '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-check-double"></i> Reconciled</td><td class="vd-value">' + (d.is_reconciled ? '<span class="status-badge status-active">Reconciled</span>' + (d.reconciled_at ? ' <small>' + escapeHtml(d.reconciled_at) + '</small>' : '') : '<span class="status-badge status-pending">Unreconciled</span>') + '</td></tr>';
            if (d.linked_agent_name || d.linked_buyer_name) {
                var pn = d.linked_agent_name ? ('<i class="fas fa-user-tie"></i> ' + escapeHtml(d.linked_agent_name)) : ('<i class="fas fa-building"></i> ' + escapeHtml(d.linked_buyer_name));
                html += '<tr><td class="vd-label"><i class="fas fa-link"></i> Linked Ledger</td><td class="vd-value" colspan="3"><span class="badge-code">' + pn + '</span></td></tr>';
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

            let cols = [
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
                    data: 'bank_name',
                    title: 'Account',
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
                    data: 'reference_number',
                    title: 'Reference',
                    render: function(d) { return d ? escapeHtml(d) : '<em style="color:#999;">-</em>'; }
                },
                {
                    data: 'transaction_type',
                    title: 'Type',
                    render: function(d) {
                        if (d === 'credit') return '<span class="status-badge status-active">Credit</span>';
                        return '<span class="status-badge status-inactive">Debit</span>';
                    }
                },
                {
                    data: 'amount',
                    title: 'Amount',
                    render: function(d) { return '<span class="wallet-amount">' + fmtAmount(d) + '</span>'; }
                },
                // quick reconcile toggle (admin), pill for others
                {
                    data: 'is_reconciled',
                    title: 'Reconciled',
                    render: function(d, type, row) {
                        if (type !== 'display') return d ? 'Reconciled' + (row.reconciled_at ? ' ' + row.reconciled_at : '') : 'Unreconciled';
                        var tip = d ? 'Reconciled' + (row.reconciled_at ? ' on ' + row.reconciled_at : '') : 'Mark reconciled';
                        if (IS_ADMIN) return '<input type="checkbox" class="toggle recon-toggle" data-id="' + row.id + '"' + (d ? ' checked' : '') + ' title="' + escapeHtml(tip) + '" aria-label="' + escapeHtml(tip) + '">';
                        return d ? '<span class="status-badge status-active" title="' + escapeHtml(tip) + '">Yes</span>' : '<span class="status-badge status-pending">No</span>';
                    }
                },
                {
                    data: 'running_balance',
                    title: 'Balance',
                    render: function(d) { return '<span class="wallet-amount">' + fmtAmount(d) + '</span>'; }
                },
                {
                    data: 'category',
                    title: 'Category',
                    render: function(d, t, row) {
                        var c = fmtCategory(d);
                        var party = row.linked_agent_name
                            ? '<span class="badge-code" title="Posted to agent ledger"><i class="fas fa-user-tie"></i> ' + escapeHtml(row.linked_agent_name) + '</span>'
                            : (row.linked_buyer_name
                                ? '<span class="badge-code" title="Posted to buyer ledger"><i class="fas fa-building"></i> ' + escapeHtml(row.linked_buyer_name) + '</span>'
                                : '');
                        return party ? (c + ' ' + party) : c;
                    }
                }
            ];

            cols.push({ data: 'payment_mode', title: 'Mode', visible: false, render: function(d) { return escapeHtml(d || '-'); } });
            // Policy / Expense FK link cols
            cols.push({
                data: 'policy_entry_id',
                title: 'Policy',
                render: function(d) {
                    return d
                        ? '<a href="policy_entries.php?id=' + d + '" title="Open policy"><span class="badge-code"><i class="fas fa-file-alt"></i> Policy #' + d + '</span></a>'
                        : '<em style="color:#999;">-</em>';
                }
            });
            cols.push({
                data: 'expense_id',
                title: 'Expense',
                render: function(d) {
                    return d
                        ? '<a href="expenses.php" title="Open expenses"><span class="badge-code"><i class="fas fa-receipt"></i> Expense #' + d + '</span></a>'
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
                            '<button class="more-item more-recon" data-id="' + row.id + '" data-on="' + (row.is_reconciled ? 0 : 1) + '"><i class="fas ' + (row.is_reconciled ? 'fa-rotate-left' : 'fa-check-double') + '"></i> ' + (row.is_reconciled ? 'Mark unreconciled' : 'Mark reconciled') + '</button>' +
                            '<button class="more-item more-edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> Edit</button>' +
                            '<div class="more-divider"></div>' +
                            '<button class="more-item danger more-del" data-id="' + row.id + '"><i class="fas fa-trash"></i> Delete</button>' +
                            '</div></div>';
                    } else if (IS_ADMIN && row.is_linked_mirror == 1) {
                        var origin = row.agent_id ? 'agent_transactions.php' : 'buyer_transactions.php';
                        more = '<a class="action-icon" href="' + origin + '" title="Linked from an Agent/Buyer payment — manage it there"><i class="fas fa-link"></i></a>';
                    }
                    return '<div class="action-hub">' +
                        '<button class="action-icon view-icon act-view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button>' +
                        more +
                    '</div>';
                }
            });

            txnTable = rsTable('#txnTable', {
                columns: cols, data: data, title: 'Bank Transactions', file: 'bank_transactions', sort: ['id', 'desc'],
                // credit = green, debit = red
                rowClass: function(r) { return r.transaction_type === 'credit' ? 'dt-row-success' : (r.transaction_type === 'debit' ? 'dt-row-danger' : ''); },
                info: function() {
                    var b = document.getElementById('filterBank');
                    return [['Account', b && b.value ? b.options[b.selectedIndex].text : 'All'],
                        ['From', $('#filterDateFrom').val() || '-'], ['To', $('#filterDateTo').val() || '-'],
                        ['Type', $('#filterType').val() || 'All'], ['Reconciled', $('#filterRecon').val() === '' ? 'All' : ($('#filterRecon').val() === '1' ? 'Yes' : 'No')], ['Category', $('#filterCategory').val() ? fmtCategory($('#filterCategory').val()) : 'All']];
                }
            });
            rsTbSearch(txnTable, $('#bankTxnSearch').val()); // keep head search

            // bind filters
            $('#filterBank, #filterType, #filterCategory, #filterRecon').off('change').on('change', applyFilters);
            $('#filterDateFrom, #filterDateTo').off('change').on('change', applyFilters);

            // deep-link pre-filter
            <?php if ($preBankId > 0): ?>
            if (!window._bankPrefilled) {
                window._bankPrefilled = true;
                $('#filterBank').val('<?php echo $preBankId; ?>');
                var sd = document.getElementById('filterBank');
                if (sd && sd.sdRefresh) sd.sdRefresh();
            }
            <?php endif; ?>
            applyFilters();
        }

        function applyFilters() {
            var dateFrom = $('#filterDateFrom').val(), dateTo = $('#filterDateTo').val();
            rsTbWhere(txnTable, 'date', dateFrom || dateTo ? function(r) {
                var d = r.transaction_date ? r.transaction_date.substring(0, 10) : '';
                return !(dateFrom && d < dateFrom) && !(dateTo && d > dateTo);
            } : null);
            var bank = $('#filterBank').val(), type = $('#filterType').val(), cat = $('#filterCategory').val();
            rsTbWhere(txnTable, 'bank', bank ? function(r) { return String(r.bank_account_id) === String(bank); } : null);
            rsTbWhere(txnTable, 'type', type ? function(r) { return r.transaction_type === type; } : null);
            rsTbWhere(txnTable, 'cat', cat ? function(r) { return r.category === cat; } : null);
            var rec = $('#filterRecon').val();
            rsTbWhere(txnTable, 'recon', rec !== '' && rec != null ? function(r) { return String(r.is_reconciled) === rec; } : null);
        }

        function clearFilters() {
            $('#filterBank').val('');
            $('#filterDateFrom').val('');
            $('#filterDateTo').val('');
            $('#filterType').val('');
            $('#filterCategory').val('');
            $('#filterRecon').val('');
            $('#bankTxnSearch').val('');
            ['filterBank', 'filterType', 'filterCategory', 'filterRecon'].forEach(function(id) { var el = document.getElementById(id); if (el && el.sdSetVal) el.sdSetVal(''); });
            $('.hf-seg button').removeClass('is-on');
            rsTbClear(txnTable);
        }

        // header search -> table
        $('#bankTxnSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(txnTable, v); },200); });

        <?php if ($isAdmin): ?>
        function handleLinkTypeChange() {
            var lt = $('#txnLinkType').val();
            $('#linkAgentGroup').toggleClass('form-hidden', lt !== 'agent');
            $('#linkBuyerGroup').toggleClass('form-hidden', lt !== 'buyer');
            if (lt !== 'agent') $('#txnLinkAgent').val('');
            if (lt !== 'buyer') $('#txnLinkBuyer').val('');
            document.querySelectorAll('#linkAgentGroup select, #linkBuyerGroup select').forEach(function(s){ if (s.sdRefresh) s.sdRefresh(); });
        }

        function openAddModal() {
            isEditMode = false;
            $('#modalTitle').html('<i class="fas fa-plus-circle"></i> Add Transaction');
            $('#txnForm')[0].reset();
            $('#txnId').val('');
            // default today
            $('#txnDate').val(new Date().toISOString().substring(0, 10));
            $('#txnReconciled').prop('checked', false);
            handleLinkTypeChange();
            document.querySelectorAll('#txnForm select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
            $('#txnModal').addClass('active');
        }

        function editTxn(row) {
            isEditMode = true;
            $('#modalTitle').html('<i class="fas fa-edit"></i> Edit Transaction');
            $('#txnId').val(row.id);
            $('#txnBankAccount').val(row.bank_account_id);
            $('#txnDate').val(row.transaction_date ? row.transaction_date.substring(0, 10) : '');
            $('#txnDesc').val(row.description);
            $('#txnType').val(row.transaction_type);
            $('#txnAmount').val(row.amount);
            $('#txnRefNo').val(row.reference_number || '');
            $('#txnCategory').val(row.category || '');
            $('#txnPolicyId').val(row.policy_entry_id || '');
            $('#txnExpenseId').val(row.expense_id || '');
            $('#txnPayMode').val(row.payment_mode || '');
            $('#txnReconciled').prop('checked', !!row.is_reconciled);
            // linked party
            var lt = row.agent_id ? 'agent' : (row.buyer_id ? 'buyer' : '');
            $('#txnLinkType').val(lt);
            $('#txnLinkAgent').val(row.agent_id || '');
            $('#txnLinkBuyer').val(row.buyer_id || '');
            handleLinkTypeChange();
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

            var formData = new FormData(this);
            formData.set('csrf_token', window.CSRF_TOKEN);
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

        // quick reconcile - list toggle or ⋮ item; el = toggle to roll back on failure
        function setRecon(id, on, el) {
            if (el) el.disabled = true;
            showLoading(on ? 'Marking reconciled...' : 'Clearing reconciliation...');
            $.ajax({ url: '?action=setReconciled', method: 'POST', dataType: 'json',
                data: { csrf_token: window.CSRF_TOKEN, id: id, is_reconciled: on },
                success: function(res) {
                    if (!res.success) { if (el) el.checked = !on; Swal.fire({ icon: 'error', title: 'Error', text: res.message }); return; }
                    var row = txnData.find(function(r) { return r.id === id; });
                    if (row) { row.is_reconciled = res.data.is_reconciled; row.reconciled_at = res.data.reconciled_at; rsTbData(txnTable, txnData); }
                },
                error: function() { if (el) el.checked = !on; Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); },
                complete: function() { hideLoading(); if (el) el.disabled = false; }
            });
        }
        $('#txnTable').on('change', '.recon-toggle', function() { setRecon(parseInt(this.getAttribute('data-id'), 10), this.checked ? 1 : 0, this); });
        $('#txnTable').on('click', '.more-recon', function() { setRecon(parseInt(this.getAttribute('data-id'), 10), parseInt(this.getAttribute('data-on'), 10), null); });

        function deleteTxn(tid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Transaction?',
                text: 'This will recalculate all running balances',
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

            var accountSel = document.getElementById('filterBank');
            var accountName = (accountSel && accountSel.value) ? (accountSel.options[accountSel.selectedIndex]?.text || 'All Accounts') : 'All Accounts';
            // running balance only meaningful for a single account
            if (!accountSel || !accountSel.value) {
                Swal.fire({ icon: 'warning', title: 'Select an Account', text: 'Please choose a specific bank account before printing a statement.' });
                return;
            }
            var dateFrom = document.getElementById('filterDateFrom')?.value || '';
            var dateTo = document.getElementById('filterDateTo')?.value || '';

            var typeLbl = function(r) { return String(r.transaction_type || '').replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); }); };
            var selTxt = function(id) { var e = document.getElementById(id); return e && e.value ? e.options[e.selectedIndex].text : ''; };
            rsStatement(kind || 'print', {
                title: 'Bank Statement', number: 'BKS-' + rsStamp(),
                party: { label: 'Statement for', name: accountName, lines: (function() {
                    var a = accountsList.find(function(x) { return String(x.id) === String(accountSel.value); });
                    return a && a.opening_balance_date ? ['Opening balance ' + rsMoney(a.opening_balance) + ' as of ' + rsPaperDate(a.opening_balance_date)] : [];
                })() },
                period: (dateFrom ? rsPaperDate(dateFrom) : 'Start') + ' to ' + (dateTo ? rsPaperDate(dateTo) : 'Today'),
                rows: rows, file: 'bank_statement',
                filters: [selTxt('filterType') ? 'Type: ' + selTxt('filterType') : '', selTxt('filterCategory') ? 'Category: ' + selTxt('filterCategory') : '', $('#bankTxnSearch').val() ? 'Search "' + $('#bankTxnSearch').val() + '"' : ''].filter(Boolean),
                cr: function(r) { return r.transaction_type === 'credit' ? parseFloat(r.amount) || 0 : 0; },
                dr: function(r) { return r.transaction_type === 'debit' ? parseFloat(r.amount) || 0 : 0; },
                bal: function(r) { return parseFloat(r.running_balance) || 0; },
                desc: function(r) { return r.description || typeLbl(r); },
                sub: function(r) { return r.reference_number ? 'Ref: ' + r.reference_number : ''; }
            });
        }
    </script>
</body>
</html>
