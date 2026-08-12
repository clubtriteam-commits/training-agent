# Spec: `race_courses`

> Status: **draft, awaiting approval** (Фаза 1). `docs/spec_race_evaluations.md`, посочен като шаблон, не съществува в repo-то (нито в текущото дърво, нито в git историята) — този документ следва формата на `docs/data-model.md` за схемата и стила на `docs/adr/0003-google-sheets-lab-source.md` за Sheet-sync разсъжденията, вместо да имитира несъществуващ файл.

## 1. Цел

Треньорът поддържа ръчно събрани данни за курса на всяко състезание (дължини на етапите, брой обиколки, тип старт, настилка, бележки) в Google Sheet. Целта на тази функция е тези данни да влязат в SQLite базата през същия read-only sync модел като останалите Sheet-източници, и да се покажат до резултата на атлета в `athlete.php`, за да може той/треньорът да види контекста на курса (не само времето) при преглед на резултат.

Обхват точно сега: 6-те международни (World Triathlon) състезания от сезон 2026, за които има данни в `kursove_import.csv`. Не покрива местните ("ДП") състезания — те нямат курс-данни в момента и не е поискано.

## 2. Източник

- **Sheet ID:** същият като локалните резултати — `LOCAL_RESULTS_SHEET_ID` (`config/secrets.env`), нов таб в него, **не** нов Sheet.
- **Таб:** `Курсове`
- **Service account / достъп:** същият read-only service account като `fetch_local_results.py`/`fetch_lab_data.py` (`config/google-service-account.json`, `spreadsheets.readonly` scope) — по прецедента от [ADR 0003](adr/0003-google-sheets-lab-source.md): sync скрипт не може технически да повреди Sheet-а, само да чете.
- **Интерпретатор:** venv Python 3.11 (`./venv/bin/python fetch_race_courses.py`), както `fetch_local_results.py` — заради `gspread`/`google-auth`.

### Колона → поле (от реалния `kursove_import.csv`, header ред + 6 реда данни)

| Sheet колона | `race_courses` поле | Тип | Пример от файла |
|---|---|---|---|
| `event_id` | `event_id` | TEXT | *(празно в оригиналния CSV — виж §5, отворен въпрос)* |
| `Дата` | `date` | TEXT (ISO) | `2026-05-30` |
| `Състезание` | *(не се persist-ва директно — виж §5)* | — | `РК за развитие Мамая` |
| `Дистанция` | `distance_type` | TEXT | `спринт`, `суперспринт` |
| `Водоем` | `water_body` | TEXT | `море (Черно море, Casino Beach)` |
| `Тип старт` | `start_type` | TEXT | `beach`, `deep water`, `понтон`, `неуточнено` |
| `Плуване_м` | `swim_m` | INTEGER | `750`, `400` |
| `Плуване_обиколки` | `swim_laps` | INTEGER | `1`, `2` |
| `Т_вода_C` | `water_temp` | **TEXT** | `21-23`, `~27`, *(празно)* |
| `Swim_T1_м` | `swim_t1_m` | INTEGER | `430`, *(празно)* |
| `Вело_км` | `bike_km` | REAL | `19.8`, `20`, `11` |
| `Вело_обиколки` | `bike_laps` | INTEGER | `6`, `4`, `3`, `5`, `2` |
| `Вело_профил` | `bike_profile` | TEXT | свободен текст, вкл. бележки за завои/трафик |
| `Движение` | `traffic_side` | TEXT | `дясно`, `ЛЯВО (British-style)` |
| `Бягане_км` | `run_km` | REAL | `5`, `2.5` |
| `Бягане_обиколки` | `run_laps` | INTEGER | `4`, `3`, `2` |
| `Настилка` | `run_surface` | TEXT | `асфалт`, `променада (неуточнено)`, `неуточнено` |
| `Пунктове` | `aid_stations` | TEXT | свободен текст (не структурирано число — "1 на обиколка, само вода") |
| `Старт_час` | `start_times` | TEXT | `Ж 08:00 / М 09:30` (два часа в едно поле, различни start-вълни по пол) |
| `Бележки_треньор` | `coach_notes` | TEXT | свободен многоредов текст |

Забележка: `Дистанция` в тази таблица е WT-специфична дума (спринт/суперспринт), **различна** от `local_results.distance` (винаги `NULL` за триатлон там) и различна семантика от `WT_EVENT_DISTANCE` в `dashboard-backup/includes/wt_event_meta.php` (event-ниво "Sprint"/"Super Sprint" от WT API-то) — двете би трябвало да съвпадат смислово за тези 6 събития, но идват от различни източници (треньорска преценка срещу WT API категория) и не се cross-validate автоматично в тази спека. Ако искаш такава проверка, е малко разширение на §6.

## 3. Схема

```sql
CREATE TABLE IF NOT EXISTS race_courses (
    event_id        TEXT PRIMARY KEY,
    date            TEXT,
    event_title     TEXT,
    distance_type   TEXT,
    water_body      TEXT,
    start_type      TEXT,
    swim_m          INTEGER,
    swim_laps       INTEGER,
    water_temp      TEXT,
    swim_t1_m       INTEGER,
    bike_km         REAL,
    bike_laps       INTEGER,
    bike_profile    TEXT,
    traffic_side    TEXT,
    run_km          REAL,
    run_laps        INTEGER,
    run_surface     TEXT,
    aid_stations    TEXT,
    start_times     TEXT,
    coach_notes     TEXT,
    synced_at       TEXT DEFAULT CURRENT_TIMESTAMP
);
```

| Column | Type | Notes |
|---|---|---|
| `event_id` | TEXT PRIMARY KEY | Виж §5 — предложено: числовият World Triathlon `event_id` (напр. `195430`), **не** `local_events`-стил slug. |
| `date` | TEXT | ISO `YYYY-MM-DD`, от Sheet-а директно. |
| `event_title` | TEXT, nullable | **Не се чете от Sheet-а.** Auto-fill при sync чрез `SELECT event_title FROM world_triathlon_results WHERE event_id = ?` — вижда се дали `event_id`-то реално резолвва. `NULL`, ако не намери съвпадение (= сигнал за проблем, виж §6). |
| `distance_type` | TEXT | `спринт` / `суперспринт`, свободен текст от Sheet-а (не enum на DB ниво). |
| `water_body`, `start_type`, `bike_profile`, `traffic_side`, `run_surface`, `aid_stations`, `start_times`, `coach_notes` | TEXT, nullable | Свободен текст от Sheet-а, без валидация на съдържанието — същия компромис като лактатните тестове (ADR 0003). |
| `swim_m`, `swim_laps`, `swim_t1_m`, `bike_laps`, `run_laps` | INTEGER, nullable | |
| `bike_km`, `run_km` | REAL, nullable | |
| `water_temp` | **TEXT**, nullable | Умишлено не число — Sheet-ът има диапазони (`21-23`) и приблизителни стойности (`~27`), не само точни градуси. |
| `synced_at` | TEXT (auto) | |

**Unique constraint:** `event_id` (самата PRIMARY KEY).
**Update frequency:** ръчно пускане след редакция на Sheet-а (виж Фаза 5, т.5 — **не** cron засега).
**Written by:** `fetch_race_courses.py:sync_courses()`.
**Read by:** `athlete.php` (split-panel "Курс" секция при разгънат резултат — Фаза 4).

## 4. Нормализации

- **Десетични разделители:** `bike_km`/`run_km` през `str(v).replace(',', '.')` преди `float()` — треньорът може да въведе `19,8`. Ако Sheet-ът вече връща `19.8` (какъвто е случаят в текущия CSV), заместването е no-op, безопасно е винаги да се прилага.
- **Празна клетка → `NULL`, никога `0` или `""`** — важно конкретно за `bike_laps`/`swim_laps`/etc., защото `0` обиколки е различно твърдение от "не е въведено". Същия принцип като `lactate_tests` стъпките (виж CLAUDE-ниво коментара в `storage/db.py`).
- **`water_temp` остава TEXT без опит за parse** — не се опитва `21-23` да се разбие на min/max при sync; ако някога потрябва числово сравнение, това е отделно бъдещо разширение, не част от тази спека.
- **`aid_stations`/`start_times`/`coach_notes`/`bike_profile`:** без нормализация — свободен текст, presented as-is (с `htmlspecialchars()` на PHP страната, не на sync страната).
- **Header matching:** точно съвпадение на български имена на колони, по прецедента на `fetch_local_results.py`. Няма fallback за преименувана колона в тази версия (ADR 0003 отбелязва, че точно това е чупило `fetch_lab_data.py` веднъж мълчаливо — приемаме същия риск съзнателно, не добавяме defensive header-matching за 6 реда данни, освен ако не поискаш).

## 5. Join логика — **отворен въпрос, нужно е решение преди Фаза 2**

Проверих: табът "Състезания" (`local_events`), към който насочва Фаза 0, съдържа **локална slug схема** за `event_id` (документирано в `docs/data-model.md`: *"A slug, not a numeric ID (e.g. `dp-sprint-plovdiv-2026`) — assigned in the Sheet by whoever enters the event, not by any external system"*) — това е за българските "ДП" състезания, не за World Triathlon състезания.

Шестте състезания в `kursove_import.csv` обаче са именно WT състезания, вече в `world_triathlon_results` с числов WT `event_id`. Проверих реалната база — датите съвпадат точно 1:1:

| Дата (CSV) | `event_title` (EN, от базата) | `world_triathlon_results.event_id` | Sheet `Състезание` (българско, само за референция) |
|---|---|---|---|
| 2026-05-30 | 2026 World Triathlon Development Regional Cup Mamaia | `195430` | РК за развитие Мамая |
| 2026-06-13 | 2026 Europe Triathlon Balkan Championships Ohrid | `195382` | Балканско първенство Охрид |
| 2026-06-27 | 2026 World Triathlon Development Regional Cup Gallipoli | `195395` | РК за развитие Галиполи |
| 2026-07-18 | 2026 Europe Triathlon Cup Tata | `195339` | ETC Тата |
| 2026-07-18 | 2026 Europe Triathlon Junior Cup Izvorani | `195201` | ETC Junior Извораи |
| 2026-07-26 | 2026 World Triathlon Development Regional Cup Vlasina Lake | `195396` | РК за развитие Власина |

(Забелязах и потвърдих съвпадението, което CSV бележката за Тата/Извораи вече намекваше — двете състезания са в **същия ден**, 2026-07-18, различни `event_id`.)

**Предложение:** `race_courses.event_id` = числовият WT `event_id` от тази таблица, **не** slug като в `local_events`. Ти попълваш тези числа в Sheet-а (Фаза 0), таблицата по-горе е готова напаст за copy-paste. `event_title` в `race_courses` **не** се въвежда ръчно — sync скриптът го попълва автоматично от `world_triathlon_results` по `event_id`, за да служи като вграден "сверих ли правилното число" сигнал.

**Fallback (само за audit, не за основния join):** ако бъдещ `event_id` в Sheet-а е грешен/празен, `fetch_race_courses.py` може да опита да го отгатне по `date` ±1 ден (WT API-то понякога има разминаване от ден между `event_date` и реалната дата на старта, наблюдавано вече другаде в проекта) — но само **логва предупреждение с предложение**, никога не пренаписва `event_id` автоматично. Точното съвпадение по `Състезание` (българско) срещу `event_title` (английско) не е технически възможно като string match — затова fallback-ът е по дата, не по заглавие.

**Нуждая се от твоето потвърждение по това предложение**, преди да пиша `fetch_race_courses.py` — конкретно: съгласен ли си `event_id` да е числовият WT ID (таблицата по-горе), а не slug?

## 6. Validation заявка (за `scripts/audit_data.py`)

Нова проверка, по стила на съществуващата "6. Referential integrity" (`check_new_tables_referential_integrity`):

```python
def check_race_courses_coverage(conn, athletes, report):
    entries = report.section("7. race_courses coverage")
    cur = conn.cursor()
    names = {a['name'] for a in athletes}

    # А) резултат на наш атлет от 2026 без съответен курс
    cur.execute("""
        SELECT DISTINCT event_id, event_title, event_date, athlete_name
        FROM world_triathlon_results
        WHERE event_date LIKE '2026%'
    """)
    known_courses = {row['event_id'] for row in
                      conn.execute("SELECT event_id FROM race_courses").fetchall()}
    for row in cur.fetchall():
        if row['athlete_name'] not in names:
            continue
        if row['event_id'] not in known_courses:
            entries.append(
                f"world_triathlon_results: event_id '{row['event_id']}' "
                f"({row['event_title']}, {row['event_date']}, athlete={row['athlete_name']}) "
                f"няма ред в race_courses"
            )

    # Б) курс, чийто event_id не резолвва към нищо в world_triathlon_results
    #    (== event_title остана NULL при sync, виж §3)
    cur.execute("SELECT event_id, date FROM race_courses WHERE event_title IS NULL")
    for row in cur.fetchall():
        entries.append(
            f"race_courses: event_id '{row['event_id']}' (date={row['date']}) "
            f"няма съответен ред в world_triathlon_results — грешен event_id?"
        )
```

Извиква се от `main()` до другите проверки, номерирана като "7." (следваща след съществуващата "6. Referential integrity").

## 7. Dashboard surface (кратко — детайлно проектиране във Фаза 4)

В `athlete.php`, вътре в съществуващия `result-detail`/`split-panel` блок на "Резултати по година" (виж текущия код около `find_evaluation_for_date()`/`eval_panel_html()` — същия expand панел, където вече се показва `conditions-line` за температура/wetsuit от тази сесия): ако `race_courses` има ред за резултатовия `event_id`, нова секция "Курс" — 3 колони (Плуване/Вело/Бягане: дистанция + обиколки + старт-тип/настилка/профил съответно), `coach_notes` под тях. Празни полета не се рендерират (не "неуточнено" placeholder). Без нови JS библиотеки — чист PHP/CSS като съществуващите split панели.
