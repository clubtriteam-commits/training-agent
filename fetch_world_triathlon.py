"""
World Triathlon API - тегли ranking и резултати за атлетите.
"""
import os
import sys
import requests
import yaml
from dotenv import load_dotenv

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from storage.db import (init_db, save_world_triathlon_ranking,
                        upsert_world_triathlon_result, count_world_triathlon_results)
from alerts.notifier_telegram import send_telegram_message

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


def parse_splits(result):
    """Сплитовете от 'splits' масива: [swim, T1, bike, T2, run, (total)].

    Липсващ/къс/невалиден масив или празни стойности → None за съответния
    сплит; никога не гърми.
    """
    splits = result.get('splits')
    if not isinstance(splits, list):
        splits = []

    def pick(i):
        value = splits[i] if i < len(splits) else None
        return value if isinstance(value, str) and value.strip() else None

    return {
        'swim_split': pick(0),
        't1_split':   pick(1),
        'bike_split': pick(2),
        't2_split':   pick(3),
        'run_split':  pick(4),
    }


def build_new_result_message(athlete_name, event_title, event_date,
                             position, total_time, splits):
    """Telegram текст за нов резултат; липсващите сплитове се пропускат."""
    lines = [
        f"🏁 {athlete_name}: нов резултат от {event_title} ({event_date})",
        f"Позиция: {position or '—'}, Общо време: {total_time or '—'}",
    ]
    labels = [
        ('swim_split', 'Плуване'),
        ('t1_split',   'Т1'),
        ('bike_split', 'Колело'),
        ('t2_split',   'Т2'),
        ('run_split',  'Бягане'),
    ]
    parts = [f"{label}: {splits[key]}" for key, label in labels if splits.get(key)]
    if parts:
        lines.append(' | '.join(parts))
    return '\n'.join(lines)


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

        # Празна таблица за атлета = първо зареждане на цялата история —
        # без аларми, иначе Telegram получава стотици "нови" резултата.
        is_backfill = count_world_triathlon_results(wt_id) == 0

        saved = 0
        new_messages = []
        for r in results:
            event_id = r.get('event_id')
            if event_id is None:
                continue
            splits = parse_splits(r)
            is_new = upsert_world_triathlon_result(
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
                **splits,
            )
            saved += 1
            if is_new and not is_backfill:
                new_messages.append(build_new_result_message(
                    athlete['name'], r.get('event_title'), r.get('event_date'),
                    r.get('position'), r.get('total_time'), splits,
                ))

        print(f"{athlete['name']}: {saved} резултата записани/обновени в базата")
        if is_backfill and saved:
            print(f"  (инициално зареждане — {saved} исторически резултата, без Telegram аларми)")
        for message in new_messages:
            if send_telegram_message(message):
                print(f"  📨 Telegram аларма пратена: {message.splitlines()[0]}")


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
