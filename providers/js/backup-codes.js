/* global bckCodesData */
(function($) {
    $('.two-factor-table .two-factor-option.generate a[href="#"]').click(function(e) {
        // stop the default action on the href link
        e.preventDefault();
        $(this).closest('.two-factor-option').removeClass('generate').addClass('setup');
        var _this = $(this);

        $.ajax({
            method: 'POST',
            dataType: 'JSON',
            url: bckCodesData.ajaxUrl,
            data: {
                _ajax_nonce: bckCodesData.nonce,
                action: bckCodesData.action
            },
            success: function(res) {
                // find the textarea element
                var descRow = _this.closest('tr').children('td.desc')
                var txtarea = descRow.find('textarea');
                var tagline = descRow.find('div.two-factor-option-details');
                // replace the tagline showing the status of the user's codes
                tagline.html(res.data.i18n.title);
                // set the initial value to be a comment header block
                txtarea.val(res.data.i18n.header);
                // write the codes into the textarea, one on every line
                $.each(res.data.codes, function(i, val) {
                    txtarea.val(txtarea.val() + '\r\n' + (i + 1) + ')  ' + val);
                });
                // show the row now, revealing the textarea and download link
                _this.toggleRow();
                _this.html('Delete Codes');
                _this.removeClass('setup').addClass('delete');

                // Build the download link
                var txt_data = 'data:application/text;charset=utf-8,' + txtarea.val();
                $('#two_factor-backup_codes-download_link').attr('href', encodeURI(txt_data));
            }
        });
    });
})(jQuery);
