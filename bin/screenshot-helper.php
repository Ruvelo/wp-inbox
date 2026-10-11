<?php
/**
 * Local dev only, never shipped: bin/dev-site.sh copies it into .wp/ as a
 * must-use plugin for bin/screenshots.sh. ?shot_as=<login> renders a page as
 * that user, ?shot_login=<login>&to=<path> signs in for real (for the block
 * editor), ?shot_scheme=dark|light forces a colour scheme, ?shot_open opens
 * the bell, ?shot_nobar hides the toolbar.
 */
add_filter('determine_current_user', function ($id) {
    if (isset($_GET['shot_as'])) {
        $user = get_user_by('login', sanitize_user(wp_unslash($_GET['shot_as'])));
        return $user ? $user->ID : $id;
    }
    return $id;
}, 99);
add_filter('ruvelo_inbox_settings', function ($s) {
    if (isset($_GET['shot_scheme'])) {
        $s['color_scheme'] = sanitize_key($_GET['shot_scheme']);
    }
    return $s;
});
add_filter('show_admin_bar', function ($show) { return isset($_GET['shot_nobar']) ? false : $show; });
add_action('plugins_loaded', function () {
    if (! isset($_GET['shot_as'])) {
        return;
    }
    $user = get_user_by('login', sanitize_user(wp_unslash($_GET['shot_as'])));
    if ($user) {
        $exp = time() + HOUR_IN_SECONDS;
        $_COOKIE[AUTH_COOKIE] = wp_generate_auth_cookie($user->ID, $exp, 'auth');
        $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($user->ID, $exp, 'logged_in');
    }
}, 0);
$ruvelo_shot_footer = function () {
    if (isset($_GET['shot_open'])) {
        echo '<script>window.addEventListener("load",function(){var b=document.querySelector("[data-ruvelo-inbox-toggle]");if(b){b.click();b.blur();}});</script>';
    }
    if (isset($_GET['shot_scheme']) && $_GET['shot_scheme'] === 'dark' && ! is_admin()) {
        echo '<style>body,.wp-site-blocks{background:#11111c!important;color:#f1f1f8!important}.wp-site-blocks a:not(.ruvelo-inbox *){color:#f1f1f8!important}</style>';
    }
};
add_action('wp_footer', $ruvelo_shot_footer, 99);
add_action('admin_footer', $ruvelo_shot_footer, 99);
add_action('admin_init', function () {
    if (isset($_GET['shot_as'])) {
        remove_action('admin_enqueue_scripts', 'wp_auth_check_load');
    }
});
add_action('wp_default_scripts', function ($scripts) {
    if (isset($_GET['shot_as'])) {
        // No Heartbeat requests while a screenshot renders.
        $scripts->remove('heartbeat');
        $scripts->add('heartbeat', false);
    }
}, 99);
add_action('init', function () {
    if (isset($_GET['shot_login'], $_GET['to'])) {
        $user = get_user_by('login', sanitize_user(wp_unslash($_GET['shot_login'])));
        if ($user) {
            wp_set_auth_cookie($user->ID, false);
            wp_safe_redirect(home_url(wp_unslash($_GET['to'])));
            exit;
        }
    }
}, 1);
add_action('wp_before_admin_bar_render', function () {
    global $wp_admin_bar;
    if (isset($_GET['shot_as'])) {
        // Local-only clutter, and the logo menu that headless Chrome's pointer opens.
        $wp_admin_bar->remove_node('sqlite-db-integration');
        $wp_admin_bar->remove_node('wp-logo');
    }
});
