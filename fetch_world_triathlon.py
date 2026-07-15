"""
World Triathlon API - тегли ranking и резултати за атлетите.
"""
import os
import sys
import requests
import yaml
from dotenv import load_dotenv

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from storage.db import init_db, save_world_triathlon_ranking, upsert_world_triathlon_result

load_dotenv('config/secrets.env')
API_KEY = os.getenv('WORLD_TRIATHLON_API_KEY')

BASE_URL = "https://api.triathlon.org/v1"


def get_headers():
    return {"apikey": API_KEY}


def get_athlete_rankings(athlete_id):
    url = f"{BASE_URL}/athletes/{athlete_id}/rankings"
    response = requests.get(url, headers=get_headers())
    return response.status_code, response.json() if response.status_code == 200 else response.text


def get_athlete_results(athlete_id):
    """Всички резултати на атлета, през всички страници на API-то."""
    results = []
    page = 1
    while True:
        url = f"{BASE_URL}/athletes/{athlete_id}/results"
        response = requests.get(url, headers=get_headers(),
                                params={'per_page': 50, 'page': page})
        if response.status_code != 200:
            return response.status_code, results

        body = response.json()
        data = body.get('data') or []
        results.extend(data)

        last_page = body.get('last_page') or page
        if page >= last_page or not data:
            return 200, results
        page += 1


def fetch_and_save_results():
    with open('config/athletes.yaml', 'r', encoding='utf-8') as f:
        config = yaml.safe_load(f)

    for athlete in config['athletes']:
        wt_id = athlete.get('world_triathlon_id')
        if not wt_id:
            continue

        status, results = get_athlete_results(wt_id)
        if status != 200:
            print(f"⚠️ Грешка при резултати на {athlete['name']}: {status}")
            continue

        saved = 0
        for r in results:
            event_id = r.get('event_id')
            if event_id is None:
                continue
            upsert_world_triathlon_result(
                athlete_id=wt_id,
                athlete_name=athlete['name'],
                event_id=event_id,
                prog_id=r.get('prog_id') or 0,
                event_date=r.get('event_date'),
                event_title=r.get('event_title'),
                # API-то връща число или текст (DNF/DSQ/LAP) — пазим като текст
                position=str(r['position']) if r.get('position') is not None else None,
                total_time=r.get('total_time'),
                event_country=r.get('event_country'),
            )
            saved += 1

        print(f"{athlete['name']}: {saved} резултата записани/обновени в базата")


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
    init_db()  # гарантира, че world_triathlon_results съществува (IF NOT EXISTS)
    fetch_and_save_rankings()
    fetch_and_save_results()
