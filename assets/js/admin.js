(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches('.mds-contact-delete-submission')) {
            return;
        }

        if (!window.confirm(mdsContactFormAdmin.confirmDelete)) {
            event.preventDefault();
        }
    });
}());
