<?php
/**
 * Secure Document Streamer for Verification Preview
 * Insurance Management System
 */
require_once 'config.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

if (!$userId) {
    http_response_code(403);
    exit('Unauthorized');
}

$conn = getDBConnection();
$extId = (int)($_GET['ext_id'] ?? 0);

$st = $conn->prepare("SELECT agent_id, file_path FROM policy_extractions WHERE id = ? LIMIT 1");
$st->bind_param("i", $extId);
$st->execute();
$r = $st->get_result()->fetch_assoc();
$st->close();

if (!$r) {
    http_response_code(404);
    exit('Document extraction not found');
}

if ($role === 'agent') {
    $aid = agentIdForUser($conn, $userId);
    if ((int)$r['agent_id'] !== $aid) {
        http_response_code(403);
        exit('Access denied to this document');
    }
}

sendStoredFile($r['file_path']);
