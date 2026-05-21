<?php
require_once __DIR__ . '/../config/app.php';

$user       = require_login();
$ref        = $_GET['reference'] ?? '';
$secret_key = getenv('PAYSTACK_SECRET_KEY') ?: '';

if (!$ref || !$secret_key) {
    flash('error', 'Payment verification failed.');
    redirect('/upgrade/');
}

// Verify the transaction with Paystack
$ch = curl_init('https://api.paystack.co/transaction/verify/' . urlencode($ref));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secret_key],
    CURLOPT_TIMEOUT        => 15,
]);
$response = curl_exec($ch);
$err      = curl_error($ch);
curl_close($ch);

if ($err) {
    flash('error', 'Could not verify payment. Contact support if you were charged.');
    redirect('/upgrade/');
}

$data = json_decode($response, true);

if (!($data['status'] ?? false) || ($data['data']['status'] ?? '') !== 'success') {
    flash('error', 'Payment was not successful. No charge was made.');
    redirect('/upgrade/');
}

// Confirm the payment was for this user
$meta     = $data['data']['metadata'] ?? [];
$uid      = (int)($meta['user_id'] ?? 0);
$interval = $meta['interval'] ?? 'monthly';

if ($uid !== (int)$user['id']) {
    flash('error', 'Payment mismatch. Contact support.');
    redirect('/upgrade/');
}

// Calculate expiry
$expires_at = $interval === 'annual'
    ? date('Y-m-d H:i:s', strtotime('+1 year'))
    : date('Y-m-d H:i:s', strtotime('+1 month'));

// Upgrade the user
_ensure_plan_columns();
db()->prepare(
    'UPDATE users SET plan = ?, plan_expires_at = ? WHERE id = ?'
)->execute(['pro', $expires_at, $user['id']]);

// Log the payment
try {
    db()->exec("CREATE TABLE IF NOT EXISTS payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        reference VARCHAR(100) NOT NULL UNIQUE,
        amount INT UNSIGNED NOT NULL,
        currency VARCHAR(10) NOT NULL DEFAULT 'GHS',
        interval_type VARCHAR(20) NOT NULL DEFAULT 'monthly',
        status VARCHAR(30) NOT NULL DEFAULT 'success',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id)
    )");
    db()->prepare(
        'INSERT IGNORE INTO payments (user_id, reference, amount, currency, interval_type, status)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $user['id'],
        $ref,
        (int)($data['data']['amount'] ?? 0),
        $data['data']['currency'] ?? 'GHS',
        $interval,
        'success',
    ]);
} catch (Exception $e) {}

create_notification(
    (int)$user['id'],
    'upgrade',
    'Pro plan activated!',
    'Your HakDel Pro subscription is now active. All features are unlocked.',
    '/upgrade/'
);

unset($_SESSION['paystack_ref'], $_SESSION['paystack_interval']);

flash('success', 'Pro activated! All tools and features are now unlocked.');
redirect('/upgrade/');
