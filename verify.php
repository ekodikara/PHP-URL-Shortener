<?php
/*
 * Snip — confirm an email address from a verification link (?t=<token>), or
 * resend the link (?resend=1, logged-in). Single-use, 24h token.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$u = current_user();

// Resend flow (must be logged in).
if (isset($_GET['resend'])) {
    if (!$u) {
        redirect_to('login');
    }
    if (!empty($u['email_verified'])) {
        set_flash('info', 'Your email is already verified.');
    } elseif (!rate_limit($pdo, 'verifysend:' . $u['id'], 5, 3600)) {
        set_flash('error', 'Too many requests. Please try again in a little while.');
    } else {
        send_verification_email($pdo, $u['id'], $u['email']);
        set_flash('success', 'Verification email sent — check your inbox.');
    }
    redirect_to('dashboard');
}

// Confirm flow.
$token = (string) ($_GET['t'] ?? '');
$uid = $token !== '' ? consume_auth_token($pdo, $token, 'verify') : null;
if ($uid) {
    $pdo->prepare('UPDATE users SET email_verified = 1 WHERE id = ?')->execute(array($uid));
    set_flash('success', 'Email verified — thanks!');
    redirect_to($u ? 'dashboard' : 'login');
}

render_header('Verify email');
?>
<div class="auth-wrap">
  <div class="card glass">
    <h1>Link expired</h1>
    <p class="sub">This verification link is invalid or has expired.<?php if ($u): ?> <a href="verify?resend=1">Send a new one</a>.<?php endif; ?></p>
    <a class="btn btn-ghost btn-block" href="<?= $u ? 'dashboard' : 'login' ?>">Continue</a>
  </div>
</div>
<?php render_footer(); ?>
