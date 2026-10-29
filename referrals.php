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
$current_page = 'referrals';

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getCustomersList':
                $stmt = $conn->prepare("SELECT id, name, referral_code FROM customers WHERE is_active = 1 ORDER BY name");
                $stmt->execute();
                $res = $stmt->get_result();
                $list = [];
                while ($row = $res->fetch_assoc()) $list[] = $row;
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $list]);
                exit();

            case 'getReferrals':
                $stmt = $conn->prepare("
                    SELECT r.*, c1.name as referrer_name, c1.referral_code as referrer_code,
                           c2.name as referred_name, c2.referral_code as referred_code
                    FROM referrals r
                    JOIN customers c1 ON r.referrer_id = c1.id
                    JOIN customers c2 ON r.referred_id = c2.id
                    ORDER BY r.created_at DESC
                ");
                $stmt->execute();
                $res = $stmt->get_result();
                $referrals = [];
                while ($row = $res->fetch_assoc()) {
                    $referrals[] = [
                        'id' => (int)$row['id'],
                        'referrer_id' => (int)$row['referrer_id'],
                        'referrer_name' => $row['referrer_name'],
                        'referrer_code' => $row['referrer_code'],
                        'referred_id' => (int)$row['referred_id'],
                        'referred_name' => $row['referred_name'],
                        'referred_code' => $row['referred_code'],
                        'policy_entry_id' => $row['policy_entry_id'] ? (int)$row['policy_entry_id'] : null,
                        'points_earned' => (int)$row['points_earned'],
                        'status' => $row['status'],
                        'created_at' => $row['created_at'],
                        'completed_at' => $row['completed_at']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $referrals]);
                exit();

            case 'addReferral':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $referrer_id = (int)($_POST['referrer_id'] ?? 0);
                $referred_id = (int)($_POST['referred_id'] ?? 0);

                if ($referrer_id <= 0 || $referred_id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Both referrer and referred customer are required']);
                    exit();
                }
                if ($referrer_id === $referred_id) {
                    echo json_encode(['success' => false, 'message' => 'Referrer and referred cannot be the same customer']);
                    exit();
                }

                // check both exist
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM customers WHERE id IN (?, ?)");
                $stmt->bind_param("ii", $referrer_id, $referred_id);
                $stmt->execute();
                $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();
                if ($cnt < 2) {
                    echo json_encode(['success' => false, 'message' => 'One or both customers not found']);
                    $conn->close();
                    exit();
                }

                $stmt = $conn->prepare("INSERT INTO referrals (referrer_id, referred_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $referrer_id, $referred_id);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add referral']);
                    exit();
                }
                $insertId = $stmt->insert_id;
                $stmt->close();

                logActivity($user_id, $username, 'Referral Created', "Created referral #$insertId: customer #$referrer_id -> #$referred_id");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Referral added successfully', 'data' => ['id' => $insertId]]);
                exit();

            case 'updateReferral':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $rid = (int)($_POST['id'] ?? 0);
                $status = trim($_POST['status'] ?? '');
                $points_earned = (int)($_POST['points_earned'] ?? 0);
                $policy_entry_id = !empty($_POST['policy_entry_id']) ? (int)$_POST['policy_entry_id'] : null;

                if ($rid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid referral ID']);
                    exit();
                }
                $allowed = ['pending', 'completed', 'expired'];
                if (!in_array($status, $allowed)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid status']);
                    exit();
                }

                // if completing, set completed_at
                $completed_at = ($status === 'completed') ? date('Y-m-d H:i:s') : null;

                $stmt = $conn->prepare("UPDATE referrals SET status = ?, points_earned = ?, policy_entry_id = ?, completed_at = ? WHERE id = ?");
                $stmt->bind_param("siisi", $status, $points_earned, $policy_entry_id, $completed_at, $rid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update referral']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Referral Updated', "Updated referral #$rid: status=$status, pts=$points_earned");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Referral updated successfully']);
                exit();

            case 'deleteReferral':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $rid = (int)($_POST['id'] ?? 0);
                if ($rid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid referral ID']);
                    exit();
                }

                $stmt = $conn->prepare("DELETE FROM referrals WHERE id = ?");
                $stmt->bind_param("i", $rid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete referral']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Referral Deleted', "Deleted referral #$rid");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Referral deleted successfully']);
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
                try {
                    $del = $conn->prepare("DELETE FROM referrals WHERE id = ?");
                    foreach ($ids as $rid) {
                        $del->bind_param("i", $rid);
                        if (!$del->execute()) { $del->close(); throw new Exception('delete failed'); }
                        $deleted += $del->affected_rows;
                    }
                    $del->close();
                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete referrals']);
                    exit();
                }

                logActivity($user_id, $username, 'Bulk delete', "Deleted $deleted referral(s)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => "$deleted record(s) deleted"]);
                exit();

            case 'bulkDeletePoints':
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
                $custToRecalc = [];
                try {
                    foreach ($ids as $ptid) {
                        // grab customer before delete
                        $sel = $conn->prepare("SELECT customer_id FROM point_transactions WHERE id = ?");
                        $sel->bind_param("i", $ptid);
                        $sel->execute();
                        $row = $sel->get_result()->fetch_assoc();
                        $sel->close();
                        if (!$row) continue; // skip missing

                        $custToRecalc[(int)$row['customer_id']] = true;

                        $stmt = $conn->prepare("DELETE FROM point_transactions WHERE id = ?");
                        $stmt->bind_param("i", $ptid);
                        if (!$stmt->execute()) { $stmt->close(); throw new Exception('delete failed'); }
                        $stmt->close();
                        $deleted++;
                    }

                    // recompute balance_after chain + customer balance per affected customer
                    foreach (array_keys($custToRecalc) as $cid) {
                        $chain = $conn->prepare("SELECT id, transaction_type, points FROM point_transactions WHERE customer_id = ? ORDER BY created_at ASC, id ASC");
                        $chain->bind_param("i", $cid);
                        $chain->execute();
                        $rows = $chain->get_result();
                        $running = 0;
                        $upd = $conn->prepare("UPDATE point_transactions SET balance_after = ? WHERE id = ?");
                        while ($r = $rows->fetch_assoc()) {
                            $running += ($r['transaction_type'] === 'earned') ? (int)$r['points'] : -(int)$r['points'];
                            if ($running < 0) { $chain->close(); $upd->close(); throw new Exception('negative balance in chain'); }
                            $rid2 = (int)$r['id'];
                            $upd->bind_param("ii", $running, $rid2);
                            $upd->execute();
                        }
                        $chain->close();
                        $upd->close();

                        // customer balance = chain end
                        $cb = $conn->prepare("UPDATE customers SET points_balance = ? WHERE id = ?");
                        $cb->bind_param("ii", $running, $cid);
                        $cb->execute();
                        $cb->close();
                    }

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    $msg = ($ex->getMessage() === 'negative balance in chain') ? 'Delete would make a balance negative' : 'Failed to delete transactions';
                    echo json_encode(['success' => false, 'message' => $msg]);
                    exit();
                }

                logActivity($user_id, $username, 'Bulk delete', "Deleted $deleted point transaction(s)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => "$deleted record(s) deleted"]);
                exit();

            // point transactions
            case 'getPointTransactions':
                $customerId = !empty($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

                if ($customerId > 0) {
                    $stmt = $conn->prepare("
                        SELECT pt.*, c.name as customer_name, c.referral_code, cu.name as created_by_name
                        FROM point_transactions pt
                        JOIN customers c ON pt.customer_id = c.id
                        LEFT JOIN users cu ON cu.id = pt.created_by
                        WHERE pt.customer_id = ?
                        ORDER BY pt.created_at DESC
                    ");
                    $stmt->bind_param("i", $customerId);
                } else {
                    $stmt = $conn->prepare("
                        SELECT pt.*, c.name as customer_name, c.referral_code, cu.name as created_by_name
                        FROM point_transactions pt
                        JOIN customers c ON pt.customer_id = c.id
                        LEFT JOIN users cu ON cu.id = pt.created_by
                        ORDER BY pt.created_at DESC
                    ");
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $txns = [];
                while ($row = $res->fetch_assoc()) {
                    $txns[] = [
                        'id' => (int)$row['id'],
                        'customer_id' => (int)$row['customer_id'],
                        'customer_name' => $row['customer_name'],
                        'referral_code' => $row['referral_code'],
                        'transaction_type' => $row['transaction_type'],
                        'points' => (int)$row['points'],
                        'rupee_value' => (float)$row['rupee_value'],
                        'description' => $row['description'],
                        'balance_after' => (int)$row['balance_after'],
                        'created_by_name' => $row['created_by_name'],
                        'created_at' => $row['created_at']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $txns]);
                exit();

            case 'bulkAddPoints':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows) || count($rows) === 0) { echo json_encode(['success'=>false,'message'=>'No point entries to add']); exit(); }
                $off = max(0, (int)($_POST['row_offset'] ?? 0)); // client posts in chunks - keep row numbers global

                // customer by referral code, then mobile, then name (name only when unique)
                $custMap = []; $nameCount = [];
                $res = $conn->query("SELECT id, name, mobile, referral_code FROM customers");
                while ($c = $res->fetch_assoc()) {
                    $cid = (int)$c['id'];
                    if (trim($c['referral_code']) !== '') $custMap[strtolower(trim($c['referral_code']))] = $cid;
                    if (trim($c['mobile']) !== '') $custMap[strtolower(trim($c['mobile']))] = $cid;
                    $nk = strtolower(trim($c['name']));
                    $nameCount[$nk] = ($nameCount[$nk] ?? 0) + 1;
                    if ($nameCount[$nk] === 1 && !isset($custMap[$nk])) $custMap[$nk] = $cid;
                }
                $ratio = (float)getSetting('points_to_rupees_ratio', '10');

                $inserted = 0; $errors = [];
                foreach ($rows as $idx => $r) {
                    $line = $off + $idx + 1;
                    $custRaw = trim($r['customer'] ?? '');
                    $txnType = strtolower(trim($r['transaction_type'] ?? ''));
                    $points = (int)($r['points'] ?? 0);
                    $desc = trim($r['description'] ?? '');

                    if ($custRaw === '' && $desc === '' && $points === 0) continue; // trailing blank line

                    $ck = strtolower($custRaw);
                    if ($custRaw === '' || !isset($custMap[$ck])) { $errors[] = "Row $line: customer '$custRaw' not found"; continue; }
                    if (($nameCount[$ck] ?? 0) > 1) { $errors[] = "Row $line: '$custRaw' matches several customers — use the referral code"; continue; }
                    if (!in_array($txnType, ['earned', 'redeemed'], true)) { $errors[] = "Row $line: type must be earned or redeemed"; continue; }
                    if ($points <= 0) { $errors[] = "Row $line: points must be greater than 0"; continue; }
                    if ($desc === '' || strlen($desc) > 255) { $errors[] = "Row $line: description is required (max 255 chars)"; continue; }

                    $cid = $custMap[$ck];

                    $conn->begin_transaction();
                    try {
                        // read the live balance each row so several rows for one customer stack correctly
                        $q = $conn->prepare("SELECT points_balance FROM customers WHERE id = ? FOR UPDATE");
                        $q->bind_param("i", $cid); $q->execute();
                        $cRow = $q->get_result()->fetch_assoc(); $q->close();
                        if (!$cRow) { throw new Exception('missing'); }

                        $currentBal = (int)$cRow['points_balance'];
                        if ($txnType === 'redeemed' && $points > $currentBal) { throw new Exception('insufficient:' . $currentBal); }
                        $balAfter = ($txnType === 'earned') ? $currentBal + $points : $currentBal - $points;
                        $rupeeVal = ($txnType === 'redeemed') ? $points * $ratio : 0.00;

                        $stmt = $conn->prepare("INSERT INTO point_transactions (customer_id, transaction_type, points, rupee_value, description, balance_after, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("isidsii", $cid, $txnType, $points, $rupeeVal, $desc, $balAfter, $user_id);
                        $stmt->execute(); $stmt->close();

                        $stmt = $conn->prepare("UPDATE customers SET points_balance = ? WHERE id = ?");
                        $stmt->bind_param("ii", $balAfter, $cid);
                        $stmt->execute(); $stmt->close();

                        $conn->commit();
                        $inserted++;
                    } catch (Exception $ex) {
                        $conn->rollback();
                        $m = $ex->getMessage();
                        $errors[] = strpos($m, 'insufficient:') === 0
                            ? "Row $line: '$custRaw' has only " . substr($m, 13) . " points, cannot redeem $points"
                            : "Row $line: failed to post points for '$custRaw'";
                    }
                }

                logActivity($user_id, $username, 'Bulk Points Add', "Posted $inserted point transactions, " . count($errors) . " errors");
                $conn->close();
                echo json_encode(['success'=>true, 'message'=>"$inserted point transaction(s) posted.", 'inserted'=>$inserted, 'errors'=>$errors]);
                exit();

            case 'addPointTransaction':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $cid = (int)($_POST['customer_id'] ?? 0);
                $txnType = trim($_POST['transaction_type'] ?? '');
                $points = (int)($_POST['points'] ?? 0);
                $desc = trim($_POST['description'] ?? '');

                if ($cid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Customer is required']);
                    exit();
                }
                if (!in_array($txnType, ['earned', 'redeemed'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid transaction type']);
                    exit();
                }
                if ($points <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Points must be greater than 0']);
                    exit();
                }
                if (empty($desc)) {
                    echo json_encode(['success' => false, 'message' => 'Description is required']);
                    exit();
                }

                // get current balance
                $stmt = $conn->prepare("SELECT points_balance FROM customers WHERE id = ?");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $cRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$cRow) {
                    echo json_encode(['success' => false, 'message' => 'Customer not found']);
                    $conn->close();
                    exit();
                }

                $currentBal = (int)$cRow['points_balance'];

                if ($txnType === 'redeemed' && $points > $currentBal) {
                    echo json_encode(['success' => false, 'message' => 'Insufficient points balance (current: ' . $currentBal . ')']);
                    $conn->close();
                    exit();
                }

                $balAfter = ($txnType === 'earned') ? $currentBal + $points : $currentBal - $points;

                // rupee value for redemptions: points * ratio
                $rupeeVal = 0.00;
                if ($txnType === 'redeemed') {
                    $ratio = (float)getSetting('points_to_rupees_ratio', '10');
                    $rupeeVal = $points * $ratio;
                }

                $stmt = $conn->prepare("INSERT INTO point_transactions (customer_id, transaction_type, points, rupee_value, description, balance_after, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isidsii", $cid, $txnType, $points, $rupeeVal, $desc, $balAfter, $user_id);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add transaction']);
                    exit();
                }
                $stmt->close();

                // update customer balance
                $stmt = $conn->prepare("UPDATE customers SET points_balance = ? WHERE id = ?");
                $stmt->bind_param("ii", $balAfter, $cid);
                $stmt->execute();
                $stmt->close();

                logActivity($user_id, $username, 'Points Transaction', "Customer #$cid: $txnType $points pts, balance: $balAfter");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Point transaction added successfully']);
                exit();

            case 'updatePointTransaction':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $ptid = (int)($_POST['id'] ?? 0);
                $txnType = trim($_POST['transaction_type'] ?? '');
                $points = (int)($_POST['points'] ?? 0);
                $desc = trim($_POST['description'] ?? '');

                if ($ptid <= 0 || empty($txnType) || $points <= 0 || empty($desc)) {
                    echo json_encode(['success' => false, 'message' => 'All fields required']);
                    exit();
                }
                if (!in_array($txnType, ['earned', 'redeemed'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid transaction type']);
                    exit();
                }

                // get old record
                $stmt = $conn->prepare("SELECT customer_id FROM point_transactions WHERE id = ?");
                $stmt->bind_param("i", $ptid);
                $stmt->execute();
                $old = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$old) {
                    echo json_encode(['success' => false, 'message' => 'Not found']);
                    exit();
                }

                $cid = (int)$old['customer_id'];

                // calc rupee value for redemptions: points * ratio
                $rupeeVal = 0.00;
                if ($txnType === 'redeemed') {
                    $ratio = (float)getSetting('points_to_rupees_ratio', '10');
                    $rupeeVal = $points * $ratio;
                }

                $conn->begin_transaction();
                try {
                    // apply the edit (balance_after recomputed below)
                    $stmt = $conn->prepare("UPDATE point_transactions SET transaction_type=?, points=?, rupee_value=?, description=? WHERE id=?");
                    $stmt->bind_param("sidsi", $txnType, $points, $rupeeVal, $desc, $ptid);
                    if (!$stmt->execute()) { throw new Exception('update failed'); }
                    $stmt->close();

                    // recompute balance_after for whole customer chain in date/id order
                    $chain = $conn->prepare("SELECT id, transaction_type, points FROM point_transactions WHERE customer_id = ? ORDER BY created_at ASC, id ASC");
                    $chain->bind_param("i", $cid);
                    $chain->execute();
                    $rows = $chain->get_result();
                    $running = 0;
                    $upd = $conn->prepare("UPDATE point_transactions SET balance_after = ? WHERE id = ?");
                    while ($r = $rows->fetch_assoc()) {
                        $running += ($r['transaction_type'] === 'earned') ? (int)$r['points'] : -(int)$r['points'];
                        if ($running < 0) { $chain->close(); $upd->close(); throw new Exception('negative balance in chain'); }
                        $rid2 = (int)$r['id'];
                        $upd->bind_param("ii", $running, $rid2);
                        $upd->execute();
                    }
                    $chain->close();
                    $upd->close();

                    // customer balance = chain end
                    $stmt = $conn->prepare("UPDATE customers SET points_balance = ? WHERE id = ?");
                    $stmt->bind_param("ii", $running, $cid);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    $msg = ($ex->getMessage() === 'negative balance in chain') ? 'Edit would make balance negative' : 'Failed to update point transaction';
                    echo json_encode(['success' => false, 'message' => $msg]);
                    exit();
                }

                logActivity($user_id, $username, 'Point Transaction Updated', "Updated point transaction #$ptid");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Point transaction updated']);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("referrals.php error: " . $e->getMessage());
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
    <title>Referrals & Points - Insurance Management System</title>

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
            $pageIcon    = 'fa-people-arrows';
            $pageTitle   = 'Referrals & Points';
            $crumbs      = [];
            $pageActions = [];
            if ($isAdmin) {
                $pageActions[] = ['label' => 'Add Referral', 'icon' => 'fa-plus', 'onclick' => 'rfAdd()', 'class' => 'primary', 'id' => 'rfAddBtn'];
                $pageActions[] = ['label' => 'Bulk Add / Import Points', 'icon' => 'fa-file-excel', 'onclick' => 'rfImport()'];
            }
            $pageActions[] = ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(rfTable(),'csv')"];
            $pageActions[] = ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(rfTable(),'pdf')"];
            $pageActions[] = ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(rfTable(),'print')"];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'rfRefresh()'];
            include 'page_header.php';
            ?>
            <div data-head-filter id="refStrip">
                <div class="hf-field"><label for="refSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="refSearch" placeholder="Search referrals…" autocomplete="off"></div>
                <div class="hf-field"><label for="filterRefStatus" title="Status"><i class="fas fa-flag"></i></label><select id="filterRefStatus" class="filter-input searchable">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="completed">Completed</option>
                    <option value="expired">Expired</option>
                </select></div>
                <div class="hf-field hf-range" title="Date range"><label for="filterRefDateFrom"><i class="fas fa-calendar-days"></i></label><input type="date" id="filterRefDateFrom" class="filter-input" aria-label="From"><span class="hf-sep"><i class="fas fa-arrow-right-long"></i></span><input type="date" id="filterRefDateTo" class="filter-input" aria-label="To"></div>
                <div class="hf-seg" role="group" aria-label="Quick range"><button type="button" onclick="rsDateRange('filterRefDateFrom','filterRefDateTo',0,this)">Today</button><button type="button" onclick="rsDateRange('filterRefDateFrom','filterRefDateTo',6,this)">7 days</button><button type="button" onclick="rsDateRange('filterRefDateFrom','filterRefDateTo',29,this)">30 days</button></div>
                <button type="button" class="btn btn-icon" onclick="clearRefFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <div data-head-filter id="ptStrip" style="display:none">
                <div class="hf-field"><label for="ptSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="ptSearch" placeholder="Search point ledger…" autocomplete="off"></div>
                <div class="hf-field"><label for="filterPtCustomer" title="Customer"><i class="fas fa-user"></i></label><select id="filterPtCustomer" class="filter-input searchable">
                    <option value="">All Customers</option>
                </select></div>
                <div class="hf-field"><label for="filterPtType" title="Type"><i class="fas fa-exchange-alt"></i></label><select id="filterPtType" class="filter-input searchable">
                    <option value="">All Types</option>
                    <option value="Earned">Earned</option>
                    <option value="Redeemed">Redeemed</option>
                </select></div>
                <div class="hf-field hf-range" title="Date range"><label for="filterPtDateFrom"><i class="fas fa-calendar-days"></i></label><input type="date" id="filterPtDateFrom" class="filter-input" aria-label="From"><span class="hf-sep"><i class="fas fa-arrow-right-long"></i></span><input type="date" id="filterPtDateTo" class="filter-input" aria-label="To"></div>
                <div class="hf-seg" role="group" aria-label="Quick range"><button type="button" onclick="rsDateRange('filterPtDateFrom','filterPtDateTo',0,this)">Today</button><button type="button" onclick="rsDateRange('filterPtDateFrom','filterPtDateTo',6,this)">7 days</button><button type="button" onclick="rsDateRange('filterPtDateFrom','filterPtDateTo',29,this)">30 days</button></div>
                <button type="button" class="btn btn-icon" onclick="clearPtFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>

            <!-- Tab Switcher -->
            <div class="page-tabs">
                <button class="page-tab-btn active" onclick="switchTab('referrals', this)">
                    <i class="fas fa-people-arrows"></i> Referrals
                </button>
                <button class="page-tab-btn" onclick="switchTab('points', this)">
                    <i class="fas fa-coins"></i> Point Ledger
                </button>
            </div>

            <!-- ==================== REFERRALS TAB ==================== -->
            <div class="page-tab-content active" id="tab-referrals">
                <div class="data-section">
                    <!-- Loading Skeleton -->
                    <div id="refSkeleton">
                        <div class="skeleton-table">
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            </div>
                            <?php for ($i = 0; $i < 6; $i++): ?>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- table -->
                    <div id="refTableContainer" style="display: none;">
                        <div id="referralsTable"></div>
                    </div>

                    <!-- empty state -->
                    <div id="refEmptyState" style="display: none;"></div>
                </div>
            </div>

            <!-- ==================== POINT LEDGER TAB ==================== -->
            <div class="page-tab-content" id="tab-points">
                <div class="data-section">
                    <!-- Loading Skeleton -->
                    <div id="ptSkeleton" style="display: none;">
                        <div class="skeleton-table">
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            </div>
                            <?php for ($i = 0; $i < 6; $i++): ?>
                            <div class="skeleton-table-row">
                                <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                                <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- table -->
                    <div id="ptTableContainer" style="display: none;">
                        <div id="pointsTable"></div>
                    </div>

                    <!-- empty state -->
                    <div id="ptEmptyState" style="display: none;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Detail Modal (shared by both tabs) -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="viewModalTitle"><i class="fas fa-eye"></i> Details</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- Add/Edit Referral Modal -->
    <div class="modal-overlay" id="refModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="refModalTitle"><i class="fas fa-people-arrows"></i> Add Referral</h3>
                <button class="close-btn" onclick="closeRefModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="refForm">
                    <input type="hidden" id="refId" name="id">

                    <div class="form-grid" id="refAddFields">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Referrer *</label>
                            <select id="refReferrer" name="referrer_id" class="searchable" required>
                                <option value="">Select Referrer</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-plus"></i> Referred *</label>
                            <select id="refReferred" name="referred_id" class="searchable" required>
                                <option value="">Select Referred</option>
                            </select>
                        </div>
                    </div>

                    <div id="refEditFields" style="display: none;">
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-flag"></i> Status *</label>
                                <select id="refStatus" name="status" class="searchable" required>
                                    <option value="pending">Pending</option>
                                    <option value="completed">Completed</option>
                                    <option value="expired">Expired</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-star"></i> Points Earned</label>
                                <input type="number" id="refPoints" name="points_earned" min="0" value="0">
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-file-invoice"></i> Policy Entry ID (optional)</label>
                            <input type="number" id="refPolicyId" name="policy_entry_id" min="0" placeholder="Policy entry ID">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeRefModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add/Edit Point Transaction Modal -->
    <div class="modal-overlay" id="ptModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="ptModalTitle"><i class="fas fa-coins"></i> Add Point Transaction</h3>
                <button class="close-btn" onclick="closePtModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="ptForm">
                    <input type="hidden" id="ptId" name="id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Customer *</label>
                            <select id="ptCustomer" name="customer_id" class="searchable" required>
                                <option value="">Select Customer</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-exchange-alt"></i> Type *</label>
                            <select id="ptType" name="transaction_type" class="searchable" required>
                                <option value="">Select Type</option>
                                <option value="earned">Earned</option>
                                <option value="redeemed">Redeemed</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-star"></i> Points *</label>
                            <input type="number" id="ptPoints" name="points" required min="1" placeholder="Number of points">
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-pencil-alt"></i> Description *</label>
                        <textarea id="ptDesc" name="description" required rows="3" placeholder="Transaction description"></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closePtModal()">
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
        let refTable = null, ptTable = null;
        let refBulkSel = null, ptBulkSel = null;
        let refData = [], ptData = [];
        let customersCache = [];
        let isRefEdit = false;
        let pointsTabLoaded = false;

        $(document).ready(function() {
            loadCustomersList();
            loadReferrals();

            // one delegated handler per table
            $('#referralsTable').on('click', '[data-act]', function() {
                var act = $(this).data('act'), id = parseInt($(this).data('id'), 10);
                if (act === 'view') viewReferral(id);
                else if (act === 'edit' && IS_ADMIN) editReferral(id);
                else if (act === 'delete' && IS_ADMIN) deleteReferral(id);
            });
            $('#pointsTable').on('click', '[data-act]', function() {
                var act = $(this).data('act'), id = parseInt($(this).data('id'), 10);
                if (act === 'view') viewPointTxn(id);
                else if (act === 'edit' && IS_ADMIN) editPointTxn(id);
            });
        });

        // header buttons act on the active tab
        function rfOnPoints() { return $('#tab-points').hasClass('active'); }
        function rfTable() { return rfOnPoints() ? ptTable : refTable; }
        function rfRefresh() { rfOnPoints() ? loadPoints() : loadReferrals(); }
        function rfAdd() { rfOnPoints() ? openPtModal() : openRefModal(); }
        function rfImport() { if (!rfOnPoints()) switchTab('points', $('.page-tab-btn').eq(1)[0]); pointsImport.open(); }
        $('#refSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(refTable, v); },200); });
        $('#ptSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(ptTable, v); },200); });

        // tab switching
        function switchTab(tab, btn) {
            $('.page-tab-btn').removeClass('active');
            $(btn).addClass('active');
            $('.page-tab-content').removeClass('active');
            $('#tab-' + tab).addClass('active');
            $('#refStrip').toggle(tab === 'referrals'); // header filters follow the tab
            $('#ptStrip').toggle(tab === 'points');
            var _ab = document.getElementById('rfAddBtn'), _al = tab === 'points' ? 'Add Transaction' : 'Add Referral';
            if (_ab) { _ab.title = _al; _ab.setAttribute('aria-label', _al); _ab.querySelector('span').textContent = _al; }

            // lazy load points tab
            if (tab === 'points' && !pointsTabLoaded) {
                pointsTabLoaded = true;
                loadPoints();
            }
        }

        function loadCustomersList() {
            $.ajax({
                url: '?action=getCustomersList',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (!res.success) return;
                    customersCache = res.data;
                    populateCustomerDropdowns(res.data);
                }
            });
        }

        function populateCustomerDropdowns(data) {
            // filter dropdown
            var filterSel = $('#filterPtCustomer');
            filterSel.find('option:not(:first)').remove();
            data.forEach(function(c) {
                var label = escHtml(c.name) + ' (' + (c.referral_code || '-') + ')';
                filterSel.append('<option value="' + escHtml(c.name) + '">' + label + '</option>');
            });

            <?php if ($isAdmin): ?>
            // modal dropdowns
            ['#refReferrer', '#refReferred', '#ptCustomer'].forEach(function(sel) {
                var $s = $(sel);
                $s.find('option:not(:first)').remove();
                data.forEach(function(c) {
                    var label = escHtml(c.name) + ' (' + (c.referral_code || '-') + ')';
                    $s.append('<option value="' + c.id + '">' + label + '</option>');
                });
            });
            <?php endif; ?>
            setTimeout(function(){ if (typeof initAllSearchableDropdowns === 'function') initAllSearchableDropdowns(); }, 100);
        }

        // ==================== REFERRALS ====================
        function loadReferrals() {
            $('#refSkeleton').show();
            $('#refTableContainer').hide();

            $.ajax({
                url: '?action=getReferrals',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        refData = res.data;
                        $('#refSkeleton').hide();

                        // empty state when no records
                        if (!res.data.length) {
                            $('#refTableContainer').hide();
                            $('#refEmptyState').html(emptyStateHtml({
                                icon: 'fa-people-arrows',
                                title: 'No referrals yet',
                                text: 'Link a referrer to a referred customer to start tracking referrals.',
                                btnText: IS_ADMIN ? 'Add Referral' : '',
                                btnId: IS_ADMIN ? 'refEmptyAddBtn' : ''
                            })).show();
                            if (IS_ADMIN) $('#refEmptyAddBtn').off('click').on('click', openRefModal);
                            return;
                        }

                        $('#refEmptyState').hide();
                        $('#refTableContainer').show();
                        setTimeout(function() { initRefTable(res.data); }, 100);
                    } else {
                        $('#refSkeleton').hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load referrals' });
                    }
                },
                error: function() {
                    $('#refSkeleton').hide();
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
                }
            });
        }

        function initRefTable(data) {
            if (refTable) return rsTbData(refTable, data); // build once, swap rows

            var cols = [
                { data:'id', title:'<input type="checkbox" class="bulk-check-all">', orderable:false, searchable:false, className:'bulk-col', render:function(d){ return '<input type="checkbox" class="bulk-check" data-id="'+d+'">'; } },
                { data: 'id', title: 'ID' },
                {
                    data: null,
                    title: 'Referrer',
                    render: function(d, t, row) {
                        if (t !== 'display') return row.referrer_name || '';
                        return '<a href="customers.php" title="Open customers">' + escHtml(row.referrer_name) + '</a>' +
                               '<br><small style="color:var(--text-muted);">' + escHtml(row.referrer_code || '-') + '</small>';
                    }
                },
                {
                    data: null,
                    title: 'Referred',
                    render: function(d, t, row) {
                        if (t !== 'display') return row.referred_name || '';
                        return '<a href="customers.php" title="Open customers">' + escHtml(row.referred_name) + '</a>' +
                               '<br><small style="color:var(--text-muted);">' + escHtml(row.referred_code || '-') + '</small>';
                    }
                },
                {
                    data: 'policy_entry_id',
                    title: 'Policy #',
                    render: function(d) {
                        if (!d) return '<em style="color:#999;">-</em>';
                        return '<a href="policy_entries.php?id=' + d + '" title="Open policy"><span class="badge-code"><i class="fas fa-file-alt"></i> #' + d + '</span></a>';
                    }
                },
                { data: 'points_earned', title: 'Points' },
                {
                    data: 'status',
                    title: 'Status',
                    render: function(d) {
                        return '<span class="ref-status-badge ' + d + '">' + d.charAt(0).toUpperCase() + d.slice(1) + '</span>';
                    }
                },
                {
                    data: 'created_at',
                    title: 'Created',
                    render: function(d) {
                        if (!d) return '-';
                        return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                    }
                },
                {
                    data: 'completed_at',
                    title: 'Completed',
                    render: function(d) {
                        if (!d) return '<em style="color:#999;">-</em>';
                        return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                    }
                }
            ];

            // action hub - view for all, edit/delete in ⋮ more for admin
            cols.push({
                data: null,
                title: 'Actions',
                orderable: false,
                render: function(d, t, row) {
                    var html = '<div class="action-hub">';
                    html += '<button class="action-icon view-icon" data-act="view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button>';
                    if (IS_ADMIN) {
                        html += '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button>' +
                                '<div class="more-dropdown">' +
                                '<button class="more-item" data-act="edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> Edit</button>' +
                                '<div class="more-divider"></div>' +
                                '<button class="more-item danger" data-act="delete" data-id="' + row.id + '"><i class="fas fa-trash"></i> Delete</button>' +
                                '</div></div>';
                    }
                    html += '</div>';
                    return html;
                }
            });

            refTable = rsTable('#referralsTable', {
                columns: cols, data: data, title: 'Referrals', file: 'referrals', sort: ['id', 'desc'], bulk: IS_ADMIN, // delete = admin only
                // row tint by referral status
                rowClass: function(r) { return { pending: 'dt-row-warning', completed: 'dt-row-success', expired: 'dt-row-muted' }[r.status] || ''; },
                info: function() {
                    var f = $('#filterRefDateFrom').val(), t = $('#filterRefDateTo').val();
                    return [['Status', $('#filterRefStatus').val() || 'All'], ['Date', (f || t) ? (f || '…') + ' to ' + (t || '…') : 'All']];
                }
            });

            $('#filterRefStatus').off('change').on('change', applyRefFilters);
            $('#filterRefDateFrom, #filterRefDateTo').off('change').on('change', applyRefFilters);
            rsTbSearch(refTable, $('#refSearch').val());

            if (IS_ADMIN) refBulkSel = rfBulk({
                table: refTable,
                label: 'referrals',
                onDelete: function(ids) {
                    showLoading('Deleting...');
                    $.ajax({
                        url: '?action=bulkDelete', method: 'POST', dataType: 'json',
                        data: { csrf_token: window.CSRF_TOKEN, ids: ids },
                        success: function(res) {
                            hideLoading();
                            if (res.success) { refBulkSel.clear(); loadReferrals(); Swal.fire('Deleted', res.message, 'success'); }
                            else { Swal.fire('Error', res.message, 'error'); }
                        },
                        error: function() { hideLoading(); Swal.fire('Error', 'Request failed', 'error'); }
                    });
                }
            });
            applyRefFilters();
        }

        // initBulkSelect drops every .bulk-bar - park the other tab's bar, put it back after
        function rfBulk(o) {
            var kept = $('.bulk-bar').map(function() { return [[this, this.nextSibling]]; }).get();
            kept.forEach(function(k) { $(k[0]).detach(); });
            var api = initBulkSelect(o);
            kept.forEach(function(k) { if (k[1] && k[1].parentNode) k[1].parentNode.insertBefore(k[0], k[1]); });
            return api;
        }

        // created_at date-range predicate
        function rfDateWhere(from, to) {
            return (from || to) ? function(r) {
                var d = String(r.created_at || '').substring(0, 10);
                return !(from && d < from) && !(to && d > to);
            } : null;
        }
        function applyRefFilters() {
            if (!refTable) return;
            var status = $('#filterRefStatus').val();

            rsTbWhere(refTable, 'status', status ? function(r) { return r.status === status; } : null);
            rsTbWhere(refTable, 'date', rfDateWhere($('#filterRefDateFrom').val(), $('#filterRefDateTo').val()));
        }

        function clearRefFilters() {
            $('#refSearch').val(''); $('#refStrip .hf-seg .is-on').removeClass('is-on');
            $('#filterRefStatus').val('');
            $('#filterRefDateFrom').val('');
            $('#filterRefDateTo').val('');
            var el = document.getElementById('filterRefStatus'); if (el && el.sdRefresh) el.sdRefresh();
            rsTbClear(refTable);
        }

        <?php if ($isAdmin): ?>
        function openRefModal() {
            isRefEdit = false;
            $('#refModalTitle').html('<i class="fas fa-people-arrows"></i> Add Referral');
            $('#refForm')[0].reset();
            $('#refId').val('');
            $('#refAddFields').show();
            $('#refEditFields').hide();
            $('#refReferrer').prop('required', true);
            $('#refReferred').prop('required', true);
            $('#refModal').addClass('active');
        }

        function editReferral(id) {
            var ref = refData.find(function(r) { return r.id === id; });
            if (!ref) return;
            isRefEdit = true;
            $('#refModalTitle').html('<i class="fas fa-edit"></i> Edit Referral');
            $('#refId').val(ref.id);
            $('#refAddFields').hide();
            $('#refEditFields').show();
            $('#refReferrer').prop('required', false);
            $('#refReferred').prop('required', false);
            $('#refStatus').val(ref.status);
            $('#refPoints').val(ref.points_earned);
            $('#refPolicyId').val(ref.policy_entry_id || '');
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
            $('#refModal').addClass('active');
        }

        function closeRefModal() {
            $('#refModal').removeClass('active');
            $('#refForm')[0].reset();
        }

        document.getElementById('refModal').addEventListener('click', function(e) {
            if (e.target === this) closeRefModal();
        });

        $('#refForm').on('submit', function(e) {
            e.preventDefault();

            var formData = new FormData(this);
            formData.append('csrf_token', window.CSRF_TOKEN);
            var action = isRefEdit ? 'updateReferral' : 'addReferral';

            showLoading('Saving referral...');

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
                        closeRefModal();
                        setTimeout(loadReferrals, 100);
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

        function deleteReferral(rid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Referral?',
                text: 'This action cannot be undone',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) return;

                var fd = new FormData();
                fd.append('id', rid);
                fd.append('csrf_token', window.CSRF_TOKEN);

                $.ajax({
                    url: '?action=deleteReferral',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                            setTimeout(loadReferrals, 100);
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
        <?php endif; ?>

        // ==================== POINT LEDGER ====================
        function loadPoints() {
            $('#ptSkeleton').show();
            $('#ptTableContainer').hide();

            $.ajax({
                url: '?action=getPointTransactions',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        ptData = res.data;
                        $('#ptSkeleton').hide();

                        // empty state when no records
                        if (!res.data.length) {
                            $('#ptTableContainer').hide();
                            $('#ptEmptyState').html(emptyStateHtml({
                                icon: 'fa-coins',
                                title: 'No point transactions yet',
                                text: 'Earned and redeemed loyalty points will appear here.',
                                btnText: IS_ADMIN ? 'Add Transaction' : '',
                                btnId: IS_ADMIN ? 'ptEmptyAddBtn' : ''
                            })).show();
                            if (IS_ADMIN) $('#ptEmptyAddBtn').off('click').on('click', openPtModal);
                            return;
                        }

                        $('#ptEmptyState').hide();
                        $('#ptTableContainer').show();
                        setTimeout(function() { initPtTable(res.data); }, 100);
                    } else {
                        $('#ptSkeleton').hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load transactions' });
                    }
                },
                error: function() {
                    $('#ptSkeleton').hide();
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
                }
            });
        }

        function initPtTable(data) {
            if (ptTable) return rsTbData(ptTable, data); // build once, swap rows

            var cols = [
                { data:'id', title:'<input type="checkbox" class="bulk-check-all">', orderable:false, searchable:false, className:'bulk-col', render:function(d){ return '<input type="checkbox" class="bulk-check" data-id="'+d+'">'; } },
                { data: 'id', title: 'ID' },
                {
                    data: null,
                    title: 'Customer',
                    render: function(d, t, row) {
                        if (t !== 'display') return row.customer_name || '';
                        return '<a href="customers.php" title="Open customers">' + escHtml(row.customer_name) + '</a>' +
                               '<br><small style="color:var(--text-muted);">' + escHtml(row.referral_code || '-') + '</small>';
                    }
                },
                {
                    data: 'transaction_type',
                    title: 'Type',
                    render: function(d) {
                        var icon = d === 'earned' ? '<i class="fas fa-arrow-up"></i>' : '<i class="fas fa-arrow-down"></i>';
                        return '<span class="pt-type-badge ' + d + '">' + icon + ' ' + d.charAt(0).toUpperCase() + d.slice(1) + '</span>';
                    }
                },
                { data: 'points', title: 'Points' },
                {
                    data: 'rupee_value',
                    title: 'Rupee Value',
                    render: function(d) {
                        return '<span class="wallet-amount">\u20B9' + parseFloat(d).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '</span>';
                    }
                },
                { data: 'description', title: 'Description' },
                { data: 'balance_after', title: 'Balance After' },
                { data: 'created_by_name', title: 'Created By', visible: false },
                {
                    data: 'created_at',
                    title: 'Created',
                    render: function(d) {
                        if (!d) return '-';
                        return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                    }
                }
            ];

            // action hub - view for all, edit in ⋮ more for admin
            cols.push({
                data: null,
                title: 'Actions',
                orderable: false,
                render: function(d, t, row) {
                    var html = '<div class="action-hub">';
                    html += '<button class="action-icon view-icon" data-act="view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button>';
                    if (IS_ADMIN) {
                        html += '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button>' +
                                '<div class="more-dropdown">' +
                                '<button class="more-item" data-act="edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> Edit</button>' +
                                '</div></div>';
                    }
                    html += '</div>';
                    return html;
                }
            });

            ptTable = rsTable('#pointsTable', {
                columns: cols, data: data, title: 'Point Ledger', file: 'point_ledger', sort: ['id', 'desc'], bulk: IS_ADMIN, // delete = admin only
                // row tint by txn type
                rowClass: function(r) { return { earned: 'dt-row-success', redeemed: 'dt-row-warning' }[r.transaction_type] || ''; },
                info: function() {
                    var f = $('#filterPtDateFrom').val(), t = $('#filterPtDateTo').val();
                    return [['Customer', $('#filterPtCustomer').val() || 'All'], ['Type', $('#filterPtType').val() || 'All'],
                        ['Date', (f || t) ? (f || '…') + ' to ' + (t || '…') : 'All']];
                }
            });

            $('#filterPtCustomer, #filterPtType').off('change').on('change', applyPtFilters);
            $('#filterPtDateFrom, #filterPtDateTo').off('change').on('change', applyPtFilters);
            rsTbSearch(ptTable, $('#ptSearch').val());

            if (IS_ADMIN) ptBulkSel = rfBulk({
                table: ptTable,
                label: 'point transactions',
                onDelete: function(ids) {
                    showLoading('Deleting...');
                    $.ajax({
                        url: '?action=bulkDeletePoints', method: 'POST', dataType: 'json',
                        data: { csrf_token: window.CSRF_TOKEN, ids: ids },
                        success: function(res) {
                            hideLoading();
                            if (res.success) { ptBulkSel.clear(); loadPoints(); Swal.fire('Deleted', res.message, 'success'); }
                            else { Swal.fire('Error', res.message, 'error'); }
                        },
                        error: function() { hideLoading(); Swal.fire('Error', 'Request failed', 'error'); }
                    });
                }
            });
            applyPtFilters();
        }

        function applyPtFilters() {
            if (!ptTable) return;

            var cust = $('#filterPtCustomer').val();
            var type = $('#filterPtType').val();

            // customer = contains, type = contains (case-insensitive)
            rsTbWhere(ptTable, 'cust', cust ? function(r) { return String(r.customer_name || '').toLowerCase().indexOf(cust.toLowerCase()) > -1; } : null);
            rsTbWhere(ptTable, 'type', type ? function(r) { return String(r.transaction_type || '').toLowerCase().indexOf(type.toLowerCase()) > -1; } : null);
            rsTbWhere(ptTable, 'date', rfDateWhere($('#filterPtDateFrom').val(), $('#filterPtDateTo').val()));
        }

        function clearPtFilters() {
            $('#ptSearch').val(''); $('#ptStrip .hf-seg .is-on').removeClass('is-on');
            $('#filterPtCustomer').val('');
            $('#filterPtType').val('');
            $('#filterPtDateFrom').val('');
            $('#filterPtDateTo').val('');
            ['filterPtCustomer', 'filterPtType'].forEach(function(id) { var el = document.getElementById(id); if (el && el.sdRefresh) el.sdRefresh(); });
            rsTbClear(ptTable);
        }

        <?php if ($isAdmin): ?>
        var isPointEditMode = false;

        function openPtModal() {
            isPointEditMode = false;
            $('#ptModalTitle').html('<i class="fas fa-coins"></i> Add Point Transaction');
            $('#ptForm')[0].reset();
            $('#ptId').val('');
            $('#ptCustomer').prop('disabled', false);
            $('#ptModal').addClass('active');
        }

        function editPointTxn(id) {
            var row = ptData.find(function(r) { return r.id === id; });
            if (!row) return;
            isPointEditMode = true;
            $('#ptModalTitle').html('<i class="fas fa-edit"></i> Edit Point Transaction');
            $('#ptId').val(row.id);
            $('#ptCustomer').val(row.customer_id).prop('disabled', true);
            $('#ptType').val(row.transaction_type);
            $('#ptPoints').val(row.points);
            $('#ptDesc').val(row.description);
            // refresh searchable dropdowns if any
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
            $('#ptModal').addClass('active');
        }

        function closePtModal() {
            $('#ptModal').removeClass('active');
            $('#ptForm')[0].reset();
            $('#ptCustomer').prop('disabled', false);
            isPointEditMode = false;
        }

        document.getElementById('ptModal').addEventListener('click', function(e) {
            if (e.target === this) closePtModal();
        });

        $('#ptForm').on('submit', function(e) {
            e.preventDefault();

            // re-enable customer so it gets submitted
            var custDisabled = $('#ptCustomer').prop('disabled');
            if (custDisabled) $('#ptCustomer').prop('disabled', false);

            var formData = new FormData(this);
            formData.append('csrf_token', window.CSRF_TOKEN);

            // re-disable if editing
            if (custDisabled) $('#ptCustomer').prop('disabled', true);

            var action = isPointEditMode ? 'updatePointTransaction' : 'addPointTransaction';

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
                        closePtModal();
                        setTimeout(loadPoints, 100);
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
        <?php endif; ?>

        // ==================== VIEW MODALS ====================
        function viewReferral(id) {
            var d = refData.find(function(r) { return r.id === id; });
            if (!d) return;

            var statusTxt = d.status.charAt(0).toUpperCase() + d.status.slice(1);

            // fk links
            var referrerHtml = '<a href="customers.php" title="Open customers">' + escHtml(d.referrer_name) +
                '</a> <small style="color:var(--text-muted);">(' + escHtml(d.referrer_code || '-') + ')</small>';
            var referredHtml = '<a href="customers.php" title="Open customers">' + escHtml(d.referred_name) +
                '</a> <small style="color:var(--text-muted);">(' + escHtml(d.referred_code || '-') + ')</small>';
            var policyHtml = d.policy_entry_id
                ? '<a href="policy_entries.php?id=' + d.policy_entry_id + '" title="Open policy"><span class="badge-code"><i class="fas fa-file-alt"></i> #' + d.policy_entry_id + '</span></a>'
                : '<em style="color:#999;">-</em>';

            var html = '<table class="view-detail-table">';
            html += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">REF-' + String(d.id).padStart(4,'0') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-flag"></i> Status</td><td class="vd-value"><span class="ref-status-badge ' + d.status + '">' + statusTxt + '</span></td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-user"></i> Referrer</td><td class="vd-value">' + referrerHtml + '</td>';
            html += '<td class="vd-label"><i class="fas fa-user-plus"></i> Referred</td><td class="vd-value">' + referredHtml + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-file-invoice"></i> Policy Entry</td><td class="vd-value">' + policyHtml + '</td>';
            html += '<td class="vd-label"><i class="fas fa-star"></i> Points Earned</td><td class="vd-value"><strong>' + (d.points_earned || 0) + '</strong></td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-clock"></i> Created</td><td class="vd-value">' + escHtml(d.created_at || '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-check"></i> Completed</td><td class="vd-value">' + escHtml(d.completed_at || '-') + '</td></tr>';
            html += '</table>';

            document.getElementById('viewModalTitle').innerHTML = '<i class="fas fa-eye"></i> Referral Details';
            document.getElementById('viewModalBody').innerHTML = html;
            document.getElementById('viewModal').classList.add('active');
        }

        function viewPointTxn(id) {
            var d = ptData.find(function(r) { return r.id === id; });
            if (!d) return;

            var typeTxt = d.transaction_type.charAt(0).toUpperCase() + d.transaction_type.slice(1);
            var typeIcon = d.transaction_type === 'earned' ? '<i class="fas fa-arrow-up"></i>' : '<i class="fas fa-arrow-down"></i>';
            var rupeeFmt = '₹' + parseFloat(d.rupee_value).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            var custHtml = '<a href="customers.php" title="Open customers">' + escHtml(d.customer_name) +
                '</a> <small style="color:var(--text-muted);">(' + escHtml(d.referral_code || '-') + ')</small>';

            var html = '<table class="view-detail-table">';
            html += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">PT-' + String(d.id).padStart(4,'0') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-exchange-alt"></i> Type</td><td class="vd-value"><span class="pt-type-badge ' + d.transaction_type + '">' + typeIcon + ' ' + typeTxt + '</span></td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-user"></i> Customer</td><td class="vd-value" colspan="3">' + custHtml + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-star"></i> Points</td><td class="vd-value"><strong>' + (d.points || 0) + '</strong></td>';
            html += '<td class="vd-label"><i class="fas fa-money-bill-wave"></i> Rupee Value</td><td class="vd-value">' + rupeeFmt + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-wallet"></i> Balance After</td><td class="vd-value"><strong>' + (d.balance_after || 0) + '</strong></td>';
            html += '<td class="vd-label"><i class="fas fa-clock"></i> Created</td><td class="vd-value">' + escHtml(d.created_at || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-user-pen"></i> Created By</td><td class="vd-value" colspan="3">' + escHtml(d.created_by_name || 'System') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-pencil-alt"></i> Description</td><td class="vd-value" colspan="3">' + escHtml(d.description || '-') + '</td></tr>';
            html += '</table>';

            document.getElementById('viewModalTitle').innerHTML = '<i class="fas fa-eye"></i> Point Transaction Details';
            document.getElementById('viewModalBody').innerHTML = html;
            document.getElementById('viewModal').classList.add('active');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
        }

        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) closeViewModal();
        });

        // html escape
        function escHtml(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        // bulk add / excel import — engine lives in sidebar.php
        var pointsImport = RSImport({
            key: 'point_transactions', title: 'Bulk Add / Import Point Transactions', url: '?action=bulkAddPoints',
            hint: 'Award or redeem points for many customers at once — upload an Excel/CSV file or type the rows. <strong>Customer, type, points and description</strong> are all required. Customers are matched on referral code, then mobile, then name. Each row updates that customer\'s running balance in order, and a <strong>redemption larger than the customer\'s balance is rejected</strong> rather than driving it negative.',
            onDone: function() { setTimeout(function() { loadPoints(); loadCustomersList(); }, 100); },
            fields: [
                { key: 'customer', label: 'Customer *', cls: 'bg-cust', type: 'select', req: true,
                  opts: function() { return [{ v: '', t: '— Select —' }].concat((customersCache || []).map(function(c) { return { v: c.referral_code || c.name, t: c.name + (c.referral_code ? ' (' + c.referral_code + ')' : '') }; })); },
                  aliases: ['customer', 'customername', 'referralcode', 'refcode', 'name', 'mobile'], demo: '', demo2: '' },
                { key: 'transaction_type', label: 'Type *', cls: 'bg-type', type: 'select', req: true, def: 'earned',
                  opts: [{ v: 'earned', t: 'Earned' }, { v: 'redeemed', t: 'Redeemed' }],
                  aliases: ['transactiontype', 'type', 'txntype', 'action'], demo: 'earned', demo2: 'redeemed' },
                { key: 'points', label: 'Points *', cls: 'bg-pts', type: 'number', req: true, aliases: ['points', 'point', 'pts', 'qty', 'quantity'], demo: 100, demo2: 50 },
                { key: 'description', label: 'Description *', cls: 'bg-desc', req: true, max: 255, ph: 'Why these points', aliases: ['description', 'desc', 'reason', 'remarks', 'narration', 'details'], demo: 'Demo campaign bonus', demo2: 'Demo redemption' }
            ]
        });
    </script>
</body>
</html>
