<?php
/*
 * Snip — link analytics. Pure aggregation over access_log (one row per redirect,
 * written by log_access() in inc/security.php). No new tracking surface: clicks,
 * unique visitors, and dimension breakdowns are GROUP BYs over data we already
 * store. "Unique" uses visitor_hash — a daily-rotating salted hash of IP+UA+code
 * (see visitor_hash()), so counts work without a cookie and without relying on
 * raw IPs.
 */

/** Daily-rotating, non-reversible per-link visitor fingerprint. */
function visitor_hash($ip, $ua, $code)
{
    return hash('sha256', ANALYTICS_SALT . '|' . gmdate('Y-m-d') . '|' . $ip . '|' . $ua . '|' . $code);
}

/**
 * Resolve a range key ('7'|'30'|'90'|'all') to [since_ts, days, label].
 * since_ts = 0 means "all time".
 */
function analytics_range($key)
{
    $ranges = array(
        '7'  => array(7,  'Last 7 days'),
        '30' => array(30, 'Last 30 days'),
        '90' => array(90, 'Last 90 days'),
    );
    if ($key === 'all') {
        return array(0, 0, 'All time');
    }
    if (!isset($ranges[$key])) {
        $key = '30';
    }
    list($days, $label) = $ranges[$key];
    return array(time() - $days * 86400, $days, $label);
}

/** Does this user own this code? Returns the link row or null. */
function link_owned_by(PDO $pdo, $user_id, $code)
{
    $stmt = $pdo->prepare('SELECT id, code, long_url, clicks, is_custom, blocked, created FROM urls WHERE code = ? AND user_id = ?');
    $stmt->execute(array($code, $user_id));
    return $stmt->fetch() ?: null;
}

/** Total + unique clicks for a code since $since (0 = all time). */
function link_click_summary(PDO $pdo, $code, $since)
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS clicks, COUNT(DISTINCT visitor_hash) AS uniques
           FROM access_log WHERE code = ? AND ts >= ?'
    );
    $stmt->execute(array($code, (int) $since));
    $r = $stmt->fetch();
    return array('clicks' => (int) $r['clicks'], 'uniques' => (int) $r['uniques']);
}

/**
 * Per-day time series: [ ['day'=>unixDayStart, 'clicks'=>n, 'uniques'=>n], ... ]
 * with zero-filled days across the range so charts are continuous.
 */
function link_click_timeseries(PDO $pdo, $code, $since, $days)
{
    $stmt = $pdo->prepare(
        'SELECT FLOOR(ts / 86400) AS d, COUNT(*) AS c, COUNT(DISTINCT visitor_hash) AS u
           FROM access_log WHERE code = ? AND ts >= ? GROUP BY d ORDER BY d'
    );
    $stmt->execute(array($code, (int) $since));
    $byDay = array();
    foreach ($stmt->fetchAll() as $row) {
        $byDay[(int) $row['d']] = array('clicks' => (int) $row['c'], 'uniques' => (int) $row['u']);
    }
    // Zero-fill. For "all time" (days=0) fall back to whatever days have data.
    $out = array();
    if ($days > 0) {
        $startDay = (int) floor((time() - $days * 86400) / 86400);
        $endDay   = (int) floor(time() / 86400);
        for ($d = $startDay; $d <= $endDay; $d++) {
            $hit = isset($byDay[$d]) ? $byDay[$d] : array('clicks' => 0, 'uniques' => 0);
            $out[] = array('day' => $d * 86400, 'clicks' => $hit['clicks'], 'uniques' => $hit['uniques']);
        }
    } else {
        foreach ($byDay as $d => $hit) {
            $out[] = array('day' => $d * 86400, 'clicks' => $hit['clicks'], 'uniques' => $hit['uniques']);
        }
    }
    return $out;
}

/**
 * Ranked breakdown for one whitelisted dimension column. Returns
 * [ ['key'=>value, 'clicks'=>n], ... ] ordered by clicks desc.
 */
function link_breakdown(PDO $pdo, $code, $since, $column, $limit = 10)
{
    $allowed = array('browser', 'platform', 'device', 'referer');
    if (!in_array($column, $allowed, true)) {
        return array();               // never interpolate an unvetted column
    }
    $stmt = $pdo->prepare(
        "SELECT `$column` AS k, COUNT(*) AS c
           FROM access_log WHERE code = ? AND ts >= ?
          GROUP BY `$column` ORDER BY c DESC LIMIT " . (int) $limit
    );
    $stmt->execute(array($code, (int) $since));
    $out = array();
    foreach ($stmt->fetchAll() as $row) {
        $out[] = array('key' => (string) $row['k'], 'clicks' => (int) $row['c']);
    }
    return $out;
}

/**
 * Referrers collapsed to registrable host ('' => 'Direct'), re-summed and
 * ranked. Reads the raw referer breakdown (which stores full URLs) and folds
 * multiple paths on one host into a single row.
 */
function link_referrers(PDO $pdo, $code, $since, $limit = 10)
{
    $rows = link_breakdown($pdo, $code, $since, 'referer', 200);
    $byHost = array();
    foreach ($rows as $r) {
        $ref = $r['key'];
        if ($ref === '') {
            $host = 'Direct';
        } else {
            $h = parse_url($ref, PHP_URL_HOST);
            $host = $h ? strtolower(preg_replace('/^www\./', '', $h)) : 'Other';
        }
        $byHost[$host] = ($byHost[$host] ?? 0) + $r['clicks'];
    }
    arsort($byHost);
    $out = array();
    foreach (array_slice($byHost, 0, $limit, true) as $host => $c) {
        $out[] = array('key' => $host, 'clicks' => $c);
    }
    return $out;
}
