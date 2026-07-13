import os
import base64
import requests
import yaml
from dotenv import load_dotenv

# Зареждаме секретите
load_dotenv('config/secrets.env')
API_KEY = os.getenv('INTERVALS_API_KEY')

# Зареждаме атлетите
with open('config/athletes.yaml', 'r', encoding='utf-8') as f:
    config = yaml.safe_load(f)

athletes = config['athletes']

# Intervals.icu API използва Basic Auth с "API_KEY" като username, "API_KEY" стойността като парола
def get_activities(athlete_id, days=7):
    url = f"https://intervals.icu/api/v1/athlete/{athlete_id}/activities"
    auth_str = f"API_KEY:{API_KEY}"
    auth_bytes = base64.b64encode(auth_str.encode()).decode()
    headers = {"Authorization": f"Basic {auth_bytes}"}
    params = {"oldest": "2026-07-06", "newest": "2026-07-13"}

    response = requests.get(url, headers=headers, params=params)
    return response.status_code, response.json() if response.status_code == 200 else response.text

def get_wellness(athlete_id):
    url = f"https://intervals.icu/api/v1/athlete/{athlete_id}/wellness"
    auth_str = f"API_KEY:{API_KEY}"
    auth_bytes = base64.b64encode(auth_str.encode()).decode()
    headers = {"Authorization": f"Basic {auth_bytes}"}
    params = {"oldest": "2026-07-06", "newest": "2026-07-13"}

    response = requests.get(url, headers=headers, params=params)
    return response.status_code, response.json() if response.status_code == 200 else response.text

# Тестваме за всеки атлет
for athlete in athletes:
    print(f"\n{'='*50}")
    print(f"Athlete: {athlete['name']} ({athlete['intervals_id']})")
    print('='*50)

    status, activities = get_activities(athlete['intervals_id'])
    print(f"\nActivities (status {status}):")
    print(activities)

    status, wellness = get_wellness(athlete['intervals_id'])
    print(f"\nWellness (status {status}):")
    print(wellness)

