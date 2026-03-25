<?php
namespace E2MD;

defined('ABSPATH') || exit;

/**
 * One-click Elementor data cleanup.
 *
 * Removes all Elementor content, templates, and cached assets.
 * Based on ELEMENTOR-CONTENT-EXTRACT-SPEC.md §12.3.
 */
class Cleaner {

    /**
     * Run full cleanup. Returns stats array.
     *
     * @return array{pages_cleaned: int, templates_deleted: int, options_deleted: int}
     */
    public static function clean(): array {
        $stats = [
            'pages_cleaned'    => 0,
            'templates_deleted' => 0,
            'options_deleted'   => 0,
        ];

        // 1. Clean all pages/posts: remove _elementor_* meta and clear post_content
        $posts = get_posts([
            'post_type'      => ['page', 'post'],
            'post_status'    => ['publish', 'draft', 'private', 'pending', 'future'],
            'posts_per_page' => -1,
            'meta_key'       => '_elementor_edit_mode',
            'meta_value'     => 'builder',
        ]);

        foreach ($posts as $post) {
            wp_update_post(['ID' => $post->ID, 'post_content' => '']);
            self::delete_elementor_meta($post->ID);
            $stats['pages_cleaned']++;
        }

        // 2. Delete all elementor_library posts (templates, kit, saved blocks, etc.)
        $templates = get_posts([
            'post_type'      => 'elementor_library',
            'post_status'    => 'any',
            'posts_per_page' => -1,
        ]);

        foreach ($templates as $template) {
            wp_delete_post($template->ID, true); // force delete, bypass trash
            $stats['templates_deleted']++;
        }

        // 3. Clean Elementor global options
        global $wpdb;
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'elementor%' OR option_name LIKE '_elementor%'"
        );
        $stats['options_deleted'] = (int) $deleted;

        // 4. Clean Elementor uploads cache
        $upload_dir = wp_upload_dir();
        $elementor_css_dir = $upload_dir['basedir'] . '/elementor/css';
        if (is_dir($elementor_css_dir)) {
            self::rmdir_recursive($elementor_css_dir);
        }

        return $stats;
    }

    /**
     * Delete all _elementor_* postmeta for a given post.
     */
    private static function delete_elementor_meta(int $post_id): void {
        $meta = get_post_meta($post_id);
        foreach (array_keys($meta) as $key) {
            if (strpos($key, '_elementor_') === 0 || strpos($key, 'elementor_') === 0) {
                delete_post_meta($post_id, $key);
            }
        }
    }

    /**
     * Recursively remove a directory.
     */
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
