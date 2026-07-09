<?php
/** @var array $site @var array $stats @var string $range */
$this->layout('layout', ['title' => $site['name'] . ' · Brionic Reports', 'nav' => 'sites']);

/** Render a horizontal bar list from rows of [label, value]. */
$bars = function (array $rows, string $labelKey, string $valKey) {
    if (!$rows) { echo '<p class="empty">No data yet.</p>'; return; }
    $max = max(array_map(fn ($r) => (int) $r[$valKey], $rows)) ?: 1;
    echo '<ul class="bars">';
    foreach ($rows as $r) {
        $pct = (int) round(((int) $r[$valKey] / $max) * 100);
        $label = (string) ($r[$labelKey] ?? '');
        if ($label === '') { $label = '—'; }
        echo '<li><span class="bar" style="width:' . $pct . '%"></span>'
           . '<span class="lbl">' . e($label) . '</span>'
           . '<span class="val">' . num((int) $r[$valKey]) . '</span></li>';
    }
    echo '</ul>';
};
$maxDay = 1;
foreach ($stats['by_day'] as $d) { $maxDay = max($maxDay, $d['humans'] + $d['bots']); }

$prev = $stats['prev'] ?? null;
/** % change badge vs the previous equal-length period. */
$delta = function (int $cur, string $key) use ($prev): string {
    if (!$prev || !isset($prev[$key])) { return ''; }
    $p = (int) $prev[$key];
    if ($p === 0) { return $cur > 0 ? '<span class="delta up">new</span>' : ''; }
    $pc = (int) round(($cur - $p) / $p * 100);
    if ($pc === 0) { return '<span class="delta flat">±0%</span>'; }
    return '<span class="delta ' . ($pc > 0 ? 'up' : 'down') . '">' . ($pc > 0 ? '▲' : '▼') . ' ' . abs($pc) . '%</span>';
};
?>
<?php $this->start('content'); ?>

<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">
  <div>
    <h1><?= e($site['name']) ?></h1>
    <p class="sub"><?= e($site['domain']) ?></p>
  </div>
  <a class="btn btn-sm" href="<?= app_url('sites/' . $site['id'] . '/settings') ?>">Settings &amp; snippet</a>
</div>

<?php
$exportUrl = app_url('sites/' . $site['id'] . '/export.csv') . '?' . http_build_query(array_filter([
    'range' => $range, 'from' => $from ?? null, 'to' => $to ?? null,
]));
$this->include('partials/filter', [
    'range'  => $range,
    'base'   => app_url('sites/' . $site['id']),
    'from'   => $from ?? null,
    'to'     => $to ?? null,
    'export' => $exportUrl,
]);
?>

<div class="grid grid-4" style="margin-bottom:22px">
  <div class="stat"><div class="n"><?= num($stats['visitors']) ?></div><div class="l">Unique visitors <?= $delta($stats['visitors'], 'visitors') ?></div></div>
  <div class="stat"><div class="n"><?= num($stats['pageviews']) ?></div><div class="l">Page views <?= $delta($stats['pageviews'], 'pageviews') ?></div></div>
  <div class="stat"><div class="n"><?= num($stats['bots']) ?></div><div class="l">Bot hits</div></div>
  <div class="stat"><div class="n"><?= num($stats['total']) ?></div><div class="l">Total events</div></div>
</div>

<?php
$last = $monitorLast ?? null;
$isUp = $last && (int) $last['up'] === 1;
$monEnabled = (int) ($site['monitor_enabled'] ?? 1) === 1;
?>
<div class="grid grid-2" style="margin-bottom:16px">
  <div class="card live-card" data-realtime="<?= e(app_url('sites/' . $site['id'] . '/realtime.json')) ?>">
    <h2>Live now <span class="live-dot"></span></h2>
    <div class="live-now"><span id="rt-online"><?= (int) $realtime['online'] ?></span><span class="live-lbl">online</span></div>
    <div class="muted" style="font-size:.78rem;margin-bottom:10px">Active in the last <?= (int) $realtime['minutes'] ?> minutes · live feed of the last 30&nbsp;min</div>
    <ul class="live-feed" id="rt-feed">
      <?php foreach ($realtime['feed'] as $r): $b = (int) $r['is_bot'] === 1; ?>
        <li>
          <span class="badge <?= $b ? 'bot' : 'human' ?>"><?= e($b ? ($r['bot_name'] ?: 'bot') : ($r['type'] === 'event' ? ($r['name'] ?: 'event') : 'view')) ?></span>
          <span class="fp"><?= e($r['path'] ?: '/') ?></span>
          <span class="fw"><?= e(trim((string) ($r['city'] ?: '') . (($r['city'] && $r['country']) ? ', ' : '') . (string) ($r['country'] ?: '')) ?: '—') ?></span>
          <span class="fa"><?= e(time_ago($r['created_at'])) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="empty" id="rt-empty" <?= $realtime['feed'] ? 'style="display:none"' : '' ?>>No activity in the last 30 minutes.</p>
  </div>

  <div class="card uptime-card">
    <h2>Uptime</h2>
    <div class="up-status <?= $last ? ($isUp ? 'up' : 'down') : 'unknown' ?>">
      <span class="up-badge"><span class="up-led"></span><?= $last ? ($isUp ? 'Online' : 'Down') : 'Not checked yet' ?></span>
      <?php if ($last): ?><span class="muted" style="font-size:.78rem">checked <?= e(time_ago($last['checked_at'])) ?></span><?php endif; ?>
    </div>
    <div class="grid grid-2" style="margin-top:12px">
      <div class="stat sm"><div class="n"><?= $uptime30 !== null ? e(number_format($uptime30, 2)) . '%' : '—' ?></div><div class="l">30-day uptime</div></div>
      <div class="stat sm"><div class="n"><?= $avgMs !== null ? num($avgMs) . '<span style="font-size:.5em"> ms</span>' : '—' ?></div><div class="l">Avg response</div></div>
    </div>
    <?php if (!empty($monitorRecent)): ?>
      <div class="up-bars" aria-label="Recent checks">
        <?php foreach (array_reverse($monitorRecent) as $c): ?>
          <span class="up-bar <?= (int) $c['up'] === 1 ? 'ok' : 'bad' ?>" title="<?= e($c['checked_at'] . ' · HTTP ' . $c['status_code'] . ' · ' . $c['response_ms'] . 'ms') ?>"></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if (!$monEnabled): ?>
      <p class="muted" style="font-size:.78rem;margin-top:10px">Monitoring is off — enable it in Settings.</p>
    <?php elseif (!$last): ?>
      <p class="muted" style="font-size:.78rem;margin-top:10px">The monitor runs on a schedule; results appear here after the first check.</p>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="margin-bottom:16px">
  <h2>Traffic by day <span class="muted" style="font-size:.75rem;font-weight:400"><span class="badge human">humans</span> <span class="badge bot">bots</span></span></h2>
  <?php if ($stats['by_day']): ?>
    <div class="chart">
      <?php foreach ($stats['by_day'] as $d): $th = (int) round($d['humans'] / $maxDay * 110); $tb = (int) round($d['bots'] / $maxDay * 110); ?>
        <div class="col" title="<?= e($d['date']) ?>: <?= $d['humans'] ?> humans, <?= $d['bots'] ?> bots">
          <div class="b" style="height:<?= $tb ?>px"></div>
          <div class="h" style="height:<?= $th ?>px"></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?><p class="empty">No visits in this period yet.</p><?php endif; ?>
</div>

<div class="card" style="margin-bottom:16px">
  <h2>Visitor map <span class="muted" style="font-size:.75rem;font-weight:400">by city</span></h2>
  <?php if (!empty($stats['map'])): $mx = max(array_map(fn ($p) => (int) $p['n'], $stats['map'])) ?: 1; ?>
    <div class="geo-map">
      <svg viewBox="0 0 1000 500" preserveAspectRatio="xMidYMid meet">
        <image href="<?= asset('img/worldmap.png') ?>" x="0" y="0" width="1000" height="500" preserveAspectRatio="none"></image>
        <?php foreach ($stats['map'] as $pt):
          $x = ((float) $pt['rlon'] + 180) / 360 * 1000;
          $y = (90 - (float) $pt['rlat']) / 180 * 500;
          $rr = 3 + (int) round(((int) $pt['n'] / $mx) * 12); ?>
          <circle cx="<?= round($x, 1) ?>" cy="<?= round($y, 1) ?>" r="<?= $rr ?>" fill="#d92b32" fill-opacity="0.5" stroke="#fff" stroke-width="1" stroke-opacity="0.7"><title><?= e(($pt['city'] ?: $pt['country'] ?: 'Unknown') . ' — ' . $pt['n']) ?></title></circle>
        <?php endforeach; ?>
      </svg>
    </div>
  <?php else: ?><p class="empty">No location data yet — check back once visitors arrive.</p><?php endif; ?>
</div>

<div class="grid grid-2">
  <div class="card"><h2>Top pages</h2><?php $bars($stats['top_pages'], 'path', 'n'); ?></div>
  <div class="card"><h2>Top referrers</h2><?php $bars($stats['referrers'], 'referer_host', 'n'); ?></div>
  <div class="card"><h2>Countries</h2><?php $bars($stats['countries'], 'country', 'n'); ?></div>
  <div class="card"><h2>Top cities</h2><?php $bars($stats['cities'], 'city', 'n'); ?></div>
  <div class="card"><h2>Devices</h2><?php $bars($stats['devices'], 'label', 'n'); ?></div>
  <div class="card"><h2>Browsers</h2><?php $bars($stats['browsers'], 'label', 'n'); ?></div>
  <div class="card"><h2>Operating systems</h2><?php $bars($stats['os'], 'label', 'n'); ?></div>
  <div class="card"><h2>Custom events</h2><?php $bars($stats['events'], 'name', 'n'); ?></div>
  <div class="card"><h2>Bots &amp; crawlers</h2><?php $bars($stats['bot_names'], 'bot_name', 'n'); ?></div>
</div>

<div class="card mt">
  <h2>Recent activity</h2>
  <?php if ($stats['recent']): ?>
    <div style="overflow-x:auto">
    <table class="table">
      <thead><tr><th>When</th><th>Type</th><th>Page</th><th>Referrer</th><th>Device</th><th>Country</th></tr></thead>
      <tbody>
        <?php foreach ($stats['recent'] as $r): ?>
          <tr>
            <td><?= e(time_ago($r['created_at'])) ?></td>
            <td><?php if ((int) $r['is_bot'] === 1): ?><span class="badge bot"><?= e($r['bot_name'] ?: 'bot') ?></span><?php elseif ($r['type'] === 'event'): ?><span class="badge human"><?= e($r['name'] ?: 'event') ?></span><?php else: ?><span class="badge human">view</span><?php endif; ?></td>
            <td><?= e($r['path']) ?></td>
            <td><?= e($r['referer_host'] ?: '—') ?></td>
            <td><?= e(($r['browser'] ?: '—') . ' · ' . ($r['os'] ?: '')) ?></td>
            <td><?= e($r['country'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php else: ?><p class="empty">Nothing recorded yet.</p><?php endif; ?>
</div>

<script>
(function () {
  var card = document.querySelector('.live-card[data-realtime]');
  if (!card) return;
  var url = card.getAttribute('data-realtime');
  var onlineEl = document.getElementById('rt-online');
  var feedEl = document.getElementById('rt-feed');
  var emptyEl = document.getElementById('rt-empty');

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  function render(data) {
    if (onlineEl) onlineEl.textContent = data.online;
    if (!feedEl) return;
    var feed = data.feed || [];
    if (emptyEl) emptyEl.style.display = feed.length ? 'none' : '';
    feedEl.innerHTML = feed.map(function (r) {
      var cls = r.kind === 'bot' ? 'bot' : 'human';
      return '<li><span class="badge ' + cls + '">' + esc(r.label) + '</span>'
        + '<span class="fp">' + esc(r.path || '/') + '</span>'
        + '<span class="fw">' + esc(r.where || '—') + '</span>'
        + '<span class="fa">' + esc(r.ago) + '</span></li>';
    }).join('');
  }

  function poll() {
    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d) render(d); })
      .catch(function () {});
  }

  var timer = setInterval(poll, 15000);
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { clearInterval(timer); }
    else { poll(); timer = setInterval(poll, 15000); }
  });
})();
</script>

<?php $this->stop(); ?>
