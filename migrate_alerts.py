"""
Еднократна миграция: копира съществуващите alerts_log записи в alert_events.

alerts_log остава в базата като read-only архив (никой код вече не пише
там) — тази миграция само пренася историята напред, за да могат
dashboard.php/athlete.php и weekly_summary.py да четат от новата таблица.

Дубликатите в alerts_log (същия athlete_id/date/alert_type, логнати
многократно от старата, счупена dedup логика) се компресират автоматично
до по един ред от UNIQUE constraint-а на alert_events + INSERT OR IGNORE —
редовете се обхождат по sent_at ASC, така че първият (най-ранният) сет от
всяка комбинация печели.

Забележка: alerts_log няма activity_id колона, затова мигрираните
comment_keyword/late_start редове получават source_id='' — ако е имало
две различни активности от стария бъг за един ден/тип, миграцията ги
компресира в един ред. Приемливо за еднократна миграция на архивни данни;
новите записи (през record_alert_event) вече носят истинския source_id.

    python migrate_alerts.py
"""
import sys
import os
sys.path.append(os.path.dirname(os.path.abspath(__file__)))

from storage.db import get_connection, init_db


def migrate():
    init_db()
    conn = get_connection()
    cur = conn.cursor()

    cur.execute('''
        SELECT athlete_id, athlete_name, date, alert_type, message, sent_at
        FROM alerts_log
        ORDER BY sent_at ASC
    ''')
    rows = cur.fetchall()

    inserted = 0
    for row in rows:
        cur.execute('''
            INSERT OR IGNORE INTO alert_events
                (athlete_id, athlete_name, event_date, alert_type, message,
                 source_id, detected_at, delivered_at)
            VALUES (?, ?, ?, ?, ?, '', ?, ?)
        ''', (row['athlete_id'], row['athlete_name'], row['date'], row['alert_type'],
              row['message'], row['sent_at'], row['sent_at']))
        inserted += cur.rowcount

    conn.commit()
    conn.close()

    skipped = len(rows) - inserted
    print(f"Мигрирани {inserted} нови event-а от {len(rows)} alerts_log записа "
          f"({skipped} прескочени като дубликати).")


if __name__ == '__main__':
    migrate()
