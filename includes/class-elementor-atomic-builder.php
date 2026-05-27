<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Converts the intermediate tree into Elementor Atomic JSON.
 *
 * KEY BEHAVIOUR:
 *
 * Auto-layout containers (layout.is_auto_layout = true)
 *   → flex-direction row or column
 *   → row children get percentage widths
 *   → only explicitly ABSOLUTE-positioned children use absolute offsets
 *
 * Non-auto-layout containers (layout.is_auto_layout = false)
 *   → ALL children are forced to position:absolute with parent-relative offsets
 *   → Parent gets min_height equal to the Figma frame height (prevents collapse)
 *   → Children get explicit pixel width + height
 *
 * This matches how Figma itself positions children of plain FRAMEs and GROUPs
 * that have no layoutMode: every child is placed via its absoluteBoundingBox.
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

    public function build(array $tree, array $images = [], array $color_map = []): array
    {
        $this->images    = $images;
        $this->color_map = $color_map;
        $elements        = [];

        foreach (($tree['children'] ?? []) as $child) {
            if (is_array($child)) {
                $cx = (float) ($child['layout']['x'] ?? 0);
                $cy = (float) ($child['layout']['y'] ?? 0);
                $elements[] = $this->node($child, true, 0.0, $cx, $cy, false);
            }
        }

        return $elements;
    }

    /**
     * Build Elementor elements from one section slice.
     *
     * For VISUAL_SECTION nodes (from Visual_Section_Detector): produces a two-level
     * structure — outer full-width container (background) + inner canvas (frame_width,
     * position:relative) with all children as position:absolute inside it.
     *
     * For legacy hierarchy sections: each child is treated as a root section.
     */
    public function build_section(array $section, array $images = [], array $color_map = []): array
    {
        $this->images    = $images;
        $this->color_map = $color_map;

        if ('VISUAL_SECTION' === as_str($section['figma_type'] ?? '')) {
            return [$this->root_section($section)];
        }

        // Legacy fallback: each direct child becomes its own root element.
        $elements = [];
        foreach (($section['children'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }
            $cx = (float) ($child['layout']['x'] ?? 0);
            $cy = (float) ($child['layout']['y'] ?? 0);
            $elements[] = $this->node($child, true, 0.0, $cx, $cy, false);
        }
        return $elements;
    }

    /**
     * Two-level structure for a visual section:
     *   Outer: full-width container, carries background, centers the inner canvas.
     *   Inner canvas: fixed pixel width = frame width, position:relative, coordinate origin.
     *   Children: all position:absolute, offsets relative to (frame_x, section_y_abs).
     */
    private function root_section(array $section): array
    {
        $layout    = is_array($section['layout'] ?? null) ? $section['layout'] : [];
        $frame_x   = (float) ($layout['x']      ?? 0);
        $section_y = (float) ($layout['y']      ?? 0);
        $frame_w   = (float) ($layout['width']  ?? 0);
        $section_h = (float) ($layout['height'] ?? 0);

        // ---- Inner canvas children (all absolutely positioned) ----
        $inner_children = [];
        foreach (($section['children'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }
            $inner_children[] = $this->node($child, false, $frame_w, $frame_x, $section_y, true);
        }

        // ---- Inner canvas ----
        $inner_settings = [
            'content_width'  => 'full',
            'flex_direction' => 'column',
        ];
        if ($frame_w > 0) {
            $inner_settings['width'] = ['unit' => 'px', 'size' => $frame_w, 'sizes' => []];
        }
        if ($section_h > 0) {
            $inner_settings['min_height'] = ['unit' => 'px', 'size' => $section_h, 'sizes' => []];
        }

        $inner_canvas = [
            'id'       => make_id(),
            'elType'   => 'container',
            'settings' => $inner_settings,
            'elements' => $inner_children,
            'isInner'  => true,
        ];

        // ---- Outer section (full-width, carries background, centers inner canvas) ----
        $outer_settings = [
            'content_width'        => 'full',
            'flex_direction'       => 'row',
            'flex_justify_content' => 'center',
            'flex_align_items'     => 'flex-start',
        ];
        if ($section_h > 0) {
            $outer_settings['min_height'] = ['unit' => 'px', 'size' => $section_h, 'sizes' => []];
        }
        $this->apply_background($outer_settings, $section);

        return [
            'id'       => make_id(),
            'elType'   => 'container',
            'settings' => $outer_settings,
            'elements' => [$inner_canvas],
            'isInner'  => false,
        ];
    }

    // -------------------------------------------------------------------------
    // Node dispatch
    // -------------------------------------------------------------------------

    /**
     * @param float $parent_width   pixel width of the parent container
     * @param float $parent_x       absolute canvas X of the parent
     * @param float $parent_y       absolute canvas Y of the parent
     * @param bool  $force_absolute force this node to be position:absolute even if
     *                              layoutPositioning is not 'ABSOLUTE'
     */
    private function node(
        array $item,
        bool  $is_root,
        float $parent_width,
        float $parent_x,
        float $parent_y,
        bool  $force_absolute
    ): array {
        switch ($item['type'] ?? '') {
            case 'container':
                return $this->container($item, $is_root, $parent_width, $parent_x, $parent_y, $force_absolute);
            case 'heading':
                return $this->heading_widget($item, $parent_width, $parent_x, $parent_y, $force_absolute);
            case 'text':
                return $this->text_widget($item, $parent_width, $parent_x, $parent_y, $force_absolute);
            case 'button':
                return $this->button_widget($item, $parent_width, $parent_x, $parent_y, $force_absolute);
            case 'image':
                return $this->image_node($item, $parent_width, $parent_x, $parent_y, $force_absolute);
            case 'shape':
            default:
                return $this->shape_node($item, $is_root, $parent_width, $parent_x, $parent_y, $force_absolute);
        }
    }

    // -------------------------------------------------------------------------
    // Container
    // -------------------------------------------------------------------------

    private function container(
        array $item,
        bool  $is_root,
        float $parent_width,
        float $parent_x,
        float $parent_y,
        bool  $force_absolute
    ): array {
        $layout         = is_array($item['layout'] ?? null) ? $item['layout'] : [];
        $self_width     = (float) ($layout['width']  ?? 0);
        $self_height    = (float) ($layout['height'] ?? 0);
        $self_x         = (float) ($layout['x'] ?? 0);
        $self_y         = (float) ($layout['y'] ?? 0);
        $is_auto_layout = (bool)  ($layout['is_auto_layout'] ?? false);
        $direction      = as_str($layout['direction'] ?? '');
        $is_row         = ('row' === $direction);

        // Children of a non-auto-layout container are all absolutely positioned.
        // Children of an auto-layout container follow flex rules.
        $force_children_absolute = ! $is_auto_layout;

        $children_el = [];
        foreach (($item['children'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }
            $children_el[] = $this->node(
                $child,
                false,
                $is_row ? $self_width : $self_width, // pass self_width always for absolute calc
                $self_x,
                $self_y,
                $force_children_absolute
            );
        }

        $settings = $this->container_settings(
            $item, $is_root,
            $parent_width, $self_width, $self_height,
            $self_x, $self_y,
            $parent_x, $parent_y,
            $force_absolute, $force_children_absolute
        );

        return [
            'id'       => make_id(),
            'elType'   => 'container',
            'settings' => $settings,
            'elements' => $children_el,
            'isInner'  => ! $is_root,
        ];
    }

    // -------------------------------------------------------------------------
    // Container settings
    // -------------------------------------------------------------------------

    private function container_settings(
        array $item,
        bool  $is_root,
        float $parent_width,
        float $self_width,
        float $self_height,
        float $self_x,
        float $self_y,
        float $parent_x,
        float $parent_y,
        bool  $force_absolute,
        bool  $has_absolute_children
    ): array {
        $layout         = is_array($item['layout'] ?? null) ? $item['layout'] : [];
        $direction      = as_str($layout['direction'] ?? '');
        $is_auto_layout = (bool) ($layout['is_auto_layout'] ?? false);
        $is_explicit_abs = (as_str($layout['positioning'] ?? '') === 'ABSOLUTE');
        $is_absolute    = $force_absolute || $is_explicit_abs;
        $has_children   = ! empty($item['children']);
        $is_row         = ('row' === $direction);

        $settings = [
            'content_width'  => 'full',
            'flex_direction' => $is_row ? 'row' : 'column',
        ];

        // ---- FLEX ALIGNMENT (auto-layout only) ----
        if ($is_auto_layout) {
            $justify = map_align(as_str($layout['primary_axis'] ?? ''));
            if ($justify !== '') {
                $settings['flex_justify_content'] = $justify;
            }
            $align = map_align(as_str($layout['counter_axis'] ?? ''));
            if ($align !== '') {
                $settings['flex_align_items'] = $align;
            }
            if (! empty($layout['gap'])) {
                $settings['gap'] = ['unit' => 'px', 'size' => (float) $layout['gap'], 'sizes' => []];
            }
        }

        // ---- PADDING ----
        $pad = is_array($layout['padding'] ?? null) ? $layout['padding'] : [];
        $pt  = (float) ($pad['top']    ?? 0);
        $pr  = (float) ($pad['right']  ?? 0);
        $pb  = (float) ($pad['bottom'] ?? 0);
        $pl  = (float) ($pad['left']   ?? 0);
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
        if ($is_absolute && $self_width > 0) {
            // Absolutely positioned: always pixel width.
            $settings['width'] = ['unit' => 'px', 'size' => $self_width, 'sizes' => []];
        } elseif (! $is_root && ! $is_absolute) {
            if ($is_auto_layout && $parent_width > 0 && $self_width > 0 && $is_row) {
                // Flex-row child: percentage width.
                $pct = max(1.0, min(100.0, round(($self_width / $parent_width) * 100, 2)));
                $settings['width'] = ['unit' => '%', 'size' => $pct, 'sizes' => []];
            }
            // Non-row / non-auto children default to 100 % (Elementor default).
        }

        // ---- HEIGHT / MIN_HEIGHT ----
        if ($is_absolute && $self_height > 0) {
            // Hard height for absolutely positioned containers.
            $settings['height'] = ['unit' => 'px', 'size' => $self_height, 'sizes' => []];
        } elseif ($is_root && $self_height > 0) {
            // Section root — honour Figma height as min-height.
            $settings['min_height'] = ['unit' => 'px', 'size' => $self_height, 'sizes' => []];
        } elseif (! $is_root && $has_absolute_children && $has_children && $self_height > 0) {
            // Non-auto-layout container with children: ALL children are absolute → parent
            // would collapse to zero without an explicit min_height.
            $settings['min_height'] = ['unit' => 'px', 'size' => $self_height, 'sizes' => []];
        } elseif (! $is_root && ! $has_children && $self_height >= 20) {
            // Childless visual container (background / shape placeholder).
            $settings['min_height'] = ['unit' => 'px', 'size' => min(4000, $self_height), 'sizes' => []];
        }

        // ---- ABSOLUTE POSITIONING ----
        if ($is_absolute) {
            $settings['_position'] = 'absolute';
            $rel_x = $self_x - $parent_x;
            $rel_y = $self_y - $parent_y;
            if (abs($rel_x) > 0.5) {
                $settings['_offset_x'] = ['unit' => 'px', 'size' => round($rel_x, 1), 'sizes' => []];
            }
            if (abs($rel_y) > 0.5) {
                $settings['_offset_y'] = ['unit' => 'px', 'size' => round($rel_y, 1), 'sizes' => []];
            }
        }

        // ---- OPACITY ----
        $opacity = (float) ($item['opacity'] ?? 1.0);
        if ($opacity < 0.99) {
            $settings['_opacity'] = ['unit' => 'px', 'size' => round($opacity * 100), 'sizes' => []];
        }

        // ---- BACKGROUND ----
        $this->apply_background($settings, $item);

        // ---- BORDER RADIUS ----
        $this->apply_border_radius_to_container($settings, $item);

        // ---- RESPONSIVE ----
        if ($is_auto_layout && $is_row) {
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
                $settings['background_size']       = 'cover';
                $settings['background_position']   = 'center center';
                $settings['background_repeat']     = 'no-repeat';
                if ($bg_color !== '') {
                    $settings['background_color'] = $this->resolve_color($bg_color);
                }
                return;
            }
            // CDN fetch failed — fall through to solid/gradient.
        }

        if ($bg_gradient !== null) {
            $g = as_str($bg_gradient['gradient_type'] ?? 'linear');
            $settings['background_background']    = 'gradient';
            $settings['background_gradient_type'] = $g;
            $settings['background_color']         = $this->resolve_color(as_str($bg_gradient['color_a'] ?? '#000000'));
            $settings['background_color_stop']    = ['unit' => '%', 'size' => (float) ($bg_gradient['stop_a'] ?? 0), 'sizes' => []];
            $settings['background_color_b']       = $this->resolve_color(as_str($bg_gradient['color_b'] ?? '#ffffff'));
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
        $tl  = (float) ($br['tl'] ?? 0);
        $tr  = (float) ($br['tr'] ?? 0);
        $brv = (float) ($br['br'] ?? 0);
        $bl  = (float) ($br['bl'] ?? 0);

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

    private function heading_widget(
        array $item,
        float $parent_width,
        float $parent_x,
        float $parent_y,
        bool  $force_absolute
    ): array {
        $settings = [
            'title'       => esc_html(as_str($item['content'] ?? '')),
            'header_size' => $this->choose_heading_tag($item),
            'align'       => $this->map_text_align(as_str($item['text_align'] ?? '')),
        ];
        $this->apply_typography($settings, $item);
        $this->apply_text_color($settings, $item);
        $this->apply_widget_size($settings, $item, $parent_width, $force_absolute);
        $this->apply_widget_absolute($settings, $item, $parent_x, $parent_y, $force_absolute);
        $this->apply_widget_opacity($settings, $item);
        return $this->widget('heading', $settings);
    }

    private function text_widget(
        array $item,
        float $parent_width,
        float $parent_x,
        float $parent_y,
        bool  $force_absolute
    ): array {
        $settings = [
            'editor' => wp_kses_post(nl2br(as_str($item['content'] ?? ''))),
        ];
        $this->apply_typography($settings, $item);
        $this->apply_text_color($settings, $item);
        $this->apply_widget_size($settings, $item, $parent_width, $force_absolute);
        $this->apply_widget_absolute($settings, $item, $parent_x, $parent_y, $force_absolute);
        $this->apply_widget_opacity($settings, $item);
        return $this->widget('text-editor', $settings);
    }

    private function button_widget(
        array $item,
        float $parent_width,
        float $parent_x,
        float $parent_y,
        bool  $force_absolute
    ): array {
        $settings = [
            'text' => esc_html(as_str($item['content'] ?? __('Button', 'ftea'))),
            'link' => ['url' => '#'],
        ];
        if (! empty($item['background'])) {
            $settings['background_color'] = $this->resolve_color(as_str($item['background']));
        }
        $this->apply_typography($settings, $item);
        $this->apply_text_color($settings, $item);
        $this->apply_widget_size($settings, $item, $parent_width, $force_absolute);
        $this->apply_widget_absolute($settings, $item, $parent_x, $parent_y, $force_absolute);
        $this->apply_widget_opacity($settings, $item);
        return $this->widget('button', $settings);
    }

    private function image_node(
        array $item,
        float $parent_width,
        float $parent_x,
        float $parent_y,
        bool  $force_absolute
    ): array {
        $figma_id = as_str($item['figma_id'] ?? '');
        $image    = ($figma_id && isset($this->images[$figma_id]))
            ? $this->images[$figma_id]
            : ['id' => 0, 'url' => '', 'inline_svg' => ''];

        // SVG inline → html widget.
        if (! empty($image['inline_svg'])) {
            $settings = ['html' => $image['inline_svg']];
            $this->apply_widget_border_radius($settings, $item);
            $this->apply_widget_size($settings, $item, $parent_width, $force_absolute);
            $this->apply_widget_absolute($settings, $item, $parent_x, $parent_y, $force_absolute);
            $this->apply_widget_opacity($settings, $item);
            return $this->widget('html', $settings);
        }

        // Raster image → image widget.
        if (! empty($image['url']) || $image['id'] > 0) {
            $settings = [
                'image'          => ['url' => as_str($image['url'] ?? ''), 'id' => (int) ($image['id'] ?? 0)],
                'image_size'     => 'full',
                'caption_source' => 'none',
            ];
            $this->apply_widget_border_radius($settings, $item);
            $this->apply_widget_size($settings, $item, $parent_width, $force_absolute);
            $this->apply_widget_absolute($settings, $item, $parent_x, $parent_y, $force_absolute);
            $this->apply_widget_opacity($settings, $item);
            return $this->widget('image', $settings);
        }

        // Fallback placeholder container.
        return $this->image_fallback($item, $parent_width, $parent_x, $parent_y, $force_absolute);
    }

    private function image_fallback(
        array $item,
        float $parent_width,
        float $parent_x,
        float $parent_y,
        bool  $force_absolute
    ): array {
        $layout      = is_array($item['layout'] ?? null) ? $item['layout'] : [];
        $self_width  = (float) ($layout['width']  ?? 0);
        $self_height = (float) ($layout['height'] ?? 0);
        $self_x      = (float) ($layout['x'] ?? 0);
        $self_y      = (float) ($layout['y'] ?? 0);

        $settings = [
            'content_width'         => 'full',
            'flex_direction'        => 'column',
            'background_background' => 'classic',
            'background_color'      => '#e8e8e8',
        ];

        $is_abs = $force_absolute || (as_str($layout['positioning'] ?? '') === 'ABSOLUTE');

        if ($is_abs && $self_width > 0) {
            $settings['width'] = ['unit' => 'px', 'size' => $self_width, 'sizes' => []];
        } elseif (! $is_abs && $parent_width > 0 && $self_width > 0) {
            $pct = max(1.0, min(100.0, round(($self_width / $parent_width) * 100, 2)));
            $settings['width'] = ['unit' => '%', 'size' => $pct, 'sizes' => []];
        }

        if ($is_abs && $self_height > 0) {
            $settings['height'] = ['unit' => 'px', 'size' => $self_height, 'sizes' => []];
        } elseif ($self_height >= 20) {
            $settings['min_height'] = ['unit' => 'px', 'size' => min(4000, $self_height), 'sizes' => []];
        }

        $this->apply_border_radius_to_container($settings, $item);

        if ($is_abs) {
            $settings['_position'] = 'absolute';
            $rel_x = $self_x - $parent_x;
            $rel_y = $self_y - $parent_y;
            if (abs($rel_x) > 0.5) {
                $settings['_offset_x'] = ['unit' => 'px', 'size' => round($rel_x, 1), 'sizes' => []];
            }
            if (abs($rel_y) > 0.5) {
                $settings['_offset_y'] = ['unit' => 'px', 'size' => round($rel_y, 1), 'sizes' => []];
            }
        }

        return ['id' => make_id(), 'elType' => 'container', 'settings' => $settings, 'elements' => [], 'isInner' => true];
    }

    private function shape_node(
        array $item,
        bool  $is_root,
        float $parent_width,
        float $parent_x,
        float $parent_y,
        bool  $force_absolute
    ): array {
        $layout      = is_array($item['layout'] ?? null) ? $item['layout'] : [];
        $self_width  = (float) ($layout['width']  ?? 0);
        $self_height = (float) ($layout['height'] ?? 0);
        $self_x      = (float) ($layout['x'] ?? 0);
        $self_y      = (float) ($layout['y'] ?? 0);

        $settings = ['content_width' => 'full', 'flex_direction' => 'column'];
        $this->apply_background($settings, $item);
        $this->apply_border_radius_to_container($settings, $item);

        $is_abs = $force_absolute || (as_str($layout['positioning'] ?? '') === 'ABSOLUTE');

        if ($is_abs && $self_width > 0) {
            $settings['width'] = ['unit' => 'px', 'size' => $self_width, 'sizes' => []];
        } elseif (! $is_root && ! $is_abs && $parent_width > 0 && $self_width > 0) {
            $pct = max(1.0, min(100.0, round(($self_width / $parent_width) * 100, 2)));
            $settings['width'] = ['unit' => '%', 'size' => $pct, 'sizes' => []];
        }

        if ($is_abs && $self_height > 0) {
            $settings['height'] = ['unit' => 'px', 'size' => $self_height, 'sizes' => []];
        } elseif ($self_height >= 20) {
            $settings['min_height'] = ['unit' => 'px', 'size' => min(4000, $self_height), 'sizes' => []];
        }

        if ($is_abs) {
            $settings['_position'] = 'absolute';
            $rel_x = $self_x - $parent_x;
            $rel_y = $self_y - $parent_y;
            if (abs($rel_x) > 0.5) {
                $settings['_offset_x'] = ['unit' => 'px', 'size' => round($rel_x, 1), 'sizes' => []];
            }
            if (abs($rel_y) > 0.5) {
                $settings['_offset_y'] = ['unit' => 'px', 'size' => round($rel_y, 1), 'sizes' => []];
            }
        }

        $opacity = (float) ($item['opacity'] ?? 1.0);
        if ($opacity < 0.99) {
            $settings['_opacity'] = ['unit' => 'px', 'size' => round($opacity * 100), 'sizes' => []];
        }

        return [
            'id'       => make_id(),
            'elType'   => 'container',
            'settings' => $settings,
            'elements' => [],
            'isInner'  => ! $is_root,
        ];
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
    // Shared widget helpers
    // -------------------------------------------------------------------------

    private function apply_typography(array &$settings, array $item): void
    {
        $has = ! empty($item['font_size'])
            || ! empty($item['font_weight'])
            || ! empty($item['line_height'])
            || ! empty($item['font_family'])
            || ! empty($item['letter_spacing'])
            || ! empty($item['text_case'])
            || ! empty($item['text_decoration']);

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

        // Text transform (Figma textCase → CSS text-transform)
        $text_case_map = [
            'UPPER'       => 'uppercase',
            'LOWER'       => 'lowercase',
            'TITLE'       => 'capitalize',
            'SMALL_CAPS'  => 'lowercase',
        ];
        $tc = as_str($item['text_case'] ?? '');
        if (isset($text_case_map[$tc])) {
            $settings['typography_text_transform'] = $text_case_map[$tc];
        }

        // Text decoration (Figma textDecoration → CSS text-decoration)
        $text_deco_map = [
            'UNDERLINE'      => 'underline',
            'STRIKETHROUGH'  => 'line-through',
        ];
        $td = as_str($item['text_decoration'] ?? '');
        if (isset($text_deco_map[$td])) {
            $settings['typography_text_decoration'] = $text_deco_map[$td];
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
     * Sets the widget's inline width.
     * - force_absolute: pixel width
     * - flex-row parent (parent_width > 0): percentage
     */
    private function apply_widget_size(array &$settings, array $item, float $parent_width, bool $force_absolute): void
    {
        $w = (float) ($item['layout']['width']  ?? 0);
        $h = (float) ($item['layout']['height'] ?? 0);

        if ($force_absolute) {
            if ($w > 0) {
                $settings['_element_width']        = 'initial';
                $settings['_element_custom_width'] = ['unit' => 'px', 'size' => $w, 'sizes' => []];
            }
            // Height is communicated via the widget wrapper when needed;
            // most Elementor widgets auto-size vertically, so we skip explicit height here.
            return;
        }

        if ($parent_width > 0 && $w > 0) {
            $pct = max(1.0, min(100.0, round(($w / $parent_width) * 100, 2)));
            $settings['_element_width']        = 'initial';
            $settings['_element_custom_width'] = ['unit' => '%', 'size' => $pct, 'sizes' => []];
        }
    }

    private function apply_widget_absolute(
        array &$settings,
        array $item,
        float $parent_x,
        float $parent_y,
        bool  $force_absolute
    ): void {
        $is_explicit = (as_str($item['layout']['positioning'] ?? '') === 'ABSOLUTE');
        if (! $force_absolute && ! $is_explicit) {
            return;
        }

        $self_x = (float) ($item['layout']['x'] ?? 0);
        $self_y = (float) ($item['layout']['y'] ?? 0);
        $rel_x  = $self_x - $parent_x;
        $rel_y  = $self_y - $parent_y;

        $settings['_position'] = 'absolute';
        if (abs($rel_x) > 0.5) {
            $settings['_offset_x'] = ['unit' => 'px', 'size' => round($rel_x, 1), 'sizes' => []];
        }
        if (abs($rel_y) > 0.5) {
            $settings['_offset_y'] = ['unit' => 'px', 'size' => round($rel_y, 1), 'sizes' => []];
        }
    }

    private function apply_widget_opacity(array &$settings, array $item): void
    {
        $opacity = (float) ($item['opacity'] ?? 1.0);
        if ($opacity < 0.99) {
            $settings['_opacity'] = ['unit' => 'px', 'size' => round($opacity * 100), 'sizes' => []];
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
