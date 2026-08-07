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
define( 'CASHFLOW_VERSION', '6.4.1' );
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
    // 🔴 DEFAULTS TO WooCommerce's OWN DEFAULT, not to whatever was asked for.
    // A stub that starts at the requested status could never reproduce H1 —
    // the whole defect was that nothing applied the request's status, so the
    // order stayed at WooCommerce's default `pending`.
    public string $status = 'pending';
    public array $calls = [];
    public function __construct( private int $id, array $meta = [] ) { $this->meta = $meta; }
    public function get_id() { return $this->id; }
    public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function get_status() { return $this->status; }
    public function set_status( $s, $note = '', $manual = false ) { $this->status = (string) $s; $this->calls[] = 'set_status'; }
    public function set_created_via( $v ) { $this->calls[] = 'set_created_via'; }
    public function set_prices_include_tax( $v ) { $this->calls[] = 'set_prices_include_tax'; }
    public function calculate_totals( $tax = true ) { $this->calls[] = 'calculate_totals'; }
    public function payment_complete() { $this->calls[] = 'payment_complete'; }
    public function save() { $this->calls[] = 'save'; CF_TestState::$orders[ $this->id ] = $this; return $this->id; }
    public function all_meta() { return $this->meta; }
}

function wc_get_order( $id ) { return CF_TestState::$orders[ $id ] ?? false; }
class WC_REST_Exception extends Exception {
    public function __construct( private string $code_, string $msg, private int $status_ = 400 ) { parent::__construct( $msg, $status_ ); }
    public function getErrorCode() { return $this->code_; }
}
class WC_Data_Exception extends Exception {
    public function getErrorCode() { return 'wc_data_error'; }
    public function getErrorData() { return []; }
}
class WP_REST_Request implements ArrayAccess {
    private array $p = [];
    public function __construct( public string $method = 'GET', public string $route = '/' ) {}
    public function set_param( $k, $v ) { $this->p[ $k ] = $v; }
    // Modelled because class-sync-pull's apply path uses it and create's does
    // not — the harness had only ever exercised create, so this method was
    // missing and the apply path could not execute here AT ALL. In real WP the
    // body params are a separate bag that get_param() resolves for any method;
    // collapsing them into one map is faithful for every read the plugin makes.
    // REPLACES the bag, exactly like core — not a merge.
    public function set_body_params( $params ) { $this->p = (array) $params; }
    public function get_param( $k ) { return $this->p[ $k ] ?? null; }
    public function offsetExists( $k ): bool { return isset( $this->p[ $k ] ); }
    #[\ReturnTypeWillChange] public function offsetGet( $k ) { return $this->p[ $k ] ?? null; }
    public function offsetSet( $k, $v ): void { $this->p[ $k ] = $v; }
    public function offsetUnset( $k ): void { unset( $this->p[ $k ] ); }
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

/**
 * 🔴 THE REAL APPLIER NOW RUNS (sixth pass, 2026-08-07).
 *
 * This file used to declare a standalone REPLACEMENT class named
 * CashFlow_Order_Applier. get_applier() only requires the real file
 * `if ( ! class_exists(...) )`, so the real applier NEVER LOADED under test —
 * and the double was strictly more capable than production. That is how a
 * missing create() shipped, and a mutation proved it again afterwards:
 * replacing create()'s entire body with an immediate error left 20/20 green.
 *
 * So the controller below stubs only what WooCommerce itself provides, and the
 * REAL CashFlow_Order_Applier extends it. Its logic — status, coupon ordering,
 * set_paid, the post-save re-read — is now genuinely executed.
 *
 * What this still does NOT prove: that WooCommerce behaves as modelled here.
 * prepare_object_for_database deliberately SKIPS status (both V2 and V3 have
 * the comment "Status change should be done later so transitions have new
 * data"), which is exactly what this stub reproduces — the real integration
 * still needs a live store.
 */
class WC_REST_Orders_Controller {
    protected function prepare_object_for_database( $request, $creating = false ) {
        // 🔴 CORE LOADS THE EXISTING ORDER WHEN THE REQUEST CARRIES AN id —
        // `new WC_Order( $request['id'] )` — and only mints one when creating.
        // This stub ignored the id and always minted, because it had only ever
        // been exercised by the CREATE path. On the APPLY path that made the
        // applier mutate an object nobody could observe, so a test asserting on
        // the real order could neither pass nor fail honestly. Modelled now.
        $id    = (int) ( $request['id'] ?? 0 );
        $order = ( ! $creating && $id > 0 && isset( CF_TestState::$orders[ $id ] ) )
            ? CF_TestState::$orders[ $id ]
            : new WC_Order( $id > 0 ? $id : CF_TestState::$next_id++ );
        CF_TestState::$created[] = self::request_to_body( $request );
        foreach ( (array) $request->get_param( 'meta_data' ) as $m ) {
            if ( isset( $m['key'] ) ) { $order->update_meta_data( $m['key'], $m['value'] ?? '' ); }
        }
        // Status is DELIBERATELY not applied — see the class docblock.
        return $order;
    }
    protected function calculate_coupons( $request, $order ) { return true; }
    protected function prepare_object_for_response( $order, $request ) {
        return new class( $order ) {
            public function __construct( private $o ) {}
            public function get_data() {
                return [
                    'id' => $this->o->get_id(),
                    'number' => $this->o->get_meta( 'cashflow_order_number' ) ?: (string) $this->o->get_id(),
                    'status' => $this->o->get_status(),
                ];
            }
        };
    }
    private static function request_to_body( $request ) {
        $out = [];
        foreach ( [ 'status', 'line_items', 'meta_data', 'billing', 'shipping', 'shipping_lines',
                    'fee_lines', 'coupon_lines', 'set_paid', 'payment_method', 'payment_method_title',
                    'customer_note' ] as $k ) {
            $v = $request->get_param( $k );
            if ( null !== $v ) { $out[ $k ] = $v; }
        }
        return $out;
    }
}

require_once __DIR__ . '/../includes/class-order-applier.php';

// ── the shared assertion helper ───────────────────────────────────────────
// 🔴 IT LIVED IN createPath.test.php, so a SECOND test file calling ok() died
// with "undefined function" — and run.sh reported nothing useful. A test file
// that fatally errors must not be able to look like a file that passed.
$pass = 0; $fail = 0;

function ok( string $what, bool $cond, string $detail = '' ): void {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  \u{2714} $what\n"; }
    else { $fail++; echo "  \u{2716} $what" . ( $detail ? " \u{2014} $detail" : '' ) . "\n"; }
}

// Every test file ends with summary(). Printing it from ONE place means a file
// cannot forget to report, and cannot report zero while having failed.
function summary(): void {
    global $pass, $fail;
    echo "\n$pass passed, $fail failed\n";
    exit( $fail === 0 ? 0 : 1 );
}
