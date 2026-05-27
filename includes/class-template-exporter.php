<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Exports the generated Elementor elements as an importable Elementor template JSON.
 */
final class Template_Exporter
{
    /**
     * Build the template JSON that Elementor's import dialog accepts.
     *
     * @param array  $elements  Output of Elementor_Atomic_Builder::build()
     * @param string $title     Template title
     * @return array  Template data array (not yet JSON-encoded)
     */
    public function build_template(array $elements, string $title = ''): array
    {
        return [
            'version'   => '0.4',
            'title'     => $title ?: 'Figma Import',
            'type'      => 'page',
            'content'   => $elements,
            'page_settings' => [
                'background_background' => '',
            ],
        ];
    }

    /**
     * Serve the template as a JSON download.
     */
    public function send_as_download(array $elements, string $title = ''): void
    {
        $template = $this->build_template($elements, $title);
        $json     = wp_json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $filename = sanitize_file_name(($title ?: 'figma-import') . '.json');

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Save the template as a WordPress post of type `elementor_library`.
     *
     * @return int|\WP_Error  Post ID or error
     */
    public function save_as_library_template(array $elements, string $title = ''): int|\WP_Error
    {
        $post_id = wp_insert_post([
            'post_title'  => $title ?: 'Figma Import',
            'post_type'   => 'elementor_library',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($elements)));
        update_post_meta($post_id, '_elementor_template_type', 'page');
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');

        return $post_id;
    }
}
