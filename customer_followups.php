<?php
/**
 * Customer Renewal Follow-ups CRM Pipeline
 * Insurance Management System
 */
require_once 'config.php';

// Auth check
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout()) { header("Location: login.php"); exit(); }

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = (int)$_SESSION['user_id'];
$current_page = 'customer_followups';

$conn = getDBConnection();
$aid = ($role === 'agent') ? agentIdForUser($conn, $user_id) : 0;
$branding = getBranding();
$currency = $branding['currency_symbol'] ?? '₹';

// Handle AJAX actions
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    if ($_GET['action'] === 'getFollowups') {
        $stage = trim($_GET['stage'] ?? '');
        $search = trim($_GET['search'] ?? '');

        $sql = "SELECT cf.*, c.name AS customer_name, c.mobile AS customer_mobile, c.email AS customer_email,
                       pe.policy_number, pe.company_name, pe.policy_end_date, pe.total_premium_amount,
                       pe.vehicle_number, pe.plan_name, DATEDIFF(pe.policy_end_date, CURDATE()) AS days_left
                FROM customer_followups cf
                JOIN customers c ON c.id = cf.customer_id
                LEFT JOIN policy_entries pe ON pe.id = cf.policy_entry_id
                WHERE 1=1";

        if ($role === 'agent') {
            $sql .= " AND cf.agent_id = " . (int)$aid;
        }
        if (!empty($stage)) {
            $sql .= " AND cf.followup_stage = '" . $conn->real_escape_string($stage) . "'";
        }
        if (!empty($search)) {
            $s = $conn->real_escape_string($search);
            $sql .= " AND (c.name LIKE '%$s%' OR c.mobile LIKE '%$s%' OR pe.policy_number LIKE '%$s%')";
        }

        $sql .= " ORDER BY cf.is_completed ASC, cf.next_followup_date ASC, cf.id DESC LIMIT 200";
        $res = $conn->query($sql);
        $data = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $data[] = $r;
            }
        }
        echo json_encode(['success' => true, 'data' => $data]);
        exit();
    }

    if ($_GET['action'] === 'updateFollowup') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid method']);
            exit();
        }
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit();
        }

        $id = (int)($_POST['id'] ?? 0);
        $stage = trim($_POST['followup_stage'] ?? 'new_renewal');
        $notes = trim($_POST['call_notes'] ?? '');
        $nextDate = trim($_POST['next_followup_date'] ?? '');
        $isCompleted = ($stage === 'renewed' || $stage === 'lost') ? 1 : 0;

        $upd = $conn->prepare("UPDATE customer_followups SET followup_stage = ?, call_notes = ?, next_followup_date = ?, is_completed = ? WHERE id = ?" . ($role === 'agent' ? " AND agent_id = $aid" : ""));
        $nVal = !empty($nextDate) ? $nextDate : null;
        $upd->bind_param("sssii", $stage, $notes, $nVal, $isCompleted, $id);
        $ok = $upd->execute();
        $upd->close();

        echo json_encode(['success' => $ok]);
        exit();
    }
}

// Page setup variables for header & sidebar
$pageTitle = 'Renewal Follow-ups CRM';
$pageIcon = 'fa-headset';
$crumbs = [['label' => 'CRM', 'icon' => 'fa-users'], ['label' => 'Follow-ups']];
$pageActions = [
    ['label' => 'Upload & Scan Policy', 'icon' => 'fa-file-arrow-up', 'onclick' => "window.location='policy_upload.php'", 'class' => 'primary']
];

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
        .crm-pipeline-bar {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .pipeline-pill {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 8px 18px;
            font-size: 13.5px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .pipeline-pill:hover, .pipeline-pill.active {
            background: #001f3f;
            color: white;
            border-color: #001f3f;
        }
        .pipeline-pill .badge {
            background: rgba(0,0,0,0.08);
            border-radius: 12px;
            padding: 2px 7px;
            font-size: 11px;
        }
        .pipeline-pill.active .badge {
            background: rgba(255,255,255,0.25);
            color: white;
        }
        .followup-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 14px;
            transition: all 0.2s;
        }
        .followup-card:hover {
            box-shadow: 0 4px 14px rgba(0,0,0,0.06);
            border-color: #cbd5e1;
        }
        .stage-badge {
            font-size: 12px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 12px;
            text-transform: capitalize;
            display: inline-block;
        }
        .stage-new_renewal { background: #e0f2fe; color: #0369a1; }
        .stage-contacted { background: #fef3c7; color: #b45309; }
        .stage-interested { background: #fdf2f8; color: #be185d; }
        .stage-quote_sent { background: #ede9fe; color: #6d28d9; }
        .stage-negotiation { background: #ffedd5; color: #c2410c; }
        .stage-renewed { background: #dcfce7; color: #15803d; }
        .stage-lost { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body class="dashboard-page">
    <?php include 'mobile-menu.php'; ?>
    <div class="dashboard-layout">
        <?php include 'sidebar.php'; ?>
        <main class="main-content">
            <?php include 'page_header.php'; ?>

            <div class="content-wrapper" style="padding: 20px;">
                <div class="crm-pipeline-bar">
                    <button type="button" class="pipeline-pill active" onclick="filterStage('', this)">All Follow-ups</button>
                    <button type="button" class="pipeline-pill" onclick="filterStage('new_renewal', this)">New Renewals</button>
                    <button type="button" class="pipeline-pill" onclick="filterStage('contacted', this)">Contacted</button>
                    <button type="button" class="pipeline-pill" onclick="filterStage('interested', this)">Interested</button>
                    <button type="button" class="pipeline-pill" onclick="filterStage('quote_sent', this)">Quote Sent</button>
                    <button type="button" class="pipeline-pill" onclick="filterStage('negotiation', this)">Negotiation</button>
                    <button type="button" class="pipeline-pill" onclick="filterStage('renewed', this)">Renewed</button>
                    <button type="button" class="pipeline-pill" onclick="filterStage('lost', this)">Lost</button>
                </div>

                <div style="display: flex; gap: 15px; margin-bottom: 20px;">
                    <div style="flex: 1;">
                        <input type="search" id="searchInput" placeholder="Search by customer name, mobile, or policy number..." oninput="debounceLoad()" style="width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 14px;">
                    </div>
                </div>

                <div id="followupsList">
                    <div style="text-align: center; padding: 40px; color: #94a3b8;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i> Loading follow-ups...
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Edit Follow-up Modal -->
    <div id="editModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 12px; width: 500px; max-width: 92%; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;">
                <h3 style="margin: 0; color: #001f3f; font-size: 18px;"><i class="fas fa-pen-to-square"></i> Update Customer Follow-up</h3>
                <button type="button" onclick="closeEditModal()" style="border: none; background: none; font-size: 20px; cursor: pointer; color: #94a3b8;">&times;</button>
            </div>
            <form id="editForm" onsubmit="saveFollowup(event)">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="id" id="modalId">

                <div class="form-group" style="margin-bottom: 14px;">
                    <label style="font-size: 13px; font-weight: 600;">Follow-up Stage</label>
                    <select name="followup_stage" id="modalStage" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                        <option value="new_renewal">New Renewal</option>
                        <option value="contacted">Contacted / Called</option>
                        <option value="interested">Interested / Requested Info</option>
                        <option value="quote_sent">Renewal Quote Sent</option>
                        <option value="negotiation">Price Negotiation</option>
                        <option value="renewed">Successfully Renewed</option>
                        <option value="lost">Lost / Non-Renewed</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label style="font-size: 13px; font-weight: 600;">Next Callback Date</label>
                    <input type="date" name="next_followup_date" id="modalDate" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-size: 13px; font-weight: 600;">Call Notes / Discussion Summary</label>
                    <textarea name="call_notes" id="modalNotes" rows="3" placeholder="Enter customer response, requested discounts, or preferred callback time..." style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;"></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="closeEditModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    let activeStage = '';
    let debounceTimer;

    function filterStage(stage, btn) {
        activeStage = stage;
        document.querySelectorAll('.pipeline-pill').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        loadFollowups();
    }

    function debounceLoad() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(loadFollowups, 300);
    }

    function loadFollowups() {
        const search = document.getElementById('searchInput').value;
        const container = document.getElementById('followupsList');

        fetch(`customer_followups.php?action=getFollowups&stage=${encodeURIComponent(activeStage)}&search=${encodeURIComponent(search)}`)
            .then(res => res.json())
            .then(res => {
                if (!res.success || !res.data.length) {
                    container.innerHTML = '<div style="text-align:center;padding:50px;color:#94a3b8;background:white;border-radius:12px;border:1px solid #e2e8f0;"><i class="fas fa-inbox" style="font-size:32px;margin-bottom:10px;"></i><p style="margin:0;">No renewal follow-ups found matching your filter.</p></div>';
                    return;
                }

                let html = '';
                res.data.forEach(item => {
                    const days = parseInt(item.days_left);
                    let expiryBadge = '';
                    if (days < 0) {
                        expiryBadge = `<span style="color:#ef4444;font-weight:700;"><i class="fas fa-exclamation-triangle"></i> Expired ${Math.abs(days)} days ago</span>`;
                    } else if (days <= 7) {
                        expiryBadge = `<span style="color:#ea580c;font-weight:700;"><i class="fas fa-clock"></i> Expires in ${days} days!</span>`;
                    } else {
                        expiryBadge = `<span style="color:#16a34a;"><i class="fas fa-calendar"></i> Expires in ${days} days</span>`;
                    }

                    const waLink = `https://wa.me/91${item.customer_mobile}?text=${encodeURIComponent('Hello ' + item.customer_name + ', your policy ' + (item.policy_number || '') + ' is up for renewal. Let us know if you would like us to process the renewal quote.')}`;

                    html += `
                    <div class="followup-card">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
                            <div>
                                <span class="stage-badge stage-${item.followup_stage}">${item.followup_stage.replace('_', ' ')}</span>
                                <h3 style="margin:6px 0 2px;color:#0f172a;font-size:17px;">
                                    ${escapeHtml(item.customer_name)}
                                </h3>
                                <div style="font-size:13.5px;color:#64748b;">
                                    <i class="fas fa-phone"></i> ${escapeHtml(item.customer_mobile)} 
                                    ${item.customer_email ? `| <i class="fas fa-envelope"></i> ${escapeHtml(item.customer_email)}` : ''}
                                </div>
                            </div>

                            <div style="text-align:right;">
                                <div style="font-size:14px;font-weight:700;color:#001f3f;">
                                    ${item.policy_number ? escapeHtml(item.policy_number) : 'Pending Policy'}
                                </div>
                                <div style="font-size:12.5px;color:#64748b;">
                                    ${item.company_name ? escapeHtml(item.company_name) : ''} 
                                    ${item.vehicle_number ? `(${escapeHtml(item.vehicle_number)})` : ''}
                                </div>
                                <div style="font-size:13px;margin-top:2px;">
                                    ${expiryBadge}
                                </div>
                            </div>
                        </div>

                        ${item.call_notes ? `
                            <div style="margin:12px 0;background:#f8fafc;padding:10px 14px;border-radius:6px;font-size:13px;color:#475569;border-left:3px solid #0074D9;">
                                <strong>Call Notes:</strong> ${escapeHtml(item.call_notes)}
                            </div>
                        ` : ''}

                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9;flex-wrap:wrap;gap:10px;">
                            <div style="font-size:12.5px;color:#64748b;">
                                ${item.next_followup_date ? `<strong>Next Callback:</strong> ${item.next_followup_date}` : 'No callback scheduled'}
                            </div>

                            <div style="display:flex;gap:8px;">
                                <a href="${waLink}" target="_blank" rel="noopener" class="btn btn-secondary" style="padding:6px 12px;font-size:13px;border-radius:6px;color:#25d366;text-decoration:none;">
                                    <i class="fab fa-whatsapp"></i> WhatsApp
                                </a>
                                <button type="button" class="btn btn-secondary" onclick="openEditModal(${item.id}, '${item.followup_stage}', '${item.next_followup_date || ''}', '${escapeAttr(item.call_notes || '')}')" style="padding:6px 12px;font-size:13px;border-radius:6px;">
                                    <i class="fas fa-edit"></i> Update Stage
                                </button>
                                ${item.policy_entry_id ? `
                                    <a href="policy_upload.php?renew_prev_id=${item.policy_entry_id}&customer_id=${item.customer_id}" class="btn btn-primary" style="padding:6px 14px;font-size:13px;border-radius:6px;text-decoration:none;background:#10b981;border-color:#059669;">
                                        <i class="fas fa-arrows-rotate"></i> Upload Renewed Policy
                                    </a>
                                ` : ''}
                            </div>
                        </div>
                    </div>`;
                });
                container.innerHTML = html;
            });
    }

    function openEditModal(id, stage, date, notes) {
        document.getElementById('modalId').value = id;
        document.getElementById('modalStage').value = stage;
        document.getElementById('modalDate').value = date;
        document.getElementById('modalNotes').value = notes;
        document.getElementById('editModal').style.display = 'flex';
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    function saveFollowup(e) {
        e.preventDefault();
        const form = document.getElementById('editForm');
        const formData = new FormData(form);

        fetch('customer_followups.php?action=updateFollowup', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                closeEditModal();
                loadFollowups();
            } else {
                alert(res.message || 'Error updating follow-up');
            }
        });
    }

    function escapeHtml(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function escapeAttr(str) {
        return (str || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
    }

    document.addEventListener('DOMContentLoaded', loadFollowups);
    </script>
</body>
</html>
