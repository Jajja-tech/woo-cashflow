<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Catalog — the shop sends its own catalogue to CashFlow.
 *
 * Design: cashflow-backend docs/superpowers/specs/2026-09-25-shop-sends-its-catalogue.md
 * (its v3.1 section overrides v3). Why: CashFlow's `products` table was a copy
 * nothing kept current (25 Sep 2026: 60 products missing, 74 wrong prices), and
 * the only refresh was CashFlow calling this website — the path hosts block.
 * Staff price new orders from that copy, so a stale price is a wrong charge.
 *
 * How: product hooks write rows into this plugin's own queue table; this class's
 * own Action Scheduler job sends them within a minute, each product with an MD5
 * fingerprint of exactly the payload sent. Once an hour it sends the list of
 * [id, fingerprint] so CashFlow can ask for what it lacks and trash what the
 * shop no longer has. Only this plugin ever computes a fingerprint.
 *
 * What it must never do: delay or break the order poll (CashFlow_Sync_Pull). It
 * has its own job, its own group and a LOWER priority; every request has a
 * 10-second timeout; no request starts with less than 12 seconds of budget left.
 */
class CashFlow_Catalog {

    const DB_VERSION        = '1';
    const DB_VERSION_OPTION = 'cashflow_catalog_db_version';
    const TABLE             = 'cashflow_catalog_queue';

    // ── Its own job [N1, NB9] ───────────────────────────────────────
    const TICK_HOOK   = 'cashflow_catalog_tick';
    const AS_GROUP    = 'cashflow-catalog';
    // Action Scheduler runs a LOWER number first; the order tick uses the default
    // 10. So in any runner batch the order poll has run before this job starts,
    // and a fatal here cannot stop the order poll of that batch.
    const AS_PRIORITY = 20;
    const INTERVAL    = 60;

    const REQUEST_TIMEOUT   = 10;   // every catalogue request
    const BUDGET_SECONDS    = 25;   // one run
    const MIN_LEFT_TO_START = 12;   // no request starts with less left (> the timeout, so it always ends in budget)

    const EP_PRODUCTS    = '/plugin/catalog/products';
    const MAX_PRODUCTS   = 25;      // rows per claim, so products AND removals per body stay within the server's 25 / 100
    const MAX_BODY_BYTES = 81920;   // 80 KB, measured on the encoded body; the server's JSON parser takes 100 kB
    const LIST_OPTION    = 'cashflow_catalog_list';

    /** Every statement sql() can build. The test harness matches on these. */
    const SQL_NAMES = [
        'show_table', 'insert', 'insert_set', 'claim_real', 'claim_any', 'select_claimed', 'done',
        'release_failed', 'release_untried', 'park', 'clear_parked', 'count_pending', 'count_parked',
        'parked_ids', 'enumerate',
    ];

    /** The set [B7]: parents only; these statuses; everything else is outside it. */
    const SET_STATUSES = [ 'publish', 'future', 'draft', 'pending', 'private' ];
    const REASONS      = [ 'save', 'removed', 'asked', 'resend' ];

    const MAX_ATTEMPTS  = 5;     // a row parks on its 5th failed try [NB7]
    const LEASE_SECONDS = 300;   // a claim older than this belongs to a run that died (a PHP fatal) and is taken back
    const RETRY_SECONDS = 60;    // a failed row waits for the next run (wire: "retry next run")

    const STATS_OPTION = 'cashflow_catalog_stats';

    /** Hooks that pass a post id. [B5] A category or tag rename is deliberately NOT hooked [review-2]. */
    const HOOKS_POST_ID = [
        'save_post_product', 'woocommerce_new_product', 'woocommerce_update_product',
        'woocommerce_update_product_variation', 'trashed_post', 'untrashed_post',
    ];
    /** Hooks that pass a product object. */
    const HOOKS_PRODUCT = [ 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ];

    // ── What is sent: caps equal to the server's cuts [NB7] ─────────
    // The backend's catalogPluginContract.test.js pins these to its LIMITS, so
    // the server never cuts a field this plugin already fingerprinted.
    const CAP_NAME         = 500;
    const CAP_SKU          = 100;
    const CAP_TYPE         = 40;
    const CAP_STATUS       = 20;
    const CAP_STOCK_STATUS = 40;
    const CAP_TERM_NAME    = 200;
    const CAP_TERM_SLUG    = 200;
    const CAP_TERMS        = 100;
    const CAP_VARIATIONS   = 1000;
    const CAP_IMAGE_URL    = 2048;
    const CAP_NUMBER       = 40;     // price and weight strings; no real number is longer
    /** One product's share of an 80 KB body, leaving room for the envelope. */
    const MAX_ONE_BYTES    = 65536;
    /**
     * The encoding used for the fingerprint AND the body. Changing it changes
     * every fingerprint and makes the next list ask for the whole catalogue.
     */
    const JSON_FLAGS = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /** Injectable clock: a callable returning seconds as a float. Null means the real clock. */
    public static $clock = null;

    /** When this run must stop starting requests by (seconds, from now()). */
    private $deadline = 0.0;

    public function __construct() {
        // Constructed inside plugins_loaded (CashFlow_Plugin::init). A plugin
        // UPDATE never fires the activation hook, so this is where the table is
        // created: on the first request of each site that runs this version. [NB1]
        self::maybe_upgrade();
        self::register_hooks();
        // Action Scheduler's as_* functions are registered by init.
        add_action( 'init', [ $this, 'maybe_schedule' ] );
        add_action( self::TICK_HOOK, [ $this, 'tick' ] );
    }

    public static function now() {
        return null !== self::$clock ? (float) call_user_func( self::$clock ) : microtime( true );
    }

    /** This site's queue table. $wpdb->prefix is per site on multisite, so it is read at call time. */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /** The one place this class's SQL is written. */
    public static function sql( $name ) {
        global $wpdb;
        // A claim is ONE statement, so two overlapping runs can never take the
        // same row. MySQL evaluates the SET list LEFT TO RIGHT and a later
        // assignment sees an earlier one's new value, so `attempts` comes FIRST
        // and reads the OLD token: a row taken back from a run that died (its
        // token still set, its lease expired) counts that run as a try; a fresh
        // row does not. Moving `token = %s` in front of it would count every claim.
        $claim = 'UPDATE {q} SET attempts = attempts + IF(token IS NULL, 0, 1), token = %s, claimed_at = %s, pending_key = NULL'
               . ' WHERE parked_at IS NULL AND (token IS NULL OR claimed_at < %s) AND (retry_at IS NULL OR retry_at <= %s)';
        $templates = [
            'show_table'      => 'SHOW TABLES LIKE %s',
            'insert'          => 'INSERT IGNORE INTO {q} (product_id, reason, attempts, queued_at, pending_key) VALUES (%d, %s, 0, %s, %s)',
            'insert_set'      => "INSERT IGNORE INTO {q} (product_id, reason, attempts, queued_at, pending_key) SELECT ID, 'resend', 0, %s, CONCAT(ID, ':resend') FROM {p} WHERE post_type = 'product' AND post_status IN ({set})",
            'claim_real'      => $claim . " AND reason IN ('save', 'removed') ORDER BY id ASC LIMIT %d",
            'claim_any'       => $claim . " ORDER BY (reason IN ('asked', 'resend')) ASC, id ASC LIMIT %d",
            'select_claimed'  => 'SELECT id, product_id, reason, attempts FROM {q} WHERE token = %s ORDER BY id ASC',
            'done'            => 'DELETE FROM {q} WHERE id = %d AND token = %s',
            'release_failed'  => "UPDATE IGNORE {q} SET attempts = attempts + 1, token = NULL, claimed_at = NULL, retry_at = %s, pending_key = CONCAT(product_id, ':', reason) WHERE id = %d AND token = %s",
            'release_untried' => "UPDATE IGNORE {q} SET token = NULL, claimed_at = NULL, pending_key = CONCAT(product_id, ':', reason) WHERE id = %d AND token = %s",
            'park'            => 'UPDATE {q} SET attempts = %d, token = NULL, claimed_at = NULL, pending_key = NULL, parked_at = %s WHERE id = %d AND token = %s',
            'clear_parked'    => 'DELETE FROM {q} WHERE product_id = %d AND parked_at IS NOT NULL',
            'count_pending'   => 'SELECT COUNT(*) FROM {q} WHERE parked_at IS NULL',
            'count_parked'    => 'SELECT COUNT(*) FROM {q} WHERE parked_at IS NOT NULL',
            'parked_ids'      => 'SELECT product_id FROM {q} WHERE parked_at IS NOT NULL ORDER BY id ASC LIMIT 20',
            // The set, straight from wp_posts [NB3]. Never WP_Query: another
            // plugin's pre_get_posts / posts_where filters can shorten a WP_Query,
            // and a shortened list would TRASH the products it left out.
            'enumerate'       => "SELECT ID FROM {p} WHERE post_type = 'product' AND post_status IN ({set}) AND ID > %d ORDER BY ID ASC LIMIT %d",
        ];
        if ( ! isset( $templates[ $name ] ) ) {
            throw new InvalidArgumentException( 'Unknown catalogue statement: ' . $name );
        }
        return strtr( $templates[ $name ], [
            '{q}'   => self::table(),
            '{p}'   => $wpdb->posts,
            '{set}' => "'" . implode( "', '", self::SET_STATUSES ) . "'",
        ] );
    }

    // ── The queue table ─────────────────────────────────────────────

    public static function create_table_sql() {
        global $wpdb;
        $t = self::table();
        $c = $wpdb->get_charset_collate();
        // dbDelta's rules: one column per line, two spaces after PRIMARY KEY, KEY
        // not INDEX. pending_key is "<product_id>:<reason>" while a row WAITS and
        // NULL once it is claimed or parked. MySQL lets any number of NULLs share a
        // UNIQUE index, so INSERT IGNORE on it keeps exactly one waiting row per
        // product and reason — even under two concurrent saves — while a save made
        // during a send still inserts a new row (the in-flight one has NULL).
        return "CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL,
  reason varchar(10) NOT NULL,
  token varchar(40) DEFAULT NULL,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  queued_at datetime NOT NULL,
  claimed_at datetime DEFAULT NULL,
  retry_at datetime DEFAULT NULL,
  parked_at datetime DEFAULT NULL,
  pending_key varchar(40) DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY pending_key (pending_key),
  KEY token (token),
  KEY product_id (product_id)
) $c;";
    }

    /** Create or alter the table. The version option moves only once the table is really there. */
    public static function install() {
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta( self::create_table_sql() );
        if ( ! self::table_exists() ) {
            return false;
        }
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
        return true;
    }

    public static function table_exists() {
        global $wpdb;
        $t = self::table();
        return $t === $wpdb->get_var( $wpdb->prepare( self::sql( 'show_table' ), [ $t ] ) );
    }

    /** Cheap on every request: one option read. Never throws into the site. */
    public static function maybe_upgrade() {
        try {
            if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
                return;
            }
            self::install();
        } catch ( Throwable $e ) {
            error_log( '[CashFlow Sync] Catalogue table upgrade failed: ' . $e->getMessage() );
        }
    }

    // ── The queue ───────────────────────────────────────────────────

    public static function db_time( $offset = 0 ) {
        return gmdate( 'Y-m-d H:i:s', (int) floor( self::now() ) + (int) $offset );
    }

    /** One waiting row per product and reason: a duplicate is absorbed by the UNIQUE pending_key. */
    public static function enqueue( $product_id, $reason ) {
        global $wpdb;
        $product_id = (int) $product_id;
        if ( $product_id <= 0 || ! in_array( $reason, self::REASONS, true ) ) {
            return false;
        }
        $n = $wpdb->query( $wpdb->prepare( self::sql( 'insert' ),
            [ $product_id, $reason, self::db_time(), $product_id . ':' . $reason ] ) );
        if ( false === $n ) {
            self::note_error( 'The catalogue queue could not be written: ' . $wpdb->last_error );
        }
        return false !== $n;
    }

    /**
     * Take up to $limit due rows under $token. [] when nothing is due; null when
     * the database failed — a failure must never read as "the queue is empty".
     * A row can be claimed by the UPDATE and still be unreadable (the read-back
     * fails, or answers fewer rows than were just claimed): that is a database
     * failure too, never an empty result standing in for what the update did.
     */
    public static function claim( $token, $limit, $real_only ) {
        global $wpdb;
        $n = $wpdb->query( $wpdb->prepare( self::sql( $real_only ? 'claim_real' : 'claim_any' ), [
            $token, self::db_time(), self::db_time( -self::LEASE_SECONDS ), self::db_time(), (int) $limit,
        ] ) );
        if ( false === $n ) {
            self::note_error( 'The catalogue queue could not be read: ' . $wpdb->last_error );
            return null;
        }
        if ( 0 === (int) $n ) {
            return [];
        }
        $rows = $wpdb->get_results( $wpdb->prepare( self::sql( 'select_claimed' ), [ $token ] ), ARRAY_A );
        if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) || ! $rows ) {
            self::note_error( 'The catalogue queue could not be read: ' . $wpdb->last_error );
            return null;
        }
        return $rows;
    }

    /** Sent: delete exactly this row, and only with its own token. */
    public static function done_row( array $row, $token ) {
        global $wpdb;
        $n = $wpdb->query( $wpdb->prepare( self::sql( 'done' ), [ (int) $row['id'], $token ] ) );
        if ( false === $n ) {
            self::note_error( 'The catalogue queue could not be updated: ' . $wpdb->last_error );
        }
    }

    /** Not tried this run (the budget ran out, the server said stop, it did not fit): back, no try counted. */
    public static function release_row( array $row, $token ) {
        global $wpdb;
        $n = $wpdb->query( $wpdb->prepare( self::sql( 'release_untried' ), [ (int) $row['id'], $token ] ) );
        if ( false === $n ) {
            // Recorded, deliberately left otherwise alone: the row's token and
            // claimed_at are UNCHANGED by a failed write, so it is neither lost
            // nor double-freed — it becomes claimable again once its lease
            // expires, the same as a run that died outright.
            self::note_error( 'The catalogue queue could not be updated: ' . $wpdb->last_error );
            return;
        }
        if ( 0 === $n ) {
            self::done_row( $row, $token );   // a newer waiting row for this product carries the change
        }
    }

    /**
     * Tried and failed: once more on the next run, or parked on the
     * MAX_ATTEMPTS-th failure. 'error' — a name the brief has none for — is a
     * database failure on either write: never reported as 'released' (the row
     * was NOT rearmed) or 'parked' (park_row's own write may not have landed).
     */
    public static function fail_row( array $row, $token ) {
        global $wpdb;
        $attempts = (int) $row['attempts'] + 1;
        if ( $attempts >= self::MAX_ATTEMPTS ) {
            $parked = self::park_row( $row, $token, $attempts );
            if ( null === $parked ) { return 'superseded'; }   // another run holds it now
            return $parked ? 'parked' : 'error';
        }
        $n = $wpdb->query( $wpdb->prepare( self::sql( 'release_failed' ),
            [ self::db_time( self::RETRY_SECONDS ), (int) $row['id'], $token ] ) );
        if ( false === $n ) {
            self::note_error( 'The catalogue queue could not be updated: ' . $wpdb->last_error );
            return 'error';
        }
        if ( 0 === $n ) {
            // UPDATE IGNORE skipped it: a newer save of the same product took the
            // pending_key while this row was out. That row carries the change (the
            // payload is rebuilt from the live product at send), so this one goes.
            self::done_row( $row, $token );
            return 'superseded';
        }
        return 'released';
    }

    /**
     * true when the row was parked; false on a database failure (recorded);
     * null when no row matched — the lease ran out and another run took the
     * row back, so it is not this run's to park.
     */
    public static function park_row( array $row, $token, $attempts ) {
        global $wpdb;
        $n = $wpdb->query( $wpdb->prepare( self::sql( 'park' ), [ (int) $attempts, self::db_time(), (int) $row['id'], $token ] ) );
        if ( false === $n ) {
            self::note_error( 'The catalogue queue could not be updated: ' . $wpdb->last_error );
            return false;
        }
        return $n > 0 ? true : null;
    }

    /** A product sent successfully is no longer a parked problem. */
    public static function clear_parked( $product_id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( self::sql( 'clear_parked' ), [ (int) $product_id ] ) );
    }

    /** "Resend catalogue": every product in the set, one statement, de-duplicated like any insert. */
    public static function queue_whole_set_for_resend() {
        global $wpdb;
        return false !== $wpdb->query( $wpdb->prepare( self::sql( 'insert_set' ), [ self::db_time() ] ) );
    }

    /** Ids of the set above $after_id, ascending. null on a database error — never an empty page. */
    public static function enumerate( $after_id, $limit ) {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare( self::sql( 'enumerate' ), [ (int) $after_id, (int) $limit ] ) );
        if ( '' !== (string) $wpdb->last_error || ! is_array( $ids ) ) {
            return null;
        }
        return array_map( 'intval', $ids );
    }

    public static function count_pending() {
        global $wpdb;
        return (int) $wpdb->get_var( self::sql( 'count_pending' ) );
    }

    public static function count_parked() {
        global $wpdb;
        return (int) $wpdb->get_var( self::sql( 'count_parked' ) );
    }

    public static function parked_ids() {
        global $wpdb;
        return array_map( 'intval', (array) $wpdb->get_col( self::sql( 'parked_ids' ) ) );
    }

    // ── Hooks: one cheap INSERT IGNORE, never anything that can break a save ──

    public static function register_hooks() {
        foreach ( self::HOOKS_POST_ID as $hook ) {
            add_action( $hook, [ __CLASS__, 'on_post_id' ], 10, 1 );
        }
        foreach ( self::HOOKS_PRODUCT as $hook ) {
            add_action( $hook, [ __CLASS__, 'on_product_object' ], 10, 1 );
        }
        // Fires while the post still exists, so its type and parent can be read. [B5]
        add_action( 'before_delete_post', [ __CLASS__, 'on_before_delete' ], 10, 1 );
    }

    public static function on_post_id( $post_id ) {
        self::guard( function () use ( $post_id ) { self::mark( $post_id, 'save' ); } );
    }

    public static function on_product_object( $product ) {
        self::guard( function () use ( $product ) {
            if ( is_object( $product ) && is_callable( [ $product, 'get_id' ] ) ) {
                self::mark( $product->get_id(), 'save' );
            }
        } );
    }

    public static function on_before_delete( $post_id ) {
        self::guard( function () use ( $post_id ) { self::mark( $post_id, 'removed' ); } );
    }

    /**
     * A product queues itself. A variation queues its PARENT as a save — a
     * variation change (or delete) changes the parent's payload, never removes
     * the parent. Anything else (orders, pages, revisions, unknown ids) is
     * ignored: the set is parents only [B7].
     */
    private static function mark( $post_id, $reason ) {
        $id = is_numeric( $post_id ) ? (int) $post_id : 0;
        if ( $id <= 0 ) {
            return;
        }
        $type = get_post_type( $id );
        if ( 'product' === $type ) {
            $target = $id;
        } elseif ( 'product_variation' === $type ) {
            $target = (int) wp_get_post_parent_id( $id );
            $reason = 'save';
            if ( $target <= 0 ) {
                return;
            }
        } else {
            return;
        }
        if ( ! self::enqueue( $target, $reason ) ) {
            self::note_error( 'Product ' . $target . ' could not be queued for CashFlow; the hourly list will catch it' );
        }
    }

    /** A hook runs inside an admin save. Nothing here may ever break it. */
    private static function guard( callable $fn ) {
        try {
            $fn();
        } catch ( Throwable $e ) {
            error_log( '[CashFlow Sync] Catalogue hook failed: ' . $e->getMessage() );
            // Shown on the panel too (Golden Rule #6), guarded again: recording
            // the failure must not be what breaks the save.
            try { self::note_error( 'A product change could not be queued: ' . $e->getMessage() ); } catch ( Throwable $ignored ) {}
        }
    }

    // ── The payload [B1, S3, NB2] ───────────────────────────────────

    /**
     * The one payload builder. It feeds BOTH the send and the fingerprint.
     * WC_Product getters in 'edit' context: the stored values, before display
     * filters. Never prepare_object_for_response — it runs every REST filter
     * another plugin has added and costs far more per product. [NB2]
     */
    public static function payload( $product ) {
        $image_id = (int) $product->get_image_id( 'edit' );
        if ( $image_id <= 0 ) {
            // The REST API's images[0] is the featured image, else the first gallery image.
            $gallery  = array_values( (array) $product->get_gallery_image_ids( 'edit' ) );
            $image_id = isset( $gallery[0] ) ? (int) $gallery[0] : 0;
        }
        $src = $image_id > 0 ? wp_get_attachment_url( $image_id ) : false;
        if ( is_string( $src ) && '' !== $src ) {
            // wp_get_attachment_url() upgrades http → https only when
            // is_ssl() && !is_admin() — a fact of THIS request, not of the
            // site. Action Scheduler runs the catalogue job through both
            // WP-Cron (never admin) and admin-ajax (always admin), so the
            // SAME product would fingerprint differently depending on which
            // one happened to build it, and the hourly list would resend it
            // forever for no reason. Force the scheme to the STORED home
            // option's scheme — not home_url(), which itself switches to https
            // whenever the current request is SSL.
            $home_scheme = wp_parse_url( (string) get_option( 'home' ), PHP_URL_SCHEME );
            if ( in_array( $home_scheme, [ 'http', 'https' ], true ) ) {
                $src = set_url_scheme( $src, $home_scheme );
            }
        }
        $src = is_string( $src ) ? self::clean( $src ) : '';
        // Like the server: a URL over 2,048 characters is dropped, never cut — a cut URL is a broken one.
        $images = ( '' !== $src && mb_strlen( $src, 'UTF-8' ) <= self::CAP_IMAGE_URL ) ? [ [ 'src' => $src ] ] : [];

        $variations = [];
        if ( $product->is_type( 'variable' ) ) {
            // WC_Product_Variable::get_children( $visible_only = '' ) takes no context.
            $variations = array_values( array_filter( array_map( 'intval', (array) $product->get_children() ),
                function ( $v ) { return $v > 0; } ) );
            sort( $variations, SORT_NUMERIC );
            $variations = array_slice( $variations, 0, self::CAP_VARIATIONS );
        }
        $stock = $product->get_stock_quantity( 'edit' );

        $fields = [
            'id'                    => (int) $product->get_id(),
            'name'                  => self::cap( $product->get_name( 'edit' ), self::CAP_NAME ),
            'sku'                   => self::cap( $product->get_sku( 'edit' ), self::CAP_SKU ),
            'type'                  => self::cap( $product->get_type(), self::CAP_TYPE ),
            'status'                => self::cap( $product->get_status( 'edit' ), self::CAP_STATUS ),
            'regular_price'         => self::number_or_empty( $product->get_regular_price( 'edit' ) ),
            'sale_price'            => self::number_or_empty( $product->get_sale_price( 'edit' ) ),
            'price'                 => self::number_or_empty( $product->get_price( 'edit' ) ),
            'date_on_sale_from_gmt' => self::gmt( $product->get_date_on_sale_from( 'edit' ) ),
            'date_on_sale_to_gmt'   => self::gmt( $product->get_date_on_sale_to( 'edit' ) ),
            'stock_quantity'        => is_numeric( $stock ) ? 0 + $stock : null,
            'stock_status'          => self::cap( $product->get_stock_status( 'edit' ), self::CAP_STOCK_STATUS ),
            'manage_stock'          => (bool) $product->get_manage_stock( 'edit' ),
            'weight'                => self::number_or_empty( $product->get_weight( 'edit' ) ),
            'categories'            => self::terms( $product->get_category_ids( 'edit' ), 'product_cat' ),
            'tags'                  => self::terms( $product->get_tag_ids( 'edit' ), 'product_tag' ),
            'images'                => $images,
            'variations'            => $variations,
        ];
        $fields = self::fit( $fields );
        return [ 'fields' => $fields, 'fingerprint' => self::fingerprint( $fields ) ];
    }

    /** MD5 of the fields encoded with keys sorted at every depth. Computed ONLY here, only in PHP. */
    public static function fingerprint( array $fields ) {
        return md5( self::encode( self::sorted( $fields ) ) );
    }

    public static function encode( $value ) {
        $json = json_encode( $value, self::JSON_FLAGS );
        return false === $json ? 'null' : $json;
    }

    private static function sorted( $v ) {
        if ( ! is_array( $v ) ) {
            return $v;
        }
        $is_list = [] === $v || array_keys( $v ) === range( 0, count( $v ) - 1 );
        $out = [];
        foreach ( $v as $k => $x ) {
            $out[ $k ] = self::sorted( $x );
        }
        if ( ! $is_list ) {
            ksort( $out, SORT_STRING );
        }
        return $out;
    }

    /**
     * Keep one product inside MAX_ONE_BYTES, deterministically (so the
     * fingerprint of the fitted payload is stable). The caps alone do not
     * guarantee it: 100 categories + 100 tags of 200-character names and slugs
     * of control characters (6 bytes each when escaped) is ~480 KB. After every
     * step the worst case is 10 categories (~24 KB) + name 100 + sku 100 +
     * image 2,048 characters (~13 KB) + 100 variations — under 40 KB.
     */
    private static function fit( array $f ) {
        $steps = [
            function ( $f ) { $f['tags'] = []; return $f; },
            function ( $f ) { $f['categories'] = array_slice( $f['categories'], 0, 10 ); return $f; },
            function ( $f ) { $f['variations'] = array_slice( $f['variations'], 0, 100 ); return $f; },
            function ( $f ) { $f['name'] = mb_substr( $f['name'], 0, 100, 'UTF-8' ); return $f; },
        ];
        foreach ( $steps as $step ) {
            if ( strlen( self::encode( $f ) ) <= self::MAX_ONE_BYTES ) {
                return $f;
            }
            $f = $step( $f );
        }
        return $f;
    }

    private static function terms( $ids, $taxonomy ) {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ), function ( $i ) { return $i > 0; } ) ) );
        if ( ! $ids ) {
            return [];   // never call get_terms with an empty include: core returns EVERY term
        }
        sort( $ids, SORT_NUMERIC );
        $ids = array_slice( $ids, 0, self::CAP_TERMS );
        // hide_empty FALSE: core's default hides a term no published product
        // uses, and it would silently vanish from this product's payload.
        $terms = get_terms( [ 'taxonomy' => $taxonomy, 'include' => $ids, 'hide_empty' => false ] );
        if ( ! is_array( $terms ) ) {
            throw new RuntimeException( 'Could not read ' . $taxonomy . ' terms: '
                . ( is_wp_error( $terms ) ? $terms->get_error_message() : 'unexpected answer' ) );
        }
        $out = [];
        foreach ( $terms as $t ) {
            $out[] = [
                'id'   => (int) $t->term_id,
                'name' => self::cap( $t->name, self::CAP_TERM_NAME ),
                'slug' => self::cap( $t->slug, self::CAP_TERM_SLUG ),
            ];
        }
        usort( $out, function ( $a, $b ) { return $a['id'] <=> $b['id']; } );
        return $out;
    }

    /**
     * A price or weight string over CAP_NUMBER is sent empty, never cut: a cut
     * number is a different number, and the server reads a bad one as empty.
     */
    private static function number_or_empty( $value ) {
        $v = self::clean( is_scalar( $value ) ? (string) $value : '' );
        return mb_strlen( $v, 'UTF-8' ) > self::CAP_NUMBER ? '' : $v;
    }

    /** A string of at most $max characters, valid UTF-8, no U+0000. */
    private static function cap( $value, $max ) {
        $s = self::clean( is_scalar( $value ) ? (string) $value : '' );
        return mb_strlen( $s, 'UTF-8' ) > $max ? mb_substr( $s, 0, $max, 'UTF-8' ) : $s;
    }

    private static function clean( $s ) {
        $s = (string) $s;
        if ( function_exists( 'mb_scrub' ) ) {
            $s = mb_scrub( $s, 'UTF-8' );
        } elseif ( function_exists( 'wp_check_invalid_utf8' ) ) {
            $s = wp_check_invalid_utf8( $s, true );
        }
        return str_replace( "\0", '', $s );
    }

    /** WooCommerce's zone-less UTC string, as the REST API writes *_gmt; null for anything not a date. */
    private static function gmt( $date ) {
        return $date instanceof DateTimeInterface ? gmdate( 'Y-m-d\TH:i:s', $date->getTimestamp() ) : null;
    }

    private static $last_seq = 0;

    /**
     * The plugin's microsecond WALL clock as an integer STRING [NB5]. Wall clock,
     * so it keeps rising across reinstalls [review-2]; a string, because a float
     * cannot carry 16 digits exactly everywhere. Never repeats in one process.
     */
    public static function next_seq() {
        $parts = explode( ' ', microtime() );                   // "0.12345600 1790312345"
        $seq   = (int) ( $parts[1] . substr( $parts[0], 2, 6 ) );
        if ( $seq <= self::$last_seq ) {
            $seq = self::$last_seq + 1;
        }
        self::$last_seq = $seq;
        return (string) $seq;
    }

    // ── Stats: a bounded option the status panel reads ──────────────

    public static function stats() {
        $s = get_option( self::STATS_OPTION, [] );
        return is_array( $s ) ? $s : [];
    }

    private static function update_stats( array $patch ) {
        // autoload = false: rewritten every run, read only by the admin panel.
        update_option( self::STATS_OPTION, array_merge( self::stats(), $patch ), false );
    }

    private static function now_iso() {
        return gmdate( 'c', (int) floor( self::now() ) );
    }

    /** Always on the panel; in the sync log only when the message changes (not once a minute). */
    private static function note_error( $message ) {
        $message = (string) $message;
        if ( ( self::stats()['last_error'] ?? '' ) !== $message ) {
            CashFlow_Plugin::log( 'catalog', 'store', 0, 'error', $message );
        }
        self::update_stats( [ 'last_error' => $message, 'last_error_at' => self::now_iso() ] );
    }

    // ── The job ─────────────────────────────────────────────────────

    public static function is_available() {
        return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' );
    }

    public static function is_scheduled() {
        return self::is_available() && (bool) as_next_scheduled_action( self::TICK_HOOK, [], self::AS_GROUP );
    }

    /**
     * On every init: if no run is pending, schedule one. This is also the
     * recovery path — Action Scheduler can mark an action failed after a PHP
     * fatal, and the next request puts the recurring job back.
     */
    public function maybe_schedule() {
        if ( ! self::is_available() ) {
            return;   // the status panel says "unavailable"
        }
        if ( as_next_scheduled_action( self::TICK_HOOK, [], self::AS_GROUP ) ) {
            return;
        }
        as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::TICK_HOOK, [], self::AS_GROUP, true, self::AS_PRIORITY );
    }

    /** One run. Never throws: the next run is the retry. */
    public function tick() {
        try {
            $this->run();
        } catch ( Throwable $e ) {
            self::note_error( 'The catalogue job crashed: ' . $e->getMessage() );
        }
    }

    private function run() {
        $secret = get_option( 'cashflow_connection_secret', '' );
        if ( empty( $secret ) ) {
            return;   // not connected: nothing to send, and the panel already says so
        }
        if ( ! self::table_exists() && ! self::install() ) {
            self::update_stats( [ 'table_missing' => true ] );
            self::note_error( 'The catalogue queue table is missing and could not be created; product changes are not being sent' );
            return;
        }
        $this->deadline = self::now() + self::BUDGET_SECONDS;
        self::update_stats( [ 'table_missing' => false, 'last_run_at' => self::now_iso() ] );
        $this->work( $secret );
    }

    /** No request starts with less than MIN_LEFT_TO_START seconds of this run's budget left. */
    private function can_start() {
        return ( $this->deadline - self::now() ) >= self::MIN_LEFT_TO_START;
    }

    private function work( $secret ) {
        // Real saves first [review-2], until none is due or the run must stop.
        while ( true ) {
            $d = $this->drain( $secret, true );
            if ( 'stop' === $d ) {
                return;
            }
            if ( 'empty' === $d ) {
                break;
            }
        }
        // Then everything else.
        while ( 'sent' === $this->drain( $secret, false ) ) {
        }
    }

    /** Claim a batch, build it, send it: 'sent' (a batch was handled), 'empty', or 'stop' (end this run). */
    private function drain( $secret, $real_only ) {
        if ( ! $this->can_start() ) {
            return 'stop';
        }
        $token = bin2hex( random_bytes( 16 ) );
        $rows  = self::claim( $token, self::MAX_PRODUCTS, $real_only );
        if ( null === $rows ) {
            return 'stop';   // the queue could not be read; noted by claim()
        }
        if ( ! $rows ) {
            return 'empty';
        }
        $live = [];
        foreach ( $rows as $row ) {
            if ( (int) $row['attempts'] >= self::MAX_ATTEMPTS ) {
                // Taken back from runs that died MAX_ATTEMPTS times — most likely a
                // PHP fatal while reading this product. Park it; do not die again.
                self::park_row( $row, $token, (int) $row['attempts'] );
            } else {
                $live[] = $row;
            }
        }
        $entries = $this->entries_for( $live, $token );
        if ( ! $entries ) {
            return 'sent';
        }
        list( $fit, $rest, $body ) = self::pack( $entries );
        self::release_entries( $rest, $token );          // did not fit: the next claim takes them, no try counted
        return 'stop' === $this->send_split( $secret, $fit, $token, $body ) ? 'stop' : 'sent';
    }

    /** One entry per product id: the product as it is NOW, or a removal if it is gone. */
    private function entries_for( array $rows, $token ) {
        $by = [];
        foreach ( $rows as $row ) {
            $by[ (int) $row['product_id'] ][] = $row;
        }
        $entries = [];
        foreach ( $by as $id => $group ) {
            try {
                $seq     = self::next_seq();             // taken when the product is READ, before it is [NB5]
                $product = wc_get_product( $id );
                if ( ! $product ) {
                    $entries[] = [ 'kind' => 'removed', 'id' => $id, 'rows' => $group, 'body' => [ 'id' => $id, 'sent_seq' => $seq ] ];
                    continue;
                }
                // A product that exists is sent as itself, whatever its rows said
                // (a delete that did not complete leaves a live product).
                $status = (string) $product->get_status( 'edit' );
                if ( 'trash' !== $status && ! in_array( $status, self::SET_STATUSES, true ) ) {
                    foreach ( $group as $row ) {
                        self::done_row( $row, $token );   // auto-draft and the like: outside the set [B7]
                    }
                    continue;
                }
                $built     = self::payload( $product );
                $entries[] = [ 'kind' => 'product', 'id' => $id, 'rows' => $group,
                    'body' => $built['fields'] + [ 'fingerprint' => $built['fingerprint'], 'sent_seq' => $seq ] ];
            } catch ( Throwable $e ) {
                // Recorded UNCONDITIONALLY and BEFORE fail_row runs: if fail_row
                // then hits its own database failure it calls note_error again,
                // and that later call is deliberately what is left on the panel
                // — a live database failure outranks "a product could not be
                // read", which is usually a symptom of the same outage. Both
                // messages still reach the sync log, because note_error logs
                // whenever the message actually changes.
                self::note_error( 'Product ' . $id . ' could not be read: ' . $e->getMessage() );
                foreach ( $group as $row ) {
                    self::fail_row( $row, $token );
                }
            }
        }
        self::flush_cache();
        return $entries;
    }

    /**
     * As many entries as fit in MAX_BODY_BYTES (always at least one — one
     * product is fitted under MAX_ONE_BYTES). Returns the ENCODED body
     * alongside the entries it was measured from, so the bytes send_split()
     * posts are the exact bytes measured here — never re-encoded.
     */
    private static function pack( array $entries ) {
        $rest = [];
        $body = self::encode( self::products_body( $entries ) );
        while ( count( $entries ) > 1 && strlen( $body ) > self::MAX_BODY_BYTES ) {
            array_unshift( $rest, array_pop( $entries ) );
            $body = self::encode( self::products_body( $entries ) );
        }
        return [ $entries, $rest, $body ];
    }

    private static function products_body( array $entries ) {
        $products = [];
        $removed  = [];
        foreach ( $entries as $e ) {
            if ( 'product' === $e['kind'] ) {
                $products[] = $e['body'];
            } else {
                $removed[] = $e['body'];
            }
        }
        $body = [ 'site' => self::site() ];
        if ( $products ) {
            $body['products'] = $products;
        }
        if ( $removed ) {
            $body['removed'] = $removed;
        }
        return $body;
    }

    /** Both raw options: the server's host check may match either. [NB6] */
    public static function site() {
        return [ 'siteurl' => (string) get_option( 'siteurl', '' ), 'home' => (string) get_option( 'home', '' ) ];
    }

    /**
     * $body is already-encoded JSON (from pack() or products_body(), re-encoded
     * the same way when a batch is split): never re-encoded here, so the bytes
     * sent are the bytes measured. Anything that is not a string is a
     * programming error, never a wire failure — refuse it rather than let
     * CashFlow_Plugin::api_request() fall back to wp_json_encode(), which would
     * send different bytes than were measured against MAX_BODY_BYTES.
     */
    private function post( $secret, $endpoint, $body ) {
        if ( ! is_string( $body ) ) {
            self::note_error( 'Sending to CashFlow was refused: the request body was not a pre-encoded string' );
            return [ 'ok' => false, 'status' => 0, 'error' => 'body not pre-encoded', 'data' => null ];
        }
        return CashFlow_Plugin::api_request( $endpoint, 'POST', $body, $secret, self::REQUEST_TIMEOUT );
    }

    /**
     * True when $data carries every one of $keys as its own array field — the
     * minimum shape the wire contract promises. A 2xx with no body, a null
     * body, or an HTML error page answering `ok:true` must never be read as
     * the real contract: that is exactly the shape that would otherwise
     * delete rows CashFlow was never told about. Shared with later catalogue
     * routes (e.g. Task 10's list `classify()`), which check their own keys.
     */
    public static function has_shape( $data, array $keys ) {
        if ( ! is_array( $data ) ) {
            return false;
        }
        foreach ( $keys as $k ) {
            if ( ! isset( $data[ $k ] ) || ! is_array( $data[ $k ] ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * What an answer means for the rows in the body — the wire's refusal table
     * [wire-contract.md, "Refusals common to all three routes"]:
     *   'ok'    A 2xx carrying the contract's shape (has_shape()). A 2xx that
     *           does NOT carry it is never "nothing was written" — it is
     *           treated the same as a stop: released untried, never deleted
     *           on an assumption.
     *   'split' 400, 413, 500: something in THIS body was refused or broke the
     *           write. Split it; a single product that still fails counts a try.
     *   'stop'  401, 403, 404, 409, 429, 502–504, a transport failure, anything
     *           else (including a 2xx with the wrong shape): not the rows'
     *           fault. End the run, count no try.
     */
    public static function classify( array $res ) {
        $code = (int) ( $res['status'] ?? 0 );
        if ( ! empty( $res['ok'] ) && $code >= 200 && $code < 300 ) {
            return self::has_shape( $res['data'] ?? null, [ 'applied', 'trashed', 'unchanged' ] ) ? 'ok' : 'stop';
        }
        if ( in_array( $code, [ 400, 413, 500 ], true ) ) {
            return 'split';
        }
        return 'stop';
    }

    /**
     * Send; on a refusal of the BODY (400/413/500), split it and send each
     * half; a SINGLE product that still fails counts a try (parked on the
     * MAX_ATTEMPTS-th). A stop ends the run with no try counted against
     * anyone. [NB7] $body is the ENCODED string pack() measured for $entries;
     * a split re-encodes each half the same way (products_body() + encode()),
     * so the bytes sent always match the entries they were measured from.
     */
    private function send_split( $secret, array $entries, $token, $body ) {
        if ( ! $this->can_start() ) {
            self::release_entries( $entries, $token );
            return 'stop';
        }
        $res   = $this->post( $secret, self::EP_PRODUCTS, $body );
        $class = self::classify( $res );
        if ( 'ok' === $class ) {
            $this->on_products_ok( $entries, $token, $res['data'] );
            return 'ok';
        }
        if ( 'stop' === $class ) {
            self::release_entries( $entries, $token );
            if ( ! empty( $res['ok'] ) ) {
                // A 2xx, but not with the body the contract promises — never
                // treat that as "nothing was written". The rows are kept
                // (released untried, above), not deleted on an assumption.
                self::note_error( 'Sending products failed: CashFlow answered HTTP '
                    . (int) ( $res['status'] ?? 0 ) . ' without the expected body' );
            } else {
                self::note_failure( 'Sending products', $res );
            }
            return 'stop';
        }
        // 'split': the body itself was refused, not any one product by name.
        if ( 1 === count( $entries ) ) {
            $outcome = '';
            foreach ( $entries[0]['rows'] as $row ) {
                $outcome = self::fail_row( $row, $token );
            }
            if ( 'error' !== $outcome ) {
                // 'error' means fail_row's own database write failed and
                // already recorded THAT failure; a live database failure
                // outranks a send failure and must stay the panel's last word.
                self::note_failure( 'Sending product ' . $entries[0]['id']
                    . ( 'parked' === $outcome ? ' (parked after ' . self::MAX_ATTEMPTS . ' tries)' : '' ), $res );
            }
            return 'failed';
        }
        $half   = (int) ceil( count( $entries ) / 2 );
        $first  = array_slice( $entries, 0, $half );
        $second = array_slice( $entries, $half );
        if ( 'stop' === $this->send_split( $secret, $first, $token, self::encode( self::products_body( $first ) ) ) ) {
            self::release_entries( $second, $token );
            return 'stop';
        }
        return $this->send_split( $secret, $second, $token, self::encode( self::products_body( $second ) ) );
    }

    /** On 200: delete exactly the rows read (by id and token); the product is no longer a parked problem. */
    private function on_products_ok( array $entries, $token, $data ) {
        $d = is_array( $data ) ? $data : [];
        foreach ( $entries as $e ) {
            foreach ( $e['rows'] as $row ) {
                self::done_row( $row, $token );
            }
            self::clear_parked( $e['id'] );
        }
        $s = self::stats();
        self::update_stats( [
            'last_send_at'  => self::now_iso(),
            'sent_count'    => (int) ( $s['sent_count'] ?? 0 ) + count( $entries ),
            'last_warnings' => is_array( $d['warnings'] ?? null ) ? count( $d['warnings'] ) : 0,
        ] );
        self::clear_refusal();
        if ( ! empty( $d['list_wanted'] ) ) {
            $st = self::list_state();
            if ( empty( $st['list_id'] ) ) {
                $st['wanted'] = true;
                self::save_list_state( $st );
            }
        }
    }

    private static function release_entries( array $entries, $token ) {
        foreach ( $entries as $e ) {
            foreach ( $e['rows'] as $row ) {
                self::release_row( $row, $token );
            }
        }
    }

    /**
     * Records what the wire's own status means for the CONNECTION [NB7]:
     * 401 → not connected (the panel's own "not connected" surface); 403
     * site_mismatch → the site's side of the refusal the server also recorded
     * for the store page; 404 → CashFlow has no catalogue route to answer at
     * all. The message itself still goes through describe_connection_refusal()
     * — the one place the wire's exact wording lives — so this never becomes
     * a second copy of that text.
     */
    private static function note_failure( $what, array $res ) {
        $code = (int) ( $res['status'] ?? 0 );
        $data = is_array( $res['data'] ?? null ) ? $res['data'] : [];
        $err  = (string) ( $data['error'] ?? '' );
        if ( 401 === $code ) {
            self::update_stats( [ 'not_connected' => true ] );
        } elseif ( 403 === $code && 'site_mismatch' === $err ) {
            self::update_stats( [ 'site_refusal' => [
                'reason' => (string) ( $data['reason'] ?? '' ),
                'at'     => self::now_iso(),
                'site'   => self::site(),
            ] ] );
        } elseif ( 404 === $code ) {
            $what .= ' (CashFlow does not accept the catalogue yet)';
        }
        self::note_error( $what . ' failed: ' . self::describe_connection_refusal( $res ) );
    }

    /** Any 2xx from a catalogue route proves the connection and the site check pass. */
    private static function clear_refusal() {
        $s = self::stats();
        if ( ! empty( $s['site_refusal'] ) || ! empty( $s['not_connected'] ) ) {
            self::update_stats( [ 'site_refusal' => null, 'not_connected' => false ] );
        }
    }

    /**
     * A 403 connection_not_eligible / site_mismatch names WHY on the wire
     * (wire-contract.md, "Refusals common to all three routes") — show that
     * reason, not a bare status code. store_address_missing gets the wire
     * contract's exact wording, since it is CashFlow's own data gap, not the
     * site's fault. A connection_not_eligible with NO reason at all means the
     * secret resolved to no connection identity — the wire contract says to
     * treat that as connection_missing. Anything else falls back to the one
     * shared "why did this fail" wording (CashFlow_Sync_Pull::describe_failure).
     */
    private static function describe_connection_refusal( array $res ) {
        $status = isset( $res['status'] ) ? (int) $res['status'] : 0;
        $data   = is_array( $res['data'] ?? null ) ? $res['data'] : [];
        $error  = is_string( $data['error'] ?? null ) ? $data['error'] : '';
        $reason = is_string( $data['reason'] ?? null ) ? $data['reason'] : '';
        if ( 403 === $status && 'connection_not_eligible' === $error ) {
            if ( '' === $reason ) {
                $reason = 'connection_missing';
            }
            if ( 'store_address_missing' === $reason ) {
                return 'CashFlow has no store address for this connection — reconnect the store from CashFlow';
            }
            return 'CashFlow refused this connection: ' . $reason;
        }
        if ( 403 === $status && 'site_mismatch' === $error && '' !== $reason ) {
            return 'CashFlow does not recognise this site\'s address (' . $reason . ')';
        }
        return class_exists( 'CashFlow_Sync_Pull' ) ? CashFlow_Sync_Pull::describe_failure( $res ) : 'HTTP ' . $status;
    }

    /** Only the in-request cache: a persistent object cache is never flushed. [NB2] */
    private static function flush_cache() {
        if ( function_exists( 'wp_cache_flush_runtime' ) ) {
            wp_cache_flush_runtime();
        }
    }

    public static function list_state() {
        $s = get_option( self::LIST_OPTION, [] );
        return is_array( $s ) ? $s : [];
    }

    private static function save_list_state( array $st ) {
        update_option( self::LIST_OPTION, $st, false );
    }
}
