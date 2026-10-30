<?php
/**
 * Developed by Rudees Digital
 * Website: https://rudeesdigital.com
 * Email: contact@rudeesdigital.com
 * Website: https://rudeesdigital.com
 * Email: contact@rudeesdigital.com
 * Website: https://rudeesdigital.com
  Email: contact@rudeesdigital.com
  WhatsApp: +91 8160140848
 */
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!checkSessionTimeout()) {
    header("Location: login.php");
    exit();
}

$username = $_SESSION['username'];
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'agent';
$user_id = $_SESSION['user_id'];
$current_page = 'dashboard';

// skip policies already renewed by a newer row - not at risk any more
const DX_NOT_RENEWED = " AND NOT EXISTS (SELECT 1 FROM policy_entries rn WHERE rn.previous_policy_id = policy_entries.id)";

// ajax: db errors come back as json instead of an html fatal
if (isset($_GET['action']))
    set_exception_handler(function ($e) use ($role) {
        error_log('dashboard ajax: ' . $e->getMessage());
        if (!headers_sent())
            header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $role === 'admin' ? 'Database error: ' . $e->getMessage() . ' — run setup.php?action=update' : 'Could not load stats. Please contact the administrator.']);
        exit();
    });

// customer stats ajax
if (isset($_GET['action']) && $_GET['action'] === 'getCustomerStats') {
    header('Content-Type: application/json');

    if ($role !== 'customer') {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }

    $conn = getDBConnection();
    $cid = customerIdForUser($conn, $user_id);
    if (!$cid && !empty($_SESSION['customer_id']))
        $cid = (int) $_SESSION['customer_id'];
    $famIds = $cid ? getFamilyCustomerIds($conn, $cid) : [];
    if (empty($famIds) && $cid)
        $famIds = [$cid];
    if (empty($famIds))
        $famIds = [0];
    $inCust = implode(',', array_map('intval', $famIds));

    // primary customer profile
    $st = $conn->prepare("SELECT c.*, a.name as agent_name, a.mobile as agent_mobile, a.agent_code 
        FROM customers c LEFT JOIN agents a ON a.id = c.agent_id WHERE c.id = ?");
    $st->bind_param("i", $cid);
    $st->execute();
    $customerProfile = $st->get_result()->fetch_assoc() ?: ['name' => $username, 'mobile' => ''];
    $st->close();

    // family members
    $famRes = $conn->query("SELECT id, name, mobile, family_relation, (SELECT COUNT(*) FROM policy_entries pe WHERE pe.customer_id = customers.id AND pe.status = 'active') as active_policies FROM customers WHERE id IN ($inCust) ORDER BY (id = $cid) DESC, name ASC");
    $familyMembers = [];
    if ($famRes)
        while ($r = $famRes->fetch_assoc())
            $familyMembers[] = $r;

    // total & active policies
    $polStats = $conn->query("SELECT 
        COUNT(*) as total_policies,
        COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) as active_policies,
        COALESCE(SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END), 0) as expired_policies,
        COALESCE(SUM(CASE WHEN status = 'active' AND policy_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) as expiring_soon,
        COALESCE(SUM(CASE WHEN insurance_category = 'motor' AND status = 'active' THEN 1 ELSE 0 END), 0) as motor_active,
        COALESCE(SUM(CASE WHEN insurance_category = 'health' AND status = 'active' THEN 1 ELSE 0 END), 0) as health_active,
        COALESCE(SUM(CASE WHEN insurance_category = 'health' AND status = 'active' THEN sum_insured ELSE 0 END), 0) as health_sum_insured,
        COALESCE(SUM(CASE WHEN insurance_category = 'life' AND status = 'active' THEN 1 ELSE 0 END), 0) as life_active,
        COALESCE(SUM(CASE WHEN insurance_category = 'life' AND status = 'active' THEN sum_assured ELSE 0 END), 0) as life_sum_assured,
        COALESCE(SUM(CASE WHEN status = 'active' THEN total_premium_amount ELSE 0 END), 0) as total_active_premium
    FROM policy_entries WHERE customer_id IN ($inCust)")->fetch_assoc();

    // active claims
    $clmStats = $conn->query("SELECT 
        COUNT(*) as total_claims,
        COALESCE(SUM(CASE WHEN c.status IN ('intimated', 'survey', 'approved') THEN 1 ELSE 0 END), 0) as active_claims,
        COALESCE(SUM(CASE WHEN c.status = 'settled' THEN 1 ELSE 0 END), 0) as settled_claims,
        COALESCE(SUM(CASE WHEN c.status = 'settled' THEN c.amount_settled ELSE 0 END), 0) as total_settled_amount
    FROM claims c JOIN policy_entries pe ON pe.id = c.policy_entry_id WHERE pe.customer_id IN ($inCust)")->fetch_assoc();

    // recent policies (top 8)
    $recPolRes = $conn->query("SELECT id, policy_number, insurance_category, vehicle_number, plan_name, life_plan_type, sum_insured, sum_assured, customer_name, company_name, policy_start_date, policy_end_date, status, total_premium_amount, (customer_id = $cid) as is_personal 
        FROM policy_entries WHERE customer_id IN ($inCust) ORDER BY (status = 'active') DESC, policy_end_date ASC, id DESC LIMIT 8");
    $recentPolicies = [];
    if ($recPolRes)
        while ($r = $recPolRes->fetch_assoc())
            $recentPolicies[] = $r;

    // recent active claims
    $recClmRes = $conn->query("SELECT c.id, c.claim_number, c.claim_category, c.claim_type, c.life_claim_type, c.amount_claimed, c.amount_approved, c.amount_settled, c.status, c.intimation_date, pe.policy_number, pe.customer_name 
        FROM claims c JOIN policy_entries pe ON pe.id = c.policy_entry_id WHERE pe.customer_id IN ($inCust) ORDER BY c.intimation_date DESC, c.id DESC LIMIT 5");
    $recentClaims = [];
    if ($recClmRes)
        while ($r = $recClmRes->fetch_assoc())
            $recentClaims[] = $r;

    // agent contact
    $agentInfo = null;
    if (!empty($customerProfile['agent_name'])) {
        $agentInfo = [
            'name' => $customerProfile['agent_name'],
            'mobile' => $customerProfile['agent_mobile'],
            'code' => $customerProfile['agent_code']
        ];
    } else {
        $agFind = $conn->query("SELECT pe.agent_name, a.mobile, a.agent_code FROM policy_entries pe LEFT JOIN agents a ON a.id = pe.agent_id WHERE pe.customer_id IN ($inCust) AND pe.agent_id > 0 LIMIT 1");
        if ($agFind && $row = $agFind->fetch_assoc()) {
            $agentInfo = [
                'name' => $row['agent_name'],
                'mobile' => $row['mobile'],
                'code' => $row['agent_code']
            ];
        }
    }

    $conn->close();

    echo json_encode([
        'success' => true,
        'data' => [
            'profile' => $customerProfile,
            'familyMembers' => $familyMembers,
            'stats' => $polStats,
            'claims' => $clmStats,
            'recentPolicies' => $recentPolicies,
            'recentClaims' => $recentClaims,
            'agent' => $agentInfo
        ]
    ]);
    exit();
}

// agent stats ajax
if (isset($_GET['action']) && $_GET['action'] === 'getAgentStats') {
    header('Content-Type: application/json');

    if ($role !== 'agent') {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }

    $conn = getDBConnection();

    // date range for period-sensitive metrics (resolveDateRange defined below admin block)
    $rangeKey = $_GET['range'] ?? 'all';
    list($rFrom, $rTo) = resolveDateRange($rangeKey, $_GET['from'] ?? '', $_GET['to'] ?? '');

    // get agent id
    $stmt = $conn->prepare("SELECT id FROM agents WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $agentId = $res->num_rows ? $res->fetch_assoc()['id'] : 0;
    $stmt->close();

    // total policies
    $stmt = $conn->prepare("SELECT COUNT(*) as t FROM policy_entries WHERE agent_id = ?");
    $stmt->bind_param("i", $agentId);
    $stmt->execute();
    $totalPolicies = $stmt->get_result()->fetch_assoc()['t'];
    $stmt->close();

    // active
    $stmt = $conn->prepare("SELECT COUNT(*) as t FROM policy_entries WHERE agent_id = ? AND status = 'active'");
    $stmt->bind_param("i", $agentId);
    $stmt->execute();
    $activePolicies = $stmt->get_result()->fetch_assoc()['t'];
    $stmt->close();

    // premium + commission for selected range (exclude cancelled)
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_premium_amount), 0) as prem, COALESCE(SUM(agent_commission_amount), 0) as comm FROM policy_entries WHERE agent_id = ? AND status != 'cancelled' AND entry_date BETWEEN ? AND ?");
    $stmt->bind_param("iss", $agentId, $rFrom, $rTo);
    $stmt->execute();
    $agSums = $stmt->get_result()->fetch_assoc();
    $totalPremium = $agSums['prem'];
    $totalCommission = $agSums['comm'];
    $stmt->close();

    // today's premium booked (mine)
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_premium_amount),0) as prem, COUNT(*) as cnt FROM policy_entries WHERE agent_id = ? AND status != 'cancelled' AND entry_date = CURDATE()");
    $stmt->bind_param("i", $agentId);
    $stmt->execute();
    $agToday = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // expiring 30d + expired alert (mine, with premium at risk)
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total_premium_amount),0) as prem FROM policy_entries WHERE agent_id = ? AND status = 'active' AND policy_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)" . DX_NOT_RENEWED);
    $stmt->bind_param("i", $agentId);
    $stmt->execute();
    $agExpiring = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total_premium_amount),0) as prem FROM policy_entries WHERE agent_id = ? AND status = 'expired'" . DX_NOT_RENEWED);
    $stmt->bind_param("i", $agentId);
    $stmt->execute();
    $agExpired = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // wallet + outstanding
    $stmt = $conn->prepare("SELECT COALESCE(wallet_balance, 0) as wallet, COALESCE(outstanding_balance, 0) as outstanding FROM agents WHERE id = ?");
    $stmt->bind_param("i", $agentId);
    $stmt->execute();
    $balances = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // my customers
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT customer_id) as t FROM policy_entries WHERE agent_id = ?");
    $stmt->bind_param("i", $agentId);
    $stmt->execute();
    $totalCustomers = $stmt->get_result()->fetch_assoc()['t'];
    $stmt->close();

    $expiringSoon = (int) $agExpiring['cnt'];

    // unread notifs
    $stmt = $conn->prepare("SELECT COUNT(*) as t FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $unreadNotifs = $stmt->get_result()->fetch_assoc()['t'];
    $stmt->close();

    // monthly commission within selected range (exclude cancelled)
    $stmt = $conn->prepare("SELECT DATE_FORMAT(entry_date, '%Y-%m') as m, SUM(agent_commission_amount) as commission, COUNT(*) as policies FROM policy_entries WHERE agent_id = ? AND status != 'cancelled' AND entry_date BETWEEN ? AND ? GROUP BY m ORDER BY m");
    $stmt->bind_param("iss", $agentId, $rFrom, $rTo);
    $stmt->execute();
    $monthlyCommission = [];
    $mcRes = $stmt->get_result();
    while ($row = $mcRes->fetch_assoc()) {
        $monthlyCommission[] = [
            'month' => date('M Y', strtotime($row['m'] . '-01')),
            'commission' => (float) $row['commission'],
            'policies' => (int) $row['policies']
        ];
    }
    $stmt->close();

    // policy status breakdown
    $stmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM policy_entries WHERE agent_id = ? GROUP BY status");
    $stmt->bind_param("i", $agentId);
    $stmt->execute();
    $policyByStatus = [];
    $psRes = $stmt->get_result();
    while ($row = $psRes->fetch_assoc()) {
        $policyByStatus[$row['status']] = (int) $row['cnt'];
    }
    $stmt->close();

    // vertical distribution for agent
    $stmt = $conn->prepare("SELECT COALESCE(insurance_category, 'motor') as cat, COUNT(*) as cnt, COALESCE(SUM(total_premium_amount), 0) as prem FROM policy_entries WHERE agent_id = ? AND status != 'cancelled' GROUP BY cat");
    $stmt->bind_param("i", $agentId);
    $stmt->execute();
    $catRes = $stmt->get_result();
    $categoryDist = [];
    while ($row = $catRes->fetch_assoc()) {
        $categoryDist[] = ['category' => $row['cat'], 'count' => (int) $row['cnt'], 'premium' => (float) $row['prem']];
    }
    $stmt->close();

    // recent 5
    $stmt = $conn->prepare("SELECT id, policy_number, customer_name, company_name, total_premium_amount, status, entry_date, insurance_category, plan_name, vehicle_number FROM policy_entries WHERE agent_id = ? ORDER BY id DESC LIMIT 5");
    $stmt->bind_param("i", $agentId);
    $stmt->execute();
    $recentPolicies = [];
    $rpRes = $stmt->get_result();
    while ($row = $rpRes->fetch_assoc()) {
        $recentPolicies[] = $row;
    }
    $stmt->close();

    // same numbers for the period before (tile deltas)
    $prevR = prevDateRange($rangeKey, $rFrom, $rTo);
    $prev = null;
    if ($prevR) {
        $pv = dxRows($conn, "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_premium_amount), 0) AS prem, COALESCE(SUM(agent_commission_amount), 0) AS comm FROM policy_entries WHERE agent_id = ? AND status != 'cancelled' AND entry_date BETWEEN ? AND ?", 'iss', $agentId, $prevR[0], $prevR[1])[0];
        $prev = ['count' => (int) $pv['cnt'], 'premium' => (float) $pv['prem'], 'commission' => (float) $pv['comm']];
    }
    $rangeCount = (int) dxRows($conn, "SELECT COUNT(*) AS c FROM policy_entries WHERE agent_id = ? AND status != 'cancelled' AND entry_date BETWEEN ? AND ?", 'iss', $agentId, $rFrom, $rTo)[0]['c'];
    $focus = dashboardFocus($conn, $agentId);
    $feed = dxRows($conn, "SELECT type, title, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 7", 'i', $user_id);
    $sub = getAgentSubscription($conn, $agentId);

    $conn->close();

    echo json_encode([
        'success' => true,
        'data' => [
            'subscription' => $sub,
            'rangePolicyCount' => $rangeCount,
            'prev' => $prev,
            'focus' => $focus,
            'feed' => $feed,
            'totalPolicies' => (int) $totalPolicies,
            'activePolicies' => (int) $activePolicies,
            'totalPremium' => (float) $totalPremium,
            'totalCommission' => (float) $totalCommission,
            'walletBalance' => (float) ($balances['wallet'] ?? 0),
            'outstandingBalance' => (float) ($balances['outstanding'] ?? 0),
            'totalCustomers' => (int) $totalCustomers,
            'expiringSoon' => (int) $expiringSoon,
            'unreadNotifs' => (int) $unreadNotifs,
            'monthlyCommission' => $monthlyCommission,
            'policyByStatus' => $policyByStatus,
            'categoryDistribution' => $categoryDist,
            'recentPolicies' => $recentPolicies,
            'todayPremium' => (float) $agToday['prem'],
            'todayPolicyCount' => (int) $agToday['cnt'],
            'expiringSoonCount' => (int) $agExpiring['cnt'],
            'expiringSoonPremium' => (float) $agExpiring['prem'],
            'expiredCount' => (int) $agExpired['cnt'],
            'expiredPremium' => (float) $agExpired['prem']
        ]
    ]);
    exit();
}

// resolve date-range param -> [from, to] (yyyy-mm-dd). shared by admin + agent
function resolveDateRange($range, $from, $to)
{
    $today = date('Y-m-d');
    switch ($range) {
        case 'today':
            return [$today, $today];
        case 'week':
            return [date('Y-m-d', strtotime('monday this week')), $today];
        case 'month':
            return [date('Y-m-01'), $today];
        case 'quarter':
            $q = floor((date('n') - 1) / 3);
            $qStart = date('Y-m-d', mktime(0, 0, 0, $q * 3 + 1, 1, (int) date('Y')));
            return [$qStart, $today];
        case 'custom':
            $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $from) ? $from : date('Y-m-01');
            $t = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $to) ? $to : $today;
            return [$f, $t];
        default: // 'all'
            return ['1970-01-01', $today];
    }
}

// shift a Y-m-d by n months, day clamped to that month's end (Mar 31 -1 = Feb 28)
function dxShiftMonths($d, $n)
{
    $t = strtotime($d);
    $first = mktime(0, 0, 0, (int) date('n', $t) + $n, 1, (int) date('Y', $t));
    return date('Y-m-', $first) . str_pad(min((int) date('j', $t), (int) date('t', $first)), 2, '0', STR_PAD_LEFT);
}

// the period before the selected one, same shape — yesterday, last week / month / quarter to the same day
function prevDateRange($range, $from, $to)
{
    switch ($range) {
        case 'today':
            return [date('Y-m-d', strtotime("$from -1 day")), date('Y-m-d', strtotime("$to -1 day"))];
        case 'week':
            return [date('Y-m-d', strtotime("$from -7 days")), date('Y-m-d', strtotime("$to -7 days"))];
        case 'month':
            return [dxShiftMonths($from, -1), dxShiftMonths($to, -1)];
        case 'quarter':
            return [dxShiftMonths($from, -3), dxShiftMonths($to, -3)];
        case 'custom':
            $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
            return [date('Y-m-d', strtotime("$from -$days days")), date('Y-m-d', strtotime("$from -1 day"))];
        default:
            return null; // all time has nothing before it
    }
}

// prepared select -> rows
function dxRows($conn, $sql, $types = '', ...$p)
{
    $st = $conn->prepare($sql);
    if ($types !== '')
        $st->bind_param($types, ...$p);
    $st->execute();
    $res = $st->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc())
        $rows[] = $r;
    $st->close();
    return $rows;
}

// hero + attention list + 8-month premium bars. $agentId null = every policy
function dashboardFocus($conn, $agentId = null)
{
    [$w, $t, $a] = $agentId === null ? ['', '', []] : [' AND agent_id = ?', 'i', [(int) $agentId]];
    $nr = DX_NOT_RENEWED;
    $soon = "status = 'active' AND policy_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)$nr";

    $week = dxRows($conn, "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_premium_amount), 0) AS prem FROM policy_entries WHERE status = 'active' AND policy_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)$nr$w", $t, ...$a)[0];
    $big = dxRows($conn, "SELECT id, customer_name, policy_number, total_premium_amount FROM policy_entries WHERE $soon$w ORDER BY total_premium_amount DESC LIMIT 1", $t, ...$a)[0] ?? null;
    $first = dxRows($conn, "SELECT MIN(policy_end_date) AS d FROM policy_entries WHERE $soon$w", $t, ...$a)[0]['d'];

    // premium booked per month, last 8 months, gaps = 0
    $byM = [];
    foreach (dxRows($conn, "SELECT DATE_FORMAT(entry_date, '%Y-%m') AS m, SUM(total_premium_amount) AS p FROM policy_entries WHERE status != 'cancelled' AND entry_date >= ?$w GROUP BY m", 's' . $t, date('Y-m-01', strtotime('first day of -7 months')), ...$a) as $r)
        $byM[$r['m']] = (float) $r['p'];
    $bars = [];
    for ($i = 7; $i >= 0; $i--) {
        $k = date('Y-m', strtotime("first day of -$i months"));
        $bars[] = ['m' => date('M', strtotime("$k-01")), 'v' => $byM[$k] ?? 0];
    }

    // needs action: not cancelled, expiry within 30 days either side, nearest to today first
    $win = "status != 'cancelled' AND policy_end_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)$nr";
    $attn = dxRows($conn, "SELECT id, policy_number, customer_name, vehicle_number, vehicle_type, company_name, total_premium_amount, policy_end_date, insurance_category, plan_name, DATEDIFF(policy_end_date, CURDATE()) AS days FROM policy_entries WHERE $win$w ORDER BY ABS(DATEDIFF(policy_end_date, CURDATE())), total_premium_amount DESC LIMIT 6", $t, ...$a);
    // quiet window -> show the next renewals coming up instead of an empty panel
    $later = !$attn;
    if ($later)
        $attn = dxRows($conn, "SELECT id, policy_number, customer_name, vehicle_number, vehicle_type, company_name, total_premium_amount, policy_end_date, insurance_category, plan_name, DATEDIFF(policy_end_date, CURDATE()) AS days FROM policy_entries WHERE status = 'active' AND policy_end_date > CURDATE()$nr$w ORDER BY policy_end_date, total_premium_amount DESC LIMIT 6", $t, ...$a);
    foreach ($attn as &$r) {
        $r['id'] = (int) $r['id'];
        $r['days'] = (int) $r['days'];
        $r['total_premium_amount'] = (float) $r['total_premium_amount'];
    }
    unset($r);
    // customer dues: paid to the insurer, not yet received from the customer
    $dues = dxRows($conn, "SELECT COUNT(*) AS n, COALESCE(SUM(payment_out - payment_in), 0) AS amt FROM policy_entries WHERE status != 'cancelled' AND payment_out > payment_in$w", $t, ...$a)[0];

    return [
        'weekCount' => (int) $week['cnt'],
        'weekPremium' => (float) $week['prem'],
        'big' => $big ? ['id' => (int) $big['id'], 'customer' => $big['customer_name'], 'policy' => $big['policy_number'], 'premium' => (float) $big['total_premium_amount']] : null,
        'first' => $first,
        'bars' => $bars,
        'attn' => $attn,
        'later' => $later,
        'attnCount' => (int) dxRows($conn, "SELECT COUNT(*) AS c FROM policy_entries WHERE $win$w", $t, ...$a)[0]['c'],
        'duesCount' => (int) $dues['n'],
        'duesAmt' => (float) $dues['amt'],
    ];
}

// admin/manager stats ajax
if (isset($_GET['action']) && $_GET['action'] === 'getDashboardStats') {
    header('Content-Type: application/json');

    if ($role !== 'admin' && $role !== 'manager') {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }

    $conn = getDBConnection();

    // date range for period-sensitive metrics
    $rangeKey = $_GET['range'] ?? 'all';
    list($rFrom, $rTo) = resolveDateRange($rangeKey, $_GET['from'] ?? '', $_GET['to'] ?? '');

    // KPI counts
    $totalPolicies = $conn->query("SELECT COUNT(*) as t FROM policy_entries")->fetch_assoc()['t'];
    $activePolicies = $conn->query("SELECT COUNT(*) as t FROM policy_entries WHERE status = 'active'")->fetch_assoc()['t'];
    $expiredPolicies = $conn->query("SELECT COUNT(*) as t FROM policy_entries WHERE status = 'expired'")->fetch_assoc()['t'];
    $cancelledPolicies = $conn->query("SELECT COUNT(*) as t FROM policy_entries WHERE status = 'cancelled'")->fetch_assoc()['t'];

    // premium + commissions for selected range (exclude cancelled)
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_premium_amount), 0) as premium, COALESCE(SUM(buyer_commission_amount), 0) as buyer_comm, COALESCE(SUM(agent_commission_amount), 0) as agent_comm, COUNT(*) as cnt FROM policy_entries WHERE status != 'cancelled' AND entry_date BETWEEN ? AND ?");
    $stmt->bind_param("ss", $rFrom, $rTo);
    $stmt->execute();
    $sums = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $totalPremium = (float) $sums['premium'];
    $totalBuyerCommission = (float) $sums['buyer_comm'];
    $totalAgentCommission = (float) $sums['agent_comm'];
    $rangePolicyCount = (int) $sums['cnt'];
    // commission spread = buyer comm - agent comm (not true profit)
    $commissionSpread = $totalBuyerCommission - $totalAgentCommission;

    // this month vs last month (premium, policy count, commission spread)
    $tmStart = date('Y-m-01');
    $tmEnd = date('Y-m-d');
    $lmStart = date('Y-m-01', strtotime('first day of last month'));
    $lmEnd = date('Y-m-t', strtotime('first day of last month'));
    $cmpStmt = $conn->prepare("SELECT COALESCE(SUM(total_premium_amount),0) as premium, COUNT(*) as cnt, COALESCE(SUM(buyer_commission_amount),0)-COALESCE(SUM(agent_commission_amount),0) as spread FROM policy_entries WHERE status != 'cancelled' AND entry_date BETWEEN ? AND ?");
    $cmpStmt->bind_param("ss", $tmStart, $tmEnd);
    $cmpStmt->execute();
    $thisMonth = $cmpStmt->get_result()->fetch_assoc();
    $cmpStmt->close();
    $cmpStmt = $conn->prepare("SELECT COALESCE(SUM(total_premium_amount),0) as premium, COUNT(*) as cnt, COALESCE(SUM(buyer_commission_amount),0)-COALESCE(SUM(agent_commission_amount),0) as spread FROM policy_entries WHERE status != 'cancelled' AND entry_date BETWEEN ? AND ?");
    $cmpStmt->bind_param("ss", $lmStart, $lmEnd);
    $cmpStmt->execute();
    $lastMonth = $cmpStmt->get_result()->fetch_assoc();
    $cmpStmt->close();

    // today's premium booked
    $todayPremium = $conn->query("SELECT COALESCE(SUM(total_premium_amount),0) as premium, COUNT(*) as cnt FROM policy_entries WHERE status != 'cancelled' AND entry_date = CURDATE()")->fetch_assoc();

    // expiring 30d + expired alert (count + premium at risk)
    $expiringAlert = $conn->query("SELECT COUNT(*) as cnt, COALESCE(SUM(total_premium_amount),0) as premium FROM policy_entries WHERE status = 'active' AND policy_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)" . DX_NOT_RENEWED)->fetch_assoc();
    $expiredAlert = $conn->query("SELECT COUNT(*) as cnt, COALESCE(SUM(total_premium_amount),0) as premium FROM policy_entries WHERE status = 'expired'" . DX_NOT_RENEWED)->fetch_assoc();
    $expiring30 = (int) $expiringAlert['cnt'];

    // counts
    $totalAgents = $conn->query("SELECT COUNT(*) as t FROM agents WHERE is_active = 1")->fetch_assoc()['t'];
    $totalCustomers = $conn->query("SELECT COUNT(*) as t FROM customers")->fetch_assoc()['t'];
    $totalVehicles = $conn->query("SELECT COUNT(*) as t FROM vehicles")->fetch_assoc()['t'];
    $totalBranches = $conn->query("SELECT COUNT(*) as t FROM branches WHERE is_active = 1")->fetch_assoc()['t'];

    // expenses + income for selected range
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as t FROM expenses WHERE approval_status = 'approved' AND expense_date BETWEEN ? AND ?");
    $stmt->bind_param("ss", $rFrom, $rTo);
    $stmt->execute();
    $totalExpenses = $stmt->get_result()->fetch_assoc()['t'];
    $stmt->close();
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as t FROM other_income WHERE income_date BETWEEN ? AND ?");
    $stmt->bind_param("ss", $rFrom, $rTo);
    $stmt->execute();
    $totalOtherIncome = $stmt->get_result()->fetch_assoc()['t'];
    $stmt->close();

    // true net margin = commission spread - expenses + other income (range-scoped)
    $netMargin = $commissionSpread - (float) $totalExpenses + (float) $totalOtherIncome;

    // todo pending
    try {
        $todoPending = $conn->query("SELECT COUNT(*) as t FROM todos WHERE status != 'completed'")->fetch_assoc()['t'];
    } catch (Throwable $e) {
        $todoPending = 0;
    } // todos not built yet

    // unread notifs
    $stmt = $conn->prepare("SELECT COUNT(*) as t FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $unreadNotifs = $stmt->get_result()->fetch_assoc()['t'];
    $stmt->close();

    // monthly premium within selected range (exclude cancelled)
    $monthlyPremium = [];
    $mpStmt = $conn->prepare("SELECT DATE_FORMAT(entry_date, '%Y-%m') as m, SUM(total_premium_amount) as premium, SUM(buyer_commission_amount) as buyer_comm, SUM(agent_commission_amount) as agent_comm FROM policy_entries WHERE status != 'cancelled' AND entry_date BETWEEN ? AND ? GROUP BY m ORDER BY m");
    $mpStmt->bind_param("ss", $rFrom, $rTo);
    $mpStmt->execute();
    $mpRes = $mpStmt->get_result();
    while ($row = $mpRes->fetch_assoc()) {
        $monthlyPremium[] = [
            'month' => date('M Y', strtotime($row['m'] . '-01')),
            'premium' => (float) $row['premium'],
            'buyerComm' => (float) $row['buyer_comm'],
            'agentComm' => (float) $row['agent_comm']
        ];
    }

    // policy by status
    $policyByStatus = ['active' => (int) $activePolicies, 'expired' => (int) $expiredPolicies, 'cancelled' => (int) $cancelledPolicies];

    // vertical / category distribution
    $categoryDist = [];
    $catRes = $conn->query("SELECT COALESCE(insurance_category, 'motor') as cat, COUNT(*) as cnt, COALESCE(SUM(total_premium_amount), 0) as prem FROM policy_entries WHERE status != 'cancelled' GROUP BY cat");
    while ($row = $catRes->fetch_assoc()) {
        $categoryDist[] = ['category' => $row['cat'], 'count' => (int) $row['cnt'], 'premium' => (float) $row['prem']];
    }

    // vehicle type dist
    $vehicleTypeDist = [];
    $vtRes = $conn->query("SELECT vehicle_type, COUNT(*) as cnt FROM policy_entries WHERE vehicle_type IS NOT NULL AND vehicle_type != '' GROUP BY vehicle_type");
    while ($row = $vtRes->fetch_assoc()) {
        $vehicleTypeDist[] = ['type' => $row['vehicle_type'], 'count' => (int) $row['cnt']];
    }

    // top 5 agents (exclude cancelled)
    $topAgents = [];
    $taRes = $conn->query("SELECT agent_name, SUM(total_premium_amount) as premium, COUNT(*) as policies FROM policy_entries WHERE agent_id IS NOT NULL AND status != 'cancelled' GROUP BY agent_id, agent_name ORDER BY premium DESC LIMIT 5");
    while ($row = $taRes->fetch_assoc()) {
        $topAgents[] = $row;
    }

    // top 5 buyers (exclude cancelled)
    $topBuyers = [];
    $tbRes = $conn->query("SELECT company_name, SUM(total_premium_amount) as premium, COUNT(*) as policies FROM policy_entries WHERE status != 'cancelled' GROUP BY buyer_id, company_name ORDER BY premium DESC LIMIT 5");
    while ($row = $tbRes->fetch_assoc()) {
        $topBuyers[] = $row;
    }

    // recent 5
    $recentPolicies = [];
    $rpRes = $conn->query("SELECT id, policy_number, customer_name, company_name, total_premium_amount, status, entry_date, insurance_category, plan_name, vehicle_number FROM policy_entries ORDER BY id DESC LIMIT 5");
    while ($row = $rpRes->fetch_assoc()) {
        $recentPolicies[] = $row;
    }

    // monthly expenses (6 months)
    $monthlyExpenses = [];
    $meRes = $conn->query("SELECT DATE_FORMAT(expense_date, '%Y-%m') as m, SUM(amount) as total FROM expenses WHERE approval_status = 'approved' AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY m ORDER BY m");
    while ($row = $meRes->fetch_assoc()) {
        $monthlyExpenses[] = [
            'month' => date('M Y', strtotime($row['m'] . '-01')),
            'total' => (float) $row['total']
        ];
    }

    // branch-wise premium (exclude cancelled)
    $branchPremium = [];
    $bpRes = $conn->query("SELECT b.name as branch, SUM(pe.total_premium_amount) as premium FROM policy_entries pe JOIN branches b ON pe.branch_id = b.id WHERE pe.status != 'cancelled' GROUP BY pe.branch_id, b.name ORDER BY premium DESC LIMIT 5");
    while ($row = $bpRes->fetch_assoc()) {
        $branchPremium[] = $row;
    }

    // recent activity feed (last 15)
    $recentActivity = [];
    $raRes = $conn->query("SELECT username, action, details, table_name, timestamp FROM activity_logs WHERE action NOT IN ('Login', 'Logout') ORDER BY timestamp DESC, id DESC LIMIT 7");
    while ($row = $raRes->fetch_assoc()) {
        $recentActivity[] = $row;
    }

    // same numbers for the period before (tile deltas)
    $prevR = prevDateRange($rangeKey, $rFrom, $rTo);
    $prev = null;
    if ($prevR) {
        $pv = dxRows($conn, "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_premium_amount), 0) AS prem, COALESCE(SUM(buyer_commission_amount), 0) - COALESCE(SUM(agent_commission_amount), 0) AS spread FROM policy_entries WHERE status != 'cancelled' AND entry_date BETWEEN ? AND ?", 'ss', $prevR[0], $prevR[1])[0];
        $pExp = dxRows($conn, "SELECT COALESCE(SUM(amount), 0) AS t FROM expenses WHERE approval_status = 'approved' AND expense_date BETWEEN ? AND ?", 'ss', $prevR[0], $prevR[1])[0]['t'];
        $pInc = dxRows($conn, "SELECT COALESCE(SUM(amount), 0) AS t FROM other_income WHERE income_date BETWEEN ? AND ?", 'ss', $prevR[0], $prevR[1])[0]['t'];
        $prev = ['count' => (int) $pv['cnt'], 'premium' => (float) $pv['prem'], 'netMargin' => (float) $pv['spread'] - (float) $pExp + (float) $pInc];
    }
    $focus = dashboardFocus($conn);

    $conn->close();

    echo json_encode([
        'success' => true,
        'data' => [
            'totalPolicies' => (int) $totalPolicies,
            'activePolicies' => (int) $activePolicies,
            'expiredPolicies' => (int) $expiredPolicies,
            'cancelledPolicies' => (int) $cancelledPolicies,
            'totalPremium' => $totalPremium,
            'totalBuyerCommission' => $totalBuyerCommission,
            'totalAgentCommission' => $totalAgentCommission,
            'netMargin' => $netMargin,
            'expiring30Days' => (int) $expiring30,
            'totalAgents' => (int) $totalAgents,
            'totalCustomers' => (int) $totalCustomers,
            'totalVehicles' => (int) $totalVehicles,
            'totalBranches' => (int) $totalBranches,
            'totalExpenses' => (float) $totalExpenses,
            'totalOtherIncome' => (float) $totalOtherIncome,
            'todoPending' => (int) $todoPending,
            'unreadNotifications' => (int) $unreadNotifs,
            'monthlyPremium' => $monthlyPremium,
            'policyByStatus' => $policyByStatus,
            'categoryDistribution' => $categoryDist,
            'vehicleTypeDistribution' => $vehicleTypeDist,
            'topAgents' => $topAgents,
            'topBuyers' => $topBuyers,
            'recentPolicies' => $recentPolicies,
            'monthlyExpenses' => $monthlyExpenses,
            'branchWisePremium' => $branchPremium,
            'rangePolicyCount' => $rangePolicyCount,
            'rangeLabel' => $rFrom . ' to ' . $rTo,
            'commissionSpread' => $commissionSpread,
            'todayPremium' => (float) $todayPremium['premium'],
            'todayPolicyCount' => (int) $todayPremium['cnt'],
            'expiringSoonCount' => (int) $expiringAlert['cnt'],
            'expiringSoonPremium' => (float) $expiringAlert['premium'],
            'expiredCount' => (int) $expiredAlert['cnt'],
            'expiredPremium' => (float) $expiredAlert['premium'],
            'compare' => [
                'thisMonthPremium' => (float) $thisMonth['premium'],
                'lastMonthPremium' => (float) $lastMonth['premium'],
                'thisMonthCount' => (int) $thisMonth['cnt'],
                'lastMonthCount' => (int) $lastMonth['cnt'],
                'thisMonthSpread' => (float) $thisMonth['spread'],
                'lastMonthSpread' => (float) $lastMonth['spread']
            ],
            'recentActivity' => $recentActivity,
            'prev' => $prev,
            'focus' => $focus
        ]
    ]);
    exit();
}

$branding = getBranding();
?>
<!--
  Developed by Rudees Digital
  Website: https://rudeesdigital.com
  Email: contact@rudeesdigital.com
  Website: https://rudeesdigital.com
  Email: contact@rudeesdigital.com
  WhatsApp: +91 8160140848
-->
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="icon" href="<?php echo htmlspecialchars(getBranding()['site_logo']); ?>">
    <!-- tab icon, stops the browser asking for /favicon.ico -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Dashboard - <?php echo htmlspecialchars($branding['site_name']); ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="styles.css?v=16.0">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="app.js?v=2.3"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>

<body>
    <script>/* theme before paint */(function () { var t = localStorage.getItem('theme'); if (t === 'dark' || (!t && matchMedia('(prefers-color-scheme:dark)').matches)) document.body.classList.add('dark-mode'); })();</script>
    <?php echo getSearchableDropdownJS(); ?>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <?php
            $isCustomer = ($role === 'customer');
            $isAgentDash = ($role === 'agent');
            $pageIcon = $isCustomer ? 'fa-shield-halved' : 'fa-chart-line';
            $pageTitle = $isCustomer ? 'Customer & Family Insurance Portal' : 'Dashboard';
            $crumbs = [];
            if ($isCustomer) {
                $pageActions = [
                    ['label' => 'My Policies', 'icon' => 'fa-file-shield', 'onclick' => "location.href='policy_entries.php'", 'class' => 'primary'],
                    ['label' => 'Intimate Claim', 'icon' => 'fa-car-burst', 'onclick' => "location.href='claims.php?new=1'"],
                    ['label' => 'Family Members', 'icon' => 'fa-users', 'onclick' => "location.href='customers.php?view=family'"],
                    ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadCustomerStats()'],
                ];
            } else {
                $pageActions = [
                    ['label' => 'New Policy', 'icon' => 'fa-file-circle-plus', 'onclick' => "location.href='policy_entries.php'", 'class' => 'primary'],
                    ['label' => 'Add Customer', 'icon' => 'fa-user-plus', 'onclick' => "location.href='customers.php'"],
                    $isAgentDash
                    ? ['label' => 'Notifications', 'icon' => 'fa-bell', 'onclick' => "location.href='notifications.php'"]
                    : ['label' => 'Send Notification', 'icon' => 'fa-paper-plane', 'onclick' => "location.href='notifications.php'"],
                    ['label' => 'Add Task', 'icon' => 'fa-list-check', 'onclick' => "location.href='todo.php'"],
                    ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => $isAgentDash ? 'loadAgentStats()' : 'loadDashboardStats()'],
                ];
            }
            include 'page_header.php';
            ?>

            <?php
            // focus layout — same four panels for every role, data filled by renderFocus()
            $dxFeedLink = $role === 'admin' ? 'logs.php' : 'notifications.php';
            $dxFeedTip = $role === 'admin' ? 'All activity' : 'All notifications';
            $dxSkel = '<div class="dx dx-sk">'
                . '<div class="skeleton-card dx-w5"><div class="skeleton skeleton-chart"></div></div>'
                . '<div class="skeleton-card dx-w7"><div class="skeleton skeleton-chart"></div></div>'
                . '<div class="skeleton-card dx-w8"><div class="skeleton skeleton-chart"></div></div>'
                . '<div class="skeleton-card dx-w4"><div class="skeleton skeleton-chart"></div></div></div>';
            ob_start(); ?>
            <div class="dx">
                <section class="dx-card dx-hero dx-w5" aria-label="Premium at risk">
                    <div class="dx-head">
                        <span class="dx-kicker">Premium at risk</span>
                        <div class="dx-tools"><a class="head-btn" href="policy_entries.php?expiry=soon"
                                title="Review expiring policies" aria-label="Review expiring policies"><i
                                    class="fas fa-up-right-from-square"></i></a></div>
                    </div>
                    <div class="dx-hero-body">
                        <div class="dx-hero-num">
                            <div class="dx-amt" id="dxAmt">0</div>
                            <div class="dx-amt-sub" id="dxAmtSub"></div>
                        </div>
                        <div class="dx-bars" id="dxBars" title="Premium booked, last 8 months"></div>
                    </div>
                    <div class="dx-mini">
                        <div><small>Due this week</small><b id="dxWeek">&mdash;</b></div>
                        <div><small>Largest policy</small><b id="dxBig">&mdash;</b></div>
                        <div><small>Next expiry</small><b id="dxFirst">&mdash;</b></div>
                    </div>
                </section>

                <section class="dx-card dx-w7" aria-labelledby="dxPeriod">
                    <div class="dx-head">
                        <h3 id="dxPeriod">This Month</h3>
                        <div class="dx-tools">
                            <button type="button" class="head-btn" onclick="dxPickRange()" title="Change period"
                                aria-label="Change period"><i class="fas fa-sliders"></i></button>
                            <div class="more-menu">
                                <button type="button" class="head-btn more-btn" title="More"
                                    aria-label="More options"><i class="fas fa-ellipsis-vertical"></i></button>
                                <div class="more-dropdown">
                                    <button type="button" class="more-item"
                                        onclick="<?php echo $isAgentDash ? 'loadAgentStats()' : 'loadDashboardStats()'; ?>"><i
                                            class="fas fa-rotate"></i> Refresh</button>
                                    <a class="more-item" href="policy_entries.php"><i class="fas fa-file-invoice"></i>
                                        Policy entries</a>
                                    <?php if (!$isAgentDash): ?><a class="more-item" href="reports.php"><i
                                                class="fas fa-chart-column"></i> Reports</a><?php endif; ?>
                                    <a class="more-item" href="notifications.php"><i class="fas fa-bell"></i>
                                        Notifications</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="dx-tiles" id="dxTiles"></div>
                </section>

                <section class="dx-card dx-w8" aria-label="Attention required">
                    <div class="dx-head">
                        <div>
                            <h3>Attention required</h3>
                            <small id="dxAttnSub">Expiring or expired within 30 days, nearest first</small>
                        </div>
                        <div class="dx-tools">
                            <span class="dx-pill" id="dxAttnPill" hidden></span>
                            <?php if (!$isAgentDash): ?><a class="dx-pill dx-pill-link" id="dxDuesPill"
                                    href="reports.php?report=dues"
                                    title="Customer dues — paid to the insurer, not yet received" hidden></a><?php endif; ?>
                            <a class="head-btn" href="policy_entries.php?expiry=soon" title="All expiring policies"
                                aria-label="All expiring policies"><i class="fas fa-up-right-from-square"></i></a>
                        </div>
                    </div>
                    <div class="dx-rows" id="dxAttn"></div>
                </section>

                <section class="dx-card dx-w4" aria-label="Recent activity">
                    <div class="dx-head">
                        <h3>Recent activity</h3>
                        <div class="dx-tools"><a class="head-btn" href="<?php echo $dxFeedLink; ?>"
                                title="<?php echo $dxFeedTip; ?>" aria-label="<?php echo $dxFeedTip; ?>"><i
                                    class="fas fa-ellipsis-vertical"></i></a></div>
                    </div>
                    <div class="dx-feed-list" id="dxFeed"></div>
                </section>
            </div>
            <?php $dxHtml = ob_get_clean(); ?>

            <?php if ($role === 'admin' || $role === 'manager'): ?>
                <!-- ====== ADMIN / MANAGER DASHBOARD ====== -->

                <div data-head-filter>
                    <div class="hf-field"><label for="rangeSelect" title="Reporting period"><i
                                class="fas fa-calendar-day"></i></label><select id="rangeSelect"
                            class="filter-input searchable">
                            <option value="all">All Time</option>
                            <option value="today">Today</option>
                            <option value="week">This Week</option>
                            <option value="month" selected>This Month</option>
                            <option value="quarter">This Quarter</option>
                            <option value="custom">Custom Range</option>
                        </select></div>
                </div>
                <div data-head-filter id="rangeFromWrap" style="display:none">
                    <div class="hf-field hf-range" title="Custom range"><label for="rangeFrom"><i
                                class="fas fa-calendar-days"></i></label><input type="date" id="rangeFrom"
                            class="filter-input" aria-label="From"><span class="hf-sep"><i
                                class="fas fa-arrow-right-long"></i></span><input type="date" id="rangeTo"
                            class="filter-input" aria-label="To"><button type="button" class="btn btn-icon" id="rangeApply"
                            title="Apply range" aria-label="Apply range"><i class="fas fa-check"></i></button></div>
                </div>
                <script>rsPullHeadFilters();</script>

                <!-- Skeleton -->
                <div id="adminSkeleton">
                    <?php echo $dxSkel; ?>
                    <div class="dashboard-grid-2">
                        <div class="chart-card">
                            <div class="skeleton skeleton-chart"></div>
                        </div>
                        <div class="chart-card">
                            <div class="skeleton skeleton-chart"></div>
                        </div>
                    </div>
                </div>

                <!-- Real content (hidden until loaded) -->
                <div id="adminContent" style="display:none">

                    <?php echo $dxHtml; ?>

                    <!-- Row 3: Monthly Premium & Policy Status -->
                    <div class="dashboard-grid-2">
                        <div class="chart-card">
                            <h3><i class="fas fa-chart-area"></i> Monthly Premium & Commission</h3>
                            <div class="chart-container">
                                <canvas id="chartMonthlyPremium"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h3><i class="fas fa-chart-pie"></i> Policy Status</h3>
                            <div class="chart-container">
                                <canvas id="chartPolicyStatus"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Row 3B: Insurance Verticals & Vehicle Type -->
                    <div class="dashboard-grid-2">
                        <div class="chart-card">
                            <h3><i class="fas fa-shield-halved"></i> Insurance Verticals (Motor · Health · Life)</h3>
                            <div class="chart-container">
                                <canvas id="chartCategoryDist"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h3><i class="fas fa-car-side"></i> Vehicle Type Distribution</h3>
                            <div class="chart-container">
                                <canvas id="chartVehicleType"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Row 4: Branch Premium + Monthly Expenses -->
                    <div class="dashboard-grid-2">
                        <div class="chart-card">
                            <h3><i class="fas fa-code-branch"></i> Branch-wise Premium</h3>
                            <div class="chart-container">
                                <canvas id="chartBranchPremium"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h3><i class="fas fa-receipt"></i> Monthly Expenses</h3>
                            <div class="chart-container">
                                <canvas id="chartMonthlyExpenses"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Row 5: Top Agents + Top Buyers -->
                    <div class="dashboard-grid-2">
                        <div class="data-section">
                            <div class="section-header">
                                <h2><i class="fas fa-trophy"></i> Top 5 Agents</h2>
                            </div>
                            <table class="report-top-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Agent Name</th>
                                        <th>Policies</th>
                                        <th>Premium</th>
                                    </tr>
                                </thead>
                                <tbody id="tblTopAgents"></tbody>
                            </table>
                        </div>
                        <div class="data-section">
                            <div class="section-header">
                                <h2><i class="fas fa-building"></i> Top 5 Buyers</h2>
                            </div>
                            <table class="report-top-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Company</th>
                                        <th>Policies</th>
                                        <th>Premium</th>
                                    </tr>
                                </thead>
                                <tbody id="tblTopBuyers"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Row 6: Recent Policies -->
                    <div class="data-section">
                        <div class="section-header">
                            <h2><i class="fas fa-clock"></i> Recent Policies</h2>
                        </div>
                        <table class="report-top-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Vertical</th>
                                    <th>Policy #</th>
                                    <th>Customer</th>
                                    <th>Premium</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="tblRecentPolicies"></tbody>
                        </table>
                    </div>

                </div>
            <?php elseif ($role === 'customer'): ?>
                <!-- ====== CUSTOMER & FAMILY INSURANCE PORTAL ====== -->
                <div id="custPortalSkeleton" class="data-section">
                    <div class="skeleton-card" style="height:120px;margin-bottom:16px;"></div>
                    <div
                        style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:16px;">
                        <div class="skeleton-card" style="height:100px;"></div>
                        <div class="skeleton-card" style="height:100px;"></div>
                        <div class="skeleton-card" style="height:100px;"></div>
                        <div class="skeleton-card" style="height:100px;"></div>
                    </div>
                </div>

                <div id="custPortalContent" style="display:none;">
                    <!-- Hero & Agent Contact -->
                    <div
                        style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:20px;">
                        <div class="card"
                            style="margin-bottom:0;background:linear-gradient(135deg, #0f2b48 0%, #1a4971 100%);color:#fff;border:none;">
                            <div class="card-body" style="padding:24px;">
                                <span class="status-badge chip-blue"
                                    style="margin-bottom:10px;display:inline-block;background:rgba(255,255,255,0.15);color:#fff;border-color:rgba(255,255,255,0.25);">
                                    <i class="fas fa-house-chimney-user"></i> Family Policy Portal
                                </span>
                                <h2 style="margin:0 0 6px 0;font-size:22px;color:#fff;" id="custWelcomeName">Welcome</h2>
                                <p style="margin:0;opacity:0.85;font-size:13px;" id="custSubDesc">Track and manage insurance
                                    protection for yourself and all family members.</p>
                                <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
                                    <a href="policy_entries.php" class="btn btn-sm"
                                        style="background:#fff;color:#0f2b48;font-weight:600;"><i
                                            class="fas fa-file-shield"></i> View All Policies</a>
                                    <a href="claims.php?new=1" class="btn btn-sm btn-danger"
                                        style="background:#ef4444;border-color:#ef4444;"><i class="fas fa-car-burst"></i>
                                        Intimate Claim</a>
                                </div>
                            </div>
                        </div>

                        <div class="card" style="margin-bottom:0;" id="custAgentCard">
                            <div class="card-header"
                                style="background:var(--bg-light);border-bottom:1px solid var(--border-color);">
                                <h4 style="margin:0;font-size:15px;color:var(--navy-primary);"><i
                                        class="fas fa-user-tie"></i> Dedicated Insurance Agent</h4>
                            </div>
                            <div class="card-body" style="padding:18px;">
                                <div style="display:flex;align-items:center;gap:14px;">
                                    <div
                                        style="width:48px;height:48px;border-radius:50%;background:rgba(30,58,138,0.1);display:flex;align-items:center;justify-content:center;color:var(--navy-primary);font-size:20px;">
                                        <i class="fas fa-headset"></i>
                                    </div>
                                    <div style="flex:1;">
                                        <h4 style="margin:0 0 4px 0;font-size:16px;" id="custAgentName">Assigned Agent</h4>
                                        <span class="text-muted" style="font-size:12px;" id="custAgentCode">Advisor</span>
                                    </div>
                                </div>
                                <div style="margin-top:14px;display:flex;gap:8px;" id="custAgentActions">
                                    <a href="#" id="custAgentCallBtn" class="btn btn-sm btn-secondary"
                                        style="flex:1;text-align:center;"><i class="fas fa-phone"></i> Call Agent</a>
                                    <a href="#" id="custAgentWaBtn" target="_blank" rel="noopener"
                                        class="btn btn-sm btn-success"
                                        style="flex:1;text-align:center;background:#25D366;border-color:#25D366;"><i
                                            class="fab fa-whatsapp"></i> WhatsApp</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 4 Major Coverage Cards -->
                    <div
                        style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px;">
                        <!-- Active Policies -->
                        <div class="card" style="margin-bottom:0;border-left:4px solid #2563eb;">
                            <div class="card-body" style="padding:18px;">
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                                    <div>
                                        <span class="text-muted"
                                            style="font-size:12px;text-transform:uppercase;font-weight:600;">Total
                                            Protection</span>
                                        <h3 style="margin:6px 0 2px 0;font-size:24px;" id="tileActivePol">0</h3>
                                        <small class="text-muted" id="tileTotalPolSub">0 total policies</small>
                                    </div>
                                    <span
                                        style="width:40px;height:40px;border-radius:8px;background:rgba(37,99,235,0.1);color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:18px;"><i
                                            class="fas fa-shield-check"></i></span>
                                </div>
                            </div>
                        </div>

                        <!-- Motor Insurance -->
                        <div class="card" style="margin-bottom:0;border-left:4px solid #3b82f6;">
                            <div class="card-body" style="padding:18px;">
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                                    <div>
                                        <span class="text-muted"
                                            style="font-size:12px;text-transform:uppercase;font-weight:600;">Motor
                                            Insurance</span>
                                        <h3 style="margin:6px 0 2px 0;font-size:24px;" id="tileMotorCount">0</h3>
                                        <small class="text-muted">Vehicles Insured</small>
                                    </div>
                                    <span
                                        style="width:40px;height:40px;border-radius:8px;background:rgba(59,130,246,0.1);color:#3b82f6;display:flex;align-items:center;justify-content:center;font-size:18px;"><i
                                            class="fas fa-car"></i></span>
                                </div>
                            </div>
                        </div>

                        <!-- Health Insurance -->
                        <div class="card" style="margin-bottom:0;border-left:4px solid #10b981;">
                            <div class="card-body" style="padding:18px;">
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                                    <div>
                                        <span class="text-muted"
                                            style="font-size:12px;text-transform:uppercase;font-weight:600;">Health Sum
                                            Insured</span>
                                        <h3 style="margin:6px 0 2px 0;font-size:22px;color:#059669;" id="tileHealthSI">₹0
                                        </h3>
                                        <small class="text-muted" id="tileHealthSub">0 Active Plan(s)</small>
                                    </div>
                                    <span
                                        style="width:40px;height:40px;border-radius:8px;background:rgba(16,185,129,0.1);color:#10b981;display:flex;align-items:center;justify-content:center;font-size:18px;"><i
                                            class="fas fa-heart-pulse"></i></span>
                                </div>
                            </div>
                        </div>

                        <!-- Life Insurance -->
                        <div class="card" style="margin-bottom:0;border-left:4px solid #8b5cf6;">
                            <div class="card-body" style="padding:18px;">
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                                    <div>
                                        <span class="text-muted"
                                            style="font-size:12px;text-transform:uppercase;font-weight:600;">Life Sum
                                            Assured</span>
                                        <h3 style="margin:6px 0 2px 0;font-size:22px;color:#7c3aed;" id="tileLifeSA">₹0</h3>
                                        <small class="text-muted" id="tileLifeSub">0 Active Plan(s)</small>
                                    </div>
                                    <span
                                        style="width:40px;height:40px;border-radius:8px;background:rgba(139,92,246,0.1);color:#8b5cf6;display:flex;align-items:center;justify-content:center;font-size:18px;"><i
                                            class="fas fa-user-shield"></i></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Alert banner if expiring soon -->
                    <div id="custExpiringAlert"
                        style="display:none;margin-bottom:20px;padding:14px 18px;border-radius:8px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;align-items:center;justify-content:space-between;">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <i class="fas fa-triangle-exclamation" style="font-size:20px;"></i>
                            <div>
                                <strong>Upcoming Expiry Alert:</strong>
                                <span id="custExpiringText">You have policies expiring in the next 30 days.</span>
                            </div>
                        </div>
                        <a href="policy_entries.php" class="btn btn-sm btn-danger"><i class="fas fa-redo"></i> Renew Now</a>
                    </div>

                    <!-- Policies & Family Members 2-Column Section -->
                    <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px;">
                        <!-- Recent Policies Table -->
                        <div class="card" style="margin-bottom:0;">
                            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                                <h3 style="margin:0;font-size:16px;"><i class="fas fa-file-contract"></i> My &amp; Family
                                    Policies</h3>
                                <a href="policy_entries.php" class="btn btn-sm btn-secondary">View All</a>
                            </div>
                            <div class="card-body" style="padding:0;">
                                <div style="overflow-x:auto;">
                                    <table class="table" style="margin:0;" id="custPoliciesTable">
                                        <thead>
                                            <tr>
                                                <th>Member</th>
                                                <th>Vertical</th>
                                                <th>Policy / Details</th>
                                                <th>Insurer</th>
                                                <th>Expiry</th>
                                                <th>Status</th>
                                                <th style="text-align:right;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="custPoliciesList">
                                            <tr>
                                                <td colspan="7" class="text-center text-muted" style="padding:20px;">Loading
                                                    policies...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Family Group Members -->
                        <div class="card" style="margin-bottom:0;">
                            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                                <h3 style="margin:0;font-size:16px;"><i class="fas fa-people-roof"></i> Family Group</h3>
                                <a href="customers.php?view=family" class="btn btn-sm btn-secondary"><i
                                        class="fas fa-user-plus"></i> Add</a>
                            </div>
                            <div class="card-body" style="padding:14px;" id="custFamilyList">
                                <div class="text-muted text-center" style="padding:15px;">Loading family members...</div>
                            </div>
                        </div>
                    </div>

                    <!-- Active Claims Tracker -->
                    <div class="card" style="margin-bottom:0;" id="custClaimsCard">
                        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                            <h3 style="margin:0;font-size:16px;"><i class="fas fa-car-burst"></i> Recent &amp; Active Claims
                            </h3>
                            <a href="claims.php" class="btn btn-sm btn-secondary">View All Claims</a>
                        </div>
                        <div class="card-body" style="padding:0;">
                            <div style="overflow-x:auto;">
                                <table class="table" style="margin:0;" id="custClaimsTable">
                                    <thead>
                                        <tr>
                                            <th>Claim #</th>
                                            <th>Policy #</th>
                                            <th>Member</th>
                                            <th>Type</th>
                                            <th>Claimed Amount</th>
                                            <th>Status</th>
                                            <th>Intimation Date</th>
                                        </tr>
                                    </thead>
                                    <tbody id="custClaimsList">
                                        <tr>
                                            <td colspan="7" class="text-center text-muted" style="padding:15px;">No active
                                                claims recorded.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- ====== AGENT DASHBOARD ====== -->

                <div data-head-filter>
                    <div class="hf-field"><label for="agRangeSelect" title="Reporting period"><i
                                class="fas fa-calendar-day"></i></label><select id="agRangeSelect"
                            class="filter-input searchable">
                            <option value="all">All Time</option>
                            <option value="today">Today</option>
                            <option value="week">This Week</option>
                            <option value="month" selected>This Month</option>
                            <option value="quarter">This Quarter</option>
                            <option value="custom">Custom Range</option>
                        </select></div>
                </div>
                <div data-head-filter id="agRangeFromWrap" style="display:none">
                    <div class="hf-field hf-range" title="Custom range"><label for="agRangeFrom"><i
                                class="fas fa-calendar-days"></i></label><input type="date" id="agRangeFrom"
                            class="filter-input" aria-label="From"><span class="hf-sep"><i
                                class="fas fa-arrow-right-long"></i></span><input type="date" id="agRangeTo"
                            class="filter-input" aria-label="To"><button type="button" class="btn btn-icon"
                            id="agRangeApply" title="Apply range" aria-label="Apply range"><i
                                class="fas fa-check"></i></button></div>
                </div>
                <script>rsPullHeadFilters();</script>

                <!-- Skeleton -->
                <div id="agentSkeleton">
                    <?php echo $dxSkel; ?>
                    <div class="dashboard-grid-2">
                        <div class="chart-card">
                            <div class="skeleton skeleton-chart"></div>
                        </div>
                        <div class="chart-card">
                            <div class="skeleton skeleton-chart"></div>
                        </div>
                    </div>
                </div>

                <!-- Real content -->
                <div id="agentContent" style="display:none">

                    <!-- Agent Subscription & Plan Usage Banner -->
                    <div id="agSubscriptionBanner" class="dx-card" style="margin-bottom: 20px; padding: 18px 22px; border-radius: 12px; background: white; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span class="badge" id="agSubPlanBadge" style="background: #001f3f; color: white; padding: 4px 12px; border-radius: 20px; font-weight: 700; font-size: 13px;">Plan Loading...</span>
                                <span id="agSubDaysLeft" style="font-size: 13.5px; color: #166534; font-weight: 600;"><i class="fas fa-clock"></i> Active</span>
                            </div>
                            <div style="display: flex; gap: 24px; margin-top: 12px; flex-wrap: wrap;">
                                <div>
                                    <small style="color: #64748b; font-size: 12px; display: block;">Customers Quota</small>
                                    <strong id="agCustUsage" style="font-size: 15px; color: #0f172a;">- / -</strong>
                                </div>
                                <div>
                                    <small style="color: #64748b; font-size: 12px; display: block;">Policies Quota</small>
                                    <strong id="agPolUsage" style="font-size: 15px; color: #0f172a;">- / -</strong>
                                </div>
                                <div>
                                    <small style="color: #64748b; font-size: 12px; display: block;">Monthly AI Scans</small>
                                    <strong id="agAiUsage" style="font-size: 15px; color: #0284c7;">- / -</strong>
                                </div>
                                <div>
                                    <small style="color: #64748b; font-size: 12px; display: block;">WhatsApp Reminders</small>
                                    <strong id="agWaUsage" style="font-size: 15px; color: #10b981;">- / -</strong>
                                </div>
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <a href="policy_upload.php" class="btn btn-primary" style="padding: 9px 16px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fas fa-file-arrow-up"></i> Upload & Scan Policy
                            </a>
                            <a href="customer_followups.php" class="btn btn-secondary" style="padding: 9px 14px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fas fa-headset"></i> Follow-ups
                            </a>
                            <a href="agent_plans.php" class="btn btn-secondary" style="padding: 9px 14px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                <i class="fas fa-crown"></i> Plan
                            </a>
                        </div>
                    </div>

                    <?php echo $dxHtml; ?>

                    <!-- Row 3: Charts -->
                    <div class="dashboard-grid-3">
                        <div class="chart-card">
                            <h3><i class="fas fa-chart-line"></i> My Monthly Commission</h3>
                            <div class="chart-container">
                                <canvas id="chartAgentCommission"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h3><i class="fas fa-shield-halved"></i> My Insurance Verticals</h3>
                            <div class="chart-container">
                                <canvas id="chartAgentCategory"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h3><i class="fas fa-chart-pie"></i> My Policy Status</h3>
                            <div class="chart-container">
                                <canvas id="chartAgentStatus"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Row 4: Recent Policies -->
                    <div class="data-section" style="margin-bottom:30px">
                        <div class="section-header">
                            <h2><i class="fas fa-clock"></i> Recent Policies</h2>
                        </div>
                        <table class="report-top-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Vertical</th>
                                    <th>Policy #</th>
                                    <th>Customer</th>
                                    <th>Company</th>
                                    <th>Premium</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="tblAgentRecent"></tbody>
                        </table>
                    </div>

                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // currency formatter
        var cur = function (v) { return '<?php echo $branding['currency_symbol']; ?>' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
        var fmt = function (v) { return Number(v).toLocaleString('en-IN'); };

        // chart colors
        var cNavy = '#001f3f', cBlue = '#0074D9', cGreen = '#34a853', cYellow = '#fbbc04', cRed = '#ea4335', cGray = '#6c757d';
        var chartColors = [cNavy, cBlue, cGreen, cYellow, cRed, cGray, '#7b2ff7', '#ff6f61', '#00b4d8', '#e76f51'];

        // status badge helper
        function statusBadge(s) {
            var cls = s === 'active' ? 'status-active' : (s === 'expired' ? 'status-expired' : 'status-cancelled');
            return '<span class="policy-status ' + cls + '">' + s + '</span>';
        }

        // date format
        function fmtDate(d) {
            if (!d) return '-';
            var dt = new Date(d);
            return dt.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
        }

        // chart defaults
        Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
        Chart.defaults.responsive = true;
        Chart.defaults.maintainAspectRatio = false;

        // tooltip currency callback
        var currencyTooltip = {
            callbacks: {
                label: function (ctx) {
                    return ctx.dataset.label + ': ' + cur(ctx.parsed.y || ctx.parsed || 0);
                }
            }
        };

        // range labels for the "showing for" caption
        var rangeLabels = { all: 'All Time', today: 'Today', week: 'This Week', month: 'This Month', quarter: 'This Quarter', custom: 'Custom Range' };

        // ===== focus layout: hero · period tiles · attention list · activity =====
        var CUR = '<?php echo $branding['currency_symbol']; ?>';
        var curWhole = function (v) { return CUR + Math.round(Number(v) || 0).toLocaleString('en-IN'); };
        // compact money for tiles -> [value, unit] (K / L / Cr)
        function curShort(v) {
            v = Number(v) || 0;
            var a = Math.abs(v), u = a >= 1e7 ? ['Cr', 1e7] : a >= 1e5 ? ['L', 1e5] : a >= 1e3 ? ['K', 1e3] : ['', 1];
            return [(v < 0 ? '-' : '') + CUR + (u[1] > 1 ? (a / u[1]).toFixed(2).replace(/\.?0+$/, '') : Math.round(a)), u[0]];
        }
        var dShort = function (d) {
            if (!d) return '—'; var x = new Date(String(d).slice(0, 10) + 'T00:00:00');
            return isNaN(x) ? String(d) : x.toLocaleDateString('en-US', x.getFullYear() === new Date().getFullYear() ? { month: 'short', day: '2-digit' } : { month: 'short', day: '2-digit', year: 'numeric' });
        };
        var PREV_TXT = { today: 'vs yesterday', week: 'vs last week', month: 'vs last month', quarter: 'vs last quarter', custom: 'vs prior period' };

        // change vs the period before, as an amount; prev null = all time (nothing to compare)
        function dxDelta(curV, prevV, key, money) {
            if (prevV == null) return '<span>All time</span>';
            var vs = ' ' + (PREV_TXT[key] || 'vs prior period'), d = (Number(curV) || 0) - (Number(prevV) || 0);
            if (!d) return '<span>Same as ' + vs.replace(' vs ', '') + '</span>';
            var amt = money ? curShort(Math.abs(d)).join('') : fmt(Math.abs(d));
            return '<b class="' + (d > 0 ? 'dx-up' : 'dx-down') + '">' + (d > 0 ? '&uarr; ' : '&darr; ') + rsEsc(amt) + '</b>' + vs;
        }
        function dxTile(label, icon, tone, val, unit, foot, tip) {
            return '<div class="dx-tile dx-t-' + (tone || 'info') + '"><span class="dx-tile-lbl">' + (icon ? '<i class="fas ' + icon + '"></i>' : '') + rsEsc(label) + '</span>'
                + '<div class="dx-val"' + (tip ? ' title="' + rsEsc(tip) + '"' : '') + '>' + rsEsc(val) + (unit ? '<small>' + rsEsc(unit) + '</small>' : '') + '</div>'
                + '<div class="dx-delta">' + foot + '</div></div>';
        }
        function dxMoney(label, icon, tone, v, foot) { var c = curShort(v); return dxTile(label, icon, tone, c[0], c[1], foot, cur(v)); }
        function dxExpiring(d) {
            return dxTile('Expiring in 30 days', 'fa-hourglass-half', 'warn', fmt(d.expiringSoonCount), d.expiringSoonCount === 1 ? 'policy' : 'policies',
                d.expiredCount ? '<b class="dx-down">' + fmt(d.expiredCount) + '</b> already expired' : '<span>None expired</span>');
        }

        function dxHero(d, f, mine) {
            var n = d.expiringSoonCount;
            $('#dxAmt').text(curWhole(d.expiringSoonPremium)).attr('title', cur(d.expiringSoonPremium));
            $('#dxAmtSub').text('across ' + fmt(n) + (mine ? ' of your ' : ' ') + (n === 1 ? 'policy' : 'policies') + ' expiring in the next 30 days');
            var max = Math.max.apply(null, f.bars.map(function (b) { return b.v; }).concat(1));
            $('#dxBars').html(f.bars.map(function (b, i, all) { // bar height = runtime value
                return '<span class="dx-bar' + (i === all.length - 1 ? ' is-now' : '') + '" style="height:' + Math.max(6, b.v / max * 100).toFixed(1) + '%" title="' + rsEsc(b.m + ': ' + cur(b.v)) + '">'
                    + (i === 0 || i === all.length - 1 ? '<em>' + rsEsc(b.m) + '</em>' : '') + '</span>';
            }).join(''));
            $('#dxWeek').text(curWhole(f.weekPremium)).attr('title', fmt(f.weekCount) + (f.weekCount === 1 ? ' policy' : ' policies'));
            $('#dxBig').text(f.big ? curWhole(f.big.premium) : '—').attr('title', f.big ? f.big.customer + ' · ' + f.big.policy : '');
            $('#dxFirst').text(dShort(f.first));
        }

        var vehIcon = function (t) { t = String(t || '').toLowerCase(); return /two|bike|motor|scoot/.test(t) ? 'fa-motorcycle' : /commer|truck|goods|bus|tractor/.test(t) ? 'fa-truck' : /car|four|private/.test(t) ? 'fa-car-side' : 'fa-shield-halved'; };
        var initials = function (n) { var p = String(n || '?').trim().split(/\s+/); return (p[0].charAt(0) + (p[1] ? p[1].charAt(0) : p[0].charAt(1))).toUpperCase(); };
        function dxIssue(days) {
            if (days < 0) return ['bad', 'fa-circle-exclamation', 'Expired ' + -days + (days === -1 ? ' day' : ' days') + ' ago'];
            if (days === 0) return ['bad', 'fa-hourglass-end', 'Expires today'];
            if (days <= 7) return ['warn', 'fa-hourglass-half', 'Expires in ' + days + (days === 1 ? ' day' : ' days')];
            return ['info', 'fa-calendar-day', 'Renewal due in ' + days + ' days'];
        }
        function dxAttn(rows, total, later) {
            $('#dxAttnPill').prop('hidden', !total).text(fmt(total) + ' need action');
            $('#dxAttnSub').text(later ? 'Nothing due in the next 30 days. Next renewals coming up' : 'Expiring or expired within 30 days, nearest first');
            if (!rows.length) return $('#dxAttn').html('<div class="dx-empty">No active policies with an upcoming renewal.</div>');
            var head = '<div class="dx-row dx-th"><span>Policy</span><span>Issue</span><span>Insurer</span><span>Time left</span><span>Expiry</span><span></span></div>';
            $('#dxAttn').html(head + rows.map(function (r) {
                var is = dxIssue(r.days), left = Math.max(0, r.days), tone = left > 14 ? 'ok' : left > 3 ? 'warn' : 'bad',
                    txt = left > 60 ? 'Renews ' + dShort(r.policy_end_date) : is[2];
                var subInfo = '';
                var ccat = String(r.insurance_category || 'motor').toLowerCase();
                if (ccat === 'health') {
                    subInfo = (r.plan_name || 'Health Plan') + ' · Health';
                } else if (ccat === 'life') {
                    subInfo = (r.plan_name || 'Life Policy') + ' · Life';
                } else {
                    subInfo = (r.vehicle_type || '') + ' ' + (r.vehicle_number || '');
                }
                return '<div class="dx-row">'
                    + '<div class="dx-case"><b>' + rsEsc(r.customer_name) + '</b><small>' + rsEsc(r.policy_number + ' · ' + subInfo) + '</small></div>'
                    + '<div class="dx-issue dx-c-' + is[0] + '">' + rsEsc(txt) + '</div>'
                    + '<div class="dx-buyer">' + rsEsc(r.company_name) + '</div>'
                    + '<div class="dx-odds" title="' + left + ' days left"><span>' + (left === 1 ? '1 day' : left + ' days') + '</span><i class="dx-c-' + tone + '"><em style="width:' + Math.round(Math.min(left, 30) / 30 * 100) + '%"></em></i></div>'
                    + '<div class="dx-due">' + dShort(r.policy_end_date) + '</div>'
                    + '<div class="dx-acts"><a class="head-btn" href="policy_entries.php?action=waReminder&amp;id=' + (r.id | 0) + '" target="_blank" rel="noopener" title="WhatsApp renewal reminder" aria-label="WhatsApp renewal reminder for ' + rsEsc(r.policy_number) + '"><i class="fab fa-whatsapp"></i></a>'
                    + '<a class="head-btn dx-act" href="policy_entries.php?id=' + (r.id | 0) + '" aria-label="Review policy ' + rsEsc(r.policy_number) + '"><span>Review</span></a></div>'
                    + '</div>';
            }).join(''));
        }

        // feed time: today = clock, then Yesterday, then date
        function dxWhen(ts) {
            var x = new Date(String(ts || '').replace(' ', 'T')); if (isNaN(x)) return '';
            var d0 = new Date(); d0.setHours(0, 0, 0, 0);
            if (x >= d0) return x.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            return x >= d0 - 864e5 ? 'Yesterday' : x.toLocaleDateString('en-US', { month: 'short', day: '2-digit' });
        }
        function dxFeed(items, emptyTxt) {
            $('#dxFeed').html(items.length ? items.map(function (f) {
                return '<div class="dx-feed-item"><span class="dx-dot dx-bg-' + f.tone + '"></span>'
                    + '<div><b>' + rsEsc(f.title) + '</b><small>' + rsEsc(f.sub) + '</small></div><time>' + rsEsc(dxWhen(f.at)) + '</time></div>';
            }).join('') : '<div class="dx-empty">' + rsEsc(emptyTxt) + '</div>');
        }
        var actTone = function (a) {
            a = String(a || '').toLowerCase();
            return /delet|remov|cancel|void/.test(a) ? ['bad', 'fa-xmark'] : /upload|import|bulk/.test(a) ? ['warn', 'fa-file-arrow-up']
                : /updat|edit|chang|reset/.test(a) ? ['info', 'fa-pen'] : /add|creat|new|post|sent|send/.test(a) ? ['ok', 'fa-check'] : ['mute', 'fa-circle-dot'];
        };
        var NOTIF_TONE = { policy_expiry: ['warn', 'fa-hourglass-half'], payment_pending: ['bad', 'fa-money-bill-wave'], commission_update: ['ok', 'fa-coins'], general: ['info', 'fa-bell'] };

        function renderFocus(d, key, tiles, feed, feedEmpty, mine) {
            $('#dxPeriod').text(rangeLabels[key] || 'Period');
            dxHero(d, d.focus, mine);
            $('#dxTiles').html(tiles.join(''));
            dxAttn(d.focus.attn || [], d.focus.attnCount, d.focus.later);
            $('#dxDuesPill').prop('hidden', !d.focus.duesCount).text(cur(d.focus.duesAmt) + ' customer dues');
            dxFeed(feed, feedEmpty);
        }

        // sliders button -> open the period picker (unfolds the phone filter panel first)
        function dxPickRange() {
            var sel = document.getElementById('rangeSelect') || document.getElementById('agRangeSelect');
            if (!sel) return;
            var fold = document.querySelector('.hf-toggle');
            if (fold && fold.offsetParent && fold.getAttribute('aria-expanded') !== 'true') fold.click();
            var box = sel.closest('.hf-field') || sel.parentNode, inp = box.querySelector('.searchable-dropdown-input');
            (inp || sel).scrollIntoView({ block: 'center', behavior: 'smooth' });
            setTimeout(function () { if (inp) { inp.focus(); inp.click(); } }, 250);
        }

        // chart registry (destroy before re-render on range change)
        var charts = {};

        // build/rebuild a chart on a canvas id; shows inline "no data" if empty
        function mkChart(id, hasData, cfg) {
            var canvas = document.getElementById(id);
            if (!canvas) return;
            var box = canvas.parentNode;
            // drop any prior "no data" note
            var note = box.querySelector('.chart-nodata');
            if (note) note.remove();
            if (charts[id]) { charts[id].destroy(); delete charts[id]; }
            if (!hasData) {
                canvas.style.display = 'none';
                var div = document.createElement('div');
                div.className = 'chart-nodata';
                div.style.cssText = 'display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:var(--text-muted)';
                div.innerHTML = '<i class="fas fa-chart-simple" style="font-size:34px;margin-bottom:10px;opacity:.4"></i><span>No data for this period</span>';
                box.appendChild(div);
                return;
            }
            canvas.style.display = '';
            charts[id] = new Chart(canvas, cfg);
        }

        <?php if ($role === 'admin' || $role === 'manager'): ?>
            // admin/manager dashboard
            function currentRangeParams() {
                var r = $('#rangeSelect').val();
                var p = 'range=' + r;
                if (r === 'custom') p += '&from=' + ($('#rangeFrom').val() || '') + '&to=' + ($('#rangeTo').val() || '');
                return p;
            }

            function loadDashboardStats() {
                $('#adminSkeleton').show();
                $('#adminContent').hide();
                $.ajax({
                    url: '?action=getDashboardStats&' + currentRangeParams(),
                    method: 'GET',
                    dataType: 'json',
                    success: function (res) {
                        if (!res.success) {
                            Swal.fire('Error', res.message || 'Failed to load stats', 'error');
                            return;
                        }
                        var d = res.data;

                        var key = $('#rangeSelect').val(), p = d.prev || {};
                        renderFocus(d, key, [
                            dxTile('Policies booked', 'fa-file-signature', 'info', fmt(d.rangePolicyCount), '', dxDelta(d.rangePolicyCount, d.prev ? p.count : null, key)),
                            dxMoney('Premium booked', 'fa-sack-dollar', 'ok', d.totalPremium, dxDelta(d.totalPremium, d.prev ? p.premium : null, key, true)),
                            dxExpiring(d),
                            dxMoney('Net margin', 'fa-scale-balanced', d.netMargin < 0 ? 'bad' : 'violet', d.netMargin, dxDelta(d.netMargin, d.prev ? p.netMargin : null, key, true))
                        ], (d.recentActivity || []).map(function (r) {
                            var t = actTone(r.action);
                            return { tone: t[0], icon: t[1], title: r.action, sub: [r.details, r.username].filter(Boolean).join(' · '), at: r.timestamp };
                        }), 'No recent activity');

                        // monthly premium line chart
                        renderMonthlyPremiumChart(d.monthlyPremium);

                        // policy status doughnut
                        renderPolicyStatusChart(d.policyByStatus);

                        // insurance verticals doughnut
                        renderCategoryDistChart(d.categoryDistribution, 'chartCategoryDist');

                        // vehicle type pie
                        renderVehicleTypeChart(d.vehicleTypeDistribution);

                        // branch premium bar
                        renderBranchPremiumChart(d.branchWisePremium);

                        // top agents table
                        renderTopAgents(d.topAgents);

                        // top buyers table
                        renderTopBuyers(d.topBuyers);

                        // monthly expenses bar
                        renderMonthlyExpensesChart(d.monthlyExpenses);

                        // recent policies table
                        renderRecentPolicies(d.recentPolicies, '#tblRecentPolicies', false);

                        // show content
                        $('#adminSkeleton').hide();
                        $('#adminContent').show();
                    },
                    error: function (x) {
                        // show what the server really sent (php error, login page…)
                        var t = $('<div>').html(x.responseText || '').text().replace(/\s+/g, ' ').trim().slice(0, 300);
                        Swal.fire('Error', x.status ? ('Server error ' + x.status + (t ? ': ' + t : '')) : (t || 'Failed to connect to server'), 'error');
                    }
                });
            }

            function renderMonthlyPremiumChart(data) {
                mkChart('chartMonthlyPremium', !!(data && data.length), {
                    type: 'line',
                    data: {
                        labels: (data || []).map(function (r) { return r.month; }),
                        datasets: [
                            {
                                label: 'Premium',
                                data: (data || []).map(function (r) { return r.premium; }),
                                borderColor: cNavy,
                                backgroundColor: 'rgba(0,31,63,0.1)',
                                fill: true,
                                tension: 0.4,
                                pointRadius: 4,
                                pointHoverRadius: 6
                            },
                            {
                                label: 'Buyer Commission',
                                data: (data || []).map(function (r) { return r.buyerComm; }),
                                borderColor: cBlue,
                                backgroundColor: 'rgba(0,116,217,0.1)',
                                fill: false,
                                tension: 0.4,
                                pointRadius: 3
                            },
                            {
                                label: 'Agent Commission',
                                data: (data || []).map(function (r) { return r.agentComm; }),
                                borderColor: cGreen,
                                backgroundColor: 'rgba(52,168,83,0.1)',
                                fill: false,
                                tension: 0.4,
                                pointRadius: 3
                            }
                        ]
                    },
                    options: {
                        plugins: { tooltip: currencyTooltip, legend: { position: 'bottom' } },
                        scales: {
                            y: { beginAtZero: true, ticks: { callback: function (v) { return cur(v); } } }
                        }
                    }
                });
            }

            function renderPolicyStatusChart(data) {
                var hasData = data && ((data.active || 0) + (data.expired || 0) + (data.cancelled || 0)) > 0;
                mkChart('chartPolicyStatus', hasData, {
                    type: 'doughnut',
                    data: {
                        labels: ['Active', 'Expired', 'Cancelled'],
                        datasets: [{
                            data: [(data && data.active) || 0, (data && data.expired) || 0, (data && data.cancelled) || 0],
                            backgroundColor: [cGreen, cYellow, cRed],
                            borderWidth: 2,
                            borderColor: 'var(--bg-card)'
                        }]
                    },
                    options: {
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: { callbacks: { label: function (ctx) { return ctx.label + ': ' + ctx.parsed + ' policies'; } } }
                        },
                        cutout: '60%'
                    }
                });
            }

            function renderVehicleTypeChart(data) {
                var total = (data || []).reduce(function (s, r) { return s + r.count; }, 0);
                var colors = ['#0074D9', '#34a853', '#fbbc04', '#ea4335', '#6c757d', '#7b2ff7', '#ff6f61'];
                mkChart('chartVehicleType', !!(data && data.length), {
                    type: 'doughnut',
                    data: {
                        labels: (data || []).map(function (r) { return r.type || 'Unknown'; }),
                        datasets: [{
                            data: (data || []).map(function (r) { return r.count; }),
                            backgroundColor: colors.slice(0, (data || []).length),
                            hoverBackgroundColor: colors.slice(0, (data || []).length).map(function (c) { return c + 'dd'; }),
                            borderWidth: 3,
                            borderColor: '#ffffff',
                            hoverBorderColor: '#ffffff',
                            hoverOffset: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        cutout: '55%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: { padding: 15, usePointStyle: true, pointStyle: 'circle', font: { size: 13 } }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                                        return ctx.label + ': ' + ctx.parsed + ' policies (' + pct + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            function renderBranchPremiumChart(data) {
                mkChart('chartBranchPremium', !!(data && data.length), {
                    type: 'bar',
                    data: {
                        labels: (data || []).map(function (r) { return r.branch; }),
                        datasets: [{
                            label: 'Premium',
                            data: (data || []).map(function (r) { return parseFloat(r.premium); }),
                            backgroundColor: chartColors.slice(0, (data || []).length),
                            borderRadius: 4
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        plugins: { tooltip: currencyTooltip, legend: { display: false } },
                        scales: {
                            x: { beginAtZero: true, ticks: { callback: function (v) { return cur(v); } } }
                        }
                    }
                });
            }

            function renderTopAgents(data) {
                var html = '';
                if (!data || !data.length) {
                    html = '<tr><td colspan="4" style="text-align:center;color:var(--text-muted)">No data available</td></tr>';
                } else {
                    data.forEach(function (r, i) {
                        html += '<tr><td class="rank">' + (i + 1) + '</td><td>' + escHtml(r.agent_name || '-') + '</td><td>' + fmt(r.policies) + '</td><td><span class="wallet-amount">' + cur(r.premium) + '</span></td></tr>';
                    });
                }
                $('#tblTopAgents').html(html);
            }

            function renderTopBuyers(data) {
                var html = '';
                if (!data || !data.length) {
                    html = '<tr><td colspan="4" style="text-align:center;color:var(--text-muted)">No data available</td></tr>';
                } else {
                    data.forEach(function (r, i) {
                        html += '<tr><td class="rank">' + (i + 1) + '</td><td>' + escHtml(r.company_name || '-') + '</td><td>' + fmt(r.policies) + '</td><td><span class="wallet-amount">' + cur(r.premium) + '</span></td></tr>';
                    });
                }
                $('#tblTopBuyers').html(html);
            }

            function renderMonthlyExpensesChart(data) {
                mkChart('chartMonthlyExpenses', !!(data && data.length), {
                    type: 'bar',
                    data: {
                        labels: (data || []).map(function (r) { return r.month; }),
                        datasets: [{
                            label: 'Expenses',
                            data: (data || []).map(function (r) { return r.total; }),
                            backgroundColor: cRed,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        plugins: { tooltip: currencyTooltip, legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { callback: function (v) { return cur(v); } } }
                        }
                    }
                });
            }

            function renderRecentPolicies(data, target, showCompany) {
                var html = '';
                if (!data || !data.length) {
                    var cols = showCompany ? 7 : 6;
                    html = '<tr><td colspan="' + cols + '" style="text-align:center;color:var(--text-muted)">No recent policies</td></tr>';
                } else {
                    data.forEach(function (r) {
                        var cat = String(r.insurance_category || 'motor').toLowerCase();
                        var catBadge = '<span class="chip-badge chip-blue">Motor</span>';
                        if (cat === 'health') catBadge = '<span class="chip-badge chip-emerald">Health</span>';
                        else if (cat === 'life') catBadge = '<span class="chip-badge chip-purple">Life</span>';

                        var planVeh = (cat === 'health' || cat === 'life') ? (r.plan_name || (cat === 'health' ? 'Health Plan' : 'Life Plan')) : (r.vehicle_number || '-');

                        html += '<tr>';
                        html += '<td>' + fmtDate(r.entry_date) + '</td>';
                        html += '<td>' + catBadge + '</td>';
                        html += '<td><span class="badge-code">' + escHtml(r.policy_number || '-') + '</span><br><small style="color:var(--text-muted)">' + escHtml(planVeh) + '</small></td>';
                        html += '<td>' + escHtml(r.customer_name || '-') + '</td>';
                        if (showCompany) html += '<td>' + escHtml(r.company_name || '-') + '</td>';
                        html += '<td><span class="wallet-amount">' + cur(r.total_premium_amount) + '</span></td>';
                        html += '<td>' + statusBadge(r.status) + '</td>';
                        html += '</tr>';
                    });
                }
                $(target).html(html);
            }

            function renderCategoryDistChart(data, canvasId) {
                var id = canvasId || 'chartCategoryDist';
                var total = (data || []).reduce(function (s, r) { return s + r.count; }, 0);
                var catNames = { motor: 'Motor', health: 'Health', life: 'Life' };
                var catColors = { motor: '#0074D9', health: '#10b981', life: '#8b5cf6' };
                mkChart(id, !!(data && data.length), {
                    type: 'doughnut',
                    data: {
                        labels: (data || []).map(function (r) { return catNames[r.category] || r.category; }),
                        datasets: [{
                            data: (data || []).map(function (r) { return r.count; }),
                            backgroundColor: (data || []).map(function (r) { return catColors[r.category] || '#6c757d'; }),
                            borderWidth: 2,
                            borderColor: 'var(--bg-card)'
                        }]
                    },
                    options: {
                        responsive: true,
                        cutout: '55%',
                        plugins: {
                            legend: { position: 'bottom', labels: { padding: 12, usePointStyle: true, pointStyle: 'circle' } },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var item = data[ctx.dataIndex] || {};
                                        var pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                                        return ctx.label + ': ' + ctx.parsed + ' policies (' + pct + '%) · ' + cur(item.premium || 0);
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // escape html
            function escHtml(s) {
                if (!s) return '';
                var d = document.createElement('div');
                d.textContent = s;
                return d.innerHTML;
            }

            // range selector wiring
            $('#rangeSelect').on('change', function () {
                var custom = $(this).val() === 'custom';
                $('#rangeFromWrap').toggle(custom);
                if (!custom) loadDashboardStats();
            });
            $('#rangeApply').on('click', function () {
                if ($('#rangeSelect').val() === 'custom' && (!$('#rangeFrom').val() || !$('#rangeTo').val())) {
                    Swal.fire('Pick dates', 'Choose both From and To dates for a custom range.', 'info');
                    return;
                }
                loadDashboardStats();
            });

            initSearchableDropdown(document.getElementById('rangeSelect'));
            document.getElementById('rangeSelect').sdRefresh(); // show preset
            loadDashboardStats();
        <?php endif; ?>

        <?php if ($role === 'agent'): ?>
            // agent dashboard
            function agRangeParams() {
                var r = $('#agRangeSelect').val();
                var p = 'range=' + r;
                if (r === 'custom') p += '&from=' + ($('#agRangeFrom').val() || '') + '&to=' + ($('#agRangeTo').val() || '');
                return p;
            }

            function loadAgentStats() {
                $('#agentSkeleton').show();
                $('#agentContent').hide();
                $.ajax({
                    url: '?action=getAgentStats&' + agRangeParams(),
                    method: 'GET',
                    dataType: 'json',
                    success: function (res) {
                        if (!res.success) {
                            Swal.fire('Error', res.message || 'Failed to load stats', 'error');
                            return;
                        }
                        var d = res.data;

                        var key = $('#agRangeSelect').val(), p = d.prev || {};
                        renderFocus(d, key, [
                            dxTile('Policies booked', 'fa-file-signature', 'info', fmt(d.rangePolicyCount), '', dxDelta(d.rangePolicyCount, d.prev ? p.count : null, key)),
                            dxMoney('My commission', 'fa-coins', 'ok', d.totalCommission, dxDelta(d.totalCommission, d.prev ? p.commission : null, key, true)),
                            dxExpiring(d),
                            dxMoney('Wallet balance', 'fa-wallet', d.walletBalance < 0 ? 'bad' : 'violet', d.walletBalance,
                                d.outstandingBalance ? '<b>' + rsEsc(curShort(d.outstandingBalance).join('')) + '</b> outstanding' : '<span>Nothing outstanding</span>')
                        ], (d.feed || []).map(function (n) {
                            var t = NOTIF_TONE[n.type] || NOTIF_TONE.general;
                            return { tone: t[0], icon: t[1], title: n.title, sub: n.message, at: n.created_at };
                        }), 'No notifications yet', true);

                        if (d.subscription) {
                            var sub = d.subscription;
                            $('#agSubPlanBadge').text(sub.plan_name + ' (' + (sub.billing_cycle || 'monthly') + ')');
                            $('#agSubDaysLeft').html('<i class="fas fa-clock"></i> ' + (sub.days_left || 0) + ' days remaining');
                            $('#agCustUsage').text((sub.actual_customer_count || 0) + ' / ' + (sub.max_customers > 99999 ? 'Unlimited' : sub.max_customers));
                            $('#agPolUsage').text((sub.actual_policy_count || 0) + ' / ' + (sub.max_policies > 99999 ? 'Unlimited' : sub.max_policies));
                            $('#agAiUsage').text((sub.ai_scan_count || 0) + ' / ' + sub.monthly_ai_scans);
                            $('#agWaUsage').text((sub.whatsapp_count || 0) + ' / ' + sub.whatsapp_limit);
                        }

                        // monthly commission line chart
                        renderAgentCommissionChart(d.monthlyCommission);

                        // insurance verticals doughnut
                        renderCategoryDistChart(d.categoryDistribution, 'chartAgentCategory');

                        // policy status doughnut
                        renderAgentStatusChart(d.policyByStatus);

                        // recent policies table
                        renderAgentRecentPolicies(d.recentPolicies);

                        // show content
                        $('#agentSkeleton').hide();
                        $('#agentContent').show();
                    },
                    error: function (x) {
                        // show what the server really sent (php error, login page…)
                        var t = $('<div>').html(x.responseText || '').text().replace(/\s+/g, ' ').trim().slice(0, 300);
                        Swal.fire('Error', x.status ? ('Server error ' + x.status + (t ? ': ' + t : '')) : (t || 'Failed to connect to server'), 'error');
                    }
                });
            }

            function renderAgentCommissionChart(data) {
                mkChart('chartAgentCommission', !!(data && data.length), {
                    type: 'line',
                    data: {
                        labels: (data || []).map(function (r) { return r.month; }),
                        datasets: [{
                            label: 'Commission',
                            data: (data || []).map(function (r) { return r.commission; }),
                            borderColor: cBlue,
                            backgroundColor: 'rgba(0,116,217,0.15)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 5,
                            pointHoverRadius: 7,
                            pointBackgroundColor: cBlue
                        }]
                    },
                    options: {
                        plugins: { tooltip: currencyTooltip, legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { callback: function (v) { return cur(v); } } }
                        }
                    }
                });
            }

            function renderAgentStatusChart(data) {
                data = data || {};
                var labels = [], values = [], colors = [];
                if (data.active) { labels.push('Active'); values.push(data.active); colors.push(cGreen); }
                if (data.expired) { labels.push('Expired'); values.push(data.expired); colors.push(cYellow); }
                if (data.cancelled) { labels.push('Cancelled'); values.push(data.cancelled); colors.push(cRed); }

                mkChart('chartAgentStatus', values.length > 0, {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: values,
                            backgroundColor: colors,
                            borderWidth: 2,
                            borderColor: 'var(--bg-card)'
                        }]
                    },
                    options: {
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: { callbacks: { label: function (ctx) { return ctx.label + ': ' + ctx.parsed + ' policies'; } } }
                        },
                        cutout: '60%'
                    }
                });
            }

            function renderAgentRecentPolicies(data) {
                renderRecentPolicies(data, '#tblAgentRecent', true);
            }

            // escape html (agent scope)
            function escHtml(s) {
                if (!s) return '';
                var d = document.createElement('div');
                d.textContent = s;
                return d.innerHTML;
            }

            // range selector wiring
            $('#agRangeSelect').on('change', function () {
                var custom = $(this).val() === 'custom';
                $('#agRangeFromWrap').toggle(custom);
                if (!custom) loadAgentStats();
            });
            $('#agRangeApply').on('click', function () {
                if ($('#agRangeSelect').val() === 'custom' && (!$('#agRangeFrom').val() || !$('#agRangeTo').val())) {
                    Swal.fire('Pick dates', 'Choose both From and To dates for a custom range.', 'info');
                    return;
                }
                loadAgentStats();
            });

            initSearchableDropdown(document.getElementById('agRangeSelect'));
            document.getElementById('agRangeSelect').sdRefresh(); // show preset
            loadAgentStats();
        <?php endif; ?>

        <?php if ($role === 'customer'): ?>
            function loadCustomerStats() {
                $('#custPortalSkeleton').show();
                $('#custPortalContent').hide();
                $.ajax({
                    url: '?action=getCustomerStats',
                    method: 'GET',
                    dataType: 'json',
                    success: function (res) {
                        if (!res.success) {
                            $('#custPortalSkeleton').hide();
                            Swal.fire('Error', res.message || 'Failed to load portal stats', 'error');
                            return;
                        }
                        var d = res.data;
                        var prof = d.profile || {};
                        var st = d.stats || {};
                        var clm = d.claims || {};
                        var ag = d.agent || null;

                        // Welcome info
                        $('#custWelcomeName').text('Welcome, ' + (prof.name || 'Valued Customer'));
                        var famCount = (d.familyMembers || []).length;
                        $('#custSubDesc').text('Protecting ' + (prof.name || 'You') + (famCount > 1 ? ' and ' + (famCount - 1) + ' family member(s).' : '.'));

                        // Agent info
                        if (ag && ag.name) {
                            $('#custAgentName').text(ag.name);
                            $('#custAgentCode').text('Agent Code: ' + (ag.code || 'Advisor'));
                            if (ag.mobile) {
                                $('#custAgentCallBtn').attr('href', 'tel:' + ag.mobile);
                                var cleanMob = String(ag.mobile).replace(/[^0-9]/g, '');
                                if (cleanMob.length === 10) cleanMob = '91' + cleanMob;
                                $('#custAgentWaBtn').attr('href', 'https://wa.me/' + cleanMob + '?text=' + encodeURIComponent('Hello ' + ag.name + ', I need help with my insurance policy.')).show();
                            } else {
                                $('#custAgentCallBtn').hide();
                                $('#custAgentWaBtn').hide();
                            }
                            $('#custAgentCard').show();
                        } else {
                            $('#custAgentName').text('Agency Customer Care');
                            $('#custAgentCode').text('Support');
                            $('#custAgentCallBtn').attr('href', 'tel:8160140848');
                            $('#custAgentWaBtn').attr('href', 'https://wa.me/918160140848?text=Hello%20Support').show();
                        }

                        // Summary tiles
                        $('#tileActivePol').text(st.active_policies || 0);
                        $('#tileTotalPolSub').text((st.total_policies || 0) + ' total policies enrolled');
                        $('#tileMotorCount').text(st.motor_active || 0);

                        var healthAmt = Number(st.health_sum_insured || 0);
                        $('#tileHealthSI').text('₹' + healthAmt.toLocaleString('en-IN', { maximumFractionDigits: 0 }));
                        $('#tileHealthSub').text((st.health_active || 0) + ' active Health policy(ies)');

                        var lifeAmt = Number(st.life_sum_assured || 0);
                        $('#tileLifeSA').text('₹' + lifeAmt.toLocaleString('en-IN', { maximumFractionDigits: 0 }));
                        $('#tileLifeSub').text((st.life_active || 0) + ' active Life policy(ies)');

                        // Expiring alert
                        if (Number(st.expiring_soon || 0) > 0) {
                            $('#custExpiringText').text('You have ' + st.expiring_soon + ' policy(ies) expiring within the next 30 days.');
                            $('#custExpiringAlert').css('display', 'flex');
                        } else {
                            $('#custExpiringAlert').hide();
                        }

                        // Recent policies table
                        var pols = d.recentPolicies || [];
                        if (pols.length === 0) {
                            $('#custPoliciesList').html('<tr><td colspan="7" class="text-center text-muted" style="padding:20px;">No policies found. Contact your agent to enroll.</td></tr>');
                        } else {
                            var h = '';
                            pols.forEach(function (p) {
                                var cat = (p.insurance_category || 'motor').toLowerCase();
                                var chip = cat === 'health' ? 'chip-emerald' : (cat === 'life' ? 'chip-purple' : 'chip-blue');
                                var ico = cat === 'health' ? 'fa-heart-pulse' : (cat === 'life' ? 'fa-user-shield' : 'fa-car');
                                var catLbl = cat === 'health' ? 'Health' : (cat === 'life' ? 'Life' : 'Motor');

                                var badge = p.is_personal ? '<span class="status-badge status-active" style="font-size:10px;padding:2px 6px;">Self</span>' : '<span class="status-badge status-user" style="font-size:10px;padding:2px 6px;">Family</span>';

                                var desc = p.vehicle_number || p.plan_name || (p.life_plan_type ? p.life_plan_type.toUpperCase() : '-');
                                var stCls = p.status === 'active' ? 'status-active' : (p.status === 'expired' ? 'status-cancelled' : 'status-pending');

                                h += '<tr>';
                                h += '<td><strong>' + rsEsc(p.customer_name) + '</strong> ' + badge + '</td>';
                                h += '<td><span class="status-badge ' + chip + '" style="font-size:11px;"><i class="fas ' + ico + '"></i> ' + catLbl + '</span></td>';
                                h += '<td><span class="badge-code">' + rsEsc(p.policy_number) + '</span><br><small class="text-muted">' + rsEsc(desc) + '</small></td>';
                                h += '<td>' + rsEsc(p.company_name) + '</td>';
                                h += '<td>' + rsEsc(p.policy_end_date) + '</td>';
                                h += '<td><span class="policy-status ' + stCls + '">' + rsEsc(p.status.toUpperCase()) + '</span></td>';
                                h += '<td style="text-align:right;">';
                                h += '<a href="policy_entries.php" class="action-icon view-icon" title="View"><i class="fas fa-eye"></i></a> ';
                                if (p.status === 'active') {
                                    h += '<a href="claims.php?new=1&policy_id=' + p.id + '" class="action-icon" style="color:#ef4444;" title="Claim"><i class="fas fa-car-burst"></i></a>';
                                }
                                h += '</td>';
                                h += '</tr>';
                            });
                            $('#custPoliciesList').html(h);
                        }

                        // Family group list
                        var fam = d.familyMembers || [];
                        if (fam.length === 0) {
                            $('#custFamilyList').html('<div class="text-muted text-center" style="padding:15px;">No family members linked yet.</div>');
                        } else {
                            var fh = '';
                            fam.forEach(function (m) {
                                var rel = m.family_relation || 'Self';
                                var isSelf = (m.id == prof.id);
                                fh += '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px;border-bottom:1px solid var(--border-color);">';
                                fh += '<div style="display:flex;align-items:center;gap:10px;">';
                                fh += '<div style="width:36px;height:36px;border-radius:50%;background:rgba(30,58,138,0.1);display:flex;align-items:center;justify-content:center;color:var(--navy-primary);font-weight:bold;font-size:13px;">' + rsEsc(m.name.charAt(0)) + '</div>';
                                fh += '<div><strong style="font-size:13px;">' + rsEsc(m.name) + '</strong> ' + (isSelf ? '<span class="status-badge status-active" style="font-size:9px;padding:1px 5px;">Head</span>' : '') + '<br><small class="text-muted">' + rsEsc(rel) + (m.mobile ? ' · ' + rsEsc(m.mobile) : '') + '</small></div>';
                                fh += '</div>';
                                fh += '<span class="status-badge status-user" style="font-size:11px;">' + (m.active_policies || 0) + ' policy(ies)</span>';
                                fh += '</div>';
                            });
                            $('#custFamilyList').html(fh);
                        }

                        // Claims list
                        var claims = d.recentClaims || [];
                        if (claims.length === 0) {
                            $('#custClaimsList').html('<tr><td colspan="7" class="text-center text-muted" style="padding:15px;">No claims recorded.</td></tr>');
                        } else {
                            var ch = '';
                            claims.forEach(function (c) {
                                var amt = '₹' + Number(c.amount_claimed || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
                                var cStCls = c.status === 'settled' ? 'status-active' : (c.status === 'rejected' ? 'status-cancelled' : 'status-pending');
                                ch += '<tr>';
                                ch += '<td><span class="badge-code">' + rsEsc(c.claim_number) + '</span></td>';
                                ch += '<td>' + rsEsc(c.policy_number) + '</td>';
                                ch += '<td>' + rsEsc(c.customer_name) + '</td>';
                                ch += '<td>' + rsEsc(c.claim_type || c.life_claim_type || '-') + '</td>';
                                ch += '<td><strong>' + amt + '</strong></td>';
                                ch += '<td><span class="policy-status ' + cStCls + '">' + rsEsc(c.status.toUpperCase()) + '</span></td>';
                                ch += '<td>' + rsEsc(c.intimation_date) + '</td>';
                                ch += '</tr>';
                            });
                            $('#custClaimsList').html(ch);
                        }

                        $('#custPortalSkeleton').hide();
                        $('#custPortalContent').show();
                    },
                    error: function () {
                        $('#custPortalSkeleton').hide();
                        Swal.fire('Error', 'Connection error while loading customer portal', 'error');
                    }
                });
            }

            $(document).ready(function () {
                loadCustomerStats();
            });
        <?php endif; ?>
    </script>
</body>

</html>