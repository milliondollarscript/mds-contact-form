<?php
/**
 * Contact Form validation and custom-field rendering fixture.
 *
 * Usage:
 * wp --path=/var/www/html eval-file wp-content/plugins/mds-contact-form/tests/security-fixture.php
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('MDS_Contact_Form')) {
    throw new RuntimeException('Contact Form must be active.');
}

$form = (new ReflectionClass('MDS_Contact_Form'))->newInstanceWithoutConstructor();
$call = static function ($method, array $args = []) use ($form) {
    $reflection = new ReflectionClass($form);
    $target = $reflection->getMethod($method);
    $target->setAccessible(true);

    return $target->invokeArgs($form, $args);
};

$oversized = $form->process_submission([
    'name' => str_repeat('n', 201),
    'email' => 'fixture@example.test',
    'message' => 'Fixture message.',
]);
if (!is_wp_error($oversized) || 'mds_contact_form_too_long' !== $oversized->get_error_code()) {
    throw new RuntimeException('Contact Form accepted an oversized base field.');
}

$text_field = $call('normalize_custom_field_definition', [[
    'key' => 'fixture_text',
    'label' => 'Fixture text',
    'type' => 'text',
]]);
if (!is_array($text_field) || 0 !== (int) ($text_field['options']['maxlength'] ?? -1)) {
    throw new RuntimeException('An unconfigured custom text maxlength was not normalized correctly.');
}

ob_start();
$call('render_custom_field', ['fixture-form', $text_field]);
$text_html = ob_get_clean();
if (false !== strpos($text_html, 'maxlength="0"')) {
    throw new RuntimeException('Contact Form rendered maxlength=0 for an unconfigured custom text field.');
}

$url_field = $call('normalize_custom_field_definition', [[
    'key' => 'fixture_url',
    'label' => 'Fixture URL',
    'type' => 'url',
]]);
if (
    false !== $call('validate_custom_field_value', ['https://user:pass@example.test/path', $url_field])
    || true !== $call('validate_custom_field_value', ['https://example.test/path', $url_field])
) {
    throw new RuntimeException('Contact Form URL credential validation is incorrect.');
}

$pattern_field = $call('normalize_custom_field_definition', [[
    'key' => 'fixture_pattern',
    'label' => 'Fixture pattern',
    'type' => 'text',
    'options' => ['pattern' => str_repeat('a', 300)],
]]);
if (256 !== strlen((string) ($pattern_field['options']['pattern'] ?? ''))) {
    throw new RuntimeException('Contact Form did not bound a custom validation pattern.');
}

$invalid_pattern_field = $call('normalize_custom_field_definition', [[
    'key' => 'fixture_invalid_pattern',
    'label' => 'Fixture invalid pattern',
    'type' => 'text',
    'options' => ['pattern' => '['],
]]);
if (false !== $call('validate_custom_field_value', ['value', $invalid_pattern_field])) {
    throw new RuntimeException('Contact Form did not fail closed for an invalid validation pattern.');
}

$checkbox_field = $call('normalize_custom_field_definition', [[
    'key' => 'fixture_choices',
    'label' => 'Fixture choices',
    'type' => 'checkbox',
    'required' => true,
    'options' => [
        'choices' => [
            ['value' => 'one', 'label' => 'One'],
            ['value' => 'two', 'label' => 'Two'],
        ],
    ],
]]);
ob_start();
$call('render_custom_field', ['fixture-form', $checkbox_field]);
$checkbox_html = ob_get_clean();
if (
    false === strpos($checkbox_html, '<fieldset class="mds-form-choice-set"')
    || false === strpos($checkbox_html, 'data-required="true"')
    || false === strpos($checkbox_html, '<legend>')
) {
    throw new RuntimeException('Contact Form did not render an accessible required choice group.');
}

if (
    true !== $call('validate_custom_field_value', [['one'], $checkbox_field])
    || false !== $call('validate_custom_field_value', [['forged'], $checkbox_field])
) {
    throw new RuntimeException('Contact Form choice allowlist validation is incorrect.');
}

echo wp_json_encode([
    'base_limits' => true,
    'maxlength' => true,
    'urls' => true,
    'patterns' => true,
    'choice_groups' => true,
]) . PHP_EOL;
