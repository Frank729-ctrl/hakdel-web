<?php
require_once __DIR__ . '/../config/app.php';
$user = require_login();

$username  = $user['username'];
$initials  = $user['avatar_initials'] ?? strtoupper(substr($username, 0, 2));
$xp_data   = xp_progress((int)$user['xp']);
$level     = $xp_data['level'];

$is_pro           = is_pro($user);
$scans_today      = free_scans_today((int)$user['id']);
$quota_exceeded   = scan_quota_exceeded($user);

$profiles = [
    'quick'  => ['label' => 'Quick Scan', 'modules' => [
        'whois','ssl','headers','dns','cookies','waf','stack'
    ]],
    'full'   => ['label' => 'Full Scan',  'modules' => [
        'whois','ssl','headers','dns','ports','cms','cve',
        'cookies','xss','sqli','dirs','access','stack',
        'email','waf','session','smtp','sniffing','malware','db',
        'virustotal','nvd',
        'subdomains','cors','methods','ratelimit','redirect'
    ]],
    'custom' => ['label' => 'Custom',     'modules' => []],
];

$all_modules = [
    'whois'    => ['label' => 'WHOIS / Domain',        'group' => 'Passive'],
    'ssl'      => ['label' => 'SSL/TLS Certificate',   'group' => 'Passive'],
    'headers'  => ['label' => 'HTTP Headers',           'group' => 'Passive'],
    'dns'      => ['label' => 'DNS / DNSSEC',           'group' => 'Passive'],
    'email'    => ['label' => 'Email Security',         'group' => 'Passive'],
    'ports'    => ['label' => 'Open Ports',             'group' => 'Active'],
    'cms'      => ['label' => 'CMS Fingerprint',        'group' => 'Active'],
    'cve'      => ['label' => 'CVE Disclosure',         'group' => 'Active'],
    'stack'    => ['label' => 'Tech Stack Detection',   'group' => 'Active'],
    'waf'      => ['label' => 'WAF Detection',          'group' => 'Active'],
    'cookies'  => ['label' => 'Cookie Security',        'group' => 'Security'],
    'session'  => ['label' => 'Session Security',       'group' => 'Security'],
    'xss'      => ['label' => 'XSS Detection',          'group' => 'Security'],
    'sqli'     => ['label' => 'SQL Injection Probe',    'group' => 'Security'],
    'dirs'     => ['label' => 'Sensitive Files',        'group' => 'Security'],
    'access'   => ['label' => 'Broken Access Control',  'group' => 'Security'],
    'sniffing' => ['label' => 'Sniffing Exposure',      'group' => 'Security'],
    'smtp'       => ['label' => 'SMTP Enumeration',       'group' => 'Network'],
    'malware'    => ['label' => 'Malware Indicators',     'group' => 'Network'],
    'db'         => ['label' => 'Database Exposure',      'group' => 'Network'],
    'virustotal' => ['label' => 'VirusTotal Reputation',  'group' => 'Threat Intel'],
    'nvd'        => ['label' => 'NVD CVE Lookup',         'group' => 'Threat Intel'],
    'subdomains' => ['label' => 'Subdomain Enumeration',  'group' => 'Passive'],
    'cors'       => ['label' => 'CORS Policy',            'group' => 'Security'],
    'methods'    => ['label' => 'HTTP Methods',           'group' => 'Security'],
    'ratelimit'  => ['label' => 'Rate Limiting',          'group' => 'Security'],
    'redirect'   => ['label' => 'Open Redirect',          'group' => 'Security'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?php echo h(csrf_token()); ?>">
<title>HakDel - Scanner</title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/layout.css">
</head>
<body>

<?php require __DIR__ . '/../partials/topbar.php'; ?>

<div class="hk-shell">

  <?php
$nav_active  = 'scanner';
$sidebar_sub = 'Scanner active';
$sidebar_footer = '<button class="btn-quick-scan" onclick="document.getElementById(\'scan-url\').focus()">&#9654; Quick Scan</button>';
require __DIR__ . '/../partials/sidebar.php';
?>

  <main class="hk-main">

    <div class="hk-page-header">
      <div>
        <div class="hk-page-eyebrow">&#9632; Site Scanner &nbsp;&middot;&nbsp; Ethical use only</div>
        <h1 class="hk-page-title">Security Audit</h1>
        <p class="hk-page-sub">Only test sites you own or have written permission to audit.</p>
      </div>
      <div class="hk-page-actions" id="result-actions" style="display:none">
        <button class="btn-secondary" onclick="exportReport()">&#8659; Export TXT</button>
        <button class="btn-secondary" onclick="openPdfReport()">&#128438; PDF Report</button>
        <button class="btn-ai" id="btn-ai" onclick="requestAIAnalysis()">&#129302; AI Analysis</button>
        <button class="btn-primary"   onclick="resetScan()">&#8635; New Scan</button>
      </div>
    </div>

    <?php if ($quota_exceeded): ?>
    <div style="background:rgba(255,170,0,0.07);border:1px solid rgba(255,170,0,0.25);border-radius:var(--radius);padding:12px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px">
      <div style="font-size:13px;color:var(--warn)">
        &#9888; You've used all <strong><?= FREE_SCAN_LIMIT ?></strong> free scans for today.
        Daily quota resets at midnight.
      </div>
      <a href="/upgrade/" style="flex-shrink:0;padding:7px 16px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:var(--bg);border-radius:var(--radius);font-family:var(--mono);font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap">
        Upgrade to Pro
      </a>
    </div>
    <?php elseif (!$is_pro): ?>
    <div style="background:var(--bg3);border:1px solid var(--border);border-radius:var(--radius);padding:10px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px">
      <div style="font-size:12px;color:var(--text3)">
        Free plan: <strong style="color:var(--text2)"><?= $scans_today ?>/<?= FREE_SCAN_LIMIT ?></strong> scans used today.
      </div>
      <a href="/upgrade/" style="font-family:var(--mono);font-size:11px;color:var(--accent);text-decoration:none">Upgrade for unlimited &rarr;</a>
    </div>
    <?php endif; ?>

    <div class="hk-scan-input-card" id="scan-input-card">
      <div class="scan-url-row">
        <div class="scan-url-wrap">
          <span class="scan-url-icon">&#9670;</span>
          <input type="text" id="scan-url" class="scan-url-field"
                 placeholder="https://example.com" spellcheck="false" autocomplete="off"
                 <?php echo $quota_exceeded ? 'disabled' : ''; ?>>
        </div>
        <button class="btn-primary btn-scan-go" id="btn-scan" onclick="startScan()"
                <?php echo $quota_exceeded ? 'disabled title="Daily quota reached — upgrade to Pro"' : ''; ?>>
          &#9654; Scan
        </button>
      </div>
      <p class="scan-url-error" id="url-error"></p>

      <div class="scan-profiles">
        <?php foreach ($profiles as $key => $profile): ?>
        <button class="profile-pill <?php echo $key === 'quick' ? 'active' : ''; ?>"
                data-profile="<?php echo $key; ?>"
                data-modules="<?php echo htmlspecialchars(json_encode($profile['modules']), ENT_QUOTES); ?>"
                onclick="selectProfile(this)">
          <?php echo $profile['label']; ?>
        </button>
        <?php endforeach; ?>
      </div>

      <div id="custom-modules" style="display:none">
        <div class="custom-modules-grid">
          <?php foreach ($all_modules as $key => $mod): ?>
          <label class="mod-pill">
            <input type="checkbox" class="mod-check" value="<?php echo $key; ?>" checked>
            <span class="mod-pill-inner">
              <span class="mod-group-tag"><?php echo $mod['group']; ?></span>
              <?php echo $mod['label']; ?>
            </span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div id="scan-progress" style="display:none">
        <div class="progress-track">
          <div class="progress-fill" id="progress-fill" style="width:0%"></div>
        </div>
        <div class="scan-timer-row">
          <div class="progress-label" id="progress-status">Initializing...</div>
          <div class="scan-eta" id="scan-eta"></div>
        </div>
      </div>
    </div>

    <div id="results-area"></div>

  </main>
</div>

<script>
/* Pass server-side config to the JS module */
window.SCANNER_API = '<?php echo addslashes(API_BASE); ?>';
</script>
<script src="/assets/js/scanner.js"></script>
</body>
</html>