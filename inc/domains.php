<?php
/*
 * Snip — custom branded domains (Enterprise multi-tenancy).
 *
 * A request's Host determines the "tenant": if it matches a verified row in
 * `domains`, links are scoped to that domain; otherwise it's the main app.
 */

/** The request host, lowercased, without port. */
function current_host()
{
    $h = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    return strtolower(preg_replace('/:.*$/', '', $h));
}

/** scheme://host/ for the CURRENT request (used to brand created short links). */
function request_base()
{
    return (REQUEST_HTTPS ? 'https' : 'http') . '://' . current_host() . '/';
}

/** The verified custom-domain row for the current Host, or null (= main app). */
function current_domain(PDO $pdo)
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $host = current_host();
    if ($host === '') {
        return $cache = null;
    }
    $stmt = $pdo->prepare('SELECT id, host, user_id, verified FROM domains WHERE host = ? AND verified = 1');
    $stmt->execute(array($host));
    return $cache = ($stmt->fetch() ?: null);
}

/** List a user's domains. */
function list_domains(PDO $pdo, $user_id)
{
    $stmt = $pdo->prepare('SELECT id, host, verified, token, created FROM domains WHERE user_id = ? ORDER BY created DESC');
    $stmt->execute(array($user_id));
    return $stmt->fetchAll();
}

/** Add a domain for a user (unverified). Returns [row, null] or [null, error]. */
function add_domain(PDO $pdo, $user_id, $host)
{
    $host = strtolower(trim((string) $host));
    $host = preg_replace('#^https?://#', '', $host);
    $host = preg_replace('#/.*$#', '', $host);
    if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host)) {
        return array(null, 'Enter a valid domain, e.g. go.acme.com');
    }
    $stmt = $pdo->prepare('SELECT 1 FROM domains WHERE host = ?');
    $stmt->execute(array($host));
    if ($stmt->fetch()) {
        return array(null, 'That domain is already registered.');
    }
    $token = 'snip-verify=' . bin2hex(random_bytes(12));
    $ins = $pdo->prepare('INSERT INTO domains (host, user_id, verified, token, created) VALUES (?, ?, 0, ?, ?)');
    $ins->execute(array($host, $user_id, $token, time()));
    return array(array('host' => $host, 'token' => $token), null);
}

/** Verify domain ownership via a DNS TXT record containing the token. */
function verify_domain(PDO $pdo, $user_id, $id)
{
    $stmt = $pdo->prepare('SELECT host, token FROM domains WHERE id = ? AND user_id = ?');
    $stmt->execute(array($id, $user_id));
    $row = $stmt->fetch();
    if (!$row) {
        return array(false, 'Domain not found.');
    }
    $found = false;
    $records = @dns_get_record($row['host'], DNS_TXT);
    if (is_array($records)) {
        foreach ($records as $r) {
            if (isset($r['txt']) && strpos($r['txt'], $row['token']) !== false) {
                $found = true;
                break;
            }
        }
    }
    if (!$found) {
        return array(false, 'TXT record not found yet. DNS can take a few minutes to propagate.');
    }
    $pdo->prepare('UPDATE domains SET verified = 1 WHERE id = ? AND user_id = ?')->execute(array($id, $user_id));
    return array(true, 'Domain verified.');
}
