<?php
/*
 * Snip — resolve a short code and redirect, counting the visit.
 */
// Anonymous hot path — no session needed; skip the per-request session I/O.
define('SNIP_SKIP_SESSION', true);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';   // themed error pages

$code = isset($_GET['code']) ? $_GET['code'] : (isset($_GET['url']) ? $_GET['url'] : '');

// Codes are random (CODE_LENGTH) or custom slugs (3–40 of [A-Za-z0-9_-]).
if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) $code)) {
    render_error_page(404, 'Link not found', 'That\'s not a valid short link. Check the address for typos.');
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
    render_error_page(404, 'Link not found', 'This short link doesn\'t exist or was removed. Check the address for typos.');
}

// Multi-tenant isolation: a branded-domain link resolves only on its own host,
// and a main-app link resolves only on the main app (not on a custom domain).
$cur = current_domain($pdo);
$cur_id = $cur ? (int) $cur['id'] : null;
if ((int) $link['domain_id'] !== (int) $cur_id) {
    // (int)null === 0 on both sides keeps main-app links (NULL) on the main app.
    render_error_page(404, 'Link not found', 'This short link doesn\'t exist or was removed. Check the address for typos.');
}

// Record who is accessing this link (security review): ip, browser, platform…
// Best-effort: a logging hiccup must never block the redirect itself.
try {
    log_access($pdo, 'redirect', $code, $link['owner_id']);
} catch (\Throwable $e) {
    error_log('access log failed for ' . $code . ': ' . $e->getMessage());
}

// Disabled link or suspended owner → gone.
if (!empty($link['link_blocked']) || !empty($link['owner_blocked'])) {
    render_error_page(410, 'Link disabled', 'This link was turned off by its owner or an administrator.');
}

// Defense in depth: never redirect to anything but http(s).
if (!preg_match('|^https?://|i', $link['long_url'])) {
    render_error_page(404, 'Link not found', 'This short link doesn\'t exist or was removed. Check the address for typos.');
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
            render_error_page(410, 'Visit limit reached', 'This link\'s owner has used this month\'s visit allowance. Visits resume next month, or sooner if the owner upgrades.');
        }
    } catch (PDOException $e) {
        error_log('monthly visit count failed: ' . $e->getMessage());
    }
}

// Re-check the destination against Safe Browsing (cached). A link whose target
// turned malicious after creation is auto-disabled and never redirected.
$threat = url_threat($pdo, $link['long_url']);
if ($threat !== '') {
    try {
        $pdo->prepare('UPDATE urls SET blocked = 1 WHERE id = ?')->execute(array($link['id']));
    } catch (PDOException $e) {
        error_log('auto-block failed: ' . $e->getMessage());
    }
    log_security_event($pdo, 'auto_block_malicious', $threat . ' ' . $code, $link['owner_id']);
    http_response_code(410);
    die('This link has been disabled.');
}

// Count the visit on the link (best-effort, lifetime counter).
try {
    $upd = $pdo->prepare('UPDATE urls SET clicks = clicks + 1 WHERE id = ?');
    $upd->execute(array($link['id']));
} catch (PDOException $e) {
    error_log('click count failed: ' . $e->getMessage());
}

// Links owned by trial accounts go through an interstitial notice instead of a
// silent redirect — visitors see where they're headed and can report abuse.
// Paid plans redirect directly.
if (!plan_is_paid(array('plan' => $link['plan']))) {
    require __DIR__ . '/inc/layout.php';
    $dest = $link['long_url'];
    $dest_host = parse_url($dest, PHP_URL_HOST);
    render_header('Redirect notice');
    ?>
<div class="auth-wrap">
  <div class="card glass" style="text-align:center">
    <h1 style="font-family:var(--font-display);font-weight:800;text-transform:uppercase;font-size:1.5rem;margin:0 0 10px">You're leaving <?= e(APP_NAME) ?></h1>
    <p class="sub">This short link points to</p>
    <p style="font-size:1.15rem;font-weight:600;margin:6px 0"><?= e($dest_host) ?></p>
    <p class="sub" style="word-break:break-all;font-size:0.82rem"><?= e($dest) ?></p>
    <p class="sub">Only continue if you trust this destination.</p>
    <a class="btn btn-solid" rel="noopener nofollow" href="<?= e($dest) ?>">Continue to <?= e($dest_host) ?></a>
    <p style="margin-top:14px;font-size:0.85rem"><a href="report?code=<?= e($code) ?>">Report this link</a></p>
  </div>
</div>
    <?php
    render_footer();
    exit;
}

// 302 (not 301): a permanent redirect gets cached by browsers/proxies, after
// which repeat visits never reach us — silently breaking click counting and
// the monthly visit cap. Temporary + no-store keeps every visit countable.
header('Cache-Control: no-store, max-age=0');
header('Location: ' . $link['long_url'], true, 302);
exit;
