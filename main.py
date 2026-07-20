"""
Главен orchestrator - тегли данни, изчислява метрики, праща аларми в Telegram.
Пуска се през cron (дневно за аларми, седмично за пълен summary).
"""
import os

import yaml
from fetch_intervals import get_wellness, get_activities
from metrics.acwr import analyze_athlete_acwr
from metrics.readiness import analyze_readiness
from metrics.comment_alerts import analyze_new_activities
from metrics.late_start import analyze_late_starts
from storage.db import filter_new_activities
from alerts.notifier_telegram import send_alerts_batch

# git не следи празни директории — logs/ може да липсва след .gitignore
# промяна или fresh clone, което поваля cron redirect-а тихо.
os.makedirs('logs', exist_ok=True)


def run_daily_check():
    with open('config/athletes.yaml', 'r', encoding='utf-8') as f:
        config = yaml.safe_load(f)

    all_alerts = []

    for athlete in config['athletes']:
        status, wellness = get_wellness(athlete['intervals_id'])

        if status != 200:
            print(f"⚠️ Грешка при извличане на данни за {athlete['name']}: {status}")
            continue

        # ACWR (записва в базата + връща аларми при преход)
        results, acwr_alerts = analyze_athlete_acwr(
            wellness, athlete['intervals_id'], athlete['name']
        )
        all_alerts.extend(acwr_alerts)

        # Readiness (HRV/сън/стрес) - чете от базата, която acwr вече е обновил
        readiness_alerts = analyze_readiness(athlete['intervals_id'], athlete['name'])
        all_alerts.extend(readiness_alerts)

        # Проверки върху новите (невиждани) активности: дедупликацията е
        # обща, затова филтрираме веднъж и подаваме резултата на всички
        act_status, activities = get_activities(athlete['intervals_id'])
        if act_status == 200:
            new_activities = filter_new_activities(athlete['intervals_id'], activities)

            # Оплаквания в заглавие/коментар
            keyword_alerts = analyze_new_activities(
                athlete['intervals_id'], athlete['name'], new_activities
            )
            all_alerts.extend(keyword_alerts)

            # Късно започнали тренировки (информативно)
            late_alerts = analyze_late_starts(
                athlete['intervals_id'], athlete['name'], new_activities
            )
            all_alerts.extend(late_alerts)
        else:
            print(f"⚠️ Грешка при извличане на активности за {athlete['name']}: {act_status}")

    if all_alerts:
        print(f"Пращам {len(all_alerts)} нови аларми в Telegram...")
        send_alerts_batch(all_alerts)
    else:
        print("Няма нови аларми за днес.")


if __name__ == '__main__':
    run_daily_check()
