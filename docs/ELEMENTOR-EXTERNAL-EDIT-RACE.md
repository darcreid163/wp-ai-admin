# Elementor full-document concurrent editor safety — P0 / Issue #13

**Release state: BLOCKED.** Owner: ChatGPT implementation, DeepSeek independent QA. See https://github.com/darcreid163/wp-ai-admin/issues/13.

## Actual gap: external writer between check and save

An operation-table CAS only prevents two WP AI Admin operations claiming the same approved operation. It does NOT protect the Elementor document against a human browser save, WordPress post-meta writer, another plugin, or direct SQL modification. The Elementor document save pipeline has no shared content-version compare-and-swap with those writers.

A repeatable **two-process** failure is now included in tests/wordpress-elementor-external-editor-race.php (WP-CLI workers coordinated through MySQL):

1. AI process verifies original page snapshot and independently persisted MySQL Elementor data and pauses.
2. Another independent PHP process saves a different heading through the normal Elementor document API.
3. The AI process resumes and calls the same native save adapter with an earlier approved target document.
4. The AI save overwrites the other editor's change. The test expects F3_UNSAFE_RACE_REPRODUCED and F3_CONFIRMED_UNSAFE_GAP_REMAINS.

**This is an expected vulnerability reproducer in disposable WordPress, NOT a passing safety gate.** CI success means the diagnosis is reproducible.

## Incremental protections implemented in the staged branch

- OAI_Elementor_External_Edit_Guard::inspect requires an authorized current administrator, a real WordPress page, and consistent live Elementor API and MySQL document images.
- An active WordPress _edit_lock from another editor blocks the operation before saving; a current administrator's own lock does not.
- Missing/ambiguous Elementor metadata and differing persistent content fail closed.
- The staged document executor calls this guard after operation CAS and just before Elementor save.
- **Safety stop:** the staged executor, including operator reconciliation, refuses invocation outside a disposable test origin/environment and explicit PHP constant OAI_ELEMENTOR_DOCUMENT_STAGED_TEST=true. A normal installation returns oai_document_staged_only even if the file is included by mistake. No runtime REST/MCP endpoint is registered.

These checks do not prevent another writer after the last read. WordPress edit locks are advisory, not DB mutexes; raw SQL ignores application-level locks.

## Options requiring independent design review

1. **Remain disabled for production** until persistence supports a genuine atomic content-version write or every relevant UI and AI writer shares an enforced coordination contract.
2. Evaluate DB-backed compare-and-swap over exact stored Elementor bytes only if the native save hooks, cache, CSS generation, normalization, revision behavior and rollback can be preserved. Never silently replace Elementor's save pipeline with raw SQL.
3. Evaluate transactions and row locks in disposable MySQL under two-process interleavings; identify bypasses such as direct SQL, inserts/deletes, alternate editors and duplicate metadata. Do not adopt production transactions without crash/deadlock tests.
4. Limit feature scope to reads, schema, preview or user-mediated patches without automated whole-document writes if no strong writer contract is possible.

## Acceptance criteria

- A two-process external-editor test that reproduced lost updates must demonstrate **no overwritten external edit**, not just detect data loss afterward.
- Exact trusted before-image, actor, post/version identity, human approval, atomic content claim and completion receipts must be verified.
- Test WordPress native editor, Elementor UI, REST/WP API, postmeta writes, raw DB edits and failures; explicitly document which writers share enforcement.
- DeepSeek independently reviews the actual semantics in a separate QA PR with exact code SHA and CI.
- Keep the fail-closed staged switch and do not ship this feature while P0 issues remain open.

## Evidence

- Native 2.1.16 compatibility: https://github.com/darcreid163/wp-ai-admin/actions/runs/39423883147 — 6 relevant jobs passed.
- External editor race reproduction: https://github.com/darcreid163/wp-ai-admin/actions/runs/39424413203 — both WP profiles show unsafe interleaving.
- Issue #14 independently tracks durable execution proof, crash recovery and accounting: https://github.com/darcreid163/wp-ai-admin/issues/14 .

The draft PR's installable CI archive is not an approved production release.
