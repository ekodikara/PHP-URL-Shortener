<?php
/*
 * Snip — the AJAX shorten form. Expects $user (current user row) in scope.
 * Shows the custom-name field only for plans that allow it.
 */
$__plan = plan_config($user['plan']);
$__can_custom = $__plan['custom_slugs'] > 0;

if (trial_expired($user)):
?>
<div class="result-inner" style="display:block;text-align:center">
  <p style="margin:0 0 14px;color:var(--ink-dim)">
    Your <?= e(TRIAL_DAYS) ?>-day free trial has ended. Your existing links still work —
    upgrade to keep creating new ones.
  </p>
  <a class="btn btn-solid" href="upgrade">See plans →</a>
</div>
<?php return; endif; ?>
<form id="shorten-form" autocomplete="off">
  <?= csrf_field() ?>
  <div class="field">
    <label for="longurl">Paste a long URL</label>
    <div class="input-row">
      <input type="url" id="longurl" name="longurl" placeholder="https://example.com/a/very/long/path…" required>
      <button class="btn btn-solid" type="submit">Shorten</button>
    </div>
  </div>

  <?php if ($__can_custom): ?>
  <div class="field">
    <label for="slug">Custom name <span style="color:var(--ink-faint)">(optional)</span></label>
    <div class="slug-row">
      <span class="prefix"><?= e(preg_replace('|^https?://|', '', BASE_HREF)) ?></span>
      <input type="text" id="slug" name="slug" placeholder="my-link" pattern="[A-Za-z0-9_-]{3,40}">
    </div>
    <p class="hint">3–40 letters, numbers, hyphens or underscores.</p>
  </div>
  <?php else: ?>
  <p class="hint">Want your own link names like <code><?= e(preg_replace('|^https?://|', '', BASE_HREF)) ?>my-launch</code>? <a href="upgrade">Upgrade to Premium →</a></p>
  <?php endif; ?>
  <?php if (captcha_needed($pdo)): ?><?= recaptcha_block() ?><?php endif; ?>
</form>

<div class="result" id="shorten-result">
  <div class="result-inner">
    <div class="qr" data-qr-target></div>
    <div class="result-link">
      <div class="lbl">Your short link</div>
      <div class="url"></div>
    </div>
    <div class="row-actions">
      <a class="icon-btn open-link" href="#" target="_blank" rel="noopener" title="Open">↗</a>
      <button class="icon-btn" type="button" data-copy="" title="Copy">⧉</button>
    </div>
  </div>
</div>
