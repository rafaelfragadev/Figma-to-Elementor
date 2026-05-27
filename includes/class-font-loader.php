<?php

namespace FigmaToElementorAtomic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Detects font families from the parsed Figma tree and enqueues them via Google Fonts.
 *
 * Usage:
 *   $loader = new Font_Loader();
 *   $loader->collect($parsed_tree);
 *   $loader->enqueue();                   // call inside wp_head / admin_head
 *   $report = $loader->get_report();
 */
final class Font_Loader
{
    /** @var string[] Unique font families collected from the tree */
    private array $detected = [];

    /** @var string[] Families successfully enqueued via Google Fonts */
    private array $loaded = [];

    /** @var string[] Families not found in the Google Fonts catalogue */
    private array $missing = [];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /** Walk the parsed intermediate tree and collect all unique font families. */
    public function collect(array $tree): void
    {
        $found = [];
        $this->traverse($tree, $found);
        $this->detected = array_values(array_unique(array_merge($this->detected, $found)));
    }

    /**
     * Accept a pre-built list of font family names (e.g. restored from post meta).
     *
     * @param string[] $families
     */
    public function collect_families(array $families): void
    {
        $this->detected = array_values(array_unique(array_merge($this->detected, array_filter($families, 'is_string'))));
    }

    /**
     * Enqueue detected fonts via Google Fonts.
     * Call from a `wp_head` or `admin_head` hook.
     */
    public function enqueue(): void
    {
        $to_load = [];
        $this->missing = [];

        foreach ($this->detected as $family) {
            if ($this->is_google_font($family)) {
                $to_load[] = $family;
            } else {
                $this->missing[] = $family;
            }
        }

        if (! empty($to_load)) {
            $params = array_map(
                fn ($f) => urlencode($f) . ':ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,300;1,400;1,700',
                $to_load
            );
            $url = 'https://fonts.googleapis.com/css2?family=' . implode('&family=', $params) . '&display=swap';
            wp_enqueue_style('ftea-google-fonts', esc_url_raw($url), [], null);
            $this->loaded = $to_load;
        }
    }

    /**
     * Generate a Google Fonts @import CSS string (alternative to wp_enqueue_style).
     * Useful when injecting via inline styles into a post.
     */
    public function get_import_css(): string
    {
        $to_load = array_filter($this->detected, fn ($f) => $this->is_google_font($f));
        if (empty($to_load)) {
            return '';
        }
        $params = array_map(
            fn ($f) => urlencode($f) . ':ital,wght@0,100;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,400;1,700',
            $to_load
        );
        $url = 'https://fonts.googleapis.com/css2?family=' . implode('&family=', $params) . '&display=swap';
        return "@import url('" . esc_url($url) . "');";
    }

    /**
     * @return array{detected:string[], loaded:string[], missing:string[]}
     */
    public function get_report(): array
    {
        return [
            'detected' => $this->detected,
            'loaded'   => $this->loaded,
            'missing'  => $this->missing,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function traverse(array $node, array &$found): void
    {
        $family = as_str($node['font_family'] ?? '');
        if ($family !== '') {
            $found[] = $family;
        }

        // Derive family from PostScript name if font_family is absent
        if ($family === '' && ! empty($node['font_post_script_name'])) {
            $ps  = as_str($node['font_post_script_name']);
            $fam = (string) preg_replace('/[-_](Bold|Italic|Light|Regular|Medium|SemiBold|ExtraBold|Black|Thin|Heavy|Condensed|Narrow|Wide|Expanded).*$/i', '', $ps);
            $fam = trim((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $fam) ?: $fam);
            if ($fam !== '') {
                $found[] = $fam;
            }
        }

        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->traverse($child, $found);
            }
        }
    }

    private function is_google_font(string $family): bool
    {
        $slug = strtolower(trim((string) preg_replace('/\s+/', '-', $family)));
        return in_array($slug, self::google_font_slugs(), true);
    }

    /** @return string[] */
    private static function google_font_slugs(): array
    {
        return [
            'roboto','open-sans','lato','montserrat','oswald','raleway','source-sans-pro',
            'source-sans-3','pt-sans','pt-serif','ubuntu','merriweather','nunito',
            'playfair-display','poppins','mukta','rubik','work-sans','titillium-web',
            'inconsolata','noto-sans','noto-serif','fira-sans','karla','inter','dm-sans',
            'outfit','josefin-sans','barlow','barlow-condensed','barlow-semi-condensed',
            'exo','exo-2','encode-sans','encode-sans-condensed','cabin','archivo','arimo',
            'manrope','jost','quicksand','libre-baskerville','crimson-text','eb-garamond',
            'cormorant-garamond','anton','bebas-neue','passion-one','fjalla-one','teko',
            'changa','heebo','varela-round','righteous','signika','questrial','pacifico',
            'lobster','dancing-script','great-vibes','satisfy','caveat','indie-flower',
            'amatic-sc','comfortaa','fredoka-one','russo-one','secular-one','space-grotesk',
            'sora','plus-jakarta-sans','lexend','lexend-deca','red-hat-display','red-hat-text',
            'urbanist','overpass','mulish','dosis','asap','asap-condensed','cardo','spectral',
            'lora','alice','abril-fatface','alfa-slab-one','black-ops-one','bree-serif',
            'cinzel','forum','ibm-plex-mono','ibm-plex-sans','ibm-plex-serif','josefin-slab',
            'kaushan-script','league-spartan','marcellus','old-standard-tt','philosopher',
            'poiret-one','source-code-pro','source-serif-4','tangerine','yeseva-one',
            'zilla-slab','nunito-sans','figtree','be-vietnam-pro','nanum-gothic','hind',
            'prompt','kanit','sarabun','tajawal','cairo','almarai','amiri',
        ];
    }
}
