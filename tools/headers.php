<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'osint-headers';
$topbar_title = 'Headers Analyser';
$gate_feature = 'Headers Analyser'; $gate_hard = true; require __DIR__ . '/../partials/pro_gate.php';

$pdo = db();

$pdo->exec("CREATE TABLE IF NOT EXISTS header_checks (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    url         VARCHAR(512) NOT NULL,
    score       TINYINT UNSIGNED DEFAULT 0,
    grade       VARCHAR(3) DEFAULT 'F',
    result_json MEDIUMTEXT,
    checked_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id)
)");

// ── Grading rules ─────────────────────────────────────────────────────────────
function hdr_grade(int $score): string {
    return match(true) {
        $score >= 90 => 'A+',
        $score >= 80 => 'A',
        $score >= 70 => 'B',
        $score >= 55 => 'C',
        $score >= 35 => 'D',
        default      => 'F',
    };
}

function hdr_analyse(array $headers): array {
    $lower = [];
    foreach ($headers as $k => $v) $lower[strtolower($k)] = $v;

    $checks = [];
    $total  = 0;
    $earned = 0;

    // Helper to add a check
    $add = function(string $name, string $header, int $pts, string $desc, callable $test) use ($lower, &$checks, &$total, &$earned) {
        $val    = $lower[$header] ?? null;
        $result = $test($val);
        $total += $pts;
        if ($result['pass']) $earned += $pts;
        $checks[] = [
            'name'   => $name,
            'header' => $header,
            'value'  => $val,
            'status' => $result['pass'] ? 'pass' : ($result['warn'] ?? false ? 'warn' : 'fail'),
            'note'   => $result['note'],
            'points' => $pts,
        ];
    };

    $add('Strict-Transport-Security', 'strict-transport-security', 20,
        'Enforces HTTPS',
        function($v) {
            if (!$v) return ['pass'=>false,'note'=>'Missing — site may be accessed over HTTP'];
            preg_match('/max-age=(\d+)/i', $v, $m);
            $age = (int)($m[1] ?? 0);
            if ($age < 31536000) return ['pass'=>false,'warn'=>true,'note'=>"max-age too short ({$age}s), recommend ≥31536000"];
            return ['pass'=>true,'note'=>'Present and max-age is sufficient'];
        });

    $add('Content-Security-Policy', 'content-security-policy', 25,
        'Prevents XSS and injection',
        function($v) {
            if (!$v) return ['pass'=>false,'note'=>'Missing — high XSS risk'];
            if (str_contains($v, "'unsafe-inline'") || str_contains($v, "'unsafe-eval'"))
                return ['pass'=>false,'warn'=>true,'note'=>"Present but contains 'unsafe-inline' or 'unsafe-eval'"];
            return ['pass'=>true,'note'=>'Present'];
        });

    $add('X-Frame-Options', 'x-frame-options', 15,
        'Prevents clickjacking',
        function($v) {
            if (!$v) return ['pass'=>false,'note'=>'Missing — vulnerable to clickjacking'];
            $v = strtoupper(trim($v));
            if (!in_array($v, ['DENY','SAMEORIGIN'])) return ['pass'=>false,'warn'=>true,'note'=>"Value '{$v}' is not recommended"];
            return ['pass'=>true,'note'=>"Set to {$v}"];
        });

    $add('X-Content-Type-Options', 'x-content-type-options', 10,
        'Prevents MIME sniffing',
        function($v) {
            if (!$v) return ['pass'=>false,'note'=>'Missing — browser may sniff MIME types'];
            return strtolower(trim($v)) === 'nosniff'
                ? ['pass'=>true,'note'=>'nosniff set']
                : ['pass'=>false,'warn'=>true,'note'=>"Unexpected value: {$v}"];
        });

    $add('Referrer-Policy', 'referrer-policy', 10,
        'Controls referrer information',
        function($v) {
            if (!$v) return ['pass'=>false,'note'=>'Missing — referrer sent to all origins'];
            $safe = ['no-referrer','strict-origin','strict-origin-when-cross-origin','no-referrer-when-downgrade','origin','origin-when-cross-origin','same-origin'];
            return in_array(strtolower(trim($v)), $safe)
                ? ['pass'=>true,'note'=>$v]
                : ['pass'=>false,'warn'=>true,'note'=>"Value '{$v}' may leak referrer data"];
        });

    $add('Permissions-Policy', 'permissions-policy', 10,
        'Restricts browser features',
        function($v) {
            if (!$v) return ['pass'=>false,'note'=>'Missing — all browser features allowed'];
            return ['pass'=>true,'note'=>'Present'];
        });

    $add('Server Header Hidden', 'server', 5,
        'Hides server software version',
        function($v) {
            if (!$v) return ['pass'=>true,'note'=>'Not exposed'];
            if (preg_match('/[\d\.]+/', $v)) return ['pass'=>false,'note'=>"Exposes version: {$v}"];
            return ['pass'=>false,'warn'=>true,'note'=>"Exposes software: {$v}"];
        });

    $add('X-Powered-By Hidden', 'x-powered-by', 5,
        'Hides backend technology',
        function($v) {
            if (!$v) return ['pass'=>true,'note'=>'Not exposed'];
            return ['pass'=>false,'note'=>"Exposes: {$v}"];
        });

    $score = $total > 0 ? (int)round(($earned / $total) * 100) : 0;
    return ['checks' => $checks, 'score' => $score, 'grade' => hdr_grade($score)];
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) { http_response_code(403); exit; }

    $url = trim($_POST['url'] ?? '');
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid URL']); exit;
    }

    // Fetch headers only (HEAD request, follow redirects)
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 HakDel Security Scanner',
    ]);
    $raw      = curl_exec($ch);
    $err      = curl_error($ch);
    $http_code= curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final_url= curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($err) {
        header('Content-Type: application/json');
        echo json_encode(['error' => "Could not connect: {$err}"]); exit;
    }

    // Parse headers
    $hdrs = [];
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $hdrs[trim($k)] = trim($v);
        }
    }

    $analysis = hdr_analyse($hdrs);
    $result = [
        'url'       => $final_url,
        'http_code' => $http_code,
        'headers'   => $hdrs,
        'analysis'  => $analysis,
    ];

    $pdo->prepare('INSERT INTO header_checks (user_id, url, score, grade, result_json) VALUES (?,?,?,?,?)')
        ->execute([$user['id'], $url, $analysis['score'], $analysis['grade'], json_encode($result)]);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $result]); exit;
}

// ── History ───────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT id, url, score, grade, checked_at FROM header_checks WHERE user_id = ? ORDER BY checked_at DESC LIMIT 10');
$stmt->execute([$user['id']]);
$history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Headers Analyser — HakDel</title>
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="stylesheet" href="/assets/layout.css">
  <link rel="stylesheet" href="/assets/tools.css">
</head>
<body>
<?php require __DIR__ . '/../partials/topbar.php'; ?>
<div class="hk-shell">
<?php require __DIR__ . '/../partials/sidebar.php'; ?>
<main class="hk-main">

  <div class="hk-page-header">
    <div>
      <div class="hk-page-eyebrow">OSINT</div>
      <h1 class="hk-page-title">Headers Analyser</h1>
      <p class="hk-page-sub">Security headers grader — checks CSP, HSTS, clickjacking protection and more</p>
    </div>
  </div>

  <div class="tool-card">
    <form onsubmit="checkHeaders(event)">
      <div style="display:flex;gap:10px;align-items:flex-end">
        <div style="flex:1">
          <label class="tool-label">URL</label>
          <input type="text" id="url-input" class="tool-input" placeholder="https://example.com" autocomplete="off">
        </div>
        <button type="submit" class="btn-tool-primary" id="check-btn">Analyse</button>
      </div>
    </form>
  </div>

  <div id="loading" style="display:none" class="tool-card">
    <div class="tool-loading-dots"><span></span><span></span><span></span></div>
    <div style="font-size:13px;color:var(--text3);margin-top:10px">Fetching headers...</div>
  </div>

  <div id="results" style="display:none"></div>

  <?php if ($history): ?>
  <div class="tool-card">
    <div class="tool-card-header">&#9783; Recent Checks</div>
    <table class="ip-history-table">
      <thead><tr><th>URL</th><th>Grade</th><th>Score</th><th>Date</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($history as $h): ?>
        <tr>
          <td style="font-family:var(--mono);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo h($h['url']); ?></td>
          <td><span class="risk-badge <?php echo match(substr($h['grade'],0,1)) { 'A'=>'clean','B'=>'clean','C'=>'suspicious',default=>'malicious' }; ?>"><?php echo h($h['grade']); ?></span></td>
          <td style="font-family:var(--mono)"><?php echo $h['score']; ?>/100</td>
          <td style="color:var(--text3)"><?php echo date('M j, H:i', strtotime($h['checked_at'])); ?></td>
          <td><a href="#" onclick="rerun(<?php echo h(json_encode($h['url'])); ?>);return false" style="color:var(--accent);font-size:12px">&#8635;</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</main>
</div>
<script>
const CSRF = '<?php echo h(csrf_token()); ?>';

function rerun(url) {
  document.getElementById('url-input').value = url;
  checkHeaders(null);
}

async function checkHeaders(e) {
  if (e) e.preventDefault();
  const url = document.getElementById('url-input').value.trim();
  if (!url) return;

  document.getElementById('results').style.display = 'none';
  document.getElementById('loading').style.display = 'block';
  document.getElementById('check-btn').disabled = true;

  try {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('url', url);
    const res  = await fetch('', {method:'POST', body:fd});
    const json = await res.json();
    document.getElementById('loading').style.display = 'none';
    document.getElementById('check-btn').disabled = false;
    if (json.error) { showError(json.error); return; }
    renderResults(json.data);
  } catch(err) {
    document.getElementById('loading').style.display = 'none';
    document.getElementById('check-btn').disabled = false;
    showError('Request failed: ' + err.message);
  }
}

function showError(msg) {
  const r = document.getElementById('results');
  r.style.display = 'block';
  r.innerHTML = `<div class="tool-error">${msg}</div>`;
}

function renderResults(d) {
  const r = document.getElementById('results');
  r.style.display = 'block';

  const a     = d.analysis;
  const grade = a.grade;
  const score = a.score;
  const gradeColor = score >= 80 ? 'var(--accent)' : score >= 55 ? '#f59e0b' : 'var(--danger)';

  // Grade arc
  const pct   = score / 100;
  const circ  = 2 * Math.PI * 54;
  const dash  = circ * pct;
  const arc = `<svg viewBox="0 0 120 120" class="risk-gauge-wrap" style="width:120px;height:120px">
    <circle cx="60" cy="60" r="54" fill="none" stroke="var(--bg4)" stroke-width="10"/>
    <circle cx="60" cy="60" r="54" fill="none" stroke="${gradeColor}" stroke-width="10"
      stroke-dasharray="${dash} ${circ}" stroke-dashoffset="${circ/4}"
      stroke-linecap="round" transform="rotate(-90 60 60)" style="transition:stroke-dasharray 0.6s"/>
    <text x="60" y="55" text-anchor="middle" fill="${gradeColor}" font-size="26" font-family="monospace" font-weight="bold">${grade}</text>
    <text x="60" y="72" text-anchor="middle" fill="var(--text3)" font-size="11" font-family="monospace">${score}/100</text>
  </svg>`;

  // Checks table
  const statusIcon = {pass:'✓', warn:'⚠', fail:'✗'};
  const statusColor= {pass:'var(--accent)', warn:'#f59e0b', fail:'var(--danger)'};
  let rows = '';
  for (const c of a.checks) {
    rows += `<tr>
      <td style="color:${statusColor[c.status]};font-size:16px;text-align:center">${statusIcon[c.status]}</td>
      <td style="font-family:var(--mono);font-size:12px">${escHtml(c.header)}</td>
      <td style="font-size:13px">${escHtml(c.name)}</td>
      <td style="font-size:12px;color:var(--text3)">${escHtml(c.note)}</td>
      <td style="font-family:var(--mono);font-size:11px;color:var(--text3);text-align:right">${c.points}pts</td>
    </tr>`;
  }

  // Raw headers
  let rawRows = '';
  for (const [k,v] of Object.entries(d.headers || {})) {
    rawRows += `<tr><td style="font-family:var(--mono);font-size:11px;color:var(--accent)">${escHtml(k)}</td><td style="font-size:12px;color:var(--text2);word-break:break-all">${escHtml(v)}</td></tr>`;
  }

  r.innerHTML = `
    <div style="display:flex;align-items:flex-start;gap:16px;margin-bottom:16px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px">
      ${arc}
      <div>
        <div style="font-family:var(--mono);font-size:18px;color:var(--text)">${escHtml(d.url)}</div>
        <div style="font-size:12px;color:var(--text3);margin-top:4px">HTTP ${d.http_code}</div>
        <div style="font-size:13px;color:var(--text2);margin-top:10px">
          ${a.checks.filter(c=>c.status==='pass').length} passed &nbsp;·&nbsp;
          <span style="color:#f59e0b">${a.checks.filter(c=>c.status==='warn').length} warnings</span> &nbsp;·&nbsp;
          <span style="color:var(--danger)">${a.checks.filter(c=>c.status==='fail').length} missing</span>
        </div>
      </div>
    </div>
    <div class="tools-grid tools-grid-2">
      <div class="tool-card" style="grid-column:1/-1">
        <div class="tool-card-header">&#9654; Security Headers</div>
        <table style="width:100%;border-collapse:collapse">
          <thead><tr style="font-size:11px;color:var(--text3);font-family:var(--mono)">
            <th style="padding:8px 12px;text-align:center">Status</th>
            <th style="padding:8px 12px;text-align:left">Header</th>
            <th style="padding:8px 12px;text-align:left">Check</th>
            <th style="padding:8px 12px;text-align:left">Note</th>
            <th style="padding:8px 12px;text-align:right">Pts</th>
          </tr></thead>
          <tbody style="font-size:13px">${rows}</tbody>
        </table>
      </div>
      <div class="tool-card" style="grid-column:1/-1">
        <div class="tool-card-header">&#128196; Raw Headers</div>
        <table style="width:100%;border-collapse:collapse">
          <tbody>${rawRows}</tbody>
        </table>
      </div>
    </div>`;
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
