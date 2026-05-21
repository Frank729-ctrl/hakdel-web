<?php
require_once __DIR__ . '/../config/app.php';

// Ensure admin_logs table exists
db()->exec('
    CREATE TABLE IF NOT EXISTS admin_logs (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        admin_id      INT UNSIGNED NOT NULL,
        admin_username VARCHAR(40) NOT NULL,
        action        VARCHAR(80)  NOT NULL,
        target_type   VARCHAR(40)  DEFAULT NULL,
        target_id     INT UNSIGNED DEFAULT NULL,
        detail        TEXT         DEFAULT NULL,
        ip            VARCHAR(45)  DEFAULT NULL,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
');

function require_admin(): array {
    if (!isset($_SESSION['admin_id'])) {
        header('Location: /admin/index.php');
        exit;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND role = "admin"');
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    if (!$admin) {
        session_destroy();
        header('Location: /admin/index.php');
        exit;
    }
    return $admin;
}

function admin_logout(): void {
    unset($_SESSION['admin_id'], $_SESSION['admin_username']);
}

function log_admin_action(array $admin, string $action, string $target_type = '', int $target_id = 0, string $detail = ''): void {
    try {
        db()->prepare('
            INSERT INTO admin_logs (admin_id, admin_username, action, target_type, target_id, detail, ip)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $admin['id'],
            $admin['username'],
            $action,
            $target_type ?: null,
            $target_id    ?: null,
            $detail        ?: null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) {
        // Silently fail — don't let logging break the admin action
    }
}
