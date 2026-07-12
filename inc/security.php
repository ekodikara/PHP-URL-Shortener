<?php
/*
 * Snip — security layer: audit logging, rate limiting, and bot defenses.
 */

/**
 * Best-effort client IP. If REMOTE_ADDR is a trusted proxy (per TRUSTED_PROXIES),
 * use the right-most X-Forwarded-For entry — the address the proxy itself
 * observed, which a client can't forge through a single trusted hop.
 */
function client_ip()
{
    $remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if (TRUSTED_PROXIES === '' || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $remote;
    }

    $trusted = false;
    if (TRUSTED_PROXIES === 'private') {
        // filter_var returns false for private/reserved IPs → those are our proxy.
        $trusted = ($remote !== '' && !filter_var(
            $remote,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ));
    } else {
        $list = array_filter(array_map('trim', explode(',', TRUSTED_PROXIES)));
        $trusted = in_array($remote, $list, true);
    }

    if ($trusted) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim(end($parts));
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return $remote;
}

function user_agent()
{
    return isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
}

/** Record a security-relevant event (persisted in the DB). */
function log_security_event(PDO $pdo, $event, $detail = '', $user_id = null)
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO security_log (ts, event, ip, user_id, detail, user_agent) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array(
            time(), $event, client_ip(), $user_id,
            mb_substr((string) $detail, 0, 255),
            mb_substr(user_agent(), 0, 255),
        ));
    } catch (Exception $e) {
        error_log('security_log failed: ' . $e->getMessage());
    }
}

/**
 * Fixed-window rate limit. Returns true if the action is allowed (i.e. the
 * count after this hit is within $max), false if the limit is exceeded.
 */
function rate_limit(PDO $pdo, $key, $max, $window)
{
    $now = time();
    $cutoff = $now - $window;
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO rate_limits (rl_key, count, window_start) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE
               count = IF(window_start <= ?, 1, count + 1),
               window_start = IF(window_start <= ?, ?, window_start)'
        );
        $stmt->execute(array($key, $now, $cutoff, $cutoff, $now));
        $sel = $pdo->prepare('SELECT count FROM rate_limits WHERE rl_key = ?');
        $sel->execute(array($key));
        return ((int) $sel->fetchColumn()) <= $max;
    } catch (Exception $e) {
        error_log('rate_limit failed: ' . $e->getMessage());
        return true; // fail open — don't lock users out on a DB hiccup
    }
}

/** Reset a rate-limit counter (e.g. after a successful login). */
function rl_clear(PDO $pdo, $key)
{
    try {
        $pdo->prepare('DELETE FROM rate_limits WHERE rl_key = ?')->execute(array($key));
    } catch (Exception $e) { /* best effort */ }
}

// --- Bot defenses ------------------------------------------------------------

function turnstile_enabled()
{
    return TURNSTILE_SITE_KEY !== '' && TURNSTILE_SECRET !== '';
}

/** <script> tag for the Turnstile widget (empty when not configured). */
function turnstile_script()
{
    return turnstile_enabled()
        ? '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'
        : '';
}

/**
 * Hidden anti-bot fields for a form: honeypot, JS-proof, and (if configured)
 * the Turnstile widget. Also stamps the render time in the session.
 */
function form_guard_fields()
{
    $_SESSION['form_ts'] = time();
    $out  = '<div class="hp-field" aria-hidden="true">'
          . '<label>Leave this field empty<input type="text" name="hp_url" tabindex="-1" autocomplete="off"></label></div>';
    $out .= '<input type="hidden" name="js_ok" value="">';
    if (turnstile_enabled()) {
        $out .= '<div class="cf-turnstile" data-sitekey="' . e(TURNSTILE_SITE_KEY) . '" style="margin:10px 0"></div>';
    }
    return $out;
}

/**
 * Validate the anti-bot fields. Returns '' if the request looks human,
 * otherwise a short reason code ('honeypot' | 'too_fast' | 'no_js' | 'captcha').
 */
function form_guard_check(PDO $pdo)
{
    if (!empty($_POST['hp_url'])) {
        return 'honeypot';                 // hidden field filled → bot
    }
    $ts = isset($_SESSION['form_ts']) ? (int) $_SESSION['form_ts'] : 0;
    if ($ts && (time() - $ts) < 2) {
        return 'too_fast';                 // submitted faster than a human can type
    }
    if (!isset($_POST['js_ok']) || $_POST['js_ok'] !== '1') {
        return 'no_js';                    // JS never ran → scripted client
    }
    if (turnstile_enabled() && !turnstile_verify(isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '')) {
        return 'captcha';
    }
    return '';
}

function turnstile_verify($response)
{
    if ($response === '') {
        return false;
    }
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_POSTFIELDS     => http_build_query(array(
            'secret'   => TURNSTILE_SECRET,
            'response' => $response,
            'remoteip' => client_ip(),
        )),
    ));
    $body = curl_exec($ch);
    curl_close($ch);
    $json = json_decode((string) $body, true);
    return !empty($json['success']);
}

// --- Suspicion detection (spam behaviour + VPN/proxy) ------------------------

/** Count "bad" events from an IP within the suspicion window. */
function ip_spam_score(PDO $pdo, $ip)
{
    if ($ip === '') {
        return 0;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM security_log
              WHERE ip = ? AND ts >= ?
                AND event IN ('bot_blocked','rate_limited','login_fail','mcp_invalid_token')"
        );
        $stmt->execute(array($ip, time() - SUSPICION_WINDOW));
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/** Whether an IP is a VPN/proxy/Tor per IPQualityScore (cached 24h). No key → false. */
function ip_is_vpn(PDO $pdo, $ip)
{
    if (IPQS_API_KEY === '' || $ip === '') {
        return false;
    }
    try {
        $sel = $pdo->prepare('SELECT is_vpn, checked FROM ip_reputation WHERE ip = ?');
        $sel->execute(array($ip));
        $row = $sel->fetch();
        if ($row && (time() - (int) $row['checked']) < 86400) {
            return (bool) $row['is_vpn'];
        }
    } catch (Exception $e) { /* fall through to live lookup */ }

    $ch = curl_init('https://ipqualityscore.com/api/json/ip/' . rawurlencode(IPQS_API_KEY) . '/' . rawurlencode($ip) . '?strictness=1');
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4));
    $json = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    $vpn = !empty($json['vpn']) || !empty($json['proxy']) || !empty($json['tor'])
        || (isset($json['fraud_score']) && $json['fraud_score'] >= 85);
    try {
        $pdo->prepare('INSERT INTO ip_reputation (ip, is_vpn, checked) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE is_vpn = ?, checked = ?')
            ->execute(array($ip, $vpn ? 1 : 0, time(), $vpn ? 1 : 0, time()));
    } catch (Exception $e) { /* best effort */ }
    return $vpn;
}

/** Disposable/temporary email domains as a lookup set (loaded + cached once). */
function disposable_email_domains()
{
    static $set = null;
    if ($set !== null) {
        return $set;
    }
    $path = DISPOSABLE_EMAIL_LIST;
    $set = (is_string($path) && is_file($path) && is_readable($path) && function_exists('parse_hosts_blocklist'))
        ? parse_hosts_blocklist(file_get_contents($path))
        : array();
    return $set;
}

/**
 * Is $email from a known disposable/temporary provider? Checks the exact host
 * and its registrable domain (so subdomains of a throwaway provider are caught).
 */
function is_disposable_email($email)
{
    if (!BLOCK_DISPOSABLE_EMAIL) {
        return false;
    }
    $at = strrpos((string) $email, '@');
    if ($at === false) {
        return false;
    }
    $host = strtolower(trim(substr((string) $email, $at + 1)));
    if ($host === '') {
        return false;
    }
    $set = disposable_email_domains();
    if (isset($set[$host])) {
        return true;
    }
    if (function_exists('registrable_domain')) {
        $reg = registrable_domain($host);
        if ($reg !== $host && isset($set[$reg])) {
            return true;
        }
    }
    return false;
}

/** Is the current client suspicious (spammer or VPN/proxy)? */
function is_suspicious(PDO $pdo)
{
    $ip = client_ip();
    if (ip_spam_score($pdo, $ip) >= SUSPICION_EVENTS) {
        return true;
    }
    return ip_is_vpn($pdo, $ip);
}

// --- Google reCAPTCHA (adaptive) ---------------------------------------------

function recaptcha_enabled()
{
    return RECAPTCHA_SITE_KEY !== '' && RECAPTCHA_SECRET !== '';
}

/** Widget + script, rendered only where a challenge is needed. */
function recaptcha_block()
{
    if (!recaptcha_enabled()) {
        return '';
    }
    return '<div class="g-recaptcha" data-sitekey="' . e(RECAPTCHA_SITE_KEY) . '" style="margin:12px 0"></div>'
         . '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
}

function recaptcha_verify($response)
{
    if ($response === '') {
        return false;
    }
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_POSTFIELDS     => http_build_query(array(
            'secret'   => RECAPTCHA_SECRET,
            'response' => $response,
            'remoteip' => client_ip(),
        )),
    ));
    $json = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    return !empty($json['success']);
}

/** True if the current client should be shown a captcha challenge. */
function captcha_needed(PDO $pdo)
{
    return recaptcha_enabled() && is_suspicious($pdo);
}

/**
 * Adaptive gate for a sensitive action. Returns '' to allow, or a reason:
 *  'captcha' = suspicious + captcha missing/failed; 'blocked' = suspicious but
 *  no captcha configured to challenge with.
 */
function captcha_gate(PDO $pdo, $tag = '')
{
    if (!is_suspicious($pdo)) {
        return '';
    }
    if (!recaptcha_enabled()) {
        log_security_event($pdo, 'suspicious_blocked', $tag);
        return 'blocked';
    }
    $resp = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';
    if (recaptcha_verify($resp)) {
        return '';
    }
    log_security_event($pdo, 'captcha_required', $tag);
    return 'captcha';
}

// --- Destination URL safety (Google Safe Browsing) ---------------------------

function safe_browsing_enabled()
{
    return SAFE_BROWSING_API_KEY !== '';
}

/**
 * Threat type for a destination URL per Google Safe Browsing v4, or '' when
 * clean/unknown. Verdicts are cached (url_reputation) for URL_SCAN_TTL seconds.
 * No API key or lookup failure → '' (fail open; failures are not cached).
 */
function url_threat(PDO $pdo, $url)
{
    if (!safe_browsing_enabled() || $url === '') {
        return '';
    }
    $hash = hash('sha256', $url);
    try {
        $sel = $pdo->prepare('SELECT threat, checked FROM url_reputation WHERE url_hash = ?');
        $sel->execute(array($hash));
        $row = $sel->fetch();
        if ($row && (time() - (int) $row['checked']) < URL_SCAN_TTL) {
            return (string) $row['threat'];
        }
    } catch (Exception $e) { /* fall through to live lookup */ }

    $body = json_encode(array(
        'client'     => array('clientId' => 'snip', 'clientVersion' => '1.0'),
        'threatInfo' => array(
            'threatTypes'      => array('MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'),
            'platformTypes'    => array('ANY_PLATFORM'),
            'threatEntryTypes' => array('URL'),
            'threatEntries'    => array(array('url' => $url)),
        ),
    ));
    $ch = curl_init('https://safebrowsing.googleapis.com/v4/threatMatches:find?key=' . rawurlencode(SAFE_BROWSING_API_KEY));
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_POSTFIELDS     => $body,
    ));
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false || $http !== 200) {
        return '';                 // transient failure — don't cache as clean
    }
    $json = json_decode((string) $resp, true);
    $threat = isset($json['matches'][0]['threatType']) ? (string) $json['matches'][0]['threatType'] : '';
    try {
        $pdo->prepare('INSERT INTO url_reputation (url_hash, threat, checked) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE threat = ?, checked = ?')
            ->execute(array($hash, $threat, time(), $threat, time()));
    } catch (Exception $e) { /* best effort */ }
    return $threat;
}

// --- Access info collection --------------------------------------------------

/** Crude browser/platform parse from a User-Agent string. */
function parse_user_agent($ua)
{
    $browser = 'Other';
    foreach (array('Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome',
                   'Firefox' => 'Firefox', 'Safari' => 'Safari', 'curl' => 'curl',
                   'bot' => 'Bot', 'python' => 'Python') as $needle => $name) {
        if (stripos($ua, $needle) !== false) { $browser = $name; break; }
    }
    $platform = 'Other';
    foreach (array('Windows' => 'Windows', 'iPhone' => 'iOS', 'iPad' => 'iOS',
                   'Android' => 'Android', 'Mac OS' => 'macOS', 'Macintosh' => 'macOS',
                   'Linux' => 'Linux') as $needle => $name) {
        if (stripos($ua, $needle) !== false) { $platform = $name; break; }
    }
    // Device class from the UA (mobile / tablet / desktop). Android without
    // "Mobile" is conventionally a tablet.
    if (stripos($ua, 'iPad') !== false || stripos($ua, 'Tablet') !== false) {
        $device = 'Tablet';
    } elseif (preg_match('/Mobi|iPhone|Windows Phone|IEMobile/i', $ua)) {
        $device = 'Mobile';
    } elseif (stripos($ua, 'Android') !== false) {
        $device = preg_match('/Mobile/i', $ua) ? 'Mobile' : 'Tablet';
    } elseif ($ua === '') {
        $device = 'Other';
    } else {
        $device = 'Desktop';
    }
    return array('browser' => $browser, 'platform' => $platform, 'device' => $device);
}

/** Record an access event (e.g. a short-link redirect) for security review. */
function log_access(PDO $pdo, $event, $code = null, $user_id = null)
{
    $ua = user_agent();
    $p = parse_user_agent($ua);
    $ip = client_ip();
    // Per-link unique-visitor fingerprint (daily-salted; no cookie). Only for
    // code-scoped events so account-level events don't skew per-link uniques.
    $vhash = ($code !== null && function_exists('visitor_hash')) ? visitor_hash($ip, $ua, (string) $code) : '';
    $country = function_exists('geoip_country') ? geoip_country($ip) : '';
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO access_log (ts, event, code, user_id, ip, browser, platform, device, country, referer, user_agent, visitor_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(array(
            time(), $event, $code, $user_id, $ip, $p['browser'], $p['platform'], $p['device'], $country,
            mb_substr(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '', 0, 255),
            mb_substr($ua, 0, 255), $vhash,
        ));
    } catch (Exception $e) {
        error_log('access_log failed: ' . $e->getMessage());
    }
}
