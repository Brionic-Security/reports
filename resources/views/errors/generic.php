<?php
/** @var string $status @var string $message */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($status ?? '404') ?> · Brionic Reports</title>
  <link rel="stylesheet" href="<?= asset('app.css') ?>">
</head>
<body>
  <div class="login-wrap">
    <div class="login" style="text-align:center">
      <div class="brand" style="justify-content:center;margin-bottom:16px"><span class="dot"></span> Brionic Reports</div>
      <h1 style="font-size:3rem;margin:0"><?= e($status ?? '404') ?></h1>
      <p class="muted"><?= e($message ?? 'Page not found.') ?></p>
      <a class="btn mt" href="<?= app_url('dashboard') ?>">Back to dashboard</a>
    </div>
  </div>
</body>
</html>
