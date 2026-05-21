<?php
require_once __DIR__ . '/../config/app.php';

if (current_user()) redirect('/scanner/');

$token  = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error  = '';
$errors = [];

/**
 * Validate a reset token and return the row, or null if invalid/expired/used.
 */
function fetch_reset_row(string $token): ?array
{
    if (!$token) return null;
    $stmt = db()->prepare(
        'SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW()'
    );
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

// GET — validate token upfront to show proper error immediately
$reset_row = null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!$token) {
        $error = 'This reset link is invalid or has expired.';
    } else {
        $reset_row = fetch_reset_row($token);
        if (!$reset_row) {
            $error = 'This reset link is invalid or has expired.';
        }
    }
}

// POST — process new password
if (is_post()) {
    $form = $_POST['form'] ?? '';

    if ($form === 'reset') {
        if (!verify_csrf($_POST['csrf'] ?? '')) {
            $errors[] = 'Invalid form submission.';
        } else {
            // Re-validate token
            $reset_row = fetch_reset_row($token);
            if (!$reset_row) {
                $error = 'This reset link is invalid or has expired.';
            } else {
                $password = $_POST['password'] ?? '';
                $confirm  = $_POST['confirm']  ?? '';

                if (strlen($password) < 8) {
                    $errors[] = 'Password must be at least 8 characters.';
                }
                if ($password !== $confirm) {
                    $errors[] = 'Passwords do not match.';
                }

                if (empty($errors)) {
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

                    db()->prepare('UPDATE users SET password_hash = ? WHERE email = ?')
                        ->execute([$hash, $reset_row['email']]);

                    db()->prepare('UPDATE password_resets SET used = 1 WHERE token = ?')
                        ->execute([$token]);

                    flash('success', 'Password updated. You can now sign in.');
                    redirect('/auth/login.php');
                }
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
<title>Reset Password — HakDel</title>
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
    <div class="auth-heading">Set New Password</div>
    <div class="auth-sub">Choose a strong password for your account.</div>

    <?php if ($error): ?>
    <div class="auth-errors">
      <div class="auth-error-item"><?= h($error) ?></div>
    </div>
    <div class="auth-switch">
      <a href="/auth/forgot_password.php">Request a new reset link</a>
    </div>

    <?php else: ?>

    <?php if ($errors): ?>
    <div class="auth-errors">
      <?php foreach ($errors as $e): ?>
      <div class="auth-error-item"><?= h($e) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="/auth/reset_password.php" class="auth-form" novalidate>
      <input type="hidden" name="csrf"  value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="form"  value="reset">
      <input type="hidden" name="token" value="<?= h($token) ?>">

      <div class="form-field">
        <label class="form-label">New Password</label>
        <input type="password" name="password" class="form-input"
               placeholder="Min. 8 characters" autocomplete="new-password" required>
      </div>

      <div class="form-field">
        <label class="form-label">Confirm Password</label>
        <input type="password" name="confirm" class="form-input"
               placeholder="Repeat new password" autocomplete="new-password" required>
      </div>

      <button type="submit" class="btn-auth">Set New Password</button>
    </form>

    <div class="auth-switch">
      <a href="/auth/login.php">&larr; Back to Login</a>
    </div>

    <?php endif; ?>
  </div>
</div>

</body>
</html>
