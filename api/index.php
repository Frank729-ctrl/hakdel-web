<?php
/**
 * Vercel front controller.
 * Single serverless function — routes every request to the correct PHP file.
 * vercel.json rewrites all traffic here; we resolve the original path and
 * require the matching file so __DIR__ inside each page still resolves correctly.
 */

$uri  = $_SERVER['REQUEST_URI'] ?? '/';
$path = rtrim(parse_url($uri, PHP_URL_PATH), '/') ?: '/';

$base = __DIR__ . '/..';   // project root (one level up from api/)

// ── Dynamic routes (path params) ────────────────────────────────────────────
$dynamic = [
    '#^/quiz/([^/]+)/play$#' => ['/quiz/quiz_play.php',       'slug'],
    '#^/quiz/([^/]+)$#'      => ['/quiz/quiz_category.php',   'slug'],
    '#^/labs/([^/]+)$#'      => ['/labs/view.php',            'slug'],
    '#^/incidents/(\d+)$#'   => ['/incidents/view.php',        'id'],
];

foreach ($dynamic as $pattern => [$rel, $param]) {
    if (preg_match($pattern, $path, $m)) {
        $_GET[$param] = $_REQUEST[$param] = $m[1];
        $file = $base . $rel;
        if (file_exists($file)) { chdir(dirname($file)); require $file; }
        else                    { http_response_code(404); echo '404'; }
        exit;
    }
}

// ── Static routes ────────────────────────────────────────────────────────────
$routes = [
    '/'                        => '/index.php',

    '/dashboard'               => '/dashboard/index.php',

    '/scanner'                 => '/scanner/index.php',
    '/scanner/history'         => '/scanner/history.php',
    '/scanner/compare'         => '/scanner/compare.php',
    '/scanner/schedule'        => '/scanner/schedule.php',
    '/scanner/report'          => '/scanner/report.php',
    '/scanner/save'            => '/scanner/save.php',
    '/scanner/get'             => '/scanner/get.php',

    '/labs'                    => '/labs/index.php',
    '/labs/submit'             => '/labs/submit.php',

    '/quiz'                    => '/quiz/index.php',
    '/quiz/answer'             => '/quiz/save_quiz_answer.php',
    '/quiz/complete'           => '/quiz/complete_session.php',

    '/leaderboard'             => '/leaderboard/index.php',
    '/profile'                 => '/profile/index.php',
    '/about'                   => '/about/index.php',

    '/tools'                   => '/tools/index.php',
    '/tools/ip'                => '/tools/ip_check.php',
    '/tools/hash'              => '/tools/hash_check.php',
    '/tools/cve'               => '/tools/cve_check.php',
    '/tools/ports'             => '/tools/port_scan.php',
    '/tools/headers'           => '/tools/headers.php',
    '/tools/url'               => '/tools/url_check.php',
    '/tools/domain'            => '/tools/domain.php',
    '/tools/email'             => '/tools/email_check.php',
    '/tools/ai'                => '/tools/ai_analysis.php',
    '/tools/network'           => '/tools/network.php',
    '/tools/watchlist'         => '/tools/watchlist.php',

    '/incidents'               => '/incidents/index.php',
    '/incidents/create'        => '/incidents/create.php',

    '/settings'                => '/settings/index.php',
    '/settings/2fa'            => '/settings/2fa.php',

    '/upgrade'                 => '/upgrade/index.php',
    '/upgrade/callback'        => '/upgrade/callback.php',
    '/upgrade/webhook'         => '/upgrade/webhook.php',

    '/notifications'           => '/notifications/index.php',
    '/api/notifications'       => '/api/notifications.php',

    '/admin'                   => '/admin/index.php',
    '/admin/dashboard'         => '/admin/dashboard.php',

    '/auth/login'              => '/auth/login.php',
    '/auth/register'           => '/auth/register.php',
    '/auth/logout'             => '/auth/logout.php',
    '/auth/forgot-password'    => '/auth/forgot_password.php',
    '/auth/reset-password'     => '/auth/reset_password.php',
    '/auth/verify-email'       => '/auth/verify_email.php',
    '/auth/2fa'                => '/auth/2fa_verify.php',
    '/auth/google_callback.php'=> '/auth/google_callback.php',

    '/legal/terms'             => '/legal/terms.php',
    '/legal/privacy'           => '/legal/privacy.php',
];

$rel = $routes[$path] ?? null;
if ($rel) {
    $file = $base . $rel;
    if (file_exists($file)) {
        chdir(dirname($file));
        require $file;
        exit;
    }
}

http_response_code(404);
echo '<!DOCTYPE html><html><body><h1>404 — Page not found</h1></body></html>';
