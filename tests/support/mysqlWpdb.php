<?php
/**
 * A $wpdb over mysqli, for running the catalogue's REAL SQL on a real MySQL.
 * prepare() follows core's contract for the two placeholders the catalogue
 * uses — %d becomes an integer, %s a quoted, escaped string — and refuses a
 * placeholder/argument count mismatch (core warns and returns an unusable
 * query; either way it is a bug worth failing on).
 */
class CF_Mysqli_WPDB {
    public string $prefix  = 'wp_';
    public string $posts   = 'wp_posts';
    public string $options = 'wp_options';
    public string $last_error = '';
    private mysqli $db;

    public function __construct( mysqli $db ) { $this->db = $db; }

    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }

    public function prepare( $sql, ...$args ) {
        $args = ( count( $args ) === 1 && is_array( $args[0] ) ) ? $args[0] : $args;
        $i = 0;
        $out = preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $args ) {
            if ( ! array_key_exists( $i, $args ) ) { throw new RuntimeException( 'prepare: fewer arguments than placeholders' ); }
            $v = $args[ $i++ ];
            return '%d' === $m[0] ? (string) (int) $v : "'" . $this->db->real_escape_string( (string) $v ) . "'";
        }, $sql );
        if ( $i !== count( $args ) ) { throw new RuntimeException( 'prepare: more arguments than placeholders' ); }
        return $out;
    }

    private function run( string $sql ) {
        $this->last_error = '';
        $r = $this->db->query( $sql );
        if ( false === $r ) { $this->last_error = $this->db->error; }
        return $r;
    }

    public function query( $sql ) {
        $r = $this->run( $sql );
        if ( false === $r ) { return false; }
        if ( $r instanceof mysqli_result ) { $n = $r->num_rows; $r->free(); return $n; }
        return $this->db->affected_rows;
    }

    public function get_results( $sql, $output = 'OBJECT' ) {
        $r = $this->run( $sql );
        if ( ! $r instanceof mysqli_result ) { return []; }
        $rows = $r->fetch_all( MYSQLI_ASSOC );
        $r->free();
        return $rows;
    }

    public function get_col( $sql ) {
        $r = $this->run( $sql );
        if ( ! $r instanceof mysqli_result ) { return []; }
        $out = [];
        while ( $row = $r->fetch_row() ) { $out[] = $row[0]; }
        $r->free();
        return $out;
    }

    public function get_var( $sql ) {
        $r = $this->run( $sql );
        if ( ! $r instanceof mysqli_result ) { return null; }
        $row = $r->fetch_row();
        $r->free();
        return $row ? $row[0] : null;
    }
}
