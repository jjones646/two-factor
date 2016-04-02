/* global  u2fL10n */
(function($) {
    $('button.two-factor-register.two-factor-fido-u2f').click(function() {
        if ($(this).hasClass('clicked')) {
            return false;
        }
        $(this).addClass('clicked');

        var _this = $(this);
        var btnTxt = $(this).text();

        $(this).text(u2fL10n.text.insert).append('<span class="spinner is-active" />');
        $('span.spinner.is-active', $(this)).css('margin', '2.5px 0px 0px 5px');

        setTimeout($.proxy(function() {
            u2f.register([u2fL10n.register.request], u2fL10n.register.sigs, function(data) {
                if (data.errorCode) {
                    _this.text(btnTxt);
                    _this.removeClass('clicked');

                    $.each(u2f.ErrorCodes, function(k, v) {
                        if (v == data.errorCode) {
                            alert('Registration failed: ' + k + '.');
                            return false;
                        }
                    });
                }

                $('#do_new_security_key').val('true');
                $('#u2f_response').val(JSON.stringify(data));
                // See: http://stackoverflow.com/questions/833032/submit-is-not-a-function-error-in-javascript
                $('<form>')[0].submit.call($('#your-profile')[0]);
            });
        }, $(this)), 1000);
    });

    $('button.two-factor-toggle.two-factor').click(function() {
        $(this).prop('disabled', true);
        $('td > .two-factor-toggle').slideUp(125, function() {
            $('td > div.two-factor-toggle').slideDown();
        });
    });

    $('.two-factor-table .two-factor-option a').click(function(e) {
        // stop the default action on the href link
        e.preventDefault();

        var elem = $(this).closest('td').siblings('td');
        // console.log(elem);
        elem = elem.find('div.two-factor-options.two-factor-toggle');
        // console.log(elem);
        elem.slideToggle();
    });

    $('button.two-factor-toggle.two-factor-fido-u2f').click(function() {
        var e = $(this).closest('div.two-factor-toggle.two-factor-wrap').children('div.two-factor-toggle:last-child')

        $(this).toggleClass('clicked');
        e.slideToggle();
    });
})(jQuery);