<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Converts the intermediate tree into sections.
 *
 * RULE: each direct child of the root frame is ONE section.
 * We never group children by Y-gap because adjacent sections have gap=0
 * and that would merge everything into one giant section.
 *
 * Y coordinates are normalised to frame-relative (0 = top of frame).
 */
final class Section_Detector
{
    /**
     * @param  array $tree  Output of Figma_Parser::parse()
     * @return array<int, array{index:int,name:string,y:float,height:float,background:string,element_count:int,children:array}>
     */
    public function detect(array $tree): array
    {
        // The tree is a document with one child: the selected frame container.
        $frame_container = null;
        foreach (($tree['children'] ?? []) as $root) {
            if (is_array($root)) {
                $frame_container = $root;
                break;
            }
        }

        if (! $frame_container) {
            return [];
        }

        $frame_y        = (float) ($frame_container['layout']['y'] ?? 0);
        $frame_children = is_array($frame_container['children'] ?? null)
            ? $frame_container['children']
            : [];

        // If the frame has no children, treat the frame itself as section 0.
        if (empty($frame_children)) {
            $height = (float) ($frame_container['layout']['height'] ?? 0);
            if ($height < 4) {
                return [];
            }
            return [[
                'index'         => 0,
                'name'          => as_str($frame_container['name'] ?? 'Section 1'),
                'y'             => 0.0,
                'height'        => $height,
                'background'    => as_str($frame_container['background'] ?? ''),
                'element_count' => $this->count_elements($frame_container),
                'children'      => [$frame_container],
            ]];
        }

        // Sort by absolute Y position before slicing into sections.
        usort($frame_children, static function ($a, $b) {
            return ((float) ($a['layout']['y'] ?? 0)) <=> ((float) ($b['layout']['y'] ?? 0));
        });

        $sections = [];

        foreach ($frame_children as $child) {
            if (! is_array($child)) {
                continue;
            }

            $height = (float) ($child['layout']['height'] ?? 0);
            $width  = (float) ($child['layout']['width'] ?? 0);

            // Skip zero-size phantom nodes.
            if ($height < 4 && $width < 4) {
                continue;
            }

            // Normalise Y to frame-relative so the admin UI shows sensible values.
            $abs_y = (float) ($child['layout']['y'] ?? 0);
            $rel_y = $abs_y - $frame_y;

            $sections[] = [
                'index'         => count($sections),
                'name'          => as_str($child['name'] ?? ('Section ' . (count($sections) + 1))),
                'y'             => max(0.0, $rel_y),
                'height'        => $height,
                'background'    => as_str($child['background'] ?? ''),
                'element_count' => $this->count_elements($child),
                'children'      => [$child],      // always exactly ONE top-level child
            ];
        }

        return $sections;
    }

    /**
     * Recursively count all nodes in the sub-tree (used for the log display).
     */
    public function count_elements(array $node): int
    {
        $count = 1;
        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $count += $this->count_elements($child);
            }
        }
        return $count;
    }
}
