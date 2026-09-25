<?php
/**
 * THE NEW STAND-INS BEHAVE LIKE CORE, INCLUDING CORE'S TRAPS.
 *
 * A stub kinder than WordPress certifies code that fails on a real store. The
 * two traps pinned here are real: get_terms() defaults to hide_empty = true (a
 * category no published product uses would vanish from a payload built with the
 * default), and get_terms() with an empty `include` returns EVERY term.
 */

require_once __DIR__ . '/bootstrap.php';

CF_TestState::reset();

echo "── WC_Product: core's defaults, and every read recorded with its context\n";
$p = new WC_Product( 7 );
ok( 'a fresh product has core\'s empty name', $p->get_name( 'edit' ) === '' );
ok( 'and is simple', $p->get_type() === 'simple' && $p->is_type( 'simple' ) && ! $p->is_type( 'variable' ) );
ok( 'and is in stock, not managing stock', $p->get_stock_status( 'edit' ) === 'instock' && $p->get_manage_stock( 'edit' ) === false );
ok( 'with no stock quantity, no sale dates, no terms, no image', $p->get_stock_quantity( 'edit' ) === null
    && $p->get_date_on_sale_from( 'edit' ) === null && $p->get_category_ids( 'edit' ) === [] && $p->get_image_id( 'edit' ) === '' );
ok( 'the context of each read is recorded', in_array( [ 'name', 'edit' ], CF_TestState::$product_reads, true ) );
$q = new WC_Product( 8, 0, 'draft', [ 'name' => 'Scarf', 'type' => 'variable', 'children' => [ 12, 11 ] ] );
ok( 'props override the defaults', $q->get_name( 'edit' ) === 'Scarf' && $q->is_type( [ 'grouped', 'variable' ] ) && $q->get_children() === [ 12, 11 ] );
ok( 'the 3-argument constructor the order tests use is unchanged', ( new WC_Product( 501, 0, 'trash' ) )->get_status() === 'trash'
    && ( new WC_Product( 602, 601 ) )->get_parent_id() === 601 );
$seen = [];
CF_TestState::$on_product_get = function ( $prop ) use ( &$seen ) { $seen[] = $prop; };
$q->get_sku( 'edit' );
ok( 'on_product_get runs inside a getter', $seen === [ 'sku' ] );
CF_TestState::$on_product_get = null;

echo "── posts, parents, attachments, options\n";
CF_TestState::$posts[20] = [ 'type' => 'product', 'status' => 'publish', 'parent' => 0 ];
CF_TestState::$posts[21] = [ 'type' => 'product_variation', 'status' => 'publish', 'parent' => 20 ];
ok( 'get_post_type reads the post', get_post_type( 20 ) === 'product' && get_post_type( 21 ) === 'product_variation' );
ok( 'get_post_type of an unknown id is false, like core', get_post_type( 99 ) === false );
ok( 'wp_get_post_parent_id', wp_get_post_parent_id( 21 ) === 20 && wp_get_post_parent_id( 20 ) === 0 );
CF_TestState::$attachments[5] = 'https://example.test/a.png';
ok( 'wp_get_attachment_url, false when absent', wp_get_attachment_url( 5 ) === 'https://example.test/a.png' && wp_get_attachment_url( 6 ) === false );
update_option( 'x', 1 ); delete_option( 'x' );
ok( 'delete_option removes', get_option( 'x', 'gone' ) === 'gone' );

echo "── get_terms, with core's traps\n";
CF_TestState::$terms[92] = (object) [ 'term_id' => 92, 'name' => 'Scarf', 'slug' => 'scarf', 'taxonomy' => 'product_cat', 'count' => 3 ];
CF_TestState::$terms[93] = (object) [ 'term_id' => 93, 'name' => 'Empty', 'slug' => 'empty', 'taxonomy' => 'product_cat', 'count' => 0 ];
CF_TestState::$terms[94] = (object) [ 'term_id' => 94, 'name' => 'Sale', 'slug' => 'sale', 'taxonomy' => 'product_tag', 'count' => 1 ];
$d = get_terms( [ 'taxonomy' => 'product_cat', 'include' => [ 93, 92 ] ] );
ok( 'hide_empty defaults to TRUE: the unused category is hidden', count( $d ) === 1 && $d[0]->term_id === 92 );
$a = get_terms( [ 'taxonomy' => 'product_cat', 'include' => [ 93, 92 ], 'hide_empty' => false, 'orderby' => 'include' ] );
ok( 'hide_empty false keeps it, in include order', array_map( fn( $t ) => $t->term_id, $a ) === [ 93, 92 ] );
ok( 'another taxonomy\'s term is not returned', get_terms( [ 'taxonomy' => 'product_cat', 'include' => [ 94 ], 'hide_empty' => false ] ) === [] );
$threw = false;
try { get_terms( [ 'taxonomy' => 'product_cat', 'include' => [], 'hide_empty' => false ] ); } catch ( RuntimeException $e ) { $threw = true; }
ok( 'an empty include is refused (core would return EVERY term)', $threw );
CF_TestState::$terms_error = 'Deadlock found';
$threw = false;
try { get_terms( [ 'taxonomy' => 'product_cat', 'include' => [ 92 ], 'hide_empty' => false, 'number' => 5 ] ); } catch ( RuntimeException $e ) { $threw = true; }
ok( 'an argument the stand-in does not model throws, never a plausible answer', $threw );
ok( 'a read failure is a WP_Error', is_wp_error( get_terms( [ 'taxonomy' => 'product_cat', 'include' => [ 92 ], 'hide_empty' => false ] ) ) );
CF_TestState::$terms_error = null;

echo "── Action Scheduler and add_action are recorded\n";
as_schedule_recurring_action( 100, 60, 'hook_a', [], 'group_a', true );
as_schedule_recurring_action( 100, 60, 'hook_b', [], 'group_b', true, 20 );
ok( 'an omitted priority is Action Scheduler\'s default, 10', CF_TestState::$as_scheduled['hook_a']['priority'] === 10 );
ok( 'a given priority is recorded', CF_TestState::$as_scheduled['hook_b']['priority'] === 20 );
ok( 'as_next_scheduled_action answers per hook', as_next_scheduled_action( 'hook_a' ) === 100 && as_next_scheduled_action( 'nope' ) === false );
ok( 'and respects the group when one is given', as_next_scheduled_action( 'hook_b', [], 'group_a' ) === false );
as_unschedule_all_actions( 'hook_a' );
ok( 'unschedule removes it', as_next_scheduled_action( 'hook_a' ) === false );
add_action( 'save_post_product', 'cb', 10, 1 );
ok( 'add_action records hook, callback, priority, args', CF_TestState::$actions['save_post_product'][0] === [ 'cb', 10, 1 ] );
ok( 'wp_cache_flush_runtime is counted', wp_cache_flush_runtime() === true && CF_TestState::$cache_flushes === 1 );

CF_TestState::reset();
ok( 'reset clears all of it', CF_TestState::$actions === [] && CF_TestState::$posts === [] && CF_TestState::$as_scheduled === []
    && CF_TestState::$product_reads === [] && CF_TestState::$cache_flushes === 0 );

summary();
