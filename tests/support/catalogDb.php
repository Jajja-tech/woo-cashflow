<?php
/**
 * The catalogue's tables, modelled with MySQL's semantics for EXACTLY the
 * statements CashFlow_Catalog::sql() builds. A statement is recognised by its
 * full SQL text, so if the plugin's SQL changes and this model does not, the
 * statement is unmodelled and throws — it is never "executed" by a guess.
 *
 * catalogQueueMysql.test.php runs the same scenarios on a real MySQL; that is
 * what proves this model honest.
 */
class CF_Test_CatalogDB {

    /** Which $wpdb method each statement must arrive through. */
    const METHODS = [
        'show_table' => 'get_var',
    ];

    public static function name_of( string $sql ): ?string {
        if ( ! class_exists( 'CashFlow_Catalog', false ) ) { return null; }
        foreach ( CashFlow_Catalog::SQL_NAMES as $n ) {
            if ( CashFlow_Catalog::sql( $n ) === $sql ) { return $n; }
        }
        return null;
    }

    public static function run( string $method, string $name, array $a, $wpdb ) {
        CF_TestState::$sql[] = 'catalog:' . $name;
        if ( ( self::METHODS[ $name ] ?? null ) !== $method ) {
            throw new RuntimeException( "harness: catalogue statement $name must not arrive through \$wpdb->$method" );
        }
        if ( null !== CF_TestState::$db_error_on && str_contains( CashFlow_Catalog::sql( $name ), CF_TestState::$db_error_on ) ) {
            $wpdb->last_error = 'harness: injected failure in ' . $name;
            return 'query' === $method ? false : ( 'get_var' === $method ? null : [] );
        }
        switch ( $name ) {
            case 'show_table':
                return isset( CF_TestState::$catalog_tables[ $a[0] ] ) ? $a[0] : null;
        }
        throw new RuntimeException( "harness: catalogue statement $name not modelled" );
    }
}
