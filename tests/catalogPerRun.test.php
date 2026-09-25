<?php
/**
 * [I-2] "PER RUN" MEANS PER RUN — NOT PER PHP PROCESS.
 *
 * In production there is ONE CashFlow_Catalog per request (built on
 * plugins_loaded) and its tick() is bound to it. A long-lived Action
 * Scheduler runner (`wp action-scheduler run`, a raised time limit, a busy
 * store's backlog) can call that SAME object's tick() more than once. Every
 * other test builds a fresh object per tick, which hid this: each run below
 * ticks ONE object twice and must behave exactly as a fresh one would.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-sync-pull.php';
require_once __DIR__ . '/../includes/class-catalog.php';

$T = 1790000000.0;
const LIST_ID = '5b0f3c1e-2a8d-4c1f-9a51-0c9f3f1d2e77';
function store(): void {
    global $T;
    $T = 1790000000.0;
    CF_TestState::reset();
    CF_TestState::$options['cashflow_catalog_db_version'] = '1';
    CF_TestState::$options['cashflow_connection_secret']  = 'secret-xyz';
    CF_TestState::$options['siteurl'] = 'https://zensha.pk';
    CF_TestState::$options['home']    = 'https://zensha.pk';
    CF_TestState::$catalog_tables['wp_cashflow_catalog_queue'] = true;
    // A list opened just now: no hourly list is due unless a test opens one.
    CF_TestState::$options['cashflow_catalog_list'] = [ 'last_opened_at' => $T ];
    CashFlow_Catalog::$clock = function () { global $T; return $T; };
}
function saves( array $ids ): void {
    foreach ( $ids as $id ) {
        CF_TestState::$posts[ $id ]    = [ 'type' => 'product', 'status' => 'publish', 'parent' => 0 ];
        CF_TestState::$products[ $id ] = new WC_Product( $id, 0, 'publish', [ 'name' => "P$id" ] );
        CashFlow_Catalog::enqueue( $id, 'save' );
    }
}
function listed( array $ids ): void {
    foreach ( $ids as $id ) {
        CF_TestState::$posts[ $id ]    = [ 'type' => 'product', 'status' => 'publish', 'parent' => 0 ];
        CF_TestState::$products[ $id ] = new WC_Product( $id, 0, 'publish', [ 'name' => "P$id" ] );
    }
}
function open_list( int $page_size = 1000 ): void {
    global $T;
    CF_TestState::$options['cashflow_catalog_list'] = [ 'list_id' => LIST_ID, 'after_id' => 0, 'page_size' => $page_size, 'asked' => 0, 'last_opened_at' => $T ];
}
function respond( string $ep, int $n, callable $fn, float $secs = 0.0 ): void {
    for ( $i = 0; $i < $n; $i++ ) {
        CF_TestState::$api_responses[ $ep ][] = function ( $call ) use ( $fn, $secs ) {
            global $T;
            $T += $secs;
            return $fn( json_decode( $call['body'], true ) );
        };
    }
}
function ok200(): array {
    return [ 'ok' => true, 'status' => 200, 'data' => [ 'applied' => [], 'trashed' => [], 'unchanged' => [], 'list_wanted' => false ] ];
}
function err( int $code, $data ): array { return [ 'ok' => false, 'status' => $code, 'data' => $data ]; }
function follow(): callable {
    return function ( $b ) {
        $last = $b['rows'] ? end( $b['rows'] )[0] : $b['after_id'];
        return [ 'ok' => true, 'status' => 200, 'data' => [ 'after_id' => $last, 'complete' => $b['complete'],
            'outcome' => $b['complete'] ? 'trashed' : null, 'need' => [], 'trashed' => 0, 'trash_refused' => false, 'warnings' => [] ] ];
    };
}
function calls( string $ep ): array {
    return array_values( array_filter( CF_TestState::$api_calls, function ( $c ) use ( $ep ) { return $ep === $c['endpoint']; } ) );
}
function row_of( int $id ): ?array {
    foreach ( CF_TestState::$catalog_queue as $r ) { if ( (int) $r['product_id'] === $id ) { return $r; } }
    return null;
}
function last_error(): string { return (string) ( CashFlow_Catalog::stats()['last_error'] ?? '' ); }

echo "── an outage on the second run counts no try, as on a fresh run\n";
store();
$job = new CashFlow_Catalog();
saves( [ 8001 ] );
respond( '/plugin/catalog/products', 1, function () { return ok200(); } );
$job->tick();                                                  // run 1: a product goes through
ok( 'run 1 sent its product', null === row_of( 8001 ) );
$T += 61;
saves( [ 8002 ] );
respond( '/plugin/catalog/products', 2, function () { return err( 500, [ 'error' => 'catalogue_write_failed' ] ); } );
$before = count( calls( '/plugin/catalog/products' ) );
$job->tick();                                                  // run 2, SAME object: the server is down
ok( 'run 2 corroborated the 500 with an empty request — run 1\'s success is not run 2\'s',
    count( calls( '/plugin/catalog/products' ) ) - $before === 2, (string) ( count( calls( '/plugin/catalog/products' ) ) - $before ) );
ok( 'no try counted against the product', '0' === ( row_of( 8002 )['attempts'] ?? null ) );
ok( 'and the panel calls it an outage', str_contains( last_error(), 'an outage' ), last_error() );

echo "── a conflict is followed again on the second run\n";
store();
listed( [ 10, 13, 14 ] );
$job = new CashFlow_Catalog();
open_list();
respond( '/plugin/catalog/list/page', 1, function () { return err( 409, [ 'error' => 'position_mismatch', 'expected_after_id' => 13 ] ); } );
respond( '/plugin/catalog/list/page', 1, follow() );
$job->tick();                                                  // run 1: one conflict followed, the list completes
ok( 'run 1 followed its conflict', array_column( array_map( function ( $c ) { return json_decode( $c['body'], true ); }, calls( '/plugin/catalog/list/page' ) ), 'after_id' ) === [ 0, 13 ] );
$T += 61;
open_list();                                                   // a list is open again
respond( '/plugin/catalog/list/page', 1, function () { return err( 409, [ 'error' => 'position_mismatch', 'expected_after_id' => 13 ] ); } );
respond( '/plugin/catalog/list/page', 1, follow() );
$before = count( calls( '/plugin/catalog/list/page' ) );
$job->tick();                                                  // run 2, SAME object
$run2 = array_slice( array_map( function ( $c ) { return json_decode( $c['body'], true ); }, calls( '/plugin/catalog/list/page' ) ), $before );
ok( 'run 2 followed its own first conflict too: re-sent from the expected position', array_column( $run2, 'after_id' ) === [ 0, 13 ], json_encode( array_column( $run2, 'after_id' ) ) );
ok( 'never reported as a second conflict "this run"', ! str_contains( last_error(), 'twice this run' ), last_error() );
ok( 'and the list completed', empty( CashFlow_Catalog::list_state()['list_id'] ) );

echo "── pages are sent again on the second run after a failed page on the first\n";
store();
listed( [ 10, 13, 14 ] );
$job = new CashFlow_Catalog();
open_list( 1 );                                                // one product per page
respond( '/plugin/catalog/list/page', 1, function () { return err( 500, [ 'error' => 'catalogue_list_failed' ] ); } );
$job->tick();                                                  // run 1: the page fails; this run tries no more pages
ok( 'run 1 tried one page', count( calls( '/plugin/catalog/list/page' ) ) === 1 );
$T += 61;
respond( '/plugin/catalog/list/page', 4, follow() );
$job->tick();                                                  // run 2, SAME object
$run2 = array_slice( array_map( function ( $c ) { return json_decode( $c['body'], true ); }, calls( '/plugin/catalog/list/page' ) ), 1 );
ok( 'run 2 sent every page, not just its first (the last one, empty, says complete)', array_column( $run2, 'after_id' ) === [ 0, 10, 13, 14 ], json_encode( array_column( $run2, 'after_id' ) ) );
ok( 'and the list completed', empty( CashFlow_Catalog::list_state()['list_id'] ) );

/** Run 1 on $job: 4 saves, a 413, the first half sent, the second half cut short onto the solo list. */
function cutoff_run( CashFlow_Catalog $job ): void {
    saves( [ 7001, 7002, 7003, 7004 ] );
    respond( '/plugin/catalog/products', 1, function () { return err( 413, [ 'error' => 'request entity too large' ] ); }, 8.0 );
    respond( '/plugin/catalog/products', 1, function () { return ok200(); }, 8.0 );
    $job->tick();
}

echo "── a budget cutoff on the first run is not carried into the second's solo list\n";
store();
$job = new CashFlow_Catalog();
cutoff_run( $job );
ok( 'run 1 put the unsent half on the solo list', [ 7003, 7004 ] === CashFlow_Catalog::solo_ids() );
$T += 61;
saves( [ 7011, 7012, 7013, 7014 ] );                           // run 2 has its OWN cutoff
respond( '/plugin/catalog/products', 2, function () { return ok200(); } );   // the two solo ids, one at a time
respond( '/plugin/catalog/products', 1, function () { return err( 413, [ 'error' => 'request entity too large' ] ); }, 8.0 );
respond( '/plugin/catalog/products', 1, function () { return ok200(); }, 8.0 );
CF_TestState::$options['cashflow_catalog_stats'] = [];
$job->tick();                                                  // run 2, SAME object
ok( 'run 2 sent both solo ids', null === row_of( 7003 ) && null === row_of( 7004 ) );
ok( 'the solo list holds ONLY run 2\'s own cutoff — run 1\'s ids, already sent, are not put back',
    [ 7013, 7014 ] === CashFlow_Catalog::solo_ids(), json_encode( CashFlow_Catalog::solo_ids() ) );
ok( 'and the note counts only those', str_contains( last_error(), '2 products will be sent one at a time' ), last_error() );

echo "── a refusal on the second run ends it, as on a fresh run — a first run's cutoff does not excuse it\n";
store();
$job = new CashFlow_Catalog();
cutoff_run( $job );
$T += 61;
respond( '/plugin/catalog/products', 3, function () { return err( 401, [ 'error' => 'invalid connection secret' ] ); } );
$before = count( calls( '/plugin/catalog/products' ) );
$job->tick();                                                  // run 2, SAME object: the first solo send is refused
ok( 'one request, then the run ends', count( calls( '/plugin/catalog/products' ) ) - $before === 1,
    (string) ( count( calls( '/plugin/catalog/products' ) ) - $before ) );
ok( 'the solo ids stay listed for the next run', [ 7003, 7004 ] === CashFlow_Catalog::solo_ids(), json_encode( CashFlow_Catalog::solo_ids() ) );

CashFlow_Catalog::$clock = null;
summary();
