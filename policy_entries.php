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

// auth check
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (!checkSessionTimeout()) { header("Location: login.php"); exit(); }

$username = $_SESSION['username'];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$current_page = 'policies';

$isAdmin = ($role === 'admin');
$isManager = ($role === 'manager');
$isAgent = ($role === 'agent');
$isCustomer = ($role === 'customer');
$canCreate = ($isAdmin || $isManager || $isAgent);
$canEdit = ($isAdmin || $isAgent);
$canDelete = $isAdmin;

// commission calc - single source of truth for server side
function computeCommission($basis, $rate, $total, $tp, $od, $net) {
    switch ($basis) {
        case 'premium_pct': return round($total * $rate / 100, 2);
        case 'tp_pct': return round($tp * $rate / 100, 2);
        case 'od_pct': return round($od * $rate / 100, 2);
        case 'net_pct': return round($net * $rate / 100, 2);
        case 'fixed': return round($rate, 2);
        default: return 0.00;
    }
}

// optional production cols -> [vals, err]; $src = POST or an import row
function policyExtras($conn, array $src, $pid, $status, $total, $net) {
    $insCat = trim($src['insurance_category'] ?? 'motor');
    if (!in_array($insCat, ['motor', 'health', 'life'], true)) $insCat = 'motor';

    $pt = trim($src['policy_type'] ?? '');
    $bt = trim($src['business_type'] ?? '');
    if ($pt !== '' && !in_array($pt, ['comprehensive', 'third_party', 'own_damage'], true)) return [null, 'Invalid policy type'];
    if ($bt !== '' && !in_array($bt, ['new', 'renewal', 'rollover'], true)) return [null, 'Invalid business type'];
    $num = function($k, $max) use ($src) { // '' -> null, else 0..max
        $v = trim((string)($src[$k] ?? ''));
        if ($v === '') return null;
        return (is_numeric($v) && (float)$v >= 0 && (float)$v <= $max) ? round((float)$v, 2) : false;
    };
    $idv = $num('idv', 9999999999.99); $gst = $num('gst_amount', 9999999999.99); $ncb = $num('ncb_percent', 100);
    if ($idv === false) return [null, 'IDV must be a positive amount'];
    if ($gst === false) return [null, 'GST amount cannot be negative'];
    if ($ncb === false) return [null, 'NCB % must be between 0 and 100'];
    if ($gst === null) $gst = max(0, round($total - $net, 2)); // default = total - net
    $prop = trim($src['proposal_number'] ?? '');
    if (mb_strlen($prop) > 50) return [null, 'Proposal number max 50 characters'];
    $prev = (int)($src['previous_policy_id'] ?? 0) ?: null;
    if ($prev) {
        if ($prev === (int)$pid) return [null, 'A policy cannot renew itself'];
        $st = $conn->prepare("SELECT p.id, (SELECT r.policy_number FROM policy_entries r WHERE r.previous_policy_id = p.id AND r.id <> ? LIMIT 1) AS renewed_by FROM policy_entries p WHERE p.id = ?");
        $st->bind_param("ii", $pid, $prev);
        $st->execute();
        $pr = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$pr) return [null, 'Previous policy not found'];
        if ($pr['renewed_by']) return [null, 'That policy is already renewed by ' . $pr['renewed_by']];
        if ($bt === '') $bt = 'renewal';
        if ($bt === 'new') return [null, 'A new-business policy cannot be linked to a previous policy'];
    }
    $reason = trim($src['cancellation_reason'] ?? '');
    if ($status === 'cancelled') {
        if ($reason === '') return [null, 'Cancellation reason is required'];
        if (mb_strlen($reason) > 255) return [null, 'Cancellation reason max 255 characters'];
    } else $reason = null; // back to active/expired -> clear

    // Health fields
    $sumInsured = $num('sum_insured', 9999999999.99);
    $planName = trim($src['plan_name'] ?? '') ?: null;
    $tpaName = trim($src['tpa_name'] ?? '') ?: null;
    $healthType = trim($src['health_policy_type'] ?? '') ?: null;
    $membersCount = (int)($src['members_count'] ?? 1);
    if ($membersCount < 1) $membersCount = 1;
    $membersDetails = trim($src['members_details'] ?? '') ?: null;
    $pedDetails = trim($src['ped_details'] ?? '') ?: null;

    // Life fields
    $sumAssured = $num('sum_assured', 9999999999.99);
    $lifePlanType = trim($src['life_plan_type'] ?? '') ?: null;
    $policyTerm = !empty($src['policy_term']) ? (int)$src['policy_term'] : null;
    $ppt = !empty($src['premium_paying_term']) ? (int)$src['premium_paying_term'] : null;
    $payFreq = trim($src['payment_frequency'] ?? '') ?: null;
    $nomineeName = trim($src['nominee_name'] ?? '') ?: null;
    $nomineeRel = trim($src['nominee_relation'] ?? '') ?: null;
    $nomineeDob = trim($src['nominee_dob'] ?? '') ?: null;

    return [[
        'insurance_category' => $insCat,
        'policy_type' => $pt ?: null, 'business_type' => $bt ?: null, 'previous_policy_id' => $prev, 'proposal_number' => $prop ?: null,
        'idv' => $idv, 'gst_amount' => $gst, 'ncb_percent' => $ncb, 'cancellation_reason' => $reason,
        'sum_insured' => $sumInsured, 'plan_name' => $planName, 'tpa_name' => $tpaName,
        'health_policy_type' => $healthType, 'members_count' => $membersCount, 'members_details' => $membersDetails, 'ped_details' => $pedDetails,
        'sum_assured' => $sumAssured, 'life_plan_type' => $lifePlanType, 'policy_term' => $policyTerm, 'premium_paying_term' => $ppt,
        'payment_frequency' => $payFreq, 'nominee_name' => $nomineeName, 'nominee_relation' => $nomineeRel, 'nominee_dob' => $nomineeDob
    ], ''];
}

// write extras; cancelled_at stamped once on cancel, cleared otherwise; $updBy null on insert
function savePolicyExtras($conn, $id, array $x, $updBy) {
    $st = $conn->prepare("UPDATE policy_entries SET
        insurance_category=?, policy_type=?, business_type=?, previous_policy_id=?, proposal_number=?, idv=?, gst_amount=?, ncb_percent=?,
        sum_insured=?, plan_name=?, tpa_name=?, health_policy_type=?, members_count=?, members_details=?, ped_details=?,
        sum_assured=?, life_plan_type=?, policy_term=?, premium_paying_term=?, payment_frequency=?, nominee_name=?, nominee_relation=?, nominee_dob=?,
        cancellation_reason=?, cancelled_at = IF(status = 'cancelled', COALESCE(cancelled_at, NOW()), NULL), updated_by = COALESCE(?, updated_by) WHERE id=?");
    $st->bind_param("sssisdddsdssissssiisssssii",
        $x['insurance_category'], $x['policy_type'], $x['business_type'], $x['previous_policy_id'], $x['proposal_number'],
        $x['idv'], $x['gst_amount'], $x['ncb_percent'],
        $x['sum_insured'], $x['plan_name'], $x['tpa_name'], $x['health_policy_type'], $x['members_count'], $x['members_details'], $x['ped_details'],
        $x['sum_assured'], $x['life_plan_type'], $x['policy_term'], $x['premium_paying_term'], $x['payment_frequency'], $x['nominee_name'], $x['nominee_relation'], $x['nominee_dob'],
        $x['cancellation_reason'], $updBy, $id);
    $st->execute();
    $st->close();
}

// log text for the extras
function policyExtrasLog(array $x) {
    $p = array_filter(['type' => $x['policy_type'], 'business' => $x['business_type'], 'IDV' => $x['idv'], 'GST' => $x['gst_amount'],
        'NCB%' => $x['ncb_percent'], 'proposal' => $x['proposal_number'], 'cancel reason' => $x['cancellation_reason']], fn($v) => $v !== null && $v !== '');
    return $p ? ' [' . implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($p), $p)) . ']' : '';
}

// default company agent - safety net so no policy is ever agent-less
// returns agent id, creates the "Company Direct" record (+linked user) if missing
function ensureDefaultAgent($conn) {
    $code = 'DIRECT';
    $stmt = $conn->prepare("SELECT id FROM agents WHERE agent_code = ? LIMIT 1");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($r) return (int)$r['id'];

    // needs a linked user (agents.user_id is NOT NULL UNIQUE) - reuse a reserved one or make it
    $sysEmail = 'company.direct@system.local';
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $sysEmail);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($u) {
        $uid = (int)$u['id'];
    } else {
        // reserved system user - inactive, random phone, unusable pwd
        $sysName = 'Company Direct';
        $sysPhone = 'SYS' . substr(str_pad((string)mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT), 0, 9);
        $sysPwd = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $sysRole = 'agent';
        $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 0)");
        $stmt->bind_param("sssss", $sysName, $sysEmail, $sysPhone, $sysPwd, $sysRole);
        $stmt->execute();
        $uid = $stmt->insert_id;
        $stmt->close();
    }

    // create the agent row
    $aName = 'Company Direct';
    $aMobile = '0000000000';
    $stmt = $conn->prepare("INSERT INTO agents (user_id, branch_id, agent_code, name, mobile, is_active) VALUES (?, NULL, ?, ?, ?, 1)");
    $stmt->bind_param("isss", $uid, $code, $aName, $aMobile);
    $stmt->execute();
    $aid = $stmt->insert_id;
    $stmt->close();
    return (int)$aid;
}

// fire a "new policy" notification to all admins + the assigned agent's user
function notifyPolicyAdded($conn, $policyId, $policyNo, $agentId, $agentName) {
    $title = 'New Policy Added';
    $msg = "New policy $policyNo added for agent " . ($agentName ?: 'Company Direct');
    $type = 'general';

    $targets = [];
    // all active admins
    $res = $conn->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
    if ($res) { while ($r = $res->fetch_assoc()) $targets[(int)$r['id']] = true; }
    // assigned agent's linked user
    if ($agentId) {
        $stmt = $conn->prepare("SELECT user_id FROM agents WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $agentId);
        $stmt->execute();
        $a = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($a && $a['user_id']) $targets[(int)$a['user_id']] = true;
    }
    if (!$targets) return;

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, policy_entry_id, agent_id) VALUES (?, ?, ?, ?, ?, ?)");
    foreach (array_keys($targets) as $uid) {
        $stmt->bind_param("isssii", $uid, $type, $title, $msg, $policyId, $agentId);
        $stmt->execute();
    }
    $stmt->close();
}

// put a snapshot row back, bind types read off the live columns (columns dropped since are skipped)
function rowInsert($conn, $table, array $row) {
    static $meta = [];
    if (!isset($meta[$table])) {
        foreach ($conn->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC) as $c) {
            $t = strtolower($c['Type']);
            $meta[$table][$c['Field']] = preg_match('/^(tinyint|smallint|mediumint|int|bigint)/', $t) ? 'i' : (preg_match('/^(decimal|float|double)/', $t) ? 'd' : 's');
        }
    }
    $row = array_intersect_key($row, $meta[$table]);
    $cols = array_keys($row);
    $vals = array_values($row);
    $st = $conn->prepare("INSERT INTO `$table` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")");
    $st->bind_param(implode('', array_map(fn($c) => $meta[$table][$c], $cols)), ...$vals);
    $st->execute();
    $id = $st->insert_id;
    $st->close();
    return $id;
}

// rows of $sql for one policy id ($optional: table may not exist before the database update -> [])
function policyRows($conn, $sql, $pid, $optional = false) {
    try {
        $st = $conn->prepare($sql);
    } catch (mysqli_sql_exception $e) {
        if ($optional && (int)$e->getCode() === 1146) return [];
        throw $e;
    }
    $st->bind_param('i', $pid);
    $st->execute();
    $r = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $r;
}
function policyExec($conn, $sql, $pid) {
    $st = $conn->prepare($sql);
    $st->bind_param('i', $pid);
    $st->execute();
    $st->close();
}

// delete = move to Trash: snapshot the policy + its ledger lines, documents, endorsements and links, then remove it.
// files stay in the private folder until the trash entry is purged. returns ids to recalc, null = not found
function trashPolicy($conn, $pid, $uid) {
    $pid = (int)$pid;
    $pol = policyRows($conn, "SELECT * FROM policy_entries WHERE id = ?", $pid)[0] ?? null;
    if (!$pol) return null;
    $claims = (int)(policyRows($conn, "SELECT COUNT(*) AS n FROM claims WHERE policy_entry_id = ?", $pid, true)[0]['n'] ?? 0);
    if ($claims) throw new RuntimeException("Policy {$pol['policy_number']} has $claims claim(s) — delete the claims first");
    $ids = fn($sql) => array_map('intval', array_column(policyRows($conn, $sql, $pid), 'id'));
    $snap = [
        'policy' => $pol,
        'agent_tx' => policyRows($conn, "SELECT * FROM agent_transactions WHERE policy_entry_id = ?", $pid),
        'buyer_tx' => policyRows($conn, "SELECT * FROM buyer_transactions WHERE policy_entry_id = ?", $pid),
        'bank_tx' => policyRows($conn, "SELECT * FROM bank_transactions WHERE policy_entry_id = ?", $pid),
        'docs' => policyRows($conn, "SELECT * FROM policy_documents WHERE policy_entry_id = ?", $pid),
        'endorsements' => policyRows($conn, "SELECT * FROM policy_endorsements WHERE policy_entry_id = ?", $pid, true),
        'links' => [
            'renewals' => $ids("SELECT id FROM policy_entries WHERE previous_policy_id = ?"),
            'referrals' => $ids("SELECT id FROM referrals WHERE policy_entry_id = ?"),
            'point_transactions' => $ids("SELECT id FROM point_transactions WHERE policy_entry_id = ?"),
            'notifications' => $ids("SELECT id FROM notifications WHERE policy_entry_id = ?"),
        ],
    ];
    $json = json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    try {
        $st = $conn->prepare("INSERT INTO policy_trash (policy_entry_id, policy_number, customer_name, vehicle_number, company_name, total_premium_amount, snapshot, deleted_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    } catch (mysqli_sql_exception $e) {
        throw new RuntimeException((int)$e->getCode() === 1146 ? 'Run the database update (setup.php → Update) once, then delete again' : $e->getMessage());
    }
    $st->bind_param('issssdsi', $pid, $pol['policy_number'], $pol['customer_name'], $pol['vehicle_number'], $pol['company_name'], $pol['total_premium_amount'], $json, $uid);
    $st->execute();
    $st->close();

    // every party / account the policy touched — incl. both payment accounts
    $touch = ['agents' => [], 'buyers' => [], 'banks' => []];
    foreach ($snap['agent_tx'] as $r) $touch['agents'][(int)$r['agent_id']] = 1;
    foreach ($snap['buyer_tx'] as $r) $touch['buyers'][(int)$r['buyer_id']] = 1;
    foreach ($snap['bank_tx'] as $r) $touch['banks'][(int)$r['bank_account_id']] = 1;
    if ($pol['agent_id']) $touch['agents'][(int)$pol['agent_id']] = 1;
    if ($pol['buyer_id']) $touch['buyers'][(int)$pol['buyer_id']] = 1;
    foreach (['bank_account_id', 'payment_in_bank_account_id', 'payment_out_bank_account_id'] as $k) if (!empty($pol[$k])) $touch['banks'][(int)$pol[$k]] = 1;

    foreach (['DELETE FROM agent_transactions WHERE policy_entry_id = ?', 'DELETE FROM buyer_transactions WHERE policy_entry_id = ?', 'DELETE FROM bank_transactions WHERE policy_entry_id = ?',
              'UPDATE policy_entries SET previous_policy_id = NULL WHERE previous_policy_id = ?', 'DELETE FROM policy_entries WHERE id = ?'] as $sql) policyExec($conn, $sql, $pid); // documents + endorsements cascade
    return array_map('array_keys', $touch);
}

// Trash -> live again: same id when still free, ledger lines / documents / endorsements re-inserted, links restored
function restorePolicy($conn, $trashId) {
    $trashId = (int)$trashId;
    $t = policyRows($conn, "SELECT * FROM policy_trash WHERE id = ?", $trashId)[0] ?? null;
    if (!$t) throw new RuntimeException('That item is no longer in Trash');
    $s = json_decode($t['snapshot'], true);
    $pol = $s['policy'];
    $oldId = (int)$pol['id'];
    $st = $conn->prepare("SELECT 1 FROM policy_entries WHERE policy_number = ?");
    $st->bind_param('s', $pol['policy_number']);
    $st->execute();
    $taken = (bool)$st->get_result()->fetch_row();
    $st->close();
    if ($taken) throw new RuntimeException("Policy number {$pol['policy_number']} is used by another policy now — change that one first");
    $free = fn($table, $id) => !$conn->query("SELECT 1 FROM `$table` WHERE id = " . (int)$id)->fetch_row();
    $keepId = $free('policy_entries', $oldId);
    if (!$keepId) unset($pol['id']);
    try {
        $newId = $keepId ? $oldId : 0;
        $ins = rowInsert($conn, 'policy_entries', $pol);
        if (!$keepId) $newId = $ins;
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() === 1452) throw new RuntimeException('Cannot restore — its customer, vehicle, branch, buyer or agent was deleted');
        throw $e;
    }
    $move = $newId !== $oldId;
    foreach (['agent_tx' => 'agent_transactions', 'buyer_tx' => 'buyer_transactions', 'bank_tx' => 'bank_transactions', 'docs' => 'policy_documents', 'endorsements' => 'policy_endorsements'] as $k => $table) {
        foreach ($s[$k] ?? [] as $r) {
            $r['policy_entry_id'] = $newId;
            if (!$free($table, $r['id'])) unset($r['id']);
            if ($k === 'docs' && $move) { // folder follows the new id
                $dir = __DIR__ . "/uploads/policies/$newId";
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $to = "uploads/policies/$newId/" . basename($r['file_path']);
                if (@rename(__DIR__ . '/' . $r['file_path'], __DIR__ . '/' . $to)) $r['file_path'] = $to;
            }
            rowInsert($conn, $table, $r);
        }
    }
    $relink = function ($table, $col, $ids) use ($conn, $newId) {
        $ids = array_filter(array_map('intval', (array)$ids));
        if ($ids) $conn->query("UPDATE `$table` SET `$col` = $newId WHERE `$col` IS NULL AND id IN (" . implode(',', $ids) . ")");
    };
    $relink('policy_entries', 'previous_policy_id', $s['links']['renewals'] ?? []);
    foreach (['referrals', 'point_transactions', 'notifications'] as $table) $relink($table, 'policy_entry_id', $s['links'][$table] ?? []);
    policyExec($conn, "DELETE FROM policy_trash WHERE id = ?", $trashId);

    $touch = ['agents' => [], 'buyers' => [], 'banks' => []];
    foreach ($s['agent_tx'] ?? [] as $r) $touch['agents'][(int)$r['agent_id']] = 1;
    foreach ($s['buyer_tx'] ?? [] as $r) $touch['buyers'][(int)$r['buyer_id']] = 1;
    foreach ($s['bank_tx'] ?? [] as $r) $touch['banks'][(int)$r['bank_account_id']] = 1;
    return ['id' => $newId, 'number' => $pol['policy_number']] + array_map('array_keys', $touch);
}

// gone for good: the trash row + the files it still holds
function purgeTrashItem($conn, $trashId) {
    $trashId = (int)$trashId;
    $t = policyRows($conn, "SELECT policy_number, snapshot FROM policy_trash WHERE id = ?", $trashId)[0] ?? null;
    if (!$t) return null;
    $base = realpath(__DIR__ . '/uploads/policies');
    foreach (json_decode($t['snapshot'], true)['docs'] ?? [] as $d) {
        $f = realpath(__DIR__ . '/' . $d['file_path']);
        if ($f && $base && strpos($f, $base . DIRECTORY_SEPARATOR) === 0 && is_file($f)) {
            @unlink($f);
            @rmdir(dirname($f)); // only when empty
        }
    }
    policyExec($conn, "DELETE FROM policy_trash WHERE id = ?", $trashId);
    return $t['policy_number'];
}

// AJAX handlers
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($_GET['action']) {

            case 'getDropdowns':
                $out = [];

                $stmt = $conn->prepare("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name");
                $stmt->execute();
                $res = $stmt->get_result();
                $out['branches'] = [];
                while ($r = $res->fetch_assoc()) $out['branches'][] = $r;
                $stmt->close();

                if ($isAgent) {
                    $myAgentId = agentIdForUser($conn, $user_id) ?? 0;
                    $stmt = $conn->prepare("SELECT a.id, a.agent_code, a.name FROM agents a WHERE a.id = ?");
                    $stmt->bind_param("i", $myAgentId);
                } else {
                    $stmt = $conn->prepare("SELECT a.id, a.agent_code, a.name FROM agents a WHERE a.is_active=1 ORDER BY a.name");
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $out['agents'] = [];
                while ($r = $res->fetch_assoc()) $out['agents'][] = $r;
                $stmt->close();

                $stmt = $conn->prepare("SELECT id, company_name, company_code FROM buyers WHERE is_active=1 ORDER BY company_name");
                $stmt->execute();
                $res = $stmt->get_result();
                $out['buyers'] = [];
                while ($r = $res->fetch_assoc()) $out['buyers'][] = $r;
                $stmt->close();

                if ($isAgent) {
                    $myAgentId = agentIdForUser($conn, $user_id) ?? 0;
                    $stmt = $conn->prepare("SELECT id, name, mobile FROM customers WHERE is_active=1 AND (agent_id = ? OR agent_id IS NULL) ORDER BY name");
                    $stmt->bind_param("i", $myAgentId);
                } else {
                    $stmt = $conn->prepare("SELECT id, name, mobile FROM customers WHERE is_active=1 ORDER BY name");
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $out['customers'] = [];
                while ($r = $res->fetch_assoc()) $out['customers'][] = $r;
                $stmt->close();

                $stmt = $conn->prepare("SELECT id, vehicle_number, vehicle_type, make, model, customer_id FROM vehicles ORDER BY vehicle_number");
                $stmt->execute();
                $res = $stmt->get_result();
                $out['vehicles'] = [];
                while ($r = $res->fetch_assoc()) $out['vehicles'][] = $r;
                $stmt->close();

                $stmt = $conn->prepare("SELECT id, bank_name, account_number FROM bank_accounts WHERE is_active=1 ORDER BY bank_name");
                $stmt->execute();
                $res = $stmt->get_result();
                $out['bankAccounts'] = [];
                while ($r = $res->fetch_assoc()) $out['bankAccounts'][] = $r;
                $stmt->close();

                $conn->close();
                echo json_encode(['success' => true, 'data' => $out]);
                exit();

            case 'getPolicies':
                $sql = "SELECT pe.id, pe.insurance_category, pe.entry_date, pe.policy_number, pe.vehicle_number, pe.vehicle_type,
                               pe.customer_id, pe.customer_name, pe.company_name, pe.company_code,
                               pe.branch_id, br.name as branch_name,
                               pe.total_premium_amount, pe.tp_amount, pe.od_amount, pe.net_premium_amount,
                               pe.buyer_commission_basis, pe.buyer_commission_rate, pe.buyer_commission_amount,
                               pe.agent_id, pe.agent_name, pe.agent_commission_basis, pe.agent_commission_rate, pe.agent_commission_amount,
                               pe.vehicle_id, pe.payment_mode, pe.bank_name, pe.payment_in, pe.payment_out,
                               pe.status, pe.remarks, pe.policy_start_date, pe.policy_end_date,
                               pe.policy_type, pe.business_type, pe.proposal_number, pe.idv, pe.gst_amount, pe.ncb_percent, pe.cancellation_reason,
                               pe.sum_insured, pe.plan_name, pe.tpa_name, pe.health_policy_type, pe.members_count, pe.members_details, pe.ped_details,
                               pe.sum_assured, pe.life_plan_type, pe.policy_term, pe.premium_paying_term, pe.payment_frequency, pe.nominee_name, pe.nominee_relation, pe.nominee_dob,
                               DATE_FORMAT(pe.created_at, '%b %d, %Y') as created_at
                        FROM policy_entries pe
                        LEFT JOIN branches br ON pe.branch_id = br.id";

                // drill-down filters from other pages
                $fCustomerId = (int)($_GET['customer_id'] ?? 0);
                $fVehicleId = (int)($_GET['vehicle_id'] ?? 0);
                $fAgentId = (int)($_GET['agent_id'] ?? 0);

                $where = [];
                $types = '';
                $params = [];
                if ($isAgent) {
                    $where[] = "pe.agent_id = (SELECT a.id FROM agents a WHERE a.user_id = ?)";
                    $types .= 'i';
                    $params[] = $user_id;
                } elseif ($isCustomer) {
                    $cid = customerIdForUser($conn, $user_id);
                    if (!$cid && !empty($_SESSION['customer_id'])) $cid = (int)$_SESSION['customer_id'];
                    $famIds = $cid ? getFamilyCustomerIds($conn, $cid) : [0];
                    if (empty($famIds)) $famIds = [0];
                    $inCust = implode(',', array_map('intval', $famIds));
                    $where[] = "pe.customer_id IN ($inCust)";
                }
                if ($fCustomerId > 0) { $where[] = "pe.customer_id = ?"; $types .= 'i'; $params[] = $fCustomerId; }
                if ($fVehicleId > 0)  { $where[] = "pe.vehicle_id = ?";  $types .= 'i'; $params[] = $fVehicleId; }
                if ($fAgentId > 0 && !$isAgent) { $where[] = "pe.agent_id = ?"; $types .= 'i'; $params[] = $fAgentId; }

                if ($where) $sql .= " WHERE " . implode(' AND ', $where);
                $sql .= " ORDER BY pe.id DESC";

                $stmt = $conn->prepare($sql);
                if ($types !== '') $stmt->bind_param($types, ...$params);

                $stmt->execute();
                $res = $stmt->get_result();
                $policies = [];
                while ($row = $res->fetch_assoc()) {
                    $row['id'] = (int)$row['id'];
                    $row['branch_id'] = (int)($row['branch_id'] ?? 0);
                    $row['total_premium_amount'] = (float)$row['total_premium_amount'];
                    $row['tp_amount'] = (float)$row['tp_amount'];
                    $row['od_amount'] = (float)$row['od_amount'];
                    $row['net_premium_amount'] = (float)$row['net_premium_amount'];
                    $row['buyer_commission_amount'] = (float)$row['buyer_commission_amount'];
                    $row['agent_commission_amount'] = (float)($row['agent_commission_amount'] ?? 0);
                    $row['payment_in'] = (float)($row['payment_in'] ?? 0);
                    $row['payment_out'] = (float)($row['payment_out'] ?? 0);
                    $row['gst_amount'] = (float)($row['gst_amount'] ?? 0);
                    if ($isCustomer) {
                        $row['buyer_commission_basis'] = '';
                        $row['buyer_commission_rate'] = 0;
                        $row['buyer_commission_amount'] = 0;
                        $row['agent_commission_basis'] = '';
                        $row['agent_commission_rate'] = 0;
                        $row['agent_commission_amount'] = 0;
                        $row['payment_out'] = 0;
                        $row['company_code'] = '';
                        $row['buyer_id'] = 0;
                        $row['is_personal'] = ($row['customer_id'] == $cid);
                    }
                    $policies[] = $row;
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $policies]);
                exit();

            case 'getPolicy':
                $pid = (int)($_GET['id'] ?? 0);
                if ($pid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid policy ID']);
                    exit();
                }

                $sql = "SELECT pe.*, br.name as branch_name,
                               bin.bank_name as payment_in_bank_name, bout.bank_name as payment_out_bank_name,
                               prev.policy_number AS previous_policy_number, upd.name AS updated_by_name,
                               (SELECT r.id FROM policy_entries r WHERE r.previous_policy_id = pe.id ORDER BY r.id DESC LIMIT 1) AS renewed_by_id,
                               (SELECT r.policy_number FROM policy_entries r WHERE r.previous_policy_id = pe.id ORDER BY r.id DESC LIMIT 1) AS renewed_by_number
                        FROM policy_entries pe
                        LEFT JOIN branches br ON pe.branch_id = br.id
                        LEFT JOIN policy_entries prev ON prev.id = pe.previous_policy_id
                        LEFT JOIN users upd ON upd.id = pe.updated_by
                        LEFT JOIN bank_accounts bin ON pe.payment_in_bank_account_id = bin.id
                        LEFT JOIN bank_accounts bout ON pe.payment_out_bank_account_id = bout.id
                        WHERE pe.id = ?";
                if ($isAgent) {
                    $sql .= " AND pe.agent_id = (SELECT a.id FROM agents a WHERE a.user_id = ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ii", $pid, $user_id);
                } elseif ($isCustomer) {
                    $cid = customerIdForUser($conn, $user_id);
                    if (!$cid && !empty($_SESSION['customer_id'])) $cid = (int)$_SESSION['customer_id'];
                    $famIds = $cid ? getFamilyCustomerIds($conn, $cid) : [0];
                    if (empty($famIds)) $famIds = [0];
                    $inCust = implode(',', array_map('intval', $famIds));
                    $sql .= " AND pe.customer_id IN ($inCust)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $pid);
                } else {
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $pid);
                }

                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Policy not found']);
                    exit();
                }

                $row['id'] = (int)$row['id'];
                if ($isCustomer) {
                    $row['buyer_commission_basis'] = '';
                    $row['buyer_commission_rate'] = 0;
                    $row['buyer_commission_amount'] = 0;
                    $row['agent_commission_basis'] = '';
                    $row['agent_commission_rate'] = 0;
                    $row['agent_commission_amount'] = 0;
                    $row['payment_out'] = 0;
                    $row['company_code'] = '';
                    $row['buyer_id'] = 0;
                    $row['buyer_name'] = '';
                    $row['payment_out_bank_account_id'] = 0;
                    $row['payment_out_bank_name'] = '';
                }
                $conn->close();
                echo json_encode(['success' => true, 'data' => $row]);
                exit();

            case 'addPolicy':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if (!$canCreate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied: Only agents are allowed to create new policies']);
                    exit();
                }

                if ($role === 'agent') {
                    $chkAid = agentIdForUser($conn, $user_id);
                    $pLimit = checkAgentSubscriptionLimit($conn, $chkAid, 'policy');
                    if (!$pLimit['allowed']) {
                        echo json_encode(['success' => false, 'message' => $pLimit['message']]);
                        exit();
                    }
                }

                // required fields
                $insCat = trim($_POST['insurance_category'] ?? 'motor');
                if (!in_array($insCat, ['motor', 'health', 'life'], true)) $insCat = 'motor';
                $isMotor = ($insCat === 'motor');

                $entryDate = trim($_POST['entry_date'] ?? '');
                $branchId = (int)($_POST['branch_id'] ?? 0);
                $vehicleId = $isMotor ? (int)($_POST['vehicle_id'] ?? 0) : null;
                $customerId = (int)($_POST['customer_id'] ?? 0);
                $buyerId = (int)($_POST['buyer_id'] ?? 0);
                $policyNumber = trim($_POST['policy_number'] ?? '');
                $totalPremium = (float)($_POST['total_premium_amount'] ?? 0);
                $tpAmount = $isMotor ? (float)($_POST['tp_amount'] ?? 0) : 0.0;
                $odAmount = $isMotor ? (float)($_POST['od_amount'] ?? 0) : 0.0;
                $netPremium = (float)($_POST['net_premium_amount'] ?? 0);
                $buyerBasis = trim($_POST['buyer_commission_basis'] ?? '');
                $buyerRate = (float)($_POST['buyer_commission_rate'] ?? 0);
                $buyerCommAmt = (float)($_POST['buyer_commission_amount'] ?? 0);
                if (empty($buyerBasis)) {
                    $buyerBasis = 'fixed';
                    $buyerRate = 0.0;
                    $buyerCommAmt = 0.0;
                }
                $policyStart = trim($_POST['policy_start_date'] ?? '');
                $policyEnd = trim($_POST['policy_end_date'] ?? '');
                $status = trim($_POST['status'] ?? 'active');

                // optional
                $agentId = !empty($_POST['agent_id']) ? (int)$_POST['agent_id'] : null;
                if ($isAgent) {
                    $myAgentId = agentIdForUser($conn, $user_id);
                    if ($myAgentId) {
                        $agentId = (int)$myAgentId;
                    }
                }
                $agentBasis = !empty($_POST['agent_commission_basis']) ? trim($_POST['agent_commission_basis']) : null;
                $agentRate = !empty($_POST['agent_commission_rate']) ? (float)$_POST['agent_commission_rate'] : null;
                $agentCommAmt = (float)($_POST['agent_commission_amount'] ?? 0);
                $isManual = isset($_POST['is_commission_manual']) ? 1 : 0;
                $payInBankId = !empty($_POST['payment_in_bank_account_id']) ? (int)$_POST['payment_in_bank_account_id'] : null;
                $payOutBankId = !empty($_POST['payment_out_bank_account_id']) ? (int)$_POST['payment_out_bank_account_id'] : null;
                $bankAccId = $payInBankId ?: $payOutBankId; // legacy single col mirrors whichever account is set
                $paymentMode = !empty($_POST['payment_mode']) ? trim($_POST['payment_mode']) : null;
                $paymentIn = (float)($_POST['payment_in'] ?? 0);
                $paymentOut = (float)($_POST['payment_out'] ?? 0);
                $remarks = trim($_POST['remarks'] ?? '');

                // agent is mandatory - always agent-based, no Company Direct
                if (!$agentId) {
                    echo json_encode(['success' => false, 'message' => 'Please select an agent — every policy must be assigned to a listed agent']);
                    exit();
                }

                // handle new vehicle (motor only)
                if ($isMotor && !empty($_POST['new_vehicle_number'])) {
                    $newVehicleNum = strtoupper(trim($_POST['new_vehicle_number']));
                    $newVehicleCat = trim($_POST['new_vehicle_category'] ?? '');
                    $newVehicleMake = trim($_POST['new_vehicle_make'] ?? '') ?: null;
                    $newVehicleModel = trim($_POST['new_vehicle_model'] ?? '') ?: null;
                    $validCats = getVehicleCategories($conn); // admin-managed list
                    if (!$newVehicleNum || !in_array($newVehicleCat, $validCats)) {
                        echo json_encode(['success' => false, 'message' => 'Vehicle number and valid category required']);
                        exit();
                    }
                    $stmt = $conn->prepare("SELECT id FROM vehicles WHERE vehicle_number = ? LIMIT 1");
                    $stmt->bind_param("s", $newVehicleNum);
                    $stmt->execute();
                    $ev = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($ev) {
                        $vehicleId = (int)$ev['id'];
                    } else {
                        $cid = $customerId ?: 0;
                        $stmt = $conn->prepare("INSERT INTO vehicles (vehicle_number, vehicle_type, make, model, customer_id) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("ssssi", $newVehicleNum, $newVehicleCat, $newVehicleMake, $newVehicleModel, $cid);
                        $stmt->execute();
                        $vehicleId = $stmt->insert_id;
                        $stmt->close();
                    }
                }

                // handle new customer
                if (!empty($_POST['new_customer_name'])) {
                    $newCustName = trim($_POST['new_customer_name']);
                    $newCustMobile = trim($_POST['new_customer_mobile'] ?? '');
                    if (!$newCustName || !$newCustMobile) {
                        echo json_encode(['success' => false, 'message' => 'Customer name and mobile required']);
                        exit();
                    }
                    $stmt = $conn->prepare("SELECT id FROM customers WHERE mobile = ? LIMIT 1");
                    $stmt->bind_param("s", $newCustMobile);
                    $stmt->execute();
                    $ec = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($ec) {
                        $customerId = (int)$ec['id'];
                    } else {
                        $stmt = $conn->prepare("INSERT INTO customers (name, mobile) VALUES (?, ?)");
                        $stmt->bind_param("ss", $newCustName, $newCustMobile);
                        $stmt->execute();
                        $customerId = $stmt->insert_id;
                        $stmt->close();
                    }
                    // update vehicle owner if newly created
                    if ($isMotor && !empty($_POST['new_vehicle_number']) && $vehicleId && !$ev) {
                        $stmt = $conn->prepare("UPDATE vehicles SET customer_id = ? WHERE id = ?");
                        $stmt->bind_param("ii", $customerId, $vehicleId);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                // auto-fill buyer / basis if not provided (pure policy management)
                if (!$buyerId) {
                    $bQ = $conn->query("SELECT id, company_name, company_code FROM buyers WHERE is_active = 1 LIMIT 1");
                    if ($bQ && ($bRow = $bQ->fetch_assoc())) {
                        $buyerId = (int)$bRow['id'];
                    } else {
                        $buyerId = 1;
                    }
                }
                if (!$buyerBasis) {
                    $buyerBasis = 'fixed';
                    $buyerRate = 0.0;
                    $buyerCommAmt = 0.0;
                }

                // validate required - presence checks (0 is a valid amount)
                $totalPremiumRaw = $_POST['total_premium_amount'] ?? '';
                $netPremiumRaw = $_POST['net_premium_amount'] ?? '';
                if (!$entryDate || !$branchId || ($isMotor && !$vehicleId) || !$customerId || !$policyNumber || trim((string)$totalPremiumRaw) === '' || trim((string)$netPremiumRaw) === '' || !$policyStart || !$policyEnd) {
                    echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
                    exit();
                }
                // no negative money
                if ($totalPremium < 0 || $netPremium < 0 || $tpAmount < 0 || $odAmount < 0 || $buyerRate < 0 || $buyerCommAmt < 0 || $agentCommAmt < 0 || $paymentIn < 0 || $paymentOut < 0) {
                    echo json_encode(['success' => false, 'message' => 'Amounts cannot be negative']);
                    exit();
                }
                if (!in_array($status, ['active', 'expired', 'cancelled'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid status']);
                    exit();
                }
                $validBasis = ['premium_pct', 'tp_pct', 'od_pct', 'net_pct', 'fixed'];
                if (!in_array($buyerBasis, $validBasis)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid buyer commission basis']);
                    exit();
                }

                list($extras, $xErr) = policyExtras($conn, $_POST, 0, $status, $totalPremium, $netPremium);
                if ($xErr) { echo json_encode(['success' => false, 'message' => $xErr]); exit(); }

                // unique policy number
                $stmt = $conn->prepare("SELECT id FROM policy_entries WHERE policy_number = ?");
                $stmt->bind_param("s", $policyNumber);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Policy number already exists']);
                    exit();
                }
                $stmt->close();

                // auto-fill denormalized fields
                // vehicle
                $vehicleNumber = null;
                $vehicleType = null;
                if ($isMotor && $vehicleId) {
                    $stmt = $conn->prepare("SELECT vehicle_number, vehicle_type FROM vehicles WHERE id = ?");
                    $stmt->bind_param("i", $vehicleId);
                    $stmt->execute();
                    $veh = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $vehicleNumber = $veh ? $veh['vehicle_number'] : null;
                    $vehicleType = $veh ? $veh['vehicle_type'] : null;
                }

                // customer
                $stmt = $conn->prepare("SELECT name FROM customers WHERE id = ?");
                $stmt->bind_param("i", $customerId);
                $stmt->execute();
                $cust = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $customerName = $cust ? $cust['name'] : '';

                // buyer
                $stmt = $conn->prepare("SELECT company_name, company_code FROM buyers WHERE id = ?");
                $stmt->bind_param("i", $buyerId);
                $stmt->execute();
                $buy = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $companyName = $buy ? $buy['company_name'] : '';
                $companyCode = $buy ? $buy['company_code'] : '';

                // agent guaranteed present above (agent-based only)
                $agentName = null;
                if ($agentId) {
                    $stmt = $conn->prepare("SELECT name FROM agents WHERE id = ?");
                    $stmt->bind_param("i", $agentId);
                    $stmt->execute();
                    $agt = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $agentName = $agt ? $agt['name'] : null;
                }

                // bank
                $bankName = null;
                if ($bankAccId) {
                    $stmt = $conn->prepare("SELECT bank_name FROM bank_accounts WHERE id = ?");
                    $stmt->bind_param("i", $bankAccId);
                    $stmt->execute();
                    $bnk = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $bankName = $bnk ? $bnk['bank_name'] : null;
                }

                $createdBy = $user_id;

                // server-side commission recompute (ignore posted amt unless manual)
                $buyerCommAmt = computeCommission($buyerBasis, $buyerRate, $totalPremium, $tpAmount, $odAmount, $netPremium);
                if (!$isManual) {
                    $agentCommAmt = ($agentId && $agentBasis && in_array($agentBasis, $validBasis))
                        ? computeCommission($agentBasis, (float)$agentRate, $totalPremium, $tpAmount, $odAmount, $netPremium)
                        : 0.00;
                }

                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("INSERT INTO policy_entries (
                        entry_date, branch_id, vehicle_id, vehicle_number, vehicle_type,
                        customer_id, customer_name, agent_id, agent_name, buyer_id,
                        company_name, company_code, policy_number, total_premium_amount, tp_amount,
                        od_amount, net_premium_amount, buyer_commission_basis, buyer_commission_rate, buyer_commission_amount,
                        agent_commission_basis, agent_commission_rate, agent_commission_amount, is_commission_manual,
                        bank_account_id, bank_name, payment_in, payment_out, payment_in_bank_account_id, payment_out_bank_account_id, payment_mode,
                        policy_start_date, policy_end_date, status, remarks, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                    // types: s=str i=int d=decimal, 36 params
                    $stmt->bind_param(
                        "siissisisisssddddsddsddiisddiisssssi",
                        $entryDate, $branchId, $vehicleId, $vehicleNumber, $vehicleType,
                        $customerId, $customerName, $agentId, $agentName, $buyerId,
                        $companyName, $companyCode, $policyNumber, $totalPremium, $tpAmount,
                        $odAmount, $netPremium, $buyerBasis, $buyerRate, $buyerCommAmt,
                        $agentBasis, $agentRate, $agentCommAmt, $isManual,
                        $bankAccId, $bankName, $paymentIn, $paymentOut, $payInBankId, $payOutBankId, $paymentMode,
                        $policyStart, $policyEnd, $status, $remarks, $createdBy
                    );

                    if (!$stmt->execute()) {
                        $err = $stmt->error;
                        $stmt->close();
                        throw new Exception('Failed to add policy: ' . $err);
                    }
                    $insertId = $stmt->insert_id;
                    $stmt->close();
                    savePolicyExtras($conn, $insertId, $extras, null);

                    // Automatic 5-Stage Expiry Reminders Scheduling
                    schedulePolicyReminders($conn, $insertId, $agentId, $customerId, $policyEnd);

                    // Continuous Policy Renewal Chain
                    $prevPolicyId = !empty($_POST['previous_policy_id']) ? (int)$_POST['previous_policy_id'] : 0;
                    if ($prevPolicyId > 0) {
                        $conn->query("UPDATE policy_entries SET previous_policy_id = {$prevPolicyId}, renewal_status = 'renewed' WHERE id = {$insertId}");
                        $conn->query("UPDATE policy_entries SET renewal_status = 'renewed' WHERE id = {$prevPolicyId}");
                        $conn->query("UPDATE reminder_schedules SET status = 'cancelled' WHERE policy_entry_id = {$prevPolicyId} AND status = 'pending'");
                        $conn->query("UPDATE customer_followups SET followup_stage = 'renewed', is_completed = 1 WHERE policy_entry_id = {$prevPolicyId}");
                    }

                    // Increment Agent Subscription Usage
                    if ($agentId > 0) {
                        $conn->query("UPDATE subscriptions SET policy_count = policy_count + 1 WHERE agent_id = {$agentId} AND status = 'active' ORDER BY id DESC LIMIT 1");
                    }

                    $zero = 0.00;

                    // auto-post agent commission txn
                    if ($agentId && $agentCommAmt > 0) {
                        $commDesc = "Commission for $policyNumber";
                        $commType = 'commission';
                        $stmt = $conn->prepare("INSERT INTO agent_transactions (agent_id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, policy_entry_id, created_by) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
                        $stmt->bind_param("isssddii", $agentId, $entryDate, $commDesc, $commType, $agentCommAmt, $zero, $insertId, $user_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // auto-post buyer commission txn
                    if ($buyerId && $buyerCommAmt > 0) {
                        $commDesc = "Commission for $policyNumber";
                        $commType = 'commission_receivable';
                        $stmt = $conn->prepare("INSERT INTO buyer_transactions (buyer_id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, policy_entry_id, created_by) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
                        $stmt->bind_param("isssddii", $buyerId, $entryDate, $commDesc, $commType, $buyerCommAmt, $zero, $insertId, $user_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // auto-post payment_in bank txn -> into the payment-in account
                    if ($payInBankId && $paymentIn > 0) {
                        $btDesc = "Payment In - $policyNumber ($customerName)";
                        $btType = 'credit';
                        $stmt = $conn->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, description, transaction_type, amount, running_balance, policy_entry_id, category, created_by) VALUES (?, ?, ?, ?, ?, 0, ?, 'policy_payment_in', ?)");
                        $stmt->bind_param("isssdii", $payInBankId, $entryDate, $btDesc, $btType, $paymentIn, $insertId, $user_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // auto-post payment_out bank txn -> out of the payment-out account
                    if ($payOutBankId && $paymentOut > 0) {
                        $btDesc = "Payment Out - $policyNumber ($companyName)";
                        $btType = 'debit';
                        $stmt = $conn->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, description, transaction_type, amount, running_balance, policy_entry_id, category, created_by) VALUES (?, ?, ?, ?, ?, 0, ?, 'policy_payment_out', ?)");
                        $stmt->bind_param("isssdii", $payOutBankId, $entryDate, $btDesc, $btType, $paymentOut, $insertId, $user_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // recompute balances from ledgers
                    if ($agentId && $agentCommAmt > 0) recalcAgentBalances($conn, $agentId);
                    if ($buyerId && $buyerCommAmt > 0) recalcBuyerBalances($conn, $buyerId);
                    foreach (array_unique(array_filter([$payInBankId, $payOutBankId])) as $bk) recalcBankBalance($conn, $bk);

                    // notify admins + assigned agent (inside txn - no orphan on rollback)
                    notifyPolicyAdded($conn, $insertId, $policyNumber, $agentId, $agentName);

                    $conn->commit();
                    logActivity($user_id, $username, 'Policy Created', "Policy #$insertId: $policyNumber ($companyName)" . policyExtrasLog($extras));
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Policy added successfully', 'data' => ['id' => $insertId]]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
                exit();

            case 'updatePolicy':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if (!$canEdit) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $pid = (int)($_POST['id'] ?? 0);
                if ($pid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid policy ID']);
                    exit();
                }

                $insCat = trim($_POST['insurance_category'] ?? 'motor');
                if (!in_array($insCat, ['motor', 'health', 'life'], true)) $insCat = 'motor';
                $isMotor = ($insCat === 'motor');

                $entryDate = trim($_POST['entry_date'] ?? '');
                $branchId = (int)($_POST['branch_id'] ?? 0);
                $vehicleId = $isMotor ? (int)($_POST['vehicle_id'] ?? 0) : null;
                $customerId = (int)($_POST['customer_id'] ?? 0);
                $buyerId = (int)($_POST['buyer_id'] ?? 0);
                $policyNumber = trim($_POST['policy_number'] ?? '');
                $totalPremium = (float)($_POST['total_premium_amount'] ?? 0);
                $tpAmount = $isMotor ? (float)($_POST['tp_amount'] ?? 0) : 0.0;
                $odAmount = $isMotor ? (float)($_POST['od_amount'] ?? 0) : 0.0;
                $netPremium = (float)($_POST['net_premium_amount'] ?? 0);
                $buyerBasis = trim($_POST['buyer_commission_basis'] ?? '');
                $buyerRate = (float)($_POST['buyer_commission_rate'] ?? 0);
                $buyerCommAmt = (float)($_POST['buyer_commission_amount'] ?? 0);
                if (empty($buyerBasis)) {
                    $buyerBasis = 'fixed';
                    $buyerRate = 0.0;
                    $buyerCommAmt = 0.0;
                }
                $policyStart = trim($_POST['policy_start_date'] ?? '');
                $policyEnd = trim($_POST['policy_end_date'] ?? '');
                $status = trim($_POST['status'] ?? 'active');

                $agentId = !empty($_POST['agent_id']) ? (int)$_POST['agent_id'] : null;
                if ($isAgent) {
                    $myAgentId = agentIdForUser($conn, $user_id);
                    $chk = $conn->prepare("SELECT agent_id FROM policy_entries WHERE id = ?");
                    $chk->bind_param("i", $pid);
                    $chk->execute();
                    $polRow = $chk->get_result()->fetch_assoc();
                    $chk->close();
                    if (!$polRow || (int)$polRow['agent_id'] !== (int)$myAgentId) {
                        echo json_encode(['success' => false, 'message' => 'Access denied: You can only edit your own policies']);
                        exit();
                    }
                    $agentId = (int)$myAgentId;
                }
                $agentBasis = !empty($_POST['agent_commission_basis']) ? trim($_POST['agent_commission_basis']) : null;
                $agentRate = !empty($_POST['agent_commission_rate']) ? (float)$_POST['agent_commission_rate'] : null;
                $agentCommAmt = (float)($_POST['agent_commission_amount'] ?? 0);
                $isManual = isset($_POST['is_commission_manual']) ? 1 : 0;
                $payInBankId = !empty($_POST['payment_in_bank_account_id']) ? (int)$_POST['payment_in_bank_account_id'] : null;
                $payOutBankId = !empty($_POST['payment_out_bank_account_id']) ? (int)$_POST['payment_out_bank_account_id'] : null;
                $bankAccId = $payInBankId ?: $payOutBankId; // legacy single col mirrors whichever account is set
                $paymentMode = !empty($_POST['payment_mode']) ? trim($_POST['payment_mode']) : null;
                $paymentIn = (float)($_POST['payment_in'] ?? 0);
                $paymentOut = (float)($_POST['payment_out'] ?? 0);
                $remarks = trim($_POST['remarks'] ?? '');

                // presence checks (0 is a valid amount)
                $totalPremiumRaw = $_POST['total_premium_amount'] ?? '';
                $netPremiumRaw = $_POST['net_premium_amount'] ?? '';
                if (!$entryDate || !$branchId || ($isMotor && !$vehicleId) || !$customerId || !$buyerId || !$policyNumber || trim((string)$totalPremiumRaw) === '' || trim((string)$netPremiumRaw) === '' || !$buyerBasis || !$policyStart || !$policyEnd) {
                    echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
                    exit();
                }
                if ($totalPremium < 0 || $netPremium < 0 || $tpAmount < 0 || $odAmount < 0 || $buyerRate < 0 || $buyerCommAmt < 0 || $agentCommAmt < 0 || $paymentIn < 0 || $paymentOut < 0) {
                    echo json_encode(['success' => false, 'message' => 'Amounts cannot be negative']);
                    exit();
                }
                if (!in_array($status, ['active', 'expired', 'cancelled'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid status']);
                    exit();
                }
                $validBasis = ['premium_pct', 'tp_pct', 'od_pct', 'net_pct', 'fixed'];
                if (!in_array($buyerBasis, $validBasis)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid buyer commission basis']);
                    exit();
                }

                list($extras, $xErr) = policyExtras($conn, $_POST, $pid, $status, $totalPremium, $netPremium);
                if ($xErr) { echo json_encode(['success' => false, 'message' => $xErr]); exit(); }

                // unique policy number excluding self
                $stmt = $conn->prepare("SELECT id FROM policy_entries WHERE policy_number = ? AND id != ?");
                $stmt->bind_param("si", $policyNumber, $pid);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $stmt->close(); $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Policy number already exists']);
                    exit();
                }
                $stmt->close();

                // auto-fill denormalized
                $vehicleNumber = null;
                $vehicleType = null;
                if ($isMotor && $vehicleId) {
                    $stmt = $conn->prepare("SELECT vehicle_number, vehicle_type FROM vehicles WHERE id = ?");
                    $stmt->bind_param("i", $vehicleId);
                    $stmt->execute();
                    $veh = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $vehicleNumber = $veh ? $veh['vehicle_number'] : null;
                    $vehicleType = $veh ? $veh['vehicle_type'] : null;
                }

                $stmt = $conn->prepare("SELECT name FROM customers WHERE id = ?");
                $stmt->bind_param("i", $customerId);
                $stmt->execute();
                $cust = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $customerName = $cust ? $cust['name'] : '';

                $stmt = $conn->prepare("SELECT company_name, company_code FROM buyers WHERE id = ?");
                $stmt->bind_param("i", $buyerId);
                $stmt->execute();
                $buy = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $companyName = $buy ? $buy['company_name'] : '';
                $companyCode = $buy ? $buy['company_code'] : '';

                // agent is mandatory - always agent-based, no Company Direct
                if (!$agentId) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Please select an agent — every policy must be assigned to a listed agent']);
                    exit();
                }

                $agentName = null;
                if ($agentId) {
                    $stmt = $conn->prepare("SELECT name FROM agents WHERE id = ?");
                    $stmt->bind_param("i", $agentId);
                    $stmt->execute();
                    $agt = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $agentName = $agt ? $agt['name'] : null;
                }

                $bankName = null;
                if ($bankAccId) {
                    $stmt = $conn->prepare("SELECT bank_name FROM bank_accounts WHERE id = ?");
                    $stmt->bind_param("i", $bankAccId);
                    $stmt->execute();
                    $bnk = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $bankName = $bnk ? $bnk['bank_name'] : null;
                }

                // server-side commission recompute (ignore posted amt unless manual)
                $buyerCommAmt = computeCommission($buyerBasis, $buyerRate, $totalPremium, $tpAmount, $odAmount, $netPremium);
                if (!$isManual) {
                    $agentCommAmt = ($agentId && $agentBasis && in_array($agentBasis, $validBasis))
                        ? computeCommission($agentBasis, (float)$agentRate, $totalPremium, $tpAmount, $odAmount, $netPremium)
                        : 0.00;
                }

                $conn->begin_transaction();
                try {
                    // load old row to reverse its auto-posted ledger entries
                    $stmt = $conn->prepare("SELECT agent_id, buyer_id, bank_account_id, payment_in_bank_account_id, payment_out_bank_account_id FROM policy_entries WHERE id = ?");
                    $stmt->bind_param("i", $pid);
                    $stmt->execute();
                    $old = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$old) throw new Exception('Policy not found');

                    $oldAgentId = $old['agent_id'] ? (int)$old['agent_id'] : null;
                    $oldBuyerId = $old['buyer_id'] ? (int)$old['buyer_id'] : null;
                    $oldBankId = $old['bank_account_id'] ? (int)$old['bank_account_id'] : null;
                    $oldInBankId = $old['payment_in_bank_account_id'] ? (int)$old['payment_in_bank_account_id'] : null;
                    $oldOutBankId = $old['payment_out_bank_account_id'] ? (int)$old['payment_out_bank_account_id'] : null;

                    // reverse old auto-posted entries
                    $del = $conn->prepare("DELETE FROM agent_transactions WHERE policy_entry_id = ?");
                    $del->bind_param("i", $pid); $del->execute(); $del->close();
                    $del = $conn->prepare("DELETE FROM buyer_transactions WHERE policy_entry_id = ?");
                    $del->bind_param("i", $pid); $del->execute(); $del->close();
                    $del = $conn->prepare("DELETE FROM bank_transactions WHERE policy_entry_id = ?");
                    $del->bind_param("i", $pid); $del->execute(); $del->close();

                    // update policy
                    $stmt = $conn->prepare("UPDATE policy_entries SET
                        entry_date=?, branch_id=?, vehicle_id=?, vehicle_number=?, vehicle_type=?,
                        customer_id=?, customer_name=?, agent_id=?, agent_name=?, buyer_id=?,
                        company_name=?, company_code=?, policy_number=?, total_premium_amount=?, tp_amount=?,
                        od_amount=?, net_premium_amount=?, buyer_commission_basis=?, buyer_commission_rate=?, buyer_commission_amount=?,
                        agent_commission_basis=?, agent_commission_rate=?, agent_commission_amount=?, is_commission_manual=?,
                        bank_account_id=?, bank_name=?, payment_in=?, payment_out=?, payment_in_bank_account_id=?, payment_out_bank_account_id=?, payment_mode=?,
                        policy_start_date=?, policy_end_date=?, status=?, remarks=?
                    WHERE id=?");

                    // 36 params: same as insert minus created_by + id at end
                    $stmt->bind_param(
                        "siissisisisssddddsddsddiisddiisssssi",
                        $entryDate, $branchId, $vehicleId, $vehicleNumber, $vehicleType,
                        $customerId, $customerName, $agentId, $agentName, $buyerId,
                        $companyName, $companyCode, $policyNumber, $totalPremium, $tpAmount,
                        $odAmount, $netPremium, $buyerBasis, $buyerRate, $buyerCommAmt,
                        $agentBasis, $agentRate, $agentCommAmt, $isManual,
                        $bankAccId, $bankName, $paymentIn, $paymentOut, $payInBankId, $payOutBankId, $paymentMode,
                        $policyStart, $policyEnd, $status, $remarks, $pid
                    );
                    if (!$stmt->execute()) {
                        $err = $stmt->error;
                        $stmt->close();
                        throw new Exception('Failed to update: ' . $err);
                    }
                    $stmt->close();
                    savePolicyExtras($conn, $pid, $extras, (int)$user_id);

                    $zero = 0.00;

                    // re-post agent commission
                    if ($agentId && $agentCommAmt > 0) {
                        $commDesc = "Commission for $policyNumber";
                        $commType = 'commission';
                        $stmt = $conn->prepare("INSERT INTO agent_transactions (agent_id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, policy_entry_id, created_by) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
                        $stmt->bind_param("isssddii", $agentId, $entryDate, $commDesc, $commType, $agentCommAmt, $zero, $pid, $user_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // re-post buyer commission
                    if ($buyerId && $buyerCommAmt > 0) {
                        $commDesc = "Commission for $policyNumber";
                        $commType = 'commission_receivable';
                        $stmt = $conn->prepare("INSERT INTO buyer_transactions (buyer_id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, policy_entry_id, created_by) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
                        $stmt->bind_param("isssddii", $buyerId, $entryDate, $commDesc, $commType, $buyerCommAmt, $zero, $pid, $user_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // re-post payment_in bank txn -> into the payment-in account
                    if ($payInBankId && $paymentIn > 0) {
                        $btDesc = "Payment In - $policyNumber ($customerName)";
                        $btType = 'credit';
                        $stmt = $conn->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, description, transaction_type, amount, running_balance, policy_entry_id, category, created_by) VALUES (?, ?, ?, ?, ?, 0, ?, 'policy_payment_in', ?)");
                        $stmt->bind_param("isssdii", $payInBankId, $entryDate, $btDesc, $btType, $paymentIn, $pid, $user_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // re-post payment_out bank txn -> out of the payment-out account
                    if ($payOutBankId && $paymentOut > 0) {
                        $btDesc = "Payment Out - $policyNumber ($companyName)";
                        $btType = 'debit';
                        $stmt = $conn->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, description, transaction_type, amount, running_balance, policy_entry_id, category, created_by) VALUES (?, ?, ?, ?, ?, 0, ?, 'policy_payment_out', ?)");
                        $stmt->bind_param("isssdii", $payOutBankId, $entryDate, $btDesc, $btType, $paymentOut, $pid, $user_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // recompute balances for every affected party (old + new)
                    $agentsToFix = array_unique(array_filter([$oldAgentId, $agentId]));
                    foreach ($agentsToFix as $a) recalcAgentBalances($conn, $a);
                    $buyersToFix = array_unique(array_filter([$oldBuyerId, $buyerId]));
                    foreach ($buyersToFix as $b) recalcBuyerBalances($conn, $b);
                    $banksToFix = array_unique(array_filter([$oldBankId, $oldInBankId, $oldOutBankId, $payInBankId, $payOutBankId]));
                    foreach ($banksToFix as $bk) recalcBankBalance($conn, $bk);

                    $conn->commit();
                    logActivity($user_id, $username, 'Policy Updated', "Updated policy #$pid: $policyNumber" . policyExtrasLog($extras));
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => 'Policy updated successfully']);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
                exit();

            case 'deletePolicy':
            case 'bulkDelete':
            case 'bulkDeletePolicies':
                // delete = move to Trash (restorable), one or many
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if (!$canDelete) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }
                $single = $_GET['action'] === 'deletePolicy';
                $ids = $single ? [(int)($_POST['id'] ?? 0)] : (array)json_decode($_POST['ids'] ?? '[]', true);
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
                if (!$ids) {
                    echo json_encode(['success' => false, 'message' => $single ? 'Invalid policy ID' : 'No policies selected']);
                    exit();
                }

                $conn->begin_transaction();
                try {
                    $recalc = ['agents' => [], 'buyers' => [], 'banks' => []];
                    $moved = [];
                    foreach ($ids as $pid) {
                        $num = policyRows($conn, "SELECT policy_number FROM policy_entries WHERE id = ?", $pid)[0]['policy_number'] ?? null;
                        $touch = trashPolicy($conn, $pid, $user_id);
                        if ($touch === null) continue;
                        foreach ($touch as $k => $list) foreach ($list as $x) $recalc[$k][$x] = 1;
                        $moved[] = $num;
                    }
                    if (!$moved) throw new RuntimeException('Policy not found');
                    // balances straight from the ledgers, every account the policies touched
                    foreach (array_keys($recalc['agents']) as $aid) recalcAgentBalances($conn, $aid);
                    foreach (array_keys($recalc['buyers']) as $bid) recalcBuyerBalances($conn, $bid);
                    foreach (array_keys($recalc['banks']) as $bkid) recalcBankBalance($conn, $bkid);
                    $conn->commit();
                    logActivity($user_id, $username, count($moved) > 1 ? 'Bulk Delete' : 'Policy Deleted', 'Moved to Trash: ' . implode(', ', $moved));
                    $conn->close();
                    echo json_encode(['success' => true, 'message' => count($moved) > 1 ? count($moved) . ' policies moved to Trash' : 'Policy moved to Trash — restore it from Trash if needed']);
                } catch (Throwable $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Could not delete: ' . $e->getMessage()]);
                }
                exit();

            case 'getTrash':
                if (!$canDelete) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
                $rows = $conn->query("SELECT t.id, t.policy_entry_id, t.policy_number, t.customer_name, t.vehicle_number, t.company_name, t.total_premium_amount, t.deleted_at, u.name AS deleted_by_name
                                      FROM policy_trash t LEFT JOIN users u ON u.id = t.deleted_by ORDER BY t.deleted_at DESC, t.id DESC")->fetch_all(MYSQLI_ASSOC);
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();

            case 'restorePolicy':
            case 'purgeTrash':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'Invalid request']); exit(); }
                if (!$canDelete) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
                $tid = (int)($_POST['id'] ?? 0);
                $conn->begin_transaction();
                try {
                    if ($_GET['action'] === 'purgeTrash') {
                        $num = purgeTrashItem($conn, $tid);
                        if ($num === null) throw new RuntimeException('That item is no longer in Trash');
                        $conn->commit();
                        logActivity($user_id, $username, 'Policy Purged', "Deleted forever from Trash: $num");
                        echo json_encode(['success' => true, 'message' => "Policy $num deleted permanently"]);
                    } else {
                        $r = restorePolicy($conn, $tid);
                        foreach ($r['agents'] as $aid) recalcAgentBalances($conn, $aid);
                        foreach ($r['buyers'] as $bid) recalcBuyerBalances($conn, $bid);
                        foreach ($r['banks'] as $bkid) recalcBankBalance($conn, $bkid);
                        $conn->commit();
                        logActivity($user_id, $username, 'Policy Restored', "Restored from Trash: {$r['number']} (#{$r['id']})");
                        echo json_encode(['success' => true, 'message' => "Policy {$r['number']} restored", 'id' => $r['id']]);
                    }
                } catch (Throwable $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Error: ' . $e->getMessage()]);
                }
                $conn->close();
                exit();

            case 'waReminder':
                // opens whatsapp with the reminder text (new tab); renewal reminders are counted on the policy
                $pid = (int)($_GET['id'] ?? 0);
                $kind = ($_GET['kind'] ?? '') === 'due' ? 'due' : 'renewal';
                $wa = $pid > 0 && canSeePolicy($conn, $pid) ? waPolicyLink($conn, $pid, $kind) : null;
                if (!$wa) { header('Content-Type: text/plain; charset=utf-8'); http_response_code(404); exit('Policy not found'); }
                if ($kind === 'renewal') policyExec($conn, "UPDATE policy_entries SET last_reminder_at = NOW(), reminder_count = reminder_count + 1 WHERE id = ?", $pid);
                logActivity($user_id, $username, $kind === 'due' ? 'Dues Reminder' : 'Renewal Reminder', "WhatsApp " . ($kind === 'due' ? 'dues' : 'renewal') . " reminder opened for {$wa['policy']}" . ($wa['number'] ? " (+{$wa['number']})" : ' (no number on file)'));
                $conn->close();
                header('Location: ' . $wa['url']);
                exit();

            case 'getEndorsements':
                $pid = (int)($_GET['policy_id'] ?? 0);
                if ($pid <= 0 || !canSeePolicy($conn, $pid)) { echo json_encode(['success' => false, 'message' => 'Invalid policy ID']); exit(); }
                $rows = policyRows($conn, "SELECT e.id, e.endorsement_number, e.endorsement_type, e.endorsement_date, e.effective_date, e.description, e.premium_change, e.fee_amount, e.other_income_id, e.created_at, u.name AS created_by_name
                                           FROM policy_endorsements e LEFT JOIN users u ON u.id = e.created_by WHERE e.policy_entry_id = ? ORDER BY e.endorsement_date DESC, e.id DESC", $pid, true);
                $conn->close();
                echo json_encode(['success' => true, 'data' => $rows]);
                exit();

            case 'addEndorsement':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'Invalid request']); exit(); }
                if (!$canEdit) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
                $pid = (int)($_POST['policy_id'] ?? 0);
                $pol = policyRows($conn, "SELECT id, policy_number, branch_id, policy_start_date, policy_end_date, status FROM policy_entries WHERE id = ?", $pid)[0] ?? null;
                $eType = $_POST['endorsement_type'] ?? '';
                $eNo = trim($_POST['endorsement_number'] ?? '') ?: null;
                $eDate = trim($_POST['endorsement_date'] ?? '');
                $effDate = trim($_POST['effective_date'] ?? '') ?: null;
                $eDesc = trim($_POST['description'] ?? '');
                $premChg = round((float)($_POST['premium_change'] ?? 0), 2);
                $fee = round((float)($_POST['fee_amount'] ?? 0), 2);
                $asIncome = ($_POST['record_income'] ?? '') === '1' && $fee > 0;
                $feeBank = !empty($_POST['bank_account_id']) ? (int)$_POST['bank_account_id'] : null;
                $types = ['owner_transfer', 'vehicle_details', 'addon_cover', 'hypothecation', 'correction', 'other'];
                $err = !$pol ? 'Policy not found'
                    : ($pol['status'] === 'cancelled' ? 'A cancelled policy cannot be endorsed'
                    : (!in_array($eType, $types, true) ? 'Pick an endorsement type'
                    : (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eDate) ? 'Endorsement date is required'
                    : ($eDate < $pol['policy_start_date'] || $eDate > $pol['policy_end_date'] ? "Endorsement date must fall inside the policy period ({$pol['policy_start_date']} – {$pol['policy_end_date']})"
                    : ($effDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effDate) ? 'Effective date is invalid'
                    : ($eDesc === '' || mb_strlen($eDesc) > 500 ? 'Description is required (max 500 chars)'
                    : ($eNo !== null && mb_strlen($eNo) > 50 ? 'Endorsement number max 50 chars'
                    : ($fee < 0 ? 'Fee cannot be negative' : null))))))));
                if ($err) { echo json_encode(['success' => false, 'message' => $err]); exit(); }

                $conn->begin_transaction();
                try {
                    $oiId = null;
                    if ($asIncome) { // fee -> other income (+ bank credit when an account is picked), same as the Other Income page
                        $src = "Endorsement fee - {$pol['policy_number']}";
                        $mode = $feeBank ? 'Bank Transfer' : 'Cash';
                        $st = $conn->prepare("INSERT INTO other_income (branch_id, income_date, source, amount, payment_mode, bank_account_id, reference_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $st->bind_param("issdsisi", $pol['branch_id'], $eDate, $src, $fee, $mode, $feeBank, $eNo, $user_id);
                        $st->execute();
                        $oiId = $st->insert_id;
                        $st->close();
                        if ($feeBank) { oiPostLeg($conn, $oiId, $feeBank, $eDate, $src, $eNo, $fee, (int)$user_id); recalcBankBalance($conn, $feeBank); }
                    }
                    $st = $conn->prepare("INSERT INTO policy_endorsements (policy_entry_id, endorsement_number, endorsement_type, endorsement_date, effective_date, description, premium_change, fee_amount, other_income_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $st->bind_param("isssssddii", $pid, $eNo, $eType, $eDate, $effDate, $eDesc, $premChg, $fee, $oiId, $user_id);
                    $st->execute();
                    $st->close();
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => 'Could not save the endorsement']);
                    exit();
                }
                logActivity($user_id, $username, 'Endorsement Added', "{$pol['policy_number']}: $eType" . ($fee > 0 ? ", fee $fee" . ($oiId ? " -> other income #$oiId" : '') : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Endorsement saved']);
                exit();

            case 'deleteEndorsement':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'Invalid request']); exit(); }
                if (!$canDelete) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
                $eid = (int)($_POST['id'] ?? 0);
                $en = policyRows($conn, "SELECT e.id, e.other_income_id, e.endorsement_type, p.policy_number, oi.bank_account_id FROM policy_endorsements e JOIN policy_entries p ON p.id = e.policy_entry_id LEFT JOIN other_income oi ON oi.id = e.other_income_id WHERE e.id = ?", $eid)[0] ?? null;
                if (!$en) { echo json_encode(['success' => false, 'message' => 'Endorsement not found']); exit(); }
                $conn->begin_transaction();
                try {
                    if ($en['other_income_id']) { // its fee income + bank leg go with it
                        oiDropLeg($conn, (int)$en['other_income_id']);
                        policyExec($conn, "DELETE FROM other_income WHERE id = ?", (int)$en['other_income_id']);
                        if ($en['bank_account_id']) recalcBankBalance($conn, (int)$en['bank_account_id']);
                    }
                    policyExec($conn, "DELETE FROM policy_endorsements WHERE id = ?", $eid);
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => 'Could not delete the endorsement']);
                    exit();
                }
                logActivity($user_id, $username, 'Endorsement Deleted', "{$en['policy_number']}: {$en['endorsement_type']} (#$eid)" . ($en['other_income_id'] ? ", fee income #{$en['other_income_id']} removed" : ''));
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Endorsement deleted']);
                exit();

            case 'getPolicyLedger':
                $pid = (int)($_GET['id'] ?? 0);
                if ($pid <= 0 || !canSeePolicy($conn, $pid)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid policy ID']);
                    exit();
                }

                // agent ledger lines auto-posted for this policy
                $ledger = [];
                $stmt = $conn->prepare("SELECT at.transaction_date, at.description, at.transaction_type, at.credit_amount, at.debit_amount, a.name as party
                                        FROM agent_transactions at LEFT JOIN agents a ON at.agent_id = a.id
                                        WHERE at.policy_entry_id = ? ORDER BY at.id");
                $stmt->bind_param("i", $pid);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($x = $r->fetch_assoc()) {
                    $ledger[] = ['ledger'=>'Agent', 'party'=>$x['party'], 'date'=>$x['transaction_date'],
                        'type'=>$x['transaction_type'], 'desc'=>$x['description'],
                        'credit'=>(float)$x['credit_amount'], 'debit'=>(float)$x['debit_amount']];
                }
                $stmt->close();

                $stmt = $conn->prepare("SELECT bt.transaction_date, bt.description, bt.transaction_type, bt.credit_amount, bt.debit_amount, b.company_name as party
                                        FROM buyer_transactions bt LEFT JOIN buyers b ON bt.buyer_id = b.id
                                        WHERE bt.policy_entry_id = ? ORDER BY bt.id");
                $stmt->bind_param("i", $pid);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($x = $r->fetch_assoc()) {
                    $ledger[] = ['ledger'=>'Buyer', 'party'=>$x['party'], 'date'=>$x['transaction_date'],
                        'type'=>$x['transaction_type'], 'desc'=>$x['description'],
                        'credit'=>(float)$x['credit_amount'], 'debit'=>(float)$x['debit_amount']];
                }
                $stmt->close();

                // bank txns use a single amount + credit/debit type
                $stmt = $conn->prepare("SELECT bnt.transaction_date, bnt.description, bnt.transaction_type, bnt.amount, ba.bank_name as party
                                        FROM bank_transactions bnt LEFT JOIN bank_accounts ba ON bnt.bank_account_id = ba.id
                                        WHERE bnt.policy_entry_id = ? ORDER BY bnt.id");
                $stmt->bind_param("i", $pid);
                $stmt->execute();
                $r = $stmt->get_result();
                while ($x = $r->fetch_assoc()) {
                    $amt = (float)$x['amount'];
                    $isCr = ($x['transaction_type'] === 'credit');
                    $ledger[] = ['ledger'=>'Bank', 'party'=>$x['party'], 'date'=>$x['transaction_date'],
                        'type'=>$x['transaction_type'], 'desc'=>$x['description'],
                        'credit'=>$isCr ? $amt : 0, 'debit'=>$isCr ? 0 : $amt];
                }
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $ledger]);
                exit();

            case 'viewDoc':
                // files are never public — login + same access as the policy
                $docId = (int)($_GET['id'] ?? 0);
                $stmt = $conn->prepare("SELECT file_path, file_name, policy_entry_id FROM policy_documents WHERE id = ?");
                $stmt->bind_param("i", $docId);
                $stmt->execute();
                $doc = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$doc || !canSeePolicy($conn, $doc['policy_entry_id'])) {
                    http_response_code(404);
                    header('Content-Type: text/plain; charset=utf-8');
                    exit('File not found');
                }
                $conn->close();
                sendStoredFile($doc['file_path'], $doc['file_name']);

            case 'getDocuments':
                $pid = (int)($_GET['policy_id'] ?? 0);
                if ($pid <= 0 || !canSeePolicy($conn, $pid)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid policy ID']);
                    exit();
                }

                $stmt = $conn->prepare("SELECT pd.id, pd.policy_entry_id, pd.document_type, pd.file_name, pd.file_size_kb, pd.mime_type, pd.created_at, u.name as uploader_name FROM policy_documents pd LEFT JOIN users u ON pd.uploaded_by = u.id WHERE pd.policy_entry_id = ? ORDER BY pd.created_at DESC");
                $stmt->bind_param("i", $pid);
                $stmt->execute();
                $res = $stmt->get_result();
                $docs = [];
                while ($r = $res->fetch_assoc()) $docs[] = $r;
                $stmt->close();
                $conn->close();
                echo json_encode(['success' => true, 'data' => $docs]);
                exit();

            case 'uploadDocument':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if (!$canEdit) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $pid = (int)($_POST['policy_id'] ?? 0);
                $docType = trim($_POST['document_type'] ?? 'other');
                if ($pid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid policy ID']);
                    exit();
                }
                if (!in_array($docType, ['policy_pdf', 'vehicle_image', 'other'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid document type']);
                    exit();
                }
                if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
                    exit();
                }

                $file = $_FILES['document'];
                $maxSize = 5 * 1024 * 1024; // 5MB
                if ($file['size'] > $maxSize) {
                    echo json_encode(['success' => false, 'message' => 'File too large. Max 5MB']);
                    exit();
                }

                // MIME check
                $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);

                if (!in_array($mime, $allowedMimes)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: PDF, JPG, PNG, GIF, WEBP']);
                    exit();
                }

                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExts)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid file extension']);
                    exit();
                }

                // create dir
                $uploadDir = __DIR__ . '/uploads/policies/' . $pid;
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $newName = storedName($docType, $ext);
                $filePath = 'uploads/policies/' . $pid . '/' . $newName;
                $fullPath = $uploadDir . '/' . $newName;

                if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
                    echo json_encode(['success' => false, 'message' => 'Failed to save file']);
                    exit();
                }

                $fileSizeKb = (int)round($file['size'] / 1024);
                $origName = basename($file['name']);

                $stmt = $conn->prepare("INSERT INTO policy_documents (policy_entry_id, document_type, file_name, file_path, file_size_kb, mime_type, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssisi", $pid, $docType, $origName, $filePath, $fileSizeKb, $mime, $user_id);

                if (!$stmt->execute()) {
                    $stmt->close(); $conn->close();
                    unlink($fullPath); // cleanup
                    echo json_encode(['success' => false, 'message' => 'Failed to save document record']);
                    exit();
                }
                $stmt->close();

                logActivity($user_id, $username, 'Document Uploaded', "Uploaded $docType for policy #$pid: $origName");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Document uploaded successfully']);
                exit();

            case 'bulkUpload':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if (!$canCreate) {
                    echo json_encode(['success' => false, 'message' => 'Access denied: Only agents are allowed to upload policies']);
                    exit();
                }

                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows) || count($rows) === 0) {
                    echo json_encode(['success' => false, 'message' => 'No data found in file']);
                    exit();
                }

                $inserted = 0;
                $errors = [];
                $validBasis = ['premium_pct', 'tp_pct', 'od_pct', 'net_pct', 'fixed'];
                $validCategories = getVehicleCategories($conn); // admin-managed list

                // bank lookup - account_number is the ONLY globally-unique key; several of our accounts share the same bank_name (and can share an IFSC)
                $bankByAcc = [];      // acctno -> id (unique)
                $bankByCombo = [];    // "name - acctno" / "ifsc - acctno" -> id (unique)
                $bankByNameIfsc = []; // name|ifsc -> {id:1,...} (may resolve to many = ambiguous)
                $bankById = [];       // id -> bank_name (denormalized col)
                $bres = $conn->query("SELECT id, bank_name, account_number, ifsc_code FROM bank_accounts WHERE is_active = 1");
                while ($b = $bres->fetch_assoc()) {
                    $bid = (int)$b['id'];
                    $nm = strtolower(trim($b['bank_name']));
                    $acc = strtolower(trim($b['account_number']));
                    $ifsc = strtolower(trim($b['ifsc_code']));
                    if ($acc !== '') $bankByAcc[$acc] = $bid;
                    if ($nm !== '' && $acc !== '') $bankByCombo[preg_replace('/\s*-\s*/', ' - ', $nm . ' - ' . $acc)] = $bid;
                    if ($ifsc !== '' && $acc !== '') $bankByCombo[preg_replace('/\s*-\s*/', ' - ', $ifsc . ' - ' . $acc)] = $bid;
                    if ($nm !== '') $bankByNameIfsc[$nm][$bid] = 1;
                    if ($ifsc !== '') $bankByNameIfsc[$ifsc][$bid] = 1;
                    $bankById[$bid] = $b['bank_name'];
                }
                // resolve a sheet bank ref -> [id, err]; err = '' | 'notfound' | 'ambiguous'
                $resolveBank = function($raw) use ($bankByAcc, $bankByCombo, $bankByNameIfsc) {
                    $k = strtolower(trim($raw));
                    if ($k === '') return [0, ''];
                    $kc = preg_replace('/\s*-\s*/', ' - ', $k); // normalize "name-acc" / "name  -  acc"
                    if (isset($bankByAcc[$k])) return [$bankByAcc[$k], ''];
                    if (isset($bankByCombo[$kc])) return [$bankByCombo[$kc], ''];
                    if (isset($bankByNameIfsc[$k])) {
                        $ids = array_keys($bankByNameIfsc[$k]);
                        return (count($ids) === 1) ? [$ids[0], ''] : [0, 'ambiguous'];
                    }
                    return [0, 'notfound'];
                };
                $banksTouched = [];

                foreach ($rows as $idx => $row) {
                    $rowNum = $idx + 2; // header is row 1
                    $rowTxn = false;
                    try {
                        // vertical
                        $insCategory = trim(strtolower($row['insurance_category'] ?? 'motor'));
                        if (!in_array($insCategory, ['motor', 'health', 'life'], true)) $insCategory = 'motor';

                        // required fields
                        $policyNumber = trim($row['policy_number'] ?? '');
                        $customerName = trim($row['customer_name'] ?? '');
                        $customerMobile = trim($row['customer_mobile'] ?? '');
                        $vehicleNumber = strtoupper(trim($row['vehicle_number'] ?? ''));
                        $vehicleCategory = trim($row['vehicle_category'] ?? '');
                        $companyCode = trim($row['company_code'] ?? '');
                        $branchName = trim($row['branch'] ?? '');
                        $totalPremium = (float)($row['total_premium'] ?? 0);
                        $netPremium = (float)($row['net_premium'] ?? 0);
                        $buyerBasis = trim($row['buyer_commission_basis'] ?? 'premium_pct');
                        $buyerRate = (float)($row['buyer_commission_rate'] ?? 0);
                        $policyStart = trim($row['policy_start_date'] ?? '');

                        // optional
                        $tpAmount = (float)($row['tp_amount'] ?? 0);
                        $odAmount = (float)($row['od_amount'] ?? 0);
                        $agentCode = trim($row['agent_code'] ?? '');
                        $agentBasis = trim($row['agent_commission_basis'] ?? '');
                        $agentRate = (float)($row['agent_commission_rate'] ?? 0);
                        $payMode = trim($row['payment_mode'] ?? '');
                        $payIn = (float)($row['payment_in'] ?? 0);
                        $payOut = (float)($row['payment_out'] ?? 0);
                        $payInBankRaw = trim($row['payment_in_bank'] ?? '');
                        $payOutBankRaw = trim($row['payment_out_bank'] ?? '');
                        $vehicleMake = trim($row['vehicle_make'] ?? '');
                        $vehicleModel = trim($row['vehicle_model'] ?? '');
                        $fuelType = trim($row['fuel_type'] ?? '');
                        $remarksVal = trim($row['remarks'] ?? '');

                        // validate required by vertical
                        if ($insCategory === 'motor') {
                            if (!$policyNumber || !$customerName || !$customerMobile || !$vehicleNumber || !$vehicleCategory || !$companyCode || !$totalPremium || !$netPremium || !$policyStart) {
                                $errors[] = "Row $rowNum: Missing required field(s) for Motor Policy (policy_number, customer_name, customer_mobile, vehicle_number, vehicle_category, company_code, total_premium, net_premium, policy_start_date)";
                                continue;
                            }
                            if (!in_array($vehicleCategory, $validCategories)) {
                                $errors[] = "Row $rowNum: Invalid vehicle category '$vehicleCategory'. Use one of: " . implode(', ', $validCategories);
                                continue;
                            }
                        } else {
                            if (!$policyNumber || !$customerName || !$customerMobile || !$companyCode || !$totalPremium || !$netPremium || !$policyStart) {
                                $errors[] = "Row $rowNum: Missing required field(s) for " . ucfirst($insCategory) . " Policy (policy_number, customer_name, customer_mobile, company_code, total_premium, net_premium, policy_start_date)";
                                continue;
                            }
                        }

                        // validate buyer basis
                        if (!in_array($buyerBasis, $validBasis)) {
                            $errors[] = "Row $rowNum: Invalid buyer commission basis '$buyerBasis'";
                            continue;
                        }

                        // agent is mandatory - we are always agent-based, never Company Direct
                        if ($agentCode === '') {
                            $errors[] = "Row $rowNum: Agent is required — enter the agent's code or name (as listed in Agents)";
                            continue;
                        }

                        // resolve in/out bank accounts by account no / "name - acctno" / ifsc; ambiguous name alone = hard error so nothing posts to the wrong account
                        $payInBankId = 0; $payOutBankId = 0;
                        if ($payInBankRaw !== '') {
                            list($payInBankId, $bErr) = $resolveBank($payInBankRaw);
                            if ($bErr === 'ambiguous') { $errors[] = "Row $rowNum: Payment In bank '$payInBankRaw' matches more than one account — use the account number (or 'Bank Name - AccountNumber')"; continue; }
                            if (!$payInBankId) { $errors[] = "Row $rowNum: Payment In bank '$payInBankRaw' not found"; continue; }
                        }
                        if ($payOutBankRaw !== '') {
                            list($payOutBankId, $bErr) = $resolveBank($payOutBankRaw);
                            if ($bErr === 'ambiguous') { $errors[] = "Row $rowNum: Payment Out bank '$payOutBankRaw' matches more than one account — use the account number (or 'Bank Name - AccountNumber')"; continue; }
                            if (!$payOutBankId) { $errors[] = "Row $rowNum: Payment Out bank '$payOutBankRaw' not found"; continue; }
                        }

                        // optional production cols - labels like "Third Party" accepted
                        $xRow = $row;
                        foreach (['policy_type', 'business_type'] as $k) $xRow[$k] = preg_replace('/[\s-]+/', '_', strtolower(trim($row[$k] ?? '')));
                        $prevNo = trim($row['previous_policy_number'] ?? '');
                        $xRow['previous_policy_id'] = 0;
                        if ($prevNo !== '') {
                            $stmt = $conn->prepare("SELECT id FROM policy_entries WHERE policy_number = ? LIMIT 1");
                            $stmt->bind_param("s", $prevNo);
                            $stmt->execute();
                            $pv = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                            if (!$pv) { $errors[] = "Row $rowNum: Previous policy '$prevNo' not found"; continue; }
                            $xRow['previous_policy_id'] = (int)$pv['id'];
                        }
                        list($extras, $xErr) = policyExtras($conn, $xRow, 0, 'active', $totalPremium, $netPremium);
                        if ($xErr) { $errors[] = "Row $rowNum: $xErr"; continue; }

                        // check duplicate policy_number
                        $stmt = $conn->prepare("SELECT id FROM policy_entries WHERE policy_number = ?");
                        $stmt->bind_param("s", $policyNumber);
                        $stmt->execute();
                        if ($stmt->get_result()->num_rows > 0) {
                            $stmt->close();
                            $errors[] = "Row $rowNum: Policy number '$policyNumber' already exists";
                            continue;
                        }
                        $stmt->close();

                        // money writes start here - atomic per row
                        $conn->begin_transaction();
                        $rowTxn = true;

                        // resolve branch
                        $branchId = 0;
                        if ($branchName) {
                            $stmt = $conn->prepare("SELECT id FROM branches WHERE name = ? AND is_active = 1 LIMIT 1");
                            $stmt->bind_param("s", $branchName);
                            $stmt->execute();
                            $br = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                            if ($br) $branchId = (int)$br['id'];
                        }
                        if (!$branchId) {
                            // use first active branch
                            $br = $conn->query("SELECT id FROM branches WHERE is_active=1 ORDER BY id LIMIT 1")->fetch_assoc();
                            $branchId = $br ? (int)$br['id'] : 0;
                        }
                        if (!$branchId) {
                            $errors[] = "Row $rowNum: No active branch found";
                            $conn->rollback(); $rowTxn = false;
                            continue;
                        }

                        // resolve buyer by company_code
                        $stmt = $conn->prepare("SELECT id, company_name FROM buyers WHERE company_code = ? AND is_active = 1 LIMIT 1");
                        $stmt->bind_param("s", $companyCode);
                        $stmt->execute();
                        $buyerRow = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if (!$buyerRow) {
                            $errors[] = "Row $rowNum: Buyer with code '$companyCode' not found";
                            $conn->rollback(); $rowTxn = false;
                            continue;
                        }
                        $buyerId = (int)$buyerRow['id'];
                        $companyName = $buyerRow['company_name'];

                        // auto-create customer if not exists
                        $stmt = $conn->prepare("SELECT id FROM customers WHERE mobile = ? LIMIT 1");
                        $stmt->bind_param("s", $customerMobile);
                        $stmt->execute();
                        $custRow = $stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        if ($custRow) {
                            $customerId = (int)$custRow['id'];
                        } else {
                            $stmt = $conn->prepare("INSERT INTO customers (name, mobile) VALUES (?, ?)");
                            $stmt->bind_param("ss", $customerName, $customerMobile);
                            $stmt->execute();
                            $customerId = $stmt->insert_id;
                            $stmt->close();
                        }

                        // auto-create vehicle if motor
                        $vehicleId = null;
                        if ($insCategory === 'motor') {
                            $stmt = $conn->prepare("SELECT id FROM vehicles WHERE vehicle_number = ? LIMIT 1");
                            $stmt->bind_param("s", $vehicleNumber);
                            $stmt->execute();
                            $vehRow = $stmt->get_result()->fetch_assoc();
                            $stmt->close();

                            if ($vehRow) {
                                $vehicleId = (int)$vehRow['id'];
                            } else {
                                $mkVal = $vehicleMake ?: null;
                                $mdVal = $vehicleModel ?: null;
                                $ftVal = $fuelType ?: null;
                                $stmt = $conn->prepare("INSERT INTO vehicles (vehicle_number, vehicle_type, make, model, fuel_type, customer_id) VALUES (?, ?, ?, ?, ?, ?)");
                                $stmt->bind_param("sssssi", $vehicleNumber, $vehicleCategory, $mkVal, $mdVal, $ftVal, $customerId);
                                $stmt->execute();
                                $vehicleId = $stmt->insert_id;
                                $stmt->close();
                            }
                        } else {
                            $vehicleNumber = null;
                            $vehicleCategory = null;
                        }

                        // resolve agent by code or name
                        $agentId = null;
                        $agentName = null;
                        if ($agentCode) {
                            $stmt = $conn->prepare("SELECT id, name FROM agents WHERE agent_code = ? AND is_active = 1 LIMIT 1");
                            $stmt->bind_param("s", $agentCode);
                            $stmt->execute();
                            $agtRow = $stmt->get_result()->fetch_assoc();
                            $stmt->close();

                            // fallback: match by name
                            if (!$agtRow) {
                                $stmt = $conn->prepare("SELECT id, name FROM agents WHERE LOWER(name) = LOWER(?) AND is_active = 1 LIMIT 1");
                                $stmt->bind_param("s", $agentCode);
                                $stmt->execute();
                                $agtRow = $stmt->get_result()->fetch_assoc();
                                $stmt->close();
                            }

                            if ($agtRow) {
                                $agentId = (int)$agtRow['id'];
                                $agentName = $agtRow['name'];
                            }
                        }

                        // agent must be in our list - no Company Direct fallback
                        if (!$agentId) {
                            $errors[] = "Row $rowNum: Agent '$agentCode' is not in the agent list — add the agent first, then re-upload";
                            $conn->rollback(); $rowTxn = false;
                            continue;
                        }

                        // calc commissions
                        $buyerCommAmt = 0;
                        switch ($buyerBasis) {
                            case 'premium_pct': $buyerCommAmt = $totalPremium * $buyerRate / 100; break;
                            case 'tp_pct': $buyerCommAmt = $tpAmount * $buyerRate / 100; break;
                            case 'od_pct': $buyerCommAmt = $odAmount * $buyerRate / 100; break;
                            case 'net_pct': $buyerCommAmt = $netPremium * $buyerRate / 100; break;
                            case 'fixed': $buyerCommAmt = $buyerRate; break;
                        }

                        $agentCommAmt = 0;
                        $agentBasisVal = null;
                        $agentRateVal = null;
                        if ($agentId && $agentBasis && in_array($agentBasis, $validBasis)) {
                            $agentBasisVal = $agentBasis;
                            $agentRateVal = $agentRate;
                            switch ($agentBasis) {
                                case 'premium_pct': $agentCommAmt = $totalPremium * $agentRate / 100; break;
                                case 'tp_pct': $agentCommAmt = $tpAmount * $agentRate / 100; break;
                                case 'od_pct': $agentCommAmt = $odAmount * $agentRate / 100; break;
                                case 'net_pct': $agentCommAmt = $netPremium * $agentRate / 100; break;
                                case 'fixed': $agentCommAmt = $agentRate; break;
                            }
                        }

                        // calc end date = start + 1 year - 1 day
                        $policyEnd = date('Y-m-d', strtotime($policyStart . ' +1 year -1 day'));
                        $entryDate = date('Y-m-d');
                        $status = 'active';
                        $isManual = 0;
                        // legacy single col mirrors whichever account is set; new cols hold both
                        $bankAccId = $payInBankId ?: ($payOutBankId ?: null);
                        $bankName = $bankAccId ? ($bankById[$bankAccId] ?? null) : null;
                        $payInBankIdVal = $payInBankId ?: null;
                        $payOutBankIdVal = $payOutBankId ?: null;
                        $payModeVal = $payMode ?: null;
                        $remarksValFinal = $remarksVal ?: null;

                        $stmt = $conn->prepare("INSERT INTO policy_entries (
                            entry_date, branch_id, vehicle_id, vehicle_number, vehicle_type,
                            customer_id, customer_name, agent_id, agent_name, buyer_id,
                            company_name, company_code, policy_number, total_premium_amount, tp_amount,
                            od_amount, net_premium_amount, buyer_commission_basis, buyer_commission_rate, buyer_commission_amount,
                            agent_commission_basis, agent_commission_rate, agent_commission_amount, is_commission_manual,
                            bank_account_id, bank_name, payment_in, payment_out, payment_in_bank_account_id, payment_out_bank_account_id, payment_mode,
                            policy_start_date, policy_end_date, status, remarks, created_by, insurance_category
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                        $stmt->bind_param(
                            "siissisisisssddddsddsddiisddiisssssis",
                            $entryDate, $branchId, $vehicleId, $vehicleNumber, $vehicleCategory,
                            $customerId, $customerName, $agentId, $agentName, $buyerId,
                            $companyName, $companyCode, $policyNumber, $totalPremium, $tpAmount,
                            $odAmount, $netPremium, $buyerBasis, $buyerRate, $buyerCommAmt,
                            $agentBasisVal, $agentRateVal, $agentCommAmt, $isManual,
                            $bankAccId, $bankName, $payIn, $payOut, $payInBankIdVal, $payOutBankIdVal, $payModeVal,
                            $policyStart, $policyEnd, $status, $remarksValFinal, $user_id, $insCategory
                        );

                        if (!$stmt->execute()) {
                            $errors[] = "Row $rowNum: " . $stmt->error;
                            $stmt->close();
                            $conn->rollback();
                            $rowTxn = false;
                            continue;
                        }
                        $newId = $stmt->insert_id;
                        $stmt->close();
                        savePolicyExtras($conn, $newId, $extras, null);

                        $z = 0.00;

                        // auto-post agent commission
                        if ($agentId && $agentCommAmt > 0) {
                            $cd = "Commission for $policyNumber";
                            $ct = 'commission';
                            $s = $conn->prepare("INSERT INTO agent_transactions (agent_id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, policy_entry_id, created_by) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
                            $s->bind_param("isssddii", $agentId, $entryDate, $cd, $ct, $agentCommAmt, $z, $newId, $user_id);
                            $s->execute();
                            $s->close();
                        }

                        // auto-post buyer commission
                        if ($buyerId && $buyerCommAmt > 0) {
                            $cd = "Commission for $policyNumber";
                            $ct = 'commission_receivable';
                            $s = $conn->prepare("INSERT INTO buyer_transactions (buyer_id, transaction_date, description, transaction_type, credit_amount, debit_amount, running_balance, policy_entry_id, created_by) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)");
                            $s->bind_param("isssddii", $buyerId, $entryDate, $cd, $ct, $buyerCommAmt, $z, $newId, $user_id);
                            $s->execute();
                            $s->close();
                        }

                        // auto-post payment_in bank txn -> payment-in account
                        if ($payInBankId && $payIn > 0) {
                            $bd = "Payment In - $policyNumber ($customerName)";
                            $bt = 'credit';
                            $s = $conn->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, description, transaction_type, amount, running_balance, policy_entry_id, category, created_by) VALUES (?, ?, ?, ?, ?, 0, ?, 'policy_payment_in', ?)");
                            $s->bind_param("isssdii", $payInBankId, $entryDate, $bd, $bt, $payIn, $newId, $user_id);
                            $s->execute();
                            $s->close();
                            $banksTouched[$payInBankId] = 1;
                        }

                        // auto-post payment_out bank txn -> payment-out account
                        if ($payOutBankId && $payOut > 0) {
                            $bd = "Payment Out - $policyNumber ($companyName)";
                            $bt = 'debit';
                            $s = $conn->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_date, description, transaction_type, amount, running_balance, policy_entry_id, category, created_by) VALUES (?, ?, ?, ?, ?, 0, ?, 'policy_payment_out', ?)");
                            $s->bind_param("isssdii", $payOutBankId, $entryDate, $bd, $bt, $payOut, $newId, $user_id);
                            $s->execute();
                            $s->close();
                            $banksTouched[$payOutBankId] = 1;
                        }

                        // recompute balances from ledgers
                        if ($agentId && $agentCommAmt > 0) recalcAgentBalances($conn, $agentId);
                        if ($buyerId && $buyerCommAmt > 0) recalcBuyerBalances($conn, $buyerId);

                        // notify admins + assigned agent (inside row txn)
                        notifyPolicyAdded($conn, $newId, $policyNumber, $agentId, $agentName);

                        $conn->commit();
                        $rowTxn = false;
                        $inserted++;
                    } catch (Exception $ex) {
                        if ($rowTxn) { $conn->rollback(); $rowTxn = false; }
                        $errors[] = "Row $rowNum: " . $ex->getMessage();
                    }
                }

                // recompute each affected bank account once (rows already committed)
                foreach (array_keys($banksTouched) as $bk) recalcBankBalance($conn, $bk);

                logActivity($user_id, $username, 'Bulk Policy Upload', "Uploaded $inserted policies, " . count($errors) . " errors");
                $conn->close();

                $msg = "$inserted policies uploaded successfully.";
                if (count($errors) > 0) {
                    $msg .= " " . count($errors) . " row(s) had errors.";
                }
                echo json_encode(['success' => true, 'message' => $msg, 'inserted' => $inserted, 'errors' => $errors]);
                exit();

            case 'deleteDocument':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    echo json_encode(['success' => false, 'message' => 'Invalid request']);
                    exit();
                }
                if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'Invalid request']); exit; }
                if (!$canDelete) {
                    echo json_encode(['success' => false, 'message' => 'Access denied']);
                    exit();
                }

                $docId = (int)($_POST['id'] ?? 0);
                if ($docId <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid document ID']);
                    exit();
                }

                // get file path
                $stmt = $conn->prepare("SELECT file_path, file_name, policy_entry_id FROM policy_documents WHERE id = ?");
                $stmt->bind_param("i", $docId);
                $stmt->execute();
                $doc = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$doc) {
                    $conn->close();
                    echo json_encode(['success' => false, 'message' => 'Document not found']);
                    exit();
                }

                // delete file
                $fullPath = __DIR__ . '/' . $doc['file_path'];
                if (file_exists($fullPath)) unlink($fullPath);

                $stmt = $conn->prepare("DELETE FROM policy_documents WHERE id = ?");
                $stmt->bind_param("i", $docId);
                $stmt->execute();
                $stmt->close();

                logActivity($user_id, $username, 'Document Deleted', "Deleted doc #$docId ({$doc['file_name']}) from policy #{$doc['policy_entry_id']}");
                $conn->close();
                echo json_encode(['success' => true, 'message' => 'Document deleted successfully']);
                exit();

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit();
        }
    } catch (Exception $e) {
        error_log("policy_entries.php error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
        exit();
    }
}

// one-time backfill: old "direct customer" policies (agent_id null/0) get the default agent
// idempotent - only touches unassigned rows, nothing left to do once clean
if ($isAdmin) {
    try {
        $mc = getDBConnection();
        $orphan = $mc->query("SELECT COUNT(*) c FROM policy_entries WHERE agent_id IS NULL OR agent_id = 0")->fetch_assoc();
        if ($orphan && (int)$orphan['c'] > 0) {
            $defAid = ensureDefaultAgent($mc);
            $da = $mc->query("SELECT name FROM agents WHERE id = " . (int)$defAid . " LIMIT 1")->fetch_assoc();
            $defName = $da ? $da['name'] : 'Company Direct';
            $up = $mc->prepare("UPDATE policy_entries SET agent_id = ?, agent_name = ? WHERE agent_id IS NULL OR agent_id = 0");
            $up->bind_param("is", $defAid, $defName);
            $up->execute();
            $up->close();
        }
        $mc->close();
    } catch (Exception $e) {
        error_log("policy_entries.php agent backfill error: " . $e->getMessage());
    }
}
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
    <link rel="icon" href="<?php echo htmlspecialchars(getBranding()['site_logo']); ?>"><!-- tab icon, stops the browser asking for /favicon.ico -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Policy Entries - Insurance Management System</title>

    <!-- CDN Dependencies -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.1/dist/css/tabulator.min.css">
    <link rel="stylesheet" href="styles.css?v=16.0">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="app.js?v=2.3"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.1/dist/js/tabulator.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
</head>
<body>
<script>/* theme before paint */(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme:dark)').matches))document.body.classList.add('dark-mode');})();</script>
    <?php echo getSearchableDropdownJS(); ?>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <?php
            $pageIcon    = 'fa-file-shield';
            $pageTitle   = $isCustomer ? 'My & Family Policies' : 'Policy Entries';
            $crumbs      = [];
            $pageActions = [];
            if ($canCreate && !$isCustomer) {
                $pageActions[] = ['label' => 'Upload & Scan', 'icon' => 'fa-file-arrow-up', 'onclick' => "window.location='policy_upload.php'", 'class' => 'primary'];
                $pageActions[] = ['label' => 'Add Policy', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()'];
                $pageActions[] = ['label' => 'Bulk Upload', 'icon' => 'fa-file-excel', 'onclick' => 'openUploadModal()'];
            }
            $pageActions[] = ['label' => 'CSV', 'icon' => 'fa-file-csv', 'onclick' => "dtBtn(policiesTable,'csv')"];
            $pageActions[] = ['label' => 'PDF',     'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(policiesTable,'pdf')"];
            $pageActions[] = ['label' => 'Print',   'icon' => 'fa-print',    'onclick' => "dtBtn(policiesTable,'print')"];
            if ($canDelete && !$isCustomer) $pageActions[] = ['label' => 'Trash', 'icon' => 'fa-trash-can-arrow-up', 'onclick' => 'openTrash()'];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate',   'onclick' => 'loadPolicies()'];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="policySearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="policySearch" placeholder="Search policies…" autocomplete="off"></div>
                <div class="hf-field hf-range" title="Entry date range"><label for="filterDateFrom"><i class="fas fa-calendar-days"></i></label><input type="date" id="filterDateFrom" aria-label="From"><span class="hf-sep"><i class="fas fa-arrow-right-long"></i></span><input type="date" id="filterDateTo" aria-label="To"></div>
                <div class="hf-seg" role="group" aria-label="Quick range"><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',0,this)">Today</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',6,this)">7 days</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',29,this)">30 days</button></div>
                <div class="hf-field" title="Vertical"><label for="filterCategory"><i class="fas fa-layer-group"></i></label>
                    <select id="filterCategory" class="searchable">
                        <option value="">All Verticals</option>
                        <option value="motor">Motor Insurance</option>
                        <option value="health">Health Insurance</option>
                        <option value="life">Life Insurance</option>
                    </select>
                </div>
                <div class="hf-field" title="Status"><label for="filterStatus"><i class="fas fa-flag"></i></label>
                    <select id="filterStatus" class="searchable">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="expired">Expired</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <?php if ($isCustomer): ?>
                <div class="hf-field" title="Scope"><label for="filterScope"><i class="fas fa-users"></i></label>
                    <select id="filterScope" class="searchable">
                        <option value="">All (Self &amp; Family)</option>
                        <option value="self">My Policies (Self)</option>
                        <option value="family">Family Members</option>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (!$isCustomer): ?>
                <div class="hf-field" title="Insurance Company"><label for="filterBuyer"><i class="fas fa-building-shield"></i></label>
                    <select id="filterBuyer" class="searchable">
                        <option value="">All Insurers</option>
                    </select>
                </div>
                <div class="hf-field" title="Branch"><label for="filterBranch"><i class="fas fa-code-branch"></i></label>
                    <select id="filterBranch" class="searchable">
                        <option value="">All Branches</option>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (!$isAgent && !$isCustomer): ?>
                <div class="hf-field" title="Agent"><label for="filterAgent"><i class="fas fa-user-tie"></i></label>
                    <select id="filterAgent" class="searchable">
                        <option value="">All Agents</option>
                    </select>
                </div>
                <?php endif; ?>
                <button type="button" class="btn btn-icon" onclick="clearFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>

            <div class="data-section">
                <div id="drillNote" class="text-muted" style="display:none;font-size:13px;margin-bottom:10px;"></div>

                <!-- Loading Skeleton -->
                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.4"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.3"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.3"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.3"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.9"></div>
                        </div>
                        <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="skeleton-table-row">
                            <div class="skeleton skeleton-table-cell" style="flex: 0.4"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.5"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.3"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.3"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1.3"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.8"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 1"></div>
                            <div class="skeleton skeleton-table-cell" style="flex: 0.9"></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- table -->
                <div id="tableContainer" style="display: none;">
                    <div id="tableInner">
                        <div id="policiesTable"></div>
                    </div>
                    <div id="tableEmpty" style="display:none;"></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canCreate || $canEdit): ?>
    <!-- Add/Edit Policy Modal -->
    <div class="modal-overlay" id="policyModal">
        <div class="modal modal-wide" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus-circle"></i> Add Policy</h3>
                <button class="close-btn" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="policyForm">
                    <input type="hidden" id="policyId" name="id">

                    <!-- Entry Details -->
                    <div class="form-grid-3">
                        <div class="form-section-title"><i class="fas fa-info-circle"></i> Entry Details</div>
                        <div class="form-group">
                            <label><i class="fas fa-layer-group"></i> Insurance Vertical *</label>
                            <select id="fInsuranceCategory" name="insurance_category" class="searchable" onchange="onCategorySwitch(this.value)" required>
                                <option value="motor">Motor Insurance</option>
                                <option value="health">Health Insurance / Mediclaim</option>
                                <option value="life">Life Insurance</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Entry Date *</label>
                            <input type="date" id="fEntryDate" name="entry_date" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-code-branch"></i> Branch *</label>
                            <select id="fBranch" name="branch_id" class="searchable" required>
                                <option value="">Select Branch</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-flag"></i> Status</label>
                            <select id="fStatus" name="status" class="searchable">
                                <option value="active">Active</option>
                                <option value="expired">Expired</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="form-group form-full-width" id="cancelReasonGroup">
                            <label><i class="fas fa-ban"></i> Cancellation Reason *</label>
                            <input type="text" id="fCancelReason" name="cancellation_reason" maxlength="255" placeholder="Why was this policy cancelled?">
                        </div>
                    </div>

                    <!-- Motor Vehicle Details (Motor Only) -->
                    <div class="form-grid" id="motorVehicleSection">
                        <div class="form-section-title"><i class="fas fa-car"></i> Motor Vehicle Details</div>

                        <div class="form-group form-full-width">
                            <div class="toggle-switch">
                                <input type="checkbox" id="fNewVehicle" class="toggle" onchange="toggleVehicleMode()">
                                <label for="fNewVehicle">Add new vehicle (not in list)</label>
                            </div>
                        </div>

                        <div class="form-group" id="vehicleSelectGroup">
                            <label><i class="fas fa-car-side"></i> Vehicle *</label>
                            <select id="fVehicle" name="vehicle_id" class="searchable">
                                <option value="">Select Vehicle</option>
                            </select>
                        </div>
                        <div class="form-group" id="vehicleNumberReadonlyGroup">
                            <label><i class="fas fa-hashtag"></i> Vehicle Number</label>
                            <input type="text" id="fVehicleNumber" readonly>
                        </div>
                        <div class="form-group" id="vehicleTypeReadonlyGroup">
                            <label><i class="fas fa-truck"></i> Vehicle Category</label>
                            <input type="text" id="fVehicleType" readonly>
                        </div>

                        <div class="form-group" id="vehicleNewNumberGroup" style="display:none;">
                            <label><i class="fas fa-hashtag"></i> Vehicle Number *</label>
                            <input type="text" id="fNewVehicleNumber" name="new_vehicle_number" placeholder="e.g. GJ01AB1234" style="text-transform:uppercase;">
                        </div>
                        <div class="form-group" id="vehicleNewCategoryGroup" style="display:none;">
                            <label><i class="fas fa-truck"></i> Vehicle Category *</label>
                            <select id="fNewVehicleCategory" name="new_vehicle_category" class="searchable">
                                <option value="">Select</option>
                                <?php echo vehicleCategoryOptionsHtml(); ?>
                            </select>
                            <?php if ($isAdmin): ?>
                            <button type="button" class="btn-link-add" onclick="quickAddCategory('fNewVehicleCategory')"><i class="fas fa-plus"></i> New category</button>
                            <?php endif; ?>
                        </div>
                        <div class="form-group" id="vehicleNewMakeGroup" style="display:none;">
                            <label><i class="fas fa-industry"></i> Vehicle Make</label>
                            <input type="text" id="fNewVehicleMake" name="new_vehicle_make" placeholder="e.g. Maruti, Honda">
                        </div>
                        <div class="form-group" id="vehicleNewModelGroup" style="display:none;">
                            <label><i class="fas fa-car"></i> Vehicle Model</label>
                            <input type="text" id="fNewVehicleModel" name="new_vehicle_model" placeholder="e.g. Swift, City">
                        </div>
                    </div>

                    <!-- Health Insurance Details (Health Only) -->
                    <div class="form-grid" id="healthDetailsSection" style="display:none;">
                        <div class="form-section-title"><i class="fas fa-heart-pulse"></i> Health Insurance Details</div>
                        <div class="form-group">
                            <label><i class="fas fa-file-medical"></i> Plan / Policy Name *</label>
                            <input type="text" id="fPlanName" name="plan_name" placeholder="e.g. Optima Secure, Star Comprehensive">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-shield-heart"></i> Sum Insured (Coverage) *</label>
                            <input type="number" id="fSumInsured" name="sum_insured" step="1000" min="0" placeholder="e.g. 500000">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hospital-user"></i> Policy Type</label>
                            <select id="fHealthPolicyType" name="health_policy_type" class="searchable">
                                <option value="individual">Individual</option>
                                <option value="floater">Family Floater</option>
                                <option value="senior">Senior Citizen</option>
                                <option value="top_up">Top-Up / Super Top-Up</option>
                                <option value="critical_illness">Critical Illness</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-users"></i> Covered Members Count</label>
                            <input type="number" id="fMembersCount" name="members_count" min="1" max="25" value="1">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hand-holding-medical"></i> TPA (Third Party Admin)</label>
                            <input type="text" id="fTpaName" name="tpa_name" placeholder="e.g. Medi Assist, Paramount, In-House">
                        </div>
                        <div class="form-group form-full-width">
                            <label><i class="fas fa-id-card"></i> Insured Members Details (Name, Age, Relation)</label>
                            <textarea id="fMembersDetails" name="members_details" rows="2" placeholder="e.g. Self (35), Spouse (32), Child (6)"></textarea>
                        </div>
                        <div class="form-group form-full-width">
                            <label><i class="fas fa-notes-medical"></i> Pre-Existing Diseases (PED) / Notes</label>
                            <textarea id="fPedDetails" name="ped_details" rows="2" placeholder="e.g. None / Diabetes / Hypertension"></textarea>
                        </div>
                    </div>

                    <!-- Life Insurance Details (Life Only) -->
                    <div class="form-grid" id="lifeDetailsSection" style="display:none;">
                        <div class="form-section-title"><i class="fas fa-user-shield"></i> Life Insurance Details</div>
                        <div class="form-group">
                            <label><i class="fas fa-award"></i> Plan Type *</label>
                            <select id="fLifePlanType" name="life_plan_type" class="searchable">
                                <option value="term">Term Life Insurance</option>
                                <option value="endowment">Endowment Plan</option>
                                <option value="ulip">ULIP (Unit Linked)</option>
                                <option value="money_back">Money Back Plan</option>
                                <option value="whole_life">Whole Life Plan</option>
                                <option value="annuity">Annuity / Pension Plan</option>
                                <option value="child">Child Plan</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hand-holding-dollar"></i> Sum Assured (Life Cover) *</label>
                            <input type="number" id="fSumAssured" name="sum_assured" step="1000" min="0" placeholder="e.g. 10000000">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hourglass-half"></i> Policy Term (Years)</label>
                            <input type="number" id="fPolicyTerm" name="policy_term" min="1" max="100" placeholder="e.g. 30">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check"></i> Premium Paying Term (PPT - Years)</label>
                            <input type="number" id="fPremiumPayingTerm" name="premium_paying_term" min="1" max="100" placeholder="e.g. 10">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-clock"></i> Payment Frequency</label>
                            <select id="fPaymentFrequency" name="payment_frequency" class="searchable">
                                <option value="yearly">Yearly / Annual</option>
                                <option value="half_yearly">Half-Yearly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="monthly">Monthly</option>
                                <option value="single">Single Premium</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-tag"></i> Nominee Name</label>
                            <input type="text" id="fNomineeName" name="nominee_name" placeholder="Nominee full name">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-heart"></i> Nominee Relation</label>
                            <input type="text" id="fNomineeRelation" name="nominee_relation" placeholder="e.g. Spouse, Father, Mother, Child">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-cake-candles"></i> Nominee DOB</label>
                            <input type="date" id="fNomineeDob" name="nominee_dob">
                        </div>
                    </div>

                    <!-- Customer Details (All Verticals) -->
                    <div class="form-grid">
                        <div class="form-section-title"><i class="fas fa-user"></i> Customer / Policyholder Details</div>

                        <div class="form-group form-full-width">
                            <div class="toggle-switch">
                                <input type="checkbox" id="fNewCustomer" class="toggle" onchange="toggleCustomerMode()">
                                <label for="fNewCustomer">Add new customer (not in list)</label>
                            </div>
                        </div>

                        <div class="form-group" id="customerSelectGroup">
                            <label><i class="fas fa-user"></i> Customer *</label>
                            <select id="fCustomer" name="customer_id" class="searchable" required>
                                <option value="">Select Customer</option>
                            </select>
                        </div>
                        <div class="form-group" id="customerNewNameGroup" style="display:none;">
                            <label><i class="fas fa-user"></i> Customer Name *</label>
                            <input type="text" id="fNewCustomerName" name="new_customer_name" placeholder="Enter customer name">
                        </div>
                        <div class="form-group" id="customerNewMobileGroup" style="display:none;">
                            <label><i class="fas fa-phone"></i> Customer Mobile *</label>
                            <input type="text" id="fNewCustomerMobile" name="new_customer_mobile" placeholder="Enter mobile number">
                        </div>
                    </div>

                    <!-- Agent -->
                    <div class="form-grid">
                        <div class="form-section-title"><i class="fas fa-user-tie"></i> Agent</div>
                        <div class="form-group">
                            <label><i class="fas fa-user-tie"></i> Agent *</label>
                            <select id="fAgent" name="agent_id" class="searchable" required>
                                <option value="">Select Agent</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-id-badge"></i> Agent Name</label>
                            <input type="text" id="fAgentName" readonly>
                        </div>
                    </div>

                    <!-- Insurance Company -->
                    <div class="form-grid">
                        <div class="form-section-title"><i class="fas fa-building-shield"></i> Insurance Company</div>
                        <div class="form-group">
                            <label><i class="fas fa-building-shield"></i> Insurance Provider *</label>
                            <select id="fBuyer" name="buyer_id" class="searchable" required>
                                <option value="">Select Insurance Provider</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-file-contract"></i> Policy Number *</label>
                            <input type="text" id="fPolicyNumber" name="policy_number" required placeholder="e.g. NIC-2025-001">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check"></i> Policy Start Date *</label>
                            <input type="date" id="fPolicyStart" name="policy_start_date" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-times"></i> Policy End Date *</label>
                            <input type="date" id="fPolicyEnd" name="policy_end_date" required>
                        </div>
                        <div class="form-group motor-only-field" id="policyTypeGroup">
                            <label><i class="fas fa-shield-halved"></i> Motor Policy Type</label>
                            <select id="fPolicyType" name="policy_type" class="searchable">
                                <option value="">Select</option>
                                <option value="comprehensive">Comprehensive</option>
                                <option value="third_party">Third Party</option>
                                <option value="own_damage">Own Damage</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-briefcase"></i> Business Type</label>
                            <select id="fBusinessType" name="business_type" class="searchable">
                                <option value="">Select</option>
                                <option value="new">New</option>
                                <option value="renewal">Renewal</option>
                                <option value="rollover">Rollover</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-file-signature"></i> Proposal Number</label>
                            <input type="text" id="fProposalNumber" name="proposal_number" maxlength="50" placeholder="e.g. PRP-2025-001">
                        </div>
                        <div class="form-group" id="prevPolicyGroup">
                            <label><i class="fas fa-link"></i> Renewal Of</label>
                            <input type="hidden" id="fPrevPolicyId" name="previous_policy_id">
                            <input type="text" id="fPrevPolicyNo" readonly>
                        </div>
                    </div>

                    <!-- Premium Details -->
                    <div class="form-grid">
                        <div class="form-section-title"><i class="fas fa-money-bill-wave"></i> Premium Details</div>
                        <div class="form-group">
                            <label><i class="fas fa-coins"></i> Total Premium *</label>
                            <input type="number" id="fTotalPremium" name="total_premium_amount" required step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group motor-only-field" id="tpAmountGroup">
                            <label><i class="fas fa-shield-alt"></i> TP Amount</label>
                            <input type="number" id="fTpAmount" name="tp_amount" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group motor-only-field" id="odAmountGroup">
                            <label><i class="fas fa-car-crash"></i> OD Amount</label>
                            <input type="number" id="fOdAmount" name="od_amount" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-receipt"></i> Net Premium *</label>
                            <input type="number" id="fNetPremium" name="net_premium_amount" required step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-file-invoice-dollar"></i> GST Amount</label>
                            <input type="number" id="fGstAmount" name="gst_amount" step="0.01" min="0" placeholder="Total − Net">
                        </div>
                        <div class="form-group motor-only-field" id="idvGroup">
                            <label><i class="fas fa-car"></i> IDV <small>(insured value)</small></label>
                            <input type="number" id="fIdv" name="idv" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group motor-only-field" id="ncbGroup">
                            <label><i class="fas fa-percent"></i> NCB %</label>
                            <input type="number" id="fNcb" name="ncb_percent" step="0.01" min="0" max="100" placeholder="0">
                        </div>
                    </div>

                    <!-- Commissions (Hidden: Not a sales brokerage platform) -->
                    <div id="buyerCommSection" style="display:none;">
                        <input type="hidden" id="fBuyerBasis" name="buyer_commission_basis" value="fixed">
                        <input type="hidden" id="fBuyerRate" name="buyer_commission_rate" value="0.00">
                        <input type="hidden" id="fBuyerCommAmt" name="buyer_commission_amount" value="0.00">
                    </div>
                    <div id="agentCommSection" style="display:none;">
                        <input type="hidden" id="fAgentBasis" name="agent_commission_basis" value="fixed">
                        <input type="hidden" id="fAgentRate" name="agent_commission_rate" value="0.00">
                        <input type="hidden" id="fAgentCommAmt" name="agent_commission_amount" value="0.00">
                        <input type="hidden" id="fIsManual" name="is_commission_manual" value="0">
                    </div>

                    <!-- Payment -->
                    <div class="form-grid">
                        <div class="form-section-title"><i class="fas fa-credit-card"></i> Payment</div>
                        <div class="form-group">
                            <label><i class="fas fa-wallet"></i> Payment Mode</label>
                            <select id="fPaymentMode" name="payment_mode" class="searchable">
                                <option value="">Select</option>
                                <option value="Cash">Cash</option>
                                <option value="Cheque">Cheque</option>
                                <option value="NEFT">NEFT</option>
                                <option value="UPI">UPI</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-arrow-down"></i> Payment In <small>(received)</small></label>
                            <input type="number" id="fPaymentIn" name="payment_in" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-university"></i> Payment In Account <small>(deposited to)</small></label>
                            <select id="fPaymentInBank" name="payment_in_bank_account_id" class="searchable">
                                <option value="">Select Account</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-arrow-up"></i> Payment Out <small>(paid)</small></label>
                            <input type="number" id="fPaymentOut" name="payment_out" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-university"></i> Payment Out Account <small>(paid from)</small></label>
                            <select id="fPaymentOutBank" name="payment_out_bank_account_id" class="searchable">
                                <option value="">Select Account</option>
                            </select>
                        </div>
                    </div>

                    <!-- Remarks -->
                    <div class="form-grid">
                        <div class="form-section-title"><i class="fas fa-comment-alt"></i> Remarks</div>
                        <div class="form-group form-full-width">
                            <label><i class="fas fa-sticky-note"></i> Remarks</label>
                            <textarea id="fRemarks" name="remarks" rows="3" placeholder="Optional notes..."></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- View Policy Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal modal-wide" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Policy Details</h3>
                <button class="close-btn" onclick="closeViewModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <!-- populated by JS -->
            </div>
            <div class="form-actions" id="viewModalFooter">
                <button type="button" class="btn btn-primary" id="viewPrintBtn"><i class="fas fa-print"></i> Print</button>
                <button type="button" class="btn btn-success btn-ico-phone" id="viewWaBtn" title="Send a renewal reminder on WhatsApp" aria-label="WhatsApp reminder"><i class="fab fa-whatsapp"></i> <span>Reminder</span></button>
                <?php if ($canCreate): ?>
                <button type="button" class="btn btn-success" id="viewRenewBtn"><i class="fas fa-redo"></i> Renew</button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button>
            </div>
        </div>
    </div>

    <?php if ($canDelete): ?>
    <!-- Trash: deleted policies (restore / delete forever) -->
    <div class="modal-overlay" id="trashModal">
        <div class="modal modal-wide" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-trash-can-arrow-up"></i> Policy Trash <span class="text-muted" id="trashCount"></span></h3>
                <button class="close-btn" onclick="closeTrash()" aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body"><div id="trashTable"></div></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($canCreate): ?>
    <!-- Bulk Upload Modal -->
    <div class="modal-overlay" id="uploadModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="fas fa-file-excel"></i> Bulk Upload Policies</h3>
                <button class="close-btn" onclick="closeUploadModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom:16px;">
                    <p style="color:var(--text-muted);margin-bottom:10px;">Upload an Excel (.xlsx) or CSV file with policy data. Customers & vehicles are auto-created if not found. <strong>Agent</strong> must already exist in your Agents list — rows with an unknown or blank agent are skipped (we don't do direct sales). <strong>Payment In Bank</strong> / <strong>Payment Out Bank</strong> = the bank <em>account number</em> (or <code>Bank Name - AccountNumber</code>); a plain bank name is rejected when several of your accounts share it. Leave blank for no bank movement. Optional: <strong>policy_type</strong> (comprehensive / third_party / own_damage), <strong>business_type</strong> (new / renewal / rollover), <strong>previous_policy_number</strong> (an existing policy this one renews), <strong>proposal_number</strong>, <strong>idv</strong>, <strong>gst_amount</strong> (blank = total − net premium), <strong>ncb_percent</strong> (0–100).</p>
                    <button class="btn btn-secondary btn-sm" onclick="downloadTemplate()">
                        <i class="fas fa-download"></i> Download Template
                    </button>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-file-upload"></i> Select File *</label>
                    <input type="file" id="bulkFile" accept=".xlsx,.xls,.csv" class="file-input">
                </div>
                <div id="uploadPreview" style="display:none;margin-top:16px;">
                    <h4 style="color:var(--navy-primary);margin-bottom:8px;"><i class="fas fa-table"></i> Preview (<span id="previewCount">0</span> rows)</h4>
                    <div style="overflow-x:auto;max-height:300px;overflow-y:auto;border:1px solid var(--border-color);border-radius:4px;">
                        <table class="preview-table" id="previewTable"></table>
                    </div>
                </div>
                <div id="uploadErrors" style="display:none;margin-top:12px;"></div>
                <div class="form-actions" style="margin-top:16px;">
                    <button class="btn btn-success" id="btnProcessUpload" onclick="processUpload()" disabled>
                        <i class="fas fa-upload"></i> Upload & Process
                    </button>
                    <button class="btn btn-secondary" onclick="closeUploadModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    /** Developed by Rudees Digital */
    const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
    const IS_AGENT = <?php echo $isAgent ? 'true' : 'false'; ?>;
    const IS_CUSTOMER = <?php echo $isCustomer ? 'true' : 'false'; ?>;
    const CAN_CREATE = <?php echo ($canCreate && !$isCustomer) ? 'true' : 'false'; ?>;
    var POLICY_TYPE_LBL = { comprehensive: 'Comprehensive', third_party: 'Third Party', own_damage: 'Own Damage' };
    var POLICY_TYPE_SHORT = { comprehensive: 'Comp', third_party: 'TP', own_damage: 'OD' };
    var BUSINESS_TYPE_LBL = { 'new': 'New', renewal: 'Renewal', rollover: 'Rollover' };
    const CAN_EDIT = <?php echo ($canEdit && !$isCustomer) ? 'true' : 'false'; ?>;
    const CAN_DELETE = <?php echo ($canDelete && !$isCustomer) ? 'true' : 'false'; ?>;

    let policiesTable = null;
    let policiesData = [];
    let ddData = {}; // dropdown data
    let isEditMode = false;

    $(document).ready(function() {
        loadDropdowns();
        loadPolicies();
        // header search
        $('#policySearch').on('input', function() { clearTimeout(this._t); var v = this.value; this._t = setTimeout(function() { rsTbSearch(policiesTable, v); }, 200); });

        // single delegated action-hub handler - bound on the table el, survives redraws
        $('#policiesTable').on('click', '[data-act]', function() {
            var act = $(this).data('act'), id = $(this).data('id');
            if (act === 'view') viewPolicy(id);
            else if (act === 'renew' && typeof renewPolicy === 'function') renewPolicy(id);
            else if (act === 'print') printPolicy(id);
            else if (act === 'pdf') printPolicy(id, 'pdf');
            else if (act === 'edit' && typeof editPolicy === 'function') editPolicy(id);
            else if (act === 'documents') viewPolicy(id, 'documents');
            else if (act === 'endorse') viewPolicy(id, 'endorse');
            else if (act === 'claims') viewPolicy(id, 'claims');
            else if (act === 'wa') waReminder(id);
            else if (act === 'delete' && typeof deletePolicy === 'function') deletePolicy(id);
        });
    });

    // expiring-soon predicate flag
    var ddExpirySoon = false;

    // read drill-down url params and reflect into UI filters / modal
    function applyDrillDownFilters() {
        var qs = new URLSearchParams(window.location.search);
        var custId = qs.get('customer_id'), vehId = qs.get('vehicle_id'), agtId = qs.get('agent_id');
        var status = (qs.get('status') || '').toLowerCase();
        var expiry = (qs.get('expiry') || '').toLowerCase();
        var openId = qs.get('id');

        // ?expiry=expired is just a status=expired alias
        if (expiry === 'expired') status = 'expired';

        var hasFilter = custId || vehId || agtId || status || expiry === 'soon';
        if (!hasFilter && !openId) return;

        // server already scoped getPolicies to customer/vehicle/agent - just label those
        var note = [];
        if (custId) {
            var c = (ddData.customers || []).find(function(x){ return x.id == custId; });
            note.push('Customer: ' + (c ? c.name : '#' + custId));
        }
        if (vehId) {
            var v = (ddData.vehicles || []).find(function(x){ return x.id == vehId; });
            note.push('Vehicle: ' + (v ? v.vehicle_number : '#' + vehId));
        }
        if (agtId) {
            var a = (ddData.agents || []).find(function(x){ return x.id == agtId; });
            if (a && !IS_AGENT) { $('#filterAgent').val(a.name); var el = document.getElementById('filterAgent'); if (el && el.sdRefresh) el.sdRefresh(); }
            note.push('Agent: ' + (a ? a.name : '#' + agtId));
        }

        // ?status= / ?expiry=expired - drive the existing status dropdown
        if (status === 'active' || status === 'expired' || status === 'cancelled') {
            var sel = document.getElementById('filterStatus');
            if (sel) { sel.value = status; if (sel.sdRefresh) sel.sdRefresh(); }
            $('#filterStatus').val(status);
            note.push('Status: ' + status.charAt(0).toUpperCase() + status.slice(1));
        }

        // ?expiry=soon - active policies expiring within 30 days
        if (expiry === 'soon') {
            ddExpirySoon = true;
            note.push('Expiring Soon');
        }

        if (hasFilter) {
            applyFilters();
            $('#drillNote').html('<i class="fas fa-link"></i> Filtered &mdash; ' + note.join(' | ')
                + ' <a href="policy_entries.php">(show all)</a>').show();
        }

        // ?id=N - open the view modal for that policy if it's in the result set
        if (openId) {
            var found = (policiesData || []).some(function(p){ return p.id == openId; });
            if (found && typeof viewPolicy === 'function') viewPolicy(openId);
        }
    }

    // load all dropdown data
    function loadDropdowns() {
        $.ajax({
            url: '?action=getDropdowns',
            method: 'GET',
            dataType: 'json',
            success: function(res) {
                if (!res.success) return;
                ddData = res.data;
                populateDropdowns();
            }
        });
    }

    function populateDropdowns() {
        // filter dropdowns
        var fb = $('#filterBuyer');
        fb.find('option:not(:first)').remove();
        ddData.buyers.forEach(function(b) {
            fb.append('<option value="' + b.company_name + '">' + b.company_name + ' (' + b.company_code + ')</option>');
        });

        // branch filter
        var fbr = $('#filterBranch');
        fbr.find('option:not(:first)').remove();
        ddData.branches.forEach(function(b) {
            fbr.append('<option value="' + b.name + '">' + b.name + '</option>');
        });

        if (!IS_AGENT) {
            var fa = $('#filterAgent');
            fa.find('option:not(:first)').remove();
            ddData.agents.forEach(function(a) {
                fa.append('<option value="' + a.name + '">' + a.agent_code + ' - ' + a.name + '</option>');
            });
        }

        // modal dropdowns
        if (CAN_CREATE || CAN_EDIT) {
            // branches
            var sel = $('#fBranch');
            sel.find('option:not(:first)').remove();
            ddData.branches.forEach(function(b) {
                sel.append('<option value="' + b.id + '">' + b.name + '</option>');
            });

            // vehicles
            sel = $('#fVehicle');
            sel.find('option:not(:first)').remove();
            ddData.vehicles.forEach(function(v) {
                var label = v.vehicle_number + ' - ' + (v.make || '') + ' ' + (v.model || '');
                sel.append('<option value="' + v.id + '" data-cid="' + v.customer_id + '" data-vnum="' + v.vehicle_number + '" data-vtype="' + v.vehicle_type + '">' + label.trim() + '</option>');
            });

            // customers
            sel = $('#fCustomer');
            sel.find('option:not(:first)').remove();
            ddData.customers.forEach(function(c) {
                sel.append('<option value="' + c.id + '">' + c.name + ' - ' + c.mobile + '</option>');
            });

            // agents
            sel = $('#fAgent');
            sel.find('option:not(:first)').remove();
            ddData.agents.forEach(function(a) {
                sel.append('<option value="' + a.id + '">' + a.agent_code + ' - ' + a.name + '</option>');
            });

            // buyers
            sel = $('#fBuyer');
            sel.find('option:not(:first)').remove();
            ddData.buyers.forEach(function(b) {
                sel.append('<option value="' + b.id + '">' + b.company_name + ' (' + b.company_code + ')</option>');
            });

            // bank accounts - separate in/out selectors
            var bankOpts = ddData.bankAccounts.map(function(b) {
                return '<option value="' + b.id + '">' + b.bank_name + ' - ' + b.account_number + '</option>';
            }).join('');
            $('#fPaymentInBank, #fPaymentOutBank').each(function() {
                $(this).find('option:not(:first)').remove();
                $(this).append(bankOpts);
            });
        }
        setTimeout(function(){ initAllSearchableDropdowns(); }, 100);
    }

    function loadPolicies() {
        $('#loadingSkeleton').show();
        $('#tableContainer').hide();
        $.ajax({
            url: '?action=getPolicies',
            method: 'GET',
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    policiesData = res.data;
                    $('#loadingSkeleton').hide();
                    $('#tableContainer').show();
                    initDataTable(res.data);
                } else {
                    $('#loadingSkeleton').hide();
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load policies' });
                }
            },
            error: function() {
                $('#loadingSkeleton').hide();
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
            }
        });
    }

    var fmtAmt = function(d) { return Number(d||0).toLocaleString('en-IN', {minimumFractionDigits: 2}); };

    // days from today until a YYYY-MM-DD date (negative = past)
    function daysToExpiry(endDate) {
        if (!endDate) return null;
        var end = new Date(endDate + 'T00:00:00');
        if (isNaN(end)) return null;
        var today = new Date();
        today.setHours(0,0,0,0);
        return Math.round((end - today) / 86400000);
    }

    function initDataTable(data) {
        // 0 rows -> empty state, skip the grid
        if (!data || data.length === 0) {
            if (policiesTable) rsTbData(policiesTable, []);
            $('#tableInner').hide();
            $('#tableEmpty').html(emptyStateHtml({
                icon: 'fa-file-contract',
                title: 'No Policies Found',
                text: CAN_CREATE ? 'Add your first policy to get started.' : 'No policies match the current view.',
                btnText: CAN_CREATE ? 'Add Policy' : '',
                btnId: CAN_CREATE ? 'emptyAddPolicy' : ''
            })).show();
            $('#emptyAddPolicy').off('click').on('click', openAddModal);
            return;
        }
        $('#tableEmpty').hide().empty();
        $('#tableInner').show();
        if (policiesTable) return rsTbData(policiesTable, data); // build once, swap rows

        var basisLbl = {'premium_pct':'Premium %','tp_pct':'TP %','od_pct':'OD %','net_pct':'Net %','fixed':'Fixed'};

        var cols = [];
        if (!IS_CUSTOMER) {
            cols.push({
                data: 'id', title: '<input type="checkbox" class="bulk-check-all">',
                orderable: false, searchable: false, className: 'bulk-col',
                render: function(d) { return '<input type="checkbox" class="bulk-check" data-id="' + d + '">'; }
            });
        }
        cols.push({ data: 'id', title: 'ID' });
        cols.push({
            data: 'insurance_category', title: 'Vertical', name: 'insurance_category',
            render: function(d, type) {
                var cat = (d || 'motor').toLowerCase();
                var lbl = cat === 'health' ? 'Health' : (cat === 'life' ? 'Life' : 'Motor');
                var chip = cat === 'health' ? 'chip-emerald' : (cat === 'life' ? 'chip-purple' : 'chip-blue');
                var ico = cat === 'health' ? 'fa-heart-pulse' : (cat === 'life' ? 'fa-user-shield' : 'fa-car');
                if (type !== 'display') return lbl;
                return '<span class="status-badge ' + chip + '"><i class="fas ' + ico + '"></i> ' + lbl + '</span>';
            }
        });
        cols.push({ data: 'entry_date', title: 'Date' });
        cols.push({
            data: 'policy_number', title: 'Policy#',
            render: function(d) { return '<span class="badge-code">' + escapeHtml(d) + '</span>'; }
        });
        cols.push({ data: 'business_type', title: 'Business Type', visible: false, render: function(d){ return BUSINESS_TYPE_LBL[d] || '-'; } });
        cols.push({ data: 'proposal_number', title: 'Proposal#', visible: false, render: function(d){ return d ? escapeHtml(d) : '-'; } });
        cols.push({
            data: 'vehicle_number', title: 'Vehicle / Plan',
            render: function(d, type, row) {
                if (row && row.insurance_category === 'health') {
                    var pName = row.plan_name || 'Health Plan';
                    var si = row.sum_insured ? ' (' + fmtAmt(row.sum_insured) + ')' : '';
                    return '<span class="badge-code" title="Sum Insured: ' + (row.sum_insured ? fmtAmt(row.sum_insured) : '-') + '"><i class="fas fa-heart-pulse"></i> ' + escapeHtml(pName + si) + '</span>';
                } else if (row && row.insurance_category === 'life') {
                    var lType = (row.life_plan_type || 'Life').toUpperCase();
                    var sa = row.sum_assured ? ' (' + fmtAmt(row.sum_assured) + ')' : '';
                    return '<span class="badge-code" title="Sum Assured: ' + (row.sum_assured ? fmtAmt(row.sum_assured) : '-') + '"><i class="fas fa-user-shield"></i> ' + escapeHtml(lType + sa) + '</span>';
                }
                return d ? escapeHtml(d) : '-';
            }
        });
        cols.push({ data: 'vehicle_type', title: 'Category', visible: false, render: function(d){ return escapeHtml(d); } });
        cols.push({
            data: 'customer_name', title: IS_CUSTOMER ? 'Insured Member' : 'Customer',
            render: function(d, type, row){
                var name = escapeHtml(d);
                if (IS_CUSTOMER && row) {
                    var badge = row.is_personal ? '<span class="status-badge status-active" style="font-size:10px;margin-left:5px;padding:2px 6px;">Self</span>' : '<span class="status-badge status-user" style="font-size:10px;margin-left:5px;padding:2px 6px;">Family</span>';
                    return name + badge;
                }
                return name;
            }
        });
        cols.push({ data: 'company_name', title: 'Insurer', name: 'company_name', render: function(d){ return escapeHtml(d); } });
        if (!IS_CUSTOMER) {
            cols.push({ data: 'company_code', title: 'Company Code', visible: false, render: function(d){ return escapeHtml(d); } });
            cols.push({ data: 'branch_name', title: 'Branch', name: 'branch_name', visible: false, render: function(d){ return d?escapeHtml(d):'-'; } });
        }
        cols.push({
            data: 'total_premium_amount', title: 'Premium',
            render: function(d) {
                var cls = d > 0 ? 'wallet-positive' : 'wallet-zero';
                return '<span class="wallet-amount ' + cls + '">' + fmtAmt(d) + '</span>';
            }
        });
        cols.push({ data: 'tp_amount', title: 'TP Amount', visible: false, render: function(d){ return fmtAmt(d); } });
        cols.push({ data: 'od_amount', title: 'OD Amount', visible: false, render: function(d){ return fmtAmt(d); } });
        cols.push({ data: 'net_premium_amount', title: 'Net Premium', visible: false, render: function(d){ return fmtAmt(d); } });
        cols.push({ data: 'gst_amount', title: 'GST', visible: false, render: function(d){ return fmtAmt(d); } });
        cols.push({ data: 'idv', title: 'IDV', visible: false, render: function(d){ return d === null || d === '' ? '-' : fmtAmt(d); } });
        cols.push({ data: 'ncb_percent', title: 'NCB %', visible: false, render: function(d){ return d === null || d === '' ? '-' : (+d) + '%'; } });

        if (!IS_CUSTOMER) {
            cols.push({ data: 'buyer_commission_basis', title: 'Buyer Comm Basis', visible: false, render: function(d){ return basisLbl[d]||d||'-'; } });
            cols.push({ data: 'buyer_commission_rate', title: 'Buyer Comm Rate', visible: false });
            cols.push({
                data: 'buyer_commission_amount', title: 'Buyer Comm', visible: false,
                render: function(d){ return '<span class="wallet-amount wallet-positive">' + fmtAmt(d) + '</span>'; }
            });
            cols.push({
                data: 'agent_name', title: 'Agent', name: 'agent_name',
                render: function(d) { return d ? escapeHtml(d) : '<span class="text-muted">Direct</span>'; }
            });
            cols.push({
                data: 'agent_commission_amount', title: 'Agent Comm', visible: false,
                render: function(d){ return '<span class="wallet-amount wallet-positive">' + fmtAmt(d) + '</span>'; }
            });
            cols.push({ data: 'payment_mode', title: 'Payment Mode', visible: false, render: function(d){ return d?escapeHtml(d):'-'; } });
            cols.push({ data: 'bank_name', title: 'Bank', visible: false, render: function(d){ return d?escapeHtml(d):'-'; } });
            cols.push({ data: 'payment_in', title: 'Payment In', visible: false, render: function(d){ return fmtAmt(d); } });
            cols.push({ data: 'payment_out', title: 'Payment Out', visible: false, render: function(d){ return fmtAmt(d); } });
        }
        cols.push({ data: 'policy_start_date', title: 'Start Date', visible: false });
        cols.push({ data: 'policy_end_date', title: 'Expiry Date' });
        cols.push({
            title: 'Days to Expiry', field: '_days', minWidth: 60, download: false,
            sorter: function(a, b, ra, rb) { return daysSort(ra.getData()) - daysSort(rb.getData()); },
            formatter: function(cell) {
                var row = cell.getRow().getData(), n = daysToExpiry(row.policy_end_date);
                if (n === null) return '-';
                if (row.status === 'cancelled') return '<span class="text-muted">-</span>';
                if (n < 0) return '<span class="wallet-amount wallet-negative">Expired ' + Math.abs(n) + 'd</span>';
                if (n <= 30) return '<span class="wallet-amount wallet-negative">' + n + ' days</span>';
                return '<span class="wallet-amount wallet-positive">' + n + ' days</span>';
            }
        });
        cols.push({
            data: 'status', title: 'Status', name: 'status',
            render: function(d) {
                var s = escapeHtml(d || '');
                return '<span class="policy-status status-' + s + '">' + (s ? s.charAt(0).toUpperCase() + s.slice(1) : '') + '</span>';
            }
        });
        cols.push({
            data: 'policy_type', title: 'Type', name: 'policy_type',
            render: function(d, type) {
                var l = POLICY_TYPE_LBL[d] || '';
                if (type !== 'display') return l;
                return l ? '<span class="status-badge status-user" title="' + l + '">' + POLICY_TYPE_SHORT[d] + '</span>' : '<span class="text-muted">-</span>';
            }
        });
        cols.push({ data: 'cancellation_reason', title: 'Cancellation Reason', visible: false, render: function(d){ return d ? escapeHtml(d) : '-'; } });
        cols.push({ data: 'remarks', title: 'Remarks', visible: false, render: function(d){ return escapeHtml(d); } });
        cols.push({ data: 'created_at', title: 'Added On' });
        cols.push({
            title: 'Actions', field: '_act', headerSort: false, download: false, responsive: 0, minWidth: IS_CUSTOMER ? 120 : 140,
            hozAlign: 'right', headerHozAlign: 'right', resizable: false, widthGrow: 0,
            formatter: function(cell) {
                var r = cell.getRow().getData();
                var id = r.id;
                var h = '<div class="action-hub">';
                h += '<button class="action-icon view-icon" data-act="view" data-id="' + id + '" title="View Policy Details"><i class="fas fa-eye"></i></button>';
                if (IS_CUSTOMER) {
                    h += '<button class="action-icon print-icon" data-act="pdf" data-id="' + id + '" title="Download Policy PDF"><i class="fas fa-file-pdf"></i></button>';
                    if (r.status === 'active') {
                        h += '<a class="action-icon" href="claims.php?new=1&policy_id=' + id + '" title="File Claim" style="color:var(--danger,#dc2626);"><i class="fas fa-car-burst"></i></a>';
                    }
                } else {
                    if (CAN_CREATE) {
                        h += '<button class="action-icon edit-icon" data-act="renew" data-id="' + id + '" title="Renew"><i class="fas fa-redo"></i></button>';
                    }
                    h += '<div class="more-menu"><button class="more-btn" title="More"><i class="fas fa-ellipsis-v"></i></button><div class="more-dropdown">';
                    h += '<button class="more-item" data-act="print" data-id="' + id + '"><i class="fas fa-print"></i> Print</button>';
                    h += '<button class="more-item" data-act="pdf" data-id="' + id + '"><i class="fas fa-file-pdf"></i> Download PDF</button>';
                    if (CAN_EDIT) {
                        h += '<button class="more-item" data-act="edit" data-id="' + id + '"><i class="fas fa-pen"></i> Edit</button>';
                    }
                    h += '<button class="more-item" data-act="documents" data-id="' + id + '"><i class="fas fa-folder-open"></i> Documents</button>';
                    h += '<button class="more-item" data-act="wa" data-id="' + id + '"><i class="fab fa-whatsapp"></i> Renewal reminder</button>';
                    h += '<button class="more-item" data-act="endorse" data-id="' + id + '"><i class="fas fa-file-signature"></i> Endorsements</button>';
                    h += '<button class="more-item" data-act="claims" data-id="' + id + '"><i class="fas fa-car-burst"></i> Claims</button>';
                    if (CAN_DELETE) {
                        h += '<div class="more-divider"></div>';
                        h += '<button class="more-item danger" data-act="delete" data-id="' + id + '"><i class="fas fa-trash"></i> Delete</button>';
                    }
                    h += '</div></div>';
                }
                h += '</div>';
                return h;
            }
        });

        policiesTable = rsTable('#policiesTable', {
            columns: cols, data: data, title: IS_CUSTOMER ? 'My & Family Policies' : 'Policy Entries', file: 'policy_entries', sort: ['id', 'desc'],
            rowClass: policyRowClass, empty: 'No policies match the current filters',
            info: function() {
                var f = [['From', $('#filterDateFrom').val()], ['To', $('#filterDateTo').val()], ['Status', $('#filterStatus').val()]];
                if (!IS_CUSTOMER) {
                    f.push(['Buyer', $('#filterBuyer').val()], ['Branch', $('#filterBranch').val()]);
                    if (!IS_AGENT) f.push(['Agent', $('#filterAgent').val()]);
                } else {
                    f.push(['Scope', $('#filterScope').val()]);
                }
                return f.filter(function(x) { return x[1]; });
            }
        });

        $('#filterDateFrom, #filterDateTo, #filterCategory, #filterStatus, #filterBuyer, #filterBranch, #filterScope').off('change').on('change', applyFilters);
        if (!IS_AGENT && !IS_CUSTOMER) $('#filterAgent').off('change').on('change', applyFilters);

        setupBulkSelect();
        applyDrillDownFilters();
        rsTbSearch(policiesTable, $('#policySearch').val()); // keep header search
    }

    // null end date sorts last
    function daysSort(r) { var n = daysToExpiry(r.policy_end_date); return n === null ? 999999 : n; }

    // row tint by status / expiry
    function policyRowClass(r) {
        if (r.status === 'cancelled') return 'dt-row-muted';
        if (r.status === 'expired') return 'dt-row-danger';
        var n = daysToExpiry(r.policy_end_date);
        if (r.status !== 'active' || n === null) return '';
        return n < 0 ? 'dt-row-danger' : (n <= 30 ? 'dt-row-warning' : '');
    }

    function applyFilters() {
        if (!policiesTable) return;
        var dateFrom = $('#filterDateFrom').val(), dateTo = $('#filterDateTo').val(), category = $('#filterCategory').val(), status = $('#filterStatus').val();
        var buyer = $('#filterBuyer').val(), branch = $('#filterBranch').val(), agent = (!IS_AGENT && !IS_CUSTOMER) ? $('#filterAgent').val() : '';
        var lo = dateFrom ? new Date(dateFrom) : null, hi = dateTo ? new Date(dateTo + 'T23:59:59') : null;

        // date range on entry_date
        rsTbWhere(policiesTable, 'date', lo || hi ? function(r) {
            if (!r.entry_date) return true;
            var d = new Date(r.entry_date);
            return !(lo && d < lo) && !(hi && d > hi);
        } : null);
        // expiring-soon drill-down - active, 0-30 days to expiry
        rsTbWhere(policiesTable, 'soon', ddExpirySoon ? function(r) {
            var n = daysToExpiry(r.policy_end_date);
            return r.status === 'active' && n !== null && n >= 0 && n <= 30;
        } : null);
        rsTbWhere(policiesTable, 'category', category ? function(r) { return (r.insurance_category || 'motor') === category; } : null);
        rsTbWhere(policiesTable, 'status', status ? function(r) { return r.status === status; } : null);
        rsTbWhere(policiesTable, 'buyer', buyer ? function(r) { return r.company_name === buyer; } : null);
        rsTbWhere(policiesTable, 'branch', branch ? function(r) { return r.branch_name === branch; } : null);
        rsTbWhere(policiesTable, 'agent', agent ? function(r) { return r.agent_name === agent; } : null);
        if (IS_CUSTOMER) {
            var scope = $('#filterScope').val();
            rsTbWhere(policiesTable, 'scope', scope ? function(r) {
                return scope === 'self' ? !!r.is_personal : !r.is_personal;
            } : null);
        }
    }

    function clearFilters() {
        $('#filterDateFrom, #filterDateTo, #policySearch').val('');
        $('.hf-seg .is-on').removeClass('is-on');
        $('#filterCategory, #filterStatus, #filterBuyer, #filterBranch, #filterScope').val('');
        if (!IS_AGENT && !IS_CUSTOMER) $('#filterAgent').val('');
        // resync searchable dropdown display
        ['filterCategory', 'filterStatus', 'filterBuyer', 'filterBranch', 'filterAgent', 'filterScope'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) { el.value = ''; if (el.sdRefresh) el.sdRefresh(); }
        });
        ddExpirySoon = false; // drop expiring-soon drill-down
        $('#drillNote').hide().empty();
        rsTbClear(policiesTable);
    }

    // commission calc
    function calcCommission(basis, rate, premiums) {
        var total = premiums.total || 0, tp = premiums.tp || 0, od = premiums.od || 0, net = premiums.net || 0;
        switch (basis) {
            case 'premium_pct': return total * rate / 100;
            case 'tp_pct': return tp * rate / 100;
            case 'od_pct': return od * rate / 100;
            case 'net_pct': return net * rate / 100;
            case 'fixed': return rate;
            default: return 0;
        }
    }

    function getPremiums() {
        return {
            total: parseFloat($('#fTotalPremium').val()) || 0,
            tp: parseFloat($('#fTpAmount').val()) || 0,
            od: parseFloat($('#fOdAmount').val()) || 0,
            net: parseFloat($('#fNetPremium').val()) || 0
        };
    }

    function recalcBuyerComm() {
        var basis = $('#fBuyerBasis').val();
        var rate = parseFloat($('#fBuyerRate').val()) || 0;
        var amt = calcCommission(basis, rate, getPremiums());
        $('#fBuyerCommAmt').val(amt.toFixed(2));
    }

    function recalcAgentComm() {
        if ($('#fIsManual').is(':checked')) return; // manual override
        var basis = $('#fAgentBasis').val();
        var rate = parseFloat($('#fAgentRate').val()) || 0;
        var amt = calcCommission(basis, rate, getPremiums());
        $('#fAgentCommAmt').val(amt.toFixed(2));
    }

    <?php if ($canCreate || $canEdit): ?>

    // vehicle change -> auto-fill customer + fields
    $(document).on('change', '#fVehicle', function() {
        var opt = $(this).find(':selected');
        var cid = opt.data('cid');
        var vnum = opt.data('vnum') || '';
        var vtype = opt.data('vtype') || '';

        $('#fVehicleNumber').val(vnum);
        $('#fVehicleType').val(vtype);

        if (cid) {
            $('#fCustomer').val(cid);
        }
    });

    // agent change -> show/hide agent commission, fill name
    $(document).on('change', '#fAgent', function() {
        var agentId = $(this).val();
        if (agentId) {
            var agent = ddData.agents.find(function(a) { return a.id == agentId; });
            $('#fAgentName').val(agent ? agent.name : '');
            $('#agentCommSection').show();
        } else {
            $('#fAgentName').val('');
            $('#agentCommSection').hide();
        }
    });

    // manual override toggle
    $(document).on('change', '#fIsManual', function() {
        if ($(this).is(':checked')) {
            $('#fAgentCommAmt').removeClass('calculated-input').prop('readonly', false);
        } else {
            $('#fAgentCommAmt').addClass('calculated-input').prop('readonly', false);
            recalcAgentComm();
        }
    });

    // auto-set expiry = start + 1 year - 1 day
    $(document).on('change', '#fPolicyStart', function() {
        var sd = $(this).val();
        if (!sd) return;
        var d = new Date(sd);
        d.setFullYear(d.getFullYear() + 1);
        d.setDate(d.getDate() - 1);
        var y = d.getFullYear(), m = String(d.getMonth()+1).padStart(2,'0'), dd = String(d.getDate()).padStart(2,'0');
        $('#fPolicyEnd').val(y + '-' + m + '-' + dd);
    });

    // commission recalc triggers
    $(document).on('input change', '#fTotalPremium, #fTpAmount, #fOdAmount, #fNetPremium, #fBuyerBasis, #fBuyerRate', recalcBuyerComm);
    $(document).on('input change', '#fTotalPremium, #fTpAmount, #fOdAmount, #fNetPremium, #fAgentBasis, #fAgentRate', recalcAgentComm);

    // gst suggestion = total - net until the user types their own
    var gstAuto = true;
    function suggestGst() {
        if (!gstAuto) return;
        var t = parseFloat($('#fTotalPremium').val()), n = parseFloat($('#fNetPremium').val());
        $('#fGstAmount').val(isNaN(t) || isNaN(n) ? '' : Math.max(0, t - n).toFixed(2));
    }
    $(document).on('input change', '#fTotalPremium, #fNetPremium', suggestGst);
    $(document).on('input', '#fGstAmount', function() { gstAuto = this.value === ''; });

    // cancel reason only for cancelled
    function toggleCancelReason() {
        var on = $('#fStatus').val() === 'cancelled';
        $('#cancelReasonGroup').toggle(on);
        $('#fCancelReason').prop('required', on);
        if (!on) $('#fCancelReason').val('');
    }
    $(document).on('change', '#fStatus', toggleCancelReason);

    // renewal link - shown only when set; "new" drops it
    function setPrevPolicy(id, no) {
        $('#fPrevPolicyId').val(id || '');
        $('#fPrevPolicyNo').val(no || '');
        $('#prevPolicyGroup').toggle(!!id);
    }
    $(document).on('change', '#fBusinessType', function() { if (this.value === 'new') setPrevPolicy(); });

    function onCategorySwitch(cat) {
        cat = (cat || 'motor').toLowerCase();
        var isMotor = (cat === 'motor');
        var isHealth = (cat === 'health');
        var isLife = (cat === 'life');

        $('#motorVehicleSection').toggle(isMotor);
        $('.motor-only-field').toggle(isMotor);
        $('#healthDetailsSection').toggle(isHealth);
        $('#lifeDetailsSection').toggle(isLife);

        if (isMotor) {
            toggleVehicleMode();
        } else {
            $('#fVehicle').prop('required', false);
            $('#fNewVehicleNumber').prop('required', false);
        }

        $('#fPlanName').prop('required', isHealth);
        $('#fSumInsured').prop('required', isHealth);
        $('#fSumAssured').prop('required', isLife);

        setTimeout(function() {
            ['fInsuranceCategory', 'fHealthPolicyType', 'fLifePlanType', 'fPaymentFrequency'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el && el.sdRefresh) el.sdRefresh();
            });
        }, 50);
    }

    // fill / reset the extra fields
    function fillExtras(p) {
        p = p || {};
        var cat = (p.insurance_category || 'motor').toLowerCase();
        $('#fInsuranceCategory').val(cat);
        onCategorySwitch(cat);

        // Motor extras
        $('#fPolicyType').val(p.policy_type || '');
        $('#fBusinessType').val(p.business_type || '');
        $('#fProposalNumber').val(p.proposal_number || '');
        $('#fIdv').val(p.idv === null || p.idv === undefined ? '' : p.idv);
        $('#fNcb').val(p.ncb_percent === null || p.ncb_percent === undefined ? '' : p.ncb_percent);
        $('#fCancelReason').val(p.cancellation_reason || '');
        setPrevPolicy(p.previous_policy_id, p.previous_policy_number);

        // Health fields
        $('#fPlanName').val(p.plan_name || '');
        $('#fSumInsured').val(p.sum_insured === null || p.sum_insured === undefined ? '' : p.sum_insured);
        $('#fHealthPolicyType').val(p.health_policy_type || 'individual');
        $('#fMembersCount').val(p.members_count || 1);
        $('#fTpaName').val(p.tpa_name || '');
        $('#fMembersDetails').val(p.members_details || '');
        $('#fPedDetails').val(p.ped_details || '');

        // Life fields
        $('#fLifePlanType').val(p.life_plan_type || 'term');
        $('#fSumAssured').val(p.sum_assured === null || p.sum_assured === undefined ? '' : p.sum_assured);
        $('#fPolicyTerm').val(p.policy_term || '');
        $('#fPremiumPayingTerm').val(p.premium_paying_term || '');
        $('#fPaymentFrequency').val(p.payment_frequency || 'yearly');
        $('#fNomineeName').val(p.nominee_name || '');
        $('#fNomineeRelation').val(p.nominee_relation || '');
        $('#fNomineeDob').val(p.nominee_dob || '');

        var t = parseFloat(p.total_premium_amount), n = parseFloat(p.net_premium_amount), g = parseFloat(p.gst_amount);
        // legacy rows hold 0 -> treat as unset and suggest
        gstAuto = !p.id || isNaN(g) || g === 0 || Math.abs(g - Math.max(0, t - n)) < 0.005;
        if (gstAuto) suggestGst(); else $('#fGstAmount').val(g.toFixed(2));
        toggleCancelReason();
        ['fInsuranceCategory', 'fPolicyType', 'fBusinessType', 'fStatus', 'fHealthPolicyType', 'fLifePlanType', 'fPaymentFrequency'].forEach(function(i) {
            var el = document.getElementById(i);
            if (el && el.sdRefresh) el.sdRefresh();
        });
    }

    function toggleVehicleMode() {
        var isNew = $('#fNewVehicle').is(':checked');
        if (isNew) {
            $('#vehicleSelectGroup, #vehicleNumberReadonlyGroup, #vehicleTypeReadonlyGroup').hide();
            $('#vehicleNewNumberGroup, #vehicleNewCategoryGroup, #vehicleNewMakeGroup, #vehicleNewModelGroup').show();
            $('#fVehicle').prop('required', false).val('');
        } else {
            $('#vehicleSelectGroup, #vehicleNumberReadonlyGroup, #vehicleTypeReadonlyGroup').show();
            $('#vehicleNewNumberGroup, #vehicleNewCategoryGroup, #vehicleNewMakeGroup, #vehicleNewModelGroup').hide();
            $('#fVehicle').prop('required', true);
            $('#fNewVehicleNumber').val('');
        }
    }

    function toggleCustomerMode() {
        var isNew = $('#fNewCustomer').is(':checked');
        if (isNew) {
            $('#customerSelectGroup').hide();
            $('#customerNewNameGroup, #customerNewMobileGroup').show();
            $('#fCustomer').prop('required', false).val('');
        } else {
            $('#customerSelectGroup').show();
            $('#customerNewNameGroup, #customerNewMobileGroup').hide();
            $('#fCustomer').prop('required', true);
            $('#fNewCustomerName, #fNewCustomerMobile').val('');
        }
    }

    function openAddModal() {
        if (!CAN_CREATE) {
            Swal.fire({ icon: 'warning', title: 'Restricted', text: 'You do not have permission to add policies.' });
            return;
        }
        isEditMode = false;
        $('#modalTitle').html('<i class="fas fa-plus-circle"></i> Add Policy');
        $('#policyForm')[0].reset();
        $('#policyId').val('');
        $('#fEntryDate').val(new Date().toISOString().split('T')[0]);
        $('#fStatus').val('active');
        $('#fInsuranceCategory').val('motor');
        $('#fVehicleNumber, #fVehicleType, #fAgentName').val('');
        $('#agentCommSection').hide();
        $('#fAgentCommAmt').addClass('calculated-input');
        $('#fNewVehicle, #fNewCustomer').prop('checked', false);
        toggleVehicleMode();
        toggleCustomerMode();
        fillExtras({ insurance_category: 'motor' });
        if (IS_AGENT && ddData && ddData.agents && ddData.agents.length > 0) {
            var myAg = ddData.agents[0];
            $('#fAgent').val(myAg.id);
            $('#fAgentName').val(myAg.name);
        }
        $('#policyModal').addClass('active');
        setTimeout(function(){
            document.querySelectorAll('#policyModal select.searchable').forEach(function(s){ if (s.sdRefresh) s.sdRefresh(); });
        }, 50);
    }

    function editPolicy(id) {
        showLoading('Loading...');

        $.ajax({
            url: '?action=getPolicy&id=' + id,
            method: 'GET',
            dataType: 'json',
            success: function(res) {
                hideLoading();
                if (!res.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                    return;
                }
                var p = res.data;
                isEditMode = true;
                // reset new-entry toggles for edit
                $('#fNewVehicle, #fNewCustomer').prop('checked', false);
                toggleVehicleMode();
                toggleCustomerMode();
                $('#modalTitle').html('<i class="fas fa-edit"></i> Edit Policy');
                $('#policyId').val(p.id);
                $('#fEntryDate').val(p.entry_date);
                $('#fBranch').val(p.branch_id);
                $('#fVehicle').val(p.vehicle_id);
                $('#fCustomer').val(p.customer_id);
                $('#fVehicleNumber').val(p.vehicle_number);
                $('#fVehicleType').val(p.vehicle_type);
                $('#fAgent').val(p.agent_id || '');
                $('#fAgentName').val(p.agent_name || '');
                $('#fBuyer').val(p.buyer_id);
                $('#fPolicyNumber').val(p.policy_number);
                $('#fPolicyStart').val(p.policy_start_date);
                $('#fPolicyEnd').val(p.policy_end_date);
                $('#fTotalPremium').val(p.total_premium_amount);
                $('#fTpAmount').val(p.tp_amount);
                $('#fOdAmount').val(p.od_amount);
                $('#fNetPremium').val(p.net_premium_amount);
                $('#fBuyerBasis').val(p.buyer_commission_basis);
                $('#fBuyerRate').val(p.buyer_commission_rate);
                $('#fBuyerCommAmt').val(p.buyer_commission_amount);
                $('#fStatus').val(p.status);

                // agent commission
                if (p.agent_id) {
                    $('#agentCommSection').show();
                    $('#fAgentBasis').val(p.agent_commission_basis || '');
                    $('#fAgentRate').val(p.agent_commission_rate || '');
                    $('#fAgentCommAmt').val(p.agent_commission_amount || 0);
                    var manual = parseInt(p.is_commission_manual);
                    $('#fIsManual').prop('checked', !!manual);
                    if (manual) {
                        $('#fAgentCommAmt').removeClass('calculated-input');
                    } else {
                        $('#fAgentCommAmt').addClass('calculated-input');
                    }
                } else {
                    $('#agentCommSection').hide();
                }

                $('#fPaymentInBank').val(p.payment_in_bank_account_id || p.bank_account_id || '');
                $('#fPaymentOutBank').val(p.payment_out_bank_account_id || p.bank_account_id || '');
                $('#fPaymentMode').val(p.payment_mode || '');
                $('#fPaymentIn').val(p.payment_in);
                $('#fPaymentOut').val(p.payment_out);
                $('#fRemarks').val(p.remarks || '');
                fillExtras(p);

                document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
                $('#policyModal').addClass('active');
            },
            error: function() { hideLoading(); Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    }

    // renew - opens Add modal prefilled from source policy, fresh dates + blank policy#
    function renewPolicy(id) {
        showLoading('Loading...');
        $.ajax({
            url: '?action=getPolicy&id=' + id,
            method: 'GET',
            dataType: 'json',
            success: function(res) {
                hideLoading();
                if (!res.success) { Swal.fire({ icon: 'error', title: 'Error', text: res.message }); return; }
                var p = res.data;

                // start fresh as a NEW policy
                isEditMode = false;
                $('#modalTitle').html('<i class="fas fa-redo"></i> Renew Policy');
                $('#policyForm')[0].reset();
                $('#policyId').val('');
                $('#fNewVehicle, #fNewCustomer').prop('checked', false);
                toggleVehicleMode();
                toggleCustomerMode();

                // carry over vehicle/customer/buyer/agent/branch + commission setup
                $('#fEntryDate').val(new Date().toISOString().split('T')[0]);
                $('#fBranch').val(p.branch_id);
                $('#fVehicle').val(p.vehicle_id);
                $('#fCustomer').val(p.customer_id);
                $('#fVehicleNumber').val(p.vehicle_number);
                $('#fVehicleType').val(p.vehicle_type);
                $('#fAgent').val(p.agent_id || '');
                $('#fAgentName').val(p.agent_name || '');
                $('#fBuyer').val(p.buyer_id);
                $('#fBuyerBasis').val(p.buyer_commission_basis);
                $('#fBuyerRate').val(p.buyer_commission_rate);
                $('#fStatus').val('active');

                // fresh policy number - user confirms/edits
                $('#fPolicyNumber').val('');

                // new term: old end + 1 day -> +1 year
                var newStart = '', newEnd = '';
                if (p.policy_end_date) {
                    var s = new Date(p.policy_end_date + 'T00:00:00');
                    s.setDate(s.getDate() + 1);
                    var e = new Date(s);
                    e.setFullYear(e.getFullYear() + 1);
                    e.setDate(e.getDate() - 1);
                    var fmt = function(d){ return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); };
                    newStart = fmt(s); newEnd = fmt(e);
                }
                $('#fPolicyStart').val(newStart);
                $('#fPolicyEnd').val(newEnd);

                // carry premium amounts as a starting point
                $('#fTotalPremium').val(p.total_premium_amount);
                $('#fTpAmount').val(p.tp_amount);
                $('#fOdAmount').val(p.od_amount);
                $('#fNetPremium').val(p.net_premium_amount);

                // agent commission
                if (p.agent_id) {
                    $('#agentCommSection').show();
                    $('#fAgentBasis').val(p.agent_commission_basis || '');
                    $('#fAgentRate').val(p.agent_commission_rate || '');
                    var manual = parseInt(p.is_commission_manual);
                    $('#fIsManual').prop('checked', !!manual);
                    if (manual) {
                        $('#fAgentCommAmt').val(p.agent_commission_amount || 0).removeClass('calculated-input');
                    } else {
                        $('#fAgentCommAmt').addClass('calculated-input');
                    }
                } else {
                    $('#agentCommSection').hide();
                }

                // carry payment setup
                $('#fPaymentInBank').val(p.payment_in_bank_account_id || p.bank_account_id || '');
                $('#fPaymentOutBank').val(p.payment_out_bank_account_id || p.bank_account_id || '');
                $('#fPaymentMode').val(p.payment_mode || '');
                $('#fRemarks').val('Renewal of ' + (p.policy_number || ''));
                // link to the old policy - saved as previous_policy_id
                fillExtras({
                    insurance_category: p.insurance_category || 'motor',
                    previous_policy_id: p.id,
                    previous_policy_number: p.policy_number,
                    business_type: 'renewal',
                    policy_type: p.policy_type,
                    plan_name: p.plan_name,
                    sum_insured: p.sum_insured,
                    health_policy_type: p.health_policy_type,
                    members_count: p.members_count,
                    members_details: p.members_details,
                    ped_details: p.ped_details,
                    tpa_name: p.tpa_name,
                    life_plan_type: p.life_plan_type,
                    sum_assured: p.sum_assured,
                    policy_term: p.policy_term,
                    premium_paying_term: p.premium_paying_term,
                    payment_frequency: p.payment_frequency,
                    nominee_name: p.nominee_name,
                    nominee_relation: p.nominee_relation,
                    nominee_dob: p.nominee_dob
                });

                recalcBuyerComm();
                recalcAgentComm();

                document.querySelectorAll('select.searchable').forEach(function(s) { if (s.sdRefresh) s.sdRefresh(); });
                $('#viewModal').removeClass('active');
                $('#policyModal').addClass('active');
                Swal.fire({ icon: 'info', title: 'Renewal Ready', text: 'Enter a new policy number, review the details, then Save.', timer: 2600, showConfirmButton: false });
            },
            error: function() { hideLoading(); Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    }

    function closeModal() {
        $('#policyModal').removeClass('active');
        $('#policyForm')[0].reset();
    }

    document.getElementById('policyModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // form submit
    $('#policyForm').on('submit', function(e) {
        e.preventDefault();

        // every policy must be assigned to an agent
        if (!$('#fAgent').val()) {
            Swal.fire({ icon: 'warning', title: 'Agent Required', text: 'Please select an agent — every policy must be assigned to an agent.' }).then(function() {
                var ag = document.getElementById('fAgent');
                if (!ag) return;
                // focus the searchable wrapper's visible input if present, else the select
                var inp = ag.previousElementSibling && ag.previousElementSibling.querySelector
                    ? ag.previousElementSibling.querySelector('input') : null;
                (inp || ag).focus();
            });
            return;
        }

        var formData = new FormData(this);
        var action = isEditMode ? 'updatePolicy' : 'addPolicy';

        // checkbox not in FormData when unchecked
        if (!$('#fIsManual').is(':checked')) {
            formData.delete('is_commission_manual');
        }
        formData.append('csrf_token', window.CSRF_TOKEN);

        showLoading('Processing...');

        $.ajax({
            url: '?action=' + action,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                hideLoading();
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Success!', text: res.message, timer: 2000, showConfirmButton: false });
                    closeModal();
                    setTimeout(loadPolicies, 100);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error: function(xhr, status, err) {
                hideLoading();
                Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error: ' + err });
            }
        });
    });

    <?php endif; ?>

    function deletePolicy(id) {
        Swal.fire({
            icon: 'warning',
            title: 'Move policy to Trash?',
            text: 'Its commission and bank entries are reversed. An admin can restore it from Trash.',
            showCancelButton: true,
            confirmButtonColor: '#ea4335',
            confirmButtonText: 'Move to Trash',
            cancelButtonText: 'Cancel'
        }).then(function(result) {
            if (!result.isConfirmed) return;

            var fd = new FormData();
            fd.append('id', id);
            fd.append('csrf_token', window.CSRF_TOKEN);

            showLoading('Deleting...');
            $.ajax({
                url: '?action=deletePolicy',
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    hideLoading();
                    if (res.success) {
                        Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                        setTimeout(loadPolicies, 100);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                    }
                },
                error: function() { hideLoading(); Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    // whatsapp renewal reminder — server builds the text + logs it, opens in a new tab (sync click = no popup block)
    function waReminder(id, kind) {
        window.open('?action=waReminder&id=' + parseInt(id, 10) + (kind === 'due' ? '&kind=due' : ''), '_blank', 'noopener');
    }

    var END_TYPES = [['owner_transfer', 'Ownership transfer'], ['vehicle_details', 'Vehicle details change'], ['addon_cover', 'Add-on cover'], ['hypothecation', 'Hypothecation (loan)'], ['correction', 'Correction'], ['other', 'Other']];
    var CLAIM_ST = { intimated: ['Intimated', 'status-user'], survey: ['Survey', 'status-pending'], approved: ['Approved', 'status-admin'], settled: ['Settled', 'status-active'], rejected: ['Rejected', 'status-cancelled'], withdrawn: ['Withdrawn', 'status-inactive'] }; // same colours as claims.php
    var money = function(v) { return ((window.RS_BRAND && RS_BRAND.sym) || '₹') + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var ymdLocal = function(d) { d = d || new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); };

    // endorsement form inside the view modal (admin / manager)
    function endorseFormHtml(p) {
        var today = ymdLocal(), def = today < p.policy_start_date ? p.policy_start_date : (today > p.policy_end_date ? p.policy_end_date : today);
        var banks = (ddData && ddData.bankAccounts || []).map(function(b) { return '<option value="' + parseInt(b.id) + '">' + escapeHtml(b.bank_name + ' - ' + b.account_number) + '</option>'; }).join('');
        var h = '<form class="doc-upload" id="endForm" autocomplete="off" novalidate><div class="doc-upload-title"><i class="fas fa-plus"></i> Add an endorsement</div>';
        h += '<div class="form-grid"><div class="form-group"><label for="endType"><i class="fas fa-tags"></i> Type *</label><select id="endType" name="endorsement_type" class="searchable" required>'
            + END_TYPES.map(function(t) { return '<option value="' + t[0] + '">' + t[1] + '</option>'; }).join('') + '</select></div>';
        h += '<div class="form-group"><label for="endNo"><i class="fas fa-hashtag"></i> Endorsement No</label><input type="text" id="endNo" name="endorsement_number" maxlength="50"></div></div>';
        h += '<div class="form-grid"><div class="form-group"><label for="endDate"><i class="fas fa-calendar"></i> Endorsement Date *</label><input type="date" id="endDate" name="endorsement_date" required value="' + def + '" min="' + escapeHtml(p.policy_start_date) + '" max="' + escapeHtml(p.policy_end_date) + '"></div>';
        h += '<div class="form-group"><label for="endEff"><i class="fas fa-calendar-check"></i> Effective From</label><input type="date" id="endEff" name="effective_date"></div></div>';
        h += '<div class="form-group"><label for="endDesc"><i class="fas fa-align-left"></i> What changed *</label><textarea id="endDesc" name="description" rows="2" maxlength="500" required></textarea></div>';
        h += '<div class="form-grid"><div class="form-group"><label for="endPrem"><i class="fas fa-plus-minus"></i> Premium Change</label><input type="number" id="endPrem" name="premium_change" step="0.01" placeholder="0.00"></div>';
        h += '<div class="form-group"><label for="endFee"><i class="fas fa-receipt"></i> Fee Charged</label><input type="number" id="endFee" name="fee_amount" step="0.01" min="0" placeholder="0.00"></div></div>';
        h += '<div class="form-grid"><div class="form-group"><div class="toggle-switch"><input type="checkbox" class="toggle" id="endIncome" name="record_income" value="1" checked><label for="endIncome">Record fee in Other Income</label></div></div>';
        h += '<div class="form-group"><label for="endBank"><i class="fas fa-university"></i> Fee Received In</label><select id="endBank" name="bank_account_id" class="searchable"><option value="">Cash / not banked</option>' + banks + '</select></div></div>';
        h += '<div class="doc-upload-row"><span></span><button type="submit" class="btn btn-primary" id="endSaveBtn"><i class="fas fa-save"></i> Save Endorsement</button></div></form>';
        return h;
    }
    function initEndorseForm(p) {
        var f = document.getElementById('endForm');
        if (!f) return;
        f.querySelectorAll('select.searchable').forEach(function(el) { initSearchableDropdown(el); });
        f.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!f.reportValidity()) return;
            var btn = document.getElementById('endSaveBtn'), ico = btn.querySelector('i'), fd = new FormData(f);
            fd.append('policy_id', p.id);
            fd.append('csrf_token', window.CSRF_TOKEN);
            if (!document.getElementById('endIncome').checked) fd.delete('record_income');
            btn.disabled = true; ico.className = 'fas fa-spinner fa-spin';
            var done = rsOverlay('Saving…');
            $.ajax({ url: '?action=addEndorsement', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
                .done(function(res) {
                    if (!res.success) return Swal.fire({ icon: 'error', title: 'Not saved', text: res.message });
                    f.reset();
                    f.querySelectorAll('select.searchable').forEach(function(el) { el.sdRefresh && el.sdRefresh(); });
                    loadEndorsements(p.id);
                    Swal.fire({ icon: 'success', text: res.message, timer: 1600, showConfirmButton: false });
                })
                .fail(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); })
                .always(function() { done(); btn.disabled = false; ico.className = 'fas fa-save'; });
        });
    }
    function loadEndorsements(policyId) {
        $.getJSON('?action=getEndorsements&policy_id=' + policyId).done(function(res) {
            var rows = res.success ? res.data : [], lbl = {};
            END_TYPES.forEach(function(t) { lbl[t[0]] = t[1]; });
            $('#endCount').text(rows.length ? rows.length : '');
            $('#endList').html('<div class="tab-table" id="endTbl"></div>');
            var cols = [
                { data: 'endorsement_date', title: 'Date', render: function(d, t, r) { return t === 'display' && r.effective_date ? escapeHtml(d) + '<br><small class="text-muted">from ' + escapeHtml(r.effective_date) + '</small>' : escapeHtml(d); } },
                { data: 'endorsement_type', title: 'Type', render: function(d) { return escapeHtml(lbl[d] || d); } },
                { data: 'endorsement_number', title: 'No', defaultContent: '-' },
                { data: 'description', title: 'What changed' },
                { data: 'premium_change', title: 'Premium change', render: function(d, t) {
                    var pc = Number(d || 0);
                    if (t !== 'display') return pc;
                    return pc ? '<span class="wallet-amount ' + (pc < 0 ? 'wallet-negative' : 'wallet-positive') + '">' + (pc > 0 ? '+' : '') + money(pc) + '</span>' : '-'; } },
                { data: 'fee_amount', title: 'Fee', render: function(d, t, r) {
                    if (t !== 'display') return Number(d || 0);
                    return Number(d) ? money(d) + (r.other_income_id ? '<br><small class="text-muted">in Other Income</small>' : '') : '-'; } },
                { data: 'created_by_name', title: 'By', defaultContent: '-' }
            ];
            if (CAN_DELETE) cols.push({ data: null, orderable: false, title: '', render: function(d, t, r) {
                return '<button type="button" class="action-icon delete-icon" title="Delete endorsement" aria-label="Delete endorsement" onclick="deleteEndorsement(' + parseInt(r.id) + ', ' + parseInt(policyId) + ', this)"><i class="fas fa-trash"></i></button>'; } });
            rsTabTable('#endTbl', { data: rows, columns: cols, title: 'Endorsements', file: 'endorsements', sort: ['endorsement_date', 'desc'], empty: 'No endorsements yet' });
        });
    }

    function deleteEndorsement(id, policyId, btn) {
        Swal.fire({ icon: 'warning', title: 'Delete endorsement?', text: 'Its fee entry in Other Income (and bank) is removed too.', showCancelButton: true, confirmButtonColor: '#ea4335', confirmButtonText: 'Delete' }).then(function(r) {
            if (!r.isConfirmed) return;
            var ico = btn.querySelector('i'); btn.disabled = true; ico.className = 'fas fa-spinner fa-spin';
            var done = rsOverlay('Deleting…');
            $.post('?action=deleteEndorsement', { id: id, csrf_token: window.CSRF_TOKEN }, null, 'json')
                .done(function(res) { res.success ? loadEndorsements(policyId) : Swal.fire({ icon: 'error', title: 'Error', text: res.message }); })
                .fail(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); })
                .always(function() { done(); btn.disabled = false; ico.className = 'fas fa-trash'; });
        });
    }

    // claims on this policy (claims.php owns them)
    function loadPolicyClaims(policyId) {
        $.getJSON('claims.php?action=getClaims&policy_id=' + policyId).done(function(res) {
            var rows = res && res.success ? res.data : [];
            $('#claimCount').text(rows.length ? rows.length : '');
            $('#claimList').html('<div class="tab-table" id="claimTbl"></div>');
            rsTabTable('#claimTbl', {
                data: rows, title: 'Claims', file: 'policy_claims', sort: ['intimation_date', 'desc'], empty: 'No claims on this policy',
                columns: [
                    { data: 'claim_number', title: 'Claim No', render: function(d, t, c) { return t === 'display' ? '<a href="claims.php?id=' + parseInt(c.id) + '">' + escapeHtml(d) + '</a>' : d; } },
                    { data: 'claim_type', title: 'Type', render: function(d) { return escapeHtml(String(d || '').replace(/_/g, ' ')); } },
                    { data: 'intimation_date', title: 'Intimated' },
                    { data: 'status', title: 'Status', render: function(d, t) { var st = CLAIM_ST[d] || [d, 'status-user']; return t === 'display' ? '<span class="status-badge ' + st[1] + '">' + escapeHtml(st[0]) + '</span>' : st[0]; } },
                    { data: 'amount_claimed', title: 'Claimed', render: function(d, t) { return t === 'display' ? money(d) : Number(d || 0); } },
                    { data: 'amount_settled', title: 'Settled', render: function(d, t) { return t === 'display' ? (d != null ? money(d) : '-') : Number(d || 0); } }
                ]
            });
        }).fail(function() { $('#claimList').html('<div class="doc-empty"><i class="fas fa-car-burst"></i><div><b>Claims could not be loaded</b></div></div>'); });
    }

    // Trash — deleted policies, restore or delete forever (admin)
    var trashTable = null;
    function openTrash() { $('#trashModal').addClass('active'); loadTrash(); }
    function closeTrash() { $('#trashModal').removeClass('active'); }
    function loadTrash() {
        showLoading('Loading...');
        $.getJSON('?action=getTrash').done(function(res) {
            if (!res.success) return Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            $('#trashCount').text(res.data.length ? '· ' + res.data.length : '');
            if (trashTable) return rsTbData(trashTable, res.data);
            trashTable = rsTable('#trashTable', {
                data: res.data, title: 'Policy Trash', file: 'policy_trash', sort: ['deleted_at', 'desc'], empty: 'Trash is empty',
                columns: [
                    { data: 'policy_number', title: 'Policy No' },
                    { data: 'customer_name', title: 'Customer' },
                    { data: 'vehicle_number', title: 'Vehicle' },
                    { data: 'total_premium_amount', title: 'Premium', render: function(d, type) { return type === 'display' ? money(d) : d; } },
                    { data: 'deleted_at', title: 'Deleted On' },
                    { data: 'deleted_by_name', title: 'Deleted By', defaultContent: '-' },
                    { data: null, orderable: false, title: '', render: function(d, t, r) {
                        return '<div class="action-hub"><button type="button" class="action-icon edit-icon" data-tact="restore" data-id="' + parseInt(r.id) + '" title="Restore" aria-label="Restore"><i class="fas fa-rotate-left"></i></button>'
                            + '<button type="button" class="action-icon delete-icon" data-tact="purge" data-id="' + parseInt(r.id) + '" title="Delete forever" aria-label="Delete forever"><i class="fas fa-trash"></i></button></div>';
                    } }
                ]
            });
        }).fail(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }).always(hideLoading);
    }
    $(document).on('click', '#trashTable [data-tact]', function() {
        var btn = this, act = btn.dataset.tact, id = btn.dataset.id, restore = act === 'restore';
        Swal.fire(restore
            ? { icon: 'question', title: 'Restore this policy?', text: 'Its commission, bank entries, documents and endorsements come back and balances are recalculated.', showCancelButton: true, confirmButtonText: 'Restore' }
            : { icon: 'warning', title: 'Delete forever?', text: 'This cannot be undone. Its documents are removed too.', showCancelButton: true, confirmButtonColor: '#ea4335', confirmButtonText: 'Delete forever' }
        ).then(function(r) {
            if (!r.isConfirmed) return;
            var ico = btn.querySelector('i'), was = ico.className; btn.disabled = true; ico.className = 'fas fa-spinner fa-spin';
            var done = rsOverlay(restore ? 'Restoring…' : 'Deleting…');
            $.post('?action=' + (restore ? 'restorePolicy' : 'purgeTrash'), { id: id, csrf_token: window.CSRF_TOKEN }, null, 'json')
                .done(function(res) {
                    if (!res.success) return Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                    loadTrash();
                    if (restore) loadPolicies();
                    Swal.fire({ icon: 'success', text: res.message, timer: 1800, showConfirmButton: false });
                })
                .fail(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); })
                .always(function() { done(); btn.disabled = false; ico.className = was; });
        });
    });

    // policy summary -> A4 print / PDF
    function printPolicy(id, kind) {
        $.ajax({
            url: '?action=getPolicy&id=' + id,
            method: 'GET',
            dataType: 'json',
            success: function(res) {
                if (!res.success) { Swal.fire('Error', res.message, 'error'); return; }
                var d = res.data;
                var cat = (d.insurance_category || 'motor').toLowerCase();
                var catLabel = cat === 'health' ? 'Health Insurance' : (cat === 'life' ? 'Life Insurance' : 'Motor Insurance');
                var basis = function(b, r) { return b ? String(b).replace(/_/g, ' ') + (r !== null && r !== '' && r !== undefined ? ' @ ' + r : '') : '-'; };
                var partyLines = [];
                if (cat === 'health') {
                    partyLines.push((d.plan_name || 'Health Policy') + (d.sum_insured ? ' · Cover: ' + rsMoney(d.sum_insured) : ''));
                } else if (cat === 'life') {
                    partyLines.push(((d.life_plan_type || 'Life').toUpperCase() + ' Plan') + (d.sum_assured ? ' · Sum Assured: ' + rsMoney(d.sum_assured) : ''));
                } else {
                    partyLines.push((d.vehicle_number || '-') + ' · ' + (d.vehicle_type || 'Motor'));
                }

                var rows = [
                    { k: 'Vertical', v: catLabel }
                ];
                if (cat === 'health') {
                    rows.push({ k: 'Plan Name', v: d.plan_name || '-' });
                    rows.push({ k: 'Sum Insured', v: d.sum_insured ? rsMoney(d.sum_insured) : '-' });
                    if (d.tpa_name) rows.push({ k: 'TPA', v: d.tpa_name });
                    if (d.health_policy_type) rows.push({ k: 'Policy Type', v: d.health_policy_type.toUpperCase() });
                    if (d.members_count) rows.push({ k: 'Members Count', v: String(d.members_count) });
                } else if (cat === 'life') {
                    rows.push({ k: 'Plan Type', v: (d.life_plan_type || '-').toUpperCase() });
                    rows.push({ k: 'Sum Assured', v: d.sum_assured ? rsMoney(d.sum_assured) : '-' });
                    if (d.policy_term) rows.push({ k: 'Policy Term', v: d.policy_term + ' Years' });
                    if (d.premium_paying_term) rows.push({ k: 'PPT', v: d.premium_paying_term + ' Years' });
                    if (d.payment_frequency) rows.push({ k: 'Frequency', v: d.payment_frequency.toUpperCase() });
                    if (d.nominee_name) rows.push({ k: 'Nominee', v: d.nominee_name + (d.nominee_relation ? ' (' + d.nominee_relation + ')' : '') });
                } else {
                    rows.push({ k: 'Vehicle', v: (d.vehicle_number || '-') + ' · ' + (d.vehicle_type || '-') });
                    if (d.policy_type) rows.push({ k: 'Policy type', v: POLICY_TYPE_LBL[d.policy_type] || '-' });
                    if (d.idv) rows.push({ k: 'IDV (insured value)', v: rsMoney(d.idv) });
                    if (d.ncb_percent) rows.push({ k: 'NCB', v: (+d.ncb_percent) + '%' });
                    if (d.tp_amount) rows.push({ k: 'Third party (TP)', a: d.tp_amount });
                    if (d.od_amount) rows.push({ k: 'Own damage (OD)', a: d.od_amount });
                }

                rows.push({ k: 'Insurer', v: d.company_name + (d.company_code ? ' (' + d.company_code + ')' : '') });
                rows.push({ k: 'Agent', v: d.agent_name || 'Direct' });
                rows.push({ k: 'Branch', v: d.branch_name || '-' });
                rows.push({ k: 'Policy period', v: rsPaperDate(d.policy_start_date) + ' to ' + rsPaperDate(d.policy_end_date) });
                rows.push({ k: 'Business type', v: (BUSINESS_TYPE_LBL[d.business_type] || '-') + (d.previous_policy_number ? ' · renewal of ' + d.previous_policy_number : '') });
                rows.push({ k: 'GST', a: d.gst_amount || 0 });
                if (!IS_CUSTOMER) {
                    rows.push({ k: 'Buyer commission', v: basis(d.buyer_commission_basis, d.buyer_commission_rate), a: d.buyer_commission_amount });
                    rows.push({ k: 'Agent commission', v: basis(d.agent_commission_basis, d.agent_commission_rate), a: d.agent_commission_amount || 0 });
                    rows.push({ k: 'Payment in', v: [d.payment_in_bank_name, d.payment_mode].filter(Boolean).join(' · ') || '-', a: d.payment_in || 0 });
                    rows.push({ k: 'Payment out', v: d.payment_out_bank_name || '-', a: d.payment_out || 0 });
                }

                rsReportExport(kind || 'print', {
                    title: 'Policy Summary', number: d.policy_number,
                    party: { label: 'Insured', name: d.customer_name, lines: partyLines },
                    info: [['Entry date', rsPaperDate(d.entry_date)], ['Start date', rsPaperDate(d.policy_start_date)], ['End date', rsPaperDate(d.policy_end_date)], ['Status', String(d.status || '').toUpperCase()]],
                    cols: [
                        { title: '#', field: '#', align: 'center' },
                        { title: 'Particulars', field: 'k', bold: true },
                        { title: 'Details', get: function(r) { return r.v || ''; } },
                        { title: 'Amount', get: function(r) { return r.a === undefined ? '' : rsMoney(r.a); }, align: 'right' }
                    ],
                    rows: rows,
                    totals: [['Net premium', rsMoney(d.net_premium_amount)], ['TOTAL PREMIUM', rsMoney(d.total_premium_amount)]],
                    notesTitle: 'Remarks', notes: d.remarks || '',
                    footNote: 'Computer-generated policy summary. No signature or stamp is required.'
                }, d.policy_number);
            }
        });
    }

    var currentViewId = 0;

    // view policy detail - tabbed modal
    function viewPolicy(id, focusTab) {
        showLoading('Loading...');
        currentViewId = id;

        $.ajax({
            url: '?action=getPolicy&id=' + id,
            method: 'GET',
            dataType: 'json',
            success: function(res) {
                hideLoading();
                if (!res.success) {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                    return;
                }
                renderViewModal(res.data, focusTab);
                loadDocuments(id);
                if (!IS_CUSTOMER) {
                    loadEndorsements(id);
                    loadPolicyClaims(id);
                    loadLedger(id);
                } else {
                    loadPolicyClaims(id);
                }
                $('#viewModal').addClass('active');
            },
            error: function() { hideLoading(); Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    }

    function renderViewModal(p, focusTab) {
        var h = function(v) { return $('<span>').text(v || '-').html(); };
        var amt = function(v) { return '₹' + Number(v || 0).toLocaleString('en-IN', {minimumFractionDigits: 2}); };
        var statusCls = p.status === 'active' ? 'print-badge-active' : p.status === 'expired' ? 'print-badge-expired' : 'print-badge-cancelled';
        var statusTxt = p.status ? p.status.charAt(0).toUpperCase() + p.status.slice(1) : '-';
        var basisLbl = {'premium_pct':'Premium %','tp_pct':'TP %','od_pct':'OD %','net_pct':'Net %','fixed':'Fixed'};
        var cat = (p.insurance_category || 'motor').toLowerCase();
        var tabVehTitle = cat === 'health' ? '<i class="fas fa-heart-pulse"></i> Health &amp; Customer' : (cat === 'life' ? '<i class="fas fa-user-shield"></i> Life &amp; Customer' : '<i class="fas fa-car"></i> Vehicle &amp; Customer');
        var catBadge = '';
        if (cat === 'health') {
            catBadge = '<span class="status-badge chip-emerald"><i class="fas fa-heart-pulse"></i> Health Insurance</span>';
        } else if (cat === 'life') {
            catBadge = '<span class="status-badge chip-purple"><i class="fas fa-user-shield"></i> Life Insurance</span>';
        } else {
            catBadge = '<span class="status-badge chip-blue"><i class="fas fa-car"></i> Motor Insurance</span>';
        }

        // ---- tab nav ----
        var html = '<div class="tab-nav">';
        html += '<button type="button" class="tab-btn" data-tab="details"><i class="fas fa-file-contract"></i> Policy Details</button>';
        html += '<button type="button" class="tab-btn" data-tab="vehcust">' + tabVehTitle + '</button>';
        if (!IS_CUSTOMER) {
            html += '<button type="button" class="tab-btn" data-tab="company"><i class="fas fa-percentage"></i> Company &amp; Commissions</button>';
            html += '<button type="button" class="tab-btn" data-tab="payment"><i class="fas fa-credit-card"></i> Payments &amp; Bank</button>';
        }
        html += '<button type="button" class="tab-btn" data-tab="documents"><i class="fas fa-folder-open"></i> Documents</button>';
        if (!IS_CUSTOMER) {
            html += '<button type="button" class="tab-btn" data-tab="endorse"><i class="fas fa-file-signature"></i> Endorsements</button>';
        }
        html += '<button type="button" class="tab-btn" data-tab="claims"><i class="fas fa-car-burst"></i> Claims</button>';
        if (!IS_CUSTOMER) {
            html += '<button type="button" class="tab-btn" data-tab="ledger"><i class="fas fa-book"></i> Ledger</button>';
            html += '<button type="button" class="tab-btn" data-tab="timeline"><i class="fas fa-stream"></i> Timeline</button>';
        }
        html += '</div>';

        // ---- tab: policy details ----
        html += '<div class="tab-content" data-tab="details">';
        html += '<table class="view-detail-table">';
        html += '<tr><td class="vd-label"><i class="fas fa-layer-group"></i> Vertical</td><td class="vd-value">' + catBadge + '</td>';
        html += '<td class="vd-label"><i class="fas fa-hashtag"></i> Policy #</td><td class="vd-value"><span class="badge-code">' + h(p.policy_number) + '</span></td></tr>';
        html += '<tr><td class="vd-label"><i class="fas fa-flag"></i> Status</td><td class="vd-value"><span class="status-badge ' + statusCls + '">' + statusTxt + '</span></td>';
        html += '<td class="vd-label"><i class="fas fa-calendar"></i> Entry Date</td><td class="vd-value">' + h(p.entry_date) + '</td></tr>';
        html += '<tr><td class="vd-label"><i class="fas fa-code-branch"></i> Branch</td><td class="vd-value">' + h(p.branch_name) + '</td>';
        html += '<td class="vd-label"><i class="fas fa-calendar-check"></i> Start Date</td><td class="vd-value">' + h(p.policy_start_date) + '</td></tr>';
        html += '<tr><td class="vd-label"><i class="fas fa-calendar-times"></i> End Date</td><td class="vd-value" colspan="3">' + h(p.policy_end_date) + '</td></tr>';
        // empty pairs skipped - keeps the summary panel for real facts
        if (p.policy_type || p.business_type) html += '<tr><td class="vd-label"><i class="fas fa-shield-halved"></i> Policy Type</td><td class="vd-value">' + (POLICY_TYPE_LBL[p.policy_type] ? '<span class="status-badge status-user">' + POLICY_TYPE_LBL[p.policy_type] + '</span>' : '-') + '</td>'
            + '<td class="vd-label"><i class="fas fa-briefcase"></i> Business Type</td><td class="vd-value">' + (BUSINESS_TYPE_LBL[p.business_type] || '-') + '</td></tr>';
        var polLink = function(id, no) { return id ? '<button type="button" class="btn-link-add" data-open-policy="' + (+id) + '"><i class="fas fa-up-right-from-square"></i> ' + h(no) + '</button>' : '-'; };
        if (p.proposal_number || p.previous_policy_id) html += '<tr><td class="vd-label"><i class="fas fa-file-signature"></i> Proposal #</td><td class="vd-value">' + h(p.proposal_number) + '</td>'
            + '<td class="vd-label"><i class="fas fa-link"></i> Renewal Of</td><td class="vd-value">' + polLink(p.previous_policy_id, p.previous_policy_number) + '</td></tr>';
        if (p.renewed_by_id) html += '<tr><td class="vd-label"><i class="fas fa-redo"></i> Renewed By</td><td class="vd-value" colspan="3">' + polLink(p.renewed_by_id, p.renewed_by_number) + '</td></tr>';
        if (p.status === 'cancelled') {
            html += '<tr><td class="vd-label"><i class="fas fa-ban"></i> Cancelled On</td><td class="vd-value">' + h(p.cancelled_at) + '</td>';
            html += '<td class="vd-label"><i class="fas fa-comment-slash"></i> Cancellation Reason</td><td class="vd-value">' + h(p.cancellation_reason) + '</td></tr>';
        }
        if (p.updated_by_name) html += '<tr><td class="vd-label"><i class="fas fa-user-pen"></i> Last Edited By</td><td class="vd-value" colspan="3">' + h(p.updated_by_name) + (p.updated_at ? ' · ' + h(p.updated_at) : '') + '</td></tr>';
        html += '</table>';
        if (p.remarks) {
            html += '<h4 style="margin:18px 0 8px;color:var(--navy-primary);"><i class="fas fa-comment-alt"></i> Remarks</h4>';
            html += '<table class="view-detail-table"><tr><td class="vd-value" colspan="4">' + h(p.remarks) + '</td></tr></table>';
        }
        html += '</div>';

        // ---- tab: vehicle / coverage & customer ----
        html += '<div class="tab-content" data-tab="vehcust">';
        if (cat === 'health') {
            html += '<h4 style="margin:0 0 8px;color:var(--navy-primary);"><i class="fas fa-heart-pulse"></i> Health Insurance Coverage</h4>';
            html += '<table class="view-detail-table">';
            html += '<tr><td class="vd-label"><i class="fas fa-file-medical"></i> Plan Name</td><td class="vd-value"><strong>' + h(p.plan_name) + '</strong></td>';
            html += '<td class="vd-label"><i class="fas fa-shield-heart"></i> Sum Insured</td><td class="vd-value"><strong class="wallet-amount wallet-positive">' + amt(p.sum_insured) + '</strong></td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-hospital-user"></i> Type</td><td class="vd-value">' + h(p.health_policy_type ? p.health_policy_type.toUpperCase() : '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-users"></i> Covered Members</td><td class="vd-value">' + (p.members_count || 1) + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-hand-holding-medical"></i> TPA</td><td class="vd-value" colspan="3">' + h(p.tpa_name) + '</td></tr>';
            if (p.members_details) html += '<tr><td class="vd-label"><i class="fas fa-id-card"></i> Member Details</td><td class="vd-value" colspan="3">' + h(p.members_details) + '</td></tr>';
            if (p.ped_details) html += '<tr><td class="vd-label"><i class="fas fa-notes-medical"></i> Pre-Existing Conditions</td><td class="vd-value" colspan="3">' + h(p.ped_details) + '</td></tr>';
            html += '</table>';
        } else if (cat === 'life') {
            html += '<h4 style="margin:0 0 8px;color:var(--navy-primary);"><i class="fas fa-user-shield"></i> Life Insurance Coverage</h4>';
            html += '<table class="view-detail-table">';
            html += '<tr><td class="vd-label"><i class="fas fa-award"></i> Plan Type</td><td class="vd-value"><strong>' + h(p.life_plan_type ? p.life_plan_type.toUpperCase() : '-') + '</strong></td>';
            html += '<td class="vd-label"><i class="fas fa-hand-holding-dollar"></i> Sum Assured</td><td class="vd-value"><strong class="wallet-amount wallet-positive">' + amt(p.sum_assured) + '</strong></td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-hourglass-half"></i> Policy Term</td><td class="vd-value">' + (p.policy_term ? p.policy_term + ' Years' : '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-calendar-check"></i> PPT</td><td class="vd-value">' + (p.premium_paying_term ? p.premium_paying_term + ' Years' : '-') + '</td></tr>';
            html += '<tr><td class="vd-label"><i class="fas fa-clock"></i> Frequency</td><td class="vd-value">' + h(p.payment_frequency ? p.payment_frequency.toUpperCase() : '-') + '</td>';
            html += '<td class="vd-label"><i class="fas fa-user-tag"></i> Nominee</td><td class="vd-value">' + h(p.nominee_name) + (p.nominee_relation ? ' (' + h(p.nominee_relation) + ')' : '') + (p.nominee_dob ? ' - DOB: ' + h(p.nominee_dob) : '') + '</td></tr>';
            html += '</table>';
        } else {
            html += '<h4 style="margin:0 0 8px;color:var(--navy-primary);"><i class="fas fa-car"></i> Motor Vehicle Details</h4>';
            html += '<table class="view-detail-table">';
            html += '<tr><td class="vd-label"><i class="fas fa-car-side"></i> Vehicle #</td><td class="vd-value"><span class="badge-code">' + h(p.vehicle_number) + '</span></td>';
            html += '<td class="vd-label"><i class="fas fa-truck"></i> Category</td><td class="vd-value">' + h(p.vehicle_type) + '</td></tr>';
            html += '</table>';
        }
        html += '<h4 style="margin:18px 0 8px;color:var(--navy-primary);"><i class="fas fa-user"></i> Customer &amp; Agent</h4>';
        html += '<table class="view-detail-table">';
        html += '<tr><td class="vd-label"><i class="fas fa-user"></i> Customer</td><td class="vd-value"><strong>' + h(p.customer_name) + '</strong></td>';
        html += '<td class="vd-label"><i class="fas fa-user-tie"></i> Agent</td><td class="vd-value">' + h(p.agent_name || 'Direct Customer') + '</td></tr>';
        html += '</table>';
        html += '</div>';

        // ---- tab: company & commissions ----
        html += '<div class="tab-content" data-tab="company">';
        html += '<h4 style="margin:0 0 8px;color:var(--navy-primary);"><i class="fas fa-building"></i> Insurance Company</h4>';
        html += '<table class="view-detail-table">';
        html += '<tr><td class="vd-label"><i class="fas fa-building"></i> Company</td><td class="vd-value">' + h(p.company_name) + '</td>';
        html += '<td class="vd-label"><i class="fas fa-barcode"></i> Code</td><td class="vd-value"><span class="badge-code">' + h(p.company_code) + '</span></td></tr>';
        html += '</table>';
        html += '<h4 style="margin:18px 0 8px;color:var(--navy-primary);"><i class="fas fa-money-bill-wave"></i> Premium Details</h4>';
        html += '<table class="view-detail-table">';
        html += '<tr><td class="vd-label"><i class="fas fa-coins"></i> Total Premium</td><td class="vd-value"><strong>' + amt(p.total_premium_amount) + '</strong></td>';
        html += '<td class="vd-label"><i class="fas fa-receipt"></i> Net Premium</td><td class="vd-value"><strong>' + amt(p.net_premium_amount) + '</strong></td></tr>';
        html += '<tr><td class="vd-label"><i class="fas fa-shield-alt"></i> TP Amount</td><td class="vd-value">' + amt(p.tp_amount) + '</td>';
        html += '<td class="vd-label"><i class="fas fa-car-crash"></i> OD Amount</td><td class="vd-value">' + amt(p.od_amount) + '</td></tr>';
        html += '<tr><td class="vd-label"><i class="fas fa-file-invoice-dollar"></i> GST Amount</td><td class="vd-value">' + amt(p.gst_amount) + '</td>';
        html += '<td class="vd-label"><i class="fas fa-car"></i> IDV</td><td class="vd-value">' + (p.idv === null || p.idv === '' ? '-' : amt(p.idv)) + '</td></tr>';
        html += '<tr><td class="vd-label"><i class="fas fa-percent"></i> NCB</td><td class="vd-value">' + (p.ncb_percent === null || p.ncb_percent === '' ? '-' : (+p.ncb_percent) + '%') + '</td><td colspan="2"></td></tr>';
        html += '</table>';
        html += '</div>';

        // ---- tab: payments & bank ----
        html += '<div class="tab-content" data-tab="payment">';
        html += '<table class="view-detail-table">';
        html += '<tr><td class="vd-label"><i class="fas fa-arrow-down"></i> Payment In</td><td class="vd-value"><strong class="wallet-amount wallet-positive">' + amt(p.payment_in) + '</strong></td>';
        html += '<td class="vd-label"><i class="fas fa-university"></i> In Account</td><td class="vd-value">' + h(p.payment_in_bank_name || p.bank_name) + '</td></tr>';
        html += '<tr><td class="vd-label"><i class="fas fa-arrow-up"></i> Payment Out</td><td class="vd-value"><strong class="wallet-amount wallet-negative">' + amt(p.payment_out) + '</strong></td>';
        html += '<td class="vd-label"><i class="fas fa-university"></i> Out Account</td><td class="vd-value">' + h(p.payment_out_bank_name || p.bank_name) + '</td></tr>';
        html += '<tr><td class="vd-label"><i class="fas fa-wallet"></i> Mode</td><td class="vd-value">' + h(p.payment_mode) + '</td><td colspan="2"></td></tr>';
        html += '</table>';
        html += '</div>';

        // ---- tab: documents ----
        html += '<div class="tab-content" data-tab="documents"><div class="doc-panel">';
        html += '<div class="doc-head"><span><i class="fas fa-folder-open"></i> Files on this policy</span><em id="docsCount"></em></div>';
        html += '<div id="docsList"><div class="doc-empty"><i class="fas fa-spinner fa-spin"></i><div><b>Loading documents…</b></div></div></div>';
        if (CAN_CREATE) {
            html += '<div class="doc-upload"><div class="doc-upload-title"><i class="fas fa-cloud-arrow-up"></i> Add a document</div>';
            html += '<input type="file" id="docFile" class="file-input" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp" data-max-mb="5" data-hint="PDF or image · max 5MB">';
            html += '<div class="doc-upload-row"><input type="hidden" id="docType" value="policy_pdf"><div class="doc-types" role="radiogroup" aria-label="Document type">';
            DOC_TYPES.forEach(function(t, i) {
                html += '<button type="button" class="doc-type' + (i ? '' : ' on') + '" role="radio" aria-checked="' + (i ? 'false' : 'true') + '" data-type="' + t[0] + '"><i class="fas ' + t[2] + '"></i> ' + t[1] + '</button>';
            });
            html += '</div><button type="button" class="btn btn-primary" id="docUploadBtn" onclick="uploadDoc(' + p.id + ')"><i class="fas fa-upload"></i> Upload</button></div></div>';
        }
        html += '</div></div>';

        // ---- tab: endorsements ----
        html += '<div class="tab-content" data-tab="endorse"><div class="doc-panel">';
        html += '<div class="doc-head"><span><i class="fas fa-file-signature"></i> Endorsements on this policy</span><em id="endCount"></em></div>';
        html += '<div id="endList"><div class="doc-empty"><i class="fas fa-spinner fa-spin"></i><div><b>Loading…</b></div></div></div>';
        if (CAN_CREATE && p.status !== 'cancelled') html += endorseFormHtml(p);
        html += '</div></div>';

        // ---- tab: claims ----
        html += '<div class="tab-content" data-tab="claims"><div class="doc-panel">';
        html += '<div class="doc-head"><span><i class="fas fa-car-burst"></i> Claims on this policy</span><em id="claimCount"></em></div>';
        html += '<div id="claimList"><div class="doc-empty"><i class="fas fa-spinner fa-spin"></i><div><b>Loading…</b></div></div></div>';
        if (p.status !== 'cancelled') html += '<div><a class="btn btn-primary" href="claims.php?new=1&amp;policy_id=' + parseInt(p.id) + '"><i class="fas fa-plus"></i> New Claim</a></div>';
        html += '</div></div>';

        // ---- tab: ledger ----
        html += '<div class="tab-content" data-tab="ledger">';
        html += '<div id="ledgerList"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Loading ledger...</p></div></div>';
        html += '</div>';

        // ---- tab: timeline ----
        html += '<div class="tab-content" data-tab="timeline">';
        html += buildTimeline(p);
        html += '</div>';

        $('#viewModalBody').html(html);
        initModalTabs('#viewModalBody');
        if (window.rsDropzone && document.getElementById('docFile')) rsDropzone(document.getElementById('docFile'));
        // jump to a specific tab if requested
        if (focusTab) setTimeout(function() { $('#viewModalBody .tab-btn[data-tab="' + focusTab + '"]').trigger('click'); }, 0); // after the pop-up is visible, so the tab scrolls into view

        // footer buttons
        $('#viewPrintBtn').off('click').on('click', function() { printPolicy(p.id); });
        $('#viewWaBtn').off('click').on('click', function() { waReminder(p.id); });
        initEndorseForm(p);
        $('#viewRenewBtn').toggle(!p.renewed_by_id).off('click').on('click', function() { renewPolicy(p.id); });
        // renewal chain - open the linked policy
        $('#viewModalBody').off('click.chain').on('click.chain', '[data-open-policy]', function() { viewPolicy($(this).data('open-policy')); });
    }

    // timeline built from policy lifecycle dates
    function buildTimeline(p) {
        var h = function(v) { return $('<span>').text(v || '-').html(); };
        var n = daysToExpiry(p.policy_end_date);
        var steps = [];
        steps.push({ icon: 'fa-plus-circle', label: 'Created', date: p.entry_date || p.created_at, done: true });
        steps.push({ icon: 'fa-calendar-check', label: 'Active', date: p.policy_start_date, done: true });
        if (p.status === 'cancelled') {
            steps.push({ icon: 'fa-ban', label: 'Cancelled', date: '', done: true, danger: true });
        } else if (p.status === 'expired' || (n !== null && n < 0)) {
            steps.push({ icon: 'fa-calendar-times', label: 'Expired', date: p.policy_end_date, done: true, danger: true });
        } else if (n !== null && n <= 30) {
            steps.push({ icon: 'fa-hourglass-half', label: 'Expiring in ' + n + ' days', date: p.policy_end_date, done: false, warn: true });
        } else {
            steps.push({ icon: 'fa-calendar-times', label: 'Expires', date: p.policy_end_date, done: false });
        }
        var html = '<table class="view-detail-table">';
        steps.forEach(function(s) {
            var cls = s.danger ? 'wallet-negative' : (s.warn ? 'wallet-negative' : (s.done ? 'wallet-positive' : 'text-muted'));
            html += '<tr><td class="vd-label"><i class="fas ' + s.icon + '"></i> ' + h(s.label) + '</td>';
            html += '<td class="vd-value"><span class="wallet-amount ' + cls + '">' + h(s.date) + '</span></td></tr>';
        });
        html += '</table>';
        return html;
    }

    // policy ledger - auto-posted agent/buyer/bank rows
    function loadLedger(policyId) {
        $.ajax({
            url: '?action=getPolicyLedger&id=' + policyId,
            method: 'GET',
            dataType: 'json',
            success: function(res) {
                var amt = function(v, cls) { return Number(v) ? '<span class="wallet-amount ' + cls + '">' + Number(v).toLocaleString('en-IN', {minimumFractionDigits: 2}) + '</span>' : '-'; };
                $('#ledgerList').html('<div class="tab-table" id="ledgerTbl"></div>');
                rsTabTable('#ledgerTbl', {
                    data: res.success ? res.data || [] : [], title: 'Policy Ledger', file: 'policy_ledger', sort: ['date', 'asc'],
                    empty: 'No auto-posted transactions for this policy',
                    columns: [
                        { data: 'ledger', title: 'Ledger', render: function(d, t) { return t === 'display' ? '<span class="badge-code">' + escapeHtml(d) + '</span>' : d; } },
                        { data: 'party', title: 'Party', defaultContent: '-' },
                        { data: 'date', title: 'Date', defaultContent: '-' },
                        { data: 'type', title: 'Type', defaultContent: '-' },
                        { data: 'desc', title: 'Description', defaultContent: '-' },
                        { data: 'credit', title: 'Credit', render: function(d, t) { return t === 'display' ? amt(d, 'wallet-positive') : (+d || 0); } },
                        { data: 'debit', title: 'Debit', render: function(d, t) { return t === 'display' ? amt(d, 'wallet-negative') : (+d || 0); } }
                    ]
                });
            },
            error: function() {
                $('#ledgerList').html('<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Could not load ledger</p></div>');
            }
        });
    }

    // doc types: value, label, icon
    var DOC_TYPES = [['policy_pdf', 'Policy PDF', 'fa-file-pdf'], ['vehicle_image', 'Vehicle image', 'fa-car'], ['other', 'Other', 'fa-file']];
    $('#viewModalBody').on('click', '.doc-type', function() { // inside the pop-up: .modal stops clicks bubbling to document
        $('.doc-type').removeClass('on').attr('aria-checked', 'false');
        $(this).addClass('on').attr('aria-checked', 'true');
        $('#docType').val($(this).data('type'));
    });

    function loadDocuments(policyId) {
        $.ajax({
            url: '?action=getDocuments&policy_id=' + policyId,
            method: 'GET',
            dataType: 'json',
            success: function(res) {
                var docs = res.success ? res.data : [];
                $('#docsCount').text(docs.length ? docs.length + (docs.length === 1 ? ' file' : ' files') : '');
                if (!docs.length) {
                    $('#docsList').html('<div class="doc-empty"><i class="fas fa-folder-open"></i><div><b>No documents yet</b><small>' + (CAN_CREATE ? 'Add the policy PDF or vehicle photos below.' : 'Nothing has been uploaded for this policy.') + '</small></div></div>');
                    return;
                }
                var lbl = {}; DOC_TYPES.forEach(function(t) { lbl[t[0]] = t[1]; });
                var html = '<ul class="doc-list">';
                docs.forEach(function(doc) {
                    var pdf = doc.mime_type && doc.mime_type.indexOf('pdf') > -1;
                    html += '<li class="doc-item">';
                    html += '<div class="doc-icon' + (pdf ? ' is-pdf' : '') + '"><i class="fas ' + (pdf ? 'fa-file-pdf' : 'fa-file-image') + '"></i></div>';
                    html += '<div class="doc-info"><div class="doc-name">' + escapeHtml(doc.file_name) + '</div>';
                    html += '<div class="doc-meta">' + escapeHtml(lbl[doc.document_type] || doc.document_type) + ' &middot; ' + (parseInt(doc.file_size_kb) || 0) + ' KB' + (doc.uploader_name ? ' &middot; ' + escapeHtml(doc.uploader_name) : '') + '</div></div>';
                    html += '<div class="doc-actions"><a href="?action=viewDoc&amp;id=' + parseInt(doc.id) + '" target="_blank" rel="noopener" class="head-btn" title="Open" aria-label="Open ' + escapeHtml(doc.file_name) + '"><i class="fas fa-up-right-from-square"></i></a>';
                    if (CAN_DELETE) html += '<button type="button" class="head-btn doc-del" title="Delete" aria-label="Delete ' + escapeHtml(doc.file_name) + '" onclick="deleteDoc(' + parseInt(doc.id) + ', ' + parseInt(doc.policy_entry_id) + ')"><i class="fas fa-trash"></i></button>';
                    html += '</div></li>';
                });
                $('#docsList').html(html + '</ul>');
            }
        });
    }

    function uploadDoc(policyId) {
        var fileInput = document.getElementById('docFile');
        if (!fileInput.files.length) {
            Swal.fire({ icon: 'warning', title: 'No File', text: 'Please select a file' });
            return;
        }

        var fd = new FormData();
        fd.append('policy_id', policyId);
        fd.append('document', fileInput.files[0]);
        fd.append('document_type', $('#docType').val());
        fd.append('csrf_token', window.CSRF_TOKEN);

        var btn = $('#docUploadBtn').prop('disabled', true), done = window.rsOverlay ? rsOverlay('Uploading…') : function() {};
        btn.find('i').attr('class', 'fas fa-spinner fa-spin');
        var finish = function() { done(); btn.prop('disabled', false).find('i').attr('class', 'fas fa-upload'); };

        $.ajax({
            url: '?action=uploadDocument',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                finish();
                if (res.success) {
                    Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                    fileInput.value = '';
                    loadDocuments(policyId);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error: function() { finish(); Swal.fire({ icon: 'error', title: 'Error', text: 'Upload failed' }); }
        });
    }

    function deleteDoc(docId, policyId) {
        Swal.fire({
            icon: 'warning',
            title: 'Delete Document?',
            showCancelButton: true,
            confirmButtonColor: '#ea4335',
            confirmButtonText: 'Delete'
        }).then(function(result) {
            if (!result.isConfirmed) return;
            var fd = new FormData();
            fd.append('id', docId);
            fd.append('csrf_token', window.CSRF_TOKEN);

            showLoading('Deleting...');
            $.ajax({
                url: '?action=deleteDocument',
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(res) {
                    hideLoading();
                    if (res.success) {
                        Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                        loadDocuments(policyId);
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                    }
                },
                error: function() { hideLoading(); Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
            });
        });
    }

    function closeViewModal() {
        $('#viewModal').removeClass('active');
    }

    document.getElementById('viewModal').addEventListener('click', function(e) {
        if (e.target === this) closeViewModal();
    });

    // bulk upload
    var bulkRows = [];

    function openUploadModal() {
        bulkRows = [];
        $('#bulkFile').val('');
        $('#uploadPreview').hide();
        $('#uploadErrors').hide();
        $('#btnProcessUpload').prop('disabled', true);
        $('#uploadModal').addClass('active');
    }

    function closeUploadModal() {
        $('#uploadModal').removeClass('active');
    }

    // modal only rendered for $canCreate
    var upModalEl = document.getElementById('uploadModal');
    if (upModalEl) upModalEl.addEventListener('click', function(e) {
        if (e.target === this) closeUploadModal();
    });

    // template columns
    var tplCols = [
        'insurance_category','policy_number','customer_name','customer_mobile','company_code','branch','agent_code',
        'vehicle_number','vehicle_category','vehicle_make','vehicle_model','fuel_type',
        'plan_name','sum_insured','health_policy_type','tpa_name','members_count',
        'sum_assured','life_plan_type','policy_term','premium_paying_term','payment_frequency','nominee_name','nominee_relation',
        'total_premium','tp_amount','od_amount','net_premium','buyer_commission_basis','buyer_commission_rate','agent_commission_basis','agent_commission_rate',
        'payment_mode','payment_in','payment_in_bank','payment_out','payment_out_bank','policy_start_date','remarks','policy_type','business_type','previous_policy_number','proposal_number','idv','gst_amount','ncb_percent'
    ];

    function downloadTemplate() {
        var wb = XLSX.utils.book_new();
        // 3 sample rows representing Motor, Health, and Life Insurance
        var sampleRows = [
            tplCols,
            // 1. Motor Sample
            ['motor','POL-2025-101','Ramesh Patel','9876543210','IC-001','Main Branch','AGT-001','GJ01AB1234','Car','Maruti','Swift','Petrol','','','','','','','','','','','','','25000','8000','17000','22000','premium_pct','15','premium_pct','10','NEFT','25000','HDFC Bank - 50100123456','0','','2025-04-01','Sample Motor Policy','comprehensive','new','','PRP-2025-101','450000','3000','20'],
            // 2. Health Sample
            ['health','POL-2025-102','Priya Sharma','9876543211','IC-001','Main Branch','AGT-001','','','','','','Family Optima Restore','500000','floater','MediAssist TPA','4','','','','','','','','18000','0','0','15000','premium_pct','15','premium_pct','10','UPI','18000','HDFC Bank - 50100123456','0','','2025-04-01','Sample Health Policy','','new','','PRP-2025-102','','3000',''],
            // 3. Life Sample
            ['life','POL-2025-103','Amit Shah','9876543212','IC-001','Main Branch','AGT-001','','','','','','','','','','','1000000','endowment','20','15','yearly','Neha Shah','Wife','45000','0','0','45000','premium_pct','20','premium_pct','15','Cheque','45000','HDFC Bank - 50100123456','0','','2025-04-01','Sample Life Policy','','new','','PRP-2025-103','','0','']
        ];
        var ws = XLSX.utils.aoa_to_sheet(sampleRows);
        XLSX.utils.book_append_sheet(wb, ws, 'Policies');
        XLSX.writeFile(wb, 'policy_upload_template.xlsx');
    }

    // file change -> parse & preview
    $('#bulkFile').on('change', function() {
        var file = this.files[0];
        if (!file) return;

        var reader = new FileReader();
        reader.onload = function(e) {
            try {
                var wb = XLSX.read(e.target.result, { type: 'array', cellDates: true });
                var ws = wb.Sheets[wb.SheetNames[0]];
                var json = XLSX.utils.sheet_to_json(ws, { raw: false, defval: '' });

                if (!json.length) {
                    Swal.fire({ icon: 'warning', title: 'Empty File', text: 'No data rows found' });
                    return;
                }

                bulkRows = json;
                $('#previewCount').text(json.length);

                // build preview table
                var headers = Object.keys(json[0]);
                var html = '<thead><tr>';
                headers.forEach(function(h) { html += '<th>' + escapeHtml(h) + '</th>'; });
                html += '</tr></thead><tbody>';
                json.slice(0, 10).forEach(function(r) {
                    html += '<tr>';
                    headers.forEach(function(h) { html += '<td>' + escapeHtml(r[h] || '') + '</td>'; });
                    html += '</tr>';
                });
                if (json.length > 10) html += '<tr><td colspan="' + headers.length + '" style="text-align:center;color:var(--text-muted);">... and ' + (json.length - 10) + ' more rows</td></tr>';
                html += '</tbody>';
                $('#previewTable').html(html);
                $('#uploadPreview').show();
                $('#btnProcessUpload').prop('disabled', false);
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Parse Error', text: 'Could not read file: ' + err.message });
            }
        };
        reader.readAsArrayBuffer(file);
    });

    function processUpload() {
        if (!bulkRows.length) return;

        showLoading('Processing ' + bulkRows.length + ' rows...');

        var fd = new FormData();
        fd.append('rows', JSON.stringify(bulkRows));
        fd.append('csrf_token', window.CSRF_TOKEN);

        $.ajax({
            url: '?action=bulkUpload',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                hideLoading();
                if (res.success) {
                    var errHtml = '';
                    if (res.errors && res.errors.length > 0) {
                        errHtml = '<div style="max-height:200px;overflow-y:auto;text-align:left;font-size:13px;margin-top:10px;background:#fff3cd;padding:10px;border-radius:4px;">';
                        res.errors.forEach(function(e) { errHtml += '<div style="margin-bottom:4px;color:#856404;"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(e) + '</div>'; });
                        errHtml += '</div>';
                    }
                    Swal.fire({
                        icon: res.inserted > 0 ? 'success' : 'warning',
                        title: res.inserted > 0 ? 'Upload Complete' : 'Upload Issues',
                        html: '<p>' + res.message + '</p>' + errHtml
                    });
                    if (res.inserted > 0) {
                        closeUploadModal();
                        loadDropdowns();
                        loadPolicies();
                    }
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error: function() { hideLoading(); Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
        });
    }

    // row-checkbox bulk delete via shared helper - table built once
    var bulkSel = null;
    function setupBulkSelect() {
        if (!policiesTable || typeof initBulkSelect !== 'function') return;
        bulkSel = initBulkSelect({
            table: policiesTable,
            label: 'policies',
            onDelete: function(ids) {
                showLoading('Deleting...');
                var fd = new FormData();
                fd.append('ids', JSON.stringify(ids));
                fd.append('csrf_token', window.CSRF_TOKEN);
                $.ajax({
                    url: '?action=bulkDelete',
                    method: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(res) {
                        hideLoading();
                        if (res.success) {
                            if (bulkSel) bulkSel.clear();
                            loadPolicies();
                            Swal.fire({ icon: 'success', text: res.message, timer: 2000, showConfirmButton: false });
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                        }
                    },
                    error: function() { hideLoading(); Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); }
                });
            }
        });
    }
        // quick-add a vehicle category inline (admin), then select it in the given dropdown
        function quickAddCategory(selectId) {
            Swal.fire({
                title: 'New Vehicle Category',
                input: 'text',
                inputPlaceholder: 'e.g. Commercial - Truck',
                showCancelButton: true,
                confirmButtonText: 'Add',
                inputValidator: function(v){ if (!v || !v.trim()) return 'Enter a category name'; }
            }).then(function(res){
                if (!res.isConfirmed) return;
                var name = res.value.trim();
                showLoading('Adding...');
                $.ajax({
                    url: 'vehicles.php?action=addCategory', method: 'POST',
                    data: { name: name, csrf_token: window.CSRF_TOKEN }, dataType: 'json',
                    success: function(r){
                        hideLoading();
                        if (!r.success) { Swal.fire({icon:'error', title:'Error', text:r.message}); return; }
                        var sel = document.getElementById(selectId);
                        if (sel && !Array.from(sel.options).some(function(o){ return o.value === name; })) sel.add(new Option(name, name, true, true));
                        else if (sel) sel.value = name;
                        if (sel && sel.sdRefresh) sel.sdRefresh();
                        Swal.fire({icon:'success', text:'Category added', timer:1200, showConfirmButton:false});
                    },
                    error: function(){ hideLoading(); Swal.fire({icon:'error', title:'Error', text:'Connection error'}); }
                });
            });
        }
    </script>
</body>
</html>
