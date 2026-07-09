<?php
/** @var string $range @var string $base (full URL) */
$ranges = ['24h' => '24h', '7d' => '7 days', '30d' => '30 days', '90d' => '90 days', 'all' => 'All'];
$sep = str_contains($base, '?') ? '&' : '?';
$from = $from ?? null;
$to = $to ?? null;
$export = $export ?? null;
$custom = ($from || $to);
?>
<div class="filter-bar">
  <div class="filter">
    <?php foreach ($ranges as $key => $label): ?>
      <a href="<?= e($base . $sep . 'range=' . $key) ?>" class="<?= (!$custom && $range === $key) ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <form class="daterange" method="get" action="<?= e($base) ?>">
    <input class="input-date" type="date" name="from" value="<?= e((string) ($from ?? '')) ?>" aria-label="From">
    <span class="sep">&rarr;</span>
    <input class="input-date" type="date" name="to" value="<?= e((string) ($to ?? '')) ?>" aria-label="To">
    <button class="btn btn-sm <?= $custom ? 'active' : '' ?>" type="submit">Apply</button>
  </form>
  <?php if ($export): ?>
    <a class="btn btn-sm export" href="<?= e($export) ?>">&#8681; Export CSV</a>
  <?php endif; ?>
</div>
