<?php
/**
 * The shop hands CashFlow the product pictures CashFlow cannot fetch.
 *
 * CashFlow keeps its own copy of every product picture so its screens and
 * packing lists never have to reach this website to show one. It fetches that
 * copy from its server, and SiteGround (and hosts like it) answer that server
 * with a "prove you are human" page instead of the picture. A request FROM
 * this site TO CashFlow is never challenged — the order poll has worked that
 * way from the start. So this job asks CashFlow which of this site's pictures
 * it is missing, reads each one from this site's own uploads folder, and sends
 * the bytes.
 *
 * Only files inside the uploads folder are ever read, and only for a URL
 * CashFlow named; CashFlow in turn only accepts a picture it asked for, on
 * this site. Nothing here writes to the shop.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CashFlow_Media {

    const TICK_HOOK   = 'cashflow_media_tick';
    const AS_GROUP    = 'cashflow-media';
    // After the order poll (10) and the catalogue (20): pictures are the least
    // urgent thing this plugin sends, and a fatal here must not stop either.
    const AS_PRIORITY = 30;
    const INTERVAL    = 300;

    const EP_WANTED = '/plugin/media/wanted';
    const EP_UPLOAD = '/plugin/media/upload';

    const WANTED_TIMEOUT    = 10;
    const UPLOAD_TIMEOUT    = 20;
    const BUDGET_SECONDS    = 25;
    const MIN_LEFT_TO_START = 21;         // > UPLOAD_TIMEOUT, so an upload always ends in budget
    const MAX_BYTES         = 20971520;   // 20 MB; CashFlow accepts up to 25
    // A picture this site could not read (moved, deleted, outside uploads) is
    // not offered again for a day, so one bad URL cannot hold every run.
    const SKIP_OPTION  = 'cashflow_media_skip';
    const SKIP_SECONDS = 86400;
    const STATS_OPTION = 'cashflow_media_last';

    // Test seams. Null = the real WordPress function.
    public static $http     = null;   // callable( $url, $args ) => wp_remote_request response
    public static $uploads  = null;   // [ 'baseurl' => ..., 'basedir' => ... ]
    public static $api_base = null;   // callable() => https origin
    public static $clock    = null;   // callable() => float seconds

    public function __construct() {
        add_action( 'init', [ $this, 'maybe_schedule' ] );
        add_action( self::TICK_HOOK, [ $this, 'tick' ] );
    }

    public static function now() {
        return null !== self::$clock ? (float) call_user_func( self::$clock ) : microtime( true );
    }

    public function maybe_schedule() {
        if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
            return;
        }
        if ( as_next_scheduled_action( self::TICK_HOOK, [], self::AS_GROUP ) ) {
            return;
        }
        as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::TICK_HOOK, [], self::AS_GROUP, true, self::AS_PRIORITY );
    }

    /** One run. Never throws: the next run is the retry. */
    public function tick() {
        try {
            self::run();
        } catch ( Throwable $e ) {
            self::record( [ 'error' => 'The picture job crashed: ' . $e->getMessage() ] );
        }
    }

    public static function run() {
        $secret = get_option( 'cashflow_connection_secret', '' );
        if ( empty( $secret ) ) {
            return;
        }
        $started = self::now();
        $stats   = [ 'asked' => 0, 'sent' => 0, 'skipped' => 0, 'refused' => 0, 'error' => null ];

        $res = CashFlow_Plugin::api_request(
            self::EP_WANTED, 'POST',
            wp_json_encode( [ 'site' => CashFlow_Catalog::site(), 'limit' => 10 ] ),
            $secret, self::WANTED_TIMEOUT
        );
        if ( empty( $res['ok'] ) ) {
            $stats['error'] = 'CashFlow did not say which pictures it needs: ' . ( $res['error'] ?? ( 'HTTP ' . ( $res['status'] ?? 0 ) ) );
            self::record( $stats );
            return;
        }
        $wanted = ( is_array( $res['data'] ?? null ) && is_array( $res['data']['wanted'] ?? null ) ) ? $res['data']['wanted'] : [];
        $stats['asked'] = count( $wanted );

        $skip = self::skip_list();
        foreach ( $wanted as $item ) {
            $url = is_array( $item ) ? (string) ( $item['url'] ?? '' ) : '';
            if ( $url === '' || isset( $skip[ $url ] ) ) {
                $stats['skipped']++;
                continue;
            }
            if ( self::BUDGET_SECONDS - ( self::now() - $started ) < self::MIN_LEFT_TO_START ) {
                break;   // the next run carries on
            }
            $file = self::local_file( $url );
            if ( null === $file ) {
                $skip[ $url ] = time();
                $stats['skipped']++;
                continue;
            }
            $sent = self::upload( $secret, $url, $file );
            if ( $sent === 'sent' ) {
                $stats['sent']++;
            } else {
                // Refused or failed: not offered again today either way. A
                // refusal will not change by retrying; a failure gets its
                // retry tomorrow rather than on every run.
                $skip[ $url ] = time();
                $stats['refused']++;
                $stats['error'] = $sent;
            }
        }
        self::save_skip_list( $skip );
        self::record( $stats );
    }

    /**
     * The file on this server behind a URL in this site's uploads folder, or
     * null. The URL must sit under the uploads base URL (scheme and a leading
     * www. aside), and the resolved path must stay inside the uploads folder —
     * a URL can never make this read any other file.
     */
    public static function local_file( $url ) {
        $up = null !== self::$uploads ? self::$uploads : wp_get_upload_dir();
        $base_url = (string) ( $up['baseurl'] ?? '' );
        $base_dir = (string) ( $up['basedir'] ?? '' );
        if ( $base_url === '' || $base_dir === '' ) {
            return null;
        }
        $u = wp_parse_url( $url );
        $b = wp_parse_url( $base_url );
        if ( ! is_array( $u ) || ! is_array( $b ) || empty( $u['host'] ) || empty( $b['host'] ) ) {
            return null;
        }
        $host = function ( $h ) { return preg_replace( '/^www\./', '', strtolower( rtrim( $h, '.' ) ) ); };
        if ( $host( $u['host'] ) !== $host( $b['host'] ) ) {
            return null;
        }
        $bpath = rtrim( (string) ( $b['path'] ?? '' ), '/' ) . '/';
        $upath = (string) ( $u['path'] ?? '' );
        if ( strpos( $upath, $bpath ) !== 0 ) {
            return null;
        }
        $rel = rawurldecode( substr( $upath, strlen( $bpath ) ) );
        if ( $rel === '' || strpos( $rel, "\0" ) !== false || preg_match( '#(^|/)\.\.(/|$)#', $rel ) ) {
            return null;
        }
        $real_base = realpath( $base_dir );
        $real      = realpath( $base_dir . '/' . $rel );
        if ( false === $real_base || false === $real || strpos( $real, rtrim( $real_base, '/' ) . '/' ) !== 0 ) {
            return null;
        }
        if ( ! is_file( $real ) || ! is_readable( $real ) ) {
            return null;
        }
        $size = filesize( $real );
        if ( ! $size || $size > self::MAX_BYTES ) {
            return null;
        }
        return $real;
    }

    public static function mime_of( $path ) {
        $map = [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif' ];
        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        return $map[ $ext ] ?? 'application/octet-stream';
    }

    /** 'sent', or the reason it was not. */
    public static function upload( $secret, $url, $file ) {
        $bytes = file_get_contents( $file );
        if ( false === $bytes || $bytes === '' ) {
            return 'could not read ' . basename( $file );
        }
        $site = CashFlow_Catalog::site();
        $base = null !== self::$api_base ? call_user_func( self::$api_base ) : CashFlow_Plugin::api_base();
        $http = null !== self::$http ? self::$http : 'wp_remote_request';
        $response = call_user_func( $http, $base . self::EP_UPLOAD, [
            'method'  => 'POST',
            'headers' => [
                'Content-Type'           => self::mime_of( $file ),
                'Authorization'          => 'Bearer ' . $secret,
                'X-CashFlow-Site'        => get_site_url(),
                'X-CashFlow-Siteurl'     => $site['siteurl'],
                'X-CashFlow-Home'        => $site['home'],
                'X-CashFlow-Source-Url'  => $url,
                'X-Plugin-Version'       => CASHFLOW_VERSION,
            ],
            'body'      => $bytes,
            'timeout'   => self::UPLOAD_TIMEOUT,
            'sslverify' => true,
        ] );
        if ( is_wp_error( $response ) ) {
            return $response->get_error_message();
        }
        $code = (int) ( $response['response']['code'] ?? 0 );
        if ( $code >= 200 && $code < 300 ) {
            return 'sent';
        }
        return 'CashFlow refused ' . basename( $file ) . ' (HTTP ' . $code . ')';
    }

    private static function skip_list() {
        $list = get_option( self::SKIP_OPTION, [] );
        $list = is_array( $list ) ? $list : [];
        $now  = time();
        foreach ( $list as $url => $at ) {
            if ( $now - (int) $at > self::SKIP_SECONDS ) {
                unset( $list[ $url ] );
            }
        }
        return $list;
    }

    private static function save_skip_list( $list ) {
        // Bounded: a site cannot grow this option without limit.
        if ( count( $list ) > 500 ) {
            asort( $list );
            $list = array_slice( $list, -500, null, true );
        }
        update_option( self::SKIP_OPTION, $list );
    }

    private static function record( $stats ) {
        $stats['at'] = time();
        update_option( self::STATS_OPTION, $stats );
    }
}
