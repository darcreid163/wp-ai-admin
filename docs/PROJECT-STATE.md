# WP AI Admin — shared project state

Last checked: **2026-10-20**. This file is the single handoff summary for independent coding agents. GitHub issues and PRs are the source of truth for work status; the repository itself remains the source of truth for code. Update this page only when a change is supported by a link to a commit, PR or test result.

## Product boundaries

WP AI Admin provides safe MCP access to WordPress sites, including Elementor, Gutenberg, WooCommerce, content, files and controlled administrative actions. The lifecycle is **Read → Sandbox → Human Approval → Apply → Verify → Receipt/Proof → Rollback**; failures and conflicts must fail closed. Human approval is not a boolean supplied by a caller.

### Repositories
- Public WordPress Native: `darcreid163/wp-ai-admin`. Only distributable Native code, documentation and safe test fixtures.
- Private Cloud Core: `darcreid163/wp-ai-admin-cloud-core`. Hosted Gateway, OAuth services, multi-site accounts, tenant isolation, quotas/usage and commercial implementation. Never copy the private source or its credentials into public PRs or an external model context.
- Historical source repository: `darcreid163/okean-ai-admin` remains private for comparison, not a public release path.
- Live `wpaiadmin.ru` and its connected customer sites **must not be modified by tests**.

### Code baselines vs release
- Public Native main was migrated from a **2.1.14** source baseline; newer deployed versions and their exact hashes have **not** been reconciled.
- Private Cloud was migrated from a **0.3.6** source baseline; subsequent deployed Cloud versions require a separate source-parity check.
- Staged Elementor Schema/Save `includes/class-oai-elementor-schema-save.php` is not loaded as an MCP mutation endpoint. It is not proof that production authorization has been integrated.
- The latest CI pass is a test of the exact commit under test, **not** clearance for production deployment.

## Verified evidence
| Component | State | Evidence |
|---|---|---|
| Original Native smoke and PHP 8.2/8.3/8.4 | Passed | https://github.com/darcreid163/wp-ai-admin/actions/runs/39394530925 |
| Native user consent, OAuth retention, WP lifecycle, Elementor Schema/Save, pinned/latest WordPress stack, Receipt checks | Passed in Draft PR #1 | https://github.com/darcreid163/wp-ai-admin/actions/runs/39400426178 |
| Independent collaboration protocol | Merged PR #4 into main (docs/templates/CODEOWNERS only) | https://github.com/darcreid163/wp-ai-admin/pull/4 |
| Authoritative document operation binding to Native DB and approvals | **In progress** | https://github.com/darcreid163/wp-ai-admin/issues/2 |
| Independent adversarial QA | **First report delivered, 9 gaps reproduced** | https://github.com/darcreid163/wp-ai-admin/pull/5 |
| Issue #2 staged integrity fixes on Draft PR #6 | **PHP 8.2/8.3/8.4 and WordPress passed** | https://github.com/darcreid163/wp-ai-admin/actions/runs/39404483886 |
| Live OAuth end-to-end, subscriptions, tenant isolation and verified release | **Not cleared** | Separate private acceptance required |

## Pricing decision (shared design, server enforcement only)
- Free: 100 successful actions / month.
- Pro: 500 successful actions / day.
- Business: 2,000 successful actions / day.
- BusinessPro: 5,000 successful actions / day.
- Successful reads and successful Sandbox creation count as actions.
- Batch counts each successfully changed object individually; failed actions and idempotent replays must not charge.
- Paid purchase requires explicit account confirmation; no automatic charges.
- Additional paid resets up to three times per month are deferred; never activate an unimplemented billing contract.
- The migrated private Cloud executable baseline has older pilot settings. The newer pricing is recorded as **approved target**, not claimed to be active.

## Agent collaboration policy

**Roles are assigned by task, not presumed model superiority.** Both models may write and review code, but their initial responsibilities are complementary.

| Area | ChatGPT primary | DeepSeek primary |
|---|---|---|
| Architecture, lifecycle integration, Native ↔ private Gateway contracts | Design and scoped implementation | Independent API and security review |
| Native Elementor Schema/Save | Issue #2 implementation and code changes under assigned `includes/` files | Review snapshot safety and check failure modes |
| Authorization, stale edits, rollback, idempotency | Integrate guards, test traces, CI evidence | Adversarial regression, edge cases and negative tests |
| QA, performance, backward-compatibility | CI matrix and remediation | Independent test design and failure reproduction (Issue #3) |
| Private Cloud Core source | Access only where connector permits | No access unless the owner explicitly authorizes |
| Merging, releasing, customer data and production | **Neither agent autonomously** | **Neither agent autonomously** |

### Collision rules
- Work in **separate branches**, created from a recorded baseline SHA. Do not write to the other agent's branch.
- DeepSeek's Issue #3 owns independent tests and QA comments; ChatGPT's Issue #2 owns integration code and its minimal own fixture. DeepSeek can request production-code fixes via Issue comments rather than editing the same PHP file in parallel.
- Handoff after each unit of work: **issue / branch / commit SHA / files touched / checks and CI URL / outstanding risks / next action**.
- Include concrete GitHub issue/PR links. Do not declare a task complete on the basis of chat text only.
- Apply fixes in new commits, rerun CI on the new SHA, and re-review new changes.
- Keep Draft PRs open until all blockers are resolved. Repository owner approves merge and release.

## Independent QA findings and current response

DeepSeek's first published QA deliverable: [Draft PR #5](https://github.com/darcreid163/wp-ai-admin/pull/5), [Issue #3](https://github.com/darcreid163/wp-ai-admin/issues/3). The independent reproducer documents nine gaps in the staged (unregistered) Elementor boundary. This is a real cross-agent handoff and supersedes the earlier "assigned" state.

| Finding | Severity | ChatGPT issue #2 response |
|---|---|---|
| GAP-1 payload `after_sha256` ignored | High | Integrity validation implemented in Draft PR #6; PHP + WordPress CI pass #39404483886 |
| GAP-2 rollback snapshot caller-controlled | High | OPEN: bind exclusively to persisted immutable OAI_DB before-image |
| GAP-3 boolean approval bypass | High | OPEN: replace with authoritative operation id and lifecycle context before any write route |
| GAP-4 no post-save authoritative compare | Medium | Readback verification implemented in Draft PR #6; PHP + WordPress CI pass #39404483886 |
| GAP-5 key-order false conflicts | Medium | OPEN: canonical equivalence design and tests |
| GAP-6 unscoped schema/snapshot | Medium | OPEN: capabilities, owner and post-status scope |
| GAP-7 no persisted Receipt/Proof in helper | Medium | OPEN: wire existing OAI_Receipts/OAI_Proof only through lifecycle |
| GAP-8 malformed SHA causes warning | Low | Typed validation implemented in Draft PR #6; PHP + WordPress CI pass #39404483886 |
| GAP-9 check-then-act race | Medium | OPEN: operation lease, atomic status transition and two-process DB test |

Rollback now also validates prepared hashes and checks the saved document by readback. This remains an **integrity check only**, not trustworthy operation provenance; GAP-2 and GAP-3 stay open until immutable OAI_DB-backed execution and full authorization are wired.

The new Stage-2 binding utility is **read-only** and is NOT registered at runtime. It narrows persisted type/status/site/post/image checks; it does not cure independent authorization or atomic execution. A passing CI does not close the open high-severity findings.

## Next release steps
1. Finish persisted Elementor document operation data contract, owner/site/client authorization and lifecycle state machine (Issue #2). Preflight-only utilities are **not** an authenticated executor.
2. Obtain independent DeepSeek security review and additional regressions for Issue #3; integrate their findings in separate fix commits.
3. Run Native ↔ Cloud Core API contract and cross-account isolation tests without publishing private server code.
4. Reconcile deployed package versions and restore legacy regression fixture parity.
5. Close release readiness, confirm safe migration and build signed/versioned archives. Production remains unchanged until owner approval.

## Explicitly out of scope now
Unbounded batch expansion, replacing all editors, auto-subscription charges, Cloud scaling architecture and quota reset enhancements. These will be handled after commercial release stability.

## 2026-10-20 follow-up: operation binding hardening

Primary integration Draft PR #6 (newest executable commit `26e51a89`) now also includes:

- Fail-closed WordPress owner/capability scoping for the **read-only** operation preflight: current actor must match the persisted `owner_user_id`, have `manage_options` plus `edit_post`, and target document may not be trashed/auto-draft.
- Requires persisted approval identity/date, denies expired Apply, and denies Rollback when no `applied_at` is recorded.
- `inspect_ai_authorized()` checks `OAI_AI_Lifecycle_Control::request_authorized` for the **exact operation and action** before returning the snapshot; no method here performs a write.
- A disposable WordPress/MySQL+Elementor test creates an operation and document, tests anonymous denial and missing scoped AI authorization, stale Apply, stale Rollback, and removes test data in finally.
- CI proof: [run #39407599458](https://github.com/darcreid163/wp-ai-admin/actions/runs/39407599458) — PHP 8.2, 8.3, 8.4 and real WP smoke, all **passed**.

**Do not misinterpret:** checking `owner_user_id` and `approved_at` from DB is not proof that those fields were originally issued by the trusted approval flow, and `inspect()` remains a data preflight. It is not a replacement for OAI_REST human approval, scoped MCP client session, database atomic leasing or the stored irreversible operation receipts. No Stage-2 methods are registered with the runtime.

### Remaining release blockers after this checkpoint

1. Persist **original before/after snapshots and actor/client provenance** through an authorized OAI_DB sandbox transaction (not caller-provided metadata).
2. Route Apply/Rollback through the actual OAI_REST lifecycle and operation lock, consuming only the stored snapshots; remove reliance on booleans for authorization.
3. Bind Verify, proof and receipt with rollback-safe, idempotent operation state transitions.
4. Add independent two-process MySQL concurrency test and permission/tenant negative coverage.
5. Reconcile additional testing from QA PR #5 without silently treating its gap-repro CI as a proof of security (the reproducer intentionally passes when gaps exist).
6. Independently clear public licence/secret release gates and private Cloud contract parity before packaging or deployment.


## 2026-10-20 Stage 3 — canonical snapshots and atomic MySQL claims

Integration branch: `agent/chatgpt/2-elementor-operation-binding`, Draft [PR #6](https://github.com/darcreid163/wp-ai-admin/pull/6).

- **GAP-5 staged remediation:** Elementor documents are compared using canonical associative-key ordering while retaining widget/section list order, scalar types and original persisted structure. The digest is computed from normalized JSON, not a lossy element rewrite.
- **GAP-9 staged mitigation:** `OAI_Elementor_Operation_Lease` provides an **unregistered** internal SQL compare-and-swap operation claim. A status transition succeeds only when operation id, type, status, site-bound post, operation identifier, both exact DB snapshots and metadata fingerprint still match. Expired apply and rollback without an applied timestamp cannot claim. Duplicate, race or changed database row fails without a document save.
- Claim has separate WordPress-human nonce/capability and scoped AI lifecycle context variants. These are **entry checks**, not full human consent implementation and are not wired to the existing REST lifecycle.
- **Two independent WP-CLI processes** race for one test operation; exactly one receives `CLAIMED`, the other `REFUSED`, and the Elementor draft is unchanged by either claim. The same test runs for **Apply and Rollback**, each with fresh test operations, then removes all test content.
- Integration test matrix: WordPress 7.0.6 + Elementor 4.3.3 + WooCommerce 11.1.2 + Yoast 28.6; and latest publicly downloadable plugin versions. All three PHP variants (8.2–8.4) also pass.
- Evidence: [GitHub Actions run #39411147203](https://github.com/darcreid163/wp-ai-admin/actions/runs/39411147203), 5/5 Passed. Both real-WP jobs independently logged `CLAIMED`, `REFUSED`, `VERIFIED` twice (apply and rollback).
- **Important:** no staged Stage-3 class is loaded into production plugin bootstrap and **no MCP/REST write capability was added**.

### Mandatory next P0 integration gates

1. Construct the operation in the **trusted** Native sandbox planner, with server-derived before/after snapshots, immutable owner/client/site provenance, and a persisted real human approval event. Today's standalone staging tests synthesize approved DB rows; this is not proof of provenance.
2. Bind the CAS claim to the actual OAI_REST lifecycle state machine. The eventual executor must consume the DB-stored before/after snapshot **only after** validated approval and atomic claim, not a caller-supplied boolean or document. Deliver status transitions, failures, Apply/Verify/Proof/Receipt and conflict-safe Rollback.
3. Perform independent security review of the atomic DB claim (including after-claim crashes, expiry races, forged metadata, SQL compare semantics, lost claims, idempotency and multi-site boundaries).
4. Integrate QA findings from [PR #5](https://github.com/darcreid163/wp-ai-admin/pull/5) and obtain owner approval before merge, release, or any installed-site update.

Release meaning: **GAP-5 canonicalization tested in staging; GAP-9 concurrency mechanism tested in staging only. GAP-2/3/7 and full executor authorization remain OPEN.**

## Repository synchronization checkpoint

On 2026-10-20 the public Native main branch advanced to commit `37d54f931878f93f26fd5ea48add74bafd6c8523` through the **independent merge** of collaboration-only PR #4. The Elementor integration Draft PR #6 remains unmerged and is based on its earlier native main ancestor; the runtime changes are isolated to its feature branch. Do not confuse a passing feature-branch CI run with a release merge. Before eventual integration, reconcile with the current main branch and rerun checks on the exact merged candidate SHA.

## 2026-10-20 Stage 4 — trusted pending operations and actual human approval audit

This stage is implemented in **Draft PR #6** only. It is **unregistered** in the Native bootstrap, REST and MCP: no production behavior or tool exposure changes.

- `OAI_Elementor_Trusted_Planner::prepare_human(post_id, proposed_tree)` derives the before-image from a live Elementor document, current WP administrator, site identity, operation ID, nonce-independent creation time and expiry. It saves a `pending` operation; it **never approves, applies or accepts client-supplied owner/site/before snapshots**.
- A versioned HMAC seal over the planned operation's immutable identity, before/after hashes, owner/site, timestamps and channel protects against unauthorized database edits detectable without the server's WordPress auth salt. Losing/rotating the WP secret invalidates pending records (fail closed); a compromised WP runtime or its secrets is outside this guarantee.
- `verify_record()` checks the seal plus stored before/after trees and origin. `verify_approval_record()` requires an **existing WP-admin approval audit event by the same planning administrator** matching the operation, not just a manually set `approved_at`. Staged CAS claims now require both checks.
- Disposable WP/MySQL tests create the real `pending` operation, submit it through existing nonce-guarded `OAI_REST::sandbox_action('approve')`, and then race Apply and Rollback claim transitions. A second concurrent process must fail; no Elementor document write is performed by either claim.
- Negative tests intentionally forge status, original/target snapshots, post owner and site, expiry and missing REST nonce; they must all be refused.
- Trusted approval evidence is an audit proof for this staged human path, **not** authorization to expose a public write API. The actual Apply/Verify/Proof/Receipt executor remains unimplemented for `elementor_document_save`, as do crash recovery, cloud owner/client identity and final rollback restoration from immutable DB-only before-images.
- Baseline passing CI for the human approval + claim + forged-status/snapshot checks: [run #39414555968](https://github.com/darcreid163/wp-ai-admin/actions/runs/39414555968), **5/5 Passed**. A follow-up test extends owner/site/expiry/nonce tamper checks on later commit and must have its own successful run before counting as verified.

**Do not merge or deploy:** Keep Draft #6 open pending independent DeepSeek adversarial review, real complete operation lifecycle, cross-tenant contract and release reconciliation.

## 2026-10-20 Stage 5/6 — real executor, receipt proof and crash classification

Draft PR #6 now contains the unregistered `OAI_Elementor_Document_Executor` and the read-only `OAI_Elementor_Document_Recovery` plus their isolated real WordPress/MySQL fixtures.

- The Stage-5 executor accepts **only** operation ID + post ID + WP human nonce; it delegates to the existing owner/site/real-approval-audit and atomic CAS claim, loads sealed DB-only before/after images, saves via native Elementor, reads both Elementor API and `_elementor_data` directly from MySQL, double-verifies, and writes the existing OAI_Proof and OAI_Receipts records. It supports DB-only rollback and rejects replays/stale editor changes.
- Fixed a shared Proof cache issue: a proof of Apply is no longer reused as proof of Rollback. Test now requires `proof.phase=rollback` and a different execution hash.
- Stage-6 recovery is **read-only**, rejects wrong actor/target/untrusted origin, and distinguishes state before, after and other for interrupted Apply/Rollback; it never retries, rolls back, or changes DB status.
- Executable CI **5/5 passed** at [run #39416941946](https://github.com/darcreid163/wp-ai-admin/actions/runs/39416941946) on commit `7cea5380`: PHP 8.2/8.3/8.4 and pinned/latest WordPress+Elementor+WooCommerce+Yoast. Earlier fully integrated executor test [run #39416251069](https://github.com/darcreid163/wp-ai-admin/actions/runs/39416251069) also passed 5/5; both WP job logs attest the end-to-end lifecycle.
- The subsequent test-only commit `96daa9fb` adds a **post-Apply human-edit rollback conflict** and refusal without nonce; its CI must be verified separately before acceptance. Do not reuse earlier CI proof for it.
- Actual integration into OAI_REST Sandbox/Approval route, public MCP tool dispatch, AI client identity, usage ledger, recovery authorization and private Cloud contract remains NOT IMPLEMENTED. No runtime bootstrap registration has been made.

### Reconciliation with independently advanced public main

While this branch was developed, independent PR #5 (DeepSeek adversarial gap reproducer / CI) and PR #9 (publication gates / GPL attribution) were merged into public Native `main`, now `c2673156c71d40526a552399a7c48a474709c210`. Their changes include `.github/workflows/native-validate.yml` and security boundary checks. This feature branch diverges from main. **Do not merge PR #6 or overwrite workflow files until both agents reconcile the branch against current main and preserve all CI/security tests.** The QA reproducer intentionally demonstrated previous vulnerabilities; whether its current assertions should remain proof-of-gap or become regression gates must be independently reviewed.

### Next P0

1. DeepSeek independent audit of `class-oai-elementor-document-executor.php`, proof caching phase, persistent MySQL verification, stale rollback and recovery classification; publish exact reproducer/PR.
2. Run on current base **including** merged publication and adversarial QA gates; preserve, never weaken, all existing checks.
3. Add safe owner-authorized recovery/resumption design for interrupted CAS without blind replay and test failed writes/after-save crash boundaries.
4. Wire full typed execution through existing Native sandbox lifecycle **only after** the above QA gates and explicit maintainer approval; require private Cloud tenant/client identity contract before MCP tool exposure.

## 2026-10-20 — PR #6 branch reconciliation and latest CI

After DeepSeek's independent PR #5 (adversarial fixture) and PR #9 (publication/licensing guardrails) were merged to public `main`, PR #6 became unmergeable (`dirty`) and no longer received ordinary PR CI. We resolved it by **merging main into only the PR #6 feature branch** using a two-parent merge commit `2c41ca20032e1abd6fe4591d81e0e57f67f1830e`. Both sets of test gates are retained: main's adversarial gap reproducer and publication boundary, plus all new Elementor binding/execution/recovery tests.

- CI [run #39418132363](https://github.com/darcreid163/wp-ai-admin/actions/runs/39418132363): **5/5 Passed**, PHP 8.2/8.3/8.4 and pinned/latest WordPress plugin stacks.
- Latest WordPress log explicitly confirms successful human-approved DB-only Elementor Apply/Verify/Proof/Receipt/Rollback, no replay/stale overwrite, one-winner Apply/Rollback CAS, and **read-only crash classification**. Rollback receives a fresh, rollback-phase-specific proof, not cached Apply proof.
- The merge resolves branch conflicts **without merging Elementor PR #6 to main**. It does not authorize release.
- Independent review remains mandatory for failure after persistence, proof/receipt crash window, authorizer/nonce ownership, narrow post type policy, double-billing, and the residual gap between pre-save read and actual Elementor document persistence. The adversarial QA reproducer and publication gate are preserved but their results need independent semantic assessment: a passing gap report does not by itself mean no vulnerabilities.

Current integration work is internal-only. No new runtime REST/MCP tool or production deployment.

## 2026-10-20 Stage 7 — independent executor audit remediation (PR #12)

DeepSeek delivered an independent audit as [PR #12](https://github.com/darcreid163/wp-ai-admin/pull/12), reviewing staged PR #6 on commit `2dce6a6`. It flagged eight execution-specific findings (F1–F8). These are tracked individually; a passing gap reproducer is **not** a release clearance.

| Finding | Follow-up as of this commit |
|---|---|
| **F1 High** — after-write verification error stored as apply_failed with no recorded applied image | **Staged remediation, CI passed:** once Elementor save was attempted, preserve `applying`/`rolling_back` and manual-review incident; when save returns success persist `applied_data`/`applied_at` *before* verification; no blind retries. Added explicit **human-only** `reconcile_applied_human()`: double-verify approved after-image and DB-backed record, then finalize a confirmed Apply and issue a fresh proof/receipt **without saving Elementor again**. Foreign editor edits and missing nonce are refused. |
| **F2 High** — unsealed state-based proof and incomplete terminal receipt after crash | **Partially mitigated, OPEN:** read-only crash inspector identifies an `applied`/`rolled_back` operation with missing/wrong-phase Proof or Receipt and requires manual review. Cryptographically authenticated pre-save execution intent / post-save event evidence and durable receipt recovery remain outstanding. Existing proof attests observed state, not independently witnessed execution. |
| **F3 Medium–High** — competing editor save between snapshot comparison and Elementor write | **OPEN:** CAS serializes our operation records, not third-party WordPress writes. Need independent UI/editor lock integration or stronger persistence conflict semantics plus adversarial interleaving tests. No claim of full lost-update prevention. |
| **F4 Medium** — unsupported revision/attachment target | **Staged remediation, CI passed:** allow only `post_type=page` in planner and operation binding; reject revisions, attachments and other post types by default pending explicitly approved Elementor type expansion. |
| **F5 Medium** — lost failed-status transition leaves claim in-flight | **Partially mitigated:** after possible write, keep ambiguous in-flight state and offer owner-only evidence-based reconciliation for Apply when persisted applied marker exists. Crashes before marker and full lease recovery remain OPEN. |
| **F6 Low–Medium** — success may be reported as error if receipt/proof fails after committed write | **OPEN:** terminal evidence gaps are diagnosed; billing contract / distinct `applied_but_reconciliation_required` response needs an explicit decision before routing. |
| **F7 Low** — inconsistent PHP vs MySQL expiry clocks | **OPEN:** use one canonical clock at final CAS; audit time zone conversions and boundary tests. |
| **F8 Info** — generic wp_rest nonce not per-operation | **Documented limitation:** owner, capability, site, persisted approval event and CAS bind the operation; do not treat nonce as per-operation token. |

**Full failure-window CI:** [run #39421099494](https://github.com/darcreid163/wp-ai-admin/actions/runs/39421099494) — **5/5 Passed** (PHP 8.2/8.3/8.4 plus pinned/latest real WordPress/Elementor/WooCommerce/Yoast). The WordPress test injects a post-save/pre-verification metadata conflict via WordPress's normal cache-clear action, asserts status stays `applying` with applied marker and a **non-success** incident, verifies no automatic overwrite, demonstrates refusal to reconcile a different document, then explicitly restores the *disposable fixture* and completes owner-only reconciliation with a normal DB-based Rollback. It also simulates lost Apply and Rollback receipts and verifies incident detection.

**Safety boundary:** all new executor, planner, binding, CAS, reconciliation and recovery classes remain **unregistered at runtime**. The changes on PR #6 have not been merged into `main`; no production, private Cloud or live billing change. DeepSeek must independently re-audit the new head and the unresolved F2/F3/F5/F6/F7 gaps before any approval to expose a REST/MCP route.

## 2026-10-20 Stage 8 — F3 external-editor race reproduction and hard release gate

**Public Native main now contains the reviewed 2.1.16 release-line hardening, head `7e48f400`.** Our Elementor branch was reconciled with that main via merge commit `ea5075b6`, preserving publication security, release-artifact CI, expanded read-only CLI, DeepSeek QA tests and staged Elementor compatibility tests. This is a merge **into the draft feature branch only**.

**Owner separation:** ChatGPT implements P0 [Issue #13](https://github.com/darcreid163/wp-ai-admin/issues/13), touching only its staged integration PHP and new integration fixture. DeepSeek owns independent QA/design of P0 [Issue #14](https://github.com/darcreid163/wp-ai-admin/issues/14) under new docs/test-only files; it must not edit includes/, our PR #6, the native CI workflow or this shared state file. Full directions are posted in both issues and #3.

### What was added / verified

- New **unregistered** `OAI_Elementor_External_Edit_Guard::inspect()` checks current administrator/post permissions, page-only type, advisory WordPress `_edit_lock` owned by a different user, and an independent direct MySQL read of `_elementor_data` against the approved snapshot (plus Elementor API). Missing/duplicate metadata or stale content fails closed. The staged executor calls this guard just before its document save.
- **P0 remains OPEN:** two independent WP-CLI workers in `tests/wordpress-elementor-external-editor-race.php` deterministically reproduce a lost human edit when the human writer saves after the AI's preflight and before its actual save. The stdout explicitly reports `F3_UNSAFE_RACE_REPRODUCED` and `F3_CONFIRMED_UNSAFE_GAP_REMAINS`. This reproducer is intentionally test-successful when the unsafe gap is seen; CI green DOES NOT mean F3 is fixed.
- A fail-closed **execution kill switch** blocks even an accidentally included staged executor and its human reconciliation on real sites. Writes are allowed only with explicit `OAI_ELEMENTOR_DOCUMENT_STAGED_TEST=true` PHP constant, `OAI_DISPOSABLE_WP_TEST=1` environment and localhost/.test WordPress origin. There are **no** registered Native REST/MCP write routes. The default-deny behavior is exercised by the WordPress fixture.
- Release-safety design, residual threat model and exit criteria documented in `docs/ELEMENTOR-EXTERNAL-EDIT-RACE.md`. The advisory WP edit lock and last-moment DB read are useful guards, **not** a cross-editor exclusion guarantee.

### Exact CI evidence

- Reconciled Native 2.1.16 / Elementor feature: [run #39423883147](https://github.com/darcreid163/wp-ai-admin/actions/runs/39423883147), **6/6 success** (PHP 8.2/8.3/8.4, pinned/latest WP, artifact build; release skipped).
- True two-process F3 reproducer: [run #39424413203](https://github.com/darcreid163/wp-ai-admin/actions/runs/39424413203), **6/6 success** but both WP logs show a demonstrated unsafe race.
- Disposable-only executor default-deny: [run #39425035917](https://github.com/darcreid163/wp-ai-admin/actions/runs/39425035917), **6/6 success**. Earlier incremental commits had red tests because test fixtures did not yet enable the new explicit test-only switch; those were corrected and rerun.

**Release condition:** never merge/enable a full Elementor document write until tests demonstrate that the other editor's changes are not overwritten even in after-check/before-save interleavings (not merely detected afterward), plus Issue #14 receipt/proof/usage safeguards and independent QA. The staged ZIP artifact produced by a Draft PR is not an approved production release.

## 2026-10-21 Stage 9 — real external-editor transaction spike and agent handoff

**Native base:** public main at `7e48f400` (2.1.16 hardening). Stage-only Elementor Draft PR #6 is reconciled and separate. No production or private Cloud changes.

**Parallel ownership:** ChatGPT writes the integration/source + test spike for P0 Issue #13, DeepSeek reviews #13 and owns independent QA/design of P0 Issue #14 in **new** `docs/QA-EXECUTION-EVIDENCE-*` and `tests/qa-execution-evidence-*` files. Neither agent may rewrite another's PR branch, overwrite common workflows or merge without review. Source-of-truth comments are on Issues #13, #14 and #3. On 2026-10-21, DeepSeek submitted independent docs-only [PR #16](https://github.com/darcreid163/wp-ai-admin/pull/16), `e4b91927`, with the execution evidence design for #14 and an external-edit race review for #13. No `includes/`/CI/shared test conflicts. ChatGPT reviewed PR #16, and left six release-risk clarifications on its PR; no independent implementation or approval/merge has occurred.

**F3 experiment:** new disposable-only two-process MySQL transaction fixture `tests/wordpress-elementor-external-transaction.php`, executed in `tests/wordpress-native-smoke.sh` on both supported WordPress test profiles:
1. Human writer saves after AI preflight but before AI's `SELECT ... FOR UPDATE`: DB transaction reads the new document and AI **refuses** rather than overwriting.
2. AI holds `_elementor_data` row lock, human/editor process starts a save and waits until AI commits: writes **serialize**, then last human write completes. But the human had loaded a *stale pre-AI document*, so **the approved AI content can be overwritten**. This is reverse lost update; the test now labels it explicitly.
3. Row locks are necessary but not sufficient. A genuine shared version/ETag check must be enforced for *both* AI and human/Elementor UI writers. Raw SQL replacements are not an approved workaround; Elementor save hooks/CSS/cache and normal revisions must remain correct.

**Evidence:** Initial transaction experiment CI [#39546442955](https://github.com/darcreid163/wp-ai-admin/actions/runs/39546442955) **6/6 Passed** after correcting an isolated WP-CLI signal-path scope bug. The later test commit `7462e1d5` adds explicit reverse-loss assertions and passed [CI #39546734257](https://github.com/darcreid163/wp-ai-admin/actions/runs/39546734257), 6/6; both WP profiles confirmed `F3_TX_REVERSE_LOST_UPDATE_REPRODUCED`. This negative finding keeps P0 #13 OPEN.

**Safety gate:** staged full-document Apply/Rollback remains fail-closed outside disposable CI, even if an operator loads the class. No new public REST/MCP writer has been registered. Release P0 #13 and #14 stay open; no branch merge or live installation.

## 2026-10-21 Stage 10 — independent QA PR #16 and reverse-lost-update evidence

- **Independent agent delivery:** DeepSeek opened [PR #16](https://github.com/darcreid163/wp-ai-admin/pull/16) in `agent/deepseek/qa-13-14-design`; only `docs/QA-SIGNED-EXECUTION-EVIDENCE.md` and `docs/QA-EXTERNAL-EDIT-RACE-REVIEW.md` changed. It separates proof-of-intent, proof-of-persisted-state and an execution witness; proposes a hash-chained, keyed event ledger with explicit crash matrix and quota test scenarios. It does not implement a live tool.
- **ChatGPT review:** PR #16 has a concrete independent review comment. Required clarifications: no-loss guarantee for stale Elementor UI writers **after** an AI row-locked commit; a separately anchored chain head and signed event header; intent token is not write proof; charging unresolved mutations requires approved successful-action billing semantics; DB UTC epoch timestamp model; secure idempotent append/owner identity.
- **Reverse lost-update verified:** two real WordPress test profiles printed `F3_TX_REVERSE_LOST_UPDATE_REPRODUCED` in [run #39546734257](https://github.com/darcreid163/wp-ai-admin/actions/runs/39546734257), **6/6 Passed** (this is a test identifying a vulnerability, not a released safety fix). `SELECT ... FOR UPDATE` serialized database writes, but a human later saved a stale pre-AI document and erased the earlier AI edit. Thus #13 and #14 remain P0 release blockers.
- **Scope discipline:** ChatGPT owns experimental test/runtime source in PR #6, DeepSeek owns docs and independent QA branch PR #16, and neither agent is authorized to merge, modify production or expose private Cloud code. Executable full-document writer stays disabled except in disposable CI. 

## 2026-10-21 Stage 11 — Elementor strict row resolver and UI/AI version contract

- **DeepSeek QA G1 staged remediation:** `OAI_Elementor_Schema_Save::canonical_meta_row(post_id)` is now the **one shared fail-closed direct-MySQL resolver** of a single `_elementor_data` row. `OAI_Elementor_External_Edit_Guard` and `OAI_Verify::operation()` both use it; no more "guard denies duplicates, verify selects latest" inconsistency. The `meta_row_id` is emitted by verification for future evidence receipts.
- Disposable real WordPress regression in `tests/wordpress-elementor-document-executor.php` deliberately adds a duplicate `_elementor_data` metadata row, proves **both** guard and Verify reject it, deletes it and proceeds with the normal human-approved Apply/Verify/Receipt/Rollback. No production data touched.
- Executable CI [#39549836010](https://github.com/darcreid163/wp-ai-admin/actions/runs/39549836010), commit `03704f8e41a4aacf8c1c75cb000e048c727b8dc6`: **6/6 Passed** (PHP 8.2–8.4, pinned/latest WordPress and Elementor, ZIP candidate build; release skipped). Do not confuse this with closure of F3.
- New design-only `docs/ELEMENTOR-EDITOR-VERSION-CONTRACT.md` uses official Elementor source (`core/documents-manager.php` and `core/base/document.php`) to specify an **enforced two-sided expected document version** for both human browser saves and AI writes. Native Ajax save does not by itself demonstrate a shared server-enforced ETag; cross-editor optimistic concurrency remains unproven. Independent DeepSeek review requested through Issue #13.
- **P0 unchanged:** reverse lost update under InnoDB row lock remains reproduced in [CI #39546734257](https://github.com/darcreid163/wp-ai-admin/actions/runs/39546734257); therefore full-document executor is **disabled** outside disposable CI, PR #6 is still Draft and unmerged. Issue #14 signed evidence+quota contract remains independent work owned by DeepSeek; don't edit their QA branch.
