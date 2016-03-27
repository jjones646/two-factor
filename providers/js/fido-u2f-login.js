/* global u2f, u2fL10n */
var u2fL10n;
( function( $ ) {
	setTimeout( function() {
		u2f.sign( u2fL10n.request, function( data ) {
			$( '#u2f_response' ).val( JSON.stringify( data ) );
			$( '#loginform' ).submit();
		} );
	}, 1000 );
} )( jQuery );
