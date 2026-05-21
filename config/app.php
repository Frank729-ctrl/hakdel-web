<?php
// config.php — include this at the top of every PHP page
// Local development settings

// ── Load .env file if env vars aren't already set (dev / PHP built-in server) ─
(function () {
    $env_file = __DIR__ . '/../../.env';
    if (!file_exists($env_file)) return;
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key]    = $val;
            $_SERVER[$key] = $val;
        }
    }
})();

define('DB_HOST',   getenv('DB_HOST')   ?: 'localhost');
define('DB_NAME',   getenv('DB_NAME')   ?: 'hakdel');
define('DB_USER',   getenv('DB_USER')   ?: 'root');
define('DB_PASS',   getenv('DB_PASS')   ?: 'Shequan123!');           // XAMPP default is empty password
define('DB_PORT',   (int)(getenv('DB_PORT')   ?: 3306));

define('API_BASE',  getenv('API_BASE')  ?: 'http://localhost:8000');   // FastAPI scanner
define('SITE_URL',  getenv('SITE_URL')  ?: 'http://localhost:8080');   // PHP frontend
define('APP_NAME', 'HakDel');

// XP thresholds per level — exponential curve: 100*(N-1)^1.8, rounded to nearest 50
// Index 0 = Level 1 = 0 XP. Max level is 15.
define('XP_LEVELS', [0, 100, 280, 520, 820, 1200, 1650, 2150, 2750, 3400, 4150, 4950, 5850, 6800, 7850]);

// ─── Database connection ───────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                DB_HOST, DB_PORT, DB_NAME);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// ─── Auth helpers ──────────────────────────────────────────────────────────
session_start();

function current_user(): ?array {
    if (!isset($_SESSION['token'])) return null;
    $token = $_SESSION['token'];
    $stmt = db()->prepare(
        'SELECT u.* FROM users u
         JOIN sessions s ON s.user_id = u.id
         WHERE s.token = ? AND s.expires_at > NOW()'
    );
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        header('Location: /auth/login.php');
        exit;
    }
    return $user;
}

function login_user(int $user_id): string {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    db()->prepare('INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, ?)')
        ->execute([$token, $user_id, $expires]);
    $_SESSION['token'] = $token;
    return $token;
}

function logout_user(): void {
    if (isset($_SESSION['token'])) {
        db()->prepare('DELETE FROM sessions WHERE token = ?')
            ->execute([$_SESSION['token']]);
    }
    session_destroy();
}

// ─── XP helpers ───────────────────────────────────────────────────────────
// award_xp() has moved to frontend/config/xp_service.php
// Require xp_service.php in any file that needs to award XP.

function xp_to_level(int $xp): int {
    $thresholds = XP_LEVELS;
    $level = 1;
    foreach ($thresholds as $i => $threshold) {
        if ($xp >= $threshold) $level = $i + 1;
    }
    return min($level, 15); // Hard cap at level 15
}

function xp_progress(int $xp): array {
    $thresholds = XP_LEVELS;
    $level = xp_to_level($xp);
    $current_threshold = $thresholds[$level - 1] ?? 0;
    $next_threshold    = $thresholds[$level]     ?? $thresholds[count($thresholds) - 1];
    $progress_xp  = $xp - $current_threshold;
    $needed_xp    = $next_threshold - $current_threshold;
    $pct = $needed_xp > 0 ? round(($progress_xp / $needed_xp) * 100) : 100;
    return [
        'level'    => $level,
        'xp'       => $xp,
        'progress' => $pct,
        'current'  => $current_threshold,
        'next'     => $next_threshold,
    ];
}

// ─── Badge helpers ─────────────────────────────────────────────────────────
function check_and_award_badges(int $user_id): array {
    $pdo  = db();
    $user = $pdo->prepare('SELECT xp, level FROM users WHERE id = ?');
    $user->execute([$user_id]);
    $u = $user->fetch();

    $scans  = $pdo->prepare('SELECT COUNT(*) FROM scans WHERE user_id = ? AND status = "done"');
    $scans->execute([$user_id]);
    $scan_count = (int)$scans->fetchColumn();

    $labs  = $pdo->prepare('SELECT COUNT(*) FROM lab_attempts WHERE user_id = ? AND status = "solved"');
    $labs->execute([$user_id]);
    $labs_solved = (int)$labs->fetchColumn();

    $quiz  = $pdo->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE user_id = ?');
    $quiz->execute([$user_id]);
    $quiz_count = (int)$quiz->fetchColumn();

    $streak = $pdo->prepare('SELECT streak_days FROM users WHERE id = ?');
    $streak->execute([$user_id]);
    $streak_days = (int)$streak->fetchColumn();

    $all_badges = $pdo->query('SELECT * FROM badges')->fetchAll();
    $earned_ids = $pdo->prepare('SELECT badge_id FROM user_badges WHERE user_id = ?');
    $earned_ids->execute([$user_id]);
    $already_earned = array_column($earned_ids->fetchAll(), 'badge_id');

    $newly_earned = [];
    foreach ($all_badges as $badge) {
        if (in_array($badge['id'], $already_earned)) continue;
        $earn = false;
        switch ($badge['condition_type']) {
            case 'scan_count':   $earn = $scan_count  >= $badge['condition_value']; break;
            case 'labs_solved':  $earn = $labs_solved  >= $badge['condition_value']; break;
            case 'quiz_score':   $earn = $quiz_count   >= $badge['condition_value']; break;
            case 'streak':       $earn = $streak_days  >= $badge['condition_value']; break;
            case 'xp_reached':   $earn = ($u['xp'] ?? 0) >= $badge['condition_value']; break;
        }
        if ($earn) {
            $pdo->prepare('INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?, ?)')
                ->execute([$user_id, $badge['id']]);
            $newly_earned[] = $badge;
        }
    }
    return $newly_earned;
}

// ─── CSRF ──────────────────────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(string $token): bool {
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

// ─── Helpers ───────────────────────────────────────────────────────────────
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never {
    header("Location: $url");
    exit;
}

function google_oauth_url(): string {
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => getenv('GOOGLE_CLIENT_ID') ?: '',
        'redirect_uri'  => getenv('GOOGLE_REDIRECT_URI') ?: '',
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account',
    ]);
}

function is_post(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function create_notification(int $user_id, string $type, string $title, string $message, string $link = ''): void {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT,
            link VARCHAR(512) DEFAULT '',
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id, is_read)
        )");
        db()->prepare('INSERT INTO notifications (user_id, type, title, message, link) VALUES (?,?,?,?,?)')
            ->execute([$user_id, $type, $title, $message, $link]);
    } catch (Exception $e) {}
}

function totp_generate_secret(): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 16; $i++) $secret .= $chars[random_int(0, 31)];
    return $secret;
}
function totp_base32_decode(string $s): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper($s)) as $c) {
        $pos = strpos($chars, $c);
        if ($pos !== false) $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) $out .= chr(bindec(substr($bits, $i, 8)));
    return $out;
}
function totp_code(string $secret, ?int $time = null): string {
    $time ??= time();
    $counter = pack('N*', 0, (int)floor($time / 30));
    $hash = hash_hmac('sha1', $counter, totp_base32_decode($secret), true);
    $offset = ord($hash[19]) & 0xf;
    $code = ((ord($hash[$offset]) & 0x7f) << 24) | ((ord($hash[$offset+1]) & 0xff) << 16)
          | ((ord($hash[$offset+2]) & 0xff) << 8)  |  (ord($hash[$offset+3]) & 0xff);
    return str_pad($code % 1000000, 6, '0', STR_PAD_LEFT);
}
function totp_verify(string $secret, string $code): bool {
    $t = time();
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(totp_code($secret, $t + $i * 30), $code)) return true;
    }
    return false;
}

// ─── Plan / subscription helpers ───────────────────────────────────────────
// Plans: 'free' | 'pro'
// Ensure columns exist lazily — called once per request by is_pro()
function _ensure_plan_columns(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("ALTER TABLE users
            ADD COLUMN IF NOT EXISTS plan ENUM('free','pro') NOT NULL DEFAULT 'free',
            ADD COLUMN IF NOT EXISTS plan_expires_at DATETIME NULL,
            ADD COLUMN IF NOT EXISTS stripe_customer_id VARCHAR(255) NULL,
            ADD COLUMN IF NOT EXISTS stripe_subscription_id VARCHAR(255) NULL"
        );
    } catch (Exception $e) {}
}

function is_pro(array $user): bool {
    _ensure_plan_columns();
    if (($user['role'] ?? '') === 'admin') return true; // admins always have pro
    if (($user['plan'] ?? 'free') !== 'pro') return false;
    $exp = $user['plan_expires_at'] ?? null;
    if (!$exp) return true; // lifetime / no expiry set
    return strtotime($exp) > time();
}

function require_pro(array $user): void {
    if (!is_pro($user)) {
        redirect('/upgrade/');
    }
}

// Daily scan quota for free users (0 = unlimited)
define('FREE_SCAN_LIMIT', 3);

function free_scans_today(int $user_id): int {
    try {
        $s = db()->prepare(
            "SELECT COUNT(*) FROM scans WHERE user_id = ? AND DATE(created_at) = CURDATE()"
        );
        $s->execute([$user_id]);
        return (int)$s->fetchColumn();
    } catch (Exception $e) { return 0; }
}

function scan_quota_exceeded(array $user): bool {
    if (is_pro($user)) return false;
    return free_scans_today((int)$user['id']) >= FREE_SCAN_LIMIT;
}

function flash(string $key, string $msg): void {
    $_SESSION["flash_$key"] = $msg;
}

function get_flash(string $key): ?string {
    $msg = $_SESSION["flash_$key"] ?? null;
    unset($_SESSION["flash_$key"]);
    return $msg;
}
