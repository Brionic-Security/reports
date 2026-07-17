<?php
/** @var array $google @var array $bing @var array $indexnow @var array $cloudflare @var ?string $ok @var ?string $error */
$this->layout('layout', ['title' => 'Integrations · Brionic Reports', 'nav' => 'integrations']);
?>
<?php $this->start('content'); ?>

<h1>Integrations</h1>
<p class="sub">Connect Google Search Console and Bing once, then wire each site up from its Settings page.</p>

<?php if (!empty($ok)): ?><div class="flash"><?= e($ok) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>

<div class="grid">
  <div class="card">
    <h2>Google Search Console</h2>
    <?php if (!$google['configured']): ?>
      <div class="conn-status wait"><span class="up-led"></span>
        <span>Not configured. Add <code>GOOGLE_OAUTH_CLIENT_ID</code> and <code>GOOGLE_OAUTH_CLIENT_SECRET</code> to <code>.env</code>, then reload.</span>
      </div>
      <p class="muted">Create an OAuth client in Google Cloud (APIs &amp; Services → Credentials). Enable the <strong>Search Console API</strong> and <strong>Site Verification API</strong>. Authorized redirect URI:</p>
      <code class="code"><?= e(config('search.google.redirect_uri')) ?></code>
    <?php elseif ($google['connected']): ?>
      <div class="conn-status ok"><span class="up-led"></span>
        <span><strong>Connected</strong><?= $google['account'] !== '' ? ' as ' . e($google['account']) : '' ?>. Search performance + sitemap submission enabled.</span>
      </div>
      <form method="post" action="<?= app_url('integrations/google/disconnect') ?>" style="margin-top:12px" onsubmit="return confirm('Disconnect the Google account? Site connections stay but will stop syncing.');">
        <?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit">Disconnect Google</button>
      </form>
    <?php else: ?>
      <div class="conn-status wait"><span class="up-led"></span>
        <span>Configured but not connected. Authorize your Google account to manage properties and read Search Console data.</span>
      </div>
      <a class="btn btn-primary mt" href="<?= app_url('integrations/google/connect') ?>">Connect Google account</a>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Bing Webmaster Tools</h2>
    <?php if ($bing['configured']): ?>
      <div class="conn-status ok"><span class="up-led"></span>
        <span><strong>API key set.</strong> URL submission + search stats enabled for connected sites.</span>
      </div>
    <?php else: ?>
      <div class="conn-status wait"><span class="up-led"></span>
        <span>Not configured. Add <code>BING_WEBMASTER_API_KEY</code> to <code>.env</code>.</span>
      </div>
      <p class="muted">In Bing Webmaster Tools → <strong>Settings → API access → API Key</strong>, generate a key and paste it into <code>.env</code>.</p>
    <?php endif; ?>

    <hr style="border:none;border-top:1px solid var(--line);margin:16px 0">
    <h3 style="margin:0 0 6px">IndexNow</h3>
    <?php if ($indexnow['configured']): ?>
      <div class="conn-status ok" style="margin-bottom:8px"><span class="up-led"></span>
        <span><strong>Enabled.</strong> Instant crawl pings to Bing/Yandex.</span>
      </div>
      <p class="muted" style="font-size:.82rem">Key file (this domain): <code><?= e(app_url($indexnow['key'] . '.txt')) ?></code></p>
    <?php else: ?>
      <div class="conn-status wait"><span class="up-led"></span>
        <span>Not set. Add <code>INDEXNOW_KEY</code> (32 hex chars) to <code>.env</code>.</span>
      </div>
      <p class="muted" style="font-size:.82rem">Generate one: <code>php -r "echo bin2hex(random_bytes(16));"</code></p>
    <?php endif; ?>

    <hr style="border:none;border-top:1px solid var(--line);margin:16px 0">
    <h3 style="margin:0 0 6px">Cloudflare (auto DNS verification)</h3>
    <div class="conn-status <?= $cloudflare['configured'] ? 'ok' : 'wait' ?>"><span class="up-led"></span>
      <span><?= $cloudflare['configured'] ? '<strong>Configured.</strong> Domains in your Cloudflare account are verified automatically via DNS TXT.' : 'Optional. Add <code>CLOUDFLARE_API_TOKEN</code> (Zone:DNS:Edit) to auto-verify Cloudflare-hosted domains.' ?></span>
    </div>
  </div>
</div>

<div class="card mt">
  <h2>How it works</h2>
  <ul class="muted" style="line-height:1.7">
    <li><strong>Google</strong> — verifies ownership (Cloudflare DNS when possible, else a meta tag served by the Brionic WordPress plugin), then reads Search Console performance and submits your sitemap on request.</li>
    <li><strong>Bing</strong> — adds the site via API; <strong>IndexNow</strong> handles instant indexing (its key file is served by the WordPress plugin on each site). Import your sites into Bing in one click via “Import from Google Search Console”.</li>
    <li><strong>Request indexing</strong> — from any site’s Settings, submit its sitemap to Google and push URLs to Bing/IndexNow whenever you publish.</li>
  </ul>
</div>

<?php $this->stop(); ?>
