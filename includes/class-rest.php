<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_REST
 * Additional REST endpoints exposed by the plugin.
 * Push model: `/configure` receives the connection identity + secret from
 * the backend after WC-key-authed approval; `/status` is a public,
 * secret-free heartbeat the app uses for its install check.
 */
class CashFlow_REST {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register' ] );
    }

    public function register() {
        // Public heartbeat — the app's install check (no secrets).
        register_rest_route( 'cashflow/v1', '/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'status' ],
            'permission_callback' => '__return_true',
        ] );

        // Backend hands the plugin its identity + connection secret after WC Approve.
        // Authenticated by proving possession of a valid WooCommerce API key for this site.
        register_rest_route( 'cashflow/v1', '/configure', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'configure' ],
            'permission_callback' => [ $this, 'verify_wc_key' ],
        ] );

        // Sync log + status list — gated on the connection secret (reuse CashFlow_Sync's check).
        register_rest_route( 'cashflow/v1', '/sync-log', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_sync_log' ],
            'permission_callback' => [ $this, 'verify_connection_secret' ],
        ] );
        register_rest_route( 'cashflow/v1', '/order-statuses', [
            'methods'             => 'GET',
            'callback'            => fn() => new WP_REST_Response( CashFlow_Statuses::get_all_statuses(), 200 ),
            'permission_callback' => [ $this, 'verify_connection_secret' ],
        ] );
    }

    // Backend→plugin calls authenticated by the per-connection secret (same as CashFlow_Sync).
    public function verify_connection_secret( $request ) {
        $secret = get_option( 'cashflow_connection_secret', '' );
        if ( ! $secret ) return false;
        $header = $request->get_header( 'X-CashFlow-Secret' );
        return $header && hash_equals( $secret, (string) $header );
    }

    // /configure auth: the caller must present a valid WooCommerce API key for THIS site
    // (the key WC minted during Approve). A1's configurePlugin sends it as HTTP Basic
    // (base64 of consumer_key:consumer_secret) — parse that, hash the consumer_key the same
    // way WooCommerce stores it, and constant-time compare the secret.
    public function verify_wc_key( $request ) {
        global $wpdb;
        $auth = (string) $request->get_header( 'Authorization' );
        if ( stripos( $auth, 'Basic ' ) !== 0 ) return false;
        $decoded = base64_decode( substr( $auth, 6 ), true );
        if ( ! $decoded || strpos( $decoded, ':' ) === false ) return false;
        list( $ck, $cs ) = explode( ':', $decoded, 2 );
        if ( $ck === '' || $cs === '' ) return false;
        $hash = function_exists( 'wc_api_hash' ) ? wc_api_hash( $ck ) : hash_hmac( 'sha256', $ck, 'woocommerce-api' );
        // Require write permissions: a leaked READ-ONLY WC key must not be able to
        // authenticate /configure and poison the connection secret, escalating a
        // read-only leak into write access on order-status/stock/courier endpoints.
        $row  = $wpdb->get_row( $wpdb->prepare(
            "SELECT consumer_secret FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s AND permissions IN ('write','read_write') LIMIT 1", $hash ) );
        return $row && hash_equals( (string) $row->consumer_secret, (string) $cs );
    }

    public function status( $request ) {
        $s = CashFlow_Plugin::get_settings();
        return new WP_REST_Response( [
            'version'        => CASHFLOW_VERSION,
            'connected'      => ! empty( $s['connected'] ),
            'store_id'       => $s['store_id'] ?? '',
            'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : ( function_exists('WC') ? WC()->version : '' ),
        ], 200 );
    }

    public function configure( $request ) {
        $store_id = sanitize_text_field( (string) $request->get_param( 'store_id' ) );
        $org_id   = sanitize_text_field( (string) $request->get_param( 'org_id' ) );
        $secret   = (string) $request->get_param( 'connection_secret' );
        $prefix   = sanitize_text_field( (string) $request->get_param( 'order_prefix' ) );
        if ( ! $store_id || ! $secret ) {
            return new WP_Error( 'bad_request', 'store_id and connection_secret are required', [ 'status' => 400 ] );
        }
        update_option( 'cashflow_connection_secret', $secret );
        if ( $prefix !== '' ) { update_option( 'cashflow_order_prefix', $prefix ); }
        else { delete_option( 'cashflow_order_prefix' ); }
        CashFlow_Plugin::save_settings( [
            'store_id'     => $store_id,
            'org_id'       => $org_id,
            'connected'    => true,
            'connected_at' => current_time( 'mysql' ),
            'store_url'    => get_site_url(),
        ] );
        CashFlow_Plugin::log( 'configure', 'store', 0, 'success', "Configured by backend → store $store_id" );
        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    public function get_sync_log( $request ) {
        global $wpdb;
        $limit = min( (int) ( $request->get_param( 'limit' ) ?? 50 ), 200 );
        $logs  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cashflow_sync_log ORDER BY created_at DESC LIMIT %d",
            $limit
        ) );
        return new WP_REST_Response( [ 'logs' => $logs ], 200 );
    }
}
