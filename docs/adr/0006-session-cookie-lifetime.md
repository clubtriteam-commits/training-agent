# ADR 0006: Session cookie lifetime bootstrapped from the login page

## Резюме

Потребителите се оплакваха, че системата ги "изхвърля" и иска паролата отново, въпреки че сесията би трябвало да трае 30 дни. Причината: страницата за вход (`index.php`) създаваше сесията с настройки по подразбиране на PHP (кратък живот), не с 30-дневните настройки, които всички ОСТАНАЛИ страници очакваха — тя ги задаваше едва при следващото зареждане, когато вече може да е късно.

## Status

Accepted (commit `8bbdd8c`).

## Context

`includes/auth.php` calls `ini_set('session.gc_maxlifetime', 30 days)` and `session_set_cookie_params(30 days)` **before** `session_start()`, so every page that `require_once`s it gets a consistently long-lived session cookie. This worked correctly for `dashboard.php`, `athlete.php`, `lactate_analysis.php`, `api_lactate.php`, `logout.php` — all of them include `auth.php`.

`index.php` (the login form itself, where `$_SESSION['logged_in'] = true` first gets set) did **not** include `auth.php` — it called a bare `session_start()` with no cookie-lifetime configuration at all. This meant the session cookie was *first created* under PHP's platform default (commonly a session-only cookie, or a short `session.gc_maxlifetime` — observed as PHP 8.0's shared-hosting default on production). Only on the *next* page load (e.g. `dashboard.php` right after login) would the cookie's parameters get "upgraded" to 30 days — by which point the original short-lived session might already be gone (browser closed the tab, or the server garbage-collected the session file), producing exactly the symptom reported: users logging in successfully, then getting bounced back to the login form on a subsequent visit far sooner than 30 days.

## Decision

Route `index.php` (and `logout.php`, for consistency, though it matters less there since it's about to destroy the session anyway) through `includes/auth.php` instead of a bare `session_start()`. This guarantees the *very first* `session_start()` call for any given session — the one that actually creates the cookie the browser will hold onto — already carries the correct 30-day configuration.

## Consequences

**Positive:**
- Verified locally and in production: `Set-Cookie: ...; Max-Age=2592000` is present on the login `POST` response itself, not just on subsequent page loads.
- No page-specific behavior differs — `index.php` doesn't call `require_login()` (it's the login page), it only shares the session-bootstrapping half of `auth.php`.

**Negative / accepted trade-offs:**
- None identified — this was a straightforward bug fix, not a design trade-off. Included as an ADR because the *failure mode* (silent, inconsistent session configuration across entry points) is a pattern worth recognizing if a new PHP entry point is ever added: **any file that calls `session_start()` without going through `includes/auth.php` will reintroduce this bug.**
