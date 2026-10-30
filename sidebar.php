<?php
/**
 * Developed by Rudees Digital
 * Website: https://rudeesdigital.com
 * Email: contact@rudeesdigital.com
 * Website: https://rudeesdigital.com
  Email: contact@rudeesdigital.com
  WhatsApp: +91 8160140848
 *
 * Shared Sidebar Component
 * Include this file in all dashboard pages
 *
 * Required variables before including:
 * - $username: Current logged-in username
 * - $role: Current user role (admin/manager/agent)
 * - $current_page: Current page identifier (dashboard/users/logs/account/settings)
 * - $user_id: Current user ID
 */

if (!isset($username) || !isset($role) || !isset($current_page) || !isset($user_id)) {
    die('Sidebar requires $username, $role, $current_page, and $user_id variables');
}

// branding + avatar (runs in page scope, so every name here is $sb_ prefixed)
$sb_brand = getBranding();
$sb_default_logo = $sb_brand['site_logo'];
$sb_avatar = getProfileImage($user_id) ?: $sb_default_logo;
$sb_theme = getUserTheme($user_id);
$sb_badges = navBadges($user_id, $role); // notif + todo, one query
$sb_staff = in_array($role, ['admin', 'manager'], true);

// daily jobs (expiry alerts, expired status, weekly backup) — once a day, after the page is sent
if (dailyJobsDue()) register_shutdown_function(function () {
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    runDailyJobs();
});

echo generateUserThemeCSS($user_id);

// count pill — 99+ cap (collapsed rail shrinks it to a dot)
$sb_pill = function ($n) {
    return $n > 0 ? '<em class="nav-pill">' . ($n > 99 ? '99+' : (int)$n) . '</em>' : '';
};
// one <li>; $sb_pkey set -> data-pill so live counts can refresh it
$sbLink = function ($href, $page, $icon, $label, $sb_pkey = null, $sb_n = 0) use ($current_page, $sb_pill) {
    $sb_l = htmlspecialchars($label);
    return '<li data-tooltip="' . $sb_l . '"' . ($sb_pkey ? ' data-pill="' . $sb_pkey . '"' : '') . '>'
         . '<a href="' . $href . '" class="' . ($current_page === $page ? 'active' : '') . '">'
         . '<i class="fas ' . $icon . '"></i><span>' . $sb_l . '</span></a>'
         . ($sb_pkey ? $sb_pill($sb_n) : '') . '</li>';
};
// group = plain label + its links, always open
$sbGroup = function ($id, $label, array $items) use ($sbLink) {
    if (!$items) return '';
    $sb_html = '';
    foreach ($items as $sb_it) $sb_html .= $sbLink(...$sb_it);
    return '<div class="sb-head" data-sec="' . $id . '">' . htmlspecialchars($label) . '</div>'
         . '<ul class="sidebar-menu" id="' . $id . '">' . $sb_html . '</ul>';
};

// menu per role — same links each role saw before
if ($role === 'customer') {
    $sb_main = [
        ['dashboard.php', 'dashboard', 'fa-house', 'Customer Portal'],
        ['policy_entries.php', 'policies', 'fa-shield-halved', 'My & Family Policies'],
        ['claims.php', 'claims', 'fa-car-burst', 'Claims & Intimation', 'claims', $sb_badges['claims']],
        ['customers.php?view=family', 'customers', 'fa-people-roof', 'Family Members'],
        ['notifications.php', 'notifications', 'fa-bell', 'Notifications', 'notif', $sb_badges['notif']],
    ];
    $sb_groups = [['account-submenu', 'My Account', [
        ['account.php', 'account', 'fa-user', 'Profile & Password'],
    ]]];
} elseif ($sb_staff) {
    $sb_main = [
        ['dashboard.php', 'dashboard', 'fa-chart-line', 'Dashboard'],
        ['policy_entries.php', 'policies', 'fa-file-invoice', 'Policy Entry'],
        ['vehicles.php', 'vehicles', 'fa-car', 'Vehicle List'],
        ['claims.php', 'claims', 'fa-car-burst', 'Claims', 'claims', $sb_badges['claims']],
        ['referrals.php', 'referrals', 'fa-gift', 'Referrals & Points'],
        ['notifications.php', 'notifications', 'fa-bell', 'Notifications', 'notif', $sb_badges['notif']],
        ['todo.php', 'todo', 'fa-tasks', 'To Do Work', 'todo', $sb_badges['todo']],
    ];
    $sb_groups = [
        ['agents-submenu', 'Agents', [
            ['agents.php', 'agents', 'fa-user-tie', 'Agent List'],
            ['agent_transactions.php', 'agent_transactions', 'fa-receipt', 'Agent Accounts'],
        ]],
        ['buyers-submenu', 'Buyers', [
            ['buyers.php', 'buyers', 'fa-building', 'Buyer List'],
            ['buyer_transactions.php', 'buyer_transactions', 'fa-receipt', 'Buyer Accounts'],
        ]],
        ['accounting-submenu', 'Accounting', [
            ['branches.php', 'branches', 'fa-code-branch', 'Branch List'],
            ['customers.php', 'customers', 'fa-address-book', 'Customer List'],
            ['bank_accounts.php', 'bank_accounts', 'fa-university', 'Our Accounts'],
            ['bank_transactions.php', 'bank_transactions', 'fa-file-invoice-dollar', 'Bank Statement'],
            ['expenses.php', 'expenses', 'fa-money-bill-wave', 'Expenses', 'exp', $sb_badges['exp']],
            ['other_income.php', 'other_income', 'fa-coins', 'Other Income'],
        ]],
        ['payout-submenu', 'Payout', [
            ['buyer_payout_charts.php', 'buyer_payouts', 'fa-chart-bar', 'Buyer Payout'],
            ['agent_payout_charts.php', 'agent_payouts', 'fa-hand-holding-usd', 'Agent Payout'],
        ]],
        ['reports-submenu', 'Reports', [
            ['reports.php', 'reports', 'fa-chart-pie', 'Dashboard Reports'],
            ['logs.php', 'logs', 'fa-history', 'Activity Logs'],
        ]],
    ];
    if ($role === 'admin') $sb_groups[] = ['system-submenu', 'System', [
        ['users.php', 'users', 'fa-users-cog', 'User Management'],
        ['settings.php', 'settings', 'fa-cog', 'Settings'],
    ]];
} else {
    $sb_main = [
        ['dashboard.php', 'dashboard', 'fa-chart-line', 'Agent Dashboard'],
        ['policy_entries.php', 'policies', 'fa-file-invoice', 'My Policies'],
        ['claims.php', 'claims', 'fa-car-burst', 'My Claims', 'claims', $sb_badges['claims']],
        ['vehicles.php', 'vehicles', 'fa-car', 'My Vehicles'],
        ['customers.php', 'customers', 'fa-users', 'My Customers'],
        ['agents.php', 'agents', 'fa-id-card', 'My Profile'],
        ['agent_payout_charts.php', 'agent_payouts', 'fa-hand-holding-usd', 'My Payout Chart'],
        ['agent_transactions.php', 'agent_transactions', 'fa-receipt', 'My Account Statement'],
        ['notifications.php', 'notifications', 'fa-bell', 'Notifications', 'notif', $sb_badges['notif']],
    ];
    $sb_groups = [['account-submenu', 'My Account', [
        ['account.php', 'account', 'fa-user', 'Account Settings'],
        ['logs.php', 'logs', 'fa-history', 'Activity Logs'],
    ]]];
}
?>
<!-- Sidebar -->
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <script>try { var sbc = localStorage.getItem('sidebarCollapsed'); if (sbc === 'true' || (sbc === null && innerWidth < 1200)) document.getElementById('sidebar').classList.add('collapsed'); } catch (e) {} /* rail before paint; tablets start as rail until chosen */</script>

    <div class="sb-brand">
        <img src="<?php echo htmlspecialchars($sb_brand['site_logo']); ?>" alt="" class="sb-logo" width="30" height="30" onerror="this.src='<?php echo htmlspecialchars($sb_default_logo); ?>'">
        <strong class="sb-name"><?php echo htmlspecialchars($sb_brand['site_name']); ?></strong>
        <button type="button" class="sb-icon-btn" onclick="toggleSidebar()" aria-label="Toggle sidebar" data-tooltip="Expand sidebar">
            <i class="fas fa-table-columns"></i><i class="fas fa-xmark sb-x"></i>
        </button>
    </div>

    <div class="sb-search">
        <label class="sb-find" for="sbQ" data-tooltip="Quick search" onclick="sbSearchOpen()">
            <i class="fas fa-magnifying-glass"></i>
            <input type="search" id="sbQ" placeholder="Quick search" autocomplete="off" aria-label="Quick search" oninput="sbFind()" onkeydown="sbFindKey(event)">
        </label>
    </div>

    <div class="sb-scroll">
        <div class="sb-sec">
            <div class="sb-head" data-sec="sb-main">Menu</div>
            <ul class="sidebar-menu" id="sb-main"><?php foreach ($sb_main as $sb_it) echo $sbLink(...$sb_it); ?></ul>
        </div>
        <div class="sb-sec">
            <?php foreach ($sb_groups as $sb_g) echo $sbGroup(...$sb_g); ?>
        </div>
        <small class="sb-nohit sb-off" id="sbNoHit"><i class="fas fa-magnifying-glass"></i> No pages match</small>
    </div>

    <div class="sb-bottom">
        <!-- one row of icon buttons, name on hover; the rail shows them as icons -->
        <ul class="sidebar-menu sb-grid">
            <?php echo $sbLink('account.php', 'account', 'fa-user-gear', $sb_staff ? 'My Profile' : 'Account Settings'); ?>
            <li data-tooltip="Dark mode">
                <button type="button" id="sbMode" role="switch" aria-checked="false" onclick="sbToggleMode()">
                    <i class="fas fa-moon"></i><span>Dark mode</span><b class="sb-switch" aria-hidden="true"></b>
                </button>
            </li>
            <?php echo $sbLink('about.php', 'about', 'fa-circle-info', 'About App'); ?>
        </ul>
    </div>

    <!-- alerts / install buttons land here on desktop (page_header.php moves the dock) -->
    <div class="sidebar-dock" id="rsSideDock"></div>

    <div class="sb-user">
        <div class="sb-user-menu" id="sbUserMenu" role="menu">
            <a href="account.php" role="menuitem"><i class="fas fa-circle-user"></i>My Account</a>
            <?php if ($role === 'admin'): ?>
            <a href="settings.php" role="menuitem"><i class="fas fa-gear"></i>Settings</a>
            <?php endif; ?>
            <a href="logout.php" role="menuitem" class="danger"><i class="fas fa-right-from-bracket"></i>Logout</a>
        </div>
        <button type="button" class="sb-user-btn" onclick="sbUserToggle(event)" aria-haspopup="menu" aria-expanded="false" aria-controls="sbUserMenu" data-tooltip="<?php echo htmlspecialchars($username); ?>">
            <img src="<?php echo htmlspecialchars($sb_avatar); ?>" alt="" class="sb-avatar" width="38" height="38" onerror="this.src='<?php echo htmlspecialchars($sb_default_logo); ?>'">
            <span class="sb-user-txt"><strong><?php echo htmlspecialchars($username); ?></strong><small><?php echo htmlspecialchars($role === 'customer' ? 'Policy Holder' : ucfirst($role)); ?></small></span>
            <i class="fas fa-sort sb-caret"></i>
        </button>
    </div>
</aside>

<!-- Floating Loading Popup (shared) -->
<div class="loading-ov" id="globalLoadingOv" style="display:none;">
    <div class="loading-popup">
        <div class="loading-progress"><div class="loading-progress-bar"></div></div>
        <div class="loading-txt" id="globalLoadingTxt">Loading...</div>
    </div>
</div>

<!-- Shared Front-End Infra (CSRF + escapeHtml + loading popup) -->
<script>
// csrf token — send with every POST/AJAX as csrf_token
window.CSRF_TOKEN = '<?= generateCSRFToken() ?>';

// safety net: any select.searchable a page never upgraded (some roles skip the page's own init) — the widget tracks later option changes itself
window.addEventListener('load', function () { if (window.initAllSearchableDropdowns) initAllSearchableDropdowns(); });

// html-escape — tolerates null/undefined/numbers
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// floating loading popup
function showLoading(msg) {
    var ov = document.getElementById('globalLoadingOv');
    var txt = document.getElementById('globalLoadingTxt');
    if (txt) txt.textContent = msg || 'Loading...';
    if (ov) ov.style.display = 'flex';
}
function hideLoading() {
    var ov = document.getElementById('globalLoadingOv');
    if (ov) ov.style.display = 'none';
}

// shared bulk row-select — Tabulator tick bar + floating delete bar
// opts: { table (Tabulator, req), label (str, req), onDelete(idsArr) (fn, req), barAnchor (selector, opt) }
window.initBulkSelect = function(opts) {
    opts = opts || {};
    var t = opts.table, label = opts.label || 'items', onDelete = opts.onDelete;
    if (!t || !t.getSelectedData || typeof onDelete !== 'function') { console.warn('initBulkSelect: table + onDelete required'); return { clear: function(){} }; }

    var key = t.element.id || 'tb';
    $('.bulk-bar[data-for="' + key + '"]').remove(); // one bar per table
    var $bar = $('<div class="bulk-bar" data-for="' + key + '">' +
        '<span class="bulk-bar-count"><i class="fas fa-check-square"></i><span class="bulk-bar-n">0</span> selected</span>' +
        '<button type="button" class="btn btn-danger bulk-bar-del"><i class="fas fa-trash"></i> Delete Selected</button>' +
        '<button type="button" class="btn bulk-bar-clear"><i class="fas fa-times"></i> Clear</button>' +
    '</div>');
    var $anchor = opts.barAnchor ? $(opts.barAnchor) : $(t.element);
    if ($anchor.length) $anchor.first().before($bar); else $('body').append($bar);

    var ids = function() { return t.getSelectedData().map(function(r) { return String(r.id); }); };
    var paint = function(n) { $bar.find('.bulk-bar-n').text(n); $bar.toggleClass('show', n > 0); };
    t.on('rowSelectionChanged', function(data) { paint(data.length); });

    var api = { clear: function() { t.deselectRow(); paint(0); }, ids: ids };
    $bar.find('.bulk-bar-clear').on('click', api.clear);
    $bar.find('.bulk-bar-del').on('click', function() {
        var sel = ids();
        if (!sel.length) return;
        Swal.fire({
            title: 'Delete ' + sel.length + ' ' + label + '?',
            text: 'This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#ea4335',
            cancelButtonColor: '#6c757d'
        }).then(function(r) { if (r.isConfirmed) onDelete(sel); });
    });
    return api;
};

// shared bulk-add + excel/csv import engine — page declares its fields, this builds the modal,
// parses the sheet into an editable grid, and saves in chunks (rejected rows stay for a re-save)
// cfg: { key, title, url, fields[], hint, controls, onOpen, onDone, chunk, extra() }
// field: { key, label, cls, type:text|number|date|select, aliases[], req, digits, max, ph, opts[]|fn, def, demo, demo2 }
window.RSImport = function(cfg) {
    var F = cfg.fields, CH = cfg.chunk || 40, ID = 'rsImp_' + cfg.key;
    var idFields = F.filter(function(f) { return f.req; }); // blank-row detection keys
    if (!idFields.length) idFields = F.slice(0, 3);

    var norm = function(s) { return String(s == null ? '' : s).toLowerCase().replace(/[^a-z0-9]/g, ''); };
    var optsOf = function(f) { return (typeof f.opts === 'function' ? f.opts() : f.opts) || []; };

    function cellStr(v) {
        if (v === null || v === undefined) return '';
        if (v instanceof Date) return ymd(v);
        return String(v).trim(); // numbers stay plain, no sci-notation
    }
    function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }

    // "1,25,000" / "₹1500" / "(500)" -> number, brackets = negative
    function money(v) {
        if (!v) return 0;
        var neg = /^\(.*\)$/.test(String(v).trim()), n = parseFloat(String(v).replace(/[^0-9.\-]/g, ''));
        return isNaN(n) ? 0 : (neg ? -Math.abs(n) : n);
    }

    // Date obj / excel serial / yyyy-mm-dd / dd-mm-yyyy (indian order) -> yyyy-mm-dd
    function toDate(v) {
        if (v instanceof Date) return ymd(v);
        if (typeof v === 'number' && v > 20000 && v < 80000) return ymd(new Date(Math.round((v - 25569) * 86400000)));
        var s = cellStr(v);
        if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
        var m = /^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/.exec(s);
        if (!m) return '';
        var y = m[3].length === 2 ? '20' + m[3] : m[3];
        return y + '-' + ('0' + m[2]).slice(-2) + '-' + ('0' + m[1]).slice(-2);
    }

    // sheet text -> option value (matches the value or the visible label)
    function matchOpt(f, val) {
        var s = norm(val);
        if (!s) return f.def || '';
        var hit = optsOf(f).filter(function(o) { return norm(o.v) === s || norm(o.t) === s; })[0];
        return hit ? hit.v : (f.def || '');
    }

    function cellHtml(f) {
        if (f.type === 'select') return '<select class="' + f.cls + '">' + optsOf(f).map(function(o) {
            return '<option value="' + escapeHtml(o.v) + '">' + escapeHtml(o.t) + '</option>'; }).join('') + '</select>';
        return '<input type="' + (f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text') + '" class="' + f.cls + '"'
            + (f.type === 'number' ? ' step="0.01"' : '') + (f.max ? ' maxlength="' + f.max + '"' : '')
            + (f.ph ? ' placeholder="' + escapeHtml(f.ph) + '"' : '') + '>';
    }

    function addRow(n, vals) {
        var body = document.querySelector('#' + ID + ' .rsi-body'), tr;
        for (var i = 0; i < (n || 1); i++) {
            tr = document.createElement('tr');
            tr.innerHTML = F.map(function(f) { return '<td>' + cellHtml(f) + '</td>'; }).join('')
                + '<td><button type="button" class="bg-remove" title="Remove" onclick="this.closest(\'tr\').remove()"><i class="fas fa-trash"></i></button></td>';
            body.appendChild(tr);
        }
        if (vals) F.forEach(function(f) { var el = tr.querySelector('.' + f.cls); if (el) el.value = vals[f.key] === undefined ? '' : vals[f.key]; });
        return tr;
    }

    function mapHeader(hdr) {
        var map = {}, used = {};
        (hdr || []).forEach(function(h, i) {
            var k = norm(h);
            if (!k) return;
            F.forEach(function(f) {
                if (!used[f.key] && (f.aliases || []).concat([norm(f.key)]).indexOf(k) > -1) { map[f.key] = i; used[f.key] = 1; }
            });
        });
        return map;
    }

    function parseSheet(aoa) {
        var out = [], map = null, start = 0;
        for (var r = 0; r < Math.min(aoa.length, 6); r++) { // header may sit under a title row
            var m = mapHeader(aoa[r]);
            if (Object.keys(m).length >= 2) { map = m; start = r + 1; break; }
        }
        if (!map) { map = {}; F.forEach(function(f, i) { map[f.key] = i; }); start = 0; } // no header -> template order
        for (var i = start; i < aoa.length; i++) {
            var row = aoa[i] || [], rec = {};
            F.forEach(function(f) {
                var raw = map[f.key] === undefined ? '' : row[map[f.key]], s = cellStr(raw);
                rec[f.key] = f.type === 'number' ? money(s) : f.type === 'date' ? toDate(raw)
                    : f.type === 'select' ? matchOpt(f, s) : f.digits ? s.replace(/\D/g, '') : s;
            });
            if (idFields.some(function(f) { return rec[f.key] !== '' && rec[f.key] !== 0; })) out.push(rec);
        }
        return out;
    }

    function fill(rows) {
        $('#' + ID + ' .rsi-body tr').each(function() { // drop untouched starter rows
            var tr = this;
            if (!idFields.some(function(f) { var el = tr.querySelector('.' + f.cls); return el && String(el.value).trim(); })) tr.remove();
        });
        rows.forEach(function(r) { addRow(1, r); });
    }

    function collect() {
        var rows = [], trs = [];
        $('#' + ID + ' .rsi-body tr').each(function() {
            var tr = this, rec = {};
            F.forEach(function(f) { var el = tr.querySelector('.' + f.cls); rec[f.key] = el ? el.value : ''; });
            if (idFields.some(function(f) { return String(rec[f.key] || '').trim(); })) { rows.push(rec); trs.push(tr); }
        });
        return { rows: rows, trs: trs };
    }

    function template() {
        if (typeof XLSX === 'undefined') { Swal.fire({ icon: 'error', title: 'Excel library not loaded', text: 'Check your internet connection and reload the page.' }); return; }
        var aoa = [F.map(function(f) { return f.key; }), F.map(function(f) { return f.demo === undefined ? '' : f.demo; })];
        if (F.some(function(f) { return f.demo2 !== undefined; })) aoa.push(F.map(function(f) { return f.demo2 === undefined ? '' : f.demo2; }));
        var wb = XLSX.utils.book_new(), ws = XLSX.utils.aoa_to_sheet(aoa);
        ws['!cols'] = F.map(function(f) { return { wch: Math.max(14, f.key.length + 4) }; });
        XLSX.utils.book_append_sheet(wb, ws, (cfg.title || 'Data').replace(/[^\w ]/g, '').slice(0, 28));
        XLSX.writeFile(wb, cfg.key + '_upload_template.xlsx');
    }

    function readFile(input) {
        var file = input.files[0];
        if (!file) return;
        if (typeof XLSX === 'undefined') { Swal.fire({ icon: 'error', title: 'Excel library not loaded', text: 'Check your internet connection and reload the page.' }); input.value = ''; return; }
        var rd = new FileReader();
        rd.onload = function(e) {
            try {
                var wb = XLSX.read(e.target.result, { type: 'array', cellDates: true });
                var aoa = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { header: 1, raw: true, defval: '', blankrows: false });
                var rows = parseSheet(aoa);
                if (!rows.length) Swal.fire({ icon: 'warning', title: 'Nothing to import', text: 'No data rows found in this file.' });
                else {
                    fill(rows);
                    Swal.fire({ icon: 'success', title: 'File loaded', text: rows.length + ' row(s) loaded — review them, then click Save All.', timer: 2400, showConfirmButton: false });
                }
            } catch (err) { Swal.fire({ icon: 'error', title: 'Parse Error', text: 'Could not read file: ' + err.message }); }
            input.value = ''; // allow re-picking the same file
        };
        rd.readAsArrayBuffer(file);
    }

    // chunked — a big sheet in one post would hit max_execution_time (hashes, txns, recalcs per row)
    function submit() {
        var c = collect();
        if (!c.rows.length) { Swal.fire({ icon: 'info', text: 'Add at least one row' }); return; }
        var extra = cfg.extra ? cfg.extra() : {};
        if (extra === false) return; // page-level guard rejected
        var total = c.rows.length, inserted = 0, errors = [];
        showLoading('Saving... 0/' + total);
        (function send(start) {
            var fd = new FormData();
            fd.append('csrf_token', window.CSRF_TOKEN);
            fd.append('row_offset', start); // keeps "Row N" numbering global
            fd.append('rows', JSON.stringify(c.rows.slice(start, start + CH)));
            Object.keys(extra).forEach(function(k) { fd.append(k, extra[k]); });
            $.ajax({
                url: cfg.url, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
                success: function(res) {
                    if (!res.success) { hideLoading(); Swal.fire({ icon: 'error', title: 'Error', text: res.message }); cfg.onDone && cfg.onDone(); return; }
                    inserted += res.inserted || 0;
                    if (res.errors && res.errors.length) errors = errors.concat(res.errors);
                    var done = Math.min(start + CH, total);
                    if (done < total) { showLoading('Saving... ' + done + '/' + total); send(done); return; }
                    hideLoading();
                    finish(inserted, errors, total, c.trs);
                },
                error: function() {
                    hideLoading();
                    Swal.fire({ icon: 'error', title: 'Connection Error', text: inserted + ' row(s) saved before the connection dropped. Reload and re-upload the rest.' });
                    cfg.onDone && cfg.onDone();
                }
            });
        })(0);
    }

    // keep only rejected rows on screen so they can be fixed and saved again
    function finish(inserted, errors, total, trs) {
        var failed = {};
        errors.forEach(function(e) { var m = /^Row (\d+):/.exec(e); if (m) failed[+m[1] - 1] = 1; });
        var html = inserted + ' of ' + total + ' row(s) saved.' + (errors.length ? ' ' + errors.length + ' skipped — fix them in the grid and save again.' : '');
        if (errors.length) html += '<div class="bulk-errors">' + errors.map(escapeHtml).join('<br>') + '</div>';
        if (errors.length) trs.forEach(function(tr, i) { if (!failed[i]) tr.remove(); });
        else close();
        Swal.fire({ icon: inserted ? 'success' : 'warning', title: cfg.title || 'Bulk Add', html: html });
        cfg.onDone && cfg.onDone();
    }

    function build() {
        var cols = F.map(function(f) { return '<code>' + escapeHtml(f.key) + '</code>'; }).join(', ');
        $('body').append(
            '<div class="modal-overlay" id="' + ID + '">' +
              '<div class="modal modal-wide" onclick="event.stopPropagation()">' +
                '<div class="modal-header"><h3><i class="fas fa-file-excel"></i> ' + escapeHtml(cfg.title || 'Bulk Add') + '</h3>' +
                  '<button class="close-btn rsi-x"><i class="fas fa-times"></i></button></div>' +
                '<div class="modal-body">' +
                  (cfg.hint ? '<p class="bulk-hint"><i class="fas fa-info-circle"></i> ' + cfg.hint + '</p>' : '') +
                  '<div class="bulk-import-bar">' +
                    '<div class="form-group bulk-file"><label><i class="fas fa-file-excel"></i> Upload Excel / CSV</label>' +
                      '<input type="file" class="file-input-styled rsi-file" accept=".xlsx,.xls,.csv"></div>' +
                    '<button type="button" class="btn btn-secondary btn-sm rsi-tpl"><i class="fas fa-download"></i> Download Template</button>' +
                    '<button type="button" class="btn btn-secondary btn-sm rsi-clr"><i class="fas fa-eraser"></i> Clear Rows</button>' +
                  '</div>' +
                  '<p class="bulk-hint bulk-cols-hint"><i class="fas fa-table-columns"></i> File columns (any order, flexible headers): ' + cols +
                    '. Dates as <code>YYYY-MM-DD</code> or <code>DD/MM/YYYY</code>. Uploaded rows drop into the grid — review or fix them, then hit <strong>Save All</strong>.</p>' +
                  (cfg.controls || '') +
                  '<div class="bulk-grid-wrap"><table class="bulk-grid"><thead><tr>' +
                    F.map(function(f) { return '<th>' + escapeHtml(f.label) + '</th>'; }).join('') + '<th></th></tr></thead>' +
                    '<tbody class="rsi-body"></tbody></table></div>' +
                  '<div class="bulk-grid-actions">' +
                    '<button type="button" class="btn btn-secondary btn-sm rsi-add1"><i class="fas fa-plus"></i> Add Row</button>' +
                    '<button type="button" class="btn btn-secondary btn-sm rsi-add5"><i class="fas fa-plus"></i> Add 5 Rows</button>' +
                  '</div>' +
                  '<div class="form-actions">' +
                    '<button type="button" class="btn btn-primary rsi-save"><i class="fas fa-save"></i> Save All</button>' +
                    '<button type="button" class="btn btn-secondary rsi-x"><i class="fas fa-times"></i> Cancel</button>' +
                  '</div>' +
                '</div></div></div>');

        var $m = $('#' + ID);
        $m.on('click', function(e) { if (e.target === this) close(); });
        $m.find('.rsi-x').on('click', close);
        $m.find('.rsi-tpl').on('click', template);
        $m.find('.rsi-clr').on('click', function() { $m.find('.rsi-body').empty(); addRow(3); });
        $m.find('.rsi-add1').on('click', function() { addRow(1); });
        $m.find('.rsi-add5').on('click', function() { addRow(5); });
        $m.find('.rsi-save').on('click', submit);
        $m.find('.rsi-file').on('change', function() { readFile(this); });
    }

    function open(n) {
        if (!document.getElementById(ID)) build();
        var $m = $('#' + ID);
        $m.find('.rsi-body').empty();
        $m.find('.rsi-file').val('');
        cfg.onOpen && cfg.onOpen($m);
        addRow(n || 3);
        $m.addClass('active');
    }
    function close() { $('#' + ID).removeClass('active'); }

    return { open: open, close: close, addRow: addRow, template: template, submit: submit, parseSheet: parseSheet, toDate: toDate, money: money };
};

/* ===== ⋮ More overflow dropdown — delegated, works on table rows ===== */
var moreClose = function() { $('.more-dropdown.open').removeClass('open is-fixed').css({ top: '', left: '' }); };
$(document).on('click', '.more-btn', function(e) {
    e.stopPropagation();
    var $dd = $(this).siblings('.more-dropdown');
    var wasOpen = $dd.hasClass('open');
    moreClose(); // close others
    if (wasOpen) return;
    $dd.removeClass('flip-left flip-up').addClass('open');
    if ($dd.closest('.tabulator').length) { // table cells clip — pin to the viewport
        var b = this.getBoundingClientRect(), w = $dd.outerWidth(), h = $dd.outerHeight();
        $dd.addClass('is-fixed').css({
            left: Math.max(8, Math.min(b.right - w, window.innerWidth - w - 8)),
            top: b.bottom + 4 + h > window.innerHeight - 8 ? Math.max(8, b.top - h - 4) : b.bottom + 4
        });
        return;
    }
    var r = $dd[0].getBoundingClientRect(); // flip near viewport edges
    if (r.right > window.innerWidth - 8) $dd.addClass('flip-left');
    if (r.bottom > window.innerHeight - 8) $dd.addClass('flip-up');
});
$(document).on('click', '.more-item', moreClose); // close after pick
$(document).on('click', moreClose);
window.addEventListener('scroll', function(e) { if (!$(e.target).closest('.more-dropdown').length) moreClose(); }, true);

/* ===== add / edit pop-ups -> 90% split: form left, live preview right (every page, one place) =====
   the form only moves into the left pane (ids, listeners and its buttons stay put); the right pane is
   rebuilt from the form's own labels + values while the pop-up is open */
(function () {
    var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    var mk = function (tag, cls, html) { var e = document.createElement(tag); e.className = cls; if (html) e.innerHTML = html; return e; };
    var shown = function (n, form) { // tab panes count as shown, anything toggled off does not
        for (; n && n !== form; n = n.parentElement) {
            if (n.classList.contains('tab-content')) continue;
            if (n.hidden || getComputedStyle(n).display === 'none') return false;
        }
        return true;
    };
    function val(el) {
        if (el.type === 'checkbox') return el.checked ? 'Yes' : 'No';
        if (el.type === 'password') return el.value ? '••••••' : '';
        if (el.type === 'file') return el.files && el.files.length ? [].map.call(el.files, function (f) { return f.name; }).join(', ') : '';
        if (el.tagName === 'SELECT') {
            if (el.multiple) return [].filter.call(el.options, function (o) { return o.selected && o.value; }).map(function (o) { return o.text.trim(); }).join(', ');
            var o = el.options[el.selectedIndex];
            return o && o.value !== '' ? o.text.trim() : '';
        }
        return String(el.value || '').trim();
    }
    function read(form) {
        var sec = 'Details', out = [];
        form.querySelectorAll('.form-section-title, .form-group').forEach(function (n) {
            if (n.classList.contains('form-section-title')) { sec = n.textContent.trim() || sec; return; }
            var el = n.querySelector('input:not([type=hidden]):not([type=button]):not([type=submit]):not([type=search]), select, textarea'), lb = n.querySelector('label');
            if (!el || !lb || !shown(n, form)) return;
            var ic = lb.querySelector('i');
            out.push({ s: sec, k: lb.textContent.replace(/\*/g, '').trim(), v: val(el), req: el.required, ico: ic ? ic.className : 'fas fa-circle-dot' });
        });
        return out;
    }
    function render(ov) {
        var f = read(ov._form), sig = JSON.stringify(f.map(function (x) { return [x.k, x.v]; }));
        if (ov._sig === sig) return;
        ov._sig = sig;
        var pick = function (re) { return f.filter(function (x) { return x.v && x.v !== 'Yes' && x.v !== 'No' && re.test(x.k); })[0]; };
        var head = pick(/name|title|company|customer|policy|source|category/i) || f.filter(function (x) { return x.v && x.v !== 'Yes' && x.v !== 'No'; })[0];
        var sub = pick(/mobile|phone|email|number|code|date|amount/i);
        if (sub === head) sub = null;
        var editing = /edit|update|renew/i.test((ov.querySelector('.modal-header h3') || {}).textContent || '');
        var filled = f.filter(function (x) { return x.v; }).length, html = '', cur = null;
        html += '<div class="pv-label"><i class="fas fa-eye"></i> Live preview</div>'
            + '<div class="pv-card"><div class="pv-who"><strong>' + esc(head ? head.v : 'Nothing entered yet') + '</strong>'
            + '<small>' + esc(sub ? sub.k + ': ' + sub.v : 'Fill in the form to see it here') + '</small></div>'
            + '<span class="pv-badge">' + (editing ? 'Editing' : 'New') + '</span></div>';
        f.forEach(function (x) {
            if (x.s !== cur) { html += (cur === null ? '' : '</ul>') + '<div class="pv-label">' + esc(x.s) + '</div><ul class="pv-list">'; cur = x.s; }
            var v = x.v.length > 120 ? x.v.slice(0, 117) + '…' : x.v;
            html += '<li class="' + (x.v ? 'ok' : x.req ? 'warn' : 'muted') + '"><i class="' + esc(x.ico) + '"></i><span class="pv-k">' + esc(x.k) + '</span>'
                + (x.v ? '<b>' + esc(v) + '</b>' : '<em>' + (x.req ? 'Required' : '—') + '</em>') + '</li>';
        });
        html += (cur === null ? '' : '</ul>') + '<span class="pv-count">' + filled + ' of ' + f.length + ' fields filled</span>';
        ov._pv.innerHTML = html;
    }
    function upgrade(ov) {
        var modal = ov.querySelector('.modal'), body = modal && modal.querySelector('.modal-body'), form = body && body.querySelector('form');
        if (ov._form || !form || /bulk|upload|categor/i.test(ov.id) || !form.querySelector('.form-actions') || form.querySelectorAll('.form-group').length < 3) return;
        modal.classList.add('modal-split');
        var tabs = mk('div', 'split-tabs', '<button type="button" class="on"><i class="fas fa-pen"></i> Form</button><button type="button"><i class="fas fa-eye"></i> Preview</button>');
        var wrap = mk('div', 'split-body'), left = mk('div', 'split-form'), pv = mk('aside', 'split-preview');
        pv.setAttribute('aria-label', 'Live preview');
        body.parentNode.insertBefore(tabs, body);
        tabs.after(wrap);
        left.appendChild(body);
        wrap.append(left, pv);
        tabs.addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            var prev = b === tabs.lastChild;
            modal.classList.toggle('show-preview', prev);
            tabs.firstChild.classList.toggle('on', !prev); tabs.lastChild.classList.toggle('on', prev);
            if (prev) render(ov);
        });
        ov._form = form; ov._pv = pv;
        ['input', 'change'].forEach(function (ev) { form.addEventListener(ev, function () { render(ov); }); });
        // opening resets to the form pane; edit fills values in code, so keep an eye while it is open
        new MutationObserver(function () {
            if (!ov.classList.contains('active')) return;
            modal.classList.remove('show-preview'); tabs.firstChild.classList.add('on'); tabs.lastChild.classList.remove('on');
            ov._sig = null; render(ov);
        }).observe(ov, { attributes: true, attributeFilter: ['class'] });
        render(ov);
    }
    function all() { document.querySelectorAll('.modal-overlay').forEach(upgrade); }
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', all) : all();
    setInterval(function () {
        document.querySelectorAll('.modal-overlay.active').forEach(function (ov) { if (ov._form) render(ov); });
    }, 500);
    window.rsSplitModals = all; // pages that build a pop-up later can call it
})();

/* ===== view pop-ups -> 90% split: record summary left, its data in tabs right =====
   built from what the page already rendered (detail tables + tabs), refreshed each time a record is shown */
(function () {
    var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    // label / value pairs out of the detail tables (rows hold 1 or 2 pairs)
    function facts(root) {
        var out = [];
        root.querySelectorAll('.view-detail-table tr').forEach(function (tr) {
            var c = tr.children;
            for (var i = 0; i + 1 < c.length; i += 2) {
                var k = c[i].textContent.replace(/:\s*$/, '').trim(), v = c[i + 1];
                if (!k || !v.textContent.trim()) continue;
                var ic = c[i].querySelector('i');
                out.push({ k: k, ico: ic ? ic.className : 'fas fa-circle-dot', html: v.innerHTML, txt: v.textContent.trim() });
            }
        });
        return out;
    }
    function split(ov) {
        var modal = ov.querySelector('.modal'), body = ov.querySelector('.modal-body');
        if (!modal || !body || ov._form) return;
        var first = body.querySelector('.tab-content'), f = facts(first || body);
        if (!f.length) return;
        // the summary also borrows the headline facts that live on the other tabs
        if (first) facts(body).forEach(function (x) {
            if (/customer|vehicle|premium|agent|company|insurer|mobile|email|amount|total|balance|wallet/i.test(x.k)
                && !f.some(function (y) { return y.k === x.k; })) f.push(x);
        });
        modal.classList.add('modal-vsplit');
        if (!ov._vside) {
            var wrap = document.createElement('div'); wrap.className = 'vsplit-body';
            ov._vside = document.createElement('aside'); ov._vside.className = 'vsplit-side';
            body.parentNode.insertBefore(wrap, body);
            wrap.append(ov._vside, body);
            var on = body.querySelector('.tab-nav .tab-btn.active'); // moving the body reset the strip's scroll
            if (on && on.scrollIntoView) on.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
        // single-table views get the same tab strip so every view reads alike
        if (!body.querySelector('.tab-nav')) {
            var bar = document.createElement('div');
            bar.className = 'tab-nav vs-tabs';
            bar.innerHTML = '<button type="button" class="tab-btn active"><i class="fas fa-list"></i> Details</button>';
            body.prepend(bar);
        }
        var head = f.filter(function (x) { return x.txt.length < 80 && /name|policy|title|customer|company|code|number|vehicle|user|source|category|subject|action/i.test(x.k); })[0] || f[0];
        var h3 = ov.querySelector('.modal-header h3');
        ov._vside.innerHTML = '<div class="vs-kicker">' + esc(h3 ? h3.textContent.trim() : 'Details') + '</div>'
            + '<div class="vs-title">' + esc(head.txt) + '</div><div class="vs-sub">' + esc(head.k) + '</div>'
            + '<dl class="vs-facts">' + f.filter(function (x) { return x !== head; }).slice(0, 10).map(function (x) {
                return '<div><dt><i class="' + esc(x.ico) + '"></i> ' + esc(x.k) + '</dt><dd>' + x.html + '</dd></div>';   // value cell = page-rendered, already escaped
            }).join('') + '</dl>';
    }
    function hook(ov) {
        var body = ov.querySelector('.modal-body');
        if (!body || ov._vhook || !/view/i.test(ov.id) || body.querySelector('form')) return;
        ov._vhook = true;
        var t;
        new MutationObserver(function () { clearTimeout(t); t = setTimeout(function () { split(ov); }, 30); }).observe(body, { childList: true });
        split(ov);
    }
    function all() { document.querySelectorAll('.modal-overlay').forEach(hook); }
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', all) : all();
})();

/* ===== Tabbed modal helper — wires .tab-nav/.tab-btn/.tab-content ===== */
window.initModalTabs = function(container) {
    var $c = $(container);
    if (!$c.length) return;
    var $btns = $c.find('.tab-btn'), $panes = $c.find('.tab-content');
    if (!$btns.length) return;
    // first tab active if none marked
    if (!$btns.filter('.active').length) {
        $btns.first().addClass('active');
        var first = $btns.first().data('tab');
        $panes.removeClass('active').filter('[data-tab="' + first + '"]').addClass('active');
    }
    $c.off('click.modalTabs', '.tab-btn').on('click.modalTabs', '.tab-btn', function() {
        var t = $(this).data('tab');
        $btns.removeClass('active');
        $(this).addClass('active');
        $panes.removeClass('active').filter('[data-tab="' + t + '"]').addClass('active');
        if (this.scrollIntoView) this.scrollIntoView({ block: 'nearest', inline: 'nearest' }); // tab strip scrolls sideways — keep the picked tab in view
    });
};

/* ===== Empty-state HTML builder ===== */
// opts: { icon, title, text, btnText, btnId }
window.emptyStateHtml = function(opts) {
    opts = opts || {};
    var btn = (opts.btnText && opts.btnId)
        ? '<button type="button" class="btn btn-primary" id="' + escapeHtml(opts.btnId) + '">' +
          '<i class="fas fa-plus"></i> ' + escapeHtml(opts.btnText) + '</button>'
        : '';
    return '<div class="empty-state">' +
        '<i class="fas ' + escapeHtml(opts.icon || 'fa-inbox') + ' empty-state-icon"></i>' +
        '<div class="empty-state-title">' + escapeHtml(opts.title || 'Nothing here yet') + '</div>' +
        '<div class="empty-state-text">' + escapeHtml(opts.text || '') + '</div>' +
        btn +
    '</div>';
};

/* ===== Escape closes top-most open modal (SweetAlert handles its own) ===== */
$(document).on('keydown', function(e) {
    if (e.key !== 'Escape' && e.keyCode !== 27) return;
    if ($('.swal2-container').length) return; // let SweetAlert manage itself
    var $open = $('.modal-overlay.active');
    if (!$open.length) return;
    $open.last().removeClass('active'); // top-most = last in DOM
});

/* ===== Back-to-top button — markup injected once ===== */
$(function() {
    if ($('#backToTop').length) return;
    var $btn = $('<button type="button" class="back-to-top" id="backToTop" title="Back to top" aria-label="Back to top"><i class="fas fa-arrow-up"></i></button>');
    $('body').append($btn);
    var $scroller = $('.main-content').length ? $('.main-content') : $(window);
    function onScroll() {
        var y = $('.main-content').length ? $('.main-content').scrollTop() : $(window).scrollTop();
        $btn.toggleClass('show', y > 300);
    }
    $scroller.on('scroll', onScroll);
    onScroll();
    $btn.on('click', function() {
        if ($('.main-content').length) $('.main-content').animate({ scrollTop: 0 }, 350);
        else $('html, body').animate({ scrollTop: 0 }, 350);
    });
});

/* ===== Session-timeout warning — 30min idle, warn ~2min before ===== */
(function() {
    var TIMEOUT = <?= defined('SESSION_TIMEOUT') ? (int)SESSION_TIMEOUT : 1800 ?> * 1000; // ms
    var WARN_BEFORE = 120 * 1000;   // 2 min
    var lastActivity = Date.now();
    var warnTimer = null, expireTimer = null, countdownTimer = null, warningShown = false;

    function resetTimers() {
        lastActivity = Date.now();
        warningShown = false;
        clearTimeout(warnTimer); clearTimeout(expireTimer); clearInterval(countdownTimer);
        if (window.Swal && Swal.getTitle && document.querySelector('.swal2-container .session-countdown')) Swal.close();
        warnTimer = setTimeout(showWarning, TIMEOUT - WARN_BEFORE);
        expireTimer = setTimeout(doExpire, TIMEOUT);
    }

    function doExpire() {
        window.location.href = 'login.php';
    }

    function showWarning() {
        if (warningShown || !window.Swal) return;
        warningShown = true;
        var remain = Math.round(WARN_BEFORE / 1000);
        Swal.fire({
            title: 'Session expiring',
            html: 'You will be logged out in <span class="session-countdown">' + remain + '</span> seconds due to inactivity.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-rotate"></i> Stay logged in',
            cancelButtonText: 'Logout',
            confirmButtonColor: '#0074D9',
            cancelButtonColor: '#6c757d',
            allowOutsideClick: false,
            allowEscapeKey: false
        }).then(function(r) {
            clearInterval(countdownTimer);
            if (r.isConfirmed) {
                // ping current page to refresh LAST_ACTIVITY server-side
                $.get(window.location.pathname + '?_ping=' + Date.now()).always(resetTimers);
            } else {
                doExpire();
            }
        });
        countdownTimer = setInterval(function() {
            remain--;
            var el = document.querySelector('.session-countdown');
            if (el) el.textContent = remain;
            if (remain <= 0) { clearInterval(countdownTimer); Swal.close(); doExpire(); }
        }, 1000);
    }

    // user activity bumps the idle clock (ignored while warning is up)
    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function(ev) {
        document.addEventListener(ev, function() {
            if (!warningShown) resetTimers();
        }, { passive: true });
    });
    resetTimers();
})();
</script>

<!-- Theme + sidebar toggles -->
<script>
// single place that re-skins the page live — keeps hex + rgb vars in sync
window.rsSkin = function (p, s, a) {
    var r = document.documentElement.style,
        rgb = function (h) {
            h = String(h).replace('#', '');
            if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
            var n = parseInt(h, 16) || 0;
            return [(n >> 16) & 255, (n >> 8) & 255, n & 255].join(', ');
        };
    r.setProperty('--navy-primary', p);
    r.setProperty('--navy-light', s);
    r.setProperty('--navy-dark', p);
    r.setProperty('--navy-hover', s);
    r.setProperty('--navy-accent', a);
    r.setProperty('--navy-primary-rgb', rgb(p));
    r.setProperty('--navy-light-rgb', rgb(s));
    r.setProperty('--navy-accent-rgb', rgb(a));
};

// priority: localStorage > saved user mode > system
function initTheme() {
    var saved = localStorage.getItem('theme'), userMode = <?php echo json_encode((string)($sb_theme['theme_mode'] ?? '')); ?>, isDark;
    if (saved) isDark = saved === 'dark';
    else if (userMode) { isDark = userMode === 'dark'; localStorage.setItem('theme', userMode); }
    else isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.body.classList.toggle('dark-mode', isDark);
    updateThemeButton(isDark);
}

function toggleTheme() {
    var isDark = document.body.classList.toggle('dark-mode');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    updateThemeButton(isDark);
}

// sidebar switch + header moon follow the body class
function updateThemeButton(isDark) {
    var sw = document.getElementById('sbMode'), mi = document.getElementById('modeIcon');
    if (sw) sw.setAttribute('aria-checked', isDark ? 'true' : 'false');
    if (mi) { mi.classList.toggle('fa-moon', !isDark); mi.classList.toggle('fa-sun', isDark); }
}

// header moon + sidebar switch both land here (header file may override it)
window.quickToggleMode = window.quickToggleMode || toggleTheme;
function sbToggleMode() { (window.quickToggleMode || toggleTheme)(); }

initTheme();

// icon rail toggle (desktop). phones get the drawer, so there it just closes it
function toggleSidebar(force) {
    if (window.innerWidth <= 768) { if (typeof closeMobileMenu === 'function') closeMobileMenu(); return; }
    var sb = document.getElementById('sidebar');
    var c = force === undefined ? sb.classList.toggle('collapsed') : sb.classList.toggle('collapsed', force);
    localStorage.setItem('sidebarCollapsed', c);
    if (window.sbTipHide) sbTipHide();
}
// old names, kept for any caller
function initSidebar() {}
function updateSidebarIcon() {}

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
    if (localStorage.getItem('theme')) return;
    document.body.classList.toggle('dark-mode', e.matches);
    updateThemeButton(e.matches);
});

// fire a hidden DataTables export button from the page header
function dtBtn(t, kind) { rsTbExport(t, kind); }
</script>

<!-- Sidebar behaviour — groups, quick search, rail tooltips, account menu, live counts -->
<script>
(function () {
    var sb = document.getElementById('sidebar');
    if (!sb) return;
    var q = document.getElementById('sbQ'),
        off = function (el, hide) { if (el) el.classList.toggle('sb-off', hide); };

    // quick search — filters the links already rendered, so it only ever shows permitted pages
    var items = Array.prototype.slice.call(sb.querySelectorAll('.sidebar-menu > li'));
    var label = function (el) {
        var f = el.querySelector('[data-find]'), s = el.querySelector('span');
        return ((f && f.dataset.find) || (s && s.textContent) || '').toLowerCase();
    };
    window.sbFind = function () {
        var t = q.value.trim().toLowerCase(), hits = 0;
        sb.classList.toggle('sb-filtering', !!t);
        items.forEach(function (el) {
            var m = !t || label(el).indexOf(t) > -1;
            off(el, !m);
            if (m) hits++;
        });
        // headings + whole sections drop out when nothing under them matches
        sb.querySelectorAll('[data-sec]').forEach(function (h) {
            var ul = document.getElementById(h.dataset.sec);
            off(h, !!t && !(ul && ul.querySelector(':scope > li:not(.sb-off)')));
        });
        sb.querySelectorAll('.sb-sec, .sb-bottom').forEach(function (s) {
            off(s, !!t && !s.querySelector('.sidebar-menu > li:not(.sb-off)'));
        });
        off(document.getElementById('sbNoHit'), !t || hits > 0);
    };
    window.sbFindKey = function (e) {
        if (e.key === 'Escape') { q.value = ''; sbFind(); q.blur(); return; }
        if (e.key !== 'Enter' || !q.value.trim()) return;
        e.preventDefault();
        var hit = items.filter(function (el) { return !el.classList.contains('sb-off'); })[0],
            go = hit && hit.querySelector('a[href], button');
        if (go) go.click();
    };
    // rail: the magnifier opens the full sidebar and drops you in the box
    window.sbSearchOpen = function () {
        if (sb.classList.contains('collapsed') && window.innerWidth > 768) toggleSidebar(false);
        q.focus();
    };

    // rail tooltips — fixed on <body> so the scrolling list can't clip them
    var tip = document.createElement('b');
    tip.className = 'sb-tip';
    tip.setAttribute('aria-hidden', 'true');
    document.body.appendChild(tip);
    window.sbTipHide = function () { tip.classList.remove('on'); };
    var tipShow = function (e) {
        var el = e.target.closest && e.target.closest('[data-tooltip]');
        if (!el || !sb.contains(el) || !sb.classList.contains('collapsed') || window.innerWidth <= 768) return sbTipHide();
        var r = el.getBoundingClientRect();
        tip.textContent = el.getAttribute('data-tooltip');
        tip.style.top = (r.top + r.height / 2) + 'px';
        tip.style.left = (sb.getBoundingClientRect().right + 8) + 'px';
        tip.classList.add('on');
    };
    sb.addEventListener('mouseover', tipShow);
    sb.addEventListener('focusin', tipShow);
    sb.addEventListener('mouseleave', sbTipHide);
    sb.addEventListener('focusout', sbTipHide);
    sb.querySelector('.sb-scroll').addEventListener('scroll', sbTipHide, { passive: true });

    // footer account menu
    var um = document.getElementById('sbUserMenu'), ub = sb.querySelector('.sb-user-btn');
    var umSet = function (open) { um.classList.toggle('open', open); ub.setAttribute('aria-expanded', open); };
    window.sbUserToggle = function (e) {
        e.stopPropagation();
        sbTipHide();
        umSet(!um.classList.contains('open'));
    };
    document.addEventListener('click', function (e) { if (!e.target.closest('.sb-user')) umSet(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') umSet(false); });

    // live counts — pollers hand fresh numbers in here (sidebar row + phone bottom bar twin)
    window.sbPill = function (key, n) {
        var mb = document.querySelector('.mobile-bottom-nav [data-pill="' + key + '"]');
        if (mb) {
            var p = mb.querySelector('.mobile-nav-pill');
            if (!(n > 0)) { if (p) p.remove(); }
            else { if (!p) { p = document.createElement('em'); p.className = 'mobile-nav-pill'; mb.appendChild(p); } p.textContent = n > 9 ? '9+' : n; }
        }
        var li = sb.querySelector('[data-pill="' + key + '"]');
        if (!li) return;
        var em = li.querySelector('.nav-pill');
        if (!(n > 0)) { if (em) em.remove(); return; }
        if (!em) { em = document.createElement('em'); em.className = 'nav-pill'; li.appendChild(em); }
        em.textContent = n > 99 ? '99+' : n;
    };
})();
</script>
<script>
// upload drop zone — upgrades every <input type="file" class="file-input"> on the page.
// the real input stays inside (opacity 0, covering the zone): click, keyboard, native drop and every
// existing 'change' listener keep working. data-max-mb + accept are checked here; data-hint overrides the hint
(function () {
    var desc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    var size = function (b) { return b < 1024 ? b + ' B' : b < 1048576 ? (b / 1024).toFixed(0) + ' KB' : (b / 1048576).toFixed(1) + ' MB'; };
    var kinds = function (acc) {
        return (acc || '').split(',').map(function (a) { a = a.trim(); return a.indexOf('/') > -1 ? a.split('/')[1].replace('svg+xml', 'svg').replace('jpeg', 'jpg') : a.replace('.', ''); })
            .filter(Boolean).map(function (a) { return a.toUpperCase(); }).join(', ');
    };
    var okType = function (f, acc) {
        if (!acc) return true;
        var n = f.name.toLowerCase();
        return acc.split(',').some(function (a) { a = a.trim().toLowerCase(); return a.charAt(0) === '.' ? n.slice(-a.length) === a : a.slice(-2) === '/*' ? f.type.indexOf(a.slice(0, -1)) === 0 : f.type === a; });
    };

    function enhance(inp) {
        if (inp._rsDrop) return;
        inp._rsDrop = true;
        var max = parseFloat(inp.dataset.maxMb || '0'), hint = inp.dataset.hint || [kinds(inp.accept), max ? 'max ' + max + 'MB' : ''].filter(Boolean).join(' · ');
        var box = document.createElement('div');
        box.className = 'rs-drop';
        box.innerHTML = '<div class="rs-drop-empty"><span class="rs-drop-ico"><i class="fas fa-cloud-arrow-up"></i></span><div class="rs-drop-txt">'
            + '<strong>Drop a file here or <u>browse</u></strong>' + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</div></div>'
            + '<div class="rs-drop-file"><span class="rs-drop-thumb"></span><div class="rs-drop-txt"><strong class="rs-drop-name"></strong><small class="rs-drop-meta"></small></div>'
            + '<button type="button" class="rs-drop-x" title="Remove file" aria-label="Remove file"><i class="fas fa-xmark"></i></button></div>'
            + '<div class="rs-drop-err" role="alert"></div>';
        inp.parentNode.insertBefore(box, inp);
        box.insertBefore(inp, box.firstChild);
        inp.classList.add('rs-drop-input');
        inp.setAttribute('aria-label', inp.getAttribute('aria-label') || 'Choose a file');
        var url = null, err = box.querySelector('.rs-drop-err');

        function render() {
            var f = inp.files && inp.files[0];
            if (url) { URL.revokeObjectURL(url); url = null; }
            box.dataset.state = f ? 'ready' : (box.dataset.state === 'error' ? 'error' : 'empty');
            if (!f) return;
            err.textContent = '';
            box.querySelector('.rs-drop-name').textContent = f.name;
            box.querySelector('.rs-drop-meta').textContent = size(f.size) + (inp.files.length > 1 ? ' · +' + (inp.files.length - 1) + ' more' : '');
            var th = box.querySelector('.rs-drop-thumb');
            if (f.type.indexOf('image/') === 0) { url = URL.createObjectURL(f); th.innerHTML = '<img src="' + url + '" alt="">'; }
            else th.innerHTML = '<i class="fas ' + (/\.sql$/i.test(f.name) ? 'fa-database' : /\.(csv|xlsx?)$/i.test(f.name) ? 'fa-file-csv' : /\.pdf$/i.test(f.name) ? 'fa-file-pdf' : 'fa-file') + '"></i>';
        }
        function fail(msg) { desc.set.call(inp, ''); box.dataset.state = 'error'; err.textContent = msg; }

        // checks run first (capture) so a refused file never reaches the page's own change handler
        inp.addEventListener('change', function (e) {
            var f = inp.files && inp.files[0];
            if (f && !okType(f, inp.accept)) { e.stopImmediatePropagation(); return fail('That file type is not allowed' + (hint ? ' — ' + hint : '')); }
            if (f && max && f.size > max * 1048576) { e.stopImmediatePropagation(); return fail('File is too large (' + size(f.size) + ') — max ' + max + 'MB'); }
            if (f) box.dataset.state = 'ready';
            render();
        }, true);
        // code that clears the input (input.value = '') redraws the zone too
        Object.defineProperty(inp, 'value', { configurable: true, get: function () { return desc.get.call(this); }, set: function (v) { desc.set.call(this, v); if (!v) box.dataset.state = 'empty'; render(); } });
        if (inp.form) inp.form.addEventListener('reset', function () { setTimeout(function () { box.dataset.state = 'empty'; render(); }, 0); });
        box.querySelector('.rs-drop-x').addEventListener('click', function () {
            inp.value = '';
            inp.dispatchEvent(new Event('change', { bubbles: true }));   // page previews reset as if the user cleared it
            inp.focus();
        });
        ['dragenter', 'dragover'].forEach(function (t) { inp.addEventListener(t, function () { box.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (t) { inp.addEventListener(t, function () { box.classList.remove('is-over'); }); });
        render();
    }
    window.rsDropzone = enhance;
    var run = function () { document.querySelectorAll('input[type="file"].file-input').forEach(enhance); };
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', run) : run();
})();
</script>
<script>
// letterhead for printed / PDF reports (rsReport in app.js)
<?php
// currency symbol -> code for paper (PDF fonts carry no ₹ / € glyphs)
$sb_sym = trim($sb_brand['currency_symbol'] ?? '');
$sb_cur = ['₹' => 'INR', '$' => 'USD', '€' => 'EUR', '£' => 'GBP', '৳' => 'BDT', '¥' => 'JPY'][$sb_sym] ?? (preg_match('/^[A-Za-z.]{1,4}$/', $sb_sym) ? $sb_sym : '');
?>
window.RS_BRAND = <?php echo json_encode(['name' => $sb_brand['site_name'], 'logo' => $sb_brand['site_logo'], 'user' => $username, 'sym' => $sb_sym, 'cur' => $sb_cur], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;

// branded processing overlay — writes only (reads use the thin top bar). one per page, reused
window.rsOverlay = function (label) {
    var o = document.getElementById('rsOverlay');
    if (!o) {
        o = document.createElement('div');
        o.id = 'rsOverlay';
        o.className = 'rs-overlay';
        o.innerHTML = '<div class="rs-overlay-card"><img src="<?php echo htmlspecialchars($sb_brand['site_logo'], ENT_QUOTES); ?>" alt="" width="44" height="44"><div class="rs-overlay-bar"><i></i></div><span></span></div>';
        document.body.appendChild(o);
    }
    o.querySelector('span').textContent = label || 'Working…';
    o.classList.add('on');
    return function () { o.classList.remove('on'); };
};
</script>
<script>
// first-run welcome tour — once per user (ai_tour_<uid>), steps follow the role
(function () {
    var key = 'ai_tour_<?php echo (int)$user_id; ?>';
    try { if (localStorage.getItem(key)) return; } catch (e) { return; }
    var steps = <?php echo json_encode($sb_staff ? [
        ['fa-chart-line', 'Dashboard', 'Premium at risk, this period\'s numbers and the policies that need you today.'],
        ['fa-file-invoice', 'Policies', 'Add, renew and print policies. Documents, endorsements, claims and WhatsApp reminders live inside each policy.'],
        ['fa-car-burst', 'Claims', 'Follow every claim from intimation to settlement. The sidebar count shows the open ones.'],
        ['fa-user-tie', 'Agents & Buyers', 'Commissions post to their ledgers on their own, and payments link to your bank accounts.'],
        ['fa-chart-pie', 'Reports', 'Renewals, customer dues, commissions and profit. Print or export any list.'],
    ] : [
        ['fa-id-card', 'My Profile', 'Your details, licence and commission balance in one place.'],
        ['fa-file-invoice', 'My Policies', 'Your policies with documents and a one-tap WhatsApp renewal reminder.'],
        ['fa-car-burst', 'My Claims', 'Report a claim for your customer and follow it to settlement.'],
        ['fa-receipt', 'My Statement', 'Every commission and payment on your account, ready to print.'],
    ], JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    function open() {
        if (document.querySelector('.modal-overlay.active')) return; // a page pop-up opened first — next visit
        try { localStorage.setItem(key, '1'); } catch (e) {}
        var i = 0, ov = document.createElement('div');
        ov.className = 'modal-overlay active';
        ov.setAttribute('role', 'dialog');
        ov.setAttribute('aria-modal', 'true');
        ov.setAttribute('aria-label', 'Welcome tour');
        var paint = function () {
            var s = steps[i], last = i === steps.length - 1;
            ov.innerHTML = '<div class="modal tour-modal"><div class="modal-body"><div class="tour-ico"><i class="fas ' + s[0] + '"></i></div>'
                + '<h3>' + escapeHtml(s[1]) + '</h3><p>' + escapeHtml(s[2]) + '</p><div class="tour-dots">'
                + steps.map(function (x, k) { return '<button type="button" data-k="' + k + '" class="' + (k === i ? 'on' : '') + '" aria-label="Step ' + (k + 1) + '"></button>'; }).join('')
                + '</div></div><div class="form-actions"><button type="button" class="btn btn-secondary" data-t="skip">Skip</button>'
                + (last ? '<button type="button" class="btn btn-success" data-t="done"><i class="fas fa-check"></i> Get Started</button>'
                        : '<button type="button" class="btn btn-primary" data-t="next">Next <i class="fas fa-arrow-right"></i></button>') + '</div></div>';
            var f = ov.querySelector('[data-t="next"],[data-t="done"]'); if (f) f.focus();
        };
        ov.addEventListener('click', function (e) {
            var b = e.target.closest('button');
            if (!b) return;
            if (b.dataset.k) { i = +b.dataset.k; return paint(); }
            if (b.dataset.t === 'next') { i++; return paint(); }
            ov.remove();
        });
        paint();
        document.body.appendChild(ov);
    }
    window.addEventListener('load', function () { setTimeout(open, 700); });
})();

<?php if ($sb_staff): ?>
// demo data quick-add: info icon beside the close (x) of every add/edit pop-up with a form — set #N, +1 per click
(function () {
    function demoSeq(k) {
        var n = 1;
        try { n = parseInt(localStorage.getItem('rsDemoSeq_' + k) || '0', 10) + 1; localStorage.setItem('rsDemoSeq_' + k, n); } catch (e) {}
        return n;
    }
    var ymd = function (off) { var d = new Date(); d.setDate(d.getDate() + off); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); };
    // name / type aware, values pass this app's validators (pan, ifsc, gstin, pincode, ckyc, upi, mobile 10-15 digits)
    function demoValue(name, type, n, entity, label) {
        name = String(name || '').toLowerCase();
        var pad = String(n).padStart(3, '0'), p4 = String(n % 10000).padStart(4, '0'), yr = new Date().getFullYear();
        if (type === 'email' || /email/.test(name)) return 'demo' + n + '@demo.com';
        if (type === 'password') return 'demo1234';
        if (/(^|_)pan(_|$)/.test(name)) return 'DEMOP' + p4 + 'Z';
        if (/ifsc/.test(name)) return 'DEMO0' + String(n % 1000000).padStart(6, '0');
        if (/gst(in|_number|_no)/.test(name)) return '27DEMOP' + p4 + 'Z1Z5';
        if (/pin_?code|zip/.test(name)) return String(400000 + (n % 99999) + 1);
        if (/ckyc/.test(name)) return String(10000000000000 + n);
        if (/upi/.test(name)) return 'demo' + n + '@okdemo';
        if (/aadha?r/.test(name)) return String(200000000000 + n);
        if (type === 'tel' || /phone|mobile|whatsapp/.test(name)) return /alternate|alt_|emergency/.test(name) ? '0321' + (2000000 + n) : '0300' + (1000000 + n);
        if (/cnic|passport|nic|nid/.test(name)) return '35202' + (10000000 + n);
        if (/vehicle_number|registration_number|reg_no/.test(name)) return 'MH01DM' + p4;
        if (/chassis/.test(name)) return 'CHS' + yr + pad;
        if (/engine/.test(name)) return 'ENG' + yr + pad;
        if (/account_number|account_no/.test(name)) return '5010' + String(10000000 + n);
        if (/policy_number/.test(name)) return 'POL-' + yr + '-D' + pad;
        if (type === 'date') {
            if (/policy_end/.test(name)) return ymd(364 - (n % 14));
            if (/dob|birth/.test(name)) return ymd(-(9000 + n * 30));
            if (/incident/.test(name)) return ymd(-(n % 10) - 2);
            if (/expiry|next|due|end/.test(name)) return ymd(7 + (n % 21));
            return ymd(-(n % 14));
        }
        if (type === 'time') return String(9 + (n % 8)).padStart(2, '0') + ':00';
        if (/rate|percent|ncb/.test(name)) return String((n % 15) + 5);
        if (/year/.test(name)) return String(2015 + (n % 10));
        if (type === 'number' || /amount|fee|price|salary|cost|premium/.test(name)) return String((n % 20 + 1) * 500);
        if (/receipt/.test(name)) return 'REC-' + yr + '-' + pad;
        if (/invoice/.test(name)) return 'INV-' + yr + '-' + pad;
        if (/(^|_)(number|no|code|ref)$/.test(name)) return (entity || 'DEMO').replace(/[^A-Za-z]/g, '').substring(0, 3).toUpperCase() + '-' + yr + '-' + pad;
        if (/address/.test(name)) return 'House ' + n + ', Street ' + ((n % 9) + 1) + ', Demo City';
        if (/city/.test(name)) return 'Demo City';
        if (/state/.test(name)) return 'Demo State';
        if (/nationality|country/.test(name)) return 'Demo Country';
        if (/first_name/.test(name)) return entity || 'Demo';
        if (/last_name|surname/.test(name)) return String(n);
        if (/full_name|^name$|username|_name$/.test(name)) return (entity || 'Demo') + ' ' + n;
        if (/description|notes|remark|content|summary|message/.test(name)) return 'Demo ' + String(label || 'entry').toLowerCase() + ' ' + n + ' — sample text for testing.';
        return (label || 'Demo') + ' ' + n;
    }
    function seed(form, ov) {
        var h3 = ov.querySelector('.modal-header h3');
        var entity = h3 ? h3.textContent.trim().replace(/^(add|edit|new|bulk)\s+/i, '').trim() : '';
        var n = demoSeq(form.id || ov.id || 'form');
        form.querySelectorAll('input, select, textarea').forEach(function (el) {
            var type = (el.type || '').toLowerCase();
            if (el.disabled || el.readOnly || ['hidden', 'file', 'submit', 'button', 'reset', 'search'].indexOf(type) > -1) return; // never the record id
            if (type === 'checkbox') el.checked = n % 2 === 1;
            else if (el.tagName === 'SELECT') {
                var opts = [].filter.call(el.options, function (o) { return o.value !== '' && !o.disabled; });
                if (!opts.length) return;
                el.value = opts[(n - 1) % opts.length].value;
            } else {
                var g = el.closest('.form-group'), lab = g && g.querySelector('label');
                var v = demoValue(el.name || el.id, type, n, entity, lab ? lab.textContent.replace(/\*/g, '').trim() : '');
                if (type === 'number') { // keep inside the field's own min / max
                    if (el.max !== '' && +v > +el.max) v = el.max;
                    if (el.min !== '' && +v < +el.min) v = el.min;
                }
                var max = parseInt(el.getAttribute('maxlength') || '0', 10);
                el.value = max > 0 && v.length > max ? v.substring(0, max) : v;
            }
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            if (el.sdRefresh) el.sdRefresh(); // searchable widget shows the new pick
        });
        if (window.Swal) Swal.fire({ toast: true, position: 'top-end', icon: 'info', title: 'Demo set #' + n, timer: 1500, showConfirmButton: false });
    }
    function inject() {
        document.querySelectorAll('.modal-overlay').forEach(function (ov) {
            var form = ov.querySelector('form'), head = ov.querySelector('.modal-header');
            if (!form || !head || head.querySelector('.demo-seed-btn') || /bulk|upload|import|categor/i.test(ov.id)) return;
            var tools = head.querySelector('.modal-head-tools');
            if (!tools) {
                tools = document.createElement('div');
                tools.className = 'modal-head-tools';
                head.appendChild(tools);
                var close = head.querySelector('.close-btn');
                if (close) tools.appendChild(close);
            }
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'close-btn demo-seed-btn';
            b.title = 'Fill this form with demo data (testing)';
            b.setAttribute('aria-label', 'Fill demo data');
            b.innerHTML = '<i class="fas fa-circle-info"></i>';
            b.addEventListener('click', function () { seed(form, ov); });
            tools.insertBefore(b, tools.firstChild);
        });
    }
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', inject) : inject();
})();
<?php endif; ?>
</script>
