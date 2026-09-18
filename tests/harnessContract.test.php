<?php
/**
 * RUN.SH MUST FAIL A TEST FILE THAT CRASHES OR NEVER REPORTS.
 *
 * Both have happened here: a file died on an undefined function and the run
 * still read as clean; another ran 11 assertions, never called summary(), and
 * exited 0. This drives the REAL tests/run.sh against throwaway files and
 * checks its exit code — the guard is proven, not trusted.
 */

require_once __DIR__ . '/bootstrap.php';

$root = realpath( __DIR__ . '/..' );
$tmp  = sys_get_temp_dir() . '/cf-harness-' . getmypid();
@mkdir( $tmp );
$boot = var_export( __DIR__ . '/bootstrap.php', true );

$files = [
    'good'      => "<?php require_once $boot;\nok( 'fine', true );\nsummary();\n",
    'crash'     => "<?php require_once $boot;\nok( 'fine', true );\nundefined_function_here();\nsummary();\n",
    'nosummary' => "<?php require_once $boot; ok( 'fine', true );\n",
    'commented' => "<?php require_once $boot; ok( 'fine', true );\n// summary();\n",
    'failing'   => "<?php require_once $boot;\nok( 'broken', false );\nsummary();\n",
];
$paths = [];
foreach ( $files as $name => $code ) {
    $paths[ $name ] = "$tmp/$name.test.php";
    file_put_contents( $paths[ $name ], $code );
}

function run_sh( string $root, string $file ): int {
    $cmd = 'cd ' . escapeshellarg( $root ) . ' && CF_SKIP_LINT=1 CF_TEST_FILES=' . escapeshellarg( $file )
         . ' bash tests/run.sh > /dev/null 2>&1';
    exec( $cmd, $out, $code );
    return $code;
}

echo "── run.sh is itself a test that can fail\n";
ok( 'a file that passes and reports → exit 0', run_sh( $root, $paths['good'] ) === 0 );
ok( 'a file that CRASHES → non-zero', run_sh( $root, $paths['crash'] ) !== 0 );
ok( 'a file that never calls summary() → non-zero', run_sh( $root, $paths['nosummary'] ) !== 0 );
ok( 'a commented-out summary() does not count → non-zero', run_sh( $root, $paths['commented'] ) !== 0 );
ok( 'a failing assertion → non-zero', run_sh( $root, $paths['failing'] ) !== 0 );

foreach ( $paths as $p ) { @unlink( $p ); }
@rmdir( $tmp );

echo "── every real test file reports\n";
foreach ( glob( __DIR__ . '/*.test.php' ) as $t ) {
    ok( basename( $t ) . ' calls summary()', (bool) preg_match( '/^\s*summary\(\)/m', (string) file_get_contents( $t ) ) );
}

summary();
