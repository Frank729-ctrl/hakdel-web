<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'tools-network';
$topbar_title = 'Network Tools';
$gate_feature = 'Network Tools'; $gate_hard = true; require __DIR__ . '/../partials/pro_gate.php';

$uid = (int)$user['id'];

$active_tab = $_GET['tab'] ?? 'dns';
$allowed_tabs = ['dns', 'ping', 'whois', 'headers'];
if (!in_array($active_tab, $allowed_tabs)) $active_tab = 'dns';

$dns_result    = null;
$ping_result   = null;
$whois_result  = null;
$headers_result = null;
$error = '';

// ── DNS Lookup ──
if ($active_tab === 'dns' && is_post() && verify_csrf($_POST['csrf'] ?? '')) {
    $domain = trim($_POST['dns_domain'] ?? '');
    $type   = strtoupper(trim($_POST['dns_type'] ?? 'A'));
    $valid_types = ['A', 'AAAA', 'MX', 'NS', 'TXT', 'CNAME', 'SOA', 'ALL'];
    if (!$domain) {
        $error = 'Please enter a domain.';
    } elseif (!in_array($type, $valid_types)) {
        $error = 'Invalid record type.';
    } else {
        try {
            if ($type === 'ALL') {
                $records = [];
                foreach (['A', 'AAAA', 'MX', 'NS', 'TXT', 'CNAME', 'SOA'] as $t) {
                    $r = @dns_get_record($domain, constant('DNS_' . $t));
                    if ($r) $records[$t] = $r;
                }
                $dns_result = ['domain' => $domain, 'type' => 'ALL', 'records' => $records];
            } else {
                $r = @dns_get_record($domain, constant('DNS_' . $type));
                $dns_result = ['domain' => $domain, 'type' => $type, 'records' => [$type => $r ?: []]];
            }
        } catch (Exception $e) {
            $error = 'DNS lookup failed: ' . $e->getMessage();
        }
    }
}

// ── Ping / Connectivity ──
if ($active_tab === 'ping' && is_post() && verify_csrf($_POST['csrf'] ?? '')) {
    $host = trim($_POST['ping_host'] ?? '');
    if (!$host) {
        $error = 'Please enter a hostname or IP.';
    } else {
        $ip = @gethostbyname($host);
        $resolved = ($ip !== $host) ? $ip : null;
        $ht_url = 'https://api.hackertarget.com/hostsearch/?q=' . urlencode($host);
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'HakDel/1.0']]);
        $ht_raw = @file_get_contents($ht_url, false, $ctx);
        $ping_result = [
            'host'     => $host,
            'resolved' => $resolved,
            'ip'       => $ip,
            'ht_data'  => $ht_raw ?: null,
        ];
    }
}

// ── WHOIS ──
if ($active_tab === 'whois' && is_post() && verify_csrf($_POST['csrf'] ?? '')) {
    $domain = strtolower(trim($_POST['whois_domain'] ?? ''));
    // Strip protocol
    $domain = preg_replace('#^https?://#', '', $domain);
    $domain = explode('/', $domain)[0];
    if (!$domain) {
        $error = 'Please enter a domain.';
    } else {
        // Determine TLD-based WHOIS server
        $tld = strtolower(substr(strrchr($domain, '.'), 1));
        $whois_servers = [
            'com' => 'whois.verisign-grs.com', 'net' => 'whois.verisign-grs.com',
            'org' => 'whois.pir.org', 'io' => 'whois.nic.io',
            'co' => 'whois.nic.co', 'uk' => 'whois.nic.uk',
            'de' => 'whois.denic.de', 'nl' => 'whois.domain-registry.nl',
            'fr' => 'whois.nic.fr', 'eu' => 'whois.eu',
        ];
        $server = $whois_servers[$tld] ?? 'whois.iana.org';
        $fp = @fsockopen($server, 43, $errno, $errstr, 10);
        if (!$fp) {
            $error = 'Could not connect to WHOIS server (' . $server . ').';
        } else {
            fputs($fp, $domain . "\r\n");
            $raw = '';
            while (!feof($fp)) $raw .= fread($fp, 1024);
            fclose($fp);
            $whois_result = ['domain' => $domain, 'server' => $server, 'raw' => $raw];
        }
    }
}

// ── HTTP Headers ──
if ($active_tab === 'headers' && is_post() && verify_csrf($_POST['csrf'] ?? '')) {
    $url = trim($_POST['headers_url'] ?? '');
    if (!$url) {
        $error = 'Please enter a URL.';
    } else {
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_HEADER         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'HakDel/1.0',
            ]);
            $raw = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($err) {
                $error = 'cURL error: ' . $err;
            } else {
                // Parse last response headers
                $parts = explode("\r\n\r\n", $raw);
                $last_header = trim(end($parts));
                if (!$last_header) $last_header = trim($parts[0]);
                $headers_result = ['url' => $url, 'code' => $http_code, 'raw' => $last_header];
            }
        } else {
            // Fallback: get_headers
            $hdrs = @get_headers($url, 1);
            if ($hdrs === false) {
                $error = 'Failed to fetch headers.';
            } else {
                $raw = implode("\r\n", array_map(fn($k, $v) => is_int($k) ? $v : "$k: $v", array_keys($hdrs), $hdrs));
                $headers_result = ['url' => $url, 'code' => null, 'raw' => $raw];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Network Tools — HakDel</title>
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="stylesheet" href="/assets/layout.css">
  <link rel="stylesheet" href="/assets/tools.css">
  <style>
    .net-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
    .net-tab {
      display: flex; align-items: center; gap: 6px;
      background: var(--bg2); border: 1px solid var(--border);
      color: var(--text2); font-family: var(--mono); font-size: 12px;
      padding: 8px 16px; border-radius: var(--radius); cursor: pointer;
      text-decoration: none; transition: all 0.12s;
    }
    .net-tab:hover { border-color: rgba(255,255,255,0.2); color: var(--text); }
    .net-tab.active { background: rgba(0,212,170,0.1); border-color: var(--accent); color: var(--accent); }
    .net-panel { display: none; }
    .net-panel.active { display: block; }
    .net-card {
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: var(--radius-lg); overflow: hidden;
    }
    .net-card-header {
      padding: 14px 18px; border-bottom: 1px solid var(--border);
      font-family: var(--mono); font-size: 12px; font-weight: 700; color: var(--text);
    }
    .net-card-body { padding: 18px; display: flex; flex-direction: column; gap: 14px; }
    .dns-record-group { margin-bottom: 16px; }
    .dns-type-label {
      font-family: var(--mono); font-size: 10px; color: var(--accent);
      text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px;
    }
    .dns-record {
      display: flex; gap: 12px; align-items: flex-start;
      padding: 8px 12px; background: var(--bg3);
      border: 1px solid var(--border); border-radius: var(--radius);
      margin-bottom: 6px; font-size: 12px; font-family: var(--mono);
    }
    .dns-rec-key { color: var(--text3); min-width: 60px; flex-shrink: 0; }
    .dns-rec-val { color: var(--text); word-break: break-all; }
    .raw-output {
      font-family: var(--mono); font-size: 12px; color: var(--text2);
      background: var(--bg3); padding: 16px; border-radius: var(--radius);
      overflow-x: auto; white-space: pre-wrap; line-height: 1.6;
      max-height: 450px; overflow-y: auto;
      border: 1px solid var(--border);
    }
    .ping-result-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px;
    }
    .ping-result-item {
      background: var(--bg3); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 12px;
    }
    .ping-result-label { font-family: var(--mono); font-size: 10px; color: var(--text3); text-transform: uppercase; }
    .ping-result-val { font-family: var(--mono); font-size: 14px; color: var(--text); margin-top: 4px; font-weight: 700; }
    .resolved-ok { color: var(--accent); }
    .resolved-fail { color: var(--danger); }
    .form-field { display: flex; flex-direction: column; gap: 5px; }
    .form-label { font-size: 12px; font-weight: 600; color: var(--text2); }
    .form-input, .form-select {
      background: var(--bg3); border: 1px solid var(--border2);
      border-radius: var(--radius); padding: 9px 12px;
      font-size: 13px; color: var(--text); outline: none;
      transition: border-color 0.15s; font-family: inherit;
    }
    .form-input:focus, .form-select:focus { border-color: var(--accent); }
    .form-select option { background: var(--bg2); }
    .submit-btn {
      display: inline-flex; align-items: center;
      background: var(--accent); color: var(--bg); border: none;
      border-radius: var(--radius); padding: 9px 20px;
      font-family: var(--mono); font-size: 13px; font-weight: 700;
      cursor: pointer; transition: opacity 0.15s;
    }
    .submit-btn:hover { opacity: 0.85; }
    .form-row { display: flex; gap: 10px; align-items: flex-end; }
    .err-box {
      background: rgba(255,77,77,0.08); border: 1px solid rgba(255,77,77,0.2);
      border-radius: var(--radius); padding: 10px 14px; font-size: 13px; color: var(--danger);
    }
    .no-records { font-size: 13px; color: var(--text3); font-style: italic; padding: 8px 0; }
  </style>
</head>
<body>
<?php require __DIR__ . '/../partials/topbar.php'; ?>
<div class="hk-shell">
<?php require __DIR__ . '/../partials/sidebar.php'; ?>
<main class="hk-main">

  <div class="hk-page-header">
    <div>
      <div class="hk-page-eyebrow">INVESTIGATE</div>
      <h1 class="hk-page-title">Network Tools</h1>
      <p class="hk-page-sub">DNS lookup, connectivity check, WHOIS and HTTP headers</p>
    </div>
  </div>

  <!-- Tabs -->
  <div class="net-tabs">
    <a href="?tab=dns" class="net-tab <?php echo $active_tab === 'dns' ? 'active' : ''; ?>">&#128270; DNS Lookup</a>
    <a href="?tab=ping" class="net-tab <?php echo $active_tab === 'ping' ? 'active' : ''; ?>">&#128268; Ping / Resolve</a>
    <a href="?tab=whois" class="net-tab <?php echo $active_tab === 'whois' ? 'active' : ''; ?>">&#128203; WHOIS</a>
    <a href="?tab=headers" class="net-tab <?php echo $active_tab === 'headers' ? 'active' : ''; ?>">&#128737; HTTP Headers</a>
  </div>

  <?php if ($error): ?>
  <div class="err-box"><?php echo h($error); ?></div>
  <?php endif; ?>

  <!-- DNS Panel -->
  <div class="net-panel <?php echo $active_tab === 'dns' ? 'active' : ''; ?>">
    <div class="net-card">
      <div class="net-card-header">&#128270; DNS Record Lookup</div>
      <div class="net-card-body">
        <form method="POST" action="?tab=dns">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
          <div style="display:flex;flex-direction:column;gap:12px">
            <div class="form-row">
              <div class="form-field" style="flex:1">
                <label class="form-label">Domain</label>
                <input type="text" name="dns_domain" class="form-input"
                       placeholder="example.com"
                       value="<?php echo $dns_result ? h($dns_result['domain']) : ''; ?>"
                       required>
              </div>
              <div class="form-field" style="width:120px">
                <label class="form-label">Type</label>
                <select name="dns_type" class="form-select">
                  <?php foreach (['A', 'AAAA', 'MX', 'NS', 'TXT', 'CNAME', 'SOA', 'ALL'] as $t): ?>
                  <option value="<?php echo $t; ?>" <?php echo ($dns_result['type'] ?? 'A') === $t ? 'selected' : ''; ?>>
                    <?php echo $t; ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-field">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="submit-btn">Look up</button>
              </div>
            </div>
          </div>
        </form>

        <?php if ($dns_result): ?>
        <div>
          <div style="font-family:var(--mono);font-size:12px;color:var(--text3);margin-bottom:12px">
            Results for <span style="color:var(--accent)"><?php echo h($dns_result['domain']); ?></span>
          </div>
          <?php foreach ($dns_result['records'] as $type => $records): ?>
          <div class="dns-record-group">
            <div class="dns-type-label"><?php echo h($type); ?> Records</div>
            <?php if (empty($records)): ?>
            <div class="no-records">No <?php echo h($type); ?> records found.</div>
            <?php else: ?>
            <?php foreach ($records as $rec): ?>
            <div class="dns-record">
              <?php
              $display_fields = ['ip', 'ipv6', 'target', 'host', 'txt', 'pri', 'mname', 'rname', 'serial', 'refresh'];
              foreach ($display_fields as $field) {
                  if (isset($rec[$field])) {
                      echo '<span class="dns-rec-key">' . h($field) . '</span>';
                      echo '<span class="dns-rec-val">' . h((string)$rec[$field]) . '</span>';
                  }
              }
              ?>
              <?php if (!empty($rec['ttl'])): ?>
              <span style="margin-left:auto;font-family:var(--mono);font-size:10px;color:var(--text3)">TTL <?php echo (int)$rec['ttl']; ?>s</span>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Ping Panel -->
  <div class="net-panel <?php echo $active_tab === 'ping' ? 'active' : ''; ?>">
    <div class="net-card">
      <div class="net-card-header">&#128268; Ping / Host Resolution</div>
      <div class="net-card-body">
        <form method="POST" action="?tab=ping">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
          <div class="form-row">
            <div class="form-field" style="flex:1">
              <label class="form-label">Hostname or IP</label>
              <input type="text" name="ping_host" class="form-input"
                     placeholder="example.com or 8.8.8.8"
                     value="<?php echo $ping_result ? h($ping_result['host']) : ''; ?>"
                     required>
            </div>
            <div class="form-field">
              <label class="form-label">&nbsp;</label>
              <button type="submit" class="submit-btn">Check</button>
            </div>
          </div>
        </form>

        <div style="font-size:12px;color:var(--text3);line-height:1.5">
          Note: Direct ICMP ping is not available in web applications.
          This tool resolves hostnames and uses the HackerTarget host search API for connectivity information.
        </div>

        <?php if ($ping_result): ?>
        <div class="ping-result-grid">
          <div class="ping-result-item">
            <div class="ping-result-label">Host</div>
            <div class="ping-result-val" style="font-size:12px"><?php echo h($ping_result['host']); ?></div>
          </div>
          <div class="ping-result-item">
            <div class="ping-result-label">Resolved IP</div>
            <div class="ping-result-val <?php echo $ping_result['resolved'] ? 'resolved-ok' : 'resolved-fail'; ?>">
              <?php echo $ping_result['resolved'] ? h($ping_result['ip']) : 'Not resolved'; ?>
            </div>
          </div>
        </div>
        <?php if ($ping_result['ht_data'] && !str_contains((string)$ping_result['ht_data'], 'error')): ?>
        <div>
          <div style="font-family:var(--mono);font-size:10px;color:var(--text3);margin-bottom:8px">HOST SEARCH RESULTS (HackerTarget)</div>
          <pre class="raw-output"><?php echo h($ping_result['ht_data']); ?></pre>
        </div>
        <?php elseif ($ping_result['resolved']): ?>
        <div style="background:rgba(0,212,170,0.06);border:1px solid rgba(0,212,170,0.2);border-radius:var(--radius);padding:12px;font-size:13px;color:var(--accent)">
          &#9679; Host is reachable — resolved to <?php echo h($ping_result['ip']); ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- WHOIS Panel -->
  <div class="net-panel <?php echo $active_tab === 'whois' ? 'active' : ''; ?>">
    <div class="net-card">
      <div class="net-card-header">&#128203; WHOIS Lookup</div>
      <div class="net-card-body">
        <form method="POST" action="?tab=whois">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
          <div class="form-row">
            <div class="form-field" style="flex:1">
              <label class="form-label">Domain</label>
              <input type="text" name="whois_domain" class="form-input"
                     placeholder="example.com"
                     value="<?php echo $whois_result ? h($whois_result['domain']) : ''; ?>"
                     required>
            </div>
            <div class="form-field">
              <label class="form-label">&nbsp;</label>
              <button type="submit" class="submit-btn">WHOIS</button>
            </div>
          </div>
        </form>

        <?php if ($whois_result): ?>
        <div>
          <div style="font-family:var(--mono);font-size:11px;color:var(--text3);margin-bottom:8px">
            Server: <?php echo h($whois_result['server']); ?>
          </div>
          <pre class="raw-output"><?php echo h($whois_result['raw']); ?></pre>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Headers Panel -->
  <div class="net-panel <?php echo $active_tab === 'headers' ? 'active' : ''; ?>">
    <div class="net-card">
      <div class="net-card-header">&#128737; HTTP Response Headers</div>
      <div class="net-card-body">
        <form method="POST" action="?tab=headers">
          <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
          <div class="form-row">
            <div class="form-field" style="flex:1">
              <label class="form-label">URL</label>
              <input type="text" name="headers_url" class="form-input"
                     placeholder="https://example.com"
                     value="<?php echo $headers_result ? h($headers_result['url']) : ''; ?>"
                     required>
            </div>
            <div class="form-field">
              <label class="form-label">&nbsp;</label>
              <button type="submit" class="submit-btn">Fetch</button>
            </div>
          </div>
        </form>

        <?php if ($headers_result): ?>
        <div>
          <?php if ($headers_result['code']): ?>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
            <span style="font-family:var(--mono);font-size:11px;color:var(--text3)">Status:</span>
            <span style="font-family:var(--mono);font-size:13px;font-weight:700;color:<?php echo $headers_result['code'] < 400 ? 'var(--accent)' : 'var(--danger)'; ?>">
              <?php echo (int)$headers_result['code']; ?>
            </span>
          </div>
          <?php endif; ?>
          <pre class="raw-output"><?php echo h($headers_result['raw']); ?></pre>
          <div style="font-size:12px;color:var(--text3);margin-top:8px">
            For a full security header analysis with grades, use the
            <a href="/tools/headers.php" style="color:var(--accent)">Headers Analyser</a>.
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</main>
</div>
</body>
</html>
