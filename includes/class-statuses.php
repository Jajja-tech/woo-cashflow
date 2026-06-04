<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Statuses
 *
 * Registers all WooCommerce order statuses required by CashFlow.pk.
 * This class is the authoritative source — INMX plugin no longer required.
 *
 * Responsibilities:
 *  - Register custom statuses (booked, shipped, returned) with WordPress/WooCommerce
 *  - Badge colours + WooCommerce font icons in WC admin orders list
 *  - Action buttons per row, flow-aware (only allowed next statuses shown)
 *  - Bulk actions for custom statuses
 *  - Paid-status and analytics declarations
 *  - Normalize() helper for CashFlow sync layer
 */
class CashFlow_Statuses {

    /**
     * Full status registry.
     *
     * type     'core' = built-in WC, no registration needed
     *          'custom' = must be registered via register_post_status()
     * color_bg  Badge/button background hex
     * color_text Badge/button text + border hex
     * glyph    WooCommerce font unicode codepoint for badge + action button icon
     * paid     Whether WC treats this status as paid
     * next     Allowed transition targets (slugs, no wc- prefix)
     */
    const STATUSES = [
        'pending' => [
            'label'      => 'Pending payment',
            'description'=> 'Order is awaiting payment. Do not change the status until payment is received.',
            'type'       => 'core',
            'color_bg'   => '#f8dda7',
            'color_text' => '#94660c',
            'glyph'      => 'e604',
            'paid'       => false,
            'next'       => [ 'processing', 'on-hold', 'cancelled' ],
        ],
        'on-hold' => [
            'label'      => 'On hold',
            'description'=> 'Order is on hold. Awaiting confirmation or manual review.',
            'type'       => 'core',
            'color_bg'   => '#f8dda7',
            'color_text' => '#94660c',
            'glyph'      => 'e033',
            'paid'       => false,
            'next'       => [ 'processing', 'cancelled' ],
        ],
        'processing' => [
            'label'      => 'Processing',
            'description'=> 'Order confirmed, stock reduced, order is being processed.',
            'type'       => 'core',
            'color_bg'   => '#dd9933',
            'color_text' => '#ffffff',
            'glyph'      => 'e011',
            'paid'       => false,
            'next'       => [ 'booked', 'cancelled' ],
        ],
        'booked' => [
            'label'      => 'Booked',
            'description'=> 'Order booked with courier, awaiting dispatch.',
            'type'       => 'custom',
            'color_bg'   => '#2d66b1',
            'color_text' => '#ffffff',
            'glyph'      => 'e006',
            'paid'       => true,
            'next'       => [ 'shipped', 'cancelled' ],
        ],
        'shipped' => [
            'label'      => 'Shipped',
            'description'=> 'Order dispatched and on its way to the customer.',
            'type'       => 'custom',
            'color_bg'   => '#2d66b1',
            'color_text' => '#ffffff',
            'glyph'      => 'e01a',
            'paid'       => true,
            'next'       => [ 'completed', 'failed', 'returned' ],
        ],
        'failed' => [
            'label'      => 'Failed',
            'description'=> 'Order failed to be delivered. Awaiting menual reattempt.',
            'type'       => 'core',
            'color_bg'   => '#eba3a3',
            'color_text' => '#761919',
            'glyph'      => 'e016',
            'paid'       => false,
            'next'       => [ 'shipped', 'returned' ],
        ],
        'returned' => [
            'label'      => 'Returned',
            'description'=> 'Order returned by customer. Awaiting manual review and refund decision.',
            'type'       => 'custom',
            'color_bg'   => '#eba3a3',
            'color_text' => '#761919',
            'glyph'      => 'e014',
            'paid'       => true,
            'next'       => [ 'pending', 'refunded' ],
        ],
        'completed' => [
            'label'      => 'Completed',
            'description'=> 'Order completed successfully. No further action required.',
            'type'       => 'core',
            'color_bg'   => '#81d742',
            'color_text' => '#ffffff',
            'glyph'      => 'e015',
            'paid'       => true,
            'next'       => [ 'refunded' ],
        ],
        'refunded' => [
            'label'      => 'Refunded',
            'description'=> 'Order refunded to the customer.',
            'type'       => 'core',
            'color_bg'   => '#e5e5e5',
            'color_text' => '#777777',
            'glyph'      => 'e012',
            'paid'       => false,
            'next'       => [],
        ],
        'cancelled' => [
            'label'      => 'Cancelled',
            'description'=> 'Order cancelled by customer or admin.',
            'type'       => 'core',
            'color_bg'   => '#eba3a3',
            'color_text' => '#761919',
            'glyph'      => 'e013',
            'paid'       => false,
            'next'       => [],
        ],
        'checkout-draft' => [
            'label'      => 'Draft',
            'description'=> 'Order is a draft and not yet submitted.',
            'type'       => 'core',
            'color_bg'   => '#e5e5e5',
            'color_text' => '#777777',
            'glyph'      => 'e603',
            'paid'       => false,
            'next'       => [ 'pending' ],
        ],
    ];

    // ── Boot ────────────────────────────────────────────────────────────
    public function __construct() {
        // Status registration
        add_action( 'init',                                    [ $this, 'register_statuses'     ] );
        add_filter( 'wc_order_statuses',                       [ $this, 'add_to_order_statuses' ], 20 );
        add_filter( 'woocommerce_order_is_paid_statuses',      [ $this, 'declare_paid_statuses' ] );
        add_filter( 'woocommerce_reports_order_statuses',      [ $this, 'add_to_reports'        ] );
        add_filter( 'woocommerce_analytics_order_statuses',    [ $this, 'add_to_reports'        ] );

        // Admin UI — admin_head to prevent flash of default WC styles
        add_action( 'admin_head',                              [ $this, 'admin_css'             ] );

        // Action buttons — flow-restricted per row
        add_filter( 'woocommerce_admin_order_actions',         [ $this, 'action_buttons'        ], 10, 2 );

        // Bulk actions
        add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ $this, 'bulk_actions'          ] );
        add_filter( 'bulk_actions-edit-shop_order',            [ $this, 'bulk_actions'          ] );
        add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ $this, 'handle_bulk'    ], 20, 3 );
        add_filter( 'handle_bulk_actions-edit-shop_order',     [ $this, 'handle_bulk'           ], 20, 3 );
        add_action( 'admin_notices',                           [ $this, 'bulk_notice'           ] );
    }

    // ════════════════════════════════════════════════════════════════════
    // STATUS REGISTRATION
    // ════════════════════════════════════════════════════════════════════

    public function register_statuses() {
        foreach ( self::STATUSES as $slug => $data ) {
            if ( 'custom' !== $data['type'] ) continue;

            $wc_key = 'wc-' . $slug;
            global $wp_post_statuses;
            if ( isset( $wp_post_statuses[ $wc_key ] ) ) continue;

            register_post_status( $wc_key, [
                'label'                     => $data['label'],
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop(
                    $data['label'] . ' <span class="count">(%s)</span>',
                    $data['label'] . ' <span class="count">(%s)</span>'
                ),
                'description'               => $data['description'] ?? $data['label'],
            ] );
        }
    }

    public function add_to_order_statuses( $statuses ) {
        $ordered = [];
        foreach ( self::STATUSES as $slug => $data ) {
            $key             = 'wc-' . $slug;
            $ordered[ $key ] = $statuses[ $key ] ?? $data['label'];
        }
        foreach ( $statuses as $key => $label ) {
            if ( ! isset( $ordered[ $key ] ) ) {
                $ordered[ $key ] = $label;
            }
        }
        return $ordered;
    }

    public function declare_paid_statuses( $statuses ) {
        foreach ( self::STATUSES as $slug => $data ) {
            if ( ! empty( $data['paid'] ) ) {
                $statuses[] = $slug;
            }
        }
        return array_unique( $statuses );
    }

    public function add_to_reports( $statuses ) {
        foreach ( self::STATUSES as $slug => $data ) {
            if ( 'custom' === $data['type'] ) {
                $statuses[] = $slug;
            }
        }
        return array_unique( $statuses );
    }

    // ════════════════════════════════════════════════════════════════════
    // ADMIN CSS — badges + action buttons in one pass
    // ════════════════════════════════════════════════════════════════════

    /**
     * Outputs all badge and action button CSS in a single <style> block.
     * Runs on admin_head so styles are present before the browser renders,
     * preventing the flash of WC default styles.
     *
     * Glyphs are stored on each status in STATUSES['glyph'] — single source
     * of truth, shared by both badge and button rules.
     */
    public function admin_css() {
        global $pagenow;
        $is_orders = ( $pagenow === 'edit.php'  && isset( $_GET['post_type'] ) && $_GET['post_type'] === 'shop_order' )
                  || ( $pagenow === 'admin.php' && isset( $_GET['page'] )     && $_GET['page']      === 'wc-orders'   );
        if ( ! $is_orders ) return;

        // ── Badge base styles ──────────────────────────────────────────
        $css = '
.widefat .column-order_status .order-status,
.woocommerce_page_wc-orders .order-status {
    position: relative !important;
    padding: 0 !important;
    text-indent: -9999px !important;
    background: transparent !important;
    border: 0 !important;
    font-size: 2em !important;
    line-height: 1 !important;
    vertical-align: text-top !important;
    display: inline-block !important;
}
.widefat .column-order_status .order-status::after,
.woocommerce_page_wc-orders .order-status::after {
    font-family: "WooCommerce" !important;
    speak: none !important;
    font-weight: normal !important;
    font-variant: normal !important;
    text-transform: none !important;
    -webkit-font-smoothing: antialiased !important;
    margin: 0 !important;
    text-indent: 0 !important;
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
    text-align: center !important;
    width: 100% !important;
}
';
        // ── Per-status rules (badge + action button in one loop) ───────
        foreach ( self::STATUSES as $slug => $data ) {
            $bg    = sanitize_hex_color( $data['color_bg'] );
            $text  = sanitize_hex_color( $data['color_text'] );
            $glyph = $data['glyph'];
            $s     = esc_attr( $slug );

            if ( ! $bg || ! $text ) continue;

            // Badge colour + glyph — use $bg as icon color (background is transparent, icon matches the status colour)
            $css .= sprintf(
                '.order-status.status-%1$s { color:%2$s !important; background-color:transparent !important; border:0 !important; }' . "\n" .
                '.order-status.status-%1$s::after { content:"\\%3$s" !important; color:%2$s !important; }' . "\n",
                $s, $bg, $glyph
            );

            // Action button colour + glyph (font-family needed for custom statuses WC doesn't know)
            $css .= sprintf(
                '.wc-action-button-%1$s { background-color:%2$s !important; color:%3$s !important; border-color:%3$s !important; }' . "\n" .
                '.wc-action-button-%1$s::after { font-family:"WooCommerce" !important; content:"\\%4$s" !important; color:%3$s !important; font-weight:normal !important; speak:none !important; -webkit-font-smoothing:antialiased !important; }' . "\n",
                $s, $bg, $text, $glyph
            );
        }

        echo '<style id="cf-admin-css">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput

        // Tooltips: WC only adds data-tip for order note count, not status description.
        // Confirmed from WC source — no PHP hook exists. JS on DOMContentLoaded is the only way.
        $tips = [];
        foreach ( self::STATUSES as $slug => $data ) {
            if ( ! empty( $data['description'] ) ) {
                $tips[ 'status-' . $slug ] = $data['description'];
            }
        }
        if ( ! empty( $tips ) ) {
            echo '<script id="cf-status-tips">document.addEventListener("DOMContentLoaded",function(){';
            echo 'var t=' . wp_json_encode( $tips ) . ';';
            echo 'Object.keys(t).forEach(function(c){document.querySelectorAll(".order-status."+c).forEach(function(e){e.setAttribute("data-tip",t[c]);e.classList.add("tips");});});';
            echo '});</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    // ════════════════════════════════════════════════════════════════════
    // ACTION BUTTONS
    // ════════════════════════════════════════════════════════════════════

    public function action_buttons( $actions, $order ) {
        $current = $order->get_status();
        if ( ! isset( self::STATUSES[ $current ] ) ) return $actions;

        $allowed = self::STATUSES[ $current ]['next'];
        if ( empty( $allowed ) ) return [];

        $all_statuses = wc_get_order_statuses();
        $new_actions  = [];

        foreach ( $allowed as $next ) {
            $label = $all_statuses[ 'wc-' . $next ] ?? ucfirst( str_replace( '-', ' ', $next ) );

            $new_actions[ $next ] = [
                'url'    => wp_nonce_url(
                    admin_url( 'admin-ajax.php?action=woocommerce_mark_order_status&status=' . $next . '&order_id=' . $order->get_id() ),
                    'woocommerce-mark-order-status'
                ),
                'name'   => $label,
                'action' => $next,
            ];
        }

        return $new_actions;
    }

    // ════════════════════════════════════════════════════════════════════
    // BULK ACTIONS
    // ════════════════════════════════════════════════════════════════════

    public function bulk_actions( $actions ) {
        $custom = [];
        foreach ( self::STATUSES as $slug => $data ) {
            if ( 'custom' === $data['type'] ) {
                $custom[ 'mark_' . $slug ] = sprintf( 'Change status to %s', $data['label'] );
            }
        }
        $pos = array_search( 'mark_cancelled', array_keys( $actions ), true );
        if ( $pos !== false ) {
            return array_merge(
                array_slice( $actions, 0, $pos + 1, true ),
                $custom,
                array_slice( $actions, $pos + 1, null, true )
            );
        }
        return array_merge( $actions, $custom );
    }

    public function handle_bulk( $redirect, $action, $ids ) {
        $matched = null;
        foreach ( self::STATUSES as $slug => $data ) {
            if ( 'custom' === $data['type'] && 'mark_' . $slug === $action ) {
                $matched = $slug;
                break;
            }
        }
        if ( ! $matched ) return $redirect;

        $changed = 0;
        foreach ( $ids as $id ) {
            $order = wc_get_order( absint( $id ) );
            if ( $order ) {
                $order->update_status( $matched, '[CashFlow] Bulk action.' );
                $changed++;
            }
        }
        return add_query_arg( [ 'cf_bulk' => $matched, 'changed' => $changed ], $redirect );
    }

    public function bulk_notice() {
        if ( isset( $_REQUEST['cf_bulk'], $_REQUEST['changed'] ) && absint( $_REQUEST['changed'] ) > 0 ) {
            $n = absint( $_REQUEST['changed'] );
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html( sprintf( _n( '%d order status updated.', '%d order statuses updated.', $n ), $n ) )
            );
        }
    }

    // ════════════════════════════════════════════════════════════════════
    // CASHFLOW SYNC HELPERS
    // ════════════════════════════════════════════════════════════════════

    /**
     * Normalize any status string to a clean slug.
     *
     * 'wc-booked', 'booked', 'on_hold', 'wc-on-hold' → 'booked', 'on-hold'
     *
     * Use everywhere: $order->update_status(), comparisons, CashFlow API payloads.
     */
    public static function normalize( $status ) {
        $s = strtolower( trim( $status ) );
        $s = preg_replace( '/^wc-/', '', $s );
        $s = str_replace( '_', '-', $s );
        return $s;
    }

    /**
     * Return the full status registry for the CashFlow frontend to consume.
     */
    public static function get_all_statuses() {
        $result = [];
        foreach ( self::STATUSES as $slug => $data ) {
            $result[] = [
                'slug'       => $slug,
                'wc_key'     => 'wc-' . $slug,
                'label'      => $data['label'],
                'type'       => $data['type'],
                'color_bg'   => $data['color_bg'],
                'color_text' => $data['color_text'],
                'glyph'      => $data['glyph'],
                'paid'       => $data['paid'],
                'next'       => $data['next'],
            ];
        }
        return $result;
    }

    /**
     * Return only custom (non-core) statuses, keyed by slug.
     */
    public static function get_custom_statuses() {
        return array_filter( self::STATUSES, fn( $d ) => 'custom' === $d['type'] );
    }

    /**
     * Return allowed next-status transitions.
     *
     * @param  string|null $slug  Specific slug, or null for the full map.
     */
    public static function get_transitions( $slug = null ) {
        if ( $slug !== null ) {
            $slug = preg_replace( '/^wc-/', '', strtolower( trim( $slug ) ) );
            return self::STATUSES[ $slug ]['next'] ?? [];
        }
        $map = [];
        foreach ( self::STATUSES as $s => $data ) {
            $map[ $s ] = $data['next'];
        }
        return $map;
    }
}