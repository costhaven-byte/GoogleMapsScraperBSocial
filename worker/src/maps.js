// Google Maps scraping: search results, place details, newest reviews.
const { sleep } = require('./util');
const { relativeAgeMonths } = require('./activity');

const withHl = (url) => (url.includes('hl=') ? url : `${url}${url.includes('?') ? '&' : '?'}hl=en`);

const placeKey = (url) => {
  const m = url.match(/!1s(0x[0-9a-f]+:0x[0-9a-f]+)/i) || url.match(/\/place\/([^/]+)/);
  return m ? m[1] : url;
};

class GoogleBlockedError extends Error {}

// Google's rate-limit responses: the /sorry/ captcha page or an "unusual traffic" notice.
async function assertNotBlocked(page) {
  const url = page.url();
  const blocked =
    /google\.[a-z.]+\/sorry\//.test(url) ||
    (await page.getByText(/unusual traffic from your computer network|detected unusual traffic|not a robot/i).count()) > 0;
  if (blocked) throw new GoogleBlockedError('Google is showing a captcha / unusual-traffic page');
}

// EU visitors get a consent wall first; always take the privacy-preserving option.
async function handleConsent(page) {
  await assertNotBlocked(page);
  if (!/consent\.google/.test(page.url())) return;
  const reject = page.getByRole('button', { name: /reject all/i }).first();
  if (await reject.count()) {
    await reject.click();
    await page.waitForURL(/google\.[a-z.]+\/maps/, { timeout: 20000 }).catch(() => {});
  }
}

async function searchPlaces(page, query, limit) {
  await page.goto(withHl(`https://www.google.com/maps/search/${encodeURIComponent(query)}`), {
    waitUntil: 'domcontentloaded',
  });
  await handleConsent(page);
  await page.waitForSelector('div[role="feed"], h1.DUwDvf', { timeout: 25000 }).catch(() => {});
  await sleep(1500);

  // A very specific query jumps straight to a single place page.
  if (page.url().includes('/maps/place/') && !(await page.locator('div[role="feed"]').count())) {
    return [page.url()];
  }

  const feed = page.locator('div[role="feed"]').first();
  if (!(await feed.count())) return [];

  const linkSel = 'div[role="feed"] a[href*="/maps/place/"]';
  let lastCount = 0;
  let stagnant = 0;
  while (true) {
    const count = await page.locator(linkSel).count();
    if (count >= limit) break;
    if (await page.getByText(/reached the end of the list/i).count()) break;
    stagnant = count === lastCount ? stagnant + 1 : 0;
    if (stagnant >= 6) break;
    lastCount = count;
    await feed.evaluate((el) => el.scrollBy(0, el.scrollHeight));
    await sleep(1300);
  }

  const hrefs = await page.$$eval(linkSel, (as) => as.map((a) => a.href));
  const seen = new Set();
  return hrefs.filter((h) => !seen.has(placeKey(h)) && seen.add(placeKey(h))).slice(0, limit);
}

function extractDetails() {
  const main = document.querySelector('div[role="main"][aria-label]') || document.body;
  const q = (sel) => main.querySelector(sel);
  const aria = (sel) => q(sel)?.getAttribute('aria-label') || '';
  const afterColon = (s) => s.replace(/^[^:]+:\s*/, '').trim();

  const name = (q('h1')?.innerText || main.getAttribute('aria-label') || '').trim();
  const category = (q('button[jsaction*="category"]')?.innerText || '').trim();
  const address = afterColon(aria('button[data-item-id="address"]'));
  const phoneBtn = q('button[data-item-id^="phone:tel:"]');
  const phone = phoneBtn ? phoneBtn.getAttribute('data-item-id').replace('phone:tel:', '') : '';

  let website = q('a[data-item-id="authority"]')?.href || '';
  const redirect = website.match(/[?&]q=([^&]+)/);
  if (redirect && website.includes('google.')) website = decodeURIComponent(redirect[1]);

  const leaves = [...main.querySelectorAll('span, div')].filter((el) => el.children.length === 0);
  const statusEl = leaves.find((el) => /^(permanently|temporarily) closed$/i.test(el.textContent.trim()));

  let rating = null;
  const ratingEl = q('div.F7nice span[aria-hidden="true"]');
  if (ratingEl) rating = parseFloat(ratingEl.textContent.replace(',', '.')) || null;
  if (rating === null) {
    const stars = [...main.querySelectorAll('[role="img"][aria-label*="star" i]')][0];
    const m = stars?.getAttribute('aria-label').match(/([\d.,]+)\s*star/i);
    if (m) rating = parseFloat(m[1].replace(',', '.'));
  }

  let reviewCount = 0;
  const countEl = [...main.querySelectorAll('[aria-label]')].find((el) =>
    /^[\d,.\s]+reviews?$/i.test(el.getAttribute('aria-label').trim())
  );
  if (countEl) reviewCount = parseInt(countEl.getAttribute('aria-label').replace(/\D/g, ''), 10) || 0;
  else {
    const m = (q('div.F7nice')?.textContent || '').match(/\(([\d,.]+)\)/);
    if (m) reviewCount = parseInt(m[1].replace(/\D/g, ''), 10) || 0;
  }

  // Social profile and mailto links shown on the listing (Google wraps external links in /url?q=).
  const listingLinks = [...main.querySelectorAll('a[href]')].map((a) => {
    const wrapped = a.href.match(/^https?:\/\/(?:www\.)?google\.[a-z.]+\/url\?(?:.*&)?q=([^&]+)/i);
    return wrapped ? decodeURIComponent(wrapped[1]) : a.href;
  });
  const socialProfiles = [...new Set(listingLinks.filter((h) => /^https?:\/\/([a-z0-9-]+\.)*(facebook|fb|instagram|tiktok|linkedin|youtube|twitter|x)\.com\//i.test(h)))];
  const listingEmails = [...new Set(listingLinks.filter((h) => /^mailto:/i.test(h)).map((h) => h.slice(7).split('?')[0]))];

  const hoursEl = main.querySelector('[aria-label*="hours" i][aria-label*="day" i]');
  const bookingLink = [...main.querySelectorAll('a[href]')].find((a) =>
    /book|reserve|reservation|appointment/i.test(`${a.getAttribute('aria-label') || ''} ${a.innerText}`)
  );

  return {
    name,
    category,
    address,
    phone,
    website,
    rating,
    reviewCount,
    businessStatus: statusEl ? statusEl.textContent.trim() : 'Operational',
    hours: hoursEl ? hoursEl.getAttribute('aria-label') : '',
    unclaimed: !!q('a[data-item-id="merchant"]'),
    socialProfiles,
    listingEmails,
    mapsBookingLink: bookingLink ? bookingLink.href : '',
    hasReviewsTab: [...document.querySelectorAll('button[role="tab"]')].some((b) => /review/i.test(b.textContent)),
  };
}

async function readNewestReviews(page, n) {
  const tab = page.locator('button[role="tab"]').filter({ hasText: /review/i }).first();
  if (!(await tab.count())) return { reviews: [], sortedByNewest: false };
  await tab.click();
  await page.waitForSelector('div[data-review-id]', { timeout: 10000 }).catch(() => {});
  await sleep(1200);

  // Signed-out sessions get a "Sign-in to get the best of Google Maps" wall instead
  // of the sort menu. Then we fall back to a larger "most relevant" sample: any
  // recent review in it still proves activity, it just may miss some.
  let sortedByNewest = false;
  const sortBtn = page.locator('button[aria-label*="Sort" i], button[data-value="Sort"]').first();
  if (await sortBtn.count()) {
    await sortBtn.click().catch(() => {});
    const newest = page
      .getByRole('menuitemradio', { name: /newest/i })
      .or(page.getByRole('menuitem', { name: /newest/i }))
      .first();
    await newest.waitFor({ timeout: 4000 }).catch(() => {});
    if (await newest.count()) {
      await newest.click();
      sortedByNewest = true;
      await sleep(2000);
    }
  }
  await dismissSignInWall(page);

  // Signed-out "limited view" hard-caps the list (currently 5), so stop once it stops growing.
  const target = sortedByNewest ? n : Math.max(n, 30);
  let lastCount = -1;
  let stagnant = 0;
  for (let i = 0; i < Math.ceil(target / 8) + 2; i++) {
    const count = await page.evaluate(
      () => [...document.querySelectorAll('div[data-review-id]')].filter((el) => !el.parentElement.closest('[data-review-id]')).length
    );
    if (count >= target) break;
    stagnant = count === lastCount ? stagnant + 1 : 0;
    if (stagnant >= 2) break;
    lastCount = count;
    await page.evaluate(() => {
      let el = document.querySelector('div[data-review-id]');
      while (el && !(el.scrollHeight > el.clientHeight + 50 && /auto|scroll/.test(getComputedStyle(el).overflowY))) {
        el = el.parentElement;
      }
      if (el) el.scrollBy(0, el.scrollHeight);
    });
    await sleep(1200);
  }

  const reviews = await page.evaluate((limit) => {
    const tops = [...document.querySelectorAll('div[data-review-id]')].filter(
      (el) => !el.parentElement.closest('[data-review-id]')
    );
    const isAgo = (t) => /\bago\b/i.test(t) && t.length < 60;
    return tops.slice(0, limit).map((el) => {
      el.querySelector('button[aria-label="See more"]')?.click();
      const spans = [...el.querySelectorAll('span')].map((s) => s.textContent.trim());
      const date = el.querySelector('.rsqaWe')?.textContent.trim() || spans.find(isAgo) || '';
      const text = el.querySelector('.wiI7pd')?.textContent.trim() || '';
      let ownerDate = el.querySelector('.DZSIDd')?.textContent.trim() || '';
      if (!ownerDate) {
        const label = [...el.querySelectorAll('span, div')].find(
          (x) => x.children.length === 0 && /response from the owner/i.test(x.textContent)
        );
        const box = label?.parentElement;
        if (box) ownerDate = [...box.querySelectorAll('span')].map((s) => s.textContent.trim()).find(isAgo) || '';
      }
      return { date, text, ownerDate };
    });
  }, target);

  return { reviews, sortedByNewest };
}

async function dismissSignInWall(page) {
  const dismiss = page.getByRole('button', { name: /^(dismiss|not now|no thanks)$/i }).first();
  if (await dismiss.count()) {
    await dismiss.click().catch(() => {});
    await sleep(600);
  } else {
    await page.keyboard.press('Escape').catch(() => {});
  }
}

const byAge = (a, b) => (relativeAgeMonths(a) ?? 999) - (relativeAgeMonths(b) ?? 999);

async function scrapePlace(page, url, reviewsToRead) {
  await page.goto(withHl(url), { waitUntil: 'domcontentloaded' });
  await handleConsent(page);
  await page.waitForSelector('h1', { timeout: 25000 });
  await sleep(1800);

  const details = await page.evaluate(extractDetails);
  const { reviews, sortedByNewest } = details.hasReviewsTab
    ? await readNewestReviews(page, reviewsToRead)
    : { reviews: [], sortedByNewest: false };

  return {
    ...details,
    mapsUrl: url.split('?')[0],
    placeKey: placeKey(url),
    reviewDates: reviews.map((r) => r.date).filter(Boolean).sort(byAge),
    ownerResponseDates: reviews.map((r) => r.ownerDate).filter(Boolean).sort(byAge),
    reviewTexts: reviews.map((r) => r.text).filter(Boolean),
    reviewsSortedByNewest: sortedByNewest,
    reviewsHidden: (details.reviewCount > 0 || !!details.rating) && reviews.length === 0,
  };
}

module.exports = { searchPlaces, scrapePlace, placeKey, GoogleBlockedError };
