<?php
/**
 * Five pictures really do go at once (class-media.php, curl_many()).
 *
 * mediaBacklog.test.php stands a model in for the sending, so it cannot show
 * that the real sender holds several requests open together, or that what
 * arrives is what was sent. This file runs the real sender against real web
 * servers on this machine: five of PHP's own, one per picture of a batch,
 * each answering an upload after the wait its picture's name asks for and
 * writing down what it got. (One PHP server answers one request at a time;
 * five of them, each stopped by its own process number, stand in for a
 * CashFlow that works on five at once.)
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-catalog.php';
require_once __DIR__ . '/../includes/class-media.php';

if ( ! function_exists( 'curl_multi_init' ) ) {
    ok( 'PHP on this machine has curl, which this test needs', false );
    summary();
    return;
}

$root = sys_get_temp_dir() . '/cf-media-parallel-' . getmypid();
@mkdir( $root . '/uploads', 0777, true );
@mkdir( $root . '/got', 0777, true );

// The stand-in for CashFlow: waits, answers, and records the request.
file_put_contents( $root . '/server.php', '<?php
$src  = $_SERVER["HTTP_X_CASHFLOW_SOURCE_URL"] ?? "";
$body = file_get_contents( "php://input" );
$in   = microtime( true );
if ( preg_match( "/wait(\d+)/", $src, $m ) ) { usleep( (int) $m[1] * 100000 ); }
$code = preg_match( "/refuse(\d+)/", $src, $m ) ? (int) $m[1] : 200;
file_put_contents( ' . var_export( $root . '/got', true ) . ' . "/" . md5( $src ) . ".json", json_encode( [
    "src" => $src, "in" => $in, "out" => microtime( true ), "bytes" => strlen( $body ), "md5" => md5( $body ),
    "type" => $_SERVER["CONTENT_TYPE"] ?? "", "auth" => $_SERVER["HTTP_AUTHORIZATION"] ?? "", "method" => $_SERVER["REQUEST_METHOD"],
    "path" => parse_url( $_SERVER["REQUEST_URI"], PHP_URL_PATH ), "version" => $_SERVER["HTTP_X_PLUGIN_VERSION"] ?? "",
    "agent" => $_SERVER["HTTP_USER_AGENT"] ?? "",
] ) );
http_response_code( $code );
echo "{}";
' );

$servers = [];
$ports   = [];
$up      = true;
for ( $n = 0; $n < 5; $n++ ) {
    $sock = stream_socket_server( 'tcp://127.0.0.1:0' );
    $port = (int) substr( strrchr( stream_socket_get_name( $sock, false ), ':' ), 1 );
    fclose( $sock );
    $servers[] = proc_open(
        [ PHP_BINARY, '-S', '127.0.0.1:' . $port, $root . '/server.php' ],
        [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ],
        $pipes, $root
    );
    $ports[] = $port;
    $there   = false;
    for ( $i = 0; $i < 100 && ! $there; $i++ ) {
        $c = @fsockopen( '127.0.0.1', $port, $e, $es, 0.1 );
        if ( $c ) { fclose( $c ); $there = true; } else { usleep( 50000 ); }
    }
    $up = $up && $there;
}
ok( 'the five stand-in servers started', $up );

CF_TestState::$options = [ 'siteurl' => 'https://zensha.pk', 'home' => 'https://zensha.pk' ];
CashFlow_Media::$uploads  = [ 'baseurl' => 'https://zensha.pk/wp-content/uploads', 'basedir' => $root . '/uploads' ];
// Each request of a batch asks for the address once, so each goes to its own server.
$turn = 0;
CashFlow_Media::$api_base = function () use ( $ports, &$turn ) { return 'http://127.0.0.1:' . $ports[ $turn++ % 5 ]; };
CashFlow_Media::$http      = null;
CashFlow_Media::$http_many = null;
CashFlow_Media::$clock     = null;

function pic( $name, $bytes ) {
    global $root;
    file_put_contents( $root . '/uploads/' . $name, $bytes );
    return [ 'url' => 'https://zensha.pk/wp-content/uploads/' . $name, 'file' => $root . '/uploads/' . $name ];
}
function got( $url ) {
    global $root;
    $f = $root . '/got/' . md5( $url ) . '.json';
    return is_file( $f ) ? json_decode( file_get_contents( $f ), true ) : null;
}

echo "── five uploads that each take a second are answered in about one second, not five\n";
$batch = [];
for ( $i = 1; $i <= 5; $i++ ) {
    $batch[] = pic( 'wait10-' . $i . '.webp', random_bytes( 200000 + $i ) );
}
$began = microtime( true );
$outs  = CashFlow_Media::upload_many( 'secret-xyz', $batch, 20 );
$took  = microtime( true ) - $began;
ok( 'all five were sent', array_column( $outs, 'sent' ) === [ true, true, true, true, true ], json_encode( $outs ) );
ok( 'in under two and a half seconds', $took >= 1.0 && $took < 2.5, round( $took, 2 ) . ' s' );
$ins  = array_map( function ( $b ) { return got( $b['url'] )['in']; }, $batch );
$outz = array_map( function ( $b ) { return got( $b['url'] )['out']; }, $batch );
ok( 'the server had all five in hand before it answered any', max( $ins ) < min( $outz ) );

echo "── what arrives is what was sent\n";
$g = got( $batch[2]['url'] );
ok( 'the bytes are the file\'s bytes', $g['bytes'] === 200003 && $g['md5'] === md5_file( $batch[2]['file'] ) );
ok( 'as a POST to the upload address', $g['method'] === 'POST' && $g['path'] === '/plugin/media/upload' );
ok( 'with the picture\'s type, the secret, its address and the plugin version',
    $g['type'] === 'image/webp' && $g['auth'] === 'Bearer secret-xyz' && $g['src'] === $batch[2]['url'] && $g['version'] === CASHFLOW_VERSION, json_encode( $g ) );
ok( 'named as WordPress names itself', strpos( $g['agent'], 'WordPress/' ) === 0 );

echo "── each picture of a batch gets its own answer\n";
$mixed = [
    'a' => pic( 'fine.webp', 'WEBP-fine' ),
    'b' => pic( 'refuse422.webp', 'WEBP-refused' ),
    'c' => pic( 'wait30.webp', 'WEBP-slow' ),          // answers after 3 s; the batch allows 1
    'd' => pic( 'fine2.jpg', 'JPG-fine' ),
];
$began = microtime( true );
$outs  = CashFlow_Media::upload_many( 'secret-xyz', $mixed, 1 );
$took  = microtime( true ) - $began;
ok( 'the answers come back under the keys the pictures went in with', array_diff( [ 'a', 'b', 'c', 'd' ], array_keys( $outs ) ) === [] && count( $outs ) === 4 );
ok( 'the two good ones were sent', $outs['a']['sent'] && $outs['d']['sent'] );
ok( 'the refused one says so, and did get an answer', ! $outs['b']['sent'] && ! $outs['b']['no_answer'] && strpos( $outs['b']['reason'], 'HTTP 422' ) !== false, json_encode( $outs['b'] ) );
ok( 'the slow one got no answer inside its one second', ! $outs['c']['sent'] && $outs['c']['no_answer'] === true && strpos( $outs['c']['reason'], 'cURL error 28' ) === 0, json_encode( $outs['c'] ) );
ok( 'and the batch ended at the timeout, not at the slow picture\'s three seconds', $took < 2.0, round( $took, 2 ) . ' s' );
ok( 'a jpg went as a jpg', got( $mixed['d']['url'] )['type'] === 'image/jpeg' );

echo "── nothing listening at all is no answer, not a refusal\n";
$sock   = stream_socket_server( 'tcp://127.0.0.1:0' );
$closed = (int) substr( strrchr( stream_socket_get_name( $sock, false ), ':' ), 1 );
fclose( $sock );
CashFlow_Media::$api_base = function () use ( $closed ) { return 'http://127.0.0.1:' . $closed; };
$outs = CashFlow_Media::upload_many( 'secret-xyz', [ pic( 'x1.webp', 'X1' ), pic( 'x2.webp', 'X2' ) ], 2 );
ok( 'both report no answer', $outs[0]['no_answer'] === true && $outs[1]['no_answer'] === true && ! $outs[0]['sent'], json_encode( $outs ) );

foreach ( $servers as $server ) {
    proc_terminate( $server );
    proc_close( $server );
}
array_map( 'unlink', array_merge( glob( $root . '/uploads/*' ), glob( $root . '/got/*' ), [ $root . '/server.php' ] ) );
summary();
