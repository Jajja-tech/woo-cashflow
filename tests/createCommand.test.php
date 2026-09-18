<?php
/**
 * ORDER CREATE (command order.create@1) — CashFlow_Order_Applier::create().
 *
 * CashFlow saves the order and mints its number before this runs. The plugin
 * only makes the WooCommerce copy — once — and reports honestly. What these
 * tests pin, each proven by breaking the code first:
 *   - the REAL applier runs (not a stand-in — a replacement class once hid a
 *     create() that did not exist at all);
 *   - one idempotency key makes one order, however often it is delivered;
 *   - the status is the one asked for, not WooCommerce's default `pending`;
 *   - the key and every meta value ride the save that makes the order, and a
 *     failure half-way leaves no order behind;
 *   - anything outside @1 is refused, not half-honoured;
 *   - a product the store does not have is a refusal, not a retry.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-sync-pull.php';

function a_command( array $over = [], array $order_over = [] ): array {
    $order = array_merge( [
        'order_number'  => '1SH-C12',
        'status'        => 'on-hold',
        'currency'      => 'PKR',
        'customer'      => [ 'first_name' => 'Ali', 'last_name' => 'Khan', 'phone' => '03001234567', 'email' => '' ],
        'address'       => [ 'address_1' => 'Hafiz Jamal Metro Station', 'address_2' => '', 'city' => 'Gujar Khan',
                             'state' => '', 'postcode' => '', 'country' => 'PK' ],
        'customer_note' => 'call before delivery',
        'payment_method'=> 'cod',
        'line_items'    => [ [ 'product_id' => 501, 'variation_id' => 0, 'quantity' => 2, 'subtotal' => '2500', 'total' => '2500' ] ],
        'shipping_lines'=> [ [ 'method_id' => 'flat_rate', 'method_title' => 'Flat rate', 'total' => '299' ] ],
        'fee_lines'     => [ [ 'name' => 'Discount', 'total' => '-125' ] ],
        'meta'          => [ '_wc_order_attribution_source_type' => 'utm', '_wc_order_attribution_utm_source' => 'facebook',
                             'cashflow_courier_name' => 'PostEx' ],
        'money_display' => [ 'advance_amount' => '500', 'cod_amount' => '2174', 'payment_status' => 'partial' ],
        'expected_total'=> '2674',
    ], $order_over );
    return array_merge( [
        'command_id'      => 'cmd-1',
        'kind'            => 'order.create',
        'capability'      => 'order.create@1',
        'idempotency_key' => 'cfc_11111111-1111-1111-1111-111111111111',
        'attempt'         => 1,
        'order'           => $order,
    ], $over );
}

function fresh_store(): void {
    CF_TestState::reset();
    CF_TestState::$products[501] = new WC_Product( 501 );
    CF_TestState::$products[601] = new WC_Product( 601 );
    CF_TestState::$products[602] = new WC_Product( 602, 601 );   // a variation of 601
}

function created_orders(): array {
    return array_values( array_filter( CF_TestState::$orders,
        fn( $o ) => '' !== (string) $o->get_meta( 'cashflow_command_key' ) ) );
}

$applier = new CashFlow_Order_Applier();

echo "── the real applier is the one under test\n";
$rm = new ReflectionMethod( CashFlow_Order_Applier::class, 'create' );
ok( 'create() is declared in includes/class-order-applier.php, not in the harness',
    realpath( $rm->getFileName() ) === realpath( __DIR__ . '/../includes/class-order-applier.php' ),
    'declared in ' . $rm->getFileName() );
ok( 'and the real applier extends the harness controller (only WooCommerce is stubbed)',
    get_parent_class( $applier ) === 'WC_REST_Orders_Controller' );

// ── 1. ONE KEY, ONE ORDER ─────────────────────────────────────────────────
echo "── one idempotency key creates one order\n";
fresh_store();
$r1 = $applier->create( a_command() );
$r2 = $applier->create( a_command( [ 'command_id' => 'cmd-1', 'attempt' => 2 ] ) );
ok( 'the first delivery creates', ( $r1['outcome'] ?? '' ) === 'created', json_encode( $r1 ) );
ok( 'the redelivery reports already_created', ( $r2['outcome'] ?? '' ) === 'already_created', json_encode( $r2 ) );
ok( 'exactly ONE WooCommerce order exists', count( created_orders() ) === 1, count( created_orders() ) . ' orders' );
ok( 'both acks describe the same order', ( $r1['order']['id'] ?? 1 ) === ( $r2['order']['id'] ?? 2 ) );
ok( 'the ack shows the CashFlow number', ( $r1['order']['number'] ?? '' ) === '1SH-C12', json_encode( $r1['order'] ?? null ) );

// A trashed order must still block a second create: 'any' excludes trash.
fresh_store();
$applier->create( a_command() );
created_orders()[0]->status = 'trash';
$r3 = $applier->create( a_command() );
ok( 'an order trashed before the ack still counts — no second create',
    ( $r3['outcome'] ?? '' ) === 'already_created' && count( created_orders() ) === 1,
    json_encode( $r3['outcome'] ?? null ) . ', ' . count( created_orders() ) . ' orders' );

// Same number, different key: never two WooCommerce orders behind one number.
fresh_store();
$applier->create( a_command() );
$r4 = $applier->create( a_command( [ 'idempotency_key' => 'cfc_other' ] ) );
ok( 'a second command for the same number is refused, not created',
    ( $r4['outcome'] ?? '' ) === 'rejected' && ( $r4['error']['code'] ?? '' ) === 'order_number_in_use'
    && count( created_orders() ) === 1, json_encode( $r4 ) );

// ── 2. STATUS ─────────────────────────────────────────────────────────────
echo "── the status is set, not left pending\n";
fresh_store();
$r = $applier->create( a_command() );
$o = created_orders()[0] ?? null;
ok( 'the order is on-hold, as asked', $o && $o->get_status() === 'on-hold', $o ? $o->get_status() : 'no order' );
ok( 'and the ack says so', ( $r['order']['status'] ?? '' ) === 'on-hold' );
$calls = $o ? $o->calls : [];
$last_save = $o ? array_keys( $calls, 'save', true ) : [];
ok( 'set_status ran BEFORE the final save',
    $o && array_search( 'set_status', $calls, true ) < end( $last_save ), json_encode( $calls ) );
ok( 'the final save persisted on-hold', $o && end( $o->saves )['status'] === 'on-hold' );

fresh_store();
$ri = $applier->create( a_command( [], [ 'status' => 'wobbly' ] ) );
ok( 'an unregistered status is refused (core would silently make it pending)',
    ( $ri['outcome'] ?? '' ) === 'rejected' && ( $ri['error']['code'] ?? '' ) === 'invalid_status'
    && count( created_orders() ) === 0, json_encode( $ri ) );

// ── 3. META IN THE SAME SAVE; ROLLBACK LEAVES NOTHING ──────────────────────
echo "── the key and every meta value ride the create save\n";
fresh_store();
$applier->create( a_command() );
$o = created_orders()[0];
$want = [
    'cashflow_order_number'   => '1SH-C12',
    'cashflow_command_key'    => 'cfc_11111111-1111-1111-1111-111111111111',
    'cashflow_advance_amount' => '500',
    'cashflow_cod_amount'     => '2174',
    'cashflow_payment_status' => 'partial',
    '_wc_order_attribution_source_type' => 'utm',
    '_wc_order_attribution_utm_source'  => 'facebook',
    'cashflow_courier_name'   => 'PostEx',
];
$first = $o->saves[0]['meta'] ?? [];
$final = end( $o->saves )['meta'] ?? [];
$missing_first = array_keys( array_diff_assoc( $want, $first ) );
$missing_final = array_keys( array_diff_assoc( $want, $final ) );
ok( 'every meta value is on the order at its FIRST save', ! $missing_first, 'missing: ' . json_encode( $missing_first ) );
ok( 'and at its final save', ! $missing_final, 'missing: ' . json_encode( $missing_final ) );
ok( 'created_via is cashflow', $o->get_created_via() === 'cashflow' );
ok( 'the whole create ran inside one transaction that committed',
    CF_TestState::$tx === [ 'start', 'commit' ], json_encode( CF_TestState::$tx ) );
ok( 'payment_complete (set_paid) is never called', ! in_array( 'payment_complete', $o->calls, true ) );

fresh_store();
CF_TestState::$throw_on_calculate_totals = new RuntimeException( 'database went away' );
$rf = $applier->create( a_command() );
ok( 'a crash half-way reports failed', ( $rf['outcome'] ?? '' ) === 'failed', json_encode( $rf ) );
ok( 'and names why', str_contains( $rf['error']['message'] ?? '', 'database went away' ) );
ok( 'the transaction was rolled back', CF_TestState::$tx === [ 'start', 'rollback' ], json_encode( CF_TestState::$tx ) );
ok( 'NO order survives the rollback (not even the first save)', count( CF_TestState::$orders ) === 0,
    count( CF_TestState::$orders ) . ' orders left' );
CF_TestState::$throw_on_calculate_totals = null;
$rr = $applier->create( a_command() );
ok( 'so the retry creates it cleanly, once', ( $rr['outcome'] ?? '' ) === 'created' && count( created_orders() ) === 1 );

// ── 4. THE REQUEST WOOCOMMERCE RECEIVES ────────────────────────────────────
echo "── the body handed to WooCommerce\n";
fresh_store();
$applier->create( a_command() );
$b = CF_TestState::$created[0] ?? [];
ok( 'the ONE address is written to billing', ( $b['billing']['address_1'] ?? '' ) === 'Hafiz Jamal Metro Station' );
ok( 'and to shipping', ( $b['shipping']['address_1'] ?? '' ) === 'Hafiz Jamal Metro Station'
    && ( $b['shipping']['city'] ?? '' ) === 'Gujar Khan' );
ok( 'a guest order: customer_id 0', array_key_exists( 'customer_id', $b ) && 0 === $b['customer_id'] );
ok( 'a blank email is left out, not sent', ! array_key_exists( 'email', $b['billing'] ?? [] ) );
ok( 'payment method and a title ride', ( $b['payment_method'] ?? '' ) === 'cod' && ( $b['payment_method_title'] ?? '' ) !== '' );
ok( 'set_paid is never sent', ! array_key_exists( 'set_paid', $b ) );
ok( 'status is not in the prepare body (core ignores it there; we set it ourselves)', ! array_key_exists( 'status', $b ) );
ok( "CashFlow's line price rides", ( $b['line_items'][0]['total'] ?? '' ) === '2500' );
ok( 'the discount rides as a signed fee line', ( $b['fee_lines'][0]['total'] ?? '' ) === '-125' );
ok( 'no coupon lines at all', ! array_key_exists( 'coupon_lines', $b ) );

// ── 5. OUTSIDE @1 IS REFUSED ─────────────────────────────────────────────
echo "── anything outside order.create@1 is refused\n";
$cases = [
    'an unknown order key'            => a_command( [], [ 'gift_wrap' => true ] ),
    'coupon_codes (not in @1)'        => a_command( [], [ 'coupon_codes' => [ 'EID' ] ] ),
    'an unknown line key'             => a_command( [], [ 'line_items' => [ [ 'product_id' => 501, 'quantity' => 1, 'sku' => 'X' ] ] ] ),
    'an unknown customer key'         => a_command( [], [ 'customer' => [ 'first_name' => 'A', 'cnic' => '1' ] ] ),
    'an unknown top-level key'        => a_command( [ 'priority' => 'high' ] ),
    'a reserved meta key in the map'  => a_command( [], [ 'meta' => [ 'cashflow_command_key' => 'hijack' ] ] ),
];
foreach ( $cases as $what => $cmd ) {
    fresh_store();
    $r = $applier->create( $cmd );
    ok( "$what → rejected unsupported_field, no order",
        ( $r['outcome'] ?? '' ) === 'rejected' && ( $r['error']['code'] ?? '' ) === 'unsupported_field'
        && count( CF_TestState::$orders ) === 0, json_encode( $r ) );
}

// ── 6. A PRODUCT THE STORE DOES NOT HAVE ──────────────────────────────────
echo "── an invalid product is rejected, not failed\n";
fresh_store();
$rp = $applier->create( a_command( [], [ 'line_items' => [ [ 'product_id' => 999, 'quantity' => 1, 'total' => '100', 'subtotal' => '100' ] ] ] ) );
ok( 'a product id WooCommerce does not know is REJECTED', ( $rp['outcome'] ?? '' ) === 'rejected'
    && ( $rp['error']['code'] ?? '' ) === 'invalid_product', json_encode( $rp ) );
ok( 'the reason names the product', str_contains( $rp['error']['message'] ?? '', '999' ) );
ok( 'and no order was made', count( CF_TestState::$orders ) === 0 );

fresh_store();
CF_TestState::$products[501] = new WC_Product( 501, 0, 'trash' );
$rt = $applier->create( a_command() );
ok( 'a trashed product is rejected too', ( $rt['error']['code'] ?? '' ) === 'invalid_product' );

fresh_store();
$rv = $applier->create( a_command( [], [ 'line_items' => [ [ 'product_id' => 501, 'variation_id' => 602, 'quantity' => 1 ] ] ] ) );
ok( 'a variation of a DIFFERENT product is rejected', ( $rv['error']['code'] ?? '' ) === 'invalid_product', json_encode( $rv ) );
fresh_store();
$rok = $applier->create( a_command( [], [ 'line_items' => [ [ 'product_id' => 601, 'variation_id' => 602, 'quantity' => 1 ] ] ] ) );
ok( 'its own variation is accepted', ( $rok['outcome'] ?? '' ) === 'created', json_encode( $rok ) );

// WooCommerce's own validation (a WC_Data_Exception) is a refusal with its words.
fresh_store();
$rw = $applier->create( a_command( [], [ 'currency' => 'XYZ' ] ) );
ok( "WooCommerce's own validation error is REJECTED with its own code, not failed",
    ( $rw['outcome'] ?? '' ) === 'rejected' && ( $rw['error']['code'] ?? '' ) === 'order_invalid_currency', json_encode( $rw ) );
ok( 'with its own message', ( $rw['error']['message'] ?? '' ) === 'Invalid currency code' );
ok( 'and the transaction it opened is rolled back, leaving nothing',
    CF_TestState::$tx === [ 'start', 'rollback' ] && count( CF_TestState::$orders ) === 0, json_encode( CF_TestState::$tx ) );

summary();
