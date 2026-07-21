"""
Readiness метрики - HRV, сън, стрес спрямо rolling baseline.
Прагове (конфигурируеми):
- HRV пад >20% спрямо 7-дневна база, за 2+ последователни дни
- Сън <7ч, за 2+ последователни нощи
- Стрес >10% над baseline
"""
import sys
import os
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from storage.db import get_connection, record_alert_event


def get_recent_wellness(athlete_id, days=14):
    """Взима последните N дни wellness данни от базата (за baseline изчисление)"""
    conn = get_connection()
    cur = conn.cursor()
    cur.execute('''
        SELECT date, hrv, sleep_secs, stress, resting_hr
        FROM daily_metrics
        WHERE athlete_id = ?
        ORDER BY date DESC
        LIMIT ?
    ''', (athlete_id, days))
    rows = cur.fetchall()
    conn.close()
    return [dict(row) for row in rows]


def calculate_baseline(values):
    """Проста средна стойност, игнорирайки None"""
    clean = [v for v in values if v is not None]
    if not clean:
        return None
    return sum(clean) / len(clean)


def check_sleep_alert(wellness_list, threshold_hours=7, consecutive_nights=2):
    """
    wellness_list: най-новите данни първи (descending date)
    Връща (bool, message) - True ако последните N нощи са под прага
    """
    if len(wellness_list) < consecutive_nights:
        return False, None

    recent = wellness_list[:consecutive_nights]
    all_low = True

    for day in recent:
        sleep_secs = day.get('sleep_secs')
        if sleep_secs is None:
            return False, None  # няма данни, не можем да преценим
        hours = sleep_secs / 3600
        if hours >= threshold_hours:
            all_low = False
            break

    if all_low:
        return True, f"😴 Сън под {threshold_hours}ч за {consecutive_nights}+ последователни нощи"

    return False, None


def check_hrv_alert(wellness_list, drop_percent=20, consecutive_days=2, baseline_days=7):
    """
    Сравнява последните N дни HRV спрямо baseline (средна от предходните дни)
    """
    if len(wellness_list) < consecutive_days + baseline_days:
        return False, None

    recent = wellness_list[:consecutive_days]
    baseline_source = wellness_list[consecutive_days:consecutive_days + baseline_days]

    baseline_hrv = calculate_baseline([d.get('hrv') for d in baseline_source])

    if baseline_hrv is None:
        return False, None

    all_dropped = True
    for day in recent:
        hrv = day.get('hrv')
        if hrv is None:
            return False, None
        if hrv >= baseline_hrv * (1 - drop_percent / 100):
            all_dropped = False
            break

    if all_dropped:
        return True, f"📉 HRV пад >{drop_percent}% спрямо базата за {consecutive_days}+ дни"

    return False, None


def check_stress_alert(wellness_list, above_percent=10, baseline_days=7):
    """Проверява дали днешния стрес е над baseline с above_percent%"""
    if len(wellness_list) < baseline_days + 1:
        return False, None

    today = wellness_list[0]
    baseline_source = wellness_list[1:baseline_days + 1]

    today_stress = today.get('stress')
    baseline_stress = calculate_baseline([d.get('stress') for d in baseline_source])

    if today_stress is None or baseline_stress is None or baseline_stress == 0:
        return False, None

    if today_stress >= baseline_stress * (1 + above_percent / 100):
        return True, f"😰 Стрес {above_percent}%+ над базата днес"

    return False, None


def analyze_readiness(athlete_id, athlete_name):
    """Обединява всички readiness проверки, връща списък НОВИ аларми
    (записани за първи път в alert_events за event_date/alert_type
    комбинацията — при многодневно продължаващо отклонение не се
    преповтаря всеки ден)."""
    wellness_list = get_recent_wellness(athlete_id, days=14)
    alerts = []

    if not wellness_list:
        return alerts

    event_date = wellness_list[0]['date']

    sleep_flag, sleep_msg = check_sleep_alert(wellness_list)
    if sleep_flag:
        msg = f"{sleep_msg} - {athlete_name}"
        if record_alert_event(athlete_id, athlete_name, event_date, 'readiness_sleep', msg):
            alerts.append(msg)

    hrv_flag, hrv_msg = check_hrv_alert(wellness_list)
    if hrv_flag:
        msg = f"{hrv_msg} - {athlete_name}"
        if record_alert_event(athlete_id, athlete_name, event_date, 'readiness_hrv', msg):
            alerts.append(msg)

    stress_flag, stress_msg = check_stress_alert(wellness_list)
    if stress_flag:
        msg = f"{stress_msg} - {athlete_name}"
        if record_alert_event(athlete_id, athlete_name, event_date, 'readiness_stress', msg):
            alerts.append(msg)

    return alerts


if __name__ == '__main__':
    import yaml
    with open('config/athletes.yaml', 'r', encoding='utf-8') as f:
        config = yaml.safe_load(f)

    for athlete in config['athletes']:
        alerts = analyze_readiness(athlete['intervals_id'], athlete['name'])
        print(f"\n{athlete['name']}:")
        if alerts:
            for a in alerts:
                print(f"  {a}")
        else:
            print("  Няма readiness аларми (или недостатъчно данни).")
