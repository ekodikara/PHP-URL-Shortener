<?php
/*
 * Snip — per-link analytics. Owner-scoped. Clean URL: /stats?code=<code>
 * (add 'stats' to RESERVED_SLUGS). Pure aggregation over access_log via
 * inc/analytics.php — clicks over time, unique visitors, referrers, and
 * browser/OS/device breakdowns, plus a CSV export. Charts are inline SVG
 * (CSP-safe, no CDN).
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

require_login();
$user = current_user();

$code = isset($_GET['code']) ? (string) $_GET['code'] : '';
$link = $code !== '' ? link_owned_by($pdo, $user['id'], $code) : null;
if (!$link) {
    render_error_page(404, 'Link not found', 'That link doesn\'t exist or isn\'t yours.');
}

$rangeKey = isset($_GET['range']) ? (string) $_GET['range'] : '30';
list($since, $days, $rangeLabel) = analytics_range($rangeKey);

$summary = link_click_summary($pdo, $code, $since);
$series  = link_click_timeseries($pdo, $code, $since, $days);

// CSV export — must run before any HTML is emitted.
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $fname = 'snip-' . preg_replace('/[^A-Za-z0-9_-]/', '', $code) . '-clicks.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array('date', 'clicks', 'unique_visitors'));
    foreach ($series as $pt) {
        fputcsv($out, array(gmdate('Y-m-d', $pt['day']), $pt['clicks'], $pt['uniques']));
    }
    fclose($out);
    exit;
}

$refs     = link_referrers($pdo, $code, $since);
$browsers = link_breakdown($pdo, $code, $since, 'browser');
$oses     = link_breakdown($pdo, $code, $since, 'platform');
$devices  = link_breakdown($pdo, $code, $since, 'device');

$short   = BASE_HREF . $link['code'];
$avgDay  = $days > 0 ? $summary['clicks'] / $days
                     : (count($series) ? $summary['clicks'] / max(1, count($series)) : 0);

// --- tiny view helpers (closures avoid page-include redefinition) -----------
$chart = function (array $series) {
    if (!$series) {
        return '<p class="sub">No clicks in this range yet.</p>';
    }
    $max = 1;
    foreach ($series as $p) { $max = max($max, $p['clicks']); }
    $n = count($series); $w = 100; $h = 40; $bw = $w / $n; $i = 0; $bars = '';
    foreach ($series as $p) {
        $bh = $p['clicks'] / $max * ($h - 4);
        $x  = $i * $bw; $y = $h - $bh;
        $t  = gmdate('M j', $p['day']) . ': ' . $p['clicks'] . ' clicks, ' . $p['uniques'] . ' unique';
        $bars .= '<rect x="' . round($x + $bw * 0.12, 2) . '" y="' . round($y, 2)
               . '" width="' . round($bw * 0.76, 2) . '" height="' . round($bh, 2) . '">'
               . '<title>' . e($t) . '</title></rect>';
        $i++;
    }
    return '<svg class="click-chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none"'
         . ' role="img" aria-label="Clicks over time">' . $bars . '</svg>';
};
$ranked = function (array $rows, $empty = '—') {
    if (!$rows) {
        return '<p class="sub">No data yet.</p>';
    }
    $max = 1;
    foreach ($rows as $r) { $max = max($max, $r['clicks']); }
    $html = '<ul class="rank-list">';
    foreach ($rows as $r) {
        $label = $r['key'] === '' ? $empty : $r['key'];
        $pct = round($r['clicks'] / $max * 100);
        $html .= '<li><span class="rk-label" title="' . e($label) . '">' . e($label) . '</span>'
              . '<span class="rk-bar"><i style="width:' . $pct . '%"></i></span>'
              . '<span class="rk-n">' . number_format($r['clicks']) . '</span></li>';
    }
    return $html . '</ul>';
};

render_header('Stats · /' . $link['code']);
?>
<section class="page-head">
  <div>
    <h1>Link analytics</h1>
    <p class="sub">
      <a href="<?= e($short) ?>" target="_blank" rel="noopener"><?= e(preg_replace('|^https?://|', '', $short)) ?></a>
      <?php if ($link['blocked']): ?><span class="custom-badge badge-danger">disabled</span><?php endif; ?>
      → <span title="<?= e($link['long_url']) ?>"><?= e(mb_strimwidth($link['long_url'], 0, 60, '…')) ?></span>
    </p>
  </div>
  <div class="page-head-actions">
    <a class="btn btn-ghost" href="stats?code=<?= e($link['code']) ?>&amp;range=<?= e($rangeKey) ?>&amp;export=csv">Export CSV</a>
    <a class="btn btn-ghost" href="dashboard">← Dashboard</a>
  </div>
</section>

<nav class="range-tabs" aria-label="Date range">
  <?php foreach (array('7' => '7 days', '30' => '30 days', '90' => '90 days', 'all' => 'All time') as $k => $lbl): ?>
    <a href="stats?code=<?= e($link['code']) ?>&amp;range=<?= e($k) ?>"
       class="range-tab<?= $k === $rangeKey ? ' is-active' : '' ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>
</nav>

<section class="stat-row">
  <div class="stat glass">
    <div class="k">Clicks</div>
    <div class="v"><?= number_format($summary['clicks']) ?></div>
    <div class="s"><?= e($rangeLabel) ?></div>
  </div>
  <div class="stat glass">
    <div class="k">Unique visitors</div>
    <div class="v"><?= number_format($summary['uniques']) ?></div>
    <div class="s">by IP + device, per day</div>
  </div>
  <div class="stat glass">
    <div class="k">Lifetime clicks</div>
    <div class="v"><?= number_format((int) $link['clicks']) ?></div>
    <div class="s">since created</div>
  </div>
  <div class="stat glass">
    <div class="k">Avg / day</div>
    <div class="v"><?= number_format($avgDay, $avgDay < 10 ? 1 : 0) ?></div>
    <div class="s"><?= e($rangeLabel) ?></div>
  </div>
</section>

<div class="card glass">
  <h2>Clicks over time</h2>
  <p class="sub">Hover a bar for the day's totals.</p>
  <?= $chart($series) ?>
  <?php if ($series): ?>
  <div class="chart-axis">
    <span><?= e(gmdate('M j', $series[0]['day'])) ?></span>
    <span><?= e(gmdate('M j', $series[count($series) - 1]['day'])) ?></span>
  </div>
  <?php endif; ?>
</div>

<div class="analytics-grid">
  <div class="card glass">
    <h2>Top referrers</h2>
    <?= $ranked($refs, 'Direct') ?>
  </div>
  <div class="card glass">
    <h2>Devices</h2>
    <?= $ranked($devices) ?>
  </div>
  <div class="card glass">
    <h2>Browsers</h2>
    <?= $ranked($browsers) ?>
  </div>
  <div class="card glass">
    <h2>Operating systems</h2>
    <?= $ranked($oses) ?>
  </div>
</div>

<?php render_footer(); ?>
