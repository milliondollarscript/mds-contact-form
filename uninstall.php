<?php
/** Contact Form uninstall cleanup. */
if (!defined('WP_UNINSTALL_PLUGIN') || !class_exists('\\MillionDollarScript\\Extensions\\CleanupPolicy')) { exit; }
\MillionDollarScript\Extensions\CleanupPolicy::cleanup('mds-contact-form', [
    'tables' => ['mds_contact_submissions'],
    'option_prefixes' => ['mds_contact_form_'],
    'transient_prefixes' => ['mds_contact_', 'mds_contact_form_'],
    'cron_hooks' => ['mds_contact_form_cleanup'],
]);
