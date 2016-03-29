(function($) {
    $('button.two-factor-unregister.two-factor-backup-codes').click(function() {
        $(this).toggleClass('two-factor-unregister').toggleClass('two-factor-register');
        $(this).toggleClass('clicked');
    });
})(jQuery);
