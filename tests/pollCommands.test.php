<?php
/**
 * THE POLL DECLARES WHAT THE PLUGIN CAN DO, AND EVERY COMMAND IS ACKED.
 *
 * Runs the REAL CashFlow_Sync_Pull::tick() end to end against the harness:
 * the only thing faked is CashFlow_Plugin::api_request (the network), which
 * records every call so the poll body and each ack can be read back.
 *
 * Why `supports` matters: the backend hands out a create command only to a
 * poll that declares order.create@1. A poll that forgot it would leave every
 * CashFlow-created order waiting forever with "plugin update needed" on a
 * store that has the update.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-sync-pull.php';

function poll_with( array $data ): void {
    CF_TestState::$api_responses['/plugin/sync/poll'][] = [ 'ok' => true, 'status' => 200, 'data' => $data ];
}
function ack_ok( int $n = 1 ): void {
    for ( $i = 0; $i < $n; $i++ ) {
        CF_TestState::$api_responses['/plugin/commands/ack'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'ok' => true ] ];
    }
}
function calls_to( string $endpoint ): array {
    return array_values( array_filter( CF_TestState::$api_calls, fn( $c ) => $c['endpoint'] === $endpoint ) );
}
function connected_store(): void {
    CF_TestState::reset();
    CF_TestState::$options['cashflow_connection_secret'] = 'secret-xyz';
    CF_TestState::$products[501] = new WC_Product( 501 );
}
function create_command( string $key = 'cfc_aaa', string $num = '1SH-C7' ): array {
    return [
        'command_id' => 'cmd-' . $key, 'kind' => 'order.create', 'capability' => 'order.create@1',
        'idempotency_key' => $key, 'attempt' => 1,
        'order' => [
            'order_number' => $num, 'status' => 'on-hold', 'currency' => 'PKR',
            'customer' => [ 'first_name' => 'Sana', 'last_name' => 'B', 'phone' => '0300', 'email' => '' ],
            'address' => [ 'address_1' => 'St 4', 'city' => 'Lahore', 'country' => 'PK' ],
            'customer_note' => '', 'payment_method' => 'cod',
            'line_items' => [ [ 'product_id' => 501, 'quantity' => 1, 'subtotal' => '1000', 'total' => '1000' ] ],
            'shipping_lines' => [], 'fee_lines' => [], 'meta' => [],
            'money_display' => [ 'advance_amount' => '0', 'cod_amount' => '1000', 'payment_status' => 'unpaid' ],
            'expected_total' => '1000',
        ],
    ];
}

$pull = new CashFlow_Sync_Pull();

echo "── supports is declared on every poll\n";
connected_store();
poll_with( [ 'jobs' => [] ] );                 // a heartbeat
poll_with( [ 'jobs' => [] ] );                 // and another
$pull->tick();
$pull->tick();
$polls = calls_to( '/plugin/sync/poll' );
ok( 'two ticks made two polls', count( $polls ) === 2, count( $polls ) . ' polls' );
foreach ( $polls as $i => $p ) {
    ok( "poll #" . ( $i + 1 ) . " declares supports: ['order.create@1', 'catalog.push@1']",
        ( $p['body']['supports'] ?? null ) === [ 'order.create@1', 'catalog.push@1' ], json_encode( $p['body'] ) );
    ok( "poll #" . ( $i + 1 ) . " still carries version and limit",
        ( $p['body']['version'] ?? null ) === CASHFLOW_VERSION && ( $p['body']['limit'] ?? null ) === 3 );
}

echo "── a create command is run and acked\n";
connected_store();
poll_with( [ 'jobs' => [], 'commands' => [ create_command() ] ] );
ack_ok();
$pull->tick();
$acks = calls_to( '/plugin/commands/ack' );
ok( 'exactly one ack went to /plugin/commands/ack', count( $acks ) === 1, count( $acks ) . ' acks' );
$a = $acks[0]['body'] ?? [];
ok( 'the ack says created', ( $a['outcome'] ?? '' ) === 'created', json_encode( $a ) );
ok( 'it names the command and its key', ( $a['command_id'] ?? '' ) === 'cmd-cfc_aaa' && ( $a['idempotency_key'] ?? '' ) === 'cfc_aaa' );
ok( 'it carries the serialized order', isset( $a['order']['id'] ) && ( $a['order']['number'] ?? '' ) === '1SH-C7', json_encode( $a['order'] ?? null ) );
ok( 'it authenticates with the connection secret, like the job ack', ( $acks[0]['token'] ?? '' ) === 'secret-xyz' && ( $acks[0]['method'] ?? '' ) === 'POST' );
ok( 'no error key on a success', ! array_key_exists( 'error', $a ) );

echo "── the same command delivered twice makes one order\n";
connected_store();
poll_with( [ 'commands' => [ create_command() ] ] );
poll_with( [ 'commands' => [ create_command() ] ] );   // lease expired, redelivered
ack_ok( 2 );
$pull->tick();
$pull->tick();
$acks = calls_to( '/plugin/commands/ack' );
ok( 'second ack says already_created', ( $acks[1]['body']['outcome'] ?? '' ) === 'already_created', json_encode( $acks[1]['body'] ?? null ) );
ok( 'and one order exists', count( CF_TestState::$orders ) === 1 );

echo "── anything this build did not declare is acked unsupported\n";
connected_store();
poll_with( [ 'commands' => [
    array_merge( create_command( 'k1' ), [ 'capability' => 'order.create@2' ] ),
    array_merge( create_command( 'k2' ), [ 'kind' => 'order.cancel', 'capability' => 'order.cancel@1' ] ),
] ] );
ack_ok( 2 );
$pull->tick();
$acks = calls_to( '/plugin/commands/ack' );
ok( 'an undeclared capability → unsupported', ( $acks[0]['body']['outcome'] ?? '' ) === 'unsupported', json_encode( $acks[0]['body'] ?? null ) );
ok( 'an unknown kind → unsupported', ( $acks[1]['body']['outcome'] ?? '' ) === 'unsupported' );
ok( 'with a reason naming what was refused', str_contains( $acks[0]['body']['error']['message'] ?? '', 'order.create@2' ) );
ok( 'and nothing was created', count( CF_TestState::$orders ) === 0 );

echo "── a refusal and a crash each reach the backend, never silence\n";
connected_store();
$bad = create_command( 'k3' ); $bad['order']['line_items'][0]['product_id'] = 999;
poll_with( [ 'commands' => [ $bad, create_command( 'k4', '1SH-C8' ) ] ] );
ack_ok( 2 );
$pull->tick();
$acks = calls_to( '/plugin/commands/ack' );
ok( 'the bad one is acked rejected with its code', ( $acks[0]['body']['outcome'] ?? '' ) === 'rejected'
    && ( $acks[0]['body']['error']['code'] ?? '' ) === 'invalid_product', json_encode( $acks[0]['body'] ?? null ) );
ok( 'and its sibling is still created', ( $acks[1]['body']['outcome'] ?? '' ) === 'created' );

connected_store();
CF_TestState::$throw_on_calculate_totals = new RuntimeException( 'lock wait timeout' );
poll_with( [ 'commands' => [ create_command() ] ] );
ack_ok();
$pull->tick();
$acks = calls_to( '/plugin/commands/ack' );
ok( 'a crash is acked failed, with the reason', ( $acks[0]['body']['outcome'] ?? '' ) === 'failed'
    && str_contains( $acks[0]['body']['error']['message'] ?? '', 'lock wait timeout' ), json_encode( $acks[0]['body'] ?? null ) );

// A failure OUTSIDE create()'s own transaction (the idempotency lookup hits a
// database error) must still be acked, and must not stop the next command.
connected_store();
poll_with( [ 'commands' => [ create_command( 'k5' ), create_command( 'k6', '1SH-C9' ) ] ] );
ack_ok( 2 );
CF_TestState::$throw_on_get_orders = new RuntimeException( 'MySQL server has gone away' );
$pull->tick();
$acks = calls_to( '/plugin/commands/ack' );
ok( 'a crash before the transaction is still acked failed',
    ( $acks[0]['body']['outcome'] ?? '' ) === 'failed'
    && str_contains( $acks[0]['body']['error']['message'] ?? '', 'gone away' ), json_encode( $acks[0]['body'] ?? null ) );
ok( 'and the next command is still processed and acked', count( $acks ) === 2, count( $acks ) . ' acks' );

echo "── a command with no id cannot be acked, so it is logged\n";
connected_store();
poll_with( [ 'commands' => [ [ 'kind' => 'order.create' ] ] ] );
$pull->tick();
ok( 'no ack is attempted', count( calls_to( '/plugin/commands/ack' ) ) === 0 );
ok( 'the skip is logged as an error', (bool) array_filter( CF_TestState::$log,
    fn( $l ) => $l['status'] === 'error' && str_contains( $l['message'], 'no command_id' ) ) );

summary();
