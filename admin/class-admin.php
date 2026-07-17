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
        add_action( 'wp_ajax_cashflow_reregister_webhooks', [ $this, 'ajax_reregister_webhooks' ] );
        add_action( 'wp_ajax_cashflow_save_settings',       [ $this, 'ajax_save_settings'       ] );
        add_action( 'wp_ajax_cashflow_get_sync_log',        [ $this, 'ajax_get_sync_log'        ] );
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

        $settings = CashFlow_Plugin::get_settings();
        if ( ! empty( $settings['connected'] ) && empty( get_option( 'cashflow_webhook_ids' ) ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo '<strong>CashFlow Sync:</strong> Webhooks are not registered. <a href="' . admin_url( 'admin.php?page=cashflow-sync' ) . '">Re-register webhooks →</a>';
            echo '</p></div>';
        }
    }

    public function ajax_reregister_webhooks() {
        check_ajax_referer( 'cashflow_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }
        $result = ( new CashFlow_Webhooks() )->reregister_webhooks();
        if ( $result ) {
            wp_send_json_success( [ 'message' => 'Webhooks registered: ' . $result['registered'] . '/' . $result['total'] ] );
        } else {
            wp_send_json_error( [ 'message' => 'Not connected — please connect first' ] );
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

    public function render_page() {
        require CASHFLOW_PLUGIN_DIR . 'admin/views/settings.php';
    }
}
