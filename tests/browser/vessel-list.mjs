import {chromium} from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';

const work = path.resolve('../.agents/ota-admin-preview-20261014');
const cookie = JSON.parse(await fs.readFile(work + '/session.json', 'utf8'));
const browser = await chromium.launch({channel: 'msedge', headless: true});
const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
await context.addCookies([cookie]);
const page = await context.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.message));
await page.route('https://fonts.bunny.net/**', route => route.abort());

async function checkLayout(width, fixture = '') {
    await page.setViewportSize({width, height: 1000});
    await page.goto('http://127.0.0.1:8767/admin/vessels' + fixture, {waitUntil: 'networkidle'});
    const rows = page.locator('.ota-vessel-row');
    assert.equal(await rows.count(), fixture ? 4 : 3);
    assert.ok((await page.getByRole('heading', {name: 'Main-menu vessels', exact: true}).boundingBox()).height < 50, 'Heading squeezed by actions');
    const geometry = await page.evaluate(() => {
        const shell = document.querySelector('.ota-vessels .ota-table-scroll');
        return {page: document.documentElement.scrollWidth, viewport: window.innerWidth, table: shell.scrollWidth, available: shell.clientWidth};
    });
    assert.ok(geometry.page <= geometry.viewport + 1, 'Page overflow: ' + JSON.stringify(geometry));
    assert.ok(geometry.table <= geometry.available + 1, 'Table overflow: ' + JSON.stringify(geometry));
    for (const row of await rows.all()) {
        const actions = row.locator('.ota-vessel-actions > a');
        const edit = await actions.nth(0).boundingBox(), download = await actions.nth(1).boundingBox();
        assert.ok(Math.abs(edit.y - download.y) < 1 && Math.abs(edit.height - download.height) < 1, 'Actions are misaligned');
        for (const action of [edit, download]) assert.ok(action.x >= 0 && action.x + action.width <= width, 'Action is off-screen');
        assert.match(await actions.nth(0).getAttribute('href'), /vessel-editor\?path=/);
        assert.match(await actions.nth(1).getAttribute('href'), /downloads\/main-menu-vessels\//);
    }
    const toggle = rows.first().getByRole('button', {name: /File details/});
    const details = page.locator('#' + await toggle.getAttribute('aria-controls'));
    await expectHidden(details);
    await toggle.focus();
    await page.keyboard.press('Enter');
    await details.waitFor({state: 'visible'});
    assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
    assert.equal((await details.locator('.ota-vessel-checksum code').textContent()).length, 64);
    assert.equal(await details.locator('dt').count(), 4);
    const fitsExpanded = await page.evaluate(() => {
        const shell = document.querySelector('.ota-vessels .ota-table-scroll');
        return shell.scrollWidth <= shell.clientWidth + 1 && document.documentElement.scrollWidth <= innerWidth + 1;
    });
    assert.ok(fitsExpanded, 'Expanded details overflow');
    await page.screenshot({path: work + `/vessel-list-${width}${fixture ? '-long' : ''}.png`, fullPage: true});
    await page.keyboard.press('Enter');
    await expectHidden(details);
    return {width, fixture: fixture || 'published', ...geometry};
}

async function expectHidden(locator) {
    await locator.waitFor({state: 'hidden'});
    assert.equal(await locator.isVisible(), false);
}

const checks = [];
for (const width of [1440, 1024, 768, 390]) checks.push(await checkLayout(width));
checks.push(await checkLayout(390, '?fixture=long'));
assert.ok(await page.getByText('Mass unavailable', {exact: true}).isVisible());
await page.getByRole('button', {name: 'Manage vessels'}).click();
for (const label of ['Add vessel', 'Replace vessel', 'Reorder vessel', 'Delete vessel']) assert.ok(await page.getByRole('button', {name: label, exact: true}).isVisible());
await page.getByRole('button', {name: 'Reorder vessel', exact: true}).click();
const dialog = page.getByRole('dialog');
const modalWindow = dialog.locator('.fi-modal-window');
await modalWindow.waitFor({state: 'visible'});
await modalWindow.getByLabel(/Draft change set/).waitFor({state: 'visible'});
await modalWindow.getByLabel(/Order position/).waitFor({state: 'visible'});
await page.keyboard.press('Escape');
await modalWindow.waitFor({state: 'hidden'});
await page.evaluate(() => {localStorage.setItem('theme', 'dark'); document.documentElement.classList.add('dark');});
await page.screenshot({path: work + '/vessel-list-dark.png', fullPage: true});
await page.goto('http://127.0.0.1:8767/admin/vessels?fixture=empty', {waitUntil: 'networkidle'});
assert.ok(await page.getByText('No vessels are currently published.').isVisible());
assert.deepEqual(errors, []);
const report = {passed: true, checks, expandableDetails: true, keyboardDisclosure: true, groupedManagementActions: true, emptyState: true, pageErrors: errors};
await fs.writeFile(work + '/vessel-list-validation.json', JSON.stringify(report, null, 2));
console.log(JSON.stringify(report));
await browser.close();
