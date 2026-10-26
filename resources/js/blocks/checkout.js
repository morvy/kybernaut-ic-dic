/**
 * Block checkout companion for the classic checkout script (classic/checkout.js).
 *
 * That one drives the classic checkout through jQuery and #billing_* selectors,
 * none of which exist in the block checkout. This does the same two jobs against the
 * WooCommerce Blocks data stores instead: check the Business ID as it is typed, and
 * fill the address from the country's company service: ARES (CZ), the Finančná
 * správa lists (SK), a store's own (woolab_icdic_services).
 *
 * Everything else the classic script does is handled elsewhere here: showing and
 * hiding the fields is a `hidden` rule WooCommerce evaluates itself (src/Checkout/Blocks.php),
 * and the checkout refresh that revalidates the VAT number happens on its own —
 * WooCommerce pushes changed fields to the draft order and recalculates the totals.
 *
 * @since 2.0.0
 */
import * as data from '@wordpress/data';
import { lookup, service } from '../shared/lookup';
import { busy as busyField, debounce } from '../shared/status';
import suggest from '../shared/suggest';

( function ( wp, wc ) {

	if ( ! wc || ! wc.wcBlocksData || ! wc.wcSettings ) {
		return;
	}

	// Woolab_Icdic_Blocks_Integration::get_script_data()
	var woolab = wc.wcSettings.getSetting( 'woolab-ic-dic_data', null );

	if ( ! woolab || ! woolab.fields ) {
		return;
	}

	var CHECKOUT = wc.wcBlocksData.CHECKOUT_STORE_KEY;
	var CART = wc.wcBlocksData.CART_STORE_KEY;
	var VALIDATION = wc.wcBlocksData.VALIDATION_STORE_KEY;

	var IC = woolab.fields.ic;
	var IC_EU = woolab.fields.ic_eu;
	var DIC = woolab.fields.dic;
	var DIC_DPH = woolab.fields.dic_dph;
	var COMPANY = woolab.fields.company;

	var lastIco = null;

	/**
	 * The modulo-11 checksum from Numbers\Checksum::ic() (src/Numbers/Checksum.php),
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
	 * WooCommerce keys validation errors by the location and the field id joined
	 * with an underscore, "order_kybernaut/ic"; the element's own id swaps the
	 * slash for a dash.
	 */
	function fieldInput( field ) {
		return document.querySelector( '[id$="' + field.replace( '/', '-' ) + '"]' );
	}

	/**
	 * The IČO field shown for a billing country: kybernaut/ic in the IČO home
	 * countries, kybernaut/ic-eu elsewhere ('' when there is none).
	 */
	function icField( country ) {
		return woolab.ic_home.indexOf( country ) !== -1 ? IC : IC_EU;
	}

	function icInput() {
		return fieldInput( icField( billingCountry() ) );
	}

	/**
	 * The validation store key whose error shows under a field.
	 */
	function fieldErrorId( field ) {
		var input = fieldInput( field );
		// WooCommerce 11 also puts the key in the input's name; 10.0 leaves it empty.
		return input && input.name ? input.name : 'order_' + field;
	}

	/**
	 * Spinner in the field while a lookup runs (resources/css/lookup.css).
	 */
	function busy( on ) {
		var input = icInput();
		if ( input ) {
			busyField( input, on );
		}
	}

	/**
	 * The lookup's message under the IČO field, as the classic checkout shows it:
	 * 'info' (validating, not listed) or 'success' (resources/css/lookup.css).
	 * Errors go through the validation store, which shows them in the same place
	 * and keeps the order from being placed.
	 */
	var shown = null;

	function message( type, text ) {
		var input = icInput();

		if ( shown ) {
			shown.remove();
			shown = null;
		}

		if ( ! type || ! text || ! input ) {
			return;
		}

		shown = document.createElement( 'div' );
		shown.className = 'woolab-ic-dic-message woolab-ic-dic-message--' + type;
		shown.setAttribute( 'role', 'status' );
		shown.textContent = text;
		// After the input's wrapper: inside it, the text would push the spinner,
		// centred on the wrapper, off the input.
		input.parentNode.after( shown );
	}

	/**
	 * Only an error this script set is cleared: the key is the field's, and with the
	 * Business ID required (woolab_icdic_fields_mode()) WooCommerce's own "enter a
	 * valid Business ID" is on it too.
	 */
	var ownError = null;

	function setError( message ) {
		var field = icField( billingCountry() );
		var id = message && field ? fieldErrorId( field ) : null;

		// Cleared where it was set: the country may show the other IČO field now.
		if ( ownError && ownError !== id ) {
			wp.data.dispatch( VALIDATION ).clearValidationError( ownError );
		}
		ownError = id;

		if ( ! id ) {
			return;
		}

		var errors = {};
		errors[ id ] = { message: message, hidden: false };
		wp.data.dispatch( VALIDATION ).setValidationErrors( errors );
	}

	/**
	 * The billing country as it will be on the order: with "use same address for
	 * billing" ticked, the shipping one.
	 */
	function billingCountry() {
		var customer = wp.data.select( CART ).getCustomerData();
		if ( ! customer ) {
			return '';
		}
		var address = wp.data.select( CHECKOUT ).getUseShippingAsBilling() ? customer.shippingAddress : customer.billingAddress;
		return address ? address.country : '';
	}

	/**
	 * With "use same address for billing" ticked, WooCommerce copies the shipping
	 * address onto the billing one only when the order is placed. Until then the
	 * billing country is whatever it was — and the fields' country rules
	 * (src/Checkout/Blocks.php) and the server's VAT exemption read it. Keep it in step.
	 */
	function syncBillingCountry() {
		var customer = wp.data.select( CART ).getCustomerData();
		if ( ! customer || ! customer.shippingAddress || ! customer.billingAddress || ! wp.data.select( CHECKOUT ).getUseShippingAsBilling() ) {
			return;
		}
		if ( customer.shippingAddress.country && customer.shippingAddress.country !== customer.billingAddress.country ) {
			wp.data.dispatch( CART ).setBillingAddress( { country: customer.shippingAddress.country } );
		}
	}

	/**
	 * With "use same address for billing" ticked, WooCommerce copies the shipping
	 * address onto the billing one on every change, so writing the billing address
	 * alone would be undone straight away — write the one the shopper can see.
	 */
	/** The DIČ field shown for a billing country (woolab_icdic_blocks_dic_fields()) */
	function dicField( country ) {
		return DIC[ country ] || DIC[ '' ];
	}

	function fillAddress( ares, country ) {
		var address = {
			address_1: ares.adresa,
			postcode: ares.psc,
			city: ares.mesto
		};
		var fields = {};

		// The company name is only an address field while the toggle is off; with it
		// on it lives in this plugin's own section (src/Checkout/Blocks.php).
		if ( COMPANY ) {
			fields[ COMPANY ] = ares.spolecnost;
		} else {
			address.company = ares.spolecnost;
		}

		if ( ares.dic ) {
			fields[ dicField( country ) ] = ares.dic;
		}

		// Finančná správa lists only; null when the subject has none.
		if ( ares.dic_dph ) {
			fields[ DIC_DPH ] = ares.dic_dph;
		}

		var shipping = wp.data.select( CHECKOUT ).getUseShippingAsBilling();
		var cart = wp.data.dispatch( CART );

		if ( shipping ) {
			cart.setShippingAddress( address );
		} else {
			cart.setBillingAddress( address );
		}

		if ( Object.keys( fields ).length ) {
			wp.data.dispatch( CHECKOUT ).setAdditionalFields( fields );
		}
	}

	function check( ico ) {

		// ARES is the Czech register, the Finančná správa lists the Slovak one, a
		// store may plug in its country's (src/Services/Services.php).
		var country = billingCountry();
		var home = woolab.ic_home.indexOf( country ) !== -1;
		var enabled = !! service( woolab, country ).lookup;

		// Outside the IČO home countries kybernaut/ic-eu has no checks
		// (Checkout\Blocks::fields()) unless a service looks companies up there, so a
		// stored value is no error.
		message( null );

		if ( ! ico || ! ( home || enabled ) ) {
			setError( null );
			return;
		}

		// The Slovak lists hold real IČOs that fail the checksum, so they decide
		// those; another country's service checks its own numbers.
		var valid = enabled && country !== 'CZ' ? country !== 'SK' || /^\d{8}$/.test( ico ) : verifyIc( ico );
		if ( ! valid ) {
			setError( woolab.l18n_not_valid );
			return;
		}

		setError( null );

		if ( ! enabled ) {
			return;
		}

		busy( true );
		message( 'info', service( woolab, country ).validating );

		lookup( woolab.rest, ico, country ).then( function ( ares ) {
			busy( false );
			message( null );

			if ( ! ares ) {
				setError( woolab.l18n_not_valid );
				return;
			}

			// Not in the Slovak lists yet: accepted, nothing to fill.
			if ( ares.unlisted ) {
				setError( null );
				message( 'info', ares.error );
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

			if ( service( woolab, country ).autofill ) {
				fillAddress( ares, country );
				message( 'success', service( woolab, country ).ok );
			}
		} ).catch( function () {
			busy( false );
			message( null );
			setError( woolab.l18n_error );
		} );
	}

	var checkLater = debounce( check, 500 );

	/**
	 * VAT exemption while the form is filled: the server decides
	 * (woolab_icdic_blocks_update_vat_exempt()) and WooCommerce returns the cart
	 * with the new totals. Sent when the billing country, the toggle or a VAT field
	 * changes.
	 */
	var lastVat = null;
	var sentVat = false;

	var updateVat = debounce( function ( fields, country ) {
		var customer = wp.data.select( CART ).getCustomerData();

		// The update comes back with the cart, address included, and the block stores
		// that over its own. It saves what was typed only after a pause, so an
		// address just filled in (by ARES, by the browser) would be lost: the update
		// carries it, in the same request.
		var data = {
			fields: fields,
			country: country,
			addresses: [
				Object.assign( { type: 'shipping' }, customer.shippingAddress ),
				Object.assign( { type: 'billing' }, customer.billingAddress )
			]
		};

		wc.blocksCheckout.extensionCartUpdate( {
			namespace: 'woolab-ic-dic',
			data: data
		} );
	}, 800 );

	function watchVat() {
		var fields = wp.data.select( CHECKOUT ).getAdditionalFields() || {};
		var country = billingCountry();
		var vat = {};

		vat[ dicField( country ) ] = fields[ dicField( country ) ] || '';
		vat[ DIC_DPH ] = fields[ DIC_DPH ] || '';
		vat[ 'kybernaut/iscomp' ] = !! fields[ 'kybernaut/iscomp' ];

		var key = country + '|' + JSON.stringify( vat );
		if ( key === lastVat ) {
			return;
		}

		lastVat = key;

		// Each update is a full WooCommerce request, on hosting where PHP workers
		// are scarce. Without a VAT number there is nothing to exempt, so only the
		// update that ends an exemption goes out. A VAT number the page opened with
		// is sent too: the server decided on page load from the saved customer
		// details, which hold neither the block checkout's fields nor, with "Use
		// same address for billing", the current billing country.
		var hasVat = !! ( vat[ dicField( country ) ] || vat[ DIC_DPH ] );
		if ( hasVat || sentVat ) {
			sentVat = hasVat;
			updateVat( vat, country );
		}
	}

	if ( woolab.vat_exempt && wc.blocksCheckout && wc.blocksCheckout.extensionCartUpdate ) {
		wp.data.subscribe( watchVat, CHECKOUT );
		wp.data.subscribe( watchVat, CART );
	}

	// Company name suggestions: picking one sets the IČO, and the subscriber below
	// looks it up like a typed one.
	if ( Object.keys( woolab.services || {} ).some( function ( country ) {
		return woolab.services[ country ].search;
	} ) ) {
		suggest( {
			rest: woolab.rest,
			selector: COMPANY ? '[id$="' + COMPANY.replace( '/', '-' ) + '"]' : '#billing-company, #shipping-company',
			country: billingCountry,
			enabled: function ( country ) {
				return !! service( woolab, country ).search && !! icField( country );
			},
			pick: function ( item ) {
				var fields = {};
				fields[ icField( billingCountry() ) ] = item.ico;
				wp.data.dispatch( CHECKOUT ).setAdditionalFields( fields );
			}
		} );
	}

	wp.data.subscribe( syncBillingCountry, CART );
	wp.data.subscribe( syncBillingCountry, CHECKOUT );
	syncBillingCountry();

	// Not scoped to one store: the IČO is in the checkout store, the billing
	// country that decides whether it is checked in the cart store.
	wp.data.subscribe( function () {
		var fields = wp.data.select( CHECKOUT ).getAdditionalFields() || {};
		var ico = String( fields[ icField( billingCountry() ) ] || '' ).replace( /\s+/g, '' );
		var key = billingCountry() + '|' + ico;

		if ( key === lastIco ) {
			return;
		}

		lastIco = key;
		checkLater( ico );
	} );

	// The order's validation errors (woolab_icdic_blocks_validate()) arrive as
	// notices above the form, their id and context depending on the WooCommerce
	// version ("additional_fields_" + code in wc/cart on 11.2). Each code names its
	// field, so the message goes under the field instead, where WooCommerce shows
	// its own.
	var ERROR_CONTEXTS = [ 'wc/cart', 'wc/checkout', 'wc/checkout/order-information' ];
	wp.data.subscribe( function () {
		ERROR_CONTEXTS.forEach( function ( context ) {
			wp.data.select( 'core/notices' ).getNotices( context ).forEach( function ( notice ) {
				var field = /woolab_icdic:([^:]+):/.exec( notice.id || '' );
				var errors = {};

				if ( ! field || ! fieldInput( field[1] ) ) {
					return;
				}

				errors[ fieldErrorId( field[1] ) ] = { message: notice.content, hidden: false };
				wp.data.dispatch( 'core/notices' ).removeNotice( notice.id, context );
				wp.data.dispatch( VALIDATION ).setValidationErrors( errors );
			} );
		} );
	}, 'core/notices' );

	// Reverse charge, or why there is none (woolab_icdic_blocks_cart_data()).
	var vatNotice = null;
	wp.data.subscribe( function () {
		var extensions = wp.data.select( CART ).getCartData().extensions || {};
		var notice = ( extensions[ 'woolab-ic-dic' ] || {} ).vat_notice || null;

		if ( notice === vatNotice ) {
			return;
		}

		vatNotice = notice;
		if ( notice ) {
			wp.data.dispatch( 'core/notices' ).createNotice( 'info', notice, { id: 'woolab-ic-dic-vat', context: 'wc/checkout', isDismissible: false } );
		} else {
			wp.data.dispatch( 'core/notices' ).removeNotice( 'woolab-ic-dic-vat', 'wc/checkout' );
		}
	}, CART );

}( { data }, window.wc ) );
