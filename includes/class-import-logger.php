<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Per-request import logger.
 *
 * Tracks section-level metrics and persists them in the admin-page session
 * so the UI can display a detailed status table.
 */
final class Import_Logger
{
    private const MAX_HISTORY = 10;

    /** @var array<string, mixed>[] */
    private array $entries = [];

    private string $session_id = '';

    // -------------------------------------------------------------------------
    // Session lifecycle
    // -------------------------------------------------------------------------

    public function start_session(string $file_key, string $frame_name): string
    {
        $this->session_id = 'ftea_' . date('Ymd_His') . '_' . substr(md5($file_key . $frame_name), 0, 6);
        $this->entries    = [];

        $this->add([
            'type'       => 'session_start',
            'file_key'   => $file_key,
            'frame_name' => $frame_name,
            'timestamp'  => current_time('mysql'),
        ]);

        return $this->session_id;
    }

    public function finish_session(int $page_id): void
    {
        $this->add([
            'type'      => 'session_end',
            'page_id'   => $page_id,
            'timestamp' => current_time('mysql'),
        ]);

        $this->persist();
    }

    // -------------------------------------------------------------------------
    // Section logging
    // -------------------------------------------------------------------------

    /**
     * @param array{found:int,downloaded:int,failed:int,svg:int,errors:string[]} $asset_stats
     * @param array{frames:int,texts:int,images:int,svgs:int,buttons:int,ignored:int} $parse_stats
     */
    public function log_section(
        int    $index,
        string $name,
        float  $y,
        float  $height,
        string $status,
        array  $asset_stats = [],
        array  $parse_stats = []
    ): void {
        $this->add([
            'type'           => 'section',
            'index'          => $index,
            'name'           => $name,
            'y'              => round($y),
            'height'         => round($height),
            'status'         => $status,
            'images_found'   => $asset_stats['found']      ?? 0,
            'images_ok'      => $asset_stats['downloaded'] ?? 0,
            'images_failed'  => $asset_stats['failed']     ?? 0,
            'svgs_ok'        => $asset_stats['svg']        ?? 0,
            'errors'         => $asset_stats['errors']     ?? [],
            'elements'       => ($parse_stats['frames'] ?? 0)
                              + ($parse_stats['texts']  ?? 0)
                              + ($parse_stats['images'] ?? 0)
                              + ($parse_stats['buttons'] ?? 0),
            'texts'          => $parse_stats['texts']   ?? 0,
            'buttons'        => $parse_stats['buttons'] ?? 0,
            'timestamp'      => current_time('mysql'),
        ]);
    }

    public function log_error(string $message, array $context = []): void
    {
        $this->add([
            'type'      => 'error',
            'message'   => $message,
            'context'   => $context,
            'timestamp' => current_time('mysql'),
        ]);
        ftea_log('Import error: ' . $message, $context);
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function get_session_id(): string
    {
        return $this->session_id;
    }

    /** @return array<string, mixed>[] */
    public function get_entries(): array
    {
        return $this->entries;
    }

    /**
     * Return only 'section' type entries — used to populate the per-section
     * log table in the admin UI.
     *
     * @return array<string, mixed>[]
     */
    public function get_section_entries(): array
    {
        return array_values(array_filter($this->entries, fn ($e) => ($e['type'] ?? '') === 'section'));
    }

    /** @param array<string, mixed>[] $entries Restore from session. */
    public function restore_entries(array $entries): void
    {
        $this->entries = $entries;
    }

    /** Most-recent sessions from WP options (history). */
    public function get_recent_sessions(int $limit = 5): array
    {
        $all = get_option('ftea_import_log', []);
        if (! is_array($all)) {
            return [];
        }
        return array_slice(array_reverse($all), 0, min($limit, count($all)));
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function add(array $entry): void
    {
        $this->entries[] = $entry;
    }

    private function persist(): void
    {
        $all = get_option('ftea_import_log', []);
        if (! is_array($all)) {
            $all = [];
        }
        $all[] = ['session_id' => $this->session_id, 'entries' => $this->entries];

        if (count($all) > self::MAX_HISTORY) {
            $all = array_slice($all, -self::MAX_HISTORY);
        }

        update_option('ftea_import_log', $all);
    }
}
