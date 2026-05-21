<?php
require_once __DIR__ . '/../config/xp_service.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/mail_templates.php';

if (current_user()) redirect('/scanner/');

$error          = get_flash('error') ?? '';
$unverified_uid = 0;

if (is_post()) {
    $form = $_POST['form'] ?? '';

    // ── Resend verification email ─────────────────────────────────────────────
    if ($form === 'resend_verify' && verify_csrf($_POST['csrf'] ?? '')) {
        $uid = (int)($_POST['user_id'] ?? 0);
        $user_row = null;
        if ($uid) {
            $s = db()->prepare('SELECT id, email, username, email_verified FROM users WHERE id = ?');
            $s->execute([$uid]);
            $user_row = $s->fetch();
        }
        if ($user_row && !$user_row['email_verified']) {
            // Rate limit: only allow resend if no valid token created in last 5 minutes
            $recent = db()->prepare('SELECT COUNT(*) FROM email_verifications WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)');
            $recent->execute([$uid]);
            if (!$recent->fetchColumn()) {
                db()->prepare('DELETE FROM email_verifications WHERE user_id = ?')->execute([$uid]);
                $token = bin2hex(random_bytes(32));
                $exp   = date('Y-m-d H:i:s', strtotime('+24 hours'));
                db()->prepare('INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)')->execute([$uid, $token, $exp]);
                $verify_url = (defined('SITE_URL') ? SITE_URL : '') . '/auth/verify_email.php?token=' . $token;
                $tpl = mail_template_welcome($user_row['username'], $verify_url);
                send_mail($user_row['email'], $tpl['subject'], $tpl['text'], $tpl['html']);
            }
        }
        $error = 'Verification email sent! Check your inbox (also spam folder).';

    // ── Normal login ──────────────────────────────────────────────────────────
    } elseif (!verify_csrf($_POST['csrf'] ?? '')) {
        $error = 'Invalid form submission.';
    } else {
        $login    = trim($_POST['login']    ?? '');
        $password = $_POST['password']      ?? '';

        if ($login && $password) {
            // Allow login by username OR email
            $stmt = db()->prepare(
                'SELECT * FROM users WHERE username = ? OR email = ?'
            );
            $stmt->execute([$login, $login]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Check email verification before allowing login
                if (!(int)$user['email_verified']) {
                    $error          = 'Please verify your email before signing in.';
                    $unverified_uid = (int)$user['id'];
                } else {
                    // Check if 2FA enabled
                    $tfa = db()->prepare('SELECT secret FROM user_2fa WHERE user_id = ?');
                    $tfa->execute([$user['id']]);
                    if ($tfa->fetch()) {
                        $_SESSION['_2fa_pending_user_id'] = $user['id'];
                        redirect('/auth/2fa_verify.php');
                    }
                    login_user((int)$user['id']);

                    // ── Update streak and award streak XP ────────────────────────
                    $today  = date('Y-m-d');
                    $last   = $user['last_active'];
                    $streak = (int)$user['streak_days'];

                    $is_new_day = ($last !== $today);

                    if ($last === date('Y-m-d', strtotime('-1 day'))) {
                        $streak++;
                    } elseif ($last !== $today) {
                        $streak = 1;
                    }

                    // Update longest_streak
                    $longest = max((int)($user['longest_streak'] ?? 0), $streak);

                    db()->prepare('UPDATE users SET last_active = ?, streak_days = ?, longest_streak = ? WHERE id = ?')
                        ->execute([$today, $streak, $longest, $user['id']]);

                    // Award streak XP only once per day
                    if ($is_new_day) {
                        $streak_result = award_streak_xp((int)$user['id'], $streak);
                        if ($streak_result['xp_awarded'] > 0) {
                            $_SESSION['pending_xp_notify'] = [
                                'messages'         => $streak_result['messages'],
                                'total_xp_awarded' => $streak_result['xp_awarded'] + ($streak_result['level_bonus'] ?? 0),
                                'leveled_up'       => $streak_result['leveled_up'] ?? false,
                                'new_level'        => $streak_result['new_level']  ?? null,
                                'current_xp'       => $streak_result['total_xp']  ?? null,
                            ];
                        }
                    }

                    check_and_award_badges((int)$user['id']);
                    flash('success', 'Welcome back, ' . $user['username'] . '!');
                    redirect('/scanner/');
                }
            } else {
                $error = 'Incorrect username/email or password.';
            }
        } else {
            $error = 'Please fill in all fields.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign In — HakDel</title>
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
    <div class="auth-heading">Sign in</div>
    <div class="auth-sub">Continue your hacking journey.</div>

    <?php if ($error): ?>
    <div class="auth-errors">
      <div class="auth-error-item"><?= h($error) ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($unverified_uid)): ?>
    <form method="POST" action="/auth/login.php" class="auth-form" style="margin-top:-8px">
      <input type="hidden" name="csrf"    value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="form"    value="resend_verify">
      <input type="hidden" name="user_id" value="<?= $unverified_uid ?>">
      <button type="submit" class="btn-auth" style="background:transparent;border:1px solid var(--accent);color:var(--accent)">
        Resend Verification Email
      </button>
    </form>
    <?php endif; ?>

    <?php $success = get_flash('success'); if ($success): ?>
    <div class="auth-success"><?= h($success) ?></div>
    <?php endif; ?>

    <a href="<?= h(google_oauth_url()) ?>" class="btn-google">
      <svg width="18" height="18" viewBox="0 0 48 48" style="flex-shrink:0"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/><path fill="none" d="M0 0h48v48H0z"/></svg>
      Continue with Google
    </a>

    <div class="auth-divider"><span>or</span></div>

    <form method="POST" action="/auth/login.php" class="auth-form">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

      <div class="form-field">
        <label class="form-label">Username or Email</label>
        <input type="text" name="login" class="form-input"
               placeholder="username or email" autocomplete="username" required>
      </div>

      <div class="form-field">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-input"
               placeholder="Your password" autocomplete="current-password" required>
      </div>

      <button type="submit" class="btn-auth">Sign In</button>
    </form>

    <div style="text-align:center;margin-top:-4px">
      <a href="/auth/forgot_password.php" style="font-size:12px;color:var(--text3);text-decoration:none">Forgot password?</a>
    </div>

    <div class="auth-switch">
      New here? <a href="/auth/register.php">Create an account</a>
    </div>
  </div>

  <div style="text-align:center;margin-top:20px;font-size:11px;color:var(--text3)">
    <a href="/legal/terms.php" style="color:var(--text3);text-decoration:none;margin:0 8px">Terms</a>
    <a href="/legal/privacy.php" style="color:var(--text3);text-decoration:none;margin:0 8px">Privacy</a>
  </div>
</div>

</body>
</html>
