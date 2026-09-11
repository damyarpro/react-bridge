<?php
if (!defined('ABSPATH')) exit;

final class RB_Seo
{
    public static function init(): void
    {
        add_action('template_redirect', [self::class, 'maybe_redirect_frontend']);
        add_filter('get_canonical_url', [self::class, 'canonical'], 10, 2);
    }

    /** WooCommerce storefront pages must stay server-rendered by WordPress. */
    private static function is_woocommerce_page(): bool
    {
        return function_exists('is_woocommerce') && (is_woocommerce() || is_cart() || is_checkout() || is_account_page());
    }

    /** When enabled, WordPress pages 301 to the frontend so duplicate content is never indexed. */
    public static function maybe_redirect_frontend(): void
    {
        if (!RB_Settings::get('redirect_to_frontend') || !RB_Settings::get('frontend_url')) return;
        if (is_admin() || is_feed() || is_robots() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
        if (is_preview() || (is_user_logged_in() && current_user_can('edit_posts') && isset($_GET['preview']))) return;
        if (self::is_woocommerce_page()) return;

        if (is_singular('post')) {
            wp_redirect(RB_Settings::post_url(get_queried_object()), 301);
            exit;
        }
        if (is_singular('page')) {
            if (is_front_page()) {
                wp_redirect(RB_Settings::frontend_url(), 301);
                exit;
            }
            if (!RB_Settings::get('expose_pages')) return;
            wp_redirect(RB_Settings::post_url(get_queried_object()), 301);
            exit;
        }
        if (is_front_page() || is_home()) {
            wp_redirect(RB_Settings::frontend_url(is_home() && !is_front_page() ? (string) RB_Settings::get('blog_path') : ''), 301);
            exit;
        }
        if (is_category() || is_tag()) {
            $t = get_queried_object();
            wp_redirect(RB_Settings::frontend_url(trim((string) RB_Settings::get('blog_path'), '/') . '?' . ($t->taxonomy === 'category' ? 'category' : 'tag') . '=' . $t->slug), 301);
            exit;
        }
    }

    public static function canonical($url, $post)
    {
        if (!RB_Settings::get('frontend_url') || !$post instanceof WP_Post || !in_array($post->post_type, ['post', 'page'], true)) return $url;
        return RB_Settings::post_url($post);
    }

    /** Reads one SEO value in priority order: this plugin, Yoast, Rank Math, then the fallback. */
    private static function meta(int $id, string $own, array $others, string $fallback = ''): string
    {
        $v = get_post_meta($id, $own, true);
        if ($v !== '') return (string) $v;
        foreach ($others as $k) {
            $v = get_post_meta($id, $k, true);
            if ($v !== '' && !str_contains((string) $v, '%%') && !str_contains((string) $v, '%sep%')) return (string) $v;
        }
        return $fallback;
    }

    public static function build(WP_Post $post): array
    {
        $s        = RB_Settings::all();
        $site     = $s['site_name'] ?: get_bloginfo('name');
        $title    = self::meta($post->ID, '_rb_seo_title', ['_yoast_wpseo_title', 'rank_math_title']);
        $title    = $title ?: str_replace(['%title%', '%site%'], [wp_strip_all_tags(get_the_title($post)), $site], $s['seo_title_tpl']);
        $desc     = self::meta($post->ID, '_rb_seo_desc', ['_yoast_wpseo_metadesc', 'rank_math_description']);
        $desc     = $desc ?: wp_trim_words(wp_strip_all_tags(get_the_excerpt($post) ?: $post->post_content), 28, '…');
        $desc     = $desc ?: $s['default_desc'];
        $noindex  = (bool) get_post_meta($post->ID, '_rb_noindex', true)
                 || get_post_meta($post->ID, '_yoast_wpseo_meta-robots-noindex', true) == '1'
                 || in_array('noindex', (array) get_post_meta($post->ID, 'rank_math_robots', true), true);
        $og       = get_post_meta($post->ID, '_rb_og_image', true)
                 ?: (get_the_post_thumbnail_url($post, 'large') ?: $s['default_og']);
        $url      = RB_Settings::post_url($post);
        $author   = get_the_author_meta('display_name', $post->post_author);

        $jsonld = [
            '@context'         => 'https://schema.org',
            '@type'            => $post->post_type === 'page' ? 'WebPage' : 'BlogPosting',
            'headline'         => wp_strip_all_tags(get_the_title($post)),
            'description'      => $desc,
            'url'              => $url,
            'mainEntityOfPage' => $url,
            'datePublished'    => get_the_date('c', $post),
            'dateModified'     => get_the_modified_date('c', $post),
            'inLanguage'       => str_replace('_', '-', $s['locale']),
            'author'           => ['@type' => 'Person', 'name' => $author],
            'publisher'        => ['@type' => 'Organization', 'name' => $site] + ($s['default_og'] ? ['logo' => ['@type' => 'ImageObject', 'url' => $s['default_og']]] : []),
        ];
        if ($og) $jsonld['image'] = $og;

        $seo = [
            'title'       => $title,
            'description' => $desc,
            'canonical'   => $url,
            'robots'      => $noindex ? 'noindex, nofollow' : 'index, follow, max-image-preview:large',
            'og'          => [
                'type'        => $post->post_type === 'page' ? 'website' : 'article',
                'title'       => $title,
                'description' => $desc,
                'url'         => $url,
                'image'       => $og ?: null,
                'site_name'   => $site,
                'locale'      => $s['locale'],
            ],
            'twitter'     => ['card' => $og ? 'summary_large_image' : 'summary', 'site' => $s['twitter'] ? '@' . $s['twitter'] : null],
            'jsonld'      => $jsonld,
        ];
        $seo['head_html'] = self::head_html($seo);
        return $seo;
    }

    public static function head_html(array $seo): string
    {
        $m = fn($attr, $name, $content) => $content === null || $content === '' ? '' :
            sprintf('<meta %s="%s" content="%s">' . "\n", $attr, esc_attr($name), esc_attr($content));
        $h  = '<title>' . esc_html($seo['title']) . "</title>\n";
        $h .= $m('name', 'description', $seo['description']);
        $h .= $m('name', 'robots', $seo['robots']);
        $h .= '<link rel="canonical" href="' . esc_url($seo['canonical']) . "\">\n";
        foreach ($seo['og'] as $k => $v) $h .= $m('property', "og:$k", $v);
        foreach ($seo['twitter'] as $k => $v) $h .= $m('name', "twitter:$k", $v);
        $h .= '<script type="application/ld+json">' . wp_json_encode($seo['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . "</script>\n";
        return $h;
    }

    public static function site_seo(): array
    {
        $s = RB_Settings::all();
        return [
            'site_name'   => $s['site_name'] ?: get_bloginfo('name'),
            'description' => $s['default_desc'],
            'default_og'  => $s['default_og'],
            'twitter'     => $s['twitter'],
            'locale'      => $s['locale'],
            'title_tpl'   => $s['seo_title_tpl'],
            'sitemap'     => rest_url(RB_NS . '/sitemap.xml'),
        ];
    }
}
