<?php
/**
 * React Bridge test runner (CLI only).
 * Usage: php tests/run.php [path-to-wordpress-root]
 * Exit code: 0 when every test passes, 1 on any failure (or when no tests are found).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php'; // file scope on purpose: WordPress needs global scope

$rb_files = glob(__DIR__ . '/*-tests.php') ?: [];
sort($rb_files);
foreach ($rb_files as $rb_file) require $rb_file;

$rb_pass = 0;
$rb_fail = 0;
foreach ($GLOBALS['rb_tests'] as $rb_t) {
    rb_test_reset();
    try {
        ($rb_t['fn'])();
        $rb_pass++;
        echo 'PASS ', $rb_t['name'], PHP_EOL;
    } catch (Throwable $e) {
        $rb_fail++;
        $rb_where = $e instanceof RB_Test_Failure ? '' : sprintf(' [%s at %s:%d]', get_class($e), basename($e->getFile()), $e->getLine());
        echo 'FAIL ', $rb_t['name'], ' - ', $e->getMessage(), $rb_where, PHP_EOL;
    }
}
rb_test_reset();

$rb_total = $rb_pass + $rb_fail;
if ($rb_total === 0) {
    echo 'FAIL no tests found in ', __DIR__, PHP_EOL;
    exit(1);
}
printf("\n%d passed, %d failed, %d total\n", $rb_pass, $rb_fail, $rb_total);
exit($rb_fail > 0 ? 1 : 0);
