<?php
/**
 * THE PAYLOAD, ITS FINGERPRINT, AND sent_seq.
 *
 * One builder feeds the send and the fingerprint. The fingerprint is pinned to
 * the EXACT canonical text below: changing the flags, the key order or a field
 * changes every product's fingerprint and makes the next hourly list ask for
 * the whole catalogue again — which must be a deliberate, visible decision,
 * never a side effect of a tidy-up.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-catalog.php';

function shop(): void {
    CF_TestState::reset();
    CF_TestState::$terms[92] = (object) [ 'term_id' => 92, 'name' => 'Scarf Bandana', 'slug' => 'scarf-bandana', 'taxonomy' => 'product_cat', 'count' => 0 ];
    CF_TestState::$terms[15] = (object) [ 'term_id' => 15, 'name' => 'Winter', 'slug' => 'winter', 'taxonomy' => 'product_cat', 'count' => 4 ];
    CF_TestState::$terms[7]  = (object) [ 'term_id' => 7, 'name' => 'New', 'slug' => 'new', 'taxonomy' => 'product_tag', 'count' => 9 ];
    CF_TestState::$attachments[555] = 'https://zensha.pk/wp-content/uploads/2026/09/x.png';
    CF_TestState::$attachments[556] = 'https://zensha.pk/wp-content/uploads/2026/09/gallery.png';
}
function scarf( array $over = [] ): WC_Product {
    return new WC_Product( 19642, 0, 'publish', array_merge( [
        'name' => 'Wine Blossom Scarf Bandana', 'sku' => 'ZEN-AUG26-031-1-2',
        'regular_price' => '1200', 'sale_price' => '990', 'price' => '990',
        // 05:00 in Karachi IS 00:00 UTC — the wire carries WooCommerce's zone-less GMT.
        'date_on_sale_from' => new DateTimeImmutable( '2026-09-20 05:00:00', new DateTimeZone( 'Asia/Karachi' ) ),
        'category_ids' => [ 92, 15 ], 'tag_ids' => [ 7 ], 'image_id' => 555,
    ], $over ) );
}

echo "── the fields, exactly the wire's, from edit-context getters\n";
shop();
$built = CashFlow_Catalog::payload( scarf() );
$expected = [
    'id' => 19642, 'name' => 'Wine Blossom Scarf Bandana', 'sku' => 'ZEN-AUG26-031-1-2', 'type' => 'simple', 'status' => 'publish',
    'regular_price' => '1200', 'sale_price' => '990', 'price' => '990',
    'date_on_sale_from_gmt' => '2026-09-20T00:00:00', 'date_on_sale_to_gmt' => null,
    'stock_quantity' => null, 'stock_status' => 'instock', 'manage_stock' => false, 'weight' => '',
    'categories' => [ [ 'id' => 15, 'name' => 'Winter', 'slug' => 'winter' ], [ 'id' => 92, 'name' => 'Scarf Bandana', 'slug' => 'scarf-bandana' ] ],
    'tags' => [ [ 'id' => 7, 'name' => 'New', 'slug' => 'new' ] ],
    'images' => [ [ 'src' => 'https://zensha.pk/wp-content/uploads/2026/09/x.png' ] ],
    'variations' => [],
];
ok( 'the payload is exactly the wire\'s product (types and order included)', $built['fields'] === $expected, json_encode( $built['fields'] ) );
ok( 'a category no published product uses is still this product\'s (hide_empty false)', $built['fields']['categories'][1]['id'] === 92 );
$contexts = array_unique( array_map( function ( $r ) { return $r[0] === 'children' ? 'edit' : $r[1]; }, CF_TestState::$product_reads ) );
ok( 'every getter was read in edit context', array_values( $contexts ) === [ 'edit' ], json_encode( CF_TestState::$product_reads ) );

echo "── the fingerprint is MD5 of THIS canonical text\n";
$canonical = '{"categories":[{"id":15,"name":"Winter","slug":"winter"},{"id":92,"name":"Scarf Bandana","slug":"scarf-bandana"}],'
    . '"date_on_sale_from_gmt":"2026-09-20T00:00:00","date_on_sale_to_gmt":null,"id":19642,'
    . '"images":[{"src":"https://zensha.pk/wp-content/uploads/2026/09/x.png"}],"manage_stock":false,'
    . '"name":"Wine Blossom Scarf Bandana","price":"990","regular_price":"1200","sale_price":"990","sku":"ZEN-AUG26-031-1-2",'
    . '"status":"publish","stock_quantity":null,"stock_status":"instock","tags":[{"id":7,"name":"New","slug":"new"}],'
    . '"type":"simple","variations":[],"weight":""}';
ok( 'fingerprint = md5( sorted-key JSON, slashes and unicode unescaped )', $built['fingerprint'] === md5( $canonical ), $built['fingerprint'] );
ok( 'it is 32 lowercase hex characters', 1 === preg_match( '/^[0-9a-f]{32}$/', $built['fingerprint'] ) );
ok( 'the same product built twice has the same fingerprint', CashFlow_Catalog::payload( scarf() )['fingerprint'] === $built['fingerprint'] );
ok( 'terms stored in another order change nothing', CashFlow_Catalog::payload( scarf( [ 'category_ids' => [ 15, 92, 15 ] ] ) )['fingerprint'] === $built['fingerprint'] );
ok( 'a price change changes it', CashFlow_Catalog::payload( scarf( [ 'price' => '1000' ] ) )['fingerprint'] !== $built['fingerprint'] );

echo "── images, variations, stock, dates\n";
shop();
ok( 'no featured image → the first gallery image, as the REST API does',
    CashFlow_Catalog::payload( scarf( [ 'image_id' => '', 'gallery_image_ids' => [ 556, 555 ] ] ) )['fields']['images'] === [ [ 'src' => 'https://zensha.pk/wp-content/uploads/2026/09/gallery.png' ] ] );
ok( 'no image at all → []', CashFlow_Catalog::payload( scarf( [ 'image_id' => '' ] ) )['fields']['images'] === [] );
CF_TestState::$attachments[557] = 'https://zensha.pk/' . str_repeat( 'a', 2049 );
ok( 'an image URL over 2,048 characters is dropped, never cut', CashFlow_Catalog::payload( scarf( [ 'image_id' => 557 ] ) )['fields']['images'] === [] );
ok( 'a variable product sends its variation ids, ascending',
    CashFlow_Catalog::payload( scarf( [ 'type' => 'variable', 'children' => [ 12, 11 ] ] ) )['fields']['variations'] === [ 11, 12 ] );
ok( 'a grouped product\'s children are NOT variations', CashFlow_Catalog::payload( scarf( [ 'type' => 'grouped', 'children' => [ 5 ] ] ) )['fields']['variations'] === [] );
$f = CashFlow_Catalog::payload( scarf( [ 'manage_stock' => true, 'stock_quantity' => '5', 'stock_status' => 'onbackorder',
    'date_on_sale_to' => new DateTimeImmutable( '2026-09-30 18:59:59', new DateTimeZone( 'UTC' ) ) ] ) )['fields'];
ok( 'stock is a number and managed', $f['stock_quantity'] === 5 && $f['manage_stock'] === true && $f['stock_status'] === 'onbackorder' );
ok( 'the sale end is zone-less UTC', $f['date_on_sale_to_gmt'] === '2026-09-30T18:59:59' );
ok( 'a sale date that is not a date is null', CashFlow_Catalog::payload( scarf( [ 'date_on_sale_from' => 'soon' ] ) )['fields']['date_on_sale_from_gmt'] === null );

echo "── the image src is independent of is_ssl()/is_admin() [review, Important]\n";
// Action Scheduler runs the catalogue job both through WP-Cron (is_ssl varies,
// is_admin false) and through admin-ajax (is_admin true). Core's own
// wp_get_attachment_url() upgrades http → https only when is_ssl() &&
// !is_admin(), so the SAME stored (http) attachment answers differently
// depending on which runner asked — unless the payload forces a scheme
// independent of the request. A store like 1shop.pk (siteurl http, home
// https) hits this on every ordinary product.
shop();
CF_TestState::$attachments[558] = 'http://1shop.pk/wp-content/uploads/2026/09/y.png';
CF_TestState::$is_ssl = true;  CF_TestState::$is_admin = false;   // WP-Cron: is_ssl varies, never admin
$via_cron = CashFlow_Catalog::payload( scarf( [ 'image_id' => 558 ] ) );
CF_TestState::$is_ssl = false; CF_TestState::$is_admin = true;    // admin-ajax: always admin
$via_ajax = CashFlow_Catalog::payload( scarf( [ 'image_id' => 558 ] ) );
CF_TestState::$is_ssl = false; CF_TestState::$is_admin = false;   // restore
ok( 'the same image src whichever runner built it', $via_cron['fields']['images'] === $via_ajax['fields']['images'],
    json_encode( [ 'cron' => $via_cron['fields']['images'], 'ajax' => $via_ajax['fields']['images'] ] ) );
ok( 'and therefore the same fingerprint (no needless resend)', $via_cron['fingerprint'] === $via_ajax['fingerprint'] );
ok( 'the src takes home_url()\'s scheme, not the request\'s',
    $via_cron['fields']['images'] === [ [ 'src' => 'https://1shop.pk/wp-content/uploads/2026/09/y.png' ] ] );

echo "── a variable parent's own price fields, as WooCommerce reports them [review, Minor]\n";
shop();
$vp = CashFlow_Catalog::payload( scarf( [
    'type' => 'variable', 'regular_price' => '', 'sale_price' => '', 'price' => '450', 'children' => [ 30, 20 ],
] ) )['fields'];
ok( 'regular_price and sale_price are empty (a variable parent carries none of its own)', $vp['regular_price'] === '' && $vp['sale_price'] === '' );
ok( 'price is whatever WooCommerce reports (its active range)', $vp['price'] === '450' );
ok( 'and its variation ids, ascending', $vp['variations'] === [ 20, 30 ] );

echo "── sizes are capped in the plugin, at the server's own cuts [NB7]\n";
shop();
for ( $i = 1; $i <= 150; $i++ ) {
    CF_TestState::$terms[ 1000 + $i ] = (object) [ 'term_id' => 1000 + $i, 'name' => str_repeat( 'é', 400 ), 'slug' => "c$i", 'taxonomy' => 'product_cat', 'count' => 1 ];
}
$big = CashFlow_Catalog::payload( scarf( [
    'name' => str_repeat( 'é', 5000 ), 'sku' => str_repeat( 'S', 300 ), 'regular_price' => str_repeat( '9', 60 ),
    'category_ids' => range( 1001, 1150 ), 'type' => 'variable', 'children' => range( 1, 1500 ),
] ) )['fields'];
ok( 'name cut to 500 characters (not bytes)', mb_strlen( $big['name'] ) === 500 );
ok( 'sku cut to 100', strlen( $big['sku'] ) === 100 );
ok( 'a number string cut to 40', strlen( $big['regular_price'] ) === 40 );
ok( 'at most 100 categories, each name cut to 200', count( $big['categories'] ) === 100 && mb_strlen( $big['categories'][0]['name'] ) === 200 );
ok( 'at most 1,000 variations', count( $big['variations'] ) === 1000 );
ok( 'a price over the cap is CUT to 40 characters, never sent empty [review, Minor]',
    strlen( $big['regular_price'] ) === 40 && $big['regular_price'] !== '' );

echo "── bad bytes never reach the wire [NB7]\n";
shop();
$dirty = CashFlow_Catalog::payload( scarf( [ 'name' => "Caf\xE9 \0Scarf", 'sku' => "A\xC3" ] ) );
ok( 'invalid UTF-8 is replaced, and the result is valid UTF-8', mb_check_encoding( $dirty['fields']['name'], 'UTF-8' ) && str_starts_with( $dirty['fields']['name'], 'Caf' ) );
ok( 'U+0000 is removed (Postgres cannot hold it)', ! str_contains( $dirty['fields']['name'], "\0" ) );
ok( 'the dirty product still encodes and fingerprints', 1 === preg_match( '/^[0-9a-f]{32}$/', $dirty['fingerprint'] )
    && json_decode( CashFlow_Catalog::encode( $dirty['fields'] ), true )['sku'] !== null );

echo "── one product can never outgrow its share of a body\n";
shop();
for ( $i = 1; $i <= 100; $i++ ) {
    CF_TestState::$terms[ 3000 + $i ] = (object) [ 'term_id' => 3000 + $i, 'name' => str_repeat( "\x01", 200 ), 'slug' => str_repeat( "\x02", 200 ), 'taxonomy' => 'product_cat', 'count' => 1 ];
    CF_TestState::$terms[ 4000 + $i ] = (object) [ 'term_id' => 4000 + $i, 'name' => str_repeat( "\x01", 200 ), 'slug' => str_repeat( "\x02", 200 ), 'taxonomy' => 'product_tag', 'count' => 1 ];
}
$huge_over = [ 'name' => str_repeat( "\x01", 500 ), 'category_ids' => range( 3001, 3100 ), 'tag_ids' => range( 4001, 4100 ), 'type' => 'variable', 'children' => range( 1, 1000 ) ];
$huge = CashFlow_Catalog::payload( scarf( $huge_over ) );
ok( 'every field capped, the product still over the share, is fitted under 64 KB',
    strlen( CashFlow_Catalog::encode( $huge['fields'] ) ) <= CashFlow_Catalog::MAX_ONE_BYTES, (string) strlen( CashFlow_Catalog::encode( $huge['fields'] ) ) );
ok( 'by dropping tags first, then keeping 10 categories', $huge['fields']['tags'] === [] && count( $huge['fields']['categories'] ) === 10 );
ok( 'and the fitting is deterministic (same fingerprint every time)', CashFlow_Catalog::payload( scarf( $huge_over ) )['fingerprint'] === $huge['fingerprint'] );

echo "── a product is never fingerprinted from half its data\n";
shop();
CF_TestState::$terms_error = 'Deadlock found when trying to get lock';
$threw = false;
try { CashFlow_Catalog::payload( scarf() ); } catch ( RuntimeException $e ) { $threw = str_contains( $e->getMessage(), 'product_cat' ); }
ok( 'a term read failure throws, naming the taxonomy', $threw );
CF_TestState::$terms_error = null;

echo "── sent_seq: the microsecond clock, as an integer string [NB5]\n";
$seqs = [];
for ( $i = 0; $i < 1000; $i++ ) { $seqs[] = CashFlow_Catalog::next_seq(); }
ok( 'a 16-digit integer string', 1 === preg_match( '/^[0-9]{16}$/', $seqs[0] ), $seqs[0] );
$rising = true;
for ( $i = 1; $i < 1000; $i++ ) { if ( (int) $seqs[ $i ] <= (int) $seqs[ $i - 1 ] ) { $rising = false; } }
ok( 'strictly rising, even for 1,000 reads in one microsecond-dense loop', $rising );
ok( 'and it is the wall clock (it keeps rising across reinstalls)', abs( (int) end( $seqs ) / 1e6 - microtime( true ) ) < 2 );

summary();
