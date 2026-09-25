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

// A controllable clock for the lease/attempt assertions below — independent of
// the one cf_queue_scenarios() used and cleared on its own way out.
$clock = 1790500000.0;
CashFlow_Catalog::$clock = function () use ( &$clock ) { return $clock; };

// Each block below clears the stats option too — 'last_error' is a bounded
// panel value that PERSISTS across calls (that is the point of note_error's
// own dedupe), so a check against it is meaningless unless it starts empty:
// otherwise it can read as "recorded" purely because an EARLIER block left a
// matching message behind, whether or not THIS call ever recorded anything.

echo "── a claim whose read-back fails answers null too, never what the update claimed\n";
CF_TestState::$catalog_queue = [];
CF_TestState::$options = [];
CashFlow_Catalog::enqueue( 6, 'save' );
CF_TestState::$db_error_on = 'SELECT id, product_id, reason, attempts FROM';   // select_claimed only
ok( 'the update claimed a row but the read-back failed: still null', CashFlow_Catalog::claim( 'y', 25, false ) === null );
ok( 'and it is recorded', str_contains( (string) ( CashFlow_Catalog::stats()['last_error'] ?? '' ), 'queue could not be read' ) );
CF_TestState::$db_error_on = null;

echo "── enqueue reports a write failure too, instead of a bare false\n";
CF_TestState::$catalog_queue = [];
CF_TestState::$options = [];
CF_TestState::$db_error_on = 'INSERT IGNORE INTO wp_cashflow_catalog_queue (product_id, reason, attempts, queued_at, pending_key) VALUES';
ok( 'a failed insert still answers false', CashFlow_Catalog::enqueue( 40, 'save' ) === false );
ok( 'and it is recorded', str_contains( (string) ( CashFlow_Catalog::stats()['last_error'] ?? '' ), 'queue could not be' ) );
CF_TestState::$db_error_on = null;

echo "── fail_row reports a database failure as 'error', never a false 'released'\n";
CF_TestState::$catalog_queue = [];
CF_TestState::$options = [];
CashFlow_Catalog::enqueue( 41, 'save' );
$r = CashFlow_Catalog::claim( 'fe', 25, false );
CF_TestState::$db_error_on = 'attempts = attempts + 1';   // release_failed only
ok( 'a failed release-failed statement reports error, never released', CashFlow_Catalog::fail_row( $r[0], 'fe' ) === 'error' );
ok( 'and it is recorded', str_contains( (string) ( CashFlow_Catalog::stats()['last_error'] ?? '' ), 'queue could not be' ) );
CF_TestState::$db_error_on = null;

echo "── fail_row parks only if the park write actually landed\n";
CF_TestState::$catalog_queue = [];
CF_TestState::$options = [];
CashFlow_Catalog::enqueue( 44, 'save' );
for ( $try = 1; $try <= 4; $try++ ) {
    $r = CashFlow_Catalog::claim( "pk$try", 25, false );
    CashFlow_Catalog::fail_row( $r[0], "pk$try" );
    $clock += 61;
}
$r = CashFlow_Catalog::claim( 'pk5', 25, false );
ok( 'carries 4 earlier failures', (int) $r[0]['attempts'] === 4 );
CF_TestState::$db_error_on = 'parked_at = %s WHERE id';   // park only
ok( 'a failed park reports error, never parked', CashFlow_Catalog::fail_row( $r[0], 'pk5' ) === 'error' );
ok( 'and it is recorded', str_contains( (string) ( CashFlow_Catalog::stats()['last_error'] ?? '' ), 'queue could not be' ) );
CF_TestState::$db_error_on = null;
ok( 'the row is not actually parked', CashFlow_Catalog::count_parked() === 0 );

echo "── release_row does not ignore a database failure, and never loses the row\n";
CF_TestState::$catalog_queue = [];
CF_TestState::$options = [];
CashFlow_Catalog::enqueue( 42, 'save' );
$r = CashFlow_Catalog::claim( 're', 25, false );
CF_TestState::$db_error_on = 'claimed_at = NULL, pending_key = CONCAT';   // release_untried only
CashFlow_Catalog::release_row( $r[0], 're' );
ok( 'a failed release-untried statement is recorded, not silently ignored',
    str_contains( (string) ( CashFlow_Catalog::stats()['last_error'] ?? '' ), 'queue could not be' ) );
ok( 'and it is still leased, not claimable yet', CashFlow_Catalog::claim( 're2', 25, false ) === [] );
CF_TestState::$db_error_on = null;
$clock += 301;
$back = CashFlow_Catalog::claim( 're3', 25, false );
ok( 'after the lease, the row a failed release could not free is still claimable',
    count( (array) $back ) === 1 && 42 === (int) $back[0]['product_id'] );

echo "── done_row reports a delete failure, and never pretends the row is gone\n";
CF_TestState::$catalog_queue = [];
CF_TestState::$options = [];
CashFlow_Catalog::enqueue( 43, 'save' );
$r = CashFlow_Catalog::claim( 'de', 25, false );
CF_TestState::$db_error_on = 'DELETE FROM wp_cashflow_catalog_queue WHERE id =';   // done only
CashFlow_Catalog::done_row( $r[0], 'de' );
ok( 'a failed delete is recorded, not silently swallowed',
    str_contains( (string) ( CashFlow_Catalog::stats()['last_error'] ?? '' ), 'queue could not be' ) );
CF_TestState::$db_error_on = null;
ok( 'and the row was never actually deleted', count( CF_TestState::$catalog_queue ) === 1 );

echo "── a repeated identical failure updates the panel every time, the sync log only once\n";
CF_TestState::$catalog_queue = [];
CF_TestState::$options = [];
CF_TestState::$log = [];
CashFlow_Catalog::enqueue( 45, 'save' );
CF_TestState::$db_error_on = 'attempts + IF(token IS NULL, 0, 1)';
CashFlow_Catalog::claim( 'lg1', 25, false );
CashFlow_Catalog::claim( 'lg2', 25, false );
CashFlow_Catalog::claim( 'lg3', 25, false );
CF_TestState::$db_error_on = null;
$catalog_logs = array_values( array_filter( CF_TestState::$log, function ( $l ) { return 'catalog' === $l['event_type']; } ) );
ok( 'the identical failure is logged exactly once, not on every call', count( $catalog_logs ) === 1, (string) count( $catalog_logs ) );

CashFlow_Catalog::$clock = null;

summary();
