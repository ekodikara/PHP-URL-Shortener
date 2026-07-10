<?php
/*
 * Snip — Terms of Service & Acceptable Use Policy.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

render_header('Terms of Service');
?>
<section class="hero" style="padding:40px 0 8px">
  <h1>Terms &amp; <span class="grad">acceptable use</span></h1>
  <p>The short version: use <?= e(APP_NAME) ?> for links you'd stand behind.
     Abuse gets removed, fast.</p>
</section>

<div class="card glass" style="max-width:720px;margin:0 auto">
  <h2>1. The service</h2>
  <p class="sub"><?= e(APP_NAME) ?> shortens URLs and redirects visitors to the destination
    chosen by the link's creator. Links are created by registered account holders and remain
    their responsibility. The service is provided "as is", without warranty of any kind.</p>

  <h2>2. Acceptable use</h2>
  <p class="sub">You may not create or share short links that point to, or are used for:</p>
  <ul style="color:var(--ink-dim);line-height:1.7;margin:0 0 16px 20px">
    <li>Phishing, credential harvesting, or impersonation of any person or brand</li>
    <li>Malware, spyware, or any software installed without informed consent</li>
    <li>Unsolicited bulk messaging (spam), whether by email, SMS, or other channels</li>
    <li>Fraud, scams, or deceptive commercial practices</li>
    <li>Sexually explicit or pornographic material</li>
    <li>Content that is harmful or inappropriate to minors</li>
    <li>Content that is illegal in Australia or in the jurisdiction it targets</li>
    <li>Circumventing blocks or filters applied to another URL or domain</li>
  </ul>

  <h2>3. Enforcement</h2>
  <p class="sub">We may disable any link, suspend any account, and withhold any plan features
    at our sole discretion and without prior notice where we believe these terms are being
    violated. Repeated or serious abuse leads to permanent account termination. Fees, where
    applicable, are not refunded for accounts terminated for abuse.</p>

  <h2>4. Reporting abuse</h2>
  <p class="sub">Anyone can report a link via the <a href="report">report page</a> or by
    mailing <a href="mailto:<?= e(ABUSE_EMAIL) ?>"><?= e(ABUSE_EMAIL) ?></a>. Reported links
    may be disabled automatically pending review. We aim to action verified abuse reports
    within one business day.</p>

  <h2>5. Logging &amp; disclosure</h2>
  <p class="sub">We log link creation and visits (timestamps, IP addresses, and browser
    metadata) for security, quota, and abuse-prevention purposes. We may disclose these
    records where required by law or a valid legal request.</p>

  <h2>6. Liability</h2>
  <p class="sub">Destinations belong to third parties; we do not control and are not
    responsible for their content. To the maximum extent permitted by law, our liability
    arising out of the service is limited to the amount you paid us in the preceding
    12 months.</p>

  <h2>7. Changes</h2>
  <p class="sub">We may update these terms from time to time; continued use of the service
    after a change constitutes acceptance. This page always carries the current version.</p>
</div>
<?php render_footer(); ?>
