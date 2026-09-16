// Channel enrichment: finds written ways to reach a business, in source order:
//   1. business website (homepage mailto links, /contact, /about, /book pages)
//   2. the Google Maps listing
//   3. the Facebook Page About section
//   4. the Instagram bio, then its link-in-bio target
// Every fetch goes through the PoliteFetcher (robots.txt, rate limit, cache).
const {
  baseDomain, cleanEmail, emailRejection, emailConfidence, emailsFromSnapshot, hasContactForm,
  instagramFromUrl, facebookFromUrl, whatsappFromUrl, isLinkInBio, rankEmails, contactability,
} = require('./contact');
const { checkSocial } = require('./social');
const { outcomeOf } = require('./polite');

async function enrichChannels({ fetcher, context, place, site, sitePages, checkSocials, timeoutMs }) {
  const sources = [...(site.fetchLog || [])];
  const businessDomain = site.kind.type === 'own-site' ? baseDomain(place.website) : null;
  const emails = new Map();
  const rejectedEmails = [];
  const addEmails = (list, { foreignHost } = {}) => {
    for (const e of list) {
      if (emails.has(e.value) || rejectedEmails.some((r) => r.value === e.value)) continue;
      const reason = foreignHost ? `found on ${foreignHost}, not the business's own website` : emailRejection(e.value);
      if (reason) rejectedEmails.push({ ...e, reason });
      else emails.set(e.value, { ...e, confidence: emailConfidence(e.value, businessDomain) });
    }
  };
  let instagram = null;
  let facebook = null;
  let whatsapp = null;
  const bioTargets = [];

  const considerLink = (href, source, opts) => {
    const ig = instagramFromUrl(href);
    if (ig && !instagram) instagram = { ...ig, source };
    const fb = facebookFromUrl(href, opts);
    if (fb && !facebook) facebook = { ...fb, source };
    const wa = whatsappFromUrl(href);
    if (wa && !whatsapp) whatsapp = { ...wa, source };
    if (isLinkInBio(href) && !bioTargets.some((b) => b.url === href)) bioTargets.push({ url: href, source });
  };

  // 1. Business website. Pages that redirected to another domain don't speak for the business.
  let contactFormUrl = null;
  for (const p of sitePages) {
    const host = baseDomain(p.url);
    if (businessDomain && host !== businessDomain) {
      addEmails(emailsFromSnapshot(p.snapshot, p.url), { foreignHost: host });
      continue;
    }
    addEmails(emailsFromSnapshot(p.snapshot, p.url));
    for (const l of p.snapshot.links || []) considerLink(l.href, p.url);
    if (!contactFormUrl && hasContactForm(p.snapshot)) contactFormUrl = p.url;
  }

  // 2. Google Maps listing
  for (const raw of place.listingEmails || []) {
    const value = cleanEmail(raw);
    if (value) addEmails([{ value, source: place.mapsUrl, via: 'Google Maps listing' }]);
  }
  if (place.website) considerLink(place.website, 'Google Maps listing', { allowShareLinks: true });
  for (const href of place.socialProfiles || []) considerLink(href, 'Google Maps listing');
  sources.push({ source: 'Google Maps listing', url: place.mapsUrl, outcome: 'ok (read during the Maps scrape)' });

  // 3. Facebook Page About  4. Instagram bio
  const socials = [];
  for (const platform of ['facebook', 'instagram']) {
    const profile = platform === 'facebook' ? facebook : instagram;
    if (!profile) continue;
    if (!checkSocials) {
      if (platform === 'facebook') Object.assign(facebook, { messaging: 'unverified', messagingNote: 'social checks turned off' });
      continue;
    }
    const r = await checkSocial(fetcher, context, platform, profile.url, place, timeoutMs);
    socials.push(r);
    sources.push({ source: platform === 'facebook' ? 'Facebook Page About' : 'Instagram bio', url: r.fetchedUrl, outcome: r.outcome });
    addEmails(r.emails);
    if (platform === 'facebook') Object.assign(facebook, { messaging: r.messaging, messagingNote: r.messagingNote });
    if (r.externalUrl) {
      considerLink(r.externalUrl, r.fetchedUrl);
      if (baseDomain(r.externalUrl) !== businessDomain && !bioTargets.some((b) => b.url === r.externalUrl)) {
        bioTargets.push({ url: r.externalUrl, source: r.fetchedUrl });
      }
    }
  }

  // Link-in-bio pages (from the bio, the website or the listing)
  for (const target of bioTargets.slice(0, 2)) {
    const r = await fetcher.fetchPage(context, target.url, { timeoutMs, variant: 'desktop' });
    sources.push({ source: 'Link-in-bio page', url: target.url, outcome: outcomeOf(r) });
    if (!r.ok || !r.snapshot) continue;
    addEmails(emailsFromSnapshot(r.snapshot, target.url));
    for (const l of r.snapshot.links || []) considerLink(l.href, target.url);
  }

  if (facebook && !facebook.messaging) {
    facebook.messaging = 'unverified';
    facebook.messagingNote = 'found after the social check ran';
  }

  const channels = {
    emails: rankEmails([...emails.values()]).slice(0, 5),
    rejectedEmails,
    contactFormUrl,
    instagram,
    facebook,
    whatsapp,
    phone: place.phone || null,
    sources,
  };
  return { channels, socials };
}

// Clone/spam sites reuse one contact address across many fake "business" websites.
// Any address found for more than one business in the run is distrusted, along with
// everything else published on the site(s) it came from. Returns how many leads changed.
function distrustSharedContacts(enriched, contactOpts = {}) {
  // Count every address found, including ones already rejected on their own
  // (e.g. a qq.com address): the site that published it is still a clone.
  const allFound = (c) => [...c.emails, ...c.rejectedEmails];
  const owners = new Map();
  for (const e of enriched) {
    for (const em of allFound(e.channels)) {
      if (!owners.has(em.value)) owners.set(em.value, new Set());
      owners.get(em.value).add(e.place.placeKey);
    }
  }
  const shared = new Set([...owners].filter(([, keys]) => keys.size > 1).map(([value]) => value));
  let changed = 0;
  for (const e of enriched) {
    const c = e.channels;
    const hits = allFound(c).filter((em) => shared.has(em.value));
    if (!hits.length) continue;
    const badDomains = new Set(hits.map((h) => baseDomain(h.source)).filter((d) => d && !/google\.|facebook\.|instagram\./.test(d)));
    const fromBad = (url) => !!url && badDomains.has(baseDomain(url));
    for (const em of c.emails) {
      if (!shared.has(em.value) && !fromBad(em.source)) continue;
      c.rejectedEmails.push({
        ...em,
        reason: shared.has(em.value)
          ? `same address appears on ${owners.get(em.value).size} different businesses' sites (clone/spam network)`
          : `published on ${baseDomain(em.source)}, a site that shares contact details with other businesses`,
      });
    }
    c.emails = c.emails.filter((em) => !shared.has(em.value) && !fromBad(em.source));
    if (fromBad(c.contactFormUrl)) c.contactFormUrl = null;
    if (c.instagram && fromBad(c.instagram.source)) c.instagram = null;
    if (c.facebook && fromBad(c.facebook.source)) c.facebook = null;
    if (c.whatsapp && fromBad(c.whatsapp.source)) c.whatsapp = null;
    if (badDomains.has(baseDomain(e.place.website))) e.site.cloneSuspect = true;
    e.contact = contactability(c, e.place, e.site, contactOpts);
    changed++;
  }
  return changed;
}

module.exports = { enrichChannels, distrustSharedContacts };
