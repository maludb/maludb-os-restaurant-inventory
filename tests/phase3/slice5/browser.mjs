// Headless Chromium proof of slice 5 (pos.md "Proof": 375 × 740 and 1280 × 800): the source form's sub-form switch (and every sub-form
// visible with JavaScript off); the upload's preview through htmx, then Import landing on the day list with its notice; on a phone the
// preview under the form with Import held at the bottom, the day's tiles stacking; scrollWidth ≤ the viewport on every slice screen;
// every control ≥ 44 px on the upload; no console errors.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const P = process.env.P_APP || '9320';
const BASE = 'http://127.0.0.1:' + P;
const SHOTS = process.env.SHOTS || '/tmp/ri-shots-s5';
const KEY = process.env.ACTION_TOKEN_KEY;
const FIX = new URL('../../fixtures/pos/', import.meta.url).pathname;
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
const overflow = (page) => page.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: document.documentElement.clientWidth }));
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ' + s + ' select')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.height < 44 || r.width < 44); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);
const DOWNTOWN = 101;
const toast = sql("SELECT id FROM sources WHERE name = 'SMOKE Toast export'");
const square = sql("SELECT id FROM sources WHERE name = 'SMOKE Square'");
const run = sql("SELECT id FROM depletion_runs WHERE scope_id = 101 AND business_date = '2026-10-05' AND status <> 'void' ORDER BY id DESC LIMIT 1");
ok(toast !== '' && square !== '' && run !== '', 'the PHP proofs left the sources and a run for the browser');

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

// ---- 1280 × 800: the admin's source form and an upload ----
{
  const { ctx, page, errors } = await session(mint(1, DOWNTOWN), { width: 1280, height: 800 });
  console.log('1280 x 800 — the source form and the upload');
  await page.goto(BASE + '/sales/sources/new', { waitUntil: 'networkidle' });
  ok(await page.locator('#source-form-file').isVisible() && !(await page.locator('#source-form-square').isVisible()), 'the source form opens on the file sub-form, Square hidden');
  await page.selectOption('#source-form-field-connector', 'square');
  ok(await page.locator('#source-form-square').isVisible() && !(await page.locator('#source-form-file').isVisible()), 'choosing Square shows its sub-form and hides the file one');
  await page.selectOption('#source-form-field-connector', 'file');
  await page.selectOption('#source-form-field-intake', 'sftp');
  ok(await page.locator('#source-form-field-sftp-host').isVisible() && !(await page.locator('#source-form-field-email-from').isVisible()), 'intake sftp shows the SFTP fields, not the sender');
  await page.selectOption('#source-form-field-intake', 'email');
  ok(await page.locator('#source-form-field-email-from').isVisible() && !(await page.locator('#source-form-field-sftp-host').isVisible()), 'intake email shows the sender');
  await page.goto(BASE + '/sales/upload?source=' + toast, { waitUntil: 'networkidle' });
  await page.setInputFiles('#upload-form-field-file', FIX + 'toast_items.csv');
  await page.click('#upload-preview-btn');
  await page.waitForSelector('#upload-preview-table', { timeout: 8000 });
  ok(await page.locator('#upload-preview-table tbody tr').count() === 8 && await page.locator('#upload-preview-dates').count() === 1, 'Preview through htmx: eight rows and the dates seen, in place');
  ok(await page.locator('#upload-form').count() === 1 && page.url().includes('/sales/upload'), 'the form stays; the page did not change');
  await page.screenshot({ path: SHOTS + '/upload-1280.png', fullPage: true });
  await Promise.all([page.waitForURL(/\/sales\/\?/, { timeout: 10000 }), page.click('#upload-import-btn')]);
  await page.waitForLoadState('networkidle');
  ok(await page.locator('#sales-days-table').count() === 1 && /Imported 2 days/.test(await page.content()), 'Import lands on the day list with its notice');
  for (const path of ['/sales/', '/sales/2026-10-05', '/sales/sources/', '/sales/sources/' + square, '/sales/runs/', '/sales/runs/' + run, '/sales/mappings/', '/sales/mappings/toast_items']) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const o = await overflow(page);
    ok(o.sw <= o.iw, '1280: ' + path + ' scrollWidth ' + o.sw + ' ≤ ' + o.iw);
  }
  ok(errors.length === 0, 'no console errors at 1280' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}

// ---- JavaScript off: every sub-form visible ----
{
  const { ctx, page } = await session(mint(1, DOWNTOWN), { width: 1280, height: 800 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  await page.goto(BASE + '/sales/sources/new', { waitUntil: 'load' });
  ok(await page.locator('#source-form-file').isVisible() && await page.locator('#source-form-square').isVisible() && await page.locator('#source-form-field-sftp-host').isVisible() && await page.locator('#source-form-field-email-from').isVisible(), 'every sub-form and every intake field visible with JavaScript off');
  await ctx.close();
}

// ---- 375 × 740: Bea on a phone ----
{
  const { ctx, page, errors } = await session(mint(28, DOWNTOWN), { width: 375, height: 740 });
  console.log('375 x 740 — the upload and the day on a phone');
  await page.goto(BASE + '/sales/upload?source=' + toast, { waitUntil: 'networkidle' });
  await page.setInputFiles('#upload-form-field-file', FIX + 'toast_items.csv');
  await page.click('#upload-preview-btn');
  await page.waitForSelector('#upload-preview-table', { timeout: 8000 });
  const formBox = await page.locator('#upload-form').boundingBox();
  const prevBox = await page.locator('#upload-preview-card').boundingBox();
  ok(prevBox && formBox && prevBox.y >= formBox.y + formBox.height - 1, 'the preview under the form');
  const pos = await page.locator('#upload-actions').evaluate((el) => getComputedStyle(el).position);
  await page.evaluate(() => window.scrollTo(0, 0));
  const ab = await page.locator('#upload-actions').boundingBox();
  ok(['sticky', 'fixed'].includes(pos) && ab && ab.y + ab.height <= 740, 'Import held at the bottom (' + pos + '), on screen');
  const small = await smallControls(page, '#upload-form');
  ok(small.length === 0, 'every control of the upload ≥ 44 px' + (small.length ? ': ' + small.join(', ') : ''));
  await page.screenshot({ path: SHOTS + '/upload-375.png', fullPage: true });
  await page.goto(BASE + '/sales/2026-10-05', { waitUntil: 'networkidle' });
  const tiles = await page.locator('#day-tiles > div').evaluateAll((els) => els.map((e) => { const r = e.getBoundingClientRect(); return [Math.round(r.x), Math.round(r.y), Math.round(r.width)]; }));
  ok(tiles.length >= 3 && new Set(tiles.map((t) => t[1])).size >= 2, "the day's tiles stack on a phone (" + tiles.map((t) => t.join('/')).join(' ') + ')');
  await page.screenshot({ path: SHOTS + '/day-375.png', fullPage: true });
  for (const path of ['/sales/', '/sales/2026-10-05', '/sales/upload', '/sales/sources/', '/sales/sources/' + square, '/sales/sources/' + square + '/pulls', '/sales/runs/', '/sales/runs/' + run, '/sales/mappings/generic']) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const o = await overflow(page);
    ok(o.sw <= o.iw, '375: ' + path + ' scrollWidth ' + o.sw + ' ≤ ' + o.iw);
  }
  ok(errors.length === 0, 'no console errors at 375' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
