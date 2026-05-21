<?php
require_once __DIR__ . '/../config/app.php';
$user    = require_login();
$xp_data = xp_progress((int)$user['xp']);
$level   = $xp_data['level'];
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$id_a = (int)($_GET['a'] ?? 0);
$id_b = (int)($_GET['b'] ?? 0);

// Fetch both scans (must belong to this user)
function fetchScan(PDO $db, int $id, int $user_id): ?array {
    $stmt = $db->prepare('SELECT * FROM scans WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user_id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $result = json_decode($row['result_json'], true) ?? [];
    return array_merge($row, ['findings' => $result['findings'] ?? []]);
}

$scan_a = $id_a ? fetchScan(db(), $id_a, $user['id']) : null;
$scan_b = $id_b ? fetchScan(db(), $id_b, $user['id']) : null;

// For the selector: load last 20 scans
$stmt = db()->prepare('SELECT id, target_url, score, grade, scanned_at FROM scans WHERE user_id = ? AND status="done" ORDER BY scanned_at DESC LIMIT 20');
$stmt->execute([$user['id']]);
$all_scans = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HakDel - Compare Scans</title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/layout.css">
</head>
<body>
<?php require __DIR__ . '/../partials/topbar.php'; ?>
<div class="hk-shell">
  <?php
  $nav_active  = 'history';
  $sidebar_sub = 'Compare Scans';
  require __DIR__ . '/../partials/sidebar.php';
  ?>
  <main class="hk-main">
    <div class="hk-page-header">
      <div>
        <div class="hk-page-eyebrow">&#9783; Compare &nbsp;&middot;&nbsp; Side-by-Side Diff</div>
        <h1 class="hk-page-title">Scan Comparison</h1>
        <p class="hk-page-sub">Compare two scans to track security improvements or regressions.</p>
      </div>
      <a href="/scanner/history.php" class="btn-secondary" style="text-decoration:none;">&#8592; Back to History</a>
    </div>

    <!-- Scan picker -->
    <div class="compare-picker">
      <div class="compare-picker-side">
        <div class="compare-picker-label">Scan A (baseline)</div>
        <select class="compare-select" id="sel-a" onchange="updateUrl()">
          <option value="">— select scan —</option>
          <?php foreach ($all_scans as $s): ?>
          <option value="<?php echo $s['id']; ?>" <?php echo $id_a === (int)$s['id'] ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars(substr($s['target_url'], 0, 40)); ?> — <?php echo $s['score']; ?>/100 (<?php echo date('d M', strtotime($s['scanned_at'])); ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="compare-picker-vs">VS</div>
      <div class="compare-picker-side">
        <div class="compare-picker-label">Scan B (comparison)</div>
        <select class="compare-select" id="sel-b" onchange="updateUrl()">
          <option value="">— select scan —</option>
          <?php foreach ($all_scans as $s): ?>
          <option value="<?php echo $s['id']; ?>" <?php echo $id_b === (int)$s['id'] ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars(substr($s['target_url'], 0, 40)); ?> — <?php echo $s['score']; ?>/100 (<?php echo date('d M', strtotime($s['scanned_at'])); ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <?php if ($scan_a && $scan_b): ?>
    <div id="compare-results">
      <!-- Score comparison -->
      <div class="compare-scores">
        <?php
        $delta = (int)$scan_b['score'] - (int)$scan_a['score'];
        $delta_class = $delta > 0 ? 'delta-up' : ($delta < 0 ? 'delta-down' : 'delta-neutral');
        $delta_str   = ($delta > 0 ? '+' : '') . $delta;
        ?>
        <div class="compare-score-card">
          <div class="csc-label">Scan A — <?php echo htmlspecialchars($scan_a['target_url']); ?></div>
          <div class="csc-score" style="color:<?php echo $scan_a['score'] >= 75 ? 'var(--accent)' : ($scan_a['score'] >= 50 ? 'var(--warn)' : 'var(--danger)'); ?>">
            <?php echo $scan_a['score']; ?>
          </div>
          <div class="csc-grade"><?php echo $scan_a['grade']; ?></div>
          <div class="csc-date"><?php echo date('d M Y H:i', strtotime($scan_a['scanned_at'])); ?></div>
        </div>
        <div class="compare-delta <?php echo $delta_class; ?>">
          <div class="delta-arrow"><?php echo $delta > 0 ? '&#8593;' : ($delta < 0 ? '&#8595;' : '&#8596;'); ?></div>
          <div class="delta-num"><?php echo $delta_str; ?></div>
          <div class="delta-label">points</div>
        </div>
        <div class="compare-score-card">
          <div class="csc-label">Scan B — <?php echo htmlspecialchars($scan_b['target_url']); ?></div>
          <div class="csc-score" style="color:<?php echo $scan_b['score'] >= 75 ? 'var(--accent)' : ($scan_b['score'] >= 50 ? 'var(--warn)' : 'var(--danger)'); ?>">
            <?php echo $scan_b['score']; ?>
          </div>
          <div class="csc-grade"><?php echo $scan_b['grade']; ?></div>
          <div class="csc-date"><?php echo date('d M Y H:i', strtotime($scan_b['scanned_at'])); ?></div>
        </div>
      </div>

      <!-- Finding diff -->
      <div class="compare-diff-card">
        <div class="compare-diff-header">
          <span class="compare-diff-title">Finding Differences</span>
          <span class="compare-diff-sub">Changes between Scan A and Scan B</span>
        </div>
        <div class="compare-diff-body" id="diff-body">
          <!-- Rendered by compare.js -->
          <script>
          window.SCAN_A_FINDINGS = <?php echo json_encode($scan_a['findings']); ?>;
          window.SCAN_B_FINDINGS = <?php echo json_encode($scan_b['findings']); ?>;
          </script>
        </div>
      </div>
    </div>
    <?php elseif ($id_a || $id_b): ?>
    <div class="hk-error" style="margin-top:16px">One or both scans not found or don't belong to your account.</div>
    <?php else: ?>
    <div class="compare-empty">Select two scans above to compare them.</div>
    <?php endif; ?>

  </main>
</div>
<script src="/assets/js/compare.js"></script>
</body>
</html>
