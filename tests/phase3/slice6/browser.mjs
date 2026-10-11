// Headless Chromium proof of slice 6 (counts.md "Proof": Offline, 375 × 740 and 1280 × 800): the count sheet on a phone — one area at a time, the pager kept
// in view, 44 px controls — with the network cut: three entries kept in localStorage, the amber dots and the banner, the keys draining oldest first when the
// signal returns, a 422 clearing its key and saying why, a reload with the sends blocked still showing what is pending, JavaScript off posting plain forms;
// the review at 1280 in full and the approval through the confirmation; the sheet editor's drag; scrollWidth ≤ the viewport on every screen; no console errors.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const P = process.env.P_APP || '9329';
const BASE = 'http://127.0.0.1:' + P;
const SHOTS = process.env.SHOTS || '/tmp/ri-shots/slice6';
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
const keys = (page) => page.evaluate(() => Object.keys(localStorage).filter((k) => k.startsWith('ri.count.')));

const ing = (code) => sql(`SELECT id FROM ingredients WHERE code = '${code}'`);
const egg = ing('L-EGG'), oil = ing('L-OIL'), flour = ing('L-FLOUR'), lime = ing('L-LIME');
const walkin = sql("SELECT id FROM storage_areas WHERE scope_id = 101 AND name = 'SMOKE Walk-in'");
const fullHouse = sql("SELECT id FROM count_sheets WHERE name = 'Full house'");
sql(`UPDATE inventory_counts SET status = 'discarded' WHERE scope_id = 101 AND status IN ('open', 'review', 'recount')`);
sql(`INSERT INTO count_sheets (scope_id, name, kind) VALUES (101, 'Walk-in test', 'spot')`);
const sheet = sql("SELECT id FROM count_sheets WHERE name = 'Walk-in test'");
sql(`INSERT INTO count_sheet_lines (sheet_id, storage_area_id, ingredient_id, shelf_order) VALUES (${sheet}, ${walkin}, ${egg}, 1), (${sheet}, ${walkin}, ${oil}, 2), (${sheet}, ${walkin}, ${flour}, 3), (${sheet}, ${walkin}, ${lime}, 4)`);
ok(/^\d+$/.test(sheet) && /^\d+$/.test(fullHouse), 'the PHP proofs left the world and a sheet to count from');

const browser = await chromium.launch();
async function session(signOn, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/503 \(Service Unavailable\)|404 \(Not Found\)|422 \(|ERR_INTERNET_DISCONNECTED|ERR_FAILED|Failed to load resource: net::/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(signOn, { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const settle = (page) => page.waitForLoadState('networkidle');

// ---- 375 × 740: Bea counts the walk-in on a phone ----
let countId = '', L = {};
{
  const { ctx, page, errors } = await session(mint(28, 101), { width: 375, height: 740 });
  console.log('375 x 740 — Bea starts a count and walks the Walk-in');
  await page.goto(BASE + '/counts/new?sheet=' + sheet, { waitUntil: 'networkidle' });
  ok((await page.inputValue('#count-form-field-sheet')) === sheet && (await page.locator('#count-form-field-blind').isDisabled()), 'the start form: the sheet chosen, blind disabled for a Kitchen member');
  await page.click('#count-form-submit');
  await page.waitForURL(/\/counts\/\d+(\?.*)?$/, { timeout: 15000 });
  await settle(page);
  countId = page.url().match(/\/counts\/(\d+)/)[1];
  const line = (i) => sql(`SELECT id FROM inventory_count_lines WHERE count_id = ${countId} AND ingredient_id = ${i}`);
  L = { egg: line(egg), oil: line(oil), flour: line(flour), lime: line(lime) };
  ok(/^\d+$/.test(countId) && Object.values(L).every((x) => /^\d+$/.test(x)), 'Start lands on the count with its four lines (' + page.url().replace(BASE, '') + ')');
  ok(await page.locator('#count-area-pager').isVisible() && await page.locator('#count-progress').isVisible() && (await page.locator('#count-progress').innerText()).includes('0 of 4 lines'), 'the area pager and the progress ("0 of 4 lines")');
  ok(await page.locator('#count-review-btn').isDisabled(), 'Review is disabled while lines are pending');
  ok((await smallControls(page, '#count-lines')).length === 0, 'every control of the lines is at least 44 px tall' + ((await smallControls(page, '#count-lines')).join(',') ? ': ' + (await smallControls(page, '#count-lines')).join(',') : ''));
  await page.screenshot({ path: SHOTS + '/count-view-375.png' });

  // online: a save swaps the row for the server's and moves the bar
  await page.fill('#count-line-' + L.egg + '-whole', '2');
  await page.locator('#count-line-' + L.egg + '-whole').blur();
  await page.waitForFunction((id) => document.getElementById('count-line-' + id + '-state')?.textContent.trim() === 'counted', L.egg, { timeout: 8000 }).catch(() => {});
  ok((await page.locator('#count-line-' + L.egg + '-state').innerText()).trim() === 'counted' && (await page.locator('#count-progress-done').innerText()) === '1' && (await keys(page)).length === 0, 'online: eggs saved as typed — the row is the server\'s, the bar says 1 of 4, no key is kept');
  ok(sql(`SELECT counted_qty_base FROM inventory_count_lines WHERE id = ${L.egg}`).startsWith('60'), '…and the database has 2 flats = 60 each');

  // the sticky pager: scrolled down, still in view
  await page.evaluate(() => window.scrollTo(0, 600));
  await page.waitForTimeout(300);
  const sticky = await page.evaluate(() => { const r = document.getElementById('count-sticky').getBoundingClientRect(); return { top: r.top, bottom: r.bottom, vis: r.bottom > 0 && r.top < window.innerHeight }; });
  ok(sticky.vis && sticky.top >= 78 && sticky.top < 200, 'the pager and progress stay in view when the page is scrolled (top ' + Math.round(sticky.top) + ' px)');
  await page.evaluate(() => window.scrollTo(0, 0));

  // the network cut after the page loaded: three entries
  console.log('375 x 740 — the signal is gone');
  await ctx.setOffline(true);
  await page.fill('#count-line-' + L.oil + '-whole', '1');
  await page.selectOption('#count-line-' + L.oil + '-whole-unit', 'case4');
  await page.locator('#count-line-' + L.oil + '-whole').blur();
  await page.waitForTimeout(500);
  await page.fill('#count-line-' + L.flour + '-whole', '2');
  await page.selectOption('#count-line-' + L.flour + '-whole-unit', 'bag50');
  await page.locator('#count-line-' + L.flour + '-whole').blur();
  await page.waitForTimeout(500);
  await page.click('#count-line-' + L.lime + '-zero');
  await page.waitForFunction(() => document.getElementById('count-offline') && !document.getElementById('count-offline').hidden, null, { timeout: 8000 }).catch(() => {});
  const ks = await keys(page);
  ok(ks.length === 3 && ks.every((k) => k.startsWith('ri.count.' + countId + '.')), 'three entries typed with no signal → three localStorage keys (ri.count.<count>.<line>)');
  ok((await page.locator('.count-line.line-pending').count()) === 3 && await page.locator('#count-line-' + L.oil + ' .count-pending-dot').isVisible(), 'three rows carry the amber dot ("saved on this phone — not yet sent")');
  ok((await page.locator('#count-offline').innerText()).includes('3 entries saved on this phone') && (await page.locator('#count-offline').innerText()).includes('sent when the signal returns'), 'the banner: "3 entries saved on this phone. They are sent when the signal returns."');
  const bb = await page.locator('#count-offline').boundingBox();
  ok(bb !== null && bb.y + bb.height <= 740 && bb.y > 400 && bb.width >= 370, 'the banner sits at the bottom of the screen (y ' + Math.round(bb?.y ?? 0) + ')');
  ok(sql(`SELECT count(*) FROM inventory_count_lines WHERE count_id = ${countId} AND state <> 'pending'`) === '1', 'nothing reached the server (one line counted: the eggs)');
  await page.screenshot({ path: SHOTS + '/count-offline-375.png' });

  // a reload with the sends blocked (the signal back for pages, not for the POSTs): the keys still show what is pending
  await page.route('**/counts/line.php', (r) => r.abort('connectionfailed'));
  await ctx.setOffline(false);
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  ok((await keys(page)).length === 3 && (await page.locator('.count-line.line-pending').count()) === 3 && !(await page.locator('#count-offline').isHidden()), 'after a reload with the sends still failing: the pending marks and the banner are rebuilt from the keys');
  ok((await page.inputValue('#count-line-' + L.oil + '-whole')) === '1' && (await page.inputValue('#count-line-' + L.flour + '-whole')) === '2', '…and the fields show what was typed, not what the server has');
  await page.unroute('**/counts/line.php');

  // the signal returns: oldest first, one at a time
  const sent = [];
  page.on('request', (r) => { if (r.url().endsWith('/counts/line.php') && r.method() === 'POST') sent.push(new URLSearchParams(r.postData() || '').get('count_line')); });
  await page.evaluate(() => window.dispatchEvent(new Event('online')));
  await page.waitForFunction(() => Object.keys(localStorage).filter((k) => k.startsWith('ri.count.')).length === 0, null, { timeout: 12000 }).catch(() => {});
  await page.waitForTimeout(500);
  ok((await keys(page)).length === 0 && await page.locator('#count-offline').isHidden() && (await page.locator('.count-line.line-pending').count()) === 0, 'the signal back: the keys drain, the dots go, the banner clears');
  ok(sent.join(',') === [L.oil, L.flour, L.lime].join(','), 'they were sent oldest first, one at a time (' + sent.join(',') + ')');
  ok(sql(`SELECT count(*) FROM inventory_count_lines WHERE count_id = ${countId} AND state <> 'pending'`) === '4' && sql(`SELECT state FROM inventory_count_lines WHERE id = ${L.lime}`) === 'zero' && sql(`SELECT counted_qty_base FROM inventory_count_lines WHERE id = ${L.oil}`).startsWith('15141.65'), 'the server has all four lines: oil 1 case, flour 2 bags, limes zero');
  ok((await page.locator('#count-line-' + L.oil + '-state').innerText()).trim() === 'counted' && (await page.locator('#count-progress-done').innerText()) === '4' && !(await page.locator('#count-review-btn').isDisabled()), 'the rows are the server\'s again, the bar says 4 of 4 and Review is enabled');

  // a 422 clears its key and says why
  await page.evaluate((id) => { const s = document.getElementById('count-line-' + id + '-whole-unit'); const o = document.createElement('option'); o.value = 'zzz'; o.textContent = 'zzz'; s.appendChild(o); s.value = 'zzz'; }, L.egg);
  await page.fill('#count-line-' + L.egg + '-whole', '4');
  await page.locator('#count-line-' + L.egg + '-whole').blur();
  await page.waitForFunction((id) => document.getElementById('count-line-' + id + '-error')?.textContent.includes('No unit zzz'), L.egg, { timeout: 8000 }).catch(() => {});
  ok((await page.locator('#count-line-' + L.egg + '-error').innerText()).includes('No unit zzz') && (await keys(page)).length === 0, 'a unit the database refuses: its sentence under the line, and the key is cleared (the person must fix it)');
  ok(sql(`SELECT counted_qty_base FROM inventory_count_lines WHERE id = ${L.egg}`).startsWith('60'), '…and the line is unchanged');
  await page.screenshot({ path: SHOTS + '/count-error-375.png' });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors[0] : ''));
  await ctx.close();
}

// ---- JavaScript off: every input pair is a form ----
{
  const { ctx, page } = await session(mint(28, 101), { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('375 x 740 — JavaScript off');
  await page.goto(BASE + '/counts/' + countId + '?area=' + walkin, { waitUntil: 'networkidle' });
  ok(await page.locator('#count-line-' + L.egg + '-save').isVisible() && await page.locator('#count-line-' + L.egg + '-zero').isVisible() && await page.locator('#count-offline').isHidden(), 'the Save button shows, 0 and Skip are buttons, the offline banner is not there');
  await page.fill('#count-line-' + L.egg + '-whole', '3');
  await Promise.all([page.waitForURL(/\/counts\/\d+\?area=\d+/, { timeout: 10000 }), page.click('#count-line-' + L.egg + '-save')]);
  ok(sql(`SELECT counted_qty_base FROM inventory_count_lines WHERE id = ${L.egg}`).startsWith('90'), 'Save posts the pair as a form: 3 flats = 90 each, and the page comes back at the area');
  await Promise.all([page.waitForResponse((r) => r.url().endsWith('/counts/line.php'), { timeout: 10000 }), page.locator('#count-line-' + L.lime + '-skip').evaluate((b) => b.click())]);
  await page.waitForTimeout(500);
  ok(sql(`SELECT state FROM inventory_count_lines WHERE id = ${L.lime}`) === 'skipped', 'Skip is a form too');
  await ctx.close();
}

// ---- 375: the review on a phone, the screens fit ----
{
  const { ctx, page, errors } = await session(mint(30, 101), { width: 375, height: 740 });
  console.log('375 x 740 — Dana');
  const shots = {};
  for (const [name, path] of [['count-list', '/counts/'], ['count-add', '/counts/new'], ['count-view', '/counts/' + countId], ['count-review', '/counts/' + countId + '/review'], ['count-history', '/counts/' + countId + '/history'], ['count-sheet-list', '/counts/sheets/'], ['count-sheet-edit', '/counts/sheets/' + fullHouse], ['container-list', '/counts/containers/']]) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const o = await overflow(page);
    shots[name] = o;
    await page.screenshot({ path: SHOTS + '/' + name + '-375.png' });
    ok(o.sw <= o.iw, name + ' fits the screen (scrollWidth ' + o.sw + ' ≤ ' + o.iw + ')');
  }
  await page.goto(BASE + '/counts/' + countId + '/review', { waitUntil: 'networkidle' });
  ok(await page.locator('#review-table').isVisible() && !(await page.locator('#review-table thead th:has-text("At cost")').isVisible()) && await page.locator('#review-table thead th:has-text("Variance")').isVisible(), 'the review on a phone shows ingredient · book · counted · variance; the rest is under "more"');
  ok((await smallControls(page, '#count-review-actions')).length === 0, 'the review\'s buttons are at least 44 px');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors[0] : ''));
  await ctx.close();
}

// ---- 1280 × 800: the review in full, the approval, the sheet editor's drag ----
{
  const { ctx, page, errors } = await session(mint(30, 101), { width: 1280, height: 800 });
  console.log('1280 x 800 — Dana reviews and approves');
  page.on('dialog', async (d) => { dialogText = d.message(); await d.accept(); });
  var dialogText = '';
  await page.goto(BASE + '/counts/' + countId + '/review', { waitUntil: 'networkidle' });
  ok(await page.locator('#count-review-btn').isVisible() && (await page.locator('#count-status').innerText()).includes('open'), 'the review of an open count says so and offers Review');
  await page.click('#count-review-btn');
  await page.waitForURL(/\/review\?notice=reviewed/, { timeout: 10000 }).catch(() => {});
  await settle(page);
  const status = sql(`SELECT status FROM inventory_counts WHERE id = ${countId}`);
  ok(status === 'recount' || status === 'review', 'Review from the screen: the count is now ' + status);
  const heads = await page.locator('#review-table thead th').evaluateAll((ths) => ths.filter((t) => t.getBoundingClientRect().width > 0).length);
  ok(heads === 10, 'at 1280 the review table shows every column (' + heads + ' of 10)');
  const o = await overflow(page);
  ok(o.sw <= o.iw, 'no sideways scroll at 1280');
  await page.screenshot({ path: SHOTS + '/count-review-1280.png' });
  const approve = status === 'review' ? '#count-approve' : '#count-approve-force';
  await page.click(approve);
  await page.waitForURL(/\/history/, { timeout: 10000 }).catch(() => {});
  await settle(page);
  ok(/^Approve CNT-\d{5}: \d+ corrections? at /.test(dialogText), 'the confirmation counts the corrections: "' + dialogText + '"');
  ok(sql(`SELECT status FROM inventory_counts WHERE id = ${countId}`) === 'approved' && page.url().includes('/history') && await page.locator('#history-corrections-table').isVisible(), 'approved: it lands on the history with the corrections');
  await page.screenshot({ path: SHOTS + '/count-history-1280.png' });

  console.log('1280 x 800 — the sheet editor');
  await page.goto(BASE + '/counts/sheets/' + fullHouse, { waitUntil: 'networkidle' });
  const before = sql(`SELECT string_agg(ingredient_id::text, ',' ORDER BY shelf_order, id) FROM count_sheet_lines WHERE sheet_id = ${fullHouse} AND storage_area_id = ${walkin}`);
  const ids = sql(`SELECT string_agg(id::text, ',' ORDER BY shelf_order, id) FROM count_sheet_lines WHERE sheet_id = ${fullHouse} AND storage_area_id = ${walkin}`).split(',');
  const h1 = await page.locator('#sheet-line-' + ids[0] + ' .sheet-handle').boundingBox();
  const h2 = await page.locator('#sheet-line-' + ids[1]).boundingBox();
  ok(h1 !== null && h2 !== null, 'the Walk-in\'s lines have drag handles at 1280');
  if (h1 && h2) {
    await page.mouse.move(h1.x + h1.width / 2, h1.y + h1.height / 2);
    await page.mouse.down();
    await page.mouse.move(h1.x + h1.width / 2, h1.y + h1.height / 2 + 10, { steps: 4 });
    await page.mouse.move(h1.x + h1.width / 2, h2.y + h2.height - 4, { steps: 12 });
    await page.mouse.up();
    await page.waitForFunction((a) => document.getElementById('sheet-lines-' + a)?.getAttribute('data-saved'), walkin, { timeout: 8000 }).catch(() => {});
  }
  const after = sql(`SELECT string_agg(ingredient_id::text, ',' ORDER BY shelf_order, id) FROM count_sheet_lines WHERE sheet_id = ${fullHouse} AND storage_area_id = ${walkin}`);
  ok(after !== before && after.split(',').reverse().join(',') === before, 'dragging the first line below the second saves the new shelf order (' + before + ' → ' + after + ')');
  const down = await page.locator('#sheet-line-' + ids[1] + '-up').isDisabled();
  ok(down, '…and the ▲/▼ buttons follow the new order (the moved line is first, its ▲ disabled)');
  await page.screenshot({ path: SHOTS + '/count-sheet-1280.png' });
  for (const [name, path] of [['count-list', '/counts/'], ['count-add', '/counts/new'], ['count-sheet-list', '/counts/sheets/'], ['container-list', '/counts/containers/']]) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const x = await overflow(page);
    ok(x.sw <= x.iw, name + ' fits at 1280');
  }
  await page.screenshot({ path: SHOTS + '/container-list-1280.png' });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors[0] : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? failed + ' FAILED (' + passed + ' passed)' : 'all ' + passed + ' passed');
process.exit(failed ? 1 : 0);
