<?php
/**
 * woo-cashflow/tests/addressApply.test.php
 *
 * THE ADDRESS EDIT REACHES WOOCOMMERCE BY PULL.
 *
 * 🔴 THE BUG THIS CLOSES. CashFlow's operators retype unusable addresses so a
 * courier can find the house — "Hfiz.Jamll.matro.asteshan" becomes "Hafiz Jamal
 * Metro Station", Urdu is romanised, a district is spelled out. That edit was
 * sent by DIRECT PUSH from CashFlow's server to the store, which is the one hop
 * the host's bot-wall poisons: SiteGround answers 202 and a CAPTCHA page. 12
 * refusals were logged in a single morning, and until CashFlow took ownership of
 * the order (backend v1.67.0) the next webhook wrote the customer's raw text
 * back over the operator's work — 619 fields across 224 orders. 1SH-33042
 * shipped to Peshawar instead of Hangu because of exactly this.
 *
 * The queue was already the working transport for every other kind of edit. The
 * address simply had no way onto it: apply_job built its request body from `ops`
 * and mapped six keys — lineItems, status, paymentMethod, shippingLines,
 * feeLines, couponLines — and no address.
 *
 * NOTHING IN THE APPLIER NEEDED CHANGING, which is the useful part: billing and
 * shipping go through WooCommerce's own prepare_object_for_database, which DOES
 * apply them. Status is the single field that method skips by design (core sets
 * it in save_object), which is why status needed a special case and this does
 * not. The applier already gates calculate_totals on isset($request['billing'])
 * — core's own rule, because an address can move a tax or shipping zone.
 *
 * These tests execute the REAL CashFlow_Sync_Pull::apply_job and the REAL
 * CashFlow_Order_Applier against the WP/WC stubs, and assert on the REQUEST BODY
 * apply_job builds — which is precisely the contract that was missing. The stub
 * records it in CF_TestState::$created (named for the create path it was written
 * for; prepare_object_for_database records on both paths).
 *
 * ⚠️ The stub does NOT itself apply billing to the order — it models the surface
 * the plugin CALLS, not WooCommerce. So these prove the plugin now ASKS
 * WooCommerce to set the address; that core honours a `billing` param in
 * prepare_object_for_database is WooCommerce's own documented behaviour and the
 * reason status needed a special case while this does not. The live store is
 * still the final gate.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-sync-pull.php';

function addr_apply_job( array $job ) {
    $m = new ReflectionMethod( CashFlow_Sync_Pull::class, 'apply_job' );
    return $m->invoke( new CashFlow_Sync_Pull(), $job );
}

function addr_seed_order( int $id ): WC_Order {
    $o = new WC_Order( $id );
    $o->status = 'on-hold';
    CF_TestState::$orders[ $id ] = $o;
    return $o;
}

echo "── the address reaches the WooCommerce order\n";

// ── 1. THE BUG: a billing address in ops must reach the order ──────────────
CF_TestState::reset();
addr_seed_order( 33466 );
$r = addr_apply_job( [
    'external_id' => 33466,
    'sync_key'    => 'a1',
    'ops'         => [
        'billing' => [
            'address_1' => 'Mandrajoriyan Stop, Chakwal Road, Madrasa Mandra',
            'city'      => 'Gujar Khan',
        ],
    ],
] );

ok( 'the job applies', ( $r['outcome'] ?? '' ) === 'applied',
    'outcome was ' . json_encode( $r['outcome'] ?? null ) . ' — ' . ( $r['error'] ?? '' ) );

$sent = CF_TestState::$created[0] ?? [];   // the stub records every request body here
ok( 'billing was carried into the request body',
    isset( $sent['billing'] ) && is_array( $sent['billing'] ),
    'body carried: ' . json_encode( array_keys( $sent ) ) );
ok( 'the operator\'s romanised street is the value sent',
    ( $sent['billing']['address_1'] ?? '' ) === 'Mandrajoriyan Stop, Chakwal Road, Madrasa Mandra',
    json_encode( $sent['billing'] ?? null ) );
ok( 'the corrected city rides with it',
    ( $sent['billing']['city'] ?? '' ) === 'Gujar Khan' );

// ── 2. shipping is carried independently of billing ───────────────────────
CF_TestState::reset();
addr_seed_order( 33470 );
addr_apply_job( [
    'external_id' => 33470,
    'sync_key'    => 'a2',
    'ops'         => [ 'shipping' => [ 'address_1' => 'Madrasa Asad Dar-ul-Uloom', 'city' => 'lower dir' ] ],
] );
$sent = CF_TestState::$created[0] ?? [];   // the stub records every request body here
ok( 'shipping alone is carried', ( $sent['shipping']['city'] ?? '' ) === 'lower dir' );
ok( 'and billing is NOT invented when the edit did not assert one',
    ! array_key_exists( 'billing', $sent ),
    'body carried: ' . json_encode( array_keys( $sent ) ) );

// ── 3. ABSENT MEANS DO NOT TOUCH — the contract, not a nicety ─────────────
// The whole ops contract reads an absent key as silence and a present one as an
// assertion. An address is a PARTIAL object by nature (a merchant corrects
// address_1 and nothing else), so if an edit that never mentioned the address
// sent `billing => []`, WooCommerce would be handed an empty address to merge
// and the merchant's own street could be blanked by a status change.
CF_TestState::reset();
addr_seed_order( 33488 );
addr_apply_job( [ 'external_id' => 33488, 'sync_key' => 'a3', 'ops' => [ 'status' => 'processing' ] ] );
$sent = CF_TestState::$created[0] ?? [];   // the stub records every request body here
ok( 'a status-only job carries NO billing key at all',
    ! array_key_exists( 'billing', $sent ),
    'body carried: ' . json_encode( array_keys( $sent ) ) );
ok( 'and no shipping key either',
    ! array_key_exists( 'shipping', $sent ) );

// ── 4. a non-array address is refused rather than passed through ──────────
// Defensive: `is_array` guards the same way the line categories do. A string
// where WooCommerce expects an object would reach prepare_object_for_database
// and fail deep inside core, with the job acked against a body nobody can read.
CF_TestState::reset();
addr_seed_order( 33489 );
addr_apply_job( [ 'external_id' => 33489, 'sync_key' => 'a4', 'ops' => [ 'billing' => 'Karachi' ] ] );
$sent = CF_TestState::$created[0] ?? [];   // the stub records every request body here
ok( 'a malformed (non-array) billing is not forwarded',
    ! array_key_exists( 'billing', $sent ),
    'body carried: ' . json_encode( array_keys( $sent ) ) );

// ── 5. an address rides TOGETHER with a status on one save ────────────────
// The operator confirms the order and fixes the address in the same sitting.
// Both must land on the same save, or one of them is a second round-trip that
// can be interrupted.
CF_TestState::reset();
addr_seed_order( 33490 );
$r5 = addr_apply_job( [
    'external_id' => 33490,
    'sync_key'    => 'a5',
    'ops'         => [ 'status' => 'processing', 'billing' => [ 'address_1' => 'Hafiz Jamal Metro Station' ] ],
] );
$sent = CF_TestState::$created[0] ?? [];   // the stub records every request body here
ok( 'the status and the address arrive in ONE body',
    ( $sent['status'] ?? '' ) === 'processing'
    && ( $sent['billing']['address_1'] ?? '' ) === 'Hafiz Jamal Metro Station',
    json_encode( $sent ) );
ok( 'and the job reports applied', ( $r5['outcome'] ?? '' ) === 'applied', $r5['error'] ?? '' );

summary();
