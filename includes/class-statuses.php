<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Statuses
 *
 * Registers all WooCommerce order statuses required by CashFlow.pk.
 * Status set mirrors the INMX Order Flow defaults exactly — this class
 * is the authoritative source now that INMX has been removed.
 *
 * Responsibilities:
 *  - Register custom statuses with WordPress/WooCommerce
 *  - Apply badge colours + Dashicons in WC admin
 *  - Enforce paid-status declarations
 *  - Action buttons per row (flow-aware, colored, Dashicon)
 *  - Restrict status dropdown on single order edit page
 *  - JS enforcement on orders list inline dropdowns
 *  - Bulk actions for custom statuses
 *  - Server-side hard block on invalid transitions
 *  - Provide bi-directional mapping helpers for the CashFlow sync layer
 */
class CashFlow_Statuses {

    /**
     * Full status registry — mirrors inmx_cos_defaults() exactly.
     *
     * Keys:
     *   label       Human-readable label shown in WC admin
     *   type        'core' (built-in WC) | 'custom' (must be registered)
     *   color_bg    Badge background hex
     *   color_text  Badge text/border hex
     *   icon        Dashicons class for the badge icon and action button
     *   paid        Whether WC should treat this status as paid
     *   next        Allowed transition targets (slugs, no wc- prefix)
     */
    const STATUSES = [
        'pending' => [
            'label'      => 'Pending payment',
            'type'       => 'core',
            'color_bg'   => '#e5e5e5',
            'color_text' => '#777777',
            'icon'       => 'dashicons-clock',
            'paid'       => false,
            'next'       => [ 'processing', 'on-hold', 'cancelled' ],
        ],
        'on-hold' => [
            'label'      => 'On hold',
            'type'       => 'core',
            'color_bg'   => '#f8dda7',
            'color_text' => '#94660c',
            'icon'       => 'dashicons-warning',
            'paid'       => false,
            'next'       => [ 'processing', 'cancelled' ],
        ],
        'processing' => [
            'label'      => 'Processing',
            'type'       => 'core',
            'color_bg'   => '#c6e1c6',
            'color_text' => '#5b841b',
            'icon'       => 'dashicons-update',
            'paid'       => true,
            'next'       => [ 'booked', 'cancelled' ],
        ],
        'booked' => [
            'label'      => 'Booked',
            'type'       => 'custom',
            'color_bg'   => '#c8d8f0',
            'color_text' => '#1a4a8a',
            'icon'       => 'dashicons-clipboard',
            'paid'       => true,
            'next'       => [ 'shipped', 'cancelled' ],
        ],
        'shipped' => [
            'label'      => 'Shipped',
            'type'       => 'custom',
            'color_bg'   => '#fde8c8',
            'color_text' => '#8a4a00',
            'icon'       => 'dashicons-car',
            'paid'       => true,
            'next'       => [ 'completed', 'failed', 'returned' ],
        ],
        'failed' => [
            'label'      => 'Failed',
            'type'       => 'core',
            'color_bg'   => '#eba3a3',
            'color_text' => '#761919',
            'icon'       => 'dashicons-no-alt',
            'paid'       => false,
            'next'       => [ 'shipped', 'returned' ],
        ],
        'returned' => [
            'label'      => 'Returned',
            'type'       => 'custom',
            'color_bg'   => '#e8d0f0',
            'color_text' => '#5a1a8a',
            'icon'       => 'dashicons-undo',
            'paid'       => true,
            'next'       => [ 'pending', 'refunded' ],
        ],
        'completed' => [
            'label'      => 'Completed',
            'type'       => 'core',
            'color_bg'   => '#c8d7e1',
            'color_text' => '#2e4453',
            'icon'       => 'dashicons-yes-alt',
            'paid'       => true,
            'next'       => [ 'refunded' ],
        ],
        'refunded' => [
            'label'      => 'Refunded',
            'type'       => 'core',
            'color_bg'   => '#e5e5e5',
            'color_text' => '#777777',
            'icon'       => 'dashicons-money-alt',
            'paid'       => false,
            'next'       => [],
        ],
        'cancelled' => [
            'label'      => 'Cancelled',
            'type'       => 'core',
            'color_bg'   => '#eba3a3',
            'color_text' => '#761919',
            'icon'       => 'dashicons-dismiss',
            'paid'       => false,
            'next'       => [],
        ],
        'checkout-draft' => [
            'label'      => 'Draft',
            'type'       => 'core',
            'color_bg'   => '#e5e5e5',
            'color_text' => '#777777',
            'icon'       => 'dashicons-edit',
            'paid'       => false,
            'next'       => [ 'pending' ],
        ],
    ];

    /**
     * Dashicons class → CSS unicode codepoint.
     * Covers all icons used in STATUSES above plus a fallback.
     */
    const ICON_MAP = [
        'dashicons-clock'      => 'f469',
        'dashicons-warning'    => 'f534',
        'dashicons-update'     => 'f463',
        'dashicons-clipboard'  => 'f481',
        'dashicons-car'        => 'f513',
        'dashicons-no-alt'     => 'f335',
        'dashicons-undo'       => 'f171',
        'dashicons-yes-alt'    => 'f472',
        'dashicons-money-alt'  => 'f507',
        'dashicons-dismiss'    => 'f153',
        'dashicons-edit'       => 'f464',
        'dashicons-marker'     => 'f231', // fallback
    ];

    // ── Boot ────────────────────────────────────────────────────────────
    public function __construct() {
        // Status registration
        add_action( 'init',                                 [ $this, 'register_statuses'      ] );
        add_filter( 'wc_order_statuses',                    [ $this, 'add_to_order_statuses'  ], 20 );
        add_filter( 'woocommerce_order_is_paid_statuses',   [ $this, 'declare_paid_statuses'  ] );
        add_filter( 'woocommerce_reports_order_statuses',   [ $this, 'add_to_reports'         ] );
        add_filter( 'woocommerce_analytics_order_statuses', [ $this, 'add_to_reports'         ] );

        // Admin UI
        add_action( 'admin_enqueue_scripts',                [ $this, 'enqueue_dashicons'      ] );
        add_action( 'admin_footer',                         [ $this, 'badge_colours'          ] );
        add_action( 'admin_footer',                         [ $this, 'action_button_css'      ] );

        // Action buttons — flow-restricted per row
        add_filter( 'woocommerce_admin_order_actions',      [ $this, 'action_buttons'         ], 10, 2 );

        // Bulk actions
        add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ $this, 'bulk_actions'        ] );
        add_filter( 'bulk_actions-edit-shop_order',            [ $this, 'bulk_actions'        ] );
        add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ $this, 'handle_bulk'  ], 20, 3 );
        add_filter( 'handle_bulk_actions-edit-shop_order',            [ $this, 'handle_bulk'  ], 20, 3 );
        add_action( 'admin_notices',                        [ $this, 'bulk_notice'            ] );
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
            ] );
        }
    }

    public function add_to_order_statuses( $statuses ) {
        $ordered = [];
        foreach ( self::STATUSES as $slug => $data ) {
            $key            = 'wc-' . $slug;
            $ordered[ $key ] = isset( $statuses[ $key ] ) ? $statuses[ $key ] : $data['label'];
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
    // ADMIN UI — BADGE COLOURS
    // ════════════════════════════════════════════════════════════════════

    public function enqueue_dashicons() {
        $screen = get_current_screen();
        if ( $screen && in_array( $screen->id, [ 'edit-shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
            wp_enqueue_style( 'dashicons' );
        }
    }

    public function badge_colours() {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, [ 'edit-shop_order', 'woocommerce_page_wc-orders' ], true ) ) return;

        $css = '
mark.order-status,span.order-status{
    display:inline-flex !important;
    align-items:center !important;
    gap:4px !important;
}
mark.order-status::before,span.order-status::before{
    font-family:Dashicons !important;
    speak:never;
    font-style:normal !important;
    font-weight:400 !important;
    font-variant:normal !important;
    text-transform:none !important;
    line-height:1 !important;
    -webkit-font-smoothing:antialiased;
    -moz-osx-font-smoothing:grayscale;
    font-size:12px !important;
    width:12px;
    height:12px;
    display:inline-flex !important;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
}
';
        foreach ( self::STATUSES as $slug => $data ) {
            $bg   = sanitize_hex_color( $data['color_bg'] );
            $text = sanitize_hex_color( $data['color_text'] );
            $icon = $data['icon'] ?? 'dashicons-marker';
            $hex  = self::ICON_MAP[ $icon ] ?? self::ICON_MAP['dashicons-marker'];
            if ( ! $bg || ! $text ) continue;

            $s    = esc_attr( $slug );
            $css .= sprintf(
                'mark.order-status.status-%1$s,span.order-status.status-%1$s,.order-status.status-%1$s{background-color:%2$s !important;color:%3$s !important;}' . "\n",
                $s, $bg, $text
            );
            $css .= sprintf(
                'mark.order-status.status-%1$s::before,span.order-status.status-%1$s::before{content:"\\%2$s";color:%3$s !important;}' . "\n",
                $s, $hex, $text
            );
        }

        echo '<style id="cf-badge-colours">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    // ════════════════════════════════════════════════════════════════════
    // FLOW ENFORCEMENT — ACTION BUTTONS (orders list row)
    // ════════════════════════════════════════════════════════════════════

    /**
     * Replace the default WC action buttons with flow-aware next-step buttons.
     * Each button is coloured and icon-matched to the target status.
     */
    public function action_buttons( $actions, $order ) {
        $current = $order->get_status();
        if ( ! isset( self::STATUSES[ $current ] ) ) return $actions;

        $allowed = self::STATUSES[ $current ]['next'];
        if ( empty( $allowed ) ) return [];

        $all_statuses = wc_get_order_statuses();
        $new_actions  = [];

        foreach ( $allowed as $next ) {
            $label = isset( $all_statuses[ 'wc-' . $next ] )
                ? $all_statuses[ 'wc-' . $next ]
                : ucfirst( str_replace( '-', ' ', $next ) );

            $new_actions[ $next ] = [
                'url'    => wp_nonce_url(
                    admin_url( 'admin-ajax.php?action=woocommerce_mark_order_status&status=' . $next . '&order_id=' . $order->get_id() ),
                    'woocommerce-mark-order-status'
                ),
                'name'   => $label,
                'action' => 'cf-next-' . $next,
            ];
        }

        return $new_actions;
    }

    /**
     * CSS for the action buttons: size, Dashicon glyph, per-status colour.
     * Hooked to admin_footer so it always overrides other plugin styles.
     */
    public function action_button_css() {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, [ 'edit-shop_order', 'woocommerce_page_wc-orders' ], true ) ) return;

        $css = '
.widefat .column-wc_actions a.button[class*="cf-next-"] {
    width: 28px !important;
    height: 28px !important;
    border-radius: 3px;
    border-width: 1px;
    border-style: solid;
    margin: 1px;
    padding: 0;
    color: transparent !important;
    position: relative;
    overflow: visible;
}
.widefat .column-wc_actions a.button[class*="cf-next-"]::after {
    font-family: Dashicons !important;
    speak: never;
    font-style: normal;
    font-weight: 400 !important;
    font-variant: normal;
    text-transform: none;
    line-height: 1;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    font-size: 16px !important;
    margin: 0;
    text-indent: 0;
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    display: flex !important;
    align-items: center;
    justify-content: center;
}
';
        foreach ( self::STATUSES as $slug => $data ) {
            $bg   = sanitize_hex_color( $data['color_bg'] );
            $text = sanitize_hex_color( $data['color_text'] );
            $icon = $data['icon'] ?? 'dashicons-marker';
            $hex  = self::ICON_MAP[ $icon ] ?? self::ICON_MAP['dashicons-marker'];
            if ( ! $bg || ! $text ) continue;

            $s    = esc_attr( $slug );
            $css .= sprintf(
                '.widefat .column-wc_actions a.button.cf-next-%1$s { background: %2$s !important; color: %3$s !important; border-color: %3$s !important; }' . "\n" .
                '.widefat .column-wc_actions a.button.cf-next-%1$s::after { content: "\\%4$s"; color: %3$s !important; }' . "\n",
                $s, $bg, $text, $hex
            );
        }

        echo '<style id="cf-action-btn-css">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
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
     * Handles all variants CashFlow or WooCommerce might send:
     *   'wc-booked', 'booked', 'on_hold', 'wc-on-hold' → 'booked', 'on-hold'
     *
     * Use this everywhere: $order->update_status(), get_status() comparisons,
     * and anything sent to or received from the CashFlow API.
     *
     * @param  string $status  Any status string.
     * @return string          Clean slug, e.g. 'booked', 'on-hold'.
     */
    public static function normalize( $status ) {
        $s = strtolower( trim( $status ) );
        $s = preg_replace( '/^wc-/', '', $s ); // strip wc- prefix if present
        $s = str_replace( '_', '-', $s );      // on_hold → on-hold
        return $s;
    }

    /**
     * Return the full status registry for the CashFlow frontend to consume.
     *
     * @return array[]
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
                'icon'       => $data['icon'],
                'paid'       => $data['paid'],
                'next'       => $data['next'],
            ];
        }
        return $result;
    }

    /**
     * Return only the custom (non-core) statuses, keyed by slug.
     *
     * @return array[]
     */
    public static function get_custom_statuses() {
        return array_filter( self::STATUSES, fn( $d ) => 'custom' === $d['type'] );
    }

    /**
     * Return the allowed next-status transitions.
     *
     * @param  string|null $slug  Pass a slug to get transitions for one status,
     *                            or null to get the full map.
     * @return string[]|array[]
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