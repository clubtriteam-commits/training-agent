# ADR 0007: Known limitations

## Резюме

Не всичко тук е "решение" — това е списък с ограничения, с които проектът живее съзнателно, защото средата (споделен хостинг, външни API-та) налага граници извън наш контрол. Полезно четиво, преди да предложиш "защо просто не..." — вероятно вече сме обмислили и защо не е толкова просто.

## Status

Living document — update as new constraints are discovered.

## Shared hosting constraints (SuperHosting.bg, CloudLinux)

- **System Python is 3.6.8.** End-of-life since 2021. Every cron job except `fetch_lab_data.py` runs on it (see [ADR 0005](0005-venv-python311.md)). `test_lock_concurrency.py` had to be patched specifically for 3.6 compatibility (commit `d3248f9`) — f-string and stdlib behavior differences between 3.6 and modern Python are a real, recurring tax on any new code that gets tested locally (on a modern Python) and then run in production.
- **File permission model (suPHP-style).** `deploy.ps1` explicitly `chmod`s everything to `644` (files) / `755` (directories) after every deploy, with a comment noting group-writable files make suPHP refuse to execute them — a `664` file (common default from some editors/tools) silently 500s in production but works fine locally. This is a real recurring incident class — see [runbook.md](../runbook.md).
- **WAF blocks default HTTP client User-Agents.** Plain `curl` (default UA) gets a `403 Forbidden` from the hosting's edge WAF on this domain — observed repeatedly when scripting against the live site. A browser-like `User-Agent` header works. Anyone writing monitoring/health-check scripts against the production URL needs to know this or will misdiagnose a WAF block as an application outage.
- **No control over PHP version or `php.ini` defaults.** Production runs PHP 8.0.30 with `serialize_precision=100` (the pre-7.1 default) rather than the modern `-1` — `api_lactate.php` has to explicitly `ini_set()` this per-request rather than relying on a sane platform default. Any new JSON-emitting endpoint needs to remember this too.
- **No `X-Powered-By` or other identifying headers** exposed (hosting-level hardening) — makes remote diagnosis of "what's actually running" harder; the reliable way to check is dropping a temporary diagnostic `.php` file and curling it directly (see [runbook.md](../runbook.md) for the pattern), not response headers.

## Intervals.icu API gaps

- **`stress` is not consistently populated.** The wellness endpoint's `stress` field depends on what the athlete's connected device/platform reports — not every athlete has a source that provides it. The codebase treats every wellness field as nullable throughout (`daily_metrics.stress`, `metrics/readiness.py:check_stress_alert()` bails out entirely if either today's or the baseline `stress` values are `None`), which means the stress-based readiness alert is effectively **silently disabled for any athlete whose device doesn't report it** — there's no visibility into which athletes that is, or an explicit "insufficient data" signal distinct from "no stress spike detected." Confirm per-athlete device coverage if this alert type is expected to be firing and isn't.
- **Activities restricted/private in the athlete's source (e.g. Strava) may not appear via Intervals.icu at all.** Intervals.icu commonly ingests activities from a connected Strava (or similar) account; an activity an athlete marks private/hidden at the source can fail to sync through, meaning `metrics/comment_alerts.py`'s keyword scan and `metrics/late_start.py`'s late-start check simply never see it — no error, the activity is just absent from the `get_activities()` response. This is a known category of gap, not something the codebase currently detects or flags; if a coach expects an alert for a specific workout, checking Intervals.icu directly is the first debugging step, not this codebase's logs.

## SQLite concurrency

- Single-writer-at-a-time by nature. `main.py` protects itself with a `flock()`-based lock (`acquire_lock()`, POSIX-only — a no-op on Windows dev machines) so overlapping cron runs exit immediately rather than contend for the database — but **other scripts (`fetch_world_triathlon.py`, `weekly_summary.py`, `fetch_lab_data.py`) have no such lock.** Two of them writing at genuinely the same moment (unlikely given the current cron schedule's spacing, but not impossible if a run is delayed) can hit `database is locked` errors with no retry logic to absorb it.
- The PHP dashboard opens its own `PDO sqlite:` connection per request, read-only in practice (no PHP code path writes to the database) — this doesn't contend with the Python writers under SQLite's locking model in any way that's caused a known incident, but it's untested under real concurrent load (see [scaling.md](../scaling.md)).
