<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Order_Applier
 *
 * A thin subclass of WooCommerce's own v3 orders controller so a queued
 * CashFlow edit is applied by EXACTLY the code an HTTP PUT
 * /wc/v3/orders/{id} runs — set_item's id-vs-add semantics, quantity-0
 * removal, {key: null} line deletion, coupon recalculation, totals — with
 * zero hand-rolled money math in this plugin.
 *
 * THIS FILE IS LOADED LAZILY (CashFlow_Sync_Pull::get_applier), never in
 * the boot require loop: the `extends` below is resolved when the file is
 * parsed, so WC_REST_Orders_Controller must already be loadable. Do NOT
 * add this file to CashFlow_Plugin's $files list.
 *
 * Signature notes (no WC source lives on the dev machine — every claim
 * below was verified against WooCommerce trunk source fetched from the
 * official repo during review, 2026-08-06):
 *   - WC_REST_Orders_Controller (v3) overrides prepare_object_for_database
 *     ( $request, $creating = false ): protected; reads $request['id'],
 *     `new WC_Order( $id )`; line removal = item_is_null (any of
 *     product_id/method_id/method_title/name/code explicitly null) OR
 *     quantity === 0 (strict int), via its typed remove_item() which
 *     throws woocommerce_rest_invalid_item_id for a stale id.
 *   - WC_REST_Orders_Controller::calculate_coupons( $request, $order ):
 *     protected; returns false when coupon_lines absent, true on success,
 *     and THROWS WC_REST_Exception on any invalid coupon or a coupon line
 *     carrying an id ("Coupon item ID is readonly."). Desired-end-state
 *     natively: removes all coupons, re-applies the listed codes.
 *   - WC_REST_Orders_V2_Controller::prepare_object_for_response
 *     ( $object, $request ): public; tolerates a bare WP_REST_Request
 *     (dp defaults to wc_get_price_decimals, context to 'view').
 *   - Core's save_object gates calculate_totals( true ) on
 *     isset(billing|shipping|line_items|shipping_lines|fee_lines|coupon_lines)
 *     — it is NOT unconditional; apply() mirrors that gate exactly.
 * Core's own save path (save_object) catches WC_Data_Exception /
 * WC_REST_Exception thrown from prepare_object_for_database's set_item —
 * we bypass save_object, so apply() mirrors those catches itself.
 */
class CashFlow_Order_Applier extends WC_REST_Orders_Controller {

    /**
     * Apply the prepared request to the order and stamp the sync key.
     *
     * The sync-key meta rides the SAME final $order->save() as the
     * economics — the atomicity the backend's idempotence verdict relies
     * on: if the save happened, the key is on the order; if it didn't,
     * neither is.
     *
     * Coupon-carrying jobs are the one exception, and the stamp is placed
     * AFTER calculate_coupons because of it: remove_coupon/apply_coupon
     * SAVE the order internally (core's own fragmentation, verified in
     * trunk), and any save persists ALL staged changes — items and meta
     * alike. Stamping first would let a mid-coupon failure persist the
     * key against a HALF-applied edit, and the redelivery would then ack
     * `already_applied` over partial state: a silent false success. With
     * the key stamped after, that same failure leaves the key absent
     * while the partial save has moved date_modified — so the redelivery
     * surfaces as a LOUD `conflict` a human resolves. Same partial-state
     * exposure as a real REST PUT; the failure mode is just honest.
     *
     * @param WP_REST_Request $request  PUT-shaped request with body params.
     * @param string          $sync_key The job's idempotence key.
     * @return WC_Order|WP_Error
     */
    public function apply( WP_REST_Request $request, $sync_key ) {
        try {
            $order = $this->prepare_object_for_database( $request, false );
            if ( is_wp_error( $order ) ) {
                return $order;
            }

            // Core parity (save_object): gateways loaded so gateway hooks
            // fire on save.
            if ( function_exists( 'WC' ) && is_callable( [ WC(), 'payment_gateways' ] ) ) {
                WC()->payment_gateways();
            }

            // Core parity: totals recalculate ONLY when the request touched
            // lines or addresses — save_object's exact isset() gate. An
            // unconditional recalc would let a paymentMethod-only job
            // rewrite totals and re-run taxes (current rates, current
            // settings) on an order whose totals were manually set — money
            // moved by an edit that never mentioned money.
            if ( isset( $request['billing'] ) || isset( $request['shipping'] )
                || isset( $request['line_items'] ) || isset( $request['shipping_lines'] )
                || isset( $request['fee_lines'] ) || isset( $request['coupon_lines'] ) ) {
                $order->calculate_totals( true );
            }

            // Coupons only when the request carries coupon_lines — v3's
            // calculate_coupons self-gates the same way in core's save_object.
            // It THROWS WC_REST_Exception on any invalid coupon (caught
            // below); is_wp_error kept as belt for future WC versions.
            if ( null !== $request->get_param( 'coupon_lines' ) ) {
                $coupons = $this->calculate_coupons( $request, $order );
                if ( is_wp_error( $coupons ) ) {
                    return $coupons;
                }
            }

            // 🔴 STATUS — the one field prepare_object_for_database NEVER
            // applies. Both the V2 and V3 controllers carry the same comment
            // ("Status change should be done later so transitions have new
            // data") and core sets it in save_object(), which this class
            // deliberately bypasses. So a status in the request silently
            // evaporated: the order saved with its old status, the ack
            // reported that old status, and the backend wrote it back over the
            // operator's confirmation. Live 2026-08-06→07: 40 reverts, 35
            // orders, every job acked `applied` and nothing logged anywhere.
            //
            // Set here — after coupons, before the save — which is exactly
            // where core puts it, so the transition hooks see the new data and
            // the status persists on the SAME save as the economics.
            if ( ! empty( $request['status'] ) ) {
                $order->set_status( (string) $request['status'] );
            }

            // Stamped AFTER coupons, BEFORE the save — see the docblock.
            $order->update_meta_data( 'cashflow_sync_key', (string) $sync_key );

            $order->save();

            // Re-read, exactly like core's save_object returns
            // get_object(): the legacy (non-HPOS) data store writes a fresh
            // post_modified to the DB on save WITHOUT refreshing the
            // in-memory object (verified in trunk: only the HPOS store
            // calls set_date_modified during save). Serializing $order
            // directly would ack a STALE date_modified_gmt — and the
            // backend, basing the next job on it, would false-conflict
            // every subsequent edit on legacy-storage stores.
            $saved = wc_get_order( $order->get_id() );
            return $saved instanceof WC_Order ? $saved : $order;
        } catch ( WC_REST_Exception $e ) {
            // Must precede the WC_Data_Exception catch: WC_REST_Exception
            // EXTENDS WC_Data_Exception in core, so the general catch would
            // otherwise shadow this one.
            return new WP_Error( $e->getErrorCode(), $e->getMessage(), [ 'status' => $e->getCode() ] );
        } catch ( WC_Data_Exception $e ) {
            return new WP_Error( $e->getErrorCode(), $e->getMessage(), $e->getErrorData() );
        }
    }

    // ── Create (command order.create@1) ─────────────────────────────

    /** The ONLY keys order.create@1 may carry, at every level. */
    const CREATE_SCHEMA = [
        'command'       => [ 'command_id', 'kind', 'capability', 'idempotency_key', 'attempt', 'order' ],
        'order'         => [ 'order_number', 'status', 'currency', 'customer', 'address', 'customer_note',
                             'payment_method', 'line_items', 'shipping_lines', 'fee_lines', 'meta',
                             'money_display', 'expected_total' ],
        'customer'      => [ 'first_name', 'last_name', 'phone', 'email' ],
        'address'       => [ 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ],
        'line_items'    => [ 'product_id', 'variation_id', 'quantity', 'subtotal', 'total' ],
        'shipping_lines'=> [ 'method_id', 'method_title', 'total' ],
        'fee_lines'     => [ 'name', 'total' ],
        'money_display' => [ 'advance_amount', 'cod_amount', 'payment_status' ],
    ];

    /**
     * The ONLY meta keys the free `meta` map may write — an ALLOW-list.
     *
     * It was a deny-list of six keys the plugin writes itself, which let the
     * map write anything else on the order: `_date_paid`, `_transaction_id`,
     * `_billing_email`, `_order_total`, a gateway's private keys. The map
     * exists for two things only — WooCommerce's own order-attribution keys
     * and the preferred courier — so those are all it may name. The plugin's
     * own keys (cashflow_order_number, cashflow_command_key, the money
     * display) are therefore refused too, because they are not on the list.
     *
     * Attribution is matched on its prefix because WooCommerce defines a
     * family of them (_wc_order_attribution_source_type, _utm_source,
     * _utm_medium, _session_entry, …) and grows it between versions.
     */
    const CREATE_META_ALLOWED_PREFIX = '_wc_order_attribution_';
    const CREATE_META_ALLOWED_KEYS   = [ '_cashflow_courier_name' ];

    const META_ORDER_NUMBER = 'cashflow_order_number';
    const META_COMMAND_KEY  = 'cashflow_command_key';

    /**
     * Create the WooCommerce copy of an order CashFlow already owns.
     *
     * CashFlow has saved the order and minted its number (<prefix>-C<n>) before
     * this ever runs. This method only makes the store's copy, once, and says
     * truthfully what happened:
     *
     *   created          — made it now
     *   already_created  — a previous delivery made it (lost ack, lease expiry)
     *   rejected         — the store cannot accept THIS payload; retrying the
     *                      same payload can never succeed (unknown field,
     *                      missing product, invalid status, WooCommerce's own
     *                      validation) — the backend must not retry
     *   failed           — something transient went wrong; nothing was kept
     *
     * ONE TRANSACTION. The idempotency key and the order commit together: a
     * crash between "order exists" and "order carries the key" would make the
     * redelivery create a SECOND order, which is the exact failure this
     * command exists to prevent.
     *
     * @param array $command One entry of the poll's `commands`.
     * @return array { outcome, order?, error?: { code, message } }
     */
    public function create( array $command ) {
        $bad = self::validate_create_command( $command );
        if ( $bad ) {
            return self::create_result( 'rejected', null, $bad[0], $bad[1] );
        }
        $o   = $command['order'];
        $key = (string) $command['idempotency_key'];
        $num = (string) $o['order_number'];

        // (1) IDEMPOTENCY — the wall against a duplicate order. Looked up
        // across EVERY status INCLUDING TRASH: `'status' => 'any'` excludes
        // trash in both storages (OrdersTableQuery::process_status and
        // WP_Query drop exclude_from_search statuses), so a merchant trashing
        // the order before our ack landed would otherwise let the redelivery
        // create it again.
        $found = self::find_by_meta( self::META_COMMAND_KEY, $key );
        if ( $found ) {
            return self::create_result( 'already_created', $this->serialize( $found ) );
        }
        // The NUMBER is CashFlow's identity and must name one order here too.
        // A different key carrying the same number means a copy already exists
        // under an earlier command — creating another would put two WooCommerce
        // orders behind one CashFlow number.
        $clash = self::find_by_meta( self::META_ORDER_NUMBER, $num );
        if ( $clash ) {
            return self::create_result( 'rejected', null, 'order_number_in_use',
                sprintf( 'Order number %s is already on WooCommerce order #%d (created by a different command).', $num, $clash->get_id() ) );
        }

        // (2) PRODUCTS — checked by us, because WooCommerce does not.
        // Core's prepare_line_items does `$product = wc_get_product( $id )` and
        // then `if ( $product && … )`: an id that is not a product is NOT an
        // error, the line is simply created with no product behind it. The
        // order would take money for nothing that can be picked, packed or
        // restocked. So a missing, trashed or mismatched product is a refusal.
        foreach ( $o['line_items'] as $i => $li ) {
            $pid = (int) ( $li['product_id'] ?? 0 );
            $vid = (int) ( $li['variation_id'] ?? 0 );
            $use = $vid > 0 ? $vid : $pid;
            $product = $use > 0 ? wc_get_product( $use ) : false;
            if ( ! $product || 'trash' === $product->get_status() ) {
                return self::create_result( 'rejected', null, 'invalid_product',
                    sprintf( 'Line %d: product #%d does not exist in this store.', $i + 1, $use ) );
            }
            if ( $vid > 0 && $pid > 0 && (int) $product->get_parent_id() !== $pid ) {
                return self::create_result( 'rejected', null, 'invalid_product',
                    sprintf( 'Line %d: variation #%d does not belong to product #%d.', $i + 1, $vid, $pid ) );
            }
        }

        // (3) STATUS — checked by us, because WooCommerce does not refuse it
        // either: WC_Data::set_status turns an unregistered status into
        // `pending` without a word. A pending order is cancelled by the
        // unpaid-order cron and never reduces stock.
        $status = preg_replace( '/^wc-/', '', (string) $o['status'] );
        if ( ! array_key_exists( 'wc-' . $status, wc_get_order_statuses() ) ) {
            return self::create_result( 'rejected', null, 'invalid_status',
                sprintf( 'Status "%s" is not an order status on this store.', $status ) );
        }

        $request = new WP_REST_Request( 'POST', '/wc/v3/orders' );
        $request->set_body_params( self::create_body( $o ) );

        $started = false;
        try {
            wc_transaction_query( 'start' );
            $started = true;

            // Core's own creating form: new WC_Order(0), addresses via
            // update_address, lines via set_item, currency/customer/payment
            // via their setters. Status and coupons are skipped by core here.
            $order = $this->prepare_object_for_database( $request, true );
            if ( is_wp_error( $order ) ) {
                wc_transaction_query( 'rollback' );
                return self::create_result( 'rejected', null, $order->get_error_code(), $order->get_error_message() );
            }

            // Stamped BEFORE the first save, so no save of this order can ever
            // exist without its key — even on a host where transactions are
            // not honoured (MyISAM tables, WC_USE_TRANSACTIONS false).
            foreach ( (array) ( $o['meta'] ?? [] ) as $mk => $mv ) {
                $order->update_meta_data( (string) $mk, (string) $mv );
            }
            $md = (array) ( $o['money_display'] ?? [] );
            $order->update_meta_data( 'cashflow_advance_amount', (string) ( $md['advance_amount'] ?? '0' ) );
            $order->update_meta_data( 'cashflow_cod_amount', (string) ( $md['cod_amount'] ?? '0' ) );
            $order->update_meta_data( 'cashflow_payment_status', (string) ( $md['payment_status'] ?? '' ) );
            $order->update_meta_data( self::META_ORDER_NUMBER, $num );
            $order->update_meta_data( self::META_COMMAND_KEY, $key );

            // Core parity (save_object, $creating branch).
            if ( function_exists( 'WC' ) && is_callable( [ WC(), 'payment_gateways' ] ) ) {
                WC()->payment_gateways();
            }
            $order->set_created_via( 'cashflow' );
            $order->set_prices_include_tax( 'yes' === get_option( 'woocommerce_prices_include_tax' ) );
            $order->save();
            // A new order has no totals to preserve, so the recalculation is
            // unconditional — exactly core's creating branch. (calculate_totals
            // itself ends in a save; all of it sits inside the transaction.)
            $order->calculate_totals( true );

            // 🔴 STATUS BEFORE THE FINAL SAVE, never after. prepare skips it by
            // design; if nothing sets it the order stays `pending`, the unpaid
            // cron cancels it, and stock is not reduced. Set here, where core's
            // save_object sets it, so the transition hooks (stock, emails) see
            // the finished order.
            $order->set_status( $status );
            // set_paid is NEVER applied: CashFlow owns the money, and
            // payment_complete() would record a payment nobody made.
            $order->save();

            wc_transaction_query( 'commit' );
            $started = false;
        } catch ( WC_Data_Exception $e ) {
            // WooCommerce's own validation (WC_REST_Exception extends this):
            // an invalid email, currency, product reference. The same payload
            // can never succeed, so it is a refusal carrying WooCommerce's
            // own words — not a failure the backend would retry six times.
            if ( $started ) { wc_transaction_query( 'rollback' ); }
            return self::create_result( 'rejected', null, $e->getErrorCode(), $e->getMessage() );
        } catch ( Throwable $e ) {
            if ( $started ) { wc_transaction_query( 'rollback' ); }
            return self::create_result( 'failed', null, 'exception', $e->getMessage() );
        }

        // Re-read, as apply() does: the legacy data store does not refresh the
        // in-memory object's date_modified on save.
        $saved = wc_get_order( $order->get_id() );
        $saved = $saved instanceof WC_Order ? $saved : $order;

        // The ack carries the RE-READ order, so if another plugin's hook moved
        // the status on save, CashFlow is told the truth, not what was asked.
        return self::create_result( 'created', $this->serialize( $saved ) );
    }

    /**
     * The REST v3 body core's creating prepare consumes. The address goes to
     * billing AND shipping — CashFlow keeps ONE address per order. Guest order
     * (customer_id 0): CashFlow owns the customer, so no WP user is made.
     */
    private static function create_body( array $o ) {
        $c = (array) ( $o['customer'] ?? [] );
        $a = (array) ( $o['address'] ?? [] );
        $who = [
            'first_name' => (string) ( $c['first_name'] ?? '' ),
            'last_name'  => (string) ( $c['last_name'] ?? '' ),
            'phone'      => (string) ( $c['phone'] ?? '' ),
        ];
        $where = [];
        foreach ( self::CREATE_SCHEMA['address'] as $k ) {
            if ( isset( $a[ $k ] ) ) { $where[ $k ] = (string) $a[ $k ]; }
        }
        $billing = $who + $where;
        // An empty email is left out, not sent: set_billing_email('') is fine
        // in core, but a blank key reads as an assertion in every other part
        // of this contract.
        if ( ! empty( $c['email'] ) ) { $billing['email'] = (string) $c['email']; }

        $method = (string) ( $o['payment_method'] ?? '' );
        $body = [
            'currency'      => (string) ( $o['currency'] ?? '' ),
            'customer_id'   => 0,
            'billing'       => $billing,
            'shipping'      => $who + $where,
            'customer_note' => (string) ( $o['customer_note'] ?? '' ),
            'line_items'    => [],
            'shipping_lines'=> [],
            'fee_lines'     => [],
        ];
        if ( '' === $body['currency'] ) { unset( $body['currency'] ); }
        if ( '' !== $method ) {
            $body['payment_method']       = $method;
            $body['payment_method_title'] = class_exists( 'CashFlow_Sync_Pull' )
                ? CashFlow_Sync_Pull::payment_method_title( $method )
                : $method;
        }
        foreach ( $o['line_items'] as $li ) {
            $line = [ 'product_id' => (int) $li['product_id'], 'quantity' => (int) $li['quantity'] ];
            if ( ! empty( $li['variation_id'] ) ) { $line['variation_id'] = (int) $li['variation_id']; }
            // CashFlow's prices win: core first sets the catalogue price, then
            // maybe_set_item_props overwrites it with the posted total/subtotal.
            foreach ( [ 'subtotal', 'total' ] as $k ) {
                if ( isset( $li[ $k ] ) ) { $line[ $k ] = (string) $li[ $k ]; }
            }
            $body['line_items'][] = $line;
        }
        foreach ( (array) ( $o['shipping_lines'] ?? [] ) as $sl ) {
            $body['shipping_lines'][] = [
                'method_id'    => (string) ( $sl['method_id'] ?? '' ),
                'method_title' => (string) ( $sl['method_title'] ?? '' ),
                'total'        => (string) ( $sl['total'] ?? '0' ),
            ];
        }
        foreach ( (array) ( $o['fee_lines'] ?? [] ) as $fl ) {
            // Signed: a negative fee IS the discount (no coupons on created orders).
            $body['fee_lines'][] = [ 'name' => (string) ( $fl['name'] ?? '' ), 'total' => (string) ( $fl['total'] ?? '0' ) ];
        }
        return $body;
    }

    /**
     * Refuse anything outside order.create@1 — loudly, naming the path. A key
     * we do not understand is a field the operator set and would silently
     * lose; `coupon_codes` in particular is not in @1 (the Director removed
     * coupons from CashFlow-created orders), so it must not be half-honoured.
     *
     * @return array|null [ code, message ] or null when valid.
     */
    private static function validate_create_command( array $cmd ) {
        $unknown = function ( $arr, $allowed, $path ) {
            foreach ( array_keys( (array) $arr ) as $k ) {
                if ( ! in_array( $k, $allowed, true ) ) {
                    return [ 'unsupported_field', sprintf( '%s%s is not part of order.create@1.', $path, $k ) ];
                }
            }
            return null;
        };
        if ( $e = $unknown( $cmd, self::CREATE_SCHEMA['command'], '' ) ) { return $e; }
        if ( empty( $cmd['idempotency_key'] ) || ! is_string( $cmd['idempotency_key'] ) ) {
            return [ 'invalid_payload', 'idempotency_key is required.' ];
        }
        if ( ! isset( $cmd['order'] ) || ! is_array( $cmd['order'] ) ) {
            return [ 'invalid_payload', 'order is required.' ];
        }
        $o = $cmd['order'];
        if ( $e = $unknown( $o, self::CREATE_SCHEMA['order'], 'order.' ) ) { return $e; }
        foreach ( [ 'customer', 'address', 'money_display' ] as $obj ) {
            if ( isset( $o[ $obj ] ) ) {
                if ( ! is_array( $o[ $obj ] ) ) { return [ 'invalid_payload', "order.$obj must be an object." ]; }
                if ( $e = $unknown( $o[ $obj ], self::CREATE_SCHEMA[ $obj ], "order.$obj." ) ) { return $e; }
            }
        }
        foreach ( [ 'line_items', 'shipping_lines', 'fee_lines' ] as $list ) {
            if ( ! isset( $o[ $list ] ) ) { continue; }
            if ( ! is_array( $o[ $list ] ) ) { return [ 'invalid_payload', "order.$list must be a list." ]; }
            foreach ( $o[ $list ] as $i => $line ) {
                if ( ! is_array( $line ) ) { return [ 'invalid_payload', "order.{$list}[$i] must be an object." ]; }
                if ( $e = $unknown( $line, self::CREATE_SCHEMA[ $list ], "order.{$list}[$i]." ) ) { return $e; }
            }
        }
        if ( empty( $o['order_number'] ) || ! is_string( $o['order_number'] ) ) {
            return [ 'invalid_payload', 'order.order_number is required.' ];
        }
        if ( empty( $o['status'] ) || ! is_string( $o['status'] ) ) {
            return [ 'invalid_payload', 'order.status is required.' ];
        }
        if ( empty( $o['line_items'] ) ) {
            return [ 'invalid_payload', 'order.line_items must name at least one product.' ];
        }
        if ( isset( $o['meta'] ) ) {
            if ( ! is_array( $o['meta'] ) ) { return [ 'invalid_payload', 'order.meta must be an object.' ]; }
            foreach ( $o['meta'] as $mk => $mv ) {
                if ( ! self::create_meta_key_allowed( (string) $mk ) ) {
                    return [ 'unsupported_field', sprintf( 'order.meta.%s is not a meta key order.create@1 may write.', $mk ) ];
                }
                if ( ! is_scalar( $mv ) && null !== $mv ) {
                    return [ 'invalid_payload', sprintf( 'order.meta.%s must be a plain value.', $mk ) ];
                }
            }
        }
        return null;
    }

    /** Is $key on the order.create@1 meta allow-list? */
    private static function create_meta_key_allowed( $key ) {
        if ( in_array( $key, self::CREATE_META_ALLOWED_KEYS, true ) ) {
            return true;
        }
        // Prefix plus at least one more character, lowercase words only — the
        // bare prefix, or a key with spaces or capitals smuggled in after it,
        // is not a WooCommerce attribution field.
        return 1 === preg_match( '/^' . preg_quote( self::CREATE_META_ALLOWED_PREFIX, '/' ) . '[a-z0-9_]+$/', $key );
    }

    /** One order carrying this meta value, across every status incl. trash. */
    private static function find_by_meta( $key, $value ) {
        $found = wc_get_orders( [
            'meta_key'   => $key,
            'meta_value' => $value,
            'status'     => array_merge( array_keys( wc_get_order_statuses() ), [ 'trash' ] ),
            'limit'      => 1,
        ] );
        return ( ! empty( $found ) && $found[0] instanceof WC_Order ) ? $found[0] : null;
    }

    private static function create_result( $outcome, $order = null, $code = null, $message = null ) {
        $out = [ 'outcome' => $outcome ];
        if ( null !== $order ) { $out['order'] = $order; }
        if ( null !== $code )  { $out['error'] = [ 'code' => (string) $code, 'message' => (string) $message ]; }
        return $out;
    }

    /**
     * The full REST v3 order payload, exactly as core would return it —
     * the ack contract requires prepare_object_for_response's output
     * verbatim (the backend reads date_modified_gmt from it).
     *
     * @param WC_Order $order
     * @return array
     */
    public function serialize( $order ) {
        return $this->prepare_object_for_response( $order, new WP_REST_Request( 'GET', '/' ) )->get_data();
    }
}
