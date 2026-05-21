<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'dashboard';
$topbar_title = 'Dashboard';

$pdo = db();
$uid = (int)$user['id'];

// ── Recent scans (last 5) ──────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT id, target_url, score, grade, scanned_at FROM scans WHERE user_id = ? AND status = "done" ORDER BY scanned_at DESC LIMIT 5');
$stmt->execute([$uid]);
$recent_scans = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT COUNT(*), COALESCE(AVG(score),0), COALESCE(MAX(score),0) FROM scans WHERE user_id = ? AND status = "done"');
$stmt->execute([$uid]);
[$total_scans, $avg_score, $best_score] = $stmt->fetch(PDO::FETCH_NUM);
$avg_score  = (int)round($avg_score);
$best_score = (int)$best_score;

// ── Watchlist alerts (tables may not exist if user hasn't visited tools yet) ──
$alerts = []; $alert_count = 0; $wl_count = 0;
try {
    $stmt = $pdo->prepare('
        SELECT wa.id, wa.alert_type, wa.message, wa.created_at, w.domain
        FROM watchlist_alerts wa
        JOIN watchlist w ON w.id = wa.watchlist_id
        WHERE w.user_id = ? AND wa.is_read = 0
        ORDER BY wa.created_at DESC LIMIT 5
    ');
    $stmt->execute([$uid]);
    $alerts      = $stmt->fetchAll();
    $alert_count = count($alerts);

    $s = $pdo->prepare('SELECT COUNT(*) FROM watchlist WHERE user_id = ? AND is_active = 1');
    $s->execute([$uid]);
    $wl_count = (int)$s->fetchColumn();
} catch (Exception $e) {}

// ── Open incidents ─────────────────────────────────────────────────────────
$open_incidents = 0;
$recent_incidents = [];
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM incidents WHERE user_id = ? AND status IN ('open','investigating')");
    $s->execute([$uid]);
    $open_incidents = (int)$s->fetchColumn();

    $s = $pdo->prepare(
        "SELECT id, title, severity, status, updated_at
         FROM incidents WHERE user_id = ? AND status IN ('open','investigating','contained')
         ORDER BY updated_at DESC LIMIT 3"
    );
    $s->execute([$uid]);
    $recent_incidents = $s->fetchAll();
} catch (Exception $e) {}

// ── Tool usage ─────────────────────────────────────────────────────────────
$ip_count = $hash_count = $cve_count = 0;
try { $s = $pdo->prepare('SELECT COUNT(*) FROM ip_checks   WHERE user_id = ?'); $s->execute([$uid]); $ip_count   = (int)$s->fetchColumn(); } catch (Exception $e) {}
try { $s = $pdo->prepare('SELECT COUNT(*) FROM hash_checks WHERE user_id = ?'); $s->execute([$uid]); $hash_count = (int)$s->fetchColumn(); } catch (Exception $e) {}
try { $s = $pdo->prepare('SELECT COUNT(*) FROM cve_lookups  WHERE user_id = ?'); $s->execute([$uid]); $cve_count  = (int)$s->fetchColumn(); } catch (Exception $e) {}

// ── XP log (last 5 events) ─────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT amount, description, created_at FROM xp_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
$stmt->execute([$uid]);
$xp_log = $stmt->fetchAll();

// ── Labs solved ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT COUNT(*) FROM lab_attempts WHERE user_id = ? AND status = "solved"');
$stmt->execute([$uid]);
$labs_solved = (int)$stmt->fetchColumn();

// ── Grade colour helper ────────────────────────────────────────────────────
function dash_grade_class(string $g): string {
    return match(strtoupper($g)) {
        'A+','A' => 'grade-a',
        'B'      => 'grade-b',
        'C'      => 'grade-c',
        'D'      => 'grade-d',
        default  => 'grade-f',
    };
}

function dash_alert_icon(string $type): string {
    return match($type) {
        'ssl_expired' => '&#128274;',
        'ssl_expiry'  => '&#9200;',
        'dns_change'  => '&#127760;',
        default       => '&#9888;',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard — HakDel</title>
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="stylesheet" href="/assets/layout.css">
  <style>
    /* ── Dashboard grid ── */
    .dash-grid {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      grid-template-rows: auto;
      gap: 16px;
    }
    .dash-card {
      background: var(--bg2);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      overflow: hidden;
    }
    .dash-card-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 14px 18px;
      border-bottom: 1px solid var(--border);
    }
    .dash-card-title {
      font-family: var(--mono); font-size: 12px; font-weight: 700;
      color: var(--text); letter-spacing: 0.5px;
      display: flex; align-items: center; gap: 7px;
    }
    .dash-card-link {
      font-family: var(--mono); font-size: 11px; color: var(--accent);
      text-decoration: none; opacity: 0.8;
    }
    .dash-card-link:hover { opacity: 1; }
    .dash-card-body { padding: 18px; }

    /* ── Stat bar (top row) ── */
    .dash-stats {
      grid-column: 1 / 4;
      display: grid; grid-template-columns: repeat(7, 1fr); gap: 0;
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: var(--radius-lg); overflow: hidden;
    }
    .dash-stat {
      padding: 18px 16px;
      border-right: 1px solid var(--border);
      display: flex; flex-direction: column; gap: 4px;
    }
    .dash-stat:last-child { border-right: none; }
    .dash-stat-label { font-family: var(--mono); font-size: 10px; color: var(--text3); text-transform: uppercase; letter-spacing: 1px; }
    .dash-stat-value { font-family: var(--mono); font-size: 22px; font-weight: 700; color: var(--accent); }
    .dash-stat-sub   { font-size: 11px; color: var(--text3); }

    /* ── XP card ── */
    .dash-xp { grid-column: 1 / 2; }
    .xp-level-big {
      font-family: var(--mono); font-size: 48px; font-weight: 700;
      color: var(--accent); line-height: 1;
    }
    .xp-level-label { font-size: 12px; color: var(--text3); margin-top: 4px; }
    .xp-progress-wrap { margin-top: 16px; }
    .xp-progress-label {
      display: flex; justify-content: space-between;
      font-family: var(--mono); font-size: 11px; color: var(--text3);
      margin-bottom: 6px;
    }
    .xp-progress-bar {
      height: 6px; background: var(--bg4); border-radius: 3px; overflow: hidden;
    }
    .xp-progress-fill {
      height: 100%; border-radius: 3px;
      background: linear-gradient(90deg, var(--accent), var(--accent2));
      transition: width 0.6s ease;
    }
    .xp-log-list { margin-top: 16px; display: flex; flex-direction: column; gap: 8px; }
    .xp-log-item {
      display: flex; align-items: center; justify-content: space-between;
      font-size: 12px; color: var(--text2);
    }
    .xp-log-amount { font-family: var(--mono); color: var(--accent); font-weight: 700; font-size: 13px; }
    .xp-log-time   { font-family: var(--mono); font-size: 10px; color: var(--text3); }

    /* ── Alerts card ── */
    .dash-alerts { grid-column: 2 / 4; }
    .alert-list { display: flex; flex-direction: column; gap: 0; }
    .alert-item {
      display: flex; align-items: flex-start; gap: 12px;
      padding: 12px 18px; border-bottom: 1px solid var(--border);
    }
    .alert-item:last-child { border-bottom: none; }
    .alert-icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
    .alert-body { flex: 1; min-width: 0; }
    .alert-domain { font-family: var(--mono); font-size: 12px; color: var(--accent); }
    .alert-msg    { font-size: 12px; color: var(--text2); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .alert-time   { font-family: var(--mono); font-size: 10px; color: var(--text3); flex-shrink: 0; }
    .alert-badge-ssl  { color: #f59e0b; }
    .alert-badge-dns  { color: var(--accent); }
    .alert-badge-exp  { color: var(--danger); }
    .dash-no-alerts {
      padding: 28px; text-align: center;
      font-size: 13px; color: var(--text3);
    }
    .dash-no-alerts-icon { font-size: 28px; margin-bottom: 8px; }

    /* ── Recent scans ── */
    .dash-scans { grid-column: 1 / 3; }
    .scan-list { display: flex; flex-direction: column; gap: 0; }
    .scan-item {
      display: flex; align-items: center; gap: 12px;
      padding: 10px 18px; border-bottom: 1px solid var(--border);
      text-decoration: none; transition: background 0.1s;
    }
    .scan-item:last-child { border-bottom: none; }
    .scan-item:hover { background: rgba(255,255,255,0.02); }
    .scan-grade-pill {
      width: 32px; height: 32px; border-radius: 6px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-family: var(--mono); font-size: 12px; font-weight: 700;
    }
    .grade-a { background: rgba(0,212,170,0.12); color: var(--accent); }
    .grade-b { background: rgba(96,165,250,0.12); color: #60a5fa; }
    .grade-c { background: rgba(251,191,36,0.12); color: #fbbf24; }
    .grade-d { background: rgba(251,146,60,0.12); color: #f97316; }
    .grade-f { background: rgba(255,77,77,0.12); color: var(--danger); }
    .scan-url  { font-size: 13px; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .scan-score { font-family: var(--mono); font-size: 13px; color: var(--text2); flex-shrink: 0; }
    .scan-time  { font-family: var(--mono); font-size: 10px; color: var(--text3); flex-shrink: 0; }
    .dash-no-scans { padding: 28px; text-align: center; font-size: 13px; color: var(--text3); }

    /* ── Quick actions ── */
    .dash-quick { grid-column: 3 / 4; }
    .quick-list { display: flex; flex-direction: column; gap: 8px; padding: 14px; }
    .quick-item {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px; border-radius: var(--radius);
      background: var(--bg3); border: 1px solid var(--border);
      text-decoration: none; font-size: 13px; color: var(--text2);
      transition: border-color 0.12s, color 0.12s, background 0.12s;
    }
    .quick-item:hover { border-color: var(--accent); color: var(--text); background: rgba(0,212,170,0.04); }
    .quick-item-icon { font-size: 16px; width: 20px; text-align: center; flex-shrink: 0; }
    .quick-item-label { flex: 1; font-weight: 500; }
    .quick-item-arrow { font-size: 12px; color: var(--text3); }

    /* ── Incidents ── */
    .dash-incidents { grid-column: 1 / 4; }
    .inc-sev-critical { color: #ef4444; background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.2); }
    .inc-sev-high     { color: #f97316; background: rgba(249,115,22,0.08); border: 1px solid rgba(249,115,22,0.2); }
    .inc-sev-medium   { color: #fbbf24; background: rgba(251,191,36,0.08); border: 1px solid rgba(251,191,36,0.2); }
    .inc-sev-low      { color: #60a5fa; background: rgba(96,165,250,0.08); border: 1px solid rgba(96,165,250,0.2); }
    .inc-sev-info     { color: #9ca3af; background: rgba(156,163,175,0.08); border: 1px solid rgba(156,163,175,0.2); }
    .inc-status-open  { color: #f87171; }
    .inc-status-investigating { color: #fb923c; }
    .inc-status-contained { color: #fbbf24; }
    .dash-inc-row {
      display: flex; align-items: center; gap: 12px;
      padding: 10px 18px; border-bottom: 1px solid var(--border);
      text-decoration: none; transition: background 0.1s;
    }
    .dash-inc-row:last-child { border-bottom: none; }
    .dash-inc-row:hover { background: rgba(255,255,255,0.02); }
    .dash-inc-sev {
      font-family: var(--mono); font-size: 9px; font-weight: 700;
      padding: 2px 6px; border-radius: 3px; text-transform: uppercase; flex-shrink: 0;
    }
    .dash-inc-title { flex: 1; font-size: 12px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .dash-inc-status { font-family: var(--mono); font-size: 10px; flex-shrink: 0; }
    .dash-inc-date { font-family: var(--mono); font-size: 10px; color: var(--text3); flex-shrink: 0; }

    /* ── Tools usage ── */
    .dash-tools { grid-column: 1 / 4; }
    .tools-usage-row {
      display: grid; grid-template-columns: repeat(4, 1fr); gap: 0;
    }
    .tool-usage-item {
      display: flex; align-items: center; gap: 14px;
      padding: 16px 18px; border-right: 1px solid var(--border);
      text-decoration: none; transition: background 0.12s;
    }
    .tool-usage-item:last-child { border-right: none; }
    .tool-usage-item:hover { background: rgba(0,212,170,0.03); }
    .tool-usage-icon { font-size: 22px; flex-shrink: 0; }
    .tool-usage-name  { font-family: var(--mono); font-size: 12px; color: var(--text); font-weight: 600; }
    .tool-usage-count { font-size: 11px; color: var(--text3); margin-top: 2px; }

    @media (max-width: 900px) {
      .dash-grid    { grid-template-columns: 1fr; }
      .dash-stats   { grid-column: 1; grid-template-columns: repeat(4, 1fr); }
      .dash-xp      { grid-column: 1; }
      .dash-alerts  { grid-column: 1; }
      .dash-scans   { grid-column: 1; }
      .dash-quick   { grid-column: 1; }
      .dash-tools   { grid-column: 1; }
      .dash-incidents { grid-column: 1; }
      .tools-usage-row { grid-template-columns: repeat(2, 1fr); }
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
      <div class="hk-page-eyebrow">OVERVIEW</div>
      <h1 class="hk-page-title">Welcome back, <?php echo htmlspecialchars($user['username']); ?></h1>
      <p class="hk-page-sub"><?php echo date('l, F j, Y'); ?></p>
    </div>
    <div class="hk-page-actions">
      <a href="/scanner/" class="btn-primary" style="text-decoration:none">&#9654; New Scan</a>
    </div>
  </div>

  <div class="dash-grid">

    <!-- ── Top stat bar ─────────────────────────────────────── -->
    <div class="dash-stats">
      <div class="dash-stat">
        <div class="dash-stat-label">Total Scans</div>
        <div class="dash-stat-value"><?php echo $total_scans; ?></div>
        <div class="dash-stat-sub">all time</div>
      </div>
      <div class="dash-stat">
        <div class="dash-stat-label">Avg Score</div>
        <div class="dash-stat-value"><?php echo $avg_score; ?></div>
        <div class="dash-stat-sub">out of 100</div>
      </div>
      <div class="dash-stat">
        <div class="dash-stat-label">Best Score</div>
        <div class="dash-stat-value"><?php echo $best_score; ?></div>
        <div class="dash-stat-sub">personal best</div>
      </div>
      <div class="dash-stat">
        <div class="dash-stat-label">Labs Solved</div>
        <div class="dash-stat-value"><?php echo $labs_solved; ?></div>
        <div class="dash-stat-sub">challenges</div>
      </div>
      <div class="dash-stat">
        <div class="dash-stat-label">Watched</div>
        <div class="dash-stat-value"><?php echo $wl_count; ?></div>
        <div class="dash-stat-sub">domains</div>
      </div>
      <div class="dash-stat">
        <div class="dash-stat-label">Alerts</div>
        <div class="dash-stat-value" style="<?php echo $alert_count > 0 ? 'color:var(--danger)' : ''; ?>"><?php echo $alert_count; ?></div>
        <div class="dash-stat-sub">unread</div>
      </div>
      <div class="dash-stat">
        <div class="dash-stat-label">Open Incidents</div>
        <div class="dash-stat-value" style="<?php echo $open_incidents > 0 ? 'color:#f97316' : ''; ?>"><?php echo $open_incidents; ?></div>
        <div class="dash-stat-sub"><a href="/incidents/" style="color:var(--accent);text-decoration:none;font-size:10px">view all</a></div>
      </div>
    </div>

    <!-- ── XP / Level card ──────────────────────────────────── -->
    <div class="dash-card dash-xp">
      <div class="dash-card-header">
        <div class="dash-card-title">&#9733; XP Progress</div>
        <a href="/profile/" class="dash-card-link">Profile &#8594;</a>
      </div>
      <div class="dash-card-body">
        <div class="xp-level-big"><?php echo $level; ?></div>
        <div class="xp-level-label">Current Level &nbsp;·&nbsp; <?php echo number_format((int)$user['xp']); ?> XP total</div>
        <div class="xp-progress-wrap">
          <div class="xp-progress-label">
            <span><?php echo number_format($xp_data['current']); ?> XP</span>
            <span><?php echo number_format($xp_data['next']); ?> XP</span>
          </div>
          <div class="xp-progress-bar">
            <div class="xp-progress-fill" style="width:<?php echo $xp_data['progress']; ?>%"></div>
          </div>
        </div>
        <?php if ($xp_log): ?>
        <div class="xp-log-list">
          <?php foreach ($xp_log as $e): ?>
          <div class="xp-log-item">
            <span><?php echo htmlspecialchars($e['description'] ?? 'XP awarded'); ?></span>
            <div style="display:flex;align-items:center;gap:10px;flex-shrink:0">
              <span class="xp-log-amount">+<?php echo $e['amount']; ?></span>
              <span class="xp-log-time"><?php echo date('M j', strtotime($e['created_at'])); ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Watchlist alerts ──────────────────────────────────── -->
    <div class="dash-card dash-alerts">
      <div class="dash-card-header">
        <div class="dash-card-title">
          &#128204; Watchlist Alerts
          <?php if ($alert_count > 0): ?>
          <span style="background:var(--danger);color:#fff;font-size:10px;padding:1px 6px;border-radius:8px;font-family:var(--mono)"><?php echo $alert_count; ?></span>
          <?php endif; ?>
        </div>
        <a href="/tools/watchlist.php" class="dash-card-link">View all &#8594;</a>
      </div>
      <?php if ($alerts): ?>
      <div class="alert-list">
        <?php foreach ($alerts as $a): ?>
        <div class="alert-item">
          <div class="alert-icon <?php
            echo str_contains($a['alert_type'], 'ssl_expired') ? 'alert-badge-exp'
               : (str_contains($a['alert_type'], 'ssl') ? 'alert-badge-ssl' : 'alert-badge-dns');
          ?>"><?php echo dash_alert_icon($a['alert_type']); ?></div>
          <div class="alert-body">
            <div class="alert-domain"><?php echo htmlspecialchars($a['domain']); ?></div>
            <div class="alert-msg"><?php echo htmlspecialchars($a['message']); ?></div>
          </div>
          <div class="alert-time"><?php echo date('M j', strtotime($a['created_at'])); ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="dash-no-alerts">
        <div class="dash-no-alerts-icon">&#10003;</div>
        No unread alerts
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Recent scans ──────────────────────────────────────── -->
    <div class="dash-card dash-scans">
      <div class="dash-card-header">
        <div class="dash-card-title">&#9783; Recent Scans</div>
        <a href="/scanner/history.php" class="dash-card-link">History &#8594;</a>
      </div>
      <?php if ($recent_scans): ?>
      <div class="scan-list">
        <?php foreach ($recent_scans as $s): ?>
        <a href="/scanner/?scan_id=<?php echo $s['id']; ?>" class="scan-item">
          <div class="scan-grade-pill <?php echo dash_grade_class($s['grade']); ?>"><?php echo htmlspecialchars($s['grade']); ?></div>
          <div class="scan-url"><?php echo htmlspecialchars($s['target_url']); ?></div>
          <div class="scan-score"><?php echo $s['score']; ?>/100</div>
          <div class="scan-time"><?php echo date('M j', strtotime($s['scanned_at'])); ?></div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="dash-no-scans">No scans yet — <a href="/scanner/" style="color:var(--accent)">run your first scan</a></div>
      <?php endif; ?>
    </div>

    <!-- ── Quick actions ─────────────────────────────────────── -->
    <div class="dash-card dash-quick">
      <div class="dash-card-header">
        <div class="dash-card-title">&#9658; Quick Actions</div>
      </div>
      <div class="quick-list">
        <a href="/scanner/" class="quick-item">
          <span class="quick-item-icon">&#9632;</span>
          <span class="quick-item-label">New Scan</span>
          <span class="quick-item-arrow">&#8594;</span>
        </a>
        <a href="/tools/ip_check.php" class="quick-item">
          <span class="quick-item-icon">&#127760;</span>
          <span class="quick-item-label">IP Checker</span>
          <span class="quick-item-arrow">&#8594;</span>
        </a>
        <a href="/tools/hash_check.php" class="quick-item">
          <span class="quick-item-icon">&#128273;</span>
          <span class="quick-item-label">Hash Lookup</span>
          <span class="quick-item-arrow">&#8594;</span>
        </a>
        <a href="/tools/cve_check.php" class="quick-item">
          <span class="quick-item-icon">&#9888;</span>
          <span class="quick-item-label">CVE Lookup</span>
          <span class="quick-item-arrow">&#8594;</span>
        </a>
        <a href="/tools/watchlist.php" class="quick-item">
          <span class="quick-item-icon">&#128204;</span>
          <span class="quick-item-label">Watchlist</span>
          <span class="quick-item-arrow">&#8594;</span>
        </a>
        <a href="/labs/" class="quick-item">
          <span class="quick-item-icon">&#9670;</span>
          <span class="quick-item-label">Labs</span>
          <span class="quick-item-arrow">&#8594;</span>
        </a>
      </div>
    </div>

    <!-- ── Recent Incidents ──────────────────────────────────── -->
    <div class="dash-card dash-incidents">
      <div class="dash-card-header">
        <div class="dash-card-title">
          &#128203; Open Incidents
          <?php if ($open_incidents > 0): ?>
          <span style="background:rgba(249,115,22,0.15);color:#fb923c;font-size:10px;padding:1px 6px;border-radius:8px;font-family:var(--mono)"><?php echo $open_incidents; ?></span>
          <?php endif; ?>
        </div>
        <a href="/incidents/" class="dash-card-link">All incidents &#8594;</a>
      </div>
      <?php if ($recent_incidents): ?>
      <?php foreach ($recent_incidents as $inc): ?>
      <a href="/incidents/view.php?id=<?php echo (int)$inc['id']; ?>" class="dash-inc-row">
        <span class="dash-inc-sev inc-sev-<?php echo h($inc['severity']); ?>"><?php echo h($inc['severity']); ?></span>
        <span class="dash-inc-title"><?php echo h($inc['title']); ?></span>
        <span class="dash-inc-status inc-status-<?php echo h($inc['status']); ?>"><?php echo h($inc['status']); ?></span>
        <span class="dash-inc-date"><?php echo date('M j', strtotime($inc['updated_at'])); ?></span>
      </a>
      <?php endforeach; ?>
      <?php else: ?>
      <div style="padding:24px;text-align:center;font-size:13px;color:var(--text3)">
        No active incidents. <a href="/incidents/create.php" style="color:var(--accent)">Create one</a>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Tool usage bar ────────────────────────────────────── -->
    <div class="dash-card dash-tools">
      <div class="dash-card-header">
        <div class="dash-card-title">&#9881; Your Tool Usage</div>
        <a href="/tools/" class="dash-card-link">All tools &#8594;</a>
      </div>
      <div class="tools-usage-row">
        <a href="/tools/ip_check.php" class="tool-usage-item">
          <span class="tool-usage-icon">&#127760;</span>
          <div>
            <div class="tool-usage-name">IP Checker</div>
            <div class="tool-usage-count"><?php echo $ip_count; ?> lookups</div>
          </div>
        </a>
        <a href="/tools/hash_check.php" class="tool-usage-item">
          <span class="tool-usage-icon">&#128273;</span>
          <div>
            <div class="tool-usage-name">Hash Lookup</div>
            <div class="tool-usage-count"><?php echo $hash_count; ?> checks</div>
          </div>
        </a>
        <a href="/tools/cve_check.php" class="tool-usage-item">
          <span class="tool-usage-icon">&#9888;</span>
          <div>
            <div class="tool-usage-name">CVE Lookup</div>
            <div class="tool-usage-count"><?php echo $cve_count; ?> lookups</div>
          </div>
        </a>
        <a href="/tools/watchlist.php" class="tool-usage-item">
          <span class="tool-usage-icon">&#128204;</span>
          <div>
            <div class="tool-usage-name">Watchlist</div>
            <div class="tool-usage-count"><?php echo $wl_count; ?> domains active</div>
          </div>
        </a>
      </div>
    </div>

  </div><!-- /.dash-grid -->

</main>
</div>
</body>
</html>
