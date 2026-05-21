<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'tools-cve';
$sidebar_sub  = 'Security Tools';
$topbar_title = 'CVE Lookup';
$gate_feature = 'CVE Lookup'; $gate_hard = true; require __DIR__ . '/../partials/pro_gate.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function is_cve_id(string $q): bool {
    return (bool)preg_match('/^CVE-\d{4}-\d{4,}$/i', trim($q));
}

function cve_severity_from_score(float $score): string {
    if ($score >= 9.0) return 'Critical';
    if ($score >= 7.0) return 'High';
    if ($score >= 4.0) return 'Medium';
    if ($score >  0.0) return 'Low';
    return 'None';
}

function parse_nvd_cve(array $cve): array {
    $desc = '';
    foreach ($cve['descriptions'] ?? [] as $d) {
        if ($d['lang'] === 'en') { $desc = $d['value']; break; }
    }

    // CVSS — prefer v3.1, fall back to v3.0, then v2
    $cvss_score  = null;
    $cvss_vector = '';
    $severity    = '';
    foreach (['cvssMetricV31', 'cvssMetricV30', 'cvssMetricV2'] as $key) {
        if (!empty($cve['metrics'][$key][0])) {
            $m = $cve['metrics'][$key][0];
            $cvss_score  = (float)($m['cvssData']['baseScore']    ?? 0);
            $cvss_vector = $m['cvssData']['vectorString']          ?? '';
            $severity    = $m['cvssData']['baseSeverity']          ?? $m['baseSeverity'] ?? '';
            break;
        }
    }
    if ($cvss_score !== null && !$severity) {
        $severity = cve_severity_from_score($cvss_score);
    }

    // CPE / affected products
    $products = [];
    foreach ($cve['configurations'] ?? [] as $cfg) {
        foreach ($cfg['nodes'] ?? [] as $node) {
            foreach ($node['cpeMatch'] ?? [] as $m) {
                if (!empty($m['vulnerable']) && !empty($m['criteria'])) {
                    // cpe:2.3:a:vendor:product:version:...
                    $parts = explode(':', $m['criteria']);
                    $vendor  = $parts[3] ?? '';
                    $product = $parts[4] ?? '';
                    $version = $parts[5] ?? '*';
                    if ($vendor && $product) {
                        $key = "$vendor:$product";
                        if (!isset($products[$key])) {
                            $products[$key] = ['vendor' => $vendor, 'product' => $product, 'versions' => []];
                        }
                        if ($version !== '*') {
                            $products[$key]['versions'][] = $version;
                        }
                    }
                }
            }
        }
    }
    $products = array_values($products);
    foreach ($products as &$p) {
        $p['versions'] = array_unique($p['versions']);
    }
    unset($p);

    // References
    $refs = [];
    foreach ($cve['references'] ?? [] as $r) {
        $refs[] = ['url' => $r['url'] ?? '', 'tags' => $r['tags'] ?? []];
    }

    return [
        'id'          => $cve['id'] ?? '',
        'published'   => $cve['published'] ?? '',
        'modified'    => $cve['lastModified'] ?? '',
        'status'      => $cve['vulnStatus'] ?? '',
        'description' => $desc,
        'cvss_score'  => $cvss_score,
        'cvss_vector' => $cvss_vector,
        'severity'    => $severity,
        'products'    => array_slice($products, 0, 20),
        'refs'        => array_slice($refs, 0, 15),
    ];
}

// ── AJAX handler (POST) ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!verify_csrf($_POST['csrf'] ?? '')) {
        echo json_encode(['error' => 'Invalid request']); exit;
    }

    $query = trim($_POST['query'] ?? '');
    if (!$query || strlen($query) < 3) {
        echo json_encode(['error' => 'Please enter a CVE ID or keyword (min 3 characters).']); exit;
    }
    if (strlen($query) > 100) {
        echo json_encode(['error' => 'Query too long (max 100 characters).']); exit;
    }

    $nvd_key   = getenv('NVD_API_KEY') ?: '';
    $is_cve    = is_cve_id($query);
    $cve_id_uc = $is_cve ? strtoupper(trim($query)) : null;

    // ── Parallel: NVD + ExploitDB (if CVE ID) ────────────────────────────────
    $mh      = curl_multi_init();
    $handles = [];

    // NVD
    $nvd_params = $is_cve
        ? ['cveId' => $cve_id_uc]
        : ['keywordSearch' => $query, 'resultsPerPage' => 10];

    $nvd_headers = $nvd_key ? ['apiKey: ' . $nvd_key] : [];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://services.nvd.nist.gov/rest/json/cves/2.0?' . http_build_query($nvd_params),
        CURLOPT_HTTPHEADER     => $nvd_headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'HakDel/1.0',
    ]);
    $handles['nvd'] = $ch;
    curl_multi_add_handle($mh, $ch);

    // ExploitDB (only for CVE ID lookups)
    if ($is_cve) {
        $cve_num = preg_replace('/^CVE-/i', '', $cve_id_uc); // e.g. 2021-44228
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://www.exploit-db.com/search?cve=' . urlencode($cve_num),
            CURLOPT_HTTPHEADER     => [
                'X-Requested-With: XMLHttpRequest',
                'Accept: application/json, text/javascript',
                'User-Agent: Mozilla/5.0 (compatible; HakDel/1.0)',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $handles['exploitdb'] = $ch;
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

    // ── Parse NVD ─────────────────────────────────────────────────────────────
    $nvd_vulns = [];
    $nvd_total = 0;
    if (!empty($raw['nvd']['body']['vulnerabilities'])) {
        $nvd_total = (int)($raw['nvd']['body']['totalResults'] ?? 0);
        foreach ($raw['nvd']['body']['vulnerabilities'] as $v) {
            $nvd_vulns[] = parse_nvd_cve($v['cve'] ?? []);
        }
    }

    // ── Parse ExploitDB ───────────────────────────────────────────────────────
    $exploits = [];
    $has_public_exploit = false;
    if (!empty($raw['exploitdb']['body']['data'])) {
        foreach ($raw['exploitdb']['body']['data'] as $exp) {
            $exploits[] = [
                'id'          => $exp['id']          ?? '',
                'title'       => $exp['description']  ?? ($exp['title'] ?? ''),
                'type'        => $exp['type']['val']  ?? '',
                'platform'    => $exp['platform']['val'] ?? '',
                'author'      => $exp['author']['name'] ?? '',
                'date'        => $exp['date_published'] ?? '',
                'url'         => 'https://www.exploit-db.com/exploits/' . ($exp['id'] ?? ''),
            ];
        }
        $has_public_exploit = count($exploits) > 0;
    }

    // ── Single CVE vs list ────────────────────────────────────────────────────
    $result_type = $is_cve ? 'single' : 'list';
    $single      = $is_cve && !empty($nvd_vulns) ? $nvd_vulns[0] : null;

    // Save to DB
    $save_cve_id    = $single['id'] ?? ($is_cve ? $cve_id_uc : null);
    $save_cvss      = $single['cvss_score'] ?? null;
    $save_severity  = $single['severity']   ?? null;
    $save_result    = [
        'type'              => $result_type,
        'query'             => $query,
        'single'            => $single,
        'list'              => $is_cve ? null : $nvd_vulns,
        'total'             => $nvd_total,
        'exploits'          => $exploits,
        'has_public_exploit'=> $has_public_exploit,
    ];

    try {
        db()->prepare(
            'INSERT INTO cve_lookups (user_id, query, cve_id, result, cvss_score, severity) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            (int)$user['id'], $query, $save_cve_id,
            json_encode($save_result), $save_cvss, $save_severity,
        ]);
    } catch (Exception $e) {}

    echo json_encode($save_result);
    exit;
}

// ── History ───────────────────────────────────────────────────────────────────
try {
    $stmt = db()->prepare(
        'SELECT query, cve_id, cvss_score, severity, looked_up_at
         FROM cve_lookups WHERE user_id = ?
         ORDER BY looked_up_at DESC LIMIT 10'
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
<title>CVE Lookup — HakDel</title>
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
        &#9888; CVE Lookup
      </h1>
      <p style="font-size:13px;color:var(--text3);margin:0;">
        Search the NVD for CVE details and check ExploitDB for public exploits.
      </p>
    </div>

    <!-- Input form -->
    <div class="tool-card" style="margin-bottom:24px;">
      <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <div style="flex:1;min-width:260px;">
          <label style="font-family:var(--mono);font-size:11px;color:var(--text3);letter-spacing:1px;text-transform:uppercase;display:block;margin-bottom:6px;">
            CVE ID or Keyword
          </label>
          <input type="text" id="cve-input" class="form-input"
                 placeholder="CVE-2021-44228 or &quot;Apache Log4j&quot;"
                 style="font-family:var(--mono);font-size:14px;"
                 maxlength="100" autocomplete="off">
        </div>
        <button id="lookup-btn" class="btn-tool-primary" onclick="lookupCVE()">
          &#128269; Look Up
        </button>
      </div>
      <div style="margin-top:10px;display:flex;gap:16px;flex-wrap:wrap;">
        <span style="font-family:var(--mono);font-size:11px;color:var(--text3);">
          Quick: &nbsp;
          <a href="#" onclick="setAndSearch('CVE-2021-44228')" style="color:var(--accent);text-decoration:none;">Log4Shell</a>
          &nbsp;&middot;&nbsp;
          <a href="#" onclick="setAndSearch('CVE-2014-0160')" style="color:var(--accent);text-decoration:none;">Heartbleed</a>
          &nbsp;&middot;&nbsp;
          <a href="#" onclick="setAndSearch('CVE-2017-0144')" style="color:var(--accent);text-decoration:none;">EternalBlue</a>
          &nbsp;&middot;&nbsp;
          <a href="#" onclick="setAndSearch('CVE-2021-34527')" style="color:var(--accent);text-decoration:none;">PrintNightmare</a>
        </span>
      </div>
      <div id="cve-error" class="tool-error" style="display:none;margin-top:12px;"></div>
    </div>

    <!-- Loading -->
    <div id="cve-loading" style="display:none;text-align:center;padding:48px 0;">
      <div class="tool-loading-dots"><span></span><span></span><span></span></div>
      <p style="font-family:var(--mono);font-size:13px;color:var(--text3);margin-top:16px;">
        Querying NVD &middot; ExploitDB&hellip;
      </p>
    </div>

    <!-- Results (single CVE) -->
    <div id="cve-single" style="display:none;">

      <!-- Header row: CVE ID / published / severity gauge / exploit badge -->
      <div class="tools-grid tools-grid-4" style="margin-bottom:16px;">
        <div class="tool-card tool-stat-card">
          <div class="tool-stat-label">CVE ID</div>
          <div id="s-id" class="tool-stat-val" style="font-size:16px;letter-spacing:1px;color:var(--accent);">—</div>
        </div>
        <div class="tool-card tool-stat-card">
          <div class="tool-stat-label">Published</div>
          <div id="s-pub" class="tool-stat-val" style="font-size:14px;">—</div>
        </div>
        <div class="tool-card tool-stat-card">
          <div class="tool-stat-label">CVSS Score</div>
          <div id="s-score-wrap" style="margin-top:4px;">—</div>
        </div>
        <div class="tool-card tool-stat-card">
          <div class="tool-stat-label">Public Exploit</div>
          <div id="s-exploit-badge" style="margin-top:6px;">—</div>
        </div>
      </div>

      <!-- CVSS gauge + Description -->
      <div class="tools-grid tools-grid-2" style="margin-bottom:16px;">

        <!-- Severity card -->
        <div class="tool-card">
          <div class="tool-card-header">
            <span class="tool-card-icon">&#128246;</span>
            Severity
          </div>
          <div style="display:flex;flex-direction:column;align-items:center;padding:8px 0 16px;">
            <div class="cvss-gauge-wrap">
              <svg viewBox="0 0 120 70" xmlns="http://www.w3.org/2000/svg" style="width:180px;height:110px;">
                <path d="M 10 65 A 50 50 0 0 1 110 65" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="10" stroke-linecap="round"/>
                <path id="cvss-arc" d="M 10 65 A 50 50 0 0 1 110 65" fill="none" stroke="#00d4aa" stroke-width="10" stroke-linecap="round"
                      stroke-dasharray="157" stroke-dashoffset="157"/>
              </svg>
              <div style="text-align:center;margin-top:-16px;">
                <div id="cvss-num" style="font-family:var(--mono);font-size:36px;font-weight:700;color:var(--text);line-height:1;">—</div>
                <div id="cvss-sev" style="font-size:13px;font-weight:600;margin-top:4px;letter-spacing:1px;">—</div>
              </div>
            </div>
            <div id="cvss-vector" style="font-family:var(--mono);font-size:11px;color:var(--text3);margin-top:16px;text-align:center;word-break:break-all;padding:0 12px;"></div>
          </div>
        </div>

        <!-- Description card -->
        <div class="tool-card">
          <div class="tool-card-header">
            <span class="tool-card-icon">&#128196;</span>
            Description
          </div>
          <div id="s-desc" style="font-size:13px;color:var(--text2);line-height:1.7;">—</div>
        </div>
      </div>

      <!-- Exploits -->
      <div id="exploits-section" style="display:none;margin-bottom:16px;">
        <div class="tool-card" style="border-color:rgba(255,77,109,0.4);">
          <div class="tool-card-header" style="color:#ff4d6d;">
            <span class="tool-card-icon">&#128163;</span>
            Public Exploits Found — Patch Immediately
          </div>
          <div id="exploits-list"></div>
        </div>
      </div>

      <!-- Affected products + References -->
      <div class="tools-grid tools-grid-2" style="margin-bottom:24px;">

        <div class="tool-card">
          <div class="tool-card-header">
            <span class="tool-card-icon">&#128187;</span>
            Affected Products
          </div>
          <div id="s-products">
            <div class="tool-no-data">No CPE data available.</div>
          </div>
        </div>

        <div class="tool-card">
          <div class="tool-card-header">
            <span class="tool-card-icon">&#128279;</span>
            References
          </div>
          <div id="s-refs">
            <div class="tool-no-data">No references.</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Results (keyword list) -->
    <div id="cve-list" style="display:none;margin-bottom:24px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <div style="font-family:var(--mono);font-size:13px;color:var(--text2);">
          Showing <span id="list-count" style="color:var(--accent);">0</span> results
          <span id="list-total-note" style="color:var(--text3);"></span>
        </div>
      </div>
      <div id="cve-cards"></div>
    </div>

    <!-- No results -->
    <div id="cve-noresults" style="display:none;text-align:center;padding:48px 0;">
      <div style="font-size:32px;margin-bottom:12px;">&#128269;</div>
      <div style="font-family:var(--mono);font-size:15px;color:var(--text2);margin-bottom:6px;">No CVEs found</div>
      <div style="font-size:13px;color:var(--text3);">Try a different CVE ID or keyword.</div>
    </div>

    <!-- History -->
    <?php if (!empty($history)): ?>
    <div class="tool-card">
      <div class="tool-card-header" style="margin-bottom:14px;">
        <span class="tool-card-icon">&#128337;</span>
        Recent CVE Lookups
      </div>
      <table class="ip-history-table">
        <thead>
          <tr>
            <th>Query</th>
            <th>CVE ID</th>
            <th>CVSS</th>
            <th>Severity</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $row):
            $score = $row['cvss_score'];
            $sev   = $row['severity'] ?? '';
            $sev_class = match(strtolower($sev)) {
                'critical' => 'risk-dangerous',
                'high'     => 'risk-malicious',
                'medium'   => 'risk-suspicious',
                'low'      => 'risk-clean',
                default    => '',
            };
          ?>
          <tr>
            <td>
              <a href="#" onclick="event.preventDefault();setAndSearch('<?= h(addslashes($row['query'])) ?>');"
                 style="color:var(--accent);text-decoration:none;font-family:var(--mono);font-size:13px;">
                <?= h($row['query']) ?>
              </a>
            </td>
            <td style="font-family:var(--mono);font-size:12px;color:var(--text3);">
              <?= h($row['cve_id'] ?? '—') ?>
            </td>
            <td style="font-family:var(--mono);font-size:13px;font-weight:700;
                color:<?= $score >= 9 ? '#ff4d6d' : ($score >= 7 ? '#ff9800' : ($score >= 4 ? '#ffd166' : '#4f90f0')) ?>;">
              <?= $score !== null ? number_format((float)$score, 1) : '—' ?>
            </td>
            <td>
              <?php if ($sev): ?>
              <span class="risk-badge <?= $sev_class ?>"><?= h($sev) ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td style="font-family:var(--mono);font-size:12px;color:var(--text3);">
              <?= h(date('M j, H:i', strtotime($row['looked_up_at']))) ?>
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

function setAndSearch(q) {
    document.getElementById('cve-input').value = q;
    lookupCVE();
    return false;
}

async function lookupCVE() {
    const query = document.getElementById('cve-input').value.trim();
    const errEl = document.getElementById('cve-error');
    errEl.style.display = 'none';

    if (!query || query.length < 3) {
        showCVEError('Please enter a CVE ID or keyword (min 3 characters).');
        return;
    }

    document.getElementById('lookup-btn').disabled = true;
    document.getElementById('lookup-btn').textContent = 'Looking up…';
    document.getElementById('cve-loading').style.display = 'block';
    document.getElementById('cve-single').style.display    = 'none';
    document.getElementById('cve-list').style.display      = 'none';
    document.getElementById('cve-noresults').style.display = 'none';

    try {
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('query', query);

        const res  = await fetch('/tools/cve_check.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.error) { showCVEError(data.error); return; }

        if (data.type === 'single') {
            if (data.single) {
                renderSingle(data.single, data.exploits || [], data.has_public_exploit);
                document.getElementById('cve-single').style.display = 'block';
            } else {
                document.getElementById('cve-noresults').style.display = 'block';
            }
        } else {
            if (data.list && data.list.length > 0) {
                renderList(data.list, data.total || 0);
                document.getElementById('cve-list').style.display = 'block';
            } else {
                document.getElementById('cve-noresults').style.display = 'block';
            }
        }
    } catch (e) {
        showCVEError('Network error. Please try again.');
    } finally {
        document.getElementById('cve-loading').style.display = 'none';
        document.getElementById('lookup-btn').disabled = false;
        document.getElementById('lookup-btn').innerHTML = '&#128269; Look Up';
    }
}

function showCVEError(msg) {
    const el = document.getElementById('cve-error');
    el.textContent = msg;
    el.style.display = 'block';
    document.getElementById('cve-loading').style.display = 'none';
    document.getElementById('lookup-btn').disabled = false;
    document.getElementById('lookup-btn').innerHTML = '&#128269; Look Up';
}

function cvssColor(score) {
    if (score === null || score === undefined) return '#555';
    if (score >= 9.0) return '#ff4d6d';
    if (score >= 7.0) return '#ff9800';
    if (score >= 4.0) return '#ffd166';
    if (score >  0.0) return '#4f90f0';
    return '#555';
}
function cvssSevClass(sev) {
    const map = { critical:'risk-dangerous', high:'risk-malicious', medium:'risk-suspicious', low:'risk-clean' };
    return map[(sev||'').toLowerCase()] || '';
}
function fmtDate(s) {
    if (!s) return '—';
    return new Date(s).toLocaleDateString('en-GB', {day:'numeric', month:'short', year:'numeric'});
}

function renderSingle(cve, exploits, hasExploit) {
    const color = cvssColor(cve.cvss_score);

    document.getElementById('s-id').textContent  = cve.id || '—';
    document.getElementById('s-pub').textContent = fmtDate(cve.published);
    document.getElementById('s-desc').textContent = cve.description || 'No description available.';

    // Score wrap
    const scoreNum = cve.cvss_score !== null ? cve.cvss_score.toFixed(1) : '—';
    document.getElementById('s-score-wrap').innerHTML =
        `<span style="font-family:var(--mono);font-size:28px;font-weight:700;color:${color};">${scoreNum}</span>` +
        `<span style="font-size:12px;color:var(--text3);"> / 10</span>` +
        (cve.severity ? `<div><span class="risk-badge ${cvssSevClass(cve.severity)}" style="margin-top:6px;">${cve.severity}</span></div>` : '');

    // Exploit badge
    document.getElementById('s-exploit-badge').innerHTML = hasExploit
        ? `<span class="risk-badge risk-dangerous" style="font-size:13px;padding:6px 14px;">&#128163; YES — Patch NOW</span>`
        : `<span class="risk-badge risk-clean" style="font-size:13px;padding:6px 14px;">&#10003; No known exploit</span>`;

    // CVSS gauge
    const arc   = document.getElementById('cvss-arc');
    const pct   = cve.cvss_score ? cve.cvss_score / 10 : 0;
    arc.style.strokeDashoffset = 157 - (157 * pct);
    arc.style.stroke = color;
    document.getElementById('cvss-num').textContent = scoreNum;
    document.getElementById('cvss-num').style.color = color;
    document.getElementById('cvss-sev').textContent = (cve.severity || '').toUpperCase();
    document.getElementById('cvss-sev').style.color  = color;
    document.getElementById('cvss-vector').textContent = cve.cvss_vector || '';

    // Exploits list
    const expSection = document.getElementById('exploits-section');
    if (hasExploit && exploits.length > 0) {
        expSection.style.display = 'block';
        document.getElementById('exploits-list').innerHTML = exploits.map(e => `
            <div style="display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,77,109,0.1);">
                <span style="font-family:var(--mono);font-size:12px;color:#ff4d6d;flex-shrink:0;">EDB-${e.id}</span>
                <div style="flex:1;">
                    <a href="${e.url}" target="_blank" rel="noopener"
                       style="font-size:13px;color:var(--text);text-decoration:none;display:block;margin-bottom:3px;">${e.title || 'Exploit'}</a>
                    <span style="font-size:11px;color:var(--text3);">${e.type || ''} ${e.platform ? '· ' + e.platform : ''} ${e.date ? '· ' + fmtDate(e.date) : ''}</span>
                </div>
            </div>`).join('');
    } else {
        expSection.style.display = 'none';
    }

    // Affected products
    const prodEl = document.getElementById('s-products');
    if (cve.products && cve.products.length > 0) {
        prodEl.innerHTML = `<table style="width:100%;border-collapse:collapse;">` +
            cve.products.slice(0, 15).map(p => {
                const vStr = p.versions && p.versions.length ? p.versions.slice(0,3).join(', ') : 'multiple versions';
                return `<tr>
                    <td style="padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.04);">
                        <span style="font-family:var(--mono);font-size:12px;color:var(--accent);">${p.vendor}</span>
                        <span style="font-size:13px;color:var(--text2);margin-left:6px;">${p.product}</span>
                    </td>
                    <td style="padding:6px 0 6px 12px;border-bottom:1px solid rgba(255,255,255,0.04);font-family:var(--mono);font-size:11px;color:var(--text3);">${vStr}</td>
                </tr>`;
            }).join('') + `</table>`;
        if (cve.products.length > 15) {
            prodEl.innerHTML += `<div style="font-size:12px;color:var(--text3);margin-top:8px;">+ ${cve.products.length - 15} more products</div>`;
        }
    } else {
        prodEl.innerHTML = '<div class="tool-no-data">No CPE data in NVD for this CVE.</div>';
    }

    // References
    const refEl = document.getElementById('s-refs');
    if (cve.refs && cve.refs.length > 0) {
        const tagColors = { Patch: '#00d4aa', Vendor: '#4f90f0', Exploit: '#ff4d6d', Mitigation: '#ffd166' };
        refEl.innerHTML = cve.refs.map(r => {
            const domain = (() => { try { return new URL(r.url).hostname.replace('www.',''); } catch { return r.url; } })();
            const tagHtml = (r.tags || []).map(t => {
                const c = tagColors[t] || '#888';
                return `<span style="font-size:10px;font-family:var(--mono);color:${c};border:1px solid ${c}44;padding:1px 5px;border-radius:3px;margin-left:4px;">${t}</span>`;
            }).join('');
            return `<div style="padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.04);display:flex;align-items:flex-start;gap:6px;">
                <a href="${r.url}" target="_blank" rel="noopener"
                   style="font-size:12px;color:var(--accent);text-decoration:none;flex:1;word-break:break-all;"
                   title="${r.url}">${domain}</a>
                ${tagHtml}
            </div>`;
        }).join('');
    } else {
        refEl.innerHTML = '<div class="tool-no-data">No references found.</div>';
    }
}

function renderList(list, total) {
    document.getElementById('list-count').textContent = list.length;
    document.getElementById('list-total-note').textContent =
        total > list.length ? ` of ${total.toLocaleString()} total` : '';

    document.getElementById('cve-cards').innerHTML = list.map(cve => {
        const color = cvssColor(cve.cvss_score);
        const score = cve.cvss_score !== null ? cve.cvss_score.toFixed(1) : '—';
        const sev   = cve.severity || '';
        const desc  = cve.description
            ? (cve.description.length > 180 ? cve.description.substring(0, 180) + '…' : cve.description)
            : 'No description.';

        return `<div class="tool-card" style="margin-bottom:12px;cursor:pointer;"
                     onclick="setAndSearch('${cve.id}')">
            <div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;">
                <div style="flex-shrink:0;text-align:center;min-width:60px;">
                    <div style="font-family:var(--mono);font-size:28px;font-weight:700;color:${color};line-height:1;">${score}</div>
                    ${sev ? `<div style="font-size:11px;font-weight:600;color:${color};letter-spacing:0.5px;">${sev.toUpperCase()}</div>` : ''}
                </div>
                <div style="flex:1;min-width:200px;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap;">
                        <span style="font-family:var(--mono);font-size:14px;font-weight:700;color:var(--accent);">${cve.id}</span>
                        <span style="font-size:12px;color:var(--text3);">${fmtDate(cve.published)}</span>
                        ${sev ? `<span class="risk-badge ${cvssSevClass(sev)}" style="font-size:10px;">${sev}</span>` : ''}
                    </div>
                    <div style="font-size:13px;color:var(--text3);line-height:1.5;">${desc}</div>
                </div>
                <div style="flex-shrink:0;align-self:center;font-size:13px;color:var(--text3);">
                    &#8250; Details
                </div>
            </div>
        </div>`;
    }).join('');
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('cve-input').addEventListener('keydown', e => {
        if (e.key === 'Enter') lookupCVE();
    });
});
</script>

</body>
</html>
