<?php
/**
 * Plugin Name:       Brionic Reports
 * Plugin URI:        https://reports.brionicsecurity.com
 * Description:       Adds privacy-first Brionic Reports analytics to your WordPress site. No cookies, no personal data collected.
 * Version:           1.0.3
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

define('BRIONIC_REPORTS_DEFAULT_KEY', '__SITE_KEY__');
define('BRIONIC_REPORTS_SRC', '__TRACKER_SRC__');

/**
 * The active site key. A plugin downloaded from the dashboard has the key baked
 * in (BRIONIC_REPORTS_DEFAULT_KEY) — that always wins, so it works with no setup
 * and is immune to hosts whose option storage is unreliable. Only an unbaked
 * copy falls back to the key saved on the settings page.
 */
function brionic_reports_key() {
    if (BRIONIC_REPORTS_DEFAULT_KEY !== '__SITE_KEY__') {
        return BRIONIC_REPORTS_DEFAULT_KEY;
    }
    return trim((string) get_option('brionic_reports_site_key', ''));
}

/** On activation, seed the option with the baked-in key if not already set. */
register_activation_hook(__FILE__, function () {
    if (get_option('brionic_reports_site_key', '') === ''
        && BRIONIC_REPORTS_DEFAULT_KEY !== '__SITE_KEY__') {
        update_option('brionic_reports_site_key', BRIONIC_REPORTS_DEFAULT_KEY);
    }
});

/** Inject the tracker into the <head> of every front-end page. */
add_action('wp_head', function () {
    $key = brionic_reports_key();
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
    $key = brionic_reports_key();
    if ($key === '' || $key === '__SITE_KEY__') {
        return;
    }
    wp_enqueue_script('brionic-reports', BRIONIC_REPORTS_SRC, [], null, false);
});
add_filter('script_loader_tag', function ($tag, $handle, $src) {
    if ($handle !== 'brionic-reports') {
        return $tag;
    }
    $key = brionic_reports_key();
    return '<script defer data-site="' . esc_attr($key) . '" data-via="wordpress" src="' . esc_url($src) . '"></script>' . "\n";
}, 10, 3);

/** Base URL of the Brionic Reports instance (derived from the tracker URL). */
function brionic_reports_base() {
    return preg_replace('#/b\.js.*$#', '', BRIONIC_REPORTS_SRC);
}

/** Server-side connection test against the Reports /api/verify endpoint. */
function brionic_reports_test_connection() {
    $key = brionic_reports_key();
    if ($key === '' || $key === '__SITE_KEY__') {
        return ['ok' => false, 'msg' => 'No site key is configured yet.'];
    }
    $url = brionic_reports_base() . '/api/verify?key=' . rawurlencode($key);
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

/** Settings page under Settings → Brionic Reports. */
add_action('admin_menu', function () {
    add_options_page(
        'Brionic Reports',
        'Brionic Reports',
        'manage_options',
        'brionic-reports',
        'brionic_reports_settings_page'
    );
});

function brionic_reports_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Self-contained, nonce-protected save (does not rely on the Settings API,
    // which can be blocked by some hosts/security plugins).
    if (isset($_POST['brionic_reports_save']) && check_admin_referer('brionic_reports_save')) {
        $val = isset($_POST['brionic_reports_site_key'])
            ? sanitize_text_field(wp_unslash($_POST['brionic_reports_site_key']))
            : '';
        update_option('brionic_reports_site_key', $val);
        echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
    }

    // "Test connection" — server-side check against Reports.
    $test = null;
    if (isset($_POST['brionic_reports_test']) && check_admin_referer('brionic_reports_test')) {
        $test = brionic_reports_test_connection();
        $cls = $test['ok'] ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>' . esc_html($test['msg']) . '</p></div>';
    }

    $saved = trim((string) get_option('brionic_reports_site_key', ''));
    $baked = (BRIONIC_REPORTS_DEFAULT_KEY !== '__SITE_KEY__');
    $key = brionic_reports_key();
    $active = ($key !== '' && $key !== '__SITE_KEY__');
    ?>
    <div class="wrap">
        <h1>Brionic Reports</h1>
        <p>Privacy-first analytics. Once a site key is set, a lightweight tracker is added to every page&mdash;no cookies, no personal data.</p>
        <?php if ($baked): ?>
            <div class="notice notice-info inline"><p>This plugin was downloaded from your dashboard with the site key <strong>built in</strong> &mdash; no configuration needed.</p></div>
        <?php endif; ?>
        <form action="" method="post">
            <?php wp_nonce_field('brionic_reports_save'); ?>
            <input type="hidden" name="brionic_reports_save" value="1">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="brionic_reports_site_key">Site key</label></th>
                    <td>
                        <input name="brionic_reports_site_key" id="brionic_reports_site_key" type="text"
                               class="regular-text" value="<?php echo esc_attr($baked ? BRIONIC_REPORTS_DEFAULT_KEY : $saved); ?>"
                               placeholder="Paste your key here — starts with site_" <?php echo $baked ? 'readonly' : ''; ?>>
                        <p class="description">
                            <?php if ($baked): ?>
                                Built into this download: <code><?php echo esc_html(BRIONIC_REPORTS_DEFAULT_KEY); ?></code>. Nothing to save.
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
            <?php wp_nonce_field('brionic_reports_test'); ?>
            <input type="hidden" name="brionic_reports_test" value="1">
            <button type="submit" class="button button-secondary">Test connection</button>
            <span class="description" style="margin-left:8px">Checks that your server can reach Brionic Reports and that the site key is valid.</span>
        </form>
    </div>
    <?php
}
