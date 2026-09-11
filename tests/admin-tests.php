<?php
/**
 * RB_Admin: frontend reachability probe. Runs only through tests/run.php (CLI).
 *
 * No real HTTP request is made: pre_http_request short-circuits WP_Http, so the tests assert
 * exactly which requests probe_url() would have sent and how it reads the answers.
 */
if (!defined('RB_TESTING')) exit;

/* ---------- Local helpers (rbadm_ prefix avoids clashes with other test files) ---------- */

function rbadm_show($v): string
{
    $s = is_string($v) ? '"' . $v . '"' : (string) wp_json_encode($v);
    return strlen($s) > 120 ? substr($s, 0, 117) . '...' : $s;
}

function rbadm_response(int $code): array
{
    return [
        'headers'  => [],
        'body'     => '',
        'response' => ['code' => $code, 'message' => get_status_header_desc($code)],
        'cookies'  => [],
        'filename' => null,
    ];
}

/**
 * Runs $run with wp_remote_* mocked by $reply(array $args, string $url).
 * Returns [$result, $calls] where $calls records the method, url and args of every request.
 */
function rbadm_with_http(callable $reply, callable $run): array
{
    $calls  = [];
    $filter = static function ($pre, $args, $url) use ($reply, &$calls) {
        $calls[] = ['method' => (string) ($args['method'] ?? ''), 'url' => (string) $url, 'args' => $args];
        return $reply($args, (string) $url);
    };
    add_filter('pre_http_request', $filter, 10, 3);
    try {
        $result = $run();
    } finally {
        remove_filter('pre_http_request', $filter, 10);
    }
    return [$result, $calls];
}

/* ---------- probe_url() ---------- */

rb_test('probe_url: a 200 HEAD is reachable and sends the plugin user agent', function () {
    [$out, $calls] = rbadm_with_http(
        fn() => rbadm_response(200),
        fn() => RB_Admin::probe_url('https://front.example.com')
    );
    rb_assert($out === ['reachable' => true, 'status' => 200, 'error' => null], 'result = ' . rbadm_show($out));
    rb_assert(count($calls) === 1 && $calls[0]['method'] === 'HEAD', 'calls = ' . rbadm_show(array_column($calls, 'method')));
    rb_assert($calls[0]['url'] === 'https://front.example.com', 'url = ' . rbadm_show($calls[0]['url']));
    rb_assert(($calls[0]['args']['user-agent'] ?? '') === 'React Bridge/' . RB_VERSION, 'user agent = ' . rbadm_show($calls[0]['args']['user-agent'] ?? null));
    rb_assert((int) ($calls[0]['args']['timeout'] ?? 0) === RB_Admin::PROBE_TIMEOUT, 'timeout = ' . rbadm_show($calls[0]['args']['timeout'] ?? null));
    rb_assert((int) ($calls[0]['args']['redirection'] ?? 0) === 3, 'redirection = ' . rbadm_show($calls[0]['args']['redirection'] ?? null));
});

rb_test('probe_url: a transport failure reports the error and no status', function () {
    [$out, $calls] = rbadm_with_http(
        fn() => new WP_Error('http_request_failed', 'cURL error 28: Operation timed out'),
        fn() => RB_Admin::probe_url('https://down.example.com')
    );
    rb_assert($out['reachable'] === false, 'must not be reachable: ' . rbadm_show($out));
    rb_assert($out['status'] === null, 'status = ' . rbadm_show($out['status']));
    rb_assert(is_string($out['error']) && $out['error'] !== '', 'error = ' . rbadm_show($out['error']));
    rb_assert(count($calls) === 1, 'a WP_Error must not be retried: ' . rbadm_show(array_column($calls, 'method')));
});

rb_test('probe_url: HEAD refused (405/403) falls back to GET', function () {
    foreach ([405, 403] as $refused) {
        [$out, $calls] = rbadm_with_http(
            fn(array $args) => rbadm_response(($args['method'] ?? '') === 'HEAD' ? $refused : 200),
            fn() => RB_Admin::probe_url('https://picky.example.com')
        );
        rb_assert($out === ['reachable' => true, 'status' => 200, 'error' => null], "after $refused result = " . rbadm_show($out));
        rb_assert(array_column($calls, 'method') === ['HEAD', 'GET'], "after $refused calls = " . rbadm_show(array_column($calls, 'method')));
        rb_assert(isset($calls[1]['args']['limit_response_size']), 'the GET fallback must not download the whole page');
    }
});

rb_test('probe_url: other error statuses are reported, not retried', function () {
    [$out, $calls] = rbadm_with_http(
        fn() => rbadm_response(404),
        fn() => RB_Admin::probe_url('https://front.example.com')
    );
    rb_assert($out === ['reachable' => false, 'status' => 404, 'error' => null], 'result = ' . rbadm_show($out));
    rb_assert(count($calls) === 1, 'calls = ' . rbadm_show(array_column($calls, 'method')));
});

rb_test('probe_url: a 3xx within the redirect budget is reachable', function () {
    [$out] = rbadm_with_http(
        fn() => rbadm_response(301),
        fn() => RB_Admin::probe_url('https://front.example.com')
    );
    rb_assert($out['reachable'] === true && $out['status'] === 301, 'result = ' . rbadm_show($out));
});

rb_test('probe_url: non http(s) schemes are refused without any request', function () {
    foreach (['ftp://front.example.com', 'javascript:alert(1)', 'front.example.com', ''] as $bad) {
        [$out, $calls] = rbadm_with_http(
            fn() => rbadm_response(200),
            fn() => RB_Admin::probe_url($bad)
        );
        rb_assert($out['reachable'] === false && $out['status'] === null, rbadm_show($bad) . ' result = ' . rbadm_show($out));
        rb_assert(is_string($out['error']) && $out['error'] !== '', rbadm_show($bad) . ' must explain why');
        rb_assert($calls === [], rbadm_show($bad) . ' must not be requested');
    }
});
