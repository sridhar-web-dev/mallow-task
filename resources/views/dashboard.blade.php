<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Merchant Dashboard · {{ config('app.name') }}</title>
    <style>
        :root {
            --navy: #1e293b; --ink: #1f2937; --muted: #64748b; --line: #cbd5e1; --panel: #f1f5f9;
            --blue: #2563eb; --orange: #d97706; --green: #15803d; --red: #b91c1c; --red-bg: #fef2f2;
            --info: #0369a1; --info-bg: #f0f9ff; --radius: 6px;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: #fff; }
        header { background: var(--navy); color: #fff; padding: 14px 24px; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        header h1 { font-size: 19px; margin: 0; font-weight: 650; }
        header .meta { margin-left: auto; display: flex; gap: 12px; align-items: center; color: #cbd5e1; font-size: 13px; }
        button { font: inherit; cursor: pointer; border-radius: var(--radius); border: 1px solid var(--line); background: #fff; color: var(--ink); padding: 6px 12px; }
        button.primary { background: var(--blue); border-color: var(--blue); color: #fff; }
        header button { background: transparent; color: #e2e8f0; border-color: #475569; padding: 4px 10px; }
        main { max-width: 1100px; margin: 0 auto; padding: 24px; }
        .kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 28px; }
        .kpi { background: var(--panel); border: 1px solid var(--line); border-left: 6px solid; border-radius: var(--radius); padding: 16px 18px; }
        .kpi.blue { border-left-color: var(--blue); } .kpi.orange { border-left-color: var(--orange); } .kpi.green { border-left-color: var(--green); }
        .kpi .label { color: var(--muted); font-size: 13px; }
        .kpi .value { font-size: 20px; font-weight: 700; margin-top: 6px; font-variant-numeric: tabular-nums; }
        .kpi .sub { color: var(--muted); font-size: 12px; margin-top: 4px; }
        .meter { height: 6px; background: #e2e8f0; border-radius: 3px; margin-top: 10px; overflow: hidden; }
        .meter > span { display: block; height: 100%; background: var(--blue); }
        .meter.over > span { background: var(--orange); }
        .grid { display: grid; grid-template-columns: 1.65fr 1fr; gap: 24px; align-items: start; }
        h2 { font-size: 15px; margin: 0 0 10px; }
        table { width: 100%; border-collapse: separate; border-spacing: 0 4px; font-variant-numeric: tabular-nums; }
        th { text-align: left; background: #e2e8f0; padding: 8px 10px; font-size: 13px; }
        th:first-child, td:first-child { border-radius: var(--radius) 0 0 var(--radius); }
        th:last-child, td:last-child { border-radius: 0 var(--radius) var(--radius) 0; }
        td { padding: 8px 10px; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        td:first-child { border-left: 1px solid var(--line); } td:last-child { border-right: 1px solid var(--line); }
        td.num, th.num { text-align: right; }
        .ref { color: var(--muted); font-size: 12px; }
        .pct { display: inline-flex; align-items: center; gap: 8px; justify-content: flex-end; }
        .pct .bar { width: 60px; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; }
        .pct .bar span { display: block; height: 100%; background: var(--blue); }
        .pct.over .bar span { background: var(--orange); } .pct.over b { color: var(--orange); }
        .note { color: var(--muted); font-size: 12px; margin: 6px 2px 0; }
        .chart { border: 1px solid var(--line); border-radius: var(--radius); padding: 8px 8px 4px; margin-top: 28px; }
        .chart svg { width: 100%; height: 210px; display: block; }
        .box { border: 1.5px solid; border-radius: var(--radius); padding: 14px 16px; margin-bottom: 24px; }
        .box.churn { border-color: var(--red); background: var(--red-bg); color: #7f1d1d; min-height: 200px; }
        .box.churn h2 { color: var(--red); }
        .box.info { border-color: var(--info); background: var(--info-bg); color: #0c4a6e; }
        .box.info h2 { color: var(--info); }
        .box ul { margin: 0; padding-left: 18px; } .box li { margin: 6px 0; }
        .box dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 6px 12px; font-size: 13px; }
        .box dt { color: #075985; } .box dd { margin: 0; font-weight: 550; }
        .empty { color: var(--muted); font-style: italic; }
        #login { max-width: 440px; margin: 60px auto; border: 1px solid var(--line); border-radius: var(--radius); padding: 24px; background: var(--panel); }
        #login h2 { font-size: 18px; } #login p { color: var(--muted); margin-top: 0; }
        #login input { width: 100%; padding: 9px 10px; border: 1px solid var(--line); border-radius: var(--radius); font: 13px ui-monospace, monospace; margin: 8px 0 12px; }
        #login .row { display: flex; gap: 8px; flex-wrap: wrap; }
        .loading { text-align: center; color: var(--muted); margin: 80px 0; }
        .error { color: var(--red); font-size: 13px; min-height: 18px; margin-top: 10px; }
        [hidden] { display: none !important; }
        @media (max-width: 860px) { .kpis, .grid { grid-template-columns: 1fr; } main { padding: 16px; } header { padding: 12px 16px; } }
    </style>
</head>
<body>
<header>
    <h1>Merchant Dashboard <span id="merchant-name"></span></h1>
    <div class="meta" id="session" hidden>
        <span id="as-of"></span>
        <button type="button" id="refresh">Refresh</button>
        <button type="button" id="sign-out">Switch key</button>
    </div>
</header>

<p id="loading" class="loading" role="status" hidden>Loading dashboard…</p>

<section id="login" hidden>
    <h2>Connect with a merchant API key</h2>
    <p>The key stays in this browser tab (sessionStorage) and is sent only as a Bearer token to this app's API.</p>
    <form id="login-form">
        <label for="api-key">API key</label>
        <input id="api-key" name="api_key" type="password" autocomplete="off" placeholder="mk_…" required>
        <div class="row">
            <button type="submit" class="primary">Open dashboard</button>
            @if ($demoKey)
                <button type="button" id="use-demo" data-key="{{ $demoKey }}">Use demo key (Acme)</button>
            @endif
        </div>
        <div class="error" id="login-error" role="alert"></div>
    </form>
</section>

<main id="dashboard" hidden>
    <section class="kpis">
        <div class="kpi blue">
            <div class="label">Current Cycle Usage</div>
            <div class="value" id="kpi-usage">–</div>
            <div class="meter" id="kpi-usage-meter"><span></span></div>
            <div class="sub" id="kpi-usage-sub"></div>
        </div>
        <div class="kpi orange">
            <div class="label">Projected Overage Revenue</div>
            <div class="value" id="kpi-overage">–</div>
            <div class="sub" id="kpi-overage-sub"></div>
        </div>
        <div class="kpi green">
            <div class="label">Most Subscribed Plan</div>
            <div class="value" id="kpi-plan">–</div>
            <div class="sub" id="kpi-plan-sub"></div>
        </div>
    </section>

    <div class="grid">
        <div>
            <h2>Top 5 Customers by Usage (this month)</h2>
            <table>
                <thead><tr><th>Customer</th><th>Plan</th><th class="num">Usage</th><th class="num">% of Allowance</th></tr></thead>
                <tbody id="top-customers"></tbody>
            </table>
            <p class="note" id="top-note"></p>

            <div class="chart">
                <h2 style="margin:4px 4px 0">Daily Usage Trend (last 30 days)</h2>
                <svg id="trend" role="img" aria-label="Daily usage over the last 30 days"></svg>
            </div>
        </div>

        <aside>
            <div class="box churn">
                <h2>⚠ Churn Risk (usage ↓ &gt;<span id="churn-threshold">50</span>% MoM)</h2>
                <ul id="churn"></ul>
                <p class="note" id="churn-note" style="color:#991b1b"></p>
            </div>

            <div class="box info">
                <h2>System status (informational)</h2>
                <dl>
                    <dt>Plan pricing cache</dt><dd>{{ ucfirst($system['cache_store']) }}, TTL {{ intdiv($system['plan_catalog_ttl'], 60) }}m, cleared on plan change</dd>
                    <dt>Dashboard cache</dt><dd>TTL {{ intdiv($system['dashboard_ttl'], 60) }}m, versioned per merchant</dd>
                    <dt>Aggregation job</dt><dd>Hourly, {{ $system['queue'] }} queue, chunked ({{ number_format($system['aggregation_chunk']) }} customers/batch)</dd>
                    <dt>Usage rolled up at</dt><dd id="aggregated-at">–</dd>
                    <dt>Late-arrival window</dt><dd>{{ $system['late_days'] }} days, invoices wait for it</dd>
                    <dt>Usage endpoint</dt><dd>Rate-limited: {{ number_format($system['rate_merchant']) }}/min per merchant, {{ number_format($system['rate_customer']) }}/min per customer</dd>
                </dl>
            </div>
        </aside>
    </div>
</main>

<script>
(() => {
    const STORAGE_KEY = 'usage-billing.api-key';
    const $ = (id) => document.getElementById(id);
    let currency = 'INR';

    const storage = {
        get() { try { return sessionStorage.getItem(STORAGE_KEY); } catch { return null; } },
        set(v) { try { sessionStorage.setItem(STORAGE_KEY, v); } catch {} },
        clear() { try { sessionStorage.removeItem(STORAGE_KEY); } catch {} },
    };

    const units = (n) => new Intl.NumberFormat('en-IN').format(n);
    const money = (minor) => new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 0 }).format(minor / 100);

    // Builds DOM nodes with textContent only. Customer names are merchant-supplied data and are never parsed as HTML.
    function el(tag, attrs = {}, ...children) {
        const node = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) k === 'class' ? (node.className = v) : node.setAttribute(k, v);
        for (const child of children) node.append(child instanceof Node ? child : document.createTextNode(String(child)));
        return node;
    }

    async function api(path, key) {
        const res = await fetch('/api' + path, { headers: { Accept: 'application/json', Authorization: 'Bearer ' + key } });
        if (!res.ok) {
            const message = res.status === 401 ? 'That API key was not recognised.'
                : res.status === 429 ? 'Rate limited. Try again in a minute.'
                : `Request failed (HTTP ${res.status}).`;
            throw Object.assign(new Error(message), { status: res.status });
        }
        return (await res.json()).data;
    }

    function showLogin(error = '') {
        $('dashboard').hidden = true;
        $('session').hidden = true;
        $('login').hidden = false;
        $('merchant-name').textContent = '';
        $('login-error').textContent = error;
        $('api-key').focus();
    }

    async function load(key) {
        $('refresh').disabled = true;
        // First load shows a placeholder. A refresh keeps the current numbers on screen until the new ones arrive.
        if ($('dashboard').hidden) {
            $('login').hidden = true;
            $('loading').hidden = false;
        }
        try {
            const merchant = await api('/merchant', key);
            const data = await api(`/merchants/${merchant.id}/dashboard`, key);
            currency = data.currency;
            render(merchant, data);
            $('login').hidden = true;
            $('dashboard').hidden = false;
            $('session').hidden = false;
        } catch (e) {
            if (e.status === 401) storage.clear();
            showLogin(e.message);
        } finally {
            $('loading').hidden = true;
            $('refresh').disabled = false;
        }
    }

    function render(merchant, d) {
        $('merchant-name').textContent = ' — ' + merchant.name;
        $('as-of').textContent = 'As of ' + d.as_of + ' (UTC)';

        const s = d.summary;
        const pct = s.cycle_included_units ? (100 * s.cycle_used_units / s.cycle_included_units) : 0;
        $('kpi-usage').textContent = `${units(s.cycle_used_units)} / ${units(s.cycle_included_units)} units`;
        $('kpi-usage-meter').classList.toggle('over', pct > 100);
        $('kpi-usage-meter').firstElementChild.style.width = Math.min(100, pct) + '%';
        $('kpi-usage-sub').textContent = `${pct.toFixed(1)}% of included allowance across ${s.active_subscriptions} active subscriptions`;

        const o = d.projected_overage;
        $('kpi-overage').textContent = money(o.projected_minor);
        $('kpi-overage-sub').textContent = `${money(o.actual_to_date_minor)} already incurred · ${o.subscriptions_projected_over_allowance} subscriptions projected over`;

        const plan = s.most_subscribed_plan;
        $('kpi-plan').textContent = plan ? `${plan.name} — ${plan.billing_cycle}` : 'No active plans';
        $('kpi-plan-sub').textContent = plan ? `${plan.subscriptions} of ${s.active_subscriptions} active subscriptions` : '';

        const rows = d.top_customers.customers.map((c) => {
            const over = c.percent_of_allowance !== null && c.percent_of_allowance > 100;
            const pctCell = c.percent_of_allowance === null ? el('span', { class: 'ref' }, 'n/a')
                : el('span', { class: 'pct' + (over ? ' over' : '') },
                    el('span', { class: 'bar' }, Object.assign(el('span'), { style: `width:${Math.min(100, c.percent_of_allowance)}%` })),
                    el('b', {}, c.percent_of_allowance + '%'));
            return el('tr', {},
                el('td', {}, c.name, el('br'), el('span', { class: 'ref' }, c.external_ref)),
                el('td', {}, c.plan ?? '–'),
                el('td', { class: 'num' }, units(c.units)),
                el('td', { class: 'num' }, pctCell));
        });
        $('top-customers').replaceChildren(...(rows.length ? rows : [el('tr', {}, el('td', { colspan: 4, class: 'empty' }, 'No usage recorded this month yet.'))]));
        $('top-note').textContent = `${d.top_customers.period.start} to ${d.top_customers.period.end}, sorted by usage. % is against each customer's prorated allowance for the current cycle.`;

        const churn = d.churn_risk;
        $('churn-threshold').textContent = churn.threshold_percent;
        $('churn').replaceChildren(...(churn.customers.length
            ? churn.customers.map((c) => el('li', {}, el('b', {}, c.name), ` — ${c.drop_percent}% drop (${units(c.previous_units)} → ${units(c.current_units)})`))
            : [el('li', { class: 'empty' }, 'No customers at risk.')]));
        $('churn-note').textContent = `Comparing ${churn.current_period.start}–${churn.current_period.end.slice(8)} with ${churn.previous_period.start}–${churn.previous_period.end.slice(8)}.`;

        $('aggregated-at').textContent = s.usage_aggregated_at ? s.usage_aggregated_at + ' UTC' : 'no rollup in the last 7 days';

        drawTrend(d.daily_trend);
    }

    function drawTrend(points) {
        const svg = $('trend');
        const NS = 'http://www.w3.org/2000/svg';
        const W = 640, H = 210, L = 48, R = 10, T = 14, B = 26;
        svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
        svg.replaceChildren();
        const node = (tag, attrs, text) => {
            const n = document.createElementNS(NS, tag);
            for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, v);
            if (text !== undefined) n.textContent = text;
            svg.append(n);
            return n;
        };

        const max = Math.max(1, ...points.map((p) => p.units));
        const step = Math.pow(10, Math.floor(Math.log10(max)));
        const top = Math.ceil(max / step) * step;
        const x = (i) => L + (i * (W - L - R)) / Math.max(1, points.length - 1);
        const y = (v) => T + (H - T - B) * (1 - v / top);

        for (let g = 0; g <= 4; g++) {
            const v = (top * g) / 4;
            node('line', { x1: L, x2: W - R, y1: y(v), y2: y(v), stroke: '#e2e8f0' });
            node('text', { x: L - 6, y: y(v) + 4, 'text-anchor': 'end', 'font-size': 10, fill: '#64748b' }, new Intl.NumberFormat('en-IN', { notation: 'compact' }).format(v));
        }
        // Label every 7th day counting back from today, so today is always labelled and labels never collide.
        const last = points.length - 1;
        points.forEach((p, i) => {
            if ((last - i) % 7 === 0) {
                node('text', { x: x(i), y: H - 8, 'text-anchor': i === last ? 'end' : 'middle', 'font-size': 10, fill: '#64748b' }, p.date.slice(5));
            }
        });

        const line = points.map((p, i) => `${x(i)},${y(p.units)}`).join(' ');
        node('polygon', { points: `${x(0)},${y(0)} ${line} ${x(points.length - 1)},${y(0)}`, fill: 'rgba(37,99,235,0.08)' });
        node('polyline', { points: line, fill: 'none', stroke: '#2563eb', 'stroke-width': 2, 'stroke-linejoin': 'round' });
        points.forEach((p, i) => {
            const dot = node('circle', { cx: x(i), cy: y(p.units), r: 3, fill: '#2563eb' });
            const title = document.createElementNS(NS, 'title');
            title.textContent = `${p.date}: ${units(p.units)} units`;
            dot.append(title);
        });
    }

    $('login-form').addEventListener('submit', (e) => {
        e.preventDefault();
        const key = $('api-key').value.trim();
        if (!key) return;
        storage.set(key);
        $('api-key').value = '';
        load(key);
    });
    $('use-demo')?.addEventListener('click', (e) => { $('api-key').value = e.currentTarget.dataset.key; $('login-form').requestSubmit(); });
    $('refresh').addEventListener('click', () => { const key = storage.get(); key ? load(key) : showLogin(); });
    $('sign-out').addEventListener('click', () => { storage.clear(); showLogin(); });

    const saved = storage.get();
    saved ? load(saved) : showLogin();
})();
</script>
</body>
</html>
