# GScraper (web)

The GMSCraper lead finder as a multi-user web app for **SiteGround shared hosting**.

It finds US local businesses on Google Maps, keeps only the ones that are **active right now**, works out how to reach each one **in writing** (email, contact form, Instagram, Facebook Messenger), drops the unreachable ones, and scores the rest by how badly they need a website, online booking/forms, or a chatbot. The rules are the same as the desktop GMSCraper; see [Pipeline rules](#pipeline-rules).

| Document | Contents |
|---|---|
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Step-by-step SiteGround deployment, cron, permissions, troubleshooting, final checklist |
| [docs/WORKER_SETUP.md](docs/WORKER_SETUP.md) | Installing and running the scraping worker on a Windows PC |
| [docs/DATABASE.md](docs/DATABASE.md) | Schema, relationships, indexes |
| [docs/TESTING.md](docs/TESTING.md) | Automated tests and the manual test plan |
| [docs/LANGUAGES.md](docs/LANGUAGES.md) | English/Arabic interface, what is translated, adding a language |

---

## 1. Architecture

```
            ┌──────────────── SiteGround shared hosting ─────────────────┐
 Browser ──►│ Apache + PHP 8.3 ── Laravel 13 web app ── MySQL 8          │
 (polls     │   Blade pages, auth, search queue, results, CSV exports,   │
  progress) │   worker API (/api/worker/v1/*)                            │
            │ Cron, every minute ── php artisan schedule:run (short jobs)│
            └───────────────────────────▲────────────────────────────────┘
                                        │ HTTPS, outbound from the PC only:
                                        │ poll for jobs, upload places,
                                        │ logs and results in small chunks
            ┌───────────────────────────┴────────────────────────────────┐
            │ Your PC: GScraper worker (Node.js + Playwright Chromium)     │
            │ Google Maps scrape → activity gate → website/social         │
            │ enrichment (robots.txt, cache) → contactability → scoring   │
            └────────────────────────────────────────────────────────────┘
```

**Why the scraper runs on a PC.** The desktop GMSCraper drives a real Chromium browser for every search. That can't work on shared hosting: there's no Chromium, no persistent processes, strict CPU/memory/time limits, and Google blocks datacenter IPs within minutes. So the work is split:

- **The web app** (PHP) is everything people use: accounts, the queue, live progress, results, the cross-run lead database, search insights, CSV exports and importing old GMSCraper reports.
- **The worker** runs your existing, proven GMSCraper modules unchanged (`worker/src/maps.js`, `enrich.js`, `scoring.js`, …). It asks the web app for queued searches and sends results back. It only makes **outbound** HTTPS requests, so no port forwarding or public IP is needed.

### SiteGround compatibility analysis

| Needed feature | Usual implementation | What this app does instead |
|---|---|---|
| Browser scraping | Headless Chrome on the server | Local worker on a PC (see above) |
| Background jobs | Redis + Supervisor + `queue:work` | The worker polls a MySQL-backed job table. The Laravel `database` queue is drained by cron (`queue:work --stop-when-empty`) |
| Live progress | WebSockets / Reverb / SSE | The browser polls a small JSON endpoint every 3 s (with backoff) |
| Worker heartbeats / timeouts | Long-running daemon | Every worker request updates `last_seen_at`. A one-minute cron task fails runs whose worker went silent |
| Cache, sessions, rate limits | Redis / Memcached | MySQL (`CACHE_STORE=database`, `SESSION_DRIVER=database`) |
| Frontend | Vite dev server / SSR Node | `npm run build` locally. Compiled assets in `public/build` are committed and uploaded |
| Large uploads/processing | Queued workers | Bounded: worker uploads ≤ 100 records/request, legacy imports ≤ 20 MB / 5,000 businesses, CSVs streamed with `lazy()` |
| Custom vhost / docroot | Apache config | Split deploy: `public_html/` (web root) + `gscraper/` (app, outside the web root) |
| Daily scraping limit | Counted on the scraping machine | Google rate-limits by IP, so the server counts places per **public IP** and shares one budget between every worker on that connection |
| Mail | Local MTA | SMTP through a SiteGround mailbox |
| Arabic interface | Separate RTL stylesheet or a second build | One build: `dir="rtl"` plus CSS logical properties; strings in `lang/`, chosen per person by cookie |

Nothing requires root, Docker, Redis, Supervisor, systemd, WebSockets, custom Apache modules or custom PHP extensions.

---

## 2. Technology stack

**Production (SiteGround)**

| Component | Version |
|---|---|
| PHP | 8.3 or 8.4 (developed and tested on 8.3.33). Extensions: pdo_mysql, mbstring, openssl, fileinfo, tokenizer, ctype, json, curl, zip. All standard on SiteGround |
| Laravel framework | 13.31 |
| MySQL | 8.0 (utf8mb4, InnoDB) |
| Apache | 2.4 with mod_rewrite (`.htaccess`) |
| Cron | One job, every minute |

**Development machine only**

| Component | Version |
|---|---|
| Composer | 2.10 |
| Node.js / npm | Node 20.19+ or 22.12+ (built with 24.15) |
| Vite | 8.3 |
| Tailwind CSS (+ @tailwindcss/vite) | 4.3 |
| laravel-vite-plugin | 3.2 |
| PHPUnit | 12.5 |
| Laravel Pint | 1.27 (code style) |

**Worker PC**

| Component | Version |
|---|---|
| Windows 10/11 (macOS/Linux also work) | |
| Node.js | 20+ |
| Playwright (Chromium) | 1.63 |

No frontend framework, no Livewire, and no runtime npm packages: the only JavaScript in the browser is one ~4 KB compiled file.

---

## 3. Project structure

```
app/
  Console/Commands/      app:create-admin, runs:fail-stale, runs:prune-logs
  Enums/                 RunStatus, RunType, Stage, UserRole
  Http/Controllers/      web pages; Api/WorkerApiController (worker protocol); Admin/*; Auth/*
  Http/Middleware/       SecurityHeaders, SetLocale, EnsureUserIsActive, AuthenticateWorker
  Http/Requests/         StoreRunRequest (search form + .txt upload)
  Models/                User, Worker, Run, RunPlace, RunBusiness, RunLog
  Policies/RunPolicy.php who may stop/re-process/delete runs
  Services/              RunQueue (job queue), BusinessRecorder (stores results),
                         LegacyImporter (GMSCraper leads.json), RunRows (CSV layouts)
  Support/               SafeUrl, Csv, PlaceKey, Like, Vocab (translates worker vocabularies)
bootstrap/app.php        routing, middleware, exception rendering
config/gscraper.php      app-specific settings (all from .env)
database/migrations/     schema
public/                  web root: index.php, .htaccess, build/ (compiled assets)
lang/en|ar/              interface strings, worker vocabularies, the How-to-use guide
resources/views/         Blade templates (layouts, pages, error pages, help)
resources/css|js/        asset sources (compiled into public/build)
routes/                  web.php, api.php (worker), console.php (schedule)
scripts/package.php      builds the SiteGround deployment zip
tests/                   PHPUnit feature and unit tests
worker/                  the local scraping worker (never uploaded to SiteGround)
docs/                    guides
```

### What goes where

| Path | Development only | Uploaded to SiteGround | Must be writable on the server | Publicly reachable |
|---|---|---|---|---|
| `app/ bootstrap/ config/ database/ resources/views/ routes/ vendor/ artisan composer.*` | | ✔ (in `gscraper/`) | | **No** |
| `storage/` | | ✔ | ✔ (logs, sessions, views, cache) | **No** |
| `bootstrap/cache/` | | ✔ | ✔ | **No** |
| `.env` | (local copy) | ✔ created on the server | | **No** |
| `public/` → `public_html/` | | ✔ | | Yes (the web root) |
| `public/build/` | built here | ✔ | | Yes |
| `resources/css`, `resources/js`, `node_modules/`, `package*.json`, `vite.config.js` | ✔ | | | |
| `tests/`, `phpunit.xml`, `scripts/`, `docs/`, `.tools/`, `dist/` | ✔ | | | |
| `worker/` | runs on the worker PC | | | |

`scripts/package.php` enforces this split automatically.

---

## 4. Local development

Requirements: PHP 8.3+, Composer 2, Node.js 20.19+.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
```

Edit `.env` for local use: `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://localhost:8000`, `DB_CONNECTION=sqlite` (or a local MySQL), `SESSION_SECURE_COOKIE=false`, `FORCE_HTTPS=false`, `SECURITY_HSTS=false`, `MAIL_MAILER=log`. Then:

```bash
php artisan migrate
php artisan app:create-admin
npm run build
php artisan serve
```

Open http://localhost:8000. For live CSS/JS reloading, run `npm run dev` in a second terminal. The Content-Security-Policy header is relaxed automatically while the Vite dev server is running.

To test with a worker locally, create a worker token under **Workers** and set `SERVER_URL=http://localhost:8000` in `worker/.env`. Plain http is only accepted for localhost.

---

## 5. Build process (exact commands)

Run these on your development machine, in the project root.

| # | Step | Command |
|---|---|---|
| 1 | Install all dependencies | `composer install` then `npm ci` |
| 2 | PHP dependencies only | `composer install` |
| 3 | Frontend dependencies only | `npm ci` |
| 4 | Build frontend assets | `npm run build` (writes `public/build/`) |
| 5 | Run tests | `php artisan test` (worker: `cd worker && npm test`) |
| 6 | Code style (optional) | `vendor/bin/pint` |
| 7 | Create the deployment package | `composer package` |

`composer package` runs `scripts/package.php`, which:

1. checks that `public/build/manifest.json` exists,
2. copies the app without development files,
3. runs `composer install --no-dev --optimize-autoloader --classmap-authoritative` in the copy,
4. moves `public/` to `public_html/` and points `index.php` at `../gscraper`,
5. zips the result to `dist/gscraper-deploy-<timestamp>.zip`.

If `composer` isn't on your PATH, set `COMPOSER_BIN`, e.g. `COMPOSER_BIN="php C:/tools/composer.phar" php scripts/package.php`.

These commands run **on the server** after uploading; see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md):

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Never run `config:cache` locally before packaging: cached config contains absolute paths from your machine. The package script deletes any cached config it finds.

---

## 6. Pipeline rules

Implemented in `worker/src/`, carried over from GMSCraper except where noted.

1. **Activity gate.** Keep a business only if Maps doesn't mark it closed and its newest review or owner reply is within the chosen months. A star rating whose reviews can't be read is always excluded.
2. **Channel enrichment.** Website (homepage, contact/about/booking pages), the Maps listing, then Facebook/Instagram (never fetched in practice, because their robots.txt disallows it) and link-in-bio pages. robots.txt is obeyed, with at least 3 s between requests per site and a 7-day cache. Nothing is guessed: `info@domain` is never constructed.
3. **Contactability gate.**

   | Tier | Channel | Links in the opener |
   |---|---|---|
   | **A** | Email, or WhatsApp click-to-chat | OK |
   | **B** | Website contact form, Instagram DM, or **phone/SMS when phone counts** | risky for DM/form, fine on a call |
   | **C** | Facebook Page only | no links: the opener must stand alone |
   | **D** | Nothing usable — dropped before scoring | — |

   **Phone is a setting.** Tick *Phone calls count as a way to reach a lead* on the search form when the sender can actually call those numbers (same country). Phone-only businesses then land in tier B instead of being dropped. It defaults to `PHONE_IS_CHANNEL` in `.env`, and each run stores the choice it ran with. WhatsApp always counts, because it's written and links work.

4. **Scoring** (reachable leads only, 0–100). Only gaps the scraper can actually verify earn points:

   | Area | Max | What earns points | Sells |
   |---|---|---|---|
   | Website & UX | 25 | No website 18 · social/third-party page 14–15 · site down or parked 20 · real but weak site: not mobile-friendly 10, no HTTPS 7, outdated © 6, slow 5, thin content 4, DIY builder 3, plus 8 for being a proven buyer | Vhorus website development, UI/UX, hosting |
   | Conversion & lead capture | 20 | No contact or quote form 7–9 · no online booking for appointment/reservation businesses 7 · online store present 4 · no WhatsApp on a consumer-facing site 3 | Landing Page Conversion Kit, Lead Magnet Launch Kit |
   | Paid media & tracking | 20 | No Meta pixel 7 · no Google Ads tag 6 · no analytics at all 5 · running ads into a page with no lead capture 4 · store with no pixel 3 | META Awareness Boost, Google Discovery & SEM, GDN, Analytics |
   | Social presence & content | 15 | No Instagram 6 · no Facebook Page 4 · visual business with 50+ reviews and no Instagram 3 · site links to no socials 2 · no video on the site 2 | Reels Studio, Creative & Production |
   | Chat & response | 10 | No chat widget 4 · reviews saying they can't be reached 4 · high review volume 2–3 · owner never replies to reviews 3 | Conversational AI ChatBot Kit, Community Management |
   | Brand & listing consistency | 10 | Unclaimed Google listing 3 · NAP mismatches 2–3 · no meta description 2 · free email address for business 2 · broken social profile 2 | Branding, Vhorus email hosting, listing fixes |

   Priority: Hot ≥ 50, Warm ≥ 30 (`worker/config.json`). Each area can be switched off in `services` if the agency doesn't sell it, and the pitch list names the matching package. Anything needing human judgement (creative quality, app or AR/VR ideas) is deliberately left unscored.

**Scraping limits are per internet connection, not per PC**, because that's how Google counts. The server keeps one rolling 24-hour budget for every worker sharing a public IP (`SCRAPE_DAILY_PER_CONNECTION`, default 300; `SCRAPE_PER_RUN`, default 100), caps each job to what's left, and hands out only re-process work while it's used up. Each worker additionally enforces its own local cool-down and the 24-hour pause after a Google block. A worker on a different network gets its own budget.

---

## 7. Interface languages

The app ships in **English** and **Arabic**, chosen per person from the button in the top bar and remembered in a cookie for a year. Arabic renders right to left from the same build: the layout sets `dir="rtl"` and the stylesheet uses logical properties, so there is no second CSS file and no separate templates.

Every page, message and error is translated, along with the fixed vocabularies the worker sends — scoring areas, suggested packages, website status, tiers and priorities. Free text the worker composed when it saved a result (activity evidence, the reason behind a score, the log) stays as written, and CSV headings stay English so spreadsheets and scripts keep working. `php artisan lang:check` fails if the two languages drift apart.

The **How to use** button, next to the language switch, opens a guide that explains every action on every page in the current language, with a link per section.

Details, and how to add a third language: [docs/LANGUAGES.md](docs/LANGUAGES.md).
