<?php
/*
 * Snip — admin panel: block/unblock users & links, review access + security logs.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

require_login();
$me = current_user();
if (!is_admin($me)) {
    http_response_code(403);
    set_flash('error', 'Admins only.');
    redirect_to('dashboard');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'block_user' || $action === 'unblock_user') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $block = $action === 'block_user' ? 1 : 0;
        $reason = $block ? mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255) : null;
        // Don't let an admin lock themselves out.
        if ($uid === (int) $me['id']) {
            set_flash('error', 'You can\'t block your own account.');
        } else {
            $pdo->prepare('UPDATE users SET blocked = ?, blocked_reason = ? WHERE id = ?')
                ->execute(array($block, $reason, $uid));
            log_security_event($pdo, $block ? 'admin_block_user' : 'admin_unblock_user', 'uid=' . $uid, $me['id']);
            set_flash('success', $block ? 'User blocked.' : 'User unblocked.');
        }
    } elseif ($action === 'block_link' || $action === 'unblock_link') {
        $code = (string) ($_POST['code'] ?? '');
        $block = $action === 'block_link' ? 1 : 0;
        $pdo->prepare('UPDATE urls SET blocked = ? WHERE code = ?')->execute(array($block, $code));
        log_security_event($pdo, $block ? 'admin_block_link' : 'admin_unblock_link', $code, $me['id']);
        set_flash('success', $block ? 'Link disabled.' : 'Link enabled.');
    } elseif ($action === 'resolve_report' || $action === 'dismiss_report') {
        $rid = (int) ($_POST['report_id'] ?? 0);
        $status = $action === 'resolve_report' ? 'resolved' : 'dismissed';
        // Resolving = the abuse was real: also disable the reported link.
        if ($status === 'resolved') {
            $sel = $pdo->prepare('SELECT code FROM link_reports WHERE id = ?');
            $sel->execute(array($rid));
            if ($rcode = $sel->fetchColumn()) {
                $pdo->prepare('UPDATE urls SET blocked = 1 WHERE code = ?')->execute(array($rcode));
                log_security_event($pdo, 'admin_block_link', $rcode . ' (report #' . $rid . ')', $me['id']);
            }
        }
        $pdo->prepare('UPDATE link_reports SET status = ? WHERE id = ?')->execute(array($status, $rid));
        set_flash('success', $status === 'resolved' ? 'Report resolved — link disabled.' : 'Report dismissed.');
    }
    redirect_to('admin');
}

$users   = $pdo->query('SELECT id, email, plan, is_admin, blocked, blocked_reason, created FROM users ORDER BY created DESC LIMIT 100')->fetchAll();
$flagged = $pdo->query('SELECT code, long_url, user_id, blocked, clicks FROM urls WHERE blocked = 1 ORDER BY id DESC LIMIT 50')->fetchAll();
$leads   = $pdo->query('SELECT ts, name, email, company, message FROM enterprise_leads ORDER BY id DESC LIMIT 25')->fetchAll();
$reports = $pdo->query(
    "SELECT r.id, r.ts, r.code, r.reason, r.detail, r.email, r.status, u.long_url, u.blocked AS link_blocked
       FROM link_reports r LEFT JOIN urls u ON u.code = r.code
      ORDER BY r.status = 'open' DESC, r.id DESC LIMIT 50"
)->fetchAll();
$access  = $pdo->query('SELECT ts, event, code, ip, browser, platform, referer FROM access_log ORDER BY id DESC LIMIT 25')->fetchAll();
$sec     = $pdo->query('SELECT ts, event, ip, detail FROM security_log ORDER BY id DESC LIMIT 25')->fetchAll();

render_header('Admin');
?>
<section style="margin:24px 0 8px">
  <h1 style="font-family:var(--font-display);font-weight:800;letter-spacing:-.03em;font-size:2rem;margin:0">Admin</h1>
  <p style="color:var(--ink-dim);margin:6px 0 0">Moderation &amp; security review.</p>
</section>

<div class="card glass">
  <h2>Users</h2>
  <div style="overflow-x:auto">
  <table class="links-table">
    <thead><tr><th>Email</th><th>Plan</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= e($u['email']) ?><?= $u['is_admin'] ? ' <span class="custom-badge">admin</span>' : '' ?></td>
        <td><?= e(plan_config($u['plan'])['name']) ?></td>
        <td><?= $u['blocked'] ? '<span style="color:var(--danger)">blocked</span>' : '<span style="color:var(--accent-2)">active</span>' ?></td>
        <td>
          <?php if (!$u['is_admin']): ?>
          <form method="post" action="admin" style="display:flex;gap:6px;align-items:center">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
            <?php if ($u['blocked']): ?>
              <input type="hidden" name="action" value="unblock_user">
              <button class="icon-btn" type="submit" title="Unblock">▶</button>
            <?php else: ?>
              <input type="hidden" name="action" value="block_user">
              <input type="text" name="reason" placeholder="reason (optional)" style="width:160px;padding:6px 8px">
              <button class="icon-btn danger" type="submit" title="Block">⛔</button>
            <?php endif; ?>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="card glass">
  <h2>Disabled links</h2>
  <?php if (!$flagged): ?>
    <p class="sub">No links are currently disabled. Disable one by code:</p>
    <form method="post" action="admin" class="input-row">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="block_link">
      <input type="text" name="code" placeholder="short code" required>
      <button class="btn btn-ghost" type="submit">Disable link</button>
    </form>
  <?php else: ?>
  <table class="links-table">
    <thead><tr><th>Code</th><th>Destination</th><th>Clicks</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($flagged as $l): ?>
      <tr>
        <td class="short"><?= e($l['code']) ?></td>
        <td class="long" title="<?= e($l['long_url']) ?>"><?= e($l['long_url']) ?></td>
        <td class="clicks"><?= number_format((int) $l['clicks']) ?></td>
        <td>
          <form method="post" action="admin" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="unblock_link">
            <input type="hidden" name="code" value="<?= e($l['code']) ?>">
            <button class="icon-btn" type="submit" title="Enable">▶</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card glass">
  <h2>Abuse reports</h2>
  <div style="overflow-x:auto">
  <table class="links-table">
    <thead><tr><th>When</th><th>Code</th><th>Destination</th><th>Reason</th><th>Detail</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($reports as $r): ?>
      <tr>
        <td><?= e(gmdate('m-d H:i', (int) $r['ts'])) ?></td>
        <td class="short"><?= e($r['code']) ?><?= $r['link_blocked'] ? ' <span class="custom-badge">disabled</span>' : '' ?></td>
        <td class="long" title="<?= e($r['long_url']) ?>"><?= e($r['long_url'] ?? '(deleted)') ?></td>
        <td><?= e($r['reason']) ?></td>
        <td class="long" title="<?= e($r['detail']) ?>"><?= e($r['detail']) ?></td>
        <td><?= e($r['status']) ?></td>
        <td>
          <?php if ($r['status'] === 'open'): ?>
          <form method="post" action="admin" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="report_id" value="<?= e($r['id']) ?>">
            <input type="hidden" name="action" value="resolve_report">
            <button class="icon-btn danger" type="submit" title="Abuse confirmed — disable link">⛔</button>
          </form>
          <form method="post" action="admin" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="report_id" value="<?= e($r['id']) ?>">
            <input type="hidden" name="action" value="dismiss_report">
            <button class="icon-btn" type="submit" title="Dismiss report">✕</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$reports): ?><tr><td colspan="7" style="color:var(--ink-faint)">No reports yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="card glass">
  <h2>Enterprise leads</h2>
  <div style="overflow-x:auto">
  <table class="links-table">
    <thead><tr><th>When</th><th>Name</th><th>Email</th><th>Company</th><th>Message</th></tr></thead>
    <tbody>
    <?php foreach ($leads as $l): ?>
      <tr>
        <td><?= e(gmdate('m-d H:i', (int) $l['ts'])) ?></td>
        <td><?= e($l['name']) ?></td>
        <td><?= e($l['email']) ?></td>
        <td><?= e($l['company']) ?></td>
        <td class="long" title="<?= e($l['message']) ?>"><?= e($l['message']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$leads): ?><tr><td colspan="5" style="color:var(--ink-faint)">No leads yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="card glass">
  <h2>Recent access</h2>
  <div style="overflow-x:auto">
  <table class="links-table">
    <thead><tr><th>When</th><th>Event</th><th>Code</th><th>IP</th><th>Browser</th><th>Platform</th></tr></thead>
    <tbody>
    <?php foreach ($access as $a): ?>
      <tr>
        <td><?= e(gmdate('m-d H:i', (int) $a['ts'])) ?></td>
        <td><?= e($a['event']) ?></td>
        <td class="short"><?= e($a['code']) ?></td>
        <td><?= e($a['ip']) ?></td>
        <td><?= e($a['browser']) ?></td>
        <td><?= e($a['platform']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$access): ?><tr><td colspan="6" style="color:var(--ink-faint)">No access logged yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="card glass">
  <h2>Security events</h2>
  <div style="overflow-x:auto">
  <table class="links-table">
    <thead><tr><th>When</th><th>Event</th><th>IP</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($sec as $s): ?>
      <tr>
        <td><?= e(gmdate('m-d H:i', (int) $s['ts'])) ?></td>
        <td><?= e($s['event']) ?></td>
        <td><?= e($s['ip']) ?></td>
        <td class="long"><?= e($s['detail']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$sec): ?><tr><td colspan="4" style="color:var(--ink-faint)">No events yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php render_footer(); ?>
