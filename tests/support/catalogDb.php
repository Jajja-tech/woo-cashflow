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
        'show_table' => 'get_var', 'insert' => 'query', 'insert_set' => 'query',
        'claim_real' => 'query', 'claim_any' => 'query', 'select_claimed' => 'get_results',
        'done' => 'query', 'release_failed' => 'query', 'release_untried' => 'query', 'park' => 'query',
        'clear_parked' => 'query', 'count_pending' => 'get_var', 'count_parked' => 'get_var',
        'parked_ids' => 'get_col', 'enumerate' => 'get_col',
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
        $q = &CF_TestState::$catalog_queue;
        switch ( $name ) {
            case 'show_table':
                return isset( CF_TestState::$catalog_tables[ $a[0] ] ) ? $a[0] : null;

            case 'insert':
                return self::insert( (int) $a[0], (string) $a[1], (string) $a[2], (string) $a[3] );

            case 'insert_set':
                $n = 0;
                foreach ( self::set_ids( 0, PHP_INT_MAX ) as $id ) {
                    $n += self::insert( $id, 'resend', (string) $a[0], $id . ':resend' );
                }
                return $n;

            case 'claim_real':
            case 'claim_any':
                [ $token, $at, $stale_before, $now, $limit ] = $a;
                $due = [];
                foreach ( $q as $r ) {
                    if ( null !== $r['parked_at'] ) { continue; }
                    if ( null !== $r['token'] && ! ( $r['claimed_at'] < $stale_before ) ) { continue; }
                    if ( null !== $r['retry_at'] && ! ( $r['retry_at'] <= $now ) ) { continue; }
                    if ( 'claim_real' === $name && ! in_array( $r['reason'], [ 'save', 'removed' ], true ) ) { continue; }
                    $due[] = $r;
                }
                usort( $due, function ( $x, $y ) use ( $name ) {
                    if ( 'claim_any' === $name ) {
                        $d = (int) in_array( $x['reason'], [ 'asked', 'resend' ], true ) <=> (int) in_array( $y['reason'], [ 'asked', 'resend' ], true );
                        if ( 0 !== $d ) { return $d; }
                    }
                    return (int) $x['id'] <=> (int) $y['id'];
                } );
                $n = 0;
                foreach ( array_slice( $due, 0, (int) $limit ) as $r ) {
                    $id = (int) $r['id'];
                    // MySQL evaluates SET left to right: attempts reads the OLD token.
                    $q[ $id ]['attempts']    = (string) ( (int) $q[ $id ]['attempts'] + ( null === $q[ $id ]['token'] ? 0 : 1 ) );
                    $q[ $id ]['token']       = (string) $token;
                    $q[ $id ]['claimed_at']  = (string) $at;
                    $q[ $id ]['pending_key'] = null;
                    $n++;
                }
                return $n;

            case 'select_claimed':
                $out = [];
                foreach ( $q as $r ) {
                    if ( $r['token'] === (string) $a[0] ) {
                        $out[] = [ 'id' => $r['id'], 'product_id' => $r['product_id'], 'reason' => $r['reason'], 'attempts' => $r['attempts'] ];
                    }
                }
                usort( $out, function ( $x, $y ) { return (int) $x['id'] <=> (int) $y['id']; } );
                return $out;

            case 'done':
                $id = (int) $a[0];
                if ( isset( $q[ $id ] ) && $q[ $id ]['token'] === (string) $a[1] ) { unset( $q[ $id ] ); return 1; }
                return 0;

            case 'release_failed':
            case 'release_untried':
                [ $id, $token ] = 'release_failed' === $name ? [ (int) $a[1], (string) $a[2] ] : [ (int) $a[0], (string) $a[1] ];
                if ( ! isset( $q[ $id ] ) || $q[ $id ]['token'] !== $token ) { return 0; }
                $key = $q[ $id ]['product_id'] . ':' . $q[ $id ]['reason'];
                foreach ( $q as $other ) {
                    if ( $other['pending_key'] === $key ) { return 0; }   // UPDATE IGNORE: duplicate key, row skipped
                }
                if ( 'release_failed' === $name ) {
                    $q[ $id ]['attempts'] = (string) ( (int) $q[ $id ]['attempts'] + 1 );
                    $q[ $id ]['retry_at'] = (string) $a[0];
                }
                $q[ $id ]['token'] = null;
                $q[ $id ]['claimed_at'] = null;
                $q[ $id ]['pending_key'] = $key;
                return 1;

            case 'park':
                [ $attempts, $at, $id, $token ] = [ (int) $a[0], (string) $a[1], (int) $a[2], (string) $a[3] ];
                if ( ! isset( $q[ $id ] ) || $q[ $id ]['token'] !== $token ) { return 0; }
                $q[ $id ] = array_merge( $q[ $id ], [ 'attempts' => (string) $attempts, 'token' => null, 'claimed_at' => null,
                    'pending_key' => null, 'parked_at' => $at ] );
                return 1;

            case 'clear_parked':
                $n = 0;
                foreach ( $q as $id => $r ) {
                    if ( (int) $r['product_id'] === (int) $a[0] && null !== $r['parked_at'] ) { unset( $q[ $id ] ); $n++; }
                }
                return $n;

            case 'count_pending':
                return (string) count( array_filter( $q, function ( $r ) { return null === $r['parked_at']; } ) );

            case 'count_parked':
                return (string) count( array_filter( $q, function ( $r ) { return null !== $r['parked_at']; } ) );

            case 'parked_ids':
                $parked = array_values( array_filter( $q, function ( $r ) { return null !== $r['parked_at']; } ) );
                usort( $parked, function ( $x, $y ) { return (int) $x['id'] <=> (int) $y['id']; } );
                return array_map( function ( $r ) { return $r['product_id']; }, array_slice( $parked, 0, 20 ) );

            case 'enumerate':
                return array_map( 'strval', self::set_ids( (int) $a[0], (int) $a[1] ) );
        }
        throw new RuntimeException( "harness: catalogue statement $name not modelled" );
    }

    private static function insert( int $product_id, string $reason, string $at, string $key ): int {
        foreach ( CF_TestState::$catalog_queue as $r ) {
            if ( $r['pending_key'] === $key ) { return 0; }         // INSERT IGNORE: duplicate key
        }
        $id = CF_TestState::$catalog_queue_next++;
        CF_TestState::$catalog_queue[ $id ] = [
            'id' => (string) $id, 'product_id' => (string) $product_id, 'reason' => $reason, 'token' => null,
            'attempts' => '0', 'queued_at' => $at, 'claimed_at' => null, 'retry_at' => null, 'parked_at' => null,
            'pending_key' => $key,
        ];
        return 1;
    }

    /** wp_posts: products in the set, id > $after, ascending. */
    private static function set_ids( int $after, int $limit ): array {
        $ids = [];
        foreach ( CF_TestState::$posts as $id => $p ) {
            if ( 'product' === $p['type'] && in_array( $p['status'], [ 'publish', 'future', 'draft', 'pending', 'private' ], true ) && (int) $id > $after ) {
                $ids[] = (int) $id;
            }
        }
        sort( $ids );
        return array_slice( $ids, 0, $limit );
    }
}
