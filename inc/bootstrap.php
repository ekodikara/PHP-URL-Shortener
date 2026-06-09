<?php
/*
 * Snip — bootstrap. Include this at the top of every page/endpoint.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/../config.php';

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

require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/urls.php';
require __DIR__ . '/tokens.php';
require __DIR__ . '/security.php';
require __DIR__ . '/domains.php';
