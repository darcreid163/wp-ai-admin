# Public Native migration readiness — 2026-10-20

Status: PRIVATE / PUBLICATION BLOCKED until validation. Gates 1–4 verified on 2026-10-20 (see below); gates 5–7 remain open.

## Completed
- Native source separated with clean Git history from the private original monorepo.
- The private Gateway and historical Cloud code are excluded from this repository.
- Original Elementor schema/save fixture and adapter have been moved without any runtime MCP exposure.
- GPL-2.0-or-later file and plugin header have been added.
- A separate Native CI workflow, a lightweight WordPress/Elementor smoke job and basic publication boundary gate have been added.
- A text scan of migrated files found and removed a remaining competitor attribution. No proprietary upstream source was copied by the schema/save adapter.

## Unresolved publication gates
1. GitHub Actions jobs are failing before ordinary steps are reported. Identify GitHub account/org billing or Actions runner policy; a repository visibility change alone is not proof that a code or runner failure is fixed.
2. Run PHP lint and isolated fixtures successfully, including the new Elementor schema/save test.
3. Run the new disposable WordPress/Elementor smoke and later actual full integration tests.
4. Full native regression in `tests/wordpress-upgrade.sh` references historical release ZIPs and a Woodmart MCP fixture ZIP that were deliberately NOT included in public Git; replace these dependencies with independently downloadable *verified and license-safe* test fixtures before enabling that legacy scenario.
5. Confirm production Native version and source parity. This migration uses the original main 2.1.14 baseline plus the separate schema/save stage 1 files; it is NOT a proven latest 2.1.15 build.
6. Perform independent secrets/history/code attribution audit, review GPL notices and protect public CI from secrets and workflows using privileged PR tokens.
7. Require a human-reviewed release and staging connection regression before any production deployment.

## Publication gate status — 2026-10-20 (independent QA pass)

| # | Gate | State | Evidence |
|---|---|---|---|
| 1 | Hosted Actions actually run | **closed** | PR #1 `39400426178`, PR #4 `39401403705`, PR #5 `39403278174`: PHP 8.2/8.3/8.4 and WordPress smoke all green |
| 2 | PHP lint and isolated fixtures | **closed** | Hosted CI green on three PRs; local rerun: 85 PHP files, 0 lint errors, 7 isolated suites pass |
| 3 | Disposable WordPress/Elementor smoke | **closed** | `wordpress-smoke` job (WP 7.0.6 + latest, Woo 11.1.2, Elementor 4.3.3, Yoast 28.6) green in the same runs |
| 4 | Legacy upgrade fixtures | **closed as explicit skip** | `tests/wordpress-upgrade.sh` now skips loudly when the private 2.1.5/2.1.11/Woodmart fixtures are absent; `OAI_REQUIRE_PRIVATE_FIXTURES=1` forces failure. The legacy scenario is therefore **not covered publicly** — treat it as unavailable, not as passing |
| 5 | Production version/source parity | open | Public main is the 2.1.14 baseline; the qualified 2.1.15 / Cloud 0.3.8 candidate lives in private PRs and is not reconciled here |
| 6 | Secrets, attribution, GPL notices | **closed technically, legal review open** | `tests/repository-boundary.php` now checks boundary, publication contract and disclosure hygiene (negative-tested); `THIRD-PARTY.md` records the GPL-2.0-or-later attribution and porting obligations |
| 7 | Human-reviewed release and staging regression | open | Requires maintainer approval and a live two-site connection check |

Reproduce the automated gate locally with `php tests/repository-boundary.php`. It fails on
installable artifacts, server-only paths, key material, absolute local paths, missing
`LICENSE`/`THIRD-PARTY.md`, a non-GPL plugin header, or missing WPVibe attribution.

Do not expose this repository to the public until gates 5–7 are satisfied.

## 2026-10-20 audit continuation
- Rechecked both new Git trees against the source monorepo: all selected Native source/test files and all current and legacy Cloud Core source/test files were migrated. The only deliberately excluded Native file is `tests/fixtures/woodmart-mcp-1.0.1.zip`, which the legacy full WordPress regression still expects.
- Textual repository scan completed; project-specific references were cleaned. No server code or release ZIP appears in the new Native tree.
- Last checked Actions workflow failed before any job step was recorded (jobs had `steps: null`). PHP tests have therefore **not** been executed by hosted Actions. Investigate account-level GitHub Actions spending limit or billing entitlement, runner policy, or runner availability. Do not interpret the failed workflow as a PHP regression.
- Public Native still requires an independent full secrets / legal attribution audit and reproducible CI success. No production installation is authorized.
