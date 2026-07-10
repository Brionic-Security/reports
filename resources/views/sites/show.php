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

<div class="grid">
  <div class="card">
    <h2>Connect this website</h2>
    <?php $conn = $connection ?? ['any' => false, 'wordpress' => 0, 'snippet' => 0, 'last' => null]; ?>
    <?php if ($conn['any']): ?>
      <div class="conn-status ok">
        <span class="up-led"></span>
        <span><strong>Connected</strong> &mdash; receiving data<?= $conn['last'] ? ', last hit ' . e(time_ago($conn['last'])) : '' ?>.
        <?php
          $via = [];
          if ($conn['wordpress'] > 0) { $via[] = 'WordPress plugin'; }
          if ($conn['snippet'] > 0) { $via[] = 'code snippet'; }
        ?>
        Detected via <strong><?= e(implode(' + ', $via)) ?></strong>.</span>
      </div>
    <?php else: ?>
      <div class="conn-status wait">
        <span class="up-led"></span>
        <span>Not connected yet &mdash; add one of the methods below, then reload your site. Data appears here within a minute.</span>
      </div>
    <?php endif; ?>
    <?php if (!empty($conn['plugin_check'])): ?>
      <p class="muted" style="font-size:.8rem;margin:-6px 0 12px">&#10003; WordPress plugin reached us (server test passed <?= e(time_ago($conn['plugin_check'])) ?>).<?= !$conn['any'] ? ' The plugin&rsquo;s key + connectivity are good — if no visits show, the tracker script isn&rsquo;t rendering on your pages (theme/optimiser).' : '' ?></p>
    <?php endif; ?>
    <form method="post" action="<?= app_url('sites/' . $site['id'] . '/validate') ?>" style="margin:0 0 14px">
      <?= csrf_field() ?>
      <button class="btn btn-sm" type="submit">&#8635; Validate connection</button>
      <span class="muted" style="font-size:.8rem;margin-left:8px">Checks for data and looks for the tracker on your homepage.</span>
    </form>
    <p class="muted">Pick whichever is easiest — tracking is plug-and-play, privacy-first, and never uses cookies.</p>

    <div class="connect-method <?= $conn['wordpress'] > 0 ? 'is-connected' : '' ?>">
      <h3><span class="cm-num">1</span> WordPress <?php if ($conn['wordpress'] > 0): ?><span class="cm-badge">&#10003; Connected</span><?php else: ?><span class="cm-tag">easiest</span><?php endif; ?></h3>
      <p class="muted">Your site key is baked into the download. In WordPress go to <strong>Plugins &rarr; Add New &rarr; Upload Plugin</strong>, upload the file, then click <strong>Activate</strong>.</p>
      <a class="btn btn-primary btn-sm" href="<?= app_url('sites/' . $site['id'] . '/plugin.zip') ?>">&#8681; Download WordPress plugin</a>
    </div>

    <div class="connect-method <?= $conn['snippet'] > 0 ? 'is-connected' : '' ?>">
      <h3><span class="cm-num">2</span> Any website (HTML) <?php if ($conn['snippet'] > 0): ?><span class="cm-badge">&#10003; Connected</span><?php endif; ?></h3>
      <p class="muted">Paste this once, just before the closing <code>&lt;/head&gt;</code> tag, on every page you want to track.</p>
      <code class="code"><?= e($snippet) ?></code>
    </div>

    <div class="connect-method">
      <h3><span class="cm-num">3</span> Google Tag Manager, Shopify, Wix, Squarespace&hellip;</h3>
      <p class="muted">Add a <strong>Custom HTML</strong> tag (GTM) or paste the snippet above into your theme&rsquo;s header / custom-code area, set to load on all pages.</p>
    </div>

    <p class="muted mt" style="font-size:.82rem">Site key: <code><?= e($site['public_id']) ?></code> &middot; data flows in within a minute of your first visit.</p>
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
