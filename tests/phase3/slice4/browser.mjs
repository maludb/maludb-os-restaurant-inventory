// Headless Chromium proof of slice 4 (purchasing.md "Proof": 375 × 740 and 1280 × 800): a Manager drafts an order from the guide on a phone, sends it; the supplier's rep
// answers on THEIR phone (three forms stacked, every control ≥ 44 px, no account); the PO view's arithmetic folds under "Why this much"; the invoice line form's picker
// narrows the receipt-line select; the alerts' threshold form; every one of the eleven screens fits both widths; the tables in full at the desk; JavaScript off.
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const P = process.env.P_APP || '9325';
const BASE = 'http://127.0.0.1:' + P;
const SHOTS = process.env.SHOTS || '/tmp/ri-shots/slice4';
const KEY = process.env.ACTION_TOKEN_KEY;
fs.mkdirSync(SHOTS, { recursive: true });
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
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ' + s + ' select')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.height < 44; }).map((a) => (a.id || a.name || a.tagName) + ':' + Math.round(a.getBoundingClientRect().height)), scope);
const visible = (page, sel) => page.locator(sel).first().isVisible();

const sysco = sql("SELECT id FROM suppliers WHERE name = 'SMOKE Sysco'");
const siFlour = sql("SELECT id FROM supplier_items WHERE supplier_sku = 'SY-FL50'"), siEgg = sql("SELECT id FROM supplier_items WHERE supplier_sku = 'SY-EGG15'"), siChk = sql("SELECT id FROM supplier_items WHERE supplier_sku = 'SY-CHK'");
const dairy = sql("SELECT id FROM ingredient_categories WHERE key = 'dairy'");
const recv = sql('SELECT goods_receipt_id FROM goods_receipt_lines l JOIN goods_receipts g ON g.id = l.goods_receipt_id WHERE g.status = \'posted\' AND g.supplier_id = ' + sysco + ' GROUP BY 1 HAVING count(DISTINCT l.ingredient_id) >= 2 ORDER BY 1 DESC LIMIT 1');
ok([sysco, siFlour, siEgg, siChk, dairy, recv].every((x) => /^\d+$/.test(x)), 'the PHP proofs left a supplier, its items and a two-line receipt for the browser');

const browser = await chromium.launch();
async function session(signOn, viewport, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: 2, isMobile: viewport.width < 600, hasTouch: viewport.width < 600, ...opts });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/503 \(Service Unavailable\)|404 \(Not Found\)|422 \(Unprocessable|403 \(Forbidden\)|409 \(Conflict\)/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('dialog', (d) => d.accept());
  if (signOn) { await page.goto(signOn, { waitUntil: 'networkidle' }); }
  return { ctx, page, errors };
}
async function pick(page, openId, rowId, search) {
  await page.click('#' + openId);
  await page.waitForSelector('#record-picker.show', { timeout: 5000 });
  await page.fill('#record-picker-search', search);
  await page.waitForTimeout(700);
  await page.click('#record-picker-row-' + rowId);
  await page.waitForTimeout(900);
}
let po = null, doorUrl = null, dana2 = null;

// ---- 375 × 740: Marco drafts and sends on a phone ----
{
  const { ctx, page, errors } = await session(mint(27, 101), { width: 375, height: 740 });
  console.log('375 x 740 — Marco drafts an order from the guide');
  await page.goto(BASE + '/purchasing/new', { waitUntil: 'networkidle' });
  await pick(page, 'po-form-field-supplier-open', sysco, 'Sysco');
  await page.fill('#po-form-field-notes', 'Back door, please.');
  await page.click('#po-form-submit');
  await page.waitForURL(/\/purchasing\/\d+\/edit/, { timeout: 15000 }).catch(() => {});
  await page.waitForLoadState('networkidle');
  po = sql("SELECT max(id) FROM purchase_orders WHERE scope_id = 101 AND status = 'draft'");
  ok(page.url().includes('/purchasing/' + po + '/edit'), 'Create lands on the draft\'s edit page');
  ok(await page.inputValue('#po-form-field-expected_on') !== '', 'the expected date is already the supplier\'s next delivery: ' + await page.inputValue('#po-form-field-expected_on'));
  ok(await visible(page, '#po-guide-row-' + siFlour) && await visible(page, '#po-guide-row-' + siEgg), 'the order guide lists its rows to tick');
  await page.check('#po-guide-check-' + siFlour); await page.fill('#po-guide-qty-' + siFlour, '3');
  await page.check('#po-guide-check-' + siEgg); await page.fill('#po-guide-qty-' + siEgg, '1');
  await page.click('#po-guide-submit');
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(700);
  ok(sql('SELECT count(*) FROM purchase_order_lines WHERE purchase_order_id = ' + po) === '2', 'Add lines: the two ticked rows became lines');
  ok(await page.locator('[id^=po-line-card-]').first().isVisible() && !(await visible(page, '#po-lines-table')), 'the lines show as cards on the phone');
  await pick(page, 'po-line-form-field-supplier_item-open', siChk, 'SY-CHK');
  const units = await page.locator('#po-line-form-field-unit option').allInnerTexts();
  const sel = await page.locator('#po-line-form-field-unit').inputValue();
  ok(sel === 'lb' && units.length > 1, 'choosing the chicken item refills the unit select and picks its purchase unit (' + sel + ')');
  const cost = await page.inputValue('#po-line-form-field-unit_cost');
  ok(/^3\.1/.test(cost), 'the cost field takes the item\'s price (' + cost + ')');
  await page.fill('#po-line-form-field-qty', '5');
  const small = await smallControls(page, '#po-line-form');
  ok(small.length === 0, 'every control of the line form is at least 44 px' + (small.length ? ' (' + small.join(', ') + ')' : ''));
  await page.click('#po-line-form-submit');
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(700);
  ok(sql('SELECT count(*) FROM purchase_order_lines WHERE purchase_order_id = ' + po) === '3', 'the chicken line added from the phone');
  let o = await overflow(page);
  ok(o.sw <= o.iw, `no sideways scroll on the edit page (${o.sw} ≤ ${o.iw})`);
  await page.evaluate(() => window.scrollTo(0, 0)); await page.screenshot({ path: SHOTS + '/po-edit-375.png' });
  await page.goto(BASE + '/purchasing/' + po, { waitUntil: 'networkidle' });
  ok(await visible(page, '#po-send-btn') && await visible(page, '#po-place-btn'), 'the draft offers Send by email and Mark placed');
  const sb = await page.locator('#po-send-btn').evaluate((b) => { const r = b.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height), full: r.width / b.parentElement.getBoundingClientRect().width }; });
  ok(sb.h >= 44 && sb.full >= 0.98, 'Send is a full-width button ≥ 44 px (' + sb.w + ' × ' + sb.h + ')');
  await page.click('#po-send-btn');
  await page.waitForURL(/notice=sent/, { timeout: 15000 }).catch(() => {});
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(500);
  ok(sql('SELECT status FROM purchase_orders WHERE id = ' + po) === 'sent', 'Send (with its confirm) sent it');
  const link = await page.inputValue('#po-link-once-url').catch(() => '');
  doorUrl = link.replace(/^https?:\/\/[^/]+/, BASE);
  ok(/\/s\/[a-f0-9]{48}$/.test(doorUrl), 'the link is on the page once, to copy');
  o = await overflow(page);
  ok(o.sw <= o.iw, `no sideways scroll on the order (${o.sw} ≤ ${o.iw})`);
  await page.evaluate(() => window.scrollTo(0, 0)); await page.screenshot({ path: SHOTS + '/po-view-375.png' });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- 375 × 740: the supplier's rep, with no account ----
{
  const { ctx, page, errors } = await session(null, { width: 375, height: 740 });
  console.log('375 x 740 — the supplier\'s door');
  await page.goto(doorUrl, { waitUntil: 'networkidle' });
  ok((await page.title()).includes('PO-') && await visible(page, '#door-number'), 'the door opens with no sign-in: the PO number');
  const lines = await page.locator('[id^=door-line-]:not([id^=door-line-qty]):not([id^=door-line-note])').count();
  ok(lines === 3, 'three lines as cards (' + lines + ')');
  const boxes = {};
  for (const id of ['door-ack-form', 'door-date-form']) { boxes[id] = await page.locator('#' + id).boundingBox(); }
  ok(boxes['door-ack-form'] && boxes['door-date-form'] && boxes['door-date-form'].y > boxes['door-ack-form'].y + boxes['door-ack-form'].height - 1, 'the forms are stacked, one column');
  const small = await smallControls(page, '#door-content');
  ok(small.length === 0, 'every control on the door is at least 44 px' + (small.length ? ' (' + small.join(', ') + ')' : ''));
  const bb = await page.locator('#door-ack-submit').evaluate((b) => { const r = b.getBoundingClientRect(); return { w: Math.round(r.width), full: r.width / b.parentElement.getBoundingClientRect().width }; });
  ok(bb.full >= 0.98 && bb.w >= 260, 'Acknowledge is full width (' + bb.w + ' px of the card)');
  let o = await overflow(page);
  ok(o.sw <= o.iw, `no sideways scroll (${o.sw} ≤ ${o.iw})`);
  ok(!(await page.content()).includes('$'), 'no dollar sign anywhere on the page');
  await page.evaluate(() => window.scrollTo(0, 0)); await page.screenshot({ path: SHOTS + '/door-375.png' });
  await page.fill('#door-ack-ref', 'BR-1');
  const day = sql("SELECT to_char((now() AT TIME ZONE 'America/New_York')::date + 6, 'YYYY-MM-DD')");
  await page.fill('#door-ack-date', day);
  await page.click('#door-ack-submit');
  await page.waitForLoadState('networkidle');
  ok(sql('SELECT status || \':\' || supplier_order_ref || \':\' || expected_on FROM purchase_orders WHERE id = ' + po) === 'acknowledged:BR-1:' + day && await visible(page, '#door-notice'), 'Acknowledge from the phone: acknowledged, their reference and date kept, a thank-you shown');
  const eggLine = sql('SELECT id FROM purchase_order_lines WHERE purchase_order_id = ' + po + ' AND line_no = 2');
  await page.click('#door-decline-' + eggLine + ' summary');
  ok(await visible(page, '#door-decline-reason-' + eggLine), 'a line\'s decline opens under it (no JavaScript needed)');
  await page.fill('#door-decline-reason-' + eggLine, 'out of stock');
  await page.click('#door-decline-submit-' + eggLine);
  await page.waitForLoadState('networkidle');
  ok(sql('SELECT status FROM purchase_order_lines WHERE id = ' + eggLine) === 'declined' && (await page.content()).includes('out of stock'), 'Decline the eggs: the line declined with their reason, shown back');
  const day2 = sql("SELECT to_char((now() AT TIME ZONE 'America/New_York')::date + 8, 'YYYY-MM-DD')");
  await page.fill('#door-date-input', day2);
  await page.click('#door-date-submit');
  await page.waitForLoadState('networkidle');
  ok(sql('SELECT expected_on FROM purchase_orders WHERE id = ' + po) === day2, 'Set the date: the delivery moved');
  ok(sql("SELECT count(*) FROM notifications WHERE kind IN ('po_ack', 'po_decline', 'po_date') AND record_id = " + po) === '3', 'the drafter (who also sent it) was told of each answer once: three notifications');
  o = await overflow(page);
  ok(o.sw <= o.iw, `no sideways scroll after answering (${o.sw} ≤ ${o.iw})`);
  ok(errors.length === 0, 'no console errors at the door' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- 375 × 740: Dana reads a suggested order — the arithmetic folds under "Why" ----
{
  const { ctx, page, errors } = await session(mint(30, 101), { width: 375, height: 740 });
  console.log('375 x 740 — the arithmetic row');
  const line = sql('SELECT id FROM purchase_order_lines WHERE purchase_order_id = ' + po + ' AND line_no = 1');
  sql("UPDATE purchase_orders SET origin = 'suggested' WHERE id = " + po);
  sql("UPDATE purchase_order_lines SET need_base = 90718.47, have_base = 22679.6185, on_order_base = 0, cap_base = 136077.7, raw_base = 72574.77, rounding_note = 'rounded up to whole bags (3)' WHERE id = " + line);
  await page.goto(BASE + '/purchasing/' + po, { waitUntil: 'networkidle' });
  ok(await visible(page, '#po-line-why-' + line + ' summary') && !(await visible(page, '#po-line-math-card-' + line)), 'a suggested line shows "Why this much" and the arithmetic is folded');
  await page.click('#po-line-why-' + line + ' summary');
  await page.waitForTimeout(200);
  const txt = await page.locator('#po-line-math-card-' + line).innerText();
  ok(await visible(page, '#po-line-math-card-' + line) && /Need/.test(txt) && /rounded up to whole bags/.test(txt), 'opened, it shows need · have · on order · cap and the rounding note');
  const o = await overflow(page);
  ok(o.sw <= o.iw, `no sideways scroll (${o.sw} ≤ ${o.iw})`);
  await page.evaluate(() => window.scrollTo(0, 0)); await page.screenshot({ path: SHOTS + '/po-math-375.png' });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- 1280 × 800: Marco's desk — the invoice's line form, the alerts' threshold, every table in full ----
let inv = null;
{
  const { ctx, page, errors } = await session(mint(27, 101), { width: 1280, height: 800 });
  console.log('1280 x 800 — the invoice and the alerts');
  await page.goto(BASE + '/invoices/new?receipt=' + recv, { waitUntil: 'networkidle' });
  await page.fill('#invoice-form-field-invoice_no', 'BR-INV-1');
  await page.click('#invoice-form-submit');
  await page.waitForURL(/\/invoices\/\d+\/edit/, { timeout: 15000 }).catch(() => {});
  await page.waitForLoadState('networkidle');
  inv = sql("SELECT max(id) FROM supplier_invoices WHERE invoice_no = 'BR-INV-1'");
  ok(page.url().includes('/invoices/' + inv + '/edit') && (await page.locator('[id^=invoice-line-row-]').count()) >= 2, 'Create from a receipt lands on the edit page with its lines prefilled');
  const all = await page.locator('#invoice-line-form-field-receipt_line option:not([disabled])').count();
  await pick(page, 'invoice-line-form-field-supplier_item-open', siEgg, 'EGG');
  await page.waitForTimeout(600);
  const eggOpts = await page.locator('#invoice-line-form-field-receipt_line option:not([disabled])').allInnerTexts();
  ok(all >= 3 && eggOpts.length === 2 && /Eggs/.test(eggOpts[1]), 'choosing the egg item narrows the receipt-line select to the eggs\' line (' + all + ' → ' + eggOpts.length + ')');
  ok((await page.locator('#invoice-line-form-field-unit').inputValue()) === 'case' && /^54/.test(await page.inputValue('#invoice-line-form-field-unit_price')), 'the unit and the price follow the item (case, ' + (await page.inputValue('#invoice-line-form-field-unit_price')) + ')');
  await pick(page, 'invoice-line-form-field-supplier_item-open', siFlour, 'FL50');
  await page.waitForTimeout(600);
  const flOpts = await page.locator('#invoice-line-form-field-receipt_line option:not([disabled])').allInnerTexts();
  ok(flOpts.length === 2 && /Flour/.test(flOpts[1]), 'then the flour item narrows it to the flour line');
  await page.evaluate(() => window.scrollTo(0, 0)); await page.screenshot({ path: SHOTS + '/invoice-edit-1280.png' });
  await page.goto(BASE + '/invoices/' + inv, { waitUntil: 'networkidle' });
  await page.click('#invoice-match-btn');
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(500);
  ok(['matched', 'disputed'].includes(sql('SELECT status FROM supplier_invoices WHERE id = ' + inv)) && await visible(page, '[id^=invoice-line-match-]'), 'Match from the page: the status and a chip per line');
  await page.evaluate(() => window.scrollTo(0, 0)); await page.screenshot({ path: SHOTS + '/invoice-view-1280.png' });
  await page.goto(BASE + '/purchasing/alerts', { waitUntil: 'networkidle' });
  await page.fill('#alert-threshold-pct', '12');
  await page.click('#alert-threshold-submit');
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(500);
  ok(sql('SELECT price_alert_pct FROM ri_settings WHERE id = 1') === '12.00', 'the default threshold saved from the form');
  await pick(page, 'alert-category-field-open', dairy, 'Dairy');
  await page.fill('#alert-category-pct', '6');
  await page.click('#alert-category-submit');
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(500);
  ok(sql("SELECT price_alert_pct FROM ingredient_categories WHERE key = 'dairy'") === '6.00', 'a category\'s own threshold saved through the picker');
  sql('UPDATE ri_settings SET price_alert_pct = 10 WHERE id = 1');
  sql("UPDATE ingredient_categories SET price_alert_pct = NULL WHERE key = 'dairy'");
  console.log('1280 x 800 — the tables in full');
  for (const [path, table] of [['/purchasing/', '#po-table'], ['/purchasing/' + po, '#po-lines-table'], ['/purchasing/' + po + '?tab=events', '#po-events-table'], ['/purchasing/due?on=' + sql("SELECT to_char(current_date + 30, 'YYYY-MM-DD')"), '#due-table'], ['/invoices/', '#invoices-table'], ['/invoices/' + inv, '#invoice-lines-table'], ['/purchasing/alerts', '#alerts-table']]) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const t = await page.locator(table).evaluate((x) => { const w = x.parentElement; const r = x.getBoundingClientRect(); return { tw: Math.round(r.width), ww: w.clientWidth, sw: w.scrollWidth, shown: r.width > 0 && getComputedStyle(x).display !== 'none' }; }).catch(() => null);
    ok(t && t.shown && t.sw <= t.ww + 1, `${path.split('?')[0]}: ${table} in full (${t ? t.tw + ' px in ' + t.ww : 'missing'})`);
  }
  await page.goto(BASE + '/purchasing/' + po, { waitUntil: 'networkidle' });
  ok(await page.locator('#po-lines-table').isVisible() && !(await page.locator('[id^=po-line-card-]').first().isVisible()), 'at the desk the lines are a table, the cards hidden');
  await page.evaluate(() => window.scrollTo(0, 0)); await page.screenshot({ path: SHOTS + '/po-view-1280.png' });
  ok(errors.length === 0, 'no console errors at the desk' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  await ctx.close();
}

// ---- every screen fits both widths ----
sql("INSERT INTO supplier_invoices (scope_id, supplier_id, invoice_no, invoice_date, created_by) VALUES (101, " + sysco + ", 'BR-INV-2', current_date, 27)");
const inv2 = sql("SELECT max(id) FROM supplier_invoices WHERE invoice_no = 'BR-INV-2'");
for (const [name, vp] of [['375', { width: 375, height: 740 }], ['1280', { width: 1280, height: 800 }]]) {
  const { ctx, page } = await session(mint(27, 101), vp);
  const draft = sql("SELECT id FROM purchase_orders WHERE status = 'draft' AND scope_id = 101 ORDER BY id DESC LIMIT 1");
  const door = doorUrl.replace(BASE, '');
  const screens = ['/purchasing/', '/purchasing/new', '/purchasing/' + po, '/purchasing/' + draft + '/edit', '/purchasing/due', '/invoices/', '/invoices/new', '/invoices/' + inv, '/invoices/' + inv2 + '/edit', '/purchasing/alerts', door];
  const bad = [];
  for (const s of screens) {
    const r = await page.goto(BASE + s, { waitUntil: 'networkidle' });
    const o = await overflow(page);
    if (!r || r.status() !== 200 || o.sw > o.iw) { bad.push(s.replace(/[a-f0-9]{48}/, '<token>') + ' ' + (r ? r.status() : '?') + ' ' + o.sw + '/' + o.iw); }
  }
  console.log(name + ' — all eleven screens');
  ok(bad.length === 0, `every one of the eleven screens answers 200 with scrollWidth ≤ ${vp.width}` + (bad.length ? ' (' + bad.join('; ') + ')' : ''));
  await ctx.close();
}

// ---- JavaScript off ----
{
  const { ctx, page } = await session(mint(27, 101), { width: 1280, height: 800 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  const draft = sql("SELECT id FROM purchase_orders WHERE status = 'draft' AND scope_id = 101 ORDER BY id DESC LIMIT 1");
  await page.goto(BASE + '/purchasing/' + draft + '/edit');
  ok((await page.locator('#po-form').getAttribute('method')) === 'post' && (await page.locator('#po-line-form').getAttribute('method')) === 'post' && (await page.locator('#po-guide').count()) === 1, 'the order\'s forms post (header, line, guide)');
  await page.goto(BASE + '/invoices/' + inv2 + '/edit');
  ok((await page.locator('#invoice-line-form').getAttribute('method')) === 'post' && (await page.locator('#invoice-form').getAttribute('enctype')) === 'multipart/form-data', 'the invoice\'s forms post, the header as multipart');
  const dctx = await browser.newContext({ viewport: { width: 375, height: 740 }, javaScriptEnabled: false });
  const dp = await dctx.newPage();
  await dp.goto(doorUrl);
  ok((await dp.locator('#door-date-form').getAttribute('method')) === 'post' && (await dp.locator('#door-date-form').getAttribute('action')).includes('/date'), 'the door\'s forms post with no JavaScript');
  await dctx.close();
  await ctx.close();
}
await browser.close();
console.log(failed ? `${failed} FAILED (${passed} passed)` : `all ${passed} passed`);
process.exit(failed ? 1 : 0);
