<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Downloads Figma image/SVG assets and saves them to the WordPress media library.
 *
 * KEY FIXES vs. original:
 *  - Node IDs are never URL-encoded (Figma rejects %3A for colon).
 *  - SVGs are fetched inline from the Figma CDN URL — WordPress blocks SVG
 *    uploads by default, so we never attempt to sideload them.
 *  - The cache persists within a request and can be restored from a prior
 *    request's serialised map stored in the admin-page session.
 *  - All errors are logged with context so the admin UI log can show them.
 */
final class Asset_Importer
{
    /** @var array<string, array{id:int,url:string,inline_svg:string}> */
    private array $cache = [];

    /** @var array{found:int,downloaded:int,failed:int,svg:int,errors:string[]} */
    private array $stats = ['found' => 0, 'downloaded' => 0, 'failed' => 0, 'svg' => 0, 'errors' => []];

    private ?Figma_API $api  = null;
    private string $file_key = '';
    private string $token    = '';

    // -------------------------------------------------------------------------
    // Initialisation
    // -------------------------------------------------------------------------

    public function init(Figma_API $api, string $file_key, string $token): void
    {
        $this->api      = $api;
        $this->file_key = $file_key;
        $this->token    = $token;
    }

    /**
     * Restore a previously serialised cache (between HTTP requests).
     *
     * @param array<string, array{id:int,url:string,inline_svg:string}> $cache
     */
    public function restore_cache(array $cache): void
    {
        $this->cache = $cache;
    }

    // -------------------------------------------------------------------------
    // Prefetch
    // -------------------------------------------------------------------------

    /**
     * Export Figma nodes as raster or SVG and cache the results.
     *
     * @param string[] $node_ids  Raw Figma node IDs, e.g. ['1:2', '34:567']
     */
    public function prefetch(array $node_ids, string $format = 'png'): void
    {
        if (empty($node_ids) || ! $this->api) {
            return;
        }

        $pending = array_values(array_filter($node_ids, fn ($id) => ! isset($this->cache[$id])));
        if (empty($pending)) {
            return;
        }

        $this->stats['found'] += count($pending);

        $response = $this->api->get_image_urls($this->file_key, $this->token, $pending, $format);
        if (is_wp_error($response)) {
            $msg = 'Figma export API error: ' . $response->get_error_message();
            ftea_log($msg, ['ids' => $pending]);
            $this->stats['errors'][] = $msg;
            // Mark all as failed so the builder can show a placeholder.
            foreach ($pending as $id) {
                $this->cache[$id] = ['id' => 0, 'url' => '', 'inline_svg' => ''];
                $this->stats['failed']++;
            }
            return;
        }

        $images = is_array($response['images'] ?? null) ? $response['images'] : [];

        foreach ($pending as $node_id) {
            $url = is_string($images[$node_id] ?? null) ? $images[$node_id] : '';

            if ($url === '') {
                // Figma returned null — node not exportable (e.g. hidden, locked).
                $this->cache[$node_id] = ['id' => 0, 'url' => '', 'inline_svg' => ''];
                $this->stats['failed']++;
                ftea_log('No export URL for node', ['id' => $node_id]);
                continue;
            }

            if ($format === 'svg') {
                $inline = $this->fetch_inline_svg($url);
                if ($inline !== '') {
                    $this->cache[$node_id] = ['id' => 0, 'url' => $url, 'inline_svg' => $inline];
                    $this->stats['svg']++;
                    $this->stats['downloaded']++;
                    continue;
                }
                // If SVG fetch failed, fall through to PNG-style download.
            }

            $saved = $this->download_to_media($url, $node_id, $format);
            $this->cache[$node_id] = $saved;

            if ($saved['id'] > 0 || $saved['url'] !== '') {
                $this->stats['downloaded']++;
            } else {
                $this->stats['failed']++;
            }
        }
    }

    /**
     * Resolve imageRef hashes → CDN URLs, then download to media library.
     *
     * @param string[] $refs  imageRef strings extracted from Figma fills
     */
    public function prefetch_fill_refs(array $refs): void
    {
        if (empty($refs) || ! $this->api) {
            return;
        }

        $pending = array_values(array_filter($refs, function ($ref) {
            return ! isset($this->cache['fillref:' . $ref]);
        }));

        if (empty($pending)) {
            return;
        }

        $response = $this->api->get_fill_images($this->file_key, $this->token);
        if (is_wp_error($response)) {
            $msg = 'Fill images API error: ' . $response->get_error_message();
            ftea_log($msg);
            $this->stats['errors'][] = $msg;
            foreach ($pending as $ref) {
                $this->cache['fillref:' . $ref] = ['id' => 0, 'url' => '', 'inline_svg' => ''];
                $this->stats['failed']++;
            }
            return;
        }

        // Response: {"error":false,"meta":{"images":{"ref":"url",...}}}
        $meta   = is_array($response['meta'] ?? null) ? $response['meta'] : [];
        $images = is_array($meta['images'] ?? null) ? $meta['images'] : [];

        $this->stats['found'] += count($pending);

        foreach ($pending as $ref) {
            $cache_key = 'fillref:' . $ref;
            $url       = is_string($images[$ref] ?? null) ? $images[$ref] : '';

            if ($url === '') {
                $this->cache[$cache_key] = ['id' => 0, 'url' => '', 'inline_svg' => ''];
                $this->stats['failed']++;
                ftea_log('No CDN URL for imageRef', ['ref' => $ref]);
                continue;
            }

            $safe_name = 'figma_fill_' . substr(md5($ref), 0, 8);
            $saved     = $this->download_to_media($url, $safe_name, 'png');
            $this->cache[$cache_key] = $saved;

            if ($saved['id'] > 0 || $saved['url'] !== '') {
                $this->stats['downloaded']++;
            } else {
                $this->stats['failed']++;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /** @return array{id:int,url:string,inline_svg:string} */
    public function get(string $node_id): array
    {
        return $this->cache[$node_id] ?? ['id' => 0, 'url' => '', 'inline_svg' => ''];
    }

    /** @return array<string, array{id:int,url:string,inline_svg:string}> */
    public function get_all(): array
    {
        return $this->cache;
    }

    /** @return array{found:int,downloaded:int,failed:int,svg:int,errors:string[]} */
    public function get_stats(): array
    {
        return $this->stats;
    }

    /**
     * Traverse an intermediate tree node and collect all asset IDs and fill refs.
     *
     * @return array{nodes:array<string,string>, fill_refs:string[]}
     */
    public function collect_asset_ids(array $node): array
    {
        $nodes     = [];
        $fill_refs = [];
        $this->traverse_for_assets($node, $nodes, $fill_refs);
        return [
            'nodes'      => $nodes,
            'fill_refs'  => array_values(array_unique($fill_refs)),
        ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function traverse_for_assets(array $node, array &$nodes, array &$fill_refs): void
    {
        $type = as_str($node['type'] ?? '');

        if ($type === 'image') {
            $id  = as_str($node['figma_id'] ?? '');
            $fmt = as_str($node['export_format'] ?? 'png');
            if ($id !== '') {
                $nodes[$id] = $fmt;
            }
        }

        $ref = as_str($node['background_image_ref'] ?? '');
        if ($ref !== '') {
            $fill_refs[] = $ref;
        }

        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->traverse_for_assets($child, $nodes, $fill_refs);
            }
        }
    }

    /** @return array{id:int,url:string,inline_svg:string} */
    private function download_to_media(string $url, string $name, string $format): array
    {
        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) {
            $msg = 'download_url failed: ' . $tmp->get_error_message();
            ftea_log($msg, ['url' => $url]);
            $this->stats['errors'][] = $msg;
            return ['id' => 0, 'url' => $url, 'inline_svg' => ''];
        }

        $ext  = 'png'; // always PNG for raster Figma exports
        $file = [
            'name'     => sanitize_file_name($name . '.' . $ext),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file, 0, '');

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            $msg = 'media sideload failed: ' . $attachment_id->get_error_message();
            ftea_log($msg, ['name' => $name]);
            $this->stats['errors'][] = $msg;
            return ['id' => 0, 'url' => $url, 'inline_svg' => ''];
        }

        $attachment_url = wp_get_attachment_url($attachment_id);

        return [
            'id'         => (int) $attachment_id,
            'url'        => is_string($attachment_url) ? $attachment_url : $url,
            'inline_svg' => '',
        ];
    }

    /**
     * Fetch an SVG from a URL and return sanitised inline markup.
     * Never attempts to upload to WP media library (blocked by default).
     */
    private function fetch_inline_svg(string $url): string
    {
        $response = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($response)) {
            ftea_log('SVG fetch failed: ' . $response->get_error_message(), ['url' => $url]);
            return '';
        }

        $body = wp_remote_retrieve_body($response);
        if (strpos($body, '<svg') === false) {
            return '';
        }

        // Extract only the <svg>…</svg> portion.
        if (! preg_match('/<svg[\s\S]*?<\/svg>/i', $body, $m)) {
            return '';
        }

        return wp_kses($m[0], $this->svg_allowed_tags());
    }

    /**
     * SVG allowed-tags map for wp_kses().
     * Note: attribute names listed here are case-sensitive for HTML, but
     * browsers handle SVG mixed-case (viewBox, gradientTransform, etc.)
     * before WP sanitises, so we list both common casings.
     */
    private function svg_allowed_tags(): array
    {
        $common = [
            'id' => [], 'class' => [], 'style' => [],
            'fill' => [], 'fill-opacity' => [], 'fill-rule' => [],
            'stroke' => [], 'stroke-width' => [], 'stroke-opacity' => [],
            'stroke-linecap' => [], 'stroke-linejoin' => [], 'stroke-miterlimit' => [],
            'd' => [], 'points' => [], 'transform' => [],
            'cx' => [], 'cy' => [], 'r' => [], 'rx' => [], 'ry' => [],
            'x' => [], 'y' => [], 'x1' => [], 'y1' => [], 'x2' => [], 'y2' => [],
            'width' => [], 'height' => [],
            'viewbox' => [], 'viewBox' => [],
            'xmlns' => [], 'xmlns:xlink' => [],
            'opacity' => [],
            'clip-path' => [], 'clip-rule' => [],
            'offset' => [], 'stop-color' => [], 'stop-opacity' => [],
            'gradientunits' => [], 'gradientUnits' => [],
            'gradienttransform' => [], 'gradientTransform' => [],
            'spreadmethod' => [], 'spreadMethod' => [],
            'patternunits' => [], 'patternUnits' => [],
            'patterntransform' => [], 'patternTransform' => [],
            'marker-start' => [], 'marker-mid' => [], 'marker-end' => [],
            'preserveaspectratio' => [], 'preserveAspectRatio' => [],
            'xlink:href' => [], 'href' => [],
        ];

        $tags = [
            'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line',
            'polyline', 'polygon', 'text', 'tspan', 'textPath',
            'defs', 'use', 'symbol', 'image',
            'clipPath', 'mask',
            'linearGradient', 'radialGradient', 'stop',
            'pattern', 'marker',
            'title', 'desc', 'metadata',
            'animate', 'animateTransform',
        ];

        return array_fill_keys($tags, $common);
    }
}
