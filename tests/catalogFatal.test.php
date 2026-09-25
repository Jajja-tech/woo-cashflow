<?php
/**
 * A REAL PHP FATAL IN THE CATALOGUE JOB DOES NOT STOP THE ORDER POLL. [NB9]
 *
 * A child PHP process runs the catalogue job and dies of memory exhaustion
 * while reading a product — a real E_ERROR, not an exception: tick()'s
 * catch ( Throwable ) never sees it. The child writes down everything it
 * left behind as it dies (shutdown functions still run). This process then
 * starts from exactly that state and runs the ORDER poll, then the catalogue.
 *
 * Not provable here: Action Scheduler itself (it claims by priority, 10 before
 * 20 — pinned in catalogJob.test.php, checked live in the release checks).
 *
 * Deviation from the task brief, noted per implementer-instructions.md: the
 * brief's scripted /plugin/catalog/products reply for the recovery send was
 * `{"list_wanted": false}` alone. classify() only calls a 2xx 'ok' when it
 * carries has_shape()'s three arrays (applied/trashed/unchanged) — the
 * wire contract's own shape, task 9's guard against a 2xx-but-empty reply
 * being read as "nothing was written". That reply would classify 'stop',
 * release the row untried, and never send anything — the opposite of what
 * this step exists to prove. Fixed to carry `applied: [501]` alongside it.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-sync-pull.php';
require_once __DIR__ . '/../includes/class-catalog.php';

$tmp   = sys_get_temp_dir() . '/cf-fatal-' . getmypid();
@mkdir( $tmp );
$state = "$tmp/state.json";
$child = "$tmp/catalogue-dies.php";

file_put_contents( $child, strtr( <<<'CHILD'
<?php
require_once __BOOT__;
require_once __PULL__;
require_once __CATALOG__;
CF_TestState::$options['cashflow_catalog_db_version'] = '1';
CF_TestState::$options['cashflow_connection_secret']  = 'secret-xyz';
CF_TestState::$options['siteurl'] = 'https://zensha.pk';
CF_TestState::$options['home']    = 'https://zensha.pk';
CF_TestState::$options['cashflow_catalog_list'] = [ 'last_opened_at' => 1790000000.0 ];
CF_TestState::$catalog_tables['wp_cashflow_catalog_queue'] = true;
CashFlow_Catalog::$clock = function () { return 1790000000.0; };
CF_TestState::$products[501] = new WC_Product( 501, 0, 'publish', [ 'name' => 'Scarf' ] );
CashFlow_Catalog::enqueue( 501, 'save' );
register_shutdown_function( function () {
    file_put_contents( __STATE__, json_encode( [
        'options' => CF_TestState::$options,
        'queue'   => CF_TestState::$catalog_queue,
        'next'    => CF_TestState::$catalog_queue_next,
        'tables'  => CF_TestState::$catalog_tables,
        'calls'   => count( CF_TestState::$api_calls ),
    ] ) );
} );
// A REAL fatal: asking for 1 GB under a 64 MB limit is E_ERROR. The
// allocation is refused outright, so the shutdown function has room to run.
CF_TestState::$on_product_get = function () {
    ini_set( 'memory_limit', '64M' );
    $hog = str_repeat( 'x', 1024 * 1024 * 1024 );
};
( new CashFlow_Catalog() )->tick();
echo "UNREACHABLE\n";
CHILD
, [
    '__BOOT__'    => var_export( __DIR__ . '/bootstrap.php', true ),
    '__PULL__'    => var_export( __DIR__ . '/../includes/class-sync-pull.php', true ),
    '__CATALOG__' => var_export( __DIR__ . '/../includes/class-catalog.php', true ),
    '__STATE__'   => var_export( $state, true ),
] ) );

echo "── the catalogue job dies of a real fatal\n";
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $child ) . ' 2>&1', $out, $exit );
$text = implode( "\n", $out );
ok( 'the child exited 255 (a fatal error)', 255 === $exit, "exit $exit" );
ok( 'of memory exhaustion — uncatchable', str_contains( $text, 'Allowed memory size' ), $text );
ok( 'nothing after it ran', ! str_contains( $text, 'UNREACHABLE' ) );
$left = json_decode( (string) @file_get_contents( $state ), true );
ok( 'what it left behind was captured', is_array( $left ) );
ok( 'tick()\'s own crash handler never ran (proof it was not an exception)',
    ! str_contains( (string) ( $left['options']['cashflow_catalog_stats']['last_error'] ?? '' ), 'crashed' ) );
ok( 'it died before sending anything', 0 === ( $left['calls'] ?? -1 ) );
$row = array_values( $left['queue'] ?? [] )[0] ?? [];
ok( 'and left its claim on the row behind', null !== ( $row['token'] ?? null ) && '501' === ( $row['product_id'] ?? '' ) );

echo "── the order poll runs normally from exactly that state\n";
CF_TestState::reset();
CF_TestState::$options            = $left['options'];
CF_TestState::$catalog_queue      = $left['queue'];
CF_TestState::$catalog_queue_next = $left['next'];
CF_TestState::$catalog_tables     = $left['tables'];
CF_TestState::$api_responses['/plugin/sync/poll'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'jobs' => [], 'commands' => [] ] ];
( new CashFlow_Sync_Pull() )->tick();
$polls = array_values( array_filter( CF_TestState::$api_calls, function ( $c ) { return '/plugin/sync/poll' === $c['endpoint']; } ) );
ok( 'one poll, as always', 1 === count( $polls ) );
ok( 'declaring what it always declares', ( $polls[0]['body']['supports'] ?? null ) === [ 'order.create@1', 'catalog.push@1' ] );
$pull = get_option( 'cashflow_sync_pull_stats', [] );
ok( 'recorded as a healthy poll, no error', ! empty( $pull['last_poll_at'] ) && empty( $pull['last_error'] ) );

echo "── the catalogue recovers after its lease\n";
CF_TestState::$products[501] = new WC_Product( 501, 0, 'publish', [ 'name' => 'Scarf' ] );
$T = 1790000000.0 + 100;
CashFlow_Catalog::$clock = function () use ( &$T ) { return $T; };
( new CashFlow_Catalog() )->tick();
$sends = function () { return array_values( array_filter( CF_TestState::$api_calls, function ( $c ) { return '/plugin/catalog/products' === $c['endpoint']; } ) ); };
ok( 'within the 5-minute lease the dead run\'s row is left alone', [] === $sends() );
$T = 1790000000.0 + 301;
CF_TestState::$api_responses['/plugin/catalog/products'][] = [ 'ok' => true, 'status' => 200,
    'data' => [ 'applied' => [ 501 ], 'trashed' => [], 'unchanged' => [], 'list_wanted' => false ] ];
( new CashFlow_Catalog() )->tick();
ok( 'after it, the product is sent', 1 === count( $sends() ) && [ 501 ] === array_column( json_decode( $sends()[0]['body'], true )['products'] ?? [], 'id' ) );
ok( 'and the queue is empty', [] === CF_TestState::$catalog_queue );

echo "── a product that kills the job every time is parked after five deaths\n";
CF_TestState::$catalog_queue = [ 7 => [ 'id' => '7', 'product_id' => '501', 'reason' => 'save', 'token' => 'dead-4th',
    'attempts' => '4', 'queued_at' => '2026-09-20 00:00:00', 'claimed_at' => '2026-09-20 00:00:00',
    'retry_at' => null, 'parked_at' => null, 'pending_key' => null ] ];
CF_TestState::$catalog_queue_next = 8;
$before = count( $sends() );
$T += 600;
( new CashFlow_Catalog() )->tick();
ok( 'the fifth death parks it: never read again', null !== CF_TestState::$catalog_queue[7]['parked_at'] && '5' === CF_TestState::$catalog_queue[7]['attempts'] );
ok( 'and nothing was sent for it', count( $sends() ) === $before );

@unlink( $child );
@unlink( $state );
@rmdir( $tmp );
CashFlow_Catalog::$clock = null;
summary();
