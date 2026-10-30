<?php
/**
 * Subscription Invoice & Payment Receipt
 * Insurance Management System
 */
require_once 'config.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

if (!$userId) {
    header("Location: login.php");
    exit();
}

$conn = getDBConnection();
$branding = getBranding();
$currency = $branding['currency_symbol'] ?? '₹';

$invNum = trim($_GET['inv'] ?? '');
$payId = (int)($_GET['id'] ?? 0);

if (!empty($invNum)) {
    $stmt = $conn->prepare("SELECT p.*, pl.name AS plan_name, pl.description AS plan_desc,
                                   a.name AS agent_name, a.agency_name, a.mobile AS agent_mobile, a.email AS agent_email,
                                   a.address AS agent_address, a.city AS agent_city, a.state AS agent_state, a.pincode AS agent_pincode,
                                   s.start_date, s.expiry_date
                            FROM subscription_payments p
                            JOIN subscription_plans pl ON pl.id = p.plan_id
                            JOIN agents a ON a.id = p.agent_id
                            LEFT JOIN subscriptions s ON s.id = p.subscription_id
                            WHERE p.invoice_number = ? LIMIT 1");
    $stmt->bind_param("s", $invNum);
} else {
    $stmt = $conn->prepare("SELECT p.*, pl.name AS plan_name, pl.description AS plan_desc,
                                   a.name AS agent_name, a.agency_name, a.mobile AS agent_mobile, a.email AS agent_email,
                                   a.address AS agent_address, a.city AS agent_city, a.state AS agent_state, a.pincode AS agent_pincode,
                                   s.start_date, s.expiry_date
                            FROM subscription_payments p
                            JOIN subscription_plans pl ON pl.id = p.plan_id
                            JOIN agents a ON a.id = p.agent_id
                            LEFT JOIN subscriptions s ON s.id = p.subscription_id
                            WHERE p.id = ? LIMIT 1");
    $stmt->bind_param("i", $payId);
}

$stmt->execute();
$inv = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$inv) {
    die("Invoice not found.");
}

// Security: ensure agent can only view their own invoice (admin can view any)
if ($role === 'agent') {
    $aid = agentIdForUser($conn, $userId);
    if ((int)$inv['agent_id'] !== $aid) {
        die("Unauthorized access to invoice.");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo htmlspecialchars($inv['invoice_number']); ?> — <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="styles.css?v=5.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { background: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 20px; }
        .invoice-card {
            max-width: 800px;
            margin: 20px auto;
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            padding: 40px;
        }
        .inv-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 25px;
            margin-bottom: 25px;
        }
        .inv-badge {
            background: #dcfce7;
            color: #15803d;
            font-size: 13px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
            text-transform: uppercase;
        }
        .inv-table {
            width: 100%;
            border-collapse: collapse;
            margin: 25px 0;
        }
        .inv-table th {
            background: #f8fafc;
            text-align: left;
            padding: 12px;
            font-size: 13px;
            color: #64748b;
            text-transform: uppercase;
            border-bottom: 2px solid #e2e8f0;
        }
        .inv-table td {
            padding: 14px 12px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14.5px;
            color: #1e293b;
        }
        .no-print-bar {
            max-width: 800px;
            margin: 0 auto 15px;
            display: flex;
            justify-content: space-between;
        }
        @media print {
            body { background: white; padding: 0; }
            .invoice-card { border: none; box-shadow: none; margin: 0; padding: 0; }
            .no-print-bar { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="no-print-bar">
        <a href="dashboard.php" class="btn btn-secondary" style="padding: 8px 16px; border-radius: 6px; text-decoration: none;">
            <i class="fas fa-arrow-left"></i> Dashboard
        </a>
        <button onclick="window.print()" class="btn btn-primary" style="padding: 8px 18px; border-radius: 6px;">
            <i class="fas fa-print"></i> Print Receipt
        </button>
    </div>

    <div class="invoice-card">
        <div class="inv-header">
            <div>
                <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="" width="48" height="48" style="margin-bottom: 10px;" onerror="this.src='uploads/logos/site_logo.png'">
                <h2 style="margin: 0; color: #001f3f;"><?php echo htmlspecialchars($branding['site_name']); ?></h2>
                <p style="margin: 4px 0 0; color: #64748b; font-size: 13.5px;">SaaS Insurance Management Platform</p>
            </div>
            <div style="text-align: right;">
                <span class="inv-badge"><i class="fas fa-check-circle"></i> Paid in Full</span>
                <h3 style="margin: 10px 0 4px; color: #0f172a; font-size: 20px;">TAX INVOICE</h3>
                <div style="font-size: 14px; color: #475569;"><strong>Invoice No:</strong> <?php echo htmlspecialchars($inv['invoice_number']); ?></div>
                <div style="font-size: 13.5px; color: #64748b;"><strong>Date:</strong> <?php echo date('d M Y, h:i A', strtotime($inv['paid_at'] ?: $inv['created_at'])); ?></div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 25px;">
            <div>
                <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #94a3b8;">Billed To:</span>
                <div style="font-size: 16px; font-weight: 700; color: #0f172a; margin-top: 4px;"><?php echo htmlspecialchars($inv['agency_name'] ?: $inv['agent_name']); ?></div>
                <div style="font-size: 14px; color: #475569; margin-top: 2px;">Attn: <?php echo htmlspecialchars($inv['agent_name']); ?></div>
                <?php if ($inv['agent_address']): ?>
                    <div style="font-size: 13.5px; color: #64748b; margin-top: 2px;"><?php echo nl2br(htmlspecialchars($inv['agent_address'])); ?></div>
                    <div style="font-size: 13.5px; color: #64748b;"><?php echo htmlspecialchars($inv['agent_city'] . ($inv['agent_state'] ? ', ' . $inv['agent_state'] : '') . ($inv['agent_pincode'] ? ' - ' . $inv['agent_pincode'] : '')); ?></div>
                <?php endif; ?>
                <div style="font-size: 13.5px; color: #64748b; margin-top: 2px;">Phone: <?php echo htmlspecialchars($inv['agent_mobile']); ?> | Email: <?php echo htmlspecialchars($inv['agent_email']); ?></div>
            </div>

            <div style="text-align: right;">
                <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #94a3b8;">Payment Reference:</span>
                <div style="font-size: 13.5px; color: #475569; margin-top: 4px;"><strong>Payment ID:</strong> <?php echo htmlspecialchars($inv['payment_id'] ?: 'N/A'); ?></div>
                <div style="font-size: 13.5px; color: #475569; margin-top: 2px;"><strong>Order ID:</strong> <?php echo htmlspecialchars($inv['order_id'] ?: 'N/A'); ?></div>
                <div style="font-size: 13.5px; color: #475569; margin-top: 2px;"><strong>Method:</strong> <?php echo ucfirst(htmlspecialchars($inv['payment_method'])); ?></div>
                <?php if ($inv['start_date'] && $inv['expiry_date']): ?>
                    <div style="font-size: 13.5px; color: #166534; margin-top: 4px;">
                        <strong>Coverage Period:</strong><br>
                        <?php echo date('d M Y', strtotime($inv['start_date'])); ?> to <?php echo date('d M Y', strtotime($inv['expiry_date'])); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <table class="inv-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Billing Cycle</th>
                    <th style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong style="color: #001f3f;"><?php echo htmlspecialchars($inv['plan_name']); ?> Subscription</strong>
                        <div style="font-size: 13px; color: #64748b; margin-top: 2px;"><?php echo htmlspecialchars($inv['plan_desc']); ?></div>
                    </td>
                    <td style="text-transform: capitalize; font-weight: 600;"><?php echo htmlspecialchars($inv['billing_cycle']); ?></td>
                    <td style="text-align: right; font-weight: 700; font-size: 16px;"><?php echo $currency . number_format($inv['amount'], 2); ?></td>
                </tr>
                <tr>
                    <td colspan="2" style="text-align: right; font-weight: 700; border-top: 2px solid #e2e8f0; font-size: 16px;">Total Paid:</td>
                    <td style="text-align: right; font-weight: 800; font-size: 20px; color: #001f3f; border-top: 2px solid #e2e8f0;">
                        <?php echo $currency . number_format($inv['amount'], 2); ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top: 35px; border-top: 1px dashed #cbd5e1; padding-top: 20px; font-size: 12.5px; color: #94a3b8; text-align: center;">
            This is a computer-generated tax invoice and requires no physical signature.<br>
            For support or inquiries, contact <?php echo htmlspecialchars(getSetting('smtp_from_email', 'contact@rudeesdigital.com')); ?>
        </div>
    </div>
</body>
</html>
