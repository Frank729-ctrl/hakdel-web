<?php
/**
 * mail_templates.php — Branded HTML email templates for HakDel
 *
 * All templates return ['subject' => '...', 'html' => '...', 'text' => '...']
 */

/**
 * Internal helper: wrap body HTML in a branded dark email layout.
 */
function _mail_base(string $title, string $body_html, string $preview): string
{
    $site_url = defined('SITE_URL') ? SITE_URL : 'http://localhost:8080';
    $year     = date('Y');

    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>
</head>
<body style="margin:0;padding:0;background:#0a0a0a;font-family:\'Courier New\',Courier,monospace;">
<!-- Preview text -->
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">' . htmlspecialchars($preview, ENT_QUOTES, 'UTF-8') . '&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0a0a0a;min-height:100vh;">
  <tr>
    <td align="center" style="padding:40px 16px;">

      <!-- Container -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">

        <!-- Header / Logo -->
        <tr>
          <td align="center" style="padding-bottom:28px;">
            <span style="display:inline-flex;align-items:center;gap:10px;">
              <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#00d4aa;"></span>
              <span style="font-family:\'Courier New\',Courier,monospace;font-size:22px;font-weight:700;letter-spacing:3px;color:#e0e0e0;">HAK<span style="color:#00d4aa;">DEL</span></span>
            </span>
          </td>
        </tr>

        <!-- Card -->
        <tr>
          <td style="background:#141414;border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:36px;">
            ' . $body_html . '
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td align="center" style="padding-top:24px;">
            <p style="margin:0;font-family:\'Courier New\',Courier,monospace;font-size:11px;color:#555;">
              &copy; ' . $year . ' HakDel &middot; <a href="' . htmlspecialchars($site_url, ENT_QUOTES, 'UTF-8') . '" style="color:#555;text-decoration:none;">' . htmlspecialchars($site_url, ENT_QUOTES, 'UTF-8') . '</a>
            </p>
            <p style="margin:6px 0 0;font-family:\'Courier New\',Courier,monospace;font-size:10px;color:#444;">
              You received this email because an action was taken on your HakDel account. If this wasn\'t you, you can safely ignore this email.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>';
}

/**
 * Welcome / email verification template.
 *
 * @param string $username   The new user's username
 * @param string $verify_url Full URL to the email verification endpoint
 * @return array{subject: string, html: string, text: string}
 */
function mail_template_welcome(string $username, string $verify_url): array
{
    $u = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $v = htmlspecialchars($verify_url, ENT_QUOTES, 'UTF-8');

    $body_html = '
    <!-- Heading -->
    <h1 style="margin:0 0 8px;font-family:\'Courier New\',Courier,monospace;font-size:24px;font-weight:700;color:#e0e0e0;letter-spacing:1px;">Welcome, ' . $u . '</h1>
    <p style="margin:0 0 24px;font-family:\'Courier New\',Courier,monospace;font-size:13px;color:#888;line-height:1.6;">Your HakDel account has been created. Verify your email to start your ethical hacking journey.</p>

    <!-- Feature list -->
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:rgba(0,212,170,0.05);border:1px solid rgba(0,212,170,0.12);border-radius:8px;margin-bottom:28px;">
      <tr>
        <td style="padding:18px 20px;">
          <p style="margin:0 0 10px;font-family:\'Courier New\',Courier,monospace;font-size:11px;color:#00d4aa;letter-spacing:2px;text-transform:uppercase;">What\'s waiting for you</p>
          <p style="margin:0 0 6px;font-family:\'Courier New\',Courier,monospace;font-size:13px;color:#e0e0e0;line-height:1.5;"><span style="color:#00d4aa;">&#9642;</span>&nbsp; <strong style="color:#00d4aa;">Security Scanner</strong> — audit any web target instantly</p>
          <p style="margin:0 0 6px;font-family:\'Courier New\',Courier,monospace;font-size:13px;color:#e0e0e0;line-height:1.5;"><span style="color:#00d4aa;">&#9642;</span>&nbsp; <strong style="color:#00d4aa;">Hacking Labs</strong> — hands-on challenges &amp; CTF exercises</p>
          <p style="margin:0 0 6px;font-family:\'Courier New\',Courier,monospace;font-size:13px;color:#e0e0e0;line-height:1.5;"><span style="color:#00d4aa;">&#9642;</span>&nbsp; <strong style="color:#00d4aa;">Security Quiz</strong> — test your knowledge &amp; earn XP</p>
          <p style="margin:0;font-family:\'Courier New\',Courier,monospace;font-size:13px;color:#e0e0e0;line-height:1.5;"><span style="color:#00d4aa;">&#9642;</span>&nbsp; <strong style="color:#00d4aa;">Leaderboards</strong> — compete with the community</p>
        </td>
      </tr>
    </table>

    <!-- CTA Button -->
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:16px;">
      <tr>
        <td align="center">
          <a href="' . $v . '" style="display:inline-block;background:#00d4aa;color:#0a0a0a;font-family:\'Courier New\',Courier,monospace;font-size:13px;font-weight:700;letter-spacing:2px;text-decoration:none;padding:14px 36px;border-radius:6px;">VERIFY EMAIL</a>
        </td>
      </tr>
    </table>

    <!-- Expiry note -->
    <p style="margin:0;text-align:center;font-family:\'Courier New\',Courier,monospace;font-size:11px;color:#555;">Link expires in 24 hours.</p>
    ';

    $preview = 'Welcome to HakDel — verify your email to get started.';

    $text = "Welcome to HakDel, {$username}!\n\n"
          . "Your account has been created. Please verify your email address to get started.\n\n"
          . "Verify your email: {$verify_url}\n\n"
          . "This link expires in 24 hours.\n\n"
          . "What's waiting for you:\n"
          . "  * Security Scanner — audit any web target instantly\n"
          . "  * Hacking Labs — hands-on challenges & CTF exercises\n"
          . "  * Security Quiz — test your knowledge & earn XP\n"
          . "  * Leaderboards — compete with the community\n\n"
          . "If you didn't create this account, you can safely ignore this email.\n\n"
          . "— The HakDel Team";

    return [
        'subject' => 'Verify your HakDel email address',
        'html'    => _mail_base('Verify your HakDel email — ' . $username, $body_html, $preview),
        'text'    => $text,
    ];
}

/**
 * Password reset template.
 *
 * @param string $username  The user's username
 * @param string $reset_url Full URL to the password reset endpoint
 * @return array{subject: string, html: string, text: string}
 */
function mail_template_reset(string $username, string $reset_url): array
{
    $u = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $r = htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8');

    $body_html = '
    <!-- Heading -->
    <h1 style="margin:0 0 8px;font-family:\'Courier New\',Courier,monospace;font-size:24px;font-weight:700;color:#e0e0e0;letter-spacing:1px;">Password Reset</h1>
    <p style="margin:0 0 28px;font-family:\'Courier New\',Courier,monospace;font-size:13px;color:#888;line-height:1.6;">Hi ' . $u . ', we received a request to reset your HakDel password.</p>

    <!-- CTA Button -->
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
      <tr>
        <td align="center">
          <a href="' . $r . '" style="display:inline-block;background:#00d4aa;color:#0a0a0a;font-family:\'Courier New\',Courier,monospace;font-size:13px;font-weight:700;letter-spacing:2px;text-decoration:none;padding:14px 36px;border-radius:6px;">RESET PASSWORD</a>
        </td>
      </tr>
    </table>

    <!-- Warning box -->
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td style="background:rgba(255,77,109,0.07);border:1px solid rgba(255,77,109,0.25);border-radius:8px;padding:14px 16px;">
          <p style="margin:0;font-family:\'Courier New\',Courier,monospace;font-size:12px;color:#ff4d6d;line-height:1.6;">&#9888; This link expires in 1 hour. If you didn\'t request a reset, your password is unchanged.</p>
        </td>
      </tr>
    </table>
    ';

    $preview = 'Reset your HakDel password — link expires in 1 hour.';

    $text = "HakDel Password Reset\n\n"
          . "Hi {$username},\n\n"
          . "We received a request to reset your HakDel password.\n\n"
          . "Reset your password: {$reset_url}\n\n"
          . "This link expires in 1 hour.\n\n"
          . "If you didn't request a password reset, your password is unchanged and you can safely ignore this email.\n\n"
          . "— The HakDel Team";

    return [
        'subject' => 'Reset your HakDel password',
        'html'    => _mail_base('HakDel Password Reset', $body_html, $preview),
        'text'    => $text,
    ];
}

/**
 * Scheduled scan alert template.
 *
 * @param string $username   The user's username
 * @param string $target     The scanned target URL
 * @param int    $score      Security score (0–100)
 * @param string $grade      Letter grade (A–F)
 * @param string $summary    Scan summary text
 * @param int    $threshold  Alert threshold set by the user
 * @param string $history_url Full URL to the scan history page
 * @return array{subject: string, html: string, text: string}
 */
function mail_template_scan_alert(
    string $username,
    string $target,
    int    $score,
    string $grade,
    string $summary,
    int    $threshold,
    string $history_url
): array {
    $u = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $t = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
    $g = htmlspecialchars($grade, ENT_QUOTES, 'UTF-8');
    $sm = htmlspecialchars($summary, ENT_QUOTES, 'UTF-8');
    $h = htmlspecialchars($history_url, ENT_QUOTES, 'UTF-8');

    // Score color
    if ($score >= 70) {
        $score_color = '#00d4aa';
    } elseif ($score >= 50) {
        $score_color = '#ffd166';
    } else {
        $score_color = '#ff4d6d';
    }

    $body_html = '
    <!-- Heading -->
    <h1 style="margin:0 0 8px;font-family:\'Courier New\',Courier,monospace;font-size:24px;font-weight:700;color:#e0e0e0;letter-spacing:1px;">Scan Alert</h1>
    <p style="margin:0 0 24px;font-family:\'Courier New\',Courier,monospace;font-size:13px;color:#888;line-height:1.6;">Hi ' . $u . ', your scheduled scan completed below your alert threshold.</p>

    <!-- Info grid -->
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:rgba(0,212,170,0.05);border:1px solid rgba(0,212,170,0.12);border-radius:8px;margin-bottom:24px;">
      <tr>
        <td style="padding:18px 20px;">
          <p style="margin:0 0 14px;font-family:\'Courier New\',Courier,monospace;font-size:11px;color:#00d4aa;letter-spacing:2px;text-transform:uppercase;">Scan Results</p>

          <!-- Target -->
          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:12px;">
            <tr>
              <td style="font-family:\'Courier New\',Courier,monospace;font-size:11px;color:#555;text-transform:uppercase;letter-spacing:1px;padding-bottom:3px;" colspan="2">Target</td>
            </tr>
            <tr>
              <td style="font-family:\'Courier New\',Courier,monospace;font-size:13px;color:#e0e0e0;word-break:break-all;">' . $t . '</td>
            </tr>
          </table>

          <!-- Score / Grade / Threshold row -->
          <table width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td width="33%" style="vertical-align:top;padding-right:8px;">
                <p style="margin:0 0 3px;font-family:\'Courier New\',Courier,monospace;font-size:11px;color:#555;text-transform:uppercase;letter-spacing:1px;">Score</p>
                <p style="margin:0;font-family:\'Courier New\',Courier,monospace;font-size:28px;font-weight:700;color:' . $score_color . ';line-height:1;">' . $score . '<span style="font-size:14px;color:#888;">/100</span></p>
              </td>
              <td width="33%" style="vertical-align:top;padding-right:8px;">
                <p style="margin:0 0 3px;font-family:\'Courier New\',Courier,monospace;font-size:11px;color:#555;text-transform:uppercase;letter-spacing:1px;">Grade</p>
                <p style="margin:0;font-family:\'Courier New\',Courier,monospace;font-size:28px;font-weight:700;color:' . $score_color . ';line-height:1;">' . $g . '</p>
              </td>
              <td width="33%" style="vertical-align:top;">
                <p style="margin:0 0 3px;font-family:\'Courier New\',Courier,monospace;font-size:11px;color:#555;text-transform:uppercase;letter-spacing:1px;">Alert Threshold</p>
                <p style="margin:0;font-family:\'Courier New\',Courier,monospace;font-size:28px;font-weight:700;color:#888;line-height:1;">' . $threshold . '</p>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>

    <!-- Summary -->
    ' . ($summary ? '
    <p style="margin:0 0 6px;font-family:\'Courier New\',Courier,monospace;font-size:11px;color:#555;letter-spacing:1px;text-transform:uppercase;">Summary</p>
    <p style="margin:0 0 24px;font-family:\'Courier New\',Courier,monospace;font-size:13px;color:#888;line-height:1.7;">' . $sm . '</p>
    ' : '') . '

    <!-- CTA Button -->
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td align="center">
          <a href="' . $h . '" style="display:inline-block;background:#00d4aa;color:#0a0a0a;font-family:\'Courier New\',Courier,monospace;font-size:13px;font-weight:700;letter-spacing:2px;text-decoration:none;padding:14px 36px;border-radius:6px;">VIEW FULL RESULTS</a>
        </td>
      </tr>
    </table>
    ';

    $preview = "Your scan scored {$score}/100 (Grade: {$grade}) — below your threshold of {$threshold}.";

    $text = "HakDel Scan Alert\n\n"
          . "Hi {$username},\n\n"
          . "Your scheduled scan completed below your alert threshold.\n\n"
          . "Target:          {$target}\n"
          . "Score:           {$score}/100\n"
          . "Grade:           {$grade}\n"
          . "Alert Threshold: {$threshold}\n\n"
          . ($summary ? "Summary:\n{$summary}\n\n" : '')
          . "View full results: {$history_url}\n\n"
          . "— HakDel Security Engine";

    return [
        'subject' => "HakDel Alert: {$target} scored {$score}/100",
        'html'    => _mail_base('HakDel Scan Alert', $body_html, $preview),
        'text'    => $text,
    ];
}
