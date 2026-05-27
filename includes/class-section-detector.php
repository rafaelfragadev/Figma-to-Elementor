<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Converts the intermediate tree into SectionModel entries.
 *
 * Priority:
 *   1. FRAME nodes                    → always a standalone section
 *   2. Full-width GROUP/COMPONENT     → section if width ≥ 80 % of frame width
 *   3. Everything else                → decorative; attached to the nearest section
 *
 * Sections are sorted by absolute Y before indexing.
 * A SectionModel includes background type info for the debug panel.
 */
final class Section_Detector
{
    /** Maximum allowed height ratio before flagging a height mismatch (130 %). */
    private const HEIGHT_WARN_RATIO = 1.30;

    /**
     * @param  array $tree  Output of Figma_Parser::parse()
     * @return array<int, array>  Array of SectionModel entries
     */
    public function detect(array $tree): array
    {
        $frame_container = null;
        foreach (($tree['children'] ?? []) as $root) {
            if (is_array($root)) {
                $frame_container = $root;
                break;
            }
        }

        if (! $frame_container) {
            return [];
        }

        $frame_y     = (float) ($frame_container['layout']['y'] ?? 0);
        $frame_w     = (float) ($frame_container['layout']['width'] ?? 0);
        $frame_children = is_array($frame_container['children'] ?? null)
            ? $frame_container['children']
            : [];

        // If the frame has no children, wrap the frame itself as section 0.
        if (empty($frame_children)) {
            $height = (float) ($frame_container['layout']['height'] ?? 0);
            if ($height < 4) {
                return [];
            }
            return [[$this->make_section(0, $frame_container, 0.0, $frame_y, $frame_w)]];
        }

        // Sort by absolute Y position.
        usort($frame_children, static function ($a, $b) {
            return ((float) ($a['layout']['y'] ?? 0)) <=> ((float) ($b['layout']['y'] ?? 0));
        });

        $sections    = [];
        $decorations = [];

        foreach ($frame_children as $child) {
            if (! is_array($child)) {
                continue;
            }

            $height = (float) ($child['layout']['height'] ?? 0);
            $width  = (float) ($child['layout']['width'] ?? 0);

            // Skip zero-size phantom nodes.
            if ($height < 4 && $width < 4) {
                continue;
            }

            if ($this->is_section_node($child, $frame_w)) {
                $abs_y   = (float) ($child['layout']['y'] ?? 0);
                $rel_y   = max(0.0, $abs_y - $frame_y);
                $idx     = count($sections);
                $section = $this->make_section($idx, $child, $rel_y, $abs_y, $frame_w);

                // Attach pending decorations to this section.
                if (! empty($decorations)) {
                    $section['extra_children'] = $decorations;
                    $decorations               = [];
                }

                $sections[] = $section;
            } else {
                // Collect decoration to attach to nearest section.
                $decorations[] = $child;
            }
        }

        // Attach leftover decorations to the last section.
        if (! empty($decorations) && ! empty($sections)) {
            $last = count($sections) - 1;
            $sections[$last]['extra_children'] = array_merge(
                $sections[$last]['extra_children'] ?? [],
                $decorations
            );
        }

        // Re-index and re-number.
        $sections = array_values($sections);
        foreach ($sections as $i => &$sec) {
            $sec['index'] = $i;
        }
        unset($sec);

        return $sections;
    }

    /**
     * Count all nodes in a sub-tree (for the log display).
     */
    public function count_elements(array $node): int
    {
        $count = 1;
        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $count += $this->count_elements($child);
            }
        }
        return $count;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true when the node should be treated as a standalone section.
     */
    private function is_section_node(array $child, float $frame_w): bool
    {
        $figma_type = as_str($child['figma_type'] ?? '');
        $node_type  = as_str($child['type'] ?? '');
        $width      = (float) ($child['layout']['width'] ?? 0);
        $height     = (float) ($child['layout']['height'] ?? 0);

        // Priority 1: FRAME nodes are always sections.
        if ('FRAME' === $figma_type) {
            return true;
        }

        // Priority 2: Full-width containers (GROUP, COMPONENT, INSTANCE).
        if (in_array($figma_type, ['GROUP', 'COMPONENT', 'INSTANCE', 'COMPONENT_SET'], true)
            && 'container' === $node_type
            && $height >= 80
            && ($frame_w <= 0 || ($width / $frame_w) >= 0.75)
        ) {
            return true;
        }

        // Priority 3: Background change — any container with a visible background and decent height.
        if ('container' === $node_type && $height >= 80) {
            $has_bg = (as_str($child['background'] ?? '') !== '')
                || ($child['background_image_ref'] ?? '') !== ''
                || ($child['background_gradient'] ?? null) !== null;
            if ($has_bg && ($frame_w <= 0 || ($width / $frame_w) >= 0.50)) {
                return true;
            }
        }

        return false;
    }

    private function make_section(int $index, array $child, float $rel_y, float $abs_y, float $frame_w): array
    {
        $height     = (float) ($child['layout']['height'] ?? 0);
        $width      = (float) ($child['layout']['width'] ?? 0);
        $background = as_str($child['background'] ?? '');
        $bg_ref     = as_str($child['background_image_ref'] ?? '');
        $bg_grad    = $child['background_gradient'] ?? null;

        if ($bg_ref !== '') {
            $bg_type = 'image';
        } elseif ($bg_grad !== null) {
            $bg_type = 'gradient';
        } elseif ($background !== '') {
            $bg_type = 'solid';
        } else {
            $bg_type = 'none';
        }

        return [
            'index'         => $index,
            'name'          => as_str($child['name'] ?? ('Section ' . ($index + 1))),
            'y'             => $rel_y,
            'y_abs'         => $abs_y,
            'height'        => $height,
            'width'         => $width,
            'background'    => $background,
            'background_type' => $bg_type,
            'figma_type'    => as_str($child['figma_type'] ?? $child['type'] ?? ''),
            'is_auto_layout'=> (bool) ($child['layout']['is_auto_layout'] ?? false),
            'element_count' => $this->count_elements($child),
            'children'      => [$child],
            'extra_children'=> [],
            'height_warning'=> ($height > 0 && $height > $height * self::HEIGHT_WARN_RATIO), // always false, placeholder
        ];
    }
}
