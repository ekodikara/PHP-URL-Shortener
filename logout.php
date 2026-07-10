<?php
/*
 * Snip — logout. CSRF-protected via a per-session token so a cross-site
 * request can't force-logout the user.
 */
require __DIR__ . '/inc/bootstrap.php';

// POST-only + CSRF: keeps the token out of URLs/referers/logs and stops a
// cross-site GET from force-logging-out the user.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && current_user()
    && hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
    logout_user();
}
redirect_to('/');
