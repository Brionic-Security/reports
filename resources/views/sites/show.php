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

<?php
  // Single source of truth for the paste-in code block: the tracker plus any
  // search-engine verification metas. Auto-updates as Google/Bing get connected.
  $sx  = $search ?? [];
  $cgx = $sx['conn_google'] ?? null;
  $cbx = $sx['conn_bing'] ?? null;
  $connectLines = [$snippet];
  if ($cgx && (($cgx['verification'] ?? '') === 'meta') && ($cgx['verify_token'] ?? '') !== '') {
      $connectLines[] = $cgx['verify_token'];
  }
  if ($cbx && (($cbx['verify_token'] ?? '') !== '')) {
      $connectLines[] = '<meta name="msvalidate.01" content="' . $cbx['verify_token'] . '" />';
  }
  $pasteBlock    = implode("\n", $connectLines);
  $hasSearchMeta = count($connectLines) > 1;
?>

<div class="grid">
  <div class="card" id="connect">
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
      <p class="muted">Paste this once, just before the closing <code>&lt;/head&gt;</code> tag, on every page you want to track.<?php if ($hasSearchMeta): ?> This block also includes your <strong>Google/Bing verification</strong> &mdash; after pasting, click <strong>Verify</strong> under &ldquo;Search engines &amp; indexing&rdquo; below.<?php endif; ?></p>
      <code class="code"<?= $hasSearchMeta ? ' style="white-space:pre-wrap;display:block"' : '' ?>><?= e($pasteBlock) ?></code>
      <?php if (!empty($sx['indexnow']) && (string) ($sx['indexnow_key'] ?? '') !== ''): ?>
        <p class="muted" style="font-size:.82rem;margin:12px 0 0">
          <strong>Instant indexing (IndexNow):</strong> also add one small text file so Bing/Yandex accept instant updates &mdash; create
          <code>https://<?= e(\App\Services\SearchService::domain($site)) ?>/<?= e((string) $sx['indexnow_key']) ?>.txt</code>
          containing exactly <code><?= e((string) $sx['indexnow_key']) ?></code>.
          <em>WordPress sites get this automatically from the Brionic plugin &mdash; nothing to add.</em>
        </p>
      <?php endif; ?>
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

<div class="card mt" id="search">
  <h2>Search engines &amp; indexing</h2>
  <?php
    $s = $search ?? [];
    $cg = $s['conn_google'] ?? null;
    $cb = $s['conn_bing'] ?? null;
    $tot = $s['totals'] ?? ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0];
  ?>
  <?php if (empty($s['google_configured']) && empty($s['bing_configured'])): ?>
    <p class="muted" style="margin-top:0">Not set up yet. Connect Google Search Console and/or Bing on the <a href="<?= app_url('integrations') ?>">Integrations</a> page first.</p>
  <?php else: ?>

  <div class="grid" style="margin-top:4px">
    <!-- Google -->
    <div class="connect-method <?= ($cg && $cg['status'] === 'verified') ? 'is-connected' : '' ?>">
      <h3><span class="cm-num">G</span> Google Search Console
        <?php if ($cg && $cg['status'] === 'verified'): ?><span class="cm-badge">&#10003; Verified</span>
        <?php elseif ($cg): ?><span class="cm-tag">pending</span><?php endif; ?>
      </h3>
      <?php if (empty($s['google_connected'])): ?>
        <p class="muted">Connect a Google account on <a href="<?= app_url('integrations') ?>">Integrations</a> to enable this.</p>
      <?php elseif (!$cg): ?>
        <p class="muted">Add this site to Search Console and verify ownership automatically.</p>
        <form method="post" action="<?= app_url('sites/' . $site['id'] . '/search/connect') ?>">
          <?= csrf_field() ?><input type="hidden" name="provider" value="google">
          <button class="btn btn-primary btn-sm" type="submit">Connect Google</button>
        </form>
      <?php else: ?>
        <p class="muted" style="font-size:.82rem">Property <code><?= e($cg['property']) ?></code> &middot; <?= e($cg['detail']) ?></p>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <?php if ($cg['status'] !== 'verified'): ?>
            <form method="post" action="<?= app_url('sites/' . $site['id'] . '/search/verify') ?>" style="margin:0">
              <?= csrf_field() ?><input type="hidden" name="provider" value="google">
              <button class="btn btn-primary btn-sm" type="submit">&#8635; Verify</button>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= app_url('sites/' . $site['id'] . '/search/disconnect') ?>" style="margin:0">
            <?= csrf_field() ?><input type="hidden" name="provider" value="google">
            <button class="btn btn-sm" type="submit">Disconnect</button>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <!-- Bing -->
    <div class="connect-method <?= ($cb && $cb['status'] === 'verified') ? 'is-connected' : '' ?>">
      <h3><span class="cm-num">B</span> Bing Webmaster
        <?php if ($cb && $cb['status'] === 'verified'): ?><span class="cm-badge">&#10003; Connected</span>
        <?php elseif ($cb): ?><span class="cm-tag">pending</span><?php endif; ?>
      </h3>
      <?php if (empty($s['bing_configured'])): ?>
        <p class="muted">Add a Bing API key on <a href="<?= app_url('integrations') ?>">Integrations</a> to enable this.</p>
      <?php elseif (!$cb): ?>
        <p class="muted">Add this site to Bing and enable IndexNow-based indexing.</p>
        <form method="post" action="<?= app_url('sites/' . $site['id'] . '/search/connect') ?>">
          <?= csrf_field() ?><input type="hidden" name="provider" value="bing">
          <button class="btn btn-primary btn-sm" type="submit">Connect Bing</button>
        </form>
      <?php else: ?>
        <p class="muted" style="font-size:.82rem">Site <code><?= e($cb['property']) ?></code> &middot; <?= e($cb['detail']) ?></p>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <?php if ($cb['status'] !== 'verified'): ?>
            <form method="post" action="<?= app_url('sites/' . $site['id'] . '/search/verify') ?>" style="margin:0">
              <?= csrf_field() ?><input type="hidden" name="provider" value="bing">
              <button class="btn btn-primary btn-sm" type="submit">&#8635; Verify</button>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= app_url('sites/' . $site['id'] . '/search/disconnect') ?>" style="margin:0">
            <?= csrf_field() ?><input type="hidden" name="provider" value="bing">
            <button class="btn btn-sm" type="submit">Disconnect</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($hasSearchMeta): ?>
    <hr style="border:none;border-top:1px solid var(--line);margin:18px 0">
    <p class="muted" style="margin:0;font-size:.85rem"><strong>&#10003; Verification tag ready.</strong> It&rsquo;s already included in the paste block under <a href="#connect">Connect this website</a> above &mdash; <strong>WordPress</strong> sites get it automatically via the Brionic plugin; any other site pastes that one block once, then clicks <strong>Verify</strong>.</p>
  <?php endif; ?>

  <?php if ($cg || $cb): ?>
  <hr style="border:none;border-top:1px solid var(--line);margin:18px 0">
  <div class="grid">
    <div>
      <h3 style="margin:0 0 8px">Request indexing</h3>
      <p class="muted" style="margin-top:0;font-size:.85rem">Google always gets your full sitemap. The pages below (pre-filled from your sitemap) are pushed to Bing + IndexNow &mdash; edit or add any, or clear the box to submit just the homepage. One URL or path per line.</p>
      <form method="post" action="<?= app_url('sites/' . $site['id'] . '/search/index') ?>">
        <?= csrf_field() ?>
        <textarea class="input" name="urls" rows="6" placeholder="/&#10;/blog/new-post&#10;https://<?= e($site['domain']) ?>/pricing"><?= e($s['default_urls'] ?? '') ?></textarea>
        <button class="btn btn-primary btn-sm mt" type="submit">&#9889; Request indexing now</button>
      </form>
      <?php if (!empty($index_result)): ?>
        <div class="flash" style="margin:10px 0 0;font-size:.82rem;white-space:pre-wrap"><?= e($index_result) ?></div>
      <?php endif; ?>
      <?php
        $il = $s['index_last'] ?? [];
        $lastAny = '';
        foreach ($il as $ts) { if ((string) $ts > $lastAny) { $lastAny = (string) $ts; } }
        $gp = $s['google_processed'] ?? null;
      ?>
      <?php if ($lastAny !== ''): ?>
        <p class="muted" style="font-size:.8rem;margin:10px 0 4px">Last requested <strong><?= e(time_ago($lastAny)) ?></strong>.</p>
        <ul class="muted" style="font-size:.8rem;margin:0;padding-left:16px;line-height:1.7">
          <?php if (!empty($il['google'])): ?>
            <li>Google &mdash; submitted <?= e(time_ago($il['google'])) ?><?php
              if ($gp && !empty($gp['downloaded'])): ?> &middot; fetched by Google <?= e(time_ago($gp['downloaded'])) ?><?php if ((int) ($gp['errors'] ?? 0) > 0): ?> <span style="color:var(--danger)">(<?= (int) $gp['errors'] ?> errors)</span><?php endif;
              elseif ($gp && !empty($gp['pending'])): ?> &middot; queued for crawl<?php endif; ?></li>
          <?php endif; ?>
          <?php if (!empty($il['bing'])): ?><li>Bing &mdash; submitted <?= e(time_ago($il['bing'])) ?></li><?php endif; ?>
          <?php if (!empty($il['indexnow'])): ?><li>IndexNow &mdash; pinged <?= e(time_ago($il['indexnow'])) ?></li><?php endif; ?>
        </ul>
      <?php else: ?>
        <p class="muted" style="font-size:.8rem;margin:10px 0 0">Not requested yet.</p>
      <?php endif; ?>
    </div>
    <div>
      <h3 style="margin:0 0 8px">Search performance <span class="muted" style="font-weight:400;font-size:.8rem">(last 28 days)</span></h3>
      <?php if (!empty($s['has_metrics'])): ?>
        <div class="stat-row" style="display:flex;gap:18px;flex-wrap:wrap">
          <div><div class="stat-num"><?= number_format((int) $tot['clicks']) ?></div><div class="muted" style="font-size:.78rem">Clicks</div></div>
          <div><div class="stat-num"><?= number_format((int) $tot['impressions']) ?></div><div class="muted" style="font-size:.78rem">Impressions</div></div>
          <div><div class="stat-num"><?= number_format((float) $tot['ctr'] * 100, 1) ?>%</div><div class="muted" style="font-size:.78rem">CTR</div></div>
          <div><div class="stat-num"><?= number_format((float) $tot['position'], 1) ?></div><div class="muted" style="font-size:.78rem">Avg position</div></div>
        </div>
        <?php if (!empty($s['top_queries'])): ?>
          <table class="table mt"><thead><tr><th>Top query</th><th>Clicks</th><th>Impr.</th></tr></thead><tbody>
            <?php foreach ($s['top_queries'] as $q): ?>
              <tr><td><?= e($q['label']) ?></td><td><?= number_format((int) $q['clicks']) ?></td><td><?= number_format((int) $q['impressions']) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted" style="font-size:.85rem">No data synced yet. Verify a property, then click “Sync search data”.</p>
      <?php endif; ?>
      <form method="post" action="<?= app_url('sites/' . $site['id'] . '/search/sync') ?>" style="margin-top:8px">
        <?= csrf_field() ?><button class="btn btn-sm" type="submit">&#8635; Sync search data</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($s['requests'])): ?>
    <h3 style="margin:18px 0 8px">Recent indexing requests</h3>
    <table class="table"><thead><tr><th>Engine</th><th>Type</th><th>Target</th><th>Status</th><th>When</th></tr></thead><tbody>
      <?php foreach ($s['requests'] as $rq): ?>
        <tr>
          <td><?= e(ucfirst($rq['provider'])) ?></td>
          <td><?= e($rq['kind']) ?></td>
          <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($rq['target']) ?></td>
          <td><span class="badge <?= $rq['status'] === 'ok' ? 'human' : ($rq['status'] === 'error' ? 'bot' : '') ?>"><?= e($rq['status']) ?></span></td>
          <td><?= e(time_ago($rq['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>

  <?php endif; ?>
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
