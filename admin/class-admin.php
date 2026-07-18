<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Admin
 * Admin settings page, enqueue scripts.
 */
class CashFlow_Admin {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu'          ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'    ] );
        add_action( 'admin_notices',         [ $this, 'show_notices'      ] );
        add_action( 'wp_ajax_cashflow_save_settings',       [ $this, 'ajax_save_settings'       ] );
        add_action( 'wp_ajax_cashflow_get_sync_log',        [ $this, 'ajax_get_sync_log'        ] );
        add_action( 'wp_ajax_cashflow_disconnect',          [ $this, 'ajax_disconnect'          ] );
    }

    public function add_menu() {
        add_menu_page(
            'CashFlow Sync',
            'CashFlow',
            'manage_woocommerce',
            'cashflow-sync',
            [ $this, 'render_page' ],
            // Base64 SVG (not a PNG URL) so WP-admin sizes it to 20px like every
            // other menu icon — a raw PNG rendered oversized. Same CashFlow mark.
            'data:image/svg+xml;base64,' . base64_encode( cf_logo( 20, false, '#a7aaad' ) ),
            58
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'cashflow-sync' ) === false ) return;
        wp_enqueue_style(  'cashflow-admin', CASHFLOW_PLUGIN_URL . 'assets/admin.css', [], CASHFLOW_VERSION );
        wp_enqueue_script( 'cashflow-admin', CASHFLOW_PLUGIN_URL . 'assets/admin.js',  [ 'jquery' ], CASHFLOW_VERSION, true );
        // ajaxUrl + nonce ONLY — this array is printed into the page HTML.
        // Never localize CashFlow_Plugin::get_settings() wholesale: it carries
        // cashflow_token (and any key later added to the option). The settings
        // page renders its own values server-side in admin/views/settings.php.
        wp_localize_script( 'cashflow-admin', 'cashflowAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'cashflow_nonce' ),
        ] );
    }

    public function show_notices() {
        if ( get_transient( 'cashflow_activated' ) ) {
            delete_transient( 'cashflow_activated' );
            $settings = CashFlow_Plugin::get_settings();
            echo '<div class="notice notice-success is-dismissible"><p>';
            if ( ! empty( $settings['connected'] ) ) {
                echo '<strong>CashFlow Sync</strong> activated successfully!';
            } else {
                $url = admin_url( 'admin.php?page=cashflow-sync' );
                printf( '<strong>CashFlow Sync</strong> activated! <a href="%s">Connect your store →</a>', esc_url( $url ) );
            }
            echo '</p></div>';
        }
    }

    public function ajax_save_settings() {
        check_ajax_referer( 'cashflow_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }
        $settings = CashFlow_Plugin::get_settings();
        $fields = [ 'sync_courier_meta', 'bidirectional' ];
        foreach ( $fields as $f ) {
            $settings[ $f ] = ! empty( $_POST[ $f ] );
        }
        update_option( CASHFLOW_OPTION_KEY, $settings );
        wp_send_json_success( [ 'message' => 'Settings saved' ] );
    }

    public function ajax_get_sync_log() {
        check_ajax_referer( 'cashflow_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }
        global $wpdb;
        $logs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}cashflow_sync_log ORDER BY created_at DESC LIMIT 50"
        );
        wp_send_json_success( [ 'logs' => $logs ] );
    }

    // ── Disconnect (local-only; the backend owns the connection + WC keys) ──
    public function ajax_disconnect() {
        check_ajax_referer( 'cashflow_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'message' => 'Permission denied' ] );
        delete_option( 'cashflow_connection_secret' );
        delete_option( 'cashflow_order_prefix' );
        CashFlow_Plugin::save_settings( [ 'connected'=>false, 'store_id'=>'', 'org_id'=>'', 'connected_at'=>'', 'store_url'=>'' ] );
        CashFlow_Plugin::log( 'disconnect', 'store', 0, 'success', 'Local config cleared' );
        wp_send_json_success( [ 'message' => 'Disconnected locally. Manage the connection in the CashFlow app.' ] );
    }

    public function render_page() {
        require CASHFLOW_PLUGIN_DIR . 'admin/views/settings.php';
    }
}
