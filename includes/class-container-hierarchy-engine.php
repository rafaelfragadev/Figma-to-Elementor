<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Groups a flat list of intermediate Figma nodes into logical Elementor containers.
 *
 * ROLES:
 *   background  — spans ≥85% of frame width, or named bg/background/texture/pattern
 *   logo        — node whose name contains "logo"
 *   content     — heading/text clusters (with nearby buttons absorbed)
 *   media       — large images / complex containers (≥28% of frame width)
 *   cards       — card groups (≥2 similar-size siblings at same Y)  → CARD_GROUP
 *   decorative  — everything else (small ornaments, small SVGs, etc.)
 *
 * Output nodes are either:
 *   - LOGICAL_CONTAINER  (type=container, figma_type=LOGICAL_CONTAINER)
 *   - CARD_GROUP         (type=container, figma_type=CARD_GROUP)
 *   - unchanged original nodes that don't benefit from wrapping
 *
 * All produced containers use absolute positioning (is_auto_layout=false) so
 * the existing builder coordinate math works without modification.
 */
final class Container_Hierarchy_Engine
{
    private Grid_Model $grid;

    public function __construct(Grid_Model $grid)
    {
        $this->grid = $grid;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * @param  array $nodes      Flat list of parsed intermediate nodes for one section
     * @param  float $frame_x    Absolute X origin of the Figma frame
     * @param  float $section_y  Absolute Y of the section top (unused in math, kept for future)
     * @param  float $frame_w    Frame width in design units
     * @return array Grouped/wrapped node list
     */
    public function group(array $nodes, float $frame_x, float $section_y, float $frame_w): array
    {
        $nodes = array_values(array_filter($nodes, 'is_array'));
        if (empty($nodes)) {
            return [];
        }

        // 1. Assign a _role to every node.
        foreach ($nodes as &$n) {
            $n['_role'] = $this->detect_role($n, $frame_w);
        }
        unset($n);

        // 2. Detect card groups (must run before content clustering).
        $nodes = $this->detect_card_groups($nodes);

        // 3. Cluster text / heading / button nodes into content containers.
        $nodes = $this->cluster_content($nodes, $frame_w);

        // 4. Wrap lone logo and media nodes in their own logical containers.
        $nodes = $this->mark_solo_wraps($nodes, ['logo', 'media']);

        // 5. Build LOGICAL_CONTAINERs for marked nodes; strip metadata elsewhere.
        return $this->finalize($nodes, $frame_x);
    }

    // -------------------------------------------------------------------------
    // Role detection
    // -------------------------------------------------------------------------

    private function detect_role(array $node, float $frame_w): string
    {
        // Already-grouped card group (from a previous pass)
        if ('CARD_GROUP' === as_str($node['figma_type'] ?? '')) {
            return 'cards';
        }

        $name   = strtolower(as_str($node['name'] ?? ''));
        $type   = as_str($node['type']   ?? '');
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : [];
        $node_w = (float) ($layout['width'] ?? 0);

        // Logo
        if (strpos($name, 'logo') !== false) {
            return 'logo';
        }

        // Background: spans most of the frame, or named explicitly
        if ($frame_w > 0 && $node_w >= $frame_w * 0.85) {
            return 'background';
        }
        foreach (['bg', 'background', 'pattern', 'texture', 'overlay'] as $kw) {
            if (strpos($name, $kw) !== false) {
                return 'background';
            }
        }

        // Explicit widget types
        if ('button' === $type) {
            return 'button';
        }
        if (in_array($type, ['heading', 'text'], true)) {
            return 'content';
        }
        if ('shape' === $type) {
            return ($frame_w > 0 && $node_w >= $frame_w * 0.85) ? 'background' : 'decorative';
        }
        if ('image' === $type) {
            return ($frame_w > 0 && $node_w >= $frame_w * 0.28) ? 'media' : 'decorative';
        }

        // Container: peek at children to decide
        if ('container' === $type && ! empty($node['children'])) {
            if ($this->subtree_has_types($node, ['heading', 'text'])) {
                return 'content';
            }
            $w_ratio = $frame_w > 0 ? $node_w / $frame_w : 0;
            return $w_ratio >= 0.25 ? 'media' : 'decorative';
        }

        return 'decorative';
    }

    private function subtree_has_types(array $node, array $types): bool
    {
        if (in_array(as_str($node['type'] ?? ''), $types, true)) {
            return true;
        }
        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child) && $this->subtree_has_types($child, $types)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Card detection
    // -------------------------------------------------------------------------

    private function detect_card_groups(array $nodes): array
    {
        // Only containers-with-children that are not already grouped
        $containers = [];
        $others     = [];
        foreach ($nodes as $node) {
            if (
                as_str($node['type'] ?? '') === 'container'
                && ! empty($node['children'])
                && as_str($node['figma_type'] ?? '') !== 'CARD_GROUP'
            ) {
                $containers[] = $node;
            } else {
                $others[] = $node;
            }
        }

        if (count($containers) < 2) {
            return $nodes;
        }

        $n    = count($containers);
        $used = array_fill(0, $n, false);
        $groups = [];

        for ($i = 0; $i < $n; $i++) {
            if ($used[$i]) {
                continue;
            }
            $li = is_array($containers[$i]['layout'] ?? null) ? $containers[$i]['layout'] : [];
            $wi = (float) ($li['width']  ?? 0);
            $hi = (float) ($li['height'] ?? 0);
            $yi = (float) ($li['y']      ?? 0);

            $group    = [$containers[$i]];
            $used[$i] = true;

            for ($j = $i + 1; $j < $n; $j++) {
                if ($used[$j]) {
                    continue;
                }
                $lj = is_array($containers[$j]['layout'] ?? null) ? $containers[$j]['layout'] : [];
                $wj = (float) ($lj['width']  ?? 0);
                $hj = (float) ($lj['height'] ?? 0);
                $yj = (float) ($lj['y']      ?? 0);

                if ($wi <= 0 || $wj <= 0) {
                    continue;
                }

                $w_ratio = min($wi, $wj) / max($wi, $wj);
                $h_ratio = ($hi > 0 && $hj > 0) ? min($hi, $hj) / max($hi, $hj) : 1.0;

                if ($w_ratio >= 0.80 && $h_ratio >= 0.75 && abs($yi - $yj) <= 40) {
                    $group[]  = $containers[$j];
                    $used[$j] = true;
                }
            }

            $groups[] = $group;
        }

        $result = $others;
        foreach ($groups as $group) {
            if (count($group) >= 2) {
                $cg          = $this->make_card_group($group);
                $cg['_role'] = 'cards';
                $result[]    = $cg;
            } else {
                $result[] = $group[0];
            }
        }

        usort($result, fn ($a, $b) =>
            (float) ((is_array($a['layout'] ?? null) ? $a['layout'] : [])['y'] ?? 0)
            <=>
            (float) ((is_array($b['layout'] ?? null) ? $b['layout'] : [])['y'] ?? 0)
        );

        return $result;
    }

    private function make_card_group(array $cards): array
    {
        usort($cards, fn ($a, $b) =>
            (float) ((is_array($a['layout'] ?? null) ? $a['layout'] : [])['x'] ?? 0)
            <=>
            (float) ((is_array($b['layout'] ?? null) ? $b['layout'] : [])['x'] ?? 0)
        );

        $fl     = is_array($cards[0]['layout'] ?? null) ? $cards[0]['layout'] : [];
        $ll_key = array_key_last($cards);
        $ll     = is_array($cards[$ll_key]['layout'] ?? null) ? $cards[$ll_key]['layout'] : [];

        $group_x = (float) ($fl['x'] ?? 0);
        $group_y = (float) ($fl['y'] ?? 0);
        $last_x  = (float) ($ll['x'] ?? 0);
        $last_w  = (float) ($ll['width'] ?? 0);
        $group_w = ($last_x + $last_w) - $group_x;
        $group_h = max(array_map(
            fn ($c) => (float) ((is_array($c['layout'] ?? null) ? $c['layout'] : [])['height'] ?? 0),
            $cards
        ) ?: [0]);

        $gap = 0.0;
        if (count($cards) > 1) {
            $l0  = is_array($cards[0]['layout'] ?? null) ? $cards[0]['layout'] : [];
            $l1  = is_array($cards[1]['layout'] ?? null) ? $cards[1]['layout'] : [];
            $gap = max(0.0, (float) ($l1['x'] ?? 0) - ((float) ($l0['x'] ?? 0) + (float) ($l0['width'] ?? 0)));
        }

        return [
            'type'                 => 'container',
            'figma_type'           => 'CARD_GROUP',
            'figma_id'             => '',
            'name'                 => 'Card Group',
            'is_card_group'        => true,
            'layout'               => [
                'is_auto_layout' => true,
                'direction'      => 'row',
                'gap'            => $gap,
                'padding'        => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0],
                'primary_axis'   => 'MIN',
                'counter_axis'   => 'MIN',
                'positioning'    => '',
                'x'              => $group_x,
                'y'              => $group_y,
                'width'          => $group_w,
                'height'         => $group_h,
            ],
            'background'           => '',
            'background_image_ref' => '',
            'background_gradient'  => null,
            'border_radius'        => null,
            'opacity'              => 1.0,
            'children'             => $cards,
        ];
    }

    // -------------------------------------------------------------------------
    // Content clustering
    // -------------------------------------------------------------------------

    /**
     * Groups adjacent text/heading/button nodes that share a visual column
     * (overlapping X range, within ~350px in Y) into content cluster markers.
     */
    private function cluster_content(array $nodes, float $frame_w): array
    {
        $content_roles = ['content', 'button'];
        $content_nodes = [];
        $other_nodes   = [];

        foreach ($nodes as $node) {
            if (in_array(as_str($node['_role'] ?? ''), $content_roles, true)) {
                $content_nodes[] = $node;
            } else {
                $other_nodes[] = $node;
            }
        }

        if (count($content_nodes) < 2) {
            return $nodes;
        }

        usort($content_nodes, function ($a, $b) {
            $la = is_array($a['layout'] ?? null) ? $a['layout'] : [];
            $lb = is_array($b['layout'] ?? null) ? $b['layout'] : [];
            $dy = (float) ($la['y'] ?? 0) - (float) ($lb['y'] ?? 0);
            return $dy !== 0.0 ? ($dy > 0 ? 1 : -1) : 0;
        });

        // Max cluster width: 55% of frame (prevents left + right columns merging)
        $max_cluster_w = $frame_w > 0 ? $frame_w * 0.55 : 800.0;

        $n    = count($content_nodes);
        $used = array_fill(0, $n, false);
        $clusters = [];

        for ($i = 0; $i < $n; $i++) {
            if ($used[$i]) {
                continue;
            }

            $li   = is_array($content_nodes[$i]['layout'] ?? null) ? $content_nodes[$i]['layout'] : [];
            $xi   = (float) ($li['x'] ?? 0);
            $yi   = (float) ($li['y'] ?? 0);
            $xi1  = $xi + (float) ($li['width'] ?? 0);
            $cl   = $xi;    // cluster left
            $cr   = $xi1;   // cluster right

            $cluster  = [$content_nodes[$i]];
            $used[$i] = true;

            for ($j = $i + 1; $j < $n; $j++) {
                if ($used[$j]) {
                    continue;
                }
                $lj  = is_array($content_nodes[$j]['layout'] ?? null) ? $content_nodes[$j]['layout'] : [];
                $xj  = (float) ($lj['x'] ?? 0);
                $yj  = (float) ($lj['y'] ?? 0);
                $xj1 = $xj + (float) ($lj['width'] ?? 0);

                $x_overlap = ($xj < $cr) && ($xj1 > $cl);
                $y_close   = abs($yj - $yi) <= 350;
                $new_w     = max($cr, $xj1) - min($cl, $xj);

                if ($x_overlap && $y_close && $new_w <= $max_cluster_w) {
                    $cluster[] = $content_nodes[$j];
                    $used[$j]  = true;
                    $cl = min($cl, $xj);
                    $cr = max($cr, $xj1);
                }
            }

            $clusters[] = $cluster;
        }

        $result = $other_nodes;
        foreach ($clusters as $cluster) {
            if (count($cluster) > 1) {
                $result[] = [
                    '_cluster'      => true,
                    '_cluster_role' => 'content',
                    '_nodes'        => $cluster,
                ];
            } else {
                $result[] = $cluster[0];
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Solo wrapping
    // -------------------------------------------------------------------------

    private function mark_solo_wraps(array $nodes, array $roles): array
    {
        foreach ($nodes as &$node) {
            if (is_array($node) && in_array(as_str($node['_role'] ?? ''), $roles, true)) {
                $node['_wrap'] = true;
            }
        }
        unset($node);
        return $nodes;
    }

    // -------------------------------------------------------------------------
    // Finalize: produce LOGICAL_CONTAINERs
    // -------------------------------------------------------------------------

    private function finalize(array $nodes, float $frame_x): array
    {
        $result = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            // Content cluster marker
            if (! empty($node['_cluster'])) {
                $result[] = $this->make_logical_container(
                    $node['_nodes'],
                    as_str($node['_cluster_role'] ?? 'content'),
                    $frame_x
                );
                continue;
            }

            // Solo logo / media wrap
            if (! empty($node['_wrap'])) {
                $role = as_str($node['_role'] ?? 'decorative');
                unset($node['_role'], $node['_wrap']);
                $result[] = $this->make_logical_container([$node], $role, $frame_x);
                continue;
            }

            // Plain node — just strip metadata
            unset($node['_role'], $node['_wrap'], $node['_cluster'], $node['_cluster_role'], $node['_nodes']);
            $result[] = $node;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Logical container factory
    // -------------------------------------------------------------------------

    private function make_logical_container(array $nodes, string $role, float $frame_x): array
    {
        // Compute bounding box of all children.
        $min_x = null;
        $min_y = null;
        $max_x = null;
        $max_y = null;

        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            $l    = is_array($n['layout'] ?? null) ? $n['layout'] : [];
            $nx   = (float) ($l['x']      ?? 0);
            $ny   = (float) ($l['y']      ?? 0);
            $nxe  = $nx + (float) ($l['width']  ?? 0);
            $nye  = $ny + (float) ($l['height'] ?? 0);
            $min_x = $min_x === null ? $nx  : min($min_x, $nx);
            $min_y = $min_y === null ? $ny  : min($min_y, $ny);
            $max_x = $max_x === null ? $nxe : max($max_x, $nxe);
            $max_y = $max_y === null ? $nye : max($max_y, $nye);
        }

        $min_x = $min_x ?? 0.0;
        $min_y = $min_y ?? 0.0;
        $max_x = $max_x ?? $min_x;
        $max_y = $max_y ?? $min_y;

        $w = max(0.0, $max_x - $min_x);
        $h = max(0.0, $max_y - $min_y);

        // Grid annotations (for debug / future grid-aware layout)
        $col_start = $this->grid->column_start($min_x, $frame_x);
        $col_span  = $this->grid->column_span($w);

        // Strip internal metadata from children
        $children = array_map(function ($n) {
            if (is_array($n)) {
                unset($n['_role'], $n['_wrap']);
            }
            return $n;
        }, $nodes);

        return [
            'type'             => 'container',
            'figma_type'       => 'LOGICAL_CONTAINER',
            'figma_id'         => '',
            'name'             => $role . '-container',
            'logical_role'     => $role,
            'grid_col_start'   => $col_start,
            'grid_col_span'    => $col_span,
            'layout'           => [
                // Always absolute children — pixel-perfect fidelity over editability
                'is_auto_layout' => false,
                'direction'      => '',
                'gap'            => null,
                'padding'        => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0],
                'primary_axis'   => '',
                'counter_axis'   => '',
                'positioning'    => '',
                'x'              => $min_x,
                'y'              => $min_y,
                'width'          => $w,
                'height'         => $h,
            ],
            'background'           => '',
            'background_image_ref' => '',
            'background_gradient'  => null,
            'border_radius'        => null,
            'opacity'              => 1.0,
            'children'             => $children,
        ];
    }
}
