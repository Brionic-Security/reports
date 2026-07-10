<?php
/**
 * Plugin Name:       Brionic Reports
 * Plugin URI:        https://reports.brionicsecurity.com
 * Description:       Adds privacy-first Brionic Reports analytics to your WordPress site. No cookies, no personal data collected.
 * Version:           1.0.1
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

/** The configured site key (option overrides the baked-in default). */
function brionic_reports_key() {
    $key = trim((string) get_option('brionic_reports_site_key', ''));
    if ($key === '') {
        $key = BRIONIC_REPORTS_DEFAULT_KEY;
    }
    return $key;
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
    printf(
        '<script defer data-site="%s" data-via="wordpress" src="%s"></script>' . "\n",
        esc_attr($key),
        esc_url(BRIONIC_REPORTS_SRC)
    );
}, 1);

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

    $saved = trim((string) get_option('brionic_reports_site_key', ''));
    $key = brionic_reports_key();
    $active = ($key !== '' && $key !== '__SITE_KEY__');
    ?>
    <div class="wrap">
        <h1>Brionic Reports</h1>
        <p>Privacy-first analytics. Once a site key is set, a lightweight tracker is added to every page&mdash;no cookies, no personal data.</p>
        <form action="" method="post">
            <?php wp_nonce_field('brionic_reports_save'); ?>
            <input type="hidden" name="brionic_reports_save" value="1">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="brionic_reports_site_key">Site key</label></th>
                    <td>
                        <input name="brionic_reports_site_key" id="brionic_reports_site_key" type="text"
                               class="regular-text" value="<?php echo esc_attr($saved !== '' ? $saved : (BRIONIC_REPORTS_DEFAULT_KEY !== '__SITE_KEY__' ? BRIONIC_REPORTS_DEFAULT_KEY : '')); ?>"
                               placeholder="site_xxxxxxxxxxxxxxxxxxxx">
                        <p class="description">From your Brionic Reports dashboard &rarr; the site&rsquo;s settings page. If you downloaded this plugin from the dashboard, it is already filled in.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save changes'); ?>
        </form>
        <p><strong>Status:</strong>
            <?php if ($active): ?>
                <span style="color:#12996b;font-weight:600">&#10003; Active</span> &mdash; tracking with key <code><?php echo esc_html($key); ?></code>.
            <?php else: ?>
                <span style="color:#c0341d;font-weight:600">Not configured</span> &mdash; enter your site key above and click Save.
            <?php endif; ?>
        </p>
    </div>
    <?php
}
