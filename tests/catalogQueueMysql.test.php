<?php
/**
 * THE QUEUE'S REAL SQL, ON A REAL MySQL 8.4.
 *
 * support/catalogDb.php models MySQL; this file is what keeps that model
 * honest — the same scenarios, the plugin's own statements, its own dbDelta
 * CREATE, a real server. It also proves the one fault no model can see:
 * MySQL evaluates UPDATE ... SET left to right (the last block below).
 *
 * The server's default sql_mode is STRICTER than the one WordPress sets
 * (WordPress removes the strict modes), so a statement that passes here also
 * passes on a store.
 *
 * Gated on CF_TEST_MYSQL=host:port:user:password. Without it this file SKIPS
 * and says so — a skip is never a pass.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-catalog.php';
require_once __DIR__ . '/support/catalogQueueScenarios.php';
require_once __DIR__ . '/support/mysqlWpdb.php';

$cfg = getenv( 'CF_TEST_MYSQL' );
if ( ! $cfg ) {
    echo "  \u{26A0} SKIPPED — CF_TEST_MYSQL is not set, so the queue's SQL was NOT executed by this run.\n";
    echo "    brew services start mysql@8.4 && export CF_TEST_MYSQL='127.0.0.1:3306:root:'\n";
    summary();
}

// NOT $pass: bootstrap.php's ok() counts passes in a GLOBAL named $pass, and a
// password assigned to it would silently corrupt the count.
[ $host, $port, $user, $db_password ] = array_pad( explode( ':', $cfg, 4 ), 4, '' );
mysqli_report( MYSQLI_REPORT_OFF );
$db = @new mysqli( $host, $user, $db_password, '', (int) $port );
ok( 'connected to MySQL', 0 === $db->connect_errno, (string) $db->connect_error );
if ( $db->connect_errno ) { summary(); }
ok( 'the server is MySQL 8', str_starts_with( $db->server_info, '8.' ), $db->server_info );
$db->query( 'DROP DATABASE IF EXISTS cf_catalog_test' );
$db->query( 'CREATE DATABASE cf_catalog_test' );
$db->select_db( 'cf_catalog_test' );

$GLOBALS['wpdb'] = new CF_Mysqli_WPDB( $db );
ok( 'a wp_posts stand-in exists', true === $db->query(
    'CREATE TABLE wp_posts ( ID bigint(20) unsigned NOT NULL, post_type varchar(20) NOT NULL, post_status varchar(20) NOT NULL, PRIMARY KEY (ID) )' ), $db->error );
ok( 'the queue table is created from the plugin\'s OWN dbDelta statement', true === $db->query( CashFlow_Catalog::create_table_sql() ), $db->error );
ok( 'table_exists() finds it through SHOW TABLES', CashFlow_Catalog::table_exists() );

cf_queue_scenarios( [
    'reset' => function ( bool $posts_too = true ) use ( $db ) {
        $db->query( 'TRUNCATE wp_cashflow_catalog_queue' );
        if ( $posts_too ) { $db->query( 'TRUNCATE wp_posts' ); }
    },
    'seed_posts' => function ( array $posts ) use ( $db ) {
        foreach ( $posts as [ $id, $type, $status ] ) {
            $db->query( sprintf( "INSERT INTO wp_posts VALUES (%d, '%s', '%s')", $id, $db->real_escape_string( $type ), $db->real_escape_string( $status ) ) );
        }
    },
    'rows' => function () use ( $db ) {
        return $db->query( 'SELECT * FROM wp_cashflow_catalog_queue ORDER BY id' )->fetch_all( MYSQLI_ASSOC );
    },
] );

echo "── the SET order in the claim is load-bearing — shown on the server, not asserted\n";
$db->query( 'TRUNCATE wp_cashflow_catalog_queue' );
CashFlow_Catalog::enqueue( 1, 'save' );
$swapped = str_replace(
    'SET attempts = attempts + IF(token IS NULL, 0, 1), token = %s,',
    'SET token = %s, attempts = attempts + IF(token IS NULL, 0, 1),',
    CashFlow_Catalog::sql( 'claim_any' ) );
ok( 'the swap applied to the real statement', $swapped !== CashFlow_Catalog::sql( 'claim_any' ) );
$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( $swapped,
    [ 'tokX', CashFlow_Catalog::db_time(), CashFlow_Catalog::db_time( -300 ), CashFlow_Catalog::db_time(), 5 ] ) );
$att = $db->query( 'SELECT attempts FROM wp_cashflow_catalog_queue' )->fetch_row()[0];
ok( 'token-first would count a FRESH claim as a failed try — so attempts must come first', '1' === (string) $att, "attempts=$att" );

$db->query( 'DROP DATABASE cf_catalog_test' );
summary();
