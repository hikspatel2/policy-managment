<?php
/**
 * Support Desk Tickets Module
 * Insurance Management System
 */
require_once 'config.php';

// Auth check
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout()) { header("Location: login.php"); exit(); }

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = (int)$_SESSION['user_id'];
$current_page = 'support_tickets';

$conn = getDBConnection();
$aid = ($role === 'agent') ? agentIdForUser($conn, $user_id) : 0;
$branding = getBranding();

// View single ticket thread or ticket list
$ticketId = (int)($_GET['id'] ?? 0);
$error = '';
$notice = '';

// Handle Create Ticket POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_ticket') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $subject = trim($_POST['subject'] ?? '');
        $category = trim($_POST['category'] ?? 'general');
        $priority = trim($_POST['priority'] ?? 'medium');
        $message = trim($_POST['message'] ?? '');

        if (empty($subject) || empty($message)) {
            $error = 'Please enter both subject and message details.';
        } else {
            $tNum = 'TCK-' . date('Y') . '-' . strtoupper(substr(uniqid(), -5));
            $tAgentId = ($role === 'agent') ? $aid : (int)($_POST['agent_id'] ?? 1);

            $tIns = $conn->prepare("INSERT INTO support_tickets (ticket_number, agent_id, subject, category, priority, status, last_reply_at) VALUES (?, ?, ?, ?, ?, 'open', NOW())");
            $tIns->bind_param("sisss", $tNum, $tAgentId, $subject, $category, $priority);
            $tIns->execute();
            $newTicketId = $conn->insert_id;
            $tIns->close();

            // Insert initial message
            $mIns = $conn->prepare("INSERT INTO ticket_messages (ticket_id, sender_user_id, sender_role, message) VALUES (?, ?, ?, ?)");
            $mIns->bind_param("iiss", $newTicketId, $user_id, $role, $message);
            $mIns->execute();
            $mIns->close();

            header("Location: support_tickets.php?id=" . $newTicketId);
            exit();
        }
    }
}

// Handle Reply to Ticket POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reply_ticket') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $tId = (int)($_POST['ticket_id'] ?? 0);
        $replyMsg = trim($_POST['message'] ?? '');
        $newStatus = trim($_POST['status'] ?? '');

        if (!empty($replyMsg)) {
            $rIns = $conn->prepare("INSERT INTO ticket_messages (ticket_id, sender_user_id, sender_role, message) VALUES (?, ?, ?, ?)");
            $rIns->bind_param("iiss", $tId, $user_id, $role, $replyMsg);
            $rIns->execute();
            $rIns->close();

            $statusSql = (!empty($newStatus)) ? ", status = '" . $conn->real_escape_string($newStatus) . "'" : "";
            $conn->query("UPDATE support_tickets SET last_reply_at = NOW() {$statusSql} WHERE id = {$tId}");

            header("Location: support_tickets.php?id=" . $tId);
            exit();
        }
    }
}

// If viewing a single ticket
$ticket = null;
$messages = [];
if ($ticketId > 0) {
    $tStmt = $conn->prepare("SELECT st.*, a.name AS agent_name, a.agency_name, a.mobile AS agent_mobile
                             FROM support_tickets st
                             JOIN agents a ON a.id = st.agent_id
                             WHERE st.id = ? LIMIT 1");
    $tStmt->bind_param("i", $ticketId);
    $tStmt->execute();
    $ticket = $tStmt->get_result()->fetch_assoc();
    $tStmt->close();

    if ($ticket) {
        if ($role === 'agent' && (int)$ticket['agent_id'] !== $aid) {
            die("Unauthorized access to this ticket.");
        }
        $mRes = $conn->query("SELECT tm.*, u.name AS sender_name 
                              FROM ticket_messages tm 
                              JOIN users u ON u.id = tm.sender_user_id 
                              WHERE tm.ticket_id = {$ticketId} 
                              ORDER BY tm.created_at ASC");
        if ($mRes) {
            while ($m = $mRes->fetch_assoc()) $messages[] = $m;
        }
    }
}

// Fetch all tickets for list view
$tickets = [];
if (!$ticketId) {
    $listSql = "SELECT st.*, a.name AS agent_name, a.agency_name 
                FROM support_tickets st 
                JOIN agents a ON a.id = st.agent_id";
    if ($role === 'agent') {
        $listSql .= " WHERE st.agent_id = " . (int)$aid;
    }
    $listSql .= " ORDER BY (st.status = 'open' OR st.status = 'in_progress') DESC, st.last_reply_at DESC LIMIT 100";
    $tRes = $conn->query($listSql);
    if ($tRes) {
        while ($tr = $tRes->fetch_assoc()) $tickets[] = $tr;
    }
}

$pageTitle = $ticket ? 'Ticket #' . $ticket['ticket_number'] : 'Support Tickets';
$pageIcon = 'fa-headset';
$crumbs = [['label' => 'Helpdesk', 'icon' => 'fa-circle-question'], ['label' => 'Support Tickets']];
$pageActions = $ticket
    ? [['label' => 'Back to All Tickets', 'icon' => 'fa-arrow-left', 'onclick' => "window.location='support_tickets.php'", 'class' => 'secondary']]
    : [['label' => 'Open New Ticket', 'icon' => 'fa-plus', 'onclick' => "openCreateTicketModal()", 'class' => 'primary']];

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> — <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="styles.css?v=5.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .ticket-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
        }
        .ticket-card:hover {
            border-color: #0074D9;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transform: translateY(-2px);
        }
        .msg-bubble {
            max-width: 75%;
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 14px;
            font-size: 14px;
            line-height: 1.5;
        }
        .msg-bubble.from-me {
            background: #001f3f;
            color: white;
            margin-left: auto;
            border-bottom-right-radius: 2px;
        }
        .msg-bubble.from-other {
            background: #f1f5f9;
            color: #1e293b;
            border: 1px solid #e2e8f0;
            margin-right: auto;
            border-bottom-left-radius: 2px;
        }
        .badge-status-open { background: #fee2e2; color: #b91c1c; }
        .badge-status-in_progress { background: #fef3c7; color: #b45309; }
        .badge-status-resolved { background: #dcfce7; color: #15803d; }
        .badge-status-closed { background: #f1f5f9; color: #64748b; }
    </style>
</head>
<body class="dashboard-page">
    <?php include 'mobile-menu.php'; ?>
    <div class="dashboard-layout">
        <?php include 'sidebar.php'; ?>
        <main class="main-content">
            <?php include 'page_header.php'; ?>

            <div class="content-wrapper" style="padding: 20px;">
                <?php if ($error): ?>
                    <div class="error" style="margin-bottom: 15px;"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if ($ticket): ?>
                    <!-- SINGLE TICKET VIEW -->
                    <div style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; padding: 25px; margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px; border-bottom: 1px solid #f1f5f9; padding-bottom: 15px;">
                            <div>
                                <span class="badge badge-status-<?php echo $ticket['status']; ?>" style="text-transform: capitalize; padding: 4px 10px; border-radius: 20px; font-weight: 700;">
                                    <?php echo str_replace('_', ' ', $ticket['status']); ?>
                                </span>
                                <h2 style="margin: 8px 0 4px; color: #001f3f; font-size: 20px;"><?php echo htmlspecialchars($ticket['subject']); ?></h2>
                                <div style="font-size: 13px; color: #64748b;">
                                    <strong>Category:</strong> <?php echo ucfirst($ticket['category']); ?> | 
                                    <strong>Priority:</strong> <?php echo ucfirst($ticket['priority']); ?> | 
                                    <strong>Subscriber:</strong> <?php echo htmlspecialchars($ticket['agency_name'] ?: $ticket['agent_name']); ?>
                                </div>
                            </div>
                            <div style="text-align: right; font-size: 13px; color: #94a3b8;">
                                <div>Ticket: <strong><?php echo htmlspecialchars($ticket['ticket_number']); ?></strong></div>
                                <div>Created: <?php echo date('d M Y, h:i A', strtotime($ticket['created_at'])); ?></div>
                            </div>
                        </div>

                        <!-- Messages Thread -->
                        <div style="padding: 25px 0; max-height: 480px; overflow-y: auto;">
                            <?php foreach ($messages as $m): 
                                $isMe = ((int)$m['sender_user_id'] === $user_id);
                            ?>
                            <div style="display: flex; flex-direction: column;">
                                <div style="font-size: 12px; color: #94a3b8; margin-bottom: 3px; <?php echo $isMe ? 'text-align: right;' : ''; ?>">
                                    <strong><?php echo htmlspecialchars($m['sender_name']); ?></strong> (<?php echo ucfirst($m['sender_role']); ?>) • <?php echo date('d M, h:i A', strtotime($m['created_at'])); ?>
                                </div>
                                <div class="msg-bubble <?php echo $isMe ? 'from-me' : 'from-other'; ?>">
                                    <?php echo nl2br(htmlspecialchars($m['message'])); ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Reply Form -->
                        <form method="POST" action="support_tickets.php?id=<?php echo $ticketId; ?>" style="border-top: 1px solid #f1f5f9; padding-top: 20px;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="reply_ticket">
                            <input type="hidden" name="ticket_id" value="<?php echo $ticketId; ?>">

                            <div class="form-group" style="margin-bottom: 12px;">
                                <label style="font-size: 13px; font-weight: 600;">Type your response</label>
                                <textarea name="message" rows="3" required placeholder="Write a reply..." style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1;"></textarea>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <?php if ($role === 'admin' || $role === 'manager'): ?>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <label style="font-size: 13px;">Update Status:</label>
                                        <select name="status" style="padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                                            <option value="open" <?php echo $ticket['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                                            <option value="in_progress" <?php echo $ticket['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="resolved" <?php echo $ticket['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                            <option value="closed" <?php echo $ticket['status'] === 'closed' ? 'selected' : ''; ?>>Closed</option>
                                        </select>
                                    </div>
                                <?php else: ?>
                                    <div></div>
                                <?php endif; ?>

                                <button type="submit" class="btn btn-primary" style="padding: 9px 22px;">
                                    <i class="fas fa-paper-plane"></i> Send Reply
                                </button>
                            </div>
                        </form>
                    </div>

                <?php else: ?>
                    <!-- TICKETS LIST VIEW -->
                    <div style="margin-bottom: 20px;">
                        <h2 style="margin: 0 0 6px; color: #001f3f; font-size: 22px;">Support Desk</h2>
                        <p style="margin: 0; color: #64748b; font-size: 14px;">Contact administrative support regarding subscriptions, AI policy scanning, or system issues.</p>
                    </div>

                    <?php if (empty($tickets)): ?>
                        <div style="text-align: center; padding: 60px 20px; background: white; border-radius: 12px; border: 1px solid #e2e8f0; color: #94a3b8;">
                            <i class="fas fa-headset" style="font-size: 38px; margin-bottom: 12px; color: #cbd5e1;"></i>
                            <h3 style="margin: 0; color: #475569;">No Support Tickets</h3>
                            <p style="margin: 6px 0 18px; font-size: 13.5px;">Have questions about your subscription or features? Open a ticket below.</p>
                            <button type="button" class="btn btn-primary" onclick="openCreateTicketModal()">
                                <i class="fas fa-plus"></i> Open New Ticket
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($tickets as $t): ?>
                            <a href="support_tickets.php?id=<?php echo $t['id']; ?>" class="ticket-card">
                                <div>
                                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                        <span class="badge badge-status-<?php echo $t['status']; ?>" style="text-transform: capitalize; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 700;">
                                            <?php echo str_replace('_', ' ', $t['status']); ?>
                                        </span>
                                        <span style="font-size: 12px; color: #94a3b8;"><?php echo htmlspecialchars($t['ticket_number']); ?></span>
                                    </div>
                                    <div style="font-weight: 700; font-size: 16px; color: #0f172a;">
                                        <?php echo htmlspecialchars($t['subject']); ?>
                                    </div>
                                    <div style="font-size: 13px; color: #64748b; margin-top: 2px;">
                                        Category: <strong><?php echo ucfirst($t['category']); ?></strong> | Priority: <strong><?php echo ucfirst($t['priority']); ?></strong>
                                    </div>
                                </div>
                                <div style="text-align: right; color: #64748b; font-size: 13px;">
                                    <div>Last Activity: <?php echo date('d M Y, h:i A', strtotime($t['last_reply_at'] ?: $t['created_at'])); ?></div>
                                    <span style="color: #0074D9; font-weight: 600;">View Thread <i class="fas fa-chevron-right" style="font-size: 11px;"></i></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Create Ticket Modal -->
    <div id="createTicketModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 12px; width: 560px; max-width: 92%; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                <h3 style="margin: 0; color: #001f3f; font-size: 18px;"><i class="fas fa-ticket"></i> Open New Support Ticket</h3>
                <button type="button" onclick="closeCreateTicketModal()" style="border: none; background: none; font-size: 20px; cursor: pointer; color: #94a3b8;">&times;</button>
            </div>
            <form method="POST" action="support_tickets.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="create_ticket">

                <div class="form-group" style="margin-bottom: 14px;">
                    <label style="font-size: 13px; font-weight: 600;">Subject *</label>
                    <input type="text" name="subject" required placeholder="Brief description of your issue" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div class="form-group">
                        <label style="font-size: 13px; font-weight: 600;">Category</label>
                        <select name="category" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                            <option value="general">General Inquiry</option>
                            <option value="billing">Billing & Subscription</option>
                            <option value="ai_scanner">AI Policy Scanner</option>
                            <option value="whatsapp">WhatsApp Notifications</option>
                            <option value="policy">Policy Management</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="font-size: 13px; font-weight: 600;">Priority</label>
                        <select name="priority" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-size: 13px; font-weight: 600;">Detailed Message *</label>
                    <textarea name="message" rows="4" required placeholder="Explain the problem or request in detail..." style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;"></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="closeCreateTicketModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Submit Ticket</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openCreateTicketModal() {
        document.getElementById('createTicketModal').style.display = 'flex';
    }
    function closeCreateTicketModal() {
        document.getElementById('createTicketModal').style.display = 'none';
    }
    </script>
</body>
</html>
