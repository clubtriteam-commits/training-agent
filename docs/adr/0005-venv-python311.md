# ADR 0005: Dedicated Python 3.11 venv for `gspread`/`google-auth`

## Резюме

Сървърът има само Python 3.6 системно инсталиран — твърде стар за библиотеките, нужни за четене на Google Sheets. Решението: отделно виртуално обкръжение с Python 3.11, инсталирано от CloudLinux "alt-python" пакетите, само за скриптовете, които имат нужда от него.

## Status

Accepted (session of 2026-07-22, server setup done via SSH).

## Context

The production host is shared hosting (SuperHosting.bg, CloudLinux). Its system `python3` is 3.6.8 — old enough that `gspread` and `google-auth` (needed for [ADR 0003](0003-google-sheets-lab-source.md)'s Sheets sync) either fail to install or fail at import time. All pre-existing cron jobs (`main.py`, `fetch_world_triathlon.py`, `weekly_summary.py`) were written against 3.6 and work fine on it — only the new `fetch_lab_data.py` needed something newer.

## Decision

CloudLinux hosts often ship parallel-installable "alt-python" packages (`/opt/alt/python311/bin/python3.11` etc., up to 3.13 available) specifically for this scenario. Rather than upgrade the system Python (out of the tenant's control on shared hosting, and risky for the existing 3.6-tested scripts), a dedicated venv was created:

```
/opt/alt/python311/bin/python3.11 -m venv /home/trailser/training-agent/venv
```

Only `fetch_lab_data.py`'s cron entry uses it (`./venv/bin/python fetch_lab_data.py`); every other cron job keeps using system `/bin/python3` (3.6) unchanged. `requirements.txt` was introduced at the same time (previously nonexistent — dependencies were whatever happened to already be installed system-wide).

## Consequences

**Positive:**
- Zero risk to already-working 3.6-based cron jobs — nothing about them changed.
- `requirements.txt` now exists at all, which it didn't before this — a small but real improvement to reproducibility.

**Negative / accepted trade-offs:**
- **Two Python versions now run in production**, which is easy to forget when debugging — running a script manually via `python3 script.py` instead of `./venv/bin/python script.py` on the server silently uses the wrong interpreter and may `ImportError` or, worse, run with a different (older, differently-behaved) stdlib.
- `requirements.txt` isn't enforced anywhere for the *other* scripts (`requests`, `PyYAML`, `python-dotenv` for the 3.6-based jobs are just "whatever's on the system") — the file only accurately describes what the venv needs, not the whole project's dependency surface. See [scaling.md](../scaling.md) for the broader shared-hosting constraint this sits inside.
- If a future script needs *both* something only available in 3.6's environment and something requiring 3.11+, this split becomes a real problem rather than a minor inconvenience.
