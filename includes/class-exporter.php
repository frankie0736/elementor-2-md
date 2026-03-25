<?php
namespace E2MD;

defined('ABSPATH') || exit;

/**
 * Core exporter: extracts Elementor content from DB and outputs Hybrid Markdown.
 *
 * Based on ELEMENTOR-CONTENT-EXTRACT-SPEC.md Layer 1b rules.
 * Reads _elementor_data from postmeta and converts each post to a .md file.
 */
class Exporter {

    /** Template types that belong to Theme Builder (structural templates only) */
    const THEME_BUILDER_TYPES = [
        'header', 'footer', 'single', 'single-post', 'single-page',
        'archive', 'search-results', 'error-404', 'loop-item',
    ];

    /**
     * Run full export. Returns array of exported file paths.
     *
     * @param string $output_dir Directory to write files into.
     * @param array  $scope      What to export: ['pages' => bool, 'templates' => bool]
     * @return array{pages: string[], templates: string[], manifest: string}
     */
    public static function export(string $output_dir, array $scope = []): array {
        $export_pages = $scope['pages'] ?? true;
        $export_templates = $scope['templates'] ?? true;

        $pages_dir = $output_dir . '/pages';
        $templates_dir = $output_dir . '/templates';
        $raw_dir = $output_dir . '/raw';
        if ($export_pages) wp_mkdir_p($pages_dir);
        if ($export_templates) wp_mkdir_p($templates_dir);
        wp_mkdir_p($raw_dir);

        $posts = get_posts([
            'post_type'      => ['page', 'post', 'elementor_library'],
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => -1,
            'meta_query'     => [[
                'key'     => '_elementor_data',
                'compare' => 'EXISTS',
            ]],
        ]);

        $result = ['pages' => [], 'templates' => []];

        foreach ($posts as $post) {
            $raw = get_post_meta($post->ID, '_elementor_data', true);
            if (empty($raw)) {
                continue;
            }

            $elements = is_string($raw) ? json_decode($raw, true) : $raw;
            if (!is_array($elements)) {
                continue;
            }

            $template_type = get_post_meta($post->ID, '_elementor_template_type', true) ?: 'unknown';

            // Skip kit — handled by Kit_Exporter
            if ($template_type === 'kit') {
                continue;
            }

            // Skip saved templates (section, page, container) — only export theme builder templates
            if ($post->post_type === 'elementor_library'
                && !in_array($template_type, self::THEME_BUILDER_TYPES, true)) {
                continue;
            }

            $page_settings = get_post_meta($post->ID, '_elementor_page_settings', true);
            $post->_e2md_conditions = get_post_meta($post->ID, '_elementor_conditions', true) ?: [];
            $post->_e2md_location = get_post_meta($post->ID, '_elementor_location', true) ?: '';

            // Dump raw data for local development loop
            $slug = sanitize_title($post->post_title) ?: 'untitled';
            $raw_file = sprintf('%s/%s-%d.json', $raw_dir, $slug, $post->ID);
            file_put_contents($raw_file, json_encode([
                'id'             => $post->ID,
                'title'          => $post->post_title,
                'post_type'      => $post->post_type,
                'post_status'    => $post->post_status,
                'template_type'  => $template_type,
                'page_settings'  => $page_settings ?: null,
                'conditions'     => $post->_e2md_conditions ?: null,
                'location'       => $post->_e2md_location ?: null,
                'elementor_data' => $elements,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // Determine output directory
            $is_template = in_array($template_type, self::THEME_BUILDER_TYPES, true);

            // Skip based on scope
            if ($is_template && !$export_templates) continue;
            if (!$is_template && !$export_pages) continue;

            $content = self::process_elements($elements);

            // Build Hybrid Markdown
            $md = self::build_markdown($post, $template_type, $page_settings, $content);

            // Determine output directory
            $is_template = in_array($template_type, self::THEME_BUILDER_TYPES, true);

            // Skip based on scope
            if ($is_template && !$export_templates) continue;
            if (!$is_template && !$export_pages) continue;

            $dir = $is_template ? $templates_dir : $pages_dir;

            $slug = sanitize_title($post->post_title) ?: 'untitled';
            $filename = sprintf('%s/%s-%d.md', $dir, $slug, $post->ID);
            file_put_contents($filename, $md);

            if ($is_template) {
                $result['templates'][] = $filename;
            } else {
                $result['pages'][] = $filename;
            }
        }

        return $result;
    }

    /**
     * Build Hybrid Markdown for a single post.
     */
    private static function build_markdown($post, string $template_type, $page_settings, array $content): string {
        $lines = [];

        // Content
        $lines[] = self::content_to_markdown($content, 0);

        return implode("\n", $lines) . "\n";
    }

    // =========================================================================
    // Element tree processing
    // =========================================================================

    public static function process_elements(array $elements): array {
        $result = [];
        foreach ($elements as $el) {
            $processed = self::process_element($el);
            if ($processed !== null) {
                $result[] = $processed;
            }
        }
        return $result;
    }

    private static function process_element(array $el): ?array {
        $type = $el['elType'] ?? '';
        $settings = $el['settings'] ?? [];
        $children = $el['elements'] ?? [];

        if ($type === 'widget') {
            return self::extract_widget($el['widgetType'] ?? 'unknown', $settings);
        }

        // Container / Section / Column: recurse
        if (in_array($type, ['container', 'section', 'column'], true)) {
            $child_content = self::process_elements($children);
            if (empty($child_content)) {
                return null;
            }
            // Single child container: unwrap
            if (count($child_content) === 1) {
                return $child_content[0];
            }
            return ['_type' => 'container', '_children' => $child_content];
        }

        return null;
    }

    // =========================================================================
    // Markdown output
    // =========================================================================

    private static function content_to_markdown(array $content, int $depth): string {
        $blocks = [];
        foreach ($content as $item) {
            if (isset($item['_type']) && $item['_type'] === 'container') {
                $blocks[] = self::content_to_markdown($item['_children'], $depth + 1);
            } else {
                $blocks[] = self::item_to_markdown($item);
            }
        }
        return implode("\n\n", array_filter($blocks));
    }

    private static function item_to_markdown(array $item): string {
        if (empty($item) || !isset($item['_widget'])) {
            return '';
        }

        $widget = $item['_widget'];
        unset($item['_widget']);

        switch ($widget) {
            case 'heading':
                $tag = $item['tag'] ?? 'h2';
                $level = (int) str_replace('h', '', $tag);
                $level = max(1, min(6, $level));
                $prefix = str_repeat('#', $level);
                $text = $item['text'] ?? '';
                $md = "{$prefix} {$text}";
                if (!empty($item['link'])) {
                    $md = "{$prefix} [{$text}]({$item['link']})";
                }
                return $md;

            case 'text':
                return $item['html'] ?? '';

            case 'image':
                $alt = $item['alt'] ?? '';
                $src = $item['src'] ?? '';
                $md = "![{$alt}]({$src})";
                if (!empty($item['caption'])) {
                    $md .= "\n*{$item['caption']}*";
                }
                if (!empty($item['link'])) {
                    $md = "[![{$alt}]({$src})]({$item['link']})";
                }
                return $md;

            case 'video':
                $type = $item['video_type'] ?? 'youtube';
                $url = $item['url'] ?? '';
                return "[video: {$url}]";

            case 'button':
                $text = $item['text'] ?? 'Button';
                $url = $item['url'] ?? '#';
                $md = "[button: {$text}]({$url})";
                if (!empty($item['icon'])) {
                    $md .= " {$item['icon']}";
                }
                return $md;

            case 'icon':
                $icon = $item['icon'] ?? '';
                if (!empty($item['link'])) {
                    return "[icon: {$icon}]({$item['link']})";
                }
                return "[icon: {$icon}]";

            case 'icon-box':
                $parts = [];
                if (!empty($item['icon'])) $parts[] = "[icon: {$item['icon']}]";
                if (!empty($item['title'])) $parts[] = "**{$item['title']}**";
                if (!empty($item['description'])) $parts[] = $item['description'];
                if (!empty($item['link'])) $parts[] = "[Link]({$item['link']})";
                return implode("\n", $parts);

            case 'image-box':
                $parts = [];
                if (!empty($item['image'])) $parts[] = "![{$item['title']}]({$item['image']})";
                if (!empty($item['title'])) $parts[] = "**{$item['title']}**";
                if (!empty($item['description'])) $parts[] = $item['description'];
                if (!empty($item['link'])) $parts[] = "[Link]({$item['link']})";
                return implode("\n", $parts);

            case 'icon-list':
                $lines = [];
                foreach ($item['items'] ?? [] as $li) {
                    $icon = !empty($li['icon']) ? "{$li['icon']} " : '';
                    $text = $li['text'] ?? '';
                    $line = "- {$icon}{$text}";
                    if (!empty($li['link'])) {
                        $line = "- {$icon}[{$text}]({$li['link']})";
                    }
                    $lines[] = $line;
                }
                return implode("\n", $lines);

            case 'counter':
                $prefix_str = $item['prefix'] ?? '';
                $suffix_str = $item['suffix'] ?? '';
                $value = $item['value'] ?? '0';
                $title = $item['title'] ?? '';
                return "[counter: {$prefix_str}{$value}{$suffix_str}]" . ($title ? " {$title}" : '');

            case 'progress':
                $title = $item['title'] ?? '';
                $percent = $item['percent'] ?? 0;
                return "[progress: {$title} {$percent}%]";

            case 'testimonial':
                $parts = [];
                if (!empty($item['content'])) $parts[] = "> {$item['content']}";
                $attribution = [];
                if (!empty($item['name'])) $attribution[] = "**{$item['name']}**";
                if (!empty($item['job'])) $attribution[] = $item['job'];
                if (!empty($attribution)) $parts[] = '> — ' . implode(', ', $attribution);
                return implode("\n", $parts);

            case 'tabs':
                $lines = [];
                foreach ($item['items'] ?? [] as $tab) {
                    $lines[] = "**{$tab['title']}**";
                    $lines[] = $tab['content'] ?? '';
                }
                return implode("\n\n", $lines);

            case 'accordion':
            case 'toggle':
                $lines = [];
                foreach ($item['items'] ?? [] as $panel) {
                    $lines[] = "**Q: {$panel['title']}**\n\n{$panel['content']}";
                }
                return implode("\n\n", $lines);

            case 'social-icons':
                $platforms = [];
                foreach ($item['items'] ?? [] as $social) {
                    $p = $social['platform'] ?? '';
                    if (!empty($social['url'])) {
                        $platforms[] = "[{$p}]({$social['url']})";
                    } else {
                        $platforms[] = $p;
                    }
                }
                return '[social: ' . implode(', ', $platforms) . ']';

            case 'alert':
                $type = $item['alert_type'] ?? 'info';
                $title = $item['title'] ?? '';
                $desc = $item['description'] ?? '';
                return "> **{$title}** ({$type})\n> {$desc}";

            case 'rating':
                $score = $item['score'] ?? '';
                $scale = $item['scale'] ?? 5;
                $title = $item['title'] ?? '';
                return "[rating: {$score}/{$scale}]" . ($title ? " {$title}" : '');

            case 'map':
                return "[map: {$item['address']}]";

            case 'form':
                return self::form_to_markdown($item);

            case 'slides':
                $lines = [];
                foreach ($item['items'] ?? [] as $slide) {
                    $parts = [];
                    if (!empty($slide['background'])) $parts[] = "![slide]({$slide['background']})";
                    if (!empty($slide['heading'])) $parts[] = "### {$slide['heading']}";
                    if (!empty($slide['description'])) $parts[] = $slide['description'];
                    if (!empty($slide['button_text'])) {
                        $url = $slide['button_url'] ?? '#';
                        $parts[] = "[button: {$slide['button_text']}]({$url})";
                    }
                    $lines[] = implode("\n", $parts);
                }
                return implode("\n\n---\n\n", $lines);

            case 'price-table':
                return self::price_table_to_markdown($item);

            case 'price-list':
                $lines = [];
                foreach ($item['items'] ?? [] as $li) {
                    $parts = [];
                    if (!empty($li['title'])) $parts[] = "**{$li['title']}**";
                    if (!empty($li['price'])) $parts[] = $li['price'];
                    if (!empty($li['description'])) $parts[] = $li['description'];
                    $lines[] = '- ' . implode(' — ', $parts);
                }
                return implode("\n", $lines);

            case 'cta':
                $parts = [];
                if (!empty($item['title'])) $parts[] = "## {$item['title']}";
                if (!empty($item['description'])) $parts[] = $item['description'];
                if (!empty($item['button_text'])) {
                    $url = $item['button_url'] ?? '#';
                    $parts[] = "[button: {$item['button_text']}]({$url})";
                }
                return implode("\n\n", $parts);

            case 'animated-headline':
                $before = $item['before'] ?? '';
                $after = $item['after'] ?? '';
                if (!empty($item['rotating_words'])) {
                    $words = implode(' / ', $item['rotating_words']);
                    return "## {$before} [{$words}] {$after}";
                }
                if (!empty($item['highlighted'])) {
                    return "## {$before} **{$item['highlighted']}** {$after}";
                }
                return "## {$before} {$after}";

            case 'flip-box':
                $parts = [];
                $front = $item['front'] ?? [];
                $back = $item['back'] ?? [];
                $parts[] = '<!-- flip-box front -->';
                if (!empty($front['title'])) $parts[] = "**{$front['title']}**";
                if (!empty($front['description'])) $parts[] = $front['description'];
                $parts[] = '<!-- flip-box back -->';
                if (!empty($back['title'])) $parts[] = "**{$back['title']}**";
                if (!empty($back['description'])) $parts[] = $back['description'];
                if (!empty($back['button_text'])) {
                    $url = $back['button_url'] ?? '#';
                    $parts[] = "[button: {$back['button_text']}]({$url})";
                }
                return implode("\n", $parts);

            case 'posts':
                $source = $item['source'] ?? 'post';
                $count = $item['count'] ?? '';
                $params = "source={$source}";
                if ($count) $params .= ", count={$count}";
                return "[posts: {$params}]";

            case 'carousel':
                $lines = [];
                foreach ($item['items'] ?? [] as $slide) {
                    if (!empty($slide['image'])) {
                        if (!empty($slide['link'])) {
                            $lines[] = "- [![]({$slide['image']})]({$slide['link']})";
                        } else {
                            $lines[] = "- ![]({$slide['image']})";
                        }
                    }
                }
                return "<!-- carousel -->\n" . implode("\n", $lines);

            case 'gallery':
                $lines = [];
                foreach ($item['items'] ?? [] as $img) {
                    $lines[] = "- ![]({$img['image']})";
                }
                return "<!-- gallery -->\n" . implode("\n", $lines);

            case 'share-buttons':
                $platforms = array_column($item['items'] ?? [], 'platform');
                return '[share: ' . implode(', ', $platforms) . ']';

            case 'post-navigation':
                $prev = $item['prev_label'] ?? 'Previous';
                $next = $item['next_label'] ?? 'Next';
                return "[post-nav: prev={$prev}, next={$next}]";

            case 'countdown':
                $type = $item['countdown_type'] ?? 'due_date';
                $date = $item['date'] ?? '';
                return "[countdown: {$type}" . ($date ? ", {$date}" : '') . ']';

            // Dynamic theme widgets
            case 'post-title':
                $tag = $item['tag'] ?? 'h1';
                return "[post-title" . ($tag !== 'h1' ? ": tag={$tag}" : '') . ']';

            case 'post-content':
                return '[post-content]';

            case 'post-excerpt':
                return '[post-excerpt]';

            case 'post-featured-image':
                $fallback = $item['fallback'] ?? '';
                return '[post-featured-image' . ($fallback ? " | fallback: {$fallback}" : '') . ']';

            case 'post-info':
                $types = array_column($item['items'] ?? [], 'type');
                return '[post-info: ' . implode(', ', $types) . ']';

            case 'nav-menu':
                $menu = $item['menu'] ?? '';
                return "[nav-menu: {$menu}]";

            case 'table':
                return self::table_to_markdown($item);

            case 'before-after':
                $parts = [];
                $before_img = $item['before_image'] ?? '';
                $after_img = $item['after_image'] ?? '';
                $before_label = $item['before_label'] ?? 'Before';
                $after_label = $item['after_label'] ?? 'After';
                if ($before_img) $parts[] = "![{$before_label}]({$before_img})";
                if ($after_img) $parts[] = "![{$after_label}]({$after_img})";
                return implode(" → ", $parts);

            default:
                // Unknown widget: only output meaningful content fields
                $content_parts = [];
                foreach ($item as $k => $v) {
                    if ($k === '_widget') continue;
                    if (is_string($v) && $v !== '') {
                        $content_parts[] = $v;
                    }
                }
                if (empty($content_parts)) {
                    return "[{$widget}]";
                }
                // If only one content field, output it directly
                if (count($content_parts) === 1) {
                    return "[{$widget}] " . $content_parts[0];
                }
                return "[{$widget}]\n" . implode("\n", $content_parts);
        }
    }

    private static function form_to_markdown(array $item): string {
        $lines = [];
        $name = $item['name'] ?? 'Form';
        $lines[] = "[form: {$name}]";

        foreach ($item['fields'] ?? [] as $field) {
            $label = $field['label'] ?? 'Field';
            $type = $field['type'] ?? 'text';
            $line = "- {$label}: {$type}";
            if (!empty($field['required'])) $line .= ' *required';
            if (!empty($field['placeholder'])) $line .= " \"{$field['placeholder']}\"";
            if (!empty($field['options'])) {
                $line .= ' [' . implode(', ', $field['options']) . ']';
            }
            $lines[] = $line;
        }

        if (!empty($item['submit_text'])) {
            $lines[] = "-> {$item['submit_text']}";
        }
        if (!empty($item['success_message'])) {
            $lines[] = "=> {$item['success_message']}";
        }

        return implode("\n", $lines);
    }

    private static function price_table_to_markdown(array $item): string {
        $lines = [];
        if (!empty($item['ribbon'])) $lines[] = "<!-- ribbon: {$item['ribbon']} -->";
        if (!empty($item['heading'])) $lines[] = "### {$item['heading']}";
        if (!empty($item['sub_heading'])) $lines[] = "*{$item['sub_heading']}*";

        $currency = $item['currency'] ?? '';
        $price = $item['price'] ?? '';
        $period = $item['period'] ?? '';
        if ($price !== '') {
            $lines[] = "**{$currency}{$price}**{$period}";
        }

        foreach ($item['features'] ?? [] as $feature) {
            $lines[] = "- {$feature}";
        }

        if (!empty($item['button_text'])) {
            $url = $item['button_url'] ?? '#';
            $lines[] = "[button: {$item['button_text']}]({$url})";
        }

        return implode("\n", $lines);
    }

    private static function table_to_markdown(array $item): string {
        $lines = [];
        $header = $item['header'] ?? [];
        $rows = $item['rows'] ?? [];

        if (!empty($header)) {
            $lines[] = '| ' . implode(' | ', $header) . ' |';
            $lines[] = '| ' . implode(' | ', array_fill(0, count($header), '---')) . ' |';
        }

        foreach ($rows as $row) {
            if (is_array($row)) {
                $lines[] = '| ' . implode(' | ', $row) . ' |';
            }
        }

        return implode("\n", $lines);
    }

    // =========================================================================
    // Widget extractors — return normalized arrays with _widget key
    // =========================================================================

    private static function extract_widget(string $type, array $s): ?array {
        $map = [
            'heading'                    => 'extract_heading',
            'text-editor'                => 'extract_text_editor',
            'image'                      => 'extract_image',
            'video'                      => 'extract_video',
            'button'                     => 'extract_button',
            'icon'                       => 'extract_icon',
            'icon-box'                   => 'extract_icon_box',
            'image-box'                  => 'extract_image_box',
            'icon-list'                  => 'extract_icon_list',
            'counter'                    => 'extract_counter',
            'progress-bar'              => 'extract_progress',
            'testimonial'                => 'extract_testimonial',
            'tabs'                       => 'extract_tabs',
            'accordion'                  => 'extract_accordion',
            'toggle'                     => 'extract_toggle',
            'social-icons'               => 'extract_social_icons',
            'alert'                      => 'extract_alert',
            'star-rating'                => 'extract_star_rating',
            'google_maps'                => 'extract_map',
            'form'                       => 'extract_form',
            'slides'                     => 'extract_slides',
            'price-table'                => 'extract_price_table',
            'price-list'                 => 'extract_price_list',
            'call-to-action'             => 'extract_cta',
            'animated-headline'          => 'extract_animated_headline',
            'flip-box'                   => 'extract_flip_box',
            'posts'                      => 'extract_posts',
            'image-carousel'             => 'extract_carousel',
            'media-carousel'             => 'extract_carousel',
            'image-gallery'              => 'extract_gallery',
            'share-buttons'              => 'extract_share_buttons',
            'post-navigation'            => 'extract_post_navigation',
            'countdown'                  => 'extract_countdown',
            'theme-post-title'           => 'extract_theme_post_title',
            'theme-post-content'         => 'extract_theme_post_content',
            'theme-post-excerpt'         => 'extract_theme_post_excerpt',
            'theme-post-featured-image'  => 'extract_theme_featured_image',
            'post-info'                  => 'extract_post_info',
            'nav-menu'                   => 'extract_nav_menu',
            'html'                       => 'extract_html',
            'shortcode'                  => 'extract_shortcode',
            // Third-party: UAE (Ultimate Addons for Elementor)
            'uael-dual-color-heading'    => 'extract_uael_dual_heading',
            'uael-table'                 => 'extract_uael_table',
            'uael-ba-slider'             => 'extract_uael_ba_slider',
            // Third-party: ElementsKit
            'ekit-nav-menu'              => 'extract_ekit_nav_menu',
        ];

        // Pure style / structural widgets — skip
        if (in_array($type, ['divider', 'spacer', 'menu-anchor', 'read-more'], true)) {
            return null;
        }

        if (isset($map[$type])) {
            $result = self::{$map[$type]}($s);
            return $result !== null ? self::clean_html_in_result($result) : null;
        }

        // Unknown widget: extract only content-like fields, strip all style noise
        $content_fields = self::extract_unknown_widget_content($type, $s);
        if (empty($content_fields)) {
            return null;
        }
        $content_fields['_widget'] = $type;
        return self::clean_html_in_result($content_fields);
    }

    // --- Individual extractors ---

    private static function extract_heading(array $s): ?array {
        $title = $s['title'] ?? '';
        $dynamic = $s['__dynamic__']['title'] ?? '';
        if (empty($title) && empty($dynamic)) return null;

        $text = !empty($dynamic) ? '(dynamic: post-title)' : $title;
        $tag = $s['header_size'] ?? 'h2';
        $result = ['_widget' => 'heading', 'text' => $text, 'tag' => $tag];
        if (!empty($s['link']['url'])) {
            $result['link'] = $s['link']['url'];
        }
        return $result;
    }

    private static function extract_text_editor(array $s): ?array {
        $html = $s['editor'] ?? '';
        if (empty($html)) return null;
        $md = self::html_to_markdown($html);
        if (empty(trim($md))) return null;
        return ['_widget' => 'text', 'html' => trim($md)];
    }

    private static function extract_image(array $s): ?array {
        $url = $s['image']['url'] ?? '';
        if (empty($url)) return null;
        $result = ['_widget' => 'image', 'src' => self::url_to_filename($url)];
        if (!empty($s['image']['alt'])) {
            $result['alt'] = $s['image']['alt'];
        }
        if (($s['caption_source'] ?? 'none') !== 'none' && !empty($s['caption'])) {
            $result['caption'] = $s['caption'];
        }
        if (($s['link_to'] ?? 'none') !== 'none' && !empty($s['link']['url'])) {
            $result['link'] = $s['link']['url'];
        }
        return $result;
    }

    private static function extract_video(array $s): ?array {
        $type = $s['video_type'] ?? 'youtube';
        $url = $s[$type . '_url'] ?? ($s['youtube_url'] ?? '');
        if (empty($url)) return null;
        return ['_widget' => 'video', 'video_type' => $type, 'url' => $url];
    }

    private static function extract_button(array $s): ?array {
        $text = $s['text'] ?? '';
        if (empty($text)) return null;
        $result = ['_widget' => 'button', 'text' => $text];
        if (!empty($s['link']['url'])) $result['url'] = $s['link']['url'];
        $icon = $s['selected_icon']['value'] ?? ($s['icon'] ?? '');
        if (!empty($icon)) $result['icon'] = $icon;
        return $result;
    }

    private static function extract_icon(array $s): ?array {
        $icon = $s['selected_icon']['value'] ?? '';
        if (empty($icon)) return null;
        $result = ['_widget' => 'icon', 'icon' => $icon];
        if (!empty($s['link']['url'])) $result['link'] = $s['link']['url'];
        return $result;
    }

    private static function extract_icon_box(array $s): ?array {
        $result = ['_widget' => 'icon-box'];
        $has_content = false;
        $icon = $s['selected_icon']['value'] ?? '';
        if (!empty($icon)) { $result['icon'] = $icon; $has_content = true; }
        if (!empty($s['title_text'])) { $result['title'] = $s['title_text']; $has_content = true; }
        if (!empty($s['description_text'])) { $result['description'] = $s['description_text']; $has_content = true; }
        if (!empty($s['link']['url'])) $result['link'] = $s['link']['url'];
        return $has_content ? $result : null;
    }

    private static function extract_image_box(array $s): ?array {
        $result = ['_widget' => 'image-box'];
        $has_content = false;
        if (!empty($s['image']['url'])) { $result['image'] = self::url_to_filename($s['image']['url']); $has_content = true; }
        if (!empty($s['title_text'])) { $result['title'] = $s['title_text']; $has_content = true; }
        if (!empty($s['description_text'])) { $result['description'] = $s['description_text']; $has_content = true; }
        if (!empty($s['link']['url'])) $result['link'] = $s['link']['url'];
        return $has_content ? $result : null;
    }

    private static function extract_icon_list(array $s): ?array {
        $items = $s['icon_list'] ?? [];
        if (empty($items)) return null;
        $result = [];
        foreach ($items as $item) {
            $entry = [];
            $icon = $item['selected_icon']['value'] ?? '';
            if (!empty($icon)) $entry['icon'] = $icon;
            if (!empty($item['text'])) $entry['text'] = $item['text'];
            if (!empty($item['link']['url'])) $entry['link'] = $item['link']['url'];
            if (!empty($entry)) $result[] = $entry;
        }
        return empty($result) ? null : ['_widget' => 'icon-list', 'items' => $result];
    }

    private static function extract_counter(array $s): ?array {
        $result = ['_widget' => 'counter'];
        if (isset($s['ending_number'])) $result['value'] = $s['ending_number'];
        if (!empty($s['prefix'])) $result['prefix'] = $s['prefix'];
        if (!empty($s['suffix'])) $result['suffix'] = $s['suffix'];
        if (!empty($s['title'])) $result['title'] = $s['title'];
        return isset($result['value']) ? $result : null;
    }

    private static function extract_progress(array $s): ?array {
        $result = ['_widget' => 'progress'];
        if (!empty($s['title'])) $result['title'] = $s['title'];
        if (isset($s['percent'])) $result['percent'] = $s['percent'];
        return isset($result['percent']) ? $result : null;
    }

    private static function extract_testimonial(array $s): ?array {
        $result = ['_widget' => 'testimonial'];
        $has = false;
        if (!empty($s['testimonial_content'])) { $result['content'] = $s['testimonial_content']; $has = true; }
        if (!empty($s['testimonial_name'])) { $result['name'] = $s['testimonial_name']; $has = true; }
        if (!empty($s['testimonial_job'])) $result['job'] = $s['testimonial_job'];
        if (!empty($s['testimonial_image']['url'])) $result['avatar'] = self::url_to_filename($s['testimonial_image']['url']);
        return $has ? $result : null;
    }

    private static function extract_tabs(array $s): ?array {
        $tabs = $s['tabs'] ?? [];
        if (empty($tabs)) return null;
        $items = [];
        foreach ($tabs as $tab) {
            $entry = [];
            if (!empty($tab['tab_title'])) $entry['title'] = $tab['tab_title'];
            if (!empty($tab['tab_content'])) $entry['content'] = self::html_to_markdown($tab['tab_content']);
            if (!empty($entry)) $items[] = $entry;
        }
        return empty($items) ? null : ['_widget' => 'tabs', 'items' => $items];
    }

    private static function extract_accordion(array $s): ?array {
        $tabs = $s['tabs'] ?? [];
        if (empty($tabs)) return null;
        $items = [];
        foreach ($tabs as $tab) {
            $entry = [];
            if (!empty($tab['tab_title'])) $entry['title'] = $tab['tab_title'];
            if (!empty($tab['tab_content'])) $entry['content'] = self::html_to_markdown($tab['tab_content']);
            if (!empty($entry)) $items[] = $entry;
        }
        return empty($items) ? null : ['_widget' => 'accordion', 'items' => $items];
    }

    private static function extract_toggle(array $s): ?array {
        $result = self::extract_accordion($s);
        if ($result) $result['_widget'] = 'toggle';
        return $result;
    }

    private static function extract_social_icons(array $s): ?array {
        $items = $s['social_icon_list'] ?? [];
        if (empty($items)) return null;
        $result = [];
        foreach ($items as $item) {
            $icon = $item['social_icon']['value'] ?? ($item['social'] ?? '');
            $platform = $icon;
            if (preg_match('/fa-(facebook|twitter|instagram|linkedin|youtube|pinterest|tiktok|whatsapp|telegram|github)/', $icon, $m)) {
                $platform = $m[1];
            }
            $entry = ['platform' => $platform];
            if (!empty($item['link']['url'])) $entry['url'] = $item['link']['url'];
            $result[] = $entry;
        }
        return empty($result) ? null : ['_widget' => 'social-icons', 'items' => $result];
    }

    private static function extract_alert(array $s): ?array {
        $has = false;
        $result = ['_widget' => 'alert'];
        if (!empty($s['alert_type'])) $result['alert_type'] = $s['alert_type'];
        if (!empty($s['alert_title'])) { $result['title'] = $s['alert_title']; $has = true; }
        if (!empty($s['alert_description'])) { $result['description'] = $s['alert_description']; $has = true; }
        return $has ? $result : null;
    }

    private static function extract_star_rating(array $s): ?array {
        $result = ['_widget' => 'rating'];
        if (isset($s['rating'])) $result['score'] = $s['rating'];
        if (isset($s['rating_scale'])) $result['scale'] = $s['rating_scale'];
        if (!empty($s['title'])) $result['title'] = $s['title'];
        return isset($result['score']) ? $result : null;
    }

    private static function extract_map(array $s): ?array {
        if (empty($s['address'])) return null;
        return ['_widget' => 'map', 'address' => $s['address']];
    }

    private static function extract_form(array $s): ?array {
        $result = ['_widget' => 'form'];
        if (!empty($s['form_name'])) $result['name'] = $s['form_name'];
        if (!empty($s['button_text'])) $result['submit_text'] = $s['button_text'];
        if (!empty($s['success_message'])) $result['success_message'] = $s['success_message'];

        $fields = $s['form_fields'] ?? [];
        $result['fields'] = [];
        foreach ($fields as $field) {
            $f = ['type' => $field['field_type'] ?? 'text'];
            // Label: prefer field_label, fallback to placeholder, then field_id
            $label = $field['field_label'] ?? '';
            if (empty($label)) $label = $field['placeholder'] ?? '';
            if (empty($label)) $label = $field['custom_id'] ?? 'Field';
            $f['label'] = $label;
            if (!empty($field['required'])) $f['required'] = true;
            if (!empty($field['placeholder'])) $f['placeholder'] = $field['placeholder'];
            if (!empty($field['field_options'])) {
                $f['options'] = array_map('trim', explode("\n", $field['field_options']));
            }
            $result['fields'][] = $f;
        }
        return $result;
    }

    private static function extract_slides(array $s): ?array {
        $slides = $s['slides'] ?? [];
        if (empty($slides)) return null;
        $items = [];
        foreach ($slides as $slide) {
            $entry = [];
            if (!empty($slide['heading'])) $entry['heading'] = $slide['heading'];
            if (!empty($slide['description'])) $entry['description'] = $slide['description'];
            if (!empty($slide['button_text'])) $entry['button_text'] = $slide['button_text'];
            if (!empty($slide['link']['url'])) $entry['button_url'] = $slide['link']['url'];
            if (!empty($slide['background_image']['url'])) $entry['background'] = self::url_to_filename($slide['background_image']['url']);
            if (!empty($entry)) $items[] = $entry;
        }
        return empty($items) ? null : ['_widget' => 'slides', 'items' => $items];
    }

    private static function extract_price_table(array $s): ?array {
        $result = ['_widget' => 'price-table'];
        $has = false;
        if (!empty($s['heading'])) { $result['heading'] = $s['heading']; $has = true; }
        if (!empty($s['sub_heading'])) $result['sub_heading'] = $s['sub_heading'];
        if (!empty($s['price'])) { $result['price'] = $s['price']; $has = true; }
        if (!empty($s['currency_symbol'])) $result['currency'] = $s['currency_symbol'];
        if (!empty($s['period'])) $result['period'] = $s['period'];
        if (!empty($s['ribbon_title'])) $result['ribbon'] = $s['ribbon_title'];
        $features = $s['features_list'] ?? [];
        if (!empty($features)) {
            $result['features'] = [];
            foreach ($features as $f) {
                if (!empty($f['item_text'])) $result['features'][] = $f['item_text'];
            }
        }
        if (!empty($s['button_text'])) $result['button_text'] = $s['button_text'];
        if (!empty($s['link']['url'])) $result['button_url'] = $s['link']['url'];
        return $has ? $result : null;
    }

    private static function extract_price_list(array $s): ?array {
        $items = $s['price_list'] ?? [];
        if (empty($items)) return null;
        $result = [];
        foreach ($items as $item) {
            $entry = [];
            if (!empty($item['title'])) $entry['title'] = $item['title'];
            if (!empty($item['description'])) $entry['description'] = $item['description'];
            if (!empty($item['price'])) $entry['price'] = $item['price'];
            if (!empty($item['image']['url'])) $entry['image'] = self::url_to_filename($item['image']['url']);
            if (!empty($entry)) $result[] = $entry;
        }
        return empty($result) ? null : ['_widget' => 'price-list', 'items' => $result];
    }

    private static function extract_cta(array $s): ?array {
        $result = ['_widget' => 'cta'];
        $has = false;
        if (!empty($s['title'])) { $result['title'] = $s['title']; $has = true; }
        if (!empty($s['description'])) { $result['description'] = $s['description']; $has = true; }
        if (!empty($s['button_text'])) $result['button_text'] = $s['button_text'];
        if (!empty($s['link']['url'])) $result['button_url'] = $s['link']['url'];
        if (!empty($s['bg_image']['url'])) $result['background'] = self::url_to_filename($s['bg_image']['url']);
        return $has ? $result : null;
    }

    private static function extract_animated_headline(array $s): ?array {
        $result = ['_widget' => 'animated-headline'];
        $has = false;
        if (!empty($s['before_text'])) { $result['before'] = $s['before_text']; $has = true; }
        if (!empty($s['rotating_text'])) {
            $result['rotating_words'] = array_map('trim', explode("\n", $s['rotating_text']));
            $has = true;
        }
        if (!empty($s['highlighted_text'])) { $result['highlighted'] = $s['highlighted_text']; $has = true; }
        if (!empty($s['after_text'])) $result['after'] = $s['after_text'];
        return $has ? $result : null;
    }

    private static function extract_flip_box(array $s): ?array {
        $front = [];
        if (!empty($s['selected_icon_a']['value'])) $front['icon'] = $s['selected_icon_a']['value'];
        if (!empty($s['image_a']['url'])) $front['image'] = self::url_to_filename($s['image_a']['url']);
        if (!empty($s['title_text_a'])) $front['title'] = $s['title_text_a'];
        if (!empty($s['description_text_a'])) $front['description'] = $s['description_text_a'];

        $back = [];
        if (!empty($s['title_text_b'])) $back['title'] = $s['title_text_b'];
        if (!empty($s['description_text_b'])) $back['description'] = $s['description_text_b'];
        if (!empty($s['button_text'])) $back['button_text'] = $s['button_text'];
        if (!empty($s['link']['url'])) $back['button_url'] = $s['link']['url'];

        return ['_widget' => 'flip-box', 'front' => $front, 'back' => $back];
    }

    private static function extract_posts(array $s): ?array {
        $result = ['_widget' => 'posts', 'source' => 'post'];
        if (!empty($s['posts_post_type'])) $result['source'] = $s['posts_post_type'];
        $skin = $s['_skin'] ?? 'classic';
        $count_key = $skin . '_posts_per_page';
        if (isset($s[$count_key])) $result['count'] = $s[$count_key];
        return $result;
    }

    private static function extract_carousel(array $s): ?array {
        $slides = $s['carousel'] ?? ($s['slides'] ?? []);
        if (empty($slides)) return null;
        $items = [];
        foreach ($slides as $slide) {
            $entry = [];
            if (!empty($slide['image']['url'])) $entry['image'] = self::url_to_filename($slide['image']['url']);
            if (!empty($slide['link']['url'])) $entry['link'] = $slide['link']['url'];
            if (!empty($entry)) $items[] = $entry;
        }
        return empty($items) ? null : ['_widget' => 'carousel', 'items' => $items];
    }

    private static function extract_gallery(array $s): ?array {
        $gallery = $s['gallery'] ?? [];
        if (empty($gallery)) return null;
        $items = [];
        foreach ($gallery as $img) {
            if (!empty($img['url'])) {
                $items[] = ['image' => self::url_to_filename($img['url'])];
            }
        }
        return empty($items) ? null : ['_widget' => 'gallery', 'items' => $items];
    }

    private static function extract_share_buttons(array $s): ?array {
        $buttons = $s['share_buttons'] ?? [];
        if (empty($buttons)) return null;
        $items = [];
        foreach ($buttons as $btn) {
            $items[] = ['platform' => $btn['button'] ?? 'facebook'];
        }
        return empty($items) ? null : ['_widget' => 'share-buttons', 'items' => $items];
    }

    private static function extract_post_navigation(array $s): ?array {
        $result = ['_widget' => 'post-navigation'];
        if (!empty($s['prev_label'])) $result['prev_label'] = $s['prev_label'];
        if (!empty($s['next_label'])) $result['next_label'] = $s['next_label'];
        return $result;
    }

    private static function extract_countdown(array $s): ?array {
        $result = ['_widget' => 'countdown'];
        $result['countdown_type'] = $s['countdown_type'] ?? 'due_date';
        if ($result['countdown_type'] === 'due_date' && !empty($s['due_date'])) {
            $result['date'] = $s['due_date'];
        }
        return $result;
    }

    private static function extract_theme_post_title(array $s): ?array {
        $result = ['_widget' => 'post-title'];
        $tag = $s['header_size'] ?? 'h1';
        if ($tag !== 'h1') $result['tag'] = $tag;
        return $result;
    }

    private static function extract_theme_post_content(array $s): ?array {
        return ['_widget' => 'post-content'];
    }

    private static function extract_theme_post_excerpt(array $s): ?array {
        return ['_widget' => 'post-excerpt'];
    }

    private static function extract_theme_featured_image(array $s): ?array {
        $result = ['_widget' => 'post-featured-image'];
        if (!empty($s['image']['url'])) {
            $result['fallback'] = self::url_to_filename($s['image']['url']);
        }
        return $result;
    }

    private static function extract_post_info(array $s): ?array {
        $items = $s['icon_list'] ?? [];
        if (empty($items)) return null;
        $result = [];
        foreach ($items as $item) {
            $result[] = ['type' => $item['type'] ?? 'date'];
        }
        return ['_widget' => 'post-info', 'items' => $result];
    }

    private static function extract_nav_menu(array $s): ?array {
        $result = ['_widget' => 'nav-menu'];
        if (!empty($s['menu'])) $result['menu'] = $s['menu'];
        return $result;
    }

    private static function extract_html(array $s): ?array {
        $html = $s['html'] ?? '';
        if (empty($html)) return null;
        // For raw HTML widgets, convert to markdown but preserve code that's truly HTML embed
        $md = self::html_to_markdown($html);
        if (empty(trim($md))) return null;
        return ['_widget' => 'text', 'html' => trim($md)];
    }

    private static function extract_shortcode(array $s): ?array {
        $shortcode = $s['shortcode'] ?? '';
        if (empty($shortcode)) return null;
        return ['_widget' => 'text', 'html' => "`{$shortcode}`"];
    }

    // --- Third-party widget extractors ---

    private static function extract_uael_dual_heading(array $s): ?array {
        $before = trim($s['before_heading_text'] ?? '');
        $second = trim($s['second_heading_text'] ?? '');
        $after = trim($s['after_heading_text'] ?? '');
        $combined = trim("{$before} {$second} {$after}");
        if (empty($combined)) return null;
        $tag = $s['dual_tag_selection'] ?? 'h2';
        return ['_widget' => 'heading', 'text' => $combined, 'tag' => $tag];
    }

    private static function extract_uael_table(array $s): ?array {
        $headings = $s['table_headings'] ?? [];
        $body = $s['table_content'] ?? [];
        if (empty($headings) && empty($body)) return null;

        $result = ['_widget' => 'table'];

        // Extract header cells
        if (!empty($headings)) {
            $header = [];
            foreach ($headings as $h) {
                $header[] = $h['heading_text'] ?? '';
            }
            $result['header'] = $header;
        }

        // Extract body rows — uael-table uses a flat array with row spans
        if (!empty($body)) {
            $rows = [];
            $current_row = [];
            foreach ($body as $cell) {
                // New row marker
                if (($cell['table_content_row'] ?? '') === 'row') {
                    if (!empty($current_row)) {
                        $rows[] = $current_row;
                    }
                    $current_row = [$cell['table_content_text'] ?? ''];
                } else {
                    $current_row[] = $cell['table_content_text'] ?? '';
                }
            }
            if (!empty($current_row)) {
                $rows[] = $current_row;
            }
            $result['rows'] = $rows;
        }

        return $result;
    }

    private static function extract_uael_ba_slider(array $s): ?array {
        $result = ['_widget' => 'before-after'];
        $has = false;
        if (!empty($s['before_text'])) { $result['before_label'] = $s['before_text']; $has = true; }
        if (!empty($s['after_text'])) { $result['after_label'] = $s['after_text']; $has = true; }
        if (!empty($s['before_image']['url'])) { $result['before_image'] = self::url_to_filename($s['before_image']['url']); $has = true; }
        if (!empty($s['after_image']['url'])) { $result['after_image'] = self::url_to_filename($s['after_image']['url']); $has = true; }
        return $has ? $result : null;
    }

    private static function extract_ekit_nav_menu(array $s): ?array {
        $result = ['_widget' => 'nav-menu'];
        if (!empty($s['elementskit_nav_menu'])) {
            $result['menu'] = $s['elementskit_nav_menu'];
        }
        return $result;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Extract content-only fields from unknown widgets.
     * Strips all style/typography/layout noise, keeps only text/content fields.
     */
    private static function extract_unknown_widget_content(string $type, array $s): array {
        // Known content field names across third-party widgets
        $content_keys = [
            'title', 'heading', 'description', 'content', 'text', 'editor',
            'before_heading_text', 'second_heading_text', 'after_heading_text',
            'before_text', 'after_text', 'highlighted_text', 'rotating_text',
            'before_label', 'after_label',
            'tab_title', 'tab_content', 'label', 'placeholder',
            'button_text', 'submit_text', 'success_message',
            'address', 'url', 'link',
            'anchor', 'search_text',
            'form_name',
        ];

        // Patterns that are always noise
        $noise_patterns = '/^(typography_|font_|text_shadow_|background_|border_|box_shadow_|'
            . 'css_filters_|flex_|content_width|width|min_height|margin|padding|gap|'
            . 'html_tag|structure|layout|content_position|reverse_order|'
            . 'motion_fx_|sticky|display_condition_|e_display_conditions|handle_|'
            . 'icon_size|icon_color|icon_spacing|image_size|hover_|normal_|'
            . 'animation_|entrance_|exit_|transform_|'
            . 'color$|_color$|_size$|_weight$|_style$|_family$|_spacing$|_decoration$|'
            . '_tablet$|_mobile$)/';

        $result = [];
        foreach ($s as $key => $value) {
            // Skip internal keys
            if (strpos($key, '_') === 0) continue;
            if ($key === '__globals__' || $key === '__dynamic__') continue;
            // Skip empty
            if (self::is_empty_value($value)) continue;
            // Skip if matches noise pattern
            if (preg_match($noise_patterns, $key)) continue;
            // Only keep known content keys or simple string values that look like content
            if (in_array($key, $content_keys, true)) {
                $result[$key] = is_string($value) ? self::html_to_markdown($value) : $value;
            }
        }

        return $result;
    }

    /**
     * Recursively convert all HTML string values in a widget result to Markdown.
     * Skips keys that are URLs, filenames, or widget type identifiers.
     */
    private static function clean_html_in_result(array $data): array {
        $skip_keys = ['_widget', 'src', 'url', 'link', 'image', 'icon', 'menu',
            'fallback', 'before_image', 'after_image', 'background', 'avatar',
            'platform', 'type', 'tag', 'video_type', 'countdown_type', 'source',
            'anchor', 'button_url'];

        foreach ($data as $key => &$value) {
            if (in_array($key, $skip_keys, true)) {
                continue;
            }
            if (is_string($value) && strpos($value, '<') !== false) {
                $value = self::html_to_markdown($value);
            } elseif (is_array($value)) {
                if (isset($value[0]) && is_array($value[0])) {
                    // List of arrays (items/rows/table cells)
                    foreach ($value as &$item) {
                        if (is_array($item)) {
                            $item = self::clean_html_in_result($item);
                        }
                    }
                    unset($item);
                } elseif (isset($value[0]) && is_string($value[0])) {
                    // List of strings (e.g. table header cells)
                    foreach ($value as &$item) {
                        if (is_string($item) && strpos($item, '<') !== false) {
                            $item = self::html_to_markdown($item);
                        }
                    }
                    unset($item);
                } elseif (!isset($value[0])) {
                    // Associative array
                    $value = self::clean_html_in_result($value);
                }
            }
        }
        unset($value);
        return $data;
    }

    private static function clean_settings(array $settings): array {
        $clean = [];
        foreach ($settings as $key => $value) {
            if (strpos($key, '_') === 0) continue;
            if (preg_match('/^(motion_fx_|sticky|display_condition_|e_display_conditions|handle_)/', $key)) continue;
            if (preg_match('/_(tablet|mobile)$/', $key)) continue;
            if (preg_match('/^(background_|border_|box_shadow_|typography_|text_shadow_|css_filters_|flex_|content_width|width|min_height|margin|padding|gap|html_tag|structure|layout|content_position|reverse_order)/', $key)) continue;
            if ($key === '__globals__') continue;
            if (self::is_empty_value($value)) continue;
            $clean[$key] = $value;
        }
        return $clean;
    }

    private static function is_empty_value($value): bool {
        if ($value === '' || $value === null || $value === []) return true;
        if (is_array($value) && isset($value['url']) && $value['url'] === '' && isset($value['id']) && $value['id'] === '') return true;
        return false;
    }

    public static function url_to_filename(string $url): string {
        // Return full URL for absolute paths (AI consumer needs to know the source)
        if (strpos($url, 'http') === 0) {
            // Strip query/fragment
            return preg_replace('/[?#].*$/', '', $url);
        }
        $clean = preg_replace('/[?#].*$/', '', $url);
        $parts = explode('/', $clean);
        $filename = end($parts);
        return urldecode($filename) ?: $url;
    }

    /**
     * Convert HTML content to Markdown.
     * Handles: p, strong/b, em/i, a, h1-h6, ul/ol/li, br, span, div, style, script.
     */
    private static function html_to_markdown(string $html): string {
        // Strip style and script blocks entirely
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);

        // Strip all attributes first (style, class, data-*, id, etc.)
        // But preserve href on <a> tags
        $html = preg_replace_callback('/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', function ($m) {
            $url = $m[1];
            $text = strip_tags($m[2]);
            return "[{$text}]({$url})";
        }, $html);

        // Headings h1-h6 → Markdown headings
        for ($i = 6; $i >= 1; $i--) {
            $prefix = str_repeat('#', $i);
            $html = preg_replace_callback("/<h{$i}[^>]*>(.*?)<\/h{$i}>/is", function ($m) use ($prefix) {
                $text = strip_tags($m[1]);
                return "\n{$prefix} " . trim($text) . "\n";
            }, $html);
        }

        // Unordered lists — process before inline tag conversion
        $html = preg_replace_callback('/<ul[^>]*>(.*?)<\/ul>/is', function ($m) {
            $lines = [];
            preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $m[1], $items);
            foreach ($items[1] ?? [] as $content) {
                $text = preg_replace('/<(strong|b)>(.*?)<\/\1>/is', '**$2**', $content);
                $text = preg_replace('/<(em|i)>(.*?)<\/\1>/is', '*$2*', $text);
                $text = strip_tags($text);
                $lines[] = "- " . trim($text);
            }
            return "\n" . implode("\n", $lines) . "\n";
        }, $html);

        // Ordered lists
        $html = preg_replace_callback('/<ol[^>]*>(.*?)<\/ol>/is', function ($m) {
            $lines = [];
            preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $m[1], $items);
            foreach ($items[1] ?? [] as $idx => $content) {
                $text = preg_replace('/<(strong|b)>(.*?)<\/\1>/is', '**$2**', $content);
                $text = preg_replace('/<(em|i)>(.*?)<\/\1>/is', '*$2*', $text);
                $text = strip_tags($text);
                $lines[] = ($idx + 1) . ". " . trim($text);
            }
            return "\n" . implode("\n", $lines) . "\n";
        }, $html);

        // Strong/bold (remaining, outside lists)
        $html = preg_replace('/<(strong|b)>(.*?)<\/\1>/is', '**$2**', $html);

        // Em/italic
        $html = preg_replace('/<(em|i)>(.*?)<\/\1>/is', '*$2*', $html);

        // Paragraphs → double newline
        $html = preg_replace('/<\/p>\s*<p[^>]*>/is', "\n\n", $html);
        $html = preg_replace('/<p[^>]*>/i', '', $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);

        // Line breaks
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);

        // Strip remaining tags (div, span, etc.)
        $html = strip_tags($html);

        // Clean up whitespace
        $html = preg_replace('/\n{3,}/', "\n\n", $html);
        $html = preg_replace('/[ \t]+/', ' ', $html);
        // Trim each line
        $lines = array_map('trim', explode("\n", $html));
        $html = implode("\n", $lines);

        return trim($html);
    }

    private static function strip_style_attrs(string $html): string {
        $html = preg_replace('/\s+style="[^"]*"/', '', $html);
        $html = preg_replace('/\s+data-[a-z-]+="[^"]*"/', '', $html);
        // Strip Elementor-specific class attributes from tags
        $html = preg_replace('/\s+class="[^"]*elementor[^"]*"/', '', $html);
        // Strip class attributes that are purely presentational (no semantic value)
        $html = preg_replace('/\s+class="[^"]*"/', '', $html);
        // Convert heading tags with stripped classes to clean tags
        // e.g. <h4> content </h4> stays, but empty wrapper divs are removed
        $html = preg_replace('/<div[^>]*>\s*<\/div>/', '', $html);
        $html = preg_replace('/<span[^>]*>\s*<\/span>/', '', $html);
        return trim($html);
    }
}
