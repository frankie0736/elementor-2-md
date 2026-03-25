#!/usr/bin/env php
<?php
/**
 * Local test harness for Elementor 2 MD exporter.
 *
 * Reads raw JSON files exported by the plugin and runs the Exporter
 * conversion locally — no WordPress or DB needed.
 *
 * Usage:
 *   php test-local.php <raw-dir> [output-dir]
 *
 * Example:
 *   php test-local.php elementor-export-elementor-export/raw ./test-output
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php test-local.php <raw-dir> [output-dir]\n");
    exit(1);
}

$raw_dir = rtrim($argv[1], '/');
$output_dir = rtrim($argv[2] ?? __DIR__ . '/test-output', '/');

if (!is_dir($raw_dir)) {
    fwrite(STDERR, "Error: raw dir not found: {$raw_dir}\n");
    exit(1);
}

// Stub WordPress functions and constants used by Exporter
define('ABSPATH', __DIR__);

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return true;
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9\-]/', '-', $title);
        $title = preg_replace('/-+/', '-', $title);
        return trim($title, '-');
    }
}

// Load Exporter class
require_once __DIR__ . '/includes/class-exporter.php';

// Prepare output dirs
$pages_out = $output_dir . '/pages';
$templates_out = $output_dir . '/templates';
wp_mkdir_p($pages_out);
wp_mkdir_p($templates_out);

// Process each raw JSON file
$files = glob("{$raw_dir}/*.json");
if (empty($files)) {
    fwrite(STDERR, "No JSON files found in {$raw_dir}\n");
    exit(1);
}

$stats = ['pages' => 0, 'templates' => 0, 'skipped' => 0];

foreach ($files as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (!$data || empty($data['elementor_data'])) {
        $stats['skipped']++;
        continue;
    }

    $template_type = $data['template_type'] ?? 'unknown';
    $elements = $data['elementor_data'];

    // Process through Exporter
    $content = E2MD\Exporter::process_elements($elements);

    // Build a fake post object for build_markdown
    $post = (object) [
        'ID'                => $data['id'],
        'post_title'        => $data['title'],
        'post_type'         => $data['post_type'],
        '_e2md_conditions'  => $data['conditions'] ?? [],
        '_e2md_location'    => $data['location'] ?? '',
    ];

    // Use reflection to call private build_markdown
    $ref = new ReflectionMethod('E2MD\Exporter', 'build_markdown');
    $ref->setAccessible(true);
    $md = $ref->invoke(null, $post, $template_type, $data['page_settings'], $content);

    // Determine output
    $theme_types = E2MD\Exporter::THEME_BUILDER_TYPES;
    $is_template = in_array($template_type, $theme_types, true);
    $dir = $is_template ? $templates_out : $pages_out;

    $slug = sanitize_title($data['title']) ?: 'untitled';
    $out_file = sprintf('%s/%s-%d.md', $dir, $slug, $data['id']);
    file_put_contents($out_file, $md);

    if ($is_template) {
        $stats['templates']++;
    } else {
        $stats['pages']++;
    }

    $basename = basename($file);
    $out_basename = basename($out_file);
    echo "  {$basename} -> {$out_basename}\n";
}

echo "\nDone. Pages: {$stats['pages']}, Templates: {$stats['templates']}, Skipped: {$stats['skipped']}\n";
echo "Output: {$output_dir}\n";
