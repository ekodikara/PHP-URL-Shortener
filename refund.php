<?php
/*
 * Snip — Refund & Cancellation Policy. States the lawful "no change-of-mind
 * refund" position while preserving Australian Consumer Law rights.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

render_header('Refund & Cancellation Policy');
?>
<section class="hero" style="padding:40px 0 8px">
  <h1>Refund &amp; <span class="grad">cancellation</span></h1>
  <p>Cancel anytime, in a click. Here's exactly what happens to your access and
     your money.</p>
</section>

<div class="card glass" style="max-width:720px;margin:0 auto">
  <h2>1. Your Australian Consumer Law rights come first</h2>
  <p class="sub">Our services come with guarantees that cannot be excluded under the Australian
    Consumer Law. <strong>Nothing in this policy limits or excludes any right you have under the
    Australian Consumer Law.</strong> If there is a major failure with the service you are entitled
    to cancel and receive a refund for the unused portion, or compensation for any drop in value.</p>

  <h2>2. Subscriptions renew automatically</h2>
  <p class="sub">Paid plans (Pro and Premium) are billed in advance and <strong>renew automatically</strong>
    at the price and interval shown at checkout (monthly or yearly) until you cancel. We show the
    price before you pay, and — once billing is live — your renewal date on your dashboard.</p>

  <h2>3. How to cancel</h2>
  <p class="sub">Cancel anytime from your <a href="dashboard">dashboard</a> → <strong>Manage billing</strong>
    (the Stripe customer portal). Cancelling is no harder than signing up, takes effect immediately for
    future renewals, and needs no email or phone call.</p>

  <h2>4. What cancelling does</h2>
  <p class="sub">When you cancel, <strong>future renewals stop</strong> and you <strong>keep access until the
    end of the period you've already paid for</strong>. We do <strong>not</strong> refund the current period
    for a change of mind or for cancelling part-way through a period — you keep the paid access you already
    have until it ends. There is no cooling-off period for online digital subscriptions.</p>

  <h2>5. Refunds for a service problem</h2>
  <p class="sub">Separate from change-of-mind: if the service has a <strong>major failure</strong> (it doesn't
    work, isn't as described, or can't be fixed in a reasonable time), you can choose a refund of the unused
    portion or to keep it and recover any drop in value, as required by the Australian Consumer Law. For a
    minor problem we'll fix it promptly. To request this, email us (below) — we don't need you to go through
    your bank.</p>

  <h2>6. A charge you don't recognise? Talk to us first</h2>
  <p class="sub">Charges appear on your statement as <code><?= e(STATEMENT_DESCRIPTOR) ?></code>. If a charge
    looks wrong, please <a href="mailto:<?= e(ABUSE_EMAIL) ?>"><?= e(ABUSE_EMAIL) ?></a> us <strong>before
    opening a dispute with your bank</strong> — we can usually resolve it within one business day, which is
    faster for you. This is an invitation, not a barrier: it never affects your right to dispute a charge.</p>

  <h2>7. Prices &amp; tax</h2>
  <p class="sub">Prices are shown before you pay. Any applicable taxes (e.g. GST once we are registered) are
    shown at checkout. Your invoices and receipts are available anytime in the billing portal.</p>
</div>
<?php render_footer(); ?>
