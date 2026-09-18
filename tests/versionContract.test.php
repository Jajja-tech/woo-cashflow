<?php
/**
 * THE PLUGIN DECLARES ITS VERSION TWICE, AND THE TWO MUST AGREE.
 *
 * The WordPress header (`Version:`) is what the updater compares against
 * GitHub; CASHFLOW_VERSION is what every poll reports to CashFlow. On
 * 2026-08-07 they disagreed on `main` (header 6.4.0, constant 6.3.0), so every
 * poll reported a version the store was not running. Read from the real file.
 */

require_once __DIR__ . '/bootstrap.php';

$src = (string) file_get_contents( __DIR__ . '/../woo-cashflow.php' );
preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $src, $h );
preg_match( "/define\(\s*'CASHFLOW_VERSION',\s*'([^']+)'/", $src, $c );

echo "── one version, stated twice\n";
ok( 'the header Version is readable', ! empty( $h[1] ), 'no Version: line found' );
ok( 'CASHFLOW_VERSION is readable', ! empty( $c[1] ), 'no CASHFLOW_VERSION define found' );
ok( 'header Version equals CASHFLOW_VERSION', ( $h[1] ?? 'a' ) === ( $c[1] ?? 'b' ),
    'header ' . ( $h[1] ?? '?' ) . ' vs constant ' . ( $c[1] ?? '?' ) );
ok( 'the harness reports the same version the plugin does', CASHFLOW_VERSION === ( $c[1] ?? '' ),
    'harness ' . CASHFLOW_VERSION );
ok( 'this release is 6.6.0 (the one that runs order.create@1)', ( $c[1] ?? '' ) === '6.6.0' );

summary();
