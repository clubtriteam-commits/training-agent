"""
Детекция на късно започнали тренировки — информативна аларма за видимост
на треньора, не тревожен сигнал.
"""
import sys
import os
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from storage.db import record_alert_event

# Тренировка, започнала СЛЕД този час (локално време), дава аларма
LATE_START_THRESHOLD = '18:30'


def analyze_late_starts(athlete_id, athlete_name, new_activities):
    """Проверява start_date_local на активностите спрямо LATE_START_THRESHOLD.
    Очаква списък, вече филтриран през storage.db.filter_new_activities().
    Връща списък аларми (и ги записва в alerts_log)."""
    threshold = tuple(int(part) for part in LATE_START_THRESHOLD.split(':'))
    alerts = []

    for activity in new_activities or []:
        start = activity.get('start_date_local') or ''
        # Очакван формат: 2026-07-17T19:15:00
        if 'T' not in start:
            continue
        activity_date, time_part = start.split('T', 1)
        try:
            hour, minute = int(time_part[0:2]), int(time_part[3:5])
        except ValueError:
            continue

        if (hour, minute) <= threshold:
            continue

        start_time = f"{hour:02d}:{minute:02d}"
        activity_name = activity.get('name') or 'тренировка'
        msg = (f"🌙 {athlete_name}: късна тренировка - {activity_name} "
               f"започна в {start_time} ({activity_date})")
        source_id = str(activity.get('id'))
        if record_alert_event(athlete_id, athlete_name, activity_date, 'late_start',
                               msg, source_id=source_id):
            alerts.append(msg)

    return alerts
