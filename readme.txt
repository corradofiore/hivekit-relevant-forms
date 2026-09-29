=== Relevant Forms ===
Contributors: corradofiore
Tags: forms, contact form, spam protection, file upload, conditional logic
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free WordPress forms with entries, local anti-spam and optional Relevant Cloud semantic filtering, priority alerts and managed secure uploads.

== Description ==

**Relevant Forms Core is a free, standalone WordPress form plugin.**

Create forms, store entries, send email notifications, use local anti-spam controls and manage everyday form workflows without purchasing any subscription.

When a site needs more than ordinary form handling, administrators can optionally connect **Relevant Cloud**, a paid external service performing substantive remote functionality: semantic message classification, managed SMS alerts, private object storage, malware scanning and payment brokering.

Core does not require Relevant Cloud to render or accept ordinary forms.

= Build forms without a paid account =

Core functionality includes:

* Common form fields (text, textarea, email, telephone, number, URL, select, radio, checkbox, consent, hidden, date, file)
* Multi-page forms: page breaks split long forms into steps with Previous/Next navigation and a "Step X of Y" indicator
* Entry preview: an optional review step that shows the answers entered so far, updated live, before the visitor submits
* Content blocks for formatted instructions (headings, links, lists and images) between fields, plus a custom HTML field for embedded code (rendered as authored and editable only by users who may save unfiltered HTML)
* Conditional field visibility: show or hide fields based on earlier answers, with rule groups (AND within a group, OR across groups)
* Payment fields: fixed single items, priced dropdowns and choices, donation-style user-entered amounts and an automatic live total (collecting payments requires connecting your own Stripe account)
* Entry storage in your own WordPress database
* Entries inbox with statuses and spam/suspicious triage
* Email notifications
* Shortcode and template function embedding: `[relevant_form id="123"]` and `relevant_forms_render( 123 )`
* Local anti-abuse controls: honeypot, timing checks, rate limiting, duplicate detection and spam signals
* Local secure-upload fallback: files are stored privately with strict validation and a scan-before-download policy
* WPForms compatibility scanner and importer for supported field types — including page breaks, dividers, entry previews, content blocks and custom HTML — and conditional logic (drafts only; your WPForms data is never modified), with a Tier 1 hook compatibility layer that keeps submission integrations working after switching

= Optional Relevant Cloud – forms that understand the message =

Traditional bot controls ask whether a visitor appears human. Relevant Cloud can additionally analyze whether the content is relevant to the purpose of the form.

For example, an emergency-plumbing form might receive:

> "Water is coming through the kitchen ceiling. Can someone come today?"

That can be recognized as highly relevant and urgent. The same form might receive:

> "We can improve your Google rankings with our SEO service."

The sender may be human, but the message is unrelated unsolicited marketing.

When explicitly enabled, Relevant Cloud returns validated signals such as spam probability, relevance, urgency, intent, category and a short summary. Core uses these results to help prioritize entries.

= Optional priority SMS alerts =

Important submissions should not look identical to every other email. When managed SMS is configured, a high-priority submission can trigger a short SMS alert while ordinary submissions continue through normal email and inbox handling. Alerts contain the classification summary and a secure link to the entry (WordPress login required) – never the full submission.

= Optional managed secure uploads =

Relevant Cloud can provide managed private object storage for form uploads:

1. Core requests a tightly scoped, short-lived upload authorization.
2. The browser uploads directly to private object storage (large files never pass through your PHP).
3. The file stays quarantined while validation and malware scanning run.
4. Only clean files are released, through authenticated or short-lived signed downloads.

Permanent cloud credentials are never exposed to the visitor's browser.

= Privacy and control =

External processing is optional. Core does not send form submissions to Relevant Cloud merely because the plugin is installed or activated: an administrator must connect the site and explicitly enable each capability. Redaction (emails, phone numbers, IP addresses, URL paths) is applied by default before semantic classification. Administrators can disconnect Relevant Cloud at any time without disabling Core forms.

= Relevant Pro =

Advanced local integrations may be offered separately through Relevant Pro, a separately distributed add-on plugin that extends Core through documented extension points. It is not installed or downloaded automatically by this plugin.

== External services ==

This plugin works fully without contacting any external service. The services below are contacted only after an administrator explicitly configures the corresponding functionality.

= Relevant Cloud =

Relevant Cloud provides the paid managed functionality described above (semantic classification, managed SMS alerts, managed private storage, malware scanning and payment brokering). Core sends only the information needed by the capability the administrator has enabled and connected, to the Relevant Cloud service (api.relevantcloud.com).

Service terms: [INSERT RELEVANT CLOUD TERMS URL]
Privacy policy: [INSERT RELEVANT PRIVACY POLICY URL]
Subprocessors: [INSERT RELEVANT SUBPROCESSORS URL]

= AI model providers =

When semantic classification is enabled, configured and redacted submission text is processed by Relevant Cloud and its configured model provider(s). The current provider list is documented on the Relevant subprocessors page linked above.

= SMS providers =

When managed SMS is enabled, Relevant Cloud transmits the destination telephone number and the minimal alert content (classification summary and secure entry link) to its configured messaging provider.

= Object storage and malware scanning =

When managed secure uploads are enabled, uploaded files are stored in private object storage and processed by the Relevant Cloud malware-scanning service before being released for authorized download.

= Stripe (payments) =

Payment fields are local and free: nothing leaves the site until an administrator connects a Stripe account and enables the Payments capability. When enabled, Relevant Cloud creates the hosted Stripe Checkout session for each submission on the site owner's own Stripe account, so buyers pay the site owner directly and card data is entered on Stripe's pages only — it never touches this plugin or its database. Checkout amounts, currency and item descriptions defined in the form are sent to Relevant Cloud for this purpose. Relevant Cloud retains a 2% platform fee from each charge; the remainder settles on the connected Stripe account.

Stripe services agreement: https://stripe.com/legal/connect-account
Stripe privacy policy: https://stripe.com/privacy
Relevant Cloud platform fee terms: [INSERT RELEVANT CLOUD TERMS URL]
Subprocessors (including Stripe): [INSERT RELEVANT SUBPROCESSORS URL]

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/relevant-forms` directory, or install the plugin through the WordPress Plugins screen directly.
2. Activate the plugin.
3. Create a form under **Relevant Forms → Forms**, or import supported forms from WPForms under **Relevant Forms → Import**.
4. Embed a form with the `[relevant_form id="123"]` shortcode or the `relevant_forms_render( 123 )` template function.
5. Submit a test entry and verify local storage and email behavior.
6. Optionally, connect Relevant Cloud from **Relevant Forms → Settings** to enable semantic classification, managed SMS alerts or managed secure uploads.

== Frequently Asked Questions ==

= Is Relevant Forms Core free? =

Yes. Core is intended to remain useful without purchasing Relevant Cloud or Relevant Pro.

= How do card payments work, and what does Relevant Cloud charge? =

Payment fields are free and fully local; no payment provider is contacted until you connect your own Stripe account and enable the Payments capability. Buyers pay you directly on your Stripe account (hosted Stripe Checkout). Relevant Cloud brokers the connection and monitors each payment, and retains a 2% platform fee from each charge.

= What is Relevant Cloud? =

Relevant Cloud is an optional paid external service that performs remote functions such as semantic classification, managed SMS, managed private storage, malware scanning and payment brokering through Stripe.

= Does my form stop working if Relevant Cloud is unavailable or my subscription ends? =

No. Core form rendering, local entry storage and ordinary local functionality continue to work. Only features that require the paid remote service become unavailable while that service is not entitled.

= Does activating the plugin automatically send my data to an AI service? =

No. External semantic classification must be explicitly configured and enabled by an administrator, and submissions are only classified for forms that opted in.

= Is Relevant Pro included in this plugin? =

No. Relevant Pro, if offered, is a separate add-on distributed outside WordPress.org. This plugin does not automatically download or install it.

= Can I migrate from WPForms? =

Relevant Forms can scan and import supported WPForms configurations as drafts without deleting or modifying the source forms. Compatibility varies by field type and integration; the scanner reports what will be imported and what will be skipped before migration.

Relevant Forms is an independent product and is not affiliated with or endorsed by WPForms or its owners.

== Changelog ==

= 1.0.0 =
* Initial release.
* Forms: builder with the common field set, multi-page forms (page breaks with step navigation and a progress indicator), entry preview review steps, content blocks, custom HTML, conditional field visibility (show/hide rules with AND/OR groups), schema versioning, entries inbox, email notifications.
* Security: honeypot, timing checks, rate limiting, duplicate detection, spam signals, local spam triage.
* Uploads: local private quarantine with strict validation and a scan-before-download policy.
* Migration: WPForms compatibility scanner and draft importer (field types including page breaks, dividers, entry previews, content blocks and custom HTML code blocks, notifications, confirmations and conditional logic converted to visibility rules), plus a Tier 1 hook compatibility layer that runs only while WPForms itself is not active. Imported forms keep the original WPForms form ID, so integrations with per-form mappings — e.g. Newsletter subscription sources — keep working after the switch.
* Payments: Stripe Connect card payments (fixed items, priced choices, donation-style amounts, live total), hosted Stripe Checkout on the site owner's own Stripe account, 2% platform fee, admin refunds and WPForms payment-field import with amounts preserved.
* Optional Relevant Cloud: explicit consent connection flow, semantic classification with redaction, priority SMS alerts, managed secure uploads, usage metering and per-site subscriptions with hosted checkout.
* Privacy: no external calls before explicit configuration; the plugin is fully functional without Relevant Cloud.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
