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
    // Misconfiguration: without the secret we can't verify or process events, so
    // subscriptions would silently never activate. Make it LOUD in the logs
    // (still 200 so Stripe doesn't hammer retries against a broken endpoint).
    error_log('STRIPE MISCONFIG: stripe-webhook received an event but STRIPE_WEBHOOK_SECRET is unset — no subscription changes will be processed.');
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

// Idempotency: process each event id at most once (replay / out-of-order guard).
// The stripe_events row is the guard, but we only keep it if handling SUCCEEDS —
// on failure we remove it and 500 so Stripe retries (otherwise a transient DB
// error during handling would be permanently masked as "already processed" and
// a paying customer would never get upgraded).
try {
    $seen = $pdo->prepare('INSERT INTO stripe_events (event_id, ts) VALUES (?, ?)');
    $seen->execute(array($event->id, time()));
} catch (\PDOException $e) {
    // Duplicate key → already processed successfully before; acknowledge and stop.
    http_response_code(200);
    echo 'duplicate';
    exit;
}

try {
    switch ($event->type) {
        case 'checkout.session.completed':
            $s = $event->data->object;
            // 'no_payment_required' covers 100%-off promo codes (allow_promotion_codes).
            $paid = in_array($s->payment_status, array('paid', 'no_payment_required'), true);
            if (!empty($s->metadata->user_id) && $paid) {
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
            // Fires when a subscription actually ends. Only downgrade if the
            // ENDED subscription is the user's current one — a late-arriving
            // delete for an old sub must not clobber a fresh re-subscription.
            $sub = $event->data->object;
            downgrade_by_customer($pdo, $sub->customer, $sub->id);
            break;

        case 'customer.subscription.created':
        case 'customer.subscription.updated':
            // Sync the period end + cancel flag so the dashboard can show
            // "Renews on / Access until X" and reflect a Portal cancellation.
            // Plan activation stays with checkout.session.completed; this only
            // touches the two period fields on the matching (customer, sub) row.
            $sub = $event->data->object;
            stripe_store_period(
                $pdo, $sub->customer, $sub->id,
                isset($sub->current_period_end) ? $sub->current_period_end : null,
                !empty($sub->cancel_at_period_end)
            );
            break;
    }
} catch (\Throwable $e) {
    // Handling failed — undo the idempotency marker and 500 so Stripe retries.
    try {
        $pdo->prepare('DELETE FROM stripe_events WHERE event_id = ?')->execute(array($event->id));
    } catch (\Throwable $e2) { /* best effort */ }
    error_log('Stripe webhook handling failed for event ' . $event->id . ': ' . $e->getMessage());
    http_response_code(500);
    echo 'retry';
    exit;
}

http_response_code(200);
echo 'ok';
