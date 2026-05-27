<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

final class Admin_Page
{
    private const SLUG         = 'figma-to-elementor-atomic';
    private const OPTION_TOKEN = 'ftea_figma_token';
    private const OPTION_FILE  = 'ftea_figma_file_key';
    private const SESSION_KEY  = 'ftea_session';

    private Figma_API               $api;
    private Figma_Parser            $parser;
    private Section_Detector        $section_detector;
    private Design_Token_Extractor  $token_extractor;
    private Asset_Importer          $asset_importer;
    private Elementor_Atomic_Builder $builder;
    private Responsive_Mapper       $responsive;
    private Import_Logger           $logger;
    private Template_Exporter       $exporter;

    public function __construct(
        Figma_API $api,
        Figma_Parser $parser,
        Section_Detector $section_detector,
        Design_Token_Extractor $token_extractor,
        Asset_Importer $asset_importer,
        Elementor_Atomic_Builder $builder,
        Responsive_Mapper $responsive,
        Import_Logger $logger,
        Template_Exporter $exporter
    ) {
        $this->api              = $api;
        $this->parser           = $parser;
        $this->section_detector = $section_detector;
        $this->token_extractor  = $token_extractor;
        $this->asset_importer   = $asset_importer;
        $this->builder          = $builder;
        $this->responsive       = $responsive;
        $this->logger           = $logger;
        $this->exporter         = $exporter;
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(): void
    {
        // Navigating to ?step=connect explicitly resets the session so the
        // user truly starts over instead of seeing the stale 'done' screen.
        if (isset($_GET['step']) && $_GET['step'] === 'connect') {
            $this->clear_session();
        }

        $step = $this->get_step();
        ?>
        <div class="wrap ftea-wrap">
            <h1><?php esc_html_e('Figma → Elementor Atomic', 'ftea'); ?></h1>
            <?php $this->render_notices_inline(); ?>

            <?php if ('frames' === $step) : ?>
                <?php $this->render_step_frames(); ?>
            <?php elseif ('sections' === $step) : ?>
                <?php $this->render_step_sections(); ?>
            <?php elseif ('done' === $step) : ?>
                <?php $this->render_step_done(); ?>
            <?php else : ?>
                <?php $this->render_step_connect(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_step_connect(): void
    {
        $token    = esc_attr(get_option(self::OPTION_TOKEN, ''));
        $file_key = esc_attr(get_option(self::OPTION_FILE, ''));
        ?>
        <div class="ftea-card">
            <h2><?php esc_html_e('Connect to Figma', 'ftea'); ?></h2>
            <p><?php esc_html_e('Enter your Personal Access Token and the file URL or key.', 'ftea'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ftea_load_frames', 'ftea_nonce'); ?>
                <input type="hidden" name="action" value="ftea_load_frames">

                <table class="form-table">
                    <tr>
                        <th><label for="ftea_token"><?php esc_html_e('Personal Access Token', 'ftea'); ?></label></th>
                        <td>
                            <input type="password" id="ftea_token" name="ftea_token"
                                   value="<?php echo $token; ?>" class="regular-text" autocomplete="off" required>
                            <p class="description"><?php esc_html_e('Figma → Account Settings → Personal access tokens', 'ftea'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ftea_file_key"><?php esc_html_e('File URL or Key', 'ftea'); ?></label></th>
                        <td>
                            <input type="text" id="ftea_file_key" name="ftea_file_key"
                                   value="<?php echo $file_key; ?>" class="regular-text" required
                                   placeholder="https://www.figma.com/design/XXXX/...">
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Load Frames', 'ftea'), 'primary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    private function render_step_frames(): void
    {
        $session  = $this->get_session();
        $frames   = $session['frames'] ?? [];
        $file_key = esc_attr(get_option(self::OPTION_FILE, ''));
        ?>
        <div class="ftea-card">
            <h2><?php esc_html_e('Select a Frame', 'ftea'); ?></h2>
            <p><?php printf(esc_html__('File: %s — %d frame(s) found.', 'ftea'), '<code>' . esc_html($file_key) . '</code>', count($frames)); ?></p>

            <?php if (empty($frames)) : ?>
                <div class="notice notice-warning inline">
                    <p><?php esc_html_e('No top-level frames found. Make sure your file has at least one page with FRAME nodes.', 'ftea'); ?></p>
                </div>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('ftea_import_section', 'ftea_nonce'); ?>
                    <input type="hidden" name="action" value="ftea_import_section">
                    <input type="hidden" name="ftea_phase" value="detect_sections">

                    <table class="wp-list-table widefat fixed striped ftea-frames-table">
                        <thead>
                            <tr>
                                <th style="width:36px;"></th>
                                <th><?php esc_html_e('Name', 'ftea'); ?></th>
                                <th><?php esc_html_e('Page', 'ftea'); ?></th>
                                <th><?php esc_html_e('ID', 'ftea'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($frames as $frame) : ?>
                                <tr>
                                    <td><input type="radio" name="ftea_frame_id" value="<?php echo esc_attr($frame['id']); ?>" required></td>
                                    <td><?php echo esc_html($frame['name']); ?></td>
                                    <td><?php echo esc_html($frame['page']); ?></td>
                                    <td><code><?php echo esc_html($frame['id']); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <br>
                    <?php submit_button(__('Detect Sections', 'ftea'), 'primary', 'submit', false); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>" class="button">
                        <?php esc_html_e('← Back', 'ftea'); ?>
                    </a>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_step_sections(): void
    {
        $session    = $this->get_session();
        $sections   = $session['sections'] ?? [];
        $frame_name = esc_html($session['frame_name'] ?? '');
        $page_id    = (int) ($session['page_id'] ?? 0);
        $imported   = is_array($session['imported_sections'] ?? null) ? $session['imported_sections'] : [];
        $log_map    = $this->build_log_map($session['log_entries'] ?? []);
        ?>
        <div class="ftea-card">
            <h2><?php printf(esc_html__('Import Sections — %s', 'ftea'), $frame_name); ?></h2>
            <p><?php printf(esc_html__('%d section(s) detected. Import one at a time to validate each section before advancing.', 'ftea'), count($sections)); ?></p>
            <?php $this->render_debug_panel($sections); ?>

            <?php if ($page_id > 0) : ?>
                <p>
                    <a href="<?php echo esc_url(get_edit_post_link($page_id)); ?>" target="_blank" class="button button-secondary">
                        <?php esc_html_e('Edit in Elementor', 'ftea'); ?>
                    </a>
                    <?php if (get_permalink($page_id)) : ?>
                        <a href="<?php echo esc_url(get_permalink($page_id)); ?>" target="_blank" class="button button-secondary">
                            <?php esc_html_e('Preview', 'ftea'); ?>
                        </a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped ftea-sections-table">
                <thead>
                    <tr>
                        <th style="width:30px;">#</th>
                        <th><?php esc_html_e('Section', 'ftea'); ?></th>
                        <th><?php esc_html_e('Y / Height', 'ftea'); ?></th>
                        <th><?php esc_html_e('Elements', 'ftea'); ?></th>
                        <th><?php esc_html_e('Images', 'ftea'); ?></th>
                        <th><?php esc_html_e('Errors', 'ftea'); ?></th>
                        <th><?php esc_html_e('Status', 'ftea'); ?></th>
                        <th><?php esc_html_e('Action', 'ftea'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sections as $i => $sec) :
                        $is_done = in_array($i, $imported, true);
                        $log     = $log_map[$i] ?? null;
                    ?>
                        <tr class="<?php echo $is_done ? 'ftea-section-done' : ''; ?>">
                            <td><?php echo ($i + 1); ?></td>
                            <td><?php echo esc_html($sec['name'] ?? ('Section ' . ($i + 1))); ?></td>
                            <td class="ftea-meta"><?php echo round((float) ($sec['y'] ?? 0)); ?>px / <?php echo round((float) ($sec['height'] ?? 0)); ?>px</td>
                            <td class="ftea-meta"><?php echo (int) ($sec['element_count'] ?? count($sec['children'] ?? [])); ?></td>
                            <td class="ftea-meta">
                                <?php if ($log) : ?>
                                    <?php echo (int) $log['images_ok']; ?>/<?php echo (int) $log['images_found']; ?>
                                    <?php if ((int) $log['svgs_ok'] > 0) : ?>
                                        <span class="ftea-meta"> + <?php echo (int) $log['svgs_ok']; ?> SVG</span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="ftea-meta">
                                <?php if ($log && ! empty($log['errors'])) : ?>
                                    <span class="ftea-badge ftea-badge-error" title="<?php echo esc_attr(implode('; ', (array) $log['errors'])); ?>">
                                        <?php echo count((array) $log['errors']); ?>
                                    </span>
                                <?php else : ?>
                                    <span style="color:#46b450;">✓</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($is_done) : ?>
                                    <span class="ftea-badge ftea-badge-done"><?php esc_html_e('Imported', 'ftea'); ?></span>
                                <?php else : ?>
                                    <span class="ftea-badge ftea-badge-pending"><?php esc_html_e('Pending', 'ftea'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (! $is_done) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                        <?php wp_nonce_field('ftea_import_section', 'ftea_nonce'); ?>
                                        <input type="hidden" name="action" value="ftea_import_section">
                                        <input type="hidden" name="ftea_phase" value="import_one">
                                        <input type="hidden" name="ftea_section_index" value="<?php echo $i; ?>">
                                        <button type="submit" class="button button-primary button-small">
                                            <?php esc_html_e('Import', 'ftea'); ?>
                                        </button>
                                    </form>
                                <?php else : ?>
                                    <span class="dashicons dashicons-yes-alt" style="color:#46b450;font-size:20px;"></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php $this->render_import_errors($session['log_entries'] ?? []); ?>

            <br>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                <?php wp_nonce_field('ftea_import_all', 'ftea_nonce'); ?>
                <input type="hidden" name="action" value="ftea_import_all">
                <button type="submit" class="button button-primary" onclick="return confirm('<?php esc_attr_e('Import all remaining sections now?', 'ftea'); ?>')">
                    <?php esc_html_e('Import All Remaining', 'ftea'); ?>
                </button>
            </form>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG . '&step=frames')); ?>" class="button">
                <?php esc_html_e('← Choose another frame', 'ftea'); ?>
            </a>
        </div>
        <?php
    }

    private function render_import_errors(array $log_entries): void
    {
        $errors = [];
        foreach ($log_entries as $entry) {
            if (! empty($entry['errors']) && is_array($entry['errors'])) {
                foreach ($entry['errors'] as $err) {
                    $errors[] = '[' . esc_html($entry['name'] ?? '?') . '] ' . esc_html($err);
                }
            }
        }
        if (empty($errors)) {
            return;
        }
        ?>
        <details class="ftea-errors-panel">
            <summary><?php printf(esc_html__('Import errors (%d)', 'ftea'), count($errors)); ?></summary>
            <ul>
                <?php foreach ($errors as $err) : ?>
                    <li><?php echo $err; ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php
    }

    private function render_step_done(): void
    {
        $session    = $this->get_session();
        $page_id    = (int) ($session['page_id'] ?? 0);
        $frame_name = esc_html($session['frame_name'] ?? '');

        // Build every URL explicitly so there is no ambiguity.
        $elementor_edit_url = $page_id > 0
            ? admin_url('post.php?post=' . $page_id . '&action=elementor')
            : '';
        $wp_edit_url  = $page_id > 0 ? get_edit_post_link($page_id, 'display') : '';
        $preview_url  = $page_id > 0 ? (string) get_permalink($page_id) : '';
        $pages_url    = admin_url('edit.php?post_type=page');
        $new_url      = admin_url('admin.php?page=' . self::SLUG . '&step=connect');
        $sections_url = admin_url('admin.php?page=' . self::SLUG . '&step=sections');
        ?>
        <div class="ftea-card">
            <h2>✅ <?php esc_html_e('Import Complete', 'ftea'); ?></h2>

            <?php if ($page_id > 0) : ?>
                <p>
                    <?php
                    printf(
                        esc_html__('Page "%s" created successfully (ID: %d).', 'ftea'),
                        '<strong>' . $frame_name . '</strong>',
                        $page_id
                    );
                    ?>
                </p>

                <table class="ftea-done-table">
                    <tr>
                        <th><?php esc_html_e('Open Elementor editor', 'ftea'); ?></th>
                        <td><a href="<?php echo esc_url($elementor_edit_url); ?>" class="button button-primary"><?php esc_html_e('Open in Elementor', 'ftea'); ?></a></td>
                    </tr>
                    <?php if ($preview_url) : ?>
                    <tr>
                        <th><?php esc_html_e('Preview page', 'ftea'); ?></th>
                        <td><a href="<?php echo esc_url($preview_url); ?>" class="button" target="_blank"><?php esc_html_e('Preview', 'ftea'); ?></a></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($wp_edit_url) : ?>
                    <tr>
                        <th><?php esc_html_e('WordPress editor (fallback)', 'ftea'); ?></th>
                        <td><a href="<?php echo esc_url($wp_edit_url); ?>" class="button"><?php esc_html_e('Edit Page (WP)', 'ftea'); ?></a></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th><?php esc_html_e('All pages', 'ftea'); ?></th>
                        <td><a href="<?php echo esc_url($pages_url); ?>" class="button"><?php esc_html_e('Pages list', 'ftea'); ?></a></td>
                    </tr>
                </table>

            <?php else : ?>
                <div class="notice notice-warning inline">
                    <p><?php esc_html_e('Import finished but no page ID was recorded. The page may still exist — check the Pages list.', 'ftea'); ?></p>
                </div>
                <p><a href="<?php echo esc_url($pages_url); ?>" class="button button-primary"><?php esc_html_e('Go to Pages', 'ftea'); ?></a></p>
            <?php endif; ?>

            <hr style="margin:20px 0;">

            <p>
                <?php if ($page_id > 0) : ?>
                    <a href="<?php echo esc_url($sections_url); ?>" class="button">
                        <?php esc_html_e('← Back to sections', 'ftea'); ?>
                    </a>
                <?php endif; ?>
                <!-- ?step=connect clears the session before loading the connect form -->
                <a href="<?php echo esc_url($new_url); ?>" class="button">
                    <?php esc_html_e('Start New Import', 'ftea'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Form handlers
    // -------------------------------------------------------------------------

    public function handle_load_frames(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'ftea'));
        }
        check_admin_referer('ftea_load_frames', 'ftea_nonce');

        $token    = sanitize_token(as_str($_POST['ftea_token'] ?? ''));
        $file_key = extract_figma_file_key(as_str($_POST['ftea_file_key'] ?? ''));

        if ('' === $token || '' === $file_key) {
            $this->redirect_with_notice('connect', 'error', __('Token or file key is missing.', 'ftea'));
            return;
        }

        update_option(self::OPTION_TOKEN, $token);
        update_option(self::OPTION_FILE, $file_key);

        $figma_file = $this->api->get_file($file_key, $token);
        if (is_wp_error($figma_file)) {
            $this->redirect_with_notice('connect', 'error', $figma_file->get_error_message());
            return;
        }

        $frames = $this->parser->get_top_level_frames($figma_file);
        $this->save_session(['step' => 'frames', 'frames' => $frames, 'figma_file' => $figma_file]);
        $this->redirect_to_step('frames');
    }

    public function handle_import_section(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'ftea'));
        }
        check_admin_referer('ftea_import_section', 'ftea_nonce');

        $phase = as_str($_POST['ftea_phase'] ?? '');

        if ('detect_sections' === $phase) {
            $this->do_detect_sections();
        } elseif ('import_one' === $phase) {
            $this->do_import_one_section((int) ($_POST['ftea_section_index'] ?? 0));
        }
    }

    public function handle_import_all(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'ftea'));
        }
        check_admin_referer('ftea_import_all', 'ftea_nonce');

        $session  = $this->get_session();
        $sections = $session['sections'] ?? [];
        $imported = is_array($session['imported_sections'] ?? null) ? $session['imported_sections'] : [];

        foreach (array_keys($sections) as $i) {
            if (! in_array($i, $imported, true)) {
                $this->import_section_by_index((int) $i);
            }
        }

        $session         = $this->get_session();
        $session['step'] = 'done';
        $this->save_session($session);
        $this->redirect_to_step('done');
    }

    public function ajax_section_preview(): void
    {
        check_ajax_referer('ftea_section_preview', 'nonce');
        if (! current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $index   = (int) ($_POST['section_index'] ?? 0);
        $session = $this->get_session();
        $section = $session['sections'][$index] ?? null;

        if (! $section) {
            wp_send_json_error('Section not found');
        }

        wp_send_json_success([
            'name'          => $section['name'] ?? '',
            'y'             => $section['y'] ?? 0,
            'height'        => $section['height'] ?? 0,
            'element_count' => $section['element_count'] ?? 0,
        ]);
    }

    // -------------------------------------------------------------------------
    // Import logic
    // -------------------------------------------------------------------------

    private function do_detect_sections(): void
    {
        $frame_id = as_str($_POST['ftea_frame_id'] ?? '');
        if ('' === $frame_id) {
            $this->redirect_with_notice('frames', 'error', __('No frame selected.', 'ftea'));
            return;
        }

        $session    = $this->get_session();
        $figma_file = $session['figma_file'] ?? null;

        if (! is_array($figma_file)) {
            $this->redirect_with_notice('frames', 'error', __('Session expired. Please reload the file.', 'ftea'));
            return;
        }

        $tree = $this->parser->parse($figma_file, $frame_id);

        if (empty($tree['children'])) {
            $this->redirect_with_notice('frames', 'error', __('Frame is empty or invisible.', 'ftea'));
            return;
        }

        $sections   = $this->section_detector->detect($tree);
        $frame_name = $tree['name'] ?? $frame_id;

        if (empty($sections)) {
            $this->redirect_with_notice('frames', 'error', __('No sections could be detected in this frame.', 'ftea'));
            return;
        }

        $page_id = $this->create_elementor_draft($frame_name);

        $session['step']              = 'sections';
        $session['frame_id']          = $frame_id;
        $session['frame_name']        = $frame_name;
        $session['tree']              = $tree;
        $session['sections']          = $sections;
        $session['page_id']           = $page_id;
        $session['imported_sections'] = [];
        $session['all_elements']      = [];
        $session['asset_cache']       = [];
        $session['log_entries']       = [];

        $this->save_session($session);
        $this->redirect_to_step('sections');
    }

    private function do_import_one_section(int $index): void
    {
        $this->import_section_by_index($index);
        $this->redirect_to_step('sections');
    }

    private function import_section_by_index(int $index): void
    {
        $session  = $this->get_session();
        $sections = $session['sections'] ?? [];

        if (! isset($sections[$index])) {
            return;
        }

        $section  = $sections[$index];
        $tree     = $session['tree'] ?? [];
        $page_id  = (int) ($session['page_id'] ?? 0);
        $token    = as_str(get_option(self::OPTION_TOKEN, ''));
        $file_key = as_str(get_option(self::OPTION_FILE, ''));

        // ---- INIT ASSET IMPORTER ----
        $this->asset_importer->init($this->api, $file_key, $token);

        // Restore asset cache from session so we don't re-download across requests.
        if (! empty($session['asset_cache']) && is_array($session['asset_cache'])) {
            $this->asset_importer->restore_cache($session['asset_cache']);
        }

        // ---- COLLECT ASSETS FOR THIS SECTION ----
        $assets = $this->asset_importer->collect_asset_ids($section['children'][0] ?? []);

        // Group by format.
        $png_ids = [];
        $svg_ids = [];
        foreach ($assets['nodes'] as $id => $fmt) {
            if ('svg' === $fmt) {
                $svg_ids[] = $id;
            } else {
                $png_ids[] = $id;
            }
        }

        if (! empty($png_ids)) {
            $this->asset_importer->prefetch($png_ids, 'png');
        }
        if (! empty($svg_ids)) {
            $this->asset_importer->prefetch($svg_ids, 'svg');
        }
        if (! empty($assets['fill_refs'])) {
            $this->asset_importer->prefetch_fill_refs($assets['fill_refs']);
        }

        // ---- DESIGN TOKENS ----
        $color_map = $this->token_extractor->extract_and_apply($tree);

        // ---- BUILD ELEMENTS ----
        $images   = $this->asset_importer->get_all();
        $elements = $this->builder->build_section($section, $images, $color_map);

        // ---- ACCUMULATE & WRITE ----
        $all_elements = is_array($session['all_elements'] ?? null) ? $session['all_elements'] : [];
        foreach ($elements as $el) {
            $all_elements[] = $el;
        }

        if ($page_id > 0) {
            $this->write_elementor_data($page_id, $all_elements);
        }

        // ---- LOG ----
        $asset_stats = $this->asset_importer->get_stats();
        $log_entries = is_array($session['log_entries'] ?? null) ? $session['log_entries'] : [];

        $log_entries[] = [
            'index'        => $index,
            'name'         => $section['name'] ?? ('Section ' . ($index + 1)),
            'y'            => $section['y'] ?? 0,
            'height'       => $section['height'] ?? 0,
            'images_found' => $asset_stats['found'],
            'images_ok'    => $asset_stats['downloaded'],
            'images_failed'=> $asset_stats['failed'],
            'svgs_ok'      => $asset_stats['svg'],
            'errors'       => $asset_stats['errors'],
            'status'       => empty($asset_stats['errors']) ? 'ok' : 'error',
        ];

        // ---- PERSIST SESSION ----
        $imported   = is_array($session['imported_sections'] ?? null) ? $session['imported_sections'] : [];
        $imported[] = $index;

        $session['all_elements']      = $all_elements;
        $session['imported_sections'] = $imported;
        $session['asset_cache']       = $this->asset_importer->get_all();
        $session['log_entries']       = $log_entries;

        if (count($imported) >= count($sections)) {
            $session['step'] = 'done';
        }

        $this->save_session($session);
    }

    // -------------------------------------------------------------------------
    // Elementor page management
    // -------------------------------------------------------------------------

    private function create_elementor_draft(string $title): int
    {
        $post_id = wp_insert_post([
            'post_title'  => sanitize_text_field($title),
            'post_type'   => 'page',
            'post_status' => 'draft',
        ]);

        if (is_wp_error($post_id) || ! $post_id) {
            return 0;
        }

        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_data', '[]');
        update_post_meta($post_id, '_elementor_template_type', 'wp-page');
        update_post_meta($post_id, '_wp_page_template', 'elementor_canvas');

        return (int) $post_id;
    }

    private function write_elementor_data(int $page_id, array $elements): void
    {
        $json = wp_json_encode($elements);
        update_post_meta($page_id, '_elementor_data', wp_slash($json));
        delete_post_meta($page_id, '_elementor_css');
        update_post_meta($page_id, '_elementor_version', '3.0.0');
    }

    // -------------------------------------------------------------------------
    // Session helpers
    // -------------------------------------------------------------------------

    private function get_session(): array
    {
        $raw = get_transient(self::SESSION_KEY . '_' . get_current_user_id());
        return is_array($raw) ? $raw : [];
    }

    private function save_session(array $data): void
    {
        set_transient(self::SESSION_KEY . '_' . get_current_user_id(), $data, 3 * HOUR_IN_SECONDS);
    }

    private function clear_session(): void
    {
        delete_transient(self::SESSION_KEY . '_' . get_current_user_id());
    }

    private function get_step(): string
    {
        $get = as_str($_GET['step'] ?? '');
        if ($get !== '') {
            return sanitize_key($get);
        }
        $session = $this->get_session();
        return as_str($session['step'] ?? 'connect');
    }

    private function redirect_to_step(string $step): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&step=' . $step));
        exit;
    }

    private function redirect_with_notice(string $step, string $type, string $message): void
    {
        $session           = $this->get_session();
        $session['notice'] = ['type' => $type, 'message' => $message];
        $this->save_session($session);
        $this->redirect_to_step($step);
    }

    // -------------------------------------------------------------------------
    // Notices
    // -------------------------------------------------------------------------

    public function render_notices(): void
    {
        $screen = get_current_screen();
        if (! $screen || strpos($screen->id, self::SLUG) === false) {
            return;
        }
        $this->render_notices_inline();
    }

    private function render_notices_inline(): void
    {
        $session = $this->get_session();
        $notice  = $session['notice'] ?? null;
        if (! is_array($notice)) {
            return;
        }

        $type    = in_array($notice['type'] ?? '', ['error', 'warning', 'success', 'info'], true) ? $notice['type'] : 'info';
        $message = esc_html(as_str($notice['message'] ?? ''));

        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . $message . '</p></div>';

        unset($session['notice']);
        $this->save_session($session);
    }

    // -------------------------------------------------------------------------
    // Assets (scripts / styles)
    // -------------------------------------------------------------------------

    public function enqueue_assets(string $hook): void
    {
        if (strpos($hook, self::SLUG) === false) {
            return;
        }

        wp_enqueue_style('ftea-admin', FTEA_PLUGIN_URL . 'assets/css/admin.css', [], FTEA_VERSION);
        wp_enqueue_script('ftea-admin', FTEA_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], FTEA_VERSION, true);
        wp_localize_script('ftea-admin', 'ftea', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ftea_section_preview'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a section-index → log-entry map from the stored log_entries array.
     */
    private function build_log_map(array $log_entries): array
    {
        $map = [];
        foreach ($log_entries as $entry) {
            if (isset($entry['index'])) {
                $map[(int) $entry['index']] = $entry;
            }
        }
        return $map;
    }

    // -------------------------------------------------------------------------
    // Debug panel (Phase 7)
    // -------------------------------------------------------------------------

    private function render_debug_panel(array $sections): void
    {
        if (empty($sections)) {
            return;
        }

        $total_elements = 0;
        $total_images   = 0;
        $has_image_bg   = 0;
        $has_gradient   = 0;
        $has_solid_bg   = 0;
        $auto_layout    = 0;
        $frame_sections = 0;

        foreach ($sections as $sec) {
            $total_elements += (int) ($sec['element_count'] ?? 0);
            $figma_type      = as_str($sec['figma_type'] ?? '');
            $bg_type         = as_str($sec['background_type'] ?? 'none');

            if ('FRAME' === $figma_type) {
                $frame_sections++;
            }
            if ('image' === $bg_type) {
                $has_image_bg++;
            } elseif ('gradient' === $bg_type) {
                $has_gradient++;
            } elseif ('solid' === $bg_type) {
                $has_solid_bg++;
            }
            if (! empty($sec['is_auto_layout'])) {
                $auto_layout++;
            }
        }
        ?>
        <details class="ftea-debug-panel">
            <summary><?php esc_html_e('Debug Panel — Section Analysis', 'ftea'); ?></summary>

            <div class="ftea-debug-grid">
                <div class="ftea-debug-stat">
                    <span class="ftea-debug-num"><?php echo count($sections); ?></span>
                    <span class="ftea-debug-label"><?php esc_html_e('Sections', 'ftea'); ?></span>
                </div>
                <div class="ftea-debug-stat">
                    <span class="ftea-debug-num"><?php echo $frame_sections; ?></span>
                    <span class="ftea-debug-label"><?php esc_html_e('FRAME nodes', 'ftea'); ?></span>
                </div>
                <div class="ftea-debug-stat">
                    <span class="ftea-debug-num"><?php echo $total_elements; ?></span>
                    <span class="ftea-debug-label"><?php esc_html_e('Total nodes', 'ftea'); ?></span>
                </div>
                <div class="ftea-debug-stat">
                    <span class="ftea-debug-num"><?php echo $auto_layout; ?></span>
                    <span class="ftea-debug-label"><?php esc_html_e('Auto-layout', 'ftea'); ?></span>
                </div>
                <div class="ftea-debug-stat">
                    <span class="ftea-debug-num"><?php echo $has_image_bg; ?></span>
                    <span class="ftea-debug-label"><?php esc_html_e('Image BG', 'ftea'); ?></span>
                </div>
                <div class="ftea-debug-stat">
                    <span class="ftea-debug-num"><?php echo $has_gradient; ?></span>
                    <span class="ftea-debug-label"><?php esc_html_e('Gradient BG', 'ftea'); ?></span>
                </div>
                <div class="ftea-debug-stat">
                    <span class="ftea-debug-num"><?php echo $has_solid_bg; ?></span>
                    <span class="ftea-debug-label"><?php esc_html_e('Solid BG', 'ftea'); ?></span>
                </div>
            </div>

            <table class="wp-list-table widefat striped ftea-debug-table" style="margin-top:12px">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php esc_html_e('Name', 'ftea'); ?></th>
                        <th><?php esc_html_e('Figma Type', 'ftea'); ?></th>
                        <th><?php esc_html_e('Layout', 'ftea'); ?></th>
                        <th><?php esc_html_e('Background', 'ftea'); ?></th>
                        <th><?php esc_html_e('BG Color', 'ftea'); ?></th>
                        <th><?php esc_html_e('Y', 'ftea'); ?></th>
                        <th><?php esc_html_e('H (px)', 'ftea'); ?></th>
                        <th><?php esc_html_e('Nodes', 'ftea'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sections as $sec) :
                        $bg_type = as_str($sec['background_type'] ?? 'none');
                        $bg_css  = as_str($sec['background'] ?? '');
                        $is_auto = ! empty($sec['is_auto_layout']);
                        $f_type  = as_str($sec['figma_type'] ?? '—');
                        $badge_cls = [
                            'image'    => 'ftea-badge-done',
                            'gradient' => 'ftea-badge-pending',
                            'solid'    => '',
                            'none'     => 'ftea-badge-error',
                        ][$bg_type] ?? '';
                    ?>
                        <tr>
                            <td><?php echo (int) $sec['index'] + 1; ?></td>
                            <td><?php echo esc_html($sec['name'] ?? ''); ?></td>
                            <td><code><?php echo esc_html($f_type); ?></code></td>
                            <td>
                                <?php if ($is_auto) : ?>
                                    <span class="ftea-badge ftea-badge-done"><?php esc_html_e('flex', 'ftea'); ?></span>
                                <?php else : ?>
                                    <span class="ftea-badge ftea-badge-pending"><?php esc_html_e('abs', 'ftea'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="ftea-badge <?php echo esc_attr($badge_cls); ?>">
                                    <?php echo esc_html($bg_type); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($bg_css) : ?>
                                    <span class="ftea-color-swatch" style="background:<?php echo esc_attr($bg_css); ?>"></span>
                                    <code style="font-size:10px"><?php echo esc_html($bg_css); ?></code>
                                <?php else : ?>
                                    <span class="ftea-meta">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="ftea-meta"><?php echo (int) ($sec['y'] ?? 0); ?>px</td>
                            <td class="ftea-meta"><?php echo (int) ($sec['height'] ?? 0); ?>px</td>
                            <td class="ftea-meta"><?php echo (int) ($sec['element_count'] ?? 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
        <?php
    }
}
