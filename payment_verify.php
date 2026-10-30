<?php
/**
 * Payment Verification Endpoint (Server-Side Verification)
 * Insurance Management System
 */
require_once 'config.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

if (!$userId || $role !== 'agent') {
    header("Location: login.php");
    exit();
}

$conn = getDBConnection();
$aid = agentIdForUser($conn, $userId);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: agent_plans.php");
    exit();
}

if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    die("Security verification failed. Invalid CSRF token.");
}

$planId = (int)($_POST['plan_id'] ?? 0);
$cycle = strtolower(trim($_POST['cycle'] ?? 'monthly'));
$orderId = trim($_POST['order_id'] ?? '');
$method = trim($_POST['payment_method'] ?? 'razorpay');

// Fetch plan
$pStmt = $conn->prepare("SELECT * FROM subscription_plans WHERE id = ? AND is_active = 1 LIMIT 1");
$pStmt->bind_param("i", $planId);
$pStmt->execute();
$plan = $pStmt->get_result()->fetch_assoc();
$pStmt->close();

if (!$plan) {
    die("Selected plan does not exist or is inactive.");
}

// Calculate amount
if ($cycle === 'quarterly') {
    $amount = (float)$plan['quarterly_price'];
} elseif ($cycle === 'yearly') {
    $amount = (float)$plan['yearly_price'];
} elseif ($cycle === 'trial' || $plan['is_trial']) {
    $amount = 0.00;
    $cycle = 'trial';
} else {
    $cycle = 'monthly';
    $amount = (float)$plan['monthly_price'];
}

$paymentId = null;

// Handle Razorpay verification
if ($method === 'razorpay') {
    $rzpPaymentId = trim($_POST['razorpay_payment_id'] ?? '');
    $rzpOrderId = trim($_POST['razorpay_order_id'] ?? '');
    $rzpSignature = trim($_POST['razorpay_signature'] ?? '');
    $keySecret = getSetting('razorpay_key_secret', '');

    if (empty($rzpPaymentId) || empty($rzpOrderId) || empty($rzpSignature)) {
        die("Incomplete payment details received from payment gateway.");
    }

    // Verify signature with secret
    if (!empty($keySecret)) {
        $expectedSignature = hash_hmac('sha256', $rzpOrderId . '|' . $rzpPaymentId, $keySecret);
        if (!hash_equals($expectedSignature, $rzpSignature)) {
            error_log("Razorpay signature verification failed for order: " . $rzpOrderId);
            die("Payment signature verification failed. Your card was not charged.");
        }
    }
    $paymentId = $rzpPaymentId;
    $orderId = $rzpOrderId;
} else {
    // Offline / Direct Activation Mode
    $paymentId = 'OFFLINE-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

// Prevent duplicate activation: check if this payment_id has already been processed
$chkDup = $conn->prepare("SELECT id FROM subscription_payments WHERE payment_id = ? AND status = 'completed' LIMIT 1");
$chkDup->bind_param("s", $paymentId);
$chkDup->execute();
if ($chkDup->get_result()->num_rows > 0) {
    $chkDup->close();
    header("Location: dashboard.php");
    exit();
}
$chkDup->close();

// Activate subscription
$subId = activateAgentSubscription($conn, $aid, $planId, $cycle, $paymentId, $orderId, $method, $amount);

// Check if agent first-time setup is completed
$uStmt = $conn->prepare("SELECT profile_setup_completed FROM users WHERE id = ? LIMIT 1");
$uStmt->bind_param("i", $userId);
$uStmt->execute();
$uRow = $uStmt->get_result()->fetch_assoc();
$uStmt->close();
$conn->close();

$isSetupDone = !empty($uRow['profile_setup_completed']);

if (!$isSetupDone) {
    header("Location: agent_setup.php?activated=1");
} else {
    header("Location: dashboard.php?sub_success=1");
}
exit();
