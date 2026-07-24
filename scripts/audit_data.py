"""
Data integrity audit — standalone, read-only checks across data/agent.db.

Не променя нищо в базата. Пуска се ръчно, при съмнение за разминаване
между таблиците (виж docs/data-model.md за пълния модел, docs/runbook.md
за конкретни инциденти, довели до нуждата от този скрипт):

    python scripts/audit_data.py
    python scripts/audit_data.py --stale-days 5

Проверки:
  1. Orphan records — редове, чийто athlete_id/athlete_name не отговаря
     на нито един атлет в config/athletes.yaml.
  2. Athlete_name consistency — един и същ athlete_id да не се среща
     под различни имена в различни редове (виж ADR 0004).
  3. alert_events vs alerts_log drift — стари alerts_log редове без
     съответен alert_events ред (миграцията трябваше да ги прехвърли
     всички еднократно; нов такъв ред би значел бъг или писане в
     остарялата таблица).
  4. Lactate step continuity — celi ("дупки") в попълнените стъпки на
     лактатен тест: празна стъпка, последвана от попълнена по-нататък.
  5. Wellness data freshness — атлети, за които daily_metrics не се е
     обновявал скоро (възможен признак, че fetch-ът за този атлет чупи
     тихо, докато останалите вървят нормално).

Изходен код: 0 ако няма находки, 1 ако има поне едно предупреждение —
удобно за бъдеща автоматизация (напр. cron ред, който маха резултата
в Telegram само при находки), макар да не е окачествено така в момента.
"""
import argparse
import os
import sys
from datetime import date, datetime

import yaml

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from storage.db import get_connection

# Windows конзолата по подразбиране е cp1252 и не поддържа кирилица в print() —
# при ръчно пускане (Linux/cron няма нужда, там stdout вече е UTF-8).
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

ATHLETES_YAML = os.path.join(
    os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
    'config', 'athletes.yaml'
)

DEFAULT_STALE_DAYS = 3


def load_athletes():
    with open(ATHLETES_YAML, 'r', encoding='utf-8') as f:
        config = yaml.safe_load(f)
    return config.get('athletes') or []


class Report:
    """Събира находки по секция; печата ги четимо накрая."""

    def __init__(self):
        self.sections = []

    def section(self, title):
        entries = []
        self.sections.append((title, entries))
        return entries

    def print_and_exit_code(self):
        total_findings = 0
        print("=" * 72)
        print("Data integrity audit — data/agent.db")
        print(f"Run at: {datetime.now().isoformat(timespec='seconds')}")
        print("=" * 72)

        for title, entries in self.sections:
            print(f"\n## {title}")
            if not entries:
                print("  OK — няма находки.")
            else:
                for entry in entries:
                    print(f"  ⚠️  {entry}")
                total_findings += len(entries)

        print("\n" + "=" * 72)
        if total_findings == 0:
            print("Обобщение: 0 находки. Всичко изглежда консистентно.")
            return 0
        else:
            print(f"Обобщение: {total_findings} находки — виж секциите по-горе.")
            return 1


def check_orphans(conn, athletes, report):
    entries = report.section("1. Orphan records (athlete_id/athlete_name без съответствие в athletes.yaml)")

    known_intervals_ids = {a['intervals_id'] for a in athletes if a.get('intervals_id')}
    known_wt_ids = {a['world_triathlon_id'] for a in athletes if a.get('world_triathlon_id')}
    known_names = {a['name'] for a in athletes}

    cur = conn.cursor()

    for table in ('daily_metrics', 'alert_events', 'seen_activities'):
        cur.execute(f"SELECT DISTINCT athlete_id FROM {table}")
        for row in cur.fetchall():
            if row['athlete_id'] not in known_intervals_ids:
                entries.append(
                    f"{table}: athlete_id '{row['athlete_id']}' не е в config/athletes.yaml (intervals_id)"
                )

    for table in ('world_triathlon', 'world_triathlon_results'):
        cur.execute(f"SELECT DISTINCT athlete_id FROM {table}")
        for row in cur.fetchall():
            if row['athlete_id'] not in known_wt_ids:
                entries.append(
                    f"{table}: athlete_id '{row['athlete_id']}' не е в config/athletes.yaml (world_triathlon_id)"
                )

    cur.execute("SELECT DISTINCT athlete_name FROM lactate_tests")
    for row in cur.fetchall():
        if row['athlete_name'] not in known_names:
            entries.append(
                f"lactate_tests: athlete_name '{row['athlete_name']}' не е в config/athletes.yaml — "
                f"проверка за печатна грешка в Sheet-а или изтрит/преименуван атлет (виж ADR 0003/0004)"
            )


def check_name_consistency(conn, athletes, report):
    entries = report.section("2. Athlete_name consistency (един athlete_id -> повече от едно име)")
    cur = conn.cursor()

    id_to_names = {}
    for table, id_col in (
        ('daily_metrics', 'athlete_id'),
        ('world_triathlon', 'athlete_id'),
        ('world_triathlon_results', 'athlete_id'),
    ):
        cur.execute(f"SELECT DISTINCT {id_col}, athlete_name FROM {table}")
        for row in cur.fetchall():
            key = (table, row[id_col])
            names = id_to_names.setdefault(key, set())
            names.add(row['athlete_name'])

    for (table, athlete_id), names in id_to_names.items():
        if len(names) > 1:
            entries.append(
                f"{table}: athlete_id '{athlete_id}' се среща с {len(names)} различни имена: "
                f"{', '.join(sorted(names))} — вероятно преименуване в config/athletes.yaml, "
                f"без обновяване на историческите редове (виж ADR 0004)"
            )

    # Обратна проверка: едно име в config, споделено случайно от два различни ID
    # в дадена таблица (напр. два реда с различен athlete_id, но еднакво име).
    for table, id_col in (('daily_metrics', 'athlete_id'), ('world_triathlon', 'athlete_id')):
        cur.execute(f"SELECT DISTINCT {id_col}, athlete_name FROM {table}")
        name_to_ids = {}
        for row in cur.fetchall():
            name_to_ids.setdefault(row['athlete_name'], set()).add(row[id_col])
        for name, ids in name_to_ids.items():
            if len(ids) > 1:
                entries.append(
                    f"{table}: името '{name}' се среща с {len(ids)} различни ID-та: "
                    f"{', '.join(sorted(ids))} — възможно дублиране на атлет"
                )


def check_alerts_log_drift(conn, report):
    entries = report.section("3. alert_events vs alerts_log drift")
    cur = conn.cursor()

    cur.execute("SELECT athlete_id, date, alert_type, message, sent_at FROM alerts_log")
    log_rows = cur.fetchall()

    if not log_rows:
        return

    cur.execute("SELECT athlete_id, event_date, alert_type FROM alert_events")
    migrated = {(r['athlete_id'], r['event_date'], r['alert_type']) for r in cur.fetchall()}

    unmigrated = 0
    for row in log_rows:
        key = (row['athlete_id'], row['date'], row['alert_type'])
        if key not in migrated:
            unmigrated += 1
            entries.append(
                f"alerts_log ред без съответствие в alert_events: athlete_id={row['athlete_id']}, "
                f"date={row['date']}, type={row['alert_type']}, sent_at={row['sent_at']} — "
                f"или migrate_alerts.py не е пуснат за този ред, или нещо пак пише в alerts_log "
                f"(вижте migrate_alerts.py — никой код не би трябвало да прави това след ADR 0001/0002)"
            )

    if unmigrated:
        entries.append(
            f"Общо {unmigrated}/{len(log_rows)} alerts_log реда без съответствие — "
            f"пусни migrate_alerts.py, ако липсват само исторически (очаквано еднократно), "
            f"или разследвай кой пише в alerts_log, ако продължават да се появяват нови."
        )


def check_lactate_step_continuity(conn, report):
    entries = report.section("4. Lactate test step continuity (дупки в стъпките)")
    cur = conn.cursor()
    cur.execute("SELECT * FROM lactate_tests ORDER BY athlete_name, test_date")

    for row in cur.fetchall():
        filled = []
        for i in range(1, 11):
            has_data = row[f'step{i}_hr'] is not None or row[f'step{i}_la'] is not None
            filled.append(has_data)

        # Легитимен модел: стъпки 1..N попълнени, N+1..10 празни (тест спрян рано).
        # Находка: празна стъпка, СЛЕДВАНА от попълнена — истинска дупка, не край на теста.
        seen_gap = False
        for i in range(len(filled) - 1):
            if not filled[i] and any(filled[i + 1:]):
                seen_gap = True
                break

        if seen_gap:
            steps_desc = ''.join('X' if f else '.' for f in filled)
            entries.append(
                f"{row['athlete_name']} / {row['test_date']} (id={row['id']}): "
                f"дупка в стъпките [{steps_desc}] (X=налична, .=празна) — "
                f"вероятна грешка при въвеждане в Sheet-а, не легитимен 'тест спрян рано'"
            )


def check_wellness_freshness(conn, athletes, stale_days, report):
    entries = report.section(f"5. Wellness data freshness (праг: {stale_days}+ дни без обновяване)")
    cur = conn.cursor()
    today = date.today()

    for athlete in athletes:
        athlete_id = athlete.get('intervals_id')
        if not athlete_id:
            continue
        cur.execute(
            "SELECT MAX(date) AS latest FROM daily_metrics WHERE athlete_id = ?",
            (athlete_id,)
        )
        row = cur.fetchone()
        latest = row['latest'] if row else None

        if latest is None:
            entries.append(f"{athlete['name']} ({athlete_id}): няма нито един daily_metrics ред изобщо")
            continue

        latest_date = datetime.strptime(latest, '%Y-%m-%d').date()
        age_days = (today - latest_date).days
        if age_days >= stale_days:
            entries.append(
                f"{athlete['name']} ({athlete_id}): последни данни от {latest} "
                f"({age_days} дни назад) — проверете дали fetch-ът за този атлет чупи тихо "
                f"(виж logs/cron.log за грешки, специфични за него)"
            )


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--stale-days', type=int, default=DEFAULT_STALE_DAYS,
                        help=f"Праг за 'остарели' wellness данни, в дни (по подразбиране {DEFAULT_STALE_DAYS})")
    args = parser.parse_args()

    athletes = load_athletes()
    conn = get_connection()
    report = Report()

    try:
        check_orphans(conn, athletes, report)
        check_name_consistency(conn, athletes, report)
        check_alerts_log_drift(conn, report)
        check_lactate_step_continuity(conn, report)
        check_wellness_freshness(conn, athletes, args.stale_days, report)
    finally:
        conn.close()

    exit_code = report.print_and_exit_code()
    sys.exit(exit_code)


if __name__ == '__main__':
    main()
