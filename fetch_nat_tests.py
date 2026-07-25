#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
fetch_nat_tests.py — синхронизира националните функционални тестове (НЦ)
от Google Sheet.

Отделно от lactate_tests (клубния лактатен протокол) — вело срещу тредбанд,
и НЦ протоколите срещу клубния протокол, дават различни числа за същия
атлет по физиологични причини (виж includes/nat_tests.php за правилото
"кога две стойности изобщо са сравними").

Чете два таба:
    Протоколи             -> nat_test_protocols  (описание на всеки протокол)
    Функционални тестове  -> nat_functional_tests (самите резултати)

Схемата живее в storage/db.py:init_db() (не тук) — скриптът само я вика
преди sync, за да гарантира, че таблиците съществуват.

ВАЖНО: изисква venv Python 3.11 (gspread/google-auth), както fetch_lab_data.py
и fetch_local_results.py.
    ./venv/bin/python fetch_nat_tests.py
"""

import os
import sys

try:
    import gspread
    from google.oauth2.service_account import Credentials
except ImportError:
    sys.exit("gspread/google-auth липсват — ползвай ./venv/bin/python")

from dotenv import load_dotenv

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from storage.db import init_db, get_connection

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CREDS_PATH = os.path.join(BASE_DIR, "config", "google-service-account.json")
SCOPES = ["https://www.googleapis.com/auth/spreadsheets.readonly"]

# Windows конзолата по подразбиране е cp1252 и не поддържа кирилица в print() —
# при ръчно пускане (Linux/cron няма нужда, там stdout вече е UTF-8).
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

os.makedirs(os.path.join(BASE_DIR, "logs"), exist_ok=True)

load_dotenv(os.path.join(BASE_DIR, "config", "secrets.env"))
SHEET_ID = os.getenv("NAT_TESTS_SHEET_ID")

PROTOCOLS_TAB = "Протоколи"
TESTS_TAB = "Функционални тестове"

# Sheet колона -> поле в nat_functional_tests.
TEST_FIELD_MAP = {
    "Дата": "test_date", "Име": "athlete_name", "protocol": "protocol",
    "Уред": "device", "Лаборатория": "lab",
    "Ръст_см": "height_cm", "Разтег_см": "arm_span_cm",
    "Тегло_кг": "weight_kg", "АТМ_кг": "lean_mass_kg",
    "Мазнини_%": "fat_pct", "Мазнини_кг": "fat_kg",
    "Мускули_%": "muscle_pct", "Мускули_кг": "muscle_kg",
    "Мин": "duration_min",
    "W_max": "w_max", "W_max_kg": "w_max_kg", "S_max_kmh": "s_max_kmh",
    "VO2max": "vo2max", "VO2max_kg": "vo2max_kg", "HR_max": "hr_max",
    "ЕПЗ_от": "epz_from", "ЕПЗ_до": "epz_to",
    "La_2": "la_2", "La_6": "la_6", "La_15": "la_15",
    "Hr_2": "hr_2", "Hr_6": "hr_6",
}
# Числови полета — останалите (test_date/athlete_name/protocol/device/lab) са текст.
TEST_NUMERIC_FIELDS = {
    "height_cm", "arm_span_cm", "weight_kg", "lean_mass_kg",
    "fat_pct", "fat_kg", "muscle_pct", "muscle_kg", "duration_min",
    "w_max", "w_max_kg", "s_max_kmh", "vo2max", "vo2max_kg", "hr_max",
    "epz_from", "epz_to", "la_2", "la_6", "la_15", "hr_2", "hr_6",
}

PROTOCOL_FIELD_MAP = {
    "protocol": "protocol", "Уред": "device", "Старт": "start_value",
    "Инкремент": "increment", "Стъпка_мин": "step_minutes",
    "Наклон": "incline", "Метрика": "metric",
    "Лаборатория": "lab", "Бележка": "note",
}
PROTOCOL_NUMERIC_FIELDS = {"step_minutes"}


def num(v):
    """Празна клетка -> None (не 0). gspread връща числово форматираните
    клетки вече като int/float, но празните идват като '' — оттам проверката."""
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


def open_sheet():
    if not SHEET_ID:
        sys.exit("❌ Липсва NAT_TESTS_SHEET_ID в config/secrets.env")
    if not os.path.exists(CREDS_PATH):
        sys.exit("❌ Липсва %s" % CREDS_PATH)
    creds = Credentials.from_service_account_file(CREDS_PATH, scopes=SCOPES)
    return gspread.authorize(creds).open_by_key(SHEET_ID)


def sync_protocols(conn, book):
    try:
        ws = book.worksheet(PROTOCOLS_TAB)
    except gspread.exceptions.WorksheetNotFound:
        sys.exit("❌ Няма таб '%s' — преименуван ли е?" % PROTOCOLS_TAB)

    n = 0
    for row in ws.get_all_records(numericise_ignore=['all']):
        values = {}
        for sheet_col, field in PROTOCOL_FIELD_MAP.items():
            raw = row.get(sheet_col)
            values[field] = num(raw) if field in PROTOCOL_NUMERIC_FIELDS else text(raw)

        # Бележки в дъното на таба са в самата "protocol" клетка (не отделен
        # ред без данни) — device е празен само за тях, никога за реален ред.
        if not values["protocol"] or not values["device"]:
            continue

        conn.execute("""
            INSERT INTO nat_test_protocols
                (protocol, device, start_value, increment, step_minutes,
                 incline, metric, lab, note, synced_at)
            VALUES (?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)
            ON CONFLICT(protocol) DO UPDATE SET
                device=excluded.device, start_value=excluded.start_value,
                increment=excluded.increment, step_minutes=excluded.step_minutes,
                incline=excluded.incline, metric=excluded.metric,
                lab=excluded.lab, note=excluded.note, synced_at=excluded.synced_at
        """, (values["protocol"], values["device"], values["start_value"],
              values["increment"], values["step_minutes"], values["incline"],
              values["metric"], values["lab"], values["note"]))
        n += 1
    conn.commit()
    return n


def sync_tests(conn, book):
    try:
        ws = book.worksheet(TESTS_TAB)
    except gspread.exceptions.WorksheetNotFound:
        sys.exit("❌ Няма таб '%s' — преименуван ли е?" % TESTS_TAB)

    cols = list(TEST_FIELD_MAP.values())
    n = 0
    for row in ws.get_all_records(numericise_ignore=['all']):
        values = {}
        for sheet_col, field in TEST_FIELD_MAP.items():
            raw = row.get(sheet_col)
            values[field] = num(raw) if field in TEST_NUMERIC_FIELDS else text(raw)

        # Бележки/празни редове в дъното на таба (без Дата/Име) — прескачаме,
        # не са реални тестове.
        if not values["test_date"] or not values["athlete_name"] or not values["protocol"]:
            continue

        placeholders = ",".join("?" * len(cols))
        update_clause = ", ".join(f"{c}=excluded.{c}" for c in cols if c not in
                                   ("test_date", "athlete_name", "protocol"))
        conn.execute(f"""
            INSERT INTO nat_functional_tests ({", ".join(cols)}, synced_at)
            VALUES ({placeholders}, CURRENT_TIMESTAMP)
            ON CONFLICT(athlete_name, test_date, protocol) DO UPDATE SET
                {update_clause}, synced_at=excluded.synced_at
        """, tuple(values[c] for c in cols))
        n += 1
    conn.commit()
    return n


def find_orphans(conn, book):
    """Редове в базата, които вече ги няма в Sheet-а (sync никога не трие)."""
    try:
        ws = book.worksheet(TESTS_TAB)
    except gspread.exceptions.WorksheetNotFound:
        return []

    live = set()
    for row in ws.get_all_records(numericise_ignore=['all']):
        d, name, proto = text(row.get("Дата")), text(row.get("Име")), text(row.get("protocol"))
        if d and name and proto:
            live.add((name, d, proto))

    cur = conn.execute("SELECT athlete_name, test_date, protocol FROM nat_functional_tests")
    return [r for r in cur.fetchall() if tuple(r) not in live]


def main():
    init_db()
    conn = get_connection()

    book = open_sheet()
    print("📗 %s" % book.title)

    p = sync_protocols(conn, book)
    print("   Протоколи: %d" % p)

    t = sync_tests(conn, book)
    print("   Функционални тестове: %d" % t)

    orphans = find_orphans(conn, book)
    if orphans:
        print("\n⚠️  %d реда в базата ги няма в Sheet-а:" % len(orphans))
        for name, d, proto in orphans:
            print("     %s / %s / %s" % (name, d, proto))
        print("   Синхронизацията само upsert-ва, никога не трие —")
        print("   изтрий ги ръчно, ако наистина са излишни.")

    conn.close()


if __name__ == "__main__":
    main()
