<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Sync
 * Handles CashFlow → WooCommerce direction.
 * Exposes REST endpoints that CashFlow backend can call to update WC.
 * Also handles inventory sync pull from CashFlow.
 */
class CashFlow_Sync {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    // ── Register REST endpoints ─────────────────────────────────────
    // CashFlow backend calls these to update WooCommerce
    public function register_rest_routes() {
        $namespace = 'cashflow/v1';

        // Order status update: CashFlow → WC
        register_rest_route( $namespace, '/order-status', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'update_order_status' ],
            'permission_callback' => [ $this, 'verify_cashflow_request' ],
            'args'                => [
                'order_id' => [ 'required' => true, 'type' => 'integer' ],
                'status'   => [ 'required' => true, 'type' => 'string'  ],
            ],
        ] );

        // Inventory update: CashFlow → WC
        register_rest_route( $namespace, '/update-stock', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'update_stock' ],
            'permission_callback' => [ $this, 'verify_cashflow_request' ],
        ] );

        // Courier meta update: CashFlow → WC order
        register_rest_route( $namespace, '/courier-meta', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'update_courier_meta' ],
            'permission_callback' => [ $this, 'verify_cashflow_request' ],
        ] );

        // Bulk order sync: WC → CashFlow
        register_rest_route( $namespace, '/sync-orders', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'sync_orders_to_cashflow' ],
            'permission_callback' => [ $this, 'verify_cashflow_request' ],
        ] );

        // Health check
        register_rest_route( $namespace, '/ping', [
            'methods'             => 'GET',
            'callback'            => fn() => new WP_REST_Response( [ 'status' => 'ok', 'version' => CASHFLOW_VERSION ], 200 ),
            'permission_callback' => '__return_true',
        ] );
    }

    // ── Verify request is from CashFlow ────────────────────────────
    public function verify_cashflow_request( $request ) {
        $settings = CashFlow_Plugin::get_settings();
        if ( empty( $settings['connected'] ) ) return false;

        // Check plugin secret header
        $secret   = get_option( 'cashflow_plugin_secret' );
        if ( ! $secret ) {
            $secret = wp_generate_password( 40, false );
            update_option( 'cashflow_plugin_secret', $secret );
        }

        $header = $request->get_header( 'X-CashFlow-Secret' );
        if ( hash_equals( $secret, (string) $header ) ) return true;

        // Also accept WC webhook HMAC signature
        $sig = $request->get_header( 'X-WC-Webhook-Signature' );
        if ( $sig ) {
            $body      = $request->get_body();
            $wh_secret = get_option( 'cashflow_webhook_secret', '' );
            $expected  = base64_encode( hash_hmac( 'sha256', $body, $wh_secret, true ) );
            return hash_equals( $expected, $sig );
        }

        return false;
    }

    // ── Update WC order status ──────────────────────────────────────
    public function update_order_status( $request ) {
        $order_id    = (int) $request->get_param( 'order_id' );
        $status      = sanitize_text_field( $request->get_param( 'status' ) );
        $note        = sanitize_text_field( $request->get_param( 'note' ) ?? '' );
        $notify      = (bool) $request->get_param( 'notify_customer' );

        // Also support lookup by order_number or external_id
        if ( ! $order_id ) {
            $order_number = $request->get_param( 'order_number' );
            if ( $order_number ) {
                $orders = wc_get_orders( [
                    'meta_key'   => '_order_number',
                    'meta_value' => $order_number,
                    'limit'      => 1,
                ] );
                if ( empty( $orders ) ) {
                    // Try by post ID
                    $order_id = (int) $order_number;
                } else {
                    $order_id = $orders[0]->get_id();
                }
            }
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'not_found', 'Order not found', [ 'status' => 404 ] );
        }

        // Use centralized mapper (handles custom statuses: booked, shipped, returned)
        $wc_status = CashFlow_Statuses::normalize( $status );

        // Avoid unnecessary updates
        if ( $order->get_status() === $wc_status ) {
            return new WP_REST_Response( [ 'success' => true, 'message' => 'Status already set' ], 200 );
        }

        $old_status = $order->get_status();

        // Remove our hook temporarily to prevent loop
        remove_action( 'woocommerce_order_status_changed', [ new CashFlow_Webhooks(), 'on_order_status_changed' ], 10 );

        $order->update_status( $wc_status, $note ?: 'Updated via CashFlow', $notify );

        // Re-add hook
        add_action( 'woocommerce_order_status_changed', [ new CashFlow_Webhooks(), 'on_order_status_changed' ], 10, 4 );

        CashFlow_Plugin::log( 'order_status_updated', 'order', $order_id, 'success', "$old_status → $wc_status via CashFlow" );

        return new WP_REST_Response( [
            'success'    => true,
            'order_id'   => $order_id,
            'old_status' => $old_status,
            'new_status' => $wc_status,
        ], 200 );
    }

    // ── Update stock ────────────────────────────────────────────────
    public function update_stock( $request ) {
        $external_id  = (int) $request->get_param( 'external_id' );
        $sku          = sanitize_text_field( $request->get_param( 'sku' ) ?? '' );
        $stock_qty    = $request->get_param( 'stock_qty' );
        $stock_status = sanitize_text_field( $request->get_param( 'stock_status' ) ?? '' );

        // Find product by ID or SKU
        $product = null;
        if ( $external_id ) {
            $product = wc_get_product( $external_id );
        }
        if ( ! $product && $sku ) {
            $product_id = wc_get_product_id_by_sku( $sku );
            if ( $product_id ) $product = wc_get_product( $product_id );
        }

        if ( ! $product ) {
            return new WP_Error( 'not_found', 'Product not found', [ 'status' => 404 ] );
        }

        // Remove our hook temporarily to prevent loop
        remove_action( 'woocommerce_product_set_stock', [ new CashFlow_Webhooks(), 'on_stock_changed' ], 10 );

        if ( $stock_qty !== null ) {
            wc_update_product_stock( $product, (int) $stock_qty, 'set' );
        }
        if ( $stock_status ) {
            $product->set_stock_status( $stock_status );
            $product->save();
        }

        add_action( 'woocommerce_product_set_stock', [ new CashFlow_Webhooks(), 'on_stock_changed' ], 10, 1 );

        CashFlow_Plugin::log( 'stock_updated', 'product', $product->get_id(), 'success', "Stock: $stock_qty" );

        return new WP_REST_Response( [
            'success'    => true,
            'product_id' => $product->get_id(),
            'stock_qty'  => $product->get_stock_quantity(),
        ], 200 );
    }

// ── Update courier meta ─────────────────────────────────────────
public function update_courier_meta( $request ) {
    $order_id       = (int) $request->get_param( 'order_id' );
    $order_number   = $request->get_param( 'order_number' );
    $courier_name   = sanitize_text_field( $request->get_param( 'courier_name' )    ?? '' );
    $tracking_no    = sanitize_text_field( $request->get_param( 'tracking_number' ) ?? '' );
    $courier_status = sanitize_text_field( $request->get_param( 'status' )          ?? '' );

    // Find order by ID or number
    $order = null;
    if ( $order_id ) {
        $order = wc_get_order( $order_id );
    }
    if ( ! $order && $order_number ) {
        $orders = wc_get_orders( [
            'meta_key'   => '_order_number',
            'meta_value' => $order_number,
            'limit'      => 1,
        ] );
        if ( ! empty( $orders ) ) $order = $orders[0];
        if ( ! $order ) $order = wc_get_order( (int) $order_number );
    }

    if ( ! $order ) {
        return new WP_Error( 'not_found', 'Order not found', [ 'status' => 404 ] );
    }

    if ( $courier_name   ) {
            update_post_meta( $order->get_id(), '_cashflow_courier_name',    $courier_name   );
            $order->update_meta_data( '_cashflow_courier_name',    $courier_name   );
        }
        if ( $tracking_no    ) {
            update_post_meta( $order->get_id(), '_cashflow_tracking_number', $tracking_no    );
            $order->update_meta_data( '_cashflow_tracking_number', $tracking_no    );
        }
        if ( $courier_status ) {
            update_post_meta( $order->get_id(), '_cashflow_courier_status',  $courier_status );
            $order->update_meta_data( '_cashflow_courier_status',  $courier_status );
        }
        $order->save();

    $order->save();

    // ── Internal order note ────────────────────────────────────────
    $note_msg = sprintf(
        'CashFlow Update — Courier: %s | Tracking: %s | Status: %s',
        $courier_name   ?: 'N/A',
        $tracking_no    ?: 'N/A',
        $courier_status ?: 'N/A'
    );
    $order->add_order_note( $note_msg, false );

    CashFlow_Plugin::log( 'courier_meta_updated', 'order', $order->get_id(), 'success', "Tracking: $tracking_no" );

    return new WP_REST_Response( [
        'success'  => true,
        'order_id' => $order->get_id(),
        'meta'     => [
            'courier_name'    => $courier_name,
            'tracking_number' => $tracking_no,
            'courier_status'  => $courier_status,
        ],
    ], 200 );
}
    // ── Bulk sync orders to CashFlow ────────────────────────────────
    public function sync_orders_to_cashflow( $request ) {
        $limit  = min( (int) ( $request->get_param( 'limit' ) ?? 100 ), 500 );
        $offset = (int) ( $request->get_param( 'offset' ) ?? 0 );
        $status = $request->get_param( 'status' ) ?? '';

        $args = [
            'limit'   => $limit,
            'offset'  => $offset,
            'orderby' => 'date',
            'order'   => 'DESC',
        ];
        if ( $status ) $args['status'] = $status;

        $orders  = wc_get_orders( $args );
        $webhook = new CashFlow_Webhooks();
        $pushed  = 0;

        foreach ( $orders as $order ) {
            $webhook->push_order( $order->get_id() );
            $pushed++;
        }

        return new WP_REST_Response( [
            'success' => true,
            'pushed'  => $pushed,
            'total'   => count( $orders ),
        ], 200 );
    }
}
