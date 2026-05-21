<?php
require_once __DIR__ . '/../config/xp_service.php';
$user = require_login();

if (!is_post()) redirect('/labs/');
if (!verify_csrf($_POST['csrf'] ?? '')) redirect('/labs/');

$lab_id   = (int)($_POST['lab_id']   ?? 0);
$lab_slug = trim($_POST['lab_slug']  ?? '');
$flag     = trim($_POST['flag']      ?? '');
$back     = '/labs/view.php?slug=' . urlencode($lab_slug);

if (!$lab_id || !$flag) redirect($back);

// Fetch lab
$stmt = db()->prepare('SELECT * FROM labs WHERE id = ? AND is_active = 1');
$stmt->execute([$lab_id]);
$lab = $stmt->fetch();
if (!$lab) redirect('/labs/');

// Already solved?
$stmt = db()->prepare('SELECT * FROM lab_attempts WHERE user_id = ? AND lab_id = ?');
$stmt->execute([$user['id'], $lab_id]);
$attempt = $stmt->fetch();

if ($attempt && $attempt['status'] === 'solved') {
    flash('lab_success', 'You already solved this lab!');
    redirect($back);
}

// Check flag — compare SHA256 hash
$submitted_hash = hash('sha256', $flag);
$correct        = hash_equals($lab['flag_hash'], $submitted_hash);

if ($correct) {
    // Mark solved
    db()->prepare('
        UPDATE lab_attempts
        SET status = "solved", solved_at = NOW()
        WHERE user_id = ? AND lab_id = ?
    ')->execute([$user['id'], $lab_id]);

    // Award XP via xp_service (handles dedup + logging + level-up)
    $xp_result = award_lab_xp((int)$user['id'], $lab_id);

    // Store XP notification for topbar to display on next page load
    if ($xp_result['xp_awarded'] > 0) {
        $_SESSION['pending_xp_notify'] = [
            'messages'         => $xp_result['messages'],
            'total_xp_awarded' => $xp_result['xp_awarded'] + ($xp_result['level_bonus'] ?? 0),
            'leveled_up'       => $xp_result['leveled_up'] ?? false,
            'new_level'        => $xp_result['new_level']  ?? null,
            'current_xp'       => $xp_result['total_xp']  ?? null,
        ];
    }

    $xp_msg = $xp_result['xp_awarded'] > 0 ? ' +' . $xp_result['xp_awarded'] . ' XP awarded.' : '';
    flash('lab_success', 'Correct! Flag accepted.' . $xp_msg);
} else {
    // Increment attempts
    db()->prepare('
        UPDATE lab_attempts
        SET attempts_count = attempts_count + 1
        WHERE user_id = ? AND lab_id = ?
    ')->execute([$user['id'], $lab_id]);

    flash('lab_error', 'Incorrect flag. Check your work and try again.');
}

redirect($back);
