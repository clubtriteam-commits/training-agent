# Glossary

## Резюме (Executive Summary)

Този документ обяснява всички специализирани термини, използвани в training-agent — тренировъчни метрики (ACWR, CTL, ATL), показатели за възстановяване (HRV, readiness) и терминология от лактатното тестване (LT1, LT2, зони). Ако не си спортен специалист, чети този файл първи — останалата документация приема, че тези термини са познати.

---

This glossary defines every domain term used across the codebase and documentation. Field names match the code exactly (SQLite columns, JSON keys) so you can grep for them.

## Training load

| Term | Meaning | Source | Code |
|---|---|---|---|
| **CTL** (Chronic Training Load) | "Fitness" — long-term training load, exponentially-weighted rolling average over ~42 days. Higher = fitter. | Computed by Intervals.icu, read via the wellness endpoint. | `daily_metrics.ctl` |
| **ATL** (Acute Training Load) | "Fatigue" — short-term training load, ~7-day rolling average. Higher = more recent fatigue. | Computed by Intervals.icu. | `daily_metrics.atl` |
| **ACWR** (Acute:Chronic Workload Ratio) | `ATL / CTL`. The core injury-risk signal in this system. | Computed locally in `metrics/acwr.py`. | `daily_metrics.acwr`, `.acwr_status` |
| **TSS** (Training Stress Score) | Per-workout load score combining intensity and duration. Not stored directly — CTL/ATL are its rolling averages. | Intervals.icu / device. | Only referenced in `seed_dev_data.py`'s synthetic data generator. |

### ACWR thresholds — two different bands, both intentional

There are **two separate ACWR thresholds** in this codebase, serving different purposes — this looks like an inconsistency at first glance but is a deliberate design choice:

- **Alerting threshold** (`metrics/acwr.py`): `< 0.8` = `low` (detraining), `> 1.5` = `high` (injury risk), `0.8–1.5` = `ok`. This is what actually fires Telegram alerts.
- **Visual "optimal" band** (`athlete.php` chart shading, `includes/metrics_glossary.php` glossary text): `0.8–1.3`. This is a *tighter*, coach-facing guideline shown on the chart as a shaded reference zone — "aim to stay in here," not "alert fires outside here."

If you're debugging "why didn't this ACWR value alert," check `metrics/acwr.py`'s `1.5` threshold, not the `1.3` band on the chart.

`seed_dev_data.py` (local dev fixture generator) uses yet a *third* value (`> 1.3` = high) in its own local `acwr_status()` helper — this only affects synthetic local dev data cosmetics, not production alerting logic.

## Readiness (recovery)

| Term | Meaning | Threshold used |
|---|---|---|
| **HRV** (Heart Rate Variability) | Variability between heartbeats; higher generally indicates better recovery/lower stress on the autonomic nervous system. | Alert fires if HRV drops >20% below a 7-day rolling baseline, for 2+ consecutive days. |
| **Resting HR** | Morning resting heart rate. Sustained elevation can signal fatigue or illness. | Displayed only, not currently alerted on. |
| **Sleep** | Hours of sleep per night (`sleep_secs / 3600`). | Alert fires if <7h for 2+ consecutive nights. |
| **Stress** | Device-reported stress score (0–100-ish scale; exact range depends on the athlete's connected device). | Alert fires if today's value is >10% above a 7-day baseline. See [known limitation](adr/0007-limitations.md) — this field is not reliably present for every athlete/device. |

## Lactate testing

| Term | Meaning |
|---|---|
| **Lactate step test** | Incremental exercise test: power increases in fixed steps (protocol-dependent), blood lactate (mmol/L) and heart rate are sampled at each step. |
| **Protocol** | `М` (male): starts at 80W, +40W per step. `Ж` (female): starts at 60W, +30W per step. Encoded in `lactate_step_watts()` (`includes/lactate_zones.php`). |
| **LT1** (first lactate threshold, aerobic threshold) | The power at which blood lactate first rises meaningfully above baseline — conventionally where the curve crosses **2.0 mmol/L**. Marks the top of "easy aerobic" training. |
| **LT2** (second lactate threshold, anaerobic threshold) | The power at which lactate accumulation becomes exponential — conventionally where the curve crosses **4.0 mmol/L**. Marks the boundary of sustainable "hard" effort. |
| **Estimated LT** | If a coach hasn't manually entered LT1/LT2 in the source Google Sheet, `api_lactate.php` computes it via linear interpolation between the two step points straddling the 2.0/4.0 mmol crossing. Flagged as `lt1_estimated`/`lt2_estimated: true` in the API response and shown as "(est.)" in the UI. |
| **5-zone model** | Derived entirely from LT1/LT2: Z1 Recovery (0–85% LT1), Z2 Endurance (85% LT1–LT1), Z3 Tempo (LT1–95% LT2), Z4 Threshold (95–105% LT2), Z5 VO2max+ (>105% LT2). Computed by `compute_zones()`. |
| **FTP** (Functional Threshold Power) | Estimated power sustainable for ~1 hour; entered separately in the lab data, not derived from the step test itself. |
| **W/kg** | FTP divided by body weight — the standard normalized power metric for comparing cyclists of different sizes. |

## National functional tests (НЦ)

A second, deliberately separate step-test protocol from the club's own lactate testing (see [Lactate testing](#lactate-testing) above) — see [features.md](features.md) and [data-model.md](data-model.md#nat_functional_tests--national-center-lab-step-test-results) for why the two are never compared directly.

| Term | Meaning |
|---|---|
| **W_max** | Maximum power reached during the incremental bike-ergometer test (watts). |
| **W_max/kg** | `W_max` divided by body weight — the standard metric for comparing cyclists of different sizes. |
| **VO2max** | Maximum oxygen consumption — the ceiling of aerobic capacity (mL/min). |
| **VO2max/kg** | `VO2max` divided by body weight — allows comparison across athletes of different mass. |
| **ЕПЗ** (Ефективна пулсова зона / Effective Heart-rate Zone) | The heart-rate range in which training produces the best aerobic effect, determined individually from the test. Shown as a range (e.g. `151–171`); has no single scalar value, so it never gets a computed delta — comparison tables always show `—` for it. |
| **La 2' / La 6' / La 15'** | Blood lactate level (mmol/L), sampled at 2, 6, and 15 minutes after the test ends — indicates recovery speed. `La 6'` (lower is better) is the one metric in this group that gets *inverted* before radar normalization — see below. |
| **АТМ** (Активна телесна маса / Active body mass) | Lean body mass — weight minus fat (muscle + bone + water). |
| **Разтег** (Wingspan) | Arm span, fingertip to fingertip — an anthropometric measurement. |

### Comparison table and radar rules (2026-07-26 redesign)

- **Δ общо** (total delta) is always `last test − first test` for that protocol, not consecutive-test deltas — a 3-test trend's delta column reflects the full span, not the most recent step.
- **Delta color** follows one explicit direction map (`$NAT_METRIC_DIRECTION` in `includes/nat_tests.php`): higher-is-better for load/VO2max/HR/lactate/muscle-% metrics (green ▲ / red ▼), but **тегло (weight) and ЕПЗ are always neutral/gray** regardless of direction — per explicit design requirement, not because their real delta is hidden (it's still shown, just not color-judged).
- **Radar normalization** is per-axis personal-best across *all* of that athlete's tests on that protocol, not just the two being compared, and not a hardcoded 100 for "the latest test." A metric can legitimately show <100% for the latest test if an earlier test happens to be the athlete's personal best on that specific axis (observed in production: an athlete's peak-lactate-recovery axis got *worse* over time even as power/VO2max improved, so their most recent radar polygon doesn't touch 100% on that one axis).
- A protocol with only 1 recorded test gets **no** delta, trend line, or radar overlay — shown as a standalone reference card instead, since a trend needs at least two points to exist.

## Race tracking

| Term | Meaning |
|---|---|
| **World Ranking / Regional Ranking** | Position in World Triathlon's global / regional (e.g. European) points ranking. Lower number = better. |
| **Splits** | Per-discipline times within a triathlon result: Swim, T1 (transition 1), Bike, T2, Run. |
| **Split position** | The athlete's rank *within a single discipline* for a given race, computed locally (`compute_split_positions()`) since the World Triathlon API doesn't provide it directly. |

## Alerts

| Term | Meaning |
|---|---|
| **alert_type** | The category of an alert event: `acwr_high`, `acwr_low`, `acwr_normalized`, `readiness_hrv`, `readiness_sleep`, `readiness_stress`, `comment_keyword`, `late_start`. See [data-model.md](data-model.md) for the full table schema. |
| **source_id** | Disambiguates multiple alerts of the same type/date/athlete (e.g. two flagged activities on the same day). Empty string for day-level alerts (ACWR, readiness) that are inherently one-per-day. |
| **Detection vs. delivery** | Two separate phases — see [ADR 0002](adr/0002-two-phase-detection-delivery.md). An alert can be *detected* (written to `alert_events`) without being *delivered* (sent to Telegram) yet. |
