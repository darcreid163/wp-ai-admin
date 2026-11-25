# Elementor UI + AI document version contract — investigation (Issue #13)

**Status:** design / not a production feature. The full-document staged executor remains gated to disposable CI. This is NOT a claim that third-party editor saves are safely serialized.

## Why we need a two-sided contract

The tests in `tests/wordpress-elementor-external-editor-race.php` and `tests/wordpress-elementor-external-transaction.php` show both directions of lost update. Holding `SELECT ... FOR UPDATE` during the AI save can serialize MySQL writers, but an Elementor browser session that loaded a stale tree can save it after the transaction ends and overwrite the AI's earlier change. A lock without an **expected loaded revision** on the human request does not prevent that.

Official Elementor source review (verify exact installed versions separately):
- Document-manager save handler: https://github.com/elementor/elementor/blob/main/core/documents-manager.php — the `ajax_save` path checks access, assembles `elements` and `settings`, calls `$document->save($data)`, and returns the resulting config.
- Document save lifecycle: https://github.com/elementor/elementor/blob/main/core/base/document.php — uses `elementor/document/save/data` filter and `elementor/document/before_save` / `elementor/document/after_save` hooks; saving can affect CSS, caches and revisions.

These source paths do NOT by themselves establish an atomic optimistic version token enforced for every Elementor browser save. We have not yet proven such a contract exists in Elementor 4.3.3 or the newest profile. Treat the browser as a potentially non-cooperating writer.

## Required semantics for a safe writer contract

1. When a human opens a document, issue a signed or server-derived `(site_id, post_id, meta_row_id, content_version, tree_sha256)` baseline bound to that editing session; **persist the baseline outside user-modifiable fields**.
2. For **every** Elementor browser save, require and validate the exact baseline against current DB value **at the persistence boundary** (not in an early REST/HTTP pre-filter). Missing/unknown baseline must fail closed or enter a documented safe compatibility mode that blocks concurrent AI full-document edits.
3. The AI writer must use the same versioning/lock path. Claims against the operation row alone cannot protect Elementor `_elementor_data`.
4. Serialize the version compare plus write with compatible transactions/row locks while ensuring WordPress/Elementor native hooks, caches, CSS, revision and autosave semantics. Reject stale save with explicit conflict and retain the newer document byte-for-byte.
5. Require a single authoritative metadata row resolver. The staged `OAI_Elementor_Schema_Save::canonical_meta_row` fails closed on missing/duplicate/malformed `_elementor_data`; both guard and verify use it (DeepSeek G1), and it returns `meta_id` for future durable receipts. This fixes a **read-consistency mismatch**, not the cross-editor TOCTOU window.
6. Human UI must show a visible choice to reload/compare/reapply changes after a conflict; no silent last-writer-wins retry by the plugin.
7. Plugins/raw SQL that do not follow the shared version protocol must be declared out of contract; raw DB edits and transaction interleavings need explicit negative tests and incident reporting.

## Negative acceptance matrix

- AI preflight → human commit → AI CAS: **AI refused**, no human loss.
- Human loads v1 → AI commit v2 → human submits v1: **human save refused**, no AI loss.
- Two browser tabs v1: first save wins; second save gets version conflict, not silent overwrite.
- Autosave/revision attempts must not accidentally update the parent Elementor content without expected revision.
- Duplicate/missing Elementor meta rows: guard **and** Verify reject consistently; never select different rows.
- A direct SQL writer and an Elementor editor save happen during AI transaction: explicitly document what can bypass version checks.
- Aborted/deadlocked DB transaction: no half-applied document/receipt/usage charge.
- Native Elementor save hooks, CSS regeneration and cache invalidation preserved, pinned/latest profiles.
- All behavior independently reviewed by DeepSeek in its own QA branch; do not merge without maintainer approval.

## Decision

**Keep full-document AI Apply/Rollback disabled on production.** Pending hard, two-sided version enforcement at actual Elementor persistence, prefer a limited preview/read/sandbox workflow over risking user edits. Follow-up evidence and exact tests go into Issue #13; DeepSeek's execution evidence work remains isolated in Issue #14.
