# GScraper worker

Runs Google Maps searches for the GScraper web app on this PC and uploads the results over HTTPS.

Quick start:

```bash
npm install
npx playwright install chromium
copy .env.example .env     # set SERVER_URL and WORKER_TOKEN
npm run login              # optional: sign in to Google once
npm start                  # or double-click GScraperWorker.bat
```

Full guide: [../docs/WORKER_SETUP.md](../docs/WORKER_SETUP.md)
