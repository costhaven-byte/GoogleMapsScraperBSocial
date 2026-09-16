// Contact-channel extraction and the contactability gate.
//
// Operator constraint: outreach happens from outside the US, so phone calls and
// SMS are unusable, and Facebook Messenger won't deliver links. A lead is only
// worth scoring if it has a written channel. Only contact details literally
// present on a fetched page are recorded; nothing is guessed or constructed.

const EMAIL_RE = /[a-z0-9][a-z0-9._%+-]{0,63}@[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?)*\.[a-z]{2,24}/gi;
const FILE_EXT = /\.(png|jpe?g|gif|webp|svg|avif|ico|css|js|json|mp4|webm|pdf)$/i;
const PLACEHOLDER_LOCAL = /^(your|youremail|yourname|your\.name|name|email|user|username|someone|example|test|johndoe|john\.doe|janedoe|jane\.doe|me|you|firstname|first\.last)$/i;
const NON_BUSINESS_DOMAINS = /(^|\.)(example\.(com|org|net)|domain\.com|email\.com|yourdomain\.com|yoursite\.com|website\.com|company\.com|mysite\.com|sentry\.io|wixpress\.com|wix\.com|squarespace\.com|godaddy\.com|secureserver\.net|weebly\.com|shopify\.com|facebook\.com|fb\.com|instagram\.com|google\.com|linktr\.ee|cloudflare\.com|w3\.org|schema\.org|typekit\.net)$/i;

// Addresses that are real but almost never belong to a US local business: foreign
// free-mail providers common on clone/spam sites, and directory/aggregator brands.
const UNLIKELY_PROVIDERS = /(^|\.)(qq\.com|163\.com|126\.com|yeah\.net|sina\.com|sohu\.com|aliyun\.com|yandex\.[a-z]+|mail\.ru)$/i;
const DIRECTORY_BRANDS = /mappway|yellowpages|bizapedia|manta\.com|chamberofcommerce|cybo\.com|brownbook|hotfrog|restaurantji|menupix|allmenus|opendi|n49\.com|merchantcircle|superpages|citysearch|localstack|mapquest|yelp\.com|tripadvisor|foursquare/i;
const FREE_MAIL = /^(gmail|googlemail|yahoo|ymail|outlook|hotmail|live|msn|aol|icloud|me|mac|comcast|att|sbcglobal|bellsouth|verizon|cox|charter|earthlink|protonmail|proton)\.[a-z.]+$/i;

const FORM_EMBEDS = /jotform\.com|typeform\.com|docs\.google\.com\/forms|forms\.gle|hsforms\.|forms\.hubspot|formstack\.com|cognitoforms\.com|123formbuilder|paperform\.co|tally\.so|formsite\.com|wufoo\.com|forms\.zohopublic|fillout\.com|leadconnectorhq\.com\/widget\/form/i;
const LINK_IN_BIO = /(^|\.)(linktr\.ee|beacons\.ai|linkin\.bio|lnk\.bio|bio\.link|taplink\.cc|solo\.to|campsite\.bio|many\.link|hoo\.be|linkpop\.com|stan\.store|msha\.ke|carrd\.co)$/i;
const WHATSAPP_HOSTS = /^(wa\.me|api\.whatsapp\.com|web\.whatsapp\.com|chat\.whatsapp\.com|wa\.link)$/i;

// example.com for www.shop.example.com (good enough for US business domains).
function baseDomain(urlOrHost) {
  let host = String(urlOrHost || '');
  try { host = new URL(host).hostname; } catch { /* already a host */ }
  return host.toLowerCase().replace(/^www\./, '').split('.').slice(-2).join('.');
}

function cleanEmail(raw) {
  let v = String(raw || '').trim();
  try { v = decodeURIComponent(v); } catch { /* keep as-is */ }
  v = v.replace(/^mailto:/i, '').split('?')[0].trim().toLowerCase();
  const m = v.match(/^[a-z0-9][a-z0-9._%+-]{0,63}@([a-z0-9.-]+\.[a-z]{2,24})$/);
  if (!m || FILE_EXT.test(v) || NON_BUSINESS_DOMAINS.test(m[1]) || PLACEHOLDER_LOCAL.test(v.split('@')[0])) return null;
  return v;
}

// Returns null if the address is usable, otherwise why it isn't trusted.
function emailRejection(value) {
  const domain = value.split('@')[1];
  if (UNLIKELY_PROVIDERS.test(domain)) return `${domain} addresses almost never belong to a US local business (common on clone/spam sites)`;
  if (DIRECTORY_BRANDS.test(value)) return 'belongs to a directory/aggregator site, not the business';
  return null;
}

function emailConfidence(value, businessDomain) {
  const domain = value.split('@')[1];
  if (businessDomain && baseDomain(domain) === businessDomain) return 'high (on their own domain)';
  if (FREE_MAIL.test(domain)) return 'medium (free email provider)';
  return 'low (unrelated domain)';
}

// Cloudflare "email protection" hides addresses as XOR-encoded hex on the page itself.
function decodeCloudflare(hex) {
  const key = parseInt(hex.slice(0, 2), 16);
  let out = '';
  for (let i = 2; i < hex.length; i += 2) out += String.fromCharCode(parseInt(hex.slice(i, i + 2), 16) ^ key);
  return out;
}

function emailsFromSnapshot(snap, sourceUrl) {
  const found = new Map();
  const add = (raw, via) => {
    const value = cleanEmail(raw);
    if (value && !found.has(value)) found.set(value, { value, source: sourceUrl, via });
  };
  for (const l of snap.links || []) {
    if (/^mailto:/i.test(l.href)) add(l.href, 'mailto link');
    const cf = l.href.match(/\/cdn-cgi\/l\/email-protection#([0-9a-f]+)/i);
    if (cf) add(decodeCloudflare(cf[1]), 'Cloudflare-protected link');
  }
  for (const hex of snap.cfEmails || []) add(decodeCloudflare(hex), 'Cloudflare-protected text');
  for (const m of (snap.text || '').matchAll(EMAIL_RE)) add(m[0], 'page text');
  for (const m of (snap.html || '').matchAll(/"email"\s*:\s*"([^"]+)"/gi)) add(m[1], 'structured data');
  return [...found.values()];
}

// A form someone can type a message into. Newsletter sign-ups, search boxes and
// booking widgets don't count: you can't pitch through them.
function hasContactForm(snap) {
  const forms = (snap.forms || []).filter((f) => !f.search);
  return (
    forms.some((f) => f.textareas > 0) ||
    forms.some((f) => f.hasEmail && f.inputs >= 3) ||
    (snap.iframes || []).some((src) => FORM_EMBEDS.test(src))
  );
}

const unwrapGoogle = (href) => {
  const m = String(href).match(/^https?:\/\/(?:www\.)?google\.[a-z.]+\/url\?(?:.*&)?q=([^&]+)/i);
  return m ? decodeURIComponent(m[1]) : href;
};

function instagramFromUrl(href) {
  let u;
  try { u = new URL(unwrapGoogle(href)); } catch { return null; }
  if (!/(^|\.)instagram\.com$/i.test(u.hostname)) return null;
  const seg = u.pathname.split('/').filter(Boolean)[0];
  if (!seg || /^(p|reel|reels|tv|explore|accounts|stories|direct|about|developer|legal|web|challenge)$/i.test(seg)) return null;
  if (!/^[A-Za-z0-9._]{1,30}$/.test(seg)) return null;
  const handle = seg.toLowerCase();
  return { handle, url: `https://www.instagram.com/${handle}/` };
}

// allowShareLinks: facebook.com/share/<id> links are only trusted when they come
// from the listing's own "website" field, where they point at the business Page.
function facebookFromUrl(href, { allowShareLinks = false } = {}) {
  let u;
  try { u = new URL(unwrapGoogle(href)); } catch { return null; }
  if (!/(^|\.)(facebook|fb)\.com$/i.test(u.hostname)) return null;
  const segs = u.pathname.split('/').filter(Boolean);
  if (!segs.length) return null;
  if (segs[0] === 'share' && segs.length >= 2 && allowShareLinks) {
    return { url: `https://www.facebook.com/share/${segs[1]}/`, note: 'share link from the Maps listing; opens their Page' };
  }
  if (/^(sharer|sharer\.php|share|share\.php|dialog|plugins|tr|login|login\.php|policies|privacy|help|hashtag|watch|events|groups|marketplace|gaming|photo\.php|photo|photos|story\.php|permalink\.php|l\.php|business|ads|legal|about|home\.php|reel|videos)$/i.test(segs[0])) return null;
  if (segs[0] === 'profile.php') {
    const id = u.searchParams.get('id');
    return id && /^\d+$/.test(id) ? { url: `https://www.facebook.com/profile.php?id=${id}` } : null;
  }
  if (segs[0] === 'pages' || segs[0] === 'people') return segs.length >= 2 ? { url: `https://www.facebook.com/${segs.slice(0, 3).join('/')}` } : null;
  if (!/^[A-Za-z0-9.\-]{2,100}$/.test(segs[0])) return null;
  return { url: `https://www.facebook.com/${segs[0]}` };
}

// Click-to-chat links: wa.me/201234567890, api.whatsapp.com/send?phone=…,
// or a chat.whatsapp.com group invite. A written channel where links are fine.
function whatsappFromUrl(href) {
  let u;
  try { u = new URL(unwrapGoogle(href)); } catch { return null; }
  if (!WHATSAPP_HOSTS.test(u.hostname.replace(/^www\./, ''))) return null;
  if (/^chat\.whatsapp\.com$/i.test(u.hostname)) {
    return u.pathname.length > 1 ? { url: u.href, kind: 'group invite', display: 'group invite' } : null;
  }
  const raw = u.searchParams.get('phone') || u.pathname.split('/').filter(Boolean)[0] || '';
  const number = raw.replace(/\D/g, '');
  if (number.length < 8 || number.length > 15) return null;
  return { url: `https://wa.me/${number}`, number, display: `+${number}` };
}

const isLinkInBio = (href) => {
  try { return LINK_IN_BIO.test(new URL(href).hostname); } catch { return false; }
};

// Emails on the business's own domain first, then free-mail, then mailto links.
function rankEmails(emails) {
  const rank = (e) => (e.confidence.startsWith('high') ? 0 : e.confidence.startsWith('medium') ? 2 : 4) + (e.via === 'mailto link' ? 0 : 1);
  return [...emails].sort((a, b) => rank(a) - rank(b));
}

// Tier A–D. D is the hard gate: those leads are never scored or surfaced.
//
// `phoneIsChannel` depends on where the sender is. Calling and texting a lead in
// your own country is a real channel (tier B); from abroad it isn't, which is why
// it stays off by default. WhatsApp always counts: it's written and links work.
function contactability(channels, place, site, opts = {}) {
  const phoneIsChannel = !!opts.phoneIsChannel && !!place.phone;
  const usable = [];
  if (channels.emails.length) usable.push('email');
  if (channels.whatsapp) usable.push('WhatsApp');
  if (channels.contactFormUrl) usable.push('contact form');
  if (channels.instagram) usable.push('Instagram DM');
  if (phoneIsChannel) usable.push('phone call / SMS');
  if (channels.facebook) usable.push('Facebook Messenger');
  const make = (tier, reasonCode, linksInOpener, summary) => ({ tier, reasonCode, linksInOpener, usableChannels: usable, summary });

  if (channels.emails.length) return make('A', 'EMAIL_FOUND', 'yes', `email ${channels.emails[0].value}`);
  if (channels.whatsapp) return make('A', 'WHATSAPP', 'yes', `WhatsApp ${channels.whatsapp.display}`);
  if (channels.contactFormUrl) {
    return make('B', 'CONTACT_FORM', 'risky', `contact form${channels.instagram ? ` + Instagram @${channels.instagram.handle}` : ''}`);
  }
  if (channels.instagram) return make('B', 'INSTAGRAM_DM', 'risky', `Instagram @${channels.instagram.handle}`);
  if (phoneIsChannel) return make('B', 'PHONE_CALL', 'yes', `phone ${place.phone} (call or SMS)`);
  if (channels.facebook) {
    return make('C', 'FACEBOOK_MESSENGER_ONLY', 'no', `Facebook Page only (messaging ${channels.facebook.messaging}), so the opener must work without a link`);
  }

  const uncheckable = site.kind.type === 'own-site' && (site.robotsBlocked || site.blocked);
  const reasonCode = uncheckable ? 'WEBSITE_NOT_CHECKABLE' : place.phone ? 'PHONE_ONLY' : place.address ? 'ADDRESS_ONLY' : 'NO_CONTACT_DETAILS';
  let why;
  if (!place.website) why = 'no website and no email/social links on the Maps listing';
  else if (site.kind.type === 'social-only' || site.kind.type === 'third-party-page') why = `listing "website" is ${site.kind.platform}, with no usable channel found`;
  else if (site.cloneSuspect) why = 'listing website looks like a clone/spam site (its contact details also appear on other businesses\' sites)';
  else if (site.robotsBlocked) why = "website's robots.txt disallows automated access, so it wasn't checked. Worth a manual look";
  else if (site.blocked) why = `website blocks automated visitors (HTTP ${site.status}), so it wasn't checked. Worth a manual look`;
  else if (!site.reachable) why = 'website is down, so no channel could be found';
  else why = 'website has no email, contact form or social links';
  return { ...make('D', reasonCode, 'no', `${reasonCode.toLowerCase().replace(/_/g, ' ')}: ${why}`), why };
}

function contactDistribution(enriched, excluded, queries) {
  const empty = () => ({ A: 0, B: 0, C: 0, D: 0, inactive: 0 });
  const overall = empty();
  const byQuery = Object.fromEntries(queries.map((q) => [q, empty()]));
  for (const e of enriched) {
    overall[e.contact.tier]++;
    (byQuery[e.query] ||= empty())[e.contact.tier]++;
  }
  for (const e of excluded) {
    overall.inactive++;
    (byQuery[e.query] ||= empty()).inactive++;
  }
  return { overall, byQuery };
}

function formatTiers(t) {
  const total = t.A + t.B + t.C + t.D;
  const reachable = total - t.D;
  const pct = total ? ` (${Math.round((reachable / total) * 100)}%)` : '';
  return `A ${t.A} · B ${t.B} · C ${t.C} · D ${t.D}  →  ${reachable}/${total} reachable${pct}${t.inactive ? ` · ${t.inactive} inactive dropped earlier` : ''}`;
}

module.exports = {
  baseDomain,
  cleanEmail,
  emailRejection,
  emailConfidence,
  emailsFromSnapshot,
  hasContactForm,
  instagramFromUrl,
  facebookFromUrl,
  whatsappFromUrl,
  isLinkInBio,
  rankEmails,
  contactability,
  contactDistribution,
  formatTiers,
  DIRECTORY_BRANDS,
};
