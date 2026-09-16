// Decides whether a business is CURRENTLY operating. A lead is only kept when
// Google Maps doesn't flag it closed AND there is recent, dated proof of life.

const UNIT_MONTHS = { minute: 0, hour: 0, day: 0, week: 0.25, month: 1, year: 12 };

// Parses Google's relative dates ("3 weeks ago", "a year ago", "Edited 2 months ago")
// into an approximate age in months. Returns null when unparseable.
function relativeAgeMonths(text) {
  if (!text) return null;
  const m = text.toLowerCase().match(/(\d+|an?|one)\s+(minute|hour|day|week|month|year)s?\s+ago/);
  if (!m) return null;
  const n = /^\d+$/.test(m[1]) ? parseInt(m[1], 10) : 1;
  return n * UNIT_MONTHS[m[2]];
}

function assessActivity(place, cfg) {
  const reasons = [];
  const status = (place.businessStatus || '').toLowerCase();

  if (status.includes('permanently closed')) {
    return { active: false, reasons: ['Google Maps: permanently closed'] };
  }
  if (status.includes('temporarily closed') && cfg.excludeTemporarilyClosed) {
    return { active: false, reasons: ['Google Maps: temporarily closed'] };
  }

  const ages = [
    ...place.reviewDates.map((d) => ({ kind: 'review', age: relativeAgeMonths(d), raw: d })),
    ...place.ownerResponseDates.map((d) => ({ kind: 'owner reply', age: relativeAgeMonths(d), raw: d })),
  ].filter((a) => a.age !== null);

  const freshest = ages.sort((a, b) => a.age - b.age)[0];
  const evidence = {
    newestReview: place.reviewDates[0] || null,
    newestOwnerReply: place.ownerResponseDates[0] || null,
    newestActivityMonths: freshest ? freshest.age : null,
  };

  if (!freshest) {
    if (place.reviewsHidden) {
      return { active: false, reasons: ['Google is hiding reviews for this place (limited view or a soft block on your connection), so it cannot be proven active'], ...evidence };
    }
    // A star rating means reviews exist; if none could be read, that's a scraping
    // failure, not a review-less business, so it must never slip through as "unverified".
    if (place.rating) {
      return { active: false, reasons: ['Has a star rating but no review dates could be read, so it cannot be proven active'], ...evidence };
    }
    if (cfg.allowUnverified && place.reviewCount === 0) {
      return { active: true, unverified: true, reasons: ['No reviews — activity unverified (allowUnverified on)'], ...evidence };
    }
    return { active: false, reasons: ['No dated reviews or owner replies — cannot prove it is active'], ...evidence };
  }

  if (freshest.age > cfg.maxReviewAgeMonths) {
    const caveat = place.reviewsSortedByNewest
      ? ''
      : ` — checked ${place.reviewDates.length} reviews unsorted (Google sign-in wall); run \`npm run login\` for an exact newest-first check`;
    return {
      active: false,
      reasons: [`Last sign of life was a ${freshest.kind} "${freshest.raw}" (limit ${cfg.maxReviewAgeMonths} months)${caveat}`],
      ...evidence,
    };
  }

  reasons.push(`Recent ${freshest.kind}: ${freshest.raw}`);
  return { active: true, reasons, ...evidence };
}

module.exports = { assessActivity, relativeAgeMonths };
