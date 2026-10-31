<?php
/**
 * Subscription Plans Page
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

// Get agent record
$aid = null;
$currentSub = null;
try {
    $aid = agentIdForUser($conn, $userId);
    $currentSub = $aid ? getAgentSubscription($conn, $aid) : null;
} catch (Throwable $e) {}

// Fetch all active subscription plans ordered by display_order
$plans = [];
try {
    $pRes = $conn->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY display_order ASC, monthly_price ASC");
    if ($pRes) {
        while ($row = $pRes->fetch_assoc()) {
            $plans[] = $row;
        }
    }
} catch (Throwable $e) {}

if (empty($plans)) {
    $plans[] = [
        'id' => 1,
        'name' => 'Agent Standard Access',
        'slug' => 'standard-agent',
        'description' => 'Complete customer policy and expiry reminder portfolio management.',
        'monthly_price' => 0,
        'quarterly_price' => 0,
        'yearly_price' => 0,
        'is_trial' => 1,
        'trial_days' => 365,
        'max_customers' => 999999,
        'max_policies' => 999999,
        'monthly_ai_scans' => 999999,
        'whatsapp_limit' => 999999,
        'reports_access' => 1,
        'advanced_features' => 1,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Plans — <?php echo htmlspecialchars($branding['site_name']); ?></title>
    <link rel="stylesheet" href="styles.css?v=5.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .plans-wrapper {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .plans-header {
            text-align: center;
            margin-bottom: 35px;
        }
        .plans-header h1 {
            font-size: 32px;
            color: #0f172a;
            margin-bottom: 10px;
        }
        .plans-header p {
            font-size: 16px;
            color: #64748b;
            max-width: 600px;
            margin: 0 auto;
        }
        .cycle-switcher {
            display: inline-flex;
            background: #e2e8f0;
            padding: 4px;
            border-radius: 30px;
            margin-top: 25px;
        }
        .cycle-btn {
            border: none;
            background: transparent;
            padding: 9px 22px;
            border-radius: 25px;
            font-weight: 600;
            font-size: 14px;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s;
        }
        .cycle-btn.active {
            background: #001f3f;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(0, 31, 63, 0.25);
        }
        .save-tag {
            background: #10b981;
            color: white;
            font-size: 11px;
            padding: 2px 7px;
            border-radius: 12px;
            margin-left: 6px;
            font-weight: 700;
        }
        .plans-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }
        .plan-card {
            background: #ffffff;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 30px 25px;
            display: flex;
            flex-direction: column;
            position: relative;
            transition: all 0.25s ease;
        }
        .plan-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.08);
            border-color: #0074D9;
        }
        .plan-card.popular {
            border-color: #0074D9;
            box-shadow: 0 10px 25px rgba(0, 116, 217, 0.15);
        }
        .popular-badge {
            position: absolute;
            top: -13px;
            left: 50%;
            transform: translateX(-50%);
            background: #0074D9;
            color: white;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 4px 16px;
            border-radius: 20px;
        }
        .plan-title {
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .plan-desc {
            font-size: 13.5px;
            color: #64748b;
            min-height: 40px;
            margin-bottom: 20px;
        }
        .plan-price-wrap {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #f1f5f9;
        }
        .plan-price {
            font-size: 38px;
            font-weight: 800;
            color: #001f3f;
        }
        .plan-period {
            font-size: 14px;
            color: #94a3b8;
            font-weight: 500;
        }
        .feature-list {
            list-style: none;
            padding: 0;
            margin: 0 0 30px 0;
            flex-grow: 1;
        }
        .feature-list li {
            font-size: 14px;
            color: #334155;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .feature-list li i.fa-check-circle {
            color: #10b981;
            font-size: 16px;
        }
        .feature-list li i.fa-times-circle {
            color: #cbd5e1;
            font-size: 16px;
        }
        .current-sub-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }
    </style>
</head>
<body style="background: #f8fafc; font-family: inherit;">
    <div class="plans-wrapper">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <a href="dashboard.php" style="display: flex; align-items: center; gap: 8px; text-decoration: none; color: #001f3f; font-weight: 600;">
                <img src="<?php echo htmlspecialchars($branding['site_logo']); ?>" alt="" width="32" height="32" onerror="this.src='uploads/logos/site_logo.png'">
                <span><?php echo htmlspecialchars($branding['site_name']); ?></span>
            </a>
            <div>
                <?php if ($currentSub && $currentSub['is_valid']): ?>
                    <a href="dashboard.php" class="btn btn-secondary" style="padding: 8px 16px; border-radius: 6px; text-decoration: none;">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                <?php else: ?>
                    <a href="logout.php" class="btn btn-secondary" style="padding: 8px 16px; border-radius: 6px; text-decoration: none;">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($currentSub && $currentSub['is_valid']): ?>
            <div class="current-sub-box">
                <div>
                    <h3 style="color: #166534; margin: 0 0 4px 0; font-size: 16px;">
                        <i class="fas fa-badge-check"></i> Current Active Plan: <strong><?php echo htmlspecialchars($currentSub['plan_name']); ?></strong> (<?php echo ucfirst($currentSub['billing_cycle']); ?>)
                    </h3>
                    <p style="margin: 0; color: #15803d; font-size: 13.5px;">
                        Valid until <strong><?php echo date('d M Y', strtotime($currentSub['expiry_date'])); ?></strong> 
                        (<?php echo (int)$currentSub['days_left']; ?> days remaining).
                    </p>
                </div>
                <a href="#plans" class="btn btn-primary" style="padding: 8px 16px; border-radius: 6px; text-decoration: none;">
                    <i class="fas fa-arrow-up"></i> Upgrade / Renew Plan
                </a>
            </div>
        <?php endif; ?>

        <div class="plans-header" id="plans">
            <h1>Choose Your Subscription Plan</h1>
            <p>Empower your insurance agency with automated customer reminders, AI-driven policy scanning, and unlimited renewal tracking.</p>

            <div class="cycle-switcher">
                <button type="button" class="cycle-btn active" onclick="switchCycle('monthly')">Monthly</button>
                <button type="button" class="cycle-btn" onclick="switchCycle('quarterly')">Quarterly <span class="save-tag">SAVE 12%</span></button>
                <button type="button" class="cycle-btn" onclick="switchCycle('yearly')">Yearly <span class="save-tag">SAVE 25%</span></button>
            </div>
        </div>

        <div class="plans-grid">
            <?php foreach ($plans as $p): 
                $isPopular = ($p['slug'] === 'professional-agent');
                $isTrial = (bool)$p['is_trial'];
                $isCurrent = ($currentSub && (int)$currentSub['plan_id'] === (int)$p['id'] && $currentSub['is_valid']);
            ?>
            <div class="plan-card <?php echo $isPopular ? 'popular' : ''; ?>">
                <?php if ($isPopular): ?>
                    <span class="popular-badge"><i class="fas fa-star"></i> Most Popular</span>
                <?php endif; ?>

                <div class="plan-title"><?php echo htmlspecialchars($p['name']); ?></div>
                <div class="plan-desc"><?php echo htmlspecialchars($p['description']); ?></div>

                <div class="plan-price-wrap">
                    <?php if ($isTrial): ?>
                        <span class="plan-price">FREE</span>
                        <span class="plan-period">/ <?php echo (int)$p['trial_days']; ?> days</span>
                    <?php else: ?>
                        <div class="price-val" data-monthly="<?php echo (float)$p['monthly_price']; ?>" data-quarterly="<?php echo (float)$p['quarterly_price']; ?>" data-yearly="<?php echo (float)$p['yearly_price']; ?>">
                            <span class="plan-price"><?php echo $currency; ?><span class="amt"><?php echo number_format($p['monthly_price']); ?></span></span>
                            <span class="plan-period">/ month</span>
                        </div>
                    <?php endif; ?>
                </div>

                <ul class="feature-list">
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span><strong><?php echo (int)$p['max_customers'] > 99999 ? 'Unlimited' : number_format($p['max_customers']); ?></strong> Customers</span>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span><strong><?php echo (int)$p['max_policies'] > 99999 ? 'Unlimited' : number_format($p['max_policies']); ?></strong> Policies</span>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span><strong><?php echo number_format($p['monthly_ai_scans']); ?></strong> AI Policy Scans / month</span>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span><strong><?php echo number_format($p['whatsapp_limit']); ?></strong> WhatsApp Notifications</span>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span>5-Stage Expiry Reminders (30, 14, 7, 2, 0 days)</span>
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <span>Policy Renewal Chain & History</span>
                    </li>
                    <li>
                        <?php if ($p['reports_access']): ?>
                            <i class="fas fa-check-circle"></i>
                            <span>Financial & Commission Reports</span>
                        <?php else: ?>
                            <i class="fas fa-times-circle"></i>
                            <span style="color:#94a3b8;">Reports Access</span>
                        <?php endif; ?>
                    </li>
                    <li>
                        <?php if ($p['advanced_features']): ?>
                            <i class="fas fa-check-circle"></i>
                            <span>Priority Support Desk & Followup CRM</span>
                        <?php else: ?>
                            <i class="fas fa-times-circle"></i>
                            <span style="color:#94a3b8;">Advanced CRM Features</span>
                        <?php endif; ?>
                    </li>
                </ul>

                <div style="margin-top: auto;">
                    <?php if ($isCurrent): ?>
                        <button type="button" class="btn btn-secondary btn-block" style="width: 100%; padding: 12px; font-weight: 600;" disabled>
                            <i class="fas fa-check"></i> Current Plan
                        </button>
                    <?php else: ?>
                        <a href="payment_checkout.php?plan_id=<?php echo $p['id']; ?>&cycle=monthly" class="btn <?php echo $isPopular ? 'btn-primary' : 'btn-secondary'; ?> btn-block checkout-link" data-plan-id="<?php echo $p['id']; ?>" style="width: 100%; text-align: center; padding: 12px; font-weight: 600; text-decoration: none; display: block; border-radius: 8px;">
                            <?php echo $isTrial ? 'Start 14-Day Free Trial' : ($isCurrent ? 'Renew Plan' : 'Select Plan & Pay'); ?> <i class="fas fa-arrow-right" style="font-size: 12px; margin-left: 4px;"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top: 60px; background: white; border-radius: 16px; padding: 30px; border: 1px solid #e2e8f0; text-align: center;">
            <h3 style="margin-top: 0; color: #001f3f;"><i class="fas fa-shield-halved"></i> 100% Safe & Secure Payments</h3>
            <p style="color: #64748b; font-size: 14px; max-width: 600px; margin: 0 auto 15px;">
                Payments are processed via encrypted gateways including Razorpay, UPI, Net Banking, and Credit/Debit Cards.
                GST invoice and tax receipt generated instantly upon activation.
            </p>
            <div style="display: flex; justify-content: center; gap: 20px; color: #94a3b8; font-size: 24px;">
                <i class="fab fa-cc-visa"></i>
                <i class="fab fa-cc-mastercard"></i>
                <i class="fas fa-building-columns"></i>
                <i class="fab fa-google-pay"></i>
            </div>
        </div>

        <div class="login-footer" style="margin-top: 40px; text-align: center;">
            <p><?php echo htmlspecialchars($branding['site_footer'] ?: '© ' . date('Y') . ' ' . $branding['site_name']); ?></p>
        </div>
    </div>

    <script>
    let currentCycle = 'monthly';
    const currency = '<?php echo $currency; ?>';

    function switchCycle(cycle) {
        currentCycle = cycle;
        document.querySelectorAll('.cycle-btn').forEach(b => b.classList.remove('active'));
        event.target.closest('.cycle-btn').classList.add('active');

        document.querySelectorAll('.price-val').forEach(pv => {
            const amtSpan = pv.querySelector('.amt');
            const periodSpan = pv.querySelector('.plan-period');
            const amt = parseFloat(pv.dataset[cycle]);

            if (cycle === 'monthly') {
                amtSpan.textContent = amt.toLocaleString();
                periodSpan.textContent = '/ month';
            } else if (cycle === 'quarterly') {
                amtSpan.textContent = amt.toLocaleString();
                periodSpan.textContent = '/ quarter';
            } else if (cycle === 'yearly') {
                amtSpan.textContent = amt.toLocaleString();
                periodSpan.textContent = '/ year';
            }
        });

        // Update checkout link query parameters
        document.querySelectorAll('.checkout-link').forEach(link => {
            const planId = link.dataset.planId;
            link.href = `payment_checkout.php?plan_id=${planId}&cycle=${cycle}`;
        });
    }
    </script>
</body>
</html>
