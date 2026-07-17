<?php
defined( 'ABSPATH' ) || exit;

/**
 * CashFlow_Advance — multi-payment advances on the WooCommerce order screens.
 *
 * ── ARCHITECTURE: CashFlow is the single WRITER *and* the single READER ──
 * Every add / edit / delete calls the CashFlow plugin API SYNCHRONOUSLY, from
 * PHP, with the v5 per-connection secret. Only after CashFlow confirms does
 * anything here change. There is no "write meta and wait for a sync" path, so
 * conflict rules, tombstones and sync races cannot exist by construction.
 *
 * The PANEL reads its rows LIVE from CashFlow every time it renders
 * (GET /plugin/orders/{id}/advance-payments) and keeps no list of its own.
 * Nothing here reconciles anything, because there is only ever one list.
 *
 * Order meta holds a DISPLAY CACHE — never truth, never read back to compute a
 * write:
 *   cashflow_advance_amount    CashFlow's advance total   (CashFlow pushes this too)
 *   cashflow_cod_amount        total − advance            (CashFlow pushes this too)
 *   cashflow_payment_status    unpaid|partial|paid        (CashFlow pushes this too)
 * It exists for the two surfaces that CANNOT call the API per render: the orders
 * LIST (one HTTP call per row is not an option) and the totals rows, which draw
 * before the panel does. When CashFlow pushes these it OVERWRITES ours — which
 * is correct: CashFlow wins (design D-1).
 *
 * A failed API call FAILS LOUDLY and changes nothing (Golden Rule #6). Money
 * recorded in WordPress that CashFlow never learned about is strictly worse than
 * a save that errors. A failed READ takes the controls down with it: with no
 * list there is no baseline, and a stale one would invite a shop manager to
 * "delete" a payment that is not there, or re-enter one that already is —
 * recording the same money twice.
 *
 * ── HPOS ────────────────────────────────────────────────────────────────
 * WC CRUD only: wc_get_order() / get_meta() / update_meta_data() / save().
 * No get_post_meta, no update_post_meta, no wp_posts, no wp_postmeta.
 *
 * ── SCOPE (design D-3 / D-4) ────────────────────────────────────────────
 * An entry means money RECEIVED — there is no per-entry paid/unpaid toggle,
 * and no COD toggle. COD collection lives in CashFlow ▸ Finance ▸ Courier COD.
 */
class CashFlow_Advance {

	const META_ADVANCE = 'cashflow_advance_amount';
	const META_COD     = 'cashflow_cod_amount';
	const META_STATUS  = 'cashflow_payment_status';

	const NONCE_ACTION = 'cashflow_advance';

	/** Cached accounts list, so a brief CashFlow outage does not blank the picker. */
	const ACCOUNTS_TRANSIENT = 'cashflow_finance_accounts';
	const ACCOUNTS_TTL       = 600;

	/**
	 * Single source of truth for payment-status presentation — one map, read by
	 * the totals row, the list column and the list filter. The test plugin
	 * duplicated this logic in two places and they had already drifted apart.
	 */
	private static $statuses = [
		'paid'    => [ 'label' => 'Paid',    'class' => 'cf-pay-paid'    ],
		'partial' => [ 'label' => 'Partial', 'class' => 'cf-pay-partial' ],
		'unpaid'  => [ 'label' => 'Unpaid',  'class' => 'cf-pay-unpaid'  ],
	];

	public function __construct() {
		// Surface 1 — totals rows on the order screen.
		add_action( 'woocommerce_admin_order_totals_after_tax', [ $this, 'render_totals' ] );

		// Surface 2 — the button + the slide-in panel.
		add_action( 'woocommerce_order_item_add_action_buttons',        [ $this, 'render_button' ] );
		add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'render_panel' ] );

		// Surface 3 — payment column on the orders list (HPOS-aware hooks, same
		// pair class-meta.php uses for its courier column).
		add_filter( 'woocommerce_shop_order_list_table_columns',       [ $this, 'add_column'    ], 20 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', [ $this, 'render_column' ], 10, 2 );

		// Surface 4 — payment filter on the orders list (same pair class-meta.php
		// uses for its courier filter).
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', [ $this, 'render_filter' ] );
		add_filter( 'woocommerce_order_query_args',                        [ $this, 'apply_filter'  ] );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_cashflow_advance_add',    [ $this, 'ajax_add'    ] );
		add_action( 'wp_ajax_cashflow_advance_edit',   [ $this, 'ajax_edit'   ] );
		add_action( 'wp_ajax_cashflow_advance_delete', [ $this, 'ajax_delete' ] );
	}


	// ════════════════════════════════════════════════════════════════
	// CAPABILITY  (design D-6)
	// ════════════════════════════════════════════════════════════════

	/**
	 * manage_woocommerce for BOTH view and write, everywhere. The test plugin
	 * gated the button on manage_woocommerce but its AJAX handlers on
	 * manage_options, so shop managers saw a button that always errored.
	 */
	private static function can_manage() {
		return current_user_can( 'manage_woocommerce' );
	}


	// ════════════════════════════════════════════════════════════════
	// CASHFLOW API
	// ════════════════════════════════════════════════════════════════

	/**
	 * Call the CashFlow plugin API with the v5 per-connection secret.
	 *
	 * Reuses CashFlow_Plugin::api_request() (Rule #11) by passing the secret as
	 * its $token argument — that puts it in `Authorization: Bearer <secret>`,
	 * which is exactly what the backend's makePluginAuth accepts
	 * (cashflow-backend/src/middleware/pluginAuth.js). org_id and store_id are
	 * resolved by the backend FROM the secret and are never sent from here.
	 *
	 * The secret is read server-side and never localized to the browser.
	 */
	private static function api( $endpoint, $method = 'GET', $body = null ) {
		$secret = get_option( 'cashflow_connection_secret', '' );
		if ( empty( $secret ) ) {
			return [
				'ok'     => false,
				'status' => 0,
				'data'   => null,
				'error'  => 'This store is not connected to CashFlow. Connect it under CashFlow → Settings, then reload this order.',
			];
		}

		$res = CashFlow_Plugin::api_request( $endpoint, $method, $body, $secret );

		if ( empty( $res['ok'] ) ) {
			$res['error'] = self::error_message( $res );
		}
		return $res;
	}

	/**
	 * Turn an API failure into something a shop manager can act on.
	 *
	 * The status-0 wording is deliberate: a transport failure means we do NOT
	 * know whether the write landed, and saying "nothing was recorded" would be
	 * a guess presented as fact.
	 */
	private static function error_message( $res ) {
		if ( ! empty( $res['data']['error'] ) ) {
			return (string) $res['data']['error'];
		}
		if ( ! empty( $res['error'] ) ) {
			return (string) $res['error'];
		}

		$status = isset( $res['status'] ) ? (int) $res['status'] : 0;
		if ( 0 === $status ) {
			return 'CashFlow could not be reached, so this was NOT confirmed. Reload the order and check whether it was recorded before trying again.';
		}
		if ( 401 === $status ) {
			return 'CashFlow rejected this store’s connection. Reconnect under CashFlow → Settings.';
		}
		if ( 403 === $status ) {
			return 'CashFlow refused this request for this store.';
		}
		if ( 404 === $status ) {
			return 'CashFlow has no record of this order yet — it appears there once the order syncs. Nothing was recorded.';
		}
		return 'CashFlow returned an unexpected error (HTTP ' . $status . '). Nothing was recorded.';
	}

	/**
	 * The finance accounts the advance can land in — fetched live from CashFlow.
	 *
	 * id + name ONLY. The API never sends a balance and none is ever stored or
	 * displayed (design D-5).
	 *
	 * Returns [ 'accounts' => [ [id,name], … ], 'error' => string|null ].
	 * On an outage the last known list is reused so the picker does not blank
	 * out; a stale account id cannot mis-record money because the API validates
	 * it and the write fails loudly.
	 */
	public static function accounts() {
		$cached = get_transient( self::ACCOUNTS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return [ 'accounts' => $cached, 'error' => null ];
		}

		$res = self::api( '/plugin/finance/accounts' );

		if ( ! empty( $res['ok'] ) && ! empty( $res['data']['accounts'] ) && is_array( $res['data']['accounts'] ) ) {
			$accounts = [];
			foreach ( $res['data']['accounts'] as $a ) {
				if ( empty( $a['id'] ) ) {
					continue;
				}
				$accounts[] = [
					'id'   => (string) $a['id'],
					'name' => (string) ( ! empty( $a['name'] ) ? $a['name'] : 'Unnamed account' ),
				];
			}
			set_transient( self::ACCOUNTS_TRANSIENT, $accounts, self::ACCOUNTS_TTL );
			return [ 'accounts' => $accounts, 'error' => null ];
		}

		// Reachable but empty is not an error — it means the org has no accounts.
		if ( ! empty( $res['ok'] ) ) {
			return [ 'accounts' => [], 'error' => null ];
		}

		return [
			'accounts' => [],
			'error'    => ! empty( $res['error'] ) ? $res['error'] : 'Could not load finance accounts from CashFlow.',
		];
	}

	/**
	 * id → name for a whole list of rows, from an accounts list the caller has
	 * ALREADY fetched — one lookup per render, not one per row, and never a second
	 * accounts() call (which on the uncached path is a second HTTP round-trip).
	 *
	 * A missing id is not an error: the account may be one this connection can no
	 * longer see. The row then shows a dash — never a guessed name.
	 */
	private static function account_names( $accounts ) {
		$map = [];
		foreach ( $accounts as $a ) {
			$map[ $a['id'] ] = $a['name'];
		}
		return $map;
	}

	/**
	 * 🔴 THE order's advance payments, read LIVE from CashFlow. This is the ONLY
	 * list — the panel renders it and edits against it, and there is no copy of
	 * it anywhere in WordPress.
	 *
	 * It is deliberately not cached. A cached list is a second version of the
	 * truth, and the moment it disagrees with CashFlow the panel starts offering
	 * to delete payments that no longer exist and to re-enter money that is
	 * already recorded. One HTTP call per panel render buys that away.
	 *
	 * Returns [ 'payments' => [ … ], 'error' => string|null ].
	 * On failure `payments` is EMPTY and `error` is set — and the caller MUST
	 * render no rows and no controls (Golden Rule #6). An empty list on a failed
	 * read is not "no payments", it is "we do not know", and the two must never
	 * look the same on screen.
	 */
	private static function payments( $order ) {
		$res = self::api( '/plugin/orders/' . rawurlencode( $order->get_id() ) . '/advance-payments' );

		if ( empty( $res['ok'] ) ) {
			return [
				'payments' => [],
				'error'    => ! empty( $res['error'] ) ? $res['error'] : 'Could not load the advance payments from CashFlow.',
			];
		}

		$rows = ( isset( $res['data']['payments'] ) && is_array( $res['data']['payments'] ) )
			? $res['data']['payments']
			: [];

		$out = [];
		foreach ( $rows as $p ) {
			if ( empty( $p['id'] ) ) {
				continue; // Nothing can be edited or deleted without CashFlow's id.
			}
			$out[] = [
				'id'         => (string) $p['id'],
				'amount'     => isset( $p['amount'] ) ? (float) $p['amount'] : 0.0,
				'account_id' => (string) ( isset( $p['account_id'] ) ? $p['account_id'] : '' ),
				'txn'        => (string) ( isset( $p['transaction_id'] ) ? $p['transaction_id'] : '' ),
				'paid_at'    => (string) ( isset( $p['paid_at'] ) ? $p['paid_at'] : '' ),
				'source'     => (string) ( isset( $p['source'] ) ? $p['source'] : '' ),
			];
		}

		return [ 'payments' => $out, 'error' => null ];
	}

	/**
	 * The advance total = the SUM of the rows CashFlow just returned.
	 *
	 * This is CashFlow's own derivation restated, not arithmetic of our own:
	 * orders.advance_amount is a DERIVED SUM of exactly these rows, maintained by
	 * trg_order_advance_payments_sync. Summing the list we were just handed lands
	 * on the same number, from the same source, with nothing carried over from a
	 * previous screen.
	 */
	private static function advance_total( $payments ) {
		$sum = 0.0;
		foreach ( $payments as $p ) {
			$sum += (float) $p['amount'];
		}
		return round( $sum, 2 );
	}


	// ════════════════════════════════════════════════════════════════
	// DISPLAY CACHE  (for the surfaces that cannot call the API)
	// ════════════════════════════════════════════════════════════════

	private static function advance_amount( $order ) {
		return (float) $order->get_meta( self::META_ADVANCE );
	}

	/**
	 * CashFlow's own payment_status rule, mirrored exactly
	 * (cashflow-backend/src/services/updateOrderOnWoo.service.js:162):
	 *   advance === 0 ? 'unpaid' : advance >= total ? 'paid' : 'partial'
	 * Two different answers to "is this order paid" across the two screens would
	 * be worse than one rule restated in a second language.
	 */
	private static function derive_status( $advance, $total ) {
		if ( $advance <= 0 ) {
			return 'unpaid';
		}
		return $advance >= $total ? 'paid' : 'partial';
	}

	/**
	 * Refresh the display cache from CashFlow's truth, in ONE save.
	 * (The test plugin saved 2–3 times per action: sync_cod_amount saved, then
	 * sync_payment_status saved again.)
	 *
	 * 🔴 $advance MUST be the sum of a list CashFlow just returned — see
	 * advance_total(). It is never this cache's own previous value plus or minus
	 * what we think we changed: that arithmetic silently drifts the moment the
	 * same order is touched in the CashFlow app, and it drifts on the money that
	 * decides what the courier collects at the door.
	 *
	 * Nothing here is authoritative. CashFlow pushes these same three fields and
	 * overwrites us, which is correct (design D-1) — this only spares the orders
	 * list and the totals rows from being wrong until it does.
	 */
	private static function write_display_cache( $order, $advance ) {
		$advance = max( 0, (float) $advance );
		$total   = (float) $order->get_total();
		$cod     = max( 0, $total - $advance );

		$order->update_meta_data( self::META_ADVANCE, wc_format_decimal( $advance, 2 ) );
		$order->update_meta_data( self::META_COD, wc_format_decimal( $cod, 2 ) );
		$order->update_meta_data( self::META_STATUS, self::derive_status( $advance, $total ) );
		$order->save();
	}


	// ════════════════════════════════════════════════════════════════
	// SHARED RENDERERS  (one per repeated element — Rule #11)
	// ════════════════════════════════════════════════════════════════

	/**
	 * wc_price() as PLAIN TEXT, for a title attribute or a sentence.
	 *
	 * wc_price() separates the currency symbol from the number with a literal
	 * `&nbsp;` entity, so stripping the tags alone leaves "Rs&nbsp;500.00" —
	 * which esc_attr()/esc_html() then re-encode into a visible "&nbsp;". Decode
	 * the entities back to real characters first. (Only for text contexts: HTML
	 * contexts print wc_price() through wp_kses_post() untouched.)
	 */
	private static function money_text( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	private static function status_badge( $key ) {
		$s = isset( self::$statuses[ $key ] ) ? self::$statuses[ $key ] : self::$statuses['unpaid'];
		return sprintf(
			'<span class="cf-pay-badge %s">%s</span>',
			esc_attr( $s['class'] ),
			esc_html( $s['label'] )
		);
	}

	/**
	 * Where a payment was recorded. CashFlow's `source` is the answer, and the
	 * DB's CHECK allows exactly these two values.
	 *
	 * This column replaced a "By" column that named the WordPress user. That name
	 * came out of the local mirror, and the mirror is gone: CashFlow records who
	 * took a payment in CashFlow, and a payment taken in the app has no WordPress
	 * user to name at all. Showing where it came from is something we actually
	 * know.
	 */
	private static function source_label( $source ) {
		return 'woocommerce' === $source ? 'WooCommerce' : 'CashFlow';
	}

	private static function render_row( $payment, $names ) {
		$id      = $payment['id'];
		$amount  = (float) $payment['amount'];
		$account = isset( $names[ $payment['account_id'] ] ) ? $names[ $payment['account_id'] ] : '—';
		$txn     = '' !== $payment['txn'] ? $payment['txn'] : '—';

		ob_start();
		?>
		<tr data-id="<?php echo esc_attr( $id ); ?>">
			<td><?php echo esc_html( self::local_date( $payment['paid_at'] ) ); ?></td>
			<td><?php echo wp_kses_post( wc_price( $amount ) ); ?></td>
			<td><?php echo esc_html( $account ); ?></td>
			<td><?php echo esc_html( $txn ); ?></td>
			<td><?php echo esc_html( self::source_label( $payment['source'] ) ); ?></td>
			<td class="cf-adv-row-actions">
				<button type="button" class="button cf-adv-edit"
					data-id="<?php echo esc_attr( $id ); ?>"
					data-amount="<?php echo esc_attr( $amount ); ?>"
					data-account="<?php echo esc_attr( $payment['account_id'] ); ?>"
					data-txn="<?php echo esc_attr( $payment['txn'] ); ?>"
				>Edit</button>
				<button type="button" class="button cf-adv-delete" data-id="<?php echo esc_attr( $id ); ?>">Delete</button>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * $payments is always a list CashFlow returned. "Empty" here therefore means
	 * CashFlow holds none — it can never mean "we could not ask", because a
	 * failed read never reaches this far (see payments() and render_panel()).
	 */
	private static function render_rows( $payments, $names ) {
		if ( empty( $payments ) ) {
			return '<tr class="cf-adv-empty"><td colspan="6">No advance payments on this order.</td></tr>';
		}
		$out = '';
		foreach ( $payments as $payment ) {
			$out .= self::render_row( $payment, $names );
		}
		return $out;
	}

	/**
	 * Everything the panel re-renders after a write — rebuilt from a FRESH read
	 * of CashFlow, never from what we believe we just changed. Built server-side
	 * so the row markup has exactly one definition (in PHP) instead of a second
	 * copy in JavaScript that would drift.
	 */
	private static function panel_payload( $order, $message = '' ) {
		$read = self::payments( $order );

		if ( null !== $read['error'] ) {
			// The write itself succeeded — the caller already checked. What failed
			// is reading back the result, so we do not know what the list looks
			// like now and will not draw one. Sending no `rows`/`advance`/`cod`/
			// `badge` keys leaves the screen exactly as it was: apply() only
			// touches the keys it is given. Stale, and said out loud.
			//
			// `stale` also tells the panel to LOCK: what is on screen no longer has
			// a known baseline behind it, so it must not be edited from. Reloading
			// is the only way back.
			return [
				'message' => trim( $message . ' But the list below could NOT be refreshed from CashFlow (' . $read['error'] . '), so it is now out of date — reload the order before recording anything else. Do not re-enter what you just recorded.' ),
				'stale'   => true,
			];
		}

		$advance  = self::advance_total( $read['payments'] );
		$total    = (float) $order->get_total();
		$accounts = self::accounts();

		self::write_display_cache( $order, $advance );

		return [
			'rows'    => self::render_rows( $read['payments'], self::account_names( $accounts['accounts'] ) ),
			'advance' => wc_price( $advance ),
			'cod'     => wc_price( max( 0, $total - $advance ) ),
			'badge'   => self::status_badge( self::derive_status( $advance, $total ) ),
			'message' => $message,
		];
	}


	// ════════════════════════════════════════════════════════════════
	// SURFACE 1 — TOTALS ROWS
	// ════════════════════════════════════════════════════════════════

	public function render_totals( $order_id ) {
		if ( ! self::can_manage() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$advance = self::advance_amount( $order );
		$total   = (float) $order->get_total();
		$cod     = max( 0, $total - $advance );
		?>
		<tr><td colspan="3" class="cf-adv-sep"></td></tr>
		<tr>
			<td class="label">Advance Paid
				<span class="cf-adv-badge-slot"><?php echo wp_kses_post( self::status_badge( self::derive_status( $advance, $total ) ) ); ?></span>
			</td>
			<td width="1%"></td>
			<td class="total cf-adv-total-advance"><?php echo wp_kses_post( wc_price( $advance ) ); ?></td>
		</tr>
		<tr>
			<td class="label">COD to Collect</td>
			<td width="1%"></td>
			<td class="total cf-adv-total-cod"><?php echo wp_kses_post( wc_price( $cod ) ); ?></td>
		</tr>
		<tr><td colspan="3" class="cf-adv-sep"></td></tr>
		<?php
	}


	// ════════════════════════════════════════════════════════════════
	// SURFACE 2 — BUTTON + SLIDE-IN PANEL
	// ════════════════════════════════════════════════════════════════

	public function render_button( $order ) {
		if ( ! self::can_manage() || ! $order instanceof WC_Order || ! $order->get_id() ) {
			return;
		}
		echo '<button type="button" class="button cf-adv-open">Advance Payments</button>';
	}

	public function render_panel( $order ) {
		if ( ! self::can_manage() || ! $order instanceof WC_Order || ! $order->get_id() ) {
			return;
		}

		// 🔴 The live read is the BASELINE for everything below it. If it failed we
		// do not know what exists, so the panel shows no rows and offers no
		// controls at all — not the last thing we saw, and not an empty table that
		// would read as "no payments". No baseline, no controls (Rule #6).
		$read       = self::payments( $order );
		$read_error = $read['error'];

		// Everything below is asked for ONLY once the read is known good. If
		// CashFlow could not be reached at all, a second doomed call would stall
		// this order screen for another api_request timeout, to fill in a picker
		// that is not going to be rendered anyway.
		$accounts = [ 'accounts' => [], 'error' => null ];
		$names    = [];
		$blocker  = '';

		if ( ! $read_error ) {
			$accounts = self::accounts();
			$names    = self::account_names( $accounts['accounts'] );

			// No form is offered when there is nothing valid to submit — a broken
			// form that always errors is worse than a plain sentence (Rule #6).
			if ( ! empty( $accounts['error'] ) ) {
				$blocker = $accounts['error'];
			} elseif ( empty( $accounts['accounts'] ) ) {
				$blocker = 'CashFlow has no finance accounts for this store yet. Add one in CashFlow → Finance, then reload this order.';
			}
		}
		?>
		<div id="cf-adv-overlay" class="cf-adv-overlay" style="display:none;">
			<div class="cf-adv-panel" role="dialog" aria-label="Advance payments">

				<div class="cf-adv-header">
					<h3>Advance Payments</h3>
					<button type="button" class="button-link cf-adv-close" aria-label="Close">&times;</button>
				</div>

				<div class="cf-adv-body">
					<p class="cf-adv-hint">Read from and recorded straight into CashFlow. The account is the payment method; each entry means money already received.</p>

					<?php if ( $read_error ) : ?>

						<div class="cf-adv-notice cf-adv-notice-error"><?php echo esc_html( $read_error ); ?></div>
						<p class="cf-adv-hint">
							This order&rsquo;s payments could not be read, so none are shown and none can be
							added, edited or deleted here. Nothing is wrong with the order &mdash; reload it once
							CashFlow is reachable, or open it in CashFlow.
						</p>

					<?php else : ?>

						<!-- Outside the form block on purpose: the row Edit/Delete buttons render
						     even when the form does not (no accounts), and they still need the
						     order id to post and a slot to report a failure into. Inside the form
						     these would vanish and a delete would fail silently. No name
						     attribute — see the note on the form fields below. -->
						<input type="hidden" id="cf-adv-order-id" value="<?php echo esc_attr( $order->get_id() ); ?>">

						<div class="cf-adv-table-wrap">
							<table class="cf-adv-table">
								<thead>
									<tr>
										<th>Date</th><th>Amount</th><th>Account</th><th>Txn ID</th><th>Source</th><th>Actions</th>
									</tr>
								</thead>
								<tbody id="cf-adv-rows"><?php echo wp_kses_post( self::render_rows( $read['payments'], $names ) ); ?></tbody>
							</table>
						</div>

						<p id="cf-adv-msg" class="cf-adv-msg"></p>

						<?php if ( $blocker ) : ?>
							<div class="cf-adv-notice cf-adv-notice-error"><?php echo esc_html( $blocker ); ?></div>
						<?php else : ?>
							<div class="cf-adv-form">
								<h4 id="cf-adv-form-title">Add a payment</h4>

								<!-- No name attributes anywhere in this panel: it renders inside the
								     WooCommerce order form, and named fields would be posted with the
								     order itself. -->
								<input type="hidden" id="cf-adv-payment-id" value="">

								<label for="cf-adv-amount">Amount *</label>
								<input type="number" id="cf-adv-amount" step="0.01" min="0.01" autocomplete="off">

								<label for="cf-adv-account">Account *</label>
								<select id="cf-adv-account">
									<option value="">— Select account —</option>
									<?php foreach ( $accounts['accounts'] as $a ) : ?>
										<option value="<?php echo esc_attr( $a['id'] ); ?>"><?php echo esc_html( $a['name'] ); ?></option>
									<?php endforeach; ?>
								</select>

								<label for="cf-adv-txn">Transaction ID (optional)</label>
								<input type="text" id="cf-adv-txn" autocomplete="off">

								<div class="cf-adv-form-actions">
									<button type="button" class="button button-primary" id="cf-adv-save">Save payment</button>
									<button type="button" class="button" id="cf-adv-cancel" style="display:none;">Cancel</button>
								</div>
							</div>
						<?php endif; ?>

					<?php endif; ?>
				</div>

			</div>
		</div>
		<?php
	}


	// ════════════════════════════════════════════════════════════════
	// SURFACE 3 — ORDERS LIST COLUMN
	// ════════════════════════════════════════════════════════════════

	public function add_column( $columns ) {
		$new = [];
		foreach ( $columns as $key => $col ) {
			$new[ $key ] = $col;
			if ( 'order_total' === $key ) {
				$new['cashflow_payment'] = 'Payment';
			}
		}
		return $new;
	}

	public function render_column( $column, $order ) {
		if ( 'cashflow_payment' !== $column || ! $order instanceof WC_Order ) {
			return;
		}

		$advance = self::advance_amount( $order );
		$total   = (float) $order->get_total();
		$cod     = max( 0, $total - $advance );

		$tooltip = sprintf(
			"Advance: %s\nCOD: %s\nTotal: %s",
			self::money_text( $advance ),
			self::money_text( $cod ),
			self::money_text( $total )
		);

		printf(
			'<span title="%s">%s</span>',
			esc_attr( $tooltip ),
			wp_kses_post( self::status_badge( self::derive_status( $advance, $total ) ) )
		);
	}


	// ════════════════════════════════════════════════════════════════
	// SURFACE 4 — ORDERS LIST FILTER
	// ════════════════════════════════════════════════════════════════

	public function render_filter() {
		if ( ! self::can_manage() ) {
			return;
		}

		$selected = isset( $_GET['cf_payment_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['cf_payment_filter'] ) ) : '';

		$options = [ '' => 'All payments' ];
		foreach ( self::$statuses as $key => $data ) {
			$options[ $key ] = $data['label'];
		}

		echo '<select name="cf_payment_filter" style="margin-left:8px;">';
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

	public function apply_filter( $args ) {
		if ( empty( $_GET['cf_payment_filter'] ) ) {
			return $args;
		}

		$filter = sanitize_text_field( wp_unslash( $_GET['cf_payment_filter'] ) );
		if ( ! isset( self::$statuses[ $filter ] ) ) {
			return $args;
		}

		if ( 'unpaid' === $filter ) {
			// An order that never took an advance carries no status meta at all —
			// nothing stamps every order in the store, and nothing should (the
			// test plugin's activation hook looped every order to do exactly
			// that). So "Unpaid" must also match orders with the key absent.
			$args['meta_query'][] = [
				'relation' => 'OR',
				[ 'key' => self::META_STATUS, 'value' => 'unpaid', 'compare' => '=' ],
				[ 'key' => self::META_STATUS, 'compare' => 'NOT EXISTS' ],
			];
		} else {
			$args['meta_query'][] = [
				'key'     => self::META_STATUS,
				'value'   => $filter,
				'compare' => '=',
			];
		}

		return $args;
	}


	// ════════════════════════════════════════════════════════════════
	// ASSETS
	// ════════════════════════════════════════════════════════════════

	public function enqueue_assets() {
		if ( ! self::can_manage() ) {
			return;
		}

		$is_edit = self::is_order_edit_screen();
		$is_list = self::is_order_list_screen();
		if ( ! $is_edit && ! $is_list ) {
			return;
		}

		wp_enqueue_style( 'cashflow-advance', CASHFLOW_PLUGIN_URL . 'assets/advance.css', [], CASHFLOW_VERSION );

		if ( ! $is_edit ) {
			return;
		}

		wp_enqueue_script( 'cashflow-advance', CASHFLOW_PLUGIN_URL . 'assets/advance.js', [ 'jquery' ], CASHFLOW_VERSION, true );
		// ajaxUrl + nonce ONLY. The connection secret and the CashFlow token
		// stay server-side and are never handed to the browser.
		wp_localize_script( 'cashflow-advance', 'cashflowAdvance', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
		] );
	}

	/**
	 * The order screen id, HPOS-aware — class-meta.php's exact pattern.
	 * HPOS: 'woocommerce_page_wc-orders' (list AND edit). Legacy: 'shop_order'
	 * for edit, 'edit-shop_order' for the list.
	 */
	private static function order_screen_id() {
		return class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';
	}

	private static function is_order_edit_screen() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== self::order_screen_id() ) {
			return false;
		}
		if ( 'shop_order' === $screen->id ) {
			return true; // legacy: the edit screen has its own id
		}
		// HPOS: list and edit share one screen id — only the edit view has `action`.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
		return 'edit' === $action || 'new' === $action;
	}

	private static function is_order_list_screen() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		if ( 'edit-shop_order' === $screen->id ) {
			return true; // legacy list
		}
		if ( $screen->id !== self::order_screen_id() || 'shop_order' === $screen->id ) {
			return false;
		}
		// HPOS: list and edit share one screen id. Anything that is not the edit
		// view is the list — including a bulk action, which posts its own
		// `action` value and would be missed by an isset() check.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
		return 'edit' !== $action && 'new' !== $action;
	}


	// ════════════════════════════════════════════════════════════════
	// AJAX
	// ════════════════════════════════════════════════════════════════

	/**
	 * Nonce, then capability, then the order — on EVERY handler, not just the
	 * button that opens the panel. Sends the error and exits on failure.
	 */
	private static function guard_request() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! self::can_manage() ) {
			wp_send_json_error( [ 'message' => 'You do not have permission to manage advance payments.' ], 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			wp_send_json_error( [ 'message' => 'Order not found.' ], 404 );
		}

		return $order;
	}

	private static function posted_amount() {
		$raw = isset( $_POST['amount'] ) ? wp_unslash( $_POST['amount'] ) : '';
		return (float) wc_format_decimal( $raw, 2 );
	}

	private static function posted_text( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * The CashFlow payment id an edit/delete names — taken straight from the row,
	 * which came straight from CashFlow.
	 *
	 * It is passed to the API as-is, with no local check that it belongs to this
	 * order. That check would be theatre: the backend resolves org + store from
	 * the connection secret and 404s anything outside this store, and within the
	 * store a shop manager can already edit any order's payments by opening that
	 * order. The connection secret is the wall — not a lookup we do here.
	 */
	private static function posted_payment_id() {
		return self::posted_text( 'payment_id' );
	}

	private static function local_date( $iso ) {
		if ( empty( $iso ) ) {
			return current_time( 'Y-m-d H:i' );
		}
		$ts = strtotime( (string) $iso );
		return $ts ? wp_date( 'Y-m-d H:i', $ts ) : current_time( 'Y-m-d H:i' );
	}

	// ── Add ─────────────────────────────────────────────────────────
	public function ajax_add() {
		$order = self::guard_request();

		$amount     = self::posted_amount();
		$account_id = self::posted_text( 'account_id' );
		$txn        = self::posted_text( 'transaction_id' );

		if ( $amount <= 0 ) {
			wp_send_json_error( [ 'message' => 'Enter an amount greater than 0.' ], 422 );
		}
		if ( empty( $account_id ) ) {
			wp_send_json_error( [ 'message' => 'Choose the account the money went into.' ], 422 );
		}

		// This plugin's own id for the payment, sent as external_id. The backend's
		// UNIQUE (store_id, external_id) turns a retry of the SAME request into
		// the same payment instead of a second one, so a timeout cannot
		// double-record money. It is write-only from here: nothing reads it back,
		// because CashFlow's own payment id comes down with the list.
		$external_id = 'wc_' . $order->get_id() . '_' . wp_generate_password( 12, false );

		$res = self::api(
			'/plugin/orders/' . rawurlencode( $order->get_id() ) . '/advance-payments',
			'POST',
			[
				'amount'         => $amount,
				'account_id'     => $account_id,
				'transaction_id' => '' !== $txn ? $txn : null,
				'external_id'    => $external_id,
			]
		);

		if ( empty( $res['ok'] ) ) {
			// Fail loudly, write nothing (Golden Rule #6).
			CashFlow_Plugin::log( 'advance_add', 'order', $order->get_id(), 'error', $res['error'] );
			wp_send_json_error( [ 'message' => $res['error'] ], 502 );
		}

		CashFlow_Plugin::log( 'advance_add', 'order', $order->get_id(), 'success', 'Advance recorded in CashFlow' );

		$message = ! empty( $res['data']['finance_warning'] )
			? 'Payment recorded, but CashFlow could not post it to the ledger: ' . $res['data']['finance_warning']
			: 'Payment recorded in CashFlow.';

		wp_send_json_success( self::panel_payload( $order, $message ) );
	}

	// ── Edit ────────────────────────────────────────────────────────
	public function ajax_edit() {
		$order = self::guard_request();

		$payment_id = self::posted_payment_id();
		$amount     = self::posted_amount();
		$account_id = self::posted_text( 'account_id' );
		$txn        = self::posted_text( 'transaction_id' );

		if ( empty( $payment_id ) ) {
			wp_send_json_error( [ 'message' => 'That payment is no longer on this order. Reload and try again.' ], 400 );
		}
		if ( $amount <= 0 ) {
			wp_send_json_error( [ 'message' => 'Enter an amount greater than 0. To remove a payment, delete it.' ], 422 );
		}
		if ( empty( $account_id ) ) {
			wp_send_json_error( [ 'message' => 'Choose the account the money went into.' ], 422 );
		}

		$res = self::api(
			'/plugin/advance-payments/' . rawurlencode( $payment_id ),
			'PATCH',
			[
				'amount'         => $amount,
				'account_id'     => $account_id,
				'transaction_id' => '' !== $txn ? $txn : null,
			]
		);

		if ( empty( $res['ok'] ) ) {
			CashFlow_Plugin::log( 'advance_edit', 'order', $order->get_id(), 'error', $res['error'] );
			wp_send_json_error( [ 'message' => $res['error'] ], 502 );
		}

		CashFlow_Plugin::log( 'advance_edit', 'order', $order->get_id(), 'success', 'Advance updated in CashFlow' );

		$message = ! empty( $res['data']['finance_warning'] )
			? 'Payment updated, but CashFlow could not post it to the ledger: ' . $res['data']['finance_warning']
			: 'Payment updated in CashFlow.';

		wp_send_json_success( self::panel_payload( $order, $message ) );
	}

	// ── Delete ──────────────────────────────────────────────────────
	public function ajax_delete() {
		$order = self::guard_request();

		$payment_id = self::posted_payment_id();
		if ( empty( $payment_id ) ) {
			wp_send_json_error( [ 'message' => 'That payment is no longer on this order. Reload and try again.' ], 400 );
		}

		$res = self::api( '/plugin/advance-payments/' . rawurlencode( $payment_id ), 'DELETE' );

		if ( empty( $res['ok'] ) ) {
			CashFlow_Plugin::log( 'advance_delete', 'order', $order->get_id(), 'error', $res['error'] );
			wp_send_json_error( [ 'message' => $res['error'] ], 502 );
		}

		CashFlow_Plugin::log( 'advance_delete', 'order', $order->get_id(), 'success', 'Advance removed in CashFlow' );

		wp_send_json_success( self::panel_payload( $order, 'Payment removed in CashFlow.' ) );
	}
}
