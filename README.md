# training-agent

[![CI](https://github.com/clubtriteam-commits/training-agent/actions/workflows/ci.yml/badge.svg)](https://github.com/clubtriteam-commits/training-agent/actions/workflows/ci.yml)

## Резюме (Executive Summary)

Система за клубно триатлонско треньорство: автоматично тегли тренировъчни данни (Intervals.icu), състезателни резултати (World Triathlon + местни състезания), лабораторни тестове (клубни лактатни + национални функционални) и следи здравословни/тренировъчни рискове с Telegram аларми. PHP dashboard показва всичко на треньора. Пълната документация е в [`docs/`](docs/) — започни от [`docs/features.md`](docs/features.md), ако търсиш "какво прави системата", или от [`docs/workflows.md`](docs/workflows.md), ако търсиш "как да я пусна/deploy-на".

---

## What this is

A training-data pipeline and coaching dashboard for a small triathlon club: daily injury-risk monitoring (ACWR), race result tracking (World Triathlon API + a coach-maintained local-results Sheet), two independent lab-test streams (club lactate step tests + national-center functional tests), and Telegram alerts for health/training-load anomalies. A PHP dashboard (`dashboard-backup/`) is the coach-facing surface; Python cron jobs do the fetching/analysis into a single SQLite file.

## Where to start

- **What does it do?** → [`docs/features.md`](docs/features.md)
- **How is data laid out?** → [`docs/data-model.md`](docs/data-model.md)
- **How do I run it / deploy it?** → [`docs/workflows.md`](docs/workflows.md)
- **Something's broken — now what?** → [`docs/runbook.md`](docs/runbook.md)
- **Unfamiliar term (ACWR, LT1, W_max, ...)?** → [`docs/glossary.md`](docs/glossary.md)
- **Why was it built this way?** → [`docs/adr/`](docs/adr/README.md)
- **What changed and when?** → [`docs/CHANGELOG.md`](docs/CHANGELOG.md)
- **Security model / known risks?** → [`docs/security.md`](docs/security.md)
- **Known limitations of the hosting environment?** → [`docs/adr/0007-limitations.md`](docs/adr/0007-limitations.md)

## Quickstart

```bash
git clone https://github.com/clubtriteam-commits/training-agent.git
cd training-agent
pip install -r requirements.txt
```

Full setup (secrets, seeding local dev data, running the PHP dashboard locally) is in [`docs/workflows.md`](docs/workflows.md#quickstart--from-a-fresh-clone-to-a-running-local-dashboard).

## CI

Every push/PR to `main` runs [`.github/workflows/ci.yml`](.github/workflows/ci.yml): `php -l` over the dashboard PHP, plus the two Python test scripts (`test_alert_system.py`, `test_lock_concurrency.py`) on Python 3.11. See [`docs/workflows.md`](docs/workflows.md#continuous-integration-githubworkflowsciyml-added-2026-07-26) for what this does and doesn't guarantee (notably: it doesn't verify Python 3.6 compatibility, which is what production's cron jobs actually run on).
