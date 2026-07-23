"""
Чете лактатни тестове от Google Sheet (попълва се ръчно от лаборатория)
и ги upsert-ва в lactate_tests. Пуска се отделно, седмично — лаб данните
не се променят достатъчно често, за да си заслужава дневен cron ред.
"""
import os
import sys

import gspread
from google.oauth2.service_account import Credentials
from dotenv import load_dotenv

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from storage.db import init_db, upsert_lactate_test

# Windows конзолата по подразбиране е cp1252 и не поддържа кирилица в print() —
# при ръчно пускане (Linux/cron няма нужда, там stdout вече е UTF-8).
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

os.makedirs('logs', exist_ok=True)

load_dotenv('config/secrets.env')
SHEET_ID = os.getenv('LACTATE_SHEET_ID')

SERVICE_ACCOUNT_FILE = 'config/google-service-account.json'
WORKSHEET_NAME = 'Лактатни тестове'

# readonly е достатъчен — скриптът само чете, никога не пише в sheet-а
SCOPES = ['https://www.googleapis.com/auth/spreadsheets.readonly']

STEP_COUNT = 10


def _get_client():
    creds = Credentials.from_service_account_file(SERVICE_ACCOUNT_FILE, scopes=SCOPES)
    return gspread.authorize(creds)


def _num(value):
    """Празна клетка -> None; иначе float. gspread вече връща числата
    като int/float за числово форматирани клетки, но текстовите/празните
    идват като string, затова конверсията пак минава оттук."""
    if value is None:
        return None
    if isinstance(value, (int, float)):
        return float(value)
    value = str(value).strip()
    if not value:
        return None
    try:
        return float(value.replace(',', '.'))
    except ValueError:
        return None


def get_lactate_tests():
    """Връща суровите редове от таба като list от dict-и (ключ = header)."""
    client = _get_client()
    sheet = client.open_by_key(SHEET_ID)
    worksheet = sheet.worksheet(WORKSHEET_NAME)
    return worksheet.get_all_records()


def sync_lactate_tests():
    if not SHEET_ID:
        print("⚠️ LACTATE_SHEET_ID липсва в config/secrets.env — пропускам синхронизацията.")
        return

    rows = get_lactate_tests()
    synced = 0
    skipped = 0

    for row in rows:
        test_date = str(row.get('Дата', '')).strip()
        athlete_name = str(row.get('Атлет', '')).strip()
        if not test_date or not athlete_name:
            skipped += 1
            continue

        steps_hr = [_num(row.get(f'Стъпка{i}_HR')) for i in range(1, STEP_COUNT + 1)]
        steps_la = [_num(row.get(f'Стъпка{i}_La')) for i in range(1, STEP_COUNT + 1)]

        age = _num(row.get('Възраст'))

        upsert_lactate_test(
            test_date=test_date,
            athlete_name=athlete_name,
            protocol=str(row.get('Протокол (М/Ж)') or row.get('Протокол') or '').strip() or None,
            height_cm=_num(row.get('Ръст')),
            weight_kg=_num(row.get('Тегло')),
            age=int(age) if age is not None else None,
            ftp=_num(row.get('FTP')),
            w_kg=_num(row.get('W_kg')),
            lactate_start=_num(row.get('Лактат_старт')),
            hr_start=_num(row.get('Пулс_старт')),
            steps_hr=steps_hr,
            steps_la=steps_la,
            lt1_w=_num(row.get('LT1_W')),
            lt2_w=_num(row.get('LT2_W')),
            notes=str(row.get('Бележки', '')).strip() or None,
        )
        synced += 1

    print(f"Лактатни тестове: {synced} синхронизирани, {skipped} прескочени (без дата/атлет).")


if __name__ == '__main__':
    init_db()  # гарантира, че lactate_tests съществува (IF NOT EXISTS)
    sync_lactate_tests()
