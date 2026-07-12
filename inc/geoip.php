<?php
/*
 * Snip — IP → ISO country resolution for click analytics.
 *
 * Uses a local MaxMind-format database (.mmdb) via maxmind-db/reader. Works
 * with GeoLite2-Country OR DB-IP IP-to-Country Lite (both MMDB; DB-IP Lite is
 * free + redistributable with attribution and needs no license key). Fully
 * offline — a tree walk, no per-request network cost. If the DB is absent or
 * the IP is private/reserved/invalid, resolution returns '' (fail-open, like
 * the Safe Browsing / IPQS integrations) so geo is simply unavailable, never
 * fatal. Refresh the DB with scripts/update-geoip.sh.
 */

/** Is a geo database present and configured? */
function geoip_enabled()
{
    return is_string(GEOIP_DB) && GEOIP_DB !== '' && is_file(GEOIP_DB);
}

/** ISO-3166 alpha-2 country code for a public IP, or '' when unknown. */
function geoip_country($ip)
{
    // Private/reserved/invalid addresses never resolve — skip the lookup.
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return '';
    }
    if (!geoip_enabled()) {
        return '';
    }
    static $reader = null;
    static $tried = false;
    if (!$tried) {
        $tried = true;
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
        if (class_exists('MaxMind\\Db\\Reader')) {
            try {
                $reader = new MaxMind\Db\Reader(GEOIP_DB);
            } catch (\Exception $e) {
                $reader = null;
                error_log('geoip open failed: ' . $e->getMessage());
            }
        }
    }
    if (!$reader) {
        return '';
    }
    try {
        $rec = $reader->get($ip);
    } catch (\Exception $e) {
        return '';
    }
    if (is_array($rec) && !empty($rec['country']['iso_code'])) {
        return strtoupper(substr((string) $rec['country']['iso_code'], 0, 2));
    }
    return '';
}

/** Regional-indicator flag emoji for a 2-letter ISO code ('' → white flag). */
function country_flag($cc)
{
    $cc = strtoupper((string) $cc);
    if (!preg_match('/^[A-Z]{2}$/', $cc)) {
        return "\u{1F3F3}";   // 🏳 (unknown)
    }
    return mb_chr(0x1F1E6 - 65 + ord($cc[0])) . mb_chr(0x1F1E6 - 65 + ord($cc[1]));
}
