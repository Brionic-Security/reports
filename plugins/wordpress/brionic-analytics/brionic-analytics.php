<?php
/**
 * Plugin Name:       Brionic Reports
 * Plugin URI:        https://reports.brionicsecurity.com
 * Description:       Privacy-first Brionic Reports analytics, plus site email controls (From name/address, Reply-To, and a forwarded copy of every outgoing email).
 * Version:           1.1.0
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
    if (BRIONIC_ANALYTICS_DEFAULT_KEY !== '__SITE_KEY__') {
        return BRIONIC_ANALYTICS_DEFAULT_KEY;
    }
    return trim((string) get_option('brionic_analytics_site_key', ''));
}

/** On activation, seed the option with the baked-in key if not already set. */
register_activation_hook(__FILE__, function () {
    if (get_option('brionic_analytics_site_key', '') === ''
        && BRIONIC_ANALYTICS_DEFAULT_KEY !== '__SITE_KEY__') {
        update_option('brionic_analytics_site_key', BRIONIC_ANALYTICS_DEFAULT_KEY);
    }
});

/** Inject the tracker into the <head> of every front-end page. */
add_action('wp_head', function () {
    $key = brionic_analytics_key();
    if ($key === '' || $key === '__SITE_KEY__' || is_admin()) {
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
    if ($key === '' || $key === '__SITE_KEY__') {
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
    if ($key === '' || $key === '__SITE_KEY__') {
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

    $saved = trim((string) get_option('brionic_analytics_site_key', ''));
    $baked = (BRIONIC_ANALYTICS_DEFAULT_KEY !== '__SITE_KEY__');
    $key = brionic_analytics_key();
    $active = ($key !== '' && $key !== '__SITE_KEY__');
    $mFromName  = (string) get_option('brionic_mail_from_name', '');
    $mFromEmail = (string) get_option('brionic_mail_from_email', '');
    $mReplyTo   = (string) get_option('brionic_mail_reply_to', '');
    $mForward   = (string) get_option('brionic_mail_forward_to', '');
    $adminEmail = (string) get_option('admin_email', '');
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
    </div>
    <?php
}
