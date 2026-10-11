// Headless Chromium proof of slice 7 (periods.md "Proof": 375 × 740 and 1280 × 800): the period list as cards on a phone with 44 px controls and the close through
// its confirmation, the usage table pinning ingredient · actual · theoretical · unexplained at 375 px and showing every column at 1280, the quick picks and the
// ingredient's week by link and back, the period's tabs, the COGS chart scaling to the width, the reopen through its confirmation, the forms with JavaScript
// off, scrollWidth ≤ the viewport on every screen, no console errors.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const P = process.env.P_APP || '9333';
const BASE = 'http://127.0.0.1:' + P;
const SHOTS = process.env.SHOTS || '/tmp/ri-shots/slice7';
fs.mkdirSync(SHOTS, { recursive: true });
const KEY = process.env.ACTION_TOKEN_KEY;
const fixture = JSON.parse(fs.readFileSync(new URL('../../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member, scope = null) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.restaurant_inventory.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member, ...(scope ? { scope } : {}) };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const sql = (text) => execFileSync('sudo', ['-n', '-u', 'postgres', 'psql', '-d', process.env.DB_NAME, '-Atc', text], { encoding: 'utf8' }).trim();
let failed = 0, passed = 0;
const ok = (c, l) => { if (c) passed++; else failed++; console.log((c ? '  ok   ' : '  FAIL ') + l); };
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ' + s + ' select')]
  .filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.height < 43.5; }).map((a) => (a.id || a.name || a.tagName) + ':' + Math.round(a.getBoundingClientRect().height)), scope);

const id = (n) => sql(`SELECT id FROM periods WHERE scope_id = 101 AND no = ${n} ORDER BY calendar_id LIMIT 1`);
const egg = sql("SELECT id FROM ingredients WHERE code = 'L-EGG'");
const w4 = sql(`SELECT starts_on || '|' || ends_on FROM periods WHERE id = ${id(4)}`).split('|');
const toClose = sql("SELECT id FROM periods WHERE scope_id = 101 AND status = 'open' AND ends_on < current_date ORDER BY starts_on LIMIT 1");
const usageUrl = `/usage/?from=${w4[0]}&to=${w4[1]}`;
ok(/^\d+$/.test(id(4)) && /^\d+$/.test(toClose) && /^\d+$/.test(egg), 'the PHP proofs left the world: weeks closed in order, one past its end still open, eggs');

const browser = await chromium.launch();
async function session(signOn, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/404 \(Not Found\)|422 \(|Failed to load resource: net::/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(signOn, { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}

// ---- 375 × 740: Marco on a phone ----
{
  const { ctx, page, errors } = await session(mint(27, 101), { width: 375, height: 740 });
  console.log('375 x 740 — Marco: the periods, the close, the usage table');
  await page.goto(BASE + '/periods/', { waitUntil: 'networkidle' });
  ok(await page.locator('#period-cards').isVisible() && !(await page.locator('#periods-table').isVisible()), 'the period list stacks each period as a card (the table is for the desk)');
  ok((await page.locator('#period-cards .period-card').count()) >= 6 && (await page.locator('#period-card-' + toClose + '.closes-next').count()) === 1, 'a card per period, the one to close marked');
  let x = await overflow(page);
  ok(x.sw <= x.iw, 'the list fits at 375 (' + x.sw + '/' + x.iw + ')');
  const small = await smallControls(page, '#period-close-card');
  ok(small.length === 0, 'the close form\'s select and button are at least 44 px tall' + (small.length ? ': ' + small.join(',') : ''));
  await page.screenshot({ path: SHOTS + '/periods-375.png' });
  page.once('dialog', (d) => d.accept());
  await page.click('#period-close-btn');
  await page.waitForURL(new RegExp('/periods/' + toClose + '(\\?.*)?$'), { timeout: 15000 });
  await page.waitForLoadState('networkidle');
  ok((await page.locator('#period-status').innerText()).trim() === 'closed' && (await page.locator('#notice-banner').innerText()).includes('frozen'), 'Close, through its confirmation, lands on the period: closed, with the notice');
  ok(sql(`SELECT status FROM periods WHERE id = ${toClose}`) === 'closed', '…and the database agrees');
  await page.screenshot({ path: SHOTS + '/period-view-375.png' });

  await page.goto(BASE + usageUrl, { waitUntil: 'networkidle' });
  x = await overflow(page);
  ok(x.sw <= x.iw, 'the usage report fits at 375 — the table scrolls inside its own box (' + x.sw + '/' + x.iw + ')');
  const pos = await page.evaluate(() => ['u-ing', 'u-actual', 'u-theo', 'u-unexp'].map((c) => { const r = document.querySelector('#usage-table thead th.' + c).getBoundingClientRect(); return [Math.round(r.left), Math.round(r.width)]; }));
  ok(pos.every((p, i) => i === 0 || p[0] >= pos[i - 1][0] + pos[i - 1][1] - 2) && pos[3][0] + pos[3][1] < 345, 'ingredient · actual · theoretical · unexplained are the first four columns, side by side at the left (' + pos.map((p) => p[0]).join(', ') + ')');
  const scrollable = await page.evaluate(() => { const b = document.querySelector('#usage-card .table-responsive'); return b.scrollWidth > b.clientWidth + 100; });
  ok(scrollable, 'the other columns are reachable by scrolling right');
  await page.evaluate(() => { document.querySelector('#usage-card .table-responsive').scrollLeft = 500; });
  const pinned = await page.evaluate(() => ['u-ing', 'u-actual', 'u-theo', 'u-unexp'].map((c) => Math.round(document.querySelector('#usage-table thead th.' + c).getBoundingClientRect().left)));
  ok(pinned.every((l, i) => Math.abs(l - pos[i][0]) <= 2), 'scrolled right, the four key columns stay where they were (pinned: ' + pinned.join(', ') + ')');
  await page.screenshot({ path: SHOTS + '/usage-375.png' });
  await page.goto(BASE + '/usage/' + egg + '?from=' + w4[0] + '&to=' + w4[1], { waitUntil: 'networkidle' });
  x = await overflow(page);
  ok(x.sw <= x.iw && (await page.locator('#usage-ingredient-buckets .card').count()) === 14, 'the ingredient\'s week fits at 375, fourteen bucket cards (' + x.sw + '/' + x.iw + ')');
  await page.screenshot({ path: SHOTS + '/usage-ingredient-375.png' });
  await page.goto(BASE + '/usage/cogs', { waitUntil: 'networkidle' });
  x = await overflow(page);
  const chart = await page.locator('#cogs-chart-svg').boundingBox();
  ok(x.sw <= x.iw && chart !== null && chart.width <= 375 && chart.width > 200, 'COGS fits at 375 and the chart scales to the width (' + (chart ? Math.round(chart.width) : '?') + ' px)');
  await page.screenshot({ path: SHOTS + '/cogs-375.png' });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors[0] : ''));
  await ctx.close();
}

// ---- 1280 × 800: the owner at a desk ----
{
  const { ctx, page, errors } = await session(mint(1, 101), { width: 1280, height: 800 });
  console.log('1280 x 800 — the owner: every column, the picks, the ingredient and back, the tabs, the reopen');
  await page.goto(BASE + '/periods/', { waitUntil: 'networkidle' });
  ok(await page.locator('#periods-table').isVisible() && !(await page.locator('#period-cards').isVisible()) && (await page.locator('#periods-table tbody tr').count()) >= 6, 'the period list is a table at the desk');
  await page.screenshot({ path: SHOTS + '/periods-1280.png' });
  await page.goto(BASE + usageUrl, { waitUntil: 'networkidle' });
  const ths = await page.evaluate(() => [...document.querySelectorAll('#usage-table thead th')].map((t) => ({ t: t.textContent.trim(), shown: getComputedStyle(t).display !== 'none' })));
  ok(ths.length === 25 && ths.every((t) => t.shown), 'every column is there at 1280: ingredient, category, the fourteen buckets and the nine value columns (' + ths.length + ')');
  const eggRow = await page.locator('#usage-row-' + egg).innerText();
  ok(eggRow.includes('SMOKE Eggs') && eggRow.includes('-12.30'), 'the eggs\' row carries the unexplained value (−$12.30)');
  ok((await page.locator('#usage-cat-dairy').count()) === 1 && (await page.locator('#usage-totals').count()) === 1 && (await page.locator('#usage-legend').isVisible()), 'the subtotals, the totals and the legend');
  await page.screenshot({ path: SHOTS + '/usage-1280.png' });
  await page.click('#usage-pick-last');
  await page.waitForURL(/pick=last/, { timeout: 10000 });
  await page.waitForLoadState('networkidle');
  ok((await page.inputValue('#usage-form-field-from')).length === 10 && page.url().includes('pick=last'), 'a quick pick (Last period) loads that window into the form through HTMX');
  await page.goto(BASE + usageUrl, { waitUntil: 'networkidle' });
  await page.click('#usage-row-' + egg + ' a.fw-semibold');
  await page.waitForURL(new RegExp('/usage/' + egg + '\\?'), { timeout: 10000 });
  await page.waitForLoadState('networkidle');
  ok(await page.locator('#usage-ingredient-buckets').isVisible() && (await page.locator('#usage-ingredient-back').innerText()).includes('Back to Actual vs theoretical'), 'the ingredient\'s name opens its week, with "Back to Actual vs theoretical"');
  await page.screenshot({ path: SHOTS + '/usage-ingredient-1280.png' });
  await page.click('#usage-ingredient-back');
  await page.waitForURL(/\/usage\/\?/, { timeout: 10000 });
  await page.waitForLoadState('networkidle');
  ok(await page.locator('#usage-table').isVisible(), '…and the way back is the report');

  await page.goto(BASE + '/periods/' + id(4), { waitUntil: 'networkidle' });
  ok(await page.locator('#period-figure-cogs').isVisible() && await page.locator('#period-usage').isVisible(), 'the period view: the figure cards and the usage by category');
  await page.click('#period-tab-counts');
  await page.waitForSelector('#period-counts', { timeout: 10000 });
  ok(await page.locator('#period-counts-table').isVisible(), 'the Counts tab lists the closing count');
  await page.click('#period-tab-late');
  await page.waitForSelector('#period-late-rows', { timeout: 10000 });
  await page.click('#period-tab-trail');
  await page.waitForSelector('#period-trail', { timeout: 10000 });
  ok(await page.locator('#period-trail').isVisible(), 'the Late rows and Trail tabs open in place');
  await page.screenshot({ path: SHOTS + '/period-view-1280.png' });

  await page.goto(BASE + '/usage/cogs', { waitUntil: 'networkidle' });
  const rects = await page.locator('#cogs-chart-svg rect').count();
  ok(rects === 2 * (await page.locator('#cogs-table tbody tr[id^=cogs-row-]').count()) && rects >= 12, 'the COGS chart has a pair of bars per period (' + rects + ' bars)');
  await page.screenshot({ path: SHOTS + '/cogs-1280.png' });

  await page.goto(BASE + '/periods/', { waitUntil: 'networkidle' });
  const last = sql("SELECT id FROM periods WHERE scope_id = 101 AND status = 'closed' ORDER BY starts_on DESC LIMIT 1");
  await page.fill('#period-reopen-form-field-reason', 'a late invoice from the browser');
  page.once('dialog', (d) => d.accept());
  await page.click('#period-reopen-btn');
  await page.waitForURL(new RegExp('/periods/' + last + '(\\?.*)?$'), { timeout: 15000 });
  await page.waitForLoadState('networkidle');
  ok((await page.locator('#period-status').innerText()).trim() === 'open' && sql(`SELECT reopened_by FROM periods WHERE id = ${last}`) === '1', 'Reopen with a reason, through its confirmation: the last closed period is open again');
  await page.goto(BASE + '/periods/calendars', { waitUntil: 'networkidle' });
  await page.selectOption('#calendar-preview-calendar', { index: 0 });
  await page.click('#calendar-preview-apply');
  await page.waitForSelector('#calendar-preview-table', { timeout: 10000 });
  ok((await page.locator('#calendar-preview-table tbody tr').count()) >= 12, 'the calendar preview lists the periods before the button');
  for (const [name, path] of [['period-list', '/periods/'], ['usage-report', usageUrl], ['usage-ingredient', '/usage/' + egg + '?from=' + w4[0] + '&to=' + w4[1]], ['cogs-report', '/usage/cogs'], ['calendar-list', '/periods/calendars'], ['period-view', '/periods/' + id(4)]]) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const o = await overflow(page);
    ok(o.sw <= o.iw, name + ' fits at 1280');
  }
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors[0] : ''));
  await ctx.close();
}

// ---- JavaScript off: plain forms ----
{
  const { ctx, page, errors } = await session(mint(1, 101), { width: 1280, height: 800 }, { javaScriptEnabled: false });
  console.log('JavaScript off — the forms are forms');
  await page.goto(BASE + '/periods/calendars', { waitUntil: 'load' });
  await page.fill('#calendar-form-field-name', 'No-JS months');
  await page.selectOption('#calendar-form-field-kind', 'month');
  await page.fill('#calendar-form-field-year_start_on', new Date().getFullYear() + '-01-01');
  await page.locator('#calendar-form-field-name').press('Enter');
  await page.waitForURL(/notice=created/, { timeout: 15000 });
  ok(sql("SELECT count(*) FROM costing_calendars WHERE name = 'No-JS months'") === '1' && (await page.locator('#notice-banner').innerText()).includes('calendar is made'), 'the calendar form posts and lands on its row with the notice');
  await page.goto(BASE + usageUrl, { waitUntil: 'load' });
  await page.fill('#usage-form-field-to', w4[0]);
  await page.selectOption('#usage-form-field-sort', 'name');
  await page.click('#usage-form-apply');
  await page.waitForURL(/sort=name/, { timeout: 15000 });
  ok(page.url().includes('to=' + w4[0]) && (await page.locator('#usage-table').count()) === 1, 'the usage form is a GET: the window and the sort in the address, the table answered');
  const csv = await page.request.get(BASE + usageUrl + '&format=csv');
  ok(csv.ok() && (csv.headers()['content-type'] || '').includes('text/csv'), 'the CSV link answers text/csv');
  await page.goto(BASE + '/periods/', { waitUntil: 'load' });
  const open = sql("SELECT id FROM periods WHERE scope_id = 101 AND status = 'open' AND ends_on < current_date ORDER BY starts_on LIMIT 1");
  ok(/^\d+$/.test(open) && (await page.locator('#period-close-form').count()) === 1, 'the close form is a plain form on the list (the owner holds periods.close)');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors[0] : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? failed + ' FAILED (' + passed + ' passed)' : 'all ' + passed + ' passed');
process.exit(failed ? 1 : 0);
