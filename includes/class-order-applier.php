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

    /**
     * Create a NEW order from a CashFlow-composed body.
     *
     * 🔴 THIS METHOD DID NOT EXIST AND THE WHOLE CREATE PATH CALLED IT
     * (live-readiness review, 2026-08-07). class-sync-pull.php's apply_create
     * called $applier->create( $body ) against a class declaring only apply()
     * and serialize(). Every create would have thrown "Call to undefined
     * method", acked `failed`, burnt six attempts and parked red — the order
     * would have stayed in CashFlow forever and never reached WooCommerce.
     *
     * The plugin suite was green throughout because tests/bootstrap.php
     * declared a standalone REPLACEMENT class of the same name rather than a
     * subclass, and get_applier() only requires the real file when the class
     * does not already exist — so the real applier was never loaded under test.
     * The harness now EXTENDS this class, so a missing method fails loudly.
     *
     * Deliberately mirrors apply() rather than reusing it: prepare_object_for_
     * database( $request, TRUE ) is the creating form, totals are recalculated
     * unconditionally (a new order has none to preserve — the "money moved by
     * an edit that never mentioned money" hazard apply() guards against cannot
     * arise), and the sync key is stamped BEFORE the save so a redelivery can
     * find our own earlier work. That last point is the create path's ONLY
     * idempotence: without the key on the order, a lost ack creates a SECOND
     * real order for the same job.
     *
     * @param array $body REST v3 order shape (line_items, billing, meta_data…).
     * @return WC_Order|WP_Error
     */
    public function create( array $body ) {
        try {
            $request = new WP_REST_Request( 'POST', '/wc/v3/orders' );
            foreach ( $body as $key => $value ) {
                $request->set_param( $key, $value );
            }

            $order = $this->prepare_object_for_database( $request, true );
            if ( is_wp_error( $order ) ) {
                return $order;
            }

            // Core parity (save_object): gateways loaded so gateway hooks fire.
            if ( function_exists( 'WC' ) && is_callable( [ WC(), 'payment_gateways' ] ) ) {
                WC()->payment_gateways();
            }

            $order->calculate_totals( true );

            // Coupons AFTER totals and BEFORE the save, same ordering and same
            // self-gating as apply(). calculate_coupons THROWS WC_REST_Exception
            // on an invalid coupon; is_wp_error kept as belt for future WC.
            if ( null !== $request->get_param( 'coupon_lines' ) ) {
                $coupons = $this->calculate_coupons( $request, $order );
                if ( is_wp_error( $coupons ) ) {
                    return $coupons;
                }
            }

            $order->save();

            // set_paid runs AFTER the save so payment_complete() acts on a real,
            // persisted order — core's own ordering in create_item.
            if ( ! empty( $body['set_paid'] ) ) {
                $order->payment_complete();
            }

            // Re-read for the same reason apply() does: the legacy data store
            // writes post_modified without refreshing the in-memory object, and
            // the ack's date_modified_gmt becomes the next job's base.
            $saved = wc_get_order( $order->get_id() );
            return $saved instanceof WC_Order ? $saved : $order;
        } catch ( WC_REST_Exception $e ) {
            // Must precede WC_Data_Exception — it extends it in core.
            return new WP_Error( $e->getErrorCode(), $e->getMessage(), [ 'status' => $e->getCode() ] );
        } catch ( WC_Data_Exception $e ) {
            return new WP_Error( $e->getErrorCode(), $e->getMessage(), $e->getErrorData() );
        }
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
