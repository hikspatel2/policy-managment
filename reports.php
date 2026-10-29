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
$current_page = 'reports';

// agent cant access
if ($role === 'agent') { header("Location: dashboard.php"); exit(); }

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // sane date range — default last 90 days
    $from = $_GET['from'] ?? '';
    $to   = $_GET['to'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-90 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to = date('Y-m-d');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            // dropdown options for filters
            case 'getFilterLists':
                $branches = [];
                $r = $conn->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name");
                while ($row = $r->fetch_assoc()) $branches[] = $row;

                $agents = [];
                $r = $conn->query("SELECT id, name, agent_code FROM agents WHERE is_active=1 ORDER BY name");
                while ($row = $r->fetch_assoc()) $agents[] = $row;

                $buyers = [];
                $r = $conn->query("SELECT id, company_name FROM buyers WHERE is_active=1 ORDER BY company_name");
                while ($row = $r->fetch_assoc()) $buyers[] = $row;

                $conn->close();
                echo json_encode(['success' => true, 'branches' => $branches, 'agents' => $agents, 'buyers' => $buyers]);
                exit();

            // report 1: agent commission payout — premium + commission earned vs paid
            case 'reportAgentPayout':
                $agentId = (int)($_GET['agent_id'] ?? 0);

                // earned side from policy entries (exclude cancelled), scoped by entry_date
                $sql = "SELECT a.id, a.name, a.agent_code,
                               COUNT(p.id) AS policy_count,
                               COALESCE(SUM(p.total_premium_amount),0) AS premium,
                               COALESCE(SUM(p.agent_commission_amount),0) AS comm_earned
                        FROM agents a
                        LEFT JOIN policy_entries p
                          ON p.agent_id = a.id AND p.status != 'cancelled'
                          AND p.entry_date BETWEEN ? AND ?
                        WHERE a.is_active = 1";
                $params = [$from, $to];
                $types = 'ss';
                if ($agentId) { $sql .= " AND a.id = ?"; $params[] = $agentId; $types .= 'i'; }
                $sql .= " GROUP BY a.id, a.name, a.agent_code ORDER BY comm_earned DESC";

                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($row = $res->fetch_assoc()) $rows[$row['id']] = $row + ['paid' => 0];
                $stmt->close();

                // paid side from agent_transactions — debit = money paid out to agent in range
                $sql2 = "SELECT agent_id, COALESCE(SUM(debit_amount),0) AS paid
                         FROM agent_transactions
                         WHERE transaction_type = 'payment_out'
                           AND transaction_date BETWEEN ? AND ?
                         GROUP BY agent_id";
                $stmt = $conn->prepare($sql2);
                $stmt->bind_param('ss', $from, $to);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    if (isset($rows[$row['agent_id']])) $rows[$row['agent_id']]['paid'] = (float)$row['paid'];
                }
                $stmt->close();

                $out = [];
                foreach ($rows as $r) {
                    $earned = (float)$r['comm_earned'];
                    $paid = (float)$r['paid'];
                    $out[] = [
                        'agent_code'   => $r['agent_code'],
                        'name'         => $r['name'],
                        'policy_count' => (int)$r['policy_count'],
                        'premium'      => (float)$r['premium'],
                        'comm_earned'  => $earned,
                        'paid'         => $paid,
                        'outstanding'  => $earned - $paid
                    ];
                }
                $conn->close();
                echo json_encode(['success' => true, 'data' => $out]);
                exit();

            // report 2: renewals / expiry worklist — policies ending in range
            case 'reportRenewals':
                $sql = "SELECT pe.id, pe.policy_number, pe.customer_name, pe.vehicle_number, pe.vehicle_type,
                               pe.agent_name, pe.total_premium_amount, pe.policy_end_date, pe.status, pe.last_reminder_at, pe.reminder_count,
                               DATEDIFF(pe.policy_end_date, CURDATE()) AS days_to_expiry,
                               EXISTS(SELECT 1 FROM policy_entries r WHERE r.previous_policy_id = pe.id) AS renewed
                        FROM policy_entries pe
                        WHERE pe.status != 'cancelled'
                          AND pe.policy_end_date BETWEEN ? AND ?
                        ORDER BY pe.policy_end_date ASC";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss', $from, $to);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($row = $res->fetch_assoc()) {
                    $row['total_premium_amount'] = (float)$row['total_premium_amount'];
                    $row['days_to_expiry'] = (int)$row['days_to_expiry'];
                    $row['renewed'] = (int)$row['renewed'];
                    $rows[] = $row;
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();

            // customer dues — we paid the insurer more than the customer has paid us (as of today, not range-bound)
            case 'reportCustomerDues':
                $rows = $conn->query("SELECT pe.id, pe.policy_number, pe.customer_name, COALESCE(NULLIF(c.whatsapp_number, ''), c.mobile) AS phone,
                                             pe.vehicle_number, pe.agent_name, pe.entry_date, pe.payment_out, pe.payment_in,
                                             pe.payment_out - pe.payment_in AS due, DATEDIFF(CURDATE(), pe.entry_date) AS age_days
                                      FROM policy_entries pe LEFT JOIN customers c ON c.id = pe.customer_id
                                      WHERE pe.status <> 'cancelled' AND pe.payment_out > pe.payment_in
                                      ORDER BY due DESC")->fetch_all(MYSQLI_ASSOC);
                foreach ($rows as &$r) foreach (['payment_out', 'payment_in', 'due'] as $k) $r[$k] = (float)$r[$k];
                unset($r);
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();

            // report 3: premium collection — booked premium grouped by day/month
            case 'reportPremiumCollection':
                $groupBy = ($_GET['group'] ?? 'month') === 'day' ? 'day' : 'month';
                $period = $groupBy === 'day'
                    ? "DATE_FORMAT(entry_date, '%Y-%m-%d')"
                    : "DATE_FORMAT(entry_date, '%Y-%m')";

                $sql = "SELECT $period AS period,
                               COUNT(*) AS policy_count,
                               COALESCE(SUM(total_premium_amount),0) AS premium,
                               COALESCE(SUM(net_premium_amount),0) AS net_premium
                        FROM policy_entries
                        WHERE status != 'cancelled'
                          AND entry_date BETWEEN ? AND ?
                        GROUP BY period ORDER BY period ASC";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss', $from, $to);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($row = $res->fetch_assoc()) {
                    $rows[] = [
                        'period'       => $row['period'],
                        'policy_count' => (int)$row['policy_count'],
                        'premium'      => (float)$row['premium'],
                        'net_premium'  => (float)$row['net_premium']
                    ];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows, 'group' => $groupBy]);
                exit();

            // report 4: branch performance — policy/premium/commission vs expenses
            case 'reportBranchPerformance':
                // policy aggregates per branch (exclude cancelled)
                $sql = "SELECT b.id, b.name,
                               COUNT(p.id) AS policy_count,
                               COALESCE(SUM(p.total_premium_amount),0) AS premium,
                               COALESCE(SUM(p.buyer_commission_amount),0) AS buyer_comm,
                               COALESCE(SUM(p.agent_commission_amount),0) AS agent_comm
                        FROM branches b
                        LEFT JOIN policy_entries p
                          ON p.branch_id = b.id AND p.status != 'cancelled'
                          AND p.entry_date BETWEEN ? AND ?
                        WHERE b.is_active = 1
                        GROUP BY b.id, b.name ORDER BY b.name";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ss', $from, $to);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($row = $res->fetch_assoc()) {
                    $rows[$row['id']] = [
                        'name'         => $row['name'],
                        'policy_count' => (int)$row['policy_count'],
                        'premium'      => (float)$row['premium'],
                        'buyer_comm'   => (float)$row['buyer_comm'],
                        'agent_comm'   => (float)$row['agent_comm'],
                        'expenses'     => 0.0
                    ];
                }
                $stmt->close();

                // expenses per branch in range
                $sql2 = "SELECT branch_id, COALESCE(SUM(amount),0) AS exp
                         FROM expenses
                         WHERE approval_status = 'approved' AND expense_date BETWEEN ? AND ?
                         GROUP BY branch_id";
                $stmt = $conn->prepare($sql2);
                $stmt->bind_param('ss', $from, $to);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    if (isset($rows[$row['branch_id']])) $rows[$row['branch_id']]['expenses'] = (float)$row['exp'];
                }
                $stmt->close();

                $out = [];
                foreach ($rows as $r) {
                    // net = our commission spread minus expenses
                    $r['net'] = ($r['buyer_comm'] - $r['agent_comm']) - $r['expenses'];
                    $out[] = $r;
                }
                $conn->close();
                echo json_encode(['success' => true, 'data' => $out]);
                exit();

            // report 5: expense vs income P&L
            case 'reportProfitLoss':
                // income = buyer commission earned (exclude cancelled)
                $stmt = $conn->prepare("SELECT COALESCE(SUM(buyer_commission_amount),0) AS v
                                        FROM policy_entries
                                        WHERE status != 'cancelled' AND entry_date BETWEEN ? AND ?");
                $stmt->bind_param('ss', $from, $to);
                $stmt->execute();
                $buyerComm = (float)$stmt->get_result()->fetch_assoc()['v'];
                $stmt->close();

                // agent commission expense
                $stmt = $conn->prepare("SELECT COALESCE(SUM(agent_commission_amount),0) AS v
                                        FROM policy_entries
                                        WHERE status != 'cancelled' AND entry_date BETWEEN ? AND ?");
                $stmt->bind_param('ss', $from, $to);
                $stmt->execute();
                $agentComm = (float)$stmt->get_result()->fetch_assoc()['v'];
                $stmt->close();

                // operating expenses
                $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS v
                                        FROM expenses WHERE approval_status = 'approved' AND expense_date BETWEEN ? AND ?");
                $stmt->bind_param('ss', $from, $to);
                $stmt->execute();
                $expenses = (float)$stmt->get_result()->fetch_assoc()['v'];
                $stmt->close();

                // other income
                $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS v
                                        FROM other_income WHERE income_date BETWEEN ? AND ?");
                $stmt->bind_param('ss', $from, $to);
                $stmt->execute();
                $otherIncome = (float)$stmt->get_result()->fetch_assoc()['v'];
                $stmt->close();

                $totalIncome = $buyerComm + $otherIncome;
                $totalDeduct = $agentComm + $expenses;
                $netProfit = $totalIncome - $totalDeduct;

                $conn->close();
                echo json_encode(['success' => true, 'data' => [
                    'buyer_comm'   => $buyerComm,
                    'other_income' => $otherIncome,
                    'total_income' => $totalIncome,
                    'agent_comm'   => $agentComm,
                    'expenses'     => $expenses,
                    'total_deduct' => $totalDeduct,
                    'net_profit'   => $netProfit
                ]]);
                exit();

            // report 6: buyer commission — receivable vs received
            case 'reportBuyerCommission':
                $buyerId = (int)($_GET['buyer_id'] ?? 0);

                // receivable side from policy entries
                $sql = "SELECT b.id, b.company_name, b.company_code,
                               COUNT(p.id) AS policy_count,
                               COALESCE(SUM(p.total_premium_amount),0) AS premium,
                               COALESCE(SUM(p.buyer_commission_amount),0) AS receivable
                        FROM buyers b
                        LEFT JOIN policy_entries p
                          ON p.buyer_id = b.id AND p.status != 'cancelled'
                          AND p.entry_date BETWEEN ? AND ?
                        WHERE b.is_active = 1";
                $params = [$from, $to];
                $types = 'ss';
                if ($buyerId) { $sql .= " AND b.id = ?"; $params[] = $buyerId; $types .= 'i'; }
                $sql .= " GROUP BY b.id, b.company_name, b.company_code ORDER BY receivable DESC";

                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $res = $stmt->get_result();
                $rows = [];
                while ($row = $res->fetch_assoc()) $rows[$row['id']] = $row + ['received' => 0];
                $stmt->close();

                // received side from buyer_transactions — credit on payment_received
                $sql2 = "SELECT buyer_id, COALESCE(SUM(credit_amount),0) AS received
                         FROM buyer_transactions
                         WHERE transaction_type = 'payment_received'
                           AND transaction_date BETWEEN ? AND ?
                         GROUP BY buyer_id";
                $stmt = $conn->prepare($sql2);
                $stmt->bind_param('ss', $from, $to);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    if (isset($rows[$row['buyer_id']])) $rows[$row['buyer_id']]['received'] = (float)$row['received'];
                }
                $stmt->close();

                $out = [];
                foreach ($rows as $r) {
                    $rec = (float)$r['receivable'];
                    $got = (float)$r['received'];
                    $out[] = [
                        'company_code' => $r['company_code'],
                        'company_name' => $r['company_name'],
                        'policy_count' => (int)$r['policy_count'],
                        'premium'      => (float)$r['premium'],
                        'receivable'   => $rec,
                        'received'     => $got,
                        'outstanding'  => $rec - $got
                    ];
                }
                $conn->close();
                echo json_encode(['success' => true, 'data' => $out]);
                exit();

            default:
                $conn->close();
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("reports.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
        exit();
    }
}

// default date range for the UI — last 90 days
$defFrom = date('Y-m-d', strtotime('-90 days'));
$defTo   = date('Y-m-d');
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
    <title>Reports - Insurance Management System</title>

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
            $pageIcon    = 'fa-chart-bar';
            $pageTitle   = 'Reports';
            $crumbs      = [['label' => 'Reports', 'icon' => 'fa-chart-pie']];
            $pageActions = [
                ['label' => 'Run Report', 'icon' => 'fa-play', 'onclick' => 'runReport()', 'class' => 'primary'],
                ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(rptTable,'csv')"],
                ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(rptTable,'pdf')"],
                ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(rptTable,'print')"],
            ];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="rptSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="rptSearch" placeholder="Search report…" autocomplete="off"></div>
                <div class="hf-field"><label for="rptPicker" title="Report"><i class="fas fa-file-lines"></i></label><select id="rptPicker" class="filter-input searchable">
                    <option value="agentPayout">Agent Payout</option>
                    <option value="renewals">Renewals / Expiry</option>
                    <option value="premium">Premium Collection</option>
                    <option value="branch">Branch Performance</option>
                    <option value="pl">Expense vs Income</option>
                    <option value="buyer">Buyer Commission</option>
                    <option value="dues">Customer Dues</option>
                </select></div>
                <div class="hf-field hf-range" title="Date range"><label for="fltFrom"><i class="fas fa-calendar-days"></i></label><input type="date" id="fltFrom" aria-label="From" value="<?php echo $defFrom; ?>"><span class="hf-sep"><i class="fas fa-arrow-right-long"></i></span><input type="date" id="fltTo" aria-label="To" value="<?php echo $defTo; ?>"></div>
                <div class="hf-seg" role="group" aria-label="Quick range"><button type="button" onclick="rsDateRange('fltFrom','fltTo',0,this)">Today</button><button type="button" onclick="rsDateRange('fltFrom','fltTo',6,this)">7 days</button><button type="button" onclick="rsDateRange('fltFrom','fltTo',29,this)">30 days</button></div>
                <!-- agent filter — only for agent payout -->
                <div class="hf-field" id="fgAgent" style="display:none;"><label for="fltAgent" title="Agent"><i class="fas fa-user-tag"></i></label><select id="fltAgent" class="filter-input searchable">
                    <option value="">All Agents</option>
                </select></div>
                <!-- buyer filter — only for buyer commission -->
                <div class="hf-field" id="fgBuyer" style="display:none;"><label for="fltBuyer" title="Buyer / Insurer"><i class="fas fa-building"></i></label><select id="fltBuyer" class="filter-input searchable">
                    <option value="">All Buyers</option>
                </select></div>
                <!-- group-by — only for premium collection -->
                <div class="hf-field" id="fgGroup" style="display:none;"><label for="fltGroup" title="Group By"><i class="fas fa-layer-group"></i></label><select id="fltGroup" class="filter-input searchable">
                    <option value="month">By Month</option>
                    <option value="day">By Day</option>
                </select></div>
            </div>
            <script>rsPullHeadFilters();</script>

            <!-- KPI summary strip — current snapshot -->
            <div class="dashboard-grid-4" id="kpiStrip">
                <div class="stat-card">
                    <div class="stat-card-icon"><i class="fas fa-file-invoice"></i></div>
                    <div class="stat-card-value" id="kpiPolicies">-</div>
                    <div class="stat-card-label">Policies in Range</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="stat-card-value" id="kpiPremium">-</div>
                    <div class="stat-card-label">Premium in Range</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="stat-card-value" id="kpiSpread">-</div>
                    <div class="stat-card-label">Commission Spread</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon"><i class="fas fa-coins"></i></div>
                    <div class="stat-card-value" id="kpiNet">-</div>
                    <div class="stat-card-label">Net Profit (P&L)</div>
                </div>
            </div>

            <div class="data-section">
                <!-- Report title bar -->
                <div class="section-header">
                    <h2><i class="fas fa-table" id="rptIcon"></i> <span id="rptTitle">Agent Commission Payout</span></h2>
                    <span id="rptRangeLbl" class="status-badge status-active"></span>
                </div>

                <!-- Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- Report table -->
                <div id="tableContainer" style="display:none;">
                    <div id="reportTable"></div>
                    <div id="plBox"></div>
                </div>

                <!-- Empty state -->
                <div id="emptyBox" style="display:none;"></div>
            </div>
        </div>
    </div>

    <script>
        /** Developed by Mohammad Rameez Imdad (Rameez Scripts) */

        var rptTable = null;          // active report table
        var currentReport = 'agentPayout';
        var filterLists = { agents: [], buyers: [] };

        // report config map — title/icon/action/columns
        var REPORTS = {
            agentPayout: {
                title: 'Agent Commission Payout', icon: 'fa-user-tie',
                action: 'reportAgentPayout'
            },
            renewals: {
                title: 'Policy Renewals / Expiry', icon: 'fa-calendar-times',
                action: 'reportRenewals'
            },
            premium: {
                title: 'Premium Collection', icon: 'fa-money-check-alt',
                action: 'reportPremiumCollection'
            },
            branch: {
                title: 'Branch Performance', icon: 'fa-code-branch',
                action: 'reportBranchPerformance'
            },
            pl: {
                title: 'Expense vs Income (P&L)', icon: 'fa-balance-scale',
                action: 'reportProfitLoss'
            },
            buyer: {
                title: 'Buyer Commission', icon: 'fa-building',
                action: 'reportBuyerCommission'
            },
            dues: {
                title: 'Customer Dues', icon: 'fa-hand-holding-dollar',
                action: 'reportCustomerDues', asOfToday: true
            }
        };

        function cur(v) {
            return '₹' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function fmt(v) {
            return Number(v || 0).toLocaleString('en-IN');
        }

        $(document).ready(function() {
            // report picker
            $('#rptPicker').on('change', function() {
                currentReport = this.value || 'agentPayout';
                syncFilterVisibility();
                runReport();
            });

            // ?report=dues deep link (dashboard)
            var want = new URLSearchParams(location.search).get('report');
            if (want && REPORTS[want]) {
                currentReport = want;
                var pk = document.getElementById('rptPicker');
                pk.sdSetVal ? pk.sdSetVal(want) : (pk.value = want);
            }

            // load filter dropdowns then first report
            $.getJSON('?action=getFilterLists', function(res) {
                if (res && res.success) {
                    filterLists.agents = res.agents || [];
                    filterLists.buyers = res.buyers || [];
                    fillAgentSelect();
                    fillBuyerSelect();
                }
                syncFilterVisibility();
                runReport();
            }).fail(function() {
                syncFilterVisibility();
                runReport();
            });
        });

        function fillAgentSelect() {
            var $s = $('#fltAgent');
            filterLists.agents.forEach(function(a) {
                $s.append('<option value="' + a.id + '">' + escapeHtml(a.name + ' (' + a.agent_code + ')') + '</option>');
            });
            if ($s[0] && $s[0].sdRefresh) $s[0].sdRefresh();
        }
        function fillBuyerSelect() {
            var $s = $('#fltBuyer');
            filterLists.buyers.forEach(function(b) {
                $s.append('<option value="' + b.id + '">' + escapeHtml(b.company_name) + '</option>');
            });
            if ($s[0] && $s[0].sdRefresh) $s[0].sdRefresh();
        }

        // show only the entity filter relevant to the active report
        function syncFilterVisibility() {
            $('#fgAgent').toggle(currentReport === 'agentPayout');
            $('#fgBuyer').toggle(currentReport === 'buyer');
            $('#fgGroup').toggle(currentReport === 'premium');
            $('#fltFrom').closest('.hf-range').next('.hf-seg').addBack().toggle(!REPORTS[currentReport].asOfToday); // dues = today's position
            setTimeout(function(){ initAllSearchableDropdowns(); }, 50);
        }

        function getRange() {
            var from = $('#fltFrom').val(), to = $('#fltTo').val();
            if (from && to && from > to) {
                Swal.fire({ icon: 'warning', title: 'Invalid Range', text: 'From date cannot be after To date' });
                return null;
            }
            return { from: from, to: to };
        }

        function runReport() {
            var rng = getRange();
            if (!rng) return;

            var cfg = REPORTS[currentReport];
            $('#rptTitle').text(cfg.title);
            $('#rptIcon').attr('class', 'fas ' + cfg.icon);
            $('#rptRangeLbl').text(cfg.asOfToday ? 'As of ' + new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : (rng.from || '—') + '  to  ' + (rng.to || '—'));

            $('#tableContainer').hide();
            $('#emptyBox').hide();
            $('#loadingSkeleton').show();

            var params = { action: cfg.action, from: rng.from, to: rng.to };
            if (currentReport === 'agentPayout') params.agent_id = $('#fltAgent').val() || '';
            if (currentReport === 'buyer')      params.buyer_id = $('#fltBuyer').val() || '';
            if (currentReport === 'premium')    params.group = $('#fltGroup').val() || 'month';

            $.getJSON('?' + $.param(params), function(res) {
                $('#loadingSkeleton').hide();
                if (!res || !res.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Failed to load report' });
                    return;
                }
                renderReport(currentReport, res);
            }).fail(function() {
                $('#loadingSkeleton').hide();
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
            });
        }

        // drop the current grid + P&L box
        function resetReport() {
            if (rptTable) { rptTable.destroy(); rptTable = null; }
            $('#reportTable').empty().show();
            $('#plBox').empty();
        }

        // column set differs per report -> rebuild the grid each run
        function buildTable(cols, rows, orderCol, rowClass) {
            resetReport();
            var cfg = REPORTS[currentReport];
            rptTable = rsTable('#reportTable', {
                columns: cols, data: rows, title: cfg.title, file: currentReport,
                sort: orderCol != null ? [cols[orderCol].data, 'desc'] : null,
                rowClass: rowClass,
                info: function() { return [['Range', $('#rptRangeLbl').text()]]; }
            });
            rsTbSearch(rptTable, $('#rptSearch').val()); // keep head search
            $('#tableContainer').show();
        }

        function showEmpty(title, text) {
            resetReport();
            $('#tableContainer').hide();
            $('#emptyBox').html(emptyStateHtml({ icon: 'fa-chart-bar', title: title, text: text })).show();
        }

        // -- render dispatch --
        function renderReport(type, res) {
            var data = res.data || [];

            // P&L has no table grid — it's a breakdown
            if (type === 'pl') { renderPL(data); return; }

            if (!data.length) {
                showEmpty('No data for this range', 'Try widening the date range or changing filters.');
                updateKpiFromReport(type, data);
                return;
            }

            if (type === 'agentPayout')  renderAgentPayout(data);
            if (type === 'renewals')     renderRenewals(data);
            if (type === 'premium')      renderPremium(data, res.group);
            if (type === 'branch')       renderBranch(data);
            if (type === 'buyer')        renderBuyer(data);
            if (type === 'dues')         renderDues(data);

            updateKpiFromReport(type, data);
        }

        // report 1: agent payout
        function renderAgentPayout(data) {
            var cols = [
                { data: 'agent_code', title: 'Agent Code', render: function(d){ return escapeHtml(d); } },
                { data: 'name', title: 'Agent', render: function(d){ return escapeHtml(d); } },
                { data: 'policy_count', title: 'Policies' },
                { data: 'premium', title: 'Total Premium', render: cur },
                { data: 'comm_earned', title: 'Commission Earned', render: cur },
                { data: 'paid', title: 'Commission Paid', render: cur },
                { data: 'outstanding', title: 'Outstanding', render: function(d){
                    return '<strong style="color:' + (d > 0 ? '#ea4335' : '#34a853') + '">' + cur(d) + '</strong>'; } }
            ];
            buildTable(cols, data, 4);
        }

        // report 2: renewals / expiry
        function renderRenewals(data) {
            var cols = [
                { data: 'policy_number', title: 'Policy #', render: function(d){ return escapeHtml(d); } },
                { data: 'customer_name', title: 'Customer', render: function(d){ return escapeHtml(d); } },
                { data: 'vehicle_number', title: 'Vehicle', render: function(d, t, row){
                    return escapeHtml(d) + ' <small style="color:#999">' + escapeHtml(row.vehicle_type) + '</small>'; } },
                { data: 'agent_name', title: 'Agent', render: function(d){ return d ? escapeHtml(d) : '<em style="color:#999">-</em>'; } },
                { data: 'total_premium_amount', title: 'Premium', render: cur },
                { data: 'policy_end_date', title: 'End Date' },
                { data: 'days_to_expiry', title: 'Days to Expiry', render: function(d){
                    if (d < 0) return '<span style="color:#ea4335">Expired ' + Math.abs(d) + 'd ago</span>';
                    if (d === 0) return '<span style="color:#ea4335">Expires today</span>';
                    return d + ' days';
                } },
                { data: 'status', title: 'Status', render: function(d, t, row){
                    if (row.renewed) return t === 'display' ? '<span class="status-badge status-active">renewed</span>' : 'renewed';
                    return '<span class="status-badge status-' + escapeHtml(d) + '">' + escapeHtml(d) + '</span>'; } },
                { data: 'last_reminder_at', title: 'Reminded', render: function(d, t, row){
                    if (!d) return t === 'display' ? '<span class="text-muted">-</span>' : '';
                    return escapeHtml(String(d).slice(0, 10)) + (row.reminder_count > 1 ? ' (' + parseInt(row.reminder_count) + 'x)' : ''); } },
                { data: null, orderable: false, title: '', render: function(d, t, row){
                    return '<div class="action-hub">' + (row.renewed ? '' : '<a class="action-icon edit-icon" href="policy_entries.php?action=waReminder&amp;id=' + parseInt(row.id) + '" target="_blank" rel="noopener" title="WhatsApp renewal reminder" aria-label="WhatsApp renewal reminder" onclick="setTimeout(runReport, 1500)"><i class="fab fa-whatsapp"></i></a>')
                        + '<a class="action-icon view-icon" href="policy_entries.php?id=' + parseInt(row.id) + '" title="Open policy" aria-label="Open policy"><i class="fas fa-eye"></i></a></div>'; } }
            ];
            // row coloring — expired -> danger, expiring within 30 days -> warning
            buildTable(cols, data, 5, function(r) {
                var d = r.days_to_expiry;
                return d < 0 ? 'dt-row-danger' : (d <= 30 ? 'dt-row-warning' : '');
            });
        }

        // customer dues — paid to insurer minus received from customer
        function renderDues(data) {
            var cols = [
                { data: 'policy_number', title: 'Policy #', render: function(d){ return escapeHtml(d); } },
                { data: 'customer_name', title: 'Customer', render: function(d){ return escapeHtml(d); } },
                { data: 'phone', title: 'Mobile', render: function(d){ return escapeHtml(d || '-'); } },
                { data: 'vehicle_number', title: 'Vehicle', render: function(d){ return escapeHtml(d); } },
                { data: 'agent_name', title: 'Agent', render: function(d){ return escapeHtml(d || '-'); } },
                { data: 'payment_out', title: 'Paid to Insurer', render: cur },
                { data: 'payment_in', title: 'Received', render: cur },
                { data: 'due', title: 'Due', render: function(d, t){ return t === 'display' ? '<strong class="wallet-amount wallet-negative">' + cur(d) + '</strong>' : d; } },
                { data: 'age_days', title: 'Days Open', render: function(d){ return parseInt(d) + 'd'; } },
                { data: null, orderable: false, title: '', render: function(d, t, row){
                    return '<div class="action-hub"><a class="action-icon edit-icon" href="policy_entries.php?action=waReminder&amp;kind=due&amp;id=' + parseInt(row.id) + '" target="_blank" rel="noopener" title="WhatsApp dues reminder" aria-label="WhatsApp dues reminder"><i class="fab fa-whatsapp"></i></a>'
                        + '<a class="action-icon view-icon" href="policy_entries.php?id=' + parseInt(row.id) + '" title="Open policy" aria-label="Open policy"><i class="fas fa-eye"></i></a></div>'; } }
            ];
            buildTable(cols, data, 7, function(r) { return r.age_days > 30 ? 'dt-row-danger' : (r.age_days > 7 ? 'dt-row-warning' : ''); });
        }

        // report 3: premium collection
        function renderPremium(data, group) {
            var periodTitle = group === 'day' ? 'Day' : 'Month';
            var cols = [
                { data: 'period', title: periodTitle, render: function(d){ return escapeHtml(d); } },
                { data: 'policy_count', title: 'Policies' },
                { data: 'premium', title: 'Total Premium', render: cur },
                { data: 'net_premium', title: 'Net Premium', render: cur }
            ];
            buildTable(cols, data, 0);
        }

        // report 4: branch performance
        function renderBranch(data) {
            var cols = [
                { data: 'name', title: 'Branch', render: function(d){ return escapeHtml(d); } },
                { data: 'policy_count', title: 'Policies' },
                { data: 'premium', title: 'Total Premium', render: cur },
                { data: 'buyer_comm', title: 'Buyer Commission', render: cur },
                { data: 'agent_comm', title: 'Agent Commission', render: cur },
                { data: 'expenses', title: 'Expenses', render: cur },
                { data: 'net', title: 'Net', render: function(d){
                    return '<strong style="color:' + (d >= 0 ? '#34a853' : '#ea4335') + '">' + cur(d) + '</strong>'; } }
            ];
            buildTable(cols, data, 6);
        }

        // report 6: buyer commission
        function renderBuyer(data) {
            var cols = [
                { data: 'company_code', title: 'Code', render: function(d){ return escapeHtml(d); } },
                { data: 'company_name', title: 'Buyer / Insurer', render: function(d){ return escapeHtml(d); } },
                { data: 'policy_count', title: 'Policies' },
                { data: 'premium', title: 'Premium', render: cur },
                { data: 'receivable', title: 'Commission Receivable', render: cur },
                { data: 'received', title: 'Received', render: cur },
                { data: 'outstanding', title: 'Outstanding', render: function(d){
                    return '<strong style="color:' + (d > 0 ? '#ea4335' : '#34a853') + '">' + cur(d) + '</strong>'; } }
            ];
            buildTable(cols, data, 4);
        }

        // report 5: P&L breakdown — a 2-col plus/minus statement
        function renderPL(d) {
            resetReport();
            $('#reportTable').hide();
            $('#emptyBox').hide();

            var rows = [
                ['<i class="fas fa-plus-circle" style="color:#34a853"></i> Buyer Commission (income)', d.buyer_comm, 'pos'],
                ['<i class="fas fa-plus-circle" style="color:#34a853"></i> Other Income', d.other_income, 'pos'],
                ['<strong>Total Income</strong>', d.total_income, 'sub'],
                ['<i class="fas fa-minus-circle" style="color:#ea4335"></i> Agent Commission (expense)', d.agent_comm, 'neg'],
                ['<i class="fas fa-minus-circle" style="color:#ea4335"></i> Operating Expenses', d.expenses, 'neg'],
                ['<strong>Total Deductions</strong>', d.total_deduct, 'sub']
            ];
            var html = '<table class="report-top5-table"><thead><tr><th>Line Item</th><th>Amount</th></tr></thead><tbody>';
            rows.forEach(function(r) {
                var sign = r[2] === 'neg' ? '-' : '';
                var bg = r[2] === 'sub' ? ' style="background:#eef3f8;font-weight:700"' : '';
                html += '<tr' + bg + '><td>' + r[0] + '</td><td class="amount-cell">' + sign + cur(r[1]) + '</td></tr>';
            });
            var netColor = d.net_profit >= 0 ? '#34a853' : '#ea4335';
            html += '<tr style="background:var(--navy-primary,#001f3f);color:#fff">' +
                    '<td><strong style="color:#fff"><i class="fas fa-coins"></i> NET PROFIT</strong></td>' +
                    '<td class="amount-cell"><strong style="color:#fff">' + cur(d.net_profit) + '</strong></td></tr>';
            html += '</tbody></table>';

            $('#tableContainer').show();
            $('#plBox').html(
                '<div style="max-width:560px">' + html + '</div>' +
                '<p style="color:#999;margin-top:10px;font-size:13px">' +
                '<i class="fas fa-info-circle"></i> Net Profit = Total Income - Total Deductions. ' +
                'Commission figures exclude cancelled policies.</p>'
            );
            updateKpiFromReport('pl', d);
        }

        // refresh KPI strip from whatever report just ran
        function updateKpiFromReport(type, data) {
            if (type === 'pl') {
                $('#kpiNet').text(cur(data.net_profit));
                return;
            }
            if (type === 'agentPayout') {
                var pol = 0, prem = 0;
                data.forEach(function(r){ pol += r.policy_count; prem += r.premium; });
                $('#kpiPolicies').text(fmt(pol));
                $('#kpiPremium').text(cur(prem));
            }
            if (type === 'branch') {
                var pol = 0, prem = 0, spread = 0;
                data.forEach(function(r){ pol += r.policy_count; prem += r.premium; spread += (r.buyer_comm - r.agent_comm); });
                $('#kpiPolicies').text(fmt(pol));
                $('#kpiPremium').text(cur(prem));
                $('#kpiSpread').text(cur(spread));
            }
            if (type === 'premium') {
                var pol = 0, prem = 0;
                data.forEach(function(r){ pol += r.policy_count; prem += r.premium; });
                $('#kpiPolicies').text(fmt(pol));
                $('#kpiPremium').text(cur(prem));
            }
            if (type === 'buyer') {
                var prem = 0;
                data.forEach(function(r){ prem += r.premium; });
                $('#kpiPremium').text(cur(prem));
            }
            if (type === 'dues') {
                $('#kpiPolicies').text(fmt(data.length));
            }
        }

        // re-run report when range/entity filters change
        // quick ranges fire From + To together -> one run
        $('#fltFrom, #fltTo, #fltAgent, #fltBuyer, #fltGroup').on('change', function() {
            clearTimeout(window._rptT); window._rptT = setTimeout(runReport, 0);
        });

        // header search -> table
        $('#rptSearch').on('input', function(){ clearTimeout(this._t); var v=this.value; this._t=setTimeout(function(){ rsTbSearch(rptTable, v); },200); });
    </script>
</body>
</html>
