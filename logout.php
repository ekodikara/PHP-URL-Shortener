<?php
/*
 * Snip — logout. CSRF-protected via a per-session token so a cross-site
 * request can't force-logout the user.
 */
require __DIR__ . '/inc/bootstrap.php';

$token = isset($_POST['csrf']) ? $_POST['csrf'] : (isset($_GET['t']) ? $_GET['t'] : '');
if (current_user() && hash_equals(csrf_token(), (string) $token)) {
    logout_user();
}
redirect_to('/');
