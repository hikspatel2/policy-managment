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
$current_page = 'vehicles';

// extra vehicle fields (form + import) -> [err, chassis, engine, cc, seats, gvw, regDate]
function vehExtra(array $r): array {
    $id = fn($k) => strtoupper(preg_replace('/\s+/', '', (string)($r[$k] ?? ''))) ?: null;
    $int = fn($k) => (int)($r[$k] ?? 0) ?: null; // blank/0 = not set (import sends 0)
    [$ch, $en, $cc, $seat, $gvw] = [$id('chassis_number'), $id('engine_number'), $int('cubic_capacity'), $int('seating_capacity'), $int('gvw_kg')];
    $reg = trim((string)($r['registration_date'] ?? '')) ?: null;
    $err = null;
    if ($ch !== null && !preg_match('/^[A-Z0-9]{6,30}$/', $ch)) $err = 'Chassis number must be 6-30 letters/digits';
    elseif ($en !== null && !preg_match('/^[A-Z0-9]{6,30}$/', $en)) $err = 'Engine number must be 6-30 letters/digits';
    elseif ($cc !== null && ($cc < 1 || $cc > 30000)) $err = 'Cubic capacity must be 1-30000 cc';
    elseif ($seat !== null && ($seat < 1 || $seat > 255)) $err = 'Seating capacity must be 1-255';
    elseif ($gvw !== null && ($gvw < 1 || $gvw > 100000)) $err = 'GVW must be 1-100000 kg';
    elseif ($reg !== null && (!($d = DateTime::createFromFormat('Y-m-d', $reg)) || $d->format('Y-m-d') !== $reg)) $err = 'Registration date must be YYYY-MM-DD';
    return [$err, $ch, $en, $cc, $seat, $gvw, $reg];
}

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getCustomersList':
                $stmt = $conn->prepare("SELECT id, name, mobile FROM customers WHERE is_active = 1 ORDER BY name");
                $stmt->execute();
                $res = $stmt->get_result();
                $customers = [];
                while ($row = $res->fetch_assoc()) $customers[] = $row;
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $customers]);
                exit();

            case 'getVehicles':
                // policy count + latest policy status/expiry + active-policy flag via subqueries
                $polCols = "
                    (SELECT COUNT(*) FROM policy_entries pe WHERE pe.vehicle_id = v.id) as policy_count,
                    (SELECT pe.status FROM policy_entries pe WHERE pe.vehicle_id = v.id ORDER BY pe.entry_date DESC, pe.id DESC LIMIT 1) as latest_status,
                    (SELECT DATE_FORMAT(pe.policy_end_date, '%b %d, %Y') FROM policy_entries pe WHERE pe.vehicle_id = v.id ORDER BY pe.entry_date DESC, pe.id DESC LIMIT 1) as latest_expiry,
                    (SELECT COUNT(*) FROM policy_entries pe WHERE pe.vehicle_id = v.id AND pe.status = 'active' AND pe.policy_end_date >= CURDATE()) as active_count
                ";
                if ($role === 'agent') {
                    // agent: only vehicles linked via policy_entries
                    $stmt = $conn->prepare("
                        SELECT v.*, c.name as customer_name, c.mobile as customer_mobile, $polCols
                        FROM vehicles v
                        JOIN customers c ON v.customer_id = c.id
                        WHERE v.customer_id IN (
                            SELECT DISTINCT pe.customer_id FROM policy_entries pe
                            WHERE pe.agent_id = (SELECT a.id FROM agents a WHERE a.user_id = ?)
                        )
                        ORDER BY v.id DESC
                    ");
                    $stmt->bind_param("i", $user_id);
                } else {
                    // admin/manager: all vehicles
                    $stmt = $conn->prepare("
                        SELECT v.*, c.name as customer_name, c.mobile as customer_mobile, $polCols
                        FROM vehicles v
                        JOIN customers c ON v.customer_id = c.id
                        ORDER BY v.id DESC
                    ");
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $vehicles = [];
                while ($row = $res->fetch_assoc()) {
                    $vehicles[] = [
                        'id' => (int)$row['id'],
                        'vehicle_number' => $row['vehicle_number'],
                        'vehicle_type' => $row['vehicle_type'],
                        'make' => $row['make'],
                        'model' => $row['model'],
                        'year_of_manufacture' => $row['year_of_manufacture'],
                        'fuel_type' => $row['fuel_type'],
                        'chassis_number' => $row['chassis_number'] ?? null,
                        'engine_number' => $row['engine_number'] ?? null,
                        'cubic_capacity' => $row['cubic_capacity'] ?? null,
                        'seating_capacity' => $row['seating_capacity'] ?? null,
                        'gvw_kg' => $row['gvw_kg'] ?? null,
                        'registration_date' => $row['registration_date'] ?? null,
                        'is_active' => (int)($row['is_active'] ?? 1),
                        'customer_id' => (int)$row['customer_id'],
                        'customer_name' => $row['customer_name'],
                        'customer_mobile' => $row['customer_mobile'],
                        'policy_count' => (int)$row['policy_count'],
                        'latest_status' => $row['latest_status'] ?: '',
                        'latest_expiry' => $row['latest_expiry'] ?: '-',
                        'has_active' => (int)$row['active_count'] > 0 ? 1 : 0
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $vehicles]);
                exit();

            case 'getVehiclePolicies':
                $vid = (int)($_GET['id'] ?? 0);
                if ($vid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid vehicle ID']); exit(); }
                // iso dates + plain numbers for the tab table; agent = own policies only
                $agentOnly = $role === 'agent';
                $stmt = $conn->prepare("SELECT id, policy_number, total_premium_amount, status, entry_date, policy_end_date
                    FROM policy_entries WHERE vehicle_id = ?" . ($agentOnly ? " AND agent_id = ?" : "") . " ORDER BY entry_date DESC, id DESC");
                if ($agentOnly) { $aid = agentIdForUser($conn, $user_id) ?? 0; $stmt->bind_param("ii", $vid, $aid); }
                else $stmt->bind_param("i", $vid);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($r = $res->fetch_assoc()) {
                    $rows[] = [
                        'id' => (int)$r['id'],
                        'entry_date' => $r['entry_date'],
                        'policy_number' => $r['policy_number'],
                        'premium' => (float)$r['total_premium_amount'],
                        'status' => $r['status'],
                        'expiry' => $r['policy_end_date']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();

            case 'getCategories':
                // admins get all (for management); others only active (for dropdowns)
                $onlyActive = ($role !== 'admin');
                $res = $conn->query("SELECT id, name, is_active FROM vehicle_categories" . ($onlyActive ? " WHERE is_active=1" : "") . " ORDER BY name");
                $cats = [];
                if ($res) while ($c = $res->fetch_assoc()) $cats[] = ['id'=>(int)$c['id'], 'name'=>$c['name'], 'is_active'=>(int)$c['is_active']];
                $conn->close();
                echo json_encode(['success'=>true, 'data'=>$cats]);
                exit();

            case 'addCategory':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }
                $name = trim($_POST['name'] ?? '');
                if ($name === '' || mb_strlen($name) > 60) { echo json_encode(['success'=>false,'message'=>'Category name is required (max 60 chars)']); exit(); }
                $stmt = $conn->prepare("SELECT id FROM vehicle_categories WHERE name = ?");
                $stmt->bind_param("s", $name); $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) { $stmt->close(); $conn->close(); echo json_encode(['success'=>false,'message'=>'That category already exists']); exit(); }
                $stmt->close();
                $stmt = $conn->prepare("INSERT INTO vehicle_categories (name) VALUES (?)");
                $stmt->bind_param("s", $name); $stmt->execute(); $stmt->close();
                logActivity($user_id, $username, 'Category Added', "Vehicle category: $name");
                $conn->close();
                echo json_encode(['success'=>true, 'message'=>'Category added']);
                exit();

            case 'updateCategory':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }
                $cid = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                if ($cid <= 0 || $name === '' || mb_strlen($name) > 60) { echo json_encode(['success'=>false,'message'=>'Valid category and name (max 60) required']); exit(); }
                $stmt = $conn->prepare("SELECT name FROM vehicle_categories WHERE id = ?");
                $stmt->bind_param("i", $cid); $stmt->execute();
                $curr = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$curr) { $conn->close(); echo json_encode(['success'=>false,'message'=>'Category not found']); exit(); }
                $oldName = $curr['name'];
                $stmt = $conn->prepare("SELECT id FROM vehicle_categories WHERE name = ? AND id != ?");
                $stmt->bind_param("si", $name, $cid); $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) { $stmt->close(); $conn->close(); echo json_encode(['success'=>false,'message'=>'Another category already has that name']); exit(); }
                $stmt->close();
                // rename + keep denormalized labels on vehicles/policies in sync (neither has a unique on vehicle_type)
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("UPDATE vehicle_categories SET name = ? WHERE id = ?");
                    $stmt->bind_param("si", $name, $cid); $stmt->execute(); $stmt->close();
                    if ($oldName !== $name) {
                        $u = $conn->prepare("UPDATE vehicles SET vehicle_type = ? WHERE vehicle_type = ?");
                        $u->bind_param("ss", $name, $oldName); $u->execute(); $u->close();
                        $u = $conn->prepare("UPDATE policy_entries SET vehicle_type = ? WHERE vehicle_type = ?");
                        $u->bind_param("ss", $name, $oldName); $u->execute(); $u->close();
                    }
                    $conn->commit();
                } catch (Exception $ex) { $conn->rollback(); $conn->close(); echo json_encode(['success'=>false,'message'=>'Failed to update category']); exit(); }
                logActivity($user_id, $username, 'Category Updated', "Vehicle category: $oldName -> $name");
                $conn->close();
                echo json_encode(['success'=>true, 'message'=>'Category updated']);
                exit();

            case 'toggleCategory':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }
                $cid = (int)($_POST['id'] ?? 0);
                $active = ((int)($_POST['is_active'] ?? 0)) ? 1 : 0;
                if ($cid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid category']); exit(); }
                $stmt = $conn->prepare("UPDATE vehicle_categories SET is_active = ? WHERE id = ?");
                $stmt->bind_param("ii", $active, $cid); $stmt->execute(); $stmt->close();
                logActivity($user_id, $username, 'Category Toggled', "Vehicle category #$cid active=$active");
                $conn->close();
                echo json_encode(['success'=>true, 'message'=>$active ? 'Category activated' : 'Category deactivated']);
                exit();

            case 'addVehicle':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $vehicleNumber = strtoupper(trim($_POST['vehicle_number'] ?? ''));
                $vehicleType = trim($_POST['vehicle_type'] ?? '');
                $make = trim($_POST['make'] ?? '') ?: null;
                $model = trim($_POST['model'] ?? '') ?: null;
                $year = !empty($_POST['year_of_manufacture']) ? (int)$_POST['year_of_manufacture'] : null;
                $fuelType = trim($_POST['fuel_type'] ?? '') ?: null;
                $customerId = (int)($_POST['customer_id'] ?? 0);
                [$xErr, $chassis, $engine, $cc, $seats, $gvw, $regDate] = vehExtra($_POST);
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                if ($xErr) { echo json_encode(['success' => false, 'message' => $xErr]); exit(); }

                // validate required
                if (empty($vehicleNumber)) {
                    echo json_encode(['success' => false, 'message' => 'Vehicle number is required']);
                    exit();
                }
                if (empty($vehicleType)) {
                    echo json_encode(['success' => false, 'message' => 'Vehicle type is required']);
                    exit();
                }
                if ($customerId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Please select a customer']);
                    exit();
                }

                // unique vehicle_number
                $stmt = $conn->prepare("SELECT id FROM vehicles WHERE vehicle_number = ?");
                $stmt->bind_param("s", $vehicleNumber);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Vehicle number already exists']);
                    exit();
                }
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO vehicles (vehicle_number, vehicle_type, make, model, year_of_manufacture, fuel_type, chassis_number, engine_number, cubic_capacity, seating_capacity, gvw_kg, registration_date, customer_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssisssiiisii", $vehicleNumber, $vehicleType, $make, $model, $year, $fuelType, $chassis, $engine, $cc, $seats, $gvw, $regDate, $customerId, $isActive);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add vehicle']);
                    exit();
                }
                $insertId = $stmt->insert_id;
                $stmt->close();

                logActivity($user_id, $username, 'Vehicle Created', "Added vehicle: $vehicleNumber ($vehicleType)" . ($chassis ? ", chassis $chassis" : '') . ($engine ? ", engine $engine" : '') . ($isActive ? '' : ', inactive'));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Vehicle added successfully', 'data' => ['id' => $insertId]]);
                exit();

            case 'bulkAddVehicles':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows) || count($rows) === 0) { echo json_encode(['success'=>false,'message'=>'No vehicles to add']); exit(); }
                $off = max(0, (int)($_POST['row_offset'] ?? 0)); // client posts in chunks - keep row numbers global

                // canonical category lookup, case-insensitive
                $cats = [];
                foreach (getVehicleCategories($conn, true) as $c) $cats[strtolower($c)] = $c;

                $inserted = 0; $created = 0; $errors = [];
                $seenVno = []; // within-batch dedup
                foreach ($rows as $idx => $r) {
                    $line = $off + $idx + 1;
                    $vno = strtoupper(trim($r['vehicle_number'] ?? ''));
                    $vtype = trim($r['vehicle_type'] ?? '');
                    $make = trim($r['make'] ?? '') ?: null;
                    $model = trim($r['model'] ?? '') ?: null;
                    $year = (int)($r['year_of_manufacture'] ?? 0);
                    $fuel = trim($r['fuel_type'] ?? '') ?: null;
                    $cmob = trim($r['customer_mobile'] ?? '');
                    $cname = trim($r['customer_name'] ?? '');

                    if ($vno === '' && $cmob === '') continue; // trailing blank line

                    if ($vno === '' || strlen($vno) > 20) { $errors[] = "Row $line: vehicle number is required (max 20 chars)"; continue; }
                    if ($vtype === '' || !isset($cats[strtolower($vtype)])) { $errors[] = "Row $line: unknown category '$vtype' — use one of: " . implode(', ', $cats); continue; }
                    $vtype = $cats[strtolower($vtype)];
                    if ($year && ($year < 1900 || $year > 2100)) { $errors[] = "Row $line: year '$year' is out of range"; continue; }
                    $yearVal = $year ?: null;
                    if ($fuel !== null && strlen($fuel) > 20) { $errors[] = "Row $line: fuel type too long"; continue; }
                    if (!preg_match('/^\d{10,15}$/', $cmob)) { $errors[] = "Row $line: owner mobile must be 10-15 digits"; continue; }
                    [$xErr, $chassis, $engine, $cc, $seats, $gvw, $regDate] = vehExtra($r);
                    if ($xErr) { $errors[] = "Row $line: $xErr"; continue; }

                    if (isset($seenVno[$vno])) { $errors[] = "Row $line: duplicate vehicle number '$vno' in list"; continue; }
                    $stmt = $conn->prepare("SELECT id FROM vehicles WHERE vehicle_number = ?");
                    $stmt->bind_param("s", $vno); $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) { $stmt->close(); $errors[] = "Row $line: vehicle '$vno' already exists"; continue; }
                    $stmt->close();

                    // owner: match on mobile, else exact name, else create (same as policy upload)
                    $stmt = $conn->prepare("SELECT id FROM customers WHERE mobile = ? LIMIT 1");
                    $stmt->bind_param("s", $cmob); $stmt->execute();
                    $cust = $stmt->get_result()->fetch_assoc(); $stmt->close();
                    $customerId = $cust ? (int)$cust['id'] : 0;

                    if (!$customerId && $cname !== '') {
                        $stmt = $conn->prepare("SELECT id FROM customers WHERE name = ? LIMIT 1");
                        $stmt->bind_param("s", $cname); $stmt->execute();
                        $byName = $stmt->get_result()->fetch_assoc(); $stmt->close();
                        if ($byName) $customerId = (int)$byName['id'];
                    }

                    $conn->begin_transaction();
                    try {
                        if (!$customerId) {
                            if (strlen($cname) < 2) { throw new Exception('no owner'); }
                            $stmt = $conn->prepare("INSERT INTO customers (name, mobile) VALUES (?, ?)");
                            $stmt->bind_param("ss", $cname, $cmob);
                            $stmt->execute(); $customerId = (int)$stmt->insert_id; $stmt->close();
                            $refCode = 'REF-' . str_pad($customerId, 4, '0', STR_PAD_LEFT);
                            $stmt = $conn->prepare("UPDATE customers SET referral_code = ? WHERE id = ?");
                            $stmt->bind_param("si", $refCode, $customerId);
                            $stmt->execute(); $stmt->close();
                            $created++;
                        }

                        $stmt = $conn->prepare("INSERT INTO vehicles (vehicle_number, vehicle_type, make, model, year_of_manufacture, fuel_type, chassis_number, engine_number, cubic_capacity, seating_capacity, gvw_kg, registration_date, customer_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssisssiiisi", $vno, $vtype, $make, $model, $yearVal, $fuel, $chassis, $engine, $cc, $seats, $gvw, $regDate, $customerId);
                        $stmt->execute(); $stmt->close();

                        $conn->commit();
                        $seenVno[$vno] = 1;
                        $inserted++;
                    } catch (Exception $ex) {
                        $conn->rollback();
                        $errors[] = $ex->getMessage() === 'no owner'
                            ? "Row $line: owner mobile '$cmob' not found — add an owner name to create the customer"
                            : "Row $line: failed to add '$vno'";
                    }
                }

                logActivity($user_id, $username, 'Bulk Vehicle Add', "Added $inserted vehicles, $created new customers, " . count($errors) . " errors");
                $conn->close();
                $msg = "$inserted vehicle(s) added." . ($created ? " $created new customer(s) created." : "");
                echo json_encode(['success'=>true, 'message'=>$msg, 'inserted'=>$inserted, 'errors'=>$errors]);
                exit();

            case 'updateVehicle':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $vid = (int)($_POST['id'] ?? 0);
                $vehicleNumber = strtoupper(trim($_POST['vehicle_number'] ?? ''));
                $vehicleType = trim($_POST['vehicle_type'] ?? '');
                $make = trim($_POST['make'] ?? '') ?: null;
                $model = trim($_POST['model'] ?? '') ?: null;
                $year = !empty($_POST['year_of_manufacture']) ? (int)$_POST['year_of_manufacture'] : null;
                $fuelType = trim($_POST['fuel_type'] ?? '') ?: null;
                $customerId = (int)($_POST['customer_id'] ?? 0);
                [$xErr, $chassis, $engine, $cc, $seats, $gvw, $regDate] = vehExtra($_POST);
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                if ($xErr) { echo json_encode(['success' => false, 'message' => $xErr]); exit(); }

                if ($vid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid vehicle ID']);
                    exit();
                }
                if (empty($vehicleNumber)) {
                    echo json_encode(['success' => false, 'message' => 'Vehicle number is required']);
                    exit();
                }
                if (empty($vehicleType)) {
                    echo json_encode(['success' => false, 'message' => 'Vehicle type is required']);
                    exit();
                }
                if ($customerId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Please select a customer']);
                    exit();
                }

                // unique vehicle_number excluding self
                $stmt = $conn->prepare("SELECT id FROM vehicles WHERE vehicle_number = ? AND id != ?");
                $stmt->bind_param("si", $vehicleNumber, $vid);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Vehicle number already exists']);
                    exit();
                }
                $stmt->close();

                $stmt = $conn->prepare("UPDATE vehicles SET vehicle_number=?, vehicle_type=?, make=?, model=?, year_of_manufacture=?, fuel_type=?, chassis_number=?, engine_number=?, cubic_capacity=?, seating_capacity=?, gvw_kg=?, registration_date=?, customer_id=?, is_active=? WHERE id=?");
                $stmt->bind_param("ssssisssiiisiii", $vehicleNumber, $vehicleType, $make, $model, $year, $fuelType, $chassis, $engine, $cc, $seats, $gvw, $regDate, $customerId, $isActive, $vid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update vehicle']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Vehicle Updated', "Updated vehicle #$vid: $vehicleNumber" . ($chassis ? ", chassis $chassis" : '') . ($engine ? ", engine $engine" : '') . ', ' . ($isActive ? 'active' : 'inactive'));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Vehicle updated successfully']);
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
                foreach ($ids as $vid) {
                    // skip if linked policies (same check as single delete)
                    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM policy_entries WHERE vehicle_id = ?");
                    $stmt->bind_param("i", $vid);
                    $stmt->execute();
                    $linked = $stmt->get_result()->fetch_assoc()['cnt'];
                    $stmt->close();
                    if ($linked > 0) { $skipped++; continue; }

                    $stmt = $conn->prepare("DELETE FROM vehicles WHERE id = ?");
                    $stmt->bind_param("i", $vid);
                    if ($stmt->execute() && $stmt->affected_rows > 0) $deleted++;
                    $stmt->close();
                }

                logActivity($user_id, $username, 'Bulk delete', "Deleted $deleted vehicle(s), skipped $skipped");
                $conn->close();
                $msg = "$deleted record(s) deleted";
                if ($skipped > 0) $msg .= " — $skipped skipped (linked policies)";
                echo json_encode(['success' => true, 'message' => $msg]);
                exit();

            case 'deleteVehicle':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $vid = (int)($_POST['id'] ?? 0);
                if ($vid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid vehicle ID']);
                    exit();
                }

                // get number before delete
                $stmt = $conn->prepare("SELECT vehicle_number FROM vehicles WHERE id = ?");
                $stmt->bind_param("i", $vid);
                $stmt->execute();
                $delRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $delNum = $delRow ? $delRow['vehicle_number'] : 'Unknown';

                // check if vehicle has policies linked
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM policy_entries WHERE vehicle_id = ?");
                $stmt->bind_param("i", $vid);
                $stmt->execute();
                $linked = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();
                if ($linked > 0) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => "Cannot delete vehicle $delNum — it has $linked linked policy entries"]);
                    exit();
                }

                $stmt = $conn->prepare("DELETE FROM vehicles WHERE id = ?");
                $stmt->bind_param("i", $vid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete vehicle']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Vehicle Deleted', "Deleted vehicle: $delNum (#$vid)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Vehicle deleted successfully']);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("vehicles.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
        exit();
    }
}

$isAdmin = ($role === 'admin');
$isAgent = ($role === 'agent');
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
    <title>Vehicle Management - Insurance Management System</title>

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
            $pageIcon    = 'fa-car';
            $pageTitle   = 'Vehicle Management';
            $crumbs      = [];
            $pageActions = [];
            if ($isAdmin) {
                $pageActions[] = ['label' => 'Add Vehicle', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
                $pageActions[] = ['label' => 'Bulk Add / Import', 'icon' => 'fa-file-excel', 'onclick' => 'vehiclesImport.open()'];
                $pageActions[] = ['label' => 'Categories', 'icon' => 'fa-tags', 'onclick' => 'openCategoriesModal()'];
            }
            $pageActions[] = ['label' => 'CSV',     'icon' => 'fa-file-csv', 'onclick' => "dtBtn(vehiclesTable,'csv')"];
            $pageActions[] = ['label' => 'PDF',     'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(vehiclesTable,'pdf')"];
            $pageActions[] = ['label' => 'Print',   'icon' => 'fa-print',    'onclick' => "dtBtn(vehiclesTable,'print')"];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate',   'onclick' => 'loadVehicles()'];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="vehSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="vehSearch" placeholder="Search vehicles…" autocomplete="off"></div>
                <div class="hf-field" title="Vehicle category"><label for="filterVehicleType"><i class="fas fa-car-side"></i></label>
                    <select id="filterVehicleType" class="searchable">
                        <option value="">All Categories</option>
                        <?php echo vehicleCategoryOptionsHtml(); ?>
                    </select>
                </div>
                <div class="hf-field" title="Fuel type"><label for="filterFuelType"><i class="fas fa-gas-pump"></i></label>
                    <select id="filterFuelType" class="searchable">
                        <option value="">All Fuel Types</option>
                        <option value="Petrol">Petrol</option>
                        <option value="Diesel">Diesel</option>
                        <option value="EV">EV</option>
                        <option value="CNG">CNG</option>
                    </select>
                </div>
                <div class="hf-field" title="Status"><label for="filterVehStatus"><i class="fas fa-toggle-on"></i></label>
                    <select id="filterVehStatus" class="searchable">
                        <option value="">All Statuses</option>
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
                <div class="hf-field" title="Customer"><label for="filterCustomer"><i class="fas fa-user"></i></label>
                    <select id="filterCustomer" class="searchable">
                        <option value="">All Customers</option>
                    </select>
                </div>
                <button type="button" class="btn btn-icon" onclick="clearFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>

            <div class="data-section">

                <!-- Skeleton loader -->
                <div id="skeletonLoader">
                    <div class="skeleton-table">
                        <div class="skeleton-row"><div class="skeleton-cell"></div><div class="skeleton-cell"></div><div class="skeleton-cell"></div><div class="skeleton-cell"></div></div>
                        <div class="skeleton-row"><div class="skeleton-cell"></div><div class="skeleton-cell"></div><div class="skeleton-cell"></div><div class="skeleton-cell"></div></div>
                        <div class="skeleton-row"><div class="skeleton-cell"></div><div class="skeleton-cell"></div><div class="skeleton-cell"></div><div class="skeleton-cell"></div></div>
                        <div class="skeleton-row"><div class="skeleton-cell"></div><div class="skeleton-cell"></div><div class="skeleton-cell"></div><div class="skeleton-cell"></div></div>
                        <div class="skeleton-row"><div class="skeleton-cell"></div><div class="skeleton-cell"></div><div class="skeleton-cell"></div><div class="skeleton-cell"></div></div>
                    </div>
                </div>

                <!-- empty state mount -->
                <div id="emptyState" style="display: none;"></div>

                <div id="tableWrapper" style="display: none;">
                    <div id="vehiclesTable"></div>
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
    <!-- Vehicle Modal -->
    <div class="modal-overlay" id="vehicleModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-car"></i> Add Vehicle</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="vehicleForm">
                    <input type="hidden" id="vehicleId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-hashtag"></i> Vehicle Number *</label>
                            <input type="text" id="vehicleNumber" name="vehicle_number" required maxlength="20" placeholder="e.g. MH01AB1234">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-car-side"></i> Vehicle Category *</label>
                            <select id="vehicleType" name="vehicle_type" required class="searchable">
                                <option value="">Select Category</option>
                                <?php echo vehicleCategoryOptionsHtml(); ?>
                            </select>
                            <button type="button" class="btn-link-add" onclick="quickAddCategory('vehicleType')"><i class="fas fa-plus"></i> New category</button>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-industry"></i> Make</label>
                            <input type="text" id="vehicleMake" name="make" maxlength="100" placeholder="e.g. Maruti, Hyundai">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-car"></i> Model</label>
                            <input type="text" id="vehicleModel" name="model" maxlength="100" placeholder="e.g. Swift, i20">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Year of Manufacture</label>
                            <input type="number" id="vehicleYear" name="year_of_manufacture" min="1990" max="2030" placeholder="e.g. 2023">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-gas-pump"></i> Fuel Type</label>
                            <select id="vehicleFuelType" name="fuel_type" class="searchable">
                                <option value="">Select Fuel</option>
                                <option value="Petrol">Petrol</option>
                                <option value="Diesel">Diesel</option>
                                <option value="EV">EV</option>
                                <option value="CNG">CNG</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Customer *</label>
                            <select id="vehicleCustomer" name="customer_id" required class="searchable">
                                <option value="">Select Customer</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-barcode"></i> Chassis Number</label>
                            <input type="text" id="vehicleChassis" name="chassis_number" maxlength="30" pattern="[A-Za-z0-9]{6,30}" class="text-uppercase" placeholder="6-30 letters/digits">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-gears"></i> Engine Number</label>
                            <input type="text" id="vehicleEngine" name="engine_number" maxlength="30" pattern="[A-Za-z0-9]{6,30}" class="text-uppercase" placeholder="6-30 letters/digits">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-gauge-high"></i> Cubic Capacity (cc)</label>
                            <input type="number" id="vehicleCc" name="cubic_capacity" min="1" max="30000" step="1" placeholder="e.g. 1197">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-chair"></i> Seating Capacity</label>
                            <input type="number" id="vehicleSeats" name="seating_capacity" min="1" max="255" step="1" placeholder="e.g. 5">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-weight-hanging"></i> GVW (kg)</label>
                            <input type="number" id="vehicleGvw" name="gvw_kg" min="1" max="100000" step="1" placeholder="goods vehicles">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check"></i> Registration Date</label>
                            <input type="date" id="vehicleRegDate" name="registration_date">
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="toggle-switch">
                            <input type="checkbox" class="toggle" id="vehicleIsActive" name="is_active" checked>
                            <label for="vehicleIsActive">Active</label>
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

    <!-- Vehicle Categories Modal -->
    <div class="modal-overlay" id="categoriesModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-tags"></i> Vehicle Categories</h3>
                <button class="close-btn" onclick="closeCategoriesModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <p class="bulk-hint"><i class="fas fa-info-circle"></i> Add as many categories as you need (e.g. commercial sub-types). Deactivated categories stay on old records but drop out of dropdowns for new entries.</p>
                <div class="cat-add-row">
                    <input type="text" id="newCategoryName" maxlength="60" placeholder="New category name (e.g. Commercial - Truck)">
                    <button type="button" class="btn btn-success btn-sm" onclick="addCategory()"><i class="fas fa-plus"></i> Add</button>
                </div>
                <div class="cat-list-wrap">
                    <table class="cat-list" id="catList">
                        <thead><tr><th>Category</th><th>Status</th><th class="cat-actions-col">Actions</th></tr></thead>
                        <tbody id="catListBody"></tbody>
                    </table>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeCategoriesModal()"><i class="fas fa-times"></i> Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        /** Rameez Scripts */
        const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        const IS_AGENT = <?php echo $isAgent ? 'true' : 'false'; ?>;
        let vehiclesTable = null;
        let vehiclesData = [];
        let customersData = [];
        let isEditMode = false;
        let bulkSel = null;

        $(document).ready(function() {
            loadCustomers();
            loadVehicles();
            // header search
            $('#vehSearch').on('input', function() { clearTimeout(this._t); var v = this.value; this._t = setTimeout(function() { rsTbSearch(vehiclesTable, v); }, 200); });
        });

        // load customers — modal dropdown (admin) + customer filter (all roles)
        function loadCustomers() {
            $.ajax({
                url: '?action=getCustomersList',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (!res.success) return;
                    customersData = res.data;

                    // modal customer dropdown - admin only
                    if (IS_ADMIN) {
                        const sel = $('#vehicleCustomer');
                        sel.find('option:not(:first)').remove();
                        res.data.forEach(function(c) {
                            sel.append('<option value="' + c.id + '">' + escapeHtml(c.name) + ' - ' + escapeHtml(c.mobile) + '</option>');
                        });
                    }

                    // customer filter dropdown
                    const fsel = $('#filterCustomer');
                    fsel.find('option:not(:first)').remove();
                    res.data.forEach(function(c) {
                        fsel.append('<option value="' + escapeHtml(c.name) + '">' + escapeHtml(c.name) + '</option>');
                    });

                    setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
                }
            });
        }

        function loadVehicles() {
            $('#skeletonLoader').show();
            $('#tableWrapper').hide();
            $('#emptyState').hide();

            $.ajax({
                url: '?action=getVehicles',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    $('#skeletonLoader').hide();
                    if (res.success) {
                        vehiclesData = res.data;
                        if (res.data.length === 0) {
                            $('#tableWrapper').hide();
                            $('#emptyState').html(emptyStateHtml({
                                icon: 'fa-car',
                                title: 'No Vehicles Yet',
                                text: IS_AGENT ? 'Vehicles linked to your customers will appear here.' : 'Add your first insured vehicle to get started.',
                                btnText: IS_ADMIN ? 'Add Vehicle' : '',
                                btnId: IS_ADMIN ? 'emptyAddBtn' : ''
                            })).show();
                            if (IS_ADMIN) $('#emptyAddBtn').off('click').on('click', openAddModal);
                            return;
                        }
                        $('#emptyState').hide().empty();
                        $('#tableWrapper').show();
                        initDataTable(res.data);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load vehicles' });
                    }
                },
                error: function() {
                    $('#skeletonLoader').hide();
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
                }
            });
        }

        function initDataTable(data) {
            if (vehiclesTable) return rsTbData(vehiclesTable, data); // build once, swap rows

            let cols = [];
            // bulk checkbox col - admin only
            if (IS_ADMIN) {
                cols.push({ data:'id', title:'<input type="checkbox" class="bulk-check-all">', orderable:false, searchable:false, className:'bulk-col', render:function(d){ return '<input type="checkbox" class="bulk-check" data-id="'+d+'">'; } });
            }
            cols = cols.concat([
                { data: 'id', title: 'ID' },
                {
                    data: 'vehicle_number',
                    title: 'Vehicle Number',
                    render: function(d, type, row) {
                        if (type !== 'display') return d;
                        return '<span class="badge-code">' + escapeHtml(d) + '</span>' + (row.is_active ? '' : ' <span class="status-badge status-inactive">Inactive</span>');
                    }
                },
                { data: 'vehicle_type', title: 'Category', render: function(d) { return escapeHtml(d || '-'); } },
                { data: 'make', title: 'Make', render: function(d) { return escapeHtml(d || '-'); } },
                { data: 'model', title: 'Model', render: function(d) { return escapeHtml(d || '-'); } },
                { data: 'year_of_manufacture', title: 'Year', render: function(d) { return escapeHtml(d || '-'); } },
                { data: 'fuel_type', title: 'Fuel Type', render: function(d) { return escapeHtml(d || '-'); } },
                { data: 'customer_name', title: 'Customer', render: function(d) { return escapeHtml(d || '-'); } },
                { data: 'chassis_number', title: 'Chassis No', visible: false, render: function(d) { return escapeHtml(d || '-'); } },
                { data: 'engine_number', title: 'Engine No', visible: false, render: function(d) { return escapeHtml(d || '-'); } },
                { data: 'cubic_capacity', title: 'CC', visible: false, render: function(d) { return escapeHtml(d || '-'); } },
                { data: 'seating_capacity', title: 'Seats', visible: false, render: function(d) { return escapeHtml(d || '-'); } },
                { data: 'gvw_kg', title: 'GVW (kg)', visible: false, render: function(d) { return escapeHtml(d || '-'); } },
                { data: 'registration_date', title: 'Reg. Date', visible: false, render: function(d) { return escapeHtml(d || '-'); } },
                { data: 'is_active', title: 'Status', visible: false, render: function(d) { return d ? 'Active' : 'Inactive'; } },
                {
                    data: 'policy_count',
                    title: 'Policies',
                    className: 'points-col',
                    render: function(d) {
                        return d ? '<span class="badge-code">' + d + '</span>' : '0';
                    }
                },
                {
                    data: null,
                    name: 'latest_expiry',
                    title: 'Latest Policy',
                    render: function(d, type, row) {
                        if (!row.latest_status) return '<span class="status-badge status-inactive">No Policy</span>';
                        if (type !== 'display') return row.latest_status + ' ' + (row.latest_expiry || ''); // export/search text
                        var cls = row.latest_status === 'active' ? 'status-active' : 'status-inactive';
                        return '<span class="status-badge ' + cls + '">' + escapeHtml(row.latest_status) + '</span>'
                             + '<br><small>' + escapeHtml(row.latest_expiry) + '</small>';
                    }
                }
            ]);

            // actions col - action hub: view + policies link, edit/delete in ⋮ menu (admin)
            cols.push({
                data: null,
                title: 'Actions',
                orderable: false,
                width: '140', // 3 icons
                render: function(d, type, row) {
                    var html = '<div class="action-hub">';
                    html += '<button class="action-icon view-icon" data-act="view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button>';
                    html += '<button class="action-icon view-icon" data-act="policies" data-id="' + row.id + '" title="Policies"><i class="fas fa-file-contract"></i></button>';
                    if (IS_ADMIN) {
                        html += '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button>';
                        html += '<div class="more-dropdown">';
                        html += '<button class="more-item" data-act="edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> Edit</button>';
                        html += '<div class="more-divider"></div>';
                        html += '<button class="more-item danger" data-act="delete" data-id="' + row.id + '"><i class="fas fa-trash"></i> Delete</button>';
                        html += '</div></div>';
                    }
                    html += '</div>';
                    return html;
                }
            });

            vehiclesTable = rsTable('#vehiclesTable', {
                columns: cols, data: data, title: 'Vehicles', file: 'vehicles', sort: ['id', 'desc'],
                rowClass: function(r) { return r.has_active ? '' : 'dt-row-danger'; }, // no active policy
                info: function() { return [['Category', $('#filterVehicleType').val() || 'All'], ['Fuel', $('#filterFuelType').val() || 'All'], ['Status', $('#filterVehStatus option:selected').val() ? $('#filterVehStatus option:selected').text() : 'All'], ['Customer', $('#filterCustomer').val() || 'All']]; }
            });

            // bulk row-select - admin only
            if (IS_ADMIN) {
                bulkSel = initBulkSelect({
                    table: vehiclesTable,
                    label: 'vehicles',
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
                                    loadVehicles();
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
            $('#filterVehicleType, #filterFuelType, #filterVehStatus, #filterCustomer').off('change').on('change', applyFilters);
            rsTbSearch(vehiclesTable, $('#vehSearch').val()); // keep header search

            // one delegated handler for the whole action hub
            $('#vehiclesTable').off('click', '[data-act]').on('click', '[data-act]', function() {
                var id = parseInt($(this).data('id'), 10);
                var act = $(this).data('act');
                var row = vehiclesData.find(function(r) { return r.id === id; });
                if (act === 'policies') { window.location.href = 'policy_entries.php?vehicle_id=' + id; return; }
                if (!row) return;
                if (act === 'view') viewRecord(row);
                else if (act === 'edit' && IS_ADMIN) editVehicle(row);
                else if (act === 'delete' && IS_ADMIN) deleteVehicle(id);
            });
            applyFilters();
        }

        function applyFilters() {
            const vType = $('#filterVehicleType').val(), fType = $('#filterFuelType').val(), cust = $('#filterCustomer').val();
            rsTbWhere(vehiclesTable, 'type', vType ? function(r) { return r.vehicle_type === vType; } : null);
            rsTbWhere(vehiclesTable, 'fuel', fType ? function(r) { return r.fuel_type === fType; } : null);
            const vSt = $('#filterVehStatus').val();
            rsTbWhere(vehiclesTable, 'status', vSt !== '' ? function(r) { return String(r.is_active) === vSt; } : null);
            rsTbWhere(vehiclesTable, 'cust', cust ? function(r) { return r.customer_name === cust; } : null);
        }

        function clearFilters() {
            $('#filterVehicleType').val('');
            $('#filterFuelType').val('');
            $('#filterVehStatus').val('');
            $('#filterCustomer').val('');
            $('#vehSearch').val('');
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
            rsTbClear(vehiclesTable);
        }

        // tabbed view modal - details / policies+claims / owner
        function viewRecord(d) {
            // details pane
            var details = '<table class="view-detail-table">';
            details += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">' + d.id + '</td>';
            details += '<td class="vd-label"><i class="fas fa-car"></i> Vehicle #</td><td class="vd-value"><span class="badge-code">' + escapeHtml(d.vehicle_number || '-') + '</span></td></tr>';
            details += '<tr><td class="vd-label"><i class="fas fa-car-side"></i> Category</td><td class="vd-value">' + escapeHtml(d.vehicle_type || '-') + '</td>';
            details += '<td class="vd-label"><i class="fas fa-industry"></i> Make</td><td class="vd-value">' + escapeHtml(d.make || '-') + '</td></tr>';
            details += '<tr><td class="vd-label"><i class="fas fa-cog"></i> Model</td><td class="vd-value">' + escapeHtml(d.model || '-') + '</td>';
            details += '<td class="vd-label"><i class="fas fa-calendar"></i> Year</td><td class="vd-value">' + escapeHtml(d.year_of_manufacture || '-') + '</td></tr>';
            details += '<tr><td class="vd-label"><i class="fas fa-gas-pump"></i> Fuel Type</td><td class="vd-value">' + escapeHtml(d.fuel_type || '-') + '</td>';
            details += '<td class="vd-label"><i class="fas fa-file-contract"></i> Policies</td><td class="vd-value">' + (d.policy_count || 0) + '</td></tr>';
            details += '<tr><td class="vd-label"><i class="fas fa-barcode"></i> Chassis No</td><td class="vd-value">' + escapeHtml(d.chassis_number || '-') + '</td>';
            details += '<td class="vd-label"><i class="fas fa-gears"></i> Engine No</td><td class="vd-value">' + escapeHtml(d.engine_number || '-') + '</td></tr>';
            details += '<tr><td class="vd-label"><i class="fas fa-gauge-high"></i> Cubic Capacity</td><td class="vd-value">' + (d.cubic_capacity ? escapeHtml(d.cubic_capacity) + ' cc' : '-') + '</td>';
            details += '<td class="vd-label"><i class="fas fa-chair"></i> Seating</td><td class="vd-value">' + escapeHtml(d.seating_capacity || '-') + '</td></tr>';
            details += '<tr><td class="vd-label"><i class="fas fa-weight-hanging"></i> GVW</td><td class="vd-value">' + (d.gvw_kg ? escapeHtml(d.gvw_kg) + ' kg' : '-') + '</td>';
            details += '<td class="vd-label"><i class="fas fa-calendar-check"></i> Reg. Date</td><td class="vd-value">' + escapeHtml(d.registration_date || '-') + '</td></tr>';
            details += '<tr><td class="vd-label"><i class="fas fa-toggle-on"></i> Status</td><td class="vd-value" colspan="3">' + (d.is_active ? '<span class="status-badge status-active">Active</span>' : '<span class="status-badge status-inactive">Inactive</span>') + '</td></tr>';
            details += '</table>';

            // owner pane
            var owner = '<table class="view-detail-table">';
            owner += '<tr><td class="vd-label"><i class="fas fa-user"></i> Customer</td><td class="vd-value">' + escapeHtml(d.customer_name || '-') + '</td>';
            owner += '<td class="vd-label"><i class="fas fa-phone"></i> Mobile</td><td class="vd-value">' + escapeHtml(d.customer_mobile || '-') + '</td></tr>';
            owner += '</table>';

            var html = '<div class="tab-nav">';
            html += '<button class="tab-btn active" data-tab="details"><i class="fas fa-car"></i> Details</button>';
            html += '<button class="tab-btn" data-tab="policies"><i class="fas fa-file-contract"></i> Policies / Claims</button>';
            html += '<button class="tab-btn" data-tab="owner"><i class="fas fa-user"></i> Owner</button>';
            html += '</div>';
            html += '<div class="tab-content active" data-tab="details">' + details + '</div>';
            html += '<div class="tab-content" data-tab="policies"><div class="doc-panel">'
                + '<div class="doc-head"><span><i class="fas fa-file-contract"></i> Policies</span><em id="vehPolCount"></em></div><div class="tab-table" id="vehPolTbl"></div>'
                + '<div class="doc-head"><span><i class="fas fa-car-burst"></i> Claims</span><em id="vehClaimCount"></em></div><div class="tab-table" id="vehClaimTbl"></div>'
                + '</div></div>';
            html += '<div class="tab-content" data-tab="owner">' + owner + '</div>';

            document.getElementById('viewModalTitle').innerHTML = '<i class="fas fa-eye"></i> Vehicle Details';
            document.getElementById('viewModalBody').innerHTML = html;
            document.getElementById('viewModal').classList.add('active');
            initModalTabs('#viewModalBody');

            loadVehiclePolicies(d.id);
            loadVehicleClaims(d.id);
        }

        // policies / claims tab -> small kit tables, built when the tab shows
        var VEH_CLAIM_ST = { intimated: ['Intimated', 'status-user'], survey: ['Survey', 'status-pending'], approved: ['Approved', 'status-admin'], settled: ['Settled', 'status-active'], rejected: ['Rejected', 'status-cancelled'], withdrawn: ['Withdrawn', 'status-inactive'] };
        function vehMoney(v) { return v == null || v === '' ? '-' : (RS_BRAND.sym || '') + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
        function vehAmtCol(field, title) { return { data: field, title: title, render: function(d, t) { return t === 'export' ? (d == null || d === '' ? '' : +d) : escapeHtml(vehMoney(d)); } }; }

        function loadVehiclePolicies(vid) {
            var show = function(rows, empty) {
                $('#vehPolCount').text(rows.length || '');
                rsTabTable('#vehPolTbl', {
                    data: rows, title: 'Vehicle Policies', file: 'vehicle_policies', sort: ['entry_date', 'desc'], empty: empty, opts: { paginationSize: 5 },
                    columns: [
                        { data: 'entry_date', title: 'Date' },
                        { data: 'policy_number', title: 'Policy No', render: function(d, t, p) { return t === 'display' ? '<a href="policy_entries.php?id=' + parseInt(p.id, 10) + '">' + escapeHtml(d) + '</a>' : d; } },
                        vehAmtCol('premium', 'Premium'),
                        { data: 'status', title: 'Status', render: function(d, t) { var s = String(d || ''); return t === 'display' ? '<span class="status-badge ' + (s === 'active' ? 'status-active' : 'status-inactive') + '">' + escapeHtml(s) + '</span>' : s; } },
                        { data: 'expiry', title: 'Expiry' }
                    ]
                });
            };
            $.getJSON('?action=getVehiclePolicies&id=' + vid)
                .done(function(res) { show(res.success ? res.data : [], res.success ? 'No policy history for this vehicle' : 'Policies could not be loaded'); })
                .fail(function() { show([], 'Policies could not be loaded'); });
        }

        // claims on this vehicle (claims.php owns them, agent-scoped there)
        function loadVehicleClaims(vid) {
            var show = function(rows, empty) {
                $('#vehClaimCount').text(rows.length || '');
                rsTabTable('#vehClaimTbl', {
                    data: rows, title: 'Vehicle Claims', file: 'vehicle_claims', sort: ['intimation_date', 'desc'], empty: empty, opts: { paginationSize: 5 },
                    columns: [
                        { data: 'claim_number', title: 'Claim No', render: function(d, t, c) { return t === 'display' ? '<a href="claims.php?id=' + parseInt(c.id, 10) + '">' + escapeHtml(d) + '</a>' : d; } },
                        { data: 'policy_number', title: 'Policy No' },
                        { data: 'claim_type_label', title: 'Type' },
                        { data: 'intimation_date', title: 'Intimated' },
                        { data: 'status', title: 'Status', render: function(d, t) { var s = VEH_CLAIM_ST[d] || [String(d || ''), 'status-user']; return t === 'display' ? '<span class="status-badge ' + s[1] + '">' + escapeHtml(s[0]) + '</span>' : s[0]; } },
                        vehAmtCol('amount_claimed', 'Claimed'),
                        vehAmtCol('amount_settled', 'Settled')
                    ]
                });
            };
            $.getJSON('claims.php?action=getClaims&vehicle_id=' + vid)
                .done(function(res) { show(res && res.success ? res.data : [], res && res.success ? 'No claims on this vehicle' : 'Claims could not be loaded'); })
                .fail(function() { show([], 'Claims could not be loaded'); });
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
            $('#modalTitle').html('<i class="fas fa-car"></i> Add Vehicle');
            $('#vehicleForm')[0].reset();
            $('#vehicleId').val('');
            $('#vehicleIsActive').prop('checked', true);
            $('#vehicleModal').addClass('active');
        }

        function editVehicle(v) {
            isEditMode = true;
            $('#modalTitle').html('<i class="fas fa-edit"></i> Edit Vehicle');
            $('#vehicleId').val(v.id);
            $('#vehicleNumber').val(v.vehicle_number);
            $('#vehicleType').val(v.vehicle_type);
            $('#vehicleMake').val(v.make || '');
            $('#vehicleModel').val(v.model || '');
            $('#vehicleYear').val(v.year_of_manufacture || '');
            $('#vehicleFuelType').val(v.fuel_type || '');
            $('#vehicleCustomer').val(v.customer_id);
            $('#vehicleChassis').val(v.chassis_number || '');
            $('#vehicleEngine').val(v.engine_number || '');
            $('#vehicleCc').val(v.cubic_capacity || '');
            $('#vehicleSeats').val(v.seating_capacity || '');
            $('#vehicleGvw').val(v.gvw_kg || '');
            $('#vehicleRegDate').val(v.registration_date || '');
            $('#vehicleIsActive').prop('checked', !!v.is_active);
            $('#vehicleModal').addClass('active');
            // refresh searchable display
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
        }

        function closeModal() {
            $('#vehicleModal').removeClass('active');
            $('#vehicleForm')[0].reset();
        }

        // close on overlay click
        document.getElementById('vehicleModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // form submit
        $('#vehicleForm').on('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const action = isEditMode ? 'updateVehicle' : 'addVehicle';
            formData.append('csrf_token', window.CSRF_TOKEN);

            showLoading('Processing...');

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
                        setTimeout(loadVehicles, 100);
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

        function deleteVehicle(vid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Vehicle?',
                text: 'This action cannot be undone',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) return;

                const fd = new FormData();
                fd.append('id', vid);
                fd.append('csrf_token', window.CSRF_TOKEN);

                $.ajax({
                    url: '?action=deleteVehicle',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                            setTimeout(loadVehicles, 100);
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
        <?php if ($isAdmin): ?>
        // === vehicle categories management ===
        function openCategoriesModal() { $('#categoriesModal').addClass('active'); loadCategories(); }
        function closeCategoriesModal() { $('#categoriesModal').removeClass('active'); loadVehicles(); }
        document.getElementById('categoriesModal').addEventListener('click', function(e){ if (e.target === this) closeCategoriesModal(); });

        function loadCategories() {
            $.ajax({ url:'?action=getCategories', method:'GET', dataType:'json', success:function(res){
                var body = document.getElementById('catListBody');
                body.innerHTML = '';
                if (!res.success || !res.data) return;
                res.data.forEach(function(c){
                    var tr = document.createElement('tr');
                    var badge = c.is_active ? '<span class="status-badge status-active">Active</span>' : '<span class="status-badge status-inactive">Inactive</span>';
                    var toggleLbl = c.is_active ? 'Deactivate' : 'Activate';
                    var toggleIcon = c.is_active ? 'fa-ban' : 'fa-check';
                    tr.innerHTML =
                        '<td><input type="text" class="cat-name-inp" value="' + escapeHtml(c.name) + '" maxlength="60"></td>' +
                        '<td>' + badge + '</td>' +
                        '<td class="cat-actions-col">' +
                          '<button type="button" class="btn btn-primary btn-xs" title="Save name" onclick="saveCategory(' + c.id + ', this)"><i class="fas fa-save"></i></button> ' +
                          '<button type="button" class="btn btn-secondary btn-xs" onclick="toggleCategory(' + c.id + ',' + (c.is_active ? 0 : 1) + ')"><i class="fas ' + toggleIcon + '"></i> ' + toggleLbl + '</button>' +
                        '</td>';
                    body.appendChild(tr);
                });
            }});
        }
        function catPost(action, data) {
            data.csrf_token = window.CSRF_TOKEN;
            showLoading('Saving...');
            $.ajax({ url:'?action='+action, method:'POST', data:data, dataType:'json',
                success:function(res){ hideLoading(); if(res.success){ loadCategories(); } else { Swal.fire({icon:'error', title:'Error', text:res.message}); } },
                error:function(){ hideLoading(); Swal.fire({icon:'error', title:'Error', text:'Connection error'}); }
            });
        }
        function addCategory() {
            var name = ($('#newCategoryName').val()||'').trim();
            if (!name) { Swal.fire({icon:'info', text:'Enter a category name'}); return; }
            $('#newCategoryName').val('');
            catPost('addCategory', {name:name});
        }
        function saveCategory(id, btn) {
            var name = ($(btn).closest('tr').find('.cat-name-inp').val()||'').trim();
            if (!name) { Swal.fire({icon:'info', text:'Name cannot be empty'}); return; }
            catPost('updateCategory', {id:id, name:name});
        }
        function toggleCategory(id, active) { catPost('toggleCategory', {id:id, is_active:active}); }

        // quick-add a category from inside the Add/Edit Vehicle form, then select it
        function quickAddCategory(selectId) {
            Swal.fire({
                title: 'New Vehicle Category',
                input: 'text',
                inputPlaceholder: 'e.g. Commercial - Truck',
                showCancelButton: true,
                confirmButtonText: 'Add',
                inputValidator: function(v){ if (!v || !v.trim()) return 'Enter a category name'; }
            }).then(function(res){
                if (!res.isConfirmed) return;
                var name = res.value.trim();
                showLoading('Adding...');
                $.ajax({
                    url: 'vehicles.php?action=addCategory', method: 'POST',
                    data: { name: name, csrf_token: window.CSRF_TOKEN }, dataType: 'json',
                    success: function(r){
                        hideLoading();
                        if (!r.success) { Swal.fire({icon:'error', title:'Error', text:r.message}); return; }
                        var sel = document.getElementById(selectId);
                        if (sel && !Array.from(sel.options).some(function(o){ return o.value === name; })) sel.add(new Option(name, name, true, true));
                        else if (sel) sel.value = name;
                        if (sel && sel.sdRefresh) sel.sdRefresh();
                        Swal.fire({icon:'success', text:'Category added', timer:1200, showConfirmButton:false});
                    },
                    error: function(){ hideLoading(); Swal.fire({icon:'error', title:'Error', text:'Connection error'}); }
                });
            });
        }

        // bulk add / excel import — engine lives in sidebar.php
        var VEH_CATS = <?php echo json_encode(getVehicleCategories()); ?>;
        var vehiclesImport = RSImport({
            key: 'vehicles', title: 'Bulk Add / Import Vehicles', url: '?action=bulkAddVehicles',
            hint: 'Add many vehicles at once — upload an Excel/CSV file or type the rows. <strong>Vehicle number</strong>, <strong>category</strong> and an <strong>owner</strong> are required. The owner is matched on <strong>mobile</strong> first, then on an exact customer name; if the mobile is new and a name is given, the customer is created automatically (same as the policy upload).',
            onDone: function() { setTimeout(loadVehicles, 100); },
            fields: [
                { key: 'vehicle_number', label: 'Vehicle No *', cls: 'bg-vno', req: true, max: 20, ph: 'MH01AB1234', aliases: ['vehiclenumber', 'vehicleno', 'regno', 'registrationnumber', 'registrationno', 'number', 'vehicle'], demo: 'MH01AB1234', demo2: 'MH02CD5678' },
                { key: 'vehicle_type', label: 'Category *', cls: 'bg-vtype', type: 'select', req: true,
                  opts: function() { return [{ v: '', t: '— Select —' }].concat(VEH_CATS.map(function(c) { return { v: c, t: c }; })); },
                  aliases: ['vehicletype', 'category', 'type', 'vehiclecategory'], demo: (VEH_CATS[0] || 'Car'), demo2: (VEH_CATS[0] || 'Car') },
                { key: 'make', label: 'Make', cls: 'bg-make', max: 100, ph: 'Maruti', aliases: ['make', 'brand', 'manufacturer', 'company'], demo: 'Maruti', demo2: 'Hyundai' },
                { key: 'model', label: 'Model', cls: 'bg-model', max: 100, ph: 'Swift', aliases: ['model', 'variant', 'modelname'], demo: 'Swift', demo2: 'i20' },
                { key: 'year_of_manufacture', label: 'Year', cls: 'bg-year', type: 'number', ph: '2023', aliases: ['yearofmanufacture', 'year', 'mfgyear', 'manufactureyear', 'modelyear'], demo: 2023, demo2: 2021 },
                { key: 'fuel_type', label: 'Fuel', cls: 'bg-fuel', type: 'select',
                  opts: [{ v: '', t: '— None —' }, { v: 'Petrol', t: 'Petrol' }, { v: 'Diesel', t: 'Diesel' }, { v: 'EV', t: 'EV' }, { v: 'CNG', t: 'CNG' }],
                  aliases: ['fueltype', 'fuel'], demo: 'Petrol', demo2: 'Diesel' },
                { key: 'chassis_number', label: 'Chassis No', cls: 'bg-chassis', max: 30, ph: '6-30 alnum', aliases: ['chassisnumber', 'chassisno', 'chassis', 'vin'], demo: 'MA3EWDE1S00123456', demo2: 'MALBB51BLDM123457' },
                { key: 'engine_number', label: 'Engine No', cls: 'bg-engine', max: 30, ph: '6-30 alnum', aliases: ['enginenumber', 'engineno', 'engine'], demo: 'K12MN1234567', demo2: 'G4LAEM123458' },
                { key: 'cubic_capacity', label: 'CC', cls: 'bg-cc', type: 'number', ph: '1197', aliases: ['cubiccapacity', 'cc', 'enginecc'], demo: 1197, demo2: 1197 },
                { key: 'seating_capacity', label: 'Seats', cls: 'bg-seats', type: 'number', ph: '5', aliases: ['seatingcapacity', 'seats', 'seating'], demo: 5, demo2: 5 },
                { key: 'gvw_kg', label: 'GVW kg', cls: 'bg-gvw', type: 'number', ph: 'goods only', aliases: ['gvwkg', 'gvw', 'grossvehicleweight'], demo: '', demo2: '' },
                { key: 'registration_date', label: 'Reg. Date', cls: 'bg-regdate', type: 'date', aliases: ['registrationdate', 'regdate', 'dateofregistration'], demo: '2023-04-12', demo2: '2021-08-03' },
                { key: 'customer_mobile', label: 'Owner Mobile *', cls: 'bg-cmob', req: true, max: 15, digits: true, ph: '10-15 digits', aliases: ['customermobile', 'ownermobile', 'mobile', 'phone', 'phoneno', 'contactno', 'customerphone'], demo: '9876543210', demo2: '9876543211' },
                { key: 'customer_name', label: 'Owner Name', cls: 'bg-cname', max: 150, ph: 'creates if new', aliases: ['customername', 'ownername', 'customer', 'owner', 'name'], demo: 'Demo Customer One', demo2: 'Demo Customer Two' }
            ]
        });
        <?php endif; ?>
    </script>
</body>
</html>
