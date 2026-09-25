<?php
/**
 * WHAT THE STORE OWNER SEES. Every way the catalogue can stop is a line on
 * the panel, never a silence (Golden Rule #6).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-sync-pull.php';
require_once __DIR__ . '/../includes/class-catalog.php';

function store(): void {
    CF_TestState::reset();
    CF_TestState::$options['cashflow_catalog_db_version'] = '1';
    CF_TestState::$options['cashflow_connection_secret']  = 'secret-xyz';
    CF_TestState::$options['siteurl'] = 'http://1shop.pk';
    CF_TestState::$options['home']    = 'https://1shop.pk';
    CF_TestState::$options['cashflow_catalog_list'] = [ 'last_opened_at' => 1790000000.0 ];
    CF_TestState::$catalog_tables['wp_cashflow_catalog_queue'] = true;
    CashFlow_Catalog::$clock = function () { return 1790000000.0; };
}

echo "── a healthy, idle store\n";
store();
$s = CashFlow_Catalog::status_summary();
ok( 'the table is ready and nothing waits', $s['table_ready'] === true && $s['pending'] === 0 && $s['parked'] === 0 && $s['parked_ids'] === [] );
ok( 'no refusal, connected, no error, no warnings', null === $s['site_refusal'] && false === $s['not_connected'] && '' === $s['last_error'] && 0 === $s['last_warnings'] );
ok( 'every key the panel reads is there', array_keys( $s ) === [ 'available', 'scheduled', 'table_ready', 'pending', 'parked', 'parked_ids',
    'last_send_at', 'sent_count', 'last_warnings', 'list_open', 'list_position', 'last_list', 'last_list_text', 'site_refusal', 'not_connected',
    'last_error', 'last_error_at' ], json_encode( array_keys( $s ) ) );
ok( 'and no secret is in it', ! str_contains( json_encode( $s ), 'secret-xyz' ) );

echo "── the queue table is missing\n";
store();
CF_TestState::$catalog_tables = [];
$s = CashFlow_Catalog::status_summary();
ok( 'table_ready is false, and nothing is counted from a table that is not there', false === $s['table_ready'] && 0 === $s['pending'] && 0 === $s['parked'] );

echo "── parked products are named\n";
store();
foreach ( [ 501, 502 ] as $i => $pid ) {
    CF_TestState::$catalog_queue[ $i + 1 ] = [ 'id' => (string) ( $i + 1 ), 'product_id' => (string) $pid, 'reason' => 'save', 'token' => null,
        'attempts' => '5', 'queued_at' => '2026-09-21 00:00:00', 'claimed_at' => null, 'retry_at' => null, 'parked_at' => '2026-09-21 00:05:00', 'pending_key' => null ];
}
CF_TestState::$catalog_queue_next = 3;       // the next row id after the two fixtures, as AUTO_INCREMENT would
CashFlow_Catalog::enqueue( 503, 'save' );
$s = CashFlow_Catalog::status_summary();
ok( 'two parked, by product id; one waiting', 2 === $s['parked'] && [ 501, 502 ] === $s['parked_ids'] && 1 === $s['pending'] );

echo "── a real send's warnings reach the panel, and a clean one clears them\n";
store();
CF_TestState::$products[503] = new WC_Product( 503, 0, 'publish', [ 'name' => 'Scarf' ] );
CashFlow_Catalog::enqueue( 503, 'save' );
CF_TestState::$api_responses['/plugin/catalog/products'][] = [ 'ok' => true, 'status' => 200, 'data' => [
    'applied' => [ 503 ], 'trashed' => [], 'unchanged' => [],
    'warnings' => [ [ 'id' => 503, 'field' => 'name', 'code' => 'truncated' ] ], 'list_wanted' => false,
] ];
( new CashFlow_Catalog() )->tick();
ok( 'the last send\'s warning count is on the panel', 1 === CashFlow_Catalog::status_summary()['last_warnings'] );
CF_TestState::$products[504] = new WC_Product( 504, 0, 'publish', [ 'name' => 'Belt' ] );
CashFlow_Catalog::enqueue( 504, 'save' );
CF_TestState::$api_responses['/plugin/catalog/products'][] = [ 'ok' => true, 'status' => 200, 'data' => [
    'applied' => [ 504 ], 'trashed' => [], 'unchanged' => [], 'warnings' => [], 'list_wanted' => false,
] ];
( new CashFlow_Catalog() )->tick();
ok( 'a clean send clears the count, never leaving a stale one', 0 === CashFlow_Catalog::status_summary()['last_warnings'] );

echo "── a site refusal and a lost connection, from real answers\n";
store();
CF_TestState::$products[503] = new WC_Product( 503, 0, 'publish', [ 'name' => 'Scarf' ] );
CashFlow_Catalog::enqueue( 503, 'save' );
CF_TestState::$api_responses['/plugin/catalog/products'][] = [ 'ok' => false, 'status' => 403, 'data' => [ 'error' => 'site_mismatch', 'reason' => 'host_differs' ] ];
( new CashFlow_Catalog() )->tick();
$s = CashFlow_Catalog::status_summary();
ok( 'the refusal, its reason, and BOTH addresses this site sent', ( $s['site_refusal']['reason'] ?? '' ) === 'host_differs'
    && ( $s['site_refusal']['site'] ?? null ) === [ 'siteurl' => 'http://1shop.pk', 'home' => 'https://1shop.pk' ] );
// describe_connection_refusal()'s wording for this exact shape (403
// site_mismatch) is "CashFlow does not recognise this site's address
// (host_differs)" — it never repeats the wire's own error CODE, so this
// checks for the reason it DOES carry, not a string the code has moved past.
ok( 'and the error line carries the reason', str_contains( $s['last_error'], 'host_differs' ) && '' !== $s['last_error_at'], $s['last_error'] );
CF_TestState::$api_responses['/plugin/catalog/products'][] = [ 'ok' => false, 'status' => 401, 'data' => [ 'error' => 'invalid connection secret' ] ];
( new CashFlow_Catalog() )->tick();
ok( 'a 401 shows as not connected', true === CashFlow_Catalog::status_summary()['not_connected'] );

echo "── the last list, in plain words\n";
$cases = [
    [ [ 'result' => 'trashed', 'trashed' => 3, 'asked' => 2, 'at' => '2026-09-21T14:13:20+00:00' ], 'Complete: 3 removed from the shop marked as trash in CashFlow; 2 re-sent' ],
    [ [ 'result' => 'trashed', 'trashed' => 0, 'asked' => 0, 'at' => 'x' ], 'Complete: CashFlow matches the shop' ],
    [ [ 'result' => 'trash_refused', 'trashed' => 0, 'asked' => 0, 'at' => 'x' ], 'Complete, but CashFlow held back removing many products at once; the next complete list confirms it' ],
    [ [ 'result' => 'flawed', 'at' => 'x' ], 'Complete, but a page was unusable, so nothing was removed' ],
    [ [ 'result' => 'later', 'at' => 'x' ], 'Waiting: CashFlow asked to try later' ],
    [ [ 'result' => 'later', 'at' => 'x', 'retry_at' => '2026-09-21T15:00:00+00:00' ], 'Waiting: CashFlow asked to try later; it will try again at 2026-09-21T15:00:00+00:00' ],
    [ [ 'result' => 'list_expired', 'at' => 'x' ], 'Restarted: the list sat idle too long' ],
    [ [ 'result' => 'list_not_found', 'at' => 'x' ], 'Restarted: CashFlow did not know the list' ],
    [ [ 'result' => 'list_closed', 'at' => 'x' ], 'Already complete' ],
    [ null, 'Not yet run' ],
];
foreach ( $cases as [ $last, $want ] ) {
    ok( 'describe_list: ' . ( $last['result'] ?? 'none' ) . ( isset( $last['trashed'] ) ? ' / ' . $last['trashed'] : '' ) . ( isset( $last['retry_at'] ) ? ' /retry' : '' ),
        CashFlow_Catalog::describe_list( $last ) === $want, CashFlow_Catalog::describe_list( $last ) );
}
ok( 'an unknown result is shown as it came, never hidden', CashFlow_Catalog::describe_list( [ 'result' => 'something_new', 'at' => 'x' ] ) === 'Last result: something_new' );

echo "── a real 'later' answer writes its own retry time, and the panel shows it\n";
store();
CF_TestState::$options['cashflow_catalog_list'] = [];   // never listed before: due at once
CF_TestState::$api_responses['/plugin/catalog/list/open'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'later' => true, 'retry_after_seconds' => 600 ] ];
( new CashFlow_Catalog() )->tick();
$want_retry = gmdate( 'c', (int) floor( 1790000000.0 + 600 ) );
$s = CashFlow_Catalog::status_summary();
ok( 'the list state carries the server\'s retry time', ( CashFlow_Catalog::list_state()['retry_at'] ?? null ) === 1790000000.0 + 600 );
ok( 'the panel names when it tries again', $s['last_list_text'] === 'Waiting: CashFlow asked to try later; it will try again at ' . $want_retry, $s['last_list_text'] );

echo "── the summary never throws\n";
store();
$real = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new class extends CF_Test_WPDB { public function get_var( $q ) { throw new RuntimeException( 'gone away' ); } };
$threw = false;
try { $s = CashFlow_Catalog::status_summary(); } catch ( Throwable $e ) { $threw = true; }
$GLOBALS['wpdb'] = $real;
ok( 'a database failure reads as "table not ready", not as a broken admin page', ! $threw && false === $s['table_ready'] );

echo "── the admin card shows every line (source, comments stripped)\n";
$view = (string) file_get_contents( __DIR__ . '/../admin/views/settings.php' );
$view = preg_replace( '#<!--.*?-->#s', '', $view );
$view = preg_replace( '#/\*.*?\*/#s', '', $view );
ok( 'it reads CashFlow_Catalog::status_summary()', str_contains( $view, 'CashFlow_Catalog::status_summary()' ) );
foreach ( [ "['table_ready']", "['site_refusal']", "['not_connected']", "['parked_ids']", "['parked']", "['pending']",
            "['last_list_text']", "['last_send_at']", "['last_error']", "['available']", "['last_warnings']" ] as $key ) {
    ok( "it renders $key", str_contains( $view, $key ) );
}
ok( 'every value it prints is escaped', ! preg_match( "/echo\s+\\\$cf_cat\[/", $view ) );

CashFlow_Catalog::$clock = null;
summary();
