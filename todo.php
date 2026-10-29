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
$current_page = 'todo';

// agent cant access
if ($role === 'agent') { header("Location: dashboard.php"); exit(); }

// ensure todos table
$conn = getDBConnection();
$conn->query("CREATE TABLE IF NOT EXISTS todos (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
    status ENUM('pending','in_progress','completed') DEFAULT 'pending',
    due_date DATE NULL,
    assigned_to BIGINT NULL,
    created_by BIGINT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$conn->close();

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getUsers':
                $stmt = $conn->prepare("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name ASC");
                $stmt->execute();
                $res = $stmt->get_result();
                $users = [];
                while ($row = $res->fetch_assoc()) {
                    $users[] = ['id' => (int)$row['id'], 'name' => $row['name']];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $users]);
                exit();

            case 'getPolicies':
                // related-policy picker - latest 500 keeps it light
                $res = $conn->query("SELECT id, policy_number, customer_name FROM policy_entries WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 500");
                $pols = [];
                while ($r = $res->fetch_assoc()) $pols[] = ['id' => (int)$r['id'], 'policy_number' => $r['policy_number'], 'customer_name' => $r['customer_name']];
                $conn->close();
                echo json_encode(['success' => true, 'data' => $pols]);
                exit();

            case 'getTodos':
                $sql = "SELECT t.*, u.name as assigned_name, c.name as creator_name, p.policy_number, p.customer_name as policy_customer
                        FROM todos t
                        LEFT JOIN users u ON t.assigned_to = u.id
                        LEFT JOIN users c ON t.created_by = c.id
                        LEFT JOIN policy_entries p ON t.policy_entry_id = p.id";

                // manager sees own tasks only
                if ($role === 'manager') {
                    $sql .= " WHERE t.created_by = ?";
                }

                $sql .= " ORDER BY FIELD(t.priority,'urgent','high','medium','low'), t.due_date ASC";

                $stmt = $conn->prepare($sql);
                if ($role === 'manager') {
                    $stmt->bind_param("i", $user_id);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $todos = [];
                while ($row = $res->fetch_assoc()) {
                    $todos[] = [
                        'id' => (int)$row['id'],
                        'title' => $row['title'],
                        'description' => $row['description'],
                        'priority' => $row['priority'],
                        'status' => $row['status'],
                        'due_date' => $row['due_date'],
                        'assigned_to' => $row['assigned_to'] ? (int)$row['assigned_to'] : null,
                        'assigned_name' => $row['assigned_name'],
                        'creator_name' => $row['creator_name'],
                        'created_by' => (int)$row['created_by'],
                        'created_at' => $row['created_at'],
                        'updated_at' => $row['updated_at'],
                        'completed_at' => $row['completed_at'],
                        'policy_entry_id' => $row['policy_entry_id'] ? (int)$row['policy_entry_id'] : null,
                        'policy_number' => $row['policy_number'],
                        'policy_customer' => $row['policy_customer']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $todos]);
                exit();

            case 'bulkAddTodos':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid request method']); exit(); }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin' && $role !== 'manager') { echo json_encode(['success'=>false,'message'=>'Access denied']); exit(); }

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows) || count($rows) === 0) { echo json_encode(['success'=>false,'message'=>'No tasks to add']); exit(); }
                $off = max(0, (int)($_POST['row_offset'] ?? 0)); // client posts in chunks - keep row numbers global

                // assignee by name or email, case-insensitive
                $userMap = []; $res = $conn->query("SELECT id, name, email FROM users WHERE is_active = 1");
                while ($u = $res->fetch_assoc()) {
                    $uid = (int)$u['id'];
                    $userMap[strtolower(trim($u['name']))] = $uid;
                    $userMap[strtolower(trim($u['email']))] = $uid;
                }

                $inserted = 0; $errors = [];
                foreach ($rows as $idx => $r) {
                    $line = $off + $idx + 1;
                    $title = trim($r['title'] ?? '');
                    $desc = trim($r['description'] ?? '');
                    $prio = strtolower(trim($r['priority'] ?? '')) ?: 'medium';
                    $status = strtolower(trim($r['status'] ?? '')) ?: 'pending';
                    $due = trim($r['due_date'] ?? '');
                    $assignRaw = trim($r['assigned_to'] ?? '');
                    $polRaw = trim($r['policy_number'] ?? '');

                    if ($title === '') continue; // trailing blank line

                    if (strlen($title) < 2 || strlen($title) > 255) { $errors[] = "Row $line: title must be 2-255 characters"; continue; }
                    if (!in_array($prio, ['low', 'medium', 'high', 'urgent'], true)) { $errors[] = "Row $line: invalid priority '$prio'"; continue; }
                    if (!in_array($status, ['pending', 'in_progress', 'completed'], true)) { $errors[] = "Row $line: invalid status '$status'"; continue; }
                    if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) { $errors[] = "Row $line: due date must be YYYY-MM-DD"; continue; }

                    $assignedTo = null;
                    if ($assignRaw !== '') {
                        if (!isset($userMap[strtolower($assignRaw)])) { $errors[] = "Row $line: user '$assignRaw' not found"; continue; }
                        $assignedTo = $userMap[strtolower($assignRaw)];
                    }

                    $polId = null;
                    if ($polRaw !== '') { // match policy no
                        $ps = $conn->prepare("SELECT id FROM policy_entries WHERE policy_number = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
                        $ps->bind_param("s", $polRaw); $ps->execute(); $pr = $ps->get_result()->fetch_assoc(); $ps->close();
                        if (!$pr) { $errors[] = "Row $line: policy '$polRaw' not found"; continue; }
                        $polId = (int)$pr['id'];
                    }

                    $descVal = $desc ?: null; $dueVal = $due ?: null;
                    $stmt = $conn->prepare("INSERT INTO todos (title, description, priority, status, due_date, assigned_to, created_by, policy_entry_id, completed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, IF(? = 'completed', NOW(), NULL))");
                    $stmt->bind_param("sssssiiis", $title, $descVal, $prio, $status, $dueVal, $assignedTo, $user_id, $polId, $status);
                    if ($stmt->execute()) $inserted++;
                    else $errors[] = "Row $line: failed to add '$title'";
                    $stmt->close();
                }

                logActivity($user_id, $username, 'Bulk Task Add', "Added $inserted tasks, " . count($errors) . " errors");
                $conn->close();
                echo json_encode(['success'=>true, 'message'=>"$inserted task(s) added.", 'inserted'=>$inserted, 'errors'=>$errors]);
                exit();

            case 'addTodo':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }

                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $priority = trim($_POST['priority'] ?? 'medium');
                $status = trim($_POST['status'] ?? 'pending');
                $due_date = trim($_POST['due_date'] ?? '');
                $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
                $policyId = !empty($_POST['policy_entry_id']) ? (int)$_POST['policy_entry_id'] : null;

                if (strlen($title) < 2 || strlen($title) > 255) {
                    echo json_encode(['success' => false, 'message' => 'Title must be 2-255 characters']);
                    exit();
                }

                $validPriorities = ['low', 'medium', 'high', 'urgent'];
                if (!in_array($priority, $validPriorities)) $priority = 'medium';

                $validStatuses = ['pending', 'in_progress', 'completed'];
                if (!in_array($status, $validStatuses)) $status = 'pending';

                $dueDateVal = !empty($due_date) ? $due_date : null;
                $descVal = !empty($description) ? $description : null;

                if ($policyId) { // linked policy must exist
                    $ps = $conn->prepare("SELECT policy_number FROM policy_entries WHERE id = ? AND deleted_at IS NULL");
                    $ps->bind_param("i", $policyId); $ps->execute(); $polRow = $ps->get_result()->fetch_assoc(); $ps->close();
                    if (!$polRow) { $conn->close(); echo json_encode(['success' => false, 'message' => 'Related policy not found']); exit(); }
                }
                $polTxt = $policyId ? ", policy {$polRow['policy_number']}" : '';

                $stmt = $conn->prepare("INSERT INTO todos (title, description, priority, status, due_date, assigned_to, created_by, policy_entry_id, completed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, IF(? = 'completed', NOW(), NULL))");
                $stmt->bind_param("sssssiiis", $title, $descVal, $priority, $status, $dueDateVal, $assigned_to, $user_id, $policyId, $status);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add task']);
                    exit();
                }
                $insertId = $stmt->insert_id;
                $stmt->close();

                logActivity($user_id, $username, 'Todo Created', "Created task: $title ($status$polTxt)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Task added successfully', 'data' => ['id' => $insertId]]);
                exit();

            case 'updateTodo':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }

                $tid = (int)($_POST['id'] ?? 0);
                if ($tid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid task ID']);
                    exit();
                }

                // manager can only edit own tasks
                if ($role === 'manager') {
                    $chk = $conn->prepare("SELECT created_by FROM todos WHERE id = ?");
                    $chk->bind_param("i", $tid);
                    $chk->execute();
                    $chkRow = $chk->get_result()->fetch_assoc();
                    $chk->close();
                    if (!$chkRow || (int)$chkRow['created_by'] !== $user_id) {
                        echo json_encode(['success' => false, 'message' => 'Access denied']);
                        exit();
                    }
                }

                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $priority = trim($_POST['priority'] ?? 'medium');
                $status = trim($_POST['status'] ?? 'pending');
                $due_date = trim($_POST['due_date'] ?? '');
                $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
                $policyId = !empty($_POST['policy_entry_id']) ? (int)$_POST['policy_entry_id'] : null;

                if (strlen($title) < 2 || strlen($title) > 255) {
                    echo json_encode(['success' => false, 'message' => 'Title must be 2-255 characters']);
                    exit();
                }

                $validPriorities = ['low', 'medium', 'high', 'urgent'];
                if (!in_array($priority, $validPriorities)) $priority = 'medium';

                $validStatuses = ['pending', 'in_progress', 'completed'];
                if (!in_array($status, $validStatuses)) $status = 'pending';

                $dueDateVal = !empty($due_date) ? $due_date : null;
                $descVal = !empty($description) ? $description : null;

                if ($policyId) { // linked policy must exist
                    $ps = $conn->prepare("SELECT policy_number FROM policy_entries WHERE id = ? AND deleted_at IS NULL");
                    $ps->bind_param("i", $policyId); $ps->execute(); $polRow = $ps->get_result()->fetch_assoc(); $ps->close();
                    if (!$polRow) { $conn->close(); echo json_encode(['success' => false, 'message' => 'Related policy not found']); exit(); }
                }
                $polTxt = $policyId ? ", policy {$polRow['policy_number']}" : '';

                // keep first completion time, clear on reopen
                $stmt = $conn->prepare("UPDATE todos SET title=?, description=?, priority=?, status=?, due_date=?, assigned_to=?, policy_entry_id=?, completed_at = IF(? = 'completed', COALESCE(completed_at, NOW()), NULL) WHERE id=?");
                $stmt->bind_param("sssssiisi", $title, $descVal, $priority, $status, $dueDateVal, $assigned_to, $policyId, $status, $tid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update task']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Todo Updated', "Updated task #$tid: $title ($status$polTxt)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Task updated successfully']);
                exit();

            case 'toggleTodoStatus':
                // one-click complete / reopen
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }

                $tid = (int)($_POST['id'] ?? 0);
                if ($tid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid task ID']); exit(); }

                $stmt = $conn->prepare("SELECT title, status, created_by FROM todos WHERE id = ?");
                $stmt->bind_param("i", $tid);
                $stmt->execute();
                $tRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$tRow) { $conn->close(); echo json_encode(['success'=>false,'message'=>'Task not found']); exit(); }

                // manager can only toggle own tasks
                if ($role === 'manager' && (int)$tRow['created_by'] !== $user_id) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $newStatus = $tRow['status'] === 'completed' ? 'pending' : 'completed';
                $stmt = $conn->prepare("UPDATE todos SET status = ?, completed_at = IF(? = 'completed', NOW(), NULL) WHERE id = ?");
                $stmt->bind_param("ssi", $newStatus, $newStatus, $tid);
                $stmt->execute();
                $stmt->close();

                logActivity($user_id, $username, 'Todo Updated', "Task #$tid ({$tRow['title']}) set to $newStatus");
                $conn->close();
                echo json_encode(['success' => true, 'message' => $newStatus === 'completed' ? 'Task marked complete' : 'Task reopened', 'status' => $newStatus]);
                exit();

            case 'deleteTodo':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $tid = (int)($_POST['id'] ?? 0);
                if ($tid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid task ID']);
                    exit();
                }

                // get title before delete
                $stmt = $conn->prepare("SELECT title FROM todos WHERE id = ?");
                $stmt->bind_param("i", $tid);
                $stmt->execute();
                $delRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $delTitle = $delRow ? $delRow['title'] : 'Unknown';

                $stmt = $conn->prepare("DELETE FROM todos WHERE id = ?");
                $stmt->bind_param("i", $tid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete task']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Todo Deleted', "Deleted task: $delTitle (#$tid)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Task deleted successfully']);
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
                if (!is_array($ids)) $ids = [];
                $ids = array_values(array_filter(array_map('intval', $ids), function($v){ return $v > 0; }));
                if (!$ids) {
                    echo json_encode(['success' => false, 'message' => 'No tasks selected']);
                    exit();
                }

                $del = $conn->prepare("DELETE FROM todos WHERE id = ?");
                $done = 0;
                foreach ($ids as $tid) {
                    $del->bind_param("i", $tid);
                    if ($del->execute() && $conn->affected_rows > 0) $done++;
                }
                $del->close();

                logActivity($user_id, $username, 'Bulk delete', "Deleted $done task(s)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => "$done record(s) deleted"]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("todo.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
        exit();
    }
}

$isAdmin = ($role === 'admin');
$canEdit = ($role === 'admin' || $role === 'manager');
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
    <title>To Do / Tasks - Insurance Management System</title>

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
            $pageIcon    = 'fa-tasks';
            $pageTitle   = 'To Do / Tasks';
            $crumbs      = [];
            $pageActions = [];
            if ($canEdit) {
                $pageActions[] = ['label' => 'Add Task', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
                $pageActions[] = ['label' => 'Bulk Add / Import', 'icon' => 'fa-file-excel', 'onclick' => 'todosImport.open()'];
            }
            $pageActions[] = ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(todosTable,'csv')"];
            $pageActions[] = ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(todosTable,'pdf')"];
            $pageActions[] = ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(todosTable,'print')"];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadTodos()'];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="todoSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="todoSearch" placeholder="Search tasks…" autocomplete="off"></div>
                <div class="hf-field"><label for="filterPriority" title="Priority"><i class="fas fa-flag"></i></label><select id="filterPriority" class="filter-input searchable">
                    <option value="">All Priorities</option>
                    <option value="urgent">Urgent</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select></div>
                <div class="hf-field"><label for="filterStatus" title="Status"><i class="fas fa-toggle-on"></i></label><select id="filterStatus" class="filter-input searchable">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                </select></div>
                <div class="hf-field hf-range" title="Due date range"><label for="filterDueFrom"><i class="fas fa-calendar-days"></i></label><input type="date" id="filterDueFrom" class="filter-input" aria-label="Due from"><span class="hf-sep"><i class="fas fa-arrow-right-long"></i></span><input type="date" id="filterDueTo" class="filter-input" aria-label="Due to"></div>
                <button type="button" class="btn btn-icon" onclick="clearFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>

            <div class="data-section">
                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- table -->
                <div id="tableContainer" style="display: none;">
                    <div id="todosTable"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Detail Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="viewModalTitle"><i class="fas fa-eye"></i> Task Details</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>

    <?php if ($canEdit): ?>
    <!-- Todo Modal -->
    <div class="modal-overlay" id="todoModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-tasks"></i> Add Task</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="todoForm">
                    <input type="hidden" id="todoId" name="id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-heading"></i> Title *</label>
                            <input type="text" id="todoTitle" name="title" required minlength="2" maxlength="255" placeholder="Task title">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-flag"></i> Priority</label>
                            <select id="todoPriority" name="priority" class="searchable">
                                <option value="medium">Medium</option>
                                <option value="low">Low</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-spinner"></i> Status</label>
                            <select id="todoStatus" name="status" class="searchable">
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar"></i> Due Date</label>
                            <input type="date" id="todoDueDate" name="due_date">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Assigned To</label>
                            <select id="todoAssignedTo" name="assigned_to" class="searchable">
                                <option value="">-- Select User --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-file-shield"></i> Related Policy</label>
                            <select id="todoPolicy" name="policy_entry_id" class="searchable">
                                <option value="">-- None --</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-align-left"></i> Description</label>
                        <textarea id="todoDescription" name="description" rows="3" placeholder="Task description (optional)"></textarea>
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
        /** Rameez Scripts */
        const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        const CAN_EDIT = <?php echo $canEdit ? 'true' : 'false'; ?>;
        const ME_ID = <?php echo (int)$user_id; ?>;
        let todosTable = null;
        let todosData = [];
        let usersData = [];
        let isEditMode = false;

        $(document).ready(function() {
            loadUsers();
            loadTodos();
            if (CAN_EDIT) loadPolicies();
        });

        // related-policy picker (latest 500)
        function polLabel(no, cust) { return (no || '-') + (cust ? ' — ' + cust : ''); }
        function loadPolicies() {
            $.getJSON('?action=getPolicies', function(res) {
                if (!res.success) return;
                var sel = $('#todoPolicy');
                if (!sel.length) return;
                sel.find('option:not(:first)').remove();
                res.data.forEach(function(p) {
                    sel.append('<option value="' + p.id + '">' + escapeHtml(polLabel(p.policy_number, p.customer_name)) + '</option>');
                });
                if (sel[0].sdRefresh) sel[0].sdRefresh();
            });
        }
        function policyLink(r) {
            return r.policy_entry_id ? '<a href="policy_entries.php?id=' + r.policy_entry_id + '" title="Open policy"><span class="badge-code"><i class="fas fa-file-shield"></i> ' + escapeHtml(r.policy_number || ('#' + r.policy_entry_id)) + '</span></a>' : '<em class="text-muted">-</em>';
        }

        // load users for assign dropdown
        function loadUsers() {
            $.ajax({
                url: '?action=getUsers',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (!res.success) return;
                    usersData = res.data;
                    var sel = $('#todoAssignedTo');
                    sel.find('option:not(:first)').remove();
                    res.data.forEach(function(u) {
                        sel.append('<option value="' + u.id + '">' + escapeHtml(u.name) + '</option>');
                    });
                    // refresh searchable dropdown
                    if (sel[0] && sel[0].sdRefresh) sel[0].sdRefresh();
                }
            });
        }

        function loadTodos() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();

            $.ajax({
                url: '?action=getTodos',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        todosData = res.data;
                        if (IS_ADMIN && window.sbPill) sbPill('todo', todosData.filter(function(t) { return t.assigned_to === ME_ID && t.status !== 'completed'; }).length); // admin list = all tasks
                        $('#loadingSkeleton').hide();
                        $('#tableContainer').show();
                        setTimeout(function() { initializeDataTable(res.data); }, 100);
                    } else {
                        $('#loadingSkeleton').hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load tasks' });
                    }
                },
                error: function() {
                    $('#loadingSkeleton').hide();
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
                }
            });
        }

        function priorityBadge(p) {
            return '<span class="priority-badge priority-' + p + '">' + p + '</span>';
        }

        function statusBadge(s) {
            var label = s === 'in_progress' ? 'In Progress' : s.charAt(0).toUpperCase() + s.slice(1);
            return '<span class="todo-status-badge todo-' + s + '">' + label + '</span>';
        }

        function initializeDataTable(data) {
            if (todosTable) return rsTbData(todosTable, data); // build once, swap rows

            var cols = [
                { data:'id', title:'<input type="checkbox" class="bulk-check-all">', orderable:false, searchable:false, className:'bulk-col', render:function(d){ return '<input type="checkbox" class="bulk-check" data-id="'+d+'">'; } },
                { data: 'id', title: 'ID', responsivePriority: window.innerWidth < 480 ? 9 : 1 }, // phone: id folds first
                { data: 'title', title: 'Title', render: function(d) { return escapeHtml(d); } },
                {
                    data: 'priority', title: 'Priority',
                    render: function(d) { return priorityBadge(d); }
                },
                {
                    data: 'status', title: 'Status',
                    render: function(d) { return statusBadge(d); }
                },
                {
                    data: 'due_date', title: 'Due Date',
                    render: function(d) { return d ? escapeHtml(d) : '<em class="text-muted">-</em>'; }
                },
                {
                    data: 'assigned_name', title: 'Assigned To',
                    render: function(d) { return d ? escapeHtml(d) : '<em class="text-muted">-</em>'; }
                },
                {
                    data: 'policy_number', title: 'Policy',
                    render: function(d, type, row) { return type === 'display' ? policyLink(row) : (d || ''); }
                },
                { data: 'creator_name', title: 'Created By', render: function(d) { return escapeHtml(d); } },
                { data: 'completed_at', title: 'Completed At', visible: false }
            ];

            // actions — view + quick toggle for all, more menu for editors
            // native col - 3 icons need more than the kit's default act width
            cols.push({
                title: 'Actions', field: '_act', headerSort: false, download: false, responsive: 0, resizable: false, widthGrow: 0,
                hozAlign: 'right', headerHozAlign: 'right', minWidth: CAN_EDIT ? 170 : 104,
                formatter: function(cell) {
                    var row = cell.getRow().getData();
                    var done = row.status === 'completed';
                    var html = '<div class="action-hub">';
                    html += '<button class="action-icon view-icon" data-act="view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button>';
                    if (CAN_EDIT) {
                        html += '<button class="action-icon" data-act="toggle" data-id="' + row.id + '" title="' + (done ? 'Reopen' : 'Mark Complete') + '"><i class="fas ' + (done ? 'fa-rotate-left' : 'fa-circle-check') + '"></i></button>';
                        html += '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button>';
                        html += '<div class="more-dropdown">';
                        html += '<button class="more-item" data-act="edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> Edit</button>';
                        if (IS_ADMIN) {
                            html += '<div class="more-divider"></div>';
                            html += '<button class="more-item danger" data-act="delete" data-id="' + row.id + '"><i class="fas fa-trash"></i> Delete</button>';
                        }
                        html += '</div></div>';
                    }
                    return html + '</div>';
                }
            });

            todosTable = rsTable('#todosTable', {
                columns: cols, data: data, title: 'To Do Tasks', file: 'tasks', sort: ['id', 'desc'], bulk: IS_ADMIN,
                rowClass: function(r) {
                    var today = new Date(); today.setHours(0, 0, 0, 0);
                    if (r.status === 'completed') return 'dt-row-success'; // completed
                    if (r.due_date && new Date(r.due_date) < today) return 'dt-row-danger'; // overdue
                    if (r.priority === 'high' || r.priority === 'urgent') return 'dt-row-warning'; // high priority, not done
                    return '';
                },
                info: function() { return [['Priority', $('#filterPriority').val() || 'All'], ['Status', $('#filterStatus').val() || 'All']]; }
            });
            rsTbSearch(todosTable, $('#todoSearch').val());

            $('#filterPriority, #filterStatus, #filterDueFrom, #filterDueTo').off('change').on('change', applyFilters);

            // one delegated handler for all row actions
            $('#todosTable').off('click', '[data-act]').on('click', '[data-act]', function() {
                var id = parseInt($(this).data('id'), 10);
                var act = $(this).data('act');
                var row = todosData.find(function(r) { return r.id === id; });
                if (!row) return;
                if (act === 'view') viewRecord(row);
                else if (act === 'toggle' && CAN_EDIT) toggleTodoStatus(row);
                else if (act === 'edit' && CAN_EDIT) editTodo(row);
                else if (act === 'delete' && IS_ADMIN) deleteTodo(id);
            });

            // bulk select (admin)
            if (IS_ADMIN) {
                initBulkSelect({
                    table: todosTable,
                    label: 'tasks',
                    onDelete: function(ids) {
                        showLoading('Deleting...');
                        $.ajax({
                            url: '?action=bulkDelete',
                            method: 'POST',
                            dataType: 'json',
                            data: { csrf_token: window.CSRF_TOKEN, ids: ids },
                            success: function(res) {
                                hideLoading();
                                if (res.success) { Swal.fire('Deleted', res.message, 'success'); loadTodos(); }
                                else { Swal.fire('Error', res.message, 'error'); }
                            },
                            error: function() { hideLoading(); Swal.fire('Error', 'Request failed', 'error'); }
                        });
                    }
                });
            }
            applyFilters();
        }

        function applyFilters() {
            if (!todosTable) return;
            var priority = $('#filterPriority').val(), status = $('#filterStatus').val();
            var dueFrom = $('#filterDueFrom').val(), dueTo = $('#filterDueTo').val();

            // due_date range - no due date excluded when range set
            rsTbWhere(todosTable, 'due', (dueFrom || dueTo) ? function(r) {
                if (!r.due_date) return false;
                var d = new Date(r.due_date);
                if (dueFrom && d < new Date(dueFrom)) return false;
                if (dueTo && d > new Date(dueTo)) return false;
                return true;
            } : null);
            rsTbWhere(todosTable, 'priority', priority ? function(r) { return r.priority === priority; } : null);
            rsTbWhere(todosTable, 'status', status ? function(r) { return r.status === status; } : null);
        }

        // header search -> table
        $('#todoSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(todosTable, v); },200); });

        function clearFilters() {
            $('#todoSearch').val('');
            $('#filterPriority').val('');
            $('#filterStatus').val('');
            $('#filterDueFrom').val('');
            $('#filterDueTo').val('');
            ['filterPriority', 'filterStatus'].forEach(function(id) { var el = document.getElementById(id); if (el && el.sdRefresh) el.sdRefresh(); });
            rsTbClear(todosTable);
        }

        // view detail
        function viewRecord(d) {
            var html = '<table class="view-detail-table">';
            html += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">' + d.id + '</td>';
            html += '<td class="vd-label"><i class="fas fa-heading"></i> Title</td><td class="vd-value">' + escapeHtml(d.title || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-flag"></i> Priority</td><td class="vd-value">' + priorityBadge(d.priority) + '</td>';
            html += '<td class="vd-label"><i class="fas fa-spinner"></i> Status</td><td class="vd-value">' + statusBadge(d.status) + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-calendar"></i> Due Date</td><td class="vd-value">' + escapeHtml(d.due_date || '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-user"></i> Assigned To</td><td class="vd-value">' + escapeHtml(d.assigned_name || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-user-edit"></i> Created By</td><td class="vd-value">' + escapeHtml(d.creator_name || '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-clock"></i> Created</td><td class="vd-value">' + escapeHtml(d.created_at || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-file-shield"></i> Related Policy</td><td class="vd-value">' + policyLink(d) + (d.policy_customer ? ' <span class="text-muted">' + escapeHtml(d.policy_customer) + '</span>' : '') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-circle-check"></i> Completed At</td><td class="vd-value">' + escapeHtml(d.completed_at || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-align-left"></i> Description</td><td class="vd-value" colspan="3">' + escapeHtml(d.description || '-') + '</td></tr>';
            html += '</table>';
            document.getElementById('viewModalTitle').innerHTML = '<i class="fas fa-eye"></i> Task Details';
            document.getElementById('viewModalBody').innerHTML = html;
            document.getElementById('viewModal').classList.add('active');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
        }

        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) closeViewModal();
        });

        <?php if ($canEdit): ?>
        // one-click complete / reopen — no modal
        function toggleTodoStatus(todo) {
            var fd = new FormData();
            fd.append('id', todo.id);
            fd.append('csrf_token', window.CSRF_TOKEN);
            showLoading('Updating...');
            $.ajax({
                url: '?action=toggleTodoStatus', method: 'POST', data: fd,
                processData: false, contentType: false, dataType: 'json',
                success: function(res) {
                    hideLoading();
                    if (res.success) { Swal.fire({ icon:'success', text:res.message, timer:1600, showConfirmButton:false }); setTimeout(loadTodos, 100); }
                    else { Swal.fire('Error', res.message, 'error'); }
                },
                error: function() { hideLoading(); Swal.fire('Error', 'Request failed', 'error'); }
            });
        }

        function openAddModal() {
            isEditMode = false;
            $('#modalTitle').html('<i class="fas fa-tasks"></i> Add Task');
            $('#todoForm')[0].reset();
            $('#todoId').val('');
            // reset searchable dropdowns
            var pSel = document.getElementById('todoPriority');
            var sSel = document.getElementById('todoStatus');
            var aSel = document.getElementById('todoAssignedTo');
            if (pSel) { pSel.value = 'medium'; if (pSel.sdRefresh) pSel.sdRefresh(); }
            if (sSel) { sSel.value = 'pending'; if (sSel.sdRefresh) sSel.sdRefresh(); }
            if (aSel) { aSel.value = ''; if (aSel.sdRefresh) aSel.sdRefresh(); }
            var polSel = document.getElementById('todoPolicy');
            if (polSel) { polSel.value = ''; if (polSel.sdRefresh) polSel.sdRefresh(); }
            $('#todoModal').addClass('active');
            setTimeout(initAllSearchableDropdowns, 100);
        }

        function editTodo(todo) {
            isEditMode = true;
            $('#modalTitle').html('<i class="fas fa-edit"></i> Edit Task');
            $('#todoId').val(todo.id);
            $('#todoTitle').val(todo.title);
            $('#todoDescription').val(todo.description || '');
            $('#todoDueDate').val(todo.due_date || '');

            var pSel = document.getElementById('todoPriority');
            var sSel = document.getElementById('todoStatus');
            var aSel = document.getElementById('todoAssignedTo');

            if (pSel) { pSel.value = todo.priority; if (pSel.sdRefresh) pSel.sdRefresh(); }
            if (sSel) { sSel.value = todo.status; if (sSel.sdRefresh) sSel.sdRefresh(); }
            if (aSel) { aSel.value = todo.assigned_to || ''; if (aSel.sdRefresh) aSel.sdRefresh(); }
            var polSel = document.getElementById('todoPolicy');
            if (polSel) {
                // linked policy older than the latest 500 -> add it so edit keeps it
                if (todo.policy_entry_id && !polSel.querySelector('option[value="' + todo.policy_entry_id + '"]')) {
                    $(polSel).append('<option value="' + todo.policy_entry_id + '">' + escapeHtml(polLabel(todo.policy_number, todo.policy_customer)) + '</option>');
                }
                polSel.value = todo.policy_entry_id || '';
                if (polSel.sdRefresh) polSel.sdRefresh();
            }

            $('#todoModal').addClass('active');
            setTimeout(initAllSearchableDropdowns, 100);
        }

        function closeModal() {
            $('#todoModal').removeClass('active');
            $('#todoForm')[0].reset();
        }

        document.getElementById('todoModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // form submit
        $('#todoForm').on('submit', function(e) {
            e.preventDefault();

            var formData = new FormData(this);
            var action = isEditMode ? 'updateTodo' : 'addTodo';
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
                        setTimeout(loadTodos, 100);
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

        <?php if ($isAdmin): ?>
        function deleteTodo(tid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Task?',
                text: 'This action cannot be undone',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) return;

                var fd = new FormData();
                fd.append('id', tid);
                fd.append('csrf_token', window.CSRF_TOKEN);

                $.ajax({
                    url: '?action=deleteTodo',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                            setTimeout(loadTodos, 100);
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
        var todosImport = RSImport({
            key: 'todos', title: 'Bulk Add / Import Tasks', url: '?action=bulkAddTodos',
            hint: 'Add many tasks at once — upload an Excel/CSV file or type the rows. Only the <strong>title</strong> is required. <strong>Assign To</strong> is matched on a user\'s name or email; leave it blank for an unassigned task.',
            onDone: function() { setTimeout(loadTodos, 100); },
            fields: [
                { key: 'title', label: 'Title *', cls: 'bg-title', req: true, max: 255, ph: 'What needs doing', aliases: ['title', 'task', 'taskname', 'subject', 'name'], demo: 'Demo follow-up call', demo2: 'Demo renewal reminder' },
                { key: 'description', label: 'Description', cls: 'bg-desc', ph: 'optional', aliases: ['description', 'desc', 'details', 'notes', 'remarks'], demo: 'Demo task details', demo2: '' },
                { key: 'priority', label: 'Priority', cls: 'bg-prio', type: 'select', def: 'medium',
                  opts: [{ v: 'low', t: 'Low' }, { v: 'medium', t: 'Medium' }, { v: 'high', t: 'High' }, { v: 'urgent', t: 'Urgent' }],
                  aliases: ['priority', 'prio', 'importance'], demo: 'high', demo2: 'medium' },
                { key: 'status', label: 'Status', cls: 'bg-status', type: 'select', def: 'pending',
                  opts: [{ v: 'pending', t: 'Pending' }, { v: 'in_progress', t: 'In Progress' }, { v: 'completed', t: 'Completed' }],
                  aliases: ['status', 'state', 'stage'], demo: 'pending', demo2: 'pending' },
                { key: 'due_date', label: 'Due Date', cls: 'bg-due', type: 'date', aliases: ['duedate', 'due', 'deadline', 'targetdate'], demo: '2026-08-01', demo2: '' },
                { key: 'assigned_to', label: 'Assign To', cls: 'bg-assign', type: 'select',
                  opts: function() { return [{ v: '', t: '— Unassigned —' }].concat((usersData || []).map(function(u) { return { v: u.name, t: u.name }; })); },
                  aliases: ['assignedto', 'assignee', 'assign', 'owner', 'responsible'], demo: '', demo2: '' },
                { key: 'policy_number', label: 'Policy No', cls: 'bg-policy', max: 50, ph: 'optional', aliases: ['policynumber', 'policyno', 'policy', 'relatedpolicy'], demo: '', demo2: '' }
            ]
        });
        <?php endif; ?>
    </script>
</body>
</html>
