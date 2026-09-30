<?php
/**
 * A shop with many pictures waiting hands them over in minutes (class-media.php).
 *
 * The shop's uploads folder is real. CashFlow is modelled: it names at most
 * ten waiting pictures per ask, in a fixed order, and a picture leaves the
 * list when its upload is answered. Time is a clock the test owns: an ask and
 * an upload each move it by as long as they take, so a run's budget is spent
 * exactly as it would be on a shop.
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
    $shop  = [ 'waiting' => [], 'stored' => [], 'uploads' => [], 'asks' => [], 'slow' => [], 'upload_s' => $upload_s, 'wanted_s' => $wanted_s ];
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
ok( 'one follow-up run is asked for, 30 seconds on, in the picture job\'s own group and place in the queue',
    count( $f ) === 1 && $f[0]['timestamp'] === (int) ceil( $clock ) + 30 && $f[0]['group'] === 'cashflow-media' && $f[0]['priority'] === 30, json_encode( $f ) );

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

/**
 * The whole backlog, run by run, as Action Scheduler would run it: its queue
 * is passed once a minute; the five-minute tick is due on every fifth pass and
 * a follow-up on the first pass at or after its time. Counted from the tick
 * that first finds the backlog. Answers the seconds until nothing is waiting.
 */
function drain( $n, $upload_s, $wanted_s = 0.5 ) {
    global $shop, $clock;
    backlog( $n, $upload_s, $wanted_s );
    $longest = 0.0;
    for ( $pass = 0; $pass <= 4 * 3600; $pass += 60 ) {
        if ( $clock > $pass ) { continue; }                     // the last pass is still running
        $clock = (float) $pass;
        $due   = ( 0 === $pass % 300 ) ? [ 'tick' ] : [];
        foreach ( follow_ups() as $id => $a ) {
            if ( $a['timestamp'] <= $pass ) { $due[] = $id; }
        }
        foreach ( $due as $d ) {
            if ( 'tick' !== $d ) { CF_TestState::$as_singles[ $d ]['status'] = 'in-progress'; }
            $began = $clock;
            CashFlow_Media::run();
            $longest = max( $longest, $clock - $began );
            if ( 'tick' !== $d ) { CF_TestState::$as_singles[ $d ]['status'] = 'complete'; }
        }
        if ( [] === $shop['waiting'] ) {
            return [ 'seconds' => $clock, 'longest_run' => $longest ];
        }
    }
    return [ 'seconds' => INF, 'longest_run' => $longest ];
}

echo "── 300 pictures waiting are all with CashFlow inside an hour\n";
// How long the hour takes depends on how long CashFlow takes to answer one
// upload, because the pictures go one at a time. Read from CashFlow's request
// log on 30 Sep 2026: 0.9 s for a picture whose bytes it already held, and
// 9.3 s for a new one while its server uploaded the eight sizes one after
// another. Four seconds a picture is the slowest at which the hour is met.
$r = drain( 300, 4.0 );
ok( 'all 300 are stored, each exactly once', count( $shop['stored'] ) === 300 && count( array_unique( $shop['stored'] ) ) === 300, (string) count( $shop['stored'] ) );
ok( 'inside an hour, at four seconds a picture', $r['seconds'] <= 3600, $r['seconds'] . ' s' );
ok( 'no run went past its 25 seconds', $r['longest_run'] <= 25.0, (string) $r['longest_run'] );
ok( 'no upload was ever given more than 20 seconds', max( array_column( $shop['uploads'], 'timeout' ) ) <= 20 );
$r = drain( 300, 2.0 );
ok( 'in half an hour, at two seconds a picture', $r['seconds'] <= 1800, $r['seconds'] . ' s' );
$r = drain( 300, 9.3 );
ok( 'at 9.3 seconds a picture the plugin cannot make the hour: that half is the server\'s', $r['seconds'] > 3600 && count( $shop['stored'] ) === 300, $r['seconds'] . ' s' );

array_map( 'unlink', glob( $root . '/uploads/*' ) );
summary();
