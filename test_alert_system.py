"""
Ad-hoc проверки за alert_events системата (без pytest — прекия стил на
проекта е скриптове, пуснати директно с `python3 file.py`).

Всеки тест работи върху временна SQLite база (storage.db.DB_PATH се
пренасочва) и я трие след себе си — не пипа истинската data/agent.db.

    python3 test_alert_system.py
"""
import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import storage.db as db


def with_temp_db(test_fn):
    """Пренасочва storage.db.DB_PATH към временен файл за времетраенето на
    един тест, после го трие — тестовете не си влияят един на друг."""
    tmp_fd, tmp_path = tempfile.mkstemp(suffix=".db")
    os.close(tmp_fd)
    original_path = db.DB_PATH
    db.DB_PATH = tmp_path
    try:
        db.init_db()
        test_fn()
    finally:
        db.DB_PATH = original_path
        os.remove(tmp_path)


def test_dedup_across_runs():
    """Две последователни пускания над същия wellness прозорец — второто
    не създава нова аларма (симулира ежедневния cron, преизчисляващ
    последните 14 дни)."""
    from metrics.acwr import analyze_athlete_acwr

    conn = db.get_connection()
    cur = conn.cursor()
    cur.execute('''
        INSERT INTO daily_metrics (athlete_id, athlete_name, date, ctl, atl, acwr, acwr_status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ''', ('test1', 'Test Athlete', '2026-07-08', 50, 80, 1.6, 'high'))
    conn.commit()
    conn.close()

    wellness_list = [{'id': '2026-07-09', 'ctl': 100, 'atl': 90}]

    _, alerts1 = analyze_athlete_acwr(wellness_list, 'test1', 'Test Athlete')
    assert len(alerts1) == 1, f"Очаквах 1 аларма на run 1, взех {len(alerts1)}"

    _, alerts2 = analyze_athlete_acwr(wellness_list, 'test1', 'Test Athlete')
    assert len(alerts2) == 0, f"Очаквах 0 аларми на run 2 (dedup), взех {len(alerts2)}"

    print("  OK - dedup при две пускания над същия прозорец")


def test_unique_constraint_on_recalculation():
    """Преизчислена стойност за същата (athlete_id, event_date, alert_type,
    source_id) не създава втори ред — UNIQUE constraint-ът го гарантира,
    дори при различно message съдържание."""
    first = db.record_alert_event('test2', 'Test Athlete 2', '2026-07-10',
                                   'acwr_high', "ACWR 1.6 - висок риск")
    assert first is True, "Първият запис трябва да е нов"

    second = db.record_alert_event('test2', 'Test Athlete 2', '2026-07-10',
                                    'acwr_high', "ACWR 1.62 - преизчислен")
    assert second is False, "Преизчислена стойност за същия ден не трябва да създава нов ред"

    conn = db.get_connection()
    cur = conn.cursor()
    cur.execute('''
        SELECT COUNT(*) AS n FROM alert_events
        WHERE athlete_id = ? AND event_date = ? AND alert_type = ?
    ''', ('test2', '2026-07-10', 'acwr_high'))
    n = cur.fetchone()['n']
    conn.close()
    assert n == 1, f"Очаквах точно 1 ред, взех {n}"

    print("  OK - UNIQUE constraint блокира дублиране при преизчисление")


def test_retry_after_telegram_failure():
    """Ако send_telegram_message() върне False, събитието остава
    delivered_at IS NULL и се доставя при следващия опит (retry-safe)."""
    import main

    db.record_alert_event('test3', 'Test Athlete 3', '2026-07-11',
                           'acwr_high', "ACWR 1.7 - висок риск")

    original_sender = main.send_telegram_message
    original_flag = main.DAILY_TELEGRAM_ALERTS
    try:
        main.DAILY_TELEGRAM_ALERTS = True

        main.send_telegram_message = lambda text: False
        main.deliver_pending_alerts()
        pending = db.get_undelivered_events()
        assert len(pending) == 1, \
            f"След неуспешен Telegram опит очаквах 1 недоставена аларма, взех {len(pending)}"

        main.send_telegram_message = lambda text: True
        main.deliver_pending_alerts()
        pending = db.get_undelivered_events()
        assert len(pending) == 0, \
            f"След успешен retry очаквах 0 недоставени аларми, взех {len(pending)}"
    finally:
        main.send_telegram_message = original_sender
        main.DAILY_TELEGRAM_ALERTS = original_flag

    print("  OK - Telegram провал не губи алармата, retry я доставя")


def _seed_prev_day_status(athlete_id, date, status):
    conn = db.get_connection()
    cur = conn.cursor()
    cur.execute('''
        INSERT INTO daily_metrics (athlete_id, athlete_name, date, ctl, atl, acwr, acwr_status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ''', (athlete_id, 'Test Athlete', date, 50, 50, 1.0, status))
    conn.commit()
    conn.close()


def _alert_types_for(athlete_id, event_date):
    conn = db.get_connection()
    cur = conn.cursor()
    cur.execute('''
        SELECT alert_type FROM alert_events
        WHERE athlete_id = ? AND event_date = ?
    ''', (athlete_id, event_date))
    types = [row['alert_type'] for row in cur.fetchall()]
    conn.close()
    return types


def test_rest_period_suppresses_acwr_low():
    """Атлет с rest_period, покриващ event_date — нисък ACWR не генерира аларма."""
    from metrics.acwr import analyze_athlete_acwr

    _seed_prev_day_status('rest1', '2026-07-08', 'ok')
    rest_period = {'from': '2026-07-05', 'to': '2026-07-12'}

    # ctl=100, atl=70 -> acwr=0.7 -> 'low'
    wellness_list = [{'id': '2026-07-09', 'ctl': 100, 'atl': 70}]
    _, alerts = analyze_athlete_acwr(wellness_list, 'rest1', 'Test Athlete', rest_period=rest_period)

    assert len(alerts) == 0, f"Очаквах 0 аларми по време на почивка, взех {len(alerts)}"
    assert _alert_types_for('rest1', '2026-07-09') == [], \
        "acwr_low не трябваше да се записва в alert_events по време на почивка"

    print("  OK - rest_period потиска acwr_low")


def test_no_rest_period_generates_acwr_low_normally():
    """Атлет БЕЗ rest_period — нисък ACWR продължава да генерира аларма нормално."""
    from metrics.acwr import analyze_athlete_acwr

    _seed_prev_day_status('norest1', '2026-07-08', 'ok')

    wellness_list = [{'id': '2026-07-09', 'ctl': 100, 'atl': 70}]
    _, alerts = analyze_athlete_acwr(wellness_list, 'norest1', 'Test Athlete', rest_period=None)

    assert len(alerts) == 1, f"Очаквах 1 аларма без rest_period, взех {len(alerts)}"
    assert _alert_types_for('norest1', '2026-07-09') == ['acwr_low']

    print("  OK - без rest_period acwr_low се генерира нормално")


def test_acwr_high_ignores_rest_period():
    """acwr_high се генерира независимо от активна почивка — претоварване по време
    на планирана почивка е още по-важен сигнал, не по-малко."""
    from metrics.acwr import analyze_athlete_acwr

    _seed_prev_day_status('rest2', '2026-07-08', 'ok')
    rest_period = {'from': '2026-07-05', 'to': '2026-07-12'}

    # ctl=50, atl=90 -> acwr=1.8 -> 'high'
    wellness_list = [{'id': '2026-07-09', 'ctl': 50, 'atl': 90}]
    _, alerts = analyze_athlete_acwr(wellness_list, 'rest2', 'Test Athlete', rest_period=rest_period)

    assert len(alerts) == 1, f"Очаквах 1 acwr_high аларма въпреки почивката, взех {len(alerts)}"
    assert _alert_types_for('rest2', '2026-07-09') == ['acwr_high']

    print("  OK - acwr_high не се потиска от rest_period")


def main_test():
    tests = [
        test_dedup_across_runs,
        test_unique_constraint_on_recalculation,
        test_retry_after_telegram_failure,
        test_rest_period_suppresses_acwr_low,
        test_no_rest_period_generates_acwr_low_normally,
        test_acwr_high_ignores_rest_period,
    ]
    for test in tests:
        print(f"{test.__name__}...")
        with_temp_db(test)

    print("\nВсички тестове минаха.")


if __name__ == '__main__':
    main_test()
