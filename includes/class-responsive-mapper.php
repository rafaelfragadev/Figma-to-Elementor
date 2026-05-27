<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Merges responsive overrides (tablet/mobile frames) into the base desktop
 * Elementor element tree by injecting responsive control values.
 *
 * In Elementor, responsive settings are stored as separate keys with device
 * suffixes: `_tablet`, `_mobile` (e.g. `padding_tablet`, `min_height_mobile`).
 */
final class Responsive_Mapper
{
    /**
     * Merge tablet and mobile trees into the desktop tree.
     *
     * @param array $desktop_elements  Output from Elementor_Atomic_Builder::build()
     * @param array $tablet_elements   Optional tablet variant
     * @param array $mobile_elements   Optional mobile variant
     * @return array Merged elements
     */
    public function merge(array $desktop_elements, array $tablet_elements = [], array $mobile_elements = []): array
    {
        if (empty($tablet_elements) && empty($mobile_elements)) {
            return $desktop_elements;
        }

        // Build ID-indexed lookup for tablet and mobile elements
        $tablet_index = $this->index_by_id($tablet_elements);
        $mobile_index = $this->index_by_id($mobile_elements);

        return $this->apply_responsive($desktop_elements, $tablet_index, $mobile_index);
    }

    private function apply_responsive(array $elements, array $tablet_idx, array $mobile_idx): array
    {
        foreach ($elements as &$el) {
            $id = as_str($el['id'] ?? '');

            if ($id !== '' && isset($tablet_idx[$id])) {
                $el['settings'] = $this->merge_responsive_settings(
                    $el['settings'] ?? [],
                    $tablet_idx[$id]['settings'] ?? [],
                    '_tablet'
                );
            }

            if ($id !== '' && isset($mobile_idx[$id])) {
                $el['settings'] = $this->merge_responsive_settings(
                    $el['settings'] ?? [],
                    $mobile_idx[$id]['settings'] ?? [],
                    '_mobile'
                );
            }

            if (! empty($el['elements'])) {
                $el['elements'] = $this->apply_responsive($el['elements'], $tablet_idx, $mobile_idx);
            }
        }
        unset($el);

        return $elements;
    }

    private function merge_responsive_settings(array $base, array $override, string $suffix): array
    {
        $responsive_keys = ['min_height', 'padding', 'width', 'gap', 'font_size',
                            'typography_font_size', 'typography_line_height'];

        foreach ($responsive_keys as $key) {
            if (isset($override[$key])) {
                $base[$key . $suffix] = $override[$key];
            }
        }

        return $base;
    }

    /** @return array<string, array> */
    private function index_by_id(array $elements): array
    {
        $index = [];
        foreach ($elements as $el) {
            if (! is_array($el)) {
                continue;
            }
            $id = as_str($el['id'] ?? '');
            if ($id !== '') {
                $index[$id] = $el;
            }
            if (! empty($el['elements'])) {
                $index = array_merge($index, $this->index_by_id($el['elements']));
            }
        }
        return $index;
    }
}
