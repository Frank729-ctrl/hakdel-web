<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'tools-port';
$topbar_title = 'Port Scanner';
$gate_feature = 'Port Scanner'; $gate_hard = true; require __DIR__ . '/../partials/pro_gate.php';

$uid = (int)$user['id'];
$pdo = db();

// Ensure table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS port_scans (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        target VARCHAR(255) NOT NULL,
        mode VARCHAR(20) DEFAULT 'common',
        open_ports JSON,
        scanned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id)
    )");
} catch (Exception $e) {}

$service_names = [
    21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 25 => 'SMTP', 53 => 'DNS',
    80 => 'HTTP', 110 => 'POP3', 143 => 'IMAP', 443 => 'HTTPS',
    445 => 'SMB', 993 => 'IMAPS', 995 => 'POP3S',
    1433 => 'MSSQL', 1521 => 'Oracle', 3306 => 'MySQL',
    3389 => 'RDP', 5432 => 'PostgreSQL', 5900 => 'VNC',
    6379 => 'Redis', 8080 => 'HTTP-Alt', 8443 => 'HTTPS-Alt',
    8888 => 'HTTP-Dev', 27017 => 'MongoDB',
];

$common_ports = array_keys($service_names);

$results = null;
$scan_target = '';
$scan_mode   = '';
$scan_error  = '';
$open_ports  = [];
$history     = [];

// Load history
try {
    $h_stmt = $pdo->prepare('SELECT * FROM port_scans WHERE user_id = ? ORDER BY scanned_at DESC LIMIT 10');
    $h_stmt->execute([$uid]);
    $history = $h_stmt->fetchAll();
} catch (Exception $e) {}

if (is_post() && verify_csrf($_POST['csrf'] ?? '')) {
    $target = trim($_POST['target'] ?? '');
    $mode   = $_POST['mode'] ?? 'common';

    if (!$target) {
        $scan_error = 'Please enter a target IP or hostname.';
    } else {
        $scan_target = $target;
        $scan_mode   = $mode;

        if ($mode === 'hackertarget') {
            // Use HackerTarget nmap API
            $url = 'https://api.hackertarget.com/nmap/?q=' . urlencode($target);
            $ctx = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'HakDel/1.0']]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false || str_contains($raw, 'error')) {
                $scan_error = 'HackerTarget API request failed. Try manual scan.';
            } else {
                $results = $raw;
                // Parse open ports from nmap output
                preg_match_all('/(\d+)\/tcp\s+open\s+(\S+)/i', $raw, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $port = (int)$m[1];
                    $open_ports[] = [
                        'port'    => $port,
                        'service' => $service_names[$port] ?? $m[2],
                        'status'  => 'open',
                    ];
                }
            }
        } else {
            // Manual socket scan
            $results = 'manual';
            foreach ($common_ports as $port) {
                $fp = @fsockopen($target, $port, $errno, $errstr, 1.0);
                if ($fp !== false) {
                    fclose($fp);
                    $open_ports[] = [
                        'port'    => $port,
                        'service' => $service_names[$port] ?? 'Unknown',
                        'status'  => 'open',
                    ];
                }
            }
        }

        if ($results !== null && !$scan_error) {
            // Save to DB
            try {
                $pdo->prepare('INSERT INTO port_scans (user_id, target, mode, open_ports) VALUES (?, ?, ?, ?)')
                    ->execute([$uid, $target, $mode, json_encode($open_ports)]);
                // Refresh history
                $h_stmt->execute([$uid]);
                $history = $h_stmt->fetchAll();
            } catch (Exception $e) {}
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Port Scanner — HakDel</title>
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="stylesheet" href="/assets/layout.css">
  <link rel="stylesheet" href="/assets/tools.css">
  <style>
    .port-results-card {
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: var(--radius-lg); overflow: hidden;
    }
    .port-results-header {
      padding: 14px 18px; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
    }
    .port-results-title { font-family: var(--mono); font-size: 12px; font-weight: 700; color: var(--text); }
    .port-table { width: 100%; border-collapse: collapse; }
    .port-table th {
      font-family: var(--mono); font-size: 10px; color: var(--text3);
      text-transform: uppercase; letter-spacing: 1px;
      padding: 10px 16px; border-bottom: 1px solid var(--border);
      text-align: left; background: var(--bg3);
    }
    .port-table td {
      padding: 10px 16px; border-bottom: 1px solid rgba(255,255,255,0.04);
      font-size: 13px;
    }
    .port-table tr:last-child td { border-bottom: none; }
    .port-num { font-family: var(--mono); font-size: 14px; color: var(--accent); font-weight: 700; }
    .port-service { color: var(--text); font-weight: 500; }
    .port-status-open {
      display: inline-flex; align-items: center; gap: 4px;
      background: rgba(0,212,170,0.1); color: var(--accent);
      border: 1px solid rgba(0,212,170,0.25);
      font-family: var(--mono); font-size: 10px; padding: 2px 7px; border-radius: 4px;
    }
    .port-no-open {
      padding: 32px; text-align: center; font-size: 13px; color: var(--text3);
    }
    .hackertarget-raw {
      font-family: var(--mono); font-size: 12px; color: var(--text2);
      background: var(--bg3); padding: 16px; margin: 0; overflow-x: auto;
      white-space: pre-wrap; border-top: 1px solid var(--border);
      max-height: 400px; overflow-y: auto; line-height: 1.6;
    }
    .mode-tabs { display: flex; gap: 0; }
    .mode-tab {
      padding: 8px 16px; border: 1px solid var(--border2);
      background: var(--bg3); color: var(--text2);
      font-family: var(--mono); font-size: 12px; cursor: pointer;
      transition: all 0.12s;
    }
    .mode-tab:first-child { border-radius: var(--radius) 0 0 var(--radius); }
    .mode-tab:last-child { border-radius: 0 var(--radius) var(--radius) 0; border-left: none; }
    .mode-tab.active { background: rgba(0,212,170,0.1); border-color: var(--accent); color: var(--accent); }
    .hist-table { width: 100%; border-collapse: collapse; }
    .hist-table th {
      font-family: var(--mono); font-size: 10px; color: var(--text3);
      text-transform: uppercase; letter-spacing: 1px;
      padding: 9px 14px; border-bottom: 1px solid var(--border);
      text-align: left; background: var(--bg3);
    }
    .hist-table td {
      padding: 9px 14px; border-bottom: 1px solid rgba(255,255,255,0.04);
      font-size: 12px; color: var(--text2);
    }
    .hist-table tr:last-child td { border-bottom: none; }
    .hist-target { font-family: var(--mono); color: var(--text); }
    .hist-ports { color: var(--accent); font-family: var(--mono); font-size: 11px; }
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
      <h1 class="hk-page-title">Port Scanner</h1>
      <p class="hk-page-sub">Scan hosts for open ports and running services</p>
    </div>
  </div>

  <!-- Scan form -->
  <div class="tool-card">
    <div class="tool-card-header">
      <span class="tool-card-title">Port Scan</span>
    </div>
    <div style="padding:20px">
      <form method="POST">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
        <div style="display:flex;flex-direction:column;gap:14px">
          <div>
            <label class="tool-label">Target IP or Hostname</label>
            <input type="text" name="target" class="tool-input"
                   placeholder="192.168.1.1 or example.com"
                   value="<?php echo h($scan_target); ?>"
                   required>
          </div>
          <div>
            <label class="tool-label">Scan Mode</label>
            <div class="mode-tabs">
              <label class="mode-tab <?php echo ($scan_mode ?: 'common') === 'common' ? 'active' : ''; ?>">
                <input type="radio" name="mode" value="common" style="display:none"
                       <?php echo ($scan_mode ?: 'common') === 'common' ? 'checked' : ''; ?>>
                Common Ports (Fast)
              </label>
              <label class="mode-tab <?php echo $scan_mode === 'hackertarget' ? 'active' : ''; ?>"
                     onclick="this.querySelector('input').checked=true;document.querySelectorAll('.mode-tab').forEach(e=>e.classList.remove('active'));this.classList.add('active')">
                <input type="radio" name="mode" value="hackertarget" style="display:none"
                       <?php echo $scan_mode === 'hackertarget' ? 'checked' : ''; ?>>
                Full nmap (HackerTarget)
              </label>
            </div>
            <div style="font-size:11px;color:var(--text3);margin-top:6px">
              Common: checks <?php echo count($common_ports); ?> well-known ports via direct socket.
              Full: uses HackerTarget's free nmap API (internet targets only).
            </div>
          </div>
          <?php if ($scan_error): ?>
          <div style="background:rgba(255,77,77,0.08);border:1px solid rgba(255,77,77,0.2);border-radius:var(--radius);padding:10px 14px;font-size:13px;color:var(--danger)">
            <?php echo h($scan_error); ?>
          </div>
          <?php endif; ?>
          <div>
            <button type="submit" class="btn-tool-primary">Scan</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <?php if ($results !== null && !$scan_error): ?>
  <!-- Results -->
  <div class="port-results-card">
    <div class="port-results-header">
      <div class="port-results-title">
        &#128268; Scan Results — <?php echo h($scan_target); ?>
        <span style="color:var(--text3);font-weight:400;margin-left:8px">
          <?php echo count($open_ports); ?> open port<?php echo count($open_ports) !== 1 ? 's' : ''; ?>
        </span>
      </div>
      <span style="font-family:var(--mono);font-size:10px;color:var(--text3)"><?php echo strtoupper($scan_mode ?: 'common'); ?></span>
    </div>

    <?php if (!empty($open_ports)): ?>
    <table class="port-table">
      <thead>
        <tr>
          <th>Port</th>
          <th>Service</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($open_ports as $p): ?>
        <tr>
          <td><span class="port-num"><?php echo (int)$p['port']; ?></span></td>
          <td class="port-service"><?php echo h($p['service']); ?></td>
          <td><span class="port-status-open">&#9679; open</span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="port-no-open">
      No open ports found<?php echo $scan_mode === 'hackertarget' ? '' : ' in common port range'; ?>.
    </div>
    <?php endif; ?>

    <?php if ($scan_mode === 'hackertarget' && $results !== 'manual'): ?>
    <pre class="hackertarget-raw"><?php echo h($results); ?></pre>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($history)): ?>
  <!-- History -->
  <div class="port-results-card">
    <div class="port-results-header">
      <div class="port-results-title">&#9783; Recent Scans</div>
    </div>
    <table class="hist-table">
      <thead>
        <tr>
          <th>Target</th>
          <th>Mode</th>
          <th>Open Ports</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($history as $h): ?>
        <?php $ports = json_decode($h['open_ports'] ?? '[]', true) ?: []; ?>
        <tr>
          <td class="hist-target"><?php echo h($h['target']); ?></td>
          <td style="font-family:var(--mono);font-size:11px;color:var(--text3)"><?php echo h($h['mode']); ?></td>
          <td class="hist-ports">
            <?php if ($ports): ?>
            <?php echo implode(', ', array_column($ports, 'port')); ?>
            <?php else: ?>
            <span style="color:var(--text3)">none</span>
            <?php endif; ?>
          </td>
          <td style="font-family:var(--mono);font-size:10px;color:var(--text3)"><?php echo date('M j, Y H:i', strtotime($h['scanned_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</main>
</div>

<script>
// Mode tab toggle
document.querySelectorAll('.mode-tab').forEach(function(tab){
  tab.addEventListener('click', function(){
    document.querySelectorAll('.mode-tab').forEach(function(t){ t.classList.remove('active'); });
    this.classList.add('active');
    this.querySelector('input').checked = true;
  });
});
</script>
</body>
</html>
