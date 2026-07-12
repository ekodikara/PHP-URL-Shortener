<?php
/*
 * Snip — shared page shell (flat neutral theme with light + dark modes).
 */

function render_header($title = '')
{
    $u = current_user();
    $full_title = $title !== '' ? $title . ' · ' . APP_NAME : APP_NAME . ' — ' . APP_TAGLINE;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($full_title) ?></title>
<script>/* set theme before paint (no flash of wrong mode) */(function(){try{var t=localStorage.getItem('snip-theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@600;700;800;900&family=Archivo+Expanded:wght@600;700;800;900&family=Hanken+Grotesk:wght@400;500;600&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= e(@filemtime(__DIR__ . '/../assets/style.css') ?: '1') ?>">
<?= turnstile_script() ?>
</head>
<body>
<header class="nav">
  <a class="brand" href="/">
    <span class="brand-mark">✂</span><span class="brand-name"><?= e(APP_NAME) ?></span>
  </a>
  <nav class="nav-links">
    <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Switch between light and dark mode" title="Light / dark">
      <span class="moon" aria-hidden="true">☾</span><span class="sun" aria-hidden="true">☀</span>
    </button>
    <a href="/#pricing">Pricing</a>
    <?php if ($u): ?>
      <a href="dashboard">Dashboard</a>
      <?php if (is_on_trial($u)): ?>
        <span class="plan-pill plan-free"><?= trial_active($u) ? 'Trial · ' . trial_days_left($u) . 'd' : 'Trial ended' ?></span>
      <?php else: ?>
        <span class="plan-pill plan-<?= e($u['plan']) ?>"><?= e(plan_config($u['plan'])['name']) ?></span>
      <?php endif; ?>
      <form class="nav-signout" method="post" action="logout"><?= csrf_field() ?><button class="btn btn-ghost" type="submit">Sign out</button></form>
    <?php else: ?>
      <a href="login">Log in</a>
      <a class="btn btn-solid" href="register">Get started</a>
    <?php endif; ?>
  </nav>
</header>

<?php $flashes = take_flashes(); if ($flashes): ?>
<div class="flash-stack">
  <?php foreach ($flashes as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<main class="wrap">
<?php
}

/** Monthly/Yearly billing interval toggle (drives the pricing cards via JS). */
function render_billing_toggle()
{
    ?>
    <div class="billing-toggle" id="billing-toggle">
      <button type="button" class="bt-opt is-active" data-interval="month">Monthly</button>
      <button type="button" class="bt-opt" data-interval="year">Yearly <span class="bt-save">save 12%</span></button>
    </div>
    <?php
}

/** Render the three pricing cards. $current = the viewer's plan key (or null). */
function render_plans($current = null)
{
    $features = array(
        'free'       => array(TRIAL_DAYS . '-day free trial', '20 links total', '10 visits / month', 'QR codes + click stats'),
        'pro'        => array('50 links / month', 'Unlimited visits', 'QR codes + click stats', 'Priority redirects'),
        'premium'    => array('Unlimited links', 'Unlimited visits', '100 custom link names', 'Everything in Pro'),
        'enterprise' => array('Everything in Premium', 'Custom branded domains', 'SSO (SAML & OIDC)', 'Unlimited custom names'),
    );
    $u = current_user();
    foreach ($GLOBALS['PLANS'] as $key => $p):
        $featured = ($key === 'pro');
        $is_current = ($current === $key);
        $is_trial = !empty($p['is_trial']);
        $is_contact = !empty($p['contact']);
    ?>
    <div class="plan glass<?= $featured ? ' featured' : '' ?>">
      <?php if ($featured): ?><span class="tag">Most popular</span><?php endif; ?>
      <h3><?= e($p['name']) ?></h3>
      <p class="blurb"><?= e($p['blurb']) ?></p>
      <?php if ($is_contact): ?>
        <div class="price" style="font-size:1.9rem">Custom</div>
        <p class="save-note" style="visibility:visible">Tailored to your team</p>
      <?php elseif ($is_trial): ?>
        <div class="price">$0<span>/<?= TRIAL_DAYS ?> days</span></div>
      <?php else: ?>
        <div class="price"
             data-monthly="<?= e(money(plan_amount($key, 'month'))) ?>"
             data-yearly="<?= e(money(plan_amount($key, 'year'))) ?>">
          $<span class="js-price"><?= e(money(plan_amount($key, 'month'))) ?></span><span class="js-unit">/mo</span>
        </div>
        <?php $yearly_savings = plan_amount($key, 'month') * 12 - plan_amount($key, 'year'); ?>
        <p class="save-note js-save-note">Billed annually — save $<?= e(money($yearly_savings)) ?>/yr</p>
      <?php endif; ?>
      <ul>
        <?php foreach ($features[$key] as $feat): ?><li><?= e($feat) ?></li><?php endforeach; ?>
      </ul>
      <?php if ($is_contact): ?>
        <a class="btn <?= $is_current ? 'btn-ghost' : 'btn-solid' ?> btn-block" href="enterprise"><?= $is_current ? 'Your plan · contact us' : 'Contact sales' ?></a>
      <?php elseif ($is_current && $is_trial): ?>
        <button class="btn btn-ghost btn-block" disabled><?= trial_active($u) ? trial_days_left($u) . ' day' . (trial_days_left($u) === 1 ? '' : 's') . ' left' : 'Trial ended' ?></button>
      <?php elseif ($is_current): ?>
        <form method="post" action="billing-portal"><?= csrf_field() ?><button class="btn btn-ghost btn-block" type="submit">Manage billing</button></form>
      <?php elseif ($is_trial): ?>
        <?php if ($u): ?>
          <button class="btn btn-ghost btn-block" disabled>New accounts only</button>
        <?php else: ?>
          <a class="btn btn-solid btn-block" href="register">Start free trial</a>
        <?php endif; ?>
      <?php elseif ($u): ?>
        <form method="post" action="checkout">
          <?= csrf_field() ?>
          <input type="hidden" name="plan" value="<?= e($key) ?>">
          <input type="hidden" name="interval" value="month" class="js-interval">
          <button class="btn btn-solid btn-block" type="submit">Choose <?= e($p['name']) ?></button>
        </form>
      <?php else: ?>
        <a class="btn btn-solid btn-block" href="register">Get started</a>
      <?php endif; ?>
    </div>
    <?php endforeach;
}

function render_footer()
{
    ?>
</main>
<footer class="foot">
  <span><?= e(APP_NAME) ?> — <?= e(APP_TAGLINE) ?></span>
  <span class="foot-links"><a href="terms">Terms</a> · <a href="report">Report abuse</a></span>
  <span class="foot-dim">Measure. Cut. Share.</span>
</footer>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU" crossorigin="anonymous"></script>
<script src="assets/app.js?v=<?= e(@filemtime(__DIR__ . '/../assets/app.js') ?: '1') ?>"></script>
</body>
</html>
<?php
}

/**
 * Themed error page for user-facing dead ends (bad short links, disabled
 * links, visit caps). Replaces bare die() text so public visitors always
 * land on a branded page. Sets the status code, renders, and exits.
 */
function render_error_page($status, $title, $message)
{
    http_response_code((int) $status);
    render_header($title);
    ?>
<div class="auth-wrap">
  <div class="card glass error-card">
    <div class="error-code"><?= (int) $status ?></div>
    <h1 style="font-family:var(--font-display);font-weight:800;text-transform:uppercase;font-size:1.6rem;margin:0 0 6px"><?= e($title) ?></h1>
    <p class="sub"><?= e($message) ?></p>
    <a class="btn btn-solid" href="/">Go to <?= e(APP_NAME) ?></a>
  </div>
</div>
    <?php
    render_footer();
    exit;
}
