<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'incidents';
$topbar_title = 'New Incident';

$uid = (int)$user['id'];
$pdo = db();

// Ensure tables exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS incidents (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        severity ENUM('critical','high','medium','low','info') DEFAULT 'medium',
        status ENUM('open','investigating','contained','resolved','closed') DEFAULT 'open',
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (user_id, status)
    )");
} catch (Exception $e) {}

$error = '';

if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $error = 'Invalid form submission.';
    } else {
        $title       = trim($_POST['title']       ?? '');
        $severity    = $_POST['severity']         ?? 'medium';
        $status      = $_POST['status']           ?? 'open';
        $description = trim($_POST['description'] ?? '');

        $valid_sev    = ['critical', 'high', 'medium', 'low', 'info'];
        $valid_status = ['open', 'investigating', 'contained', 'resolved', 'closed'];

        if (strlen($title) < 3) {
            $error = 'Title must be at least 3 characters.';
        } elseif (!in_array($severity, $valid_sev)) {
            $error = 'Invalid severity.';
        } elseif (!in_array($status, $valid_status)) {
            $error = 'Invalid status.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO incidents (user_id, title, severity, status, description)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$uid, $title, $severity, $status, $description ?: null]);
            $new_id = (int)$pdo->lastInsertId();
            redirect('/incidents/view.php?id=' . $new_id);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>New Incident — HakDel</title>
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="stylesheet" href="/assets/layout.css">
  <style>
    .create-inc-card {
      max-width: 640px;
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: var(--radius-lg); overflow: hidden;
    }
    .create-inc-header {
      padding: 18px 24px; border-bottom: 1px solid var(--border);
      font-family: var(--mono); font-size: 14px; font-weight: 700; color: var(--text);
    }
    .create-inc-body {
      padding: 24px; display: flex; flex-direction: column; gap: 18px;
    }
    .form-field { display: flex; flex-direction: column; gap: 6px; }
    .form-label { font-size: 12px; font-weight: 600; color: var(--text2); }
    .form-input, .form-select, .form-textarea {
      background: var(--bg3); border: 1px solid var(--border2);
      border-radius: var(--radius); padding: 10px 12px;
      font-size: 13px; color: var(--text); outline: none;
      transition: border-color 0.15s; font-family: inherit; width: 100%;
    }
    .form-input:focus, .form-select:focus, .form-textarea:focus { border-color: var(--accent); }
    .form-select option { background: var(--bg2); }
    .form-textarea { min-height: 120px; resize: vertical; line-height: 1.6; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .create-inc-actions {
      display: flex; gap: 10px; padding: 16px 24px;
      border-top: 1px solid var(--border);
    }
    .btn-create {
      background: var(--accent); color: var(--bg); border: none;
      border-radius: var(--radius); padding: 10px 24px;
      font-family: var(--mono); font-size: 13px; font-weight: 700;
      cursor: pointer; transition: opacity 0.15s;
    }
    .btn-create:hover { opacity: 0.85; }
    .btn-cancel {
      background: transparent; color: var(--text2);
      border: 1px solid var(--border2); border-radius: var(--radius);
      padding: 10px 20px; font-family: var(--mono); font-size: 13px;
      cursor: pointer; text-decoration: none; transition: all 0.12s;
    }
    .btn-cancel:hover { color: var(--text); border-color: rgba(255,255,255,0.2); }
    .form-error {
      background: rgba(255,77,77,0.08); border: 1px solid rgba(255,77,77,0.2);
      border-radius: var(--radius); padding: 10px 14px;
      font-size: 13px; color: var(--danger);
    }
  </style>
</head>
<body>
<?php require __DIR__ . '/../partials/topbar.php'; ?>
<div class="hk-shell">
<?php require __DIR__ . '/../partials/sidebar.php'; ?>
<main class="hk-main">

  <div class="hk-page-header">
    <div>
      <div class="hk-page-eyebrow">INCIDENTS</div>
      <h1 class="hk-page-title">New Incident</h1>
    </div>
    <div class="hk-page-actions">
      <a href="/incidents/" class="btn-cancel" style="display:inline-flex;align-items:center">
        &larr; Back
      </a>
    </div>
  </div>

  <div class="create-inc-card">
    <div class="create-inc-header">Create Incident</div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <div class="create-inc-body">

        <?php if ($error): ?>
        <div class="form-error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <div class="form-field">
          <label class="form-label">Title <span style="color:var(--danger)">*</span></label>
          <input type="text" name="title" class="form-input"
                 placeholder="e.g. Suspicious login from unknown IP"
                 value="<?php echo h($_POST['title'] ?? ''); ?>"
                 maxlength="255" required>
        </div>

        <div class="form-row">
          <div class="form-field">
            <label class="form-label">Severity</label>
            <select name="severity" class="form-select">
              <?php foreach (['critical', 'high', 'medium', 'low', 'info'] as $sev): ?>
              <option value="<?php echo $sev; ?>" <?php echo ($_POST['severity'] ?? 'medium') === $sev ? 'selected' : ''; ?>>
                <?php echo ucfirst($sev); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-field">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <?php foreach (['open', 'investigating', 'contained', 'resolved', 'closed'] as $st): ?>
              <option value="<?php echo $st; ?>" <?php echo ($_POST['status'] ?? 'open') === $st ? 'selected' : ''; ?>>
                <?php echo ucfirst($st); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-field">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-textarea"
                    placeholder="Describe the incident, what happened, initial findings..."><?php echo h($_POST['description'] ?? ''); ?></textarea>
        </div>

      </div>
      <div class="create-inc-actions">
        <button type="submit" class="btn-create">Create Incident</button>
        <a href="/incidents/" class="btn-cancel">Cancel</a>
      </div>
    </form>
  </div>

</main>
</div>
</body>
</html>
