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
