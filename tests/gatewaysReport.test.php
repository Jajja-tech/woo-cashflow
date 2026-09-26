<?php
/**
 * THE STORE REPORTS ITS ENABLED PAYMENT GATEWAYS ON EVERY POLL.
 *
 * The order editor is only allowed to offer payment methods the STORE has
 * actually switched on, by the store's own wording — never a fixed cod/bacs
 * guess (Golden Rule #6). This is the plugin half: CashFlow_Sync_Pull's
 * poll body gains a `gateways` key built from CashFlow_Sync_Pull::
 * enabled_gateways(), a static that reads WC()->payment_gateways()->
 * payment_gateways() directly — never get_available_payment_gateways(),
 * which depends on a cart/checkout context this cron tick does not have and
 * would report every store as having zero payment methods.
 *
 * ABSENT vs EMPTY is the whole contract: the key is missing when WC's
 * gateways could not be read at all (keep whatever the backend already has
 * stored), and is an empty array when the store genuinely has none enabled
 * (replace what is stored). Conflating the two would either erase a real
 * report on a transient hiccup, or hide a store that turned everything off.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-sync-pull.php';

function gw( string $id, string $enabled = 'yes', $title = '' ): CF_Test_Gateway {
    return new CF_Test_Gateway( $id, $enabled, $title );
}
function poll_with( array $data ): void {
    CF_TestState::$api_responses['/plugin/sync/poll'][] = [ 'ok' => true, 'status' => 200, 'data' => $data ];
}
function calls_to( string $endpoint ): array {
    return array_values( array_filter( CF_TestState::$api_calls, fn( $c ) => $c['endpoint'] === $endpoint ) );
}
function connected_store(): void {
    CF_TestState::reset();
    CF_TestState::$options['cashflow_connection_secret'] = 'secret-xyz';
}

echo "── two enabled + one disabled → only the two, in order, with titles\n";
CF_TestState::$payment_gateways = [
    'cod'    => gw( 'cod', 'yes', 'Cash on Delivery' ),
    'cheque' => gw( 'cheque', 'no', 'Cheque Payment' ),
    'bacs'   => gw( 'bacs', 'yes', 'Direct Bank Transfer' ),
];
$out = CashFlow_Sync_Pull::enabled_gateways();
ok( 'returns exactly the two enabled gateways', is_array( $out ) && count( $out ) === 2, json_encode( $out ) );
ok( 'WooCommerce\'s own order is preserved (cod before bacs)',
    ( $out[0]['id'] ?? '' ) === 'cod' && ( $out[1]['id'] ?? '' ) === 'bacs', json_encode( $out ) );
ok( 'each carries the store\'s own title', ( $out[0]['title'] ?? '' ) === 'Cash on Delivery'
    && ( $out[1]['title'] ?? '' ) === 'Direct Bank Transfer', json_encode( $out ) );
ok( 'the disabled gateway is not in the list', ! in_array( 'cheque', array_column( $out, 'id' ), true ) );

echo "── empty title falls back to the id\n";
CF_TestState::$payment_gateways = [ 'cod' => gw( 'cod', 'yes', '' ) ];
$out = CashFlow_Sync_Pull::enabled_gateways();
ok( 'blank title becomes the id', ( $out[0]['title'] ?? '' ) === 'cod', json_encode( $out ) );

echo "── whitespace-only title also falls back to the id\n";
CF_TestState::$payment_gateways = [ 'cod' => gw( 'cod', 'yes', '   ' ) ];
$out = CashFlow_Sync_Pull::enabled_gateways();
ok( 'whitespace-only title becomes the id', ( $out[0]['title'] ?? '' ) === 'cod', json_encode( $out ) );

echo "── a title over 200 chars is cut to 200\n";
$long = str_repeat( 'A', 250 );
CF_TestState::$payment_gateways = [ 'cod' => gw( 'cod', 'yes', $long ) ];
$out = CashFlow_Sync_Pull::enabled_gateways();
ok( 'title is cut to exactly 200 chars', strlen( $out[0]['title'] ?? '' ) === 200, (string) strlen( $out[0]['title'] ?? '' ) );
ok( 'the cut keeps the leading characters', ( $out[0]['title'] ?? '' ) === str_repeat( 'A', 200 ) );

echo "── more than 50 enabled gateways is capped at 50\n";
$many = [];
for ( $i = 1; $i <= 60; $i++ ) { $many[ 'gw' . $i ] = gw( 'gw' . $i, 'yes', 'Gateway ' . $i ); }
CF_TestState::$payment_gateways = $many;
$out = CashFlow_Sync_Pull::enabled_gateways();
ok( 'capped at 50 entries', is_array( $out ) && count( $out ) === 50, count( $out ?? [] ) . ' entries' );
ok( 'the first 50 in order are kept (gw1..gw50)', ( $out[0]['id'] ?? '' ) === 'gw1' && ( $out[49]['id'] ?? '' ) === 'gw50' );

echo "── WC's gateways cannot be read → null, and the poll body has NO gateways key\n";
connected_store();
CF_TestState::$payment_gateways = null; // WC()->payment_gateways()->payment_gateways() unavailable
$out = CashFlow_Sync_Pull::enabled_gateways();
ok( 'enabled_gateways() itself answers null', null === $out );
poll_with( [ 'jobs' => [] ] );
$pull = new CashFlow_Sync_Pull();
$pull->tick();
$poll = calls_to( '/plugin/sync/poll' )[0]['body'] ?? [];
ok( 'the poll body carries no gateways key at all', ! array_key_exists( 'gateways', $poll ), json_encode( $poll ) );

echo "── a long Urdu title is cut in characters, never mid-character\n";
CF_TestState::reset();
CF_TestState::$payment_gateways = [ 'cod' => gw( 'cod', 'yes', str_repeat( 'نقد', 100 ) ) ];
$out = CashFlow_Sync_Pull::enabled_gateways();
ok( 'cut to exactly 200 characters', mb_strlen( $out[0]['title'] ?? '', 'UTF-8' ) === 200, (string) mb_strlen( $out[0]['title'] ?? '', 'UTF-8' ) );
ok( 'still valid UTF-8, so the poll body can be encoded', mb_check_encoding( $out[0]['title'] ?? '', 'UTF-8' ) && false !== json_encode( $out ) );

echo "── a gateway whose get_title() throws is still reported, its id standing in for the title (final review M5)\n";
CF_TestState::$payment_gateways = [
    'cod'  => gw( 'cod', 'yes', 'Cash on Delivery' ),
    'bacs' => gw( 'bacs', 'yes', new RuntimeException( 'a broken gateway plugin' ) ),
];
$threw       = false;
$throw_result = 'never assigned';
try {
    $throw_result = CashFlow_Sync_Pull::enabled_gateways();
} catch ( Throwable $e ) {
    $threw = true;
}
ok( 'enabled_gateways() never throws', false === $threw );
ok( 'the WORKING gateway still reports', is_array( $throw_result ) && count( $throw_result ) === 2, json_encode( $throw_result ) );
ok( 'the broken one reports too, with its id as the title — only a whole-read failure answers null',
    ( $throw_result[1]['id'] ?? '' ) === 'bacs' && ( $throw_result[1]['title'] ?? '' ) === 'bacs', json_encode( $throw_result ) );

echo "── zero enabled gateways → [], and the key IS sent (a real, positive report)\n";
connected_store();
CF_TestState::$payment_gateways = [ 'cheque' => gw( 'cheque', 'no', 'Cheque Payment' ) ];
$direct = CashFlow_Sync_Pull::enabled_gateways();
ok( 'enabled_gateways() itself answers an empty array, not null', is_array( $direct ) && [] === $direct, json_encode( $direct ) );
poll_with( [ 'jobs' => [] ] );
$pull = new CashFlow_Sync_Pull();
$pull->tick();
$poll = calls_to( '/plugin/sync/poll' )[0]['body'] ?? [];
ok( 'the gateways key IS present', array_key_exists( 'gateways', $poll ), json_encode( $poll ) );
ok( 'and it is an empty array', [] === ( $poll['gateways'] ?? 'missing' ), json_encode( $poll['gateways'] ?? null ) );

echo "── the poll request body (captured by the api_request stub) carries the list\n";
connected_store();
CF_TestState::$payment_gateways = [
    'cod'  => gw( 'cod', 'yes', 'Cash on Delivery' ),
    'bacs' => gw( 'bacs', 'yes', 'Direct Bank Transfer' ),
];
poll_with( [ 'jobs' => [] ] );
$pull = new CashFlow_Sync_Pull();
$pull->tick();
$poll = calls_to( '/plugin/sync/poll' )[0]['body'] ?? [];
ok( 'the poll body carries the reported gateways', ( $poll['gateways'] ?? null ) === [
    [ 'id' => 'cod', 'title' => 'Cash on Delivery' ],
    [ 'id' => 'bacs', 'title' => 'Direct Bank Transfer' ],
], json_encode( $poll['gateways'] ?? null ) );
ok( 'still carries version, limit and supports too', ( $poll['version'] ?? null ) === CASHFLOW_VERSION
    && ( $poll['limit'] ?? null ) === 3 && ( $poll['supports'] ?? null ) === [ 'order.create@1', 'catalog.push@1' ] );

echo "── an id over 100 characters is dropped (final review M6)\n";
$id100 = str_repeat( 'x', 100 );
CF_TestState::$payment_gateways = [ $id100 => gw( $id100, 'yes', 'Title' ) ];
$out = CashFlow_Sync_Pull::enabled_gateways();
ok( 'an id at exactly 100 chars is kept', is_array( $out ) && count( $out ) === 1 && ( $out[0]['id'] ?? '' ) === $id100, json_encode( $out ) );

$long_id = str_repeat( 'x', 101 );
CF_TestState::$payment_gateways = [ $long_id => gw( $long_id, 'yes', 'Title' ) ];
$out = CashFlow_Sync_Pull::enabled_gateways();
ok( 'an id over 100 chars is dropped, not truncated', is_array( $out ) && [] === $out, json_encode( $out ) );

echo "── a duplicate id (numeric array keys, so both fall back to \$g->id) keeps the FIRST (final review M6)\n";
CF_TestState::$payment_gateways = [
    gw( 'cod', 'yes', 'First title' ),
    gw( 'cod', 'yes', 'A later, different title' ),
];
$out = CashFlow_Sync_Pull::enabled_gateways();
ok( 'only one entry survives', is_array( $out ) && count( $out ) === 1, json_encode( $out ) );
ok( 'it is the FIRST one seen', ( $out[0]['title'] ?? '' ) === 'First title', json_encode( $out ) );

echo "── a plain numeric array key is not usable as an id — falls back to \$g->id (final review M6)\n";
CF_TestState::$payment_gateways = [ gw( 'bacs', 'yes', 'Direct Bank Transfer' ) ]; // key 0, not 'bacs'
$out = CashFlow_Sync_Pull::enabled_gateways();
ok( 'the id comes from $g->id, not the array key', is_array( $out ) && count( $out ) === 1
    && ( $out[0]['id'] ?? '' ) === 'bacs', json_encode( $out ) );

echo "── control characters are stripped from both id and title before anything is bounded (final review M3/M6)\n";
CF_TestState::$payment_gateways = [ "co\x00d" => gw( "co\x00d", 'yes', "Cash\x07 on\x7F delivery" ) ];
$out = CashFlow_Sync_Pull::enabled_gateways();
ok( 'the id has its control characters stripped', ( $out[0]['id'] ?? '' ) === 'cod', json_encode( $out ) );
ok( 'the title has its control characters stripped', ( $out[0]['title'] ?? '' ) === 'Cash on delivery', json_encode( $out ) );

echo "── an id that is nothing but control characters is dropped, like an empty one\n";
CF_TestState::$payment_gateways = [ "\x00\x01" => gw( "\x00\x01", 'yes', 'Title' ) ];
$out = CashFlow_Sync_Pull::enabled_gateways();
ok( 'dropped, not reported with a blank id', is_array( $out ) && [] === $out, json_encode( $out ) );

summary();
