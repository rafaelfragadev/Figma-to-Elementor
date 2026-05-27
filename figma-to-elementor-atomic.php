<?php
/**
 * Plugin Name: Figma to Elementor Atomic
 * Plugin URI: https://example.com/figma-to-elementor-atomic
 * Description: Converts Figma layouts to Elementor pages using atomic elements with section-by-section processing and design tokens.
 * Version: 1.3.0
 * Author: MVP
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: ftea
 */

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

define('FTEA_VERSION', '1.3.0');
define('FTEA_PLUGIN_FILE', __FILE__);
define('FTEA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FTEA_PLUGIN_URL', plugin_dir_url(__FILE__));

if (defined('FTEA_LOADED')) {
    return;
}
define('FTEA_LOADED', true);

require_once FTEA_PLUGIN_DIR . 'includes/helpers.php';
require_once FTEA_PLUGIN_DIR . 'includes/class-figma-api.php';
require_once FTEA_PLUGIN_DIR . 'includes/class-figma-parser.php';
require_once FTEA_PLUGIN_DIR . 'includes/class-section-detector.php';
require_once FTEA_PLUGIN_DIR . 'includes/class-design-token-extractor.php';
require_once FTEA_PLUGIN_DIR . 'includes/class-asset-importer.php';
require_once FTEA_PLUGIN_DIR . 'includes/class-elementor-atomic-builder.php';
require_once FTEA_PLUGIN_DIR . 'includes/class-responsive-mapper.php';
require_once FTEA_PLUGIN_DIR . 'includes/class-import-logger.php';
require_once FTEA_PLUGIN_DIR . 'includes/class-template-exporter.php';
require_once FTEA_PLUGIN_DIR . 'includes/class-admin-page.php';

add_action('admin_menu', __NAMESPACE__ . '\\ftea_register_menu', 1);
add_action('admin_post_ftea_load_frames', __NAMESPACE__ . '\\ftea_handle_load_frames');
add_action('admin_post_ftea_import_section', __NAMESPACE__ . '\\ftea_handle_import_section');
add_action('admin_post_ftea_import_all', __NAMESPACE__ . '\\ftea_handle_import_all');
add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\ftea_enqueue_assets');
add_action('wp_ajax_ftea_get_section_preview', __NAMESPACE__ . '\\ftea_ajax_section_preview');
add_action('admin_notices', __NAMESPACE__ . '\\ftea_render_notices');
add_filter('plugin_action_links_' . plugin_basename(FTEA_PLUGIN_FILE), __NAMESPACE__ . '\\ftea_action_links');

register_activation_hook(FTEA_PLUGIN_FILE, __NAMESPACE__ . '\\ftea_on_activate');
register_deactivation_hook(FTEA_PLUGIN_FILE, __NAMESPACE__ . '\\ftea_on_deactivate');

function ftea_make_admin_page(): Admin_Page
{
    static $page = null;
    if (null === $page) {
        $page = new Admin_Page(
            new Figma_API(),
            new Figma_Parser(),
            new Section_Detector(),
            new Design_Token_Extractor(),
            new Asset_Importer(),
            new Elementor_Atomic_Builder(),
            new Responsive_Mapper(),
            new Import_Logger(),
            new Template_Exporter()
        );
    }
    return $page;
}

function ftea_register_menu(): void
{
    add_menu_page(
        __('Figma → Elementor Atomic', 'ftea'),
        __('Figma Atomic', 'ftea'),
        'manage_options',
        'figma-to-elementor-atomic',
        __NAMESPACE__ . '\\ftea_render_page',
        'dashicons-layout',
        4
    );
}

function ftea_render_page(): void
{
    ftea_make_admin_page()->render();
}

function ftea_handle_load_frames(): void
{
    ftea_make_admin_page()->handle_load_frames();
}

function ftea_handle_import_section(): void
{
    ftea_make_admin_page()->handle_import_section();
}

function ftea_handle_import_all(): void
{
    ftea_make_admin_page()->handle_import_all();
}

function ftea_ajax_section_preview(): void
{
    ftea_make_admin_page()->ajax_section_preview();
}

function ftea_render_notices(): void
{
    ftea_make_admin_page()->render_notices();
}

function ftea_enqueue_assets(string $hook): void
{
    ftea_make_admin_page()->enqueue_assets($hook);
}

function ftea_action_links(array $links): array
{
    $link = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('admin.php?page=figma-to-elementor-atomic')),
        esc_html__('Figma Atomic', 'ftea')
    );
    array_unshift($links, $link);
    return $links;
}

function ftea_on_activate(): void
{
    add_option('ftea_activation_redirect', 1);
}

function ftea_on_deactivate(): void
{
    // nothing to clean up
}
