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
   * WooCommerce keys validation errors by the input's `name` — the location and the
   * field id joined with an underscore, "order_kybernaut/ic" — while the element's
   * own id swaps the slash for a dash. Find the element by the id it renders, then
   * take the key off it, so neither convention has to be rebuilt here.
   */
  function icInput() {
    return document.querySelector('[id$="' + IC.replace('/', '-') + '"]');
  }
  function errorId() {
    var input = icInput();
    return input && input.name ? input.name : IC;
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
  function setError(message) {
    var id = errorId();
    if (!message) {
      wp.data.dispatch(VALIDATION).clearValidationError(id);
      return;
    }
    var errors = {};
    errors[id] = {
      message: message,
      hidden: false
    };
    wp.data.dispatch(VALIDATION).setValidationErrors(errors);
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
  function lookupAres(ico, country) {
    return fetch(woolab.ajaxurl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: 'action=ajaxAres&ico=' + encodeURIComponent(ico) + '&country=' + encodeURIComponent(country) + '&nonce=' + encodeURIComponent(woolab.nonce)
    }).then(function (response) {
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
    if (wp.data.select(CHECKOUT).getUseShippingAsBilling()) {
      wp.data.dispatch(CART).setShippingAddress(address);
    } else {
      wp.data.dispatch(CART).setBillingAddress(address);
    }
    if (Object.keys(fields).length) {
      wp.data.dispatch(CHECKOUT).setAdditionalFields(fields);
    }
  }
  function check(ico) {
    if (!ico) {
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
  var updateVat = debounce(function (fields) {
    wc.blocksCheckout.extensionCartUpdate({
      namespace: 'woolab-ic-dic',
      data: {
        fields: fields
      }
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

    // The first run only records the state the page opened with: the server set
    // the exemption from the customer's saved details on page load.
    var first = null === lastVat;
    lastVat = key;
    if (!first) {
      updateVat(vat);
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
      ajaxurl: woolab.ajaxurl,
      nonce: woolab.nonce,
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

  // Scoped to the checkout store: the cart store changes far more often.
  wp.data.subscribe(function () {
    var fields = wp.data.select(CHECKOUT).getAdditionalFields() || {};
    var ico = String(fields[IC] || '').replace(/\s+/g, '');
    if (ico === lastIco) {
      return;
    }
    lastIco = ico;
    checkLater(ico);
  }, CHECKOUT);
})(window.wp, window.wc);