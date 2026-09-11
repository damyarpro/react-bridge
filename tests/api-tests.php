<?php
/**
 * rb/v1 API tests. Loaded by tests/run.php after tests/bootstrap.php (CLI only).
 * Settings are flipped with RB_Settings::update(); bootstrap restores rb_settings + rb_cache_gen on shutdown.
 */
if (PHP_SAPI !== 'cli' || !function_exists('rb_test')) exit;

if (!function_exists('rb_api_t_get')) {
    /** GET an rb/v1 route in-process. Always sends the API key so tests pass whether or not require_key is on. */
    function rb_api_t_get(string $route, array $query = [], array $headers = []): WP_REST_Response
    {
        $req = new WP_REST_Request('GET', '/' . RB_NS . $route);
        $req->set_query_params($query);
        $req->set_header('X-RB-Key', (string) RB_Settings::get('api_key'));
        foreach ($headers as $k => $v) $req->set_header($k, $v);
        return rest_ensure_response(rest_do_request($req));
    }

    function rb_api_t_header(WP_REST_Response $res, string $name): ?string
    {
        foreach ($res->get_headers() as $k => $v) if (strcasecmp($k, $name) === 0) return (string) $v;
        return null;
    }

    /** WP_Query prepends sticky posts on page 1 (unchanged legacy behaviour), so counts are bounded by per_page + sticky. */
    function rb_api_t_sticky(): int
    {
        return count(array_filter((array) get_option('sticky_posts', [])));
    }
}

rb_test('api: /posts per_page=-1 is clamped to 1..50', function () {
    rb_as_anon();
    RB_Settings::update(['cache_ttl_seconds' => 0]);
    $res = rb_api_t_get('/posts', ['per_page' => -1]);
    rb_assert($res->get_status() === 200, 'expected 200, got ' . $res->get_status());
    $n = count($res->get_data()['items']);
    rb_assert($n <= 50, "expected <= 50 items, got $n");
    rb_assert($n <= 1 + rb_api_t_sticky(), "per_page=-1 must clamp to 1 (+ sticky), got $n");
});

rb_test('api: /posts per_page=500 is capped at 50', function () {
    rb_as_anon();
    RB_Settings::update(['cache_ttl_seconds' => 0]);
    $big    = rb_api_t_get('/posts', ['per_page' => 500]);
    $capped = rb_api_t_get('/posts', ['per_page' => 50]);
    rb_assert($big->get_status() === 200, 'expected 200, got ' . $big->get_status());
    $ids = fn(WP_REST_Response $r) => array_column($r->get_data()['items'], 'id');
    rb_assert($ids($big) === $ids($capped), 'per_page=500 must return the same items as per_page=50');
    rb_assert(count($ids($big)) <= 50 + rb_api_t_sticky(), 'expected <= 50 items (+ sticky), got ' . count($ids($big)));
});

rb_test('api: /posts per_page=0 falls back to posts_per_page', function () {
    rb_as_anon();
    RB_Settings::update(['cache_ttl_seconds' => 0, 'posts_per_page' => 2]);
    rb_assert(RB_Settings::get('posts_per_page') === 2, 'posts_per_page should be int 2 after update()');
    $res = rb_api_t_get('/posts', ['per_page' => 0]);
    rb_assert($res->get_status() === 200, 'expected 200, got ' . $res->get_status());
    $data = $res->get_data();
    $two  = rb_api_t_get('/posts', ['per_page' => 2])->get_data();
    rb_assert(array_column($data['items'], 'id') === array_column($two['items'], 'id'), 'per_page=0 must equal per_page=posts_per_page (2)');
    rb_assert($data['total_pages'] === $two['total_pages'], 'total_pages must match the default page size');
    rb_assert(array_keys($data) === ['items', 'total', 'total_pages', 'page'], 'response shape changed: ' . implode(',', array_keys($data)));
    rb_assert((string) $data['total'] === rb_api_t_header($res, 'X-RB-Total'), 'X-RB-Total header must match total');
});

rb_test('api: /posts page < 1 is treated as page 1', function () {
    rb_as_anon();
    RB_Settings::update(['cache_ttl_seconds' => 0]);
    $res = rb_api_t_get('/posts', ['page' => -5]);
    rb_assert($res->get_status() === 200, 'expected 200, got ' . $res->get_status());
    rb_assert($res->get_data()['page'] === 1, 'expected page 1');
});

rb_test('api: unknown slug is 404 twice and never cached', function () {
    rb_as_anon();
    RB_Settings::update(['cache_ttl_seconds' => 300]);
    $slug = 'rb-test-missing-' . strtolower(wp_generate_password(10, false, false));
    foreach ([1, 2] as $i) {
        $res = rb_api_t_get('/posts/' . $slug);
        rb_assert($res->get_status() === 404, "request #$i: expected 404, got " . $res->get_status());
    }
    rb_assert(get_transient(RB_Api::cache_key("post:$slug")) === false, 'a not-found result must not create a transient');
});

rb_test('api: /posts/{slug} resolves encoded and decoded non-ASCII slugs', function () {
    rb_as_anon();
    RB_Settings::update(['cache_ttl_seconds' => 0]);
    $ids = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 20, 'fields' => 'ids']);
    $post = null;
    foreach ($ids as $id) {
        $p = get_post($id);
        if (preg_match('/%[0-9a-f]{2}/i', $p->post_name)) { $post = $p; break; }
    }
    if (!$post) return; // no published post with a non-ASCII slug on this site
    $decoded = urldecode($post->post_name);
    foreach (['raw lower-hex' => $post->post_name, 'upper-hex' => rawurlencode($decoded), 'decoded' => $decoded] as $label => $slug) {
        $res = rb_api_t_get('/posts/' . $slug);
        rb_assert($res->get_status() === 200, "$label slug: expected 200, got " . $res->get_status());
        rb_assert(($res->get_data()['id'] ?? 0) === $post->ID, "$label slug: wrong post");
    }
});

rb_test('api: existing resource is cached under the current generation', function () {
    rb_as_anon();
    RB_Settings::update(['cache_ttl_seconds' => 300]);
    $res = rb_api_t_get('/taxonomies');
    rb_assert($res->get_status() === 200, 'expected 200, got ' . $res->get_status());
    rb_assert(get_transient(RB_Api::cache_key('tax')) !== false, 'taxonomies should be cached when ttl > 0');
    $before = RB_Api::cache_key('tax');
    RB_Api::flush_cache();
    rb_assert(RB_Api::cache_key('tax') !== $before, 'flush_cache() must change the cache key (generation bump)');
});

rb_test('api: matching If-None-Match returns 304 with null body', function () {
    rb_as_anon();
    RB_Settings::update(['cache_ttl_seconds' => 300]);
    $first = rb_api_t_get('/taxonomies');
    $etag  = rb_api_t_header($first, 'ETag');
    rb_assert($first->get_status() === 200 && $etag !== null && $etag !== '', 'first response must be 200 with an ETag');

    foreach ([$etag, 'W/' . $etag, '"nope", ' . $etag, '*'] as $inm) {
        $res = rb_api_t_get('/taxonomies', [], ['If-None-Match' => $inm]);
        rb_assert($res->get_status() === 304, "If-None-Match [$inm]: expected 304, got " . $res->get_status());
        rb_assert($res->get_data() === null, "If-None-Match [$inm]: 304 must have null data");
        rb_assert(rb_api_t_header($res, 'ETag') === $etag, "If-None-Match [$inm]: 304 must repeat the ETag");
    }
    $miss = rb_api_t_get('/taxonomies', [], ['If-None-Match' => '"something-else"']);
    rb_assert($miss->get_status() === 200 && $miss->get_data() !== null, 'non-matching ETag must return 200 with data');
});

rb_test('api: etag_matches parsing', function () {
    $e = '"abc"';
    rb_assert(RB_Api::etag_matches($e, '"abc"'), 'exact');
    rb_assert(RB_Api::etag_matches($e, 'W/"abc"'), 'weak prefix');
    rb_assert(RB_Api::etag_matches($e, '"x" , W/"abc"'), 'comma list');
    rb_assert(RB_Api::etag_matches($e, '*'), 'wildcard');
    rb_assert(!RB_Api::etag_matches($e, '"abcd"'), 'different tag');
    rb_assert(!RB_Api::etag_matches($e, ''), 'empty header');
    rb_assert(!RB_Api::etag_matches($e, 'abc'), 'unquoted tag must not match');
});

rb_test('api: Cache-Control is no-store when ttl = 0', function () {
    rb_as_anon();
    RB_Settings::update(['cache_ttl_seconds' => 0]);
    $res = rb_api_t_get('/taxonomies');
    rb_assert(rb_api_t_header($res, 'Cache-Control') === 'no-store', 'got ' . var_export(rb_api_t_header($res, 'Cache-Control'), true));
    rb_assert(get_transient(RB_Api::cache_key('tax')) === false, 'ttl 0 must bypass the server cache');
});

rb_test('api: Cache-Control is public when ttl > 0 and key not required', function () {
    rb_as_anon();
    RB_Settings::update(['cache_ttl_seconds' => 120, 'require_key' => false]);
    rb_assert(RB_Settings::get('require_key') === false, 'require_key should be bool false after update()');
    $res = rb_api_t_get('/taxonomies');
    rb_assert(rb_api_t_header($res, 'Cache-Control') === 'public, max-age=120', 'got ' . var_export(rb_api_t_header($res, 'Cache-Control'), true));
    $vary = (string) rb_api_t_header($res, 'Vary');
    rb_assert(stripos($vary, 'Origin') !== false && stripos($vary, 'X-RB-Key') !== false, "Vary must include Origin and X-RB-Key, got [$vary]");
});

rb_test('api: Cache-Control is private when require_key is on', function () {
    rb_as_anon();
    RB_Settings::update(['cache_ttl_seconds' => 120, 'require_key' => true]);
    rb_assert(RB_Settings::get('require_key') === true, 'require_key should be bool true after update()');
    $res = rb_api_t_get('/taxonomies');
    rb_assert($res->get_status() === 200, 'expected 200 with a valid key, got ' . $res->get_status());
    rb_assert(rb_api_t_header($res, 'Cache-Control') === 'private, max-age=120', 'got ' . var_export(rb_api_t_header($res, 'Cache-Control'), true));

    $req = new WP_REST_Request('GET', '/' . RB_NS . '/taxonomies');
    $denied = rest_ensure_response(rest_do_request($req));
    rb_assert($denied->get_status() === 401, 'missing key must be 401, got ' . $denied->get_status());
});

rb_test('api: reading_time counts Unicode words', function () {
    rb_assert(RB_Api::reading_time('') === 1, 'empty content is at least 1 minute');
    rb_assert(RB_Api::reading_time(str_repeat('سلام ', 200)) === 1, '200 Persian words = 1 minute');
    rb_assert(RB_Api::reading_time(str_repeat('سلام ', 401)) === 3, '401 Persian words = 3 minutes');
    rb_assert(RB_Api::reading_time('<p>' . str_repeat('کلمه&nbsp;', 10) . '</p>') >= 1, 'markup is stripped');
});

rb_test('api: sitemap is a single urlset', function () {
    rb_as_anon();
    RB_Settings::update(['cache_ttl_seconds' => 0]);
    $res = rb_api_t_get('/sitemap.xml');
    rb_assert($res->get_status() === 200, 'expected 200, got ' . $res->get_status());
    $d = $res->get_data();
    rb_assert(is_array($d) && !empty($d['_raw']) && $d['type'] === 'application/xml', 'sitemap must be a raw XML response');
    rb_assert(substr_count($d['body'], '<urlset') === 1, 'exactly one urlset');
    rb_assert(substr_count($d['body'], '<url>') <= RB_Api::SITEMAP_MAX, 'capped at 50000 URLs');
});
