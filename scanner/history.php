<?php
require_once __DIR__ . '/../config/app.php';
$user    = require_login();
$xp_data = xp_progress((int)$user['xp']);
$level   = $xp_data['level'];
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$stmt = db()->prepare('
    SELECT id, job_id, target_url, profile, score, grade, summary, scanned_at
    FROM scans
    WHERE user_id = ? AND status = "done"
    ORDER BY scanned_at DESC
    LIMIT 50
');
$stmt->execute([$user['id']]);
$scans = $stmt->fetchAll();

$total_scans = count($scans);
$avg_score   = $total_scans > 0 ? round(array_sum(array_column($scans, 'score')) / $total_scans) : 0;
$best_score  = $total_scans > 0 ? max(array_column($scans, 'score')) : 0;
$crit_sites  = count(array_filter($scans, fn($s) => $s['score'] < 35));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HakDel - History</title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/layout.css">
</head>
<body>

<?php require __DIR__ . '/../partials/topbar.php'; ?>

<div class="hk-shell">
  <?php
$nav_active  = 'history';
$sidebar_sub = 'Scan History';
require __DIR__ . '/../partials/sidebar.php';
?>

  <main class="hk-main">

    <div class="hk-page-header">
      <div>
        <div class="hk-page-eyebrow">&#9783; Scan History &nbsp;&middot;&nbsp; <?php echo htmlspecialchars($user['username']); ?></div>
        <h1 class="hk-page-title">Past Scans</h1>
        <p class="hk-page-sub"><?php echo $total_scans; ?> scan<?php echo $total_scans !== 1 ? 's' : ''; ?> on record.</p>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <button class="btn-secondary" id="btn-compare" onclick="startCompare()" style="display:none">&#8644; Compare Selected</button>
        <a href="/scanner/" class="btn-primary" style="text-decoration:none;">&#9654; New Scan</a>
      </div>
    </div>

    <?php if ($total_scans === 0): ?>
    <div class="history-empty">
      <div class="empty-icon">&#9783;</div>
      <div class="empty-title">No scans yet</div>
      <p class="empty-sub">Run your first scan and the results will appear here.</p>
      <a href="/scanner/" class="btn-primary" style="text-decoration:none;display:inline-block;margin-top:16px;">&#9654; Start Scanning</a>
    </div>

    <?php else: ?>

    <!-- Stats -->
    <div class="history-stats">
      <div class="hstat-card">
        <div class="hstat-num"><?php echo $total_scans; ?></div>
        <div class="hstat-label">Total Scans</div>
      </div>
      <div class="hstat-card">
        <div class="hstat-num" style="color:var(--accent2)"><?php echo $avg_score; ?></div>
        <div class="hstat-label">Avg Score</div>
      </div>
      <div class="hstat-card">
        <div class="hstat-num" style="color:var(--accent)"><?php echo $best_score; ?></div>
        <div class="hstat-label">Best Score</div>
      </div>
      <div class="hstat-card">
        <div class="hstat-num" style="color:var(--danger)"><?php echo $crit_sites; ?></div>
        <div class="hstat-label">Critical Sites</div>
      </div>
    </div>

    <!-- Scan table -->
    <div class="history-table-card">
      <div class="history-table-header">
        <span class="history-table-title">Scan Records</span>
        <span class="history-table-meta">Newest first &nbsp;&middot;&nbsp; Last 50</span>
      </div>

      <!-- Column headers -->
      <div class="history-col-header">
        <span class="hcol-check"></span>
        <span class="hcol-score">Score</span>
        <span class="hcol-target">Target</span>
        <span class="hcol-profile">Profile</span>
        <span class="hcol-date">Date</span>
        <span class="hcol-grade">Grade</span>
        <span class="hcol-action"></span>
      </div>

      <div class="history-table-body">
        <?php foreach ($scans as $scan):
          $score     = (int)$scan['score'];
          $grade     = $scan['grade'];
          $sc        = $score >= 75 ? 'score-a' : ($score >= 50 ? 'score-c' : 'score-f');
          $date      = date('d M Y', strtotime($scan['scanned_at']));
          $time      = date('H:i', strtotime($scan['scanned_at']));
          $profile   = ucfirst($scan['profile']);
          $target    = $scan['target_url'];
          $short     = strlen($target) > 40 ? substr($target, 0, 40) . '...' : $target;
        ?>
        <div class="history-row" onclick="viewScan('<?php echo $scan['id']; ?>')">
          <span class="hcol-check" onclick="event.stopPropagation()">
            <input type="checkbox" class="compare-check" value="<?php echo $scan['id']; ?>" onchange="handleCompareCheck()">
          </span>
          <span class="hcol-score">
            <div class="history-score-badge <?php echo $sc; ?>"><?php echo $score; ?></div>
          </span>
          <span class="hcol-target">
            <div class="history-target"><?php echo htmlspecialchars($short); ?></div>
            <div class="history-meta"><?php echo $time; ?></div>
          </span>
          <span class="hcol-profile">
            <span class="history-profile-tag"><?php echo $profile; ?></span>
          </span>
          <span class="hcol-date">
            <div class="history-date"><?php echo $date; ?></div>
          </span>
          <span class="hcol-grade">
            <span class="history-grade <?php echo $sc; ?>"><?php echo $grade; ?></span>
          </span>
          <span class="hcol-action" style="display:flex;align-items:center;gap:8px" onclick="event.stopPropagation()">
            <a href="/scanner/report_pdf.php?scan_id=<?php echo (int)$scan['id']; ?>"
               title="PDF Report" target="_blank"
               style="color:var(--text3);font-size:12px;text-decoration:none;font-family:var(--mono);
                      border:1px solid var(--border);padding:2px 7px;border-radius:4px;
                      transition:all 0.12s"
               onmouseover="this.style.color='var(--accent)';this.style.borderColor='var(--accent)'"
               onmouseout="this.style.color='var(--text3)';this.style.borderColor='var(--border)'">
              PDF
            </a>
            <span style="color:var(--text3);font-size:18px">&#8250;</span>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php endif; ?>

  </main>
</div>

<!-- Modal -->
<div id="scan-modal" class="modal-overlay" style="display:none" onclick="closeModal(event)">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-title" id="modal-title">Scan Report</span>
      <button class="modal-close" onclick="document.getElementById('scan-modal').style.display='none'">&#10005;</button>
    </div>
    <div class="modal-body" id="modal-body">Loading...</div>
  </div>
</div>

<script src="/assets/js/history.js"></script>
</body>
</html>