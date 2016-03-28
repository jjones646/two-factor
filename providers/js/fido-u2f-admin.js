/* global u2f, u2fL10n */
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
		console.log($(this));
	});

	$('button.two-factor-toggle.two-factor').click(function() {
		console.log($(this));
		$(this).prop('disabled', true);
		$('td > .two-factor-toggle').slideUp(125, function() {
			$('td > div.two-factor-toggle').slideDown();
		});
	});

	$('button.two-factor-toggle.two-factor-fido-u2f').click(function() {
		$(this).toggleClass('clicked');
		var e = $('div.security-keys > div.two-factor-toggle.two-factor-fido-u2f');
		if ($(this).hasClass('clicked')) {
			e.parent().parent().children().last().slideUp(125, function(){
				e.slideDown();
			});
		} else {
			e.slideUp(250, function(){
				e.parent().parent().children().last().slideDown();
			});
		}
	});
})(jQuery);
