# ADR 0003: Google Sheets as the lab data source of truth

## Резюме

Лактатните тестове се въвеждат ръчно от лаборанти в Google Sheet (не в специализиран софтуер), защото това е инструментът, който вече ползват. Скрипт синхронизира Sheet-а с базата веднъж седмично. Компромисът: няма validation на въведените данни, а заглавията на колоните могат да се сменят без предупреждение.

## Status

Accepted (commit `f9a3668`, header-matching hardened in `ed1ba46`/`5f02239`).

## Context

Lab staff conducting lactate step tests already work in spreadsheets — building a dedicated data-entry UI was out of scope for the value it would add over "a Sheet with agreed column headers." The requirement was to get that data into the same SQLite database the rest of the dashboard reads from, without asking lab staff to change their workflow.

## Decision

- A Google Sheet, shared with a dedicated service account (`training-agent-sheets-reader@...`), holds one row per test with a fixed 33-column header row.
- `fetch_lab_data.py` runs weekly (Monday 08:00), reads the whole sheet via `gspread`, and **upserts** every row into `lactate_tests` keyed on `(athlete_name, test_date)`.
- The service account uses **read-only** scope (`spreadsheets.readonly`) for the routine sync — the script has no ability to write back to the Sheet, by design (least privilege; a sync bug can't corrupt the source data).
- Header matching is defensive by necessity: the `Протокол` column has already been renamed twice in production (`"Протокол"` → `"Протокол (М/Ж)"` → `"Sex"`) without the sheet owner realizing it broke the sync silently (protocol landed as `NULL`, which cascades into missing wattage-per-step everywhere). `fetch_lab_data.py` now tries three header name variants in order.

## Consequences

**Positive:**
- Zero training overhead for lab staff — they keep using a spreadsheet.
- Read-only sync scope means a bug in the Python side can never damage the source-of-truth Sheet.

**Negative / accepted trade-offs:**
- **No data validation at entry.** A lab tech can type `241` (an FTP value) into a lactate column by mistake — and this has happened in production (see the `Стъпка10_La=241` incident, matched a nearby FTP value exactly, almost certainly a copy-paste error). Nothing catches this automatically; a human has to notice an implausible number. `scripts/audit_data.py`'s step-continuity check catches *some* of this class of error (e.g. a step with `La` but no `HR` after the test was supposedly stopped earlier) but not implausible-but-structurally-valid values.
- **Header renames break the sync silently** — no error is raised, affected fields just come back `NULL`. There is no automated check that the Sheet's header row still matches what `fetch_lab_data.py` expects; a coach renaming a column is invisible until someone notices missing data on the dashboard.
- **Deleted Sheet rows leave orphans in SQLite.** The sync only upserts what's *currently* in the Sheet — it never deletes local rows that disappeared from the source. This already happened once (a test entered under the wrong athlete name, later deleted from the Sheet, requiring a manual `DELETE FROM lactate_tests` on both the local and production databases). `scripts/audit_data.py` flags orphan rows for this reason, but doesn't auto-clean them.
- **`athlete_name` is the only join key** (see [ADR 0004](0004-athlete-name-joins.md)) — a typo'd name in the Sheet creates a test that will never show up on any athlete's dashboard page, with no error anywhere.
