<?php
/**
 * PHP built-in server router.
 * Run with: php -S localhost:5500 router.php
 */

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = __DIR__;

// Serve real files / directories as-is
if ($uri !== '/' && file_exists($base . $uri)) {
    return false;
}

// ── Route table ──────────────────────────────────────────────
// /quiz/{slug}/play?tier=N
if (preg_match('#^/quiz/([^/]+)/play$#', $uri, $m)) {
    $_GET['slug'] = $m[1];
    include $base . '/quiz/quiz_play.php';
    return;
}

// /quiz/{slug}
if (preg_match('#^/quiz/([^/]+)$#', $uri, $m)) {
    // Don't catch .php file references
    if (!str_ends_with($m[1], '.php')) {
        $_GET['slug'] = $m[1];
        include $base . '/quiz/quiz_category.php';
        return;
    }
}

// /labs/{slug}
if (preg_match('#^/labs/([^/]+)$#', $uri, $m)) {
    if (!str_ends_with($m[1], '.php')) {
        $_GET['slug'] = $m[1];
        include $base . '/labs/view.php';
        return;
    }
}

// Fall through to index.php if exists in matched dir
$path = $base . $uri;
if (is_dir($path)) {
    $index = rtrim($path, '/') . '/index.php';
    if (file_exists($index)) {
        include $index;
        return;
    }
}

// 404
http_response_code(404);
include $base . '/404.php' ?: print('<h1>404 Not Found</h1>');
