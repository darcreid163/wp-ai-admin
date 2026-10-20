# Elementor Document — trusted operation lifecycle contract

**Status:** design + staged CI primitives only. Not registered in Native production bootstrap, REST, or MCP. Draft PR #6; owner-approved merge is required separately. The existing Native operation engine is authoritative. Do **not** create a second state machine or bill a successful Sandbox and Apply twice by accident.

## End-to-end sequence (desired, not yet implemented)

1. **Propose** — user or authenticated MCP client supplies a proposed Elementor document, never a `before` snapshot, owner, site id, operation id or approval flag. Authenticate current WP owner/actor and scoped client separately.
2. **Plan pending** — `OAI_Elementor_Trusted_Planner::prepare_human()` captures the live server document, validates the proposal, derives owner/site/expiry, creates a unique `pending` OAI_DB operation with HMAC-sealed before/after identity. No Elementor write occurs.
3. **Human approval** — the existing WordPress administrator REST approval flow with valid nonce changes `pending → approved` and writes its owner audit event. A forged `approved_at` field alone is rejected by `verify_approval_record()`. AI lifecycle mode can be handled **only after** its separately approved, scoped control-plane contract is reviewed; this initial planner is WP-admin-human only.
4. **Claim** — actual REST lifecycle must verify exact current actor, site, permission, persisted approval and signed snapshots; `OAI_Elementor_Operation_Lease` atomically performs `approved → applying` with DB compare-and-swap (CAS), matching row fingerprints. A duplicate call must fail/replay safely, never write twice.
5. **Apply** — the new executor must accept the operation ID and a claim token, **not arbitrary caller-supplied before/after or `approved=true`**. Reload sealed DB state, recheck live post snapshot immediately before saving, call the existing native Elementor document save API, verify the full tree by readback, and persist `applied_data`, `applied_at`, `applied`. Preserve the actual DB proof of the modified document.
6. **Verify/Proof/Receipt** — the existing Native `OAI_Verify`, `OAI_Proof`, `OAI_Receipts`, `OAI_Audit` contracts should issue independent, timestamped verification and recoverable receipts. Today their generic operation dispatch does not yet support the new `elementor_document_save` type. Do not claim a verified result until this extension is tested.
7. **Rollback** — require persisted `applied_at` and prior verification. Atomically claim `applied → rolling_back` with site/actor/approval checks, compare live tree to the DB **applied snapshot** (not caller data), restore only the original DB before-image, verify by independent read, store proof and receipt, then persist `rolled_back`.
8. **Conflict/recovery** — any different live state must be a conflict, not an overwrite. Failed or interrupted `applying`/`rolling_back` requires deterministic readback:
   - Live equals **before**: no Apply landed; state can be safely failed/replanned.
   - Live equals **after**: Apply may have landed before process crash; reconcile with trusted proof and require independent verification before marking applied. Do not blindly write again.
   - Live is **neither**: protect newer human edits; mark conflict for administrator review and do not auto-rollback.
   - Recovery requires a bounded lease/owner/deadline and a controlled event; simply resetting a timed-out state to `approved` is not safe.

## Security tests required (release blockers)

| Gate | Expected |
|---|---|
| Planner forgery | Replaced owner/site/snapshot/expiry/operation id invalidates server seal |
| Approval forgery | No matching owner-admin audit event means no CAS claim |
| Cross-site and wrong WP post | Rejected, no writes |
| Invalid or revoked MCP client | No lifecycle request authorization |
| Concurrent Apply or Rollback | One CAS claim wins; losers never save a document |
| CAS winner crashes before save | No automatic reapply; deterministic recovery |
| CAS winner crashes after save before receipt | Detect current after-state, recover proof without double mutation |
| Elementor normalization / unrelated editor change | Full canonical compare; fail-closed if semantic change |
| Wrong idempotency/request key | Cannot create duplicate successful debit |
| Rollback after concurrent human change | Refuses destructive overwrite |
| Receipt and proof tampering | Verify via persisted authoritative records |
| Both pinned and latest WP/Elementor | Full workflow, not only isolated fixture |

## Current CI evidence

- [Run #39414990708](https://github.com/darcreid163/wp-ai-admin/actions/runs/39414990708): 5/5 passed, including real nonce-approved server-planned operations, forged metadata/expiry/approval negative cases and two-process Apply/Rollback CAS races on both WordPress profiles.
- There is currently **no test** of the full execution sequence because the new trusted executor is **not implemented**.
- Staging feature branch must remain private to PR review; no production deployment before both original and independent agent agree the failure matrix is closed.

## Agent work ownership

- **ChatGPT:** implement scoped staged planner, authorized executor integration, targeted source fixes in `includes/`, documented changes, CI reproduction.
- **DeepSeek:** independent adversarial review in Issue #3 and QA PR #5; own tests/docs branch. Try owner/site/proof forgery, stale authorizations, interrupted CAS, SQL race and rollback conflicts. Supply issue, exact commit SHA, reproducer and failing assertion; do not edit ChatGPT's integration branch.
- **Maintainer:** approve release scope, merging, testing on staging sites and eventual production update.

Do not expose private Cloud Core source, customer data or production credentials in public reviews or test artifacts.
