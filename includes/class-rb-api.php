<?php
if (!defined('ABSPATH')) exit;

final class RB_Api
{
    const SITEMAP_MAX = 50000;

    /** @var array<int, array> webhook payloads queued in this request, keyed by post ID */
    private static array $queue = [];
    /** @var array<int, true> posts that left "publish" in this request */
    private static array $unpublished = [];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('rest_pre_serve_request', [self::class, 'cors'], 11, 3);
        add_filter('rest_pre_serve_request', [self::class, 'serve_raw'], 20, 3);

        // Cache invalidation - only on changes that affect rb/v1 output.
        add_action('save_post', [self::class, 'on_save_post'], 20, 2);
        add_action('deleted_post', [self::class, 'on_deleted_post'], 10, 2);
        add_action('trashed_post', [self::class, 'on_deleted_post']);
        foreach (['created_term', 'edited_term', 'delete_term'] as $h) add_action($h, [self::class, 'on_term'], 10, 3);
        add_action('wp_update_nav_menu', [self::class, 'flush_cache']);
        foreach (['blogname', 'blogdescription', 'permalink_structure'] as $o) add_action("update_option_$o", [self::class, 'flush_cache']);

        // Revalidation webhook - queued per post, sent once at shutdown.
        add_action('transition_post_status', [self::class, 'on_transition'], 10, 3);
        add_action('before_delete_post', [self::class, 'on_before_delete'], 10, 2);
        add_action('shutdown', [self::class, 'send_webhooks']);
    }

    /* ---------- CORS (rb/v1 routes only; core CORS stays intact elsewhere) ---------- */
    private static function is_rb_route($request): bool
    {
        if (!$request instanceof WP_REST_Request) return false;
        $route = $request->get_route();
        return $route === '/' . RB_NS || str_starts_with($route, '/' . RB_NS . '/');
    }

    public static function cors($served, $result, $request)
    {
        if (headers_sent() || !self::is_rb_route($request)) return $served;
        foreach (['Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods'] as $h) header_remove($h);
        self::vary(['Origin']);
        $origin = strtolower(trim((string) get_http_origin()));
        if ($origin !== '' && in_array($origin, RB_Settings::origins(), true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: GET, PATCH, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-RB-Key, If-None-Match, X-WP-Nonce');
            header('Access-Control-Expose-Headers: X-RB-Total, X-RB-TotalPages, ETag, X-RB-Ignored-Fields');
            header('Access-Control-Max-Age: 86400');
        }
        return $served;
    }

    /** Merge tokens into a single Vary header without dropping ones already sent (e.g. "Origin, X-RB-Key" from respond()). */
    private static function vary(array $add): void
    {
        $tokens = [];
        foreach (headers_list() as $h) {
            if (stripos($h, 'Vary:') !== 0) continue;
            foreach (explode(',', substr($h, 5)) as $t) if (($t = trim($t)) !== '') $tokens[strtolower($t)] = $t;
        }
        foreach ($add as $t) $tokens[strtolower($t)] ??= $t;
        header_remove('Vary');
        header('Vary: ' . implode(', ', $tokens));
    }

    /* ---------- Auth ---------- */
    public static function permission(WP_REST_Request $r)
    {
        if (!RB_Settings::get('require_key')) return true;
        $key = $r->get_header('x_rb_key') ?: $r->get_param('key');
        if ($key && hash_equals((string) RB_Settings::get('api_key'), (string) $key)) return true;
        return new WP_Error('rb_forbidden', __('The API key is not valid.', 'react-bridge'), ['status' => 401]);
    }

    /* ---------- Routes ---------- */
    public static function routes(): void
    {
        $get = fn(string $path, callable $cb, array $args = []) => register_rest_route(RB_NS, $path, [
            'methods' => 'GET', 'callback' => $cb, 'permission_callback' => [self::class, 'permission'], 'args' => $args,
        ]);
        $get('/manifest', [self::class, 'manifest']);
        $get('/health', fn() => ['ok' => true, 'version' => RB_VERSION, 'time' => current_time('c')]);
        $get('/posts', [self::class, 'posts'], [
            'page' => ['default' => 1], 'per_page' => [], 'category' => [], 'tag' => [], 'search' => [], 'author' => [], 'exclude' => [],
        ]);
        // Slugs arrive percent-encoded (/wp-json/...) or already decoded (?rest_route=...), and Persian
        // slugs decode to non-ASCII letters; accept any path segment and let get_page_by_path() sanitize it.
        $get('/posts/(?P<slug>[^/?#]+)', [self::class, 'post']);
        $get('/pages/(?P<slug>[^?#]+)', [self::class, 'page']);
        $get('/taxonomies', [self::class, 'taxonomies']);
        $get('/render', [self::class, 'render'], ['path' => ['required' => true]]);
        $get('/sitemap.xml', [self::class, 'sitemap']);
    }

    /* ---------- Cache ---------- */
    /** Transient name for a logical cache key; the generation makes flushing O(1). Public for tests. */
    public static function cache_key(string $key): string
    {
        return 'rb_' . md5(RB_Settings::cache_gen() . '|' . RB_VERSION . '|' . $key);
    }

    /**
     * Read-through cache. Never stores null/false (not-found must not be cached).
     * $store decides for a computed value whether it is worth keeping (bounds user-driven key growth).
     */
    private static function cached(string $key, callable $fn, ?callable $store = null)
    {
        $ttl = (int) RB_Settings::get('cache_ttl_seconds');
        if ($ttl <= 0) return $fn();
        $k   = self::cache_key($key);
        $hit = get_transient($k);
        if ($hit !== false) return $hit;
        $v = $fn();
        if ($v !== null && $v !== false && (!$store || $store($v))) set_transient($k, $v, $ttl);
        return $v;
    }

    public static function flush_cache(): void
    {
        RB_Settings::bump_cache_gen();
    }

    private static function is_content($post): bool
    {
        return $post instanceof WP_Post && in_array($post->post_type, ['post', 'page'], true)
            && !wp_is_post_revision($post) && !wp_is_post_autosave($post);
    }

    /** Draft-only saves do not touch public output, so they keep the cache warm. */
    public static function on_save_post(int $id, WP_Post $post): void
    {
        if (!self::is_content($post)) return;
        if ($post->post_status === 'publish' || isset(self::$unpublished[$id])) self::flush_cache();
    }

    public static function on_deleted_post(int $id, $post = null): void
    {
        if (self::is_content($post instanceof WP_Post ? $post : get_post($id))) self::flush_cache();
    }

    public static function on_term($term, $tt_id, $taxonomy): void
    {
        if (in_array($taxonomy, ['category', 'post_tag'], true)) self::flush_cache();
    }

    public static function cache_control(): string
    {
        $ttl = (int) RB_Settings::get('cache_ttl_seconds');
        if ($ttl <= 0) return 'no-store';
        return (RB_Settings::get('require_key') ? 'private' : 'public') . ', max-age=' . $ttl;
    }

    /** RFC 9110 If-None-Match: comma list, weak comparison (W/ ignored), "*" matches any. */
    public static function etag_matches(string $etag, string $header): bool
    {
        foreach (explode(',', $header) as $t) {
            $t = trim($t);
            if (strncasecmp($t, 'W/', 2) === 0) $t = substr($t, 2);
            if ($t !== '' && ($t === '*' || $t === $etag)) return true;
        }
        return false;
    }

    private static function respond($data, ?WP_REST_Request $r = null, array $headers = []): WP_REST_Response
    {
        $res  = new WP_REST_Response($data);
        $etag = '"' . md5((string) wp_json_encode($data)) . '"';
        $res->header('ETag', $etag);
        $res->header('Cache-Control', self::cache_control());
        $res->header('Vary', 'Origin, X-RB-Key');
        foreach ($headers as $k => $v) $res->header($k, (string) $v);
        $inm = $r ? (string) $r->get_header('if_none_match') : '';
        if ($inm === '') $inm = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if ($inm !== '' && self::etag_matches($etag, $inm)) {
            $res->set_status(304);
            $res->set_data(null);
        }
        return $res;
    }

    /**
     * Reading direction of the rendered site: the WordPress text direction first (it knows every
     * RTL locale a translation ships with), then the configured content locale as a fallback for
     * sites whose admin language differs from the published content.
     */
    public static function is_rtl_locale(string $locale): bool
    {
        return is_rtl() || in_array(substr($locale, 0, 2), ['fa', 'ar', 'he'], true);
    }

    /* ---------- Formatters ---------- */
    public static function reading_time(string $content): int
    {
        $words = preg_split('/\s+/u', wp_strip_all_tags($content), -1, PREG_SPLIT_NO_EMPTY);
        return max(1, (int) ceil(count($words ?: []) / 200));
    }

    public static function format_post(WP_Post $p, bool $full = true): array
    {
        $img = null;
        if ($tid = get_post_thumbnail_id($p)) {
            $meta = wp_get_attachment_metadata($tid);
            $img = [
                'src'    => wp_get_attachment_image_url($tid, 'large'),
                'full'   => wp_get_attachment_image_url($tid, 'full'),
                'srcset' => wp_get_attachment_image_srcset($tid, 'large') ?: null,
                'alt'    => (string) get_post_meta($tid, '_wp_attachment_image_alt', true),
                'width'  => $meta['width'] ?? null,
                'height' => $meta['height'] ?? null,
            ];
        }
        $out = [
            'id'           => $p->ID,
            'type'         => $p->post_type,
            'slug'         => $p->post_name,
            'url'          => RB_Settings::post_url($p),
            'date'         => get_the_date('c', $p),
            'modified'     => get_the_modified_date('c', $p),
            'title'        => wp_strip_all_tags(get_the_title($p)),
            'title_html'   => get_the_title($p),
            'excerpt'      => wp_strip_all_tags(get_the_excerpt($p)),
            'reading_time' => self::reading_time($p->post_content),
            'author'       => [
                'name'   => get_the_author_meta('display_name', $p->post_author),
                'avatar' => get_avatar_url($p->post_author, ['size' => 96]),
            ],
            'image'        => $img,
            'categories'   => self::terms($p, 'category'),
            'tags'         => self::terms($p, 'post_tag'),
        ];
        if ($full) {
            // the_content filters (shortcodes, blocks, <!--nextpage-->) expect the global post context.
            $GLOBALS['post'] = $p;
            setup_postdata($p);
            $out['content'] = apply_filters('the_content', $p->post_content);
            $out['seo']     = RB_Seo::build($p);
            $out['prev']    = self::adjacent($p, true);
            $out['next']    = self::adjacent($p, false);
            wp_reset_postdata();
        }
        return apply_filters('rb_format_post', $out, $p, $full);
    }

    private static function terms(WP_Post $p, string $tax): array
    {
        $t = get_the_terms($p, $tax);
        if (!$t || is_wp_error($t)) return [];
        return array_values(array_map(fn($x) => ['id' => $x->term_id, 'name' => $x->name, 'slug' => $x->slug], $t));
    }

    private static function adjacent(WP_Post $p, bool $prev): ?array
    {
        if ($p->post_type !== 'post') return null;
        $GLOBALS['post'] = $p;
        $a = $prev ? get_previous_post() : get_next_post();
        return $a ? ['slug' => $a->post_name, 'title' => wp_strip_all_tags(get_the_title($a)), 'url' => RB_Settings::post_url($a)] : null;
    }

    /* ---------- Endpoints ---------- */
    public static function manifest(WP_REST_Request $r): WP_REST_Response
    {
        $data = self::cached('manifest', function () {
            $s = RB_Settings::all();
            $menus = [];
            foreach (get_nav_menu_locations() as $loc => $id) {
                $items = wp_get_nav_menu_items($id) ?: [];
                $menus[$loc] = array_values(array_map(fn($i) => [
                    'id' => $i->ID, 'title' => $i->title, 'url' => $i->url, 'parent' => (int) $i->menu_item_parent, 'target' => $i->target,
                ], $items));
            }
            $base = rest_url(RB_NS);
            $pp   = (int) $s['posts_per_page'];
            return [
                'version'      => RB_VERSION,
                'site'         => ['name' => $s['site_name'] ?: get_bloginfo('name'), 'url' => home_url(), 'frontend_url' => $s['frontend_url'], 'blog_path' => $s['blog_path'], 'locale' => $s['locale'], 'rtl' => self::is_rtl_locale((string) $s['locale'])],
                'seo'          => RB_Seo::site_seo(),
                'per_page'     => $pp,
                'auth'         => ['required' => (bool) $s['require_key'], 'header' => 'X-RB-Key'],
                'endpoints'    => [
                    'manifest'   => $base . '/manifest',
                    'posts'      => $base . '/posts?page=1&per_page=' . $pp . '&category=&tag=&search=',
                    'post'       => $base . '/posts/{slug}',
                    'page'       => $base . '/pages/{slug}',
                    'taxonomies' => $base . '/taxonomies',
                    'render'     => $base . '/render?path={path}',
                    'sitemap'    => $base . '/sitemap.xml',
                    'health'     => $base . '/health',
                ],
                'taxonomies'   => self::taxonomies_data(),
                'menus'        => $menus,
                'pages'        => $s['expose_pages'] ? array_map(fn($pg) => ['slug' => $pg->post_name, 'title' => $pg->post_title, 'url' => RB_Settings::post_url($pg)], get_pages(['post_status' => 'publish'])) : [],
                'counts'       => ['posts' => (int) wp_count_posts()->publish, 'pages' => (int) wp_count_posts('page')->publish],
            ];
        });
        return self::respond($data, $r);
    }

    public static function posts(WP_REST_Request $r): WP_REST_Response
    {
        $pp = (int) $r['per_page'];
        if ($pp === 0) $pp = (int) RB_Settings::get('posts_per_page', 10);
        $args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'paged'          => max(1, (int) $r['page']),
            'posts_per_page' => max(1, min(50, $pp)),
            's'              => sanitize_text_field((string) $r['search']),
            'author_name'    => sanitize_text_field((string) $r['author']),
            'post__not_in'   => array_values(array_filter(array_map('intval', explode(',', (string) $r['exclude'])))),
        ];
        if ($r['category']) $args['category_name'] = sanitize_text_field((string) $r['category']);
        if ($r['tag']) $args['tag'] = sanitize_text_field((string) $r['tag']);

        $build = function () use ($args) {
            $q = new WP_Query($args);
            return [
                'items'       => array_map(fn($p) => self::format_post($p, false), $q->posts),
                'total'       => (int) $q->found_posts,
                'total_pages' => (int) $q->max_num_pages,
                'page'        => (int) $args['paged'],
            ];
        };
        // Free-text search and empty pages are not cached: their key space is attacker-controlled.
        $data = $args['s'] !== '' ? $build() : self::cached('posts:' . wp_json_encode($args), $build, fn($v) => !empty($v['items']));
        return self::respond($data, $r, ['X-RB-Total' => $data['total'], 'X-RB-TotalPages' => $data['total_pages']]);
    }

    public static function post(WP_REST_Request $r)
    {
        $slug = urldecode((string) $r['slug']);
        $data = self::cached("post:$slug", function () use ($slug) {
            $p = get_page_by_path($slug, OBJECT, 'post');
            return $p && $p->post_status === 'publish' ? self::format_post($p) : null;
        });
        if (!$data) return new WP_Error('rb_not_found', __('Post not found.', 'react-bridge'), ['status' => 404]);
        return self::respond($data, $r);
    }

    public static function page(WP_REST_Request $r)
    {
        if (!RB_Settings::get('expose_pages')) return new WP_Error('rb_disabled', __('Pages are not available.', 'react-bridge'), ['status' => 404]);
        $slug = urldecode((string) $r['slug']);
        $data = self::cached("page:$slug", function () use ($slug) {
            $p = get_page_by_path($slug, OBJECT, 'page');
            return $p && $p->post_status === 'publish' ? self::format_post($p) : null;
        });
        if (!$data) return new WP_Error('rb_not_found', __('Page not found.', 'react-bridge'), ['status' => 404]);
        return self::respond($data, $r);
    }

    private static function taxonomies_data(): array
    {
        $map = fn(string $tax) => array_values(array_map(fn($t) => [
            'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => (int) $t->count, 'parent' => (int) $t->parent, 'description' => $t->description,
        ], get_terms(['taxonomy' => $tax, 'hide_empty' => true]) ?: []));
        return ['categories' => $map('category'), 'tags' => $map('post_tag')];
    }

    public static function taxonomies(WP_REST_Request $r): WP_REST_Response
    {
        return self::respond(self::cached('tax', fn() => self::taxonomies_data()), $r);
    }

    /* ---------- Dynamic rendering for crawlers ---------- */
    public static function render(WP_REST_Request $r)
    {
        $path = '/' . trim(parse_url((string) $r['path'], PHP_URL_PATH) ?? '', '/');
        $bp   = '/' . trim((string) RB_Settings::get('blog_path'), '/');
        $html = self::cached("render:$path", function () use ($path, $bp) {
            $s = RB_Settings::all();
            $site = $s['site_name'] ?: get_bloginfo('name');
            $dir  = self::is_rtl_locale((string) $s['locale']) ? 'rtl' : 'ltr';
            $lang = str_replace('_', '-', $s['locale']);

            $post = null; $body = ''; $head = '';
            if ($bp !== '/' && str_starts_with($path, $bp . '/')) {
                $post = get_page_by_path(substr($path, strlen($bp) + 1), OBJECT, 'post');
            } elseif ($path === $bp) {
                $q = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 50, 'no_found_rows' => true]);
                /* translators: %s: site name. */
                $blog_title = sprintf(__('Blog of %s', 'react-bridge'), $site);
                $seo = ['title' => str_replace(['%title%', '%site%'], [__('Blog', 'react-bridge'), $site], $s['seo_title_tpl']), 'description' => $s['default_desc'], 'canonical' => RB_Settings::frontend_url($bp), 'robots' => 'index, follow',
                        'og' => ['type' => 'website', 'title' => $blog_title, 'description' => $s['default_desc'], 'url' => RB_Settings::frontend_url($bp), 'image' => $s['default_og'] ?: null, 'site_name' => $site, 'locale' => $s['locale']],
                        'twitter' => ['card' => 'summary'], 'jsonld' => ['@context' => 'https://schema.org', '@type' => 'Blog', 'name' => $site, 'url' => RB_Settings::frontend_url($bp)]];
                $head = RB_Seo::head_html($seo);
                $body = '<h1>' . esc_html($blog_title) . '</h1><ul>';
                foreach ($q->posts as $p) $body .= '<li><a href="' . esc_url(RB_Settings::post_url($p)) . '">' . esc_html(get_the_title($p)) . '</a> <small>' . esc_html(get_the_date('', $p)) . '</small></li>';
                $body .= '</ul>';
            } elseif ($path !== '/') {
                $post = get_page_by_path(ltrim($path, '/'), OBJECT, 'page');
            }
            if ($post && $post->post_status === 'publish') {
                $d    = self::format_post($post);
                $head = $d['seo']['head_html'];
                $body = '<article><h1>' . $d['title_html'] . '</h1><p><time datetime="' . esc_attr($d['date']) . '">' . esc_html(get_the_date('', $post)) . '</time> &middot; ' . esc_html($d['author']['name']) . '</p>'
                      . ($d['image'] ? '<img src="' . esc_url($d['image']['src']) . '" alt="' . esc_attr($d['image']['alt']) . '">' : '')
                      . $d['content'] . '</article>';
            }
            if (!$body) return null;
            return "<!doctype html>\n<html lang=\"" . esc_attr($lang) . "\" dir=\"$dir\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n<meta name=\"generator\" content=\"React Bridge " . RB_VERSION . "\">\n$head</head>\n<body>\n<header><a href=\"" . esc_url(RB_Settings::frontend_url()) . "\">" . esc_html($site) . "</a></header>\n<main>$body</main>\n</body>\n</html>";
        });
        if (!$html) return new WP_Error('rb_not_found', __('Path not found.', 'react-bridge'), ['status' => 404]);
        return self::respond(['_raw' => true, 'type' => 'text/html', 'body' => $html], $r);
    }

    /** One query, only the columns needed; noindex excluded in SQL; capped at the 50,000-URL protocol limit. */
    private static function sitemap_rows(array $types, int $limit): array
    {
        global $wpdb;
        if ($limit <= 0) return [];
        $mq  = new WP_Meta_Query(['relation' => 'OR',
            ['key' => '_rb_noindex', 'compare' => 'NOT EXISTS'],
            ['key' => '_rb_noindex', 'value' => '1', 'compare' => '!='],
        ]);
        $sql = $mq->get_sql('post', $wpdb->posts, 'ID');
        $p   = $wpdb->posts;
        $in  = $wpdb->prepare(implode(',', array_fill(0, count($types), '%s')), ...$types);
        return $wpdb->get_results(
            "SELECT $p.ID, $p.post_name, $p.post_type, $p.post_modified_gmt FROM $p {$sql['join']}"
            . " WHERE $p.post_status = 'publish' AND $p.post_type IN ($in) {$sql['where']}"
            . " GROUP BY $p.ID ORDER BY $p.post_modified_gmt DESC"
            . $wpdb->prepare(' LIMIT %d', $limit)
        ) ?: [];
    }

    public static function sitemap(WP_REST_Request $r): WP_REST_Response
    {
        $xml = self::cached('sitemap', function () {
            $now  = current_time('c');
            $urls = [['loc' => RB_Settings::frontend_url(), 'lastmod' => $now, 'priority' => '1.0']];
            $bp   = trim((string) RB_Settings::get('blog_path'), '/');
            if ($bp) $urls[] = ['loc' => RB_Settings::frontend_url($bp), 'lastmod' => $now, 'priority' => '0.9'];
            $types = RB_Settings::get('expose_pages') ? ['post', 'page'] : ['post'];
            foreach (self::sitemap_rows($types, self::SITEMAP_MAX - count($urls)) as $row) {
                $p  = new WP_Post((object) ['ID' => (int) $row->ID, 'post_name' => $row->post_name, 'post_type' => $row->post_type, 'filter' => 'raw']);
                $ts = strtotime($row->post_modified_gmt . ' UTC');
                $urls[] = ['loc' => RB_Settings::post_url($p), 'lastmod' => $ts > 0 ? gmdate('c', $ts) : $now, 'priority' => $row->post_type === 'page' ? '0.6' : '0.8'];
            }
            $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($urls as $u) $x .= "  <url><loc>" . esc_url($u['loc']) . "</loc><lastmod>{$u['lastmod']}</lastmod><priority>{$u['priority']}</priority></url>\n";
            return $x . '</urlset>';
        });
        return self::respond(['_raw' => true, 'type' => 'application/xml', 'body' => $xml], $r);
    }

    /** Raw bodies (HTML/XML). Cache-Control/ETag/Vary come from respond() via the response headers. */
    public static function serve_raw($served, $result, $request)
    {
        if ($served || !$result instanceof WP_HTTP_Response || !self::is_rb_route($request)) return $served;
        $d = $result->get_data();
        if (!is_array($d) || empty($d['_raw'])) return $served;
        status_header($result->get_status());
        header('Content-Type: ' . $d['type'] . '; charset=utf-8');
        echo $d['body'];
        return true;
    }

    /* ---------- Revalidation webhook ---------- */
    public static function on_transition(string $new, string $old, WP_Post $post): void
    {
        if (!self::is_content($post)) return;
        if ($old === 'publish' && $new !== 'publish') self::$unpublished[$post->ID] = true;
        if ($new === 'publish') self::queue($old === 'publish' ? 'update' : 'publish', $post);
        elseif ($old === 'publish') self::queue('unpublish', $post);
    }

    public static function on_before_delete(int $id, $post = null): void
    {
        $post = $post instanceof WP_Post ? $post : get_post($id);
        if ($post && $post->post_status === 'publish' && self::is_content($post)) self::queue('delete', $post);
    }

    /** One event per post per request: a fresh publish stays "publish" through later updates; leaving publish always wins. */
    private static function queue(string $event, WP_Post $post): void
    {
        if (!RB_Settings::get('revalidate_webhook_url')) return;
        $prev = self::$queue[$post->ID]['event'] ?? null;
        if ($prev === 'delete' || ($prev === 'publish' && $event === 'update')) return;
        if ($prev === 'unpublish' && in_array($event, ['publish', 'update'], true)) $event = 'update';

        // Trashing renames the slug to "name__trashed"; the frontend needs the public slug.
        if (str_ends_with($post->post_name, '__trashed')) {
            $post = clone $post;
            $post->post_name = (string) (get_post_meta($post->ID, '_wp_desired_post_slug', true) ?: substr($post->post_name, 0, -9));
        }
        self::$queue[$post->ID] = ['event' => $event, 'type' => $post->post_type, 'slug' => $post->post_name, 'url' => RB_Settings::post_url($post), 'time' => time()];
    }

    public static function send_webhooks(): void
    {
        $url = (string) RB_Settings::get('revalidate_webhook_url');
        if ($url === '' || !self::$queue) return;
        $secret = (string) get_option('rb_secret');
        foreach (self::$queue as $id => $payload) {
            $body = (string) wp_json_encode($payload);
            $res  = wp_remote_post($url, [
                'timeout'  => 3,
                'blocking' => false,
                'headers'  => ['Content-Type' => 'application/json', 'X-RB-Signature' => hash_hmac('sha256', $body, $secret)],
                'body'     => $body,
            ]);
            if (is_wp_error($res)) error_log(sprintf('[react-bridge] webhook %s for %s #%d failed: %s', $payload['event'], $payload['type'], $id, $res->get_error_message()));
        }
        self::$queue = [];
    }
}
