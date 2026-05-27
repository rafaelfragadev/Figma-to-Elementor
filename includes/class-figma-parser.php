<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Parses a raw Figma file JSON into an intermediate tree that retains
 * bounding boxes, auto-layout data, fills, and content for each node.
 */
final class Figma_Parser
{
    /** @var array{frames:int,texts:int,images:int,svgs:int,buttons:int,ignored:int} */
    private array $stats = [
        'frames'  => 0,
        'texts'   => 0,
        'images'  => 0,
        'svgs'    => 0,
        'buttons' => 0,
        'ignored' => 0,
    ];

    /** @return array<int, array{id:string,name:string,page:string}> */
    public function get_top_level_frames(array $figma_file): array
    {
        $document = $figma_file['document'] ?? [];
        $pages    = $document['children'] ?? [];
        $frames   = [];

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }
            foreach (($page['children'] ?? []) as $child) {
                if (
                    ! is_array($child)
                    || ($child['visible'] ?? true) === false
                    || ($child['type'] ?? '') !== 'FRAME'
                ) {
                    continue;
                }
                $frames[] = [
                    'id'   => as_str($child['id'] ?? ''),
                    'name' => get_node_name($child),
                    'page' => get_node_name($page),
                ];
            }
        }

        return $frames;
    }

    public function parse(array $figma_file, string $selected_frame_id): array
    {
        $this->reset_stats();
        $frame = $this->find_node_by_id($figma_file, $selected_frame_id);

        if (! $frame || ($frame['type'] ?? '') !== 'FRAME' || ($frame['visible'] ?? true) === false) {
            return ['type' => 'document', 'name' => '', 'children' => [], 'stats' => $this->stats];
        }

        $parsed = $this->parse_node($frame, true);

        return [
            'type'     => 'document',
            'name'     => get_node_name($frame),
            'children' => $parsed ? [$parsed] : [],
            'stats'    => $this->stats,
        ];
    }

    public function get_stats(): array
    {
        return $this->stats;
    }

    private function reset_stats(): void
    {
        $this->stats = [
            'frames'  => 0,
            'texts'   => 0,
            'images'  => 0,
            'svgs'    => 0,
            'buttons' => 0,
            'ignored' => 0,
        ];
    }

    private function parse_node(array $node, bool $is_root = false): ?array
    {
        if (($node['visible'] ?? true) === false) {
            $this->stats['ignored']++;
            return null;
        }

        $type = as_str($node['type'] ?? '');

        if ('TEXT' === $type) {
            return $this->parse_text($node);
        }

        if ('RECTANGLE' === $type) {
            if (has_image_fill($node)) {
                return $this->parse_image($node);
            }
            return $this->looks_like_icon($node) ? $this->parse_svg($node) : $this->parse_shape($node);
        }

        if (in_array($type, ['VECTOR', 'BOOLEAN_OPERATION', 'STAR', 'LINE', 'ELLIPSE', 'POLYGON'], true)) {
            return $this->parse_svg($node);
        }

        if (in_array($type, ['FRAME', 'GROUP', 'COMPONENT', 'INSTANCE'], true)) {
            return $this->parse_container($node, $is_root);
        }

        if (has_image_fill($node)) {
            return $this->parse_image($node);
        }

        $this->stats['ignored']++;
        return null;
    }

    private function parse_container(array $node, bool $is_root): ?array
    {
        if (! $is_root && $this->should_export_as_svg($node)) {
            return $this->parse_svg($node);
        }

        $children = [];
        foreach (($node['children'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }
            $parsed = $this->parse_node($child);
            if ($parsed) {
                $children[] = $parsed;
            }
        }

        $button = $this->detect_button($node, $children);
        if ($button) {
            return $button;
        }

        $this->stats['frames']++;

        return [
            'type'                 => 'container',
            'figma_id'             => as_str($node['id'] ?? ''),
            'figma_type'           => as_str($node['type'] ?? ''),
            'name'                 => get_node_name($node),
            'layout'               => extract_auto_layout($node),
            'background'           => extract_background_color($node),
            'background_image_ref' => extract_background_image_ref($node),
            'background_gradient'  => extract_gradient($node),
            'border_radius'        => extract_border_radius($node),
            'is_root'              => $is_root,
            'children'             => $children,
        ];
    }

    private function parse_text(array $node): array
    {
        $this->stats['texts']++;

        $style       = is_array($node['style'] ?? null) ? $node['style'] : [];
        $font_size   = isset($style['fontSize']) ? (float) $style['fontSize'] : null;
        $font_weight = isset($style['fontWeight']) ? (int) $style['fontWeight'] : null;
        $line_height = isset($style['lineHeightPx']) ? (float) $style['lineHeightPx'] : null;
        $letter_sp   = isset($style['letterSpacing']) ? (float) $style['letterSpacing'] : null;
        $text_align  = as_str($style['textAlignHorizontal'] ?? '');
        $is_heading  = $font_size !== null && ($font_size >= 24 || ($font_weight !== null && $font_weight >= 600));
        $text        = sanitize_textarea_field(as_str($node['characters'] ?? ''));

        return [
            'type'          => $is_heading ? 'heading' : 'text',
            'figma_id'      => as_str($node['id'] ?? ''),
            'name'          => get_node_name($node),
            'content'       => $text,
            'font_size'     => $font_size,
            'font_weight'   => $font_weight,
            'line_height'   => $line_height,
            'letter_spacing'=> $letter_sp,
            'text_align'    => $text_align,
            'font_family'   => is_string($style['fontFamily'] ?? null) ? $style['fontFamily'] : null,
            'text_color'    => extract_text_color($node),
            'layout'        => extract_auto_layout($node),
        ];
    }

    private function parse_image(array $node): array
    {
        $this->stats['images']++;

        return [
            'type'          => 'image',
            'asset_kind'    => 'image',
            'export_format' => 'png',
            'figma_id'      => as_str($node['id'] ?? ''),
            'name'          => get_node_name($node),
            'background'    => extract_background_color($node),
            'border_radius' => extract_border_radius($node),
            'layout'        => extract_auto_layout($node),
        ];
    }

    private function parse_svg(array $node): array
    {
        $this->stats['svgs']++;

        return [
            'type'          => 'image',
            'asset_kind'    => 'svg',
            'export_format' => 'svg',
            'figma_id'      => as_str($node['id'] ?? ''),
            'name'          => get_node_name($node),
            'background'    => extract_background_color($node),
            'border_radius' => extract_border_radius($node),
            'layout'        => extract_auto_layout($node),
        ];
    }

    private function parse_shape(array $node): array
    {
        return [
            'type'          => 'shape',
            'figma_id'      => as_str($node['id'] ?? ''),
            'name'          => get_node_name($node),
            'background'    => extract_background_color($node),
            'border_radius' => extract_border_radius($node),
            'layout'        => extract_auto_layout($node),
        ];
    }

    private function detect_button(array $node, array $children): ?array
    {
        if (count($children) !== 1 || ! in_array(($children[0]['type'] ?? ''), ['text', 'heading'], true)) {
            return null;
        }

        $text       = as_str($children[0]['content'] ?? '');
        $background = extract_background_color($node);
        $layout     = extract_auto_layout($node);
        $name       = get_node_name($node);

        if (! $this->looks_like_cta($text, $layout, $name, $background)) {
            return null;
        }

        $this->stats['buttons']++;
        $this->stats['texts'] = max(0, $this->stats['texts'] - 1);

        return [
            'type'        => 'button',
            'figma_id'    => as_str($node['id'] ?? ''),
            'name'        => $name,
            'content'     => sanitize_text_field($text),
            'background'  => $background,
            'text_color'  => as_str($children[0]['text_color'] ?? ''),
            'font_size'   => $children[0]['font_size'] ?? null,
            'font_weight' => $children[0]['font_weight'] ?? null,
            'layout'      => $layout,
        ];
    }

    private function looks_like_cta(string $text, array $layout, string $name, string $background): bool
    {
        $text   = trim($text);
        $len    = strlen($text);
        $width  = (float) ($layout['width'] ?? 0);
        $height = (float) ($layout['height'] ?? 0);
        $name   = strtolower($name);

        if ($len < 2 || $len > 36) {
            return false;
        }

        if (strpos($name, 'button') !== false || strpos($name, 'cta') !== false) {
            return true;
        }

        return '' !== $background && $width >= 60 && $width <= 420 && $height >= 24 && $height <= 100;
    }

    private function looks_like_icon(array $node): bool
    {
        $layout = extract_auto_layout($node);
        $width  = (float) ($layout['width'] ?? 0);
        $height = (float) ($layout['height'] ?? 0);
        $name   = strtolower(get_node_name($node));

        if (strpos($name, 'icon') !== false || strpos($name, 'logo') !== false) {
            return true;
        }

        return $width > 0 && $height > 0 && $width <= 96 && $height <= 96;
    }

    private function should_export_as_svg(array $node): bool
    {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if (empty($children)) {
            return false;
        }

        $name         = strtolower(get_node_name($node));
        $layout       = extract_auto_layout($node);
        $width        = (float) ($layout['width'] ?? 0);
        $height       = (float) ($layout['height'] ?? 0);
        $visual_count = 0;
        $text_count   = 0;

        foreach ($children as $child) {
            if (! is_array($child) || ($child['visible'] ?? true) === false) {
                continue;
            }
            $t = as_str($child['type'] ?? '');
            if ('TEXT' === $t) {
                $text_count++;
            } elseif (in_array($t, ['VECTOR', 'BOOLEAN_OPERATION', 'STAR', 'LINE', 'ELLIPSE', 'POLYGON', 'RECTANGLE'], true) || has_image_fill($child)) {
                $visual_count++;
            }
        }

        if ($visual_count === 0 || $text_count > 0) {
            return false;
        }

        if (strpos($name, 'logo') !== false || strpos($name, 'icon') !== false || strpos($name, 'illustration') !== false) {
            return true;
        }

        return $width > 0 && $height > 0 && $width <= 160 && $height <= 160;
    }

    private function find_node_by_id(array $figma_file, string $node_id): ?array
    {
        $document = is_array($figma_file['document'] ?? null) ? $figma_file['document'] : [];
        return $this->find_recursive($document, $node_id);
    }

    private function find_recursive(array $node, string $node_id): ?array
    {
        if (as_str($node['id'] ?? '') === $node_id) {
            return $node;
        }
        foreach (($node['children'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }
            $found = $this->find_recursive($child, $node_id);
            if ($found) {
                return $found;
            }
        }
        return null;
    }
}
