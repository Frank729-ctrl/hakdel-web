<?php
require_once __DIR__ . '/../config/app.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$uid  = (int)$user['id'];

// ── check_unlock action ───────────────────────────────────────────────────────
if (($body['action'] ?? '') === 'check_unlock') {
    $cat_slug = trim($body['category_slug'] ?? '');
    if (!$cat_slug) { echo json_encode(['tier_unlocked' => false]); exit; }

    $unlocked_tier = null;
    for ($t = 1; $t <= 2; $t++) {
        $stmt = db()->prepare('
            SELECT questions_done, correct_count, unlocked
            FROM quiz_tier_progress
            WHERE user_id = ? AND category_slug = ? AND tier = ?
        ');
        $stmt->execute([$uid, $cat_slug, $t]);
        $row = $stmt->fetch();
        if (!$row || $row['unlocked']) continue;
        if ((int)$row['questions_done'] >= 10 &&
            (int)$row['questions_done'] > 0 &&
            ((int)$row['correct_count'] / (int)$row['questions_done']) >= 0.70)
        {
            db()->prepare('
                UPDATE quiz_tier_progress
                SET unlocked = 1, unlocked_at = NOW()
                WHERE user_id = ? AND category_slug = ? AND tier = ?
            ')->execute([$uid, $cat_slug, $t]);

            db()->prepare('
                INSERT IGNORE INTO quiz_tier_progress (user_id, category_slug, tier, questions_done, correct_count, unlocked)
                VALUES (?, ?, ?, 0, 0, 0)
            ')->execute([$uid, $cat_slug, $t + 1]);

            $unlocked_tier = $t + 1;
            break;
        }
    }

    echo json_encode([
        'tier_unlocked' => $unlocked_tier !== null,
        'new_tier'      => $unlocked_tier,
    ]);
    exit;
}

// ── Save answer (no XP awarded here — XP is awarded by complete_session.php) ─
$question_id = (int)($body['question_id']   ?? 0);
$answer      = trim($body['answer']         ?? '');
$cat_slug    = trim($body['category_slug']  ?? '');
$tier        = max(1, min(3, (int)($body['tier'] ?? 1)));

if (!$question_id || !in_array($answer, ['a','b','c','d']) || !$cat_slug) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data']);
    exit;
}

// Server-side correctness check (never trust client)
$qstmt = db()->prepare('SELECT correct FROM quiz_questions WHERE id = ? AND is_active = 1');
$qstmt->execute([$question_id]);
$qrow = $qstmt->fetch();
if (!$qrow) {
    http_response_code(404);
    echo json_encode(['error' => 'Question not found']);
    exit;
}
$is_correct = ($answer === $qrow['correct']);

// Save attempt
db()->prepare('
    INSERT INTO quiz_attempts (user_id, question_id, answer, is_correct)
    VALUES (?, ?, ?, ?)
')->execute([$uid, $question_id, $answer, $is_correct ? 1 : 0]);

// Upsert tier progress
db()->prepare('
    INSERT INTO quiz_tier_progress (user_id, category_slug, tier, questions_done, correct_count, unlocked)
    VALUES (?, ?, ?, 1, ?, 0)
    ON DUPLICATE KEY UPDATE
        questions_done = questions_done + 1,
        correct_count  = correct_count + ?
')->execute([$uid, $cat_slug, $tier, $is_correct ? 1 : 0, $is_correct ? 1 : 0]);

// Check if this tier now qualifies for unlock
$tp = db()->prepare('
    SELECT questions_done, correct_count, unlocked
    FROM quiz_tier_progress
    WHERE user_id = ? AND category_slug = ? AND tier = ?
');
$tp->execute([$uid, $cat_slug, $tier]);
$tp_row = $tp->fetch();

$tier_just_unlocked = false;
$new_tier           = null;

if ($tp_row && !$tp_row['unlocked']
    && (int)$tp_row['questions_done'] >= 10
    && ((int)$tp_row['correct_count'] / (int)$tp_row['questions_done']) >= 0.70
    && $tier < 3)
{
    db()->prepare('
        UPDATE quiz_tier_progress
        SET unlocked = 1, unlocked_at = NOW()
        WHERE user_id = ? AND category_slug = ? AND tier = ?
    ')->execute([$uid, $cat_slug, $tier]);

    db()->prepare('
        INSERT IGNORE INTO quiz_tier_progress (user_id, category_slug, tier, questions_done, correct_count, unlocked)
        VALUES (?, ?, ?, 0, 0, 0)
    ')->execute([$uid, $cat_slug, $tier + 1]);

    $tier_just_unlocked = true;
    $new_tier           = $tier + 1;
}

echo json_encode([
    'saved'         => true,
    'is_correct'    => $is_correct,
    'tier_unlocked' => $tier_just_unlocked,
    'new_tier'      => $new_tier,
]);
