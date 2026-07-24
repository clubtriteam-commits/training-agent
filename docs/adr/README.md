# Architecture Decision Records

## Резюме (Executive Summary)

Тук са записани най-важните архитектурни решения в проекта — не само *какво* е направено, но и *защо*, какви алтернативи са обмислени, и какви компромиси идват с всяко решение. Ако питаш "защо е направено така, а не по-просто?" — отговорът вероятно е тук.

---

Each ADR captures a decision that would otherwise only live in a commit message or a Slack conversation. Format: Context → Decision → Consequences (including the trade-offs we accepted knowingly).

| ADR | Title |
|---|---|
| [0001](0001-alert-events-dedup.md) | `alert_events` UNIQUE constraint as the deduplication mechanism |
| [0002](0002-two-phase-detection-delivery.md) | Two-phase detection/delivery for alerts |
| [0003](0003-google-sheets-lab-source.md) | Google Sheets as the lab data source of truth |
| [0004](0004-athlete-name-joins.md) | `athlete_name` string joins instead of a unified athlete ID |
| [0005](0005-venv-python311.md) | Dedicated Python 3.11 venv for `gspread`/`google-auth` |
| [0006](0006-session-cookie-lifetime.md) | Session cookie lifetime bootstrapped from the login page |
| [0007](0007-limitations.md) | Known limitations (not decisions — constraints we live with) |
