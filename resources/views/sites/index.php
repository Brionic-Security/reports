<?php
/** @var array $sites */
$this->layout('layout', ['title' => 'Sites · Brionic Reports', 'nav' => 'sites']);
$error = \App\Support\Session::getFlash('error');
?>
<?php $this->start('content'); ?>

<h1>Sites</h1>
<p class="sub">Connect a website by adding it here, then paste the snippet into your pages.</p>

<?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>

<div class="grid grid-2">
  <div class="card">
    <h2>Your sites</h2>
    <?php if (!$sites): ?>
      <p class="empty">No sites yet — add your first one.</p>
    <?php else: ?>
      <table class="table">
        <tbody>
        <?php foreach ($sites as $s): ?>
          <tr>
            <td><a href="<?= app_url('sites/' . $s['id']) ?>"><strong><?= e($s['name']) ?></strong></a><br><span class="muted" style="font-size:.8rem"><?= e($s['domain']) ?></span></td>
            <td class="right"><a class="btn btn-sm" href="<?= app_url('sites/' . $s['id'] . '/settings') ?>">Settings</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Add a site</h2>
    <form method="post" action="<?= app_url('sites') ?>">
      <?= csrf_field() ?>
      <div class="field"><label>Name</label><input class="input" name="name" placeholder="Acme Inc." required></div>
      <div class="field"><label>Domain</label><input class="input" name="domain" placeholder="acme.com" required></div>
      <div class="field"><label>Client report email <span class="muted">(optional)</span></label><input class="input" type="email" name="report_email" placeholder="client@acme.com"></div>
      <button class="btn btn-primary" type="submit">Add site</button>
    </form>
  </div>
</div>

<?php $this->stop(); ?>
