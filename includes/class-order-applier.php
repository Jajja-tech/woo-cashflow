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
 * Signature notes (no WC source on the dev machine — verified against
 * WooCommerce trunk conventions, current as of WC 8.x/9.x):
 *   - WC_REST_Orders_V2_Controller::prepare_object_for_database( $request, $creating = false )
 *     protected; reads $request['id'], returns WC_Order|WP_Error.
 *   - WC_REST_Orders_V2_Controller::calculate_coupons( $request, $order )
 *     protected; returns bool|WP_Error; REJECTS coupon lines carrying an
 *     id ("Coupon item ID is readonly.") and applies desired-end-state
 *     natively (removes all coupons, re-applies the listed codes).
 *   - WP_REST_Controller::prepare_object_for_response( $object, $request )
 *     public (overridden in the v2/v3 orders controllers).
 * Core's own save path (save_object) catches WC_Data_Exception /
 * WC_REST_Exception thrown from prepare_object_for_database's set_item —
 * we bypass save_object, so apply() mirrors those catches itself.
 */
class CashFlow_Order_Applier extends WC_REST_Orders_Controller {

    /**
     * Apply the prepared request to the order and stamp the sync key.
     *
     * The sync-key meta rides the SAME $order->save() as the economics —
     * the atomicity the backend's idempotence verdict relies on: if the
     * save happened, the key is on the order; if it didn't, neither is.
     * (Caveat, inherited from core: calculate_coupons applies coupons via
     * $order->apply_coupon, which performs its own internal save — for
     * coupon-carrying jobs the single-save guarantee is core's fragmented
     * one, not ours to fix from here.)
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

            $order->update_meta_data( 'cashflow_sync_key', (string) $sync_key );

            // Coupons only when the request carries coupon_lines — mirrors
            // core's save_object, which gates on isset( $request['coupon_lines'] ).
            if ( null !== $request->get_param( 'coupon_lines' ) ) {
                $coupons = $this->calculate_coupons( $request, $order );
                if ( is_wp_error( $coupons ) ) {
                    return $coupons;
                }
            }

            $order->calculate_totals();
            $order->save();

            return $order;
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
