<?php
/**
 * Local workflow fixture for Contact Form.
 *
 * Run with:
 * ./scripts/wp eval-file wp-content/plugins/mds-contact-form/tests/mds3-fixture.php
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('MDS_Contact_Form')) {
    fwrite(STDERR, "Contact Form is not active.\n");
    exit(1);
}

wp_set_current_user(1);
global $wpdb;
$form = $GLOBALS['mds_contact_form'] ?? new MDS_Contact_Form();
$suffix = strtolower(wp_generate_password(8, false, false));
$table_name = $wpdb->prefix . 'mds_contact_submissions';
$created_ids = [];
$rate_keys = [];
$sent_messages = [];
$mail_should_fail = false;
$failure = '';
$original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
$missing_option = '__mds_contact_fixture_missing_' . $suffix;
$settings_before = get_option('mds_contact_form_settings', $missing_option);
$custom_settings_before = get_option('mds_contact_form_custom_field_settings', $missing_option);
$mail_filter = static function ($return, $atts) use (&$sent_messages, &$mail_should_fail) {
    $sent_messages[] = [
        'to' => sanitize_email((string) ($atts['to'] ?? '')),
        'subject' => sanitize_text_field((string) ($atts['subject'] ?? '')),
        'message' => (string) ($atts['message'] ?? ''),
    ];

    return !$mail_should_fail;
};
add_filter('pre_wp_mail', $mail_filter, 10, 2);

$set_remote_addr = static function ($ip) use (&$rate_keys) {
    $_SERVER['REMOTE_ADDR'] = $ip;
    $rate_keys[] = 'mds_contact_rate_' . hash_hmac('sha256', $ip, wp_salt('nonce'));
};

$record_submission = static function ($result) use (&$created_ids) {
    if (!is_array($result) || empty($result['submission_id'])) {
        throw new RuntimeException('A valid form submission was not stored.');
    }

    $created_ids[] = absint($result['submission_id']);

    return absint($result['submission_id']);
};

try {
    $form->activate();

    $columns = $wpdb->get_col("SHOW COLUMNS FROM $table_name", 0);
    foreach (['id', 'name', 'email', 'message', 'custom_fields', 'status', 'notification_status'] as $column) {
        if (!in_array($column, $columns, true)) {
            throw new RuntimeException('Contact Form table is missing column: ' . $column);
        }
    }
    if (!wp_next_scheduled('mds_contact_form_cleanup')) {
        throw new RuntimeException('Contact Form retention cleanup was not scheduled.');
    }

    $form->add_admin_menu();
    global $submenu;
    $menu_slugs = array_map(static function ($item) {
        return (string) ($item[2] ?? '');
    }, (array) ($submenu['mds3'] ?? []));
    if (!in_array('mds-contact-form', $menu_slugs, true)) {
        throw new RuntimeException('Contact Form did not register its extension submenu.');
    }

    $onboarding = apply_filters('million-dollar-script/extension/onboarding/items', []);
    if (empty($onboarding['mds-contact-form']['actions']) || empty($onboarding['mds-contact-form']['legal_documents'])) {
        throw new RuntimeException('Contact Form onboarding is incomplete.');
    }
    $legal_content = (string) ($onboarding['mds-contact-form']['legal_documents'][0]['content'] ?? '');
    if (false === strpos($legal_content, 'non-reversible network abuse signals') || false !== strpos($legal_content, 'browser details')) {
        throw new RuntimeException('Contact Form privacy guidance does not match stored request data.');
    }

    $blocks = apply_filters('million-dollar-script/editor/extension/blocks', []);
    $contact_block = null;
    foreach ($blocks as $block) {
        if ('mds-contact-form/form' === (string) ($block['name'] ?? '')) {
            $contact_block = $block;
            break;
        }
    }
    if (!$contact_block || !is_callable($contact_block['render_callback'] ?? null)) {
        throw new RuntimeException('Contact Form editor block is not registered.');
    }

    $form->enqueue_scripts();
    $shortcode = $form->contact_form_shortcode([
        'title' => '<b>Fixture Contact</b>',
        'show_subject' => 'false',
        'button_text' => 'Send fixture',
        'success_message' => 'Fixture sent.',
        'custom_fields' => 'none',
    ]);
    if (false === strpos($shortcode, '&lt;b&gt;Fixture Contact&lt;/b&gt;') || false !== strpos($shortcode, '<script') || false !== strpos($shortcode, '<style')) {
        throw new RuntimeException('Contact Form shortcode escaping or external asset delivery is incomplete.');
    }
    if (false !== strpos($shortcode, '>Subject<') || false === strpos($shortcode, 'data-success-message="Fixture sent."')) {
        throw new RuntimeException('Contact Form shortcode attributes were not honored.');
    }
    if (!wp_script_is('mds-contact-form', 'enqueued') || !wp_style_is('mds-contact-form', 'enqueued')) {
        throw new RuntimeException('Contact Form frontend assets were not enqueued.');
    }

    update_option('mds_contact_form_settings', [
        'notifications_enabled' => 1,
        'notification_email' => 'contact-fixture@example.test',
        'retention_days' => 365,
    ], false);
    update_option('mds_contact_form_custom_field_settings', [
        'mode' => 'none',
        'groups' => [],
        'keys' => [],
    ], false);

    $set_remote_addr('192.0.2.10');
    if (!is_wp_error($form->process_submission(['name' => '', 'email' => '', 'message' => '']))) {
        throw new RuntimeException('Required Contact Form fields should be rejected.');
    }
    if (!is_wp_error($form->process_submission(['name' => 'Fixture', 'email' => 'invalid', 'message' => 'Hello']))) {
        throw new RuntimeException('Invalid Contact Form email should be rejected.');
    }
    if (!is_wp_error($form->process_submission(['honeypot' => 'filled', 'name' => 'Fixture', 'email' => 'fixture@example.test', 'message' => 'Hello']))) {
        throw new RuntimeException('The Contact Form honeypot should reject a submission.');
    }

    $email = 'contact-' . $suffix . '@example.test';
    $submission_id = $record_submission($form->process_submission([
        'name' => '<b>Fixture Person</b>',
        'email' => $email,
        'subject' => 'Fixture subject',
        'message' => "Fixture message\n<script>alert(1)</script>",
        'mds_contact_form_custom_fields' => 'none',
    ]));
    $submission = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $submission_id), ARRAY_A);
    if ('Fixture Person' !== ($submission['name'] ?? '') || '' !== ($submission['ip_address'] ?? '') || '' !== ($submission['user_agent'] ?? '')) {
        throw new RuntimeException('Contact Form did not sanitize the message or avoid raw request metadata.');
    }
    if ('sent' !== ($submission['notification_status'] ?? '') || 1 !== count($sent_messages)) {
        throw new RuntimeException('Contact Form notification success was not recorded.');
    }
    if ('contact-fixture@example.test' !== ($sent_messages[0]['to'] ?? '') || false === strpos((string) ($sent_messages[0]['message'] ?? ''), 'Fixture message')) {
        throw new RuntimeException('Contact Form notification content or recipient is incorrect.');
    }

    $record_submission($form->process_submission(['name' => 'Second', 'email' => 'second-' . $email, 'message' => 'Second message']));
    $record_submission($form->process_submission(['name' => 'Third', 'email' => 'third-' . $email, 'message' => 'Third message']));
    $limited = $form->process_submission(['name' => 'Fourth', 'email' => 'fourth-' . $email, 'message' => 'Fourth message']);
    if (!is_wp_error($limited) || 'mds_contact_form_rate_limit' !== $limited->get_error_code()) {
        throw new RuntimeException('Contact Form throttling should reject the fourth request in ten minutes.');
    }

    $mail_should_fail = true;
    $set_remote_addr('192.0.2.11');
    $failed_id = $record_submission($form->process_submission([
        'name' => 'Mail Failure',
        'email' => 'mail-failure-' . $email,
        'message' => 'Keep this submission when mail fails.',
    ]));
    $failed_status = $wpdb->get_var($wpdb->prepare("SELECT notification_status FROM $table_name WHERE id = %d", $failed_id));
    if ('failed' !== $failed_status) {
        throw new RuntimeException('Contact Form should preserve and mark a submission when email delivery fails.');
    }
    $mail_should_fail = false;

    $exporters = apply_filters('wp_privacy_personal_data_exporters', []);
    $erasers = apply_filters('wp_privacy_personal_data_erasers', []);
    if (!is_callable($exporters['mds-contact-form']['callback'] ?? null) || !is_callable($erasers['mds-contact-form']['callback'] ?? null)) {
        throw new RuntimeException('Contact Form privacy callbacks are not registered.');
    }
    $export = $form->privacy_exporter($email, 1);
    if (1 !== count($export['data'] ?? []) || empty($export['done'])) {
        throw new RuntimeException('Contact Form privacy export did not return the matching submission.');
    }
    $erasure = $form->privacy_eraser($email, 1);
    $erased = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $submission_id), ARRAY_A);
    $erased_custom_fields = json_decode((string) ($erased['custom_fields'] ?? ''), true);
    if (empty($erasure['items_removed']) || 'erased' !== ($erased['status'] ?? '') || '' !== ($erased['ip_address'] ?? '') || !is_array($erased_custom_fields) || !empty($erased_custom_fields)) {
        throw new RuntimeException('Contact Form privacy erasure is incomplete.');
    }
    if (!empty($form->privacy_exporter($email, 1)['data'])) {
        throw new RuntimeException('Erased Contact Form data should not remain discoverable by the original email.');
    }

    $old_email = 'old-' . $email;
    $wpdb->insert($table_name, [
        'name' => 'Old Fixture',
        'email' => $old_email,
        'message' => 'Expired fixture submission.',
        'submitted_at' => '2000-01-01 00:00:00',
        'status' => 'read',
        'notification_status' => 'disabled',
    ]);
    $old_id = absint($wpdb->insert_id);
    $created_ids[] = $old_id;
    update_option('mds_contact_form_settings', [
        'notifications_enabled' => 0,
        'notification_email' => 'contact-fixture@example.test',
        'retention_days' => 3650,
    ], false);
    if ($form->cleanup_expired_submissions() < 1 || $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE id = %d", $old_id))) {
        throw new RuntimeException('Contact Form retention cleanup did not remove an expired submission.');
    }

    $mail_count = count($sent_messages);
    $set_remote_addr('192.0.2.12');
    $disabled_id = $record_submission($form->process_submission([
        'name' => 'No Notice',
        'email' => 'no-notice-' . $email,
        'message' => 'Store without an email notice.',
    ]));
    $disabled_status = $wpdb->get_var($wpdb->prepare("SELECT notification_status FROM $table_name WHERE id = %d", $disabled_id));
    if ('disabled' !== $disabled_status || $mail_count !== count($sent_messages)) {
        throw new RuntimeException('Contact Form notification opt-out was not honored.');
    }
} catch (Throwable $error) {
    $failure = $error->getMessage();
} finally {
    remove_filter('pre_wp_mail', $mail_filter, 10);
    foreach (array_unique($rate_keys) as $rate_key) {
        delete_transient($rate_key);
    }
    foreach (array_unique(array_filter(array_map('absint', $created_ids))) as $submission_id) {
        $wpdb->delete($table_name, ['id' => $submission_id], ['%d']);
    }
    if ($missing_option === $settings_before) {
        delete_option('mds_contact_form_settings');
    } else {
        update_option('mds_contact_form_settings', $settings_before, false);
    }
    if ($missing_option === $custom_settings_before) {
        delete_option('mds_contact_form_custom_field_settings');
    } else {
        update_option('mds_contact_form_custom_field_settings', $custom_settings_before, false);
    }
    if (null === $original_remote_addr) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $original_remote_addr;
    }
}

if ($failure) {
    fwrite(STDERR, "Contact Form fixture failed: " . $failure . "\n");
    exit(1);
}

echo "Success: Contact Form fixture passed.\n";
