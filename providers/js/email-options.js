(function($) {
    $('button.two-factor-register.two-factor-totp').click(function() {
        $(this).toggleClass('clicked');
        var e = $('#two-factor-totp-options');
        if ($(this).hasClass('clicked')) {
            e.slideDown();
        } else {
            e.slideUp();
        }
    });
})(jQuery);
