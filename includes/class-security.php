<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Security
 * 
 * Handles:
 * - Store ownership verification (site_url comes from WP, not user)
 * - HMAC request signature verification
 * - Rate limiting on auth attempts
 * - Replay attack prevention (nonce + timestamp)
 */
class CashFlow_Security {

    // Max 5 connection attempts per hour per IP
    const RATE_LIMIT      = 5;
    const RATE_WINDOW     = 3600;
    // Max request age: 5 minutes (prevents replay attacks)
    const MAX_REQUEST_AGE = 300;

    // ── Verify incoming request from CashFlow backend ──────────────
    // Returns true if request is authentic
    public static function verify_incoming_request( WP_REST_Request $request ) {
        $settings = CashFlow_Plugin::get_settings();
        if ( empty( $settings['connected'] ) ) return false;

        // Get plugin secret (shared with CashFlow during handshake)
        $plugin_secret = get_option( 'cashflow_plugin_secret', '' );
        if ( ! $plugin_secret ) return false;

        // Method 1: X-CashFlow-Secret header (simple)
        $secret_header = $request->get_header( 'X-CashFlow-Secret' );
        if ( $secret_header && hash_equals( $plugin_secret, $secret_header ) ) {
            return self::check_replay( $request );
        }

        // Method 2: HMAC signature
        $timestamp = (int) $request->get_header( 'X-CashFlow-Time' );
        $nonce     = $request->get_header( 'X-CashFlow-Nonce' );
        $sig       = $request->get_header( 'X-CashFlow-Sig' );
        $method    = $request->get_method();
        $route     = $request->get_route();

        if ( $timestamp && $nonce && $sig ) {
            // Check timestamp freshness
            if ( abs( time() - $timestamp ) > self::MAX_REQUEST_AGE ) {
                return false; // Request too old or too new
            }

            // Verify HMAC
            $sig_data = $method . $route . $timestamp . $nonce;
            $expected = hash_hmac( 'sha256', $sig_data, $plugin_secret );
            if ( hash_equals( $expected, $sig ) ) {
                return self::check_replay( $request, $nonce );
            }
        }

        // Method 3: WC webhook HMAC (for WC-originated webhooks)
        $wc_sig    = $request->get_header( 'X-WC-Webhook-Signature' );
        if ( $wc_sig ) {
            $wh_secret = get_option( 'cashflow_webhook_secret', '' );
            $body      = $request->get_body();
            $expected  = base64_encode( hash_hmac( 'sha256', $body, $wh_secret, true ) );
            return hash_equals( $expected, $wc_sig );
        }

        return false;
    }

    // ── Replay attack prevention ───────────────────────────────────
    private static function check_replay( $request, $nonce = null ) {
        if ( ! $nonce ) {
            $nonce = $request->get_header( 'X-CashFlow-Nonce' );
        }
        if ( ! $nonce ) return true; // No nonce — skip check (backwards compat)

        $used_nonces = get_transient( 'cashflow_used_nonces' ) ?: [];
        if ( in_array( $nonce, $used_nonces, true ) ) {
            return false; // Replay detected
        }

        // Mark nonce as used (keep for MAX_REQUEST_AGE seconds)
        $used_nonces[] = $nonce;
        // Keep only last 1000 nonces
        if ( count( $used_nonces ) > 1000 ) {
            array_shift( $used_nonces );
        }
        set_transient( 'cashflow_used_nonces', $used_nonces, self::MAX_REQUEST_AGE );
        return true;
    }

    // ── Rate limiting ──────────────────────────────────────────────
    public static function check_rate_limit( $identifier = null ) {
        $id  = $identifier ?: ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        $key = 'cashflow_rl_' . md5( $id );
        $attempts = (int) get_transient( $key );

        if ( $attempts >= self::RATE_LIMIT ) {
            return false; // Rate limited
        }

        set_transient( $key, $attempts + 1, self::RATE_WINDOW );
        return true;
    }

    public static function clear_rate_limit( $identifier = null ) {
        $id  = $identifier ?: ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        delete_transient( 'cashflow_rl_' . md5( $id ) );
    }

    // ── Verify store ownership ─────────────────────────────────────
    // site_url comes from WordPress get_site_url() — NOT user input
    // CashFlow backend verifies this URL is registered to the token's org
    public static function get_verified_site_url() {
        return rtrim( get_site_url(), '/' );
    }

    // ── Sanitize token ─────────────────────────────────────────────
    public static function sanitize_token( $token ) {
        // Remove whitespace, newlines (common paste issue)
        $token = trim( preg_replace( '/\s+/', '', $token ) );
        // Accept CashFlow API tokens (cf_live_xxx) OR JWT tokens
        if ( strpos( $token, 'cf_live_' ) === 0 ) return $token;
        // JWT format: 3 base64 segments separated by dots
        if ( substr_count( $token, '.' ) >= 2 ) return $token;
        return '';
    }
}
