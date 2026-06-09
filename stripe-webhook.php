<?php
/*
 * Snip — Stripe webhook receiver. Verifies the signature, then keeps the
 * user's plan in sync with their subscription. No session / CSRF here —
 * authenticity comes from the Stripe signature.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/inc/stripe.php';

$payload = file_get_contents('php://input');
$sig = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';

if (STRIPE_WEBHOOK_SECRET === '') {
    // Not configured — acknowledge so Stripe doesn't retry, but do nothing.
    http_response_code(200);
    echo 'webhook secret not configured';
    exit;
}

try {
    $event = \Stripe\Webhook::constructEvent($payload, $sig, STRIPE_WEBHOOK_SECRET);
} catch (\UnexpectedValueException $e) {
    http_response_code(400); exit;          // bad payload
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400); exit;          // bad signature — reject
}

switch ($event->type) {
    case 'checkout.session.completed':
        $s = $event->data->object;
        if (!empty($s->metadata->user_id) && $s->payment_status === 'paid') {
            activate_subscription(
                $pdo,
                $s->metadata->user_id,
                $s->metadata->plan,
                $s->metadata->interval,
                $s->customer,
                $s->subscription
            );
        }
        break;

    case 'customer.subscription.deleted':
        // Fires when the subscription actually ends (after cancel_at_period_end,
        // or on failed payment). Downgrade to the locked free state.
        $sub = $event->data->object;
        downgrade_by_customer($pdo, $sub->customer);
        break;

    // customer.subscription.updated (e.g. cancel_at_period_end = true) keeps the
    // plan active until period end, so no action is needed there.
}

http_response_code(200);
echo 'ok';
