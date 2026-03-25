<?php
/**
 * Plugin Name: Elementor 2 MD
 * Description: Export all Elementor content (pages, templates, design system) to Markdown + JSON for AI consumption. Includes one-click Elementor data cleanup.
 * Version: 1.0.0
 * Author: FX
 * License: GPL-2.0-or-later
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

defined('ABSPATH') || exit;

define('E2MD_VERSION', '1.0.0');
define('E2MD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('E2MD_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'E2MD\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = E2MD_PLUGIN_DIR . 'includes/class-' . strtolower(str_replace('_', '-', $relative)) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Admin page
add_action('admin_menu', function () {
    require_once E2MD_PLUGIN_DIR . 'includes/class-admin-page.php';
    E2MD\Admin_Page::register();
});

// AJAX handlers
add_action('wp_ajax_e2md_export', function () {
    require_once E2MD_PLUGIN_DIR . 'includes/class-admin-page.php';
    E2MD\Admin_Page::handle_export();
});

add_action('wp_ajax_e2md_download', function () {
    require_once E2MD_PLUGIN_DIR . 'includes/class-admin-page.php';
    E2MD\Admin_Page::handle_download();
});

add_action('wp_ajax_e2md_clean', function () {
    require_once E2MD_PLUGIN_DIR . 'includes/class-admin-page.php';
    E2MD\Admin_Page::handle_clean();
});

// WP-CLI command
if (defined('WP_CLI') && WP_CLI) {
    require_once E2MD_PLUGIN_DIR . 'includes/class-cli-command.php';
    WP_CLI::add_command('e2md', 'E2MD\\CLI_Command');
}
