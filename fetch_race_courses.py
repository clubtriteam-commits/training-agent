#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
fetch_race_courses.py — синхронизира треньорските данни за курса на
състезание (плуване/вело/бягане детайли, тип старт, настилка, бележки)
от таб "Курсове" в същия Google Sheet като местните резултати.

Схемата на race_courses живее в storage/db.py (не тук, за разлика от
fetch_local_results.py — виж docs/spec_race_courses.md §3).

event_id е числовият World Triathlon event_id (не local_events-стил slug —
виж спека §5), попълнен ръчно в Sheet-а от треньора. event_title НЕ идва
от Sheet-а — sync скриптът го попълва от world_triathlon_results по
event_id, вградено служи и като "резолвна ли се event_id-то" сигнал.

Данните се РЕДАКТИРАТ в Sheet-а (не се добавят реда по ред като
резултати) — INSERT OR REPLACE по event_id, никога не трие редове (само
предупреждава за осиротели, по прецедента на fetch_local_results.py).

ВАЖНО: изисква venv Python 3.11 (gspread/google-auth), както
fetch_local_results.py/fetch_lab_data.py.
    ./venv/bin/python fetch_race_courses.py
"""

import os
import sqlite3
import sys
from datetime import datetime

try:
    import gspread
    from google.oauth2.service_account import Credentials
except ImportError:
    sys.exit("gspread/google-auth липсват — ползвай ./venv/bin/python")

from dotenv import load_dotenv

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from storage.db import init_db, DB_PATH

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CREDS_PATH = os.path.join(BASE_DIR, "config", "google-service-account.json")
SCOPES = ["https://www.googleapis.com/auth/spreadsheets.readonly"]

load_dotenv(os.path.join(BASE_DIR, "config", "secrets.env"))
# Същият Sheet ID като fetch_local_results.py — нов таб в него, не нов Sheet.
SHEET_ID = os.getenv("LOCAL_RESULTS_SHEET_ID")

COURSES_TAB = "Курсове"

# Sheet колона -> race_courses поле, за текстовите полета без числова
# нормализация (виж §2 от спека). "Състезание" (българското име) умишлено
# липсва тук — не се persist-ва, само event_title (EN, auto-fill) се пази.
TEXT_FIELDS = {
    "date":          "Дата",
    "distance_type": "Дистанция",
    "water_body":    "Водоем",
    "start_type":    "Тип старт",
    "water_temp":    "Т_вода_C",   # умишлено TEXT, виж normalize по-долу
    "bike_profile":  "Вело_профил",
    "traffic_side":  "Движение",
    "run_surface":   "Настилка",
    "aid_stations":  "Пунктове",
    "start_times":   "Старт_час",
    "coach_notes":   "Бележки_треньор",
}

# Sheet колона -> race_courses поле, за числови полета (нормализация:
# българска запетая -> точка, после int()).
INT_FIELDS = {
    "swim_m":     "Плуване_м",
    "swim_laps":  "Плуване_обиколки",
    "swim_t1_m":  "Swim_T1_м",
    "bike_laps":  "Вело_обиколки",
    "run_laps":   "Бягане_обиколки",
}

# Sheet колона -> race_courses поле, за REAL числови полета.
FLOAT_FIELDS = {
    "bike_km": "Вело_км",
    "run_km":  "Бягане_км",
}


def as_text(v):
    """Празна клетка -> None, иначе trim-нат текст без числова обработка."""
    if v is None:
        return None
    s = str(v).strip()
    return s if s != "" else None


def normalize_decimal(v):
    """Празно -> None; иначе замества българска десетична запетая с точка."""
    s = as_text(v)
    return s.replace(",", ".") if s is not None else None


def as_int(v):
    s = normalize_decimal(v)
    if s is None:
        return None
    try:
        return int(float(s))
    except ValueError:
        return None


def as_float(v):
    s = normalize_decimal(v)
    if s is None:
        return None
    try:
        return float(s)
    except ValueError:
        return None


def open_sheet():
    if not SHEET_ID:
        sys.exit("❌ Липсва LOCAL_RESULTS_SHEET_ID в config/secrets.env")
    if not os.path.exists(CREDS_PATH):
        sys.exit("❌ Липсва %s" % CREDS_PATH)
    creds = Credentials.from_service_account_file(CREDS_PATH, scopes=SCOPES)
    return gspread.authorize(creds).open_by_key(SHEET_ID)


def lookup_event_title(conn, event_id):
    """event_title НЕ идва от Sheet-а — резолва се от world_triathlon_results
    по event_id. None означава или невалиден event_id, или състезание, което
    още не е синхронизирано от fetch_world_triathlon.py — и в двата случая
    заслужава внимание (виж audit_data.py проверката в спека §6)."""
    try:
        eid = int(event_id)
    except (TypeError, ValueError):
        return None
    row = conn.execute(
        "SELECT event_title FROM world_triathlon_results WHERE event_id = ? LIMIT 1",
        (eid,)
    ).fetchone()
    return row[0] if row else None


def sync_courses(conn, book):
    try:
        ws = book.worksheet(COURSES_TAB)
    except gspread.exceptions.WorksheetNotFound:
        sys.exit("❌ Няма таб '%s' — преименуван ли е?" % COURSES_TAB)

    n = 0
    skipped_no_id = 0
    seen_ids = set()
    now = datetime.now().isoformat(timespec="seconds")

    for row in ws.get_all_records():
        eid = as_text(row.get("event_id"))
        if not eid:
            skipped_no_id += 1
            continue
        seen_ids.add(eid)

        values = {field: as_text(row.get(col)) for field, col in TEXT_FIELDS.items()}
        values.update({field: as_int(row.get(col)) for field, col in INT_FIELDS.items()})
        values.update({field: as_float(row.get(col)) for field, col in FLOAT_FIELDS.items()})

        conn.execute("""
            INSERT OR REPLACE INTO race_courses
                (event_id, date, event_title, distance_type, water_body,
                 start_type, swim_m, swim_laps, water_temp, swim_t1_m,
                 bike_km, bike_laps, bike_profile, traffic_side,
                 run_km, run_laps, run_surface, aid_stations,
                 start_times, coach_notes, synced_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        """, (
            eid, values["date"], lookup_event_title(conn, eid),
            values["distance_type"], values["water_body"], values["start_type"],
            values["swim_m"], values["swim_laps"], values["water_temp"],
            values["swim_t1_m"], values["bike_km"], values["bike_laps"],
            values["bike_profile"], values["traffic_side"],
            values["run_km"], values["run_laps"], values["run_surface"],
            values["aid_stations"], values["start_times"], values["coach_notes"],
            now,
        ))
        n += 1

    conn.commit()
    return n, skipped_no_id, seen_ids


def find_orphans(conn, seen_ids):
    """Редове в базата, които вече ги няма в Sheet-а (sync никога не трие)."""
    cur = conn.execute("SELECT event_id FROM race_courses")
    return [row[0] for row in cur.fetchall() if row[0] not in seen_ids]


def main():
    init_db()  # гарантира, че race_courses съществува (IF NOT EXISTS); прави и data/ директорията
    conn = sqlite3.connect(DB_PATH)

    book = open_sheet()
    print("📗 %s" % book.title)

    n, skipped_no_id, seen_ids = sync_courses(conn, book)
    print("   Курсове: %d синхронизирани" % n)
    if skipped_no_id:
        print("   ⚠️  %d реда без event_id — пропуснати (попълни колоната в Sheet-а)" % skipped_no_id)

    orphans = find_orphans(conn, seen_ids)
    if orphans:
        print("\n⚠️  %d реда в базата ги няма в Sheet-а:" % len(orphans))
        for eid in orphans:
            print("     event_id=%s" % eid)
        print("   Синхронизацията само upsert-ва, никога не трие —")
        print("   изтрий ги ръчно, ако наистина са излишни.")

    missing_titles = conn.execute(
        "SELECT event_id, date FROM race_courses WHERE event_title IS NULL"
    ).fetchall()
    if missing_titles:
        print("\n⚠️  %d курса с event_id, който не резолвва в world_triathlon_results:" % len(missing_titles))
        for eid, d in missing_titles:
            print("     event_id=%s (date=%s) — грешен ID, или състезанието още не е synced" % (eid, d))

    conn.close()


if __name__ == "__main__":
    main()
