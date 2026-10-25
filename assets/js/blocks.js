"use strict";

/**
 * Block checkout companion for assets/js/public.js.
 *
 * public.js drives the classic checkout through jQuery and #billing_* selectors,
 * none of which exist in the block checkout. This does the same two jobs against the
 * WooCommerce Blocks data stores instead: check the Business ID as it is typed, and
 * fill the address from ARES (CZ) or the Finančná správa lists (SK).
 *
 * Everything else the classic script does is handled elsewhere here: showing and
 * hiding the fields is a `hidden` rule WooCommerce evaluates itself (includes/blocks.php),
 * and the checkout refresh that revalidates the VAT number happens on its own —
 * WooCommerce pushes changed fields to the draft order and recalculates the totals.
 *
 * @since 2.0.0
 */
(function (wp, wc) {
  if (!wp || !wp.data || !wc || !wc.wcBlocksData || !wc.wcSettings) {
    return;
  }

  // Woolab_Icdic_Blocks_Integration::get_script_data()
  var woolab = wc.wcSettings.getSetting('woolab-ic-dic_data', null);
  if (!woolab || !woolab.fields) {
    return;
  }
  var CHECKOUT = wc.wcBlocksData.CHECKOUT_STORE_KEY;
  var CART = wc.wcBlocksData.CART_STORE_KEY;
  var VALIDATION = wc.wcBlocksData.VALIDATION_STORE_KEY;
  var IC = woolab.fields.ic;
  var DIC = woolab.fields.dic;
  var DIC_DPH = woolab.fields.dic_dph;
  var COMPANY = woolab.fields.company;
  var lastIco = null;
  function debounce(fn, wait) {
    var timeout;
    return function () {
      var args = arguments;
      clearTimeout(timeout);
      timeout = setTimeout(function () {
        fn.apply(null, args);
      }, wait);
    };
  }

  /**
   * The modulo-11 checksum from woolab_icdic_verify_ic() (includes/helpers.php),
   * so a mistyped Business ID is caught before anything is sent anywhere.
   */
  function verifyIc(ico) {
    if (!/^\d{8}$/.test(ico)) {
      return false;
    }
    var sum = 0;
    for (var i = 0; i < 7; i++) {
      sum += parseInt(ico[i], 10) * (8 - i);
    }
    var mod = sum % 11;
    var check = mod === 0 ? 1 : mod === 1 ? 0 : 11 - mod;
    return parseInt(ico[7], 10) === check;
  }

  /**
   * WooCommerce keys validation errors by the location and the field id joined
   * with an underscore, "order_kybernaut/ic"; the element's own id swaps the
   * slash for a dash.
   */
  function icInput() {
    return document.querySelector('[id$="' + IC.replace('/', '-') + '"]');
  }
  function errorId() {
    var input = icInput();
    // WooCommerce 11 also puts the key in the input's name; 10.0 leaves it empty.
    return input && input.name ? input.name : 'order_' + IC;
  }

  /**
   * Spinner in the field while a lookup runs (src/css/lookup.css).
   */
  function busy(on) {
    var input = icInput();
    if (input) {
      input.parentNode.classList.toggle('woolab-ic-dic-loading', on);
      input.setAttribute('aria-busy', on ? 'true' : 'false');
    }
  }

  /**
   * Only an error this script set is cleared: the key is the field's, and with the
   * Business ID required (woolab_icdic_fields_mode()) WooCommerce's own "enter a
   * valid Business ID" is on it too.
   */
  var ownError = false;
  function setError(message) {
    var id = errorId();
    if (!message) {
      if (ownError) {
        wp.data.dispatch(VALIDATION).clearValidationError(id);
      }
      ownError = false;
      return;
    }
    var errors = {};
    errors[id] = {
      message: message,
      hidden: false
    };
    wp.data.dispatch(VALIDATION).setValidationErrors(errors);
    ownError = true;
  }

  /**
   * The billing country as it will be on the order: with "use same address for
   * billing" ticked, the shipping one.
   */
  function billingCountry() {
    var customer = wp.data.select(CART).getCustomerData();
    if (!customer) {
      return '';
    }
    var address = wp.data.select(CHECKOUT).getUseShippingAsBilling() ? customer.shippingAddress : customer.billingAddress;
    return address ? address.country : '';
  }

  /**
   * With "use same address for billing" ticked, WooCommerce copies the shipping
   * address onto the billing one only when the order is placed. Until then the
   * billing country is whatever it was — and the fields' country rules
   * (includes/blocks.php) and the server's VAT exemption read it. Keep it in step.
   */
  function syncBillingCountry() {
    var customer = wp.data.select(CART).getCustomerData();
    if (!customer || !customer.shippingAddress || !customer.billingAddress || !wp.data.select(CHECKOUT).getUseShippingAsBilling()) {
      return;
    }
    if (customer.shippingAddress.country && customer.shippingAddress.country !== customer.billingAddress.country) {
      wp.data.dispatch(CART).setBillingAddress({
        country: customer.shippingAddress.country
      });
    }
  }

  /** GET woolab-ic-dic/v1/lookup (includes/rest.php); a 429 carries the same shape. */
  function lookupAres(ico, country) {
    var url = woolab.rest + 'lookup' + (woolab.rest.indexOf('?') === -1 ? '?' : '&') + 'ico=' + encodeURIComponent(ico) + '&country=' + encodeURIComponent(country);
    return fetch(url).then(function (response) {
      return response.json();
    });
  }

  /**
   * With "use same address for billing" ticked, WooCommerce copies the shipping
   * address onto the billing one on every change, so writing the billing address
   * alone would be undone straight away — write the one the shopper can see.
   */
  /** The DIČ field shown for a billing country (woolab_icdic_blocks_dic_fields()) */
  function dicField(country) {
    return DIC[country] || DIC[''];
  }
  function fillAddress(ares, country) {
    var address = {
      address_1: ares.adresa,
      postcode: ares.psc,
      city: ares.mesto
    };
    var fields = {};

    // The company name is only an address field while the toggle is off; with it
    // on it lives in this plugin's own section (includes/blocks.php).
    if (COMPANY) {
      fields[COMPANY] = ares.spolecnost;
    } else {
      address.company = ares.spolecnost;
    }
    if (ares.dic) {
      fields[dicField(country)] = ares.dic;
    }

    // Finančná správa lists only; null when the subject has none.
    if (ares.dic_dph) {
      fields[DIC_DPH] = ares.dic_dph;
    }
    var shipping = wp.data.select(CHECKOUT).getUseShippingAsBilling();
    var cart = wp.data.dispatch(CART);
    if (shipping) {
      cart.setShippingAddress(address);
    } else {
      cart.setBillingAddress(address);
    }

    // The VAT update the DIČ below triggers would come back with the address the
    // server still has (the Checkout block saves after a pause), and the block
    // would store that over the one just filled: that update carries it.
    if (ares.dic) {
      saveAddress = shipping ? 'shipping' : 'billing';
    }
    if (Object.keys(fields).length) {
      wp.data.dispatch(CHECKOUT).setAdditionalFields(fields);
    }
  }
  function check(ico) {
    // The field is hidden elsewhere and kybernaut/ic-eu has no checks
    // (woolab_icdic_blocks_fields()), so a stored value is no error there.
    if (!ico || woolab.ic_home.indexOf(billingCountry()) === -1) {
      setError(null);
      return;
    }

    // ARES is the Czech register, the Finančná správa lists the Slovak
    // one (includes/financna-sprava.php).
    var country = billingCountry();
    var sk = country === 'SK' && woolab.sk_check;
    var enabled = country === 'CZ' && woolab.ares_check || sk;

    // The Slovak lists hold real IČOs that fail the checksum, so they decide those.
    if (sk ? !/^\d{8}$/.test(ico) : !verifyIc(ico)) {
      setError(woolab.l18n_not_valid);
      return;
    }
    setError(null);
    if (!enabled) {
      return;
    }
    busy(true);
    lookupAres(ico, country).then(function (ares) {
      busy(false);
      if (!ares) {
        setError(woolab.l18n_not_valid);
        return;
      }

      // Not in the Slovak lists yet: accepted, nothing to fill.
      if (ares.unlisted) {
        setError(null);
        return;
      }
      if (ares.error) {
        // An ARES outage is not the shopper's fault, and the store may be
        // configured to let it through — same rule as public.js.
        if (!ares.internal_error || !woolab.ignore_check_fail) {
          setError(ares.error);
        }
        return;
      }
      setError(null);
      if (country === 'SK' ? woolab.sk_fill : woolab.ares_fill) {
        fillAddress(ares, country);
      }
    })["catch"](function () {
      busy(false);
      setError(woolab.l18n_error);
    });
  }
  var checkLater = debounce(check, 500);

  /**
   * VAT exemption while the form is filled: the server decides
   * (woolab_icdic_blocks_update_vat_exempt()) and WooCommerce returns the cart
   * with the new totals. Sent when the billing country, the toggle or a VAT field
   * changes.
   */
  var lastVat = null;
  var sentVat = false;
  var saveAddress = null;
  var updateVat = debounce(function (fields, country) {
    var data = {
      fields: fields,
      country: country
    };

    // The address as it is now, not as ARES filled it: the shopper may have
    // edited it since.
    if (saveAddress) {
      var customer = wp.data.select(CART).getCustomerData();
      data.address = Object.assign({
        type: saveAddress
      }, 'shipping' === saveAddress ? customer.shippingAddress : customer.billingAddress);
      saveAddress = null;
    }
    wc.blocksCheckout.extensionCartUpdate({
      namespace: 'woolab-ic-dic',
      data: data
    });
  }, 800);
  function watchVat() {
    var fields = wp.data.select(CHECKOUT).getAdditionalFields() || {};
    var country = billingCountry();
    var vat = {};
    vat[dicField(country)] = fields[dicField(country)] || '';
    vat[DIC_DPH] = fields[DIC_DPH] || '';
    vat['kybernaut/iscomp'] = !!fields['kybernaut/iscomp'];
    var key = country + '|' + JSON.stringify(vat);
    if (key === lastVat) {
      return;
    }
    lastVat = key;

    // Each update is a full WooCommerce request, on hosting where PHP workers
    // are scarce. Without a VAT number there is nothing to exempt, so only the
    // update that ends an exemption goes out. A VAT number the page opened with
    // is sent too: the server decided on page load from the saved customer
    // details, which hold neither the block checkout's fields nor, with "Use
    // same address for billing", the current billing country.
    var hasVat = !!(vat[dicField(country)] || vat[DIC_DPH]);
    if (hasVat || sentVat) {
      sentVat = hasVat;
      updateVat(vat, country);
    }
  }
  if (woolab.vat_exempt && wc.blocksCheckout && wc.blocksCheckout.extensionCartUpdate) {
    wp.data.subscribe(watchVat, CHECKOUT);
    wp.data.subscribe(watchVat, CART);
  }

  // Company name suggestions: picking one sets the IČO, and the subscriber below
  // looks it up like a typed one.
  if (window.woolabSuggest) {
    window.woolabSuggest({
      rest: woolab.rest,
      selector: COMPANY ? '[id$="' + COMPANY.replace('/', '-') + '"]' : '#billing-company, #shipping-company',
      country: billingCountry,
      enabled: function enabled(country) {
        return country === 'CZ' && woolab.ares_check && woolab.ares_fill || country === 'SK' && woolab.sk_check && woolab.sk_fill;
      },
      pick: function pick(item) {
        var fields = {};
        fields[IC] = item.ico;
        wp.data.dispatch(CHECKOUT).setAdditionalFields(fields);
      }
    });
  }
  wp.data.subscribe(syncBillingCountry, CART);
  wp.data.subscribe(syncBillingCountry, CHECKOUT);
  syncBillingCountry();

  // Not scoped to one store: the IČO is in the checkout store, the billing
  // country that decides whether it is checked in the cart store.
  wp.data.subscribe(function () {
    var fields = wp.data.select(CHECKOUT).getAdditionalFields() || {};
    var ico = String(fields[IC] || '').replace(/\s+/g, '');
    var key = billingCountry() + '|' + ico;
    if (key === lastIco) {
      return;
    }
    lastIco = key;
    checkLater(ico);
  });
})(window.wp, window.wc);