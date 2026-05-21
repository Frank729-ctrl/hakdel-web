<?php
$effective = 'March 23, 2025';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Privacy Policy — HakDel</title>
  <link rel="stylesheet" href="/assets/style.css">
  <style>
    body { max-width: 760px; margin: 0 auto; padding: 48px 24px 80px; }
    .legal-brand {
      font-family: var(--mono); font-size: 18px; font-weight: 700;
      color: var(--text); margin-bottom: 40px; display: block;
      text-decoration: none;
    }
    .legal-brand span { color: var(--accent); }
    h1 { font-family: var(--mono); font-size: 26px; font-weight: 700; color: var(--text); margin: 0 0 6px; }
    .legal-meta { font-size: 13px; color: var(--text3); margin-bottom: 36px; }
    h2 { font-family: var(--mono); font-size: 14px; letter-spacing: 1px; color: var(--accent);
         text-transform: uppercase; margin: 36px 0 12px; }
    p, li { font-size: 14px; color: var(--text2); line-height: 1.75; }
    ul { padding-left: 20px; margin: 10px 0; }
    li { margin-bottom: 6px; }
    a { color: var(--accent); }
    strong { color: var(--text); }
    table { width: 100%; border-collapse: collapse; margin: 14px 0; font-size: 13px; }
    th { text-align: left; font-family: var(--mono); font-size: 11px; letter-spacing: 1px;
         text-transform: uppercase; color: var(--text3); padding: 8px 12px;
         border-bottom: 1px solid var(--border); }
    td { padding: 9px 12px; color: var(--text2); border-bottom: 1px solid var(--border); vertical-align: top; }
    tr:last-child td { border-bottom: none; }
    .legal-footer { margin-top: 48px; padding-top: 24px; border-top: 1px solid var(--border);
                    font-size: 13px; color: var(--text3); }
    .legal-footer a { color: var(--text3); text-decoration: none; margin-right: 16px; }
    .legal-footer a:hover { color: var(--accent); }
  </style>
</head>
<body>

<a href="/" class="legal-brand">HAK<span>DEL</span></a>

<h1>Privacy Policy</h1>
<div class="legal-meta">Effective date: <?= $effective ?> &nbsp;&middot;&nbsp; Last updated: <?= $effective ?></div>

<p>
  This Privacy Policy describes how HakDel ("we", "us", "our") collects, uses, and protects your
  personal information when you use the Platform at hakdel.com. We take your privacy seriously and
  will only use your data as described here.
</p>

<h2>1. Information We Collect</h2>

<table>
  <tr>
    <th>Data</th>
    <th>How we collect it</th>
    <th>Why</th>
  </tr>
  <tr>
    <td><strong>Account data</strong><br>Username, email address, password (hashed)</td>
    <td>You provide it at registration</td>
    <td>To create and manage your account</td>
  </tr>
  <tr>
    <td><strong>Google profile</strong><br>Name, email, Google ID</td>
    <td>Google OAuth (only if you choose "Sign in with Google")</td>
    <td>To authenticate without a password</td>
  </tr>
  <tr>
    <td><strong>Scan and tool inputs</strong><br>URLs, IP addresses, domains, hashes you submit</td>
    <td>You enter them into tools</td>
    <td>To perform the analysis and store your history</td>
  </tr>
  <tr>
    <td><strong>Usage data</strong><br>XP, scan count, lab progress, quiz answers</td>
    <td>Generated as you use the Platform</td>
    <td>Gamification, leaderboard, badge system</td>
  </tr>
  <tr>
    <td><strong>Payment data</strong><br>Transaction reference, amount paid, billing date</td>
    <td>Paystack payment processor</td>
    <td>To manage your subscription. <strong>We never store card details.</strong></td>
  </tr>
  <tr>
    <td><strong>Login session data</strong><br>Session token, last active date, streak</td>
    <td>Generated on login</td>
    <td>To keep you logged in and track daily streaks</td>
  </tr>
  <tr>
    <td><strong>Security data</strong><br>2FA secret (encrypted)</td>
    <td>Set up by you in Settings</td>
    <td>To secure your account with two-factor authentication</td>
  </tr>
</table>

<p>
  We do <strong>not</strong> collect precise location data, sell your data to third parties, or
  use your data for advertising.
</p>

<h2>2. How We Use Your Data</h2>
<ul>
  <li>Provide and maintain the Platform and its features</li>
  <li>Process and display scan results and tool output to you</li>
  <li>Send transactional emails (email verification, password reset, watchlist alerts, payment receipts)</li>
  <li>Manage your subscription and process payments via Paystack</li>
  <li>Display leaderboard rankings and award badges based on your activity</li>
  <li>Improve the Platform through anonymised, aggregated usage analysis</li>
  <li>Enforce our Terms of Service and investigate abuse</li>
</ul>

<h2>3. Third-Party Services</h2>
<p>
  To deliver functionality, the Platform sends data to the following third-party services.
  Each is governed by their own privacy policy:
</p>
<ul>
  <li><strong>VirusTotal</strong> — URLs, file hashes, and IP addresses you submit to relevant tools
      are sent to VirusTotal for reputation analysis.</li>
  <li><strong>AbuseIPDB</strong> — IP addresses you check are sent to AbuseIPDB.</li>
  <li><strong>Shodan</strong> — IP addresses may be checked against Shodan for open port data.</li>
  <li><strong>Google Safe Browsing</strong> — URLs you submit to the URL checker are sent to Google.</li>
  <li><strong>Paystack</strong> — Your email address and transaction amount are sent to Paystack
      to process subscription payments. We do not receive or store card details.</li>
  <li><strong>Google OAuth</strong> — If you sign in with Google, your Google profile (name, email,
      Google ID) is received from Google's OAuth service.</li>
  <li><strong>HackerTarget</strong> — Certain network tool queries (port scanning, reverse IP) may
      be sent to HackerTarget's free API.</li>
</ul>
<p>
  By using these features, you acknowledge that your submitted data is forwarded to these services.
  Do not submit sensitive personal data (passwords, private keys, confidential documents) through
  the Platform's tools.
</p>

<h2>4. Data Retention</h2>
<ul>
  <li><strong>Account data</strong> — retained for as long as your account exists. Deleted within
      30 days of account deletion.</li>
  <li><strong>Scan and tool history</strong> — retained indefinitely so you can review past results.
      You can delete individual entries from your history pages.</li>
  <li><strong>Payment records</strong> — retained for 7 years for accounting and legal compliance.</li>
  <li><strong>Session tokens</strong> — expire after 30 days of inactivity.</li>
</ul>

<h2>5. Data Security</h2>
<p>
  We implement the following technical measures to protect your data:
</p>
<ul>
  <li>Passwords are hashed using bcrypt (cost factor 12) — we cannot read your password</li>
  <li>CSRF tokens on all state-changing forms</li>
  <li>Session tokens are random 256-bit values stored server-side</li>
  <li>2FA secrets are stored in the database and protected by your account login</li>
  <li>Database connections use prepared statements to prevent SQL injection</li>
  <li>HTTPS enforced in production</li>
</ul>
<p>
  No system is 100% secure. In the event of a data breach affecting your personal data, we will
  notify you by email within 72 hours of becoming aware of it.
</p>

<h2>6. Your Rights</h2>
<p>You have the right to:</p>
<ul>
  <li><strong>Access</strong> — request a copy of the personal data we hold about you</li>
  <li><strong>Correction</strong> — update your username, email, or password from Settings</li>
  <li><strong>Deletion</strong> — delete your account and associated data from Settings &rarr; Danger Zone.
      Payment records are excluded as they are required for legal compliance.</li>
  <li><strong>Data portability</strong> — request an export of your scan history and tool results</li>
  <li><strong>Objection</strong> — object to any processing you believe is unlawful</li>
</ul>
<p>
  To exercise any of these rights, contact us at <a href="mailto:privacy@hakdel.com">privacy@hakdel.com</a>.
  We will respond within 30 days.
</p>

<h2>7. Cookies and Local Storage</h2>
<p>
  The Platform uses one server-side session cookie to keep you logged in. We do not use tracking
  cookies, analytics cookies, or advertising cookies. No third-party scripts place cookies on your
  device from the Platform.
</p>

<h2>8. Children</h2>
<p>
  The Platform is not directed at children under 16. We do not knowingly collect personal data from
  children under 16. If you believe a child under 16 has created an account, contact us and we will
  delete it promptly.
</p>

<h2>9. Changes to This Policy</h2>
<p>
  We may update this Privacy Policy. Significant changes will be communicated via email or an
  in-platform notice. The "last updated" date at the top of this page reflects the most recent revision.
</p>

<h2>10. Contact</h2>
<p>
  Privacy questions or requests:
  <a href="mailto:privacy@hakdel.com">privacy@hakdel.com</a>
</p>

<div class="legal-footer">
  <a href="/">Home</a>
  <a href="/legal/terms.php">Terms of Service</a>
  <a href="/legal/privacy.php">Privacy Policy</a>
  <span>&copy; <?= date('Y') ?> HakDel. All rights reserved.</span>
</div>

</body>
</html>
