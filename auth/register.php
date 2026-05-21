<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/mail_templates.php';

// Redirect if already logged in
if (current_user()) redirect('/scanner/');

$errors = [];
$values = ['username' => '', 'email' => ''];

if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid form submission.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']      ?? '';
        $confirm  = $_POST['confirm']       ?? '';

        $values = ['username' => $username, 'email' => $email];

        // Validation
        if (strlen($username) < 3 || strlen($username) > 40)
            $errors[] = 'Username must be 3–40 characters.';
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username))
            $errors[] = 'Username can only contain letters, numbers, underscores, hyphens.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            $errors[] = 'Invalid email address.';
        if (strlen($password) < 8)
            $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $confirm)
            $errors[] = 'Passwords do not match.';
        if (empty($_POST['agree_terms']))
            $errors[] = 'You must agree to the Terms of Service and Privacy Policy.';

        // Uniqueness check
        if (empty($errors)) {
            $check = db()->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
            $check->execute([$username, $email]);
            if ($check->fetch()) $errors[] = 'Username or email already taken.';
        }

        if (empty($errors)) {
            _ensure_plan_columns();
            $initials    = strtoupper(substr($username, 0, 2));
            $hash        = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $trial_ends  = date('Y-m-d H:i:s', strtotime('+30 days'));
            $stmt = db()->prepare(
                'INSERT INTO users (username, email, password_hash, avatar_initials, last_active, plan, plan_expires_at)
                 VALUES (?, ?, ?, ?, CURDATE(), ?, ?)'
            );
            $stmt->execute([$username, $email, $hash, $initials, 'pro', $trial_ends]);
            $user_id = (int)db()->lastInsertId();

            // Create email verification token
            $token = bin2hex(random_bytes(32));
            $exp   = date('Y-m-d H:i:s', strtotime('+24 hours'));
            db()->prepare(
                'INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)'
            )->execute([$user_id, $token, $exp]);

            // Send welcome / verification email
            $verify_url = (defined('SITE_URL') ? SITE_URL : '') . '/auth/verify_email.php?token=' . $token;
            $tpl = mail_template_welcome($username, $verify_url);
            send_mail($email, $tpl['subject'], $tpl['text'], $tpl['html']);

            flash('success', 'Account created! Check your email to verify your account.');
            redirect('/auth/login.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Register — HakDel</title>
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
    <div class="auth-heading">Create account</div>
    <div class="auth-sub">Start your ethical hacking journey.</div>
    <div style="background:rgba(0,212,170,0.07);border:1px solid rgba(0,212,170,0.2);border-radius:var(--radius);padding:9px 14px;font-size:12px;color:var(--accent);font-family:var(--mono);text-align:center;letter-spacing:0.3px">
      &#9651; 30-day Pro trial — no card required
    </div>

    <?php if ($errors): ?>
    <div class="auth-errors">
      <?php foreach ($errors as $e): ?>
      <div class="auth-error-item"><?= h($e) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <a href="<?= h(google_oauth_url()) ?>" class="btn-google">
      <svg width="18" height="18" viewBox="0 0 48 48" style="flex-shrink:0"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/><path fill="none" d="M0 0h48v48H0z"/></svg>
      Sign up with Google
    </a>

    <div class="auth-divider"><span>or</span></div>

    <form method="POST" action="/auth/register.php" class="auth-form" novalidate>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

      <div class="form-field">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-input"
               value="<?= h($values['username']) ?>"
               placeholder="e.g. frankd_sec" autocomplete="username" required>
      </div>

      <div class="form-field">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-input"
               value="<?= h($values['email']) ?>"
               placeholder="you@example.com" autocomplete="email" required>
      </div>

      <div class="form-field">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-input"
               placeholder="Min. 8 characters" autocomplete="new-password" required>
      </div>

      <div class="form-field">
        <label class="form-label">Confirm Password</label>
        <input type="password" name="confirm" class="form-input"
               placeholder="Repeat password" autocomplete="new-password" required>
      </div>

      <div class="form-field">
        <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:12px;color:var(--text3);line-height:1.5">
          <input type="checkbox" name="agree_terms" required style="margin-top:2px;flex-shrink:0">
          I agree to the <a href="/legal/terms.php" target="_blank" style="color:var(--accent)">Terms of Service</a>
          and <a href="/legal/privacy.php" target="_blank" style="color:var(--accent)">Privacy Policy</a>
        </label>
      </div>

      <button type="submit" class="btn-auth">Create Account &mdash; Free Trial</button>
    </form>

    <div class="auth-switch">
      Already have an account? <a href="/auth/login.php">Sign in</a>
    </div>
  </div>

  <div style="text-align:center;margin-top:20px;font-size:11px;color:var(--text3)">
    <a href="/legal/terms.php" style="color:var(--text3);text-decoration:none;margin:0 8px">Terms</a>
    <a href="/legal/privacy.php" style="color:var(--text3);text-decoration:none;margin:0 8px">Privacy</a>
  </div>
</div>

</body>
</html>
