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
    const SQL_NAMES = [ 'show_table' ];

    /** Injectable clock: a callable returning seconds as a float. Null means the real clock. */
    public static $clock = null;

    public function __construct() {
        // Constructed inside plugins_loaded (CashFlow_Plugin::init). A plugin
        // UPDATE never fires the activation hook, so this is where the table is
        // created: on the first request of each site that runs this version. [NB1]
        self::maybe_upgrade();
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
        $templates = [
            'show_table' => 'SHOW TABLES LIKE %s',
        ];
        if ( ! isset( $templates[ $name ] ) ) {
            throw new InvalidArgumentException( 'Unknown catalogue statement: ' . $name );
        }
        return strtr( $templates[ $name ], [ '{q}' => self::table(), '{p}' => $wpdb->posts ] );
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
}
