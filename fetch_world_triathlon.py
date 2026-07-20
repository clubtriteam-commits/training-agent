"""
World Triathlon API - тегли ranking и резултати за атлетите.
"""
import os
import sys
import time

import requests
import yaml
from dotenv import load_dotenv

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from storage.db import (init_db, save_world_triathlon_ranking,
                        upsert_world_triathlon_result, count_world_triathlon_results,
                        get_results_needing_positions, save_result_positions)
from alerts.notifier_telegram import send_telegram_message

# git не следи празни директории — logs/ може да липсва след .gitignore
# промяна или fresh clone, което поваля cron redirect-а тихо.
os.makedirs('logs', exist_ok=True)

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


# Позиция-колона → индекс в 'splits' масива на event results
POSITION_SPLIT_INDEX = [
    ('swim_position', 0),
    ('t1_position',   1),
    ('bike_position', 2),
    ('t2_position',   3),
    ('run_position',  4),
]

# Backlog защита: при инициално изчисление има стотици необработени
# резултати — обработваме ги на порции през пусканията, не наведнъж.
MAX_EVENT_FETCHES_PER_RUN = 40
EVENT_FETCH_PAUSE_SECS = 0.5


def parse_time_to_seconds(value):
    """'H:MM:SS' или 'MM:SS' → секунди.

    Празно, невалиден формат или '00:00:00' (така WT API-то маркира
    липсващ сплит) → None.
    """
    if not isinstance(value, str) or not value.strip():
        return None
    parts = value.strip().split(':')
    if len(parts) not in (2, 3):
        return None
    try:
        nums = [int(float(p)) for p in parts]
    except ValueError:
        return None
    if len(nums) == 2:
        nums = [0] + nums
    total = nums[0] * 3600 + nums[1] * 60 + nums[2]
    return total if total > 0 else None


def get_event_program_results(event_id, prog_id):
    """Всички участници (с техните splits) в дадена програма на събитие."""
    url = f"{BASE_URL}/events/{event_id}/programs/{prog_id}/results"
    response = requests.get(url, headers=get_headers())
    if response.status_code != 200:
        return response.status_code, []
    data = response.json().get('data') or {}
    # Резултатите са в data.results; защита, ако data е директно масив
    participants = data.get('results') if isinstance(data, dict) else data
    return 200, participants or []


def is_ranked(participant):
    """DNF/DNS/DSQ/LAP имат нечислова position — извън подредбата."""
    pos = participant.get('position')
    return isinstance(pos, int) or (isinstance(pos, str) and pos.strip().isdigit())


def compute_split_positions(participants, our_athlete_id):
    """Позицията на нашия атлет във всяка дисциплина.

    В подредбата участват само класирани участници (is_ranked); липсващ
    сплит вади участника от подредбата на съответната дисциплина. Равни
    времена делят позиция във формат '=3'.

    Връща dict {swim_position: '4' | '=2' | None, ...}.
    """
    positions = {key: None for key, _ in POSITION_SPLIT_INDEX}
    ranked = [p for p in participants if is_ranked(p)]

    our = next((p for p in ranked
                if str(p.get('athlete_id')) == str(our_athlete_id)), None)
    if our is None:
        return positions

    for key, idx in POSITION_SPLIT_INDEX:
        times = []
        for p in ranked:
            splits = p.get('splits')
            raw = splits[idx] if isinstance(splits, list) and idx < len(splits) else None
            secs = parse_time_to_seconds(raw)
            if secs is not None:
                times.append((p, secs))

        our_secs = next((secs for p, secs in times if p is our), None)
        if our_secs is None:
            continue

        faster = sum(1 for _, secs in times if secs < our_secs)
        tied = sum(1 for _, secs in times if secs == our_secs)
        rank = faster + 1
        positions[key] = f"={rank}" if tied > 1 else str(rank)

    return positions


def compute_missing_split_positions():
    """Изчислява per-split позиции за резултатите, които нямат такива.

    Rate limit защити: event results endpoint-ът се вика само за
    необработени резултати (positions_computed_at IS NULL), веднъж на
    (event, prog) двойка в рамките на пускане (двама наши атлети в едно
    състезание = една заявка), с пауза между заявките и таван на брой
    заявки за пускане.
    """
    pending = get_results_needing_positions()
    if not pending:
        print("Per-split позиции: няма необработени резултати")
        return

    cache = {}
    fetches = computed = skipped = 0
    for row in pending:
        event_id, prog_id = row['event_id'], row['prog_id']

        # Без prog_id не можем да построим event URL — маркираме като
        # обработен, иначе ще опитваме до безкрай.
        if not prog_id:
            save_result_positions(row['athlete_id'], event_id, prog_id, {})
            skipped += 1
            continue

        key = (event_id, prog_id)
        if key not in cache:
            if fetches >= MAX_EVENT_FETCHES_PER_RUN:
                print(f"  Достигнат лимит от {MAX_EVENT_FETCHES_PER_RUN} event "
                      f"заявки — остатъкът при следващото пускане")
                break
            status, participants = get_event_program_results(event_id, prog_id)
            fetches += 1
            time.sleep(EVENT_FETCH_PAUSE_SECS)
            if status != 200:
                print(f"  ⚠️ Event {event_id}/prog {prog_id}: HTTP {status}")
                cache[key] = None
            else:
                cache[key] = participants

        participants = cache[key]
        if participants is None:
            continue  # неуспешна заявка — без маркер, retry следващия път

        positions = compute_split_positions(participants, row['athlete_id'])
        save_result_positions(row['athlete_id'], event_id, prog_id, positions)
        computed += 1

    print(f"Per-split позиции: {computed} изчислени, {skipped} без prog_id, "
          f"{fetches} event заявки")


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
    compute_missing_split_positions()
