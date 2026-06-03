<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Meta
 * Shows courier meta fields in WooCommerce order admin.
 * Proper meta fields — NOT customer notes.
 */
class CashFlow_Meta {

    public function __construct() {
        // Show meta in order admin
        add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'display_courier_meta_box' ], 10, 1 );

        // Add meta box to order edit page
        add_action( 'add_meta_boxes', [ $this, 'add_courier_meta_box' ] );

        // Save meta from order edit page
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'save_courier_meta' ], 10, 2 );

        // Show in order list column
        add_filter( 'manage_edit-shop_order_columns',        [ $this, 'add_tracking_column'    ], 20    );
        add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_tracking_column' ], 10, 2 );

        // Show in HPOS order list
        add_filter( 'woocommerce_shop_order_list_table_columns',       [ $this, 'add_tracking_column'          ], 20    );
        add_action( 'woocommerce_shop_order_list_table_custom_column', [ $this, 'render_tracking_column_hpos'  ], 10, 2 );
    }

    // ── Add meta box ────────────────────────────────────────────────
    public function add_courier_meta_box() {
        $screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
            && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        add_meta_box(
            'cashflow_courier_meta',
            '🚚 CashFlow — Courier Info',
            [ $this, 'render_courier_meta_box' ],
            $screen,
            'side',
            'high'
        );
    }

    // ── Render meta box ─────────────────────────────────────────────
    public function render_courier_meta_box( $post_or_order ) {
        $order_id = $post_or_order instanceof WC_Order
            ? $post_or_order->get_id()
            : $post_or_order->ID;

        $courier_name    = get_post_meta( $order_id, '_cashflow_courier_name',    true );
        $tracking_number = get_post_meta( $order_id, '_cashflow_tracking_number', true );
        $courier_status  = get_post_meta( $order_id, '_cashflow_courier_status',  true );

        wp_nonce_field( 'cashflow_save_meta', 'cashflow_meta_nonce' );
        ?>
        <div class="cashflow-meta-box">
            <p>
                <label><strong><?php esc_html_e( 'Courier', 'cashflow-sync' ); ?></strong></label><br>
                <input type="text" name="_cashflow_courier_name"
                       value="<?php echo esc_attr( $courier_name ); ?>"
                       placeholder="e.g. PostEx"
                       style="width:100%;margin-top:4px">
            </p>
            <p>
                <label><strong><?php esc_html_e( 'Tracking Number', 'cashflow-sync' ); ?></strong></label><br>
                <input type="text" name="_cashflow_tracking_number"
                       value="<?php echo esc_attr( $tracking_number ); ?>"
                       placeholder="e.g. 29130090000774"
                       style="width:100%;margin-top:4px">
                <?php if ( $tracking_number ) : ?>
                <a href="#" onclick="navigator.clipboard.writeText('<?php echo esc_attr( $tracking_number ); ?>');this.textContent='Copied!';return false;"
                   style="font-size:11px;color:#7c3aed">Copy</a>
                <?php endif; ?>
            </p>
            <p>
                <label><strong><?php esc_html_e( 'Courier Status', 'cashflow-sync' ); ?></strong></label><br>
                <select name="_cashflow_courier_status" style="width:100%;margin-top:4px">
                    <?php
                    $statuses = [
                        ''           => '— Select status —',
                        'pending'    => 'Pending',
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

    // ── Save meta ───────────────────────────────────────────────────
    public function save_courier_meta( $order_id, $post ) {
        if ( ! isset( $_POST['cashflow_meta_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['cashflow_meta_nonce'], 'cashflow_save_meta' ) ) return;

        $fields = [
            '_cashflow_courier_name',
            '_cashflow_tracking_number',
            '_cashflow_courier_status',
        ];

        foreach ( $fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $order_id, $field, sanitize_text_field( $_POST[ $field ] ) );
            }
        }
    }

    // ── Display inline in order page (after shipping address) ───────
    public function display_courier_meta_box( $order ) {
        $tracking = get_post_meta( $order->get_id(), '_cashflow_tracking_number', true );
        $courier  = get_post_meta( $order->get_id(), '_cashflow_courier_name',    true );
        $status   = get_post_meta( $order->get_id(), '_cashflow_courier_status',  true );

        if ( ! $tracking ) return;
        ?>
        <div class="cashflow-shipping-info" style="margin-top:12px;padding:10px 12px;background:#f5f3ff;border-radius:8px;border:1px solid #ddd6fe">
            <p style="margin:0 0 4px;font-weight:600;color:#7c3aed;font-size:12px;text-transform:uppercase;letter-spacing:0.06em">
                🚚 CashFlow Shipment
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

    // ── Add tracking column to order list ───────────────────────────
    public function add_tracking_column( $columns ) {
        $new = [];
        foreach ( $columns as $key => $col ) {
            $new[ $key ] = $col;
            if ( $key === 'order_status' ) {
                $new['cashflow_tracking'] = '🚚 Tracking';
            }
        }
        return $new;
    }

    public function render_tracking_column( $column, $post_id ) {
        if ( $column !== 'cashflow_tracking' ) return;
        $tracking = get_post_meta( $post_id, '_cashflow_tracking_number', true );
        $courier  = get_post_meta( $post_id, '_cashflow_courier_name',    true );
        if ( $tracking ) {
            echo '<small style="color:#7c3aed;font-weight:600">' . esc_html( $courier ) . '</small><br>';
            echo '<code style="font-size:11px">' . esc_html( $tracking ) . '</code>';
        } else {
            echo '<span style="color:#9ca3af">—</span>';
        }
    }

    public function render_tracking_column_hpos( $column, $order ) {
        if ( $column !== 'cashflow_tracking' ) return;
        $this->render_tracking_column( $column, $order->get_id() );
    }
}
