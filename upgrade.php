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
<section class="hero" style="padding-bottom:8px">
  <h1>Pick your <span class="grad">plan</span></h1>
  <p>You're currently on the <strong><?= e(plan_config($user['plan'])['name']) ?></strong> plan.</p>
</section>

<section id="pricing">
  <!-- Yearly billing toggle hidden for now; monthly-only. Re-enable with render_billing_toggle(). -->
  <div class="plans">
    <?php render_plans($user['plan']); ?>
  </div>
  <p class="hint" style="text-align:center;margin-top:22px">
    Secure payments by Stripe. Cancel anytime — you keep access until the period you paid for ends.
  </p>
</section>
<?php render_footer(); ?>
