<?php
/*
 * Snip — landing page: hero, shorten box (for members), pricing.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$user = current_user();
render_header();
?>

<section class="hero">
  <h1>Long links,<br><span class="grad">cut down to size.</span></h1>
  <p><?= e(APP_NAME) ?> turns sprawling URLs into short, shareable links — with QR codes, click stats, and your own custom names.</p>
</section>

<div class="card glass shorten-card">
<?php if ($user): ?>
  <h2>New short link</h2>
  <p class="sub">Paste a URL and get a tidy link back instantly.</p>
  <?php require __DIR__ . '/inc/shorten_box.php'; ?>
<?php else: ?>
  <h2>Start shortening in seconds</h2>
  <p class="sub">Start your free trial to make links, track clicks, and generate QR codes.</p>
  <div class="input-row">
    <a class="btn btn-solid btn-block" href="register">Start free trial</a>
    <a class="btn btn-ghost btn-block" href="login">Log in</a>
  </div>
  <p class="hint"><?= e(TRIAL_DAYS) ?>-day free trial — 20 links total, no card required.</p>
<?php endif; ?>
</div>

<section id="pricing">
  <div class="section-head">
    <h2>Simple, honest pricing</h2>
    <p>Start free. Upgrade when you outgrow it.</p>
  </div>
  <div class="plans">
    <?php render_plans($user ? $user['plan'] : null); ?>
  </div>
</section>

<?php render_footer(); ?>
