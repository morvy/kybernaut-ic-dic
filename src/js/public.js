(function($) {

    var last_ico_value = '';
    // Whether the company fields hold what the last lookup filled in.
    var filled = false;
    var filled_fields = '#billing_ic, #billing_dic, #billing_dic_dph, #billing_postcode, #billing_city, #billing_address_1';

    // Debounce function to replace donetyping
    function debounce(func, wait) {
        let timeout;
        return function (...args) {
            const context = this;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), wait);
        };
    }

    $(document).ready(function() {

        $(document.body).on('input', '.woolab-ic-dic-no_spaces input', function() {
            $(this).val(function(_, v){
                return v.replace(/\s+/g, '');
            });
        });

        /** On init, check if the toggle is present or is set to "on" and run the hiding logic */
        var $fieldToggle = $('#billing_iscomp');
        if ( ! $fieldToggle.length || $fieldToggle.prop("checked") ) {
            restore_company_data();
            based_on_country();
        }

        /** Toggle fields when country is changed or checkbox is toggled */
        $(document.body).on("change", "#billing_country, #billing_iscomp", function () {
            var $fieldToggle = $('#billing_iscomp');
            if ( ! $fieldToggle.length || $fieldToggle.prop("checked") ) {
                restore_company_data();
                based_on_country();
            } else {
                $(".woolab-ic-dic-toggle").slideUp();
                clear_company_data();
            }
        });

        /** Refresh checkout to validate VAT number and VAT exemption */
        var validateFields = debounce(function () {
            var $field = $(this);
            var country = $("#billing_country").val();

            if (country !== "SK" && $field.attr("id") === "billing_dic") {
                $(document.body).trigger("update_checkout");
            }

            if (country === "SK" && $field.attr("id") === "billing_dic_dph") {
                $(document.body).trigger("update_checkout");
            }
        }, 750); // 750ms debounce delay

        $('#billing_dic, #billing_dic_dph').on('input', validateFields);

        // The company name stays editable after a lookup. Changing it means another
        // company: drop the IČO and what the lookup filled in, so the shopper can
        // search again or fill the rest by hand.
        $(document.body).on('input', '#billing_company', function () {
            if ( filled ) {
                start_over();
            }
        });

        /** Company name suggestions: picking one looks its IČO up like a typed one */
        if ( window.woolabSuggest ) {
            woolabSuggest({
                selector: '#billing_company',
                country: function () {
                    return $("#billing_country").val();
                },
                enabled: function ( country ) {
                    return country === 'CZ' && woolab.ares_check && woolab.ares_fill || country === 'SK' && woolab.sk_check && woolab.sk_fill;
                },
                pick: function ( item ) {
                    $('#billing_ic').val( item.ico );
                    ares_check( $('#billing_ic') );
                }
            });
        }
    });

    /** ARES for CZ, the Slovak lists for SK */
    function registry_check() {
        var country = $("#billing_country").val();
        return country === 'CZ' && woolab.ares_check || country === 'SK' && woolab.sk_check;
    }

    function registry_fill() {
        return $("#billing_country").val() === 'SK' ? woolab.sk_fill : woolab.ares_fill;
    }

    /** Show/Hide logic for woolab-ic-dic fields */
    function based_on_country() {

        if (woolab.ares_fill || woolab.sk_fill) {
            clear_validation();
        }

        $("#billing_ic_field").slideDown();
        $("#billing_dic_field").slideDown();

        var country = $("#billing_country").val();
        switch (country) {
            case 'SK':
                $("#billing_dic_dph_field").slideDown();
                $("#billing_dic_field > label").addClass("woolab-ic-dic-required");
                break;
            case 'CZ':
                $("#billing_dic_dph_field").slideUp();
                $("#billing_dic_field > label").removeClass("woolab-ic-dic-required");
                break;
            default:
                $("#billing_dic_dph_field").slideUp();
                $("#billing_dic_field > label").removeClass("woolab-ic-dic-required");
        }
        if (woolab.ares_check || woolab.sk_check) {
            // The country decides the register, so look the same IČO up again.
            last_ico_value = '';
            enable_ares_check();
        }
        $("#billing_company_field").slideDown();
    }

    function clear_company_data() {
        unmark_filled();
        var country   = $("#billing_country").val();
        var vat_field = country == 'SK' ? 'billing_dic_dph' : 'billing_dic';
        $("#billing_company, #billing_ic, #billing_dic, #billing_dic_dph").each(function(index,el) {
            el.setAttribute('data-value', el.value);
            if (el.id == vat_field && el.value.length) {
                $(document.body).trigger("update_checkout");
            }
            el.value = '';
        });
    }

    function restore_company_data() {
        var country   = $("#billing_country").val();
        var vat_field = country == 'SK' ? 'billing_dic_dph' : 'billing_dic';
        $("#billing_company, #billing_ic, #billing_dic, #billing_dic_dph").each(function(index,el) {
            if (el.getAttribute('data-value')) {
                el.value = el.getAttribute('data-value');
                if (el.id == vat_field && el.value.length) {
                    $(document.body).trigger("update_checkout");
                }
            }
        });
    }

    /** Forget what the last lookup filled in */
    function start_over() {
        filled = false;
        last_ico_value = '';
        $(filled_fields).val('');
        ares_remove_disabled_from_input();
        $('.woolab-ic-dic-tip').remove();
        woolab_remove_class_ok( $('#billing_ic_field') );
    }

    /**
     * Green padlock on the company name and IČO (its X clears them), a checkmark
     * on the rest of what the lookup filled in (src/css/lookup.css).
     */
    function mark_filled() {
        unmark_filled();
        $('#billing_company, #billing_ic').each(function () {
            $(this).parent().addClass( 'woolab-ic-dic-verified' ).append(
                $('<button type="button" class="woolab-ic-dic-lock"></button>')
                    .attr({ 'aria-label': woolab.l18n_clear, title: woolab.l18n_clear })
                    // Bound here, not delegated: the click does not reach the body
                    // on every theme.
                    .on('click', clear_company)
            );
        });
        // Locked by the lookup; a DIČ the Slovak lists do not have stays the shopper's.
        $('#billing_dic, #billing_dic_dph, #billing_address_1, #billing_postcode, #billing_city').filter(function () {
            return this.readOnly;
        }).each(function () {
            $(this).parent().addClass( 'woolab-ic-dic-verified' ).append( '<span class="woolab-ic-dic-check" aria-hidden="true"></span>' );
        });
    }

    /** The padlock's X: clear the company details, the company name included */
    function clear_company() {
        start_over();
        $('#billing_company').val('').trigger('focus');
        $('body').trigger('update_checkout');
    }

    function unmark_filled() {
        $('.woolab-ic-dic-lock, .woolab-ic-dic-check').remove();
        $('.woolab-ic-dic-verified').removeClass( 'woolab-ic-dic-verified' );
    }

    function clear_validation() {
        $('.woolab-ic-dic-tip').remove();
        ares_remove_disabled_from_input();
    }

    function woolab_remove_class_ok ( selector ) {
        selector.removeClass( 'kbnt-ok' ).removeClass( 'woocommerce-validated' );
    }
    function woolab_add_class_ok ( selector ) {
        selector.addClass( 'kbnt-ok' ).addClass( 'woocommerce-validated' ).removeClass( 'woocommerce-invalid' );
    }

    function woolab_remove_class_wrong ( selector ) {
        selector.removeClass( 'kbnt-wrong' ).removeClass( 'woocommerce-invalid' );
    }
    function woolab_add_class_wrong ( selector ) {
        selector.addClass( 'kbnt-wrong' ).addClass( 'woocommerce-invalid' ).removeClass( 'woocommerce-validated' );
    }

    function enable_ares_check() {

        var ico = $('#billing_ic');
        ares_check( ico );
        $( document.body ).on('focusin', '#billing_ic', function() {
            last_ico_value = $('#billing_ic').val();
        });
        var validateBillingIC = debounce(function () {
            var ico = $('#billing_ic'); // Because of Fluid Checkout for WooCommerce - Lite compatibility
            if (ico.val() !== last_ico_value) {
                ares_check(ico);
            }
        }, 500); // 500ms debounce delay
        $('#billing_ic').on('input', validateBillingIC);
    }

    function ares_remove_disabled_from_input() {
        unmark_filled();
        $('#billing_company').removeAttr("readonly");
        $('#billing_dic').removeAttr("readonly");
        $('#billing_dic_dph').removeAttr("readonly");
        $('#billing_postcode').removeAttr("readonly");
        $('#billing_city').removeAttr("readonly");
        $('#billing_address_1').removeAttr("readonly");
    }

    function ares_check( ico ) {

        if ( ! registry_check() ) {
            return;
        }

        var sk = $("#billing_country").val() === 'SK';

        var value = ico.val();
        if ( value !== last_ico_value ) {

            var ico_class = $('#billing_ic_field');
            var not_valid = '<span role="alert" class="woolab-ic-dic-tip">'+woolab.l18n_not_valid+'</span>';

            $('.woolab-ic-dic-tip').remove();
            unmark_filled();
            woolab_remove_class_wrong( ico_class );
            woolab_remove_class_ok( ico_class );

            if ( (value.length == 7 || value.length == 8) && value.match(/^[0-9]+$/) != null ) {

                $.ajax({
                    url: woolab.ajaxurl,
                    data: {
                        action: "ajaxAres",
                        'ico' : value,
                        'country' : sk ? 'SK' : 'CZ',
                        'nonce' : woolab.nonce,
                    },
                    beforeSend: function() {
                        busy( ico, true );
                        ico_class.addClass( 'kbnt-validating' );
                        ico_class.append( '<span role="info" class="woolab-ic-dic-tip">'+( sk ? woolab.l18n_validating_sk : woolab.l18n_validating )+'</span>' );
                    },
                    success: function ( data ) {
                        ico_class.removeClass( 'kbnt-validating' );
                        if ( data ) {
                            // The endpoint responds with a JSON content type, so
                            // jQuery may have parsed the payload already.
                            if ( typeof data === 'string' ) {
                                data = JSON.parse( data );
                            }

                            if ( data.unlisted ) {

                                // Not in the Slovak lists yet: accepted, filled in by hand, so
                                // drop what an earlier lookup filled in.
                                $('.woolab-ic-dic-tip').remove();
                                if ( filled ) {
                                    filled = false;
                                    $('#billing_company, #billing_dic, #billing_dic_dph, #billing_postcode, #billing_city, #billing_address_1').val('');
                                }
                                ares_remove_disabled_from_input();
                                ico_class.append( '<span role="info" class="woolab-ic-dic-tip">'+data.error+'</span>' );

                            } else if ( data.error == false ) {

                                $('.woolab-ic-dic-tip').remove();
                                woolab_add_class_ok( ico_class );

                                if ( registry_fill() ) {

                                    // Compatibility with Fluid Checkout for WooCommerce – Lite
                                    // https://wordpress.org/support/topic/compatibility-with-kybernaut-ico-dic-plugin/
                                    if ($('#billing_same_as_shipping') && $('#billing_same_as_shipping').is(':checked')) {

                                        // Check whether the CollapsibleBlock library is available
                                        if ( window.CollapsibleBlock ) {

                                            // Set billing address as different from shipping address
                                            var fc_billing_same_as_shipping_field = document.querySelector( '#billing_same_as_shipping' );
                                            fc_billing_same_as_shipping_field.checked = false;

                                            // Expand the billing address fields
                                            var fc_billing_address_fields_wrapper = document.querySelector( '#woocommerce-billing-fields__field-wrapper' );
                                            if ( fc_billing_address_fields_wrapper ) {
                                                CollapsibleBlock.expand( fc_billing_address_fields_wrapper );
                                            }

                                            // Get company field toggle and content elements
                                            var fc_billing_company_toggle = document.querySelector( '#fc-expansible-form-section__toggle-plus--billing_company' );
                                            var fc_billing_company_content = document.querySelector( '#fc-expansible-form-section__content--billing_company' );

                                            // Expand the billing company field
                                            if ( fc_billing_company_toggle ) { CollapsibleBlock.collapse( fc_billing_company_toggle ); }
                                            if ( fc_billing_company_content ) { CollapsibleBlock.expand( fc_billing_company_content ); }

                                            // Get dic field toggle and content elements
                                            var fc_billing_dic_toggle = document.querySelector( '#fc-expansible-form-section__toggle-plus--billing_dic' );
                                            var fc_billing_dic_content = document.querySelector( '#fc-expansible-form-section__content--billing_dic' );

                                            // Expand the billing dic field
                                            if ( fc_billing_dic_toggle ) { CollapsibleBlock.collapse( fc_billing_dic_toggle ); }
                                            if ( fc_billing_dic_content ) { CollapsibleBlock.expand( fc_billing_dic_content ); }

                                        }

                                    }

                                    // Update values
                                    filled = true;
                                    $('#billing_company').val(data.spolecnost);
                                    // Slovak lists: null when unknown, leave the field to the shopper.
                                    if ( ! sk || data.dic ) {
                                        $('#billing_dic').val(data.dic).attr('readonly', true);
                                    }
                                    if ( sk && data.dic_dph ) {
                                        $('#billing_dic_dph').val(data.dic_dph).attr('readonly', true);
                                    }
                                    $('#billing_address_1').val(data.adresa).attr('readonly', true);
                                    $('#billing_postcode').val(data.psc).attr('readonly', true);
                                    $('#billing_city').val(data.mesto).attr('readonly', true);
                                    ico_class.append( '<span role="info" class="woolab-ic-dic-tip">'+( sk ? woolab.l18n_ok_sk : woolab.l18n_ok )+'</span>' );

                                    mark_filled();

                                    // Trigger the checkout update
                                    $('body').trigger('update_checkout');

                                }

                            } else {

                                ares_error( ico_class );
                                if ( $('.woolab-ic-dic-tip').length > 0 ) {
                                    $('.woolab-ic-dic-tip').remove();
                                }

                                ares_remove_disabled_from_input();

                                if ( ! data.internal_error || ! woolab.ignore_check_fail ) {
                                    ico_class.append('<span role="alert" class="woolab-ic-dic-tip error">' + data.error + '</span>');
                                }

                            }

                        } else {
                            ares_error( ico_class );
                            if ( $('.woolab-ic-dic-tip').length == 0 ) {
                                ares_remove_disabled_from_input();
                                ico_class.append( not_valid );
                            }
                        }


                    },
                    complete: function () {
                        busy( ico, false );
                    },
                    error: function(errorThrown){
                        if ( $('.woolab-ic-dic-tip').length == 0 ) {
                            ico.val('');
                            ares_error( ico_class );
                            ico_class.append( '<span role="alert" class="woolab-ic-dic-tip error">'+woolab.l18n_error+'</span>' );
                        }
                    }
                });

            } else {
                ares_remove_disabled_from_input();
                if ( value.length > 0 ) {
                    woolab_add_class_wrong( ico_class );
                } else {
                    woolab_remove_class_wrong( ico_class );
                }
            }
            last_ico_value = ico.val();
        }
    }

    /** Spinner in the field while a lookup runs (src/css/lookup.css) */
    function busy( input, on ) {
        input.parent().toggleClass( 'woolab-ic-dic-loading', on );
        input.attr( 'aria-busy', on ? 'true' : 'false' );
    }

    function ares_error ( ico_class ) {

        if ( registry_fill() ) {
            if ( ! woolab.ignore_check_fail ) {
                $('#billing_company').val('');
                $('#billing_dic').val('');
                $('#billing_dic_dph').val('');
                $('#billing_postcode').val('');
                $('#billing_city').val('');
                $('#billing_address_1').val('');
            }

            ares_remove_disabled_from_input();
            filled = false;
        }

        woolab_add_class_wrong( ico_class );
    }

})( jQuery );
