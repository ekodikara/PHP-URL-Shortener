<?php
/*
 * Snip — set a new password from a reset link (?t=<token>). Single-use token,
 * rate-limited, same 8–200 char policy as registration.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$token = (string) ($_GET['t'] ?? ($_POST['t'] ?? ''));
$error = '';
// Read-only validity check for display (does NOT consume the token).
$valid = $token !== '' ? (peek_auth_token($pdo, $token, 'reset') !== null) : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!rate_limit($pdo, 'reset:' . client_ip(), 10, 3600)) {
        http_response_code(429);
        $error = 'Too many attempts. Please try again in a little while.';
    } else {
        $pw = (string) ($_POST['password'] ?? '');
        if (strlen($pw) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (strlen($pw) > 200) {
            $error = 'Password must be at most 200 characters.';
        } else {
            $uid = consume_auth_token($pdo, $token, 'reset');
            if (!$uid) {
                $error = 'This reset link is invalid or has expired. Request a new one.';
                $valid = false;
            } else {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute(array(password_hash($pw, PASSWORD_DEFAULT), $uid));
                // Invalidate any other outstanding reset tokens for this user.
                $pdo->prepare('UPDATE auth_tokens SET used = 1 WHERE user_id = ? AND kind = "reset" AND used = 0')
                    ->execute(array($uid));
                log_security_event($pdo, 'password_reset', 'uid=' . $uid, $uid);
                set_flash('success', 'Password updated — you can log in now.');
                redirect_to('login');
            }
        }
    }
}

render_header('Set a new password');
?>
<div class="auth-wrap">
  <div class="card glass">
  <?php if ($valid): ?>
    <h1>Set a new password</h1>
    <p class="sub">Choose a new password for your account.</p>
    <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="reset">
      <?= csrf_field() ?>
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <div class="field">
        <label for="password">New password</label>
        <input type="password" id="password" name="password" minlength="8" required autofocus aria-describedby="pw-hint">
        <p class="hint" id="pw-hint">At least 8 characters.</p>
      </div>
      <button class="btn btn-solid btn-block" type="submit">Update password</button>
    </form>
  <?php else: ?>
    <h1>Link expired</h1>
    <p class="sub"><?= $error !== '' ? e($error) : 'This reset link is invalid or has expired.' ?></p>
    <a class="btn btn-solid btn-block" href="forgot">Request a new link</a>
  <?php endif; ?>
  </div>
</div>
<?php render_footer(); ?>
