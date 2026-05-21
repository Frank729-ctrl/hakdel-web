<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'osint-url';
$topbar_title = 'URL / Phishing Checker';
$gate_feature = 'URL / Phishing Checker'; $gate_hard = true; require __DIR__ . '/../partials/pro_gate.php';

$pdo = db();

$pdo->exec("CREATE TABLE IF NOT EXISTS url_checks (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    url         VARCHAR(2048) NOT NULL,
    verdict     VARCHAR(20) DEFAULT 'unknown',
    result_json MEDIUMTEXT,
    checked_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id)
)");

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) { http_response_code(403); exit; }

    $url = trim($_POST['url'] ?? '');
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid URL. Include http:// or https://']); exit;
    }

    $vt_key = getenv('VIRUSTOTAL_API_KEY') ?: '';
    $results = [];

    // ── VirusTotal URL scan ───────────────────────────────────────────────────
    $vt = ['detections' => 0, 'total' => 0, 'categories' => [], 'scan_date' => '', 'permalink' => ''];
    if ($vt_key) {
        // Submit URL
        $ch = curl_init('https://www.virustotal.com/api/v3/urls');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'url=' . urlencode($url),
            CURLOPT_HTTPHEADER     => ["x-apikey: {$vt_key}", "Content-Type: application/x-www-form-urlencoded"],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $submit = json_decode(curl_exec($ch), true);
        curl_close($ch);

        $analysis_id = $submit['data']['id'] ?? null;
        if ($analysis_id) {
            sleep(3); // wait for analysis
            $ch = curl_init("https://www.virustotal.com/api/v3/analyses/{$analysis_id}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ["x-apikey: {$vt_key}"],
                CURLOPT_TIMEOUT        => 15,
            ]);
            $analysis = json_decode(curl_exec($ch), true);
            curl_close($ch);

            $stats = $analysis['data']['attributes']['stats'] ?? [];
            $vt['detections'] = ($stats['malicious'] ?? 0) + ($stats['suspicious'] ?? 0);
            $vt['total']      = array_sum($stats);
            $vt['stats']      = $stats;
            $engines = $analysis['data']['attributes']['results'] ?? [];
            foreach ($engines as $engine => $res) {
                if (in_array($res['category'] ?? '', ['malicious','suspicious'])) {
                    $vt['flagged_by'][] = ['engine' => $engine, 'result' => $res['result'] ?? '', 'category' => $res['category']];
                }
            }
        }
    }
    $results['virustotal'] = $vt;

    // ── PhishTank ─────────────────────────────────────────────────────────────
    $pt = ['in_database' => false, 'valid' => false, 'verified' => false];
    $ch = curl_init('https://checkurl.phishtank.com/checkurl/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'url=' . urlencode(base64_encode($url)) . '&format=json&app_key=',
        CURLOPT_HTTPHEADER     => ['User-Agent: phishtank/HakDel'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $pt_body = curl_exec($ch);
    curl_close($ch);
    $pt_data = json_decode($pt_body, true)['results'] ?? [];
    if ($pt_data) {
        $pt = [
            'in_database' => (bool)($pt_data['in_database'] ?? false),
            'valid'       => (bool)($pt_data['valid']       ?? false),
            'verified'    => (bool)($pt_data['verified']    ?? false),
            'phish_id'    => $pt_data['phish_id'] ?? null,
        ];
    }
    $results['phishtank'] = $pt;

    // ── Google Safe Browsing ──────────────────────────────────────────────────
    $gsb_key = getenv('GOOGLE_SAFE_BROWSING_KEY') ?: '';
    $gsb = ['threats' => []];
    if ($gsb_key) {
        $payload = json_encode(['client'=>['clientId'=>'hakdel','clientVersion'=>'1.0'],
            'threatInfo'=>['threatTypes'=>['MALWARE','SOCIAL_ENGINEERING','UNWANTED_SOFTWARE','POTENTIALLY_HARMFUL_APPLICATION'],
                'platformTypes'=>['ANY_PLATFORM'],'threatEntryTypes'=>['URL'],
                'threatEntries'=>[['url'=>$url]]]]);
        $ch = curl_init("https://safebrowsing.googleapis.com/v4/threatMatches:find?key={$gsb_key}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 8,
        ]);
        $gsb_res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        foreach ($gsb_res['matches'] ?? [] as $m) {
            $gsb['threats'][] = $m['threatType'] ?? 'UNKNOWN';
        }
    }
    $results['google_safe_browsing'] = $gsb;

    // ── Verdict ───────────────────────────────────────────────────────────────
    $verdict = 'clean';
    if (!empty($gsb['threats']) || $pt['valid'] || $vt['detections'] >= 3) {
        $verdict = 'malicious';
    } elseif ($vt['detections'] >= 1) {
        $verdict = 'suspicious';
    }

    $result = ['url' => $url, 'verdict' => $verdict, 'sources' => $results];

    $pdo->prepare('INSERT INTO url_checks (user_id, url, verdict, result_json) VALUES (?,?,?,?)')
        ->execute([$user['id'], $url, $verdict, json_encode($result)]);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $result]); exit;
}

// ── History ───────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT id, url, verdict, checked_at FROM url_checks WHERE user_id = ? ORDER BY checked_at DESC LIMIT 10');
$stmt->execute([$user['id']]);
$history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>URL Checker — HakDel</title>
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
      <h1 class="hk-page-title">URL / Phishing Checker</h1>
      <p class="hk-page-sub">Check any URL against VirusTotal, PhishTank and Google Safe Browsing</p>
    </div>
  </div>

  <div class="tool-card">
    <form onsubmit="checkURL(event)">
      <div style="display:flex;gap:10px;align-items:flex-end">
        <div style="flex:1">
          <label class="tool-label">URL</label>
          <input type="text" id="url-input" class="tool-input" placeholder="https://suspicious-site.com/login" autocomplete="off" spellcheck="false">
        </div>
        <button type="submit" class="btn-tool-primary" id="check-btn">Check URL</button>
      </div>
    </form>
  </div>

  <div id="loading" style="display:none" class="tool-card">
    <div class="tool-loading-dots"><span></span><span></span><span></span></div>
    <div style="font-size:13px;color:var(--text3);margin-top:10px" id="loading-msg">Submitting to VirusTotal...</div>
  </div>

  <div id="results" style="display:none"></div>

  <?php if ($history): ?>
  <div class="tool-card">
    <div class="tool-card-header">&#9783; Recent Checks</div>
    <table class="ip-history-table">
      <thead><tr><th>URL</th><th>Verdict</th><th>Date</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($history as $h): ?>
        <tr>
          <td style="font-family:var(--mono);font-size:12px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo h($h['url']); ?></td>
          <td><span class="risk-badge <?php echo match($h['verdict']){'malicious'=>'malicious','suspicious'=>'suspicious',default=>'clean'}; ?>"><?php echo h($h['verdict']); ?></span></td>
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
  checkURL(null);
}

async function checkURL(e) {
  if (e) e.preventDefault();
  const url = document.getElementById('url-input').value.trim();
  if (!url) return;

  document.getElementById('results').style.display = 'none';
  document.getElementById('loading').style.display = 'block';
  document.getElementById('check-btn').disabled = true;

  const msgs = ['Submitting to VirusTotal...','Waiting for analysis...','Checking PhishTank...','Querying Safe Browsing...'];
  let mi = 0;
  const ti = setInterval(() => { document.getElementById('loading-msg').textContent = msgs[mi++ % msgs.length]; }, 2000);

  try {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('url', url);
    const res  = await fetch('', {method:'POST', body:fd});
    const json = await res.json();
    clearInterval(ti);
    document.getElementById('loading').style.display = 'none';
    document.getElementById('check-btn').disabled = false;
    if (json.error) { showError(json.error); return; }
    renderResults(json.data);
  } catch(err) {
    clearInterval(ti);
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

  const verdictColor = {malicious:'var(--danger)', suspicious:'#f59e0b', clean:'var(--accent)'}[d.verdict] || 'var(--text3)';
  const verdictIcon  = {malicious:'&#128683;', suspicious:'&#9888;', clean:'&#10003;'}[d.verdict] || '?';

  const vt = d.sources.virustotal;
  const pt = d.sources.phishtank;
  const gsb= d.sources.google_safe_browsing;

  const ptStatus = pt.valid ? '&#128683; Listed as phishing' : pt.in_database ? '&#9888; In database (unverified)' : '&#10003; Not in database';
  const gsbStatus= gsb.threats?.length ? `&#128683; ${gsb.threats.join(', ')}` : '&#10003; No threats found';

  let flaggedHtml = '';
  if (vt.flagged_by?.length) {
    flaggedHtml = `<div style="margin-top:12px">
      <div style="font-size:11px;color:var(--text3);font-family:var(--mono);margin-bottom:6px">FLAGGED BY</div>
      <div style="display:flex;flex-wrap:wrap;gap:6px">
        ${vt.flagged_by.slice(0,20).map(f=>`<span class="osint-sub-pill" style="background:rgba(255,77,77,0.1);color:var(--danger)">${escHtml(f.engine)}: ${escHtml(f.result||f.category)}</span>`).join('')}
      </div>
    </div>`;
  }

  r.innerHTML = `
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;margin-bottom:16px">
      <div style="display:flex;align-items:center;gap:14px">
        <div style="font-size:40px;line-height:1">${verdictIcon}</div>
        <div>
          <div style="font-size:20px;font-weight:700;font-family:var(--mono);color:${verdictColor}">${d.verdict.toUpperCase()}</div>
          <div style="font-family:var(--mono);font-size:13px;color:var(--text2);margin-top:4px;word-break:break-all">${escHtml(d.url)}</div>
        </div>
      </div>
    </div>
    <div class="tools-grid tools-grid-3">
      <div class="tool-card">
        <div class="tool-card-header">&#128737; VirusTotal</div>
        <div class="tool-metric-row" style="margin-bottom:8px">
          <span>Detections</span>
          <span style="font-family:var(--mono);font-weight:700;color:${vt.detections>0?'var(--danger)':'var(--accent)'}">${vt.detections} / ${vt.total}</span>
        </div>
        ${vt.stats ? `
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
          ${Object.entries(vt.stats).map(([k,v])=>`<span style="font-family:var(--mono);font-size:11px;padding:2px 8px;background:var(--bg3);border-radius:4px;border:1px solid var(--border)">${k}: ${v}</span>`).join('')}
        </div>` : ''}
        ${flaggedHtml}
      </div>
      <div class="tool-card">
        <div class="tool-card-header">&#128041; PhishTank</div>
        <div style="font-size:13px;margin-top:4px">${ptStatus}</div>
        ${pt.phish_id ? `<div style="font-family:var(--mono);font-size:11px;color:var(--text3);margin-top:8px">ID: ${escHtml(String(pt.phish_id))}</div>` : ''}
      </div>
      <div class="tool-card">
        <div class="tool-card-header">&#128737; Google Safe Browsing</div>
        <div style="font-size:13px;margin-top:4px">${gsbStatus}</div>
        ${!gsb.threats?.length && !d.sources.google_safe_browsing._enabled ? '<div style="font-size:11px;color:var(--text3);margin-top:6px">Add GOOGLE_SAFE_BROWSING_KEY to .env to enable</div>' : ''}
      </div>
    </div>`;
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
