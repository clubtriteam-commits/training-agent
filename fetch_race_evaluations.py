#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
fetch_race_evaluations.py — синхронизира треньорските оценки от състезания
(таб "Оценки" в същия Google Sheet, който чете fetch_local_results.py —
LOCAL_RESULTS_SHEET_ID, общ service account) в race_evaluations.

Субективна оценка (1-5) по 10 елемента на едно състезание на един атлет —
досега водена в отделни .docx файлове, мигрирана в Sheets. Виж спецификацията
за пълния контекст (Google Sheet структура, join логика в athlete.php).

ВАЖНО: изисква venv Python 3.11 (gspread/google-auth), както fetch_lab_data.py
и fetch_local_results.py.
    ./venv/bin/python fetch_race_evaluations.py
"""

import os
import sys

try:
    import gspread
    from google.oauth2.service_account import Credentials
except ImportError:
    sys.exit("gspread/google-auth липсват — ползвай ./venv/bin/python")

from dotenv import load_dotenv

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, BASE_DIR)
from storage.db import init_db, get_connection

CREDS_PATH = os.path.join(BASE_DIR, "config", "google-service-account.json")
SCOPES = ["https://www.googleapis.com/auth/spreadsheets.readonly"]

# Windows конзолата по подразбиране е cp1252 и не поддържа кирилица в print() —
# при ръчно пускане (Linux/cron няма нужда, там stdout вече е UTF-8).
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

os.makedirs(os.path.join(BASE_DIR, "logs"), exist_ok=True)

load_dotenv(os.path.join(BASE_DIR, "config", "secrets.env"))
# Общ Sheet с fetch_local_results.py — не отделен *_SHEET_ID, таб "Оценки"
# живее в същата електронна таблица.
SHEET_ID = os.getenv("LOCAL_RESULTS_SHEET_ID")

EVALUATIONS_TAB = "Оценки"

# Sheet колона -> поле в race_evaluations, в реда от спецификацията.
FIELD_MAP = {
    "Дата": "event_date", "Състезание": "event_title", "Тип": "event_type",
    "Дистанция": "distance", "Атлет": "athlete_name",
    "Плуване: Старт": "swim_start", "Плуване: Тренировки": "swim_training",
    "Бележки Плуване": "notes_swim",
    "Т1: Събличане": "t1_wetsuit", "Т1: Качване": "t1_mount",
    "Бележки Т1": "notes_t1",
    "Колоездене: Мощност": "bike_power", "Колоездене: Техника": "bike_technique",
    "Бележки Колоездене": "notes_bike",
    "Т2: Слизане": "t2_dismount", "Т2: Обуване": "t2_shoes",
    "Бележки Т2": "notes_t2",
    "Бягане: Преход": "run_transition", "Бягане: Разпределение": "run_pacing",
    "Бележки Бягане": "notes_run",
    "Обща бележка": "general_note",
}
# Числови (оценки 1-5) полета — останалите са текст.
NUMERIC_FIELDS = {
    "swim_start", "swim_training", "t1_wetsuit", "t1_mount",
    "bike_power", "bike_technique", "t2_dismount", "t2_shoes",
    "run_transition", "run_pacing",
}


def num(v):
    """Празна клетка -> None (не 0). Приема и '4,5', и '4.5' — научен урок
    от fetch_local_results.py/fetch_nat_tests.py: gspread's numericise без
    numericise_ignore мангли десетичните запетаи ('4,5' -> 45), затова тук
    четем с numericise_ignore=['all'] и парсваме ръчно, навсякъде."""
    if v is None:
        return None
    if isinstance(v, (int, float)):
        return float(v)
    v = str(v).strip()
    if not v:
        return None
    try:
        return float(v.replace(",", "."))
    except ValueError:
        return None


def text(v):
    if v is None:
        return None
    v = str(v).strip()
    return v or None


def normalize_athlete_name(v):
    """Регистърът в Sheet-а не е гарантирано консистентен ("ГЕОРГИЕВА" срещу
    "Георгиева") — join-овете в athlete.php стъпват на athlete_name точно
    съвпадение, затова нормализираме към Title Case тук, веднъж, при ingest."""
    v = text(v)
    if v is None:
        return None
    return " ".join(v.split()).title()


def open_sheet():
    if not SHEET_ID:
        sys.exit("❌ Липсва LOCAL_RESULTS_SHEET_ID в config/secrets.env")
    if not os.path.exists(CREDS_PATH):
        sys.exit("❌ Липсва %s" % CREDS_PATH)
    creds = Credentials.from_service_account_file(CREDS_PATH, scopes=SCOPES)
    return gspread.authorize(creds).open_by_key(SHEET_ID)


def sync_evaluations(conn, book):
    try:
        ws = book.worksheet(EVALUATIONS_TAB)
    except gspread.exceptions.WorksheetNotFound:
        sys.exit("❌ Няма таб '%s' — преименуван ли е?" % EVALUATIONS_TAB)

    cols = list(FIELD_MAP.values())
    n = 0
    skipped = 0
    for i, row in enumerate(ws.get_all_records(numericise_ignore=['all']), start=2):
        values = {}
        for sheet_col, field in FIELD_MAP.items():
            raw = row.get(sheet_col)
            values[field] = num(raw) if field in NUMERIC_FIELDS else text(raw)
        values["athlete_name"] = normalize_athlete_name(row.get("Атлет"))

        # Ред без дата: warning в лога, пропускаме — не крашваме останалата
        # синхронизация заради една празна/чернова бележка накрая на таба.
        if not values["event_date"]:
            print("⚠️  Ред %d в '%s': няма дата, пропускам (%s / %s)" % (
                i, EVALUATIONS_TAB, values.get("athlete_name") or "?",
                values.get("event_title") or "?"))
            skipped += 1
            continue
        if not values["athlete_name"]:
            print("⚠️  Ред %d в '%s': няма атлет, пропускам (дата %s)" % (
                i, EVALUATIONS_TAB, values["event_date"]))
            skipped += 1
            continue

        placeholders = ",".join("?" * len(cols))
        update_clause = ", ".join(
            "%s=excluded.%s" % (c, c) for c in cols if c not in ("athlete_name", "event_date")
        )
        conn.execute("""
            INSERT INTO race_evaluations (%s, synced_at)
            VALUES (%s, CURRENT_TIMESTAMP)
            ON CONFLICT(athlete_name, event_date) DO UPDATE SET
                %s, synced_at=excluded.synced_at
        """ % (", ".join(cols), placeholders, update_clause),
                     tuple(values[c] for c in cols))
        n += 1
    conn.commit()
    return n, skipped


def find_orphans(conn, book):
    """Редове в базата, които вече ги няма в Sheet-а (sync никога не трие) —
    upsert-only модел, същият като local_results/nat_functional_tests."""
    try:
        ws = book.worksheet(EVALUATIONS_TAB)
    except gspread.exceptions.WorksheetNotFound:
        return []

    live = set()
    for row in ws.get_all_records(numericise_ignore=['all']):
        name = normalize_athlete_name(row.get("Атлет"))
        d = text(row.get("Дата"))
        if name and d:
            live.add((name, d))

    cur = conn.execute("SELECT athlete_name, event_date FROM race_evaluations")
    return [r for r in cur.fetchall() if tuple(r) not in live]


def main():
    init_db()
    conn = get_connection()

    book = open_sheet()
    print("📗 %s" % book.title)

    n, skipped = sync_evaluations(conn, book)
    print("   Оценки: %d синхронизирани, %d пропуснати" % (n, skipped))

    orphans = find_orphans(conn, book)
    if orphans:
        print("\n⚠️  %d реда в базата ги няма в Sheet-а:" % len(orphans))
        for name, d in orphans:
            print("     %s / %s" % (name, d))
        print("   Синхронизацията само upsert-ва, никога не трие —")
        print("   изтрий ги ръчно, ако наистина са излишни.")

    conn.close()


if __name__ == "__main__":
    main()
