<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Webhooks
 * Local WC-side order-number meta stamping only.
 *
 * The CashFlow backend now registers and manages the native WooCommerce
 * webhooks itself (order.created/updated/deleted → the backend's
 * /stores/webhook-receive/{store_id} route), so this class no longer
 * registers anything. Its only remaining job is stamping the prefixed
 * CashFlow order number (e.g. ZEN-8522) onto new WC orders so it also
 * shows in WC admin.
 */
class CashFlow_Webhooks {

    public function __construct() {
        // Local WC-side behaviour only: stamp the CashFlow order-number meta so
        // the prefixed number (e.g. ZEN-8522) also shows in WC admin. Order data
        // itself reaches CashFlow via the native WC webhooks the backend registers.
        add_action( 'woocommerce_new_order', [ $this, 'on_order_created' ], 10, 2 );
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

        $order->update_meta_data( '_cashflow_order_number', $cf_number );
        $order->save();
    }
}
