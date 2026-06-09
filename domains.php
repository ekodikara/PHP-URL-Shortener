<?php
/*
 * Snip — custom domain management (Enterprise). Add, verify (DNS TXT), remove.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

require_login();
$user = current_user();

// Custom domains are an Enterprise capability.
if ($user['plan'] !== 'enterprise') {
    set_flash('error', 'Custom domains are an Enterprise feature. Talk to us about it.');
    redirect_to('enterprise');
}

$added = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        list($added, $err) = add_domain($pdo, $user['id'], $_POST['host'] ?? '');
        set_flash($err ? 'error' : 'success', $err ?: 'Domain added — add the TXT record, then verify.');
        if ($err) { redirect_to('domains'); }
    } elseif ($action === 'verify') {
        list($ok, $msg) = verify_domain($pdo, $user['id'], (int) ($_POST['id'] ?? 0));
        set_flash($ok ? 'success' : 'error', $msg);
        redirect_to('domains');
    } elseif ($action === 'remove') {
        $pdo->prepare('DELETE FROM domains WHERE id = ? AND user_id = ?')->execute(array((int) ($_POST['id'] ?? 0), $user['id']));
        set_flash('success', 'Domain removed.');
        redirect_to('domains');
    }
}

$domains = list_domains($pdo, $user['id']);
$ip_hint = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : 'your-server-ip';

render_header('Custom domains');
?>
<section class="hero" style="padding:36px 0 8px">
  <h1>Custom <span class="grad">domains</span></h1>
  <p>Serve your short links from your own branded domain, e.g. <code>go.acme.com/launch</code>.</p>
</section>

<?php if ($added): ?>
<div class="card glass" style="border-color:rgba(182,255,60,0.5)">
  <h2>Verify <?= e($added['host']) ?></h2>
  <p class="sub">1) Point the domain at this app — add a DNS <strong>A/CNAME</strong> record to your server.<br>
     2) Add a DNS <strong>TXT</strong> record on <code><?= e($added['host']) ?></code> with this value, then click Verify:</p>
  <div class="result-inner"><div class="result-link"><div class="url" style="font-size:1rem"><?= e($added['token']) ?></div></div>
    <button class="icon-btn" type="button" data-copy="<?= e($added['token']) ?>" title="Copy">⧉</button></div>
</div>
<?php endif; ?>

<div class="card glass">
  <h2>Add a domain</h2>
  <form method="post" action="domains" class="input-row">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <input type="text" name="host" placeholder="go.acme.com" required>
    <button class="btn btn-solid" type="submit">Add domain</button>
  </form>
  <p class="hint">Point the domain's A/CNAME record at this server (<?= e($ip_hint) ?>); TLS is issued automatically on first request.</p>

  <?php if ($domains): ?>
  <table class="links-table" style="margin-top:18px">
    <thead><tr><th>Domain</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($domains as $d): ?>
      <tr>
        <td class="short"><?= e($d['host']) ?></td>
        <td><?= $d['verified'] ? '<span style="color:var(--accent-2)">verified</span>' : '<span style="color:var(--accent-3)">pending</span>' ?></td>
        <td>
          <div class="row-actions">
            <?php if (!$d['verified']): ?>
            <form method="post" action="domains" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="id" value="<?= e($d['id']) ?>">
              <button class="btn btn-ghost" type="submit" style="padding:6px 12px">Verify</button>
            </form>
            <?php endif; ?>
            <form method="post" action="domains" onsubmit="return confirm('Remove this domain?');" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= e($d['id']) ?>">
              <button class="icon-btn danger" type="submit" title="Remove">✕</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <p class="empty">No custom domains yet.</p>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
