// All page behaviour lives in this compiled bundle: the Content-Security-Policy
// forbids inline scripts. Every feature is opt-in through data-* attributes.

const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

// Interface strings for the current language, put on <body> by the layout: the
// Content-Security-Policy forbids inline scripts, so they travel as a data attribute.
let strings = {};
try {
    strings = JSON.parse(document.body.dataset.i18n || '{}');
} catch {}

// Fills :placeholders and picks a plural form the way Laravel does, so the same
// "{1} one|[2,*] many" strings work here and on the server.
const t = (key, replace = {}, count = null) => {
    let line = strings[key] ?? '';
    if (count !== null) line = choose(line, count);
    return Object.entries(replace).reduce((text, [name, value]) => text.replaceAll(`:${name}`, value), line);
};

function choose(line, count) {
    const forms = line.split('|');
    for (const form of forms) {
        const explicit = form.match(/^\s*(?:\{(\d+)\}|\[(\d+),(\d+|\*)\])\s*/);
        if (!explicit) continue;
        const [, exact, from, to] = explicit;
        const matches = exact !== undefined
            ? count === Number(exact)
            : count >= Number(from) && (to === '*' || count <= Number(to));
        if (matches) return form.slice(explicit[0].length);
    }
    const plain = forms.filter((form) => !/^\s*(?:\{\d+\}|\[\d+,(?:\d+|\*)\])/.test(form));
    return (count === 1 ? plain[0] : plain[1]) ?? plain[0] ?? '';
}

// Forms that need a confirmation, e.g. <form data-confirm="Delete this run?">
document.addEventListener('submit', (event) => {
    const message = event.target.dataset?.confirm;
    if (message && !window.confirm(message)) event.preventDefault();
});

// Lead rows expand to their detail row.
document.addEventListener('click', (event) => {
    const row = event.target.closest('tr[data-toggle-detail]');
    if (!row || event.target.closest('a, button, form')) return;
    const detail = row.nextElementSibling;
    if (detail?.classList.contains('detail')) {
        detail.hidden = !detail.hidden;
        row.setAttribute('aria-expanded', String(!detail.hidden));
    }
});
document.addEventListener('keydown', (event) => {
    const row = event.target.closest?.('tr[data-toggle-detail]');
    if (row && (event.key === 'Enter' || event.key === ' ')) {
        event.preventDefault();
        row.click();
    }
});

// Copy buttons: <button data-copy="#element-id">
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');
    if (!button) return;
    const source = document.querySelector(button.dataset.copy);
    try {
        await navigator.clipboard.writeText((source?.value ?? source?.textContent ?? '').trim());
        const label = button.textContent;
        button.textContent = t('copied');
        setTimeout(() => (button.textContent = label), 1500);
    } catch {
        source?.select?.();
    }
});

// Countdown to a timestamp in ms: <span data-countdown="1789300000000">
function formatDuration(ms) {
    const s = Math.max(0, Math.ceil(ms / 1000));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const pad = (n) => String(n).padStart(2, '0');
    return h ? `${h}:${pad(m)}:${pad(s % 60)}` : `${pad(m)}:${pad(s % 60)}`;
}
const countdowns = $$('[data-countdown]');
if (countdowns.length) {
    const tick = () => countdowns.forEach((el) => (el.textContent = formatDuration(Number(el.dataset.countdown) - Date.now())));
    tick();
    setInterval(tick, 1000);
}

// New-search form: keep an unsent draft and show how many businesses it may open.
const searchForm = document.querySelector('[data-search-form]');
if (searchForm) {
    const queries = searchForm.querySelector('textarea[name=queries]');
    const limit = searchForm.querySelector('input[name=limit]');
    const estimate = searchForm.querySelector('[data-estimate]');
    const budget = Number(searchForm.dataset.runBudget || 0);
    const key = 'gscraper.queries';
    try {
        if (!queries.value) queries.value = localStorage.getItem(key) || '';
    } catch {}
    const update = () => {
        try {
            localStorage.setItem(key, queries.value);
        } catch {}
        const count = queries.value.split('\n').filter((q) => q.trim() && !q.trim().startsWith('#')).length;
        const max = count * (parseInt(limit.value, 10) || 0);
        if (!estimate) return;
        estimate.textContent = !max
            ? ''
            : t(budget && max > budget ? 'estimate_capped' : 'estimate', { searches: count, max, budget }, count);
    };
    queries.addEventListener('input', update);
    limit.addEventListener('input', update);
    searchForm.addEventListener('submit', () => {
        try {
            localStorage.removeItem(key);
        } catch {}
    });
    update();
}

// Live run progress: polls the progress endpoint while a worker is on the job.
const live = document.querySelector('[data-run-live]');
if (live) {
    const url = live.dataset.progressUrl;
    const log = live.querySelector('[data-log]');
    const set = (name, value) => $$(`[data-field="${name}"]`, live).forEach((el) => (el.textContent = value));
    let after = Number(live.dataset.after || 0);
    let failures = 0;

    const render = (data) => {
        const p = data.progress || {};
        const tiers = p.tiers || { A: 0, B: 0, C: 0, D: 0 };
        set('status', data.statusLabel);
        const badge = live.querySelector('[data-field="status"]');
        if (badge) badge.className = `badge badge-${data.status}`;
        set('phase', p.phase || t(data.status === 'queued' ? 'waiting_for_worker' : 'starting'));
        set('active', p.active ?? 0);
        set('excluded', p.excluded ?? 0);
        set('reachable', (tiers.A || 0) + (tiers.B || 0) + (tiers.C || 0));
        set('tiers', `A ${tiers.A || 0} · B ${tiers.B || 0} · C ${tiers.C || 0}`);
        set('unreachable', tiers.D || 0);
        set('hot', p.hot ?? 0);
        const bar = live.querySelector('[data-field="bar"]');
        if (bar) bar.style.width = `${Math.min(100, Math.max(2, Number(p.pct) || 2))}%`;
        live.querySelector('[data-worker-offline]')?.toggleAttribute('hidden', data.workerOnline || data.status === 'queued');
        live.querySelector('[data-cancel-requested]')?.toggleAttribute('hidden', !data.cancelRequested);
        live.querySelector('[data-blocked]')?.toggleAttribute('hidden', !p.blocked);
        live.querySelector('[data-limit-hit]')?.toggleAttribute('hidden', !p.limitHit);

        if (data.logs.length && log) {
            const nearBottom = log.scrollTop + log.clientHeight >= log.scrollHeight - 40;
            log.append(document.createTextNode(data.logs.map((l) => `[${l.time}] ${l.message}`).join('\n') + '\n'));
            if (nearBottom) log.scrollTop = log.scrollHeight;
            after = data.logs[data.logs.length - 1].id;
        }
    };

    const poll = async () => {
        try {
            const res = await fetch(`${url}?after=${after}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (res.status === 401 || res.status === 419) return window.location.reload();
            if (!res.ok) throw new Error(res.statusText);
            const data = await res.json();
            failures = 0;
            render(data);
            if (data.finished) return setTimeout(() => window.location.reload(), 800);
        } catch {
            failures++;
        }
        setTimeout(poll, Math.min(30000, 3000 * 2 ** failures));
    };
    if (log) log.scrollTop = log.scrollHeight;
    poll();
}
