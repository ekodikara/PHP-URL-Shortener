<?php
/*
 * Snip — login.
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

    // Throttle login attempts per IP and per account.
    if (!rate_limit($pdo, 'login:' . client_ip(), 10, 900)
        || !rate_limit($pdo, 'login_acct:' . strtolower(trim($email)), 8, 900)) {
        log_security_event($pdo, 'rate_limited', 'login:' . $email);
        http_response_code(429);
        $error = 'Too many login attempts. Please try again in a few minutes.';
    } elseif (($why = form_guard_check($pdo)) !== '') {
        log_security_event($pdo, 'bot_blocked', 'login:' . $why);
        http_response_code(403);
        $error = 'We couldn\'t verify your request. Please enable JavaScript and try again.';
    } elseif (($gate = captcha_gate($pdo, 'login')) !== '') {
        http_response_code(403);
        $error = $gate === 'captcha'
            ? 'Please complete the verification below and try again.'
            : 'Suspicious activity detected. Please try again later.';
    } else {
        list($uid, $error) = login_user($pdo, $email, $password);
        if ($uid) {
            log_security_event($pdo, 'login_ok', $email, $uid);
            establish_session($uid);
            redirect_to('dashboard');
        } else {
            log_security_event($pdo, 'login_fail', $email);
        }
    }
}

render_header('Log in');
?>
<div class="auth-wrap">
  <div class="card glass">
    <h1>Welcome back</h1>
    <p class="sub">Log in to manage your links.</p>

    <?php if ($error): ?><div class="form-error"><?= e($error) ?></div><?php endif; ?>

    <form method="post" action="login">
      <?= csrf_field() ?>
      <?= form_guard_fields() ?>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>
      <?php if (captcha_needed($pdo)): ?><?= recaptcha_block() ?><?php endif; ?>
      <button class="btn btn-solid btn-block" type="submit">Log in</button>
    </form>

    <p class="auth-alt">New here? <a href="register">Create an account</a></p>
  </div>
</div>
<?php render_footer(); ?>
