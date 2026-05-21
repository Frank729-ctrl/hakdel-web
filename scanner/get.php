<?php
// get_scan.php — returns full scan result for history modal
require_once __DIR__ . '/../config/app.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit; }

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }

// Only return scans belonging to this user
$stmt = db()->prepare('SELECT * FROM scans WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $user['id']]);
$scan = $stmt->fetch();

if (!$scan) { http_response_code(404); echo json_encode(['error' => 'Scan not found']); exit; }

$result = json_decode($scan['result_json'], true) ?? [];
$findings = $result['findings'] ?? [];

echo json_encode([
    'id'         => $scan['id'],
    'target_url' => $scan['target_url'],
    'score'      => (int)$scan['score'],
    'grade'      => $scan['grade'],
    'summary'    => $scan['summary'],
    'scanned_at' => $scan['scanned_at'],
    'profile'    => $scan['profile'],
    'findings'   => $findings,
]);