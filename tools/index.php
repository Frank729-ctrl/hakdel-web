<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = 'tools';
$sidebar_sub  = 'Security Tools';
$topbar_title = 'Tools';
$user_is_pro  = is_pro($user);

// Pull quick stats for this user
$pdo = db();
$uid = (int)$user['id'];

$s = $pdo->prepare('SELECT COUNT(*) FROM ip_checks   WHERE user_id = ?'); $s->execute([$uid]); $ip_count   = (int)$s->fetchColumn();
$s = $pdo->prepare('SELECT COUNT(*) FROM hash_checks WHERE user_id = ?'); $s->execute([$uid]); $hash_count = (int)$s->fetchColumn();
$s = $pdo->prepare('SELECT COUNT(*) FROM cve_lookups  WHERE user_id = ?'); $s->execute([$uid]); $cve_count  = (int)$s->fetchColumn();
$s = $pdo->prepare('SELECT COUNT(*) FROM watchlist    WHERE user_id = ? AND is_active = 1'); $s->execute([$uid]); $wl_count = (int)$s->fetchColumn();
$s = $pdo->prepare('SELECT COUNT(*) FROM watchlist_alerts wa JOIN watchlist w ON w.id = wa.watchlist_id WHERE w.user_id = ? AND wa.is_read = 0'); $s->execute([$uid]); $wl_alerts = (int)$s->fetchColumn();

// OSINT stats (tables created lazily)
$domain_count = $header_count = $url_count = $email_count = 0;
try { $s = $pdo->prepare('SELECT COUNT(*) FROM domain_lookups WHERE user_id = ?'); $s->execute([$uid]); $domain_count = (int)$s->fetchColumn(); } catch(Exception $e) {}
try { $s = $pdo->prepare('SELECT COUNT(*) FROM header_checks  WHERE user_id = ?'); $s->execute([$uid]); $header_count = (int)$s->fetchColumn(); } catch(Exception $e) {}
try { $s = $pdo->prepare('SELECT COUNT(*) FROM url_checks     WHERE user_id = ?'); $s->execute([$uid]); $url_count    = (int)$s->fetchColumn(); } catch(Exception $e) {}
try { $s = $pdo->prepare('SELECT COUNT(*) FROM email_checks   WHERE user_id = ?'); $s->execute([$uid]); $email_count  = (int)$s->fetchColumn(); } catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Tools — HakDel</title>
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="stylesheet" href="/assets/layout.css">
  <style>
    .tools-hub-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 16px;
    }
    .tool-hub-card {
      background: var(--bg2);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 24px;
      text-decoration: none;
      display: flex; flex-direction: column; gap: 12px;
      transition: border-color 0.15s, background 0.15s;
      position: relative; overflow: hidden;
    }
    .tool-hub-card:hover {
      border-color: var(--accent);
      background: rgba(0,212,170,0.03);
    }
    .tool-hub-card::before {
      content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
      background: linear-gradient(90deg, var(--accent), var(--accent2));
      opacity: 0; transition: opacity 0.15s;
    }
    .tool-hub-card:hover::before { opacity: 1; }
    .tool-hub-icon {
      font-size: 28px; line-height: 1;
    }
    .tool-hub-name {
      font-family: var(--mono); font-size: 16px; font-weight: 700;
      color: var(--text);
    }
    .tool-hub-desc {
      font-size: 13px; color: var(--text2); line-height: 1.5;
    }
    .tool-hub-meta {
      display: flex; align-items: center; justify-content: space-between;
      margin-top: auto; padding-top: 12px;
      border-top: 1px solid var(--border);
    }
    .tool-hub-stat {
      font-family: var(--mono); font-size: 11px; color: var(--text3);
    }
    .tool-hub-stat span { color: var(--accent); font-size: 14px; font-weight: 700; }
    .tool-hub-arrow {
      font-size: 16px; color: var(--text3); transition: color 0.15s, transform 0.15s;
    }
    .tool-hub-card:hover .tool-hub-arrow { color: var(--accent); transform: translateX(3px); }
    .tool-hub-alert {
      position: absolute; top: 14px; right: 14px;
      background: var(--danger); color: #fff;
      font-family: var(--mono); font-size: 10px; font-weight: 700;
      padding: 2px 7px; border-radius: 10px;
    }
    .tool-hub-lock {
      position: absolute; top: 12px; right: 12px;
      background: rgba(0,0,0,0.5); color: var(--text3);
      font-size: 13px; width: 24px; height: 24px;
      border-radius: 50%; display: flex; align-items: center; justify-content: center;
      border: 1px solid var(--border);
    }
    .tool-hub-card.locked {
      opacity: 0.65;
    }
    .tool-hub-card.locked:hover {
      border-color: var(--accent);
      opacity: 1;
    }
    .tool-hub-card.locked .tool-hub-arrow::before {
      content: '&#128274; ';
    }
    .tools-hub-summary {
      display: flex; gap: 16px; flex-wrap: wrap;
    }
    .hub-summary-stat {
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 14px 20px;
      font-family: var(--mono); font-size: 12px; color: var(--text3);
      display: flex; flex-direction: column; gap: 4px;
    }
    .hub-summary-stat strong {
      font-size: 22px; color: var(--accent); font-weight: 700;
    }
  </style>
</head>
<body>
<?php require __DIR__ . '/../partials/topbar.php'; ?>
<div class="hk-shell">
<?php require __DIR__ . '/../partials/sidebar.php'; ?>
<main class="hk-main">

  <div class="hk-page-header">
    <div>
      <div class="hk-page-eyebrow">SECURITY</div>
      <h1 class="hk-page-title">Tools</h1>
      <p class="hk-page-sub">Investigate IPs, hashes, vulnerabilities and monitor your domains</p>
    </div>
  </div>

  <!-- Summary stats -->
  <div class="tools-hub-summary">
    <div class="hub-summary-stat">
      <strong><?php echo $ip_count; ?></strong>
      IP Lookups
    </div>
    <div class="hub-summary-stat">
      <strong><?php echo $hash_count; ?></strong>
      Hash Checks
    </div>
    <div class="hub-summary-stat">
      <strong><?php echo $cve_count; ?></strong>
      CVE Lookups
    </div>
    <div class="hub-summary-stat">
      <strong><?php echo $wl_count; ?></strong>
      Watched Domains
    </div>
  </div>

  <!-- Tool cards -->
  <div class="tools-hub-grid">

    <a href="/tools/ip_check.php" class="tool-hub-card<?= $user_is_pro ? '' : ' locked' ?>">
      <?php if (!$user_is_pro): ?><div class="tool-hub-lock">&#128274;</div><?php endif; ?>
      <div class="tool-hub-icon">&#127760;</div>
      <div class="tool-hub-name">IP Checker</div>
      <div class="tool-hub-desc">
        Analyse any IP address for abuse reports, malware associations and open ports
        using AbuseIPDB, VirusTotal and Shodan.
      </div>
      <div class="tool-hub-meta">
        <div class="tool-hub-stat"><span><?php echo $ip_count; ?></span> lookups</div>
        <div class="tool-hub-arrow">&#8594;</div>
      </div>
    </a>

    <a href="/tools/hash_check.php" class="tool-hub-card<?= $user_is_pro ? '' : ' locked' ?>">
      <?php if (!$user_is_pro): ?><div class="tool-hub-lock">&#128274;</div><?php endif; ?>
      <div class="tool-hub-icon">&#128273;</div>
      <div class="tool-hub-name">Hash Lookup</div>
      <div class="tool-hub-desc">
        Check MD5, SHA-1 or SHA-256 file hashes against VirusTotal and MalwareBazaar
        to identify known malware.
      </div>
      <div class="tool-hub-meta">
        <div class="tool-hub-stat"><span><?php echo $hash_count; ?></span> checks</div>
        <div class="tool-hub-arrow">&#8594;</div>
      </div>
    </a>

    <a href="/tools/cve_check.php" class="tool-hub-card<?= $user_is_pro ? '' : ' locked' ?>">
      <?php if (!$user_is_pro): ?><div class="tool-hub-lock">&#128274;</div><?php endif; ?>
      <div class="tool-hub-icon">&#9888;</div>
      <div class="tool-hub-name">CVE Lookup</div>
      <div class="tool-hub-desc">
        Search the NVD database for CVE details, CVSS scores, affected products
        and known exploits from ExploitDB.
      </div>
      <div class="tool-hub-meta">
        <div class="tool-hub-stat"><span><?php echo $cve_count; ?></span> lookups</div>
        <div class="tool-hub-arrow">&#8594;</div>
      </div>
    </a>

    <a href="/tools/watchlist.php" class="tool-hub-card<?= $user_is_pro ? '' : ' locked' ?>">
      <?php if (!$user_is_pro): ?>
      <div class="tool-hub-lock">&#128274;</div>
      <?php elseif ($wl_alerts > 0): ?>
      <div class="tool-hub-alert"><?php echo $wl_alerts; ?> alert<?php echo $wl_alerts !== 1 ? 's' : ''; ?></div>
      <?php endif; ?>
      <div class="tool-hub-icon">&#128204;</div>
      <div class="tool-hub-name">Watchlist</div>
      <div class="tool-hub-desc">
        Monitor domains for SSL certificate expiry and DNS changes.
        Get email alerts before things break.
      </div>
      <div class="tool-hub-meta">
        <div class="tool-hub-stat"><span><?php echo $wl_count; ?></span> domain<?php echo $wl_count !== 1 ? 's' : ''; ?> monitored</div>
        <div class="tool-hub-arrow">&#8594;</div>
      </div>
    </a>

    <a href="/tools/port_scan.php" class="tool-hub-card<?= $user_is_pro ? '' : ' locked' ?>">
      <?php if (!$user_is_pro): ?><div class="tool-hub-lock">&#128274;</div><?php endif; ?>
      <div class="tool-hub-icon">&#128268;</div>
      <div class="tool-hub-name">Port Scanner</div>
      <div class="tool-hub-desc">
        Scan any IP or hostname for open ports and running services using direct socket checks
        or HackerTarget's nmap API.
      </div>
      <div class="tool-hub-meta">
        <div class="tool-hub-stat"><span>&#128268;</span> socket scan</div>
        <div class="tool-hub-arrow">&#8594;</div>
      </div>
    </a>

    <a href="/tools/network.php" class="tool-hub-card<?= $user_is_pro ? '' : ' locked' ?>">
      <?php if (!$user_is_pro): ?><div class="tool-hub-lock">&#128274;</div><?php endif; ?>
      <div class="tool-hub-icon">&#127756;</div>
      <div class="tool-hub-name">Network Tools</div>
      <div class="tool-hub-desc">
        DNS records lookup, host resolution, WHOIS queries, and HTTP response header inspection
        — all in one place.
      </div>
      <div class="tool-hub-meta">
        <div class="tool-hub-stat"><span>4</span> tools</div>
        <div class="tool-hub-arrow">&#8594;</div>
      </div>
    </a>

  </div>

  <!-- OSINT Tools -->
  <div style="margin-top:8px">
    <div class="hk-page-eyebrow" style="margin-bottom:12px">OSINT</div>
    <div class="tools-hub-grid">

      <a href="/tools/domain.php" class="tool-hub-card<?= $user_is_pro ? '' : ' locked' ?>">
        <?php if (!$user_is_pro): ?><div class="tool-hub-lock">&#128274;</div><?php endif; ?>
        <div class="tool-hub-icon">&#127760;</div>
        <div class="tool-hub-name">Domain Intel</div>
        <div class="tool-hub-desc">WHOIS, DNS records, subdomains via certificate transparency, reverse IP and reputation.</div>
        <div class="tool-hub-meta">
          <div class="tool-hub-stat"><span><?php echo $domain_count; ?></span> lookups</div>
          <div class="tool-hub-arrow">&#8594;</div>
        </div>
      </a>

      <a href="/tools/headers.php" class="tool-hub-card<?= $user_is_pro ? '' : ' locked' ?>">
        <?php if (!$user_is_pro): ?><div class="tool-hub-lock">&#128274;</div><?php endif; ?>
        <div class="tool-hub-icon">&#128737;</div>
        <div class="tool-hub-name">Headers Analyser</div>
        <div class="tool-hub-desc">Grade any site's security headers — CSP, HSTS, X-Frame-Options, Referrer-Policy and more.</div>
        <div class="tool-hub-meta">
          <div class="tool-hub-stat"><span><?php echo $header_count; ?></span> checks</div>
          <div class="tool-hub-arrow">&#8594;</div>
        </div>
      </a>

      <a href="/tools/url_check.php" class="tool-hub-card<?= $user_is_pro ? '' : ' locked' ?>">
        <?php if (!$user_is_pro): ?><div class="tool-hub-lock">&#128274;</div><?php endif; ?>
        <div class="tool-hub-icon">&#128279;</div>
        <div class="tool-hub-name">URL / Phishing</div>
        <div class="tool-hub-desc">Check any URL against VirusTotal, PhishTank and Google Safe Browsing in one shot.</div>
        <div class="tool-hub-meta">
          <div class="tool-hub-stat"><span><?php echo $url_count; ?></span> checks</div>
          <div class="tool-hub-arrow">&#8594;</div>
        </div>
      </a>

      <a href="/tools/email_check.php" class="tool-hub-card<?= $user_is_pro ? '' : ' locked' ?>">
        <?php if (!$user_is_pro): ?><div class="tool-hub-lock">&#128274;</div><?php endif; ?>
        <div class="tool-hub-icon">&#9993;</div>
        <div class="tool-hub-name">Email Investigator</div>
        <div class="tool-hub-desc">Validate SPF, DMARC and DKIM records, check MX reachability and detect disposable addresses.</div>
        <div class="tool-hub-meta">
          <div class="tool-hub-stat"><span><?php echo $email_count; ?></span> checks</div>
          <div class="tool-hub-arrow">&#8594;</div>
        </div>
      </a>

    </div>
  </div>

</main>
</div>
</body>
</html>
