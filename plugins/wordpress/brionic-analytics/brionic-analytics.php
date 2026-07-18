<?php
/**
 * Plugin Name:       Brionic Config
 * Plugin URI:        https://reports.brionicsecurity.com
 * Description:       Brionic all-in-one WordPress config: analytics, SEO, search-engine verification (Google Search Console + IndexNow), email controls, automatic-update management, a branded login page, an under-construction mode, and cache tools — one plugin for your Brionic-managed site.
 * Version:           1.4.0
 * Author:            Brionic Security
 * Author URI:        https://brionicsecurity.com
 * License:           MIT
 * Requires at least: 5.0
 * Requires PHP:      7.2
 *
 * The site key + tracker URL below are pre-filled when you download this plugin
 * from your Brionic Reports dashboard. You can also change the key later under
 * Settings → Brionic Config.
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
    // Expose the tracker config as a raw inline global in <head>. This is the
    // authoritative source the tracker reads, so analytics keep working even when
    // a speed plugin combines/inlines the external b.js (which drops its data-*
    // attributes and rewrites its origin). Marked no-optimize so it stays inline.
    printf(
        '<script data-no-optimize="1" data-no-minify="1" data-cfasync="false">window.__brionic={site:%s,via:"wordpress",origin:%s};</script>' . "\n",
        wp_json_encode($key),
        wp_json_encode(brionic_analytics_base())
    );
}, 1);

// Load the tracker the standard WordPress way so caching/optimisation plugins
// keep it (raw wp_head output is sometimes stripped or combined away). The extra
// attributes + filters below tell optimisers (SiteGround Speed Optimizer, WP
// Rocket, Cloudflare Rocket Loader, etc.) to leave this tiny external script
// alone, so it is never combined/minified/removed and analytics keep working.
add_action('wp_enqueue_scripts', function () {
    $key = brionic_analytics_key();
    if (strncmp($key, 'site_', 5) !== 0) {
        return;
    }
    wp_enqueue_script('brionic-analytics', BRIONIC_ANALYTICS_SRC, [], null, false);
    // Expose the config as a global so the tracker still works even if an
    // optimiser combines/inlines the external script (which drops the data-site
    // attribute and rewrites the script origin).
    wp_add_inline_script(
        'brionic-analytics',
        'window.__brionic={site:' . wp_json_encode($key)
            . ',via:"wordpress",origin:' . wp_json_encode(brionic_analytics_base()) . '};',
        'before'
    );
});
add_filter('script_loader_tag', function ($tag, $handle, $src) {
    if ($handle !== 'brionic-analytics') {
        return $tag;
    }
    $key = brionic_analytics_key();
    return '<script defer data-no-optimize="1" data-no-minify="1" data-no-defer="1" data-cfasync="false"'
        . ' data-site="' . esc_attr($key) . '" data-via="wordpress" src="' . esc_url($src) . '"></script>' . "\n";
}, 10, 3);

// Explicitly exclude the tracker from SiteGround Speed Optimizer's JS handling.
foreach (['sgo_js_minify_exclude', 'sgo_javascript_combine_exclude', 'sgo_js_async_exclude', 'sgo_javascript_combine_exclude_defer'] as $brionic_sgo_filter) {
    add_filter($brionic_sgo_filter, function ($excluded) {
        foreach (['brionic-analytics', 'b.js', 'reports.brionicsecurity.com'] as $needle) {
            if (!in_array($needle, (array) $excluded, true)) {
                $excluded[] = $needle;
            }
        }
        return $excluded;
    });
}

/** Base URL of the Brionic Reports instance (derived from the tracker URL). */
function brionic_analytics_base() {
    return preg_replace('#/b\.js.*$#', '', BRIONIC_ANALYTICS_SRC);
}

/* ------------------------------------------------------------------ *
 * Search-engine verification + IndexNow.
 * Pulls this site's verification tokens from Brionic Reports so a site
 * connected in the dashboard verifies itself with zero manual steps:
 *   - injects the Google Search Console ownership <meta> tag, and
 *   - serves the IndexNow key file at /{key}.txt (Bing/Yandex indexing).
 * Tokens are cached in a transient so this adds no per-request overhead.
 * ------------------------------------------------------------------ */

/** Fetch (and cache) this site's search tags from Reports. */
function brionic_search_tags() {
    $empty = ['google_meta' => '', 'indexnow_key' => ''];
    $key = brionic_analytics_key();
    if (strncmp($key, 'site_', 5) !== 0) {
        return $empty;
    }
    $cached = get_transient('brionic_search_tags');
    if (is_array($cached)) {
        return $cached;
    }
    $tags = $empty;
    $url = brionic_analytics_base() . '/api/search-tags?key=' . rawurlencode($key);
    $resp = wp_remote_get($url, ['timeout' => 12, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
        // Back off briefly on failure so we don't hammer the API.
        set_transient('brionic_search_tags', $tags, 30 * MINUTE_IN_SECONDS);
        return $tags;
    }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (is_array($body) && !empty($body['ok'])) {
        $tags['google_meta']  = isset($body['google_meta']) ? (string) $body['google_meta'] : '';
        $tags['indexnow_key'] = isset($body['indexnow_key']) ? (string) $body['indexnow_key'] : '';
    }
    // Cache a real result for longer; cache an "empty" (not yet connected)
    // result briefly so newly-connected sites pick up their token quickly.
    $hasTokens = $tags['google_meta'] !== '' || $tags['indexnow_key'] !== '';
    set_transient('brionic_search_tags', $tags, $hasTokens ? 6 * HOUR_IN_SECONDS : 20 * MINUTE_IN_SECONDS);
    return $tags;
}

/** Inject the Google Search Console ownership meta tag into <head>. */
add_action('wp_head', function () {
    if (is_admin()) {
        return;
    }
    $tags = brionic_search_tags();
    if (!empty($tags['google_meta'])) {
        printf('<meta name="google-site-verification" content="%s">' . "\n", esc_attr($tags['google_meta']));
    }
}, 1);

/** Serve the IndexNow key file at /{key}.txt (root-level), for Bing/Yandex. */
add_action('template_redirect', function () {
    $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if (!preg_match('#^([a-f0-9]{8,64})\.txt$#i', $path, $m)) {
        return;
    }
    $tags = brionic_search_tags();
    $key = (string) $tags['indexnow_key'];
    if ($key !== '' && hash_equals($key, $m[1])) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $key;
        exit;
    }
});

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
        "This is a test email from your WordPress site, sent through Brionic Config.\n\n"
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

/** Parse one or more email addresses (comma / semicolon / space / newline separated) into a validated, de-duplicated list. */
function brionic_email_list($raw) {
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) as $part) {
        $email = sanitize_email($part);
        if ($email && is_email($email) && !in_array($email, $out, true)) {
            $out[] = $email;
        }
    }
    return $out;
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
    $to = brionic_email_list(get_option('brionic_au_notify_email', ''));
    if (!$to) {
        $to = [(string) get_option('admin_email')];
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
      #login { position:relative; z-index:1; background:rgba(255,255,255,.96); border-radius:16px; padding:26px 30px 30px; box-shadow:0 24px 70px rgba(0,0,0,.45); }
      /* Modern WordPress uses .wp-login-logo (specificity beats a bare h1 a), so match it and force our logo. */
      .login h1, .login h1 a, .login .wp-login-logo a {
          background-image:url("<?php echo $logo; ?>") !important;
          background-size:contain !important; background-position:center !important; background-repeat:no-repeat !important;
          width:100% !important; height:74px !important; margin:0 auto 10px !important;
      }
      .login form { margin-top:16px; background:transparent; border:0; box-shadow:none; padding:0; }
      .login #nav, .login #backtoblog { text-align:center; padding:14px 24px 0; margin:0 auto; }
      .login #backtoblog a, .login #nav a { color:#eef2f8 !important; text-shadow:0 1px 3px rgba(0,0,0,.7); }
      .login #backtoblog a:hover, .login #nav a:hover { color:#fff !important; }
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

// ── "Under construction" / coming-soon mode ─────────────────────────────────
// When enabled, visitors see a branded holding page (HTTP 503) while you build.
// Logged-in users who can edit the site still browse it normally so you can work.
add_action('template_redirect', function () {
    $preview = isset($_GET['brionic_uc_preview']) && current_user_can('manage_options');
    if (!$preview) {
        if (get_option('brionic_uc_enabled', '0') !== '1') {
            return;
        }
        if (is_user_logged_in() && current_user_can('edit_posts')) {
            return; // let editors/admins see the real site
        }
        if (is_admin()
            || (defined('DOING_AJAX') && DOING_AJAX)
            || (defined('DOING_CRON') && DOING_CRON)
            || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        if ((isset($GLOBALS['pagenow']) ? $GLOBALS['pagenow'] : '') === 'wp-login.php') {
            return; // never block the login screen
        }
    }

    $logo    = brionic_login_asset_url('brionic_uc_logo_url', 'login-logo.png');
    $bg      = brionic_login_asset_url('brionic_uc_bg_url', 'login-bg.jpg');
    $heading = trim((string) get_option('brionic_uc_heading', ''));
    if ($heading === '') { $heading = 'Website Under Construction'; }
    $message = trim((string) get_option('brionic_uc_message', ''));
    if ($message === '') { $message = 'We are making improvements and will be back shortly.'; }
    $site    = get_bloginfo('name');

    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 3600');
    status_header(503);
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html($heading . ' - ' . $site); ?></title>
<style>
  *{box-sizing:border-box} html,body{height:100%;margin:0}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#eef2f8;
    background:#0e1118 url("<?php echo esc_url($bg); ?>") center center / cover no-repeat fixed;}
  .overlay{position:fixed;inset:0;background:linear-gradient(180deg,rgba(10,12,18,.55),rgba(10,12,18,.80));}
  .wrap{position:relative;min-height:100%;display:flex;align-items:center;justify-content:center;padding:32px;text-align:center;}
  .card{max-width:560px;width:100%;background:rgba(16,18,26,.55);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);
    border:1px solid rgba(255,255,255,.12);border-radius:20px;padding:44px 36px;box-shadow:0 24px 70px rgba(0,0,0,.5);}
  .logo{width:230px;max-width:72%;height:auto;margin:0 auto 22px;display:block;}
  h1{font-size:1.9rem;line-height:1.25;margin:0 0 12px;font-weight:700;}
  p{font-size:1.05rem;line-height:1.6;color:#cdd6e4;margin:0;}
  .dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#d92b32;margin-right:9px;vertical-align:middle;}
  .foot{margin-top:28px;font-size:.78rem;color:#8b93a4;letter-spacing:.06em;text-transform:uppercase;}
</style></head>
<body><div class="overlay"></div><div class="wrap"><div class="card">
  <img class="logo" src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($site); ?>">
  <h1><span class="dot"></span><?php echo esc_html($heading); ?></h1>
  <p><?php echo esc_html($message); ?></p>
  <div class="foot"><?php echo esc_html($site); ?></div>
</div></div></body></html>
    <?php
    exit;
});

// ── Brionic SEO ─────────────────────────────────────────────────────────────
// A self-contained, zero-config SEO engine: titles, meta description, canonical,
// robots, Open Graph, Twitter cards and JSON-LD schema. Deterministic (no AI, no
// external calls). On by default — it optimises everything automatically. As a
// safety net it stays dormant while another SEO plugin is active, so it never
// double-outputs tags; disable the other plugin and it takes over automatically.

/** Returns a label if a competing SEO plugin is active, else '' (empty). */
function brionic_seo_competitor() {
    if (defined('WPSEO_VERSION')
        || defined('RANK_MATH_VERSION') || class_exists('RankMath')
        || defined('AIOSEO_VERSION') || function_exists('aioseo')
        || defined('SEOPRESS_VERSION')
        || defined('THE_SEO_FRAMEWORK_VERSION')) {
        return 'another SEO plugin';
    }
    return '';
}

/** True only when Brionic SEO is enabled AND no competing SEO plugin is active. */
function brionic_seo_active() {
    return get_option('brionic_seo_enabled', '1') === '1' && brionic_seo_competitor() === '';
}

/** Trim to a length on a word boundary with an ellipsis. */
function brionic_seo_trim($s, $len = 160) {
    $s = trim(preg_replace('/\s+/', ' ', (string) $s));
    if (function_exists('mb_strlen') ? mb_strlen($s) <= $len : strlen($s) <= $len) {
        return $s;
    }
    $cut = function_exists('mb_substr') ? mb_substr($s, 0, $len - 1) : substr($s, 0, $len - 1);
    $sp  = function_exists('mb_strrpos') ? mb_strrpos($cut, ' ') : strrpos($cut, ' ');
    if ($sp && $sp > $len * 0.6) {
        $cut = function_exists('mb_substr') ? mb_substr($cut, 0, $sp) : substr($cut, 0, $sp);
    }
    return rtrim($cut) . '…';
}

function brionic_seo_sep() {
    $sep = trim((string) get_option('brionic_seo_sep', ''));
    return ' ' . ($sep !== '' ? $sep : '–') . ' ';
}

/** Compute the document title for the current request. */
function brionic_seo_title() {
    $site = get_bloginfo('name');
    $sep  = brionic_seo_sep();
    if (is_front_page()) {
        $h = trim((string) get_option('brionic_seo_home_title', ''));
        if ($h !== '') {
            return $h;
        }
        $tag = get_bloginfo('description');
        return $tag ? $site . $sep . $tag : $site;
    }
    if (is_singular())                         { $t = get_the_title(get_queried_object_id()); }
    elseif (is_category() || is_tag() || is_tax()) { $t = single_term_title('', false); }
    elseif (is_post_type_archive())            { $t = post_type_archive_title('', false); }
    elseif (is_author())                       { $t = get_the_author_meta('display_name', (int) get_queried_object_id()); }
    elseif (is_search())                       { $t = 'Search results for “' . get_search_query() . '”'; }
    elseif (is_404())                          { $t = 'Page not found'; }
    elseif (is_archive())                      { $t = wp_strip_all_tags(get_the_archive_title()); }
    else                                       { $t = ''; }
    $t = trim(wp_strip_all_tags((string) $t));
    return $t !== '' ? $t . $sep . $site : $site;
}

/** Compute the meta description for the current request. */
function brionic_seo_description() {
    if (is_front_page()) {
        $d = trim((string) get_option('brionic_seo_home_desc', ''));
        if ($d !== '') {
            return $d;
        }
        $tag = trim((string) get_bloginfo('description'));
        if ($tag !== '') {
            return $tag;
        }
        // No tagline set: on a static front page, derive one automatically from
        // the page's own content so the homepage is never left without a
        // description.
        $front = (int) get_option('page_on_front');
        if ($front) {
            $auto = brionic_seo_post_description(get_post($front));
            if ($auto !== '') {
                return $auto;
            }
        }
        return '';
    }
    if (is_singular()) {
        $auto = brionic_seo_post_description(get_queried_object());
        if ($auto !== '') {
            return $auto;
        }
    }
    if (is_category() || is_tag() || is_tax()) {
        $term = get_queried_object();
        if ($term && !empty($term->description)) {
            return brionic_seo_trim(wp_strip_all_tags($term->description));
        }
    }
    return get_bloginfo('description');
}

/** Derive a meta description from a post/page's excerpt or content. */
function brionic_seo_post_description($post) {
    if (!($post instanceof WP_Post)) {
        return '';
    }
    if (has_excerpt($post)) {
        return brionic_seo_trim(wp_strip_all_tags(get_the_excerpt($post)));
    }
    $c = (string) $post->post_content;
    if (function_exists('excerpt_remove_blocks')) {
        $c = excerpt_remove_blocks($c); // drop Gutenberg block delimiters
    }
    $c = wp_strip_all_tags(strip_shortcodes($c)); // drop shortcodes/page-builder markup
    $c = trim(preg_replace('/\s+/', ' ', $c));
    return $c !== '' ? brionic_seo_trim($c) : '';
}

/** Best canonical URL for the current request. */
function brionic_seo_canonical() {
    if (is_front_page())  { return home_url('/'); }
    if (is_singular())    { return get_permalink(get_queried_object_id()); }
    if (is_category() || is_tag() || is_tax()) {
        $l = get_term_link(get_queried_object());
        return is_wp_error($l) ? '' : $l;
    }
    if (is_post_type_archive()) { return get_post_type_archive_link(get_query_var('post_type')) ?: ''; }
    if (is_author())            { return get_author_posts_url((int) get_queried_object_id()); }
    return '';
}

/** Best sharing image URL for the current request. */
function brionic_seo_image() {
    if (is_singular() && has_post_thumbnail(get_queried_object_id())) {
        $u = get_the_post_thumbnail_url(get_queried_object_id(), 'full');
        if ($u) { return $u; }
    }
    $d = trim((string) get_option('brionic_seo_og_image', ''));
    if ($d !== '') { return $d; }
    $icon = function_exists('get_site_icon_url') ? get_site_icon_url(512) : '';
    return $icon ?: '';
}

// Force the theme to use the WordPress <title> tag so our filter applies.
add_action('after_setup_theme', function () {
    if (get_option('brionic_seo_enabled', '1') === '1') {
        add_theme_support('title-tag');
    }
}, 99);

add_filter('pre_get_document_title', function ($title) {
    return brionic_seo_active() ? brionic_seo_title() : $title;
}, 20);

// Take over canonical output (core only prints it on singular views).
add_action('template_redirect', function () {
    if (brionic_seo_active()) {
        remove_action('wp_head', 'rel_canonical');
    }
}, 9);

// Tune the robots meta WordPress already prints (avoids a duplicate tag).
add_filter('wp_robots', function ($robots) {
    if (!brionic_seo_active()) {
        return $robots;
    }
    if (is_search() || is_404()) {
        $robots['noindex'] = true;
        $robots['follow']  = true;
    }
    if (get_option('brionic_seo_noindex_archives', '0') === '1' && (is_author() || is_date())) {
        $robots['noindex'] = true;
    }
    $robots['max-image-preview'] = 'large';
    return $robots;
});

// Emit description, canonical, Open Graph, Twitter cards and JSON-LD.
add_action('wp_head', function () {
    if (!brionic_seo_active()) {
        return;
    }
    $desc      = brionic_seo_description();
    $canonical = brionic_seo_canonical();
    $title     = brionic_seo_title();
    $image     = brionic_seo_image();
    $site      = get_bloginfo('name');

    echo "\n<!-- Brionic SEO -->\n";
    if ($desc)      { echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n"; }
    if ($canonical) { echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n"; }

    if (get_option('brionic_seo_social', '1') === '1') {
        $type = (is_singular() && !is_front_page()) ? 'article' : 'website';
        echo '<meta property="og:locale" content="' . esc_attr(str_replace('-', '_', get_locale())) . '">' . "\n";
        echo '<meta property="og:type" content="' . esc_attr($type) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        if ($desc)      { echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . "\n"; }
        if ($canonical) { echo '<meta property="og:url" content="' . esc_url($canonical) . '">' . "\n"; }
        echo '<meta property="og:site_name" content="' . esc_attr($site) . '">' . "\n";
        if ($image) { echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n"; }
        echo '<meta name="twitter:card" content="' . ($image ? 'summary_large_image' : 'summary') . '">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
        if ($desc)  { echo '<meta name="twitter:description" content="' . esc_attr($desc) . '">' . "\n"; }
        if ($image) { echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n"; }
    }

    if (get_option('brionic_seo_schema', '1') === '1') {
        $graph = brionic_seo_schema_graph();
        if ($graph) {
            echo '<script type="application/ld+json">'
                . wp_json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                . '</script>' . "\n";
        }
    }
    echo "<!-- /Brionic SEO -->\n";
}, 1);

/** Build the schema.org @graph for the current request. */
function brionic_seo_schema_graph() {
    $home    = home_url('/');
    $orgName = trim((string) get_option('brionic_seo_org_name', '')) ?: get_bloginfo('name');
    $orgLogo = trim((string) get_option('brionic_seo_org_logo', ''));
    if ($orgLogo === '' && function_exists('get_site_icon_url')) {
        $orgLogo = get_site_icon_url(512);
    }

    $org = ['@type' => 'Organization', '@id' => $home . '#org', 'name' => $orgName, 'url' => $home];
    if ($orgLogo) {
        $org['logo'] = ['@type' => 'ImageObject', 'url' => $orgLogo];
    }
    $graph = [$org];

    $graph[] = [
        '@type'     => 'WebSite',
        '@id'       => $home . '#website',
        'url'       => $home,
        'name'      => get_bloginfo('name'),
        'publisher' => ['@id' => $home . '#org'],
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => $home . '?s={search_term_string}'],
            'query-input' => 'required name=search_term_string',
        ],
    ];

    if (is_singular('post')) {
        $id = get_queried_object_id();
        $graph[] = [
            '@type'            => 'Article',
            '@id'              => get_permalink($id) . '#article',
            'headline'         => wp_strip_all_tags(get_the_title($id)),
            'datePublished'    => get_the_date('c', $id),
            'dateModified'     => get_the_modified_date('c', $id),
            'author'           => ['@type' => 'Person', 'name' => get_the_author_meta('display_name', (int) get_post_field('post_author', $id))],
            'publisher'        => ['@id' => $home . '#org'],
            'mainEntityOfPage' => get_permalink($id),
        ];
    }

    if (is_singular()) {
        $id     = get_queried_object_id();
        $crumbs = [['name' => get_bloginfo('name'), 'url' => $home]];
        if (is_singular('post')) {
            $cats = get_the_category($id);
            if ($cats) {
                $crumbs[] = ['name' => $cats[0]->name, 'url' => get_category_link($cats[0]->term_id)];
            }
        }
        $crumbs[] = ['name' => wp_strip_all_tags(get_the_title($id)), 'url' => get_permalink($id)];
        $items = [];
        $pos   = 1;
        foreach ($crumbs as $c) {
            $items[] = ['@type' => 'ListItem', 'position' => $pos++, 'name' => $c['name'], 'item' => $c['url']];
        }
        $graph[] = ['@type' => 'BreadcrumbList', '@id' => get_permalink($id) . '#breadcrumb', 'itemListElement' => $items];
    }

    return ['@context' => 'https://schema.org', '@graph' => $graph];
}

// Admin nudge: enabled but a competing SEO plugin is still active.
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    if (get_option('brionic_seo_enabled', '1') !== '1') {
        return;
    }
    $competitor = brionic_seo_competitor();
    if ($competitor === '') {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>Brionic SEO is waiting.</strong> '
        . 'Another SEO plugin is still active, so Brionic SEO is staying dormant to avoid duplicate tags. '
        . 'Disable the other SEO plugin and Brionic SEO takes over automatically.</p></div>';
});

/** Settings page under Settings → Brionic Config. */
add_action('admin_menu', function () {
    add_options_page(
        'Brionic Config',
        'Brionic Config',
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
        update_option('brionic_au_notify_email', implode(', ', brionic_email_list(wp_unslash($_POST['brionic_au_notify_email'] ?? ''))));
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

    // Under-construction settings save.
    if (isset($_POST['brionic_uc_save']) && check_admin_referer('brionic_uc_save')) {
        update_option('brionic_uc_enabled', isset($_POST['brionic_uc_enabled']) ? '1' : '0');
        update_option('brionic_uc_heading', sanitize_text_field(wp_unslash($_POST['brionic_uc_heading'] ?? '')));
        update_option('brionic_uc_message', sanitize_textarea_field(wp_unslash($_POST['brionic_uc_message'] ?? '')));
        update_option('brionic_uc_logo_url', esc_url_raw(wp_unslash($_POST['brionic_uc_logo_url'] ?? '')));
        update_option('brionic_uc_bg_url', esc_url_raw(wp_unslash($_POST['brionic_uc_bg_url'] ?? '')));
        echo '<div class="notice notice-success is-dismissible"><p>Under-construction settings saved.</p></div>';
    }

    // SEO settings save.
    if (isset($_POST['brionic_seo_save']) && check_admin_referer('brionic_seo_save')) {
        update_option('brionic_seo_enabled', isset($_POST['brionic_seo_enabled']) ? '1' : '0');
        update_option('brionic_seo_social', isset($_POST['brionic_seo_social']) ? '1' : '0');
        update_option('brionic_seo_schema', isset($_POST['brionic_seo_schema']) ? '1' : '0');
        update_option('brionic_seo_noindex_archives', isset($_POST['brionic_seo_noindex_archives']) ? '1' : '0');
        update_option('brionic_seo_sep', sanitize_text_field(wp_unslash($_POST['brionic_seo_sep'] ?? '')));
        update_option('brionic_seo_home_title', sanitize_text_field(wp_unslash($_POST['brionic_seo_home_title'] ?? '')));
        update_option('brionic_seo_home_desc', sanitize_textarea_field(wp_unslash($_POST['brionic_seo_home_desc'] ?? '')));
        update_option('brionic_seo_og_image', esc_url_raw(wp_unslash($_POST['brionic_seo_og_image'] ?? '')));
        update_option('brionic_seo_org_name', sanitize_text_field(wp_unslash($_POST['brionic_seo_org_name'] ?? '')));
        update_option('brionic_seo_org_logo', esc_url_raw(wp_unslash($_POST['brionic_seo_org_logo'] ?? '')));
        echo '<div class="notice notice-success is-dismissible"><p>SEO settings saved.</p></div>';
    }
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
    $ucOn        = get_option('brionic_uc_enabled', '0') === '1';
    $ucHeading   = (string) get_option('brionic_uc_heading', '');
    $ucMessage   = (string) get_option('brionic_uc_message', '');
    $ucLogo      = (string) get_option('brionic_uc_logo_url', '');
    $ucBg        = (string) get_option('brionic_uc_bg_url', '');
    $seoOn       = get_option('brionic_seo_enabled', '1') === '1';
    $seoSocial   = get_option('brionic_seo_social', '1') === '1';
    $seoSchema   = get_option('brionic_seo_schema', '1') === '1';
    $seoNoArch   = get_option('brionic_seo_noindex_archives', '0') === '1';
    $seoSep      = (string) get_option('brionic_seo_sep', '');
    $seoHomeT    = (string) get_option('brionic_seo_home_title', '');
    $seoHomeD    = (string) get_option('brionic_seo_home_desc', '');
    $seoOgImg    = (string) get_option('brionic_seo_og_image', '');
    $seoOrgName  = (string) get_option('brionic_seo_org_name', '');
    $seoOrgLogo  = (string) get_option('brionic_seo_org_logo', '');
    $seoRival    = brionic_seo_competitor();

    $tabs = [
        'analytics'    => 'Analytics',
        'seo'          => 'SEO',
        'email'        => 'Email',
        'updates'      => 'Automatic updates',
        'login'        => 'Login page',
        'construction' => 'Under construction',
        'maintenance'  => 'Maintenance',
    ];
    $tab = (isset($_GET['tab']) && isset($tabs[$_GET['tab']])) ? sanitize_key($_GET['tab']) : 'analytics';
    $tabBase = admin_url('options-general.php?page=brionic-analytics');
    ?>
    <div class="wrap">
        <h1>Brionic Config</h1>
        <h2 class="nav-tab-wrapper">
            <?php foreach ($tabs as $tk => $tlabel): ?>
                <a href="<?php echo esc_url($tabBase . '&tab=' . $tk); ?>" class="nav-tab <?php echo $tab === $tk ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($tlabel); ?></a>
            <?php endforeach; ?>
        </h2>

        <?php if ($tab === 'analytics'): ?>
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

        <?php elseif ($tab === 'seo'): ?>
        <h2>SEO</h2>
        <p>Brionic optimises your SEO <strong>automatically</strong> &mdash; page titles, meta descriptions, canonical URLs, Open Graph &amp; Twitter cards, and schema.org structured data are generated from your content. It&rsquo;s on by default and needs no setup; the fields below are optional overrides if you want to fine-tune anything.</p>
        <?php if ($seoOn && $seoRival !== ''): ?>
            <div class="notice notice-warning inline"><p><strong>Another SEO plugin is still active.</strong> Brionic SEO stays dormant while another SEO plugin runs, to avoid duplicate tags. Disable the other plugin and Brionic SEO takes over automatically.</p></div>
        <?php elseif ($seoOn): ?>
            <div class="notice notice-success inline"><p><strong>Brionic SEO is active</strong> and optimising your site&rsquo;s search-engine tags automatically.</p></div>
        <?php endif; ?>
        <form action="" method="post">
            <?php wp_nonce_field('brionic_seo_save'); ?>
            <input type="hidden" name="brionic_seo_save" value="1">
            <table class="form-table" role="presentation">
                <tr><th scope="row">Enable</th><td>
                    <label><input type="checkbox" name="brionic_seo_enabled" <?php checked($seoOn); ?>> Optimise this site&rsquo;s SEO automatically with Brionic</label>
                    <p class="description">On by default. If another SEO plugin is active, disable it so Brionic SEO can take over.</p>
                </td></tr>
                <tr><th scope="row">Output</th><td>
                    <label><input type="checkbox" name="brionic_seo_social" <?php checked($seoSocial); ?>> Open Graph &amp; Twitter card tags (social previews)</label><br>
                    <label><input type="checkbox" name="brionic_seo_schema" <?php checked($seoSchema); ?>> schema.org structured data (JSON-LD)</label><br>
                    <label><input type="checkbox" name="brionic_seo_noindex_archives" <?php checked($seoNoArch); ?>> Keep author &amp; date archives out of search results (noindex)</label>
                </td></tr>
                <tr><th scope="row"><label for="brionic_seo_sep">Title separator</label></th><td>
                    <input type="text" class="small-text" id="brionic_seo_sep" name="brionic_seo_sep" value="<?php echo esc_attr($seoSep); ?>" placeholder="–" maxlength="3">
                    <p class="description">Sits between the page title and site name, e.g. <code>Page&nbsp;–&nbsp;Site</code>.</p>
                </td></tr>
                <tr><th scope="row"><label for="brionic_seo_home_title">Home title</label></th><td>
                    <input type="text" class="regular-text" id="brionic_seo_home_title" name="brionic_seo_home_title" value="<?php echo esc_attr($seoHomeT); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                    <p class="description">The title tag for your front page. Blank = site name + tagline.</p>
                </td></tr>
                <tr><th scope="row"><label for="brionic_seo_home_desc">Home description</label></th><td>
                    <textarea class="large-text" rows="2" id="brionic_seo_home_desc" name="brionic_seo_home_desc" placeholder="<?php echo esc_attr(get_bloginfo('description')); ?>"><?php echo esc_textarea($seoHomeD); ?></textarea>
                    <p class="description">Meta description for the front page. Other pages use their excerpt or content automatically.</p>
                </td></tr>
                <tr><th scope="row"><label for="brionic_seo_og_image">Default share image</label></th><td>
                    <input type="url" class="regular-text" id="brionic_seo_og_image" name="brionic_seo_og_image" value="<?php echo esc_attr($seoOgImg); ?>" placeholder="Optional — falls back to the site icon">
                    <p class="description">Shown in social previews when a page has no featured image.</p>
                </td></tr>
                <tr><th scope="row"><label for="brionic_seo_org_name">Organisation name</label></th><td>
                    <input type="text" class="regular-text" id="brionic_seo_org_name" name="brionic_seo_org_name" value="<?php echo esc_attr($seoOrgName); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                    <p class="description">Used in structured data. Blank = site name.</p>
                </td></tr>
                <tr><th scope="row"><label for="brionic_seo_org_logo">Organisation logo</label></th><td>
                    <input type="url" class="regular-text" id="brionic_seo_org_logo" name="brionic_seo_org_logo" value="<?php echo esc_attr($seoOrgLogo); ?>" placeholder="Optional — falls back to the site icon">
                    <p class="description">Logo URL for structured data (helps search engines show your brand).</p>
                </td></tr>
            </table>
            <?php submit_button('Save SEO settings'); ?>
        </form>

        <?php elseif ($tab === 'email'): ?>
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

        <?php elseif ($tab === 'updates'): ?>
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
                <tr><th scope="row"><label for="brionic_au_notify_email">Notify address(es)</label></th><td>
                    <input type="text" class="regular-text" id="brionic_au_notify_email" name="brionic_au_notify_email" value="<?php echo esc_attr($auEmail); ?>" placeholder="<?php echo esc_attr($adminEmail); ?>">
                    <p class="description">Where update summaries go (sent using the email settings above). You can enter <strong>multiple addresses separated by commas</strong>. Uses the admin email if blank.</p>
                </td></tr>
            </table>
            <?php submit_button('Save update settings'); ?>
        </form>

        <?php elseif ($tab === 'login'): ?>
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

        <?php elseif ($tab === 'construction'): ?>
        <h2>Under construction</h2>
        <p>Show visitors a branded &ldquo;Website Under Construction&rdquo; holding page with your Brionic logo while you build or make changes. You and any logged-in editor still see the real site, so you can keep working. Search engines are told the page is temporary (HTTP&nbsp;503).</p>
        <?php if ($ucOn): ?>
            <div class="notice notice-warning inline"><p><strong>Under-construction mode is ON.</strong> Logged-out visitors see the holding page. Turn it off below when you&rsquo;re ready to go live.</p></div>
        <?php endif; ?>
        <form action="" method="post">
            <?php wp_nonce_field('brionic_uc_save'); ?>
            <input type="hidden" name="brionic_uc_save" value="1">
            <table class="form-table" role="presentation">
                <tr><th scope="row">Enable</th><td>
                    <label><input type="checkbox" name="brionic_uc_enabled" <?php checked($ucOn); ?>> Show the &ldquo;Under construction&rdquo; page to visitors</label>
                    <p class="description">You&rsquo;ll still see the normal site while logged in.</p>
                </td></tr>
                <tr><th scope="row"><label for="brionic_uc_heading">Heading</label></th><td>
                    <input type="text" class="regular-text" id="brionic_uc_heading" name="brionic_uc_heading" value="<?php echo esc_attr($ucHeading); ?>" placeholder="Website Under Construction">
                    <p class="description">Leave blank to use &ldquo;Website Under Construction&rdquo;.</p>
                </td></tr>
                <tr><th scope="row"><label for="brionic_uc_message">Message</label></th><td>
                    <textarea class="large-text" rows="2" id="brionic_uc_message" name="brionic_uc_message" placeholder="We are making improvements and will be back shortly."><?php echo esc_textarea($ucMessage); ?></textarea>
                    <p class="description">A short line shown under the heading.</p>
                </td></tr>
                <tr><th scope="row"><label for="brionic_uc_logo_url">Logo URL</label></th><td>
                    <input type="url" class="regular-text" id="brionic_uc_logo_url" name="brionic_uc_logo_url" value="<?php echo esc_attr($ucLogo); ?>" placeholder="Default: built-in Brionic logo">
                    <p class="description">Leave blank to use the built-in Brionic Security logo, or paste a Media Library image URL.</p>
                </td></tr>
                <tr><th scope="row"><label for="brionic_uc_bg_url">Background URL</label></th><td>
                    <input type="url" class="regular-text" id="brionic_uc_bg_url" name="brionic_uc_bg_url" value="<?php echo esc_attr($ucBg); ?>" placeholder="Default: built-in geometric wallpaper">
                    <p class="description">Leave blank to use the built-in wallpaper, or paste a Media Library image URL.</p>
                </td></tr>
                <tr><th scope="row">Preview</th><td>
                    <a href="<?php echo esc_url(home_url('/?brionic_uc_preview=1')); ?>" target="_blank" class="button button-secondary">Open a preview</a>
                    <span class="description" style="margin-left:8px">Opens the holding page in a new tab (visible to you even when the mode is off).</span>
                </td></tr>
            </table>
            <?php submit_button('Save under-construction settings'); ?>
        </form>

        <?php elseif ($tab === 'maintenance'): ?>
        <h2>Maintenance</h2>
        <p>Clear cached pages and compiled PHP if the site is serving stale content or a plugin/theme change isn&rsquo;t showing up. Safe to run anytime &mdash; caches simply rebuild on the next visit.</p>
        <form action="" method="post">
            <?php wp_nonce_field('brionic_cache_flush'); ?>
            <input type="hidden" name="brionic_cache_flush" value="1">
            <button type="submit" class="button button-secondary">Flush all caches</button>
            <span class="description" style="margin-left:8px">Clears the WordPress object cache, PHP OPcache, and any caching plugin found (SiteGround, WP Rocket, LiteSpeed, W3&nbsp;Total&nbsp;Cache).</span>
        </form>
        <?php endif; ?>
    </div>
    <?php
}
