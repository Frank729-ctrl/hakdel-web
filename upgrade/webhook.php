<?php
// Paystack webhook — called by Paystack servers, NOT by users
// Add this URL in your Paystack dashboard: https://yourdomain.com/upgrade/webhook.php

require_once __DIR__ . '/../config/app.php';

$secret_key = getenv('PAYSTACK_SECRET_KEY') ?: '';
$payload    = file_get_contents('php://input');
$sig        = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

// Verify webhook signature
if (!hash_equals(hash_hmac('sha512', $payload, $secret_key), $sig)) {
    http_response_code(400);
    exit('Invalid signature');
}

$event = json_decode($payload, true);
if (!$event) { http_response_code(200); exit; }

$type = $event['event'] ?? '';
$data = $event['data'] ?? [];

switch ($type) {

    case 'charge.success':
        // A recurring payment succeeded — extend the plan
        $meta     = $data['metadata'] ?? [];
        $uid      = (int)($meta['user_id'] ?? 0);
        $interval = $meta['interval'] ?? 'monthly';
        $ref      = $data['reference'] ?? '';

        if ($uid && $ref) {
            _ensure_plan_columns();
            $expires_at = $interval === 'annual'
                ? date('Y-m-d H:i:s', strtotime('+1 year'))
                : date('Y-m-d H:i:s', strtotime('+1 month'));
            db()->prepare('UPDATE users SET plan = ?, plan_expires_at = ? WHERE id = ?')
                ->execute(['pro', $expires_at, $uid]);
            try {
                db()->prepare(
                    'INSERT IGNORE INTO payments (user_id, reference, amount, currency, interval_type, status)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$uid, $ref, (int)($data['amount'] ?? 0), $data['currency'] ?? 'GHS', $interval, 'success']);
            } catch (Exception $e) {}
        }
        break;

    case 'subscription.disable':
        // Subscription cancelled — let it run until expiry (already set), don't extend
        // No action needed; expiry date already handles access removal.
        break;
}

http_response_code(200);
echo 'OK';
