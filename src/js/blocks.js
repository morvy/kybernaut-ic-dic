"use strict";

/**
 * Block checkout companion for assets/js/public.js.
 *
 * public.js drives the classic checkout through jQuery and #billing_* selectors,
 * none of which exist in the block checkout. This does the same two jobs against the
 * WooCommerce Blocks data stores instead: check the Business ID as it is typed, and
 * fill the address from ARES.
 *
 * Everything else the classic script does is handled elsewhere here: showing and
 * hiding the fields is a `hidden` rule WooCommerce evaluates itself (includes/blocks.php),
 * and the checkout refresh that revalidates the VAT number happens on its own —
 * WooCommerce pushes changed fields to the draft order and recalculates the totals.
 *
 * @since 2.0.0
 */
( function ( wp, wc, woolab ) {

	if ( ! wp || ! wp.data || ! wc || ! wc.wcBlocksData || ! woolab || ! woolab.fields ) {
		return;
	}

	var CHECKOUT = wc.wcBlocksData.CHECKOUT_STORE_KEY;
	var CART = wc.wcBlocksData.CART_STORE_KEY;
	var VALIDATION = wc.wcBlocksData.VALIDATION_STORE_KEY;

	var IC = woolab.fields.ic;
	var DIC = woolab.fields.dic;
	var COMPANY = woolab.fields.company;

	var lastIco = null;

	function debounce( fn, wait ) {
		var timeout;
		return function () {
			var args = arguments;
			clearTimeout( timeout );
			timeout = setTimeout( function () {
				fn.apply( null, args );
			}, wait );
		};
	}

	/**
	 * The modulo-11 checksum from woolab_icdic_verify_ic() (includes/helpers.php),
	 * so a mistyped Business ID is caught before anything is sent anywhere.
	 */
	function verifyIc( ico ) {
		if ( ! /^\d{8}$/.test( ico ) ) {
			return false;
		}

		var sum = 0;
		for ( var i = 0; i < 7; i++ ) {
			sum += parseInt( ico[ i ], 10 ) * ( 8 - i );
		}

		var mod = sum % 11;
		var check = mod === 0 ? 1 : ( mod === 1 ? 0 : 11 - mod );

		return parseInt( ico[ 7 ], 10 ) === check;
	}

	/**
	 * WooCommerce keys validation errors by the input's `name` — the location and the
	 * field id joined with an underscore, "order_kybernaut/ic" — while the element's
	 * own id swaps the slash for a dash. Find the element by the id it renders, then
	 * take the key off it, so neither convention has to be rebuilt here.
	 */
	function errorId() {
		var input = document.querySelector( '[id$="' + IC.replace( '/', '-' ) + '"]' );
		return input && input.name ? input.name : IC;
	}

	function setError( message ) {
		var id = errorId();

		if ( ! message ) {
			wp.data.dispatch( VALIDATION ).clearValidationError( id );
			return;
		}

		var errors = {};
		errors[ id ] = { message: message, hidden: false };
		wp.data.dispatch( VALIDATION ).setValidationErrors( errors );
	}

	function billingCountry() {
		var customer = wp.data.select( CART ).getCustomerData();
		return customer && customer.billingAddress ? customer.billingAddress.country : '';
	}

	function lookupAres( ico ) {
		return fetch( woolab.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: 'action=ajaxAres&ico=' + encodeURIComponent( ico ) + '&nonce=' + encodeURIComponent( woolab.nonce )
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * With "use same address for billing" ticked, WooCommerce copies the shipping
	 * address onto the billing one on every change, so writing the billing address
	 * alone would be undone straight away — write the one the shopper can see.
	 */
	function fillAddress( ares ) {
		var address = {
			address_1: ares.adresa,
			postcode: ares.psc,
			city: ares.mesto
		};
		var fields = {};

		// The company name is only an address field while the toggle is off; with it
		// on it lives in this plugin's own section (includes/blocks.php).
		if ( COMPANY ) {
			fields[ COMPANY ] = ares.spolecnost;
		} else {
			address.company = ares.spolecnost;
		}

		if ( ares.dic ) {
			fields[ DIC ] = ares.dic;
		}

		if ( wp.data.select( CHECKOUT ).getUseShippingAsBilling() ) {
			wp.data.dispatch( CART ).setShippingAddress( address );
		} else {
			wp.data.dispatch( CART ).setBillingAddress( address );
		}

		if ( Object.keys( fields ).length ) {
			wp.data.dispatch( CHECKOUT ).setAdditionalFields( fields );
		}
	}

	function check( ico ) {

		if ( ! ico ) {
			setError( null );
			return;
		}

		if ( ! verifyIc( ico ) ) {
			setError( woolab.l18n_not_valid );
			return;
		}

		setError( null );

		// ARES is the Czech register; the Slovak ones have no public lookup, which
		// is why ares_check() is only ever consulted for CZ.
		if ( billingCountry() !== 'CZ' || ! woolab.ares_check ) {
			return;
		}

		lookupAres( ico ).then( function ( ares ) {
			if ( ! ares ) {
				setError( woolab.l18n_not_valid );
				return;
			}

			if ( ares.error ) {
				// An ARES outage is not the shopper's fault, and the store may be
				// configured to let it through — same rule as public.js.
				if ( ! ares.internal_error || ! woolab.ignore_check_fail ) {
					setError( ares.error );
				}
				return;
			}

			setError( null );

			if ( woolab.ares_fill ) {
				fillAddress( ares );
			}
		} ).catch( function () {
			setError( woolab.l18n_error );
		} );
	}

	var checkLater = debounce( check, 500 );

	wp.data.subscribe( function () {
		var fields = wp.data.select( CHECKOUT ).getAdditionalFields() || {};
		var ico = String( fields[ IC ] || '' ).replace( /\s+/g, '' );

		if ( ico === lastIco ) {
			return;
		}

		lastIco = ico;
		checkLater( ico );
	} );

}( window.wp, window.wc, window.woolab ) );
