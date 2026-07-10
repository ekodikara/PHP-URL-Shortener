<?php
/*
 * Snip — bootstrap. Include this at the top of every page/endpoint.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/../config.php';

// Baseline security headers on every response (the redirect/api endpoints that
// don't render the layout still go through bootstrap, so this covers them all).
if (!headers_sent()) {
    header('X-Frame-Options: DENY');                       // clickjacking
    header('X-Content-Type-Options: nosniff');             // MIME sniffing
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: frame-ancestors 'none'");
    if (REQUEST_HTTPS) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Hardened session cookie.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => REQUEST_HTTPS,
    ));
    session_name('snipsess');
    session_start();
}

// Session idle (2h) + absolute (12h) timeout for logged-in sessions.
if (!empty($_SESSION['uid'])) {
    $now = time();
    $idle = isset($_SESSION['last_seen']) ? $now - (int) $_SESSION['last_seen'] : 0;
    $age  = isset($_SESSION['login_at']) ? $now - (int) $_SESSION['login_at'] : 0;
    if ($idle > 7200 || $age > 43200) {
        $_SESSION = array();
        session_regenerate_id(true);
    } else {
        $_SESSION['last_seen'] = $now;
    }
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/urls.php';
require __DIR__ . '/tokens.php';
require __DIR__ . '/security.php';
require __DIR__ . '/domains.php';
