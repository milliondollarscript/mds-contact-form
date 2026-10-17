# Contact Form Usage

Contact Form adds a simple visitor message form with email notifications, stored submissions, spam throttling, privacy export support, and optional reusable custom fields.

## Practical Examples

- Add a short support form that stores every request even when the host cannot deliver its notification email.
- Create a sponsorship inquiry form with company, budget, campaign date, and website fields supplied by the optional Fields extension.
- Place different forms on sales, installation, and general-contact pages while overriding the heading, subject field, button text, and reusable fields for each page.

### Example workflow: qualify sponsorship inquiries

1. In Fields, create a group containing company, campaign URL, budget, and preferred launch date fields.
2. Open **Million Dollar Script -> Extensions -> Contact Form -> Settings**, choose **Show fields from selected groups**, and select that group.
3. Add the **Contact Form** block to a sponsorship page and customize its heading and submit button.
4. Review each message and its structured responses under **Submissions**; retry the WordPress email notification there if delivery failed.

## Admin Workspace

The workspace opens on **Submissions** for message review and delivery status. Use **Settings** for email, retention, and custom-field configuration, and **Usage** for shortcode guidance. Each tab has a direct URL and identifies the active section to assistive technology.

## Add a Form

Use the Contact Form block or add this shortcode to any page:

```text
[mds_contact_form]
```

Shortcode attributes:

- `title`: Heading shown above the form. Leave it blank to hide the heading.
- `show_subject`: Use `true` or `false` to show or hide the subject field.
- `button_text`: Text shown on the submit button.
- `success_message`: Message shown after a successful submission.
- `custom_fields`: Use `settings`, `none`, `all`, `groups`, `fields`, or a comma-separated list of field keys.
- `field_groups`: Comma-separated group keys when `custom_fields="groups"`.
- `field_keys`: Comma-separated field keys when `custom_fields="fields"`.

Examples:

```text
[mds_contact_form title="Contact Us"]
[mds_contact_form show_subject="false" custom_fields="none"]
[mds_contact_form custom_fields="fields" field_keys="company,budget"]
```

## Reuse Fields Extension Fields

When Fields is active, open **Million Dollar Script -> Extensions -> Contact Form -> Settings** and choose the default custom-field mode:

- **Do not show custom fields** keeps forms limited to name, email, subject, and message.
- **Show all compatible fields** adds every supported active field from Fields.
- **Show fields from selected groups** adds active fields from the groups you select.
- **Show selected fields only** adds only the field keys you select.

Individual shortcodes can override the default settings. This is useful when one contact page needs a short support form and another page needs extra fields for sales or sponsorship inquiries.

If no compatible field provider is active, the settings page shows a small callout linking to the Million Dollar Script Extensions page. The base contact form continues to work without Fields.

Supported Fields types on Contact Form:

- Text
- Textarea
- Email
- URL
- Phone
- Number
- Select
- Radio
- Checkbox
- Date
- Color

File and image fields are not shown on Contact Form yet. They require a dedicated secure upload flow in Contact Form so uploaded files can be validated, stored, replaced, and erased correctly.

Text inputs and provider definitions are bounded before storage. Names and subjects allow up to 200 characters, email addresses up to 254 characters, messages and textarea fields up to 10,000 characters, and other custom text values up to their documented field-type limit. A smaller limit configured in Fields takes precedence. URL fields accept HTTP or HTTPS addresses without embedded usernames or passwords. Choice values must match the configured options, and custom validation patterns run with bounded processing limits.

## Submissions, Email, and Privacy

Open **Million Dollar Script -> Extensions -> Contact Form -> Settings** to configure:

- Whether new submissions send an email notification.
- The email address that receives notifications.
- How many days submissions remain stored. A value of `0` keeps them until an administrator deletes or erases them.

Contact Form stores a message before attempting its email notification. If WordPress cannot send the email, the submission remains available and is marked **Failed** under **Submissions**. An administrator can retry that notification from the submission row.

The **Submissions** tab provides the complete message, reusable field responses, read/unread status, email notification status, and secure controls to retry or permanently delete a submission. On small screens, each submission is presented as a labeled record instead of requiring a wide table.

Contact Form stores submitted custom-field values with their field labels, field types, raw values, and formatted display values. Stored values appear in:

- Admin submission lists.
- Administrator email notifications.
- WordPress personal data exports.

The WordPress personal data eraser anonymizes matching Contact Form submissions by replacing direct personal fields, clearing custom-field values, and preserving an erased row for audit continuity.

Contact Form does not retain raw visitor IP addresses or browser user-agent strings. Spam throttling uses a non-reversible keyed value that expires after ten minutes. Updating from an earlier development version clears legacy request metadata from stored submissions.

The daily retention cleanup uses WordPress cron. Low-traffic sites may run the cleanup on the first visit after its scheduled time, which is standard WordPress cron behavior.

## Local Workflow Verification

After syncing the extension into the local WordPress stack, run:

```bash
./scripts/wp eval-file wp-content/plugins/mds-contact-form/tests/mds3-fixture.php
./scripts/wp eval-file wp-content/plugins/mds-contact-form/tests/mds-fields-fixture.php
./scripts/wp eval-file wp-content/plugins/mds-contact-form/tests/fields-upsell-fixture.php
```

The primary fixture intercepts outgoing mail and removes the submissions it creates. It does not send external email.

## Developer Hooks

Field providers can integrate without making Contact Form depend on their database tables.

```php
add_filter('mds_contact_form_custom_field_catalog', 'my_provider_catalog');
add_filter('mds_contact_form_custom_field_definitions', 'my_provider_fields', 10, 2);
add_filter('mds_contact_form_validate_custom_fields', 'my_provider_validate', 10, 4);
add_filter('mds_contact_form_sanitize_custom_field_values', 'my_provider_sanitize', 10, 4);
```

Field descriptors should include:

- `key`: Stable field key.
- `label`: Human-readable label.
- `type`: One of the supported Contact Form field types.
- `required`: Boolean required state.
- `help`: Optional help text.
- `source`: Provider slug.
- `group_key` and `group_label`: Optional grouping details.
- `options`: Placeholder, rows, limits, pattern, and choices when applicable.
- `validation`: Provider-specific validation data.
