<?php
/*
 * Snip — post-checkout landing. Verifies the session and activates the plan.
 * (The webhook also activates it; doing it here too makes local testing work
 * without a publicly reachable webhook. Both paths are idempotent.)
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/stripe.php';
require __DIR__ . '/inc/layout.php';

require_login();
$user = current_user();

$activated = false;
$session_id = isset($_GET['session_id']) ? $_GET['session_id'] : '';

if ($session_id !== '' && stripe_ready()) {
    try {
        $session = \Stripe\Checkout\Session::retrieve(array(
            'id'     => $session_id,
            'expand' => array('subscription'),
        ));
        // The subscription must actually be active/trialing (not incomplete/past_due).
        $sub_status = is_object($session->subscription) ? $session->subscription->status : null;
        $sub_ok = in_array($sub_status, array('active', 'trialing'), true);
        // Only act on this user's own, completed & paid session with an active sub.
        if ((string) $session->metadata->user_id === (string) $user['id']
            && $session->status === 'complete'
            && $session->payment_status === 'paid'
            && $sub_ok) {
            $sub_id = is_object($session->subscription) ? $session->subscription->id : $session->subscription;
            activate_subscription(
                $pdo,
                $user['id'],
                $session->metadata->plan,
                $session->metadata->interval,
                $session->customer,
                $sub_id
            );
            $activated = true;
            $user = current_user(); // refresh cached row not needed; re-fetch fresh
        }
    } catch (\Exception $e) {
        error_log('Stripe success verify error: ' . $e->getMessage());
    }
}

$plan_name = plan_config(($activated ? $session->metadata->plan : $user['plan']))['name'];

render_header('Subscription confirmed');
?>
<div class="auth-wrap">
  <div class="card glass" style="text-align:center">
    <div style="font-size:3rem;line-height:1;margin-bottom:8px">🎉</div>
    <h1><?= $activated ? 'You\'re on ' . e($plan_name) . '!' : 'Thanks!' ?></h1>
    <?php if ($activated): ?>
      <p class="sub">Your subscription is active. Time to make some links.</p>
    <?php else: ?>
      <p class="sub">If your payment went through, your plan will update momentarily.</p>
    <?php endif; ?>
    <a class="btn btn-solid btn-block" href="dashboard">Go to dashboard →</a>
  </div>
</div>
<?php render_footer(); ?>
