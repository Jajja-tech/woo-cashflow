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

    return cf_get_prefix() . $order->get_id();
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

    $prefix = cf_get_prefix();

    if ( stripos( $search, $prefix ) === 0 ) {
        $stripped = substr( $search, strlen( $prefix ) );
        if ( $stripped !== '' ) {
            $args['s'] = $stripped;
        }
    }

    return $args;
}
