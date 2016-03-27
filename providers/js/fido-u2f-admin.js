/* global u2f, u2fL10n */
( function( $ ) {
	$( 'button#register_security_key' ).click( function() {
		if ( $(this).hasClass( 'clicked' ) ) {
			return false;
		}
		$(this).addClass( 'clicked' );

		setTimeout( $.proxy( function() {
			var btnTxt = $(this).text();
			$(this).text( u2fL10n.text.insert ).append( '<span class="spinner is-active" />' );
			$( 'span.spinner.is-active', $(this) ).css( 'margin', '2.5px 0px 0px 5px' );

			u2f.register( [ u2fL10n.register.request ], u2fL10n.register.sigs, function( data ) {
				if ( data.errorCode ) {
					alert( 'Registration failed.' , data.errorCode );
					$(this).text( btnTxt );

					return false;
				}

				$( '#do_new_security_key' ).val( 'true' );
				$( '#u2f_response' ).val( JSON.stringify( data ) );
				// See: http://stackoverflow.com/questions/833032/submit-is-not-a-function-error-in-javascript
				$( '<form>' )[0].submit.call( $( '#your-profile' )[0] );
			});
		}, $(this)), 1000);
	});
})( jQuery );
