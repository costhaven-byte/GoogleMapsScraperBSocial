# Testing

## Automated tests

```bash
php artisan test                 # PHP: feature + unit tests (SQLite in memory)
cd worker && npm test            # worker: config, options, reporter (no network/browser)
```

To run the PHP suite against MySQL instead, create an empty database. Real environment variables override `phpunit.xml`:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=gscraper_test DB_USERNAME=root DB_PASSWORD=secret php artisan test
```

| File | Covers |
|---|---|
| `tests/Feature/AuthTest.php` | sign-in, wrong password, deactivated accounts, login rate limiting, sign-out, mid-session deactivation, password reset (no account enumeration, token reset, password strength) |
| `tests/Feature/AuthorizationTest.php` | admin-only pages, shared run visibility, owner/admin-only delete and stop, active runs can't be deleted, re-process preconditions, role/status not mass-assignable |
| `tests/Feature/RunCreationTest.php` | search form parsing (comments, duplicates), `.txt` upload, rejection of PHP/PNG/oversized uploads, limits on searches/results/months |
| `tests/Feature/WorkerApiTest.php` | token auth and revocation, heartbeat usage whitelisting, oldest-first single claim, rate-limited workers only get re-process jobs, logs/progress/cancel flag, idempotent place and result uploads, paging, oversized/invalid payloads, counters and contactability table, ownership (404) and ended jobs (409), stale-run failing |
| `tests/Feature/ScrapeBudgetTest.php` | shared daily budget across workers on one public IP, separate budgets per connection, re-process and 24h-old scrapes excluded, exhausted connections only get re-process jobs, per-job cap, heartbeat budget and free-up time, dashboard display |
| `tests/Feature/ResultsTest.php` | run page tabs and filters, live progress panel, XSS escaping and `javascript:`/`data:` links suppressed, CSV formula neutralisation, cross-run leads de-duplication, insights, security headers, friendly 404 |
| `tests/Feature/ImportTest.php` | GMSCraper `leads.json` + `raw-places.jsonl` import, then re-process copies places; invalid files rejected |
| `tests/Feature/AdminTest.php` | creating and deactivating users, last admin protected, worker token shown once and stored hashed, revocation, `app:create-admin`, log pruning, scheduler registration |
| `tests/Feature/LocalizationTest.php` | switching language and remembering it, unknown or tampered locales refused, the How-to-use page signed out and in both languages, the guide covering every page, results shown in the chosen language, worker vocabularies translated with unknown values passed through, `lang:check` parity |
| `tests/Unit/SupportTest.php` | `SafeUrl`, `Csv::cell`, `PlaceKey`, `Like` |
| `worker/test/worker.test.js` | `.env` parsing, https enforcement, job option clamping, website status labels, WhatsApp link parsing, contactability tiers with phone on and off, scoring areas and package pitches, switchable service areas, store detection needing a real cart, marketing-tag detection, reporter batching, cancel and "job gone" handling |

---

## Manual test plan

Run it on a staging copy (or production before inviting users). ✔ = expected result.

### Authentication

| # | Steps | Expected |
|---|---|---|
| A1 | Open `/` signed out | ✔ Redirect to `/login` |
| A2 | Sign in with valid credentials, tick "Keep me signed in", close and reopen the browser | ✔ Still signed in |
| A3 | Wrong password 6 times in a minute | ✔ Error each time, then "Too many requests" |
| A4 | Forgot password with a real and a fake email | ✔ Same message for both; only the real one receives mail |
| A5 | Use the reset link twice | ✔ Second use says the token is invalid |
| A6 | Admin deactivates a user who is signed in elsewhere; that user clicks any link | ✔ Signed out with "deactivated" message |
| A7 | Sign out, press Back, submit a form | ✔ 419 or redirect to login, no action performed |

### Authorization

| # | Steps | Expected |
|---|---|---|
| Z1 | As a member, open `/admin/users` and `/admin/workers` | ✔ 403 page |
| Z2 | Member B opens member A's run | ✔ Visible, no Stop/Delete buttons |
| Z3 | Member B sends `DELETE /runs/{A's run}` (e.g. from devtools with a CSRF token) | ✔ 403 |
| Z4 | Admin edits the only admin to Member | ✔ Refused with message |
| Z5 | Admin deactivates themselves | ✔ Refused |

### Database operations

| # | Steps | Expected |
|---|---|---|
| D1 | `php artisan migrate --force` on an empty MySQL database | ✔ All tables created, utf8mb4 |
| D2 | Queue a search; stop the worker mid-run (close the window) | ✔ After `WORKER_STALE_MINUTES`, run shows Failed with saved places |
| D3 | Re-process that run | ✔ New run with the same places; original untouched |
| D4 | Delete a finished run | ✔ Its places, results and logs are gone (`SELECT COUNT(*) FROM run_businesses WHERE run_id=…` = 0) |
| D5 | Business names with emoji/accents (e.g. "Café 😀") | ✔ Stored and displayed correctly |

### Forms

| # | Steps | Expected |
|---|---|---|
| F1 | Submit the search form empty | ✔ "Enter at least one search", nothing queued |
| F2 | 60 searches (limit 50) | ✔ Error, nothing queued |
| F3 | Results per search = 0 or 500 | ✔ Validation error |
| F4 | Duplicate lines and `# comments` | ✔ Queued once each, comments ignored |
| F5 | Create a user with a 6-character password | ✔ Error: at least 10 characters with letters and numbers |
| F6 | Remove the `_token` field in devtools and submit any form | ✔ 419 Page Expired |

### File uploads

| # | Steps | Expected |
|---|---|---|
| U1 | Upload a `.txt` of searches | ✔ Merged into the queued run |
| U2 | Upload a `.php`, `.exe`, or a PNG renamed to `.txt` | ✔ Rejected |
| U3 | Upload a 100 KB `.txt` | ✔ "may not be larger than 64 KB" |
| U4 | Import a GMSCraper `leads.json` + `raw-places.jsonl` | ✔ Run with the same counts as the old report; Re-process available |
| U5 | Import a random JSON file | ✔ "This is not a GMSCraper leads.json file" |
| U6 | Import a 30 MB file | ✔ Rejected (validation or PHP `post_max_size`), with no 500 error |
| U7 | Confirm nothing uploaded persists: check `storage/app` after U1–U5 | ✔ No uploaded files kept |

### API integrations (worker)

| # | Steps | Expected |
|---|---|---|
| W1 | Start the worker with a wrong token | ✔ "Invalid or revoked worker token", worker exits |
| W2 | Start with a valid token | ✔ Online within 15 s; dashboard shows daily usage |
| W3 | Queue "dentists in <small town>", 5 results | ✔ Live log and counters update every ~3 s; run completes; tabs and CSVs populated |
| W4 | Press **Stop** during scraping | ✔ Worker stops within seconds; run Cancelled; places saved |
| W5 | Revoke the worker while it runs | ✔ Worker stops with a token error |
| W6 | Disconnect the PC's network for 1 minute during a run, then reconnect | ✔ Worker retries and the run continues |
| W7 | Set `dailyBusinesses` to 5 in `worker/config.json`, queue 10 | ✔ "LIMIT" note; next search waits with a countdown; Re-process still runs |
| W8 | `curl -X POST https://…/api/worker/v1/heartbeat` without a token | ✔ 401 JSON |
| W9 | Set `SCRAPE_DAILY_PER_CONNECTION=5`, run a search that scrapes 5, queue another | ✔ Dashboard shows the budget used with a countdown; the worker window says the connection's budget is used up; the queued search waits |
| W10 | With the budget used up, queue a re-process | ✔ Runs immediately |
| W11 | Connect a second worker on the same network | ✔ Dashboard shows one budget "shared by 2 workers"; the two together can't exceed it |
| W12 | Run one search with "phone calls count" ticked and one without, same query | ✔ With it ticked, phone-only businesses appear as tier B (`PHONE_CALL`); without, they stay in Unreachable (`PHONE_ONLY`) |

### Languages

| # | Steps | Expected |
|---|---|---|
| L1 | Press **العربية** in the top bar | ✔ The whole page flips to Arabic, right to left; the button now offers English |
| L2 | Sign out, close the browser, reopen the site | ✔ Still Arabic (the choice is a year-long cookie), and the login page is Arabic too |
| L3 | In Arabic, open a finished run and expand a lead | ✔ Labels, tiers, packages, area names and website status are Arabic; phone numbers, emails and URLs read left to right; the worker's own sentences and its log stay English |
| L4 | In Arabic, export a Leads CSV and open it in Excel | ✔ Column headings English, Arabic business names readable |
| L5 | In Arabic, submit the search form with no searches, then with 60 | ✔ Validation messages in Arabic |
| L6 | Press **How to use** on every page, signed in and signed out | ✔ Opens the guide in the current language; each contents link jumps to its section |
| L7 | `php artisan lang:check` after editing a string | ✔ Reports any key missing from the other language, exit code 1 |
| L8 | Colleague signs in on another PC while you are in Arabic | ✔ They get the default language; your choice didn't follow them |

### Security

| # | Steps | Expected |
|---|---|---|
| S1 | `curl -I https://leads.example.com/login` | ✔ `Content-Security-Policy`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Strict-Transport-Security`; session cookie `Secure; HttpOnly; SameSite=Lax` |
| S2 | Open `/.env`, `/../gscraper/.env`, `/build/manifest.json`, `/index.php/../.env` | ✔ 403 or 404, never file contents |
| S3 | Set `APP_DEBUG=false`, visit `/runs/abc` and force an exception | ✔ Friendly error page, no stack trace; details in `storage/logs` |
| S4 | Import a report whose business name is `<img src=x onerror=alert(1)>` and website is `javascript:alert(1)` | ✔ Shown as text; website not clickable |
| S5 | Export CSV for a business named `=HYPERLINK(...)` and open it in Excel | ✔ Shown as text, not a formula |
| S6 | Search box: `%' OR 1=1 --` | ✔ No error, no extra results |
| S7 | Embed the site in an `<iframe>` on another domain | ✔ Refused |
| S8 | `git status` / package zip | ✔ No `.env` or tokens committed or packaged |

### Performance

| # | Steps | Expected |
|---|---|---|
| P1 | Import or generate a run with 1,000+ businesses; open it | ✔ Page loads in < 1 s; 50 rows per page |
| P2 | Leads page with 10,000+ rows across runs | ✔ Paginated; no timeout |
| P3 | Export CSV of a large run | ✔ Streams immediately; PHP memory stays low |
| P4 | Leave a run page open for 30 minutes | ✔ Polling stops when the run finishes; backoff on errors |
| P5 | Local development: browse every page with `APP_ENV=local` | ✔ No lazy-loading (N+1) exceptions from strict mode |
| P6 | Lighthouse on `/login` | ✔ CSS ~16 KB and JS ~4 KB (gzip ~6 KB); hashed assets cached for a year |

### Production deployment

| # | Steps | Expected |
|---|---|---|
| X1 | Follow docs/DEPLOYMENT.md on a fresh subdomain | ✔ Works without editing any code |
| X2 | `php artisan about` over SSH | ✔ Environment production, debug off, caches "CACHED" |
| X3 | `php artisan schedule:list` | ✔ Four tasks listed; `storage/logs` shows no cron errors after 5 minutes |
| X4 | Deploy an update following "Updating to a new version" | ✔ Maintenance page during migrate; no lost data |
| X5 | Complete the final checklist in DEPLOYMENT.md | ✔ All boxes ticked |
