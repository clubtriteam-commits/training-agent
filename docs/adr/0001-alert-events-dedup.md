# ADR 0001: `alert_events` UNIQUE constraint as the deduplication mechanism

## Резюме

Как системата гарантира, че една и съща аларма не се изпраща два пъти в Telegram? Не с код, който проверява "дали вече съм пращал това" — а с ограничение на самата база данни (UNIQUE constraint), което физически не позволява втори ред със същата комбинация от атлет/дата/тип аларма. По-просто и по-надеждно от код-базирана проверка.

## Status

Accepted (implemented in commit `9721e9a`, superseding the earlier `alerts_log` table).

## Context

`main.py` re-fetches and re-analyzes a rolling window of data every run (14 days of wellness, 7 days of activities) rather than only "new since last run" — this is deliberate, so a missed or failed cron run self-heals within the window instead of permanently losing a day. But re-analyzing the same day repeatedly means the same ACWR transition, the same flagged keyword, the same late-start activity gets *detected* again on every run.

The original implementation (`alerts_log`, pre-`9721e9a`) had no such constraint and relied on application-level checks before inserting — which drifted and produced duplicate Telegram messages in production (the reason `alerts_log` has real historical duplicates, visible in `migrate_alerts.py`'s handling of them).

## Decision

Make the database itself the source of truth for "have I already recorded this": a `UNIQUE(athlete_id, event_date, alert_type, source_id)` constraint on `alert_events`, paired with `INSERT OR IGNORE` in `record_alert_event()` (`storage/db.py`). The function's return value (`True`/`False` — did a row actually get inserted) tells the caller whether this is a genuinely new event, which is what gates whether it gets added to the "alerts to report" list in that run's output.

`source_id` exists specifically so day-level alerts (ACWR, readiness — inherently one possible event per day, `source_id = ''`) and activity-level alerts (keyword scan, late start — an athlete can log two flagged activities in one day, `source_id = activity_id`) share the same table and constraint shape without colliding.

## Consequences

**Positive:**
- Impossible to duplicate an alert by accident, regardless of how many times or how concurrently the detection code runs — the constraint is enforced by SQLite, not by application logic that can have bugs.
- Recalculating a metric with a slightly different value for an already-alerted day (e.g. ACWR recomputed as 1.62 instead of 1.6) does **not** create a second alert — see `test_alert_system.py:test_unique_constraint_on_recalculation`.

**Negative / accepted trade-offs:**
- The constraint silently swallows legitimate updates to `message` text — if you wanted to *update* an already-recorded alert's wording, `INSERT OR IGNORE` won't do it. This has never been needed in practice.
- `source_id = ''` for day-level alerts means at most one ACWR alert per athlete per day can ever exist, even if the semantics of "ACWR high" changed twice in one day (not physically possible given the data granularity, but worth knowing if the alert taxonomy grows).
