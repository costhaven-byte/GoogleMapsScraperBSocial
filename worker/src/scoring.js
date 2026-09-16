// Problem-severity scoring (0-100) for leads that already passed the
// contactability gate. Contactability itself is never scored here.
//
// The areas below map to what the agency sells:
//   BSocial   strategy, creative & production, Reels, online advertising,
//             analytics, community management, UI/UX, AI chatbot kit,
//             landing pages, lead magnets, e-commerce and SEM/GDN creatives
//   Vhorus    website development, mobile apps, AR/VR, branding,
//             domain / hosting / email
//
// Only gaps the scraper can actually observe earn points. Anything that needs a
// human judgement (brand quality, ad creative quality, app ideas) is left out,
// so the ranking stays honest: it measures visible, verifiable gaps.

const AREAS = [
  { key: 'web', label: 'Website & UX', max: 25 },
  { key: 'conversion', label: 'Conversion & lead capture', max: 20 },
  { key: 'paidMedia', label: 'Paid media & tracking', max: 20 },
  { key: 'social', label: 'Social presence & content', max: 15 },
  { key: 'automation', label: 'Chat & response', max: 10 },
  { key: 'brand', label: 'Brand & listing consistency', max: 10 },
];

const APPOINTMENT = /salon|barber|spa\b|nail|beauty|massage|lash|brow|wax|facial|esthetic|dentist|dental|orthodont|clinic|doctor|physician|chiropract|physio|therap|counsel|psycholog|medical|optometr|dermatolog|veterinar|\bvet\b|groom|tattoo|piercing|gym|fitness|yoga|pilates|personal trainer|martial|dance|tutor|lesson|driving school|photograph|studio|med spa|acupunct|podiatr|hair|makeup|tanning/i;
const RESERVATION = /restaurant|cafe|café|bistro|grill|steakhouse|sushi|pizzeria|brasserie|bar\b|pub\b|lounge|winery|brewery|hotel|motel|\binn\b|lodge|bed & breakfast|venue|escape room|bowling|golf|resort|travel agency|tour/i;
const QUOTE = /plumb|electric|hvac|heating|air condition|roof|contractor|construct|landscap|lawn|tree service|pest|mover|moving|cleaning|cleaner|janitor|painter|painting|remodel|handyman|flooring|fence|pool|garage door|locksmith|auto|mechanic|body shop|detailing|towing|tire|diesel|muffler|brake|transmission|oil change|truck repair|windshield|glass|collision|welding|window|solar|pressure wash|carpet|junk removal|appliance repair|lawyer|attorney|law firm|accountant|tax|insurance|real estate|mortgage|consult|marketing|printing|signs|catering|event planner|wedding|interior design|architect|developer|clinic supplies|logistics|shipping/i;
// Businesses whose customers choose with their eyes: the strongest fit for
// Reels, creative production and community management.
const CONSUMER = /restaurant|cafe|café|bakery|patisserie|dessert|juice|coffee|food|grill|pizzeria|sushi|salon|barber|spa\b|nail|beauty|makeup|lash|cosmetic|clothing|fashion|boutique|apparel|shoe|jewel|accessor|furniture|decor|home goods|gift|florist|flower|toy|pet (shop|store)|gym|fitness|yoga|studio|photograph|hotel|resort|travel|tour|event|wedding|catering|retail|store|shop|market|supermarket|pharmacy|optic|mobile phone|electronics|car dealer|showroom|clinic|dental|medical|academy|school|nursery|kids/i;

const UNRESPONSIVE = /never (called|got a call|heard) back|no (one|body) (answer|pick)|didn.t (answer|pick up|call back|respond)|couldn.t (get through|reach)|can.t (get through|reach)|voicemail|no response|never respond|hard to (reach|contact|get a hold)|unresponsive|never returned|won.t (answer|respond)|phone (just )?rings|mailbox (is )?full|no reply|ignored my|لا يردون|لا يرد|مش بيردوا|مفيش رد/i;

const DIY_BUILDERS = ['Wix', 'GoDaddy Builder', 'Weebly', 'Google Sites'];
const FREE_MAIL = /@(gmail|googlemail|yahoo|ymail|outlook|hotmail|live|msn|aol|icloud)\./i;

function bookingType(category) {
  if (APPOINTMENT.test(category)) return 'appointment';
  if (RESERVATION.test(category)) return 'reservation';
  if (QUOTE.test(category)) return 'quote';
  return 'general';
}

/**
 * @param {object} input
 * @param {object} input.place    Google Maps listing
 * @param {object} input.site     website audit (see website.js)
 * @param {Array}  input.socials  social checks
 * @param {object} input.channels contact channels found (see enrich.js)
 * @param {object} input.services which areas to score, from config.json
 */
function scoreLead({ place, site, socials = [], channels = {}, services = {}, now = new Date() }) {
  const lines = []; // { area, points, reason }
  const add = (area, points, reason) => {
    if (services[area] !== false) lines.push({ area, points, reason });
  };
  const pitch = new Set();
  const type = bookingType(place.category);
  const consumerFacing = CONSUMER.test(place.category || '');
  const hasOwnSite = site.kind.type === 'own-site';
  const siteUnknown = hasOwnSite && (site.robotsBlocked || site.blocked || site.cloneSuspect);
  const siteWorks = hasOwnSite && !siteUnknown && site.reachable && !site.parked;

  // ---- Website & UX (25) ----------------------------------------------------
  if (!place.website) {
    add('web', 18, 'No website at all');
    pitch.add('New website');
  } else if (site.kind.type === 'social-only') {
    add('web', 15, `"Website" is just a ${site.kind.platform} page`);
    pitch.add('New website');
  } else if (site.kind.type === 'third-party-page') {
    add('web', 14, `"Website" is a third-party page (${site.kind.platform})`);
    pitch.add('New website');
  } else if (siteUnknown) {
    add('web', 0, site.cloneSuspect
      ? 'Listing website looks like a clone/spam site, so it was not audited'
      : site.blocked
        ? `Website not audited: it blocks automated visitors (HTTP ${site.status})`
        : 'Website not audited: its robots.txt disallows automated access');
  } else if (!site.reachable) {
    add('web', 20, `Website is down or unreachable${site.error ? ` (${String(site.error).slice(0, 60)})` : ''}`);
    pitch.add('Hosting, domain & email');
    pitch.add('New website');
  } else if (site.parked) {
    add('web', 20, 'Website is parked, expired or suspended');
    pitch.add('Hosting, domain & email');
    pitch.add('New website');
  } else {
    const before = lines.length;
    if (!site.mobileFriendly) add('web', 10, 'Website is not mobile-friendly');
    if (!site.https) add('web', 7, 'Website has no HTTPS (browser shows "Not secure")');
    const age = site.copyrightYear ? now.getFullYear() - site.copyrightYear : 0;
    if (age >= 3) add('web', 6, `Website looks abandoned (© ${site.copyrightYear})`);
    else if (age === 2) add('web', 3, `Website footer is outdated (© ${site.copyrightYear})`);
    if (site.loadSeconds > 6) add('web', 5, `Website is slow (${site.loadSeconds}s)`);
    else if (site.loadSeconds > 3.5) add('web', 2, `Website is sluggish (${site.loadSeconds}s)`);
    if (site.wordCount < 150) add('web', 4, `Very thin website content (${site.wordCount} words)`);
    if (DIY_BUILDERS.includes(site.builder)) add('web', 3, `DIY site builder (${site.builder})`);
    if (lines.length > before) {
      add('web', 8, 'Has a real website that underperforms: a proven buyer with a working contact path');
      pitch.add('Website redesign / UI-UX');
    }
  }

  // ---- Conversion & lead capture (20) ---------------------------------------
  if (!siteUnknown) {
    const needsBooking = type === 'appointment' || type === 'reservation';
    const bookingLabel = type === 'reservation' ? 'online reservations' : 'online booking';
    if (!siteWorks) {
      add('conversion', 6, 'Nowhere online to capture an enquiry');
      pitch.add('Landing page & lead capture');
      if (needsBooking && !place.mapsBookingLink) add('conversion', 6, `No ${bookingLabel} anywhere (${type}-based business)`);
    } else {
      if (!site.contactForm) {
        add('conversion', type === 'quote' ? 9 : 7, type === 'quote' ? 'No quote-request form on the website' : 'No contact or lead form on the website');
        pitch.add('Landing page & lead capture');
      }
      if (needsBooking && !site.onlineBooking) {
        add('conversion', 7, `No ${bookingLabel} on the website`);
        pitch.add('Online booking');
      }
      if (site.ecommerce) {
        add('conversion', 4, `Online store (${site.ecommerce}): every visit is a sale to win or lose`);
        pitch.add('E-commerce sales creatives');
      }
      if (!channels.whatsapp && consumerFacing) {
        add('conversion', 3, 'No WhatsApp / click-to-chat button for quick enquiries');
        pitch.add('AI chatbot kit');
      }
    }
  }

  // ---- Paid media & tracking (20) -------------------------------------------
  if (siteWorks) {
    const t = site.trackers || {};
    if (!t.metaPixel) {
      add('paidMedia', 7, 'No Meta pixel: Facebook/Instagram ads can\'t be measured or retargeted');
      pitch.add('Meta awareness & lead-gen campaign');
    }
    if (!t.googleAds) {
      add('paidMedia', 6, 'No Google Ads or remarketing tag on the site');
      pitch.add('Google Discovery & SEM creatives');
    }
    if (!site.measures) {
      add('paidMedia', 5, 'No analytics at all: nothing on the site is measured');
      pitch.add('Analytics setup');
    }
    if (site.runsPaidMedia && !site.contactForm) {
      add('paidMedia', 4, 'Running paid media but sending traffic to a page with no lead capture');
      pitch.add('Landing page & lead capture');
    }
    if (site.ecommerce && !t.metaPixel) {
      add('paidMedia', 3, `Online store (${site.ecommerce}) with no Meta pixel: no catalogue ads or retargeting`);
      pitch.add('E-commerce sales creatives');
    }
  } else if (!siteUnknown) {
    add('paidMedia', 8, 'No working website to advertise to, so paid traffic has nowhere to land');
    pitch.add('Landing page & lead capture');
  }

  // ---- Social presence & content (15) ---------------------------------------
  const socialLinks = site.socialLinks || {};
  if (!channels.instagram) {
    add('social', 6, 'No Instagram profile found');
    pitch.add('Reels & content production');
  }
  if (!channels.facebook) {
    add('social', 4, 'No Facebook Page found');
    pitch.add('Meta awareness & lead-gen campaign');
  }
  if (consumerFacing && place.reviewCount >= 50 && !channels.instagram) {
    add('social', 3, `Visual, consumer-facing business with ${place.reviewCount} reviews and no Instagram`);
    pitch.add('Reels & content production');
  }
  if (siteWorks && !Object.keys(socialLinks).length) {
    add('social', 2, 'Website links to no social profiles');
  }
  if (siteWorks && !site.hasVideo && consumerFacing) {
    add('social', 2, 'No video anywhere on the website');
    pitch.add('Reels & content production');
  }

  // ---- Chat & response (10) -------------------------------------------------
  if (!siteUnknown && (!siteWorks || !site.chatWidget)) {
    add('automation', 4, 'No chat or AI assistant to answer customers 24/7');
    pitch.add('AI chatbot kit');
  }
  const complaints = (place.reviewTexts || []).filter((t) => UNRESPONSIVE.test(t));
  if (complaints.length) {
    add('automation', 4, `${complaints.length} recent review(s) complain they couldn't reach the business`);
    pitch.add('AI chatbot kit');
  }
  if (place.reviewCount >= 150) add('automation', 3, `High customer volume (${place.reviewCount} reviews) to handle`);
  else if (place.reviewCount >= 50) add('automation', 2, `Solid customer volume (${place.reviewCount} reviews)`);
  if (place.reviewCount >= 10 && !(place.ownerResponseDates || []).length) {
    add('automation', 3, 'Owner never replies to Google reviews');
    pitch.add('Community management');
  }

  // ---- Brand & listing consistency (10) -------------------------------------
  if (place.unclaimed) {
    add('brand', 3, 'Google Business Profile is unclaimed');
    pitch.add('Claim & fix Google listing');
  }
  if (siteWorks) {
    if (place.phone && site.phoneOnSite && site.phoneMatches === false) add('brand', 3, `Phone on the website doesn't match Google Maps (${place.phone})`);
    else if (place.phone && !site.phoneOnSite) add('brand', 2, 'Phone number is not shown on the website');
    if (site.addressMatches === false) add('brand', 2, 'Google Maps address not found on the website');
    if (site.nameMatch < 0.5) add('brand', 2, "Business name on the website doesn't match Google Maps");
    if (!site.metaDescription) {
      add('brand', 2, 'No meta description: poor search and share previews');
      pitch.add('Landing page & lead capture');
    }
  }
  const email = (channels.emails || [])[0];
  if (email && FREE_MAIL.test(email.value)) {
    add('brand', 2, `Uses a free email address for business (${email.value})`);
    pitch.add('Hosting, domain & email');
  }
  for (const s of socials) {
    if (s.broken) add('brand', 2, `Linked ${s.platform} profile is broken or missing`);
    else if (s.readable && s.phoneMatches === false) add('brand', 2, `${s.platform} phone differs from Google Maps`);
  }
  if (!siteWorks && !siteUnknown && place.website) pitch.add('Branding & identity');

  // ---- Totals ---------------------------------------------------------------
  const breakdown = {};
  const areas = AREAS.filter((a) => services[a.key] !== false).map((a) => {
    const points = Math.min(a.max, lines.filter((l) => l.area === a.key).reduce((sum, l) => sum + l.points, 0));
    breakdown[a.key] = points;

    return { ...a, points };
  });
  const score = areas.reduce((sum, a) => sum + a.points, 0);

  return {
    score,
    areas,
    breakdown,
    reasons: lines.filter((l) => l.points > 0 || l.reason).sort((a, b) => b.points - a.points),
    pitch: [...pitch].slice(0, 5),
    bookingType: type,
    consumerFacing,
  };
}

module.exports = { scoreLead, bookingType, AREAS };
