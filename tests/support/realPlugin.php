<?php
/**
 * The REAL CashFlow_Plugin, cut out of woo-cashflow.php and renamed
 * CF_Real_Plugin so it cannot collide with bootstrap.php's recorder. The file
 * itself boots the whole plugin and cannot be required; its class is the last
 * thing in it, so everything from "class CashFlow_Plugin {" to the end is it.
 */
function cf_load_real_plugin(): void {
    if ( class_exists( 'CF_Real_Plugin', false ) ) { return; }
    $src = (string) file_get_contents( __DIR__ . '/../../woo-cashflow.php' );
    $at  = strpos( $src, "\nclass CashFlow_Plugin {" );
    if ( false === $at ) { throw new RuntimeException( 'harness: class CashFlow_Plugin not found in woo-cashflow.php' ); }
    eval( str_replace( "\nclass CashFlow_Plugin {", "\nclass CF_Real_Plugin {", substr( $src, $at ) ) );
}
