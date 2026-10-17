<?php
/**
 * WP-CLI fixture for Contact Form Fields-extension callout behavior.
 *
 * Usage:
 * wp --path=/var/www/html eval-file wp-content/plugins/mds-contact-form/tests/fields-upsell-fixture.php
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('MDS_Contact_Form')) {
    echo wp_json_encode(['skipped' => 'contact_form_unavailable']) . "\n";
    return;
}

$contact_form = (new ReflectionClass('MDS_Contact_Form'))->newInstanceWithoutConstructor();
$reflection = new ReflectionClass($contact_form);
$call = static function (string $method, array $args = []) use ($contact_form, $reflection) {
    $method_reflection = $reflection->getMethod($method);
    $method_reflection->setAccessible(true);

    return $method_reflection->invokeArgs($contact_form, $args);
};

$failures = [];
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$empty_catalog = ['providers' => [], 'groups' => [], 'fields' => []];
$populated_catalog = [
    'providers' => ['mds-fields' => ['label' => 'Fields']],
    'groups' => [],
    'fields' => [
        'company' => [
            'key' => 'company',
            'label' => 'Company',
            'type' => 'text',
        ],
    ],
];

$assert(true === $call('should_show_fields_upsell', [$empty_catalog]), 'Fields callout should show when no compatible fields are available.');
$assert(false === $call('should_show_fields_upsell', [$populated_catalog]), 'Fields callout should hide when compatible fields are available.');

$html = (string) $call('fields_extension_upsell_html');
$assert(false !== strpos($html, 'mds-contact-fields-callout'), 'Fields callout HTML should include the expected wrapper class.');
$assert(false !== strpos($html, 'Use reusable fields on contact forms'), 'Fields callout should explain the unlocked capability.');
$assert(false !== strpos($html, 'Open Extensions'), 'Fields callout should link to the Extensions page.');
$assert(false !== strpos($html, 'page=mds3-extensions'), 'Fields callout link should target the Million Dollar Script Extensions page.');

echo wp_json_encode([
    'status' => $failures ? 'failed' : 'passed',
    'failures' => $failures,
]) . "\n";
