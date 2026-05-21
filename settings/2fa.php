<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'settings';
$topbar_title = 'Two-Factor Authentication';

$uid = (int)$user['id'];
$pdo = db();

// Ensure tables exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_2fa (
        user_id INT UNSIGNED PRIMARY KEY,
        secret VARCHAR(32) NOT NULL,
        backup_codes JSON,
        enabled_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
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

$success = get_flash('success');
$error   = get_flash('error');
$new_backup_codes = get_flash('backup_codes');
$new_backup_codes = $new_backup_codes ? json_decode($new_backup_codes, true) : null;

if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        flash('error', 'Invalid form submission.');
        redirect('/settings/2fa.php');
    }
    $action = $_POST['action'] ?? '';

    // Start setup — generate and store secret in session
    if ($action === 'start_setup') {
        $_SESSION['_2fa_setup_secret'] = totp_generate_secret();
        redirect('/settings/2fa.php');
    }

    // Verify setup code and enable
    if ($action === 'verify_setup') {
        $secret = $_SESSION['_2fa_setup_secret'] ?? '';
        $code   = trim($_POST['code'] ?? '');
        if (!$secret) {
            flash('error', 'Session expired. Please start the setup again.');
            redirect('/settings/2fa.php');
        }
        if (!totp_verify($secret, $code)) {
            flash('error', 'Invalid code. Please check your authenticator app and try again.');
            redirect('/settings/2fa.php');
        }
        // Generate 8 backup codes
        $backup_codes = [];
        for ($i = 0; $i < 8; $i++) {
            $backup_codes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        try {
            $pdo->prepare('INSERT INTO user_2fa (user_id, secret, backup_codes) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE secret=VALUES(secret), backup_codes=VALUES(backup_codes), enabled_at=NOW()')
                ->execute([$uid, $secret, json_encode($backup_codes)]);
        } catch (Exception $e) {
            flash('error', 'Failed to enable 2FA: ' . $e->getMessage());
            redirect('/settings/2fa.php');
        }
        unset($_SESSION['_2fa_setup_secret']);
        flash('success', '2FA enabled successfully!');
        flash('backup_codes', json_encode($backup_codes));
        redirect('/settings/2fa.php');
    }

    // Disable 2FA
    if ($action === 'disable') {
        $current_pw = $_POST['current_password'] ?? '';
        $db_user = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $db_user->execute([$uid]);
        $db_user = $db_user->fetch();
        if (!$db_user || !password_verify($current_pw, $db_user['password_hash'])) {
            flash('error', 'Incorrect password. 2FA not disabled.');
            redirect('/settings/2fa.php');
        }
        try {
            $pdo->prepare('DELETE FROM user_2fa WHERE user_id = ?')->execute([$uid]);
        } catch (Exception $e) {}
        flash('success', '2FA has been disabled.');
        redirect('/settings/2fa.php');
    }

    redirect('/settings/2fa.php');
}

// Setup mode
$setup_secret = $_SESSION['_2fa_setup_secret'] ?? null;
$qr_url = '';
if ($setup_secret && !$has_2fa) {
    $otp_auth = 'otpauth://totp/HakDel:' . rawurlencode($user['email'] ?? $user['username'])
              . '?secret=' . $setup_secret . '&issuer=HakDel';
    $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($otp_auth);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>2FA Setup — HakDel</title>
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="stylesheet" href="/assets/layout.css">
  <style>
    .tfa-card {
      max-width: 560px;
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: var(--radius-lg); overflow: hidden;
    }
    .tfa-card-header {
      padding: 18px 24px; border-bottom: 1px solid var(--border);
    }
    .tfa-card-title { font-family: var(--mono); font-size: 15px; font-weight: 700; color: var(--text); }
    .tfa-card-sub { font-size: 12px; color: var(--text3); margin-top: 4px; }
    .tfa-card-body { padding: 24px; display: flex; flex-direction: column; gap: 20px; }
    .tfa-status-wrap {
      display: flex; align-items: center; gap: 14px;
      padding: 16px; background: var(--bg3);
      border: 1px solid var(--border); border-radius: var(--radius);
    }
    .tfa-status-icon { font-size: 28px; }
    .tfa-enabled-text { color: var(--accent); font-weight: 700; font-size: 14px; }
    .tfa-disabled-text { color: var(--text3); font-weight: 600; font-size: 14px; }
    .tfa-btn {
      display: inline-flex; align-items: center; gap: 6px;
      background: var(--accent); color: var(--bg); border: none;
      border-radius: var(--radius); padding: 10px 20px;
      font-family: var(--mono); font-size: 13px; font-weight: 700;
      cursor: pointer; transition: opacity 0.15s;
    }
    .tfa-btn:hover { opacity: 0.85; }
    .tfa-btn.secondary {
      background: transparent; color: var(--text2);
      border: 1px solid var(--border2);
    }
    .tfa-btn.danger-btn { background: var(--danger); color: #fff; }
    .tfa-input {
      background: var(--bg3); border: 1px solid var(--border2);
      border-radius: var(--radius); padding: 10px 14px;
      font-family: var(--mono); font-size: 20px; color: var(--text);
      letter-spacing: 6px; text-align: center; outline: none; width: 100%;
      transition: border-color 0.15s;
    }
    .tfa-input:focus { border-color: var(--accent); }
    .qr-wrap {
      display: flex; justify-content: center;
      padding: 16px; background: #fff; border-radius: var(--radius);
      width: fit-content; margin: 0 auto;
    }
    .setup-step {
      background: var(--bg3); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 14px 16px;
    }
    .setup-step-num {
      font-family: var(--mono); font-size: 10px; color: var(--accent);
      letter-spacing: 1px; text-transform: uppercase; margin-bottom: 6px;
    }
    .setup-step-text { font-size: 13px; color: var(--text2); line-height: 1.6; }
    .secret-display {
      font-family: var(--mono); font-size: 16px; font-weight: 700;
      color: var(--accent); letter-spacing: 3px; text-align: center;
      padding: 12px; background: var(--bg4); border-radius: var(--radius);
      border: 1px solid var(--border); word-break: break-all;
    }
    .backup-codes-grid {
      display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;
    }
    .backup-code {
      font-family: var(--mono); font-size: 13px; font-weight: 700;
      color: var(--text); text-align: center;
      padding: 8px 4px; background: var(--bg3);
      border: 1px solid var(--border); border-radius: var(--radius);
    }
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
      <div class="hk-page-eyebrow">SETTINGS / SECURITY</div>
      <h1 class="hk-page-title">Two-Factor Authentication</h1>
      <p class="hk-page-sub">Secure your account with a TOTP authenticator app</p>
    </div>
    <div class="hk-page-actions">
      <a href="/settings/" class="btn-secondary" style="text-decoration:none;padding:8px 16px;font-size:13px">
        &larr; Back to Settings
      </a>
    </div>
  </div>

  <?php if ($success): ?>
  <div class="flash-success" style="max-width:560px"><?php echo h($success); ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="flash-error" style="max-width:560px"><?php echo h($error); ?></div>
  <?php endif; ?>

  <?php if ($new_backup_codes): ?>
  <div class="tfa-card" style="max-width:560px">
    <div class="tfa-card-header">
      <div class="tfa-card-title">&#128270; Your Backup Codes</div>
      <div class="tfa-card-sub">Save these somewhere safe — each code can only be used once</div>
    </div>
    <div class="tfa-card-body">
      <div class="backup-codes-grid">
        <?php foreach ($new_backup_codes as $code): ?>
        <div class="backup-code"><?php echo h($code); ?></div>
        <?php endforeach; ?>
      </div>
      <div style="font-size:12px;color:var(--danger);line-height:1.6">
        &#9888; These codes will not be shown again. Store them securely (password manager, printed paper safe, etc).
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($has_2fa && !$new_backup_codes): ?>
  <!-- 2FA is enabled -->
  <div class="tfa-card">
    <div class="tfa-card-header">
      <div class="tfa-card-title">Two-Factor Authentication</div>
      <div class="tfa-card-sub">Your account is protected with 2FA</div>
    </div>
    <div class="tfa-card-body">
      <div class="tfa-status-wrap">
        <span class="tfa-status-icon" style="color:var(--accent)">&#128274;</span>
        <div>
          <div class="tfa-enabled-text">2FA Enabled</div>
          <div style="font-size:12px;color:var(--text3);margin-top:3px">
            Enabled on <?php echo date('F j, Y', strtotime($tfa_row['enabled_at'])); ?>
          </div>
        </div>
      </div>

      <div style="border-top:1px solid var(--border);padding-top:20px">
        <div style="font-family:var(--mono);font-size:12px;color:var(--text3);margin-bottom:12px;text-transform:uppercase;letter-spacing:1px">Disable 2FA</div>
        <div style="font-size:13px;color:var(--text2);margin-bottom:14px">
          Enter your current password to disable two-factor authentication.
        </div>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
          <input type="hidden" name="action" value="disable">
          <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            <div style="flex:1;min-width:200px">
              <label style="font-size:12px;color:var(--text2);display:block;margin-bottom:5px">Current Password</label>
              <input type="password" name="current_password" class="tfa-input"
                     style="font-size:14px;letter-spacing:1px;text-align:left" required autocomplete="current-password">
            </div>
            <button type="submit" class="tfa-btn danger-btn" onclick="return confirm('Disable 2FA? Your account will be less secure.')">
              Disable 2FA
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php elseif (!$has_2fa && !$setup_secret): ?>
  <!-- Not enabled, not in setup -->
  <div class="tfa-card">
    <div class="tfa-card-header">
      <div class="tfa-card-title">Enable Two-Factor Authentication</div>
      <div class="tfa-card-sub">Use an authenticator app like Google Authenticator, Authy, or 1Password</div>
    </div>
    <div class="tfa-card-body">
      <div class="tfa-status-wrap">
        <span class="tfa-status-icon" style="color:var(--text3)">&#128275;</span>
        <div>
          <div class="tfa-disabled-text">2FA Not Enabled</div>
          <div style="font-size:12px;color:var(--text3);margin-top:3px">
            Protect your account from unauthorized access
          </div>
        </div>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="action" value="start_setup">
        <button type="submit" class="tfa-btn">Begin Setup</button>
      </form>
    </div>
  </div>

  <?php elseif (!$has_2fa && $setup_secret): ?>
  <!-- In setup flow -->
  <div class="tfa-card">
    <div class="tfa-card-header">
      <div class="tfa-card-title">Set Up Authenticator App</div>
      <div class="tfa-card-sub">Follow the steps below to configure your authenticator</div>
    </div>
    <div class="tfa-card-body">

      <div class="setup-step">
        <div class="setup-step-num">Step 1 — Scan QR Code</div>
        <div class="setup-step-text">Open your authenticator app and scan this QR code:</div>
        <div style="display:flex;justify-content:center;margin-top:14px">
          <div class="qr-wrap">
            <img src="<?php echo h($qr_url); ?>" alt="QR Code" width="200" height="200">
          </div>
        </div>
      </div>

      <div class="setup-step">
        <div class="setup-step-num">Step 2 — Manual Entry</div>
        <div class="setup-step-text">Can't scan? Enter this secret key manually:</div>
        <div class="secret-display" style="margin-top:10px">
          <?php echo h(implode(' ', str_split($setup_secret, 4))); ?>
        </div>
        <div style="font-size:11px;color:var(--text3);margin-top:6px;text-align:center">
          Account: HakDel &nbsp;|&nbsp; Key: <?php echo h($setup_secret); ?> &nbsp;|&nbsp; TOTP &nbsp;|&nbsp; SHA1 &nbsp;|&nbsp; 6 digits &nbsp;|&nbsp; 30s
        </div>
      </div>

      <div class="setup-step">
        <div class="setup-step-num">Step 3 — Verify Code</div>
        <div class="setup-step-text">Enter the 6-digit code from your authenticator app to confirm setup:</div>
        <form method="POST" style="margin-top:14px">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
          <input type="hidden" name="action" value="verify_setup">
          <input type="text" name="code" class="tfa-input" placeholder="000000"
                 maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                 autocomplete="one-time-code" required>
          <div style="display:flex;gap:10px;margin-top:14px">
            <button type="submit" class="tfa-btn">Verify &amp; Enable 2FA</button>
            <a href="/settings/2fa.php" class="tfa-btn secondary" style="text-decoration:none">Cancel</a>
          </div>
        </form>
      </div>

    </div>
  </div>
  <?php endif; ?>

</main>
</div>
</body>
</html>
