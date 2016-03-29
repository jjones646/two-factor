(function($) {
    $('button.two-factor-register.two-factor-email').click(function() {
        $(this).toggleClass('clicked');
        var e = $('#two-factor-email-options');
        if ($(this).hasClass('clicked')) {
            e.slideDown();
        } else {
            e.slideUp();
        }
    });
})(jQuery);
