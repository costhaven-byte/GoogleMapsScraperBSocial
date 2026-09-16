const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const randomBetween = ([min, max]) => Math.floor(min + Math.random() * (max - min));

const slugify = (s) =>
  s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60);

// Runs async fn over items with a fixed number of parallel workers.
async function mapPool(items, concurrency, fn) {
  const results = new Array(items.length);
  let next = 0;
  const worker = async () => {
    while (next < items.length) {
      const i = next++;
      results[i] = await fn(items[i], i);
    }
  };
  await Promise.all(Array.from({ length: Math.min(concurrency, items.length) }, worker));
  return results;
}

const log = (...args) => console.log(`[${new Date().toLocaleTimeString()}]`, ...args);

// Chromium's Cast/media-router discovery and WebRTC open local network sockets,
// which make Windows Firewall pop up "allow access?" prompts. None are needed here.
const BROWSER_ARGS = [
  '--disable-features=MediaRouter,DialMediaRouteProvider,CastMediaRouteProvider,GlobalMediaControlsCastStartStop',
  '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
  '--webrtc-ip-handling-policy=disable_non_proxied_udp',
  '--disable-background-networking',
  '--no-first-run',
];

module.exports = { sleep, randomBetween, slugify, mapPool, log, BROWSER_ARGS };
