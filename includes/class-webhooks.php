<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Webhooks
 * Registers WooCommerce webhooks to push data to CashFlow backend.
 * WooCommerce → CashFlow direction.
 */
class CashFlow_Webhooks {

    // All webhooks we register
    private $webhook_topics = [
        'order.created'   => 'Order Created',
        'order.updated'   => 'Order Updated',
        'order.deleted'   => 'Order Deleted',
        'product.created' => 'Product Created',
        'product.updated' => 'Product Updated',
        'product.deleted' => 'Product Deleted',
        'customer.created'=> 'Customer Created',
        'customer.updated'=> 'Customer Updated',
    ];

    public function __construct() {
        // Listen for WC webhook deliveries (alternative: use WC hooks directly)
        add_action( 'woocommerce_order_status_changed',   [ $this, 'on_order_status_changed' ], 10, 4 );
        add_action( 'woocommerce_new_order',              [ $this, 'on_order_created'         ], 10, 2 );
        add_action( 'woocommerce_update_order',           [ $this, 'on_order_updated'         ], 10, 2 );
        add_action( 'woocommerce_trash_order',            [ $this, 'on_order_deleted'         ], 10, 1 );
        add_action( 'woocommerce_product_set_stock',      [ $this, 'on_stock_changed'         ], 10, 1 );
        add_action( 'woocommerce_variation_set_stock',    [ $this, 'on_stock_changed'         ], 10, 1 );
        add_action( 'save_post_product',                  [ $this, 'on_product_saved'         ], 20, 3 );
        add_action( 'woocommerce_created_customer',       [ $this, 'on_customer_created'      ], 10, 3 );
    }

    // ── Register all webhooks via WC Webhook API ────────────────────
    public function register_all_webhooks( $token, $store_id ) {
        $settings   = CashFlow_Plugin::get_settings();
        $webhook_url = CASHFLOW_API_BASE . '/stores/' . $store_id . '/webhook-receive';

        // Remove old webhooks first
        $old_ids = get_option( 'cashflow_webhook_ids', [] );
        foreach ( $old_ids as $id ) {
            $wh = new WC_Webhook( $id );
            if ( $wh->get_id() ) $wh->delete( true );
        }

        $new_ids = [];
        $user    = wp_get_current_user();

        foreach ( $this->webhook_topics as $topic => $name ) {
            $webhook = new WC_Webhook();
            $webhook->set_name( 'CashFlow: ' . $name );
            $webhook->set_topic( $topic );
            $webhook->set_delivery_url( $webhook_url );
            $webhook->set_status( 'active' );
            $webhook->set_user_id( $user->ID );
            $webhook->set_secret( $this->get_webhook_secret() );
            $id = $webhook->save();
            if ( $id ) $new_ids[] = $id;
        }

        update_option( 'cashflow_webhook_ids', $new_ids );

        return [
            'registered' => count( $new_ids ),
            'total'      => count( $this->webhook_topics ),
        ];
    }

    // ── Re-register (called from admin) ────────────────────────────
    public function reregister_webhooks() {
        $settings = CashFlow_Plugin::get_settings();
        if ( empty( $settings['connected'] ) || empty( $settings['store_id'] ) ) {
            return false;
        }
        return $this->register_all_webhooks(
            $settings['cashflow_token'],
            $settings['store_id']
        );
    }

    // ── Webhook secret ──────────────────────────────────────────────
    private function get_webhook_secret() {
        $secret = get_option( 'cashflow_webhook_secret' );
        if ( ! $secret ) {
            $secret = wp_generate_password( 40, false );
            update_option( 'cashflow_webhook_secret', $secret );
        }
        return $secret;
    }

    // ── Order Status Changed ────────────────────────────────────────
    public function on_order_status_changed( $order_id, $old_status, $new_status, $order ) {
        $settings = CashFlow_Plugin::get_settings();
        if ( empty( $settings['connected'] ) || empty( $settings['sync_orders'] ) ) return;

        // Map WC status to CashFlow status
        // Use centralized mapper (handles custom statuses too)
        $cashflow_status = CashFlow_Statuses::normalize( $new_status );

        $result = CashFlow_Plugin::api_request(
            '/stores/' . $settings['store_id'] . '/orders/status',
            'POST',
            [
                'order_number' => $order->get_order_number(),
                'external_id'  => $order_id,
                'status'       => $cashflow_status,
                'old_status'   => $old_status,
            ]
        );

        CashFlow_Plugin::log(
            'order_status_changed',
            'order',
            $order_id,
            $result['ok'] ? 'success' : 'error',
            "Status: $old_status → $new_status"
        );
    }

    // ── Order Created ───────────────────────────────────────────────
    // ── Order Created ───────────────────────────────────────────────
    public function on_order_created( $order_id, $order ) {
        $settings = CashFlow_Plugin::get_settings();
        if ( empty( $settings['connected'] ) ) return;

        // Prefix meta set karo — WC admin mein bhi KLJ-8522 dikhega
        $this->set_order_prefix_meta( $order_id );

        if ( empty( $settings['sync_orders'] ) ) return;
        // Small delay to ensure order is fully saved
        wp_schedule_single_event( time() + 2, 'cashflow_push_order', [ $order_id ] );
    }

    // ── Set CashFlow order number meta ──────────────────────────────
    private function set_order_prefix_meta( $order_id ) {
        $prefix = get_option( 'cashflow_order_prefix', '' );
        if ( ! $prefix ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $wc_number = $order->get_order_number();

        // Already has prefix — skip (double-prefix prevention)
        if ( strpos( (string) $wc_number, '-' ) !== false ) return;

        $cf_number = $prefix . '-' . $wc_number;

        update_post_meta( $order_id, '_cashflow_order_number', $cf_number );
    }

    // ── Order Updated ───────────────────────────────────────────────
    public function on_order_updated( $order_id, $order ) {
        $settings = CashFlow_Plugin::get_settings();
        if ( empty( $settings['connected'] ) || empty( $settings['sync_orders'] ) ) return;

        $this->push_order( $order_id );
    }

    // ── Order Deleted ───────────────────────────────────────────────
    public function on_order_deleted( $order_id ) {
        $settings = CashFlow_Plugin::get_settings();
        if ( empty( $settings['connected'] ) ) return;

        CashFlow_Plugin::api_request(
            '/stores/' . $settings['store_id'] . '/orders/delete',
            'POST',
            [ 'external_id' => $order_id ]
        );
    }

    // ── Stock Changed ───────────────────────────────────────────────
    public function on_stock_changed( $product ) {
        $settings = CashFlow_Plugin::get_settings();
        if ( empty( $settings['connected'] ) || empty( $settings['sync_inventory'] ) ) return;

        $result = CashFlow_Plugin::api_request(
            '/stores/' . $settings['store_id'] . '/products/stock-update',
            'POST',
            [
                'external_id'  => $product->get_id(),
                'sku'          => $product->get_sku(),
                'stock_qty'    => $product->get_stock_quantity(),
                'stock_status' => $product->get_stock_status(),
                'manage_stock' => $product->get_manage_stock(),
            ]
        );

        CashFlow_Plugin::log(
            'stock_changed', 'product', $product->get_id(),
            $result['ok'] ? 'success' : 'error',
            'Stock: ' . $product->get_stock_quantity()
        );
    }

    // ── Product Saved ───────────────────────────────────────────────
    public function on_product_saved( $post_id, $post, $update ) {
        $settings = CashFlow_Plugin::get_settings();
        if ( empty( $settings['connected'] ) || empty( $settings['sync_inventory'] ) ) return;
        if ( $post->post_status === 'auto-draft' ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

        $product = wc_get_product( $post_id );
        if ( ! $product ) return;

        CashFlow_Plugin::api_request(
            '/stores/' . $settings['store_id'] . '/products/update',
            'POST',
            [
                'external_id'  => $post_id,
                'name'         => $product->get_name(),
                'sku'          => $product->get_sku(),
                'price'        => $product->get_price(),
                'stock_qty'    => $product->get_stock_quantity(),
                'stock_status' => $product->get_stock_status(),
                'status'       => $post->post_status,
                'image_url'    => wp_get_attachment_url( $product->get_image_id() ) ?: '',
            ]
        );
    }

    // ── Customer Created ────────────────────────────────────────────
    public function on_customer_created( $customer_id, $new_customer_data, $password_generated ) {
        $settings = CashFlow_Plugin::get_settings();
        if ( empty( $settings['connected'] ) || empty( $settings['sync_customers'] ) ) return;

        $customer = new WC_Customer( $customer_id );
        CashFlow_Plugin::api_request(
            '/stores/' . $settings['store_id'] . '/customers/upsert',
            'POST',
            [
                'external_id' => $customer_id,
                'email'       => $customer->get_email(),
                'first_name'  => $customer->get_first_name(),
                'last_name'   => $customer->get_last_name(),
                'phone'       => $customer->get_billing_phone(),
                'city'        => $customer->get_billing_city(),
            ]
        );
    }

    // ── Push single order to CashFlow ───────────────────────────────
    public function push_order( $order_id ) {
        $settings = CashFlow_Plugin::get_settings();
        $order    = wc_get_order( $order_id );
        if ( ! $order ) return;

        $items = [];
        foreach ( $order->get_items() as $item ) {
            $product  = $item->get_product();
            $items[] = [
                'id'        => $item->get_id(),
                'name'      => $item->get_name(),
                'quantity'  => $item->get_quantity(),
                'sku'       => $product ? $product->get_sku() : '',
                'price'     => $order->get_item_total( $item ),
                'total'     => $item->get_total(),
                'subtotal'  => $item->get_subtotal(),
                'image_url' => $product ? wp_get_attachment_url( $product->get_image_id() ) : '',
            ];
        }

        $result = CashFlow_Plugin::api_request(
            '/stores/' . $settings['store_id'] . '/webhook-receive',
            'POST',
            [
                'id'             => $order_id,
                'order_number'   => $order->get_order_number(),
                'status'         => $order->get_status(),
                'currency'       => $order->get_currency(),
                'total'          => $order->get_total(),
                'payment_method' => $order->get_payment_method_title(),
                'customer_note'  => $order->get_customer_note(),
                'date_created'   => $order->get_date_created()?->date( 'c' ),
                'billing'        => [
                    'first_name' => $order->get_billing_first_name(),
                    'last_name'  => $order->get_billing_last_name(),
                    'email'      => $order->get_billing_email(),
                    'phone'      => $order->get_billing_phone(),
                    'address_1'  => $order->get_billing_address_1(),
                    'address_2'  => $order->get_billing_address_2(),
                    'city'       => $order->get_billing_city(),
                    'state'      => $order->get_billing_state(),
                    'postcode'   => $order->get_billing_postcode(),
                    'country'    => $order->get_billing_country(),
                ],
                'shipping'       => [
                    'first_name' => $order->get_shipping_first_name(),
                    'last_name'  => $order->get_shipping_last_name(),
                    'address_1'  => $order->get_shipping_address_1(),
                    'address_2'  => $order->get_shipping_address_2(),
                    'city'       => $order->get_shipping_city(),
                    'state'      => $order->get_shipping_state(),
                    'postcode'   => $order->get_shipping_postcode(),
                    'country'    => $order->get_shipping_country(),
                ],
                'line_items'     => $items,
            ]
        );

        CashFlow_Plugin::log(
            'order_pushed', 'order', $order_id,
            $result['ok'] ? 'success' : 'error',
            'Order #' . $order->get_order_number()
        );
    }
}

// ── Cron: push order after creation ────────────────────────────────
add_action( 'cashflow_push_order', function( $order_id ) {
    ( new CashFlow_Webhooks() )->push_order( $order_id );
} );
