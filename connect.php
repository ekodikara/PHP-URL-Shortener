<?php
/*
 * Snip — Connect AI (MCP). Generate/manage API tokens for the MCP server.
 * Paid plans only.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

require_login();
$user = current_user();

// Gate to active paid plans.
if (!plan_is_paid($user)) {
    set_flash('error', 'MCP access is a paid feature. Upgrade to Pro or Premium to connect your AI assistant.');
    redirect_to('upgrade');
}

$new_token = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'create' && ($gate = captcha_gate($pdo, 'token_create')) !== '') {
        set_flash('error', $gate === 'captcha'
            ? 'Please complete the verification and try again.'
            : 'Suspicious activity detected. Please try again later.');
        redirect_to('connect');
    } elseif ($action === 'create') {
        $scope = (isset($_POST['scope']) && $_POST['scope'] === 'read') ? 'read' : 'full';
        $new_token = generate_api_token($pdo, $user['id'], isset($_POST['label']) ? $_POST['label'] : 'MCP token', $scope);
        set_flash('success', 'Token created — copy it now, it won\'t be shown again.');
    } elseif ($action === 'revoke') {
        revoke_api_token($pdo, $user['id'], (int) (isset($_POST['id']) ? $_POST['id'] : 0));
        set_flash('success', 'Token revoked.');
        redirect_to('connect');
    }
}

$tokens = list_api_tokens($pdo, $user['id']);
$mcp_url = BASE_HREF . 'mcp';

render_header('Connect AI');
?>
<section class="hero" style="padding:36px 0 8px">
  <h1>Connect your <span class="grad">AI assistant</span></h1>
  <p>Use <?= e(APP_NAME) ?> from Claude and other MCP clients — shorten links, list them, check stats, and delete them by just asking.</p>
</section>

<?php if ($new_token): ?>
<div class="card glass" style="border-color:rgba(182,255,60,0.5)">
  <h2>Your new token</h2>
  <p class="sub">Copy it now — for security we only store a hash and can't show it again.</p>
  <div class="result-inner">
    <div class="result-link">
      <div class="lbl">API token</div>
      <div class="url" style="font-size:1rem"><?= e($new_token) ?></div>
    </div>
    <button class="icon-btn" type="button" data-copy="<?= e($new_token) ?>" title="Copy">⧉</button>
  </div>
</div>
<?php endif; ?>

<div class="card glass">
  <h2>1 · Generate a token</h2>
  <p class="sub">Each token authenticates one MCP client as you.</p>
  <form method="post" action="connect">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="input-row">
      <input type="text" name="label" placeholder="e.g. My laptop — Claude" maxlength="80">
      <button class="btn btn-solid" type="submit">Generate token</button>
    </div>
    <div class="field" style="margin-top:12px">
      <label style="display:inline-flex;align-items:center;gap:8px;margin-right:18px">
        <input type="radio" name="scope" value="full" checked> Full access <span style="color:var(--ink-faint)">(create, list, stats, delete)</span>
      </label>
      <label style="display:inline-flex;align-items:center;gap:8px">
        <input type="radio" name="scope" value="read"> Read-only <span style="color:var(--ink-faint)">(list + stats)</span>
      </label>
    </div>
    <?php if (captcha_needed($pdo)): ?><?= recaptcha_block() ?><?php endif; ?>
  </form>

  <?php if ($tokens): ?>
  <table class="links-table" style="margin-top:18px">
    <thead><tr><th>Label</th><th>Scope</th><th>Created</th><th>Last used</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tokens as $t): ?>
      <tr>
        <td><?= e($t['label']) ?></td>
        <td><span class="custom-badge"><?= $t['scope'] === 'read' ? 'read-only' : 'full' ?></span></td>
        <td><?= e(gmdate('Y-m-d', (int) $t['created'])) ?></td>
        <td><?= $t['last_used'] ? e(gmdate('Y-m-d H:i', (int) $t['last_used'])) . ' UTC' : '<span style="color:var(--ink-faint)">never</span>' ?></td>
        <td>
          <form method="post" action="connect" onsubmit="return confirm('Revoke this token? Clients using it will stop working.');" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="revoke">
            <input type="hidden" name="id" value="<?= e($t['id']) ?>">
            <button class="icon-btn danger" type="submit" title="Revoke">✕</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card glass">
  <h2>2 · Add Snip to your AI client</h2>
  <p class="sub">MCP endpoint (Streamable HTTP):</p>
  <div class="result-inner" style="margin-bottom:16px">
    <div class="result-link"><div class="url" style="font-size:1.05rem"><?= e($mcp_url) ?></div></div>
    <button class="icon-btn" type="button" data-copy="<?= e($mcp_url) ?>" title="Copy">⧉</button>
  </div>
  <p class="sub">Claude Code (replace <code>TOKEN</code> with the one above):</p>
  <pre style="background:rgba(0,0,0,0.35);border:1px solid var(--stroke);border-radius:12px;padding:14px;overflow-x:auto;color:var(--ink);font-size:0.85rem"><code>claude mcp add --transport http snip <?= e($mcp_url) ?> \
  --header "Authorization: Bearer TOKEN"</code></pre>
  <p class="hint">Then ask your assistant: “shorten https://example.com” or “list my Snip links”. Available tools: <code>shorten_url</code>, <code>list_links</code>, <code>get_stats</code>, <code>delete_link</code>.</p>
</div>
<?php render_footer(); ?>
