/* global bckCodesData */
(function($) {
    $('.button-two-factor-backup-codes-generate').click(function() {
        $.ajax({
            method: 'POST',
            url: ajaxurl,
            data: {
                action: 'two_factor-backup_codes_generate',
                user_id: bckCodesData.userId ,
                nonce: bckCodesData.nonce
            },
            dataType: 'JSON',
            success: function(response) {
                var $codesList = $('.two-factor-backup-codes-unused-codes');

                $('.two-factor-backup-codes-wrapper').show();
                $codesList.html('');

                // Append the codes.
                for (i = 0; i < response.data.codes.length; i++) {
                    $codesList.append('<li>' + response.data.codes[i] + '</li>');
                }

                // Update counter.
                $('.two-factor-backup-codes-count').html(response.data.i18n.count);

                // Build the download link
                var txt_data = 'data:application/text;charset=utf-8,' + '\n';
                txt_data += response.data.i18n.title.replace(/%s/g, document.domain) + '\n\n';

                for (i = 0; i < response.data.codes.length; i++) {
                    txt_data += i + 1 + '. ' + response.data.codes[i] + '\n';
                }

                $('#two_factor-backup_codes_download_link').attr('href', encodeURI(txt_data));
            }
        });
    });
})(jQuery);
