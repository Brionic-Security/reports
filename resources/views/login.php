<?php /** @var ?string $error */ ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Sign in · Brionic Reports</title>
  <link rel="stylesheet" href="<?= asset('app.css') ?>">
</head>
<body>
  <div class="login-wrap">
    <div class="login">
      <div class="brand" style="justify-content:center;margin-bottom:20px"><span class="dot"></span> Brionic Reports</div>
      <div class="card">
        <h2>Operator sign in</h2>
        <?php if (!empty($error)): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= app_url('login') ?>">
          <?= csrf_field() ?>
          <div class="field">
            <label>Email</label>
            <input class="input" type="email" name="email" autocomplete="username" required autofocus>
          </div>
          <div class="field">
            <label>Password</label>
            <input class="input" type="password" name="password" autocomplete="current-password" required>
          </div>
          <button class="btn btn-primary" type="submit" style="width:100%">Sign in</button>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
