<?php
require_once __DIR__ . '/../config/app.php';
header('Content-Type: application/json');
$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['error'=>'Not authenticated']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body   = json_decode(file_get_contents('php://input'), true);
$amount = (int)($body['amount'] ?? 0);

if ($amount <= 0 || $amount > 500) {
    http_response_code(400); echo json_encode(['error'=>'Invalid amount']); exit;
}

$result = award_xp((int)$user['id'], $amount, $body['reason'] ?? '');
echo json_encode(['status'=>'ok', 'xp'=>$result['xp'], 'level'=>$result['level']]);