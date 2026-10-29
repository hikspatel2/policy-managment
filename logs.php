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

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Check session timeout
if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'agent';
$user_id = $_SESSION['user_id'];
$current_page = 'logs';

// Handle AJAX requests
if (isset($_GET['action']) && $_GET['action'] === 'getLogs') {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        // Admin sees all logs, regular users see only their own
        if ($role === 'admin' || $role === 'manager') {
            $stmt = $conn->prepare("SELECT * FROM activity_logs ORDER BY timestamp DESC");
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY timestamp DESC");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
        }

        $result = $stmt->get_result();
        $logs = [];

        while ($row = $result->fetch_assoc()) {
            $logs[] = [
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'username' => $row['username'],
                'action' => $row['action'],
                'details' => $row['details'],
                'ip_address' => $row['ip_address'],
                'timestamp' => date('M d, Y H:i:s', strtotime($row['timestamp'])),
                'timestamp_iso' => $row['timestamp']
            ];
        }

        $stmt->close();
        $conn->close();

        echo json_encode(['success' => true, 'data' => $logs]);
        exit();

    } catch (Exception $e) {
        error_log("Logs.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A server error occurred']);
        exit();
    }
}

// If we reach here, render the HTML page
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
    <title>Activity Logs - Dashboard System</title>

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
            $pageIcon    = 'fa-history';
            $pageTitle   = 'Activity Logs';
            $crumbs      = [$role === 'agent' ? ['label' => 'My Account', 'icon' => 'fa-user'] : ['label' => 'Reports', 'icon' => 'fa-chart-pie']];
            $pageActions = [
                ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadLogs()'],
                ['label' => 'CSV',     'icon' => 'fa-file-csv', 'onclick' => "dtBtn(logsTable,'csv')"],
                ['label' => 'PDF',     'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(logsTable,'pdf')"],
                ['label' => 'Print',   'icon' => 'fa-print',    'onclick' => "dtBtn(logsTable,'print')"],
            ];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="logsSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="logsSearch" placeholder="Search logs…" autocomplete="off"></div>
                <div class="hf-field hf-range" title="Date range"><label for="filterDateFrom"><i class="fas fa-calendar-days"></i></label><input type="date" id="filterDateFrom" aria-label="From"><span class="hf-sep"><i class="fas fa-arrow-right-long"></i></span><input type="date" id="filterDateTo" aria-label="To"></div>
                <div class="hf-seg" role="group" aria-label="Quick range"><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',0,this)">Today</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',6,this)">7 days</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',29,this)">30 days</button></div>
                        <div class="hf-field" title="Action type"><label for="filterAction"><i class="fas fa-tag"></i></label>
                            <select id="filterAction" class="searchable">
                                <option value="">All Actions</option>
                            </select>
                        </div>
                        <div class="hf-field" title="Module"><label for="filterModule"><i class="fas fa-cubes"></i></label>
                            <select id="filterModule" class="searchable">
                                <option value="">All Modules</option>
                                <option value="Policy">Policy</option>
                                <option value="Customer">Customer</option>
                                <option value="Agent">Agent</option>
                                <option value="Branch">Branch</option>
                                <option value="User">User</option>
                                <option value="Buyer">Buyer</option>
                                <option value="Expense">Expense</option>
                                <option value="Income">Income</option>
                                <option value="Bank">Bank</option>
                                <option value="Notification">Notification</option>
                                <option value="Todo">Task</option>
                                <option value="Vehicle">Vehicle</option>
                                <option value="Payout">Payout</option>
                                <option value="Auth">Login / Auth</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <?php if ($role === 'admin' || $role === 'manager'): ?>
                        <div class="hf-field" title="Username"><label for="filterUsername"><i class="fas fa-user"></i></label>
                            <select id="filterUsername" class="searchable">
                                <option value="">All Users</option>
                            </select>
                        </div>
                        <?php endif; ?>
                <button type="button" class="btn btn-icon" onclick="clearFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>

            <div class="data-section">

                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 2"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- table -->
                <div id="tableContainer" style="display: none;">
                    <div id="logsTable"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Detail Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="viewModalTitle"><i class="fas fa-eye"></i> Log Details</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
        </div>
    </div>

    <script>
        let logsTable;
        let logsData = [];
        let uniqueActions = [];
        let uniqueUsernames = [];

        // derive module from free-text action string
        function deriveModule(action) {
            var a = (action || '').toLowerCase();
            if (a.indexOf('polic') > -1) return 'Policy';
            if (a.indexOf('customer') > -1) return 'Customer';
            if (a.indexOf('agent') > -1) return 'Agent';
            if (a.indexOf('branch') > -1) return 'Branch';
            if (a.indexOf('buyer') > -1) return 'Buyer';
            if (a.indexOf('expense') > -1) return 'Expense';
            if (a.indexOf('income') > -1) return 'Income';
            if (a.indexOf('bank') > -1) return 'Bank';
            if (a.indexOf('notif') > -1) return 'Notification';
            if (a.indexOf('todo') > -1 || a.indexOf('task') > -1) return 'Todo';
            if (a.indexOf('vehicle') > -1) return 'Vehicle';
            if (a.indexOf('payout') > -1) return 'Payout';
            if (a.indexOf('user') > -1) return 'User';
            if (a.indexOf('login') > -1 || a.indexOf('logout') > -1 || a.indexOf('auth') > -1 || a.indexOf('password') > -1) return 'Auth';
            return 'Other';
        }

        $(document).ready(function() {
            loadLogs();
            // header search
            $('#logsSearch').on('input', function() { clearTimeout(this._t); var v = this.value; this._t = setTimeout(function() { rsTbSearch(logsTable, v); }, 200); });
        });

        function loadLogs() {
            $('#loadingSkeleton').show();
            $('#tableContainer').hide();

            $.ajax({
                url: '?action=getLogs',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logsData = response.data;

                        // Extract unique values for filters
                        uniqueActions = [...new Set(response.data.map(r => r.action).filter(Boolean))];
                        uniqueUsernames = [...new Set(response.data.map(r => r.username).filter(Boolean))];

                        // Populate filter dropdowns
                        populateFilters();

                        $('#loadingSkeleton').hide();
                        $('#tableContainer').show();

                        setTimeout(() => initializeDataTable(response.data), 100);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to load logs'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    $('#loadingSkeleton').hide();
                    Swal.fire({
                        icon: 'error',
                        title: 'Connection Error',
                        text: 'Could not connect to server. Please check console for details.'
                    });
                }
            });
        }

        function populateFilters() {
            // Populate action filter
            const actionSelect = document.getElementById('filterAction');
            actionSelect.innerHTML = '<option value="">All Actions</option>';
            uniqueActions.forEach(action => {
                const option = document.createElement('option');
                option.value = action;
                option.textContent = action;
                actionSelect.appendChild(option);
            });

            // Populate username filter (Admin only)
            <?php if ($role === 'admin' || $role === 'manager'): ?>
            const usernameSelect = document.getElementById('filterUsername');
            usernameSelect.innerHTML = '<option value="">All Users</option>';
            uniqueUsernames.forEach(username => {
                const option = document.createElement('option');
                option.value = username;
                option.textContent = username;
                usernameSelect.appendChild(option);
            });
            <?php endif; ?>

            // init / refresh searchable dropdowns
            setTimeout(function() {
                initAllSearchableDropdowns();
                document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
            }, 50);
        }

        function initializeDataTable(data) {
            if (logsTable) return rsTbData(logsTable, data); // build once, swap rows

            // audit view - exports only, no row selection
            logsTable = rsTable('#logsTable', {
                columns: [
                    { data: 'id', title: 'ID', width: '60px' },
                    { data: 'username', title: 'Username' },
                    { data: 'action', title: 'Action' },
                    {
                        data: 'details',
                        title: 'Details',
                        render: function(data) {
                            if (!data) return '<em class="text-muted">No details</em>';
                            var txt = data.length > 100 ? data.substring(0, 100) + '...' : data;
                            return escapeHtml(txt);
                        }
                    },
                    { data: 'ip_address', title: 'IP Address' },
                    { data: 'timestamp', title: 'Timestamp' },
                    {
                        // hidden col - module text stays searchable, never exported
                        data: null, name: 'module', title: 'Module', visible: false, exportable: false,
                        render: function(d, type, row) { return deriveModule(row.action); }
                    },
                    {
                        data: null, title: 'Actions', orderable: false, searchable: false,
                        render: function(d, type, row) {
                            return '<div class="action-hub"><button class="action-icon view-icon" data-act="view" data-id="' + row.id + '" title="View"><i class="fas fa-eye"></i></button></div>';
                        }
                    }
                ],
                data: data, title: 'Activity Logs', file: 'activity_logs', sort: ['id', 'desc'], bulk: false,
                info: function() {
                    var i = [['Action', $('#filterAction').val() || 'All'], ['Module', $('#filterModule').val() || 'All']];
                    if ($('#filterUsername').length) i.push(['User', $('#filterUsername').val() || 'All']);
                    return i;
                }
            });
            rsTbSearch(logsTable, $('#logsSearch').val()); // keep header search

            $('#filterDateFrom, #filterDateTo, #filterAction, #filterModule, #filterUsername').off('change').on('change', applyFilters);

            // view full details
            $('#logsTable').off('click', '[data-act="view"]').on('click', '[data-act="view"]', function() {
                var id = parseInt($(this).data('id'), 10);
                var rec = logsData.find(function(r) { return r.id == id; });
                if (rec) viewLog(rec);
            });
            applyFilters();
        }

        // full untruncated log detail modal
        function viewLog(d) {
            var html = '<table class="view-detail-table">';
            html += '<tr><td class="vd-label"><i class="fas fa-hashtag"></i> ID</td><td class="vd-value">' + d.id + '</td>';
            html += '<td class="vd-label"><i class="fas fa-user"></i> Username</td><td class="vd-value">' + escapeHtml(d.username || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-tag"></i> Action</td><td class="vd-value">' + escapeHtml(d.action || '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-cubes"></i> Module</td><td class="vd-value">' + escapeHtml(deriveModule(d.action)) + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-network-wired"></i> IP Address</td><td class="vd-value">' + escapeHtml(d.ip_address || '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-clock"></i> Timestamp</td><td class="vd-value">' + escapeHtml(d.timestamp || '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-align-left"></i> Details</td><td class="vd-value" colspan="3">' + (d.details ? escapeHtml(d.details) : '<em class="text-muted">No details</em>') + '</td></tr>';
            html += '</table>';
            document.getElementById('viewModalBody').innerHTML = html;
            document.getElementById('viewModal').classList.add('active');
        }

        function closeViewModal() {
            document.getElementById('viewModal').classList.remove('active');
        }

        document.getElementById('viewModal').addEventListener('click', function(e) {
            if (e.target === this) closeViewModal();
        });

        function applyFilters() {
            if (!logsTable) return;
            const dateFrom = document.getElementById('filterDateFrom').value;
            const dateTo = document.getElementById('filterDateTo').value;
            const action = document.getElementById('filterAction').value;
            const module = document.getElementById('filterModule').value;
            const userSel = document.getElementById('filterUsername'), username = userSel ? userSel.value : '';

            // date range on timestamp_iso
            rsTbWhere(logsTable, 'date', (dateFrom || dateTo) ? function(r) {
                if (!r.timestamp_iso) return true;
                const d = new Date(r.timestamp_iso);
                if (dateFrom && d < new Date(dateFrom)) return false;
                if (dateTo && d > new Date(dateTo + 'T23:59:59')) return false;
                return true;
            } : null);
            rsTbWhere(logsTable, 'action', action ? function(r) { return r.action === action; } : null);
            rsTbWhere(logsTable, 'module', module ? function(r) { return deriveModule(r.action) === module; } : null);
            rsTbWhere(logsTable, 'user', username ? function(r) { return r.username === username; } : null);
        }

        function clearFilters() {
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('logsSearch').value = '';
            document.querySelectorAll('.hf-seg .is-on').forEach(function(b) { b.classList.remove('is-on'); });
            document.getElementById('filterAction').value = '';
            document.getElementById('filterModule').value = '';
            <?php if ($role === 'admin' || $role === 'manager'): ?>
            document.getElementById('filterUsername').value = '';
            <?php endif; ?>
            rsTbClear(logsTable);
            document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
        }
    </script>
</body>
</html>
