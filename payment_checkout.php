<?php
/**
 * Payment Checkout Page
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
$branding = getBranding();
$currency = $branding['currency_symbol'] ?? '₹';
$aid = agentIdForUser($conn, $userId);

$planId = (int)($_GET['plan_id'] ?? 0);
$cycle = strtolower(trim($_GET['cycle'] ?? 'monthly'));
if (!in_array($cycle, ['monthly', 'quarterly', 'yearly', 'trial'], true)) {
    $cycle = 'monthly';
}

// Fetch plan
$pStmt = $conn->prepare("SELECT * FROM subscription_plans WHERE id = ? AND is_active = 1 LIMIT 1");
$pStmt->bind_param("i", $planId);
$pStmt->execute();
$plan = $pStmt->get_result()->fetch_assoc();
$pStmt->close();

if (!$plan) {
    header("Location: agent_plans.php");
    exit();
}

// If trial plan
if ($plan['is_trial']) {
    $cycle = 'trial';
    $amount = 0.00;
} else {
    if ($cycle === 'quarterly') {
        $amount = (float)$plan['quarterly_price'];
    } elseif ($cycle === 'yearly') {
        $amount = (float)$plan['yearly_price'];
    } else {
        $cycle = 'monthly';
        $amount = (float)$plan['monthly_price'];
    }
}

// Fetch agent & user details
$agentStmt = $conn->prepare("SELECT a.*, u.email AS user_email FROM agents a JOIN users u ON u.id = a.user_id WHERE a.id = ? LIMIT 1");
$agentStmt->bind_param("i", $aid);
$agentStmt->execute();
$agent = $agentStmt->get_result()->fetch_assoc();
$agentStmt->close();

// Check gateway settings
$razorpayEnabled = getSetting('razorpay_enabled', '0') === '1';
$razorpayKeyId = getSetting('razorpay_key_id', '');
$razorpayKeySecret = getSetting('razorpay_key_secret', '');
$offlineEnabled = getSetting('payment_offline_enabled', '1') === '1';

// If amount is 0 (Free Trial), instant server-side activation
if ($amount == 0.00) {
    $subId = activateAgentSubscription($conn, $aid, $planId, $cycle, 'TRIAL-' . uniqid(), 'ORD-TRIAL-' . uniqid(), 'free_trial', 0.00);
    $conn->close();
    header("Location: agent_setup.php?activated=1");
    exit();
}

// Generate server-side Order ID
$orderId = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
$orderAmountPaise = (int)round($amount * 100);

// If Razorpay API enabled and configured, create Razorpay Order via cURL
$razorpayOrderId = null;
if ($razorpayEnabled && !empty($razorpayKeyId) && !empty($razorpayKeySecret)) {
    $rpPayload = json_encode([
        'amount' => $orderAmountPaise,
        'currency' => 'INR',
        'receipt' => $orderId,
        'notes' => [
            'agent_id' => (string)$aid,
            'plan_id' => (string)$planId,
            'cycle' => $cycle
        ]
    ]);
    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $rpPayload,
        CURLOPT_USERPWD => $razorpayKeyId . ':' . $razorpayKeySecret,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);
    $rpRes = curl_exec($ch);
    $rpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($rpStatus === 200) {
        $rpData = json_decode($rpRes, true);
        if (!empty($rpData['id'])) {
            $razorpayOrderId = $rpData['id'];
            $orderId = $razorpayOrderId;
        }
    }
}

// Insert pending payment record
$insPay = $conn->prepare("INSERT INTO subscription_payments (agent_id, plan_id, order_id, amount, billing_cycle, payment_method, status) VALUES (?, ?, ?, ?, ?, 'razorpay', 'pending')");
$insPay->bind_param("iisds", $aid, $planId, $orderId, $amount, $cycle);
$insPay->execute();
$insPay->close();

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout — <?php echo htmlspecialchars($plan['name']); ?> — <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="styles.css?v=5.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php if ($razorpayEnabled && $razorpayOrderId): ?>
        <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <?php endif; ?>
    <style>
        .checkout-box {
            max-width: 720px;
            margin: 40px auto;
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        .checkout-head {
            background: #001f3f;
            color: white;
            padding: 25px 30px;
        }
        .checkout-body {
            padding: 30px;
        }
        .order-summary-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .order-summary-table td {
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14.5px;
        }
        .order-summary-table tr:last-child td {
            border-bottom: none;
            font-weight: 700;
            font-size: 18px;
            color: #001f3f;
            padding-top: 18px;
        }
        .method-card {
            border: 2px solid #cbd5e1;
            border-radius: 10px;
            padding: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .method-card:hover, .method-card.active {
            border-color: #0074D9;
            background: #f0f9ff;
        }
    </style>
</head>
<body style="background: #f8fafc; font-family: inherit;">
    <div style="padding: 20px;">
        <div class="checkout-box">
            <div class="checkout-head">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <span style="font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: #93c5fd;">Subscription Checkout</span>
                        <h2 style="margin: 4px 0 0; font-size: 24px; color: white;"><?php echo htmlspecialchars($plan['name']); ?></h2>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-size: 13px; color: #93c5fd;">Cycle</span>
                        <div style="font-size: 18px; font-weight: 700; text-transform: capitalize;"><?php echo htmlspecialchars($cycle); ?></div>
                    </div>
                </div>
            </div>

            <div class="checkout-body">
                <h3 style="margin-top: 0; color: #1e293b; font-size: 17px;"><i class="fas fa-file-invoice"></i> Order Summary</h3>
                <table class="order-summary-table">
                    <tr>
                        <td style="color: #64748b;">Plan Name</td>
                        <td style="text-align: right; font-weight: 600;"><?php echo htmlspecialchars($plan['name']); ?></td>
                    </tr>
                    <tr>
                        <td style="color: #64748b;">Billing Cycle</td>
                        <td style="text-align: right; font-weight: 600; text-transform: capitalize;"><?php echo htmlspecialchars($cycle); ?></td>
                    </tr>
                    <tr>
                        <td style="color: #64748b;">Agency / Subscriber</td>
                        <td style="text-align: right; font-weight: 600;"><?php echo htmlspecialchars($agent['agency_name'] ?: $agent['name']); ?></td>
                    </tr>
                    <tr>
                        <td style="color: #64748b;">AI Policy Scans Limit</td>
                        <td style="text-align: right; font-weight: 600;"><?php echo number_format($plan['monthly_ai_scans']); ?> scans/mo</td>
                    </tr>
                    <tr>
                        <td style="color: #64748b;">Customer Limit</td>
                        <td style="text-align: right; font-weight: 600;"><?php echo (int)$plan['max_customers'] > 99999 ? 'Unlimited' : number_format($plan['max_customers']); ?></td>
                    </tr>
                    <tr>
                        <td style="color: #64748b;">Total Amount Due</td>
                        <td style="text-align: right; color: #001f3f;"><?php echo $currency . number_format($amount, 2); ?></td>
                    </tr>
                </table>

                <h3 style="margin: 25px 0 15px; color: #1e293b; font-size: 17px;"><i class="fas fa-credit-card"></i> Choose Payment Method</h3>

                <?php if ($razorpayEnabled && $razorpayOrderId): ?>
                    <div class="method-card active" onclick="selectMethod('razorpay')">
                        <div style="display: flex; align-items: center; gap: 14px;">
                            <input type="radio" name="pay_method" id="m_razorpay" value="razorpay" checked>
                            <div>
                                <label for="m_razorpay" style="font-weight: 700; color: #0f172a; cursor: pointer; display: block;">Razorpay Secure Gateway</label>
                                <span style="font-size: 13px; color: #64748b;">UPI (GPay, PhonePe, Paytm), Credit/Debit Card, Net Banking</span>
                            </div>
                        </div>
                        <img src="https://razorpay.com/assets/razorpay-glyph.svg" alt="" width="28" height="28" style="opacity: 0.85;">
                    </div>
                <?php endif; ?>

                <?php if ($offlineEnabled || !$razorpayOrderId): ?>
                    <div class="method-card <?php echo (!$razorpayOrderId) ? 'active' : ''; ?>" onclick="selectMethod('offline')">
                        <div style="display: flex; align-items: center; gap: 14px;">
                            <input type="radio" name="pay_method" id="m_offline" value="offline" <?php echo (!$razorpayOrderId) ? 'checked' : ''; ?>>
                            <div>
                                <label for="m_offline" style="font-weight: 700; color: #0f172a; cursor: pointer; display: block;">Instant Activation / Bank Transfer</label>
                                <span style="font-size: 13px; color: #64748b;">Instant agent onboarding with invoice generation</span>
                            </div>
                        </div>
                        <i class="fas fa-bolt" style="font-size: 24px; color: #f59e0b;"></i>
                    </div>
                <?php endif; ?>

                <div style="margin-top: 30px;">
                    <?php if ($razorpayEnabled && $razorpayOrderId): ?>
                        <button type="button" id="payRazorpayBtn" class="btn btn-primary btn-block" style="width: 100%; padding: 14px; font-size: 16px; font-weight: 700; border-radius: 8px;">
                            <i class="fas fa-lock"></i> Pay <?php echo $currency . number_format($amount, 2); ?> via Razorpay
                        </button>
                    <?php endif; ?>

                    <form method="POST" action="payment_verify.php" id="offlineForm" style="<?php echo ($razorpayEnabled && $razorpayOrderId) ? 'display:none;' : ''; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($orderId); ?>">
                        <input type="hidden" name="plan_id" value="<?php echo $planId; ?>">
                        <input type="hidden" name="cycle" value="<?php echo htmlspecialchars($cycle); ?>">
                        <input type="hidden" name="payment_method" value="offline">
                        <button type="submit" class="btn btn-primary btn-block" style="width: 100%; padding: 14px; font-size: 16px; font-weight: 700; border-radius: 8px;">
                            <i class="fas fa-check-circle"></i> Confirm & Activate Subscription (<?php echo $currency . number_format($amount, 2); ?>)
                        </button>
                    </form>

                    <div style="margin-top: 15px; text-align: center;">
                        <a href="agent_plans.php" style="color: #64748b; font-size: 13.5px; text-decoration: none;">
                            <i class="fas fa-arrow-left"></i> Change Plan
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Hidden form for Razorpay verification callback -->
    <form method="POST" action="payment_verify.php" id="rzpSuccessForm" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($orderId); ?>">
        <input type="hidden" name="plan_id" value="<?php echo $planId; ?>">
        <input type="hidden" name="cycle" value="<?php echo htmlspecialchars($cycle); ?>">
        <input type="hidden" name="payment_method" value="razorpay">
        <input type="hidden" name="razorpay_payment_id" id="rzpPaymentId">
        <input type="hidden" name="razorpay_order_id" id="rzpOrderId">
        <input type="hidden" name="razorpay_signature" id="rzpSignature">
    </form>

    <script>
    function selectMethod(method) {
        document.querySelectorAll('.method-card').forEach(c => c.classList.remove('active'));
        if (method === 'razorpay') {
            document.getElementById('m_razorpay').checked = true;
            document.getElementById('m_razorpay').closest('.method-card').classList.add('active');
            if (document.getElementById('payRazorpayBtn')) document.getElementById('payRazorpayBtn').style.display = 'block';
            document.getElementById('offlineForm').style.display = 'none';
        } else {
            document.getElementById('m_offline').checked = true;
            document.getElementById('m_offline').closest('.method-card').classList.add('active');
            if (document.getElementById('payRazorpayBtn')) document.getElementById('payRazorpayBtn').style.display = 'none';
            document.getElementById('offlineForm').style.display = 'block';
        }
    }

    <?php if ($razorpayEnabled && $razorpayOrderId): ?>
    const rzpBtn = document.getElementById('payRazorpayBtn');
    if (rzpBtn) {
        rzpBtn.onclick = function(e) {
            e.preventDefault();
            const options = {
                "key": "<?php echo htmlspecialchars($razorpayKeyId); ?>",
                "amount": "<?php echo $orderAmountPaise; ?>",
                "currency": "INR",
                "name": "<?php echo htmlspecialchars($branding['site_name']); ?>",
                "description": "Subscription: <?php echo htmlspecialchars($plan['name']); ?> (<?php echo ucfirst($cycle); ?>)",
                "image": "<?php echo htmlspecialchars($branding['site_logo']); ?>",
                "order_id": "<?php echo htmlspecialchars($razorpayOrderId); ?>",
                "handler": function (response) {
                    document.getElementById('rzpPaymentId').value = response.razorpay_payment_id;
                    document.getElementById('rzpOrderId').value = response.razorpay_order_id;
                    document.getElementById('rzpSignature').value = response.razorpay_signature;
                    document.getElementById('rzpSuccessForm').submit();
                },
                "prefill": {
                    "name": "<?php echo htmlspecialchars($agent['name'] ?? ''); ?>",
                    "email": "<?php echo htmlspecialchars($agent['user_email'] ?? ''); ?>",
                    "contact": "<?php echo htmlspecialchars($agent['mobile'] ?? ''); ?>"
                },
                "theme": {
                    "color": "#001f3f"
                }
            };
            const rzp = new Razorpay(options);
            rzp.on('payment.failed', function (response) {
                alert("Payment failed: " + response.error.description);
            });
            rzp.open();
        };
    }
    <?php endif; ?>
    </script>
</body>
</html>
