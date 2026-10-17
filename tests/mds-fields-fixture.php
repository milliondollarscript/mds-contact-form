<?php
/**
 * WP-CLI fixture for Contact Form custom fields supplied by Fields.
 *
 * Usage:
 * wp --path=/var/www/html eval-file wp-content/plugins/mds-contact-form/tests/mds-fields-fixture.php
 */

use MillionDollarScript\Extensions\Fields\Field_Management\Manager;
use MillionDollarScript\Extensions\Fields\Utils\Database;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('MDS_Contact_Form') || !class_exists(Manager::class) || !class_exists(Database::class)) {
    throw new RuntimeException('Contact Form custom fields fixture requires Contact Form and Fields to be active.');
}

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
if (!$admin) {
    throw new RuntimeException('Contact Form custom fields fixture requires an administrator user.');
}
wp_set_current_user(absint($admin[0]));

function mds_contact_form_fixture_call($instance, string $method, array $args = []) {
    $reflection = new ReflectionClass($instance);
    $method_reflection = $reflection->getMethod($method);
    $method_reflection->setAccessible(true);

    return $method_reflection->invokeArgs($instance, $args);
}

$previous_settings = get_option('mds_contact_form_custom_field_settings', null);
$inserted_submission_id = 0;
$previous_field_rows = [];
$fixture_field_keys = ['custom_name', 'custom_email'];
$contact_form = (new ReflectionClass('MDS_Contact_Form'))->newInstanceWithoutConstructor();

try {
    Database::instance()->create_tables();
    $contact_form->create_database_table();

    global $wpdb;
    $fields_table = $wpdb->prefix . 'mds_custom_fields';
    foreach ($fixture_field_keys as $field_key) {
        $previous_field_rows[$field_key] = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . $fields_table . ' WHERE field_key = %s', $field_key),
            ARRAY_A
        );
        $wpdb->delete($fields_table, ['field_key' => $field_key]);
    }

    $wpdb->insert($fields_table, [
        'field_key' => 'custom_name',
        'field_label' => 'Full Name',
        'field_type' => 'text',
        'field_options' => wp_json_encode(['placeholder' => 'Enter your full name']),
        'field_validation' => wp_json_encode(['required' => true]),
        'field_order' => 1,
        'field_group_id' => null,
        'is_required' => 1,
        'is_active' => 1,
        'show_in_popup' => 1,
    ]);
    $wpdb->insert($fields_table, [
        'field_key' => 'custom_email',
        'field_label' => 'Email Address',
        'field_type' => 'email',
        'field_options' => wp_json_encode(['placeholder' => 'Enter your email']),
        'field_validation' => wp_json_encode(['required' => true, 'email' => true]),
        'field_order' => 2,
        'field_group_id' => null,
        'is_required' => 1,
        'is_active' => 1,
        'show_in_popup' => 1,
    ]);

    Manager::instance()->refresh_fields();

    update_option(
        'mds_contact_form_custom_field_settings',
        [
            'mode' => 'fields',
            'groups' => [],
            'keys' => ['custom_name', 'custom_email'],
        ],
        false
    );

    $catalog = apply_filters('million-dollar-script/extensions/contact-form/custom/field/catalog', [
        'providers' => [],
        'groups' => [],
        'fields' => [],
    ]);

    if (empty($catalog['fields']['custom_name']) || empty($catalog['fields']['custom_email'])) {
        throw new RuntimeException('Fields did not expose expected Contact Form field catalog entries.');
    }

    ob_start();
    mds_contact_form_fixture_call($contact_form, 'display_settings');
    $settings_html = ob_get_clean();
    if (false !== strpos($settings_html, 'mds-contact-fields-callout')) {
        throw new RuntimeException('Contact Form showed the Fields upsell even though Fields exposed compatible fields.');
    }

    $html = do_shortcode('[mds_contact_form custom_fields="fields" field_keys="custom_name,custom_email"]');
    if (
        false === strpos($html, 'name="mds_contact_fields[custom_name]"') ||
        false === strpos($html, 'name="mds_contact_fields[custom_email]"') ||
        false === strpos($html, 'Additional details')
    ) {
        throw new RuntimeException('Contact Form did not render selected Fields definitions.');
    }

    $context_token = '';
    $html_processor = new WP_HTML_Tag_Processor($html);
    while ($html_processor->next_tag('input')) {
        if ('mds_contact_form_field_context_token' === $html_processor->get_attribute('name')) {
            $context_token = (string) $html_processor->get_attribute('value');
            break;
        }
    }
    if ('' === $context_token) {
        throw new RuntimeException('Contact Form did not sign the custom-field context.');
    }

    $valid_submission = mds_contact_form_fixture_call($contact_form, 'prepare_custom_field_submission', [[
        'mds_contact_form_custom_fields' => 'fields',
        'mds_contact_form_field_keys' => 'custom_name,custom_email',
        'mds_contact_form_field_context_token' => $context_token,
        'mds_contact_fields' => [
            'custom_name' => 'Ada Lovelace',
            'custom_email' => 'ada@example.test',
        ],
    ]]);

    if (is_wp_error($valid_submission)) {
        throw new RuntimeException('Contact Form rejected valid Fields values: ' . $valid_submission->get_error_message());
    }

    if ('Ada Lovelace' !== (string) ($valid_submission['custom_name']['value'] ?? '')) {
        throw new RuntimeException('Contact Form did not normalize the custom text field.');
    }

    if ('ada@example.test' !== sanitize_email((string) ($valid_submission['custom_email']['value'] ?? ''))) {
        throw new RuntimeException('Contact Form did not normalize the custom email field.');
    }

    $forged_submission = mds_contact_form_fixture_call($contact_form, 'prepare_custom_field_submission', [[
        'mds_contact_form_custom_fields' => 'fields',
        'mds_contact_form_field_keys' => 'custom_name,custom_email',
        'mds_contact_form_field_context_token' => str_repeat('0', 64),
        'mds_contact_fields' => [
            'custom_name' => 'Forged Name',
            'custom_email' => 'forged@example.test',
        ],
    ]]);
    if (!is_array($forged_submission) || !empty($forged_submission)) {
        throw new RuntimeException('Contact Form accepted a forged custom-field context.');
    }

    $invalid_submission = mds_contact_form_fixture_call($contact_form, 'prepare_custom_field_submission', [[
        'mds_contact_form_custom_fields' => 'fields',
        'mds_contact_form_field_keys' => 'custom_name,custom_email',
        'mds_contact_form_field_context_token' => $context_token,
        'mds_contact_fields' => [
            'custom_name' => 'Ada Lovelace',
            'custom_email' => 'not-an-email',
        ],
    ]]);

    if (!is_wp_error($invalid_submission)) {
        throw new RuntimeException('Contact Form accepted an invalid Fields email value.');
    }

    $email_text = mds_contact_form_fixture_call($contact_form, 'format_custom_fields_for_email', [$valid_submission]);
    if (false === strpos($email_text, 'Full Name: Ada Lovelace') || false === strpos($email_text, 'Email Address: ada@example.test')) {
        throw new RuntimeException('Contact Form did not format custom fields for email notifications.');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'mds_contact_submissions';
    $inserted = $wpdb->insert($table_name, [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'subject' => 'Fixture',
        'message' => 'Testing custom fields.',
        'custom_fields' => wp_json_encode($valid_submission),
        'ip_address' => '',
        'user_agent' => '',
        'submitted_at' => current_time('mysql'),
        'status' => 'unread',
        'notification_status' => 'disabled',
    ]);

    if (!$inserted) {
        throw new RuntimeException('Could not insert Contact Form fixture submission.');
    }

    $inserted_submission_id = absint($wpdb->insert_id);

    $export = $contact_form->privacy_exporter('ada@example.test');
    $export_json = wp_json_encode($export);
    if (empty($export['data']) || false === strpos($export_json, 'Ada Lovelace') || false === strpos($export_json, 'ada@example.test')) {
        throw new RuntimeException('Contact Form privacy exporter did not include custom field values.');
    }

    $admin_html = mds_contact_form_fixture_call($contact_form, 'format_custom_fields_for_admin', [wp_json_encode($valid_submission)]);
    if (false === strpos($admin_html, 'Full Name') || false === strpos($admin_html, 'Ada Lovelace')) {
        throw new RuntimeException('Contact Form did not format custom fields for admin display.');
    }

    echo wp_json_encode([
        'catalog' => true,
        'rendered' => true,
        'validated' => true,
        'stored' => true,
        'privacy_export' => true,
    ]) . PHP_EOL;
} finally {
    global $wpdb;

    if ($inserted_submission_id) {
        $wpdb->delete($wpdb->prefix . 'mds_contact_submissions', ['id' => $inserted_submission_id]);
    }

    if (null === $previous_settings) {
        delete_option('mds_contact_form_custom_field_settings');
    } else {
        update_option('mds_contact_form_custom_field_settings', $previous_settings, false);
    }

    $fields_table = $wpdb->prefix . 'mds_custom_fields';
    foreach ($fixture_field_keys as $field_key) {
        $wpdb->delete($fields_table, ['field_key' => $field_key]);
        if (!empty($previous_field_rows[$field_key]) && is_array($previous_field_rows[$field_key])) {
            $wpdb->insert($fields_table, $previous_field_rows[$field_key]);
        }
    }
    if (class_exists(Manager::class)) {
        Manager::instance()->refresh_fields();
    }
}
