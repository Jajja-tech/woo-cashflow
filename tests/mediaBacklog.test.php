<?php
/**
 * A shop with many pictures waiting hands them over in minutes (class-media.php).
 *
 * The shop's uploads folder is real. CashFlow is modelled: it names at most
 * ten waiting pictures per ask, in a fixed order, and a picture leaves the
 * list when its upload is answered. Time is a clock the test owns: an ask and
 * an upload each move it by as long as they take, so a run's budget is spent
 * exactly as it would be on a shop.
 *
 * The first half sends one picture at a time, as a server without PHP's curl
 * does. The second half sends five at once: a batch moves the clock by as long
 * as its slowest picture takes, not by the sum.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-catalog.php';
require_once __DIR__ . '/../includes/class-media.php';

$root = sys_get_temp_dir() . '/cf-media-backlog-' . getmypid();
@mkdir( $root . '/uploads', 0777, true );

$clock = 0.0;
$shop  = [];

CashFlow_Media::$uploads  = [ 'baseurl' => 'https://zensha.pk/wp-content/uploads', 'basedir' => $root . '/uploads' ];
CashFlow_Media::$api_base = function () { return 'https://api.cashflow.pk'; };
CashFlow_Media::$clock    = function () { global $clock; return $clock; };
CashFlow_Media::$http     = function ( $url, $args ) {
    global $shop, $clock;
    $src   = $args['headers']['X-CashFlow-Source-Url'];
    $takes = $shop['slow'][ $src ] ?? $shop['upload_s'];
    $shop['uploads'][] = [ 'url' => $src, 'timeout' => $args['timeout'], 'at' => $clock ];
    if ( $takes > $args['timeout'] ) {
        $clock += $args['timeout'];
        return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
    }
    $clock += $takes;
    $shop['waiting'] = array_values( array_diff( $shop['waiting'], [ $src ] ) );
    $shop['stored'][] = $src;
    return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
};

/** Five at once. $shop['batch_s'] says how long CashFlow takes to answer a batch of n. */
function many_at_once( $on = true ) {
    CashFlow_Media::$http_many = ! $on ? null : function ( $reqs ) {
        global $shop, $clock;
        $n       = count( $reqs );
        $out     = [];
        $longest = 0.0;
        $shop['batches'][] = $n;
        foreach ( $reqs as $k => $r ) {
            $src     = $r['args']['headers']['X-CashFlow-Source-Url'];
            $timeout = $r['args']['timeout'];
            $takes   = $shop['slow'][ $src ] ?? call_user_func( $shop['batch_s'], $n );
            $shop['uploads'][] = [ 'url' => $src, 'timeout' => $timeout, 'at' => $clock ];
            if ( $takes > $timeout ) {
                $longest   = max( $longest, (float) $timeout );
                $out[ $k ] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
                continue;
            }
            $longest = max( $longest, (float) $takes );
            if ( isset( $shop['refuse'][ $src ] ) ) {
                $out[ $k ] = [ 'response' => [ 'code' => $shop['refuse'][ $src ] ], 'body' => '{}' ];
                continue;
            }
            $shop['waiting']  = array_values( array_diff( $shop['waiting'], [ $src ] ) );
            $shop['stored'][] = $src;
            $out[ $k ] = [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
        }
        $clock += $longest;
        return $out;
    };
}

function picture( $i ) { return 'https://zensha.pk/wp-content/uploads/p' . $i . '.webp'; }

/** CashFlow answers every ask: the first ten still waiting. */
function cashflow_answers() {
    CF_TestState::$api_responses['/plugin/media/wanted'] = [ function ( $call ) {
        global $shop, $clock;
        cashflow_answers();
        $shop['asks'][] = $call;
        $clock += $shop['wanted_s'];
        $limit = (int) ( json_decode( (string) $call['body'], true )['limit'] ?? 10 );
        $named = array_slice( $shop['waiting'], 0, min( 10, $limit ) );
        return [ 'ok' => true, 'status' => 200, 'data' => [ 'wanted' => array_map( function ( $u ) { return [ 'url' => $u ]; }, $named ) ] ];
    } ];
}

/** A shop with $n pictures waiting, each upload taking $upload_s seconds. */
function backlog( $n, $upload_s = 1.0, $wanted_s = 0.5 ) {
    global $shop, $clock, $root;
    CF_TestState::$as_singles = [];
    CF_TestState::$as_calls   = [];
    CF_TestState::$options    = [
        'cashflow_connection_secret' => 'secret-xyz',
        'siteurl' => 'https://zensha.pk', 'home' => 'https://zensha.pk',
    ];
    $clock = 0.0;
    $shop  = [ 'waiting' => [], 'stored' => [], 'uploads' => [], 'asks' => [], 'slow' => [], 'refuse' => [], 'batches' => [], 'upload_s' => $upload_s, 'wanted_s' => $wanted_s,
               'batch_s' => function ( $n ) use ( $upload_s ) { return $upload_s; } ];
    for ( $i = 1; $i <= $n; $i++ ) {
        $f = $root . '/uploads/p' . $i . '.webp';
        if ( ! is_file( $f ) ) { file_put_contents( $f, 'WEBP' . $i ); }
        $shop['waiting'][] = picture( $i );
    }
    cashflow_answers();
}

function follow_ups( $status = 'pending' ) {
    return array_filter( CF_TestState::$as_singles, function ( $a ) use ( $status ) {
        return $a['hook'] === CashFlow_Media::FOLLOW_HOOK && $a['status'] === $status;
    } );
}
function last_run() { return CF_TestState::$options['cashflow_media_last']; }

echo "── one run sends every waiting picture it has time for\n";
backlog( 12 );
CashFlow_Media::run();
ok( 'all 12 were sent in one run', count( $shop['stored'] ) === 12 && last_run()['sent'] === 12, json_encode( last_run() ) );
ok( 'it asked twice: ten, then the two left', count( $shop['asks'] ) === 2 );
ok( 'nothing is left, so no further run is asked for', follow_ups() === [] && last_run()['more'] === false );
ok( 'the first upload had the full 20 seconds', $shop['uploads'][0]['timeout'] === 20 );
ok( 'every later upload had what was left of the run, less one second',
    $shop['uploads'][1]['timeout'] === 20 && $shop['uploads'][11]['timeout'] === 12, json_encode( array_column( $shop['uploads'], 'timeout' ) ) );

echo "── a run stops inside its 25 seconds and asks for the next one\n";
backlog( 40 );
CashFlow_Media::run();
ok( 'the run ended inside its budget', $clock <= 25.0, (string) $clock );
ok( 'it sent 19 of the 40', count( $shop['stored'] ) === 19, (string) count( $shop['stored'] ) );
ok( 'and recorded that more are waiting', last_run()['more'] === true );
$f = array_values( follow_ups() );
ok( 'one follow-up run is asked for, due at once, in the picture job\'s own group and place in the queue',
    count( $f ) === 1 && $f[0]['timestamp'] === (int) ceil( $clock ) && $f[0]['group'] === 'cashflow-media' && $f[0]['priority'] === 30, json_encode( $f ) );

echo "── CashFlow taking nine seconds a picture: no upload is started only to be cut off\n";
backlog( 5, 9.0 );
CashFlow_Media::run();
ok( 'two were sent', count( $shop['stored'] ) === 2 && count( $shop['uploads'] ) === 2, json_encode( $shop['uploads'] ) );
ok( 'the third was not started with 6.5 seconds left', $clock === 18.5, (string) $clock );
ok( 'nothing was set aside for the day', empty( CF_TestState::$options['cashflow_media_skip'] ) );
ok( 'the rest follow in the next run', count( follow_ups() ) === 1 );

echo "── an upload cut off by the run's own clock is not set aside\n";
backlog( 6, 5.0 );
$shop['slow'][ picture( 4 ) ] = 12.0;                 // needs 12 s; only 9.5 s of the run are left when its turn comes
CashFlow_Media::run();
ok( 'three were sent, the fourth was cut at 8 seconds', count( $shop['stored'] ) === 3 && $shop['uploads'][3]['timeout'] === 8, json_encode( $shop['uploads'] ) );
ok( 'it is not counted as refused', last_run()['refused'] === 0 && last_run()['error'] === null, json_encode( last_run() ) );
ok( 'and not set aside for the day', ! isset( CF_TestState::$options['cashflow_media_skip'][ picture( 4 ) ] ) );
ok( 'a follow-up run is asked for', count( follow_ups() ) === 1 );
$clock = 60.0;
$shop['uploads'] = [];
CashFlow_Media::run();
ok( 'the next run sends it first, with the full 20 seconds', $shop['uploads'][0]['url'] === picture( 4 ) && $shop['uploads'][0]['timeout'] === 20 && in_array( picture( 4 ), $shop['stored'], true ) );

echo "── an upload that fails with the full 20 seconds is set aside, as before\n";
backlog( 3 );
$shop['slow'][ picture( 1 ) ] = 30.0;
CashFlow_Media::run();
ok( 'it is set aside for the day with its reason', isset( CF_TestState::$options['cashflow_media_skip'][ picture( 1 ) ] ) && last_run()['refused'] === 1 && strpos( (string) last_run()['error'], 'timed out' ) !== false, json_encode( last_run() ) );

echo "── a follow-up run asks for the next follow-up\n";
backlog( 60 );
CashFlow_Media::run();
$id = array_key_first( follow_ups() );
CF_TestState::$as_singles[ $id ]['status'] = 'in-progress';    // Action Scheduler is running it
$clock = 60.0;
CashFlow_Media::run();
CF_TestState::$as_singles[ $id ]['status'] = 'complete';
ok( 'while it was itself in progress, it asked for another', count( follow_ups() ) === 1 && array_key_first( follow_ups() ) !== $id, json_encode( CF_TestState::$as_singles ) );

echo "── the follow-up hook runs the job\n";
CF_TestState::$actions = [];
$job = new CashFlow_Media();
ok( 'both the five-minute tick and the follow-up call the same run',
    ( CF_TestState::$actions['cashflow_media_tick'][0][0] ?? null ) === [ $job, 'tick' ]
    && ( CF_TestState::$actions['cashflow_media_follow_up'][0][0] ?? null ) === [ $job, 'tick' ] );

echo "── a follow-up already waiting is not asked for twice\n";
$clock = 90.0;
CashFlow_Media::run();
ok( 'still exactly one waiting', count( follow_ups() ) === 1 );

echo "── CashFlow slow to say what it needs: nothing is started late\n";
backlog( 5, 1.0, 5.0 );
CashFlow_Media::run();
ok( 'no upload is started with under 21 seconds left', $shop['uploads'] === [] );
ok( 'and the five-minute run carries on, with no follow-up', follow_ups() === [] && last_run()['more'] === false );

echo "── nothing waiting: one ask, nothing else\n";
backlog( 0 );
CashFlow_Media::run();
ok( 'one ask, no upload, no follow-up', count( $shop['asks'] ) === 1 && $shop['uploads'] === [] && follow_ups() === [] );

echo "── ten pictures this site cannot read do not make the run ask in a loop\n";
backlog( 10 );
foreach ( $shop['waiting'] as $u ) { CF_TestState::$options['cashflow_media_skip'][ $u ] = time(); }
CashFlow_Media::run();
ok( 'one ask, ten skipped, no follow-up', count( $shop['asks'] ) === 1 && last_run()['skipped'] === 10 && follow_ups() === [], json_encode( last_run() ) );

echo "── CashFlow stops answering in the middle of a run\n";
backlog( 25 );
$asked = 0;
CF_TestState::$api_responses['/plugin/media/wanted'] = [ function ( $call ) use ( &$asked ) {
    global $shop, $clock;
    $asked++;
    $clock += 0.5;
    CF_TestState::$api_responses['/plugin/media/wanted'] = [ [ 'ok' => false, 'status' => 503, 'error' => 'down', 'data' => null ] ];
    return [ 'ok' => true, 'status' => 200, 'data' => [ 'wanted' => array_map( function ( $u ) { return [ 'url' => $u ]; }, array_slice( $shop['waiting'], 0, 10 ) ) ] ];
} ];
CashFlow_Media::run();
ok( 'the ten already sent are counted, and the failure is on record', last_run()['sent'] === 10 && strpos( (string) last_run()['error'], 'down' ) !== false, json_encode( last_run() ) );

// ───────────────────────── five at once ─────────────────────────
many_at_once();

echo "── five go at once, and a batch costs the time of its slowest picture\n";
backlog( 12 );
$shop['batch_s'] = function ( $n ) { return 5.0; };
CashFlow_Media::run();
ok( 'all 12 were sent in one run, as batches of 5, 5 and 2', count( $shop['stored'] ) === 12 && $shop['batches'] === [ 5, 5, 2 ], json_encode( $shop['batches'] ) );
ok( 'three batches cost 15 seconds and two asks one, not twelve uploads\' worth', $clock === 16.0, (string) $clock );
ok( 'every picture of the first batch had the full 20 seconds', array_column( array_slice( $shop['uploads'], 0, 5 ), 'timeout' ) === [ 20, 20, 20, 20, 20 ] );
ok( 'nothing is left, so no further run is asked for', follow_ups() === [] && last_run()['more'] === false );

echo "── a run of batches stops inside its 25 seconds and asks for the next one at once\n";
backlog( 40 );
$shop['batch_s'] = function ( $n ) { return 5.0; };
CashFlow_Media::run();
ok( 'it sent 20 of the 40 in four batches', count( $shop['stored'] ) === 20 && $shop['batches'] === [ 5, 5, 5, 5 ], json_encode( $shop['batches'] ) );
ok( 'the run ended inside its budget', $clock <= 25.0, (string) $clock );
ok( 'the last batch was given only what was left of the run, less one second', $shop['uploads'][19]['timeout'] === 8, json_encode( array_column( $shop['uploads'], 'timeout' ) ) );
$f = array_values( follow_ups() );
ok( 'the follow-up is due at once', last_run()['more'] === true && count( $f ) === 1 && $f[0]['timestamp'] === (int) ceil( $clock ), json_encode( $f ) );

echo "── a batch slower than what is left is not started\n";
backlog( 40 );
$shop['batch_s'] = function ( $n ) { return 9.0; };
CashFlow_Media::run();
ok( 'two batches were sent; a third was not started with 6.5 seconds left', $shop['batches'] === [ 5, 5 ] && $clock === 18.5, json_encode( [ $shop['batches'], $clock ] ) );
ok( 'nothing was set aside, and the rest follow', empty( CF_TestState::$options['cashflow_media_skip'] ) && count( follow_ups() ) === 1 );

echo "── one picture of a batch cut off by the run's clock: the others count, it is not set aside\n";
backlog( 20 );
$shop['batch_s'] = function ( $n ) { return 7.0; };
$shop['slow'][ picture( 13 ) ] = 12.0;               // third batch starts with 9.5 s left: cut at 8
CashFlow_Media::run();
ok( 'the other four of its batch were stored', count( $shop['stored'] ) === 14 && ! in_array( picture( 13 ), $shop['stored'], true ), (string) count( $shop['stored'] ) );
ok( 'it is not refused and not set aside', last_run()['refused'] === 0 && ! isset( CF_TestState::$options['cashflow_media_skip'][ picture( 13 ) ] ), json_encode( last_run() ) );
ok( 'the run ended there and asked for the next', last_run()['more'] === true && count( follow_ups() ) === 1 && $shop['batches'] === [ 5, 5, 5 ] );
$clock = 200.0;
$shop['uploads'] = [];
CashFlow_Media::run();
ok( 'the next run sends it in its first batch, with the full 20 seconds', $shop['uploads'][0]['url'] === picture( 13 ) && $shop['uploads'][0]['timeout'] === 20 && in_array( picture( 13 ), $shop['stored'], true ) );

echo "── one picture of a batch refused: it alone is set aside\n";
backlog( 5 );
$shop['refuse'][ picture( 3 ) ] = 422;
CashFlow_Media::run();
ok( 'four stored, one refused with its reason', count( $shop['stored'] ) === 4 && last_run()['refused'] === 1 && strpos( (string) last_run()['error'], 'HTTP 422' ) !== false, json_encode( last_run() ) );
ok( 'only the refused one is set aside', array_keys( CF_TestState::$options['cashflow_media_skip'] ) === [ picture( 3 ) ] );

echo "── one picture of the first batch with no answer in the full 20 seconds is set aside, as one alone is\n";
backlog( 5 );
$shop['slow'][ picture( 2 ) ] = 30.0;
CashFlow_Media::run();
ok( 'it is set aside; the other four are stored', array_keys( CF_TestState::$options['cashflow_media_skip'] ) === [ picture( 2 ) ] && count( $shop['stored'] ) === 4, json_encode( last_run() ) );

echo "── large pictures are not all held in memory at once\n";
backlog( 0 );
foreach ( [ 'big1' => 6, 'big2' => 6, 'big3' => 11 ] as $name => $mb ) {
    file_put_contents( $root . '/uploads/p' . $name . '.webp', str_repeat( 'x', $mb * 1048576 ) );
}
$shop['waiting'] = [ picture( 'big1' ), picture( 'big2' ), picture( 1 ), picture( 'big3' ), picture( 2 ) ];
file_put_contents( $root . '/uploads/p1.webp', 'WEBP1' );
file_put_contents( $root . '/uploads/p2.webp', 'WEBP2' );
CashFlow_Media::run();
ok( 'two 6 MB pictures do not share a batch, and an 11 MB one goes alone', $shop['batches'] === [ 1, 2, 1, 1 ] && count( $shop['stored'] ) === 5, json_encode( $shop['batches'] ) );
ok( 'in the order CashFlow named them', array_column( $shop['uploads'], 'url' ) === [ picture( 'big1' ), picture( 'big2' ), picture( 1 ), picture( 'big3' ), picture( 2 ) ] );
foreach ( [ 'big1', 'big2', 'big3' ] as $name ) { unlink( $root . '/uploads/p' . $name . '.webp' ); }

echo "── a server that cannot hold several requests open sends one at a time\n";
many_at_once( false );
backlog( 12 );
CashFlow_Media::run();
ok( 'twelve uploads, one after another', count( $shop['stored'] ) === 12 && $shop['batches'] === [] && $clock === 13.0, (string) $clock );

/**
 * The whole backlog, run by run. The first run is the five-minute tick that
 * finds it. Each run after starts $gap seconds after the run before it ended:
 * that is how long Action Scheduler took to come round to a follow-up on
 * Zensha on 30 Sep 2026 (70 and 95 seconds, read from CashFlow's request
 * log). Answers the seconds until nothing is waiting.
 */
function drain( $n, $gap, $batch_s = null, $upload_s = 1.0 ) {
    global $shop, $clock;
    backlog( $n, $upload_s );
    if ( null !== $batch_s ) { $shop['batch_s'] = $batch_s; }
    $longest = 0.0;
    $runs    = 0;
    $id      = null;
    while ( $runs < 2000 ) {
        $began = $clock;
        CashFlow_Media::run();
        $runs++;
        $longest = max( $longest, $clock - $began );
        if ( null !== $id ) { CF_TestState::$as_singles[ $id ]['status'] = 'complete'; }
        if ( [] === $shop['waiting'] ) {
            return [ 'seconds' => $clock, 'longest_run' => $longest, 'runs' => $runs ];
        }
        $id = array_key_first( follow_ups() );
        if ( null === $id ) {
            $clock = ( floor( $clock / 300 ) + 1 ) * 300.0;      // no follow-up: the next five-minute tick
            continue;
        }
        $clock += $gap;
        CF_TestState::$as_singles[ $id ]['status'] = 'in-progress';
    }
    return [ 'seconds' => INF, 'longest_run' => $longest, 'runs' => $runs ];
}

echo "── 300 pictures waiting are all with CashFlow inside an hour\n";
// Measured on Zensha on 30 Sep 2026 with plugin 6.10.0, one picture at a time:
// CashFlow answered an upload in 2.6 to 3.9 seconds, a run sent five, and the
// next run started 70 and 95 seconds after the last ended. Every case below
// waits the longer of the two, 95 seconds, between runs.
$r = drain( 300, 95, null, 3.9 );
ok( 'one at a time at the measured 3.9 seconds does NOT make the hour: what 6.10.0 did live',
    $r['seconds'] > 3600 && count( $shop['stored'] ) === 300, $r['seconds'] . ' s in ' . $r['runs'] . ' runs' );

many_at_once();
$r = drain( 300, 95, function ( $n ) { return 6.0; } );
ok( 'five at once: all 300 are stored, each exactly once', count( $shop['stored'] ) === 300 && count( array_unique( $shop['stored'] ) ) === 300, (string) count( $shop['stored'] ) );
ok( 'inside an hour when CashFlow answers a batch of five within six seconds', $r['seconds'] <= 3600, $r['seconds'] . ' s in ' . $r['runs'] . ' runs' );
ok( 'no run went past its 25 seconds', $r['longest_run'] <= 25.0, (string) $r['longest_run'] );
ok( 'no upload was ever given more than 20 seconds', max( array_column( $shop['uploads'], 'timeout' ) ) <= 20 );
$r = drain( 300, 95, function ( $n ) { return 9.0; } );
ok( 'and still inside an hour at nine seconds a batch', $r['seconds'] <= 3600, $r['seconds'] . ' s in ' . $r['runs'] . ' runs' );
$r = drain( 300, 95, function ( $n ) { return 3.9 * $n; } );
ok( 'but not if five at once are no faster than five in a row: the hour needs CashFlow to work on them together',
    $r['seconds'] > 3600 && count( $shop['stored'] ) === 300, $r['seconds'] . ' s in ' . $r['runs'] . ' runs' );
many_at_once( false );

array_map( 'unlink', glob( $root . '/uploads/*' ) );
summary();
