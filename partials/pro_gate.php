<?php
/**
 * Pro gate overlay — include at the top of any Pro-only page.
 *
 * Usage:
 *   $user = require_login();
 *   require __DIR__ . '/../partials/pro_gate.php'; // echoes overlay if not pro, dies if hard mode
 *
 * Variables:
 *   $gate_feature (string) — name of the feature being gated, e.g. "IP Checker"
 *   $gate_hard    (bool)   — if true, show full-page block and exit (default: false = overlay only)
 */

$gate_feature ??= 'this feature';
$gate_hard    ??= false;

if (is_pro($user)) return; // nothing to do

if ($gate_hard):
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Pro Required — HakDel</title>
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="stylesheet" href="/assets/layout.css">
</head>
<body>
<?php require __DIR__ . '/topbar.php'; ?>
<div class="hk-shell">
<?php require __DIR__ . '/sidebar.php'; ?>
<main class="hk-main" style="display:flex;align-items:center;justify-content:center;min-height:60vh">
  <div style="text-align:center;max-width:400px">
    <div style="font-size:40px;margin-bottom:16px">&#128274;</div>
    <div style="font-family:var(--mono);font-size:20px;font-weight:700;color:var(--text);margin-bottom:8px">
      Pro Required
    </div>
    <p style="font-size:14px;color:var(--text2);line-height:1.6;margin-bottom:24px">
      <strong style="color:var(--text)"><?= h($gate_feature) ?></strong> is available on the Pro plan.
      Upgrade to unlock all tools, unlimited scans, and more.
    </p>
    <a href="/upgrade/" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:var(--bg);border-radius:var(--radius);font-family:var(--mono);font-size:14px;font-weight:700;text-decoration:none;letter-spacing:1px">
      Upgrade to Pro &rarr;
    </a>
    <div style="margin-top:14px">
      <a href="/dashboard/" style="font-size:13px;color:var(--text3);text-decoration:none">Back to dashboard</a>
    </div>
  </div>
</main>
</div>
</body>
</html>
<?php
exit;
endif;

// Soft overlay — just output the HTML, caller decides when to show it
?>
<div id="pro-gate-overlay" style="
  position:fixed;inset:0;z-index:500;
  background:rgba(10,12,15,0.82);backdrop-filter:blur(6px);
  display:flex;align-items:center;justify-content:center;
">
  <div style="
    background:var(--bg2);border:1px solid var(--accent);
    border-radius:var(--radius-lg);padding:36px 40px;
    text-align:center;max-width:420px;width:90%;
    box-shadow:0 16px 48px rgba(0,0,0,0.6);
  ">
    <div style="font-size:36px;margin-bottom:14px">&#9651;</div>
    <div style="font-family:var(--mono);font-size:18px;font-weight:700;color:var(--accent);margin-bottom:8px">
      Pro Feature
    </div>
    <p style="font-size:14px;color:var(--text2);line-height:1.65;margin-bottom:24px">
      <strong style="color:var(--text)"><?= h($gate_feature) ?></strong> is available on the
      <strong style="color:var(--accent)">Pro plan</strong>. Upgrade to unlock all tools,
      unlimited scans, OSINT suite, watchlist monitoring, and more.
    </p>
    <a href="/upgrade/" style="
      display:block;padding:13px;margin-bottom:10px;
      background:linear-gradient(135deg,var(--accent),var(--accent2));
      color:var(--bg);border-radius:var(--radius);
      font-family:var(--mono);font-size:14px;font-weight:700;
      text-decoration:none;letter-spacing:1px;
    ">
      Upgrade to Pro &rarr;
    </a>
    <a href="/upgrade/" style="font-size:12px;color:var(--text3);text-decoration:none">
      View pricing
    </a>
  </div>
</div>
