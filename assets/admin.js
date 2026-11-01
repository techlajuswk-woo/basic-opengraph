(function ($) {
    'use strict';

    $(function () {
        var frame;
        var $input = $('#bog_logo_id');
        var $preview = $('#bog-logo-preview');
        var $remove = $('#bog-logo-remove');

        $('#bog-logo-select').on('click', function (event) {
            event.preventDefault();

            if (frame) {
                frame.open();
                return;
            }

            frame = wp.media({
                title: 'Select Open Graph image',
                button: { text: 'Use this image' },
                library: { type: 'image' },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                var url = (attachment.sizes && attachment.sizes.medium)
                    ? attachment.sizes.medium.url
                    : attachment.url;

                $input.val(attachment.id);
                $preview.html(
                    $('<img />').attr({
                        src: url,
                        alt: '',
                        style: 'max-width:240px;height:auto;border:1px solid #c3c4c7;border-radius:6px;'
                    })
                );
                $remove.prop('disabled', false);
            });

            frame.open();
        });

        $remove.on('click', function (event) {
            event.preventDefault();
            $input.val('0');
            $preview.html('<em>No image selected.</em>');
            $remove.prop('disabled', true);
        });
    });
})(jQuery);
