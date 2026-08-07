<?php
/**
 * THE ORDER STATUS MUST SURVIVE THE WHOLE PLUGIN PATH.
 *
 * Live defect, 2026-08-07: an operator confirming an order in CashFlow saw it
 * flip back to on-hold within a minute. `set_status` appeared NOWHERE in the
 * plugin, and apply_job built its request body from `ops` without ever reading
 * a status — so WooCommerce saved the edit with its status untouched, the ack
 * returned that stale status, and the backend wrote it back over the
 * operator's own decision. Every job acked `applied`. 40 reverts / 35 orders.
 *
 * These tests execute the REAL CashFlow_Sync_Pull::apply_job and the REAL
 * CashFlow_Order_Applier. The harness's controller stub reproduces the thing
 * that makes this bug possible: WooCommerce's prepare_object_for_database
 * DELIBERATELY skips status ("Status change should be done later so
 * transitions have new data") and core applies it in save_object(), which the
 * applier bypasses. If the stub applied status, these tests could not fail the
 * real way — the exact trap that let a missing create() ship 20/20 green.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-sync-pull.php';

/** apply_job is private by design; the behaviour under test is not. */
function apply_job( array $job ) {
    $m = new ReflectionMethod( CashFlow_Sync_Pull::class, 'apply_job' );
    return $m->invoke( new CashFlow_Sync_Pull(), $job );
}

function seed_order( int $id, string $status ): WC_Order {
    $o = new WC_Order( $id );
    $o->status = $status;
    CF_TestState::$orders[ $id ] = $o;
    return $o;
}

echo "── status reaches the WooCommerce order\n";

// ── 1. THE BUG ────────────────────────────────────────────────────────────
CF_TestState::reset();
$order  = seed_order( 33047, 'on-hold' );
$result = apply_job( [ 'external_id' => 33047, 'sync_key' => 'k1', 'ops' => [ 'status' => 'processing' ] ] );

ok( 'the job applies', ( $result['outcome'] ?? '' ) === 'applied',
    'outcome was ' . json_encode( $result['outcome'] ?? null ) . ' — ' . ( $result['error'] ?? '' ) );
ok( 'the order in WooCommerce is now processing', $order->get_status() === 'processing',
    'still ' . $order->get_status() );
ok( 'set_status was actually called', in_array( 'set_status', $order->calls, true ) );
ok( 'the status was set BEFORE the save — a status set after save never persists',
    array_search( 'set_status', $order->calls, true ) < array_search( 'save', $order->calls, true ) );

// The ack payload is what the backend writes onto the CashFlow row. If it
// carried the stale status, the revert would happen anyway — one door over.
ok( 'the ack reports the NEW status back to CashFlow',
    ( $result['order']['status'] ?? null ) === 'processing',
    'ack said ' . json_encode( $result['order']['status'] ?? null ) );

// ── 2. THE TRI-STATE ──────────────────────────────────────────────────────
// An edit that asserts nothing about status must not be able to change one.
// This is the shipping-deletion lesson of 2026-07-22: absent ≠ empty ≠ set.
CF_TestState::reset();
$untouched = seed_order( 33048, 'completed' );
$r2 = apply_job( [ 'external_id' => 33048, 'sync_key' => 'k2',
                   'ops' => [ 'lineItems' => [] ] ] );
ok( 'an ops with no status applies', ( $r2['outcome'] ?? '' ) === 'applied', $r2['error'] ?? '' );
ok( 'an edit that asserts no status leaves it alone', $untouched->get_status() === 'completed',
    'became ' . $untouched->get_status() );
ok( 'and set_status is never called', ! in_array( 'set_status', $untouched->calls, true ) );

// ── 3. A BLANK STATUS IS NOT AN ASSERTION ─────────────────────────────────
// The backend already refuses to send one; the two sides must agree rather
// than one relying on the other staying careful.
CF_TestState::reset();
$blank = seed_order( 33049, 'on-hold' );
apply_job( [ 'external_id' => 33049, 'sync_key' => 'k3', 'ops' => [ 'status' => '' ] ] );
ok( 'a blank status changes nothing', $blank->get_status() === 'on-hold' );

// ── 4. CASHFLOW'S OWN VOCABULARY ──────────────────────────────────────────
// booked/shipped/returned are registered in WooCommerce by class-statuses.php
// and ride verbatim, exactly as the push path's WOO_STATUS_MAP passes them.
foreach ( [ 'booked', 'shipped', 'returned', 'cancelled' ] as $i => $s ) {
    CF_TestState::reset();
    $o = seed_order( 34000 + $i, 'processing' );
    apply_job( [ 'external_id' => 34000 + $i, 'sync_key' => "k-$s", 'ops' => [ 'status' => $s ] ] );
    ok( "CashFlow status '$s' rides verbatim", $o->get_status() === $s, 'got ' . $o->get_status() );
}

// ── 5. IDEMPOTENCE IS NOT DEFEATED BY THE NEW FIELD ───────────────────────
// A redelivered job must still touch nothing — including the status.
CF_TestState::reset();
$already = seed_order( 33050, 'on-hold' );
$already->update_meta_data( CashFlow_Sync_Pull::SYNC_KEY_META, 'k5' );
$r5 = apply_job( [ 'external_id' => 33050, 'sync_key' => 'k5', 'ops' => [ 'status' => 'processing' ] ] );
ok( 'a redelivered job reports already_applied', ( $r5['outcome'] ?? '' ) === 'already_applied' );
ok( 'and does NOT re-apply the status', $already->get_status() === 'on-hold',
    'became ' . $already->get_status() );

summary();
