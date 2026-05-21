<?php
require_once __DIR__ . '/../config/app.php';
$user    = require_login();
$xp_data = xp_progress((int)$user['xp']);
$level   = $xp_data['level'];
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

// Handle form submissions
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $url       = filter_var($_POST['target_url'] ?? '', FILTER_VALIDATE_URL);
        $profile   = in_array($_POST['profile'] ?? '', ['quick','full']) ? $_POST['profile'] : 'quick';
        $frequency = in_array($_POST['frequency'] ?? '', ['daily','weekly','monthly']) ? $_POST['frequency'] : 'weekly';
        $threshold = max(0, min(100, (int)($_POST['alert_threshold'] ?? 70)));
        $email_on  = isset($_POST['email_alerts']) ? 1 : 0;
        if ($url) {
            $next_run = match($frequency) {
                'daily'   => date('Y-m-d H:i:s', strtotime('+1 day')),
                'weekly'  => date('Y-m-d H:i:s', strtotime('+1 week')),
                'monthly' => date('Y-m-d H:i:s', strtotime('+1 month')),
            };
            $stmt = db()->prepare('INSERT INTO scheduled_scans (user_id,target_url,profile,frequency,alert_threshold,email_alerts,next_run_at) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$user['id'], $url, $profile, $frequency, $threshold, $email_on, $next_run]);
            $msg = 'Schedule created.';
        } else {
            $msg = 'Invalid URL.';
        }
    } elseif ($action === 'delete') {
        $del_id = (int)($_POST['schedule_id'] ?? 0);
        if ($del_id) {
            db()->prepare('DELETE FROM scheduled_scans WHERE id=? AND user_id=?')->execute([$del_id, $user['id']]);
            $msg = 'Schedule deleted.';
        }
    } elseif ($action === 'toggle') {
        $tog_id = (int)($_POST['schedule_id'] ?? 0);
        if ($tog_id) {
            db()->prepare('UPDATE scheduled_scans SET active = 1-active WHERE id=? AND user_id=?')->execute([$tog_id, $user['id']]);
        }
    }
}

$stmt = db()->prepare('SELECT * FROM scheduled_scans WHERE user_id=? ORDER BY created_at DESC');
$stmt->execute([$user['id']]);
$schedules = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HakDel - Scheduled Scans</title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/layout.css">
</head>
<body>
<?php require __DIR__ . '/../partials/topbar.php'; ?>
<div class="hk-shell">
  <?php
  $nav_active  = 'schedule';
  $sidebar_sub = 'Scheduled Scans';
  require __DIR__ . '/../partials/sidebar.php';
  ?>
  <main class="hk-main">
    <div class="hk-page-header">
      <div>
        <div class="hk-page-eyebrow">&#9200; Scheduler &nbsp;&middot;&nbsp; Automated Scanning</div>
        <h1 class="hk-page-title">Scheduled Scans</h1>
        <p class="hk-page-sub">Set up recurring scans with email alerts on score changes.</p>
      </div>
    </div>

    <?php if ($msg): ?>
    <div class="hk-msg <?php echo str_contains($msg, 'Invalid') ? 'hk-msg-err' : 'hk-msg-ok'; ?>"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <!-- Create form -->
    <div class="schedule-form-card">
      <div class="schedule-form-title">New Scheduled Scan</div>
      <form method="POST" class="schedule-form">
        <input type="hidden" name="action" value="create">
        <div class="sform-row">
          <div class="sform-field">
            <label class="sform-label">Target URL</label>
            <input type="url" name="target_url" class="sform-input" placeholder="https://example.com" required>
          </div>
          <div class="sform-field sform-field-sm">
            <label class="sform-label">Profile</label>
            <select name="profile" class="sform-select">
              <option value="quick">Quick</option>
              <option value="full">Full</option>
            </select>
          </div>
          <div class="sform-field sform-field-sm">
            <label class="sform-label">Frequency</label>
            <select name="frequency" class="sform-select">
              <option value="daily">Daily</option>
              <option value="weekly" selected>Weekly</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>
          <div class="sform-field sform-field-sm">
            <label class="sform-label">Alert if score &lt;</label>
            <input type="number" name="alert_threshold" class="sform-input" value="70" min="0" max="100">
          </div>
        </div>
        <div class="sform-footer">
          <label class="sform-check">
            <input type="checkbox" name="email_alerts" checked>
            <span>Email alerts on score drop</span>
          </label>
          <button type="submit" class="btn-primary">&#43; Create Schedule</button>
        </div>
      </form>
    </div>

    <!-- Schedule list -->
    <?php if (empty($schedules)): ?>
    <div class="compare-empty">No scheduled scans yet. Create one above.</div>
    <?php else: ?>
    <div class="schedule-list">
      <?php foreach ($schedules as $s): ?>
      <div class="schedule-card <?php echo $s['active'] ? '' : 'schedule-paused'; ?>">
        <div class="schedule-card-left">
          <div class="schedule-target"><?php echo htmlspecialchars($s['target_url']); ?></div>
          <div class="schedule-meta">
            <?php echo ucfirst($s['profile']); ?> scan &nbsp;&middot;&nbsp;
            <?php echo ucfirst($s['frequency']); ?> &nbsp;&middot;&nbsp;
            Alert if score &lt; <?php echo $s['alert_threshold']; ?> &nbsp;&middot;&nbsp;
            <?php echo $s['email_alerts'] ? '&#9993; Email on' : 'No email'; ?>
          </div>
          <div class="schedule-next">
            <?php if ($s['last_run_at']): ?>
            Last run: <?php echo date('d M Y H:i', strtotime($s['last_run_at'])); ?> &nbsp;&middot;&nbsp;
            Score: <?php echo $s['last_score'] ?? 'N/A'; ?>
            <?php endif; ?>
            Next run: <?php echo date('d M Y H:i', strtotime($s['next_run_at'])); ?>
          </div>
        </div>
        <div class="schedule-card-actions">
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="schedule_id" value="<?php echo $s['id']; ?>">
            <button type="submit" class="btn-secondary" style="padding:7px 14px;font-size:13px">
              <?php echo $s['active'] ? '&#10074;&#10074; Pause' : '&#9654; Resume'; ?>
            </button>
          </form>
          <form method="POST" style="display:inline" onsubmit="return confirm('Delete this schedule?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="schedule_id" value="<?php echo $s['id']; ?>">
            <button type="submit" class="btn-secondary" style="padding:7px 14px;font-size:13px;color:var(--danger);border-color:rgba(255,77,109,0.2)">&#128465; Delete</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (($user['role'] ?? '') === 'admin'): ?>
    <!-- Cron setup instructions — admin only -->
    <div class="cron-info-card">
      <div class="cron-info-title">&#9881; Cron Setup</div>
      <p class="cron-info-text">Add this to your server crontab to run scheduled scans:</p>
      <code class="cron-code">* * * * * php <?php echo realpath(__DIR__ . '/../../'); ?>/cron/run-scheduled.php >> /tmp/hakdel-cron.log 2>&1</code>
    </div>
    <?php endif; ?>

  </main>
</div>
</body>
</html>
