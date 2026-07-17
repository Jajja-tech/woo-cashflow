/**
 * CashFlow — advance payments panel (order edit screen).
 *
 * Every read and every write goes PHP → CashFlow. This file never sees the
 * connection secret and never decides anything about money: it posts the form,
 * and re-renders whatever the server hands back. The rows it draws were read
 * live from CashFlow and rendered in PHP, so the markup has exactly one
 * definition and this file keeps no list of its own to fall back on.
 *
 * A failure is shown in the panel and nothing on the page is updated — no silent
 * fallback. If a write lands but CashFlow cannot be read back, the server says
 * `stale` and the panel LOCKS rather than let anyone act on rows it can no
 * longer vouch for.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.cashflowAdvance || {};

	// One-way. Set when the panel loses its baseline (a write landed but CashFlow
	// could not be read back), and only a reload clears it.
	var locked = false;

	function say( msg, isError ) {
		var $msg = $( '#cf-adv-msg' );
		if ( ! $msg.length ) {
			return;
		}
		$msg.text( msg || '' ).attr(
			'class',
			msg ? ( isError ? 'cf-adv-msg cf-adv-msg-error' : 'cf-adv-msg cf-adv-msg-ok' ) : 'cf-adv-msg'
		);
	}

	function busy( on ) {
		$( '#cf-adv-overlay' ).toggleClass( 'cf-adv-busy', !! on );
	}

	/**
	 * The write landed but CashFlow could not be read back, so the rows and totals
	 * on screen are no longer known to be current. Take the controls away until
	 * the order is reloaded: same rule as a panel that could not be read at all —
	 * no baseline, no controls. Without this the form is still sitting there
	 * inviting the user to re-enter a payment that IS already recorded — and
	 * external_id cannot save them, because it is minted per request and so
	 * dedupes a retry of one request, never a human re-entry.
	 *
	 * 🔴 The `locked` flag in post() is the ENFORCEMENT. The class and the
	 * disabled props are only the affordance: `pointer-events: none` does not stop
	 * a keyboard Enter on a focused button, which dispatches a real click straight
	 * into the delegated handlers. Greying a control out is not the same as
	 * turning it off.
	 *
	 * Deliberately one-way. Only a reload re-establishes a baseline.
	 */
	function lock() {
		locked = true;
		$( '#cf-adv-overlay' ).addClass( 'cf-adv-locked' );
		$( '#cf-adv-save, #cf-adv-cancel, #cf-adv-amount, #cf-adv-account, #cf-adv-txn' ).prop( 'disabled', true );
		$( '#cf-adv-rows' ).find( '.cf-adv-edit, .cf-adv-delete' ).prop( 'disabled', true );
	}

	function resetForm() {
		$( '#cf-adv-payment-id' ).val( '' );
		$( '#cf-adv-amount' ).val( '' );
		$( '#cf-adv-account' ).val( '' );
		$( '#cf-adv-txn' ).val( '' );
		$( '#cf-adv-form-title' ).text( 'Add a payment' );
		$( '#cf-adv-save' ).text( 'Save payment' );
		$( '#cf-adv-cancel' ).hide();
	}

	// Re-render from the server's payload. Only the keys it sent are touched —
	// which is load-bearing when the write landed but the read-back did not: the
	// server then sends a message and NO rows/totals, and the screen keeps what it
	// had rather than being redrawn from a guess.
	function apply( data ) {
		if ( ! data ) {
			return;
		}
		if ( typeof data.rows === 'string' ) {
			$( '#cf-adv-rows' ).html( data.rows );
		}
		if ( typeof data.advance === 'string' ) {
			$( '.cf-adv-total-advance' ).html( data.advance );
		}
		if ( typeof data.cod === 'string' ) {
			$( '.cf-adv-total-cod' ).html( data.cod );
		}
		if ( typeof data.badge === 'string' ) {
			$( '.cf-adv-badge-slot' ).html( data.badge );
		}
	}

	function post( action, data ) {
		// 🔴 THE enforcement point for lock(). Every control funnels through here,
		// so this catches the click however it arrived — mouse, keyboard, or a
		// handler firing on markup that CSS was only pretending to disable.
		if ( locked ) {
			say( 'This panel is out of date and cannot record anything more. Reload the order.', true );
			return;
		}

		busy( true );
		say( '' );

		return $.post(
			cfg.ajaxUrl,
			$.extend( { action: action, nonce: cfg.nonce, order_id: $( '#cf-adv-order-id' ).val() }, data )
		)
			.done( function ( res ) {
				if ( res && res.success ) {
					var stale = !! ( res.data && res.data.stale );
					apply( res.data );
					resetForm();
					// `stale` = the write landed but CashFlow could not be re-read, so
					// what is on screen is no longer known to be current. That is not a
					// success tone, and it is not a state to keep editing from.
					if ( stale ) {
						lock();
					}
					say( ( res.data && res.data.message ) || 'Saved.', stale );
					return;
				}
				// success:false — the server rejected it and changed nothing.
				say( ( res && res.data && res.data.message ) || 'CashFlow rejected the change. Nothing was recorded.', true );
			} )
			.fail( function ( xhr ) {
				var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
				say(
					msg || 'The request failed and CashFlow did not confirm it. Reload the order and check before trying again.',
					true
				);
			} )
			.always( function () {
				busy( false );
			} );
	}

	$( function () {
		var $overlay = $( '#cf-adv-overlay' );
		if ( ! $overlay.length ) {
			return;
		}

		$( document ).on( 'click', '.cf-adv-open', function () {
			$overlay.show();
		} );

		function close() {
			$overlay.hide();
			resetForm();
			say( '' );
		}

		$( document ).on( 'click', '.cf-adv-close', close );

		// Click the dark backdrop (not the panel) to close.
		$overlay.on( 'click', function ( e ) {
			if ( e.target === this ) {
				close();
			}
		} );

		$( document ).on( 'keyup', function ( e ) {
			if ( e.key === 'Escape' && $overlay.is( ':visible' ) ) {
				close();
			}
		} );

		// ── Edit: load the row into the form ──
		// data-id is CashFlow's own payment id — the rows were rendered from
		// CashFlow's list, so it is the id the API expects, with no local
		// translation step in between.
		$( document ).on( 'click', '.cf-adv-edit', function () {
			var $btn = $( this );
			$( '#cf-adv-payment-id' ).val( $btn.data( 'id' ) );
			$( '#cf-adv-amount' ).val( $btn.data( 'amount' ) );
			$( '#cf-adv-account' ).val( String( $btn.data( 'account' ) || '' ) );
			$( '#cf-adv-txn' ).val( $btn.data( 'txn' ) || '' );
			$( '#cf-adv-form-title' ).text( 'Edit payment' );
			$( '#cf-adv-save' ).text( 'Update payment' );
			$( '#cf-adv-cancel' ).show();
			say( '' );
		} );

		$( document ).on( 'click', '#cf-adv-cancel', function () {
			resetForm();
			say( '' );
		} );

		// ── Save (add or edit) ──
		$( document ).on( 'click', '#cf-adv-save', function () {
			var paymentId = $( '#cf-adv-payment-id' ).val();
			var payload = {
				amount: $( '#cf-adv-amount' ).val(),
				account_id: $( '#cf-adv-account' ).val(),
				transaction_id: $( '#cf-adv-txn' ).val()
			};

			if ( ! payload.amount || parseFloat( payload.amount ) <= 0 ) {
				say( 'Enter an amount greater than 0.', true );
				return;
			}
			if ( ! payload.account_id ) {
				say( 'Choose the account the money went into.', true );
				return;
			}

			if ( paymentId ) {
				payload.payment_id = paymentId;
				post( 'cashflow_advance_edit', payload );
			} else {
				post( 'cashflow_advance_add', payload );
			}
		} );

		// ── Delete ──
		$( document ).on( 'click', '.cf-adv-delete', function () {
			if ( ! window.confirm( 'Delete this payment? It is removed from CashFlow too, and the COD to collect goes back up.' ) ) {
				return;
			}
			post( 'cashflow_advance_delete', { payment_id: $( this ).data( 'id' ) } );
		} );
	} );
} )( jQuery );
