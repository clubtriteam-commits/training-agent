# ADR 0002: Two-phase detection/delivery for alerts

## Резюме

Разделихме "откриване на проблем" от "изпращане на съобщение" на две отделни стъпки. Ако Telegram е недостъпен, откритата аларма не се губи — остава маркирана като "недоставена" и системата опитва пак при следващото пускане, докато не успее.

## Status

Accepted (commit `312c4b4`, building on [ADR 0001](0001-alert-events-dedup.md)).

## Context

The original flow detected a condition and immediately tried to send it to Telegram in the same step. If the Telegram API call failed (network blip, rate limit, bot token issue), the alert was lost — the next run would re-detect the same condition only if the underlying data window still covered it, and even then only for conditions that don't require a *transition* (like keyword/late-start checks); transition-based alerts (ACWR status change) would never fire again because the "previous status" comparison had already moved on.

## Decision

Split into two independent phases, both running every `main.py` execution:

1. **Detection** — each metric module (`metrics/acwr.py`, `readiness.py`, `comment_alerts.py`, `late_start.py`) calls `record_alert_event()`, which writes to `alert_events` with `delivered_at = NULL`. This phase never touches Telegram.
2. **Delivery** — `main.py:deliver_pending_alerts()` runs *after* all detection is done, queries `get_undelivered_events()` (`WHERE delivered_at IS NULL`), and attempts to send each one. Only on a successful `send_telegram_message()` call does `mark_delivered()` update the row.

Delivery is retry-safe by construction: an event that fails to send simply stays `delivered_at IS NULL` and gets picked up again on the *next* cron run, with no special retry logic needed beyond "try everything that's still pending."

A `DAILY_TELEGRAM_ALERTS` flag (`main.py`) can decouple this further: when `False`, detection and DB writes still happen normally, but delivery marks events delivered *without* sending — used historically to silence daily pushes while keeping `weekly_summary.py`'s Sunday digest as the only Telegram-facing output. Currently `True` in production.

## Consequences

**Positive:**
- A Telegram outage never loses an alert — see `test_alert_system.py:test_retry_after_telegram_failure`.
- Detection logic has zero knowledge of delivery mechanics — swapping Telegram for email/Slack later only touches `deliver_pending_alerts()`.
- The `alert_events` table itself becomes a complete, queryable history independent of whether delivery ever succeeded — `athlete.php`'s alert history table reads it directly.

**Negative / accepted trade-offs:**
- An alert can sit undelivered indefinitely if `deliver_pending_alerts()` itself never runs successfully (e.g. cron stops entirely) — nothing pages anyone about a backlog of undelivered alerts. `scripts/audit_data.py` doesn't currently check for this either; see [scaling.md](../scaling.md).
- Delivery order is `detected_at ASC` — if the backlog is large, the oldest (potentially now-stale) alert is texted first, not the most urgent.
