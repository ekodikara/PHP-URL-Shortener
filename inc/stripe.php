<?php
/*
 * Snip — Stripe integration helpers (subscriptions via Checkout).
 *
 * Pricing model: every paid plan is billed monthly by default; choosing the
 * yearly interval applies a YEARLY_DISCOUNT (12%) off 12 months.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (STRIPE_SECRET_KEY !== '') {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    \Stripe\Stripe::setApiVersion(STRIPE_API_VERSION);
}

/** True if a Stripe secret key is configured. */
function stripe_ready()
{
    return STRIPE_SECRET_KEY !== '';
}

/** Plans that can actually be purchased. */
function billable_plans()
{
    $out = array();
    foreach ($GLOBALS['PLANS'] as $key => $p) {
        if (!$p['is_trial'] && $p['price'] > 0) {
            $out[$key] = $p;
        }
    }
    return $out;
}

/** A Checkout line item with inline recurring price_data. */
function stripe_line_item($plan, $interval)
{
    $name = 'Snip ' . plan_config($plan)['name']
        . ($interval === 'year' ? ' (annual)' : ' (monthly)');
    return array(
        'quantity'   => 1,
        'price_data' => array(
            'currency'     => 'usd',
            'unit_amount'  => plan_amount_cents($plan, $interval),
            'recurring'    => array('interval' => $interval),
            'product_data' => array('name' => $name),
        ),
    );
}

/** Return the user's Stripe customer id, creating one if needed. */
function stripe_get_or_create_customer(PDO $pdo, array $user)
{
    if (!empty($user['stripe_customer_id'])) {
        return $user['stripe_customer_id'];
    }
    $customer = \Stripe\Customer::create(array(
        'email'    => $user['email'],
        'metadata' => array('user_id' => (string) $user['id']),
    ));
    $stmt = $pdo->prepare('UPDATE users SET stripe_customer_id = ? WHERE id = ?');
    $stmt->execute(array($customer->id, $user['id']));
    return $customer->id;
}

/** Persist an active subscription onto the user. Idempotent. */
function activate_subscription(PDO $pdo, $user_id, $plan, $interval, $customer_id, $subscription_id)
{
    if (!isset($GLOBALS['PLANS'][$plan]) || $GLOBALS['PLANS'][$plan]['is_trial']) {
        return; // never activate the trial via billing
    }
    $interval = ($interval === 'year') ? 'year' : 'month';
    $stmt = $pdo->prepare(
        'UPDATE users SET plan = ?, billing_interval = ?, stripe_customer_id = ?, stripe_subscription_id = ? WHERE id = ?'
    );
    $stmt->execute(array($plan, $interval, $customer_id, $subscription_id, $user_id));
}

/**
 * Persist a subscription's current-period-end + cancel-at-period-end onto the
 * matching user (by customer + subscription id). Fed by the subscription
 * webhook events (and post-checkout) so the dashboard can show "Renews on /
 * Access until X" from a local read, with no live Stripe API call per page.
 */
function stripe_store_period(PDO $pdo, $customer_id, $subscription_id, $current_period_end, $cancel_at_period_end)
{
    if (!$customer_id || !$subscription_id) {
        return;
    }
    $stmt = $pdo->prepare(
        'UPDATE users SET current_period_end = ?, cancel_at_period_end = ?
          WHERE stripe_customer_id = ? AND stripe_subscription_id = ?'
    );
    $stmt->execute(array(
        $current_period_end !== null ? (int) $current_period_end : null,
        $cancel_at_period_end ? 1 : 0,
        $customer_id, $subscription_id,
    ));
}

/**
 * Downgrade a user (looked up by Stripe customer id) back to the locked free
 * state. When $subscription_id is given, only downgrade if it matches the
 * user's CURRENT subscription — so a late/duplicate delete for a superseded
 * subscription can't wipe out a fresh re-subscription.
 */
function downgrade_by_customer(PDO $pdo, $customer_id, $subscription_id = null)
{
    if ($subscription_id !== null) {
        $stmt = $pdo->prepare(
            'UPDATE users SET plan = "free", billing_interval = NULL, stripe_subscription_id = NULL,
                    current_period_end = NULL, cancel_at_period_end = 0
              WHERE stripe_customer_id = ? AND stripe_subscription_id = ?'
        );
        $stmt->execute(array($customer_id, $subscription_id));
        return; // if it wasn't the current sub, we intentionally do nothing
    }
    $stmt = $pdo->prepare(
        'UPDATE users SET plan = "free", billing_interval = NULL, stripe_subscription_id = NULL,
                current_period_end = NULL, cancel_at_period_end = 0 WHERE stripe_customer_id = ?'
    );
    $stmt->execute(array($customer_id));
}
