<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'incidents';
$topbar_title = 'Incidents';

$uid = (int)$user['id'];
$pdo = db();

// Ensure tables exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS incidents (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        severity ENUM('critical','high','medium','low','info') DEFAULT 'medium',
        status ENUM('open','investigating','contained','resolved','closed') DEFAULT 'open',
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (user_id, status)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS incident_evidence (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        incident_id INT UNSIGNED NOT NULL,
        type VARCHAR(30) NOT NULL,
        ref_id INT UNSIGNED,
        title VARCHAR(255),
        detail TEXT,
        added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (incident_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS incident_notes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        incident_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        note TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (incident_id)
    )");
} catch (Exception $e) {}

// Filter
$filter = $_GET['status'] ?? 'all';
$allowed_statuses = ['open', 'investigating', 'contained', 'resolved', 'closed'];

try {
    if ($filter !== 'all' && in_array($filter, $allowed_statuses)) {
        $stmt = $pdo->prepare(
            'SELECT i.*,
                (SELECT COUNT(*) FROM incident_evidence e WHERE e.incident_id = i.id) as evidence_count
             FROM incidents i WHERE i.user_id = ? AND i.status = ?
             ORDER BY i.updated_at DESC'
        );
        $stmt->execute([$uid, $filter]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT i.*,
                (SELECT COUNT(*) FROM incident_evidence e WHERE e.incident_id = i.id) as evidence_count
             FROM incidents i WHERE i.user_id = ?
             ORDER BY i.updated_at DESC'
        );
        $stmt->execute([$uid]);
    }
    $incidents = $stmt->fetchAll();
} catch (Exception $e) {
    $incidents = [];
}

// Stats
try {
    $stats_stmt = $pdo->prepare(
        "SELECT status, COUNT(*) as cnt FROM incidents WHERE user_id = ? GROUP BY status"
    );
    $stats_stmt->execute([$uid]);
    $stats = [];
    foreach ($stats_stmt->fetchAll() as $row) {
        $stats[$row['status']] = (int)$row['cnt'];
    }
    $stats['all'] = array_sum($stats);
} catch (Exception $e) {
    $stats = [];
}

function sev_class(string $s): string {
    return match($s) {
        'critical' => 'sev-critical',
        'high'     => 'sev-high',
        'medium'   => 'sev-medium',
        'low'      => 'sev-low',
        'info'     => 'sev-info',
        default    => 'sev-info',
    };
}
function status_class(string $s): string {
    return match($s) {
        'open'         => 'status-open',
        'investigating' => 'status-investigating',
        'contained'    => 'status-contained',
        'resolved'     => 'status-resolved',
        'closed'       => 'status-closed',
        default        => 'status-open',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Incidents — HakDel</title>
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="stylesheet" href="/assets/layout.css">
  <style>
    .inc-header-actions { display: flex; align-items: center; gap: 10px; }
    .inc-filters {
      display: flex; gap: 6px; flex-wrap: wrap;
    }
    .inc-filter-btn {
      background: var(--bg2); border: 1px solid var(--border);
      color: var(--text2); font-family: var(--mono); font-size: 11px;
      padding: 6px 14px; border-radius: 20px; cursor: pointer;
      text-decoration: none; transition: all 0.12s;
      display: flex; align-items: center; gap: 5px;
    }
    .inc-filter-btn:hover { border-color: rgba(255,255,255,0.2); color: var(--text); }
    .inc-filter-btn.active { background: rgba(0,212,170,0.1); border-color: var(--accent); color: var(--accent); }
    .inc-filter-count {
      background: var(--bg4); padding: 0 5px; border-radius: 8px;
      font-size: 10px;
    }
    .inc-table-wrap {
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: var(--radius-lg); overflow: hidden;
    }
    .inc-table { width: 100%; border-collapse: collapse; }
    .inc-table th {
      font-family: var(--mono); font-size: 10px; color: var(--text3);
      text-transform: uppercase; letter-spacing: 1px;
      padding: 10px 16px; border-bottom: 1px solid var(--border);
      text-align: left; font-weight: 600; background: var(--bg3);
    }
    .inc-table td {
      padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.04);
      vertical-align: middle;
    }
    .inc-table tr:last-child td { border-bottom: none; }
    .inc-table tr.inc-row { cursor: pointer; transition: background 0.1s; }
    .inc-table tr.inc-row:hover { background: rgba(255,255,255,0.02); }
    .inc-title { font-size: 13px; font-weight: 600; color: var(--text); }
    .inc-title-sub { font-size: 11px; color: var(--text3); margin-top: 2px; }
    .sev-badge, .status-badge {
      display: inline-flex; align-items: center; justify-content: center;
      font-family: var(--mono); font-size: 10px; font-weight: 700;
      padding: 3px 8px; border-radius: 4px; text-transform: uppercase;
      letter-spacing: 0.5px; white-space: nowrap;
    }
    .sev-critical { background: rgba(220,38,38,0.15); color: #ef4444; border: 1px solid rgba(220,38,38,0.3); }
    .sev-high     { background: rgba(234,88,12,0.15); color: #f97316; border: 1px solid rgba(234,88,12,0.3); }
    .sev-medium   { background: rgba(202,138,4,0.15); color: #fbbf24; border: 1px solid rgba(202,138,4,0.3); }
    .sev-low      { background: rgba(37,99,235,0.15); color: #60a5fa; border: 1px solid rgba(37,99,235,0.3); }
    .sev-info     { background: rgba(107,114,128,0.15); color: #9ca3af; border: 1px solid rgba(107,114,128,0.3); }
    .status-open         { background: rgba(239,68,68,0.12); color: #f87171; border: 1px solid rgba(239,68,68,0.25); }
    .status-investigating { background: rgba(251,146,60,0.12); color: #fb923c; border: 1px solid rgba(251,146,60,0.25); }
    .status-contained    { background: rgba(250,204,21,0.12); color: #fbbf24; border: 1px solid rgba(250,204,21,0.25); }
    .status-resolved     { background: rgba(0,212,170,0.12); color: var(--accent); border: 1px solid rgba(0,212,170,0.25); }
    .status-closed       { background: rgba(107,114,128,0.12); color: #9ca3af; border: 1px solid rgba(107,114,128,0.25); }
    .inc-evidence-count {
      font-family: var(--mono); font-size: 12px; color: var(--text3);
    }
    .inc-date { font-family: var(--mono); font-size: 10px; color: var(--text3); white-space: nowrap; }
    .inc-empty {
      padding: 60px 20px; text-align: center;
    }
    .inc-empty-icon { font-size: 40px; margin-bottom: 12px; }
    .inc-empty-text { font-size: 14px; color: var(--text3); margin-bottom: 16px; }
    .btn-new-inc {
      display: inline-flex; align-items: center; gap: 7px;
      background: var(--accent); color: var(--bg); border: none;
      border-radius: var(--radius); padding: 10px 20px;
      font-family: var(--mono); font-size: 13px; font-weight: 700;
      text-decoration: none; cursor: pointer; transition: opacity 0.15s;
    }
    .btn-new-inc:hover { opacity: 0.85; }
    .stat-bar {
      display: flex; gap: 0;
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: var(--radius-lg); overflow: hidden;
    }
    .stat-item {
      flex: 1; padding: 14px 16px;
      border-right: 1px solid var(--border);
      display: flex; flex-direction: column; gap: 3px;
    }
    .stat-item:last-child { border-right: none; }
    .stat-label { font-family: var(--mono); font-size: 10px; color: var(--text3); text-transform: uppercase; }
    .stat-val { font-family: var(--mono); font-size: 20px; font-weight: 700; }
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
      <h1 class="hk-page-title">Incident Tracker</h1>
      <p class="hk-page-sub">Track and investigate security incidents</p>
    </div>
    <div class="hk-page-actions">
      <a href="/incidents/create.php" class="btn-new-inc">+ New Incident</a>
    </div>
  </div>

  <!-- Stats bar -->
  <div class="stat-bar">
    <div class="stat-item">
      <div class="stat-label">Total</div>
      <div class="stat-val" style="color:var(--text)"><?php echo $stats['all'] ?? 0; ?></div>
    </div>
    <div class="stat-item">
      <div class="stat-label">Open</div>
      <div class="stat-val" style="color:#f87171"><?php echo $stats['open'] ?? 0; ?></div>
    </div>
    <div class="stat-item">
      <div class="stat-label">Investigating</div>
      <div class="stat-val" style="color:#fb923c"><?php echo $stats['investigating'] ?? 0; ?></div>
    </div>
    <div class="stat-item">
      <div class="stat-label">Contained</div>
      <div class="stat-val" style="color:#fbbf24"><?php echo $stats['contained'] ?? 0; ?></div>
    </div>
    <div class="stat-item">
      <div class="stat-label">Resolved</div>
      <div class="stat-val" style="color:var(--accent)"><?php echo $stats['resolved'] ?? 0; ?></div>
    </div>
  </div>

  <!-- Filters -->
  <div class="inc-filters">
    <a href="/incidents/" class="inc-filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">
      All <span class="inc-filter-count"><?php echo $stats['all'] ?? 0; ?></span>
    </a>
    <a href="/incidents/?status=open" class="inc-filter-btn <?php echo $filter === 'open' ? 'active' : ''; ?>">
      Open <span class="inc-filter-count"><?php echo $stats['open'] ?? 0; ?></span>
    </a>
    <a href="/incidents/?status=investigating" class="inc-filter-btn <?php echo $filter === 'investigating' ? 'active' : ''; ?>">
      Investigating <span class="inc-filter-count"><?php echo $stats['investigating'] ?? 0; ?></span>
    </a>
    <a href="/incidents/?status=contained" class="inc-filter-btn <?php echo $filter === 'contained' ? 'active' : ''; ?>">
      Contained <span class="inc-filter-count"><?php echo $stats['contained'] ?? 0; ?></span>
    </a>
    <a href="/incidents/?status=resolved" class="inc-filter-btn <?php echo $filter === 'resolved' ? 'active' : ''; ?>">
      Resolved <span class="inc-filter-count"><?php echo $stats['resolved'] ?? 0; ?></span>
    </a>
    <a href="/incidents/?status=closed" class="inc-filter-btn <?php echo $filter === 'closed' ? 'active' : ''; ?>">
      Closed <span class="inc-filter-count"><?php echo $stats['closed'] ?? 0; ?></span>
    </a>
  </div>

  <!-- Incidents table -->
  <div class="inc-table-wrap">
    <?php if (empty($incidents)): ?>
    <div class="inc-empty">
      <div class="inc-empty-icon">&#128203;</div>
      <div class="inc-empty-text">
        <?php if ($filter !== 'all'): ?>
          No <?php echo h($filter); ?> incidents found.
        <?php else: ?>
          No incidents yet. Create your first incident to start tracking.
        <?php endif; ?>
      </div>
      <a href="/incidents/create.php" class="btn-new-inc">+ New Incident</a>
    </div>
    <?php else: ?>
    <table class="inc-table">
      <thead>
        <tr>
          <th>Title</th>
          <th>Severity</th>
          <th>Status</th>
          <th>Evidence</th>
          <th>Created</th>
          <th>Updated</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($incidents as $inc): ?>
        <tr class="inc-row" onclick="window.location='/incidents/view.php?id=<?php echo (int)$inc['id']; ?>'">
          <td>
            <div class="inc-title"><?php echo h($inc['title']); ?></div>
            <?php if ($inc['description']): ?>
            <div class="inc-title-sub"><?php echo h(mb_strimwidth(strip_tags($inc['description']), 0, 60, '...')); ?></div>
            <?php endif; ?>
          </td>
          <td><span class="sev-badge <?php echo sev_class($inc['severity']); ?>"><?php echo h($inc['severity']); ?></span></td>
          <td><span class="status-badge <?php echo status_class($inc['status']); ?>"><?php echo h($inc['status']); ?></span></td>
          <td class="inc-evidence-count"><?php echo (int)$inc['evidence_count']; ?> items</td>
          <td class="inc-date"><?php echo date('M j, Y', strtotime($inc['created_at'])); ?></td>
          <td class="inc-date"><?php echo date('M j H:i', strtotime($inc['updated_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</main>
</div>
</body>
</html>
