// Headless Chromium proof of slice 3 (ledger.md "Proof": 375 × 740 and 1280 × 800): the receiving screen — the picker chooses chicken, the
// declared-weight pair appears, the temperature buttons, Post kept in view at the bottom; the waste log's three stage buttons and the scale
// fields; the batch preview re-rendering under the form; the levels and movements tables scrolling inside their cards; scrollWidth ≤ the
// viewport on all nineteen screens; at 1280 the tables in full; every control of the line form ≥ 44 px; no console errors; JavaScript off.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const P = process.env.P_APP || '9316';
const BASE = 'http://127.0.0.1:' + P;
const SHOTS = process.env.SHOTS || '/tmp/ri-shots-s3';
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
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ' + s + ' select, ' + s + ' label.btn')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.height < 44 || r.width < 44); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);
const visible = (page, sel) => page.locator(sel).first().isVisible();

const id = (q) => sql(q);
const flour = id("SELECT id FROM ingredients WHERE code = 'L-FLOUR'"), chicken = id("SELECT id FROM ingredients WHERE code = 'L-CHK'");
const stockRecipe = id("SELECT id FROM recipes WHERE code = 'L-R-STOCK'");
const dry = id("SELECT id FROM storage_areas WHERE scope_id = 101 AND name = 'SMOKE Dry'"), sysco = id("SELECT id FROM suppliers WHERE name = 'SMOKE Sysco'");
const receipt = id("SELECT max(id) FROM goods_receipts WHERE scope_id = 101 AND status = 'posted'");
const transfer = id("SELECT max(id) FROM inventory_transfers WHERE scope_id = 101");
const adjustment = id("SELECT max(id) FROM inventory_adjustments WHERE scope_id = 101");
const batch = id("SELECT max(id) FROM production_batches WHERE scope_id = 101 AND status = 'posted'");
const waste = id("SELECT max(id) FROM waste_logs WHERE scope_id = 101");
ok([flour, chicken, stockRecipe, dry, sysco, receipt, transfer, adjustment, batch, waste].every((x) => /^\d+$/.test(x)), 'the PHP proofs left a document of every kind for the browser');

const browser = await chromium.launch();
async function session(signOn, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/503 \(Service Unavailable\)|404 \(Not Found\)/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(signOn, { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
async function pick(page, openId, rowId, search) {
  await page.click('#' + openId);
  await page.waitForSelector('#record-picker.show', { timeout: 5000 });
  await page.fill('#record-picker-search', search);
  await page.waitForTimeout(700);
  await page.click('#record-picker-row-' + rowId);
  await page.waitForTimeout(800);
}

// ---- 375 × 740: Dana receives on a phone ----
let draft = null;
{
  const { ctx, page, errors } = await session(mint(30, 101), { width: 375, height: 740 });
  console.log('375 x 740 — receiving');
  await page.goto(BASE + '/receipts/new', { waitUntil: 'networkidle' });
  await page.selectOption('#receipt-form-field-area', dry);
  await pick(page, 'receipt-form-field-supplier-open', sysco, 'Sysco');
  await page.click('#receipt-form-submit');
  await page.waitForURL(/\/receipts\/\d+(#.*)?$/, { timeout: 15000 }).catch(() => {});
  await page.waitForLoadState('networkidle');
  draft = sql("SELECT max(id) FROM goods_receipts WHERE status = 'draft' AND scope_id = 101");
  ok(page.url().includes('/receipts/' + draft), 'a draft made from the phone lands on its receiving screen');
  ok(await page.locator('#receipt-line-form').count() === 1, 'the line form at 375');
  await pick(page, 'receipt-line-form-field-ingredient-open', flour, 'flour');
  ok(!(await visible(page, '#receipt-line-form-catch-weight')) && !(await visible(page, '#receipt-line-form-temperature')), 'flour chosen: no declared weight, no temperature');
  const units = await page.locator('#receipt-line-form-field-unit option').allInnerTexts();
  ok(units.some((u) => /bag50/.test(u)) && units.some((u) => /^kg/.test(u)), "the unit select filled from flour's units (" + units.length + ')');
  await pick(page, 'receipt-line-form-field-ingredient-open', chicken, 'chicken');
  ok(await visible(page, '#receipt-line-form-catch-weight') && await visible(page, '#receipt-line-form-field-weight_qty'), 'chicken chosen: the declared-weight pair appears');
  ok(await visible(page, '#receipt-line-form-temperature') && await visible(page, 'label[for=receipt-line-form-field-temperature_ok-yes]') && await visible(page, 'label[for=receipt-line-form-field-temperature_ok-no]'), '…and the temperature with its OK / Not OK buttons');
  const cost = await page.inputValue('#receipt-line-form-field-unit_cost');
  ok(cost === '' || /^3\.1/.test(cost), 'the cost field (blank or the last price per lb: ' + (cost || 'blank') + ')');
  await page.fill('#receipt-line-form-field-qty', '1');
  await page.selectOption('#receipt-line-form-field-unit', { label: (await page.locator('#receipt-line-form-field-unit option').allInnerTexts()).find((u) => /^case/.test(u)) });
  await page.fill('#receipt-line-form-field-weight_qty', '38.5');
  await page.selectOption('#receipt-line-form-field-weight_unit', 'lb');
  await page.fill('#receipt-line-form-field-unit_cost', '3.10');
  await page.fill('#receipt-line-form-field-temperature_c', '3');
  await page.click('label[for=receipt-line-form-field-temperature_ok-yes]');
  const small = await smallControls(page, '#receipt-line-form');
  ok(small.length === 0, 'every control of the line form is at least 44 px' + (small.length ? ' (' + small.join(', ') + ')' : ''));
  await page.click('#receipt-line-form-submit');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(900);
  const line = sql('SELECT qty_base FROM goods_receipt_lines WHERE goods_receipt_id = ' + draft);
  ok(Math.abs(parseFloat(line) - 17463.31) < 0.01, 'the line saved from the phone: 38.5 lb declared = 17 463.31 g');
  ok(await page.locator('[id^=receipt-line-card-]').first().isVisible(), 'the line shows as a card on the phone');
  const bar = await page.locator('#receipt-actions').boundingBox();
  const pos = await page.locator('#receipt-actions').evaluate((e) => getComputedStyle(e).position);
  await page.evaluate(() => window.scrollTo(0, 0));
  const barTop = await page.locator('#receipt-actions').boundingBox();
  ok(pos === 'sticky' && bar && barTop && barTop.y >= 0 && barTop.y + barTop.height <= 740, 'Post is kept in view at the bottom (sticky above the tab bar, ' + Math.round(barTop ? barTop.y : -1) + ' px)');
  let o = await overflow(page);
  ok(o.sw <= o.iw, `no sideways scroll on the receiving screen (scrollWidth ${o.sw} ≤ ${o.iw})`);
  await page.screenshot({ path: SHOTS + '/receive-375.png', fullPage: true });

  console.log('375 x 740 — the waste log');
  await page.goto(BASE + '/waste/new', { waitUntil: 'networkidle' });
  const stages = ['ingredient', 'prep', 'plate'];
  const labels = [];
  for (const s of stages) { labels.push(await page.locator('label[for=waste-form-field-stage-' + s + ']').boundingBox()); }
  ok(labels.every((b) => b && b.height >= 44) && labels[0].y === labels[2].y, 'three stage buttons side by side, each ≥ 44 px tall');
  ok(await visible(page, '#waste-form-field-gross_weight_g') && await visible(page, '#waste-form-field-tare_g'), 'ingredient stage: the scale fields (gross and tare)');
  await page.click('label[for=waste-form-field-stage-plate]');
  await page.waitForTimeout(300);
  ok(!(await visible(page, '#waste-form-weigh')) && await visible(page, '#waste-form-field-recipe-label') && !(await visible(page, '#waste-form-field-ingredient-label')), 'plate stage: the recipe picker, no scale, no ingredient');
  const wunits = await page.locator('select[name=unit]').first().locator('option').allInnerTexts();
  ok(wunits.some((u) => /portion/.test(u)), 'the unit offers portions for a plate (' + wunits.join(', ') + ')');
  await page.click('label[for=waste-form-field-stage-prep]');
  await page.waitForTimeout(300);
  ok(await visible(page, '#waste-form-field-recipe-label'), 'prep stage: a recipe too');
  await page.click('label[for=waste-form-field-stage-ingredient]');
  await page.waitForTimeout(300);
  ok(await visible(page, '#waste-form-field-gross_weight_g') && !(await visible(page, '#waste-form-field-recipe-label')), 'back to ingredient: the scale again');
  o = await overflow(page);
  ok(o.sw <= o.iw, `no sideways scroll on the waste log (${o.sw} ≤ ${o.iw})`);
  await page.screenshot({ path: SHOTS + '/waste-375.png', fullPage: true });

  console.log('375 x 740 — the batch preview');
  await page.goto(BASE + '/production/new?recipe=' + stockRecipe, { waitUntil: 'networkidle' });
  const before = await page.locator('#production-preview').innerText();
  await page.fill('#production-form-field-batches', '3');
  await page.locator('#production-form-field-batches').dispatchEvent('change');
  await page.waitForTimeout(1200);
  const after = await page.locator('#production-preview').innerText();
  ok(/6,000 g|6 kg/.test(after) && after !== before, 'the preview re-renders for 3 batches: 6 kg of chicken (' + after.split('\n').find((x) => /chicken/i.test(x)) + ')');
  const fb = await page.locator('#production-form').boundingBox(), pb = await page.locator('#production-preview').boundingBox();
  ok(fb && pb && pb.y >= fb.y, 'the preview renders under the form on a phone');
  o = await overflow(page);
  ok(o.sw <= o.iw, `no sideways scroll on the batch form (${o.sw} ≤ ${o.iw})`);

  console.log('375 x 740 — the tables scroll inside their cards');
  for (const [path, table] of [['/stock/', '#levels-table'], ['/stock/movements', '#movements-table']]) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const wrap = await page.locator(table).evaluate((t) => { const w = t.closest('.stock-table-wrap'); return w ? { sw: w.scrollWidth, cw: w.clientWidth, ox: getComputedStyle(w).overflowX } : null; });
    const p = await overflow(page);
    ok(wrap && wrap.ox === 'auto' && p.sw <= p.iw && (wrap.sw >= wrap.cw), `${path}: the table scrolls inside its card (${wrap ? wrap.sw + '/' + wrap.cw : 'no wrapper'}), the page does not (${p.sw} ≤ ${p.iw})`);
  }
  await page.screenshot({ path: SHOTS + '/movements-375.png', fullPage: true });

  console.log('375 x 740 — all nineteen screens');
  const screens = ['/stock/', '/stock/movements', '/stock/value', '/receipts/', '/receipts/new', '/receipts/' + receipt, '/receipts/' + draft + '/edit', '/transfers/', '/transfers/new', '/transfers/' + transfer,
    '/adjustments/', '/adjustments/new', '/adjustments/' + adjustment, '/production/', '/production/new', '/production/' + batch, '/waste/', '/waste/new', '/waste/' + waste];
  const wide = [];
  for (const s of screens) {
    const r = await page.goto(BASE + s, { waitUntil: 'networkidle' });
    const p = await overflow(page);
    if (!r || r.status() !== 200 || p.sw > p.iw) { wide.push(s + ' ' + (r ? r.status() : '?') + ' ' + p.sw); }
  }
  ok(wide.length === 0, 'every one of the nineteen screens answers 200 with scrollWidth ≤ 375' + (wide.length ? ' (' + wide.join('; ') + ')' : ''));
  ok(errors.length === 0, 'no console errors on the phone' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- 1280 × 800: the tables in full ----
{
  const { ctx, page, errors } = await session(mint(30, 101), { width: 1280, height: 800 });
  console.log('1280 x 800 — the desk');
  for (const [path, table] of [['/stock/', '#levels-table'], ['/stock/movements', '#movements-table'], ['/receipts/', '#receipts-table'], ['/receipts/' + receipt, '#receipt-lines-table'], ['/waste/', '#waste-table'], ['/transfers/', '#transfers-table'], ['/adjustments/', '#adjustments-table'], ['/production/', '#production-table']]) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const t = await page.locator(table).evaluate((x) => { const w = x.parentElement; const r = x.getBoundingClientRect(); return { tw: Math.round(r.width), ww: w.clientWidth, sw: w.scrollWidth, shown: r.width > 0 && getComputedStyle(x).display !== 'none' }; }).catch(() => null);
    ok(t && t.shown && t.sw <= t.ww + 1, `${path}: ${table} in full (${t ? t.tw + ' px in ' + t.ww : 'missing'})`);
  }
  await page.goto(BASE + '/receipts/' + draft, { waitUntil: 'networkidle' });
  ok(await page.locator('#receipt-lines-table').isVisible() && !(await page.locator('[id^=receipt-line-card-]').first().isVisible()), 'at the desk the lines are a table, the cards hidden');
  await page.screenshot({ path: SHOTS + '/receipt-1280.png', fullPage: true });
  await page.goto(BASE + '/waste/', { waitUntil: 'networkidle' });
  const tiles = await page.locator('[id^=waste-tile-]').filter({ hasNot: page.locator('[id$=-entries]') }).count();
  ok(await page.locator('#waste-week').isVisible() && await page.locator('#waste-tile-plate').isVisible(), "the week's tiles at the desk");
  const o = await overflow(page);
  ok(o.sw <= o.iw, `no sideways scroll at 1280 (${o.sw} ≤ ${o.iw})`);
  ok(errors.length === 0, 'no console errors at the desk' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- JavaScript off: the forms post ----
{
  const { ctx, page } = await session(mint(30, 101), { width: 1280, height: 800 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await page.goto(BASE + '/receipts/' + draft);
  ok((await page.locator('#receipt-line-form').getAttribute('method')) === 'post' && await page.locator('#receipt-line-form-catch-weight').isVisible() && await page.locator('#receipt-line-form-temperature').isVisible(), 'the line form posts, the declared weight and the temperature rendered for any ingredient');
  await page.goto(BASE + '/waste/new');
  ok((await page.locator('#waste-form').getAttribute('method')) === 'post', 'the waste log posts');
  await page.goto(BASE + '/waste/new?stage=plate');
  ok(await page.locator('#waste-form-field-recipe-label').isVisible(), '?stage=plate opens on the recipe (the link the phone shares)');
  await page.goto(BASE + '/production/new?recipe=' + stockRecipe);
  ok(await page.locator('#production-preview-table').count() === 1, 'the batch preview renders on the server for a recipe in the address');
  await ctx.close();
}
sql('UPDATE goods_receipts SET status = \'cancelled\' WHERE id = ' + draft + " AND status = 'draft'");
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
