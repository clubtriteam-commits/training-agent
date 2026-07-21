"""
ACWR (Acute:Chronic Workload Ratio) - използва CTL/ATL от Intervals.icu
Безопасна зона: 0.8 - 1.5
< 0.8  -> детрениране / рязък спад
> 1.5  -> висок риск от травма/пренатоварване
"""
import sys
import os
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from storage.db import upsert_daily_metric, get_previous_status, log_alert, alert_already_logged


def calculate_acwr(wellness_day):
    ctl = wellness_day.get('ctl')
    atl = wellness_day.get('atl')

    if ctl is None or atl is None or ctl == 0:
        return None, 'no_data'

    acwr = atl / ctl

    if acwr < 0.8:
        status = 'low'
    elif acwr > 1.5:
        status = 'high'
    else:
        status = 'ok'

    return round(acwr, 2), status


def analyze_athlete_acwr(wellness_list, athlete_id, athlete_name, save_to_db=True):
    results = []
    alerts = []

    for day in wellness_list:
        acwr, status = calculate_acwr(day)
        date = day.get('id')
        ctl = day.get('ctl')
        atl = day.get('atl')

        results.append({'date': date, 'acwr': acwr, 'status': status})

        if save_to_db and status != 'no_data':
            prev_status = get_previous_status(athlete_id, date)

            upsert_daily_metric(
                athlete_id=athlete_id,
                athlete_name=athlete_name,
                date=date,
                ctl=ctl,
                atl=atl,
                acwr=acwr,
                acwr_status=status,
                hrv=day.get('hrv'),
                sleep_secs=day.get('sleepSecs'),
                stress=day.get('stress'),
                resting_hr=day.get('restingHR')
            )

            if prev_status is not None and prev_status != status:
                alert_type, msg = None, None
                if status == 'high':
                    alert_type = 'acwr_high'
                    msg = f"⚠️ {athlete_name}: ACWR скочи на {acwr} ({date}) - риск от пренатоварване"
                elif status == 'low':
                    alert_type = 'acwr_low'
                    msg = f"ℹ️ {athlete_name}: ACWR падна на {acwr} ({date}) - детрениране"
                elif status == 'ok' and prev_status in ('high', 'low'):
                    alert_type = 'acwr_normalized'
                    msg = f"✅ {athlete_name}: ACWR се нормализира на {acwr} ({date})"

                if alert_type and not alert_already_logged(athlete_id, date, alert_type):
                    alerts.append(msg)
                    log_alert(athlete_id, athlete_name, date, alert_type, msg)

    return results, alerts


if __name__ == '__main__':
    from fetch_intervals import get_wellness
    import yaml

    with open('config/athletes.yaml', 'r', encoding='utf-8') as f:
        config = yaml.safe_load(f)

    for athlete in config['athletes']:
        status, wellness = get_wellness(athlete['intervals_id'])
        if status == 200:
            results, alerts = analyze_athlete_acwr(
                wellness, athlete['intervals_id'], athlete['name']
            )
            print(f"\n{athlete['name']}:")
            for r in results:
                print(f"  {r['date']}: ACWR={r['acwr']} ({r['status']})")
            if alerts:
                print("  Нови аларми (преходи):")
                for a in alerts:
                    print(f"    {a}")
            else:
                print("  Няма нови преходи в статус.")
