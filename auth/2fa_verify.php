<?php
require_once __DIR__ . '/../config/app.php';

// Must have a pending 2FA user in session
$pending_uid = (int)($_SESSION['_2fa_pending_user_id'] ?? 0);
if (!$pending_uid) {
    redirect('/auth/login.php');
}

$error = get_flash('error') ?? '';
$show_backup = isset($_GET['backup']) || !empty($error);

if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        flash('error', 'Invalid form submission.');
        redirect('/auth/2fa_verify.php');
    }

    $code = trim($_POST['code'] ?? '');
    $is_backup = (bool)($_POST['use_backup'] ?? false);

    if (!$code) {
        flash('error', 'Please enter a code.');
        redirect('/auth/2fa_verify.php');
    }

    $pdo = db();

    // Load secret
    $s = $pdo->prepare('SELECT * FROM user_2fa WHERE user_id = ?');
    $s->execute([$pending_uid]);
    $tfa = $s->fetch();

    if (!$tfa) {
        // 2FA record gone — allow login
        unset($_SESSION['_2fa_pending_user_id']);
        login_user($pending_uid);
        redirect('/dashboard/');
    }

    $verified = false;

    if ($is_backup) {
        // Check backup codes
        $backup_codes = json_decode($tfa['backup_codes'] ?? '[]', true);
        $upper_code = strtoupper($code);
        $key = array_search($upper_code, $backup_codes);
        if ($key !== false) {
            // Remove used backup code
            array_splice($backup_codes, $key, 1);
            $pdo->prepare('UPDATE user_2fa SET backup_codes = ? WHERE user_id = ?')
                ->execute([json_encode($backup_codes), $pending_uid]);
            $verified = true;
        }
    } else {
        $verified = totp_verify($tfa['secret'], $code);
    }

    if ($verified) {
        unset($_SESSION['_2fa_pending_user_id']);
        login_user($pending_uid);
        redirect('/dashboard/');
    } else {
        flash('error', $is_backup ? 'Invalid backup code.' : 'Invalid code. Please try again.');
        redirect('/auth/2fa_verify.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Two-Factor Authentication — HakDel</title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/auth.css">
</head>
<body class="auth-page">

<div class="auth-shell">
  <div class="auth-brand">
    <span class="logo-dot"></span>
    <span class="logo-text">HAK<span class="logo-accent">DEL</span></span>
  </div>

  <div class="auth-card">
    <div class="auth-heading" style="display:flex;align-items:center;gap:10px;justify-content:center">
      &#128274; Verification
    </div>
    <div class="auth-sub">Enter the code from your authenticator app.</div>

    <?php if ($error): ?>
    <div class="auth-errors">
      <div class="auth-error-item"><?php echo h($error); ?></div>
    </div>
    <?php endif; ?>

    <?php if (!$show_backup): ?>
    <!-- TOTP form -->
    <form method="POST" action="/auth/2fa_verify.php" class="auth-form" id="totp-form">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="use_backup" value="0">
      <div class="form-field">
        <label class="form-label">Authenticator Code</label>
        <input type="text" name="code" class="form-input"
               placeholder="000000" maxlength="6" pattern="[0-9]{6}"
               inputmode="numeric" autocomplete="one-time-code"
               autofocus required
               style="font-family:var(--mono);font-size:22px;letter-spacing:8px;text-align:center">
      </div>
      <button type="submit" class="btn-auth">Verify</button>
    </form>

    <div style="text-align:center;margin-top:12px">
      <a href="/auth/2fa_verify.php?backup=1" style="font-size:12px;color:var(--text3);text-decoration:none">
        Use a backup code instead
      </a>
    </div>

    <?php else: ?>
    <!-- Backup code form -->
    <form method="POST" action="/auth/2fa_verify.php" class="auth-form" id="backup-form">
      <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="use_backup" value="1">
      <div class="form-field">
        <label class="form-label">Backup Code</label>
        <input type="text" name="code" class="form-input"
               placeholder="XXXXXXXX" maxlength="8"
               autocomplete="off"
               autofocus required
               style="font-family:var(--mono);font-size:18px;letter-spacing:4px;text-align:center;text-transform:uppercase">
      </div>
      <button type="submit" class="btn-auth">Use Backup Code</button>
    </form>

    <div style="text-align:center;margin-top:12px">
      <a href="/auth/2fa_verify.php" style="font-size:12px;color:var(--text3);text-decoration:none">
        Use authenticator app instead
      </a>
    </div>
    <?php endif; ?>

    <div style="text-align:center;margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
      <a href="/auth/login.php" style="font-size:12px;color:var(--text3);text-decoration:none">
        &larr; Back to login
      </a>
    </div>
  </div>
</div>

</body>
</html>
