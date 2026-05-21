<?php
require_once __DIR__ . '/../config/app.php';
$user    = require_login();
$xp_data = xp_progress((int)$user['xp']);
$level   = $xp_data['level'];
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

// Top users by XP
$top_users = db()->query('
    SELECT u.id, u.username, u.xp, u.level, u.avatar_initials,
           COUNT(DISTINCT s.id)  as scan_count,
           COUNT(DISTINCT la.id) as labs_solved,
           COUNT(DISTINCT qa.id) as quiz_answered
    FROM users u
    LEFT JOIN scans s        ON s.user_id = u.id AND s.status = "done"
    LEFT JOIN lab_attempts la ON la.user_id = u.id AND la.status = "solved"
    LEFT JOIN quiz_attempts qa ON qa.user_id = u.id
    GROUP BY u.id
    ORDER BY u.xp DESC
    LIMIT 20
')->fetchAll();

// Find current user rank
$rank_stmt = db()->prepare('
    SELECT COUNT(*) + 1 as `user_rank`
    FROM users
    WHERE xp > (SELECT xp FROM users WHERE id = ?)
');
$rank_stmt->execute([$user['id']]);
$my_rank = (int)$rank_stmt->fetchColumn();

// Platform stats
$platform = db()->query('
    SELECT
        COUNT(DISTINCT u.id)   as total_users,
        COUNT(DISTINCT s.id)   as total_scans,
        COUNT(DISTINCT la.id)  as total_labs_solved,
        COUNT(DISTINCT qa.id)  as total_quiz_answers
    FROM users u
    LEFT JOIN scans s         ON s.user_id = u.id AND s.status = "done"
    LEFT JOIN lab_attempts la ON la.user_id = u.id AND la.status = "solved"
    LEFT JOIN quiz_attempts qa ON qa.user_id = u.id
')->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HakDel - Leaderboard</title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/layout.css">
</head>
<body>

<?php require __DIR__ . '/../partials/topbar.php'; ?>

<div class="hk-shell">
  <?php
$nav_active  = 'leaderboard';
$sidebar_sub = 'Leaderboard';
require __DIR__ . '/../partials/sidebar.php';
?>

  <main class="hk-main">

    <div class="hk-page-header">
      <div>
        <div class="hk-page-eyebrow">&#9651; Rankings &nbsp;&middot;&nbsp; Top 20</div>
        <h1 class="hk-page-title">Leaderboard</h1>
        <p class="hk-page-sub">Ranked by XP earned through scans, labs, and quiz answers.</p>
      </div>
    </div>

    <!-- Platform stats -->
    <div class="quiz-stats-row">
      <div class="qstat-card">
        <div class="qstat-num"><?php echo $platform['total_users']; ?></div>
        <div class="qstat-label">Total Users</div>
      </div>
      <div class="qstat-card">
        <div class="qstat-num" style="color:var(--accent)"><?php echo $platform['total_scans']; ?></div>
        <div class="qstat-label">Scans Run</div>
      </div>
      <div class="qstat-card">
        <div class="qstat-num" style="color:var(--accent2)"><?php echo $platform['total_labs_solved']; ?></div>
        <div class="qstat-label">Labs Solved</div>
      </div>
      <div class="qstat-card">
        <div class="qstat-num" style="color:var(--warn)"><?php echo $platform['total_quiz_answers']; ?></div>
        <div class="qstat-label">Quiz Answers</div>
      </div>
    </div>

    <!-- Your rank card -->
    <div class="lb-my-rank">
      <div class="lb-my-rank-left">
        <div class="lb-my-avatar"><?php echo htmlspecialchars($initials); ?></div>
        <div>
          <div class="lb-my-name"><?php echo htmlspecialchars($user['username']); ?></div>
          <div class="lb-my-sub">Your current ranking</div>
        </div>
      </div>
      <div class="lb-my-stats">
        <div class="lb-my-stat">
          <div class="lb-my-stat-num">#<?php echo $my_rank; ?></div>
          <div class="lb-my-stat-label">Rank</div>
        </div>
        <div class="lb-my-stat">
          <div class="lb-my-stat-num" style="color:var(--accent)"><?php echo $user['xp']; ?></div>
          <div class="lb-my-stat-label">XP</div>
        </div>
        <div class="lb-my-stat">
          <div class="lb-my-stat-num"><?php echo $level; ?></div>
          <div class="lb-my-stat-label">Level</div>
        </div>
      </div>
    </div>

    <!-- Leaderboard table -->
    <div class="history-table-card">
      <div class="history-table-header">
        <span class="history-table-title">Top Hackers</span>
        <span class="history-table-meta">Updated live</span>
      </div>

      <div class="lb-table-head">
        <span class="lb-col-rank">Rank</span>
        <span class="lb-col-user">User</span>
        <span class="lb-col-xp">XP</span>
        <span class="lb-col-level">Level</span>
        <span class="lb-col-scans">Scans</span>
        <span class="lb-col-labs">Labs</span>
        <span class="lb-col-quiz">Quiz</span>
      </div>

      <?php foreach ($top_users as $i => $u):
        $rank      = $i + 1;
        $is_me     = $u['id'] === $user['id'];
        $initials_u = $u['avatar_initials'] ?? strtoupper(substr($u['username'], 0, 2));
        $rank_class = $rank === 1 ? 'rank-gold' : ($rank === 2 ? 'rank-silver' : ($rank === 3 ? 'rank-bronze' : ''));
        $rank_icon  = $rank === 1 ? '&#9733;' : ($rank === 2 ? '&#9651;' : ($rank === 3 ? '&#9670;' : '#' . $rank));
      ?>
      <div class="lb-row <?php echo $is_me ? 'lb-row-me' : ''; ?>">
        <span class="lb-col-rank">
          <span class="lb-rank <?php echo $rank_class; ?>"><?php echo $rank_icon; ?></span>
        </span>
        <span class="lb-col-user">
          <div class="lb-user-avatar <?php echo $is_me ? 'lb-avatar-me' : ''; ?>"><?php echo htmlspecialchars($initials_u); ?></div>
          <span class="lb-username <?php echo $is_me ? 'lb-username-me' : ''; ?>">
            <?php echo htmlspecialchars($u['username']); ?>
            <?php if ($is_me): ?><span class="lb-you-tag">you</span><?php endif; ?>
          </span>
        </span>
        <span class="lb-col-xp"><?php echo number_format($u['xp']); ?></span>
        <span class="lb-col-level"><?php echo $u['level']; ?></span>
        <span class="lb-col-scans"><?php echo $u['scan_count']; ?></span>
        <span class="lb-col-labs"><?php echo $u['labs_solved']; ?></span>
        <span class="lb-col-quiz"><?php echo $u['quiz_answered']; ?></span>
      </div>
      <?php endforeach; ?>

    </div>

  </main>
</div>

</body>
</html>