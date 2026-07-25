# Features

## Резюме (Executive Summary)

Системата има пет основни функционални области: (1) следене на тренировъчното натоварване за риск от травма, (2) следене на състезателните резултати и ранкинги — както международни (World Triathlon), така и местни български състезания, (3) автоматични здравни алерти при оплаквания или необичайни модели, (4) лабораторни данни — клубни лактатни тестове с визуален анализ, плюс национални функционални тестове (НЦ) с отделно проследяване (протоколите не са сравними едни с други), и (5) седмичен обобщителен отчет. Всяка работи независимо — спирането на една не чупи останалите.

---

## 1. Training Monitoring

**What it does:** tracks each athlete's Acute:Chronic Workload Ratio (ACWR) daily, alerting on status *transitions* (not on every day a value happens to be outside the safe zone — see [ADR 0001](adr/0001-alert-events-dedup.md)).

**Data flow:** `main.py` → `fetch_intervals.py:get_wellness()` (14-day rolling window) → `metrics/acwr.py:analyze_athlete_acwr()` → `daily_metrics` + `alert_events`.

**Alert types:**
- `acwr_high` — ACWR > 1.5. Fires regardless of whether the athlete is in a configured rest period (a spike *during* planned rest is a stronger signal, not a weaker one).
- `acwr_low` — ACWR < 0.8 (detraining). **Suppressed** if `config/athletes.yaml`'s `rest_period.from`/`.to` covers the event date — an expected dip during planned rest isn't alert-worthy.
- `acwr_normalized` — ACWR returns to the `0.8–1.5` band after having been `high` or `low`.

**Per-athlete configuration** (`config/athletes.yaml`):
```yaml
athletes:
  - name: "Athlete_A"
    intervals_id: "i000001"
    rest_period:
      from: "2026-08-01"
      to: "2026-08-14"
```

**Readiness sub-feature** (`metrics/readiness.py`, runs alongside ACWR): three independent checks against a rolling baseline —
- HRV drop >20% vs. 7-day baseline, sustained 2+ days (`readiness_hrv`)
- Sleep <7h, sustained 2+ nights (`readiness_sleep`)
- Stress >10% above 7-day baseline, today only (`readiness_stress` — see [known gap](adr/0007-limitations.md) re: `stress` field availability)

**Dashboard surface:** `athlete.php` — ACWR/CTL/ATL/HRV/sleep line charts (Chart.js, 30/90/180-day toggle), last-14-days table, full alert history.

## 2. Race Tracking

**What it does:** pulls World Triathlon rankings and race results, computing per-discipline (swim/bike/run) split positions locally since the API doesn't provide them.

**Data flow:** `fetch_world_triathlon.py` (3x/week for rankings, weekly for results) → `world_triathlon` / `world_triathlon_results`.

**Backfill safety:** the first time results are fetched for an athlete (`count_world_triathlon_results() == 0`), no Telegram alerts fire even though every result is technically "new" — otherwise onboarding an athlete with years of race history would flood Telegram with historical results.

**New result alerts:** `🏁 {name}: нов резултат от {event} ({date})` with position, total time, and per-discipline splits inline.

**Dashboard surface:** `athlete.php`'s "Резултати по година" (results by year) — year-filtered table, click-to-expand per-result split panel (Swim/T1/Bike/T2/Run times + computed positions), podium-colored position badges.

**Local (Bulgarian) races** — a separate, parallel source for domestic competitions that never reach the World Triathlon API: a coach-maintained Google Sheet (three result tabs — triathlon/duathlon/aquathlon — merged into one `local_results` table with discipline-agnostic `leg1`/`leg2`/`leg3` columns, since what each leg *means* varies by sport). Synced weekly by `fetch_local_results.py`, same upsert-only/orphan-check pattern as the lab-data scripts (see [ADR 0003](adr/0003-google-sheets-lab-source.md)). Rendered on `athlete.php` as its own "Местни състезания" section — visually mirrors the World Triathlon results table (year filter, expandable split panels, podium badges) but is a fully independent block, since the page's year-filter JavaScript only ever binds to the first `.year-nav` it finds.

## 3. Health Alerts

Two independent, activity-level checks, both deduplicated through the shared `seen_activities` ledger (`storage/db.py:filter_new_activities()`) so an activity is only ever scanned once regardless of how many times the 7-day fetch window re-covers it.

**Keyword detection** (`metrics/comment_alerts.py`): scans each new activity's title and description for pain/injury keywords (`config/keywords.yaml` — bilingual BG/EN root-word list, case-insensitive substring match, e.g. Bulgarian root `"бол"` catches "болка", "болеше", "болезнено"). Alert: `🩹 {name}: възможно оплакване в тренировка` with a truncated quote of the matched text and which keywords matched.

**Late-start detection** (`metrics/late_start.py`): flags any activity starting after 18:30 local time. Explicitly informational, not a health-risk signal — surfaces training-schedule patterns to the coach (e.g. "keeps training right before bed").

## 4. Lab Data (Club Lactate Testing + National Functional Tests)

**What it does:** syncs lactate step-test results from a coach-maintained Google Sheet, computes lactate thresholds (LT1/LT2) and a 5-zone training model, and renders both a summary table and a dedicated per-test analysis page.

**Data flow:** `fetch_lab_data.py` (weekly) → Google Sheets → `lactate_tests`. See [ADR 0003](adr/0003-google-sheets-lab-source.md) for the sync mechanics and its known fragility.

**`athlete.php` surface:** expandable per-test row — power/HR/lactate table (steps as columns), color-coded lactate badges (green <2 / amber 2–4 / red >4 mmol), LT1/LT2 column highlighting, a "📊 Анализ" link per test.

**`lactate_analysis.php` — dedicated analysis page** (client-rendered via `api_lactate.php`):
- Dual-axis chart: power (x) vs. HR (left y, blue) and lactate (right y, red), Chart.js + `chartjs-plugin-annotation`.
- 5 colored zone bands + dashed LT1 (green)/LT2 (red) threshold lines, computed server-side (`compute_zones()`), with "(est.)" labeling when a threshold wasn't manually entered and had to be linearly interpolated.
- **Test comparison ("Фаза 2"):** up to 2 additional tests overlaid on the same chart (dashed/dotted, lighter tints — zones/LT lines stay tied to the primary test only, to avoid visual noise from multiple thresholds). A comparison table below shows LT1/LT2/FTP/W-kg/HR-at-LT2/max-lactate per selected test plus a Δ (delta) column between the oldest and newest test shown, color-coded (higher = improvement for LT1/LT2/FTP/W-kg; lower = improvement for HR-at-LT2; no color judgment for max lactate — direction isn't unambiguous). Selection state lives in the URL (`?test_id=X&compare=Y,Z`) so a specific comparison is bookmarkable/shareable.

**National functional tests (НЦ)** — a second, deliberately *separate* lab-data stream for comprehensive physiological testing done at the national center (bike ergometer and treadmill protocols), synced weekly by `fetch_nat_tests.py` from its own Google Sheet into `nat_test_protocols` (protocol reference data) and `nat_functional_tests` (results). Kept apart from `lactate_tests` because the protocols genuinely aren't comparable: treadmill VO2max reads 5-10% higher than bike for the same athlete, and the national lab's bike protocol uses different step increments than the club's own. `includes/nat_tests.php:nat_tests_comparable()` is the single rule ("same protocol only") every chart is built through — `athlete.php`'s two trend charts (VO2max/kg, W_max/kg) render one series per protocol rather than one connected line, specifically so a protocol switch is never mistaken for a real physiological change. Tests are grouped by protocol in the summary table, each group showing the protocol's own parameters (device, step size, increment) pulled verbatim from `nat_test_protocols` so the description never drifts from what the lab recorded.

## 5. Reporting

**Weekly summary** (`weekly_summary.py`, Sunday 08:00, or Sunday 19:00 per the current crontab — see [workflows.md](workflows.md) for the authoritative schedule): sends **unconditionally**, unlike the daily alert flow — every athlete gets a digest regardless of whether anything alert-worthy happened, because the point is a weekly check-in, not just anomaly reporting.

Per athlete: average ACWR for the week (with rest-period-aware emoji suppression), CTL/ATL trend arrows (↗/↘/→), latest HRV/sleep if available, ranking (only if updated that week), and a count of alerts raised.
