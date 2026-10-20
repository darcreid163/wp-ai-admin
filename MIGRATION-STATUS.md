# Public Native migration readiness — 2026-10-20

Status: PRIVATE / PUBLICATION BLOCKED until validation.

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

Do not expose this repository to the public until these gates are satisfied.

## 2026-10-20 audit continuation
- Rechecked both new Git trees against the source monorepo: all selected Native source/test files and all current and legacy Cloud Core source/test files were migrated. The only deliberately excluded Native file is `tests/fixtures/woodmart-mcp-1.0.1.zip`, which the legacy full WordPress regression still expects.
- Textual repository scan completed; project-specific references were cleaned. No server code or release ZIP appears in the new Native tree.
- Last checked Actions workflow failed before any job step was recorded (jobs had `steps: null`). PHP tests have therefore **not** been executed by hosted Actions. Investigate account-level GitHub Actions spending limit or billing entitlement, runner policy, or runner availability. Do not interpret the failed workflow as a PHP regression.
- Public Native still requires an independent full secrets / legal attribution audit and reproducible CI success. No production installation is authorized.
