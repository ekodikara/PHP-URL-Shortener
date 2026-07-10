<?php
/*
 * Snip — Enterprise "contact sales" page + inquiry form.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$sent = false;
$error = '';
$name = $email = $company = $message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name    = trim((string) ($_POST['name'] ?? ''));
    $email   = trim((string) ($_POST['email'] ?? ''));
    $company = trim((string) ($_POST['company'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));

    // Throttle + bot-guard the public form.
    if (!rate_limit($pdo, 'lead:' . client_ip(), 5, 3600)) {
        http_response_code(429);
        $error = 'Too many submissions. Please try again later.';
    } elseif (!empty($_POST['hp_url'])) {
        log_security_event($pdo, 'bot_blocked', 'enterprise_lead:honeypot');
        $error = 'We couldn\'t verify your submission — please reload the page and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
        $error = 'Please provide your name and a valid work email.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO enterprise_leads (ts, name, email, company, message, ip) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute(array(time(), mb_substr($name, 0, 120), mb_substr($email, 0, 190), mb_substr($company, 0, 120), mb_substr($message, 0, 2000), client_ip()));
        log_security_event($pdo, 'enterprise_lead', $email);
        $sent = true;
    }
}

render_header('Enterprise');
?>
<section class="hero hero-sub">
  <h1>Snip for <span class="grad">teams</span></h1>
  <p>Custom branded domains, single sign-on (SAML &amp; OIDC), unlimited everything,
     and the option to run Snip in your own environment.</p>
</section>

<div class="card glass contact-card">
<?php if ($sent): ?>
  <div class="form-success">
    <div class="success-glyph" aria-hidden="true">📨</div>
    <h2>Thanks — we'll be in touch</h2>
    <p class="sub">Our team will reach out to <strong><?= e($email) ?></strong> shortly.</p>
    <a class="btn btn-ghost" href="/">Back home</a>
  </div>
<?php else: ?>
  <h2>Talk to sales</h2>
  <p class="sub">Tell us a bit about your team and we'll tailor a plan.</p>
  <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="enterprise">
    <?= csrf_field() ?>
    <div class="hp-field" aria-hidden="true"><label>Leave blank<input type="text" name="hp_url" tabindex="-1" autocomplete="off"></label></div>
    <div class="field"><label for="name">Your name</label><input type="text" id="name" name="name" value="<?= e($name) ?>" required></div>
    <div class="field"><label for="email">Work email</label><input type="email" id="email" name="email" value="<?= e($email) ?>" required></div>
    <div class="field"><label for="company">Company</label><input type="text" id="company" name="company" value="<?= e($company) ?>"></div>
    <div class="field"><label for="message">What do you need?</label>
      <textarea id="message" name="message" rows="4"><?= e($message) ?></textarea>
    </div>
    <button class="btn btn-solid btn-block" type="submit">Send inquiry</button>
  </form>
<?php endif; ?>
</div>
<?php render_footer(); ?>
