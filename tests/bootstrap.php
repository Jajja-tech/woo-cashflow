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
// Read from the REAL plugin file, never a copy: this said '6.4.1' while the
// plugin was on 6.5.0, so every poll a test inspected reported a version the
// code under test was not.
if ( ! preg_match( "/define\\(\\s*'CASHFLOW_VERSION',\\s*'([^']+)'/", (string) file_get_contents( dirname( __DIR__ ) . '/woo-cashflow.php' ), $cf_v ) ) {
    fwrite( STDERR, "harness: could not read CASHFLOW_VERSION from woo-cashflow.php\n" );
    exit( 1 );
}
define( 'CASHFLOW_VERSION', $cf_v[1] );
define( 'CASHFLOW_OPTION_KEY', 'cashflow_sync_v2' );
define( 'CASHFLOW_API_BASE_DEFAULT', 'https://api.cashflow.pk' );

// ── the recorder every stub writes to ────────────────────────────────────
class CF_TestState {
    public static array $orders = [];        // id => WC_Order
    public static array $created = [];       // the payloads handed to the applier
    public static array $options = [];
    public static int   $next_id = 1000;
    public static array $products = [];      // id => WC_Product
    public static array $tx = [];            // every wc_transaction_query() call, in order
    public static ?array $tx_snapshot = null;
    public static array $api_calls = [];     // every CashFlow_Plugin::api_request(), in order
    public static array $api_responses = []; // endpoint => queue of scripted responses
    public static array $log = [];           // every CashFlow_Plugin::log()
    public static ?Throwable $throw_on_calculate_totals = null;
    public static ?Throwable $throw_on_get_orders = null;   // a DB error in a lookup
    public static array $filters = [];       // hook => callbacks registered by add_filter

    public static function reset(): void {
        self::$orders = [];
        self::$created = [];
        self::$options = [];
        self::$next_id = 1000;
        self::$products = [];
        self::$tx = [];
        self::$tx_snapshot = null;
        self::$api_calls = [];
        self::$api_responses = [];
        self::$log = [];
        self::$throw_on_calculate_totals = null;
        self::$throw_on_get_orders = null;
    }
}

// ── WordPress ────────────────────────────────────────────────────────────
function add_action() {}
function add_filter( $hook, $cb = null, $prio = 10, $args = 1 ) { CF_TestState::$filters[ $hook ][] = $cb; return true; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $v ) { return $v; }
function home_url() { return 'https://example.test'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_option( $k, $d = false ) { return CF_TestState::$options[ $k ] ?? $d; }
function update_option( $k, $v ) { CF_TestState::$options[ $k ] = $v; return true; }
function get_site_url() { return 'https://example.test'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = null ) { return $s; }

class WP_Error {
    public function __construct( private string $code = '', private string $message = '', private $data = null ) {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

/**
 * CashFlow_Plugin lives in woo-cashflow.php, which boots the whole plugin and
 * cannot be required here. Its two static helpers are the plugin's only way
 * out to the network and the log, so they are RECORDED, and a response the
 * test did not script is a loud failure — never a silent 200.
 */
if ( ! class_exists( 'CashFlow_Plugin' ) ) {
    class CashFlow_Plugin {
        public static function api_request( $endpoint, $method = 'GET', $body = null, $token = null ) {
            CF_TestState::$api_calls[] = [ 'endpoint' => $endpoint, 'method' => $method, 'body' => $body, 'token' => $token ];
            if ( empty( CF_TestState::$api_responses[ $endpoint ] ) ) {
                return [ 'ok' => false, 'status' => 0, 'error' => "harness: no scripted response for $endpoint", 'data' => null ];
            }
            return array_shift( CF_TestState::$api_responses[ $endpoint ] );
        }
        public static function log( $event_type, $object_type, $object_id, $status = 'success', $message = '' ) {
            CF_TestState::$log[] = compact( 'event_type', 'object_type', 'object_id', 'status', 'message' );
        }
    }
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
    public string $created_via = '';
    // What the order carried AT EACH save — so a test can ask "was the key on
    // the order the moment anything persisted", not just "is it there now".
    public array $saves = [];
    // An id of 0 is a NEW order, exactly as in core: it gets its id on save.
    public function __construct( private int $id = 0, array $meta = [] ) { $this->meta = $meta; }
    public function get_id() { return $this->id; }
    public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; $this->calls[] = 'meta:' . $key; }
    public function get_status() { return $this->status; }
    // 🔴 MODELLED ON CORE (abstract-wc-order.php set_status): a status that is
    // not a registered order status SILENTLY BECOMES `pending`. A stub that
    // accepted any string could never catch a create asking for a status the
    // store does not know.
    public function set_status( $s, $note = '', $manual = false ) {
        $s = preg_replace( '/^wc-/', '', (string) $s );
        if ( ! array_key_exists( 'wc-' . $s, wc_get_order_statuses() ) && ! in_array( $s, [ 'trash', 'auto-draft' ], true ) ) {
            $s = 'pending';
        }
        $this->status = $s; $this->calls[] = 'set_status';
    }
    public function set_created_via( $v ) { $this->created_via = (string) $v; $this->calls[] = 'set_created_via'; }
    public function get_created_via() { return $this->created_via; }
    public function set_prices_include_tax( $v ) { $this->calls[] = 'set_prices_include_tax'; }
    public function calculate_totals( $tax = true ) {
        $this->calls[] = 'calculate_totals';
        if ( CF_TestState::$throw_on_calculate_totals ) { throw CF_TestState::$throw_on_calculate_totals; }
        // Core's calculate_totals ends in $this->save() (abstract-wc-order.php).
        $this->save();
    }
    public function payment_complete() { $this->calls[] = 'payment_complete'; }
    public function save() {
        if ( 0 === $this->id ) { $this->id = CF_TestState::$next_id++; }
        $this->calls[] = 'save';
        $this->saves[] = [ 'meta' => $this->meta, 'status' => $this->status ];
        CF_TestState::$orders[ $this->id ] = $this;
        return $this->id;
    }
    public function all_meta() { return $this->meta; }
}

function wc_get_order( $id ) { return CF_TestState::$orders[ $id ] ?? false; }

/** The registered statuses: core's list plus CashFlow's own (class-statuses.php). */
function wc_get_order_statuses() {
    $out = [];
    foreach ( [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed',
                'booked', 'shipped', 'returned' ] as $s ) { $out[ 'wc-' . $s ] = ucfirst( $s ); }
    return $out;
}

// 🔴 SAME HIERARCHY AS CORE: `class WC_REST_Exception extends WC_Data_Exception {}`
// (includes/class-wc-rest-exception.php). These were unrelated classes here, so
// a catch ordering that is wrong in production could not be seen by any test.
// Constructor signature is core's too: ( $code, $message, $http_status, $data ).
class WC_Data_Exception extends Exception {
    public function __construct( protected $error_code = 'wc_data_error', $message = '', $http_status_code = 400, protected $error_data = [] ) {
        parent::__construct( (string) $message, (int) $http_status_code );
    }
    public function getErrorCode() { return $this->error_code; }
    public function getErrorData() { return $this->error_data; }
}
class WC_REST_Exception extends WC_Data_Exception {}

class WC_Product {
    public function __construct( private int $id, private int $parent = 0, private string $status = 'publish' ) {}
    public function get_id() { return $this->id; }
    public function get_parent_id() { return $this->parent; }
    public function get_status() { return $this->status; }
}
/** Like core: false for an id that is not a product. Never invents one. */
function wc_get_product( $id ) { return CF_TestState::$products[ (int) $id ] ?? false; }

/**
 * wc_transaction_query — modelled as a real transaction: 'start' snapshots the
 * order table, 'rollback' restores it. So "a rollback leaves no order" is
 * something a test can observe rather than a call it can count.
 */
function wc_transaction_query( $type = 'start', $force = false ) {
    CF_TestState::$tx[] = $type;
    if ( 'start' === $type ) {
        CF_TestState::$tx_snapshot = CF_TestState::$orders;
    } elseif ( 'rollback' === $type ) {
        CF_TestState::$orders = CF_TestState::$tx_snapshot ?? CF_TestState::$orders;
        CF_TestState::$tx_snapshot = null;
    } else {
        CF_TestState::$tx_snapshot = null;
    }
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
    if ( CF_TestState::$throw_on_get_orders ) { throw CF_TestState::$throw_on_get_orders; }
    // Status, as core resolves it (OrdersTableQuery::process_status): absent or
    // 'any' means every registered status EXCEPT the exclude_from_search ones —
    // which includes TRASH. An explicit list is honoured as given.
    $status = $args['status'] ?? 'any';
    $status = is_array( $status ) ? $status : [ $status ];
    if ( in_array( 'any', $status, true ) ) {
        $status = array_keys( wc_get_order_statuses() );
    }
    $status = array_map( fn( $s ) => preg_replace( '/^wc-/', '', (string) $s ), $status );
    $out = [];
    foreach ( CF_TestState::$orders as $order ) {
        if ( (string) $order->get_meta( $args['meta_key'] ) !== (string) $args['meta_value'] ) continue;
        if ( ! in_array( $order->get_status(), $status, true ) ) continue;
        $out[] = ( ( $args['return'] ?? '' ) === 'ids' ) ? $order->get_id() : $order;
        if ( ! empty( $args['limit'] ) && count( $out ) >= (int) $args['limit'] ) break;
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
        if ( $creating ) {
            // Core: `new WC_Order( 0 )` — no id until the first save.
            $order = new WC_Order( 0 );
            // Core's get_product_id (V2 controller) THROWS for a line with no
            // product reference at all — but for a product id that does not
            // exist it does NOT: wc_get_product() returns false, the
            // `if ( $product && … )` is skipped, and the line is created with
            // no product. Modelled exactly both ways, so only the applier's own
            // check can make a missing product a refusal.
            foreach ( (array) $request->get_param( 'line_items' ) as $li ) {
                if ( empty( $li['sku'] ) && empty( $li['product_id'] ) && empty( $li['variation_id'] ) ) {
                    throw new WC_REST_Exception( 'woocommerce_rest_required_product_reference', 'Product ID or SKU is required.', 400 );
                }
            }
            // Core's WC_Abstract_Order::set_currency throws WC_Data_Exception
            // 'order_invalid_currency' for a code not in get_woocommerce_currencies().
            $cur = $request->get_param( 'currency' );
            if ( $cur && ! in_array( $cur, [ 'PKR', 'USD', 'GBP', 'EUR', 'AED' ], true ) ) {
                throw new WC_Data_Exception( 'order_invalid_currency', 'Invalid currency code', 400 );
            }
        } else {
            $order = ( $id > 0 && isset( CF_TestState::$orders[ $id ] ) )
                ? CF_TestState::$orders[ $id ]
                : new WC_Order( $id > 0 ? $id : CF_TestState::$next_id++ );
        }
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
                    'customer_note', 'currency', 'customer_id' ] as $k ) {
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
