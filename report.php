<?php
/*
 * Snip — public abuse-report form for short links.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$REASONS = array('phishing', 'malware', 'spam', 'other');

$sent = false;
$error = '';
$code   = trim((string) ($_GET['code'] ?? ''));
$reason = 'phishing';
$detail = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $code   = trim((string) ($_POST['code'] ?? ''));
    $reason = (string) ($_POST['reason'] ?? 'other');
    $detail = trim((string) ($_POST['detail'] ?? ''));
    $email  = trim((string) ($_POST['email'] ?? ''));

    // Accept a bare code or a pasted full short URL.
    if (preg_match('|https?://[^/]+/([A-Za-z0-9_-]{1,40})|i', $code, $m)) {
        $code = $m[1];
    }

    if (!rate_limit($pdo, 'report:' . client_ip(), 5, 3600)) {
        http_response_code(429);
        $error = 'Too many reports from your address. Please try again later.';
    } elseif (!empty($_POST['hp_url'])) {
        log_security_event($pdo, 'bot_blocked', 'report:honeypot');
        $error = 'Could not verify your request.';
    } elseif (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $code)) {
        $error = 'Please paste the short link (or its code) you want to report.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That contact email doesn\'t look valid.';
    } else {
        if (!in_array($reason, $REASONS, true)) {
            $reason = 'other';
        }
        $stmt = $pdo->prepare('SELECT id FROM urls WHERE code = ?');
        $stmt->execute(array($code));
        $url = $stmt->fetch();
        // Accept the report even for unknown codes (don't leak which exist),
        // but only store/act on ones that map to a real link.
        if ($url) {
            $pdo->prepare('INSERT INTO link_reports (ts, code, reason, detail, email, ip) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute(array(time(), $code, $reason, mb_substr($detail, 0, 2000), mb_substr($email, 0, 190), client_ip()));
            log_security_event($pdo, 'link_reported', $reason . ' ' . $code);

            // Several open reports → disable the link pending admin review.
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM link_reports WHERE code = ? AND status = 'open'");
            $cnt->execute(array($code));
            if ((int) $cnt->fetchColumn() >= AUTO_BLOCK_REPORTS) {
                $pdo->prepare('UPDATE urls SET blocked = 1 WHERE code = ?')->execute(array($code));
                log_security_event($pdo, 'auto_block_reported', $code);
            }
        }
        $sent = true;
    }
}

render_header('Report abuse');
?>
<section class="hero" style="padding:40px 0 8px">
  <h1>Report a <span class="grad">link</span></h1>
  <p>Seen a <?= e(APP_NAME) ?> short link used for phishing, malware or spam?
     Tell us and we'll review it — reported links can be disabled immediately.</p>
</section>

<div class="card glass" style="max-width:620px;margin:0 auto">
<?php if ($sent): ?>
  <div style="text-align:center;padding:20px 0">
    <div style="font-size:3rem;line-height:1">🛡️</div>
    <h2>Thanks — report received</h2>
    <p class="sub">Our team will review it shortly. Urgent cases can also be mailed to
      <a href="mailto:<?= e(ABUSE_EMAIL) ?>"><?= e(ABUSE_EMAIL) ?></a>.</p>
    <a class="btn btn-ghost" href="/">Back home</a>
  </div>
<?php else: ?>
  <h2>Report abuse</h2>
  <p class="sub">Paste the short link (or just its code) and what's wrong with it.</p>
  <?php if ($error): ?><div class="form-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="report">
    <?= csrf_field() ?>
    <div class="hp-field" aria-hidden="true"><label>Leave blank<input type="text" name="hp_url" tabindex="-1" autocomplete="off"></label></div>
    <div class="field"><label for="code">Short link or code</label>
      <input type="text" id="code" name="code" value="<?= e($code) ?>" placeholder="<?= e(BASE_HREF) ?>abc123" required></div>
    <div class="field"><label for="reason">What's the problem?</label>
      <select id="reason" name="reason" style="width:100%;padding:14px 16px;font-family:var(--font-body);color:var(--ink);background:rgba(0,0,0,0.25);border:1px solid var(--stroke);border-radius:var(--radius-s)">
        <?php foreach ($REASONS as $r): ?>
          <option value="<?= e($r) ?>"<?= $r === $reason ? ' selected' : '' ?>><?= e(ucfirst($r)) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><label for="detail">Details (optional)</label>
      <textarea id="detail" name="detail" rows="4" style="width:100%;padding:14px 16px;font-family:var(--font-body);color:var(--ink);background:rgba(0,0,0,0.25);border:1px solid var(--stroke);border-radius:var(--radius-s)"><?= e($detail) ?></textarea></div>
    <div class="field"><label for="email">Your email (optional, for follow-up)</label>
      <input type="email" id="email" name="email" value="<?= e($email) ?>"></div>
    <button class="btn btn-solid btn-block" type="submit">Send report</button>
  </form>
<?php endif; ?>
</div>
<?php render_footer(); ?>
