<?php
/**
 * Razorpay Payment Webhook Handler
 * Insurance Management System
 */
require_once 'config.php';

header('Content-Type: application/json');

$webhookSecret = getSetting('razorpay_webhook_secret', '');
$rawPayload = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (!empty($webhookSecret)) {
    if (empty($signatureHeader)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing signature header']);
        exit();
    }

    $expectedSignature = hash_hmac('sha256', $rawPayload, $webhookSecret);
    if (!hash_equals($expectedSignature, $signatureHeader)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid webhook signature']);
        exit();
    }
}

$data = json_decode($rawPayload, true);
if (!$data || !isset($data['event'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit();
}

$conn = getDBConnection();

$event = $data['event'];
if ($event === 'payment.captured' || $event === 'order.paid') {
    $entity = $data['payload']['payment']['entity'] ?? ($data['payload']['order']['entity'] ?? null);
    if ($entity) {
        $paymentId = $entity['id'] ?? '';
        $orderId = $entity['order_id'] ?? ($entity['id'] ?? '');
        $amount = isset($entity['amount']) ? ((float)$entity['amount'] / 100) : 0.00;
        $notes = $entity['notes'] ?? [];

        $agentId = (int)($notes['agent_id'] ?? 0);
        $planId = (int)($notes['plan_id'] ?? 0);
        $cycle = $notes['cycle'] ?? 'monthly';

        if ($agentId > 0 && $planId > 0) {
            // Check if already processed
            $chk = $conn->prepare("SELECT id FROM subscription_payments WHERE payment_id = ? AND status = 'completed' LIMIT 1");
            $chk->bind_param("s", $paymentId);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                activateAgentSubscription($conn, $agentId, $planId, $cycle, $paymentId, $orderId, 'razorpay_webhook', $amount);
            }
            $chk->close();
        }
    }
}

$conn->close();
http_response_code(200);
echo json_encode(['status' => 'ok']);
exit();
