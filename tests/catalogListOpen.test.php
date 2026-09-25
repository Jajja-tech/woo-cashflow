<?php
/**
 * WHEN A LIST OPENS, AND WHAT OPENING ONE DOES.
 *
 * Once an hour, or as soon as the server says list_wanted. "later" is obeyed.
 * A resend queues every product in the set before the list runs. The plugin
 * keeps only what the server can give back: lose the option, open again, and
 * the server returns the same open list and its position.
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
    CashFlow_Catalog::$clock = function () { global $T; return $T; };
}
function opens( array $data, int $status = 200 ): void {
    CF_TestState::$api_responses['/plugin/catalog/list/open'][] = [ 'ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data ];
}
function calls_to( string $ep ): array {
    return array_values( array_filter( CF_TestState::$api_calls, function ( $c ) use ( $ep ) { return $ep === $c['endpoint']; } ) );
}
function run_job(): void { ( new CashFlow_Catalog() )->tick(); }
$opened = [ 'list_id' => LIST_ID, 'after_id' => 0, 'resend' => false, 'resumed' => false, 'page_size' => 1000 ];

echo "── a store that has never listed opens a list on its first run\n";
store();
opens( $opened );
run_job();
$c = calls_to( '/plugin/catalog/list/open' );
ok( 'one open', count( $c ) === 1 );
ok( 'its body is just the site', json_decode( $c[0]['body'] ?? 'null', true ) === [ 'site' => [ 'siteurl' => 'https://zensha.pk', 'home' => 'https://zensha.pk' ] ] );
ok( 'with the 10-second timeout', ( $c[0]['timeout'] ?? null ) === 10 );
$st = CashFlow_Catalog::list_state();
ok( 'the list id, position and page size are kept', ( $st['list_id'] ?? null ) === LIST_ID && ( $st['after_id'] ?? null ) === 0 && ( $st['page_size'] ?? null ) === 1000 );
ok( 'and when it opened', ( $st['last_opened_at'] ?? null ) === $T );

echo "── not again within the hour, unless the server asks\n";
store();
CF_TestState::$options['cashflow_catalog_list'] = [ 'last_opened_at' => $T - 3599 ];
run_job();
ok( '59:59 after the last list: no open', calls_to( '/plugin/catalog/list/open' ) === [] );
store();
CF_TestState::$options['cashflow_catalog_list'] = [ 'last_opened_at' => $T - 3600 ];
opens( $opened );
run_job();
ok( 'an hour after: it opens', count( calls_to( '/plugin/catalog/list/open' ) ) === 1 );
store();
CF_TestState::$options['cashflow_catalog_list'] = [ 'last_opened_at' => $T - 60, 'wanted' => true ];
opens( $opened );
run_job();
ok( 'list_wanted opens one at once', count( calls_to( '/plugin/catalog/list/open' ) ) === 1 && empty( CashFlow_Catalog::list_state()['wanted'] ) );

echo "── later is obeyed, even when a list is wanted\n";
store();
opens( [ 'later' => true, 'retry_after_seconds' => 600 ] );
run_job();
$st = CashFlow_Catalog::list_state();
ok( 'no list is held', empty( $st['list_id'] ) );
ok( 'the retry time is the server\'s', ( $st['retry_at'] ?? null ) === $T + 600 );
ok( 'the panel says later', ( CashFlow_Catalog::stats()['last_list']['result'] ?? '' ) === 'later' );
$st['wanted'] = true;
update_option( 'cashflow_catalog_list', $st );
$T += 599;
run_job();
ok( '599 s later, wanted or not, it does not ask again', count( calls_to( '/plugin/catalog/list/open' ) ) === 1 );
$T += 1;
opens( $opened );
run_job();
ok( 'at 600 s it does', count( calls_to( '/plugin/catalog/list/open' ) ) === 2 && ( CashFlow_Catalog::list_state()['list_id'] ?? null ) === LIST_ID );

echo "── the server's position and page size are followed\n";
store();
opens( [ 'list_id' => LIST_ID, 'after_id' => 19642, 'resend' => false, 'resumed' => true, 'page_size' => 5000 ] );
run_job();
$st = CashFlow_Catalog::list_state();
ok( 'a resumed list continues from the server\'s position', ( $st['after_id'] ?? null ) === 19642 );
ok( 'a page size over 1,000 is held to 1,000', ( $st['page_size'] ?? null ) === 1000 );
ok( 'the panel knows it was resumed', true === ( CashFlow_Catalog::stats()['last_list_resumed'] ?? null ) );
store();
opens( [ 'list_id' => LIST_ID, 'after_id' => 0, 'resend' => false, 'resumed' => false, 'page_size' => 250 ] );
run_job();
ok( 'a smaller page size is used as given', ( CashFlow_Catalog::list_state()['page_size'] ?? null ) === 250 );

echo "── a resend queues every product in the set first [S9]\n";
store();
CF_TestState::$posts = [
    10 => [ 'type' => 'product', 'status' => 'publish', 'parent' => 0 ],
    11 => [ 'type' => 'product', 'status' => 'draft', 'parent' => 0 ],
    12 => [ 'type' => 'product', 'status' => 'trash', 'parent' => 0 ],
];
opens( [ 'list_id' => LIST_ID, 'after_id' => 0, 'resend' => true, 'resumed' => false, 'page_size' => 1000 ] );
run_job();
$queued = array_map( function ( $r ) { return $r['product_id'] . ':' . $r['reason']; }, array_values( CF_TestState::$catalog_queue ) );
sort( $queued );
ok( 'the set is queued as resend', $queued === [ '10:resend', '11:resend' ], json_encode( $queued ) );
ok( 'and the list is held', ( CashFlow_Catalog::list_state()['list_id'] ?? null ) === LIST_ID );

store();
CF_TestState::$posts = [ 10 => [ 'type' => 'product', 'status' => 'publish', 'parent' => 0 ] ];
CF_TestState::$db_error_on = "SELECT ID, 'resend'";
opens( [ 'list_id' => LIST_ID, 'after_id' => 0, 'resend' => true, 'resumed' => false, 'page_size' => 1000 ] );
run_job();
CF_TestState::$db_error_on = null;
ok( 'a resend that could not be queued does NOT hold the list', empty( CashFlow_Catalog::list_state()['list_id'] ) );
ok( 'so the next run opens again (the server hands back the same list, resend still set)', CashFlow_Catalog::list_state() === [] || empty( CashFlow_Catalog::list_state()['last_opened_at'] ) );
ok( 'and the panel says why', str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'resend could not be queued' ) );

echo "── a failed open\n";
store();
opens( [ 'error' => 'catalogue_list_failed' ], 500 );
run_job();
ok( 'a 500 holds no list and is on the panel', empty( CashFlow_Catalog::list_state()['list_id'] )
    && str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'Opening the hourly list failed: HTTP 500: catalogue_list_failed' ) );
store();
opens( [ 'error' => 'site_mismatch', 'reason' => 'host_differs' ], 403 );
run_job();
ok( 'a site refusal on open is recorded like any other', ( CashFlow_Catalog::stats()['site_refusal']['reason'] ?? '' ) === 'host_differs' );
store();
opens( [ 'something' => 'else' ] );
run_job();
ok( 'a 200 with no list id is not a list', empty( CashFlow_Catalog::list_state()['list_id'] )
    && str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'without a list id' ) );

echo "── real saves still go first\n";
store();
CF_TestState::$products[501] = new WC_Product( 501, 0, 'publish', [ 'name' => 'Scarf' ] );
CashFlow_Catalog::enqueue( 501, 'save' );
CF_TestState::$api_responses['/plugin/catalog/products'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'list_wanted' => true ] ];
opens( $opened );
run_job();
// (Once pages exist, the list's first page follows in the same run; only the order of the first two matters here.)
ok( 'the save, then the list', array_slice( array_column( CF_TestState::$api_calls, 'endpoint' ), 0, 2 ) === [ '/plugin/catalog/products', '/plugin/catalog/list/open' ] );

echo "── the order poll declares the capability, and it is not a command\n";
store();
CF_TestState::$api_responses['/plugin/sync/poll'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'jobs' => [], 'commands' => [
    [ 'command_id' => 'c1', 'kind' => 'catalog.push', 'capability' => 'catalog.push@1', 'idempotency_key' => 'k1' ] ] ] ];
CF_TestState::$api_responses['/plugin/commands/ack'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'ok' => true ] ];
( new CashFlow_Sync_Pull() )->tick();
ok( 'supports carries catalog.push@1 beside order.create@1', ( calls_to( '/plugin/sync/poll' )[0]['body']['supports'] ?? null ) === [ 'order.create@1', 'catalog.push@1' ] );
ok( 'a command asking for it is acked unsupported', ( calls_to( '/plugin/commands/ack' )[0]['body']['outcome'] ?? '' ) === 'unsupported' );

CashFlow_Catalog::$clock = null;
summary();
