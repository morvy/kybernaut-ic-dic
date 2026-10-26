(function($) {

    $(document).ready(function() {
                                 
        // Country based
        var country = $('#_billing_country');        
        based_on_country( country.val() );

        country.change( function() {            
            based_on_country( country.val() );
        });

        // "Validate again in VIES" (VAT ID check box): the order action, run by the
        // order save so that a VAT ID edited with it is checked.
        $( '.woolab-icdic-revalidate' ).on( 'click', function() {
            $( 'select[name="wc_order_action"]' ).val( $( this ).data( 'action' ) );
            $( 'button.save_order' ).trigger( 'click' );
        });


    });

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