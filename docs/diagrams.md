# Diagrams

## Резюме (Executive Summary)

Три диаграми: (1) откъде идват данните и как стигат до потребителя, (2) къде физически живее всяка част от системата (лаптоп, GitHub, сървър, външни услуги), и (3) какво се случва стъпка по стъпка от откриване на проблем до Telegram съобщение.

---

## 1. Data flow

```mermaid
flowchart LR
    subgraph Sources["External data sources"]
        INT["Intervals.icu API<br/>(wellness + activities)"]
        WT["World Triathlon API<br/>(rankings + results)"]
        SHEET["Google Sheet<br/>(lactate tests, hand-entered)"]
    end

    subgraph Fetch["Fetch / detect scripts (Python, cron)"]
        MAIN["main.py<br/>daily 20:00"]
        FWT["fetch_world_triathlon.py<br/>Mon 10/12/18:00"]
        FLD["fetch_lab_data.py<br/>Mon 08:00, venv py3.11"]
        WEEK["weekly_summary.py<br/>Sun 19:00"]
    end

    DB[("SQLite<br/>data/agent.db")]

    subgraph PHP["PHP dashboard (reads DB directly)"]
        DASH["dashboard.php"]
        ATH["athlete.php"]
        API["api_lactate.php<br/>(JSON)"]
        LAC["lactate_analysis.php<br/>(fetch()es api_lactate.php)"]
    end

    TG["Telegram<br/>(coach's chat)"]
    USER["Coach<br/>(browser)"]

    INT --> MAIN
    WT --> FWT
    SHEET --> FLD

    MAIN -->|"daily_metrics, alert_events"| DB
    FWT -->|"world_triathlon,<br/>world_triathlon_results"| DB
    FLD -->|"lactate_tests"| DB
    WEEK -->|"reads daily_metrics,<br/>world_triathlon, alert_events"| DB

    MAIN -->|"deliver_pending_alerts()"| TG
    WEEK -->|"send_alerts_batch()"| TG
    FWT -->|"new race result"| TG

    DB --> DASH --> USER
    DB --> ATH --> USER
    DB --> API
    API --> LAC --> USER
    USER -->|"login"| DASH
```

## 2. Infrastructure

```mermaid
flowchart TB
    subgraph LOCAL["Local Windows dev machine"]
        REPO["training-agent/<br/>(git working copy)"]
        DEPLOYPS["deploy.ps1"]
    end

    GH["GitHub<br/>clubtriteam-commits/training-agent"]

    subgraph SERVER["SuperHosting.bg shared hosting (CloudLinux)"]
        subgraph CRONCOPY["/home/trailser/training-agent<br/>(git checkout — cron reads THIS)"]
            PYCODE["main.py, fetch_*.py,<br/>storage/, metrics/, alerts/"]
            VENV["venv/ — Python 3.11<br/>(gspread, google-auth)"]
            SYSPY["system Python 3.6.8<br/>(everything else)"]
            SQLITEDB[("data/agent.db")]
        end
        subgraph WEBROOT["public_html/.../athlete-dashboard<br/>(deploy.ps1 target — Apache/PHP serves THIS)"]
            PHPCODE["dashboard-backup/* contents<br/>(athlete.php, api_lactate.php, ...)"]
        end
        CRON["system crontab"]
        WAF["Edge WAF<br/>(blocks default curl UA)"]
    end

    EXT1["Intervals.icu API"]
    EXT2["World Triathlon API"]
    EXT3["Google Sheets API"]
    TG["Telegram Bot API"]
    BROWSER["Coach's browser"]

    REPO -->|"git push"| GH
    GH -->|"git pull (manual, via SSH)"| CRONCOPY
    REPO -->|"deploy.ps1: scp + chmod<br/>(local disk, any git status)"| WEBROOT

    CRON --> PYCODE
    PYCODE --> SQLITEDB
    PYCODE -->|"venv/bin/python for fetch_lab_data.py"| VENV
    PYCODE -->|"/bin/python3 for everything else"| SYSPY
    PYCODE --> EXT1
    PYCODE --> EXT2
    PYCODE --> EXT3
    PYCODE --> TG

    PHPCODE -->|"PDO sqlite: (same file, direct read)"| SQLITEDB
    BROWSER -->|"HTTPS"| WAF --> PHPCODE
```

## 3. Alert lifecycle

```mermaid
sequenceDiagram
    participant M as main.py
    participant D as metrics/* (detection)
    participant DB as alert_events (SQLite)
    participant T as Telegram

    Note over M,D: Phase 1 — Detection (every run, re-scans rolling window)
    M->>D: analyze_athlete_acwr() / analyze_readiness() / ...
    D->>DB: record_alert_event(athlete_id, event_date, alert_type, message, source_id)
    alt UNIQUE(athlete_id, event_date, alert_type, source_id) already exists
        DB-->>D: INSERT OR IGNORE — no-op, returns False
        Note over D: Already known — not re-added to this run's alert list
    else new combination
        DB-->>D: row inserted, delivered_at = NULL, returns True
        Note over D: New — added to this run's alert list
    end

    Note over M,T: Phase 2 — Delivery (runs after ALL detection is done)
    M->>DB: get_undelivered_events() — WHERE delivered_at IS NULL
    DB-->>M: pending events, oldest detected_at first
    loop each pending event
        M->>T: send_telegram_message(event.message)
        alt HTTP 200
            T-->>M: success
            M->>DB: mark_delivered(event.id)
        else failure (network, 429 flood control, etc.)
            T-->>M: failure
            Note over DB: delivered_at stays NULL —<br/>retried automatically next run, no data lost
        end
    end
```
