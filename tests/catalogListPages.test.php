<?php
/**
 * THE LIST: ONE DIRECT QUERY, TIME-SIZED PAGES, AND THE SERVER DECIDES.
 *
 * The plugin keeps no list state it cannot lose [N4]: after any 409 it does
 * what the server says. A database error stops the list — it is never sent as
 * an empty page, because a complete list with pages missing would trash the
 * products it left out [NB3].
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-sync-pull.php';
require_once __DIR__ . '/../includes/class-catalog.php';

$T = 1790000000.0;
const LIST_ID  = '5b0f3c1e-2a8d-4c1f-9a51-0c9f3f1d2e77';
const LIST_ID2 = '0e9d4a77-1111-4c1f-9a51-0c9f3f1d2e77';
function store( array $ids = [ 10, 13, 14 ], int $page_size = 1000 ): void {
    global $T;
    $T = 1790000000.0;
    CF_TestState::reset();
    CF_TestState::$options['cashflow_catalog_db_version'] = '1';
    CF_TestState::$options['cashflow_connection_secret']  = 'secret-xyz';
    CF_TestState::$options['siteurl'] = 'https://zensha.pk';
    CF_TestState::$options['home']    = 'https://zensha.pk';
    CF_TestState::$catalog_tables['wp_cashflow_catalog_queue'] = true;
    CF_TestState::$options['cashflow_catalog_list'] = [ 'list_id' => LIST_ID, 'after_id' => 0, 'page_size' => $page_size, 'asked' => 0, 'last_opened_at' => $T ];
    CashFlow_Catalog::$clock = function () { global $T; return $T; };
    foreach ( $ids as $id ) {
        CF_TestState::$posts[ $id ] = [ 'type' => 'product', 'status' => 'publish', 'parent' => 0 ];
        CF_TestState::$products[ $id ] = new WC_Product( $id, 0, 'publish', [ 'name' => "P$id", 'price' => (string) ( 100 + $id ) ] );
    }
    CF_TestState::$posts[11] = [ 'type' => 'product', 'status' => 'trash', 'parent' => 0 ];              // outside the set
    CF_TestState::$posts[12] = [ 'type' => 'product_variation', 'status' => 'publish', 'parent' => 10 ]; // not a parent
}
function page_answers( int $n, callable $fn ): void {
    for ( $i = 0; $i < $n; $i++ ) {
        CF_TestState::$api_responses['/plugin/catalog/list/page'][] = function ( $call ) use ( $fn ) { return $fn( json_decode( $call['body'], true ) ); };
    }
}
function ok_page( array $data ): array { return [ 'ok' => true, 'status' => 200, 'data' => $data + [ 'need' => [], 'complete' => false, 'outcome' => null, 'trashed' => 0, 'trash_refused' => false, 'warnings' => [] ] ]; }
function conflict( array $data ): array { return [ 'ok' => false, 'status' => 409, 'data' => $data ]; }
/** The server's normal answer: position = the last id sent; complete echoed. */
function follow( array $extra = [] ): callable {
    return function ( $b ) use ( $extra ) {
        $last = $b['rows'] ? end( $b['rows'] )[0] : $b['after_id'];
        return ok_page( $extra + [ 'after_id' => $last, 'complete' => $b['complete'], 'outcome' => $b['complete'] ? 'trashed' : null ] );
    };
}
/** Like follow(), but the RESPONSE ITSELF costs $cost seconds — a slow list-page server, distinct from a slow build. */
function slow_follow( float $cost, array $extra = [] ): callable {
    return function ( $b ) use ( $cost, $extra ) {
        global $T;
        $T += $cost;
        $last = $b['rows'] ? end( $b['rows'] )[0] : $b['after_id'];
        return ok_page( $extra + [ 'after_id' => $last, 'complete' => $b['complete'], 'outcome' => $b['complete'] ? 'trashed' : null ] );
    };
}
function pages(): array {
    return array_values( array_map( function ( $c ) { return json_decode( $c['body'], true ); },
        array_filter( CF_TestState::$api_calls, function ( $c ) { return '/plugin/catalog/list/page' === $c['endpoint']; } ) ) );
}
function run_job(): void { ( new CashFlow_Catalog() )->tick(); }
function fp( int $id ): string { return CashFlow_Catalog::payload( CF_TestState::$products[ $id ] )['fingerprint']; }

echo "── one page, exactly the wire's, of exactly the set\n";
store();
page_answers( 1, function ( $b ) { return ok_page( [ 'after_id' => 14, 'complete' => true, 'outcome' => 'trashed', 'trashed' => 2 ] ); } );
run_job();
$p = pages()[0] ?? [];
ok( 'the page carries site, list_id, after_id, rows, complete — nothing else', array_keys( $p ) === [ 'site', 'list_id', 'after_id', 'rows', 'complete' ], json_encode( array_keys( $p ) ) );
ok( 'the list id and position are the ones the server gave', ( $p['list_id'] ?? '' ) === LIST_ID && ( $p['after_id'] ?? null ) === 0 );
ok( 'rows: the set only (no trash, no variation), ascending, with the SAME fingerprint a send carries',
    ( $p['rows'] ?? null ) === [ [ 10, fp( 10 ) ], [ 13, fp( 13 ) ], [ 14, fp( 14 ) ] ] );
ok( 'the last page says complete', ( $p['complete'] ?? null ) === true );
$st = CashFlow_Catalog::list_state();
ok( 'a completed list is let go; its open time is kept for the hourly timer', empty( $st['list_id'] ) && ( $st['last_opened_at'] ?? null ) === $T );
$ll = CashFlow_Catalog::stats()['last_list'] ?? [];
ok( 'the panel shows the outcome and how many were trashed', ( $ll['result'] ?? '' ) === 'trashed' && ( $ll['trashed'] ?? null ) === 2 );

echo "── the set comes from ONE direct query, never WP_Query [NB3]\n";
ok( 'the enumeration ran as the plugin\'s own statement', in_array( 'catalog:enumerate', CF_TestState::$sql, true ) );
$src = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( __DIR__ . '/../includes/class-catalog.php' ) );
$src = preg_replace( '#//[^\n]*#', '', $src );
ok( 'no WP_Query, get_posts or wc_get_products anywhere in the class (comments stripped)',
    ! str_contains( $src, 'WP_Query' ) && ! str_contains( $src, 'get_posts(' ) && ! str_contains( $src, 'wc_get_products(' ) );

echo "── need is queued as asked, and sent in the same run\n";
store();
page_answers( 1, function () { return ok_page( [ 'need' => [ 13, 'junk', -1 ], 'after_id' => 14, 'complete' => true, 'outcome' => 'trashed' ] ); } );
CF_TestState::$api_responses['/plugin/catalog/products'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'applied' => [ 13 ], 'trashed' => [], 'unchanged' => [], 'warnings' => [], 'list_wanted' => false ] ];
run_job();
$sent = array_values( array_filter( CF_TestState::$api_calls, function ( $c ) { return '/plugin/catalog/products' === $c['endpoint']; } ) );
ok( 'the asked product went out in the same run', count( $sent ) === 1 && array_column( json_decode( $sent[0]['body'], true )['products'] ?? [], 'id' ) === [ 13 ] );
ok( 'junk in need is ignored', CF_TestState::$catalog_queue === [] );
ok( 'the panel counts what was asked', ( CashFlow_Catalog::stats()['last_list']['asked'] ?? null ) === 1 );

echo "── pages are sized by TIME, not count [NB2]\n";
// A list already open (store()'s default) means the run's FIRST page goes
// through work()'s step -1, which reserves REQUEST_TIMEOUT on top of the
// usual margin [task-12, IMPORTANT 1, second review] — a 3 s build, not
// PAGE_BUILD_SECONDS' full 6, so a real-save batch always has room after
// it. Step 2/3's own calls (unaffected) still get the full 6 s. Same
// total time spent building this run either way (13 s = 3+6+4, same as
// the pre-fix 6+6+1) — only the FIRST page shrank.
store( range( 101, 200 ) );
CF_TestState::$on_product_get = function ( $prop ) { global $T; if ( 'name' === $prop ) { $T += 0.25; } };   // each product takes 0.25 s to read
page_answers( 5, follow() );
run_job();
CF_TestState::$on_product_get = null;
$sizes = array_map( function ( $p ) { return count( $p['rows'] ); }, pages() );
ok( 'three pages: 3 s (the reserved first step), 6 s, then the 4 s the budget still allows',
    $sizes === [ 12, 24, 16 ], json_encode( $sizes ) );
ok( 'each continuing from the server\'s position', array_column( pages(), 'after_id' ) === [ 0, 112, 136 ] );
ok( 'none claimed complete', [ false, false, false ] === array_column( pages(), 'complete' ) );
ok( 'the list is held at 152 for the next run', ( CashFlow_Catalog::list_state()['after_id'] ?? null ) === 152 );

echo "── an exact page is followed by an empty complete page\n";
store( [ 10, 13, 14 ], 3 );
page_answers( 2, follow() );
run_job();
ok( 'a full page of 3, not complete; then rows [] complete', array_map( function ( $p ) { return [ count( $p['rows'] ), $p['complete'] ]; }, pages() ) === [ [ 3, false ], [ 0, true ] ] );

echo "── a database error stops the list; it never becomes an empty page [NB3]\n";
store();
CF_TestState::$db_error_on = 'SELECT ID FROM wp_posts';
run_job();
CF_TestState::$db_error_on = null;
ok( 'no page was sent', pages() === [] );
ok( 'the list is still held, at the same position', ( CashFlow_Catalog::list_state()['list_id'] ?? '' ) === LIST_ID && ( CashFlow_Catalog::list_state()['after_id'] ?? null ) === 0 );
ok( 'the panel says the list stopped and why', str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'product query failed' ) );
$enum_calls = count( array_filter( CF_TestState::$sql, function ( $s ) { return 'catalog:enumerate' === $s; } ) );
ok( 'a DB error while building costs exactly ONE attempt this run, not a repeat [task-12, IMPORTANT 2]',
    1 === $enum_calls, (string) $enum_calls );

echo "── 409: the server's position, and the server's list\n";
store();
page_answers( 1, function () { return conflict( [ 'error' => 'position_mismatch', 'expected_after_id' => 13 ] ); } );
page_answers( 1, follow() );
run_job();
ok( 'position_mismatch: re-sent from the expected position', array_column( pages(), 'after_id' ) === [ 0, 13 ] && ( pages()[1]['rows'] ?? null ) === [ [ 14, fp( 14 ) ] ] );

store();
page_answers( 1, function () { return conflict( [ 'error' => 'list_expired', 'expected_after_id' => null ] ); } );
CF_TestState::$api_responses['/plugin/catalog/list/open'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'list_id' => LIST_ID2, 'after_id' => 0, 'resend' => false, 'resumed' => false, 'page_size' => 1000 ] ];
page_answers( 1, follow() );
run_job();
ok( 'list_expired: a new list is opened in the same run and paged', ( pages()[1]['list_id'] ?? '' ) === LIST_ID2 );

store();
page_answers( 1, function () { return conflict( [ 'error' => 'list_not_found', 'expected_after_id' => null ] ); } );
CF_TestState::$api_responses['/plugin/catalog/list/open'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'list_id' => LIST_ID2, 'after_id' => 0, 'resend' => false, 'resumed' => false, 'page_size' => 1000 ] ];
page_answers( 1, follow() );
run_job();
ok( 'list_not_found: the same', ( pages()[1]['list_id'] ?? '' ) === LIST_ID2 );

store();
page_answers( 1, function () { return conflict( [ 'error' => 'list_closed', 'expected_after_id' => null ] ); } );
run_job();
$st = CashFlow_Catalog::list_state();
ok( 'list_closed: let go, NOT reopened now (next hour)', empty( $st['list_id'] ) && empty( $st['wanted'] ) && ( $st['last_opened_at'] ?? null ) === $T
    && [] === array_filter( CF_TestState::$api_calls, function ( $c ) { return '/plugin/catalog/list/open' === $c['endpoint']; } ) );
ok( 'and the panel says so', ( CashFlow_Catalog::stats()['last_list']['result'] ?? '' ) === 'list_closed' );

echo "── the outcomes are shown as the server gave them\n";
store();
page_answers( 1, function () { return ok_page( [ 'after_id' => 14, 'complete' => true, 'outcome' => 'trash_refused', 'trash_refused' => true ] ); } );
run_job();
ok( 'trash_refused', ( CashFlow_Catalog::stats()['last_list']['result'] ?? '' ) === 'trash_refused' && true === ( CashFlow_Catalog::stats()['last_list']['trash_refused'] ?? null ) );
store();
page_answers( 1, function () { return ok_page( [ 'after_id' => 14, 'complete' => true, 'outcome' => 'flawed' ] ); } );
run_job();
ok( 'flawed', ( CashFlow_Catalog::stats()['last_list']['result'] ?? '' ) === 'flawed' );

echo "── a product that cannot be read is listed with no fingerprint (so the server asks)\n";
store();
CF_TestState::$terms[92] = (object) [ 'term_id' => 92, 'name' => 'Scarf', 'slug' => 'scarf', 'taxonomy' => 'product_cat', 'count' => 1 ];
CF_TestState::$products[13] = new WC_Product( 13, 0, 'publish', [ 'category_ids' => [ 92 ] ] );
CF_TestState::$terms_error = 'Deadlock found';
page_answers( 1, follow() );
run_job();
CF_TestState::$terms_error = null;
ok( 'its row carries null', ( pages()[0]['rows'][1] ?? null ) === [ 13, null ] );

echo "── a failed page keeps the list where it was\n";
// One failed page attempt per run [task-12, IMPORTANT 2 — decided]: this
// non-'page' answer (a 500, not a conflict) sets $list_page_failed_this_run
// in step -1, so step 2's list_step() skips send_page() rather than trying
// the same failing page again. TWO identical responses are still scripted
// (never just one) so the assertion below proves the bound by construction
// — if the fix regressed and a second attempt were made, it would consume
// the second response and still land on the same message, so only the
// explicit request-count check below can catch the regression.
store();
page_answers( 2, function () { return [ 'ok' => false, 'status' => 500, 'data' => [ 'error' => 'catalogue_list_failed' ] ]; } );
run_job();
ok( 'still held at 0, reason on the panel', ( CashFlow_Catalog::list_state()['after_id'] ?? null ) === 0
    && str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'Sending a list page failed: HTTP 500: catalogue_list_failed' ) );
ok( 'bounded at exactly ONE attempt this run — step 2 does not retry a page step -1 already failed [task-12, IMPORTANT 2]',
    count( pages() ) === 1, (string) count( pages() ) );

echo "── a real refusal on the list route ends the run — no second request anywhere [task-12]\n";
store();
page_answers( 1, function () { return [ 'ok' => false, 'status' => 401, 'data' => [ 'error' => 'invalid connection secret' ] ]; } );
run_job();
ok( '401 on list/page: the run stops on the spot', count( pages() ) === 1 && true === CashFlow_Catalog::stats()['not_connected'] );
ok( 'the list is left exactly where it was', ( CashFlow_Catalog::list_state()['list_id'] ?? '' ) === LIST_ID
    && ( CashFlow_Catalog::list_state()['after_id'] ?? null ) === 0 );

echo "── at most one conflict is followed per run — a repeating refusal costs a HANDFUL of requests, not hundreds [task-12, IMPORTANT 2]\n";
store();
for ( $i = 0; $i < 50; $i++ ) {
    page_answers( 1, function () { return conflict( [ 'error' => 'list_not_found' ] ); } );
    CF_TestState::$api_responses['/plugin/catalog/list/open'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'list_id' => LIST_ID2, 'after_id' => 0, 'resend' => false, 'resumed' => false, 'page_size' => 1000 ] ];
}
run_job();
$page_calls = count( array_filter( CF_TestState::$api_calls, function ( $c ) { return '/plugin/catalog/list/page' === $c['endpoint']; } ) );
$open_calls = count( array_filter( CF_TestState::$api_calls, function ( $c ) { return '/plugin/catalog/list/open' === $c['endpoint']; } ) );
ok( 'a server refusing every page as list_not_found still costs a handful of requests, not dozens (50 were available)',
    $page_calls + $open_calls <= 5, "page=$page_calls open=$open_calls" );
ok( 'the capped-conflict message names the ACTUAL 409 in neutral wording [task-12, minor 1]',
    str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'CashFlow answered list_not_found twice this run' ),
    (string) CashFlow_Catalog::stats()['last_error'] );
ok( 'last_list is updated to match — never left showing a stale "opened" from the reopen that preceded it [task-12, minor 1]',
    ( CashFlow_Catalog::stats()['last_list']['result'] ?? '' ) === 'list_not_found' );

store();
for ( $i = 0; $i < 50; $i++ ) {
    page_answers( 1, function () { return conflict( [ 'error' => 'position_mismatch', 'expected_after_id' => 5 ] ); } );
}
run_job();
ok( 'the same, for a repeating position_mismatch (50 were available)', count( pages() ) <= 5, (string) count( pages() ) );

echo "── send_page clamps page_size to 1-1000, exactly as open_list does [task-12, minor 1]\n";
// A SET past 1,000 ids AND a stale page_size past MAX_LIST_ROWS — so an
// unclamped page_size would genuinely be able to return more than 1,000
// rows in one page (a smaller SET would hide the missing clamp, since the
// SET itself would run out first regardless of page_size).
store( range( 1, 1200 ), 5000 );
page_answers( 1, follow() );
run_job();
ok( 'the page is capped at exactly MAX_LIST_ROWS even though the stored page_size and the SET both exceed it',
    count( pages()[0]['rows'] ?? [] ) === CashFlow_Catalog::MAX_LIST_ROWS, (string) count( pages()[0]['rows'] ?? [] ) );

store();
CF_TestState::$options['cashflow_catalog_list']['page_size'] = 0;   // would make build_page()'s own loop never run at all
page_answers( 1, follow() );
run_job();
ok( 'a page_size of 0 is floored to 1, not left to stall the list forever', ( pages()[0]['rows'] ?? null ) !== [] );

echo "── a page built and then dropped for want of time is noted, never silent [task-12, minor 2]\n";
store( [ 601 ] );   // one product; reading it alone costs more than the run can spare
CF_TestState::$on_product_get = function ( $prop ) { global $T; if ( 'name' === $prop ) { $T += 14; } };
run_job();
CF_TestState::$on_product_get = null;
ok( 'no page was sent — there was no time left this run to send what was built', pages() === [] );
ok( 'the panel says so, never silently', str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'no time left this run to send it' ) );

echo "── a 2xx with the wrong shape on list/page says so plainly, like open_list()'s own message [task-12, minor]\n";
// A malformed 200 is now a 'stop', consistent with the products route
// [task-12, second review, minor 2] — the SAME connection-level fault that
// makes the list route answer garbage would make the products route too,
// so this ends the WHOLE run outright (step -1 returns 'stop', work()
// returns before drain_solo/real saves/step 2 are ever reached) — not
// merely a gated retry via $list_page_failed_this_run, which alone (an
// 'idle') would still let real saves proceed normally this run. Real
// saves are queued here specifically so the two behave differently:
// $list_page_failed_this_run gates ONLY further list attempts, but 'stop'
// ends real-save draining too — this is the assertion that actually tells
// the two apart (a bare "1 page request" count would pass under either).
store();
CashFlow_Catalog::enqueue( 9001, 'save' );
page_answers( 1, function () { return [ 'ok' => true, 'status' => 200, 'data' => [ 'unexpected' => true ] ]; } );
// Scripted even though the CORRECT code never reaches it (a 'stop' ends
// the run before step 1) — so a regression that lets real saves run
// doesn't ALSO corrupt last_error via an unrelated "no scripted response"
// failure, muddying which assertion is actually catching the regression.
CF_TestState::$api_responses['/plugin/catalog/products'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'applied' => [], 'trashed' => [ 9001 ], 'unchanged' => [], 'warnings' => [], 'list_wanted' => false ] ];
run_job();
ok( 'worded the same way open_list() words its own missing-shape failure',
    str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'Sending a list page failed: CashFlow answered without a page result' ) );
ok( 'a malformed 200 costs exactly ONE attempt on the list route',
    count( pages() ) === 1, (string) count( pages() ) );
ok( 'and it stops the WHOLE run — the queued real save is untouched, not merely the list route [task-12, minor 2]',
    CashFlow_Catalog::count_pending() === 1, (string) CashFlow_Catalog::count_pending() );

echo "── a need id that fails to enqueue is counted and shown, not silently dropped [task-12, minor 4]\n";
store();
page_answers( 1, function () { return ok_page( [ 'need' => [ 13, 14 ], 'after_id' => 14, 'complete' => true, 'outcome' => 'trashed' ] ); } );
CF_TestState::$db_error_on = 'INSERT IGNORE INTO wp_cashflow_catalog_queue (product_id, reason, attempts, queued_at, pending_key) VALUES';
run_job();
CF_TestState::$db_error_on = null;
ok( 'the failed enqueues are counted on the panel, not just the raw DB reason',
    str_contains( (string) CashFlow_Catalog::stats()['last_error'], '2 asked products could not be queued' ) );
ok( 'nothing was actually queued', CashFlow_Catalog::count_pending() === 0 );

echo "── enumerate() catches a get_col() that returns a non-array with no last_error set [task-12, minor — HARNESS-ONLY shape]\n";
// CORRECTED per review: this covers a shape only the harness's
// $db_silent_failure_on can produce (get_col() returning `false`, which
// real \wpdb::get_col() never does — it always returns an ARRAY, even on
// failure). It does NOT cover the realistic "not-ready-connection" case,
// where a real failed get_col() returns [] with $wpdb->last_error ALSO
// left empty — enumerate()'s `'' !== last_error || ! is_array($ids)` check
// would read THAT case as "the set has no more rows" (is_array([]) is
// true), not as a failure. That case is NOT modelled or tested here; this
// only proves the non-array branch of the guard, nothing more.
CF_TestState::reset();
CF_TestState::$catalog_tables['wp_cashflow_catalog_queue'] = true;
CF_TestState::$db_silent_failure_on = 'enumerate';
$silent = CashFlow_Catalog::enumerate( 0, 100 );
CF_TestState::$db_silent_failure_on = null;
ok( 'a get_col that returns a non-array with no last_error still answers null, not []', $silent === null );

echo "── a DB error on a LATER chunk discards the WHOLE page, not just what failed [task-12, minor 6]\n";
store( range( 301, 520 ), 1000 );   // 220 ids: needs more than one ENUM_CHUNK to enumerate
CF_TestState::$stmt_fail_from_call['enumerate'] = 2;   // the first chunk succeeds; the second (and any later) fails
run_job();
CF_TestState::$stmt_fail_from_call = [];
ok( 'no page was sent — the successfully-read first chunk was discarded too, never sent alone', pages() === [] );
ok( 'the list is left exactly where it was', ( CashFlow_Catalog::list_state()['after_id'] ?? null ) === 0 );
ok( 'the panel says why', str_contains( (string) CashFlow_Catalog::stats()['last_error'], 'product query failed' ) );

echo "── a 409 with an error the contract does not name still drops the list safely — never stuck, never a crash [task-12, minor 6]\n";
store();
page_answers( 1, function () { return conflict( [ 'error' => 'something_the_plugin_has_never_seen' ] ); } );
CF_TestState::$api_responses['/plugin/catalog/list/open'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'list_id' => LIST_ID2, 'after_id' => 0, 'resend' => false, 'resumed' => false, 'page_size' => 1000 ] ];
page_answers( 1, follow() );
run_job();
ok( 'an unrecognised 409 reason is treated like any other conflict — a fresh list opens and pages, safely',
    ( pages()[1]['list_id'] ?? '' ) === LIST_ID2 );

echo "── position_mismatch with no usable expected_after_id falls back to opening fresh, never a guess [task-12, minor 6]\n";
store();
page_answers( 1, function () { return conflict( [ 'error' => 'position_mismatch' ] ); } );   // no expected_after_id at all
CF_TestState::$api_responses['/plugin/catalog/list/open'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'list_id' => LIST_ID2, 'after_id' => 0, 'resend' => false, 'resumed' => false, 'page_size' => 1000 ] ];
page_answers( 1, follow() );
run_job();
ok( 'no usable position — falls back to opening fresh rather than guessing one',
    ( pages()[1]['list_id'] ?? '' ) === LIST_ID2 );

echo "── a long real-save queue does not starve an open list [task-12, IMPORTANT 1 — ordering, not a threshold]\n";
store( range( 201, 217 ), 5 );
for ( $id = 1001; $id <= 1200; $id++ ) {
    CashFlow_Catalog::enqueue( $id, 'save' );   // 200 real saves — a bulk edit
}
// A "slow server": every real-save batch costs 5 s of (simulated) wall time.
$slow_products = function () {
    global $T;
    $T += 5;
    return [ 'ok' => true, 'status' => 200, 'data' => [ 'applied' => [], 'trashed' => [], 'unchanged' => [], 'warnings' => [], 'list_wanted' => false ] ];
};
for ( $i = 0; $i < 10; $i++ ) {
    CF_TestState::$api_responses['/plugin/catalog/products'][] = $slow_products;
}
page_answers( 6, follow() );

run_job();
ok( 'tick 1: the list gets the run\'s very FIRST turn — one page, not zero, despite 200 real saves being due',
    count( pages() ) === 1 );
ok( 'tick 1: real saves still drain, going first does not stop them',
    CashFlow_Catalog::count_pending() === 125, (string) CashFlow_Catalog::count_pending() );
ok( 'tick 1: the list advanced past its very first row and is still open (not force-completed, not expired)',
    ( CashFlow_Catalog::list_state()['after_id'] ?? null ) === 205 && ! empty( CashFlow_Catalog::list_state()['list_id'] ) );

run_job();
ok( 'tick 2: the list gets first turn again — it is never skipped while the backlog exists',
    count( pages() ) === 2 && ( CashFlow_Catalog::list_state()['after_id'] ?? null ) === 210 );
ok( 'tick 2: real saves are STILL draining',
    CashFlow_Catalog::count_pending() === 50, (string) CashFlow_Catalog::count_pending() );

run_job();
ok( 'tick 3: the whole real-save backlog is gone — nothing was starved for good, only delayed',
    CashFlow_Catalog::count_pending() === 0, (string) CashFlow_Catalog::count_pending() );
ok( 'tick 3: the list reaches its own end — every id in the set was still reached',
    empty( CashFlow_Catalog::list_state()['list_id'] ) && count( pages() ) === 4 );

echo "── the first step leaves room for a real-save batch, even at a slow page request [task-12, IMPORTANT 1, second review]\n";
// The reviewer's exact scenario: a page REQUEST costing ~10s, a build that
// uses its whole (reduced) budget, and 100 real saves queued. Without the
// REQUEST_TIMEOUT reserve on step -1's build, the first step alone could
// consume build(6s)+request(10s)=16s, leaving under MIN_LEFT_TO_START and
// starving every real save for the whole run — the reviewer reproduced 100
// saves still pending after 3 runs. With the fix, step -1's build is
// capped at (25-12-10)=3s, so even a 10s request leaves exactly 12s.
store( range( 801, 850 ), 1000 );   // 50-id set — more than the reduced 3s budget can cover at 0.25s/product
for ( $id = 3001; $id <= 3100; $id++ ) {
    CashFlow_Catalog::enqueue( $id, 'save' );   // 100 real saves
}
CF_TestState::$on_product_get = function ( $prop ) { global $T; if ( 'name' === $prop ) { $T += 0.25; } };   // the build uses its whole budget
page_answers( 10, slow_follow( 10.0 ) );        // the list's own request costs 10s — a slow server
for ( $i = 0; $i < 10; $i++ ) {
    CF_TestState::$api_responses['/plugin/catalog/products'][] = [ 'ok' => true, 'status' => 200, 'data' => [ 'applied' => [], 'trashed' => [], 'unchanged' => [], 'warnings' => [], 'list_wanted' => false ] ];
}

run_job();
$pending0 = 100;
$pending1 = CashFlow_Catalog::count_pending();
ok( 'run 1: the list still gets its page', count( pages() ) >= 1 );
ok( 'run 1: a real-save batch is STILL sent — the first step left exactly enough room',
    $pending1 < $pending0, (string) $pending1 );
$pages1 = count( pages() );

run_job();
$pending2 = CashFlow_Catalog::count_pending();
ok( 'run 2: the list advances further (or has already finished)',
    count( pages() ) > $pages1 || empty( CashFlow_Catalog::list_state()['list_id'] ) );
ok( 'run 2: real saves keep draining', $pending2 < $pending1 || 0 === $pending1, (string) $pending2 );
$pages2 = count( pages() );

run_job();
CF_TestState::$on_product_get = null;
$pending3 = CashFlow_Catalog::count_pending();
ok( 'run 3: the list is still being reached (or already finished)',
    count( pages() ) > $pages2 || empty( CashFlow_Catalog::list_state()['list_id'] ) );
ok( 'run 3: real saves keep draining too — every run sent at least one batch, nothing was starved',
    $pending3 < $pending2 || 0 === $pending2, (string) $pending3 );

echo "── the same, at batch costs a fixed reserve could never have covered [task-12, IMPORTANT 1]\n";
// The reviewer reproduced 0 pages across 3 runs at a 6.5-7s or 13.5s batch
// cost under the old threshold design — guaranteeing a page after such a
// batch needs 28s and a run only has 25. Ordering removes the question
// entirely: the list goes first, before any real-save batch is even
// claimed, so its own cost can never matter.
foreach ( [ 5.0, 6.8, 10.0, 13.5 ] as $cost ) {
    store( range( 701, 712 ), 5 );   // 12-id set, page_size 5 — several pages needed to complete
    for ( $id = 2001; $id <= 2060; $id++ ) {
        CashFlow_Catalog::enqueue( $id, 'save' );   // 60 real saves, several batches
    }
    $slow = function () use ( $cost ) {
        global $T;
        $T += $cost;
        return [ 'ok' => true, 'status' => 200, 'data' => [ 'applied' => [], 'trashed' => [], 'unchanged' => [], 'warnings' => [], 'list_wanted' => false ] ];
    };
    for ( $i = 0; $i < 10; $i++ ) {
        CF_TestState::$api_responses['/plugin/catalog/products'][] = $slow;
    }
    page_answers( 10, follow() );

    run_job();
    $pages1   = count( pages() );
    $pending1 = CashFlow_Catalog::count_pending();
    ok( "batch cost {$cost}s: run 1 sends the list at least one page", $pages1 >= 1, "got $pages1 pages" );
    ok( "batch cost {$cost}s: run 1 still drains real saves", $pending1 < 60, "pending still $pending1" );

    run_job();
    $pages2   = count( pages() );
    $pending2 = CashFlow_Catalog::count_pending();
    ok( "batch cost {$cost}s: run 2 advances the list further (or it has already finished)",
        $pages2 > $pages1 || empty( CashFlow_Catalog::list_state()['list_id'] ), "pages stayed at $pages1" );
    ok( "batch cost {$cost}s: run 2 keeps draining (or is already done)",
        $pending2 < $pending1 || 0 === $pending1, "pending stuck at $pending1" );
}

CashFlow_Catalog::$clock = null;
summary();
