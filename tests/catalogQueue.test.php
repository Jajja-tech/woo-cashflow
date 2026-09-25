<?php
/**
 * THE QUEUE, ON THE MODEL. The same scenarios run on a real MySQL in
 * catalogQueueMysql.test.php; this file adds what only the model can show.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-catalog.php';
require_once __DIR__ . '/support/catalogQueueScenarios.php';

CF_TestState::reset();
CF_TestState::$catalog_tables['wp_cashflow_catalog_queue'] = true;

cf_queue_scenarios( [
    'reset' => function ( bool $posts_too = true ) {
        CF_TestState::$catalog_queue = [];
        CF_TestState::$catalog_queue_next = 1;
        if ( $posts_too ) { CF_TestState::$posts = []; }
    },
    'seed_posts' => function ( array $posts ) {
        foreach ( $posts as [ $id, $type, $status ] ) { CF_TestState::$posts[ $id ] = [ 'type' => $type, 'status' => $status, 'parent' => 0 ]; }
    },
    'rows' => function () { return array_values( CF_TestState::$catalog_queue ); },
] );

echo "── the model covers every statement, and nothing else\n";
$modelled = array_keys( CF_Test_CatalogDB::METHODS );
sort( $modelled );
$built = CashFlow_Catalog::SQL_NAMES;
sort( $built );
ok( 'every statement sql() builds is modelled', $modelled === $built, implode( ',', array_diff( $built, $modelled ) ) );
$threw = false;
try { $GLOBALS['wpdb']->query( 'DELETE FROM wp_cashflow_catalog_queue' ); } catch ( RuntimeException $e ) { $threw = true; }
ok( 'a statement the plugin does not build is refused', $threw );

echo "── a database error is reported, never read as an empty queue or an empty page\n";
CF_TestState::$catalog_queue = [];
CashFlow_Catalog::enqueue( 5, 'save' );
CF_TestState::$db_error_on = 'attempts + IF(token IS NULL, 0, 1)';   // in both claim statements, whatever the SET order
ok( 'a failed claim answers null, not []', CashFlow_Catalog::claim( 'x', 25, false ) === null );
ok( 'and says why on the panel', str_contains( (string) ( CashFlow_Catalog::stats()['last_error'] ?? '' ), 'queue could not be read' ) );
ok( 'and in the sync log', ( end( CF_TestState::$log )['event_type'] ?? '' ) === 'catalog' );
CF_TestState::$db_error_on = 'SELECT ID FROM wp_posts';
ok( 'a failed enumeration answers null, not []', CashFlow_Catalog::enumerate( 0, 100 ) === null );
CF_TestState::$db_error_on = null;

summary();
