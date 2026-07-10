<?php /** @var string $title */ ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title ?? 'Brionic Reports') ?></title>
  <link rel="icon" type="image/png" href="<?= asset('img/brionic-icon.png') ?>">
  <link rel="stylesheet" href="<?= asset('app.css') ?>">
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <a class="brand" href="<?= app_url('dashboard') ?>"><img class="brand-logo" src="<?= asset('img/brionic-logo.webp') ?>" alt="Brionic Security"> <span class="brand-sub">Reports</span></a>
      <nav class="nav">
        <a href="<?= app_url('dashboard') ?>" class="<?= ($nav ?? '') === 'dashboard' ? 'active' : '' ?>">Overview</a>
        <a href="<?= app_url('sites') ?>" class="<?= ($nav ?? '') === 'sites' ? 'active' : '' ?>">Sites</a>
        <form method="post" action="<?= app_url('logout') ?>" style="margin:0">
          <?= csrf_field() ?>
          <button class="btn btn-sm" type="submit">Sign out</button>
        </form>
      </nav>
    </div>
    <?= $this->section('content') ?>
    <p class="muted mt" style="padding:30px 0;font-size:.8rem">Brionic Reports · privacy-first analytics · <?= date('Y') ?></p>
  </div>
</body>
</html>
