<?php
require_once __DIR__ . '/../config/app.php';
$user    = require_login();
$xp_data = xp_progress((int)$user['xp']);
$level   = $xp_data['level'];
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

// Fetch all labs
$labs = db()->query('
    SELECT l.*,
           a.status as attempt_status,
           a.attempts_count
    FROM labs l
    LEFT JOIN lab_attempts a ON a.lab_id = l.id AND a.user_id = ' . (int)$user['id'] . '
    WHERE l.is_active = 1
    ORDER BY l.sort_order ASC, l.difficulty ASC
')->fetchAll();

// Stats
$solved = array_filter($labs, fn($l) => $l['attempt_status'] === 'solved');
$started = array_filter($labs, fn($l) => $l['attempt_status'] === 'started');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HakDel - Labs</title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/layout.css">
</head>
<body>

<?php require __DIR__ . '/../partials/topbar.php'; ?>

<div class="hk-shell">
  <?php
$nav_active  = 'labs';
$sidebar_sub = 'Labs';
require __DIR__ . '/../partials/sidebar.php';
?>

  <main class="hk-main">

    <div class="hk-page-header">
      <div>
        <div class="hk-page-eyebrow">&#9670; Labs &nbsp;&middot;&nbsp; SSH-based challenges</div>
        <h1 class="hk-page-title">CEH Labs</h1>
        <p class="hk-page-sub">SSH into the lab machine, exploit the vulnerability, find the flag, submit it here.</p>
      </div>
    </div>

    <!-- Stats -->
    <div class="quiz-stats-row">
      <div class="qstat-card">
        <div class="qstat-num"><?php echo count($labs); ?></div>
        <div class="qstat-label">Total Labs</div>
      </div>
      <div class="qstat-card">
        <div class="qstat-num" style="color:var(--accent)"><?php echo count($solved); ?></div>
        <div class="qstat-label">Solved</div>
      </div>
      <div class="qstat-card">
        <div class="qstat-num" style="color:var(--warn)"><?php echo count($started); ?></div>
        <div class="qstat-label">In Progress</div>
      </div>
      <div class="qstat-card">
        <div class="qstat-num" style="color:var(--accent2)"><?php echo count($labs) - count($solved) - count($started); ?></div>
        <div class="qstat-label">Not Started</div>
      </div>
    </div>

    <!-- Labs grid -->
    <div class="labs-grid">
      <?php foreach ($labs as $lab):
        $locked   = $lab['level_required'] > $level;
        $status   = $lab['attempt_status'] ?? 'none';
        $diff_class = [
          'easy'   => 'diff-easy',
          'medium' => 'diff-med',
          'hard'   => 'diff-hard',
          'expert' => 'diff-expert',
        ][$lab['difficulty']] ?? 'diff-easy';
        $cat_icon = [
          'Web Exploitation'      => '&#9874;',
          'Network Attacks'       => '&#9832;',
          'Linux Fundamentals'    => '&#9649;',
          'Privilege Escalation'  => '&#8679;',
          'Password Attacks'      => '&#9632;',
          'CEH Domain'            => '&#9671;',
        ][$lab['category']] ?? '&#9670;';
      ?>
      <div class="lab-card <?php echo $locked ? 'lab-locked' : ''; ?> <?php echo $status === 'solved' ? 'lab-solved' : ''; ?>"
           <?php if (!$locked): ?>onclick="window.location='lab.php?slug=<?php echo urlencode($lab['slug']); ?>'"<?php endif; ?>>

        <div class="lab-card-top">
          <span class="lab-cat-icon"><?php echo $cat_icon; ?></span>
          <span class="lab-category"><?php echo htmlspecialchars($lab['category'] ?? 'General'); ?></span>
          <?php if ($status === 'solved'): ?>
            <span class="lab-status-badge badge-solved">&#10003; Solved</span>
          <?php elseif ($status === 'started'): ?>
            <span class="lab-status-badge badge-started">&#9654; Started</span>
          <?php elseif ($locked): ?>
            <span class="lab-status-badge badge-locked">&#128274; LVL <?php echo $lab['level_required']; ?></span>
          <?php endif; ?>
        </div>

        <div class="lab-title"><?php echo htmlspecialchars($lab['title']); ?></div>
        <div class="lab-desc"><?php echo htmlspecialchars($lab['description']); ?></div>

        <div class="lab-card-footer">
          <span class="lab-diff <?php echo $diff_class; ?>"><?php echo ucfirst($lab['difficulty']); ?></span>
          <span class="lab-xp">+<?php echo $lab['xp_reward']; ?> XP</span>
          <?php if ($lab['attempts_count'] > 0): ?>
          <span class="lab-attempts"><?php echo $lab['attempts_count']; ?> attempt<?php echo $lab['attempts_count'] != 1 ? 's' : ''; ?></span>
          <?php endif; ?>
          <?php if (!$locked): ?>
          <span class="lab-arrow">&#8250;</span>
          <?php endif; ?>
        </div>

      </div>
      <?php endforeach; ?>
    </div>

  </main>
</div>

</body>
</html>