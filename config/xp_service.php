<?php
/**
 * xp_service.php — Central XP service for HakDel
 *
 * Single source of truth for ALL XP awards.
 * Every XP-granting action must go through these functions.
 * Every award is logged to xp_log for auditability and dedup.
 *
 * XP table:
 *   Labs:        easy=100, medium=200, hard=350, expert=500  (first solve only)
 *   Quiz session: tier*30 base, multiplied by accuracy bonus
 *   Tier unlock: tier2=+50, tier3=+100 (one-time per category)
 *   Level bonus: new_level*10 on each level-up
 *   Daily streak: day1=5, day2=10, day3=20, day4=30, day5=50, day6=75, day7+=100
 */

require_once __DIR__ . '/app.php';

// ── Internal helper: write to xp_log ────────────────────────────────────────
function _xp_log(int $user_id, int $amount, string $source, int $ref_id, string $description): void {
    try {
        db()->prepare('
            INSERT INTO xp_log (user_id, amount, source, source_ref, description)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([
            $user_id,
            $amount,
            $source,
            $ref_id ?: null,
            $description ?: null,
        ]);
    } catch (Exception $e) {
        // Silently fail — never let logging break an award
    }
}

// ── Core award function ──────────────────────────────────────────────────────
/**
 * Award XP to a user, handle level-up cascade, log the award.
 *
 * @return array{xp_awarded:int, total_xp:int, old_level:int, new_level:int,
 *               leveled_up:bool, level_bonus:int, messages:string[]}
 */
function award_xp(int $user_id, int $amount, string $source, int $ref_id = 0, string $description = ''): array {
    $empty = ['xp_awarded' => 0, 'total_xp' => 0, 'old_level' => 1,
              'new_level' => 1, 'leveled_up' => false, 'level_bonus' => 0, 'messages' => []];

    if ($amount <= 0) return $empty;

    // Fetch current state
    $stmt = db()->prepare('SELECT xp, level FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    if (!$row) return $empty;

    $old_xp    = (int)$row['xp'];
    $old_level = (int)$row['level'];
    $new_xp    = $old_xp + $amount;
    $new_level = xp_to_level($new_xp);

    // Persist
    db()->prepare('UPDATE users SET xp = ?, level = ? WHERE id = ?')
        ->execute([$new_xp, $new_level, $user_id]);

    // Log
    _xp_log($user_id, $amount, $source, $ref_id, $description ?: ucfirst(str_replace('_', ' ', $source)));

    // Handle level-up bonus (level_bonus source does NOT recurse)
    $messages    = [];
    $level_bonus = 0;
    $leveled_up  = $new_level > $old_level;

    if ($leveled_up && $source !== 'level_bonus') {
        for ($lv = $old_level + 1; $lv <= $new_level; $lv++) {
            $bonus = $lv * 10;
            $messages[] = 'You reached Level ' . $lv . '!';
            $messages[] = 'Level bonus: +' . $bonus . ' XP';
            // Award the bonus (non-recursive — source='level_bonus' won't trigger again)
            $br = award_xp($user_id, $bonus, 'level_bonus', $lv, 'Level ' . $lv . ' reached');
            $level_bonus += $br['xp_awarded'];
        }
    }

    // Re-fetch total_xp after possible bonus awards
    $curr = db()->prepare('SELECT xp, level FROM users WHERE id = ?');
    $curr->execute([$user_id]);
    $curr_row = $curr->fetch();

    return [
        'xp_awarded'  => $amount,
        'total_xp'    => (int)$curr_row['xp'],
        'old_level'   => $old_level,
        'new_level'   => (int)$curr_row['level'],
        'leveled_up'  => $leveled_up,
        'level_bonus' => $level_bonus,
        'messages'    => $messages,
    ];
}

// ── Lab XP ──────────────────────────────────────────────────────────────────
/**
 * Award XP for a successfully solved lab (first solve only — no farming).
 */
function award_lab_xp(int $user_id, int $lab_id): array {
    // Get lab
    $stmt = db()->prepare('SELECT title, difficulty, xp_reward FROM labs WHERE id = ?');
    $stmt->execute([$lab_id]);
    $lab = $stmt->fetch();
    if (!$lab) return ['xp_awarded' => 0, 'already_solved' => false];

    // Check for prior solve — no XP if already solved before
    $prior = db()->prepare('
        SELECT COUNT(*) FROM xp_log
        WHERE user_id = ? AND source = "lab_complete" AND source_ref = ?
    ');
    $prior->execute([$user_id, $lab_id]);
    if ((int)$prior->fetchColumn() > 0) {
        return ['xp_awarded' => 0, 'already_solved' => true, 'messages' => []];
    }

    $amount = (int)$lab['xp_reward'];
    $result = award_xp($user_id, $amount, 'lab_complete', $lab_id, 'Lab: ' . $lab['title']);
    $result['already_solved'] = false;
    array_unshift($result['messages'], 'Lab solved! +' . $amount . ' XP');
    return $result;
}

// ── Quiz session XP ─────────────────────────────────────────────────────────
/**
 * Award XP for completing a 10-question quiz session.
 * Base = tier * 30. Multiplier from accuracy.
 */
function award_session_xp(int $user_id, string $category_slug, int $tier, int $correct, int $total): array {
    $base = $tier * 30;
    $pct  = $total > 0 ? ($correct / $total) : 0;

    if    ($pct >= 0.90) $mult = 1.5;
    elseif ($pct >= 0.70) $mult = 1.2;
    elseif ($pct >= 0.50) $mult = 1.0;
    else                  $mult = 0.5;

    $amount = (int)ceil($base * $mult);
    $desc   = 'Quiz: ' . $category_slug . ' T' . $tier . ' (' . $correct . '/' . $total . ')';

    $result = award_xp($user_id, $amount, 'quiz_session', 0, $desc);
    $result['session_xp'] = $amount;
    return $result;
}

// ── Tier unlock bonus ────────────────────────────────────────────────────────
/**
 * Award one-time bonus XP when a tier is first unlocked in a category.
 * tier 2 = +50 XP, tier 3 = +100 XP.
 */
function award_tier_unlock_xp(int $user_id, string $category_slug, int $tier): array {
    if ($tier < 2 || $tier > 3) return ['xp_awarded' => 0, 'messages' => []];

    $bonuses = [2 => 50, 3 => 100];
    $amount  = $bonuses[$tier];
    $desc    = 'Tier ' . $tier . ' unlocked: ' . $category_slug;

    // Dedup: only award once per category+tier
    $prior = db()->prepare('
        SELECT COUNT(*) FROM xp_log
        WHERE user_id = ? AND source = "tier_unlock" AND description = ?
    ');
    $prior->execute([$user_id, $desc]);
    if ((int)$prior->fetchColumn() > 0) {
        return ['xp_awarded' => 0, 'already_awarded' => true, 'messages' => []];
    }

    $result = award_xp($user_id, $amount, 'tier_unlock', $tier, $desc);
    $result['already_awarded'] = false;
    array_unshift($result['messages'], 'Tier ' . $tier . ' Unlocked! +' . $amount . ' XP bonus');
    return $result;
}

// ── Streak XP ────────────────────────────────────────────────────────────────
/**
 * Award daily streak XP. Call once per login day (caller checks last_active).
 */
function award_streak_xp(int $user_id, int $streak_days): array {
    $streak_table = [1 => 5, 2 => 10, 3 => 20, 4 => 30, 5 => 50, 6 => 75];
    $amount = $streak_days >= 7 ? 100 : ($streak_table[$streak_days] ?? 5);
    $desc   = $streak_days . '-day login streak';

    $result = award_xp($user_id, $amount, 'daily_streak', $streak_days, $desc);
    $result['streak_xp'] = $amount;
    array_unshift($result['messages'], $streak_days . '-day streak! +' . $amount . ' XP');
    return $result;
}
