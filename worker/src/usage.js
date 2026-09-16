// Tracks how many businesses were scraped in the last 24 hours (rolling window),
// the cooldown after each run, and lockouts after Google shows a block page.
const fs = require('fs');
const path = require('path');

const FILE = path.join(__dirname, '..', 'data', 'usage.json');
const HOUR = 3_600_000;
const DAY = 24 * HOUR;

function load() {
  try {
    return { scrapes: [], lastRunEndedAt: 0, lastRunCount: 0, blockedAt: 0, ...JSON.parse(fs.readFileSync(FILE, 'utf8')) };
  } catch {
    return { scrapes: [], lastRunEndedAt: 0, lastRunCount: 0, blockedAt: 0 };
  }
}

function save(usage) {
  const cutoff = Date.now() - DAY;
  usage.scrapes = usage.scrapes.filter((t) => t > cutoff);
  fs.mkdirSync(path.dirname(FILE), { recursive: true });
  fs.writeFileSync(FILE, JSON.stringify(usage));
}

const update = (fn) => {
  const usage = load();
  fn(usage);
  save(usage);
};

const recordScrape = () => update((u) => u.scrapes.push(Date.now()));
const recordRunEnd = (count) => update((u) => { u.lastRunEndedAt = Date.now(); u.lastRunCount = count; });
const recordBlocked = () => update((u) => { u.blockedAt = Date.now(); });

// limits: { dailyBusinesses, perRunBusinesses, cooldownMinutes, minRunBusinesses, blockPauseHours }
function usageStatus(limits, now = Date.now()) {
  const usage = load();
  const recent = usage.scrapes.filter((t) => t > now - DAY).sort((a, b) => a - b);
  const used = recent.length;
  const remaining = Math.max(0, limits.dailyBusinesses - used);
  const minRun = Math.min(limits.minRunBusinesses, limits.dailyBusinesses);

  let nextStartAt = now;
  let reason = null;

  // Rolling window: a slot frees up 24h after each scrape. Unlock once minRun slots are free.
  if (remaining < minRun) {
    nextStartAt = recent[used - (limits.dailyBusinesses - minRun) - 1] + DAY;
    reason = 'daily';
  }
  // Cooldown scales with the size of the last run (a full-size run = the full cooldown).
  const cooldownMs = limits.cooldownMinutes * 60_000 * Math.min(1, usage.lastRunCount / limits.perRunBusinesses);
  const cooldownUntil = usage.lastRunEndedAt + cooldownMs;
  if (cooldownUntil > nextStartAt) {
    nextStartAt = cooldownUntil;
    reason = 'cooldown';
  }
  const blockedUntil = usage.blockedAt ? usage.blockedAt + limits.blockPauseHours * HOUR : 0;
  if (blockedUntil > nextStartAt) {
    nextStartAt = blockedUntil;
    reason = 'blocked';
  }

  return {
    now,
    used,
    limit: limits.dailyBusinesses,
    remaining,
    perRun: limits.perRunBusinesses,
    runBudget: Math.min(remaining, limits.perRunBusinesses),
    canStart: nextStartAt <= now,
    nextStartAt,
    reason,
    fullResetAt: used ? recent[used - 1] + DAY : now,
  };
}

function describeLock(status) {
  const when = new Date(status.nextStartAt).toLocaleString();
  const mins = Math.ceil((status.nextStartAt - status.now) / 60_000);
  const wait = mins >= 60 ? `${Math.floor(mins / 60)}h ${mins % 60}m` : `${mins}m`;
  const why = {
    daily: `Daily limit reached (${status.used}/${status.limit} businesses in the last 24h).`,
    cooldown: 'Cooling down after the last run.',
    blocked: 'Google showed a block/captcha page — pausing to let it clear.',
  }[status.reason];
  return `${why} You can start again in ${wait} (${when}).`;
}

module.exports = { recordScrape, recordRunEnd, recordBlocked, usageStatus, describeLock };
