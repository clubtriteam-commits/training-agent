"""
Детекция на оплаквания в коментарите (description) на активностите.
Търси корени на думи от config/keywords.yaml (bg + en) като case-insensitive
substring. Всяка активност се проверява само веднъж — проследяване през
таблица seen_activities.
"""
import sys
import os
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from datetime import date

import yaml

from storage.db import mark_activity_seen, log_alert

KEYWORDS_PATH = os.path.join(
    os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
    'config', 'keywords.yaml'
)

QUOTE_MAX_CHARS = 100

_keywords_cache = None


def load_keywords():
    """Чете config/keywords.yaml и връща плосък списък от всички корени (bg + en)."""
    with open(KEYWORDS_PATH, 'r', encoding='utf-8') as f:
        data = yaml.safe_load(f)
    keywords = []
    for lang_keywords in (data.get('pain_keywords') or {}).values():
        keywords.extend(lang_keywords or [])
    return keywords


def check_comment_for_keywords(comment_text):
    """Връща списък от намерените корени в текста (case-insensitive substring),
    празен списък при липса на съвпадение или празен текст."""
    global _keywords_cache
    if not comment_text:
        return []
    if _keywords_cache is None:
        _keywords_cache = load_keywords()
    text = comment_text.lower()
    return [kw for kw in _keywords_cache if kw.lower() in text]


def analyze_new_activities(athlete_id, athlete_name, activities_list):
    """Проверява description на всички невиждани досега активности за keywords.
    Връща списък аларми (и ги записва в alerts_log)."""
    alerts = []

    for activity in activities_list or []:
        activity_id = activity.get('id')
        if activity_id is None:
            continue

        # False = вече проверена при предишно пускане — прескачаме
        if not mark_activity_seen(athlete_id, activity_id):
            continue

        found = check_comment_for_keywords(activity.get('description'))
        if not found:
            continue

        # Цитат: нормализирани интервали, до QUOTE_MAX_CHARS символа
        quote = ' '.join(activity.get('description').split())
        if len(quote) > QUOTE_MAX_CHARS:
            quote = quote[:QUOTE_MAX_CHARS] + '…'

        activity_date = (activity.get('start_date_local') or '')[:10] or date.today().isoformat()

        msg = (f"🩹 {athlete_name}: възможно оплакване в коментар към тренировка "
               f"({activity_date}): „{quote}“ — ключови думи: {', '.join(found)}")
        alerts.append(msg)
        log_alert(athlete_id, athlete_name, activity_date, 'comment_keyword', msg)

    return alerts
