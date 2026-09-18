<?php
// ============================================
// 1. PREFIX CONSTANT — FALLBACK ONLY
// ============================================

if ( ! defined( 'CF_ORDER_PREFIX' ) ) {
    define( 'CF_ORDER_PREFIX', 'ORD-' );
}

// ============================================
// 2. HELPERS
// ============================================

function cf_generate_default_prefix() {
    $host  = wp_parse_url( home_url(), PHP_URL_HOST );
    $host  = preg_replace( '/^www\./', '', (string) $host );
    $first = strtoupper( substr( preg_replace( '/[^a-z0-9]/i', '', $host ), 0, 3 ) );

    if ( ! $first ) {
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $first   = $letters[ rand( 0, 25 ) ] . $letters[ rand( 0, 25 ) ] . $letters[ rand( 0, 25 ) ];
    }

    return $first . '-';
}

function cf_get_prefix() {
    // Order of precedence, most authoritative first:
    //   1. cashflow_order_prefix — pushed by CashFlow from the INTEGRATION's
    //      settings. CashFlow owns the prefix now, so its value wins.
    //   2. cf_order_prefix — the local option from before CashFlow pushed one.
    //   3. CF_ORDER_PREFIX — the domain-derived default set on activation.
    //
    // Until this existed, /configure wrote `cashflow_order_prefix` while this
    // read `cf_order_prefix` — two different keys — so a prefix pushed from
    // CashFlow was stored and then never used for the displayed order number.
    //
    // The NUMBERING LOGIC is untouched: the prefix is still only prepended to
    // the order number by cf_display_order_number; order ids are not affected.
    $pushed = get_option( 'cashflow_order_prefix', '' );
    if ( $pushed !== '' ) {
        $pushed = sanitize_text_field( $pushed );
        // Accept it with or without a trailing separator — CashFlow stores the
        // bare prefix ("ZEN"), while the local option historically included it.
        return substr( $pushed, -1 ) === '-' ? $pushed : $pushed . '-';
    }
    $val = get_option( 'cf_order_prefix', '' );
    return $val !== '' ? sanitize_text_field( $val ) : CF_ORDER_PREFIX;
}

function cf_get_padding() {
    return 0;
}

// ============================================
// 3. SET DEFAULT PREFIX ONCE ON ACTIVATION
// ============================================

function cf_on_activation() {
    if ( get_option( 'cf_order_prefix', null ) === null ) {
    $host  = wp_parse_url( home_url(), PHP_URL_HOST );
    $host  = preg_replace( '/^www\./', '', (string) $host );
    $first = strtoupper( substr( preg_replace( '/[^a-z0-9]/i', '', $host ), 0, 3 ) );
    if ( ! $first ) {
            $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $first   = $letters[ rand(0,25) ] . $letters[ rand(0,25) ] . $letters[ rand(0,25) ];
        }
        update_option( 'cf_order_prefix', $first . '-' );
    }
}

// ============================================
// 4. DISPLAY — PREFIX ON ORDER NUMBERS
// ============================================

add_filter( 'woocommerce_order_number', 'cf_display_order_number', 10, 2 );

function cf_display_order_number( $number, $order ) {
    if ( ! function_exists( 'wc_get_order' ) ) {
        return $number;
    }

    // An order CashFlow created carries its OWN number (<prefix>-C<n>), minted
    // by CashFlow and never changed: courier bookings, printed labels and the
    // activity log all point at it. Showing prefix + WooCommerce id instead
    // would give the same order two names — one on the label, another in WP
    // admin, the customer's email and My Account.
    if ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
        $cf_number = (string) $order->get_meta( 'cashflow_order_number' );
        if ( '' !== $cf_number ) {
            return $cf_number;
        }
    }

    return cf_get_prefix() . $order->get_id();
}

/**
 * The WooCommerce id of the order whose CashFlow number is $search, or 0.
 *
 * Admin search otherwise strips the prefix and searches by id, so "1SH-C12"
 * became "C12" and found nothing: a C number is not an id. Accepts the number
 * with or without the prefix and a leading '#'. Used by BOTH the legacy
 * (posts) and the HPOS search below.
 */
function cf_find_cashflow_numbered_order_id( $search ) {
    if ( ! function_exists( 'wc_get_orders' ) ) return 0;

    $search = strtoupper( trim( (string) $search ) );
    if ( substr( $search, 0, 1 ) === '#' ) {
        $search = substr( $search, 1 );
    }
    // Only a C-series shape is looked up; everything else keeps the old path.
    if ( ! preg_match( '/(^|-)C\d+$/', $search ) ) return 0;

    $prefix     = strtoupper( cf_get_prefix() );
    $candidates = [ $search ];
    if ( stripos( $search, $prefix ) !== 0 ) {
        $candidates[] = $prefix . $search;   // "C12" typed without the prefix
    }

    foreach ( $candidates as $candidate ) {
        $ids = wc_get_orders( [
            'meta_key'   => 'cashflow_order_number',
            'meta_value' => $candidate,
            'limit'      => 1,
            'return'     => 'ids',
        ] );
        if ( ! empty( $ids ) ) {
            return (int) $ids[0];
        }
    }
    return 0;
}

// ============================================
// 5. SEARCH — STRIP PREFIX BEFORE QUERY RUNS
// ============================================

add_action( 'admin_init', 'cf_normalize_order_search' );

function cf_normalize_order_search() {
    if ( ! isset( $_GET['s'] ) ) return;

    $is_orders_page = (
        ( isset( $_GET['post_type'] ) && 'shop_order' === $_GET['post_type'] ) ||
        ( isset( $_GET['page'] )      && 'wc-orders'  === $_GET['page']      )
    );

    if ( ! $is_orders_page ) return;

    $search = sanitize_text_field( wp_unslash( $_GET['s'] ) );

    if ( substr( $search, 0, 1 ) === '#' ) {
        $search = substr( $search, 1 );
    }

    // A CashFlow number resolves to its order's id, which the legacy search
    // matches directly (it adds a numeric term as a post ID).
    $cf_id = cf_find_cashflow_numbered_order_id( $search );
    if ( $cf_id > 0 ) {
        $_GET['s']     = (string) $cf_id;
        $_REQUEST['s'] = (string) $cf_id;
        return;
    }

    $prefix = cf_get_prefix();

    if ( stripos( $search, $prefix ) === 0 ) {
        $stripped = substr( $search, strlen( $prefix ) );
        if ( $stripped === '' ) return;
        $_GET['s']     = $stripped;
        $_REQUEST['s'] = $stripped;
        return;
    }

    if ( ctype_digit( $search ) ) return;
}

add_filter( 'woocommerce_order_query_args', 'cf_hpos_search_args', 10 );

function cf_hpos_search_args( $args ) {
    if ( empty( $args['s'] ) ) return $args;

    $search = sanitize_text_field( $args['s'] );

    if ( substr( $search, 0, 1 ) === '#' ) {
        $search = substr( $search, 1 );
    }

    // HPOS: same resolution. The lookup below calls wc_get_orders without an
    // 's', so re-entering this filter returns at the top — no recursion.
    $cf_id = cf_find_cashflow_numbered_order_id( $search );
    if ( $cf_id > 0 ) {
        $args['s'] = (string) $cf_id;
        return $args;
    }

    $prefix = cf_get_prefix();

    if ( stripos( $search, $prefix ) === 0 ) {
        $stripped = substr( $search, strlen( $prefix ) );
        if ( $stripped !== '' ) {
            $args['s'] = $stripped;
        }
    }

    return $args;
}

// Legacy (posts) search also LIKE-matches the order meta fields listed here, so
// a partial CashFlow number ("C12" inside "1SH-C12") is found as well.
add_filter( 'woocommerce_shop_order_search_fields', 'cf_search_cashflow_number_field' );

function cf_search_cashflow_number_field( $fields ) {
    $fields   = (array) $fields;
    $fields[] = 'cashflow_order_number';
    return array_values( array_unique( $fields ) );
}
