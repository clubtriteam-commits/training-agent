# Scaling

## Резюме (Executive Summary)

Системата в момента следи 3 атлета и работи без проблеми. Но е изградена с прости, последователни (не паралелни) цикли и почти без database индекси извън default-ните от UNIQUE ограниченията — работи добре на малък мащаб, но има конкретни, проверени в кода точки, които ще станат проблем при растеж. Най-сериозната: заявките по `athlete_name` (мост между различните ID системи, виж [ADR 0004](adr/0004-athlete-name-joins.md)) нямат database индекс изобщо — при повече исторически данни всяко зареждане на dashboard-а ще прави пълно сканиране на таблицата за всеки атлет.

---

Findings below are marked **[verified]** (confirmed by reading the actual code/schema) or **[estimated]** (reasoned from code behavior, not measured under real load) or **[unknown]** (a real risk this codebase has no visibility into — flagged so it doesn't get mistaken for "checked, and it's fine").

## What breaks first: dashboard query performance **[verified]**

`storage/db.py` defines **zero explicit indexes** — every query relies on the implicit index SQLite creates for each `UNIQUE` constraint. This works *only* when a query's `WHERE` clause matches a `UNIQUE`d column combination. It doesn't, in two places that run on every single dashboard page load:

- `world_triathlon`'s only unique index is `(athlete_id, fetched_at)` — but `dashboard.php:get_latest_ranking()` and `weekly_summary.py` query `WHERE athlete_name = ?`. Every such query is a **full table scan**, once per athlete, per page load.
- `world_triathlon_results`'s only unique index is `(athlete_id, event_id, prog_id)` — but `athlete.php`'s race-results query also filters `WHERE athlete_name = ?`, same problem.

At 3 athletes and ~150 total rows across both tables, a full scan is unmeasurably fast. It stops being unmeasurable once `world_triathlon` accumulates years of 3x/week ranking snapshots across more athletes — at, say, 30 athletes × 3×/week × 3 years ≈ 14,000 rows, `dashboard.php` doing 30 unindexed full scans (one per athlete card) on every load is a real, user-visible slowdown, not a theoretical one.

**Fix, when it matters:** `CREATE INDEX idx_world_triathlon_name ON world_triathlon(athlete_name)` and the equivalent on `world_triathlon_results` — cheap, backward-compatible, no code changes needed elsewhere.

## Cron duration **[estimated]**

`main.py`, `fetch_world_triathlon.py` all process athletes in a **plain sequential `for` loop** — no concurrency anywhere in the codebase. Runtime scales roughly linearly with athlete count, bounded by network I/O to Intervals.icu / World Triathlon per athlete.

- At 3 athletes, each daily run almost certainly completes in well under a minute (not directly measured/logged — `main.py` doesn't currently log its own total wall-clock time, which is itself a gap worth closing before this becomes hard to diagnose).
- At 10 athletes: still likely fine, low minutes.
- At 50 athletes: if each athlete's fetch+detect cycle takes 2–5 seconds (typical for a couple of sequential HTTP calls + SQLite writes), that's 100–250 seconds just for `main.py`. `fetch_world_triathlon.py` runs 3x on Mondays and does *more* per-athlete work (rankings + paginated results + the capped split-position backfill) — this is the script most likely to start bumping into real time limits first.
- **[unknown]:** the exact CPU/wall-clock limits this specific shared-hosting cron environment enforces on a script invoked directly by system cron (not through PHP-FPM, so typical `max_execution_time` framing may not apply cleanly). Not verified — recommend adding simple start/end timestamp logging to `main.py` and `fetch_world_triathlon.py` before athlete count grows meaningfully, so this stops being a guess.

## SQLite concurrency **[verified] code / [estimated] impact**

Only `main.py` takes a lock (`acquire_lock()`, POSIX `flock`) — see [ADR 0007](adr/0007-limitations.md). `fetch_world_triathlon.py`, `weekly_summary.py`, `fetch_lab_data.py` have none. Growing athlete count doesn't by itself increase lock contention (each script's writes are still one process at a time under the current cron schedule) — the real risk is **schedule collision**, which becomes more likely as any individual script's runtime grows (see above) and starts overlapping into a neighboring cron slot. At 50 athletes, if `fetch_world_triathlon.py`'s Monday 12:00 run is still finishing when the 12:00 slot... (it only fires once, but a slow-running `main.py` from 20:00 spilling toward midnight next to unrelated jobs is the more realistic collision shape). Worth a lock on every write-capable script, not just `main.py`, before athlete count grows significantly.

## API rate limits

- **Intervals.icu** — **[unknown]**, no documented limit referenced anywhere in this codebase. Current usage: ~2 calls/athlete/day (`get_wellness`, `get_activities`). At 50 athletes that's ~100 calls/day, trivial for most API providers but genuinely unverified for this one specifically.
- **World Triathlon API** — **[unknown]** stability/SLA; this is an unofficial-feeling federation API (no rate-limit headers or documented quota referenced in `fetch_world_triathlon.py`'s comments). The code already defends against its own worst-case load with `MAX_EVENT_FETCHES_PER_RUN = 40` and a `0.5s` pause between per-event calls (`compute_missing_split_positions()`) — a self-imposed throttle, not a response to a documented limit. At 50 athletes, the *initial* rankings+results calls (not the capped backfill) scale linearly and unthrottled — worth watching first.
- **Google Sheets API** — **[verified low risk currently]**: documented Google default quota is 100 requests/100 seconds/user. `fetch_lab_data.py` does a small, constant number of API calls per run (open spreadsheet + one `get_all_records()` call) **regardless of athlete count or row count** — the whole sheet is read in one call. This does not scale with athlete count in any way that approaches the quota.
- **Telegram** — Bot API's documented per-chat throttling is roughly 1 message/second sustained, with bursts flagged by `429 Too Many Requests`. `alerts/notifier_telegram.py:send_alerts_batch()` bundles ≤5 alerts into one message but sends >5 as individual `send_telegram_message()` calls **with no delay between them and no `429`/`retry-after` handling** — it just checks for HTTP `200` and returns `False` otherwise. Thanks to [ADR 0002](adr/0002-two-phase-detection-delivery.md), a flood-controlled message doesn't get lost — it stays `delivered_at IS NULL` and retries next run — but a genuinely bad day for many athletes at once (a plausible scenario once there are 10–50 of them) could see several alerts silently delayed by a day due to unhandled flood control, with nothing surfacing that this happened beyond the cron log.

## Summary: rough breaking points

| Athletes | Likely first symptom |
|---|---|
| ~10 | Nothing yet — current architecture has real headroom here. |
| ~30 | `world_triathlon`/`world_triathlon_results` unindexed `athlete_name` scans become a noticeable dashboard load-time cost. |
| ~50 | Cron runtime for `fetch_world_triathlon.py` worth actively monitoring; unhandled Telegram flood control starts being plausible on high-alert days; still no evidence of hitting Intervals.icu/World Triathlon API limits, but also no instrumentation that would tell you if you had. |
