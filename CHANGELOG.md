# Changelog

## 1.0.7 - 2026-10-21
- Corrected the version the plugin reports, so its listings, its assets, and its built-in documentation all name the installed build.

## 1.0.6 - 2026-10-21
- Tested with WordPress 7.1.3 and WooCommerce 11.2.1, and the plugin states the exact versions it was tested with, so WordPress and WooCommerce no longer report it as untested on a current site.
- Added opt-in uninstall cleanup through the core extension policy for submissions, settings, transients, and scheduled cleanup.

## 1.0.5 - 2026-07-13
- Open the workspace on Submissions and relabel the instruction-only Overview tab as Usage.
- Added an accessible label and active-page state to the existing Overview, Settings, and Submissions workspace tabs.
- Fixed custom text fields without a configured limit incorrectly rendering with `maxlength="0"`.
- Added bounded server validation for base fields, custom values, choice lists, and administrator-provided patterns.
- Restricted URL fields to credential-free HTTP and HTTPS addresses and completed number, date, and color validation.
- Improved radio and checkbox accessibility with labeled fieldsets and required-group browser validation.

## 1.0.4 - 2026-07-13
- Added configurable notification email, email enablement, and automatic submission retention settings.
- Added readable submission details, read/unread controls, permanent deletion, email delivery status, and failed-notification retry controls.
- Replaced stored raw IP addresses and browser user agents with short-lived, non-reversible throttling keys; existing request metadata is cleared during the database upgrade.
- Moved frontend and admin styles and scripts into versioned assets, improved keyboard and screen-reader feedback, and added a mobile submission layout.
- Added a WordPress workflow fixture covering submission validation, email success and failure, throttling, retention, privacy export and erasure, blocks, onboarding, and admin registration.

## 1.0.3 - 2026-06-05
- Added a restrained Fields extension callout on Contact Form settings when no compatible custom-field provider is active.
- Linked the callout to the Million Dollar Script Extensions page and added fixture coverage for active and inactive Fields states.

## 1.0.2 - 2026-06-05
- Added reusable custom-field support through provider hooks.
- Added Fields integration support for text, textarea, email, URL, phone, number, select, radio, checkbox, date, and color fields.
- Added Contact Form settings for default custom-field mode, selected groups, and selected fields.
- Stored custom-field submissions with labels and formatted values for admin views, email notifications, and privacy exports.
- Added privacy erasure support for stored Contact Form submissions.

## 1.0.1 - 2026-04-25
- Added MDS 3.0 admin-menu compatibility.
- Hardened AJAX submission nonce handling, request sanitization, IP throttling data, and admin output escaping.

## 0.1.0 - 2025-01-01
- Initial development version.
