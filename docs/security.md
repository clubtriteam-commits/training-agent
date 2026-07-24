# Security

## Резюме (Executive Summary)

Дашбордът защитава достъпа с една обща парола (не индивидуални акаунти) и сесийна бисквитка, валидна 30 дни. Тайните (API ключове, пароли) се пазят в файлове, изключени от git — никога не влизат в хранилището на кода. **Важно и честно:** към момента на писане на този документ, проверката за парола на живия сайт е временно изключена (за тестови цели, вижте бележката по-долу) — dashboard-ът е публично достъпен без парола. Това трябва да се върне обратно преди да се разчита на този документ като описание на реалната защита в момента.

---

## ⚠️ Current live status (as of this writing)

**The password check is currently bypassed on production.** `dashboard-backup/includes/auth.php`'s `require_login()` has an early `return;` inserted, deliberately, for interactive testing during active development. This is documented honestly here rather than glossed over — the rest of this document describes the **intended/normal** security model, which is not what's currently enforced live.

To check whether the bypass is still active: `curl` `dashboard.php` on the production URL with no session cookie — a `200` response with dashboard content (instead of a `302` redirect to `index.php`) means it's still open. To revert: remove the early `return;` from `require_login()` in `includes/auth.php` and redeploy via `deploy.ps1`.

This has happened twice in this project's history (2026-07-23, 2026-07-24) as a recurring "I need to test without re-logging-in" workaround. It is not committed to git either time (kept as a local, uncommitted change specifically so it can't accidentally ship via a normal `git pull`-based deploy) — but `deploy.ps1` deploys whatever's in the local working copy regardless of git status, so it *does* reach production. See [runbook.md](runbook.md) for the "how do I tell if this is on" checklist.

## Auth model

- **Single shared password**, not per-user accounts. Stored in `config/secrets.env` as `DASHBOARD_PASSWORD` (plain text — see risks below).
- `index.php` compares the submitted password against the file's value with `===` (exact string match, not a hash comparison) and sets `$_SESSION['logged_in'] = true` on success.
- Session cookie lifetime: 30 days (`session.gc_maxlifetime` + `session_set_cookie_params()`, both set in `includes/auth.php` before `session_start()`). See [ADR 0006](adr/0006-session-cookie-lifetime.md) for why this has to be configured *identically and early* on every entry point, including the login page itself.
- `require_login()` (in `includes/auth.php`) is the single gate — every page that should require a session calls it immediately after including the file. It redirects (`302` to `index.php`) on failure.

## API endpoint protection

`api_lactate.php` is the one JSON endpoint in the system (see [data-model.md](data-model.md#api_lactatephp--the-one-real-api-in-this-codebase)) and **does not call `require_login()`** — deliberately. `require_login()`'s redirect-on-failure behavior is wrong for a `fetch()` target (the browser would silently follow the redirect and hand the JS a login page's HTML instead of JSON, or in the case of `fetch()`, an opaque failure). Instead it checks `$_SESSION['logged_in']` directly and returns a `401` JSON body.

**Consequence of the current bypass:** because this check is independent of `require_login()`, `api_lactate.php` is **not** covered by the auth bypass described above — it still enforces the real session check even while every HTML page is open. An anonymous visitor can load `lactate_analysis.php` (the page shell) but the chart won't populate, since its `fetch()` to `api_lactate.php` gets `401`ed and the page redirects to `index.php`.

## Secrets management

| Location | Contents | In git? |
|---|---|---|
| `config/secrets.env` | `DASHBOARD_PASSWORD`, `INTERVALS_API_KEY`, `WORLD_TRIATHLON_API_KEY`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`, `LACTATE_SHEET_ID` | **No** — `.gitignore`d. Loaded via `python-dotenv`; PHP reads it with a regex (`index.php`) rather than a proper `.env` parser. |
| `config/google-service-account.json` | Google Cloud service account private key, used by `fetch_lab_data.py` to authenticate to Sheets/Drive APIs | **No** — `.gitignore`d. `chmod 600` on the server. |
| `deploy.config.psd1` | SSH host/user/port, optional path to a local SSH private key | **No** — `.gitignore`d (`deploy.config.example.psd1` is the checked-in template with placeholder values). |
| SSH private key (referenced by `deploy.config.psd1`'s `KeyFile`) | Passwordless SSH auth for `deploy.ps1` | Lives outside the repo entirely (`~/.ssh/`), never touches git. |

**`LACTATE_SHEET_ID` is not itself sensitive** (a Sheet ID is not a credential — access is controlled by Google's sharing permissions, not by knowing the ID) but lives alongside real secrets in the same file for convenience.

### Google service account scope

`fetch_lab_data.py` requests **`spreadsheets.readonly`** scope only — the routine sync can never write to the source Sheet, regardless of bugs in the sync code. When a lactate-test data-entry error needed manual correction directly in the Sheet (has happened — see [ADR 0003](adr/0003-google-sheets-lab-source.md)), that required a separate, temporary, manually-authorized script requesting the broader `spreadsheets` (read-write) scope for that one operation — not a standing capability.

## Known risks

- **Plain-text password**, both at rest (`secrets.env`) and in comparison logic (`===`, no hashing). Acceptable for a single-shared-password model used by a small trusted group, but means anyone who can read `secrets.env` (anyone with server file access) has the literal login password, and a leaked `secrets.env` (e.g. accidental `git add -f`, though `.gitignore`d) is immediately exploitable with no cracking required.
- **No rate limiting on login.** `index.php` accepts unlimited password attempts with no lockout, throttling, or CAPTCHA. The production WAF (see [ADR 0007](adr/0007-limitations.md)) may incidentally rate-limit at the infrastructure level for non-browser traffic, but this isn't a designed protection and shouldn't be relied on as one.
- **No explicit HTTPS enforcement in application code.** The site is served over HTTPS in practice (hosting-level), but nothing in `index.php`/`auth.php` checks `$_SERVER['HTTPS']` or redirects an accidental plain-HTTP request — if the hosting's HTTPS redirect were ever misconfigured, session cookies could be transmitted in the clear with no application-level safety net. `session_set_cookie_params()` does not set the `secure` flag.
- **Session fixation / no session regeneration on login.** `index.php` doesn't call `session_regenerate_id()` after a successful password check — the pre-login session ID continues to be the post-login (authenticated) session ID. Low practical risk given the deployment's size and threat model, but a textbook hardening gap.
- **The recurring auth-bypass workaround** (see top of this document) is itself the most acute current risk — it's a manual, easy-to-forget step, and there's no automated check or alert that would catch "the bypass has been live on production for N days." A [runbook.md](runbook.md) entry exists specifically to make checking for it a habit.
