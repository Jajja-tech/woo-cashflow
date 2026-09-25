<?php
/**
 * THE CATALOGUE HAS ITS OWN JOB, AND THE ORDER TICK ALWAYS GOES FIRST. [N1, NB9]
 *
 * Action Scheduler claims a batch ordered by priority (lower number first),
 * then by date. The order tick is scheduled at the default 10; the catalogue
 * at 20 in its own group — so in any runner batch the order poll has already
 * run before the catalogue starts. What the harness cannot run is Action
 * Scheduler itself; that ordering is proven by the numbers here and checked
 * live on a store (see "Release checks" at the end of the plan).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-sync-pull.php';
require_once __DIR__ . '/../includes/class-catalog.php';
require_once __DIR__ . '/support/realPlugin.php';

if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = [] ) { return array_merge( $defaults, is_array( $args ) ? $args : [] ); }
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
    function wp_clear_scheduled_hook( $hook ) { CF_TestState::$as_calls[] = [ 'fn' => 'wp_clear', 'hook' => $hook ]; return 0; }
}

function connected(): void {
    CF_TestState::reset();
    CF_TestState::$options['cashflow_catalog_db_version'] = '1';
    CF_TestState::$options['cashflow_connection_secret']  = 'secret-xyz';
    CF_TestState::$catalog_tables['wp_cashflow_catalog_queue'] = true;
}

echo "── the constructor wires the job\n";
connected();
$cat = new CashFlow_Catalog();
ok( 'init → maybe_schedule', ( CF_TestState::$actions['init'][0][0] ?? null ) === [ $cat, 'maybe_schedule' ] );
ok( 'cashflow_catalog_tick → tick', ( CF_TestState::$actions['cashflow_catalog_tick'][0][0] ?? null ) === [ $cat, 'tick' ] );

echo "── its own hook, its own group, a lower priority than the order tick\n";
connected();
( new CashFlow_Sync_Pull() )->maybe_schedule();
( new CashFlow_Catalog() )->maybe_schedule();
$order = CF_TestState::$as_scheduled['cashflow_sync_pull_tick'] ?? null;
$cata  = CF_TestState::$as_scheduled['cashflow_catalog_tick'] ?? null;
ok( 'the catalogue is scheduled', null !== $cata );
ok( 'every 60 seconds, unique', ( end( CF_TestState::$as_calls )['interval'] ?? null ) === 60 && ( end( CF_TestState::$as_calls )['unique'] ?? null ) === true );
ok( 'in its own group, not the order tick\'s', $cata['group'] === 'cashflow-catalog' && $order['group'] === 'cashflow' );
ok( 'the order tick keeps Action Scheduler\'s default priority, 10', $order['priority'] === 10 );
ok( 'the catalogue runs AFTER it: priority 20 (lower number runs first)', $cata['priority'] === 20 && $cata['priority'] > $order['priority'] );

echo "── scheduled once, and re-scheduled if Action Scheduler lost it\n";
$before = count( CF_TestState::$as_calls );
( new CashFlow_Catalog() )->maybe_schedule();
ok( 'a second init schedules nothing', count( CF_TestState::$as_calls ) === $before );
ok( 'is_scheduled() says so', CashFlow_Catalog::is_scheduled() );
as_unschedule_all_actions( 'cashflow_catalog_tick' );       // e.g. the action was marked failed after a fatal
ok( 'is_scheduled() notices it is gone', ! CashFlow_Catalog::is_scheduled() );
( new CashFlow_Catalog() )->maybe_schedule();
ok( 'the next init puts it back', CashFlow_Catalog::is_scheduled() );

echo "── the budget arithmetic\n";
ok( 'every request has a 10-second timeout', CashFlow_Catalog::REQUEST_TIMEOUT === 10 );
ok( 'no request starts with less than 12 seconds left', CashFlow_Catalog::MIN_LEFT_TO_START === 12 );
ok( 'so a request that starts always ends inside the budget', CashFlow_Catalog::MIN_LEFT_TO_START > CashFlow_Catalog::REQUEST_TIMEOUT
    && CashFlow_Catalog::BUDGET_SECONDS > CashFlow_Catalog::MIN_LEFT_TO_START );

echo "── the tick bails out cleanly, and never throws\n";
connected();
unset( CF_TestState::$options['cashflow_connection_secret'] );
( new CashFlow_Catalog() )->tick();
ok( 'not connected: no request, no error', CF_TestState::$api_calls === [] && empty( CashFlow_Catalog::stats()['last_error'] ) );

connected();
CF_TestState::$catalog_tables = [];
CF_TestState::$dbdelta_creates = false;
( new CashFlow_Catalog() )->tick();
$s = CashFlow_Catalog::stats();
ok( 'a missing table that cannot be created is on the panel', ( $s['table_missing'] ?? null ) === true
    && str_contains( (string) ( $s['last_error'] ?? '' ), 'queue table is missing' ) );
ok( 'and nothing was sent', CF_TestState::$api_calls === [] );

connected();
CF_TestState::$catalog_tables = [];
( new CashFlow_Catalog() )->tick();
ok( 'a missing table that CAN be created is created by the job itself', CashFlow_Catalog::table_exists()
    && ( CashFlow_Catalog::stats()['table_missing'] ?? null ) === false );

connected();
CF_TestState::$catalog_tables = [];
CF_TestState::$dbdelta_throws = new RuntimeException( 'Table is read only' );
$threw = false;
try { ( new CashFlow_Catalog() )->tick(); } catch ( Throwable $e ) { $threw = true; }
ok( 'an exception inside the job never leaves tick()', ! $threw );
ok( 'it is on the panel', str_contains( (string) ( CashFlow_Catalog::stats()['last_error'] ?? '' ), 'Table is read only' ) );

echo "── the plugin boots the class, and deactivation unschedules it\n";
$src = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( __DIR__ . '/../woo-cashflow.php' ) );
$src = preg_replace( '#//[^\n]*#', '', $src );
ok( 'class-catalog.php is in the files the plugin loads', str_contains( $src, "'includes/class-catalog.php'," ) );
ok( 'CashFlow_Catalog is in the modules the plugin boots', str_contains( $src, "'CashFlow_Catalog'," ) );
cf_load_real_plugin();
CF_TestState::reset();
CF_Real_Plugin::deactivate();
$un = array_values( array_filter( CF_TestState::$as_calls, function ( $c ) { return 'unschedule_all' === $c['fn']; } ) );
ok( 'deactivate unschedules the order tick (unchanged)', in_array( 'cashflow_sync_pull_tick', array_column( $un, 'hook' ), true ) );
ok( 'and the catalogue job, in its own group', in_array( [ 'fn' => 'unschedule_all', 'hook' => 'cashflow_catalog_tick', 'group' => 'cashflow-catalog' ], $un, true ) );

summary();
