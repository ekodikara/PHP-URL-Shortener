<?php
/*
 * Snip — layered destination-URL content filter.
 *
 * Google Safe Browsing (inc/security.php) only flags malware / phishing /
 * unwanted software — it does NOT flag adult content, so pornographic links
 * pass its check. This module adds the acceptable-use layers promised by
 * /terms, cheapest-first:
 *
 *   1. Bundled adult-domain blocklist  — offline, instant, free (data/adult-domains.txt).
 *   2. Admin-managed blocklist         — the `blocked_domains` table (/admin).
 *   3. IPQualityScore URL category     — long tail; only when IPQS_API_KEY is set; cached.
 *   4. Keyword heuristic               — crude backstop; flags for admin review, never hard-blocks.
 *
 * url_is_disallowed() combines Safe Browsing + (1)+(2)+(3) into one verdict,
 * enforced at creation (reject before INSERT, inc/urls.php) and re-checked at
 * redirect time (auto-disable, redirect.php) — mirroring how url_threat() is
 * already wired in. Returns '' when the URL is allowed, else "<kind>:<detail>"
 * where <kind> is 'unsafe' (Safe Browsing) or 'adult' (our AUP layers).
 */

/** Adult-content filtering layers active? (Safe Browsing runs regardless.) */
function content_filter_enabled()
{
    return CONTENT_FILTER_ON;
}

/** Lowercased host of a full URL, or '' if it has none. */
function url_host($url)
{
    $h = parse_url((string) $url, PHP_URL_HOST);
    // Lowercase and drop a trailing DNS root dot: "Pornhub.com." resolves to the
    // exact same host but would otherwise dodge an exact blocklist match.
    return $h ? rtrim(strtolower($h), '.') : '';
}

/**
 * Best-effort registrable domain (eTLD+1) for a host. Not a full Public Suffix
 * List — just enough to stop blocklist matching from walking up to a bare TLD.
 * Handles the common two-label suffixes (co.uk, com.au, co.jp, ...).
 */
function registrable_domain($host)
{
    $host = strtolower(trim((string) $host, " \t\n\r\0\x0B."));
    if ($host === '' || strpos($host, '.') === false) {
        return $host;
    }
    static $two_label = array(
        'co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'or', 'ne', 'go',
    );
    $labels = explode('.', $host);
    $n = count($labels);
    if ($n <= 2) {
        return $host;
    }
    // If the second-to-last label is a common SLD (e.g. "co" in co.uk), keep 3.
    if (in_array($labels[$n - 2], $two_label, true) && strlen($labels[$n - 1]) <= 3) {
        return implode('.', array_slice($labels, -3));
    }
    return implode('.', array_slice($labels, -2));
}

/**
 * Parse a hosts-file or bare-domain blocklist into a lookup set
 * (domain => true). Accepts "0.0.0.0 domain", "127.0.0.1 domain" and bare
 * "domain" lines; ignores comments (#), blanks and non-domain tokens. Pure
 * (no I/O) so it is unit-testable.
 */
function parse_hosts_blocklist($contents)
{
    $set = array();
    $lines = preg_split('/\r\n|\r|\n/', (string) $contents);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = preg_split('/\s+/', $line);
        $domain = strtolower(end($parts));
        if ($domain === '' || $domain === 'localhost' || strpos($domain, '.') === false) {
            continue;
        }
        // Drop an IP token that slipped through (e.g. a bare "0.0.0.0").
        if (preg_match('/^[0-9.]+$/', $domain)) {
            continue;
        }
        $set[$domain] = true;
    }
    return $set;
}

/** The bundled adult-domain blocklist as a lookup set, loaded + cached once. */
function adult_blocklist()
{
    static $set = null;
    if ($set !== null) {
        return $set;
    }
    $path = ADULT_BLOCKLIST;
    if (!is_string($path) || !is_file($path) || !is_readable($path)) {
        return $set = array();
    }
    // The bundled list is ~77k lines (~1.3 MB). Parsing it on every link-create
    // AND every redirect (the hot path) is a DoS-amplification vector, and
    // mod_php resets function statics each request — so persist the parsed set
    // in APCu, keyed by the file's mtime (re-parsed only when the list changes).
    $key = 'snip_adult_blocklist_' . (@filemtime($path) ?: 0);
    if (function_exists('apcu_enabled') && apcu_enabled()) {
        $ok = false;
        $cached = apcu_fetch($key, $ok);
        if ($ok && is_array($cached)) {
            return $set = $cached;
        }
        $set = parse_hosts_blocklist(file_get_contents($path));
        apcu_store($key, $set, 86400);
        return $set;
    }
    return $set = parse_hosts_blocklist(file_get_contents($path));
}

/**
 * Is $host (or any of its parent domains down to the registrable domain) on the
 * bundled blocklist or the admin-managed `blocked_domains` table?
 */
function domain_is_blocked(PDO $pdo, $host)
{
    // Canonicalize as registrable_domain() does: lowercase + strip surrounding
    // whitespace AND a trailing root dot, so "Pornhub.com." can't dodge a match.
    $host = strtolower(trim((string) $host, " \t\n\r\0\x0B."));
    if ($host === '') {
        return false;
    }
    $reg = registrable_domain($host);

    // Candidate suffixes: the full host, then each parent, down to the
    // registrable domain (never a bare public suffix like "com"). BOTH the
    // bundled set and the admin table are matched against this same set, so a
    // deeper subdomain can't slip past a blocklist entry for a parent domain.
    $labels = explode('.', $host);
    $candidates = array();
    for ($i = 0, $n = count($labels); $i < $n; $i++) {
        $cand = implode('.', array_slice($labels, $i));
        $candidates[] = $cand;
        if ($cand === $reg) {
            break;
        }
    }

    // 1. Bundled blocklist (in-memory set).
    $set = adult_blocklist();
    foreach ($candidates as $cand) {
        if (isset($set[$cand])) {
            return true;
        }
    }

    // 2. Admin-managed blocklist (same suffix set, one indexed query).
    try {
        $in = implode(',', array_fill(0, count($candidates), '?'));
        $stmt = $pdo->prepare("SELECT 1 FROM blocked_domains WHERE domain IN ($in) LIMIT 1");
        $stmt->execute($candidates);
        if ($stmt->fetch()) {
            return true;
        }
    } catch (Exception $e) { /* table missing / transient — fail open */ }

    return false;
}

/**
 * IPQualityScore URL category for a destination, or '' when disabled/unknown/
 * lookup-failed. 'adult' means the scanner flagged adult content. Cached in
 * url_reputation.category for URL_SCAN_TTL seconds (its own cat_checked stamp,
 * independent of the Safe Browsing `threat`/`checked` pair on the same row).
 */
function url_category(PDO $pdo, $url)
{
    if (IPQS_API_KEY === '' || $url === '') {
        return '';
    }
    $hash = hash('sha256', $url);
    try {
        $sel = $pdo->prepare('SELECT category, cat_checked FROM url_reputation WHERE url_hash = ?');
        $sel->execute(array($hash));
        $row = $sel->fetch();
        if ($row && (int) $row['cat_checked'] > 0 && (time() - (int) $row['cat_checked']) < URL_SCAN_TTL) {
            return (string) $row['category'];
        }
    } catch (Exception $e) { /* fall through to live lookup */ }

    $ch = curl_init('https://ipqualityscore.com/api/json/url/' . rawurlencode(IPQS_API_KEY) . '/' . rawurlencode($url));
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4));
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false || $http !== 200) {
        return '';                 // transient failure — don't cache
    }
    $json = json_decode((string) $resp, true);
    if (!is_array($json) || empty($json['success'])) {
        return '';
    }
    $category = !empty($json['adult'])
        ? 'adult'
        : mb_substr(strtolower((string) ($json['category'] ?? '')), 0, 40);
    try {
        $pdo->prepare('INSERT INTO url_reputation (url_hash, category, cat_checked) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE category = ?, cat_checked = ?')
            ->execute(array($hash, $category, time(), $category, time()));
    } catch (Exception $e) { /* best effort */ }
    return $category;
}

/**
 * Crude keyword backstop: obvious adult tokens in the host or path. Word-
 * bounded so "essex"/"sussex" don't trip "sex". This does NOT hard-block — the
 * caller flags the link for admin review. Pure/unit-testable.
 */
function url_keyword_flag($url)
{
    $host = url_host($url);
    $path = (string) parse_url((string) $url, PHP_URL_PATH);
    $hay = $host . ' ' . rawurldecode($path);
    return (bool) preg_match('/\b(porn|xxx|nsfw|hentai|camgirl|camgirls|escort|escorts|sex)\b/i', $hay);
}

/**
 * Normalize an admin-entered domain for the blocklist: strip scheme/path/www,
 * lowercase, validate as a hostname. Returns '' if not a valid domain.
 */
function normalize_blockable_host($input)
{
    $h = strtolower(trim((string) $input));
    $h = preg_replace('#^[a-z]+://#', '', $h);   // strip scheme if pasted
    $h = explode('/', $h)[0];                     // strip path
    $h = explode('?', $h)[0];                     // strip query
    $h = explode(':', $h)[0];                     // strip port
    $h = preg_replace('/^www\./', '', $h);
    $h = trim($h, '.');
    if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $h)) {
        return '';
    }
    return $h;
}

/**
 * Combined destination verdict. '' = allowed. Otherwise "<kind>:<detail>":
 *   'unsafe:<THREAT>'   — Google Safe Browsing (always checked; AUP-independent)
 *   'adult:blocklist'   — bundled or admin domain blocklist
 *   'adult:ipqs'        — IPQualityScore adult category
 * The keyword heuristic is intentionally NOT here (it's advisory — see
 * url_keyword_flag()); this function only reports HARD-block reasons.
 */
function url_is_disallowed(PDO $pdo, $url)
{
    if ($url === '') {
        return '';
    }
    // 1. Malware / phishing (Google Safe Browsing) — runs even if the adult
    //    layers are disabled, preserving the pre-existing behaviour.
    $threat = url_threat($pdo, $url);
    if ($threat !== '') {
        return 'unsafe:' . $threat;
    }
    if (!content_filter_enabled()) {
        return '';
    }
    $host = url_host($url);
    if ($host === '') {
        return '';
    }
    // 2/3. Domain blocklist (bundled + admin).
    if (domain_is_blocked($pdo, $host)) {
        return 'adult:blocklist';
    }
    // 4. IPQS URL category (long tail; no-op without a key).
    if (url_category($pdo, $url) === 'adult') {
        return 'adult:ipqs';
    }
    return '';
}
