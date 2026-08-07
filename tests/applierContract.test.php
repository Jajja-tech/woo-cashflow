<?php
/**
 * THE APPLIER CONTRACT — every method the pull path calls must EXIST.
 *
 * 🔴 WHY THIS FILE EXISTS. class-sync-pull.php called $applier->create() for
 * the entire life of the create feature, and CashFlow_Order_Applier declared
 * only apply() and serialize(). Every create in production would have thrown
 * "Call to undefined method", acked `failed`, burnt six attempts and parked
 * red — the merchant's order would have stayed in CashFlow forever.
 *
 * The suite was GREEN throughout, and that is the part worth fixing. The
 * behavioural harness (tests/bootstrap.php) declares a standalone class NAMED
 * CashFlow_Order_Applier — not a subclass — and get_applier() only requires the
 * real file `if ( ! class_exists( ... ) )`. So under test the real applier is
 * never loaded, and the double was strictly MORE capable than production. Same
 * family as the three doubles in the backend that could not fail the real way.
 *
 * WHAT THIS PROVES, AND WHAT IT DOES NOT. It reads the SOURCE: every method
 * invoked on the applier is declared on the real class. That is exactly the
 * hole that shipped, and it costs nothing to keep closed.
 *
 * It does NOT prove the method WORKS — that needs WooCommerce, and the real
 * applier extends WC_REST_Orders_Controller, so exercising it means a live
 * store. Say that distinction out loud rather than reaching for a blanket
 * disclaimer: the create path's LOGIC is covered by createPath.test.php
 * against the double; its WooCommerce integration is not covered by anything
 * here.
 */

require_once __DIR__ . '/bootstrap.php';

$applierSrc = file_get_contents( __DIR__ . '/../includes/class-order-applier.php' );
$pullSrc    = file_get_contents( __DIR__ . '/../includes/class-sync-pull.php' );

// Comments stripped BEFORE matching: a guard its own prose can satisfy proves
// nothing, and this repo has shipped exactly that mistake before.
$strip = function ( $src ) {
    $src = preg_replace( '!/\*.*?\*/!s', '', $src );
    return preg_replace( '!//.*$!m', '', $src );
};
$applierSrc = $strip( $applierSrc );
$pullSrc    = $strip( $pullSrc );

preg_match_all( '/\$applier->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $pullSrc, $calls );
$called = array_values( array_unique( $calls[1] ) );

ok( 'the pull path calls at least one applier method', count( $called ) > 0 );

foreach ( $called as $method ) {
    ok(
        "CashFlow_Order_Applier declares {$method}()",
        (bool) preg_match( '/function\s+' . preg_quote( $method, '/' ) . '\s*\(/', $applierSrc )
    );
}

// The create path's only idempotence is the sync key being ON the order when a
// redelivery looks for it. Without it, a lost ack creates a SECOND real order
// for the same job — a duplicate PARCEL, not a duplicate row.
//
// Unlike the edit path, create does not stamp it inside the applier: it rides
// in the body's meta_data, so it is written by the same
// prepare_object_for_database call that writes the order — one save, nothing to
// interleave. This asserts it is in the body BEFORE create() is called.
ok(
    'the create body carries the sync key meta before the applier is called',
    (bool) preg_match(
        '/SYNC_KEY_META.*?\$applier->create\s*\(/s',
        $pullSrc
    )
);

summary();
