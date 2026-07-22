"""
Главен orchestrator - тегли данни, изчислява метрики, праща аларми в Telegram.
Пуска се през cron (дневно за аларми, седмично за пълен summary).
"""
import os
import sys
import traceback

import yaml
from fetch_intervals import get_wellness, get_activities
from metrics.acwr import analyze_athlete_acwr
from metrics.readiness import analyze_readiness
from metrics.comment_alerts import analyze_new_activities
from metrics.late_start import analyze_late_starts
from storage.db import filter_new_activities, get_undelivered_events, mark_delivered
from alerts.notifier_telegram import send_telegram_message

# fcntl е POSIX-only — на production сървъра (Linux, cron) е налично,
# затова file lock-ът пази реален смисъл; на не-POSIX платформи acquire_lock()
# просто прескача заключването (main.py не се пуска в production никъде другаде).
try:
    import fcntl
except ImportError:
    fcntl = None

# git не следи празни директории — logs/ може да липсва след .gitignore
# промяна или fresh clone, което поваля cron redirect-а тихо.
os.makedirs('logs', exist_ok=True)

LOCK_PATH = os.path.join('logs', 'main.lock')

# При False: fetch/ACWR/readiness/keyword/late-start продължават да текат
# нормално и алармите пак се записват в alert_events (базата и dashboard-ът
# остават актуални), но deliver_pending_alerts() ги маркира delivered веднага
# БЕЗ да ги праща в Telegram (само weekly_summary.py праща там). Смени на
# True, за да се върнат дневните Telegram пушове.
DAILY_TELEGRAM_ALERTS = True

# Стъпките след "fetch" за конкретен атлет — реда, в който се отбелязват
# като прескочени, ако fetch-ът на wellness се провали.
STEPS_AFTER_FETCH = ("ACWR", "readiness", "keyword", "late-start")


def acquire_lock():
    """Взима ексклузивен non-blocking lock върху logs/main.lock.

    Ако друго изпълнение вече го държи, излиза веднага (вместо да чака) —
    предпазва от препокриващи се cron run-ове, ако предишният е забавен."""
    if fcntl is None:
        return None
    lock_file = open(LOCK_PATH, 'w')
    try:
        fcntl.flock(lock_file, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        print("⛔ Друго изпълнение на main.py вече върви — излизам.")
        lock_file.close()
        sys.exit(0)
    return lock_file


def deliver_pending_alerts():
    """Доставя всички недоставени alert_events - независимо от detection
    стъпките по-горе. Работи и върху събития от предишни run-ове: ако
    Telegram гръмне, редът остава delivered_at IS NULL и се пробва пак тук
    при следващото пускане — доставката е retry-safe."""
    pending = get_undelivered_events()

    if not pending:
        print("Няма недоставени аларми.")
        return

    if DAILY_TELEGRAM_ALERTS:
        print(f"Доставям {len(pending)} недоставени аларми в Telegram...")
        sent = 0
        for event in pending:
            if send_telegram_message(event['message']):
                mark_delivered(event['id'])
                sent += 1
            else:
                print(f"  ❌ Telegram грешка — {event['athlete_name']} | {event['alert_type']} "
                      f"({event['event_date']}) остава недоставена, ще се опита пак следващия run")
        print(f"Доставени: {sent}/{len(pending)}")
    else:
        for event in pending:
            print(f"  {event['athlete_name']} | {event['alert_type']} "
                  f"({event['event_date']}): {event['message']}")
            mark_delivered(event['id'])
        print(f"{len(pending)} аларми маркирани като доставени "
              f"(DAILY_TELEGRAM_ALERTS=False — Telegram push изключен за дневните аларми)")


def log_step(athlete_name, step, ok, detail=""):
    """Единен формат за статус на стъпка: ✅/❌ Атлет | стъпка — детайл.

    Прави cron.log grep-ваем по атлет ("грешка при Мира") или по стъпка
    ("❌ ... | ACWR"), вместо генерични съобщения без контекст.
    """
    icon = "✅" if ok else "❌"
    suffix = f" — {detail}" if detail else ""
    print(f"{icon} {athlete_name} | {step}{suffix}")


def log_step_error(athlete_name, step, exc):
    """При изключение: ясен ред за атлет+стъпка, после traceback за дебъг.

    Изключението се хваща тук вместо да пропадне нагоре — иначе една
    провалена стъпка (напр. мрежова грешка при един атлет) би спряла
    целия cron run и би оставила остатъка от атлетите необработени.
    """
    log_step(athlete_name, step, False, f"{type(exc).__name__}: {exc}")
    print(traceback.format_exc())


def run_daily_check():
    with open('config/athletes.yaml', 'r', encoding='utf-8') as f:
        config = yaml.safe_load(f)

    all_alerts = []

    for athlete in config['athletes']:
        name = athlete['name']

        # --- Fetch wellness (нужен и за ACWR, и за readiness) ---
        try:
            status, wellness = get_wellness(athlete['intervals_id'])
            if status != 200:
                raise RuntimeError(f"wellness HTTP {status}")
        except Exception as e:
            log_step_error(name, "fetch", e)
            for step in STEPS_AFTER_FETCH:
                log_step(name, step, False, "прескочено — fetch се провали")
            continue
        log_step(name, "fetch", True, f"{len(wellness)} wellness записа")

        # --- ACWR (записва в базата + връща аларми при преход) ---
        try:
            results, acwr_alerts = analyze_athlete_acwr(
                wellness, athlete['intervals_id'], name,
                rest_period=athlete.get('rest_period')
            )
            log_step(name, "ACWR", True, f"{len(results)} дни обработени")
            all_alerts.extend(acwr_alerts)
        except Exception as e:
            log_step_error(name, "ACWR", e)

        # --- Readiness (HRV/сън/стрес) - чете от базата, която acwr вече е обновил ---
        try:
            readiness_alerts = analyze_readiness(athlete['intervals_id'], name)
            log_step(name, "readiness", True,
                     f"{len(readiness_alerts)} аларми" if readiness_alerts else "без отклонения")
            all_alerts.extend(readiness_alerts)
        except Exception as e:
            log_step_error(name, "readiness", e)

        # --- Активности: обща fetch + дедупликация за keyword и late-start ---
        try:
            act_status, activities = get_activities(athlete['intervals_id'])
            if act_status != 200:
                raise RuntimeError(f"activities HTTP {act_status}")
            new_activities = filter_new_activities(athlete['intervals_id'], activities)
        except Exception as e:
            log_step_error(name, "keyword", e)
            log_step_error(name, "late-start", e)
            continue

        # Оплаквания в заглавие/коментар
        try:
            keyword_alerts = analyze_new_activities(
                athlete['intervals_id'], name, new_activities
            )
            log_step(name, "keyword", True,
                     f"{len(keyword_alerts)} намерени" if keyword_alerts
                     else f"{len(new_activities)} нови активности, чисто")
            all_alerts.extend(keyword_alerts)
        except Exception as e:
            log_step_error(name, "keyword", e)

        # Късно започнали тренировки (информативно)
        try:
            late_alerts = analyze_late_starts(
                athlete['intervals_id'], name, new_activities
            )
            log_step(name, "late-start", True,
                     f"{len(late_alerts)} намерени" if late_alerts else "без закъснели")
            all_alerts.extend(late_alerts)
        except Exception as e:
            log_step_error(name, "late-start", e)

    if all_alerts:
        print(f"{len(all_alerts)} нови аларми открити днес.")
    else:
        print("Няма нови аларми за днес.")

    deliver_pending_alerts()


if __name__ == '__main__':
    lock_file = acquire_lock()
    try:
        run_daily_check()
    finally:
        if lock_file is not None:
            fcntl.flock(lock_file, fcntl.LOCK_UN)
            lock_file.close()
