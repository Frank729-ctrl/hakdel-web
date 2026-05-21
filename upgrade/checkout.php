<?php
require_once __DIR__ . '/../config/app.php';

$user = require_login();
if (!verify_csrf($_POST['csrf'] ?? '')) { redirect('/upgrade/'); }

$interval = ($_POST['interval'] ?? 'monthly') === 'annual' ? 'annual' : 'monthly';

// Prices in GHS (Ghana Cedis) — adjust to your currency/amounts
// Paystack uses smallest currency unit (pesewas for GHS, kobo for NGN, cents for USD)
$prices = [
    'monthly' => ['amount' => 14900,  'label' => 'GHS 149/month'],  // GHS 149.00
    'annual'  => ['amount' => 104900, 'label' => 'GHS 1,049/year'], // GHS 1,049.00
];

$price     = $prices[$interval];
$secret_key = getenv('PAYSTACK_SECRET_KEY') ?: '';

if (!$secret_key) {
    flash('error', 'Payment is not configured yet. Please try again later.');
    redirect('/upgrade/');
}

// Create Paystack transaction
$ref  = 'hkd_' . $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(4));
$body = json_encode([
    'email'        => $user['email'],
    'amount'       => $price['amount'],
    'reference'    => $ref,
    'currency'     => 'GHS',
    'callback_url' => (getenv('SITE_URL') ?: 'http://localhost:8080') . '/upgrade/callback.php',
    'metadata'     => [
        'user_id'  => $user['id'],
        'interval' => $interval,
        'plan'     => 'pro',
    ],
    'channels' => ['card', 'mobile_money', 'bank'],
]);

$ch = curl_init('https://api.paystack.co/transaction/initialize');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $secret_key,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$err      = curl_error($ch);
curl_close($ch);

if ($err) {
    flash('error', 'Payment gateway error. Please try again.');
    redirect('/upgrade/');
}

$data = json_decode($response, true);

if (!($data['status'] ?? false) || empty($data['data']['authorization_url'])) {
    flash('error', 'Could not initialize payment. Please try again.');
    redirect('/upgrade/');
}

// Store reference in session to verify on callback
$_SESSION['paystack_ref']      = $ref;
$_SESSION['paystack_interval'] = $interval;

// Redirect to Paystack hosted payment page
header('Location: ' . $data['data']['authorization_url']);
exit;
