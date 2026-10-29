<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 * TikTok: https://www.tiktok.com/@aiwithrameez (Follow for more!)
 */

// per-user theme save for the header quick menu — partial: only the posted fields change
require_once 'config.php';

header('Content-Type: application/json');

$uid = $_SESSION['user_id'] ?? null;
if (!$uid) { echo json_encode(['success' => false, 'login' => true]); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'POST required']); exit(); }
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'Security check failed']); exit(); }

// only known keys with valid values are written
$hex = '/^#[0-9A-Fa-f]{6}$/';
$f = [];
foreach (['theme_primary', 'theme_secondary', 'theme_accent'] as $k) {
    if (isset($_POST[$k]) && preg_match($hex, $_POST[$k])) $f[$k] = $_POST[$k];
}
if (isset($_POST['theme_mode']) && in_array($_POST['theme_mode'], ['light', 'dark'], true)) $f['theme_mode'] = $_POST['theme_mode'];
if (isset($_POST['theme_style']) && isset($UI_STYLES[$_POST['theme_style']])) $f['theme_style'] = $_POST['theme_style'];
if (isset($_POST['theme_shape']) && isset($UI_SHAPES[$_POST['theme_shape']])) $f['theme_shape'] = $_POST['theme_shape'];
if (isset($_POST['theme_sidebar']) && isset($UI_SIDEBARS[$_POST['theme_sidebar']])) $f['theme_sidebar'] = $_POST['theme_sidebar'];
// colours travel as a set — a lone primary would mix two palettes
$colours = array_intersect_key($f, array_flip(['theme_primary', 'theme_secondary', 'theme_accent']));
if ($colours && count($colours) < 3) {
    $cur = getUserTheme($uid);
    foreach (['theme_primary', 'theme_secondary', 'theme_accent'] as $k) $f[$k] = $f[$k] ?? $cur[$k];
}

if ($f && setUserTheme($uid, $f)) { echo json_encode(['success' => true]); exit(); }
echo json_encode(themeSchemaReady()
    ? ['success' => false, 'message' => 'Could not save your theme']
    : ['success' => false, 'needs_update' => true, 'message' => 'Not saved — an admin needs to run setup.php → Run Update (Safe)']);
exit();
