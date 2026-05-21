<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'settings';
$topbar_title = 'Settings';

$uid = (int)$user['id'];
$pdo = db();

// Ensure user_settings table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_settings (
        user_id INT UNSIGNED PRIMARY KEY,
        notif_watchlist_email TINYINT(1) DEFAULT 1,
        notif_scan_email TINYINT(1) DEFAULT 0,
        notif_badge_email TINYINT(1) DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

// Ensure user_2fa table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_2fa (
        user_id INT UNSIGNED PRIMARY KEY,
        secret VARCHAR(32) NOT NULL,
        backup_codes JSON,
        enabled_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

// Load current settings
$settings = ['notif_watchlist_email' => 1, 'notif_scan_email' => 0, 'notif_badge_email' => 0];
try {
    $s = $pdo->prepare('SELECT * FROM user_settings WHERE user_id = ?');
    $s->execute([$uid]);
    $row = $s->fetch();
    if ($row) $settings = $row;
} catch (Exception $e) {}

// Load 2FA status
$has_2fa = false;
$tfa_row = null;
try {
    $s = $pdo->prepare('SELECT * FROM user_2fa WHERE user_id = ?');
    $s->execute([$uid]);
    $tfa_row = $s->fetch();
    $has_2fa = (bool)$tfa_row;
} catch (Exception $e) {}

$errors = [];
$success = get_flash('success');
$error   = get_flash('error');

if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        flash('error', 'Invalid form submission.');
        redirect('/settings/');
    }
    $action = $_POST['action'] ?? '';

    // Change username
    if ($action === 'change_username') {
        $new_username = trim($_POST['username'] ?? '');
        if (strlen($new_username) < 3 || strlen($new_username) > 32) {
            flash('error', 'Username must be 3-32 characters.');
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $new_username)) {
            flash('error', 'Username can only contain letters, numbers and underscores.');
        } else {
            $check = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
            $check->execute([$new_username, $uid]);
            if ($check->fetch()) {
                flash('error', 'That username is already taken.');
            } else {
                $pdo->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$new_username, $uid]);
                flash('success', 'Username updated successfully.');
            }
        }
        redirect('/settings/');
    }

    // Change email
    if ($action === 'change_email') {
        $new_email = trim(strtolower($_POST['email'] ?? ''));
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid email address.');
        } else {
            $check = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $check->execute([$new_email, $uid]);
            if ($check->fetch()) {
                flash('error', 'That email address is already in use.');
            } else {
                $pdo->prepare('UPDATE users SET email = ?, email_verified = 0 WHERE id = ?')->execute([$new_email, $uid]);
                flash('success', 'Email updated. Please verify your new email address.');
            }
        }
        redirect('/settings/');
    }

    // Change password
    if ($action === 'change_password') {
        $current_pw  = $_POST['current_password'] ?? '';
        $new_pw      = $_POST['new_password'] ?? '';
        $confirm_pw  = $_POST['confirm_password'] ?? '';
        $db_user = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $db_user->execute([$uid]);
        $db_user = $db_user->fetch();
        if (!$db_user || !password_verify($current_pw, $db_user['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } elseif (strlen($new_pw) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } elseif ($new_pw !== $confirm_pw) {
            flash('error', 'New passwords do not match.');
        } else {
            $hash = password_hash($new_pw, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $uid]);
            flash('success', 'Password changed successfully.');
        }
        redirect('/settings/#security');
    }

    // Save notification preferences
    if ($action === 'save_notifications') {
        $wl    = isset($_POST['notif_watchlist_email']) ? 1 : 0;
        $scan  = isset($_POST['notif_scan_email'])      ? 1 : 0;
        $badge = isset($_POST['notif_badge_email'])     ? 1 : 0;
        try {
            $pdo->prepare('INSERT INTO user_settings (user_id, notif_watchlist_email, notif_scan_email, notif_badge_email)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE notif_watchlist_email=VALUES(notif_watchlist_email),
                    notif_scan_email=VALUES(notif_scan_email), notif_badge_email=VALUES(notif_badge_email)')
                ->execute([$uid, $wl, $scan, $badge]);
            flash('success', 'Notification preferences saved.');
        } catch (Exception $e) {
            flash('error', 'Failed to save preferences.');
        }
        redirect('/settings/#notifications');
    }

    // Delete account
    if ($action === 'delete_account') {
        $typed = trim($_POST['confirm_username'] ?? '');
        if ($typed !== $user['username']) {
            flash('error', 'Username did not match. Account not deleted.');
            redirect('/settings/#danger');
        }
        // Delete all user data
        try {
            $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$uid]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        } catch (Exception $e) {}
        session_destroy();
        redirect('/auth/login.php');
    }

    redirect('/settings/');
}

$active_tab = $_GET['tab'] ?? 'account';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Settings — HakDel</title>
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="stylesheet" href="/assets/layout.css">
  <style>
    .settings-layout { display: flex; gap: 24px; align-items: flex-start; }
    .settings-tabs-nav {
      width: 180px; flex-shrink: 0;
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: var(--radius-lg); overflow: hidden;
    }
    .settings-tab-btn {
      display: flex; align-items: center; gap: 9px;
      width: 100%; padding: 11px 16px;
      background: none; border: none; border-bottom: 1px solid var(--border);
      color: var(--text2); font-size: 13px; font-weight: 500;
      cursor: pointer; text-align: left; transition: all 0.12s;
    }
    .settings-tab-btn:last-child { border-bottom: none; }
    .settings-tab-btn:hover { background: rgba(255,255,255,0.03); color: var(--text); }
    .settings-tab-btn.active { color: var(--accent); background: rgba(0,212,170,0.05); border-left: 2px solid var(--accent); }
    .settings-tab-btn.danger-tab { color: var(--danger); }
    .settings-tab-btn.danger-tab.active { color: var(--danger); background: rgba(255,77,77,0.05); border-left-color: var(--danger); }
    .settings-content { flex: 1; min-width: 0; }
    .settings-panel { display: none; }
    .settings-panel.active { display: flex; flex-direction: column; gap: 20px; }
    .settings-section {
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: var(--radius-lg); overflow: hidden;
    }
    .settings-section-header {
      padding: 16px 20px; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
    }
    .settings-section-title {
      font-family: var(--mono); font-size: 13px; font-weight: 700; color: var(--text);
    }
    .settings-section-sub { font-size: 12px; color: var(--text3); margin-top: 2px; }
    .settings-section-body { padding: 20px; display: flex; flex-direction: column; gap: 14px; }
    .settings-field { display: flex; flex-direction: column; gap: 5px; }
    .settings-label { font-size: 12px; font-weight: 600; color: var(--text2); }
    .settings-input {
      background: var(--bg3); border: 1px solid var(--border2);
      border-radius: var(--radius); padding: 9px 12px;
      font-size: 13px; color: var(--text); outline: none;
      transition: border-color 0.15s; font-family: inherit;
    }
    .settings-input:focus { border-color: var(--accent); }
    .settings-hint { font-size: 11px; color: var(--text3); }
    .settings-btn {
      display: inline-flex; align-items: center; gap: 6px;
      background: var(--accent); color: var(--bg); border: none;
      border-radius: var(--radius); padding: 9px 20px;
      font-family: var(--mono); font-size: 13px; font-weight: 700;
      cursor: pointer; transition: opacity 0.15s;
    }
    .settings-btn:hover { opacity: 0.85; }
    .settings-btn.secondary {
      background: transparent; color: var(--text2);
      border: 1px solid var(--border2);
    }
    .settings-btn.secondary:hover { color: var(--text); border-color: rgba(255,255,255,0.2); }
    .settings-btn.danger-btn {
      background: var(--danger); color: #fff;
    }
    .avatar-display {
      width: 64px; height: 64px; border-radius: 50%;
      background: var(--bg4); border: 2px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      font-family: var(--mono); font-size: 22px; color: var(--accent);
    }
    .tfa-status {
      display: flex; align-items: center; gap: 10px;
      padding: 12px 16px; background: var(--bg3);
      border-radius: var(--radius); border: 1px solid var(--border);
    }
    .tfa-status-icon { font-size: 20px; }
    .tfa-enabled { color: var(--accent); }
    .tfa-disabled { color: var(--text3); }
    .notif-check-row {
      display: flex; align-items: center; gap: 12px;
      padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.04);
    }
    .notif-check-row:last-child { border-bottom: none; }
    .notif-check-toggle { position: relative; width: 36px; height: 20px; flex-shrink: 0; }
    .notif-check-toggle input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
      position: absolute; cursor: pointer;
      top: 0; left: 0; right: 0; bottom: 0;
      background: var(--bg4); border-radius: 20px;
      transition: 0.2s; border: 1px solid var(--border);
    }
    .toggle-slider:before {
      content: ''; position: absolute;
      width: 14px; height: 14px; left: 2px; bottom: 2px;
      background: var(--text3); border-radius: 50%; transition: 0.2s;
    }
    .notif-check-toggle input:checked + .toggle-slider { background: rgba(0,212,170,0.3); border-color: var(--accent); }
    .notif-check-toggle input:checked + .toggle-slider:before { transform: translateX(16px); background: var(--accent); }
    .notif-check-info { flex: 1; }
    .notif-check-label { font-size: 13px; color: var(--text); font-weight: 500; }
    .notif-check-hint  { font-size: 11px; color: var(--text3); margin-top: 2px; }
    .danger-zone-section {
      background: var(--bg2); border: 1px solid rgba(255,77,77,0.3);
      border-radius: var(--radius-lg); overflow: hidden;
    }
    .danger-zone-section .settings-section-header {
      border-bottom-color: rgba(255,77,77,0.2);
      background: rgba(255,77,77,0.04);
    }
    .modal-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,0.7); z-index: 500;
      align-items: center; justify-content: center;
    }
    .modal-overlay.open { display: flex; }
    .modal-box {
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: var(--radius-lg); padding: 28px;
      max-width: 420px; width: 90%;
    }
    .modal-title { font-family: var(--mono); font-size: 16px; font-weight: 700; color: var(--danger); margin-bottom: 10px; }
    .modal-body { font-size: 13px; color: var(--text2); line-height: 1.6; margin-bottom: 16px; }
    .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
    .flash-success {
      background: rgba(0,212,170,0.08); border: 1px solid rgba(0,212,170,0.2);
      border-radius: var(--radius); padding: 10px 16px; font-size: 13px; color: var(--accent);
    }
    .flash-error {
      background: rgba(255,77,77,0.08); border: 1px solid rgba(255,77,77,0.2);
      border-radius: var(--radius); padding: 10px 16px; font-size: 13px; color: var(--danger);
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
      <div class="hk-page-eyebrow">WORKSPACE</div>
      <h1 class="hk-page-title">Settings</h1>
      <p class="hk-page-sub">Manage your account, security and preferences</p>
    </div>
  </div>

  <?php if ($success): ?>
  <div class="flash-success"><?php echo h($success); ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="flash-error"><?php echo h($error); ?></div>
  <?php endif; ?>

  <div class="settings-layout">
    <!-- Tab nav -->
    <div class="settings-tabs-nav">
      <button class="settings-tab-btn active" onclick="showTab('account', this)">&#128100; Account</button>
      <button class="settings-tab-btn" onclick="showTab('security', this)">&#128274; Security</button>
      <button class="settings-tab-btn" onclick="showTab('notifications', this)">&#128276; Notifications</button>
      <button class="settings-tab-btn danger-tab" onclick="showTab('danger', this)">&#9888; Danger Zone</button>
    </div>

    <!-- Panels -->
    <div class="settings-content">

      <!-- Account Panel -->
      <div class="settings-panel active" id="panel-account">
        <div class="settings-section">
          <div class="settings-section-header">
            <div>
              <div class="settings-section-title">Avatar</div>
              <div class="settings-section-sub">Your display initials</div>
            </div>
          </div>
          <div class="settings-section-body" style="flex-direction:row;align-items:center;gap:20px">
            <div class="avatar-display"><?php echo h($initials); ?></div>
            <div style="font-size:13px;color:var(--text3)">Avatar is generated from your username initials. Update your username to change it.</div>
          </div>
        </div>

        <div class="settings-section">
          <div class="settings-section-header">
            <div>
              <div class="settings-section-title">Change Username</div>
              <div class="settings-section-sub">3-32 characters, letters/numbers/underscores only</div>
            </div>
          </div>
          <form method="POST" class="settings-section-body">
            <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="change_username">
            <div class="settings-field">
              <label class="settings-label">New Username</label>
              <input type="text" name="username" class="settings-input"
                     value="<?php echo h($user['username']); ?>"
                     minlength="3" maxlength="32" pattern="[a-zA-Z0-9_]+" required>
            </div>
            <div>
              <button type="submit" class="settings-btn">Save Username</button>
            </div>
          </form>
        </div>

        <div class="settings-section">
          <div class="settings-section-header">
            <div>
              <div class="settings-section-title">Change Email</div>
              <div class="settings-section-sub">You will need to verify your new email address</div>
            </div>
          </div>
          <form method="POST" class="settings-section-body">
            <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="change_email">
            <div class="settings-field">
              <label class="settings-label">Current Email</label>
              <input type="text" class="settings-input" value="<?php echo h($user['email'] ?? ''); ?>" disabled>
            </div>
            <div class="settings-field">
              <label class="settings-label">New Email</label>
              <input type="email" name="email" class="settings-input" placeholder="new@email.com" required>
            </div>
            <div>
              <button type="submit" class="settings-btn">Update Email</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Security Panel -->
      <div class="settings-panel" id="panel-security">
        <div class="settings-section">
          <div class="settings-section-header">
            <div>
              <div class="settings-section-title">Change Password</div>
              <div class="settings-section-sub">Choose a strong password with at least 8 characters</div>
            </div>
          </div>
          <form method="POST" class="settings-section-body">
            <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="change_password">
            <div class="settings-field">
              <label class="settings-label">Current Password</label>
              <input type="password" name="current_password" class="settings-input" required autocomplete="current-password">
            </div>
            <div class="settings-field">
              <label class="settings-label">New Password</label>
              <input type="password" name="new_password" class="settings-input" required minlength="8" autocomplete="new-password">
            </div>
            <div class="settings-field">
              <label class="settings-label">Confirm New Password</label>
              <input type="password" name="confirm_password" class="settings-input" required minlength="8" autocomplete="new-password">
            </div>
            <div>
              <button type="submit" class="settings-btn">Change Password</button>
            </div>
          </form>
        </div>

        <div class="settings-section">
          <div class="settings-section-header">
            <div>
              <div class="settings-section-title">Two-Factor Authentication</div>
              <div class="settings-section-sub">Add an extra layer of security to your account</div>
            </div>
          </div>
          <div class="settings-section-body">
            <div class="tfa-status">
              <?php if ($has_2fa): ?>
              <span class="tfa-status-icon tfa-enabled">&#128274;</span>
              <div style="flex:1">
                <div style="font-size:13px;font-weight:600;color:var(--accent)">2FA is enabled</div>
                <div style="font-size:11px;color:var(--text3);margin-top:2px">
                  Enabled <?php echo date('M j, Y', strtotime($tfa_row['enabled_at'])); ?>
                </div>
              </div>
              <a href="/settings/2fa.php" class="settings-btn secondary">Manage 2FA</a>
              <?php else: ?>
              <span class="tfa-status-icon tfa-disabled">&#128275;</span>
              <div style="flex:1">
                <div style="font-size:13px;font-weight:600;color:var(--text2)">2FA is not enabled</div>
                <div style="font-size:11px;color:var(--text3);margin-top:2px">Protect your account with an authenticator app</div>
              </div>
              <a href="/settings/2fa.php" class="settings-btn">Enable 2FA</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Notifications Panel -->
      <div class="settings-panel" id="panel-notifications">
        <div class="settings-section">
          <div class="settings-section-header">
            <div>
              <div class="settings-section-title">Email Notifications</div>
              <div class="settings-section-sub">Control which emails you receive from HakDel</div>
            </div>
          </div>
          <form method="POST" class="settings-section-body">
            <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="save_notifications">

            <div class="notif-check-row">
              <label class="notif-check-toggle">
                <input type="checkbox" name="notif_watchlist_email" <?php echo $settings['notif_watchlist_email'] ? 'checked' : ''; ?>>
                <span class="toggle-slider"></span>
              </label>
              <div class="notif-check-info">
                <div class="notif-check-label">Watchlist Alerts</div>
                <div class="notif-check-hint">Email when a monitored domain has SSL or DNS changes</div>
              </div>
            </div>

            <div class="notif-check-row">
              <label class="notif-check-toggle">
                <input type="checkbox" name="notif_scan_email" <?php echo $settings['notif_scan_email'] ? 'checked' : ''; ?>>
                <span class="toggle-slider"></span>
              </label>
              <div class="notif-check-info">
                <div class="notif-check-label">Scan Complete</div>
                <div class="notif-check-hint">Email when a scheduled scan finishes</div>
              </div>
            </div>

            <div class="notif-check-row">
              <label class="notif-check-toggle">
                <input type="checkbox" name="notif_badge_email" <?php echo $settings['notif_badge_email'] ? 'checked' : ''; ?>>
                <span class="toggle-slider"></span>
              </label>
              <div class="notif-check-info">
                <div class="notif-check-label">Badge Earned</div>
                <div class="notif-check-hint">Email when you earn a new achievement badge</div>
              </div>
            </div>

            <div>
              <button type="submit" class="settings-btn">Save Preferences</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Danger Zone Panel -->
      <div class="settings-panel" id="panel-danger">
        <div class="danger-zone-section">
          <div class="settings-section-header">
            <div>
              <div class="settings-section-title" style="color:var(--danger)">Delete Account</div>
              <div class="settings-section-sub">Permanently delete your account and all associated data</div>
            </div>
          </div>
          <div class="settings-section-body">
            <div style="font-size:13px;color:var(--text2);line-height:1.6">
              This action is <strong style="color:var(--danger)">permanent and irreversible</strong>.
              All your scans, tool history, XP, badges, and personal data will be deleted immediately.
            </div>
            <div>
              <button type="button" class="settings-btn danger-btn" onclick="document.getElementById('delete-modal').classList.add('open')">
                &#128465; Delete My Account
              </button>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /.settings-content -->
  </div><!-- /.settings-layout -->

</main>
</div>

<!-- Delete Account Modal -->
<div class="modal-overlay" id="delete-modal">
  <div class="modal-box">
    <div class="modal-title">&#9888; Delete Account</div>
    <div class="modal-body">
      This will permanently delete your account and all your data. This cannot be undone.
      <br><br>
      Type your username <strong style="color:var(--text)"><?php echo h($user['username']); ?></strong> to confirm:
    </div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="action" value="delete_account">
      <input type="text" name="confirm_username" class="settings-input" style="width:100%;margin-bottom:16px"
             placeholder="Type your username" required autocomplete="off">
      <div class="modal-actions">
        <button type="button" class="settings-btn secondary" onclick="document.getElementById('delete-modal').classList.remove('open')">Cancel</button>
        <button type="submit" class="settings-btn danger-btn">Delete Forever</button>
      </div>
    </form>
  </div>
</div>

<script>
function showTab(tab, btn) {
  document.querySelectorAll('.settings-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.settings-tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('panel-' + tab).classList.add('active');
  btn.classList.add('active');
}

// Auto-open tab from hash
(function(){
  var hash = window.location.hash.replace('#', '');
  var map = {security:'security', notifications:'notifications', danger:'danger'};
  if (map[hash]) {
    var btn = document.querySelector('[onclick="showTab(\'' + map[hash] + '\', this)"]');
    if (btn) showTab(map[hash], btn);
  }
})();

// Close modal on overlay click
document.getElementById('delete-modal').addEventListener('click', function(e){
  if (e.target === this) this.classList.remove('open');
});
</script>
</body>
</html>
