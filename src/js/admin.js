(function($) {

    $(document).ready(function() {

        // Option => the options it needs: an autofill needs its check, ignoring a
        // failed check needs at least one check.
        var requires = {
            ares_fill: [ 'ares_check' ],
            sk_fill: [ 'sk_check' ],
            ignore_check_fail: [ 'ares_check', 'sk_check', 'vies_check' ]
        };

        // Fields are named woolab_icdic_settings[key] (includes/options.php).
        function field( key ) {
            return $( document.getElementById( 'woolab_icdic_settings[' + key + ']' ) );
        }

        function update() {
            $.each( requires, function ( option, checks ) {
                var on = checks.some( function ( key ) {
                    return field( key ).prop( 'checked' );
                });
                field( option ).prop( 'disabled', ! on );
                if ( ! on ) {
                    field( option ).prop( 'checked', false );
                }
            });
        }

        $.each( [ 'ares_check', 'sk_check', 'vies_check' ], function ( i, key ) {
            field( key ).on( 'change', update );
        });
        update();

    });

})( jQuery );
