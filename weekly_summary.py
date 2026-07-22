"""
Седмичен обобщителен отчет в Telegram — праща се ВИНАГИ, независимо от
преходите в статуса (за разлика от дневните аларми в main.py).

За всеки атлет от config/athletes.yaml:
  - последните 7 дни от daily_metrics (среден ACWR, тренд на CTL/ATL,
    последни HRV и сън, ако има данни)
  - последният world_triathlon запис, ако е обновен през седмицата
  - брой аларми през седмицата (alert_events)

Пуска се самостоятелно (cron, неделя 8:00):
    0 8 * * 0 cd /home/trailser/training-agent && python weekly_summary.py
"""
import os
import sys
from datetime import date, timedelta

import yaml

from storage.db import get_connection
from metrics.acwr import in_rest_period

# git не следи празни директории — logs/ може да липсва след .gitignore
# промяна или fresh clone, което поваля cron redirect-а тихо.
os.makedirs('logs', exist_ok=True)

# alerts/notifier_telegram.py съществува само на сървъра — локално
# скриптът само печата съобщението (dry run).
try:
    from alerts.notifier_telegram import send_alerts_batch
    TELEGRAM_AVAILABLE = True
except ImportError:
    TELEGRAM_AVAILABLE = False


def acwr_emoji(acwr, suppress_low=False):
    # Праговете следват metrics/acwr.py: безопасна зона 0.8–1.5.
    # suppress_low=True за атлет в rest_period — нисък ACWR е очакван, не аларма.
    if acwr is None:
        return "❔"
    if acwr < 0.8:
        return "😴" if suppress_low else "ℹ️"
    if acwr > 1.5:
        return "⚠️"
    return "✅"


def trend_arrow(first, last, threshold=1.0):
    if first is None or last is None:
        return ""
    if last - first > threshold:
        return "↗ расте"
    if first - last > threshold:
        return "↘ пада"
    return "→ стабилен"


def last_non_null(rows, key):
    for row in reversed(rows):
        if row[key] is not None:
            return row[key]
    return None


def athlete_week_summary(conn, athlete_id, athlete_name, since, rest_period=None, today=None):
    cur = conn.cursor()

    cur.execute('''
        SELECT date, ctl, atl, acwr, hrv, sleep_secs
        FROM daily_metrics
        WHERE athlete_id = ? AND date >= ?
        ORDER BY date ASC
    ''', (athlete_id, since))
    days = cur.fetchall()

    in_rest = today is not None and in_rest_period(today.isoformat(), rest_period)
    header = athlete_name
    if in_rest:
        header += f" (в планирана почивка до {rest_period['to']})"
    lines = [f"🏃 {header}"]

    if not days:
        lines.append("  • Няма данни за последните 7 дни")
    else:
        acwr_values = [d['acwr'] for d in days if d['acwr'] is not None]
        if acwr_values:
            avg_acwr = sum(acwr_values) / len(acwr_values)
            lines.append(f"  • Среден ACWR: {avg_acwr:.2f} {acwr_emoji(avg_acwr, suppress_low=in_rest)}")
        else:
            lines.append("  • ACWR: няма данни")

        ctl_first, ctl_last = days[0]['ctl'], days[-1]['ctl']
        atl_first, atl_last = days[0]['atl'], days[-1]['atl']
        load_parts = []
        if ctl_last is not None:
            load_parts.append(f"CTL {ctl_last:.1f} ({trend_arrow(ctl_first, ctl_last)})")
        if atl_last is not None:
            load_parts.append(f"ATL {atl_last:.1f} ({trend_arrow(atl_first, atl_last)})")
        if load_parts:
            lines.append("  • " + ", ".join(load_parts))

        wellness_parts = []
        hrv = last_non_null(days, 'hrv')
        if hrv is not None:
            wellness_parts.append(f"HRV {hrv:.0f}")
        sleep_secs = last_non_null(days, 'sleep_secs')
        if sleep_secs is not None:
            wellness_parts.append(f"сън {sleep_secs / 3600:.1f} ч")
        if wellness_parts:
            lines.append("  • " + ", ".join(wellness_parts))

    # Ранкинг — само ако е обновен през седмицата.
    # world_triathlon ползва World Triathlon ID, затова търсим по athlete_name.
    cur.execute('''
        SELECT world_ranking, regional_ranking
        FROM world_triathlon
        WHERE athlete_name = ? AND date(fetched_at) >= ?
        ORDER BY fetched_at DESC LIMIT 1
    ''', (athlete_name, since))
    ranking = cur.fetchone()
    if ranking:
        rank_parts = []
        if ranking['world_ranking'] is not None:
            rank_parts.append(f"#{ranking['world_ranking']} World")
        if ranking['regional_ranking'] is not None:
            rank_parts.append(f"#{ranking['regional_ranking']} Regional")
        if rank_parts:
            lines.append(f"  • Ranking: {' / '.join(rank_parts)}")

    cur.execute('''
        SELECT COUNT(*) AS cnt FROM alert_events
        WHERE athlete_id = ? AND event_date >= ?
    ''', (athlete_id, since))
    alert_count = cur.fetchone()['cnt']
    if alert_count:
        lines.append(f"  • Аларми през седмицата: {alert_count}")

    return "\n".join(lines)


def build_weekly_summary():
    with open('config/athletes.yaml', 'r', encoding='utf-8') as f:
        config = yaml.safe_load(f)

    today = date.today()
    since = (today - timedelta(days=7)).isoformat()

    conn = get_connection()
    sections = [f"📊 Седмичен отчет ({since} – {today.isoformat()})"]
    for athlete in config['athletes']:
        sections.append(athlete_week_summary(
            conn, athlete['intervals_id'], athlete['name'], since,
            rest_period=athlete.get('rest_period'), today=today
        ))
    conn.close()

    return "\n\n".join(sections)


def main():
    message = build_weekly_summary()
    print(message)

    if TELEGRAM_AVAILABLE:
        send_alerts_batch([message])
        print("\n(изпратено в Telegram)")
    else:
        print("\n(alerts/notifier_telegram.py липсва локално — само печат, без изпращане)")


if __name__ == '__main__':
    main()
