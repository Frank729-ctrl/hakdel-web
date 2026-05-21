<?php
require_once __DIR__ . '/../config/xp_service.php';

if (current_user()) redirect('/dashboard/');

$client_id     = getenv('GOOGLE_CLIENT_ID')     ?: '';
$client_secret = getenv('GOOGLE_CLIENT_SECRET') ?: '';
$redirect_uri  = getenv('GOOGLE_REDIRECT_URI')  ?: '';

// ── Validate state to prevent CSRF ───────────────────────────────────────────
$state = $_GET['state'] ?? '';
if (!$state || !isset($_SESSION['google_oauth_state']) || !hash_equals($_SESSION['google_oauth_state'], $state)) {
    unset($_SESSION['google_oauth_state']);
    flash('error', 'Invalid OAuth state. Please try again.');
    redirect('/auth/login.php');
}
unset($_SESSION['google_oauth_state']);

$code = $_GET['code'] ?? '';
if (!$code) {
    flash('error', 'Google sign-in was cancelled.');
    redirect('/auth/login.php');
}

// ── Exchange code for access token ───────────────────────────────────────────
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri'  => $redirect_uri,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT    => 10,
]);
$token_res = json_decode(curl_exec($ch), true);
curl_close($ch);

$access_token = $token_res['access_token'] ?? '';
if (!$access_token) {
    flash('error', 'Failed to get access token from Google.');
    redirect('/auth/login.php');
}

// ── Fetch Google user profile ─────────────────────────────────────────────────
$ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$access_token}"],
    CURLOPT_TIMEOUT        => 10,
]);
$profile = json_decode(curl_exec($ch), true);
curl_close($ch);

$google_id = $profile['id']             ?? '';
$email     = strtolower(trim($profile['email']   ?? ''));
$name      = $profile['name']           ?? '';
$picture   = $profile['picture']        ?? '';

if (!$google_id || !$email) {
    flash('error', 'Could not retrieve profile from Google.');
    redirect('/auth/login.php');
}

$pdo = db();

// ── Add google_id column if it doesn't exist ─────────────────────────────────
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN google_id VARCHAR(64) NULL UNIQUE AFTER email");
} catch (Exception $e) { /* already exists */ }

// ── Find or create user ───────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT * FROM users WHERE google_id = ? OR email = ?');
$stmt->execute([$google_id, $email]);
$user = $stmt->fetch();

if ($user) {
    // Link google_id if they registered normally before
    if (!$user['google_id']) {
        $pdo->prepare('UPDATE users SET google_id = ? WHERE id = ?')
            ->execute([$google_id, $user['id']]);
    }
    // Ensure email is verified for Google users
    if (!$user['email_verified']) {
        $pdo->prepare('UPDATE users SET email_verified = 1 WHERE id = ?')
            ->execute([$user['id']]);
    }
} else {
    // New user — create account from Google profile
    $username  = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower(explode('@', $email)[0]));
    $username  = substr($username, 0, 30);

    // Ensure username is unique
    $base = $username;
    $i    = 1;
    while (true) {
        $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute([$username]);
        if (!$check->fetch()) break;
        $username = $base . $i++;
    }

    _ensure_plan_columns();
    $initials   = strtoupper(substr($name ?: $username, 0, 2));
    $trial_ends = date('Y-m-d H:i:s', strtotime('+30 days'));
    $pdo->prepare(
        'INSERT INTO users (username, email, password_hash, avatar_initials, google_id, email_verified, last_active, plan, plan_expires_at)
         VALUES (?, ?, ?, ?, ?, 1, CURDATE(), ?, ?)'
    )->execute([$username, $email, '', $initials, $google_id, 'pro', $trial_ends]);

    $user_id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
}

// ── Log in ────────────────────────────────────────────────────────────────────
login_user((int)$user['id']);

// Update streak
$today  = date('Y-m-d');
$last   = $user['last_active'] ?? null;
$streak = (int)($user['streak_days'] ?? 0);
$is_new_day = ($last !== $today);
if ($last === date('Y-m-d', strtotime('-1 day'))) {
    $streak++;
} elseif ($last !== $today) {
    $streak = 1;
}
$longest = max((int)($user['longest_streak'] ?? 0), $streak);
$pdo->prepare('UPDATE users SET last_active = ?, streak_days = ?, longest_streak = ? WHERE id = ?')
    ->execute([$today, $streak, $longest, $user['id']]);

if ($is_new_day) {
    $streak_result = award_streak_xp((int)$user['id'], $streak);
    if (!empty($streak_result['xp_awarded'])) {
        $_SESSION['pending_xp_notify'] = [
            'messages'         => $streak_result['messages'],
            'total_xp_awarded' => $streak_result['xp_awarded'] + ($streak_result['level_bonus'] ?? 0),
            'leveled_up'       => $streak_result['leveled_up'] ?? false,
            'new_level'        => $streak_result['new_level']  ?? null,
            'current_xp'       => $streak_result['total_xp']  ?? null,
        ];
    }
}

flash('success', 'Welcome, ' . $user['username'] . '!');
redirect('/dashboard/');
