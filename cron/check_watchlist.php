<?php
/**
 * check_watchlist.php — Hourly SSL + DNS monitor cron
 *
 * Usage:
 *   php /path/to/frontend/cron/check_watchlist.php --token=YOUR_TOKEN
 *
 * Crontab entry:
 *   0 * * * * php /home/frank-dela/hakdel/frontend/cron/check_watchlist.php \
 *             --token=073b25da929174404f8069cbe53bf67b >> /tmp/hakdel-watchlist.log 2>&1
 */

// ── Auth ──────────────────────────────────────────────────────────────────────
$opts  = getopt('', ['token:']);
$token = $opts['token'] ?? ($_GET['token'] ?? '');

require_once __DIR__ . '/../config/app.php';

$expected = getenv('WATCHLIST_CRON_TOKEN') ?: '';
if (!$expected || !hash_equals($expected, $token)) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Invalid or missing token.\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Watchlist check started.\n";

// ── Helpers ───────────────────────────────────────────────────────────────────
function cron_check_ssl(string $domain): ?array {
    $ctx = stream_context_create(['ssl' => [
        'capture_peer_cert' => true,
        'verify_peer'       => false,
        'verify_peer_name'  => false,
    ]]);
    $sock = @stream_socket_client("ssl://{$domain}:443", $errno, $errstr, 10,
        STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) return null;
    $params = stream_context_get_params($sock);
    fclose($sock);
    $cert = $params['options']['ssl']['peer_certificate'] ?? null;
    if (!$cert) return null;
    $info = openssl_x509_parse($cert);
    $ts   = $info['validTo_time_t'] ?? null;
    if (!$ts) return null;
    return [
        'expiry_ts'   => $ts,
        'expiry_date' => date('Y-m-d', $ts),
        'days'        => (int)ceil(($ts - time()) / 86400),
        'issuer'      => $info['issuer']['O'] ?? '',
        'subject'     => $info['subject']['CN'] ?? $domain,
    ];
}

function cron_check_dns(string $domain): array {
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

function cron_dns_diff(array $old, array $new): array {
    $changes = [];
    foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $t) {
        $o = array_unique($old[$t] ?? []); sort($o);
        $n = array_unique($new[$t] ?? []); sort($n);
        if ($o !== $n) {
            $added   = array_diff($n, $o);
            $removed = array_diff($o, $n);
            if ($added)   $changes[] = "$t added: "   . implode(', ', $added);
            if ($removed) $changes[] = "$t removed: " . implode(', ', $removed);
        }
    }
    return $changes;
}

// ── Main loop ─────────────────────────────────────────────────────────────────
$pdo   = db();
$entries = $pdo->query(
    "SELECT * FROM watchlist WHERE is_active = 1
     AND (ssl_last_checked IS NULL OR ssl_last_checked < DATE_SUB(NOW(), INTERVAL 1 HOUR)
          OR dns_last_checked IS NULL OR dns_last_checked < DATE_SUB(NOW(), INTERVAL 1 HOUR))"
)->fetchAll();

echo "[" . date('Y-m-d H:i:s') . "] Found " . count($entries) . " domain(s) to check.\n";

foreach ($entries as $e) {
    $domain = $e['domain'];
    echo "[" . date('H:i:s') . "] Checking {$domain}...\n";

    // ── SSL ───────────────────────────────────────────────────────────────────
    if ($e['check_ssl']) {
        $ssl = cron_check_ssl($domain);
        if ($ssl) {
            $pdo->prepare('UPDATE watchlist SET ssl_expiry_days=?, ssl_last_checked=NOW() WHERE id=?')
                ->execute([$ssl['days'], $e['id']]);

            if ($ssl['days'] <= 0) {
                // Only alert once — check if unread alert exists
                $exists = $pdo->prepare("SELECT id FROM watchlist_alerts WHERE watchlist_id=? AND alert_type='ssl_expired' AND is_read=0");
                $exists->execute([$e['id']]);
                if (!$exists->fetch()) {
                    $pdo->prepare("INSERT INTO watchlist_alerts (watchlist_id,alert_type,message) VALUES (?,?,?)")
                        ->execute([$e['id'], 'ssl_expired', "SSL certificate for {$domain} has EXPIRED!"]);
                    echo "  [!] SSL EXPIRED for {$domain}\n";
                    send_watchlist_email($e, "SSL EXPIRED: {$domain}", "The SSL certificate for {$domain} has expired. Renew immediately.");
                }
            } elseif ($ssl['days'] <= 30) {
                $exists = $pdo->prepare("SELECT id FROM watchlist_alerts WHERE watchlist_id=? AND alert_type='ssl_expiry' AND is_read=0 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                $exists->execute([$e['id']]);
                if (!$exists->fetch()) {
                    $pdo->prepare("INSERT INTO watchlist_alerts (watchlist_id,alert_type,message) VALUES (?,?,?)")
                        ->execute([$e['id'], 'ssl_expiry', "SSL for {$domain} expires in {$ssl['days']} days ({$ssl['expiry_date']})."]);
                    echo "  [!] SSL expiring in {$ssl['days']} days for {$domain}\n";
                    if ($ssl['days'] <= 14) {
                        send_watchlist_email($e, "SSL Expiring Soon: {$domain}", "SSL for {$domain} expires in {$ssl['days']} days ({$ssl['expiry_date']}). Renew soon.");
                    }
                }
            } else {
                echo "  [ok] SSL valid, {$ssl['days']} days remaining.\n";
            }
        } else {
            echo "  [warn] Could not reach {$domain}:443\n";
        }
    }

    // ── DNS ───────────────────────────────────────────────────────────────────
    if ($e['check_dns']) {
        $new_snap = cron_check_dns($domain);
        $old_snap = json_decode($e['dns_snapshot'] ?? '{}', true) ?: [];
        $changes  = $old_snap ? cron_dns_diff($old_snap, $new_snap) : [];

        $pdo->prepare('UPDATE watchlist SET dns_snapshot=?, dns_last_checked=NOW() WHERE id=?')
            ->execute([json_encode($new_snap), $e['id']]);

        if ($changes) {
            $msg = "DNS changed for {$domain}: " . implode('; ', $changes);
            $pdo->prepare("INSERT INTO watchlist_alerts (watchlist_id,alert_type,message) VALUES (?,?,?)")
                ->execute([$e['id'], 'dns_change', $msg]);
            echo "  [!] DNS CHANGED: " . implode('; ', $changes) . "\n";
            send_watchlist_email($e, "DNS Change Detected: {$domain}", $msg);
        } else {
            echo "  [ok] DNS unchanged.\n";
        }
    }
}

// ── Send alert email helper ───────────────────────────────────────────────────
function send_watchlist_email(array $entry, string $subject, string $body): void {
    if (empty($entry['alert_email'])) return;
    try {
        require_once __DIR__ . '/../config/mail.php';
        $site = defined('SITE_URL') ? SITE_URL : 'https://hakdel.local';
        $html = "<div style='font-family:monospace;background:#0a0a0a;color:#e0e0e0;padding:24px;'>"
              . "<h2 style='color:#00d4aa;'>HakDel Watchlist Alert</h2>"
              . "<p style='color:#888;'>" . htmlspecialchars($body) . "</p>"
              . "<p style='margin-top:20px;'><a href='{$site}/tools/watchlist.php' "
              . "style='background:#00d4aa;color:#000;padding:10px 20px;text-decoration:none;font-weight:bold;border-radius:4px;'>View Watchlist</a></p>"
              . "</div>";
        send_mail($entry['alert_email'], "[HakDel] $subject", $body, $html);
        echo "  [mail] Alert sent to {$entry['alert_email']}\n";
    } catch (Exception $e) {
        echo "  [mail error] " . $e->getMessage() . "\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Done.\n";
