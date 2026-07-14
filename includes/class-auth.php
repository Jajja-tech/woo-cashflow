<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Auth — One-click connect flow
 */
class CashFlow_Auth {

    public function __construct() {
        add_action( 'wp_ajax_cashflow_connect',    [ $this, 'handle_connect'    ] );
        add_action( 'wp_ajax_cashflow_disconnect', [ $this, 'handle_disconnect' ] );
        add_action( 'wp_ajax_cashflow_verify',     [ $this, 'handle_verify'     ] );
        add_action( 'wp_ajax_cashflow_pre_check',  [ $this, 'handle_pre_check'  ] );
    }

    // ── Pre-check ───────────────────────────────────────────────────
    public function handle_pre_check() {
        check_ajax_referer( 'cashflow_nonce', 'nonce' );
        $token = CashFlow_Security::sanitize_token( $_POST['cashflow_token'] ?? '' );
        if ( empty( $token ) ) wp_send_json_error( [ 'message' => 'Token is required' ] );

        if ( strpos( $token, 'cf_live_' ) === 0 ) {
            $result = CashFlow_Plugin::api_request( '/tokens/verify', 'POST', [ 'token' => $token ], $token );
        } else {
            $result = CashFlow_Plugin::api_request( '/auth/me', 'GET', null, $token );
        }
        if ( ! $result['ok'] ) wp_send_json_error( [ 'message' => 'Invalid token — please copy it again from CashFlow settings' ] );
        wp_send_json_success( [ 'valid' => true, 'site_url' => CashFlow_Security::get_verified_site_url() ] );
    }

    // ── Full Connect ────────────────────────────────────────────────
    public function handle_connect() {
        check_ajax_referer( 'cashflow_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }

        $token = CashFlow_Security::sanitize_token( $_POST['cashflow_token'] ?? '' );
        if ( empty( $token ) ) wp_send_json_error( [ 'message' => 'Token is required' ] );

        // Step 1: Verify token
        if ( strpos( $token, 'cf_live_' ) === 0 ) {
            $me = CashFlow_Plugin::api_request( '/tokens/verify', 'POST', [
                'token'     => $token,
                'store_url' => get_site_url(),
            ], $token );
        } else {
            $me = CashFlow_Plugin::api_request( '/auth/me', 'GET', null, $token );
        }
        if ( ! $me['ok'] ) wp_send_json_error( [ 'message' => 'Invalid CashFlow token' ] );

        $org_id   = strpos( $token, 'cf_live_' ) === 0
            ? ( $me['data']['org_id'] ?? null )
            : ( $me['data']['profile']['org_id'] ?? null );
        $site_url = CashFlow_Security::get_verified_site_url();

        // Step 2: Check/register store
        $store_check = CashFlow_Plugin::api_request( '/stores/verify-ownership', 'POST', [
            'site_url'  => $site_url,
            'platform'  => 'woocommerce',
        ], $token );

        $store_id = null;
        if ( $store_check['ok'] && ! empty( $store_check['data']['store_id'] ) ) {
            $store_id = $store_check['data']['store_id'];
        } else {
            $register = CashFlow_Plugin::api_request( '/stores', 'POST', [
                'name'      => get_bloginfo( 'name' ),
                'platform'  => 'woocommerce',
                'store_url' => $site_url,
                'currency'  => get_woocommerce_currency(),
                'timezone'  => wp_timezone_string(),
            ], $token );
            if ( ! $register['ok'] ) {
                wp_send_json_error( [ 'message' => $register['data']['error'] ?? 'Could not register store' ] );
            }
            $store_id = $register['data']['store']['id'] ?? null;
        }
        if ( ! $store_id ) wp_send_json_error( [ 'message' => 'Could not get store ID' ] );

        // Step 3: Generate WC API keys
        $keys = $this->generate_wc_api_keys();
        if ( is_wp_error( $keys ) ) wp_send_json_error( [ 'message' => $keys->get_error_message() ] );

        // Step 4 (v5): create the platform CHANNEL and mint the per-connection
        // secret. ONE call replaces the legacy update-credentials + plugin-handshake:
        // WC creds go into integration_connections (encrypted); nothing to stores.* .
        $connect = CashFlow_Plugin::api_request( '/integrations/woocommerce/connect-platform', 'POST', [
            'store_id'    => $store_id,
            'credentials' => [
                'store_url'       => $site_url,
                'consumer_key'    => $keys['consumer_key'],
                'consumer_secret' => $keys['consumer_secret'],
            ],
        ], $token );
        if ( ! $connect['ok'] || empty( $connect['data']['connectionSecret'] ) ) {
            $this->delete_wc_api_key( $keys['key_id'] );
            wp_send_json_error( [ 'message' => $connect['data']['error'] ?? 'Could not connect the store to CashFlow' ] );
        }
        $connection_secret = $connect['data']['connectionSecret'];
        update_option( 'cashflow_connection_secret', $connection_secret );
        delete_option( 'cashflow_plugin_secret' ); // legacy secret retired in v5

        // Step 5 (v5): order prefix is a store SETTING now — read it from /settings.
        $settings_res = CashFlow_Plugin::api_request( '/settings?scope=store&scope_id=' . $store_id, 'GET', null, $token );
        $prefix = $settings_res['ok'] ? ( $settings_res['data']['settings']['store.order_prefix'] ?? '' ) : '';
        if ( ! empty( $prefix ) ) {
            update_option( 'cashflow_order_prefix', sanitize_text_field( $prefix ) );
        } else {
            delete_option( 'cashflow_order_prefix' );
        }

        // Step 6 (v5): register native WC webhooks signed with the CONNECTION SECRET
        // (the backend verifies X-WC-Webhook-Signature against it).
        $webhook_result = ( new CashFlow_Webhooks() )->register_all_webhooks( $token, $store_id, $connection_secret );

        // Step 7: Save settings
        CashFlow_Plugin::save_settings( [
            'cashflow_token' => $token,
            'store_id'       => $store_id,
            'org_id'         => $org_id,
            'connected'      => true,
            'connected_at'   => current_time( 'mysql' ),
            'store_url'      => $site_url,
            'wc_key_id'      => $keys['key_id'],
        ] );

        CashFlow_Plugin::log( 'connect', 'store', 0, 'success', "Connected: $site_url → Store: $store_id" );
        wp_send_json_success( [
            'message'  => 'Connected successfully! 🎉',
            'store_id' => $store_id,
            'site_url' => $site_url,
            'webhooks' => $webhook_result,
        ] );
    }

    // ── Disconnect ──────────────────────────────────────────────────
    public function handle_disconnect() {
        check_ajax_referer( 'cashflow_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }
        $settings = CashFlow_Plugin::get_settings();
        if ( ! empty( $settings['store_id'] ) && ! empty( $settings['cashflow_token'] ) ) {
            CashFlow_Plugin::api_request( '/stores/' . $settings['store_id'] . '/plugin-disconnect', 'POST', [
                'site_url' => CashFlow_Security::get_verified_site_url()
            ] );
        }
        if ( ! empty( $settings['wc_key_id'] ) ) $this->delete_wc_api_key( $settings['wc_key_id'] );
        $webhook_ids = get_option( 'cashflow_webhook_ids', [] );
        foreach ( $webhook_ids as $id ) {
            $wh = new WC_Webhook( $id );
            if ( $wh->get_id() ) $wh->delete( true );
        }
        delete_option( 'cashflow_webhook_ids' );
        delete_option( 'cashflow_plugin_secret' );
        CashFlow_Plugin::save_settings( [
            'connected' => false, 'cashflow_token' => '', 'store_id' => '',
            'org_id' => '', 'wc_key_id' => '', 'connected_at' => '',
        ] );
        CashFlow_Plugin::log( 'disconnect', 'store', 0, 'success', 'Disconnected' );
        wp_send_json_success( [ 'message' => 'Disconnected successfully' ] );
    }

    // ── Verify ──────────────────────────────────────────────────────
    public function handle_verify() {
        check_ajax_referer( 'cashflow_nonce', 'nonce' );
        $settings = CashFlow_Plugin::get_settings();
        if ( empty( $settings['cashflow_token'] ) ) {
            wp_send_json_error( [ 'message' => 'Not connected' ] );
        }
        $token = $settings['cashflow_token'];

        // cf_live_ token → /tokens/verify, JWT → /auth/me
        if ( strpos( $token, 'cf_live_' ) === 0 ) {
            $result = CashFlow_Plugin::api_request( '/tokens/verify', 'POST', [ 'token' => $token ], $token );
        } else {
            $result = CashFlow_Plugin::api_request( '/auth/me', 'GET', null, $token );
        }

        $ping    = wp_remote_get( get_site_url() . '/wp-json/cashflow/v1/ping', [ 'timeout' => 5 ] );
        $ping_ok = ! is_wp_error( $ping ) && wp_remote_retrieve_response_code( $ping ) === 200;

        if ( $result['ok'] ) {
            wp_send_json_success( [
                'message'       => 'Connection verified ✓',
                'cashflow_api'  => '✅ Reachable',
                'rest_endpoint' => $ping_ok ? '✅ Working' : '⚠️ Not reachable',
                'store_id'      => $settings['store_id'],
                'connected_at'  => $settings['connected_at'],
            ] );
        } else {
            wp_send_json_error( [ 'message' => 'Token invalid or expired — please reconnect' ] );
        }
    }

    // ── Generate WC API keys ────────────────────────────────────────
    private function generate_wc_api_keys() {
        global $wpdb;
        $user = wp_get_current_user();
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT key_id FROM {$wpdb->prefix}woocommerce_api_keys WHERE description LIKE 'CashFlow Sync%%' AND user_id = %d LIMIT 1",
            $user->ID
        ) );
        if ( $existing ) $this->delete_wc_api_key( $existing );
        $consumer_key    = 'ck_' . ( function_exists( 'wc_rand_hash' ) ? wc_rand_hash() : bin2hex( random_bytes( 20 ) ) );
        $consumer_secret = 'cs_' . ( function_exists( 'wc_rand_hash' ) ? wc_rand_hash() : bin2hex( random_bytes( 20 ) ) );
        $key_hash        = function_exists( 'wc_api_hash' )
            ? wc_api_hash( $consumer_key )
            : hash_hmac( 'sha256', $consumer_key, 'woocommerce-api' );
        $result = $wpdb->insert(
            $wpdb->prefix . 'woocommerce_api_keys',
            [
                'user_id'         => $user->ID,
                'description'     => 'CashFlow Sync — ' . current_time( 'Y-m-d H:i:s' ),
                'permissions'     => 'read_write',
                'consumer_key'    => $key_hash,
                'consumer_secret' => $consumer_secret,
                'truncated_key'   => substr( $consumer_key, -7 ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
        if ( ! $result ) return new WP_Error( 'key_error', 'Failed to generate WooCommerce API keys' );
        return [
            'key_id'          => $wpdb->insert_id,
            'consumer_key'    => $consumer_key,
            'consumer_secret' => $consumer_secret,
        ];
    }

    private function delete_wc_api_key( $key_id ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', [ 'key_id' => (int) $key_id ], [ '%d' ] );
    }

    private function get_plugin_endpoints() {
        $base = get_site_url() . '/wp-json/cashflow/v1';
        return [
            'order_status' => $base . '/order-status',
            'update_stock' => $base . '/update-stock',
            'courier_meta' => $base . '/courier-meta',
            'ping'         => $base . '/ping',
            'status'       => $base . '/status',
        ];
    }
}
