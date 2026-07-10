<?php
/*
 * Snip — request a password reset. Anti-enumeration: the response is identical
 * whether or not the email has an account.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (current_user()) {
    redirect_to('dashboard');
}

$error = '';
$sent  = false;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string) ($_POST['email'] ?? ''));

    if (!rate_limit($pdo, 'forgot:' . client_ip(), 5, 3600)) {
        http_response_code(429);
        $error = 'Too many requests. Please try again in a little while.';
    } elseif (($why = form_guard_check($pdo)) !== '') {
        http_response_code(403);
        $error = 'We couldn\'t verify your request. Please enable JavaScript and try again.';
    } else {
        // Only act if the account exists — but ALWAYS show the same result.
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $st = $pdo->prepare('SELECT id, email FROM users WHERE email = ?');
            $st->execute(array($email));
            if ($row = $st->fetch()) {
                send_password_reset_email($pdo, $row['id'], $row['email']);
            }
        }
        $sent = true;
    }
}

render_header('Reset password');
?>
<div class="auth-wrap">
  <div class="card glass">
  <?php if ($sent): ?>
    <h1>Check your email</h1>
    <p class="sub">If an account exists for that address, we've sent a link to reset your password. It expires in 1 hour.</p>
    <a class="btn btn-ghost btn-block" href="login">Back to log in</a>
  <?php else: ?>
    <h1>Reset password</h1>
    <p class="sub">Enter your account email and we'll send a reset link.</p>
    <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="forgot">
      <?= csrf_field() ?>
      <?= form_guard_fields() ?>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus>
      </div>
      <button class="btn btn-solid btn-block" type="submit">Send reset link</button>
    </form>
    <p class="auth-alt">Remembered it? <a href="login">Log in</a></p>
  <?php endif; ?>
  </div>
</div>
<?php render_footer(); ?>
