#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
fetch_local_results.py — синхронизира българските състезания от Google Sheet.

Чете четири таба:
    Състезания           -> local_events
    Резултати            -> local_results (sport='triathlon')
    Резултати-дуатлон    -> local_results (sport='duathlon')
    Резултати-акватлон   -> local_results (sport='aquathlon')

Трите резултатни таба се сливат в ЕДНА таблица с generic имена на етапите
(leg1/leg2/leg3), защото иначе всяка нова дисциплина иска нова таблица,
нов sync и нов PHP код.

    триатлон:  leg1=плуване  leg2=колело  leg3=бягане
    дуатлон:   leg1=бягане   leg2=колело  leg3=бягане
    акватлон:  leg1=бягане   leg2=плуване leg3=бягане   (t1/t2 = NULL)

ВАЖНО: изисква venv Python 3.11 (gspread/google-auth), както fetch_lab_data.py.
    ./venv/bin/python fetch_local_results.py
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

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.path.join(BASE_DIR, "data", "agent.db")
CREDS_PATH = os.path.join(BASE_DIR, "config", "google-service-account.json")
SCOPES = ["https://www.googleapis.com/auth/spreadsheets.readonly"]

load_dotenv(os.path.join(BASE_DIR, "config", "secrets.env"))
SHEET_ID = os.getenv("LOCAL_RESULTS_SHEET_ID")

EVENTS_TAB = "Състезания"
RESULT_TABS = {
    "Резултати": "triathlon",
    "Резултати-дуатлон": "duathlon",
    "Резултати-акватлон": "aquathlon",
}

# име на колона в Sheet -> име на поле; по позиция на етапа, не по дисциплина
LEG_MAP = {
    "triathlon": {"leg1":"Плуване","leg2":"Колело","leg3":"Бягане",
        "pos_leg1":"Поз_плуване","pos_leg2":"Поз_колело","pos_leg3":"Поз_бягане"},
    "duathlon": {"leg1":"Бягане1","leg2":"Колело","leg3":"Бягане2",
        "pos_leg1":"Поз_бягане1","pos_leg2":"Поз_колело","pos_leg3":"Поз_бягане2"},
    "aquathlon": {"leg1":"Бягане1","leg2":"Плуване","leg3":"Бягане2",
        "pos_leg1":"Поз_бягане1","pos_leg2":"Поз_плуване","pos_leg3":"Поз_бягане2"},
}


def normalize_time(v):
    if v in (None, ""):
        return None
    t = str(v).strip().split(",")[0].split(".")[0]
    parts = t.split(":")
    try:
        parts = [int(x) for x in parts]
    except ValueError:
        return t or None
    if len(parts) == 2:
        h, m, sec = 0, parts[0], parts[1]
    elif len(parts) == 3:
        h, m, sec = parts
    else:
        return t
    return "%d:%02d:%02d" % (h, m, sec)


# ---------------------------------------------------------------- schema
SCHEMA = """
CREATE TABLE IF NOT EXISTS local_events (
    event_id    TEXT PRIMARY KEY,
    event_date  TEXT NOT NULL,
    name        TEXT NOT NULL,
    city        TEXT,
    organizer   TEXT,
    source_url  TEXT,
    synced_at   TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS local_results (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id     TEXT NOT NULL,
    sport        TEXT NOT NULL,
    distance     TEXT,
    category     TEXT,
    place        TEXT,
    athlete_name TEXT NOT NULL,
    club         TEXT,
    leg1         TEXT,
    t1           TEXT,
    leg2         TEXT,
    t2           TEXT,
    leg3         TEXT,
    total_time   TEXT,
    pos_leg1     TEXT,
    pos_leg2     TEXT,
    pos_leg3     TEXT,
    field_size   INTEGER,
    synced_at    TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(event_id, athlete_name)
);

CREATE INDEX IF NOT EXISTS idx_local_results_name
    ON local_results(athlete_name);
CREATE INDEX IF NOT EXISTS idx_local_results_event
    ON local_results(event_id);
"""


def init_schema(conn):
    conn.executescript(SCHEMA)
    conn.commit()


# ---------------------------------------------------------------- helpers
def pick(row, names):
    """Първата непразна стойност измежду няколко възможни имена на колони."""
    for n in names:
        v = row.get(n)
        if v not in (None, ""):
            return str(v).strip()
    return None


def as_int(v):
    try:
        return int(str(v).strip())
    except (TypeError, ValueError):
        return None


def open_sheet():
    if not SHEET_ID:
        sys.exit("❌ Липсва LOCAL_RESULTS_SHEET_ID в config/secrets.env")
    if not os.path.exists(CREDS_PATH):
        sys.exit("❌ Липсва %s" % CREDS_PATH)
    creds = Credentials.from_service_account_file(CREDS_PATH, scopes=SCOPES)
    return gspread.authorize(creds).open_by_key(SHEET_ID)


# ---------------------------------------------------------------- sync
def sync_events(conn, book):
    try:
        ws = book.worksheet(EVENTS_TAB)
    except gspread.exceptions.WorksheetNotFound:
        sys.exit("❌ Няма таб '%s' — преименуван ли е?" % EVENTS_TAB)

    n = 0
    for row in ws.get_all_records():
        eid = pick(row, ("event_id",))
        if not eid:
            continue
        conn.execute("""
            INSERT INTO local_events
                (event_id, event_date, name, city, organizer, source_url, synced_at)
            VALUES (?,?,?,?,?,?,?)
            ON CONFLICT(event_id) DO UPDATE SET
                event_date=excluded.event_date, name=excluded.name,
                city=excluded.city, organizer=excluded.organizer,
                source_url=excluded.source_url, synced_at=excluded.synced_at
        """, (eid, pick(row, ("Дата",)), pick(row, ("Име",)), pick(row, ("Град",)),
              pick(row, ("Организатор",)), pick(row, ("Източник",)),
              datetime.now().isoformat(timespec="seconds")))
        n += 1
    conn.commit()
    return n


def sync_results(conn, book, tab_name, sport):
    try:
        ws = book.worksheet(tab_name)
    except gspread.exceptions.WorksheetNotFound:
        print("⚠️  Няма таб '%s' — пропускам." % tab_name)
        return 0

    m = LEG_MAP[sport]
    n = 0
    for row in ws.get_all_records():
        eid = pick(row, ("event_id",))
        name = pick(row, ("Име",))
        if not eid or not name:
            continue

        # акватлонът няма отделни транзиции — таймерът ги слива в плувната зона
        t1 = pick(row, ("Т1",)) if sport != "aquathlon" else None
        t2 = pick(row, ("Т2",)) if sport != "aquathlon" else None

        conn.execute("""
            INSERT INTO local_results
                (event_id, sport, distance, category, place, athlete_name, club,
                 leg1, t1, leg2, t2, leg3, total_time,
                 pos_leg1, pos_leg2, pos_leg3, field_size, synced_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON CONFLICT(event_id, athlete_name) DO UPDATE SET
                sport=excluded.sport, distance=excluded.distance,
                category=excluded.category, place=excluded.place,
                club=excluded.club, leg1=excluded.leg1, t1=excluded.t1,
                leg2=excluded.leg2, t2=excluded.t2, leg3=excluded.leg3,
                total_time=excluded.total_time, pos_leg1=excluded.pos_leg1,
                pos_leg2=excluded.pos_leg2, pos_leg3=excluded.pos_leg3,
                field_size=excluded.field_size, synced_at=excluded.synced_at
        """, (
            eid, sport, pick(row, ("Дистанция",)), pick(row, ("Категория",)),
            pick(row, ("Място",)), name, pick(row, ("Клуб",)),
            normalize_time(pick(row, (m["leg1"],))), t1,
            normalize_time(pick(row, (m["leg2"],))), t2,
            normalize_time(pick(row, (m["leg3"],))),
            normalize_time(pick(row, ("Общо",))),
            pick(row, (m["pos_leg1"],)),
            pick(row, (m["pos_leg2"],)),
            pick(row, (m["pos_leg3"],)),
            as_int(pick(row, ("В_групата",))),
            datetime.now().isoformat(timespec="seconds"),
        ))
        n += 1
    conn.commit()
    return n


def find_orphans(conn, book):
    """Редове в базата, които вече ги няма в Sheet-а (sync никога не трие)."""
    live = set()
    for tab in RESULT_TABS:
        try:
            ws = book.worksheet(tab)
        except gspread.exceptions.WorksheetNotFound:
            continue
        for row in ws.get_all_records():
            eid, name = pick(row, ("event_id",)), pick(row, ("Име",))
            if eid and name:
                live.add((eid, name))

    cur = conn.execute("SELECT event_id, athlete_name FROM local_results")
    return [r for r in cur.fetchall() if tuple(r) not in live]


def main():
    if not os.path.exists(os.path.dirname(DB_PATH)):
        os.makedirs(os.path.dirname(DB_PATH), exist_ok=True)

    conn = sqlite3.connect(DB_PATH)
    init_schema(conn)

    book = open_sheet()
    print("📗 %s" % book.title)

    ev = sync_events(conn, book)
    print("   Състезания: %d" % ev)

    total = 0
    for tab, sport in RESULT_TABS.items():
        cnt = sync_results(conn, book, tab, sport)
        if cnt:
            print("   %s (%s): %d" % (tab, sport, cnt))
        total += cnt
    print("   Общо резултати: %d" % total)

    orphans = find_orphans(conn, book)
    if orphans:
        print("\n⚠️  %d реда в базата ги няма в Sheet-а:" % len(orphans))
        for eid, name in orphans:
            print("     %s / %s" % (eid, name))
        print("   Синхронизацията само upsert-ва, никога не трие —")
        print("   изтрий ги ръчно, ако наистина са излишни.")

    conn.close()


if __name__ == "__main__":
    main()
