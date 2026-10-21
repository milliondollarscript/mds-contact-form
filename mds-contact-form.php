<?php
/**
 * Plugin Name: Million Dollar Script - Contact Form
 * Plugin URI: https://milliondollarscript.com/extensions/contact-form
 * Description: Simple contact form builder with email notifications and basic spam protection for Million Dollar Script.
 * Version: 1.0.6
 * Author: Million Dollar Script
 * Author URI: https://milliondollarscript.com
 * License: GPL v2 or later
 * Text Domain: mds-contact-form
 * Requires at least: 6.0
 * Tested up to: 7.1.3
 * Requires PHP: 8.1
 * Requires Plugins: million-dollar-script
 * Requires MDS: 3.0.0
 * Requires MDS API: 1
 * MDS Compatible: MDS 3.0
 */

namespace MillionDollarScript\Extensions\ContactForm;

use DateTimeImmutable;
use WP_Error;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('MDS_CONTACT_FORM_VERSION', '1.0.5');
define('MDS_CONTACT_FORM_DB_VERSION', '1.0.3');
define('MDS_CONTACT_FORM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MDS_CONTACT_FORM_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Contact Form class.
 */
class MDS_Contact_Form {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('admin_post_mds_contact_form_save_settings', [$this, 'save_settings']);
        add_action('admin_post_mds_contact_form_submission_action', [$this, 'handle_submission_action']);
        add_action('wp_ajax_mds_contact_form_submit', [$this, 'handle_form_submission']);
        add_action('wp_ajax_nopriv_mds_contact_form_submit', [$this, 'handle_form_submission']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('mds_contact_form_cleanup', [$this, 'cleanup_expired_submissions']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'privacy_exporters']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'privacy_erasers']);
        add_filter('million-dollar-script/extension/onboarding/items', [$this, 'extension_onboarding_items']);
        add_filter('million-dollar-script/editor/extension/blocks', [$this, 'editor_blocks']);
        
    }

    /**
     * Prepare storage and the daily retention cleanup.
     */
    public function activate() {
        $this->create_database_table();
        $this->schedule_cleanup();
    }

    /**
     * Remove the scheduled cleanup without deleting stored submissions.
     */
    public function deactivate() {
        wp_clear_scheduled_hook('mds_contact_form_cleanup');
    }

    /**
     * Add Contact Form setup shortcuts and legal draft recommendations.
     */
    public function extension_onboarding_items($items) {
        $items['mds-contact-form'] = [
            'name' => __('Contact Form', 'mds-contact-form'),
            'summary' => __('Review contact form placement, spam protection, submission retention, email notifications, and privacy language before collecting visitor messages.', 'mds-contact-form'),
            'priority' => 60,
            'actions' => [
                [
                    'label' => __('Open Contact Form', 'mds-contact-form'),
                    'url' => admin_url('admin.php?page=mds-contact-form'),
                    'primary' => true,
                ],
            ],
            'legal_documents' => [
                [
                    'slug' => 'contact-form-privacy-notice',
                    'title' => __('Contact Form Privacy Notice', 'mds-contact-form'),
                    'description' => __('Privacy language for visitor names, email addresses, messages, spam-prevention records, email notifications, and retention.', 'mds-contact-form'),
                    'page_slug' => 'contact-form-privacy-notice',
                    'content' => $this->contact_form_legal_content(),
                ],
            ],
        ];

        return $items;
    }

    /**
     * Draft Contact Form privacy content.
     */
    private function contact_form_legal_content() {
        return '<h2>' . esc_html__('Contact Form Privacy Notice', 'mds-contact-form') . '</h2>'
            . '<p>' . esc_html__('This notice explains how {{site_name}} handles messages submitted through contact forms on {{site_url}}. It supplements the main privacy policy at {{privacy_policy_url}}.', 'mds-contact-form') . '</p>'
            . '<h3>' . esc_html__('Information Collected', 'mds-contact-form') . '</h3>'
            . '<p>' . esc_html__('Contact forms may collect names, email addresses, subjects, message text, timestamps, custom field responses, short-lived non-reversible network abuse signals, and email notification status. This information is used to receive messages, respond to requests, prevent spam or abuse, troubleshoot delivery, protect the site, and maintain support records.', 'mds-contact-form') . '</p>'
            . '<h3>' . esc_html__('Email Notifications and Retention', 'mds-contact-form') . '</h3>'
            . '<p>' . esc_html__('Submitted messages may be stored in WordPress, sent by email to site administrators, processed by hosting providers, delivered through email services, and included in backups. Messages may be retained for support, security, accounting, legal compliance, dispute handling, backup recovery, and audit purposes.', 'mds-contact-form') . '</p>'
            . '<h3>' . esc_html__('Requests and Contact', 'mds-contact-form') . '</h3>'
            . '<p>' . esc_html__('Access, correction, deletion, export, objection, and support requests can be sent through {{contact_method}}, subject to legal, security, backup, and dispute-retention obligations.', 'mds-contact-form') . '</p>';
    }
    
    /**
     * Initialize the extension
     */
    public function init() {
        load_plugin_textdomain('mds-contact-form', false, dirname(plugin_basename(__FILE__)) . '/languages');
        $this->maybe_upgrade_database();
        $this->schedule_cleanup();
        
        // Add shortcode
        add_shortcode('mds_contact_form', [$this, 'contact_form_shortcode']);
    }

    /**
     * Register the editor block through Million Dollar Script when the core block bridge is available.
     */
    public function editor_blocks($blocks) {
        $blocks[] = [
            'name' => 'mds-contact-form/form',
            'title' => __('Contact Form', 'mds-contact-form'),
            'description' => __('Display a contact form with email notifications and stored submissions.', 'mds-contact-form'),
            'icon' => 'email-alt',
            'category' => 'widgets',
            'attributes' => [
                'title' => ['type' => 'string', 'default' => __('Contact Us', 'mds-contact-form')],
                'show_subject' => ['type' => 'boolean', 'default' => true],
                'button_text' => ['type' => 'string', 'default' => __('Send Message', 'mds-contact-form')],
                'success_message' => ['type' => 'string', 'default' => __('Thank you for your message! We\'ll get back to you soon.', 'mds-contact-form')],
            ],
            'controls' => [
                [
                    'attribute' => 'title',
                    'type' => 'text',
                    'label' => __('Form title', 'mds-contact-form'),
                    'help' => __('Heading shown above the contact form. Leave blank to hide the heading.', 'mds-contact-form'),
                ],
                [
                    'attribute' => 'show_subject',
                    'type' => 'toggle',
                    'label' => __('Show subject field', 'mds-contact-form'),
                    'help' => __('Ask visitors for a subject line before the message field.', 'mds-contact-form'),
                ],
                [
                    'attribute' => 'button_text',
                    'type' => 'text',
                    'label' => __('Button text', 'mds-contact-form'),
                    'help' => __('Text shown on the submit button.', 'mds-contact-form'),
                ],
                [
                    'attribute' => 'success_message',
                    'type' => 'textarea',
                    'label' => __('Success message', 'mds-contact-form'),
                    'help' => __('Message shown after the form submits successfully.', 'mds-contact-form'),
                ],
            ],
            'preview' => [
                'title' => __('Contact Form', 'mds-contact-form'),
                'description' => __('Shows name, email, message fields, optional subject, spam trap, and confirmation text.', 'mds-contact-form'),
                'rows' => [
                    __('Name and email fields', 'mds-contact-form'),
                    __('Optional subject field', 'mds-contact-form'),
                    __('Message box and submit button', 'mds-contact-form'),
                ],
            ],
            'render_callback' => [$this, 'render_editor_block'],
        ];

        return $blocks;
    }

    /**
     * Render the dynamic editor block with sanitized shortcode attributes.
     */
    public function render_editor_block($attributes = []) {
        $attributes = is_array($attributes) ? $attributes : [];

        return $this->contact_form_shortcode([
            'title' => sanitize_text_field((string) ($attributes['title'] ?? __('Contact Us', 'mds-contact-form'))),
            'show_subject' => $this->truthy($attributes['show_subject'] ?? true) ? 'true' : 'false',
            'button_text' => sanitize_text_field((string) ($attributes['button_text'] ?? __('Send Message', 'mds-contact-form'))),
            'success_message' => sanitize_text_field((string) ($attributes['success_message'] ?? __('Thank you for your message! We\'ll get back to you soon.', 'mds-contact-form'))),
        ]);
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');

        wp_enqueue_style(
            'mds-contact-form',
            MDS_CONTACT_FORM_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            MDS_CONTACT_FORM_VERSION
        );
        wp_enqueue_script(
            'mds-contact-form',
            MDS_CONTACT_FORM_PLUGIN_URL . 'assets/js/frontend.js',
            ['jquery'],
            MDS_CONTACT_FORM_VERSION,
            true
        );
        
        wp_localize_script('mds-contact-form', 'mds_contact_form_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mds_contact_form_nonce'),
            'strings' => [
                'sending' => __('Sending...', 'mds-contact-form'),
                'success' => __('Message sent successfully!', 'mds-contact-form'),
                'error' => __('Error sending message. Please try again.', 'mds-contact-form'),
                'required_field' => __('This field is required.', 'mds-contact-form'),
                'invalid_email' => __('Please enter a valid email address.', 'mds-contact-form'),
                'choose_one' => __('Choose at least one option.', 'mds-contact-form'),
            ]
        ]);
    }

    /**
     * Enqueue shared admin styling when available.
     */
    public function admin_assets($hook) {
        if (false === strpos((string) $hook, 'mds-contact-form')) {
            return;
        }

        if (class_exists('\MillionDollarScript\Core\Runtime')) {
            wp_enqueue_style('million-dollar-script-admin', \MillionDollarScript\Core\Runtime::url('assets/mds3/') . 'css/admin.css', [], \MillionDollarScript\Core\Runtime::version());
        }

        wp_enqueue_style(
            'mds-contact-form-admin',
            MDS_CONTACT_FORM_PLUGIN_URL . 'assets/css/admin.css',
            [],
            MDS_CONTACT_FORM_VERSION
        );
        wp_enqueue_script(
            'mds-contact-form-admin',
            MDS_CONTACT_FORM_PLUGIN_URL . 'assets/js/admin.js',
            [],
            MDS_CONTACT_FORM_VERSION,
            true
        );
        wp_localize_script('mds-contact-form-admin', 'mdsContactFormAdmin', [
            'confirmDelete' => __('Delete this submission permanently?', 'mds-contact-form'),
        ]);
    }
    
    /**
     * Create database table for form submissions
     */
    public function create_database_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mds_contact_submissions';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            email varchar(255) NOT NULL,
            subject varchar(255) DEFAULT '',
            message text NOT NULL,
            custom_fields longtext DEFAULT NULL,
            ip_address varchar(45) DEFAULT '',
            user_agent text DEFAULT '',
            submitted_at datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(20) DEFAULT 'unread',
            notification_status varchar(20) DEFAULT 'pending',
            PRIMARY KEY (id),
            KEY email (email(191)),
            KEY submitted_at (submitted_at),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        update_option('mds_contact_form_db_version', MDS_CONTACT_FORM_DB_VERSION, false);
    }

    /**
     * Keep existing installs current when the extension updates.
     */
    private function maybe_upgrade_database() {
        $installed_version = (string) get_option('mds_contact_form_db_version', '0');
        if ($installed_version === MDS_CONTACT_FORM_DB_VERSION) {
            return;
        }

        $this->create_database_table();

        if (version_compare($installed_version, '1.0.3', '<')) {
            global $wpdb;
            $table_name = $this->submission_table();
            $wpdb->query("UPDATE $table_name SET ip_address = '', user_agent = '' WHERE ip_address <> '' OR user_agent <> ''");
        }
    }

    /**
     * Ensure the daily retention cleanup exists.
     */
    private function schedule_cleanup() {
        if (!wp_next_scheduled('mds_contact_form_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'mds_contact_form_cleanup');
        }
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        $parent_slug = class_exists('\\MillionDollarScript\\Extensions\\Support')
            ? \MillionDollarScript\Extensions\Support::admin_parent_slug('milliondollarscript')
            : (class_exists('\MillionDollarScript\Core\Runtime') ? 'mds3' : 'milliondollarscript');

        add_submenu_page(
            $parent_slug,
            __('Contact Form', 'mds-contact-form'),
            __('Contact Form', 'mds-contact-form'),
            'manage_options',
            'mds-contact-form',
            [$this, 'admin_page']
        );
    }
    
    /**
     * Contact form shortcode
     */
    public function contact_form_shortcode($atts) {
        $atts = shortcode_atts([
            'title' => __('Contact Us', 'mds-contact-form'),
            'show_subject' => 'true',
            'button_text' => __('Send Message', 'mds-contact-form'),
            'success_message' => __('Thank you for your message! We\'ll get back to you soon.', 'mds-contact-form'),
            'custom_fields' => 'settings',
            'field_groups' => '',
            'field_keys' => '',
        ], $atts);
        
        $form_id = wp_unique_id('mds-contact-form-');
        $custom_field_context = $this->custom_field_context_from_atts($atts);
        $custom_fields = $this->custom_field_definitions($custom_field_context);
        
        ob_start();
        ?>
        <div class="mds-contact-form-wrapper">
            <?php if (!empty($atts['title'])): ?>
                <h3 class="mds-contact-form-title"><?php echo esc_html($atts['title']); ?></h3>
            <?php endif; ?>
            
            <form
                id="<?php echo esc_attr($form_id); ?>"
                class="mds-contact-form"
                method="post"
                data-success-message="<?php echo esc_attr($atts['success_message']); ?>"
                data-button-text="<?php echo esc_attr($atts['button_text']); ?>"
            >
                <div class="mds-form-group">
                    <label for="<?php echo esc_attr($form_id); ?>_name"><?php esc_html_e('Name', 'mds-contact-form'); ?> <span class="required">*</span></label>
                    <input type="text" id="<?php echo esc_attr($form_id); ?>_name" name="name" autocomplete="name" maxlength="200" required>
                </div>
                
                <div class="mds-form-group">
                    <label for="<?php echo esc_attr($form_id); ?>_email"><?php esc_html_e('Email', 'mds-contact-form'); ?> <span class="required">*</span></label>
                    <input type="email" id="<?php echo esc_attr($form_id); ?>_email" name="email" autocomplete="email" maxlength="254" required>
                </div>
                
                <?php if ($this->truthy($atts['show_subject'])): ?>
                    <div class="mds-form-group">
                        <label for="<?php echo esc_attr($form_id); ?>_subject"><?php esc_html_e('Subject', 'mds-contact-form'); ?></label>
                        <input type="text" id="<?php echo esc_attr($form_id); ?>_subject" name="subject" maxlength="200">
                    </div>
                <?php endif; ?>
                
                <div class="mds-form-group">
                    <label for="<?php echo esc_attr($form_id); ?>_message"><?php esc_html_e('Message', 'mds-contact-form'); ?> <span class="required">*</span></label>
                    <textarea id="<?php echo esc_attr($form_id); ?>_message" name="message" rows="5" maxlength="10000" required></textarea>
                </div>

                <?php echo $this->render_custom_fields($form_id, $custom_fields); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

                <input type="hidden" name="mds_contact_form_custom_fields" value="<?php echo esc_attr($custom_field_context['mode']); ?>">
                <input type="hidden" name="mds_contact_form_field_groups" value="<?php echo esc_attr(implode(',', $custom_field_context['groups'])); ?>">
                <input type="hidden" name="mds_contact_form_field_keys" value="<?php echo esc_attr(implode(',', $custom_field_context['keys'])); ?>">
                <input type="hidden" name="mds_contact_form_field_context_token" value="<?php echo esc_attr($this->field_context_token($custom_field_context)); ?>">
                
                <div class="mds-contact-form-trap" aria-hidden="true">
                    <label for="<?php echo esc_attr($form_id); ?>_website"><?php esc_html_e('Leave this field empty', 'mds-contact-form'); ?></label>
                    <input type="text" id="<?php echo esc_attr($form_id); ?>_website" name="honeypot" value="" tabindex="-1" autocomplete="off">
                </div>
                
                <div class="mds-form-group">
                    <button type="submit"><?php echo esc_html($atts['button_text']); ?></button>
                </div>
                
                <div class="mds-form-messages" role="status" aria-live="polite"></div>
            </form>
        </div>
        <?php
        
        return ob_get_clean();
    }

    /**
     * Normalize shortcode and block boolean values.
     */
    private function truthy($value) {
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * Return saved custom-field defaults for forms that use the settings mode.
     */
    private function custom_field_settings() {
        $settings = get_option('mds_contact_form_custom_field_settings', []);
        $settings = is_array($settings) ? $settings : [];

        $mode = sanitize_key($settings['mode'] ?? 'none');
        if (!in_array($mode, ['none', 'all', 'groups', 'fields'], true)) {
            $mode = 'none';
        }

        return [
            'mode' => $mode,
            'groups' => $this->split_custom_field_list($settings['groups'] ?? []),
            'keys' => $this->split_custom_field_list($settings['keys'] ?? []),
        ];
    }

    /**
     * Return notification and retention settings with safe defaults.
     */
    private function notification_settings() {
        $settings = get_option('mds_contact_form_settings', []);
        $settings = is_array($settings) ? $settings : [];
        $email = sanitize_email((string) ($settings['notification_email'] ?? get_option('admin_email')));

        if (!$email) {
            $email = sanitize_email((string) get_option('admin_email'));
        }

        return [
            'enabled' => !array_key_exists('notifications_enabled', $settings) || !empty($settings['notifications_enabled']),
            'email' => $email,
            'retention_days' => min(3650, absint($settings['retention_days'] ?? 365)),
        ];
    }

    /**
     * Gather available custom-field providers.
     */
    private function custom_field_catalog() {
        $catalog = [
            'providers' => [],
            'groups' => [],
            'fields' => [],
        ];

        $catalog = \MillionDollarScript\Core\Hooks::apply_compat('million-dollar-script/extensions/contact-form/custom/field/catalog', ['mds_contact_form_custom_field_catalog'], $catalog);
        $catalog = is_array($catalog) ? $catalog : [];

        return [
            'providers' => is_array($catalog['providers'] ?? null) ? $catalog['providers'] : [],
            'groups' => is_array($catalog['groups'] ?? null) ? $catalog['groups'] : [],
            'fields' => is_array($catalog['fields'] ?? null) ? $catalog['fields'] : [],
        ];
    }

    /**
     * Resolve custom-field mode from shortcode attributes.
     */
    private function custom_field_context_from_atts(array $atts) {
        $raw_mode = trim((string) ($atts['custom_fields'] ?? 'settings'));
        $mode = sanitize_key($raw_mode);

        if ('settings' === $mode || '' === $mode) {
            return $this->custom_field_settings();
        }

        if (in_array($mode, ['none', 'all', 'groups', 'fields'], true)) {
            return [
                'mode' => $mode,
                'groups' => 'groups' === $mode ? $this->split_custom_field_list($atts['field_groups'] ?? '') : [],
                'keys' => 'fields' === $mode ? $this->split_custom_field_list($atts['field_keys'] ?? '') : [],
            ];
        }

        return [
            'mode' => 'fields',
            'groups' => [],
            'keys' => $this->split_custom_field_list($raw_mode),
        ];
    }

    /**
     * Resolve custom-field mode from a submitted form.
     */
    private function custom_field_context_from_post(array $raw_post) {
        $mode = sanitize_key(wp_unslash($raw_post['mds_contact_form_custom_fields'] ?? 'settings'));

        if ('settings' === $mode || '' === $mode) {
            $context = $this->custom_field_settings();
        } else {
            if (!in_array($mode, ['none', 'all', 'groups', 'fields'], true)) {
                $mode = 'none';
            }

            $context = [
                'mode' => $mode,
                'groups' => $this->split_custom_field_list(wp_unslash($raw_post['mds_contact_form_field_groups'] ?? '')),
                'keys' => $this->split_custom_field_list(wp_unslash($raw_post['mds_contact_form_field_keys'] ?? '')),
            ];
        }

        $provided_token = sanitize_text_field(wp_unslash($raw_post['mds_contact_form_field_context_token'] ?? ''));
        if (!hash_equals($this->field_context_token($context), $provided_token)) {
            return [
                'mode' => 'none',
                'groups' => [],
                'keys' => [],
            ];
        }

        return $context;
    }

    /**
     * Sign the public form's normalized field context.
     */
    private function field_context_token(array $context) {
        $normalized = [
            'mode' => sanitize_key((string) ($context['mode'] ?? 'none')),
            'groups' => $this->split_custom_field_list($context['groups'] ?? []),
            'keys' => $this->split_custom_field_list($context['keys'] ?? []),
        ];

        return hash_hmac('sha256', wp_json_encode($normalized), wp_salt('nonce'));
    }

    /**
     * Normalize comma-separated, newline-separated, or array field lists.
     */
    private function split_custom_field_list($value) {
        $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);
        $items = is_array($items) ? $items : [];
        $normalized = [];

        foreach ($items as $item) {
            $key = sanitize_key((string) $item);
            if ('' !== $key) {
                $normalized[] = $key;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Resolve field definitions for the current form context.
     */
    private function custom_field_definitions(array $context) {
        if ('none' === ($context['mode'] ?? 'none')) {
            return [];
        }

        $fields = \MillionDollarScript\Core\Hooks::apply_compat('million-dollar-script/extensions/contact-form/custom/field/definitions', ['mds_contact_form_custom_field_definitions'], [], $context);
        $fields = is_array($fields) ? $fields : [];
        $normalized = [];

        foreach (array_slice($fields, 0, 100) as $field) {
            $field = is_array($field) ? $this->normalize_custom_field_definition($field) : null;
            if (!$field) {
                continue;
            }

            $normalized[$field['key']] = $field;
        }

        return array_values($normalized);
    }

    /**
     * Normalize a provider field descriptor into the Contact Form contract.
     */
    private function normalize_custom_field_definition(array $field) {
        $key = sanitize_key($field['key'] ?? $field['field_key'] ?? '');
        if ('' === $key) {
            return null;
        }

        $type = sanitize_key($field['type'] ?? $field['field_type'] ?? 'text');
        if (!in_array($type, ['text', 'textarea', 'email', 'url', 'tel', 'number', 'select', 'radio', 'checkbox', 'date', 'color'], true)) {
            return null;
        }

        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        $choices = [];
        foreach (array_slice((array) ($options['choices'] ?? $options['options'] ?? []), 0, 100) as $choice) {
            if (!is_array($choice)) {
                continue;
            }

            $value = $this->limit_text(sanitize_text_field((string) ($choice['value'] ?? '')), 200);
            if ('' === $value) {
                continue;
            }

            $choices[] = [
                'value' => $value,
                'label' => $this->limit_text(sanitize_text_field((string) ($choice['label'] ?? $value)), 200),
            ];
        }

        $hard_limit = $this->hard_length_limit($type);
        $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];
        $configured_maxlength = absint($options['maxlength'] ?? ($validation['max_length'] ?? 0));
        $maxlength = $configured_maxlength > 0 ? min($hard_limit, $configured_maxlength) : 0;
        $min_length = isset($validation['min_length']) ? min($hard_limit, absint($validation['min_length'])) : 0;
        $pattern = $this->limit_text(sanitize_text_field((string) ($options['pattern'] ?? ($validation['pattern'] ?? ''))), 256);

        return [
            'key' => $key,
            'label' => $this->limit_text(sanitize_text_field((string) ($field['label'] ?? $field['field_label'] ?? $key)), 200),
            'type' => $type,
            'required' => !empty($field['required']) || !empty($field['is_required']),
            'help' => $this->limit_text(sanitize_textarea_field((string) ($field['help'] ?? $field['description'] ?? '')), 1000),
            'source' => sanitize_key((string) ($field['source'] ?? 'custom')),
            'group_key' => sanitize_key((string) ($field['group_key'] ?? '')),
            'group_label' => $this->limit_text(sanitize_text_field((string) ($field['group_label'] ?? $field['group_name'] ?? '')), 200),
            'options' => [
                'placeholder' => $this->limit_text(sanitize_text_field((string) ($options['placeholder'] ?? '')), 200),
                'rows' => min(20, max(1, absint($options['rows'] ?? 4))),
                'maxlength' => $maxlength,
                'min' => sanitize_text_field((string) ($options['min'] ?? '')),
                'max' => sanitize_text_field((string) ($options['max'] ?? '')),
                'step' => sanitize_text_field((string) ($options['step'] ?? '')),
                'pattern' => $pattern,
                'choices' => $choices,
            ],
            'validation' => [
                'min_length' => $min_length,
                'max_length' => $maxlength,
                'pattern' => $pattern,
                'email' => !empty($validation['email']),
                'url' => !empty($validation['url']),
                'numeric' => !empty($validation['numeric']),
            ],
        ];
    }

    /**
     * Render custom fields supplied by providers.
     */
    private function render_custom_fields($form_id, array $fields) {
        if (empty($fields)) {
            return '';
        }

        ob_start();
        ?>
        <fieldset class="mds-contact-custom-fields">
            <legend><?php esc_html_e('Additional details', 'mds-contact-form'); ?></legend>
            <?php foreach ($fields as $field): ?>
                <?php $this->render_custom_field($form_id, $field); ?>
            <?php endforeach; ?>
        </fieldset>
        <?php

        return ob_get_clean();
    }

    /**
     * Render one custom field.
     */
    private function render_custom_field($form_id, array $field) {
        $key = $field['key'];
        $type = $field['type'];
        $field_id = sanitize_html_class($form_id . '_' . $key);
        $name = 'mds_contact_fields[' . $key . ']';
        $required = !empty($field['required']);
        $required_attr = $required ? ' required' : '';
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        $help_id = $field_id . '_help';
        $aria_describedby = '' !== ($field['help'] ?? '') ? ' aria-describedby="' . esc_attr($help_id) . '"' : '';
        ?>
        <div class="mds-form-group mds-contact-custom-field mds-contact-custom-field-<?php echo esc_attr($type); ?>">
            <?php if (in_array($type, ['radio', 'checkbox'], true) && !empty($options['choices'])): ?>
                <fieldset class="mds-form-choice-set"<?php echo $required ? ' data-required="true" aria-required="true"' : ''; ?><?php echo $aria_describedby; ?>>
                    <legend>
                        <?php echo esc_html($field['label']); ?>
                        <?php if ($required): ?><span class="required">*</span><?php endif; ?>
                    </legend>
                    <div class="mds-form-choices">
                    <?php foreach ($options['choices'] as $index => $choice): ?>
                        <?php $choice_id = $field_id . '_' . absint($index); ?>
                        <label class="mds-form-choice" for="<?php echo esc_attr($choice_id); ?>">
                            <input
                                type="<?php echo esc_attr('radio' === $type ? 'radio' : 'checkbox'); ?>"
                                id="<?php echo esc_attr($choice_id); ?>"
                                name="<?php echo esc_attr('checkbox' === $type ? $name . '[]' : $name); ?>"
                                value="<?php echo esc_attr($choice['value']); ?>"
                                <?php echo 'radio' === $type && $required ? 'required' : ''; ?>
                            >
                            <span><?php echo esc_html($choice['label']); ?></span>
                        </label>
                    <?php endforeach; ?>
                    </div>
                </fieldset>
            <?php else: ?>
                <label for="<?php echo esc_attr($field_id); ?>">
                    <?php echo esc_html($field['label']); ?>
                    <?php if ($required): ?><span class="required">*</span><?php endif; ?>
                </label>

                <?php if ('textarea' === $type): ?>
                    <textarea id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($name); ?>" rows="<?php echo esc_attr(max(1, absint($options['rows'] ?? 4))); ?>" placeholder="<?php echo esc_attr($options['placeholder'] ?? ''); ?>"<?php echo !empty($options['maxlength']) ? ' maxlength="' . esc_attr(absint($options['maxlength'])) . '"' : ''; ?><?php echo $required_attr; ?><?php echo $aria_describedby; ?>></textarea>
                <?php elseif ('select' === $type): ?>
                    <select id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($name); ?>"<?php echo $required_attr; ?><?php echo $aria_describedby; ?>>
                        <option value=""><?php esc_html_e('Select an option', 'mds-contact-form'); ?></option>
                        <?php foreach (($options['choices'] ?? []) as $choice): ?>
                            <option value="<?php echo esc_attr($choice['value']); ?>"><?php echo esc_html($choice['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input
                        type="<?php echo esc_attr(in_array($type, ['email', 'url', 'tel', 'number', 'date', 'color'], true) ? $type : 'text'); ?>"
                        id="<?php echo esc_attr($field_id); ?>"
                        name="<?php echo esc_attr($name); ?>"
                        placeholder="<?php echo esc_attr($options['placeholder'] ?? ''); ?>"
                        <?php echo !empty($options['maxlength']) ? 'maxlength="' . esc_attr(absint($options['maxlength'])) . '"' : ''; ?>
                        <?php echo '' !== ($options['min'] ?? '') ? 'min="' . esc_attr($options['min']) . '"' : ''; ?>
                        <?php echo '' !== ($options['max'] ?? '') ? 'max="' . esc_attr($options['max']) . '"' : ''; ?>
                        <?php echo '' !== ($options['step'] ?? '') ? 'step="' . esc_attr($options['step']) . '"' : ''; ?>
                        <?php echo '' !== ($options['pattern'] ?? '') ? 'pattern="' . esc_attr($options['pattern']) . '"' : ''; ?>
                        <?php echo $required_attr; ?>
                        <?php echo $aria_describedby; ?>
                    >
                <?php endif; ?>
            <?php endif; ?>

            <?php if ('' !== ($field['help'] ?? '')): ?>
                <span class="mds-form-help" id="<?php echo esc_attr($help_id); ?>"><?php echo esc_html($field['help']); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Handle form submission
     */
    public function handle_form_submission() {
        check_ajax_referer('mds_contact_form_nonce', 'nonce');

        $result = $this->process_submission($_POST);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Message sent successfully!', 'mds-contact-form'),
            'submission_id' => absint($result['submission_id'] ?? 0),
            'notification_status' => sanitize_key((string) ($result['notification_status'] ?? '')),
        ]);
    }

    /**
     * Validate and store one form submission.
     *
     * @param array $raw_post Untrusted request values.
     * @return array|WP_Error
     */
    public function process_submission(array $raw_post) {
        if (!empty($raw_post['honeypot'] ?? '')) {
            return new WP_Error('mds_contact_form_spam', __('Your message could not be accepted.', 'mds-contact-form'));
        }

        $name = sanitize_text_field(wp_unslash($raw_post['name'] ?? ''));
        $email = sanitize_email(wp_unslash($raw_post['email'] ?? ''));
        $subject = sanitize_text_field(wp_unslash($raw_post['subject'] ?? ''));
        $message = sanitize_textarea_field(wp_unslash($raw_post['message'] ?? ''));

        if (empty($name) || empty($email) || empty($message)) {
            return new WP_Error('mds_contact_form_required', __('Please fill in all required fields.', 'mds-contact-form'));
        }

        if (!is_email($email)) {
            return new WP_Error('mds_contact_form_email', __('Please enter a valid email address.', 'mds-contact-form'));
        }

        if (
            $this->value_length($name) > 200
            || $this->value_length($email) > 254
            || $this->value_length($subject) > 200
            || $this->value_length($message) > 10000
        ) {
            return new WP_Error('mds_contact_form_too_long', __('One or more fields are too long. Please shorten your message and try again.', 'mds-contact-form'));
        }

        $custom_field_submission = $this->prepare_custom_field_submission($raw_post);
        if (is_wp_error($custom_field_submission)) {
            return $custom_field_submission;
        }

        $rate_key = $this->rate_limit_key();
        if ($this->rate_limit_count($rate_key) >= 3) {
            return new WP_Error('mds_contact_form_rate_limit', __('Too many submissions. Please wait before trying again.', 'mds-contact-form'));
        }

        global $wpdb;
        $table_name = $this->submission_table();

        $result = $wpdb->insert(
            $table_name,
            [
                'name' => $name,
                'email' => $email,
                'subject' => $subject,
                'message' => $message,
                'custom_fields' => wp_json_encode($custom_field_submission),
                'ip_address' => '',
                'user_agent' => '',
                'submitted_at' => current_time('mysql'),
                'status' => 'unread',
                'notification_status' => 'pending',
            ]
        );

        if ($result === false) {
            return new WP_Error('mds_contact_form_storage', __('Your message could not be saved. Please try again.', 'mds-contact-form'));
        }

        $submission_id = absint($wpdb->insert_id);
        $this->increment_rate_limit($rate_key);
        $notification_status = 'disabled';
        $notification_settings = $this->notification_settings();

        if (!empty($notification_settings['enabled'])) {
            $notification_status = $this->send_email_notification($name, $email, $subject, $message, $custom_field_submission)
                ? 'sent'
                : 'failed';
        }

        $wpdb->update(
            $table_name,
            ['notification_status' => $notification_status],
            ['id' => $submission_id],
            ['%s'],
            ['%d']
        );

        \MillionDollarScript\Core\Hooks::do_compat('million-dollar-script/extensions/contact-form/submission/created', ['mds_contact_form_submission_created'], $submission_id, [
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'message' => $message,
            'custom_fields' => $custom_field_submission,
            'notification_status' => $notification_status,
        ]);

        return [
            'submission_id' => $submission_id,
            'notification_status' => $notification_status,
        ];
    }

    /**
     * Validate and normalize provider custom-field submissions.
     */
    private function prepare_custom_field_submission(array $raw_post) {
        $context = $this->custom_field_context_from_post($raw_post);
        $fields = $this->custom_field_definitions($context);

        if (empty($fields)) {
            return [];
        }

        $raw_values = isset($raw_post['mds_contact_fields']) && is_array($raw_post['mds_contact_fields'])
            ? wp_unslash($raw_post['mds_contact_fields'])
            : [];

        $valid = \MillionDollarScript\Core\Hooks::apply_compat('million-dollar-script/extensions/contact-form/validate/custom/fields', ['mds_contact_form_validate_custom_fields'], true, $raw_values, $fields, $context);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $fallback_valid = $this->validate_custom_field_values($raw_values, $fields);
        if (is_wp_error($fallback_valid)) {
            return $fallback_valid;
        }

        $values = \MillionDollarScript\Core\Hooks::apply_compat('million-dollar-script/extensions/contact-form/sanitize/custom/field/values', ['mds_contact_form_sanitize_custom_field_values'], [], $raw_values, $fields, $context);
        $values = is_array($values) ? $values : [];
        $values = array_merge($this->fallback_sanitize_custom_field_values($raw_values, $fields), $values);

        return $this->normalize_saved_custom_fields($values, $fields);
    }

    /**
     * Fallback validation for providers that only expose field definitions.
     */
    private function validate_custom_field_values(array $raw_values, array $fields) {
        foreach ($fields as $field) {
            $key = $field['key'];
            $value = $raw_values[$key] ?? ('checkbox' === $field['type'] ? [] : '');

            if (!$this->validate_custom_field_value($value, $field)) {
                return new WP_Error(
                    'mds_contact_form_invalid_custom_field',
                    sprintf(
                        /* translators: %s: field label */
                        __('%s is required or has an invalid value.', 'mds-contact-form'),
                        $field['label']
                    )
                );
            }
        }

        return true;
    }

    /**
     * Sanitize raw values without requiring a specific provider.
     */
    private function fallback_sanitize_custom_field_values(array $raw_values, array $fields) {
        $values = [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $values[$key] = $this->sanitize_custom_field_value($raw_values[$key] ?? ('checkbox' === $field['type'] ? [] : ''), $field);
        }

        return $values;
    }

    /**
     * Sanitize a custom field value.
     */
    private function sanitize_custom_field_value($value, array $field) {
        if ('checkbox' === $field['type']) {
            $values = is_array($value) ? $value : ('' === (string) $value ? [] : [$value]);
            return array_values(array_filter(array_map('sanitize_text_field', $values), static function($item) {
                return '' !== $item;
            }));
        }

        if (is_array($value)) {
            $value = reset($value);
        }

        switch ($field['type']) {
            case 'email':
                return sanitize_email((string) $value);
            case 'url':
                return esc_url_raw((string) $value);
            case 'textarea':
                return sanitize_textarea_field((string) $value);
            case 'number':
                return '' === trim((string) $value) ? '' : (string) floatval($value);
            case 'color':
                return sanitize_hex_color((string) $value) ?: '';
            default:
                return sanitize_text_field((string) $value);
        }
    }

    /**
     * Validate a custom field value.
     */
    private function validate_custom_field_value($value, array $field) {
        $is_empty = is_array($value) ? empty($value) : '' === trim((string) $value);

        if (!empty($field['required']) && $is_empty) {
            return false;
        }

        if ($is_empty) {
            return true;
        }

        if (is_array($value)) {
            if (count($value) > 100) {
                return false;
            }
            foreach ($value as $item) {
                if ($this->value_length((string) $item) > 2048) {
                    return false;
                }
            }
        } elseif ($this->value_length((string) $value) > $this->hard_length_limit($field['type'])) {
            return false;
        }

        $value_string = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        $validation = is_array($field['validation'] ?? null) ? $field['validation'] : [];
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];

        if (('email' === $field['type'] || !empty($validation['email'])) && !is_email($value_string)) {
            return false;
        }

        if (('url' === $field['type'] || !empty($validation['url'])) && !$this->valid_url_value($value_string)) {
            return false;
        }

        if (('number' === $field['type'] || !empty($validation['numeric'])) && (!is_numeric($value_string) || !is_finite((float) $value_string))) {
            return false;
        }

        if (!empty($validation['min_length']) && $this->value_length($value_string) < absint($validation['min_length'])) {
            return false;
        }

        if (!empty($validation['max_length']) && $this->value_length($value_string) > min($this->hard_length_limit($field['type']), absint($validation['max_length']))) {
            return false;
        }

        if (!empty($options['choices']) && in_array($field['type'], ['select', 'radio', 'checkbox'], true)) {
            $allowed = array_map(static function($choice) {
                return (string) ($choice['value'] ?? '');
            }, $options['choices']);
            $submitted = is_array($value) ? $value : [$value];

            foreach ($submitted as $submitted_value) {
                if (!in_array((string) $submitted_value, $allowed, true)) {
                    return false;
                }
            }
        }

        if (!empty($options['pattern']) && !$this->validation_pattern_matches((string) $options['pattern'], $value_string)) {
            return false;
        }

        if ('number' === $field['type']) {
            if ('' !== (string) ($options['min'] ?? '') && (float) $value_string < (float) $options['min']) {
                return false;
            }
            if ('' !== (string) ($options['max'] ?? '') && (float) $value_string > (float) $options['max']) {
                return false;
            }
        }

        if ('date' === $field['type']) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value_string);
            if (!$date || $date->format('Y-m-d') !== $value_string) {
                return false;
            }
        }

        if ('color' === $field['type'] && !preg_match('/^#[0-9a-f]{6}$/i', $value_string)) {
            return false;
        }

        return true;
    }

    /**
     * Match an administrator-provided validation fragment with bounded PCRE work.
     */
    private function validation_pattern_matches($pattern, $value) {
        $pattern = $this->limit_text(trim((string) $pattern), 256);
        if ('' === $pattern) {
            return true;
        }

        $regex = '~(*LIMIT_MATCH=100000)(*LIMIT_RECURSION=10000)(?:' . str_replace('~', '\~', $pattern) . ')~u';
        $had_error = false;
        set_error_handler(static function() use (&$had_error) {
            $had_error = true;
            return true;
        });

        try {
            $matched = preg_match($regex, (string) $value);
        } finally {
            restore_error_handler();
        }

        return !$had_error && 1 === $matched;
    }

    /**
     * Accept only credential-free HTTP(S) URLs.
     */
    private function valid_url_value($value) {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = wp_parse_url($value);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($parts['host'])
            && empty($parts['user'])
            && empty($parts['pass']);
    }

    /**
     * Return the absolute submitted-value limit for a field type.
     */
    private function hard_length_limit($type) {
        switch (sanitize_key((string) $type)) {
            case 'textarea':
                return 10000;
            case 'email':
            case 'url':
            case 'tel':
                return 2048;
            case 'date':
            case 'color':
                return 32;
            case 'number':
                return 128;
            default:
                return 2000;
        }
    }

    /**
     * Measure text consistently when multibyte support is available.
     */
    private function value_length($value) {
        return function_exists('mb_strlen') ? mb_strlen((string) $value) : strlen((string) $value);
    }

    /**
     * Truncate trusted field definitions to their rendered limits.
     */
    private function limit_text($value, $limit) {
        return function_exists('mb_substr')
            ? mb_substr((string) $value, 0, absint($limit))
            : substr((string) $value, 0, absint($limit));
    }

    /**
     * Add labels and formatted values for storage, email, and admin display.
     */
    private function normalize_saved_custom_fields(array $values, array $fields) {
        $saved = [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $value = $values[$key] ?? ('checkbox' === $field['type'] ? [] : '');
            $empty = is_array($value) ? empty($value) : '' === trim((string) $value);

            if ($empty) {
                continue;
            }

            $saved[$key] = [
                'label' => $field['label'],
                'type' => $field['type'],
                'value' => $value,
                'formatted_value' => $this->format_custom_field_value($value, $field),
            ];
        }

        return $saved;
    }

    /**
     * Format a submitted custom value for plain display.
     */
    private function format_custom_field_value($value, array $field) {
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        $choice_labels = [];

        foreach (($options['choices'] ?? []) as $choice) {
            $choice_labels[(string) ($choice['value'] ?? '')] = (string) ($choice['label'] ?? $choice['value'] ?? '');
        }

        if (is_array($value)) {
            $labels = [];
            foreach ($value as $item) {
                $labels[] = $choice_labels[(string) $item] ?? (string) $item;
            }
            return implode(', ', array_map('sanitize_text_field', $labels));
        }

        return sanitize_text_field($choice_labels[(string) $value] ?? (string) $value);
    }
    
    /**
     * Build a non-reversible request key for short-lived throttling.
     */
    private function rate_limit_key() {
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';

        return 'mds_contact_rate_' . hash_hmac('sha256', $ip, wp_salt('nonce'));
    }

    /**
     * Read the current throttling count.
     */
    private function rate_limit_count($key) {
        return absint(get_transient($key));
    }

    /**
     * Count a successful submission for ten minutes.
     */
    private function increment_rate_limit($key) {
        set_transient($key, $this->rate_limit_count($key) + 1, 10 * MINUTE_IN_SECONDS);
    }
    
    /**
     * Send email notification
     */
    private function send_email_notification($name, $email, $subject, $message, array $custom_fields = []) {
        $settings = $this->notification_settings();
        $to = $settings['email'];
        $email_subject = $subject ? $subject : __('New Contact Form Submission', 'mds-contact-form');
        
        $email_message = sprintf(
            __("New contact form submission:\n\nName: %s\nEmail: %s\nSubject: %s\n\nMessage:\n%s\n\n---\nSubmitted from: %s\nTime: %s", 'mds-contact-form'),
            $name,
            $email,
            $subject,
            $message,
            home_url(),
            current_time('F j, Y g:i a')
        );

        $custom_field_text = $this->format_custom_fields_for_email($custom_fields);
        if ('' !== $custom_field_text) {
            $email_message = str_replace(
                "\n\n---\n",
                "\n\n" . __('Additional details:', 'mds-contact-form') . "\n" . $custom_field_text . "\n\n---\n",
                $email_message
            );
        }
        
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('Reply-To: %s <%s>', sanitize_text_field($name), sanitize_email($email)),
        ];
        
        return (bool) wp_mail($to, $email_subject, $email_message, $headers);
    }

    /**
     * Format submitted custom fields for notification emails.
     */
    private function format_custom_fields_for_email(array $custom_fields) {
        $lines = [];

        foreach ($custom_fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = sanitize_text_field((string) ($field['label'] ?? ''));
            $value = sanitize_text_field((string) ($field['formatted_value'] ?? $field['value'] ?? ''));
            if ('' === $label || '' === $value) {
                continue;
            }

            $lines[] = sprintf('%s: %s', $label, $value);
        }

        return implode("\n", $lines);
    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'mds-contact-form'));
        }

        $active_tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'submissions'));
        if (!in_array($active_tab, ['overview', 'settings', 'submissions'], true)) {
            $active_tab = 'submissions';
        }
        $base_url = admin_url('admin.php?page=mds-contact-form');
        ?>
        <div class="wrap mds3-admin">
            <div class="mds-extension-heading-row">
                <h1><?php esc_html_e('Contact Form', 'mds-contact-form'); ?></h1>
                <?php echo wp_kses_post(\MillionDollarScript\Extensions\Admin::docs_button('mds-contact-form')); ?>
            </div>
            <hr class="wp-header-end">
            
            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Contact Form sections', 'mds-contact-form'); ?>">
                <a href="<?php echo esc_url(add_query_arg('tab', 'submissions', $base_url)); ?>" class="nav-tab <?php echo esc_attr($active_tab === 'submissions' ? 'nav-tab-active' : ''); ?>" <?php echo $active_tab === 'submissions' ? 'aria-current="page"' : ''; ?>><?php esc_html_e('Submissions', 'mds-contact-form'); ?></a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'settings', $base_url)); ?>" class="nav-tab <?php echo esc_attr($active_tab === 'settings' ? 'nav-tab-active' : ''); ?>" <?php echo $active_tab === 'settings' ? 'aria-current="page"' : ''; ?>><?php esc_html_e('Settings', 'mds-contact-form'); ?></a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'overview', $base_url)); ?>" class="nav-tab <?php echo esc_attr($active_tab === 'overview' ? 'nav-tab-active' : ''); ?>" <?php echo $active_tab === 'overview' ? 'aria-current="page"' : ''; ?>><?php esc_html_e('Usage', 'mds-contact-form'); ?></a>
            </nav>
            
            <?php if ($active_tab === 'overview'): ?>
                <div class="mds3-card">
                    <h2><?php esc_html_e('Contact Form Usage', 'mds-contact-form'); ?></h2>
                    <p><?php esc_html_e('Simple contact form with email notifications and spam protection.', 'mds-contact-form'); ?></p>
                    
                    <h3><?php esc_html_e('Usage', 'mds-contact-form'); ?></h3>
                    <p><?php esc_html_e('Add the contact form to any post or page using the shortcode:', 'mds-contact-form'); ?></p>
                    <?php echo wp_kses_post(\MillionDollarScript\Extensions\Admin::shortcode_copy('[mds_contact_form]')); ?>
                    
                    <h3><?php esc_html_e('Shortcode Parameters', 'mds-contact-form'); ?></h3>
                    <ul>
                        <li><strong>title</strong> - Form title (default: "Contact Us")</li>
                        <li><strong>show_subject</strong> - Show subject field (default: "true")</li>
                        <li><strong>button_text</strong> - Submit button text (default: "Send Message")</li>
                        <li><strong>success_message</strong> - Success message text</li>
                        <li><strong>custom_fields</strong> - Custom field mode: "settings", "none", "all", "groups", "fields", or a comma-separated list of field keys</li>
                        <li><strong>field_groups</strong> - Comma-separated Fields group keys when custom_fields="groups"</li>
                        <li><strong>field_keys</strong> - Comma-separated Fields field keys when custom_fields="fields"</li>
                    </ul>
                    
                    <h3><?php esc_html_e('Examples', 'mds-contact-form'); ?></h3>
                    <p><?php echo wp_kses_post(\MillionDollarScript\Extensions\Admin::shortcode_copy('[mds_contact_form title="Get In Touch" button_text="Contact Us"]')); ?></p>
                    <p><?php echo wp_kses_post(\MillionDollarScript\Extensions\Admin::shortcode_copy('[mds_contact_form show_subject="false" title="Quick Message"]')); ?></p>
                    <p><?php echo wp_kses_post(\MillionDollarScript\Extensions\Admin::shortcode_copy('[mds_contact_form custom_fields="fields" field_keys="company,budget"]')); ?></p>
                </div>
            <?php elseif ($active_tab === 'settings'): ?>
                <?php $this->display_settings(); ?>
            <?php elseif ($active_tab === 'submissions'): ?>
                <?php $this->display_submissions(); ?>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Display submissions table
     */
    private function display_submissions() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mds_contact_submissions';
        
        $per_page = 20;
        $current_page = max(1, absint($_GET['paged'] ?? 1));
        $offset = ($current_page - 1) * $per_page;
        
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        ?>
        <div class="mds3-card">
            <h2><?php esc_html_e('Contact Form Submissions', 'mds-contact-form'); ?></h2>
            <p><?php echo esc_html(sprintf(__('Total submissions: %d', 'mds-contact-form'), absint($total_items))); ?></p>

            <?php if (!empty($_GET['submission_updated'])): ?>
                <div class="notice notice-success inline"><p><?php esc_html_e('The submission was updated.', 'mds-contact-form'); ?></p></div>
            <?php endif; ?>
            
            <?php if ($submissions): ?>
                <div class="mds-contact-submissions-table">
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Name', 'mds-contact-form'); ?></th>
                            <th><?php esc_html_e('Email', 'mds-contact-form'); ?></th>
                            <th><?php esc_html_e('Subject', 'mds-contact-form'); ?></th>
                            <th><?php esc_html_e('Message', 'mds-contact-form'); ?></th>
                            <th><?php esc_html_e('Additional Details', 'mds-contact-form'); ?></th>
                            <th><?php esc_html_e('Status', 'mds-contact-form'); ?></th>
                            <th><?php esc_html_e('Email Notice', 'mds-contact-form'); ?></th>
                            <th><?php esc_html_e('Date', 'mds-contact-form'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $submission): ?>
                            <tr>
                                <td data-label="<?php esc_attr_e('Name', 'mds-contact-form'); ?>" data-colname="<?php esc_attr_e('Name', 'mds-contact-form'); ?>"><?php echo esc_html($submission->name); ?></td>
                                <td data-label="<?php esc_attr_e('Email', 'mds-contact-form'); ?>" data-colname="<?php esc_attr_e('Email', 'mds-contact-form'); ?>"><a href="<?php echo esc_url('mailto:' . sanitize_email($submission->email)); ?>"><?php echo esc_html($submission->email); ?></a></td>
                                <td data-label="<?php esc_attr_e('Subject', 'mds-contact-form'); ?>" data-colname="<?php esc_attr_e('Subject', 'mds-contact-form'); ?>"><?php echo esc_html($submission->subject); ?></td>
                                <td data-label="<?php esc_attr_e('Message', 'mds-contact-form'); ?>" data-colname="<?php esc_attr_e('Message', 'mds-contact-form'); ?>">
                                    <details>
                                        <summary><?php echo esc_html(wp_trim_words($submission->message, 12)); ?></summary>
                                        <p class="mds-contact-submission-message"><?php echo esc_html($submission->message); ?></p>
                                    </details>
                                </td>
                                <td data-label="<?php esc_attr_e('Additional Details', 'mds-contact-form'); ?>" data-colname="<?php esc_attr_e('Additional Details', 'mds-contact-form'); ?>"><?php echo wp_kses_post($this->format_custom_fields_for_admin($submission->custom_fields ?? '')); ?></td>
                                <td data-label="<?php esc_attr_e('Status', 'mds-contact-form'); ?>" data-colname="<?php esc_attr_e('Status', 'mds-contact-form'); ?>">
                                    <?php $status = sanitize_key((string) ($submission->status ?? 'unread')); ?>
                                    <span class="mds-contact-status mds-contact-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span>
                                    <div class="mds-contact-submission-actions">
                                        <?php if ('unread' === $status): ?>
                                            <?php $this->submission_action_form($submission->id, 'read', __('Mark read', 'mds-contact-form')); ?>
                                        <?php elseif ('read' === $status): ?>
                                            <?php $this->submission_action_form($submission->id, 'unread', __('Mark unread', 'mds-contact-form')); ?>
                                        <?php endif; ?>
                                        <?php $this->submission_action_form($submission->id, 'delete', __('Delete', 'mds-contact-form'), true); ?>
                                    </div>
                                </td>
                                <td data-label="<?php esc_attr_e('Email Notice', 'mds-contact-form'); ?>" data-colname="<?php esc_attr_e('Email Notice', 'mds-contact-form'); ?>">
                                    <?php $notification_status = sanitize_key((string) ($submission->notification_status ?? 'pending')); ?>
                                    <span class="mds-contact-status mds-contact-status-<?php echo esc_attr($notification_status); ?>"><?php echo esc_html(ucfirst($notification_status)); ?></span>
                                    <?php if ('failed' === $notification_status): ?>
                                        <div class="mds-contact-submission-actions">
                                            <?php $this->submission_action_form($submission->id, 'resend', __('Retry email', 'mds-contact-form')); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="<?php esc_attr_e('Date', 'mds-contact-form'); ?>" data-colname="<?php esc_attr_e('Date', 'mds-contact-form'); ?>"><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $submission->submitted_at)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                
                <?php
                // Simple pagination
                $total_pages = ceil($total_items / $per_page);
                if ($total_pages > 1):
                ?>
                    <div class="tablenav">
                        <div class="tablenav-pages">
                            <?php
                            $page_links = paginate_links([
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total' => $total_pages,
                                'current' => $current_page
                            ]);
                            echo wp_kses_post($page_links);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <p><?php esc_html_e('No submissions found.', 'mds-contact-form'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a secure submission action form.
     */
    private function submission_action_form($submission_id, $submission_action, $label, $destructive = false) {
        $submission_id = absint($submission_id);
        $submission_action = sanitize_key($submission_action);
        $classes = 'button button-small';
        if ($destructive) {
            $classes .= ' mds-contact-delete-button';
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="<?php echo $destructive ? 'mds-contact-delete-submission' : ''; ?>">
            <input type="hidden" name="action" value="mds_contact_form_submission_action">
            <input type="hidden" name="submission_id" value="<?php echo esc_attr($submission_id); ?>">
            <input type="hidden" name="submission_action" value="<?php echo esc_attr($submission_action); ?>">
            <?php wp_nonce_field('mds_contact_form_submission_' . $submission_id); ?>
            <button type="submit" class="<?php echo esc_attr($classes); ?>"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    /**
     * Format stored custom fields for admin list display.
     */
    private function format_custom_fields_for_admin($raw) {
        $fields = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($fields) || empty($fields)) {
            return '<span class="description">' . esc_html__('None', 'mds-contact-form') . '</span>';
        }

        $items = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = sanitize_text_field((string) ($field['label'] ?? ''));
            $value = sanitize_text_field((string) ($field['formatted_value'] ?? $field['value'] ?? ''));
            if ('' === $label || '' === $value) {
                continue;
            }

            $items[] = '<li><strong>' . esc_html($label) . ':</strong> ' . esc_html($value) . '</li>';
        }

        if (empty($items)) {
            return '<span class="description">' . esc_html__('None', 'mds-contact-form') . '</span>';
        }

        return '<ul class="mds-contact-custom-field-summary">' . implode('', $items) . '</ul>';
    }

    /**
     * Render Contact Form settings.
     */
    private function display_settings() {
        $settings = $this->custom_field_settings();
        $notification_settings = $this->notification_settings();
        $catalog = $this->custom_field_catalog();
        $has_fields = !empty($catalog['fields']);
        ?>
        <div class="mds3-card">
            <h2><?php esc_html_e('Contact Form Settings', 'mds-contact-form'); ?></h2>
            <p><?php esc_html_e('Choose where new-message notices are sent, how long submissions remain stored, and which reusable fields appear by default.', 'mds-contact-form'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mds_contact_form_save_settings">
                <?php wp_nonce_field('mds_contact_form_save_settings'); ?>

                <h3><?php esc_html_e('Email and Data Retention', 'mds-contact-form'); ?></h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Email notifications', 'mds-contact-form'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="notifications_enabled" value="1" <?php checked($notification_settings['enabled']); ?>>
                                <?php esc_html_e('Email the site contact when a message is stored', 'mds-contact-form'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Messages remain available under Submissions even if WordPress cannot send the notification email.', 'mds-contact-form'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mds-contact-form-notification-email"><?php esc_html_e('Notification email', 'mds-contact-form'); ?></label></th>
                        <td>
                            <input type="email" class="regular-text" id="mds-contact-form-notification-email" name="notification_email" value="<?php echo esc_attr($notification_settings['email']); ?>" autocomplete="email">
                            <p class="description"><?php esc_html_e('The address that receives new-message notices. The visitor email is included in the message so you can reply from your email client.', 'mds-contact-form'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mds-contact-form-retention-days"><?php esc_html_e('Submission retention', 'mds-contact-form'); ?></label></th>
                        <td>
                            <input type="number" class="small-text" id="mds-contact-form-retention-days" name="retention_days" value="<?php echo esc_attr($notification_settings['retention_days']); ?>" min="0" max="3650" step="1"> <?php esc_html_e('days', 'mds-contact-form'); ?>
                            <p class="description"><?php esc_html_e('Stored submissions older than this are deleted daily. Enter 0 to keep submissions until an administrator deletes or erases them.', 'mds-contact-form'); ?></p>
                        </td>
                    </tr>
                </table>

                <h3><?php esc_html_e('Custom Fields', 'mds-contact-form'); ?></h3>
                <p><?php esc_html_e('Attach reusable fields from compatible field extensions to Contact Form submissions. Shortcode attributes can override these defaults for individual forms.', 'mds-contact-form'); ?></p>

                <?php if ($this->should_show_fields_upsell($catalog)): ?>
                    <?php echo $this->fields_extension_upsell_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endif; ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Default custom fields', 'mds-contact-form'); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php esc_html_e('Default custom fields', 'mds-contact-form'); ?></legend>
                                <?php
                                $modes = [
                                    'none' => __('Do not show custom fields', 'mds-contact-form'),
                                    'all' => __('Show all compatible fields', 'mds-contact-form'),
                                    'groups' => __('Show fields from selected groups', 'mds-contact-form'),
                                    'fields' => __('Show selected fields only', 'mds-contact-form'),
                                ];
                                foreach ($modes as $mode => $label):
                                ?>
                                    <label>
                                        <input type="radio" name="custom_field_mode" value="<?php echo esc_attr($mode); ?>" <?php checked($settings['mode'], $mode); ?> <?php disabled(!$has_fields && 'none' !== $mode); ?>>
                                        <?php echo esc_html($label); ?>
                                    </label><br>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="description"><?php esc_html_e('File and image fields are not shown on contact forms until secure upload handling is enabled for this extension.', 'mds-contact-form'); ?></p>
                        </td>
                    </tr>

                    <?php if (!empty($catalog['groups'])): ?>
                        <tr>
                            <th scope="row"><label for="mds-contact-form-groups"><?php esc_html_e('Field groups', 'mds-contact-form'); ?></label></th>
                            <td>
                                <select id="mds-contact-form-groups" name="custom_field_groups[]" multiple size="<?php echo esc_attr(min(8, max(3, count($catalog['groups'])))); ?>">
                                    <?php foreach ($catalog['groups'] as $group_key => $group): ?>
                                        <?php
                                        $group_label = is_array($group) ? ($group['label'] ?? $group['name'] ?? $group_key) : $group;
                                        ?>
                                        <option value="<?php echo esc_attr($group_key); ?>" <?php selected(in_array($group_key, $settings['groups'], true)); ?>><?php echo esc_html($group_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Used when the default mode is set to selected groups. Hold Ctrl or Command to choose more than one group.', 'mds-contact-form'); ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php if (!empty($catalog['fields'])): ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Fields', 'mds-contact-form'); ?></th>
                            <td>
                                <fieldset class="mds-contact-field-list">
                                    <?php foreach ($catalog['fields'] as $field_key => $field): ?>
                                        <?php
                                        $field = is_array($field) ? $field : [];
                                        $label = sanitize_text_field((string) ($field['label'] ?? $field['field_label'] ?? $field_key));
                                        $type = sanitize_key((string) ($field['type'] ?? $field['field_type'] ?? 'text'));
                                        $group_label = sanitize_text_field((string) ($field['group_label'] ?? $field['group_name'] ?? ''));
                                        ?>
                                        <label style="display:block;margin-bottom:8px;">
                                            <input type="checkbox" name="custom_field_keys[]" value="<?php echo esc_attr($field_key); ?>" <?php checked(in_array($field_key, $settings['keys'], true)); ?>>
                                            <?php echo esc_html($label); ?>
                                            <span class="description">
                                                <?php echo esc_html(sprintf('(%s%s)', $type, '' !== $group_label ? ', ' . $group_label : '')); ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>
                                <p class="description"><?php esc_html_e('Used when the default mode is set to selected fields. Shortcodes can also pass custom_fields="fields" and field_keys="field_key".', 'mds-contact-form'); ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>

                <?php submit_button(__('Save Contact Form settings', 'mds-contact-form')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Return the quiet Fields callout shown when no compatible provider is active.
     */
    private function fields_extension_upsell_html() {
        $extensions_url = class_exists('\\MillionDollarScript\\Extensions\\Support')
            ? \MillionDollarScript\Extensions\Support::admin_url('mds3-extensions')
            : admin_url('admin.php?page=mds3-extensions');

        ob_start();
        ?>
        <div class="mds-contact-fields-callout">
            <div>
                <strong><?php esc_html_e('Use reusable fields on contact forms', 'mds-contact-form'); ?></strong>
                <p><?php esc_html_e('The Fields extension lets you add fields such as company name, budget, sponsor type, consent, and other reusable questions to Contact Form blocks and shortcodes without custom code.', 'mds-contact-form'); ?></p>
            </div>
            <a class="button" href="<?php echo esc_url($extensions_url); ?>"><?php esc_html_e('Open Extensions', 'mds-contact-form'); ?></a>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Determine whether Contact Form should show the Fields extension callout.
     */
    private function should_show_fields_upsell(array $catalog) {
        return empty($catalog['fields']);
    }

    /**
     * Save Contact Form settings.
     */
    public function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'mds-contact-form'));
        }

        check_admin_referer('mds_contact_form_save_settings');

        $mode = sanitize_key(wp_unslash($_POST['custom_field_mode'] ?? 'none'));
        if (!in_array($mode, ['none', 'all', 'groups', 'fields'], true)) {
            $mode = 'none';
        }

        $settings = [
            'mode' => $mode,
            'groups' => $this->split_custom_field_list(wp_unslash($_POST['custom_field_groups'] ?? [])),
            'keys' => $this->split_custom_field_list(wp_unslash($_POST['custom_field_keys'] ?? [])),
        ];

        update_option('mds_contact_form_custom_field_settings', $settings, false);

        $notification_email = sanitize_email(wp_unslash($_POST['notification_email'] ?? ''));
        if (!$notification_email) {
            $notification_email = sanitize_email((string) get_option('admin_email'));
        }
        update_option('mds_contact_form_settings', [
            'notifications_enabled' => !empty($_POST['notifications_enabled']) ? 1 : 0,
            'notification_email' => $notification_email,
            'retention_days' => min(3650, absint($_POST['retention_days'] ?? 365)),
        ], false);

        wp_safe_redirect(add_query_arg(['page' => 'mds-contact-form', 'tab' => 'settings', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    /**
     * Update or delete a stored submission from the admin list.
     */
    public function handle_submission_action() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'mds-contact-form'));
        }

        $submission_id = absint($_POST['submission_id'] ?? 0);
        $submission_action = sanitize_key(wp_unslash($_POST['submission_action'] ?? ''));
        check_admin_referer('mds_contact_form_submission_' . $submission_id);

        if (!$submission_id || !in_array($submission_action, ['read', 'unread', 'delete', 'resend'], true)) {
            wp_die(esc_html__('Invalid submission action.', 'mds-contact-form'));
        }

        global $wpdb;
        $table_name = $this->submission_table();

        if ('delete' === $submission_action) {
            $wpdb->delete($table_name, ['id' => $submission_id], ['%d']);
        } elseif (in_array($submission_action, ['read', 'unread'], true)) {
            $wpdb->update($table_name, ['status' => $submission_action], ['id' => $submission_id], ['%s'], ['%d']);
        } else {
            $submission = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $submission_id));
            if ($submission) {
                $custom_fields = json_decode((string) ($submission->custom_fields ?? ''), true);
                $sent = $this->send_email_notification(
                    (string) $submission->name,
                    (string) $submission->email,
                    (string) $submission->subject,
                    (string) $submission->message,
                    is_array($custom_fields) ? $custom_fields : []
                );
                $wpdb->update(
                    $table_name,
                    ['notification_status' => $sent ? 'sent' : 'failed'],
                    ['id' => $submission_id],
                    ['%s'],
                    ['%d']
                );
            }
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'mds-contact-form',
            'tab' => 'submissions',
            'submission_updated' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Delete submissions older than the configured retention period.
     */
    public function cleanup_expired_submissions() {
        $retention_days = $this->notification_settings()['retention_days'];
        if (!$retention_days) {
            return 0;
        }

        global $wpdb;
        $cutoff = (new DateTimeImmutable('now', wp_timezone()))
            ->modify('-' . absint($retention_days) . ' days')
            ->format('Y-m-d H:i:s');
        $table_name = $this->submission_table();

        return absint($wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE submitted_at < %s",
            $cutoff
        )));
    }

    /**
     * Register privacy exporter.
     */
    public function privacy_exporters($exporters) {
        $exporters['mds-contact-form'] = [
            'exporter_friendly_name' => __('Million Dollar Script contact form submissions', 'mds-contact-form'),
            'callback' => [$this, 'privacy_exporter'],
        ];

        return $exporters;
    }

    /**
     * Register privacy eraser.
     */
    public function privacy_erasers($erasers) {
        $erasers['mds-contact-form'] = [
            'eraser_friendly_name' => __('Million Dollar Script contact form submissions', 'mds-contact-form'),
            'callback' => [$this, 'privacy_eraser'],
        ];

        return $erasers;
    }

    /**
     * Export submissions by email address.
     */
    public function privacy_exporter($email_address, $page = 1) {
        global $wpdb;

        $per_page = 20;
        $page = max(1, absint($page));
        $offset = ($page - 1) * $per_page;
        $table_name = $this->submission_table();
        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE email = %s ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
            sanitize_email($email_address),
            $per_page,
            $offset
        ));

        $data = [];
        foreach ($submissions as $submission) {
            $item = [
                'group_id' => 'mds-contact-form',
                'group_label' => __('Contact Form Submissions', 'mds-contact-form'),
                'item_id' => 'mds-contact-form-' . absint($submission->id),
                'data' => [
                    ['name' => __('Name', 'mds-contact-form'), 'value' => $submission->name],
                    ['name' => __('Email', 'mds-contact-form'), 'value' => $submission->email],
                    ['name' => __('Subject', 'mds-contact-form'), 'value' => $submission->subject],
                    ['name' => __('Message', 'mds-contact-form'), 'value' => $submission->message],
                    ['name' => __('Submitted at', 'mds-contact-form'), 'value' => $submission->submitted_at],
                ],
            ];

            $custom_fields = json_decode((string) ($submission->custom_fields ?? ''), true);
            if (is_array($custom_fields)) {
                foreach ($custom_fields as $field) {
                    if (!is_array($field)) {
                        continue;
                    }

                    $item['data'][] = [
                        'name' => sanitize_text_field((string) ($field['label'] ?? __('Additional detail', 'mds-contact-form'))),
                        'value' => sanitize_text_field((string) ($field['formatted_value'] ?? $field['value'] ?? '')),
                    ];
                }
            }

            $data[] = $item;
        }

        return [
            'data' => $data,
            'done' => count($submissions) < $per_page,
        ];
    }

    /**
     * Erase submissions by email address while preserving row structure.
     */
    public function privacy_eraser($email_address, $page = 1) {
        global $wpdb;

        $table_name = $this->submission_table();
        $submissions = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $table_name WHERE email = %s LIMIT 20",
            sanitize_email($email_address)
        ));

        $removed = false;
        foreach ($submissions as $submission) {
            $result = $wpdb->update(
                $table_name,
                [
                    'name' => __('Erased user', 'mds-contact-form'),
                    'email' => 'erased-' . absint($submission->id) . '@example.invalid',
                    'subject' => '',
                    'message' => __('Erased by privacy request.', 'mds-contact-form'),
                    'custom_fields' => wp_json_encode([]),
                    'ip_address' => '',
                    'user_agent' => '',
                    'status' => 'erased',
                ],
                ['id' => absint($submission->id)]
            );

            if (false !== $result) {
                $removed = true;
            }
        }

        return [
            'items_removed' => $removed,
            'items_retained' => false,
            'messages' => $removed ? [__('Contact form submissions were erased.', 'mds-contact-form')] : [],
            'done' => count($submissions) < 20,
        ];
    }

    /**
     * Contact submission table name.
     */
    private function submission_table() {
        global $wpdb;

        return $wpdb->prefix . 'mds_contact_submissions';
    }
}

if (!class_exists('MDS_Contact_Form', false)) {
    class_alias(MDS_Contact_Form::class, 'MDS_Contact_Form');
}

// Initialize the extension.
$mds_contact_form = new MDS_Contact_Form();
register_activation_hook(__FILE__, [$mds_contact_form, 'activate']);
register_deactivation_hook(__FILE__, [$mds_contact_form, 'deactivate']);

add_action('million-dollar-script/register/extensions', static function(): void {
    if (!class_exists('\\MillionDollarScript\\Extensions\\Registry')) {
        return;
    }
    \MillionDollarScript\Extensions\Registry::register([
        'id' => 'mds-contact-form',
        'name' => 'Contact Form',
        'description' => 'Collect and manage contact form submissions with privacy controls.',
        'version' => MDS_CONTACT_FORM_VERSION,
        'main_file' => __FILE__,
        'premium' => false,
        'license_required' => false,
        'cleanup' => [
            'description' => 'Contact submissions, form settings, custom fields, and rate-limit records.',
        ],
    ]);
});
