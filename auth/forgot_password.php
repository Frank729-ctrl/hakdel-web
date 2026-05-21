<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/mail_templates.php';

if (current_user()) redirect('/scanner/');

$sent   = false;
$errors = [];

if (is_post()) {
    $form = $_POST['form'] ?? '';

    if ($form === 'forgot') {
        if (!verify_csrf($_POST['csrf'] ?? '')) {
            $errors[] = 'Invalid form submission.';
        } else {
            $email = trim($_POST['email'] ?? '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }

            if (empty($errors)) {
                // Look up user — always show same success message to prevent enumeration
                $stmt = db()->prepare('SELECT id, username, email_verified FROM users WHERE email = ?');
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && (int)$user['email_verified'] === 1) {
                    // Delete any existing reset tokens for this email
                    db()->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$email]);

                    // Create new token
                    $token = bin2hex(random_bytes(32));
                    $exp   = date('Y-m-d H:i:s', strtotime('+1 hour'));

                    db()->prepare(
                        'INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)'
                    )->execute([$email, $token, $exp]);

                    // Build reset URL and send email
                    $reset_url = (defined('SITE_URL') ? SITE_URL : '') . '/auth/reset_password.php?token=' . $token;
                    $tpl = mail_template_reset($user['username'], $reset_url);
                    send_mail($email, $tpl['subject'], $tpl['text'], $tpl['html']);
                }

                // Always true to prevent enumeration
                $sent = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot Password — HakDel</title>
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
    <div class="auth-heading">Forgot Password</div>
    <div class="auth-sub">Enter your email to receive a reset link.</div>

    <?php if ($errors): ?>
    <div class="auth-errors">
      <?php foreach ($errors as $e): ?>
      <div class="auth-error-item"><?= h($e) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($sent): ?>
    <div class="auth-success">
      If that email is registered and verified, a reset link is on its way. Check your inbox.
    </div>
    <?php else: ?>
    <form method="POST" action="/auth/forgot_password.php" class="auth-form" novalidate>
      <input type="hidden" name="csrf"  value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="form"  value="forgot">

      <div class="form-field">
        <label class="form-label">Email Address</label>
        <input type="email" name="email" class="form-input"
               value="<?= h($_POST['email'] ?? '') ?>"
               placeholder="you@example.com" autocomplete="email" required>
      </div>

      <button type="submit" class="btn-auth">Send Reset Link</button>
    </form>
    <?php endif; ?>

    <div class="auth-switch">
      <a href="/auth/login.php">&larr; Back to Login</a>
    </div>
  </div>
</div>

</body>
</html>
