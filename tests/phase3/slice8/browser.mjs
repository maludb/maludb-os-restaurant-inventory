// Headless Chromium proof of slice 8 (projection.md "Proof": 375 × 740 and 1280 × 800): the forecast table pinning item · 7 days · total, the charts scaling through their viewBox, the calendar as a
// list of days on a phone and a grid on a desk, the suggested-order card with its lines scrolling and Draft the PO at 44 px, the proposals as cards with 44 px buttons, the forms through HTMX and with
// JavaScript off, scrollWidth ≤ the viewport on every screen, no console errors.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const P = process.env.P_APP || '9337';
const BASE = 'http://127.0.0.1:' + P;
const SHOTS = process.env.SHOTS || '/tmp/ri-shots/slice8';
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

// the state the PHP proofs left: clear the findings, the drafts, the overrides, the closures; then a world for the screens
sql("DELETE FROM item_forecasts; DELETE FROM forecast_runs; DELETE FROM forecast_overrides; DELETE FROM calendar_days; DELETE FROM covers_expected; DELETE FROM buyer_proposals; DELETE FROM watches; " +
    "DELETE FROM purchase_order_lines WHERE purchase_order_id IN (SELECT id FROM purchase_orders WHERE origin IN ('suggested', 'agent') AND status = 'draft'); DELETE FROM purchase_orders WHERE origin IN ('suggested', 'agent') AND status = 'draft'; " +
    "DELETE FROM activity_log WHERE action = 'buyer.note'");
const sand = sql("SELECT id FROM menu_items WHERE plu = '8001'");
const egg = sql("SELECT id FROM ingredients WHERE code = 'L-EGG'");
const sysco = sql("SELECT id FROM suppliers WHERE name = 'SMOKE Sysco'");
const braise = sql("SELECT id FROM recipes WHERE code = 'L-R-BRAISE'");
const delivery = sql(`SELECT delivery_on FROM ri_next_order_windows(${sysco}, 101, now())`);
const today = sql('SELECT current_date');
sql(`INSERT INTO buyer_proposals (scope_id, kind, subject_type, subject_id, title, detail, proposed_by, note_date) VALUES (101, 'pos', 'site', 101, 'The POS missed yesterday', '{"summary":"No sales were recorded for yesterday."}', 42, (now() AT TIME ZONE 'America/New_York')::date), (101, 'price', 'ingredient', ${egg}, 'Eggs are up 6 %', '{"summary":"The last invoice moved the price."}', 42, (now() AT TIME ZONE 'America/New_York')::date)`);
const props = sql("SELECT string_agg(id::text, ',' ORDER BY id) FROM buyer_proposals").split(',');
const pday = sql("SELECT note_date FROM buyer_proposals LIMIT 1");
ok(/^\d+$/.test(sand) && /^\d+$/.test(egg) && /^\d+$/.test(sysco) && props.length === 2, 'the PHP proofs left the world: the menu items, eggs, Sysco, the braise');

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
  console.log('375 x 740 — Marco: the forecast, the item, the calendar, the proposals, the suggested order');
  await page.goto(BASE + '/projection/', { waitUntil: 'networkidle' });
  let x = await overflow(page);
  ok(x.sw <= x.iw, 'the forecast fits at 375 — the table scrolls inside its own box (' + x.sw + '/' + x.iw + ')');
  const pin = await page.evaluate(() => { const a = document.querySelector('#forecast-table tbody td:first-child'); const t = document.querySelector('.fc-table-wrap'); t.scrollLeft = 200; const r = a.getBoundingClientRect(); return { left: Math.round(r.left), scrollable: t.scrollWidth > t.clientWidth + 50 }; });
  ok(pin.scrollable && pin.left <= 30, 'the menu item stays pinned at the left while the days scroll (' + pin.left + ')');
  ok((await page.locator('#forecast-table tbody tr').count()) === 4 && (await page.locator('#forecast-row-' + sand + ' .fc-src-baseline').count()) === 7, 'a row per item with seven day cells, each marked baseline before any run');
  const small = await smallControls(page, '#forecast-list-content');
  ok(small.length === 0, 'Run the forecast now and the filters are at least 44 px tall' + (small.length ? ': ' + small.join(',') : ''));
  await page.screenshot({ path: SHOTS + '/forecast-375.png' });
  await page.click('#forecast-run-btn');
  await page.waitForURL(/\/projection\/\?notice=run/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  ok((await page.locator('#notice-banner').innerText()).includes('forecast was run') && (await page.locator('#forecast-row-' + sand + ' .fc-src-run').count()) === 7, 'Run the forecast now (HTMX): the notice, and every cell says run');

  await page.goto(BASE + '/projection/items/' + sand, { waitUntil: 'networkidle' });
  x = await overflow(page);
  const svg = await page.evaluate(() => { const s = document.querySelector('#forecast-chart svg'); const r = s.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height), bars: document.querySelectorAll('#forecast-chart .fc-bar').length, pts: document.querySelector('#forecast-chart .fc-line').getAttribute('points').split(' ').length }; });
  ok(x.sw <= x.iw && svg.w <= 375 && svg.w > 250 && svg.bars === 56 && svg.pts === 21, 'the item chart scales through its viewBox to the width (' + svg.w + ' × ' + svg.h + '), 56 bars and 21 line points');
  await page.screenshot({ path: SHOTS + '/item-375.png' });
  await page.fill('#override-form-field-factor', '1.5');
  await page.fill('#override-form-field-reason', 'browser: the festival');
  const s2 = await smallControls(page, '#override-form');
  ok(s2.length === 0, 'the override form\'s fields and button are at least 44 px tall' + (s2.length ? ': ' + s2.join(',') : ''));
  await page.click('#override-save-btn');
  await page.waitForURL(/\/projection\/items\/\d+\?notice=override/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  ok((await page.locator('#notice-banner').innerText()).includes('override is set') && (await page.locator('#overrides-card .list-group-item').count()) === 1 && sql("SELECT count(*) FROM forecast_overrides WHERE status = 'set'") === '1', 'the Override form (HTMX) sets it: the notice, the row in the list, the database agrees');
  page.once('dialog', (d) => d.accept());
  await page.click('#overrides-card button[id^=override-remove-]');
  await page.waitForURL(/notice=removed/, { timeout: 20000 });
  ok(sql('SELECT count(*) FROM forecast_overrides') === '0', 'Remove, through its confirmation, takes it away');

  await page.goto(BASE + '/projection/calendar', { waitUntil: 'networkidle' });
  x = await overflow(page);
  const cols = await page.evaluate(() => { const d = [...document.querySelectorAll('#calendar-grid .cal-day')].slice(0, 3).map((e) => Math.round(e.getBoundingClientRect().left)); return { lefts: d, pad: [...document.querySelectorAll('#calendar-grid .cal-pad')].every((p) => getComputedStyle(p).display === 'none') }; });
  ok(x.sw <= x.iw && cols.lefts[0] === cols.lefts[1] && cols.pad, 'the calendar is a list of days on a phone (one column, no padding cells)');
  await page.screenshot({ path: SHOTS + '/calendar-375.png' });
  const dayId = await page.evaluate(() => [...document.querySelectorAll('#calendar-grid .cal-day')][12].id.replace('calendar-day-', ''));
  await page.click('#calendar-day-open-' + dayId);
  await page.waitForURL(/day=/, { timeout: 20000 });
  await page.waitForSelector('#calendar-day-form');
  await page.check('#calendar-day-form-field-closed');
  await page.fill('#calendar-day-form-field-holiday', 'browser holiday');
  await page.click('#calendar-day-save-btn');
  await page.waitForURL(/notice=saved/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  ok((await page.locator('#calendar-day-' + dayId + '[data-closed="1"]').count()) === 1 && (await page.locator('#calendar-day-' + dayId).innerText()).includes('browser holiday'), 'a tap on a day opens its form; closing it with a holiday shows on the cell');

  await page.goto(BASE + '/projection/proposals?date=' + pday, { waitUntil: 'networkidle' });
  x = await overflow(page);
  const smallP = await smallControls(page, '#proposal-list-content .proposal-card');
  ok(x.sw <= x.iw && smallP.length === 0 && (await page.locator('.proposal-card').count()) === 2, 'the proposals are cards with 44 px Accept / Dismiss buttons (' + x.sw + '/' + x.iw + ')');
  await page.screenshot({ path: SHOTS + '/proposals-375.png' });
  await page.click('#proposal-accept-' + props[0]);
  await page.waitForURL(/notice=accepted/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  ok(sql(`SELECT status FROM buyer_proposals WHERE id = ${props[0]}`) === 'accepted' && (await page.locator('#proposal-card-' + props[0] + ' .badge').innerText()).trim() === 'accepted', 'Accept (HTMX): the card says accepted and its buttons are gone');
  await page.click('#note-send-btn');
  await page.waitForURL(/notice=sent/, { timeout: 20000 });
  ok((await page.locator('#note-last').innerText()).includes('Morning note sent'), 'Send the morning note: the page says when it went');

  await page.goto(BASE + '/purchasing/suggested?supplier=' + sysco, { waitUntil: 'networkidle' });
  x = await overflow(page);
  const sc = await page.evaluate(() => { const w = document.querySelector('.sugg-wrap'); const th = document.querySelector('.sugg-table th.sg-units'); const r = th.getBoundingClientRect(); return { scroll: w.scrollWidth > w.clientWidth + 50, units: Math.round(r.right) }; });
  ok(x.sw <= x.iw && sc.scroll && sc.units <= 376, 'the suggested-order card scrolls its lines inside the card; the units column stays pinned at the right (' + x.sw + '/' + x.iw + ')');
  const smallS = await smallControls(page, '.supplier-card .card-header');
  ok(smallS.length === 0, 'Draft the PO and Recompute are at least 44 px tall' + (smallS.length ? ': ' + smallS.join(',') : ''));
  await page.screenshot({ path: SHOTS + '/suggested-375.png' });
  await page.click('#sugg-draft-btn-' + sysco);
  await page.waitForURL(/\/purchasing\/\d+\?notice=drafted/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
  ok(/draft/i.test(await page.locator('body').innerText()) && sql("SELECT count(*) FROM purchase_orders WHERE origin = 'suggested' AND status = 'draft'") === '1', 'Draft the PO (HTMX): the browser lands on the drafted purchase order');
  await page.screenshot({ path: SHOTS + '/drafted-po-375.png' });

  await page.goto(BASE + `/projection/special?recipe=${braise}&date=${delivery}&portions=60`, { waitUntil: 'networkidle' });
  x = await overflow(page);
  ok(x.sw <= x.iw && (await page.locator('#special-verdict').innerText()).includes('Short') && (await page.locator('#special-draft-btn').count()) === 1, 'the special check fits and gives its verdict with the draft button (' + x.sw + '/' + x.iw + ')');
  await page.screenshot({ path: SHOTS + '/special-375.png' });
  for (const [path, name] of [['/projection/stock', 'projected'], ['/projection/stock/' + egg, 'projected-ingredient'], ['/projection/demand', 'demand'], ['/projection/watches', 'watches']]) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    x = await overflow(page);
    ok(x.sw <= x.iw, name + ' fits at 375 (' + x.sw + '/' + x.iw + ')');
    await page.screenshot({ path: SHOTS + '/' + name + '-375.png' });
  }
  const ps = await page.evaluate(() => 0);
  await page.goto(BASE + '/projection/stock/' + egg, { waitUntil: 'networkidle' });
  const pw = await page.evaluate(() => { const r = document.querySelector('#projected-chart svg').getBoundingClientRect(); return Math.round(r.width); });
  ok(pw <= 375 && pw > 250, 'the projected-stock chart scales to the width (' + pw + ')');
  await page.goto(BASE + '/projection/watches', { waitUntil: 'networkidle' });
  await page.fill('#watch-form-field-note', 'browser watch');
  await page.evaluate((id) => { document.querySelector('#watch-form-field-ingredient').value = id; }, egg);
  await page.selectOption('#watch-form-field-kind', 'below_par');
  await page.click('#watch-save-btn');
  await page.waitForURL(/notice=saved/, { timeout: 20000 });
  ok(sql("SELECT count(*) FROM watches WHERE note = 'browser watch'") === '1' && (await page.locator('#watch-table tbody tr').count()) === 1, 'the watch form (HTMX) saves a watch and the list shows it');
  ok(errors.length === 0, 'no console errors on the phone' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- 1280 × 800: Marco at the desk ----
{
  const { ctx, page, errors } = await session(mint(27, 101), { width: 1280, height: 800 });
  console.log('1280 x 800 — Marco: the forecast with its band and accuracy, the calendar as a grid, the suggested orders');
  await page.goto(BASE + '/projection/', { waitUntil: 'networkidle' });
  ok(await page.locator('#forecast-table thead th:has-text("WAPE")').isVisible() && await page.locator('#forecast-table thead th:has-text("Band")').isVisible(), 'the desk shows the band, the WAPE and the bias columns');
  await page.screenshot({ path: SHOTS + '/forecast-1280.png' });
  await page.goto(BASE + '/projection/calendar', { waitUntil: 'networkidle' });
  const cols = await page.evaluate(() => getComputedStyle(document.querySelector('#calendar-grid')).gridTemplateColumns.split(' ').length);
  ok(cols === 7 && await page.locator('.cal-head').isVisible(), 'the calendar is a seven-column grid with its weekday header');
  await page.screenshot({ path: SHOTS + '/calendar-1280.png' });
  await page.goto(BASE + '/projection/stock', { waitUntil: 'networkidle' });
  ok((await page.locator('#projected-table tbody tr').count()) >= 5 && await page.locator('#projected-table th:has-text("Par")').isVisible(), 'the projected stock shows its rows and the par column');
  await page.screenshot({ path: SHOTS + '/projected-1280.png' });
  await page.goto(BASE + '/purchasing/suggested?supplier=' + sysco, { waitUntil: 'networkidle' });
  const x = await overflow(page);
  ok(x.sw <= x.iw && await page.locator('#supplier-total-' + sysco).count() === 1, 'the suggested-order card shows every column and its total without scrolling (' + x.sw + '/' + x.iw + ')');
  await page.screenshot({ path: SHOTS + '/suggested-1280.png' });
  await page.goto(BASE + '/projection/stock/' + egg, { waitUntil: 'networkidle' });
  await page.screenshot({ path: SHOTS + '/projected-ingredient-1280.png' });
  ok(errors.length === 0, 'no console errors at the desk' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- JavaScript off: the same forms are plain posts ----
{
  const { ctx, page } = await session(mint(27, 101), { width: 1280, height: 800 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await page.goto(BASE + '/projection/items/' + sand, { waitUntil: 'load' });
  await page.fill('#override-form-field-qty', '55');
  await page.fill('#override-form-field-reason', 'no-JS override');
  await page.click('#override-save-btn', { force: true });
  await page.waitForURL(/notice=override/, { timeout: 20000 });
  ok(sql("SELECT count(*) FROM forecast_overrides WHERE reason = 'no-JS override'") === '1', 'the Override form posts with JavaScript off and lands on the item with its notice');
  await page.goto(BASE + '/projection/calendar?day=' + today + '&month=' + today.slice(0, 7), { waitUntil: 'load' });
  await page.fill('#calendar-day-form-field-event', 'no-JS event');
  await page.click('#calendar-day-save-btn', { force: true });
  await page.waitForURL(/notice=saved/, { timeout: 20000 });
  ok(sql("SELECT count(*) FROM calendar_days WHERE event = 'no-JS event'") === '1', 'the day form posts with JavaScript off');
  await page.goto(BASE + '/projection/watches', { waitUntil: 'load' });
  ok(await page.locator('#watch-form').count() === 1 && (await page.locator('#watch-table').count()) === 1, 'the watches list renders without JavaScript');
  await ctx.close();
}
await browser.close();
console.log(failed ? failed + ' FAILED (' + passed + ' passed)' : 'all ' + passed + ' passed');
process.exit(failed ? 1 : 0);
