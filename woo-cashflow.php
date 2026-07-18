<?php
/**
 * Plugin Name: Woo Sync For Cashflow.pk
 * Plugin URI:  https://cashflow.pk
 * Description: Secure bi-directional sync — WooCommerce ↔ CashFlow.pk. One-click setup with store ownership verification.
 * Version:     5.3.2
 * Update URI:  https://github.com/Jajja-tech/woo-cashflow
 * Author:      CashFlow.pk
 * Author URI:  https://cashflow.pk
 * License:     GPL v2 or later
 * Text Domain: cashflow-sync
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ──────────────────────────────────────────────────────
define( 'CASHFLOW_VERSION',    '5.3.2' );
define( 'CASHFLOW_PLUGIN_FILE', __FILE__ );
define( 'CASHFLOW_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'CASHFLOW_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'CASHFLOW_API_BASE',    'https://cashflow-backend-706502592250.asia-south1.run.app' );
define( 'CASHFLOW_OPTION_KEY',  'cashflow_sync_v2' );

// ── HPOS (custom order tables) compatibility ───────────────────────
// Without this declaration WC marks the plugin incompatible on HPOS
// stores (the default since WC 8.2), and admins are blocked from
// enabling HPOS while the plugin is active.
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// Important Files to Load Early (define helpers used by admin views + bootstrap)
require_once plugin_dir_path( __FILE__ ) . 'includes/class-prefix.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-icons.php';

// ── Auto-update from GitHub (Plugin Update Checker v5.7) ───────────
// Stores pull new versions straight from the public GitHub repo's `main`
// branch: bump the Version header above, merge to main, and every site
// sees the update in WP-admin (auto-checked ~twice daily). No wp.org.
$cf_puc_loader = plugin_dir_path( __FILE__ ) . 'includes/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $cf_puc_loader ) ) {
    require_once $cf_puc_loader;
    if ( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
        try {
            $cf_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
                'https://github.com/Jajja-tech/woo-cashflow/',
                __FILE__,          // main plugin file — PUC reads its Version header on the branch
                'woo-cashflow'     // plugin slug (must match the installed folder name)
            );
            // Stable releases live on `main`; PUC compares its Version header to the installed one.
            $cf_update_checker->setBranch( 'main' );
        } catch ( Throwable $e ) {
            error_log( '[CashFlow Sync] Update checker init failed: ' . $e->getMessage() );
        }
    }
}

// ── Red warning banner when a plugin update is pending ─────────────
// Reads WordPress's own update-plugins transient (populated by the update
// checker above), so it appears exactly when WP knows a newer version
// exists and disappears the moment the store updates. Warns admins that
// some sync features may misbehave until they're on the latest version.
add_action( 'admin_notices', 'cf_pending_update_notice' );
function cf_pending_update_notice() {
    if ( ! current_user_can( 'update_plugins' ) ) {
        return;
    }
    $plugin_file = plugin_basename( CASHFLOW_PLUGIN_FILE );
    $updates     = get_site_transient( 'update_plugins' );
    if ( ! is_object( $updates ) || ! isset( $updates->response[ $plugin_file ]->new_version ) ) {
        return; // no update pending (isset is null-safe — no warnings on empty transient)
    }
    $new_version = $updates->response[ $plugin_file ]->new_version;
    $update_url  = wp_nonce_url(
        self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $plugin_file ) ),
        'upgrade-plugin_' . $plugin_file
    );
    ?>
    <div class="notice notice-error" style="border-left-color:#b32d2e;">
        <p style="font-size:14px; margin:0.7em 0;">
            <span class="dashicons dashicons-warning" style="color:#b32d2e; vertical-align:text-bottom;"></span>
            <strong style="color:#b32d2e;">CashFlow Sync update available (v<?php echo esc_html( $new_version ); ?>).</strong>
            Some sync features may stop working correctly until you update to the latest version.
            <a href="<?php echo esc_url( $update_url ); ?>" class="button button-primary" style="margin-left:8px;">Update now</a>
        </p>
    </div>
    <?php
}

// ── Activation / Deactivation ─────────────────────────────────────
register_activation_hook(   __FILE__, [ 'CashFlow_Plugin', 'activate'   ] );
register_deactivation_hook( __FILE__, [ 'CashFlow_Plugin', 'deactivate' ] );
add_action( 'plugins_loaded', [ 'CashFlow_Plugin', 'init' ] );

/**
 * Main plugin bootstrap
 */
class CashFlow_Plugin {

    private static $instance = null;

    public static function init() {
        try {
            if ( ! class_exists( 'WooCommerce' ) ) {
                add_action( 'admin_notices', [ __CLASS__, 'woo_missing_notice' ] );
                return;
            }
            if ( self::$instance === null ) {
                self::$instance = new self();
            }
            return self::$instance;
        } catch ( Throwable $e ) {
            error_log( '[CashFlow Sync] Init error: ' . $e->getMessage() );
        }
    }

    public function __construct() {
        try {
            // Load all files — explicit requires, no autoloader
            $files = [
                'admin/class-admin.php',
                'includes/class-security.php',
                'includes/class-prefix.php',
                'includes/class-statuses.php',
                'includes/class-webhooks.php',
                'includes/class-sync.php',
                'includes/class-meta.php',
                'includes/class-advance.php',
                'includes/class-rest.php',
            ];
            foreach ( $files as $file ) {
                $path = CASHFLOW_PLUGIN_DIR . $file;
                if ( file_exists( $path ) ) {
                    require_once $path;
                } else {
                    // Log missing file but don't crash
                    error_log( '[CashFlow Sync] Missing file: ' . $path );
                }
            }

            // Boot each module safely
            $modules = [
                'CashFlow_Statuses',
                'CashFlow_Admin',
                'CashFlow_Webhooks',
                'CashFlow_Sync',
                'CashFlow_Meta',
                'CashFlow_Advance',
                'CashFlow_REST',
            ];
            foreach ( $modules as $class ) {
                if ( class_exists( $class ) ) {
                    new $class();
                } else {
                    error_log( '[CashFlow Sync] Class not found: ' . $class );
                }
            }

        } catch ( Throwable $e ) {
            // Never crash the site — just log and disable gracefully
            error_log( '[CashFlow Sync] Fatal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            add_action( 'admin_notices', function() use ( $e ) {
                echo '<div class="error"><p><strong>CashFlow Sync</strong> encountered an error and has been disabled: ' . esc_html( $e->getMessage() ) . '. Please contact support.</p></div>';
            } );
        }
    }

    // ── Activation ─────────────────────────────────────────────────
    public static function activate() {
        global $wpdb;

        // Sync log table
        $table   = $wpdb->prefix . 'cashflow_sync_log';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type  VARCHAR(100) NOT NULL,
            object_type VARCHAR(50)  NOT NULL,
            object_id   BIGINT(20)   NOT NULL DEFAULT 0,
            status      VARCHAR(20)  NOT NULL DEFAULT 'success',
            message     TEXT,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_event   (event_type),
            KEY idx_object  (object_id),
            KEY idx_created (created_at)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        cf_on_activation();

        set_transient( 'cashflow_activated', true, 30 );
    }

    public static function deactivate() {
        $settings = self::get_settings();
        // Webhooks stay — admin must explicitly disconnect
        // Just clear the cron jobs
        wp_clear_scheduled_hook( 'cashflow_push_order' );
    }

    public static function woo_missing_notice() {
        echo '<div class="error"><p><strong>CashFlow Sync</strong> requires WooCommerce to be active.</p></div>';
    }

    // ── Settings ───────────────────────────────────────────────────
    public static function get_settings() {
        return wp_parse_args( get_option( CASHFLOW_OPTION_KEY, [] ), [
            'store_id'          => '',
            'org_id'            => '',
            'connected'         => false,
            'connected_at'      => '',
            'store_url'         => '',
            'sync_courier_meta' => true,
            'bidirectional'     => true,
        ] );
    }

    public static function save_settings( $data ) {
        $settings = self::get_settings();
        update_option( CASHFLOW_OPTION_KEY, array_merge( $settings, $data ) );
    }

    // ── CashFlow API request ────────────────────────────────────────
    public static function api_request( $endpoint, $method = 'GET', $body = null, $token = null ) {
        if ( ! $token ) {
            $settings = self::get_settings();
            $token    = $settings['cashflow_token'] ?? '';
        }

        $timestamp = time();
        $nonce     = wp_generate_password( 16, false );
        $site_secret = get_option( 'cashflow_site_secret', '' );

        // HMAC signature: method + endpoint + timestamp + nonce
        $sig_data  = $method . $endpoint . $timestamp . $nonce;
        $signature = hash_hmac( 'sha256', $sig_data, $site_secret );

        $args = [
            'method'  => $method,
            'headers' => [
                'Content-Type'        => 'application/json',
                'Authorization'       => 'Bearer ' . $token,
                'X-CashFlow-Site'     => get_site_url(),
                'X-CashFlow-Time'     => $timestamp,
                'X-CashFlow-Nonce'    => $nonce,
                'X-CashFlow-Sig'      => $signature,
                'X-Plugin-Version'    => CASHFLOW_VERSION,
            ],
            'timeout'    => 30,
            'sslverify'  => true,
        ];

        if ( $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( CASHFLOW_API_BASE . $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'error' => $response->get_error_message(), 'status' => 0, 'data' => null ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return [
            'ok'     => $code >= 200 && $code < 300,
            'status' => $code,
            'data'   => $data,
        ];
    }

    // ── Sync log ───────────────────────────────────────────────────
    public static function log( $event_type, $object_type, $object_id, $status = 'success', $message = '' ) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'cashflow_sync_log',
            compact( 'event_type', 'object_type', 'object_id', 'status', 'message' ),
            [ '%s', '%s', '%d', '%s', '%s' ]
        );
    }
}
