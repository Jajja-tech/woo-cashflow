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
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );   // core's value

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
    public static $on_calculate_totals = null;               // callable: run something mid-create
    public static $after_lock_read = null;                   // callable: runs once, right after a lock SELECT
    public static array $db_options = [];                    // the options TABLE the lock rows live in
    public static array $sql = [];                           // every statement $wpdb ran
    public static array $filters = [];       // hook => callbacks registered by add_filter
    public static array $actions = [];        // hook => [ [callback, priority, accepted_args] ] — add_action
    public static array $as_calls = [];       // every as_* call, in order
    public static array $as_scheduled = [];   // hook => [ 'timestamp', 'group', 'priority' ]
    public static array $posts = [];          // id => [ 'type', 'status', 'parent' ] — the wp_posts rows that matter
    public static array $terms = [];          // term_id => (object) [ term_id, name, slug, taxonomy, count ]
    public static ?string $terms_error = null; // set: get_terms answers WP_Error, as core does on a DB failure
    public static array $attachments = [];    // attachment id => url (as STORED, before any scheme upgrade)
    public static bool  $is_ssl = false;      // is_ssl(): a REQUEST fact, not a site setting
    public static bool  $is_admin = false;    // is_admin(): true inside admin-ajax, e.g. Action Scheduler's async runner
    public static int   $cache_flushes = 0;
    public static array $product_reads = [];  // every WC_Product getter read: [ prop, context ]
    public static $on_product_get = null;     // callable( string $prop, WC_Product ): runs inside every getter
    public static array $catalog_tables = [];  // table name => true (created by dbDelta)
    public static array $catalog_queue = [];   // the queue TABLE: id => row, values as MySQL returns them (strings / null)
    public static int   $catalog_queue_next = 1;
    public static ?string $db_error_on = null; // a catalogue statement whose SQL contains this fails like MySQL
    public static array $dbdelta = [];         // every SQL dbDelta was handed
    public static bool  $dbdelta_creates = true;
    public static ?Throwable $dbdelta_throws = null;

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
        self::$on_calculate_totals = null;
        self::$after_lock_read = null;
        self::$db_options = [];
        self::$sql = [];
        self::$actions = [];
        self::$as_calls = [];
        self::$as_scheduled = [];
        self::$posts = [];
        self::$terms = [];
        self::$terms_error = null;
        self::$attachments = [];
        self::$is_ssl = false;
        self::$is_admin = false;
        self::$cache_flushes = 0;
        self::$product_reads = [];
        self::$on_product_get = null;
        self::$catalog_tables = [];
        self::$catalog_queue = [];
        self::$catalog_queue_next = 1;
        self::$db_error_on = null;
        self::$dbdelta = [];
        self::$dbdelta_creates = true;
        self::$dbdelta_throws = null;
    }
}

// ── WordPress ────────────────────────────────────────────────────────────
function add_action( $hook, $cb = null, $prio = 10, $args = 1 ) { CF_TestState::$actions[ $hook ][] = [ $cb, $prio, $args ]; return true; }
function get_post_type( $post = null ) { return CF_TestState::$posts[ (int) $post ]['type'] ?? false; }
function wp_get_post_parent_id( $post = null ) { return (int) ( CF_TestState::$posts[ (int) $post ]['parent'] ?? 0 ); }
/**
 * Core's wp_get_attachment_url() upgrades an http URL to https when
 * is_ssl() && ! is_admin() — a REQUEST fact, not a site setting. Action
 * Scheduler runs jobs through BOTH WP-Cron (not admin) and admin-ajax
 * (admin), so the identical stored URL can come back different depending on
 * which runner is asking. Modelled faithfully so a caller that does not
 * force an independent scheme can be caught disagreeing with itself.
 */
function wp_get_attachment_url( $id = 0 ) {
    $url = CF_TestState::$attachments[ (int) $id ] ?? false;
    if ( false === $url ) { return false; }
    if ( 'https' !== substr( $url, 0, 5 ) && is_ssl() && ! is_admin() ) {
        $url = set_url_scheme( $url, 'https' );
    }
    return $url;
}
function wp_cache_flush_runtime() { CF_TestState::$cache_flushes++; return true; }
function delete_option( $k ) { unset( CF_TestState::$options[ $k ] ); return true; }

/**
 * get_terms, modelled on core for the arguments the catalogue passes. Core's
 * hide_empty defaults to TRUE (a term with count 0 is hidden), and an EMPTY
 * include means "no filter" — every term in the taxonomy. The second is refused
 * here outright, so code that forgets to guard it fails in the harness.
 */
function get_terms( $args = [] ) {
    $unmodelled = array_diff( array_keys( (array) $args ), [ 'taxonomy', 'include', 'hide_empty', 'orderby' ] );
    if ( $unmodelled ) { throw new RuntimeException( 'harness: get_terms argument not modelled: ' . implode( ', ', $unmodelled ) ); }
    if ( null !== CF_TestState::$terms_error ) { return new WP_Error( 'db_error', CF_TestState::$terms_error ); }
    $include = array_map( 'intval', (array) ( $args['include'] ?? [] ) );
    if ( ! $include ) { throw new RuntimeException( 'harness: get_terms without include returns every term in core — not modelled' ); }
    $tax        = (string) ( $args['taxonomy'] ?? '' );
    $hide_empty = $args['hide_empty'] ?? true;
    $out = [];
    foreach ( $include as $id ) {
        $t = CF_TestState::$terms[ $id ] ?? null;
        if ( ! $t || $t->taxonomy !== $tax ) { continue; }
        if ( $hide_empty && 0 === (int) $t->count ) { continue; }
        $out[] = $t;
    }
    if ( 'include' !== ( $args['orderby'] ?? 'name' ) ) {
        usort( $out, function ( $a, $b ) { return strcmp( $a->name, $b->name ); } );
    }
    return $out;
}

// ── Action Scheduler (ships inside WooCommerce) ─────────────────────────
// Recorded, including the priority: a LOWER number runs FIRST (default 10),
// which is what keeps the order tick ahead of the catalogue job.
function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = [], $group = '', $unique = false, $priority = 10 ) {
    CF_TestState::$as_calls[] = [ 'fn' => 'schedule', 'hook' => $hook, 'timestamp' => $timestamp, 'interval' => $interval,
        'group' => $group, 'unique' => $unique, 'priority' => $priority ];
    CF_TestState::$as_scheduled[ $hook ] = [ 'timestamp' => $timestamp, 'group' => $group, 'priority' => $priority ];
    return count( CF_TestState::$as_calls );
}
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
    $s = CF_TestState::$as_scheduled[ $hook ] ?? null;
    if ( ! $s || ( '' !== $group && $s['group'] !== $group ) ) { return false; }
    return $s['timestamp'];
}
function as_unschedule_all_actions( $hook, $args = [], $group = '' ) {
    CF_TestState::$as_calls[] = [ 'fn' => 'unschedule_all', 'hook' => $hook, 'group' => $group ];
    unset( CF_TestState::$as_scheduled[ $hook ] );
}

/**
 * dbDelta — records the SQL and creates the table it names. Modelled failures:
 * $dbdelta_creates = false (the CREATE did not take: permissions, a full disk)
 * and $dbdelta_throws.
 */
function dbDelta( $queries = '', $execute = true ) {
    CF_TestState::$dbdelta[] = $queries;
    if ( CF_TestState::$dbdelta_throws ) { throw CF_TestState::$dbdelta_throws; }
    if ( CF_TestState::$dbdelta_creates && preg_match( '/CREATE TABLE (\S+) \(/', (string) $queries, $m ) ) {
        CF_TestState::$catalog_tables[ $m[1] ] = true;
    }
    return [];
}

function add_filter( $hook, $cb = null, $prio = 10, $args = 1 ) { CF_TestState::$filters[ $hook ][] = $cb; return true; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $v ) { return $v; }
/** Core's home_url( $path = '', $scheme = null ). Only the no-argument form is modelled. */
function home_url( $path = '', $scheme = null ) {
    if ( '' !== $path || null !== $scheme ) {
        throw new RuntimeException( 'harness: home_url() with a path or scheme argument is not modelled' );
    }
    // Core (get_home_url): the stored option, switched to https whenever the
    // CURRENT request is SSL — a fact of the request, which is exactly why the
    // catalogue must not read its scheme from here.
    $url = (string) get_option( 'home', 'https://example.test' );
    return is_ssl() ? set_url_scheme( $url, 'https' ) : $url;
}
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function is_ssl() { return CF_TestState::$is_ssl; }
function is_admin() { return CF_TestState::$is_admin; }
/**
 * Core's set_url_scheme, modelled only for the two schemes this codebase
 * ever asks for ('http'/'https'). null (meaning "the current request's
 * scheme"), 'relative', 'admin' and friends are NOT modelled and throw, so a
 * caller relying on behaviour this stub does not reproduce fails loudly
 * rather than silently passing.
 */
function set_url_scheme( $url, $scheme = null ) {
    if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
        throw new RuntimeException( 'harness: set_url_scheme() with scheme ' . var_export( $scheme, true ) . ' is not modelled' );
    }
    $url = trim( (string) $url );
    if ( '//' === substr( $url, 0, 2 ) ) { $url = 'http:' . $url; }   // core: protocol-relative → http: first
    return preg_replace( '#^\w+://#', $scheme . '://', $url );
}
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
        public static function api_request( $endpoint, $method = 'GET', $body = null, $token = null, $timeout = 30 ) {
            $call = [ 'endpoint' => $endpoint, 'method' => $method, 'body' => $body, 'token' => $token, 'timeout' => $timeout ];
            CF_TestState::$api_calls[] = $call;
            if ( empty( CF_TestState::$api_responses[ $endpoint ] ) ) {
                return [ 'ok' => false, 'status' => 0, 'error' => "harness: no scripted response for $endpoint", 'data' => null ];
            }
            $r = array_shift( CF_TestState::$api_responses[ $endpoint ] );
            // A scripted response may be a closure. It runs AT the moment of the
            // request, so a test can make something happen while a send is in
            // flight — a save during a send is the case the catalogue queue exists for.
            return $r instanceof Closure ? $r( $call ) : $r;
        }
        public static function log( $event_type, $object_type, $object_id, $status = 'success', $message = '' ) {
            CF_TestState::$log[] = compact( 'event_type', 'object_type', 'object_id', 'status', 'message' );
        }
    }
}

/**
 * $wpdb — ONLY the four statements the create lock issues, with MySQL's
 * semantics for each on a table whose option_name is UNIQUE. Anything else
 * throws: a stub that answered unknown SQL with a plausible number is how a
 * harness certifies a query that would fail on a real store.
 */
class CF_Test_WPDB {
    public string $options = 'wp_options';
    public string $prefix  = 'wp_';
    public string $posts   = 'wp_posts';
    public string $last_error = '';
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
    public function prepare( $sql, ...$args ) {
        $args = ( count( $args ) === 1 && is_array( $args[0] ) ) ? $args[0] : $args;
        return [ 'sql' => $sql, 'args' => $args ];
    }
    private static function split( $q ): array {
        return is_array( $q ) ? [ $q['sql'], $q['args'] ] : [ (string) $q, [] ];
    }
    public function query( $q ) {
        $this->last_error = '';
        [ $sql, $a ] = self::split( $q );
        if ( $name = CF_Test_CatalogDB::name_of( $sql ) ) { return CF_Test_CatalogDB::run( 'query', $name, $a, $this ); }
        CF_TestState::$sql[] = $sql;
        $t = &CF_TestState::$db_options;
        if ( str_starts_with( $sql, "INSERT IGNORE INTO {$this->options} (option_name, option_value, autoload)" ) ) {
            if ( array_key_exists( $a[0], $t ) ) return 0;          // duplicate key: ignored, 0 rows
            $t[ $a[0] ] = $a[1]; return 1;
        }
        if ( str_starts_with( $sql, "UPDATE {$this->options} SET option_value = %s WHERE option_name = %s AND option_value = %s" ) ) {
            if ( ( $t[ $a[1] ] ?? null ) === $a[2] ) { $t[ $a[1] ] = $a[0]; return 1; }
            return 0;
        }
        if ( str_starts_with( $sql, "DELETE FROM {$this->options} WHERE option_name = %s AND option_value = %s" ) ) {
            if ( ( $t[ $a[0] ] ?? null ) === $a[1] ) { unset( $t[ $a[0] ] ); return 1; }
            return 0;
        }
        throw new RuntimeException( "harness: \$wpdb->query not modelled: $sql" );
    }
    public function get_var( $q ) {
        $this->last_error = '';
        [ $sql, $a ] = self::split( $q );
        if ( $name = CF_Test_CatalogDB::name_of( $sql ) ) { return CF_Test_CatalogDB::run( 'get_var', $name, $a, $this ); }
        CF_TestState::$sql[] = $sql;
        if ( $sql === "SELECT option_value FROM {$this->options} WHERE option_name = %s" ) {
            $v = CF_TestState::$db_options[ $a[0] ] ?? null;
            if ( $f = CF_TestState::$after_lock_read ) { CF_TestState::$after_lock_read = null; $f(); }
            return $v;
        }
        throw new RuntimeException( "harness: \$wpdb->get_var not modelled: $sql" );
    }
    public function get_col( $q ) {
        $this->last_error = '';
        [ $sql, $a ] = self::split( $q );
        if ( $name = CF_Test_CatalogDB::name_of( $sql ) ) { return CF_Test_CatalogDB::run( 'get_col', $name, $a, $this ); }
        throw new RuntimeException( "harness: \$wpdb->get_col not modelled: $sql" );
    }
    public function get_results( $q, $output = 'OBJECT' ) {
        $this->last_error = '';
        [ $sql, $a ] = self::split( $q );
        if ( ARRAY_A !== $output ) { throw new RuntimeException( 'harness: get_results is modelled for ARRAY_A only' ); }
        if ( $name = CF_Test_CatalogDB::name_of( $sql ) ) { return CF_Test_CatalogDB::run( 'get_results', $name, $a, $this ); }
        throw new RuntimeException( "harness: \$wpdb->get_results not modelled: $sql" );
    }
}
$GLOBALS['wpdb'] = new CF_Test_WPDB();
require_once __DIR__ . '/support/catalogDb.php';

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
        if ( CF_TestState::$on_calculate_totals ) { ( CF_TestState::$on_calculate_totals )(); }
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
    /**
     * What core's getters return in 'edit' context. The defaults are core's own
     * for a new product (WC_Product::$data), so a test that sets nothing reads
     * what a fresh product reads — never a convenient value.
     */
    private array $props;

    // The first three parameters are unchanged: the order tests construct
    // products as ( id ), ( id, parent ) and ( id, 0, 'trash' ).
    public function __construct( private int $id, private int $parent = 0, private string $status = 'publish', array $props = [] ) {
        $this->props = array_merge( [
            'name' => '', 'sku' => '', 'type' => 'simple',
            'regular_price' => '', 'sale_price' => '', 'price' => '',
            'date_on_sale_from' => null, 'date_on_sale_to' => null,
            'stock_quantity' => null, 'stock_status' => 'instock', 'manage_stock' => false,
            'weight' => '', 'category_ids' => [], 'tag_ids' => [],
            'image_id' => '', 'gallery_image_ids' => [], 'children' => [],
        ], $props );
    }
    public function get_id() { return $this->id; }
    public function get_parent_id() { return $this->parent; }
    public function get_status( $context = 'view' ) { CF_TestState::$product_reads[] = [ 'status', $context ]; return $this->status; }

    private function read( string $prop, $context ) {
        CF_TestState::$product_reads[] = [ $prop, $context ];
        if ( CF_TestState::$on_product_get ) { ( CF_TestState::$on_product_get )( $prop, $this ); }
        return $this->props[ $prop ];
    }
    public function get_name( $context = 'view' ) { return $this->read( 'name', $context ); }
    public function get_sku( $context = 'view' ) { return $this->read( 'sku', $context ); }
    public function get_regular_price( $context = 'view' ) { return $this->read( 'regular_price', $context ); }
    public function get_sale_price( $context = 'view' ) { return $this->read( 'sale_price', $context ); }
    public function get_price( $context = 'view' ) { return $this->read( 'price', $context ); }
    public function get_date_on_sale_from( $context = 'view' ) { return $this->read( 'date_on_sale_from', $context ); }
    public function get_date_on_sale_to( $context = 'view' ) { return $this->read( 'date_on_sale_to', $context ); }
    public function get_stock_quantity( $context = 'view' ) { return $this->read( 'stock_quantity', $context ); }
    public function get_stock_status( $context = 'view' ) { return $this->read( 'stock_status', $context ); }
    public function get_manage_stock( $context = 'view' ) { return $this->read( 'manage_stock', $context ); }
    public function get_weight( $context = 'view' ) { return $this->read( 'weight', $context ); }
    public function get_category_ids( $context = 'view' ) { return $this->read( 'category_ids', $context ); }
    public function get_tag_ids( $context = 'view' ) { return $this->read( 'tag_ids', $context ); }
    public function get_image_id( $context = 'view' ) { return $this->read( 'image_id', $context ); }
    public function get_gallery_image_ids( $context = 'view' ) { return $this->read( 'gallery_image_ids', $context ); }
    // Core's WC_Product_Variable::get_children( $visible_only = '' ) takes no
    // context (a string is ignored), so it is called with no argument.
    public function get_children() { return $this->read( 'children', null ); }
    public function get_type() { return $this->props['type']; }
    public function is_type( $type ) { return in_array( $this->props['type'], (array) $type, true ); }
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
        CF_TestState::$tx_snapshot = CF_TestState::$tx_snapshot ?? CF_TestState::$orders;
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
    // READ ISOLATION, as MySQL gives it: another run cannot see an order this
    // one has saved but not committed. Without this the stub would let a
    // racing run "find" an order that, on a real store, it could not.
    $visible = CF_TestState::$tx_snapshot ?? CF_TestState::$orders;
    foreach ( $visible as $order ) {
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
