<?php
/*
 * Snip — API tokens for MCP access. Tokens are shown once on creation and
 * stored only as a SHA-256 hash (never in plaintext).
 */

define('TOKEN_PREFIX', 'snip_');

/** Create a token for a user. Returns the RAW token (show once, then gone). */
function generate_api_token(PDO $pdo, $user_id, $label = 'MCP token', $scope = 'full')
{
    $raw  = TOKEN_PREFIX . bin2hex(random_bytes(24));
    $hash = hash('sha256', $raw);
    $label = trim((string) $label);
    if ($label === '') {
        $label = 'MCP token';
    }
    $scope = ($scope === 'read') ? 'read' : 'full';
    $stmt = $pdo->prepare('INSERT INTO api_tokens (user_id, token_hash, label, scope, created) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute(array($user_id, $hash, mb_substr($label, 0, 80), $scope, time()));
    return $raw;
}

/** List a user's tokens (no secrets — just metadata). */
function list_api_tokens(PDO $pdo, $user_id)
{
    $stmt = $pdo->prepare('SELECT id, label, scope, created, last_used FROM api_tokens WHERE user_id = ? ORDER BY created DESC');
    $stmt->execute(array($user_id));
    return $stmt->fetchAll();
}

/** Revoke one of the user's tokens. */
function revoke_api_token(PDO $pdo, $user_id, $id)
{
    $stmt = $pdo->prepare('DELETE FROM api_tokens WHERE id = ? AND user_id = ?');
    $stmt->execute(array($id, $user_id));
    return $stmt->rowCount() > 0;
}

/**
 * Resolve a raw bearer token to its owner (full user row), or null.
 * Updates last_used on success.
 */
function user_for_token(PDO $pdo, $raw_token)
{
    $raw_token = trim((string) $raw_token);
    if ($raw_token === '' || strncmp($raw_token, TOKEN_PREFIX, strlen(TOKEN_PREFIX)) !== 0) {
        return null;
    }
    $hash = hash('sha256', $raw_token);
    $stmt = $pdo->prepare(
        'SELECT u.id, u.email, u.plan, u.billing_interval, u.stripe_customer_id, u.stripe_subscription_id, u.blocked, u.created, t.id AS token_id, t.scope AS token_scope
           FROM api_tokens t JOIN users u ON u.id = t.user_id
          WHERE t.token_hash = ?'
    );
    $stmt->execute(array($hash));
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $upd = $pdo->prepare('UPDATE api_tokens SET last_used = ? WHERE id = ?');
    $upd->execute(array(time(), $row['token_id']));
    return $row;
}
