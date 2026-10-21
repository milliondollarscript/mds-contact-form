<?php
/**
 * PHPUnit coverage for Contact Form.
 */

class MDS_Contact_Form_Tests extends WP_UnitTestCase {
    private $extension;
    private $table_name;
    private $email_suffix;
    private $remote_addr;
    private $rate_key;
    private $created_ids = [];

    public function setUp(): void {
        parent::setUp();

        global $wpdb, $mds_contact_form;
        $this->extension = $mds_contact_form ?? new MDS_Contact_Form();
        $this->table_name = $wpdb->prefix . 'mds_contact_submissions';
        $this->email_suffix = strtolower(wp_generate_password(8, false, false));
        $this->remote_addr = '192.0.2.' . random_int(20, 220);
        $_SERVER['REMOTE_ADDR'] = $this->remote_addr;
        $this->rate_key = 'mds_contact_rate_' . hash_hmac('sha256', $this->remote_addr, wp_salt('nonce'));

        $this->extension->create_database_table();
        update_option('mds_contact_form_settings', [
            'notifications_enabled' => 1,
            'notification_email' => 'contact-tests@example.test',
            'retention_days' => 365,
        ], false);
        update_option('mds_contact_form_custom_field_settings', [
            'mode' => 'none',
            'groups' => [],
            'keys' => [],
        ], false);
    }

    public function test_extension_initialization_and_hooks() {
        // One version for the package: compare the constant to the plugin header instead
        // of a literal, so the test still means something after a bump. The schema
        // version below is deliberately separate - it moves only when the table moves.
        $this->assertSame($this->plugin_header_version(dirname(__DIR__) . '/mds-contact-form.php'), MDS_CONTACT_FORM_VERSION, 'plugin header and version constant disagree');
        $this->assertSame('1.0.3', MDS_CONTACT_FORM_DB_VERSION);
        $this->assertNotFalse(has_action('init', [$this->extension, 'init']));
        $this->assertNotFalse(has_action('wp_ajax_mds_contact_form_submit', [$this->extension, 'handle_form_submission']));
        $this->assertNotFalse(has_action('wp_ajax_nopriv_mds_contact_form_submit', [$this->extension, 'handle_form_submission']));
        $this->assertNotFalse(has_action('mds_contact_form_cleanup', [$this->extension, 'cleanup_expired_submissions']));
        $this->assertTrue(shortcode_exists('mds_contact_form'));
    }

    /** The Version: line of a plugin file header, or false when it cannot be read. */
    private function plugin_header_version(string $file) {
        $src = (string) file_get_contents($file);
        return preg_match('/^[ \t]*\*[ \t]*Version:[ \t]*(\S+)/m', $src, $m) ? $m[1] : false;
    }

    public function test_database_table_contains_current_workflow_columns() {
        global $wpdb;
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->table_name));
        $this->assertSame($this->table_name, $table_exists);

        $columns = $wpdb->get_col("DESCRIBE {$this->table_name}", 0);
        foreach (['id', 'name', 'email', 'subject', 'message', 'custom_fields', 'submitted_at', 'status', 'notification_status'] as $column) {
            $this->assertContains($column, $columns);
        }
    }

    public function test_shortcode_uses_external_assets_and_escapes_attributes() {
        $this->extension->enqueue_scripts();
        $output = $this->extension->contact_form_shortcode([
            'title' => '<b>Contact tests</b>',
            'show_subject' => 'false',
            'button_text' => 'Send test',
            'success_message' => 'Test sent.',
            'custom_fields' => 'none',
        ]);

        $this->assertStringContainsString('&lt;b&gt;Contact tests&lt;/b&gt;', $output);
        $this->assertStringContainsString('data-success-message="Test sent."', $output);
        $this->assertStringNotContainsString('name="subject"', $output);
        $this->assertStringNotContainsString('<script', $output);
        $this->assertStringNotContainsString('<style', $output);
        $this->assertTrue(wp_script_is('mds-contact-form', 'enqueued'));
        $this->assertTrue(wp_style_is('mds-contact-form', 'enqueued'));
    }

    public function test_submission_validation_and_honeypot() {
        $this->assertWPError($this->extension->process_submission([
            'name' => '',
            'email' => 'invalid',
            'message' => '',
        ]));
        $this->assertWPError($this->extension->process_submission([
            'honeypot' => 'filled',
            'name' => 'Test',
            'email' => 'test@example.test',
            'message' => 'Message',
        ]));
    }

    public function test_submission_is_stored_without_raw_request_metadata() {
        global $wpdb;
        $messages = [];
        $mail_filter = static function ($return, $atts) use (&$messages) {
            $messages[] = $atts;
            return true;
        };
        add_filter('pre_wp_mail', $mail_filter, 10, 2);

        $email = 'stored-' . $this->email_suffix . '@example.test';
        $result = $this->extension->process_submission([
            'name' => '<b>Stored Person</b>',
            'email' => $email,
            'subject' => 'Stored subject',
            'message' => '<script>alert(1)</script>Stored message',
        ]);
        remove_filter('pre_wp_mail', $mail_filter, 10);

        $this->assertIsArray($result);
        $this->created_ids[] = absint($result['submission_id']);
        $submission = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $result['submission_id']));
        $this->assertSame('Stored Person', $submission->name);
        $this->assertSame('', $submission->ip_address);
        $this->assertSame('', $submission->user_agent);
        $this->assertSame('sent', $submission->notification_status);
        $this->assertCount(1, $messages);
        $this->assertSame('contact-tests@example.test', $messages[0]['to']);
    }

    public function test_fourth_submission_is_rate_limited() {
        add_filter('pre_wp_mail', '__return_true');

        for ($index = 1; $index <= 3; $index++) {
            $result = $this->extension->process_submission([
                'name' => 'Rate Test ' . $index,
                'email' => 'rate-' . $index . '-' . $this->email_suffix . '@example.test',
                'message' => 'Rate test message.',
            ]);
            $this->assertIsArray($result);
            $this->created_ids[] = absint($result['submission_id']);
        }

        $limited = $this->extension->process_submission([
            'name' => 'Rate Test 4',
            'email' => 'rate-4-' . $this->email_suffix . '@example.test',
            'message' => 'Rate test message.',
        ]);
        remove_filter('pre_wp_mail', '__return_true');

        $this->assertWPError($limited);
        $this->assertSame('mds_contact_form_rate_limit', $limited->get_error_code());
    }

    public function test_privacy_export_and_erasure() {
        add_filter('pre_wp_mail', '__return_true');
        $email = 'privacy-' . $this->email_suffix . '@example.test';
        $result = $this->extension->process_submission([
            'name' => 'Privacy Test',
            'email' => $email,
            'message' => 'Private message.',
        ]);
        remove_filter('pre_wp_mail', '__return_true');
        $this->created_ids[] = absint($result['submission_id']);

        $export = $this->extension->privacy_exporter($email, 1);
        $this->assertCount(1, $export['data']);
        $this->assertTrue($export['done']);

        $erasure = $this->extension->privacy_eraser($email, 1);
        $this->assertTrue($erasure['items_removed']);
        $this->assertEmpty($this->extension->privacy_exporter($email, 1)['data']);

        global $wpdb;
        $this->assertSame('erased', $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$this->table_name} WHERE id = %d",
            $result['submission_id']
        )));
    }

    public function tearDown(): void {
        global $wpdb;
        delete_transient($this->rate_key);
        foreach (array_unique(array_filter($this->created_ids)) as $submission_id) {
            $wpdb->delete($this->table_name, ['id' => absint($submission_id)], ['%d']);
        }
        delete_option('mds_contact_form_settings');
        delete_option('mds_contact_form_custom_field_settings');
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }
}
