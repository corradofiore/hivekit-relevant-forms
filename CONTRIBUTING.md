# Contributing

Thanks for taking the time to look at this. Bug reports, translations and pull
requests are all welcome.

## Before you start

- **Bugs**: open an issue with what you did, what you expected and what
  happened, plus your WordPress and PHP versions. A form export or the exact
  field configuration helps more than a description of it.
- **Security**: please do not open a public issue for a vulnerability. Report it
  privately so a fix can be prepared first.
- **Features**: open an issue before writing code for anything substantial, so
  we can agree on the shape of it. Small fixes need no ceremony — send the pull
  request.

## Setting up

You need PHP 8.3+ and Composer; the integration tests also need Docker.

```bash
composer install
composer lint          # WordPress Coding Standards
composer lint:compat   # PHP 8.3 - 8.5 compatibility
composer phpstan       # static analysis
composer test:unit     # unit tests
```

For the integration suite (runs WordPress in `wp-env`):

```bash
composer test:integration:start
composer test:integration
composer test:integration:stop
```

## What a pull request should contain

- **A test.** The suite runs on every push, and a behaviour change without one
  tends to reappear. Unit tests for logic, integration tests when WordPress
  itself is involved.
- **Passing checks.** `composer lint`, `composer lint:compat`, `composer phpstan`
  and `composer test:unit` all green. CI runs the same commands, plus the
  integration suite.
- **A focused change.** One topic per pull request; unrelated formatting makes
  the review slower and hides real changes.
- **Comments only where the code cannot say it itself.** The interesting parts
  of this codebase — why a retry is bounded, why a capability needs consent
  first — carry a short note explaining the reason, not the mechanics.

## House rules

- **WordPress APIs first.** Use `wpdb->prepare()` and bind identifiers with `%i`;
  escape output late, at the point of printing; sanitise input on the way in.
- **No external call without consent.** Anything that leaves the site happens
  only after an administrator connects the service and enables that capability.
  A feature that phones home by default will not be merged.
- **Never break a submission.** A failure in an optional step — a notification,
  an alert, a classification — must never lose the visitor's entry or stop the
  confirmation they were promised.
- **Accessibility and i18n.** Mark up forms with proper labels, and put every
  user-facing string through the `relevant-forms` text domain.

## Licence

By contributing you agree that your work is licensed under GPL-2.0-or-later,
the same licence as the project.
