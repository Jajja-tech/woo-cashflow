<?php
/**
 * A REFUSED BATCH IS SPLIT; A TRY COUNTS ONLY AGAINST A PRODUCT; A STOP IS NOBODY'S TRY.
 *
 * Overrides applied on top of the task-10 brief (Task 9 was reviewed and
 * changed after the plan was written):
 *  - classify() calls has_shape() as the ONE test that a 2xx is a success, so
 *    every scripted "success" response here carries the contract's three
 *    arrays — including ok200(), which the brief's version did not.
 *  - send_split() takes and re-encodes a $body string (post() never
 *    re-encodes an array — see the two tests at the bottom).
 *  - a fail_row() outcome of 'error' (a database failure, already recorded)
 *    must not be overwritten by note_failure() on the panel.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-sync-pull.php';
require_once __DIR__ . '/../includes/class-catalog.php';

$T = 1790000000.0;
function store(): void {
    global $T;
    $T = 1790000000.0;
    CF_TestState::reset();
    CF_TestState::$options['cashflow_catalog_db_version'] = '1';
    CF_TestState::$options['cashflow_connection_secret']  = 'secret-xyz';
    CF_TestState::$options['siteurl'] = 'https://zensha.pk';
    CF_TestState::$options['home']    = 'https://zensha.pk';
    CF_TestState::$catalog_tables['wp_cashflow_catalog_queue'] = true;
    // A list opened just now: no hourly list is due while these tests send products.
    CF_TestState::$options['cashflow_catalog_list'] = [ 'last_opened_at' => 1790000000.0 ];
    CashFlow_Catalog::$clock = function () { global $T; return $T; };
}
function saves( array $ids ): void {
    foreach ( $ids as $id ) {
        CF_TestState::$products[ $id ] = new WC_Product( $id, 0, 'publish', [ 'name' => "P$id" ] );
        CashFlow_Catalog::enqueue( $id, 'save' );
    }
}
/** Like saves(), but queued under an arbitrary reason — the hourly list ('asked') or "Resend catalogue" ('resend'). */
function queued( array $ids, string $reason ): void {
    foreach ( $ids as $id ) {
        CF_TestState::$products[ $id ] = new WC_Product( $id, 0, 'publish', [ 'name' => "P$id" ] );
        CashFlow_Catalog::enqueue( $id, $reason );
    }
}
/** Script $n responses decided per request from the decoded body; each request takes $secs seconds. */
function respond( int $n, callable $fn, float $secs = 0.0 ): void {
    for ( $i = 0; $i < $n; $i++ ) {
        CF_TestState::$api_responses['/plugin/catalog/products'][] = function ( $call ) use ( $fn, $secs ) {
            global $T;
            $T += $secs;
            return $fn( json_decode( $call['body'], true ) );
        };
    }
}
// The contract's real shape [wire-contract.md]: a 2xx MUST carry applied,
// trashed and unchanged as arrays, or classify() treats it as a stop
// (has_shape() is the one test of success). A response missing them — even
// a 2xx — must never be read as "nothing to do here, carry on".
function ok200(): array {
    return [ 'ok' => true, 'status' => 200, 'data' => [ 'applied' => [], 'trashed' => [], 'unchanged' => [], 'list_wanted' => false ] ];
}
function err( int $code, $data ): array { return [ 'ok' => false, 'status' => $code, 'data' => $data ]; }
function bodies(): array {
    $out = [];
    foreach ( CF_TestState::$api_calls as $c ) {
        if ( '/plugin/catalog/products' === $c['endpoint'] ) {
            $out[] = array_column( json_decode( $c['body'], true )['products'] ?? [], 'id' );
        }
    }
    return $out;
}
/**
 * The CRITICAL stall, reproduced under a given reason [review-4]: 25 rows
 * queued that way, one of them poisoned at 4 s per 500 ("about 2s or more").
 * Under the bug (drain_solo() calling claim($token,1,true), which only ever
 * matches 'save'/'removed'), a queue of asked/resend rows was NEVER drained
 * one at a time at all — the same large split reformed every run and cut
 * short before isolating the poison, forever, at zero tries.
 */
function stall_repro( string $label, string $reason, int $base ): void {
    global $T;
    store();
    queued( range( $base + 1, $base + 25 ), $reason );
    $poison_id = $base + 25;
    for ( $i = 0; $i < 400; $i++ ) {
        CF_TestState::$api_responses['/plugin/catalog/products'][] = function ( $call ) use ( $poison_id ) {
            global $T;
            $body       = json_decode( $call['body'], true );
            $has_poison = in_array( $poison_id, array_column( $body['products'] ?? [], 'id' ), true );
            if ( $has_poison ) {
                $T += 4.0;
                return err( 500, [ 'error' => 'catalogue_write_failed' ] );
            }
            return ok200();
        };
    }
    run_job();
    ok( "$label: a panel note appears at the very first run", str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'will be sent one at a time' ) );
    for ( $run = 2; $run <= 9; $run++ ) {
        $T += 61;
        queued( [ $base + 100 + $run ], $reason );   // company each retry [IMPORTANT 1]
        run_job();
    }
    ok( "$label: within bounded runs the poison product parks", null !== row_of( $poison_id ) && null !== row_of( $poison_id )['parked_at'] );
    ok( "$label: the other 24 of the original 25 all went through", [] === array_filter( range( $base + 1, $base + 24 ), function ( $id ) { return null !== row_of( $id ); } ) );
    ok( "$label: the solo list is empty again", [] === CashFlow_Catalog::solo_ids() );
}
function row_of( int $id ): ?array {
    foreach ( CF_TestState::$catalog_queue as $r ) { if ( (int) $r['product_id'] === $id ) { return $r; } }
    return null;
}
function run_job(): void { ( new CashFlow_Catalog() )->tick(); }

echo "── 413: the batch is split, and nobody's try is counted\n";
store();
saves( [ 3001, 3002, 3003, 3004 ] );
respond( 1, function () { return err( 413, [ 'error' => 'request entity too large' ] ); } );
respond( 2, function () { return ok200(); } );
run_job();
ok( 'sent as 4, then 2 + 2', bodies() === [ [ 3001, 3002, 3003, 3004 ], [ 3001, 3002 ], [ 3003, 3004 ] ], json_encode( bodies() ) );
ok( 'and all of it went', CF_TestState::$catalog_queue === [] );
ok( 'every split half was sent as its own pre-encoded string, not re-encoded from an array',
    [] === array_filter( CF_TestState::$api_calls, function ( $c ) { return '/plugin/catalog/products' === $c['endpoint'] && ! is_string( $c['body'] ); } ) );

echo "── 500 on one product: split down to it; only IT counts a try\n";
store();
saves( [ 3001, 3002, 3003, 3004 ] );
$poison = function ( $body ) {
    return in_array( 3003, array_column( $body['products'] ?? [], 'id' ), true )
        ? err( 500, [ 'error' => 'catalogue_write_failed' ] ) : ok200();
};
respond( 5, $poison );
run_job();
ok( 'split 4 → 2 + 2 → 1 + 1', bodies() === [ [ 3001, 3002, 3003, 3004 ], [ 3001, 3002 ], [ 3003, 3004 ], [ 3003 ], [ 3004 ] ], json_encode( bodies() ) );
$r = row_of( 3003 );
ok( 'the poison product has one try counted, and waits for the next run', null !== $r && '1' === $r['attempts'] && null === $r['token'] && null !== $r['retry_at'] );
ok( 'every other product went', count( CF_TestState::$catalog_queue ) === 1 );
ok( 'the panel names the product and the server\'s reason', str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'Sending product 3003' )
    && str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'catalogue_write_failed' ) );

echo "── it parks on the 5th failed try, and stops being sent\n";
for ( $run = 2; $run <= 5; $run++ ) {
    $T += 61;
    // A companion product each run [IMPORTANT 1]: an isolated retry with
    // NOTHING else going through the same run would read as an outage, not
    // this product's own fault, and never count a try at all.
    saves( [ 3900 + $run ] );
    respond( 5, $poison );
    run_job();
}
ok( 'after 5 tries it is parked', null !== row_of( 3003 ) && null !== row_of( 3003 )['parked_at'] && '5' === row_of( 3003 )['attempts'] );
ok( 'the panel says parked', str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'parked after 5 tries' ) );
$before = count( CF_TestState::$api_calls );
$T += 61;
run_job();
ok( 'a parked product is not sent again', count( CF_TestState::$api_calls ) === $before );

// The "500 on one product" test above IS the mixed case [IMPORTANT 1]: other
// products go through first, corroborating the poison as ITS OWN fault
// rather than an outage — that is why it alone counts a try.
echo "── IMPORTANT 1/2: a fast 500 for EVERYONE stops at the first isolated failure, parks nothing, over many runs, and shows the outage\n";
store();
saves( range( 9001, 9060 ) );   // 60 real saves, nobody spared
respond( 400, function () { return err( 500, [ 'error' => 'catalogue_write_failed' ] ); } );   // fails for every single one of them, fast — including the corroborating empty-products ping
$per_run = [];
for ( $pass = 1; $pass <= 3; $pass++ ) {
    $before = count( CF_TestState::$api_calls );
    run_job();
    $per_run[] = count( CF_TestState::$api_calls ) - $before;
}
$touched = array_filter( CF_TestState::$catalog_queue, function ( $r ) { return '0' !== $r['attempts'] || null !== $r['parked_at']; } );
ok( 'nothing ever corroborated any one product, so nothing ever counted a try', [] === $touched, count( $touched ) . ' rows touched' );
ok( 'all 60 are still pending, none lost, none parked', count( CF_TestState::$catalog_queue ) === 60 );
// [IMPORTANT 2] Once a single product's 500 has nothing to corroborate it,
// the run stops CLAIMING further batches — it does not keep exploring the
// whole tree looking for more failures. Logged, not just asserted: this is
// the number that proves it never approaches 60 (let alone 2*60-1).
fwrite( STDERR, "requests per run against a fully-failing 60-product queue: " . implode( ', ', $per_run ) . "\n" );
ok( 'a bounded handful of requests per run, nothing like 60', max( $per_run ) < 15, implode( ',', $per_run ) );
ok( 'the panel names it an outage, not a per-product failure',
    str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'outage' )
    && str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'did not answer an empty request either' ) );

echo "── a 400 (a malformed envelope) is NEVER split — every row costs one try, in ONE request [IMPORTANT 2]\n";
store();
saves( [ 4001, 4002, 4003, 4004 ] );
respond( 1, function () { return err( 400, [ 'error' => 'products_must_be_an_array' ] ); } );
run_job();
ok( 'exactly one request for the whole batch of 4 — never 2n-1', count( bodies() ) === 1, count( bodies() ) . ' requests' );
ok( 'every row in the body counted a try directly, none split off on its own',
    '1' === ( row_of( 4001 )['attempts'] ?? null ) && '1' === ( row_of( 4002 )['attempts'] ?? null )
    && '1' === ( row_of( 4003 )['attempts'] ?? null ) && '1' === ( row_of( 4004 )['attempts'] ?? null ) );
ok( 'noted once, the run then stops', str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'Sending products failed' ) );

echo "── a stop is nobody's try\n";
$stops = [
    '401'       => err( 401, [ 'error' => 'invalid connection secret' ] ),
    '403 site'  => err( 403, [ 'error' => 'site_mismatch', 'reason' => 'host_differs' ] ),
    '403 conn'  => err( 403, [ 'error' => 'connection_not_eligible' ] ),
    '404'       => err( 404, null ),
    '429'       => err( 429, [ 'message' => 'Too many requests' ] ),
    '502'       => err( 502, null ),
    'transport' => [ 'ok' => false, 'status' => 0, 'error' => 'cURL error 28: Operation timed out after 10001 milliseconds', 'data' => null ],
];
foreach ( $stops as $name => $answer ) {
    store();
    saves( [ 5001, 5002 ] );
    respond( 1, function () use ( $answer ) { return $answer; } );   // a stop never issues a second request this run
    run_job();
    $rows = array_values( CF_TestState::$catalog_queue );
    ok( "$name: one request, then the run ends", count( bodies() ) === 1, count( bodies() ) . ' requests' );
    ok( "$name: both rows are back, no try counted", 2 === count( $rows )
        && [] === array_filter( $rows, function ( $r ) { return '0' !== $r['attempts'] || null !== $r['token'] || null !== $r['retry_at']; } ) );
}

echo "── what a stop leaves on the panel\n";
store();
saves( [ 5001 ] );
respond( 1, function () { return err( 403, [ 'error' => 'site_mismatch', 'reason' => 'host_differs' ] ); } );
run_job();
$ref = CashFlow_Catalog::stats()['site_refusal'] ?? null;
ok( 'a site refusal is recorded with the reason and what was sent', ( $ref['reason'] ?? '' ) === 'host_differs'
    && ( $ref['site'] ?? null ) === [ 'siteurl' => 'https://zensha.pk', 'home' => 'https://zensha.pk' ] );
respond( 1, function () { return ok200(); } );
run_job();
ok( 'the next 200 clears it', null === ( CashFlow_Catalog::stats()['site_refusal'] ?? null ) );

store();
saves( [ 5001 ] );
respond( 1, function () { return err( 401, [ 'error' => 'invalid connection secret' ] ); } );
run_job();
ok( 'a 401 says not connected', true === ( CashFlow_Catalog::stats()['not_connected'] ?? null ) );
respond( 1, function () { return ok200(); } );
run_job();
ok( 'and a 200 clears it', false === ( CashFlow_Catalog::stats()['not_connected'] ?? null ) );

store();
saves( [ 5001 ] );
respond( 1, function () { return err( 404, null ); } );
run_job();
ok( 'a 404 is named: the backend has no catalogue route yet', str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'does not accept the catalogue yet' ) );

echo "── a product that cannot be read counts a try, without a request\n";
store();
CF_TestState::$terms[92] = (object) [ 'term_id' => 92, 'name' => 'Scarf', 'slug' => 'scarf', 'taxonomy' => 'product_cat', 'count' => 1 ];
CF_TestState::$products[6001] = new WC_Product( 6001, 0, 'publish', [ 'category_ids' => [ 92 ] ] );
CashFlow_Catalog::enqueue( 6001, 'save' );
CF_TestState::$terms_error = 'Deadlock found';
run_job();
ok( 'no request was made', [] === bodies() );
ok( 'one try counted against it', '1' === ( row_of( 6001 )['attempts'] ?? null ) );
ok( 'and the panel says why', str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'Product 6001 could not be read' ) );

echo "── the budget runs out mid-split: the unsent half is untouched\n";
store();
saves( [ 7001, 7002, 7003, 7004 ] );
respond( 1, function () { return err( 413, [ 'error' => 'request entity too large' ] ); }, 8.0 );
respond( 2, function () { return ok200(); }, 8.0 );
run_job();
ok( 'split once, second half never started (9 s left)', bodies() === [ [ 7001, 7002, 7003, 7004 ], [ 7001, 7002 ] ], json_encode( bodies() ) );
ok( 'the second half is back with no try counted', '0' === ( row_of( 7003 )['attempts'] ?? null ) && '0' === ( row_of( 7004 )['attempts'] ?? null ) );
ok( 'a budget cutoff — not any refusal — puts the untouched ids on the solo list [CRITICAL]', [ 7003, 7004 ] === CashFlow_Catalog::solo_ids() );
ok( 'and it is noted, at the very first run it happens', str_contains( (string) CashFlow_Catalog::stats()['last_error'], '2 products will be sent one at a time' ) );

echo "── CRITICAL: a slow server + one poison product must never restart the same split forever with nothing counted\n";
store();
saves( range( 2001, 2025 ) );   // 25 real saves, one of them poisoned
$poison_id = 2025;
for ( $i = 0; $i < 400; $i++ ) {
    CF_TestState::$api_responses['/plugin/catalog/products'][] = function ( $call ) use ( $poison_id ) {
        global $T;
        $body       = json_decode( $call['body'], true );
        $has_poison = in_array( $poison_id, array_column( $body['products'] ?? [], 'id' ), true );
        if ( $has_poison ) {
            $T += 4.0;   // "about 2 s or more per 500" [CRITICAL]
            return err( 500, [ 'error' => 'catalogue_write_failed' ] );
        }
        return ok200();
    };
}
run_job();   // RUN 1
ok( 'a panel note appears at the very first run', str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'will be sent one at a time' ) );
ok( 'the split made real progress even though it was cut short — not everything released untried', count( CF_TestState::$catalog_queue ) < 25, count( CF_TestState::$catalog_queue ) . ' left' );
$left_ids = array_map( 'intval', array_column( CF_TestState::$catalog_queue, 'product_id' ) );
sort( $left_ids );
$solo1 = CashFlow_Catalog::solo_ids();
sort( $solo1 );
ok( 'whatever is left is exactly what the solo list now holds', $left_ids === $solo1, json_encode( $left_ids ) . ' vs ' . json_encode( $solo1 ) );

saves( [ 2100 ] );   // a newer save, arriving while the poison product is still being untangled
$T += 61;
run_job();   // RUN 2 — drains the solo list one at a time; the two survivors sent alongside the poison corroborate its first counted try; the newer save goes through too

for ( $run = 3; $run <= 9; $run++ ) {
    $T += 61;
    // Company again [IMPORTANT 1]: once isolated, the poison product alone
    // has nothing to corroborate a fault as ITS OWN, and would otherwise
    // read as an outage forever, exactly like the earlier parking test.
    saves( [ 2100 + $run ] );
    run_job();
}
ok( 'the poison product parks after its tries', null !== row_of( $poison_id ) && null !== row_of( $poison_id )['parked_at']
    && '5' === row_of( $poison_id )['attempts'] );
ok( 'the other 24 of the original 25 all went through', [] === array_filter( range( 2001, 2024 ), function ( $id ) { return null !== row_of( $id ); } ) );
ok( 'the newer save also went through', null === row_of( 2100 ) );
ok( 'the solo list is empty again, nothing left waiting on one-at-a-time treatment', [] === CashFlow_Catalog::solo_ids() );

echo "── CRITICAL: the same stall, reproduced with 'asked' rows (the hourly list) [review-4]\n";
stall_repro( 'asked', 'asked', 3000 );

echo "── CRITICAL: the same stall, reproduced with 'resend' rows (Resend catalogue) [review-4]\n";
stall_repro( 'resend', 'resend', 4000 );

echo "── IMPORTANT 1: a healthy server + slow product READS — a depth-0 cutoff is not a refusal\n";
store();
saves( range( 5001, 5025 ) );   // 25 products; the server never refuses anything, ever
respond( 5, function () { return ok200(); } );
// The slowness is in READING the products, not the network — this batch is
// never even attempted (can_start() fails before send_split()'s first call,
// depth 0), so it must read exactly like it always did: released untried,
// no solo list, no refusal note [IMPORTANT 1].
CF_TestState::$on_wc_get_product = function () { global $T; $T += 0.6; };
run_job();
CF_TestState::$on_wc_get_product = null;
ok( 'no request was even attempted — the reads alone exhausted the budget', [] === bodies() );
ok( 'nothing lands on the solo list', [] === CashFlow_Catalog::solo_ids() );
ok( 'no refusal note appears', ! str_contains( (string) ( CashFlow_Catalog::stats()['last_error'] ?? '' ), 'refused a batch' ) );
ok( 'every row is back, untouched', 25 === count( CF_TestState::$catalog_queue )
    && [] === array_filter( CF_TestState::$catalog_queue, function ( $r ) { return '0' !== $r['attempts'] || null !== $r['token']; } ) );
respond( 5, function () { return ok200(); } );
run_job();   // reads are fast again
ok( 'throughput stays BATCHED — nothing forced this onto a one-at-a-time path', bodies() === [ [ 5001, 5002, 5003, 5004, 5005, 5006, 5007, 5008, 5009, 5010, 5011, 5012, 5013, 5014, 5015, 5016, 5017, 5018, 5019, 5020, 5021, 5022, 5023, 5024, 5025 ] ] );

echo "── NEW RULE: a lone single's 500 is corroborated with an empty products request — both branches\n";
store();
saves( [ 6501 ] );   // alone: nothing else this run to corroborate it any other way
respond( 1, function () { return err( 500, [ 'error' => 'catalogue_write_failed' ] ); } );
respond( 1, function ( $body ) {
    ok( 'the corroborating request carries an empty products array and the usual site block',
        [] === ( $body['products'] ?? 'missing' ) && isset( $body['site'] ) );
    return ok200();
} );
run_job();
ok( 'the ping succeeded: the server is up, so the try counts against the product', '1' === ( row_of( 6501 )['attempts'] ?? null ) );
ok( 'no outage wording — the ping proved it is not one', ! str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'outage' ) );

store();
saves( [ 6502 ] );
respond( 1, function () { return err( 500, [ 'error' => 'catalogue_write_failed' ] ); } );
respond( 1, function () { return err( 502, null ); } );   // the ping ALSO fails
run_job();
ok( 'the ping also failed: no try counted, an outage', '0' === ( row_of( 6502 )['attempts'] ?? null ) && null === row_of( 6502 )['token'] );
ok( '"outage" appears ONLY because the empty request also failed', str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'outage' )
    && str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'did not answer an empty request either' ) );

echo "── minor: add_to_solo() returns what is actually listed, after capping at MAX_PRODUCTS — never the uncapped merge\n";
store();
$ref_add = new ReflectionMethod( 'CashFlow_Catalog', 'add_to_solo' );
$first_batch = $ref_add->invoke( null, range( 9101, 9120 ) );      // 20 — under the cap, nothing to trim yet
ok( 'under the cap: everything given comes back', count( $first_batch ) === 20 );
$second_batch = $ref_add->invoke( null, range( 9121, 9140 ) );     // 20 more — 40 total, well past MAX_PRODUCTS
ok( 'over the cap: the RETURNED list is capped at MAX_PRODUCTS, not the 40 merged', count( $second_batch ) === 25 );
ok( 'and it is exactly what got stored, so a caller counting it never overstates the panel', $second_batch === CashFlow_Catalog::solo_ids() );

echo "── a database failure while recording a try is the panel's last word, not overwritten by the send failure\n";
store();
// 8000 is company [IMPORTANT 1]: with nothing else through this run, this
// would be decided as an outage before fail_row ever ran at all.
saves( [ 8000, 8001 ] );
respond( 5, function ( $body ) {
    return in_array( 8001, array_column( $body['products'] ?? [], 'id' ), true )
        ? err( 500, [ 'error' => 'catalogue_write_failed' ] ) : ok200();
} );
CF_TestState::$db_error_on = 'attempts = attempts + 1, token = NULL';   // release_failed only
run_job();
CF_TestState::$db_error_on = null;
ok( 'the row is untouched by the failed write — still claimed, not lost', 1 === count( CF_TestState::$catalog_queue ) );
ok( 'the panel keeps the database failure as the last word', str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'catalogue queue could not be updated' ) );
ok( 'the send failure never overwrote it', ! str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'Sending product 8001' ) );

echo "── post()'s non-string guard is a SAFEGUARD for a path production never takes — pack()/products_body() always hand it an already-encoded string\n";
store();
$ref_post = new ReflectionMethod( 'CashFlow_Catalog', 'post' );
$before_calls = count( CF_TestState::$api_calls );
$res = $ref_post->invoke( new CashFlow_Catalog(), 'secret-xyz', CashFlow_Catalog::EP_PRODUCTS, [ 'not' => 'a string' ] );
ok( 'if it ever were reached, it would refuse before the network, not let wp_json_encode re-encode it', false === $res['ok'] && count( CF_TestState::$api_calls ) === $before_calls );
ok( 'and it is recorded on the panel', str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'not a pre-encoded string' ) );

echo "── classify() is the wire's table\n";
$shape = [ 'applied' => [], 'trashed' => [], 'unchanged' => [] ];
$c = function ( $code, $data = null ) use ( $shape ) {
    return CashFlow_Catalog::classify( [ 'ok' => $code >= 200 && $code < 300, 'status' => $code, 'data' => $data ?? $shape ] );
};
ok( '2xx ok (with a real contract body)', $c( 200 ) === 'ok' );
ok( '400 is never split — a malformed envelope, its own outcome [IMPORTANT 2]', $c( 400, [ 'error' => 'products_must_be_an_array' ] ) === 'malformed' );
ok( '413 splits regardless of body', $c( 413 ) === 'split' );
ok( '500 splits ONLY when data.error is one of the three catalogue codes the wire names [IMPORTANT 1]',
    $c( 500, [ 'error' => 'catalogue_write_failed' ] ) === 'split'
    && $c( 500, [ 'error' => 'catalogue_list_failed' ] ) === 'split'
    && $c( 500, [ 'error' => 'catalogue_check_failed' ] ) === 'split'
    && $c( 500, [ 'error' => 'something_else' ] ) === 'stop'
    && $c( 500, null ) === 'stop'
    && $c( 500, '<html>server error</html>' ) === 'stop' );
ok( 'everything else stops', $c( 0 ) === 'stop' && $c( 401 ) === 'stop' && $c( 403 ) === 'stop' && $c( 404 ) === 'stop'
    && $c( 409 ) === 'stop' && $c( 429 ) === 'stop' && $c( 502 ) === 'stop' && $c( 503 ) === 'stop' && $c( 504 ) === 'stop' );
ok( 'a 2xx without the contract\'s shape is a stop, not ok — the status alone never decides success',
    'stop' === CashFlow_Catalog::classify( [ 'ok' => true, 'status' => 200, 'data' => [ 'foo' => 'bar' ] ] )
    && 'stop' === CashFlow_Catalog::classify( [ 'ok' => true, 'status' => 200 ] )
    && 'stop' === CashFlow_Catalog::classify( [ 'ok' => true, 'status' => 200, 'data' => null ] ) );

CashFlow_Catalog::$clock = null;
summary();
