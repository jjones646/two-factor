/* global u2fL10n, u2f */
(function($) {
    $.fn.extend({
        // Slide toggle for the interactions for each row in the table on user profile page
        toggleRow: function() {
            // select the appropiate section to slide in/out of view
            var elem = $(this).closest('td').siblings('td');
            elem.find('div.two-factor-options.two-factor-toggle').slideToggle();
            return this;
        }
    });

    $('button.two-factor-register.two-factor-u2f').click(function() {
        if ($(this).hasClass('clicked')) {
            return false;
        }
        $(this).addClass('clicked').addClass('disabled');

        var _this = $(this);
        var btnTxt = $(this).text();

        $(this).text(u2fL10n.text.insert).append('<span class="spinner is-active" />');
        $('span.spinner.is-active', $(this)).css('margin', '2.5px 0px 0px 5px');

        console.log('entering security key callback');

        setTimeout($.proxy(function() {
            u2f.register([u2fL10n.register.request], u2fL10n.register.sigs, function(data) {
                console.log('u2f.register callback');

                if (data.errorCode) {
                    _this.text(btnTxt);
                    _this.removeClass('clicked');

                    $.each(u2f.ErrorCodes, function(k, v) {
                        if (v === data.errorCode) {
                            window.alert('Registration failed: ' + k + '.');
                            return false;
                        }
                    });
                }

                $('#do_new_security_key').val('true');
                $('#u2f_response').val(JSON.stringify(data));
                // See: http://stackoverflow.com/questions/833032/submit-is-not-a-function-error-in-javascript
                $('<form>')[0].submit.call($('#your-profile')[0]);

                console.log('u2f.register callback complete');
            });
        }, $(this)), 5000);
    });

    // Slide toggle for the 2-Step Verification enable button on user profile page
    $('td button.two-factor-toggle.two-factor-enable').click(function() {
        var elems = $(this).siblings('.two-factor.two-factor-toggle');
        $(this).toggleClass('clicked');

        elems.filter('p').add($(this)).slideUp(250, function() {
            elems.filter('div').slideDown();
        });
    });

    $('.two-factor-table .two-factor-option:not(.generate) a[href="#"]').click(function(e) {
        // stop the default action on the href link
        e.preventDefault();
        $(this).toggleRow();
    });

    $('#two_factor-totp_show_authcode').click(function(e) {
        // stop the default action on the href link
        e.preventDefault();
        // select the element that we'll unhide
        var elem = $(this).closest('.two-factor-flex-wrap').children('div');
        elem.filter('.hide-if-js').slideToggle();
    });

    $('button.two-factor.two-factor-submit').click(function(e) {
        // stop the default action
        e.preventDefault();
    });
})(jQuery);
