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
$current_page = 'customers';

// agent/ckyc/whatsapp/dob/city/pin -> [vals] or error msg; $curAgent = agent already on the record
function customerExtras(array $src, $conn, $curAgent = null) {
    $agentId = !empty($src['agent_id']) ? (int)$src['agent_id'] : null;
    $ckyc = trim($src['ckyc_number'] ?? '');
    $wa = preg_replace('/[\s+-]/', '', trim($src['whatsapp_number'] ?? ''));
    $dob = trim($src['date_of_birth'] ?? '');
    $city = trim($src['city'] ?? '');
    $pin = trim($src['pincode'] ?? '');
    if ($ckyc !== '' && !preg_match('/^\d{14}$/', $ckyc)) return 'CKYC number must be 14 digits';
    if ($wa !== '' && !preg_match('/^\d{10,15}$/', $wa)) return 'WhatsApp number must be 10-15 digits';
    if ($dob !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$d || $d->format('Y-m-d') !== $dob || $dob > date('Y-m-d') || $dob < '1900-01-01') return 'Invalid date of birth';
    }
    if (strlen($city) > 100) return 'City max 100 characters';
    if ($pin !== '' && !preg_match('/^[1-9][0-9]{5}$/', $pin)) return 'Pincode must be 6 digits';
    if ($agentId) {
        $st = $conn->prepare("SELECT is_active FROM agents WHERE id = ?");
        $st->bind_param("i", $agentId); $st->execute();
        $ag = $st->get_result()->fetch_assoc(); $st->close();
        if (!$ag) return 'Agent not found';
        if (!$ag['is_active'] && $agentId !== (int)$curAgent) return 'Agent is inactive';
    }
    return [$agentId, $ckyc ?: null, $wa ?: null, $dob ?: null, $city ?: null, $pin ?: null];
}

// agent's own customer c: assigned to it, or on one of its policies (binds agent id twice)
const CUST_OWN_SQL = '(c.agent_id = ? OR EXISTS (SELECT 1 FROM policy_entries po WHERE po.customer_id = c.id AND po.agent_id = ?))';
// $scope: null = staff (all), else agent id (0 = no agent row -> nothing)
function custOwned($conn, $cid, $scope) {
    if ($scope === null) return true;
    $st = $conn->prepare('SELECT 1 FROM customers c WHERE c.id = ? AND ' . CUST_OWN_SQL);
    $st->bind_param('iii', $cid, $scope, $scope);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_row();
    $st->close();
    return $ok;
}

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();
        $scope = $role === 'agent' ? (agentIdForUser($conn, $user_id) ?? 0) : null;

        // per-customer reads: agent only its own customers
        if (in_array($_GET['action'], ['getCustomerPolicies', 'getCustomerVehicles', 'getCustomerPoints'], true)
            && !custOwned($conn, (int)($_GET['id'] ?? 0), $scope)) {
            echo json_encode(['success' => false, 'message' => 'Customer not found']);
            exit();
        }

        switch ($_GET['action']) {

            case 'getCustomers':
                // policy count + last policy date via subqueries; agent = own customers, own policies counted
                $pf = $scope === null ? '' : ' AND pe.agent_id = ?';
                $stmt = $conn->prepare("
                    SELECT c.*, COALESCE(ref.name, '-') as referred_by_name, ag.name as agent_name, ag.agent_code, cu.name as created_by_name,
                           DATE_FORMAT(c.created_at, '%b %d, %Y') as created_fmt,
                           (SELECT COUNT(*) FROM policy_entries pe WHERE pe.customer_id = c.id$pf) as policy_count,
                           (SELECT DATE_FORMAT(MAX(pe.entry_date), '%b %d, %Y') FROM policy_entries pe WHERE pe.customer_id = c.id$pf) as last_policy_fmt
                    FROM customers c
                    LEFT JOIN customers ref ON c.referred_by = ref.id
                    LEFT JOIN agents ag ON c.agent_id = ag.id
                    LEFT JOIN users cu ON c.created_by = cu.id
                    " . ($scope === null ? '' : 'WHERE ' . CUST_OWN_SQL) . "
                    ORDER BY c.id DESC
                ");
                if ($scope !== null) $stmt->bind_param('iiii', $scope, $scope, $scope, $scope);
                $stmt->execute();
                $res = $stmt->get_result();
                $customers = [];
                while ($row = $res->fetch_assoc()) {
                    $customers[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'mobile' => $row['mobile'],
                        'email' => $row['email'] ?: '-',
                        'address' => $row['address'],
                        'state' => $row['state'] ?: '-',
                        'referral_code' => $row['referral_code'],
                        'referred_by' => $row['referred_by'] ? (int)$row['referred_by'] : null,
                        'referred_by_name' => $row['referred_by_name'],
                        'points_balance' => (int)$row['points_balance'],
                        'agent_id' => $row['agent_id'] ? (int)$row['agent_id'] : null,
                        'agent_name' => $row['agent_name'] ? $row['agent_name'] . ' (' . $row['agent_code'] . ')' : '',
                        'ckyc_number' => $row['ckyc_number'],
                        'whatsapp_number' => $row['whatsapp_number'],
                        'date_of_birth' => $row['date_of_birth'],
                        'city' => $row['city'],
                        'pincode' => $row['pincode'],
                        'created_by_name' => $scope === null ? ($row['created_by_name'] ?? '') : '', // staff names stay staff-side
                        'is_active' => (int)$row['is_active'],
                        'created_at' => $row['created_fmt'],
                        'policy_count' => (int)$row['policy_count'],
                        'last_policy' => $row['last_policy_fmt'] ?: '-'
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $customers]);
                exit();

            case 'getCustomerPolicies':
                $cid = (int)($_GET['id'] ?? 0);
                if ($cid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid customer ID']); exit(); }
                $stmt = $conn->prepare("
                    SELECT id, policy_number, company_name, total_premium_amount, status, entry_date, policy_end_date,
                           DATE_FORMAT(entry_date, '%b %d, %Y') as entry_fmt,
                           DATE_FORMAT(policy_end_date, '%b %d, %Y') as expiry_fmt
                    FROM policy_entries WHERE customer_id = ?" . ($scope === null ? '' : ' AND agent_id = ?') . " ORDER BY entry_date DESC
                ");
                $scope === null ? $stmt->bind_param("i", $cid) : $stmt->bind_param("ii", $cid, $scope);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'policy_number' => $r['policy_number'],
                        'company_name' => $r['company_name'],
                        'premium' => number_format((float)$r['total_premium_amount'], 2),
                        'premium_amt' => (float)$r['total_premium_amount'],
                        'status' => $r['status'],
                        'entry_date' => $r['entry_fmt'],
                        'expiry' => $r['expiry_fmt'],
                        'entry_iso' => $r['entry_date'], // raw dates sort right
                        'expiry_iso' => $r['policy_end_date']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();

            case 'getCustomerVehicles':
                $cid = (int)($_GET['id'] ?? 0);
                if ($cid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid customer ID']); exit(); }
                $stmt = $conn->prepare("
                    SELECT vehicle_number, vehicle_type, make, model, year_of_manufacture, fuel_type
                    FROM vehicles WHERE customer_id = ? ORDER BY id DESC
                ");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'vehicle_number' => $r['vehicle_number'],
                        'vehicle_type' => $r['vehicle_type'],
                        'make' => $r['make'] ?: '-',
                        'model' => $r['model'] ?: '-',
                        'year' => $r['year_of_manufacture'] ?: '-',
                        'fuel_type' => $r['fuel_type'] ?: '-'
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();

            case 'getCustomerPoints':
                $cid = (int)($_GET['id'] ?? 0);
                if ($cid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid customer ID']); exit(); }
                // points ledger
                $stmt = $conn->prepare("
                    SELECT transaction_type, points, rupee_value, description, balance_after, created_at,
                           DATE_FORMAT(created_at, '%b %d, %Y') as created_fmt
                    FROM point_transactions WHERE customer_id = ? ORDER BY id DESC
                ");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'type' => $r['transaction_type'],
                        'points' => (int)$r['points'],
                        'rupee_value' => number_format((float)$r['rupee_value'], 2),
                        'value_amt' => (float)$r['rupee_value'],
                        'description' => $r['description'],
                        'balance_after' => (int)$r['balance_after'],
                        'date' => $r['created_fmt'],
                        'created_iso' => $r['created_at']
                    ];
                }
                $stmt->close();
                // referred customers list
                $stmt = $conn->prepare("SELECT c.name, c.mobile, c.referral_code FROM customers c WHERE c.referred_by = ?" . ($scope === null ? '' : ' AND ' . CUST_OWN_SQL) . " ORDER BY c.id DESC");
                $scope === null ? $stmt->bind_param("i", $cid) : $stmt->bind_param("iii", $cid, $scope, $scope);
                $stmt->execute();
                $res = $stmt->get_result();
                $refs = [];
                while ($r = $res->fetch_assoc()) $refs[] = $r;
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows, 'referrals' => $refs]);
                exit();

            case 'getAgentsList':
                if ($role === 'agent') { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
                $stmt = $conn->prepare("SELECT id, agent_code, name FROM agents WHERE is_active = 1 ORDER BY name");
                $stmt->execute();
                $list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $list]);
                exit();

            case 'getCustomersList':
                $stmt = $conn->prepare("SELECT c.id, c.name, c.referral_code FROM customers c WHERE c.is_active = 1" . ($scope === null ? '' : ' AND ' . CUST_OWN_SQL) . " ORDER BY c.name");
                if ($scope !== null) $stmt->bind_param("ii", $scope, $scope);
                $stmt->execute();
                $res = $stmt->get_result();
                $list = [];
                while ($row = $res->fetch_assoc()) $list[] = $row;
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $list]);
                exit();

            case 'addCustomer':
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
                $referredBy = !empty($_POST['referred_by']) ? (int)$_POST['referred_by'] : null;

                // validate
                if (strlen($name) < 2 || strlen($name) > 150) {
                    echo json_encode(['success' => false, 'message' => 'Name must be 2-150 characters']);
                    exit();
                }
                if (!preg_match('/^\d{10,15}$/', $mobile)) {
                    echo json_encode(['success' => false, 'message' => 'Mobile must be 10-15 digits']);
                    exit();
                }
                if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                // validate referrer exists
                if ($referredBy) {
                    $stmt = $conn->prepare("SELECT id FROM customers WHERE id = ?");
                    $stmt->bind_param("i", $referredBy);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows === 0) {
                        $stmt->close(); $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Referrer not found']);
                        exit();
                    }
                    $stmt->close();
                }

                $ex = customerExtras($_POST, $conn);
                if (is_string($ex)) { $conn->close(); echo json_encode(['success' => false, 'message' => $ex]); exit(); }
                [$agentId, $ckyc, $waNo, $dob, $city, $pin] = $ex;

                // insert without referral_code first
                $emailVal = $email ?: null;
                $addressVal = $address ?: null;
                $stateVal = $state ?: null;

                if ($referredBy) {
                    $stmt = $conn->prepare("INSERT INTO customers (name, mobile, email, address, state, referred_by, agent_id, ckyc_number, whatsapp_number, date_of_birth, city, pincode, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssssiisssssi", $name, $mobile, $emailVal, $addressVal, $stateVal, $referredBy, $agentId, $ckyc, $waNo, $dob, $city, $pin, $user_id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO customers (name, mobile, email, address, state, agent_id, ckyc_number, whatsapp_number, date_of_birth, city, pincode, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssssisssssi", $name, $mobile, $emailVal, $addressVal, $stateVal, $agentId, $ckyc, $waNo, $dob, $city, $pin, $user_id);
                }

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add customer']);
                    exit();
                }
                $insertId = $stmt->insert_id;
                $stmt->close();

                // generate referral_code from insert id
                $refCode = 'REF-' . str_pad($insertId, 4, '0', STR_PAD_LEFT);
                $stmt = $conn->prepare("UPDATE customers SET referral_code = ? WHERE id = ?");
                $stmt->bind_param("si", $refCode, $insertId);
                $stmt->execute();
                $stmt->close();

                logActivity($user_id, $username, 'Customer Created', "Created customer: $name (#$insertId)" . ($agentId ? ", agent #$agentId" : '') . ($city ? ", $city" : '') . ($ckyc ? ", CKYC $ckyc" : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Customer added successfully', 'data' => ['id' => $insertId]]);
                exit();

            case 'bulkAddCustomers':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows) || count($rows) === 0) { echo json_encode(['success'=>false,'message'=>'No customers to add']); exit(); }
                $off = max(0, (int)($_POST['row_offset'] ?? 0)); // client posts in chunks - keep row numbers global

                $inserted = 0; $errors = [];
                $seenPhone = []; // within-batch dedup
                foreach ($rows as $idx => $r) {
                    $line = $off + $idx + 1;
                    $name = trim($r['name'] ?? '');
                    $mobile = trim($r['mobile'] ?? '');
                    $email = trim($r['email'] ?? '');
                    $state = trim($r['state'] ?? '');
                    $addr = trim($r['address'] ?? '');
                    $ref = trim($r['referred_by'] ?? '');

                    if ($name === '' && $mobile === '') continue; // trailing blank line

                    if (strlen($name) < 2 || strlen($name) > 150) { $errors[] = "Row $line: name must be 2-150 characters"; continue; }
                    if (!preg_match('/^\d{10,15}$/', $mobile)) { $errors[] = "Row $line: mobile must be 10-15 digits"; continue; }
                    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "Row $line: invalid email '$email'"; continue; }

                    if (isset($seenPhone[$mobile])) { $errors[] = "Row $line: duplicate mobile '$mobile' in list"; continue; }
                    $stmt = $conn->prepare("SELECT id FROM customers WHERE mobile = ?");
                    $stmt->bind_param("s", $mobile); $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) { $stmt->close(); $errors[] = "Row $line: a customer with mobile '$mobile' already exists"; continue; }
                    $stmt->close();

                    // referrer by referral code or mobile
                    $referredBy = null;
                    if ($ref !== '') {
                        $stmt = $conn->prepare("SELECT id FROM customers WHERE referral_code = ? OR mobile = ? LIMIT 1");
                        $stmt->bind_param("ss", $ref, $ref); $stmt->execute();
                        $rr = $stmt->get_result()->fetch_assoc(); $stmt->close();
                        if (!$rr) { $errors[] = "Row $line: referrer '$ref' not found"; continue; }
                        $referredBy = (int)$rr['id'];
                    }

                    $ex = customerExtras($r, $conn);
                    if (is_string($ex)) { $errors[] = "Row $line: $ex"; continue; }
                    [$agentId, $ckyc, $waNo, $dob, $city, $pin] = $ex;

                    $emailVal = $email ?: null; $addressVal = $addr ?: null; $stateVal = $state ?: null;

                    $conn->begin_transaction();
                    try {
                        if ($referredBy) {
                            $stmt = $conn->prepare("INSERT INTO customers (name, mobile, email, address, state, referred_by, agent_id, ckyc_number, whatsapp_number, date_of_birth, city, pincode, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("sssssiisssssi", $name, $mobile, $emailVal, $addressVal, $stateVal, $referredBy, $agentId, $ckyc, $waNo, $dob, $city, $pin, $user_id);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO customers (name, mobile, email, address, state, agent_id, ckyc_number, whatsapp_number, date_of_birth, city, pincode, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("sssssisssssi", $name, $mobile, $emailVal, $addressVal, $stateVal, $agentId, $ckyc, $waNo, $dob, $city, $pin, $user_id);
                        }
                        $stmt->execute(); $newId = (int)$stmt->insert_id; $stmt->close();

                        // referral code from insert id, same as single add
                        $refCode = 'REF-' . str_pad($newId, 4, '0', STR_PAD_LEFT);
                        $stmt = $conn->prepare("UPDATE customers SET referral_code = ? WHERE id = ?");
                        $stmt->bind_param("si", $refCode, $newId);
                        $stmt->execute(); $stmt->close();

                        $conn->commit();
                        $seenPhone[$mobile] = 1;
                        $inserted++;
                    } catch (Exception $ex) {
                        $conn->rollback();
                        $errors[] = "Row $line: failed to add '$name'";
                    }
                }

                logActivity($user_id, $username, 'Bulk Customer Add', "Added $inserted customers, " . count($errors) . " errors");
                $conn->close();
                echo json_encode(['success'=>true, 'message'=>"$inserted customer(s) added.", 'inserted'=>$inserted, 'errors'=>$errors]);
                exit();

            case 'updateCustomer':
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
                $mobile = trim($_POST['mobile'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $state = trim($_POST['state'] ?? '');
                $referredBy = !empty($_POST['referred_by']) ? (int)$_POST['referred_by'] : null;
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                if ($cid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
                    exit();
                }
                if (strlen($name) < 2 || strlen($name) > 150) {
                    echo json_encode(['success' => false, 'message' => 'Name must be 2-150 characters']);
                    exit();
                }
                if (!preg_match('/^\d{10,15}$/', $mobile)) {
                    echo json_encode(['success' => false, 'message' => 'Mobile must be 10-15 digits']);
                    exit();
                }
                if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }

                // validate referrer
                if ($referredBy) {
                    if ($referredBy === $cid) {
                        echo json_encode(['success' => false, 'message' => 'Customer cannot refer themselves']);
                        exit();
                    }
                    $stmt = $conn->prepare("SELECT id FROM customers WHERE id = ?");
                    $stmt->bind_param("i", $referredBy);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows === 0) {
                        $stmt->close(); $conn->close();
                        echo json_encode(['success' => false, 'message' => 'Referrer not found']);
                        exit();
                    }
                    $stmt->close();
                }

                $emailVal = $email ?: null;
                $addressVal = $address ?: null;
                $stateVal = $state ?: null;

                // current agent - an inactive one may stay on the record
                $stmt = $conn->prepare("SELECT agent_id FROM customers WHERE id = ?");
                $stmt->bind_param("i", $cid); $stmt->execute();
                $curAgent = $stmt->get_result()->fetch_assoc()['agent_id'] ?? null; $stmt->close();
                $ex = customerExtras($_POST, $conn, $curAgent);
                if (is_string($ex)) { $conn->close(); echo json_encode(['success' => false, 'message' => $ex]); exit(); }
                [$agentId, $ckyc, $waNo, $dob, $city, $pin] = $ex;

                // no referral_code update
                if ($referredBy) {
                    $stmt = $conn->prepare("UPDATE customers SET name=?, mobile=?, email=?, address=?, state=?, referred_by=?, agent_id=?, ckyc_number=?, whatsapp_number=?, date_of_birth=?, city=?, pincode=?, is_active=? WHERE id=?");
                    $stmt->bind_param("sssssiisssssii", $name, $mobile, $emailVal, $addressVal, $stateVal, $referredBy, $agentId, $ckyc, $waNo, $dob, $city, $pin, $isActive, $cid);
                } else {
                    $stmt = $conn->prepare("UPDATE customers SET name=?, mobile=?, email=?, address=?, state=?, referred_by=NULL, agent_id=?, ckyc_number=?, whatsapp_number=?, date_of_birth=?, city=?, pincode=?, is_active=? WHERE id=?");
                    $stmt->bind_param("sssssisssssii", $name, $mobile, $emailVal, $addressVal, $stateVal, $agentId, $ckyc, $waNo, $dob, $city, $pin, $isActive, $cid);
                }

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update customer']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Customer Updated', "Updated customer #$cid: $name" . ($agentId ? ", agent #$agentId" : ', no agent') . ($city ? ", $city" : '') . ($ckyc ? ", CKYC $ckyc" : '') . ($waNo ? ", WA $waNo" : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Customer updated successfully']);
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
                foreach ($ids as $cid) {
                    // skip if linked policies (same check as single delete)
                    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM policy_entries WHERE customer_id = ?");
                    $stmt->bind_param("i", $cid);
                    $stmt->execute();
                    $linked = $stmt->get_result()->fetch_assoc()['cnt'];
                    $stmt->close();
                    if ($linked > 0) { $skipped++; continue; }

                    $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
                    $stmt->bind_param("i", $cid);
                    if ($stmt->execute() && $stmt->affected_rows > 0) $deleted++;
                    $stmt->close();
                }

                logActivity($user_id, $username, 'Bulk delete', "Deleted $deleted customer(s), skipped $skipped");
                $conn->close();
                $msg = "$deleted record(s) deleted";
                if ($skipped > 0) $msg .= " — $skipped skipped (linked policies)";
                echo json_encode(['success' => true, 'message' => $msg]);
                exit();

            case 'deleteCustomer':
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
                if ($cid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid customer ID']);
                    exit();
                }

                // get name before delete
                $stmt = $conn->prepare("SELECT name FROM customers WHERE id = ?");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $delRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $delName = $delRow ? $delRow['name'] : 'Unknown';

                // check linked policies
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM policy_entries WHERE customer_id = ?");
                $stmt->bind_param("i", $cid);
                $stmt->execute();
                $linked = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();
                if ($linked > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete customer — they have $linked linked policy entries"]);
                    exit();
                }

                $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
                $stmt->bind_param("i", $cid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete customer']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Customer Deleted', "Deleted customer: $delName (#$cid)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Customer deleted successfully']);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("customers.php error: " . $e->getMessage());
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
    <title>Customers - Insurance Management System</title>

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
            $pageIcon    = 'fa-users';
            $pageTitle   = $role === 'agent' ? 'My Customers' : 'Customer Management';
            $crumbs      = $role === 'agent' ? [] : [['label' => 'Buyers', 'icon' => 'fa-users']];
            $pageActions = [];
            if ($isAdmin) {
                $pageActions[] = ['label' => 'Add Customer', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
                $pageActions[] = ['label' => 'Bulk Add / Import', 'icon' => 'fa-file-excel', 'onclick' => 'customersImport.open()'];
            }
            // agent = read-only list of its own customers, exports kept
            $pageActions[] = ['label' => 'CSV',     'icon' => 'fa-file-csv', 'onclick' => "dtBtn(custTable,'csv')"];
            $pageActions[] = ['label' => 'PDF',     'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(custTable,'pdf')"];
            $pageActions[] = ['label' => 'Print',   'icon' => 'fa-print',    'onclick' => "dtBtn(custTable,'print')"];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate',   'onclick' => 'loadCustomers()'];
            include 'page_header.php';
            ?>

            <div data-head-filter>
                <div class="hf-field"><label for="custSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="custSearch" placeholder="Search customers…" autocomplete="off"></div>
                <div class="hf-field" title="State"><label for="filterState"><i class="fas fa-map-marker-alt"></i></label><input type="text" id="filterState" placeholder="State" autocomplete="off"></div>
                <div class="hf-field" title="Status"><label for="filterStatus"><i class="fas fa-toggle-on"></i></label>
                    <select id="filterStatus" class="searchable">
                        <option value="">All Status</option>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
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
                            <div class="skeleton skeleton-table-cell" style="flex: 1.4"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.4"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.9"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="tableContainer" style="display: none;">
                    <div id="customersTable"></div>
                </div>

                <!-- empty state mount -->
                <div id="emptyState" style="display: none;"></div>
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
    <!-- Customer Modal -->
    <div class="modal-overlay" id="customerModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Add Customer</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="customerForm">
                    <input type="hidden" id="customerId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Name *</label>
                            <input type="text" id="custName" name="name" required minlength="2" maxlength="150" placeholder="Full name">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Mobile *</label>
                            <input type="tel" id="custMobile" name="mobile" required minlength="10" maxlength="15" placeholder="10-15 digits" pattern="\d{10,15}">
                        </div>
                        <div class="form-group">
                            <label><i class="fab fa-whatsapp"></i> WhatsApp Number</label>
                            <input type="tel" id="custWhatsapp" name="whatsapp_number" maxlength="15" placeholder="10-15 digits" pattern="\d{10,15}">
                            <button type="button" class="btn-link-add" onclick="$('#custWhatsapp').val($('#custMobile').val()).trigger('input')"><i class="fas fa-copy"></i> Same as mobile</button>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" id="custEmail" name="email" placeholder="email@example.com">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-birthday-cake"></i> Date of Birth</label>
                            <input type="date" id="custDob" name="date_of_birth" min="1900-01-01" max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-id-card"></i> CKYC Number</label>
                            <input type="text" id="custCkyc" name="ckyc_number" inputmode="numeric" maxlength="14" pattern="\d{14}" placeholder="14 digits">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Agent</label>
                            <select id="custAgent" name="agent_id" class="searchable">
                                <option value="">No Agent</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-city"></i> City</label>
                            <input type="text" id="custCity" name="city" maxlength="100" placeholder="City">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-pin"></i> Pincode</label>
                            <input type="text" id="custPincode" name="pincode" inputmode="numeric" maxlength="6" pattern="[1-9][0-9]{5}" placeholder="6 digits">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map-marker-alt"></i> State</label>
                            <input type="text" id="custState" name="state" placeholder="State">
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label><i class="fas fa-home"></i> Address</label>
                            <textarea id="custAddress" name="address" rows="2" placeholder="Full address"></textarea>
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label><i class="fas fa-user-friends"></i> Referred By</label>
                            <select id="custReferredBy" name="referred_by" class="searchable">
                                <option value="">No Referrer</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" id="statusToggleGroup" style="display: none;">
                        <div class="toggle-switch">
                            <input type="checkbox" class="toggle" id="custIsActive" name="is_active" checked>
                            <label for="custIsActive">Active</label>
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
        /** Developed by Mohammad Rameez Imdad (Rameez Scripts) */
        const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        const IS_AGENT = <?php echo $role === 'agent' ? 'true' : 'false'; ?>;
        let custTable = null;
        let custData = [];
        let referralList = [];
        let agentList = [];
        let isEditMode = false;
        let bulkSel = null;

        $(document).ready(function() {
            if (!IS_AGENT) { loadCustomersList(); loadAgentsList(); } // staff dropdown data
            loadCustomers();
            // header search
            $('#custSearch').on('input', function() { clearTimeout(this._t); var v = this.value; this._t = setTimeout(function() { rsTbSearch(custTable, v); }, 200); });
        });

        // referral dropdown data
        function loadCustomersList() {
            $.ajax({
                url: '?action=getCustomersList',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (!res.success) return;
                    referralList = res.data;
                    if (IS_ADMIN) populateReferralDropdown();
                }
            });
        }

        // active agents for the assign dropdown + import
        function loadAgentsList() {
            $.getJSON('?action=getAgentsList', function(res) {
                if (!res.success) return;
                agentList = res.data;
                if (IS_ADMIN) populateAgentDropdown();
            });
        }

        // keep an inactive agent already on the record selectable
        function populateAgentDropdown(cur) {
            var sel = $('#custAgent');
            sel.find('option:not(:first)').remove();
            agentList.forEach(function(a) { sel.append($('<option>').val(a.id).text(a.name + ' (' + a.agent_code + ')')); });
            if (cur && cur.agent_id && !agentList.some(function(a) { return a.id == cur.agent_id; }))
                sel.append($('<option>').val(cur.agent_id).text((cur.agent_name || 'Agent #' + cur.agent_id) + ' - inactive'));
            var el = sel[0];
            if (el && el.sdRefresh) el.sdRefresh(); else setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
        }

        function populateReferralDropdown(excludeId) {
            const sel = $('#custReferredBy');
            sel.find('option:not(:first)').remove();
            referralList.forEach(function(c) {
                if (excludeId && c.id == excludeId) return;
                sel.append(`<option value="${c.id}">${c.name} (${c.referral_code})</option>`);
            });
            setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
        }

        function loadCustomers() {
            $.ajax({
                url: '?action=getCustomers',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        custData = res.data;
                        $('#loadingSkeleton').hide();
                        if (!res.data.length) {
                            $('#tableContainer').hide();
                            $('#emptyState').html(emptyStateHtml({
                                icon: 'fa-user-friends',
                                title: 'No Customers Yet',
                                text: IS_AGENT ? 'Your customers will appear here once policies are created.' : 'Add your first policy holder to get started.',
                                btnText: IS_ADMIN ? 'Add Customer' : '',
                                btnId: IS_ADMIN ? 'emptyAddBtn' : ''
                            })).show();
                            if (IS_ADMIN) $('#emptyAddBtn').off('click').on('click', openAddModal);
                            return;
                        }
                        $('#emptyState').hide().empty();
                        $('#tableContainer').show();
                        initDataTable(res.data);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load customers' });
                    }
                },
                error: function(xhr, status, err) {
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
                }
            });
        }

        function initDataTable(data) {
            if (custTable) return rsTbData(custTable, data); // build once, swap rows

            let cols = [];
            // bulk checkbox col - admin only
            if (IS_ADMIN) {
                cols.push({ data:'id', title:'<input type="checkbox" class="bulk-check-all">', orderable:false, searchable:false, className:'bulk-col', render:function(d){ return '<input type="checkbox" class="bulk-check" data-id="'+d+'">'; } });
            }
            cols = cols.concat([
                { data: 'id', title: 'ID' },
                { data: 'name', title: 'Name' },
                { data: 'mobile', title: 'Mobile' },
                { data: 'email', title: 'Email' },
                { data: 'state', title: 'State' },
                { data: 'agent_name', title: 'Agent', render: function(d) { return d ? escapeHtml(d) : '<em class="text-muted">-</em>'; } },
                { data: 'whatsapp_number', title: 'WhatsApp', visible: false },
                { data: 'date_of_birth', title: 'Date of Birth', visible: false },
                { data: 'ckyc_number', title: 'CKYC No', visible: false },
                { data: 'city', title: 'City', visible: false },
                { data: 'pincode', title: 'Pincode', visible: false },
            ]).concat(IS_AGENT ? [] : [{ data: 'created_by_name', title: 'Added By', visible: false }]).concat([  // staff names hidden from agents
                {
                    data: 'referral_code',
                    title: 'Referral Code',
                    render: function(d) {
                        return d ? `<span class="badge-code">${escapeHtml(d)}</span>` : '-';
                    }
                },
                { data: 'referred_by_name', title: 'Referred By' },
                {
                    data: 'points_balance',
                    title: 'Points',
                    className: 'points-col',
                    render: function(d) {
                        return d.toLocaleString();
                    }
                },
                {
                    data: 'policy_count',
                    title: '# Policies',
                    className: 'points-col',
                    render: function(d) {
                        return d ? '<span class="badge-code">' + d + '</span>' : '0';
                    }
                },
                { data: 'last_policy', title: 'Last Policy Date' },
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

            // actions col - action hub: view + policies link, edit/delete in ⋮ menu
            cols.push({
                data: null,
                title: 'Actions',
                orderable: false,
                width: '140', // 3 icons
                render: function(d, type, row) {
                    var html = '<div class="action-hub">';
                    html += '<button class="action-icon view-icon" data-act="view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button>';
                    html += '<button class="action-icon view-icon" data-act="policies" data-id="' + row.id + '" title="Policies"><i class="fas fa-file-contract"></i></button>';
                    var menu = '<button class="more-item" data-act="whatsapp" data-id="' + row.id + '"><i class="fab fa-whatsapp"></i> WhatsApp</button>';
                    menu += '<button class="more-item" data-act="email" data-id="' + row.id + '"><i class="fas fa-envelope"></i> Email</button>';
                    if (IS_ADMIN) {
                        menu = '<button class="more-item" data-act="edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> Edit</button>' + menu;
                        menu += '<div class="more-divider"></div><button class="more-item danger" data-act="delete" data-id="' + row.id + '"><i class="fas fa-trash"></i> Delete</button>';
                    }
                    html += '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button>';
                    html += '<div class="more-dropdown">' + menu + '</div></div>';
                    html += '</div>';
                    return html;
                }
            });

            custTable = rsTable('#customersTable', {
                columns: cols, data: data, title: 'Customers', file: 'customers', sort: ['id', 'desc'],
                rowClass: function(r) { return r.is_active ? '' : 'dt-row-muted'; }, // mute inactive
                info: function() { return [['State', $('#filterState').val().trim() || 'All'], ['Status', $('#filterStatus').val() || 'All']]; }
            });

            // bulk row-select - admin only
            if (IS_ADMIN) {
                bulkSel = initBulkSelect({
                    table: custTable,
                    label: 'customers',
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
                                    loadCustomers();
                                    loadCustomersList();
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

            // bind filters
            $('#filterState, #filterStatus').off('change input').on('change input', applyFilters);
            rsTbSearch(custTable, $('#custSearch').val()); // keep header search

            // one delegated handler for the whole action hub
            $('#customersTable').off('click', '[data-act]').on('click', '[data-act]', function() {
                var act = $(this).data('act'), id = $(this).data('id');
                var rec = custData.find(function(c) { return c.id == id; });
                if (act === 'view') { if (rec) viewRecord(rec); }
                else if (act === 'policies') { window.location.href = 'policy_entries.php?customer_id=' + id; }
                else if (act === 'whatsapp') {
                    if (rec) window.open('https://wa.me/' + String(rec.whatsapp_number || rec.mobile).replace(/\D/g, ''), '_blank');
                }
                else if (act === 'email') {
                    if (rec && rec.email && rec.email !== '-') window.location.href = 'mailto:' + rec.email;
                    else Swal.fire({ icon: 'info', title: 'No Email', text: 'This customer has no email on file.' });
                }
                else if (act === 'edit' && IS_ADMIN) { if (rec) editCustomer(rec); }
                else if (act === 'delete' && IS_ADMIN) { deleteCustomer(id); }
            });
            applyFilters();
        }

        function applyFilters() {
            const state = $('#filterState').val().trim().toLowerCase(), status = $('#filterStatus').val();
            rsTbWhere(custTable, 'state', state ? function(r) { return String(r.state || '').toLowerCase().indexOf(state) > -1; } : null);
            rsTbWhere(custTable, 'status', status ? function(r) { return !!Number(r.is_active) === (status === 'Active'); } : null);
        }

        function clearFilters() {
            $('#filterState').val('');
            $('#filterStatus').val('');
            $('#custSearch').val('');
            var sd = document.getElementById('filterStatus'); if (sd && sd.sdRefresh) sd.sdRefresh();
            rsTbClear(custTable);
        }

        // tabbed view modal - profile / policies / vehicles / points
        function viewRecord(d) {
            var statusCls = d.is_active ? 'status-active' : 'status-inactive';

            // profile pane
            var profile = '<table class="view-detail-table">';
            profile += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">' + d.id + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-user"></i> Name</td><td class="vd-value">' + (escapeHtml(d.name) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-phone"></i> Mobile</td><td class="vd-value">' + (escapeHtml(d.mobile) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-envelope"></i> Email</td><td class="vd-value">' + (escapeHtml(d.email) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-map-marker-alt"></i> State</td><td class="vd-value">' + (escapeHtml(d.state) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-link"></i> Referral Code</td><td class="vd-value"><span class="badge-code">' + (escapeHtml(d.referral_code) || '-') + '</span></td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-home"></i> Address</td><td class="vd-value">' + (escapeHtml(d.address) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-user-friends"></i> Referred By</td><td class="vd-value">' + (escapeHtml(d.referred_by_name) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fab fa-whatsapp"></i> WhatsApp</td><td class="vd-value">' + (escapeHtml(d.whatsapp_number) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-birthday-cake"></i> Date of Birth</td><td class="vd-value">' + (escapeHtml(d.date_of_birth) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-city"></i> City</td><td class="vd-value">' + (escapeHtml(d.city) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-map-pin"></i> Pincode</td><td class="vd-value">' + (escapeHtml(d.pincode) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-id-card"></i> CKYC No</td><td class="vd-value">' + (escapeHtml(d.ckyc_number) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-user-tie"></i> Agent</td><td class="vd-value">' + (escapeHtml(d.agent_name) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-user-plus"></i> Added By</td><td class="vd-value" colspan="3">' + (escapeHtml(d.created_by_name) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-toggle-on"></i> Status</td><td class="vd-value"><span class="status-badge ' + statusCls + '">' + (d.is_active ? 'Active' : 'Inactive') + '</span></td>';
            profile += '<td class="vd-label"><i class="fas fa-calendar"></i> Joined</td><td class="vd-value">' + (escapeHtml(d.created_at) || '-') + '</td></tr>';
            profile += '</table>';

            var html = '<div class="tab-nav">';
            html += '<button class="tab-btn active" data-tab="profile"><i class="fas fa-id-card"></i> Profile</button>';
            html += '<button class="tab-btn" data-tab="policies"><i class="fas fa-file-contract"></i> Policies</button>';
            html += '<button class="tab-btn" data-tab="vehicles"><i class="fas fa-car"></i> Vehicles</button>';
            html += '<button class="tab-btn" data-tab="points"><i class="fas fa-star"></i> Points/Referral</button>';
            html += '</div>';
            html += '<div class="tab-content active" data-tab="profile">' + profile + '</div>';
            html += '<div class="tab-content" data-tab="policies"><div class="empty-state-text"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>';
            html += '<div class="tab-content" data-tab="vehicles"><div class="empty-state-text"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>';
            html += '<div class="tab-content" data-tab="points"><div class="empty-state-text"><i class="fas fa-spinner fa-spin"></i> Loading...</div></div>';

            document.getElementById('viewModalTitle').innerHTML = '<i class="fas fa-eye"></i> Customer Details';
            document.getElementById('viewModalBody').innerHTML = html;
            document.getElementById('viewModal').classList.add('active');
            initModalTabs('#viewModalBody');

            loadCustomerPolicies(d.id);
            loadCustomerVehicles(d.id);
            loadCustomerPoints(d.id);
        }

        // policy / points status pill
        function pill(d, t, on) {
            return t === 'display' ? '<span class="status-badge status-' + (on ? 'active' : 'inactive') + '">' + escapeHtml(d) + '</span>' : String(d || '');
        }
        var codeCell = function(d, t) { return t === 'display' ? '<span class="badge-code">' + escapeHtml(d) + '</span>' : String(d || ''); };
        var tabPane = function(tab) { return $('#viewModalBody .tab-content[data-tab="' + tab + '"]'); };

        function loadCustomerPolicies(cid) {
            $.getJSON('?action=getCustomerPolicies&id=' + cid, function(res) {
                var pane = tabPane('policies');
                if (!res.success) { pane.html('<div class="empty-state-text">Failed to load.</div>'); return; }
                pane.html('<div class="tab-table"></div>');
                rsTabTable(pane.children()[0], {
                    data: res.data, title: 'Customer Policies', file: 'customer_policies', sort: ['entry_iso', 'desc'], empty: 'No policies yet',
                    columns: [
                        { data: 'entry_iso', title: 'Date', render: function(d, t, r) { return escapeHtml(r.entry_date); } },
                        { data: 'policy_number', title: 'Policy #', render: codeCell },
                        { data: 'company_name', title: 'Company', defaultContent: '-' },
                        { data: 'premium_amt', title: 'Premium', render: function(d, t, r) { return t === 'export' ? d : escapeHtml(r.premium); } },
                        { data: 'status', title: 'Status', render: function(d, t) { return pill(d, t, d === 'active'); } },
                        { data: 'expiry_iso', title: 'Expiry', render: function(d, t, r) { return escapeHtml(r.expiry); } }
                    ]
                });
            });
        }

        function loadCustomerVehicles(cid) {
            $.getJSON('?action=getCustomerVehicles&id=' + cid, function(res) {
                var pane = tabPane('vehicles');
                if (!res.success) { pane.html('<div class="empty-state-text">Failed to load.</div>'); return; }
                pane.html('<div class="tab-table"></div>');
                rsTabTable(pane.children()[0], {
                    data: res.data, title: 'Customer Vehicles', file: 'customer_vehicles', empty: 'No vehicles yet',
                    columns: [
                        { data: 'vehicle_number', title: 'Vehicle #', render: codeCell },
                        { data: 'vehicle_type', title: 'Category' },
                        { data: 'make', title: 'Make' },
                        { data: 'model', title: 'Model' },
                        { data: 'year', title: 'Year' },
                        { data: 'fuel_type', title: 'Fuel' }
                    ]
                });
            });
        }

        function loadCustomerPoints(cid) {
            $.getJSON('?action=getCustomerPoints&id=' + cid, function(res) {
                var pane = tabPane('points');
                if (!res.success) { pane.html('<div class="empty-state-text">Failed to load.</div>'); return; }
                var refs = res.referrals || [];
                pane.html('<div class="doc-panel">' +
                    '<div class="doc-head"><span><i class="fas fa-star"></i> Points Ledger</span><em>' + (res.data.length || '') + '</em></div><div class="tab-table"></div>' +
                    '<div class="doc-head"><span><i class="fas fa-user-friends"></i> Referred Customers</span><em>' + (refs.length || '') + '</em></div><div class="tab-table"></div></div>');
                var els = pane.find('.tab-table');
                rsTabTable(els[0], {
                    data: res.data, title: 'Points Ledger', file: 'customer_points', sort: ['created_iso', 'desc'], empty: 'No points activity yet',
                    columns: [
                        { data: 'created_iso', title: 'Date', render: function(d, t, r) { return escapeHtml(r.date); } },
                        { data: 'type', title: 'Type', render: function(d, t) { return pill(d, t, d === 'earned'); } },
                        { data: 'points', title: 'Points' },
                        { data: 'value_amt', title: 'Value', render: function(d, t, r) { return t === 'export' ? d : escapeHtml(r.rupee_value); } },
                        { data: 'description', title: 'Description', defaultContent: '-' },
                        { data: 'balance_after', title: 'Balance' }
                    ]
                });
                rsTabTable(els[1], {
                    data: refs, title: 'Referred Customers', file: 'customer_referrals', empty: 'No referred customers yet',
                    columns: [
                        { data: 'name', title: 'Name' },
                        { data: 'mobile', title: 'Mobile', defaultContent: '-' },
                        { data: 'referral_code', title: 'Referral Code', render: codeCell }
                    ]
                });
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
            $('#modalTitle').html('<i class="fas fa-user-plus"></i> Add Customer');
            $('#customerForm')[0].reset();
            $('#customerId').val('');
            $('#custIsActive').prop('checked', true);
            $('#statusToggleGroup').hide();
            populateReferralDropdown();
            populateAgentDropdown();
            $('#customerModal').addClass('active');
        }

        function editCustomer(cust) {
            isEditMode = true;
            $('#modalTitle').html('<i class="fas fa-edit"></i> Edit Customer');
            $('#customerId').val(cust.id);
            $('#custName').val(cust.name);
            $('#custMobile').val(cust.mobile);
            $('#custEmail').val(cust.email === '-' ? '' : cust.email);
            $('#custState').val(cust.state === '-' ? '' : cust.state);
            $('#custAddress').val(cust.address || '');
            populateReferralDropdown(cust.id);
            $('#custReferredBy').val(cust.referred_by || '');
            populateAgentDropdown(cust);
            $('#custAgent').val(cust.agent_id || '');
            $('#custWhatsapp').val(cust.whatsapp_number || '');
            $('#custDob').val(cust.date_of_birth || '');
            $('#custCkyc').val(cust.ckyc_number || '');
            $('#custCity').val(cust.city || '');
            $('#custPincode').val(cust.pincode || '');
            $('#custIsActive').prop('checked', !!cust.is_active);
            $('#statusToggleGroup').show();
            $('#customerModal').addClass('active');
            // refresh searchable display
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
        }

        function closeModal() {
            $('#customerModal').removeClass('active');
            $('#customerForm')[0].reset();
        }

        // close on overlay click
        document.getElementById('customerModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // form submit
        $('#customerForm').on('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const action = isEditMode ? 'updateCustomer' : 'addCustomer';

            // checkbox handling
            if (!$('#custIsActive').is(':checked')) {
                formData.delete('is_active');
            }
            formData.append('csrf_token', window.CSRF_TOKEN);

            showLoading('Saving customer...');

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
                        setTimeout(function() { loadCustomers(); loadCustomersList(); }, 100);
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

        function deleteCustomer(cid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Customer?',
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

                showLoading('Deleting customer...');
                $.ajax({
                    url: '?action=deleteCustomer',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        hideLoading();
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                            setTimeout(function() { loadCustomers(); loadCustomersList(); }, 100);
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
        var customersImport = RSImport({
            key: 'customers', title: 'Bulk Add / Import Customers', url: '?action=bulkAddCustomers',
            hint: 'Add many customers at once — upload an Excel/CSV file or type the rows. <strong>Name</strong> and <strong>mobile</strong> are required; a referral code is generated automatically. <strong>Referred By</strong> accepts an existing customer\'s referral code (<code>REF-0001</code>) or mobile number. Rows whose mobile already exists are skipped, so re-uploading the same sheet will not duplicate customers.',
            onDone: function() { setTimeout(loadCustomers, 100); },
            fields: [
                { key: 'name', label: 'Name *', cls: 'bg-name', req: true, max: 150, ph: 'Full name', aliases: ['name', 'customername', 'fullname', 'customer'], demo: 'Demo Customer One', demo2: 'Demo Customer Two' },
                { key: 'mobile', label: 'Mobile *', cls: 'bg-mobile', req: true, max: 15, digits: true, ph: '10-15 digits', aliases: ['mobile', 'mobileno', 'mobilenumber', 'phone', 'phoneno', 'contact', 'contactno'], demo: '9876543210', demo2: '9876543211' },
                { key: 'email', label: 'Email', cls: 'bg-email', ph: 'optional', aliases: ['email', 'emailaddress', 'emailid', 'mail'], demo: 'demo@example.com', demo2: '' },
                { key: 'state', label: 'State', cls: 'bg-state', max: 100, ph: 'optional', aliases: ['state', 'province', 'region'], demo: 'Maharashtra', demo2: 'Gujarat' },
                { key: 'address', label: 'Address', cls: 'bg-addr', ph: 'optional', aliases: ['address', 'addr', 'location'], demo: 'Demo Street, Mumbai', demo2: '' },
                { key: 'city', label: 'City', cls: 'bg-city', max: 100, ph: 'optional', aliases: ['city', 'town'], demo: 'Mumbai', demo2: '' },
                { key: 'pincode', label: 'Pincode', cls: 'bg-pin', max: 6, digits: true, ph: '6 digits', aliases: ['pincode', 'pin', 'zip', 'zipcode', 'postalcode'], demo: '400001', demo2: '' },
                { key: 'whatsapp_number', label: 'WhatsApp', cls: 'bg-wa', max: 15, digits: true, ph: 'optional', aliases: ['whatsappnumber', 'whatsapp', 'whatsappno', 'wa'], demo: '9876543210', demo2: '' },
                { key: 'date_of_birth', label: 'Date of Birth', cls: 'bg-dob', type: 'date', aliases: ['dateofbirth', 'dob', 'birthdate', 'birthday'], demo: '1990-05-15', demo2: '' },
                { key: 'ckyc_number', label: 'CKYC No', cls: 'bg-ckyc', max: 14, digits: true, ph: '14 digits', aliases: ['ckycnumber', 'ckyc', 'ckycno', 'kycno'], demo: '12345678901234', demo2: '' },
                { key: 'agent_id', label: 'Agent', cls: 'bg-agent', type: 'select',
                  opts: function() { return [{ v: '', t: '— No agent —' }].concat((agentList || []).map(function(a) { return { v: String(a.id), t: a.name + ' (' + a.agent_code + ')' }; })); },
                  aliases: ['agent', 'agentname', 'agentcode', 'agentid'], demo: '', demo2: '' },
                { key: 'referred_by', label: 'Referred By', cls: 'bg-ref', ph: 'REF-0001 or mobile', aliases: ['referredby', 'referrer', 'referralcode', 'refcode', 'referredbycode'], demo: '', demo2: 'REF-0001' }
            ]
        });
        <?php endif; ?>
    </script>
</body>
</html>
