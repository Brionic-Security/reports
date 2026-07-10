<?php
/**
 * Plugin Name:       Brionic Reports
 * Plugin URI:        https://reports.brionicsecurity.com
 * Description:       Brionic Reports analytics, email controls, automatic-update management with notifications, and a branded login page — one plugin for your Brionic-managed WordPress site.
 * Version:           1.2.2
 * Author:            Brionic Security
 * Author URI:        https://brionicsecurity.com
 * License:           MIT
 * Requires at least: 5.0
 * Requires PHP:      7.2
 *
 * The site key + tracker URL below are pre-filled when you download this plugin
 * from your Brionic Reports dashboard. You can also change the key later under
 * Settings → Brionic Reports.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BRIONIC_ANALYTICS_DEFAULT_KEY', '__SITE_KEY__');
define('BRIONIC_ANALYTICS_SRC', '__TRACKER_SRC__');

/**
 * The active site key. A plugin downloaded from the dashboard has the key baked
 * in (BRIONIC_ANALYTICS_DEFAULT_KEY) — that always wins, so it works with no setup
 * and is immune to hosts whose option storage is unreliable. Only an unbaked
 * copy falls back to the key saved on the settings page.
 */
function brionic_analytics_key() {
    // A baked download has the real key (always begins "site_") compiled in; the
    // unbaked source keeps the "__SITE_KEY__" placeholder. Detect by prefix so the
    // check survives the download-time token replacement and always wins.
    if (strncmp(BRIONIC_ANALYTICS_DEFAULT_KEY, 'site_', 5) === 0) {
        return BRIONIC_ANALYTICS_DEFAULT_KEY;
    }
    return trim((string) get_option('brionic_analytics_site_key', ''));
}

/** On activation, seed the option with the baked-in key if not already set. */
register_activation_hook(__FILE__, function () {
    if (get_option('brionic_analytics_site_key', '') === ''
        && strncmp(BRIONIC_ANALYTICS_DEFAULT_KEY, 'site_', 5) === 0) {
        update_option('brionic_analytics_site_key', BRIONIC_ANALYTICS_DEFAULT_KEY);
    }
});

/** Inject the tracker into the <head> of every front-end page. */
add_action('wp_head', function () {
    $key = brionic_analytics_key();
    if (strncmp($key, 'site_', 5) !== 0 || is_admin()) {
        return;
    }
    // A comment marker is emitted even if an optimiser later strips the <script>,
    // so the connection validator can tell "active but stripped" from "inactive".
    printf("\n<!-- Brionic Reports active: %s -->\n", esc_html($key));
}, 1);

// Load the tracker the standard WordPress way so caching/optimisation plugins
// keep it (raw wp_head output is sometimes stripped or combined away).
add_action('wp_enqueue_scripts', function () {
    $key = brionic_analytics_key();
    if (strncmp($key, 'site_', 5) !== 0) {
        return;
    }
    wp_enqueue_script('brionic-analytics', BRIONIC_ANALYTICS_SRC, [], null, false);
});
add_filter('script_loader_tag', function ($tag, $handle, $src) {
    if ($handle !== 'brionic-analytics') {
        return $tag;
    }
    $key = brionic_analytics_key();
    return '<script defer data-site="' . esc_attr($key) . '" data-via="wordpress" src="' . esc_url($src) . '"></script>' . "\n";
}, 10, 3);

/** Base URL of the Brionic Reports instance (derived from the tracker URL). */
function brionic_analytics_base() {
    return preg_replace('#/b\.js.*$#', '', BRIONIC_ANALYTICS_SRC);
}

/** Server-side connection test against the Reports /api/verify endpoint. */
function brionic_analytics_test_connection() {
    $key = brionic_analytics_key();
    if (strncmp($key, 'site_', 5) !== 0) {
        return ['ok' => false, 'msg' => 'No site key is configured yet.'];
    }
    $url = brionic_analytics_base() . '/api/verify?key=' . rawurlencode($key);
    $resp = wp_remote_get($url, ['timeout' => 15, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($resp)) {
        return ['ok' => false, 'msg' => 'Could not reach Brionic Reports from your server: ' . $resp->get_error_message()
            . ' (your host may be blocking outbound requests).'];
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code === 200 && !empty($body['ok'])) {
        return ['ok' => true, 'msg' => 'Connected! Brionic Reports recognised this site'
            . (!empty($body['name']) ? ' (' . $body['name'] . ')' : '') . '. Your key and connectivity are good.'];
    }
    if ($code === 404) {
        return ['ok' => false, 'msg' => 'Brionic Reports did not recognise this site key. Double-check the key matches the one in your dashboard.'];
    }
    return ['ok' => false, 'msg' => 'Unexpected response from Brionic Reports (HTTP ' . $code . '). Please try again.'];
}

// ── Site email controls ─────────────────────────────────────────────────────
// Optional overrides for outgoing WordPress mail. Each is applied only when a
// value is saved, so a blank field keeps the WordPress default.

add_filter('wp_mail_from', function ($email) {
    $from = sanitize_email((string) get_option('brionic_mail_from_email', ''));
    return is_email($from) ? $from : $email;
}, 99);

add_filter('wp_mail_from_name', function ($name) {
    $from = trim((string) get_option('brionic_mail_from_name', ''));
    return $from !== '' ? $from : $name;
}, 99);

add_action('phpmailer_init', function ($phpmailer) {
    $reply = sanitize_email((string) get_option('brionic_mail_reply_to', ''));
    if (is_email($reply)) {
        try { $phpmailer->addReplyTo($reply); } catch (\Exception $e) {}
    }
    $forward = sanitize_email((string) get_option('brionic_mail_forward_to', ''));
    if (is_email($forward)) {
        try { $phpmailer->addBCC($forward); } catch (\Exception $e) {}
    }
});

/** Send a test email using the configured settings. */
function brionic_mail_send_test($to) {
    $to = sanitize_email((string) $to);
    if (!is_email($to)) {
        return ['ok' => false, 'msg' => 'Enter a valid email address to send the test to.'];
    }
    $sent = wp_mail(
        $to,
        'Brionic Reports — test email',
        "This is a test email from your WordPress site, sent through Brionic Reports Config.\n\n"
        . "If you received it, your From name/address, Reply-To and forwarding settings are working."
    );
    return $sent
        ? ['ok' => true, 'msg' => 'Test email sent to ' . $to . '. Check that inbox (and your forward address, if set).']
        : ['ok' => false, 'msg' => 'WordPress could not send the test email. Check your site\'s email/SMTP configuration.'];
}

// ── Automatic updates ───────────────────────────────────────────────────────
// Configure which updates install automatically, and email a summary when the
// auto-updater runs (sent through the email settings above).

add_filter('auto_update_plugin', function ($update, $item) {
    return get_option('brionic_au_plugins', '0') === '1' ? true : $update;
}, 10, 2);

add_filter('auto_update_theme', function ($update, $item) {
    return get_option('brionic_au_themes', '0') === '1' ? true : $update;
}, 10, 2);

add_filter('allow_minor_auto_core_updates', function () {
    return get_option('brionic_au_core_minor', '1') === '1';
});

add_filter('allow_major_auto_core_updates', function () {
    return get_option('brionic_au_core_major', '0') === '1';
});

// Optionally silence WordPress's own core auto-update emails (Brionic sends its own).
if (get_option('brionic_au_quiet', '0') === '1') {
    add_filter('auto_core_update_send_email', '__return_false');
}

// Email a summary after the auto-updater runs.
add_action('automatic_updates_complete', function ($results) {
    if (get_option('brionic_au_notify', '1') !== '1') {
        return;
    }
    $labels = ['core' => 'WordPress core', 'plugin' => 'Plugin', 'theme' => 'Theme', 'translation' => 'Translation'];
    $lines = [];
    foreach ($labels as $type => $label) {
        if (empty($results[$type])) {
            continue;
        }
        foreach ((array) $results[$type] as $r) {
            $name = !empty($r->name) ? $r->name : (!empty($r->item->slug) ? $r->item->slug : '');
            $ver = !empty($r->item->new_version) ? ' v' . $r->item->new_version : '';
            $ok = !empty($r->result) && !is_wp_error($r->result);
            $lines[] = ($ok ? '[OK]   ' : '[FAILED] ') . $label . ': ' . $name . $ver;
        }
    }
    if (!$lines) {
        return;
    }
    $site = get_bloginfo('name');
    $to = sanitize_email((string) get_option('brionic_au_notify_email', ''));
    if (!is_email($to)) {
        $to = (string) get_option('admin_email');
    }
    wp_mail(
        $to,
        'Automatic updates installed on ' . $site,
        'The automatic updater ran on ' . $site . ' (' . home_url() . "):\n\n"
        . implode("\n", $lines) . "\n\n— Brionic Reports"
    );
});

// ── Login page branding ─────────────────────────────────────────────────────
// A LoginPress-style branded login screen: your logo + a full-page background.
// Defaults ship with the Brionic Security logo and a geometric red/blue wallpaper.

function brionic_login_asset_url($option, $default_file) {
    $url = trim((string) get_option($option, ''));
    return $url !== '' ? esc_url($url) : esc_url(plugins_url('assets/' . $default_file, __FILE__));
}

add_action('login_enqueue_scripts', function () {
    if (get_option('brionic_login_enabled', '1') !== '1') {
        return;
    }
    $logo = brionic_login_asset_url('brionic_login_logo_url', 'login-logo.png');
    $bg   = brionic_login_asset_url('brionic_login_bg_url', 'login-bg.jpg');
    ?>
    <style id="brionic-login">
      body.login { background:#0e1118 url("<?php echo $bg; ?>") center center / cover no-repeat fixed; }
      body.login::before { content:""; position:fixed; inset:0; background:rgba(10,12,18,.30); z-index:0; }
      #login { position:relative; z-index:1; background:rgba(255,255,255,.95); border-radius:16px; padding:24px 24px 28px; box-shadow:0 24px 70px rgba(0,0,0,.4); }
      .login h1 a { background-image:url("<?php echo $logo; ?>"); background-size:contain; background-position:center; background-repeat:no-repeat; width:100%; height:66px; margin:0 auto 6px; }
      .login form { margin-top:14px; background:transparent; border:0; box-shadow:none; padding:0; }
      .login #backtoblog a, .login #nav a { color:#eef2f8; text-shadow:0 1px 3px rgba(0,0,0,.6); }
      .login #backtoblog a:hover, .login #nav a:hover { color:#fff; }
      .wp-core-ui .button-primary { background:#d92b32; border-color:#b81d23; box-shadow:none; text-shadow:none; }
      .wp-core-ui .button-primary:hover { background:#b81d23; border-color:#b81d23; }
      .login input[type=text]:focus, .login input[type=password]:focus { border-color:#d92b32; box-shadow:0 0 0 1px #d92b32; }
    </style>
    <?php
});

add_filter('login_headerurl', function () {
    return home_url('/');
});
add_filter('login_headertext', function () {
    return get_bloginfo('name');
});

/** Settings page under Settings → Brionic Reports Config. */
add_action('admin_menu', function () {
    add_options_page(
        'Brionic Reports Config',
        'Brionic Reports',
        'manage_options',
        'brionic-analytics',
        'brionic_analytics_settings_page'
    );
});

/**
 * Best-effort flush of everything that can leave stale pages or compiled code on
 * the site: the WordPress object cache, PHP OPcache, and any common caching
 * plugin. Each step is guarded so it is safe on any host. Returns a list of the
 * caches that were actually cleared.
 */
function brionic_cache_flush() {
    $done = [];

    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
        $done[] = 'WordPress object cache';
    }

    if (function_exists('opcache_reset') && @opcache_reset()) {
        $done[] = 'PHP OPcache';
    }
    if (function_exists('opcache_invalidate') && defined('ABSPATH')) {
        // Also invalidate the file cache some hosts (e.g. SiteGround) use.
        @opcache_invalidate(__FILE__, true);
    }

    // SiteGround Speed Optimizer.
    if (class_exists('\\SiteGround_Optimizer\\Supercacher\\Supercacher')) {
        do_action('siteground_optimizer_flush_cache');
        $done[] = 'SiteGround cache';
    }

    // WP Rocket.
    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
        $done[] = 'WP Rocket';
    }

    // LiteSpeed Cache.
    if (has_action('litespeed_purge_all')) {
        do_action('litespeed_purge_all');
        $done[] = 'LiteSpeed cache';
    }

    // W3 Total Cache.
    if (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
        $done[] = 'W3 Total Cache';
    }

    return $done;
}

function brionic_analytics_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Self-contained, nonce-protected save (does not rely on the Settings API,
    // which can be blocked by some hosts/security plugins).
    if (isset($_POST['brionic_analytics_save']) && check_admin_referer('brionic_analytics_save')) {
        $val = isset($_POST['brionic_analytics_site_key'])
            ? sanitize_text_field(wp_unslash($_POST['brionic_analytics_site_key']))
            : '';
        update_option('brionic_analytics_site_key', $val);
        echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
    }

    // "Test connection" — server-side check against Reports.
    $test = null;
    if (isset($_POST['brionic_analytics_test']) && check_admin_referer('brionic_analytics_test')) {
        $test = brionic_analytics_test_connection();
        $cls = $test['ok'] ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>' . esc_html($test['msg']) . '</p></div>';
    }

    // Email settings save.
    if (isset($_POST['brionic_mail_save']) && check_admin_referer('brionic_mail_save')) {
        update_option('brionic_mail_from_name', sanitize_text_field(wp_unslash($_POST['brionic_mail_from_name'] ?? '')));
        update_option('brionic_mail_from_email', sanitize_email(wp_unslash($_POST['brionic_mail_from_email'] ?? '')));
        update_option('brionic_mail_reply_to', sanitize_email(wp_unslash($_POST['brionic_mail_reply_to'] ?? '')));
        update_option('brionic_mail_forward_to', sanitize_email(wp_unslash($_POST['brionic_mail_forward_to'] ?? '')));
        echo '<div class="notice notice-success is-dismissible"><p>Email settings saved.</p></div>';
    }

    // Send a test email.
    if (isset($_POST['brionic_mail_test']) && check_admin_referer('brionic_mail_test')) {
        $r = brionic_mail_send_test(wp_unslash($_POST['brionic_mail_test_to'] ?? ''));
        echo '<div class="notice ' . ($r['ok'] ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>' . esc_html($r['msg']) . '</p></div>';
    }

    // Automatic-update settings save.
    if (isset($_POST['brionic_au_save']) && check_admin_referer('brionic_au_save')) {
        update_option('brionic_au_core_minor', isset($_POST['brionic_au_core_minor']) ? '1' : '0');
        update_option('brionic_au_core_major', isset($_POST['brionic_au_core_major']) ? '1' : '0');
        update_option('brionic_au_plugins',    isset($_POST['brionic_au_plugins']) ? '1' : '0');
        update_option('brionic_au_themes',     isset($_POST['brionic_au_themes']) ? '1' : '0');
        update_option('brionic_au_notify',     isset($_POST['brionic_au_notify']) ? '1' : '0');
        update_option('brionic_au_quiet',      isset($_POST['brionic_au_quiet']) ? '1' : '0');
        update_option('brionic_au_notify_email', sanitize_email(wp_unslash($_POST['brionic_au_notify_email'] ?? '')));
        echo '<div class="notice notice-success is-dismissible"><p>Update settings saved.</p></div>';
    }

    // Login page settings save.
    if (isset($_POST['brionic_login_save']) && check_admin_referer('brionic_login_save')) {
        update_option('brionic_login_enabled', isset($_POST['brionic_login_enabled']) ? '1' : '0');
        update_option('brionic_login_logo_url', esc_url_raw(wp_unslash($_POST['brionic_login_logo_url'] ?? '')));
        update_option('brionic_login_bg_url', esc_url_raw(wp_unslash($_POST['brionic_login_bg_url'] ?? '')));
        echo '<div class="notice notice-success is-dismissible"><p>Login page settings saved.</p></div>';
    }

    // Flush all caches.
    if (isset($_POST['brionic_cache_flush']) && check_admin_referer('brionic_cache_flush')) {
        $flushed = brionic_cache_flush();
        $msg = $flushed
            ? 'Flushed: ' . implode(', ', $flushed) . '.'
            : 'No caches were found to flush.';
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }

    $saved = trim((string) get_option('brionic_analytics_site_key', ''));
    $baked = (strncmp(BRIONIC_ANALYTICS_DEFAULT_KEY, 'site_', 5) === 0);
    $key = brionic_analytics_key();
    $active = (strncmp($key, 'site_', 5) === 0);
    $mFromName  = (string) get_option('brionic_mail_from_name', '');
    $mFromEmail = (string) get_option('brionic_mail_from_email', '');
    $mReplyTo   = (string) get_option('brionic_mail_reply_to', '');
    $mForward   = (string) get_option('brionic_mail_forward_to', '');
    $adminEmail = (string) get_option('admin_email', '');
    $auCoreMinor = get_option('brionic_au_core_minor', '1') === '1';
    $auCoreMajor = get_option('brionic_au_core_major', '0') === '1';
    $auPlugins   = get_option('brionic_au_plugins', '0') === '1';
    $auThemes    = get_option('brionic_au_themes', '0') === '1';
    $auNotify    = get_option('brionic_au_notify', '1') === '1';
    $auQuiet     = get_option('brionic_au_quiet', '0') === '1';
    $auEmail     = (string) get_option('brionic_au_notify_email', '');
    $loginOn     = get_option('brionic_login_enabled', '1') === '1';
    $loginLogo   = (string) get_option('brionic_login_logo_url', '');
    $loginBg     = (string) get_option('brionic_login_bg_url', '');
    ?>
    <div class="wrap">
        <h1>Brionic Reports Config</h1>
        <h2>Website analytics</h2>
        <p>Privacy-first analytics. Once a site key is set, a lightweight tracker is added to every page&mdash;no cookies, no personal data.</p>
        <?php if ($baked): ?>
            <div class="notice notice-info inline"><p>This plugin was downloaded from your dashboard with the site key <strong>built in</strong> &mdash; no configuration needed.</p></div>
        <?php endif; ?>
        <form action="" method="post">
            <?php wp_nonce_field('brionic_analytics_save'); ?>
            <input type="hidden" name="brionic_analytics_save" value="1">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="brionic_analytics_site_key">Site key</label></th>
                    <td>
                        <input name="brionic_analytics_site_key" id="brionic_analytics_site_key" type="text"
                               class="regular-text" value="<?php echo esc_attr($baked ? BRIONIC_ANALYTICS_DEFAULT_KEY : $saved); ?>"
                               placeholder="Paste your key here — starts with site_" <?php echo $baked ? 'readonly' : ''; ?>>
                        <p class="description">
                            <?php if ($baked): ?>
                                Built into this download: <code><?php echo esc_html(BRIONIC_ANALYTICS_DEFAULT_KEY); ?></code>. Nothing to save.
                            <?php elseif ($saved !== ''): ?>
                                Currently saved: <code><?php echo esc_html($saved); ?></code>
                            <?php else: ?>
                                <strong style="color:#c0341d">No key saved yet.</strong> Paste the site key from your Brionic Reports dashboard (the site&rsquo;s settings page) and click Save.
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php if (!$baked): ?><?php submit_button('Save changes'); ?><?php endif; ?>
        </form>

        <hr>
        <h2>Connection</h2>
        <p><strong>Status:</strong>
            <?php if ($active): ?>
                <span style="color:#12996b;font-weight:600">&#10003; Active</span> &mdash; tracking with key <code><?php echo esc_html($key); ?></code>.
            <?php else: ?>
                <span style="color:#c0341d;font-weight:600">Not configured</span> &mdash; enter your site key above and click Save.
            <?php endif; ?>
        </p>
        <form action="" method="post" style="margin-top:10px">
            <?php wp_nonce_field('brionic_analytics_test'); ?>
            <input type="hidden" name="brionic_analytics_test" value="1">
            <button type="submit" class="button button-secondary">Test connection</button>
            <span class="description" style="margin-left:8px">Checks that your server can reach Brionic Reports and that the site key is valid.</span>
        </form>

        <hr>
        <h2>Email settings</h2>
        <p>Customise the address WordPress sends email from, set a reply-to address, and optionally forward a blind copy (BCC) of every outgoing email. Each field is optional &mdash; leave it blank to keep the WordPress default.</p>
        <form action="" method="post">
            <?php wp_nonce_field('brionic_mail_save'); ?>
            <input type="hidden" name="brionic_mail_save" value="1">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="brionic_mail_from_name">From name</label></th>
                    <td><input type="text" class="regular-text" id="brionic_mail_from_name" name="brionic_mail_from_name" value="<?php echo esc_attr($mFromName); ?>" placeholder="e.g. Your Business Name">
                        <p class="description">The sender name recipients see.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="brionic_mail_from_email">From email</label></th>
                    <td><input type="email" class="regular-text" id="brionic_mail_from_email" name="brionic_mail_from_email" value="<?php echo esc_attr($mFromEmail); ?>" placeholder="<?php echo esc_attr($adminEmail); ?>">
                        <p class="description">The From address for outgoing mail. Use an address on your own domain for best deliverability.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="brionic_mail_reply_to">Reply-To email</label></th>
                    <td><input type="email" class="regular-text" id="brionic_mail_reply_to" name="brionic_mail_reply_to" value="<?php echo esc_attr($mReplyTo); ?>" placeholder="(optional)">
                        <p class="description">Where replies go, if different from the From address.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="brionic_mail_forward_to">Forward a copy to</label></th>
                    <td><input type="email" class="regular-text" id="brionic_mail_forward_to" name="brionic_mail_forward_to" value="<?php echo esc_attr($mForward); ?>" placeholder="(optional)">
                        <p class="description">A blind copy (BCC) of <strong>every</strong> email your site sends will be forwarded here. Leave blank to disable.</p></td>
                </tr>
            </table>
            <?php submit_button('Save email settings'); ?>
        </form>

        <h3>Send a test email</h3>
        <form action="" method="post">
            <?php wp_nonce_field('brionic_mail_test'); ?>
            <input type="hidden" name="brionic_mail_test" value="1">
            <input type="email" class="regular-text" name="brionic_mail_test_to" value="<?php echo esc_attr($adminEmail); ?>">
            <button type="submit" class="button button-secondary">Send test email</button>
            <p class="description">Save your settings first, then send a test to confirm From/Reply-To and forwarding work.</p>
        </form>

        <hr>
        <h2>Automatic updates</h2>
        <p>Choose what installs automatically and get an email summary when the updater runs.</p>
        <form action="" method="post">
            <?php wp_nonce_field('brionic_au_save'); ?>
            <input type="hidden" name="brionic_au_save" value="1">
            <table class="form-table" role="presentation">
                <tr><th scope="row">What to auto-update</th><td>
                    <label><input type="checkbox" name="brionic_au_core_minor" <?php checked($auCoreMinor); ?>> WordPress core &mdash; minor &amp; security releases <span class="description">(recommended)</span></label><br>
                    <label><input type="checkbox" name="brionic_au_core_major" <?php checked($auCoreMajor); ?>> WordPress core &mdash; major releases</label><br>
                    <label><input type="checkbox" name="brionic_au_plugins" <?php checked($auPlugins); ?>> All plugins</label><br>
                    <label><input type="checkbox" name="brionic_au_themes" <?php checked($auThemes); ?>> All themes</label>
                </td></tr>
                <tr><th scope="row">Notifications</th><td>
                    <label><input type="checkbox" name="brionic_au_notify" <?php checked($auNotify); ?>> Email me a summary after automatic updates run</label><br>
                    <label><input type="checkbox" name="brionic_au_quiet" <?php checked($auQuiet); ?>> Silence WordPress&rsquo;s own core-update emails (use Brionic&rsquo;s instead)</label>
                </td></tr>
                <tr><th scope="row"><label for="brionic_au_notify_email">Notify address</label></th><td>
                    <input type="email" class="regular-text" id="brionic_au_notify_email" name="brionic_au_notify_email" value="<?php echo esc_attr($auEmail); ?>" placeholder="<?php echo esc_attr($adminEmail); ?>">
                    <p class="description">Where update summaries go (sent using the email settings above). Uses the admin email if blank.</p>
                </td></tr>
            </table>
            <?php submit_button('Save update settings'); ?>
        </form>

        <hr>
        <h2>Login page</h2>
        <p>Give your WordPress login screen the Brionic look &mdash; your logo over a full-page background. Defaults to the Brionic Security logo and a geometric red/blue wallpaper, and works with a custom login URL (e.g. /door).</p>
        <form action="" method="post">
            <?php wp_nonce_field('brionic_login_save'); ?>
            <input type="hidden" name="brionic_login_save" value="1">
            <table class="form-table" role="presentation">
                <tr><th scope="row">Enable</th><td>
                    <label><input type="checkbox" name="brionic_login_enabled" <?php checked($loginOn); ?>> Brand the login page</label>
                </td></tr>
                <tr><th scope="row"><label for="brionic_login_logo_url">Logo URL</label></th><td>
                    <input type="url" class="regular-text" id="brionic_login_logo_url" name="brionic_login_logo_url" value="<?php echo esc_attr($loginLogo); ?>" placeholder="Default: built-in Brionic logo">
                    <p class="description">Leave blank to use the built-in Brionic Security logo, or paste a Media Library image URL.</p>
                </td></tr>
                <tr><th scope="row"><label for="brionic_login_bg_url">Background URL</label></th><td>
                    <input type="url" class="regular-text" id="brionic_login_bg_url" name="brionic_login_bg_url" value="<?php echo esc_attr($loginBg); ?>" placeholder="Default: built-in geometric wallpaper">
                    <p class="description">Leave blank to use the built-in wallpaper, or paste a Media Library image URL.</p>
                </td></tr>
                <tr><th scope="row">Preview</th><td>
                    <a href="<?php echo esc_url(wp_login_url()); ?>" target="_blank" class="button button-secondary">Open login page</a>
                    <span class="description" style="margin-left:8px">Opens your login screen in a new tab.</span>
                </td></tr>
            </table>
            <?php submit_button('Save login settings'); ?>
        </form>

        <hr>
        <h2>Maintenance</h2>
        <p>Clear cached pages and compiled PHP if the site is serving stale content or a plugin/theme change isn&rsquo;t showing up. Safe to run anytime &mdash; caches simply rebuild on the next visit.</p>
        <form action="" method="post">
            <?php wp_nonce_field('brionic_cache_flush'); ?>
            <input type="hidden" name="brionic_cache_flush" value="1">
            <button type="submit" class="button button-secondary">Flush all caches</button>
            <span class="description" style="margin-left:8px">Clears the WordPress object cache, PHP OPcache, and any caching plugin found (SiteGround, WP Rocket, LiteSpeed, W3&nbsp;Total&nbsp;Cache).</span>
        </form>
    </div>
    <?php
}
