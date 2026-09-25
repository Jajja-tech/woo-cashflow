<?php
/**
 * THE REAL api_request, EXECUTED.
 *
 * woo-cashflow.php boots the whole plugin, so bootstrap.php stands a RECORDER
 * in for CashFlow_Plugin — and a recorder can never prove what the real method
 * puts on the wire. This file cuts the real class out of the real file, renames
 * it so it cannot collide with the recorder, and runs it against a recorded
 * wp_remote_request. The timeout and the body bytes are observed, not read.
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['cf_http'] = [];
if ( ! function_exists( 'wp_remote_request' ) ) {
    function wp_remote_request( $url, $args ) {
        $GLOBALS['cf_http'][] = [ 'url' => $url, 'args' => $args ];
        return [ 'response' => [ 'code' => 200 ], 'body' => '{"ok":true}' ];
    }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $r ) { return $r['body']; }
}
if ( ! function_exists( 'untrailingslashit' ) ) {
    function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
}

require_once __DIR__ . '/support/realPlugin.php';
cf_load_real_plugin();
ok( 'the real CashFlow_Plugin class is found in woo-cashflow.php', class_exists( 'CF_Real_Plugin', false ) );

echo "── an array body is encoded, and the default timeout is unchanged\n";
CF_Real_Plugin::api_request( '/plugin/sync/poll', 'POST', [ 'a' => 1 ], 'secret-xyz' );
$c = $GLOBALS['cf_http'][0] ?? null;
ok( 'it went to the backend endpoint', ( $c['url'] ?? '' ) === 'https://api.cashflow.pk/plugin/sync/poll', (string) ( $c['url'] ?? '' ) );
ok( 'the array was sent as its JSON', ( $c['args']['body'] ?? null ) === '{"a":1}', var_export( $c['args']['body'] ?? null, true ) );
ok( 'the default timeout is still 30', ( $c['args']['timeout'] ?? null ) === 30, var_export( $c['args']['timeout'] ?? null, true ) );
ok( 'it carries the connection secret as a Bearer token', ( $c['args']['headers']['Authorization'] ?? '' ) === 'Bearer secret-xyz' );

echo "── a string body is sent byte-for-byte, with the timeout asked for\n";
$GLOBALS['cf_http'] = [];
$bytes = '{"site":{"siteurl":"https://example.test"},"products":[{"name":"Café \u{FFFD}"}]}';
$res   = CF_Real_Plugin::api_request( '/plugin/catalog/products', 'POST', $bytes, 'secret-xyz', 10 );
$c     = $GLOBALS['cf_http'][0] ?? null;
ok( 'the string was NOT re-encoded', ( $c['args']['body'] ?? null ) === $bytes, var_export( $c['args']['body'] ?? null, true ) );
ok( 'the 10-second timeout was honoured', ( $c['args']['timeout'] ?? null ) === 10, var_export( $c['args']['timeout'] ?? null, true ) );
ok( 'the answer is still decoded for the caller', ( $res['ok'] ?? false ) === true && ( $res['data']['ok'] ?? false ) === true );

echo "── a nonsense timeout cannot become zero (zero means no timeout at all)\n";
$GLOBALS['cf_http'] = [];
CF_Real_Plugin::api_request( '/x', 'POST', '{}', 'secret-xyz', 0 );
ok( 'a timeout of 0 is raised to 1', ( $GLOBALS['cf_http'][0]['args']['timeout'] ?? null ) === 1 );

echo "── the harness recorder mirrors the new signature\n";
CF_TestState::reset();
CF_TestState::$api_responses['/y'][] = function ( array $call ) { return [ 'ok' => true, 'status' => 200, 'data' => [ 'seen' => $call['timeout'] ] ]; };
$r = CashFlow_Plugin::api_request( '/y', 'POST', '{}', 't', 10 );
ok( 'the recorder records the timeout', ( CF_TestState::$api_calls[0]['timeout'] ?? null ) === 10 );
ok( 'a closure response runs at the moment of the request', is_array( $r ) && ( $r['data']['seen'] ?? null ) === 10 );

summary();
