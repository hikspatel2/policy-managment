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
$current_page = 'users';

// admin only
if ($role !== 'admin') { header("Location: dashboard.php"); exit(); }

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

            case 'getUsers':
                $stmt = $conn->prepare("
                    SELECT u.id, u.name, u.email, u.phone, u.role, u.is_active,
                           COALESCE(b.name, '-') as branch_name, u.branch_id,
                           u.customer_id, COALESCE(c.name, '') as customer_name,
                           a.agent_code,
                           DATE_FORMAT(u.created_at, '%b %d, %Y') as created_at,
                           CASE WHEN u.last_login_at IS NULL THEN '' ELSE DATE_FORMAT(u.last_login_at, '%b %d, %Y %H:%i') END as last_login,
                           u.last_login_ip, cb.name as created_by_name,
                           CASE WHEN u.password_changed_at IS NULL THEN '' ELSE DATE_FORMAT(u.password_changed_at, '%b %d, %Y %H:%i') END as password_changed
                    FROM users u
                    LEFT JOIN users cb ON cb.id = u.created_by
                    LEFT JOIN branches b ON u.branch_id = b.id
                    LEFT JOIN agents a ON u.id = a.user_id
                    LEFT JOIN customers c ON u.customer_id = c.id
                    ORDER BY u.id DESC
                ");
                $stmt->execute();
                $res = $stmt->get_result();
                $users = [];
                while ($row = $res->fetch_assoc()) {
                    $users[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'phone' => $row['phone'],
                        'role' => $row['role'],
                        'customer_id' => $row['customer_id'] ? (int)$row['customer_id'] : null,
                        'customer_name' => $row['customer_name'] ?: '',
                        'is_active' => (int)$row['is_active'],
                        'branch_name' => $row['branch_name'],
                        'branch_id' => $row['branch_id'] ? (int)$row['branch_id'] : null,
                        'agent_code' => $row['agent_code'],
                        'created_at' => $row['created_at'],
                        'last_login' => $row['last_login'],
                        'last_login_ip' => $row['last_login_ip'],
                        'created_by_name' => $row['created_by_name'],
                        'password_changed' => $row['password_changed']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $users]);
                exit();

            case 'getCustomersDropdown':
                $res = $conn->query("SELECT id, name, mobile, email FROM customers WHERE is_active = 1 ORDER BY name ASC");
                $custs = [];
                if ($res) {
                    while ($r = $res->fetch_assoc()) {
                        $custs[] = [
                            'id' => (int)$r['id'],
                            'name' => $r['name'],
                            'mobile' => $r['mobile'],
                            'email' => $r['email'] ?: ''
                        ];
                    }
                }
                $conn->close();
                echo json_encode(['success' => true, 'data' => $custs]);
                exit();

            case 'getUserActivity':
                // recent activity log rows for a user (view modal tab)
                $uid = (int)($_GET['id'] ?? 0);
                if ($uid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid user ID']); exit(); }
                $stmt = $conn->prepare("SELECT action, details, ip_address, timestamp, DATE_FORMAT(timestamp, '%b %d, %Y %H:%i') as ts
                                        FROM activity_logs WHERE user_id = ? ORDER BY timestamp DESC LIMIT 25");
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) $rows[] = $r;
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();

            case 'toggleUserStatus':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

                $uid = (int)($_POST['id'] ?? 0);
                if ($uid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid user ID']); exit(); }
                if ($uid == $user_id) { echo json_encode(['success'=>false,'message'=>'Cannot change your own status']); exit(); }

                $stmt = $conn->prepare("SELECT name, is_active FROM users WHERE id = ?");
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $uRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$uRow) { $conn->close(); echo json_encode(['success'=>false,'message'=>'User not found']); exit(); }

                $newStatus = $uRow['is_active'] ? 0 : 1;
                $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $stmt->bind_param("ii", $newStatus, $uid);
                $stmt->execute();
                $stmt->close();

                logActivity($user_id, $username, 'User Updated', "Toggled status of {$uRow['name']} (#$uid) to " . ($newStatus ? 'Active' : 'Inactive'));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Status updated to ' . ($newStatus ? 'Active' : 'Inactive')]);
                exit();

            case 'addUser':
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
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $password = $_POST['password'] ?? '';
                $newRole = $_POST['role'] ?? 'agent';
                $branchId = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
                $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                // validate
                if (strlen($name) < 2 || strlen($name) > 150) {
                    echo json_encode(['success' => false, 'message' => 'Name must be 2-150 characters']);
                    exit();
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }
                if (!preg_match('/^\d{10,15}$/', $phone)) {
                    echo json_encode(['success' => false, 'message' => 'Phone must be 10-15 digits']);
                    exit();
                }
                if (strlen($password) < 6) {
                    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
                    exit();
                }
                if (!in_array($newRole, ['admin', 'manager', 'agent', 'customer'], true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid role']);
                    exit();
                }

                // check unique email
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Email already exists']);
                    exit();
                }
                $stmt->close();

                // check unique phone
                $stmt = $conn->prepare("SELECT id FROM users WHERE phone = ?");
                $stmt->bind_param("s", $phone);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Phone number already exists']);
                    exit();
                }
                $stmt->close();

                // Customer handling
                if ($newRole === 'customer') {
                    if (!$customerId) {
                        $stC = $conn->prepare("SELECT id FROM customers WHERE email = ? OR mobile = ? LIMIT 1");
                        $stC->bind_param("ss", $email, $phone);
                        $stC->execute();
                        $cRow = $stC->get_result()->fetch_assoc();
                        $stC->close();
                        if ($cRow) {
                            $customerId = (int)$cRow['id'];
                        } else {
                            $stIns = $conn->prepare("INSERT INTO customers (name, mobile, email, created_by) VALUES (?, ?, ?, ?)");
                            $stIns->bind_param("sssi", $name, $phone, $email, $user_id);
                            $stIns->execute();
                            $customerId = $stIns->insert_id;
                            $stIns->close();
                        }
                    }
                } else {
                    $customerId = null;
                }

                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, role, branch_id, customer_id, is_active, created_by, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("sssssiiii", $name, $email, $phone, $hash, $newRole, $branchId, $customerId, $isActive, $user_id);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add user']);
                    exit();
                }
                $insertId = $stmt->insert_id;
                $stmt->close();

                // if customer, update customer's portal_user_id
                if ($newRole === 'customer' && $customerId) {
                    $conn->query("UPDATE customers SET portal_user_id = $insertId WHERE id = $customerId");
                }

                // if agent, create agents record
                if ($newRole === 'agent') {
                    $agentCode = 'AGT-' . str_pad($insertId, 4, '0', STR_PAD_LEFT);
                    if ($branchId) {
                        $stmt = $conn->prepare("INSERT INTO agents (user_id, agent_code, name, mobile, email, branch_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("issssi", $insertId, $agentCode, $name, $phone, $email, $branchId);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO agents (user_id, agent_code, name, mobile, email) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("issss", $insertId, $agentCode, $name, $phone, $email);
                    }
                    $stmt->execute();
                    $stmt->close();
                }

                logActivity($user_id, $username, 'User Created', "Created user: $name ($newRole)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'User added successfully', 'data' => ['id' => $insertId]]);
                exit();

            case 'bulkAddUsers':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows) || count($rows) === 0) { echo json_encode(['success'=>false,'message'=>'No users to add']); exit(); }
                $off = max(0, (int)($_POST['row_offset'] ?? 0)); // client posts in chunks - keep row numbers global

                // branch name -> id, case-insensitive
                $branchMap = [];
                $bres = $conn->query("SELECT id, name FROM branches");
                while ($b = $bres->fetch_assoc()) $branchMap[strtolower(trim($b['name']))] = (int)$b['id'];

                $inserted = 0; $errors = []; $byRole = ['admin'=>0, 'manager'=>0, 'agent'=>0, 'customer'=>0];
                $seenEmail = []; $seenPhone = []; // within-batch dedup
                foreach ($rows as $idx => $r) {
                    $line = $off + $idx + 1;
                    $name = trim($r['name'] ?? '');
                    $email = trim($r['email'] ?? '');
                    $phone = trim($r['phone'] ?? '');
                    $pwd = (string)($r['password'] ?? '');
                    $newRole = strtolower(trim($r['role'] ?? '')) ?: 'agent';
                    $branchName = trim($r['branch'] ?? '');
                    $isActive = (trim((string)($r['is_active'] ?? '1')) === '0') ? 0 : 1;

                    if ($name === '' && $email === '' && $phone === '') continue; // trailing blank line

                    if (strlen($name) < 2 || strlen($name) > 150) { $errors[] = "Row $line: name must be 2-150 characters"; continue; }
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "Row $line: invalid email '$email'"; continue; }
                    if (!preg_match('/^\d{10,15}$/', $phone)) { $errors[] = "Row $line: phone must be 10-15 digits"; continue; }
                    if (!in_array($newRole, ['admin', 'manager', 'agent', 'customer'], true)) { $errors[] = "Row $line: invalid role '$newRole' — use admin, manager, agent or customer"; continue; }
                    if ($pwd === '') { $pwd = bin2hex(random_bytes(4)); } // auto-generated, admin can reset
                    elseif (strlen($pwd) < 6) { $errors[] = "Row $line: password must be at least 6 characters"; continue; }

                    $branchId = null;
                    if ($branchName !== '') {
                        if (!isset($branchMap[strtolower($branchName)])) { $errors[] = "Row $line: branch '$branchName' not found"; continue; }
                        $branchId = $branchMap[strtolower($branchName)];
                    }

                    $ek = strtolower($email);
                    if (isset($seenEmail[$ek])) { $errors[] = "Row $line: duplicate email '$email' in list"; continue; }
                    if (isset($seenPhone[$phone])) { $errors[] = "Row $line: duplicate phone '$phone' in list"; continue; }

                    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR phone = ?");
                    $stmt->bind_param("ss", $email, $phone); $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) { $stmt->close(); $errors[] = "Row $line: a user with email '$email' or phone '$phone' already exists"; continue; }
                    $stmt->close();

                    $conn->begin_transaction();
                    try {
                        $hash = password_hash($pwd, PASSWORD_DEFAULT);
                        if ($branchId) {
                            $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, role, branch_id, is_active, created_by, password_changed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                            $stmt->bind_param("sssssiii", $name, $email, $phone, $hash, $newRole, $branchId, $isActive, $user_id);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, role, branch_id, is_active, created_by, password_changed_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, NOW())");
                            $stmt->bind_param("sssssii", $name, $email, $phone, $hash, $newRole, $isActive, $user_id);
                        }
                        $stmt->execute(); $newUserId = (int)$stmt->insert_id; $stmt->close();

                        // agent role also gets its agents record, same as single add
                        if ($newRole === 'agent') {
                            $agentCode = 'AGT-' . str_pad($newUserId, 4, '0', STR_PAD_LEFT);
                            if ($branchId) {
                                $stmt = $conn->prepare("INSERT INTO agents (user_id, agent_code, name, mobile, email, branch_id) VALUES (?, ?, ?, ?, ?, ?)");
                                $stmt->bind_param("issssi", $newUserId, $agentCode, $name, $phone, $email, $branchId);
                            } else {
                                $stmt = $conn->prepare("INSERT INTO agents (user_id, agent_code, name, mobile, email) VALUES (?, ?, ?, ?, ?)");
                                $stmt->bind_param("issss", $newUserId, $agentCode, $name, $phone, $email);
                            }
                            $stmt->execute(); $stmt->close();
                        } elseif ($newRole === 'customer') {
                            $stC = $conn->prepare("SELECT id FROM customers WHERE email = ? OR mobile = ? LIMIT 1");
                            $stC->bind_param("ss", $email, $phone); $stC->execute();
                            $cRow = $stC->get_result()->fetch_assoc(); $stC->close();
                            $cId = $cRow ? (int)$cRow['id'] : 0;
                            if (!$cId) {
                                $stIns = $conn->prepare("INSERT INTO customers (name, mobile, email, created_by) VALUES (?, ?, ?, ?)");
                                $stIns->bind_param("sssi", $name, $phone, $email, $user_id);
                                $stIns->execute(); $cId = (int)$stIns->insert_id; $stIns->close();
                            }
                            $conn->query("UPDATE users SET customer_id = $cId WHERE id = $newUserId");
                            $conn->query("UPDATE customers SET portal_user_id = $newUserId WHERE id = $cId");
                        }

                        $conn->commit();
                        $seenEmail[$ek] = 1; $seenPhone[$phone] = 1;
                        $byRole[$newRole]++;
                        $inserted++;
                    } catch (Exception $ex) {
                        $conn->rollback();
                        $errors[] = "Row $line: failed to add '$email'";
                    }
                }

                logActivity($user_id, $username, 'Bulk User Add', "Added $inserted users (admin: {$byRole['admin']}, manager: {$byRole['manager']}, agent: {$byRole['agent']}, customer: {$byRole['customer']}), " . count($errors) . " errors");
                $conn->close();
                $msg = "$inserted user(s) added." . ($byRole['admin'] ? " {$byRole['admin']} with full admin access." : "");
                echo json_encode(['success'=>true, 'message'=>$msg, 'inserted'=>$inserted, 'errors'=>$errors]);
                exit();

            case 'updateUser':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $uid = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $password = $_POST['password'] ?? '';
                $newRole = $_POST['role'] ?? 'agent';
                $branchId = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
                $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                if ($uid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
                    exit();
                }

                // validate
                if (strlen($name) < 2 || strlen($name) > 150) {
                    echo json_encode(['success' => false, 'message' => 'Name must be 2-150 characters']);
                    exit();
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                    exit();
                }
                if (!preg_match('/^\d{10,15}$/', $phone)) {
                    echo json_encode(['success' => false, 'message' => 'Phone must be 10-15 digits']);
                    exit();
                }
                if (!empty($password) && strlen($password) < 6) {
                    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
                    exit();
                }
                if (!in_array($newRole, ['admin', 'manager', 'agent', 'customer'], true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid role']);
                    exit();
                }

                // unique email excluding self
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->bind_param("si", $email, $uid);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Email already exists']);
                    exit();
                }
                $stmt->close();

                // unique phone excluding self
                $stmt = $conn->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
                $stmt->bind_param("si", $phone, $uid);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Phone number already exists']);
                    exit();
                }
                $stmt->close();

                // Customer handling
                if ($newRole === 'customer') {
                    if (!$customerId) {
                        $stC = $conn->prepare("SELECT id FROM customers WHERE email = ? OR mobile = ? LIMIT 1");
                        $stC->bind_param("ss", $email, $phone);
                        $stC->execute();
                        $cRow = $stC->get_result()->fetch_assoc();
                        $stC->close();
                        if ($cRow) $customerId = (int)$cRow['id'];
                    }
                } else {
                    $customerId = null;
                }

                // get old role for agent check
                $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $oldRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$oldRow) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'User not found']);
                    exit();
                }
                $oldRole = $oldRow['role'];

                // build update query
                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, password_hash=?, password_changed_at=NOW(), role=?, branch_id=?, customer_id=?, is_active=? WHERE id=?");
                    $stmt->bind_param("sssssiiii", $name, $email, $phone, $hash, $newRole, $branchId, $customerId, $isActive, $uid);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, role=?, branch_id=?, customer_id=?, is_active=? WHERE id=?");
                    $stmt->bind_param("ssssiiii", $name, $email, $phone, $newRole, $branchId, $customerId, $isActive, $uid);
                }

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update user']);
                    exit();
                }
                $stmt->close();

                if ($newRole === 'customer' && $customerId) {
                    $conn->query("UPDATE customers SET portal_user_id = $uid WHERE id = $customerId");
                }
                $stmt->close();

                // handle role change to/from agent
                if ($newRole === 'agent' && $oldRole !== 'agent') {
                    // check if agents record already exists
                    $stmt = $conn->prepare("SELECT id FROM agents WHERE user_id = ?");
                    $stmt->bind_param("i", $uid);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows === 0) {
                        $stmt->close();
                        $agentCode = 'AGT-' . str_pad($uid, 4, '0', STR_PAD_LEFT);
                        if ($branchId) {
                            $stmt = $conn->prepare("INSERT INTO agents (user_id, agent_code, name, mobile, email, branch_id) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param("issssi", $uid, $agentCode, $name, $phone, $email, $branchId);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO agents (user_id, agent_code, name, mobile, email) VALUES (?, ?, ?, ?, ?)");
                            $stmt->bind_param("issss", $uid, $agentCode, $name, $phone, $email);
                        }
                        $stmt->execute();
                    }
                    $stmt->close();
                } elseif ($oldRole === 'agent' && $newRole !== 'agent') {
                    $stmt = $conn->prepare("DELETE FROM agents WHERE user_id = ?");
                    $stmt->bind_param("i", $uid);
                    $stmt->execute();
                    $stmt->close();
                }

                $pwdNote = !empty($password) ? ' (pwd changed)' : '';
                logActivity($user_id, $username, 'User Updated', "Updated user #$uid: $name ($newRole)$pwdNote");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'User updated successfully']);
                exit();

            case 'deleteUser':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $uid = (int)($_POST['id'] ?? 0);
                if ($uid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
                    exit();
                }
                if ($uid == $user_id) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
                    exit();
                }

                // get name before delete
                $stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $delRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $delName = $delRow ? $delRow['name'] : 'Unknown';

                // check linked records
                $stmt = $conn->prepare("SELECT
                    (SELECT COUNT(*) FROM policy_entries WHERE created_by = ?) +
                    (SELECT COUNT(*) FROM policy_documents WHERE uploaded_by = ?) +
                    (SELECT COUNT(*) FROM expenses WHERE created_by = ?) +
                    (SELECT COUNT(*) FROM bank_transactions WHERE created_by = ?) +
                    (SELECT COUNT(*) FROM agent_transactions WHERE created_by = ?) +
                    (SELECT COUNT(*) FROM buyer_transactions WHERE created_by = ?) +
                    (SELECT COUNT(*) FROM other_income WHERE created_by = ?) +
                    (SELECT COUNT(*) FROM buyer_payout_charts WHERE created_by = ?) +
                    (SELECT COUNT(*) FROM agent_payout_charts WHERE created_by = ?) +
                    (SELECT COUNT(*) FROM payout_chart_uploads WHERE uploaded_by = ?) as cnt");
                $stmt->bind_param("iiiiiiiiii", $uid, $uid, $uid, $uid, $uid, $uid, $uid, $uid, $uid, $uid);
                $stmt->execute();
                $linked = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();
                if ($linked > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete user — they have $linked linked records in the system"]);
                    exit();
                }

                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param("i", $uid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'User Deleted', "Deleted user: $delName (#$uid)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
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
                    echo json_encode(['success' => false, 'message' => 'No users selected']);
                    exit();
                }

                $deleted = 0; $skipped = 0;
                foreach ($ids as $rawId) {
                    $uid = (int)$rawId;
                    if ($uid <= 0) { continue; }
                    if ($uid == $user_id) { $skipped++; continue; } // never self

                    // skip users with linked records
                    $stmt = $conn->prepare("SELECT
                        (SELECT COUNT(*) FROM policy_entries WHERE created_by = ?) +
                        (SELECT COUNT(*) FROM policy_documents WHERE uploaded_by = ?) +
                        (SELECT COUNT(*) FROM expenses WHERE created_by = ?) +
                        (SELECT COUNT(*) FROM bank_transactions WHERE created_by = ?) +
                        (SELECT COUNT(*) FROM agent_transactions WHERE created_by = ?) +
                        (SELECT COUNT(*) FROM buyer_transactions WHERE created_by = ?) +
                        (SELECT COUNT(*) FROM other_income WHERE created_by = ?) +
                        (SELECT COUNT(*) FROM buyer_payout_charts WHERE created_by = ?) +
                        (SELECT COUNT(*) FROM agent_payout_charts WHERE created_by = ?) +
                        (SELECT COUNT(*) FROM payout_chart_uploads WHERE uploaded_by = ?) as cnt");
                    $stmt->bind_param("iiiiiiiiii", $uid, $uid, $uid, $uid, $uid, $uid, $uid, $uid, $uid, $uid);
                    $stmt->execute();
                    $linked = $stmt->get_result()->fetch_assoc()['cnt'];
                    $stmt->close();
                    if ($linked > 0) { $skipped++; continue; }

                    $conn->begin_transaction();
                    try {
                        // agents row cascades via FK; del user
                        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->bind_param("i", $uid);
                        $stmt->execute();
                        $stmt->close();
                        $conn->commit();
                        $deleted++;
                    } catch (Exception $ex) {
                        $conn->rollback();
                        $skipped++;
                    }
                }

                $msg = "$deleted record(s) deleted";
                if ($skipped > 0) { $msg .= " ($skipped skipped — linked records or protected)"; }
                logActivity($user_id, $username, 'Bulk delete', "Bulk deleted $deleted user(s), skipped $skipped");
                $conn->close();
                echo json_encode(['success' => true, 'message' => $msg]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("users.php error: " . $e->getMessage());
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
    <title>User Management - Insurance Management System</title>

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
            $pageTitle   = 'User Management';
            $crumbs      = [['label' => 'System', 'icon' => 'fa-server']];
            $pageActions = [];
            if ($isAdmin) {
                $pageActions[] = ['label' => 'Add User', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
                $pageActions[] = ['label' => 'Bulk Add / Import', 'icon' => 'fa-file-excel', 'onclick' => 'usersImport.open()'];
            }
            $pageActions[] = ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(usersTable,'csv')"];
            $pageActions[] = ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(usersTable,'pdf')"];
            $pageActions[] = ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(usersTable,'print')"];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadUsers()'];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="usersSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="usersSearch" placeholder="Search users…" autocomplete="off"></div>
                <div class="hf-field"><label for="filterRole" title="Role"><i class="fas fa-user-tag"></i></label><select id="filterRole" class="filter-input searchable">
                    <option value="">All Roles</option>
                    <option value="admin">Admin</option>
                    <option value="manager">Manager</option>
                    <option value="agent">Agent</option>
                    <option value="customer">Customer / Policy Holder</option>
                </select></div>
                <div class="hf-field"><label for="filterBranch" title="Branch"><i class="fas fa-building"></i></label><select id="filterBranch" class="filter-input searchable">
                    <option value="">All Branches</option>
                </select></div>
                <div class="hf-field"><label for="filterStatus" title="Status"><i class="fas fa-toggle-on"></i></label><select id="filterStatus" class="filter-input searchable">
                    <option value="">All Status</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select></div>
                <div class="hf-field hf-range" title="Created date range"><label for="filterDateFrom"><i class="fas fa-calendar-days"></i></label><input type="date" id="filterDateFrom" class="filter-input" aria-label="From"><span class="hf-sep"><i class="fas fa-arrow-right-long"></i></span><input type="date" id="filterDateTo" class="filter-input" aria-label="To"></div>
                <div class="hf-seg" role="group" aria-label="Quick range"><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',0,this)">Today</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',6,this)">7 days</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',29,this)">30 days</button></div>
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
                            <div class="skeleton skeleton-table-cell" style="flex: 1.3"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.9"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="tableContainer" style="display: none;">
                    <div id="usersTable"></div>
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
    <!-- User Modal -->
    <div class="modal-overlay" id="userModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Add User</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="userForm">
                    <input type="hidden" id="userId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Name *</label>
                            <input type="text" id="userName" name="name" required minlength="2" maxlength="150" placeholder="Full name">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email *</label>
                            <input type="email" id="userEmail" name="email" required placeholder="email@example.com">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Phone *</label>
                            <input type="tel" id="userPhone" name="phone" required minlength="10" maxlength="15" placeholder="10-15 digits" pattern="\d{10,15}">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Password <span id="passwordHint" class="help-text" style="display:none;">(leave empty to keep current)</span></label>
                            <input type="password" id="userPassword" name="password" minlength="6" placeholder="Min 6 characters">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-tag"></i> Role *</label>
                            <select id="userRole" name="role" required class="searchable" onchange="onRoleChange(this.value)">
                                <option value="agent">Agent</option>
                                <option value="manager">Manager</option>
                                <option value="admin">Admin</option>
                                <option value="customer">Customer / Policy Holder</option>
                            </select>
                        </div>
                        <div class="form-group" id="userCustomerGroup" style="display:none;">
                            <label><i class="fas fa-address-book"></i> Link Customer Profile</label>
                            <select id="userCustomer" name="customer_id" class="searchable">
                                <option value="">— Auto-create new Customer Profile —</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-building"></i> Branch</label>
                            <select id="userBranch" name="branch_id" class="searchable">
                                <option value="">No Branch</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-toggle-on"></i> Status</label>
                        <div class="control-toggle-wrapper">
                            <input type="checkbox" class="toggle" id="userIsActive" name="is_active" checked>
                            <label for="userIsActive">Active</label>
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
        let usersTable = null;
        let usersData = [];
        let branchesData = [];
        let isEditMode = false;
        let bulkSel = null;

        $(document).ready(function() {
            loadBranches();
            loadCustomersDropdown();
            loadUsers();
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

                    // populate filter dropdown
                    const filterSel = $('#filterBranch');
                    filterSel.find('option:not(:first)').remove();
                    res.data.forEach(function(b) {
                        filterSel.append(`<option value="${b.name}">${b.name}</option>`);
                    });

                    // populate modal dropdown
                    if (IS_ADMIN) {
                        const modalSel = $('#userBranch');
                        modalSel.find('option:not(:first)').remove();
                        res.data.forEach(function(b) {
                            modalSel.append(`<option value="${b.id}">${b.name}</option>`);
                        });
                    }

                    setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
                }
            });
        }

        function loadUsers() {
            $.ajax({
                url: '?action=getUsers',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        usersData = res.data;
                        $('#loadingSkeleton').hide();
                        $('#tableContainer').show();
                        initDataTable(res.data);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load users' });
                    }
                },
                error: function(xhr, status, err) {
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
                }
            });
        }

        function initDataTable(data) {
            if (usersTable) return rsTbData(usersTable, data); // build once, swap rows

            // build columns
            let cols = [
                { data:'id', title:'<input type="checkbox" class="bulk-check-all">', orderable:false, searchable:false, className:'bulk-col', render:function(d){ return '<input type="checkbox" class="bulk-check" data-id="'+d+'">'; } },
                { data: 'id', title: 'ID', responsivePriority: window.innerWidth < 480 ? 9 : 1 }, // phone: id folds first
                { data: 'name', title: 'Name' },
                { data: 'email', title: 'Email' },
                { data: 'phone', title: 'Phone' },
                {
                    data: 'role',
                    title: 'Role',
                    render: function(d, type, row) {
                        const cls = d === 'admin' ? 'status-admin' : d === 'manager' ? 'status-manager' : d === 'customer' ? 'status-customer' : 'status-agent';
                        const label = d === 'customer' ? 'Customer' : escapeHtml(d.charAt(0).toUpperCase() + d.slice(1));
                        let html = `<span class="status-badge ${cls}">${label}</span>`;
                        if (d === 'agent' && row.agent_code) {
                            html += ` <span class="badge-code">${escapeHtml(row.agent_code)}</span>`;
                        } else if (d === 'customer' && row.customer_name) {
                            html += ` <span class="badge-code"><i class="fas fa-user-check"></i> ${escapeHtml(row.customer_name)}</span>`;
                        }
                        return html;
                    }
                },
                { data: 'branch_name', title: 'Branch' },
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
                    data: 'last_login',
                    title: 'Last Login',
                    render: function(d) { return d ? escapeHtml(d) : '<em class="text-muted">Never</em>'; }
                },
                { data: 'created_at', title: 'Created' },
                { data: 'created_by_name', title: 'Created By', visible: false },
                { data: 'last_login_ip', title: 'Last Login IP', visible: false },
                { data: 'password_changed', title: 'Password Changed', visible: false }
            ];

            // actions col - view+activity for all, more menu for admin
            // native col sized to its icons; admin on phone -> activity moves into the more menu
            var actInMenu = IS_ADMIN && window.innerWidth < 480;
            cols.push({
                title: 'Actions', field: '_act', headerSort: false, download: false, responsive: 0, resizable: false, widthGrow: 0,
                hozAlign: 'right', headerHozAlign: 'right', minWidth: actInMenu ? 104 : 156,
                formatter: function(cell) {
                    var row = cell.getRow().getData();
                    var html = '<div class="action-hub">';
                    html += '<button class="action-icon view-icon" data-act="view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button>';
                    if (!actInMenu) html += '<button class="action-icon" data-act="activity" data-id="' + row.id + '" title="Activity Log"><i class="fas fa-clock-rotate-left"></i></button>';
                    if (IS_ADMIN) {
                        html += '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button>';
                        html += '<div class="more-dropdown">';
                        if (actInMenu) html += '<button class="more-item" data-act="activity" data-id="' + row.id + '"><i class="fas fa-clock-rotate-left"></i> Activity Log</button>';
                        html += '<button class="more-item" data-act="edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> Edit</button>';
                        html += '<button class="more-item" data-act="toggle" data-id="' + row.id + '"><i class="fas fa-toggle-on"></i> Toggle Status</button>';
                        html += '<button class="more-item" data-act="resetpwd" data-id="' + row.id + '"><i class="fas fa-key"></i> Reset Password</button>';
                        html += '<div class="more-divider"></div>';
                        html += '<button class="more-item danger" data-act="delete" data-id="' + row.id + '"><i class="fas fa-trash"></i> Delete</button>';
                        html += '</div></div>';
                    }
                    return html + '</div>';
                }
            });

            usersTable = rsTable('#usersTable', {
                columns: cols, data: data, title: 'Users', file: 'users', sort: ['id', 'desc'],
                rowClass: function(r) { return r.is_active ? '' : 'dt-row-muted'; }, // inactive users
                info: function() { return [['Role', $('#filterRole').val() || 'All'], ['Branch', $('#filterBranch').val() || 'All'], ['Status', $('#filterStatus').val() || 'All']]; }
            });
            rsTbSearch(usersTable, $('#usersSearch').val());

            bulkSel = initBulkSelect({
                table: usersTable,
                label: 'users',
                onDelete: function(ids) {
                    showLoading('Deleting...');
                    $.ajax({
                        url: '?action=bulkDelete', method: 'POST', dataType: 'json',
                        data: { csrf_token: window.CSRF_TOKEN, ids: ids },
                        success: function(res) {
                            hideLoading();
                            if (res.success) { bulkSel.clear(); loadUsers(); Swal.fire('Deleted', res.message, 'success'); }
                            else { Swal.fire('Error', res.message, 'error'); }
                        },
                        error: function() { hideLoading(); Swal.fire('Error', 'Request failed', 'error'); }
                    });
                }
            });

            // bind filter events
            $('#filterDateFrom, #filterDateTo, #filterRole, #filterBranch, #filterStatus').off('change').on('change', applyFilters);

            // one delegated handler for all row actions
            $('#usersTable').off('click', '[data-act]').on('click', '[data-act]', function() {
                var id = parseInt($(this).data('id'), 10);
                var act = $(this).data('act');
                var rec = usersData.find(function(u) { return u.id === id; });
                if (!rec) return;
                if (act === 'view') viewRecord(rec);
                else if (act === 'activity') openActivity(rec, true);
                else if (act === 'edit' && IS_ADMIN) editUser(rec);
                else if (act === 'toggle' && IS_ADMIN) toggleStatus(rec);
                else if (act === 'resetpwd' && IS_ADMIN) resetPassword(rec);
                else if (act === 'delete' && IS_ADMIN) deleteUser(id);
            });

            applyFilters();
        }

        function applyFilters() {
            if (!usersTable) return;
            const dateFrom = $('#filterDateFrom').val(), dateTo = $('#filterDateTo').val();
            const role = $('#filterRole').val(), branch = $('#filterBranch').val(), status = $('#filterStatus').val();

            // created date range
            rsTbWhere(usersTable, 'date', (dateFrom || dateTo) ? function(r) {
                if (!r.created_at) return true;
                const d = new Date(r.created_at);
                if (dateFrom && d < new Date(dateFrom)) return false;
                if (dateTo && d > new Date(dateTo + 'T23:59:59')) return false;
                return true;
            } : null);
            rsTbWhere(usersTable, 'role', role ? function(r) { return r.role === role; } : null);
            rsTbWhere(usersTable, 'branch', branch ? function(r) { return r.branch_name === branch; } : null);
            rsTbWhere(usersTable, 'status', status ? function(r) { return !!Number(r.is_active) === (status === 'Active'); } : null);
        }

        // header search -> table
        $('#usersSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(usersTable, v); },200); });

        function clearFilters() {
            $('#usersSearch').val('');
            $('.hf-seg .is-on').removeClass('is-on');
            $('#filterDateFrom').val('');
            $('#filterDateTo').val('');
            $('#filterRole').val('');
            $('#filterBranch').val('');
            $('#filterStatus').val('');
            ['filterRole', 'filterBranch', 'filterStatus'].forEach(function(id) { var el = document.getElementById(id); if (el && el.sdRefresh) el.sdRefresh(); });
            rsTbClear(usersTable);
        }

        // role-capability summary by role
        const ROLE_CAPS = {
            admin: ['Full system access', 'Manage users, branches & roles', 'Create/edit/delete all records', 'View activity logs & reports', 'Send notifications'],
            manager: ['Manage policies, agents & customers', 'View branch reports', 'Create & assign tasks', 'No user/branch management', 'Cannot delete users'],
            agent: ['View & enter own policies', 'Add & manage assigned customers', 'View own commission & book', 'Submit claim intimations', 'No admin tools'],
            customer: ['Personal & family policy portal', 'View personal & family policies (Motor, Health, Life)', 'Download policy PDFs & documents', 'Submit & track claim intimations', 'Manage family members', 'Financial data & commissions hidden for privacy']
        };

        function onRoleChange(role) {
            if (role === 'customer') {
                $('#userCustomerGroup').show();
            } else {
                $('#userCustomerGroup').hide();
                $('#userCustomer').val('');
            }
            var el = document.getElementById('userCustomer');
            if (el && el.sdRefresh) el.sdRefresh();
        }

        function loadCustomersDropdown(selId) {
            $.getJSON('?action=getCustomersDropdown', function(res) {
                if (!res.success) return;
                var sel = $('#userCustomer');
                sel.find('option:not(:first)').remove();
                res.data.forEach(function(c) {
                    sel.append($('<option>').val(c.id).text(c.name + ' (' + c.mobile + ')'));
                });
                if (selId) sel.val(selId);
                var el = sel[0];
                if (el && el.sdRefresh) el.sdRefresh();
            });
        }

        // tabbed view modal — Profile / Activity Log / Permissions
        function viewRecord(d) {
            var roleCls = d.role === 'admin' ? 'status-admin' : d.role === 'manager' ? 'status-manager' : d.role === 'customer' ? 'status-customer' : 'status-agent';
            var statusCls = d.is_active ? 'status-active' : 'status-inactive';

            var profile = '<table class="view-detail-table">';
            profile += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">' + d.id + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-user"></i> Name</td><td class="vd-value">' + (escapeHtml(d.name) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-envelope"></i> Email</td><td class="vd-value">' + (escapeHtml(d.email) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-phone"></i> Phone</td><td class="vd-value">' + (escapeHtml(d.phone) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-user-tag"></i> Role</td><td class="vd-value"><span class="status-badge ' + roleCls + '">' + escapeHtml(d.role) + '</span></td>';
            profile += '<td class="vd-label"><i class="fas fa-building"></i> Branch</td><td class="vd-value">' + (escapeHtml(d.branch_name) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-toggle-on"></i> Status</td><td class="vd-value"><span class="status-badge ' + statusCls + '">' + (d.is_active ? 'Active' : 'Inactive') + '</span></td>';
            profile += '<td class="vd-label"><i class="fas fa-sign-in-alt"></i> Last Login</td><td class="vd-value">' + (d.last_login ? escapeHtml(d.last_login) : 'Never') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-calendar"></i> Created</td><td class="vd-value">' + (escapeHtml(d.created_at) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-user-plus"></i> Created By</td><td class="vd-value">' + (escapeHtml(d.created_by_name) || '-') + '</td></tr>';
            profile += '<tr><td class="vd-label"><i class="fas fa-network-wired"></i> Last Login IP</td><td class="vd-value">' + (escapeHtml(d.last_login_ip) || '-') + '</td>';
            profile += '<td class="vd-label"><i class="fas fa-key"></i> Password Changed</td><td class="vd-value">' + (d.password_changed ? escapeHtml(d.password_changed) : 'Never') + '</td></tr>';
            profile += '</table>';

            var caps = ROLE_CAPS[d.role] || [];
            var perms = '<table class="view-detail-table">';
            caps.forEach(function(cap) {
                perms += '<tr><td class="vd-label"><i class="fas fa-check"></i> Capability</td><td class="vd-value" colspan="3">' + escapeHtml(cap) + '</td></tr>';
            });
            perms += '</table>';

            var html = '<div class="tab-nav">';
            html += '<button type="button" class="tab-btn active" data-tab="profile"><i class="fas fa-user"></i> Profile</button>';
            html += '<button type="button" class="tab-btn" data-tab="activity"><i class="fas fa-clock-rotate-left"></i> Activity Log</button>';
            html += '<button type="button" class="tab-btn" data-tab="perms"><i class="fas fa-shield-halved"></i> Permissions</button>';
            html += '</div>';
            html += '<div class="tab-content active" data-tab="profile">' + profile + '</div>';
            html += '<div class="tab-content" data-tab="activity" id="viewActivityPane"><em class="text-muted">Loading activity...</em></div>';
            html += '<div class="tab-content" data-tab="perms">' + perms + '</div>';

            document.getElementById('viewModalTitle').innerHTML = '<i class="fas fa-eye"></i> User Details';
            document.getElementById('viewModalBody').innerHTML = html;
            initModalTabs('#viewModalBody');
            document.getElementById('viewModal').classList.add('active');
            loadUserActivity(d.id, '#viewActivityPane');
        }

        // fetch recent activity rows into a target pane
        function loadUserActivity(uid, targetSel) {
            $.ajax({
                url: '?action=getUserActivity&id=' + uid,
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    // raw timestamp sorts, ts shows
                    $(targetSel).html('<div class="tab-table"></div>');
                    rsTabTable($(targetSel).children()[0], {
                        data: res.success ? res.data : [], title: 'User Activity', file: 'user_activity', sort: ['timestamp', 'desc'],
                        empty: 'No activity recorded for this user yet',
                        columns: [
                            { data: 'timestamp', title: 'When', render: function(d, t, r) { return escapeHtml(r.ts || '-'); } },
                            { data: 'action', title: 'Action', render: function(d) { return escapeHtml(d || '-'); } },
                            { data: 'details', title: 'Details', render: function(d) { return escapeHtml(d || '-'); } }
                        ]
                    });
                },
                error: function() { $(targetSel).html('<em class="text-muted">Failed to load activity.</em>'); }
            });
        }

        // Activity icon — opens view modal on Activity Log tab
        function openActivity(d) {
            viewRecord(d);
            setTimeout(function() { $('#viewModalBody .tab-btn[data-tab="activity"]').trigger('click'); }, 50);
        }

        // quick toggle status
        function toggleStatus(u) {
            var fd = new FormData();
            fd.append('id', u.id);
            fd.append('csrf_token', window.CSRF_TOKEN);
            showLoading('Updating status...');
            $.ajax({
                url: '?action=toggleUserStatus', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(res) {
                    hideLoading();
                    if (res.success) { Swal.fire({ icon:'success', text:res.message, timer:1800, showConfirmButton:false }); setTimeout(loadUsers, 100); }
                    else { Swal.fire('Error', res.message, 'error'); }
                },
                error: function() { hideLoading(); Swal.fire('Error', 'Request failed', 'error'); }
            });
        }

        // reset password — opens Edit modal focused on password field (no dedicated backend)
        function resetPassword(u) {
            editUser(u);
            setTimeout(function() {
                $('#userPassword').attr('required', true).focus();
                Swal.fire({ icon:'info', title:'Reset Password', text:'Enter a new password for ' + (u.name || 'this user') + ' and save.', timer:2600, showConfirmButton:false });
            }, 150);
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
        }

        // overlay close
        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) closeViewModal();
        });

        <?php if ($isAdmin): ?>
        function openAddModal() {
            isEditMode = false;
            $('#modalTitle').html('<i class="fas fa-user-plus"></i> Add User');
            $('#userForm')[0].reset();
            $('#userId').val('');
            $('#userRole').val('agent');
            onRoleChange('agent');
            $('#userIsActive').prop('checked', true);
            $('#passwordHint').hide();
            $('#userPassword').attr('required', true);
            $('#userModal').addClass('active');
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
        }

        function editUser(user) {
            isEditMode = true;
            $('#modalTitle').html('<i class="fas fa-edit"></i> Edit User');
            $('#userId').val(user.id);
            $('#userName').val(user.name);
            $('#userEmail').val(user.email);
            $('#userPhone').val(user.phone);
            $('#userRole').val(user.role);
            onRoleChange(user.role);
            if (user.customer_id) $('#userCustomer').val(user.customer_id);
            $('#userBranch').val(user.branch_id || '');
            $('#userIsActive').prop('checked', !!user.is_active);
            $('#userPassword').val('').removeAttr('required');
            $('#passwordHint').show();
            $('#userModal').addClass('active');
            // refresh searchable display
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
        }

        function closeModal() {
            $('#userModal').removeClass('active');
            $('#userForm')[0].reset();
        }

        // close modal on overlay click
        document.getElementById('userModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // form submit
        $('#userForm').on('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const action = isEditMode ? 'updateUser' : 'addUser';

            // checkbox handling - if unchecked, FormData won't include it
            if (!$('#userIsActive').is(':checked')) {
                formData.delete('is_active');
            }
            formData.append('csrf_token', window.CSRF_TOKEN);

            showLoading('Saving user...');

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
                        setTimeout(loadUsers, 100);
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

        function deleteUser(uid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete User?',
                text: 'This action cannot be undone',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) return;

                const fd = new FormData();
                fd.append('id', uid);
                fd.append('csrf_token', window.CSRF_TOKEN);

                showLoading('Deleting user...');
                $.ajax({
                    url: '?action=deleteUser',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        hideLoading();
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                            setTimeout(loadUsers, 100);
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
        var usersImport = RSImport({
            key: 'users', title: 'Bulk Add / Import Users', url: '?action=bulkAddUsers',
            hint: 'Add many staff logins at once — upload an Excel/CSV file or type the rows. <strong>Name, email and phone</strong> are required and must be unique. Leave <strong>password</strong> blank to auto-generate one (share it or have them reset it). <strong>Branch</strong> is matched on branch name. Creating a row with role <strong>agent</strong> also creates its Agent record, exactly like the single Add User form.',
            onDone: function() { setTimeout(loadUsers, 100); },
            fields: [
                { key: 'name', label: 'Name *', cls: 'bg-name', req: true, max: 150, ph: 'Full name', aliases: ['name', 'fullname', 'username', 'staffname'], demo: 'Demo Manager', demo2: 'Demo Agent' },
                { key: 'email', label: 'Email *', cls: 'bg-email', req: true, ph: 'user@example.com', aliases: ['email', 'emailaddress', 'emailid', 'mail', 'loginemail'], demo: 'manager@example.com', demo2: 'agent@example.com' },
                { key: 'phone', label: 'Phone *', cls: 'bg-phone', req: true, max: 15, digits: true, ph: '10-15 digits', aliases: ['phone', 'phoneno', 'mobile', 'mobileno', 'contact', 'contactno', 'whatsapp'], demo: '9876543210', demo2: '9876543211' },
                { key: 'password', label: 'Password', cls: 'bg-pwd', ph: 'blank = auto', aliases: ['password', 'pwd', 'pass'], demo: '', demo2: '' },
                { key: 'role', label: 'Role', cls: 'bg-role', type: 'select', def: 'agent',
                  opts: [{ v: 'agent', t: 'Agent' }, { v: 'manager', t: 'Manager' }, { v: 'admin', t: 'Admin' }, { v: 'customer', t: 'Customer' }],
                  aliases: ['role', 'usertype', 'type', 'designation'], demo: 'manager', demo2: 'agent' },
                { key: 'branch', label: 'Branch', cls: 'bg-branch', ph: 'branch name', aliases: ['branch', 'branchname', 'office', 'location'],
                  demo: '', demo2: '' },
                { key: 'is_active', label: 'Active', cls: 'bg-active', type: 'select', def: '1',
                  opts: [{ v: '1', t: 'Yes' }, { v: '0', t: 'No' }],
                  aliases: ['isactive', 'active', 'status', 'enabled'], demo: '1', demo2: '1' }
            ]
        });
        <?php endif; ?>
    </script>
</body>
</html>
