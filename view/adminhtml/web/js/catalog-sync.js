/**
 * "Sync now" next to Catalog on the settings page: sends the catalog through bluebarry/catalog/sync
 * and shows what it answered next to the button. Wired with data-mage-init (no inline script, for the
 * admin's Content Security Policy).
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var button = $(element),
            message = $('#' + config.messageId);

        function show(text, ok) {
            message.text(text).attr('data-ok', ok ? '1' : '0').css('color', ok ? '' : '#e22626');
        }

        button.on('click', function (event) {
            event.preventDefault();
            if (button.prop('disabled')) {
                return;
            }
            button.prop('disabled', true).addClass('disabled');
            message.text('').removeAttr('data-ok');
            $.ajax({
                // isAjax in the address: an expired session or form key then answers JSON, not a page.
                url: config.url + (config.url.indexOf('?') === -1 ? '?' : '&') + 'isAjax=true',
                type: 'POST',
                dataType: 'json',
                data: {form_key: window.FORM_KEY},
                showLoader: true
            }).done(function (response) {
                if (response && typeof response.message === 'string' && response.message !== '') {
                    show(response.message, response.ok === true);
                } else {
                    show(config.failed, false);
                }
            }).fail(function () {
                show(config.failed, false);
            }).always(function () {
                button.prop('disabled', false).removeClass('disabled');
            });
        });
    };
});
