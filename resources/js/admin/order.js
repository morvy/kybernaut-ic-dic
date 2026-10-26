(function($) {

    $(document).ready(function() {
                                 
        // Country based
        var country = $('#_billing_country');        
        based_on_country( country.val() );

        country.change( function() {            
            based_on_country( country.val() );
        });

        // Orders list: the reverse-charge icon right after the billing address, before
        // WooCommerce's "via (payment method)" line.
        $( '.column-billing_address .woolab-icdic-reverse-charge' ).each( function() {
            $( this ).insertBefore( $( this ).siblings( '.description' ).first() );
        });

        // "Validate again in VIES" (VAT ID check box). An edited VAT ID or country: the
        // order action, run by the order save, so the edited VAT ID is the one checked.
        // Otherwise over AJAX, without saving the order.
        $( '.woolab-icdic-revalidate' ).on( 'click', function() {
            var button = $( this );

            if ( vat_fields_edited() ) {
                $( 'select[name="wc_order_action"]' ).val( button.data( 'action' ) );
                $( 'button.save_order' ).trigger( 'click' );
                return;
            }

            button.prop( 'disabled', true ).after( '<span class="spinner is-active"></span>' );
            $.post( ajaxurl, {
                action: 'woolab_icdic_revalidate',
                order_id: button.data( 'order' ),
                _ajax_nonce: button.data( 'nonce' )
            } ).done( function() {
                window.location.reload();
            } ).fail( function() {
                button.prop( 'disabled', false ).next( '.spinner' ).remove();
                button.closest( 'p' ).after( $( '<p class="woolab-icdic-revalidate-error"></p>' ).text( button.data( 'error' ) ) );
            } );
        });


    });

    // Whether the billing country, DIČ or IČ DPH differ from what the page loaded with.
    function vat_fields_edited() {
        var edited = false;

        $( '#_billing_country, #_billing_billing_dic, #_billing_billing_dic_dph' ).each( function() {
            var field = this;
            var loaded = 'SELECT' === field.tagName
                ? $( field ).find( 'option' ).filter( function() { return this.defaultSelected; } ).val()
                : field.defaultValue;

            if ( undefined !== loaded && ( loaded || '' ) !== ( $( field ).val() || '' ) ) {
                edited = true;
            }
        } );

        return edited;
    }

    function based_on_country( country ) {  

        var dic_dph = $('._billing_billing_dic_dph_field ');

        switch( country ) {
            case 'SK':
                dic_dph.show();
                break;
            case 'CZ':
                dic_dph.hide();
                break;
            default:
                dic_dph.hide();
        }

    }   
	
})( jQuery );