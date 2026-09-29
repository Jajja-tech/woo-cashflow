<?php
/**
 * The shop hands CashFlow the pictures it cannot fetch (class-media.php).
 * Executed against a real temp uploads folder and a recorded HTTP call.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-catalog.php';
require_once __DIR__ . '/../includes/class-media.php';

$root = sys_get_temp_dir() . '/cf-media-' . getmypid();
@mkdir( $root . '/uploads/2026/09', 0777, true );
file_put_contents( $root . '/uploads/2026/09/2.webp', 'WEBPBYTES' );
file_put_contents( $root . '/secret.txt', 'NOT A PICTURE' );
file_put_contents( $root . '/uploads/2026/09/bandana maroon.png', 'PNGBYTES' );

CashFlow_Media::$uploads  = [ 'baseurl' => 'https://zensha.pk/wp-content/uploads', 'basedir' => $root . '/uploads' ];
CashFlow_Media::$api_base = function () { return 'https://api.cashflow.pk'; };
$sent = [];
$reply = 200;
CashFlow_Media::$http = function ( $url, $args ) use ( &$sent, &$reply ) {
    $sent[] = [ 'url' => $url, 'args' => $args ];
    return [ 'response' => [ 'code' => $reply ], 'body' => '{}' ];
};

echo "── which URLs map to a file\n";
ok( 'a picture under uploads maps to its file', CashFlow_Media::local_file( 'https://zensha.pk/wp-content/uploads/2026/09/2.webp' ) === realpath( $root . '/uploads/2026/09/2.webp' ) );
ok( 'http and www. do not matter', CashFlow_Media::local_file( 'http://www.zensha.pk/wp-content/uploads/2026/09/2.webp' ) !== null );
ok( 'an encoded space is decoded', CashFlow_Media::local_file( 'https://zensha.pk/wp-content/uploads/2026/09/bandana%20maroon.png' ) !== null );
ok( 'another site is refused', CashFlow_Media::local_file( 'https://evil.pk/wp-content/uploads/2026/09/2.webp' ) === null );
ok( 'a path outside uploads is refused', CashFlow_Media::local_file( 'https://zensha.pk/wp-content/uploads/../../secret.txt' ) === null );
ok( 'an encoded ../ is refused', CashFlow_Media::local_file( 'https://zensha.pk/wp-content/uploads/%2e%2e/secret.txt' ) === null );
ok( 'a missing file is null', CashFlow_Media::local_file( 'https://zensha.pk/wp-content/uploads/2026/09/gone.webp' ) === null );
ok( 'a URL outside the uploads path is refused', CashFlow_Media::local_file( 'https://zensha.pk/wp-content/themes/x.png' ) === null );

echo "── a run sends what CashFlow asked for\n";
CF_TestState::$options['cashflow_connection_secret'] = 'secret-xyz';
CF_TestState::$options['siteurl'] = 'https://zensha.pk';
CF_TestState::$options['home'] = 'https://zensha.pk';
CF_TestState::$api_responses['/plugin/media/wanted'] = [ [ 'ok' => true, 'status' => 200, 'data' => [ 'wanted' => [
    [ 'url' => 'https://zensha.pk/wp-content/uploads/2026/09/2.webp' ],
    [ 'url' => 'https://zensha.pk/wp-content/uploads/2026/09/gone.webp' ],
] ] ] ];
CashFlow_Media::run();
$ask = end( CF_TestState::$api_calls );
ok( 'it asked CashFlow which pictures it needs, stating its site', $ask['endpoint'] === '/plugin/media/wanted' && strpos( (string) $ask['body'], '"siteurl":"https:\/\/zensha.pk"' ) !== false, (string) $ask['body'] );
ok( 'exactly one picture was sent', count( $sent ) === 1, (string) count( $sent ) );
$h = $sent[0]['args']['headers'] ?? [];
ok( 'to the upload endpoint', ( $sent[0]['url'] ?? '' ) === 'https://api.cashflow.pk/plugin/media/upload' );
ok( 'the body is the file\'s own bytes', ( $sent[0]['args']['body'] ?? '' ) === 'WEBPBYTES' );
ok( 'named by its URL, typed by its extension', ( $h['X-CashFlow-Source-Url'] ?? '' ) === 'https://zensha.pk/wp-content/uploads/2026/09/2.webp' && ( $h['Content-Type'] ?? '' ) === 'image/webp' );
ok( 'authorised by the connection secret, site in headers', ( $h['Authorization'] ?? '' ) === 'Bearer secret-xyz' && ( $h['X-CashFlow-Home'] ?? '' ) === 'https://zensha.pk' );
$stats = CF_TestState::$options['cashflow_media_last'];
ok( 'the run records 1 sent and 1 skipped', $stats['sent'] === 1 && $stats['skipped'] === 1, json_encode( $stats ) );
ok( 'the unreadable URL is not offered again today', isset( CF_TestState::$options['cashflow_media_skip']['https://zensha.pk/wp-content/uploads/2026/09/gone.webp'] ) );

echo "── a refusal is recorded, not retried every run\n";
$sent = [];
$reply = 404;
CF_TestState::$api_responses['/plugin/media/wanted'] = [ [ 'ok' => true, 'status' => 200, 'data' => [ 'wanted' => [
    [ 'url' => 'https://zensha.pk/wp-content/uploads/2026/09/bandana%20maroon.png' ],
] ] ] ];
CashFlow_Media::run();
$stats = CF_TestState::$options['cashflow_media_last'];
ok( 'the refusal is counted with its reason', $stats['refused'] === 1 && strpos( (string) $stats['error'], 'HTTP 404' ) !== false, json_encode( $stats ) );
ok( 'and skipped for the day', isset( CF_TestState::$options['cashflow_media_skip']['https://zensha.pk/wp-content/uploads/2026/09/bandana%20maroon.png'] ) );

echo "── no secret, no calls\n";
$before = count( CF_TestState::$api_calls );
unset( CF_TestState::$options['cashflow_connection_secret'] );
CashFlow_Media::run();
ok( 'nothing is asked when not connected', count( CF_TestState::$api_calls ) === $before );

echo "── CashFlow unreachable is recorded\n";
CF_TestState::$options['cashflow_connection_secret'] = 'secret-xyz';
CF_TestState::$api_responses['/plugin/media/wanted'] = [ [ 'ok' => false, 'status' => 503, 'error' => 'down', 'data' => null ] ];
CashFlow_Media::run();
ok( 'the failure is on record', strpos( (string) CF_TestState::$options['cashflow_media_last']['error'], 'down' ) !== false );

array_map( 'unlink', glob( $root . '/uploads/2026/09/*' ) );
@unlink( $root . '/secret.txt' );
summary();
