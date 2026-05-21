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
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Register — HakDel</title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/auth.css">
</head>
<body class="auth-page">

<div class="auth-split">

  <!-- LEFT: hero copy -->
  <div class="auth-left">
    <div class="auth-left-bg" aria-hidden="true"></div>
    <div class="auth-left-scan" aria-hidden="true"></div>

    <div class="auth-mark">HAK<span class="accent">DEL</span><span class="dot"></span></div>

    <div class="auth-hero">
      <div class="auth-hero-eyebrow">// OPERATOR BRIEFING · v2.7</div>
      <h2 class="auth-hero-h">Train like an<br>attacker.<br><span class="accent">Defend like an analyst.</span></h2>
      <p class="auth-hero-p">Containerized exploitation labs, live network scanners, and a CEH-aligned drill bank. Built for SOC analysts, pentesters, and learners studying for certification.</p>
      <div class="auth-features">
        <div class="auth-feature"><div class="auth-feature-icon">⌬</div><div><div class="auth-feature-title">Live exploitation labs</div><div class="auth-feature-desc">Fresh isolated containers per session. Real CVEs, real shells.</div></div></div>
        <div class="auth-feature"><div class="auth-feature-icon">⌖</div><div><div class="auth-feature-title">Network scanner suite</div><div class="auth-feature-desc">Port, header, TLS, DNS, subdomain — AI-synthesized findings.</div></div></div>
        <div class="auth-feature"><div class="auth-feature-icon">⚑</div><div><div class="auth-feature-title">CEH v13 quiz bank</div><div class="auth-feature-desc">1,065 questions across all 10 exam domains, with explanations.</div></div></div>
        <div class="auth-feature"><div class="auth-feature-icon">◢</div><div><div class="auth-feature-title">Threat intel feed</div><div class="auth-feature-desc">Watchlist domains, daily CVE digest, custom alerting.</div></div></div>
      </div>
    </div>

    <div class="auth-left-foot">
      <span style="color:var(--signal)">⚡ 30-DAY PRO TRIAL — NO CARD REQUIRED</span>
      <span class="tnum">v2.7.0-build.4109</span>
    </div>
  </div>

  <!-- RIGHT: register form -->
  <div class="auth-right">
    <div class="auth-top-link">HAVE AN ACCOUNT? <a href="/auth/login.php">SIGN IN →</a></div>

    <div class="auth-form-wrap">
      <div class="auth-form-eyebrow">// NEW OPERATOR</div>
      <h2 class="auth-form-h">Provision account</h2>
      <p class="auth-form-sub">Set up your operator profile. We'll only ask for what we need to scope your training.</p>

      <?php if ($errors): ?>
      <div class="auth-errors" style="margin-bottom:16px">
        <?php foreach ($errors as $e): ?>
        <div class="auth-error-item"><?= h($e) ?></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <a href="<?= h(google_oauth_url()) ?>" class="btn-google" style="margin-bottom:14px">
        <svg width="16" height="16" viewBox="0 0 48 48" style="flex-shrink:0"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/><path fill="none" d="M0 0h48v48H0z"/></svg>
        SIGN UP WITH GOOGLE
      </a>

      <div class="auth-divider"><span>OR</span></div>

      <form method="POST" action="/auth/register.php" class="auth-form" novalidate style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

        <div class="auth-field">
          <div class="auth-field-label">HANDLE</div>
          <div class="auth-term">
            <span class="prompt">@</span>
            <input type="text" name="username" value="<?= h($values['username']) ?>"
                   placeholder="r00tk1t" autocomplete="username" required>
          </div>
        </div>

        <div class="auth-field">
          <div class="auth-field-label">EMAIL</div>
          <div class="auth-term">
            <span class="prompt">›</span>
            <input type="email" name="email" value="<?= h($values['email']) ?>"
                   placeholder="operator@domain.test" autocomplete="email" required>
          </div>
        </div>

        <div class="auth-field">
          <div class="auth-field-label">PASSWORD</div>
          <div class="auth-term">
            <span class="prompt">●</span>
            <input type="password" name="password" placeholder="Min. 8 characters" autocomplete="new-password" required>
          </div>
        </div>

        <div class="auth-field">
          <div class="auth-field-label">CONFIRM PASSWORD</div>
          <div class="auth-term">
            <span class="prompt">●</span>
            <input type="password" name="confirm" placeholder="Repeat password" autocomplete="new-password" required>
          </div>
        </div>

        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:12px;color:var(--ink-dim);line-height:1.5">
          <input type="checkbox" name="agree_terms" required style="margin-top:2px;flex-shrink:0;accent-color:var(--signal)">
          I agree to the <a href="/legal/terms.php" target="_blank" style="color:var(--signal)">Terms of Service</a>
          and <a href="/legal/privacy.php" target="_blank" style="color:var(--signal)">Privacy Policy</a>
        </label>

        <button type="submit" class="btn-auth">PROVISION ACCOUNT →</button>
      </form>

      <div class="auth-legal">
        <div class="auth-legal-label">// LEGAL</div>
        By accessing HakDel, you agree to use these tools only on infrastructure you own or are explicitly authorized to test. Unauthorized scanning violates the Computer Fraud and Abuse Act (18 U.S.C. § 1030).
      </div>
    </div>
  </div>

</div>

</body>
</html>
