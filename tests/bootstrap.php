<?php
/**
 * CLI test bootstrap for React Bridge: loads WordPress, provides the assertion helpers and
 * snapshots the plugin options so a run leaves the site as it found it.
 *
 * Must be required at file scope (tests/run.php does): WordPress relies on global variables.
 * Usage: php tests/run.php [path-to-wordpress-root]   (env RB_TEST_HOST, default localhost)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('RB_TESTING', true);

final class RB_Test_Failure extends RuntimeException {}

$GLOBALS['rb_tests']         = [];
$GLOBALS['rb_test_snapshot'] = [];

// Registered before WordPress loads so it runs ahead of any core shutdown handler that may exit.
register_shutdown_function('rb_test_restore');

$rb_wp_root = rtrim((string) ($_SERVER['argv'][1] ?? dirname(__DIR__, 4)), '/\\');
if (!is_file($rb_wp_root . '/wp-load.php')) {
    fwrite(STDERR, "wp-load.php not found in {$rb_wp_root}. Pass the WordPress root as the first argument.\n");
    exit(2);
}

// Only a placeholder for $_SERVER: WordPress builds home_url()/rest_url() from the stored options,
// so this matters solely for sites that derive the URL from the request. Override with RB_TEST_HOST.
$rb_host = getenv('RB_TEST_HOST') ?: 'localhost';
$_SERVER['HTTP_HOST']      = $rb_host;
$_SERVER['SERVER_NAME']    = $rb_host;
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PORT']    = $_SERVER['SERVER_PORT'] ?? '80';
$_SERVER['REMOTE_ADDR']    = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

define('WP_USE_THEMES', false);
if (!defined('WP_DISABLE_FATAL_ERROR_HANDLER')) define('WP_DISABLE_FATAL_ERROR_HANDLER', true);

require $rb_wp_root . '/wp-load.php';

if (!class_exists('RB_Settings') || !class_exists('RB_Settings_Rest')) {
    fwrite(STDERR, "React Bridge 1.1+ is not active on this site (RB_Settings / RB_Settings_Rest missing).\n");
    exit(2);
}

foreach (['rb_settings', 'rb_cache_gen'] as $rb_option) {
    $GLOBALS['rb_test_snapshot'][$rb_option] = rb_test_option_state($rb_option);
}

/* ---------- Helpers used by every tests/*-tests.php file ---------- */

function rb_test(string $name, callable $fn): void
{
    $GLOBALS['rb_tests'][] = ['name' => $name, 'fn' => $fn];
}

function rb_assert(bool $cond, string $msg): void
{
    if (!$cond) throw new RB_Test_Failure($msg);
}

function rb_as_admin(): int
{
    $ids = get_users(['role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1, 'fields' => 'ID']);
    if (!$ids) throw new RB_Test_Failure('No administrator account found on this site.');
    $id = (int) $ids[0];
    wp_set_current_user($id);
    rb_assert(current_user_can('manage_options'), "Administrator #$id cannot manage_options.");
    return $id;
}

function rb_as_anon(): void
{
    wp_set_current_user(0);
}

/* ---------- Snapshot / restore ---------- */

function rb_test_option_state(string $name): array
{
    $missing = new stdClass();
    $value   = get_option($name, $missing);
    return $value === $missing ? ['exists' => false, 'value' => null] : ['exists' => true, 'value' => $value];
}

/** Called by run.php before each test: anonymous user and the original rb_settings. */
function rb_test_reset(): void
{
    rb_as_anon();
    $snap = $GLOBALS['rb_test_snapshot']['rb_settings'] ?? null;
    if ($snap === null || rb_test_option_state('rb_settings') === $snap) return;
    $snap['exists'] ? update_option('rb_settings', $snap['value']) : delete_option('rb_settings');
    // Responses cached under the previous test's settings must not leak into the next test.
    RB_Settings::bump_cache_gen();
}

/**
 * Always runs (normal exit, exit(1), fatal error). rb_settings is restored exactly.
 * rb_cache_gen is a monotonic invalidation counter: if the run changed it, it is moved past
 * every value used during the run instead of being rewound, because rewinding would make
 * transients cached with test settings reachable again on the next real bump.
 */
function rb_test_restore(): void
{
    static $done = false;
    if ($done || empty($GLOBALS['rb_test_snapshot']) || !function_exists('update_option')) return;
    $done = true;
    try {
        $s = $GLOBALS['rb_test_snapshot']['rb_settings'];
        $s['exists'] ? update_option('rb_settings', $s['value']) : delete_option('rb_settings');

        $g      = $GLOBALS['rb_test_snapshot']['rb_cache_gen'];
        $before = $g['exists'] ? (int) $g['value'] : 0;
        $now    = (int) get_option('rb_cache_gen', 0);
        if ($now !== $before) update_option('rb_cache_gen', max($now, $before) + 1, true);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Could not restore React Bridge options: ' . $e->getMessage() . PHP_EOL);
    }
}
