<?php
require_once __DIR__ . '/../config/app.php';
$user = require_login();

$id = (int)($_GET['scan_id'] ?? 0);
if (!$id) redirect('/scanner/history.php');

$stmt = db()->prepare('SELECT * FROM scans WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $user['id']]);
$scan = $stmt->fetch();
if (!$scan) redirect('/scanner/history.php');

$result   = json_decode($scan['result_json'] ?? '{}', true) ?? [];
$findings = $result['findings'] ?? [];
$score    = (int)$scan['score'];
$grade    = $scan['grade'] ?? 'F';
$target   = $scan['target_url'];
$scan_date = date('d F Y \a\t H:i', strtotime($scan['scanned_at']));
$report_date = date('d F Y \a\t H:i');
$summary  = $result['summary'] ?? $scan['summary'] ?? 'No summary available.';

// Group findings by severity
$by_sev = ['critical' => [], 'high' => [], 'medium' => [], 'low' => [], 'info' => []];
foreach ($findings as $f) {
    $sev = strtolower($f['severity'] ?? 'info');
    if (!isset($by_sev[$sev])) $sev = 'info';
    $by_sev[$sev][] = $f;
}

$total_findings = count($findings);
$critical_count = count($by_sev['critical']);
$high_count     = count($by_sev['high']);

$sev_colors = [
    'critical' => ['bg' => '#fef2f2', 'text' => '#dc2626', 'border' => '#fca5a5'],
    'high'     => ['bg' => '#fff7ed', 'text' => '#ea580c', 'border' => '#fdba74'],
    'medium'   => ['bg' => '#fefce8', 'text' => '#ca8a04', 'border' => '#fde047'],
    'low'      => ['bg' => '#eff6ff', 'text' => '#2563eb', 'border' => '#93c5fd'],
    'info'     => ['bg' => '#f8fafc', 'text' => '#64748b', 'border' => '#cbd5e1'],
];

$grade_color = match(strtoupper($grade)) {
    'A+', 'A' => '#059669',
    'B'       => '#2563eb',
    'C'       => '#d97706',
    'D'       => '#ea580c',
    default   => '#dc2626',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Security Report — <?php echo htmlspecialchars($target); ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: #f0f4f8;
    color: #1a202c;
    font-size: 13px;
    line-height: 1.6;
  }

  /* Print button — screen only */
  .print-bar {
    position: fixed; top: 0; left: 0; right: 0;
    background: #1a202c; padding: 12px 32px;
    display: flex; align-items: center; justify-content: space-between;
    z-index: 100;
  }
  .print-bar-title { color: #a0aec0; font-size: 13px; }
  .print-btn {
    background: #00d4aa; color: #0d1520; border: none;
    padding: 8px 20px; border-radius: 6px;
    font-weight: 700; font-size: 13px; cursor: pointer;
    letter-spacing: 0.5px;
  }
  .print-btn:hover { opacity: 0.9; }

  .report-wrap {
    max-width: 900px; margin: 72px auto 60px;
    background: #fff; box-shadow: 0 2px 24px rgba(0,0,0,0.12);
  }

  /* Header */
  .rpt-header {
    background: #0d1520; padding: 32px 40px;
    display: flex; align-items: flex-start; justify-content: space-between;
  }
  .rpt-brand-wrap {}
  .rpt-brand {
    font-family: 'Courier New', monospace; font-size: 24px;
    color: #fff; font-weight: 700; letter-spacing: 4px;
  }
  .rpt-brand-accent { color: #00d4aa; }
  .rpt-brand-sub {
    font-family: 'Courier New', monospace; font-size: 11px;
    color: #4a5568; letter-spacing: 2px; text-transform: uppercase; margin-top: 4px;
  }
  .rpt-header-right { text-align: right; }
  .rpt-report-label { font-size: 10px; color: #4a5568; letter-spacing: 2px; text-transform: uppercase; }
  .rpt-report-date { font-size: 12px; color: #a0aec0; margin-top: 3px; }

  /* Target section */
  .rpt-target-bar {
    background: #f7fafc; border-bottom: 2px solid #e2e8f0;
    padding: 20px 40px; display: flex; align-items: center; gap: 20px;
  }
  .rpt-target-label { font-size: 10px; color: #718096; letter-spacing: 2px; text-transform: uppercase; }
  .rpt-target-url { font-family: 'Courier New', monospace; font-size: 16px; color: #1a202c; font-weight: 700; margin-top: 3px; }
  .rpt-target-meta { font-size: 12px; color: #718096; margin-top: 2px; }

  /* Grade display */
  .rpt-grade-wrap {
    display: flex; flex-direction: column; align-items: center;
    margin-left: auto;
  }
  .rpt-grade-circle {
    width: 80px; height: 80px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 32px; font-weight: 900; font-family: 'Courier New', monospace;
    border: 4px solid;
  }
  .rpt-grade-score { font-size: 12px; color: #718096; margin-top: 4px; text-align: center; }

  /* Section */
  .rpt-section { padding: 28px 40px; border-bottom: 1px solid #e2e8f0; }
  .rpt-section:last-child { border-bottom: none; }
  .rpt-section-title {
    font-size: 11px; font-weight: 800; letter-spacing: 3px;
    text-transform: uppercase; color: #2d3748; margin-bottom: 14px;
    display: flex; align-items: center; gap: 8px;
  }
  .rpt-section-title::after {
    content: ''; flex: 1; height: 1px; background: #e2e8f0;
  }

  /* Summary */
  .rpt-summary-text {
    font-size: 13px; color: #4a5568; line-height: 1.8;
    background: #f7fafc; border-left: 4px solid #00d4aa;
    padding: 14px 18px; border-radius: 0 6px 6px 0;
  }

  /* Stats row */
  .rpt-stats {
    display: flex; gap: 0; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;
  }
  .rpt-stat {
    flex: 1; padding: 16px; border-right: 1px solid #e2e8f0;
    text-align: center;
  }
  .rpt-stat:last-child { border-right: none; }
  .rpt-stat-val { font-size: 26px; font-weight: 900; font-family: 'Courier New', monospace; }
  .rpt-stat-label { font-size: 11px; color: #718096; margin-top: 3px; letter-spacing: 0.5px; }

  /* Findings table */
  .findings-group { margin-bottom: 20px; }
  .sev-group-header {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px; border-radius: 6px 6px 0 0;
    font-size: 12px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
  }
  .finding-row {
    display: grid; grid-template-columns: 1fr auto;
    gap: 12px; padding: 12px 14px;
    border: 1px solid; border-top: none;
  }
  .finding-row:last-child { border-radius: 0 0 6px 6px; }
  .finding-title { font-size: 13px; font-weight: 600; color: #1a202c; }
  .finding-detail { font-size: 12px; color: #4a5568; margin-top: 4px; line-height: 1.5; }
  .finding-status {
    font-size: 10px; font-weight: 700; padding: 3px 8px;
    border-radius: 4px; text-transform: uppercase; white-space: nowrap;
    height: fit-content; align-self: flex-start;
  }
  .no-findings {
    padding: 24px; text-align: center; color: #718096;
    border: 1px solid #e2e8f0; border-radius: 6px;
    font-size: 13px;
  }

  /* Footer */
  .rpt-footer {
    background: #0d1520; padding: 20px 40px;
    display: flex; align-items: center; justify-content: space-between;
  }
  .rpt-footer-brand { font-family: 'Courier New', monospace; font-size: 13px; color: #4a5568; }
  .rpt-footer-text { font-size: 11px; color: #4a5568; }

  /* Page numbers for print */
  @media print {
    .print-bar { display: none !important; }
    body { background: #fff; }
    .report-wrap { margin: 0; box-shadow: none; max-width: 100%; }

    @page {
      size: A4;
      margin: 15mm 18mm;
    }

    .findings-group { page-break-inside: avoid; }
    .rpt-section { page-break-inside: avoid; }

    /* Page counter via CSS */
    .rpt-footer::after {
      content: "Page " counter(page);
      font-family: 'Courier New', monospace; font-size: 11px; color: #4a5568;
    }
  }
</style>
</head>
<body>

<!-- Print bar (screen only) -->
<div class="print-bar">
  <span class="print-bar-title">HakDel Security Report — <?php echo htmlspecialchars($target); ?></span>
  <div style="display:flex;gap:10px;align-items:center">
    <a href="/scanner/report.php?id=<?php echo $id; ?>" style="color:#a0aec0;font-size:12px;text-decoration:none">
      &larr; Back to report
    </a>
    <button class="print-btn" onclick="window.print()">&#128438; Print / Save as PDF</button>
  </div>
</div>

<div class="report-wrap">

  <!-- Header -->
  <div class="rpt-header">
    <div class="rpt-brand-wrap">
      <div class="rpt-brand">HAK<span class="rpt-brand-accent">DEL</span></div>
      <div class="rpt-brand-sub">Security Assessment Report</div>
    </div>
    <div class="rpt-header-right">
      <div class="rpt-report-label">Report Generated</div>
      <div class="rpt-report-date"><?php echo $report_date; ?></div>
    </div>
  </div>

  <!-- Target bar -->
  <div class="rpt-target-bar">
    <div style="flex:1">
      <div class="rpt-target-label">Target</div>
      <div class="rpt-target-url"><?php echo htmlspecialchars($target); ?></div>
      <div class="rpt-target-meta">Scanned <?php echo $scan_date; ?></div>
    </div>
    <div class="rpt-grade-wrap">
      <div class="rpt-grade-circle" style="color:<?php echo $grade_color; ?>;border-color:<?php echo $grade_color; ?>">
        <?php echo htmlspecialchars($grade); ?>
      </div>
      <div class="rpt-grade-score"><?php echo $score; ?>/100</div>
    </div>
  </div>

  <!-- Stats -->
  <div class="rpt-section">
    <div class="rpt-section-title">Overview</div>
    <div class="rpt-stats">
      <div class="rpt-stat">
        <div class="rpt-stat-val" style="color:<?php echo $grade_color; ?>"><?php echo $score; ?></div>
        <div class="rpt-stat-label">Security Score</div>
      </div>
      <div class="rpt-stat">
        <div class="rpt-stat-val"><?php echo $total_findings; ?></div>
        <div class="rpt-stat-label">Total Findings</div>
      </div>
      <div class="rpt-stat">
        <div class="rpt-stat-val" style="color:<?php echo $critical_count > 0 ? '#dc2626' : '#059669'; ?>"><?php echo $critical_count; ?></div>
        <div class="rpt-stat-label">Critical Issues</div>
      </div>
      <div class="rpt-stat">
        <div class="rpt-stat-val" style="color:<?php echo $high_count > 0 ? '#ea580c' : '#059669'; ?>"><?php echo $high_count; ?></div>
        <div class="rpt-stat-label">High Severity</div>
      </div>
      <div class="rpt-stat">
        <div class="rpt-stat-val" style="color:<?php echo $grade_color; ?>"><?php echo htmlspecialchars($grade); ?></div>
        <div class="rpt-stat-label">Grade</div>
      </div>
    </div>
  </div>

  <!-- Executive Summary -->
  <div class="rpt-section">
    <div class="rpt-section-title">Executive Summary</div>
    <div class="rpt-summary-text"><?php echo nl2br(htmlspecialchars($summary)); ?></div>
  </div>

  <!-- Findings -->
  <div class="rpt-section">
    <div class="rpt-section-title">Findings</div>

    <?php
    $total_shown = 0;
    foreach ($by_sev as $sev => $items) {
        if (empty($items)) continue;
        $total_shown += count($items);
        $c = $sev_colors[$sev];
    ?>
    <div class="findings-group">
      <div class="sev-group-header" style="background:<?php echo $c['bg']; ?>;color:<?php echo $c['text']; ?>;border:1px solid <?php echo $c['border']; ?>">
        <?php echo strtoupper($sev); ?> — <?php echo count($items); ?> finding<?php echo count($items) !== 1 ? 's' : ''; ?>
      </div>
      <?php foreach ($items as $f): ?>
      <div class="finding-row" style="background:<?php echo $c['bg']; ?>;border-color:<?php echo $c['border']; ?>">
        <div>
          <div class="finding-title"><?php echo htmlspecialchars($f['title'] ?? 'Finding'); ?></div>
          <?php if (!empty($f['description']) || !empty($f['detail'])): ?>
          <div class="finding-detail"><?php echo htmlspecialchars($f['description'] ?? $f['detail'] ?? ''); ?></div>
          <?php endif; ?>
          <?php if (!empty($f['recommendation'])): ?>
          <div style="font-size:12px;color:#2563eb;margin-top:6px">
            <strong>Recommendation:</strong> <?php echo htmlspecialchars($f['recommendation']); ?>
          </div>
          <?php endif; ?>
        </div>
        <?php if (!empty($f['status'])): ?>
        <div class="finding-status" style="background:<?php echo $c['bg']; ?>;color:<?php echo $c['text']; ?>;border:1px solid <?php echo $c['border']; ?>">
          <?php echo htmlspecialchars($f['status']); ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php } ?>

    <?php if ($total_shown === 0): ?>
    <div class="no-findings">No findings reported for this scan. The target appears to be well configured.</div>
    <?php endif; ?>
  </div>

  <!-- Footer -->
  <div class="rpt-footer">
    <div class="rpt-footer-brand">HAK<span style="color:#00d4aa">DEL</span> Security Platform</div>
    <div class="rpt-footer-text">Scan ID: #<?php echo $id; ?> &nbsp;&bull;&nbsp; Generated <?php echo $report_date; ?></div>
  </div>

</div>

<script>
// Keyboard shortcut: Ctrl+P / Cmd+P naturally triggers print dialog
// Add direct button behavior
document.querySelector('.print-btn').addEventListener('click', function(){
  window.print();
});
</script>
</body>
</html>
