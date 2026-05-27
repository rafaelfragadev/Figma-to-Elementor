<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Encodes the 12-column grid of a Figma frame.
 *
 * Construction detects common content widths from standard frame widths
 * (1920 → 1280, 1440 → 1200, etc.) so callers need only pass the frame width.
 */
final class Grid_Model
{
    public float $frame_width;
    public float $content_width;
    public float $margin_left;
    public float $margin_right;
    public int   $columns;
    public float $gutter;
    public float $column_width;

    /**
     * @param float $frame_w    Figma frame width in design units
     * @param float $content_w  Explicit content width (0 = auto-detect)
     * @param int   $cols       Number of grid columns (default 12)
     * @param float $gutter     Gap between columns in px (default 20)
     */
    public function __construct(
        float $frame_w,
        float $content_w = 0,
        int   $cols      = 12,
        float $gutter    = 20.0
    ) {
        $this->frame_width   = $frame_w;
        $this->columns       = $cols;
        $this->gutter        = $gutter;
        $this->content_width = $content_w > 0 ? $content_w : $this->detect_content_width($frame_w);
        $this->margin_left   = ($frame_w - $this->content_width) / 2.0;
        $this->margin_right  = $this->margin_left;
        $this->column_width  = $cols > 0
            ? ($this->content_width - $gutter * ($cols - 1)) / $cols
            : $this->content_width;
    }

    /**
     * Return the 1-based column index that contains the given absolute X.
     */
    public function column_start(float $abs_x, float $frame_x): int
    {
        $rel = max(0.0, $abs_x - $frame_x - $this->margin_left);
        return max(1, min($this->columns, (int) round($rel / ($this->column_width + $this->gutter)) + 1));
    }

    /**
     * Return the number of columns that a given width spans.
     */
    public function column_span(float $width): int
    {
        return max(1, min($this->columns, (int) round($width / ($this->column_width + $this->gutter))));
    }

    public function to_array(): array
    {
        return [
            'frame_width'   => $this->frame_width,
            'content_width' => $this->content_width,
            'margin_left'   => $this->margin_left,
            'margin_right'  => $this->margin_right,
            'columns'       => $this->columns,
            'gutter'        => $this->gutter,
            'column_width'  => round($this->column_width, 2),
        ];
    }

    // -------------------------------------------------------------------------

    private function detect_content_width(float $frame_w): float
    {
        static $map = [
            1920 => 1280.0,
            1440 => 1200.0,
            1366 => 1140.0,
            1280 => 1140.0,
            1024 => 960.0,
        ];
        foreach ($map as $fw => $cw) {
            if (abs($frame_w - $fw) < 80) {
                return $cw;
            }
        }
        return round($frame_w * 0.667);
    }
}
