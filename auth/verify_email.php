<?php
require_once __DIR__ . '/../config/app.php';

$token = trim($_GET['token'] ?? '');
$error = '';
$info  = '';

if (!$token) {
    $error = 'Invalid or missing verification token.';
} else {
    $stmt = db()->prepare('
        SELECT ev.user_id, ev.expires_at, u.email_verified, u.username
        FROM email_verifications ev
        JOIN users u ON ev.user_id = u.id
        WHERE ev.token = ?
    ');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        $error = 'This verification link is invalid or has already been used.';
    } elseif ($row['expires_at'] < date('Y-m-d H:i:s')) {
        $error = 'This verification link has expired. Please request a new one by logging in.';
        // Clean up expired token
        db()->prepare('DELETE FROM email_verifications WHERE token = ?')->execute([$token]);
    } elseif ($row['email_verified']) {
        db()->prepare('DELETE FROM email_verifications WHERE token = ?')->execute([$token]);
        login_user((int)$row['user_id']);
        flash('success', 'Email already verified — you are now logged in.');
        redirect('/scanner/');
    } else {
        db()->prepare('UPDATE users SET email_verified = 1 WHERE id = ?')->execute([$row['user_id']]);
        db()->prepare('DELETE FROM email_verifications WHERE token = ?')->execute([$token]);
        login_user((int)$row['user_id']);
        flash('success', 'Email verified! Welcome to HakDel, ' . $row['username'] . '.');
        redirect('/scanner/');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify Email — HakDel</title>
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
    <div class="auth-heading">Email Verification</div>
    <div class="auth-sub">Verifying your account...</div>

    <?php if ($error): ?>
    <div class="auth-errors">
      <div class="auth-error-item"><?= h($error) ?></div>
    </div>

    <a href="/auth/login.php" class="btn-auth" style="display:block;text-align:center;text-decoration:none;margin-top:4px;">
      &larr; Back to Login
    </a>
    <?php endif; ?>
  </div>
</div>

</body>
</html>
