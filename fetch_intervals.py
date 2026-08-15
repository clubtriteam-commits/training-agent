import os
import base64
from datetime import date, timedelta

import requests
from dotenv import load_dotenv

# Зареждаме секретите
load_dotenv('config/secrets.env')
API_KEY = os.getenv('INTERVALS_API_KEY')


def _auth_headers():
    # Intervals.icu API използва Basic Auth с "API_KEY" като username, стойността като парола
    auth_bytes = base64.b64encode(f"API_KEY:{API_KEY}".encode()).decode()
    return {"Authorization": f"Basic {auth_bytes}"}


def _date_range(days):
    today = date.today()
    return (today - timedelta(days=days)).isoformat(), today.isoformat()


def get_activities(athlete_id, days=30):
    # 30, не 7 — keyword/late-start дедупликират сами (filter_new_activities),
    # но main.py's zones стъпка (upsert_activity_zones) чете ВСИЧКИ върнати
    # активности всеки ден, не само новите; при 7 дни разредени активности
    # (напр. шосейно колоездене между VirtualRide тренировки) редовно
    # изпадаха между cron пусканията и никога не влизаха в activity_zones —
    # виж инцидента от 2026-08-15, поправен с еднократен ръчен backfill
    # (days=90) плюс тази промяна занапред.
    url = f"https://intervals.icu/api/v1/athlete/{athlete_id}/activities"
    oldest, newest = _date_range(days)
    params = {"oldest": oldest, "newest": newest}

    response = requests.get(url, headers=_auth_headers(), params=params)
    return response.status_code, response.json() if response.status_code == 200 else response.text


# 14 дни по подразбиране: readiness baseline-ът чете 14 дни от базата,
# така и след пропуснати cron пускания прозорецът се запълва
def get_wellness(athlete_id, days=14):
    url = f"https://intervals.icu/api/v1/athlete/{athlete_id}/wellness"
    oldest, newest = _date_range(days)
    params = {"oldest": oldest, "newest": newest}

    response = requests.get(url, headers=_auth_headers(), params=params)
    return response.status_code, response.json() if response.status_code == 200 else response.text


if __name__ == '__main__':
    # Ръчен тест: python fetch_intervals.py (не се изпълнява при import)
    import yaml

    with open('config/athletes.yaml', 'r', encoding='utf-8') as f:
        config = yaml.safe_load(f)

    for athlete in config['athletes']:
        print(f"\n{'='*50}")
        print(f"Athlete: {athlete['name']} ({athlete['intervals_id']})")
        print('='*50)

        status, activities = get_activities(athlete['intervals_id'])
        print(f"\nActivities (status {status}):")
        print(activities)

        status, wellness = get_wellness(athlete['intervals_id'])
        print(f"\nWellness (status {status}):")
        print(wellness)
