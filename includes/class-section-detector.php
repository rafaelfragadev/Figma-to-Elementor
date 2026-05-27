<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Converts the intermediate tree into SectionModel entries.
 *
 * ALGORITHM
 * ──────────
 * 1. Classify every direct child of the root frame:
 *      main_section   — passes the scoring gate (≥3 of 7 criteria, not disqualified)
 *      background_layer — wide, has fill, but no real content children
 *      card           — too narrow (< 50 % of frame width)
 *      decorative     — logos, icons, buttons, navigation bars
 *      element        — text, images, shapes at the root level
 *
 * 2. Anchor sections = nodes classified as main_section.
 *
 * 3. Non-anchor nodes are assigned to the anchor whose Y-range contains
 *    them (by midpoint). Nodes outside every anchor Y-range → orphans.
 *
 * 4. Orphans are Y-clustered (gap ≤ 400 px) into synthetic sections.
 *    Each cluster is wrapped in a virtual container node so the builder
 *    can treat it like any other section (one root child with absolute
 *    positioning for its children).
 *
 * 5. All sections are sorted by absolute Y and re-indexed 0…N.
 *
 * SECTION SCORING (7 criteria, ≥ 3 required)
 * ───────────────────────────────────────────
 * C1  width ≥ 80 % of frame width
 * C2  height ≥ 350 px
 * C3  has a background (solid / gradient / image fill)
 * C4  node type is FRAME in the original Figma file
 * C5  has ≥ 3 structural children (containers, text, images, buttons)
 * C6  has at least one content child (text / heading / button / container)
 * C7  height ≥ 600 px
 *
 * DISQUALIFICATIONS (any single flag blocks section status)
 * ──────────────────────────────────────────────────────────
 * • type !== 'container' in the intermediate tree
 * • height < 250 px
 * • width < 50 % of frame width
 * • name contains: logo, icon, btn, button, avatar, badge, chip, nav·, navbar
 * • name starts with: bg , bg_, bg-, background, fundo, bgsec, bg sec
 * • only shape/image children with no text or nested containers
 */
final class Section_Detector
{
    private const MIN_SECTION_HEIGHT = 250;
    private const MIN_WIDTH_RATIO    = 0.50;
    private const MIN_SCORE          = 3;
    private const ORPHAN_GAP_PX      = 400;
    private const WARN_ABOVE         = 6;

    /** Full classification log from the last detect() run. */
    private array $last_log = [];
    private bool  $section_warning = false;

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * @param  array $tree  Output of Figma_Parser::parse()
     * @return array<int, array>  Array of SectionModel entries
     */
    public function detect(array $tree): array
    {
        $this->last_log       = [];
        $this->section_warning = false;

        $frame = $this->get_frame_container($tree);
        if (! $frame) {
            return [];
        }

        $frame_y  = (float) ($frame['layout']['y']     ?? 0);
        $frame_w  = (float) ($frame['layout']['width'] ?? 0);
        $children = is_array($frame['children'] ?? null) ? $frame['children'] : [];

        if (empty($children)) {
            $h = (float) ($frame['layout']['height'] ?? 0);
            return $h >= 4 ? [$this->build_anchor_model(0, $frame, $frame_y, $frame_w)] : [];
        }

        // Sort every child by absolute Y before processing.
        usort($children, static function ($a, $b) {
            return ((float) ($a['layout']['y'] ?? 0)) <=> ((float) ($b['layout']['y'] ?? 0));
        });

        // ── Phase 1: classify ──────────────────────────────────────────────
        $log     = [];
        $anchors = [];   // main_section entries
        $others  = [];   // everything else

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }
            $entry = $this->classify_node($child, $frame_w);
            $log[] = $entry;

            if ($entry['role'] === 'main_section') {
                $anchors[] = $entry;
            } else {
                $others[] = $entry;
            }
        }

        $this->last_log = $log;

        // ── Phase 2: assign non-sections to anchors ────────────────────────
        $orphans = [];
        foreach ($others as $item) {
            $idx = $this->find_containing_anchor($item, $anchors);
            if ($idx !== null) {
                $anchors[$idx]['attached'][] = $item;
            } else {
                $orphans[] = $item;
            }
        }

        // ── Phase 3: cluster orphans ───────────────────────────────────────
        $synthetic = $this->cluster_orphans($orphans, $frame_w, $frame_y);

        // ── Phase 4: build section models ─────────────────────────────────
        $sections = [];

        foreach ($anchors as $a) {
            $sections[] = $this->build_anchor_model(count($sections), $a['node'], $frame_y, $frame_w, $a);
        }

        foreach ($synthetic as $s) {
            $sections[] = $s;
        }

        // ── Phase 5: sort + re-index ───────────────────────────────────────
        usort($sections, static function ($a, $b) {
            return ($a['y_abs'] ?? 0) <=> ($b['y_abs'] ?? 0);
        });

        foreach ($sections as $i => &$sec) {
            $sec['index'] = $i;
        }
        unset($sec);

        if (count($sections) > self::WARN_ABOVE) {
            $this->section_warning = true;
        }

        return $sections;
    }

    /** Returns the full per-node classification log from the last detect() call. */
    public function get_last_log(): array
    {
        return $this->last_log;
    }

    /** True if the last detect() produced more sections than WARN_ABOVE. */
    public function has_section_warning(): bool
    {
        return $this->section_warning;
    }

    /** Recursive node count for the admin UI table. */
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

    // =========================================================================
    // Node classification
    // =========================================================================

    /**
     * Returns a classification entry:
     * [node, role, score, reasons, disq_reason, y_abs, y_end, attached]
     */
    private function classify_node(array $node, float $frame_w): array
    {
        $h       = (float) ($node['layout']['height'] ?? 0);
        $w       = (float) ($node['layout']['width']  ?? 0);
        $y       = (float) ($node['layout']['y']      ?? 0);
        $type    = as_str($node['type']       ?? '');
        $figma_t = as_str($node['figma_type'] ?? '');
        $name    = strtolower(as_str($node['name'] ?? ''));
        $ch      = is_array($node['children'] ?? null) ? $node['children'] : [];

        $base = [
            'node'        => $node,
            'y_abs'       => $y,
            'y_end'       => $y + $h,
            'score'       => 0,
            'reasons'     => [],
            'disq_reason' => null,
            'role'        => 'element',
            'attached'    => [],
        ];

        // ── Disqualifications ─────────────────────────────────────────────

        if ($type !== 'container') {
            return array_merge($base, [
                'role'        => in_array($type, ['shape', 'image'], true) ? 'decorative' : 'element',
                'disq_reason' => "type={$type} (not a container)",
            ]);
        }

        if ($h < self::MIN_SECTION_HEIGHT) {
            return array_merge($base, [
                'role'        => 'element',
                'disq_reason' => "height={$h}px < " . self::MIN_SECTION_HEIGHT . 'px',
            ]);
        }

        $width_ratio = ($frame_w > 0) ? ($w / $frame_w) : 1.0;
        if ($width_ratio < self::MIN_WIDTH_RATIO) {
            return array_merge($base, [
                'role'        => 'card',
                'disq_reason' => 'too narrow (' . round($width_ratio * 100) . '% of frame)',
            ]);
        }

        // Name-based disqualifiers for small/decorative components
        $bad_names = ['logo', 'icon', 'btn ', 'button', 'avatar', 'badge', 'chip', 'nav ', 'navbar'];
        foreach ($bad_names as $kw) {
            if (strpos($name, $kw) !== false) {
                return array_merge($base, [
                    'role'        => 'decorative',
                    'disq_reason' => "name contains '{$kw}'",
                ]);
            }
        }

        // Background-layer frames: name starts with bg/background/fundo
        $bg_prefixes = ['bg ', 'bg_', 'bg-', 'background', 'fundo', 'bgsec', 'bg sec'];
        foreach ($bg_prefixes as $pfx) {
            if (strpos($name, $pfx) === 0 || $name === 'bg') {
                return array_merge($base, [
                    'role'        => 'background_layer',
                    'disq_reason' => "background layer (name prefix '{$pfx}')",
                ]);
            }
        }

        // Container that only has shapes / images but no text or nested containers
        // → probably a decorative background frame, not a real section.
        $content_types  = ['heading', 'text', 'button', 'container'];
        $has_content_ch = false;
        $structural_count = 0;
        foreach ($ch as $c) {
            if (! is_array($c)) {
                continue;
            }
            $ct = as_str($c['type'] ?? '');
            $ch_h = (float) ($c['layout']['height'] ?? 0);
            if ($ch_h > 20 && in_array($ct, ['container', 'heading', 'text', 'image', 'button'], true)) {
                $structural_count++;
            }
            if (in_array($ct, $content_types, true)) {
                $has_content_ch = true;
            }
        }

        if (! $has_content_ch && $structural_count < 2) {
            return array_merge($base, [
                'role'        => 'background_layer',
                'disq_reason' => 'no content children (only shapes/images)',
            ]);
        }

        // ── Scoring ───────────────────────────────────────────────────────

        $score   = 0;
        $reasons = [];

        // C1: wide
        if ($width_ratio >= 0.80) {
            $score++;
            $reasons[] = 'C1: wide (' . round($width_ratio * 100) . '%)';
        }

        // C2: tall
        if ($h >= 350) {
            $score++;
            $reasons[] = 'C2: tall (' . round($h) . 'px)';
        }

        // C3: has background fill
        $has_bg = (as_str($node['background'] ?? '') !== '')
               || (as_str($node['background_image_ref'] ?? '') !== '')
               || ($node['background_gradient'] ?? null) !== null;
        if ($has_bg) {
            $score++;
            $reasons[] = 'C3: has background';
        }

        // C4: original Figma type is FRAME
        if ('FRAME' === $figma_t) {
            $score++;
            $reasons[] = 'C4: FRAME node';
        }

        // C5: multiple structural children
        if ($structural_count >= 3) {
            $score++;
            $reasons[] = "C5: {$structural_count} structural children";
        }

        // C6: has real content (text / buttons / nested containers)
        if ($has_content_ch) {
            $score++;
            $reasons[] = 'C6: has content children';
        }

        // C7: very tall section
        if ($h >= 600) {
            $score++;
            $reasons[] = 'C7: very tall (' . round($h) . 'px)';
        }

        $role = ($score >= self::MIN_SCORE) ? 'main_section' : 'content_group';

        return array_merge($base, [
            'role'    => $role,
            'score'   => $score,
            'reasons' => $reasons,
        ]);
    }

    // =========================================================================
    // Anchor assignment
    // =========================================================================

    /**
     * Returns the index in $anchors that Y-contains $item, or null.
     * Uses the item's vertical midpoint for containment.
     */
    private function find_containing_anchor(array $item, array $anchors): ?int
    {
        $mid = ($item['y_abs'] + $item['y_end']) / 2.0;

        foreach ($anchors as $idx => $anchor) {
            if ($mid >= $anchor['y_abs'] && $mid <= $anchor['y_end']) {
                return $idx;
            }
        }

        // Fallback: nearest anchor boundary within 100 px
        $best_dist = PHP_INT_MAX;
        $best_idx  = null;

        foreach ($anchors as $idx => $anchor) {
            $dist = min(abs($mid - $anchor['y_abs']), abs($mid - $anchor['y_end']));
            if ($dist < 100 && $dist < $best_dist) {
                $best_dist = $dist;
                $best_idx  = $idx;
            }
        }

        return $best_idx;
    }

    // =========================================================================
    // Orphan clustering → synthetic sections
    // =========================================================================

    /**
     * Groups orphan elements by Y proximity into synthetic section models.
     */
    private function cluster_orphans(array $orphans, float $frame_w, float $frame_y): array
    {
        if (empty($orphans)) {
            return [];
        }

        usort($orphans, static function ($a, $b) {
            return $a['y_abs'] <=> $b['y_abs'];
        });

        $clusters = [];
        $cur      = null;

        foreach ($orphans as $item) {
            if ($cur === null) {
                $cur = [
                    'items' => [$item],
                    'y'     => $item['y_abs'],
                    'y_end' => $item['y_end'],
                    'max_w' => (float) ($item['node']['layout']['width'] ?? 0),
                ];
            } else {
                $gap = $item['y_abs'] - $cur['y_end'];
                if ($gap <= self::ORPHAN_GAP_PX) {
                    $cur['items'][] = $item;
                    $cur['y_end']   = max($cur['y_end'], $item['y_end']);
                    $cur['max_w']   = max($cur['max_w'], (float) ($item['node']['layout']['width'] ?? 0));
                } else {
                    $clusters[] = $cur;
                    $cur = [
                        'items' => [$item],
                        'y'     => $item['y_abs'],
                        'y_end' => $item['y_end'],
                        'max_w' => (float) ($item['node']['layout']['width'] ?? 0),
                    ];
                }
            }
        }
        if ($cur !== null) {
            $clusters[] = $cur;
        }

        $result = [];
        foreach ($clusters as $cluster) {
            $result[] = $this->make_synthetic_section($cluster, $frame_w, $frame_y);
        }
        return $result;
    }

    private function make_synthetic_section(array $cluster, float $frame_w, float $frame_y): array
    {
        $items  = $cluster['items'];
        $y_abs  = (float) $cluster['y'];
        $y_end  = (float) $cluster['y_end'];
        $height = max($y_end - $y_abs, (float) self::MIN_SECTION_HEIGHT);
        $width  = max((float) $cluster['max_w'], $frame_w > 0 ? $frame_w : 1200.0);

        // Name: use the tallest element
        $best_node = $items[0]['node'];
        $best_h    = 0;
        foreach ($items as $item) {
            $h = (float) ($item['node']['layout']['height'] ?? 0);
            if ($h > $best_h) {
                $best_h    = $h;
                $best_node = $item['node'];
            }
        }
        $name = as_str($best_node['name'] ?? 'Section');

        // Extract a background from the cluster if any item has one.
        $bg_color = '';
        $bg_ref   = '';
        $bg_grad  = null;
        foreach ($items as $item) {
            $n = $item['node'];
            if ($bg_ref === '' && as_str($n['background_image_ref'] ?? '') !== '') {
                $bg_ref = as_str($n['background_image_ref']);
            }
            if ($bg_grad === null && ($n['background_gradient'] ?? null) !== null) {
                $bg_grad = $n['background_gradient'];
            }
            if ($bg_color === '' && as_str($n['background'] ?? '') !== '') {
                $bg_color = as_str($n['background']);
            }
        }

        // Determine background type for debug panel.
        if ($bg_ref !== '') {
            $bg_type = 'image';
        } elseif ($bg_grad !== null) {
            $bg_type = 'gradient';
        } elseif ($bg_color !== '') {
            $bg_type = 'solid';
        } else {
            $bg_type = 'none';
        }

        // Wrap all orphan nodes into a synthetic container so the builder
        // can treat this cluster like a regular section (one root child).
        $child_nodes = array_map(static fn($i) => $i['node'], $items);

        $synthetic_container = [
            'type'                 => 'container',
            'figma_id'             => '',
            'figma_type'           => 'SYNTHETIC',
            'name'                 => $name,
            'layout'               => [
                'is_auto_layout' => false,
                'direction'      => '',
                'gap'            => null,
                'padding'        => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0],
                'primary_axis'   => '',
                'counter_axis'   => '',
                'positioning'    => '',
                'x'              => 0.0,
                'y'              => $y_abs,
                'width'          => $width,
                'height'         => $height,
            ],
            'opacity'              => 1.0,
            'background'           => $bg_color,
            'background_image_ref' => $bg_ref,
            'background_gradient'  => $bg_grad,
            'border_radius'        => null,
            'is_root'              => false,
            'children'             => $child_nodes,
        ];

        return [
            'index'           => 0,   // will be re-numbered in detect()
            'name'            => $name,
            'y'               => max(0.0, $y_abs - $frame_y),
            'y_abs'           => $y_abs,
            'height'          => $height,
            'width'           => $width,
            'background'      => $bg_color,
            'background_type' => $bg_type,
            'figma_type'      => 'SYNTHETIC',
            'is_auto_layout'  => false,
            'element_count'   => count($child_nodes),
            'children'        => [$synthetic_container],
            'extra_children'  => [],
            'node_role'       => 'synthetic_section',
            'score'           => 0,
            'score_reasons'   => ['Clustered from ' . count($items) . ' orphan node(s)'],
        ];
    }

    // =========================================================================
    // Anchor → SectionModel
    // =========================================================================

    private function build_anchor_model(
        int    $index,
        array  $node,
        float  $frame_y,
        float  $frame_w,
        ?array $classified = null
    ): array {
        $h     = (float) ($node['layout']['height'] ?? 0);
        $w     = (float) ($node['layout']['width']  ?? 0);
        $y_abs = (float) ($node['layout']['y']      ?? 0);

        $background = as_str($node['background'] ?? '');
        $bg_ref     = as_str($node['background_image_ref'] ?? '');
        $bg_grad    = $node['background_gradient'] ?? null;

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
            'index'           => $index,
            'name'            => as_str($node['name'] ?? ('Section ' . ($index + 1))),
            'y'               => max(0.0, $y_abs - $frame_y),
            'y_abs'           => $y_abs,
            'height'          => $h,
            'width'           => $w,
            'background'      => $background,
            'background_type' => $bg_type,
            'figma_type'      => as_str($node['figma_type'] ?? $node['type'] ?? ''),
            'is_auto_layout'  => (bool) ($node['layout']['is_auto_layout'] ?? false),
            'element_count'   => $this->count_elements($node),
            'children'        => [$node],
            'extra_children'  => $classified['attached'] ?? [],
            'node_role'       => 'main_section',
            'score'           => $classified['score'] ?? 0,
            'score_reasons'   => $classified['reasons'] ?? [],
        ];
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    private function get_frame_container(array $tree): ?array
    {
        foreach (($tree['children'] ?? []) as $root) {
            if (is_array($root)) {
                return $root;
            }
        }
        return null;
    }
}
