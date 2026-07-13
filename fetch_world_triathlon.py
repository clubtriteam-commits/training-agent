"""
World Triathlon API - тегли ranking и резултати за атлетите.
"""
import os
import sys
import requests
import yaml
from dotenv import load_dotenv

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from storage.db import save_world_triathlon_ranking

load_dotenv('config/secrets.env')
API_KEY = os.getenv('WORLD_TRIATHLON_API_KEY')

BASE_URL = "https://api.triathlon.org/v1"


def get_headers():
    return {"apikey": API_KEY}


def get_athlete_rankings(athlete_id):
    url = f"{BASE_URL}/athletes/{athlete_id}/rankings"
    response = requests.get(url, headers=get_headers())
    return response.status_code, response.json() if response.status_code == 200 else response.text


def fetch_and_save_rankings():
    with open('config/athletes.yaml', 'r', encoding='utf-8') as f:
        config = yaml.safe_load(f)

    for athlete in config['athletes']:
        wt_id = athlete.get('world_triathlon_id')
        if not wt_id:
            continue

        status, rankings = get_athlete_rankings(wt_id)

        if status != 200:
            print(f"⚠️ Грешка при {athlete['name']}: {status}")
            continue

        data = rankings.get('data', {})
        world_rank = data.get('world_rankings', {}).get('ranking')
        regional_rank = data.get('regional_points_list', {}).get('ranking')

        save_world_triathlon_ranking(wt_id, athlete['name'], world_rank, regional_rank)

        print(f"{athlete['name']}: World #{world_rank}, Regional #{regional_rank} - записано в базата")


if __name__ == '__main__':
    fetch_and_save_rankings()
