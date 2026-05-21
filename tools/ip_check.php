<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'tools';
$sidebar_sub  = 'Security Tools';
$topbar_title = 'IP Checker';
$gate_feature = 'IP Checker'; $gate_hard = true; require __DIR__ . '/../partials/pro_gate.php';

// ── Ensure table exists ───────────────────────────────────────────────────────
try {
    db()->exec("CREATE TABLE IF NOT EXISTS ip_checks (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT UNSIGNED NOT NULL,
        ip_address  VARCHAR(45) NOT NULL,
        result      JSON,
        risk_score  TINYINT UNSIGNED,
        checked_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_ip_user (user_id, checked_at)
    )");
} catch (Exception $e) {}

// ── Country flag helper ───────────────────────────────────────────────────────
function ip_flag(string $cc): string {
    if (strlen($cc) !== 2) return '';
    $c = strtoupper($cc);
    return mb_chr(0x1F1E6 + ord($c[0]) - 65) . mb_chr(0x1F1E6 + ord($c[1]) - 65);
}

// ── AJAX handler (POST) ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!verify_csrf($_POST['csrf'] ?? '')) {
        echo json_encode(['error' => 'Invalid request']); exit;
    }

    $ip = trim($_POST['ip'] ?? '');

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        echo json_encode(['error' => 'Invalid IP address format.']); exit;
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        echo json_encode(['error' => 'Private or reserved IPs cannot be checked.']); exit;
    }

    $abuseipdb_key = getenv('ABUSEIPDB_API_KEY') ?: '';
    $vt_key        = getenv('VIRUSTOTAL_API_KEY') ?: '';
    $shodan_key    = getenv('SHODAN_API_KEY') ?: '';

    // ── Parallel API calls via curl_multi ─────────────────────────────────────
    $mh      = curl_multi_init();
    $handles = [];

    if ($abuseipdb_key) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://api.abuseipdb.com/api/v2/check?' . http_build_query([
                'ipAddress' => $ip, 'maxAgeInDays' => 90, 'verbose' => 'true',
            ]),
            CURLOPT_HTTPHEADER     => ['Key: ' . $abuseipdb_key, 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $handles['abuseipdb'] = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    if ($vt_key) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://www.virustotal.com/api/v3/ip_addresses/' . urlencode($ip),
            CURLOPT_HTTPHEADER     => ['x-apikey: ' . $vt_key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $handles['virustotal'] = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    if ($shodan_key) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://api.shodan.io/shodan/host/' . urlencode($ip) . '?key=' . urlencode($shodan_key),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $handles['shodan'] = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.5);
    } while ($running > 0);

    $raw = [];
    foreach ($handles as $key => $ch) {
        $raw[$key] = [
            'code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body' => json_decode(curl_multi_getcontent($ch), true),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    // ── Parse AbuseIPDB ───────────────────────────────────────────────────────
    $abuse = null;
    if (!empty($raw['abuseipdb']['body']['data'])) {
        $d = $raw['abuseipdb']['body']['data'];
        $abuse = [
            'confidence'    => (int)($d['abuseConfidenceScore'] ?? 0),
            'total_reports' => (int)($d['totalReports'] ?? 0),
            'country'       => $d['countryCode'] ?? '',
            'isp'           => $d['isp'] ?? '',
            'usage_type'    => $d['usageType'] ?? '',
            'domain'        => $d['domain'] ?? '',
            'last_reported' => $d['lastReportedAt'] ?? null,
            'whitelisted'   => (bool)($d['isWhitelisted'] ?? false),
            'num_distinct'  => (int)($d['numDistinctUsers'] ?? 0),
        ];
    }

    // ── Parse VirusTotal ──────────────────────────────────────────────────────
    $vt = null;
    if (!empty($raw['virustotal']['body']['data']['attributes'])) {
        $attr  = $raw['virustotal']['body']['data']['attributes'];
        $stats = $attr['last_analysis_stats'] ?? [];
        $vt = [
            'malicious'  => (int)($stats['malicious']  ?? 0),
            'suspicious' => (int)($stats['suspicious'] ?? 0),
            'harmless'   => (int)($stats['harmless']   ?? 0),
            'undetected' => (int)($stats['undetected'] ?? 0),
            'country'    => $attr['country']     ?? '',
            'as_owner'   => $attr['as_owner']    ?? '',
            'asn'        => (int)($attr['asn']   ?? 0),
            'last_date'  => $attr['last_analysis_date'] ?? null,
            'reputation' => (int)($attr['reputation']   ?? 0),
        ];
    }

    // ── Parse Shodan ──────────────────────────────────────────────────────────
    $shodan = null;
    if (
        isset($raw['shodan']['code']) &&
        $raw['shodan']['code'] === 200 &&
        !empty($raw['shodan']['body']) &&
        empty($raw['shodan']['body']['error'])
    ) {
        $d     = $raw['shodan']['body'];
        $ports = array_unique($d['ports'] ?? []);
        sort($ports);
        $vulns    = [];
        $services = [];
        foreach ($d['data'] ?? [] as $svc) {
            $product = trim(($svc['product'] ?? '') . ' ' . ($svc['version'] ?? ''));
            $services[] = [
                'port'    => (int)($svc['port'] ?? 0),
                'proto'   => $svc['transport'] ?? 'tcp',
                'product' => $product ?: ($svc['_shodan']['module'] ?? ''),
            ];
            foreach (array_keys($svc['vulns'] ?? []) as $cve) {
                $vulns[] = $cve;
            }
        }
        $shodan = [
            'ports'     => $ports,
            'services'  => array_slice($services, 0, 15),
            'vulns'     => array_values(array_unique($vulns)),
            'org'       => $d['org']          ?? '',
            'isp'       => $d['isp']          ?? '',
            'os'        => $d['os']           ?? null,
            'country'   => $d['country_name'] ?? '',
            'city'      => $d['city']         ?? '',
            'updated'   => $d['last_update']  ?? null,
            'hostnames' => array_slice($d['hostnames'] ?? [], 0, 5),
        ];
    }

    // ── Risk score (0–100) ────────────────────────────────────────────────────
    $risk_parts = [];
    if ($abuse !== null) {
        $risk_parts[] = $abuse['confidence'];
    }
    if ($vt !== null) {
        $total_v = $vt['malicious'] + $vt['suspicious'] + $vt['harmless'];
        if ($total_v > 0) {
            $risk_parts[] = (int)round((($vt['malicious'] * 100) + ($vt['suspicious'] * 50)) / $total_v);
        }
    }
    if ($shodan !== null && count($shodan['vulns']) > 0) {
        $risk_parts[] = min(count($shodan['vulns']) * 15, 75);
    }
    $risk_score = count($risk_parts) > 0
        ? (int)min(round(array_sum($risk_parts) / count($risk_parts)), 100)
        : 0;

    $country_code = $abuse['country'] ?? $vt['country'] ?? '';
    $flag         = ip_flag($country_code);
    $isp          = $abuse['isp'] ?? $shodan['isp'] ?? $vt['as_owner'] ?? '';
    $org          = $shodan['org'] ?? $vt['as_owner'] ?? $isp;

    $result = [
        'ip'           => $ip,
        'country_code' => $country_code,
        'flag'         => $flag,
        'isp'          => $isp,
        'org'          => $org,
        'risk_score'   => $risk_score,
        'abuse'        => $abuse,
        'vt'           => $vt,
        'shodan'       => $shodan,
    ];

    try {
        db()->prepare('INSERT INTO ip_checks (user_id, ip_address, result, risk_score) VALUES (?, ?, ?, ?)')
            ->execute([(int)$user['id'], $ip, json_encode($result), $risk_score]);
    } catch (Exception $e) {}

    echo json_encode($result);
    exit;
}

// ── History ───────────────────────────────────────────────────────────────────
try {
    $stmt = db()->prepare(
        'SELECT ip_address, risk_score, result, checked_at
         FROM ip_checks WHERE user_id = ?
         ORDER BY checked_at DESC LIMIT 10'
    );
    $stmt->execute([(int)$user['id']]);
    $history = $stmt->fetchAll();
} catch (Exception $e) {
    $history = [];
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>IP Reputation Checker — HakDel</title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/layout.css">
</head>
<body>

<?php require_once __DIR__ . '/../partials/topbar.php'; ?>

<div class="hk-shell">
  <?php require_once __DIR__ . '/../partials/sidebar.php'; ?>

  <main class="hk-main" style="padding:28px;max-width:1100px;width:100%;">

    <!-- Page header -->
    <div style="margin-bottom:24px;">
      <h1 style="font-family:var(--mono);font-size:20px;font-weight:700;color:var(--text);margin:0 0 4px;">
        &#127760; IP Reputation Checker
      </h1>
      <p style="font-size:13px;color:var(--text3);margin:0;">
        Query AbuseIPDB, VirusTotal, and Shodan simultaneously to assess any IP address.
      </p>
    </div>

    <!-- Input form -->
    <div class="tool-card" style="margin-bottom:24px;">
      <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <div style="flex:1;min-width:240px;">
          <label style="font-family:var(--mono);font-size:11px;color:var(--text3);letter-spacing:1px;text-transform:uppercase;display:block;margin-bottom:6px;">
            IP Address
          </label>
          <input type="text" id="ip-input" class="form-input"
                 placeholder="e.g. 8.8.8.8 or 185.220.101.42"
                 style="font-family:var(--mono);font-size:15px;letter-spacing:1px;"
                 maxlength="45" autocomplete="off">
        </div>
        <button id="check-btn" class="btn-tool-primary" onclick="checkIP()">
          &#128269; Check IP
        </button>
      </div>
      <div id="ip-error" class="tool-error" style="display:none;margin-top:12px;"></div>
    </div>

    <!-- Loading state -->
    <div id="ip-loading" style="display:none;text-align:center;padding:48px 0;">
      <div class="tool-loading-dots">
        <span></span><span></span><span></span>
      </div>
      <p style="font-family:var(--mono);font-size:13px;color:var(--text3);margin-top:16px;">
        Querying AbuseIPDB &middot; VirusTotal &middot; Shodan&hellip;
      </p>
    </div>

    <!-- Results -->
    <div id="ip-results" style="display:none;">

      <!-- IP Info header -->
      <div class="tools-grid tools-grid-4" style="margin-bottom:16px;">
        <div class="tool-card tool-stat-card">
          <div class="tool-stat-label">IP Address</div>
          <div class="tool-stat-val" id="res-ip" style="font-size:20px;letter-spacing:2px;">—</div>
        </div>
        <div class="tool-card tool-stat-card">
          <div class="tool-stat-label">Country</div>
          <div class="tool-stat-val" id="res-country" style="font-size:22px;">—</div>
        </div>
        <div class="tool-card tool-stat-card">
          <div class="tool-stat-label">ISP / Network</div>
          <div class="tool-stat-val" id="res-isp" style="font-size:14px;line-height:1.3;">—</div>
        </div>
        <div class="tool-card tool-stat-card">
          <div class="tool-stat-label">Organisation</div>
          <div class="tool-stat-val" id="res-org" style="font-size:14px;line-height:1.3;">—</div>
        </div>
      </div>

      <!-- Risk score + data cards -->
      <div class="tools-grid tools-grid-2" style="margin-bottom:16px;">

        <!-- Risk score gauge -->
        <div class="tool-card" style="display:flex;flex-direction:column;align-items:center;padding:28px;">
          <div style="font-family:var(--mono);font-size:11px;color:var(--text3);letter-spacing:2px;text-transform:uppercase;margin-bottom:20px;">
            Combined Risk Score
          </div>
          <div class="risk-gauge-wrap">
            <svg class="risk-gauge-svg" viewBox="0 0 120 70" xmlns="http://www.w3.org/2000/svg">
              <path d="M 10 65 A 50 50 0 0 1 110 65" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="10" stroke-linecap="round"/>
              <path id="gauge-arc" d="M 10 65 A 50 50 0 0 1 110 65" fill="none" stroke="#00d4aa" stroke-width="10" stroke-linecap="round"
                    stroke-dasharray="157" stroke-dashoffset="157"/>
            </svg>
            <div class="risk-gauge-center">
              <div id="gauge-score" style="font-family:var(--mono);font-size:32px;font-weight:700;color:var(--text);line-height:1;">0</div>
              <div style="font-size:12px;color:var(--text3);">/ 100</div>
            </div>
          </div>
          <div id="risk-label" class="risk-badge risk-clean" style="margin-top:16px;font-size:14px;padding:8px 24px;">
            Clean
          </div>
        </div>

        <!-- AbuseIPDB card -->
        <div class="tool-card" id="card-abuse">
          <div class="tool-card-header">
            <span class="tool-card-icon">&#9888;</span>
            AbuseIPDB
          </div>
          <div id="abuse-content" class="tool-card-body">
            <div class="tool-no-data">No AbuseIPDB data</div>
          </div>
        </div>
      </div>

      <div class="tools-grid tools-grid-2" style="margin-bottom:24px;">

        <!-- VirusTotal card -->
        <div class="tool-card" id="card-vt">
          <div class="tool-card-header">
            <span class="tool-card-icon" style="color:#4f90f0;">&#128737;</span>
            VirusTotal
          </div>
          <div id="vt-content" class="tool-card-body">
            <div class="tool-no-data">No VirusTotal data</div>
          </div>
        </div>

        <!-- Shodan card -->
        <div class="tool-card" id="card-shodan">
          <div class="tool-card-header">
            <span class="tool-card-icon" style="color:#ff6b35;">&#128268;</span>
            Shodan
          </div>
          <div id="shodan-content" class="tool-card-body">
            <div class="tool-no-data">No Shodan data — add SHODAN_API_KEY to .env</div>
          </div>
        </div>
      </div>
    </div>

    <!-- History -->
    <?php if (!empty($history)): ?>
    <div class="tool-card">
      <div class="tool-card-header" style="margin-bottom:14px;">
        <span class="tool-card-icon">&#128337;</span>
        Recent IP Checks
      </div>
      <table class="ip-history-table">
        <thead>
          <tr>
            <th>IP Address</th>
            <th>Risk</th>
            <th>Country</th>
            <th>Top Finding</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $row):
            $r    = json_decode($row['result'], true) ?? [];
            $score = (int)$row['risk_score'];
            $cc   = $r['country_code'] ?? '';
            $flag = ip_flag($cc);

            // Top finding
            $finding = 'Clean';
            $finding_class = 'risk-clean';
            if (!empty($r['abuse']['total_reports']) && $r['abuse']['total_reports'] > 0) {
                $finding = $r['abuse']['total_reports'] . ' abuse report' . ($r['abuse']['total_reports'] > 1 ? 's' : '');
                $finding_class = $score > 50 ? 'risk-malicious' : 'risk-suspicious';
            } elseif (!empty($r['vt']['malicious']) && $r['vt']['malicious'] > 0) {
                $finding = $r['vt']['malicious'] . ' VT detection' . ($r['vt']['malicious'] > 1 ? 's' : '');
                $finding_class = 'risk-malicious';
            } elseif (!empty($r['shodan']['vulns']) && count($r['shodan']['vulns']) > 0) {
                $finding = count($r['shodan']['vulns']) . ' CVE' . (count($r['shodan']['vulns']) > 1 ? 's' : '');
                $finding_class = 'risk-suspicious';
            }

            if ($score <= 25)      $badge_class = 'risk-clean';
            elseif ($score <= 50)  $badge_class = 'risk-suspicious';
            elseif ($score <= 75)  $badge_class = 'risk-malicious';
            else                   $badge_class = 'risk-dangerous';
          ?>
          <tr>
            <td style="font-family:var(--mono);font-size:13px;letter-spacing:1px;">
              <a href="?ip=<?= h(urlencode($row['ip_address'])) ?>" onclick="event.preventDefault();document.getElementById('ip-input').value='<?= h($row['ip_address']) ?>';checkIP();"
                 style="color:var(--accent);text-decoration:none;"><?= h($row['ip_address']) ?></a>
            </td>
            <td><span class="risk-badge <?= $badge_class ?>"><?= $score ?>/100</span></td>
            <td style="font-size:16px;"><?= $flag ? h($flag) . ' ' . h($cc) : h($cc ?: '—') ?></td>
            <td><span class="risk-badge <?= $finding_class ?>" style="font-size:11px;"><?= h($finding) ?></span></td>
            <td style="font-family:var(--mono);font-size:12px;color:var(--text3);">
              <?= h(date('M j, H:i', strtotime($row['checked_at']))) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  </main>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;

async function checkIP() {
    const ip = document.getElementById('ip-input').value.trim();
    const errEl = document.getElementById('ip-error');
    errEl.style.display = 'none';

    if (!ip) {
        showError('Please enter an IP address.');
        return;
    }

    document.getElementById('check-btn').disabled = true;
    document.getElementById('check-btn').textContent = 'Checking…';
    document.getElementById('ip-loading').style.display = 'block';
    document.getElementById('ip-results').style.display = 'none';

    try {
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('ip', ip);

        const res  = await fetch('/tools/ip_check.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.error) {
            showError(data.error);
            return;
        }

        renderResults(data);
        document.getElementById('ip-results').style.display = 'block';
    } catch (e) {
        showError('Network error. Please try again.');
    } finally {
        document.getElementById('ip-loading').style.display = 'none';
        document.getElementById('check-btn').disabled = false;
        document.getElementById('check-btn').innerHTML = '&#128269; Check IP';
    }
}

function showError(msg) {
    const el = document.getElementById('ip-error');
    el.textContent = msg;
    el.style.display = 'block';
    document.getElementById('ip-loading').style.display = 'none';
    document.getElementById('check-btn').disabled = false;
    document.getElementById('check-btn').innerHTML = '&#128269; Check IP';
}

function riskClass(score) {
    if (score <= 25)  return 'risk-clean';
    if (score <= 50)  return 'risk-suspicious';
    if (score <= 75)  return 'risk-malicious';
    return 'risk-dangerous';
}
function riskLabel(score) {
    if (score <= 25)  return 'Clean';
    if (score <= 50)  return 'Suspicious';
    if (score <= 75)  return 'Malicious';
    return 'Dangerous';
}
function riskColor(score) {
    if (score <= 25)  return '#00d4aa';
    if (score <= 50)  return '#ffd166';
    if (score <= 75)  return '#ff9800';
    return '#ff4d6d';
}

function renderResults(d) {
    // Header stats
    document.getElementById('res-ip').textContent      = d.ip;
    document.getElementById('res-country').textContent = d.flag ? d.flag + ' ' + d.country_code : (d.country_code || '—');
    document.getElementById('res-isp').textContent     = d.isp  || '—';
    document.getElementById('res-org').textContent     = d.org  || '—';

    // Gauge
    const score = d.risk_score;
    const arc   = document.getElementById('gauge-arc');
    const pct   = score / 100;
    // Arc length ≈ 157 (half circle). Dashoffset 157 = empty, 0 = full.
    arc.style.strokeDashoffset = 157 - (157 * pct);
    arc.style.stroke = riskColor(score);
    document.getElementById('gauge-score').textContent = score;
    document.getElementById('gauge-score').style.color  = riskColor(score);

    const rLabel = document.getElementById('risk-label');
    rLabel.textContent = riskLabel(score);
    rLabel.className   = 'risk-badge ' + riskClass(score);

    // AbuseIPDB
    const abuseEl = document.getElementById('abuse-content');
    if (d.abuse) {
        const a = d.abuse;
        const confColor = a.confidence > 50 ? '#ff4d6d' : (a.confidence > 20 ? '#ff9800' : '#00d4aa');
        abuseEl.innerHTML = `
            <div class="tool-metric-row">
                <span class="tool-metric-label">Abuse Confidence</span>
                <span class="tool-metric-val" style="color:${confColor};font-size:24px;font-weight:700;">${a.confidence}%</span>
            </div>
            <div class="tool-meter-wrap">
                <div class="tool-meter-fill" style="width:${a.confidence}%;background:${confColor};"></div>
            </div>
            <div class="tool-data-grid" style="margin-top:14px;">
                <div class="tool-data-item">
                    <div class="tool-data-label">Total Reports</div>
                    <div class="tool-data-val">${a.total_reports.toLocaleString()}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">Distinct Users</div>
                    <div class="tool-data-val">${a.num_distinct || 0}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">Usage Type</div>
                    <div class="tool-data-val">${a.usage_type || '—'}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">Domain</div>
                    <div class="tool-data-val">${a.domain || '—'}</div>
                </div>
            </div>
            <div style="margin-top:12px;font-size:12px;color:var(--text3);font-family:var(--mono);">
                Last reported: ${a.last_reported ? new Date(a.last_reported).toLocaleDateString('en-GB', {day:'numeric',month:'short',year:'numeric'}) : 'Never reported'}
                ${a.whitelisted ? '<span style="color:#00d4aa;margin-left:8px;">&#10003; Whitelisted</span>' : ''}
            </div>`;
    } else {
        abuseEl.innerHTML = '<div class="tool-no-data">AbuseIPDB returned no data for this IP.</div>';
    }

    // VirusTotal
    const vtEl = document.getElementById('vt-content');
    if (d.vt) {
        const v = d.vt;
        const total = v.malicious + v.suspicious + v.harmless + v.undetected;
        const detectedPct = total > 0 ? Math.round(((v.malicious + v.suspicious) / total) * 100) : 0;
        vtEl.innerHTML = `
            <div style="display:flex;gap:12px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
                <div style="text-align:center;">
                    <div style="font-family:var(--mono);font-size:28px;font-weight:700;color:${v.malicious > 0 ? '#ff4d6d' : '#00d4aa'};">${v.malicious}</div>
                    <div style="font-size:11px;color:var(--text3);">Malicious</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-family:var(--mono);font-size:28px;font-weight:700;color:${v.suspicious > 0 ? '#ff9800' : '#888'};">${v.suspicious}</div>
                    <div style="font-size:11px;color:var(--text3);">Suspicious</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-family:var(--mono);font-size:28px;font-weight:700;color:#00d4aa;">${v.harmless}</div>
                    <div style="font-size:11px;color:var(--text3);">Harmless</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-family:var(--mono);font-size:28px;font-weight:700;color:#555;">${v.undetected}</div>
                    <div style="font-size:11px;color:var(--text3);">Undetected</div>
                </div>
            </div>
            <div style="display:flex;height:8px;border-radius:4px;overflow:hidden;background:rgba(255,255,255,0.05);margin-bottom:14px;">
                <div style="width:${total>0?Math.round(v.malicious/total*100):0}%;background:#ff4d6d;transition:width 0.5s;"></div>
                <div style="width:${total>0?Math.round(v.suspicious/total*100):0}%;background:#ff9800;transition:width 0.5s;"></div>
                <div style="width:${total>0?Math.round(v.harmless/total*100):0}%;background:#00d4aa;transition:width 0.5s;"></div>
            </div>
            <div class="tool-data-grid">
                <div class="tool-data-item">
                    <div class="tool-data-label">AS Owner</div>
                    <div class="tool-data-val">${v.as_owner || '—'}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">ASN</div>
                    <div class="tool-data-val">${v.asn ? 'AS' + v.asn : '—'}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">Reputation</div>
                    <div class="tool-data-val" style="color:${v.reputation < 0 ? '#ff4d6d' : '#00d4aa'};">${v.reputation}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">Last Analysis</div>
                    <div class="tool-data-val">${v.last_date ? new Date(v.last_date * 1000).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : '—'}</div>
                </div>
            </div>`;
    } else {
        vtEl.innerHTML = '<div class="tool-no-data">VirusTotal returned no data for this IP.</div>';
    }

    // Shodan
    const shodanEl = document.getElementById('shodan-content');
    if (d.shodan) {
        const s = d.shodan;
        const portsHtml = s.ports.length
            ? s.ports.map(p => `<span class="port-badge">${p}</span>`).join('')
            : '<span style="color:var(--text3);font-size:13px;">No open ports found</span>';

        const vulnsHtml = s.vulns.length
            ? s.vulns.map(v => `<span class="cve-badge">${v}</span>`).join('')
            : '<span style="color:#00d4aa;font-size:13px;">&#10003; No known CVEs</span>';

        const svcHtml = s.services.filter(sv => sv.product).slice(0, 6)
            .map(sv => `<div style="font-family:var(--mono);font-size:12px;color:var(--text2);padding:4px 0;border-bottom:1px solid rgba(255,255,255,0.04);">
                <span style="color:var(--accent);">${sv.port}/${sv.proto}</span>
                <span style="margin-left:8px;color:var(--text3);">${sv.product.substring(0,50)}</span>
            </div>`).join('');

        shodanEl.innerHTML = `
            <div style="margin-bottom:14px;">
                <div style="font-family:var(--mono);font-size:10px;color:var(--text3);letter-spacing:1px;text-transform:uppercase;margin-bottom:8px;">Open Ports</div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">${portsHtml}</div>
            </div>
            ${svcHtml ? `<div style="margin-bottom:14px;">
                <div style="font-family:var(--mono);font-size:10px;color:var(--text3);letter-spacing:1px;text-transform:uppercase;margin-bottom:8px;">Services</div>
                ${svcHtml}
            </div>` : ''}
            <div style="margin-bottom:14px;">
                <div style="font-family:var(--mono);font-size:10px;color:var(--text3);letter-spacing:1px;text-transform:uppercase;margin-bottom:8px;">Known Vulnerabilities (CVE)</div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">${vulnsHtml}</div>
            </div>
            <div class="tool-data-grid">
                <div class="tool-data-item">
                    <div class="tool-data-label">Organisation</div>
                    <div class="tool-data-val">${s.org || '—'}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">OS</div>
                    <div class="tool-data-val">${s.os || 'Unknown'}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">Location</div>
                    <div class="tool-data-val">${[s.city, s.country].filter(Boolean).join(', ') || '—'}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">Last Updated</div>
                    <div class="tool-data-val">${s.updated ? new Date(s.updated).toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}) : '—'}</div>
                </div>
            </div>
            ${s.hostnames.length ? `<div style="margin-top:10px;font-size:12px;color:var(--text3);font-family:var(--mono);">Hostnames: ${s.hostnames.join(', ')}</div>` : ''}`;
    } else {
        shodanEl.innerHTML = '<div class="tool-no-data">' +
            (<?= json_encode(getenv('SHODAN_API_KEY') ? '' : 'no-key') ?> === 'no-key'
                ? 'Add SHODAN_API_KEY to .env to enable Shodan lookups.'
                : 'Host not found in Shodan database.') +
            '</div>';
    }
}

// Allow pressing Enter to check
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('ip-input').addEventListener('keydown', e => {
        if (e.key === 'Enter') checkIP();
    });
});
</script>

</body>
</html>
