<?php
/*
 * Snip — redirect to the Stripe Customer Portal for self-service billing
 * (update card, view invoices, cancel — cancellation keeps access until the
 * paid period ends, then the subscription.deleted webhook downgrades them).
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/stripe.php';

require_login();
$user = current_user();

// CSRF: require the per-session token so a cross-site request can't spin up a
// billing-portal redirect for the victim.
$token = isset($_POST['csrf']) ? $_POST['csrf'] : (isset($_GET['t']) ? $_GET['t'] : '');
if (!hash_equals(csrf_token(), (string) $token)) {
    redirect_to('dashboard');
}

if (empty($user['stripe_customer_id']) || !stripe_ready()) {
    set_flash('error', 'You don\'t have a billing account yet.');
    redirect_to('upgrade');
}

try {
    $portal = \Stripe\BillingPortal\Session::create(array(
        'customer'   => $user['stripe_customer_id'],
        'return_url' => BASE_HREF . 'dashboard',
    ));
    redirect_to($portal->url);
} catch (\Exception $e) {
    error_log('Stripe portal error: ' . $e->getMessage());
    set_flash('error', 'Billing portal is unavailable right now.');
    redirect_to('dashboard');
}
