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
$user_id = (int)$_SESSION['user_id'];
$current_page = 'claims';
$isAdmin = $role === 'admin';
$isStaff = $isAdmin || $role === 'manager';
$isAgent = $role === 'agent';
if (!$isStaff && !$isAgent) { header("Location: dashboard.php"); exit(); }

const CLM_TYPES = ['own_damage' => 'Own Damage', 'theft' => 'Theft', 'third_party' => 'Third Party', 'total_loss' => 'Total Loss', 'fire' => 'Fire', 'natural_calamity' => 'Natural Calamity', 'other' => 'Other'];
const CLM_STATUS = ['intimated' => 'Intimated', 'survey' => 'Survey', 'approved' => 'Approved', 'settled' => 'Settled', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'];
const CLM_DOCS = ['claim_form' => 'Claim form', 'fir' => 'FIR', 'estimate' => 'Estimate', 'survey_report' => 'Survey report', 'repair_invoice' => 'Repair invoice', 'photo' => 'Photo', 'settlement_letter' => 'Settlement letter', 'other' => 'Other'];
// saved cols + bind letter — insert / update are built from this
const CLM_COLS = ['policy_entry_id' => 'i', 'insurer_claim_number' => 's', 'claim_type' => 's', 'incident_date' => 's', 'intimation_date' => 's',
    'incident_place' => 's', 'description' => 's', 'fir_number' => 's', 'surveyor_name' => 's', 'surveyor_mobile' => 's', 'survey_date' => 's',
    'garage_name' => 's', 'settlement_mode' => 's', 'amount_claimed' => 'd', 'amount_approved' => 'd', 'amount_settled' => 'd',
    'settlement_date' => 's', 'status' => 's', 'rejection_reason' => 's', 'remarks' => 's'];
// text cols -> [max, label]
const CLM_TXT = ['insurer_claim_number' => [50, 'Insurer claim no'], 'incident_place' => [150, 'Incident place'], 'description' => [2000, 'Description'],
    'fir_number' => [50, 'FIR no'], 'surveyor_name' => [150, 'Surveyor name'], 'surveyor_mobile' => [15, 'Surveyor mobile'], 'garage_name' => [150, 'Garage'],
    'rejection_reason' => [255, 'Rejection reason'], 'remarks' => [500, 'Remarks']];
// agent: add = intimation fields only, edit = these while still intimated
const CLM_AGENT_ADD = ['policy_entry_id', 'claim_type', 'incident_date', 'intimation_date', 'incident_place', 'description', 'fir_number', 'amount_claimed', 'insurer_claim_number'];
const CLM_AGENT_EDIT = ['claim_type', 'incident_date', 'incident_place', 'description', 'fir_number', 'amount_claimed'];
const CLM_SELECT = "SELECT c.*, pe.policy_number, pe.vehicle_id, pe.vehicle_number, pe.vehicle_type, pe.customer_name, pe.company_name, pe.agent_id, pe.agent_name,
        pe.policy_start_date, pe.policy_end_date, pe.idv, pe.status AS policy_status, cu.mobile AS customer_mobile, a.user_id AS agent_user_id,
        u.name AS created_by_name, u2.name AS updated_by_name, (SELECT COUNT(*) FROM claim_documents d WHERE d.claim_id = c.id) AS doc_count
    FROM claims c
    JOIN policy_entries pe ON pe.id = c.policy_entry_id
    LEFT JOIN customers cu ON cu.id = pe.customer_id
    LEFT JOIN agents a ON a.id = pe.agent_id
    LEFT JOIN users u ON u.id = c.created_by
    LEFT JOIN users u2 ON u2.id = c.updated_by";

function clmOut(array $r): void { echo json_encode($r, JSON_INVALID_UTF8_SUBSTITUTE); exit(); }

// POST + csrf or stop
function clmPost(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') clmOut(['success' => false, 'message' => 'Invalid request method']);
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) clmOut(['success' => false, 'message' => 'Invalid request']);
}

function clmDmy(?string $d): string { return $d ? date('d M Y', strtotime($d)) : '-'; }

// blank -> null, bad -> false
function clmDate($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v ? $v : false;
}

// policy + its agent login, memo per request
function clmPolicy(mysqli $conn, int $pid): ?array {
    static $memo = [];
    if (array_key_exists($pid, $memo)) return $memo[$pid];
    $st = $conn->prepare("SELECT pe.id, pe.policy_number, pe.vehicle_number, pe.customer_name, pe.company_name, pe.agent_id, pe.policy_start_date, pe.policy_end_date, pe.status, pe.idv, a.user_id AS agent_user_id
        FROM policy_entries pe LEFT JOIN agents a ON a.id = pe.agent_id WHERE pe.id = ?");
    $st->bind_param('i', $pid);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $memo[$pid] = $r ?: null;
}

// one rule set for add, import and edit -> [error, clean cols, policy]
function clmCheck(mysqli $conn, array $in, ?array $old, bool $admin): array {
    $bad = fn($m) => [$m, null, null];
    $pid = (int)($in['policy_entry_id'] ?? 0);
    $pol = $pid > 0 ? clmPolicy($conn, $pid) : null;
    if (!$pol || !canSeePolicy($conn, $pid)) return $bad('Select a valid policy');
    if ($pol['status'] === 'cancelled' && (int)($old['policy_entry_id'] ?? 0) !== $pid) return $bad("Policy {$pol['policy_number']} is cancelled");
    $d = ['policy_entry_id' => $pid];

    foreach (CLM_TXT as $k => [$max, $lbl]) {
        $v = trim((string)($in[$k] ?? ''));
        if (mb_strlen($v) > $max) return $bad("$lbl can be at most $max characters");
        $d[$k] = $v === '' ? null : $v;
    }
    if ($d['surveyor_mobile'] !== null) {
        $d['surveyor_mobile'] = preg_replace('/[\s-]/', '', $d['surveyor_mobile']);
        if (!preg_match('/^\+?\d{7,14}$/', $d['surveyor_mobile'])) return $bad('Surveyor mobile must be 7-14 digits');
    }
    $d['claim_type'] = (string)($in['claim_type'] ?? '') ?: 'own_damage';
    if (!isset(CLM_TYPES[$d['claim_type']])) return $bad('Invalid claim type');
    $sm = trim((string)($in['settlement_mode'] ?? ''));
    if ($sm !== '' && !in_array($sm, ['cashless', 'reimbursement'], true)) return $bad('Invalid settlement mode');
    $d['settlement_mode'] = $sm ?: null;

    foreach (['incident_date' => 'Incident date', 'intimation_date' => 'Intimation date', 'survey_date' => 'Survey date', 'settlement_date' => 'Settlement date'] as $k => $lbl) {
        $d[$k] = clmDate($in[$k] ?? '');
        if ($d[$k] === false) return $bad("$lbl must be a valid date (YYYY-MM-DD)");
    }
    if (!$d['incident_date']) return $bad('Incident date is required');
    if (!$d['intimation_date']) return $bad('Intimation date is required');
    if ($d['incident_date'] < $pol['policy_start_date'] || $d['incident_date'] > $pol['policy_end_date'])
        return $bad('Incident date is outside the policy period (' . clmDmy($pol['policy_start_date']) . ' – ' . clmDmy($pol['policy_end_date']) . ')');
    if ($d['intimation_date'] < $d['incident_date']) return $bad('Intimation date cannot be before the incident date');
    if ($d['intimation_date'] > date('Y-m-d', time() + 14 * 3600)) return $bad('Intimation date cannot be in the future'); // tz-safe: server may run UTC, users sit ahead
    if ($d['survey_date'] && $d['survey_date'] < $d['incident_date']) return $bad('Survey date cannot be before the incident date');

    foreach (['amount_claimed' => 'Amount claimed', 'amount_approved' => 'Amount approved', 'amount_settled' => 'Amount settled'] as $k => $lbl) {
        $v = trim((string)($in[$k] ?? ''));
        $d[$k] = null;
        if ($v === '') continue;
        if (!is_numeric($v)) return $bad("$lbl must be a number");
        if ($v < 0) return $bad('Amounts cannot be negative');
        if ($v > 9999999999.99) return $bad("$lbl is too large");
        $d[$k] = round((float)$v, 2);
    }
    $d['amount_claimed'] ??= 0.0;

    $s = (string)($in['status'] ?? '') ?: 'intimated';
    if (!isset(CLM_STATUS[$s])) return $bad('Invalid status');
    if ($old && $old['status'] !== $s && in_array($old['status'], ['settled', 'rejected'], true) && !$admin) return $bad('Only an admin can reopen a settled or rejected claim');
    $idv = (float)($pol['idv'] ?? 0);
    if ($d['amount_approved'] && $idv > 0 && $d['amount_approved'] > $idv) return $bad('Amount approved cannot exceed the policy IDV (' . number_format($idv, 2) . ')');
    if ($s === 'approved' && !($d['amount_approved'] > 0)) return $bad('Enter the amount approved to mark the claim approved');
    if ($s === 'settled') {
        if (!($d['amount_settled'] > 0)) return $bad('Enter the amount settled to mark the claim settled');
        if (!$d['settlement_date']) return $bad('Enter the settlement date to mark the claim settled');
        if ($d['settlement_date'] < $d['intimation_date']) return $bad('Settlement date cannot be before the intimation date');
    }
    if ($s === 'rejected' && !$d['rejection_reason']) return $bad('Enter the rejection reason');
    if ($s !== 'rejected') $d['rejection_reason'] = null;
    $d['status'] = $s;
    return [null, $d, $pol];
}

// next CLM-YYYY-NNNN — locks the year's range, call inside the txn
function clmNextNo(mysqli $conn, string $date): string {
    $pre = 'CLM-' . substr($date, 0, 4) . '-';
    $like = $pre . '%';
    $st = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(claim_number, 10) AS UNSIGNED)), 0) FROM claims WHERE claim_number LIKE ? FOR UPDATE");
    $st->bind_param('s', $like);
    $st->execute();
    $n = (int)$st->get_result()->fetch_row()[0];
    $st->close();
    return $pre . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
}

// insert, number made in the same txn; a clash retries once -> [id, claim no]
function clmInsert(mysqli $conn, array $d, int $uid): array {
    $cols = array_keys(CLM_COLS);
    $sql = 'INSERT INTO claims (claim_number, ' . implode(', ', $cols) . ', created_by) VALUES (?' . str_repeat(', ?', count($cols)) . ', ?)';
    $types = 's' . implode('', CLM_COLS) . 'i';
    for ($try = 0; ; $try++) {
        $conn->begin_transaction();
        try {
            $no = clmNextNo($conn, $d['intimation_date']);
            $vals = array_merge([$no], array_map(fn($k) => $d[$k], $cols), [$uid]);
            $st = $conn->prepare($sql);
            $st->bind_param($types, ...$vals);
            $st->execute();
            $id = (int)$st->insert_id;
            $st->close();
            $conn->commit();
            return [$id, $no];
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            if ($e->getCode() !== 1062 || $try) throw $e; // dup number -> one more go
        }
    }
}

// one claim this login may see (null = not found / not yours)
function clmFind(mysqli $conn, int $id): ?array {
    $st = $conn->prepare(CLM_SELECT . ' WHERE c.id = ?');
    $st->bind_param('i', $id);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r && canSeePolicy($conn, $r['policy_entry_id']) ? clmRow($r) : null;
}
function clmRow(array $r): array {
    foreach (['amount_claimed', 'amount_approved', 'amount_settled', 'idv'] as $k) $r[$k] = $r[$k] === null ? null : (float)$r[$k];
    $r['claim_type_label'] = CLM_TYPES[$r['claim_type']] ?? $r['claim_type'];
    $r['status_label'] = CLM_STATUS[$r['status']] ?? $r['status'];
    return $r;
}

function clmAdmins(mysqli $conn): array {
    static $ids = null;
    return $ids ??= array_map('intval', array_column($conn->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetch_all(MYSQLI_ASSOC), 'id'));
}
// 'general' alert per user, actor skipped; never breaks the save
function clmNotify(mysqli $conn, array $uids, int $actor, string $title, string $msg, array $pol): void {
    $uids = array_diff(array_unique(array_filter(array_map('intval', $uids))), [$actor]);
    if (!$uids) return;
    try {
        $pid = (int)$pol['id'];
        $aid = $pol['agent_id'] ? (int)$pol['agent_id'] : null;
        $u = 0;
        $st = $conn->prepare("INSERT INTO notifications (user_id, type, title, message, policy_entry_id, agent_id) VALUES (?, 'general', ?, ?, ?, ?)");
        $st->bind_param('issii', $u, $title, $msg, $pid, $aid);
        foreach ($uids as $u) $st->execute();
        $st->close();
    } catch (Throwable $e) {
        error_log('claim notify: ' . $e->getMessage());
    }
}
function clmNotifyNew(mysqli $conn, array $pol, string $no, array $d, int $actor): void {
    clmNotify($conn, array_merge(clmAdmins($conn), [(int)$pol['agent_user_id']]), $actor, "New Claim: $no",
        "Claim $no (" . CLM_TYPES[$d['claim_type']] . ") intimated on policy {$pol['policy_number']} — vehicle {$pol['vehicle_number']}, {$pol['customer_name']}. Amount claimed " . number_format($d['amount_claimed'], 2) . '.', $pol);
}

// stored files under uploads/claims only, then drop empty folders
function clmUnlink(array $paths): void {
    $paths = array_filter($paths, fn($p) => strpos((string)$p, 'uploads/claims/') === 0 && strpos((string)$p, '..') === false);
    foreach ($paths as $p) if (is_file(__DIR__ . '/' . $p)) unlink(__DIR__ . '/' . $p);
    foreach (array_unique(array_map('dirname', $paths)) as $dir) {
        $full = __DIR__ . '/' . $dir;
        if (is_dir($full) && count(scandir($full)) === 2) rmdir($full);
    }
}

// AJAX handlers
if (isset($_GET['action'])) {
    $act = $_GET['action'];
    if ($act !== 'viewDoc') header('Content-Type: application/json');

    try {
        $conn = getDBConnection();

        switch ($act) {

            case 'getClaims':
                // agent = own book; policy_id / vehicle_id narrow it (policy + vehicle pop-ups)
                $w = []; $types = ''; $args = [];
                if ($isAgent) { $w[] = 'pe.agent_id = ?'; $types .= 'i'; $args[] = agentIdForUser($conn, $user_id) ?? 0; }
                foreach (['policy_id' => 'c.policy_entry_id', 'vehicle_id' => 'pe.vehicle_id'] as $k => $col) {
                    $v = (int)($_GET[$k] ?? 0);
                    if ($v > 0) { $w[] = "$col = ?"; $types .= 'i'; $args[] = $v; }
                }
                $st = $conn->prepare(CLM_SELECT . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY c.intimation_date DESC, c.id DESC');
                if ($args) $st->bind_param($types, ...$args);
                $st->execute();
                $rows = array_map(function ($r) { $r = clmRow($r); unset($r['agent_user_id']); return $r; }, $st->get_result()->fetch_all(MYSQLI_ASSOC));
                $st->close();
                clmOut(['success' => true, 'data' => $rows]);

            case 'getPolicyOptions':
                // claimable policies — cancelled out, agent own only
                $st = $conn->prepare("SELECT id, policy_number, vehicle_number, customer_name, company_name, policy_start_date, policy_end_date, idv
                    FROM policy_entries WHERE status <> 'cancelled'" . ($isAgent ? ' AND agent_id = ?' : '') . ' ORDER BY policy_end_date DESC, id DESC');
                if ($isAgent) { $aid = agentIdForUser($conn, $user_id) ?? 0; $st->bind_param('i', $aid); }
                $st->execute();
                $rows = array_map(function ($r) { $r['idv'] = $r['idv'] === null ? null : (float)$r['idv']; return $r; }, $st->get_result()->fetch_all(MYSQLI_ASSOC));
                $st->close();
                clmOut(['success' => true, 'data' => $rows]);

            case 'addClaim':
                clmPost();
                // agents intimate only; status + survey fields are staff work
                $in = $isStaff ? $_POST : array_intersect_key($_POST, array_flip(CLM_AGENT_ADD)) + ['status' => 'intimated'];
                [$err, $d, $pol] = clmCheck($conn, $in, null, $isAdmin);
                if ($err) clmOut(['success' => false, 'message' => $err]);
                [$id, $no] = clmInsert($conn, $d, $user_id);
                clmNotifyNew($conn, $pol, $no, $d, $user_id);
                logActivity($user_id, $username, 'Claim Created', "Claim $no on policy {$pol['policy_number']} (" . CLM_TYPES[$d['claim_type']] . ', claimed ' . number_format($d['amount_claimed'], 2) . ', ' . CLM_STATUS[$d['status']] . ')');
                clmOut(['success' => true, 'message' => "Claim $no saved", 'data' => ['id' => $id, 'claim_number' => $no]]);

            case 'updateClaim':
                clmPost();
                $old = clmFind($conn, (int)($_POST['id'] ?? 0));
                if (!$old) clmOut(['success' => false, 'message' => 'Claim not found']);
                if ($isAgent && $old['status'] !== 'intimated') clmOut(['success' => false, 'message' => 'This claim is already being processed — only staff can change it now']);
                $in = $_POST;
                if ($isAgent) { // intimation fields only, the rest stays as saved
                    $in = $old;
                    foreach (CLM_AGENT_EDIT as $k) $in[$k] = $_POST[$k] ?? '';
                }
                [$err, $d, $pol] = clmCheck($conn, $in, $old, $isAdmin);
                if ($err) clmOut(['success' => false, 'message' => $err]);
                $cols = array_keys(CLM_COLS);
                $st = $conn->prepare('UPDATE claims SET ' . implode(', ', array_map(fn($k) => "$k = ?", $cols)) . ', updated_by = ? WHERE id = ?');
                $vals = array_merge(array_map(fn($k) => $d[$k], $cols), [$user_id, (int)$old['id']]);
                $st->bind_param(implode('', CLM_COLS) . 'ii', ...$vals);
                $st->execute();
                $st->close();
                $no = $old['claim_number'];
                $moved = $old['status'] !== $d['status'];
                if ($moved) {
                    $note = $d['status'] === 'approved' ? ' — approved ' . number_format($d['amount_approved'], 2)
                        : ($d['status'] === 'settled' ? ' — settled ' . number_format($d['amount_settled'], 2) . ' on ' . clmDmy($d['settlement_date'])
                        : ($d['status'] === 'rejected' ? ' — reason: ' . $d['rejection_reason'] : ''));
                    clmNotify($conn, [(int)$pol['agent_user_id']], $user_id, "Claim Update: $no",
                        "Claim $no on policy {$pol['policy_number']} (vehicle {$pol['vehicle_number']}) moved from " . CLM_STATUS[$old['status']] . ' to ' . CLM_STATUS[$d['status']] . "$note.", $pol);
                }
                logActivity($user_id, $username, $moved ? 'Claim Status Changed' : 'Claim Updated',
                    "Claim $no on policy {$pol['policy_number']}" . ($moved ? ': ' . CLM_STATUS[$old['status']] . ' → ' . CLM_STATUS[$d['status']] : ' updated'));
                clmOut(['success' => true, 'message' => "Claim $no updated"]);

            case 'bulkAddClaims':
                if (!$isStaff) clmOut(['success' => false, 'message' => 'Access denied']);
                clmPost();
                $rows = json_decode($_POST['rows'] ?? '[]', true);
                if (!$rows || !is_array($rows)) clmOut(['success' => false, 'message' => 'No claims to add']);
                $off = max(0, (int)($_POST['row_offset'] ?? 0)); // chunked posts keep row numbers global

                $pmap = []; // policy no -> id
                foreach ($conn->query("SELECT id, policy_number FROM policy_entries")->fetch_all() as [$pid, $pno]) $pmap[strtolower(trim($pno))] = (int)$pid;

                $ok = 0; $errors = []; $nos = [];
                foreach ($rows as $i => $r) {
                    $line = $off + $i + 1;
                    if (!is_array($r)) continue;
                    $pno = trim((string)($r['policy_number'] ?? ''));
                    if ($pno === '' && trim((string)($r['incident_date'] ?? '')) === '' && !(float)($r['amount_claimed'] ?? 0)) continue; // blank line
                    if (!isset($pmap[strtolower($pno)])) { $errors[] = "Row $line: unknown policy '$pno'"; continue; }
                    $r['policy_entry_id'] = $pmap[strtolower($pno)];
                    [$err, $d, $pol] = clmCheck($conn, $r, null, $isAdmin);
                    if ($err) { $errors[] = "Row $line: $err"; continue; }
                    try {
                        [, $no] = clmInsert($conn, $d, $user_id);
                    } catch (Throwable $e) {
                        error_log('claim import: ' . $e->getMessage());
                        $errors[] = "Row $line: could not save";
                        continue;
                    }
                    clmNotifyNew($conn, $pol, $no, $d, $user_id);
                    $ok++;
                    $nos[] = $no;
                }
                logActivity($user_id, $username, 'Claims Imported', "Imported $ok claim(s)" . ($nos ? ': ' . implode(', ', array_slice($nos, 0, 20)) . (count($nos) > 20 ? ' …' : '') : '') . ', ' . count($errors) . ' skipped');
                clmOut(['success' => true, 'message' => "$ok claim(s) added.", 'inserted' => $ok, 'errors' => $errors]);

            case 'deleteClaim':
            case 'bulkDeleteClaims':
                if (!$isAdmin) clmOut(['success' => false, 'message' => 'Access denied']);
                clmPost();
                $ids = $act === 'deleteClaim' ? [$_POST['id'] ?? 0] : (array)($_POST['ids'] ?? []);
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
                if (!$ids) clmOut(['success' => false, 'message' => 'No claims selected']);
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $types = str_repeat('i', count($ids));
                $st = $conn->prepare("SELECT claim_number FROM claims WHERE id IN ($ph)");
                $st->bind_param($types, ...$ids);
                $st->execute();
                $nos = array_column($st->get_result()->fetch_all(), 0);
                $st->close();
                if (!$nos) clmOut(['success' => false, 'message' => 'Claim not found']);
                $st = $conn->prepare("SELECT file_path FROM claim_documents WHERE claim_id IN ($ph)");
                $st->bind_param($types, ...$ids);
                $st->execute();
                $paths = array_column($st->get_result()->fetch_all(), 0);
                $st->close();
                $st = $conn->prepare("DELETE FROM claims WHERE id IN ($ph)"); // documents cascade
                $st->bind_param($types, ...$ids);
                $st->execute();
                $st->close();
                clmUnlink($paths);
                logActivity($user_id, $username, 'Claim Deleted', 'Deleted ' . count($nos) . ' claim(s): ' . implode(', ', array_slice($nos, 0, 30)) . (count($nos) > 30 ? ' …' : '') . (count($paths) ? ' with ' . count($paths) . ' document(s)' : ''));
                clmOut(['success' => true, 'message' => count($nos) . ' claim(s) deleted']);

            case 'getDocuments':
                $c = clmFind($conn, (int)($_GET['claim_id'] ?? 0));
                if (!$c) clmOut(['success' => false, 'message' => 'Claim not found']);
                $cid = (int)$c['id'];
                $st = $conn->prepare("SELECT d.id, d.claim_id, d.document_type, d.file_name, d.file_size_kb, d.mime_type, d.created_at, u.name AS uploader_name
                    FROM claim_documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.claim_id = ? ORDER BY d.created_at DESC, d.id DESC");
                $st->bind_param('i', $cid);
                $st->execute();
                $docs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
                $st->close();
                clmOut(['success' => true, 'data' => $docs]);

            case 'uploadDocument':
                clmPost();
                $c = clmFind($conn, (int)($_POST['claim_id'] ?? 0)); // staff any, agent own
                if (!$c) clmOut(['success' => false, 'message' => 'Claim not found']);
                $cid = (int)$c['id'];
                $docType = (string)($_POST['document_type'] ?? 'other');
                if (!isset(CLM_DOCS[$docType])) clmOut(['success' => false, 'message' => 'Invalid document type']);
                $file = $_FILES['document'] ?? null;
                if (!$file || $file['error'] !== UPLOAD_ERR_OK) clmOut(['success' => false, 'message' => 'No file uploaded or upload error']);
                if ($file['size'] > 5 * 1024 * 1024) clmOut(['success' => false, 'message' => 'File too large. Max 5MB']);
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) clmOut(['success' => false, 'message' => 'Invalid file type. Allowed: PDF, JPG, PNG, GIF, WEBP']);
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'], true)) clmOut(['success' => false, 'message' => 'Invalid file extension']);

                $dir = __DIR__ . '/uploads/claims/' . $cid;
                if (!is_dir($dir) && !mkdir($dir, 0755, true)) clmOut(['success' => false, 'message' => 'Failed to save file']);
                $newName = storedName('claim', $ext);
                $rel = 'uploads/claims/' . $cid . '/' . $newName;
                if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $newName)) clmOut(['success' => false, 'message' => 'Failed to save file']);
                $kb = (int)round($file['size'] / 1024);
                $orig = mb_substr(basename($file['name']), 0, 255);
                try {
                    $st = $conn->prepare("INSERT INTO claim_documents (claim_id, document_type, file_name, file_path, file_size_kb, mime_type, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $st->bind_param('isssisi', $cid, $docType, $orig, $rel, $kb, $mime, $user_id);
                    $st->execute();
                    $st->close();
                } catch (Throwable $e) {
                    clmUnlink([$rel]); // no orphan file
                    throw $e;
                }
                logActivity($user_id, $username, 'Claim Document Uploaded', "Uploaded " . CLM_DOCS[$docType] . " to claim {$c['claim_number']}: $orig");
                clmOut(['success' => true, 'message' => 'Document uploaded']);

            case 'deleteDocument':
                if (!$isAdmin) clmOut(['success' => false, 'message' => 'Access denied']);
                clmPost();
                $did = (int)($_POST['id'] ?? 0);
                $st = $conn->prepare("SELECT d.file_path, d.file_name, d.document_type, c.claim_number FROM claim_documents d JOIN claims c ON c.id = d.claim_id WHERE d.id = ?");
                $st->bind_param('i', $did);
                $st->execute();
                $doc = $st->get_result()->fetch_assoc();
                $st->close();
                if (!$doc) clmOut(['success' => false, 'message' => 'Document not found']);
                $st = $conn->prepare("DELETE FROM claim_documents WHERE id = ?");
                $st->bind_param('i', $did);
                $st->execute();
                $st->close();
                clmUnlink([$doc['file_path']]);
                logActivity($user_id, $username, 'Claim Document Deleted', "Deleted " . (CLM_DOCS[$doc['document_type']] ?? 'document') . " from claim {$doc['claim_number']}: {$doc['file_name']}");
                clmOut(['success' => true, 'message' => 'Document deleted']);

            case 'viewDoc':
                // never a public link — login + same access as the claim's policy
                $did = (int)($_GET['id'] ?? 0);
                $st = $conn->prepare("SELECT d.file_path, d.file_name, c.policy_entry_id FROM claim_documents d JOIN claims c ON c.id = d.claim_id WHERE d.id = ?");
                $st->bind_param('i', $did);
                $st->execute();
                $doc = $st->get_result()->fetch_assoc();
                $st->close();
                if (!$doc || !canSeePolicy($conn, $doc['policy_entry_id'])) {
                    http_response_code(404);
                    header('Content-Type: text/plain; charset=utf-8');
                    exit('File not found');
                }
                $conn->close();
                sendStoredFile($doc['file_path'], $doc['file_name']);

            default:
                clmOut(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Throwable $e) {
        error_log('claims.php error: ' . $e->getMessage());
        // 1146 = table missing -> db update not run yet
        clmOut(['success' => false, 'message' => $e->getCode() === 1146 ? 'Claims tables are missing — open setup.php?action=update once to add them' : 'Server error occurred']);
    }
}
?>
<!--
  Developed by Rudees Digital
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
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title><?php echo $isAgent ? 'My Claims' : 'Claims'; ?> - Insurance Management System</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.1/dist/css/tabulator.min.css">
    <link rel="stylesheet" href="styles.css?v=16.0">

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="app.js?v=2.3"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.3.1/dist/js/tabulator.min.js"></script>
    <?php if ($isStaff): ?><script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script><?php endif; ?>
</head>
<body>
<script>/* theme before paint */(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme:dark)').matches))document.body.classList.add('dark-mode');})();</script>
    <?php echo getSearchableDropdownJS(); ?>
    <?php include 'mobile-menu.php'; ?>

    <div class="app-container">
        <?php include 'sidebar.php'; ?>

        <div class="main-content">
            <?php
            $pageIcon    = 'fa-car-burst';
            $pageTitle   = $isAgent ? 'My Claims' : 'Claims';
            $crumbs      = [];
            $pageActions = [['label' => 'Add Claim', 'icon' => 'fa-plus', 'onclick' => 'openAddModal()', 'class' => 'primary']];
            if ($isStaff) $pageActions[] = ['label' => 'Bulk Add / Import', 'icon' => 'fa-file-excel', 'onclick' => 'claimsImport.open()'];
            $pageActions[] = ['label' => 'CSV',   'icon' => 'fa-file-csv', 'onclick' => "dtBtn(claimsTable,'csv')"];
            $pageActions[] = ['label' => 'PDF',   'icon' => 'fa-file-pdf', 'onclick' => "dtBtn(claimsTable,'pdf')"];
            $pageActions[] = ['label' => 'Print', 'icon' => 'fa-print',    'onclick' => "dtBtn(claimsTable,'print')"];
            $pageActions[] = ['label' => 'Refresh', 'icon' => 'fa-rotate', 'onclick' => 'loadClaims()', 'id' => 'claimsRefresh'];
            include 'page_header.php';
            ?>
            <div data-head-filter>
                <div class="hf-field"><label for="claimSearch" title="Search"><i class="fas fa-magnifying-glass"></i></label><input type="search" id="claimSearch" placeholder="Search claims…" autocomplete="off"></div>
                <div class="hf-field" title="Claim type"><label for="filterType"><i class="fas fa-car-burst"></i></label><select id="filterType" class="searchable">
                    <option value="">All Types</option>
                    <?php foreach (CLM_TYPES as $k => $v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
                </select></div>
                <div class="hf-field" title="Insurer"><label for="filterInsurer"><i class="fas fa-building-shield"></i></label><select id="filterInsurer" class="searchable">
                    <option value="">All Insurers</option>
                </select></div>
                <div class="hf-field hf-range" title="Intimation date"><label for="filterDateFrom"><i class="fas fa-calendar-days"></i></label><input type="date" id="filterDateFrom" aria-label="From"><span class="hf-sep"><i class="fas fa-arrow-right-long"></i></span><input type="date" id="filterDateTo" aria-label="To"></div>
                <div class="hf-seg" role="group" aria-label="Quick range"><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',0,this)">Today</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',6,this)">7 days</button><button type="button" onclick="rsDateRange('filterDateFrom','filterDateTo',29,this)">30 days</button></div>
                <?php if ($isStaff): ?>
                <div class="hf-field" title="Agent"><label for="filterAgent"><i class="fas fa-user-tie"></i></label><select id="filterAgent" class="searchable">
                    <option value="">All Agents</option>
                </select></div>
                <?php endif; ?>
                <button type="button" class="btn btn-icon" onclick="clearFilters()" title="Clear filters" aria-label="Clear filters"><i class="fas fa-filter-circle-xmark"></i></button>
            </div>
            <script>rsPullHeadFilters();</script>

            <div class="data-section">
                <!-- status chevrons -->
                <div class="pipeline-stages" id="claimPipe" role="group" aria-label="Claim status"></div>

                <div id="loadingSkeleton">
                    <div class="skeleton-table">
                        <?php for ($i = 0; $i < 7; $i++): ?>
                        <div class="skeleton-row"><div class="skeleton-cell"></div><div class="skeleton-cell"></div><div class="skeleton-cell"></div><div class="skeleton-cell"></div><div class="skeleton-cell"></div><div class="skeleton-cell"></div></div>
                        <?php endfor; ?>
                    </div>
                </div>

                <div id="tableContainer" hidden>
                    <div id="claimsTable"></div>
                </div>

                <div id="emptyState" hidden></div>
            </div>
        </div>
    </div>

    <!-- View Claim Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal modal-wide" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="viewModalTitle"><i class="fas fa-car-burst"></i> Claim Details</h3>
                <button class="close-btn" onclick="closeViewModal()" aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body" id="viewModalBody"></div>
            <div class="form-actions" id="viewModalFooter"></div>
        </div>
    </div>

    <!-- Add / Edit Claim Modal -->
    <div class="modal-overlay" id="claimModal">
        <div class="modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-car-burst"></i> Add Claim</h3>
                <button class="close-btn" onclick="closeModal()" aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="claimForm">
                    <input type="hidden" id="claimId" name="id">

                    <div class="form-grid">
                        <div class="form-section-title"><i class="fas fa-bullhorn"></i> Intimation</div>
                        <div class="form-group form-full-width">
                            <label><i class="fas fa-file-contract"></i> Policy *</label>
                            <select id="cPolicy" name="policy_entry_id" class="searchable" required>
                                <option value="">Select Policy</option>
                            </select>
                            <small class="field-hint" id="cPolicyInfo"></small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-car-burst"></i> Claim Type *</label>
                            <select id="cType" name="claim_type" class="searchable" required>
                                <?php foreach (CLM_TYPES as $k => $v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-money-bill-wave"></i> Amount Claimed</label>
                            <input type="number" id="cAmtClaimed" name="amount_claimed" min="0" step="0.01" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-xmark"></i> Incident Date *</label>
                            <input type="date" id="cIncDate" name="incident_date" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-bullhorn"></i> Intimation Date *</label>
                            <input type="date" id="cIntimDate" name="intimation_date" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-location-dot"></i> Incident Place</label>
                            <input type="text" id="cPlace" name="incident_place" maxlength="150" placeholder="Road, area, city">
                        </div>
                        <div class="form-group" id="cFirGroup" hidden>
                            <label><i class="fas fa-file-shield"></i> FIR No</label>
                            <input type="text" id="cFir" name="fir_number" maxlength="50" placeholder="Police FIR number" disabled>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-receipt"></i> Insurer Claim No</label>
                            <input type="text" id="cInsurerNo" name="insurer_claim_number" maxlength="50" placeholder="Reference from the insurer">
                        </div>
                        <div class="form-group form-full-width">
                            <label><i class="fas fa-align-left"></i> Description</label>
                            <textarea id="cDesc" name="description" rows="3" maxlength="2000" placeholder="What happened"></textarea>
                        </div>

                        <?php if ($isStaff): ?>
                        <div class="form-section-title"><i class="fas fa-clipboard-check"></i> Survey &amp; Settlement</div>
                        <div class="form-group">
                            <label><i class="fas fa-user-check"></i> Surveyor Name</label>
                            <input type="text" id="cSurveyor" name="surveyor_name" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Surveyor Mobile</label>
                            <input type="tel" id="cSurveyorMob" name="surveyor_mobile" maxlength="15" inputmode="tel">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-check"></i> Survey Date</label>
                            <input type="date" id="cSurveyDate" name="survey_date">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-warehouse"></i> Garage</label>
                            <input type="text" id="cGarage" name="garage_name" maxlength="150">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-flag"></i> Status</label>
                            <select id="cStatus" name="status" class="searchable">
                                <?php foreach (CLM_STATUS as $k => $v): ?><option value="<?php echo $k; ?>"><?php echo $v; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-route"></i> Settlement Mode</label>
                            <select id="cMode" name="settlement_mode" class="searchable">
                                <option value="">Not set</option>
                                <option value="cashless">Cashless</option>
                                <option value="reimbursement">Reimbursement</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-circle-check"></i> Amount Approved</label>
                            <input type="number" id="cAmtApproved" name="amount_approved" min="0" step="0.01" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-hand-holding-dollar"></i> Amount Settled</label>
                            <input type="number" id="cAmtSettled" name="amount_settled" min="0" step="0.01" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-day"></i> Settlement Date</label>
                            <input type="date" id="cSettleDate" name="settlement_date">
                        </div>
                        <div class="form-group form-full-width" id="cRejectGroup" hidden>
                            <label><i class="fas fa-ban"></i> Rejection Reason *</label>
                            <input type="text" id="cReject" name="rejection_reason" maxlength="255" placeholder="Why the insurer rejected it" disabled>
                        </div>
                        <div class="form-group form-full-width">
                            <label><i class="fas fa-comment-dots"></i> Remarks</label>
                            <textarea id="cRemarks" name="remarks" rows="2" maxlength="500"></textarea>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="claimSaveBtn"><i class="fas fa-save"></i> Save</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        /** Developed by Rudees Digital */
        const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>, IS_STAFF = <?php echo $isStaff ? 'true' : 'false'; ?>, IS_AGENT = <?php echo $isAgent ? 'true' : 'false'; ?>;
        const CLM_TYPES = <?php echo json_encode(CLM_TYPES); ?>, CLM_STATUS = <?php echo json_encode(CLM_STATUS); ?>;
        const ST_CLS = { intimated: 'status-user', survey: 'status-pending', approved: 'status-admin', settled: 'status-active', rejected: 'status-cancelled', withdrawn: 'status-inactive' };
        const OPEN = ['intimated', 'survey', 'approved'];
        // chevrons: key, label, colour class
        const STAGES = [['', 'All', ''], ['intimated', 'Intimated', 'pipe-unread'], ['survey', 'Survey', 'pipe-unread'], ['approved', 'Approved', 'pipe-unread'], ['settled', 'Settled', 'pipe-read'], ['closed', 'Rejected/Withdrawn', 'pipe-read']];
        // doc types: value, label, icon
        const DOC_TYPES = [['claim_form', 'Claim form', 'fa-file-signature'], ['fir', 'FIR', 'fa-file-shield'], ['estimate', 'Estimate', 'fa-file-invoice'], ['survey_report', 'Survey report', 'fa-clipboard-check'],
            ['repair_invoice', 'Repair invoice', 'fa-file-invoice-dollar'], ['photo', 'Photo', 'fa-camera'], ['settlement_letter', 'Settlement letter', 'fa-envelope-open-text'], ['other', 'Other', 'fa-file']];
        // staff quick moves: next statuses + button look
        const NEXT = { intimated: ['survey', 'approved', 'rejected'], survey: ['approved', 'rejected'], approved: ['settled', 'rejected'] };
        const QUICK = { survey: ['Move to Survey', 'fa-magnifying-glass', 'btn-secondary', 'cSurveyor'], approved: ['Approve', 'fa-circle-check', 'btn-success', 'cAmtApproved'],
            settled: ['Settle', 'fa-hand-holding-dollar', 'btn-success', 'cAmtSettled'], rejected: ['Reject', 'fa-circle-xmark', 'btn-danger', 'cReject'] };
        const QS = new URLSearchParams(location.search);

        let claimsTable = null, bulkSel = null, claimsData = [], policyOpts = [], isEditMode = false, curStage = '', pinPolicy = null, reopenView = 0;

        const ymd = function(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); };
        const money = function(v) { return v == null || v === '' ? '—' : (RS_BRAND.sym || '') + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
        const pill = function(s) { return '<span class="status-badge ' + (ST_CLS[s] || 'status-user') + '">' + escapeHtml(CLM_STATUS[s] || s) + '</span>'; };
        const byId = function(id) { return claimsData.find(function(r) { return r.id === +id; }); };
        const canEdit = function(r) { return IS_STAFF || r.status === 'intimated'; };
        // policy facts from the picker list, else from a claim on it (cancelled policy in edit)
        const polById = function(pid) { pid = +pid; return policyOpts.find(function(p) { return +p.id === pid; }) || claimsData.find(function(r) { return r.policy_entry_id === pid; }); };
        const busy = function($b, on, ico) { $b.prop('disabled', on).find('i').first().attr('class', 'fas ' + (on ? 'fa-spinner fa-spin' : ico)); };

        $(function() {
            initAllSearchableDropdowns();
            renderPipe();
            $.when(loadPolicyOpts(), loadClaims()).then(deepLinks);

            // one delegated handler for row actions
            $('#claimsTable').on('click', '[data-act]', function() {
                var act = $(this).data('act'), id = +$(this).data('id');
                if (act === 'view') viewClaim(id);
                else if (act === 'docs') viewClaim(id, 'docs');
                else if (act === 'edit') editClaim(id);
                else if (act === 'print') printClaim(id);
                else if (act === 'pdf') printClaim(id, 'pdf');
                else if (act === 'delete' && IS_ADMIN) deleteClaim(id);
            });
            $('#claimPipe').on('click', '.pipeline-stage', function() { setStage(String($(this).data('stage') || '')); });
            $('#filterType, #filterInsurer, #filterAgent, #filterDateFrom, #filterDateTo').on('change', applyFilters);
            $('#claimSearch').on('input', function() {
                clearTimeout(this._t);
                var v = this.value;
                this._t = setTimeout(function() {
                    if (pinPolicy && v !== pinPolicy.no) { pinPolicy = null; rsTbWhere(claimsTable, 'policy', null); } // typing drops the deep-link pin
                    rsTbSearch(claimsTable, v);
                    renderPipe();
                }, 200);
            });
            $('#cType, #cStatus').on('change', toggles);
            $('#cPolicy').on('change', policyInfo);
            // a hidden searchable select can't show the browser's own required tip
            $('#claimSaveBtn').on('click', function(e) {
                if ($('#cPolicy').val() || $('#cPolicy').prop('disabled')) return;
                e.preventDefault();
                Swal.fire({ icon: 'warning', title: 'Policy required', text: 'Pick the policy this claim is on.' });
            });
            $('#viewModalBody').on('click', '.doc-type', function() {
                $('.doc-type').removeClass('on').attr('aria-checked', 'false');
                $(this).addClass('on').attr('aria-checked', 'true');
                $('#docType').val($(this).data('type'));
            });
            ['viewModal', 'claimModal'].forEach(function(id) {
                document.getElementById(id).addEventListener('click', function(e) { if (e.target === this) id === 'viewModal' ? closeViewModal() : closeModal(); });
            });
        });

        function loadPolicyOpts() {
            return $.getJSON('?action=getPolicyOptions', function(res) {
                if (!res.success) return;
                policyOpts = res.data;
                var el = document.getElementById('cPolicy');
                el.innerHTML = '<option value="">Select Policy</option>' + policyOpts.map(function(p) {
                    return '<option value="' + (+p.id) + '">' + escapeHtml(p.policy_number + ' · ' + p.vehicle_number + ' · ' + p.customer_name) + '</option>';
                }).join('');
                if (el.sdRefresh) el.sdRefresh();
                if (window.claimsImport && policyOpts[0]) claimsImport.demoPolicy(policyOpts[0].policy_number);
            });
        }

        function loadClaims() {
            var $r = $('#claimsRefresh i').addClass('fa-spin'); // reads: refresh icon spins, skeleton only before the first paint
            return $.getJSON('?action=getClaims', function(res) {
                if (!res.success) { Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load claims' }); return; }
                claimsData = res.data;
                fillFilterOpts();
                var none = !claimsData.length;
                $('#loadingSkeleton').prop('hidden', true);
                $('#tableContainer').prop('hidden', none);
                $('#emptyState').prop('hidden', !none).html(none ? emptyStateHtml({ icon: 'fa-car-burst', title: 'No claims yet', text: 'Intimate a claim to start tracking it.', btnText: 'Add Claim', btnId: 'emptyAddBtn' }) : '');
                if (none) $('#emptyAddBtn').on('click', function() { openAddModal(); });
                if (!none) buildTable();
                renderPipe();
                if (window.sbPill) sbPill('claims', claimsData.filter(function(r) { return OPEN.indexOf(r.status) > -1; }).length);
            }).fail(function() {
                $('#loadingSkeleton').prop('hidden', true);
                Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Could not connect to server' });
            }).always(function() { $r.removeClass('fa-spin'); });
        }

        // insurer + agent pickers from the claims on screen
        function fillFilterOpts() {
            var uniq = function(k) { return claimsData.map(function(r) { return r[k]; }).filter(function(v, i, a) { return v && a.indexOf(v) === i; }).sort(); };
            [['filterInsurer', 'company_name', 'All Insurers'], ['filterAgent', 'agent_name', 'All Agents']].forEach(function(f) {
                var el = document.getElementById(f[0]);
                if (!el) return;
                var cur = el.value, vals = uniq(f[1]);
                el.innerHTML = '<option value="">' + f[2] + '</option>' + vals.map(function(v) { return '<option value="' + escapeHtml(v) + '">' + escapeHtml(v) + '</option>'; }).join('');
                el.value = vals.indexOf(cur) > -1 ? cur : '';
                if (el.sdRefresh) el.sdRefresh();
            });
        }

        function buildTable() {
            if (claimsTable) {
                rsTbData(claimsTable, claimsData);
                if (claimsTable.rsBuilt) claimsTable.redraw(true);
                return;
            }
            var cols = [];
            if (IS_ADMIN) cols.push({ data: 'id', title: '', className: 'bulk-col', orderable: false, searchable: false });
            cols.push(
                { data: 'claim_number', title: 'Claim No', responsivePriority: 0, render: function(d, t) { return t === 'display' ? '<strong>' + escapeHtml(d) + '</strong>' : d; } },
                { data: 'policy_number', title: 'Policy No', responsivePriority: 3 },
                { data: 'customer_name', title: 'Customer', responsivePriority: 2 },
                { data: 'vehicle_number', title: 'Vehicle', responsivePriority: 8 },
                { data: 'claim_type', title: 'Type', responsivePriority: 7, render: function(d) { return escapeHtml(CLM_TYPES[d] || d); } },
                { data: 'intimation_date', title: 'Intimated', responsivePriority: 4 },
                { data: 'amount_claimed', title: 'Claimed', responsivePriority: 5, render: function(d) { return escapeHtml(money(d)); } },
                { data: 'amount_approved', title: 'Approved / Settled', responsivePriority: 6, render: function(d, t, r) { return escapeHtml(money(d) + ' / ' + money(r.amount_settled)); } },
                { data: 'status', title: 'Status', responsivePriority: 1, render: function(d, t) { return t === 'display' ? pill(d) : (CLM_STATUS[d] || d); } },
                { data: 'company_name', title: 'Insurer', visible: false, responsivePriority: 9 },
                { data: null, orderable: false, title: 'Actions', render: function(d, t, r) {
                    var id = +r.id, h = '<div class="action-hub"><button class="action-icon view-icon" data-act="view" data-id="' + id + '" title="View" aria-label="View"><i class="fas fa-eye"></i></button>';
                    if (canEdit(r)) h += '<button class="action-icon edit-icon" data-act="edit" data-id="' + id + '" title="Edit" aria-label="Edit"><i class="fas fa-pen"></i></button>';
                    h += '<div class="more-menu"><button class="more-btn" title="More" aria-label="More"><i class="fas fa-ellipsis-v"></i></button><div class="more-dropdown">'
                        + '<button class="more-item" data-act="docs" data-id="' + id + '"><i class="fas fa-folder-open"></i> Documents</button>'
                        + '<button class="more-item" data-act="print" data-id="' + id + '"><i class="fas fa-print"></i> Print</button>'
                        + '<button class="more-item" data-act="pdf" data-id="' + id + '"><i class="fas fa-file-pdf"></i> PDF</button>';
                    if (IS_ADMIN) h += '<div class="more-divider"></div><button class="more-item danger" data-act="delete" data-id="' + id + '"><i class="fas fa-trash"></i> Delete</button>';
                    return h + '</div></div></div>';
                } }
            );
            claimsTable = rsTable('#claimsTable', {
                columns: cols, data: claimsData, title: IS_AGENT ? 'My Claims' : 'Claims', file: 'claims', sort: ['intimation_date', 'desc'], bulk: IS_ADMIN,
                rowClass: function(r) { return r.status === 'settled' ? 'dt-row-success' : (r.status === 'rejected' || r.status === 'withdrawn' ? 'dt-row-muted' : ''); },
                info: function() {
                    var f = $('#filterDateFrom').val(), t = $('#filterDateTo').val(), st = STAGES.find(function(s) { return s[0] === curStage; });
                    return [['Status', curStage ? st[1] : 'All'], ['Type', CLM_TYPES[$('#filterType').val()] || 'All'], ['Insurer', $('#filterInsurer').val() || 'All'],
                        ['Intimated', (f || t) ? (f || '…') + ' to ' + (t || '…') : 'All']].concat(IS_STAFF ? [['Agent', $('#filterAgent').val() || 'All']] : [], pinPolicy ? [['Policy', pinPolicy.no]] : []);
                }
            });
            if (IS_ADMIN) bulkSel = initBulkSelect({
                table: claimsTable, label: 'claims',
                onDelete: function(ids) {
                    var done = rsOverlay('Deleting…');
                    $.post('?action=bulkDeleteClaims', { csrf_token: window.CSRF_TOKEN, ids: ids }, null, 'json')
                        .done(function(res) {
                            if (!res.success) return Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                            bulkSel.clear();
                            loadClaims();
                            Swal.fire({ icon: 'success', text: res.message, timer: 1800, showConfirmButton: false });
                        })
                        .fail(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); })
                        .always(done);
                }
            });
            rsTbSearch(claimsTable, $('#claimSearch').val());
            applyFilters();
        }

        // header filters -> row-data predicates (status lives in the chevrons)
        function applyFilters() {
            if (!claimsTable) return;
            var ty = $('#filterType').val(), ins = $('#filterInsurer').val(), ag = $('#filterAgent').val(), f = $('#filterDateFrom').val(), t = $('#filterDateTo').val();
            rsTbWhere(claimsTable, 'type', ty ? function(r) { return r.claim_type === ty; } : null);
            rsTbWhere(claimsTable, 'insurer', ins ? function(r) { return r.company_name === ins; } : null);
            rsTbWhere(claimsTable, 'agent', ag ? function(r) { return r.agent_name === ag; } : null);
            rsTbWhere(claimsTable, 'date', (f || t) ? function(r) { var d = String(r.intimation_date || '').slice(0, 10); return !(f && d < f) && !(t && d > t); } : null);
            renderPipe();
        }

        function stageFn(s) {
            return !s ? null : s === 'closed' ? function(r) { return r.status === 'rejected' || r.status === 'withdrawn'; } : function(r) { return r.status === s; };
        }
        function setStage(s) {
            curStage = s;
            rsTbWhere(claimsTable, 'status', stageFn(s));
            renderPipe();
        }
        // counts = rows passing every filter except the status one
        function renderPipe() {
            var t = claimsTable, rows = claimsData;
            if (t) {
                var q = String(t.rsQ || '').trim().toLowerCase(),
                    ps = Object.keys(t.rsWhere).filter(function(k) { return k !== 'status' && t.rsWhere[k]; }).map(function(k) { return t.rsWhere[k]; });
                rows = claimsData.filter(function(r) { return ps.every(function(p) { return p(r); }) && (!q || t.rsBlob(r).indexOf(q) > -1); });
            }
            $('#claimPipe').html(STAGES.map(function(s) {
                var fn = stageFn(s[0]), on = s[0] === curStage;
                return '<button type="button" class="pipeline-stage ' + s[2] + (on ? ' active' : '') + '" data-stage="' + s[0] + '" aria-pressed="' + on + '">'
                    + '<span class="pipeline-stage-name">' + s[1] + '</span><span class="pipeline-stage-count">' + (fn ? rows.filter(fn).length : rows.length) + '</span></button>';
            }).join(''));
        }

        function clearFilters() {
            ['filterType', 'filterInsurer', 'filterAgent'].forEach(function(id) { var el = document.getElementById(id); if (el) { el.value = ''; if (el.sdSetVal) el.sdSetVal(''); } });
            $('#filterDateFrom, #filterDateTo, #claimSearch').val('');
            $('.hf-seg button').removeClass('is-on');
            curStage = ''; pinPolicy = null;
            rsTbClear(claimsTable);
            renderPipe();
        }

        // ?policy_id= pins the list, &new=1 opens Add on it, ?id= opens a claim
        function deepLinks() {
            var pid = +QS.get('policy_id') || 0, id = +QS.get('id') || 0, isNew = QS.get('new') === '1';
            if (pid) {
                var p = polById(pid);
                pinPolicy = { id: pid, no: p ? p.policy_number : '#' + pid };
                $('#claimSearch').val(pinPolicy.no);
                rsTbWhere(claimsTable, 'policy', function(r) { return r.policy_entry_id === pid; });
                renderPipe();
            }
            if (isNew) openAddModal(pid);
            else if (id) byId(id) ? viewClaim(id) : Swal.fire({ icon: 'info', title: 'Claim not found', text: 'It may have been deleted, or it is not on your policies.' });
            if (isNew || id) history.replaceState(null, '', location.pathname + (pid ? '?policy_id=' + pid : ''));
        }

        // ---------- add / edit ----------
        function refreshSelects() { $('#claimForm select.searchable').each(function() { if (this.sdRefresh) this.sdRefresh(); }); }

        function policyInfo() {
            var p = polById($('#cPolicy').val());
            $('#cPolicyInfo').html(p ? '<i class="fas fa-building-shield"></i> ' + escapeHtml(p.company_name) + ' · ' + escapeHtml(rsPaperDate(p.policy_start_date)) + ' – ' + escapeHtml(rsPaperDate(p.policy_end_date))
                + (p.idv > 0 ? ' · IDV ' + escapeHtml(money(p.idv)) : '') : '').toggle(!!p);
        }
        // FIR only for theft / third party, reason only when rejected
        function toggles() {
            var ty = $('#cType').val(), fir = ty === 'theft' || ty === 'third_party', rej = $('#cStatus').val() === 'rejected';
            $('#cFirGroup').prop('hidden', !fir).find('input').prop('disabled', !fir);
            $('#cRejectGroup').prop('hidden', !rej).find('input').prop('disabled', !rej);
            policyInfo();
        }
        // agent edit: policy, intimation date + insurer ref stay as saved
        function lockAgent(on) {
            $('#cIntimDate, #cInsurerNo').prop('disabled', on);
            var s = document.getElementById('cPolicy'), w = s.previousElementSibling;
            s.disabled = on;
            if (w && w.querySelector) $(w.querySelector('input')).prop('disabled', on);
        }
        function openForm(title) {
            $('#modalTitle').html(title);
            $('#cIncDate, #cIntimDate').attr('max', ymd(new Date()));
            refreshSelects();
            toggles();
            $('#claimModal').addClass('active');
        }

        function openAddModal(pid) {
            isEditMode = false;
            reopenView = 0;
            $('#claimForm')[0].reset();
            $('#claimId').val('');
            $('#cPolicy option[data-tmp]').remove();
            lockAgent(false);
            $('#cIntimDate').val(ymd(new Date()));
            if (pid) {
                $('#cPolicy').val(policyOpts.some(function(p) { return +p.id === +pid; }) ? pid : '');
                if (!$('#cPolicy').val()) Swal.fire({ icon: 'warning', title: 'Policy not available', text: 'That policy is cancelled or not on your book.' });
            }
            openForm('<i class="fas fa-car-burst"></i> Add Claim');
        }

        function editClaim(id, st) {
            var c = byId(id);
            if (!c || !canEdit(c)) return;
            isEditMode = true;
            var f = document.getElementById('claimForm');
            f.reset();
            $('#cPolicy option[data-tmp]').remove();
            if (!policyOpts.some(function(p) { return +p.id === c.policy_entry_id; })) // cancelled since -> keep it pickable for this edit
                $('#cPolicy').append($('<option data-tmp="1">').val(c.policy_entry_id).text(c.policy_number + ' · ' + c.vehicle_number + ' · ' + c.customer_name));
            $(f).find('[name]').each(function() { if (this.name in c) $(this).val(c[this.name] == null ? '' : c[this.name]); });
            if (st) {
                $('#cStatus').val(st);
                if (st === 'survey' && !$('#cSurveyDate').val()) $('#cSurveyDate').val(ymd(new Date()));
                if (st === 'settled' && !$('#cSettleDate').val()) $('#cSettleDate').val(ymd(new Date()));
            }
            lockAgent(IS_AGENT);
            openForm('<i class="fas fa-pen"></i> Edit Claim ' + escapeHtml(c.claim_number));
            if (st && QUICK[st]) setTimeout(function() { $('#' + QUICK[st][3]).trigger('focus'); }, 150);
        }

        function closeModal() { $('#claimModal').removeClass('active'); }

        $('#claimForm').on('submit', function(e) {
            e.preventDefault();
            var fd = new FormData(this), btn = $('#claimSaveBtn'), done = rsOverlay('Saving…'), back = reopenView;
            fd.append('csrf_token', window.CSRF_TOKEN);
            busy(btn, true, 'fa-save');
            $.ajax({ url: '?action=' + (isEditMode ? 'updateClaim' : 'addClaim'), method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
                .done(function(res) {
                    if (!res.success) return Swal.fire({ icon: 'error', title: 'Could not save', text: res.message });
                    closeModal();
                    Swal.fire({ icon: 'success', text: res.message, timer: 1800, showConfirmButton: false });
                    loadClaims().done(function() { if (back && byId(back)) viewClaim(back); });
                })
                .fail(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); })
                .always(function() { done(); busy(btn, false, 'fa-save'); });
        });

        function deleteClaim(id) {
            var c = byId(id);
            if (!c) return;
            Swal.fire({ icon: 'warning', title: 'Delete claim ' + c.claim_number + '?', text: 'Its documents are deleted too. This cannot be undone.',
                showCancelButton: true, confirmButtonColor: 'var(--danger)', confirmButtonText: 'Delete', cancelButtonText: 'Cancel'
            }).then(function(r) {
                if (!r.isConfirmed) return;
                var done = rsOverlay('Deleting…');
                $.post('?action=deleteClaim', { id: id, csrf_token: window.CSRF_TOKEN }, null, 'json')
                    .done(function(res) {
                        if (!res.success) return Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                        closeViewModal();
                        loadClaims();
                        Swal.fire({ icon: 'success', text: res.message, timer: 1800, showConfirmButton: false });
                    })
                    .fail(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); })
                    .always(done);
            });
        }

        // ---------- view ----------
        // [icon, label, html] pairs -> label | value grid, two per row
        function kv(rows) {
            var cell = function(x) { return '<td class="vd-label"><i class="fas ' + x[0] + '"></i> ' + x[1] + '</td>'; }, h = '<table class="view-detail-table">';
            for (var i = 0; i < rows.length; i += 2) {
                var a = rows[i], b = rows[i + 1];
                h += '<tr>' + cell(a) + '<td class="vd-value"' + (b ? '' : ' colspan="3"') + '>' + a[2] + '</td>' + (b ? cell(b) + '<td class="vd-value">' + b[2] + '</td>' : '') + '</tr>';
            }
            return h + '</table>';
        }

        function viewClaim(id, tab) {
            var c = byId(id);
            if (!c) return;
            var e = function(v) { return v == null || v === '' ? '-' : escapeHtml(v); };
            var main = [
                ['fa-hashtag', 'Claim Number', '<strong>' + e(c.claim_number) + '</strong>'], ['fa-flag', 'Status', pill(c.status)],
                ['fa-file-contract', 'Policy', '<a href="policy_entries.php?id=' + (+c.policy_entry_id) + '">' + e(c.policy_number) + '</a>'], ['fa-building-shield', 'Insurer', e(c.company_name)],
                ['fa-user', 'Customer', e(c.customer_name) + (c.customer_mobile ? ' · ' + e(c.customer_mobile) : '')], ['fa-car', 'Vehicle', e(c.vehicle_number) + (c.vehicle_type ? ' · ' + e(c.vehicle_type) : '')],
                ['fa-car-burst', 'Claim Type', e(CLM_TYPES[c.claim_type] || c.claim_type)], ['fa-money-bill-wave', 'Amount Claimed', '<strong>' + e(money(c.amount_claimed)) + '</strong>'],
                ['fa-calendar-xmark', 'Incident Date', e(c.incident_date)], ['fa-bullhorn', 'Intimation Date', e(c.intimation_date)],
                // empty extras skipped — the summary panel shows real facts only
                c.incident_place && ['fa-location-dot', 'Incident Place', e(c.incident_place)], c.fir_number && ['fa-file-shield', 'FIR No', e(c.fir_number)],
                c.insurer_claim_number && ['fa-receipt', 'Insurer Claim No', e(c.insurer_claim_number)],
                c.amount_approved != null && ['fa-circle-check', 'Amount Approved', e(money(c.amount_approved))],
                c.amount_settled != null && ['fa-hand-holding-dollar', 'Amount Settled', e(money(c.amount_settled))], c.settlement_date && ['fa-calendar-day', 'Settlement Date', e(c.settlement_date)],
                c.description && ['fa-align-left', 'Description', e(c.description)]
            ].filter(Boolean);
            var survey = [
                ['fa-user-check', 'Surveyor', e(c.surveyor_name)], ['fa-phone', 'Surveyor Mobile', e(c.surveyor_mobile)],
                ['fa-calendar-check', 'Survey Date', e(c.survey_date)], ['fa-warehouse', 'Garage', e(c.garage_name)],
                ['fa-route', 'Settlement Mode', e(c.settlement_mode ? c.settlement_mode.charAt(0).toUpperCase() + c.settlement_mode.slice(1) : '')], ['fa-user-tie', 'Agent', e(c.agent_name || 'Direct')]
            ].concat(c.status === 'rejected' ? [['fa-ban', 'Rejection Reason', e(c.rejection_reason)]] : [], [
                ['fa-comment-dots', 'Remarks', e(c.remarks)],
                ['fa-user-pen', 'Created', e(c.created_by_name) + ' · ' + e(c.created_at)], ['fa-clock-rotate-left', 'Last Updated', c.updated_at ? e(c.updated_by_name || '') + ' · ' + e(c.updated_at) : '-']
            ]);

            var html = '<div class="tab-nav">'
                + '<button type="button" class="tab-btn" data-tab="details"><i class="fas fa-list"></i> Details</button>'
                + '<button type="button" class="tab-btn" data-tab="docs"><i class="fas fa-folder-open"></i> Documents</button></div>'
                + '<div class="tab-content" data-tab="details">' + kv(main)
                + '<div class="form-section-title"><i class="fas fa-clipboard-check"></i> Survey &amp; Settlement</div>' + kv(survey) + '</div>'
                + '<div class="tab-content" data-tab="docs"><div class="doc-panel">'
                + '<div class="doc-head"><span><i class="fas fa-folder-open"></i> Files on this claim</span><em id="docsCount"></em></div>'
                + '<div id="docsList"><div class="doc-empty"><i class="fas fa-spinner fa-spin"></i><div><b>Loading documents…</b></div></div></div>'
                + '<div class="doc-upload"><div class="doc-upload-title"><i class="fas fa-cloud-arrow-up"></i> Add a document</div>'
                + '<input type="file" id="docFile" class="file-input" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp" data-max-mb="5" data-hint="PDF, JPG, PNG, GIF, WEBP — max 5 MB">'
                + '<div class="doc-upload-row"><input type="hidden" id="docType" value="' + DOC_TYPES[0][0] + '"><div class="doc-types" role="radiogroup" aria-label="Document type">'
                + DOC_TYPES.map(function(t, i) { return '<button type="button" class="doc-type' + (i ? '' : ' on') + '" role="radio" aria-checked="' + (i ? 'false' : 'true') + '" data-type="' + t[0] + '"><i class="fas ' + t[2] + '"></i> ' + t[1] + '</button>'; }).join('')
                + '</div><button type="button" class="btn btn-primary" id="docUploadBtn" onclick="uploadDoc(' + (+c.id) + ')"><i class="fas fa-upload"></i> Upload</button></div></div>'
                + '</div></div>';

            var foot = canEdit(c) ? '<button type="button" class="btn btn-secondary" onclick="editFromView(' + (+c.id) + ')"><i class="fas fa-pen"></i> Edit</button>' : '';
            if (IS_STAFF) (NEXT[c.status] || []).forEach(function(s) {
                foot += '<button type="button" class="btn ' + QUICK[s][2] + '" onclick="editFromView(' + (+c.id) + ',\'' + s + '\')"><i class="fas ' + QUICK[s][1] + '"></i> ' + QUICK[s][0] + '</button>';
            });
            foot += '<button type="button" class="btn btn-primary" onclick="printClaim(' + (+c.id) + ')"><i class="fas fa-print"></i> Print</button>'
                + '<button type="button" class="btn btn-secondary" onclick="closeViewModal()"><i class="fas fa-times"></i> Close</button>';

            $('#viewModalBody').html(html);
            $('#viewModalFooter').html(foot);
            initModalTabs('#viewModalBody');
            if (window.rsDropzone) rsDropzone(document.getElementById('docFile'));
            if (tab) $('#viewModalBody .tab-btn[data-tab="' + tab + '"]').trigger('click');
            $('#viewModal').addClass('active');
            loadDocs(c.id);
        }
        function editFromView(id, st) { closeViewModal(); editClaim(id, st); reopenView = id; }
        function closeViewModal() { $('#viewModal').removeClass('active'); }

        function loadDocs(cid) {
            $.getJSON('?action=getDocuments&claim_id=' + cid, function(res) {
                var docs = res.success ? res.data : [], lbl = {};
                DOC_TYPES.forEach(function(t) { lbl[t[0]] = t[1]; });
                $('#docsCount').text(docs.length ? docs.length + (docs.length === 1 ? ' file' : ' files') : '');
                if (!docs.length) { $('#docsList').html('<div class="doc-empty"><i class="fas fa-folder-open"></i><div><b>No documents yet</b><small>Nothing has been uploaded for this claim.</small></div></div>'); return; }
                $('#docsList').html('<ul class="doc-list">' + docs.map(function(d) {
                    var pdf = String(d.mime_type || '').indexOf('pdf') > -1, did = parseInt(d.id, 10);
                    return '<li class="doc-item"><div class="doc-icon' + (pdf ? ' is-pdf' : '') + '"><i class="fas ' + (pdf ? 'fa-file-pdf' : 'fa-file-image') + '"></i></div>'
                        + '<div class="doc-info"><div class="doc-name">' + escapeHtml(d.file_name) + '</div>'
                        + '<div class="doc-meta">' + escapeHtml(lbl[d.document_type] || d.document_type) + ' &middot; ' + (parseInt(d.file_size_kb, 10) || 0) + ' KB' + (d.uploader_name ? ' &middot; ' + escapeHtml(d.uploader_name) : '') + ' &middot; ' + escapeHtml(String(d.created_at || '').slice(0, 10)) + '</div></div>'
                        + '<div class="doc-actions"><a href="?action=viewDoc&amp;id=' + did + '" target="_blank" rel="noopener" class="head-btn" title="Open" aria-label="Open ' + escapeHtml(d.file_name) + '"><i class="fas fa-up-right-from-square"></i></a>'
                        + (IS_ADMIN ? '<button type="button" class="head-btn doc-del" title="Delete" aria-label="Delete ' + escapeHtml(d.file_name) + '" onclick="deleteDoc(' + did + ', ' + (+cid) + ', this)"><i class="fas fa-trash"></i></button>' : '')
                        + '</div></li>';
                }).join('') + '</ul>');
            });
        }

        function uploadDoc(cid) {
            var inp = document.getElementById('docFile');
            if (!inp.files.length) { Swal.fire({ icon: 'warning', title: 'No file', text: 'Pick a file to upload' }); return; }
            var fd = new FormData(), btn = $('#docUploadBtn'), done = rsOverlay('Uploading…');
            fd.append('claim_id', cid);
            fd.append('document', inp.files[0]);
            fd.append('document_type', $('#docType').val());
            fd.append('csrf_token', window.CSRF_TOKEN);
            busy(btn, true, 'fa-upload');
            $.ajax({ url: '?action=uploadDocument', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
                .done(function(res) {
                    if (!res.success) return Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                    inp.value = '';
                    loadDocs(cid);
                    Swal.fire({ icon: 'success', text: res.message, timer: 1600, showConfirmButton: false });
                })
                .fail(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Upload failed' }); })
                .always(function() { done(); busy(btn, false, 'fa-upload'); });
        }

        function deleteDoc(did, cid, el) {
            Swal.fire({ icon: 'warning', title: 'Delete this document?', showCancelButton: true, confirmButtonColor: 'var(--danger)', confirmButtonText: 'Delete' }).then(function(r) {
                if (!r.isConfirmed) return;
                var $b = $(el), done = rsOverlay('Deleting…');
                busy($b, true, 'fa-trash');
                $.post('?action=deleteDocument', { id: did, csrf_token: window.CSRF_TOKEN }, null, 'json')
                    .done(function(res) { res.success ? loadDocs(cid) : Swal.fire({ icon: 'error', title: 'Error', text: res.message }); })
                    .fail(function() { Swal.fire({ icon: 'error', title: 'Error', text: 'Connection error' }); })
                    .always(function() { done(); busy($b, false, 'fa-trash'); });
            });
        }

        // claim summary -> A4 print / PDF
        function printClaim(id, kind) {
            var c = byId(id);
            if (!c) return;
            var rows = [
                { k: 'Policy', v: c.policy_number + ' · ' + rsPaperDate(c.policy_start_date) + ' to ' + rsPaperDate(c.policy_end_date) },
                { k: 'Insurer', v: c.company_name + (c.insurer_claim_number ? ' · claim ' + c.insurer_claim_number : '') },
                { k: 'Claim type', v: CLM_TYPES[c.claim_type] || c.claim_type },
                { k: 'Incident', v: rsPaperDate(c.incident_date) + (c.incident_place ? ' · ' + c.incident_place : '') },
                c.fir_number && { k: 'FIR no', v: c.fir_number },
                { k: 'Surveyor', v: [c.surveyor_name, c.surveyor_mobile].filter(Boolean).join(' · ') || '-' },
                { k: 'Survey date', v: rsPaperDate(c.survey_date) || '-' },
                { k: 'Garage', v: c.garage_name || '-' },
                { k: 'Settlement', v: [c.settlement_mode ? c.settlement_mode.charAt(0).toUpperCase() + c.settlement_mode.slice(1) : '', rsPaperDate(c.settlement_date)].filter(Boolean).join(' · ') || '-' },
                c.status === 'rejected' && { k: 'Rejection reason', v: c.rejection_reason },
                c.remarks && { k: 'Remarks', v: c.remarks }
            ].filter(Boolean);
            rsReportExport(kind || 'print', {
                title: 'Claim Summary', number: c.claim_number,
                party: { label: 'Insured', name: c.customer_name, lines: [c.vehicle_number + (c.vehicle_type ? ' · ' + c.vehicle_type : ''), c.customer_mobile ? 'Mobile: ' + c.customer_mobile : ''].filter(Boolean) },
                info: [['Intimated', rsPaperDate(c.intimation_date)], ['Incident', rsPaperDate(c.incident_date)], ['Agent', c.agent_name || 'Direct'], ['Status', String(CLM_STATUS[c.status] || c.status).toUpperCase()]],
                cols: [{ title: '#', field: '#', align: 'center' }, { title: 'Particulars', field: 'k', bold: true }, { title: 'Details', get: function(r) { return r.v || ''; } }],
                rows: rows,
                totals: [['Amount claimed', rsMoney(c.amount_claimed)], ['Amount approved', c.amount_approved == null ? '-' : rsMoney(c.amount_approved)], ['AMOUNT SETTLED', c.amount_settled == null ? '-' : rsMoney(c.amount_settled)]],
                notesTitle: 'Description', notes: c.description || '',
                footNote: 'Computer-generated claim summary. No signature or stamp is required.'
            }, c.claim_number);
        }

        <?php if ($isStaff): ?>
        // bulk add / excel import — engine lives in sidebar.php, rules = single add
        var claimsImport = (function() {
            var back = function(n) { var d = new Date(); d.setDate(d.getDate() - n); return ymd(d); };
            var opts = function(o, blank) { return (blank ? [{ v: '', t: blank }] : []).concat(Object.keys(o).map(function(k) { return { v: k, t: o[k] }; })); };
            var pol = { key: 'policy_number', label: 'Policy No *', cls: 'bg-pol', req: true, max: 50, ph: 'Policy number', aliases: ['policynumber', 'policyno', 'policy', 'polno'], demo: '', demo2: '' };
            var imp = RSImport({
                key: 'claims', title: 'Bulk Add / Import Claims', url: '?action=bulkAddClaims',
                onDone: function() { loadClaims(); },
                fields: [
                    pol,
                    { key: 'claim_type', label: 'Type', cls: 'bg-type', type: 'select', opts: opts(CLM_TYPES), def: 'own_damage', aliases: ['claimtype', 'type', 'losstype'], demo: 'own_damage', demo2: 'third_party' },
                    { key: 'incident_date', label: 'Incident Date *', cls: 'bg-date', type: 'date', req: true, aliases: ['incidentdate', 'accidentdate', 'lossdate', 'dateofloss'], demo: back(4), demo2: back(3) },
                    { key: 'intimation_date', label: 'Intimation Date *', cls: 'bg-date', type: 'date', req: true, aliases: ['intimationdate', 'reporteddate', 'claimdate', 'date'], demo: back(3), demo2: back(2) },
                    { key: 'incident_place', label: 'Place', cls: 'bg-src', max: 150, ph: 'optional', aliases: ['incidentplace', 'place', 'location'], demo: 'Demo City', demo2: '' },
                    { key: 'description', label: 'Description', cls: 'bg-remarks', max: 2000, ph: 'optional', aliases: ['description', 'desc', 'details', 'remarks', 'narration'], demo: 'Demo front bumper damage', demo2: '' },
                    { key: 'amount_claimed', label: 'Claimed', cls: 'bg-amt', type: 'number', aliases: ['amountclaimed', 'claimamount', 'amount', 'estimate'], demo: 25000, demo2: 12000 },
                    { key: 'insurer_claim_number', label: 'Insurer Claim No', cls: 'bg-ref', max: 50, ph: 'optional', aliases: ['insurerclaimnumber', 'insurerclaimno', 'insurerref', 'claimno'], demo: 'INS-1001', demo2: '' },
                    { key: 'status', label: 'Status', cls: 'bg-mode', type: 'select', opts: opts(CLM_STATUS), def: 'intimated', aliases: ['status', 'claimstatus'], demo: 'intimated', demo2: 'survey' }
                ]
            });
            imp.demoPolicy = function(no) { pol.demo = pol.demo2 = no; }; // template rows use a real policy
            return imp;
        })();
        <?php endif; ?>
    </script>
</body>
</html>
