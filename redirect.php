<?php
/*
 * Snip — resolve a short code and redirect, counting the visit.
 */
require __DIR__ . '/inc/bootstrap.php';

$code = isset($_GET['code']) ? $_GET['code'] : (isset($_GET['url']) ? $_GET['url'] : '');

// Codes are random (CODE_LENGTH) or custom slugs (3–40 of [A-Za-z0-9_-]).
if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) $code)) {
    http_response_code(404);
    die('That is not a valid short link.');
}

// Pull the link plus the owner (for the account-wide monthly visit cap).
$stmt = $pdo->prepare(
    'SELECT u.id, u.long_url, u.blocked AS link_blocked, u.domain_id, usr.id AS owner_id, usr.plan, usr.blocked AS owner_blocked, usr.month_visits, usr.visit_month
       FROM urls u
       JOIN users usr ON usr.id = u.user_id
      WHERE u.code = ?'
);
$stmt->execute(array($code));
$link = $stmt->fetch();

if (!$link) {
    http_response_code(404);
    die('Short link not found.');
}

// Multi-tenant isolation: a branded-domain link resolves only on its own host,
// and a main-app link resolves only on the main app (not on a custom domain).
$cur = current_domain($pdo);
$cur_id = $cur ? (int) $cur['id'] : null;
if ((int) $link['domain_id'] !== (int) $cur_id) {
    // (int)null === 0 on both sides keeps main-app links (NULL) on the main app.
    http_response_code(404);
    die('Short link not found.');
}

// Record who is accessing this link (security review): ip, browser, platform…
log_access($pdo, 'redirect', $code, $link['owner_id']);

// Disabled link or suspended owner → gone.
if (!empty($link['link_blocked']) || !empty($link['owner_blocked'])) {
    http_response_code(410);
    die('This link has been disabled.');
}

// Defense in depth: never redirect to anything but http(s).
if (!preg_match('|^https?://|i', $link['long_url'])) {
    http_response_code(404);
    die('Short link not found.');
}

// Account-wide monthly visit cap (e.g. free trial = 50/month; paid = unlimited).
// Done as a single atomic conditional UPDATE so concurrent visits can't exceed
// the cap (the increment only succeeds while under the limit / in a new month).
$cap = plan_config($link['plan'])['monthly_visit_cap'];
if ($cap !== null) {
    $month = date('Y-m');
    try {
        $mv = $pdo->prepare(
            'UPDATE users
                SET month_visits = IF(visit_month = ?, month_visits + 1, 1), visit_month = ?
              WHERE id = ? AND (visit_month <> ? OR month_visits < ?)'
        );
        $mv->execute(array($month, $month, $link['owner_id'], $month, $cap));
        if ($mv->rowCount() === 0) {
            http_response_code(410);
            die('This account has reached its monthly visit limit. The owner can upgrade for unlimited visits.');
        }
    } catch (PDOException $e) {
        error_log('monthly visit count failed: ' . $e->getMessage());
    }
}

// Count the visit on the link (best-effort, lifetime counter).
try {
    $upd = $pdo->prepare('UPDATE urls SET clicks = clicks + 1 WHERE id = ?');
    $upd->execute(array($link['id']));
} catch (PDOException $e) {
    error_log('click count failed: ' . $e->getMessage());
}

header('Location: ' . $link['long_url'], true, 301);
exit;
