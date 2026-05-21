<?php
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$uid = (int)$user['id'];

// Ensure table exists
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
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
                    ->execute([$id, $uid]);
            } catch (Exception $e) {}
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'mark_all_read') {
        try {
            db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')
                ->execute([$uid]);
        } catch (Exception $e) {}
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// GET
try {
    $count_stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $count_stmt->execute([$uid]);
    $count = (int)$count_stmt->fetchColumn();

    $items_stmt = db()->prepare(
        'SELECT id, type, title, message, link, created_at, is_read
         FROM notifications WHERE user_id = ?
         ORDER BY created_at DESC LIMIT 15'
    );
    $items_stmt->execute([$uid]);
    $items = $items_stmt->fetchAll();
} catch (Exception $e) {
    $count = 0;
    $items = [];
}

echo json_encode(['count' => $count, 'items' => $items]);
