<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'tools-watch';
$sidebar_sub  = 'Security Tools';
$topbar_title = 'Watchlist';
$gate_feature = 'Watchlist'; $gate_hard = true; require __DIR__ . '/../partials/pro_gate.php';

// ── SSL check helper ──────────────────────────────────────────────────────────
function wl_check_ssl(string $domain): ?array {
    $ctx    = stream_context_create(['ssl' => [
        'capture_peer_cert' => true,
        'verify_peer'       => false,
        'verify_peer_name'  => false,
    ]]);
    $socket = @stream_socket_client("ssl://{$domain}:443", $errno, $errstr, 10,
        STREAM_CLIENT_CONNECT, $ctx);
    if (!$socket) return null;
    $params = stream_context_get_params($socket);
    fclose($socket);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    if (!$cert) return null;
    $info       = openssl_x509_parse($cert);
    $expiry_ts  = $info['validTo_time_t'] ?? null;
    if (!$expiry_ts) return null;
    return [
        'expiry_ts'   => $expiry_ts,
        'expiry_date' => date('Y-m-d', $expiry_ts),
        'days'        => (int)ceil(($expiry_ts - time()) / 86400),
        'issuer'      => $info['issuer']['O'] ?? ($info['issuer']['CN'] ?? ''),
        'subject'     => $info['subject']['CN'] ?? $domain,
    ];
}

// ── DNS snapshot helper ───────────────────────────────────────────────────────
function wl_check_dns(string $domain): array {
    $snap = [];
    foreach ([DNS_A => 'A', DNS_MX => 'MX', DNS_NS => 'NS', DNS_TXT => 'TXT'] as $type => $name) {
        $records = @dns_get_record($domain, $type) ?: [];
        foreach ($records as $r) {
            $val = match($name) {
                'A'   => $r['ip']      ?? '',
                'MX'  => ($r['pri'] ?? 0) . ' ' . ($r['target'] ?? ''),
                'NS'  => $r['target']  ?? '',
                'TXT' => $r['txt']     ?? '',
                default => '',
            };
            if ($val) $snap[$name][] = $val;
        }
    }
    return $snap;
}

// ── DNS diff helper ───────────────────────────────────────────────────────────
function wl_dns_diff(array $old, array $new): array {
    $changes = [];
    $types = array_unique(array_merge(array_keys($old), array_keys($new)));
    foreach ($types as $t) {
        $o = array_unique($old[$t] ?? []);
        $n = array_unique($new[$t] ?? []);
        sort($o); sort($n);
        if ($o !== $n) {
            $added   = array_diff($n, $o);
            $removed = array_diff($o, $n);
            if ($added)   $changes[] = "$t added: "   . implode(', ', $added);
            if ($removed) $changes[] = "$t removed: " . implode(', ', $removed);
        }
    }
    return $changes;
}

// ── AJAX: check single domain now ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_now') {
    header('Content-Type: application/json');
    if (!verify_csrf($_POST['csrf'] ?? '')) { echo json_encode(['error' => 'Invalid request']); exit; }

    $wid = (int)($_POST['watchlist_id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM watchlist WHERE id = ? AND user_id = ?');
    $stmt->execute([$wid, (int)$user['id']]);
    $entry = $stmt->fetch();
    if (!$entry) { echo json_encode(['error' => 'Not found']); exit; }

    $domain   = $entry['domain'];
    $ssl_data = null;
    $dns_data = null;
    $alerts   = [];

    if ($entry['check_ssl']) {
        $ssl_data = wl_check_ssl($domain);
        if ($ssl_data) {
            db()->prepare('UPDATE watchlist SET ssl_expiry_days=?, ssl_last_checked=NOW() WHERE id=?')
                ->execute([$ssl_data['days'], $wid]);
            if ($ssl_data['days'] <= 0) {
                db()->prepare("INSERT INTO watchlist_alerts (watchlist_id,alert_type,message) VALUES (?,?,?)")
                    ->execute([$wid, 'ssl_expired', "SSL certificate for {$domain} has EXPIRED."]);
                $alerts[] = 'SSL expired';
            } elseif ($ssl_data['days'] <= 30) {
                db()->prepare("INSERT INTO watchlist_alerts (watchlist_id,alert_type,message) VALUES (?,?,?)")
                    ->execute([$wid, 'ssl_expiry', "SSL for {$domain} expires in {$ssl_data['days']} days ({$ssl_data['expiry_date']})."]);
                $alerts[] = "SSL expires in {$ssl_data['days']} days";
            }
        }
    }

    if ($entry['check_dns']) {
        $dns_data = wl_check_dns($domain);
        $old_snap = json_decode($entry['dns_snapshot'] ?? '{}', true) ?: [];
        $changes  = $old_snap ? wl_dns_diff($old_snap, $dns_data) : [];
        db()->prepare('UPDATE watchlist SET dns_snapshot=?, dns_last_checked=NOW() WHERE id=?')
            ->execute([json_encode($dns_data), $wid]);
        if ($changes) {
            $msg = "DNS changed for {$domain}: " . implode('; ', $changes);
            db()->prepare("INSERT INTO watchlist_alerts (watchlist_id,alert_type,message) VALUES (?,?,?)")
                ->execute([$wid, 'dns_change', $msg]);
            $alerts[] = 'DNS changed';
        }
    }

    echo json_encode([
        'ssl'    => $ssl_data,
        'dns'    => $dns_data,
        'alerts' => $alerts,
    ]);
    exit;
}

// ── AJAX: dismiss alert ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dismiss') {
    header('Content-Type: application/json');
    if (!verify_csrf($_POST['csrf'] ?? '')) { echo json_encode(['error' => 'Invalid']); exit; }
    $alert_id = (int)($_POST['alert_id'] ?? 0);
    // Verify alert belongs to user's domain
    db()->prepare("UPDATE watchlist_alerts wa
        JOIN watchlist w ON wa.watchlist_id = w.id
        SET wa.is_read = 1
        WHERE wa.id = ? AND w.user_id = ?")
        ->execute([$alert_id, (int)$user['id']]);
    echo json_encode(['ok' => true]); exit;
}

// ── Form POST ─────────────────────────────────────────────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    verify_csrf($_POST['csrf'] ?? '') || ($flash = 'error:Invalid request.');

    if (!$flash) {
        if ($action === 'add') {
            $domain = strtolower(trim(preg_replace('#^https?://#', '', $_POST['domain'] ?? '')));
            $domain = rtrim(explode('/', $domain)[0], '.');
            if (!$domain || !preg_match('/^[a-z0-9][a-z0-9\-\.]+\.[a-z]{2,}$/', $domain)) {
                $flash = 'error:Invalid domain name.';
            } else {
                try {
                    db()->prepare(
                        'INSERT INTO watchlist (user_id, domain, check_ssl, check_dns, alert_email)
                         VALUES (?, ?, ?, ?, ?)'
                    )->execute([
                        (int)$user['id'], $domain,
                        isset($_POST['check_ssl']) ? 1 : 0,
                        isset($_POST['check_dns']) ? 1 : 0,
                        filter_var($_POST['alert_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null,
                    ]);
                    $flash = 'ok:Domain added. Click "Check Now" to run the first check.';
                } catch (Exception $e) {
                    $flash = 'error:Domain already in your watchlist.';
                }
            }
        } elseif ($action === 'remove') {
            $wid = (int)($_POST['watchlist_id'] ?? 0);
            db()->prepare('DELETE FROM watchlist WHERE id = ? AND user_id = ?')
                ->execute([$wid, (int)$user['id']]);
            $flash = 'ok:Domain removed.';
        } elseif ($action === 'toggle') {
            $wid = (int)($_POST['watchlist_id'] ?? 0);
            db()->prepare('UPDATE watchlist SET is_active = 1 - is_active WHERE id = ? AND user_id = ?')
                ->execute([$wid, (int)$user['id']]);
        }
    }
}

// ── Fetch watchlist ───────────────────────────────────────────────────────────
$entries = db()->prepare(
    'SELECT w.*,
        (SELECT COUNT(*) FROM watchlist_alerts a WHERE a.watchlist_id = w.id AND a.is_read = 0) AS unread_alerts
     FROM watchlist w
     WHERE w.user_id = ?
     ORDER BY w.created_at DESC'
);
$entries->execute([(int)$user['id']]);
$entries = $entries->fetchAll();

// ── Fetch unread alerts (all) ─────────────────────────────────────────────────
$alerts_stmt = db()->prepare(
    'SELECT a.*, w.domain FROM watchlist_alerts a
     JOIN watchlist w ON a.watchlist_id = w.id
     WHERE w.user_id = ? AND a.is_read = 0
     ORDER BY a.created_at DESC LIMIT 20'
);
$alerts_stmt->execute([(int)$user['id']]);
$alerts = $alerts_stmt->fetchAll();

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Domain Watchlist — HakDel</title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/layout.css">
</head>
<body>

<?php require_once __DIR__ . '/../partials/topbar.php'; ?>

<div class="hk-shell">
  <?php require_once __DIR__ . '/../partials/sidebar.php'; ?>

  <main class="hk-main" style="padding:28px;max-width:1100px;width:100%;">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
      <div>
        <h1 style="font-family:var(--mono);font-size:20px;font-weight:700;color:var(--text);margin:0 0 4px;">
          &#128204; Domain Watchlist
        </h1>
        <p style="font-size:13px;color:var(--text3);margin:0;">
          Monitor SSL certificate expiry and DNS changes for your domains.
        </p>
      </div>
      <?php if (!empty($alerts)): ?>
      <div style="background:rgba(255,77,109,0.1);border:1px solid rgba(255,77,109,0.3);border-radius:8px;padding:10px 16px;display:flex;align-items:center;gap:8px;">
        <span style="font-size:16px;">&#9888;</span>
        <span style="font-family:var(--mono);font-size:13px;color:#ff4d6d;font-weight:700;">
          <?= count($alerts) ?> unread alert<?= count($alerts) !== 1 ? 's' : '' ?>
        </span>
      </div>
      <?php endif; ?>
    </div>

    <!-- Flash message -->
    <?php if ($flash):
      [$ftype, $fmsg] = explode(':', $flash, 2);
    ?>
    <div style="background:<?= $ftype === 'ok' ? 'rgba(0,212,170,0.08)' : 'rgba(255,77,109,0.08)' ?>;
                border:1px solid <?= $ftype === 'ok' ? 'rgba(0,212,170,0.3)' : 'rgba(255,77,109,0.3)' ?>;
                border-radius:6px;padding:10px 16px;font-size:13px;
                color:<?= $ftype === 'ok' ? '#00d4aa' : '#ff4d6d' ?>;margin-bottom:16px;">
      <?= h($fmsg) ?>
    </div>
    <?php endif; ?>

    <!-- Add domain form -->
    <div class="tool-card" style="margin-bottom:24px;">
      <div class="tool-card-header" style="margin-bottom:16px;">
        <span class="tool-card-icon">&#43;</span> Add Domain to Watchlist
      </div>
      <form method="POST" action="/tools/watchlist.php">
        <input type="hidden" name="csrf"   value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="add">
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
          <div style="flex:2;min-width:200px;">
            <label style="font-family:var(--mono);font-size:10px;color:var(--text3);letter-spacing:1px;text-transform:uppercase;display:block;margin-bottom:5px;">Domain</label>
            <input type="text" name="domain" class="form-input"
                   placeholder="example.com or https://example.com"
                   style="font-family:var(--mono);font-size:14px;" required>
          </div>
          <div style="flex:1;min-width:180px;">
            <label style="font-family:var(--mono);font-size:10px;color:var(--text3);letter-spacing:1px;text-transform:uppercase;display:block;margin-bottom:5px;">Alert Email (optional)</label>
            <input type="email" name="alert_email" class="form-input"
                   placeholder="you@example.com" style="font-size:13px;">
          </div>
          <div style="display:flex;flex-direction:column;gap:8px;padding-bottom:2px;">
            <label style="display:flex;align-items:center;gap:7px;font-size:13px;color:var(--text2);cursor:pointer;">
              <input type="checkbox" name="check_ssl" value="1" checked style="accent-color:var(--accent);">
              Monitor SSL
            </label>
            <label style="display:flex;align-items:center;gap:7px;font-size:13px;color:var(--text2);cursor:pointer;">
              <input type="checkbox" name="check_dns" value="1" checked style="accent-color:var(--accent);">
              Monitor DNS
            </label>
          </div>
          <button type="submit" class="btn-tool-primary" style="padding:10px 20px;">
            &#43; Add
          </button>
        </div>
      </form>
    </div>

    <!-- Unread alerts panel -->
    <?php if (!empty($alerts)): ?>
    <div class="tool-card" style="margin-bottom:24px;border-color:rgba(255,77,109,0.3);">
      <div class="tool-card-header" style="color:#ff4d6d;margin-bottom:14px;">
        <span class="tool-card-icon">&#9888;</span>
        Active Alerts
      </div>
      <div id="alerts-list">
        <?php foreach ($alerts as $alert): ?>
        <div class="wl-alert-row" id="alert-<?= $alert['id'] ?>">
          <div style="display:flex;align-items:flex-start;gap:10px;">
            <span class="risk-badge <?= $alert['alert_type'] === 'dns_change' ? 'risk-suspicious' : ($alert['alert_type'] === 'ssl_expired' ? 'risk-dangerous' : 'risk-malicious') ?>"
                  style="font-size:11px;flex-shrink:0;">
              <?= $alert['alert_type'] === 'dns_change' ? 'DNS' : 'SSL' ?>
            </span>
            <div style="flex:1;">
              <div style="font-size:13px;color:var(--text2);"><?= h($alert['message']) ?></div>
              <div style="font-family:var(--mono);font-size:11px;color:var(--text3);margin-top:3px;">
                <?= h($alert['domain']) ?> &middot; <?= h(date('M j, H:i', strtotime($alert['created_at']))) ?>
              </div>
            </div>
            <button onclick="dismissAlert(<?= $alert['id'] ?>)"
                    style="background:none;border:1px solid rgba(255,255,255,0.1);color:var(--text3);font-size:12px;padding:3px 9px;border-radius:4px;cursor:pointer;">
              Dismiss
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Watchlist domains -->
    <?php if (empty($entries)): ?>
    <div style="text-align:center;padding:60px 0;">
      <div style="font-size:36px;margin-bottom:12px;">&#128204;</div>
      <div style="font-family:var(--mono);font-size:15px;color:var(--text2);margin-bottom:6px;">No domains yet</div>
      <div style="font-size:13px;color:var(--text3);">Add a domain above to start monitoring SSL and DNS.</div>
    </div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:14px;margin-bottom:24px;">
      <?php foreach ($entries as $e):
        $days = $e['ssl_expiry_days'] !== null ? (int)$e['ssl_expiry_days'] : null;
        $ssl_color = $days === null ? '#555'
            : ($days <= 0  ? '#ff4d6d'
            : ($days <= 14 ? '#ff4d6d'
            : ($days <= 30 ? '#ffd166'
            :                '#00d4aa')));
        $ssl_label = $days === null ? 'Not checked'
            : ($days <= 0 ? 'EXPIRED'
            : ($days . ' days left'));
        $ssl_badge_class = $days === null ? ''
            : ($days <= 0  ? 'risk-dangerous'
            : ($days <= 14 ? 'risk-malicious'
            : ($days <= 30 ? 'risk-suspicious'
            :                'risk-clean')));

        $dns_checked  = !empty($e['dns_last_checked']);
        $has_snapshot = !empty($e['dns_snapshot']) && $e['dns_snapshot'] !== '{}';

        $unread = (int)($e['unread_alerts'] ?? 0);
      ?>
      <div class="tool-card wl-domain-card <?= $e['is_active'] ? '' : 'wl-paused' ?>" id="wl-<?= $e['id'] ?>">
        <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;">

          <!-- Domain info -->
          <div style="flex:1;min-width:200px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap;">
              <span style="font-family:var(--mono);font-size:15px;font-weight:700;color:var(--accent);">
                <?= h($e['domain']) ?>
              </span>
              <?php if (!$e['is_active']): ?>
              <span style="font-family:var(--mono);font-size:11px;color:var(--text3);border:1px solid rgba(255,255,255,0.1);padding:2px 7px;border-radius:4px;">Paused</span>
              <?php endif; ?>
              <?php if ($unread > 0): ?>
              <span style="background:#ff4d6d;color:#fff;font-family:var(--mono);font-size:10px;padding:2px 7px;border-radius:10px;"><?= $unread ?> alert<?= $unread > 1 ? 's' : '' ?></span>
              <?php endif; ?>
            </div>

            <div style="display:flex;gap:20px;flex-wrap:wrap;">

              <!-- SSL status -->
              <?php if ($e['check_ssl']): ?>
              <div>
                <div style="font-family:var(--mono);font-size:10px;color:var(--text3);letter-spacing:1px;text-transform:uppercase;margin-bottom:4px;">SSL</div>
                <?php if ($days !== null): ?>
                <span class="risk-badge <?= $ssl_badge_class ?>"><?= $ssl_label ?></span>
                <?php if ($e['ssl_last_checked']): ?>
                <div style="font-size:11px;color:var(--text3);margin-top:4px;font-family:var(--mono);">
                  Checked <?= h(date('M j, H:i', strtotime($e['ssl_last_checked']))) ?>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <span style="font-size:13px;color:var(--text3);">—</span>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <!-- DNS status -->
              <?php if ($e['check_dns']): ?>
              <div>
                <div style="font-family:var(--mono);font-size:10px;color:var(--text3);letter-spacing:1px;text-transform:uppercase;margin-bottom:4px;">DNS</div>
                <?php if ($dns_checked): ?>
                <span class="risk-badge risk-clean">No changes</span>
                <div style="font-size:11px;color:var(--text3);margin-top:4px;font-family:var(--mono);">
                  Checked <?= h(date('M j, H:i', strtotime($e['dns_last_checked']))) ?>
                </div>
                <?php else: ?>
                <span style="font-size:13px;color:var(--text3);">Not checked yet</span>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <!-- Alert email -->
              <?php if ($e['alert_email']): ?>
              <div>
                <div style="font-family:var(--mono);font-size:10px;color:var(--text3);letter-spacing:1px;text-transform:uppercase;margin-bottom:4px;">Alerts To</div>
                <div style="font-size:13px;color:var(--text2);"><?= h($e['alert_email']) ?></div>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Actions -->
          <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;flex-shrink:0;">
            <button class="btn-tool-primary" style="padding:7px 16px;font-size:12px;"
                    onclick="checkNow(<?= $e['id'] ?>, this)">
              &#8635; Check Now
            </button>
            <div style="display:flex;gap:6px;">
              <form method="POST" action="/tools/watchlist.php" style="display:inline;">
                <input type="hidden" name="csrf"         value="<?= h($csrf) ?>">
                <input type="hidden" name="action"       value="toggle">
                <input type="hidden" name="watchlist_id" value="<?= $e['id'] ?>">
                <button type="submit"
                        style="background:none;border:1px solid rgba(255,255,255,0.1);color:var(--text3);font-size:12px;font-family:var(--mono);padding:5px 10px;border-radius:4px;cursor:pointer;">
                  <?= $e['is_active'] ? '&#10074;&#10074; Pause' : '&#9654; Resume' ?>
                </button>
              </form>
              <form method="POST" action="/tools/watchlist.php" style="display:inline;"
                    onsubmit="return confirm('Remove <?= h(addslashes($e['domain'])) ?> from watchlist?')">
                <input type="hidden" name="csrf"         value="<?= h($csrf) ?>">
                <input type="hidden" name="action"       value="remove">
                <input type="hidden" name="watchlist_id" value="<?= $e['id'] ?>">
                <button type="submit"
                        style="background:none;border:1px solid rgba(255,77,109,0.2);color:#ff4d6d;font-size:12px;font-family:var(--mono);padding:5px 10px;border-radius:4px;cursor:pointer;">
                  &#128465;
                </button>
              </form>
            </div>
          </div>
        </div>

        <!-- Check Now result inline -->
        <div id="wl-result-<?= $e['id'] ?>" style="display:none;margin-top:14px;padding-top:14px;border-top:1px solid var(--border);"></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (($user['role'] ?? '') === 'admin'): ?>
    <!-- Cron setup — admin only -->
    <div class="tool-card" style="border-color:rgba(255,255,255,0.06);">
      <div class="tool-card-header" style="margin-bottom:12px;">
        <span class="tool-card-icon">&#9881;</span> Auto-Check Setup
      </div>
      <p style="font-size:13px;color:var(--text3);margin:0 0 10px;">
        Add this cron entry to automatically check all domains every hour:
      </p>
      <code style="display:block;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:12px 16px;font-family:var(--mono);font-size:12px;color:var(--accent);word-break:break-all;">
        0 * * * * php <?= h(realpath(__DIR__ . '/../../')) ?>/frontend/cron/check_watchlist.php --token=<?= h(getenv('WATCHLIST_CRON_TOKEN') ?: '') ?> >> /tmp/hakdel-watchlist.log 2>&1
      </code>
    </div>
    <?php endif; ?>

  </main>
</div>

<script>
const CSRF_WL = <?= json_encode($csrf) ?>;

async function checkNow(wid, btn) {
    const origText = btn.innerHTML;
    btn.disabled   = true;
    btn.innerHTML  = 'Checking…';

    const resultEl = document.getElementById('wl-result-' + wid);
    resultEl.style.display = 'block';
    resultEl.innerHTML = '<div style="display:flex;align-items:center;gap:8px;"><div class="tool-loading-dots"><span></span><span></span><span></span></div><span style="font-size:13px;color:var(--text3);font-family:var(--mono);">Connecting…</span></div>';

    try {
        const fd = new FormData();
        fd.append('csrf', CSRF_WL);
        fd.append('action', 'check_now');
        fd.append('watchlist_id', wid);

        const res  = await fetch('/tools/watchlist.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.error) {
            resultEl.innerHTML = `<div class="tool-error">${data.error}</div>`;
            return;
        }

        let html = '<div style="display:flex;gap:20px;flex-wrap:wrap;">';

        // SSL result
        if (data.ssl) {
            const s   = data.ssl;
            const cls = s.days <= 0 ? 'risk-dangerous' : s.days <= 14 ? 'risk-malicious' : s.days <= 30 ? 'risk-suspicious' : 'risk-clean';
            const lbl = s.days <= 0 ? 'EXPIRED' : s.days + ' days left';
            html += `<div>
                <div style="font-family:var(--mono);font-size:10px;color:var(--text3);letter-spacing:1px;text-transform:uppercase;margin-bottom:5px;">SSL Certificate</div>
                <span class="risk-badge ${cls}">${lbl}</span>
                <div style="font-size:12px;color:var(--text3);margin-top:4px;">Expires ${s.expiry_date} &middot; ${s.issuer || 'Unknown issuer'}</div>
            </div>`;
        } else if (data.ssl === null) {
            html += `<div>
                <div style="font-family:var(--mono);font-size:10px;color:var(--text3);letter-spacing:1px;text-transform:uppercase;margin-bottom:5px;">SSL</div>
                <span style="font-size:13px;color:#ff4d6d;">Could not connect on port 443</span>
            </div>`;
        }

        // DNS result
        if (data.dns) {
            const types = Object.keys(data.dns);
            html += `<div>
                <div style="font-family:var(--mono);font-size:10px;color:var(--text3);letter-spacing:1px;text-transform:uppercase;margin-bottom:5px;">DNS Records (${types.join(', ')})</div>
                <span class="risk-badge risk-clean">Snapshot saved</span>
            </div>`;
        }

        // Alerts triggered
        if (data.alerts && data.alerts.length > 0) {
            html += `<div>
                <div style="font-family:var(--mono);font-size:10px;color:#ff4d6d;letter-spacing:1px;text-transform:uppercase;margin-bottom:5px;">&#9888; Alerts Created</div>
                <div style="font-size:13px;color:#ff4d6d;">${data.alerts.join(', ')}</div>
            </div>`;
        } else {
            html += `<div style="align-self:flex-end;font-size:13px;color:#00d4aa;">&#10003; All clear</div>`;
        }

        html += '</div>';
        resultEl.innerHTML = html;

        // Reload the page after 3s to show updated values
        setTimeout(() => location.reload(), 3000);
    } catch (e) {
        resultEl.innerHTML = `<div class="tool-error">Network error: ${e.message}</div>`;
    } finally {
        btn.disabled  = false;
        btn.innerHTML = origText;
    }
}

async function dismissAlert(alertId) {
    const el = document.getElementById('alert-' + alertId);
    const fd = new FormData();
    fd.append('csrf', CSRF_WL);
    fd.append('action', 'dismiss');
    fd.append('alert_id', alertId);
    await fetch('/tools/watchlist.php', { method: 'POST', body: fd });
    if (el) el.remove();
}
</script>

<style>
.wl-domain-card  { transition: opacity 0.2s; }
.wl-paused       { opacity: 0.55; }
.wl-alert-row    { padding: 10px 0; border-bottom: 1px solid rgba(255,77,109,0.1); }
.wl-alert-row:last-child { border-bottom: none; }
</style>

</body>
</html>
