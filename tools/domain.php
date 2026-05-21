<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'osint-domain';
$topbar_title = 'Domain Intel';
$gate_feature = 'Domain Intel'; $gate_hard = true; require __DIR__ . '/../partials/pro_gate.php';

$pdo = db();

// ── Ensure table ──────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS domain_lookups (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    domain     VARCHAR(255) NOT NULL,
    result_json MEDIUMTEXT,
    looked_up_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id)
)");

// ── Helpers ───────────────────────────────────────────────────────────────────
function osint_whois(string $domain): array {
    $parts  = explode('.', $domain);
    $tld    = end($parts);
    $server = "{$tld}.whois-servers.net";
    $raw    = '';
    $sock   = @fsockopen($server, 43, $errno, $errstr, 8);
    if ($sock) {
        fwrite($sock, $domain . "\r\n");
        while (!feof($sock)) $raw .= fgets($sock, 4096);
        fclose($sock);
    }
    $fields = [];
    $map = [
        'Registrar'          => '/Registrar:\s*(.+)/i',
        'Registered'         => '/Creation Date:\s*(.+)/i',
        'Updated'            => '/Updated Date:\s*(.+)/i',
        'Expires'            => '/Expir(?:y|ation) Date:\s*(.+)/i',
        'Status'             => '/Domain Status:\s*(.+)/i',
        'Name Servers'       => '/Name Server:\s*(.+)/i',
        'Registrant Org'     => '/Registrant Organization:\s*(.+)/i',
        'Registrant Country' => '/Registrant Country:\s*(.+)/i',
    ];
    foreach ($map as $label => $pattern) {
        preg_match_all($pattern, $raw, $m);
        if (!empty($m[1])) {
            $vals = array_values(array_unique(array_map('trim', $m[1])));
            $fields[$label] = count($vals) === 1 ? $vals[0] : $vals;
        }
    }
    return ['raw' => $raw, 'fields' => $fields];
}

function osint_dns(string $domain): array {
    $out = [];
    $types = [DNS_A => 'A', DNS_AAAA => 'AAAA', DNS_MX => 'MX',
              DNS_NS => 'NS', DNS_TXT => 'TXT', DNS_CNAME => 'CNAME'];
    foreach ($types as $type => $name) {
        $records = @dns_get_record($domain, $type) ?: [];
        foreach ($records as $r) {
            $val = match($name) {
                'A'     => $r['ip']     ?? '',
                'AAAA'  => $r['ipv6']   ?? '',
                'MX'    => ($r['pri'] ?? 0) . ' ' . ($r['target'] ?? ''),
                'NS'    => rtrim($r['target'] ?? '', '.'),
                'TXT'   => $r['txt']    ?? '',
                'CNAME' => $r['target'] ?? '',
                default => '',
            };
            if ($val) $out[$name][] = $val;
        }
    }
    return $out;
}

function osint_subdomains(string $domain): array {
    $url  = "https://crt.sh/?q=%.{$domain}&output=json";
    $ch   = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => 'HakDel/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body) return [];
    $data = json_decode($body, true) ?: [];
    $subs = [];
    foreach ($data as $entry) {
        $names = explode("\n", $entry['name_value'] ?? '');
        foreach ($names as $n) {
            $n = strtolower(trim($n));
            if ($n && str_ends_with($n, $domain) && $n !== $domain) {
                $subs[] = ltrim($n, '*.');
            }
        }
    }
    $subs = array_values(array_unique($subs));
    sort($subs);
    return array_slice($subs, 0, 100);
}

function osint_reverse_ip(string $ip): array {
    $url = "https://api.hackertarget.com/reverseiplookup/?q=" . urlencode($ip);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_USERAGENT => 'HakDel/1.0']);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body || str_contains($body, 'error') || str_contains($body, 'API count')) return [];
    return array_filter(array_map('trim', explode("\n", $body)));
}

function osint_ip_reputation(string $ip, string $key): array {
    if (!$key || !$ip) return [];
    $ch = curl_init("https://api.abuseipdb.com/api/v2/check?ipAddress=" . urlencode($ip) . "&maxAgeInDays=90");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ["Key: {$key}", "Accept: application/json"],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $d = json_decode($body, true)['data'] ?? [];
    return $d ? [
        'score'    => $d['abuseConfidenceScore'] ?? 0,
        'country'  => $d['countryCode'] ?? '',
        'isp'      => $d['isp'] ?? '',
        'reports'  => $d['totalReports'] ?? 0,
        'hostname' => $d['hostnames'][0] ?? '',
    ] : [];
}

// ── POST handler ──────────────────────────────────────────────────────────────
$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) { http_response_code(403); exit; }

    $domain = strtolower(trim($_POST['domain'] ?? ''));
    $domain = preg_replace('#^https?://#', '', $domain);
    $domain = explode('/', $domain)[0];
    $domain = explode('?', $domain)[0];
    $domain = preg_replace('/[^a-z0-9.\-]/', '', $domain);

    if (!$domain || !preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)+$/', $domain)) {
        $error = 'Invalid domain name.';
    } else {
        // Resolve IP
        $ip = gethostbyname($domain);
        $ip = ($ip === $domain) ? null : $ip;

        // Run all lookups in parallel via curl_multi where possible
        $dns      = osint_dns($domain);
        $whois    = osint_whois($domain);
        $subs     = osint_subdomains($domain);
        $rev_ip   = $ip ? osint_reverse_ip($ip) : [];
        $ip_rep   = $ip ? osint_ip_reputation($ip, getenv('ABUSEIPDB_API_KEY') ?: '') : [];

        $result = compact('domain', 'ip', 'dns', 'whois', 'subs', 'rev_ip', 'ip_rep');

        // Save
        $pdo->prepare('INSERT INTO domain_lookups (user_id, domain, result_json) VALUES (?,?,?)')
            ->execute([$user['id'], $domain, json_encode($result)]);
    }

    header('Content-Type: application/json');
    echo json_encode($error ? ['error' => $error] : ['ok' => true, 'data' => $result]);
    exit;
}

// ── History ───────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT id, domain, looked_up_at FROM domain_lookups WHERE user_id = ? ORDER BY looked_up_at DESC LIMIT 10');
$stmt->execute([$user['id']]);
$history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Domain Intel — HakDel</title>
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
      <h1 class="hk-page-title">Domain Intel</h1>
      <p class="hk-page-sub">WHOIS, DNS records, subdomains, reverse IP and reputation</p>
    </div>
  </div>

  <!-- Input -->
  <div class="tool-card">
    <form id="domain-form" onsubmit="lookupDomain(event)">
      <div style="display:flex;gap:10px;align-items:flex-end">
        <div style="flex:1">
          <label class="tool-label">Domain</label>
          <input type="text" id="domain-input" class="tool-input" placeholder="e.g. example.com" autocomplete="off" spellcheck="false">
        </div>
        <button type="submit" class="btn-tool-primary" id="lookup-btn">Investigate</button>
      </div>
    </form>
  </div>

  <!-- Loading -->
  <div id="loading" style="display:none" class="tool-card" style="text-align:center">
    <div class="tool-loading-dots"><span></span><span></span><span></span></div>
    <div style="font-size:13px;color:var(--text3);margin-top:10px" id="loading-status">Resolving DNS...</div>
  </div>

  <!-- Results -->
  <div id="results" style="display:none"></div>

  <!-- History -->
  <?php if ($history): ?>
  <div class="tool-card">
    <div class="tool-card-header">&#9783; Recent Lookups</div>
    <table class="ip-history-table">
      <thead><tr><th>Domain</th><th>Date</th><th>Re-run</th></tr></thead>
      <tbody>
        <?php foreach ($history as $h): ?>
        <tr>
          <td style="font-family:var(--mono)"><?php echo h($h['domain']); ?></td>
          <td style="color:var(--text3)"><?php echo date('M j, H:i', strtotime($h['looked_up_at'])); ?></td>
          <td><a href="#" onclick="rerun(<?php echo h(json_encode($h['domain'])); ?>);return false" style="color:var(--accent);font-size:12px">&#8635; Re-run</a></td>
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

function rerun(domain) {
  document.getElementById('domain-input').value = domain;
  lookupDomain(null);
}

async function lookupDomain(e) {
  if (e) e.preventDefault();
  const domain = document.getElementById('domain-input').value.trim();
  if (!domain) return;

  document.getElementById('results').style.display = 'none';
  document.getElementById('loading').style.display = 'block';
  document.getElementById('lookup-btn').disabled = true;

  const steps = ['Resolving DNS...','Querying WHOIS...','Fetching subdomains...','Checking IP reputation...'];
  let si = 0;
  const stInt = setInterval(() => {
    document.getElementById('loading-status').textContent = steps[si++ % steps.length];
  }, 1800);

  try {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('domain', domain);
    const res  = await fetch('', {method:'POST', body: fd});
    const json = await res.json();
    clearInterval(stInt);
    document.getElementById('loading').style.display = 'none';
    document.getElementById('lookup-btn').disabled = false;
    if (json.error) { showError(json.error); return; }
    renderResults(json.data);
  } catch(err) {
    clearInterval(stInt);
    document.getElementById('loading').style.display = 'none';
    document.getElementById('lookup-btn').disabled = false;
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

  const dnsColors = {A:'#00d4aa',AAAA:'#60a5fa',MX:'#f59e0b',NS:'#a78bfa',TXT:'#fb923c',CNAME:'#34d399'};

  // ── DNS block ──
  let dnsHtml = '';
  for (const [type, vals] of Object.entries(d.dns || {})) {
    const color = dnsColors[type] || '#aaa';
    for (const v of vals) {
      dnsHtml += `<div class="osint-dns-row">
        <span class="osint-dns-type" style="background:${color}18;color:${color}">${type}</span>
        <span class="osint-dns-val">${escHtml(v)}</span>
      </div>`;
    }
  }
  if (!dnsHtml) dnsHtml = '<div class="tool-no-data">No DNS records found</div>';

  // ── WHOIS block ──
  let whoisHtml = '';
  const fields = d.whois?.fields || {};
  if (Object.keys(fields).length) {
    for (const [k,v] of Object.entries(fields)) {
      const val = Array.isArray(v) ? v.join(', ') : v;
      whoisHtml += `<div class="osint-whois-row"><span class="osint-whois-key">${escHtml(k)}</span><span class="osint-whois-val">${escHtml(val)}</span></div>`;
    }
  } else {
    whoisHtml = '<div class="tool-no-data">WHOIS data unavailable</div>';
  }

  // ── Subdomains ──
  let subsHtml = '';
  if (d.subs?.length) {
    subsHtml = d.subs.map(s => `<span class="osint-sub-pill">${escHtml(s)}</span>`).join('');
  } else {
    subsHtml = '<div class="tool-no-data">No subdomains found in certificate transparency logs</div>';
  }

  // ── IP reputation ──
  let ipHtml = '';
  if (d.ip) {
    const rep  = d.ip_rep || {};
    const score = rep.score ?? null;
    const scoreColor = score === null ? 'var(--text3)' : score >= 50 ? 'var(--danger)' : score >= 10 ? '#f59e0b' : 'var(--accent)';
    ipHtml = `<div class="tool-data-grid">
      <div class="tool-metric-row"><span>IP Address</span><span style="font-family:var(--mono)">${escHtml(d.ip)}</span></div>
      ${score !== null ? `<div class="tool-metric-row"><span>Abuse Score</span><span style="color:${scoreColor};font-weight:700">${score}/100</span></div>` : ''}
      ${rep.isp    ? `<div class="tool-metric-row"><span>ISP</span><span>${escHtml(rep.isp)}</span></div>` : ''}
      ${rep.country? `<div class="tool-metric-row"><span>Country</span><span>${escHtml(rep.country)}</span></div>` : ''}
      ${rep.reports? `<div class="tool-metric-row"><span>Reports</span><span>${rep.reports}</span></div>` : ''}
    </div>`;
    if (d.rev_ip?.length) {
      ipHtml += `<div style="margin-top:12px">
        <div style="font-size:11px;color:var(--text3);font-family:var(--mono);margin-bottom:8px">ALSO ON THIS IP (${d.rev_ip.length})</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px">
          ${d.rev_ip.slice(0,30).map(h => `<span class="osint-sub-pill">${escHtml(h)}</span>`).join('')}
        </div>
      </div>`;
    }
  } else {
    ipHtml = '<div class="tool-no-data">Could not resolve IP</div>';
  }

  r.innerHTML = `
    <div class="osint-result-header">
      <div class="osint-domain-name">${escHtml(d.domain)}</div>
      ${d.ip ? `<div class="osint-domain-ip">${escHtml(d.ip)}</div>` : ''}
    </div>
    <div class="tools-grid tools-grid-2">
      <div class="tool-card">
        <div class="tool-card-header">&#127760; DNS Records</div>
        <div class="osint-dns-list">${dnsHtml}</div>
      </div>
      <div class="tool-card">
        <div class="tool-card-header">&#128196; WHOIS</div>
        <div class="osint-whois-list">${whoisHtml}</div>
      </div>
      <div class="tool-card">
        <div class="tool-card-header">&#127744; IP Reputation</div>
        ${ipHtml}
      </div>
      <div class="tool-card">
        <div class="tool-card-header">&#128204; Subdomains <span style="font-size:11px;color:var(--text3);margin-left:6px">${d.subs?.length || 0} found via crt.sh</span></div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;padding-top:4px">${subsHtml}</div>
      </div>
    </div>`;
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
