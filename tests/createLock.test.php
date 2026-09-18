<?php
/**
 * ONE RUN PER IDEMPOTENCY KEY — the create lock.
 *
 * The idempotency lookup runs before the transaction. If a run stalls past the
 * backend's 300s lease, the command is redelivered, and without a lock both
 * runs find no key and both create: two WooCommerce orders behind one CashFlow
 * row, stock reduced twice. The lock is a row in the options table taken with
 * INSERT IGNORE on its unique key, held across lookup, create and commit.
 *
 * The harness's $wpdb gives MySQL's answer to exactly the four statements the
 * lock uses; lookups see only committed orders while a transaction is open, as
 * MySQL does. A second run is started INSIDE the first one's create (from its
 * calculate_totals), which is the interleaving a stalled run produces.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-sync-pull.php';

const KEY = 'cfc_22222222-2222-2222-2222-222222222222';

function lock_cmd( string $key = KEY, string $num = '1SH-C40' ): array {
    return [
        'command_id' => 'cmd-' . $key, 'kind' => 'order.create', 'capability' => 'order.create@1',
        'idempotency_key' => $key, 'attempt' => 1,
        'order' => [
            'order_number' => $num, 'status' => 'on-hold', 'currency' => 'PKR',
            'customer' => [ 'first_name' => 'A', 'last_name' => 'B', 'phone' => '0300', 'email' => '' ],
            'address' => [ 'address_1' => 'St 1', 'city' => 'Lahore', 'country' => 'PK' ],
            'customer_note' => '', 'payment_method' => 'cod',
            'line_items' => [ [ 'product_id' => 501, 'quantity' => 1, 'subtotal' => '100', 'total' => '100' ] ],
            'shipping_lines' => [], 'fee_lines' => [], 'meta' => [],
            'money_display' => [ 'advance_amount' => '0', 'cod_amount' => '100', 'payment_status' => 'unpaid' ],
            'expected_total' => '100',
        ],
    ];
}
function lock_store(): void {
    CF_TestState::reset();
    CF_TestState::$products[501] = new WC_Product( 501 );
}
function lock_row( string $key = KEY ) { return CF_TestState::$db_options[ 'cashflow_create_lock_' . md5( $key ) ] ?? null; }
function orders_for( string $key = KEY ): int {
    return count( array_filter( CF_TestState::$orders, fn( $o ) => $o->get_meta( 'cashflow_command_key' ) === $key ) );
}

$applier = new CashFlow_Order_Applier();

echo "── two interleaved runs with one key create one order\n";
lock_store();
$second = null; $lock_during = null;
CF_TestState::$on_calculate_totals = function () use ( $applier, &$second, &$lock_during ) {
    CF_TestState::$on_calculate_totals = null;            // only once
    $lock_during = lock_row();
    $second = $applier->create( lock_cmd() );              // the redelivery, while run 1 is mid-create
};
$first = $applier->create( lock_cmd() );
ok( 'run 1 held the lock while it worked', is_string( $lock_during ) && '' !== $lock_during );
ok( 'run 2, arriving mid-create, did NOT create — it acked failed', ( $second['outcome'] ?? '' ) === 'failed'
    && ( $second['error']['code'] ?? '' ) === 'create_locked', json_encode( $second ) );
ok( 'with a message saying another run is creating it', str_contains( $second['error']['message'] ?? '', 'Another run is creating' ) );
ok( 'run 1 created', ( $first['outcome'] ?? '' ) === 'created', json_encode( $first ) );
ok( 'exactly ONE WooCommerce order exists', orders_for() === 1, orders_for() . ' orders' );
ok( 'the lock is released afterwards', null === lock_row(), json_encode( lock_row() ) );
$third = $applier->create( lock_cmd() );
ok( "the backend's retry then answers already_created", ( $third['outcome'] ?? '' ) === 'already_created' && orders_for() === 1 );

// The lock is per key: a different order is never held up by this one.
lock_store();
$other = null;
CF_TestState::$on_calculate_totals = function () use ( $applier, &$other ) {
    CF_TestState::$on_calculate_totals = null;
    $other = $applier->create( lock_cmd( 'cfc_other', '1SH-C41' ) );
};
$applier->create( lock_cmd() );
ok( 'a different key creates alongside it', ( $other['outcome'] ?? '' ) === 'created', json_encode( $other ) );

echo "── a stale lock is taken over; a fresh one is not\n";
lock_store();
$stale = ( time() - CashFlow_Order_Applier::CREATE_LOCK_STALE_AFTER - 5 ) . ':deadprocess';
CF_TestState::$db_options[ 'cashflow_create_lock_' . md5( KEY ) ] = $stale;
$r = $applier->create( lock_cmd() );
ok( 'a lock older than the expiry is taken over and the order created', ( $r['outcome'] ?? '' ) === 'created', json_encode( $r ) );
ok( 'by compare-and-swap on the stale value', (bool) array_filter( CF_TestState::$sql, fn( $q ) => str_starts_with( $q, 'UPDATE' ) ) );
ok( 'and released afterwards', null === lock_row() );

lock_store();
$fresh = ( time() - 60 ) . ':stillworking';
CF_TestState::$db_options[ 'cashflow_create_lock_' . md5( KEY ) ] = $fresh;
$r = $applier->create( lock_cmd() );
ok( 'a lock inside the expiry is NOT taken: failed, nothing created', ( $r['outcome'] ?? '' ) === 'failed'
    && ( $r['error']['code'] ?? '' ) === 'create_locked' && orders_for() === 0, json_encode( $r ) );
ok( "and the other run's lock is left exactly as it was", lock_row() === $fresh, json_encode( lock_row() ) );
ok( 'the expiry outlives the backend lease (300s) by a wide margin', CashFlow_Order_Applier::CREATE_LOCK_STALE_AFTER >= 600 );

echo "── the lock is always released\n";
lock_store();
CF_TestState::$throw_on_calculate_totals = new RuntimeException( 'deadlock found' );
$r = $applier->create( lock_cmd() );
ok( 'an exception mid-create reports failed', ( $r['outcome'] ?? '' ) === 'failed' );
ok( 'and releases the lock', null === lock_row(), json_encode( lock_row() ) );

lock_store();
CF_TestState::$throw_on_get_orders = new RuntimeException( 'MySQL server has gone away' );
$threw = false;
try { $applier->create( lock_cmd() ); } catch ( Throwable $e ) { $threw = true; }
ok( 'an exception that escapes create() entirely (the lookup) still releases the lock',
    $threw && null === lock_row(), 'threw=' . json_encode( $threw ) . ' lock=' . json_encode( lock_row() ) );

lock_store();
$bad = lock_cmd(); $bad['order']['line_items'][0]['product_id'] = 999;
$r = $applier->create( $bad );
ok( 'a refusal releases the lock', ( $r['outcome'] ?? '' ) === 'rejected' && null === lock_row() );

echo "── a run whose lock was taken over does not commit\n";
lock_store();
$taker = ( time() ) . ':takeover';
CF_TestState::$on_calculate_totals = function () use ( $taker ) {
    CF_TestState::$on_calculate_totals = null;
    CF_TestState::$db_options[ 'cashflow_create_lock_' . md5( KEY ) ] = $taker;   // another run took it over
};
$r = $applier->create( lock_cmd() );
ok( 'it rolls back and reports failed', ( $r['outcome'] ?? '' ) === 'failed' && ( $r['error']['code'] ?? '' ) === 'create_lock_lost', json_encode( $r ) );
ok( 'leaving no order', orders_for() === 0, orders_for() . ' orders' );
ok( "and it does NOT delete the other run's lock", lock_row() === $taker, json_encode( lock_row() ) );

summary();
