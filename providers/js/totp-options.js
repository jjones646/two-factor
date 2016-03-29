(function($) {
    $('button.two-factor-register.two-factor-totp').click(function() {
        $(this).toggleClass('clicked');
        $('#two-factor-totp-options').slideToggle();
    });
})(jQuery);
