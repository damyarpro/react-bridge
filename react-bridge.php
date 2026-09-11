<?php
/**
 * Plugin Name: React Bridge
 * Description: Headless bridge: one REST API (rb/v1) with posts, pages, taxonomies, menus, SEO, crawler rendering, sitemap, cache and a signed revalidation webhook for any React or JavaScript frontend.
 * Version:     1.3.0
 * Author:      React Bridge contributors
 * Text Domain: react-bridge
 * Domain Path: /languages
 * Requires PHP: 8.0
 * Requires at least: 6.2
 */

if (!defined('ABSPATH')) exit;

define('RB_VERSION', '1.3.0');
define('RB_FILE', __FILE__);
define('RB_DIR', plugin_dir_path(__FILE__));
define('RB_URL', plugin_dir_url(__FILE__));
define('RB_NS', 'rb/v1');

require RB_DIR . 'includes/class-rb-settings.php';
require RB_DIR . 'includes/class-rb-settings-rest.php';
require RB_DIR . 'includes/class-rb-seo.php';
require RB_DIR . 'includes/class-rb-api.php';
require RB_DIR . 'includes/class-rb-admin.php';

// Translations must be loaded before the first __() call of a request, and no earlier than init.
add_action('init', static function (): void {
    load_plugin_textdomain('react-bridge', false, dirname(plugin_basename(RB_FILE)) . '/languages');
}, 1);

RB_Settings::init();
RB_Settings_Rest::init();
RB_Seo::init();
RB_Api::init();
if (is_admin()) RB_Admin::init();

register_activation_hook(__FILE__, function () {
    RB_Settings::install();
    RB_Settings::migrate();
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
