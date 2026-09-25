<?php
/**
 * THE LIST: ONE DIRECT QUERY, TIME-SIZED PAGES, AND THE SERVER DECIDES.
 *
 * The plugin keeps no list state it cannot lose [N4]: after any 409 it does
 * what the server says. A database error stops the list — it is never sent as
 * an empty page, because a complete list with pages missing would trash the
 * products it left out [NB3].
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-sync-pull.php';
require_once __DIR__ . '/../includes/class-catalog.php';

$T = 1790000000.0;
const LIST_ID  = '5b0f3c1e-2a8d-4c1f-9a51-0c9f3f1d2e77';
const LIST_ID2 = '0e9d4a77-1111-4c1f-9a51-0c9f3f1d2e77';
function store( array $ids = [ 10, 13, 14 ], int $page_size = 1000 ): void {
    global $T;
    $T = 1790000000.0;
    CF_TestState::reset();
    CF_TestState::$options['cashflow_catalog_db_version'] = '1';
    CF_TestState::$options['cashflow_connection_secret']  = 'secret-xyz';
    CF_TestState::$options['siteurl'] = 'https://zensha.pk';
    CF_TestState::$options['home']    = 'https://zensha.pk';
    CF_TestState::$catalog_tables['wp_cashflow_catalog_queue'] = true;
    CF_TestState::$options['cashflow_catalog_list'] = [ 'list_id' => LIST_ID, 'after_id' => 0, 'page_size' => $page_size, 'asked' => 0, 'last_opened_at' => $T ];
    CashFlow_Catalog::$clock = function () { global $T; return $T; };
    foreach ( $ids as $id ) {
        CF_TestState::$posts[ $id ] = [ 'type' => 'product', 'status' => 'publish', 'parent' => 0 ];
        CF_TestState::$products[ $id ] = new WC_Product( $id, 0, 'publish', [ 'name' => "P$id", 'price' => (string) ( 100 + $id ) ] );
    }
    CF_TestState::$posts[11] = [ 'type' => 'product', 'status' => 'trash', 'parent' => 0 ];              // outside the set
    CF_TestState::$posts[12] = [ 'type' => 'product_variation', 'status' => 'publish', 'parent' => 10 ]; // not a parent
}
function page_answers( int $n, callable $fn ): void {
    for ( $i = 0; $i < $n; $i++ ) {
        CF_TestState::$api_responses['/plugin/catalog/list/page'][] = function ( $call ) use ( $fn ) { return $fn( json_decode( $call['body'], true ) ); };
    }
}
function ok_page( array $data ): array { return [ 'ok' => true, 'status' => 200, 'data' => $data + [ 'need' => [], 'complete' => false, 'outcome' => null, 'trashed' => 0, 'trash_refused' => false, 'warnings' => [] ] ]; }
function conflict( array $data ): array { return [ 'ok' => false, 'status' => 409, 'data' => $data ]; }
/** The server's normal answer: position = the last id sent; complete echoed. */
function follow( array $extra = [] ): callable {
    return function ( $b ) use ( $extra ) {
        $last = $b['rows'] ? end( $b['rows'] )[0] : $b['after_id'];
        return ok_page( $extra + [ 'after_id' => $last, 'complete' => $b['complete'], 'outcome' => $b['complete'] ? 'trashed' : null ] );
    };
}
function pages(): array {
    return array_values( array_map( function ( $c ) { return json_decode( $c['body'], true ); },
        array_filter( CF_TestState::$api_calls, function ( $c ) { return '/plugin/catalog/list/page' === $c['endpoint']; } ) ) );
}
function run_job(): void { ( new CashFlow_Catalog() )->tick(); }
function fp( int $id ): string { return CashFlow_Catalog::payload( CF_TestState::$products[ $id ] )['fingerprint']; }

echo "── one page, exactly the wire's, of exactly the set\n";
store();
page_answers( 1, function ( $b ) { return ok_page( [ 'after_id' => 14, 'complete' => true, 'outcome' => 'trashed', 'trashed' => 2 ] ); } );
run_job();
$p = pages()[0] ?? [];
ok( 'the page carries site, list_id, after_id, rows, complete — nothing else', array_keys( $p ) === [ 'site', 'list_id', 'after_id', 'rows', 'complete' ], json_encode( array_keys( $p ) ) );
ok( 'the list id and position are the ones the server gave', ( $p['list_id'] ?? '' ) === LIST_ID && ( $p['after_id'] ?? null ) === 0 );
ok( 'rows: the set only (no trash, no variation), ascending, with the SAME fingerprint a send carries',
    ( $p['rows'] ?? null ) === [ [ 10, fp( 10 ) ], [ 13, fp( 13 ) ], [ 14, fp( 14 ) ] ] );
ok( 'the last page says complete', ( $p['complete'] ?? null ) === true );
$st = CashFlow_Catalog::list_state();
ok( 'a completed list is let go; its open time is kept for the hourly timer', empty( $st['list_id'] ) && ( $st['last_opened_at'] ?? null ) === $T );
$ll = CashFlow_Catalog::stats()['last_list'] ?? [];
ok( 'the panel shows the outcome and how many were trashed', ( $ll['result'] ?? '' ) === 'trashed' && ( $ll['trashed'] ?? null ) === 2 );

echo "── the set comes from ONE direct query, never WP_Query [NB3]\n";
ok( 'the enumeration ran as the plugin\'s own statement', in_array( 'catalog:enumerate', CF_TestState::$sql, true ) );
$src = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( __DIR__ . '/../includes/class-catalog.php' ) );
$src = preg_replace( '#//[^\n]*#', '', $src );
ok( 'no WP_Query, get_posts or wc_get_products anywhere in the class (comments stripped)',
    ! str_contains( $src, 'WP_Query' ) && ! str_contains( $src, 'get_posts(' ) && ! str_contains( $src, 'wc_get_products(' ) );

echo "── need is queued as asked, and sent in the same run\n";
store();
page_answers( 1, function () { return ok_page( [ 'need' => [ 13, 'junk', -1 ], 'after_id' => 14, 'complete' => true, 'outcome' => 'trashed' ] ); } );
CF_TestState::$api_responses['/plugin/catalog/products'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'applied' => [ 13 ], 'trashed' => [], 'unchanged' => [], 'warnings' => [], 'list_wanted' => false ] ];
run_job();
$sent = array_values( array_filter( CF_TestState::$api_calls, function ( $c ) { return '/plugin/catalog/products' === $c['endpoint']; } ) );
ok( 'the asked product went out in the same run', count( $sent ) === 1 && array_column( json_decode( $sent[0]['body'], true )['products'] ?? [], 'id' ) === [ 13 ] );
ok( 'junk in need is ignored', CF_TestState::$catalog_queue === [] );
ok( 'the panel counts what was asked', ( CashFlow_Catalog::stats()['last_list']['asked'] ?? null ) === 1 );

echo "── pages are sized by TIME, not count [NB2]\n";
store( range( 101, 200 ) );
CF_TestState::$on_product_get = function ( $prop ) { global $T; if ( 'name' === $prop ) { $T += 0.25; } };   // each product takes 0.25 s to read
page_answers( 5, follow() );
run_job();
CF_TestState::$on_product_get = null;
$sizes = array_map( function ( $p ) { return count( $p['rows'] ); }, pages() );
ok( 'three pages: 6 s, 6 s, then the 1 s the budget still allows', $sizes === [ 24, 24, 4 ], json_encode( $sizes ) );
ok( 'each continuing from the server\'s position', array_column( pages(), 'after_id' ) === [ 0, 124, 148 ] );
ok( 'none claimed complete', [ false, false, false ] === array_column( pages(), 'complete' ) );
ok( 'the list is held at 152 for the next run', ( CashFlow_Catalog::list_state()['after_id'] ?? null ) === 152 );

echo "── an exact page is followed by an empty complete page\n";
store( [ 10, 13, 14 ], 3 );
page_answers( 2, follow() );
run_job();
ok( 'a full page of 3, not complete; then rows [] complete', array_map( function ( $p ) { return [ count( $p['rows'] ), $p['complete'] ]; }, pages() ) === [ [ 3, false ], [ 0, true ] ] );

echo "── a database error stops the list; it never becomes an empty page [NB3]\n";
store();
CF_TestState::$db_error_on = 'SELECT ID FROM wp_posts';
run_job();
CF_TestState::$db_error_on = null;
ok( 'no page was sent', pages() === [] );
ok( 'the list is still held, at the same position', ( CashFlow_Catalog::list_state()['list_id'] ?? '' ) === LIST_ID && ( CashFlow_Catalog::list_state()['after_id'] ?? null ) === 0 );
ok( 'the panel says the list stopped and why', str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'product query failed' ) );

echo "── 409: the server's position, and the server's list\n";
store();
page_answers( 1, function () { return conflict( [ 'error' => 'position_mismatch', 'expected_after_id' => 13 ] ); } );
page_answers( 1, follow() );
run_job();
ok( 'position_mismatch: re-sent from the expected position', array_column( pages(), 'after_id' ) === [ 0, 13 ] && ( pages()[1]['rows'] ?? null ) === [ [ 14, fp( 14 ) ] ] );

store();
page_answers( 1, function () { return conflict( [ 'error' => 'list_expired', 'expected_after_id' => null ] ); } );
CF_TestState::$api_responses['/plugin/catalog/list/open'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'list_id' => LIST_ID2, 'after_id' => 0, 'resend' => false, 'resumed' => false, 'page_size' => 1000 ] ];
page_answers( 1, follow() );
run_job();
ok( 'list_expired: a new list is opened in the same run and paged', ( pages()[1]['list_id'] ?? '' ) === LIST_ID2 );

store();
page_answers( 1, function () { return conflict( [ 'error' => 'list_not_found', 'expected_after_id' => null ] ); } );
CF_TestState::$api_responses['/plugin/catalog/list/open'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'list_id' => LIST_ID2, 'after_id' => 0, 'resend' => false, 'resumed' => false, 'page_size' => 1000 ] ];
page_answers( 1, follow() );
run_job();
ok( 'list_not_found: the same', ( pages()[1]['list_id'] ?? '' ) === LIST_ID2 );

store();
page_answers( 1, function () { return conflict( [ 'error' => 'list_closed', 'expected_after_id' => null ] ); } );
run_job();
$st = CashFlow_Catalog::list_state();
ok( 'list_closed: let go, NOT reopened now (next hour)', empty( $st['list_id'] ) && empty( $st['wanted'] ) && ( $st['last_opened_at'] ?? null ) === $T
    && [] === array_filter( CF_TestState::$api_calls, function ( $c ) { return '/plugin/catalog/list/open' === $c['endpoint']; } ) );
ok( 'and the panel says so', ( CashFlow_Catalog::stats()['last_list']['result'] ?? '' ) === 'list_closed' );

echo "── the outcomes are shown as the server gave them\n";
store();
page_answers( 1, function () { return ok_page( [ 'after_id' => 14, 'complete' => true, 'outcome' => 'trash_refused', 'trash_refused' => true ] ); } );
run_job();
ok( 'trash_refused', ( CashFlow_Catalog::stats()['last_list']['result'] ?? '' ) === 'trash_refused' && true === ( CashFlow_Catalog::stats()['last_list']['trash_refused'] ?? null ) );
store();
page_answers( 1, function () { return ok_page( [ 'after_id' => 14, 'complete' => true, 'outcome' => 'flawed' ] ); } );
run_job();
ok( 'flawed', ( CashFlow_Catalog::stats()['last_list']['result'] ?? '' ) === 'flawed' );

echo "── a product that cannot be read is listed with no fingerprint (so the server asks)\n";
store();
CF_TestState::$terms[92] = (object) [ 'term_id' => 92, 'name' => 'Scarf', 'slug' => 'scarf', 'taxonomy' => 'product_cat', 'count' => 1 ];
CF_TestState::$products[13] = new WC_Product( 13, 0, 'publish', [ 'category_ids' => [ 92 ] ] );
CF_TestState::$terms_error = 'Deadlock found';
page_answers( 1, follow() );
run_job();
CF_TestState::$terms_error = null;
ok( 'its row carries null', ( pages()[0]['rows'][1] ?? null ) === [ 13, null ] );

echo "── a failed page keeps the list where it was\n";
store();
page_answers( 1, function () { return [ 'ok' => false, 'status' => 500, 'data' => [ 'error' => 'catalogue_list_failed' ] ]; } );
run_job();
ok( 'still held at 0, reason on the panel', ( CashFlow_Catalog::list_state()['after_id'] ?? null ) === 0
    && str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'Sending a list page failed: HTTP 500: catalogue_list_failed' ) );
ok( 'and no second request was made to the route that just refused', count( pages() ) === 1 );

echo "── a real refusal on the list route ends the run — no second request anywhere [task-12]\n";
store();
page_answers( 1, function () { return [ 'ok' => false, 'status' => 401, 'data' => [ 'error' => 'invalid connection secret' ] ]; } );
run_job();
ok( '401 on list/page: the run stops on the spot', count( pages() ) === 1 && true === CashFlow_Catalog::stats()['not_connected'] );
ok( 'the list is left exactly where it was', ( CashFlow_Catalog::list_state()['list_id'] ?? '' ) === LIST_ID
    && ( CashFlow_Catalog::list_state()['after_id'] ?? null ) === 0 );

echo "── a long real-save queue does not starve an open list [task-12, new requirement]\n";
store( range( 201, 217 ), 5 );
for ( $id = 1001; $id <= 1200; $id++ ) {
    CashFlow_Catalog::enqueue( $id, 'save' );   // 200 real saves — a bulk edit
}
// A "slow server": every real-save batch costs 5 s of (simulated) wall time.
$slow_products = function () {
    global $T;
    $T += 5;
    return [ 'ok' => true, 'status' => 200, 'data' => [ 'applied' => [], 'trashed' => [], 'unchanged' => [], 'warnings' => [], 'list_wanted' => false ] ];
};
for ( $i = 0; $i < 10; $i++ ) {
    CF_TestState::$api_responses['/plugin/catalog/products'][] = $slow_products;
}
page_answers( 6, follow() );

run_job();
ok( 'tick 1: the real-save phase does NOT drain to exhaustion — it yields with the reserve still in hand',
    CashFlow_Catalog::count_pending() === 125, (string) CashFlow_Catalog::count_pending() );
ok( 'tick 1: the list still gets its page — one, not zero, despite 200 real saves being due',
    count( pages() ) === 1 );
ok( 'tick 1: the list advanced past its very first row and is still open (not force-completed, not expired)',
    ( CashFlow_Catalog::list_state()['after_id'] ?? null ) === 205 && ! empty( CashFlow_Catalog::list_state()['list_id'] ) );

run_job();
ok( 'tick 2: real saves are STILL draining — the reserve does not stall them either',
    CashFlow_Catalog::count_pending() === 50, (string) CashFlow_Catalog::count_pending() );
ok( 'tick 2: the list advanced again — it is never skipped while the backlog exists',
    count( pages() ) === 2 && ( CashFlow_Catalog::list_state()['after_id'] ?? null ) === 210 );

run_job();
ok( 'tick 3: the whole real-save backlog is gone — nothing was starved for good, only delayed',
    CashFlow_Catalog::count_pending() === 0, (string) CashFlow_Catalog::count_pending() );
ok( 'tick 3: the list reaches its own end — every id in the set was still reached',
    empty( CashFlow_Catalog::list_state()['list_id'] ) && count( pages() ) === 4 );

CashFlow_Catalog::$clock = null;
summary();
