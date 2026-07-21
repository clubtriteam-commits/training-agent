"""
Проверява acquire_lock() в main.py: конкурентно изпълнение излиза веднага
вместо да чака или да обработи същия прозорец втори път.

POSIX-only (fcntl) — предназначен да се пусне на production сървъра
(Linux/cron), не на локалната Windows машина за разработка.

    python3 test_lock_concurrency.py
"""
import os
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import main as main_module

if main_module.fcntl is None:
    print("SKIPPED - fcntl липсва на тази платформа (non-POSIX)")
    sys.exit(0)


def test_concurrent_run_exits_immediately():
    # "Run 1" държи lock-а в текущия процес — симулира вече вървящ main.py
    held_lock = main_module.acquire_lock()
    assert held_lock is not None, "acquire_lock() трябваше да върне отворен файл на POSIX"

    try:
        # "Run 2" се опитва да вземе същия lock, докато Run 1 го държи
        result = subprocess.run(
            [sys.executable, "-c", "import main; main.acquire_lock(); print('LOCK_ACQUIRED')"],
            cwd=os.path.dirname(os.path.abspath(__file__)),
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            universal_newlines=True, timeout=15,
        )

        assert result.returncode == 0, \
            f"Очаквах чист изход (0) при заето заключване, взех {result.returncode}: {result.stderr}"
        assert "⛔" in result.stdout, \
            f"Очаквах съобщение за заето заключване в stdout, взех: {result.stdout!r}"
        assert "LOCK_ACQUIRED" not in result.stdout, \
            "Run 2 не трябваше да успее да вземе заключването, докато Run 1 го държи"

        print("  OK - конкурентно изпълнение излиза веднага, не чака и не дублира")
    finally:
        main_module.fcntl.flock(held_lock, main_module.fcntl.LOCK_UN)
        held_lock.close()
        if os.path.exists(main_module.LOCK_PATH):
            os.remove(main_module.LOCK_PATH)


if __name__ == '__main__':
    test_concurrent_run_exits_immediately()
    print("\nВсички тестове минаха.")
