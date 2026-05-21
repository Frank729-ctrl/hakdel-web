<?php
require_once __DIR__ . '/../config/app.php';
header('Content-Type: application/json');
$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['error'=>'Not authenticated']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body = json_decode(file_get_contents('php://input'), true);
$question_id = (int)($body['question_id'] ?? 0);
$answer      = trim($body['answer']      ?? '');
$is_correct  = (bool)($body['is_correct'] ?? false);

if (!$question_id || !in_array($answer, ['a','b','c','d'])) {
    http_response_code(400); echo json_encode(['error'=>'Invalid data']); exit;
}

db()->prepare('
    INSERT INTO quiz_attempts (user_id, question_id, answer, is_correct)
    VALUES (?, ?, ?, ?)
')->execute([$user['id'], $question_id, $answer, $is_correct ? 1 : 0]);

echo json_encode(['status' => 'saved']);