<?php
require_once __DIR__ . '/../config/app.php';
$user    = require_login();
$xp_data = xp_progress((int)$user['xp']);
$level   = $xp_data['level'];
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

// ── Scan stats ───────────────────────────────────────────────────────────────
$stmt = db()->prepare('
    SELECT COUNT(*) as total,
           COALESCE(AVG(score), 0) as avg_score,
           COALESCE(MAX(score), 0) as best_score
    FROM scans WHERE user_id = ? AND status = "done"
');
$stmt->execute([$user['id']]);
$scan_stats = $stmt->fetch();

$stmt = db()->prepare('
    SELECT target_url, score, grade, profile, scanned_at
    FROM scans WHERE user_id = ? AND status = "done"
    ORDER BY scanned_at DESC LIMIT 5
');
$stmt->execute([$user['id']]);
$recent_scans = $stmt->fetchAll();

// ── XP breakdown by source ───────────────────────────────────────────────────
$xp_by_source = [];
try {
    $stmt = db()->prepare('
        SELECT source, SUM(amount) as total
        FROM xp_log WHERE user_id = ?
        GROUP BY source ORDER BY total DESC
    ');
    $stmt->execute([$user['id']]);
    foreach ($stmt->fetchAll() as $r) {
        $xp_by_source[$r['source']] = (int)$r['total'];
    }
} catch (Exception $e) {}
$total_logged_xp = array_sum($xp_by_source);

// ── Quiz progress ────────────────────────────────────────────────────────────
$quiz_cats = db()->query(
    'SELECT slug, name, icon FROM quiz_categories WHERE is_active=1 ORDER BY sort_order'
)->fetchAll();

$tier_progress = [];
try {
    $stmt = db()->prepare('
        SELECT category_slug, tier, questions_done, correct_count, unlocked
        FROM quiz_tier_progress WHERE user_id = ?
    ');
    $stmt->execute([$user['id']]);
    foreach ($stmt->fetchAll() as $r) {
        $tier_progress[$r['category_slug']][$r['tier']] = $r;
    }
} catch (Exception $e) {}

// ── Recent XP activity ───────────────────────────────────────────────────────
$recent_xp = [];
try {
    $stmt = db()->prepare('
        SELECT source, amount, description, created_at
        FROM xp_log WHERE user_id = ?
        ORDER BY created_at DESC LIMIT 10
    ');
    $stmt->execute([$user['id']]);
    $recent_xp = $stmt->fetchAll();
} catch (Exception $e) {}

// ── Streak ───────────────────────────────────────────────────────────────────
$streak_days    = (int)($user['streak_days']    ?? 0);
$longest_streak = (int)($user['longest_streak'] ?? 0);
$streak_table   = [1 => 5, 2 => 10, 3 => 20, 4 => 30, 5 => 50, 6 => 75];
$next_milestone = 7;
for ($s = 1; $s <= 6; $s++) {
    if ($streak_days < $s) { $next_milestone = $s; break; }
}

$member_since = date('M Y', strtotime($user['created_at']));

$source_labels = [
    'lab_complete' => 'Labs',
    'quiz_session' => 'Quiz Sessions',
    'tier_unlock'  => 'Tier Unlocks',
    'level_bonus'  => 'Level Bonuses',
    'daily_streak' => 'Daily Streaks',
    'manual'       => 'Manual Awards',
];
$source_colors = [
    'lab_complete' => 'var(--accent)',
    'quiz_session' => 'var(--accent2)',
    'tier_unlock'  => '#bd4fff',
    'level_bonus'  => '#ffd166',
    'daily_streak' => '#ff6b35',
    'manual'       => 'var(--text2)',
];
$source_icons = [
    'lab_complete' => '&#9670;',
    'quiz_session' => '&#9671;',
    'tier_unlock'  => '&#9651;',
    'level_bonus'  => '&#9651;',
    'daily_streak' => '&#9830;',
    'manual'       => '&#9632;',
];

$nav_active     = 'profile';
$sidebar_sub    = 'Profile';
$sidebar_footer = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HakDel — Profile</title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/layout.css">
</head>
<body>

<?php require __DIR__ . '/../partials/topbar.php'; ?>

<div class="hk-shell">
<?php require __DIR__ . '/../partials/sidebar.php'; ?>

  <main class="hk-main">

    <div class="hk-page-header">
      <div>
        <div class="hk-page-eyebrow">&#9898; Account &nbsp;&middot;&nbsp; <?php echo h($user['username']); ?></div>
        <h1 class="hk-page-title">Profile</h1>
        <p class="hk-page-sub">Member since <?php echo $member_since; ?></p>
      </div>
    </div>

    <div class="profile-grid">

      <!-- Identity + level card -->
      <div class="profile-card profile-identity">
        <div class="profile-avatar-lg"><?php echo h($initials); ?></div>
        <div class="profile-name"><?php echo h($user['username']); ?></div>
        <div class="profile-email"><?php echo h($user['email']); ?></div>
        <div class="profile-since">Member since <?php echo $member_since; ?></div>

        <div class="profile-level-wrap">
          <div class="profile-level-header">
            <span class="profile-level-label">Level <?php echo $level; ?></span>
            <span class="profile-level-xp"><?php echo number_format($user['xp']); ?> / <?php echo number_format($xp_data['next']); ?> XP</span>
          </div>
          <div class="profile-level-track">
            <div class="profile-level-fill" style="width:<?php echo $xp_data['progress']; ?>%"></div>
          </div>
          <?php if ($level < 15): ?>
          <div class="profile-level-next"><?php echo number_format($xp_data['next'] - $user['xp']); ?> XP to Level <?php echo $level + 1; ?></div>
          <?php else: ?>
          <div class="profile-level-next" style="color:var(--accent)">Max Level Reached!</div>
          <?php endif; ?>
        </div>

        <!-- Streak display -->
        <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border)">
          <div style="font-family:var(--mono);font-size:10px;letter-spacing:2px;color:var(--text2);text-transform:uppercase;margin-bottom:8px">Daily Streak</div>
          <div style="display:flex;align-items:center;justify-content:space-between">
            <div style="font-size:28px;font-weight:700;color:<?php echo $streak_days >= 7 ? '#ff6b35' : 'var(--text)'; ?>">
              <?php echo $streak_days; ?>
              <span style="font-size:14px;color:var(--text2);font-weight:400">days</span>
            </div>
            <div style="text-align:right;font-family:var(--mono);font-size:11px;color:var(--text3)">
              Best: <?php echo $longest_streak; ?> days<br>
              <?php if ($streak_days < 7): ?>
              Day <?php echo $next_milestone; ?> = <?php echo ($streak_table[$next_milestone] ?? 100); ?> XP
              <?php else: ?>
              +100 XP/day &#9830;
              <?php endif; ?>
            </div>
          </div>
          <div style="display:flex;gap:4px;margin-top:10px">
            <?php for ($d = 1; $d <= 7; $d++): ?>
            <div style="flex:1;height:6px;border-radius:3px;background:<?php echo $d <= $streak_days ? '#ff6b35' : 'var(--bg3)'; ?>"></div>
            <?php endfor; ?>
          </div>
          <div style="font-family:var(--mono);font-size:10px;color:var(--text3);margin-top:4px;text-align:right">7-day cap</div>
        </div>
      </div>

      <!-- XP breakdown -->
      <?php if (!empty($xp_by_source)): ?>
      <div class="profile-card">
        <div class="profile-card-title">XP Breakdown</div>
        <div style="display:flex;flex-direction:column;gap:10px;margin-top:8px">
          <?php foreach ($xp_by_source as $source => $amount):
            $pct = $total_logged_xp > 0 ? round(($amount / $total_logged_xp) * 100) : 0;
            $clr = $source_colors[$source] ?? 'var(--text2)';
            $lbl = $source_labels[$source] ?? $source;
          ?>
          <div>
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
              <span style="color:var(--text)"><?php echo h($lbl); ?></span>
              <span style="font-family:var(--mono);color:<?php echo $clr; ?>"><?php echo number_format($amount); ?> XP</span>
            </div>
            <div style="background:var(--bg3);border-radius:3px;height:7px">
              <div style="width:<?php echo $pct; ?>%;height:7px;border-radius:3px;background:<?php echo $clr; ?>"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:12px;font-family:var(--mono);font-size:11px;color:var(--text3)">
          Total logged: <?php echo number_format($total_logged_xp); ?> XP
        </div>
      </div>
      <?php else: ?>
      <div class="profile-card profile-stats">
        <div class="profile-card-title">Scan Statistics</div>
        <div class="profile-stat-grid">
          <div class="pstat">
            <div class="pstat-num"><?php echo (int)$scan_stats['total']; ?></div>
            <div class="pstat-label">Total Scans</div>
          </div>
          <div class="pstat">
            <div class="pstat-num" style="color:var(--accent2)"><?php echo round($scan_stats['avg_score']); ?></div>
            <div class="pstat-label">Avg Score</div>
          </div>
          <div class="pstat">
            <div class="pstat-num" style="color:var(--accent)"><?php echo (int)$scan_stats['best_score']; ?></div>
            <div class="pstat-label">Best Score</div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Recent XP activity -->
      <?php if (!empty($recent_xp)): ?>
      <div class="profile-card profile-recent">
        <div class="profile-card-title">Recent XP Activity</div>
        <div style="display:flex;flex-direction:column;margin-top:4px">
          <?php foreach ($recent_xp as $log):
            $src = $log['source'] ?? 'manual';
            $clr = $source_colors[$src] ?? 'var(--text2)';
            $ico = $source_icons[$src]  ?? '&#9632;';
          ?>
          <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.04)">
            <span style="color:<?php echo $clr; ?>;font-size:14px;flex-shrink:0"><?php echo $ico; ?></span>
            <div style="flex:1;min-width:0">
              <div style="font-size:12px;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?php echo h(mb_substr($log['description'] ?? $src, 0, 55)); ?>
              </div>
              <div style="font-family:var(--mono);font-size:10px;color:var(--text3);margin-top:1px">
                <?php echo date('d M H:i', strtotime($log['created_at'])); ?>
              </div>
            </div>
            <span style="font-family:var(--mono);font-size:12px;color:<?php echo $clr; ?>;flex-shrink:0">+<?php echo $log['amount']; ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php else: ?>
      <div class="profile-card profile-recent">
        <div class="profile-card-title">Recent Scans
          <a href="/scanner/history.php" class="profile-view-all">View all &#8250;</a>
        </div>
        <?php if (empty($recent_scans)): ?>
        <div class="profile-empty">No scans yet. <a href="/scanner/">Run your first scan.</a></div>
        <?php else: ?>
        <div class="profile-recent-list">
          <?php foreach ($recent_scans as $s):
            $sc   = $s['score'] >= 75 ? 'score-a' : ($s['score'] >= 50 ? 'score-c' : 'score-f');
            $date = date('d M Y', strtotime($s['scanned_at']));
            $short = strlen($s['target_url']) > 40 ? substr($s['target_url'], 0, 40).'...' : $s['target_url'];
          ?>
          <div class="profile-recent-row">
            <div class="history-score-badge <?php echo $sc; ?>" style="width:38px;height:38px;font-size:13px"><?php echo $s['score']; ?></div>
            <div class="profile-recent-info">
              <div class="profile-recent-target"><?php echo h($short); ?></div>
              <div class="profile-recent-date"><?php echo $date; ?> &nbsp;&middot;&nbsp; <?php echo ucfirst($s['profile']); ?></div>
            </div>
            <span class="history-grade <?php echo $sc; ?>"><?php echo $s['grade']; ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Quiz progress across all categories -->
      <div class="profile-card" style="grid-column:1 / -1">
        <div class="profile-card-title">Quiz Progress</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;margin-top:10px">
          <?php foreach ($quiz_cats as $qc):
            $tp = $tier_progress[$qc['slug']] ?? [];
            $total_done    = array_sum(array_column($tp, 'questions_done'));
            $total_correct = array_sum(array_column($tp, 'correct_count'));
            $acc = $total_done > 0 ? round(($total_correct / $total_done) * 100) : 0;
            $acc_clr = $acc >= 70 ? 'var(--accent)' : ($acc >= 50 ? '#ffd166' : 'var(--danger)');
          ?>
          <div style="background:var(--bg3);border-radius:var(--radius);padding:12px 14px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
              <div style="display:flex;align-items:center;gap:8px">
                <span style="font-size:16px"><?php echo $qc['icon'] ?? '?'; ?></span>
                <span style="font-size:13px;font-weight:500;color:var(--text)"><?php echo h($qc['name']); ?></span>
              </div>
              <?php if ($total_done > 0): ?>
              <span style="font-family:var(--mono);font-size:11px;color:<?php echo $acc_clr; ?>"><?php echo $acc; ?>%</span>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:6px;align-items:center">
              <?php for ($t = 1; $t <= 3; $t++):
                $td          = $tp[$t] ?? null;
                $is_unlocked = $t === 1 || !empty($tp[$t-1]['unlocked']);
                $is_complete = !empty($td['unlocked']);
                $has_progress = $td && $td['questions_done'] > 0;
                if      ($is_complete)  $dot_bg = 'var(--accent)';
                elseif  ($has_progress) $dot_bg = 'var(--accent2)';
                elseif  ($is_unlocked)  $dot_bg = 'transparent';
                else                    $dot_bg = 'var(--bg2)';
                $dot_border = ($is_complete || $has_progress) ? 'transparent' : ($is_unlocked ? 'var(--text2)' : 'var(--border)');
              ?>
              <div style="display:flex;align-items:center;gap:3px">
                <div style="width:10px;height:10px;border-radius:50%;background:<?php echo $dot_bg; ?>;border:1.5px solid <?php echo $dot_border; ?>"></div>
                <span style="font-family:var(--mono);font-size:10px;color:var(--text3)">T<?php echo $t; ?></span>
              </div>
              <?php endfor; ?>
              <?php if ($total_done > 0): ?>
              <span style="margin-left:auto;font-family:var(--mono);font-size:10px;color:var(--text3)"><?php echo $total_done; ?> answered</span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Account settings -->
      <div class="profile-card profile-settings">
        <div class="profile-card-title">Account</div>
        <div class="profile-setting-row">
          <span class="profile-setting-label">Username</span>
          <span class="profile-setting-val"><?php echo h($user['username']); ?></span>
        </div>
        <div class="profile-setting-row">
          <span class="profile-setting-label">Email</span>
          <span class="profile-setting-val"><?php echo h($user['email']); ?></span>
        </div>

        <div class="profile-setting-row">
          <span class="profile-setting-label">Member since</span>
          <span class="profile-setting-val"><?php echo $member_since; ?></span>
        </div>
        <a href="/auth/logout.php" class="btn-logout">&#8592; Sign out</a>
      </div>

    </div><!-- /profile-grid -->
  </main>
</div>

</body>
</html>
