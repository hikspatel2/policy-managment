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
$current_page = 'notifications';

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getUsers':
                // admin only — for targeting notifications
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $stmt = $conn->prepare("SELECT id, name, role FROM users WHERE is_active = 1 ORDER BY name");
                $stmt->execute();
                $res = $stmt->get_result();
                $users = [];
                while ($row = $res->fetch_assoc()) {
                    $users[] = [
                        'id' => (int)$row['id'],
                        'name' => $row['name'],
                        'role' => $row['role']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $users]);
                exit();

            case 'getNotifications':
                // header bell: own latest N + unread count, never the whole table
                if (($_GET['mine'] ?? '') === '1') {
                    $lim = max(1, min(50, (int)($_GET['limit'] ?? 8)));
                    $stmt = $conn->prepare("SELECT id, user_id, type, title, message, policy_entry_id, agent_id, is_read, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ?");
                    $stmt->bind_param("ii", $user_id, $lim);
                    $stmt->execute();
                    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $unread = (int)$stmt->get_result()->fetch_row()[0];
                    $stmt->close();
                    $conn->close();
                    foreach ($rows as &$r) foreach (['id', 'user_id', 'is_read'] as $k) $r[$k] = (int)$r[$k];
                    unset($r);
                    echo json_encode(['success' => true, 'data' => $rows, 'unread' => $unread]);
                    exit();
                }
                if ($role === 'agent') {
                    // agent sees own only
                    $stmt = $conn->prepare("
                        SELECT n.*, u.name as user_name
                        FROM notifications n
                        LEFT JOIN users u ON n.user_id = u.id
                        WHERE n.user_id = ?
                        ORDER BY n.created_at DESC
                    ");
                    $stmt->bind_param("i", $user_id);
                } else {
                    // admin/manager see all
                    $stmt = $conn->prepare("
                        SELECT n.*, u.name as user_name
                        FROM notifications n
                        LEFT JOIN users u ON n.user_id = u.id
                        ORDER BY n.created_at DESC
                    ");
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $notifs = [];
                while ($row = $res->fetch_assoc()) {
                    $notifs[] = [
                        'id' => (int)$row['id'],
                        'user_id' => (int)$row['user_id'],
                        'user_name' => $row['user_name'] ?: 'Unknown',
                        'type' => $row['type'],
                        'title' => $row['title'],
                        'message' => $row['message'],
                        'policy_entry_id' => $row['policy_entry_id'] ? (int)$row['policy_entry_id'] : null,
                        'agent_id' => $row['agent_id'] ? (int)$row['agent_id'] : null,
                        'is_read' => (int)$row['is_read'],
                        'read_at' => $row['read_at'],
                        'is_email_sent' => (int)$row['is_email_sent'],
                        'scheduled_at' => $row['scheduled_at'],
                        'created_at' => $row['created_at']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $notifs]);
                exit();

            case 'getUnreadCount':
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'count' => (int)$row['cnt']]);
                exit();

            case 'addNotification':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $target_user_id = (int)($_POST['user_id'] ?? 0);
                $type = trim($_POST['type'] ?? '');
                $title = trim($_POST['title'] ?? '');
                $message = trim($_POST['message'] ?? '');
                $policy_entry_id = !empty($_POST['policy_entry_id']) ? (int)$_POST['policy_entry_id'] : null;
                $agent_id = !empty($_POST['agent_id']) ? (int)$_POST['agent_id'] : null;
                $scheduled_at = !empty($_POST['scheduled_at']) ? trim($_POST['scheduled_at']) : null;

                // validate
                if ($target_user_id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Target user is required']);
                    exit();
                }
                $allowed_types = ['policy_expiry', 'payment_pending', 'commission_update', 'general'];
                if (!in_array($type, $allowed_types)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid notification type']);
                    exit();
                }
                if (strlen($title) < 2 || strlen($title) > 200) {
                    echo json_encode(['success' => false, 'message' => 'Title must be 2-200 characters']);
                    exit();
                }
                if (empty($message)) {
                    echo json_encode(['success' => false, 'message' => 'Message is required']);
                    exit();
                }

                $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, policy_entry_id, agent_id, scheduled_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssiis", $target_user_id, $type, $title, $message, $policy_entry_id, $agent_id, $scheduled_at);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to add notification']);
                    exit();
                }
                $insertId = $stmt->insert_id;
                $stmt->close();

                logActivity($user_id, $username, 'Notification Created', "Created notification #$insertId: $title");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Notification sent successfully', 'data' => ['id' => $insertId]]);
                exit();

            case 'markRead':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }

                $nid = (int)($_POST['id'] ?? 0);
                if ($nid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
                    exit();
                }

                // own notifs only
                $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = ? AND user_id = ?");
                $stmt->bind_param("ii", $nid, $user_id);
                $stmt->execute();
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Marked as read']);
                exit();

            case 'markAllRead':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }

                $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'message' => "Marked $affected notifications as read"]);
                exit();

            case 'updateNotification':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $nid = (int)($_POST['id'] ?? 0);
                $nUserId = (int)($_POST['user_id'] ?? 0);
                $nType = trim($_POST['type'] ?? '');
                $nTitle = trim($_POST['title'] ?? '');
                $nMessage = trim($_POST['message'] ?? '');
                $nScheduled = trim($_POST['scheduled_at'] ?? '') ?: null;

                if ($nid <= 0 || $nUserId <= 0 || empty($nType) || empty($nTitle) || empty($nMessage)) {
                    echo json_encode(['success' => false, 'message' => 'Required fields missing']);
                    exit();
                }

                $stmt = $conn->prepare("UPDATE notifications SET user_id=?, type=?, title=?, message=?, scheduled_at=? WHERE id=?");
                $stmt->bind_param("issssi", $nUserId, $nType, $nTitle, $nMessage, $nScheduled, $nid);

                if ($stmt->execute()) {
                    logActivity($user_id, $username, 'Notification Updated', "Updated notification #$nid");
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Notification updated']);
                } else {
                    $stmt->close();
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to update notification']);
                }
                exit();

            case 'deleteNotification':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if ($role !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $nid = (int)($_POST['id'] ?? 0);
                if ($nid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid notification ID']);
                    exit();
                }

                // get title before delete
                $stmt = $conn->prepare("SELECT title FROM notifications WHERE id = ?");
                $stmt->bind_param("i", $nid);
                $stmt->execute();
                $delRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $delTitle = $delRow ? $delRow['title'] : 'Unknown';

                $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ?");
                $stmt->bind_param("i", $nid);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Failed to delete notification']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Notification Deleted', "Deleted notification: $delTitle (#$nid)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Notification deleted successfully']);
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
                    echo json_encode(['success' => false, 'message' => 'No notifications selected']);
                    exit();
                }

                $del = $conn->prepare("DELETE FROM notifications WHERE id = ?");
                $done = 0;
                foreach ($ids as $nid) {
                    $del->bind_param("i", $nid);
                    if ($del->execute() && $conn->affected_rows > 0) $done++;
                }
                $del->close();

                logActivity($user_id, $username, 'Bulk delete', "Deleted $done notification(s)");
                $conn->close();
                echo json_encode(['success' => true, 'message' => "$done record(s) deleted"]);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("notifications.php error: " . $e->getMessage());
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
    <title>Notifications - Insurance Management System</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.1/dist/css/tabulator.min.css">
    <link rel="stylesheet" href="styles.css?v=16.0">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="app.js?v=2.3"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.1/dist/js/tabulator.min.js"></script>
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
            $pageIcon    = 'fa-bell';
            $pageTitle   = 'Notifications';
            $crumbs      = [];
            $pageActions = [];
            if ($isAdmin) $pageActions[] = ['label' => 'Send Notification', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary'];
            $pageActions[] = ['label' => 'Mark All Read', 'icon' => 'fa-check-double', 'onclick' => 'markAllRead()', 'class' => $isAdmin ? '' : 'primary'];
            if (!$isAgent) {
                $pageActions[] = ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(notifsTable,'csv')"];
                $pageActions[] = ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(notifsTable,'pdf')"];
                $pageActions[] = ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(notifsTable,'print')"];
            }
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadNotifications()'];
            include 'page_header.php';
            ?>
            <?php if (!$isAgent): ?>
            <div data-head-filter>
                <div class="hf-field"><label for="notifSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="notifSearch" placeholder="Search notifications…" autocomplete="off"></div>
                <div class="hf-field"><label for="filterType" title="Type"><i class="fas fa-tag"></i></label><select id="filterType" class="filter-input searchable">
                    <option value="">All Types</option>
                    <option value="policy_expiry">Policy Expiry</option>
                    <option value="payment_pending">Payment Pending</option>
                    <option value="commission_update">Commission Update</option>
                    <option value="general">General</option>
                </select></div>
                <div class="hf-field"><label for="filterRead" title="Read status"><i class="fas fa-envelope-open"></i></label><select id="filterRead" class="filter-input searchable">
                    <option value="">All (read + unread)</option>
                    <option value="Read">Read</option>
                    <option value="Unread">Unread</option>
                </select></div>
                <div class="hf-field hf-range" title="Date range"><label for="filterDateFrom"><i class="fas fa-calendar-days"></i></label><input type="date" id="filterDateFrom" class="filter-input" aria-label="From"><span class="hf-sep"><i class="fas fa-arrow-right-long"></i></span><input type="date" id="filterDateTo" class="filter-input" aria-label="To"></div>
                <div class="hf-seg" role="group" aria-label="Quick range"><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',0,this)">Today</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',6,this)">7 days</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',29,this)">30 days</button></div>
                <button type="button" class="btn btn-icon" onclick="clearFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>
            <?php endif; ?>

            <div class="data-section">
                <?php if (!$isAgent): ?>
                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.7"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Empty state (admin/manager) -->
                <div id="emptyStateBox" style="display: none;"></div>

                <!-- table (admin/manager) -->
                <div id="tableContainer" style="display: none;">
                    <div id="notificationsTable"></div>
                </div>

                <?php else: ?>
                <!-- Agent: card-based view -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <?php for ($i = 0; $i < 5; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="agentCardsContainer" style="display: none;">
                    <div class="notif-cards" id="notifCards"></div>
                    <div id="noNotifs" style="display: none; text-align: center; padding: 40px; color: var(--text-muted);">
                        <i class="fas fa-bell-slash" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>
                        No notifications yet
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$isAgent): ?>
    <!-- View Detail Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="viewModalTitle"><i class="fas fa-eye"></i> Notification Details</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <!-- Add Notification Modal -->
    <div class="modal-overlay" id="notifModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="notifModalTitle"><i class="fas fa-plus"></i> Send Notification</h3>
                <button class="close-btn" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="notifForm">
                    <input type="hidden" id="notifId" name="id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Target User *</label>
                            <select id="notifUserId" name="user_id" class="searchable" required>
                                <option value="">Select User</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Type *</label>
                            <select id="notifType" name="type" class="searchable" required>
                                <option value="">Select Type</option>
                                <option value="policy_expiry">Policy Expiry</option>
                                <option value="payment_pending">Payment Pending</option>
                                <option value="commission_update">Commission Update</option>
                                <option value="general">General</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-heading"></i> Title *</label>
                        <input type="text" id="notifTitle" name="title" required minlength="2" maxlength="200" placeholder="Notification title">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-comment-alt"></i> Message *</label>
                        <textarea id="notifMessage" name="message" required rows="4" placeholder="Notification message"></textarea>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-clock"></i> Schedule (optional)</label>
                        <input type="datetime-local" id="notifSchedule" name="scheduled_at">
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i> Send
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
        const IS_AGENT = <?php echo $isAgent ? 'true' : 'false'; ?>;
        const CURRENT_USER_ID = <?php echo (int)$user_id; ?>;
        let notifsTable = null;
        let notifsData = [];
        var isEditMode = false;

        // type icon map
        const typeIcons = {
            'policy_expiry': '<i class="fas fa-calendar-times"></i>',
            'payment_pending': '<i class="fas fa-exclamation-triangle"></i>',
            'commission_update': '<i class="fas fa-coins"></i>',
            'general': '<i class="fas fa-info-circle"></i>'
        };

        const typeLabels = {
            'policy_expiry': 'Policy Expiry',
            'payment_pending': 'Payment Pending',
            'commission_update': 'Commission Update',
            'general': 'General'
        };

        $(document).ready(function() {
            loadNotifications();
            <?php if ($isAdmin): ?>
            loadUsers();
            <?php endif; ?>
        });

        function loadNotifications() {
            $('#loadingSkeleton').show();
            if (IS_AGENT) {
                $('#agentCardsContainer').hide();
            } else {
                $('#tableContainer').hide();
            }

            $.ajax({
                url: '?action=getNotifications',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        notifsData = res.data;
                        $('#loadingSkeleton').hide();

                        if (IS_AGENT) {
                            renderAgentCards(res.data);
                        } else if (!res.data.length) {
                            // empty state for admin/manager table
                            $('#tableContainer').hide();
                            var es = emptyStateHtml({
                                icon: 'fa-bell-slash', title: 'No notifications yet',
                                text: 'Notifications you send will appear here.',
                                btnText: IS_ADMIN ? 'Send Notification' : '', btnId: IS_ADMIN ? 'emptyAddNotif' : ''
                            });
                            $('#emptyStateBox').html(es).show();
                            $('#emptyAddNotif').off('click').on('click', openAddModal);
                        } else {
                            $('#emptyStateBox').hide();
                            $('#tableContainer').show();
                            setTimeout(function() { initializeDataTable(res.data); }, 100);
                        }
                    } else {
                        $('#loadingSkeleton').hide();
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load notifications' });
                    }
                },
                error: function() {
                    $('#loadingSkeleton').hide();
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
                }
            });
        }

        // agent card view
        function renderAgentCards(data) {
            const container = $('#notifCards');
            container.empty();

            if (!data.length) {
                $('#noNotifs').show();
                $('#agentCardsContainer').show();
                return;
            }

            $('#noNotifs').hide();
            data.forEach(function(n) {
                const unread = !n.is_read ? ' unread' : '';
                const dateStr = new Date(n.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                const html = `
                    <div class="notif-card${unread}" data-id="${n.id}" onclick="markCardRead(${n.id}, this)">
                        <div class="notif-card-header">
                            <div class="notif-card-title">${escapeHtml(n.title)}</div>
                            <div class="notif-card-date">${escapeHtml(dateStr)}</div>
                        </div>
                        <div class="notif-card-message">${escapeHtml(n.message)}</div>
                        <div class="notif-card-footer">
                            <span class="notif-type-badge ${n.type}">${typeIcons[n.type] || ''} ${escapeHtml(typeLabels[n.type] || n.type)}</span>
                            ${!n.is_read ? '<span class="read-badge no">Unread</span>' : ''}
                        </div>
                    </div>
                `;
                container.append(html);
            });
            $('#agentCardsContainer').show();
        }

        function markCardRead(nid, el) {
            if (!$(el).hasClass('unread')) return;

            $.ajax({
                url: '?action=markRead',
                method: 'POST',
                data: { id: nid, csrf_token: window.CSRF_TOKEN },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        $(el).removeClass('unread');
                        $(el).find('.read-badge').remove();
                        window.pollNotificationCount && pollNotificationCount();
                    }
                }
            });
        }

        // admin/manager table
        function initializeDataTable(data) {
            if (notifsTable) return rsTbData(notifsTable, data); // build once, swap rows

            let cols = [
                { data:'id', title:'<input type="checkbox" class="bulk-check-all">', orderable:false, searchable:false, className:'bulk-col', render:function(d){ return '<input type="checkbox" class="bulk-check" data-id="'+d+'">'; } },
                { data: 'id', title: 'ID', responsivePriority: window.innerWidth < 480 ? 9 : 1 }, // phone: id folds first
                {
                    data: 'type',
                    title: 'Type',
                    render: function(d) {
                        return '<span class="notif-type-badge ' + d + '">' + (typeIcons[d] || '') + ' ' + escapeHtml(typeLabels[d] || d) + '</span>';
                    }
                },
                { data: 'title', title: 'Title', render: function(d) { return escapeHtml(d); } },
                {
                    data: 'message',
                    title: 'Message',
                    render: function(d) {
                        var txt = d || '';
                        if (txt.length > 60) txt = txt.substring(0, 60) + '...';
                        return escapeHtml(txt);
                    }
                },
                { data: 'user_name', title: 'Target User', render: function(d) { return escapeHtml(d); } },
                {
                    data: 'is_read',
                    title: 'Read',
                    render: function(d) {
                        return d ? '<span class="read-badge yes">Yes</span>' : '<span class="read-badge no">No</span>';
                    }
                },
                { data: 'read_at', title: 'Read At', visible: false },
                {
                    data: 'is_email_sent',
                    title: 'Email Sent',
                    render: function(d) {
                        return d ? '<span class="read-badge yes">Yes</span>' : '<span class="read-badge no">No</span>';
                    }
                },
                {
                    data: 'created_at',
                    title: 'Created',
                    render: function(d) {
                        if (!d) return '-';
                        return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                    }
                }
            ];

            // actions col — view for all, more menu (edit/delete) for admin
            cols.push({
                data: null,
                title: 'Actions',
                orderable: false,
                render: function(d, type, row) {
                    var html = '<div class="action-hub">';
                    html += '<button class="action-icon view-icon" data-act="view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button>';
                    if (IS_ADMIN) {
                        html += '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button>';
                        html += '<div class="more-dropdown">';
                        html += '<button class="more-item" data-act="edit" data-id="' + row.id + '"><i class="fas fa-pen"></i> Edit</button>';
                        html += '<div class="more-divider"></div>';
                        html += '<button class="more-item danger" data-act="delete" data-id="' + row.id + '"><i class="fas fa-trash"></i> Delete</button>';
                        html += '</div></div>';
                    }
                    return html + '</div>';
                }
            });

            notifsTable = rsTable('#notificationsTable', {
                columns: cols, data: data, title: 'Notifications', file: 'notifications', sort: ['id', 'desc'], bulk: IS_ADMIN,
                info: function() { return [['Type', $('#filterType').val() || 'All'], ['Read', $('#filterRead').val() || 'All']]; }
            });
            rsTbSearch(notifsTable, $('#notifSearch').val());

            // bind filter events
            $('#filterDateFrom, #filterDateTo, #filterType, #filterRead').off('change').on('change', applyFilters);

            // one delegated handler for all row actions
            $('#notificationsTable').off('click', '[data-act]').on('click', '[data-act]', function() {
                var id = parseInt($(this).data('id'), 10);
                var act = $(this).data('act');
                var row = notifsData.find(function(r) { return r.id === id; });
                if (!row) return;
                if (act === 'view') viewNotification(row);
                else if (act === 'edit' && IS_ADMIN) editNotification(row);
                else if (act === 'delete' && IS_ADMIN) deleteNotification(id);
            });

            <?php if ($isAdmin): ?>
            // bulk select
            initBulkSelect({
                table: notifsTable,
                label: 'notifications',
                onDelete: function(ids) {
                    showLoading('Deleting...');
                    $.ajax({
                        url: '?action=bulkDelete',
                        method: 'POST',
                        dataType: 'json',
                        data: { csrf_token: window.CSRF_TOKEN, ids: ids },
                        success: function(res) {
                            hideLoading();
                            if (res.success) { Swal.fire('Deleted', res.message, 'success'); loadNotifications(); window.pollNotificationCount && pollNotificationCount(); }
                            else { Swal.fire('Error', res.message, 'error'); }
                        },
                        error: function() { hideLoading(); Swal.fire('Error', 'Request failed', 'error'); }
                    });
                }
            });
            <?php endif; ?>
            applyFilters();
        }

        function applyFilters() {
            if (!notifsTable) return;
            var type = $('#filterType').val(), read = $('#filterRead').val();
            var dateFrom = $('#filterDateFrom').val(), dateTo = $('#filterDateTo').val();

            // date range on created_at
            rsTbWhere(notifsTable, 'date', (dateFrom || dateTo) ? function(r) {
                if (!r.created_at) return true;
                var d = new Date(r.created_at);
                if (dateFrom && d < new Date(dateFrom)) return false;
                if (dateTo && d > new Date(dateTo + 'T23:59:59')) return false;
                return true;
            } : null);
            rsTbWhere(notifsTable, 'type', type ? function(r) { return r.type === type; } : null);
            rsTbWhere(notifsTable, 'read', read ? function(r) { return !!Number(r.is_read) === (read === 'Read'); } : null);
        }

        // header search -> table
        $('#notifSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(notifsTable, v); },200); });

        function clearFilters() {
            $('#notifSearch').val('');
            $('.hf-seg .is-on').removeClass('is-on');
            $('#filterType').val('');
            $('#filterRead').val('');
            $('#filterDateFrom').val('');
            $('#filterDateTo').val('');
            ['filterType', 'filterRead'].forEach(function(id) { var el = document.getElementById(id); if (el && el.sdRefresh) el.sdRefresh(); });
            rsTbClear(notifsTable);
        }

        // full notification detail modal (admin + manager)
        function viewNotification(d) {
            var created = d.created_at ? new Date(d.created_at).toLocaleString('en-US', { month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit' }) : '-';
            var html = '<table class="view-detail-table">';
            html += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">' + d.id + '</td>';
            html += '<td class="vd-label"><i class="fas fa-tag"></i> Type</td><td class="vd-value">' + escapeHtml(typeLabels[d.type] || d.type) + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-user"></i> Target User</td><td class="vd-value">' + escapeHtml(d.user_name || '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-envelope-open"></i> Read</td><td class="vd-value">' + (d.is_read ? 'Yes' : 'No') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-paper-plane"></i> Email Sent</td><td class="vd-value">' + (d.is_email_sent ? 'Yes' : 'No') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-clock"></i> Created</td><td class="vd-value">' + escapeHtml(created) + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-clock-rotate-left"></i> Read At</td><td class="vd-value" colspan="3">' + (d.read_at ? escapeHtml(new Date(d.read_at.replace(' ', 'T')).toLocaleString('en-US', { month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit' })) : '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-heading"></i> Title</td><td class="vd-value" colspan="3">' + escapeHtml(d.title || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-comment-alt"></i> Message</td><td class="vd-value" colspan="3">' + (d.message ? escapeHtml(d.message) : '<em class="text-muted">-</em>') + '</td></tr>';
            html += '</table>';
            document.getElementById('viewModalBody').innerHTML = html;
            document.getElementById('viewModal').classList.add('active');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
        }

        var _vm = document.getElementById('viewModal'); // admin/manager only
        if (_vm) _vm.addEventListener('click', function(e) {
            if (e.target === this) closeViewModal();
        });

        function markAllRead() {
            Swal.fire({
                icon: 'question',
                title: 'Mark All as Read?',
                text: 'All your notifications will be marked as read',
                showCancelButton: true,
                confirmButtonText: 'Yes, Mark All',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) return;

                $.ajax({
                    url: '?action=markAllRead',
                    method: 'POST',
                    data: { csrf_token: window.CSRF_TOKEN },
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                            setTimeout(loadNotifications, 100);
                            window.pollNotificationCount && pollNotificationCount();
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                        }
                    },
                    error: function() {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' });
                    }
                });
            });
        }

        <?php if ($isAdmin): ?>
        function loadUsers() {
            $.ajax({
                url: '?action=getUsers',
                method: 'GET',
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        var sel = $('#notifUserId');
                        sel.find('option:not(:first)').remove();
                        res.data.forEach(function(u) {
                            sel.append('<option value="' + u.id + '">' + escapeHtml(u.name) + ' (' + escapeHtml(u.role) + ')</option>');
                        });
                        setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
                    }
                }
            });
        }

        function openAddModal() {
            isEditMode = false;
            document.getElementById('notifModalTitle').innerHTML = '<i class="fas fa-plus"></i> Send Notification';
            document.getElementById('notifId').value = '';
            $('#notifForm')[0].reset();
            $('#notifModal').addClass('active');
        }

        function editNotification(row) {
            isEditMode = true;
            document.getElementById('notifModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Notification';
            document.getElementById('notifId').value = row.id;
            document.getElementById('notifUserId').value = row.user_id;
            document.getElementById('notifType').value = row.type;
            document.getElementById('notifTitle').value = row.title;
            document.getElementById('notifMessage').value = row.message;
            var sched = document.getElementById('notifSchedule');
            if (sched) sched.value = row.scheduled_at || '';
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
            document.getElementById('notifModal').classList.add('active');
        }

        function closeModal() {
            $('#notifModal').removeClass('active');
            $('#notifForm')[0].reset();
        }

        // close on overlay click
        document.getElementById('notifModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // form submit
        $('#notifForm').on('submit', function(e) {
            e.preventDefault();

            var formData = new FormData(this);
            var action = isEditMode ? 'updateNotification' : 'addNotification';
            formData.append('csrf_token', window.CSRF_TOKEN);

            showLoading(isEditMode ? 'Updating...' : 'Sending...');

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
                        Swal.fire({ icon: 'success', title: 'Sent!', text: res.message, timer: 2000, showConfirmButton: false });
                        closeModal();
                        setTimeout(loadNotifications, 100);
                        window.pollNotificationCount && pollNotificationCount();
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

        function deleteNotification(nid) {
            Swal.fire({
                icon: 'warning',
                title: 'Delete Notification?',
                text: 'This action cannot be undone',
                showCancelButton: true,
                confirmButtonColor: '#ea4335',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) return;

                var fd = new FormData();
                fd.append('id', nid);
                fd.append('csrf_token', window.CSRF_TOKEN);

                $.ajax({
                    url: '?action=deleteNotification',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        if (res.success) {
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                            setTimeout(loadNotifications, 100);
                            window.pollNotificationCount && pollNotificationCount();
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
    </script>
</body>
</html>
