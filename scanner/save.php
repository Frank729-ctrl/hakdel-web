<?php
// save_scan.php — called by scanner.php JS after scan completes
// Receives JSON body, saves to scans table, awards XP, checks badges
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json');

// Must be logged in
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

// Read JSON body
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$job_id     = trim($body['job_id']     ?? '');
$target_url = trim($body['target_url'] ?? '');
$profile    = trim($body['profile']    ?? 'quick');
$modules    = $body['modules']         ?? [];
$score      = (int)($body['score']     ?? 0);
$grade      = trim($body['grade']      ?? 'F');
$summary    = trim($body['summary']    ?? '');
$result     = $body['result']          ?? [];

if (!$job_id || !$target_url) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing job_id or target_url']);
    exit;
}

// Check if this job_id already saved (prevent duplicates)
$existing = db()->prepare('SELECT id FROM scans WHERE job_id = ?');
$existing->execute([$job_id]);
if ($existing->fetch()) {
    echo json_encode(['status' => 'already_saved']);
    exit;
}

// Save scan
$stmt = db()->prepare('
    INSERT INTO scans (user_id, job_id, target_url, profile, modules, status, score, grade, summary, result_json)
    VALUES (?, ?, ?, ?, ?, "done", ?, ?, ?, ?)
');
$stmt->execute([
    $user['id'],
    $job_id,
    $target_url,
    $profile,
    json_encode($modules),
    $score,
    $grade,
    $summary,
    json_encode($result),
]);
$scan_id = db()->lastInsertId();

// Scans do NOT award XP — scanning is a tool, not a learning activity.

// Check badges
$new_badges = check_and_award_badges((int)$user['id']);

echo json_encode([
    'status'     => 'saved',
    'id'         => $scan_id,
    'scan_id'    => $scan_id,
    'xp_awarded' => 0,
    'new_badges' => array_map(fn($b) => ['name' => $b['name'], 'icon' => $b['icon']], $new_badges),
]);