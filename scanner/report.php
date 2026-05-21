<?php
require_once __DIR__ . '/../config/app.php';
$user = require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /scanner/history.php'); exit; }

$stmt = db()->prepare('SELECT * FROM scans WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $user['id']]);
$scan = $stmt->fetch();
if (!$scan) { header('Location: /scanner/history.php'); exit; }

$result   = json_decode($scan['result_json'], true) ?? [];
$findings = $result['findings'] ?? [];
$score    = (int)$scan['score'];
$grade    = $scan['grade'];
$target   = $scan['target_url'];
$date     = date('d F Y H:i', strtotime($scan['scanned_at']));

// Group findings by severity
$by_sev = ['critical' => [], 'high' => [], 'medium' => [], 'low' => [], 'info' => []];
foreach ($findings as $f) {
    $sev = $f['severity'] ?? 'info';
    $by_sev[$sev][] = $f;
}
$score_color = $score >= 75 ? '#00d4aa' : ($score >= 50 ? '#ffd166' : '#ff4d6d');
$C = 2 * M_PI * 46;
$offset = $C - ($score / 100) * $C;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>HakDel Report — <?php echo htmlspecialchars($target); ?></title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Inter:wght@400;500;600&display=swap');
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Inter', sans-serif; background: #fff; color: #0d1520; font-size: 13px; line-height: 1.5; }
  .rpt-header { background: #080d14; color: #c8d6e5; padding: 28px 40px; display: flex; align-items: center; justify-content: space-between; }
  .rpt-brand  { font-family: 'Share Tech Mono', monospace; font-size: 22px; letter-spacing: 3px; }
  .rpt-brand span { color: #00d4aa; }
  .rpt-meta   { font-family: 'Share Tech Mono', monospace; font-size: 12px; color: #6b8098; text-align: right; }
  .rpt-body   { padding: 32px 40px; }
  .rpt-target { font-family: 'Share Tech Mono', monospace; font-size: 16px; color: #0094ff; margin-bottom: 24px; }
  .rpt-score-row { display: flex; align-items: center; gap: 24px; margin-bottom: 32px; }
  .rpt-gauge  { width: 110px; height: 110px; flex-shrink: 0; }
  .rpt-score-info h2 { font-family: 'Share Tech Mono', monospace; font-size: 48px; font-weight: 700; color: <?php echo $score_color; ?>; line-height: 1; }
  .rpt-score-info .grade { font-family: 'Share Tech Mono', monospace; font-size: 20px; color: #6b8098; }
  .rpt-score-info .summary { font-size: 14px; color: #3d5168; margin-top: 6px; }
  .rpt-stats { display: flex; gap: 12px; margin-bottom: 32px; }
  .rpt-stat { border-radius: 6px; padding: 10px 16px; font-family: 'Share Tech Mono', monospace; font-size: 13px; }
  .rpt-stat-crit { background: #fff0f3; color: #c0392b; }
  .rpt-stat-high { background: #fffbf0; color: #b8860b; }
  .rpt-stat-pass { background: #f0faf7; color: #1a8a70; }
  .rpt-stat-total{ background: #f5f6f7; color: #4a5568; }
  h3 { font-family: 'Share Tech Mono', monospace; font-size: 13px; letter-spacing: 2px; text-transform: uppercase; color: #6b8098; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin: 24px 0 12px; }
  .finding-row { border-left: 3px solid #e2e8f0; padding: 10px 14px; margin-bottom: 6px; background: #f8fafc; border-radius: 0 4px 4px 0; }
  .finding-row.sev-critical { border-left-color: #e74c3c; }
  .finding-row.sev-high     { border-left-color: #f39c12; }
  .finding-row.sev-medium   { border-left-color: #8e44ad; }
  .finding-row.sev-low      { border-left-color: #95a5a6; }
  .finding-row.sev-info     { border-left-color: #bdc3c7; }
  .fr-top { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
  .fr-status { font-family: 'Share Tech Mono', monospace; font-size: 11px; padding: 1px 6px; border-radius: 2px; }
  .st-fail { background: #fdecea; color: #c0392b; }
  .st-pass { background: #eafaf1; color: #1a8a70; }
  .st-warn { background: #fef9e7; color: #b8860b; }
  .st-info { background: #f0f3f5; color: #6b8098; }
  .fr-title { font-size: 13px; font-weight: 600; flex: 1; }
  .fr-sev { font-family: 'Share Tech Mono', monospace; font-size: 11px; color: #6b8098; }
  .fr-detail { font-size: 12px; color: #4a5568; }
  .fr-fix { font-size: 12px; color: #2d6a4f; margin-top: 2px; }
  .fr-fix-label { font-family: 'Share Tech Mono', monospace; color: #1a8a70; margin-right: 4px; }
  .rpt-footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 16px 40px; font-family: 'Share Tech Mono', monospace; font-size: 11px; color: #6b8098; display: flex; justify-content: space-between; }
  .no-print { margin-bottom: 20px; }
  @media print {
    .no-print { display: none !important; }
    body { font-size: 11px; }
    .rpt-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .finding-row { -webkit-print-color-adjust: exact; print-color-adjust: exact; break-inside: avoid; }
    h3 { break-before: auto; }
  }
</style>
</head>
<body>
<div class="no-print" style="background:#080d14;padding:12px 40px;display:flex;gap:12px;align-items:center">
  <button onclick="window.print()" style="background:#00d4aa;color:#080d14;border:none;border-radius:6px;padding:9px 20px;font-family:'Share Tech Mono',monospace;font-size:13px;cursor:pointer;font-weight:700;">&#128438; Download PDF</button>
  <a href="/scanner/history.php" style="font-family:'Share Tech Mono',monospace;font-size:13px;color:#6b8098;text-decoration:none;">&#8592; Back to History</a>
</div>

<div class="rpt-header">
  <div class="rpt-brand">HAK<span>DEL</span></div>
  <div class="rpt-meta">
    Security Report<br>
    Generated: <?php echo $date; ?><br>
    HakDel Security Engine
  </div>
</div>

<div class="rpt-body">
  <div class="rpt-target"><?php echo htmlspecialchars($target); ?></div>

  <div class="rpt-score-row">
    <svg class="rpt-gauge" viewBox="0 0 100 100">
      <circle cx="50" cy="50" r="46" fill="none" stroke="#e2e8f0" stroke-width="8"/>
      <circle cx="50" cy="50" r="46" fill="none" stroke="<?php echo $score_color; ?>" stroke-width="8"
        stroke-dasharray="<?php echo number_format($C, 1); ?>" stroke-dashoffset="<?php echo number_format($offset, 1); ?>"
        stroke-linecap="round" transform="rotate(-90 50 50)"/>
      <text x="50" y="56" text-anchor="middle" font-family="'Share Tech Mono',monospace"
            font-size="20" font-weight="700" fill="<?php echo $score_color; ?>"><?php echo $score; ?></text>
    </svg>
    <div class="rpt-score-info">
      <h2><?php echo $score; ?></h2>
      <div class="grade">Grade: <?php echo $grade; ?> &nbsp;&middot;&nbsp; <?php echo $scan['summary']; ?></div>
    </div>
  </div>

  <?php
  $crits  = count(array_filter($findings, fn($f) => $f['severity']==='critical' && $f['status']==='fail'));
  $highs  = count(array_filter($findings, fn($f) => $f['severity']==='high'     && $f['status']==='fail'));
  $passed = count(array_filter($findings, fn($f) => $f['status']==='pass'));
  ?>
  <div class="rpt-stats">
    <div class="rpt-stat rpt-stat-crit"><?php echo $crits; ?> Critical</div>
    <div class="rpt-stat rpt-stat-high"><?php echo $highs; ?> High</div>
    <div class="rpt-stat rpt-stat-pass"><?php echo $passed; ?> Passed</div>
    <div class="rpt-stat rpt-stat-total"><?php echo count($findings); ?> Total Checks</div>
  </div>

  <?php foreach (['critical' => 'Critical Findings', 'high' => 'High Severity', 'medium' => 'Medium Severity', 'low' => 'Low Severity', 'info' => 'Informational'] as $sev => $label): ?>
    <?php if (!empty($by_sev[$sev])): ?>
    <h3><?php echo $label; ?></h3>
    <?php foreach ($by_sev[$sev] as $f): ?>
    <div class="finding-row sev-<?php echo $f['severity']; ?>">
      <div class="fr-top">
        <span class="fr-status st-<?php echo $f['status']; ?>"><?php echo strtoupper($f['status']); ?></span>
        <span class="fr-title"><?php echo htmlspecialchars($f['title']); ?></span>
        <span class="fr-sev"><?php echo strtoupper($f['severity'] ?? 'info'); ?></span>
      </div>
      <div class="fr-detail"><?php echo htmlspecialchars($f['detail']); ?></div>
      <?php if (!empty($f['remediation'])): ?>
      <div class="fr-fix"><span class="fr-fix-label">Fix:</span><?php echo htmlspecialchars($f['remediation']); ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<div class="rpt-footer">
  <span>HakDel Security Engine &nbsp;&middot;&nbsp; hakdel.com</span>
  <span><?php echo htmlspecialchars($target); ?> &nbsp;&middot;&nbsp; Score: <?php echo $score; ?>/100</span>
</div>
</body>
</html>
