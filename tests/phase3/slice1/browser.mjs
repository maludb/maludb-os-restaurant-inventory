// Headless Chromium proof of the catalog (docs/build-specs/catalog.md "Proof: browser"): at 375 x 740 and 1280 x 800 — the ingredient cards and the
// page's tabs without sideways scroll, every control ≥ 44 px, the form's unit options following the class (unit-options.php), the own-units table,
// the record picker on the supplier item form, the order guide reordered by drag (SortableJS) and saved, the areas reordered, the cost chart drawn
// for the Chef and absent for the Viewer, no console errors; with JavaScript off the guide's seq inputs and the list whole. Runs after catalog.php
// (the world is there). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9301';
const SHOTS = process.env.SHOTS || '/tmp/ri-shots-slice1';
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
const browser = await chromium.launch();
async function session(signOn, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/503 \(Service Unavailable\)/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.goto(signOn, { waitUntil: 'networkidle' });
  return { ctx, page, errors };
}
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth }));
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ' + s + ' select, ' + s + ' .app-tab')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.height < 44 || r.width < 44); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);
const flour = sql("SELECT id FROM ingredients WHERE code = 'FLOUR'");
const sysco = sql("SELECT id FROM suppliers WHERE name = 'SMOKE Sysco'");

// ---- phone: the Chef ----
{
  const { ctx, page, errors } = await session(mint(30), { width: 375, height: 740 });
  console.log('375 x 740 — the Chef');
  await page.goto(BASE + '/ingredients/', { waitUntil: 'networkidle' });
  let o = await overflow(page);
  ok(o.sw === o.iw, `the ingredient cards: no sideways scroll (${o.sw} = ${o.iw})`);
  ok((await page.locator('#ingredient-card-' + flour).count()) === 1, "flour's card is there");
  let small = await smallControls(page, 'main');
  ok(small.length === 0, 'every control on the list ≥ 44 px' + (small.length ? ' — ' + small.join(', ') : ''));
  await page.screenshot({ path: SHOTS + '/ingredients-375.png', fullPage: true });
  await page.locator('#ingredient-card-' + flour + '-name').click();
  await page.waitForURL(/\/ingredients\/\d+/, { timeout: 10000 }).catch(() => {});
  await page.waitForLoadState('networkidle');
  ok(page.url().startsWith(BASE + '/ingredients/' + flour), 'the card opens the ingredient (' + page.url().replace(BASE, '') + ')');
  o = await overflow(page);
  ok(o.sw === o.iw, `the ingredient page: no sideways scroll (${o.sw} = ${o.iw})`);
  ok((await page.locator('#ingredient-view-tabs').count()) === 1, 'the tabs row is there');
  await page.locator('#ingredient-view-tab-cost').click();
  await page.waitForSelector('#ingredient-cost-chart', { timeout: 10000 }).catch(() => {});
  await page.waitForTimeout(600);
  ok((await page.locator('#ingredient-cost-chart svg').count()) >= 1, 'the cost chart is drawn (an svg) for the Chef');
  await page.screenshot({ path: SHOTS + '/ingredient-cost-375.png', fullPage: true });
  await page.locator('#ingredient-view-tab-units').click();
  await page.waitForSelector('#ingredient-units-table', { timeout: 10000 }).catch(() => {});
  ok((await page.locator('#ingredient-units-table').count()) === 1 && (await page.locator('text=1 bag50 = 22,679.6185 g').count()) === 1, 'the own-units page with the factor sentence');
  small = await smallControls(page, 'main');
  ok(small.length === 0, 'every control on the units page ≥ 44 px' + (small.length ? ' — ' + small.join(', ') : ''));
  // the form: the unit options follow the class
  await page.goto(BASE + '/ingredients/new', { waitUntil: 'networkidle' });
  o = await overflow(page);
  ok(o.sw === o.iw, `the form: no sideways scroll (${o.sw} = ${o.iw})`);
  const before = await page.locator('#ingredient-form-field-recipe_unit option').allInnerTexts();
  await page.locator('label[for="ingredient-form-field-measure_class-volume"]').click();
  await page.waitForTimeout(800);
  const after = await page.locator('#ingredient-form-field-recipe_unit option').allInnerTexts();
  ok(before.join() !== after.join() && after.some((t) => /floz|ml/.test(t)) && !after.some((t) => /^lb\b| lb\b/.test(t)), 'choosing volume swaps the recipe-unit options to the volume units (' + after.slice(0, 3).join(', ') + ')');
  await page.screenshot({ path: SHOTS + '/ingredient-form-375.png', fullPage: true });
  // the category picker
  await page.locator('#ingredient-form-field-category-open').click();
  await page.waitForSelector('.record-picker-row', { timeout: 5000 });
  await page.locator('#record-picker-search').fill('prod');
  await page.waitForTimeout(600);
  const catRows = await page.locator('.record-picker-row:visible').allInnerTexts();
  ok(catRows.length === 1 && /Produce/.test(catRows[0]), 'the category picker narrows to Produce (' + catRows.join(' | ') + ')');
  await page.locator('.record-picker-row:visible').first().click();
  await page.waitForTimeout(300);
  ok(/Produce/.test(await page.locator('#ingredient-form-field-category-open .record-picker-label').innerText()), 'and the form shows it');
  const o2 = await overflow(page);
  ok(o2.sw === o2.iw, `the form after the picker: no sideways scroll (${o2.sw} = ${o2.iw})`);
  small = await smallControls(page, 'main');
  ok(small.length === 0, 'every control on the form ≥ 44 px' + (small.length ? ' — ' + small.join(', ') : ''));
  // the sites page: the quantity + unit input groups
  await page.goto(BASE + '/ingredients/' + flour + '/sites', { waitUntil: 'networkidle' });
  ok((await page.locator('#site-settings-row-101 input[name="par"]').count()) === 1 && (await page.locator('#site-settings-row-101 select[name="par_unit"]').count()) === 1, 'the par is a quantity + unit input group');
  const o3 = await overflow(page);
  ok(o3.sw === o3.iw, `the sites page: no sideways scroll (${o3.sw} = ${o3.iw})`);
  ok(errors.length === 0, 'no console errors on the phone' + (errors.length ? ' — ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- phone: the Viewer ----
{
  const { ctx, page, errors } = await session(mint(29), { width: 375, height: 740 });
  console.log('375 x 740 — the Viewer');
  await page.goto(BASE + '/ingredients/' + flour + '?tab=cost', { waitUntil: 'networkidle' });
  ok((await page.locator('#ingredient-cost-withheld').count()) === 1 && (await page.locator('#ingredient-cost-chart').count()) === 0, 'no chart for Ann, the withheld note instead');
  ok((await page.locator('#ingredient-view-edit-btn').count()) === 0, 'no Edit button for Ann');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ' — ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- desktop: the Manager, the supplier item picker and the guide drag ----
{
  const { ctx, page, errors } = await session(mint(27, 101), { width: 1280, height: 800 });
  console.log('1280 x 800 — the Manager');
  await page.goto(BASE + '/suppliers/' + sysco + '/items', { waitUntil: 'networkidle' });
  ok((await page.locator('#supplier-items-table').count()) === 1, "Sysco's items page");
  const trig = page.locator('#supplier-item-form-field-ingredient-open');
  ok((await trig.count()) === 1, 'the ingredient picker trigger is on the item form');
  await trig.click();
  await page.waitForSelector('.record-picker-row', { timeout: 5000 });
  await page.locator('#record-picker-search').fill('gin');
  await page.waitForTimeout(600);
  const rows = await page.locator('.record-picker-row:visible').allInnerTexts();
  ok(rows.length === 1 && /Gin/.test(rows[0]), 'typing "gin" in the picker leaves the gin (' + rows.join(' | ') + ')');
  await page.locator('.record-picker-row:visible').first().click();
  await page.waitForTimeout(300);
  ok(/Gin/.test(await page.locator('#supplier-item-form-field-ingredient-open .record-picker-label').innerText()), 'the chosen gin shows on the form');
  await page.screenshot({ path: SHOTS + '/supplier-item-form-1280.png', fullPage: true });
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(mint(30), { width: 1280, height: 800 });
  console.log('1280 x 800 — the Chef drags the guide');
  await page.goto(BASE + '/suppliers/' + sysco + '/guide', { waitUntil: 'networkidle' });
  const first = await page.locator('#guide-list .guide-row').first().getAttribute('data-id');
  const second = await page.locator('#guide-list .guide-row').nth(1).getAttribute('data-id');
  ok(first && second && first !== second, 'the guide has two rows (' + first + ', ' + second + ')');
  const a = page.locator('#guide-list .guide-row').nth(1).locator('.guide-handle');
  const target = page.locator('#guide-list .guide-row').first();
  const ab = await a.boundingBox(); const tb = await target.boundingBox();
  await page.mouse.move(ab.x + ab.width / 2, ab.y + ab.height / 2);
  await page.mouse.down();
  await page.mouse.move(tb.x + tb.width / 2, tb.y + 2, { steps: 12 });
  await page.mouse.move(tb.x + tb.width / 2, tb.y - 4, { steps: 6 });
  await page.mouse.up();
  await page.waitForTimeout(300);
  const nowFirst = await page.locator('#guide-list .guide-row').first().getAttribute('data-id');
  ok(nowFirst === second, 'the second row dragged to the top');
  await page.locator('#guide-save').click();
  await page.waitForLoadState('networkidle');
  const saved = sql("SELECT supplier_item_id FROM order_guide_lines gl JOIN order_guides g ON g.id = gl.guide_id WHERE g.supplier_id = " + sysco + " AND g.scope_id = 101 ORDER BY seq LIMIT 1");
  ok(saved === second, 'and the save keeps the new order (' + saved + ')');
  await page.screenshot({ path: SHOTS + '/order-guide-1280.png', fullPage: true });
  ok(errors.length === 0, 'no console errors on the desktop' + (errors.length ? ' — ' + errors.join(' | ') : ''));
  await ctx.close();
}
{
  const { ctx, page, errors } = await session(mint(1, 101), { width: 1280, height: 800 });
  console.log('1280 x 800 — the owner reorders the areas');
  await page.goto(BASE + '/areas/', { waitUntil: 'networkidle' });
  const n = await page.locator('#areas-list .area-row').count();
  ok(n >= 3, 'the areas list with its rows (' + n + ')');
  const last = await page.locator('#areas-list .area-row').last().getAttribute('data-id');
  await page.locator('#areas-list .area-row').last().locator('.area-up').click();
  await page.waitForTimeout(800);
  const order = sql('SELECT string_agg(id::text, \',\' ORDER BY walk_order) FROM storage_areas WHERE scope_id = 101 AND active').split(',');
  ok(order.indexOf(last) === order.length - 2, 'the ↑ button moved the last area up and sent the order at once (' + order.join(',') + ')');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ' — ' + errors.join(' | ') : ''));
  await ctx.close();
}
// ---- JavaScript off ----
{
  const { ctx, page } = await session(mint(30), { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off — the Chef');
  await page.goto(BASE + '/ingredients/', { waitUntil: 'load' });
  ok((await page.locator('#ingredient-card-' + flour).count()) === 1 && (await page.locator('#ingredient-list-filters').count()) === 1, 'the list is whole with the filters');
  await page.goto(BASE + '/suppliers/' + sysco + '/guide', { waitUntil: 'load' });
  ok((await page.locator('#guide-list input[name$="[seq]"]').count()) >= 2, 'the guide shows its seq inputs');
  await page.goto(BASE + '/ingredients/new', { waitUntil: 'load' });
  ok((await page.locator('#ingredient-form-field-recipe_unit option').count()) > 3, 'the form lists every unit when scripts are off');
  await page.fill('#ingredient-form-field-name', 'SMOKE Browser Salt');
  await page.evaluate(() => { const i = document.querySelector('input[name="category"]'); if (i) { i.value = document.querySelector('input[name="category"]').value || ''; } });
  const catInput = page.locator('input[name="category"]');
  await catInput.evaluate((el) => { el.value = '5'; });
  await page.locator('#ingredient-form-field-measure_class-weight').check({ force: true });
  await page.mouse.wheel(0, 4000); await page.waitForTimeout(300);
  await page.locator('#ingredient-form-save-btn').click({ force: true });
  await page.waitForLoadState('load');
  ok(/\/ingredients\/\d+/.test(page.url()) && sql("SELECT count(*) FROM ingredients WHERE name = 'SMOKE Browser Salt'") === '1', 'the ingredient saved with JavaScript off and landed on its page (' + page.url().replace(BASE, '') + ')');
  const salt = sql("SELECT id FROM ingredients WHERE name = 'SMOKE Browser Salt'");
  await page.goto(BASE + '/ingredients/' + salt + '/sites', { waitUntil: 'load' });
  await page.fill('#site-settings-row-101 input[name="par"]', '3');
  await page.locator('#site-settings-row-101 select[name="par_unit"]').selectOption('kg');
  await page.mouse.wheel(0, 2000); await page.waitForTimeout(300);   // a thumb scrolls the button clear of the fixed bars
  await page.locator('#site-settings-form-101-save-btn').click({ force: true });
  await page.waitForLoadState('load');
  const parSaved = sql("SELECT par_base FROM ingredient_site_settings WHERE ingredient_id = " + salt + " AND scope_id = 101");
  ok(parSaved === '3000.0000', 'a par of 3 kg saved with JavaScript off → 3 000 g (' + parSaved + ', ' + page.url().replace(BASE, '') + ')');
  if (parSaved !== '3000.0000') { console.log((await page.locator('#flash, .alert, .invalid-feedback').allInnerTexts()).join(' | ').slice(0, 300)); }
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
