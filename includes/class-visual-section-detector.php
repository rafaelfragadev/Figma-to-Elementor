<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Visual-first section detector.
 *
 * Downloads the exported Figma frame PNG and analyzes pixel rows to find
 * section breaks. No Figma node hierarchy is used — sections come from the
 * rendered image itself, just as a human designer would see them.
 *
 * ALGORITHM
 * ──────────
 * 1. Sample left + right edge pixels for every ROW_STEP rows.
 *    Edge pixels (8% from each side) are almost always background, not content.
 * 2. Smooth the color sequence with a moving average.
 * 3. Compute RGB-euclidean delta between consecutive smoothed samples.
 * 4. Row positions where delta > BREAK_DIST are marked as section boundaries.
 * 5. Nearby marks (within MERGE_GAP pixels) are merged into one boundary.
 * 6. Sections shorter than MIN_SECT_FRAC of total height are discarded.
 * 7. For each section, classify the background as solid, gradient, or image.
 *
 * All Y values returned are in Figma design units (absolute canvas coordinates).
 *
 * REQUIRES: PHP GD extension.
 */
final class Visual_Section_Detector
{
    private const ROW_STEP      = 3;     // analyze every Nth row
    private const EDGE_PCT      = 0.08;  // sample leftmost + rightmost 8%
    private const BREAK_DIST    = 22.0;  // RGB-euclidean distance to flag a break
    private const MERGE_GAP     = 80;    // merge breaks within this many PNG pixels
    private const MIN_SECT_FRAC = 0.04;  // discard sections shorter than 4% of PNG height
    private const SMOOTH_WIN    = 5;     // moving-average half-window (rows)
    private const GRAD_MIN_R2   = 0.65;  // monotonic channel fraction to call it a gradient
    private const BG_SOLID_VAR  = 15.0;  // RGB-stddev below which we call it solid

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * @param  string $png_data         Raw PNG bytes downloaded from Figma CDN
     * @param  float  $frame_h_design   Frame height in Figma design units
     * @param  float  $frame_y_abs      Absolute canvas Y of the frame's top edge
     * @param  float  $frame_x_abs      Absolute canvas X of the frame's left edge
     * @return array  Visual section descriptors, sorted top to bottom.
     *                Each entry: {y_start_abs, y_end_abs, y_start_rel, height,
     *                             bg_type, bg_color, bg_gradient}
     */
    public function detect(
        string $png_data,
        float  $frame_h_design,
        float  $frame_y_abs = 0.0,
        float  $frame_x_abs = 0.0
    ): array {
        if (! extension_loaded('gd') || empty($png_data)) {
            return [];
        }

        $img = @imagecreatefromstring($png_data);
        if (! $img) {
            return [];
        }

        $png_w = imagesx($img);
        $png_h = imagesy($img);

        if ($png_w < 10 || $png_h < 20) {
            imagedestroy($img);
            return [];
        }

        $breaks   = $this->find_breaks($img, $png_w, $png_h);
        $sections = $this->build_sections(
            $img, $breaks, $png_w, $png_h,
            $frame_h_design, $frame_y_abs
        );

        imagedestroy($img);
        return $sections;
    }

    // =========================================================================
    // Break detection
    // =========================================================================

    private function find_breaks($img, int $w, int $h): array
    {
        // 1. Sample edge color per row
        $row_colors = [];
        for ($y = 0; $y < $h; $y += self::ROW_STEP) {
            $row_colors[$y] = $this->sample_row_edge($img, $y, $w);
        }

        // 2. Smooth to suppress content noise
        $smoothed = $this->smooth_colors($row_colors);

        // 3. Compute per-row delta
        $keys  = array_keys($smoothed);
        $n     = count($keys);
        $deltas = [];

        for ($i = 1; $i < $n; $i++) {
            $y = $keys[$i];
            $deltas[$y] = self::rgb_dist($smoothed[$keys[$i - 1]], $smoothed[$y]);
        }

        // 4. Collect rows above threshold
        $raw = [];
        foreach ($deltas as $y => $d) {
            if ($d >= self::BREAK_DIST) {
                $raw[] = $y;
            }
        }

        // 5. Merge close candidates
        return $this->merge_breaks($raw, self::MERGE_GAP);
    }

    /**
     * Average edge pixels (left margin + right margin) at row $y.
     * Returns ['r'=>int,'g'=>int,'b'=>int].
     */
    private function sample_row_edge($img, int $y, int $w): array
    {
        $edge = max(3, (int)($w * self::EDGE_PCT));
        $r = $g = $b = $n = 0;

        for ($x = 0; $x < $edge; $x++) {
            $px = imagecolorat($img, $x, $y);
            $r += ($px >> 16) & 0xFF;
            $g += ($px >> 8)  & 0xFF;
            $b += $px & 0xFF;
            $n++;
        }

        for ($x = max(0, $w - $edge); $x < $w; $x++) {
            $px = imagecolorat($img, $x, $y);
            $r += ($px >> 16) & 0xFF;
            $g += ($px >> 8)  & 0xFF;
            $b += $px & 0xFF;
            $n++;
        }

        if ($n === 0) {
            return ['r' => 0, 'g' => 0, 'b' => 0];
        }

        return [
            'r' => (int) round($r / $n),
            'g' => (int) round($g / $n),
            'b' => (int) round($b / $n),
        ];
    }

    private function smooth_colors(array $rows): array
    {
        $keys   = array_keys($rows);
        $n      = count($keys);
        $result = [];

        for ($i = 0; $i < $n; $i++) {
            $r = $g = $b = $cnt = 0;
            $lo = max(0, $i - self::SMOOTH_WIN);
            $hi = min($n - 1, $i + self::SMOOTH_WIN);

            for ($j = $lo; $j <= $hi; $j++) {
                $c    = $rows[$keys[$j]];
                $r   += $c['r'];
                $g   += $c['g'];
                $b   += $c['b'];
                $cnt++;
            }

            $result[$keys[$i]] = [
                'r' => (int) round($r / $cnt),
                'g' => (int) round($g / $cnt),
                'b' => (int) round($b / $cnt),
            ];
        }

        return $result;
    }

    private static function rgb_dist(array $a, array $b): float
    {
        return sqrt(
            ($a['r'] - $b['r']) ** 2 +
            ($a['g'] - $b['g']) ** 2 +
            ($a['b'] - $b['b']) ** 2
        );
    }

    private function merge_breaks(array $breaks, int $gap): array
    {
        if (empty($breaks)) {
            return [];
        }

        sort($breaks);
        $merged = [];
        $cur    = $breaks[0];

        for ($i = 1; $i < count($breaks); $i++) {
            if ($breaks[$i] - $cur <= $gap) {
                // Take midpoint of the cluster
                $cur = (int)(($cur + $breaks[$i]) / 2);
            } else {
                $merged[] = $cur;
                $cur      = $breaks[$i];
            }
        }
        $merged[] = $cur;

        return $merged;
    }

    // =========================================================================
    // Section model builder
    // =========================================================================

    private function build_sections(
        $img,
        array $breaks,
        int   $png_w,
        int   $png_h,
        float $frame_h_design,
        float $frame_y_abs
    ): array {
        $boundaries = array_unique(array_merge([0], $breaks, [$png_h]));
        sort($boundaries);

        $min_px  = (int)($png_h * self::MIN_SECT_FRAC);
        $sections = [];

        for ($i = 0; $i + 1 < count($boundaries); $i++) {
            $y0 = $boundaries[$i];
            $y1 = $boundaries[$i + 1];

            if (($y1 - $y0) < $min_px) {
                continue;
            }

            // Convert PNG pixels → design units
            $y_start_design = $frame_y_abs + ($y0 / $png_h) * $frame_h_design;
            $y_end_design   = $frame_y_abs + ($y1 / $png_h) * $frame_h_design;
            $height_design  = $y_end_design - $y_start_design;

            $bg = $this->classify_bg($img, $y0, $y1, $png_w);

            $sections[] = [
                'y_start_abs' => $y_start_design,
                'y_end_abs'   => $y_end_design,
                'y_start_rel' => $y_start_design - $frame_y_abs,
                'height'      => $height_design,
                'bg_type'     => $bg['type'],
                'bg_color'    => $bg['color']    ?? '',
                'bg_gradient' => $bg['gradient'] ?? null,
            ];
        }

        return $sections;
    }

    // =========================================================================
    // Background classification
    // =========================================================================

    private function classify_bg($img, int $y0, int $y1, int $w): array
    {
        $samples = min(14, max(4, (int)(($y1 - $y0) / 20)));
        $step    = max(1, (int)(($y1 - $y0) / $samples));
        $colors  = [];

        for ($y = $y0; $y < $y1 && count($colors) < $samples; $y += $step) {
            $colors[] = $this->sample_row_edge($img, min($y, $y1 - 1), $w);
        }

        if (empty($colors)) {
            return ['type' => 'none'];
        }

        $n     = count($colors);
        $avg_r = array_sum(array_column($colors, 'r')) / $n;
        $avg_g = array_sum(array_column($colors, 'g')) / $n;
        $avg_b = array_sum(array_column($colors, 'b')) / $n;

        $variance = 0.0;
        foreach ($colors as $c) {
            $variance += ($c['r'] - $avg_r) ** 2
                       + ($c['g'] - $avg_g) ** 2
                       + ($c['b'] - $avg_b) ** 2;
        }
        $variance = sqrt($variance / $n);

        // ── Solid ──────────────────────────────────────────────────────────
        if ($variance < self::BG_SOLID_VAR) {
            return [
                'type'  => 'solid',
                'color' => $this->to_hex((int) round($avg_r), (int) round($avg_g), (int) round($avg_b)),
            ];
        }

        // ── Gradient ───────────────────────────────────────────────────────
        if ($this->looks_like_gradient($colors)) {
            $first = $colors[0];
            $last  = $colors[$n - 1];
            $ca    = $this->to_hex($first['r'], $first['g'], $first['b']);
            $cb    = $this->to_hex($last['r'],  $last['g'],  $last['b']);
            return [
                'type'     => 'gradient',
                'color'    => $ca,
                'gradient' => [
                    'gradient_type' => 'linear',
                    'color_a'       => $ca,
                    'color_b'       => $cb,
                    'stop_a'        => 0,
                    'stop_b'        => 100,
                    'angle'         => 180,
                ],
            ];
        }

        // ── Image / complex ────────────────────────────────────────────────
        return ['type' => 'image'];
    }

    /**
     * Returns true when at least 2 of the 3 channels change monotonically
     * (indicating a top-to-bottom color gradient).
     */
    private function looks_like_gradient(array $colors): bool
    {
        $n = count($colors);
        if ($n < 4) {
            return false;
        }

        $monotonic = 0;
        foreach (['r', 'g', 'b'] as $ch) {
            $vals = array_column($colors, $ch);
            $ups  = 0;
            $dn   = 0;

            for ($i = 1; $i < $n; $i++) {
                if ($vals[$i] > $vals[$i - 1] + 4) {
                    $ups++;
                } elseif ($vals[$i] < $vals[$i - 1] - 4) {
                    $dn++;
                }
            }

            $total = $ups + $dn;
            if ($total >= 2 && (max($ups, $dn) / $total) >= self::GRAD_MIN_R2) {
                $monotonic++;
            }
        }

        return $monotonic >= 2;
    }

    private function to_hex(int $r, int $g, int $b): string
    {
        return sprintf(
            '#%02x%02x%02x',
            max(0, min(255, $r)),
            max(0, min(255, $g)),
            max(0, min(255, $b))
        );
    }
}
