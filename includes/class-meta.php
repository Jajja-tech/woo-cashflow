<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Meta
 * HPOS-compatible. Uses WC order API exclusively — no get_post_meta().
 */
class CashFlow_Meta {

    public function __construct() {
        add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'display_courier_meta_box' ], 10, 1 );
        add_action( 'add_meta_boxes', [ $this, 'add_courier_meta_box' ] );
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'save_courier_meta' ], 10, 2 );

        // Legacy order list
        add_filter( 'manage_edit-shop_order_columns',        [ $this, 'add_tracking_column' ], 20 );
        add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_tracking_column_legacy' ], 10, 2 );

        // HPOS order list
        add_filter( 'woocommerce_shop_order_list_table_columns',       [ $this, 'add_tracking_column' ], 20 );
        add_action( 'woocommerce_shop_order_list_table_custom_column', [ $this, 'render_tracking_column_hpos' ], 10, 2 );
    }


    // ── Meta box registration ────────────────────────────────────────
    public function add_courier_meta_box() {
        $screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
            && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        add_meta_box(
            'cashflow_courier_meta',
            'CashFlow — Courier Info',
            [ $this, 'render_courier_meta_box' ],
            $screen,
            'side',
            'high'
        );
    }


    // ── Helper: get order object safely ─────────────────────────────
    private function get_order( $post_or_order ) {
        if ( $post_or_order instanceof WC_Order ) {
            return $post_or_order;
        }
        $id = is_object( $post_or_order ) ? $post_or_order->ID : (int) $post_or_order;
        return wc_get_order( $id );
    }


    // ── Render meta box ─────────────────────────────────────────────
    public function render_courier_meta_box( $post_or_order ) {
        $order = $this->get_order( $post_or_order );
        if ( ! $order ) return;

        $courier_name    = $order->get_meta( '_cashflow_courier_name' );
        $tracking_number = $order->get_meta( '_cashflow_tracking_number' );
        $courier_status  = $order->get_meta( '_cashflow_courier_status' );

        wp_nonce_field( 'cashflow_save_meta', 'cashflow_meta_nonce' );
        ?>
        <div class="cashflow-meta-box">
            <p>
                <label><strong>Courier</strong></label><br>
                <input type="text" name="_cashflow_courier_name"
                       value="<?php echo esc_attr( $courier_name ); ?>"
                       placeholder="e.g. PostEx"
                       style="width:100%;margin-top:4px">
            </p>
            <p>
                <label><strong>Tracking Number</strong></label><br>
                <input type="text" name="_cashflow_tracking_number"
                       value="<?php echo esc_attr( $tracking_number ); ?>"
                       placeholder="e.g. 29130090000774"
                       style="width:100%;margin-top:4px">
                <?php if ( $tracking_number ) : ?>
                <a href="#"
                   onclick="navigator.clipboard.writeText('<?php echo esc_attr( $tracking_number ); ?>');this.textContent='Copied!';return false;"
                   style="font-size:11px;color:#7c3aed">Copy</a>
                <?php endif; ?>
            </p>
            <p>
                <label><strong>Courier Status</strong></label><br>
                <select name="_cashflow_courier_status" style="width:100%;margin-top:4px">
                    <?php
                    $statuses = [
                        ''           => '— Select status —',
                        'unassigned' => 'Unassigned',
                        'unbooked'   => 'Unbooked',
                        'booked'     => 'Booked',
                        'in_transit' => 'In Transit',
                        'delivered'  => 'Delivered',
                        'returned'   => 'Returned',
                        'failed'     => 'Failed',
                    ];
                    foreach ( $statuses as $val => $label ) {
                        printf(
                            '<option value="%s"%s>%s</option>',
                            esc_attr( $val ),
                            selected( $courier_status, $val, false ),
                            esc_html( $label )
                        );
                    }
                    ?>
                </select>
            </p>
            <?php if ( $tracking_number ) : ?>
            <p style="background:#f5f3ff;padding:8px;border-radius:6px;font-size:12px;border:1px solid #ddd6fe">
                <strong>Booked via CashFlow</strong><br>
                <?php echo esc_html( $courier_name ); ?> — <?php echo esc_html( $tracking_number ); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }


    // ── Save meta — HPOS compatible ──────────────────────────────────
    public function save_courier_meta( $order_id ) {
        if ( ! isset( $_POST['cashflow_meta_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['cashflow_meta_nonce'], 'cashflow_save_meta' ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $fields = [
            '_cashflow_courier_name',
            '_cashflow_tracking_number',
            '_cashflow_courier_status',
        ];

        foreach ( $fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                $order->update_meta_data( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
            }
        }

        $order->save();
    }


    // ── Display inline after shipping address ────────────────────────
    public function display_courier_meta_box( $order ) {
        $order = $this->get_order( $order );
        if ( ! $order ) return;

        $tracking = $order->get_meta( '_cashflow_tracking_number' );
        $courier  = $order->get_meta( '_cashflow_courier_name' );
        $status   = $order->get_meta( '_cashflow_courier_status' );

        if ( ! $tracking ) return;
        ?>
        <div class="cashflow-shipping-info" style="margin-top:12px;padding:10px 12px;background:#f5f3ff;border-radius:8px;border:1px solid #ddd6fe">
            <p style="margin:0 0 4px;font-weight:600;color:#7c3aed;font-size:12px;text-transform:uppercase;letter-spacing:0.06em">
                CashFlow Shipment
            </p>
            <?php if ( $courier ) : ?>
            <p style="margin:2px 0;font-size:13px"><strong>Courier:</strong> <?php echo esc_html( $courier ); ?></p>
            <?php endif; ?>
            <p style="margin:2px 0;font-size:13px"><strong>Tracking:</strong>
                <code style="background:#ede9fe;padding:2px 6px;border-radius:4px"><?php echo esc_html( $tracking ); ?></code>
            </p>
            <?php if ( $status ) : ?>
            <p style="margin:2px 0;font-size:13px"><strong>Status:</strong> <?php echo esc_html( ucfirst( $status ) ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }


    // ── Order list column ────────────────────────────────────────────
    public function add_tracking_column( $columns ) {
        $new = [];
        foreach ( $columns as $key => $col ) {
            $new[ $key ] = $col;
            if ( $key === 'order_status' ) {
                $new['cashflow_courier'] = 'Courier Info';
            }
        }
        return $new;
    }

    // Legacy (post-based)
    public function render_tracking_column_legacy( $column, $post_id ) {
        if ( $column !== 'cashflow_courier' ) return;
        $order = wc_get_order( $post_id );
        $this->render_courier_column_output( $order );
    }

    // HPOS
    public function render_tracking_column_hpos( $column, $order ) {
        if ( $column !== 'cashflow_courier' ) return;
        $this->render_courier_column_output( $order );
    }

    private function render_courier_column_output( $order ) {
        if ( ! $order ) return;
        $tracking = $order->get_meta( '_cashflow_tracking_number' );
        $courier  = $order->get_meta( '_cashflow_courier_name' );
        if ( $tracking ) {
            echo '<small style="color:#7c3aed;font-weight:600">' . esc_html( $courier ) . '</small><br>';
            echo '<code style="font-size:11px">' . esc_html( $tracking ) . '</code>';
        } else {
            echo '<span style="color:#9ca3af">—</span>';
        }
    }
}