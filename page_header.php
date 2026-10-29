<?php
/**
 * Developed by Rudees Digital
 * Website: https://rudeesdigital.com
 * Email: contact@rudeesdigital.com
 * Website: https://rudeesdigital.com
  Email: contact@rudeesdigital.com
  WhatsApp: +91 8160140848
 *
 * Shared page head — one card, two tiers.
 *   tier 1: page icon + title with the breadcrumb line under it | search · tools · account
 *   tier 2 (only when the page has filters or buttons, under a hairline):
 *           filters left | page buttons right (primary keeps its label, the rest are icon + tooltip)
 * Filters never live inside the table card — a page marks its strip [data-head-filter] and it is
 * moved up here before paint. One place, so a nav change never has to be repeated across pages.
 *
 * Set before including:
 *   $pageIcon    'fa-users'                                       (required)
 *   $pageTitle   'User Management'                                (required)
 *   $crumbs      [['label'=>'System','icon'=>'fa-server'], ...]   trail between Home and this page
 *   $pageActions [['label'=>'Add User','icon'=>'fa-plus','onclick'=>'openAddModal()','class'=>'primary']]
 */

$pageIcon    = $pageIcon ?? 'fa-file';
$pageTitle   = $pageTitle ?? '';
$crumbs      = isset($crumbs) && is_array($crumbs) ? $crumbs : [];
$pageActions = isset($pageActions) && is_array($pageActions) ? $pageActions : [];
$_home      = 'dashboard.php'; // every role lands here after login (agents get their own view in it)
$_isHome     = basename($_SERVER['PHP_SELF'] ?? '') === $_home;
$_ico        = strpos($pageIcon, ' ') !== false ? $pageIcon : 'fas ' . $pageIcon; // brands arrive pre-prefixed

// one crumb node — link when it has an href, plain group label otherwise
$_crumb = function ($label, $icon = '', $href = '') {
    $ico = $icon ? '<i class="fas ' . htmlspecialchars($icon) . '"></i>' : '';
    return $href
        ? '<a href="' . htmlspecialchars($href) . '">' . $ico . '<span>' . htmlspecialchars($label) . '</span></a>'
        : '<span class="crumb-group">' . $ico . htmlspecialchars($label) . '</span>';
};
$_sep = '<i class="fas fa-chevron-right crumb-sep"></i>';
?>
<div class="header" id="rsHeader">
    <div class="head-title">
        <span class="head-ico" aria-hidden="true"><i class="<?php echo htmlspecialchars($_ico); ?>"></i></span>
        <div class="head-txt">
        <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
        <nav class="head-crumbs" aria-label="Breadcrumb">
            <?php echo $_crumb('Home', 'fa-house', $_home) . $_sep; ?>
            <?php if (!$_isHome) foreach ($crumbs as $_c) echo $_crumb($_c['label'], $_c['icon'] ?? '', $_c['href'] ?? '') . $_sep; ?>
            <span class="crumb-now" aria-current="page"><?php echo htmlspecialchars($pageTitle); ?></span>
        </nav>
        </div>
    </div>
    <div class="head-actions">
        <!-- [data-head-filter] strips land here -->
        <div class="head-filters" id="rsHeadFilters" hidden></div>
        <!-- desktop: display:contents, buttons sit in tier 2. phones: the ⋮ sheet (primary floats above the bottom bar) -->
        <div class="hdr-sheet" id="rsHdrSheet">
        <div class="hdr-sheet-page">
        <div class="head-btns" id="rsPageActions">
        <?php foreach ($pageActions as $_a): ?>
        <button type="button" class="head-btn <?php echo htmlspecialchars($_a['class'] ?? ''); ?>"<?php echo isset($_a['id']) ? ' id="' . htmlspecialchars($_a['id']) . '"' : ''; ?> onclick="<?php echo htmlspecialchars($_a['onclick'] ?? '', ENT_QUOTES); ?>" title="<?php echo htmlspecialchars($_a['label']); ?>" aria-label="<?php echo htmlspecialchars($_a['label']); ?>">
            <i class="<?php echo htmlspecialchars(strpos($_a['icon'], ' ') !== false ? $_a['icon'] : 'fas ' . $_a['icon']); ?>"></i><span><?php echo htmlspecialchars($_a['label']); ?></span>
        </button>
        <?php endforeach; ?>
        </div>
        </div>
        <div class="hdr-sheet-app" id="rsHdrApp"></div>
        </div>
        <?php include 'notifications_bell.php'; ?>
        <button type="button" class="hdr-more-btn" id="rsHdrMore" aria-expanded="false" aria-controls="rsHdrSheet" title="More" aria-label="More options"><i class="fas fa-ellipsis-vertical"></i></button>
    </div>
</div>
<script>
// filter strips marked [data-head-filter] move up into the header — runs at parse time, the strips sit
// further down the page so it listens for them; tier 2 shows only once something is in it
(function () {
    var slot = document.getElementById('rsHeadFilters');
    // phones: the first field (search) stays, the rest fold behind a Filters toggle that counts what is set
    function fold(s) {
        if (s.querySelector('.hf-toggle') || s.children.length < 2) return;
        var t = document.createElement('button');
        t.type = 'button'; t.className = 'hf-toggle'; t.setAttribute('aria-expanded', 'false');
        t.innerHTML = '<i class="fas fa-sliders"></i><span>Filters</span><em hidden></em>';
        s.children[0].after(t);
        var count = function () {
            var n = 0, rest = [].slice.call(s.children).slice(2);
            rest.forEach(function (el) {
                el.querySelectorAll('input:not([type="hidden"]), select').forEach(function (f) { if (f.value && f.type !== 'search') n++; });
            });
            var em = t.querySelector('em'); em.textContent = n; em.hidden = !n;
            t.classList.toggle('has-on', !!n);
        };
        t.addEventListener('click', function () { var on = s.classList.toggle('hf-open'); t.setAttribute('aria-expanded', on ? 'true' : 'false'); });
        ['input', 'change', 'click'].forEach(function (ev) { s.addEventListener(ev, function () { setTimeout(count, 0); }); });
        count();
    }
    function pull() {
        [].slice.call(document.querySelectorAll('[data-head-filter]')).forEach(function (s) {
            if (s.parentNode === slot) return;
            s.classList.add('hf-strip');
            slot.appendChild(s);
            slot.hidden = false;
            fold(s);
        });
    }
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', pull) : pull();
    window.rsPullHeadFilters = function () { pull(); fitSoon(); };

    // desktop: the filter row stays ONE line — fields that don't fit move behind "More filters"
    // (search + clear stay out; a placeholder remembers each field's home so phones / a wider window get it back)
    var desk = matchMedia('(min-width: 769px)'), wrap, btn, pop, moved = [], timer, lastW = -1;
    var over = function () { return slot.scrollWidth > slot.clientWidth + 1; };
    function ui() {
        if (wrap) return;
        wrap = document.createElement('div'); wrap.className = 'hf-more';
        wrap.innerHTML = '<button type="button" class="hf-more-btn" aria-expanded="false" aria-haspopup="true"><i class="fas fa-sliders"></i><span>More filters</span><em hidden></em></button><div class="hf-more-pop" role="group" aria-label="More filters" hidden></div>';
        btn = wrap.firstChild; pop = wrap.lastChild;
        btn.addEventListener('click', function (e) { e.stopPropagation(); show(pop.hidden); });
        document.addEventListener('mousedown', function (e) {
            if (!pop.hidden && !wrap.contains(e.target) && !e.target.closest('.searchable-dropdown-list, .sd-panel')) show(false);
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !pop.hidden) { show(false); btn.focus(); } });
        ['input', 'change'].forEach(function (ev) { pop.addEventListener(ev, function () { setTimeout(badge, 0); }); });
    }
    function show(on) { pop.hidden = !on; btn.setAttribute('aria-expanded', on ? 'true' : 'false'); btn.classList.toggle('open', on); }
    function badge() {
        var n = 0;
        pop.querySelectorAll('input:not([type="hidden"]), select').forEach(function (f) { if (f.value) n++; });
        var em = btn.querySelector('em'); em.textContent = n; em.hidden = !n; btn.classList.toggle('has-on', !!n);
    }
    function restore() {
        moved.forEach(function (m) { m.mark.replaceWith(m.el); });
        moved = [];
        if (wrap) { show(false); wrap.remove(); }
    }
    // fields that may fold: everything but the first one (search), clear buttons and phone toggles
    function movable() {
        var first = slot.querySelector('.hf-strip > :not(.hf-toggle)');
        return [].slice.call(slot.querySelectorAll('.hf-strip > *')).filter(function (el) {
            return el !== first && !el.matches('.btn-icon, .hf-toggle, .hf-more, script') && el.offsetParent !== null;
        });
    }
    function fit() {
        restore();
        lastW = slot.clientWidth;
        if (!desk.matches || slot.hidden) return;
        if (!over()) return;
        ui();
        var clear = slot.querySelector('.hf-strip > .btn-icon:last-child');
        clear ? clear.before(wrap) : slot.appendChild(wrap);
        var list = movable();
        while (over() && list.length) {
            var el = list.pop(), mark = document.createComment('hf');
            el.replaceWith(mark);
            pop.prepend(el);
            moved.unshift({ el: el, mark: mark });
        }
        if (!moved.length) { wrap.remove(); return; }
        badge();
    }
    function fitSoon() { clearTimeout(timer); timer = setTimeout(fit, 60); }
    window.addEventListener('resize', fitSoon);
    desk.addEventListener('change', fitSoon);
    window.addEventListener('load', fitSoon);
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', fitSoon) : fitSoon();
    // the room changes without a window resize too: sidebar folded / opened, dropdowns and the icon
    // font finishing late. re-fit only when the row overflows or its width moved — our own folding
    // leaves neither behind, so this can't loop
    if (window.ResizeObserver) {
        var ro = new ResizeObserver(function () { if (desk.matches && (over() || slot.clientWidth !== lastW)) fitSoon(); });
        var watch = function () { ro.observe(slot); slot.querySelectorAll('.hf-strip').forEach(function (st) { ro.observe(st); }); };
        var pullFit = window.rsPullHeadFilters;
        window.rsPullHeadFilters = function () { pullFit(); watch(); };
        watch();
    }
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitSoon);
    // a strip a page shows / hides later (custom date range) changes the room
    new MutationObserver(function (ms) {
        if (ms.some(function (m) { return m.target.matches && m.target.matches('[data-head-filter]'); })) fitSoon();
    }).observe(slot, { attributes: true, attributeFilter: ['style', 'hidden'], subtree: true });
})();

// phone <-> desktop: app tools, the alerts/install dock and the filter slot move between the bar and their phone homes
(function () {
    var hd = document.getElementById('rsHeader'), sheet = document.getElementById('rsHdrSheet'), more = document.getElementById('rsHdrMore');
    var mq = matchMedia('(max-width: 768px)'), filters = document.getElementById('rsHeadFilters');
    var tools = [].slice.call(hd.querySelectorAll('[data-hdr-tool]')), marks = tools.map(function (t) { var c = document.createComment(''); t.before(c); return c; });
    var fMark = document.createComment(''), fBar = document.createElement('div');
    filters.before(fMark); fBar.className = 'hf-bar';
    // dock exists up front so push_register / pwa_install drop their buttons into it wherever it lives
    var dock = document.getElementById('rsFloatDock') || Object.assign(document.createElement('div'), { id: 'rsFloatDock', className: 'rs-float-dock is-inline' });
    function open(on) { hd.classList.toggle('is-more', on); more.setAttribute('aria-expanded', on ? 'true' : 'false'); }
    function place() {
        var m = mq.matches;
        tools.forEach(function (t, i) { m ? document.getElementById('rsHdrApp').appendChild(t) : marks[i].after(t); });
        (m ? sheet : document.getElementById('rsSideDock') || document.body).appendChild(dock);
        m ? (hd.after(fBar), fBar.appendChild(filters)) : (fMark.after(filters), fBar.remove());
        if (!m) open(false);
    }
    more.addEventListener('click', function (e) { e.stopPropagation(); open(!hd.classList.contains('is-more')); });
    document.addEventListener('click', function (e) {
        if (!hd.classList.contains('is-more')) return;
        if (!sheet.contains(e.target) || e.target.closest('.head-btn, .rs-float-btn, a[href]')) open(false); // outside, or a sheet button did its job
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') open(false); });
    mq.addEventListener('change', place);
    place();
})();
</script>
