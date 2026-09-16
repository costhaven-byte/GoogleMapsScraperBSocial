// HTTPS client for the web app's worker API, plus a per-job reporter that
// batches log lines, progress and scraped places into a few small requests.
const { sleep } = require('./util');

class FatalApiError extends Error {}   // bad token / revoked worker: stop the worker
class JobGoneError extends Error {}    // server no longer considers the job active: abandon it

class Api {
  constructor({ serverUrl, token, version }) {
    this.base = `${serverUrl}/api/worker/v1`;
    this.token = token;
    this.version = version;
  }

  async request(method, path, body, { retries = 4 } = {}) {
    let lastError;
    for (let attempt = 0; attempt <= retries; attempt++) {
      let res;
      try {
        res = await fetch(this.base + path, {
          method,
          headers: {
            Authorization: `Bearer ${this.token}`,
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            'User-Agent': `GScraperWorker/${this.version}`,
          },
          body: body ? JSON.stringify(body) : undefined,
          signal: AbortSignal.timeout(60_000),
        });
      } catch (err) {
        lastError = new Error(`cannot reach the server (${err.cause?.code || err.name})`);
        await sleep(Math.min(30_000, 2000 * 2 ** attempt));
        continue;
      }
      if (res.status === 204) return null;
      const data = await res.json().catch(() => ({}));
      if (res.ok) return data;
      if (res.status === 401) throw new FatalApiError(data.error || 'The server rejected the worker token.');
      if (res.status === 404 || res.status === 409) throw new JobGoneError(data.error || `HTTP ${res.status}`);
      lastError = new Error(`${data.error || data.message || res.statusText} (HTTP ${res.status})`);
      // Validation errors won't fix themselves; server errors and rate limits might.
      if (res.status === 422 || res.status === 413 || res.status === 400) throw lastError;
      await sleep(Math.min(60_000, (res.status === 429 ? 10_000 : 2000) * 2 ** attempt));
    }
    throw lastError;
  }

  heartbeat(extra) {
    return this.request('POST', '/heartbeat', { version: this.version, ...extra }, { retries: 1 });
  }

  claim(canScrape, usage) {
    return this.request('POST', '/jobs/claim', { canScrape, usage }, { retries: 1 });
  }

  async fetchPlaces(jobId) {
    const all = [];
    let after = 0;
    do {
      const page = await this.request('GET', `/jobs/${jobId}/places?after_id=${after}&limit=200`);
      all.push(...page.places);
      after = page.nextAfterId;
    } while (after);
    return all;
  }

  postPlaces(jobId, places) {
    return this.request('POST', `/jobs/${jobId}/places`, { places });
  }

  postLogs(jobId, lines, progress) {
    return this.request('POST', `/jobs/${jobId}/logs`, { lines, progress }, { retries: 2 });
  }

  postResults(jobId, items) {
    return this.request('POST', `/jobs/${jobId}/results`, { items });
  }

  complete(jobId, status, error, meta) {
    return this.request('POST', `/jobs/${jobId}/complete`, { status, error, meta }, { retries: 6 });
  }
}

class JobReporter {
  constructor(api, jobId, { intervalMs = 3000 } = {}) {
    this.api = api;
    this.jobId = jobId;
    this.lines = [];
    this.places = [];
    this.progress = null;
    this.cancelRequested = false;
    this.gone = false;
    this.chain = Promise.resolve();
    this.timer = setInterval(() => this.flush(), intervalMs);
  }

  log(message, level = 'info') {
    const line = `[${new Date().toLocaleTimeString()}] ${message}`;
    (level === 'error' ? console.error : console.log)(line);
    this.lines.push({ message: String(message).slice(0, 4000), level });
    if (this.lines.length > 5000) this.lines.splice(0, this.lines.length - 5000);
  }

  setProgress(progress) {
    this.progress = { ...progress, tiers: { ...progress.tiers } };
  }

  addPlace(query, place) {
    this.places.push({ query, place });
    if (this.places.length >= 10) this.flush();
  }

  // Flushes run one at a time, in order. They double as the job's heartbeat.
  flush() {
    this.chain = this.chain.then(() => this.#flushNow()).catch((err) => {
      if (err instanceof JobGoneError) this.gone = true;
      else console.error(`[${new Date().toLocaleTimeString()}] Could not send progress to the server: ${err.message}`);
    });
    return this.chain;
  }

  async #flushNow() {
    if (this.gone) return;
    while (this.places.length) {
      const batch = this.places.slice(0, 50);
      await this.api.postPlaces(this.jobId, batch);
      this.places.splice(0, batch.length);
    }
    const batch = this.lines.slice(0, 500);
    const res = await this.api.postLogs(this.jobId, batch, this.progress);
    this.lines.splice(0, batch.length);
    this.cancelRequested = !!res?.cancelRequested;
  }

  async close() {
    clearInterval(this.timer);
    await this.flush();
    if (this.lines.length || this.places.length) await this.flush();
  }
}

module.exports = { Api, JobReporter, FatalApiError, JobGoneError };
