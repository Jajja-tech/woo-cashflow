<?php
/**
 * THE QUEUE TABLE IS CREATED ON UPGRADE, NOT ON ACTIVATION. [NB1]
 *
 * Stores update through the Plugin Update Checker, which never fires the
 * activation hook. So the table is created from plugins_loaded whenever the
 * site's own db-version option is behind — and the option moves ONLY once the
 * table is really there, so a failed create is retried on the next request.
 *
 * Multisite: the harness has no multisite. What it proves is that the table
 * name follows $wpdb->prefix AT CALL TIME and the version is an ordinary
 * per-site option — which is exactly how each site of a network upgrades itself
 * on its own first request. A live network is still the real check.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-catalog.php';

function fresh_site(): void {
    CF_TestState::reset();
    $GLOBALS['wpdb']->prefix = 'wp_';
}

echo "── the first request after the update creates the table\n";
fresh_site();
new CashFlow_Catalog();
ok( 'dbDelta ran once', count( CF_TestState::$dbdelta ) === 1, count( CF_TestState::$dbdelta ) . ' calls' );
$ddl = CF_TestState::$dbdelta[0] ?? '';
ok( 'for this site\'s table', str_contains( $ddl, 'CREATE TABLE wp_cashflow_catalog_queue (' ) );
foreach ( [ 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT', 'product_id bigint(20) unsigned NOT NULL', 'reason varchar(10) NOT NULL',
            'token varchar(40) DEFAULT NULL', 'attempts smallint(5) unsigned NOT NULL DEFAULT 0', 'queued_at datetime NOT NULL',
            'claimed_at datetime DEFAULT NULL', 'retry_at datetime DEFAULT NULL', 'parked_at datetime DEFAULT NULL',
            'pending_key varchar(40) DEFAULT NULL', 'PRIMARY KEY  (id)', 'UNIQUE KEY pending_key (pending_key)',
            'KEY token (token)', 'KEY product_id (product_id)' ] as $line ) {
    ok( "the table declares: $line", str_contains( $ddl, $line ) );
}
ok( 'the version option moved to 1', get_option( 'cashflow_catalog_db_version' ) === '1' );
ok( 'and the table exists', CashFlow_Catalog::table_exists() );

echo "── later requests do nothing\n";
new CashFlow_Catalog();
new CashFlow_Catalog();
ok( 'no second dbDelta while the option is current', count( CF_TestState::$dbdelta ) === 1 );

echo "── a create that did not happen is retried, never recorded as done\n";
fresh_site();
CF_TestState::$dbdelta_creates = false;
new CashFlow_Catalog();
ok( 'the option did NOT move', get_option( 'cashflow_catalog_db_version', 'unset' ) === 'unset' );
ok( 'install() reports the failure', CashFlow_Catalog::install() === false );
CF_TestState::$dbdelta_creates = true;
new CashFlow_Catalog();
ok( 'the next request creates it and records it', get_option( 'cashflow_catalog_db_version' ) === '1' && CashFlow_Catalog::table_exists() );

echo "── an exception in the upgrade never reaches the site\n";
fresh_site();
CF_TestState::$dbdelta_throws = new RuntimeException( 'disk full' );
$threw = false;
try { new CashFlow_Catalog(); } catch ( Throwable $e ) { $threw = true; }
ok( 'the constructor swallowed it', ! $threw );
ok( 'and recorded nothing as done', get_option( 'cashflow_catalog_db_version', 'unset' ) === 'unset' );

echo "── each site of a network has its own table and its own version\n";
fresh_site();
new CashFlow_Catalog();
$GLOBALS['wpdb']->prefix = 'wp_2_';
CF_TestState::$options = [];          // site 2's options table
new CashFlow_Catalog();
ok( 'site 2 created wp_2_cashflow_catalog_queue', isset( CF_TestState::$catalog_tables['wp_2_cashflow_catalog_queue'] ) );
ok( 'site 1\'s table is untouched and still there', isset( CF_TestState::$catalog_tables['wp_cashflow_catalog_queue'] ) );
ok( 'site 2 recorded its own version', get_option( 'cashflow_catalog_db_version' ) === '1' );
$GLOBALS['wpdb']->prefix = 'wp_';

echo "── an unknown statement name is refused, never guessed\n";
$threw = false;
try { CashFlow_Catalog::sql( 'drop_everything' ); } catch ( InvalidArgumentException $e ) { $threw = true; }
ok( 'sql() throws for a name it does not know', $threw );

summary();
