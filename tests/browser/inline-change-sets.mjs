import {chromium} from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';

const work = path.resolve('../.agents/ota-admin-changesets-20261014');
const browser = await chromium.launch({channel: 'msedge', headless: true, args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader']});
const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
await context.addCookies([JSON.parse(await fs.readFile(work + '/session.json', 'utf8'))]);
const page = await context.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.message));
await page.route('https://fonts.bunny.net/**', route => route.abort());
await page.route('**/admin/vessel-tools/models', route => route.fulfill({json: {parts: {}, resources: {}}}));
const modal = () => page.locator('.fi-modal-window:visible').last();
async function captureModal(filename) {
    await page.waitForFunction(element => getComputedStyle(element).opacity === '1', await modal().elementHandle());
    await page.screenshot({path: work + '/' + filename, fullPage: true});
}
const part = guid => ({PartGuid: {Guid: guid}, partName: 'panel', partState: {localPosition: {x: guid === 'root' ? 0 : 2, y: 0, z: 0}, localRotation: {x: 0, y: 0, z: 0, w: 1}}, PartModulesState: [{ModuleData: [{Name: 'Data_Deployable', DataObject: {UseAnimation: true, CurrentDeployState: {storedValue: 'Retracted'}, toggleExtend: {storedValue: false}, AnimationNormalizedTime: {storedValue: 0}, DeployLimit: {storedValue: 1}, IsExtended: false, IsRetracted: true}}]}]});
const file = {Metadata: {Name: 'Inline station', VehicleName: 'Inline station', Mass: 10, Parts: 2}, Assemblies: [{isMainAssembly: true, Assembly: {AssemblyDefinition: {assemblyName: 'Inline station'}, AssemblyOABConfig: {RootGuid: {Guid: 'root'}, StagingState: {totalStageCount: 0}}, parts: [part('root'), part('second')]}}]};

await page.goto('http://127.0.0.1:8768/admin/vessel-editor', {waitUntil: 'networkidle'});
assert.equal(await page.locator('#ve-change-set option').count(), 1, 'Fixture must start without prepared drafts');
await page.locator('#ve-file').setInputFiles({name: 'inline-station.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(file))});
await page.waitForFunction(() => !document.querySelector('#ve-download').disabled);
await page.locator('#ve-name').fill('Unfinished station edit');
await page.locator('#ve-author').fill('Unsaved author');
await page.locator('#ve-body').selectOption('Pol');
await page.locator('#ve-slug').fill('inline-station');
await page.locator('#ve-part').selectOption('root');
await page.locator('#ve-deployed').check();
await page.waitForFunction(() => !document.querySelector('#ve-download').disabled);
await page.evaluate(() => {window.__inlineCanvas = document.querySelector('#ve-canvas canvas');});
await page.locator('#ve-create-change-set').click();
await modal().getByLabel(/Summary/).fill('Cancelled draft');
await modal().getByRole('button', {name: 'Cancel', exact: true}).click();
await page.locator('.fi-modal-window:visible').waitFor({state: 'hidden'});
assert.equal(await page.locator('#ve-change-set option').count(), 1);

await page.locator('#ve-create-change-set').click();
await modal().getByRole('button', {name: 'Create change set', exact: true}).click();
assert.equal(await modal().getByLabel(/Summary/).evaluate(input => input.validity.valueMissing), true);
assert.equal(await page.locator('#ve-change-set option').count(), 1);
await modal().getByLabel(/Summary/).fill('Station preview updates');
await captureModal('editor-create-change-set.png');
await modal().getByRole('button', {name: 'Create change set', exact: true}).click();
await page.waitForFunction(() => document.querySelector('#ve-change-set').selectedOptions[0]?.textContent === 'Station preview updates');
const editorDraft = await page.locator('#ve-change-set').inputValue();
assert.match(editorDraft, /^[0-9a-f-]{36}$/);
assert.equal(await page.locator('#ve-name').inputValue(), 'Unfinished station edit');
assert.equal(await page.locator('#ve-author').inputValue(), 'Unsaved author');
assert.equal(await page.locator('#ve-body').inputValue(), 'Pol');
assert.equal(await page.locator('#ve-slug').inputValue(), 'inline-station');
assert.equal(await page.locator('#ve-deployed').isChecked(), true);
assert.ok(await page.evaluate(() => window.__inlineCanvas === document.querySelector('#ve-canvas canvas')), 'Preview was recreated');
const staged = page.waitForResponse(response => response.url().endsWith('/vessel-tools/stage'));
await page.locator('#ve-stage').click();
assert.equal((await (await staged).json()).change_set_id, editorDraft);
await page.waitForFunction(() => !document.querySelector('#ve-stage').disabled);
assert.ok(await page.locator('#ve-status').textContent().then(value => value.includes('Vessel staged')));

const nestedDrafts = [];
for (const [url, headerAction, item] of [
    ['/admin/vessels', 'Reorder vessel', 'kraken-b.json'],
    ['/admin/missions', 'Reorder mission', 'fixture-mission.json'],
]) {
    await page.goto('http://127.0.0.1:8768' + url, {waitUntil: 'networkidle'});
    if (url.endsWith('vessels')) await page.getByRole('button', {name: 'Manage vessels'}).click();
    await page.getByRole('button', {name: headerAction, exact: true}).click();
    await modal().getByLabel(/Path/).selectOption(item);
    await modal().getByLabel(/Order position/).fill('2');
    await modal().getByLabel(/Draft change set/).selectOption(editorDraft);
    await modal().getByRole('button', {name: 'Create change set', exact: true}).click();
    await modal().getByLabel(/Summary/).fill('Cancelled nested draft');
    await modal().getByRole('button', {name: 'Cancel', exact: true}).click();
    await modal().getByLabel(/Path/).waitFor({state: 'visible'});
    assert.equal(await modal().getByLabel(/Path/).inputValue(), item);
    assert.equal(await modal().getByLabel(/Order position/).inputValue(), '2');
    assert.equal(await modal().getByLabel(/Draft change set/).inputValue(), editorDraft);
    await modal().getByRole('button', {name: 'Create change set', exact: true}).click();
    await modal().getByLabel(/Summary/).fill(headerAction + ' inline');
    await captureModal((url.endsWith('vessels') ? 'vessel' : 'mission') + '-inline-draft.png');
    await modal().getByRole('button', {name: 'Create change set', exact: true}).click();
    await modal().getByLabel(/Path/).waitFor({state: 'visible'});
    assert.equal(await modal().getByLabel(/Path/).inputValue(), item);
    assert.equal(await modal().getByLabel(/Order position/).inputValue(), '2');
    const draft = await modal().getByLabel(/Draft change set/).inputValue();
    assert.notEqual(draft, editorDraft);
    assert.match(draft, /^[0-9a-f-]{36}$/);
    nestedDrafts.push(draft);
    await modal().getByRole('button', {name: 'Submit', exact: true}).click();
    await page.getByText(/change staged/i, {exact: false}).last().waitFor({state: 'visible'});
}
await page.getByRole('button', {name: 'New change set', exact: true}).click();
await modal().getByLabel(/Summary/).fill('Header draft');
await modal().getByRole('button', {name: 'Create change set', exact: true}).click();
await page.getByText('Change set created', {exact: true}).last().waitFor({state: 'visible'});
assert.ok(page.url().includes('/admin/missions'));
assert.deepEqual(errors, []);
const report = {passed: true, startedWithoutDrafts: true, automaticSelection: true, editorStateAndPreviewPreserved: true, nestedFormsPreserved: true, cancellation: true, validation: true, stagedOperations: 3, editorDraft, nestedDrafts, headerCreationWithoutNavigation: true, pageErrors: errors};
await fs.writeFile(work + '/browser-validation.json', JSON.stringify(report, null, 2));
console.log(JSON.stringify(report));
await browser.close();
