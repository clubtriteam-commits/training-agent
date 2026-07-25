# Workflows

## Резюме (Executive Summary)

Системата работи чрез шест автоматични задачи (cron), пуснати на сървъра в различни моменти от седмицата — четири на Python 3.6 (системния), една на Python 3.11 (заради Google Sheets библиотеката). Има ДВА отделни начина за качване на промени: `deploy.ps1` качва само PHP dashboard-а, докато Python скриптовете (тези, които cron изпълнява) се обновяват само чрез `git pull` директно на сървъра. Забравянето на втория механизъм е причинявало сървърът да изпълнява остарял код — виж [runbook.md](runbook.md).

---

## Quickstart — from a fresh clone to a running local dashboard

```bash
git clone https://github.com/clubtriteam-commits/training-agent.git
cd training-agent
pip install -r requirements.txt          # requests, PyYAML, python-dotenv, gspread, google-auth

# Local dev has no real Intervals/World Triathlon/Telegram credentials —
# seed synthetic data instead of hitting live APIs:
python seed_dev_data.py                  # 180 days x 3 synthetic athletes into data/agent.db

cd dashboard-backup
php -S 127.0.0.1:8899
# visit http://127.0.0.1:8899/index.php — password is config/secrets.env's DASHBOARD_PASSWORD
```

If `config/secrets.env` doesn't exist yet, create it with at minimum:
```
DASHBOARD_PASSWORD=whatever-you-want-locally
```
Nothing else is required to browse the dashboard against seeded data. `INTERVALS_API_KEY` / `WORLD_TRIATHLON_API_KEY` / `TELEGRAM_BOT_TOKEN` / `TELEGRAM_CHAT_ID` are only needed if you're actually running the fetch scripts against live APIs — most local development doesn't need them.

## Daily cron flow: `main.py` (20:00 daily)

```
fetch (Intervals.icu wellness + activities, per athlete)
  → detect (ACWR, readiness, keyword scan, late-start — all write to alert_events)
  → deliver (send everything with delivered_at IS NULL to Telegram)
```

Runs once per athlete in `config/athletes.yaml`, in order. A failure fetching one athlete's wellness (network error, API down) is caught, logged (`❌ {name} | fetch — ...`), and that athlete's remaining steps for the run are skipped — **other athletes are unaffected**, the loop continues.

`acquire_lock()` (POSIX `flock` on `logs/main.lock`) makes overlapping runs exit immediately rather than double-process — relevant if a run is unusually slow and the next scheduled run fires before it finishes. See [ADR 0002](adr/0002-two-phase-detection-delivery.md) for why detection and delivery are separate phases within this one script.

`DAILY_TELEGRAM_ALERTS` (a constant at the top of `main.py`, currently `True`) can silence daily Telegram pushes without touching detection — alerts still get written to `alert_events` and marked delivered, just without actually sending, leaving `weekly_summary.py`'s Sunday digest as the only Telegram output. Toggling this is a one-line code change + redeploy, not a config value.

## Weekly summary: `weekly_summary.py`

**Current cron schedule: Sunday 19:00** (`0 19 * * 0`). Note: the script's own docstring says "Sunday 08:00" — that's stale documentation inside the file itself; the crontab is the actual source of truth. Sends **unconditionally** (not alert-gated) — see [features.md](features.md#5-reporting).

## World Triathlon sync: `fetch_world_triathlon.py`

**Current cron schedule: Monday 10:00, 12:00, and 18:00** — three times, same script, same day. This spacing exists to catch same-day ranking/result publication delays around race weekends without polling continuously; there's no code-level reason it has to be exactly these three times. Each run does rankings, then full results, then the rate-limited per-split-position backfill (capped at 40 event API calls/run — see [scaling.md](scaling.md)).

## Lactate Sheet sync: `fetch_lab_data.py`

**Current cron schedule: Monday 08:00**, using the **venv Python 3.11** interpreter specifically (`./venv/bin/python`, not `/bin/python3`) — see [ADR 0005](adr/0005-venv-python311.md). Reads the whole Google Sheet, upserts every row. Manual run (e.g. after a coach confirms they've updated the Sheet and don't want to wait for Monday):
```bash
cd /home/trailser/training-agent
./venv/bin/python fetch_lab_data.py
```

## Local race results sync: `fetch_local_results.py`

**Current cron schedule: Monday 08:10** — offset from `fetch_lab_data.py` (08:00) and `fetch_nat_tests.py` (08:05) for the same lock-free-SQLite-write reason (see [ADR 0007](adr/0007-limitations.md)); this entry was in fact missing from the crontab entirely until caught by a post-deploy audit, not present from day one the way the other two were. A second, independent Google Sheets sync (three result tabs: triathlon/duathlon/aquathlon), venv Python 3.11, upsert-only with an orphan-row warning printed on every run. Manual run:
```bash
cd /home/trailser/training-agent
./venv/bin/python fetch_local_results.py
```

## National functional tests sync: `fetch_nat_tests.py`

**Current cron schedule: Monday 08:05** — deliberately offset 5 minutes from `fetch_lab_data.py`'s 08:00 slot, since neither script takes a database lock and a same-instant SQLite write from both is possible in principle (see [ADR 0007](adr/0007-limitations.md)). Reads two tabs (`Протоколи`, `Функционални тестове`) via venv Python 3.11. Manual run:
```bash
cd /home/trailser/training-agent
./venv/bin/python fetch_nat_tests.py
```
**Locale gotcha specific to this Sheet:** it uses comma-decimal formatting (e.g. `"48,3"`), which `gspread`'s default record-fetching mangles into `483` instead of `48.3` unless read with `numericise_ignore=['all']` and parsed manually — see [data-model.md](data-model.md#nat_functional_tests--national-center-lab-step-test-results) for the full story. Any new script reading a comma-decimal Sheet needs the same treatment.

## Deploy procedure — two independent paths, easy to conflate

This is the single most important operational fact in this document: **there is no one "deploy."** Two completely separate mechanisms update two completely separate things.

### Path 1: PHP dashboard → `deploy.ps1` (run locally, from Windows)

```powershell
.\deploy.ps1
```

`scp`s the **local** `dashboard-backup/` directory (whatever's currently on disk, **regardless of git status** — uncommitted changes deploy too) to `RemotePath` in `deploy.config.psd1` (currently `/home/trailser/public_html/.../athlete-dashboard`), then explicitly `chmod`s everything to `644`/`755` (see [ADR 0007](adr/0007-limitations.md) — suPHP will 500 on group-writable files). This is what serves the live website.

**This path never touches `/home/trailser/training-agent`** (the git checkout cron reads from) **at all.**

### Path 2: Python cron scripts → `git pull` (run on the server, via SSH)

```bash
ssh trailser@argon.superhosting.bg -p 1022
cd /home/trailser/training-agent
git pull
./venv/bin/pip install -r requirements.txt -q   # if requirements.txt changed
```

This is what `main.py`, `fetch_world_triathlon.py`, `weekly_summary.py`, `fetch_lab_data.py`, and every module they import (`storage/`, `metrics/`, `alerts/`) actually run from. **Nothing automates this** — no post-push hook, no CI. It only happens when someone remembers to SSH in and pull.

### The trap

`dashboard-backup/` exists in **both** places — as a subdirectory of the git checkout at `/home/trailser/training-agent/dashboard-backup/` (stale unless someone `git pull`s) *and* as the deployed copy at `public_html/.../athlete-dashboard/` (stale unless someone runs `deploy.ps1`). Running one deploy path and assuming the other happened too is exactly how the server's cron-executed code fell 8 commits behind in practice (see [runbook.md](runbook.md) for the real incident and recovery). **After any change touching Python files, `git pull` on the server. After any change touching `dashboard-backup/`, run `deploy.ps1`. A change touching both needs both.**

## Local dev workflow

- **`seed_dev_data.py`** — generates 180 days of synthetic wellness/ACWR/ranking data for 3 hardcoded synthetic athletes directly into `data/agent.db` (idempotent for tables with a UNIQUE constraint; explicitly `DELETE`s `alert_events`/`world_triathlon` first since those don't have one suited to re-seeding). **Never run this against the production database.**
- **PHP dev server** — `cd dashboard-backup && php -S 127.0.0.1:PORT`. `includes/config.php:get_base_path()` auto-detects local vs. server by checking whether `/home/trailser/training-agent` exists as a directory — no environment variable or config flag needed, it Just Works on both.
- **Running the Python test scripts** — both are plain scripts (no pytest), run directly:
  ```bash
  python test_alert_system.py        # DB logic, uses a temp SQLite file, safe anywhere
  python test_lock_concurrency.py    # POSIX-only (fcntl) — SKIPPED with a message on Windows, meaningful only on the Linux server
  ```
- **Manual single-script testing against live APIs** requires the real API keys in `config/secrets.env` locally — most contributors won't have these and will only exercise the seeded-data + dashboard path.
