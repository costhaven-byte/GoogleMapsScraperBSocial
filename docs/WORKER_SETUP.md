# Worker setup

The worker is the part of GScraper that scrapes. It runs on a PC because Google Maps scraping needs a real Chromium browser and a residential IP, which shared hosting can't provide.

It only makes **outbound** HTTPS requests to your web app, so you don't need port forwarding, a static IP or firewall changes.

```
worker/
  GScraperWorker.bat   double-click to start (installs dependencies on first run)
  .env.example         connection settings → copy to .env
  config.json          scraping rules, daily limits, scoring thresholds
  src/worker.js        polling loop
  src/pipeline.js      one job end to end
  src/api.js           HTTPS client + batched progress reporter
  src/maps.js, activity.js, enrich.js, website.js, contact.js,
      social.js, polite.js, scoring.js, match.js, usage.js, util.js
                       unchanged GMSCraper modules
  data/                created at runtime: page cache, usage.json
  .browser-profile/    created by `npm run login` (Google session)
```

---

## 1. Install Node.js

Install **Node.js 20 LTS or newer** from https://nodejs.org (the Windows Installer, default options). Check in a new terminal:

```bash
node -v
```

## 2. Copy the worker folder

Copy the project's `worker/` folder to the PC, for example `C:\GScraperWorker`. Only this folder is needed, not the rest of the project.

Open a terminal in that folder (in Explorer, type `cmd` in the address bar) and run:

```bash
npm install
npx playwright install chromium
```

(`GScraperWorker.bat` also runs these automatically the first time.)

## 3. Create a worker token

In the web app, as an admin: **Workers** → enter a name (e.g. "Office PC") → **Create token** → **Copy**. The token is shown only once. If you lose it, revoke the worker and create a new one.

## 4. Configure `.env`

```bash
copy .env.example .env
notepad .env
```

```ini
SERVER_URL=https://leads.example.com
WORKER_TOKEN=gsw_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
HEADLESS=true
```

- `SERVER_URL` must be `https://`. The token is sent with every request, so plain http is refused, except for `http://localhost` during development.
- `BROWSER_PROFILE_DIR` (optional) reuses an existing signed-in profile (see "Coming from GMSCraper" below).
- `HEADLESS=false` shows the browser while it scrapes.

## 5. Sign in to Google (recommended, once)

```bash
npm run login
```

A Chromium window opens on Google Maps. Accept or reject the consent prompt, sign in (a secondary Google account is fine), then **close the window**. Signed-out Google Maps shows a "limited view" with only 5 unsorted reviews, so more genuinely active businesses would be excluded. The session is saved in `.browser-profile/` and reused on every run.

## 6. Start the worker

Double-click **`GScraperWorker.bat`**, or run:

```bash
npm start
```

You'll see:

```
[10:02:11] GScraper worker v1.0.0 connecting to https://leads.example.com
[10:02:12] Connected. Waiting for jobs (leave this window open).
```

Within ~15 seconds the web app shows the worker as **Online**. Leave the window open. Queued searches start automatically, and their log streams to the run page.

Other commands:

| Command | What it does |
|---|---|
| `npm start` | Run continuously |
| `npm run once` | Process at most one queued job, then exit (handy with Task Scheduler) |
| `npm run login` | Google sign-in window |
| `npm test` | Worker unit tests (no network, no browser) |

**Stopping:** press Ctrl+C once. The worker finishes saving the current job's progress and marks it stopped. Press Ctrl+C again to quit immediately. Closing the window kills it at once; the web app then marks the run failed after `WORKER_STALE_MINUTES` (default 10), and its saved places can be re-processed.

## 7. Start automatically when Windows starts (optional)

Press Win+R → `shell:startup` → Enter → create a shortcut to `GScraperWorker.bat` in that folder.

---

## How it works

1. Every ~15 s the worker sends a **heartbeat** (version, computer name, daily usage) and asks for a job.
   - If this PC is under its scraping limit, it can get **search** jobs.
   - If it's locked (daily limit, cool-down or Google block), it only gets **re-process** jobs, which don't touch Google Maps.
2. For a search, every scraped place is uploaded in batches of 10, so a crash never wastes Google traffic.
3. Every 3 s it uploads new log lines and progress. The reply tells it whether someone pressed **Stop**.
4. At the end it uploads the results in chunks of 25, then marks the job completed.

A claimed job is tied to that worker. If the server marked it failed in the meantime (for example after a long disconnection), the worker abandons it.

## Limits and rules

`worker/config.json` holds the same settings as GMSCraper's `config.json`:

| Section | Key | Default | Meaning |
|---|---|---|---|
| `contact` | `phoneIsChannel` | false | Whether a phone number counts as a way to reach a lead. Normally set per run from the web app instead |
| `services` | `web`, `conversion`, `paidMedia`, `social`, `automation`, `brand` | all true | Scoring areas. Switch off anything the agency doesn't sell and those points stop counting |
| `limits` | `dailyBusinesses` | 300 | Max businesses this PC opens on Maps per rolling 24 h |
| | `perRunBusinesses` | 100 | Max per job; the job stops early and keeps what it has |
| | `cooldownMinutes` | 60 | Break after a full-size run (proportionally shorter after smaller ones) |
| | `minRunBusinesses` | 10 | While at the daily limit, searches resume once this many slots are free |
| | `blockPauseHours` | 24 | Pause after Google shows a captcha or hides reviews |
| `scraping` | `reviewsToRead`, `delayMsBetweenPlaces`, `websiteConcurrency`, `websiteTimeoutMs` | | Scraping pace |
| `enrichment` | `cacheDays`, `perHostDelayMs`, `maxCrawlDelaySeconds` | | Politeness towards business websites |
| `priority` | `hot`, `warm` | 50, 30 | Score thresholds |

Per-search options chosen in the web app (results per search, activity window in months, skip socials, keep businesses with no reviews) override `config.json` for that job.

Restart the worker after editing `config.json`.

### Two budgets, and why

Google counts scraping **per IP address**, so the numbers above (which this PC tracks in `data/usage.json`) are not the whole story:

- **The shared connection budget** is held by the server: every worker behind the same public IP draws from one rolling 24-hour allowance (300 by default, set with `SCRAPE_DAILY_PER_CONNECTION`). The server caps each job to what's left, and while it's used up it only hands out re-process work. The dashboard shows it with a countdown.
- **This PC's own limits** still apply on top: the per-run size, the cool-down after a run, and the 24-hour pause after Google blocks it. Those protect the browser profile and this machine's behaviour.

So a second PC in the same office adds no extra scraping capacity — it shares the same allowance and just spreads the work. A worker on a genuinely different network (another office, a phone hotspot) has its own IP and therefore its own budget. Don't try to dodge a pause by changing IP: blocked connections are usually temporary, and VPN or datacenter addresses get blocked faster and harder than a home line.

---

## Coming from the desktop GMSCraper

- **Keep your Google sign-in:** set `BROWSER_PROFILE_DIR=C:\Users\<you>\Documents\GMSCraper\.browser-profile` in `.env`. Don't run the old GMSCraper app at the same time, because a Chromium profile can only be used by one browser at once.
- **Keep today's usage count:** copy `GMSCraper\data\usage.json` to `worker\data\usage.json`, so the daily limit and any block pause carry over.
- **Keep the page cache (optional):** copy `GMSCraper\data\cache\` to `worker\data\cache\`, so re-processing old runs doesn't refetch websites.
- **Bring old results into the web app:** **Import** → choose a run folder's `leads.json` and `raw-places.jsonl`.

---

## Updating the worker

Replace the `src/` folder and `package.json` with the new versions, keeping `.env`, `config.json`, `data/` and `.browser-profile/`. Then run `npm install` and start the worker again.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `WORKER_TOKEN is missing or invalid` | Paste the full token (starts with `gsw_`) into `worker/.env` |
| `STOPPED: Invalid or revoked worker token` | The worker was revoked or the token was mistyped. Create a new token under **Workers** |
| `SERVER_URL must start with https://` | Use the https address of the web app |
| `Server problem: cannot reach the server (ENOTFOUND / ECONNREFUSED)` | Check the address and your internet connection. The worker retries on its own |
| `Server problem: … (HTTP 403)` | SiteGround's security layer is blocking the PC. See DEPLOYMENT.md → Troubleshooting → 403 |
| `Server problem: … (HTTP 500)` | Check the web app's `storage/logs` |
| `self-signed certificate` / TLS errors | Antivirus or a corporate proxy is intercepting HTTPS. Allow `node.exe`, or set `NODE_EXTRA_CA_CERTS` to the proxy's CA |
| `Executable doesn't exist … chromium` | Run `npx playwright install chromium` |
| Worker online, but searches stay **Queued** | The PC is cooling down, or this connection's shared daily budget is used up. The worker window and the dashboard both say which, with a countdown. Re-process jobs still run |
| `Daily scraping budget for this internet connection is used up` | Another worker on the same connection has spent the allowance, or this one has. Slots free up 24 h after each business was scraped |
| Run says **BLOCKED** | Google soft-blocked the connection. Scraping pauses for `blockPauseHours`. Signing in doesn't lift a block |
| Many "rating but no review dates" exclusions | Run `npm run login`; signed-out Maps hides most reviews |
| "Opening in existing browser session" / a blank tab opens when a run starts | The sign-in window from `npm run login` is still open (or a leftover Chromium process). Close it, end any "Google Chrome for Testing" process in Task Manager, then search again |
| Windows Firewall prompt when Chromium starts | Choose Cancel. The worker doesn't need incoming connections |
| Run marked failed: "worker stopped responding" | The PC slept, lost its connection or the window was closed. Re-process the run to finish it from its saved places |
