<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Converts an intermediate-tree node (or section slice) into Elementor Atomic JSON.
 *
 * KEY FIXES:
 *  1. Children of flex-row containers get PERCENTAGE widths (not px) so columns render side-by-side.
 *  2. min_height is only applied to:
 *       - The section root container (top-most, is_root=true).
 *       - Childless visual containers (pure shapes / background-image placeholders).
 *     Inner layout wrappers get NO min_height — Elementor sizes them by content.
 *  3. Absolute-positioned elements receive PARENT-RELATIVE offsets.
 *     We pass the parent's absolute canvas position down the call chain.
 *  4. Basic responsive generation: flex-row containers become flex-column on tablet/mobile.
 */
final class Elementor_Atomic_Builder
{
    /** @var array<string, array{id:int,url:string,inline_svg:string}> */
    private array $images = [];

    /** @var array<string, string>  hex → global-color slug */
    private array $color_map = [];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Build Elementor elements from the full intermediate tree.
     */
    public function build(array $tree, array $images = [], array $color_map = []): array
    {
        $this->images    = $images;
        $this->color_map = $color_map;
        $elements        = [];

        foreach (($tree['children'] ?? []) as $child) {
            if (is_array($child)) {
                $cx = (float) ($child['layout']['x'] ?? 0);
                $cy = (float) ($child['layout']['y'] ?? 0);
                $elements[] = $this->node($child, true, 0, $cx, $cy);
            }
        }

        return $elements;
    }

    /**
     * Build Elementor elements from one section slice.
     * Each section has exactly ONE top-level child (the Figma section container).
     */
    public function build_section(array $section, array $images = [], array $color_map = []): array
    {
        $this->images    = $images;
        $this->color_map = $color_map;
        $elements        = [];

        foreach (($section['children'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }
            // Pass the child's own absolute position so its children can compute relative offsets.
            $cx = (float) ($child['layout']['x'] ?? 0);
            $cy = (float) ($child['layout']['y'] ?? 0);
            $elements[] = $this->node($child, true, 0, $cx, $cy);
        }

        return $elements;
    }

    // -------------------------------------------------------------------------
    // Node dispatch
    // -------------------------------------------------------------------------

    /**
     * @param float $parent_width   pixel width of the parent — 0 means "unknown / full width"
     * @param float $parent_x       absolute canvas X of the parent (for relative-offset calculation)
     * @param float $parent_y       absolute canvas Y of the parent
     */
    private function node(array $item, bool $is_root, float $parent_width, float $parent_x, float $parent_y): array
    {
        switch ($item['type'] ?? '') {
            case 'container': return $this->container($item, $is_root, $parent_width, $parent_x, $parent_y);
            case 'heading':   return $this->heading_widget($item, $parent_width, $parent_x, $parent_y);
            case 'text':      return $this->text_widget($item, $parent_width, $parent_x, $parent_y);
            case 'button':    return $this->button_widget($item, $parent_width, $parent_x, $parent_y);
            case 'image':     return $this->image_node($item, $parent_width, $parent_x, $parent_y);
            case 'shape':
            default:          return $this->shape_node($item, $is_root, $parent_width, $parent_x, $parent_y);
        }
    }

    // -------------------------------------------------------------------------
    // Container
    // -------------------------------------------------------------------------

    private function container(array $item, bool $is_root, float $parent_width, float $parent_x, float $parent_y): array
    {
        $layout      = is_array($item['layout'] ?? null) ? $item['layout'] : [];
        $self_width  = (float) ($layout['width'] ?? 0);
        $self_height = (float) ($layout['height'] ?? 0);
        $self_x      = (float) ($layout['x'] ?? 0);
        $self_y      = (float) ($layout['y'] ?? 0);
        $direction   = as_str($layout['direction'] ?? '');
        $is_row      = ('row' === $direction);

        // Children of a flex-row get THIS container's width so they can compute % share.
        $children_el = [];
        foreach (($item['children'] ?? []) as $child) {
            if (is_array($child)) {
                $children_el[] = $this->node(
                    $child,
                    false,
                    $is_row ? $self_width : 0,
                    $self_x,
                    $self_y
                );
            }
        }

        $settings = $this->container_settings(
            $item, $is_root, $parent_width,
            $self_width, $self_height,
            $self_x, $self_y,
            $parent_x, $parent_y
        );

        return [
            'id'       => make_id(),
            'elType'   => 'container',
            'settings' => $settings,
            'elements' => $children_el,
            'isInner'  => ! $is_root,
        ];
    }

    /**
     * @param float $self_x   this element's absolute canvas X
     * @param float $self_y   this element's absolute canvas Y
     */
    private function container_settings(
        array $item,
        bool  $is_root,
        float $parent_width,
        float $self_width,
        float $self_height,
        float $self_x,
        float $self_y,
        float $parent_x,
        float $parent_y
    ): array {
        $layout      = is_array($item['layout'] ?? null) ? $item['layout'] : [];
        $direction   = as_str($layout['direction'] ?? '');
        $is_absolute = (as_str($layout['positioning'] ?? '') === 'ABSOLUTE');
        $has_children = ! empty($item['children']);

        $settings = [
            'content_width'  => 'full',
            'flex_direction' => ('row' === $direction) ? 'row' : 'column',
        ];

        // Flex alignment
        $justify = map_align(as_str($layout['primary_axis'] ?? ''));
        if ($justify !== '') {
            $settings['flex_justify_content'] = $justify;
        }
        $align = map_align(as_str($layout['counter_axis'] ?? ''));
        if ($align !== '') {
            $settings['flex_align_items'] = $align;
        }

        // Gap
        if (! empty($layout['gap'])) {
            $settings['gap'] = ['unit' => 'px', 'size' => (float) $layout['gap'], 'sizes' => []];
        }

        // Padding
        $pad = is_array($layout['padding'] ?? null) ? $layout['padding'] : [];
        $pt = (float) ($pad['top'] ?? 0);
        $pr = (float) ($pad['right'] ?? 0);
        $pb = (float) ($pad['bottom'] ?? 0);
        $pl = (float) ($pad['left'] ?? 0);
        if ($pt > 0 || $pr > 0 || $pb > 0 || $pl > 0) {
            $settings['padding'] = [
                'unit'     => 'px',
                'top'      => (string) $pt,
                'right'    => (string) $pr,
                'bottom'   => (string) $pb,
                'left'     => (string) $pl,
                'isLinked' => false,
            ];
        }

        // ---- WIDTH ----
        if ($is_absolute) {
            // Absolute elements get pixel width.
            if ($self_width > 0) {
                $settings['width'] = ['unit' => 'px', 'size' => $self_width, 'sizes' => []];
            }
        } elseif (! $is_root && $parent_width > 0 && $self_width > 0) {
            // Non-root inside flex-row: percentage width.
            $pct = max(1.0, min(100.0, round(($self_width / $parent_width) * 100, 2)));
            $settings['width'] = ['unit' => '%', 'size' => $pct, 'sizes' => []];
        }

        // ---- MIN-HEIGHT ----
        // Only set min_height for:
        //   a) Section root (top-level container in build_section).
        //   b) Childless containers (pure visual shapes / backgrounds).
        // Inner layout wrappers grow with their content — no explicit min_height.
        if ($is_root && $self_height > 0) {
            $settings['min_height'] = [
                'unit'  => 'px',
                'size'  => $self_height,   // no artificial cap — honour the Figma height
                'sizes' => [],
            ];
        } elseif (! $is_root && ! $has_children && $self_height >= 20) {
            $settings['min_height'] = [
                'unit'  => 'px',
                'size'  => min(4000, $self_height),
                'sizes' => [],
            ];
        }

        // ---- ABSOLUTE POSITIONING ----
        if ($is_absolute) {
            $settings['_position'] = 'absolute';
            // Offset = self_absolute - parent_absolute → parent-relative coordinates.
            $rel_x = $self_x - $parent_x;
            $rel_y = $self_y - $parent_y;
            if (abs($rel_x) > 0.01) {
                $settings['_offset_x'] = ['unit' => 'px', 'size' => $rel_x, 'sizes' => []];
            }
            if (abs($rel_y) > 0.01) {
                $settings['_offset_y'] = ['unit' => 'px', 'size' => $rel_y, 'sizes' => []];
            }
        }

        // ---- BACKGROUND ----
        $this->apply_background($settings, $item);

        // ---- BORDER RADIUS ----
        $this->apply_border_radius_to_container($settings, $item);

        // ---- RESPONSIVE (generate if no explicit tablet/mobile frames) ----
        if ('row' === $direction) {
            // Stack columns on tablet and mobile.
            $settings['flex_direction_tablet'] = 'column';
            $settings['flex_direction_mobile'] = 'column';
        }

        return $settings;
    }

    // -------------------------------------------------------------------------
    // Background
    // -------------------------------------------------------------------------

    private function apply_background(array &$settings, array $item): void
    {
        $bg_ref      = as_str($item['background_image_ref'] ?? '');
        $bg_gradient = is_array($item['background_gradient'] ?? null) ? $item['background_gradient'] : null;
        $bg_color    = as_str($item['background'] ?? '');

        if ($bg_ref !== '') {
            $img = $this->images['fillref:' . $bg_ref] ?? null;
            if ($img && (! empty($img['url']) || ! empty($img['id']))) {
                $settings['background_background'] = 'classic';
                $settings['background_image']      = [
                    'url' => as_str($img['url'] ?? ''),
                    'id'  => (int) ($img['id'] ?? 0),
                ];
                $settings['background_size']     = 'cover';
                $settings['background_position'] = 'center center';
                $settings['background_repeat']   = 'no-repeat';
                if ($bg_color !== '') {
                    $settings['background_color'] = $this->resolve_color($bg_color);
                }
                return;
            }
            // Image not available — fall through to solid color.
            if ($bg_color !== '') {
                $settings['background_background'] = 'classic';
                $settings['background_color']      = $this->resolve_color($bg_color);
                return;
            }
        }

        if ($bg_gradient !== null) {
            $g = as_str($bg_gradient['gradient_type'] ?? 'linear');
            $settings['background_background']    = 'gradient';
            $settings['background_gradient_type'] = $g;
            $settings['background_color']         = $this->resolve_color(as_str($bg_gradient['color_a'] ?? '#000000'));
            $settings['background_color_stop']    = ['unit' => '%', 'size' => (float) ($bg_gradient['stop_a'] ?? 0), 'sizes' => []];
            $settings['background_color_b']       = $this->resolve_color(as_str($bg_gradient['color_b'] ?? '#000000'));
            $settings['background_color_b_stop']  = ['unit' => '%', 'size' => (float) ($bg_gradient['stop_b'] ?? 100), 'sizes' => []];
            if ('linear' === $g) {
                $settings['background_gradient_angle'] = ['unit' => 'deg', 'size' => (int) ($bg_gradient['angle'] ?? 180), 'sizes' => []];
            }
            return;
        }

        if ($bg_color !== '') {
            $settings['background_background'] = 'classic';
            $settings['background_color']      = $this->resolve_color($bg_color);
        }
    }

    private function apply_border_radius_to_container(array &$settings, array $item): void
    {
        $br = is_array($item['border_radius'] ?? null) ? $item['border_radius'] : null;
        if (! $br) {
            return;
        }
        $tl = (float) ($br['tl'] ?? 0);
        $tr = (float) ($br['tr'] ?? 0);
        $brv = (float) ($br['br'] ?? 0);
        $bl = (float) ($br['bl'] ?? 0);

        $settings['border_radius'] = [
            'unit'     => 'px',
            'top'      => (string) $tl,
            'right'    => (string) $tr,
            'bottom'   => (string) $brv,
            'left'     => (string) $bl,
            'isLinked' => ($tl === $tr && $tl === $brv && $tl === $bl),
        ];
    }

    // -------------------------------------------------------------------------
    // Widgets
    // -------------------------------------------------------------------------

    private function heading_widget(array $item, float $parent_width, float $parent_x, float $parent_y): array
    {
        $settings = [
            'title'       => esc_html(as_str($item['content'] ?? '')),
            'header_size' => $this->choose_heading_tag($item),
            'align'       => $this->map_text_align(as_str($item['text_align'] ?? '')),
        ];
        $this->apply_typography($settings, $item);
        $this->apply_text_color($settings, $item);
        $this->apply_widget_width($settings, $item, $parent_width);
        $this->apply_widget_absolute($settings, $item, $parent_x, $parent_y);

        return $this->widget('heading', $settings);
    }

    private function text_widget(array $item, float $parent_width, float $parent_x, float $parent_y): array
    {
        $settings = [
            'editor' => wp_kses_post(nl2br(as_str($item['content'] ?? ''))),
        ];
        $this->apply_typography($settings, $item);
        $this->apply_text_color($settings, $item);
        $this->apply_widget_width($settings, $item, $parent_width);
        $this->apply_widget_absolute($settings, $item, $parent_x, $parent_y);

        return $this->widget('text-editor', $settings);
    }

    private function button_widget(array $item, float $parent_width, float $parent_x, float $parent_y): array
    {
        $settings = [
            'text' => esc_html(as_str($item['content'] ?? __('Button', 'ftea'))),
            'link' => ['url' => '#'],
        ];
        if (! empty($item['background'])) {
            $settings['background_color'] = $this->resolve_color(as_str($item['background']));
        }
        $this->apply_typography($settings, $item);
        $this->apply_text_color($settings, $item);
        $this->apply_widget_width($settings, $item, $parent_width);
        $this->apply_widget_absolute($settings, $item, $parent_x, $parent_y);

        return $this->widget('button', $settings);
    }

    private function image_node(array $item, float $parent_width, float $parent_x, float $parent_y): array
    {
        $figma_id = as_str($item['figma_id'] ?? '');
        $image    = ($figma_id && isset($this->images[$figma_id]))
            ? $this->images[$figma_id]
            : ['id' => 0, 'url' => '', 'inline_svg' => ''];

        // SVG inline → html widget
        if (! empty($image['inline_svg'])) {
            $settings = ['html' => $image['inline_svg']];
            $this->apply_widget_border_radius($settings, $item);
            $this->apply_widget_width($settings, $item, $parent_width);
            $this->apply_widget_absolute($settings, $item, $parent_x, $parent_y);
            return $this->widget('html', $settings);
        }

        // Raster image → image widget
        if (! empty($image['url']) || ! empty($image['id'])) {
            $settings = [
                'image'          => ['url' => as_str($image['url'] ?? ''), 'id' => (int) ($image['id'] ?? 0)],
                'image_size'     => 'full',
                'caption_source' => 'none',
            ];
            $this->apply_widget_border_radius($settings, $item);
            $this->apply_widget_width($settings, $item, $parent_width);
            $this->apply_widget_absolute($settings, $item, $parent_x, $parent_y);
            return $this->widget('image', $settings);
        }

        // Fallback: a visible placeholder container so the slot isn't invisible.
        return $this->image_fallback($item, $parent_width, $parent_x, $parent_y);
    }

    private function image_fallback(array $item, float $parent_width, float $parent_x, float $parent_y): array
    {
        $layout     = is_array($item['layout'] ?? null) ? $item['layout'] : [];
        $self_width = (float) ($layout['width'] ?? 0);
        $self_height= (float) ($layout['height'] ?? 0);
        $self_x     = (float) ($layout['x'] ?? 0);
        $self_y     = (float) ($layout['y'] ?? 0);

        $settings = [
            'content_width'         => 'full',
            'flex_direction'        => 'column',
            'background_background' => 'classic',
            'background_color'      => '#e8e8e8',
        ];

        if ($parent_width > 0 && $self_width > 0) {
            $pct = max(1.0, min(100.0, round(($self_width / $parent_width) * 100, 2)));
            $settings['width'] = ['unit' => '%', 'size' => $pct, 'sizes' => []];
        }
        if ($self_height >= 20) {
            $settings['min_height'] = ['unit' => 'px', 'size' => min(4000, $self_height), 'sizes' => []];
        }

        $this->apply_border_radius_to_container($settings, $item);

        if (as_str($item['layout']['positioning'] ?? '') === 'ABSOLUTE') {
            $settings['_position'] = 'absolute';
            $rel_x = $self_x - $parent_x;
            $rel_y = $self_y - $parent_y;
            if (abs($rel_x) > 0.01) {
                $settings['_offset_x'] = ['unit' => 'px', 'size' => $rel_x, 'sizes' => []];
            }
            if (abs($rel_y) > 0.01) {
                $settings['_offset_y'] = ['unit' => 'px', 'size' => $rel_y, 'sizes' => []];
            }
        }

        return ['id' => make_id(), 'elType' => 'container', 'settings' => $settings, 'elements' => [], 'isInner' => true];
    }

    private function shape_node(array $item, bool $is_root, float $parent_width, float $parent_x, float $parent_y): array
    {
        $layout     = is_array($item['layout'] ?? null) ? $item['layout'] : [];
        $self_width = (float) ($layout['width'] ?? 0);
        $self_height= (float) ($layout['height'] ?? 0);
        $self_x     = (float) ($layout['x'] ?? 0);
        $self_y     = (float) ($layout['y'] ?? 0);

        $settings = ['content_width' => 'full', 'flex_direction' => 'column'];
        $this->apply_background($settings, $item);
        $this->apply_border_radius_to_container($settings, $item);

        if (! $is_root && $parent_width > 0 && $self_width > 0) {
            $pct = max(1.0, min(100.0, round(($self_width / $parent_width) * 100, 2)));
            $settings['width'] = ['unit' => '%', 'size' => $pct, 'sizes' => []];
        }
        if ($self_height >= 20) {
            $settings['min_height'] = ['unit' => 'px', 'size' => min(4000, $self_height), 'sizes' => []];
        }

        if (as_str($layout['positioning'] ?? '') === 'ABSOLUTE') {
            $settings['_position'] = 'absolute';
            $rel_x = $self_x - $parent_x;
            $rel_y = $self_y - $parent_y;
            if (abs($rel_x) > 0.01) {
                $settings['_offset_x'] = ['unit' => 'px', 'size' => $rel_x, 'sizes' => []];
            }
            if (abs($rel_y) > 0.01) {
                $settings['_offset_y'] = ['unit' => 'px', 'size' => $rel_y, 'sizes' => []];
            }
        }

        return ['id' => make_id(), 'elType' => 'container', 'settings' => $settings, 'elements' => [], 'isInner' => ! $is_root];
    }

    // -------------------------------------------------------------------------
    // Widget wrapper
    // -------------------------------------------------------------------------

    private function widget(string $type, array $settings): array
    {
        return [
            'id'         => make_id(),
            'elType'     => 'widget',
            'settings'   => $settings,
            'elements'   => [],
            'widgetType' => $type,
        ];
    }

    // -------------------------------------------------------------------------
    // Typography / color / layout helpers
    // -------------------------------------------------------------------------

    private function apply_typography(array &$settings, array $item): void
    {
        $has = ! empty($item['font_size'])
            || ! empty($item['font_weight'])
            || ! empty($item['line_height'])
            || ! empty($item['font_family'])
            || ! empty($item['letter_spacing']);

        if (! $has) {
            return;
        }

        $settings['typography_typography'] = 'custom';

        if (! empty($item['font_family'])) {
            $settings['typography_font_family'] = as_str($item['font_family']);
        }
        if (! empty($item['font_size'])) {
            $fs = (float) $item['font_size'];
            $settings['typography_font_size']        = ['unit' => 'px', 'size' => $fs, 'sizes' => []];
            $settings['typography_font_size_tablet'] = ['unit' => 'px', 'size' => round($fs * 0.85), 'sizes' => []];
            $settings['typography_font_size_mobile'] = ['unit' => 'px', 'size' => round($fs * 0.75), 'sizes' => []];
        }
        if (! empty($item['font_weight'])) {
            $settings['typography_font_weight'] = (string) ((int) $item['font_weight']);
        }
        if (! empty($item['line_height'])) {
            $settings['typography_line_height'] = ['unit' => 'px', 'size' => (float) $item['line_height'], 'sizes' => []];
        }
        if (! empty($item['letter_spacing'])) {
            $settings['typography_letter_spacing'] = ['unit' => 'px', 'size' => (float) $item['letter_spacing'], 'sizes' => []];
        }
    }

    private function apply_text_color(array &$settings, array $item): void
    {
        $color = as_str($item['text_color'] ?? '');
        if ($color !== '') {
            $settings['text_color'] = $this->resolve_color($color);
        }
    }

    /**
     * Set the widget's inline width when it lives inside a flex-row parent.
     */
    private function apply_widget_width(array &$settings, array $item, float $parent_width): void
    {
        if ($parent_width <= 0) {
            return;
        }
        $w = (float) ($item['layout']['width'] ?? 0);
        if ($w <= 0) {
            return;
        }
        $pct = max(1.0, min(100.0, round(($w / $parent_width) * 100, 2)));
        $settings['_element_width']        = 'initial';
        $settings['_element_custom_width'] = ['unit' => '%', 'size' => $pct, 'sizes' => []];
    }

    /**
     * Apply absolute positioning to a widget (Advanced tab).
     */
    private function apply_widget_absolute(array &$settings, array $item, float $parent_x, float $parent_y): void
    {
        if (as_str($item['layout']['positioning'] ?? '') !== 'ABSOLUTE') {
            return;
        }
        $self_x = (float) ($item['layout']['x'] ?? 0);
        $self_y = (float) ($item['layout']['y'] ?? 0);
        $rel_x  = $self_x - $parent_x;
        $rel_y  = $self_y - $parent_y;

        $settings['_position'] = 'absolute';
        if (abs($rel_x) > 0.01) {
            $settings['_offset_x'] = ['unit' => 'px', 'size' => $rel_x, 'sizes' => []];
        }
        if (abs($rel_y) > 0.01) {
            $settings['_offset_y'] = ['unit' => 'px', 'size' => $rel_y, 'sizes' => []];
        }
    }

    private function apply_widget_border_radius(array &$settings, array $item): void
    {
        $br = is_array($item['border_radius'] ?? null) ? $item['border_radius'] : null;
        if (! $br) {
            return;
        }
        $tl  = (float) ($br['tl'] ?? 0);
        $tr  = (float) ($br['tr'] ?? 0);
        $brv = (float) ($br['br'] ?? 0);
        $bl  = (float) ($br['bl'] ?? 0);

        $settings['_border_radius'] = [
            'unit'     => 'px',
            'top'      => (string) $tl,
            'right'    => (string) $tr,
            'bottom'   => (string) $brv,
            'left'     => (string) $bl,
            'isLinked' => ($tl === $tr && $tl === $brv && $tl === $bl),
        ];
    }

    private function resolve_color(string $hex): string
    {
        $hex = strtolower(trim($hex));
        if (isset($this->color_map[$hex])) {
            return 'var(--e-global-color-' . $this->color_map[$hex] . ')';
        }
        return $hex;
    }

    private function map_text_align(string $fa): string
    {
        return ['LEFT' => 'left', 'CENTER' => 'center', 'RIGHT' => 'right', 'JUSTIFIED' => 'justify'][$fa] ?? 'left';
    }

    private function choose_heading_tag(array $item): string
    {
        $fs = (float) ($item['font_size'] ?? 0);
        if ($fs >= 48) return 'h1';
        if ($fs >= 36) return 'h2';
        if ($fs >= 28) return 'h3';
        if ($fs >= 22) return 'h4';
        return 'h2';
    }
}
