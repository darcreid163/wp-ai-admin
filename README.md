# WP AI Admin — Native WordPress Plugin

WP AI Admin provides an MCP-compatible WordPress integration with support for Elementor, WooCommerce, content, file tools, controlled changes, verification and rollback.

## 2.1.16 — Public release

This release makes the public source match the 2.1.15 hardening line and adds the independent work merged in this repository. `OAI_DB_VERSION` stays at 23, so there is **no database migration** between 2.1.14, 2.1.15 and 2.1.16.

- **Safe deactivation.** Deactivating the plugin immediately disables AI Lifecycle Control and Test Automation and clears operation leases; the state stays disabled after reactivation. Site identity, installation identity, OAuth/MCP credentials and license state are preserved.
- **Release qualification.** The 2.1.15 executor and lifecycle hardening is included: operation id/idempotency hardening, lease and recovery race handling, operations cleanup, quota retention, managed QA fixtures, reactivation and Woodmart error paths.
- **Expanded read-only CLI.** `wordpress.execute` resolves 41 commands instead of 21, including `post meta get/list`, `user meta get/list`, `comment list/count`, `role list`, `cap list`, `theme mod get/list`, `menu item list`, `db tables`, `db prefix` and `post-type`/`taxonomy` listing. Credential-like meta is redacted, comment output carries no PII, and payloads are bounded. Writes remain `plan_only` through sandbox → approval → apply → verify → receipt → rollback.
- **Independent QA gates in CI.** The adversarial Elementor reproducer, the publication/licensing gate and the expanded CLI suite run on every push, so a regression in the closed authorization paths fails the build.

## Repository boundaries

This repository contains **Native WordPress code only**. It is intended for an open-source release under **GPL-2.0-or-later**. The central hosted MCP Gateway, OAuth issuer, account services, subscription, server quotas, encrypted credentials and multi-tenant control plane are maintained separately and are **not** distributed in this repository.

## Compatibility baseline

- WordPress native baseline: 2.1.14; central gateway is a separate deployment.
- Existing native connection identity and browser consent flow must remain compatible.
- Production changes require the existing server-authorized human approval workflow.
- The schema/save adapter in `includes/class-oai-elementor-schema-save.php` is staged, not registered as a public tool, and must not be used as a direct authorization route.

## Tests

- `php tests/elementor-schema-save-stage1.php`
- `php tests/elementor-single-source.php`
- `php tests/woocommerce-stock-rounding.php`
- `php tests/option-normalization.php`
- `php tests/control-plane-entitlements.php`

The workflow `.github/workflows/native-validate.yml` runs lint and smoke regression tests on standard GitHub runners. Real WordPress/Elementor acceptance, OAuth, quota and rollback checks remain release gates; a passing fixture alone is insufficient.

## Security and licensing

This repository must never contain private gateway source, credentials, production configuration, secret keys or private customer data. Never commit dumps of databases or installation artifacts with sensitive content.

Copyright (c) original project authors and contributors. GPL-2.0-or-later. See `LICENSE`. This license applies to distributable Native code, not separately hosted backend services.

## Deployment

**Do not deploy this migration snapshot to production.** Use versioned, reviewed and tested release artifacts, then obtain separate approval before changing a live site.
