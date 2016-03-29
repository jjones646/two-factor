(function($) {
    $('button.two-factor-register.two-factor-totp').click(function() {
        console.log($(this));
        $(this).toggleClass('clicked');
        $('#two-factor-totp-options').slideToggle();
    });
})(jQuery);
