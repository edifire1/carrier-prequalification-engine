const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium } = (() => { try { return require('playwright'); } catch(_) { return require('playwright-core'); } })();

(async () => {
  const edgePath = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
  const executable = process.env.CHROMIUM_PATH || (fs.existsSync(edgePath) ? edgePath : undefined);
  const browser = await chromium.launch({ ...(executable ? { executablePath: executable } : {}), args: ['--no-sandbox'], headless: true });

  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await context.newPage();

  console.log('--- 1. Testing clean live page at mobile 390px ---');
  await page.goto('https://www.dispatch.businessautomationempire.com/carrier-factoring-prequalification-engine/', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => document.getElementById('prequal-form')?.dataset.prequalInitialized);

  // Verify form loaded and radio count
  const radios = await page.$$('.prequal-radio');
  console.log(`Found ${radios.length} radio inputs.`);
  assert.equal(radios.length, 15);

  // Test selecting each group dynamically
  const groups = ['authority_age', 'monthly_gross', 'equipment', 'factoring_status'];
  for (const name of groups) {
    const ids = await page.locator(`input[name="${name}"]`).evaluateAll(els => els.map(el => el.id));
    for (const id of ids) {
      await page.locator(`label[for="${id}"]`).click();
      assert.equal(await page.locator(`input[name="${name}"]:checked`).count(), 1);
      assert.equal(await page.locator(`#${id}`).isChecked(), true);
    }
  }
  console.log('All 15 radios across all 4 groups selected successfully!');

  console.log('--- 2. Testing URL prefill on live page ---');
  const prefillUrl = 'https://www.dispatch.businessautomationempire.com/carrier-factoring-prequalification-engine/?mc=MC-778899&name=Marcus%20Vance&company=Vance%20Trucking&phone=5559876543&email=marcus@vancefreight.com&equipment=Flatbed%20%2F%20Step%20Deck&factoring_status=locked_contract';
  await page.goto(prefillUrl, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => document.getElementById('prequal-form')?.dataset.prequalInitialized);

  const bannerVisible = await page.locator('#carrier-prefill-banner').evaluate(el => el.style.display === 'flex');
  console.log(`Prefill banner visible: ${bannerVisible}`);
  assert.equal(bannerVisible, true);

  const bannerText = await page.locator('#carrier-prefill-banner').innerText();
  console.log(`Banner text: "${bannerText.replace(/\s+/g, ' ').trim()}"`);

  const mcVal = await page.inputValue('#mc_dot');
  const compVal = await page.inputValue('#company_name');
  const nameVal = await page.inputValue('#owner_name');
  const phoneVal = await page.inputValue('#phone');
  const emailVal = await page.inputValue('#email');
  console.log(`Prefilled fields: mc="${mcVal}", comp="${compVal}", name="${nameVal}", phone="${phoneVal}", email="${emailVal}"`);
  assert.equal(mcVal, 'MC-778899');
  assert.equal(nameVal, 'Marcus Vance');
  assert.equal(compVal, 'Vance Trucking');
  assert.equal(phoneVal, '5559876543');
  assert.equal(emailVal, 'marcus@vancefreight.com');

  // Check if contact fieldset is collapsed
  const isFieldsetHidden = await page.locator('#contact-fields-fieldset').evaluate(el => el.style.display === 'none');
  console.log(`Contact fieldset collapsed: ${isFieldsetHidden}`);
  assert.equal(isFieldsetHidden, true);

  // Check Edit details button
  const toggleBtn = page.locator('#toggle-edit-contact');
  await toggleBtn.click();
  const isFieldsetVisibleNow = await page.locator('#contact-fields-fieldset').evaluate(el => el.style.display !== 'none');
  console.log(`Contact fieldset visible after clicking Edit details: ${isFieldsetVisibleNow}`);
  assert.equal(isFieldsetVisibleNow, true);

  // Check prefilled radios
  const flatbedVal = await page.locator('input[name="equipment"]:checked').inputValue();
  const factorVal = await page.locator('input[name="factoring_status"]:checked').inputValue();
  console.log(`Checked equipment: "${flatbedVal}", Checked factoring: "${factorVal}"`);
  assert.equal(flatbedVal, 'Flatbed / Step Deck');
  assert.equal(factorVal, 'locked_contract');

  await browser.close();
  console.log('--- ALL LIVE PRODUCTION CHECKS PASSED WITH 100% SUCCESS! ---');
})();
