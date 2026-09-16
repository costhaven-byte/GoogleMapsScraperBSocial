// One job, end to end. Same pipeline as the desktop GMSCraper:
//   Maps scrape (or saved places) → activity gate → channel enrichment →
//   contactability gate (A–D) → problem scoring
// Places are uploaded while scraping (so a crash never loses Google traffic);
// results are uploaded in small chunks at the end.
const { chromium, devices } = require('playwright');
const { searchPlaces, scrapePlace, placeKey, GoogleBlockedError } = require('./maps');
const { recordScrape, recordRunEnd, recordBlocked, usageStatus, describeLock } = require('./usage');
const { assessActivity } = require('./activity');
const { auditWebsite } = require('./website');
const { enrichChannels, distrustSharedContacts } = require('./enrich');
const { contactability, contactDistribution, formatTiers } = require('./contact');
const { PoliteFetcher } = require('./polite');
const { scoreLead } = require('./scoring');
const { sleep, randomBetween, mapPool, BROWSER_ARGS } = require('./util');

const RESULT_CHUNK = 25;

function applyJobOptions(baseCfg, options = {}) {
  const cfg = structuredClone(baseCfg);
  if (options.limit) cfg.scraping.maxResultsPerQuery = Math.max(1, Math.min(200, parseInt(options.limit, 10)));
  if (options.months) cfg.activity.maxReviewAgeMonths = Math.max(1, Math.min(60, Number(options.months)));
  if (options.allowUnverified) cfg.activity.allowUnverified = true;
  if (options.noSocials) cfg.scraping.checkSocials = false;
  cfg.contact = cfg.contact || {};
  if (options.phoneIsChannel !== undefined) cfg.contact.phoneIsChannel = !!options.phoneIsChannel;
  return cfg;
}

function websiteStatus(place, site) {
  if (!place.website) return 'No website';
  if (site.kind.type === 'social-only') return `Social page only (${site.kind.platform})`;
  if (site.kind.type === 'third-party-page') return `Third-party page only (${site.kind.platform})`;
  if (site.cloneSuspect) return 'Website looks like a clone/spam site';
  if (site.robotsBlocked) return 'Has website (not checked: robots.txt)';
  if (site.blocked) return 'Has website (blocks automated checks)';
  if (!site.reachable) return 'Website down';
  if (site.parked) return 'Website parked/expired';
  return 'Has website';
}

// Places saved by older versions lack newer fields.
const withDefaults = (place) => ({
  socialProfiles: [], listingEmails: [], reviewDates: [], ownerResponseDates: [], reviewTexts: [], ...place,
});

const mapsContext = (profileDir, headless) =>
  chromium.launchPersistentContext(profileDir, {
    headless,
    locale: 'en-US',
    viewport: { width: 1280, height: 900 },
    args: ['--disable-blink-features=AutomationControlled', ...BROWSER_ARGS],
  });

async function login(profileDir) {
  const ctx = await mapsContext(profileDir, false);
  const page = ctx.pages()[0] || (await ctx.newPage());
  await page.goto('https://www.google.com/maps?hl=en');
  console.log('A browser window is open. Accept or reject the consent prompt and sign in to Google.');
  console.log('This removes Google\'s "limited view" so review dates are visible. Close the window when done.');
  await new Promise((resolve) => ctx.on('close', resolve));
}

/**
 * @returns {Promise<{status: 'completed'|'cancelled', meta: object}>}
 */
async function runJob({ job, baseCfg, api, reporter, profileDir, isStopping }) {
  const cfg = applyJobOptions(baseCfg, job.options);
  const contactOpts = { phoneIsChannel: !!cfg.contact.phoneIsChannel };
  const log = (m, level) => reporter.log(m, level);
  const cancelled = () => reporter.cancelRequested || reporter.gone || isStopping();
  const queries = job.queries || [];
  const progress = {
    phase: 'Starting…', pct: 2, active: 0, excluded: 0, hot: 0, enriched: 0, enrichTotal: 0,
    tiers: { A: 0, B: 0, C: 0, D: 0 }, blocked: false, limitHit: false,
  };
  const report = (changes = {}) => reporter.setProgress(Object.assign(progress, changes));
  const active = [];
  const excluded = [];
  let stoppedEarly = false;

  const classify = (query, place, label) => {
    const activity = assessActivity(place, cfg.activity);
    (activity.active ? active : excluded).push({ query, place, activity });
    progress[activity.active ? 'active' : 'excluded']++;
    log(`  ${label} ${activity.active ? 'ACTIVE  ' : 'EXCLUDED'} ${place.name} — ${activity.reasons[0]}`);
  };

  // ---- Phase 1: places
  if (job.type === 'reprocess') {
    report({ phase: 'Loading saved places (Google Maps is not contacted)', pct: 3 });
    const rows = await api.fetchPlaces(job.id);
    log(`Re-processing ${rows.length} saved places (Google Maps is not contacted).`);
    rows.forEach(({ query, place }, i) => classify(query, withDefaults(place), `[${i + 1}/${rows.length}]`));
    report({ phase: `Checked activity for ${rows.length} saved places`, pct: 10 });
  } else {
    const usage = usageStatus(cfg.limits);
    if (!usage.canStart) throw new Error(`Scraping is locked on this PC: ${describeLock(usage)}`);
    // The server caps the run too: every worker on this internet connection shares
    // one daily budget, because Google rate-limits by IP address.
    const serverBudget = Number.isFinite(job.maxBusinesses) ? job.maxBusinesses : Infinity;
    const budget = Math.max(1, Math.min(usage.runBudget, serverBudget));
    log(`Usage: ${usage.used}/${usage.limit} on this PC in the last 24h${serverBudget < usage.runBudget ? `, and the server allows ${serverBudget} more on this connection` : ''}. This run can scrape up to ${budget}.`);
    stoppedEarly = await scrapeMaps({ cfg, queries, budget, profileDir, reporter, progress, report, classify, cancelled, log });
  }

  if (cancelled()) {
    log('Stop requested: saving the inactive businesses found so far and stopping. Saved places can be re-processed later.', 'warn');
    await uploadResults(api, job.id, excluded.map((e) => ({ stage: 'inactive', ...e })));
    return { status: 'cancelled', meta: finalMeta(cfg, progress, null) };
  }

  // ---- Phase 2: channel enrichment
  log(`Enriching contact channels for ${active.length} active businesses (robots.txt respected, cached pages reused)...`);
  report({ phase: 'Finding emails, contact forms, Instagram & Facebook (robots.txt respected)', pct: 70, enrichTotal: active.length });
  const fetcher = new PoliteFetcher(cfg.enrichment);
  const browser = await chromium.launch({ headless: true, args: BROWSER_ARGS });
  let enriched;
  try {
    const siteCtx = await browser.newContext({ ...devices['iPhone 13'], locale: 'en-US', ignoreHTTPSErrors: true });
    const socialCtx = await browser.newContext({ locale: 'en-US', viewport: { width: 1280, height: 900 } });
    const timeout = cfg.scraping.websiteTimeoutMs;
    enriched = await mapPool(active, cfg.scraping.websiteConcurrency, async (entry) => {
      if (cancelled()) return null;
      const { place } = entry;
      const { site, pages } = place.website
        ? await auditWebsite(fetcher, siteCtx, place.website, place, timeout, cfg.enrichment.maxExtraPagesPerSite ?? 3)
        : { site: { kind: { type: 'none' }, reachable: false, socialLinks: {}, fetchLog: [] }, pages: [] };
      const { channels, socials } = await enrichChannels({
        fetcher, context: socialCtx, place, site, sitePages: pages, checkSocials: cfg.scraping.checkSocials, timeoutMs: timeout,
      });
      const contact = contactability(channels, place, site, contactOpts);
      progress.enriched++;
      progress.tiers[contact.tier]++;
      report({
        phase: `Finding contact channels (${progress.enriched}/${active.length})`,
        pct: 70 + 28 * (active.length ? progress.enriched / active.length : 1),
      });
      log(`  [enriched] ${place.name}`);
      return { ...entry, site, socials, channels, contact };
    });
  } finally {
    await browser.close();
  }

  if (cancelled()) {
    log('Stop requested during enrichment: saving inactive businesses and stopping. Re-process the run to finish it.', 'warn');
    await uploadResults(api, job.id, excluded.map((e) => ({ stage: 'inactive', ...e })));
    return { status: 'cancelled', meta: finalMeta(cfg, progress, fetcher.stats) };
  }
  enriched = enriched.filter(Boolean);

  const distrusted = distrustSharedContacts(enriched, contactOpts);
  if (distrusted) log(`Distrusted contact details on ${distrusted} business(es): the same address was published for several different businesses.`);
  progress.tiers = { A: 0, B: 0, C: 0, D: 0 };
  for (const e of enriched) {
    e.websiteStatus = websiteStatus(e.place, e.site);
    progress.tiers[e.contact.tier]++;
    log(`  [contact ${e.contact.tier}] ${e.place.name} — ${e.contact.summary}`);
  }

  // ---- Phase 3: contactability gate (tier D is never scored)
  const distribution = contactDistribution(enriched, excluded, queries);
  log(`CONTACTABILITY — A email · B form/Instagram · C Messenger only · D unreachable: ${formatTiers(distribution.overall)}`);
  const reachable = enriched.filter((e) => e.contact.tier !== 'D');
  const unreachable = enriched.filter((e) => e.contact.tier === 'D');

  // ---- Phase 4: problem-severity scoring for reachable leads only
  log(`Scoring ${reachable.length} reachable leads (tiers A–C); ${unreachable.length} tier-D businesses dropped unscored.`);
  report({ phase: 'Scoring reachable leads', pct: 98 });
  const leads = reachable.map((e) => {
    const scoring = scoreLead({ place: e.place, site: e.site, socials: e.socials, channels: e.channels, services: cfg.services });
    const priority = scoring.score >= cfg.priority.hot ? 'Hot' : scoring.score >= cfg.priority.warm ? 'Warm' : 'Cold';
    log(`  ${priority.padEnd(4)} ${String(scoring.score).padStart(3)}  [${e.contact.tier}] ${e.place.name} — ${scoring.pitch.join(', ') || 'no clear gaps'}`);
    return { ...e, scoring, priority };
  });
  progress.hot = leads.filter((l) => l.priority === 'Hot').length;

  report({ phase: 'Uploading results', pct: 99 });
  await uploadResults(api, job.id, [
    ...leads.map((e) => ({ stage: 'lead', ...e })),
    ...unreachable.map((e) => ({ stage: 'unreachable', ...e })),
    ...excluded.map((e) => ({ stage: 'inactive', ...e })),
  ]);

  const s = fetcher.stats;
  log(`Pages: ${s.fetched} fetched, ${s.cached} from cache, ${s.robotsDisallowed} skipped by robots.txt.`);
  log(`Done. ${leads.length} reachable leads (${progress.hot} hot) · ${unreachable.length} unreachable (tier D) · ${excluded.length} inactive.`);
  report({ phase: stoppedEarly ? 'Finished early (limit or block)' : 'Done', pct: 100 });
  return { status: 'completed', meta: finalMeta(cfg, progress, s) };
}

async function scrapeMaps({ cfg, queries, budget, profileDir, reporter, progress, report, classify, cancelled, log }) {
  const maps = await mapsContext(profileDir, cfg.scraping.headless);
  const page = maps.pages()[0] || (await maps.newPage());
  const seen = new Set();
  let scraped = 0;
  let hiddenStreak = 0;
  let stoppedEarly = false;
  try {
    queryLoop: for (const [qi, query] of queries.entries()) {
      if (cancelled()) break;
      log(`Searching: "${query}"`);
      report({ phase: `Searching Google Maps (${qi + 1}/${queries.length})`, pct: 70 * (qi / queries.length) });
      const urls = await searchPlaces(page, query, cfg.scraping.maxResultsPerQuery);
      log(`  ${urls.length} places found`);
      for (const [i, url] of urls.entries()) {
        if (cancelled()) break queryLoop;
        const key = placeKey(url);
        if (seen.has(key)) continue;
        seen.add(key);
        if (scraped >= budget) {
          log(`LIMIT: reached this run's budget of ${budget} businesses, finishing with what we have.`, 'warn');
          report({ limitHit: true });
          stoppedEarly = true;
          break queryLoop;
        }
        let place;
        scraped++;
        recordScrape();
        try {
          place = await scrapePlace(page, url, cfg.scraping.reviewsToRead);
        } catch (err) {
          if (err instanceof GoogleBlockedError) throw err;
          log(`  [${i + 1}/${urls.length}] failed: ${err.message.split('\n')[0]}`, 'warn');
          continue;
        }
        reporter.addPlace(query, place);
        classify(query, place, `[${i + 1}/${urls.length}]`);
        report({
          phase: `Checking businesses are active: search ${qi + 1}/${queries.length}, place ${i + 1}/${urls.length}`,
          pct: 70 * ((qi + (urls.length ? (i + 1) / urls.length : 1)) / queries.length),
        });
        // Soft block: Google keeps showing star ratings but hides every review.
        hiddenStreak = place.reviewsHidden ? hiddenStreak + 1 : 0;
        if (hiddenStreak >= 3) {
          throw new GoogleBlockedError(`Google is hiding reviews (${hiddenStreak} places in a row show a rating but no reviews). This is a soft block on your connection`);
        }
        await sleep(randomBetween(cfg.scraping.delayMsBetweenPlaces));
      }
    }
  } catch (err) {
    if (!(err instanceof GoogleBlockedError)) throw err;
    recordBlocked();
    report({ blocked: true });
    stoppedEarly = true;
    log(`BLOCKED: ${err.message}. Stopping Google Maps now and pausing for ${cfg.limits.blockPauseHours}h. Finishing with what was collected.`, 'error');
  } finally {
    if (scraped) recordRunEnd(scraped);
    await maps.close();
  }
  return stoppedEarly;
}

async function uploadResults(api, jobId, items) {
  for (let i = 0; i < items.length; i += RESULT_CHUNK) {
    await api.postResults(jobId, items.slice(i, i + RESULT_CHUNK));
  }
}

function finalMeta(cfg, progress, stats) {
  return {
    maxReviewAgeMonths: cfg.activity.maxReviewAgeMonths,
    generatedAt: new Date().toISOString(),
    enrichmentStats: stats,
    blocked: progress.blocked,
    limitHit: progress.limitHit,
  };
}

module.exports = { runJob, login, applyJobOptions, websiteStatus };
