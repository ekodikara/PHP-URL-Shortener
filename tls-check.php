<?php
/*
 * Snip — Caddy on-demand TLS ask endpoint. Caddy calls this before issuing a
 * certificate for a hostname; we return 200 only for verified custom domains
 * so certs are minted exclusively for domains we actually serve.
 *   GET /tls-check?domain=go.acme.com  ->  200 (allow) | 404 (deny)
 */
require __DIR__ . '/config.php';

$host = isset($_GET['domain']) ? strtolower(trim($_GET['domain'])) : '';
$host = preg_replace('/:.*$/', '', $host);

if ($host !== '' && $host === preg_replace('/:.*$/', '', strtolower(SITE_HOST !== '' ? parse_url(SITE_HOST, PHP_URL_HOST) : ''))) {
    http_response_code(200); echo 'ok'; exit;   // the main app domain
}

if ($host !== '') {
    $stmt = $pdo->prepare('SELECT 1 FROM domains WHERE host = ? AND verified = 1');
    $stmt->execute(array($host));
    if ($stmt->fetch()) {
        http_response_code(200); echo 'ok'; exit;
    }
}

http_response_code(404);
echo 'unknown domain';
