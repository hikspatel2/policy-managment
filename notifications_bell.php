<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 * TikTok: https://www.tiktok.com/@aiwithrameez (Follow for more!)
 *
 * Header tools — page search, notification bell, light/dark, account menu.
 * Included by page_header.php (page scope: $user_id, $role, $username).
 */

// avatar row — one query
$_me = ['name' => $username ?? '', 'email' => '', 'profile_image' => ''];
try {
    $_db = getDBConnection();
    $_st = $_db->prepare("SELECT name, email, profile_image FROM users WHERE id = ?");
    $_uid = (int)($user_id ?? 0);
    $_st->bind_param("i", $_uid);
    $_st->execute();
    if ($_row = $_st->get_result()->fetch_assoc()) $_me = $_row;
    $_st->close();
    $_db->close();
} catch (Throwable $e) {
    $_me['profile_image'] = function_exists('getProfileImage') ? (getProfileImage($user_id ?? 0) ?: '') : '';
}
$_logo   = function_exists('getBranding') ? (getBranding()['site_logo'] ?? '') : '';
$_avatar = !empty($_me['profile_image']) ? $_me['profile_image'] : $_logo;
$_roleLb = ucfirst((string)($role ?? ''));
?>
<div class="header-right">
    <!-- page search — indexes the sidebar links, so it only shows pages you may open -->
    <div class="header-tool-wrapper hdr-search-wrap">
        <div class="hdr-search">
            <i class="fas fa-magnifying-glass"></i>
            <input type="search" id="hdrSearch" placeholder="Search pages&hellip;" autocomplete="off" aria-label="Search pages"
                   onfocus="openSearch(event)" oninput="runSearch()" onkeydown="searchKey(event)">
            <kbd class="hdr-kbd" title="Press / to search">/</kbd>
        </div>
        <div class="header-tool-dropdown search-menu" id="searchMenu"></div>
    </div>

    <!-- theme: style · shape · sidebar · palette — instant, saved per user (theme_save.php) -->
    <?php $__uiTheme = getUserTheme($user_id ?? 0); ?>
    <div class="header-tool-wrapper" data-hdr-tool>
        <button type="button" class="header-tool-btn" onclick="toggleThemeMenu(event)" title="Theme &amp; appearance" aria-label="Theme and appearance"><i class="fas fa-palette"></i><span class="hdr-tool-lbl">Theme</span></button>
        <div class="header-tool-dropdown theme-quick-menu" id="themeQuickMenu">
            <div class="header-tool-header">
                <strong><i class="fas fa-palette"></i> Theme</strong>
                <button type="button" class="mode-toggle-btn" onclick="rsHdrMode()" title="Toggle light / dark">
                    <i class="fas fa-adjust"></i> <span id="quickModeLabel">Dark</span>
                </button>
            </div>
            <div class="theme-quick-style" role="group" aria-label="UI style">
                <?php foreach ($UI_STYLES as $__k => $__o): ?>
                <button type="button" class="mode-toggle-btn<?php echo $__uiTheme['theme_style'] === $__k ? ' active' : ''; ?>" data-style="<?php echo $__k; ?>" onclick="quickStyle('<?php echo $__k; ?>')" title="<?php echo htmlspecialchars($__o['hint']); ?>">
                    <i class="fas <?php echo $__o['icon']; ?>"></i> <?php echo htmlspecialchars($__o['label']); ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="theme-quick-style" role="group" aria-label="Shape">
                <?php foreach ($UI_SHAPES as $__k => $__o): ?>
                <button type="button" class="mode-toggle-btn<?php echo $__uiTheme['theme_shape'] === $__k ? ' active' : ''; ?>" data-shape="<?php echo $__k; ?>" onclick="quickShape('<?php echo $__k; ?>')" title="<?php echo htmlspecialchars($__o['hint']); ?>">
                    <i class="fas <?php echo $__o['icon']; ?>"></i> <?php echo htmlspecialchars($__o['label']); ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="theme-quick-style theme-quick-sb" role="group" aria-label="Sidebar colour">
                <span class="tq-label"><i class="fas fa-table-columns"></i> Sidebar</span>
                <?php foreach ($UI_SIDEBARS as $__k => $__o): ?>
                <button type="button" class="mode-toggle-btn<?php echo $__uiTheme['theme_sidebar'] === $__k ? ' active' : ''; ?>" data-sb="<?php echo $__k; ?>" onclick="quickSidebar('<?php echo $__k; ?>')" title="<?php echo htmlspecialchars($__o['hint']); ?>">
                    <i class="fas <?php echo $__o['icon']; ?>"></i> <?php echo htmlspecialchars($__o['label']); ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="theme-quick-grid">
                <?php foreach ($UI_PALETTES as $__pal):
                    $__on = (strcasecmp($__pal['p'], $__uiTheme['theme_primary']) === 0 && strcasecmp($__pal['a'], $__uiTheme['theme_accent']) === 0) ? ' active' : ''; ?>
                <button type="button" class="theme-quick-swatch<?php echo $__on; ?>" title="<?php echo htmlspecialchars($__pal['name']); ?>" aria-label="<?php echo htmlspecialchars($__pal['name']); ?>"
                        data-p="<?php echo $__pal['p']; ?>" data-a="<?php echo $__pal['a']; ?>"
                        onclick="quickApplyTheme('<?php echo $__pal['p']; ?>','<?php echo $__pal['s']; ?>','<?php echo $__pal['a']; ?>')">
                    <span style="background: linear-gradient(135deg, <?php echo $__pal['p']; ?> 0 45%, <?php echo $__pal['a']; ?> 55% 100%);"></span>
                </button>
                <?php endforeach; ?>
            </div>
            <a href="account.php" class="header-tool-footer">More theme options <i class="fas fa-arrow-right"></i></a>
        </div>
    </div>

    <div class="notification-bell-wrapper">
        <button type="button" class="notification-bell-btn" onclick="toggleNotificationDropdown(event)" title="Notifications" aria-label="Notifications">
            <i class="fas fa-bell"></i>
            <span class="notification-badge" id="notifBadge" style="display:none;">0</span>
        </button>
        <div class="notification-dropdown" id="notifDropdown">
            <div class="notification-dropdown-header">
                <strong>Notifications</strong>
                <button type="button" onclick="markAllNotificationsRead()" title="Mark all read"><i class="fas fa-check-double"></i> Mark all read</button>
            </div>
            <div class="notification-dropdown-body" id="notifList">
                <div class="notification-empty"><i class="fas fa-bell-slash"></i><br>No notifications</div>
            </div>
            <a class="notification-dropdown-foot" href="notifications.php"><i class="fas fa-list"></i> View all notifications</a>
        </div>
    </div>

    <!-- light / dark -->
    <button type="button" class="header-tool-btn moon-btn" data-hdr-tool onclick="rsHdrMode()" title="Light / dark" aria-label="Toggle light or dark mode">
        <i class="fas fa-moon" id="modeIcon"></i><span class="hdr-tool-lbl">Dark mode</span>
    </button>

    <!-- account -->
    <div class="header-tool-wrapper hdr-user-wrap">
        <button type="button" class="hdr-user" onclick="toggleUserMenu(event)" title="Account" aria-label="Account menu">
            <img src="<?php echo htmlspecialchars($_avatar); ?>" alt="" class="hdr-user-av" width="32" height="32" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($_logo, ENT_QUOTES); ?>'">
            <span class="hdr-user-txt">
                <span class="hdr-user-name"><?php echo htmlspecialchars($_me['name']); ?></span>
                <small class="hdr-user-role"><?php echo htmlspecialchars($_roleLb); ?></small>
            </span>
            <i class="fas fa-chevron-down hdr-user-caret"></i>
        </button>
        <div class="header-tool-dropdown user-menu" id="userMenu">
            <div class="user-card">
                <img src="<?php echo htmlspecialchars($_avatar); ?>" alt="" width="44" height="44" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($_logo, ENT_QUOTES); ?>'">
                <div class="user-card-txt">
                    <strong><?php echo htmlspecialchars($_me['name']); ?></strong>
                    <small><?php echo htmlspecialchars($_me['email']); ?></small>
                </div>
            </div>
            <a href="account.php" class="user-link"><i class="fas fa-circle-user"></i> My Account</a>
            <?php if (($role ?? '') === 'admin'): ?>
            <a href="settings.php" class="user-link"><i class="fas fa-gear"></i> Settings</a>
            <?php endif; ?>
            <a href="logout.php" class="user-link danger" data-noprefetch><i class="fas fa-right-from-bracket"></i> Logout</a>
        </div>
    </div>
</div>

<script>
(function () {
    if (window._hdrToolsInit) return;
    window._hdrToolsInit = true;

    var ME = <?php echo (int)($user_id ?? 0); ?>,
        CSRF = window.CSRF_TOKEN || <?php echo json_encode(generateCSRFToken(), JSON_HEX_TAG); ?>,
        API = 'notifications.php';

    function esc(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function getJson(qs) {
        return fetch(API + '?' + qs, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function post(action, body) {
        body.csrf_token = window.CSRF_TOKEN || CSRF;
        return fetch(API + '?action=' + action, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(body).toString()
        }).then(function (r) { return r.json(); });
    }

    // one closer for every dropdown up here
    function closeTools(except) {
        ['searchMenu', 'userMenu', 'notifDropdown', 'themeQuickMenu'].forEach(function (id) {
            if (id !== except) { var el = document.getElementById(id); if (el) el.classList.remove('open'); }
        });
    }
    function flip(id, e) {
        if (e) e.stopPropagation();
        var m = document.getElementById(id), on = !m.classList.contains('open');
        closeTools(id);
        m.classList.toggle('open', on);
        return on;
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.header-tool-wrapper, .notification-bell-wrapper')) closeTools();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeTools(); });

    window.toggleUserMenu = function (e) { flip('userMenu', e); };

    // theme menu — every choice shows at once and is saved per user (theme_save.php)
    window.toggleThemeMenu = function (e) { flip('themeQuickMenu', e); };
    function savTheme(params) {
        fetch('theme_save.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(Object.assign({ csrf_token: CSRF }, params)).toString()
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j.success && window.Swal) Swal.fire({ toast: true, position: 'top-end', icon: 'warning', timer: 4000, showConfirmButton: false, title: j.message || 'Could not save your theme' });
        }).catch(function () {});
    }
    var pick = function (attr, k) {
        document.querySelectorAll('.theme-quick-style [data-' + attr + ']').forEach(function (b) { b.classList.toggle('active', b.dataset[attr] === k); });
    };
    window.quickApplyTheme = function (p, s, a) {
        if (window.rsSkin) rsSkin(p, s, a);
        savTheme({ theme_primary: p, theme_secondary: s, theme_accent: a });
        document.querySelectorAll('.theme-quick-swatch').forEach(function (sw) {
            sw.classList.toggle('active', sw.dataset.p.toLowerCase() === p.toLowerCase() && sw.dataset.a.toLowerCase() === a.toLowerCase());
        });
    };
    window.quickStyle = function (k) { document.documentElement.setAttribute('data-ui', k); pick('style', k); savTheme({ theme_style: k }); };
    window.quickShape = function (k) { document.documentElement.setAttribute('data-shape', k); pick('shape', k); savTheme({ theme_shape: k }); };
    window.quickSidebar = function (k) { document.documentElement.setAttribute('data-sb', k); pick('sb', k); savTheme({ theme_sidebar: k }); };
    // light / dark from anywhere (header moon, sidebar switch, this menu) — saved too
    window.quickToggleMode = function () {
        var isDark = document.body.classList.toggle('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        if (typeof updateThemeButton === 'function') updateThemeButton(isDark);
        var lbl = document.getElementById('quickModeLabel'); if (lbl) lbl.textContent = isDark ? 'Light' : 'Dark';
        savTheme({ theme_mode: isDark ? 'dark' : 'light' });
    };
    (function () { var l = document.getElementById('quickModeLabel'); if (l) l.textContent = document.body.classList.contains('dark-mode') ? 'Light' : 'Dark'; })();

    // bell
    var TYPE_ICO = {
        policy_expiry: 'fa-calendar-xmark text-danger',
        payment_pending: 'fa-money-bill-wave text-warning',
        commission_update: 'fa-hand-holding-dollar text-success',
        general: 'fa-circle-info text-info'
    };
    function ago(d) {
        var s = Math.floor((Date.now() - new Date(String(d).replace(' ', 'T'))) / 1000);
        if (isNaN(s)) return '';
        if (s < 60) return 'Just now';
        if (s < 3600) return Math.floor(s / 60) + 'm ago';
        if (s < 86400) return Math.floor(s / 3600) + 'h ago';
        return Math.floor(s / 86400) + 'd ago';
    }
    function badge(n) {
        n = +n || 0;
        var b = document.getElementById('notifBadge'), t = n > 99 ? '99+' : n;
        if (b) { b.textContent = t; b.style.display = n ? 'flex' : 'none'; }
        if (window.sbPill) return sbPill('notif', n);
        document.querySelectorAll('[data-pill="notif"] .nav-pill').forEach(function (p) { p.textContent = t; p.hidden = !n; });
    }
    function render(list) {
        var box = document.getElementById('notifList');
        if (!list.length) { box.innerHTML = '<div class="notification-empty"><i class="fas fa-bell-slash"></i><br>No notifications</div>'; return; }
        box.innerHTML = list.map(function (n) {
            var unread = n.is_read == 0;
            return '<div class="notification-item' + (unread ? ' unread' : '') + '" data-id="' + (+n.id) + '" data-pid="' + (+n.policy_entry_id || '') + '" role="button" tabindex="0">'
                 + '<div class="notification-item-icon"><i class="fas ' + (TYPE_ICO[n.type] || TYPE_ICO.general) + '"></i></div>'
                 + '<div class="notification-item-content">'
                 +   '<div class="notification-item-title">' + esc(n.title) + '</div>'
                 +   '<div class="notification-item-message">' + esc(n.message) + '</div>'
                 +   '<div class="notification-item-time">' + esc(ago(n.created_at)) + '</div>'
                 + '</div>'
                 + (unread ? '<button type="button" class="notification-item-mark" title="Mark read" aria-label="Mark read"><i class="fas fa-check"></i></button>' : '')
                 + '</div>';
        }).join('');
    }
    // my latest 8 + unread count, both from the server (no full-table download)
    function loadNotifications() {
        getJson('action=getNotifications&mine=1&limit=8').then(function (r) {
            if (!r || !r.success) return;
            render(r.data || []);
            badge(r.unread || 0);
        }).catch(function () {});
    }
    function pollNotificationCount() {
        getJson('action=getUnreadCount').then(function (r) { if (r && r.success) badge(r.count); }).catch(function () {});
    }
    window.pollNotificationCount = pollNotificationCount; // notifications.php can refresh the bell after its writes

    window.toggleNotificationDropdown = function (e) { if (flip('notifDropdown', e)) loadNotifications(); };

    function markRead(id) { return post('markRead', { id: id }).catch(function () {}); }
    function openItem(it, goTo) {
        var id = +it.dataset.id, pid = +it.dataset.pid, was = it.classList.contains('unread');
        var done = was ? markRead(id) : Promise.resolve();
        if (!goTo) {
            it.classList.remove('unread');
            var b = it.querySelector('.notification-item-mark'); if (b) b.remove();
            return done.then(pollNotificationCount);
        }
        done.then(function () { location.href = pid ? 'policy_entries.php?id=' + pid : 'notifications.php'; });
    }
    var list = document.getElementById('notifList');
    list.addEventListener('click', function (e) {
        var it = e.target.closest('.notification-item'); if (!it) return;
        e.stopPropagation();
        openItem(it, !e.target.closest('.notification-item-mark'));
    });
    list.addEventListener('keydown', function (e) {
        var it = e.target.closest('.notification-item');
        if (it && e.key === 'Enter') openItem(it, true);
    });

    window.markAllNotificationsRead = function () {
        post('markAllRead', {}).then(function (r) { if (r && r.success) { badge(0); loadNotifications(); } }).catch(function () {});
    };

    pollNotificationCount();
    setInterval(function () { if (!document.hidden) pollNotificationCount(); }, 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) pollNotificationCount(); });

    // page search — index built from the sidebar, so it inherits role gating
    var idx = [];
    function buildIndex() {
        var seen = {};
        idx = [].map.call(document.querySelectorAll('.sidebar-menu a[href]:not([href="#"]), .sidebar a[data-find]'), function (a) {
            var i = a.querySelector('i');
            return { label: (a.getAttribute('data-find') || a.textContent || '').trim().replace(/\s+/g, ' '), href: a.getAttribute('href'), icon: i ? i.className : 'fas fa-file' };
        }).filter(function (x) {
            if (!x.label || /^javascript:/i.test(x.href) || seen[x.href]) return false;
            return (seen[x.href] = true);
        });
    }
    window.openSearch = function (e) {
        if (e) e.stopPropagation();
        if (!idx.length) buildIndex();
        closeTools('searchMenu');
        runSearch();
    };
    window.runSearch = function () {
        var q = (document.getElementById('hdrSearch').value || '').trim().toLowerCase(), box = document.getElementById('searchMenu');
        var hits = (q ? idx.filter(function (x) { return x.label.toLowerCase().indexOf(q) > -1; }) : idx).slice(0, 8);
        box.innerHTML = hits.length
            ? hits.map(function (x, k) {
                return '<a class="search-hit' + (k ? '' : ' on') + '" href="' + esc(x.href) + '"><i class="' + esc(x.icon) + '"></i><span>' + esc(x.label) + '</span>'
                     + '<em>Open <i class="fas fa-arrow-turn-down"></i></em></a>';
              }).join('')
            : '<div class="msg-none"><i class="fas fa-magnifying-glass"></i><span>Nothing matches &ldquo;' + esc(q) + '&rdquo;</span></div>';
        box.classList.add('open');
    };
    window.searchKey = function (e) {
        var hits = document.getElementById('searchMenu').querySelectorAll('.search-hit');
        if (e.key === 'Escape') { closeTools(); e.target.blur(); return; }
        if (!hits.length) return;
        var at = 0;
        hits.forEach(function (h, k) { if (h.classList.contains('on')) at = k; });
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            hits[at].classList.remove('on');
            at = (at + (e.key === 'ArrowDown' ? 1 : hits.length - 1)) % hits.length;
            hits[at].classList.add('on');
            hits[at].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            location.href = hits[at].getAttribute('href');
        }
    };
    // "/" jumps to search
    document.addEventListener('keydown', function (e) {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
        var t = e.target.tagName;
        if (t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT' || e.target.isContentEditable) return;
        var s = document.getElementById('hdrSearch');
        if (s && s.offsetParent) { e.preventDefault(); s.focus(); }
        else if (window.sbSearchOpen && window.innerWidth > 768) { e.preventDefault(); sbSearchOpen(); }
    });

    // moon/sun follows whatever flipped the mode
    window.syncModeIcon = function () {
        var i = document.getElementById('modeIcon');
        if (i) i.className = document.body.classList.contains('dark-mode') ? 'fas fa-sun' : 'fas fa-moon';
    };
    window.rsHdrMode = function () {
        if (typeof window.quickToggleMode === 'function') quickToggleMode();
        else if (typeof window.toggleTheme === 'function') toggleTheme();
        else localStorage.setItem('theme', document.body.classList.toggle('dark-mode') ? 'dark' : 'light');
        syncModeIcon();
    };
    syncModeIcon();
})();
</script>
