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
$current_page = 'agents';

$isAdmin = ($role === 'admin');

// licence/tds/joining/upi -> [vals] or error msg
function agentExtras(array $src) {
    $okDate = function ($d) { $x = DateTime::createFromFormat('Y-m-d', $d); return $x && $x->format('Y-m-d') === $d; };
    $lic = strtoupper(trim($src['license_number'] ?? ''));
    $exp = trim($src['license_expiry_date'] ?? '');
    $tds = trim((string)($src['tds_rate'] ?? ''));
    $join = trim($src['joining_date'] ?? '');
    $upi = trim($src['upi_id'] ?? '');
    if ($lic !== '' && !preg_match('#^[A-Z0-9/-]{3,30}$#', $lic)) return 'Licence number: 3-30 letters, digits, / or -';
    if ($exp !== '' && !$okDate($exp)) return 'Invalid licence expiry date';
    if ($tds === '') $tds = '5';
    if (!is_numeric($tds) || $tds < 0 || $tds > 30) return 'TDS rate must be 0-30 %';
    if ($join !== '' && (!$okDate($join) || $join > date('Y-m-d'))) return 'Invalid joining date (no future dates)';
    if ($upi !== '' && (strlen($upi) > 60 || !preg_match('/^[A-Za-z0-9._-]+@[A-Za-z][A-Za-z0-9]+$/', $upi))) return 'UPI ID must look like name@bank';
    return [$lic ?: null, $exp ?: null, round((float)$tds, 2), $join ?: null, $upi ?: null];
}

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getBranches':
                $stmt = $conn->prepare("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name");
                $stmt->execute();
                $res = $stmt->get_result();
                $branches = [];
                while ($row = $res->fetch_assoc()) $branches[] = $row;
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $branches]);
                exit();

            case 'getAgents':
                if ($role === 'agent') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }
                // policies-sold count per agent in one shot
                $stmt = $conn->prepare("
                    SELECT a.*, b.name as branch_name,
                        (SELECT COUNT(*) FROM policy_entries pe WHERE pe.agent_id = a.id) as policies_sold
                    FROM agents a
                    LEFT JOIN branches b ON a.branch_id = b.id
                    ORDER BY a.id DESC
                ");
                $stmt->execute();
                $res = $stmt->get_result();
                $agents = [];
                while ($row = $res->fetch_assoc()) {
                    $agents[] = [
                        'id' => (int)$row['id'],
                        'user_id' => $row['user_id'] ? (int)$row['user_id'] : null,
                        'branch_id' => $row['branch_id'] ? (int)$row['branch_id'] : null,
                        'agent_code' => $row['agent_code'],
                        'name' => $row['name'],
                        'mobile' => $row['mobile'],
                        'email' => $row['email'],
                        'address' => $row['address'],
                        'state' => $row['state'],
                        'pan_number' => $row['pan_number'],
                        'aadhar_number' => $row['aadhar_number'],
                        'bank_name' => $row['bank_name'],
                        'bank_account_no' => $row['bank_account_no'],
                        'ifsc_code' => $row['ifsc_code'],
                        'license_number' => $row['license_number'],
                        'license_expiry_date' => $row['license_expiry_date'],
                        'tds_rate' => (float)$row['tds_rate'],
                        'joining_date' => $row['joining_date'],
                        'upi_id' => $row['upi_id'],
                        'wallet_balance' => $row['wallet_balance'],
                        'outstanding_balance' => $row['outstanding_balance'],
                        'is_active' => (int)$row['is_active'],
                        'branch_name' => $row['branch_name'] ?? '-',
                        'policies_sold' => (int)$row['policies_sold']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $agents]);
                exit();

            case 'getAgentLedger':
                if ($role === 'agent') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }
                $aid = (int)($_GET['agent_id'] ?? 0);
                if ($aid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid agent']); exit(); }
                $stmt = $conn->prepare("SELECT id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, reference_number FROM agent_transactions WHERE agent_id = ? ORDER BY transaction_date ASC, id ASC");
                $stmt->bind_param("i", $aid);
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

            case 'getAgentPolicies':
                if ($role === 'agent') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }
                $aid = (int)($_GET['agent_id'] ?? 0);
                if ($aid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid agent']); exit(); }
                $stmt = $conn->prepare("SELECT id, entry_date, policy_number, customer_name, vehicle_number, company_name, total_premium_amount, agent_commission_amount, status FROM policy_entries WHERE agent_id = ? ORDER BY entry_date DESC, id DESC");
                $stmt->bind_param("i", $aid);
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
                        'company_name' => $r['company_name'],
                        'total_premium_amount' => (float)$r['total_premium_amount'],
                        'agent_commission_amount' => (float)$r['agent_commission_amount'],
                        'status' => $r['status']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();

            case 'getAgentCharts':
                if ($role === 'agent') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }
                $aid = (int)($_GET['agent_id'] ?? 0);
                if ($aid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid agent']); exit(); }
                $stmt = $conn->prepare("SELECT apc.id, apc.vehicle_type, apc.month, apc.year, apc.commission_basis, apc.commission_rate, apc.is_custom, apc.is_active, b.company_name FROM agent_payout_charts apc LEFT JOIN buyers b ON apc.buyer_id = b.id WHERE apc.agent_id = ? ORDER BY apc.year DESC, apc.month DESC, apc.id DESC");
                $stmt->bind_param("i", $aid);
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
                        'is_custom' => (int)$r['is_custom'],
                        'is_active' => (int)$r['is_active'],
                        'company_name' => $r['company_name']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();

            case 'getMyProfile':
                if ($role !== 'agent') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }
                $stmt = $conn->prepare("
                    SELECT a.*, b.name as branch_name
                    FROM agents a
                    LEFT JOIN branches b ON a.branch_id = b.id
                    WHERE a.user_id = ?
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $res = $stmt->get_result();
                $agent = $res->fetch_assoc();
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
                    'mobile' => $agent['mobile'],
                    'email' => $agent['email'],
                    'address' => $agent['address'],
                    'state' => $agent['state'],
                    'pan_number' => $agent['pan_number'],
                    'aadhar_number' => $agent['aadhar_number'],
                    'bank_name' => $agent['bank_name'],
                    'bank_account_no' => $agent['bank_account_no'],
                    'ifsc_code' => $agent['ifsc_code'],
                    'license_number' => $agent['license_number'],
                    'license_expiry_date' => $agent['license_expiry_date'],
                    'tds_rate' => (float)$agent['tds_rate'],
                    'joining_date' => $agent['joining_date'],
                    'upi_id' => $agent['upi_id'],
                    'wallet_balance' => $agent['wallet_balance'],
                    'outstanding_balance' => $agent['outstanding_balance'],
                    'is_active' => (int)$agent['is_active'],
                    'branch_name' => $agent['branch_name'] ?? '-'
                ]]);
                exit();

            case 'addAgent':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $agentCode = trim($_POST['agent_code'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $mobile = trim($_POST['mobile'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $password = $_POST['password'] ?? '';
                $branchId = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
                $address = trim($_POST['address'] ?? '');
                $state = trim($_POST['state'] ?? '');
                $panNumber = trim($_POST['pan_number'] ?? '');
                $aadharNumber = trim($_POST['aadhar_number'] ?? '');
                $bankName = trim($_POST['bank_name'] ?? '');
                $bankAccountNo = trim($_POST['bank_account_no'] ?? '');
                $ifscCode = trim($_POST['ifsc_code'] ?? '');

                // validate
                if (strlen($name) < 2 || strlen($name) > 150) {
                    echo json_encode(['success' => false, 'message' => 'Name must be 2-150 characters']);
                    exit();
                }
                if (!preg_match('/^\d{10,15}$/', $mobile)) {
                    echo json_encode(['success' => false, 'message' => 'Mobile must be 10-15 digits']);
                    exit();
                }
                if (empty($agentCode)) {
                    echo json_encode(['success' => false, 'message' => 'Agent code is required']);
                    exit();
                }
                // agent owns a login account — email + pwd (auto-generated if blank)
                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Valid email is required for agent login']);
                    exit();
                }
                if (empty($email)) {
                    $email = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $agentCode)) . '@agent.local';
                }
                if (empty($password)) {
                    $password = 'Agt@' . rand(1000, 9999);
                } elseif (strlen($password) < 6) {
                    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
                    exit();
                }
                $ex = agentExtras($_POST);
                if (is_string($ex)) { echo json_encode(['success' => false, 'message' => $ex]); exit(); }
                [$licNo, $licExp, $tdsRate, $joinDate, $upiId] = $ex;

                // unique agent_code
                $stmt = $conn->prepare("SELECT id FROM agents WHERE agent_code = ?");
                $stmt->bind_param("s", $agentCode);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Agent code already exists']);
                    exit();
                }
                $stmt->close();

                // unique email/phone vs users
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
                $stmt->bind_param("ss", $email, $mobile);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'A user with this email or mobile already exists']);
                    exit();
                }
                $stmt->close();

                // create login account + agent record together
                $conn->begin_transaction();
                try {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    if ($branchId) {
                        $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, role, branch_id, is_active, created_by, password_changed_at) VALUES (?, ?, ?, ?, 'agent', ?, 1, ?, NOW())");
                        $stmt->bind_param("ssssii", $name, $email, $mobile, $hash, $branchId, $user_id);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, role, branch_id, is_active, created_by, password_changed_at) VALUES (?, ?, ?, ?, 'agent', NULL, 1, ?, NOW())");
                        $stmt->bind_param("ssssi", $name, $email, $mobile, $hash, $user_id);
                    }
                    $stmt->execute();
                    $newUserId = $stmt->insert_id;
                    $stmt->close();

                    if ($branchId) {
                        $stmt = $conn->prepare("INSERT INTO agents (user_id, branch_id, agent_code, name, mobile, email, address, state, pan_number, aadhar_number, bank_name, bank_account_no, ifsc_code, license_number, license_expiry_date, tds_rate, joining_date, upi_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("iisssssssssssssdss", $newUserId, $branchId, $agentCode, $name, $mobile, $email, $address, $state, $panNumber, $aadharNumber, $bankName, $bankAccountNo, $ifscCode, $licNo, $licExp, $tdsRate, $joinDate, $upiId);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO agents (user_id, branch_id, agent_code, name, mobile, email, address, state, pan_number, aadhar_number, bank_name, bank_account_no, ifsc_code, license_number, license_expiry_date, tds_rate, joining_date, upi_id) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("isssssssssssssdss", $newUserId, $agentCode, $name, $mobile, $email, $address, $state, $panNumber, $aadharNumber, $bankName, $bankAccountNo, $ifscCode, $licNo, $licExp, $tdsRate, $joinDate, $upiId);
                    }
                    $stmt->execute();
                    $insertId = $stmt->insert_id;
                    $stmt->close();

                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add agent']);
                    exit();
                }

                logActivity($user_id, $username, 'Agent Created', "Created agent: $name ($agentCode), TDS $tdsRate%" . ($licNo ? ", licence $licNo" . ($licExp ? " exp $licExp" : '') : ''));
                $conn->close();
                echo json_encode([
                    'success' => true,
                    'message' => 'Agent added successfully',
                    'data' => [
                        'id' => $insertId,
                        'agent_code' => $agentCode,
                        'name' => $name,
                        'email' => $email,
                        'mobile' => $mobile,
                        'password' => $password
                    ]
                ]);
                exit();

            case 'bulkAddAgents':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows) || count($rows) === 0) { echo json_encode(['success'=>false,'message'=>'No agents to add']); exit(); }

                // opening-balance ledger date (default today)
                $obDate = trim($_POST['opening_date'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $obDate)) $obDate = date('Y-m-d');

                $off = max(0, (int)($_POST['row_offset'] ?? 0)); // client posts in chunks - keep row numbers global

                $inserted = 0; $errors = [];
                $seenCode = []; $seenEmail = []; $seenPhone = []; // within-batch dedup
                foreach ($rows as $idx => $r) {
                    $line = $off + $idx + 1;
                    $code = trim($r['agent_code'] ?? '');
                    $name = trim($r['name'] ?? '');
                    $mobile = trim($r['mobile'] ?? '');
                    $email = trim($r['email'] ?? '');
                    $pwd = (string)($r['password'] ?? '');
                    $branchId = !empty($r['branch_id']) ? (int)$r['branch_id'] : null;
                    $opening = (float)($r['opening_balance'] ?? 0);

                    // skip a fully-blank row (trailing grid line)
                    if ($code === '' && $name === '' && $mobile === '' && $email === '') continue;

                    if (strlen($name) < 2 || strlen($name) > 150) { $errors[] = "Row $line: name must be 2-150 characters"; continue; }
                    if (!preg_match('/^\d{10,15}$/', $mobile)) { $errors[] = "Row $line: mobile must be 10-15 digits"; continue; }
                    if ($code === '') { $errors[] = "Row $line: agent code is required"; continue; }

                    // email optional -> synth from code; password optional -> random (admin can reset later)
                    if ($email === '') $email = strtolower(preg_replace('/[^a-z0-9]+/i', '', $code)) . '@agent.local';
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "Row $line: invalid email '$email'"; continue; }
                    if ($pwd === '') { $pwd = bin2hex(random_bytes(4)); }
                    elseif (strlen($pwd) < 6) { $errors[] = "Row $line: password must be at least 6 characters"; continue; }

                    $ex = agentExtras($r);
                    if (is_string($ex)) { $errors[] = "Row $line: $ex"; continue; }
                    [$licNo, $licExp, $tdsRate, $joinDate, $upiId] = $ex;

                    $ck = strtolower($code); $ek = strtolower($email); $pk = $mobile;
                    if (isset($seenCode[$ck])) { $errors[] = "Row $line: duplicate agent code '$code' in list"; continue; }
                    if (isset($seenEmail[$ek])) { $errors[] = "Row $line: duplicate email '$email' in list"; continue; }
                    if (isset($seenPhone[$pk])) { $errors[] = "Row $line: duplicate mobile '$mobile' in list"; continue; }

                    // dedup vs DB
                    $stmt = $conn->prepare("SELECT id FROM agents WHERE agent_code = ?");
                    $stmt->bind_param("s", $code); $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) { $stmt->close(); $errors[] = "Row $line: agent code '$code' already exists"; continue; }
                    $stmt->close();
                    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
                    $stmt->bind_param("ss", $email, $mobile); $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) { $stmt->close(); $errors[] = "Row $line: a user with email '$email' or mobile '$mobile' already exists"; continue; }
                    $stmt->close();

                    $conn->begin_transaction();
                    try {
                        $hash = password_hash($pwd, PASSWORD_DEFAULT);
                        if ($branchId) {
                            $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, role, branch_id, is_active, created_by, password_changed_at) VALUES (?, ?, ?, ?, 'agent', ?, 1, ?, NOW())");
                            $stmt->bind_param("ssssii", $name, $email, $mobile, $hash, $branchId, $user_id);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, role, branch_id, is_active, created_by, password_changed_at) VALUES (?, ?, ?, ?, 'agent', NULL, 1, ?, NOW())");
                            $stmt->bind_param("ssssi", $name, $email, $mobile, $hash, $user_id);
                        }
                        $stmt->execute(); $newUserId = $stmt->insert_id; $stmt->close();

                        if ($branchId) {
                            $stmt = $conn->prepare("INSERT INTO agents (user_id, branch_id, agent_code, name, mobile, email, license_number, license_expiry_date, tds_rate, joining_date, upi_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("iissssssdss", $newUserId, $branchId, $code, $name, $mobile, $email, $licNo, $licExp, $tdsRate, $joinDate, $upiId);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO agents (user_id, agent_code, name, mobile, email, license_number, license_expiry_date, tds_rate, joining_date, upi_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("issssssdss", $newUserId, $code, $name, $mobile, $email, $licNo, $licExp, $tdsRate, $joinDate, $upiId);
                        }
                        $stmt->execute(); $newAgentId = (int)$stmt->insert_id; $stmt->close();

                        // opening balance -> adjustment txn (+ = we owe agent = credit, - = agent owes us = debit); recalc fixes running_balance + wallet
                        if (abs($opening) > 0.001) {
                            $cr = $opening > 0 ? $opening : 0.00;
                            $dr = $opening < 0 ? -$opening : 0.00;
                            $desc = 'Opening Balance'; $tt = 'adjustment';
                            $s = $conn->prepare("INSERT INTO agent_transactions (agent_id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, created_by) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
                            $s->bind_param("isssddi", $newAgentId, $obDate, $desc, $tt, $cr, $dr, $user_id);
                            $s->execute(); $s->close();
                            recalcAgentBalances($conn, $newAgentId);
                        }

                        $conn->commit();
                        $seenCode[$ck] = 1; $seenEmail[$ek] = 1; $seenPhone[$pk] = 1;
                        $inserted++;
                    } catch (Exception $ex) {
                        $conn->rollback();
                        $errors[] = "Row $line: failed to add '$code'";
                    }
                }

                logActivity($user_id, $username, 'Bulk Agent Add', "Added $inserted agents, " . count($errors) . " errors");
                $conn->close();
                $msg = "$inserted agent(s) added successfully." . (count($errors) ? " " . count($errors) . " row(s) skipped." : "");
                echo json_encode(['success'=>true, 'message'=>$msg, 'inserted'=>$inserted, 'errors'=>$errors]);
                exit();

            case 'resetAgentPassword':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }
                $aid = (int)($_POST['id'] ?? 0);
                if ($aid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid agent ID']);
                    exit();
                }
                $pwd = trim($_POST['password'] ?? '');
                if (empty($pwd)) {
                    $pwd = 'Agt@' . rand(1000, 9999);
                } elseif (strlen($pwd) < 6) {
                    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
                    exit();
                }

                $st = $conn->prepare("SELECT a.id, a.name, a.mobile, a.email, a.agent_code, a.user_id, u.id as u_id 
                    FROM agents a LEFT JOIN users u ON u.id = a.user_id WHERE a.id = ?");
                $st->bind_param("i", $aid);
                $st->execute();
                $ag = $st->get_result()->fetch_assoc();
                $st->close();

                if (!$ag) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Agent not found']);
                    exit();
                }

                $hash = password_hash($pwd, PASSWORD_DEFAULT);
                if (!empty($ag['u_id'])) {
                    $up = $conn->prepare("UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?");
                    $up->bind_param("si", $hash, $ag['u_id']);
                    $up->execute();
                    $up->close();
                } else {
                    $uEmail = !empty($ag['email']) ? $ag['email'] : strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ag['agent_code'])) . '@agent.local';
                    $ins = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, role, is_active, created_by, password_changed_at) VALUES (?, ?, ?, ?, 'agent', 1, ?, NOW())");
                    $ins->bind_param("ssssi", $ag['name'], $uEmail, $ag['mobile'], $hash, $user_id);
                    $ins->execute();
                    $newUid = $ins->insert_id;
                    $ins->close();

                    $link = $conn->prepare("UPDATE agents SET user_id = ? WHERE id = ?");
                    $link->bind_param("ii", $newUid, $aid);
                    $link->execute();
                    $link->close();
                }

                logActivity($user_id, $username, 'Agent Password Reset', "Reset password for agent: {$ag['name']} ({$ag['agent_code']})");
                $conn->close();

                echo json_encode([
                    'success' => true,
                    'message' => 'Password updated successfully',
                    'data' => [
                        'agent_code' => $ag['agent_code'],
                        'name' => $ag['name'],
                        'mobile' => $ag['mobile'],
                        'email' => $ag['email'],
                        'password' => $pwd
                    ]
                ]);
                exit();

            case 'updateAgent':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $aid = (int)($_POST['id'] ?? 0);
                $agentCode = trim($_POST['agent_code'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $mobile = trim($_POST['mobile'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $branchId = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
                $address = trim($_POST['address'] ?? '');
                $state = trim($_POST['state'] ?? '');
                $panNumber = trim($_POST['pan_number'] ?? '');
                $aadharNumber = trim($_POST['aadhar_number'] ?? '');
                $bankName = trim($_POST['bank_name'] ?? '');
                $bankAccountNo = trim($_POST['bank_account_no'] ?? '');
                $ifscCode = trim($_POST['ifsc_code'] ?? '');
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                if ($aid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid agent ID']);
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
                if (empty($agentCode)) {
                    echo json_encode(['success' => false, 'message' => 'Agent code is required']);
                    exit();
                }

                $ex = agentExtras($_POST);
                if (is_string($ex)) { echo json_encode(['success' => false, 'message' => $ex]); exit(); }
                [$licNo, $licExp, $tdsRate, $joinDate, $upiId] = $ex;

                // unique agent_code excluding self
                $stmt = $conn->prepare("SELECT id FROM agents WHERE agent_code = ? AND id != ?");
                $stmt->bind_param("si", $agentCode, $aid);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Agent code already exists']);
                    exit();
                }
                $stmt->close();

                if ($branchId) {
                    $stmt = $conn->prepare("UPDATE agents SET agent_code=?, name=?, mobile=?, email=?, branch_id=?, address=?, state=?, pan_number=?, aadhar_number=?, bank_name=?, bank_account_no=?, ifsc_code=?, license_number=?, license_expiry_date=?, tds_rate=?, joining_date=?, upi_id=?, is_active=? WHERE id=?");
                    $stmt->bind_param("ssssisssssssssdssii", $agentCode, $name, $mobile, $email, $branchId, $address, $state, $panNumber, $aadharNumber, $bankName, $bankAccountNo, $ifscCode, $licNo, $licExp, $tdsRate, $joinDate, $upiId, $isActive, $aid);
                } else {
                    $stmt = $conn->prepare("UPDATE agents SET agent_code=?, name=?, mobile=?, email=?, branch_id=NULL, address=?, state=?, pan_number=?, aadhar_number=?, bank_name=?, bank_account_no=?, ifsc_code=?, license_number=?, license_expiry_date=?, tds_rate=?, joining_date=?, upi_id=?, is_active=? WHERE id=?");
                    $stmt->bind_param("sssssssssssssdssii", $agentCode, $name, $mobile, $email, $address, $state, $panNumber, $aadharNumber, $bankName, $bankAccountNo, $ifscCode, $licNo, $licExp, $tdsRate, $joinDate, $upiId, $isActive, $aid);
                }

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update agent']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Agent Updated', "Updated agent #$aid: $name ($agentCode), TDS $tdsRate%" . ($licNo ? ", licence $licNo" . ($licExp ? " exp $licExp" : '') : '') . ($upiId ? ", UPI $upiId" : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Agent updated successfully']);
                exit();

            case 'deleteAgent':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $aid = (int)($_POST['id'] ?? 0);
                if ($aid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid agent ID']);
                    exit();
                }

                // get name + linked user before delete
                $stmt = $conn->prepare("SELECT name, agent_code, user_id FROM agents WHERE id = ?");
                $stmt->bind_param("i", $aid);
                $stmt->execute();
                $delRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$delRow) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Agent not found']);
                    exit();
                }
                $delName = $delRow['name'];
                $delCode = $delRow['agent_code'];
                $delUserId = $delRow['user_id'] ? (int)$delRow['user_id'] : null;

                // del agent + deactivate linked login account, atomically
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("DELETE FROM agents WHERE id = ?");
                    $stmt->bind_param("i", $aid);
                    $stmt->execute();
                    $stmt->close();

                    // keep linked user row (may own records) but deactivate so it can't log in
                    if ($delUserId) {
                        $stmt = $conn->prepare("UPDATE users SET is_active = 0 WHERE id = ? AND role = 'agent'");
                        $stmt->bind_param("i", $delUserId);
                        $stmt->execute();
                        $stmt->close();
                    }
                    $conn->commit();
                } catch (Exception $ex) {
                    $conn->rollback();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete agent']);
                    exit();
                }

                logActivity($user_id, $username, 'Agent Deleted', "Deleted agent: $delName ($delCode) #$aid");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Agent deleted successfully']);
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
                if (!is_array($ids) || !count($ids)) {
                    echo json_encode(['success' => false, 'message' => 'No agents selected']);
                    exit();
                }

                $deleted = 0;
                foreach ($ids as $rawId) {
                    $aid = (int)$rawId;
                    if ($aid <= 0) continue;

                    // grab linked user
                    $stmt = $conn->prepare("SELECT user_id FROM agents WHERE id = ?");
                    $stmt->bind_param("i", $aid);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$row) continue;
                    $linkUid = $row['user_id'] ? (int)$row['user_id'] : null;

                    $conn->begin_transaction();
                    try {
                        $stmt = $conn->prepare("DELETE FROM agents WHERE id = ?");
                        $stmt->bind_param("i", $aid);
                        $stmt->execute();
                        $stmt->close();

                        if ($linkUid) {
                            $stmt = $conn->prepare("UPDATE users SET is_active = 0 WHERE id = ? AND role = 'agent'");
                            $stmt->bind_param("i", $linkUid);
                            $stmt->execute();
                            $stmt->close();
                        }
                        $conn->commit();
                        $deleted++;
                    } catch (Exception $ex) {
                        $conn->rollback();
                    }
                }

                logActivity($user_id, $username, 'Bulk delete', "Bulk deleted $deleted agent(s)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => "$deleted record(s) deleted"]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("agents.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
        exit();
    }
}
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
    <title>Agent Management - Insurance Management System</title>

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
            if ($role === 'agent') {
                $pageIcon    = 'fa-id-card';
                $pageTitle   = 'My Agent Profile';
                $crumbs      = [];
                $pageActions = [['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadMyProfile()']];
            } else {
                $pageIcon    = 'fa-user-tie';
                $pageTitle   = 'Agent Management';
                $crumbs      = [['label' => 'Agents', 'icon' => 'fa-user-tie']];
                $pageActions = [];
                if ($isAdmin) {
                    $pageActions[] = ['label' => 'Add Agent', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
                    $pageActions[] = ['label' => 'Bulk Add / Import', 'icon' => 'fa-file-excel', 'onclick' => 'agentsImport.open()'];
                }
                $pageActions[] = ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(agentsTable,'csv')"];
                $pageActions[] = ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(agentsTable,'pdf')"];
                $pageActions[] = ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(agentsTable,'print')"];
                $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadAgents()'];
            }
            include 'page_header.php';
            ?>

            <?php if ($role === 'agent'): ?>
            <!-- Agent: own profile card -->
            <div class="data-section">
                <div class="account-info-card" id="agentProfileCard">
                    <div class="skeleton" style="height: 30px; width: 120px; margin: 0 auto 20px;"></div>
                    <div class="skeleton" style="height: 20px; width: 100%; margin-bottom: 15px;"></div>
                    <div class="skeleton" style="height: 20px; width: 100%; margin-bottom: 15px;"></div>
                    <div class="skeleton" style="height: 20px; width: 100%; margin-bottom: 15px;"></div>
                    <div class="skeleton" style="height: 20px; width: 80%; margin-bottom: 15px;"></div>
                    <div class="skeleton" style="height: 20px; width: 60%;"></div>
                </div>
            </div>

            <?php else: ?>
            <!-- Admin/Manager: DataTable view -->
            <div data-head-filter>
                <div class="hf-field"><label for="agentsSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="agentsSearch" placeholder="Search agents…" autocomplete="off"></div>
                <div class="hf-field"><label for="filterBranch" title="Branch"><i class="fas fa-building"></i></label><select id="filterBranch" class="filter-input searchable"><option value="">All Branches</option></select></div>
                <div class="hf-field"><label for="filterStatus" title="Status"><i class="fas fa-toggle-on"></i></label><select id="filterStatus" class="filter-input searchable"><option value="">All Status</option><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
                <button type="button" class="btn btn-icon" onclick="clearFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>

            <div class="data-section">

                <!-- loading skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <?php for ($i = 0; $i < 9; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.4"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.4"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.9"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.4"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.9"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.9"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.9"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="tableContainer" style="display: none;">
                    <div id="agentsTable"></div>
                </div>
            </div>
            <?php endif; ?>
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
    <!-- Agent Modal -->
    <div class="modal-overlay" id="agentModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Add Agent</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="agentForm">
                    <input type="hidden" id="agentId" name="id">

                    <div class="form-section-title">Basic Info</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-barcode"></i> Agent Code *</label>
                            <input type="text" id="agentCode" name="agent_code" required maxlength="30" placeholder="e.g. AGT-0001">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Name *</label>
                            <input type="text" id="agentName" name="name" required minlength="2" maxlength="150" placeholder="Full name">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Mobile *</label>
                            <input type="tel" id="agentMobile" name="mobile" required minlength="10" maxlength="15" placeholder="10-15 digits" pattern="\d{10,15}">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" id="agentEmail" name="email" placeholder="email@example.com (optional)">
                        </div>
                        <div class="form-group" id="agentPwdGroup">
                            <label><i class="fas fa-lock"></i> Login Password</label>
                            <div style="display:flex;gap:6px;">
                                <input type="text" id="agentPassword" name="password" minlength="6" placeholder="Leave blank to auto-generate" style="flex:1;">
                                <button type="button" class="btn btn-secondary" onclick="generateRandomPwd()" style="white-space:nowrap;" title="Generate Random Password"><i class="fas fa-key"></i> Generate</button>
                            </div>
                            <small class="text-muted" style="font-size:11px;">Auto-generated if left blank. Can be shared on WhatsApp.</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Branch</label>
                            <select id="agentBranch" name="branch_id" class="searchable">
                                <option value="">No Branch</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-section-title">Address</div>
                    <div class="form-grid">
                        <div class="form-group form-full-width">
                            <label><i class="fas fa-map-marker-alt"></i> Address</label>
                            <textarea id="agentAddress" name="address" rows="2" placeholder="Full address"></textarea>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-map"></i> State</label>
                            <input type="text" id="agentState" name="state" maxlength="100" placeholder="State">
                        </div>
                    </div>

                    <div class="form-section-title">Identity</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-id-card"></i> PAN Number</label>
                            <input type="text" id="agentPan" name="pan_number" maxlength="10" placeholder="ABCDE1234F" class="text-uppercase">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-fingerprint"></i> Aadhar Number</label>
                            <input type="text" id="agentAadhar" name="aadhar_number" maxlength="12" placeholder="12-digit number">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-certificate"></i> Licence Number</label>
                            <input type="text" id="agentLicNo" name="license_number" maxlength="30" placeholder="IRDAI licence no." class="text-uppercase">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-times"></i> Licence Expiry</label>
                            <input type="date" id="agentLicExp" name="license_expiry_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-percent"></i> TDS Rate (%)</label>
                            <input type="number" id="agentTds" name="tds_rate" step="0.01" min="0" max="30" value="5.00" placeholder="5.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check"></i> Joining Date</label>
                            <input type="date" id="agentJoinDate" name="joining_date">
                        </div>
                    </div>

                    <div class="form-section-title">Banking Details</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-university"></i> Bank Name</label>
                            <input type="text" id="agentBankName" name="bank_name" maxlength="100" placeholder="Bank name">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-credit-card"></i> Account Number</label>
                            <input type="text" id="agentBankAccNo" name="bank_account_no" maxlength="20" placeholder="Account number">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-code-branch"></i> IFSC Code</label>
                            <input type="text" id="agentIfsc" name="ifsc_code" maxlength="11" placeholder="IFSC code" class="text-uppercase">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-qrcode"></i> UPI ID</label>
                            <input type="text" id="agentUpi" name="upi_id" maxlength="60" pattern="[A-Za-z0-9._\-]+@[A-Za-z][A-Za-z0-9]+" placeholder="name@bank">
                        </div>
                    </div>

                    <div class="form-group" id="statusGroup" style="display: none;">
                        <div class="toggle-switch">
                            <input type="checkbox" class="toggle" id="agentIsActive" name="is_active" checked>
                            <label for="agentIsActive">Active</label>
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
        const USER_ROLE = '<?php echo $role; ?>';
        let agentsTable = null;
        let agentsData = [];
        let branchesData = [];
        let isEditMode = false;
        let bulkSel = null;

        $(document).ready(function() {
            if (USER_ROLE === 'agent') {
                loadMyProfile();
            } else {
                loadBranches();
                loadAgents();
                // head search -> table
                $('#agentsSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(agentsTable, v); },200); });
            }
        });

        // load branches for filter + modal
        function loadBranches() {
            $.ajax({
                url: '?action=getBranches',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (!res.success) return;
                    branchesData = res.data;

                    // filter dropdown
                    const filterSel = $('#filterBranch');
                    filterSel.find('option:not(:first)').remove();
                    res.data.forEach(function(b) {
                        filterSel.append('<option value="' + b.name + '">' + b.name + '</option>');
                    });

                    // modal dropdown
                    if (IS_ADMIN) {
                        const modalSel = $('#agentBranch');
                        modalSel.find('option:not(:first)').remove();
                        res.data.forEach(function(b) {
                            modalSel.append('<option value="' + b.id + '">' + b.name + '</option>');
                        });
                    }

                    setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
                }
            });
        }

        function loadAgents() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();
            $.ajax({
                url: '?action=getAgents',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        agentsData = res.data;
                        $('#filtersSection').show();
                        $('#loadingSkeleton').hide();
                        $('#tableContainer').show();
                        initDataTable(res.data);
                    } else {
                        $('#loadingSkeleton').hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load agents' });
                    }
                },
                error: function() {
                    $('#loadingSkeleton').hide();
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
                }
            });
        }

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

        // days until licence expiry (null = no date)
        function licDays(d) {
            if (!d) return null;
            var t = new Date(); t.setHours(0, 0, 0, 0);
            return Math.round((new Date(d + 'T00:00:00') - t) / 86400000);
        }
        // date + pill when expired / due in 30 days
        function licPill(d) {
            var n = licDays(d);
            if (n === null) return '<em class="text-muted">-</em>';
            var pill = n < 0 ? ' <span class="status-badge status-inactive">Expired</span>'
                : n <= 30 ? ' <span class="status-badge status-expired">Expires in ' + n + ' day' + (n === 1 ? '' : 's') + '</span>' : '';
            return escapeHtml(d) + pill;
        }

        function initDataTable(data) {
            if (agentsTable) return rsTbData(agentsTable, data); // build once, swap rows

            var cols = [
                { data:'id', title:'<input type="checkbox" class="bulk-check-all">', orderable:false, searchable:false, className:'bulk-col', render:function(d){ return '<input type="checkbox" class="bulk-check" data-id="'+d+'">'; } },
                { data: 'id', title: 'ID' },
                {
                    data: 'agent_code',
                    title: 'Agent Code',
                    render: function(d) {
                        return '<span class="badge-code">' + escapeHtml(d) + '</span>';
                    }
                },
                { data: 'name', title: 'Name' },
                { data: 'mobile', title: 'Mobile' },
                { data: 'branch_name', title: 'Branch' },
                {
                    data: 'license_expiry_date',
                    title: 'Licence Expiry',
                    render: function(d, type) { return type === 'display' ? licPill(d) : (d || ''); }
                },
                { data: 'license_number', title: 'Licence #', visible: false },
                { data: 'tds_rate', title: 'TDS %', visible: false },
                { data: 'joining_date', title: 'Joining Date', visible: false },
                { data: 'upi_id', title: 'UPI ID', visible: false },
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
                },
                {
                    data: 'policies_sold',
                    title: '# Policies Sold',
                    render: function(d) { return '<span class="badge-code">' + (parseInt(d) || 0) + '</span>'; }
                }
            ];

            // action hub col - last
            cols.push({
                data: null,
                title: 'Actions',
                orderable: false,
                render: function(d, type, row) {
                    var html = '<div class="action-hub">';
                    html += '<button class="action-icon view-icon" data-act="view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button>';
                    html += '<button class="action-icon" data-act="ledger" data-id="' + row.id + '" title="Commission Ledger"><i class="fas fa-book"></i></button>';
                    if (IS_ADMIN) {
                        html += '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button>' +
                                '<div class="more-dropdown">' +
                                '<button class="more-item" data-act="charts" data-id="' + row.id + '"><i class="fas fa-chart-line"></i> Payout Charts</button>' +
                                '<button class="more-item" data-act="whatsapp" data-id="' + row.id + '"><i class="fab fa-whatsapp"></i> WhatsApp</button>' +
                                '<button class="more-item" data-act="reset-pwd" data-id="' + row.id + '"><i class="fas fa-key"></i> Login Credentials / Reset</button>' +
                                '<div class="more-divider"></div>' +
                                '<button class="more-item" data-act="edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> Edit</button>' +
                                '<div class="more-divider"></div>' +
                                '<button class="more-item danger" data-act="delete" data-id="' + row.id + '"><i class="fas fa-trash"></i> Delete</button>' +
                                '</div></div>';
                    } else {
                        html += '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button>' +
                                '<div class="more-dropdown">' +
                                '<button class="more-item" data-act="charts" data-id="' + row.id + '"><i class="fas fa-chart-line"></i> Payout Charts</button>' +
                                '<button class="more-item" data-act="whatsapp" data-id="' + row.id + '"><i class="fab fa-whatsapp"></i> WhatsApp</button>' +
                                '</div></div>';
                    }
                    html += '</div>';
                    return html;
                }
            });

            agentsTable = rsTable('#agentsTable', {
                columns: cols, data: data, title: 'Agents', file: 'agents', sort: ['id', 'desc'],
                // outstanding>0 warn, inactive muted
                rowClass: function(r) { return !r.is_active ? 'dt-row-muted' : (parseFloat(r.outstanding_balance) > 0 ? 'dt-row-warning' : ''); },
                info: function() { return [['Branch', $('#filterBranch').val() || 'All'], ['Status', $('#filterStatus').val() || 'All']]; }
            });
            rsTbSearch(agentsTable, $('#agentsSearch').val());

            bulkSel = initBulkSelect({
                table: agentsTable,
                label: 'agents',
                onDelete: function(ids) {
                    showLoading('Deleting...');
                    $.ajax({
                        url: '?action=bulkDelete', method: 'POST', dataType: 'json',
                        data: { csrf_token: window.CSRF_TOKEN, ids: ids },
                        success: function(res) {
                            hideLoading();
                            if (res.success) { bulkSel.clear(); loadAgents(); Swal.fire('Deleted', res.message, 'success'); }
                            else { Swal.fire('Error', res.message, 'error'); }
                        },
                        error: function() { hideLoading(); Swal.fire('Error', 'Request failed', 'error'); }
                    });
                }
            });

            $('#filterBranch, #filterStatus').off('change').on('change', applyFilters);

            // one delegated handler for the action hub
            $('#agentsTable').off('click', '[data-act]').on('click', '[data-act]', function() {
                var act = $(this).data('act'), id = $(this).data('id');
                var rec = agentsData.find(function(a) { return a.id == id; });
                if (act === 'view') { if (rec) viewRecord(rec); }
                else if (act === 'ledger') { window.location.href = 'agent_transactions.php?agent_id=' + id; }
                else if (act === 'charts') { window.location.href = 'agent_payout_charts.php?agent_id=' + id; }
                else if (act === 'whatsapp') {
                    if (rec && rec.mobile) {
                        var mob = String(rec.mobile).replace(/\D/g, '');
                        window.open('https://wa.me/' + mob, '_blank');
                    } else { Swal.fire('Info', 'No mobile number on file', 'info'); }
                }
                else if (act === 'edit') { if (rec) editAgent(rec); }
                else if (act === 'delete') { deleteAgent(id); }
                else if (act === 'reset-pwd') { resetAgentPasswordPrompt(id); }
            });
            applyFilters();
        }

        function applyFilters() {
            var branch = $('#filterBranch').val(), status = $('#filterStatus').val();
            rsTbWhere(agentsTable, 'branch', branch ? function(r) { return r.branch_name === branch; } : null);
            rsTbWhere(agentsTable, 'status', status ? function(r) { return !!Number(r.is_active) === (status === 'Active'); } : null);
        }

        function clearFilters() {
            $('#filterBranch').val('');
            $('#filterStatus').val('');
            $('#agentsSearch').val('');
            ['filterBranch', 'filterStatus'].forEach(function(id) { var el = document.getElementById(id); if (el && el.sdRefresh) el.sdRefresh(); });
            rsTbClear(agentsTable);
        }

        // agent profile card
        function loadMyProfile() {
            $.ajax({
                url: '?action=getMyProfile',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (!res.success) {
                        $('#agentProfileCard').html('<p style="color: #721c24; padding: 20px;">Agent profile not found. Please contact your administrator.</p>');
                        return;
                    }
                    var a = res.data;
                    var statusBadge = a.is_active
                        ? '<span class="status-badge status-active">Active</span>'
                        : '<span class="status-badge status-inactive">Inactive</span>';

                    var html = '<div class="profile-header-badge"><span class="badge-code">' + escHtml(a.agent_code) + '</span></div>';
                    html += infoRow('fa-user', 'Name', a.name);
                    html += infoRow('fa-phone', 'Mobile', a.mobile);
                    html += infoRow('fa-envelope', 'Email', a.email || '-');
                    html += infoRow('fa-building', 'Branch', a.branch_name);
                    html += infoRow('fa-map', 'State', a.state || '-');
                    html += infoRow('fa-id-card', 'PAN', a.pan_number || '-');
                    html += infoRow('fa-fingerprint', 'Aadhar', a.aadhar_number || '-');
                    html += infoRow('fa-university', 'Bank', a.bank_name ? (a.bank_name + ' - ' + (a.bank_account_no || '')) : '-');
                    html += infoRow('fa-code-branch', 'IFSC', a.ifsc_code || '-');
                    html += infoRow('fa-qrcode', 'UPI ID', a.upi_id || '-');
                    html += infoRow('fa-certificate', 'Licence #', a.license_number || '-');
                    html += '<div class="info-row"><div class="info-label"><i class="fas fa-calendar-times"></i> Licence Expiry:</div><div class="info-value">' + licPill(a.license_expiry_date) + '</div></div>';
                    html += infoRow('fa-percent', 'TDS Rate', parseFloat(a.tds_rate || 0).toFixed(2) + '%');
                    html += infoRow('fa-calendar-check', 'Joining Date', a.joining_date || '-');
                    html += '<div class="info-row"><div class="info-label"><i class="fas fa-wallet"></i> Wallet Balance</div><div class="info-value">' + formatWallet(a.wallet_balance) + '</div></div>';
                    html += '<div class="info-row"><div class="info-label"><i class="fas fa-file-invoice-dollar"></i> Outstanding</div><div class="info-value">' + formatWallet(a.outstanding_balance) + '</div></div>';
                    html += '<div class="info-row"><div class="info-label"><i class="fas fa-toggle-on"></i> Status</div><div class="info-value">' + statusBadge + '</div></div>';

                    $('#agentProfileCard').html(html);
                },
                error: function() {
                    $('#agentProfileCard').html('<p style="color: #721c24; padding: 20px;">Connection error. Please try again.</p>');
                }
            });
        }

        function infoRow(icon, label, value) {
            return '<div class="info-row"><div class="info-label"><i class="fas ' + icon + '"></i> ' + label + ':</div><div class="info-value">' + escHtml(value) + '</div></div>';
        }

        function escHtml(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        // tabbed view modal \u2014 profile / ledger / policies / charts
        function viewRecord(d) {
            var statusCls = d.is_active ? 'status-active' : 'status-inactive';
            var wallet = '\u20B9' + parseFloat(d.wallet_balance || 0).toLocaleString('en-IN', {minimumFractionDigits: 2});
            var outstanding = '\u20B9' + parseFloat(d.outstanding_balance || 0).toLocaleString('en-IN', {minimumFractionDigits: 2});

            var profile = '<table class="view-detail-table">';
            profile += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">' + d.id + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-barcode"></i> Agent Code</td><td class="vd-value"><span class="badge-code">' + (escapeHtml(d.agent_code) || '-') + '</span></td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-user"></i> Name</td><td class="vd-value">' + (escapeHtml(d.name) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-phone"></i> Mobile</td><td class="vd-value">' + (escapeHtml(d.mobile) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-envelope"></i> Email</td><td class="vd-value">' + (escapeHtml(d.email) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-building"></i> Branch</td><td class="vd-value">' + (escapeHtml(d.branch_name) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-map-marker-alt"></i> State</td><td class="vd-value">' + (escapeHtml(d.state) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-id-card"></i> PAN</td><td class="vd-value">' + (escapeHtml(d.pan_number) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-fingerprint"></i> Aadhar</td><td class="vd-value">' + (escapeHtml(d.aadhar_number) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-university"></i> Bank Name</td><td class="vd-value">' + (escapeHtml(d.bank_name) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-credit-card"></i> Account #</td><td class="vd-value">' + (escapeHtml(d.bank_account_no) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-code-branch"></i> IFSC</td><td class="vd-value">' + (escapeHtml(d.ifsc_code) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-certificate"></i> Licence #</td><td class="vd-value">' + (escapeHtml(d.license_number) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-calendar-times"></i> Licence Expiry</td><td class="vd-value">' + licPill(d.license_expiry_date) + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-percent"></i> TDS Rate</td><td class="vd-value">' + parseFloat(d.tds_rate || 0).toFixed(2) + '%</td>';
            profile += '<td class="vd-label"><i class="fas fa-calendar-check"></i> Joining Date</td><td class="vd-value">' + (escapeHtml(d.joining_date) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-qrcode"></i> UPI ID</td><td class="vd-value" colspan="3">' + (escapeHtml(d.upi_id) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-wallet"></i> Wallet</td><td class="vd-value">' + wallet + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-file-invoice-dollar"></i> Outstanding</td><td class="vd-value">' + outstanding + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-toggle-on"></i> Status</td><td class="vd-value"><span class="status-badge ' + statusCls + '">' + (d.is_active ? 'Active' : 'Inactive') + '</span></td>';
            profile += '<td class="vd-label"><i class="fas fa-file-contract"></i> Policies Sold</td><td class="vd-value">' + (d.policies_sold || 0) + '</td></tr>';
            profile += '</table>';

            var html = '<div class="tab-nav">' +
                '<button class="tab-btn active" data-tab="profile"><i class="fas fa-id-card"></i> Profile</button>' +
                '<button class="tab-btn" data-tab="ledger"><i class="fas fa-book"></i> Commission Ledger</button>' +
                '<button class="tab-btn" data-tab="policies"><i class="fas fa-file-contract"></i> Policies Sold</button>' +
                '<button class="tab-btn" data-tab="charts"><i class="fas fa-chart-line"></i> Payout Charts</button>' +
                '</div>' +
                '<div class="tab-content active" data-tab="profile">' + profile + '</div>' +
                '<div class="tab-content" data-tab="ledger" id="vmLedger">' + tabLoading('Loading ledger...') + '</div>' +
                '<div class="tab-content" data-tab="policies" id="vmPolicies">' + tabLoading('Loading policies...') + '</div>' +
                '<div class="tab-content" data-tab="charts" id="vmCharts">' + tabLoading('Loading payout charts...') + '</div>';

            document.getElementById('viewModalTitle').innerHTML = '<i class="fas fa-eye"></i> Agent Details';
            document.getElementById('viewModalBody').innerHTML = html;
            initModalTabs('#viewModalBody');
            document.getElementById('viewModal').classList.add('active');

            loadAgentLedger(d.id);
            loadAgentPolicies(d.id);
            loadAgentCharts(d.id);
        }

        function inrAmt(v) {
            return '\u20B9' + (parseFloat(v) || 0).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        // simple loading placeholder for modal tabs
        function tabLoading(msg) {
            return '<div class="empty-state"><i class="fas fa-spinner fa-spin empty-state-icon"></i>' +
                '<div class="empty-state-text">' + escapeHtml(msg) + '</div></div>';
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

        // commission ledger tab — newest first
        function loadAgentLedger(aid) {
            $.ajax({
                url: '?action=getAgentLedger&agent_id=' + aid, method: 'GET', dataType: 'json',
                success: function(res) {
                    paneTable('#vmLedger', {
                        data: res.success ? res.data.slice().reverse() : [], title: 'Commission Ledger', file: 'agent_ledger',
                        sort: ['transaction_date', 'desc'], empty: 'No commission transactions yet',
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

        // policies sold tab
        function loadAgentPolicies(aid) {
            $.ajax({
                url: '?action=getAgentPolicies&agent_id=' + aid, method: 'GET', dataType: 'json',
                success: function(res) {
                    paneTable('#vmPolicies', {
                        data: res.success ? res.data : [], title: 'Policies Sold', file: 'agent_policies',
                        sort: ['entry_date', 'desc'], empty: 'No policies sold yet',
                        columns: [
                            { data: 'entry_date', title: 'Date' },
                            { data: 'policy_number', title: 'Policy #' },
                            { data: 'customer_name', title: 'Customer', defaultContent: '-' },
                            { data: 'vehicle_number', title: 'Vehicle', defaultContent: '-' },
                            { data: 'company_name', title: 'Buyer', defaultContent: '-' },
                            amtCol('total_premium_amount', 'Premium'),
                            amtCol('agent_commission_amount', 'Commission'),
                            stCol('status')
                        ]
                    });
                },
                error: function() { $('#vmPolicies').html(tabLoading('Failed to load policies.')); }
            });
        }

        // payout charts tab
        function loadAgentCharts(aid) {
            var basisMap = { 'premium_pct':'Premium %', 'tp_pct':'TP %', 'od_pct':'OD %', 'net_pct':'Net %', 'fixed':'Fixed' };
            var mNames = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            $.ajax({
                url: '?action=getAgentCharts&agent_id=' + aid, method: 'GET', dataType: 'json',
                success: function(res) {
                    // ym = sortable month key
                    var rows = (res.success ? res.data : []).map(function(c) { return Object.assign({ ym: c.year * 100 + +c.month }, c); });
                    paneTable('#vmCharts', {
                        data: rows, title: 'Agent Payout Charts', file: 'agent_payout_charts',
                        sort: ['ym', 'desc'], empty: 'No agent-specific payout rates configured',
                        columns: [
                            { data: 'ym', title: 'Month/Year', render: function(d, t, c) { return escapeHtml(mNames[c.month] + ' ' + c.year); } },
                            { data: 'vehicle_type', title: 'Vehicle Type' },
                            { data: 'company_name', title: 'Buyer', defaultContent: 'All Buyers' },
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
        function generateRandomPwd() {
            var p = 'Agt@' + Math.floor(1000 + Math.random() * 9000);
            $('#agentPassword').val(p);
        }

        function showAgentCredentials(data) {
            var loginId = data.email || data.agent_code;
            var pwd = data.password;
            var mob = data.mobile ? String(data.mobile).replace(/\D/g, '') : '';
            var waMsg = encodeURIComponent(
                "Hello " + data.name + ",\nYour Agent account has been created on Insurance Management System.\n\n" +
                "Agent Code: " + data.agent_code + "\n" +
                "Login ID: " + (data.agent_code || loginId) + " (or " + (data.mobile || loginId) + ")\n" +
                "Password: " + pwd + "\n\n" +
                "Please log in and update your password."
            );
            var waBtn = mob ? '<a href="https://wa.me/' + mob + '?text=' + waMsg + '" target="_blank" class="btn btn-success" style="background:#25D366;border-color:#25D366;color:#fff;display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:6px;text-decoration:none;font-weight:600;"><i class="fab fa-whatsapp"></i> Share on WhatsApp</a>' : '';

            var copyAllText = "Agent Code: " + data.agent_code + "\\nAgent Name: " + data.name + "\\nLogin ID: " + loginId + "\\nPassword: " + pwd + (data.mobile ? "\\nMobile: " + data.mobile : "");

            var credHtml = '<div style="text-align:left;background:#f8f9fa;border:1px solid #e2e8f0;border-radius:8px;padding:15px;margin:15px 0;font-size:13px;line-height:1.8;">' +
                '<div><strong>Agent Code:</strong> <span class="badge-code">' + escapeHtml(data.agent_code) + '</span></div>' +
                '<div><strong>Agent Name:</strong> ' + escapeHtml(data.name) + '</div>' +
                '<div><strong>Login Username:</strong> <code style="font-size:14px;color:#0b2239;font-weight:bold;">' + escapeHtml(loginId) + '</code> <button type="button" class="btn btn-sm btn-secondary" onclick="navigator.clipboard.writeText(\'' + escapeHtml(loginId) + '\');" style="padding:1px 6px;font-size:11px;margin-left:5px;"><i class="fas fa-copy"></i> Copy</button></div>' +
                '<div><strong>Password:</strong> <code style="font-size:15px;color:#d9534f;font-weight:bold;">' + escapeHtml(pwd) + '</code> <button type="button" class="btn btn-sm btn-secondary" onclick="navigator.clipboard.writeText(\'' + escapeHtml(pwd) + '\');" style="padding:1px 6px;font-size:11px;margin-left:5px;"><i class="fas fa-copy"></i> Copy</button></div>' +
                (data.mobile ? '<div><strong>Mobile:</strong> ' + escapeHtml(data.mobile) + '</div>' : '') +
                '</div>' +
                '<div style="display:flex;gap:10px;justify-content:center;margin-top:12px;flex-wrap:wrap;">' +
                waBtn +
                '<button type="button" class="btn btn-primary" onclick="navigator.clipboard.writeText(\'' + copyAllText + '\');Swal.fire(\'Copied!\', \'Login credentials copied to clipboard\', \'success\');" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:6px;"><i class="fas fa-copy"></i> Copy All Details</button>' +
                '</div>';

            Swal.fire({
                icon: 'success',
                title: 'Agent Credentials Ready!',
                html: credHtml,
                showConfirmButton: true,
                confirmButtonText: 'Done'
            });
        }

        function resetAgentPasswordPrompt(aid) {
            var rec = agentsData.find(function(a) { return a.id == aid; });
            var name = rec ? rec.name : 'Agent #' + aid;
            var suggestedPwd = 'Agt@' + Math.floor(1000 + Math.random() * 9000);

            Swal.fire({
                title: 'Reset Password / Login',
                html: '<p style="font-size:13px;color:#64748b;">Set or generate a new login password for <strong>' + escapeHtml(name) + '</strong>:</p>' +
                      '<div style="display:flex;gap:6px;justify-content:center;margin-top:10px;">' +
                      '<input type="text" id="swalAgentPwd" class="swal2-input" value="' + suggestedPwd + '" placeholder="Min 6 characters" style="max-width:240px;margin:0;font-family:monospace;font-weight:bold;">' +
                      '<button type="button" class="btn btn-secondary" onclick="$(\'#swalAgentPwd\').val(\'Agt@\' + Math.floor(1000 + Math.random() * 9000));" style="white-space:nowrap;"><i class="fas fa-random"></i></button>' +
                      '</div>',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-save"></i> Save & Show Details',
                cancelButtonText: 'Cancel',
                preConfirm: function() {
                    var p = $('#swalAgentPwd').val().trim();
                    if (p.length < 6) {
                        Swal.showValidationMessage('Password must be at least 6 characters');
                        return false;
                    }
                    return p;
                }
            }).then(function(res) {
                if (!res.isConfirmed || !res.value) return;
                showLoading('Updating password...');
                $.ajax({
                    url: '?action=resetAgentPassword',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        id: aid,
                        password: res.value,
                        csrf_token: window.CSRF_TOKEN
                    },
                    success: function(resp) {
                        hideLoading();
                        if (resp.success && resp.data) {
                            showAgentCredentials(resp.data);
                        } else {
                            Swal.fire('Error', resp.message || 'Failed to update password', 'error');
                        }
                    },
                    error: function() {
                        hideLoading();
                        Swal.fire('Error', 'Connection failed', 'error');
                    }
                });
            });
        }

        function openAddModal() {
            isEditMode = false;
            $('#modalTitle').html('<i class="fas fa-user-plus"></i> Add Agent');
            $('#agentForm')[0].reset();
            $('#agentId').val('');
            $('#agentIsActive').prop('checked', true);
            // add mode — auto-generated if left blank, email optional
            $('#agentPwdGroup').show();
            $('#agentPassword').prop('disabled', false).prop('required', false).val('');
            $('#agentEmail').prop('readonly', false).prop('required', false);
            $('#statusGroup').hide();
            $('#agentModal').addClass('active');
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
        }

        function editAgent(agent) {
            isEditMode = true;
            $('#modalTitle').html('<i class="fas fa-edit"></i> Edit Agent');
            $('#agentId').val(agent.id);
            $('#agentCode').val(agent.agent_code);
            $('#agentName').val(agent.name);
            $('#agentMobile').val(agent.mobile);
            $('#agentEmail').val(agent.email || '');
            $('#agentBranch').val(agent.branch_id || '');
            $('#agentAddress').val(agent.address || '');
            $('#agentState').val(agent.state || '');
            $('#agentPan').val(agent.pan_number || '');
            $('#agentAadhar').val(agent.aadhar_number || '');
            $('#agentBankName').val(agent.bank_name || '');
            $('#agentBankAccNo').val(agent.bank_account_no || '');
            $('#agentIfsc').val(agent.ifsc_code || '');
            $('#agentLicNo').val(agent.license_number || '');
            $('#agentLicExp').val(agent.license_expiry_date || '');
            $('#agentTds').val(agent.tds_rate != null ? parseFloat(agent.tds_rate).toFixed(2) : '5.00');
            $('#agentJoinDate').val(agent.joining_date || '');
            $('#agentUpi').val(agent.upi_id || '');
            $('#agentIsActive').prop('checked', !!agent.is_active);
            // edit mode — login account untouched here, hide pwd field
            $('#agentPwdGroup').hide();
            $('#agentPassword').prop('disabled', true).prop('required', false);
            $('#agentEmail').prop('required', false);
            $('#statusGroup').show();
            $('#agentModal').addClass('active');
            // refresh searchable display
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
        }

        function closeModal() {
            $('#agentModal').removeClass('active');
            $('#agentForm')[0].reset();
        }

        document.getElementById('agentModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // form submit
        $('#agentForm').on('submit', function(e) {
            e.preventDefault();

            var formData = new FormData(this);
            var action = isEditMode ? 'updateAgent' : 'addAgent';

            // checkbox fix
            if (!$('#agentIsActive').is(':checked')) {
                formData.delete('is_active');
            }

            // pwd only relevant on add
            if (isEditMode) {
                formData.delete('password');
            }
            formData.append('csrf_token', window.CSRF_TOKEN);

            showLoading('Saving agent...');

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
                        closeModal();
                        if (!isEditMode && res.data && res.data.password) {
                            showAgentCredentials(res.data);
                        } else {
                            Swal.fire({ icon: 'success', title: 'Success!', text: res.message, timer: 2000, showConfirmButton: false });
                        }
                        setTimeout(loadAgents, 100);
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

        function deleteAgent(aid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Agent?',
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

                showLoading('Deleting agent...');
                $.ajax({
                    url: '?action=deleteAgent',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        hideLoading();
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                            setTimeout(loadAgents, 100);
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
        var agentsImport = RSImport({
            key: 'agents', title: 'Bulk Add / Import Agents', url: '?action=bulkAddAgents',
            hint: 'Add many agents at once with their <strong>opening balance</strong> — upload an Excel/CSV file or type the rows. Email &amp; password are optional (auto-filled if left blank — you can set them later). Opening balance sign: <strong>+</strong> = we owe the agent, <strong>&minus;</strong> = agent owes us.',
            controls: '<div class="bulk-grid-actions"><div class="form-group bulk-date">' +
                '<label><i class="fas fa-calendar"></i> Opening balance as of</label>' +
                '<input type="date" class="rsi-obdate"></div></div>',
            onOpen: function($m) { var d = new Date(); $m.find('.rsi-obdate').val(d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2)); },
            extra: function() { return { opening_date: $('#rsImp_agents .rsi-obdate').val() || '' }; },
            onDone: function() { setTimeout(loadAgents, 100); },
            fields: [
                { key: 'agent_code', label: 'Agent Code *', cls: 'bg-code', req: true, max: 30, ph: 'AGT-001', aliases: ['agentcode', 'code', 'agentid', 'agentno'], demo: 'AGT-001', demo2: 'AGT-002' },
                { key: 'name', label: 'Name *', cls: 'bg-name', req: true, max: 150, ph: 'Full name', aliases: ['name', 'agentname', 'fullname'], demo: 'Demo Agent One', demo2: 'Demo Agent Two' },
                { key: 'mobile', label: 'Mobile *', cls: 'bg-mobile', req: true, max: 15, digits: true, ph: '10-15 digits', aliases: ['mobile', 'mobileno', 'mobilenumber', 'phone', 'phoneno', 'contact', 'contactno', 'whatsapp'], demo: '9876543210', demo2: '9876543211' },
                { key: 'email', label: 'Email', cls: 'bg-email', ph: 'optional', aliases: ['email', 'emailaddress', 'emailid', 'mail'], demo: '', demo2: 'agent2@example.com' },
                { key: 'password', label: 'Password', cls: 'bg-pwd', ph: 'blank = auto', aliases: ['password', 'pwd', 'pass'], demo: '', demo2: '' },
                { key: 'branch_id', label: 'Branch', cls: 'bg-branch', type: 'select',
                  opts: function() { return [{ v: '', t: '— No branch —' }].concat((branchesData || []).map(function(b) { return { v: String(b.id), t: b.name }; })); },
                  aliases: ['branch', 'branchname', 'office', 'location'], demo: '', demo2: '' },
                { key: 'opening_balance', label: 'Opening Bal (₹)', cls: 'bg-open', type: 'number', aliases: ['openingbalance', 'openingbal', 'opening', 'obal', 'balance', 'openingamount'], demo: 25000, demo2: -5000 },
                { key: 'license_number', label: 'Licence #', cls: 'bg-lic', max: 30, ph: 'optional', aliases: ['licensenumber', 'licenceno', 'licencenumber', 'licenseno', 'license', 'licence'], demo: 'LIC-1001', demo2: '' },
                { key: 'license_expiry_date', label: 'Licence Expiry', cls: 'bg-licexp', type: 'date', aliases: ['licenseexpirydate', 'licenseexpiry', 'licenceexpiry', 'licenceexpirydate', 'expiry', 'expirydate'], demo: '2027-03-31', demo2: '' },
                { key: 'tds_rate', label: 'TDS %', cls: 'bg-tds', max: 5, ph: 'blank = 5', aliases: ['tdsrate', 'tds', 'tdspercent'], demo: '5', demo2: '' },
                { key: 'joining_date', label: 'Joining Date', cls: 'bg-join', type: 'date', aliases: ['joiningdate', 'joindate', 'joined', 'dateofjoining', 'doj'], demo: '2025-04-01', demo2: '' },
                { key: 'upi_id', label: 'UPI ID', cls: 'bg-upi', max: 60, ph: 'name@bank', aliases: ['upiid', 'upi', 'vpa'], demo: 'demoagent@okbank', demo2: '' }
            ]
        });
        <?php endif; ?>
    </script>
</body>
</html>
