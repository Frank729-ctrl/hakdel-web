<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'osint-email';
$topbar_title = 'Email Investigator';
$gate_feature = 'Email Investigator'; $gate_hard = true; require __DIR__ . '/../partials/pro_gate.php';

$pdo = db();

$pdo->exec("CREATE TABLE IF NOT EXISTS email_checks (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    email       VARCHAR(320) NOT NULL,
    domain      VARCHAR(255) NOT NULL,
    result_json MEDIUMTEXT,
    checked_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id)
)");

// ── Helpers ───────────────────────────────────────────────────────────────────

function email_mx(string $domain): array {
    $records = @dns_get_record($domain, DNS_MX) ?: [];
    usort($records, fn($a,$b) => ($a['pri']??0) <=> ($b['pri']??0));
    return array_map(fn($r) => ['priority' => $r['pri']??0, 'host' => rtrim($r['target']??'','.')], $records);
}

function email_spf(string $domain): array {
    $records = @dns_get_record($domain, DNS_TXT) ?: [];
    foreach ($records as $r) {
        $txt = $r['txt'] ?? '';
        if (str_starts_with($txt, 'v=spf1')) {
            $pass = !str_contains($txt, '+all');
            return ['found' => true, 'record' => $txt, 'pass' => $pass,
                    'note' => $pass ? 'SPF present and restrictive' : 'SPF present but ends with +all (too permissive)'];
        }
    }
    return ['found' => false, 'record' => null, 'pass' => false, 'note' => 'No SPF record found — spoofing risk'];
}

function email_dmarc(string $domain): array {
    $records = @dns_get_record('_dmarc.' . $domain, DNS_TXT) ?: [];
    foreach ($records as $r) {
        $txt = $r['txt'] ?? '';
        if (str_starts_with($txt, 'v=DMARC1')) {
            preg_match('/p=(\w+)/i', $txt, $pm);
            $policy = strtolower($pm[1] ?? 'none');
            preg_match('/pct=(\d+)/i', $txt, $pct);
            $pass = in_array($policy, ['quarantine','reject']);
            return ['found' => true, 'record' => $txt, 'policy' => $policy,
                    'pct' => (int)($pct[1] ?? 100), 'pass' => $pass,
                    'note' => $pass ? "Policy: {$policy}" : "Policy is 'none' — no enforcement"];
        }
    }
    return ['found' => false, 'record' => null, 'policy' => null, 'pass' => false, 'note' => 'No DMARC record found'];
}

function email_dkim(string $domain): array {
    // Check common selectors
    $selectors = ['default','google','mail','dkim','k1','smtp','email','selector1','selector2','s1','s2'];
    $found = [];
    foreach ($selectors as $sel) {
        $host = "{$sel}._domainkey.{$domain}";
        $r = @dns_get_record($host, DNS_TXT) ?: [];
        foreach ($r as $rec) {
            $txt = $rec['txt'] ?? '';
            if (str_contains($txt, 'v=DKIM1') || str_contains($txt, 'p=')) {
                $found[] = ['selector' => $sel, 'record' => substr($txt, 0, 80) . (strlen($txt) > 80 ? '...' : '')];
                break;
            }
        }
    }
    return ['found' => count($found) > 0, 'keys' => $found];
}

function email_disposable(string $domain): bool {
    // Common disposable email domains
    $disposable = ['mailinator.com','guerrillamail.com','10minutemail.com','tempmail.com',
        'throwaway.email','yopmail.com','trashmail.com','fakeinbox.com','sharklasers.com',
        'guerrillamailblock.com','grr.la','guerrillamail.info','spam4.me','maildrop.cc',
        'dispostable.com','mailnull.com','spamgourmet.com','trashmail.me','wegwerfmail.de',
        'discard.email','filzmail.com','spamex.com','spamfree24.org','jetable.fr.nf',
        'notsharingmy.info','objectmail.com','obobbo.com','odnorazovoe.ru','oneoffmail.com'];
    return in_array(strtolower($domain), $disposable);
}

function email_format(string $email): array {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['valid' => false, 'note' => 'Invalid email format'];
    }
    $parts = explode('@', $email);
    return ['valid' => true, 'local' => $parts[0], 'domain' => $parts[1] ?? '',
            'note' => 'Valid format'];
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) { http_response_code(403); exit; }

    $email  = strtolower(trim($_POST['email'] ?? ''));
    $format = email_format($email);

    if (!$format['valid']) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $format['note']]); exit;
    }

    $domain = $format['domain'];

    $mx          = email_mx($domain);
    $spf         = email_spf($domain);
    $dmarc       = email_dmarc($domain);
    $dkim        = email_dkim($domain);
    $disposable  = email_disposable($domain);
    $domain_age  = null;

    // MX reachability — try connecting to first MX
    $mx_reachable = false;
    if (!empty($mx)) {
        $sock = @fsockopen($mx[0]['host'], 25, $errno, $errstr, 5);
        if ($sock) { $mx_reachable = true; fclose($sock); }
        else {
            // Try port 587
            $sock = @fsockopen($mx[0]['host'], 587, $errno, $errstr, 5);
            if ($sock) { $mx_reachable = true; fclose($sock); }
        }
    }

    // Overall score
    $score = 0;
    if (!empty($mx))        $score += 25;
    if ($mx_reachable)      $score += 10;
    if ($spf['pass'])       $score += 20;
    if ($dmarc['pass'])     $score += 25;
    if ($dkim['found'])     $score += 15;
    if (!$disposable)       $score += 5;

    $result = compact('email','domain','format','mx','mx_reachable','spf','dmarc','dkim','disposable','score');

    $pdo->prepare('INSERT INTO email_checks (user_id, email, domain, result_json) VALUES (?,?,?,?)')
        ->execute([$user['id'], $email, $domain, json_encode($result)]);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $result]); exit;
}

// ── History ───────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT id, email, domain, checked_at FROM email_checks WHERE user_id = ? ORDER BY checked_at DESC LIMIT 10');
$stmt->execute([$user['id']]);
$history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Email Investigator — HakDel</title>
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
      <h1 class="hk-page-title">Email Investigator</h1>
      <p class="hk-page-sub">SPF, DMARC, DKIM validation · MX reachability · disposable detection</p>
    </div>
  </div>

  <div class="tool-card">
    <form onsubmit="checkEmail(event)">
      <div style="display:flex;gap:10px;align-items:flex-end">
        <div style="flex:1">
          <label class="tool-label">Email Address</label>
          <input type="text" id="email-input" class="tool-input" placeholder="user@example.com" autocomplete="off" spellcheck="false">
        </div>
        <button type="submit" class="btn-tool-primary" id="check-btn">Investigate</button>
      </div>
    </form>
  </div>

  <div id="loading" style="display:none" class="tool-card">
    <div class="tool-loading-dots"><span></span><span></span><span></span></div>
    <div style="font-size:13px;color:var(--text3);margin-top:10px" id="loading-msg">Checking MX records...</div>
  </div>

  <div id="results" style="display:none"></div>

  <?php if ($history): ?>
  <div class="tool-card">
    <div class="tool-card-header">&#9783; Recent Checks</div>
    <table class="ip-history-table">
      <thead><tr><th>Email</th><th>Domain</th><th>Date</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($history as $h): ?>
        <tr>
          <td style="font-family:var(--mono);font-size:12px"><?php echo h($h['email']); ?></td>
          <td style="color:var(--text3)"><?php echo h($h['domain']); ?></td>
          <td style="color:var(--text3)"><?php echo date('M j, H:i', strtotime($h['checked_at'])); ?></td>
          <td><a href="#" onclick="rerun(<?php echo h(json_encode($h['email'])); ?>);return false" style="color:var(--accent);font-size:12px">&#8635;</a></td>
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

function rerun(email) {
  document.getElementById('email-input').value = email;
  checkEmail(null);
}

async function checkEmail(e) {
  if (e) e.preventDefault();
  const email = document.getElementById('email-input').value.trim();
  if (!email) return;

  document.getElementById('results').style.display = 'none';
  document.getElementById('loading').style.display = 'block';
  document.getElementById('check-btn').disabled = true;

  const msgs = ['Checking MX records...','Validating SPF...','Checking DMARC...','Probing DKIM selectors...'];
  let mi = 0;
  const ti = setInterval(() => { document.getElementById('loading-msg').textContent = msgs[mi++ % msgs.length]; }, 1400);

  try {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('email', email);
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

  const scoreColor = d.score >= 70 ? 'var(--accent)' : d.score >= 40 ? '#f59e0b' : 'var(--danger)';

  const check = (pass, label, note) =>
    `<div class="email-check-row">
      <span class="email-check-icon" style="color:${pass?'var(--accent)':'var(--danger)'}">${pass?'✓':'✗'}</span>
      <span class="email-check-label">${label}</span>
      <span class="email-check-note">${escHtml(note||'')}</span>
    </div>`;

  const mxHtml = d.mx?.length
    ? d.mx.map(m=>`<div class="osint-dns-row"><span class="osint-dns-type" style="background:rgba(245,158,11,0.12);color:#f59e0b">MX</span><span class="osint-dns-val">${escHtml(m.priority+' '+m.host)}</span></div>`).join('')
    : '<div class="tool-no-data">No MX records</div>';

  const dkimHtml = d.dkim?.found
    ? d.dkim.keys.map(k=>`<div style="font-size:12px;color:var(--text2);margin-bottom:6px"><span style="font-family:var(--mono);color:var(--accent)">${escHtml(k.selector)}._domainkey</span><br><span style="color:var(--text3)">${escHtml(k.record)}</span></div>`).join('')
    : '<div class="tool-no-data">No DKIM keys found (checked common selectors)</div>';

  r.innerHTML = `
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;margin-bottom:16px;display:flex;align-items:center;gap:20px">
      <div>
        <div style="font-family:var(--mono);font-size:22px;font-weight:700;color:var(--text)">${escHtml(d.email)}</div>
        <div style="display:flex;gap:10px;margin-top:8px;flex-wrap:wrap">
          ${d.disposable ? '<span class="risk-badge malicious">Disposable</span>' : '<span class="risk-badge clean">Not Disposable</span>'}
          ${d.mx?.length ? '<span class="risk-badge clean">MX Found</span>' : '<span class="risk-badge malicious">No MX</span>'}
          ${d.mx_reachable ? '<span class="risk-badge clean">MX Reachable</span>' : '<span class="risk-badge suspicious">MX Unreachable</span>'}
        </div>
      </div>
      <div style="margin-left:auto;text-align:center;flex-shrink:0">
        <div style="font-family:var(--mono);font-size:36px;font-weight:700;color:${scoreColor}">${d.score}</div>
        <div style="font-size:11px;color:var(--text3);font-family:var(--mono)">TRUST SCORE</div>
      </div>
    </div>
    <div class="tools-grid tools-grid-2">
      <div class="tool-card">
        <div class="tool-card-header">&#9993; Email Validation</div>
        <div style="display:flex;flex-direction:column;gap:2px;margin-top:8px">
          ${check(d.format?.valid, 'Format', d.format?.note)}
          ${check(d.mx?.length > 0, 'MX Records', d.mx?.length ? `${d.mx.length} record${d.mx.length>1?'s':''} found` : 'No mail servers')}
          ${check(d.mx_reachable, 'MX Reachable', d.mx_reachable ? 'Mail server responding' : 'Cannot connect to mail server')}
          ${check(!d.disposable, 'Not Disposable', d.disposable ? 'Known disposable domain' : 'Not a disposable provider')}
        </div>
      </div>
      <div class="tool-card">
        <div class="tool-card-header">&#128737; Anti-Spoofing</div>
        <div style="display:flex;flex-direction:column;gap:2px;margin-top:8px">
          ${check(d.spf?.pass, 'SPF', d.spf?.note)}
          ${check(d.dmarc?.pass, 'DMARC', d.dmarc?.note)}
          ${check(d.dkim?.found, 'DKIM', d.dkim?.found ? `${d.dkim.keys.length} key(s) found` : 'No keys found')}
        </div>
        ${d.spf?.record ? `<div style="margin-top:12px"><div style="font-size:11px;color:var(--text3);font-family:var(--mono);margin-bottom:4px">SPF RECORD</div><code style="font-size:11px;color:var(--accent);word-break:break-all">${escHtml(d.spf.record)}</code></div>` : ''}
        ${d.dmarc?.record ? `<div style="margin-top:10px"><div style="font-size:11px;color:var(--text3);font-family:var(--mono);margin-bottom:4px">DMARC RECORD</div><code style="font-size:11px;color:var(--accent);word-break:break-all">${escHtml(d.dmarc.record)}</code></div>` : ''}
      </div>
      <div class="tool-card">
        <div class="tool-card-header">&#9993; MX Records</div>
        <div class="osint-dns-list" style="margin-top:8px">${mxHtml}</div>
      </div>
      <div class="tool-card">
        <div class="tool-card-header">&#128273; DKIM Keys</div>
        <div style="margin-top:8px">${dkimHtml}</div>
      </div>
    </div>`;
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
