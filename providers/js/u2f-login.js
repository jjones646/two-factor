/* global u2f, u2fL10n */
(function($) {
    setTimeout(function() {
        console.log(u2fL10n);
        u2f.sign(u2fL10n.request, function(data) {
            if (data.errorCode) {
                $.each(u2f.ErrorCodes, function(k, v) {
                            if (v === data.errorCode) {
                                window.alert('Login failed: ' + k + '.');
                            }
                        });
            }
            $('#u2f_response').val(JSON.stringify(data));
            $('#loginform').submit();
        });
    }, 5000);
})(jQuery);
