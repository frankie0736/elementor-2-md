<?php
namespace E2MD;

defined('ABSPATH') || exit;

/**
 * WP-CLI commands for Elementor 2 MD.
 *
 * Usage:
 *   wp e2md export [--output=<dir>]
 *   wp e2md clean [--yes]
 */
class CLI_Command extends \WP_CLI_Command {

    /**
     * Export all Elementor content to Markdown + JSON.
     *
     * ## OPTIONS
     *
     * [--output=<dir>]
     * : Output directory. Defaults to ./elementor-export/
     *
     * ## EXAMPLES
     *
     *     wp e2md export
     *     wp e2md export --output=/tmp/export
     *
     * @when after_wp_load
     */
    public function export($args, $assoc_args) {
        require_once E2MD_PLUGIN_DIR . 'includes/class-exporter.php';
        require_once E2MD_PLUGIN_DIR . 'includes/class-kit-exporter.php';

        $output_dir = $assoc_args['output'] ?? (getcwd() . '/elementor-export');
        $output_dir = rtrim($output_dir, '/');

        if (!is_dir($output_dir)) {
            wp_mkdir_p($output_dir);
        }

        \WP_CLI::log("Exporting to: {$output_dir}");

        // Export content
        $result = Exporter::export($output_dir);
        \WP_CLI::success(sprintf('Exported %d pages.', count($result['pages'])));
        \WP_CLI::success(sprintf('Exported %d templates.', count($result['templates'])));

        // Export kit
        $kit_file = Kit_Exporter::export($output_dir);
        if ($kit_file) {
            \WP_CLI::success("Kit exported: {$kit_file}");
        } else {
            \WP_CLI::warning('No active kit found.');
        }

        // Manifest
        $manifest = [
            'site_url'          => home_url(),
            'site_name'         => get_bloginfo('name'),
            'exported_at'       => gmdate('c'),
            'elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : 'unknown',
            'stats'             => [
                'pages'     => count($result['pages']),
                'templates' => count($result['templates']),
                'kit'       => $kit_file !== null,
            ],
        ];
        $manifest_path = $output_dir . '/manifest.json';
        file_put_contents(
            $manifest_path,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        \WP_CLI::success("Manifest: {$manifest_path}");

        \WP_CLI::success("Export complete. Output: {$output_dir}");
    }

    /**
     * Remove all Elementor content from this site.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp e2md clean
     *     wp e2md clean --yes
     *
     * @when after_wp_load
     */
    public function clean($args, $assoc_args) {
        require_once E2MD_PLUGIN_DIR . 'includes/class-cleaner.php';

        if (empty($assoc_args['yes'])) {
            \WP_CLI::confirm('This will DELETE all Elementor content, templates, and settings. Continue?');
        }

        \WP_CLI::log('Cleaning Elementor data...');
        $stats = Cleaner::clean();

        \WP_CLI::success(sprintf(
            'Done. Cleaned %d pages, deleted %d templates, removed %d options.',
            $stats['pages_cleaned'],
            $stats['templates_deleted'],
            $stats['options_deleted']
        ));
    }
}
