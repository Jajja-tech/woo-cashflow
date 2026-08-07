<?php
/**
 * The create path, EXECUTED.
 *
 * Until 2026-08-07 this plugin had never been run on this machine — there was
 * no PHP installed — so every claim about it was static review. These assertions
 * run the real apply_create() against stubs that record what it asked for.
 *
 * Run: php tests/createPath.test.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-sync-pull.php';

/** apply_create is private by design; reflection runs the REAL method. */
function apply_create( array $job ) {
    $m = new ReflectionMethod( 'CashFlow_Sync_Pull', 'apply_create' );
    $r = new ReflectionClass( 'CashFlow_Sync_Pull' );
    return $m->invoke( $r->newInstanceWithoutConstructor(), $job, $job['sync_key'] );
}

function apply_job( array $job ) {
    $m = new ReflectionMethod( 'CashFlow_Sync_Pull', 'apply_job' );
    $r = new ReflectionClass( 'CashFlow_Sync_Pull' );
    return $m->invoke( $r->newInstanceWithoutConstructor(), $job );
}

$JOB = [
    'job_id'      => 'j1',
    'kind'        => 'create',
    'sync_key'    => 'cf_abc123',
    'external_id' => null,
    'intent'      => [
        'status'       => 'processing',
        'order_number' => '1SH-C1042',
        'customer'     => [ 'first_name' => 'M Hamza', 'phone' => '0327', 'address_1' => 'Old road', 'city' => 'MULTAN' ],
        'note'         => 'Leave at the gate',
    ],
    'ops' => [
        'lineItems'     => [ [ 'product_id' => 16964, 'quantity' => 2, 'total' => '1058' ] ],
        'shippingLines' => [ [ 'method_id' => 'flat_rate', 'method_title' => 'Flat', 'total' => '200' ] ],
        'paymentMethod' => 'cod',
    ],
];

echo "\napply_create — the payload handed to WooCommerce\n";
CF_TestState::reset();
$res = apply_create( $JOB );
$body = CF_TestState::$created[0] ?? [];
$meta = [];
foreach ( $body['meta_data'] ?? [] as $m ) { $meta[ $m['key'] ] = $m['value']; }

ok( 'the outcome is applied', ( $res['outcome'] ?? '' ) === 'applied', json_encode( $res ) );
ok( 'exactly ONE create call was made', count( CF_TestState::$created ) === 1 );
ok( 'the sync key rides the SAME call as the create',
    ( $meta['cashflow_sync_key'] ?? null ) === 'cf_abc123',
    'a crash between create and stamp would let the retry create a SECOND order' );
ok( "CashFlow's order number rides it too",
    ( $meta['cashflow_order_number'] ?? null ) === '1SH-C1042',
    'without it the ack renames the order at the moment it is confirmed' );
ok( 'the line items are passed through', ( $body['line_items'][0]['product_id'] ?? null ) === 16964 );
ok( 'shipping lines are passed through', ( $body['shipping_lines'][0]['method_id'] ?? null ) === 'flat_rate' );
ok( 'the customer becomes both address slots',
    ( $body['billing']['city'] ?? null ) === 'MULTAN' && ( $body['shipping']['city'] ?? null ) === 'MULTAN' );
ok( 'the status comes from the intent', ( $body['status'] ?? null ) === 'processing' );

// 🔴 AND IT MUST REACH THE ORDER, NOT JUST THE REQUEST BODY (H1, sixth pass).
// WooCommerce's REST controller SKIPS status in prepare_object_for_database
// deliberately — "Status change should be done later so transitions have new
// data" — and applies it in save_object(), which this applier bypasses. So the
// order was created at WooCommerce's default `pending`; the ack then mapped
// that back and flipped CashFlow's own row to Pending too. On a store with
// hold-stock configured, woocommerce_cancel_unpaid_orders CANCELS a pending
// order within the hour, and `pending` does not reduce stock while `processing`
// does. Asserting the BODY alone could never see this.
$createdOrder = CF_TestState::$orders[ array_key_last( CF_TestState::$orders ) ] ?? null;
ok( 'the status reaches the ORDER, not just the request body',
    $createdOrder && $createdOrder->get_status() === 'processing',
    'got ' . ( $createdOrder ? $createdOrder->get_status() : 'no order' ) );
ok( 'and the ack reports that status back to CashFlow',
    ( $res['order']['status'] ?? null ) === 'processing' );
// N4: the retired createOrderFromWoo sent this as customer_note and the port
// dropped it, so a note typed into CashFlow never reached the person packing
// the parcel — while CashFlow's create modal still claimed it did.
ok( 'the delivery note reaches the store',
    ( $body['customer_note'] ?? null ) === 'Leave at the gate' );

CF_TestState::reset();
$noNote = $JOB;
unset( $noNote['intent']['note'] );
apply_create( $noNote );
ok( 'an absent note sets no customer_note at all',
    ! array_key_exists( 'customer_note', CF_TestState::$created[0] ?? [] ) );
CF_TestState::reset();
apply_create( $JOB );
$body = CF_TestState::$created[0] ?? [];

echo "\nthe display cache WooCommerce renders from\n";
CF_TestState::reset();
$withMeta = $JOB;
// The UNDERSCORED key is the one every plugin reader uses, and the value is
// the plugin's own <select> option — the backend stopped sending the slug under
// the bare key on 2026-08-07 (B4).
$withMeta['intent']['meta'] = [ 'cashflow_advance_amount' => '300', '_cashflow_courier_name' => 'PostEx' ];
apply_create( $withMeta );
$m = [];
foreach ( CF_TestState::$created[0]['meta_data'] ?? [] as $e ) { $m[ $e['key'] ] = $e['value']; }
ok( 'the advance display cache is written', ( $m['cashflow_advance_amount'] ?? null ) === '300',
    'without it a CashFlow-created order reads as fully unpaid in WP admin' );
ok( 'the courier name is written under the key the plugin reads',
    ( $m['_cashflow_courier_name'] ?? null ) === 'PostEx' );
ok( 'and the sync key is still there beside it', ( $m['cashflow_sync_key'] ?? null ) === 'cf_abc123' );

echo "\nidempotence — a redelivered job must not create a second order\n";
$res2 = apply_create( $JOB );   // same sync key, order now exists
ok( 'the second attempt reports already_applied', ( $res2['outcome'] ?? '' ) === 'already_applied', json_encode( $res2 ) );
ok( 'and made NO second create call', count( CF_TestState::$created ) === 1,
    'created ' . count( CF_TestState::$created ) . ' orders — a lost ack would duplicate the merchant\'s order' );
ok( 'it acks the order it found', ( $res2['order']['id'] ?? null ) === 1000 );

echo "\nrouting — apply_job sends a create down the create path\n";
CF_TestState::reset();
$res3 = apply_job( $JOB );
ok( 'a create job with a NULL external_id is applied, not refused',
    ( $res3['outcome'] ?? '' ) === 'applied',
    'apply_job requires external_id > 0 for an edit; a create must branch before that' );

echo "\nrefusals\n";
CF_TestState::reset();
$bad = $JOB; $bad['sync_key'] = '';
$res4 = apply_job( $bad );
ok( 'a create with no sync key is refused', ( $res4['outcome'] ?? '' ) === 'failed' );
ok( 'and nothing was created', count( CF_TestState::$created ) === 0 );

CF_TestState::reset();
$edit = [ 'job_id' => 'j2', 'kind' => 'edit', 'sync_key' => 'cf_k', 'external_id' => 0, 'intent' => [], 'ops' => [] ];
$res5 = apply_job( $edit );
ok( 'an EDIT with no external_id is still refused', ( $res5['outcome'] ?? '' ) === 'failed', json_encode( $res5 ) );

summary();
