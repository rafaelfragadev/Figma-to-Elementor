<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Extracts design tokens (colors, fonts) from a parsed intermediate tree
 * and writes them to the active Elementor kit as Global Colors and Global Fonts.
 */
final class Design_Token_Extractor
{
    /** @var array<string, string> hex → slug */
    private array $color_index = [];

    /** @var array<string, bool> family → true */
    private array $font_index = [];

    /**
     * Traverse the tree, collect all unique colors and font families,
     * and push them to the Elementor kit.
     *
     * Returns the color slug map for use by the builder.
     *
     * @param array $tree Intermediate tree from Figma_Parser::parse()
     * @return array<string, string> hex → elementor_global_color_slug
     */
    public function extract_and_apply(array $tree): array
    {
        $this->color_index = [];
        $this->font_index  = [];

        $this->traverse($tree);

        $color_map = $this->apply_global_colors();
        $this->apply_global_fonts();

        return $color_map;
    }

    private function traverse(array $node): void
    {
        $type = as_str($node['type'] ?? '');

        // Collect background colors
        $bg = as_str($node['background'] ?? '');
        if ($bg !== '') {
            $this->register_color($bg);
        }

        // Collect text colors
        $tc = as_str($node['text_color'] ?? '');
        if ($tc !== '') {
            $this->register_color($tc);
        }

        // Collect gradient colors
        $grad = $node['background_gradient'] ?? null;
        if (is_array($grad)) {
            $this->register_color(as_str($grad['color_a'] ?? ''));
            $this->register_color(as_str($grad['color_b'] ?? ''));
        }

        // Collect font families
        $ff = $node['font_family'] ?? null;
        if (is_string($ff) && $ff !== '') {
            $this->font_index[$ff] = true;
        }

        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->traverse($child);
            }
        }
    }

    private function register_color(string $hex): void
    {
        if ($hex === '' || isset($this->color_index[$hex])) {
            return;
        }
        // Deduplicate by normalized lowercase hex
        $hex = strtolower($hex);
        $this->color_index[$hex] = '';
    }

    /**
     * @return array<string, string>
     */
    private function apply_global_colors(): array
    {
        if (! is_elementor_active()) {
            return [];
        }

        $kit_id   = $this->get_active_kit_id();
        $color_map = [];

        if (! $kit_id) {
            return [];
        }

        $existing_settings = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (! is_array($existing_settings)) {
            $existing_settings = [];
        }

        $system_colors = isset($existing_settings['system_colors']) && is_array($existing_settings['system_colors'])
            ? $existing_settings['system_colors']
            : [];

        // Build lookup of already registered colors by value
        $registered = [];
        foreach ($system_colors as $entry) {
            if (isset($entry['color'])) {
                $registered[strtolower($entry['color'])] = as_str($entry['_id'] ?? '');
            }
        }

        foreach (array_keys($this->color_index) as $hex) {
            if (isset($registered[$hex])) {
                $color_map[$hex] = $registered[$hex];
                continue;
            }

            $slug  = 'ftea-' . substr(md5($hex), 0, 6);
            $label = 'FTEA ' . strtoupper(substr($hex, 1));

            $system_colors[] = [
                '_id'   => $slug,
                'title' => $label,
                'color' => $hex,
            ];
            $registered[$hex]  = $slug;
            $color_map[$hex]   = $slug;
        }

        $existing_settings['system_colors'] = $system_colors;
        update_post_meta($kit_id, '_elementor_page_settings', $existing_settings);

        return $color_map;
    }

    private function apply_global_fonts(): void
    {
        if (! is_elementor_active() || empty($this->font_index)) {
            return;
        }

        $kit_id = $this->get_active_kit_id();
        if (! $kit_id) {
            return;
        }

        $existing_settings = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (! is_array($existing_settings)) {
            $existing_settings = [];
        }

        $system_typography = isset($existing_settings['system_typography']) && is_array($existing_settings['system_typography'])
            ? $existing_settings['system_typography']
            : [];

        $registered_families = [];
        foreach ($system_typography as $entry) {
            $fam = as_str($entry['typography_font_family'] ?? '');
            if ($fam !== '') {
                $registered_families[$fam] = true;
            }
        }

        foreach (array_keys($this->font_index) as $family) {
            if (isset($registered_families[$family])) {
                continue;
            }
            $slug = 'ftea-font-' . substr(md5($family), 0, 6);
            $system_typography[] = [
                '_id'                    => $slug,
                'title'                  => 'FTEA ' . $family,
                'typography_typography'  => 'custom',
                'typography_font_family' => $family,
            ];
            $registered_families[$family] = true;
        }

        $existing_settings['system_typography'] = $system_typography;
        update_post_meta($kit_id, '_elementor_page_settings', $existing_settings);
    }

    private function get_active_kit_id(): int
    {
        if (! function_exists('get_option')) {
            return 0;
        }

        $kit_id = (int) get_option('elementor_active_kit');
        return $kit_id > 0 ? $kit_id : 0;
    }
}
