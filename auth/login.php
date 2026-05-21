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
            $recent = db()->prepare("SELECT COUNT(*) FROM email_verifications WHERE user_id = ? AND created_at > NOW() - INTERVAL '5 minutes'");
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
  <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign In — HakDel</title>
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
      <span><span style="color:var(--signal)">●</span> OPERATORS ONLINE</span>
      <span class="tnum">v2.7.0-build.4109</span>
    </div>
  </div>

  <!-- RIGHT: auth form -->
  <div class="auth-right">
    <div class="auth-top-link">NO ACCOUNT? <a href="/auth/register.php">REGISTER →</a></div>

    <div class="auth-form-wrap">
      <div class="auth-form-eyebrow">// AUTH HANDSHAKE</div>
      <h2 class="auth-form-h">Access terminal</h2>
      <p class="auth-form-sub">Sign in to continue your training. All sessions are encrypted and logged for audit.</p>

      <?php if ($error): ?>
      <div class="auth-errors" style="margin-bottom:16px">
        <div class="auth-error-item"><?= h($error) ?></div>
        <?php if (!empty($unverified_uid)): ?>
        <form method="POST" action="/auth/login.php" style="margin-top:10px">
          <input type="hidden" name="csrf"    value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="form"    value="resend_verify">
          <input type="hidden" name="user_id" value="<?= $unverified_uid ?>">
          <button type="submit" class="btn-auth-outline">Resend Verification Email</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php $success = get_flash('success'); if ($success): ?>
      <div class="auth-success" style="margin-bottom:16px"><?= h($success) ?></div>
      <?php endif; ?>

      <form method="POST" action="/auth/login.php" class="auth-form">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

        <div class="auth-field">
          <div class="auth-field-label">USERNAME OR EMAIL</div>
          <div class="auth-term">
            <span class="prompt">›</span>
            <input type="text" name="login" placeholder="username or email" autocomplete="username" required>
          </div>
        </div>

        <div class="auth-field">
          <div class="auth-field-label-row">
            <span class="auth-field-label">PASSWORD</span>
            <a href="/auth/forgot_password.php">FORGOT?</a>
          </div>
          <div class="auth-term">
            <span class="prompt">●</span>
            <input type="password" name="password" placeholder="••••••••••••" autocomplete="current-password" required>
          </div>
        </div>

        <button type="submit" class="btn-auth">AUTHENTICATE →</button>
      </form>

      <div class="auth-divider" style="margin:16px 0"><span>OR</span></div>

      <a href="<?= h(google_oauth_url()) ?>" class="btn-google">
        <svg width="16" height="16" viewBox="0 0 48 48" style="flex-shrink:0"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/><path fill="none" d="M0 0h48v48H0z"/></svg>
        CONTINUE WITH GOOGLE
      </a>

      <div class="auth-legal">
        <div class="auth-legal-label">// LEGAL</div>
        By accessing HakDel, you agree to use these tools only on infrastructure you own or are explicitly authorized to test. Unauthorized scanning violates the Computer Fraud and Abuse Act (18 U.S.C. § 1030).
      </div>
    </div>
  </div>

</div>

</body>
</html>
