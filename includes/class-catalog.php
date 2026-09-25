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

    /** Injectable clock: a callable returning seconds as a float. Null means the real clock. */
    public static $clock = null;

    public function __construct() {
        // Constructed inside plugins_loaded (CashFlow_Plugin::init). A plugin
        // UPDATE never fires the activation hook, so this is where the table is
        // created: on the first request of each site that runs this version. [NB1]
        self::maybe_upgrade();
        self::register_hooks();
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
        }
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
}
