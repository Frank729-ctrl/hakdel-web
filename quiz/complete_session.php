<?php
/**
 * complete_session.php — Award XP for a completed 10-question quiz session.
 *
 * Called by quiz_play.php JS after the 10th answer is submitted.
 * Idempotent: duplicate calls within 30 seconds return cached data.
 *
 * POST JSON: { category_slug, tier, correct_count, total_count }
 * Response:  { session_xp, tier_unlocked, new_tier, tier_bonus,
 *              leveled_up, new_level, level_bonus, total_xp_awarded,
 *              messages[], current_xp, xp_progress, next_level_xp }
 */
require_once __DIR__ . '/../config/xp_service.php';
header('Content-Type: application/json');

$user = current_user();
if (!$user) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body    = json_decode(file_get_contents('php://input'), true) ?: [];
$uid     = (int)$user['id'];
$slug    = trim($body['category_slug'] ?? '');
$tier    = max(1, min(3, (int)($body['tier']         ?? 1)));
$correct = max(0,          (int)($body['correct_count'] ?? 0));
$total   = max(1,          (int)($body['total_count']   ?? 10));

if (!$slug) {
    echo json_encode(['error' => 'Missing category_slug']);
    exit;
}

// ── Idempotency check: prevent double-award within 30 seconds ────────────────
$dedup_desc = 'Quiz: ' . $slug . ' T' . $tier . ' (' . $correct . '/' . $total . ')';
$dedup = db()->prepare('
    SELECT id FROM xp_log
    WHERE user_id = ? AND source = "quiz_session" AND description = ?
      AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)
');
$dedup->execute([$uid, $dedup_desc]);
if ($dedup->fetch()) {
    // Already awarded — return a neutral response
    $curr = db()->prepare('SELECT xp, level FROM users WHERE id = ?');
    $curr->execute([$uid]);
    $cr = $curr->fetch();
    $xpd = xp_progress((int)$cr['xp']);
    echo json_encode([
        'already_awarded'  => true,
        'session_xp'       => 0,
        'total_xp_awarded' => 0,
        'messages'         => [],
        'current_xp'       => (int)$cr['xp'],
        'xp_progress'      => $xpd['progress'],
        'next_level_xp'    => $xpd['next'],
        'new_level'        => (int)$cr['level'],
        'leveled_up'       => false,
        'tier_unlocked'    => false,
    ]);
    exit;
}

// ── Award session XP ─────────────────────────────────────────────────────────
$xp_result  = award_session_xp($uid, $slug, $tier, $correct, $total);
$session_xp = $xp_result['xp_awarded'] ?? 0;

// ── Check for tier unlock that happened during this session ──────────────────
$tier_unlocked  = false;
$new_tier_num   = null;
$tier_bonus     = 0;
$unlock_msgs    = [];

if ($tier < 3) {
    $tp = db()->prepare('
        SELECT unlocked, unlocked_at FROM quiz_tier_progress
        WHERE user_id = ? AND category_slug = ? AND tier = ?
    ');
    $tp->execute([$uid, $slug, $tier]);
    $tp_row = $tp->fetch();

    if ($tp_row && $tp_row['unlocked'] && $tp_row['unlocked_at']) {
        $secs_since_unlock = time() - strtotime($tp_row['unlocked_at']);
        if ($secs_since_unlock < 90) {
            $tier_unlocked = true;
            $new_tier_num  = $tier + 1;
            $unlock_result = award_tier_unlock_xp($uid, $slug, $new_tier_num);
            $tier_bonus    = $unlock_result['xp_awarded'] ?? 0;
            $unlock_msgs   = $unlock_result['messages']   ?? [];
        }
    }
}

// ── Build final message list ──────────────────────────────────────────────────
$messages = [];
if ($session_xp > 0) {
    $messages[] = 'Session complete! +' . $session_xp . ' XP';
}
$messages = array_merge($messages, $unlock_msgs);
$messages = array_merge($messages, $xp_result['messages'] ?? []);

$total_awarded = $session_xp
               + ($xp_result['level_bonus'] ?? 0)
               + $tier_bonus;

// ── Fetch updated user state ──────────────────────────────────────────────────
$curr = db()->prepare('SELECT xp, level FROM users WHERE id = ?');
$curr->execute([$uid]);
$cr  = $curr->fetch();
$xpd = xp_progress((int)$cr['xp']);

echo json_encode([
    'session_xp'       => $session_xp,
    'tier_unlocked'    => $tier_unlocked,
    'new_tier'         => $new_tier_num,
    'tier_bonus'       => $tier_bonus,
    'leveled_up'       => $xp_result['leveled_up'] ?? false,
    'new_level'        => (int)$cr['level'],
    'level_bonus'      => $xp_result['level_bonus'] ?? 0,
    'total_xp_awarded' => $total_awarded,
    'messages'         => $messages,
    'current_xp'       => (int)$cr['xp'],
    'xp_progress'      => $xpd['progress'],
    'next_level_xp'    => $xpd['next'],
]);
