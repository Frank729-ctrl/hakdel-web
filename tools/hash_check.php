<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'tools-hash';
$sidebar_sub  = 'Security Tools';
$topbar_title = 'Hash Lookup';
$gate_feature = 'Hash Lookup'; $gate_hard = true; require __DIR__ . '/../partials/pro_gate.php';

// ── AJAX handler (POST) ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!verify_csrf($_POST['csrf'] ?? '')) {
        echo json_encode(['error' => 'Invalid request']); exit;
    }

    $hash = strtolower(trim($_POST['hash'] ?? ''));

    // Validate and detect type
    if (!preg_match('/^[a-f0-9]+$/', $hash)) {
        echo json_encode(['error' => 'Invalid hash — must be hex characters only.']); exit;
    }

    $hash_type = match(strlen($hash)) {
        32 => 'md5',
        40 => 'sha1',
        64 => 'sha256',
        default => null,
    };

    if (!$hash_type) {
        echo json_encode(['error' => 'Unrecognised hash length. Supported: MD5 (32), SHA1 (40), SHA256 (64).']); exit;
    }

    $vt_key = getenv('VIRUSTOTAL_API_KEY') ?: '';

    // ── Parallel API calls ────────────────────────────────────────────────────
    $mh      = curl_multi_init();
    $handles = [];

    // VirusTotal
    if ($vt_key) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://www.virustotal.com/api/v3/files/' . urlencode($hash),
            CURLOPT_HTTPHEADER     => ['x-apikey: ' . $vt_key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $handles['virustotal'] = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    // MalwareBazaar (no key needed)
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://mb-api.abuse.ch/api/v1/',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['query' => 'get_info', 'hash' => $hash]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $handles['malwarebazaar'] = $ch;
    curl_multi_add_handle($mh, $ch);

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

    // ── Parse VirusTotal ──────────────────────────────────────────────────────
    $vt = null;
    if (isset($raw['virustotal']['code']) && $raw['virustotal']['code'] === 200) {
        $attr  = $raw['virustotal']['body']['data']['attributes'] ?? [];
        $stats = $attr['last_analysis_stats'] ?? [];
        $names = $attr['popular_threat_classification']['suggested_threat_label'] ?? null;

        // Collect malware family names from engine results
        $families = [];
        foreach ($attr['last_analysis_results'] ?? [] as $engine => $res) {
            if (!empty($res['result']) && ($res['category'] === 'malicious' || $res['category'] === 'suspicious')) {
                $families[] = $res['result'];
            }
        }
        $families = array_values(array_unique(array_filter($families)));
        sort($families);

        $vt = [
            'malicious'     => (int)($stats['malicious']  ?? 0),
            'suspicious'    => (int)($stats['suspicious'] ?? 0),
            'harmless'      => (int)($stats['harmless']   ?? 0),
            'undetected'    => (int)($stats['undetected'] ?? 0),
            'file_type'     => $attr['type_description']  ?? ($attr['magic'] ?? ''),
            'file_size'     => isset($attr['size']) ? (int)$attr['size'] : null,
            'first_seen'    => $attr['first_submission_date'] ?? null,
            'last_analysis' => $attr['last_analysis_date']   ?? null,
            'threat_label'  => $names,
            'families'      => array_slice($families, 0, 10),
            'names'         => array_slice(array_keys($attr['names'] ?? []), 0, 5),
            'sha256'        => $attr['sha256'] ?? $hash,
            'md5'           => $attr['md5']    ?? '',
            'sha1'          => $attr['sha1']   ?? '',
            'tags'          => array_slice($attr['tags'] ?? [], 0, 8),
        ];
    } elseif (isset($raw['virustotal']['code']) && $raw['virustotal']['code'] === 404) {
        $vt = ['not_found' => true];
    }

    // ── Parse MalwareBazaar ───────────────────────────────────────────────────
    $mb = null;
    if (!empty($raw['malwarebazaar']['body']['data'][0])) {
        $d  = $raw['malwarebazaar']['body']['data'][0];
        $mb = [
            'file_name'   => $d['file_name']      ?? '',
            'file_type'   => $d['file_type_mime']  ?? ($d['file_type'] ?? ''),
            'file_size'   => isset($d['file_size']) ? (int)$d['file_size'] : null,
            'family'      => $d['signature']       ?? '',
            'first_seen'  => $d['first_seen']      ?? null,
            'last_seen'   => $d['last_seen']       ?? null,
            'tags'        => $d['tags']            ?? [],
            'delivery'    => $d['delivery_method'] ?? '',
            'reporter'    => $d['reporter']        ?? '',
            'origin'      => $d['origin_country']  ?? '',
            'comment'     => $d['comment']         ?? '',
            'sha256'      => $d['sha256_hash']     ?? '',
            'md5'         => $d['md5_hash']        ?? '',
            'sha1'        => $d['sha1_hash']       ?? '',
        ];
    } elseif (!empty($raw['malwarebazaar']['body']['query_status'])
              && $raw['malwarebazaar']['body']['query_status'] === 'hash_not_found') {
        $mb = ['not_found' => true];
    }

    // ── Verdict ───────────────────────────────────────────────────────────────
    $verdict = 'unknown';
    $mal_count = $vt['malicious'] ?? 0;
    $sus_count = $vt['suspicious'] ?? 0;

    if (!empty($mb) && empty($mb['not_found'])) {
        $verdict = 'malicious'; // In MalwareBazaar = confirmed malware
    } elseif ($mal_count >= 5) {
        $verdict = 'malicious';
    } elseif ($mal_count > 0 || $sus_count > 0) {
        $verdict = 'suspicious';
    } elseif (isset($vt['not_found']) && isset($mb['not_found'])) {
        $verdict = 'clean';
    } elseif ($vt !== null && !isset($vt['not_found']) && $mal_count === 0 && $sus_count === 0) {
        $verdict = 'clean';
    }

    $result = [
        'hash'       => $hash,
        'hash_type'  => $hash_type,
        'verdict'    => $verdict,
        'vt'         => $vt,
        'mb'         => $mb,
    ];

    // Save to DB
    try {
        db()->prepare(
            'INSERT INTO hash_checks (user_id, hash_value, hash_type, result, verdict) VALUES (?, ?, ?, ?, ?)'
        )->execute([(int)$user['id'], $hash, $hash_type, json_encode($result), $verdict]);
    } catch (Exception $e) {}

    echo json_encode($result);
    exit;
}

// ── History ───────────────────────────────────────────────────────────────────
try {
    $stmt = db()->prepare(
        'SELECT hash_value, hash_type, verdict, result, checked_at
         FROM hash_checks WHERE user_id = ?
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
<title>Hash Lookup — HakDel</title>
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
        &#128273; Hash Lookup
      </h1>
      <p style="font-size:13px;color:var(--text3);margin:0;">
        Check any MD5, SHA1, or SHA256 hash against VirusTotal and MalwareBazaar.
      </p>
    </div>

    <!-- Input form -->
    <div class="tool-card" style="margin-bottom:24px;">
      <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
        <div style="flex:1;min-width:280px;">
          <label style="font-family:var(--mono);font-size:11px;color:var(--text3);letter-spacing:1px;text-transform:uppercase;display:block;margin-bottom:6px;">
            File Hash &mdash; <span id="hash-type-label" style="color:var(--accent);">paste below to detect type</span>
          </label>
          <input type="text" id="hash-input" class="form-input"
                 placeholder="MD5 (32) · SHA1 (40) · SHA256 (64)"
                 style="font-family:var(--mono);font-size:13px;letter-spacing:1px;"
                 maxlength="64" autocomplete="off" autocorrect="off" spellcheck="false">
        </div>
        <button id="check-btn" class="btn-tool-primary" onclick="checkHash()">
          &#128269; Check Hash
        </button>
      </div>
      <div style="margin-top:10px;font-family:var(--mono);font-size:11px;color:var(--text3);">
        Example SHA256: <span style="color:var(--text2);cursor:pointer;"
          onclick="document.getElementById('hash-input').value=this.textContent;detectHashType();">
          275a021bbfb6489e54d471899f7db9d1663fc695ec2fe2a2c4538aabf651fd0f</span>
      </div>
      <div id="hash-error" class="tool-error" style="display:none;margin-top:12px;"></div>
    </div>

    <!-- Loading -->
    <div id="hash-loading" style="display:none;text-align:center;padding:48px 0;">
      <div class="tool-loading-dots"><span></span><span></span><span></span></div>
      <p style="font-family:var(--mono);font-size:13px;color:var(--text3);margin-top:16px;">
        Querying VirusTotal &middot; MalwareBazaar&hellip;
      </p>
    </div>

    <!-- Results -->
    <div id="hash-results" style="display:none;">

      <!-- Verdict header row -->
      <div class="tools-grid tools-grid-4" style="margin-bottom:16px;">
        <div class="tool-card tool-stat-card">
          <div class="tool-stat-label">Hash Value</div>
          <div id="res-hash" class="tool-stat-val"
               style="font-size:11px;letter-spacing:0.5px;word-break:break-all;color:var(--text2);">—</div>
        </div>
        <div class="tool-card tool-stat-card">
          <div class="tool-stat-label">Type</div>
          <div id="res-type" class="tool-stat-val" style="font-size:22px;text-transform:uppercase;">—</div>
        </div>
        <div class="tool-card tool-stat-card">
          <div class="tool-stat-label">Verdict</div>
          <div id="res-verdict" style="margin-top:6px;"></div>
        </div>
        <div class="tool-card tool-stat-card">
          <div class="tool-stat-label">File Type</div>
          <div id="res-filetype" class="tool-stat-val" style="font-size:13px;">—</div>
        </div>
      </div>

      <!-- VirusTotal + MalwareBazaar -->
      <div class="tools-grid tools-grid-2" style="margin-bottom:24px;">

        <!-- VirusTotal card -->
        <div class="tool-card">
          <div class="tool-card-header">
            <span class="tool-card-icon" style="color:#4f90f0;">&#128737;</span>
            VirusTotal
          </div>
          <div id="vt-content" class="tool-card-body">
            <div class="tool-no-data">No data yet</div>
          </div>
        </div>

        <!-- MalwareBazaar card -->
        <div class="tool-card">
          <div class="tool-card-header">
            <span class="tool-card-icon" style="color:#ff4d6d;">&#9763;</span>
            MalwareBazaar
          </div>
          <div id="mb-content" class="tool-card-body">
            <div class="tool-no-data">No data yet</div>
          </div>
        </div>
      </div>

    </div>

    <!-- History -->
    <?php if (!empty($history)): ?>
    <div class="tool-card">
      <div class="tool-card-header" style="margin-bottom:14px;">
        <span class="tool-card-icon">&#128337;</span>
        Recent Hash Checks
      </div>
      <table class="ip-history-table">
        <thead>
          <tr>
            <th>Hash</th>
            <th>Type</th>
            <th>Verdict</th>
            <th>File Type</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $row):
            $r = json_decode($row['result'], true) ?? [];
            $file_type = $r['vt']['file_type'] ?? ($r['mb']['file_type'] ?? '—');
            $vclass = match($row['verdict']) {
                'malicious'  => 'risk-dangerous',
                'suspicious' => 'risk-suspicious',
                'clean'      => 'risk-clean',
                default      => 'risk-suspicious',
            };
          ?>
          <tr>
            <td style="font-family:var(--mono);font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;">
              <span style="cursor:pointer;color:var(--accent);"
                    onclick="document.getElementById('hash-input').value='<?= h($row['hash_value']) ?>';detectHashType();checkHash();"
                    title="<?= h($row['hash_value']) ?>">
                <?= h(substr($row['hash_value'], 0, 16)) ?>…
              </span>
            </td>
            <td style="font-family:var(--mono);font-size:12px;text-transform:uppercase;color:var(--text3);">
              <?= h($row['hash_type'] ?? '—') ?>
            </td>
            <td><span class="risk-badge <?= $vclass ?>"><?= h($row['verdict'] ?? 'unknown') ?></span></td>
            <td style="font-size:13px;color:var(--text3);"><?= h($file_type) ?></td>
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

function detectHashType() {
    const val = document.getElementById('hash-input').value.trim();
    const label = document.getElementById('hash-type-label');
    const types = { 32: 'MD5', 40: 'SHA1', 64: 'SHA256' };
    if (/^[a-fA-F0-9]+$/.test(val) && types[val.length]) {
        label.textContent = types[val.length] + ' detected';
        label.style.color = 'var(--accent)';
    } else if (val.length > 0) {
        label.textContent = val.length + ' chars — unrecognised';
        label.style.color = '#ff4d6d';
    } else {
        label.textContent = 'paste below to detect type';
        label.style.color = 'var(--text3)';
    }
}

document.getElementById('hash-input').addEventListener('input', detectHashType);

async function checkHash() {
    const hash = document.getElementById('hash-input').value.trim().toLowerCase();
    const errEl = document.getElementById('hash-error');
    errEl.style.display = 'none';

    if (!hash) { showHashError('Please enter a hash value.'); return; }

    document.getElementById('check-btn').disabled = true;
    document.getElementById('check-btn').textContent = 'Checking…';
    document.getElementById('hash-loading').style.display = 'block';
    document.getElementById('hash-results').style.display = 'none';

    try {
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('hash', hash);

        const res  = await fetch('/tools/hash_check.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.error) { showHashError(data.error); return; }

        renderHashResults(data);
        document.getElementById('hash-results').style.display = 'block';
    } catch (e) {
        showHashError('Network error. Please try again.');
    } finally {
        document.getElementById('hash-loading').style.display = 'none';
        document.getElementById('check-btn').disabled = false;
        document.getElementById('check-btn').innerHTML = '&#128269; Check Hash';
    }
}

function showHashError(msg) {
    const el = document.getElementById('hash-error');
    el.textContent = msg;
    el.style.display = 'block';
    document.getElementById('hash-loading').style.display = 'none';
    document.getElementById('check-btn').disabled = false;
    document.getElementById('check-btn').innerHTML = '&#128269; Check Hash';
}

function fmtBytes(b) {
    if (!b) return '—';
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
    return (b/1048576).toFixed(2) + ' MB';
}
function fmtDate(ts) {
    if (!ts) return '—';
    return new Date(ts * 1000).toLocaleDateString('en-GB', {day:'numeric',month:'short',year:'numeric'});
}
function fmtDateStr(s) {
    if (!s) return '—';
    return new Date(s).toLocaleDateString('en-GB', {day:'numeric',month:'short',year:'numeric'});
}

function verdictBadge(v) {
    const map = {
        malicious:  ['risk-dangerous', '&#9760; Malicious'],
        suspicious: ['risk-suspicious','&#9888; Suspicious'],
        clean:      ['risk-clean',     '&#10003; Clean'],
        unknown:    ['risk-suspicious','&#63; Unknown'],
    };
    const [cls, label] = map[v] || map.unknown;
    return `<span class="risk-badge ${cls}" style="font-size:13px;padding:6px 16px;">${label}</span>`;
}

function renderHashResults(d) {
    const v = d.vt;
    const m = d.mb;

    // Header stats
    document.getElementById('res-hash').textContent     = d.hash;
    document.getElementById('res-type').textContent     = (d.hash_type || '—').toUpperCase();
    document.getElementById('res-verdict').innerHTML    = verdictBadge(d.verdict);
    document.getElementById('res-filetype').textContent = v?.file_type || m?.file_type || '—';

    // ── VirusTotal ────────────────────────────────────────────────────────────
    const vtEl = document.getElementById('vt-content');

    if (v && v.not_found) {
        vtEl.innerHTML = `<div style="text-align:center;padding:20px 0;">
            <div style="font-size:28px;margin-bottom:8px;">&#10003;</div>
            <div style="font-family:var(--mono);font-size:14px;color:#00d4aa;">Not found in VirusTotal</div>
            <div style="font-size:13px;color:var(--text3);margin-top:6px;">This hash has never been submitted for analysis.</div>
        </div>`;
    } else if (v && !v.not_found) {
        const total   = v.malicious + v.suspicious + v.harmless + v.undetected;
        const flagged = v.malicious + v.suspicious;
        const pctMal  = total > 0 ? Math.round(v.malicious  / total * 100) : 0;
        const pctSus  = total > 0 ? Math.round(v.suspicious / total * 100) : 0;
        const pctHarm = total > 0 ? Math.round(v.harmless   / total * 100) : 0;
        const barColor = v.malicious > 5 ? '#ff4d6d' : (v.malicious > 0 ? '#ff9800' : '#00d4aa');

        const familiesHtml = v.families.length
            ? v.families.slice(0,8).map(f => `<span class="cve-badge" style="background:rgba(255,77,109,0.08);font-size:11px;">${f}</span>`).join('')
            : '';
        const tagsHtml = v.tags.length
            ? v.tags.map(t => `<span class="port-badge" style="font-size:11px;">${t}</span>`).join('')
            : '';
        const namesHtml = v.names.length
            ? v.names.join(', ')
            : '';

        vtEl.innerHTML = `
            <div style="margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px;">
                    <span style="font-family:var(--mono);font-size:24px;font-weight:700;color:${barColor};">${flagged}<span style="font-size:14px;color:var(--text3);">/${total}</span></span>
                    <span style="font-size:13px;color:var(--text3);">engines flagged this</span>
                </div>
                <div style="display:flex;height:10px;border-radius:5px;overflow:hidden;background:rgba(255,255,255,0.05);">
                    <div style="width:${pctMal}%;background:#ff4d6d;transition:width 0.5s;"></div>
                    <div style="width:${pctSus}%;background:#ff9800;transition:width 0.5s;"></div>
                    <div style="width:${pctHarm}%;background:#00d4aa;transition:width 0.5s;"></div>
                </div>
                <div style="display:flex;gap:14px;margin-top:6px;font-size:12px;color:var(--text3);font-family:var(--mono);">
                    <span style="color:#ff4d6d;">&#9632; ${v.malicious} malicious</span>
                    <span style="color:#ff9800;">&#9632; ${v.suspicious} suspicious</span>
                    <span style="color:#00d4aa;">&#9632; ${v.harmless} harmless</span>
                </div>
            </div>
            ${v.threat_label ? `<div style="margin-bottom:12px;"><span style="font-family:var(--mono);font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;">Threat Label</span><div style="font-size:14px;color:#ff4d6d;margin-top:4px;font-weight:600;">${v.threat_label}</div></div>` : ''}
            ${familiesHtml ? `<div style="margin-bottom:12px;"><span style="font-family:var(--mono);font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;display:block;margin-bottom:6px;">Malware Names</span><div style="display:flex;flex-wrap:wrap;gap:5px;">${familiesHtml}</div></div>` : ''}
            <div class="tool-data-grid" style="margin-bottom:${tagsHtml?'12':'0'}px;">
                <div class="tool-data-item">
                    <div class="tool-data-label">File Size</div>
                    <div class="tool-data-val">${fmtBytes(v.file_size)}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">First Seen</div>
                    <div class="tool-data-val">${fmtDate(v.first_seen)}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">Last Analysis</div>
                    <div class="tool-data-val">${fmtDate(v.last_analysis)}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">Known Names</div>
                    <div class="tool-data-val" style="font-size:12px;">${namesHtml || '—'}</div>
                </div>
            </div>
            ${tagsHtml ? `<div><span style="font-family:var(--mono);font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;display:block;margin-bottom:6px;">Tags</span><div style="display:flex;flex-wrap:wrap;gap:5px;">${tagsHtml}</div></div>` : ''}`;
    } else {
        vtEl.innerHTML = '<div class="tool-no-data">VirusTotal API key not configured.</div>';
    }

    // ── MalwareBazaar ─────────────────────────────────────────────────────────
    const mbEl = document.getElementById('mb-content');

    if (m && m.not_found) {
        mbEl.innerHTML = `<div style="text-align:center;padding:20px 0;">
            <div style="font-size:28px;margin-bottom:8px;">&#10003;</div>
            <div style="font-family:var(--mono);font-size:14px;color:#00d4aa;">Not in MalwareBazaar</div>
            <div style="font-size:13px;color:var(--text3);margin-top:6px;">This hash is not a known malware sample.</div>
        </div>`;
    } else if (m && !m.not_found) {
        const tagsHtml = Array.isArray(m.tags) && m.tags.length
            ? m.tags.map(t => `<span class="cve-badge" style="font-size:11px;">${t}</span>`).join('')
            : '';

        mbEl.innerHTML = `
            <div style="background:rgba(255,77,109,0.06);border:1px solid rgba(255,77,109,0.25);border-radius:8px;padding:14px;margin-bottom:16px;">
                <div style="font-family:var(--mono);font-size:11px;color:#ff4d6d;letter-spacing:1px;text-transform:uppercase;margin-bottom:4px;">&#9760; Confirmed Malware Sample</div>
                <div style="font-size:14px;color:var(--text);font-weight:600;">${m.family || 'Unknown family'}</div>
            </div>
            <div class="tool-data-grid" style="margin-bottom:12px;">
                <div class="tool-data-item">
                    <div class="tool-data-label">File Name</div>
                    <div class="tool-data-val" style="word-break:break-all;">${m.file_name || '—'}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">File Type</div>
                    <div class="tool-data-val">${m.file_type || '—'}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">File Size</div>
                    <div class="tool-data-val">${fmtBytes(m.file_size)}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">First Seen</div>
                    <div class="tool-data-val">${fmtDateStr(m.first_seen)}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">Delivery Method</div>
                    <div class="tool-data-val">${m.delivery || '—'}</div>
                </div>
                <div class="tool-data-item">
                    <div class="tool-data-label">Origin</div>
                    <div class="tool-data-val">${m.origin || '—'}</div>
                </div>
            </div>
            ${tagsHtml ? `<div><span style="font-family:var(--mono);font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:1px;display:block;margin-bottom:6px;">Tags</span><div style="display:flex;flex-wrap:wrap;gap:5px;">${tagsHtml}</div></div>` : ''}
            ${m.sha256 ? `<div style="margin-top:12px;font-family:var(--mono);font-size:11px;color:var(--text3);">SHA256: <span style="color:var(--text2);">${m.sha256}</span></div>` : ''}`;
    } else {
        mbEl.innerHTML = '<div class="tool-no-data">MalwareBazaar returned no data for this hash.</div>';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('hash-input').addEventListener('keydown', e => {
        if (e.key === 'Enter') checkHash();
    });
});
</script>

</body>
</html>
