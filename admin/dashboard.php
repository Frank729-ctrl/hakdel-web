<?php
require_once 'admin_config.php';
$admin = require_admin();

$action  = $_GET['action']  ?? '';
$section = $_GET['section'] ?? 'stats';
$msg     = '';
$error   = '';

// ── POST handling ─────────────────────────────────────────────────────────
if (is_post() && verify_csrf($_POST['csrf'] ?? '')) {
    $form = $_POST['form'] ?? '';

    // ── Lab actions ──────────────────────────────────────────────────────
    if ($form === 'add_lab') {
        $hints = json_encode(array_values(array_filter(array_map('trim', explode("\n", $_POST['hints'] ?? '')))));
        $stmt  = db()->prepare('
            INSERT INTO labs (slug, title, description, category, difficulty, xp_reward,
                              level_required, flag_hash, instructions, hints,
                              ssh_host, ssh_port, ssh_user, ssh_password, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, SHA2(?, 256), ?, ?, ?, ?, ?, ?, 1)
        ');
        try {
            $stmt->execute([
                trim($_POST['slug']), trim($_POST['title']), trim($_POST['description']),
                trim($_POST['category']), $_POST['difficulty'],
                (int)$_POST['xp_reward'], (int)$_POST['level_required'],
                trim($_POST['flag']), trim($_POST['instructions']), $hints,
                trim($_POST['ssh_host']), trim($_POST['ssh_port']),
                trim($_POST['ssh_user']), trim($_POST['ssh_password']),
            ]);
            log_admin_action($admin, 'create_lab', 'lab', (int)db()->lastInsertId(), trim($_POST['title']));
            $msg = 'Lab added successfully.';
        } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
    }

    if ($form === 'edit_lab') {
        $hints = json_encode(array_values(array_filter(array_map('trim', explode("\n", $_POST['hints'] ?? '')))));
        $stmt  = db()->prepare('
            UPDATE labs SET title=?, description=?, category=?, difficulty=?,
                xp_reward=?, level_required=?, instructions=?, hints=?,
                ssh_host=?, ssh_port=?, ssh_user=?, ssh_password=?, is_active=?
            WHERE id=?
        ');
        try {
            $stmt->execute([
                trim($_POST['title']), trim($_POST['description']),
                trim($_POST['category']), $_POST['difficulty'],
                (int)$_POST['xp_reward'], (int)$_POST['level_required'],
                trim($_POST['instructions']), $hints,
                trim($_POST['ssh_host']), trim($_POST['ssh_port']),
                trim($_POST['ssh_user']), trim($_POST['ssh_password']),
                isset($_POST['is_active']) ? 1 : 0,
                (int)$_POST['lab_id'],
            ]);
            if (!empty(trim($_POST['flag']))) {
                db()->prepare('UPDATE labs SET flag_hash = SHA2(?, 256) WHERE id = ?')
                    ->execute([trim($_POST['flag']), (int)$_POST['lab_id']]);
            }
            log_admin_action($admin, 'update_lab', 'lab', (int)$_POST['lab_id'], trim($_POST['title']));
            $msg = 'Lab updated successfully.';
        } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
    }

    if ($form === 'delete_lab') {
        $lid = (int)$_POST['lab_id'];
        $ltitle = db()->prepare('SELECT title FROM labs WHERE id=?');
        $ltitle->execute([$lid]);
        $lrow = $ltitle->fetch();
        db()->prepare('DELETE FROM labs WHERE id = ?')->execute([$lid]);
        log_admin_action($admin, 'delete_lab', 'lab', $lid, $lrow['title'] ?? '');
        $msg = 'Lab deleted.';
    }

    // ── Quiz actions ─────────────────────────────────────────────────────
    if ($form === 'add_question') {
        $tier     = max(1, min(3, (int)($_POST['tier'] ?? 1)));
        $pts_map  = [1 => 10, 2 => 20, 3 => 30];
        $points   = (int)($_POST['points'] ?? $pts_map[$tier]);
        $stmt     = db()->prepare('
            INSERT INTO quiz_questions
                (category, domain, domain_number, question,
                 option_a, option_b, option_c, option_d,
                 correct, explanation, difficulty, tier, points, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ');
        try {
            $stmt->execute([
                trim($_POST['category']),
                trim($_POST['domain'] ?? ''),
                ((int)($_POST['domain_number'] ?? 0)) ?: null,
                trim($_POST['question']),
                trim($_POST['option_a']), trim($_POST['option_b']),
                trim($_POST['option_c']), trim($_POST['option_d']),
                $_POST['correct'],
                trim($_POST['explanation']),
                $_POST['difficulty'],
                $tier, $points,
            ]);
            $msg = 'Question added successfully.';
        } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
    }

    if ($form === 'delete_question') {
        $qid = (int)$_POST['question_id'];
        db()->prepare('DELETE FROM quiz_questions WHERE id = ?')->execute([$qid]);
        log_admin_action($admin, 'delete_question', 'quiz_questions', $qid, '');
        $msg = 'Question deleted.';
    }

    if ($form === 'toggle_question') {
        db()->prepare('UPDATE quiz_questions SET is_active = NOT is_active WHERE id = ?')
            ->execute([(int)$_POST['question_id']]);
        $msg = 'Question toggled.';
    }

    if ($form === 'bulk_questions') {
        $ids         = array_values(array_filter(array_map('intval', $_POST['question_ids'] ?? [])));
        $bulk_action = $_POST['bulk_action'] ?? '';
        if ($ids && in_array($bulk_action, ['delete', 'deactivate', 'activate'], true)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            if ($bulk_action === 'delete') {
                db()->prepare("DELETE FROM quiz_questions WHERE id IN ($ph)")->execute($ids);
                log_admin_action($admin, 'bulk_delete_questions', 'quiz_questions', 0, 'Deleted ' . count($ids) . ' questions');
            } elseif ($bulk_action === 'deactivate') {
                db()->prepare("UPDATE quiz_questions SET is_active=0 WHERE id IN ($ph)")->execute($ids);
            } elseif ($bulk_action === 'activate') {
                db()->prepare("UPDATE quiz_questions SET is_active=1 WHERE id IN ($ph)")->execute($ids);
            }
            $msg = 'Bulk action "' . h($bulk_action) . '" applied to ' . count($ids) . ' question(s).';
        } else {
            $error = 'No questions selected or invalid action.';
        }
    }

    // ── Category actions ─────────────────────────────────────────────────
    if ($form === 'edit_category') {
        $key_concepts = json_encode(
            array_values(array_filter(array_map('trim', explode("\n", $_POST['key_concepts'] ?? ''))))
        );
        db()->prepare('
            UPDATE quiz_categories
            SET name=?, description=?, icon=?, level_required=?,
                sort_order=?, intro_text=?, key_concepts=?
            WHERE id=?
        ')->execute([
            trim($_POST['name']),
            trim($_POST['description']),
            trim($_POST['icon']),
            (int)$_POST['level_required'],
            (int)$_POST['sort_order'],
            trim($_POST['intro_text']),
            $key_concepts,
            (int)$_POST['cat_id'],
        ]);
        log_admin_action($admin, 'update_category', 'quiz_categories', (int)$_POST['cat_id'], trim($_POST['name']));
        $msg = 'Category updated.';
    }

    // ── User actions ─────────────────────────────────────────────────────
    if ($form === 'reset_user') {
        $uid = (int)$_POST['user_id'];
        $unm = db()->prepare('SELECT username FROM users WHERE id=?');
        $unm->execute([$uid]);
        $urow = $unm->fetch();
        db()->prepare('UPDATE users SET xp = 0, level = 1 WHERE id = ?')->execute([$uid]);
        db()->prepare('DELETE FROM scans WHERE user_id = ?')->execute([$uid]);
        db()->prepare('DELETE FROM lab_attempts WHERE user_id = ?')->execute([$uid]);
        db()->prepare('DELETE FROM quiz_attempts WHERE user_id = ?')->execute([$uid]);
        db()->prepare('DELETE FROM quiz_tier_progress WHERE user_id = ?')->execute([$uid]);
        db()->prepare('DELETE FROM user_badges WHERE user_id = ?')->execute([$uid]);
        log_admin_action($admin, 'reset_user', 'users', $uid, $urow['username'] ?? '');
        $msg = 'User progress reset.';
    }

    if ($form === 'delete_user') {
        $uid = (int)$_POST['user_id'];
        if ($uid !== $admin['id']) {
            $unm = db()->prepare('SELECT username FROM users WHERE id=?');
            $unm->execute([$uid]);
            $urow = $unm->fetch();
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            log_admin_action($admin, 'delete_user', 'users', $uid, $urow['username'] ?? '');
            $msg = 'User deleted.';
        } else {
            $error = 'You cannot delete your own admin account.';
        }
    }

    if ($form === 'promote_user') {
        $uid = (int)$_POST['user_id'];
        if ($uid !== $admin['id']) {
            db()->prepare('UPDATE users SET role="admin" WHERE id = ?')->execute([$uid]);
            $unm = db()->prepare('SELECT username FROM users WHERE id=?');
            $unm->execute([$uid]);
            $urow = $unm->fetch();
            log_admin_action($admin, 'promote_user', 'users', $uid, $urow['username'] ?? '');
            $msg = 'User promoted to admin.';
        }
    }

    if ($form === 'award_xp_manual') {
        require_once __DIR__ . '/../config/xp_service.php';
        $uid    = (int)$_POST['user_id'];
        $amount = max(1, min(10000, (int)($_POST['xp_amount'] ?? 0)));
        $reason = trim($_POST['xp_reason'] ?? 'Admin award');
        if ($uid && $amount > 0) {
            $unm = db()->prepare('SELECT username FROM users WHERE id=?');
            $unm->execute([$uid]);
            $urow = $unm->fetch();
            award_xp($uid, $amount, 'manual', 0, $reason ?: 'Admin award');
            log_admin_action($admin, 'award_xp', 'users', $uid,
                ($urow['username'] ?? 'user') . ': +' . $amount . ' XP — ' . ($reason ?: 'Admin award'));
            $msg = 'Awarded ' . $amount . ' XP to ' . ($urow['username'] ?? 'user') . '.';
        } else {
            $error = 'Invalid user or XP amount.';
        }
    }
}

// ── Fetch section data ─────────────────────────────────────────────────────
if ($section === 'stats') {
    $stat_students    = (int)db()->query('SELECT COUNT(*) FROM users WHERE role="student"')->fetchColumn();
    $stat_labs        = (int)db()->query('SELECT COUNT(*) FROM labs WHERE is_active=1')->fetchColumn();
    $stat_questions   = (int)db()->query('SELECT COUNT(*) FROM quiz_questions WHERE is_active=1')->fetchColumn();
    $stat_scans       = (int)db()->query('SELECT COUNT(*) FROM scans')->fetchColumn();
    $stat_attempts    = (int)db()->query('SELECT COUNT(*) FROM quiz_attempts')->fetchColumn();

    // Tool usage stats (tables may not exist yet — suppress errors)
    try { $stat_ip_checks   = (int)db()->query('SELECT COUNT(*) FROM ip_checks')->fetchColumn();   } catch(Exception $e) { $stat_ip_checks = 0; }
    try { $stat_hash_checks = (int)db()->query('SELECT COUNT(*) FROM hash_checks')->fetchColumn(); } catch(Exception $e) { $stat_hash_checks = 0; }
    try { $stat_cve_lookups = (int)db()->query('SELECT COUNT(*) FROM cve_lookups')->fetchColumn(); } catch(Exception $e) { $stat_cve_lookups = 0; }
    try { $stat_watchlist   = (int)db()->query('SELECT COUNT(*) FROM watchlist WHERE is_active=1')->fetchColumn(); } catch(Exception $e) { $stat_watchlist = 0; }

    $top_users = db()->query('
        SELECT username, level, xp FROM users ORDER BY xp DESC LIMIT 5
    ')->fetchAll();

    $quiz_cat_acc = db()->query('
        SELECT qq.category,
               COUNT(*)           AS total,
               SUM(qa.is_correct) AS correct
        FROM quiz_attempts qa
        JOIN quiz_questions qq ON qa.question_id = qq.id
        GROUP BY qq.category
        ORDER BY total DESC
    ')->fetchAll();

    $recent_activity = db()->query('
        (SELECT u.username, "scan" AS type, s.target_url AS detail, s.scanned_at AS ts
         FROM scans s JOIN users u ON s.user_id = u.id
         WHERE s.status = "done")
        UNION ALL
        (SELECT u.username, "lab" AS type, l.title AS detail, la.solved_at AS ts
         FROM lab_attempts la
         JOIN users u ON la.user_id = u.id
         JOIN labs l  ON la.lab_id  = l.id
         WHERE la.status = "solved")
        ORDER BY ts DESC LIMIT 15
    ')->fetchAll();

    // XP economy stats (graceful — xp_log may not exist yet)
    $xp_this_week   = 0;
    $xp_by_source   = [];
    $xp_avg_per_user = 0;
    try {
        $xp_this_week = (int)db()->query('
            SELECT COALESCE(SUM(xp_awarded),0) FROM xp_log
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ')->fetchColumn();
        $xp_by_source = db()->query('
            SELECT source, SUM(xp_awarded) AS total
            FROM xp_log GROUP BY source ORDER BY total DESC
        ')->fetchAll();
        $xp_avg_per_user = (int)db()->query('
            SELECT COALESCE(AVG(xp),0) FROM users WHERE role="student" AND xp > 0
        ')->fetchColumn();
    } catch (Exception $e) {}
}

if ($section === 'labs') {
    $labs = db()->query('SELECT * FROM labs ORDER BY sort_order ASC, id ASC')->fetchAll();
    $edit_lab = null;
    if ($action === 'edit' && isset($_GET['id'])) {
        $stmt = db()->prepare('SELECT * FROM labs WHERE id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $edit_lab = $stmt->fetch();
    }
}

if ($section === 'quiz') {
    $quiz_categories = db()->query(
        'SELECT slug, name FROM quiz_categories WHERE is_active=1 ORDER BY sort_order'
    )->fetchAll();

    $f_cat  = trim($_GET['cat']  ?? '');
    $f_tier = (int)($_GET['tier'] ?? 0);
    $f_diff = trim($_GET['diff'] ?? '');

    $where  = ['1=1']; $params = [];
    if ($f_cat)  { $where[] = 'category = ?';   $params[] = $f_cat;  }
    if ($f_tier) { $where[] = 'tier = ?';        $params[] = $f_tier; }
    if ($f_diff) { $where[] = 'difficulty = ?';  $params[] = $f_diff; }

    $qstmt = db()->prepare(
        'SELECT * FROM quiz_questions WHERE ' . implode(' AND ', $where) .
        ' ORDER BY category ASC, tier ASC, id ASC'
    );
    $qstmt->execute($params);
    $questions = $qstmt->fetchAll();

    $cat_sum_rows = db()->query('
        SELECT category, tier, COUNT(*) AS cnt
        FROM quiz_questions
        WHERE is_active=1
        GROUP BY category, tier
        ORDER BY category ASC, tier ASC
    ')->fetchAll();
    $cat_sum_map = [];
    foreach ($cat_sum_rows as $r) {
        $cat_sum_map[$r['category']][$r['tier']] = (int)$r['cnt'];
    }
}

if ($section === 'categories') {
    $categories_all = db()->query(
        'SELECT * FROM quiz_categories ORDER BY sort_order ASC'
    )->fetchAll();
    $edit_cat = null;
    if ($action === 'edit' && isset($_GET['id'])) {
        $stmt = db()->prepare('SELECT * FROM quiz_categories WHERE id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $edit_cat = $stmt->fetch();
    }
}

if ($section === 'users') {
    $search = trim($_GET['search'] ?? '');
    $base_sql = '
        SELECT u.*,
               COUNT(DISTINCT s.id)  AS scan_count,
               COUNT(DISTINCT la.id) AS labs_solved,
               (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.user_id = u.id) AS quiz_done
        FROM users u
        LEFT JOIN scans s         ON s.user_id = u.id
        LEFT JOIN lab_attempts la ON la.user_id = u.id AND la.status = "solved"
    ';
    if ($search) {
        $ustmt = db()->prepare($base_sql . ' WHERE u.username LIKE ? OR u.email LIKE ? GROUP BY u.id ORDER BY u.created_at DESC');
        $ustmt->execute(["%$search%", "%$search%"]);
    } else {
        $ustmt = db()->query($base_sql . ' GROUP BY u.id ORDER BY u.created_at DESC');
    }
    $users = $ustmt->fetchAll();
}

if ($section === 'logs') {
    $logs = db()->query('SELECT * FROM admin_logs ORDER BY created_at DESC LIMIT 50')->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HakDel Admin Panel</title>
<link rel="stylesheet" href="../assets/style.css">
<link rel="stylesheet" href="../assets/layout.css">
<link rel="stylesheet" href="admin.css">
</head>
<body>

<?php require __DIR__ . '/../partials/admin-topbar.php'; ?>

<div class="hk-shell">

  <?php require __DIR__ . '/../partials/admin-sidebar.php'; ?>

  <main class="hk-main">

    <?php if ($msg):  ?><div class="admin-alert admin-alert-success"><?php echo h($msg);   ?></div><?php endif; ?>
    <?php if ($error):?><div class="admin-alert admin-alert-error"  ><?php echo h($error); ?></div><?php endif; ?>

    <?php // ══════════════════════════════════════════════════════════════
    // STATS DASHBOARD
    if ($section === 'stats'): ?>

    <div class="hk-page-header">
      <div>
        <div class="hk-page-eyebrow">&#9632; Overview</div>
        <h1 class="hk-page-title">Dashboard</h1>
      </div>
    </div>

    <div class="admin-stats-grid">
      <div class="admin-stat-card">
        <div class="admin-stat-label">Students</div>
        <div class="admin-stat-value"><?php echo $stat_students; ?></div>
        <div class="admin-stat-sub">registered users</div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Active Labs</div>
        <div class="admin-stat-value"><?php echo $stat_labs; ?></div>
        <div class="admin-stat-sub">published</div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Quiz Questions</div>
        <div class="admin-stat-value"><?php echo $stat_questions; ?></div>
        <div class="admin-stat-sub">active</div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Total Scans</div>
        <div class="admin-stat-value"><?php echo $stat_scans; ?></div>
        <div class="admin-stat-sub">all time</div>
      </div>
      <div class="admin-stat-card">
        <div class="admin-stat-label">Quiz Attempts</div>
        <div class="admin-stat-value"><?php echo number_format($stat_attempts); ?></div>
        <div class="admin-stat-sub">questions answered</div>
      </div>
    </div>

    <!-- Tool Usage Stats -->
    <div class="history-table-card" style="margin-bottom:20px">
      <div class="history-table-header">
        <span class="history-table-title">Tool Usage</span>
        <span style="font-family:var(--mono);font-size:11px;color:var(--text3)">all time</span>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:0;border-bottom:1px solid rgba(255,255,255,0.05)">
        <div style="padding:14px 18px;border-right:1px solid rgba(255,255,255,0.05)">
          <div style="font-size:11px;color:var(--text3);font-family:var(--mono);text-transform:uppercase;margin-bottom:4px">IP Checks</div>
          <div style="font-size:20px;font-weight:700;font-family:var(--mono);color:var(--accent)"><?php echo number_format($stat_ip_checks); ?></div>
        </div>
        <div style="padding:14px 18px;border-right:1px solid rgba(255,255,255,0.05)">
          <div style="font-size:11px;color:var(--text3);font-family:var(--mono);text-transform:uppercase;margin-bottom:4px">Hash Checks</div>
          <div style="font-size:20px;font-weight:700;font-family:var(--mono);color:var(--accent)"><?php echo number_format($stat_hash_checks); ?></div>
        </div>
        <div style="padding:14px 18px;border-right:1px solid rgba(255,255,255,0.05)">
          <div style="font-size:11px;color:var(--text3);font-family:var(--mono);text-transform:uppercase;margin-bottom:4px">CVE Lookups</div>
          <div style="font-size:20px;font-weight:700;font-family:var(--mono);color:var(--accent)"><?php echo number_format($stat_cve_lookups); ?></div>
        </div>
        <div style="padding:14px 18px;">
          <div style="font-size:11px;color:var(--text3);font-family:var(--mono);text-transform:uppercase;margin-bottom:4px">Watchlist Domains</div>
          <div style="font-size:20px;font-weight:700;font-family:var(--mono);color:var(--accent)"><?php echo number_format($stat_watchlist); ?></div>
        </div>
      </div>
    </div>

    <!-- XP Economy Stats -->
    <div class="history-table-card" style="margin-bottom:20px">
      <div class="history-table-header">
        <span class="history-table-title">XP Economy</span>
        <span style="font-family:var(--mono);font-size:11px;color:var(--text3)">last 7 days</span>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:0;border-bottom:1px solid rgba(255,255,255,0.05)">
        <div style="padding:14px 18px;border-right:1px solid rgba(255,255,255,0.05)">
          <div style="font-size:11px;color:var(--text3);font-family:var(--mono);text-transform:uppercase;margin-bottom:4px">XP This Week</div>
          <div style="font-size:20px;font-weight:700;font-family:var(--mono);color:var(--accent)"><?php echo number_format($xp_this_week); ?></div>
        </div>
        <div style="padding:14px 18px;border-right:1px solid rgba(255,255,255,0.05)">
          <div style="font-size:11px;color:var(--text3);font-family:var(--mono);text-transform:uppercase;margin-bottom:4px">Avg XP / User</div>
          <div style="font-size:20px;font-weight:700;font-family:var(--mono);color:var(--accent)"><?php echo number_format($xp_avg_per_user); ?></div>
        </div>
        <div style="padding:14px 18px">
          <div style="font-size:11px;color:var(--text3);font-family:var(--mono);text-transform:uppercase;margin-bottom:4px">Top Source</div>
          <div style="font-size:16px;font-weight:700;font-family:var(--mono);color:var(--accent)"><?php echo h(!empty($xp_by_source) ? $xp_by_source[0]['source'] : '—'); ?></div>
        </div>
      </div>
      <?php if (!empty($xp_by_source)):
        $xp_total_all = array_sum(array_column($xp_by_source, 'total'));
        $src_colors = ['lab'=>'#00d4aa','quiz_session'=>'#7f77dd','streak'=>'#ffd166','tier_unlock'=>'#48cae4','level_bonus'=>'#ff6b35','manual'=>'#ff4d6d'];
      ?>
      <div style="padding:12px 18px">
        <div style="font-size:11px;color:var(--text3);font-family:var(--mono);text-transform:uppercase;margin-bottom:10px">XP by Source (all time)</div>
        <?php foreach ($xp_by_source as $src):
          $pct = $xp_total_all > 0 ? round(($src['total'] / $xp_total_all) * 100) : 0;
          $clr = $src_colors[$src['source']] ?? '#888';
        ?>
        <div style="margin-bottom:8px">
          <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px">
            <span style="color:var(--text);font-family:var(--mono)"><?php echo h($src['source']); ?></span>
            <span style="color:<?php echo $clr; ?>;font-family:var(--mono)"><?php echo number_format((int)$src['total']); ?> XP &nbsp;(<?php echo $pct; ?>%)</span>
          </div>
          <div style="background:var(--bg3);border-radius:3px;height:5px">
            <div style="width:<?php echo $pct; ?>%;height:5px;border-radius:3px;background:<?php echo $clr; ?>;transition:width 0.6s ease"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div style="padding:16px 18px;color:var(--text3);font-size:13px">No XP awarded yet — run the migration to create the xp_log table.</div>
      <?php endif; ?>
    </div>

    <div class="admin-two-col-wide">

      <!-- Recent activity -->
      <div class="history-table-card">
        <div class="history-table-header">
          <span class="history-table-title">Recent Activity</span>
        </div>
        <div class="admin-activity-feed">
          <?php if (empty($recent_activity)): ?>
          <div style="padding:16px 18px;color:var(--text3);font-size:13px">No activity yet.</div>
          <?php endif; ?>
          <?php foreach ($recent_activity as $ev): ?>
          <div class="admin-feed-item">
            <span class="admin-feed-dot admin-feed-dot-<?php echo h($ev['type']); ?>"></span>
            <div style="flex:1;min-width:0">
              <div>
                <span class="admin-feed-user"><?php echo h($ev['username']); ?></span>
                <span style="color:var(--text2);font-size:12px"> <?php echo $ev['type'] === 'scan' ? 'scanned' : 'solved lab'; ?></span>
              </div>
              <div class="admin-feed-detail"><?php echo h(mb_substr($ev['detail'], 0, 60)); ?></div>
            </div>
            <span class="admin-feed-time"><?php echo $ev['ts'] ? date('d M H:i', strtotime($ev['ts'])) : '—'; ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Top users + quiz accuracy -->
      <div style="display:flex;flex-direction:column;gap:16px">
        <div class="history-table-card">
          <div class="history-table-header"><span class="history-table-title">Top Users by XP</span></div>
          <?php foreach ($top_users as $i => $tu): ?>
          <div class="admin-top-user">
            <span class="admin-top-rank">#<?php echo $i + 1; ?></span>
            <span class="admin-top-name"><?php echo h($tu['username']); ?></span>
            <span class="admin-top-level">Lvl <?php echo $tu['level']; ?></span>
            <span class="admin-top-xp"><?php echo number_format($tu['xp']); ?> XP</span>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="history-table-card">
          <div class="history-table-header"><span class="history-table-title">Quiz Accuracy by Category</span></div>
          <div style="padding:4px 0">
          <?php foreach ($quiz_cat_acc as $qa):
            $pct = $qa['total'] > 0 ? round(($qa['correct'] / $qa['total']) * 100) : 0;
            $clr = $pct >= 70 ? 'var(--accent)' : ($pct >= 50 ? 'var(--warn,#ffd166)' : 'var(--danger)');
          ?>
          <div style="padding:7px 18px;border-bottom:1px solid rgba(255,255,255,0.03)">
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
              <span style="color:var(--text)"><?php echo h($qa['category']); ?></span>
              <span style="font-family:var(--mono);color:<?php echo $clr; ?>"><?php echo $pct; ?>%</span>
            </div>
            <div class="admin-acc-bar-wrap">
              <div class="admin-acc-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $clr; ?>"></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($quiz_cat_acc)): ?>
          <div style="padding:16px 18px;color:var(--text3);font-size:13px">No quiz attempts yet.</div>
          <?php endif; ?>
          </div>
        </div>
      </div>

    </div>

    <?php // ══════════════════════════════════════════════════════════════
    // LABS SECTION
    elseif ($section === 'labs'): ?>

    <div class="hk-page-header">
      <div>
        <div class="hk-page-eyebrow">&#9670; Content Management</div>
        <h1 class="hk-page-title">Labs</h1>
      </div>
      <a href="?section=labs&action=add" class="btn-primary" style="text-decoration:none">+ Add Lab</a>
    </div>

    <?php if ($action === 'add' || ($action === 'edit' && $edit_lab)): ?>
    <!-- Add / Edit Lab Form -->
    <div class="admin-form-card">
      <div class="admin-form-title"><?php echo $action === 'edit' ? 'Edit Lab' : 'Add New Lab'; ?></div>
      <form method="POST" class="admin-form" id="lab-form">
        <input type="hidden" name="csrf"  value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="form"  value="<?php echo $action === 'edit' ? 'edit_lab' : 'add_lab'; ?>">
        <?php if ($action === 'edit'): ?>
        <input type="hidden" name="lab_id" value="<?php echo $edit_lab['id']; ?>">
        <?php endif; ?>

        <div class="admin-form-grid">
          <?php if ($action === 'add'): ?>
          <div class="admin-field">
            <label class="admin-label">Slug (unique, no spaces)</label>
            <input type="text" name="slug" class="admin-input" placeholder="linux-privesc-01" required>
          </div>
          <?php endif; ?>
          <div class="admin-field">
            <label class="admin-label">Title</label>
            <input type="text" name="title" class="admin-input"
                   value="<?php echo h($edit_lab['title'] ?? ''); ?>" required>
          </div>
          <div class="admin-field">
            <label class="admin-label">Category</label>
            <input type="text" name="category" class="admin-input"
                   value="<?php echo h($edit_lab['category'] ?? ''); ?>"
                   placeholder="Web Exploitation / Privilege Escalation">
          </div>
          <div class="admin-field">
            <label class="admin-label">Difficulty</label>
            <select name="difficulty" class="admin-input">
              <?php foreach (['easy','medium','hard','expert'] as $d): ?>
              <option value="<?php echo $d; ?>" <?php echo ($edit_lab['difficulty'] ?? 'easy') === $d ? 'selected' : ''; ?>>
                <?php echo ucfirst($d); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="admin-field">
            <label class="admin-label">XP Reward</label>
            <input type="number" name="xp_reward" class="admin-input"
                   value="<?php echo $edit_lab['xp_reward'] ?? 100; ?>" min="0">
          </div>
          <div class="admin-field">
            <label class="admin-label">Level Required</label>
            <input type="number" name="level_required" class="admin-input"
                   value="<?php echo $edit_lab['level_required'] ?? 1; ?>" min="1">
          </div>
        </div>

        <!-- SSH Connection grouped 2×2 -->
        <div class="admin-ssh-group">
          <div class="admin-ssh-group-label">SSH Connection</div>
          <div class="admin-ssh-grid">
            <div class="admin-field">
              <label class="admin-label">Host</label>
              <input type="text" name="ssh_host" class="admin-input"
                     value="<?php echo h($edit_lab['ssh_host'] ?? '192.168.56.101'); ?>"
                     placeholder="192.168.56.101">
            </div>
            <div class="admin-field">
              <label class="admin-label">Port</label>
              <input type="text" name="ssh_port" class="admin-input"
                     value="<?php echo h($edit_lab['ssh_port'] ?? '22'); ?>">
            </div>
            <div class="admin-field">
              <label class="admin-label">Username</label>
              <input type="text" name="ssh_user" class="admin-input"
                     value="<?php echo h($edit_lab['ssh_user'] ?? 'player'); ?>">
            </div>
            <div class="admin-field">
              <label class="admin-label">Password</label>
              <input type="text" name="ssh_password" class="admin-input"
                     value="<?php echo h($edit_lab['ssh_password'] ?? ''); ?>">
            </div>
          </div>
        </div>

        <div class="admin-field">
          <label class="admin-label">Description</label>
          <textarea name="description" class="admin-input admin-textarea" rows="2"><?php echo h($edit_lab['description'] ?? ''); ?></textarea>
        </div>

        <div class="admin-field">
          <label class="admin-label">Flag (plain text — hashed automatically)</label>
          <input type="text" name="flag" class="admin-input"
                 placeholder="<?php echo $action === 'edit' ? 'Leave blank to keep existing flag' : 'flag{your_flag_here}'; ?>">
        </div>

        <div class="admin-field">
          <label class="admin-label" style="display:flex;align-items:center;justify-content:space-between">
            <span>Instructions (Markdown)</span>
            <button type="button" class="btn-admin-edit" style="font-size:11px;padding:3px 10px"
                    onclick="openMdPreview()">&#128065; Preview</button>
          </label>
          <textarea name="instructions" id="instructions-textarea"
                    class="admin-input admin-textarea" rows="10"><?php echo h($edit_lab['instructions'] ?? ''); ?></textarea>
        </div>

        <div class="admin-field">
          <label class="admin-label">Hints (one per line)</label>
          <textarea name="hints" class="admin-input admin-textarea" rows="4"
                    placeholder="Hint 1&#10;Hint 2&#10;Hint 3"><?php
            $hints_arr = json_decode($edit_lab['hints'] ?? '[]', true) ?: [];
            echo h(implode("\n", $hints_arr));
          ?></textarea>
        </div>

        <?php if ($action === 'edit'): ?>
        <div class="admin-field admin-checkbox-field">
          <label class="admin-checkbox-label">
            <input type="checkbox" name="is_active" <?php echo ($edit_lab['is_active'] ?? 1) ? 'checked' : ''; ?>>
            Active (visible to users)
          </label>
        </div>
        <?php endif; ?>

        <div class="admin-form-actions">
          <a href="?section=labs" class="btn-secondary" style="text-decoration:none">Cancel</a>
          <button type="submit" class="btn-primary"><?php echo $action === 'edit' ? 'Save Changes' : 'Add Lab'; ?></button>
        </div>
      </form>
    </div>

    <?php else: ?>
    <!-- Labs list -->
    <div class="history-table-card">
      <div class="history-table-header">
        <span class="history-table-title">All Labs (<?php echo count($labs); ?>)</span>
      </div>
      <?php foreach ($labs as $lab): ?>
      <div class="admin-table-row">
        <div style="display:flex;align-items:center;gap:8px;width:10px;flex-shrink:0">
          <span class="vm-dot vm-dot-unknown"
                id="vm-<?php echo $lab['id']; ?>"
                title="VM status unknown"
                data-host="<?php echo h($lab['ssh_host'] ?? ''); ?>"
                data-port="<?php echo h($lab['ssh_port'] ?? '22'); ?>"></span>
        </div>
        <div class="admin-row-info">
          <div class="admin-row-title"><?php echo h($lab['title']); ?></div>
          <div class="admin-row-meta">
            <?php echo h($lab['category'] ?? '—'); ?> &nbsp;&middot;&nbsp;
            <?php echo ucfirst($lab['difficulty']); ?> &nbsp;&middot;&nbsp;
            +<?php echo $lab['xp_reward']; ?> XP &nbsp;&middot;&nbsp;
            SSH: <?php echo h($lab['ssh_host'] ?? 'not set'); ?>:<?php echo h($lab['ssh_port'] ?? '22'); ?>
            <?php if (!$lab['is_active']): ?>&nbsp;&middot;&nbsp;<span style="color:var(--danger)">INACTIVE</span><?php endif; ?>
          </div>
        </div>
        <div class="admin-row-actions">
          <a href="?section=labs&action=edit&id=<?php echo $lab['id']; ?>" class="btn-admin-edit">Edit</a>
          <form method="POST" style="display:inline" onsubmit="return confirm('Delete lab &quot;<?php echo h($lab['title']); ?>&quot;?')">
            <input type="hidden" name="csrf"   value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="form"   value="delete_lab">
            <input type="hidden" name="lab_id" value="<?php echo $lab['id']; ?>">
            <button type="submit" class="btn-admin-delete">Delete</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($labs)): ?>
      <div style="padding:20px 18px;color:var(--text3);font-size:13px">No labs yet. <a href="?section=labs&action=add" style="color:var(--accent)">Add one</a>.</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php // ══════════════════════════════════════════════════════════════
    // QUIZ SECTION
    elseif ($section === 'quiz'): ?>

    <div class="hk-page-header">
      <div>
        <div class="hk-page-eyebrow">&#9671; Content Management</div>
        <h1 class="hk-page-title">Quiz Questions</h1>
      </div>
      <a href="?section=quiz&action=add" class="btn-primary" style="text-decoration:none">+ Add Question</a>
    </div>

    <?php if ($action === 'add'): ?>
    <!-- Add Question Form -->
    <div class="admin-form-card">
      <div class="admin-form-title">Add New Question</div>
      <form method="POST" class="admin-form">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="form" value="add_question">

        <div class="admin-form-grid">
          <div class="admin-field">
            <label class="admin-label">Category</label>
            <select name="category" class="admin-input" required>
              <option value="">— Select —</option>
              <?php foreach ($quiz_categories as $qc): ?>
              <option value="<?php echo h($qc['slug']); ?>"><?php echo h($qc['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="admin-field">
            <label class="admin-label">Tier</label>
            <div style="display:flex;gap:14px;align-items:center;padding-top:8px">
              <?php foreach ([1 => '10 XP — Easy', 2 => '20 XP — Medium', 3 => '30 XP — Hard'] as $t => $tlabel): ?>
              <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer">
                <input type="radio" name="tier" value="<?php echo $t; ?>"
                       <?php echo $t === 1 ? 'checked' : ''; ?>
                       onchange="updatePoints(<?php echo $t * 10; ?>)">
                Tier <?php echo $t; ?>
                <span style="font-family:var(--mono);font-size:11px;color:var(--text2)">(<?php echo $t*10; ?> pts)</span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="admin-field">
            <label class="admin-label">Points</label>
            <input type="number" name="points" id="points-field" class="admin-input" value="10" min="1">
          </div>

          <div class="admin-field">
            <label class="admin-label">Difficulty</label>
            <select name="difficulty" class="admin-input">
              <option value="easy">Easy</option>
              <option value="medium" selected>Medium</option>
              <option value="hard">Hard</option>
            </select>
          </div>

          <div class="admin-field">
            <label class="admin-label">Domain (CEH only, optional)</label>
            <input type="text" name="domain" class="admin-input" placeholder="Scanning Networks">
          </div>

          <div class="admin-field">
            <label class="admin-label">Domain # (optional)</label>
            <input type="number" name="domain_number" class="admin-input" min="1" max="20">
          </div>
        </div>

        <div class="admin-field">
          <label class="admin-label">Question</label>
          <textarea name="question" class="admin-input admin-textarea" rows="3" required></textarea>
        </div>

        <div class="admin-form-grid">
          <?php foreach (['a','b','c','d'] as $opt): ?>
          <div class="admin-field">
            <label class="admin-label">Option <?php echo strtoupper($opt); ?></label>
            <input type="text" name="option_<?php echo $opt; ?>" class="admin-input" required>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="admin-form-grid">
          <div class="admin-field">
            <label class="admin-label">Correct Answer</label>
            <select name="correct" class="admin-input">
              <?php foreach (['a','b','c','d'] as $opt): ?>
              <option value="<?php echo $opt; ?>"><?php echo strtoupper($opt); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="admin-field">
          <label class="admin-label">Explanation (shown after answer)</label>
          <textarea name="explanation" class="admin-input admin-textarea" rows="3"></textarea>
        </div>

        <div class="admin-form-actions">
          <a href="?section=quiz" class="btn-secondary" style="text-decoration:none">Cancel</a>
          <button type="submit" class="btn-primary">Add Question</button>
        </div>
      </form>
    </div>

    <?php else: ?>
    <!-- Per-category summary table -->
    <div class="history-table-card" style="margin-bottom:16px">
      <div class="history-table-header"><span class="history-table-title">Questions by Category &amp; Tier</span></div>
      <div style="padding:12px 16px;overflow-x:auto">
      <table class="admin-cat-summary">
        <thead>
          <tr>
            <th>Category</th>
            <th>T1</th><th>T2</th><th>T3</th><th>Total</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $all_cats_seen = array_keys($cat_sum_map);
        foreach ($all_cats_seen as $catkey):
          $t1 = $cat_sum_map[$catkey][1] ?? 0;
          $t2 = $cat_sum_map[$catkey][2] ?? 0;
          $t3 = $cat_sum_map[$catkey][3] ?? 0;
        ?>
        <tr>
          <td><?php echo h($catkey); ?></td>
          <td><span class="tier-pill tier-pill-1"><?php echo $t1; ?></span></td>
          <td><span class="tier-pill tier-pill-2"><?php echo $t2; ?></span></td>
          <td><span class="tier-pill tier-pill-3"><?php echo $t3; ?></span></td>
          <td style="font-family:var(--mono);color:var(--text2)"><?php echo $t1 + $t2 + $t3; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="admin-filter-bar">
      <input type="hidden" name="section" value="quiz">
      <span class="admin-filter-label">Filter:</span>
      <select name="cat">
        <option value="">All Categories</option>
        <?php foreach ($quiz_categories as $qc): ?>
        <option value="<?php echo h($qc['slug']); ?>" <?php echo $f_cat === $qc['slug'] ? 'selected' : ''; ?>>
          <?php echo h($qc['name']); ?>
        </option>
        <?php endforeach; ?>
      </select>
      <select name="tier">
        <option value="">All Tiers</option>
        <?php for ($t=1;$t<=3;$t++): ?>
        <option value="<?php echo $t; ?>" <?php echo $f_tier === $t ? 'selected' : ''; ?>>Tier <?php echo $t; ?></option>
        <?php endfor; ?>
      </select>
      <select name="diff">
        <option value="">All Difficulties</option>
        <?php foreach (['easy','medium','hard'] as $d): ?>
        <option value="<?php echo $d; ?>" <?php echo $f_diff === $d ? 'selected' : ''; ?>><?php echo ucfirst($d); ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-primary" style="padding:6px 16px;font-size:12px">Apply</button>
      <a href="?section=quiz" class="btn-filter-reset">Reset</a>
    </form>

    <!-- Questions list with bulk actions -->
    <form method="POST" id="bulk-form">
      <input type="hidden" name="csrf"        value="<?php echo h(csrf_token()); ?>">
      <input type="hidden" name="form"        value="bulk_questions">
      <input type="hidden" name="bulk_action" id="bulk-action-val" value="">

      <div class="admin-bulk-bar">
        <input type="checkbox" id="select-all" onchange="toggleAll(this)" title="Select all">
        <span class="admin-bulk-count" id="sel-count">0 selected</span>
        <div style="margin-left:auto;display:flex;gap:8px">
          <button type="button" class="btn-admin-edit"
                  onclick="bulkDo('activate')">Activate Selected</button>
          <button type="button" class="btn-admin-edit"
                  onclick="bulkDo('deactivate')">Deactivate Selected</button>
          <button type="button" class="btn-admin-delete"
                  onclick="bulkDo('delete')">Delete Selected</button>
        </div>
      </div>

      <div class="history-table-card">
        <div class="history-table-header">
          <span class="history-table-title">
            Questions (<?php echo count($questions); ?>)
            <?php if ($f_cat || $f_tier || $f_diff): ?>
            <span style="font-family:var(--mono);font-size:11px;color:var(--text3);margin-left:8px">filtered</span>
            <?php endif; ?>
          </span>
        </div>
        <?php foreach ($questions as $q): ?>
        <div class="admin-table-row">
          <div style="flex-shrink:0;margin-right:4px">
            <input type="checkbox" name="question_ids[]" value="<?php echo $q['id']; ?>"
                   class="q-checkbox" onchange="updateSelCount()">
          </div>
          <div class="admin-row-info">
            <div class="admin-row-title" style="font-size:13px">
              <?php echo h(mb_substr($q['question'], 0, 90)) . (mb_strlen($q['question']) > 90 ? '…' : ''); ?>
            </div>
            <div class="admin-row-meta">
              <span class="tier-pill tier-pill-<?php echo $q['tier'] ?? 1; ?>" style="margin-right:4px">
                T<?php echo $q['tier'] ?? 1; ?>
              </span>
              <?php echo h($q['category'] ?? '—'); ?> &nbsp;&middot;&nbsp;
              <?php echo ucfirst($q['difficulty']); ?> &nbsp;&middot;&nbsp;
              <?php echo $q['points'] ?? 10; ?> pts &nbsp;&middot;&nbsp;
              Ans: <strong><?php echo strtoupper($q['correct']); ?></strong>
              <?php if (!$q['is_active']): ?>&nbsp;&middot;&nbsp;<span style="color:var(--danger)">INACTIVE</span><?php endif; ?>
            </div>
          </div>
          <div class="admin-row-actions">
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf"        value="<?php echo h(csrf_token()); ?>">
              <input type="hidden" name="form"        value="toggle_question">
              <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
              <button type="submit" class="btn-admin-edit">
                <?php echo $q['is_active'] ? 'Deactivate' : 'Activate'; ?>
              </button>
            </form>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('Delete this question?')">
              <input type="hidden" name="csrf"        value="<?php echo h(csrf_token()); ?>">
              <input type="hidden" name="form"        value="delete_question">
              <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
              <button type="submit" class="btn-admin-delete">Delete</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($questions)): ?>
        <div style="padding:20px 18px;color:var(--text3);font-size:13px">No questions match your filters.</div>
        <?php endif; ?>
      </div>
    </form>
    <?php endif; ?>

    <?php // ══════════════════════════════════════════════════════════════
    // CATEGORIES SECTION
    elseif ($section === 'categories'): ?>

    <div class="hk-page-header">
      <div>
        <div class="hk-page-eyebrow">&#9674; Content Management</div>
        <h1 class="hk-page-title">Quiz Categories</h1>
      </div>
    </div>

    <?php if ($action === 'edit' && $edit_cat): ?>
    <!-- Edit Category Form -->
    <div class="admin-form-card">
      <div class="admin-form-title">Edit Category: <?php echo h($edit_cat['name']); ?></div>
      <form method="POST" class="admin-form">
        <input type="hidden" name="csrf"   value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="form"   value="edit_category">
        <input type="hidden" name="cat_id" value="<?php echo $edit_cat['id']; ?>">

        <div class="admin-form-grid">
          <div class="admin-field">
            <label class="admin-label">Name</label>
            <input type="text" name="name" class="admin-input"
                   value="<?php echo h($edit_cat['name']); ?>" required>
          </div>
          <div class="admin-field">
            <label class="admin-label">Icon (emoji)</label>
            <input type="text" name="icon" class="admin-input"
                   value="<?php echo h($edit_cat['icon'] ?? ''); ?>" maxlength="10">
          </div>
          <div class="admin-field">
            <label class="admin-label">Level Required</label>
            <input type="number" name="level_required" class="admin-input"
                   value="<?php echo (int)($edit_cat['level_required'] ?? 1); ?>" min="1">
          </div>
          <div class="admin-field">
            <label class="admin-label">Sort Order</label>
            <input type="number" name="sort_order" class="admin-input"
                   value="<?php echo (int)($edit_cat['sort_order'] ?? 0); ?>" min="0">
          </div>
        </div>

        <div class="admin-field">
          <label class="admin-label">Description</label>
          <textarea name="description" class="admin-input admin-textarea" rows="2"><?php echo h($edit_cat['description'] ?? ''); ?></textarea>
        </div>

        <div class="admin-field">
          <label class="admin-label">Intro Text (shown on category page)</label>
          <textarea name="intro_text" class="admin-input admin-textarea" rows="4"><?php echo h($edit_cat['intro_text'] ?? ''); ?></textarea>
        </div>

        <div class="admin-field">
          <label class="admin-label">Key Concepts (one per line — saved as JSON array)</label>
          <textarea name="key_concepts" class="admin-input admin-textarea" rows="6"
                    placeholder="What is SQL injection&#10;Types of XSS&#10;OWASP Top 10"><?php
            $kc = json_decode($edit_cat['key_concepts'] ?? '[]', true) ?: [];
            echo h(implode("\n", $kc));
          ?></textarea>
        </div>

        <div class="admin-form-actions">
          <a href="?section=categories" class="btn-secondary" style="text-decoration:none">Cancel</a>
          <button type="submit" class="btn-primary">Save Changes</button>
        </div>
      </form>
    </div>

    <?php else: ?>
    <!-- Categories list -->
    <div class="history-table-card">
      <div class="history-table-header">
        <span class="history-table-title">All Categories (<?php echo count($categories_all); ?>)</span>
      </div>
      <?php foreach ($categories_all as $cat): ?>
      <div class="admin-table-row">
        <div style="font-size:22px;width:32px;flex-shrink:0"><?php echo $cat['icon'] ?? '?'; ?></div>
        <div class="admin-row-info">
          <div class="admin-row-title">
            <?php echo h($cat['name']); ?>
            <span style="font-family:var(--mono);font-size:11px;color:var(--text3);margin-left:8px"><?php echo h($cat['slug']); ?></span>
          </div>
          <div class="admin-row-meta">
            Level <?php echo $cat['level_required']; ?>+ &nbsp;&middot;&nbsp;
            Sort: <?php echo $cat['sort_order']; ?> &nbsp;&middot;&nbsp;
            <?php $kc_count = count(json_decode($cat['key_concepts'] ?? '[]', true) ?: []); ?>
            <?php echo $kc_count; ?> key concept<?php echo $kc_count !== 1 ? 's' : ''; ?>
          </div>
        </div>
        <div class="admin-row-actions">
          <a href="?section=categories&action=edit&id=<?php echo $cat['id']; ?>"
             class="btn-admin-edit">Edit</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php // ══════════════════════════════════════════════════════════════
    // USERS SECTION
    elseif ($section === 'users'): ?>

    <div class="hk-page-header">
      <div>
        <div class="hk-page-eyebrow">&#9898; User Management</div>
        <h1 class="hk-page-title">Users</h1>
      </div>
    </div>

    <!-- Search bar -->
    <form method="GET" style="display:flex;gap:10px;margin-bottom:16px">
      <input type="hidden" name="section" value="users">
      <input type="text" name="search" class="admin-input" style="max-width:320px"
             placeholder="Search username or email…"
             value="<?php echo h($search ?? ''); ?>">
      <button type="submit" class="btn-primary" style="padding:9px 18px;font-size:13px">Search</button>
      <?php if (!empty($search)): ?>
      <a href="?section=users" class="btn-secondary" style="text-decoration:none;padding:9px 14px;font-size:13px">Clear</a>
      <?php endif; ?>
    </form>

    <div class="history-table-card">
      <div class="history-table-header">
        <span class="history-table-title">
          <?php echo empty($search) ? 'All Users' : 'Results for "' . h($search) . '"'; ?>
          (<?php echo count($users); ?>)
        </span>
      </div>
      <?php foreach ($users as $u): ?>
      <div class="admin-table-row">
        <div class="admin-row-info">
          <div class="admin-row-title">
            <?php echo h($u['username']); ?>
            <?php if ($u['role'] === 'admin'): ?>
            <span class="admin-badge">admin</span>
            <?php endif; ?>
          </div>
          <div class="admin-row-meta">
            <?php echo h($u['email']); ?> &nbsp;&middot;&nbsp;
            LVL <?php echo $u['level']; ?> &nbsp;&middot;&nbsp;
            <?php echo number_format($u['xp']); ?> XP &nbsp;&middot;&nbsp;
            <span title="Scans">&#128269; <?php echo $u['scan_count']; ?></span> &nbsp;&middot;&nbsp;
            <span title="Labs solved">&#9670; <?php echo $u['labs_solved']; ?></span> &nbsp;&middot;&nbsp;
            <span title="Quiz answered">&#9671; <?php echo $u['quiz_done']; ?></span> &nbsp;&middot;&nbsp;
            Joined <?php echo date('d M Y', strtotime($u['created_at'])); ?>
          </div>
        </div>
        <div class="admin-row-actions" style="flex-wrap:wrap;justify-content:flex-end">
          <button type="button" class="btn-admin-activity"
                  onclick="openUserActivity(<?php echo $u['id']; ?>, '<?php echo h($u['username']); ?>')">
            Activity
          </button>
          <button type="button" class="btn-admin-promote"
                  onclick="openXPAward(<?php echo $u['id']; ?>, '<?php echo h($u['username']); ?>')"
                  style="background:rgba(0,212,170,0.12);color:var(--accent);border-color:rgba(0,212,170,0.3)">
            +XP
          </button>
          <?php if ($u['id'] !== $admin['id']): ?>
          <?php if ($u['role'] !== 'admin'): ?>
          <form method="POST" style="display:inline"
                onsubmit="return confirm('Promote <?php echo h($u['username']); ?> to admin?')">
            <input type="hidden" name="csrf"    value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="form"    value="promote_user">
            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
            <button type="submit" class="btn-admin-promote">Promote</button>
          </form>
          <?php endif; ?>
          <form method="POST" style="display:inline"
                onsubmit="return confirm('Reset all progress for <?php echo h($u['username']); ?>?')">
            <input type="hidden" name="csrf"    value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="form"    value="reset_user">
            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
            <button type="submit" class="btn-admin-edit">Reset</button>
          </form>
          <form method="POST" style="display:inline"
                onsubmit="return confirm('Permanently delete <?php echo h($u['username']); ?>?')">
            <input type="hidden" name="csrf"    value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="form"    value="delete_user">
            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
            <button type="submit" class="btn-admin-delete">Delete</button>
          </form>
          <?php else: ?>
          <span style="font-family:var(--mono);font-size:11px;color:var(--text3)">you</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($users)): ?>
      <div style="padding:20px 18px;color:var(--text3);font-size:13px">No users found.</div>
      <?php endif; ?>
    </div>

    <?php // ══════════════════════════════════════════════════════════════
    // LOGS SECTION
    elseif ($section === 'logs'): ?>

    <div class="hk-page-header">
      <div>
        <div class="hk-page-eyebrow">&#9654; Platform</div>
        <h1 class="hk-page-title">Admin Logs</h1>
      </div>
    </div>

    <div class="history-table-card">
      <div class="history-table-header">
        <span class="history-table-title">Last 50 Actions</span>
      </div>
      <div style="overflow-x:auto;padding:8px 0">
      <table class="admin-log-table">
        <thead>
          <tr>
            <th>#</th><th>Admin</th><th>Action</th><th>Target</th><th>Detail</th><th>IP</th><th>Time</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($logs)): ?>
        <tr><td colspan="7" style="color:var(--text3);padding:20px">No admin actions logged yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($logs as $lg):
          $act = strtolower($lg['action'] ?? '');
          if (str_contains($act, 'delete'))  $acls = 'log-action-delete';
          elseif (str_contains($act, 'create')) $acls = 'log-action-create';
          elseif (str_contains($act, 'update')) $acls = 'log-action-update';
          elseif (str_contains($act, 'promote')) $acls = 'log-action-promote';
          elseif (str_contains($act, 'reset'))  $acls = 'log-action-reset';
          elseif (str_contains($act, 'bulk'))   $acls = 'log-action-bulk';
          else $acls = '';
        ?>
        <tr>
          <td style="color:var(--text3)"><?php echo $lg['id']; ?></td>
          <td><?php echo h($lg['admin_username']); ?></td>
          <td class="<?php echo $acls; ?>"><?php echo h($lg['action']); ?></td>
          <td style="color:var(--text2)"><?php echo h($lg['target_type'] ?? '—'); ?><?php echo $lg['target_id'] ? ' #' . $lg['target_id'] : ''; ?></td>
          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo h($lg['detail'] ?? ''); ?>"><?php echo h(mb_substr($lg['detail'] ?? '—', 0, 40)); ?></td>
          <td style="color:var(--text3)"><?php echo h($lg['ip'] ?? ''); ?></td>
          <td style="color:var(--text3);white-space:nowrap"><?php echo date('d M H:i', strtotime($lg['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

    <?php endif; ?>

  </main>
</div>

<!-- ── Markdown Preview Modal ───────────────────────────────────────────── -->
<div class="admin-modal-overlay" id="md-modal">
  <div class="admin-modal">
    <div class="admin-modal-header">
      Markdown Preview
      <button class="admin-modal-close" onclick="closeMdPreview()">&#10005;</button>
    </div>
    <div class="admin-modal-body" id="md-modal-body"></div>
  </div>
</div>

<!-- ── Award XP Modal ────────────────────────────────────────────────────── -->
<div class="admin-modal-overlay" id="xp-modal">
  <div class="admin-modal" style="width:min(420px,95vw)">
    <div class="admin-modal-header">
      <span id="xp-modal-title">Award XP</span>
      <button class="admin-modal-close" onclick="closeXPAward()">&#10005;</button>
    </div>
    <div class="admin-modal-body">
      <form method="POST" id="xp-award-form">
        <input type="hidden" name="csrf"    value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="form"    value="award_xp_manual">
        <input type="hidden" name="user_id" id="xp-user-id" value="">
        <div class="admin-field" style="margin-bottom:14px">
          <label class="admin-label">XP Amount</label>
          <input type="number" name="xp_amount" id="xp-amount-input" class="admin-input"
                 placeholder="e.g. 100" min="1" max="10000" required>
        </div>
        <div class="admin-field" style="margin-bottom:18px">
          <label class="admin-label">Reason (shown in XP log)</label>
          <input type="text" name="xp_reason" class="admin-input"
                 placeholder="e.g. Bug bounty reward" maxlength="120">
        </div>
        <div class="admin-form-actions" style="margin-top:0">
          <button type="button" class="btn-secondary" onclick="closeXPAward()">Cancel</button>
          <button type="submit" class="btn-primary">Award XP</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── User Activity Modal ───────────────────────────────────────────────── -->
<div class="admin-modal-overlay" id="ua-modal">
  <div class="admin-modal" style="width:min(660px,95vw)">
    <div class="admin-modal-header">
      <span id="ua-modal-title">User Activity</span>
      <button class="admin-modal-close" onclick="closeUserActivity()">&#10005;</button>
    </div>
    <div class="admin-tabs">
      <div class="admin-tab active" onclick="switchTab('ua-scans')">Scans</div>
      <div class="admin-tab" onclick="switchTab('ua-labs')">Labs</div>
      <div class="admin-tab" onclick="switchTab('ua-quiz')">Quiz</div>
    </div>
    <div class="admin-modal-body" style="padding:0">
      <div class="admin-tab-panel active" id="ua-scans"></div>
      <div class="admin-tab-panel"        id="ua-labs"></div>
      <div class="admin-tab-panel"        id="ua-quiz"></div>
    </div>
  </div>
</div>

<script>
// ── Quiz tier → points auto-fill ────────────────────────────────────────
function updatePoints(pts) {
  var f = document.getElementById('points-field');
  if (f) f.value = pts;
}

// ── Bulk question selection ─────────────────────────────────────────────
function toggleAll(cb) {
  document.querySelectorAll('.q-checkbox').forEach(function(c) { c.checked = cb.checked; });
  updateSelCount();
}
function updateSelCount() {
  var n = document.querySelectorAll('.q-checkbox:checked').length;
  document.getElementById('sel-count').textContent = n + ' selected';
  document.getElementById('select-all').indeterminate =
    n > 0 && n < document.querySelectorAll('.q-checkbox').length;
}
function bulkDo(act) {
  var checked = document.querySelectorAll('.q-checkbox:checked');
  if (!checked.length) { alert('No questions selected.'); return; }
  if (act === 'delete' && !confirm('Delete ' + checked.length + ' question(s)?')) return;
  document.getElementById('bulk-action-val').value = act;
  document.getElementById('bulk-form').submit();
}

// ── VM status check ─────────────────────────────────────────────────────
document.querySelectorAll('.vm-dot[data-host]').forEach(function(dot) {
  var host = dot.dataset.host;
  var port = dot.dataset.port || '22';
  if (!host) return;
  fetch('check_vm.php?host=' + encodeURIComponent(host) + '&port=' + encodeURIComponent(port))
    .then(function(r) { return r.json(); })
    .then(function(d) {
      dot.className = 'vm-dot vm-dot-' + (d.status === 'online' ? 'online' : (d.status === 'offline' ? 'offline' : 'unknown'));
      dot.title = 'VM ' + d.status;
    })
    .catch(function() {});
});

// ── Markdown preview modal ──────────────────────────────────────────────
function simpleMarkdown(text) {
  return text
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>')
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/^### (.+)$/gm, '<h3>$1</h3>')
    .replace(/^## (.+)$/gm,  '<h2>$1</h2>')
    .replace(/^# (.+)$/gm,   '<h1>$1</h1>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/\*([^*]+)\*/g,     '<em>$1</em>')
    .replace(/^- (.+)$/gm,  '<li>$1</li>')
    .replace(/\n\n/g, '<br><br>');
}
function openMdPreview() {
  var txt = document.getElementById('instructions-textarea');
  if (!txt) return;
  document.getElementById('md-modal-body').innerHTML = simpleMarkdown(txt.value);
  document.getElementById('md-modal').classList.add('open');
}
function closeMdPreview() {
  document.getElementById('md-modal').classList.remove('open');
}

// ── User activity modal ─────────────────────────────────────────────────
function switchTab(panelId) {
  document.querySelectorAll('.admin-tab').forEach(function(t) { t.classList.remove('active'); });
  document.querySelectorAll('.admin-tab-panel').forEach(function(p) { p.classList.remove('active'); });
  var panel = document.getElementById(panelId);
  if (panel) panel.classList.add('active');
  // Activate matching tab button
  var idx = ['ua-scans','ua-labs','ua-quiz'].indexOf(panelId);
  var tabs = document.querySelectorAll('.admin-tab');
  if (tabs[idx]) tabs[idx].classList.add('active');
}

function openUserActivity(uid, username) {
  document.getElementById('ua-modal-title').textContent = username + ' — Activity';
  document.getElementById('ua-scans').innerHTML = '<div style="padding:16px;color:var(--text3)">Loading…</div>';
  document.getElementById('ua-labs').innerHTML  = '<div style="padding:16px;color:var(--text3)">Loading…</div>';
  document.getElementById('ua-quiz').innerHTML  = '<div style="padding:16px;color:var(--text3)">Loading…</div>';
  document.getElementById('ua-modal').classList.add('open');
  // Reset to first tab
  switchTab('ua-scans');

  fetch('user_activity.php?user_id=' + uid)
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.error) {
        document.getElementById('ua-scans').innerHTML = '<div style="padding:16px;color:var(--danger)">' + d.error + '</div>';
        return;
      }
      // Scans tab
      var scansHtml = '';
      if (!d.scans || !d.scans.length) {
        scansHtml = '<div style="padding:16px;color:var(--text3)">No scans yet.</div>';
      } else {
        scansHtml = '<table style="width:100%;border-collapse:collapse;font-size:12px">' +
          '<tr style="border-bottom:1px solid var(--border)"><th style="text-align:left;padding:8px 12px;font-family:var(--mono);font-size:10px;text-transform:uppercase;color:var(--text2)">Target</th><th style="padding:8px 12px;font-family:var(--mono);font-size:10px;text-transform:uppercase;color:var(--text2)">Grade</th><th style="padding:8px 12px;font-family:var(--mono);font-size:10px;text-transform:uppercase;color:var(--text2)">Date</th></tr>';
        d.scans.forEach(function(s) {
          scansHtml += '<tr style="border-bottom:1px solid rgba(255,255,255,0.03)"><td style="padding:8px 12px;font-family:var(--mono)">' + esc(s.target_url) + '</td><td style="padding:8px 12px;text-align:center;color:var(--accent)">' + (s.grade || '—') + '</td><td style="padding:8px 12px;color:var(--text3)">' + (s.scanned_at ? s.scanned_at.substring(0,16) : '—') + '</td></tr>';
        });
        scansHtml += '</table>';
      }
      document.getElementById('ua-scans').innerHTML = scansHtml;

      // Labs tab
      var labsHtml = '';
      if (!d.labs || !d.labs.length) {
        labsHtml = '<div style="padding:16px;color:var(--text3)">No labs solved yet.</div>';
      } else {
        labsHtml = '<div>';
        d.labs.forEach(function(l) {
          labsHtml += '<div style="padding:10px 16px;border-bottom:1px solid rgba(255,255,255,0.03);font-size:13px"><div style="color:var(--text)">' + esc(l.title) + '</div><div style="font-family:var(--mono);font-size:11px;color:var(--text3);margin-top:2px">' + esc(l.difficulty) + ' &nbsp;&middot;&nbsp; solved ' + (l.solved_at ? l.solved_at.substring(0,10) : '—') + '</div></div>';
        });
        labsHtml += '</div>';
      }
      document.getElementById('ua-labs').innerHTML = labsHtml;

      // Quiz tab
      var quizHtml = '';
      if (!d.quiz || !d.quiz.length) {
        quizHtml = '<div style="padding:16px;color:var(--text3)">No quiz attempts yet.</div>';
      } else {
        quizHtml = '<div>';
        d.quiz.forEach(function(q) {
          var pct = q.total > 0 ? Math.round((q.correct / q.total) * 100) : 0;
          var clr = pct >= 70 ? 'var(--accent)' : (pct >= 50 ? '#ffd166' : 'var(--danger)');
          quizHtml += '<div style="padding:8px 16px;border-bottom:1px solid rgba(255,255,255,0.03)"><div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px"><span style="color:var(--text)">' + esc(q.category) + '</span><span style="font-family:var(--mono);color:' + clr + '">' + pct + '% (' + q.correct + '/' + q.total + ')</span></div><div style="background:var(--bg3);border-radius:3px;height:5px"><div style="width:' + pct + '%;height:5px;border-radius:3px;background:' + clr + '"></div></div></div>';
        });
        if (d.xp_from_quiz) {
          quizHtml += '<div style="padding:10px 16px;font-family:var(--mono);font-size:12px;color:var(--text2)">XP earned from quiz: <span style="color:var(--accent)">' + d.xp_from_quiz + '</span></div>';
        }
        quizHtml += '</div>';
      }
      document.getElementById('ua-quiz').innerHTML = quizHtml;
    })
    .catch(function(e) {
      document.getElementById('ua-scans').innerHTML = '<div style="padding:16px;color:var(--danger)">Failed to load activity.</div>';
    });
}
function closeUserActivity() {
  document.getElementById('ua-modal').classList.remove('open');
}
function esc(str) {
  if (!str) return '—';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Award XP modal ──────────────────────────────────────────────────────
function openXPAward(uid, username) {
  document.getElementById('xp-modal-title').textContent = 'Award XP — ' + username;
  document.getElementById('xp-user-id').value = uid;
  document.getElementById('xp-amount-input').value = '';
  document.getElementById('xp-modal').classList.add('open');
  setTimeout(function() { document.getElementById('xp-amount-input').focus(); }, 100);
}
function closeXPAward() {
  document.getElementById('xp-modal').classList.remove('open');
}

// Close modals on overlay click
document.querySelectorAll('.admin-modal-overlay').forEach(function(overlay) {
  overlay.addEventListener('click', function(e) {
    if (e.target === overlay) overlay.classList.remove('open');
  });
});
</script>
</body>
</html>
