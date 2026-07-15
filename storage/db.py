"""
SQLite storage за история на wellness/ACWR метрики по атлет.
"""
import sqlite3
import os

DB_PATH = os.path.join(os.path.dirname(__file__), '..', 'data', 'agent.db')


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
            fetched_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(athlete_id, event_id, prog_id)
        )
    ''')

    # Миграция за бази, създадени преди сплит колоните: CREATE IF NOT EXISTS
    # не добавя колони към съществуваща таблица, затова ALTER при липса.
    cur.execute("PRAGMA table_info(world_triathlon_results)")
    existing_cols = {row[1] for row in cur.fetchall()}
    for col in ('swim_split', 't1_split', 'bike_split', 't2_split', 'run_split'):
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


def log_alert(athlete_id, athlete_name, date, alert_type, message):
    conn = get_connection()
    cur = conn.cursor()
    cur.execute('''
        INSERT INTO alerts_log (athlete_id, athlete_name, date, alert_type, message)
        VALUES (?, ?, ?, ?, ?)
    ''', (athlete_id, athlete_name, date, alert_type, message))
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


def save_world_triathlon_ranking(athlete_id, athlete_name, world_ranking, regional_ranking):
    conn = get_connection()
    cur = conn.cursor()
    cur.execute('''
        INSERT INTO world_triathlon (athlete_id, athlete_name, world_ranking, regional_ranking)
        VALUES (?, ?, ?, ?)
    ''', (athlete_id, athlete_name, world_ranking, regional_ranking))
    conn.commit()
    conn.close()


if __name__ == '__main__':
    init_db()
