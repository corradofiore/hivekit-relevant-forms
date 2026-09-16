=== Sentinel Forms ===
Contributors: corradofiore
Tags: forms, contact form, spam protection, file upload, lead forms
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free WordPress forms with entries, local anti-spam and optional Sentinel Cloud semantic filtering, priority alerts and managed secure uploads.

== Description ==

**Sentinel Forms Core is a free, standalone WordPress form plugin.**

Create forms, store entries, send email notifications, use local anti-spam controls and manage everyday form workflows without purchasing any subscription.

When a site needs more than ordinary form handling, administrators can optionally connect **Sentinel Cloud**, a paid external service performing substantive remote functionality: semantic message classification, managed SMS alerts, private object storage and malware scanning.

Core does not require Sentinel Cloud to render or accept ordinary forms.

= Build forms without a paid account =

Core functionality includes:

* Common form fields (text, textarea, email, telephone, number, URL, select, radio, checkbox, consent, hidden, date, file)
* Entry storage in your own WordPress database
* Entries inbox with statuses and spam/suspicious triage
* Email notifications
* Shortcode and template function embedding: `[sentinel_form id="123"]` and `sentinel_forms_render( 123 )`
* Local anti-abuse controls: honeypot, timing checks, rate limiting, duplicate detection and spam signals
* Local secure-upload fallback: files are stored privately with strict validation and a scan-before-download policy
* WPForms compatibility scanner and importer for supported field types (drafts only; your WPForms data is never modified)

= Optional Sentinel Cloud – forms that understand the message =

Traditional bot controls ask whether a visitor appears human. Sentinel Cloud can additionally analyze whether the content is relevant to the purpose of the form.

For example, an emergency-plumbing form might receive:

> "Water is coming through the kitchen ceiling. Can someone come today?"

That can be recognized as highly relevant and urgent. The same form might receive:

> "We can improve your Google rankings with our SEO service."

The sender may be human, but the message is unrelated unsolicited marketing.

When explicitly enabled, Sentinel Cloud returns validated signals such as spam probability, relevance, urgency, intent, category and a short summary. Core uses these results to help prioritize entries.

= Optional priority SMS alerts =

Important submissions should not look identical to every other email. When managed SMS is configured, a high-priority submission can trigger a short SMS alert while ordinary submissions continue through normal email and inbox handling. Alerts contain the classification summary and a secure link to the entry (WordPress login required) – never the full submission.

= Optional managed secure uploads =

Sentinel Cloud can provide managed private object storage for form uploads:

1. Core requests a tightly scoped, short-lived upload authorization.
2. The browser uploads directly to private object storage (large files never pass through your PHP).
3. The file stays quarantined while validation and malware scanning run.
4. Only clean files are released, through authenticated or short-lived signed downloads.

Permanent cloud credentials are never exposed to the visitor's browser.

= Privacy and control =

External processing is optional. Core does not send form submissions to Sentinel Cloud merely because the plugin is installed or activated: an administrator must connect the site and explicitly enable each capability. Redaction (emails, phone numbers, IP addresses, URL paths) is applied by default before semantic classification. Administrators can disconnect Sentinel Cloud at any time without disabling Core forms.

= Sentinel Pro =

Advanced local integrations may be offered separately through Sentinel Pro, a separately distributed add-on plugin that extends Core through documented extension points. It is not installed or downloaded automatically by this plugin.

== External services ==

This plugin works fully without contacting any external service. The services below are contacted only after an administrator explicitly configures the corresponding functionality.

= Sentinel Cloud =

Sentinel Cloud provides the paid managed functionality described above (semantic classification, managed SMS alerts, managed private storage and malware scanning). Core sends only the information needed by the capability the administrator has enabled, to the Cloud URL configured on the settings screen.

Service terms: [INSERT SENTINEL CLOUD TERMS URL]
Privacy policy: [INSERT SENTINEL PRIVACY POLICY URL]
Subprocessors: [INSERT SENTINEL SUBPROCESSORS URL]

= AI model providers =

When semantic classification is enabled, configured and redacted submission text is processed by Sentinel Cloud and its configured model provider(s). The current provider list is documented on the Sentinel subprocessors page linked above.

= SMS providers =

When managed SMS is enabled, Sentinel Cloud transmits the destination telephone number and the minimal alert content (classification summary and secure entry link) to its configured messaging provider.

= Object storage and malware scanning =

When managed secure uploads are enabled, uploaded files are stored in private object storage and processed by the configured malware-scanning infrastructure before being released for authorized download.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/sentinel-forms` directory, or install the plugin through the WordPress Plugins screen directly.
2. Activate the plugin.
3. Create a form under **Sentinel Forms → Forms**, or import supported forms from WPForms under **Sentinel Forms → Import**.
4. Embed a form with the `[sentinel_form id="123"]` shortcode or the `sentinel_forms_render( 123 )` template function.
5. Submit a test entry and verify local storage and email behavior.
6. Optionally, connect Sentinel Cloud from **Sentinel Forms → Settings** to enable semantic classification, managed SMS alerts or managed secure uploads.

== Frequently Asked Questions ==

= Is Sentinel Forms Core free? =

Yes. Core is intended to remain useful without purchasing Sentinel Cloud or Sentinel Pro.

= What is Sentinel Cloud? =

Sentinel Cloud is an optional paid external service that performs remote functions such as semantic classification, managed SMS, managed private storage and malware scanning.

= Does my form stop working if Sentinel Cloud is unavailable or my subscription ends? =

No. Core form rendering, local entry storage and ordinary local functionality continue to work. Only features that require the paid remote service become unavailable while that service is not entitled.

= Does activating the plugin automatically send my data to an AI service? =

No. External semantic classification must be explicitly configured and enabled by an administrator, and submissions are only classified for forms that opted in.

= Is Sentinel Pro included in this plugin? =

No. Sentinel Pro, if offered, is a separate add-on distributed outside WordPress.org. This plugin does not automatically download or install it.

= Can I migrate from WPForms? =

Sentinel Forms can scan and import supported WPForms configurations as drafts without deleting or modifying the source forms. Compatibility varies by field type and integration; the scanner reports what will be imported and what will be skipped before migration.

Sentinel Forms is an independent product and is not affiliated with or endorsed by WPForms or its owners.

== Changelog ==

= 1.0.0 =
* Initial release.
* Forms: builder with the common field set, schema versioning, entries inbox, email notifications.
* Security: honeypot, timing checks, rate limiting, duplicate detection, spam signals, local spam triage.
* Uploads: local private quarantine with strict validation and a scan-before-download policy.
* Migration: WPForms compatibility scanner and draft importer, plus a Tier 1 hook compatibility layer that runs only while WPForms itself is not active.
* Optional Sentinel Cloud: explicit consent connection flow, semantic classification with redaction, priority SMS alerts, managed secure uploads, usage metering and per-site subscriptions with hosted checkout.
* Privacy: no external calls before explicit configuration; the plugin is fully functional without Sentinel Cloud.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
