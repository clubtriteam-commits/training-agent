"""
Генерира примерни данни за ЛОКАЛНО тестване на дашборда.
Не пускай на сървъра — там базата се пълни от main.py.

    python seed_dev_data.py
"""
import math
import random
import sqlite3
from datetime import date, timedelta

from storage.db import DB_PATH, init_db, upsert_daily_metric, log_alert

# (intervals_id за daily_metrics, world_triathlon_id за world_triathlon, име) —
# двете таблици ползват РАЗЛИЧНИ ID-та, както на production
ATHLETES = [
    ("i333802", "178014", "Мира Георгиева"),
    ("i273532", "188676", "Миролюба Ненкова"),
    ("i172547", "181219", "Симеон Бобеков"),
]

DAYS = 180


def acwr_status(acwr):
    if acwr is None:
        return "no_data"
    if acwr < 0.8:
        return "low"
    if acwr > 1.3:
        return "high"
    return "ok"


def seed():
    init_db()
    today = date.today()

    # Идемпотентност: чистим таблиците без unique constraint
    conn = sqlite3.connect(DB_PATH)
    conn.execute("DELETE FROM alerts_log")
    conn.execute("DELETE FROM world_triathlon")
    conn.commit()
    conn.close()

    for idx, (athlete_id, wt_id, name) in enumerate(ATHLETES):
        rng = random.Random(42 + idx)
        ctl = 40.0 + idx * 10
        atl = ctl

        for d in range(DAYS, -1, -1):
            day = today - timedelta(days=d)
            # Седмичен цикъл: тежки дни, почивен ден, плюс лек сезонен тренд
            weekday = day.weekday()
            base_load = 70 + idx * 15 + 25 * math.sin(2 * math.pi * (DAYS - d) / 90)
            if weekday == 0:  # почивен понеделник
                tss = rng.uniform(0, 15)
            elif weekday in (5, 6):  # тежък уикенд
                tss = base_load * rng.uniform(1.1, 1.6)
            else:
                tss = base_load * rng.uniform(0.5, 1.1)

            ctl = ctl + (tss - ctl) / 42.0
            atl = atl + (tss - atl) / 7.0
            acwr = round(atl / ctl, 2) if ctl > 0 else None

            hrv = round(65 + idx * 5 + 8 * math.sin(2 * math.pi * (DAYS - d) / 30) + rng.gauss(0, 4), 1)
            sleep_secs = int(rng.gauss(7.3, 0.8) * 3600)
            stress = round(min(95, max(5, 35 + (atl - ctl) * 1.5 + rng.gauss(0, 10))), 1)
            resting_hr = round(48 + idx * 3 - (hrv - 65) * 0.2 + rng.gauss(0, 1.5), 1)

            # Малко липсващи wellness данни, както в реалността
            if rng.random() < 0.07:
                hrv = None
            if rng.random() < 0.05:
                sleep_secs = None

            upsert_daily_metric(
                athlete_id, name, day.isoformat(),
                ctl=round(ctl, 1), atl=round(atl, 1),
                acwr=acwr, acwr_status=acwr_status(acwr),
                hrv=hrv, sleep_secs=sleep_secs,
                stress=stress, resting_hr=resting_hr,
            )

            if acwr and acwr > 1.3 and rng.random() < 0.4:
                log_alert(athlete_id, name, day.isoformat(), "acwr_high",
                          f"ACWR {acwr} — повишен риск от претрениране")

        # Седмична история на ранкинга (с backdate на fetched_at)
        conn = sqlite3.connect(DB_PATH)
        world = 150 + idx * 87
        regional = 4 + idx * 3
        for week in range(DAYS // 7, -1, -1):
            fetched = (today - timedelta(days=week * 7)).isoformat() + " 06:00:00"
            world = max(1, world + rng.choice([-8, -4, -2, 0, 0, 2, 5]))
            regional = max(1, regional + rng.choice([-1, 0, 0, 0, 1]))
            conn.execute(
                "INSERT OR IGNORE INTO world_triathlon "
                "(athlete_id, athlete_name, world_ranking, regional_ranking, fetched_at) "
                "VALUES (?, ?, ?, ?, ?)",
                (wt_id, name, world, regional, fetched))
        conn.commit()
        conn.close()
        print(f"Seeded {DAYS + 1} days for {name}")


if __name__ == "__main__":
    seed()
