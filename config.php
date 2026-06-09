<?php
/*
 * Snip — a modern URL shortener.
 * Central configuration: database connection, plans, and constants.
 */

// ---------------------------------------------------------------------------
// Database (credentials come from the environment so nothing is hardcoded)
// ---------------------------------------------------------------------------
define('DB_NAME', getenv('DB_NAME') ?: 'shortener');
define('DB_USER', getenv('DB_USER') ?: 'shortener');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '');
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASSWORD,
        array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        )
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('Service temporarily unavailable.');
}

// ---------------------------------------------------------------------------
// App
// ---------------------------------------------------------------------------
define('APP_NAME', 'Snip');
define('APP_TAGLINE', 'Short links, sharp.');

// Public base URL (with trailing slash). Set SITE_HOST in production to a
// trusted value; otherwise we derive it from the request host (sanitized).
$__host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
// Behind a TLS-terminating proxy (e.g. Caddy) PHP sees plain HTTP; honour the
// proxy's X-Forwarded-Proto so generated URLs and the Secure cookie flag are right.
$__xfp = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) : '';
define('REQUEST_HTTPS', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $__xfp === 'https');
$__scheme = REQUEST_HTTPS ? 'https' : 'http';
define('SITE_HOST', getenv('SITE_HOST') ?: '');
define('BASE_HREF', SITE_HOST !== '' ? rtrim(SITE_HOST, '/') . '/' : $__scheme . '://' . $__host . '/');

// Proxies whose X-Forwarded-For we trust for the real client IP. Empty = none
// (use REMOTE_ADDR directly). 'private' = trust when REMOTE_ADDR is a private/
// reserved range (correct for Caddy/ALB on a private network). Or a comma list
// of exact proxy IPs.
define('TRUSTED_PROXIES', getenv('TRUSTED_PROXIES') ?: '');

// Characters used to build random short codes.
define('ALLOWED_CHARS', '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
define('CODE_LENGTH', 6);

// New accounts start on a time-limited trial (plan key 'free').
define('TRIAL_DAYS', 30);

// --- Stripe ----------------------------------------------------------------
define('STRIPE_SECRET_KEY', getenv('STRIPE_SECRET_KEY') ?: '');
define('STRIPE_PUBLISHABLE_KEY', getenv('STRIPE_PUBLISHABLE_KEY') ?: '');
define('STRIPE_WEBHOOK_SECRET', getenv('STRIPE_WEBHOOK_SECRET') ?: '');
define('STRIPE_API_VERSION', '2026-05-27.dahlia');

// Yearly billing discount (paid annually). 0.12 = 12% off 12 months.
define('YEARLY_DISCOUNT', 0.12);

// --- Bot protection (Cloudflare Turnstile, optional) -----------------------
// If both keys are set (in .env), Turnstile is enforced on register/login on
// top of the always-on honeypot + JS-proof + timing + rate-limit checks.
define('TURNSTILE_SITE_KEY', getenv('TURNSTILE_SITE_KEY') ?: '');
define('TURNSTILE_SECRET', getenv('TURNSTILE_SECRET') ?: '');

// --- Adaptive Google reCAPTCHA --------------------------------------------
// Shown ONLY to clients we flag as suspicious (spam behaviour or VPN/proxy),
// on login, register, shortening, and API-key creation.
// Defaults are Google's public TEST keys (always pass; show a "testing only"
// widget). Override in .env with real keys for production.
define('RECAPTCHA_SITE_KEY', getenv('RECAPTCHA_SITE_KEY') ?: '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MUExBuc');
define('RECAPTCHA_SECRET', getenv('RECAPTCHA_SECRET') ?: '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe');
// Optional IPQualityScore key enables VPN/proxy/Tor detection (no key = behaviour-only).
define('IPQS_API_KEY', getenv('IPQS_API_KEY') ?: '');
// An IP is "suspicious" after this many bad events in the window.
define('SUSPICION_EVENTS', 5);
define('SUSPICION_WINDOW', 3600);

// Admins (comma-separated emails) get the /admin panel. Also settable via the
// users.is_admin column.
define('ADMIN_EMAILS', getenv('ADMIN_EMAILS') ?: 'alice@example.com');

// ---------------------------------------------------------------------------
// Plans
//   url_limit         : max new links per limit_period (null = unlimited)
//   monthly_visit_cap : max redirects/month across ALL the user's links (null = unlimited)
//   custom_slugs      : max custom-named links allowed (0 = random codes only)
// ---------------------------------------------------------------------------
// 'is_trial' plans are time-limited (TRIAL_DAYS from the user's signup date).
// While active they grant the listed limits; once expired the user can no
// longer create NEW links (existing links keep redirecting) until they upgrade.
$GLOBALS['PLANS'] = array(
    'free' => array(
        'name'         => 'Free trial',
        'price'        => 0,
        'url_limit'         => 20,    // 20 links TOTAL for the whole trial
        'limit_period'      => 'total',
        'monthly_visit_cap' => 50,    // 50 redirects/month across all links
        'custom_slugs'      => 0,
        'is_trial'          => true,
        'blurb'        => 'Try it free for ' . TRIAL_DAYS . ' days.',
    ),
    'pro' => array(
        'name'         => 'Pro',
        'price'        => 7,
        'url_limit'         => 50,    // 50 links per month
        'limit_period'      => 'month',
        'monthly_visit_cap' => null,  // unlimited visits
        'custom_slugs'      => 0,
        'is_trial'          => false,
        'blurb'        => 'For people who share a lot.',
    ),
    'premium' => array(
        'name'         => 'Premium',
        'price'        => 12,
        'url_limit'         => null,  // unlimited
        'limit_period'      => 'total',
        'monthly_visit_cap' => null,  // unlimited visits
        'custom_slugs'      => 100,
        'is_trial'          => false,
        'blurb'        => 'Unlimited links, your own names.',
    ),
);

function plan_config($plan)
{
    return isset($GLOBALS['PLANS'][$plan]) ? $GLOBALS['PLANS'][$plan] : $GLOBALS['PLANS']['free'];
}

// Paths that must never be claimed as a custom slug (they are real routes).
$GLOBALS['RESERVED_SLUGS'] = array(
    'index', 'register', 'login', 'logout', 'dashboard', 'upgrade',
    'shorten', 'redirect', 'delete', 'assets', 'inc', 'config',
    'favicon', 'robots', 'api', 'admin', 'cache', 'me', 'account', 'pricing',
    'checkout', 'billing', 'billing-success', 'billing-portal', 'stripe-webhook',
    'connect', 'mcp', 'tokens', 'admin', 'link-toggle',
);
