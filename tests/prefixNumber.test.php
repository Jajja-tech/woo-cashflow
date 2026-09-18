<?php
/**
 * A CASHFLOW-CREATED ORDER SHOWS, AND IS FOUND BY, ITS CASHFLOW NUMBER.
 *
 * CashFlow mints <prefix>-C<n> for an order it creates and never changes it:
 * courier bookings, labels and the activity log point at it. The plugin used
 * to display prefix + WooCommerce id for every order, and admin search
 * stripped the prefix and searched by id — so "1SH-C12" showed as "1SH-4417"
 * in WP admin and emails, and searching "1SH-C12" found nothing.
 *
 * Runs the REAL includes/class-prefix.php functions.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-prefix.php';

function seed( int $id, array $meta = [], string $status = 'on-hold' ): WC_Order {
    $o = new WC_Order( $id, $meta );
    $o->status = $status;
    CF_TestState::$orders[ $id ] = $o;
    return $o;
}
function reset_search( string $s, bool $hpos_page = false ): void {
    $_GET = $_REQUEST = [];
    $_GET['s'] = $_REQUEST['s'] = $s;
    if ( $hpos_page ) { $_GET['page'] = 'wc-orders'; } else { $_GET['post_type'] = 'shop_order'; }
}

CF_TestState::reset();
CF_TestState::$options['cashflow_order_prefix'] = '1SH';

echo "── the displayed number\n";
$c  = seed( 4417, [ 'cashflow_order_number' => '1SH-C12' ] );
$w  = seed( 4418 );
ok( 'a CashFlow-created order shows its C number', cf_display_order_number( '4417', $c ) === '1SH-C12',
    cf_display_order_number( '4417', $c ) );
ok( 'a website order still shows prefix + id', cf_display_order_number( '4418', $w ) === '1SH-4418',
    cf_display_order_number( '4418', $w ) );

echo "── legacy (posts) admin search\n";
foreach ( [ '1SH-C12', '1sh-c12', '#1SH-C12', 'C12', 'c12' ] as $typed ) {
    reset_search( $typed );
    cf_normalize_order_search();
    ok( "searching \"$typed\" becomes the order's id", ( $_GET['s'] ?? '' ) === '4417' && ( $_REQUEST['s'] ?? '' ) === '4417',
        'became ' . json_encode( $_GET['s'] ?? null ) );
}
reset_search( '1SH-4418' );
cf_normalize_order_search();
ok( 'a website number still strips to its id, as before', ( $_GET['s'] ?? '' ) === '4418', json_encode( $_GET['s'] ?? null ) );
reset_search( '1SH-C99' );
cf_normalize_order_search();
ok( 'a C number nobody has is left for the normal search (no false match)', ( $_GET['s'] ?? '' ) !== '4417',
    json_encode( $_GET['s'] ?? null ) );
ok( 'the legacy search also LIKE-matches the meta field',
    in_array( 'cashflow_order_number', cf_search_cashflow_number_field( [ '_billing_email' ] ), true ) );
ok( 'and that filter is registered on the legacy hook',
    in_array( 'cf_search_cashflow_number_field', CF_TestState::$filters['woocommerce_shop_order_search_fields'] ?? [], true ) );

echo "── HPOS admin search\n";
foreach ( [ '1SH-C12', '#1sh-c12', 'C12' ] as $typed ) {
    $args = cf_hpos_search_args( [ 's' => $typed ] );
    ok( "HPOS: \"$typed\" becomes the order's id", ( $args['s'] ?? '' ) === '4417', json_encode( $args ) );
}
$args = cf_hpos_search_args( [ 's' => '1SH-4418' ] );
ok( 'HPOS: a website number still strips to its id', ( $args['s'] ?? '' ) === '4418', json_encode( $args ) );
$args = cf_hpos_search_args( [ 'status' => 'any' ] );
ok( 'HPOS: a query with no search is untouched', $args === [ 'status' => 'any' ] );

summary();
