<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Webhooks
 * Registers the native WooCommerce webhooks that push order/product/customer
 * events to the CashFlow backend (WooCommerce → CashFlow direction).
 *
 * The backend authenticates each delivery by verifying WooCommerce's
 * X-WC-Webhook-Signature against the store's WC consumer_secret, so we register
 * the webhooks with that consumer_secret as the signing secret and point them at
 * the backend's receive route: /stores/webhook-receive/{store_id}.
 *
 * All per-event syncing to the backend is handled by these native WC webhooks —
 * the plugin no longer POSTs order/status/product/customer changes itself.
 */
class CashFlow_Webhooks {

    // All webhooks we register (topic => label)
    private $webhook_topics = [
        'order.created'    => 'Order Created',
        'order.updated'    => 'Order Updated',
        'order.deleted'    => 'Order Deleted',
        'product.created'  => 'Product Created',
        'product.updated'  => 'Product Updated',
        'product.deleted'  => 'Product Deleted',
        'customer.created' => 'Customer Created',
        'customer.updated' => 'Customer Updated',
    ];

    public function __construct() {
        // Local WC-side behaviour only: stamp the CashFlow order-number meta so
        // the prefixed number (e.g. ZEN-8522) also shows in WC admin. Order data
        // itself reaches CashFlow via the native WC webhooks registered below.
        add_action( 'woocommerce_new_order', [ $this, 'on_order_created' ], 10, 2 );
    }

    // ── Register all webhooks via WC Webhook API ────────────────────
    // $consumer_secret is the store's WooCommerce API consumer secret. WooCommerce
    // signs every delivery with it (X-WC-Webhook-Signature) and the CashFlow
    // backend verifies against the same secret. When not supplied (e.g. the admin
    // re-register action) we read it back from the WC api-keys table.
    public function register_all_webhooks( $token, $store_id, $consumer_secret = null ) {
        if ( empty( $consumer_secret ) ) {
            $consumer_secret = $this->get_wc_consumer_secret();
        }

        // Without the WC consumer_secret, WooCommerce would sign deliveries with an
        // empty secret and the backend could never verify them. Bail loudly instead
        // of registering 8 silently-unverifiable webhooks.
        if ( empty( $consumer_secret ) ) {
            CashFlow_Plugin::log( 'webhook_register', 'store', 0, 'error', 'Cannot register webhooks: WC consumer secret unavailable — reconnect the store.' );
            return [ 'registered' => 0, 'total' => count( $this->webhook_topics ), 'error' => 'missing_consumer_secret' ];
        }

        // Backend receive route: store id AFTER the path segment.
        $webhook_url = CASHFLOW_API_BASE . '/stores/webhook-receive/' . $store_id;

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
            $webhook->set_secret( $consumer_secret );
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
            $settings['store_id'],
            $this->get_wc_consumer_secret()
        );
    }

    // ── Read the store's WC consumer secret from the WC api-keys table ──
    // WooCommerce stores consumer_secret in plaintext keyed by key_id; the connect
    // flow saved that key_id in plugin settings (wc_key_id).
    private function get_wc_consumer_secret() {
        global $wpdb;
        $settings = CashFlow_Plugin::get_settings();
        $key_id   = (int) ( $settings['wc_key_id'] ?? 0 );
        if ( ! $key_id ) return '';
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT consumer_secret FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d",
            $key_id
        ) );
    }

    // ── Order Created — stamp CashFlow order-number meta (local only) ──
    public function on_order_created( $order_id, $order ) {
        $settings = CashFlow_Plugin::get_settings();
        if ( empty( $settings['connected'] ) ) return;

        // Prefix meta set karo — WC admin mein bhi KLJ-8522 dikhega
        $this->set_order_prefix_meta( $order_id );
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
}
