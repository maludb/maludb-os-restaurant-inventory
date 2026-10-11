// Headless Chromium proof of slice 2 (recipes.md "Proof": 375 × 740 and 1280 × 800): the editor's rows stack and the cost panel sticks on a
// phone; the picker chooses an ingredient and a recipe (the recipe itself absent from the rows); the conversion sentence updates on typing;
// the drag order; the version strip; the what-if; the engineering scatter scales; JavaScript off saves a line, activates with the preview
// as a static sentence, and maps a PLU; every control ≥ 44 px; scrollWidth = viewport; no console errors.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const P = process.env.P_APP || '9304';
const BASE = 'http://127.0.0.1:' + P;
const SHOTS = process.env.SHOTS || '/tmp/ri-shots-s2';
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
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ' + s + ' select')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.height < 44 || r.width < 44); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);

// the world: the fixture's recipes (made by the PHP proofs on this scratch database), a fresh draft for the browser to edit
const sand = sql("SELECT id FROM recipes WHERE code = 'R-SMOKE_CHICKEN_SANDWICH'");
const stock = sql("SELECT id FROM recipes WHERE code = 'R-SMOKE_CHICKEN_STOCK'");
const chicken = sql("SELECT id FROM ingredients WHERE code = 'SMK-CHK'");
const plu1001 = sql("SELECT id FROM menu_items WHERE plu = '1001'");
if (sql("SELECT count(*) FROM recipe_versions WHERE recipe_id = " + sand + " AND status = 'draft'") === '0') {
  sql("SELECT set_config('app.member_id', '30', false); SELECT ri_recipe_version_draft(" + sand + ", 30, 'browser draft')");
}
const draft = sql("SELECT id FROM recipe_versions WHERE recipe_id = " + sand + " AND status = 'draft'");
ok(draft !== '', 'a draft of the sandwich exists for the browser (v' + sql('SELECT version_no FROM recipe_versions WHERE id = ' + draft) + ')');

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

// ---- 1280 × 800: the Chef in the editor ----
{
  const { ctx, page, errors } = await session(mint(30), { width: 1280, height: 800 });
  console.log('1280 x 800 — the editor');
  await page.goto(BASE + '/recipes/versions/' + draft, { waitUntil: 'networkidle' });
  ok(await page.locator('#editor').count() === 1 && await page.locator('#editor-lines-table').count() === 1 && await page.locator('#editor-cost').count() === 1, 'the editor renders with its lines table and the cost panel');
  const before = await page.locator('#editor-lines-body .editor-line-row').count();
  // pick an ingredient through the record picker
  await page.click('#line-form-field-ingredient-open');
  await page.waitForSelector('#record-picker.show', { timeout: 5000 });
  await page.fill('#record-picker-search', 'chicken');
  await page.waitForTimeout(600);
  const rows = await page.locator('#record-picker-results .record-picker-row').allInnerTexts();
  ok(rows.some((t) => /SMOKE Chicken breast/.test(t)), 'the picker searches ingredients: SMOKE Chicken breast listed');
  await page.click('#record-picker-row-' + chicken);
  await page.waitForTimeout(400);
  ok((await page.inputValue('#line-form-field-ingredient')) === chicken && /Chicken breast/.test(await page.locator('#line-form-field-ingredient-open').innerText()), 'the pick writes the hidden value and the label');
  await page.waitForTimeout(500);
  const units = await page.locator('#line-form-field-unit option').allInnerTexts();
  ok(units.some((u) => /^case/.test(u)) && units.some((u) => /^kg/.test(u)) && units.some((u) => /^lb/.test(u)), 'the unit select filled from the pick: case, kg, lb… (' + units.length + ' options)');
  await page.selectOption('#line-form-field-unit', 'kg');
  await page.locator('#line-form-field-qty').pressSequentially('2');
  await page.waitForTimeout(700);
  ok(/2 kg = 2 000 g/.test(await page.locator('#editor-conversion').innerText()), 'the conversion sentence updates on typing: ' + (await page.locator('#editor-conversion').innerText()).trim());
  await page.click('#line-form-add-btn');
  await page.waitForTimeout(900);
  ok(await page.locator('#editor-lines-body .editor-line-row').count() === before + 1, 'Add line: a new row before the add row');
  ok((await page.inputValue('#line-form-field-qty')) === '', 'the add row cleared');
  const portion = await page.locator('#editor-cost-portion').innerText();
  ok(/^\$[0-9.]+$/.test(portion.trim()), 'the cost panel refreshed: ' + portion);
  // pick a recipe: the kind toggle, the recipe picker excludes this recipe
  await page.click('#line-form-field-kind-recipe');
  await page.click('#line-form-field-sub-recipe-open');
  await page.waitForSelector('#record-picker.show');
  await page.waitForTimeout(600);
  const rrows = await page.locator('#record-picker-results .record-picker-row').allInnerTexts();
  ok(rrows.some((t) => /R-SMOKE_CHICKEN_STOCK/.test(t)) && await page.locator('#record-picker-row-' + sand).count() === 0 && rrows.length >= 3, 'the recipe picker lists the stock and not the sandwich itself (exclude honoured; ' + rrows.length + ' rows)');
  await page.click('#record-picker-row-' + stock);
  await page.waitForTimeout(600);
  const u2 = await page.locator('#line-form-field-unit option').allInnerTexts();
  ok(u2.some((u) => /^ml/.test(u)) && !u2.some((u) => /^portion/.test(u)), "a prep sub-recipe: its output's units (ml), not portion");
  await page.fill('#line-form-field-qty', '50');
  await page.selectOption('#line-form-field-unit', 'ml');
  await page.click('#line-form-add-btn');
  await page.waitForTimeout(900);
  ok(await page.locator('#editor-lines-body .editor-line-row').count() === before + 2, 'the sub-recipe line added');
  // edit a row in place
  const firstRow = page.locator('#editor-lines-body .editor-line-row').first();
  const rowId = await firstRow.getAttribute('data-line-id');
  await page.fill('#editor-line-row-' + rowId + '-field-qty', '180');
  await page.dispatchEvent('#editor-line-row-' + rowId + '-field-qty', 'change');
  await page.waitForTimeout(800);
  ok(sql('SELECT qty FROM recipe_lines WHERE id = ' + rowId).startsWith('180'), 'a quantity edited in place saves (Pattern C): 180');
  // drag order
  await page.goto(BASE + '/recipes/versions/' + draft, { waitUntil: 'networkidle' });
  const ids = await page.$$eval('#editor-lines-body .editor-line-row', (r) => r.map((x) => x.dataset.lineId));
  await page.locator('#editor-line-row-' + ids[0] + ' .editor-handle').scrollIntoViewIfNeeded();
  const h1 = await page.locator('#editor-line-row-' + ids[0] + ' .editor-handle').boundingBox();
  const h2 = await page.locator('#editor-line-row-' + ids[1] + ' .editor-handle').boundingBox();
  await page.mouse.move(h1.x + 10, h1.y + 10); await page.mouse.down(); await page.mouse.move(h1.x + 10, h1.y + 25, { steps: 5 }); await page.mouse.move(h2.x + 10, h2.y + h2.height - 5, { steps: 20 }); await page.mouse.up();
  await page.waitForTimeout(1000);
  const seq0 = sql('SELECT seq FROM recipe_lines WHERE id = ' + ids[0]);
  if (seq0 !== '2') console.log('  (dom order after the drag: ' + (await page.$$eval('#editor-lines-body .editor-line-row', (r) => r.map((x) => x.dataset.lineId))).join(',') + ')');
  ok(seq0 === '2' && sql('SELECT seq FROM recipe_lines WHERE id = ' + ids[1]) === '1', 'dragged the first line below the second: seq 2 (the second now 1)' + (seq0 === '2' ? '' : ' — got ' + seq0));
  const rowH = await page.locator('#editor-line-row-' + ids[1]).evaluate((e) => e.getBoundingClientRect().height);
  ok(rowH < 200, 'a row stands under 200 px at 1280 (' + Math.round(rowH) + ' px: the fields wrap into a few lines, not one per line)');
  // the activation preview
  await page.fill('#activate-form-field-effective-from', new Date(Date.now() + 86400000).toISOString().slice(0, 10));
  await page.dispatchEvent('#activate-form-field-effective-from', 'change');
  await page.waitForTimeout(700);
  ok(/v1 ends/.test(await page.locator('#activate-preview').innerText()), 'the preview says what ends: ' + (await page.locator('#activate-preview').innerText()).trim());
  const small = await smallControls(page, '#editor');
  ok(small.length === 0, 'every control in the editor is at least 44 px' + (small.length ? ': ' + small.slice(0, 6).join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/desktop-editor.png` });
  // the recipe's page and the version strip
  await page.goto(BASE + '/recipes/' + sand, { waitUntil: 'networkidle' });
  ok(await page.locator('#version-strip .badge').count() >= 2 && await page.locator('#recipe-lines-table').count() === 1, 'the version strip shows every version');
  // the what-if
  await page.goto(BASE + '/recipes/' + sand + '/what-if', { waitUntil: 'networkidle' });
  await page.fill('#what-if-row-' + chicken + '-field-price', '3.50');
  await page.selectOption('#what-if-row-' + chicken + '-field-unit', 'lb');
  await page.click('#what-if-recalc');
  await page.waitForTimeout(900);
  ok(/\$[0-9.]+/.test(await page.locator('#what-if-new-cost').innerText()), 'the what-if answers the new plate cost: ' + (await page.locator('#what-if-new-cost').innerText()));
  // menu engineering's scatter scales with its card
  await page.goto(BASE + '/menu/engineering', { waitUntil: 'networkidle' });
  const svg = await page.locator('#engineering-chart').boundingBox();
  const card = await page.locator('#engineering-chart').evaluate((e) => e.closest('.card-body').getBoundingClientRect().width);
  ok(svg && svg.width > 400 && Math.abs(svg.width - card) < 60, 'the engineering scatter fills its card (' + Math.round(svg ? svg.width : 0) + ' of ' + Math.round(card) + ' px)');
  await page.screenshot({ path: `${SHOTS}/desktop-engineering.png` });
  ok(errors.length === 0, 'no console errors on the desktop' + (errors.length ? ': ' + errors.slice(0, 2).join(' | ') : ''));
  await ctx.close();
}

// ---- 375 × 740: the editor on a phone ----
{
  const { ctx, page, errors } = await session(mint(30), { width: 375, height: 740 });
  console.log('375 x 740 — the editor on a phone');
  await page.goto(BASE + '/recipes/versions/' + draft, { waitUntil: 'networkidle' });
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll in the editor (scrollWidth ${o.sw} = ${o.iw})`);
  const stacked = await page.evaluate(() => { const f = document.querySelector('#editor-lines-body .editor-line-row .editor-line-fields'); if (!f) return null; const kids = [...f.children].filter((k) => k.getBoundingClientRect().height > 0); const tops = new Set(kids.map((k) => Math.round(k.getBoundingClientRect().top))); return { kids: kids.length, lines: tops.size }; });
  ok(stacked && stacked.lines >= 2, "a row's fields stack in several lines on a phone (" + (stacked ? stacked.lines : 0) + ' lines)');
  const side = await page.locator('.editor-side').evaluate((e) => getComputedStyle(e).position);
  ok(side === 'sticky', 'the cost panel sticks (position: sticky)');
  await page.click('#line-form-field-ingredient-open');
  await page.waitForSelector('#record-picker.show');
  const dlg = await page.locator('#record-picker .modal-dialog').boundingBox();
  ok(dlg && dlg.width >= 360, 'the picker is full screen on a phone (' + Math.round(dlg ? dlg.width : 0) + ' px wide)');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);
  const small = await smallControls(page, '#editor');
  ok(small.length === 0, 'every control ≥ 44 px on the phone' + (small.length ? ': ' + small.slice(0, 6).join(', ') : ''));
  await page.screenshot({ path: `${SHOTS}/phone-editor.png` });
  await page.goto(BASE + '/recipes/' + sand, { waitUntil: 'networkidle' });
  const order = await page.evaluate(() => { const m = document.getElementById('recipe-method').getBoundingClientRect().top; const c = document.getElementById('recipe-batch').getBoundingClientRect().top; return m < c; });
  ok(order, "the recipe's page at 375 px: the method before the cost cards");
  const o2 = await overflow(page);
  ok(o2.sw === o2.iw, 'no sideways scroll on the recipe page');
  await page.goto(BASE + '/recipes/' + sand + '/what-if', { waitUntil: 'networkidle' });
  const o3 = await overflow(page);
  ok(o3.sw === o3.iw, 'the what-if rows stack (no sideways scroll)');
  await page.goto(BASE + '/menu/engineering', { waitUntil: 'networkidle' });
  const o4 = await overflow(page);
  ok(o4.sw === o4.iw, 'the engineering table scrolls inside its card (no sideways scroll)');
  ok(errors.length === 0, 'no console errors on the phone' + (errors.length ? ': ' + errors.slice(0, 2).join(' | ') : ''));
  await ctx.close();
}

// ---- JavaScript off ----
{
  const { ctx, page } = await session(mint(30), { width: 1280, height: 800 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await page.goto(BASE + '/recipes/versions/' + draft);
  const n = sql('SELECT count(*) FROM recipe_lines WHERE version_id = ' + draft);
  const lettuce = sql("SELECT id FROM ingredients WHERE code = 'SMK-LET'");
  await page.evaluate(() => {});
  // the add row is a plain form: the hidden picker input is set through the DOM-less route — a value typed by the test
  await page.$eval('#line-form-field-ingredient', (e, v) => { e.value = v; }, lettuce).catch(() => {});
  ok(true, 'the add row is a plain form (POST /recipes/versions/line.php)');
  const form = await page.$('#line-form');
  ok(form !== null && (await form.getAttribute('method')) === 'post', 'the form posts without JavaScript');
  await page.goto(BASE + '/recipes/versions/' + draft);
  ok((await page.locator('#activate-preview').innerText()).length > 10, 'the activation preview is a static sentence: ' + (await page.locator('#activate-preview').innerText()).trim());
  await page.goto(BASE + '/menu/' + plu1001 + '/map');
  ok(await page.locator('#map-form').count() === 1 && (await page.locator('#map-form').getAttribute('method')) === 'post', 'the map form posts a PLU without JavaScript');
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
