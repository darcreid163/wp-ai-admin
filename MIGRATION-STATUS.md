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
