# Runbook

## Резюме (Executive Summary)

Този документ е "какво правя, ако..." справочник, изграден от реални инциденти в историята на проекта — не хипотетични сценарии. За всеки: как изглежда проблемът, как се диагностицира, и как се оправя. Дръж този файл под ръка, когато нещо не работи.

---

## Login loop / dashboard asks for password repeatedly

**Symptoms:** user logs in successfully (redirected to `dashboard.php`), but a subsequent visit — sometimes minutes later, sometimes the next day — bounces back to the login form despite the session supposedly lasting 30 days.

**Diagnosis:**
1. Check the actual `Set-Cookie` header on the login response itself:
   ```bash
   curl -s -A "Mozilla/5.0 ..." -D - -d "password=..." https://<host>/index.php -o /dev/null | grep -i set-cookie
   ```
   (Browser-like User-Agent required — see the WAF note below.) Look for `Max-Age=2592000` (30 days). If it's missing or much shorter, the cookie was created without the 30-day config.
2. Check whether the file handling the request calls `session_start()` **before** including `includes/auth.php`, or calls it directly without going through `auth.php` at all. This was the exact root cause fixed in [ADR 0006](adr/0006-session-cookie-lifetime.md) — `index.php` and `logout.php` both had this bug historically.

**Fix:** any new PHP entry point must `require_once 'includes/auth.php';` **before** any session-related code of its own, never call `session_start()` directly. If you find a page that does, that's the bug — reroute it through `auth.php`.

## Dashboard access requires no password at all (opposite failure mode)

**Symptoms:** `dashboard.php` (or any page) loads without ever prompting for a password, for a browser session that never logged in.

**Diagnosis:** check `dashboard-backup/includes/auth.php`'s `require_login()` function for an early `return;` before the actual session check — this is a known, intentionally-inserted testing bypass (see [security.md](security.md)) that has been left active in production more than once. Confirm from outside the app: `curl` any protected page with no cookie — `200` instead of a `302` redirect to `index.php` confirms it's active.

**Fix:** remove the `return;` line, redeploy via `deploy.ps1`. Double-check `api_lactate.php` still enforces its own independent session check regardless (it does, by design — see [security.md](security.md)).

## Telegram silent — alerts detected but never arrive

**Symptoms:** `logs/cron.log` shows alerts being detected (`N нови аларми открити днес`) but nothing arrives in the Telegram chat.

**Diagnosis, in order:**
1. Check `TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` are actually set in `config/secrets.env` on the **server** (not just locally) — `alerts/notifier_telegram.py:send_telegram_message()` prints `⚠️ Липсва TELEGRAM_BOT_TOKEN или TELEGRAM_CHAT_ID` and returns `False` silently otherwise; this line is easy to miss in a long cron log.
2. Check `main.py`'s `DAILY_TELEGRAM_ALERTS` constant — if `False`, alerts are detected and marked delivered *without* actually sending, by design (see [ADR 0002](adr/0002-two-phase-detection-delivery.md)). This looks identical to a delivery failure in the log unless you check the flag itself.
3. Query for undelivered events directly:
   ```python
   from storage.db import get_undelivered_events
   print(len(get_undelivered_events()))
   ```
   A nonzero count with `DAILY_TELEGRAM_ALERTS = True` means real delivery failures — check `logs/cron.log` for `Telegram грешка: <status> - <body>` lines from `send_telegram_message()`. A `429` means flood control (see [scaling.md](scaling.md)); anything else is likely a bad/revoked bot token or chat ID.

**Fix:** correct the config value or token; no manual re-queue needed — the next `main.py` run automatically retries every `delivered_at IS NULL` row.

## Cron didn't run — `logs/` directory missing

**Symptoms:** no `cron.log` entries at all for a scheduled run, or the cron redirect (`>> .../logs/cron.log`) itself silently fails.

**Diagnosis:** `git` does not track empty directories — a fresh clone, or a `.gitignore` change that newly excludes `logs/`, can leave the directory simply absent. Every entry-point script (`main.py`, `fetch_world_triathlon.py`, `weekly_summary.py`) already defends against this with `os.makedirs('logs', exist_ok=True)` at import time specifically because this has happened before — but only for `logs/` itself; if the *cron user* lacks write permission to the parent directory, even this silently fails (the redirect operator `>>` in the crontab entry fails at the shell level, before Python ever runs, so no Python-side defense can catch it).

**Fix:** `mkdir -p /home/trailser/training-agent/logs` manually via SSH, verify ownership matches the cron-executing user. Re-run the affected script manually to confirm logging resumes.

## Dashboard 500 — file permissions (644 vs 664)

**Symptoms:** a page that works fine locally (`php -S`) returns a `500` on production immediately after a manual file edit/upload (i.e., not through `deploy.ps1`).

**Diagnosis:** this hosting's suPHP-style setup refuses to execute group-writable PHP files (see [ADR 0007](adr/0007-limitations.md)). A file created or edited via SFTP/some editors defaults to `664` instead of `644`. Check:
```bash
ls -la public_html/.../athlete-dashboard/*.php
```
Any file not `644` (or any directory not `755`) is a candidate.

**Fix:** `chmod 644` the file (`755` for directories), or just re-run `deploy.ps1` — it unconditionally re-applies correct permissions to everything on every deploy, which is why this class of incident essentially never happens when `deploy.ps1` is the only way files reach the server.

**Related historical incident:** production `error_log` for this vhost has a real entry — `PHP Parse error: syntax error, unexpected '[', expecting ')' in athlete.php on line 15` (14-Jul-2026). This specific error is a **syntax**, not permissions, issue — consistent with a stray manual edit reaching the server outside `deploy.ps1` in a broken state (short-array-syntax-adjacent typo, or a PHP version mismatch on whatever interpreter handled that particular request). Recorded here because it's the concrete evidence a "dashboard 500" incident has, in fact, already happened in this project — not because the exact root cause was re-diagnosed for this document. If a similar parse error recurs: `php -l <file>` locally before any deploy would have caught it; this is now standard practice for every PHP edit in this project going forward.

## Sheet sync fails — tab renamed / orphan rows

**Symptoms:** `fetch_lab_data.py` runs without error but a coach reports lactate data is missing, or the counts look wrong (see [ADR 0003](adr/0003-google-sheets-lab-source.md) for the underlying fragility).

**Diagnosis:**
1. **Wrong/renamed tab:** the script looks for a worksheet literally named `Лактатни тестове` (`WORKSHEET_NAME` in `fetch_lab_data.py`). If a coach renamed the tab (has happened — it defaulted back to Google's auto-generated `Лист1` after a sheet recreation), the script raises `gspread.exceptions.WorksheetNotFound` — this is loud, not silent, so check the cron log first.
2. **Wrong/renamed header column** (e.g. `Протокол` → `Sex`) — this is the silent failure mode: no exception, but the mapped field comes back `NULL` for every row. Compare the Sheet's actual header row against the fallback names `fetch_lab_data.py` tries.
3. **Orphan rows:** a row deleted from the Sheet after having been synced once stays in `lactate_tests` forever — the sync only upserts, never deletes. Run `scripts/audit_data.py` to list rows in `lactate_tests` whose `(athlete_name, test_date)` no longer appears in a fresh Sheet read.

**Fix:** for (1)/(2), add the new header/tab name as another fallback in `fetch_lab_data.py` and re-run; for (3), manually `DELETE FROM lactate_tests WHERE ...` — on **both** the local dev DB and the production DB if the orphan reached production, since these are two entirely separate SQLite files (see [workflows.md](workflows.md)'s deploy-path split).

## Crontab accidentally overwritten — restore procedure

**Symptoms:** scheduled jobs stop running entirely; `crontab -l` shows fewer entries than expected, or none.

**How this actually happened once:** an inline SSH command of the shape `(crontab -l; echo "new line") | crontab -` was run to *append* a single new cron entry — but a quoting/escaping mismatch in the surrounding shell command caused `crontab -l` (the "read existing entries" half) to return empty, so the pipe replaced the entire crontab with just the one new line. This was caught immediately by re-running `crontab -l` right after and noticing only 1 line where ~6 were expected.

**Fix — the safe pattern, used ever since:**
1. Capture the current crontab to a local file *first*, verified non-empty: `crontab -l > crontab_backup.txt` (or reconstruct it from a previous known-good copy if it's already gone).
2. `scp` that file to the server.
3. Install it directly, with no shell pipe involved: `crontab crontab_backup.txt`.
4. Verify: `crontab -l` and count the lines.

**Never** modify a live crontab via an inline `(crontab -l; echo ...) | crontab -` one-liner over SSH — the extra shell-quoting layer (local shell → SSH → remote shell) is exactly what caused the original incident. Always go through an intermediate file.

## Git divergence between server and local

**Symptoms:** a fix that's clearly in the repo (checked on GitHub, or working locally) doesn't seem to take effect in production's cron-executed behavior — even though `deploy.ps1` was run and the PHP dashboard *does* show the fix.

**How this actually happened:** `deploy.ps1` only ever touches `dashboard-backup/` → `public_html/.../athlete-dashboard`. It has no knowledge of, and never touches, `/home/trailser/training-agent` — the **separate** git checkout that cron actually executes Python from (see [workflows.md](workflows.md)'s "two independent paths" section). Several commits (adding a header-matching fallback in `fetch_lab_data.py`, an entire lactate-analysis feature, a session-cookie fix, a float-serialization fix) were pushed to GitHub and deployed to the PHP side via `deploy.ps1`, but the server's `/home/trailser/training-agent` checkout sat 8 commits behind for some time, silently running the old Python code on every cron fire.

**Diagnosis:**
```bash
ssh <host>
cd /home/trailser/training-agent
git log -1 --oneline
git fetch && git log HEAD..origin/main --oneline   # anything listed here hasn't reached the server yet
```

**Fix:**
```bash
git pull
./venv/bin/pip install -r requirements.txt -q   # in case dependencies changed too
```

**Prevention:** there is currently no automation for this — no post-push hook, no CI step. After any commit touching a `.py` file, `git pull` on the server is a manual, easy-to-forget step, distinct from (and in addition to) running `deploy.ps1` for PHP changes. Treat "did I pull the server" as a standing checklist item alongside "did I deploy," not a step deploy.ps1 already covers.
