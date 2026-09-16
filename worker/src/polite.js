// Polite fetching for business websites, social pages and link-in-bio pages:
//  - obeys robots.txt (RFC 9309) for our product token, falling back to the '*' group
//  - honours Crawl-delay and a minimum gap between requests to the same host
//  - caches rendered page snapshots on disk so reruns don't re-hit sites
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const crypto = require('crypto');
const { sleep } = require('./util');

const CACHE_DIR = path.join(__dirname, '..', 'data', 'cache');
const ROBOTS_TOKEN = 'gmscraper';
const ROBOTS_UA = 'GMSCraper/1.0 (local business lead research)';
const HOUR = 3_600_000;

const sha1 = (s) => crypto.createHash('sha1').update(s).digest('hex');

function parseRobots(text) {
  const groups = [];
  let current = null;
  let lastWasAgent = false;
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.replace(/#.*$/, '').trim();
    const m = line.match(/^([A-Za-z-]+)\s*:\s*(.*)$/);
    if (!m) continue;
    const key = m[1].toLowerCase();
    const value = m[2].trim();
    if (key === 'user-agent') {
      if (!current || !lastWasAgent) {
        current = { agents: [], rules: [], crawlDelay: null };
        groups.push(current);
      }
      current.agents.push(value.toLowerCase());
      lastWasAgent = true;
      continue;
    }
    lastWasAgent = false;
    if (!current) continue;
    if ((key === 'allow' || key === 'disallow') && value) current.rules.push({ allow: key === 'allow', path: value });
    else if (key === 'crawl-delay') current.crawlDelay = parseFloat(value) || null;
  }
  return groups;
}

function groupFor(groups, token) {
  const specific = groups.filter((g) => g.agents.includes(token));
  const chosen = specific.length ? specific : groups.filter((g) => g.agents.includes('*'));
  return {
    rules: chosen.flatMap((g) => g.rules),
    crawlDelay: chosen.map((g) => g.crawlDelay).find((d) => d !== null) ?? null,
  };
}

function ruleMatches(pattern, target) {
  const anchored = pattern.endsWith('$');
  const body = (anchored ? pattern.slice(0, -1) : pattern)
    .split('*')
    .map((s) => s.replace(/[.+?^${}()|[\]\\]/g, '\\$&'))
    .join('.*');
  return new RegExp(`^${body}${anchored ? '$' : ''}`).test(target);
}

// Longest matching rule wins; on a tie, Allow wins.
function isAllowed(rules, target) {
  let best = null;
  for (const r of rules) {
    if (!ruleMatches(r.path, target)) continue;
    if (!best || r.path.length > best.path.length || (r.path.length === best.path.length && r.allow)) best = r;
  }
  return !best || best.allow;
}

async function fetchRobots(origin) {
  const attempt = async (o) => {
    const res = await fetch(`${o}/robots.txt`, {
      redirect: 'follow',
      headers: { 'User-Agent': ROBOTS_UA },
      signal: AbortSignal.timeout(15_000),
    });
    if (res.ok) return { mode: 'rules', groups: parseRobots((await res.text()).slice(0, 500_000)) };
    // RFC 9309: 4xx means no restrictions; 5xx means assume everything is disallowed.
    if (res.status < 500) return { mode: 'allow-all', status: res.status };
    return { mode: 'disallow-all', status: res.status, note: `robots.txt returned HTTP ${res.status}` };
  };
  // Retry once: a single network hiccup shouldn't mark a working site as down.
  let code = '';
  for (let tryNo = 0; tryNo < 2; tryNo++) {
    try {
      return await attempt(origin);
    } catch (err) {
      code = err.cause?.code || err.name;
      if (origin.startsWith('https:') && /CERT|TLS|SSL|SELF_SIGNED|UNABLE_TO_VERIFY/i.test(code)) {
        try { return await attempt(origin.replace('https:', 'http:')); } catch { /* fall through */ }
      }
      if (tryNo === 0) await sleep(3000);
    }
  }
  return { mode: 'disallow-all', unreachable: true, note: `site unreachable (${code})` };
}

async function snapshotPage(page) {
  return page.evaluate(() => {
    const meta = (p) => document.querySelector(`meta[property="${p}"], meta[name="${p}"]`)?.content || '';
    return {
      title: document.title,
      text: (document.body ? document.body.innerText : '').slice(0, 200_000),
      html: document.documentElement.outerHTML.slice(0, 800_000),
      meta: { ogTitle: meta('og:title'), ogDescription: meta('og:description'), description: meta('description') },
      links: [...document.querySelectorAll('a[href]')].map((a) => ({ href: a.href, text: a.innerText.trim().slice(0, 80) })),
      iframes: [...document.querySelectorAll('iframe[src]')].map((f) => f.src),
      cfEmails: [...document.querySelectorAll('[data-cfemail]')].map((e) => e.getAttribute('data-cfemail')),
      hasViewport: !!document.querySelector('meta[name=viewport][content*="width"]'),
      overflowsMobile: document.documentElement.scrollWidth > window.innerWidth + 30,
      forms: [...document.querySelectorAll('form')].map((f) => ({
        textareas: f.querySelectorAll('textarea').length,
        inputs: f.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button])').length,
        hasEmail: !!f.querySelector('input[type=email], input[name*="email" i]'),
        search: !!f.querySelector('input[type=search], input[name=s], input[name=q]'),
      })),
    };
  });
}

function outcomeOf(res) {
  if (res.robotsDisallowed) return `skipped: ${res.error || 'disallowed by robots.txt'}`;
  const cached = res.fromCache ? ' (cached)' : '';
  if (res.ok) return `ok${cached}`;
  return `failed${cached}: ${res.error || (res.status ? `HTTP ${res.status}` : 'no response')}`;
}

class PoliteFetcher {
  constructor(opts = {}) {
    this.cacheDays = opts.cacheDays ?? 7;
    this.failureCacheHours = opts.failureCacheHours ?? 6;
    this.perHostDelayMs = opts.perHostDelayMs ?? 3000;
    this.maxCrawlDelayMs = (opts.maxCrawlDelaySeconds ?? 20) * 1000;
    this.robotsMemo = new Map();
    this.nextSlot = new Map();
    this.stats = { fetched: 0, cached: 0, robotsDisallowed: 0 };
  }

  cachePath(kind, key) {
    return path.join(CACHE_DIR, kind, `${sha1(key)}.json.gz`);
  }

  readCache(kind, key, maxAgeMs) {
    try {
      const entry = JSON.parse(zlib.gunzipSync(fs.readFileSync(this.cachePath(kind, key))).toString('utf8'));
      if (Date.now() - entry.fetchedAt < maxAgeMs(entry)) return entry;
    } catch { /* missing or corrupt: refetch */ }
    return null;
  }

  writeCache(kind, key, entry) {
    const file = this.cachePath(kind, key);
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, zlib.gzipSync(JSON.stringify(entry)));
  }

  robots(origin) {
    if (!this.robotsMemo.has(origin)) {
      this.robotsMemo.set(origin, (async () => {
        const cached = this.readCache('robots', origin, (e) => (e.unreachable ? this.failureCacheHours : 24) * HOUR);
        if (cached) return cached;
        const entry = { origin, fetchedAt: Date.now(), ...(await fetchRobots(origin)) };
        this.writeCache('robots', origin, entry);
        return entry;
      })());
    }
    return this.robotsMemo.get(origin);
  }

  async check(url) {
    const robots = await this.robots(url.origin);
    if (robots.unreachable) return { allowed: false, unreachable: true, note: robots.note };
    if (robots.mode === 'allow-all') return { allowed: true, crawlDelayMs: 0 };
    if (robots.mode === 'disallow-all') return { allowed: false, note: robots.note };
    const group = groupFor(robots.groups, ROBOTS_TOKEN);
    return {
      allowed: isAllowed(group.rules, url.pathname + url.search),
      crawlDelayMs: (group.crawlDelay || 0) * 1000,
      note: `${url.hostname} robots.txt disallows automated access to this page`,
    };
  }

  async waitTurn(host, gapMs) {
    const now = Date.now();
    const slot = Math.max(now, this.nextSlot.get(host) || 0);
    this.nextSlot.set(host, slot + gapMs);
    if (slot > now) await sleep(slot - now);
  }

  async fetchPage(context, url, { timeoutMs, variant = 'default' }) {
    let target;
    try { target = new URL(url); } catch { return { url, ok: false, error: 'invalid URL' }; }
    target.hash = '';
    const key = `${variant}|${target.href}`;

    const cached = this.readCache('pages', key, (e) => (e.ok ? this.cacheDays * 24 : this.failureCacheHours) * HOUR);
    if (cached) {
      this.stats.cached++;
      return { ...cached, fromCache: true };
    }

    const verdict = await this.check(target);
    if (verdict.unreachable) {
      const entry = { url: target.href, ok: false, unreachable: true, error: verdict.note, fetchedAt: Date.now() };
      this.writeCache('pages', key, entry);
      return entry;
    }
    if (!verdict.allowed) {
      this.stats.robotsDisallowed++;
      return { url: target.href, ok: false, robotsDisallowed: true, error: verdict.note };
    }
    if (verdict.crawlDelayMs > this.maxCrawlDelayMs) {
      this.stats.robotsDisallowed++;
      return { url: target.href, ok: false, robotsDisallowed: true, error: `robots.txt Crawl-delay is ${verdict.crawlDelayMs / 1000}s (over our ${this.maxCrawlDelayMs / 1000}s cap)` };
    }

    await this.waitTurn(target.host, Math.max(this.perHostDelayMs, verdict.crawlDelayMs));
    const page = await context.newPage();
    let entry;
    try {
      const started = Date.now();
      const response = await page.goto(target.href, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
      const loadSeconds = +((Date.now() - started) / 1000).toFixed(1);
      await page.waitForLoadState('networkidle', { timeout: 6000 }).catch(() => {});
      entry = { url: target.href, finalUrl: page.url(), status: response ? response.status() : null, loadSeconds };
      entry.ok = !!response && response.status() < 400;

      // A redirect to another host must also be allowed by that host's robots.txt.
      const final = new URL(entry.finalUrl);
      if (final.origin !== target.origin && !(await this.check(final)).allowed) {
        this.stats.robotsDisallowed++;
        return { url: target.href, ok: false, robotsDisallowed: true, error: `redirected to ${final.hostname}, whose robots.txt disallows it` };
      }
      entry.snapshot = await snapshotPage(page);
      this.stats.fetched++;
    } catch (err) {
      entry = { url: target.href, ok: false, error: err.message.split('\n')[0] };
    } finally {
      await page.close().catch(() => {});
    }
    entry.fetchedAt = Date.now();
    this.writeCache('pages', key, entry);
    return entry;
  }
}

module.exports = { PoliteFetcher, outcomeOf, parseRobots, groupFor, isAllowed };
