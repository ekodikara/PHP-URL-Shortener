<?php
/*
 * Snip — registration.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (current_user()) {
    redirect_to('dashboard');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = isset($_POST['email']) ? $_POST['email'] : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    // Throttle account creation per IP.
    if (!rate_limit($pdo, 'register:' . client_ip(), 5, 3600)) {
        log_security_event($pdo, 'rate_limited', 'register');
        http_response_code(429);
        $error = 'Too many sign-up attempts. Please try again later.';
    } elseif (($why = form_guard_check($pdo)) !== '') {
        // Looks automated — block and record it.
        log_security_event($pdo, 'bot_blocked', 'register:' . $why);
        http_response_code(403);
        $error = 'We couldn\'t verify your request. Please enable JavaScript and try again.';
    } elseif (($gate = captcha_gate($pdo, 'register')) !== '') {
        http_response_code(403);
        $error = $gate === 'captcha'
            ? 'Please complete the verification below and try again.'
            : 'Suspicious activity detected. Please try again later.';
    } elseif (is_disposable_email($email)) {
        // Throwaway inboxes are a bot-signup vector — require a real address.
        log_security_event($pdo, 'bot_blocked', 'register:disposable_email');
        http_response_code(422);
        $error = 'Please use a permanent email address — disposable or temporary email providers aren\'t allowed.';
    } else {
        list($uid, $error) = register_user($pdo, $email, $password);
        if ($uid) {
            log_security_event($pdo, 'register', $email, $uid);
            establish_session($uid);
            send_verification_email($pdo, $uid, $email);
            set_flash('success', 'Welcome to ' . APP_NAME . '! Your free account is ready. Check your email to verify your address.');
            redirect_to('dashboard');
        }
    }
}

render_header('Create account');
?>
<div class="auth-wrap">
  <div class="card glass">
    <h1>Create your account</h1>
    <p class="sub">Free forever — <?= e($GLOBALS['PLANS']['free']['url_limit']) ?> links a month, no card required.</p>

    <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>

    <form method="post" action="register">
      <?= csrf_field() ?>
      <?= form_guard_fields() ?>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" minlength="8" required aria-describedby="password-hint">
        <p class="hint" id="password-hint">At least 8 characters.</p>
      </div>
      <?php if (captcha_needed($pdo)): ?><?= recaptcha_block() ?><?php endif; ?>
      <button class="btn btn-solid btn-block" type="submit">Create account</button>
    </form>

    <p class="auth-alt">Already have an account? <a href="login">Log in</a></p>
  </div>
</div>
<?php render_footer(); ?>
