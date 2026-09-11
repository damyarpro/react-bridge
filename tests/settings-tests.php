<?php
/**
 * RB_Settings and GET/PATCH /rb/v1/blog-settings. Runs only through tests/run.php (CLI).
 */
if (!defined('RB_TESTING')) exit;

if (!function_exists('add_settings_error')) require_once ABSPATH . 'wp-admin/includes/template.php';

/* ---------- Local helpers (rbst_ prefix avoids clashes with other test files) ---------- */

function rbst_show($v): string
{
    $s = is_string($v) ? '"' . $v . '"' : (string) wp_json_encode($v);
    return strlen($s) > 120 ? substr($s, 0, 117) . '...' : $s;
}

/** Asserts validate() accepts $value; optional 3rd argument = expected normalised value. */
function rbst_accepts(string $key, $value, ...$expected): void
{
    $want = $expected ? $expected[0] : $value;
    [$clean, $errors] = RB_Settings::validate([$key => $value]);
    rb_assert(!isset($errors[$key]), "$key should accept " . rbst_show($value) . ': ' . ($errors[$key] ?? ''));
    rb_assert(array_key_exists($key, $clean) && $clean[$key] === $want, "$key: " . rbst_show($value) . ' should become ' . rbst_show($want) . ', got ' . rbst_show($clean[$key] ?? null));
}

function rbst_rejects(string $key, $value): void
{
    [$clean, $errors] = RB_Settings::validate([$key => $value]);
    rb_assert(isset($errors[$key]) && is_string($errors[$key]) && $errors[$key] !== '', "$key should reject " . rbst_show($value));
    rb_assert(!array_key_exists($key, $clean), "$key: rejected value " . rbst_show($value) . ' leaked into $clean');
}

function rbst_request(string $method, $body = null): WP_REST_Response
{
    $req = new WP_REST_Request($method, '/' . RB_NS . '/blog-settings');
    if ($body !== null) {
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(is_string($body) ? $body : (string) wp_json_encode($body));
    }
    return rest_do_request($req);
}

function rbst_stored(): array
{
    $s = get_option(RB_Settings::OPTION);
    return is_array($s) ? $s : [];
}

function rbst_legacy_keys(): array
{
    return ['origins', 'cache_ttl', 'per_page', 'redirect_wp', 'revalidate_url'];
}

/* ---------- validate(): accept / reject tables ---------- */

rb_test('validate: frontend_url', function () {
    foreach (['', 'https://example.com', 'http://localhost:5173', 'https://example.com/fa', 'https://sub.example.co:8443/a/b', 'http://[::1]:3000'] as $ok) {
        rbst_accepts('frontend_url', $ok);
    }
    rbst_accepts('frontend_url', '  https://example.com  ', 'https://example.com');
    $bad = ['https://example.com/', 'https://example.com/fa/', 'example.com', '/fa', 'ftp://example.com', 'javascript:alert(1)', 'https://example.com?x=1',
            'https://example.com#top', 'https://user:pass@example.com', 'https://', 'https://road eep.com', 'https://example.com//fa', null, 42, true, ['https://example.com']];
    foreach ($bad as $v) rbst_rejects('frontend_url', $v);
});

rb_test('validate: blog_path', function () {
    // A non-ASCII segment must pass: blog paths are not limited to Latin letters.
    foreach (['/blog', '/en/blog', '/actualités', '/news-2026'] as $ok) rbst_accepts('blog_path', $ok);
    foreach (['/blog/', 'blog', '/', '', '//blog', '/blog//x', '/bl og', '/blog?x=1', '/blog#x', '/blog/..', "/bl\tog", '/a"b', true, null, 5, ['/blog']] as $bad) {
        rbst_rejects('blog_path', $bad);
    }
});

rb_test('validate: redirect_to_frontend is a strict boolean', function () {
    rbst_accepts('redirect_to_frontend', true);
    rbst_accepts('redirect_to_frontend', false);
    foreach (['1', 1, 0, 'true', '', null, []] as $bad) rbst_rejects('redirect_to_frontend', $bad);
});

rb_test('validate: cors_origins', function () {
    rbst_accepts('cors_origins', []);
    rbst_accepts('cors_origins', ['https://a.example.com', 'http://localhost:5173']);
    rbst_accepts('cors_origins', ['HTTPS://A.Example.COM', 'https://a.example.com'], ['https://a.example.com']);
    rbst_accepts('cors_origins', ['https://a.com:443', 'http://b.com:80', 'https://c.com:8443'], ['https://a.com', 'http://b.com', 'https://c.com:8443']);
    rbst_accepts('cors_origins', ['http://[::1]:5173']);

    $fifty = array_map(fn($i) => "https://s$i.example.com", range(1, 50));
    rbst_accepts('cors_origins', $fifty);
    rbst_rejects('cors_origins', array_merge($fifty, ['https://s51.example.com']));

    $bad = [['https://a.com/'], ['https://a.com/path'], ['*'], ['https://*.a.com'], 'https://a.com', ['x' => 'https://a.com'], [123], ['https://a.com?x=1'],
            ['https://a.com#x'], ['https://u@a.com'], ['ftp://a.com'], ['a.com'], [''], ['https://a.com:'], ['https://a.com:0'], null, true];
    foreach ($bad as $v) rbst_rejects('cors_origins', $v);

    [, $errors] = RB_Settings::validate(['cors_origins' => ['https://ok.com', 'https://bad.com/x']]);
    rb_assert(str_contains($errors['cors_origins'] ?? '', 'https://bad.com/x'), 'cors_origins error should name the offending origin');
});

rb_test('validate: cache_ttl_seconds', function () {
    foreach ([0, 1, 300, 86400] as $ok) rbst_accepts('cache_ttl_seconds', $ok);
    foreach ([-1, 86401, '300', 300.5, 300.0, true, null, ''] as $bad) rbst_rejects('cache_ttl_seconds', $bad);
});

rb_test('validate: posts_per_page', function () {
    foreach ([1, 10, 50] as $ok) rbst_accepts('posts_per_page', $ok);
    foreach ([0, 51, -1, '10', 10.0, false, null] as $bad) rbst_rejects('posts_per_page', $bad);
});

rb_test('validate: revalidate_webhook_url', function () {
    foreach (['', 'https://hooks.example.com/rb', 'http://localhost:3000/api/revalidate?token=abc'] as $ok) rbst_accepts('revalidate_webhook_url', $ok);
    foreach (['ftp://x.com', 'not a url', '/relative', 'javascript:alert(1)', 'https://', 42, null, false] as $bad) rbst_rejects('revalidate_webhook_url', $bad);
});

rb_test('validate: unknown keys are ignored, $partial=false requires all 7', function () {
    [$clean, $errors, $ignored] = RB_Settings::validate(['posts_per_page' => 5, 'api_key' => 'x', 'foo' => 1]);
    rb_assert($clean === ['posts_per_page' => 5], 'clean should only contain posts_per_page');
    rb_assert($errors === [], 'no errors expected');
    rb_assert($ignored === ['api_key', 'foo'], 'api_key and foo must be ignored, got ' . rbst_show($ignored));

    [, $errors] = RB_Settings::validate(['posts_per_page' => 5], false);
    $missing = array_values(array_diff(RB_Settings::CONTRACT_KEYS, ['posts_per_page']));
    rb_assert(array_keys($errors) === $missing, 'non-partial validate must flag every missing key, got ' . rbst_show(array_keys($errors)));

    $full = ['frontend_url' => '', 'blog_path' => '/blog', 'redirect_to_frontend' => false, 'cors_origins' => [], 'cache_ttl_seconds' => 0, 'posts_per_page' => 10, 'revalidate_webhook_url' => ''];
    [$clean, $errors] = RB_Settings::validate($full, false);
    rb_assert($errors === [] && $clean === $full, 'a complete valid payload must pass with $partial=false');
});

/* ---------- Typed reads ---------- */

rb_test('all(): typed values, no legacy keys; contract() = the 7 keys', function () {
    $s = RB_Settings::all();
    foreach (['frontend_url', 'blog_path', 'revalidate_webhook_url', 'api_key', 'site_name', 'seo_title_tpl', 'default_desc', 'default_og', 'twitter', 'locale', 'panel_name', 'panel_url'] as $k) {
        rb_assert(is_string($s[$k] ?? null), "$k must be a string");
    }
    foreach (['redirect_to_frontend', 'require_key', 'expose_pages'] as $k) rb_assert(is_bool($s[$k] ?? null), "$k must be a bool");
    foreach (['cache_ttl_seconds', 'posts_per_page'] as $k) rb_assert(is_int($s[$k] ?? null), "$k must be an int");
    rb_assert(is_array($s['cors_origins']) && array_values($s['cors_origins']) === $s['cors_origins'], 'cors_origins must be a list');
    foreach (array_merge(rbst_legacy_keys(), ['_schema']) as $k) rb_assert(!array_key_exists($k, $s), "all() must not expose $k");
    rb_assert(array_keys(RB_Settings::contract()) === RB_Settings::CONTRACT_KEYS, 'contract() keys must equal CONTRACT_KEYS in order');
});

/* ---------- Migration ---------- */

rb_test('migrate(): legacy fixture to schema 2, once', function () {
    update_option(RB_Settings::OPTION, [
        'frontend_url'   => 'https://front.example.com/',
        'blog_path'      => 'news/',
        'origins'        => "https://A.example.com/\nhttp://localhost:5173, *\nhttps://bad.example.com/path\n\nhttps://a.example.com",
        'require_key'    => 1,
        'api_key'        => 'legacykey123',
        'cache_ttl'      => 999999,
        'per_page'       => 0,
        'redirect_wp'    => 1,
        'revalidate_url' => 'javascript:alert(1)',
        'site_name'      => 'Acme Blog',
        'seo_title_tpl'  => '%title% | %site%',
        'default_desc'   => '',
        'default_og'     => '',
        'twitter'        => 'acmeblog',
        'locale'         => 'fa_IR',
        'expose_pages'   => 0,
    ]);
    $gen = RB_Settings::cache_gen();
    RB_Settings::migrate();

    $stored = rbst_stored();
    rb_assert(($stored['_schema'] ?? null) === RB_Settings::SCHEMA, '_schema must be 2 after migration');
    foreach (rbst_legacy_keys() as $k) rb_assert(!array_key_exists($k, $stored), "legacy key $k must be removed");
    $expected = [
        'frontend_url'           => 'https://front.example.com',
        'blog_path'              => '/news',
        'redirect_to_frontend'   => true,
        'cors_origins'           => ['https://a.example.com', 'http://localhost:5173'],
        'cache_ttl_seconds'      => 86400,
        'posts_per_page'         => 1,
        'revalidate_webhook_url' => '',
    ];
    rb_assert(RB_Settings::contract() === $expected, 'migrated contract mismatch: ' . rbst_show(RB_Settings::contract()));
    rb_assert($stored['api_key'] === 'legacykey123' && $stored['require_key'] === true && $stored['expose_pages'] === false, 'non-contract keys must be kept and typed');
    rb_assert(RB_Settings::cache_gen() === $gen + 1, 'migration must bump the cache generation exactly once');

    RB_Settings::migrate();
    rb_assert(rbst_stored() === $stored, 'second migrate() must not change the option');
    rb_assert(RB_Settings::cache_gen() === $gen + 1, 'second migrate() must not bump the generation');
});

rb_test('migrate(): invalid legacy values fall back to safe defaults', function () {
    update_option(RB_Settings::OPTION, [
        'frontend_url' => 'not a url', 'blog_path' => '/', 'origins' => '', 'cache_ttl' => '-5', 'per_page' => '75',
        'redirect_wp' => 0, 'revalidate_url' => 'https://hooks.example.com/rb', 'require_key' => 0, 'expose_pages' => 1, 'api_key' => 'k2',
    ]);
    RB_Settings::migrate();
    $expected = [
        'frontend_url'           => '',
        'blog_path'              => '/blog',
        'redirect_to_frontend'   => false,
        'cors_origins'           => [],
        'cache_ttl_seconds'      => 0,
        'posts_per_page'         => 50,
        'revalidate_webhook_url' => 'https://hooks.example.com/rb',
    ];
    rb_assert(RB_Settings::contract() === $expected, 'migrated contract mismatch: ' . rbst_show(RB_Settings::contract()));
    rb_assert(RB_Settings::get('expose_pages') === true && RB_Settings::get('require_key') === false, 'legacy 0/1 flags must become booleans');
});

rb_test('install(): fresh option gets defaults, api_key and schema 2', function () {
    delete_option(RB_Settings::OPTION);
    RB_Settings::install();
    $s = rbst_stored();
    rb_assert(($s['_schema'] ?? null) === RB_Settings::SCHEMA, 'install() must write _schema 2');
    rb_assert(is_string($s['api_key'] ?? null) && strlen($s['api_key']) === 32, 'install() must generate a 32-char api_key');
    $defaults = RB_Settings::defaults();
    foreach (RB_Settings::CONTRACT_KEYS as $k) rb_assert(RB_Settings::get($k) === $defaults[$k], "$k must equal its default after install()");
    $gen = RB_Settings::cache_gen();
    RB_Settings::migrate();
    rb_assert(RB_Settings::cache_gen() === $gen, 'migrate() after install() must be a no-op');
});

/* ---------- Writes ---------- */

rb_test('update(): merges, types, drops unknown keys, bumps generation', function () {
    $before = RB_Settings::contract();
    $gen    = RB_Settings::cache_gen();
    RB_Settings::update(['posts_per_page' => 3, 'require_key' => true, 'not_a_setting' => 'x']);
    $after = RB_Settings::contract();
    rb_assert($after['posts_per_page'] === 3, 'posts_per_page should be 3');
    rb_assert(RB_Settings::get('require_key') === true, 'require_key should be true');
    unset($before['posts_per_page'], $after['posts_per_page']);
    rb_assert($before === $after, 'other contract keys must be untouched');
    rb_assert(!array_key_exists('not_a_setting', rbst_stored()) && (rbst_stored()['_schema'] ?? null) === RB_Settings::SCHEMA, 'stored option must stay clean');
    rb_assert(RB_Settings::cache_gen() > $gen, 'update() must bump the cache generation');
});

rb_test('bump_cache_gen(): increments by one', function () {
    $g = RB_Settings::cache_gen();
    RB_Settings::bump_cache_gen();
    rb_assert(RB_Settings::cache_gen() === $g + 1, 'first bump');
    RB_Settings::bump_cache_gen();
    rb_assert(RB_Settings::cache_gen() === $g + 2, 'second bump');
});

rb_test('origins() includes the frontend origin; origin_of()', function () {
    RB_Settings::update(['frontend_url' => 'https://front.example.com:8443/fa', 'cors_origins' => ['http://localhost:5173']]);
    $o = RB_Settings::origins();
    rb_assert(in_array('https://front.example.com:8443', $o, true) && in_array('http://localhost:5173', $o, true) && count($o) === 2, 'origins() = ' . rbst_show($o));

    RB_Settings::update(['cors_origins' => ['https://front.example.com:8443']]);
    rb_assert(RB_Settings::origins() === ['https://front.example.com:8443'], 'origins() must be unique');

    RB_Settings::update(['frontend_url' => '', 'cors_origins' => ['https://a.example.com']]);
    rb_assert(RB_Settings::origins() === ['https://a.example.com'], 'empty frontend_url adds nothing');

    $cases = ['https://A.Example.com:8443/x?y#z' => 'https://a.example.com:8443', 'https://a.com:443/' => 'https://a.com', 'http://a.com:80' => 'http://a.com',
              'http://localhost:5173' => 'http://localhost:5173', 'ftp://a.com' => null, '' => null, 'not a url' => null];
    foreach ($cases as $in => $want) rb_assert(RB_Settings::origin_of((string) $in) === $want, "origin_of($in) should be " . rbst_show($want));
});

rb_test('sanitize_form(): form strings, checkbox absence, kept values on error', function () {
    RB_Settings::update(['posts_per_page' => 10, 'blog_path' => '/blog', 'redirect_to_frontend' => true]);
    $GLOBALS['wp_settings_errors'] = [];
    $out = RB_Settings::sanitize_form([
        'frontend_url'           => ' https://front.example.com ',
        'blog_path'              => '/blog/',
        'cors_origins'           => "https://A.example.com\r\n\r\nhttp://localhost:5173\n",
        'cache_ttl_seconds'      => '600',
        'posts_per_page'         => '99',
        'revalidate_webhook_url' => '',
        'require_key'            => '1',
        'api_key'                => 'abcDEF123',
        'site_name'              => 'Acme <b>x</b>',
        'seo_title_tpl'          => '%title% | %site%',
        'default_desc'           => 'desc',
        'default_og'             => '',
        'twitter'                => '@acmeblog',
        'locale'                 => 'fa_IR',
    ]);
    rb_assert(($out['_schema'] ?? null) === RB_Settings::SCHEMA, '_schema must be set');
    rb_assert($out['frontend_url'] === 'https://front.example.com', 'frontend_url trimmed');
    rb_assert($out['blog_path'] === '/blog', 'invalid blog_path keeps the stored value');
    rb_assert($out['cors_origins'] === ['https://a.example.com', 'http://localhost:5173'], 'textarea lines to normalised list, got ' . rbst_show($out['cors_origins']));
    rb_assert($out['cache_ttl_seconds'] === 600, '"600" to 600');
    rb_assert($out['posts_per_page'] === 10, 'invalid posts_per_page keeps the stored value');
    rb_assert($out['redirect_to_frontend'] === false && $out['expose_pages'] === false, 'absent checkboxes to false');
    rb_assert($out['require_key'] === true && $out['api_key'] === 'abcDEF123', 'require_key / api_key');
    rb_assert($out['site_name'] === 'Acme x' && $out['twitter'] === 'acmeblog', 'text fields sanitised');
    foreach (rbst_legacy_keys() as $k) rb_assert(!array_key_exists($k, $out), "sanitize_form must not return $k");

    $codes = array_column(get_settings_errors('rb_settings'), 'code');
    rb_assert(in_array('rb_blog_path', $codes, true) && in_array('rb_posts_per_page', $codes, true), 'field errors must be registered, got ' . rbst_show($codes));
    rb_assert(!in_array('rb_cache_ttl_seconds', $codes, true), 'valid fields must not raise errors');

    // WordPress also runs the callback on update_option() with the typed array: it must be a fixed point.
    $GLOBALS['wp_settings_errors'] = [];
    rb_assert(RB_Settings::sanitize_form($out) === $out, 'sanitize_form(typed) must return the same array');
    rb_assert(get_settings_errors('rb_settings') === [], 'typed input must not raise errors');

    $locale = RB_Settings::get('locale');
    rb_assert(RB_Settings::sanitize_form(['locale' => 'x"><script>'])['locale'] === $locale, 'invalid locale keeps the stored value');
    $GLOBALS['wp_settings_errors'] = [];
});

/* ---------- REST: /rb/v1/blog-settings ---------- */

rb_test('rest: anonymous GET and PATCH to 401', function () {
    rb_as_anon();
    $before = rbst_stored();
    $r = rbst_request('GET');
    rb_assert($r->get_status() === 401, 'anon GET should be 401, got ' . $r->get_status());
    rb_assert(($r->get_data()['code'] ?? '') === 'rest_forbidden', 'anon GET code should be rest_forbidden');
    $r = rbst_request('PATCH', ['posts_per_page' => 5]);
    rb_assert($r->get_status() === 401, 'anon PATCH should be 401, got ' . $r->get_status());
    rb_assert(rbst_stored() === $before, 'anon PATCH must not change settings');
});

rb_test('rest: admin GET to 200 with exactly the 7 keys, no-store', function () {
    rb_as_admin();
    $r = rbst_request('GET');
    rb_assert($r->get_status() === 200, 'admin GET should be 200, got ' . $r->get_status());
    $data = $r->get_data();
    rb_assert(is_array($data) && array_keys($data) === RB_Settings::CONTRACT_KEYS, 'GET must return exactly the 7 contract keys, got ' . rbst_show(is_array($data) ? array_keys($data) : $data));
    rb_assert($data === RB_Settings::contract(), 'GET must equal contract()');
    rb_assert(($r->get_headers()['Cache-Control'] ?? '') === 'no-store', 'GET must send Cache-Control: no-store');
});

rb_test('rest: valid PATCH saves, normalises and bumps generation', function () {
    rb_as_admin();
    $gen = RB_Settings::cache_gen();
    $r   = rbst_request('PATCH', ['posts_per_page' => 12, 'cors_origins' => ['HTTPS://Panel.Example.com'], 'redirect_to_frontend' => true]);
    rb_assert($r->get_status() === 200, 'valid PATCH should be 200, got ' . $r->get_status() . ' ' . rbst_show($r->get_data()));
    $data = $r->get_data();
    rb_assert($data['posts_per_page'] === 12 && $data['cors_origins'] === ['https://panel.example.com'] && $data['redirect_to_frontend'] === true, 'response must reflect saved values');
    rb_assert(array_keys($data) === RB_Settings::CONTRACT_KEYS, 'PATCH response must be the 7 keys');
    rb_assert((rbst_stored()['posts_per_page'] ?? null) === 12, 'value must be persisted');
    rb_assert(RB_Settings::cache_gen() > $gen, 'PATCH must bump the cache generation');
    rb_assert(!isset($r->get_headers()['X-RB-Ignored-Fields']), 'no ignored-fields header when nothing was ignored');
    rb_assert(($r->get_headers()['Cache-Control'] ?? '') === 'no-store', 'PATCH must send Cache-Control: no-store');
});

rb_test('rest: PATCH with one invalid field to 400 and nothing saved', function () {
    rb_as_admin();
    $before = rbst_stored();
    $gen    = RB_Settings::cache_gen();
    $r      = rbst_request('PATCH', ['posts_per_page' => 20, 'cache_ttl_seconds' => -1, 'blog_path' => '/blog/']);
    rb_assert($r->get_status() === 400, 'mixed PATCH should be 400, got ' . $r->get_status());
    $data = $r->get_data();
    rb_assert(($data['code'] ?? '') === 'rb_invalid_settings' && ($data['data']['status'] ?? 0) === 400, 'error code/status mismatch: ' . rbst_show($data));
    $errors = $data['data']['errors'] ?? [];
    rb_assert(isset($errors['cache_ttl_seconds'], $errors['blog_path']) && !isset($errors['posts_per_page']), 'per-field errors mismatch: ' . rbst_show($errors));
    rb_assert(rbst_stored() === $before, 'nothing may be saved when any field is invalid');
    rb_assert(RB_Settings::cache_gen() === $gen, 'a rejected PATCH must not bump the generation');
});

rb_test('rest: unknown keys ignored and reported in X-RB-Ignored-Fields', function () {
    rb_as_admin();
    $key = rbst_stored()['api_key'] ?? null;
    $r   = rbst_request('PATCH', ['posts_per_page' => 7, 'foo_bar' => 1, 'api_key' => 'hijack']);
    rb_assert($r->get_status() === 200, 'PATCH with unknown keys should be 200, got ' . $r->get_status());
    rb_assert(($r->get_headers()['X-RB-Ignored-Fields'] ?? '') === 'foo_bar,api_key', 'ignored header = ' . rbst_show($r->get_headers()['X-RB-Ignored-Fields'] ?? null));
    $stored = rbst_stored();
    rb_assert($stored['posts_per_page'] === 7 && !array_key_exists('foo_bar', $stored), 'known key saved, unknown key dropped');
    rb_assert(($stored['api_key'] ?? null) === $key, 'api_key must not be writable through the contract');
});

rb_test('rest: non-object bodies to 400', function () {
    rb_as_admin();
    $before = rbst_stored();
    foreach (['[1,2]' => 'rb_invalid_body', '"text"' => 'rb_invalid_body', '42' => 'rb_invalid_body', '{' => null] as $body => $code) {
        $r = rbst_request('PATCH', (string) $body);
        rb_assert($r->get_status() === 400, "body $body should be 400, got " . $r->get_status());
        if ($code) rb_assert(($r->get_data()['code'] ?? '') === $code, "body $body code should be $code");
    }
    rb_assert(rbst_stored() === $before, 'invalid bodies must not change settings');
});

rb_test('rest: audit log names keys, never values', function () {
    $id   = rb_as_admin();
    $log  = tempnam(sys_get_temp_dir(), 'rbt');
    $prev = ini_get('error_log');
    ini_set('error_log', $log);
    try {
        $r = rbst_request('PATCH', ['revalidate_webhook_url' => 'https://hooks.example.com/rb?token=s3cr3t-value']);
    } finally {
        ini_set('error_log', (string) $prev);
    }
    $text = (string) file_get_contents($log);
    if (is_file($log)) unlink($log);
    rb_assert($r->get_status() === 200, 'PATCH should be 200');
    rb_assert(str_contains($text, "[react-bridge] blog-settings updated by user #$id: keys=revalidate_webhook_url"), 'audit line missing: ' . rbst_show($text));
    rb_assert(!str_contains($text, 's3cr3t-value'), 'audit log must not contain values');
});

/* ---------- Copy to / paste from an external admin panel ---------- */

rb_test('panel_export(): panel keys, WordPress API url, never the secret', function () {
    RB_Settings::update(['require_key' => false]);
    $e = RB_Settings::panel_export();
    $want = array_merge(RB_Settings::CONTRACT_KEYS, ['wordpress_enabled', 'wordpress_api_url', 'wordpress_api_key']);
    rb_assert(array_keys($e) === $want, 'keys = ' . rbst_show(array_keys($e)));
    rb_assert($e['wordpress_enabled'] === true && $e['wordpress_api_url'] === untrailingslashit(rest_url(RB_NS)), 'wordpress fields = ' . rbst_show($e));
    rb_assert($e['wordpress_api_key'] === '', 'no key when it is not required');
    RB_Settings::update(['require_key' => true]);
    rb_assert(RB_Settings::panel_export()['wordpress_api_key'] === RB_Settings::get('api_key'), 'public key included when required');
    $secret = (string) get_option('rb_secret');
    rb_assert($secret === '' || !str_contains((string) wp_json_encode(RB_Settings::panel_export()), $secret), 'rb_secret must never be exported');
});

rb_test('rest: pasting a panel_export() payload applies it and ignores wordpress_* keys', function () {
    rb_as_admin();
    $payload = RB_Settings::panel_export();
    $payload['posts_per_page'] = 7;
    $payload['blog_path'] = '/blog/articles';
    $r = rbst_request('PATCH', $payload);
    rb_assert($r->get_status() === 200, 'status ' . $r->get_status() . ' ' . rbst_show($r->get_data()));
    rb_assert(RB_Settings::get('posts_per_page') === 7 && RB_Settings::get('blog_path') === '/blog/articles', 'values applied');
    rb_assert(str_contains((string) ($r->get_headers()['X-RB-Ignored-Fields'] ?? ''), 'wordpress_api_url'), 'wordpress_* keys reported as ignored');
});

/* ---------- Invisible bidi / zero-width characters (RTL copy-paste) ---------- */

rb_test('invisible marks: strict validate() rejects them in every text key', function () {
    rbst_rejects('frontend_url', "https://front.example.com\u{2069}");
    rbst_rejects('frontend_url', "\u{200F}https://front.example.com");
    rbst_rejects('blog_path', "/blog\u{200E}");
    rbst_rejects('blog_path', "/bl\u{200B}og");
    rbst_rejects('revalidate_webhook_url', "https://hooks.example.com/rb\u{202C}");
    rbst_rejects('cors_origins', ["https://a.example.com\u{2066}"]);
});

rb_test('invisible marks: sanitize_form() strips them before validating', function () {
    $GLOBALS['wp_settings_errors'] = [];
    $out = RB_Settings::sanitize_form([
        'frontend_url'           => "https://front.example.com\u{2069}",
        'blog_path'              => "\u{200F}/news",
        'cors_origins'           => "\u{2066}https://a.example.com\u{2069}\nhttp://localhost:5173\u{200E}",
        'revalidate_webhook_url' => "https://hooks.example.com/rb\u{FEFF}",
    ]);
    rb_assert($out['frontend_url'] === 'https://front.example.com', 'frontend_url = ' . rbst_show($out['frontend_url']));
    rb_assert($out['blog_path'] === '/news', 'blog_path = ' . rbst_show($out['blog_path']));
    rb_assert($out['cors_origins'] === ['https://a.example.com', 'http://localhost:5173'], 'cors_origins = ' . rbst_show($out['cors_origins']));
    rb_assert($out['revalidate_webhook_url'] === 'https://hooks.example.com/rb', 'webhook = ' . rbst_show($out['revalidate_webhook_url']));
    rb_assert(get_settings_errors('rb_settings') === [], 'stripped values must not raise errors');
    $GLOBALS['wp_settings_errors'] = [];
});

rb_test('invisible marks: already-stored values are repaired on read', function () {
    $stored = rbst_stored();
    $stored['frontend_url'] = "http://front.example.com/fa\u{2069}";
    $stored['cors_origins'] = ["https://a.example.com\u{200F}"];
    update_option(RB_Settings::OPTION, $stored);
    rb_assert(RB_Settings::get('frontend_url') === 'http://front.example.com/fa', 'frontend_url = ' . rbst_show(RB_Settings::get('frontend_url')));
    rb_assert(RB_Settings::get('cors_origins') === ['https://a.example.com'], 'cors_origins = ' . rbst_show(RB_Settings::get('cors_origins')));
    rb_assert(RB_Settings::origin_of((string) RB_Settings::get('frontend_url')) === 'http://front.example.com', 'frontend origin must be usable for CORS');
});

/* ---------- Quick-start setup state (option rb_onboarding_done) ---------- */

/** The harness restores rb_settings and rb_cache_gen only, so these tests own the wizard flag. */
function rbst_onboarding_snapshot(): array
{
    return rb_test_option_state(RB_Settings::ONBOARDING_OPTION);
}

function rbst_onboarding_restore(array $snap): void
{
    $snap['exists']
        ? update_option(RB_Settings::ONBOARDING_OPTION, $snap['value'], true)
        : delete_option(RB_Settings::ONBOARDING_OPTION);
}

rb_test('setup_state: pending while the frontend is missing or is this WordPress', function () {
    $snap = rbst_onboarding_snapshot();
    try {
        RB_Settings::set_onboarding_done(true); // the flag alone must never be enough

        RB_Settings::update(['frontend_url' => '']);
        $st = RB_Settings::setup_state();
        rb_assert($st['reasons'] === ['frontend_missing'], 'empty frontend reasons = ' . rbst_show($st['reasons']));
        rb_assert($st['done'] === false, 'empty frontend must keep the wizard visible');

        RB_Settings::update(['frontend_url' => untrailingslashit(home_url())]);
        $st = RB_Settings::setup_state();
        rb_assert($st['reasons'] === ['frontend_is_wordpress'], 'wp origin reasons = ' . rbst_show($st['reasons']));
        rb_assert($st['done'] === false, 'pointing at WordPress itself is not a finished setup');

        RB_Settings::update(['frontend_url' => 'https://front.example.com/wp-json']);
        $st = RB_Settings::setup_state();
        rb_assert($st['reasons'] === ['frontend_is_wordpress'], '/wp-json reasons = ' . rbst_show($st['reasons']));
        rb_assert($st['done'] === false, 'a /wp-json address is not a frontend');
    } finally {
        rbst_onboarding_restore($snap);
    }
});

rb_test('setup_state: a valid frontend has no reasons and follows the flag', function () {
    $snap = rbst_onboarding_snapshot();
    try {
        RB_Settings::update(['frontend_url' => 'https://front.example.com']);

        RB_Settings::set_onboarding_done(false);
        $st = RB_Settings::setup_state();
        rb_assert($st['reasons'] === [], 'valid frontend reasons = ' . rbst_show($st['reasons']));
        rb_assert($st['done'] === false, 'wizard not finished yet');

        RB_Settings::set_onboarding_done(true);
        $st = RB_Settings::setup_state();
        rb_assert($st === ['done' => true, 'reasons' => []], 'finished state = ' . rbst_show($st));

        // A later change back to WordPress brings the wizard back without clearing the flag.
        RB_Settings::update(['frontend_url' => untrailingslashit(home_url())]);
        rb_assert(RB_Settings::setup_state()['done'] === false, 'a new reason must force done=false');
        rb_assert((bool) get_option(RB_Settings::ONBOARDING_OPTION) === true, 'the stored flag itself stays untouched');
    } finally {
        rbst_onboarding_restore($snap);
    }
});

/* ---------- Optional external admin panel (panel_name / panel_url) ---------- */

rb_test('sanitize_form: panel_url accepts http(s) and keeps the stored value when invalid', function () {
    $GLOBALS['wp_settings_errors'] = [];
    $out = RB_Settings::sanitize_form(['panel_url' => ' https://panel.example.com/admin/blog-settings ']);
    rb_assert($out['panel_url'] === 'https://panel.example.com/admin/blog-settings', 'panel_url = ' . rbst_show($out['panel_url']));
    rb_assert(get_settings_errors('rb_settings') === [], 'a valid panel_url must not raise an error');

    // Write the valid value so the next call has something to fall back to.
    update_option(RB_Settings::OPTION, $out);
    $GLOBALS['wp_settings_errors'] = [];
    foreach (['not a url', 'ftp://panel.example.com', 'javascript:alert(1)', '/admin'] as $bad) {
        $again = RB_Settings::sanitize_form(['panel_url' => $bad]);
        rb_assert($again['panel_url'] === 'https://panel.example.com/admin/blog-settings', rbst_show($bad) . ' must keep the stored panel_url, got ' . rbst_show($again['panel_url']));
    }
    $codes = array_column(get_settings_errors('rb_settings'), 'code');
    rb_assert(in_array('rb_panel_url', $codes, true), 'an invalid panel_url must register a field error, got ' . rbst_show($codes));

    $GLOBALS['wp_settings_errors'] = [];
    rb_assert(RB_Settings::sanitize_form(['panel_url' => ''])['panel_url'] === '', 'an empty panel_url clears the setting');
    rb_assert(get_settings_errors('rb_settings') === [], 'an empty panel_url is valid');
    $GLOBALS['wp_settings_errors'] = [];
});

rb_test('sanitize_form: panel_name is sanitised and truncated to 60 characters', function () {
    $GLOBALS['wp_settings_errors'] = [];
    $out = RB_Settings::sanitize_form(['panel_name' => '  My <b>panel</b>  ']);
    rb_assert($out['panel_name'] === 'My panel', 'panel_name = ' . rbst_show($out['panel_name']));

    $long = str_repeat('a', RB_Settings::MAX_PANEL_NAME + 25);
    $out  = RB_Settings::sanitize_form(['panel_name' => $long]);
    rb_assert(mb_strlen($out['panel_name']) === RB_Settings::MAX_PANEL_NAME, 'panel_name length = ' . mb_strlen($out['panel_name']));

    // Multibyte names are cut by characters, not by bytes.
    $mb  = str_repeat('é', RB_Settings::MAX_PANEL_NAME + 5);
    $out = RB_Settings::sanitize_form(['panel_name' => $mb]);
    rb_assert(mb_strlen($out['panel_name']) === RB_Settings::MAX_PANEL_NAME, 'multibyte panel_name length = ' . mb_strlen($out['panel_name']));

    // Stored junk is repaired on read, with the same bound.
    $stored = rbst_stored();
    $stored['panel_name'] = $long;
    $stored['panel_url']  = 'javascript:alert(1)';
    update_option(RB_Settings::OPTION, $stored);
    rb_assert(mb_strlen((string) RB_Settings::get('panel_name')) === RB_Settings::MAX_PANEL_NAME, 'stored panel_name must be clipped on read');
    rb_assert(RB_Settings::get('panel_url') === '', 'an unusable stored panel_url must fall back to empty');
    $GLOBALS['wp_settings_errors'] = [];
});

rb_test('panel_name and panel_url are not part of the REST contract', function () {
    rb_as_admin();
    $stored = rbst_stored();
    $stored['panel_name'] = 'My panel';
    $stored['panel_url']  = 'https://panel.example.com/admin/blog-settings';
    update_option(RB_Settings::OPTION, $stored);

    rb_assert(!array_key_exists('panel_url', RB_Settings::contract()), 'contract() must stay at the 7 keys');
    $r = rbst_request('PATCH', ['panel_url' => 'https://evil.example.com', 'panel_name' => 'x']);
    rb_assert($r->get_status() === 200, 'unknown keys should be ignored, got ' . $r->get_status());
    rb_assert(RB_Settings::get('panel_url') === 'https://panel.example.com/admin/blog-settings', 'panel_url must not be writable through the contract');
    rb_assert(str_contains((string) ($r->get_headers()['X-RB-Ignored-Fields'] ?? ''), 'panel_url'), 'panel_url must be reported as ignored');
});

/* ---------- Translation ---------- */

rb_test('i18n: RB_Admin::i18n() provides every key the admin script reads', function () {
    $i18n = RB_Admin::i18n();
    $want = [
        'copied', 'copyFailed', 'show', 'hide', 'apiActive', 'apiDown', 'networkError', 'checking',
        'unsavedChanges', 'allSaved', 'saving', 'flushing', 'cacheCleared', 'flushFailed', 'flushCache',
        'generating', 'keyCreated', 'keyFailed', 'confirmRegen', 'confirmDiscard', 'running', 'run',
        'noBody', 'emptyTester', 'mustHttp', 'noSpaces', 'invalidUrl', 'noUserinfo', 'noQueryHash',
        'noTrailingSlash', 'isWordpressApi', 'badPath', 'starNotAllowed', 'onlyHttp', 'noPathInOrigin',
        'badOrigin', 'duplicate', 'tooManyOrigins', 'autoFromFrontend', 'auto', 'originAuto', 'sampleTitle',
        'sampleDesc', 'siteName', 'ofMax', 'changesCount', 'nothingToChange', 'ignored', 'invalidJson',
        'notObject', 'applyFailed', 'connectionFailed', 'applying', 'apply', 'importedCount',
        'clipboardBlocked', 'unsavedNotInCopy', 'pasteHere', 'obOriginAllowed', 'obPathSet', 'obReachable',
        'obUnreachable', 'obApiOk', 'obManifestOk', 'obManifestBad', 'obPosts', 'obSitemapOk', 'obFailed',
        'listSep', 'waiting', 'arrowTo', 'setupDone', 'enterFrontend', 'empty', 'on', 'off',
        'unitBytes', 'unitKb', 'unitMs',
    ];
    $missing = array_values(array_diff($want, array_keys($i18n)));
    rb_assert($missing === [], 'missing i18n keys: ' . rbst_show($missing));

    foreach ($want as $key) {
        rb_assert(is_string($i18n[$key]) && trim($i18n[$key]) !== '', "i18n[$key] must be a non-empty string");
    }
    foreach (['apiActive', 'apiDown', 'originAuto', 'changesCount', 'ignored', 'applyFailed', 'importedCount',
              'obOriginAllowed', 'obPathSet', 'obReachable', 'obPosts'] as $key) {
        rb_assert(str_contains($i18n[$key], '%s'), "i18n[$key] must keep its %s placeholder");
    }
    rb_assert(str_contains($i18n['ofMax'], '%1$s') && str_contains($i18n['ofMax'], '%2$s'), 'ofMax must keep both ordered placeholders');

    $labels = $i18n['labels'] ?? null;
    rb_assert(is_array($labels), 'i18n[labels] must be an array');
    $wantLabels = array_merge(RB_Settings::CONTRACT_KEYS, ['panel_name', 'panel_url']);
    rb_assert(array_values(array_diff($wantLabels, array_keys($labels))) === [], 'missing labels: ' . rbst_show(array_keys($labels)));
    foreach ($labels as $key => $label) rb_assert(is_string($label) && $label !== '', "labels[$key] must be a non-empty string");
});

rb_test('textdomain: react-bridge loads and every message is a translated string', function () {
    $loaded = load_plugin_textdomain('react-bridge', false, dirname(plugin_basename(RB_FILE)) . '/languages');
    rb_assert(is_bool($loaded), 'load_plugin_textdomain must return a boolean, got ' . rbst_show($loaded));

    foreach (['frontend_url', 'blog_path', 'cors_item', 'cache_ttl_seconds', 'panel_url', 'locale', 'required'] as $key) {
        $msg = RB_Settings::msg($key);
        rb_assert(is_string($msg) && trim($msg) !== '' && $msg !== $key, "msg($key) must be a real message, got " . rbst_show($msg));
    }
    rb_assert(str_contains(RB_Settings::msg('cors_item'), '%s'), 'the cors_item message must keep its placeholder');

    // A rejected origin is reported with the offending value substituted into the translated message.
    [, $errors] = RB_Settings::validate(['cors_origins' => ['https://bad.example.com/x']]);
    rb_assert(str_contains($errors['cors_origins'] ?? '', 'https://bad.example.com/x'), 'the translated message must carry the offending origin');
});

rb_test('set_onboarding_done: toggles the autoloaded option', function () {
    $snap = rbst_onboarding_snapshot();
    try {
        RB_Settings::set_onboarding_done(true);
        rb_assert((bool) get_option(RB_Settings::ONBOARDING_OPTION) === true, 'done=true not stored');
        RB_Settings::set_onboarding_done(false);
        rb_assert((bool) get_option(RB_Settings::ONBOARDING_OPTION) === false, 'done=false not stored');
        rb_assert(RB_Settings::setup_state()['done'] === false, 'setup_state follows the cleared flag');
    } finally {
        rbst_onboarding_restore($snap);
    }
});
