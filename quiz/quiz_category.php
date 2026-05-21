<?php
require_once __DIR__ . '/../config/app.php';
$user    = require_login();
$xp_data = xp_progress((int)$user['xp']);
$level   = $xp_data['level'];
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$slug = trim($_GET['slug'] ?? '');
if (!$slug) { header('Location: /quiz/'); exit; }

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

// Load category
$cat_stmt = db()->prepare('SELECT * FROM quiz_categories WHERE slug = ? AND is_active = 1');
$cat_stmt->execute([$slug]);
$cat = $cat_stmt->fetch();
if (!$cat) { header('Location: /quiz/'); exit; }

// Locked?
if ((int)$cat['level_required'] > $level) {
    header('Location: /quiz/');
    exit;
}

$color      = $CAT_COLORS[$slug] ?? '#00d4aa';
$key_arr    = json_decode($cat['key_concepts'] ?? '[]', true) ?: [];

// Question counts per tier
$qc_stmt = db()->prepare('
    SELECT tier, COUNT(*) AS cnt
    FROM quiz_questions
    WHERE category = ? AND is_active = 1
    GROUP BY tier
');
$qc_stmt->execute([$slug]);
$q_counts = [];
foreach ($qc_stmt->fetchAll() as $r) $q_counts[(int)$r['tier']] = (int)$r['cnt'];

// Tier progress for this user + category
$tp_stmt = db()->prepare('
    SELECT tier, questions_done, correct_count, unlocked
    FROM quiz_tier_progress
    WHERE user_id = ? AND category_slug = ?
');
$tp_stmt->execute([$user['id'], $slug]);
$tier_data = [];
foreach ($tp_stmt->fetchAll() as $r) $tier_data[(int)$r['tier']] = $r;

function tier_is_unlocked(array $tier_data, int $tier): bool {
    if ($tier === 1) return true;
    return !empty($tier_data[$tier - 1]['unlocked']);
}

function tier_progress_pct(array $tier_data, int $tier): int {
    $t = $tier_data[$tier] ?? null;
    if (!$t || (int)$t['questions_done'] === 0) return 0;
    return (int)round(((int)$t['correct_count'] / (int)$t['questions_done']) * 100);
}

// Max unlocked tier (for the Start button)
$max_tier = 1;
for ($t = 3; $t >= 1; $t--) {
    if (tier_is_unlocked($tier_data, $t)) { $max_tier = $t; break; }
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
<title>HakDel — <?php echo h($cat['name']); ?></title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/layout.css">
</head>
<body>

<?php require __DIR__ . '/../partials/topbar.php'; ?>

<div class="hk-shell">

<?php require __DIR__ . '/../partials/sidebar.php'; ?>

  <main class="hk-main">

    <!-- Back link -->
    <div style="margin-bottom:8px">
      <a href="/quiz/" class="hk-back-link">&#8592; Back to Quiz</a>
    </div>

    <!-- Category header -->
    <div class="qcat-header" style="--cat-color:<?php echo $color; ?>">
      <div class="qcat-header-icon"><?php echo $cat['icon']; ?></div>
      <div class="qcat-header-body">
        <div class="qcat-eyebrow">Level <?php echo (int)$cat['level_required']; ?>+ required</div>
        <h1 class="qcat-title"><?php echo h($cat['name']); ?></h1>
        <p class="qcat-intro"><?php echo h($cat['intro_text'] ?? $cat['description']); ?></p>
      </div>
    </div>

    <!-- Key concepts -->
    <?php if (!empty($key_arr)): ?>
    <div class="hk-bento qcat-concepts">
      <div class="hk-bento-label">Key Concepts</div>
      <ul class="qcat-concepts-list">
        <?php foreach ($key_arr as $concept): ?>
        <li><?php echo h($concept); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <!-- Tier selector -->
    <div class="hk-bento">
      <div class="hk-bento-label">Tiers</div>
      <div class="qcat-tiers">

        <?php for ($tier = 1; $tier <= 3; $tier++):
          $unlocked    = tier_is_unlocked($tier_data, $tier);
          $td          = $tier_data[$tier] ?? null;
          $q_done      = $td ? (int)$td['questions_done'] : 0;
          $q_correct   = $td ? (int)$td['correct_count']  : 0;
          $acc_pct     = $q_done > 0 ? round(($q_correct / $q_done) * 100) : 0;
          $tier_complete = $td && $td['unlocked'];
          $q_in_tier   = $q_counts[$tier] ?? 0;
          $pts         = $tier * 10;
          $diff_labels = ['', 'Easy', 'Medium', 'Hard'];
          $diff        = $diff_labels[$tier];
        ?>
        <div class="qcat-tier<?php echo $unlocked ? '' : ' qcat-tier-locked'; ?><?php echo $tier_complete ? ' qcat-tier-complete' : ''; ?>"
             style="--cat-color:<?php echo $color; ?>">

          <?php if (!$unlocked): ?>
          <div class="qcat-tier-lock">&#128274;</div>
          <?php elseif ($tier_complete): ?>
          <div class="qcat-tier-check">&#10003;</div>
          <?php endif; ?>

          <div class="qcat-tier-top">
            <div>
              <div class="qcat-tier-name">Tier <?php echo $tier; ?> &nbsp;<span class="qcat-tier-diff"><?php echo $diff; ?></span></div>
              <div class="qcat-tier-pts"><?php echo $pts; ?> XP per correct answer</div>
            </div>
            <div class="qcat-tier-count"><?php echo $q_in_tier; ?> questions</div>
          </div>

          <?php if (!$unlocked):
            // Show what's needed to unlock
            $prev_td   = $tier_data[$tier - 1] ?? null;
            $prev_done = $prev_td ? (int)$prev_td['questions_done'] : 0;
            $prev_corr = $prev_td ? (int)$prev_td['correct_count']  : 0;
            $prev_pct  = $prev_done > 0 ? round(($prev_corr / $prev_done) * 100) : 0;
            $need_done = max(0, 10 - $prev_done);
          ?>
          <div class="qcat-tier-unlock-req">
            <div class="qcat-tier-req-text">
              Score 70%+ on Tier <?php echo $tier - 1; ?> (min 10 questions)
              <?php if ($prev_done > 0): ?>
              &nbsp;&mdash;&nbsp; Current: <?php echo $prev_pct; ?>% (<?php echo $prev_done; ?>/10)
              <?php endif; ?>
            </div>
            <?php if ($prev_done > 0): ?>
            <div class="qcat-tier-bar-wrap">
              <div class="qcat-tier-bar-fill" style="width:<?php echo min(100, $prev_pct); ?>%;background:<?php echo $prev_pct >= 70 ? 'var(--accent)' : 'var(--warn)'; ?>"></div>
            </div>
            <?php endif; ?>
          </div>

          <?php else: ?>

          <?php if ($q_done > 0): ?>
          <div class="qcat-tier-stats">
            <span><?php echo $q_done; ?> answered</span>
            <span style="color:var(--accent)"><?php echo $q_correct; ?> correct</span>
            <span style="color:<?php echo $acc_pct >= 70 ? 'var(--accent)' : 'var(--warn)'; ?>"><?php echo $acc_pct; ?>% accuracy</span>
          </div>
          <div class="qcat-tier-bar-wrap">
            <div class="qcat-tier-bar-fill" style="width:<?php echo min(100, $acc_pct); ?>%;background:<?php echo $acc_pct >= 70 ? 'var(--accent)' : ($acc_pct >= 50 ? 'var(--warn)' : 'var(--danger)'); ?>"></div>
          </div>
          <?php else: ?>
          <div class="qcat-tier-stats" style="color:var(--text3)">Not started yet</div>
          <?php endif; ?>

          <div style="margin-top:10px">
            <a href="/quiz/quiz_play.php?slug=<?php echo urlencode($slug); ?>&tier=<?php echo $tier; ?>"
               class="btn-primary" style="font-size:13px;padding:8px 18px;text-decoration:none;display:inline-block">
              <?php echo $q_done > 0 ? 'Continue Tier ' . $tier : 'Start Tier ' . $tier; ?> &rsaquo;
            </a>
          </div>

          <?php endif; ?>

        </div>
        <?php endfor; ?>
      </div>
    </div>

  </main>
</div>
</body>
</html>
