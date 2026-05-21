<?php
require_once __DIR__ . '/../config/app.php';

$user     = require_login();
$level    = xp_to_level((int)$user['xp']);
$xp_data  = xp_progress((int)$user['xp']);
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));

$nav_active   = '';
$topbar_title = 'Upgrade';

_ensure_plan_columns();
$already_pro = is_pro($user);
$success_msg = get_flash('success');
$error_msg   = get_flash('error');

// Detect trial: pro with an expiry date set and no payment on record
$is_trial    = false;
$trial_days_left = 0;
if ($already_pro && !empty($user['plan_expires_at'])) {
    $paid = false;
    try {
        $s = db()->prepare('SELECT COUNT(*) FROM payments WHERE user_id = ? AND status = ?');
        $s->execute([$user['id'], 'success']);
        $paid = (int)$s->fetchColumn() > 0;
    } catch (Exception $e) {}
    if (!$paid) {
        $is_trial        = true;
        $trial_days_left = max(0, (int)ceil((strtotime($user['plan_expires_at']) - time()) / 86400));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Upgrade to Pro — HakDel</title>
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="stylesheet" href="/assets/layout.css">
  <style>
    .upgrade-wrap {
      max-width: 860px; margin: 0 auto;
    }
    .plan-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 20px;
      margin-top: 8px;
    }
    .plan-card {
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: var(--radius-lg); padding: 32px 28px;
      display: flex; flex-direction: column; gap: 20px;
      position: relative; overflow: hidden;
    }
    .plan-card.plan-pro {
      border-color: var(--accent);
      background: linear-gradient(160deg, rgba(0,212,170,0.06) 0%, var(--bg2) 60%);
    }
    .plan-card.plan-pro::before {
      content: 'MOST POPULAR';
      position: absolute; top: 16px; right: -24px;
      background: var(--accent); color: var(--bg);
      font-family: var(--mono); font-size: 9px; font-weight: 700;
      letter-spacing: 2px; padding: 4px 36px;
      transform: rotate(35deg);
    }
    .plan-name {
      font-family: var(--mono); font-size: 13px; letter-spacing: 2px;
      text-transform: uppercase; color: var(--text3);
    }
    .plan-price {
      display: flex; align-items: baseline; gap: 6px;
    }
    .plan-amount {
      font-family: var(--mono); font-size: 42px; font-weight: 700;
      color: var(--text); line-height: 1;
    }
    .plan-pro .plan-amount { color: var(--accent); }
    .plan-period { font-size: 14px; color: var(--text3); }
    .plan-desc { font-size: 13px; color: var(--text2); line-height: 1.6; }
    .plan-features { display: flex; flex-direction: column; gap: 10px; flex: 1; }
    .plan-feature {
      display: flex; align-items: center; gap: 10px;
      font-size: 13px; color: var(--text2);
    }
    .plan-feature .feat-icon { font-size: 13px; flex-shrink: 0; }
    .feat-yes  { color: var(--accent); }
    .feat-no   { color: var(--text3); }
    .feat-dim  { color: var(--text3); text-decoration: line-through; }
    .plan-cta {
      display: block; width: 100%; padding: 13px;
      text-align: center; border-radius: var(--radius);
      font-family: var(--mono); font-size: 14px; font-weight: 700;
      letter-spacing: 1px; cursor: pointer; border: none;
      transition: opacity 0.15s, transform 0.1s; text-decoration: none;
    }
    .plan-cta:hover:not(:disabled) { opacity: 0.88; }
    .plan-cta:active { transform: scale(0.97); }
    .plan-cta.cta-free {
      background: transparent; border: 1px solid var(--border2);
      color: var(--text2);
    }
    .plan-cta.cta-pro {
      background: linear-gradient(135deg, var(--accent), var(--accent2));
      color: var(--bg);
    }
    .plan-cta.cta-current {
      background: rgba(0,212,170,0.08); border: 1px solid var(--accent);
      color: var(--accent); cursor: default;
    }
    .plan-divider { border-top: 1px solid var(--border); margin: 4px 0; }
    .billing-toggle {
      display: flex; align-items: center; justify-content: center; gap: 14px;
      margin-bottom: 4px;
    }
    .billing-opt {
      font-family: var(--mono); font-size: 12px; color: var(--text3);
      cursor: pointer; padding: 6px 14px; border-radius: 20px;
      transition: all 0.12s;
    }
    .billing-opt.active {
      color: var(--text); background: var(--bg3);
      border: 1px solid var(--border2);
    }
    .save-badge {
      background: rgba(0,212,170,0.12); color: var(--accent);
      font-family: var(--mono); font-size: 10px; font-weight: 700;
      padding: 2px 8px; border-radius: 10px; border: 1px solid rgba(0,212,170,0.2);
    }
    .faq-section { margin-top: 8px; }
    .faq-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px; }
    .faq-item {
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 18px 20px;
    }
    .faq-q { font-family: var(--mono); font-size: 13px; color: var(--text); margin-bottom: 8px; }
    .faq-a { font-size: 13px; color: var(--text2); line-height: 1.6; }
    @media (max-width: 700px) {
      .plan-grid { grid-template-columns: 1fr; }
      .faq-grid  { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<?php require __DIR__ . '/../partials/topbar.php'; ?>
<div class="hk-shell">
<?php require __DIR__ . '/../partials/sidebar.php'; ?>
<main class="hk-main">
<div class="upgrade-wrap">

  <div class="hk-page-header">
    <div>
      <div class="hk-page-eyebrow">SUBSCRIPTION</div>
      <h1 class="hk-page-title">Upgrade to Pro</h1>
      <p class="hk-page-sub">Unlock every tool, scanner, and lab on the platform.</p>
    </div>
  </div>

  <?php if ($success_msg): ?>
  <div style="background:rgba(0,212,170,0.08);border:1px solid rgba(0,212,170,0.25);border-radius:var(--radius);padding:14px 18px;font-size:14px;color:var(--accent)">
    &#10003; <?= h($success_msg) ?>
  </div>
  <?php endif; ?>

  <?php if ($error_msg): ?>
  <div style="background:rgba(255,77,77,0.08);border:1px solid rgba(255,77,77,0.25);border-radius:var(--radius);padding:14px 18px;font-size:14px;color:var(--danger)">
    <?= h($error_msg) ?>
  </div>
  <?php endif; ?>

  <?php if ($already_pro && $is_trial): ?>
  <div style="background:rgba(255,170,0,0.06);border:1px solid rgba(255,170,0,0.25);border-radius:var(--radius-lg);padding:22px 28px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
    <div style="font-size:26px">&#9651;</div>
    <div style="flex:1;min-width:0">
      <div style="font-family:var(--mono);font-size:14px;color:var(--warn,#ffaa00);font-weight:700">
        Free Trial Active &mdash; <?= $trial_days_left ?> day<?= $trial_days_left !== 1 ? 's' : '' ?> remaining
      </div>
      <div style="font-size:13px;color:var(--text2);margin-top:4px">
        Your trial expires on <strong style="color:var(--text)"><?= date('M j, Y', strtotime($user['plan_expires_at'])) ?></strong>.
        Subscribe below to keep uninterrupted access.
      </div>
    </div>
  </div>
  <?php elseif ($already_pro): ?>
  <div style="background:rgba(0,212,170,0.06);border:1px solid rgba(0,212,170,0.2);border-radius:var(--radius-lg);padding:24px 28px;display:flex;align-items:center;gap:16px">
    <div style="font-size:28px">&#9651;</div>
    <div>
      <div style="font-family:var(--mono);font-size:15px;color:var(--accent);font-weight:700">You're on Pro</div>
      <div style="font-size:13px;color:var(--text2);margin-top:4px">
        <?php if (!empty($user['plan_expires_at'])): ?>
          Active until <strong style="color:var(--text)"><?= date('M j, Y', strtotime($user['plan_expires_at'])) ?></strong>
        <?php else: ?>
          Lifetime access — all features unlocked.
        <?php endif; ?>
      </div>
    </div>
    <a href="/settings/" style="margin-left:auto;font-family:var(--mono);font-size:12px;color:var(--text3);text-decoration:none">Manage &rarr;</a>
  </div>
  <?php endif; ?>

  <!-- Billing toggle -->
  <div class="billing-toggle">
    <span class="billing-opt active" id="bill-monthly" onclick="setBilling('monthly')">Monthly</span>
    <span class="billing-opt" id="bill-annual" onclick="setBilling('annual')">
      Annual <span class="save-badge">SAVE 30%</span>
    </span>
  </div>

  <!-- Plan cards -->
  <div class="plan-grid">

    <!-- Free -->
    <div class="plan-card">
      <div>
        <div class="plan-name">Free</div>
        <div class="plan-price">
          <div class="plan-amount">$0</div>
          <div class="plan-period">/ forever</div>
        </div>
      </div>
      <div class="plan-desc">Everything you need to learn the basics and explore the platform.</div>
      <div class="plan-features">
        <div class="plan-feature"><span class="feat-icon feat-yes">&#10003;</span> CEH Quiz (unlimited)</div>
        <div class="plan-feature"><span class="feat-icon feat-yes">&#10003;</span> Leaderboard &amp; XP system</div>
        <div class="plan-feature"><span class="feat-icon feat-yes">&#10003;</span> Dashboard &amp; profile</div>
        <div class="plan-feature"><span class="feat-icon feat-yes">&#10003;</span> Site scanner (3 scans / day)</div>
        <div class="plan-feature"><span class="feat-icon feat-yes">&#10003;</span> First 2 free labs</div>
        <div class="plan-divider"></div>
        <div class="plan-feature"><span class="feat-icon feat-no">&#10005;</span> <span class="feat-dim">Security tools suite</span></div>
        <div class="plan-feature"><span class="feat-icon feat-no">&#10005;</span> <span class="feat-dim">OSINT suite</span></div>
        <div class="plan-feature"><span class="feat-icon feat-no">&#10005;</span> <span class="feat-dim">Watchlist monitoring</span></div>
        <div class="plan-feature"><span class="feat-icon feat-no">&#10005;</span> <span class="feat-dim">Incident tracker</span></div>
        <div class="plan-feature"><span class="feat-icon feat-no">&#10005;</span> <span class="feat-dim">PDF reports</span></div>
        <div class="plan-feature"><span class="feat-icon feat-no">&#10005;</span> <span class="feat-dim">Unlimited scans</span></div>
      </div>
      <?php if ($already_pro): ?>
      <span class="plan-cta cta-free" style="cursor:default">Current plan</span>
      <?php else: ?>
      <span class="plan-cta cta-current">Your current plan</span>
      <?php endif; ?>
    </div>

    <!-- Pro -->
    <div class="plan-card plan-pro">
      <div>
        <div class="plan-name">Pro</div>
        <div class="plan-price">
          <div class="plan-amount" id="pro-price">$9</div>
          <div class="plan-period" id="pro-period">/ month</div>
        </div>
      </div>
      <div class="plan-desc">Full access to every tool, scanner run, lab, and feature on HakDel.</div>
      <div class="plan-features">
        <div class="plan-feature"><span class="feat-icon feat-yes">&#10003;</span> Everything in Free</div>
        <div class="plan-feature"><span class="feat-icon feat-yes">&#10003;</span> <strong>Unlimited</strong> scanner runs</div>
        <div class="plan-feature"><span class="feat-icon feat-yes">&#10003;</span> IP Checker, Hash Lookup, CVE Search</div>
        <div class="plan-feature"><span class="feat-icon feat-yes">&#10003;</span> Port Scanner &amp; Network Tools</div>
        <div class="plan-feature"><span class="feat-icon feat-yes">&#10003;</span> Full OSINT suite (Domain / Headers / URL / Email)</div>
        <div class="plan-feature"><span class="feat-icon feat-yes">&#10003;</span> Watchlist domain monitoring</div>
        <div class="plan-feature"><span class="feat-icon feat-yes">&#10003;</span> Incident tracker</div>
        <div class="plan-feature"><span class="feat-icon feat-yes">&#10003;</span> PDF scan reports</div>
        <div class="plan-feature"><span class="feat-icon feat-yes">&#10003;</span> All labs</div>
      </div>
      <?php if ($already_pro): ?>
      <span class="plan-cta cta-current">&#10003; Active</span>
      <?php else: ?>
      <form method="POST" action="/upgrade/checkout.php">
        <input type="hidden" name="csrf"     value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="interval" value="monthly" id="checkout-interval">
        <button type="submit" class="plan-cta cta-pro">Upgrade Now &#8594;</button>
      </form>
      <?php endif; ?>
    </div>

  </div>

  <!-- FAQ -->
  <div class="faq-section">
    <div class="hk-page-eyebrow">FAQ</div>
    <div class="faq-grid">
      <div class="faq-item">
        <div class="faq-q">Can I cancel anytime?</div>
        <div class="faq-a">Yes. Cancel from Settings at any time. You keep Pro access until the end of your billing period.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">What payment methods are accepted?</div>
        <div class="faq-a">Visa, Mastercard, and mobile money (via Paystack). Payments are processed securely — we never store card details.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Is there a free trial?</div>
        <div class="faq-a">The Free plan lets you explore the scanner (3 runs/day) and quiz indefinitely. No trial period or credit card required.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">What happens to my data if I downgrade?</div>
        <div class="faq-a">Nothing is deleted. Your scan history, XP, badges, and incidents are preserved. You just lose access to Pro-only tools.</div>
      </div>
    </div>
  </div>

</div>
</main>
</div>

<script>
var billing = 'monthly';
function setBilling(v) {
  billing = v;
  document.getElementById('bill-monthly').classList.toggle('active', v === 'monthly');
  document.getElementById('bill-annual').classList.toggle('active',  v === 'annual');
  document.getElementById('pro-price').textContent  = v === 'annual' ? '$76' : '$9';
  document.getElementById('pro-period').textContent = v === 'annual' ? '/ year' : '/ month';
  document.getElementById('checkout-interval').value = v;
}
</script>
</body>
</html>
