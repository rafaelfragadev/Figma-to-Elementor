<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Maps Figma nodes to visually-detected section boundaries.
 *
 * SOURCE OF TRUTH
 * ───────────────
 * The visual sections from Visual_Section_Detector define WHERE each section
 * starts and ends (in absolute design-unit Y coordinates).
 *
 * Figma node hierarchy is intentionally IGNORED for layout decisions.
 * Each direct child of the root frame is assigned to the visual section
 * whose Y range contains the child's vertical midpoint.
 *
 * The result is a standard SectionModel array that the existing
 * Elementor_Atomic_Builder can consume without modifications.
 *
 * COORDINATE SYSTEM
 * ─────────────────
 * All Figma nodes carry absoluteBoundingBox coordinates (canvas-absolute).
 * The synthetic section container stores its own canvas-absolute x/y.
 * When the builder processes children with force_absolute=true, it computes:
 *
 *   rel_x = node.layout.x - container.layout.x
 *   rel_y = node.layout.y - container.layout.y
 *
 * This naturally gives the correct position of each node inside its section,
 * regardless of the original Figma parent group structure.
 */
final class Node_Visual_Mapper
{
    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Build section models from visual boundaries + Figma first-level nodes.
     *
     * @param  array $first_level_nodes  Direct children of the root FRAME in
     *                                   the Figma_Parser intermediate tree.
     *                                   Each node has layout.x / layout.y in
     *                                   absolute canvas coordinates.
     * @param  array $visual_sections    Output of Visual_Section_Detector::detect().
     * @param  float $frame_x            Absolute canvas X of the root frame.
     * @param  float $frame_w            Frame width in design units.
     * @return array SectionModel[] sorted by ascending Y, re-indexed 0…N.
     */
    public function build_sections(
        array $first_level_nodes,
        array $visual_sections,
        float $frame_x,
        float $frame_w
    ): array {
        if (empty($visual_sections)) {
            return [];
        }

        $ns = count($visual_sections);

        // Bucket: section_index → [ assigned nodes ]
        $buckets = array_fill(0, $ns, []);

        foreach ($first_level_nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $layout = is_array($node['layout'] ?? null) ? $node['layout'] : [];
            $abs_y  = (float) ($layout['y']      ?? 0);
            $h      = (float) ($layout['height'] ?? 0);
            $mid_y  = $abs_y + $h / 2.0;

            $idx           = $this->section_for_y($mid_y, $visual_sections);
            $buckets[$idx][] = $node;
        }

        // Build one SectionModel per visual section
        $sections = [];
        foreach ($visual_sections as $vi => $vs) {
            $sections[] = $this->build_model($vi, $vs, $buckets[$vi], $frame_x, $frame_w);
        }

        // Sort + re-index by absolute Y (should already be sorted, but be safe)
        usort($sections, static fn($a, $b) => ($a['y_abs'] ?? 0) <=> ($b['y_abs'] ?? 0));
        foreach ($sections as $i => &$sec) {
            $sec['index'] = $i;
        }
        unset($sec);

        return $sections;
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * Returns the index of the visual section that contains $y (by midpoint).
     * Falls back to the nearest boundary if $y is outside every section.
     */
    private function section_for_y(float $y, array $sections): int
    {
        // Exact containment
        foreach ($sections as $i => $sec) {
            if ($y >= $sec['y_start_abs'] && $y < $sec['y_end_abs']) {
                return $i;
            }
        }

        // Nearest boundary fallback
        $best_dist = PHP_FLOAT_MAX;
        $best_idx  = 0;

        foreach ($sections as $i => $sec) {
            $dist = min(
                abs($y - $sec['y_start_abs']),
                abs($y - $sec['y_end_abs'])
            );
            if ($dist < $best_dist) {
                $best_dist = $dist;
                $best_idx  = $i;
            }
        }

        return $best_idx;
    }

    private function build_model(
        int   $index,
        array $vs,
        array $nodes,
        float $frame_x,
        float $frame_w
    ): array {
        $y_start   = (float) ($vs['y_start_abs'] ?? 0);
        $y_end     = (float) ($vs['y_end_abs']   ?? 0);
        $height    = (float) ($vs['height']       ?? ($y_end - $y_start));
        $y_rel     = (float) ($vs['y_start_rel']  ?? 0);
        $bg_type   = as_str($vs['bg_type']  ?? 'none');
        $bg_color  = as_str($vs['bg_color'] ?? '');
        $bg_grad   = is_array($vs['bg_gradient'] ?? null) ? $vs['bg_gradient'] : null;

        // ── Synthetic section container ────────────────────────────────────
        // The builder's container() will:
        //   - Set min_height = $height  (is_root = true, force_absolute = false)
        //   - Apply $bg_color / $bg_grad as background
        //   - Force all children to position:absolute  (is_auto_layout = false)
        //   - Compute each child's offset as:
        //       rel_x = child.layout.x - frame_x
        //       rel_y = child.layout.y - y_start
        $container = [
            'type'                 => 'container',
            'figma_id'             => '',
            'figma_type'           => 'VISUAL_SECTION',
            'name'                 => 'Section ' . ($index + 1),
            'layout'               => [
                'is_auto_layout' => false,
                'direction'      => '',
                'gap'            => null,
                'padding'        => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0],
                'primary_axis'   => '',
                'counter_axis'   => '',
                'positioning'    => '',
                'x'              => $frame_x,   // absolute canvas X
                'y'              => $y_start,   // absolute canvas Y = section top
                'width'          => $frame_w,
                'height'         => $height,
            ],
            'opacity'              => 1.0,
            'background'           => $bg_color,
            'background_image_ref' => '',
            'background_gradient'  => $bg_grad,
            'border_radius'        => null,
            'is_root'              => false,
            'children'             => $nodes,
        ];

        // ── Display background type ────────────────────────────────────────
        if ('gradient' === $bg_type) {
            $bg_display = 'gradient';
        } elseif ('image' === $bg_type) {
            $bg_display = 'image';
        } elseif ($bg_color !== '') {
            $bg_display = 'solid';
        } else {
            $bg_display = 'none';
        }

        // ── Count nodes ────────────────────────────────────────────────────
        $el_count = 0;
        foreach ($nodes as $n) {
            if (is_array($n)) {
                $el_count += $this->count_nodes($n);
            }
        }

        return [
            'index'           => $index,
            'name'            => 'Section ' . ($index + 1),
            'y'               => $y_rel,
            'y_abs'           => $y_start,
            'height'          => $height,
            'width'           => $frame_w,
            'background'      => $bg_color,
            'background_type' => $bg_display,
            'figma_type'      => 'VISUAL',
            'is_auto_layout'  => false,
            'element_count'   => $el_count,
            'children'        => [$container],
            'extra_children'  => [],
            'source'          => 'visual',
        ];
    }

    private function count_nodes(array $node): int
    {
        $count = 1;
        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $count += $this->count_nodes($child);
            }
        }
        return $count;
    }
}
