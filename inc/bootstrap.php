<?php
/*
 * Snip — bootstrap. Include this at the top of every page/endpoint.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

/*
 * Global failure boundary. An uncaught exception or fatal error must never
 * leak a stack trace or a blank/half-rendered 500 — return a clean themed page
 * (or JSON for XHR) and log the details server-side. Registered before the rest
 * of the app loads so it also covers config/DB failures.
 */
function snip_fail_response()
{
    if (headers_sent()) {
        return; // output already started — can't safely replace it
    }
    http_response_code(500);
    $xhr = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($xhr) {
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"Something went wrong on our end. Please try again."}';
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    // Self-contained (no external CSS): the failure may be exactly that the app
    // couldn't load. Neutral palette matching the site.
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Something went wrong · Snip</title><style>'
        . 'html{color-scheme:light dark}body{margin:0;min-height:100vh;display:grid;place-items:center;'
        . 'font-family:ui-sans-serif,system-ui,sans-serif;background:#f4f4f3;color:#18191c}'
        . '@media(prefers-color-scheme:dark){body{background:#14151a;color:#eceef2}}'
        . '.b{text-align:center;padding:32px;max-width:420px}.c{font:700 .72rem/1 ui-monospace,monospace;'
        . 'letter-spacing:.12em;color:#fff;background:#c1361c;display:inline-block;padding:3px 9px;border-radius:4px}'
        . 'h1{font-size:1.5rem;margin:14px 0 6px;text-transform:uppercase}p{color:#6b7077;margin:0 0 18px}'
        . 'a{color:#c1361c;font-weight:700;text-decoration:none}</style></head><body><div class="b">'
        . '<div class="c">500</div><h1>Something went wrong</h1>'
        . '<p>An unexpected error occurred on our end. Please try again in a moment.</p>'
        . '<a href="/">Back to Snip</a></div></body></html>';
}
set_exception_handler(function ($e) {
    error_log('Snip uncaught exception: ' . $e);
    snip_fail_response();
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        error_log('Snip fatal error: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        snip_fail_response();
    }
});

require __DIR__ . '/../config.php';

// Baseline security headers on every response (the redirect/api endpoints that
// don't render the layout still go through bootstrap, so this covers them all).
if (!headers_sent()) {
    header('X-Frame-Options: DENY');                       // clickjacking
    header('X-Content-Type-Options: nosniff');             // MIME sniffing
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Content-Security-Policy: lock sources to self + the known third parties
    // (Google Fonts, cdnjs qrcode, Turnstile, reCAPTCHA). 'unsafe-inline' is
    // still needed for the theme's inline <head> script and the inline style
    // attributes; everything else is constrained. object/base/frame-ancestors
    // are locked down hard.
    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://challenges.cloudflare.com https://www.google.com https://www.gstatic.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "frame-src https://challenges.cloudflare.com https://www.google.com; "
        . "form-action 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'"
    );
    if (REQUEST_HTTPS) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Hardened session cookie. Endpoints on the anonymous hot path (the redirect
// resolver) define SNIP_SKIP_SESSION before including this to avoid the
// per-request session file I/O they don't need.
if (session_status() !== PHP_SESSION_ACTIVE && !defined('SNIP_SKIP_SESSION')) {
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => REQUEST_HTTPS,
    ));
    session_name('snipsess');
    // Shared, DB-backed sessions for multi-instance deployments (opt-in). Must be
    // registered before session_start(). Requires the `sessions` table (migration 003).
    if (defined('SESSION_DRIVER') && SESSION_DRIVER === 'db') {
        require_once __DIR__ . '/session.php';
        session_set_save_handler(new DbSessionHandler($pdo, SESSION_TABLE), true);
    }
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
require __DIR__ . '/mail.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/urls.php';
require __DIR__ . '/tokens.php';
require __DIR__ . '/security.php';
require __DIR__ . '/content_filter.php';
require __DIR__ . '/domains.php';
require __DIR__ . '/geoip.php';
require __DIR__ . '/analytics.php';
