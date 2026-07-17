/**
 * CashFlow — advance payments panel (order edit screen).
 *
 * Every write goes PHP → CashFlow. This file never sees the connection secret
 * and never decides anything about money: it posts the form, and re-renders
 * whatever the server hands back (rows/totals are rendered in PHP so the markup
 * has exactly one definition).
 *
 * A failure is shown in the panel and nothing on the page is updated —
 * no silent fallback.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.cashflowAdvance || {};

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

	function resetForm() {
		$( '#cf-adv-entry-id' ).val( '' );
		$( '#cf-adv-amount' ).val( '' );
		$( '#cf-adv-account' ).val( '' );
		$( '#cf-adv-txn' ).val( '' );
		$( '#cf-adv-form-title' ).text( 'Add a payment' );
		$( '#cf-adv-save' ).text( 'Save payment' );
		$( '#cf-adv-cancel' ).hide();
	}

	// Re-render from the server's payload. Only the keys it sent are touched.
	function apply( data ) {
		if ( ! data ) {
			return;
		}
		if ( typeof data.rows === 'string' ) {
			$( '#cf-adv-rows' ).html( data.rows );
		}
		if ( typeof data.notice === 'string' ) {
			$( '#cf-adv-notice' ).html( data.notice );
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
		busy( true );
		say( '' );

		return $.post(
			cfg.ajaxUrl,
			$.extend( { action: action, nonce: cfg.nonce, order_id: $( '#cf-adv-order-id' ).val() }, data )
		)
			.done( function ( res ) {
				if ( res && res.success ) {
					apply( res.data );
					resetForm();
					say( ( res.data && res.data.message ) || 'Saved.', false );
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
		$( document ).on( 'click', '.cf-adv-edit', function () {
			var $btn = $( this );
			$( '#cf-adv-entry-id' ).val( $btn.data( 'id' ) );
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
			var entryId = $( '#cf-adv-entry-id' ).val();
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

			if ( entryId ) {
				payload.entry_id = entryId;
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
			post( 'cashflow_advance_delete', { entry_id: $( this ).data( 'id' ) } );
		} );
	} );
} )( jQuery );
