/* global u2f, u2fL10n */
(function($) {
    setTimeout(function() {
        console.log(u2fL10n);
        u2f.sign(u2fL10n.request, function(data) {
            console.log(data);
            $('#u2f_response').val(JSON.stringify(data));
            $('#loginform').submit();
        });
    }, 5000);
})(jQuery);
