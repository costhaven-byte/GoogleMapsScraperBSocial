// Audits a business website: health, mobile-friendliness, booking/forms/chat,
// and whether the NAP (name/address/phone) matches the Google Maps listing.
// Also returns the fetched page snapshots so contact channels can be extracted.
const { extractPhones, phoneKey, nameMatchRatio, addressFound } = require('./match');
const { hasContactForm, baseDomain, DIRECTORY_BRANDS } = require('./contact');
const { outcomeOf } = require('./polite');

const SOCIAL_HOSTS = {
  facebook: /(^|\.)facebook\.com|(^|\.)fb\.com/,
  instagram: /(^|\.)instagram\.com/,
  tiktok: /(^|\.)tiktok\.com/,
  twitter: /(^|\.)(twitter|x)\.com/,
  linkedin: /(^|\.)linkedin\.com/,
  youtube: /(^|\.)youtube\.com/,
};

// Listing "websites" that aren't really the business's own site.
const NOT_A_REAL_SITE = /(^|\.)(facebook|fb|instagram|tiktok|twitter|x|linkedin|youtube|linktr|yelp|business\.site|sites\.google|wa\.me|whatsapp|booksy|vagaro|fresha|square\.site|squareup|calendly|setmore|toasttab|ubereats|doordash|grubhub|opentable|resy|beacons|linkin|lnk|taplink|solo)\./;

const BOOKING_PROVIDERS = /calendly\.com|acuityscheduling|squareup\.com\/appointments|book\.squareup|square\.site|booksy\.com|vagaro\.com|fresha\.com|mindbodyonline|healcode|setmore\.com|simplybook\.|schedulicity|glossgenius|styleseat|zocdoc\.com|opentable\.com|resy\.com|sevenrooms\.com|exploretock\.com|yelp\.com\/reservations|janeapp\.com|housecallpro\.com|getjobber\.com|servicetitan|appointy\.com|picktime\.com|gettimely\.com|phorest\.com|zenoti\.com|joinblvd\.com|boulevard\.io|genbook\.com|10to8\.com|youcanbook\.me|bookeo\.com|checkfront\.com|fareharbor\.com|tablein\.com|quandoo\.|thefork\.|bookings\.wix|wix-bookings|meetings\.hubspot|localmed\.com|nexhealth\.com|solutionreach|demandforce|getweave\.com|shopmonkey|tekmetric|xtime\.com|tidycal\.com|savvycal\.com|booker\.com|salonbiz|rosysalonsoftware|toasttab\.com/i;
const BOOKING_TEXT = /book (now|online|an appointment|a table|your)|schedule (an |your )?(appointment|consultation|service)|online booking|reserve (a table|now|online)|make a reservation|request an appointment|احجز (الآن|موعد)|حجز موعد|احجز طاولة/i;
const CHAT_PROVIDERS = /widget\.intercom\.io|intercomcdn|code\.tidio\.co|js\.driftt\.com|client\.crisp\.chat|cdn\.livechatinc\.com|embed\.tawk\.to|static\.zdassets\.com|zopim|js\.usemessages\.com|olark\.com|freshchat|connect\.podium\.com|widget\.birdeye|birdeye\.com\/embed|smith\.ai|ada\.support|chatbot\.com|manychat\.com|landbot\.io|botpress|voiceflow|chatra\.io|userlike|liveperson|kommunicate\.io|gorgias\.chat|reamaze\.com|tiledesk|chaport|smartsupp|jivosite|botsonic|chatbase\.co|getbutton\.io|customerchat|widgets\.leadconnectorhq|chat-widget/i;
// Marketing tags. Their absence is the signal: a business with no pixel isn't
// running measurable paid media, and no analytics means nothing is being measured.
const TRACKERS = {
  metaPixel: /connect\.facebook\.net\/[^"']*fbevents\.js|fbq\s*\(\s*['"]init['"]|facebook\.com\/tr\?id=/i,
  googleAds: /googleads\.g\.doubleclick\.net|gtag\s*\(\s*['"]config['"]\s*,\s*['"]AW-|google_conversion_id|googleadservices\.com|googlesyndication\.com/i,
  analytics: /gtag\/js\?id=G-|google-analytics\.com|googletagmanager\.com\/gtag\/js|plausible\.io\/js|matomo\.js|clarity\.ms|hotjar\.com/i,
  tagManager: /googletagmanager\.com\/gtm\.js|GTM-[A-Z0-9]{4,}/,
  tiktok: /analytics\.tiktok\.com/i,
  snapchat: /sc-static\.net\/scevent|tr\.snapchat\.com/i,
  linkedin: /snap\.licdn\.com|linkedin\.com\/px/i,
};
// Platform fingerprints must be specific paths or hosts. Bare product names
// ("woocommerce", "magento") appear inside unrelated widget bundles, which made
// plain HVAC sites look like online stores.
const ECOMMERCE = [
  ['Shopify', /cdn\.shopify\.com|\/cdn\/shop\/|Shopify\.theme|shopify\.com\/s\/files/i],
  ['WooCommerce', /wp-content\/plugins\/woocommerce|wc-ajax=|woocommerce-page|add-to-cart=\d+/i],
  ['Magento', /mage\/cookies\.js|data-mage-init|\/static\/version\d+\/frontend\//i],
  ['PrestaShop', /\/modules\/ps_|prestashop\.com\/|id_product=/i],
  ['OpenCart', /route=checkout\/cart|route=product\/product|catalog\/view\/theme/i],
  ['BigCommerce', /cdn\d+\.bigcommerce\.com/i],
  ['Salla', /salla\.sa|salla\.network|s-salla\.com/i],
  ['Zid', /zid\.store|zidapi|media\.zid\.store/i],
  ['Wix Stores', /wixstores|wix-stores/i],
  ['Squarespace Commerce', /\/api\/commerce\/|sqs-add-to-cart|sqs-cart/i],
];
// Something must actually be for sale. Arabic included: leads can be in MENA.
const CART_TEXT = /add to cart|add to basket|shopping cart|proceed to checkout|view (your )?cart|أضف إلى السلة|أضف للسلة|سلة التسوق|إتمام الطلب|الدفع الآن/i;
const CART_LINK = /(\?|&)add-to-cart=|\/cart\/?$|\/checkout\/?$|route=checkout\/cart|\/shop\/?$|\/store\/?$/i;
const VIDEO_EMBED = /youtube\.com\/embed|youtu\.be\/|player\.vimeo\.com|wistia\.(net|com)|<video[\s>]/i;

const PARKED = /domain (is|may be) for sale|buy this domain|this domain is parked|parked free|hugedomains|sedoparking|\bdan\.com\b|afternic|account (has been )?suspended|website (is )?coming soon|under construction|site not found|domain has expired|renew (this|your) domain|this site can.t be reached|default web site page|welcome to nginx|future home of/i;
const BUILDERS = [
  ['Wix', /wix\.com|wixstatic/i], ['Squarespace', /squarespace/i], ['GoDaddy Builder', /godaddy|img1\.wsimg/i],
  ['Weebly', /weebly/i], ['Shopify', /cdn\.shopify/i], ['Webflow', /webflow/i], ['Duda', /dudaone|multiscreensite/i],
  ['WordPress', /wp-content|wp-includes/i], ['Google Sites', /sites\.google/i],
];

const hostOf = (url) => {
  try { return new URL(url).hostname.toLowerCase(); } catch { return ''; }
};
const bareHost = (url) => hostOf(url).replace(/^www\./, '');

function classifyListingUrl(url) {
  const host = hostOf(url);
  const social = Object.entries(SOCIAL_HOSTS).find(([, re]) => re.test(host));
  if (social) return { type: 'social-only', platform: social[0] };
  if (NOT_A_REAL_SITE.test(`${host}.`)) return { type: 'third-party-page', platform: host };
  return { type: 'own-site' };
}

const PAGE_KINDS = [
  [/contact/, 'Website contact page'],
  [/about/, 'Website about page'],
  [/book|appointment|schedule|reserv/, 'Website booking page'],
];

// Contact, about and booking pages linked from the homepage (one of each first).
// If no contact page is linked, /contact is tried directly.
function pickExtraPages(home, max) {
  const base = new URL(home.finalUrl || home.url);
  const seen = new Set([base.href.split('#')[0]]);
  const picks = [];
  for (const l of home.snapshot.links || []) {
    if (!/^https?:/.test(l.href) || bareHost(l.href) !== bareHost(base.href)) continue;
    const href = l.href.split('#')[0];
    if (seen.has(href)) continue;
    const hay = `${new URL(href).pathname} ${l.text}`.toLowerCase();
    const kind = PAGE_KINDS.findIndex(([re]) => re.test(hay));
    if (kind < 0) continue;
    seen.add(href);
    picks.push({ url: href, kind, label: PAGE_KINDS[kind][1] });
  }
  if (!picks.some((p) => p.kind === 0)) {
    const guess = new URL('/contact', base).href;
    if (!seen.has(guess)) picks.push({ url: guess, kind: 0, label: 'Website /contact (not linked, tried directly)' });
  }
  const chosen = [];
  for (const kind of [0, 1, 2]) {
    const p = picks.find((x) => x.kind === kind);
    if (p) chosen.push(p);
  }
  for (const p of picks) if (!chosen.includes(p)) chosen.push(p);
  return chosen.slice(0, max);
}

async function auditWebsite(fetcher, context, url, place, timeoutMs, maxExtraPages = 3) {
  const site = { url, kind: classifyListingUrl(url), reachable: false, socialLinks: {}, fetchLog: [] };
  if (site.kind.type !== 'own-site') return { site, pages: [] };

  const home = await fetcher.fetchPage(context, url, { timeoutMs, variant: 'mobile' });
  site.fetchLog.push({ source: 'Website homepage', url, outcome: outcomeOf(home) });
  if (home.robotsDisallowed) {
    site.robotsBlocked = true;
    site.error = home.error;
    return { site, pages: [] };
  }
  site.status = home.status ?? null;
  if (!home.snapshot) {
    site.error = home.error || (home.status ? `HTTP ${home.status}` : 'no response');
    return { site, pages: [] };
  }

  // A listing "website" that redirects to a directory or booking platform isn't their own site.
  const finalDomain = baseDomain(home.finalUrl || url);
  if (finalDomain !== baseDomain(url) && (NOT_A_REAL_SITE.test(`${hostOf(home.finalUrl)}.`) || DIRECTORY_BRANDS.test(finalDomain))) {
    site.kind = { type: 'third-party-page', platform: finalDomain, redirectedFrom: bareHost(url) };
    return { site, pages: [] };
  }

  const snap = home.snapshot;
  // 401/403/429 (or a bot challenge page) means the site is up but refuses automated visitors.
  site.blocked = [401, 403, 429].includes(home.status) ||
    (home.status === 503 && /just a moment|attention required|verify you are human|captcha/i.test(`${snap.title} ${snap.text.slice(0, 1500)}`));
  site.loadSeconds = home.loadSeconds;
  site.finalUrl = home.finalUrl;
  site.https = (home.finalUrl || '').startsWith('https://');
  site.reachable = home.ok;
  site.parked = PARKED.test(`${snap.title} ${snap.text.slice(0, 3000)}`);
  site.wordCount = snap.text.split(/\s+/).filter(Boolean).length;

  const pages = home.ok ? [{ url: home.finalUrl || url, snapshot: snap }] : [];
  if (home.ok && !site.parked) {
    for (const extra of pickExtraPages(home, maxExtraPages)) {
      const r = await fetcher.fetchPage(context, extra.url, { timeoutMs, variant: 'mobile' });
      site.fetchLog.push({ source: extra.label, url: extra.url, outcome: outcomeOf(r) });
      if (r.ok && r.snapshot) pages.push({ url: extra.url, snapshot: r.snapshot });
    }
  }
  if (!pages.length) return { site, pages };

  const allText = pages.map((p) => `${p.snapshot.title}\n${p.snapshot.text}`).join('\n');
  const allHtml = pages.map((p) => p.snapshot.html).join('\n');
  const allLinks = pages.flatMap((p) => p.snapshot.links || []);
  const telLinks = allLinks.filter((l) => l.href.startsWith('tel:')).map((l) => l.href);

  site.builder = (BUILDERS.find(([, re]) => re.test(snap.html)) || [''])[0];
  site.mobileFriendly = snap.hasViewport && !snap.overflowsMobile;
  const years = [...allText.matchAll(/(?:©|copyright)\s*(?:\d{4}\s*[-–]\s*)?(\d{4})/gi)].map((m) => +m[1]);
  site.copyrightYear = years.length ? Math.max(...years) : null;
  site.onlineBooking =
    BOOKING_PROVIDERS.test(allHtml) || (BOOKING_TEXT.test(allText) && pages.some((p) => p.snapshot.iframes.length || p.snapshot.forms.length));
  site.bookingProvider = (allHtml.match(BOOKING_PROVIDERS) || [''])[0];
  site.contactForm = pages.some((p) => hasContactForm(p.snapshot));
  site.chatWidget = CHAT_PROVIDERS.test(allHtml);
  site.chatProvider = (allHtml.match(CHAT_PROVIDERS) || [''])[0];

  // Marketing maturity: what the business already runs, so the pitch targets gaps.
  site.trackers = Object.fromEntries(Object.entries(TRACKERS).map(([name, re]) => [name, re.test(allHtml)]));
  site.runsPaidMedia = site.trackers.metaPixel || site.trackers.googleAds || site.trackers.tiktok || site.trackers.snapchat;
  site.measures = site.trackers.analytics || site.trackers.tagManager;
  // A store is only a store when customers can actually buy: a cart has to exist.
  const platform = (ECOMMERCE.find(([, re]) => re.test(allHtml)) || [null])[0];
  const sells = CART_TEXT.test(allText) || allLinks.some((l) => CART_LINK.test(l.href));
  site.ecommerce = sells ? (platform || 'custom store') : null;
  site.hasVideo = pages.some((p) => VIDEO_EMBED.test(p.snapshot.html));
  site.metaDescription = pages.some((p) => (p.snapshot.meta?.description || '').trim().length >= 50);

  for (const l of allLinks) {
    const host = hostOf(l.href);
    for (const [platform, re] of Object.entries(SOCIAL_HOSTS)) {
      if (re.test(host) && !site.socialLinks[platform] && !/sharer|share\.php|intent\/tweet|\/plugins\//.test(l.href)) {
        site.socialLinks[platform] = l.href;
      }
    }
  }

  const sitePhones = extractPhones(`${allText} ${telLinks.join(' ')}`);
  const mapsPhone = phoneKey(place.phone);
  site.phoneOnSite = sitePhones.size > 0;
  site.phoneMatches = mapsPhone ? sitePhones.has(mapsPhone) : null;
  site.addressMatches = addressFound(place.address, allText);
  site.nameMatch = +nameMatchRatio(place.name, `${snap.title} ${allText.slice(0, 5000)} ${bareHost(site.finalUrl).replace(/\./g, ' ')}`).toFixed(2);

  return { site, pages };
}

module.exports = { auditWebsite, classifyListingUrl, SOCIAL_HOSTS };
