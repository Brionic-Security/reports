<?php
/** @var array $site @var string $snippet @var ?string $ok @var ?string $error @var array $runs */
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
<?php if (!empty($error)): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>

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
      <div class="field"><label>Weekly report recipients <span class="muted">(one email per line)</span></label><textarea class="input" name="report_email" rows="3" placeholder="client@acme.com&#10;you@agency.com"><?= e($site['report_email'] ?? '') ?></textarea></div>
      <div class="field">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="monitor_enabled" value="1" <?= (int) ($site['monitor_enabled'] ?? 1) === 1 ? 'checked' : '' ?>>
          Monitor uptime for this site
        </label>
      </div>
      <div class="field"><label>Monitor URL <span class="muted">(optional — defaults to https://<?= e($site['domain']) ?>)</span></label><input class="input" type="url" name="monitor_url" value="<?= e($site['monitor_url'] ?? '') ?>" placeholder="https://<?= e($site['domain']) ?>/health"></div>
      <button class="btn btn-primary" type="submit">Save</button>
    </form>
    <hr style="border:none;border-top:1px solid var(--line);margin:18px 0">
    <form method="post" action="<?= app_url('sites/' . $site['id'] . '/delete') ?>" onsubmit="return confirm('Delete this site and all its analytics? This cannot be undone.');">
      <?= csrf_field() ?>
      <button class="btn btn-danger btn-sm" type="submit">Delete site</button>
    </form>
  </div>
</div>

<div class="card mt">
  <h2>Weekly client report</h2>
  <p class="muted" style="margin-top:0">
    <?php $recips = \App\Services\ReportService::recipients((string) ($site['report_email'] ?? '')); ?>
    A branded traffic summary emailed each week
    <?php if ($recips): ?>to <strong><?= e(implode(', ', $recips)) ?></strong><?php else: ?><span class="badge bot">no recipients set</span><?php endif; ?>.
    Runs automatically when the cron is configured.
  </p>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <a class="btn btn-sm" href="<?= app_url('sites/' . $site['id'] . '/report') ?>" target="_blank">Preview report</a>
    <form method="post" action="<?= app_url('sites/' . $site['id'] . '/report/test') ?>" style="margin:0">
      <?= csrf_field() ?><button class="btn btn-sm" type="submit">Send test to me</button>
    </form>
    <?php if (!empty($site['report_email'])): ?>
      <form method="post" action="<?= app_url('sites/' . $site['id'] . '/report/send') ?>" style="margin:0" onsubmit="return confirm('Send this week\'s report to the client now?');">
        <?= csrf_field() ?><button class="btn btn-primary btn-sm" type="submit">Send to client now</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (!empty($runs)): ?>
    <table class="table mt">
      <thead><tr><th>Period</th><th>Sent to</th><th>Status</th><th>When</th></tr></thead>
      <tbody>
        <?php foreach ($runs as $run): ?>
          <tr>
            <td><?= e($run['period_start']) ?> → <?= e($run['period_end']) ?></td>
            <td><?= e($run['sent_to']) ?></td>
            <td><span class="badge <?= $run['status'] === 'sent' ? 'human' : 'bot' ?>"><?= e($run['status']) ?></span></td>
            <td><?= e(time_ago($run['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php $this->stop(); ?>
