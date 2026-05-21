<?php
require_once __DIR__ . '/../config/app.php';
$user    = require_login();
$xp_data = xp_progress((int)$user['xp']);
$level   = $xp_data['level'];
$initials = $user['avatar_initials'] ?? strtoupper(substr($user['username'], 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HakDel - About</title>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/layout.css">
</head>
<body>

<?php require __DIR__ . '/../partials/topbar.php'; ?>

<div class="hk-shell">

  <?php
$nav_active  = 'about';
$sidebar_sub = 'About';
require __DIR__ . '/../partials/sidebar.php';
?>

  <main class="hk-main">

    <div class="hk-page-header">
      <div>
        <div class="hk-page-eyebrow">&#9633; Platform &nbsp;&middot;&nbsp; HakDel v1.0</div>
        <h1 class="hk-page-title">About HakDel</h1>
        <p class="hk-page-sub">A cybersecurity training and site auditing platform built for ethical hackers.</p>
      </div>
    </div>

    <div class="about-grid">

      <!-- What is HakDel -->
      <div class="about-card about-card-wide">
        <div class="about-card-eyebrow">&#9632; The Platform</div>
        <h2 class="about-card-title">What is HakDel?</h2>
        <p class="about-card-body">
          HakDel is a cybersecurity platform built for students, developers, and security professionals
          who want to audit websites and sharpen their ethical hacking skills in one place.
        </p>
        <p class="about-card-body">
          It combines a professional-grade site scanner with a hands-on learning environment — covering
          everything from passive reconnaissance and SSL analysis to SQL injection detection, cookie
          security, WAF identification, and database exposure checks.
        </p>
        <div class="about-tags">
          <span class="about-tag">Website Security</span>
          <span class="about-tag">CEH Preparation</span>
          <span class="about-tag">Ethical Hacking</span>
          <span class="about-tag">Cybersecurity Training</span>
        </div>
      </div>

      <!-- Builder story -->
      <div class="about-card">
        <div class="about-card-eyebrow">&#9650; The Builder</div>
        <h2 class="about-card-title">Why I built this</h2>
        <p class="about-card-body">
          I am a Computer Science student pursuing my CEH certification.
          I built HakDel because I wanted a single tool that combined real security scanning with
          the kind of hands-on lab practice that actually prepares you for the exam and the field.
        </p>
        <p class="about-card-body">
          Everything here — the scanner, the labs, the quiz — is built from scratch using Python,
          PHP, and MySQL. No shortcuts.
        </p>
        <div class="about-builder-links">
          <a href="https://www.linkedin.com/in/frank-nutsukpuie/" class="about-link" target="_blank">&#9670; LinkedIn</a>
          <a href="http://github.com/Frank729-ctrl"   class="about-link" target="_blank">&#9670; GitHub</a>
        </div>
      </div>

      <!-- Scanner -->
      <div class="about-card">
        <div class="about-card-eyebrow">&#9632; Scanner</div>
        <h2 class="about-card-title">Site Scanner</h2>
        <p class="about-card-body">
          The HakDel scanner runs 20 modules across three layers — passive recon, active recon,
          and advanced security checks.
        </p>
        <div class="about-feature-list">
          <div class="about-feature">&#10003; WHOIS, SSL/TLS, DNS, HTTP headers</div>
          <div class="about-feature">&#10003; Port scanning, CMS fingerprinting, CVE disclosure</div>
          <div class="about-feature">&#10003; XSS detection, SQLi probing, cookie security</div>
          <div class="about-feature">&#10003; WAF detection, session security, SMTP enumeration</div>
          <div class="about-feature">&#10003; Malware indicators, database exposure, sniffing risk</div>
          <div class="about-feature">&#10003; Weighted 0–100 security score with A–F grade</div>
        </div>
      </div>

      <!-- Labs -->
      <div class="about-card">
        <div class="about-card-eyebrow">&#9670; Labs</div>
        <h2 class="about-card-title">Learning Labs</h2>
        <p class="about-card-body">
          Labs are SSH-based challenges running on dedicated vulnerable machines.
          You connect with your terminal, work through a chain of steps, find the flag,
          and submit it here for XP.
        </p>
        <div class="about-feature-list">
          <div class="about-feature">&#10003; Real terminal — no browser emulation</div>
          <div class="about-feature">&#10003; Multi-step challenges per lab</div>
          <div class="about-feature">&#10003; CEH-aligned domains</div>
          <div class="about-feature">&#10003; SHA256 flag verification</div>
          <div class="about-feature">&#10003; XP awarded on correct submission</div>
        </div>
      </div>

      <!-- Tech stack -->
      <div class="about-card">
        <div class="about-card-eyebrow">&#9632; Built with</div>
        <h2 class="about-card-title">Tech Stack</h2>
        <div class="about-stack-grid">
          <div class="about-stack-item">
            <div class="about-stack-name">Python / FastAPI</div>
            <div class="about-stack-role">Scanner engine</div>
          </div>
          <div class="about-stack-item">
            <div class="about-stack-name">PHP</div>
            <div class="about-stack-role">Frontend + auth</div>
          </div>
          <div class="about-stack-item">
            <div class="about-stack-name">MySQL</div>
            <div class="about-stack-role">Database</div>
          </div>
          <div class="about-stack-item">
            <div class="about-stack-name">Linux VMs</div>
            <div class="about-stack-role">Lab environments</div>
          </div>
          <div class="about-stack-item">
            <div class="about-stack-name">httpx / dnspython</div>
            <div class="about-stack-role">Scan libraries</div>
          </div>
          <div class="about-stack-item">
            <div class="about-stack-name">Paystack</div>
            <div class="about-stack-role">Donations</div>
          </div>
        </div>
      </div>

      <!-- Donate -->
      <div class="about-card about-donate-card">
        <div class="about-card-eyebrow">&#9670; Support</div>
        <h2 class="about-card-title">Support HakDel</h2>
        <p class="about-card-body">
          HakDel is free to use. If it has helped you prepare for your CEH, audit your site,
          or learn something new — consider supporting the platform so it can grow.
        </p>
        <p class="about-card-body" style="color:var(--text2);font-size:12px">
          Donations go towards server costs, new lab VMs, and continued development.
        </p>
        <button class="btn-donate" onclick="initDonate()">
          &#9670; Donate
        </button>
        <div class="donate-note">Secure payments via Paystack. GHS, USD accepted.</div>
      </div>

    </div>

  </main>
</div>

<script src="https://js.paystack.co/v1/inline.js"></script>
<script src="/assets/js/donate.js"></script>
</body>
</html>