<?php
/*
 * Snip — member dashboard: create links, see usage, manage links.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

require_login();
$user = current_user();
$plan = plan_config($user['plan']);

$used = plan_usage($pdo, $user);             // month- or total-scoped per plan
$limit = $plan['url_limit'];                 // null = unlimited
$pct = $limit ? min(100, round($used / $limit * 100)) : 0;
$usage_label = $plan['limit_period'] === 'total' ? 'Links used' : 'Links this month';

$stmt = $pdo->prepare('SELECT code, long_url, is_custom, blocked, clicks, created FROM urls WHERE user_id = ? ORDER BY created DESC');
$stmt->execute(array($user['id']));
$links = $stmt->fetchAll();

$total_clicks = 0;
$custom_count = 0;
$max_clicks = 0;
$top = null;
foreach ($links as $l) {
    $c = (int) $l['clicks'];
    $total_clicks += $c;
    if ($l['is_custom']) { $custom_count++; }
    if ($c > $max_clicks) { $max_clicks = $c; }
    if ($top === null || $c > (int) $top['clicks']) { $top = $l; }
}
$custom_limit = $plan['custom_slugs']; // null = unlimited, 0 = not allowed

render_header('Dashboard');
?>

<section class="page-head">
  <div>
    <h1>Your links</h1>
    <p class="sub"><?= count($links) ?> link<?= count($links) === 1 ? '' : 's' ?> · <?= number_format($total_clicks) ?> total clicks</p>
  </div>
  <div class="page-head-actions">
    <?php if (is_admin($user)): ?>
      <a class="btn btn-ghost" href="admin">Admin</a>
    <?php endif; ?>
    <?php if ($user['plan'] === 'enterprise'): ?>
      <a class="btn btn-ghost" href="domains">Domains</a>
    <?php endif; ?>
    <?php if (plan_is_paid($user)): ?>
      <a class="btn btn-ghost" href="connect">Connect AI</a>
    <?php endif; ?>
    <?php if (!$plan['is_trial'] && !empty($user['stripe_customer_id'])): ?>
      <a class="btn btn-ghost" href="billing-portal?t=<?= e(csrf_token()) ?>">Manage billing</a>
    <?php endif; ?>
    <a class="btn btn-ghost" href="upgrade">Plans →</a>
  </div>
</section>

<?php if (empty($user['email_verified'])): ?>
<div class="card glass card-todo">
  <p class="sub" style="margin:0">Verify your email to secure your account. <a href="verify?resend=1">Resend the verification link</a>.</p>
</div>
<?php endif; ?>

<?php if ($links): ?>
<section class="stat-row">
  <div class="stat glass">
    <div class="k">Links</div>
    <div class="v"><?= number_format(count($links)) ?></div>
    <div class="s"><?= $limit === null ? 'unlimited' : $used . ' / ' . $limit . ' used' ?></div>
  </div>
  <div class="stat glass">
    <div class="k">Total clicks</div>
    <div class="v"><?= number_format($total_clicks) ?></div>
    <div class="s"><?= $total_clicks ? number_format($total_clicks / max(1, count($links)), 1) . ' avg / link' : 'no clicks yet' ?></div>
  </div>
  <?php if ($custom_limit === null || $custom_limit > 0): ?>
  <div class="stat glass">
    <div class="k">Custom names</div>
    <div class="v"><?= number_format($custom_count) ?></div>
    <div class="s"><?= $custom_limit === null ? 'unlimited' : $custom_count . ' / ' . $custom_limit ?></div>
  </div>
  <?php endif; ?>
  <div class="stat glass">
    <div class="k">Top link</div>
    <?php if ($top && (int) $top['clicks'] > 0): ?>
      <div class="v"><?= number_format((int) $top['clicks']) ?> <small>clicks</small></div>
      <div class="s">/<?= e($top['code']) ?></div>
    <?php else: ?>
      <div class="v">—</div>
      <div class="s">share a link to start</div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<div class="card glass">
  <h2>Create a short link</h2>
  <?php if (is_on_trial($user) && trial_active($user)): ?>
    <p class="sub">You're on the <strong>free trial</strong> — <strong><?= trial_days_left($user) ?> day<?= trial_days_left($user) === 1 ? '' : 's' ?></strong> left. <a href="upgrade">Upgrade →</a></p>
  <?php elseif (trial_expired($user)): ?>
    <p class="sub">Your free trial has <strong>ended</strong>. Existing links still redirect; <a href="upgrade">upgrade to create new ones →</a></p>
  <?php else: ?>
    <p class="sub">On the <strong><?= e($plan['name']) ?></strong> plan.</p>
  <?php endif; ?>

  <div class="meter">
    <div class="label">
      <span><?= e($usage_label) ?></span>
      <span><?= $limit === null ? $used . ' · unlimited' : $used . ' / ' . $limit ?></span>
    </div>
    <?php if ($limit !== null): ?>
      <div class="bar"><div class="fill <?= $pct >= 80 ? 'warn' : '' ?>" style="width:<?= $pct ?>%"></div></div>
    <?php else: ?>
      <div class="bar"><div class="fill" style="width:100%"></div></div>
    <?php endif; ?>
  </div>

  <?php require __DIR__ . '/inc/shorten_box.php'; ?>
</div>

<div class="card glass">
  <h2>All links</h2>
  <p class="sub">Click counts update in real time. Scan a QR or copy a link to share.</p>

  <?php if (!$links): ?>
    <div class="empty">No links yet — create your first one above. ✂</div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="links-table" id="links-table">
    <thead>
      <tr><th>QR</th><th>Short link</th><th>Destination</th><th>Clicks</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($links as $l): $short = BASE_HREF . $l['code']; ?>
      <tr>
        <td><div class="qr" style="width:56px;height:56px;padding:4px" data-qr="<?= e($short) ?>"></div></td>
        <td class="short">
          <a href="<?= e($short) ?>" target="_blank" rel="noopener"><?= e(preg_replace('|^https?://|', '', $short)) ?></a>
          <?php if ($l['is_custom']): ?><span class="custom-badge">custom</span><?php endif; ?>
          <?php if ($l['blocked']): ?><span class="custom-badge badge-danger">disabled</span><?php endif; ?>
        </td>
        <td class="long" title="<?= e($l['long_url']) ?>"><?= e($l['long_url']) ?></td>
        <td class="clicks">
          <?php $cn = (int) $l['clicks']; $cw = $max_clicks > 0 ? round($cn / $max_clicks * 100) : 0; ?>
          <div class="click-cell<?= $cn === 0 ? ' zero' : '' ?>">
            <span class="n"><?= number_format($cn) ?></span>
            <span class="cbar"><i style="width:<?= $cw ?>%"></i></span>
          </div>
        </td>
        <td>
          <div class="row-actions">
            <a class="icon-btn" href="<?= e($short) ?>" target="_blank" rel="noopener" title="Open" aria-label="Open short link /<?= e($l['code']) ?>">↗</a>
            <button class="icon-btn" type="button" data-copy="<?= e($short) ?>" title="Copy" aria-label="Copy short link /<?= e($l['code']) ?>">⧉</button>
            <form method="post" action="link-toggle" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="code" value="<?= e($l['code']) ?>">
              <button class="icon-btn" type="submit" title="<?= $l['blocked'] ? 'Enable' : 'Disable' ?>" aria-label="<?= $l['blocked'] ? 'Enable' : 'Disable' ?> link /<?= e($l['code']) ?>"><?= $l['blocked'] ? '▶' : '⏸' ?></button>
            </form>
            <form method="post" action="delete" onsubmit="return confirm('Delete this link?');" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="code" value="<?= e($l['code']) ?>">
              <button class="icon-btn danger" type="submit" title="Delete" aria-label="Delete link /<?= e($l['code']) ?>">✕</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
