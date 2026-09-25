<?php
/**
 * A PRODUCT SAVED ON THE SITE IS IN CASHFLOW WITHIN ONE JOB RUN.
 *
 * Runs the REAL CashFlow_Catalog::tick() against the harness; the network
 * (CashFlow_Plugin::api_request) is the only thing faked, and it records every
 * body so the wire can be read back byte for byte.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-sync-pull.php';
require_once __DIR__ . '/../includes/class-catalog.php';

$T = 1790000000.0;
function store(): void {
    global $T;
    $T = 1790000000.0;
    CF_TestState::reset();
    CF_TestState::$options['cashflow_catalog_db_version'] = '1';
    CF_TestState::$options['cashflow_connection_secret']  = 'secret-xyz';
    CF_TestState::$options['siteurl'] = 'http://1shop.pk';
    CF_TestState::$options['home']    = 'https://www.1shop.pk/';
    CF_TestState::$catalog_tables['wp_cashflow_catalog_queue'] = true;
    // A list opened just now: no hourly list is due while these tests send products.
    CF_TestState::$options['cashflow_catalog_list'] = [ 'last_opened_at' => 1790000000.0 ];
    CashFlow_Catalog::$clock = function () { global $T; return $T; };
}
function product( int $id, array $props = [], string $status = 'publish' ): void {
    CF_TestState::$products[ $id ] = new WC_Product( $id, 0, $status, array_merge( [ 'name' => "Product $id", 'price' => '990' ], $props ) );
}
function products_ok( int $n = 1, array $data = [], float $advance = 0.0 ): void {
    for ( $i = 0; $i < $n; $i++ ) {
        CF_TestState::$api_responses['/plugin/catalog/products'][] = function () use ( $data, $advance ) {
            global $T;
            $T += $advance;
            return [ 'ok' => true, 'status' => 200, 'data' => array_merge(
                [ 'applied' => [], 'trashed' => [], 'unchanged' => [], 'warnings' => [], 'list_wanted' => false ], $data ) ];
        };
    }
}
function sent(): array {
    return array_values( array_map( function ( $c ) { return $c + [ 'json' => json_decode( $c['body'], true ) ]; },
        array_filter( CF_TestState::$api_calls, function ( $c ) { return '/plugin/catalog/products' === $c['endpoint']; } ) ) );
}
function ids_in( $call, string $key = 'products' ): array {
    // Untyped on purpose: before the feature exists a call is missing (null),
    // and a failing check must print ✖, not die on a TypeError.
    return array_map( function ( $p ) { return is_array( $p ) ? $p['id'] : $p; }, $call['json'][ $key ] ?? [] );
}
function run_job(): void { ( new CashFlow_Catalog() )->tick(); }

echo "── one save, one run, on the wire exactly as the contract says\n";
store();
product( 501, [ 'name' => 'Scarf', 'regular_price' => '1200', 'sale_price' => '990' ] );
CashFlow_Catalog::enqueue( 501, 'save' );
products_ok();
$inside = null;
CF_TestState::$on_product_get = function () use ( &$inside ) { if ( null === $inside ) { $inside = CashFlow_Catalog::next_seq(); } };
run_job();
CF_TestState::$on_product_get = null;
$s = sent();
ok( 'exactly one POST to /plugin/catalog/products', count( $s ) === 1, count( $s ) . ' calls' );
$call = $s[0] ?? [ 'json' => [], 'body' => null, 'token' => null, 'method' => null, 'timeout' => null ];
ok( 'the body was encoded by the catalogue (a string) and is valid JSON', is_string( $call['body'] ) && is_array( $call['json'] ) );
ok( 'it carries the raw siteurl AND home', ( $call['json']['site'] ?? null ) === [ 'siteurl' => 'http://1shop.pk', 'home' => 'https://www.1shop.pk/' ] );
ok( 'authenticated by the connection secret', $call['token'] === 'secret-xyz' && $call['method'] === 'POST' );
ok( 'with a 10-second timeout', $call['timeout'] === 10 );
$p = $call['json']['products'][0] ?? [];
ok( 'the product is the payload builder\'s, plus fingerprint and sent_seq',
    array_keys( $p ) === array_merge( array_keys( CashFlow_Catalog::payload( CF_TestState::$products[501] )['fields'] ), [ 'fingerprint', 'sent_seq' ] ) );
ok( 'the fingerprint is the one the builder computes', $p['fingerprint'] === CashFlow_Catalog::payload( CF_TestState::$products[501] )['fingerprint'] );
ok( 'sent_seq is an integer STRING', is_string( $p['sent_seq'] ) && 1 === preg_match( '/^[0-9]{16}$/', $p['sent_seq'] ) );
ok( 'sent_seq was taken BEFORE the product was read [NB5]', null !== $inside && (int) $p['sent_seq'] < (int) $inside );
ok( 'no removed key when nothing was removed', ! array_key_exists( 'removed', $call['json'] ) );
ok( 'on 200 the row is deleted', CF_TestState::$catalog_queue === [] );
ok( 'the panel records the send', ( CashFlow_Catalog::stats()['sent_count'] ?? 0 ) === 1 && ! empty( CashFlow_Catalog::stats()['last_send_at'] ) );
ok( 'the object cache was flushed after the batch [NB2]', CF_TestState::$cache_flushes >= 1 );

echo "── a save made while the send is in flight is not lost [B5]\n";
store();
product( 501 );
CashFlow_Catalog::enqueue( 501, 'save' );
CF_TestState::$api_responses['/plugin/catalog/products'][] = function () {
    CashFlow_Catalog::enqueue( 501, 'save' );                 // the admin saves again while we are sending
    return [ 'ok' => true, 'status' => 200, 'data' => [ 'list_wanted' => false ] ];
};
products_ok();
run_job();
$s = sent();
ok( 'the second save went out in the same run', count( $s ) === 2 && ids_in( $s[1] ?? null ) === [ 501 ] );
ok( 'with a later sent_seq', (int) ( $s[1]['json']['products'][0]['sent_seq'] ?? 0 ) > (int) ( $s[0]['json']['products'][0]['sent_seq'] ?? 0 ) );
ok( 'and nothing is left behind', CF_TestState::$catalog_queue === [] );

echo "── real saves go before asked and resend [review-2]\n";
store();
product( 501 ); product( 601 ); product( 701 );
CashFlow_Catalog::enqueue( 601, 'asked' );
CashFlow_Catalog::enqueue( 701, 'resend' );
CashFlow_Catalog::enqueue( 501, 'save' );
products_ok( 2 );
run_job();
$s = sent();
ok( 'the first body is the real save alone', ids_in( $s[0] ?? null ) === [ 501 ] );
ok( 'then asked and resend', ids_in( $s[1] ?? null ) === [ 601, 701 ] );

echo "── what the shop no longer has goes as removed\n";
store();
product( 780 );
product( 790, [], 'trash' );
product( 795, [], 'auto-draft' );
CashFlow_Catalog::enqueue( 777, 'removed' );                 // permanently deleted
CashFlow_Catalog::enqueue( 778, 'save' );                    // saved, then deleted before the run
CashFlow_Catalog::enqueue( 780, 'removed' );                 // a delete that did not complete: the product is still there
CashFlow_Catalog::enqueue( 790, 'save' );                    // trashed
CashFlow_Catalog::enqueue( 795, 'save' );                    // an auto-draft: outside the set
products_ok();
run_job();
$call = sent()[0] ?? null;
ok( 'a missing product is a removal, with its own sent_seq', ids_in( $call, 'removed' ) === [ 777, 778 ]
    && 1 === preg_match( '/^[0-9]{16}$/', $call['json']['removed'][0]['sent_seq'] ?? '' ) );
ok( 'a product that still exists is sent as itself — the shop\'s truth wins', in_array( 780, ids_in( $call ), true ) );
ok( 'a trashed product is sent with status trash', in_array( 'trash', array_column( $call['json']['products'] ?? [], 'status' ), true ) );
ok( 'an auto-draft is not sent at all', ! in_array( 795, ids_in( $call ), true ) );
ok( 'and its row is gone too', CF_TestState::$catalog_queue === [] );

echo "── at most 25 per body\n";
store();
for ( $i = 1; $i <= 30; $i++ ) { product( 1000 + $i ); CashFlow_Catalog::enqueue( 1000 + $i, 'save' ); }
products_ok( 2 );
run_job();
$s = sent();
ok( '30 saves → a body of 25, then a body of 5', count( $s ) === 2 && count( ids_in( $s[0] ?? null ) ) === 25 && count( ids_in( $s[1] ?? null ) ) === 5 );

echo "── never more than 80 KB in one body\n";
store();
for ( $t = 1; $t <= 30; $t++ ) {
    CF_TestState::$terms[ 5000 + $t ] = (object) [ 'term_id' => 5000 + $t, 'name' => str_repeat( 'n', 200 ), 'slug' => "s$t", 'taxonomy' => 'product_cat', 'count' => 1 ];
}
for ( $i = 1; $i <= 25; $i++ ) { product( 2000 + $i, [ 'category_ids' => range( 5001, 5030 ) ] ); CashFlow_Catalog::enqueue( 2000 + $i, 'save' ); }
products_ok( 10 );
run_job();
$s = sent();
$max = max( array_merge( [ 0 ], array_map( function ( $c ) { return strlen( $c['body'] ); }, $s ) ) );
ok( 'the batch was split into several bodies', count( $s ) > 1, count( $s ) . ' bodies' );
ok( 'none over 81,920 bytes', $max <= 81920, "largest $max" );
$all = [];
foreach ( $s as $c ) { $all = array_merge( $all, ids_in( $c ) ); }
sort( $all );
ok( 'every product went, once, in the same run', $all === range( 2001, 2025 ) );

echo "── no request starts with less than 12 seconds of the 25-second budget\n";
store();
for ( $i = 1; $i <= 60; $i++ ) { product( 3000 + $i ); CashFlow_Catalog::enqueue( 3000 + $i, 'save' ); }
products_ok( 3, [], 7.0 );                                    // each request takes 7 seconds
run_job();
ok( 'two requests started (25 s and 18 s left); the third (11 s left) did not', count( sent() ) === 2 );
ok( 'the 10 unsent rows wait, with no try counted', count( CF_TestState::$catalog_queue ) === 10
    && [] === array_filter( CF_TestState::$catalog_queue, function ( $r ) { return '0' !== $r['attempts'] || null !== $r['token']; } ) );

echo "── the server asks for a list\n";
store();
product( 501 );
CashFlow_Catalog::enqueue( 501, 'save' );
products_ok( 1, [ 'list_wanted' => true ] );
run_job();
ok( 'list_wanted marks a list as wanted', ( CashFlow_Catalog::list_state()['wanted'] ?? false ) === true );

echo "── a successful send clears the product's parked rows\n";
store();
product( 501 );
CF_TestState::$catalog_queue[90] = [ 'id' => '90', 'product_id' => '501', 'reason' => 'save', 'token' => null, 'attempts' => '5',
    'queued_at' => '2026-09-20 00:00:00', 'claimed_at' => null, 'retry_at' => null, 'parked_at' => '2026-09-20 00:05:00', 'pending_key' => null ];
CF_TestState::$catalog_queue_next = 91;
CashFlow_Catalog::enqueue( 501, 'save' );
products_ok();
run_job();
ok( 'the parked row is gone once the product went through', CashFlow_Catalog::count_parked() === 0 && CF_TestState::$catalog_queue === [] );

echo "── a lease takeback can leave two rows for the same product and reason: sent once, done together\n";
store();
product( 501 );
// Row 90: claimed by a run that died (its lease is long expired) — token still
// set, claimed_at ancient. Row 91: a fresh 'save' queued for the SAME product
// and reason after the takeback would normally collide on pending_key, so this
// models the row exactly as the takeback would find it: both present, neither
// superseding the other, because pending_key is NULL while a row is claimed —
// only ONE of the two ever holds the NOT-NULL pending_key at a time, and here
// both are simply due to be claimed in the same batch.
CF_TestState::$catalog_queue[90] = [ 'id' => '90', 'product_id' => '501', 'reason' => 'save', 'token' => 'dead-run-token',
    'attempts' => '1', 'queued_at' => '2026-09-20 00:00:00', 'claimed_at' => '2020-01-01 00:00:00', 'retry_at' => null,
    'parked_at' => null, 'pending_key' => null ];
CF_TestState::$catalog_queue[91] = [ 'id' => '91', 'product_id' => '501', 'reason' => 'save', 'token' => null,
    'attempts' => '0', 'queued_at' => '2026-09-20 00:00:01', 'claimed_at' => null, 'retry_at' => null,
    'parked_at' => null, 'pending_key' => null ];
CF_TestState::$catalog_queue_next = 92;
products_ok();
run_job();
$s = sent();
ok( 'the product went out exactly once, not twice', count( $s ) === 1 && ids_in( $s[0] ?? null ) === [ 501 ] );
ok( 'both rows are gone — done together, not just one of them', CF_TestState::$catalog_queue === [] );

echo "── the same double row, but the send fails: released together, neither lost nor duplicated\n";
store();
product( 501 );
CF_TestState::$catalog_queue[90] = [ 'id' => '90', 'product_id' => '501', 'reason' => 'save', 'token' => 'dead-run-token',
    'attempts' => '1', 'queued_at' => '2026-09-20 00:00:00', 'claimed_at' => '2020-01-01 00:00:00', 'retry_at' => null,
    'parked_at' => null, 'pending_key' => null ];
CF_TestState::$catalog_queue[91] = [ 'id' => '91', 'product_id' => '501', 'reason' => 'save', 'token' => null,
    'attempts' => '0', 'queued_at' => '2026-09-20 00:00:01', 'claimed_at' => null, 'retry_at' => null,
    'parked_at' => null, 'pending_key' => null ];
CF_TestState::$catalog_queue_next = 92;
CF_TestState::$api_responses['/plugin/catalog/products'][] = [ 'ok' => false, 'status' => 500, 'error' => 'boom', 'data' => [ 'error' => 'catalogue_write_failed' ] ];
run_job();
ok( 'exactly one send was attempted for the one product', count( sent() ) === 1 );
// Both rows share the same pending_key ('501:save') once released, and MySQL's
// UNIQUE index on pending_key can hold only one of them — release_row's own
// fallback (done_row when release_untried affects 0 rows) deletes the loser as
// redundant, since the survivor already carries the same change forward.
ok( 'exactly one row remains for the product — not lost, not left duplicated', count( CF_TestState::$catalog_queue ) === 1 );
$left = array_values( CF_TestState::$catalog_queue )[0] ?? [ 'token' => 'unset', 'claimed_at' => 'unset', 'pending_key' => null, 'product_id' => null ];
ok( 'the surviving row is claimable again next run, with no try counted for this failure',
    null === $left['token'] && null === $left['claimed_at'] && null !== $left['pending_key']
    && '501' === $left['product_id'] );

CashFlow_Catalog::$clock = null;
summary();
