<?php
/** @var array $site @var string $snippet @var ?string $ok */
$this->layout('layout', ['title' => $site['name'] . ' settings · Brionic Reports', 'nav' => 'sites']);
?>
<?php $this->start('content'); ?>

<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">
  <div>
    <h1><?= e($site['name']) ?> · settings</h1>
    <p class="sub"><?= e($site['domain']) ?></p>
  </div>
  <a class="btn btn-sm" href="<?= app_url('sites/' . $site['id']) ?>">View analytics</a>
</div>

<?php if (!empty($ok)): ?><div class="flash"><?= e($ok) ?></div><?php endif; ?>

<div class="grid grid-2">
  <div class="card">
    <h2>Install snippet</h2>
    <p class="muted" style="margin-top:0">Paste this once into the <code>&lt;head&gt;</code> of every page you want to track. Plug-and-play — no other setup.</p>
    <code class="code"><?= e($snippet) ?></code>
    <p class="muted mt" style="font-size:.82rem">Site key: <code><?= e($site['public_id']) ?></code></p>
  </div>

  <div class="card">
    <h2>Details</h2>
    <form method="post" action="<?= app_url('sites/' . $site['id']) ?>">
      <?= csrf_field() ?>
      <div class="field"><label>Name</label><input class="input" name="name" value="<?= e($site['name']) ?>" required></div>
      <div class="field"><label>Domain</label><input class="input" name="domain" value="<?= e($site['domain']) ?>" required></div>
      <div class="field"><label>Client report email</label><input class="input" type="email" name="report_email" value="<?= e($site['report_email'] ?? '') ?>" placeholder="client@acme.com"></div>
      <button class="btn btn-primary" type="submit">Save</button>
    </form>
    <hr style="border:none;border-top:1px solid var(--line);margin:18px 0">
    <form method="post" action="<?= app_url('sites/' . $site['id'] . '/delete') ?>" onsubmit="return confirm('Delete this site and all its analytics? This cannot be undone.');">
      <?= csrf_field() ?>
      <button class="btn btn-danger btn-sm" type="submit">Delete site</button>
    </form>
  </div>
</div>

<?php $this->stop(); ?>
