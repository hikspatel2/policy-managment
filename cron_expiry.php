<?php
/**
 * Developed by Rudees Digital
 * Website: https://rudeesdigital.com
 * Email: contact@rudeesdigital.com
 * Website: https://rudeesdigital.com
  Email: contact@rudeesdigital.com
  WhatsApp: +91 8160140848
 *
 * Daily jobs: policy expiry alerts + expired status + weekly backup.
 * Cron (optional): php cron_expiry.php — otherwise the first page load of each day runs it.
 */
require_once 'config.php';

$cli = php_sapi_name() === 'cli';
// browser = admin only
if (!$cli && (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit();
}

$res = runDailyJobs(true);
$out = $res ? ['success' => true] + $res : ['success' => false, 'message' => 'Daily jobs failed — see error_log.txt'];

if ($cli) {
    echo date('Y-m-d H:i:s') . ($res ? " - Expiry check: {$res['notifications_created']} notifications, {$res['policies_expired']} policies expired, backup: {$res['backup']}" : ' - daily jobs failed') . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($out);
}
