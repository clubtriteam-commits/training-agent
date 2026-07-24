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
| *(none — text only)* | `"Athlete_A"` | `lactate_tests.athlete_name` (no ID column at all) |

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

**Note on float precision:** the endpoint explicitly sets `ini_set('serialize_precision', -1)` before encoding — production PHP 8.0's `php.ini` has `serialize_precision=100`, which without this override serializes every float with its full IEEE754 tail (e.g. `3.899999999999999911182...` instead of `3.9`). See the commit `cee6413` fix and [scaling.md](scaling.md) for other PHP-version-dependent gotchas.
