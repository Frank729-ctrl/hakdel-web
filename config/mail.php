<?php
/**
 * mail.php — SMTP mailer helper for HakDel
 *
 * Uses PHPMailer + Hostinger SMTP.
 * Set credentials via environment variables or edit the constants below.
 *
 * Usage:
 *   $ok = send_mail('to@example.com', 'Subject', 'Plain-text body', '<p>HTML body</p>');
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ── SMTP credentials ──────────────────────────────────────────────────────────
// Set these as environment variables (recommended) or hardcode for development.
define('MAIL_HOST',     getenv('MAIL_HOST')     ?: 'smtp.hostinger.com');
define('MAIL_PORT',     getenv('MAIL_PORT')     ?: 465);
define('MAIL_USER',     getenv('MAIL_USER')     ?: 'noreply@yourdomain.com');  // ← change this
define('MAIL_PASS',     getenv('MAIL_PASS')     ?: 'your-email-password');      // ← change this
define('MAIL_FROM',     getenv('MAIL_FROM')     ?: 'noreply@yourdomain.com');  // ← change this
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'HakDel');

/**
 * Send an email via Hostinger SMTP.
 *
 * @param string $to        Recipient email address
 * @param string $subject   Email subject
 * @param string $body_text Plain-text body
 * @param string $body_html Optional HTML body (falls back to text if omitted)
 * @return bool             True on success, false on failure
 */
function send_mail(string $to, string $subject, string $body_text, string $body_html = ''): bool
{
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USER;
        $mail->Password   = MAIL_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;   // SSL on port 465
        $mail->Port       = (int)MAIL_PORT;

        // Sender / recipient
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(!empty($body_html));
        $mail->Subject = $subject;
        $mail->Body    = !empty($body_html) ? $body_html : $body_text;
        if (!empty($body_html)) {
            $mail->AltBody = $body_text;
        }

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('HakDel mailer error: ' . $mail->ErrorInfo);
        return false;
    }
}
