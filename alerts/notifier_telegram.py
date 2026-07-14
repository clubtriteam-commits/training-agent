"""
Telegram notifier - праща съобщения до треньора.
"""
import os
import requests
from dotenv import load_dotenv

load_dotenv('config/secrets.env')

BOT_TOKEN = os.getenv('TELEGRAM_BOT_TOKEN')
CHAT_ID = os.getenv('TELEGRAM_CHAT_ID')


def send_telegram_message(text):
    if not BOT_TOKEN or not CHAT_ID:
        print("⚠️ Липсва TELEGRAM_BOT_TOKEN или TELEGRAM_CHAT_ID в secrets.env")
        return False

    url = f"https://api.telegram.org/bot{BOT_TOKEN}/sendMessage"
    payload = {
        "chat_id": CHAT_ID,
        "text": text,
        "parse_mode": "HTML"
    }

    response = requests.post(url, data=payload)

    if response.status_code == 200:
        return True
    else:
        print(f"Telegram грешка: {response.status_code} - {response.text}")
        return False


def send_alerts_batch(alerts_list):
    """Праща списък от аларми - обединени в едно съобщение, ако са малко, или поотделно"""
    if not alerts_list:
        return

    if len(alerts_list) <= 5:
        message = "\n".join(alerts_list)
        send_telegram_message(message)
    else:
        for alert in alerts_list:
            send_telegram_message(alert)


if __name__ == '__main__':
    # Тестово съобщение
    success = send_telegram_message("🤖 Training Agent тест - връзката работи!")
    if success:
        print("Съобщението е пратено успешно, провери Telegram.")
    else:
        print("Пращането се провали.")
