// Reads a public Facebook Page About section or Instagram profile through the
// PoliteFetcher. Facebook and Instagram currently disallow all automated access
// in robots.txt, so in practice this records "skipped" and never opens the page.
const { extractPhones, phoneKey, nameMatchRatio } = require('./match');
const { emailsFromSnapshot } = require('./contact');
const { outcomeOf } = require('./polite');

const LOGIN_WALL = /log in to (continue|see)|you must log in|log into facebook|sign up to see|login • instagram|content isn.t available|this page isn.t available/i;
const NOT_FOUND = /page not found|sorry, this page isn.t available|couldn.t find this account|link you followed may be broken/i;

function facebookAboutUrl(url) {
  const u = new URL(url);
  if (u.pathname === '/profile.php') {
    u.searchParams.set('sk', 'about');
    return u.href;
  }
  return `${u.origin}${u.pathname.replace(/\/$/, '')}/about`;
}

const jsonString = (raw) => {
  try { return JSON.parse(`"${raw}"`); } catch { return ''; }
};

async function checkSocial(fetcher, context, platform, url, place, timeoutMs) {
  const fetchedUrl = platform === 'facebook' ? facebookAboutUrl(url) : url;
  const res = await fetcher.fetchPage(context, fetchedUrl, { timeoutMs, variant: 'desktop' });
  const out = { platform, url, fetchedUrl, outcome: outcomeOf(res), readable: false, emails: [], externalUrl: null, messaging: 'unverified', messagingNote: null };

  if (res.robotsDisallowed) {
    out.messagingNote = `not checked: ${res.error}`;
    return out;
  }
  const snap = res.snapshot;
  if (!snap) {
    out.messagingNote = 'page could not be loaded';
    return out;
  }
  const { ogTitle = '', ogDescription = '' } = snap.meta || {};
  if (NOT_FOUND.test(`${snap.title} ${ogTitle} ${snap.text.slice(0, 600)}`)) {
    out.broken = true;
    out.messagingNote = 'profile not found';
    return out;
  }
  const walled = LOGIN_WALL.test(snap.text.slice(0, 3000));
  out.readable = !!(ogTitle || ogDescription) || !walled;
  if (!out.readable) {
    out.messagingNote = 'login wall, so the page could not be read';
    return out;
  }

  const profileText = `${ogTitle}\n${ogDescription}\n${walled ? '' : snap.text}`;
  out.nameMatch = +nameMatchRatio(place.name, `${ogTitle} ${snap.title}`).toFixed(2);
  const phones = extractPhones(profileText);
  const mapsPhone = phoneKey(place.phone);
  out.phoneMatches = mapsPhone && phones.size ? phones.has(mapsPhone) : null;
  out.emails = emailsFromSnapshot({ links: snap.links, cfEmails: snap.cfEmails, text: profileText }, fetchedUrl);

  if (platform === 'instagram') {
    const bio = snap.html.match(/"biography"\s*:\s*"((?:[^"\\]|\\.)*)"/);
    if (bio) out.emails.push(...emailsFromSnapshot({ text: jsonString(bio[1]) }, fetchedUrl));
    const ext = snap.html.match(/"external_url"\s*:\s*"((?:[^"\\]|\\.)*)"/);
    if (ext) out.externalUrl = jsonString(ext[1]) || null;
  } else {
    out.externalUrl = (snap.links || []).map((l) => l.href).find((h) => /^https?:/.test(h) && !/facebook\.com|fb\.com|fbcdn|instagram\.com|meta\.com/.test(h)) || null;
    if (!walled && /\b(send message|message)\b/i.test(snap.text.slice(0, 4000))) {
      out.messaging = 'open';
      out.messagingNote = 'Message button visible on the Page';
    }
  }

  const audience = ogDescription.match(/([\d.,]+[KkMm]?)\s+(followers|likes)/i);
  if (audience) out.audience = `${audience[1]} ${audience[2].toLowerCase()}`;
  return out;
}

module.exports = { checkSocial };
