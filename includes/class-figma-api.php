<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

final class Figma_API
{
    private const BASE = 'https://api.figma.com/v1';

    public function get_file(string $file_key, string $token): array|\WP_Error
    {
        return $this->get("/files/{$file_key}", $token);
    }

    /**
     * Export nodes as images. Node IDs must NOT be URL-encoded —
     * Figma rejects percent-encoded colons in the ids= parameter.
     *
     * @param string[] $node_ids  e.g. ['1:2', '3:456']
     */
    public function get_image_urls(string $file_key, string $token, array $node_ids, string $format = 'png', float $scale = 2): array|\WP_Error
    {
        // Join raw IDs with commas — do NOT urlencode them.
        $ids = implode(',', $node_ids);
        return $this->get("/images/{$file_key}?ids={$ids}&format={$format}&scale={$scale}", $token);
    }

    /**
     * Resolve imageRef hashes → CDN URLs.
     * Endpoint: GET /v1/files/:key/images
     * Response: {"error":false,"meta":{"images":{"ref":"url",...}}}
     */
    public function get_fill_images(string $file_key, string $token): array|\WP_Error
    {
        return $this->get("/files/{$file_key}/images", $token);
    }

    private function get(string $path, string $token): array|\WP_Error
    {
        $url      = self::BASE . $path;
        $response = wp_remote_get($url, [
            'timeout' => 45,
            'headers' => ['X-Figma-Token' => $token],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200) {
            $msg = is_array($data) && ! empty($data['message']) ? $data['message'] : "HTTP {$code}";
            return new \WP_Error('figma_api_error', $msg, ['status' => $code]);
        }

        if (! is_array($data)) {
            return new \WP_Error('figma_api_parse', 'Invalid JSON response from Figma API');
        }

        return $data;
    }
}
