<?php
/*
 * Snip — MCP server (Model Context Protocol over HTTP / JSON-RPC 2.0).
 *
 * Auth: Bearer token (see Connect AI page). Access is limited to active paid
 * plans (Pro/Premium). Exposes tools: shorten_url, list_links, get_stats,
 * delete_link. No session / CSRF — the bearer token is the credential.
 */

ini_set('display_errors', '0');
require __DIR__ . '/config.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/urls.php';
require __DIR__ . '/inc/tokens.php';
require __DIR__ . '/inc/security.php';

// Writes require a 'full'-scope token; reads are allowed for any valid token.
$GLOBALS['MCP_WRITE_TOOLS'] = array('shorten_url', 'delete_link');

header('Content-Type: application/json');

const MCP_PROTOCOL = '2025-06-18';

/* ----------------------------- helpers ----------------------------------- */

function bearer_token()
{
    $h = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $h = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
        }
    }
    if (stripos($h, 'Bearer ') === 0) {
        return trim(substr($h, 7));
    }
    return '';
}

function rpc_result($id, $result)
{
    echo json_encode(array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result));
    exit;
}

function rpc_error($id, $code, $message, $http = 200)
{
    http_response_code($http);
    echo json_encode(array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => $code, 'message' => $message)));
    exit;
}

/** A successful tool result (human-readable text). */
function tool_text($text)
{
    return array('content' => array(array('type' => 'text', 'text' => $text)));
}

/** A tool-level error (the model sees it and can react). */
function tool_error($text)
{
    return array('content' => array(array('type' => 'text', 'text' => $text)), 'isError' => true);
}

/* ----------------------------- transport --------------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rpc_error(null, -32600, 'Use HTTP POST with JSON-RPC.', 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || !isset($body['jsonrpc'])) {
    rpc_error(null, -32700, 'Parse error.', 400);
}

$id     = isset($body['id']) ? $body['id'] : null;
$method = isset($body['method']) ? $body['method'] : '';
$params = isset($body['params']) && is_array($body['params']) ? $body['params'] : array();

// Notifications (no id) — acknowledge with 202 and no body.
if ($id === null && strncmp($method, 'notifications/', 14) === 0) {
    http_response_code(202);
    exit;
}

/* ----------------------------- auth -------------------------------------- */

// Pre-auth per-IP throttle: stops token brute-forcing / unauthenticated DoS
// before we ever touch the token lookup.
if (!rate_limit($pdo, 'mcp_ip:' . client_ip(), 60, 60)) {
    rpc_error($id, -32003, 'Rate limit exceeded.', 429);
}

$user = user_for_token($pdo, bearer_token());
if (!$user) {
    log_security_event($pdo, 'mcp_invalid_token', $method);
    rpc_error($id, -32001, 'Unauthorized: missing or invalid API token.', 401);
}
if (!empty($user['blocked'])) {
    log_security_event($pdo, 'mcp_blocked', '', $user['id']);
    rpc_error($id, -32005, 'This account has been suspended.', 403);
}
if (!plan_is_paid($user)) {
    log_security_event($pdo, 'mcp_denied', 'plan:' . $user['plan'], $user['id']);
    rpc_error($id, -32002, 'MCP access requires an active Pro or Premium plan.', 403);
}

// Rate limit per token AND per user (so minting many tokens can't multiply the cap).
if (!rate_limit($pdo, 'mcp:' . $user['token_id'], 120, 60)
    || !rate_limit($pdo, 'mcp_user:' . $user['id'], 300, 60)) {
    log_security_event($pdo, 'mcp_rate_limited', '', $user['id']);
    rpc_error($id, -32003, 'Rate limit exceeded: max 120 requests per minute.', 429);
}

$scope = isset($user['token_scope']) ? $user['token_scope'] : 'full';

/* ----------------------------- methods ----------------------------------- */

switch ($method) {
    case 'initialize':
        rpc_result($id, array(
            'protocolVersion' => MCP_PROTOCOL,
            'capabilities'    => array('tools' => array('listChanged' => false)),
            'serverInfo'      => array('name' => APP_NAME . ' URL Shortener', 'version' => '1.0.0'),
            'instructions'    => 'Create and manage ' . APP_NAME . ' short links for the authenticated user.',
        ));
        break;

    case 'ping':
        rpc_result($id, new stdClass());
        break;

    case 'tools/list':
        // A read-only token only sees the read tools.
        $tools = array_values(array_filter(mcp_tools(), function ($t) use ($scope) {
            return $scope === 'full' || !in_array($t['name'], $GLOBALS['MCP_WRITE_TOOLS'], true);
        }));
        rpc_result($id, array('tools' => $tools));
        break;

    case 'tools/call':
        $name = isset($params['name']) ? $params['name'] : '';
        $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : array();
        if ($scope !== 'full' && in_array($name, $GLOBALS['MCP_WRITE_TOOLS'], true)) {
            log_security_event($pdo, 'mcp_scope_denied', $name, $user['id']);
            rpc_error($id, -32004, 'This token is read-only and cannot call "' . $name . '".', 403);
        }
        rpc_result($id, mcp_call($pdo, $user, $name, $args));
        break;

    default:
        rpc_error($id, -32601, 'Method not found: ' . $method);
}

/* ----------------------------- tool defs --------------------------------- */

function mcp_tools()
{
    return array(
        array(
            'name'        => 'shorten_url',
            'description' => 'Create a short link for a long URL. Optionally pass a custom name (Premium only).',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array(
                    'url'         => array('type' => 'string', 'description' => 'The http(s) URL to shorten.'),
                    'custom_name' => array('type' => 'string', 'description' => 'Optional custom slug (Premium plans only).'),
                ),
                'required'   => array('url'),
            ),
        ),
        array(
            'name'        => 'list_links',
            'description' => 'List the authenticated user\'s short links with destinations and click counts.',
            'inputSchema' => array('type' => 'object', 'properties' => new stdClass()),
        ),
        array(
            'name'        => 'get_stats',
            'description' => 'Get details and click count for one short link by its code.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array('code' => array('type' => 'string', 'description' => 'The short code, e.g. "a3f9Zk".')),
                'required'   => array('code'),
            ),
        ),
        array(
            'name'        => 'delete_link',
            'description' => 'Delete one of the user\'s short links by its code.',
            'inputSchema' => array(
                'type'       => 'object',
                'properties' => array('code' => array('type' => 'string', 'description' => 'The short code to delete.')),
                'required'   => array('code'),
            ),
        ),
    );
}

/* ----------------------------- tool impls -------------------------------- */

function mcp_call(PDO $pdo, array $user, $name, array $args)
{
    switch ($name) {
        case 'shorten_url':
            list($row, $err) = create_short_url($pdo, $user, isset($args['url']) ? $args['url'] : '', isset($args['custom_name']) ? $args['custom_name'] : '');
            if ($err) {
                return tool_error($err);
            }
            return tool_text("Created: " . $row['short_url'] . " → " . $row['long_url']);

        case 'list_links':
            $stmt = $pdo->prepare('SELECT code, long_url, clicks, is_custom FROM urls WHERE user_id = ? ORDER BY created DESC');
            $stmt->execute(array($user['id']));
            $rows = $stmt->fetchAll();
            if (!$rows) {
                return tool_text('No links yet.');
            }
            $lines = array();
            foreach ($rows as $r) {
                $lines[] = BASE_HREF . $r['code'] . '  →  ' . $r['long_url']
                    . '  (' . (int) $r['clicks'] . ' clicks' . ($r['is_custom'] ? ', custom' : '') . ')';
            }
            return tool_text(count($rows) . " link(s):\n" . implode("\n", $lines));

        case 'get_stats':
            $code = isset($args['code']) ? (string) $args['code'] : '';
            $stmt = $pdo->prepare('SELECT code, long_url, clicks, is_custom, created FROM urls WHERE user_id = ? AND code = ?');
            $stmt->execute(array($user['id'], $code));
            $r = $stmt->fetch();
            if (!$r) {
                return tool_error('No link found with code "' . $code . '".');
            }
            return tool_text(
                BASE_HREF . $r['code'] . "\n"
                . "Destination: " . $r['long_url'] . "\n"
                . "Clicks: " . (int) $r['clicks'] . "\n"
                . "Custom name: " . ($r['is_custom'] ? 'yes' : 'no') . "\n"
                . "Created: " . gmdate('Y-m-d H:i', (int) $r['created']) . ' UTC'
            );

        case 'delete_link':
            $code = isset($args['code']) ? (string) $args['code'] : '';
            $stmt = $pdo->prepare('DELETE FROM urls WHERE user_id = ? AND code = ?');
            $stmt->execute(array($user['id'], $code));
            return $stmt->rowCount()
                ? tool_text('Deleted ' . BASE_HREF . $code)
                : tool_error('No link found with code "' . $code . '".');

        default:
            return tool_error('Unknown tool: ' . $name);
    }
}
