"""
Главен orchestrator - тегли данни, изчислява метрики, праща аларми в Telegram.
Пуска се през cron (дневно за аларми, седмично за пълен summary).
"""
import yaml
from fetch_intervals import get_wellness
from metrics.acwr import analyze_athlete_acwr
from alerts.notifier_telegram import send_alerts_batch, send_telegram_message


def run_daily_check():
    with open('config/athletes.yaml', 'r', encoding='utf-8') as f:
        config = yaml.safe_load(f)

    all_alerts = []

    for athlete in config['athletes']:
        status, wellness = get_wellness(athlete['intervals_id'])

        if status != 200:
            print(f"⚠️ Грешка при извличане на данни за {athlete['name']}: {status}")
            continue

        results, alerts = analyze_athlete_acwr(
            wellness, athlete['intervals_id'], athlete['name']
        )

        all_alerts.extend(alerts)

    if all_alerts:
        print(f"Пращам {len(all_alerts)} нови аларми в Telegram...")
        send_alerts_batch(all_alerts)
    else:
        print("Няма нови аларми за днес.")


if __name__ == '__main__':
    run_daily_check()

