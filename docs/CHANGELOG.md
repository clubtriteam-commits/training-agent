# Changelog

## Резюме (Executive Summary)

Проектът е изграден за около две седмици (13–24 юли 2026), на пет ясни етапа: (1) базова автоматизация на тренировъчния анализ, (2) PHP dashboard, (3) здравни алерти по ключови думи, (4) преработка на цялата alert система за надеждност, (5) лабораторни лактатни данни и анализ. Всеки етап е надграждал предишния, без да го чупи — виж git история за пълни детайли, тук е обобщено по тема, не commit по commit.

---

This is a curated summary reconstructed from git history, grouped by theme rather than listed commit-by-commit. See `git log` for the full record.

## Phase 1 — Core automation MVP (2026-07-13)

The foundation: fetch training data from Intervals.icu, compute ACWR, alert on risk transitions.

- Initial repo, `.gitignore`, athlete configuration (`config/athletes.yaml`).
- `fetch_intervals.py` — wellness + activity fetching, tested against 3 real athletes.
- SQLite storage layer + the first version of transition-based ACWR alert detection.
- `main.py` — the daily orchestrator, end-to-end flow working for the first time.
- Wellness fields (HRV, sleep, stress) added to `daily_metrics`.
- Readiness checks (HRV/sleep/stress vs. baseline) integrated into the daily flow.
- World Triathlon athlete IDs added to config; first rankings integration.

## Phase 2 — PHP Dashboard (2026-07-14 to 2026-07-15)

A visual surface for the data the automation was already collecting.

- First PHP dashboard: login + per-athlete summary cards.
- `athlete.php` detail view: charts, period selector.
- `deploy.ps1` — `scp`-based deploy with strict `644`/`755` permission enforcement (see [ADR 0007](adr/0007-limitations.md) for why this matters on this hosting).
- Fixed a ranking-display bug by joining on `athlete_name` instead of mismatched IDs — the first appearance of the join pattern later formalized in [ADR 0004](adr/0004-athlete-name-joins.md).
- Weekly Telegram summary script added.
- `alerts`/`readiness` modules (which had drifted server-only) brought back into the repo and merged with local changes.
- `world_triathlon_results` table, full results fetch, and a per-year results UI.
- Telegram alerts for newly-detected race results, including splits.

## Phase 3 — Dashboard polish & activity-based health alerts (2026-07-18 to 2026-07-19)

- Collapsible metrics glossary added to both dashboard pages (the ancestor of [glossary.md](glossary.md)).
- Injury/pain keyword detection in activity titles/comments (bilingual BG/EN), sending Telegram alerts.
- Late-start workout detection; new-activity deduplication centralized into one shared mechanism (`seen_activities`) instead of each check rolling its own.
- Intervals.icu fetch windows made dynamic (computed relative to "today" instead of hardcoded), removing accidental API calls on module import.
- `deploy.ps1` gained key-based SSH auth support (passwordless deploys).
- Results section redesigned in a "WT-results" visual style; rows made expandable with full split details; per-discipline (swim/T1/bike/T2/run) split positions computed locally, since the World Triathlon API doesn't provide them.

## Phase 4 — Alert architecture migration (2026-07-20 to 2026-07-22)

The alert system's biggest structural change: from an ad-hoc, duplicate-prone log to a dedup-by-construction, retry-safe model.

- Defensive fixes first: `logs/` directory recreated defensively at script start (git doesn't track empty dirs), per-step status logging added to the daily check for better cron-log diagnosability.
- `DAILY_TELEGRAM_ALERTS` flag introduced, decoupling "write to DB" from "actually push to Telegram."
- **`alert_events` table introduced** — create-once, deliver-separately, deduplicated by a `UNIQUE` constraint rather than application logic. See [ADR 0001](adr/0001-alert-events-dedup.md).
- All detection modules refactored onto the new `record_alert_event()` function.
- Retry-safe delivery phase added to `main.py`, plus a cron-overlap file lock (`acquire_lock()`). See [ADR 0002](adr/0002-two-phase-detection-delivery.md).
- `migrate_alerts.py` — one-time migration moving `alerts_log`'s history into `alert_events`, compressing historical duplicates via the same `UNIQUE` constraint.
- Test coverage added for the new architecture (`test_alert_system.py`); `test_lock_concurrency.py` patched for Python 3.6 compatibility (the production interpreter — see [ADR 0005](adr/0005-venv-python311.md)).
- Daily Telegram push re-enabled after the migration stabilized; per-athlete `rest_period` support added so planned rest doesn't trigger detraining alerts.

## Phase 5 — Lab data & lactate analysis (2026-07-23 to 2026-07-24)

A new data domain entirely: lab-measured lactate step tests, previously not represented in the system at all.

- Google Sheets sync (`fetch_lab_data.py`) added — see [ADR 0003](adr/0003-google-sheets-lab-source.md). Header-matching hardened twice in quick succession as the source Sheet's column names drifted in practice (`Протокол` → `Протокол (М/Ж)` → `Sex`).
- Lactate test summary shown on `athlete.php`, with per-step power/HR/lactate detail and color-coded thresholds.
- **Lactate analysis page, Фаза 1**: dedicated single-test chart (`lactate_analysis.php` + new `api_lactate.php` JSON endpoint) — power vs. HR/lactate, computed LT1/LT2 thresholds (falling back to linear interpolation when not manually entered), a 5-zone training model.
- Session cookie consistency bug fixed — repeated login prompts traced to `index.php` bootstrapping sessions without the 30-day config the rest of the app expected. See [ADR 0006](adr/0006-session-cookie-lifetime.md).
- **Лактатен анализ, Фаза 2**: test-to-test comparison — overlay up to 2 additional tests on the same chart, a delta comparison table, shareable/bookmarkable comparison state via URL parameters.
- Float-serialization bug fixed in the JSON API — production's PHP 8.0 `serialize_precision=100` default was leaking raw IEEE754 precision into API responses (e.g. `3.9` as `3.899999999999999911182...`).
- Full project documentation (`docs/`, this changelog included) and a standalone data-integrity audit script (`scripts/audit_data.py`) added — see the repository's `docs/` folder for the full set.
- LT1/LT2 estimation (linear interpolation, already used on the analysis page) extended to the `athlete.php` overview table, which had been silently showing "—" for every test without a manually-entered threshold. As a side effect, fixed the same column-highlighting gap in the expanded step table.

## Phase 6 — Local race results, national lab tests, and an infrastructure discovery (2026-07-25)

Two more independent Google Sheets integrations, both following the pattern established in Phase 5, plus a real infrastructure bug found while verifying one of them.

- **Local (Bulgarian) race results** (`fetch_local_results.py`) — a new two-table sync (`local_events`, `local_results`) for domestic triathlon/duathlon/aquathlon results, merging three discipline-specific Sheet tabs into one table with generic `leg1`/`leg2`/`leg3` columns rather than one table per sport. `athlete.php` gained a "Местни състезания" section mirroring the World Triathlon results UI, built as an independently-scoped block since the page's year-filter JS only binds to the first `.year-nav` element it finds.
- **Edge cache discovered ignoring `Cache-Control`**: while verifying the local-results sync had actually reached the live site, found that production's `sh-cache` layer serves stale HTML for session-authenticated pages regardless of the app's explicit `no-store` header (`X-SH-Cache-Status: HIT` persisting across a genuinely successful data sync). No purge mechanism identified; documented as a standing limitation and runbook entry rather than fixed, since it's outside the application's control.
- **National functional tests (НЦ)** (`fetch_nat_tests.py`) — a second, deliberately separate lab-data stream (`nat_test_protocols`, `nat_functional_tests`) for national-center bike/treadmill testing, kept apart from `lactate_tests` because the protocols aren't physiologically comparable (treadmill VO2max reads 5-10% higher than bike for the same athlete). `includes/nat_tests.php:nat_tests_comparable()` is the single rule every chart goes through. Two real bugs fixed during this build, not part of the original spec: the source Sheet's comma-decimal formatting (`"48,3"`) was being mangled by `gspread`'s default numeric parsing into `483`, silently inflating every weight/VO2max/lactate value 10-100x; and free-text footer commentary in the Sheet's protocol tab was landing in the same column as real protocol slugs, sneaking 8 sentences of notes into the reference table until a stricter row filter was added.
- The `athlete.php` float-serialization bug (see Phase 5) turned out not to be specific to `api_lactate.php` — the same `ini_set('serialize_precision', -1)` fix was needed in `athlete.php` itself once its own embedded chart JSON started carrying real decimal values (national-test VO2max figures) worth noticing the noise in.
