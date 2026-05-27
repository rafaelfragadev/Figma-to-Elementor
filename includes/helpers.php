<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

function ftea_log(string $message, array $context = []): void
{
    if (! defined('WP_DEBUG') || ! WP_DEBUG) {
        return;
    }
    $line = '[FTEA] ' . $message;
    if (! empty($context)) {
        $line .= ' ' . wp_json_encode($context);
    }
    error_log($line); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

function as_str($value): string
{
    return is_string($value) ? $value : '';
}

function as_float($value, float $default = 0.0): float
{
    return is_numeric($value) ? (float) $value : $default;
}

function make_id(): string
{
    return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(8))), 0, 7);
}

function extract_figma_file_key(string $input): string
{
    $input = trim($input);
    if (preg_match('#figma\.com/(?:file|design)/([a-zA-Z0-9]+)#', $input, $m)) {
        return sanitize_text_field($m[1]);
    }
    if (preg_match('#^[a-zA-Z0-9]{10,}$#', $input)) {
        return sanitize_text_field($input);
    }
    return '';
}

function sanitize_token(string $token): string
{
    return trim(sanitize_text_field(wp_unslash($token)));
}

function get_node_name(array $node): string
{
    return sanitize_text_field(as_str($node['name'] ?? 'Untitled'));
}

/**
 * @return array{hex:string,alpha:float}
 */
function figma_color_to_hex(array $color): array
{
    $r = isset($color['r']) ? (int) round((float) $color['r'] * 255) : 0;
    $g = isset($color['g']) ? (int) round((float) $color['g'] * 255) : 0;
    $b = isset($color['b']) ? (int) round((float) $color['b'] * 255) : 0;
    $a = isset($color['a']) ? (float) $color['a'] : 1.0;

    return [
        'hex'   => sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b))),
        'alpha' => max(0.0, min(1.0, $a)),
    ];
}

/**
 * Returns rgba() string when alpha < 1, hex otherwise.
 */
function figma_color_to_css(array $color): string
{
    $result = figma_color_to_hex($color);
    if ($result['alpha'] < 0.99) {
        $r = hexdec(substr($result['hex'], 1, 2));
        $g = hexdec(substr($result['hex'], 3, 2));
        $b = hexdec(substr($result['hex'], 5, 2));
        return sprintf('rgba(%d,%d,%d,%.2f)', $r, $g, $b, $result['alpha']);
    }
    return $result['hex'];
}

function extract_background_color(array $node): string
{
    foreach (($node['fills'] ?? []) as $fill) {
        if (! is_array($fill) || ($fill['visible'] ?? true) === false) {
            continue;
        }
        if (($fill['type'] ?? '') === 'SOLID' && isset($fill['color']) && is_array($fill['color'])) {
            $opacity = isset($fill['opacity']) ? (float) $fill['opacity'] : 1.0;
            $color   = $fill['color'];
            if ($opacity < 0.99) {
                // Multiply fill-level opacity into the color alpha so that
                // figma_color_to_css() can output rgba() when needed.
                $color['a'] = ($color['a'] ?? 1.0) * $opacity;
            }
            return figma_color_to_css($color);
        }
    }
    return '';
}

function extract_text_color(array $node): string
{
    return extract_background_color($node);
}

function has_image_fill(array $node): bool
{
    foreach (($node['fills'] ?? []) as $fill) {
        if (is_array($fill) && ($fill['visible'] ?? true) !== false && ($fill['type'] ?? '') === 'IMAGE') {
            return true;
        }
    }
    return false;
}

function extract_background_image_ref(array $node): string
{
    foreach (($node['fills'] ?? []) as $fill) {
        if (! is_array($fill) || ($fill['visible'] ?? true) === false) {
            continue;
        }
        if (($fill['type'] ?? '') === 'IMAGE' && ! empty($fill['imageRef']) && is_string($fill['imageRef'])) {
            return $fill['imageRef'];
        }
    }
    return '';
}

/**
 * Returns the full gradient definition with ALL stops (not just first/last).
 */
function extract_gradient(array $node): ?array
{
    foreach (($node['fills'] ?? []) as $fill) {
        if (! is_array($fill) || ($fill['visible'] ?? true) === false) {
            continue;
        }
        $type = as_str($fill['type'] ?? '');
        if (strpos($type, 'GRADIENT') !== 0) {
            continue;
        }
        $raw_stops = isset($fill['gradientStops']) && is_array($fill['gradientStops'])
            ? $fill['gradientStops']
            : [];
        if (count($raw_stops) < 2) {
            continue;
        }

        // Build full stop list.
        $stops = [];
        foreach ($raw_stops as $s) {
            if (! isset($s['color']) || ! is_array($s['color'])) {
                continue;
            }
            $stops[] = [
                'color'    => figma_color_to_hex($s['color'])['hex'],
                'position' => (float) (($s['position'] ?? 0) * 100),
            ];
        }
        if (count($stops) < 2) {
            continue;
        }

        $angle = 180;
        if ('GRADIENT_LINEAR' === $type) {
            $handles = isset($fill['gradientHandlePositions']) && is_array($fill['gradientHandlePositions'])
                ? $fill['gradientHandlePositions']
                : [];
            if (count($handles) >= 2) {
                $dx    = (float) ($handles[1]['x'] ?? 0) - (float) ($handles[0]['x'] ?? 0);
                $dy    = (float) ($handles[1]['y'] ?? 0) - (float) ($handles[0]['y'] ?? 0);
                $angle = (int) round(fmod(rad2deg(atan2($dx, -$dy)) + 360.0, 360.0));
            }
        }

        return [
            'gradient_type' => 'GRADIENT_RADIAL' === $type ? 'radial' : 'linear',
            'color_a'       => $stops[0]['color'],
            'color_b'       => $stops[count($stops) - 1]['color'],
            'stop_a'        => $stops[0]['position'],
            'stop_b'        => $stops[count($stops) - 1]['position'],
            'angle'         => $angle,
            'stops'         => $stops,
        ];
    }
    return null;
}

function extract_border_radius(array $node): ?array
{
    if (isset($node['cornerRadius']) && (float) $node['cornerRadius'] > 0) {
        $r = (float) $node['cornerRadius'];
        return ['tl' => $r, 'tr' => $r, 'br' => $r, 'bl' => $r];
    }
    $radii = isset($node['rectangleCornerRadii']) && is_array($node['rectangleCornerRadii'])
        ? $node['rectangleCornerRadii']
        : [];
    if (4 === count($radii) && max(array_map('floatval', $radii)) > 0) {
        return [
            'tl' => (float) $radii[0],
            'tr' => (float) $radii[1],
            'br' => (float) $radii[2],
            'bl' => (float) $radii[3],
        ];
    }
    return null;
}

function extract_bounding_box(array $node): array
{
    $box = isset($node['absoluteBoundingBox']) && is_array($node['absoluteBoundingBox'])
        ? $node['absoluteBoundingBox']
        : [];
    return [
        'x'      => isset($box['x']) ? (float) $box['x'] : 0.0,
        'y'      => isset($box['y']) ? (float) $box['y'] : 0.0,
        'width'  => isset($box['width']) ? (float) $box['width'] : 0.0,
        'height' => isset($box['height']) ? (float) $box['height'] : 0.0,
    ];
}

/**
 * Extracts layout information.
 * Includes is_auto_layout flag so the builder can distinguish flex vs absolute containers.
 */
function extract_auto_layout(array $node): array
{
    $layout_mode  = as_str($node['layoutMode'] ?? '');
    $is_auto      = ($layout_mode !== '');
    $box          = extract_bounding_box($node);

    return [
        'is_auto_layout' => $is_auto,
        'direction'      => 'HORIZONTAL' === $layout_mode ? 'row' : ($is_auto ? 'column' : ''),
        'gap'            => isset($node['itemSpacing']) ? (float) $node['itemSpacing'] : null,
        'padding'        => [
            'top'    => isset($node['paddingTop'])    ? (float) $node['paddingTop']    : 0,
            'right'  => isset($node['paddingRight'])  ? (float) $node['paddingRight']  : 0,
            'bottom' => isset($node['paddingBottom']) ? (float) $node['paddingBottom'] : 0,
            'left'   => isset($node['paddingLeft'])   ? (float) $node['paddingLeft']   : 0,
        ],
        'primary_axis'  => as_str($node['primaryAxisAlignItems'] ?? ''),
        'counter_axis'  => as_str($node['counterAxisAlignItems'] ?? ''),
        'positioning'   => as_str($node['layoutPositioning'] ?? ''),
        'x'             => $box['x'],
        'y'             => $box['y'],
        'width'         => $box['width'],
        'height'        => $box['height'],
    ];
}

/** Node-level opacity (0.0–1.0). */
function extract_opacity(array $node): float
{
    return isset($node['opacity']) ? max(0.0, min(1.0, (float) $node['opacity'])) : 1.0;
}

function map_align(string $figma_align): string
{
    $map = [
        'MIN'           => 'flex-start',
        'CENTER'        => 'center',
        'MAX'           => 'flex-end',
        'SPACE_BETWEEN' => 'space-between',
        'BASELINE'      => 'baseline',
    ];
    return $map[$figma_align] ?? '';
}

function is_elementor_active(): bool
{
    return did_action('elementor/loaded') || defined('ELEMENTOR_VERSION') || ftea_is_plugin_active('elementor/elementor.php');
}

function ftea_is_plugin_active(string $plugin): bool
{
    if (! function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    return function_exists('is_plugin_active') && is_plugin_active($plugin);
}
