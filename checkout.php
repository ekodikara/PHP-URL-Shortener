<?php
/*
 * Snip — start a Stripe Checkout subscription session.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/stripe.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('upgrade');
}
csrf_check();

$user     = current_user();
$plan     = isset($_POST['plan']) ? $_POST['plan'] : '';
$interval = (isset($_POST['interval']) && $_POST['interval'] === 'year') ? 'year' : 'month';

// Only real, purchasable plans.
if (!isset($GLOBALS['PLANS'][$plan]) || $GLOBALS['PLANS'][$plan]['is_trial'] || $GLOBALS['PLANS'][$plan]['price'] <= 0) {
    set_flash('error', 'Please choose a paid plan.');
    redirect_to('upgrade');
}

if (!stripe_ready()) {
    set_flash('error', 'Billing is not configured yet. Please try again later.');
    redirect_to('upgrade');
}

// Already subscribed? Plan changes + cancellation MUST go through the Customer
// Portal (it swaps the plan with proration on the existing subscription).
// Creating a second Checkout Session here would leave the old subscription
// active and double-bill the customer.
if (!empty($user['stripe_subscription_id'])) {
    set_flash('error', 'You already have an active subscription — use "Manage billing" on the Plans page to switch plans or cancel.');
    redirect_to('upgrade');
}

try {
    $customer = stripe_get_or_create_customer($pdo, $user);
    $session = \Stripe\Checkout\Session::create(array(
        'mode'        => 'subscription',
        'customer'    => $customer,
        // NB: no payment_method_types — let Stripe pick eligible methods dynamically.
        'line_items'  => array(stripe_line_item($plan, $interval)),
        'subscription_data' => array(
            'metadata' => array('user_id' => (string) $user['id'], 'plan' => $plan, 'interval' => $interval),
        ),
        'metadata'    => array('user_id' => (string) $user['id'], 'plan' => $plan, 'interval' => $interval),
        'allow_promotion_codes' => true,
        'success_url' => BASE_HREF . 'billing-success?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => BASE_HREF . 'upgrade',
    ));
    redirect_to($session->url);
} catch (\Exception $e) {
    error_log('Stripe checkout error: ' . $e->getMessage());
    set_flash('error', 'Could not start checkout. Please try again.');
    redirect_to('upgrade');
}
