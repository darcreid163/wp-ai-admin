# WP AI Admin — Native WordPress Plugin

WP AI Admin provides an MCP-compatible WordPress integration with support for Elementor, WooCommerce, content, file tools, controlled changes, verification and rollback.

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
