#!/usr/bin/env node
// GScraper worker: polls the web app for queued jobs and runs them on this PC.
//
//   npm start          keep running, pick up jobs as they are queued
//   npm run once       run at most one job, then exit
//   npm run login      sign in to Google once in a real browser window

const os = require('os');
const { loadSettings } = require('./config');
const { Api, JobReporter, FatalApiError, JobGoneError } = require('./api');
const { runJob, login } = require('./pipeline');
const { usageStatus, describeLock } = require('./usage');
const { sleep } = require('./util');

const say = (m) => console.log(`[${new Date().toLocaleTimeString()}] ${m}`);

let stopping = false;
let busy = false;
process.on('SIGINT', () => {
  if (stopping || !busy) process.exit(130);
  stopping = true;
  say('Stopping after saving progress... press Ctrl+C again to quit immediately.');
});

async function processJob(api, settings, job) {
  busy = true;
  const reporter = new JobReporter(api, job.id);
  const label = job.type === 'reprocess' ? `re-process of ${job.placesCount} saved places` : `search: ${job.queries.join(' · ')}`;
  reporter.log(`Worker ${os.hostname()} picked up run #${job.id} (${label}).`);
  try {
    const { status, meta } = await runJob({ job, baseCfg: settings.cfg, api, reporter, profileDir: settings.profileDir, isStopping: () => stopping });
    await reporter.close();
    if (!reporter.gone) await api.complete(job.id, status, null, { ...meta, workerVersion: settings.version });
    say(`Run #${job.id} ${status}.`);
  } catch (err) {
    if (err instanceof FatalApiError) throw err;
    const gone = err instanceof JobGoneError || reporter.gone;
    if (!gone) reporter.log(`FAILED: ${err.message.split('\n')[0]}`, 'error');
    await reporter.close().catch(() => {});
    if (gone) say(`Run #${job.id} is no longer active on the server (stopped or timed out); abandoned.`);
    else await api.complete(job.id, 'failed', err.message.slice(0, 2000), { workerVersion: settings.version }).catch((e) => say(`Could not report the failure: ${e.message}`));
    if (!gone) console.error(err);
  } finally {
    busy = false;
  }
}

async function main() {
  const args = process.argv.slice(2);
  const settings = loadSettings();
  if (args.includes('--login')) return login(settings.profileDir);

  const once = args.includes('--once');
  const api = new Api(settings);
  let pollSeconds = 15;
  let lastError = '';
  let lastNotice = '';
  const notice = (message) => {
    if (message && message !== lastNotice) say(message);
    lastNotice = message || '';
  };
  say(`GScraper worker v${settings.version} connecting to ${settings.serverUrl}`);

  while (!stopping) {
    try {
      const usage = usageStatus(settings.cfg.limits);
      const beat = await api.heartbeat({ hostname: os.hostname().slice(0, 100), usage });
      pollSeconds = Math.max(5, Math.min(120, beat?.pollSeconds || 15));
      if (lastError) say('Connected again.');
      else if (lastError === '' && !busy) say('Connected. Waiting for jobs (leave this window open).');
      lastError = null;

      const res = await api.claim(usage.canStart, usage);
      if (res?.job) {
        notice('');
        await processJob(api, settings, res.job);
        if (once) break;
        continue;
      }

      // Say why searches aren't starting, once per change of reason.
      const conn = res?.connection || beat?.connection;
      if (!usage.canStart) {
        notice(`This PC is paused: ${describeLock(usage)} Re-process jobs still run.`);
      } else if (conn && !conn.canScrape) {
        const others = conn.workers > 1 ? ` across ${conn.workers} workers (${conn.workerNames.join(', ')})` : '';
        const when = conn.nextSlotAt ? ` Scraping resumes around ${new Date(conn.nextSlotAt).toLocaleString()}.` : '';
        notice(`Daily scraping budget for this internet connection is used up: ${conn.used}/${conn.limit} businesses in the last 24h${others}.${when} Re-process jobs still run.`);
      } else {
        notice('');
      }

      if (once) {
        say('No queued jobs.');
        break;
      }
    } catch (err) {
      if (err instanceof FatalApiError) {
        say(`STOPPED: ${err.message} Create a new token in the web app (Workers) and update worker/.env.`);
        process.exitCode = 1;
        return;
      }
      if (err.message !== lastError) say(`Server problem: ${err.message}. Retrying every ${pollSeconds}s.`);
      lastError = err.message;
    }
    await sleep(pollSeconds * 1000);
  }
}

main().catch((err) => {
  console.error(`[${new Date().toLocaleTimeString()}] ${err.message}`);
  process.exit(1);
});
