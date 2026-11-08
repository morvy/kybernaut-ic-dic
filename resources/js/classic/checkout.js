/**
 * Classic checkout: the company fields by billing country, the IČO lookup and
 * autofill, the company name suggestions, through jQuery and WooCommerce's
 * checkout events. Settings in window.woolab (src/Assets.php script_data()).
 */
import { anywhere, lookup, service } from '../shared/lookup';
import { busy as busyField, debounce } from '../shared/status';
import suggest from '../shared/suggest';

(function($, woolab) {

    var last_ico_value = '';
    // Whether the company fields hold what the last lookup filled in.
    var filled = false;
    var filled_fields = '#billing_ic, #billing_dic, #billing_dic_dph, #billing_postcode, #billing_city, #billing_address_1';
    // Whether the company fields are emptied, their values kept in stash (not on the
    // inputs: Fluid Checkout re-renders them).
    var company_cleared = false;
    var stash = {};

    $(document).ready(function() {

        $(document.body).on('input', '.woolab-ic-dic-no_spaces input', function() {
            $(this).val(function(_, v){
                return v.replace(/\s+/g, '');
            });
        });

        /** Look the IČO up as it is typed; ares_check() decides whether a register applies */
        if ( anywhere( woolab, 'lookup' ) ) {
            $( document.body ).on( 'focusin', '#billing_ic', function () {
                last_ico_value = $(this).val();
            });
            // Delegated, and the field read again: Fluid Checkout for WooCommerce - Lite
            // re-renders it.
            $( document.body ).on( 'input', '#billing_ic', debounce( function () {
                var ico = $('#billing_ic');
                if ( ico.val() !== last_ico_value ) {
                    ares_check( ico );
                }
            }, 500 ) );
        }

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

        // Delegated: Fluid Checkout re-renders the fields.
        $(document.body).on('input', '#billing_dic, #billing_dic_dph', validateFields);

        // Fluid Checkout re-renders the billing fields after each refresh, hidden again
        // by style.css: show the company fields again when the toggle is on. On a
        // plain checkout nothing changes.
        $(document.body).on('updated_checkout', function () {
            var $toggle = $('#billing_iscomp');
            if ( ! $toggle.length || $toggle.prop('checked') ) {
                show_company_fields( false );
            }
        });

        // The company name stays editable after a lookup. Changing it means another
        // company: drop the IČO and what the lookup filled in, so the shopper can
        // search again or fill the rest by hand.
        $(document.body).on('input', '#billing_company', function () {
            if ( filled ) {
                start_over();
            }
        });

        /** Company name suggestions: picking one looks its IČO up like a typed one */
        if ( anywhere( woolab, 'search' ) ) {
            suggest({
                rest: woolab.rest,
                selector: '#billing_company',
                country: function () {
                    return $("#billing_country").val();
                },
                // Not where the Business ID is hidden (woolab_icdic_ic_countries()).
                enabled: function ( country ) {
                    return !! service( woolab, country ).search && ( ! woolab.ic_countries || woolab.ic_countries.indexOf( country ) !== -1 );
                },
                pick: function ( item ) {
                    $('#billing_ic').val( item.ico );
                    ares_check( $('#billing_ic') );
                }
            });
        }
    });

    /** The billing country's company_lookup service: ARES for CZ, the Slovak lists for SK, a store's own */
    function registry_check() {
        return !! service( woolab, $("#billing_country").val() ).lookup;
    }

    function registry_fill() {
        return !! service( woolab, $("#billing_country").val() ).autofill;
    }

    /** Show/Hide logic for woolab-ic-dic fields */
    function based_on_country() {

        if ( anywhere( woolab, 'autofill' ) ) {
            clear_validation();
        }

        var country = $("#billing_country").val();
        relabel( country );

        show_company_fields( true );
        if ( anywhere( woolab, 'lookup' ) ) {
            // The country decides the register, so look the same IČO up again.
            last_ico_value = '';
            enable_ares_check();
        }
    }

    /** The company fields the billing country has, sliding or at once */
    function show_company_fields( slide ) {
        var country = $("#billing_country").val();
        var show = function ( selector, on ) {
            var $row = $( selector );
            if ( slide ) {
                $row[ on ? 'slideDown' : 'slideUp' ]();
            } else {
                $row.toggle( on );
            }
        };

        // Limited to the countries that have one (woolab_icdic_ic_countries()):
        // hidden elsewhere, and emptied so a hidden value is not submitted.
        var has_ic = ! woolab.ic_countries || woolab.ic_countries.indexOf( country ) !== -1;
        show( "#billing_ic_field", has_ic );
        if ( ! has_ic ) {
            $("#billing_ic").val('');
        }
        show( "#billing_dic_field", true );
        show( "#billing_dic_dph_field", country === 'SK' );
        show( "#billing_company_field", true );
    }

    /**
     * Labels follow the billing country (src/Fields/Labels.php): in SK the DIČ is the
     * tax ID, everywhere else it is the VAT ID, and so do their marks
     * (woolab_icdic_field_mark()). Escaped HTML, from PHP.
     */
    function relabel( country ) {
        var labels       = woolab.labels[ country ] || woolab.labels[''];
        var placeholders = woolab.placeholders[ country ] || woolab.placeholders[''];

        $.each( { billing_ic: 'ic', billing_dic: 'dic', billing_dic_dph: 'dic_dph' }, function ( id, key ) {
            var label = document.querySelector( 'label[for="' + id + '"]' );

            // A field WooCommerce requires keeps its own mark, only the text changes.
            if ( label && $( label ).closest( '.validate-required' ).length ) {
                if ( label.firstChild && label.firstChild.nodeType === Node.TEXT_NODE ) {
                    label.firstChild.nodeValue = $( '<div>' ).html( labels[ key ] ).find( '.optional, .required' ).remove().end().text().trim() + ' ';
                }
            } else {
                $( label ).html( labels[ key ] );
            }
            $( '#' + id ).attr( 'placeholder', placeholders[ key ] );
        });
    }

    function clear_company_data() {
        // Already cleared (e.g. the country changed while unticked): keep what was stashed.
        if ( company_cleared ) {
            return;
        }
        company_cleared = true;
        unmark_filled();
        var country   = $("#billing_country").val();
        var vat_field = country == 'SK' ? 'billing_dic_dph' : 'billing_dic';
        $("#billing_company, #billing_ic, #billing_dic, #billing_dic_dph").each(function(index,el) {
            stash[ el.id ] = el.value;
            if (el.id == vat_field && el.value.length) {
                $(document.body).trigger("update_checkout");
            }
            el.value = '';
        });
    }

    function restore_company_data() {
        company_cleared = false;
        var country   = $("#billing_country").val();
        var vat_field = country == 'SK' ? 'billing_dic_dph' : 'billing_dic';
        $("#billing_company, #billing_ic, #billing_dic, #billing_dic_dph").each(function(index,el) {
            if ( stash[ el.id ] ) {
                el.value = stash[ el.id ];
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
     * on the rest of what the lookup filled in (resources/css/lookup.css).
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

    /**
     * Check the IČO now. The typing handlers are bound once, when the page loads:
     * this runs on every country change, and binding them here stacked one more
     * set of handlers, and one more request per keystroke, each time.
     */
    function enable_ares_check() {
        ares_check( $('#billing_ic') );
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

        var country = $("#billing_country").val();
        var sk = country === 'SK';
        var texts = service( woolab, country );

        var value = ico.val();
        if ( value !== last_ico_value ) {

            var ico_class = $('#billing_ic_field');
            var not_valid = '<span role="alert" class="woolab-ic-dic-tip woolab-ic-dic-message woolab-ic-dic-message--error">'+woolab.l18n_not_valid+'</span>';

            $('.woolab-ic-dic-tip').remove();
            unmark_filled();
            woolab_remove_class_wrong( ico_class );
            woolab_remove_class_ok( ico_class );

            // A Czech or Slovak IČO has 7 or 8 digits; another country's service checks its own numbers.
            if ( country === 'CZ' || sk ? (value.length == 7 || value.length == 8) && value.match(/^[0-9]+$/) != null : value.length > 0 ) {

                var request = {
                    beforeSend: function() {
                        busy( ico, true );
                        ico_class.addClass( 'kbnt-validating' );
                        ico_class.append( '<span role="info" class="woolab-ic-dic-tip woolab-ic-dic-message woolab-ic-dic-message--info">'+texts.validating+'</span>' );
                    },
                    success: function ( data ) {
                        ico_class.removeClass( 'kbnt-validating' );
                        if ( data ) {

                            if ( data.unlisted ) {

                                // Not in the Slovak lists yet: accepted, filled in by hand, so
                                // drop what an earlier lookup filled in.
                                $('.woolab-ic-dic-tip').remove();
                                if ( filled ) {
                                    filled = false;
                                    $('#billing_company, #billing_dic, #billing_dic_dph, #billing_postcode, #billing_city, #billing_address_1').val('');
                                }
                                ares_remove_disabled_from_input();
                                ico_class.append( '<span role="info" class="woolab-ic-dic-tip woolab-ic-dic-message woolab-ic-dic-message--info">'+data.error+'</span>' );

                            } else if ( data.error == false ) {

                                $('.woolab-ic-dic-tip').remove();
                                woolab_add_class_ok( ico_class );

                                if ( registry_fill() ) {

                                    // Fluid Checkout for WooCommerce – Lite with "Same as shipping
                                    // address": its billing address is the shipping one, re-rendered from
                                    // what it saved, so only the company's identifiers are filled in
                                    // (src/Compat/FluidCheckout.php); unticking it bills another address.
                                    var keep_address = !! window.CollapsibleBlock && $('#billing_same_as_shipping').is(':checked');

                                    // Update values
                                    filled = true;
                                    $('#billing_company').val(data.spolecnost);
                                    // Slovak lists (and other services): null when unknown, leave the field to the shopper.
                                    if ( country === 'CZ' || data.dic ) {
                                        $('#billing_dic').val(data.dic).attr('readonly', true);
                                    }
                                    if ( sk && data.dic_dph ) {
                                        $('#billing_dic_dph').val(data.dic_dph).attr('readonly', true);
                                    }
                                    if ( ! keep_address ) {
                                        $('#billing_address_1').val(data.adresa).attr('readonly', true);
                                        $('#billing_postcode').val(data.psc).attr('readonly', true);
                                        $('#billing_city').val(data.mesto).attr('readonly', true);
                                    }
                                    ico_class.append( '<span role="info" class="woolab-ic-dic-tip woolab-ic-dic-message woolab-ic-dic-message--success">'+texts.ok+'</span>' );

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
                                    ico_class.append('<span role="alert" class="woolab-ic-dic-tip woolab-ic-dic-message woolab-ic-dic-message--error error">' + data.error + '</span>');
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
                    // A refusal with a reason (rate limited, register off) is an
                    // answer like any other (shared/lookup.js), not a broken connection.
                    error: function(){
                        if ( $('.woolab-ic-dic-tip').length == 0 ) {
                            ico.val('');
                            ares_error( ico_class );
                            ico_class.append( '<span role="alert" class="woolab-ic-dic-tip woolab-ic-dic-message woolab-ic-dic-message--error error">'+woolab.l18n_error+'</span>' );
                        }
                    }
                };

                // GET woolab-ic-dic/v1/lookup (src/Rest/Routes.php)
                request.beforeSend();
                lookup( woolab.rest, value, country ).then( request.success, request.error ).finally( request.complete );

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

    /** Spinner in the field while a lookup runs (resources/css/lookup.css) */
    function busy( input, on ) {
        if ( input.length ) {
            busyField( input[0], on );
        }
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

    /**
     * The checkout errors of these fields under the field, as WooCommerce puts its
     * own (checkout.js show_inline_errors()). It reads only list notices; with a
     * block theme WooCommerce 10.0 prints a banner instead, which it leaves out.
     * WooCommerce removes these again on the next change and submit.
     */
    // Deferred: WooCommerce triggers the event before it adds its own.
    $( document.body ).on( 'checkout_error', function () {
        setTimeout( add_inline_errors );
    } );

    function add_inline_errors() {
        $( '.woocommerce-NoticeGroup-checkout [data-id]' ).each( function () {
            var id   = $( this ).attr( 'data-id' );
            var $row = $( '#' + id + '_field' );

            if ( ! /^billing_(company|ic|dic|dic_dph)$/.test( id ) || ! $row.length || $row.find( '.checkout-inline-error-message' ).length ) {
                return;
            }

            $( '<p class="checkout-inline-error-message"></p>' ).attr( 'id', id + '_description' ).text( $( this ).text().trim() ).appendTo( $row );
            $( '#' + id ).attr( { 'aria-describedby': id + '_description', 'aria-invalid': 'true' } );
        } );
    }

})( jQuery, window.woolab );
