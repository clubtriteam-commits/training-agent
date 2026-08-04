"""
SQLite storage за история на wellness/ACWR метрики по атлет.
"""
import sqlite3
import os
import json

DB_PATH = os.path.join(os.path.dirname(__file__), '..', 'data', 'agent.db')

# Изнесено в константа: ползва се и от init_db(), и от mark_activity_seen(),
# за да работи детекцията на коментари и върху база, на която init_db()
# не е пускан след добавянето на таблицата.
SEEN_ACTIVITIES_SCHEMA = '''
    CREATE TABLE IF NOT EXISTS seen_activities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        athlete_id TEXT NOT NULL,
        activity_id TEXT NOT NULL,
        checked_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(athlete_id, activity_id)
    )
'''


def get_connection():
    os.makedirs(os.path.dirname(DB_PATH), exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn


def init_db():
    conn = get_connection()
    cur = conn.cursor()

    cur.execute('''
        CREATE TABLE IF NOT EXISTS daily_metrics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            athlete_id TEXT NOT NULL,
            athlete_name TEXT NOT NULL,
            date TEXT NOT NULL,
            ctl REAL,
            atl REAL,
            acwr REAL,
            acwr_status TEXT,
            hrv REAL,
            sleep_secs INTEGER,
            stress REAL,
            resting_hr REAL,
            fetched_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(athlete_id, date)
        )
    ''')

    # Архивна таблица от старата (pre-alert_events) dedup система. Никой код
    # вече не пише тук — пазим я само защото stored production историята
    # преди миграцията (виж migrate_alerts.py).
    cur.execute('''
        CREATE TABLE IF NOT EXISTS alerts_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            athlete_id TEXT NOT NULL,
            athlete_name TEXT NOT NULL,
            date TEXT NOT NULL,
            alert_type TEXT NOT NULL,
            message TEXT NOT NULL,
            sent_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ''')

    # "Събитието се създава веднъж, неизменно е, доставя се отделно."
    # source_id различава множество аларми от един и същ тип/дата/атлет
    # (напр. две активности с ключови думи в един ден) — NOT NULL DEFAULT ''
    # нарочно, не NULL: SQLite третира NULL като различен от всеки друг NULL
    # в UNIQUE индекс, така че NULL там би развалило dedup-а за ACWR/readiness
    # (които винаги ползват source_id = '').
    cur.execute('''
        CREATE TABLE IF NOT EXISTS alert_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            athlete_id TEXT NOT NULL,
            athlete_name TEXT NOT NULL,
            event_date TEXT NOT NULL,
            alert_type TEXT NOT NULL,
            message TEXT NOT NULL,
            source_id TEXT NOT NULL DEFAULT '',
            detected_at TEXT DEFAULT CURRENT_TIMESTAMP,
            delivered_at TEXT,
            UNIQUE(athlete_id, event_date, alert_type, source_id)
        )
    ''')

    cur.execute('''
        CREATE TABLE IF NOT EXISTS world_triathlon (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            athlete_id TEXT NOT NULL,
            athlete_name TEXT NOT NULL,
            world_ranking INTEGER,
            regional_ranking INTEGER,
            fetched_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(athlete_id, fetched_at)
        )
    ''')

    # Пълни резултати от get_athlete_results(). UNIQUE включва и prog_id:
    # едно събитие може да даде няколко резултата за атлет (полуфинал +
    # финал, индивидуално + щафета), така че event_id сам не стига.
    # position е TEXT — API-то връща и "DNF"/"DSQ"/"LAP" освен числа.
    # *_position колоните са TEXT заради формата за равни времена ("=3").
    # positions_computed_at маркира, че event results endpoint-ът е викан
    # за този резултат (дори когато не е дал позиции) — за rate limits.
    cur.execute('''
        CREATE TABLE IF NOT EXISTS world_triathlon_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            athlete_id TEXT NOT NULL,
            athlete_name TEXT NOT NULL,
            event_id INTEGER NOT NULL,
            prog_id INTEGER NOT NULL DEFAULT 0,
            event_date TEXT,
            event_title TEXT,
            position TEXT,
            total_time TEXT,
            event_country TEXT,
            swim_split TEXT,
            t1_split TEXT,
            bike_split TEXT,
            t2_split TEXT,
            run_split TEXT,
            swim_position TEXT,
            t1_position TEXT,
            bike_position TEXT,
            t2_position TEXT,
            run_position TEXT,
            positions_computed_at TEXT,
            fetched_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(athlete_id, event_id, prog_id)
        )
    ''')

    # Едно измерване на лактатен тест на стъпка: до 10 стъпки, HR/La могат
    # да липсват за стъпки, до които атлетът не е стигнал (NULL, не 0 —
    # 0 би изглеждал като реално измерена стойност в графиките).
    step_cols = ', '.join(
        f'step{i}_hr REAL, step{i}_la REAL' for i in range(1, 11)
    )
    cur.execute(f'''
        CREATE TABLE IF NOT EXISTS lactate_tests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            test_date TEXT NOT NULL,
            athlete_name TEXT NOT NULL,
            protocol TEXT,
            height_cm REAL,
            weight_kg REAL,
            age INTEGER,
            ftp REAL,
            w_kg REAL,
            lactate_start REAL,
            hr_start REAL,
            {step_cols},
            lt1_w REAL,
            lt2_w REAL,
            notes TEXT,
            synced_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(athlete_name, test_date)
        )
    ''')

    # Национални функционални тестове (НЦ) — отделно от lactate_tests, защото
    # протоколите (вело срещу тредбанд, различни стъпки от клубния протокол)
    # не са пряко сравними; виж includes/nat_tests.php:nat_tests_comparable().
    cur.execute('''
        CREATE TABLE IF NOT EXISTS nat_test_protocols (
            protocol      TEXT PRIMARY KEY,
            device        TEXT,
            start_value   TEXT,
            increment     TEXT,
            step_minutes  REAL,
            incline       TEXT,
            metric        TEXT,
            lab           TEXT,
            note          TEXT,
            synced_at     TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ''')

    # UNIQUE е тройка (athlete_name, test_date, protocol), не двойка — вело и
    # тредбанд може да са в един и същи ден (случи се за целия отбор през
    # април 2026), затова само (athlete_name, test_date) би загубил единия ред.
    cur.execute('''
        CREATE TABLE IF NOT EXISTS nat_functional_tests (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            athlete_name  TEXT NOT NULL,
            test_date     TEXT NOT NULL,
            protocol      TEXT NOT NULL,
            device        TEXT,
            lab           TEXT,
            height_cm REAL, arm_span_cm REAL, weight_kg REAL, lean_mass_kg REAL,
            fat_pct REAL, fat_kg REAL, muscle_pct REAL, muscle_kg REAL,
            duration_min  REAL,
            w_max REAL, w_max_kg REAL,
            s_max_kmh REAL,
            vo2max REAL, vo2max_kg REAL, hr_max INTEGER,
            epz_from INTEGER, epz_to INTEGER,
            la_2 REAL, la_6 REAL, la_15 REAL, hr_2 INTEGER, hr_6 INTEGER,
            synced_at     TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(athlete_name, test_date, protocol)
        )
    ''')
    cur.execute('CREATE INDEX IF NOT EXISTS idx_nat_tests_name ON nat_functional_tests(athlete_name)')

    # HR/power zone снимка на активност, от Intervals.icu's get_activities()
    # (вика се вече дневно за keyword/late-start — тук просто извличаме
    # допълнителни полета от същия отговор, без нова API заявка).
    # Границите (*_zones_json) и времената (*_zone_times_json) се пазят
    # отделно, snapshot-нати НА активността, не веднъж на атлет — зоните
    # се менят с времето (нов FTP/max HR), а искаме старите тренировки да
    # пазят интерпретацията, валидна към датата им.
    # JSON, не фиксирани zone_1..zone_N колони: броят зони е конфигурируем
    # per athlete/sport в Intervals.icu (разузнаването видя 7 HR зони и
    # 8 power зони — Z1-Z7 + "SS" sweet-spot — за един атлет; няма
    # гаранция, че е еднакво за всички).
    # power_zone_times_json пази icu_zone_times точно както идва от API-то
    # (списък от {"id": "Z1", "secs": ...} обекти) — форматът е различен
    # от hr_zone_times_json (плосък позиционен масив от секунди), защото
    # такъв е и в самия Intervals.icu отговор.
    cur.execute('''
        CREATE TABLE IF NOT EXISTS activity_zones (
            id                      INTEGER PRIMARY KEY AUTOINCREMENT,
            athlete_id              TEXT NOT NULL,
            athlete_name            TEXT NOT NULL,
            activity_id             TEXT NOT NULL,
            activity_date           TEXT NOT NULL,
            activity_type           TEXT,
            has_power               INTEGER NOT NULL DEFAULT 0,
            hr_zones_json           TEXT,
            hr_zone_times_json      TEXT,
            power_zones_json        TEXT,
            power_zone_times_json   TEXT,
            icu_average_watts       REAL,
            icu_weighted_avg_watts  REAL,
            icu_ftp                 REAL,
            fetched_at              TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(athlete_id, activity_id)
        )
    ''')
    cur.execute('CREATE INDEX IF NOT EXISTS idx_activity_zones_athlete ON activity_zones(athlete_id)')

    cur.execute(SEEN_ACTIVITIES_SCHEMA)

    # Миграция за бази, създадени преди сплит колоните: CREATE IF NOT EXISTS
    # не добавя колони към съществуваща таблица, затова ALTER при липса.
    cur.execute("PRAGMA table_info(world_triathlon_results)")
    existing_cols = {row[1] for row in cur.fetchall()}
    for col in ('swim_split', 't1_split', 'bike_split', 't2_split', 'run_split',
                'swim_position', 't1_position', 'bike_position', 't2_position',
                'run_position', 'positions_computed_at'):
        if col not in existing_cols:
            cur.execute(f"ALTER TABLE world_triathlon_results ADD COLUMN {col} TEXT")

    conn.commit()
    conn.close()
    print(f"DB initialized at {DB_PATH}")


def upsert_daily_metric(athlete_id, athlete_name, date, ctl=None, atl=None,
                         acwr=None, acwr_status=None, hrv=None,
                         sleep_secs=None, stress=None, resting_hr=None):
    conn = get_connection()
    cur = conn.cursor()

    cur.execute('''
        INSERT INTO daily_metrics
            (athlete_id, athlete_name, date, ctl, atl, acwr, acwr_status,
             hrv, sleep_secs, stress, resting_hr)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(athlete_id, date) DO UPDATE SET
            ctl=excluded.ctl,
            atl=excluded.atl,
            acwr=excluded.acwr,
            acwr_status=excluded.acwr_status,
            hrv=excluded.hrv,
            sleep_secs=excluded.sleep_secs,
            stress=excluded.stress,
            resting_hr=excluded.resting_hr,
            fetched_at=CURRENT_TIMESTAMP
    ''', (athlete_id, athlete_name, date, ctl, atl, acwr, acwr_status,
          hrv, sleep_secs, stress, resting_hr))

    conn.commit()
    conn.close()


def get_previous_status(athlete_id, before_date):
    conn = get_connection()
    cur = conn.cursor()
    cur.execute('''
        SELECT acwr_status FROM daily_metrics
        WHERE athlete_id = ? AND date < ?
        ORDER BY date DESC LIMIT 1
    ''', (athlete_id, before_date))
    row = cur.fetchone()
    conn.close()
    return row['acwr_status'] if row else None


def record_alert_event(athlete_id, athlete_name, event_date, alert_type, message, source_id=''):
    """Записва аларма-събитие ЕДНОКРАТНО. Връща True само ако редът е нов.

    Дедупликацията е гарантирана от UNIQUE(athlete_id, event_date, alert_type,
    source_id) constraint-а на базата (INSERT OR IGNORE), не от код тук —
    едно повторно преизчисление на същия ден/активност никога не създава
    втори ред, дори при конкурентни извиквания."""
    conn = get_connection()
    cur = conn.cursor()
    cur.execute('''
        INSERT OR IGNORE INTO alert_events
            (athlete_id, athlete_name, event_date, alert_type, message, source_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ''', (athlete_id, athlete_name, event_date, alert_type, message, source_id))
    is_new = cur.rowcount > 0
    conn.commit()
    conn.close()
    return is_new


def get_undelivered_events():
    conn = get_connection()
    cur = conn.cursor()
    cur.execute('''
        SELECT * FROM alert_events
        WHERE delivered_at IS NULL
        ORDER BY detected_at ASC
    ''')
    rows = cur.fetchall()
    conn.close()
    return rows


def mark_delivered(event_id):
    conn = get_connection()
    cur = conn.cursor()
    cur.execute('''
        UPDATE alert_events SET delivered_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ''', (event_id,))
    conn.commit()
    conn.close()


def upsert_world_triathlon_result(athlete_id, athlete_name, event_id, prog_id,
                                  event_date=None, event_title=None, position=None,
                                  total_time=None, event_country=None,
                                  swim_split=None, t1_split=None, bike_split=None,
                                  t2_split=None, run_split=None):
    """Insert/refresh един резултат; повторно пускане не дублира записи.

    Връща True, когато редът е НОВ (не е съществувал преди тази
    синхронизация) — на това стъпва Telegram детекцията за нови резултати.
    """
    conn = get_connection()
    cur = conn.cursor()

    cur.execute('''
        SELECT 1 FROM world_triathlon_results
        WHERE athlete_id = ? AND event_id = ? AND prog_id = ?
    ''', (athlete_id, event_id, prog_id))
    is_new = cur.fetchone() is None

    cur.execute('''
        INSERT INTO world_triathlon_results
            (athlete_id, athlete_name, event_id, prog_id, event_date,
             event_title, position, total_time, event_country,
             swim_split, t1_split, bike_split, t2_split, run_split)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(athlete_id, event_id, prog_id) DO UPDATE SET
            event_date=excluded.event_date,
            event_title=excluded.event_title,
            position=excluded.position,
            total_time=excluded.total_time,
            event_country=excluded.event_country,
            swim_split=excluded.swim_split,
            t1_split=excluded.t1_split,
            bike_split=excluded.bike_split,
            t2_split=excluded.t2_split,
            run_split=excluded.run_split,
            fetched_at=CURRENT_TIMESTAMP
    ''', (athlete_id, athlete_name, event_id, prog_id, event_date,
          event_title, position, total_time, event_country,
          swim_split, t1_split, bike_split, t2_split, run_split))
    conn.commit()
    conn.close()
    return is_new


def get_results_needing_positions():
    """Резултати, за които event results endpoint-ът още не е викан.

    Маркерът е positions_computed_at, а не самите позиции: резултат без
    splits в event листинга остава с NULL позиции завинаги и не искаме
    да го преизчисляваме (= нова API заявка) при всяко пускане.
    """
    conn = get_connection()
    cur = conn.cursor()
    cur.execute('''
        SELECT athlete_id, athlete_name, event_id, prog_id
        FROM world_triathlon_results
        WHERE positions_computed_at IS NULL
        ORDER BY event_date DESC
    ''')
    rows = cur.fetchall()
    conn.close()
    return rows


def save_result_positions(athlete_id, event_id, prog_id, positions):
    """Записва per-split позициите и маркира резултата като обработен.

    positions може да е празен dict (без данни за събитието) — пак
    маркираме, за да не повтаряме заявката при следващите пускания.
    """
    conn = get_connection()
    cur = conn.cursor()
    cur.execute('''
        UPDATE world_triathlon_results
        SET swim_position = ?, t1_position = ?, bike_position = ?,
            t2_position = ?, run_position = ?,
            positions_computed_at = CURRENT_TIMESTAMP
        WHERE athlete_id = ? AND event_id = ? AND prog_id = ?
    ''', (positions.get('swim_position'), positions.get('t1_position'),
          positions.get('bike_position'), positions.get('t2_position'),
          positions.get('run_position'), athlete_id, event_id, prog_id))
    conn.commit()
    conn.close()


def count_world_triathlon_results(athlete_id):
    """Брой записани резултати за атлет — 0 означава първо (инициално)
    зареждане, при което не пращаме аларми за всеки исторически резултат."""
    conn = get_connection()
    cur = conn.cursor()
    cur.execute('SELECT COUNT(*) AS n FROM world_triathlon_results WHERE athlete_id = ?',
                (athlete_id,))
    n = cur.fetchone()['n']
    conn.close()
    return n


def mark_activity_seen(athlete_id, activity_id):
    """Отбелязва активност като проверена; връща True само ако е НОВА
    (не е била виждана преди) — на това стъпва детекцията на коментари."""
    conn = get_connection()
    cur = conn.cursor()
    cur.execute(SEEN_ACTIVITIES_SCHEMA)
    cur.execute('''
        INSERT OR IGNORE INTO seen_activities (athlete_id, activity_id)
        VALUES (?, ?)
    ''', (str(athlete_id), str(activity_id)))
    is_new = cur.rowcount > 0
    conn.commit()
    conn.close()
    return is_new


def filter_new_activities(athlete_id, activities_list):
    """Връща само невиждани досега активности и ги отбелязва като видени.
    Единствената входна точка за дедупликация: всички проверки върху нови
    активности (keywords, late start, ...) трябва да получават резултата
    от тази функция, а не да викат mark_activity_seen() поотделно."""
    return [
        a for a in (activities_list or [])
        if a.get('id') is not None and mark_activity_seen(athlete_id, a['id'])
    ]


def upsert_activity_zones(athlete_id, athlete_name, activity):
    """Извлича HR/power zone полетата от една Intervals.icu активност
    (както идва директно от get_activities()) и ги upsert-ва.

    Прескача активности без никакви zone данни (напр. плуване без пулс —
    някои атлети нямат HR източник за плуване; за тях се гледа нетно
    време в спорта, не zone разбивка тук, така че празен ред би бил шум).
    Идемпотентно по (athlete_id, activity_id) — безопасно да се вика за
    ВСЯКА върната активност всеки ден, не само нововидените, защото
    презаписва коректно ако данните в Intervals.icu се сменят по-късно."""
    activity_id = activity.get('id')
    if activity_id is None:
        return

    hr_zones = activity.get('icu_hr_zones')
    hr_zone_times = activity.get('icu_hr_zone_times')
    power_zones = activity.get('icu_power_zones')
    power_zone_times = activity.get('icu_zone_times')
    avg_watts = activity.get('icu_average_watts')
    weighted_avg_watts = activity.get('icu_weighted_avg_watts')
    ftp = activity.get('icu_ftp')

    has_power = power_zone_times is not None or avg_watts is not None
    if not has_power and hr_zone_times is None:
        return

    conn = get_connection()
    cur = conn.cursor()
    cur.execute('''
        INSERT INTO activity_zones
            (athlete_id, athlete_name, activity_id, activity_date, activity_type,
             has_power, hr_zones_json, hr_zone_times_json,
             power_zones_json, power_zone_times_json,
             icu_average_watts, icu_weighted_avg_watts, icu_ftp)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(athlete_id, activity_id) DO UPDATE SET
            athlete_name=excluded.athlete_name,
            activity_date=excluded.activity_date,
            activity_type=excluded.activity_type,
            has_power=excluded.has_power,
            hr_zones_json=excluded.hr_zones_json,
            hr_zone_times_json=excluded.hr_zone_times_json,
            power_zones_json=excluded.power_zones_json,
            power_zone_times_json=excluded.power_zone_times_json,
            icu_average_watts=excluded.icu_average_watts,
            icu_weighted_avg_watts=excluded.icu_weighted_avg_watts,
            icu_ftp=excluded.icu_ftp,
            fetched_at=CURRENT_TIMESTAMP
    ''', (
        str(athlete_id), athlete_name, str(activity_id),
        (activity.get('start_date_local') or '')[:10],
        activity.get('type'),
        1 if has_power else 0,
        json.dumps(hr_zones) if hr_zones is not None else None,
        json.dumps(hr_zone_times) if hr_zone_times is not None else None,
        json.dumps(power_zones) if power_zones is not None else None,
        json.dumps(power_zone_times) if power_zone_times is not None else None,
        avg_watts, weighted_avg_watts, ftp,
    ))
    conn.commit()
    conn.close()


def save_world_triathlon_ranking(athlete_id, athlete_name, world_ranking, regional_ranking):
    conn = get_connection()
    cur = conn.cursor()
    cur.execute('''
        INSERT INTO world_triathlon (athlete_id, athlete_name, world_ranking, regional_ranking)
        VALUES (?, ?, ?, ?)
    ''', (athlete_id, athlete_name, world_ranking, regional_ranking))
    conn.commit()
    conn.close()


def upsert_lactate_test(test_date, athlete_name, protocol=None, height_cm=None,
                         weight_kg=None, age=None, ftp=None, w_kg=None,
                         lactate_start=None, hr_start=None, steps_hr=None,
                         steps_la=None, lt1_w=None, lt2_w=None, notes=None):
    """Insert/refresh лактатен тест по (athlete_name, test_date).

    steps_hr/steps_la са списъци до 10 стойности (None за липсваща стъпка) —
    подават се позиционно, стъпка 1 на индекс 0."""
    steps_hr = (steps_hr or []) + [None] * (10 - len(steps_hr or []))
    steps_la = (steps_la or []) + [None] * (10 - len(steps_la or []))
    step_names = [f'step{i}_hr' for i in range(1, 11)] + [f'step{i}_la' for i in range(1, 11)]
    step_values = steps_hr[:10] + steps_la[:10]

    conn = get_connection()
    cur = conn.cursor()
    cur.execute(f'''
        INSERT INTO lactate_tests
            (test_date, athlete_name, protocol, height_cm, weight_kg, age,
             ftp, w_kg, lactate_start, hr_start, {', '.join(step_names)},
             lt1_w, lt2_w, notes)
        VALUES ({', '.join(['?'] * (13 + len(step_names)))})
        ON CONFLICT(athlete_name, test_date) DO UPDATE SET
            protocol=excluded.protocol,
            height_cm=excluded.height_cm,
            weight_kg=excluded.weight_kg,
            age=excluded.age,
            ftp=excluded.ftp,
            w_kg=excluded.w_kg,
            lactate_start=excluded.lactate_start,
            hr_start=excluded.hr_start,
            {', '.join(f'{n}=excluded.{n}' for n in step_names)},
            lt1_w=excluded.lt1_w,
            lt2_w=excluded.lt2_w,
            notes=excluded.notes,
            synced_at=CURRENT_TIMESTAMP
    ''', (test_date, athlete_name, protocol, height_cm, weight_kg, age,
          ftp, w_kg, lactate_start, hr_start, *step_values, lt1_w, lt2_w, notes))
    conn.commit()
    conn.close()


if __name__ == '__main__':
    init_db()
