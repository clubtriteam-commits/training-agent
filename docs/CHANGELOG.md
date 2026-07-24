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
