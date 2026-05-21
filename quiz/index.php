<?php
require_once __DIR__ . '/../config/app.php';
$user    = require_login();
$xp_data = xp_progress((int)$user['xp']);
$level   = $xp_data['level'];
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$CAT_COLORS = [
    'it-fundamentals'    => '#00d4aa',
    'terminal-linux'     => '#00e676',
    'cs-fundamentals'    => '#7f77dd',
    'networking'         => '#0094ff',
    'cyber-awareness'    => '#ffd166',
    'web-security'       => '#ff4d6d',
    'cryptography'       => '#bd4fff',
    'malware'            => '#ff6b35',
    'social-engineering' => '#ff3cac',
    'cloud-security'     => '#48cae4',
    'ceh-domains'        => '#f72585',
];

// Fetch categories with question counts
$categories = db()->query('
    SELECT c.*,
           COALESCE((SELECT COUNT(*) FROM quiz_questions q
                     WHERE q.category = c.slug AND q.is_active = 1), 0) AS question_count
    FROM quiz_categories c
    WHERE c.is_active = 1
    ORDER BY c.sort_order ASC
')->fetchAll();

// Fetch this user's tier progress
$tp_stmt = db()->prepare('
    SELECT category_slug, tier, questions_done, correct_count, unlocked
    FROM quiz_tier_progress
    WHERE user_id = ?
');
$tp_stmt->execute([$user['id']]);
$tier_map = [];
foreach ($tp_stmt->fetchAll() as $row) {
    $tier_map[$row['category_slug']][$row['tier']] = $row;
}

// Global quiz stats
$qs = db()->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(is_correct),0) AS correct FROM quiz_attempts WHERE user_id = ?');
$qs->execute([$user['id']]);
$quiz_stats     = $qs->fetch();
$total_answered = (int)$quiz_stats['total'];
$total_correct  = (int)$quiz_stats['correct'];
$accuracy       = $total_answered > 0 ? round(($total_correct / $total_answered) * 100) : 0;
$cats_started   = count(array_filter($tier_map, fn($t) => !empty($t)));

function tier_badge_html(array $tier_map, string $slug, int $tier): string {
    $t = $tier_map[$slug][$tier] ?? null;
    $prev_unlocked = ($tier === 1) ? true : !empty($tier_map[$slug][$tier - 1]['unlocked']);
    if (!$prev_unlocked) {
        return '<span class="qc-tier-badge tier-locked" title="Tier ' . $tier . ' locked">T' . $tier . '</span>';
    }
    if (!$t || (int)$t['questions_done'] === 0) {
        return '<span class="qc-tier-badge tier-available" title="Tier ' . $tier . ' available">T' . $tier . '</span>';
    }
    if ($t['unlocked']) {
        return '<span class="qc-tier-badge tier-complete" title="Tier ' . $tier . ' complete ✓">T' . $tier . ' ✓</span>';
    }
    $pct = (int)$t['questions_done'] > 0 ? round(((int)$t['correct_count'] / (int)$t['questions_done']) * 100) : 0;
    return '<span class="qc-tier-badge tier-progress" title="Tier ' . $tier . ': ' . $pct . '%">T' . $tier . ' ' . $pct . '%</span>';
}

$nav_active     = 'quiz';
$sidebar_sub    = 'Quiz';
$sidebar_footer = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HakDel — Quiz</title>
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
        <div class="hk-page-eyebrow">&#9671; Quiz &nbsp;&middot;&nbsp; HakDel Learning</div>
        <h1 class="hk-page-title">Quiz Lobby</h1>
        <p class="hk-page-sub">Select a category to begin. Score 70%+ on 10 questions to unlock the next tier.</p>
      </div>
    </div>

    <!-- Stats row -->
    <div class="quiz-stats-row">
      <div class="qstat-card">
        <div class="qstat-num"><?php echo $total_answered; ?></div>
        <div class="qstat-label">Answered</div>
      </div>
      <div class="qstat-card">
        <div class="qstat-num" style="color:var(--accent)"><?php echo $total_correct; ?></div>
        <div class="qstat-label">Correct</div>
      </div>
      <div class="qstat-card">
        <div class="qstat-num" style="color:<?php echo $accuracy >= 70 ? 'var(--accent)' : ($accuracy >= 50 ? 'var(--warn)' : 'var(--danger)'); ?>"><?php echo $accuracy; ?>%</div>
        <div class="qstat-label">Accuracy</div>
      </div>
      <div class="qstat-card">
        <div class="qstat-num" style="color:var(--accent2)"><?php echo $cats_started; ?></div>
        <div class="qstat-label">Categories Started</div>
      </div>
    </div>

    <!-- Category grid -->
    <div class="qlobby-grid">
      <?php foreach ($categories as $cat):
        $slug   = $cat['slug'];
        $color  = $CAT_COLORS[$slug] ?? '#00d4aa';
        $locked = (int)$cat['level_required'] > $level;
        $tiers_complete = 0;
        for ($t = 1; $t <= 3; $t++) {
            if (!empty($tier_map[$slug][$t]['unlocked'])) $tiers_complete++;
        }
      ?>
      <div class="qlobby-card<?php echo $locked ? ' qlobby-locked' : ''; ?>"
           style="--cat-color:<?php echo $color; ?>"
           <?php if (!$locked && (int)$cat['question_count'] > 0): ?>
           onclick="window.location='/quiz/quiz_category.php?slug=<?php echo urlencode($slug); ?>'"
           <?php endif; ?>>

        <?php if ($locked): ?>
        <div class="qlobby-lock-overlay">
          <div class="qlobby-lock-icon">&#128274;</div>
          <div class="qlobby-lock-label">Level <?php echo (int)$cat['level_required']; ?> Required</div>
          <div class="qlobby-lock-sub">You are Level <?php echo $level; ?></div>
        </div>
        <?php endif; ?>

        <div class="qlobby-card-top">
          <div class="qlobby-icon"><?php echo $cat['icon']; ?></div>
          <div class="qlobby-meta">
            <div class="qlobby-name"><?php echo h($cat['name']); ?></div>
            <div class="qlobby-desc"><?php echo h($cat['description']); ?></div>
          </div>
        </div>

        <div class="qlobby-card-body">
          <div class="qlobby-tiers">
            <?php for ($t = 1; $t <= 3; $t++) echo tier_badge_html($tier_map, $slug, $t); ?>
          </div>
          <div class="qlobby-qcount"><?php echo (int)$cat['question_count']; ?> questions &nbsp;&middot;&nbsp; Level <?php echo (int)$cat['level_required']; ?>+</div>
        </div>

        <div class="qlobby-card-footer">
          <?php if ($locked): ?>
            <span class="qlobby-cta qlobby-cta-locked">Locked</span>
          <?php elseif ((int)$cat['question_count'] === 0): ?>
            <span class="qlobby-cta" style="color:var(--text3)">Coming soon</span>
          <?php elseif ($tiers_complete === 3): ?>
            <span class="qlobby-cta" style="color:var(--accent)">&#10003; Mastered &rsaquo;</span>
          <?php elseif (empty($tier_map[$slug])): ?>
            <span class="qlobby-cta">Start &rsaquo;</span>
          <?php else: ?>
            <span class="qlobby-cta">Continue &rsaquo;</span>
          <?php endif; ?>
        </div>

      </div>
      <?php endforeach; ?>
    </div>

  </main>
</div>
</body>
</html>
