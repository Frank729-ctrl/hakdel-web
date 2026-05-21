<?php
$page_title = 'Terms of Service';
$effective  = 'March 23, 2025';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Terms of Service — HakDel</title>
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
    .legal-footer { margin-top: 48px; padding-top: 24px; border-top: 1px solid var(--border);
                    font-size: 13px; color: var(--text3); }
    .legal-footer a { color: var(--text3); text-decoration: none; margin-right: 16px; }
    .legal-footer a:hover { color: var(--accent); }
  </style>
</head>
<body>

<a href="/" class="legal-brand">HAK<span>DEL</span></a>

<h1>Terms of Service</h1>
<div class="legal-meta">Effective date: <?= $effective ?> &nbsp;&middot;&nbsp; Last updated: <?= $effective ?></div>

<p>
  These Terms of Service ("Terms") govern your use of HakDel and all services, tools, and content
  provided through it ("the Platform"). By creating an account or using the Platform, you agree to
  these Terms. If you do not agree, do not use the Platform.
</p>

<h2>1. Who We Are</h2>
<p>
  HakDel is a cybersecurity operations platform providing a site scanner, OSINT tools, security
  training labs, and related features. The Platform is operated by Frank Dela ("we", "us", "our").
</p>

<h2>2. Eligibility</h2>
<p>
  You must be at least 16 years old to use the Platform. By using HakDel you represent that you
  meet this requirement and that any information you provide is accurate.
</p>

<h2>3. Acceptable Use</h2>
<p>
  You agree to use the Platform only for lawful purposes. In particular:
</p>
<ul>
  <li>
    <strong>You may only scan, probe, or test systems that you own or have explicit written
    authorisation to test.</strong> Scanning third-party systems without permission may violate
    computer misuse laws in your country (e.g. the Computer Misuse Act 1990 (UK), the Computer
    Fraud and Abuse Act (US), or equivalent legislation).
  </li>
  <li>You must not use the Platform to conduct denial-of-service attacks, distribute malware,
      or engage in any activity intended to harm others.</li>
  <li>You must not attempt to circumvent the Platform's authentication, access controls,
      or subscription enforcement.</li>
  <li>You must not scrape, copy, or redistribute Platform content or tool output at scale
      without our written consent.</li>
  <li>You must not share your account credentials with others.</li>
</ul>
<p>
  We reserve the right to suspend or terminate accounts that violate these rules without notice or refund.
</p>

<h2>4. Free Trial</h2>
<p>
  New accounts receive a <strong>30-day free trial</strong> of the Pro plan. No payment information
  is required during the trial. At the end of the trial period, your account automatically reverts
  to the Free plan. Access to Pro-only features will be restricted until a paid subscription is activated.
</p>
<p>
  One free trial per person. Creating multiple accounts to extend the trial is a violation of these Terms.
</p>

<h2>5. Subscriptions and Payments</h2>
<p>
  After the free trial, access to Pro features requires a paid subscription. By subscribing you authorise
  us to charge the applicable fee on a recurring basis (monthly or annually) via our payment processor, Paystack.
</p>
<ul>
  <li><strong>Pricing:</strong> Current prices are displayed on the upgrade page. We may change prices
      with 30 days' notice.</li>
  <li><strong>Billing:</strong> You are billed at the start of each billing period. Renewals are automatic
      unless you cancel.</li>
  <li><strong>Cancellation:</strong> You may cancel at any time from your account settings. Cancellation
      takes effect at the end of the current billing period. You retain Pro access until then.</li>
  <li><strong>Refunds:</strong> We offer a <strong>7-day refund</strong> for first-time subscribers who
      contact us within 7 days of their first charge. Subsequent charges are non-refundable.</li>
  <li><strong>Failed payments:</strong> If a payment fails, your account will revert to the Free plan
      until payment is resolved.</li>
</ul>

<h2>6. Intellectual Property</h2>
<p>
  All content, design, code, and materials on the Platform are owned by or licensed to us. You are
  granted a limited, non-exclusive, non-transferable licence to use the Platform for your personal or
  internal business purposes. You may not reproduce, redistribute, or create derivative works from
  Platform content without written permission.
</p>
<p>
  Scan results and reports generated using the Platform belong to you. We may aggregate anonymised
  usage statistics for internal analysis.
</p>

<h2>7. Third-Party Services</h2>
<p>
  The Platform integrates third-party APIs (including VirusTotal, AbuseIPDB, Shodan, Google Safe Browsing,
  and Paystack). Your use of these services via the Platform is subject to their respective terms.
  We are not responsible for the accuracy, availability, or actions of these third parties.
</p>

<h2>8. Disclaimer of Warranties</h2>
<p>
  The Platform is provided <strong>"as is"</strong> without warranties of any kind, express or implied.
  We do not guarantee that scan results are complete, accurate, or up to date. Security tool output is
  informational only — it is not a substitute for a professional security assessment.
</p>
<p>
  We do not warrant uninterrupted or error-free operation of the Platform.
</p>

<h2>9. Limitation of Liability</h2>
<p>
  To the fullest extent permitted by law, we shall not be liable for any indirect, incidental, special,
  consequential, or punitive damages arising from your use of the Platform, including but not limited
  to loss of data, loss of revenue, or security incidents.
</p>
<p>
  Our total liability to you for any claim arising under these Terms shall not exceed the amount you
  paid to us in the 3 months preceding the claim.
</p>

<h2>10. Indemnification</h2>
<p>
  You agree to indemnify and hold us harmless from any claims, losses, or damages (including legal fees)
  arising from your violation of these Terms, your use of the Platform, or your violation of any
  third-party rights.
</p>

<h2>11. Account Termination</h2>
<p>
  You may delete your account at any time from Settings. We may suspend or terminate accounts that
  violate these Terms. Upon termination, your right to use the Platform ceases immediately. We may
  retain certain data as required by law or for legitimate business purposes.
</p>

<h2>12. Changes to These Terms</h2>
<p>
  We may update these Terms from time to time. Significant changes will be notified via email or an
  in-platform notice. Continued use of the Platform after changes take effect constitutes acceptance
  of the revised Terms.
</p>

<h2>13. Governing Law</h2>
<p>
  These Terms are governed by the laws of the Republic of Ghana. Any disputes shall be subject to
  the exclusive jurisdiction of the courts of Ghana.
</p>

<h2>14. Contact</h2>
<p>
  Questions about these Terms? Contact us at
  <a href="mailto:legal@hakdel.com">legal@hakdel.com</a>.
</p>

<div class="legal-footer">
  <a href="/">Home</a>
  <a href="/legal/privacy.php">Privacy Policy</a>
  <a href="/legal/terms.php">Terms of Service</a>
  <span>&copy; <?= date('Y') ?> HakDel. All rights reserved.</span>
</div>

</body>
</html>
