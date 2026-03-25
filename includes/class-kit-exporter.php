<?php
namespace E2MD;

defined('ABSPATH') || exit;

/**
 * Exports Elementor Kit (design system) as structured JSON.
 *
 * Reads the active Kit's _elementor_page_settings and extracts:
 * - Global colors (system + custom)
 * - Global typography (system + custom)
 * - Theme style (body, headings, links, buttons, form fields, images)
 * - Site settings (container width, site identity, custom CSS)
 */
class Kit_Exporter {

    /**
     * Export Kit data as JSON file.
     *
     * @param string $output_dir Directory to write kit.json into.
     * @return string|null Path to kit.json, or null if no kit found.
     */
    public static function export(string $output_dir): ?string {
        $kit_id = get_option('elementor_active_kit');
        if (!$kit_id) {
            return null;
        }

        $settings = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (empty($settings) || !is_array($settings)) {
            return null;
        }

        $data = [
            'colors'      => self::extract_colors($settings),
            'typography'   => self::extract_typography($settings),
            'theme_style'  => self::extract_theme_style($settings),
            'settings'     => self::extract_settings($settings),
        ];

        $file = $output_dir . '/kit.json';
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $file;
    }

    /**
     * Extract global colors.
     */
    private static function extract_colors(array $settings): array {
        $result = ['system' => [], 'custom' => []];

        foreach ($settings['system_colors'] ?? [] as $color) {
            $result['system'][] = [
                'id'    => $color['_id'] ?? '',
                'title' => $color['title'] ?? '',
                'color' => $color['color'] ?? '',
            ];
        }

        foreach ($settings['custom_colors'] ?? [] as $color) {
            $result['custom'][] = [
                'id'    => $color['_id'] ?? '',
                'title' => $color['title'] ?? '',
                'color' => $color['color'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * Extract global typography.
     */
    private static function extract_typography(array $settings): array {
        $result = ['system' => [], 'custom' => []];

        foreach (['system_typography', 'custom_typography'] as $key) {
            $target = $key === 'system_typography' ? 'system' : 'custom';
            foreach ($settings[$key] ?? [] as $typo) {
                $entry = [
                    'id'    => $typo['_id'] ?? '',
                    'title' => $typo['title'] ?? '',
                ];
                // Extract typography_* prefixed fields
                foreach ($typo as $k => $v) {
                    if (strpos($k, 'typography_') === 0 && $v !== '' && $v !== null) {
                        $short_key = substr($k, strlen('typography_'));
                        $entry[$short_key] = $v;
                    }
                }
                $result[$target][] = $entry;
            }
        }

        $result['default_generic_fonts'] = $settings['default_generic_fonts'] ?? 'Sans-serif';

        return $result;
    }

    /**
     * Extract theme style settings (body, headings, links, buttons, form fields, images).
     */
    private static function extract_theme_style(array $settings): array {
        $result = [];

        // Body typography & color
        $result['body'] = self::extract_prefixed($settings, 'body_');
        $result['body']['color'] = $settings['body_color'] ?? '';

        // Paragraph spacing
        if (!empty($settings['paragraph_spacing'])) {
            $result['body']['paragraph_spacing'] = $settings['paragraph_spacing'];
        }

        // Headings h1-h6
        $result['headings'] = [];
        for ($i = 1; $i <= 6; $i++) {
            $h = self::extract_prefixed($settings, "h{$i}_");
            if (!empty($h)) {
                $result['headings']["h{$i}"] = $h;
            }
        }

        // Links
        $result['links'] = [
            'normal' => self::extract_prefixed($settings, 'link_normal_'),
            'hover'  => self::extract_prefixed($settings, 'link_hover_'),
        ];

        // Buttons
        $button = self::extract_prefixed($settings, 'button_');
        $button_hover = [];
        // Separate hover from normal
        foreach ($button as $k => $v) {
            if (strpos($k, 'hover_') === 0) {
                $button_hover[substr($k, 6)] = $v;
                unset($button[$k]);
            }
        }
        $result['buttons'] = ['normal' => $button, 'hover' => $button_hover];

        // Form fields
        $form = self::extract_prefixed($settings, 'form_');
        $result['form_fields'] = $form;

        // Images
        $result['images'] = self::extract_prefixed($settings, 'image_');

        // Remove empty sections
        $result = array_filter($result, function ($v) {
            if (is_array($v)) {
                return !empty(array_filter($v, function ($inner) {
                    return !empty($inner);
                }));
            }
            return !empty($v);
        });

        return $result;
    }

    /**
     * Extract site-level settings.
     */
    private static function extract_settings(array $settings): array {
        $result = [];

        if (!empty($settings['container_width']['size'])) {
            $unit = $settings['container_width']['unit'] ?? 'px';
            $result['container_width'] = $settings['container_width']['size'] . $unit;
        } elseif (!empty($settings['container_width'])) {
            $result['container_width'] = $settings['container_width'];
        }

        if (!empty($settings['site_name'])) $result['site_name'] = $settings['site_name'];
        if (!empty($settings['site_description'])) $result['site_description'] = $settings['site_description'];

        if (!empty($settings['site_logo']['url'])) {
            $result['site_logo'] = Exporter::url_to_filename($settings['site_logo']['url']);
        }
        if (!empty($settings['site_favicon']['url'])) {
            $result['site_favicon'] = Exporter::url_to_filename($settings['site_favicon']['url']);
        }

        if (!empty($settings['custom_css'])) {
            $result['custom_css'] = $settings['custom_css'];
        }

        // Page background
        if (!empty($settings['page_background_color'])) {
            $result['page_background_color'] = $settings['page_background_color'];
        }

        return $result;
    }

    /**
     * Extract all settings with a given prefix, stripping the prefix from keys.
     * Skips empty values and responsive variants.
     */
    private static function extract_prefixed(array $settings, string $prefix): array {
        $result = [];
        $len = strlen($prefix);
        foreach ($settings as $key => $value) {
            if (strpos($key, $prefix) !== 0) continue;
            if ($value === '' || $value === null || $value === []) continue;
            // Skip responsive variants
            if (preg_match('/_(tablet|mobile)$/', $key)) continue;

            $short = substr($key, $len);
            // Flatten typography sub-fields
            if (strpos($short, 'typography_') === 0) {
                $short = substr($short, strlen('typography_'));
            }
            $result[$short] = $value;
        }
        return $result;
    }
}
