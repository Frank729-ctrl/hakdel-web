<?php
require_once 'admin_config.php';
require_admin();

header('Content-Type: application/json');

$uid = (int)($_GET['user_id'] ?? 0);
if (!$uid) {
    echo json_encode(['error' => 'No user_id']);
    exit;
}

// Basic user info
$ustmt = db()->prepare('SELECT id, username, email, xp, level, role, created_at FROM users WHERE id = ?');
$ustmt->execute([$uid]);
$user = $ustmt->fetch();
if (!$user) {
    echo json_encode(['error' => 'User not found']);
    exit;
}

// Last 5 scans
$sstmt = db()->prepare('
    SELECT target_url, status, score, grade, scanned_at
    FROM scans WHERE user_id = ?
    ORDER BY scanned_at DESC LIMIT 5
');
$sstmt->execute([$uid]);
$scans = $sstmt->fetchAll();

// Labs solved
$lstmt = db()->prepare('
    SELECT l.title, l.difficulty, la.solved_at, la.attempts_count
    FROM lab_attempts la
    JOIN labs l ON la.lab_id = l.id
    WHERE la.user_id = ? AND la.status = "solved"
    ORDER BY la.solved_at DESC
');
$lstmt->execute([$uid]);
$labs = $lstmt->fetchAll();

// Quiz accuracy per category
$qstmt = db()->prepare('
    SELECT qq.category,
           COUNT(*)            AS total,
           SUM(qa.is_correct)  AS correct
    FROM quiz_attempts qa
    JOIN quiz_questions qq ON qa.question_id = qq.id
    WHERE qa.user_id = ?
    GROUP BY qq.category
    ORDER BY total DESC
');
$qstmt->execute([$uid]);
$quiz = $qstmt->fetchAll();

// XP breakdown by source (quiz per category totals already above; just provide summary)
$xp_quiz = db()->prepare('
    SELECT SUM(qq.points) as total_xp
    FROM quiz_attempts qa
    JOIN quiz_questions qq ON qa.question_id = qq.id
    WHERE qa.user_id = ? AND qa.is_correct = 1
');
$xp_quiz->execute([$uid]);
$xp_from_quiz = (int)$xp_quiz->fetchColumn();

$xp_labs = db()->prepare('
    SELECT COUNT(*) * 0 as placeholder
    FROM lab_attempts WHERE user_id = ? AND status = "solved"
');
// Labs don't track XP per attempt in this schema, so show count
$xp_labs->execute([$uid]);

echo json_encode([
    'user'         => $user,
    'scans'        => $scans,
    'labs'         => $labs,
    'quiz'         => $quiz,
    'xp_from_quiz' => $xp_from_quiz,
]);
