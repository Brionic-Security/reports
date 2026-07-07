<?php
/** @var string $range @var string $base (full URL) */
$ranges = ['24h' => '24h', '7d' => '7 days', '30d' => '30 days', '90d' => '90 days', 'all' => 'All'];
$sep = str_contains($base, '?') ? '&' : '?';
?>
<div class="filter">
  <?php foreach ($ranges as $key => $label): ?>
    <a href="<?= e($base . $sep . 'range=' . $key) ?>" class="<?= $range === $key ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
