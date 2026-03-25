<?php
namespace E2MD;

defined('ABSPATH') || exit;

/**
 * WP Admin page under Tools → Elementor 2 MD.
 *
 * Provides UI for export (with ZIP download) and cleanup.
 */
class Admin_Page {

    const SLUG = 'elementor-2-md';
    const NONCE = 'e2md_nonce';

    public static function register(): void {
        add_management_page(
            'Elementor 2 MD',
            'Elementor 2 MD',
            'manage_options',
            self::SLUG,
            [self::class, 'render']
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/elementor-2-md';
        $zip_path = $export_dir . '.zip';
        $has_zip = file_exists($zip_path);

        // Count Elementor items
        $page_count = self::count_elementor_posts(['page', 'post']);
        $template_count = self::count_elementor_posts(['elementor_library']);
        $kit_id = get_option('elementor_active_kit');
        $has_kit = !empty($kit_id);

        ?>
        <div class="wrap">
            <h1>Elementor 2 MD</h1>
            <p>Export all Elementor content to Markdown + JSON for AI consumption.</p>

            <div id="e2md-messages"></div>

            <!-- Export Section -->
            <div class="card" style="max-width: 600px; margin-bottom: 20px;">
                <h2>Export</h2>
                <table class="form-table">
                    <tr>
                        <th><label><input type="checkbox" id="e2md-scope-pages" value="1" /> Pages / Posts</label></th>
                        <td><strong><?php echo esc_html($page_count); ?></strong> items with Elementor data</td>
                    </tr>
                    <tr>
                        <th><label><input type="checkbox" id="e2md-scope-templates" value="1" /> Templates</label></th>
                        <td><strong><?php echo esc_html($template_count); ?></strong> Elementor library items</td>
                    </tr>
                    <tr>
                        <th>Kit / Design System</th>
                        <td><?php echo $has_kit ? '<span style="color:green">Active kit found (ID: ' . esc_html($kit_id) . ')</span>' : '<span style="color:gray">No active kit</span>'; ?></td>
                    </tr>
                </table>

                <p>
                    <button type="button" id="e2md-export-btn" class="button button-primary" disabled>Export Content</button>
                    <span id="e2md-export-status" style="margin-left: 10px;"></span>
                </p>

                <?php if ($has_zip): ?>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=e2md_download&_wpnonce=' . wp_create_nonce(self::NONCE))); ?>" class="button button-secondary">Download ZIP</a>
                    <span class="description">Last export: <?php echo esc_html(date('Y-m-d H:i:s', filemtime($zip_path))); ?></span>
                </p>
                <?php endif; ?>
            </div>

            <!-- Danger Zone -->
            <div class="card" style="max-width: 600px; border-left: 4px solid #dc3232;">
                <h2 style="color: #dc3232;">Danger Zone</h2>
                <p>Remove all Elementor content from this site. This will:</p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li>Clear Elementor data from all pages and posts</li>
                    <li>Delete all Elementor templates (header, footer, singles, archives, etc.)</li>
                    <li>Remove Elementor global settings and CSS cache</li>
                </ul>
                <p><strong>This action cannot be undone. Export first!</strong></p>
                <p>
                    <label>Type <code>CLEAN</code> to confirm:
                        <input type="text" id="e2md-clean-confirm" style="width: 100px; margin: 0 10px;" />
                    </label>
                    <button type="button" id="e2md-clean-btn" class="button" style="color: #dc3232; border-color: #dc3232;" disabled>Clean All Elementor Data</button>
                </p>
                <div id="e2md-clean-status"></div>
            </div>
        </div>

        <script>
        (function($) {
            var nonce = '<?php echo wp_create_nonce(self::NONCE); ?>';

            // Checkbox toggle → enable/disable Export button
            function updateExportBtn() {
                var any = $('#e2md-scope-pages').is(':checked') || $('#e2md-scope-templates').is(':checked');
                $('#e2md-export-btn').prop('disabled', !any);
            }
            $('#e2md-scope-pages, #e2md-scope-templates').on('change', updateExportBtn);

            // Export
            $('#e2md-export-btn').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Exporting...');
                $('#e2md-export-status').text('');

                $.post(ajaxurl, {
                    action: 'e2md_export',
                    _wpnonce: nonce,
                    scope_pages: $('#e2md-scope-pages').is(':checked') ? 1 : 0,
                    scope_templates: $('#e2md-scope-templates').is(':checked') ? 1 : 0
                }, function(res) {
                    $btn.prop('disabled', false).text('Export Content');
                    updateExportBtn();
                    if (res.success) {
                        var d = res.data;
                        $('#e2md-export-status').html(
                            '<span style="color:green">&#10003; Exported: ' +
                            d.pages + ' pages, ' + d.templates + ' templates' +
                            (d.kit ? ', kit' : '') + '</span>'
                        );
                        // Auto download ZIP
                        window.location.href = ajaxurl + '?action=e2md_download&_wpnonce=' + nonce;
                    } else {
                        $('#e2md-messages').html('<div class="notice notice-error"><p>' + res.data + '</p></div>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Export Content');
                    updateExportBtn();
                    $('#e2md-messages').html('<div class="notice notice-error"><p>Export failed. Check server logs.</p></div>');
                });
            });

            // Clean confirmation
            $('#e2md-clean-confirm').on('input', function() {
                $('#e2md-clean-btn').prop('disabled', $(this).val() !== 'CLEAN');
            });

            $('#e2md-clean-btn').on('click', function() {
                if ($('#e2md-clean-confirm').val() !== 'CLEAN') return;

                var $btn = $(this);
                $btn.prop('disabled', true).text('Cleaning...');

                $.post(ajaxurl, {
                    action: 'e2md_clean',
                    _wpnonce: nonce,
                    confirm: 'CLEAN'
                }, function(res) {
                    $btn.prop('disabled', true).text('Clean All Elementor Data');
                    $('#e2md-clean-confirm').val('');
                    if (res.success) {
                        var d = res.data;
                        $('#e2md-clean-status').html(
                            '<div class="notice notice-success inline"><p>' +
                            'Cleaned: ' + d.pages_cleaned + ' pages, ' +
                            'deleted ' + d.templates_deleted + ' templates, ' +
                            d.options_deleted + ' options removed.' +
                            '</p></div>'
                        );
                    } else {
                        $('#e2md-clean-status').html('<div class="notice notice-error inline"><p>' + res.data + '</p></div>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Clean All Elementor Data');
                    $('#e2md-clean-status').html('<div class="notice notice-error inline"><p>Clean failed.</p></div>');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * AJAX: Run export.
     */
    public static function handle_export(): void {
        check_ajax_referer(self::NONCE, '_wpnonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        require_once E2MD_PLUGIN_DIR . 'includes/class-exporter.php';
        require_once E2MD_PLUGIN_DIR . 'includes/class-kit-exporter.php';

        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/elementor-2-md';

        // Clean previous export
        if (is_dir($export_dir)) {
            self::rmdir_recursive($export_dir);
        }
        wp_mkdir_p($export_dir);

        // Export content
        $scope = [
            'pages'     => !empty($_POST['scope_pages']),
            'templates' => !empty($_POST['scope_templates']),
        ];
        $result = Exporter::export($export_dir, $scope);
        $kit_file = Kit_Exporter::export($export_dir);

        // Build manifest
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
        file_put_contents(
            $export_dir . '/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // Create ZIP
        $zip_path = $export_dir . '.zip';
        if (file_exists($zip_path)) {
            unlink($zip_path);
        }
        self::create_zip($export_dir, $zip_path);

        wp_send_json_success([
            'pages'     => count($result['pages']),
            'templates' => count($result['templates']),
            'kit'       => $kit_file !== null,
        ]);
    }

    /**
     * AJAX: Download ZIP.
     */
    public static function handle_download(): void {
        check_admin_referer(self::NONCE, '_wpnonce');
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $upload_dir = wp_upload_dir();
        $zip_path = $upload_dir['basedir'] . '/elementor-2-md.zip';
        if (!file_exists($zip_path)) {
            wp_die('No export found. Run export first.');
        }

        $site_slug = sanitize_title(get_bloginfo('name')) ?: 'elementor-export';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $site_slug . '-elementor-export.zip"');
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        exit;
    }

    /**
     * AJAX: Clean all Elementor data.
     */
    public static function handle_clean(): void {
        check_ajax_referer(self::NONCE, '_wpnonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        if (($_POST['confirm'] ?? '') !== 'CLEAN') {
            wp_send_json_error('Confirmation required. Type CLEAN.');
        }

        require_once E2MD_PLUGIN_DIR . 'includes/class-cleaner.php';

        $stats = Cleaner::clean();
        wp_send_json_success($stats);
    }

    // --- Helpers ---

    private static function count_elementor_posts(array $post_types): int {
        $posts = get_posts([
            'post_type'      => $post_types,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'     => '_elementor_data',
                'compare' => 'EXISTS',
            ]],
        ]);
        return count($posts);
    }

    private static function create_zip(string $source_dir, string $zip_path): bool {
        if (!class_exists('ZipArchive')) {
            return false;
        }
        $zip = new \ZipArchive();
        if ($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $source_dir = rtrim($source_dir, '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = str_replace($source_dir . '/', '', $item->getPathname());
            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($item->getPathname(), $relative);
            }
        }

        return $zip->close();
    }

    private static function rmdir_recursive(string $dir): void {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
