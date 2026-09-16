// Loads worker settings: .env (connection) + config.json (scraping rules and limits).
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');

// Minimal .env parser (KEY=VALUE, # comments, optional quotes). Real environment variables win.
function parseEnv(text) {
  const out = {};
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.trim();
    if (!line || line.startsWith('#')) continue;
    const m = line.match(/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/);
    if (!m) continue;
    let value = m[2].trim();
    if (/^(['"]).*\1$/.test(value)) value = value.slice(1, -1);
    out[m[1]] = value;
  }
  return out;
}

function loadEnv(file = path.join(ROOT, '.env')) {
  const fromFile = fs.existsSync(file) ? parseEnv(fs.readFileSync(file, 'utf8')) : {};
  return { ...fromFile, ...Object.fromEntries(Object.entries(process.env).filter(([k]) => k in fromFile || /^(SERVER_URL|WORKER_TOKEN|BROWSER_PROFILE_DIR|HEADLESS|ALLOW_INSECURE_HTTP)$/.test(k))) };
}

// The token travels in every request, so plain http is only allowed for localhost testing.
function validateServerUrl(raw, allowInsecure = false) {
  let url;
  try {
    url = new URL(String(raw || '').trim());
  } catch {
    throw new Error('SERVER_URL is missing or not a valid URL (e.g. https://leads.example.com).');
  }
  const local = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname);
  if (url.protocol !== 'https:' && !(url.protocol === 'http:' && (local || allowInsecure))) {
    throw new Error('SERVER_URL must start with https:// so the worker token is never sent unencrypted.');
  }
  return url.origin + url.pathname.replace(/\/+$/, '');
}

function loadSettings() {
  const env = loadEnv();
  const token = (env.WORKER_TOKEN || '').trim();
  if (!/^gsw_[A-Za-z0-9]{20,}$/.test(token)) {
    throw new Error('WORKER_TOKEN is missing or invalid. Create one in the web app under Workers and paste it into worker/.env.');
  }
  const cfg = JSON.parse(fs.readFileSync(path.join(ROOT, 'config.json'), 'utf8'));
  cfg.enrichment = cfg.enrichment || {};
  cfg.priority = cfg.priority || { hot: 50, warm: 30 };
  cfg.scraping.headless = String(env.HEADLESS ?? 'true').toLowerCase() !== 'false';
  return {
    serverUrl: validateServerUrl(env.SERVER_URL, String(env.ALLOW_INSECURE_HTTP).toLowerCase() === 'true'),
    token,
    profileDir: env.BROWSER_PROFILE_DIR ? path.resolve(env.BROWSER_PROFILE_DIR) : path.join(ROOT, '.browser-profile'),
    version: require('../package.json').version,
    cfg,
  };
}

module.exports = { parseEnv, validateServerUrl, loadSettings, ROOT };
