<?php
if ( ! defined( 'CF_ORDER_PREFIX' ) ) {
    $host = wp_parse_url( home_url(), PHP_URL_HOST );
    $host = preg_replace( '/^www\./', '', (string) $host );
    $first = strtoupper( substr( preg_replace( '/[^a-z0-9]/i', '', $host ), 0, 3 ) );
    define( 'CF_ORDER_PREFIX', ( $first ?: 'ORD' ) . '-' );
}

// ============================================
// 2. HELPERS
// ============================================

function cf_get_prefix() {
    $val = get_option( 'cf_order_prefix' );
    if ( empty( $val ) ) {
        $val = CF_ORDER_PREFIX;
    }
    return sanitize_text_field( $val );
}

function cf_get_padding() {
    return 0;
}

function cf_ensure_default_prefix() {
    if ( get_option( 'cf_order_prefix', null ) === null ) {
        update_option( 'cf_order_prefix', CF_ORDER_PREFIX );
        delete_option( 'cf_order_new_only' );
        delete_option( 'cf_order_new_only_since' );
    }
}

add_action( 'init', 'cf_ensure_default_prefix' );

// ============================================
// 3. DISPLAY — PREFIX + PADDING ON ORDER NUMBERS
// ============================================

add_filter( 'woocommerce_order_number', 'cf_display_order_number', 10, 2 );

function cf_display_order_number( $number, $order ) {
    if ( ! function_exists( 'wc_get_order' ) ) {
        return $number;
    }

    $prefix  = cf_get_prefix();

    $id = $order->get_id();

    return $prefix . $id;
}

// ============================================
// 4. SEARCH — STRIP PREFIX BEFORE QUERY RUNS
// ============================================

add_action( 'admin_init', 'cf_normalize_order_search' );

function cf_normalize_order_search() {
    // 1. Guard: search must exist
    if ( ! isset( $_GET['s'] ) ) {
        return;
    }

    // 2. Scope: only WooCommerce orders pages (CPT + HPOS screens)
    $is_orders_page = (
        ( isset( $_GET['post_type'] ) && 'shop_order' === $_GET['post_type'] ) ||
        ( isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] )
    );

    if ( ! $is_orders_page ) {
        return;
    }

    // 3. Get search
    $search = sanitize_text_field( wp_unslash( $_GET['s'] ) );

    // 4. Strip leading #
    if ( substr( $search, 0, 1 ) === '#' ) {
        $search = substr( $search, 1 );
    }

    // 5. Get prefix
    $prefix = cf_get_prefix();

    // 6. If prefixed search → strip prefix
    if ( stripos( $search, $prefix ) === 0 ) {
        $stripped = substr( $search, strlen( $prefix ) );

        if ( '' === $stripped ) {
            return;
        }

        $_GET['s']     = $stripped;
        $_REQUEST['s'] = $stripped;
        return;
    }

    // 7. If purely numeric → do nothing (native works)
    if ( ctype_digit( $search ) ) {
        return;
    }

    // 8. Otherwise → leave unchanged
}

add_filter( 'woocommerce_order_query_args', 'cf_hpos_search_args', 10 );

function cf_hpos_search_args( $args ) {
    if ( empty( $args['s'] ) ) {
        return $args;
    }

    $search = sanitize_text_field( $args['s'] );

    // Strip leading #
    if ( substr( $search, 0, 1 ) === '#' ) {
        $search = substr( $search, 1 );
    }

    // Strip prefix if present
    $prefix = cf_get_prefix();

    if ( stripos( $search, $prefix ) === 0 ) {
        $stripped = substr( $search, strlen( $prefix ) );

        if ( '' !== $stripped ) {
            $args['s'] = $stripped;
        }
    }

    return $args;
}