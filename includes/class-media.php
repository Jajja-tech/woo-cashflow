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
    // A run that ends with pictures still waiting asks for another one at
    // once, on its own hook, instead of leaving them to the five-minute tick.
    // Action Scheduler passes its queue about once a minute, so "at once" is
    // its next pass: measured on Zensha on 30 Sep 2026, a follow-up asked for
    // 30 seconds on started 70 and 95 seconds after the run before it ended.
    // A shop with nothing waiting still asks CashFlow only every five minutes.
    const FOLLOW_HOOK    = 'cashflow_media_follow_up';
    const FOLLOW_SECONDS = 0;

    const EP_WANTED = '/plugin/media/wanted';
    const EP_UPLOAD = '/plugin/media/upload';

    const WANTED_TIMEOUT    = 10;
    const WANTED_LIMIT      = 10;         // CashFlow names at most this many per ask
    const UPLOAD_TIMEOUT    = 20;
    const BUDGET_SECONDS    = 25;
    // The first upload of a run always gets the full timeout, so a picture
    // that needs all of it is never starved by the ones sent before it.
    const FIRST_NEEDS       = 21;         // > UPLOAD_TIMEOUT
    // A later upload starts with less, and its timeout is cut to what is left,
    // so every upload still ends inside the budget.
    const MIN_LEFT_TO_START = 6;
    // Pictures go to CashFlow this many at a time. One at a time, a run sent
    // five (measured, same day: CashFlow answers one in 2.6 to 3.9 seconds),
    // and five a run is 300 pictures in about 100 minutes.
    const PARALLEL          = 5;
    // One batch never holds more than this, so five large pictures are not
    // all in memory at once. A picture over it goes alone.
    const BATCH_BYTES       = 10485760;   // 10 MB
    const MAX_BYTES         = 20971520;   // 20 MB; CashFlow accepts up to 25
    // A picture this site could not read (moved, deleted, outside uploads) is
    // not offered again for a day, so one bad URL cannot hold every run.
    const SKIP_OPTION  = 'cashflow_media_skip';
    const SKIP_SECONDS = 86400;
    const STATS_OPTION = 'cashflow_media_last';

    // Test seams. Null = the real WordPress function.
    public static $http     = null;   // callable( $url, $args ) => wp_remote_request response
    public static $http_many = null;  // callable( [ [ 'url', 'args' ], ... ] ) => responses, same keys
    public static $uploads  = null;   // [ 'baseurl' => ..., 'basedir' => ... ]
    public static $api_base = null;   // callable() => https origin
    public static $clock    = null;   // callable() => float seconds

    public function __construct() {
        add_action( 'init', [ $this, 'maybe_schedule' ] );
        add_action( self::TICK_HOOK, [ $this, 'tick' ] );
        add_action( self::FOLLOW_HOOK, [ $this, 'tick' ] );
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
        $stats   = [ 'asked' => 0, 'sent' => 0, 'skipped' => 0, 'refused' => 0, 'error' => null, 'more' => false ];
        $skip    = self::skip_list();
        $named   = [];      // every URL CashFlow named in this run
        $first   = true;    // no upload has been started in this run yet
        $slowest = 0.0;     // the longest upload of this run, in seconds

        while ( true ) {
            $left = self::BUDGET_SECONDS - ( self::now() - $started );
            $res  = CashFlow_Plugin::api_request(
                self::EP_WANTED, 'POST',
                wp_json_encode( [ 'site' => CashFlow_Catalog::site(), 'limit' => self::WANTED_LIMIT ] ),
                $secret, (int) min( self::WANTED_TIMEOUT, max( 1, floor( $left ) - 1 ) )
            );
            if ( empty( $res['ok'] ) ) {
                $stats['error'] = 'CashFlow did not say which pictures it needs: ' . ( $res['error'] ?? ( 'HTTP ' . ( $res['status'] ?? 0 ) ) );
                break;
            }
            $wanted = ( is_array( $res['data'] ?? null ) && is_array( $res['data']['wanted'] ?? null ) ) ? $res['data']['wanted'] : [];

            $round_sent = 0;
            $size       = self::batch_size();
            $i          = 0;
            $n          = count( $wanted );
            while ( $i < $n ) {
                // The next pictures of this list that can be sent, up to a batch.
                $batch = [];
                $bytes = 0;
                $left  = self::BUDGET_SECONDS - ( self::now() - $started );
                while ( $i < $n && count( $batch ) < $size ) {
                    $item = $wanted[ $i ];
                    $url  = is_array( $item ) ? (string) ( $item['url'] ?? '' ) : '';
                    if ( $url !== '' && isset( $named[ $url ] ) ) {
                        $i++;
                        continue;   // named again in a later ask of this run: already dealt with
                    }
                    if ( $url === '' || isset( $skip[ $url ] ) ) {
                        $named[ $url ] = true;
                        $stats['asked']++;
                        $stats['skipped']++;
                        $i++;
                        continue;
                    }
                    if ( [] === $batch && $left < self::needs( $first, $slowest ) ) {
                        // Out of time with a picture in hand. After at least one
                        // upload, the rest follow in a moment; before any, CashFlow
                        // was slow to answer and the five-minute tick carries on.
                        $named[ $url ] = true;
                        $stats['asked']++;
                        $stats['more'] = ! $first;
                        break 3;
                    }
                    $file = self::local_file( $url );
                    if ( null === $file ) {
                        $named[ $url ] = true;
                        $stats['asked']++;
                        $skip[ $url ] = time();
                        $stats['skipped']++;
                        $i++;
                        continue;
                    }
                    $weighs = (int) filesize( $file );
                    if ( [] !== $batch && $bytes + $weighs > self::BATCH_BYTES ) {
                        break;      // it goes first in the next batch
                    }
                    $named[ $url ] = true;
                    $stats['asked']++;
                    $batch[] = [ 'url' => $url, 'file' => $file ];
                    $bytes  += $weighs;
                    $i++;
                }
                if ( [] === $batch ) {
                    break;
                }

                $timeout = $first ? self::UPLOAD_TIMEOUT : (int) min( self::UPLOAD_TIMEOUT, floor( $left ) - 1 );
                $first   = false;
                $began   = self::now();
                $outs    = self::upload_many( $secret, $batch, $timeout );
                $slowest = max( $slowest, self::now() - $began );
                $cut     = false;
                foreach ( $batch as $k => $sent ) {
                    $out = $outs[ $k ];
                    if ( $out['sent'] ) {
                        $stats['sent']++;
                        $round_sent++;
                        continue;
                    }
                    if ( $out['no_answer'] && $timeout < self::UPLOAD_TIMEOUT ) {
                        // Cut off by this run's own clock, not refused. It is
                        // among the first pictures of the next run, with the
                        // full timeout.
                        $cut = true;
                        continue;
                    }
                    // Refused or failed: not offered again today either way. A
                    // refusal will not change by retrying; a failure gets its
                    // retry tomorrow rather than on every run.
                    $skip[ $sent['url'] ] = time();
                    $stats['refused']++;
                    $stats['error'] = $out['reason'];
                }
                if ( $cut ) {
                    $stats['more'] = true;
                    break 2;
                }
            }

            // A short list is the end of what CashFlow needs. A full list that
            // sent nothing would only be named again, so it is not asked for twice.
            if ( count( $wanted ) < self::WANTED_LIMIT || 0 === $round_sent ) {
                break;
            }
            if ( self::BUDGET_SECONDS - ( self::now() - $started ) < self::needs( false, $slowest ) ) {
                $stats['more'] = true;
                break;
            }
        }

        self::save_skip_list( $skip );
        self::record( $stats );
        if ( $stats['more'] ) {
            self::follow_up();
        }
    }

    /**
     * Seconds of budget a batch needs left before it may start. After the
     * first, that is the longest batch seen in this run plus one: when
     * CashFlow takes nine seconds to answer, a third batch is not started
     * with six seconds left only to be cut off.
     */
    private static function needs( $first, $slowest ) {
        return $first ? self::FIRST_NEEDS : max( self::MIN_LEFT_TO_START, (int) ceil( $slowest ) + 1 );
    }

    /**
     * Ask Action Scheduler for one more run soon. Only a PENDING follow-up
     * counts as already asked for. A run that is itself a follow-up is in
     * progress while it asks, and both as_next_scheduled_action() and the
     * unique flag count an in-progress action, so either would end the chain
     * after one link.
     */
    private static function follow_up() {
        if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
            return;
        }
        $pending = as_get_scheduled_actions(
            [ 'hook' => self::FOLLOW_HOOK, 'group' => self::AS_GROUP, 'status' => 'pending', 'per_page' => 1 ],
            'ids'
        );
        if ( ! empty( $pending ) ) {
            return;
        }
        as_schedule_single_action( (int) ceil( self::now() ) + self::FOLLOW_SECONDS, self::FOLLOW_HOOK, [], self::AS_GROUP, false, self::AS_PRIORITY );
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

    /**
     * How many pictures go at once. Five where this server can hold several
     * requests open together (PHP's curl); one where it cannot, so a batch is
     * never sent one after another on a clock meant for one upload.
     */
    private static function batch_size() {
        if ( null !== self::$http_many ) {
            return self::PARALLEL;
        }
        if ( null === self::$http && function_exists( 'curl_multi_init' ) ) {
            return self::PARALLEL;
        }
        return 1;
    }

    /** The request that hands one picture over, or the reason it cannot be made. */
    private static function request_for( $secret, $url, $file, $timeout ) {
        $bytes = file_get_contents( $file );
        if ( false === $bytes || $bytes === '' ) {
            return 'could not read ' . basename( $file );
        }
        $site = CashFlow_Catalog::site();
        $base = null !== self::$api_base ? call_user_func( self::$api_base ) : CashFlow_Plugin::api_base();
        return [
            'url'  => $base . self::EP_UPLOAD,
            'args' => [
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
                'timeout'   => $timeout,
                'sslverify' => true,
            ],
        ];
    }

    /**
     * [ 'sent' => bool, 'reason' => why not, 'no_answer' => true when no
     * reply came back at all (a timeout, a dropped connection) ].
     */
    private static function outcome( $response, $file ) {
        $not = function ( $reason, $no_answer = false ) { return [ 'sent' => false, 'reason' => $reason, 'no_answer' => $no_answer ]; };
        if ( is_wp_error( $response ) ) {
            return $not( $response->get_error_message(), true );
        }
        $code = (int) ( $response['response']['code'] ?? 0 );
        if ( $code >= 200 && $code < 300 ) {
            return [ 'sent' => true, 'reason' => null, 'no_answer' => false ];
        }
        return $not( 'CashFlow refused ' . basename( $file ) . ' (HTTP ' . $code . ')' );
    }

    /** One picture. The outcome is described on outcome(). */
    public static function upload( $secret, $url, $file, $timeout = self::UPLOAD_TIMEOUT ) {
        $req = self::request_for( $secret, $url, $file, $timeout );
        if ( ! is_array( $req ) ) {
            return [ 'sent' => false, 'reason' => $req, 'no_answer' => false ];
        }
        $http = null !== self::$http ? self::$http : 'wp_remote_request';
        return self::outcome( call_user_func( $http, $req['url'], $req['args'] ), $file );
    }

    /**
     * A batch of pictures, all at once: [ [ 'url', 'file' ], ... ] in, one
     * outcome per picture out, under the same keys. Every request has the
     * same timeout, so the batch ends when the slowest of them does.
     */
    public static function upload_many( $secret, $batch, $timeout = self::UPLOAD_TIMEOUT ) {
        if ( count( $batch ) < 2 && null === self::$http_many ) {
            $outs = [];
            foreach ( $batch as $k => $b ) {
                $outs[ $k ] = self::upload( $secret, $b['url'], $b['file'], $timeout );
            }
            return $outs;
        }
        $outs = [];
        $reqs = [];
        foreach ( $batch as $k => $b ) {
            $req = self::request_for( $secret, $b['url'], $b['file'], $timeout );
            if ( is_array( $req ) ) {
                $reqs[ $k ] = $req;
            } else {
                $outs[ $k ] = [ 'sent' => false, 'reason' => $req, 'no_answer' => false ];
            }
        }
        if ( [] !== $reqs ) {
            $many      = null !== self::$http_many ? self::$http_many : [ __CLASS__, 'curl_many' ];
            $responses = call_user_func( $many, $reqs );
            foreach ( $reqs as $k => $req ) {
                $res        = is_array( $responses ) && array_key_exists( $k, $responses ) ? $responses[ $k ] : new WP_Error( 'http_request_failed', 'no answer was recorded for this picture' );
                $outs[ $k ] = self::outcome( $res, $batch[ $k ]['file'] );
            }
        }
        return $outs;
    }

    /**
     * Several requests held open together with PHP's curl, answered in the
     * shape wp_remote_request() answers one: [ 'response' => [ 'code' ],
     * 'body' ] or a WP_Error. WordPress has no call that sends several
     * requests at once, so this talks to curl itself, with the certificate
     * list WordPress ships and certificate checking always on.
     */
    public static function curl_many( $reqs ) {
        $mh      = curl_multi_init();
        $handles = [];
        $ca      = defined( 'ABSPATH' ) && defined( 'WPINC' ) ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : '';
        foreach ( $reqs as $k => $req ) {
            $headers = [ 'Expect:' ];
            foreach ( $req['args']['headers'] as $name => $value ) {
                $headers[] = $name . ': ' . $value;
            }
            $timeout = max( 1, (int) $req['args']['timeout'] );
            $ch      = curl_init( $req['url'] );
            curl_setopt_array( $ch, [
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => $req['args']['body'],
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min( 10, $timeout ),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
            ] );
            if ( $ca !== '' && is_file( $ca ) ) {
                curl_setopt( $ch, CURLOPT_CAINFO, $ca );
            }
            curl_multi_add_handle( $mh, $ch );
            $handles[ $k ] = $ch;
        }

        $failed = [];   // key => curl's own error number, for a request that got no answer
        do {
            $status = curl_multi_exec( $mh, $running );
            while ( $info = curl_multi_info_read( $mh ) ) {
                if ( CURLE_OK !== $info['result'] ) {
                    $k = array_search( $info['handle'], $handles, true );
                    if ( false !== $k ) {
                        $failed[ $k ] = $info['result'];
                    }
                }
            }
            if ( $running && CURLM_OK === $status ) {
                if ( -1 === curl_multi_select( $mh, 1.0 ) ) {
                    usleep( 50000 );
                }
            }
        } while ( $running && CURLM_OK === $status );

        $out = [];
        foreach ( $handles as $k => $ch ) {
            $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            if ( isset( $failed[ $k ] ) || 0 === $code ) {
                $errno     = $failed[ $k ] ?? 0;
                $out[ $k ] = new WP_Error( 'http_request_failed', 'cURL error ' . $errno . ': ' . ( curl_error( $ch ) ?: 'no answer' ) );
            } else {
                $out[ $k ] = [ 'response' => [ 'code' => $code ], 'body' => (string) curl_multi_getcontent( $ch ) ];
            }
            curl_multi_remove_handle( $mh, $ch );
        }
        curl_multi_close( $mh );
        return $out;
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
