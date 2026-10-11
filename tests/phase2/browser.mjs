// Headless Chromium proof of the shell (docs/build-specs/sso-shell.md, "Proof: browser"):
//   at 375 x 740 and 1280 x 800 (and JavaScript off) — scrollWidth = viewport, the bottom tab bar (Home · Stock · Count/Search · Order/Waste/Search · Me,
//   the third and fourth following the role) on a phone and the sidebar on a desktop, the offcanvas from the menu button, the groups per role
//   (Ann: no Admin, no Count, no Reports), the site switcher in the header (Marco) and the name alone (Ann), the bell count after a notification
//   row is inserted by SQL, the command bar answering the fake kernel's reply, "waits for approval" for a paused action, a navigate followed,
//   "The kernel is not reachable right now." when the fake kernel is stopped, My settings saving prefs (kinds unticked persist, the default
//   area of this site) with the time zone read-only, a token minted and shown once then revoked, the trail's own rows and a record's by
//   ?ingredient=, the manifest installable, every control ≥ 44 px, no console errors, the home whole with JavaScript off.
// Run through tests/phase2/run.sh (it starts the servers and passes the environment). Playwright comes from the kernel's web/node_modules (read only).
import { createRequire } from 'node:module';
const require = createRequire('/var/www/web/node_modules/');
const { chromium } = require('playwright');
import crypto from 'node:crypto';
import fs from 'node:fs';
import { spawn, execFileSync } from 'node:child_process';

const BASE = 'http://127.0.0.1:9301';
const SHOTS = process.env.SHOTS || '/tmp/ri-shots';
const KEY = process.env.ACTION_TOKEN_KEY;
const STATE = process.env.FAKE_KERNEL_STATE;
const STATE_DIR = process.env.RI_DEV_STATE || '/tmp/ri-dev2-state';
const fixture = JSON.parse(fs.readFileSync(new URL('../../bin/dev_directory.json', import.meta.url), 'utf8'));
const mint = (member, scope = null) => {
  const payload = `${member}.${Math.floor(Date.now() / 1000) + 60}.restaurant_inventory.${crypto.randomBytes(16).toString('hex')}`;
  const token = payload + '.' + crypto.createHmac('sha256', KEY).update('sso:' + payload).digest('hex');
  const claims = { ...fixture.claims[String(member)], member_id: member, ...(scope ? { scope } : {}) };
  const text = Buffer.from(JSON.stringify(claims)).toString('base64url');
  return BASE + '/sso?' + new URLSearchParams({ token, claims: text + '.' + crypto.createHmac('sha256', KEY).update(text).digest('hex') });
};
const urls = { get dana() { return mint(30); }, get ann() { return mint(29); }, get marco() { return mint(27, 102); }, get bea() { return mint(28); }, get owner() { return mint(1); } };
const kernelState = (change) => { const s = JSON.parse(fs.readFileSync(STATE, 'utf8')); const n = change(s) || s; fs.writeFileSync(STATE, JSON.stringify(n)); };
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
const shown = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0 && r.right > 0 && r.left < window.innerWidth; }, sel);
const toggleMenu = (page) => page.evaluate(() => document.getElementById('mobile-collapse').click());
const groupsOf = async (page) => (await page.locator('#shell-sidebar .nxl-caption label').allInnerTexts()).map((g) => g.trim().toLowerCase());
const tabsOf = async (page) => (await page.locator('#shell-tabbar .app-tab').allInnerTexts()).map((t) => t.trim()).join(',');
const smallControls = (page, scope) => page.evaluate((s) => [...document.querySelectorAll(s + ' a.btn, ' + s + ' button, ' + s + ' input:not([type=hidden]):not([type=checkbox]):not([type=radio]), ' + s + ' select, ' + s + ' .app-tab')].filter((a) => { const r = a.getBoundingClientRect(); return r.width > 0 && r.height > 0 && (r.height < 44 || r.width < 44); }).map((a) => (a.id || a.tagName) + ' ' + Math.round(a.getBoundingClientRect().width) + 'x' + Math.round(a.getBoundingClientRect().height)), scope);

// ---- phone: the Chef (Dana) at Downtown ----
{
  const { ctx, page, errors } = await session(urls.dana, { width: 375, height: 740 });
  console.log('375 x 740 — the Chef');
  ok(page.url() === BASE + '/', 'signed on and landed on the home (' + page.url() + ')');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll on the home (scrollWidth ${o.sw} = ${o.iw})`);
  ok(await shown(page, '#shell-tabbar') && !(await shown(page, '#shell-sidebar')), 'the bottom tab bar is shown on a phone; the sidebar is not');
  const box = await page.locator('#shell-tabbar').boundingBox();
  ok(Math.abs(box.y + box.height - 740) < 1 && box.height >= 44, `the tab bar sits on the bottom edge (bottom ${box.y + box.height}, height ${box.height})`);
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(bar.y + bar.height <= box.y + 1 && bar.y > 400, `the command bar sits just above the tab bar (bar bottom ${Math.round(bar.y + bar.height)} <= tab top ${Math.round(box.y)})`);
  ok((await tabsOf(page)) === 'Home,Stock,Count,Order,Me', `a Chef's five tabs: ${await tabsOf(page)}`);
  const small = await smallControls(page, '#shell-tabbar');
  ok(small.length === 0, 'every tab is at least 44 x 44 px' + (small.length ? ': ' + small.join(', ') : ''));
  ok(await shown(page, '#home-stockouts') && await shown(page, '#home-suggested') && await shown(page, '#home-count') && await shown(page, '#home-bell'), 'the home regions (stock-outs, suggested orders, the count, Unread) are shown');
  ok((await page.locator('#header-role-badge').innerText()).includes('Chef') && (await page.locator('#header-site-name').innerText()).includes('SMOKE Downtown'), 'the header badge reads Chef at SMOKE Downtown');
  ok((await page.locator('#site-switcher').count()) === 0, 'Dana holds one site: no switcher');
  await page.screenshot({ path: `${SHOTS}/phone-home-chef.png` });
  await page.click('#mobile-collapse');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  await page.waitForTimeout(400);
  ok(await shown(page, '#shell-sidebar') && (await page.locator('#nav-receipt-list').count()) === 1 && (await page.locator('#nav-count-list').count()) === 1 && (await page.locator('#nav-invoice-list').count()) === 0, 'the menu button opens the sidebar: Receive and Counts are listed for a Chef, Invoices not');
  const groups = await groupsOf(page);
  ok(groups.join(',') === 'kitchen,ingredients,recipes,stock,count,purchasing,sales,usage,projection,reports,me', 'a Chef\'s menu groups: ' + groups.join(', '));
  ok((await page.locator('#sidebar-site-row').count()) === 0, 'one site: no site row in the sidebar either');
  await page.screenshot({ path: `${SHOTS}/phone-sidebar.png` });
  await page.evaluate(() => { const l = document.querySelector('.navbar-content'); l.scrollTop = l.scrollHeight; });
  const lastAfter = await page.locator('#shell-sidebar .nxl-item:last-child .nxl-link').boundingBox();
  ok(lastAfter.y + lastAfter.height <= 740 - 56, `the last menu item can be scrolled clear of the bars (bottom ${Math.round(lastAfter.y + lastAfter.height)} <= ${740 - 56})`);
  await toggleMenu(page);
  await page.waitForTimeout(400);
  ok(!(await shown(page, '#shell-sidebar')), 'the menu button closes it again');
  await page.click('#tab-my-settings');
  await page.waitForURL(BASE + '/me/settings');
  await page.waitForSelector('#prefs-form-save-btn');
  ok(page.url() === BASE + '/me/settings', 'the Me tab pushed /me/settings without a page load');
  ok((await page.title()).startsWith('My settings'), 'and set the title (' + (await page.title()) + ')');
  ok(await page.evaluate(() => document.querySelector('#tab-my-settings').classList.contains('active')), 'and highlighted the tab');
  ok(await page.evaluate(() => document.getElementById('page-content').dataset.screen === 'my-settings'), 'and re-stamped #page-content data-screen for the command bar');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on My settings');
  const smallS = await smallControls(page, '#page-content');
  ok(smallS.length === 0, 'every control on My settings is at least 44 px tall' + (smallS.length ? ': ' + smallS.slice(0, 6).join(', ') : ''));
  ok((await page.locator('#settings-timezone-value').innerText()) === 'UTC' && (await page.locator('#prefs-form input[name="timezone"]').count()) === 0, 'the time zone is shown read-only (UTC) — no field for it');
  ok((await page.locator('#settings-role-badge').innerText()).includes('Chef') && (await page.locator('#settings-roles-site-101').count()) === 1, 'who I am here: Chef, the roles per site');
  await page.screenshot({ path: `${SHOTS}/phone-settings.png` });
  await page.click('#tab-count-list');
  await page.waitForURL(BASE + '/counts/');
  await page.waitForSelector('#count-list-coming');
  ok((await page.title()).startsWith('Counts') && (await page.locator('#count-list-coming').innerText()).includes('slice 6'), 'the Count tab opens the placeholder naming slice 6, title "' + (await page.title()) + '"');
  ok((await overflow(page)).sw === 375, 'no sideways scroll on the placeholder');
  await page.goto(BASE + '/me/trail', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on My trail');
  await page.goto(BASE + '/me/notifications', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375, 'no sideways scroll on Notifications');
  await page.goto(BASE + '/me/tokens', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375 && await page.evaluate(() => !!document.querySelector('#tokens-table').closest('.table-responsive')), 'no sideways scroll on Tokens; the table sits inside .table-responsive');
  await page.goto(BASE + '/search?q=limes', { waitUntil: 'networkidle' });
  ok((await overflow(page)).sw === 375 && (await page.locator('#search-empty').count()) === 1, 'no sideways scroll on Search; the empty state');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok(!(await shown(page, '#assistant-send')), 'the Send button is hidden while the bar is idle');
  await page.fill('#assistant-input', 'What do we have of limes?');
  ok(await shown(page, '#assistant-send'), 'and shown once something is typed');
  await page.click('#assistant-send');
  await page.waitForSelector('#assistant-reply-text');
  ok((await page.locator('#assistant-reply-text').innerText()).includes('Hello from the fake expert'), 'the command bar shows the expert\'s reply above the bar');
  await page.screenshot({ path: `${SHOTS}/phone-assistant.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- phone: a Viewer (Ann) and Kitchen (Bea) — the tabs follow the role ----
{
  const { ctx, page, errors } = await session(urls.ann, { width: 375, height: 740 });
  console.log('375 x 740 — a Viewer, then Kitchen');
  ok((await page.locator('#header-role-badge').innerText()).includes('Viewer'), 'the badge reads Viewer');
  ok((await tabsOf(page)) === 'Home,Stock,Search,Me', `a Viewer's tabs: Search once, four tabs (${await tabsOf(page)})`);
  await page.click('#mobile-collapse');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  await page.waitForTimeout(300);
  const groups = await groupsOf(page);
  ok(!groups.includes('admin') && !groups.includes('reports') && !groups.includes('count') && !groups.includes('usage') && groups.includes('ingredients'), 'a Viewer\'s groups hold no Admin, Reports, Count or Usage: ' + groups.join(', '));
  ok((await page.locator('#nav-receipt-list').count()) === 0 && (await page.locator('#nav-waste-list').count()) === 0 && (await page.locator('#nav-recipe-list').count()) === 1, 'no Receive, no Waste; Recipes is there');
  await page.screenshot({ path: `${SHOTS}/phone-sidebar-viewer.png` });
  ok((await page.locator('#home-bell').count()) === 1 && (await page.locator('#home-suggested').count()) === 0 && (await page.locator('#home-admin').count()) === 0 && (await page.locator('#home-purchasing').count()) === 1, 'her home: the bell\'s card and open orders, no suggested orders, nothing for the admin');
  ok(errors.length === 0, 'no console errors');
  await ctx.close();
  const b = await session(urls.bea, { width: 375, height: 740 });
  ok((await b.page.locator('#header-role-badge').innerText()).includes('Kitchen') && (await tabsOf(b.page)) === 'Home,Stock,Count,Waste,Me', `Kitchen's tabs: Count and Waste (${await tabsOf(b.page)})`);
  await b.ctx.close();
}

// ---- phone: Marco at Airport — the switcher ----
{
  const { ctx, page, errors } = await session(urls.marco, { width: 375, height: 740 });
  console.log('375 x 740 — Marco, two sites');
  ok((await page.locator('#header-site-name').innerText()).includes('SMOKE Airport') && (await page.locator('#header-role-badge').innerText()).includes('Kitchen'), 'landed at Airport (claims.scope 102) as Kitchen');
  ok((await page.locator('#site-switcher').count()) === 1, 'the header carries the switcher');
  await page.click('#mobile-collapse');
  await page.waitForSelector('nav.nxl-navigation.mob-navigation-active');
  await page.waitForTimeout(300);
  ok(await shown(page, '#sidebar-site-row'), 'the sidebar carries the site row on a phone (two sites)');
  await toggleMenu(page);
  await page.waitForTimeout(300);
  await page.click('#site-switcher');
  await page.waitForTimeout(300);
  await page.click('#site-switch-101');
  await page.waitForURL(BASE + '/');
  await page.waitForSelector('#header-site-name');
  ok((await page.locator('#header-site-name').innerText()).includes('SMOKE Downtown') && (await page.locator('#header-role-badge').innerText()).includes('Manager'), 'choosing Downtown reloads the home: the site and the Manager badge changed');
  ok((await tabsOf(page)) === 'Home,Stock,Count,Order,Me', `and the tabs follow the role at the site (${await tabsOf(page)})`);
  ok((await overflow(page)).sw === 375, 'no sideways scroll with two sites in the header');
  await page.screenshot({ path: `${SHOTS}/phone-switcher.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- desktop: the two panes, the owner ----
{
  const { ctx, page, errors } = await session(urls.owner, { width: 1280, height: 800 });
  console.log('1280 x 800 — the owner');
  const o = await overflow(page);
  ok(o.sw === o.iw, `no sideways scroll (scrollWidth ${o.sw} = ${o.iw})`);
  ok(!(await shown(page, '#shell-tabbar')), 'the tab bar is hidden on a desktop');
  ok(await shown(page, '#left-sidenav') && await shown(page, '#shell-sidebar') && await shown(page, '#nav-admin-settings'), 'the sidebar is shown, with the Admin group');
  const groups = await groupsOf(page);
  ok(groups.join(',') === 'kitchen,ingredients,recipes,stock,count,purchasing,sales,usage,projection,reports,me,admin', 'the owner\'s groups: ' + groups.join(', '));
  const nav = await page.locator('#left-sidenav').boundingBox();
  const main = await page.locator('#page-content').boundingBox();
  const bar = await page.locator('#assistant-bar').boundingBox();
  ok(Math.round(nav.width) === 280 && main.x >= nav.width - 1, `two panes: the sidebar is 280 px (${Math.round(nav.width)}) and the main pane starts after it (x ${Math.round(main.x)})`);
  ok(bar.x >= nav.width - 1 && bar.y + bar.height >= 780, `the command bar sits at the bottom, right of the sidebar (x ${Math.round(bar.x)}, sidebar ${Math.round(nav.width)})`);
  ok((await page.locator('#header-role-badge').innerText()).includes('Super-admin') && (await page.locator('#site-switcher').count()) === 1, 'the badge reads Super-admin; the switcher is there (two sites)');
  await page.screenshot({ path: `${SHOTS}/desktop-home.png` });
  await page.click('#nav-recipe-list .nxl-link');
  await page.waitForURL(BASE + '/recipes/');
  await page.waitForSelector('#recipe-list-coming');
  ok((await page.title()).startsWith('Recipes'), 'HTMX navigation: /recipes/ pushed, title "' + (await page.title()) + '"');
  ok(await page.evaluate(() => document.querySelector('#nav-recipe-list .nxl-link').classList.contains('active') && !document.querySelector('#nav-home .nxl-link').classList.contains('active')), 'the sidebar highlights the screen, not Home');
  ok((await page.locator('#recipe-list-coming').innerText()).includes('slice 2 builds this screen'), 'the placeholder names slice 2');
  await page.screenshot({ path: `${SHOTS}/desktop-placeholder.png` });
  await page.goBack();
  await page.waitForURL(BASE + '/');
  ok(true, 'the browser back button returns to /');
  await page.goto(BASE + '/me/tokens', { waitUntil: 'networkidle' });
  await page.fill('#token-mint-form-field-label', 'proof');
  await page.click('#token-mint-form-save-btn');
  await page.waitForSelector('#token-minted');
  ok((await page.locator('#token-minted-value').innerText()).startsWith('ri_') && await page.evaluate(() => document.getElementById('token-minted').classList.contains('alert-warning')), 'minting a token shows it once (ri_…) in a warning box');
  await page.screenshot({ path: `${SHOTS}/desktop-tokens.png` });
  await page.goto(BASE + '/me/tokens', { waitUntil: 'networkidle' });
  ok((await page.locator('#token-minted').count()) === 0, 'and never again');
  page.once('dialog', (d) => d.accept());
  await page.click('[id^="token-row-"][id$="-revoke"]');
  await page.waitForSelector('.badge:has-text("revoked")');
  ok((await page.locator('.badge:has-text("revoked")').count()) >= 1, 'revoking it (with the confirm) marks it revoked');
  sql("INSERT INTO storage_areas (scope_id, name, kind, walk_order) VALUES (102, 'SMOKE Browser walk-in', 'walk_in', 9) ON CONFLICT DO NOTHING");
  await page.goto(BASE + '/me/settings', { waitUntil: 'networkidle' });
  await page.uncheck('#prefs-form-field-kinds-watch');
  await page.check('#prefs-form-field-text-enabled');
  await page.check('#prefs-form-field-display-units-imperial');
  await page.selectOption('#prefs-form-field-default-area', { label: 'SMOKE Browser walk-in' });
  await page.click('#prefs-form-save-btn');
  await page.waitForSelector('#notice-banner');
  ok((await page.locator('#notice-banner').innerText()).includes('Saved your settings'), 'saving the settings lands back on them with the notice');
  ok(!(await page.isChecked('#prefs-form-field-kinds-watch')) && (await page.isChecked('#prefs-form-field-text-enabled')) && (await page.isChecked('#prefs-form-field-display-units-imperial')), 'and the unchecked kind stayed unchecked, texts on, imperial');
  ok((await page.locator('#prefs-form-field-default-area option:checked').innerText()).includes('SMOKE Browser walk-in'), 'the default area of this site is kept');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok((await page.locator('#bell').count()) === 1 && (await page.locator('#bell-count').count()) === 0, 'the bell shows no count (nothing unread)');
  sql("INSERT INTO notifications (member_id, scope_id, kind, record_type, record_id, title, body) VALUES (1, 102, 'morning_note', NULL, NULL, 'SMOKE The morning note', 'three headings')");
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  ok((await page.locator('#bell-count').innerText()) === '1' && (await page.locator('#home-bell-list').innerText()).includes('SMOKE The morning note'), 'a notification row inserted by SQL: the bell shows 1 and the home\'s Unread card lists it');
  await page.click('#bell');
  await page.waitForURL(BASE + '/me/notifications');
  await page.waitForSelector('[id^="notification-row-"]');
  await page.click('[id^="notification-row-"][id$="-read"]');
  await page.waitForSelector('#bell-count', { state: 'detached', timeout: 10000 }).catch(() => {});
  ok((await page.locator('#bell-count').count()) === 0, 'marking it read clears the bell (the notificationChanged trigger re-fetches the count)');
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  kernelState((s) => { s.chat = { run_id: 8, status: 'awaiting_approval', finished: true, reply: 'The purchase order is drafted; sending it waits for approval.', actions: [{ tool: 'purchase_order_send', status: 'awaiting_approval', record_id: 5 }], approval_request_id: 91 }; return s; });
  await page.fill('#assistant-input', 'order limes from the produce supplier');
  await page.click('#assistant-send');
  await page.waitForSelector('#assistant-reply-approval');
  ok((await page.locator('#assistant-reply-approval').innerText()).includes('Waits for a person\'s approval') && (await page.locator('#assistant-reply-actions').innerText()).includes('purchase_order_send'), 'a paused action shows "waits for approval"');
  kernelState((s) => { s.chat = { run_id: 9, status: 'succeeded', finished: true, reply: 'Opening the ingredients.', actions: [], navigate: '/ingredients/' }; return s; });
  await page.fill('#assistant-input', 'open the ingredients');
  await page.click('#assistant-send');
  await page.waitForURL(BASE + '/ingredients/');
  await page.waitForSelector('#ingredient-list-coming');
  ok(page.url() === BASE + '/ingredients/', 'a navigate answer from the command bar opened /ingredients/');
  kernelState((s) => { delete s.chat; return s; });
  const kpid = Number(fs.readFileSync(STATE_DIR + '/kernel.pid', 'utf8').trim());
  try { process.kill(kpid, 'SIGTERM'); } catch (e) { /* already gone */ }
  await page.waitForTimeout(500);
  await page.fill('#assistant-input', 'hello?');
  await page.click('#assistant-send');
  await page.waitForSelector('#assistant-reply-error');
  ok((await page.locator('#assistant-reply-error').innerText()).includes('The kernel is not reachable right now.'), 'with the fake kernel stopped the bar says "The kernel is not reachable right now."');
  const child = spawn('php', ['-S', '127.0.0.1:9302', 'tests/fake_kernel.php'], { cwd: new URL('../../', import.meta.url).pathname, detached: true, stdio: 'ignore', env: process.env });
  child.unref();
  fs.writeFileSync(STATE_DIR + '/kernel.pid', String(child.pid));
  await page.waitForTimeout(800);
  await page.fill('#assistant-input', 'hello again');
  await page.click('#assistant-send');
  await page.waitForSelector('#assistant-reply-text');
  ok((await page.locator('#assistant-reply-text').innerText()).includes('Hello from the fake expert'), 'and answers again once the kernel is back');
  sql("INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, scope_id, ingredient_id, after) VALUES (1, 'web', 'ingredient.update', 'ingredient', 9, 102, 9, '{\"name\": \"SMOKE Limes\"}')");
  await page.goto(BASE + '/me/trail', { waitUntil: 'networkidle' });
  const trail = await page.locator('#trail-list').innerText();
  ok(trail.includes('SMOKE Owner made an access token') && trail.includes('SMOKE Owner changed their settings'), 'the trail shows the person\'s own rows in words');
  await page.goto(BASE + '/me/trail?ingredient=9', { waitUntil: 'networkidle' });
  const rec = await page.locator('#trail-list').innerText();
  ok(rec.includes('SMOKE Owner changed the ingredient SMOKE Limes') && (await page.locator('#trail-card .card-title').innerText()).includes("ingredient's history"), 'and a record\'s rows by ?ingredient=');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' / ') : ''));
  await ctx.close();
}

// ---- JavaScript off: the home is whole ----
{
  const { ctx, page } = await session(urls.dana, { width: 375, height: 740 }, { javaScriptEnabled: false });
  console.log('JavaScript off');
  const text = await page.locator('#page-content').innerText();
  ok(['Stock-outs', 'Suggested orders', 'Unread'].every((t) => text.includes(t)), 'the home regions are in the server-rendered page');
  ok(await shown(page, '#shell-tabbar') && (await page.locator('#tab-count-list').getAttribute('href')) === '/counts/' && (await page.locator('#tab-my-settings').getAttribute('href')) === '/me/settings', 'the tabs are plain links');
  ok((await page.locator('#nav-recipe-list a').getAttribute('href')) === '/recipes/', 'the sidebar items are plain links too');
  await ctx.close();
}

// ---- installable ----
{
  const ctx = await browser.newContext({ viewport: { width: 375, height: 740 } });
  const page = await ctx.newPage();
  await page.goto(urls.dana, { waitUntil: 'networkidle' });
  console.log('installable');
  const href = await page.getAttribute('link[rel="manifest"]', 'href');
  const res = await page.request.get(BASE + href);
  const m = await res.json();
  ok(res.status() === 200 && /manifest\+json|application\/json/.test(res.headers()['content-type'] || ''), 'the manifest is served (' + res.headers()['content-type'] + ')');
  ok(m.display === 'standalone' && m.start_url === '/' && m.name === 'Restaurant Inventory', 'standalone, start_url /, named Restaurant Inventory');
  const sizes = [];
  for (const i of m.icons) { const r = await page.request.get(BASE + i.src); sizes.push(r.status() === 200 && i.sizes); }
  ok(sizes.includes('192x192') && sizes.includes('512x512'), 'the 192 and 512 px icons are served');
  const sw = await page.evaluate(async () => (await navigator.serviceWorker?.getRegistrations?.() || []).length);
  ok(sw === 0, 'no service worker registered (none in version 1)');
  const html = await page.content();
  ok(html.includes('name="theme-color"') && html.includes('rel="apple-touch-icon"') && html.includes('name="viewport"'), 'theme-color, apple-touch-icon and viewport are set');
  const cdp = await ctx.newCDPSession(page);
  const inst = await cdp.send('Page.getInstallabilityErrors').catch((e) => ({ installabilityErrors: [{ errorId: 'cdp:' + e.message }] }));
  const errs = (inst.installabilityErrors || []).map((e) => e.errorId);
  ok(!errs.some((e) => /manifest|icon|start-url|display|name/i.test(e)), 'Chromium reports no manifest installability error' + (errs.length ? ' (remaining: ' + errs.join(', ') + ')' : ''));
  await ctx.close();
}

await browser.close();
console.log(failed === 0 ? `all ${passed} passed` : `${failed} FAILED (${passed} passed)`);
process.exit(failed === 0 ? 0 : 1);
