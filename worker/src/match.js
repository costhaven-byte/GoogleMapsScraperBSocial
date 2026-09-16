// Normalisation helpers for comparing Google Maps data against website/social data.

const STOPWORDS = new Set(['the', 'and', 'of', 'llc', 'inc', 'co', 'ltd', 'company', 'corp', '&']);

const STREET_ABBR = {
  street: 'st', avenue: 'ave', road: 'rd', boulevard: 'blvd', drive: 'dr', lane: 'ln',
  court: 'ct', place: 'pl', suite: 'ste', highway: 'hwy', parkway: 'pkwy', north: 'n',
  south: 's', east: 'e', west: 'w', circle: 'cir', terrace: 'ter',
};

const digits = (s) => (s || '').replace(/\D/g, '');

// Last 9 digits is enough to match across country-code / formatting variants.
const phoneKey = (s) => {
  const d = digits(s);
  return d.length >= 9 ? d.slice(-9) : null;
};

function extractPhones(text) {
  const found = new Set();
  const re = /(?:\+?\d{1,3}[\s.\-]?)?\(?\d{2,4}\)?[\s.\-]?\d{3,4}[\s.\-]?\d{3,4}/g;
  for (const m of text.matchAll(re)) {
    const k = phoneKey(m[0]);
    if (k && digits(m[0]).length <= 13) found.add(k);
  }
  return found;
}

const tokens = (s) =>
  (s || '')
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9\s]/g, ' ')
    .split(/\s+/)
    .filter((t) => t && !STOPWORDS.has(t))
    .map((t) => STREET_ABBR[t] || t);

// Fraction of the business-name tokens that appear in the given text.
function nameMatchRatio(name, text) {
  const nameTokens = tokens(name);
  if (!nameTokens.length) return 0;
  const hay = new Set(tokens(text));
  return nameTokens.filter((t) => hay.has(t)).length / nameTokens.length;
}

// Street line = house number + street name. Considered found when the number
// and most street-name tokens appear in the text.
function addressFound(address, text) {
  if (!address) return null;
  const street = address.split(',')[0];
  const streetTokens = tokens(street);
  const number = streetTokens.find((t) => /^\d+[a-z]?$/.test(t));
  if (!number) return null;
  const hay = tokens(text);
  const haySet = new Set(hay);
  if (!haySet.has(number)) return false;
  const rest = streetTokens.filter((t) => t !== number);
  if (!rest.length) return true;
  return rest.filter((t) => haySet.has(t)).length / rest.length >= 0.5;
}

module.exports = { phoneKey, extractPhones, nameMatchRatio, addressFound, tokens };
