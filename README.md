# Relevant Forms

**WordPress forms that understand which messages matter.**

A free WordPress plugin for building forms, storing entries and keeping spam out
without shipping every visitor's message to a third party. Semantic filtering,
priority SMS alerts and managed private uploads are available through the
optional hosted service; the free plugin works fully without it.

## What it does

- **Forms** — the common field set (text, textarea, email, telephone, number,
  URL, select, radio, checkbox, consent, hidden, date, file), multi-page forms
  with step navigation, content blocks and custom HTML, conditional visibility
  with AND/OR groups, and a form preview tab that renders the live form inside
  the editor.
- **Entries** — every submission is stored, with a version snapshot, meta,
  spam signals and an event trail. Nothing is deleted silently.
- **Local anti-spam** — honeypot, timing checks, rate limiting, duplicate
  detection and a local spam score. No external call is needed for any of it.
- **Email notifications** — plain-text submission emails through WordPress,
  with per-form recipients and sender identity.
- **Save and Resume** — visitors can store partial progress and receive an
  emailed link that restores their answers; a browser-local draft keeps progress
  without leaving the device.
- **Payments** — Stripe Connect card payments (fixed items, priced choices,
  donation-style amounts, live total) on the site owner's own Stripe account.
- **Builder integrations** — block editor, Elementor, Divi (4 and 5), Beaver
  Builder, Bricks and WPBakery, plus the `[relevant_form id="123"]` shortcode
  anywhere else.

## Optional hosted service

Connecting the plugin to Relevant Cloud is opt-in and per capability. Until an
administrator connects the site and enables a capability, the plugin makes no
external request at all. Enabling it adds semantic classification of
submissions, priority SMS alerts, managed private storage and malware scanning.
The service is what this repository does *not* contain: this project is the
plugin, and it talks to the service over its API.

Data and privacy details, including every external service involved and what is
sent to it, are documented in `readme.txt`, which is also the WordPress.org
plugin description.

## Requirements

- WordPress 6.9 or newer
- PHP 8.3 or newer

## Installation

Install from the WordPress plugin directory once published, or download a
release and upload the zip under **Plugins → Add New → Upload Plugin**.

## Development

The plugin is plain PHP with a PSR-4 autoloader, so a checkout works without a
build step.

```bash
composer install                  # dev dependencies

composer lint                     # WordPress Coding Standards
composer lint:compat              # PHP 8.3 - 8.5 compatibility
composer phpstan                  # static analysis
composer test:unit                # unit tests
composer test:integration:start   # wp-env, requires Docker
composer test:integration
composer test:integration:stop
```

Integration tests run inside `wp-env`, which needs Docker and a WordPress
checkout it manages itself. The suite is self-contained: `test:integration:start`
brings the environment up, `test:integration` runs PHPUnit inside it, and
`test:integration:stop` tears it down.

### Building a release

```bash
composer build:release             # production artifact, dist/
composer build:release:qa          # test artifact pinned to another service
```

Both artifacts contain runtime files only — no tests, no dev tooling and no
vendored dependencies. The build verifies the staged tree against an allowlist
of runtime paths, and asserts which service each artifact points at, so a test
build can never be mistaken for a release.

## Licensing

GPL-2.0-or-later. See `LICENSE` for the full text.

## Contributing

Issues and pull requests are welcome — see `CONTRIBUTING.md`. Please keep
changes covered by tests and passing `composer lint`, `composer phpstan` and
`composer test:unit` before opening a pull request.
