<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'notifications';
$topbar_title = 'Notifications';

$uid = (int)$user['id'];
$pdo = db();

// Ensure table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
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

// Handle POST actions
if (is_post()) {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        flash('error', 'Invalid form submission.');
        redirect('/notifications/');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
        }
        redirect('/notifications/');
    }

    if ($action === 'mark_all_read') {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$uid]);
        flash('success', 'All notifications marked as read.');
        redirect('/notifications/');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
        }
        redirect('/notifications/');
    }

    if ($action === 'delete_all') {
        $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$uid]);
        flash('success', 'All notifications deleted.');
        redirect('/notifications/');
    }

    redirect('/notifications/');
}

// Pagination
$per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

try {
    $total_stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
    $total_stmt->execute([$uid]);
    $total = (int)$total_stmt->fetchColumn();

    $notifs_stmt = $pdo->prepare(
        'SELECT * FROM notifications WHERE user_id = ?
         ORDER BY created_at DESC LIMIT ? OFFSET ?'
    );
    $notifs_stmt->execute([$uid, $per_page, $offset]);
    $notifications = $notifs_stmt->fetchAll();

    $unread_count_stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $unread_count_stmt->execute([$uid]);
    $unread_count = (int)$unread_count_stmt->fetchColumn();
} catch (Exception $e) {
    $total = 0;
    $notifications = [];
    $unread_count = 0;
}

$total_pages = max(1, (int)ceil($total / $per_page));

$success = get_flash('success');
$error   = get_flash('error');

function notif_type_icon(string $type): string {
    return match($type) {
        'badge'     => '&#127941;',
        'scan'      => '&#9632;',
        'alert'     => '&#9888;',
        'xp'        => '&#9733;',
        'security'  => '&#128274;',
        'system'    => '&#9881;',
        default     => '&#128276;',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Notifications — HakDel</title>
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="stylesheet" href="/assets/layout.css">
  <style>
    .notif-page-wrap { max-width: 780px; }
    .notif-toolbar {
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      flex-wrap: wrap;
    }
    .notif-toolbar-left { display: flex; align-items: center; gap: 10px; }
    .notif-count-badge {
      background: var(--danger); color: #fff;
      font-family: var(--mono); font-size: 11px; font-weight: 700;
      padding: 2px 8px; border-radius: 10px;
    }
    .notif-row {
      display: flex; align-items: flex-start; gap: 14px;
      padding: 14px 18px;
      border-bottom: 1px solid var(--border);
      transition: background 0.1s;
    }
    .notif-row:last-child { border-bottom: none; }
    .notif-row.unread { background: rgba(0,212,170,0.025); }
    .notif-row:hover { background: rgba(255,255,255,0.02); }
    .notif-row-icon {
      width: 36px; height: 36px; border-radius: 8px;
      background: var(--bg4); border: 1px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      font-size: 17px; flex-shrink: 0;
    }
    .notif-row-body { flex: 1; min-width: 0; }
    .notif-row-title { font-size: 13px; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 7px; }
    .notif-unread-dot {
      width: 7px; height: 7px; border-radius: 50%;
      background: var(--accent); flex-shrink: 0;
    }
    .notif-row-msg { font-size: 12px; color: var(--text2); margin-top: 3px; line-height: 1.5; }
    .notif-row-time { font-family: var(--mono); font-size: 10px; color: var(--text3); margin-top: 5px; }
    .notif-row-actions { display: flex; gap: 6px; flex-shrink: 0; align-items: center; }
    .btn-notif-sm {
      background: var(--bg4); border: 1px solid var(--border);
      color: var(--text3); font-size: 11px; font-family: var(--mono);
      padding: 4px 9px; border-radius: var(--radius); cursor: pointer;
      transition: all 0.12s; text-decoration: none;
    }
    .btn-notif-sm:hover { color: var(--text); border-color: rgba(255,255,255,0.2); }
    .btn-notif-del { color: var(--danger); }
    .btn-notif-del:hover { background: rgba(255,77,77,0.08); border-color: rgba(255,77,77,0.3); }
    .notif-empty-state {
      padding: 60px 20px; text-align: center;
    }
    .notif-empty-icon { font-size: 48px; margin-bottom: 12px; }
    .notif-empty-text { font-size: 15px; color: var(--text3); }
    .notif-pagination {
      display: flex; align-items: center; gap: 8px; justify-content: center;
      padding: 16px;
    }
    .page-btn {
      background: var(--bg3); border: 1px solid var(--border);
      color: var(--text2); font-family: var(--mono); font-size: 12px;
      padding: 6px 12px; border-radius: var(--radius); text-decoration: none;
      transition: all 0.12s;
    }
    .page-btn:hover { border-color: var(--accent); color: var(--accent); }
    .page-btn.active { background: rgba(0,212,170,0.1); border-color: var(--accent); color: var(--accent); }
    .notif-card {
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: var(--radius-lg); overflow: hidden;
    }
    .flash-success {
      background: rgba(0,212,170,0.08); border: 1px solid rgba(0,212,170,0.2);
      border-radius: var(--radius); padding: 10px 16px; font-size: 13px; color: var(--accent);
    }
    .flash-error {
      background: rgba(255,77,77,0.08); border: 1px solid rgba(255,77,77,0.2);
      border-radius: var(--radius); padding: 10px 16px; font-size: 13px; color: var(--danger);
    }
  </style>
</head>
<body>
<?php require __DIR__ . '/../partials/topbar.php'; ?>
<div class="hk-shell">
<?php require __DIR__ . '/../partials/sidebar.php'; ?>
<main class="hk-main">

  <div class="hk-page-header">
    <div>
      <div class="hk-page-eyebrow">WORKSPACE</div>
      <h1 class="hk-page-title">Notifications</h1>
      <p class="hk-page-sub"><?php echo $total; ?> notification<?php echo $total !== 1 ? 's' : ''; ?> total</p>
    </div>
  </div>

  <?php if ($success): ?>
  <div class="flash-success"><?php echo h($success); ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="flash-error"><?php echo h($error); ?></div>
  <?php endif; ?>

  <div class="notif-page-wrap">
    <div class="notif-toolbar" style="margin-bottom:12px">
      <div class="notif-toolbar-left">
        <?php if ($unread_count > 0): ?>
        <span class="notif-count-badge"><?php echo $unread_count; ?> unread</span>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:8px">
        <?php if ($unread_count > 0): ?>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
          <input type="hidden" name="action" value="mark_all_read">
          <button type="submit" class="btn-notif-sm">Mark all read</button>
        </form>
        <?php endif; ?>
        <?php if ($total > 0): ?>
        <form method="POST" onsubmit="return confirm('Delete all notifications?')">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
          <input type="hidden" name="action" value="delete_all">
          <button type="submit" class="btn-notif-sm btn-notif-del">Delete all</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="notif-card">
      <?php if (empty($notifications)): ?>
      <div class="notif-empty-state">
        <div class="notif-empty-icon">&#128276;</div>
        <div class="notif-empty-text">You have no notifications yet.</div>
      </div>
      <?php else: ?>
      <?php foreach ($notifications as $n): ?>
      <div class="notif-row <?php echo !$n['is_read'] ? 'unread' : ''; ?>">
        <div class="notif-row-icon"><?php echo notif_type_icon($n['type']); ?></div>
        <div class="notif-row-body">
          <div class="notif-row-title">
            <?php if (!$n['is_read']): ?><span class="notif-unread-dot"></span><?php endif; ?>
            <?php echo h($n['title']); ?>
          </div>
          <?php if ($n['message']): ?>
          <div class="notif-row-msg"><?php echo h($n['message']); ?></div>
          <?php endif; ?>
          <div class="notif-row-time"><?php echo date('M j, Y H:i', strtotime($n['created_at'])); ?></div>
        </div>
        <div class="notif-row-actions">
          <?php if ($n['link']): ?>
          <a href="<?php echo h($n['link']); ?>" class="btn-notif-sm">View</a>
          <?php endif; ?>
          <?php if (!$n['is_read']): ?>
          <form method="POST" style="display:inline">
            <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="mark_read">
            <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">
            <button type="submit" class="btn-notif-sm">Read</button>
          </form>
          <?php endif; ?>
          <form method="POST" style="display:inline">
            <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">
            <button type="submit" class="btn-notif-sm btn-notif-del">&#10005;</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>

      <?php if ($total_pages > 1): ?>
      <div class="notif-pagination">
        <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page - 1; ?>" class="page-btn">&larr; Prev</a>
        <?php endif; ?>
        <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
        <a href="?page=<?php echo $p; ?>" class="page-btn <?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
        <a href="?page=<?php echo $page + 1; ?>" class="page-btn">Next &rarr;</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

</main>
</div>
</body>
</html>
