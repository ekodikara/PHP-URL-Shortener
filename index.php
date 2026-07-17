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

<?php
$bench_long  = 'example.com/2026/spring/product-launch/announcement?ref=newsletter&utm_campaign=q2';
$bench_short = preg_replace('|^https?://|', '', BASE_HREF) . 'q2-launch';
$bench_cut   = max(0, strlen($bench_long) - strlen($bench_short));
?>
<div class="bench glass" role="img" aria-label="Snip cuts a long URL down to the short link /q2-launch">
  <div class="bench-rule" aria-hidden="true"><span>0</span><span>20</span><span>40</span><span>60</span><span>80</span></div>
  <div class="ribbon" aria-hidden="true">
    <span class="ribbon-len"><?= strlen($bench_long) ?> ch</span>
    <span class="ribbon-url"><?= e($bench_long) ?></span>
  </div>
  <div class="cutline" aria-hidden="true"><span class="blade">✂</span></div>
  <div class="stub" aria-hidden="true">
    <span class="stub-tab">Snip</span>
    <span class="stub-code"><?= e($bench_short) ?></span>
    <span class="stub-len"><?= strlen($bench_short) ?> ch</span>
  </div>
  <?php if ($bench_cut > 0): ?><p class="bench-note" aria-hidden="true"><b><?= $bench_cut ?> characters</b> shorter.</p><?php endif; ?>
</div>

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

<?php if (!$user): ?>
<section id="pricing">
  <div class="section-head">
    <h2>Simple, honest pricing</h2>
    <p>Start free. Upgrade when you outgrow it.</p>
  </div>
  <div class="plans">
    <?php render_plans(null); ?>
  </div>
</section>
<?php endif; ?>

<?php render_footer(); ?>
