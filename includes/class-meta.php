<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Meta
 * HPOS-only. Uses WC order API exclusively.
 */
class CashFlow_Meta {

    // Single source of truth for all status data
    private static $statuses = [
        'unassigned'       => [ 'label' => 'Unassigned',       'badge' => 'cf-badge-unassigned', 'raw' => [ 'unassigned',      'Unassigned'                        ] ],
        'unbooked'         => [ 'label' => 'Unbooked',         'badge' => 'cf-badge-unbooked',   'raw' => [ 'unbooked',         'Unbooked'                          ] ],
        'booked'           => [ 'label' => 'Booked',           'badge' => 'cf-badge-booked',     'raw' => [ 'booked',           'Booked'                            ] ],
        'picked_up'        => [ 'label' => 'Picked Up',        'badge' => 'cf-badge-picked-up',  'raw' => [ 'picked_up',        'Picked up',        'Picked Up'      ] ],
        'in_transit'       => [ 'label' => 'In Transit',       'badge' => 'cf-badge-transit',    'raw' => [ 'in_transit',       'In transit',       'In Transit'     ] ],
        'out_for_delivery' => [ 'label' => 'Out for Delivery', 'badge' => 'cf-badge-ofd',        'raw' => [ 'out_for_delivery', 'Out for delivery',  'Out for Delivery'] ],
        'attempted'        => [ 'label' => 'Attempted',        'badge' => 'cf-badge-attempted',  'raw' => [ 'attempted',        'Attempted'                         ] ],
        'held'             => [ 'label' => 'Held',             'badge' => 'cf-badge-held',       'raw' => [ 'held',             'Held'                              ] ],
        'delivered'        => [ 'label' => 'Delivered',        'badge' => 'cf-badge-delivered',  'raw' => [ 'delivered',        'Delivered'                         ] ],
        'returning'        => [ 'label' => 'Returning',        'badge' => 'cf-badge-returning',  'raw' => [ 'returning',        'Returning'                         ] ],
        'returned'         => [ 'label' => 'Returned',         'badge' => 'cf-badge-returned',   'raw' => [ 'returned',         'Returned'                          ] ],
        'cancelled'        => [ 'label' => 'Cancelled',        'badge' => 'cf-badge-cancelled',  'raw' => [ 'cancelled',        'Cancelled'                         ] ],
        'lost'             => [ 'label' => 'Lost',             'badge' => 'cf-badge-lost',       'raw' => [ 'lost',             'Lost'                              ] ],
    ];

    private static $couriers = [
        ''             => '— Select Courier —',
        'PostEx'       => 'PostEx',
        'Leopards'     => 'Leopards Courier',
        'TCS'          => 'TCS Courier',
        'Trax'         => 'Trax',
        'MNP'          => 'MNP',
        'PakistanPost' => 'Pakistan Post',
    ];


    // ── Helpers: pull from single map ────────────────────────────────

    private static function status_label( $key ) {
        $key = self::normalize_status_key( $key );
        return self::$statuses[ $key ]['label'] ?? ucfirst( str_replace( '_', ' ', $key ) );
    }

    private static function status_badge( $key ) {
        $key = self::normalize_status_key( $key );
        return self::$statuses[ $key ]['badge'] ?? 'cf-badge-unassigned';
    }

    private static function status_raw_values( $key ) {
        return self::$statuses[ $key ]['raw'] ?? [ $key ];
    }

    // Normalize raw DB value → snake_case key
    private static function normalize_status_key( $raw ) {
        $normalized = strtolower( str_replace( ' ', '_', trim( $raw ) ) );
        return isset( self::$statuses[ $normalized ] ) ? $normalized : ( $raw ? $normalized : 'unassigned' );
    }


    public function __construct() {
        add_action( 'add_meta_boxes',                                      [ $this, 'add_courier_meta_box'     ]       );
        add_action( 'woocommerce_process_shop_order_meta',                 [ $this, 'save_courier_meta'        ], 10, 1 );
        add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'display_courier_meta_box' ], 10, 1 );

        add_filter( 'woocommerce_shop_order_list_table_columns',       [ $this, 'add_tracking_column'         ], 20    );
        add_action( 'woocommerce_shop_order_list_table_custom_column', [ $this, 'render_tracking_column_hpos' ], 10, 2 );

        add_action( 'woocommerce_order_list_table_restrict_manage_orders', [ $this, 'render_courier_filter' ]       );
        add_filter( 'woocommerce_order_query_args',                        [ $this, 'apply_courier_filter'  ]       );

        add_action( 'admin_head',   [ $this, 'admin_styles'  ] );
        add_action( 'admin_footer', [ $this, 'admin_scripts' ] );
    }


    // ════════════════════════════════════════════════════════════════
    // META BOX
    // ════════════════════════════════════════════════════════════════

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
                <select name="_cashflow_courier_name" style="width:100%;margin-top:4px">
                    <?php foreach ( self::$couriers as $val => $label ) {
                        printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $courier_name, $val, false ), esc_html( $label ) );
                    } ?>
                </select>
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
            <?php if ( $courier_status ) : ?>
            <p style="margin:4px 0 0;font-size:12px;color:#555">
                Status: <strong><?php echo esc_html( self::status_label( $courier_status ) ); ?></strong>
            </p>
            <?php endif; ?>
            <?php if ( $tracking_number ) : ?>
            <p style="background:#f5f3ff;padding:8px;border-radius:6px;font-size:12px;border:1px solid #ddd6fe;margin-top:8px">
                <strong>Booked via CashFlow</strong><br>
                <?php echo esc_html( $courier_name ); ?> — <?php echo esc_html( $tracking_number ); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }


    // ════════════════════════════════════════════════════════════════
    // SAVE
    // ════════════════════════════════════════════════════════════════

    public function save_courier_meta( $order_id ) {
        if ( ! isset( $_POST['cashflow_meta_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['cashflow_meta_nonce'], 'cashflow_save_meta' ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $courier_name    = sanitize_text_field( wp_unslash( $_POST['_cashflow_courier_name']    ?? '' ) );
        $tracking_number = sanitize_text_field( wp_unslash( $_POST['_cashflow_tracking_number'] ?? '' ) );

        if ( empty( $courier_name ) )        $courier_status = 'unassigned';
        elseif ( empty( $tracking_number ) ) $courier_status = 'unbooked';
        else                                 $courier_status = 'booked';

        $order->update_meta_data( '_cashflow_courier_name',    $courier_name    );
        $order->update_meta_data( '_cashflow_tracking_number', $tracking_number );
        $order->update_meta_data( '_cashflow_courier_status',  $courier_status  );
        $order->save();
        update_post_meta( $order->get_id(), '_cashflow_courier_name',    $courier_name    );
        update_post_meta( $order->get_id(), '_cashflow_tracking_number', $tracking_number );
        update_post_meta( $order->get_id(), '_cashflow_courier_status',  $courier_status  );
    }


    // ════════════════════════════════════════════════════════════════
    // INLINE DISPLAY — after shipping address
    // ════════════════════════════════════════════════════════════════

    public function display_courier_meta_box( $order ) {
        $order = $this->get_order( $order );
        if ( ! $order ) return;

        $tracking = $order->get_meta( '_cashflow_tracking_number' );
        $courier  = $order->get_meta( '_cashflow_courier_name' );
        $status   = $order->get_meta( '_cashflow_courier_status' );

        if ( ! $tracking ) return;
        ?>
        <div style="margin-top:12px;padding:10px 12px;background:#f5f3ff;border-radius:8px;border:1px solid #ddd6fe">
            <p style="margin:0 0 4px;font-weight:600;color:#7c3aed;font-size:12px;text-transform:uppercase;letter-spacing:0.06em">CashFlow Shipment</p>
            <?php if ( $courier ) : ?>
            <p style="margin:2px 0;font-size:13px"><strong>Courier:</strong> <?php echo esc_html( $courier ); ?></p>
            <?php endif; ?>
            <p style="margin:2px 0;font-size:13px"><strong>Tracking:</strong>
                <code style="background:#ede9fe;padding:2px 6px;border-radius:4px"><?php echo esc_html( $tracking ); ?></code>
            </p>
            <?php if ( $status ) : ?>
            <p style="margin:2px 0;font-size:13px"><strong>Status:</strong> <?php echo esc_html( self::status_label( $status ) ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }


    // ════════════════════════════════════════════════════════════════
    // COLUMN
    // ════════════════════════════════════════════════════════════════

    public function add_tracking_column( $columns ) {
        $new = [];
        foreach ( $columns as $key => $col ) {
            $new[ $key ] = $col;
            if ( $key === 'order_status' ) {
                $new['cashflow_courier'] = 'Courier Status';
            }
        }
        return $new;
    }

    public function render_tracking_column_hpos( $column, $order ) {
        if ( $column !== 'cashflow_courier' ) return;
        $this->render_courier_column_output( $order );
    }

    private function render_courier_column_output( $order ) {
        if ( ! $order ) return;

        $courier  = trim( (string) $order->get_meta( '_cashflow_courier_name' ) );
        $tracking = trim( (string) $order->get_meta( '_cashflow_tracking_number' ) );
        $raw      = trim( (string) $order->get_meta( '_cashflow_courier_status' ) );

        // Derive if empty
        if ( empty( $raw ) ) {
            if ( empty( $courier ) )      $raw = 'unassigned';
            elseif ( empty( $tracking ) ) $raw = 'unbooked';
            else                          $raw = 'booked';
        }

        $courier_label = ! empty( $courier ) ? ( self::$couriers[ $courier ] ?? $courier ) : '';
        $display_label = $courier_label ?: 'Unassigned';
        $status_label  = self::status_label( $raw );
        $badge_class   = self::status_badge( $raw );

        $tooltip = 'Courier: '  . ( $courier  ?: 'Unassigned' ) . '<br>' .
                   'Tracking: ' . ( $tracking ?: 'None'       ) . '<br>' .
                   'Status: '   . $status_label;

        $order_number = $order->get_order_number();

        $copy_text = implode( "\n", array_filter( [
            'Order: '    . $order_number,
            'Courier: '  . ( $courier      ?: 'Unassigned' ),
            'Tracking: ' . ( $tracking     ?: 'None'       ),
            'Status: '   . $status_label,
        ] ) );
        printf(
            '<span class="cf-status-badge %s woocommerce-tooltip" data-tip="%s" data-copy="%s" title=""><div>%s</div><div><small>%s</small></div></span>',
            esc_attr( $badge_class ),
            esc_attr( $tooltip ),
            esc_attr( $copy_text ),
            esc_html( $display_label ),
            esc_html( $status_label )
        );
    }


    // ════════════════════════════════════════════════════════════════
    // FILTER
    // ════════════════════════════════════════════════════════════════

    public function render_courier_filter() {
        $selected = sanitize_text_field( $_GET['cf_courier_filter'] ?? '' );

        $options = [ '' => 'All Couriers' ];

        // Status options — pulled from single map
        foreach ( self::$statuses as $key => $data ) {
            $options[ $key ] = '— ' . $data['label'];
        }

        // Courier options
        foreach ( self::$couriers as $val => $label ) {
            if ( $val === '' ) continue;
            $options[ $val ] = $label;
        }

        echo '<select name="cf_courier_filter" style="margin-left:8px;">';
        foreach ( $options as $val => $label ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $val ),
                selected( $selected, $val, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
    }

    public function apply_courier_filter( $args ) {
        if ( empty( $_GET['cf_courier_filter'] ) ) return $args;

        $filter = sanitize_text_field( $_GET['cf_courier_filter'] );

        if ( isset( self::$statuses[ $filter ] ) ) {
            $args['meta_query'][] = [
                'key'     => '_cashflow_courier_status',
                'value'   => self::status_raw_values( $filter ),
                'compare' => 'IN',
            ];
        } else {
            $args['meta_query'][] = [
                'key'     => '_cashflow_courier_name',
                'value'   => $filter,
                'compare' => '=',
            ];
        }

        return $args;
    }


    // ════════════════════════════════════════════════════════════════
    // STYLES
    // ════════════════════════════════════════════════════════════════

    public function admin_styles() {
        ?>
        <style>
            .cf-status-badge {
                display: inline-flex;
                flex-direction: column;
                align-items: flex-start;
                line-height: 1.5em;
                padding: 5px 8px;
                border-radius: 4px;
                border-bottom: 1px solid rgba(0,0,0,.06);
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.4px;
                cursor: pointer;
                white-space: nowrap;
            }
            .cf-status-badge small {
                font-weight: 400;
                opacity: 0.75;
                font-size: 10px;
                text-transform: none;
                letter-spacing: 0;
            }
            .cf-badge-unassigned { background: #f1f1f1; color: #777;    }
            .cf-badge-unbooked   { background: #f8dda7; color: #6b4f00; }
            .cf-badge-booked     { background: #d4edda; color: #155724; }
            .cf-badge-picked-up  { background: #cfe2ff; color: #084298; }
            .cf-badge-transit    { background: #e0d7ff; color: #3b1fa8; }
            .cf-badge-ofd        { background: #fff3cd; color: #856404; }
            .cf-badge-attempted  { background: #ffe5d0; color: #7c3200; }
            .cf-badge-held       { background: #e2e3e5; color: #383d41; }
            .cf-badge-delivered  { background: #d1e7dd; color: #0a3622; }
            .cf-badge-returning  { background: #fde8d8; color: #842029; }
            .cf-badge-returned   { background: #f8d7da; color: #842029; }
            .cf-badge-cancelled  { background: #e2e3e5; color: #383d41; }
            .cf-badge-lost       { background: #1a1a1a; color: #fff;    }
        </style>
        <?php
    }


    // ════════════════════════════════════════════════════════════════
    // SCRIPTS
    // ════════════════════════════════════════════════════════════════

    public function admin_scripts() {
        ?>
        <script>
        jQuery(function($){
            if ( typeof $.fn.tipTip !== 'undefined' ) {
                $('.cf-status-badge').tipTip({
                    attribute: 'data-tip',
                    allowHTML: true,
                    fadeIn: 50,
                    fadeOut: 50,
                    delay: 200
                });
            }

            $(document).on( 'click', '.cf-status-badge', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var $badge = $(this);
                var text   = $badge.data('copy');
                if ( ! text ) return;
                navigator.clipboard.writeText( text ).then(function() {
                    var original = $badge.html();
                    $badge.html('<div>Copied!</div>');
                    setTimeout(function(){ $badge.html( original ); }, 1200 );
                });
            });
        });
        </script>
        <?php
    }


    // ════════════════════════════════════════════════════════════════
    // HELPER
    // ════════════════════════════════════════════════════════════════

    private function get_order( $post_or_order ) {
        if ( $post_or_order instanceof WC_Order ) return $post_or_order;
        $id = is_object( $post_or_order ) ? $post_or_order->ID : (int) $post_or_order;
        return wc_get_order( $id );
    }
}