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

        // Remote disconnect. CashFlow calls this when a merchant disconnects the
        // store in the app, so the plugin stops believing it is linked. Before
        // this existed, disconnecting in CashFlow left the plugin holding its
        // store id and secret — the merchant had to also disconnect here by hand,
        // and usually did not know to.
        //
        // Gated on the CONNECTION SECRET, not a WooCommerce key: a merchant who
        // is disconnecting has often already revoked the WC key, and this still
        // has to work.
        register_rest_route( 'cashflow/v1', '/disconnect', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'disconnect' ],
            'permission_callback' => [ $this, 'verify_connection_secret' ],
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
        list( $ck, $cs ) = $this->extract_wc_credentials( $request );
        if ( $ck === '' || $cs === '' ) return false;
        $hash = function_exists( 'wc_api_hash' ) ? wc_api_hash( $ck ) : hash_hmac( 'sha256', $ck, 'woocommerce-api' );
        // Require write permissions: a leaked READ-ONLY WC key must not be able to
        // authenticate /configure and poison the connection secret, escalating a
        // read-only leak into write access on order-status/stock/courier endpoints.
        $row  = $wpdb->get_row( $wpdb->prepare(
            "SELECT consumer_secret FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s AND permissions IN ('write','read_write') LIMIT 1", $hash ) );
        return $row && hash_equals( (string) $row->consumer_secret, (string) $cs );
    }

    // The WC key can arrive three ways. A number of Apache/LiteSpeed/CGI hosts
    // strip the Authorization header before PHP ever sees it — which is
    // indistinguishable from wrong credentials and silently breaks /configure,
    // leaving the store "connected" in CashFlow and unconfigured here.
    //   1. X-CashFlow-* custom headers — never stripped; what the backend sends
    //      alongside Basic since BE v1.31.2.
    //   2. Authorization: Basic … — the standard path, incl. the
    //      REDIRECT_HTTP_AUTHORIZATION form mod_rewrite leaves behind.
    //   3. PHP_AUTH_USER/PW — when PHP parsed Basic natively (mod_php).
    // SECURITY: every path ends at the SAME write-capable woocommerce_api_keys
    // check in verify_wc_key(). These only change how the secret is transported,
    // never what is accepted — no path skips validation.
    private function extract_wc_credentials( $request ) {
        $ck = (string) $request->get_header( 'X-CashFlow-WC-Key' );
        $cs = (string) $request->get_header( 'X-CashFlow-WC-Secret' );
        if ( $ck !== '' && $cs !== '' ) return [ $ck, $cs ];

        $auth = (string) $request->get_header( 'Authorization' );
        if ( $auth === '' && ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            $auth = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if ( $auth === '' && function_exists( 'getallheaders' ) ) {
            foreach ( (array) getallheaders() as $name => $value ) {
                if ( strtolower( $name ) === 'authorization' ) { $auth = (string) $value; break; }
            }
        }
        if ( stripos( $auth, 'Basic ' ) === 0 ) {
            $decoded = base64_decode( substr( $auth, 6 ), true );
            if ( $decoded && strpos( $decoded, ':' ) !== false ) {
                list( $u, $p ) = explode( ':', $decoded, 2 );
                if ( $u !== '' && $p !== '' ) return [ (string) $u, (string) $p ];
            }
        }
        if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) && ! empty( $_SERVER['PHP_AUTH_PW'] ) ) {
            return [ (string) $_SERVER['PHP_AUTH_USER'], (string) $_SERVER['PHP_AUTH_PW'] ];
        }
        return [ '', '' ];
    }

    public function status( $request ) {
        $s = CashFlow_Plugin::get_settings();
        $res = new WP_REST_Response( [
            'version'        => CASHFLOW_VERSION,
            'connected'      => ! empty( $s['connected'] ),
            'store_id'       => $s['store_id'] ?? '',
            'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : ( function_exists('WC') ? WC()->version : '' ),
            // The EFFECTIVE prefix — what this site actually stamps on order
            // numbers, after the same precedence cf_display_order_number uses.
            // Exposed so CashFlow can VERIFY a pushed prefix instead of taking
            // its own HTTP 200 as proof: a 2xx on /configure only proves the
            // request was accepted. Reporting a prefix as applied when the
            // store was still using a different one is what sent a merchant
            // hunting a phantom conflict (bindiya.pk, 2026-07-19).
            'order_prefix'   => function_exists( 'cf_get_prefix' ) ? cf_get_prefix() : '',
        ], 200 );

        // /status is a LIVE heartbeat: CashFlow reads it to decide whether this
        // site really holds the connection. A managed host's proxy caching it
        // (SiteGround does — proven on a live store 2026-07-18, x-proxy-cache:
        // HIT) makes a freshly-connected store keep reporting connected:false
        // for as long as the cache lives, so a healthy connection looks broken.
        // Tell every layer not to store it. The backend also cache-busts the URL,
        // because that proxy ignores Cache-Control on the REQUEST — this header
        // is the response-side half, and the one that fixes other callers too.
        nocache_headers();
        $res->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        return $res;
    }

    public function disconnect( $request ) {
        // Mirrors the local admin disconnect exactly (admin/class-admin.php), so
        // remote and local disconnect leave the site in the SAME state — no
        // second, subtly different code path to drift.
        delete_option( 'cashflow_connection_secret' );
        delete_option( 'cashflow_order_prefix' );
        CashFlow_Plugin::save_settings( [
            'connected'    => false,
            'store_id'     => '',
            'org_id'       => '',
            'connected_at' => '',
            'store_url'    => '',
        ] );
        return new WP_REST_Response( [ 'ok' => true, 'connected' => false ], 200 );
    }

    public function configure( $request ) {
        $store_id = sanitize_text_field( (string) $request->get_param( 'store_id' ) );
        $org_id   = sanitize_text_field( (string) $request->get_param( 'org_id' ) );
        $secret   = (string) $request->get_param( 'connection_secret' );
        $prefix   = sanitize_text_field( (string) $request->get_param( 'order_prefix' ) );
        $api_base = esc_url_raw( (string) $request->get_param( 'api_base' ) );
        if ( ! $store_id || ! $secret ) {
            return new WP_Error( 'bad_request', 'store_id and connection_secret are required', [ 'status' => 400 ] );
        }
        update_option( 'cashflow_connection_secret', $secret );
        // The backend declares its own address. Only a valid https origin is
        // stored — a bad value must not be able to redirect this site's
        // outbound calls (they carry the connection secret) to an arbitrary
        // host, and must not silently disable them either. An absent value
        // leaves any existing setting alone, so an older backend that does not
        // send api_base cannot wipe a working one.
        if ( $api_base !== '' ) {
            if ( CashFlow_Plugin::is_valid_api_base( $api_base ) ) {
                update_option( 'cashflow_api_base', untrailingslashit( $api_base ) );
            } else {
                CashFlow_Plugin::log( 'configure', 'store', 0, 'error',
                    'Rejected api_base (not a valid https origin): ' . $api_base );
            }
        }
        // Absent vs explicitly-empty are DIFFERENT instructions, exactly as for
        // api_base above (Rule #14 — the sibling case right there already got
        // this right). An absent order_prefix means "not my department" and
        // must leave the stored value alone; only an explicit empty string
        // clears it. Treating absent as "clear" meant any /configure call that
        // did not happen to carry a prefix wiped the one CashFlow had pushed,
        // dropping the store back to its domain-derived local default.
        $raw_prefix = $request->get_param( 'order_prefix' );
        if ( $raw_prefix !== null ) {
            if ( $prefix !== '' ) { update_option( 'cashflow_order_prefix', $prefix ); }
            else { delete_option( 'cashflow_order_prefix' ); }
        }
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
