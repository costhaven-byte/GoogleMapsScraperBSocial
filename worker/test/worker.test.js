// Run with: npm test   (no network, no browser)
const test = require('node:test');
const assert = require('node:assert');
const { parseEnv, validateServerUrl } = require('../src/config');
const { applyJobOptions, websiteStatus } = require('../src/pipeline');
const { JobReporter, JobGoneError } = require('../src/api');
const { whatsappFromUrl, contactability } = require('../src/contact');
const { scoreLead } = require('../src/scoring');

const noChannels = { emails: [], rejectedEmails: [], contactFormUrl: null, instagram: null, facebook: null, whatsapp: null };
const ownSite = { kind: { type: 'own-site' }, reachable: true, socialLinks: {} };

test('parseEnv reads values, quotes and comments', () => {
  const env = parseEnv('# comment\nSERVER_URL=https://x.test\nWORKER_TOKEN="gsw_abc"\n\nHEADLESS = false\n');
  assert.deepStrictEqual(env, { SERVER_URL: 'https://x.test', WORKER_TOKEN: 'gsw_abc', HEADLESS: 'false' });
});

test('validateServerUrl requires https except for localhost', () => {
  assert.strictEqual(validateServerUrl('https://leads.example.com/'), 'https://leads.example.com');
  assert.strictEqual(validateServerUrl('http://localhost:8000'), 'http://localhost:8000');
  assert.throws(() => validateServerUrl('http://leads.example.com'), /https/);
  assert.throws(() => validateServerUrl('not a url'), /valid URL/);
});

test('applyJobOptions clamps options without mutating the base config', () => {
  const base = { scraping: { maxResultsPerQuery: 40, checkSocials: true }, activity: { maxReviewAgeMonths: 11, allowUnverified: false } };
  const cfg = applyJobOptions(base, { limit: 999, months: 6, noSocials: true, allowUnverified: true });
  assert.strictEqual(cfg.scraping.maxResultsPerQuery, 200);
  assert.strictEqual(cfg.activity.maxReviewAgeMonths, 6);
  assert.strictEqual(cfg.scraping.checkSocials, false);
  assert.strictEqual(cfg.activity.allowUnverified, true);
  assert.strictEqual(base.scraping.maxResultsPerQuery, 40);
});

test('websiteStatus labels', () => {
  assert.strictEqual(websiteStatus({}, { kind: { type: 'none' } }), 'No website');
  assert.strictEqual(websiteStatus({ website: 'x' }, { kind: { type: 'own-site' }, reachable: false }), 'Website down');
});

test('whatsappFromUrl reads click-to-chat links', () => {
    assert.deepStrictEqual(whatsappFromUrl('https://wa.me/201234567890'), { url: 'https://wa.me/201234567890', number: '201234567890', display: '+201234567890' });
    assert.strictEqual(whatsappFromUrl('https://api.whatsapp.com/send?phone=+20 123 456 7890&text=hi').number, '201234567890');
    assert.strictEqual(whatsappFromUrl('https://wa.me/'), null);
    assert.strictEqual(whatsappFromUrl('https://example.com/whatsapp'), null);
});

test('contactability: WhatsApp is tier A, phone only counts when enabled', () => {
    const place = { phone: '+201234567890', address: '1 Nile St' };

    const phoneOff = contactability({ ...noChannels }, place, { kind: { type: 'none' } });
    assert.strictEqual(phoneOff.tier, 'D');
    assert.strictEqual(phoneOff.reasonCode, 'PHONE_ONLY');

    const phoneOn = contactability({ ...noChannels }, place, { kind: { type: 'none' } }, { phoneIsChannel: true });
    assert.strictEqual(phoneOn.tier, 'B');
    assert.strictEqual(phoneOn.reasonCode, 'PHONE_CALL');
    assert.ok(phoneOn.usableChannels.includes('phone call / SMS'));

    const whatsapp = contactability({ ...noChannels, whatsapp: { url: 'https://wa.me/20123', display: '+20123' } }, place, { kind: { type: 'none' } });
    assert.strictEqual(whatsapp.tier, 'A');
    assert.strictEqual(whatsapp.reasonCode, 'WHATSAPP');
    assert.strictEqual(whatsapp.linksInOpener, 'yes');

    // Phone never outranks a written channel.
    const form = contactability({ ...noChannels, contactFormUrl: 'https://x.test/contact' }, place, ownSite, { phoneIsChannel: true });
    assert.strictEqual(form.reasonCode, 'CONTACT_FORM');
});

test('scoreLead scores the agency service areas and suggests matching packages', () => {
    const place = {
        category: 'Restaurant', website: '', reviewCount: 200, reviewTexts: [], ownerResponseDates: [],
        unclaimed: true, mapsBookingLink: '', phone: '+20100', address: '1 Nile St',
    };
    const result = scoreLead({ place, site: { kind: { type: 'none' }, reachable: false, socialLinks: {} }, channels: noChannels, services: {} });

    assert.strictEqual(result.areas.length, 6);
    assert.strictEqual(result.score, result.areas.reduce((sum, a) => sum + a.points, 0));
    assert.strictEqual(result.breakdown.web, 18); // no website at all
    assert.strictEqual(result.breakdown.social, 13); // no Instagram, no Facebook, visual business
    assert.ok(result.pitch.includes('New website'));
    assert.ok(result.pitch.includes('Reels & content production'));
    assert.strictEqual(result.bookingType, 'reservation');
    assert.strictEqual(result.consumerFacing, true);
});

test('scoreLead rewards a site that already runs measured paid media', () => {
    const place = { category: 'Dentist', website: 'https://x.test/', reviewCount: 20, reviewTexts: [], ownerResponseDates: ['a week ago'], address: '' };
    const tracked = {
        ...ownSite, socialLinks: { instagram: 'https://instagram.com/x', facebook: 'https://facebook.com/x' },
        https: true, mobileFriendly: true, loadSeconds: 1.2, wordCount: 900, copyrightYear: new Date().getFullYear(),
        contactForm: true, onlineBooking: true, chatWidget: true, metaDescription: true, hasVideo: true, measures: true,
        trackers: { metaPixel: true, googleAds: true, analytics: true }, phoneOnSite: true, phoneMatches: true, addressMatches: true, nameMatch: 1,
    };
    const channels = { ...noChannels, instagram: { handle: 'x' }, facebook: { url: 'https://fb.com/x' }, whatsapp: { display: '+20' } };

    const good = scoreLead({ place, site: tracked, channels, services: {} });
    const blind = scoreLead({ place, site: { ...tracked, trackers: {}, measures: false }, channels, services: {} });

    assert.strictEqual(good.score, 0);
    assert.ok(blind.breakdown.paidMedia >= 18, `expected a big paid-media gap, got ${blind.breakdown.paidMedia}`);
    assert.ok(blind.pitch.includes('Meta awareness & lead-gen campaign'));
});

test('scoreLead can switch off areas the agency does not sell', () => {
    const place = { category: 'Restaurant', website: '', reviewCount: 0, reviewTexts: [], ownerResponseDates: [], address: '' };
    const result = scoreLead({ place, site: { kind: { type: 'none' }, reachable: false, socialLinks: {} }, channels: noChannels, services: { social: false, paidMedia: false } });

    assert.deepStrictEqual(result.areas.map((a) => a.key), ['web', 'conversion', 'automation', 'brand']);
    assert.strictEqual(result.breakdown.social, undefined);
});

test('auditWebsite only calls a site a store when it has a cart', async () => {
    const { auditWebsite } = require('../src/website');
    const snapshot = (html, text) => ({
        title: 'Acme', text, html, meta: { description: 'x'.repeat(60) }, links: [], iframes: [], cfEmails: [],
        hasViewport: true, overflowsMobile: false, forms: [],
    });
    const audit = (html, text) => {
        const fetcher = {
            fetchPage: async (_ctx, url) => ({ url, finalUrl: url, ok: true, status: 200, loadSeconds: 1, snapshot: snapshot(html, text) }),
        };
        return auditWebsite(fetcher, {}, 'https://acme.test/', { name: 'Acme', phone: '', address: '' }, 1000, 0);
    };

    // The plugin name alone (as seen inside unrelated widget bundles) is not a store.
    const notAStore = await audit('<html>wp-content/plugins/woocommerce/assets/x.js</html>', 'We fix boilers. Check out our reviews.');
    assert.strictEqual(notAStore.site.ecommerce, null);

    const store = await audit('<html>wp-content/plugins/woocommerce/assets/x.js</html>', 'Filters from 200 EGP. Add to cart');
    assert.strictEqual(store.site.ecommerce, 'WooCommerce');

    const unknownPlatform = await audit('<html>custom shop</html>', 'أضف إلى السلة');
    assert.strictEqual(unknownPlatform.site.ecommerce, 'custom store');
});

test('auditWebsite reports which marketing tags are present', async () => {
    const { auditWebsite } = require('../src/website');
    const html = '<html><script src="https://connect.facebook.net/en_US/fbevents.js"></script><script src="https://www.googletagmanager.com/gtag/js?id=G-ABC"></script></html>';
    const fetcher = {
        fetchPage: async (_ctx, url) => ({
            url, finalUrl: url, ok: true, status: 200, loadSeconds: 1,
            snapshot: { title: 'Acme', text: 'Acme heating', html, meta: {}, links: [], iframes: [], cfEmails: [], hasViewport: true, overflowsMobile: false, forms: [] },
        }),
    };

    const { site } = await auditWebsite(fetcher, {}, 'https://acme.test/', { name: 'Acme', phone: '', address: '' }, 1000, 0);

    assert.strictEqual(site.trackers.metaPixel, true);
    assert.strictEqual(site.trackers.analytics, true);
    assert.strictEqual(site.trackers.googleAds, false);
    assert.strictEqual(site.runsPaidMedia, true);
    assert.strictEqual(site.measures, true);
    assert.strictEqual(site.metaDescription, false);
});

test('JobReporter batches places and logs, and picks up cancellation', async () => {
  const calls = [];
  const api = {
    postPlaces: async (id, places) => calls.push(['places', places.length]),
    postLogs: async (id, lines) => {
      calls.push(['logs', lines.length]);
      return { cancelRequested: true };
    },
  };
  const reporter = new JobReporter(api, 7, { intervalMs: 60_000 });
  const quiet = console.log;
  console.log = () => {};
  reporter.log('hello');
  console.log = quiet;
  reporter.addPlace('q', { name: 'A' });
  await reporter.close();
  assert.deepStrictEqual(calls, [['places', 1], ['logs', 1]]);
  assert.strictEqual(reporter.cancelRequested, true);
});

test('JobReporter marks the job gone on 404/409', async () => {
  const api = { postPlaces: async () => {}, postLogs: async () => { throw new JobGoneError('gone'); } };
  const reporter = new JobReporter(api, 1, { intervalMs: 60_000 });
  await reporter.close();
  assert.strictEqual(reporter.gone, true);
});
