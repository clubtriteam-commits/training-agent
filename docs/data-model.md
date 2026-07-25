# Data Model

## Резюме (Executive Summary)

Системата съхранява всички данни в един единствен SQLite файл (`data/agent.db`) — няма отделна база данни за всеки модул. Различните таблици идват от различни източници (Intervals.icu API, World Triathlon API, Google Sheets) и се обновяват с различна честота (дневно, седмично). Най-важната сложност: три различни системи използват три различни ID-та за един и същ атлет, и се налага "мост" през полето `athlete_name`, за да се свържат данните. Това е технически дълг, документиран подробно по-долу и в [ADR 0004](adr/0004-athlete-name-joins.md).

---

All application state lives in a single SQLite file, `data/agent.db` (path resolved by `storage/db.py:DB_PATH`, relative to the repo root: `data/agent.db`). No other datastore exists — the PHP dashboard reads the *same file* directly via `PDO sqlite:`, not through an API.

Example IDs below use the anonymized scheme: **Athlete_A** = `intervals_id i000001`, `world_triathlon_id wt_100001`. Real data uses actual Intervals.icu athlete IDs (`i######`) and World Triathlon numeric IDs.

## The athlete ID problem

Three unrelated ID systems refer to the same person, and the codebase never unifies them into one `athletes` table:

| System | ID example | Used as join key in |
|---|---|---|
| Intervals.icu | `i000001` | `daily_metrics.athlete_id`, `alert_events.athlete_id`, `seen_activities.athlete_id` |
| World Triathlon | `wt_100001` | `world_triathlon.athlete_id`, `world_triathlon_results.athlete_id` |
| *(none — text only)* | `"Athlete_A"` | `lactate_tests.athlete_name`, `local_results.athlete_name`, `nat_functional_tests.athlete_name` (no ID column at all in any of the three) |

`config/athletes.yaml` is the **only place** that maps all three together:

```yaml
athletes:
  - name: "Athlete_A"
    intervals_id: "i000001"
    world_triathlon_id: "wt_100001"
```

Consequences, all deliberate trade-offs (see [ADR 0004](adr/0004-athlete-name-joins.md) for why this wasn't fixed):

- **PHP queries join across tables by `athlete_name`**, not by any ID, whenever they need to combine `daily_metrics` (keyed by Intervals ID) with `world_triathlon`/`world_triathlon_results` (keyed by World Triathlon ID). Example, from `athlete.php`:
  ```sql
  SELECT world_ranking FROM world_triathlon WHERE athlete_name = ?
  ```
- **`lactate_tests` has no athlete ID at all** — only `athlete_name`, because the data source (a Google Sheet filled in by lab staff) has never heard of either ID system. `athlete.php` resolves this by passing the Intervals `athlete_id` through as a URL query parameter (`lactate_analysis.php?test_id=X&athlete_id=i000001`) rather than storing it — the "back to profile" link only works because the *page that links to it* already knows the ID, not because the database can look it up.
- **Renaming an athlete in `config/athletes.yaml` silently breaks every historical join.** There is no migration path — old rows under the old name become orphaned from `daily_metrics` unless you also update every table's `athlete_name` value. `scripts/audit_data.py` includes a check for this drift.

## Tables

### `daily_metrics` — training load & wellness, one row per athlete per day

| Column | Type | Source |
|---|---|---|
| `athlete_id` | TEXT | Intervals.icu ID |
| `athlete_name` | TEXT | `config/athletes.yaml` |
| `date` | TEXT (ISO) | Intervals.icu wellness `id` field |
| `ctl`, `atl` | REAL | Intervals.icu wellness endpoint |
| `acwr`, `acwr_status` | REAL, TEXT | Computed locally by `metrics/acwr.py` |
| `hrv`, `sleep_secs`, `stress`, `resting_hr` | REAL/INT | Intervals.icu wellness endpoint (device-dependent — see [limitations](adr/0007-limitations.md)) |
| `fetched_at` | TEXT (auto) | Row write time |

**Unique constraint:** `(athlete_id, date)` — re-fetching the same day upserts in place, never duplicates.
**Update frequency:** daily, `main.py` (20:00 cron), 14-day rolling window re-fetched every run (so a missed run self-heals within 2 weeks).
**Retention:** unbounded — no deletion logic exists anywhere in the codebase. At current scale (3 athletes, months of history) this is 63 rows; see [scaling.md](scaling.md) for growth projection.
**Written by:** `metrics/acwr.py:analyze_athlete_acwr()` → `upsert_daily_metric()`.
**Read by:** `metrics/readiness.py`, `weekly_summary.py`, `dashboard.php`, `athlete.php`.

### `alert_events` — the current alert system (detect-once, deliver-separately)

| Column | Type | Notes |
|---|---|---|
| `athlete_id`, `athlete_name` | TEXT | |
| `event_date` | TEXT (ISO) | The date the *condition* occurred, not when it was detected |
| `alert_type` | TEXT | `acwr_high`, `acwr_low`, `acwr_normalized`, `readiness_hrv`, `readiness_sleep`, `readiness_stress`, `comment_keyword`, `late_start` |
| `message` | TEXT | Pre-formatted Telegram message text (with emoji) |
| `source_id` | TEXT | Empty string for day-level alerts (ACWR/readiness — one possible alert per day); activity ID for activity-level alerts (`comment_keyword`, `late_start` — an athlete can log multiple flagged activities in one day) |
| `detected_at` | TEXT (auto) | |
| `delivered_at` | TEXT, nullable | `NULL` = not yet sent to Telegram; retried every run until set |

**Unique constraint:** `(athlete_id, event_date, alert_type, source_id)` — this is the entire deduplication mechanism. See [ADR 0001](adr/0001-alert-events-dedup.md).
**Update frequency:** daily (detection), daily (delivery retry) — both in `main.py`.
**Retention:** unbounded.
**Written by:** `record_alert_event()`, called from `metrics/acwr.py`, `metrics/readiness.py`, `metrics/comment_alerts.py`, `metrics/late_start.py`.
**Read by:** `main.py:deliver_pending_alerts()`, `weekly_summary.py`, `athlete.php`, `dashboard.php`, `scripts/audit_data.py`.

### `alerts_log` — deprecated, read-only archive

Pre-dates `alert_events` (see [ADR 0002](adr/0002-two-phase-detection-delivery.md)). No code writes here anymore; `migrate_alerts.py` was a one-time script that copied its history into `alert_events`. Kept only so old data isn't lost. **Do not write to this table in new code.**

| Column | Type |
|---|---|
| `athlete_id`, `athlete_name`, `date`, `alert_type`, `message`, `sent_at` | — |

### `world_triathlon` — ranking snapshots

| Column | Type | Notes |
|---|---|---|
| `athlete_id` | TEXT | **World Triathlon ID**, not Intervals ID |
| `athlete_name` | TEXT | Join bridge — see above |
| `world_ranking`, `regional_ranking` | INT, nullable | |
| `fetched_at` | TEXT (auto) | |

**Unique constraint:** `(athlete_id, fetched_at)` — this stores a **time series** (one row per fetch), not a single current value; `fetched_at` includes time-of-day so 3x/week fetches don't collide.
**Update frequency:** 3x/week (Monday 10:00, 12:00, 18:00 — see [ADR 0003](adr/0003-google-sheets-lab-source.md)'s sibling note in workflows.md for why 3x).
**Written by:** `fetch_world_triathlon.py:fetch_and_save_rankings()`.
**Read by:** `athlete.php` (ranking chart + tile), `weekly_summary.py`, `dashboard.php`.

### `world_triathlon_results` — race results with splits

| Column | Type | Notes |
|---|---|---|
| `athlete_id` | TEXT | World Triathlon ID |
| `event_id`, `prog_id` | INT | `prog_id` disambiguates multiple programs at one event (e.g. individual + relay) |
| `event_date`, `event_title`, `event_country` | TEXT | |
| `position`, `total_time` | TEXT | `position` is TEXT because the API returns `"DNF"`/`"DSQ"`/`"LAP"` as well as numbers |
| `swim_split` … `run_split` | TEXT | `"H:MM:SS"` format, nullable |
| `swim_position` … `run_position` | TEXT | Per-discipline rank, computed **locally** (not provided by the API) — see `compute_split_positions()`. TEXT because ties render as `"=3"`. |
| `positions_computed_at` | TEXT, nullable | Marks whether the (rate-limited) per-split position computation has run for this result yet |

**Unique constraint:** `(athlete_id, event_id, prog_id)`.
**Update frequency:** weekly (Monday 10:00, alongside rankings). Per-split positions computed incrementally, capped at 40 event API calls/run to respect rate limits (see [scaling.md](scaling.md)).
**Written by:** `fetch_world_triathlon.py:fetch_and_save_results()`, `save_result_positions()`.
**Read by:** `athlete.php` (results table with expandable splits).

### `seen_activities` — dedup ledger for activity-based checks

| Column | Type |
|---|---|
| `athlete_id`, `activity_id` | TEXT |
| `checked_at` | TEXT (auto) |

**Unique constraint:** `(athlete_id, activity_id)`.
**Purpose:** `filter_new_activities()` is the single dedup entry point shared by `metrics/comment_alerts.py` and `metrics/late_start.py` — an activity is only ever scanned for keywords/late-start once, no matter how many times `main.py` re-fetches the last-7-days window.
**Retention:** unbounded, grows by one row per activity per athlete forever. No pruning exists — flagged in [scaling.md](scaling.md).

### `lactate_tests` — lab step-test data, synced from Google Sheets

| Column | Type | Notes |
|---|---|---|
| `test_date` | TEXT (ISO) | |
| `athlete_name` | TEXT | **The only identifier** — no athlete ID column, see above |
| `protocol` | TEXT | `"М"` / `"Ж"` (or `"Sex"` column header fallback — [ADR 0003](adr/0003-google-sheets-lab-source.md)) |
| `height_cm`, `weight_kg`, `age` | REAL/INT | |
| `ftp`, `w_kg` | REAL | |
| `lactate_start`, `hr_start` | REAL | Resting values before the test |
| `step1_hr`…`step10_hr`, `step1_la`…`step10_la` | REAL, nullable | Up to 10 steps; unreached steps are `NULL`, not `0` |
| `lt1_w`, `lt2_w` | REAL, nullable | Manually entered in the Sheet if known; otherwise computed on-the-fly by `api_lactate.php` (never stored) |
| `notes` | TEXT | |
| `synced_at` | TEXT (auto) | |

**Unique constraint:** `(athlete_name, test_date)`.
**Update frequency:** weekly (Monday 08:00), full-replace upsert — every sync overwrites all columns for matching rows. **`fetch_lab_data.py` never deletes rows removed from the Sheet** — deleting a row in Google Sheets leaves an orphaned row in SQLite until someone manually `DELETE`s it. This has already happened once in production (a test row for a wrong athlete name) and is checked by `scripts/audit_data.py`.
**Written by:** `fetch_lab_data.py:sync_lactate_tests()`.
**Read by:** `athlete.php` (test list + expandable step table), `api_lactate.php`.

### `local_events` — Bulgarian local competitions (metadata)

| Column | Type | Notes |
|---|---|---|
| `event_id` | TEXT PRIMARY KEY | A slug, not a numeric ID (e.g. `dp-sprint-plovdiv-2026`) — assigned in the Sheet by whoever enters the event, not by any external system. |
| `event_date`, `name`, `city`, `organizer`, `source_url` | TEXT | `source_url` links to the timing company's published results page, shown as an outbound link on `athlete.php`. |
| `synced_at` | TEXT (auto) | |

**Update frequency:** weekly (Monday, alongside `local_results` — same script, same run).
**Written by:** `fetch_local_results.py:sync_events()`.
**Read by:** `athlete.php` (joined into the local-results table for event name/city/link).

### `local_results` — Bulgarian local competition results (triathlon/duathlon/aquathlon)

| Column | Type | Notes |
|---|---|---|
| `event_id` | TEXT | FK to `local_events.event_id` (no `FOREIGN KEY` constraint declared — SQLite wouldn't enforce it by default anyway) |
| `sport` | TEXT | `triathlon` / `duathlon` / `aquathlon` |
| `athlete_name` | TEXT | **Only identifier** — same limitation as `lactate_tests`, see above |
| `distance`, `category`, `place`, `club` | TEXT | `place` is TEXT for the same DNF/DSQ reason as `world_triathlon_results.position` |
| `leg1`, `t1`, `leg2`, `t2`, `leg3` | TEXT | **Generic, discipline-agnostic column names** — deliberately not `swim_split`/`bike_split`/`run_split` like `world_triathlon_results`, because what each leg *means* depends on `sport`: triathlon is swim/bike/run, duathlon is run/bike/run, aquathlon is run/swim/run (with `t1`/`t2` always `NULL` — no timed transitions in the source data). See `local_leg_labels()` in `athlete.php` for the sport → label mapping, and [ADR 0003](adr/0003-google-sheets-lab-source.md)'s sibling reasoning for why one generic table beats one table per discipline. |
| `pos_leg1`, `pos_leg2`, `pos_leg3` | TEXT | Per-leg placement, as entered in the Sheet (not computed locally, unlike `world_triathlon_results`) |
| `field_size` | INTEGER | Size of the category field the athlete competed in |
| `synced_at` | TEXT (auto) | |

**Unique constraint:** `(event_id, athlete_name)`.
**Update frequency:** weekly (Monday), upsert-only — same orphan-row caveat as `lactate_tests` (a row deleted from the Sheet stays in SQLite until manually removed; `fetch_local_results.py` prints a warning listing orphans on every run but never deletes).
**Written by:** `fetch_local_results.py:sync_results()`.
**Read by:** `athlete.php` ("Местни състезания" section — year-filtered table with expandable per-leg splits, mirrors the `world_triathlon_results` UI but as an independently-scoped block since the page's existing `.year-nav`/`#results-table` JS selectors only ever bind to the first match on the page).

### `nat_test_protocols` — reference data for national-lab test protocols

| Column | Type | Notes |
|---|---|---|
| `protocol` | TEXT PRIMARY KEY | Slug: `club-bike`, `natlab-bike`, `natlab-treadmill` |
| `device`, `start_value`, `increment`, `incline`, `metric`, `lab`, `note` | TEXT | Human-readable protocol description, shown verbatim under each test group on `athlete.php` (`nat_protocol_description()`) rather than reformatted, so it never drifts from what the lab actually recorded |
| `step_minutes` | REAL | |
| `synced_at` | TEXT (auto) | |

**Note:** `club-bike` is a reference row only — describes the *club's own* lactate-test protocol (already stored in `lactate_tests`, see [ADR 0003](adr/0003-google-sheets-lab-source.md)) for comparison purposes. No `club-bike` rows ever appear in `nat_functional_tests`.
**Update frequency:** weekly (Monday 08:05).
**Written by:** `fetch_nat_tests.py:sync_protocols()`.
**Read by:** `athlete.php` (protocol description blurb under each test group), `includes/nat_tests.php:nat_protocol_description()`.

### `nat_functional_tests` — national-center lab step-test results

| Column | Type | Notes |
|---|---|---|
| `athlete_name`, `test_date`, `protocol` | TEXT | Together form the unique key — see below |
| `device`, `lab` | TEXT | |
| `height_cm`, `arm_span_cm`, `weight_kg`, `lean_mass_kg`, `fat_pct`, `fat_kg`, `muscle_pct`, `muscle_kg` | REAL | Body composition at test time |
| `duration_min` | REAL | |
| `w_max`, `w_max_kg` | REAL, nullable | Bike protocols only — `NULL` for treadmill tests |
| `s_max_kmh` | REAL, nullable | Treadmill protocol only — `NULL` for bike tests |
| `vo2max`, `vo2max_kg`, `hr_max` | REAL/INT | Present for both protocol types — **not directly comparable across protocols**, see below |
| `epz_from`, `epz_to` | INTEGER, nullable | Effort/pace zone range, shown as `{from}–{to}` on `athlete.php` |
| `la_2`, `la_6`, `la_15`, `hr_2`, `hr_6` | REAL/INT, nullable | Lactate (mmol/L) and HR at fixed post-test recovery minutes. Legitimately absent for some tests (a 2022 test in production has no lactate values at all) — `NULL`, not `0`. |
| `synced_at` | TEXT (auto) | |

**Unique constraint:** `(athlete_name, test_date, protocol)` — a **triple**, not the `(athlete_name, test_date)` pair used by `lactate_tests`. This matters: an athlete can have both a bike and a treadmill test on the same day (the whole squad did, in one April 2026 test session) — a two-column key would silently collide and drop one of the two rows on upsert.
**Comparability rule:** `vo2max_kg` in particular looks like one continuous metric but isn't — treadmill VO2max typically reads 5-10% higher than bike for the *same* athlete (higher metabolic cost from the treadmill's incline), and the club's own bike protocol uses different step sizes than the national lab's. `includes/nat_tests.php:nat_tests_comparable($protocol_a, $protocol_b)` is the single source of truth for "may these two values be compared" (true only when `protocol_a === protocol_b`) — every chart on `athlete.php` renders one series per protocol rather than one continuous line, specifically so this never gets silently violated by a naive "just plot vo2max_kg by date" implementation. A real production data point (`vo2max_kg` 70.94 on a 2022 treadmill test, 68.94 on a 2026 bike test for the same athlete) looks like a decline if plotted as one series; it is not one, once protocol is accounted for.
**Data quality note:** the source Sheet uses comma-decimal formatting (Bulgarian locale, e.g. `"48,3"`). `gspread`'s default `get_all_records()` mangles this into `483` instead of `48.3` — `fetch_nat_tests.py` reads with `numericise_ignore=['all']` and parses the comma itself. Any future script reading this same Sheet (or one with the same locale settings) needs to do the same, or every weight/VO2max/lactate value will silently come out 10-100x too large.
**Update frequency:** weekly (Monday 08:05 — 5 minutes after `fetch_lab_data.py`'s 08:00 slot, deliberately offset since neither script takes a lock and a same-instant SQLite write from both is possible in principle).
**Written by:** `fetch_nat_tests.py:sync_tests()`.
**Read by:** `athlete.php` ("Национални функционални тестове" section — tables grouped by protocol, two charts: VO2max/kg and W_max/kg, one series per protocol).

## `api_lactate.php` — the one real API in this codebase

Everything else in the PHP layer queries SQLite directly and renders server-side HTML. `api_lactate.php` is the exception — a session-gated JSON endpoint, added to support the client-rendered lactate analysis chart (`lactate_analysis.php`).

**Auth:** checks `$_SESSION['logged_in']` directly (not via `require_login()`, which redirects — inappropriate for a `fetch()` target). Returns `401` JSON, not an HTML redirect, on an unauthenticated request.

### `GET api_lactate.php?test_id={int}`

Returns one test's full detail, with computed LT thresholds and zones.

```json
{
  "athlete_name": "Athlete_A",
  "test_date": "2025-10-31",
  "protocol": "М",
  "ftp": 236, "w_kg": 3.9,
  "height": null, "weight": null, "age": null,
  "lactate_rest": null, "hr_rest": 58,
  "steps": [{"watts": 80, "hr": 100, "lactate": 0.7}, "... up to 10"],
  "lt1_w": 197.5, "lt2_w": 238, "lt1_estimated": true, "lt2_estimated": true,
  "zones": [{"name": "Z1 Recovery", "from_w": 0, "to_w": 167.9, "color": "rgba(76,175,80,0.10)"}, "... 5 total"],
  "notes": null
}
```

Errors: `400` missing/invalid `test_id`, `404` test not found, `401` no session.

### `GET api_lactate.php?list=1&athlete={name}`

Returns all tests for one athlete (by `athlete_name` — the only key that exists), newest first — used to populate the "compare with" pickers.

```json
{"tests": [{"test_id": 4, "test_date": "2025-10-31", "ftp": null, "w_kg": null}, "..."]}
```

**Note on float precision:** the endpoint explicitly sets `ini_set('serialize_precision', -1)` before encoding — production PHP 8.0's `php.ini` has `serialize_precision=100`, which without this override serializes every float with its full IEEE754 tail (e.g. `3.899999999999999911182...` instead of `3.9`). See the commit `cee6413` fix and [scaling.md](scaling.md) for other PHP-version-dependent gotchas. `athlete.php` needed the identical fix (commit `6346d01`) for its own inline `json_encode()` calls (`$chart_data`, `NAT_DATA`) — **any new PHP file that `json_encode()`s a float for JS consumption needs this same `ini_set()` call near the top**, it is not a property of `api_lactate.php` alone.
