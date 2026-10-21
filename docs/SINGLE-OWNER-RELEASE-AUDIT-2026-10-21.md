# WP AI Admin — единый релизный аудит и план (21 октября 2026)

**Владелец исполнения:** ChatGPT + владелец проекта (один ИИ-исполнитель; независимое ревью обеспечивается автоматическими тестами и решениями владельца). DeepSeek больше не участвует. Его завершённые исходники/тесты/аудит остаются историческими материалами; никаких ожиданий, обязательств и заданий внешнему агенту нет. Никакого удаления уже полезных тестов для «чистки». **Этот PR — только аудит/план, без применения к production.**

## Репозитории и установленные версии — подтверждённые данные

| Компонент | Подтверждено | Не считать доказанным |
|---|---|---|
| Public Native `darcreid163/wp-ai-admin` | `main` = `38ea4c0d6658059bd983d711532eb04518660842`, проектная линия **2.1.16**, CI выполнен | Это не исходный код установленного **2.1.17** |
| Draft PR #6 | staged Elementor full-document, серверные снимки, Approval/CAS/Verify/Receipt/Rollback, fail-closed; последняя проверка #39550165370 6/6 | Не установлен, **не сливать**: доказанная потеря изменений при двух редакторах, P0 #13 |
| Private `darcreid163/wp-ai-admin-cloud-core` | `main` = `ffd63fe2da8d80c3ebcc1a1c95ba42929902a555`, Cloud CI #39548366740 зелёный; private OAuth, tenant, Store | Репозиторий не подтверждает побайтовое соответствие установленного Cloud Core |
| Действующий gateway | `wpaiadmin.ru` и `okean.market` зарегистрированы и active; общий доступ через MCP | Не доказан релизный SLA и стресс-тест на 1000 пользователей |
| `wpaiadmin.ru` | Native **2.1.17**, build `2.1.17-usage-batch-v1-20261019` по живому system.manifest | Бинарное содержимое и Cloud Core точная версия ещё не проверены |
| `okean.market` | Native **2.1.15**, build `2.1.15-qa38-20261013` по живому system.manifest | Совместимость произвольного обновления до 2.1.17 не подтверждена |

Live manifests read through explicit site-bound, read-only `system.manifest`; each successful call charged one action. Both site manifests advertise 116 native tools; actual interface/feature availability must always be checked against registered tool catalog and authorization in that installation.

**Critical divergence:** never label public 2.1.16 `main` as identical to installed 2.1.17, and never install a PR #6 ZIP on production.

## Единый статус возможностей (код + каталог инструментов)

| Поверхность | Подтверждённая база | Не завершено для полноценного коммерческого покрытия |
|---|---|---|
| WordPress Core | site/plugins/themes, posts/meta, options, users/comments/widgets, diagnostics, files, content patch, preview, auth/scopes, REST GET, 41 allowlisted read-only WP-CLI commands | Guided workflows для настроек и страниц; универсальная безопасная REST-модель (scope/approval) и покрытие нестандартных типов; автоматические обновления plugin/theme/core со staging/rollback |
| WooCommerce | product full read/search, цены, остатки (целое округление), описание/категории/SEO/attributes/variations read, orders read, sandbox product patch + draft create; approval/verify/rollback | Сквозные реалистичные сценарии переменных товаров, галереи, атрибутов/вариаций write, tax/shipping/coupons/orders (без обхода платежных рисков), массовая операция через центральный ledger |
| Elementor | структуры, widget/page settings, draft create, locate/patch HTML/settings, native page saves для уже поддерживаемых операций, test preview; безопасный full-document executor **только в Draft #6 и только в disposable CI** | P0 #13: two-sided version validation for browser and AI edits; после него P0 #14 receipt/usage proof; затем end-to-end public tool approval и visual regression |
| Gutenberg | parse/serialize, post resolver, outline/locate blocks, freeform patch, sandbox → approval и rollback | Pattern/reusable/synced blocks, Site Editor (FSE), global styles/templates, dynamic/third-party blocks и соответствующие Negative QA |
| Media и menus | Native каталог заявляет `media.*_sandbox`, `menu.*_sandbox`, фото, alt/title/featured, меню create/add/move/remove/assign; эти пути требуют проверки реальной способности перед релизом | Комплексная end-to-end матрица для дублей, запрета опасных URL, ролей, удаления, rollback и отображения в теме |
| Woodmart | чтение и отдельные изменения настроек, частичная поддержка | Header Builder / theme layout не считать полноценно доступными |
| Cloud/SaaS | OAuth, два сайта/tenant routing, центральные аккаунты, базовый usage ledger и квоты | Единый учёт успешных объектов batch, тарифная миграция, подписки/доступ, надежный retry, logout/revocation и принятие условий, нагрузка и изоляция при деградации |

**Сравнение с фактически доступным инструментарием WPVibe:** WPVibe предоставляет site connect/info, Abilities API discovery/run, generic REST API, WordPress CLI, upload media, code snippets, draft theme + preview + publish/backup, HTML/visual audit, plugins/themes/core fleet updates, approvals. В WP AI Admin сильнее специализированный sandbox/verify/proof/rollback/tenant flow; **не доказана** аналогичная зрелость draft-theme preview/publish и fleet management. Нельзя объявлять общие «116 инструментов» эквивалентом 36 чужим по реальной глубине. Не переносить чужой защищённый код и не упоминать бренд в публичном маркетинге без необходимости; это **внутренний сравнительный аудит**, не лицензионное разрешение.

## Тарифы — зафиксировано и обнаруженное расхождение

**Согласованный целевой контракт:** Free — 100 успешных действий/календарный месяц; Pro — 500/сутки; Business — 2 000/сутки; BusinessPro — 5 000/сутки. Все функции доступны в каждом тарифе; пользователь вручную подтверждает оплату в кабинете, автосписаний нет. Три дополнительных сброса в месяц и масштабирование отложены. Successful read = 1, successful Sandbox creation = 1, successful single write = 1, batch = 1 за **каждый успешно изменённый объект**, failure/replay = 0. Ранее спорные provisional rates не активировать.

**Что работает сейчас:** `cloud_usage` показывает Free 100, старую `legacy_monthly_v1`, `batch_available=false`, использовано **57/100** на момент первой проверки 21.10.2026 (после неё два site-bound manifest read также списали по единице, повторно баланс не запрашивался). Частный `config/plans.json` всё ещё содержит старую monthly pilot линейку `free/pro/power/agency/scale`; `config/approved-tariffs-next.json` имеет **status=approved-design-not-active**. Миграция активных планов, дневных окон, подписок и ledger должна быть отдельным private Cloud PR + тесты совместимости, а не заменой одного JSON.

## Порядок выпуска — блокирующие ворота

| Priority | Gate | Проверяемое условие закрытия |
|---|---|---|
| P0-A | **Version parity** | Снять manifest+файловый/сборочный fingerprint установленного Native 2.1.17, исходников 2.1.16 и 2.1.15; сравнить с Cloud Core 0.3.8/установленным gateway без экспорта секретов; закрепить update-path/rollback |
| P0-B | **Security** | Tenant/account/site isolation, OAuth scopes+revocation, approval owner, no cross-post edit, forged receipts, race/idempotency, public Git secrets/licence; независимый CI matrix |
| P0-C | **Usage/billing** | Атомарный event ledger, exactly-once outcome, distinction read/Sandbox/write/batch-child, daily UTC periods, no double debit on partial/crash, manual account payment only |
| P0-D | **Existing Native 2.1.17 regression** | End-to-end on disposable WP: CRUD-safe WordPress, WC simple/variable, Gutenberg, Elementor patches (not PR #6 full-doc), media/menu, batch, security and update from 2.1.15 |
| P0-E | **Critical Elementor full-document** | P0 #13/#14 MUST close **before enabling feature**; until then isolate from release behind hard default-deny. Safe release may ship **without** this unfinished experimental capability |
| P1 | **Product parity** | theme draft/preview/publish/backup; Abilities and REST tool flows; broad WC catalog editing, Gutenberg/FSE, staging and plugin/core update workflow |
| P1 | **Commercial UX** | cabinet coherent plan/subscription/usage, account approval and receipts, transparent errors and upgrade instructions, no secret exposure |
| P1 | **Acceptance & packaging** | CI/build reproducible, tag/hash signed, install/upgrade/rollback smoke on staging for both sites, owner approval, then gradual deployment |

**Release tracks:** (1) **Stable commercial beta** based on verified existing safe Native/Cloud, without exposing full-document experimental Elementor; (2) Advanced builder parity after additional security gates. Do not stall the entire safe beta on an optional unsafe feature, but never claim advanced parity until tested.

## Один исполнитель: GitHub operating rules

- ChatGPT единственный ИИ-исполнитель по обоим репозиториям; owner approves merges/deployments and payment/billing changes. Old independent reviews retained as historical findings, not an active parallel team.
- Public Native issue #13 (lost updates), #14 (execution evidence), #2 (builder lifecycle), #3 (historical adversarial QA) are **owned locally**, no more requests to DeepSeek.
- Source of truth: this file + GitHub Issues/PR with exact commit SHA, reproducible CI links and gate state; historical `docs/PROJECT-STATE.md` in Draft #6 is a prior-stage ledger, not active two-agent instructions.
- One concern per feature branch; no direct production writes, token output, uncontrolled REST/CLI or private Cloud source in public.
- First read current branches and manifests, then scoped tests, then code changes and CI. Do not confuse passing negative reproducibility with vulnerability closure. Merge only when relevant gates genuinely pass and version parity is confirmed.
