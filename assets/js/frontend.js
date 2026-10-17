(function ($) {
    'use strict';

    function responseMessage(response, fallback) {
        if (response && response.data && typeof response.data.message === 'string') {
            return response.data.message;
        }

        return fallback;
    }

    function renderMessage($messages, className, message) {
        $messages.empty().append($('<div>', {
            class: className,
            text: message || ''
        }));
    }

    function validateChoiceGroups(form) {
        var valid = true;

        $(form).find('.mds-form-choice-set[data-required="true"]').each(function () {
            var $group = $(this);
            var inputs = $group.find('input[type="checkbox"], input[type="radio"]').get();
            var firstInput = inputs[0];
            var hasSelection = inputs.some(function (input) {
                return input.checked;
            });

            if (!firstInput) {
                return;
            }

            firstInput.setCustomValidity(hasSelection ? '' : mds_contact_form_ajax.strings.choose_one);
            if (!hasSelection) {
                valid = false;
            }
        });

        return valid;
    }

    $(document).on('change', '.mds-form-choice-set[data-required="true"] input', function () {
        validateChoiceGroups(this.form);
    });

    $(document).on('submit', '.mds-contact-form', function (event) {
        event.preventDefault();

        var form = this;
        var $form = $(form);
        var $button = $form.find('button[type="submit"]').first();
        var $messages = $form.find('.mds-form-messages').first();
        var buttonText = $form.attr('data-button-text') || $button.text();
        var successMessage = $form.attr('data-success-message') || mds_contact_form_ajax.strings.success;

        $messages.empty();
        validateChoiceGroups(form);
        if (!form.checkValidity()) {
            form.reportValidity();
            renderMessage($messages, 'mds-form-error', mds_contact_form_ajax.strings.required_field);
            return;
        }

        $button.prop('disabled', true).text(mds_contact_form_ajax.strings.sending);

        var data = $form.serializeArray();
        data.push({name: 'action', value: 'mds_contact_form_submit'});
        data.push({name: 'nonce', value: mds_contact_form_ajax.nonce});

        $.ajax({
            url: mds_contact_form_ajax.ajax_url,
            type: 'POST',
            data: data
        }).done(function (response) {
            if (response && response.success) {
                renderMessage($messages, 'mds-form-success', successMessage);
                form.reset();
                return;
            }

            renderMessage($messages, 'mds-form-error', responseMessage(response, mds_contact_form_ajax.strings.error));
        }).fail(function () {
            renderMessage($messages, 'mds-form-error', mds_contact_form_ajax.strings.error);
        }).always(function () {
            $button.prop('disabled', false).text(buttonText);
        });
    });
}(jQuery));
