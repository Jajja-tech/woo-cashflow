<?php
/**
 * Minimal WordPress / WooCommerce stand-ins, so the plugin's own logic can be
 * EXECUTED rather than only read.
 *
 * This is not a WordPress test suite and does not pretend to be one. It stubs
 * exactly the surface the code under test touches, and every stub RECORDS what
 * it was asked to do so a test can assert on it. Anything not stubbed will
 * fatal loudly rather than silently no-op — a stub that quietly returns null is
 * how a harness certifies code that would fail in production.
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

define( 'CASHFLOW_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'CASHFLOW_PLUGIN_URL', 'https://example.test/wp-content/plugins/woo-cashflow/' );
define( 'CASHFLOW_VERSION', '6.5.0' );
define( 'CASHFLOW_OPTION_KEY', 'cashflow_sync_v2' );
define( 'CASHFLOW_API_BASE_DEFAULT', 'https://api.cashflow.pk' );

// ── the recorder every stub writes to ────────────────────────────────────
class CF_TestState {
    public static array $orders = [];        // id => WC_Order
    public static array $created = [];       // the payloads handed to the applier
    public static array $options = [];
    public static int   $next_id = 1000;

    public static function reset(): void {
        self::$orders = [];
        self::$created = [];
        self::$options = [];
        self::$next_id = 1000;
    }
}

// ── WordPress ────────────────────────────────────────────────────────────
function add_action() {}
function add_filter() {}
function get_option( $k, $d = false ) { return CF_TestState::$options[ $k ] ?? $d; }
function update_option( $k, $v ) { CF_TestState::$options[ $k ] = $v; return true; }
function get_site_url() { return 'https://example.test'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = null ) { return $s; }

class WP_Error {
    public function __construct( private string $code = '', private string $message = '' ) {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

// ── WooCommerce ──────────────────────────────────────────────────────────
class WC_Order {
    private array $meta = [];
    public function __construct( private int $id, array $meta = [] ) { $this->meta = $meta; }
    public function get_id() { return $this->id; }
    public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function get_status() { return 'on-hold'; }
    public function save() { return $this->id; }
    public function all_meta() { return $this->meta; }
}

/**
 * The real wc_get_orders supports a meta_key/meta_value pair. Modelled exactly:
 * anything else throws, so a test can never pass because the stub was lenient.
 */
function wc_get_orders( $args = [] ) {
    if ( ! isset( $args['meta_key'], $args['meta_value'] ) ) {
        throw new RuntimeException( 'harness: wc_get_orders called without meta_key/meta_value — not modelled' );
    }
    $out = [];
    foreach ( CF_TestState::$orders as $order ) {
        if ( (string) $order->get_meta( $args['meta_key'] ) === (string) $args['meta_value'] ) {
            $out[] = $order;
            if ( ! empty( $args['limit'] ) && count( $out ) >= (int) $args['limit'] ) break;
        }
    }
    return $out;
}

// Present so get_applier() resolves; the applier itself is stubbed below.
class WC_REST_Orders_Controller {}

/**
 * Stands in for CashFlow_Order_Applier. `create()` RECORDS the payload — that
 * payload is the contract this harness exists to assert on.
 */
class CashFlow_Order_Applier {
    public function create( array $body ) {
        CF_TestState::$created[] = $body;
        $meta = [];
        foreach ( $body['meta_data'] ?? [] as $m ) { $meta[ $m['key'] ] = $m['value']; }
        $order = new WC_Order( CF_TestState::$next_id++, $meta );
        CF_TestState::$orders[ $order->get_id() ] = $order;
        return $order;
    }
    public function serialize( $order ) {
        return [ 'id' => $order->get_id(), 'number' => $order->get_meta( 'cashflow_order_number' ) ?: (string) $order->get_id() ];
    }
    public function apply( $request, $sync_key ) { return null; }
}
