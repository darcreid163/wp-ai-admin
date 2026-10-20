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
| Independent collaboration protocol | Draft PR #4, CI passed | https://github.com/darcreid163/wp-ai-admin/pull/4 |
| Authoritative document operation binding to Native DB and approvals | **In progress** | https://github.com/darcreid163/wp-ai-admin/issues/2 |
| Independent adversarial QA | **First report delivered, 9 gaps reproduced** | https://github.com/darcreid163/wp-ai-admin/pull/5 |
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
| GAP-1 payload `after_sha256` ignored | High | Integrity validation added in Draft PR #6, pending CI |
| GAP-2 rollback snapshot caller-controlled | High | OPEN: bind exclusively to persisted immutable OAI_DB before-image |
| GAP-3 boolean approval bypass | High | OPEN: replace with authoritative operation id and lifecycle context before any write route |
| GAP-4 no post-save authoritative compare | Medium | Readback verification added in Draft PR #6, pending CI |
| GAP-5 key-order false conflicts | Medium | OPEN: canonical equivalence design and tests |
| GAP-6 unscoped schema/snapshot | Medium | OPEN: capabilities, owner and post-status scope |
| GAP-7 no persisted Receipt/Proof in helper | Medium | OPEN: wire existing OAI_Receipts/OAI_Proof only through lifecycle |
| GAP-8 malformed SHA causes warning | Low | Typed validation added in Draft PR #6, pending CI |
| GAP-9 check-then-act race | Medium | OPEN: operation lease, atomic status transition and two-process DB test |

The new Stage-2 binding utility is **read-only** and is NOT registered at runtime. It narrows persisted type/status/site/post/image checks; it does not cure independent authorization or atomic execution. A passing CI does not close the open high-severity findings.

## Next release steps
1. Finish persisted Elementor document operation data contract, owner/site/client authorization and lifecycle state machine (Issue #2). Preflight-only utilities are **not** an authenticated executor.
2. Obtain independent DeepSeek security review and additional regressions for Issue #3; integrate their findings in separate fix commits.
3. Run Native ↔ Cloud Core API contract and cross-account isolation tests without publishing private server code.
4. Reconcile deployed package versions and restore legacy regression fixture parity.
5. Close release readiness, confirm safe migration and build signed/versioned archives. Production remains unchanged until owner approval.

## Explicitly out of scope now
Unbounded batch expansion, replacing all editors, auto-subscription charges, Cloud scaling architecture and quota reset enhancements. These will be handled after commercial release stability.
