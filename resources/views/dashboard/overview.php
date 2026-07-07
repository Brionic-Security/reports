<?php
/** @var array $sites @var array $stats @var string $range */
$this->layout('layout', ['title' => 'Overview · Brionic Reports', 'nav' => 'dashboard']);
$totalViews = 0; $totalVisitors = 0;
foreach ($stats as $s) { $totalViews += $s['pageviews']; $totalVisitors += $s['visitors']; }
?>
<?php $this->start('content'); ?>

<h1>Overview</h1>
<p class="sub">All your connected sites in one place.</p>

<?php $this->include('partials/filter', ['range' => $range, 'base' => app_url('dashboard')]); ?>

<div class="grid grid-3" style="margin-bottom:24px">
  <div class="stat"><div class="n"><?= num($totalVisitors) ?></div><div class="l">Unique visitors</div></div>
  <div class="stat"><div class="n"><?= num($totalViews) ?></div><div class="l">Page views</div></div>
  <div class="stat"><div class="n"><?= count($sites) ?></div><div class="l">Connected sites</div></div>
</div>

<?php if (!$sites): ?>
  <div class="card">
    <h2>No sites yet</h2>
    <p class="muted">Add your first website to start collecting analytics.</p>
    <a class="btn btn-primary" href="<?= app_url('sites') ?>">Add a site</a>
  </div>
<?php else: ?>
  <div class="grid grid-2">
    <?php foreach ($sites as $site): $s = $stats[(int) $site['id']] ?? ['pageviews' => 0, 'visitors' => 0]; ?>
      <a class="card site-card" href="<?= app_url('sites/' . $site['id']) ?>">
        <h2 style="margin:0"><?= e($site['name']) ?></h2>
        <div class="dom"><?= e($site['domain']) ?></div>
        <div class="row">
          <div><div class="n"><?= num($s['visitors']) ?></div><div class="l">Visitors</div></div>
          <div><div class="n"><?= num($s['pageviews']) ?></div><div class="l">Page views</div></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="mt"><a class="btn" href="<?= app_url('sites') ?>">Manage sites</a></div>
<?php endif; ?>

<?php $this->stop(); ?>
