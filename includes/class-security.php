<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Security
 *
 * Thin static helper. Store ownership verification (site_url comes from
 * WP, not user input).
 */
class CashFlow_Security {

    // ── Verify store ownership ─────────────────────────────────────
    // site_url comes from WordPress get_site_url() — NOT user input
    // CashFlow backend verifies this URL is registered to the token's org
    public static function get_verified_site_url() {
        return rtrim( get_site_url(), '/' );
    }
}
