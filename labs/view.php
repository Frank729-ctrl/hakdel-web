<?php
require_once __DIR__ . '/../config/app.php';
$user    = require_login();
$xp_data = xp_progress((int)$user['xp']);
$level   = $xp_data['level'];
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$slug = trim($_GET['slug'] ?? '');
if (!$slug) redirect('/labs/');

// Fetch lab
$stmt = db()->prepare('SELECT * FROM labs WHERE slug = ? AND is_active = 1');
$stmt->execute([$slug]);
$lab = $stmt->fetch();
if (!$lab) redirect('/labs/');

// Level gate
if ($lab['level_required'] > $level) redirect('/labs/');

// Fetch or create attempt
$stmt = db()->prepare('SELECT * FROM lab_attempts WHERE user_id = ? AND lab_id = ?');
$stmt->execute([$user['id'], $lab['id']]);
$attempt = $stmt->fetch();

if (!$attempt) {
    db()->prepare('INSERT INTO lab_attempts (user_id, lab_id, status) VALUES (?, ?, "started")')
       ->execute([$user['id'], $lab['id']]);
    $attempt = ['status' => 'started', 'attempts_count' => 0];
}

$already_solved = $attempt['status'] === 'solved';
$hints = json_decode($lab['hints'] ?? '[]', true);

// Flash messages
$success_msg = get_flash('lab_success');
$error_msg   = get_flash('lab_error');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HakDel - <?php echo htmlspecialchars($lab['title']); ?></title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/layout.css">
</head>
<body>

<?php require __DIR__ . '/../partials/topbar.php'; ?>

<div class="hk-shell">
  <?php
$nav_active  = 'labs';
$sidebar_sub = $lab['title'];
$sidebar_footer = '';
require __DIR__ . '/../partials/sidebar.php';
?>

  <main class="hk-main">

    <div class="hk-page-header">
      <div>
        <div class="hk-page-eyebrow">
          &#8592; <a href="/labs/" style="color:var(--accent);text-decoration:none">Labs</a>
          &nbsp;&middot;&nbsp; <?php echo htmlspecialchars($lab['category'] ?? 'General'); ?>
        </div>
        <h1 class="hk-page-title"><?php echo htmlspecialchars($lab['title']); ?></h1>
        <p class="hk-page-sub"><?php echo htmlspecialchars($lab['description']); ?></p>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-shrink:0">
        <?php
        $diff_class = ['easy'=>'diff-easy','medium'=>'diff-med','hard'=>'diff-hard','expert'=>'diff-expert'][$lab['difficulty']] ?? '';
        ?>
        <span class="lab-diff <?php echo $diff_class; ?>"><?php echo ucfirst($lab['difficulty']); ?></span>
        <span class="lab-xp">+<?php echo $lab['xp_reward']; ?> XP</span>
      </div>
    </div>

    <?php if ($success_msg): ?>
    <div class="lab-alert lab-alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
    <div class="lab-alert lab-alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <?php if ($already_solved): ?>
    <div class="lab-alert lab-alert-success">
      &#10003; You have already solved this lab. Well done!
    </div>
    <?php endif; ?>

    <div class="lab-layout">

      <!-- Left: instructions + SSH details -->
      <div class="lab-left">

        <!-- SSH Connection card -->
        <div class="lab-ssh-card">
          <div class="lab-section-title">&#9654; Connect via SSH</div>
          <p class="lab-ssh-desc">Start your terminal and connect to the lab machine with the credentials below. The lab runs on your local VirtualBox VM.</p>

          <div class="ssh-creds">
            <div class="ssh-cred-row">
              <span class="ssh-cred-label">Host</span>
              <code class="ssh-cred-val" id="ssh-host"><?php echo htmlspecialchars($lab['ssh_host'] ?? '192.168.56.101'); ?></code>
              <button class="btn-copy" onclick="copyText('ssh-host')">Copy</button>
            </div>
            <div class="ssh-cred-row">
              <span class="ssh-cred-label">Port</span>
              <code class="ssh-cred-val" id="ssh-port"><?php echo htmlspecialchars($lab['ssh_port'] ?? '22'); ?></code>
              <button class="btn-copy" onclick="copyText('ssh-port')">Copy</button>
            </div>
            <div class="ssh-cred-row">
              <span class="ssh-cred-label">Username</span>
              <code class="ssh-cred-val" id="ssh-user"><?php echo htmlspecialchars($lab['ssh_user'] ?? 'player'); ?></code>
              <button class="btn-copy" onclick="copyText('ssh-user')">Copy</button>
            </div>
            <div class="ssh-cred-row">
              <span class="ssh-cred-label">Password</span>
              <code class="ssh-cred-val" id="ssh-pass"><?php echo htmlspecialchars($lab['ssh_password'] ?? 'hakdel2024'); ?></code>
              <button class="btn-copy" onclick="copyText('ssh-pass')">Copy</button>
            </div>
          </div>

          <div class="ssh-command-wrap">
            <span class="ssh-cmd-label">Quick connect command:</span>
            <div class="ssh-command">
              <code id="ssh-full-cmd">ssh <?php echo htmlspecialchars($lab['ssh_user'] ?? 'player'); ?>@<?php echo htmlspecialchars($lab['ssh_host'] ?? '192.168.56.101'); ?> -p <?php echo htmlspecialchars($lab['ssh_port'] ?? '22'); ?></code>
              <button class="btn-copy" onclick="copyText('ssh-full-cmd')">Copy</button>
            </div>
          </div>
        </div>

        <!-- Instructions card -->
        <div class="lab-instructions-card">
          <div class="lab-section-title">&#9783; Instructions</div>
          <div class="lab-instructions-body">
            <?php
            // Convert markdown-style content to basic HTML
            $instructions = $lab['instructions'] ?? 'Instructions coming soon.';
            $instructions = htmlspecialchars($instructions);
            $instructions = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $instructions);
            $instructions = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $instructions);
            $instructions = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $instructions);
            $instructions = preg_replace('/`(.+?)`/', '<code class="inline-code">$1</code>', $instructions);
            $instructions = nl2br($instructions);
            echo $instructions;
            ?>
          </div>
        </div>

      </div>

      <!-- Right: flag submission + hints -->
      <div class="lab-right">

        <!-- Flag submission -->
        <div class="lab-flag-card <?php echo $already_solved ? 'flag-card-solved' : ''; ?>">
          <div class="lab-section-title">
            <?php echo $already_solved ? '&#10003; Lab Solved' : '&#9673; Submit Flag'; ?>
          </div>

          <?php if ($already_solved): ?>
          <div class="flag-solved-msg">
            You solved this lab and earned <strong><?php echo $lab['xp_reward']; ?> XP</strong>.
          </div>
          <?php else: ?>
          <p class="flag-desc">Found the flag? Paste it below. Flags are in the format <code class="inline-code">flag{...}</code></p>

          <form method="POST" action="/labs/submit.php" class="flag-form">
            <input type="hidden" name="csrf"    value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="lab_id"  value="<?php echo $lab['id']; ?>">
            <input type="hidden" name="lab_slug" value="<?php echo h($slug); ?>">
            <div class="flag-input-row">
              <input type="text" name="flag" class="flag-input"
                     placeholder="flag{...}" spellcheck="false" autocomplete="off" required>
              <button type="submit" class="btn-primary">Submit</button>
            </div>
          </form>

          <?php if ($attempt['attempts_count'] > 0): ?>
          <div class="flag-attempts">
            <?php echo $attempt['attempts_count']; ?> failed attempt<?php echo $attempt['attempts_count'] != 1 ? 's' : ''; ?>
          </div>
          <?php endif; ?>
          <?php endif; ?>
        </div>

        <!-- Hints -->
        <?php if (!empty($hints)): ?>
        <div class="lab-hints-card">
          <div class="lab-section-title">&#9888; Hints</div>
          <p class="hints-warning">Hints are here to unstick you, not to solve it for you.</p>
          <?php foreach ($hints as $i => $hint): ?>
          <div class="hint-item">
            <button class="hint-toggle" onclick="toggleHint(<?php echo $i; ?>)">
              Hint <?php echo $i + 1; ?> <span class="hint-caret" id="hcaret-<?php echo $i; ?>">&#8964;</span>
            </button>
            <div class="hint-body" id="hint-<?php echo $i; ?>" style="display:none">
              <?php echo htmlspecialchars($hint); ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Lab meta -->
        <div class="lab-meta-card">
          <div class="lab-meta-row">
            <span class="lab-meta-label">Category</span>
            <span class="lab-meta-val"><?php echo htmlspecialchars($lab['category'] ?? 'General'); ?></span>
          </div>
          <div class="lab-meta-row">
            <span class="lab-meta-label">Difficulty</span>
            <span class="lab-meta-val"><?php echo ucfirst($lab['difficulty']); ?></span>
          </div>
          <div class="lab-meta-row">
            <span class="lab-meta-label">XP Reward</span>
            <span class="lab-meta-val">+<?php echo $lab['xp_reward']; ?> XP</span>
          </div>
          <div class="lab-meta-row">
            <span class="lab-meta-label">Level Required</span>
            <span class="lab-meta-val">Level <?php echo $lab['level_required']; ?></span>
          </div>
          <div class="lab-meta-row">
            <span class="lab-meta-label">Your Attempts</span>
            <span class="lab-meta-val"><?php echo $attempt['attempts_count'] ?? 0; ?></span>
          </div>
        </div>

      </div><!-- /lab-right -->
    </div><!-- /lab-layout -->

  </main>
</div>

<script src="/assets/js/lab.js"></script>
</body>
</html>