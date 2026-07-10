<?php
/*
 * Snip — retention prune. Deletes aged rows from the append-only tables so
 * they don't grow without bound (and so visitor IPs aren't retained forever).
 * Run daily from cron, e.g.:
 *   0 4 * * *  docker compose -f docker-compose.prod.yml exec -T web php scripts/prune.php
 *
 * Retention window: LOG_RETENTION_DAYS (default 90). Rate-limit rows expire
 * quickly; IP-reputation cache and used/expired auth tokens are pruned too.
 */
require __DIR__ . '/../config.php';

$days = (int) (getenv('LOG_RETENTION_DAYS') ?: 90);
$now  = time();
$cut  = $now - $days * 86400;

$deletes = array(
    'access_log'    => array('DELETE FROM access_log WHERE ts < ?',        array($cut)),
    'security_log'  => array('DELETE FROM security_log WHERE ts < ?',      array($cut)),
    // fixed-window counters older than a day are dead weight
    'rate_limits'   => array('DELETE FROM rate_limits WHERE window_start < ?', array($now - 86400)),
    // reputation cache: 30-day TTL
    'ip_reputation' => array('DELETE FROM ip_reputation WHERE checked < ?', array($now - 30 * 86400)),
    // consumed or expired auth tokens, once a week old
    'auth_tokens'   => array('DELETE FROM auth_tokens WHERE (used = 1 OR expires < ?) AND created < ?', array($now, $now - 7 * 86400)),
    // processed Stripe event ids beyond the retention window
    'stripe_events' => array('DELETE FROM stripe_events WHERE ts < ?',     array($cut)),
    // expired DB-backed sessions (no-op unless SESSION_DRIVER=db is in use)
    'sessions'      => array('DELETE FROM sessions WHERE expires < ?',     array($now)),
);

foreach ($deletes as $table => $q) {
    try {
        $stmt = $pdo->prepare($q[0]);
        $stmt->execute($q[1]);
        echo str_pad($table, 16) . " pruned " . $stmt->rowCount() . " row(s)\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "prune {$table} failed: " . $e->getMessage() . "\n");
    }
}
echo "done (retention {$days}d).\n";
