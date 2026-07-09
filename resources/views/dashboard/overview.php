<?php
/** @var array $sites @var array $stats @var string $range */
$this->layout('layout', ['title' => 'Overview · Brionic Reports', 'nav' => 'dashboard']);
$totalViews = 0; $totalVisitors = 0;
foreach ($stats as $s) { $totalViews += $s['pageviews']; $totalVisitors += $s['visitors']; }
$online = $online ?? ['sites' => [], 'total' => 0];
$uptime = $uptime ?? [];
$uptime30 = $uptime30 ?? [];
$spark = $spark ?? [];
$map = $map ?? [];

$exportUrl = app_url('dashboard/export.csv') . '?' . http_build_query(array_filter([
    'range' => $range, 'from' => $from ?? null, 'to' => $to ?? null,
]));

/** Tiny inline sparkline from a list of daily values. */
$sparkline = function (array $vals): string {
    if (!$vals || max($vals) === 0) { return '<div class="spark-empty"></div>'; }
    $max = max($vals) ?: 1; $n = count($vals); $w = 140; $h = 30;
    $step = $n > 1 ? $w / ($n - 1) : $w;
    $pts = [];
    foreach ($vals as $i => $v) {
        $x = round($i * $step, 1);
        $y = round($h - ($v / $max) * ($h - 5) - 2, 1);
        $pts[] = $x . ',' . $y;
    }
    $line = implode(' ', $pts);
    $area = '0,' . $h . ' ' . $line . ' ' . $w . ',' . $h;
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none">'
        . '<polygon points="' . $area . '" fill="#d92b32" fill-opacity="0.08"/>'
        . '<polyline points="' . $line . '" fill="none" stroke="#d92b32" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/></svg>';
};
?>
<?php $this->start('content'); ?>

<h1>Overview</h1>
<p class="sub">All your connected sites in one place.</p>

<?php $this->include('partials/filter', [
    'range' => $range, 'base' => app_url('dashboard'),
    'from' => $from ?? null, 'to' => $to ?? null, 'export' => $exportUrl,
]); ?>

<div class="grid grid-4" style="margin-bottom:24px" data-realtime="<?= e(app_url('dashboard/realtime.json')) ?>">
  <div class="stat live"><div class="n"><span class="live-dot"></span><span id="rt-total"><?= (int) $online['total'] ?></span></div><div class="l">Online now</div></div>
  <div class="stat"><div class="n"><?= num($totalVisitors) ?></div><div class="l">Unique visitors</div></div>
  <div class="stat"><div class="n"><?= num($totalViews) ?></div><div class="l">Page views</div></div>
  <div class="stat"><div class="n"><?= count($sites) ?></div><div class="l">Connected sites</div></div>
</div>

<?php if ($map): $mx = max(array_map(fn ($p) => (int) $p['n'], $map)) ?: 1; ?>
  <div class="card" style="margin-bottom:20px">
    <h2>Where your visitors are <span class="muted" style="font-size:.75rem;font-weight:400">all sites</span></h2>
    <div class="geo-map">
      <svg viewBox="0 0 1000 500" preserveAspectRatio="xMidYMid meet">
        <image href="<?= asset('img/worldmap.png') ?>" x="0" y="0" width="1000" height="500" preserveAspectRatio="none"></image>
        <?php foreach ($map as $pt):
          $x = ((float) $pt['rlon'] + 180) / 360 * 1000;
          $y = (90 - (float) $pt['rlat']) / 180 * 500;
          $rr = 3 + (int) round(((int) $pt['n'] / $mx) * 12); ?>
          <circle cx="<?= round($x, 1) ?>" cy="<?= round($y, 1) ?>" r="<?= $rr ?>" fill="#d92b32" fill-opacity="0.5" stroke="#fff" stroke-width="1" stroke-opacity="0.7"><title><?= e(($pt['city'] ?: $pt['country'] ?: 'Unknown') . ' — ' . $pt['n']) ?></title></circle>
        <?php endforeach; ?>
      </svg>
    </div>
  </div>
<?php endif; ?>

<?php if (!$sites): ?>
  <div class="card">
    <h2>No sites yet</h2>
    <p class="muted">Add your first website to start collecting analytics.</p>
    <a class="btn btn-primary" href="<?= app_url('sites') ?>">Add a site</a>
  </div>
<?php else: ?>
  <div class="grid grid-2">
    <?php foreach ($sites as $site): $id = (int) $site['id']; $s = $stats[$id] ?? ['pageviews' => 0, 'visitors' => 0];
      $u = $uptime[$id] ?? null; $isUp = $u && (int) $u['up'] === 1; $up30 = $uptime30[$id] ?? null; ?>
      <a class="card site-card" href="<?= app_url('sites/' . $id) ?>">
        <div class="site-head">
          <div>
            <h2 style="margin:0"><?= e($site['name']) ?></h2>
            <div class="dom"><?= e($site['domain']) ?></div>
          </div>
          <span class="up-pill <?= $u ? ($isUp ? 'up' : 'down') : 'unknown' ?>" title="<?= $up30 !== null ? e(number_format($up30, 2)) . '% uptime (30d)' : 'No checks yet' ?>">
            <span class="up-led"></span><?= $u ? ($isUp ? 'Up' : 'Down') : 'No data' ?>
          </span>
        </div>
        <div class="spark-wrap"><?= $sparkline($spark[$id] ?? []) ?></div>
        <div class="row">
          <div><div class="n"><?= num($s['visitors']) ?></div><div class="l">Visitors</div></div>
          <div><div class="n"><?= num($s['pageviews']) ?></div><div class="l">Page views</div></div>
          <div><div class="n on"><span class="live-dot sm"></span><span class="rt-site" data-site="<?= $id ?>"><?= (int) ($online['sites'][$id] ?? 0) ?></span></div><div class="l">Online</div></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="mt"><a class="btn" href="<?= app_url('sites') ?>">Manage sites</a></div>
<?php endif; ?>

<script>
(function () {
  var box = document.querySelector('[data-realtime]');
  if (!box) return;
  var url = box.getAttribute('data-realtime');
  function poll() {
    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        var t = document.getElementById('rt-total');
        if (t) t.textContent = d.total;
        document.querySelectorAll('.rt-site').forEach(function (el) {
          var id = el.getAttribute('data-site');
          el.textContent = (d.sites && d.sites[id]) ? d.sites[id] : 0;
        });
      }).catch(function () {});
  }
  var timer = setInterval(poll, 20000);
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { clearInterval(timer); } else { poll(); timer = setInterval(poll, 20000); }
  });
})();
</script>

<?php $this->stop(); ?>
