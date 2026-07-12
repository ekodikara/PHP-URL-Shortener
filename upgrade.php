<?php
/*
 * Snip — plan selection. Plan changes are handled by Stripe Checkout
 * (see checkout.php); this page just shows the pricing cards.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

require_login();
$user = current_user();

render_header('Plans');
?>
<section class="hero hero-sub">
  <h1>Pick your <span class="grad">plan</span></h1>
  <p>You're currently on the <strong><?= e(plan_config($user['plan'])['name']) ?></strong> plan.</p>
</section>

<section id="pricing">
  <!-- Yearly billing toggle hidden for now; monthly-only. Re-enable with render_billing_toggle(). -->
  <div class="plans">
    <?php render_plans($user['plan']); ?>
  </div>
  <?php render_plans_comparison($user['plan']); ?>
  <p class="hint center">
    Paid plans <strong>renew automatically</strong> at the price shown, until you cancel. Secure payments by
    Stripe. Cancel anytime from your dashboard — you keep access until the period you've paid for ends; the
    current period isn't refunded for change of mind. See our
    <a href="refund">Refund &amp; Cancellation Policy</a> and <a href="terms">Terms</a>.
  </p>
</section>
<?php render_footer(); ?>
