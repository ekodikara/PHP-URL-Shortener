<?php
/*
 * Snip — the AJAX shorten form. Expects $user (current user row) in scope.
 * Shows the custom-name field only for plans that allow it.
 */
$__plan = plan_config($user['plan']);
$__can_custom = $__plan['custom_slugs'] === null || $__plan['custom_slugs'] > 0;

// Usage vs the plan's link quota (null limit = unlimited).
$__limit = $__plan['url_limit'];
$__used  = plan_usage($pdo, $user);
$__at_limit  = $__limit !== null && $__used >= $__limit;
$__near_limit = $__limit !== null && !$__at_limit && ($__used / $__limit) >= 0.8;

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
<?php if ($__at_limit): ?>
<div class="result-inner limit-hit" style="display:block;text-align:center" role="status">
  <p style="margin:0 0 14px;color:var(--ink-dim)"><?= e(url_limit_message($__plan)) ?></p>
  <a class="btn btn-solid" href="upgrade">See plans →</a>
</div>
<?php return; endif; ?>
<?php if ($__near_limit): ?>
<p class="hint limit-near" role="status">
  <?php if ($__plan['limit_period'] === 'month'): ?>
    You've used <strong><?= (int) $__used ?> of <?= (int) $__limit ?></strong> links this month.
    <a href="upgrade">Upgrade</a> for a higher limit.
  <?php else: ?>
    You've used <strong><?= (int) $__used ?> of <?= (int) $__limit ?></strong> lifetime links.
    <a href="upgrade">Upgrade</a> before you run out.
  <?php endif; ?>
</p>
<?php endif; ?>
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
      <input type="text" id="slug" name="slug" placeholder="my-link" pattern="[A-Za-z0-9_\-]{3,40}">
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
      <div class="lbl">Snipped</div>
      <div class="url"></div>
      <div class="saved" data-saved hidden></div>
    </div>
    <div class="row-actions">
      <a class="icon-btn open-link" href="#" target="_blank" rel="noopener" title="Open" aria-label="Open short link">↗</a>
      <button class="icon-btn" type="button" data-copy="" title="Copy" aria-label="Copy short link">⧉</button>
    </div>
  </div>
</div>
