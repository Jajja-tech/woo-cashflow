<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_REST
 * Additional REST endpoints exposed by the plugin.
 * CashFlow backend calls these for plugin secret retrieval etc.
 */
class CashFlow_REST {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register' ] );
    }

    public function register() {
        // Return plugin secret so CashFlow can authenticate future calls
        register_rest_route( 'cashflow/v1', '/handshake', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handshake' ],
            'permission_callback' => '__return_true',
        ] );

        // Status endpoint
        register_rest_route( 'cashflow/v1', '/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'status' ],
            'permission_callback' => [ $this, 'verify_secret' ],
        ] );

        // Sync log
        register_rest_route( 'cashflow/v1', '/sync-log', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_sync_log' ],
            'permission_callback' => [ $this, 'verify_secret' ],
        ] );

        // Order statuses — WC default + CashFlow custom (booked, shipped, returned)
        register_rest_route( 'cashflow/v1', '/order-statuses', [
            'methods'             => 'GET',
            'callback'            => fn() => new WP_REST_Response( CashFlow_Statuses::get_all_statuses(), 200 ),
            'permission_callback' => [ $this, 'verify_secret' ],
        ] );

        // Health check — public, no auth needed
        register_rest_route( 'cashflow/v1', '/ping', [
            'methods'             => 'GET',
            'callback'            => fn() => new WP_REST_Response( [ 'status' => 'ok', 'version' => CASHFLOW_VERSION, 'site_url' => get_site_url() ], 200 ),
            'permission_callback' => '__return_true',
        ] );
    }

    // ── Handshake — CashFlow registers its secret ───────────────────
    public function handshake( $request ) {
        $settings = CashFlow_Plugin::get_settings();
        if ( empty( $settings['connected'] ) ) {
            return new WP_Error( 'not_connected', 'Plugin not connected', [ 'status' => 403 ] );
        }

        // Verify the CashFlow token
        $token = $request->get_header( 'Authorization' );
        $token = str_replace( 'Bearer ', '', $token );

        if ( $token !== $settings['cashflow_token'] ) {
            return new WP_Error( 'invalid_token', 'Invalid token', [ 'status' => 401 ] );
        }

        // Return plugin secret for future authenticated requests
        $secret = get_option( 'cashflow_plugin_secret' );
        if ( ! $secret ) {
            $secret = wp_generate_password( 40, false );
            update_option( 'cashflow_plugin_secret', $secret );
        }

        return new WP_REST_Response( [
            'success'        => true,
            'plugin_secret'  => $secret,
            'store_url'      => get_site_url(),
            'wc_version'     => WC()->version,
            'plugin_version' => CASHFLOW_VERSION,
            'endpoints'      => [
                'order_status'  => get_site_url() . '/wp-json/cashflow/v1/order-status',
                'update_stock'  => get_site_url() . '/wp-json/cashflow/v1/update-stock',
                'courier_meta'  => get_site_url() . '/wp-json/cashflow/v1/courier-meta',
                'sync_orders'   => get_site_url() . '/wp-json/cashflow/v1/sync-orders',
                'ping'          => get_site_url() . '/wp-json/cashflow/v1/ping',
            ],
        ], 200 );
    }

    public function verify_secret( $request ) {
        $secret   = get_option( 'cashflow_plugin_secret', '' );
        $header   = $request->get_header( 'X-CashFlow-Secret' );
        return $secret && hash_equals( $secret, (string) $header );
    }

    public function status( $request ) {
        $settings = CashFlow_Plugin::get_settings();
        return new WP_REST_Response( [
            'connected'      => $settings['connected'] ?? false,
            'store_id'       => $settings['store_id']  ?? '',
            'store_url'      => get_site_url(),
            'wc_version'     => WC()->version,
            'plugin_version' => CASHFLOW_VERSION,
            'php_version'    => PHP_VERSION,
            'webhook_count'  => count( get_option( 'cashflow_webhook_ids', [] ) ),
        ], 200 );
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
