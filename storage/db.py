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
