(function($) {
    $('button.two-factor-register.two-factor-totp').click(function() {
        $(this).toggleClass('clicked');
        var e = $('#two-factor-totp-options');
        if ($(this).hasClass('clicked')) {
            e.parent().parent().children().last().slideUp(125, function(){
                // e.slideDown();
            });
        } else {
            e.slideUp(125, function(){
                // e.parent().parent().children().last().slideDown();
            });
        }
    });
})(jQuery);
