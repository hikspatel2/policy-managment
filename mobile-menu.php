<?php
/**
 * Developed by Rudees Digital
 * Website: https://rudeesdigital.com
 * Email: contact@rudeesdigital.com
 * Website: https://rudeesdigital.com
  Email: contact@rudeesdigital.com
  WhatsApp: +91 8160140848
 *
 * Mobile Bottom Navigation Bar
 * Include this file in all dashboard pages (before app-container)
 */

// four slots per role + More (opens the sidebar drawer). notif pill live via sbPill()
$mob_current = $current_page ?? '';
$mob_role = $role ?? 'agent';
$mob_badges = function_exists('navBadges') ? navBadges($user_id ?? ($_SESSION['user_id'] ?? 0), $mob_role) : ['notif' => 0, 'todo' => 0];
$mob_items = in_array($mob_role, ['admin', 'manager'], true)
    ? ['dashboard' => ['dashboard.php', 'fa-chart-line', 'Dashboard']]
    : ['agents' => ['agents.php', 'fa-id-card', 'My Profile']];
$mob_items += [
    'policies'      => ['policy_entries.php', 'fa-file-invoice', 'Policies'],
    'vehicles'      => ['vehicles.php', 'fa-car', 'Vehicles'],
    'notifications' => ['notifications.php', 'fa-bell', 'Notifications'],
];
$mob_pill = function ($n) { return $n > 0 ? '<em class="mobile-nav-pill">' . ($n > 9 ? '9+' : (int)$n) . '</em>' : ''; };
?>

<!-- Mobile Bottom Navigation Bar -->
<nav class="mobile-bottom-nav" id="mobileBottomNav" aria-label="Main">
    <?php foreach ($mob_items as $mob_k => [$mob_href, $mob_ico, $mob_lbl]): ?>
    <a href="<?php echo $mob_href; ?>" class="mobile-nav-item<?php echo $mob_current === $mob_k ? ' active' : ''; ?>"<?php echo $mob_k === 'notifications' ? ' data-pill="notif"' : ''; ?><?php echo $mob_current === $mob_k ? ' aria-current="page"' : ''; ?>>
        <i class="fas <?php echo $mob_ico; ?>"></i><span><?php echo $mob_lbl; ?></span><?php echo $mob_k === 'notifications' ? $mob_pill($mob_badges['notif'] ?? 0) : ''; ?>
    </a>
    <?php endforeach; ?>
    <button type="button" class="mobile-nav-item mobile-nav-more" onclick="toggleMobileMenu()" aria-label="More pages">
        <i class="fas fa-bars"></i><span>More</span><?php if (($mob_badges['todo'] ?? 0) + ($mob_badges['claims'] ?? 0) + ($mob_badges['exp'] ?? 0) > 0): ?><em class="mobile-nav-pill is-dot" aria-label="Items waiting in the menu"></em><?php endif; ?>
    </button>
</nav>

<!-- Mobile Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileMenu()"></div>

<script>
/**
 * Mobile Menu Functions
 */
function toggleMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (sidebar) {
        sidebar.classList.toggle('mobile-open');
        overlay.classList.toggle('active');

        if (sidebar.classList.contains('mobile-open')) {
            document.body.style.overflow = 'hidden';
            document.body.style.position = 'fixed';
            document.body.style.width = '100%';
            document.body.style.top = `-${window.scrollY}px`;
        } else {
            const scrollY = document.body.style.top;
            document.body.style.overflow = '';
            document.body.style.position = '';
            document.body.style.width = '';
            document.body.style.top = '';
            window.scrollTo(0, parseInt(scrollY || '0') * -1);
        }
    }
}

function closeMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (sidebar && sidebar.classList.contains('mobile-open')) {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');

        const scrollY = document.body.style.top;
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.width = '';
        document.body.style.top = '';
        window.scrollTo(0, parseInt(scrollY || '0') * -1);
    }
}

// Close mobile menu when clicking a link in sidebar
document.addEventListener('DOMContentLoaded', function() {
    const sidebarLinks = document.querySelectorAll('.sidebar-menu a:not(.submenu-toggle)');
    sidebarLinks.forEach(function(link) {
        link.addEventListener('click', function() {
            closeMobileMenu();
        });
    });

    // Close menu on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMobileMenu();
        }
    });

    // Handle swipe to close sidebar
    let touchStartX = 0;
    let touchEndX = 0;
    const sidebar = document.getElementById('sidebar');

    if (sidebar) {
        sidebar.addEventListener('touchstart', function(e) {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });

        sidebar.addEventListener('touchend', function(e) {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        }, { passive: true });
    }

    function handleSwipe() {
        const swipeThreshold = 50;
        if (touchStartX - touchEndX > swipeThreshold) {
            // Swipe left - close sidebar
            closeMobileMenu();
        }
    }

    // Prevent body scroll when touching sidebar overlay
    const overlay = document.getElementById('sidebarOverlay');
    if (overlay) {
        overlay.addEventListener('touchmove', function(e) {
            e.preventDefault();
        }, { passive: false });
    }
});

// Handle orientation change
window.addEventListener('orientationchange', function() {
    // Small delay to let the browser adjust
    setTimeout(function() {
        if (window.innerWidth > 768) {
            closeMobileMenu();
        }
    }, 100);
});

// Handle resize - close mobile menu if window becomes larger
window.addEventListener('resize', function() {
    if (window.innerWidth > 768) {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar) {
            sidebar.classList.remove('mobile-open');
        }
        if (overlay) {
            overlay.classList.remove('active');
        }
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.width = '';
        document.body.style.top = '';
    }
});
</script>
