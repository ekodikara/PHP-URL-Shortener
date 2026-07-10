<?php
/*
 * Snip — health check for uptime monitors and load balancers.
 * Clean URL: /health. No session, no auth. Returns JSON:
 *   200 {"status":"ok","db":true}     — app + DB reachable
 *   503 {"status":"degraded","db":false} — DB unreachable
 */
define('SNIP_SKIP_SESSION', true);
require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$db_ok = false;
try {
    $db_ok = ($pdo->query('SELECT 1')->fetchColumn() == 1);
} catch (\Throwable $e) {
    error_log('health: DB check failed: ' . $e->getMessage());
}

http_response_code($db_ok ? 200 : 503);
echo json_encode(array(
    'status' => $db_ok ? 'ok' : 'degraded',
    'db'     => $db_ok,
    'time'   => gmdate('c'),
));
