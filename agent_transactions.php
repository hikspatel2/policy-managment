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
$current_page = 'agent_transactions';

$isAdmin = ($role === 'admin');
$isAgent = ($role === 'agent');

// deep-link: pre-select agent filter
$preAgentId = (!$isAgent && isset($_GET['agent_id'])) ? (int)$_GET['agent_id'] : 0;

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getAgents':
                // admin/manager only
                if ($isAgent) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }
                $stmt = $conn->prepare("SELECT id, agent_code, name, tds_rate FROM agents WHERE is_active = 1 ORDER BY name");
                $stmt->execute();
                $res = $stmt->get_result();
                $agents = [];
                while ($row = $res->fetch_assoc()) {
                    $agents[] = ['id' => (int)$row['id'], 'agent_code' => $row['agent_code'], 'name' => $row['name'], 'tds_rate' => (float)$row['tds_rate']];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $agents]);
                exit();

            case 'getAgentInfo':
                // agent role - get own agent record
                if (!$isAgent) {
                    echo json_encode(['success' => false, 'message' => 'Not applicable']);
                    exit();
                }
                $stmt = $conn->prepare("SELECT id, agent_code, name, wallet_balance, outstanding_balance FROM agents WHERE user_id = ?");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $agent = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $conn->close();
                if (!$agent) {
                    echo json_encode(['success' => false, 'message' => 'Agent profile not found']);
                    exit();
                }
                echo json_encode(['success' => true, 'data' => [
                    'id' => (int)$agent['id'],
                    'agent_code' => $agent['agent_code'],
                    'name' => $agent['name'],
                    'wallet_balance' => (float)$agent['wallet_balance'],
                    'outstanding_balance' => (float)$agent['outstanding_balance']
                ]]);
                exit();

            case 'getTransactions':
                if ($isAgent) {
                    // agent sees own only
                    $stmt = $conn->prepare("
                        SELECT at.id, at.agent_id, DATE_FORMAT(at.transaction_date, '%Y-%m-%d') as transaction_date,
                               at.description, at.transaction_type, at.credit_amount, at.debit_amount, at.tds_amount,
                               at.running_balance, at.payment_mode, at.reference_number,
                                   at.bank_account_id, at.bank_txn_id, at.is_linked_mirror,
                                   (SELECT bank_name FROM bank_accounts WHERE id = at.bank_account_id) as linked_bank_name,
                               DATE_FORMAT(at.created_at, '%b %d, %Y %h:%i %p') as created_at,
                               a.name as agent_name, a.agent_code
                        FROM agent_transactions at
                        JOIN agents a ON at.agent_id = a.id
                        WHERE at.agent_id = (SELECT id FROM agents WHERE user_id = ?)
                        ORDER BY at.id DESC
                    ");
                    $stmt->bind_param("i", $user_id);
                } else {
                    // admin/manager - optional agent filter
                    $agentFilter = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
                    if ($agentFilter > 0) {
                        $stmt = $conn->prepare("
                            SELECT at.id, at.agent_id, DATE_FORMAT(at.transaction_date, '%Y-%m-%d') as transaction_date,
                                   at.description, at.transaction_type, at.credit_amount, at.debit_amount, at.tds_amount,
                                   at.running_balance, at.payment_mode, at.reference_number,
                                   at.bank_account_id, at.bank_txn_id, at.is_linked_mirror,
                                   (SELECT bank_name FROM bank_accounts WHERE id = at.bank_account_id) as linked_bank_name,
                                   DATE_FORMAT(at.created_at, '%b %d, %Y %h:%i %p') as created_at,
                                   a.name as agent_name, a.agent_code
                            FROM agent_transactions at
                            JOIN agents a ON at.agent_id = a.id
                            WHERE at.agent_id = ?
                            ORDER BY at.id DESC
                        ");
                        $stmt->bind_param("i", $agentFilter);
                    } else {
                        $stmt = $conn->prepare("
                            SELECT at.id, at.agent_id, DATE_FORMAT(at.transaction_date, '%Y-%m-%d') as transaction_date,
                                   at.description, at.transaction_type, at.credit_amount, at.debit_amount, at.tds_amount,
                                   at.running_balance, at.payment_mode, at.reference_number,
                                   at.bank_account_id, at.bank_txn_id, at.is_linked_mirror,
                                   (SELECT bank_name FROM bank_accounts WHERE id = at.bank_account_id) as linked_bank_name,
                                   DATE_FORMAT(at.created_at, '%b %d, %Y %h:%i %p') as created_at,
                                   a.name as agent_name, a.agent_code
                            FROM agent_transactions at
                            JOIN agents a ON at.agent_id = a.id
                            ORDER BY at.id DESC
                        ");
                    }
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $txns = [];
                while ($row = $res->fetch_assoc()) {
                    $txns[] = [
                        'id' => (int)$row['id'],
                        'agent_id' => (int)$row['agent_id'],
                        'transaction_date' => $row['transaction_date'],
                        'description' => $row['description'],
                        'transaction_type' => $row['transaction_type'],
                        'credit_amount' => (float)$row['credit_amount'],
                        'debit_amount' => (float)$row['debit_amount'],
                        'tds_amount' => (float)$row['tds_amount'],
                        'running_balance' => (float)$row['running_balance'],
                        'payment_mode' => $row['payment_mode'],
                        'reference_number' => $row['reference_number'],
                        'bank_account_id' => $row['bank_account_id'] ? (int)$row['bank_account_id'] : null,
                        'bank_txn_id' => $row['bank_txn_id'] ? (int)$row['bank_txn_id'] : null,
                        'is_linked_mirror' => (int)$row['is_linked_mirror'],
                        'linked_bank_name' => $row['linked_bank_name'],
                        'agent_name' => $row['agent_name'],
                        'agent_code' => $row['agent_code'],
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
                if (!$isAdmin) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $agent_id = (int)($_POST['agent_id'] ?? 0);
                $txn_date = trim($_POST['transaction_date'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $txn_type = trim($_POST['transaction_type'] ?? '');
                $credit = (float)($_POST['credit_amount'] ?? 0);
                $debit = (float)($_POST['debit_amount'] ?? 0);
                $pay_mode = trim($_POST['payment_mode'] ?? '');
                $ref_num = trim($_POST['reference_number'] ?? '');
                $bankAccId = (int)($_POST['bank_account_id'] ?? 0);
                $tds = round((float)($_POST['tds_amount'] ?? 0), 2);

                // validate
                if ($agent_id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Agent is required']);
                    exit();
                }
                if (empty($txn_date)) {
                    echo json_encode(['success' => false, 'message' => 'Transaction date is required']);
                    exit();
                }
                if (empty($desc) || strlen($desc) > 500) {
                    echo json_encode(['success' => false, 'message' => 'Description is required (max 500 chars)']);
                    exit();
                }
                $validTypes = ['commission', 'payment_in', 'payment_out', 'adjustment'];
                if (!in_array($txn_type, $validTypes)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid transaction type']);
                    exit();
                }
                if ($credit < 0 || $debit < 0) {
                    echo json_encode(['success' => false, 'message' => 'Amounts cannot be negative']);
                    exit();
                }
                if ($credit == 0 && $debit == 0) {
                    echo json_encode(['success' => false, 'message' => 'Either credit or debit amount is required']);
                    exit();
                }

                // auto-set credit/debit based on type
                if ($txn_type === 'commission' || $txn_type === 'payment_in') {
                    $debit = 0;
                } elseif ($txn_type === 'payment_out') {
                    $credit = 0;
                }
                // adjustment: user can set either
                if ($e = tdsError($tds, $debit, 'payment_out', $txn_type)) {
                    echo json_encode(['success' => false, 'message' => $e]);
                    exit();
                }

                $payModeVal = !empty($pay_mode) ? $pay_mode : null;
                $refNumVal = !empty($ref_num) ? $ref_num : null;

                $conn->begin_transaction();
                try {
                    // running_balance set by recalc; insert 0 placeholder
                    $zero = 0.00;
                    $stmt = $conn->prepare("
                        INSERT INTO agent_transactions (agent_id, transaction_date, description, transaction_type, credit_amount, debit_amount, tds_amount, running_balance, payment_mode, reference_number, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("isssddddssi", $agent_id, $txn_date, $desc, $txn_type, $credit, $debit, $tds, $zero, $payModeVal, $refNumVal, $user_id);
                    $stmt->execute();
                    $insertId = $stmt->insert_id;
                    $stmt->close();

                    // bank leg for real cash payments (payment_in/out)
                    $dir = agentBankDir($txn_type);
                    if ($bankAccId > 0 && $dir !== null) {
                        $amt = (($dir === 'credit') ? $credit : $debit) - $tds; // bank = net of TDS
                        $bankTxnId = insertBankMirror($conn, $bankAccId, 'agent', $agent_id, $insertId, $txn_date, $dir, $amt, bankLegDesc($desc, $tds), $refNumVal, $user_id);
                        $lnk = $conn->prepare("UPDATE agent_transactions SET bank_account_id=?, bank_txn_id=? WHERE id=?");
                        $lnk->bind_param("iii", $bankAccId, $bankTxnId, $insertId);
                        $lnk->execute(); $lnk->close();
                        recalcBankBalance($conn, $bankAccId);
                    }

                    // recalc rewrites running_balance + wallet/outstanding
                    recalcAgentBalances($conn, $agent_id);

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add transaction']);
                    exit();
                }

                // get agent name for log
                $stmt = $conn->prepare("SELECT name FROM agents WHERE id = ?");
                $stmt->bind_param("i", $agent_id);
                $stmt->execute();
                $agentRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $agentName = $agentRow ? $agentRow['name'] : 'Unknown';

                logActivity($user_id, $username, 'Agent Txn Added', "Added $txn_type for $agentName: Cr=$credit, Dr=$debit" . ($tds > 0 ? ", TDS=$tds" : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Transaction added successfully', 'data' => ['id' => $insertId]]);
                exit();

            case 'bulkAddAgentTxns':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if (!$isAdmin) { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows) || count($rows) === 0) { echo json_encode(['success'=>false,'message'=>'No transactions to add']); exit(); }
                $off = max(0, (int)($_POST['row_offset'] ?? 0)); // client posts in chunks - keep row numbers global

                // agent by code, then by name (name only when unique)
                $agentMap = []; $nameCount = [];
                $res = $conn->query("SELECT id, agent_code, name FROM agents");
                while ($a = $res->fetch_assoc()) {
                    $aid = (int)$a['id'];
                    $agentMap[strtolower(trim($a['agent_code']))] = $aid;
                    $nk = strtolower(trim($a['name']));
                    $nameCount[$nk] = ($nameCount[$nk] ?? 0) + 1;
                    if ($nameCount[$nk] === 1 && !isset($agentMap[$nk])) $agentMap[$nk] = $aid;
                }

                // bank: account number and "bank - number" always resolve; bare bank name only when unique
                $bankMap = []; $bankNameCount = [];
                $res = $conn->query("SELECT id, bank_name, account_number FROM bank_accounts WHERE is_active = 1");
                while ($b = $res->fetch_assoc()) {
                    $bid = (int)$b['id']; $bn = strtolower(trim($b['bank_name'])); $an = strtolower(trim($b['account_number']));
                    if ($an !== '') $bankMap[$an] = $bid;
                    $bankMap[$bn . ' - ' . $an] = $bid;
                    $bankNameCount[$bn] = ($bankNameCount[$bn] ?? 0) + 1;
                    if ($bankNameCount[$bn] === 1) $bankMap[$bn] = $bid;
                }

                $inserted = 0; $errors = []; $touchedAgents = []; $touchedBanks = [];
                foreach ($rows as $idx => $r) {
                    $line = $off + $idx + 1;
                    $txnDate = trim($r['transaction_date'] ?? '');
                    $agentRaw = trim($r['agent'] ?? '');
                    $desc = trim($r['description'] ?? '');
                    $type = strtolower(trim($r['transaction_type'] ?? ''));
                    $credit = (float)($r['credit_amount'] ?? 0);
                    $debit = (float)($r['debit_amount'] ?? 0);
                    $payMode = trim($r['payment_mode'] ?? '') ?: null;
                    $bankRaw = trim($r['bank_account'] ?? '');
                    $refNo = trim($r['reference_number'] ?? '') ?: null;
                    $tds = round((float)($r['tds_amount'] ?? 0), 2);

                    if ($agentRaw === '' && $desc === '' && $credit == 0 && $debit == 0) continue; // trailing blank line

                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnDate)) { $errors[] = "Row $line: date must be YYYY-MM-DD"; continue; }
                    $ak = strtolower($agentRaw);
                    if ($agentRaw === '' || !isset($agentMap[$ak])) { $errors[] = "Row $line: agent '$agentRaw' not found"; continue; }
                    if (($nameCount[$ak] ?? 0) > 1) { $errors[] = "Row $line: '$agentRaw' matches several agents — use the agent code"; continue; }
                    if ($desc === '' || strlen($desc) > 500) { $errors[] = "Row $line: description is required (max 500 chars)"; continue; }
                    if (!in_array($type, ['commission', 'payment_in', 'payment_out', 'adjustment'], true)) { $errors[] = "Row $line: invalid type '$type'"; continue; }
                    if ($credit < 0 || $debit < 0) { $errors[] = "Row $line: amounts cannot be negative"; continue; }
                    if ($credit == 0 && $debit == 0) { $errors[] = "Row $line: either credit or debit is required"; continue; }

                    // type fixes the side, same rules as the single add
                    if ($type === 'commission' || $type === 'payment_in') $debit = 0;
                    elseif ($type === 'payment_out') $credit = 0;
                    if ($credit == 0 && $debit == 0) { $errors[] = "Row $line: '$type' needs an amount on the " . ($type === 'payment_out' ? 'debit' : 'credit') . " side"; continue; }
                    if ($e = tdsError($tds, $debit, 'payment_out', $type)) { $errors[] = "Row $line: $e"; continue; }

                    $dir = agentBankDir($type);
                    $bankId = 0;
                    if ($bankRaw !== '' && $dir !== null) {
                        $bk = strtolower($bankRaw);
                        if (!isset($bankMap[$bk])) { $errors[] = "Row $line: bank account '$bankRaw' not found"; continue; }
                        if (($bankNameCount[$bk] ?? 0) > 1) { $errors[] = "Row $line: '$bankRaw' matches several accounts — use the account number"; continue; }
                        $bankId = $bankMap[$bk];
                    }

                    $agentId = $agentMap[$ak];
                    $conn->begin_transaction();
                    try {
                        $zero = 0.00;
                        $stmt = $conn->prepare("INSERT INTO agent_transactions (agent_id, transaction_date, description, transaction_type, credit_amount, debit_amount, tds_amount, running_balance, payment_mode, reference_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("isssddddssi", $agentId, $txnDate, $desc, $type, $credit, $debit, $tds, $zero, $payMode, $refNo, $user_id);
                        $stmt->execute(); $newId = (int)$stmt->insert_id; $stmt->close();

                        // bank leg for real cash movements, linked both ways like the single add
                        if ($bankId > 0 && $dir !== null) {
                            $amt = (($dir === 'credit') ? $credit : $debit) - $tds; // bank = net of TDS
                            $bankTxnId = insertBankMirror($conn, $bankId, 'agent', $agentId, $newId, $txnDate, $dir, $amt, bankLegDesc($desc, $tds), $refNo, $user_id);
                            $lnk = $conn->prepare("UPDATE agent_transactions SET bank_account_id=?, bank_txn_id=? WHERE id=?");
                            $lnk->bind_param("iii", $bankId, $bankTxnId, $newId);
                            $lnk->execute(); $lnk->close();
                            $touchedBanks[$bankId] = 1;
                        }

                        $conn->commit();
                        $touchedAgents[$agentId] = 1;
                        $inserted++;
                    } catch (Exception $ex) {
                        $conn->rollback();
                        $errors[] = "Row $line: failed to post '$desc'";
                    }
                }

                // recalc once per touched party/account, not per row
                foreach (array_keys($touchedAgents) as $aid) recalcAgentBalances($conn, $aid);
                foreach (array_keys($touchedBanks) as $bid) recalcBankBalance($conn, $bid);

                logActivity($user_id, $username, 'Bulk Agent Txn Add', "Posted $inserted agent transactions, " . count($errors) . " errors");
                $conn->close();
                $msg = "$inserted transaction(s) posted." . (count($touchedAgents) ? " " . count($touchedAgents) . " agent(s) recalculated." : "");
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
                $newAgentId = (int)($_POST['agent_id'] ?? 0);
                $txnDate = trim($_POST['transaction_date'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $txnType = trim($_POST['transaction_type'] ?? '');
                $credit = floatval($_POST['credit_amount'] ?? 0);
                $debit = floatval($_POST['debit_amount'] ?? 0);
                $payMode = trim($_POST['payment_mode'] ?? '') ?: null;
                $refNum = trim($_POST['reference_number'] ?? '') ?: null;
                $tds = round((float)($_POST['tds_amount'] ?? 0), 2);

                if ($tid <= 0 || empty($txnDate) || empty($desc) || empty($txnType)) {
                    echo json_encode(['success' => false, 'message' => 'Required fields missing']);
                    exit();
                }
                if ($e = tdsError($tds, $debit, 'payment_out', $txnType)) {
                    echo json_encode(['success' => false, 'message' => $e]);
                    exit();
                }

                $bankAccId = (int)($_POST['bank_account_id'] ?? 0);

                // get current row (agent + any linked bank leg)
                $stmt = $conn->prepare("SELECT agent_id, bank_txn_id, is_linked_mirror, credit_amount, debit_amount, tds_amount FROM agent_transactions WHERE id = ?");
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
                $oldAgentId = (int)$oldTxn['agent_id'];
                $oldBankTxnId = (int)$oldTxn['bank_txn_id'];
                // keep old agent if none passed
                $agentId = ($newAgentId > 0) ? $newAgentId : $oldAgentId;
                $dir = agentBankDir($txnType);

                $conn->begin_transaction();
                try {
                    // drop any previous bank leg; re-post fresh below
                    $oldMirrorAcc = $oldBankTxnId > 0 ? deleteBankMirror($conn, $oldBankTxnId) : 0;

                    $newBankAccId = ($bankAccId > 0 && $dir !== null) ? $bankAccId : null;

                    $stmt = $conn->prepare("UPDATE agent_transactions SET agent_id=?, transaction_date=?, description=?, transaction_type=?, credit_amount=?, debit_amount=?, tds_amount=?, payment_mode=?, reference_number=?, bank_account_id=?, bank_txn_id=NULL, updated_by=? WHERE id=?");
                    $stmt->bind_param("isssdddssiii", $agentId, $txnDate, $desc, $txnType, $credit, $debit, $tds, $payMode, $refNum, $newBankAccId, $user_id, $tid);
                    $stmt->execute();
                    $stmt->close();

                    // re-post bank leg for cash payments
                    if ($newBankAccId !== null) {
                        $amt = (($dir === 'credit') ? $credit : $debit) - $tds; // bank = net of TDS
                        $newBankTxnId = insertBankMirror($conn, $bankAccId, 'agent', $agentId, $tid, $txnDate, $dir, $amt, bankLegDesc($desc, $tds), $refNum, $user_id);
                        $lnk = $conn->prepare("UPDATE agent_transactions SET bank_txn_id=? WHERE id=?");
                        $lnk->bind_param("ii", $newBankTxnId, $tid);
                        $lnk->execute(); $lnk->close();
                    }

                    // recalc agent(s)
                    recalcAgentBalances($conn, $agentId);
                    if ($oldAgentId !== $agentId) recalcAgentBalances($conn, $oldAgentId);
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

                logActivity($user_id, $username, 'Agent Transaction Updated', "Updated transaction #$tid: Cr={$oldTxn['credit_amount']}->$credit, Dr={$oldTxn['debit_amount']}->$debit, TDS={$oldTxn['tds_amount']}->$tds");
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
                if (!$isAdmin) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $tid = (int)($_POST['id'] ?? 0);
                if ($tid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid transaction ID']);
                    exit();
                }

                // get txn details before delete
                $stmt = $conn->prepare("SELECT at.agent_id, at.bank_txn_id, at.is_linked_mirror, a.name as agent_name FROM agent_transactions at JOIN agents a ON at.agent_id = a.id WHERE at.id = ?");
                $stmt->bind_param("i", $tid);
                $stmt->execute();
                $txn = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$txn) {
                    echo json_encode(['success' => false, 'message' => 'Transaction not found']);
                    $conn->close();
                    exit();
                }

                // linked mirror rows are managed from the Bank screen
                if ((int)$txn['is_linked_mirror'] === 1) {
                    echo json_encode(['success' => false, 'message' => 'This entry is linked from a Bank transaction. Delete it on the Bank Transactions screen.']);
                    $conn->close();
                    exit();
                }

                $agId = (int)$txn['agent_id'];
                $delName = $txn['agent_name'];
                $delBankTxnId = (int)$txn['bank_txn_id'];

                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("DELETE FROM agent_transactions WHERE id = ?");
                    $stmt->bind_param("i", $tid);
                    $stmt->execute();
                    $stmt->close();

                    // drop linked bank leg too
                    $mirrorAcc = $delBankTxnId > 0 ? deleteBankMirror($conn, $delBankTxnId) : 0;

                    // recalc rewrites running_balance + wallet/outstanding
                    recalcAgentBalances($conn, $agId);
                    if ($mirrorAcc > 0) recalcBankBalance($conn, $mirrorAcc);

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete transaction']);
                    exit();
                }

                logActivity($user_id, $username, 'Agent Txn Deleted', "Deleted txn #$tid for $delName");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Transaction deleted successfully']);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("agent_transactions.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
        exit();
    }
}

// active bank accounts for the payment -> bank link dropdown
$bankAccts = [];
$cxnBA = getDBConnection();
$rsBA = $cxnBA->query("SELECT id, bank_name, account_number FROM bank_accounts WHERE is_active = 1 ORDER BY bank_name");
if ($rsBA) { while ($baRow = $rsBA->fetch_assoc()) $bankAccts[] = $baRow; }
$cxnBA->close();
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
    <title>Agent Transactions - Insurance Management System</title>

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
            $pageIcon    = $isAgent ? 'fa-receipt' : 'fa-exchange-alt';
            $pageTitle   = $isAgent ? 'My Account Statement' : 'Agent Transactions';
            $crumbs      = $isAgent ? [] : [['label' => 'Agents', 'icon' => 'fa-user-tie']];
            $pageActions = [];
            if ($isAdmin) {
                $pageActions[] = ['label' => 'Add Transaction', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
                $pageActions[] = ['label' => 'Bulk Add / Import', 'icon' => 'fa-file-excel', 'onclick' => 'agentTxnImport.open()'];
            }
            // no add -> statement is the main action
            $pageActions[] = ['label' => 'Print Statement', 'icon' => 'fa-file-invoice', 'onclick' => 'printStatement()', 'class' => $isAdmin ? '' : 'primary'];
            $pageActions[] = ['label' => 'Statement PDF', 'icon' => 'fa-file-arrow-down', 'onclick' => "printStatement('pdf')"];
            $pageActions[] = ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(txnTable,'csv')"];
            $pageActions[] = ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(txnTable,'pdf')"];
            $pageActions[] = ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(txnTable,'print')"];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadTransactions()'];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="txnSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="txnSearch" placeholder="Search transactions…" autocomplete="off"></div>
                <?php if (!$isAgent): ?>
                <div class="hf-field"><label for="filterAgent" title="Agent"><i class="fas fa-user-tie"></i></label><select id="filterAgent" class="filter-input searchable"><option value="">All Agents</option></select></div>
                <?php endif; ?>
                <div class="hf-field"><label for="filterType" title="Type"><i class="fas fa-tag"></i></label><select id="filterType" class="filter-input searchable"><option value="">All Types</option><option value="commission">Commission</option><option value="payment_in">Payment In</option><option value="payment_out">Payment Out</option><option value="adjustment">Adjustment</option></select></div>
                <div class="hf-field hf-range" title="Date range"><label for="filterDateFrom"><i class="fas fa-calendar-days"></i></label><input type="date" id="filterDateFrom" class="filter-input" aria-label="From"><span class="hf-sep"><i class="fas fa-arrow-right-long"></i></span><input type="date" id="filterDateTo" class="filter-input" aria-label="To"></div>
                <div class="hf-seg" role="group" aria-label="Quick range"><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',0,this)">Today</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',6,this)">7 days</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',29,this)">30 days</button></div>
                <button type="button" class="btn btn-icon" onclick="clearFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>

            <!-- Agent summary card (agent role only) -->
            <?php if ($isAgent): ?>
            <div id="agentSummaryCard" class="data-section" style="display:none;">
                <div class="section-header">
                    <h2><i class="fas fa-id-badge"></i> My Account Summary</h2>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Agent Name</label>
                        <div id="summaryName" class="wallet-amount">--</div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Agent Code</label>
                        <div id="summaryCode" class="wallet-amount">--</div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-wallet"></i> Wallet Balance</label>
                        <div id="summaryWallet" class="wallet-amount" style="color: #27ae60;">--</div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-file-invoice-dollar"></i> Outstanding Balance</label>
                        <div id="summaryOutstanding" class="wallet-amount" style="color: #e74c3c;">--</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

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
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.4"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
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
    <!-- Add Transaction Modal -->
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
                            <label><i class="fas fa-user-tie"></i> Agent *</label>
                            <select id="txnAgent" name="agent_id" class="searchable" required>
                                <option value="">Select Agent</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Date *</label>
                            <input type="date" id="txnDate" name="transaction_date" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Description *</label>
                        <textarea id="txnDesc" name="description" required rows="2" maxlength="500" placeholder="Transaction description"></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Type *</label>
                            <select id="txnType" name="transaction_type" class="searchable" required onchange="handleTypeChange()">
                                <option value="">Select Type</option>
                                <option value="commission">Commission</option>
                                <option value="payment_in">Payment In</option>
                                <option value="payment_out">Payment Out</option>
                                <option value="adjustment">Adjustment</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill"></i> Payment Mode</label>
                            <select id="txnPayMode" name="payment_mode" class="searchable">
                                <option value="">Select Mode</option>
                                <option value="Cash">Cash</option>
                                <option value="Cheque">Cheque</option>
                                <option value="NEFT">NEFT</option>
                                <option value="UPI">UPI</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group" id="creditGroup">
                            <label><i class="fas fa-arrow-down"></i> Credit Amount</label>
                            <input type="number" id="txnCredit" name="credit_amount" min="0" step="0.01" value="0" placeholder="0.00">
                        </div>
                        <div class="form-group" id="debitGroup">
                            <label><i class="fas fa-arrow-up"></i> Debit Amount</label>
                            <input type="number" id="txnDebit" name="debit_amount" min="0" step="0.01" value="0" placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-grid form-hidden" id="tdsRow">
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> TDS Deducted <small id="tdsHint"></small></label>
                            <input type="number" id="txnTds" name="tds_amount" min="0" step="0.01" value="0" placeholder="0.00">
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

                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Reference Number</label>
                        <input type="text" id="txnRef" name="reference_number" maxlength="50" placeholder="Cheque/UTR/Ref no.">
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
        const IS_AGENT = <?php echo $isAgent ? 'true' : 'false'; ?>;
        let txnTable = null;
        let txnData = [];
        let agentsList = [];
        var isEditMode = false;

        function formatCurrency(val) {
            return '\u20B9' + parseFloat(val).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        $(document).ready(function() {
            if (IS_AGENT) {
                loadAgentInfo();
                initAllSearchableDropdowns(); // staff get this after loadAgents; agents have no agent list to wait for
            } else {
                loadAgents();
            }
            loadTransactions();
            // head search -> table
            $('#txnSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(txnTable, v); },200); });
        });

        // load agent summary for agent role
        function loadAgentInfo() {
            $.ajax({
                url: '?action=getAgentInfo',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        var d = res.data;
                        $('#summaryName').text(d.name);
                        $('#summaryCode').text(d.agent_code);
                        $('#summaryWallet').text(formatCurrency(d.wallet_balance));
                        $('#summaryOutstanding').text(formatCurrency(d.outstanding_balance));
                        $('#agentSummaryCard').show();
                    }
                }
            });
        }

        // load agents for filter + modal dropdown
        function loadAgents() {
            $.ajax({
                url: '?action=getAgents',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        agentsList = res.data;
                        var filterSel = $('#filterAgent');
                        filterSel.find('option:not(:first)').remove();
                        res.data.forEach(function(a) {
                            filterSel.append('<option value="' + a.id + '">' + escapeHtml(a.name) + ' (' + escapeHtml(a.agent_code) + ')</option>');
                        });

                        <?php if ($isAdmin): ?>
                        var modalSel = $('#txnAgent');
                        modalSel.find('option:not(:first)').remove();
                        res.data.forEach(function(a) {
                            modalSel.append('<option value="' + a.id + '">' + escapeHtml(a.name) + ' (' + escapeHtml(a.agent_code) + ')</option>');
                        });
                        <?php endif; ?>
                        setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
                    }
                }
            });
        }

        function loadTransactions() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();
            $('#filtersSection').hide();

            $.ajax({
                url: '?action=getTransactions',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        txnData = res.data;
                        $('#loadingSkeleton').hide();
                        $('#tableContainer').show();
                        $('#filtersSection').show();
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

        function getTypeBadge(type) {
            var map = {
                'commission': '<span class="status-badge" style="background:#0074D9;color:#fff;">Commission</span>',
                'payment_in': '<span class="status-badge status-active">Payment In</span>',
                'payment_out': '<span class="status-badge status-inactive" style="background:#ea4335;color:#fff;">Payment Out</span>',
                'adjustment': '<span class="status-badge" style="background:#6c757d;color:#fff;">Adjustment</span>'
            };
            return map[type] || type;
        }

        // view txn detail modal
        function viewTxn(d) {
            var crVal = d.credit_amount > 0 ? '<span style="color:#27ae60;">' + formatCurrency(d.credit_amount) + '</span>' : '-';
            var drVal = d.debit_amount > 0 ? '<span style="color:#ea4335;">' + formatCurrency(d.debit_amount) + '</span>' : '-';
            var html = '<table class="view-detail-table">';
            html += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">' + escapeHtml(d.id) + '</td>';
            html += '<td class="vd-label"><i class="fas fa-calendar"></i> Date</td><td class="vd-value">' + escapeHtml(d.transaction_date || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-user-tie"></i> Agent</td><td class="vd-value">' + escapeHtml((d.agent_name || '-') + ' (' + (d.agent_code || '') + ')') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-tag"></i> Type</td><td class="vd-value">' + getTypeBadge(d.transaction_type) + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-arrow-down"></i> Credit</td><td class="vd-value">' + crVal + '</td>';
            html += '<td class="vd-label"><i class="fas fa-arrow-up"></i> Debit</td><td class="vd-value">' + drVal + '</td></tr>';
            if (d.tds_amount > 0) {
                html += '<tr><td class="vd-label"><i class="fas fa-percent"></i> TDS Deducted</td><td class="vd-value">' + formatCurrency(d.tds_amount) + '</td>';
                html += '<td class="vd-label"><i class="fas fa-hand-holding-dollar"></i> Net Paid</td><td class="vd-value">' + formatCurrency(d.debit_amount - d.tds_amount) + '</td></tr>';
            }
            html += '<tr><td class="vd-label"><i class="fas fa-balance-scale"></i> Balance</td><td class="vd-value">' + formatCurrency(d.running_balance) + '</td>';
            html += '<td class="vd-label"><i class="fas fa-money-bill"></i> Payment Mode</td><td class="vd-value">' + escapeHtml(d.payment_mode || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> Reference</td><td class="vd-value">' + escapeHtml(d.reference_number || '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-clock"></i> Created</td><td class="vd-value">' + escapeHtml(d.created_at || '-') + '</td></tr>';
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

        function initDataTable(data) {
            if (txnTable) return rsTbData(txnTable, data); // build once, swap rows

            var cols = [
                { data: 'id', title: 'ID' },
                { data: 'transaction_date', title: 'Date' }
            ];

            // agent col only for admin/manager
            if (!IS_AGENT) {
                cols.push({
                    data: null,
                    title: 'Agent',
                    render: function(d, t, row) {
                        return escapeHtml(row.agent_name) + ' <small>(' + escapeHtml(row.agent_code) + ')</small>';
                    }
                });
            }

            cols.push(
                {
                    data: 'description',
                    title: 'Description',
                    render: function(d) { return escapeHtml(d); }
                },
                {
                    data: 'transaction_type',
                    title: 'Type',
                    render: function(d) { return getTypeBadge(d); }
                },
                {
                    data: 'credit_amount',
                    title: 'Credit',
                    render: function(d) {
                        return d > 0 ? '<span class="wallet-amount" style="color:#27ae60;">' + formatCurrency(d) + '</span>' : '-';
                    }
                },
                {
                    data: 'debit_amount',
                    title: 'Debit',
                    render: function(d, t, row) {
                        if (!(d > 0)) return '-';
                        var v = '<span class="wallet-amount" style="color:#ea4335;">' + formatCurrency(d) + '</span>';
                        return (t === 'display' && row.tds_amount > 0) ? v + '<br><small>TDS ' + formatCurrency(row.tds_amount) + '</small>' : v;
                    }
                },
                { data: 'tds_amount', title: 'TDS', visible: false, render: function(d) { return d > 0 ? formatCurrency(d) : '-'; } },
                {
                    data: 'running_balance',
                    title: 'Balance',
                    render: function(d) {
                        return '<span class="wallet-amount">' + formatCurrency(d) + '</span>';
                    }
                },
                {
                    data: 'payment_mode',
                    title: 'Mode',
                    render: function(d, t, row) {
                        var m = d ? escapeHtml(d) : '-';
                        if (row.bank_account_id) {
                            m += ' <span class="badge-code" title="Posted to bank statement"><i class="fas fa-university"></i> ' + escapeHtml(row.linked_bank_name || 'Bank') + '</span>';
                        }
                        return m;
                    }
                },
                {
                    data: 'reference_number',
                    title: 'Reference',
                    render: function(d) { return d ? escapeHtml(d) : '-'; }
                }
            );

            // action hub col (last) - view for all, edit/delete for admin
            cols.push({
                data: null,
                title: 'Actions',
                orderable: false,
                render: function(d, t, row) {
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
                columns: cols, data: data, title: 'Agent Transactions', file: 'agent_transactions', sort: ['id', 'desc'],
                // credit = green, debit = red
                rowClass: function(r) { return parseFloat(r.credit_amount) > 0 ? 'dt-row-success' : (parseFloat(r.debit_amount) > 0 ? 'dt-row-danger' : ''); },
                info: function() {
                    var a = document.getElementById('filterAgent'), f = $('#filterDateFrom').val(), t = $('#filterDateTo').val();
                    var i = [['Type', $('#filterType option:selected').text() || 'All Types']];
                    if (a) i.push(['Agent', a.value ? a.options[a.selectedIndex].text : 'All Agents']);
                    if (f || t) i.push(['Period', (f || '...') + ' to ' + (t || '...')]);
                    return i;
                }
            });
            rsTbSearch(txnTable, $('#txnSearch').val());

            // bind filters
            if (!IS_AGENT) {
                $('#filterAgent').off('change').on('change', applyFilters);
            }
            $('#filterDateFrom, #filterDateTo').off('change').on('change', applyFilters);
            $('#filterType').off('change').on('change', applyFilters);

            // deep-link pre-filter
            <?php if ($preAgentId > 0): ?>
            if (!IS_AGENT && !window._agentPrefilled) {
                window._agentPrefilled = true;
                $('#filterAgent').val('<?php echo $preAgentId; ?>');
                var sd = document.getElementById('filterAgent');
                if (sd && sd.sdRefresh) sd.sdRefresh();
                applyFilters();
            }
            <?php endif; ?>
            applyFilters();
        }

        function applyFilters() {
            var type = $('#filterType').val(), agent = IS_AGENT ? '' : $('#filterAgent').val();
            var from = $('#filterDateFrom').val(), to = $('#filterDateTo').val();
            rsTbWhere(txnTable, 'type', type ? function(r) { return r.transaction_type === type; } : null);
            rsTbWhere(txnTable, 'agent', agent ? function(r) { return String(r.agent_id) === String(agent); } : null);
            rsTbWhere(txnTable, 'date', (from || to) ? function(r) {
                var d = r.transaction_date;
                return !d || ((!from || d >= from) && (!to || d <= to));
            } : null);
        }

        function clearFilters() {
            if (!IS_AGENT) $('#filterAgent').val('');
            $('#filterDateFrom').val('');
            $('#filterDateTo').val('');
            $('#filterType').val('');
            $('#txnSearch').val('');
            $('.hf-seg button').removeClass('is-on');
            ['filterAgent', 'filterType'].forEach(function(id) { var el = document.getElementById(id); if (el && el.sdRefresh) el.sdRefresh(); });
            rsTbClear(txnTable);
        }

        <?php if ($isAdmin): ?>
        function handleTypeChange() {
            var type = $('#txnType').val();
            if (type === 'commission' || type === 'payment_in') {
                // credit only
                $('#txnCredit').prop('disabled', false).val('');
                $('#txnDebit').prop('disabled', true).val(0);
            } else if (type === 'payment_out') {
                // debit only
                $('#txnCredit').prop('disabled', true).val(0);
                $('#txnDebit').prop('disabled', false).val('');
            } else {
                // adjustment or empty - both enabled
                $('#txnCredit').prop('disabled', false).val('');
                $('#txnDebit').prop('disabled', false).val('');
            }
            // tds only on payouts
            $('#tdsRow').toggleClass('form-hidden', type !== 'payment_out');
            if (type !== 'payment_out') $('#txnTds').val(0);
            suggestTds();
            // bank leg only for real cash payments
            var isPay = (type === 'payment_in' || type === 'payment_out');
            $('#bankLegRow').toggleClass('form-hidden', !isPay);
            if (!isPay) {
                $('#txnBankAccount').val('');
                var bs = document.getElementById('txnBankAccount');
                if (bs && bs.sdRefresh) bs.sdRefresh();
            }
        }

        // tds = debit x agent tds_rate / 100 until the user types their own
        var tdsTouched = false;
        function suggestTds() {
            if ($('#txnType').val() !== 'payment_out') { $('#tdsHint').text(''); return; }
            var a = agentsList.find(function(x) { return String(x.id) === String($('#txnAgent').val()); });
            var rate = a ? parseFloat(a.tds_rate) || 0 : 0, dr = parseFloat($('#txnDebit').val()) || 0;
            if (!tdsTouched) $('#txnTds').val((Math.round(dr * rate) / 100).toFixed(2));
            var tds = parseFloat($('#txnTds').val()) || 0;
            $('#tdsHint').text('(' + rate + '% · bank pays ' + formatCurrency(Math.max(dr - tds, 0)) + ')');
        }
        $('#txnDebit, #txnAgent').on('input change', suggestTds);
        $('#txnTds').on('input', function() { tdsTouched = true; suggestTds(); });

        function openAddModal() {
            isEditMode = false;
            tdsTouched = false;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Add Transaction';
            document.getElementById('txnForm').reset();
            document.getElementById('txnId').value = '';
            var agentSel = document.getElementById('txnAgent');
            if (agentSel) agentSel.disabled = false;
            $('#txnCredit').prop('disabled', false).val('');
            $('#txnDebit').prop('disabled', false).val('');
            // default date to today
            $('#txnDate').val(new Date().toISOString().split('T')[0]);
            handleTypeChange(); // hide bank leg until a payment type is picked
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
            // ensure disabled fields send 0
            if ($('#txnCredit').prop('disabled')) formData.set('credit_amount', 0);
            if ($('#txnDebit').prop('disabled')) formData.set('debit_amount', 0);
            if ($('#tdsRow').hasClass('form-hidden')) formData.set('tds_amount', 0);
            var tdsV = parseFloat(formData.get('tds_amount') || 0);
            if (tdsV > 0 && tdsV >= parseFloat(formData.get('debit_amount') || 0)) {
                Swal.fire({ icon: 'warning', title: 'Invalid', text: 'TDS must be less than the debit amount' });
                return;
            }
            // include id for edit
            if (isEditMode) formData.set('id', document.getElementById('txnId').value);
            formData.set('csrf_token', window.CSRF_TOKEN);

            var cr = parseFloat(formData.get('credit_amount') || 0);
            var dr = parseFloat(formData.get('debit_amount') || 0);
            if (cr === 0 && dr === 0) {
                Swal.fire({ icon: 'warning', title: 'Invalid', text: 'Enter a credit or debit amount' });
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

        // delegated action handlers - one per table
        $('#txnTable').on('click', '.act-view, .more-edit, .more-del', function() {
            var id = parseInt($(this).attr('data-id'), 10);
            var row = txnData.find(function(r) { return r.id === id; });
            if (!row) return;
            if ($(this).hasClass('act-view')) viewTxn(row);
            else if ($(this).hasClass('more-edit')) editTxn(row);
            else if ($(this).hasClass('more-del')) deleteTxn(id);
        });

        // edit txn - populate modal
        function editTxn(row) {
            isEditMode = true;
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Transaction';
            document.getElementById('txnId').value = row.id;
            var agentSel = document.getElementById('txnAgent');
            if (agentSel) {
                agentSel.value = row.agent_id;
                agentSel.disabled = true;
            }
            document.getElementById('txnDate').value = row.transaction_date;
            document.getElementById('txnDesc').value = row.description;
            document.getElementById('txnType').value = row.transaction_type;
            var baSel = document.getElementById('txnBankAccount');
            if (baSel) baSel.value = row.bank_account_id || '';
            tdsTouched = true; // keep the stored tds
            handleTypeChange();
            document.getElementById('txnCredit').value = row.credit_amount || 0;
            document.getElementById('txnDebit').value = row.debit_amount || 0;
            document.getElementById('txnTds').value = row.tds_amount || 0;
            suggestTds();
            var payMode = document.getElementById('txnPayMode');
            if (payMode) payMode.value = row.payment_mode || '';
            var refNum = document.getElementById('txnRef');
            if (refNum) refNum.value = row.reference_number || '';
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
            document.getElementById('txnModal').classList.add('active');
        }

        function deleteTxn(tid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Transaction?',
                text: 'This will recalculate running balances. Cannot be undone.',
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

            // party name - from filter or summary card
            var agentName = 'All Agents';
            var singleAgent = IS_AGENT;
            if (IS_AGENT) {
                agentName = ($('#summaryName').text() || 'Agent') + ' (' + ($('#summaryCode').text() || '') + ')';
            } else {
                var agentSel = document.getElementById('filterAgent');
                if (agentSel && agentSel.value) {
                    agentName = agentSel.options[agentSel.selectedIndex]?.text || 'All Agents';
                    singleAgent = true;
                }
            }
            // running balance only meaningful for a single agent
            if (!singleAgent) {
                Swal.fire({ icon: 'warning', title: 'Select an Agent', text: 'Please choose a specific agent before printing a statement.' });
                return;
            }
            var dateFrom = document.getElementById('filterDateFrom')?.value || '';
            var dateTo = document.getElementById('filterDateTo')?.value || '';

            var typeLbl = function(r) { return String(r.transaction_type || '').replace(/_/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); }); };
            var typeSel = document.getElementById('filterType');
            rsStatement(kind || 'print', {
                title: 'Agent Statement', number: 'AST-' + rsStamp(),
                party: { label: 'Statement for', name: agentName },
                period: (dateFrom ? rsPaperDate(dateFrom) : 'Start') + ' to ' + (dateTo ? rsPaperDate(dateTo) : 'Today'),
                rows: rows, file: 'agent_statement',
                filters: [typeSel && typeSel.value ? 'Type: ' + typeSel.options[typeSel.selectedIndex].text : '', $('#txnSearch').val() ? 'Search "' + $('#txnSearch').val() + '"' : ''].filter(Boolean),
                cr: function(r) { return parseFloat(r.credit_amount) || 0; },
                dr: function(r) { return parseFloat(r.debit_amount) || 0; },
                bal: function(r) { return parseFloat(r.running_balance) || 0; },
                desc: function(r) { return r.description || typeLbl(r); },
                sub: function(r) { return [r.description ? typeLbl(r) : '', r.tds_amount > 0 ? 'TDS ' + rsMoney(r.tds_amount) : ''].filter(Boolean).join(' · '); }
            });
        }

        <?php if ($isAdmin): ?>
        // bulk add / excel import — engine lives in sidebar.php
        var AT_BANKS = <?php echo json_encode($bankAccts); ?>;
        var agentTxnImport = RSImport({
            key: 'agent_transactions', title: 'Bulk Add / Import Agent Transactions', url: '?action=bulkAddAgentTxns',
            hint: 'Post many agent ledger entries at once — upload an Excel/CSV file or type the rows. <strong>Date, agent, description and type</strong> are required, plus a credit or debit amount. The type fixes the side automatically: <strong>commission</strong> and <strong>payment in</strong> are credits, <strong>payment out</strong> is a debit, <strong>adjustment</strong> takes whichever you fill. A <strong>bank account</strong> only links on payment in/out — it posts the matching bank leg, same as the single form. <strong>TDS</strong> is allowed on payment out only: the ledger keeps the full debit, the bank leg posts debit − TDS. Agents are matched on agent code, then on name.',
            onDone: function() { setTimeout(loadTransactions, 100); },
            fields: [
                { key: 'transaction_date', label: 'Date *', cls: 'bg-date', type: 'date', req: true, aliases: ['transactiondate', 'date', 'txndate', 'entrydate'], demo: '2026-07-17', demo2: '2026-07-18' },
                { key: 'agent', label: 'Agent *', cls: 'bg-agent', type: 'select', req: true,
                  opts: function() { return [{ v: '', t: '— Select —' }].concat((agentsList || []).map(function(a) { return { v: a.agent_code, t: a.name + ' (' + a.agent_code + ')' }; })); },
                  aliases: ['agent', 'agentcode', 'agentname', 'code'], demo: '', demo2: '' },
                { key: 'description', label: 'Description *', cls: 'bg-desc', req: true, max: 500, ph: 'What this entry is for', aliases: ['description', 'desc', 'particulars', 'narration', 'details', 'remarks'], demo: 'Demo commission payout', demo2: 'Demo payment received' },
                { key: 'transaction_type', label: 'Type *', cls: 'bg-type', type: 'select', req: true, def: 'adjustment',
                  opts: [{ v: 'commission', t: 'Commission' }, { v: 'payment_in', t: 'Payment In' }, { v: 'payment_out', t: 'Payment Out' }, { v: 'adjustment', t: 'Adjustment' }],
                  aliases: ['transactiontype', 'type', 'txntype', 'entrytype'], demo: 'payment_out', demo2: 'payment_in' },
                { key: 'credit_amount', label: 'Credit (₹)', cls: 'bg-cr', type: 'number', aliases: ['creditamount', 'credit', 'cr', 'creditamt'], demo: 0, demo2: 5000 },
                { key: 'debit_amount', label: 'Debit (₹)', cls: 'bg-dr', type: 'number', aliases: ['debitamount', 'debit', 'dr', 'debitamt'], demo: 5000, demo2: 0 },
                { key: 'tds_amount', label: 'TDS (₹)', cls: 'bg-dr', type: 'number', aliases: ['tdsamount', 'tds', 'tdsdeducted'], demo: 250, demo2: 0 },
                { key: 'payment_mode', label: 'Mode', cls: 'bg-mode', type: 'select',
                  opts: [{ v: '', t: '— None —' }, { v: 'Cash', t: 'Cash' }, { v: 'Bank Transfer', t: 'Bank Transfer' }, { v: 'UPI', t: 'UPI' }, { v: 'Cheque', t: 'Cheque' }],
                  aliases: ['paymentmode', 'mode', 'paymode', 'paymenttype'], demo: 'Bank Transfer', demo2: 'Cash' },
                { key: 'bank_account', label: 'Bank Account', cls: 'bg-bank', type: 'select',
                  opts: function() { return [{ v: '', t: '— None —' }].concat(AT_BANKS.map(function(a) { return { v: a.account_number, t: a.bank_name + ' - ' + a.account_number }; })); },
                  aliases: ['bankaccount', 'bank', 'account', 'accountnumber', 'bankname'], demo: '', demo2: '' },
                { key: 'reference_number', label: 'Reference', cls: 'bg-ref', max: 50, ph: 'optional', aliases: ['referencenumber', 'reference', 'refno', 'chequeno', 'utr', 'voucherno'], demo: 'REF-201', demo2: '' }
            ]
        });
        <?php endif; ?>
    </script>
</body>
</html>
