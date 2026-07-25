# ADR 0004: `athlete_name` string joins instead of a unified athlete ID

## Резюме

Три различни системи (Intervals.icu, World Triathlon, Google Sheets) използват три различни начина да идентифицират един атлет. Вместо да строим централна таблица с атлети и техните ID-та, кодът просто join-ва по име (текст). Работи, докато никой не сгреши правописа на нечие име.

## Status

Accepted, with known debt (see [data-model.md](../data-model.md#the-athlete-id-problem) for the full picture).

## Context

`daily_metrics` and `alert_events` are keyed by Intervals.icu's athlete ID (`i######`) because that's what the wellness/activity APIs return. `world_triathlon` and `world_triathlon_results` are keyed by World Triathlon's numeric athlete ID because that's what *that* API returns. `lactate_tests`, `local_results`, and `nat_functional_tests` have no ID at all — each is a Google Sheet filled in by hand, and a hand-filled Sheet only ever has a person's name.

These three ID spaces have no overlap and no shared origin. `config/athletes.yaml` is manually maintained to map all three to one canonical name per athlete.

## Decision

Rather than introduce a fourth, canonical `athlete_id` and a lookup table, every cross-system query joins on `athlete_name` (a plain string) instead. E.g. `athlete.php`'s ranking query:

```sql
SELECT world_ranking FROM world_triathlon WHERE athlete_name = ?
```

This was the pragmatic choice for a 3-athlete deployment built incrementally — `world_triathlon` support and `lactate_tests` support were each added long after `daily_metrics` already existed and worked, and retrofitting a proper foreign-key model across already-populated production tables (with no migration tooling in place) was judged not worth it for the data volume involved.

## Consequences

**Positive:**
- Simple to reason about for 3 athletes — `config/athletes.yaml` is the map, and it's short enough to eyeball.
- No schema migration was needed each time a new data source was added (`world_triathlon`, then `lactate_tests`, then `local_results` and `nat_functional_tests`) — each new table just needed an `athlete_name` column, and every one of the four additions since the original decision has, in fact, taken this path rather than revisiting it.

**Negative / accepted trade-offs — this is the single biggest piece of technical debt in the project:**
- **Renaming an athlete breaks every historical join silently.** No error, no warning — old rows under the previous name simply stop appearing anywhere that joins by name.
- **A typo anywhere** (most commonly in the manually-edited Google Sheet, see [ADR 0003](0003-google-sheets-lab-source.md)) creates data that will never surface on the dashboard, with nothing flagging the mismatch. This has happened in production.
- **Doesn't scale past name uniqueness** — two athletes who happen to share a name (not implausible in a growing club) would have their data silently merged in every name-joined query. See [scaling.md](../scaling.md).
- `scripts/audit_data.py` includes an athlete-name-consistency check specifically because this failure mode has no other safety net.

**If revisited:** the fix is a proper `athletes` table (`id INTEGER PRIMARY KEY, name, intervals_id, world_triathlon_id`) with foreign keys from every other table, replacing `athlete_name` joins with `athlete_id` joins throughout. This is a real schema migration touching `storage/db.py`, every fetch script, and every PHP query.

**Status update (2026-07-25):** the "before adding a 4th data source" line above already aged out — `local_results` and `nat_functional_tests` both shipped the same day, bringing the count of `athlete_name`-only tables to three (`lactate_tests`, `local_results`, `nat_functional_tests`), on top of the two ID-keyed ones. The pattern keeps getting reused because it keeps being the fastest path to a working feature, which is exactly why it's debt rather than a one-off shortcut — each new Sheet-backed feature makes the eventual migration larger, not smaller. Worth doing before a 6th data source, not after.
