<?php
/**
 * WHICH HOOKS, WHICH ROWS.
 *
 * A hook runs INSIDE an admin save, so it does one cheap thing — an INSERT
 * IGNORE — and it can never break the save it rides on.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-catalog.php';

function site(): void {
    CF_TestState::reset();
    CF_TestState::$options['cashflow_catalog_db_version'] = '1';
    CF_TestState::$catalog_tables['wp_cashflow_catalog_queue'] = true;
    CF_TestState::$posts = [
        100 => [ 'type' => 'product', 'status' => 'publish', 'parent' => 0 ],
        101 => [ 'type' => 'product_variation', 'status' => 'publish', 'parent' => 100 ],
        102 => [ 'type' => 'product_variation', 'status' => 'publish', 'parent' => 0 ],   // an orphaned variation
        200 => [ 'type' => 'shop_order', 'status' => 'wc-processing', 'parent' => 0 ],
        300 => [ 'type' => 'revision', 'status' => 'inherit', 'parent' => 100 ],
        400 => [ 'type' => 'page', 'status' => 'publish', 'parent' => 0 ],
    ];
}
function queued(): array {
    return array_values( array_map( function ( $r ) { return $r['product_id'] . ':' . $r['reason']; }, CF_TestState::$catalog_queue ) );
}

echo "── the hooks in the design are registered, and no term hook\n";
site();
new CashFlow_Catalog();
$expect = [
    'save_post_product' => 'on_post_id', 'woocommerce_new_product' => 'on_post_id', 'woocommerce_update_product' => 'on_post_id',
    'woocommerce_update_product_variation' => 'on_post_id', 'trashed_post' => 'on_post_id', 'untrashed_post' => 'on_post_id',
    'woocommerce_product_set_stock' => 'on_product_object', 'woocommerce_variation_set_stock' => 'on_product_object',
    'before_delete_post' => 'on_before_delete',
];
foreach ( $expect as $hook => $method ) {
    $h = CF_TestState::$actions[ $hook ][0] ?? null;
    ok( "$hook → $method", $h === [ [ 'CashFlow_Catalog', $method ], 10, 1 ], json_encode( $h ) );
}
foreach ( [ 'edited_term', 'edited_product_cat', 'edited_product_tag', 'created_term', 'delete_term' ] as $hook ) {
    ok( "no $hook hook (a rename is left to the hourly list)", empty( CF_TestState::$actions[ $hook ] ) );
}

echo "── a product queues itself, once\n";
site();
CashFlow_Catalog::on_post_id( 100 );
CashFlow_Catalog::on_post_id( 100 );
CashFlow_Catalog::on_product_object( new WC_Product( 100 ) );
ok( 'three hooks for one save make one waiting row', queued() === [ '100:save' ], json_encode( queued() ) );

echo "── a variation queues its parent\n";
site();
CashFlow_Catalog::on_post_id( 101 );
ok( 'woocommerce_update_product_variation → the parent, as a save', queued() === [ '100:save' ] );
site();
CashFlow_Catalog::on_product_object( new WC_Product( 101, 100 ) );
ok( 'woocommerce_variation_set_stock → the parent, as a save', queued() === [ '100:save' ] );
site();
CashFlow_Catalog::on_post_id( 102 );
ok( 'a variation with no parent queues nothing', queued() === [] );

echo "── a permanent delete\n";
site();
CashFlow_Catalog::on_before_delete( 100 );
ok( 'a product being deleted writes a removed row', queued() === [ '100:removed' ] );
site();
CashFlow_Catalog::on_before_delete( 101 );
ok( 'a variation being deleted is a SAVE of its parent, never a removal', queued() === [ '100:save' ] );

echo "── trash and restore are saves (the payload carries the status)\n";
site();
CashFlow_Catalog::on_post_id( 100 );                       // trashed_post
ok( 'trashed_post → save', queued() === [ '100:save' ] );

echo "── everything that is not a product is ignored\n";
site();
foreach ( [ 200, 300, 400, 999, 0, -5, 'abc' ] as $id ) {
    CashFlow_Catalog::on_post_id( $id );
    CashFlow_Catalog::on_before_delete( $id );
}
CashFlow_Catalog::on_product_object( null );
CashFlow_Catalog::on_product_object( 'not a product' );
ok( 'orders, revisions, pages, unknown ids and junk queue nothing', queued() === [], json_encode( queued() ) );

echo "── a hook can never break the save it rides on\n";
site();
CF_TestState::$db_error_on = 'INSERT IGNORE INTO wp_cashflow_catalog_queue (product_id';
$threw = false;
try { CashFlow_Catalog::on_post_id( 100 ); } catch ( Throwable $e ) { $threw = true; }
ok( 'a database failure does not throw into the save', ! $threw );
ok( 'and it is on the panel', str_contains( (string) ( CashFlow_Catalog::stats()['last_error'] ?? '' ), 'Product 100 could not be queued' ) );
CF_TestState::$db_error_on = null;
$real = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new class extends CF_Test_WPDB {
    public function query( $q ) { throw new RuntimeException( 'MySQL server has gone away' ); }
};
$threw = false;
try { CashFlow_Catalog::on_post_id( 100 ); CashFlow_Catalog::on_before_delete( 100 ); CashFlow_Catalog::on_product_object( new WC_Product( 100 ) ); }
catch ( Throwable $e ) { $threw = true; }
$GLOBALS['wpdb'] = $real;
ok( 'an exception inside a handler is swallowed and logged, never thrown', ! $threw );
ok( 'and it reaches the status panel, not only the PHP log',
    str_contains( (string) ( CashFlow_Catalog::stats()['last_error'] ?? '' ), 'MySQL server has gone away' ) );

summary();
