// npm install --no-save playwright-core && node tests/prequal.test.cjs
// All external traffic is intercepted. This suite never contacts n8n or sends real emails.
const { chromium } = (() => { try { return require('playwright'); } catch(_) { return require('playwright-core'); } })();
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
  const edgePath = 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
  const executable = process.env.CHROMIUM_PATH || (fs.existsSync(edgePath) ? edgePath : (fs.existsSync('/usr/local/bin/chromium') ? '/usr/local/bin/chromium' : undefined));
  const browser = await chromium.launch({ ...(executable ? { executablePath: executable } : {}), args: ['--no-sandbox'] });
  let checks = 0;

  try {
    for (const entry of ['index.html', 'wordpress-embed.html']) {
      for (const width of [390, 1280]) {
        const page = await browser.newPage({ viewport: { width, height: 900 } });
        let body = null;
        let reply = { success: true, tier: 'SUMMAR' };
        let mode = 'ok';
        let posts = 0;
        let pingPosts = 0;
        const errors = [];

        page.on('pageerror', e => errors.push(e.message));

        const rawSource = fs.readFileSync(path.join(__dirname, '../frontend', entry), 'utf8');
        const source = rawSource.replace(/<!-- \/?wp:html -->/g, '');

        await page.route('**/*', async route => {
          const req = route.request();
          const url = req.url();
          if (url.includes('/prequal-ping')) {
            pingPosts++;
            return route.fulfill({
              status: 200,
              contentType: 'application/json',
              body: JSON.stringify({ success: true, submission_id: 999, prequal_token: 'test_token_abc123' })
            });
          }
          if (url.includes('/prequal-submit') || url.includes('/webhook/trucker-factoring-prequal')) {
            posts++;
            try { body = req.postDataJSON(); } catch(_) { body = null; }
            if (mode === 'network') return route.abort();
            if (mode === 'delayed') await new Promise(r => setTimeout(r, 150));
            return route.fulfill({
              status: mode === 'http' ? 500 : 200,
              contentType: 'application/json',
              body: mode === 'json' ? 'not json' : JSON.stringify(reply)
            });
          }
          if (url.startsWith('https://dispatch.test/')) {
            return route.fulfill({
              status: 200,
              contentType: 'text/html',
              body: source
            });
          }
          return route.abort();
        });

        const load = async (queryString = '') => {
          await page.goto('https://dispatch.test/carrier-factoring-prequalification-engine/' + queryString);
          await page.waitForFunction(() => document.getElementById('prequal-form')?.dataset.prequalInitialized);
        };

        // 1. Clean Load Test: no checked radios by default
        await load();
        assert.equal(await page.locator('input[type=radio]:checked').count(), 0);
        checks++;

        // 2. Radio Button Selection & Full-Card Click on Mobile & Desktop
        const groups = ['authority_age', 'monthly_gross', 'equipment', 'factoring_status'];
        for (const name of groups) {
          const ids = await page.locator(`input[name="${name}"]`).evaluateAll(els => els.map(el => el.id));
          for (const id of ids) {
            // Click the card (label)
            await page.locator(`label[for="${id}"]`).click();
            assert.equal(await page.locator(`input[name="${name}"]:checked`).count(), 1);
            assert.equal(await page.locator(`#${id}`).isChecked(), true);

            // Verify active styling derived from :checked state with highway amber border
            const selected = await page.locator(`input[name="${name}"] + .prequal-option`).evaluateAll(els =>
              els.filter(el => getComputedStyle(el).borderTopColor.includes('245, 158')).length
            );
            assert.equal(selected, 1);
            checks++;
          }
        }
        assert.equal(await page.locator('input[type=radio]:checked').count(), 4);
        checks++;

        // 3. Keyboard Navigation
        const equipment = page.locator('input[name=equipment]').first();
        await equipment.focus();
        await page.keyboard.press('Space');
        await page.keyboard.press('ArrowRight');
        assert.equal(await page.locator('input[name=equipment]:checked').inputValue(), 'Reefer (Temperature-Controlled)');
        checks++;

        // 4. Touch Target Sizes: >= 44x44px
        assert.equal(await equipment.locator('..').locator('.prequal-option').evaluate(el => el.getBoundingClientRect().height >= 44), true);
        checks++;

        // 5. Partial Prefill: Only MC provided -> contact fieldset remains open & editable
        await load('?mc=MC-998877&utm_source=kit_test');
        assert.equal(await page.locator('#mc_dot').inputValue(), 'MC-998877');
        assert.equal(await page.locator('#contact-fields-fieldset').evaluate(el => el.style.display !== 'none'), true);
        assert.equal(await page.locator('#carrier-partial-note').evaluate(el => el.style.display !== 'none'), true);
        checks += 3;

        // 6. Full Valid Prefill: All 5 contact fields provided -> banner shown, contact collapsed, toggle works
        await load('?mc=MC-555123&name=Marcus%20Driver&company=Apex%20Hauling&phone=5550192834&email=marcus@example.com&equipment=Dry%20Van%20(53ft)&factoring_status=not_factoring');
        assert.equal(await page.locator('#carrier-prefill-banner').evaluate(el => el.style.display === 'flex'), true);
        assert.equal(await page.locator('#contact-fields-fieldset').evaluate(el => el.style.display === 'none'), true);
        assert.equal(await page.locator('#badge-name-display').innerText(), 'Marcus Driver');
        assert.equal(await page.locator('#badge-company-display').innerText(), 'Apex Hauling');

        // Toggle edit details
        await page.locator('#toggle-edit-contact').click();
        assert.equal(await page.locator('#contact-fields-fieldset').evaluate(el => el.style.display === 'block'), true);
        assert.equal(await page.locator('#toggle-edit-contact').innerText(), 'Hide details');
        await page.locator('#toggle-edit-contact').click();
        assert.equal(await page.locator('#contact-fields-fieldset').evaluate(el => el.style.display === 'none'), true);
        checks += 6;

        // 7. Malformed / Malicious Query Parameters: No crashing or throwing DOMException
        await load('?equipment=%22%3E%3Cscript%3Ealert(1)%3C/script%3E&factoring_status=%27%22--&mc=MC-111');
        assert.equal(await page.locator('#prequal-form').getAttribute('data-prequal-initialized'), 'true');
        checks++;

        // 8. Form Validation: Empty required fields block submit without network calls
        await load();
        await page.locator('#submit-btn').click();
        assert.equal(posts, 0);
        checks++;

        // 9. Hidden Field Invalid Event Handling: Empty required field inside collapsed container unhides
        await load('?mc=MC-555123&name=Marcus%20Driver&company=Apex%20Hauling&phone=5550192834&email=marcus@example.com');
        // Clear phone while collapsed to trigger validation unhiding
        await page.$eval('#phone', el => el.value = '');
        await page.locator('#submit-btn').click();
        // Contact fieldset must automatically unhide
        assert.equal(await page.locator('#contact-fields-fieldset').evaluate(el => el.style.display !== 'none'), true);
        checks++;

        // 10. Valid Submission: Summar Flow
        await load();
        const fillForm = async () => {
          for (const [k, v] of Object.entries({
            mc_dot: 'MC-123456',
            company_name: 'Apex Logistics LLC',
            owner_name: 'Marcus Driver',
            phone: '5550192834',
            email: 'marcus@example.com'
          })) {
            await page.locator('#' + k).fill(v);
          }
          for (const name of groups) {
            const id = await page.locator(`input[name="${name}"]`).first().getAttribute('id');
            await page.locator(`label[for="${id}"]`).click();
          }
        };

        await fillForm();
        mode = 'delayed';
        reply = { success: true, tier: 'SUMMAR', email_sent: false, booking_url: 'https://cwlqj04.na1.hs-sales-engage.com/Elizabeth-Valencia' };
        await page.locator('#prequal-form').evaluate(form => {
          form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        });
        await page.waitForFunction(() => !document.getElementById('result-summar').hidden);
        assert.equal(posts, 1);
        assert.equal(body.authority_age, 'under_90_days');
        assert.equal(body.monthly_gross, 'under_10k');
        assert.equal(body.equipment, 'Dry Van (53ft)');
        assert.equal(body.factoring_status, 'not_factoring');
        assert.match(await page.locator('#result-summar [data-email-status]').innerText(), /not been confirmed/);
        checks += 6;

        // 11. Valid Submission: BasicBlock Flow with Server Confirmed Email & Malicious Redirect Prevention
        await load();
        await fillForm();
        mode = 'ok';
        reply = {
          success: true,
          tier: 'BASICBLOCK',
          email_sent: true,
          activation_url: 'javascript:alert(1)' // Malicious URL must be rejected!
        };
        await page.locator('#prequal-form').evaluate(form => {
          form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        });
        await page.waitForFunction(() => !document.getElementById('result-basicblock').hidden);
        // Valid default URL must NOT be replaced with javascript:alert(1)
        assert.match(await page.locator('#basicblock-activate-btn').getAttribute('href'), /^https:\/\/na2\.hubs\.ly\//);
        assert.match(await page.locator('#result-basicblock [data-email-status]').innerText(), /server confirmed/);
        checks += 2;

        // 12. Failure Modes & In-flight Error Recovery
        for (const failure of ['http', 'json', 'unknown', 'unsuccessful', 'network', 'timeout']) {
          await load();
          await fillForm();
          mode = failure;
          reply = failure === 'unknown' ? { success: true, tier: 'UNKNOWN' } : { success: false, tier: 'BASICBLOCK' };
          if (failure === 'timeout') {
            await page.evaluate(() => {
              window.fetch = () => Promise.reject(new DOMException('Test timeout', 'AbortError'));
            });
          }
          await page.locator('#prequal-form').evaluate(form => {
            form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
          });
          await page.waitForFunction(() => !document.getElementById('prequal-error').hidden);
          assert.equal(await page.locator('#result-summar').evaluate(el => el.hidden), true);
          assert.equal(await page.locator('#result-basicblock').evaluate(el => el.hidden), true);
          assert.equal(await page.locator('#submit-btn').isDisabled(), false);
          checks += 3;
        }

        // 13. Dynamic Re-execution / Late Script Insertion Guard
        await page.setContent('<main></main>');
        await page.evaluate(html => {
          document.querySelector('main').innerHTML = html;
          const inline = [...document.querySelectorAll('main script:not([src])')].pop().textContent;
          (0, eval)(inline);
          (0, eval)(inline);
        }, source);
        assert.equal(await page.locator('#prequal-form').getAttribute('data-prequal-initialized'), 'true');
        checks++;

        assert.deepEqual(errors, []);
        checks++;
        console.log(`PASS ${entry} @ ${width}px`);
        await page.close();
      }
    }

    console.log(`\nALL TESTS PASSED: ${checks} assertions passed across 390px mobile & 1280px desktop.`);
    console.log('All external endpoints were mocked. Zero real emails or production webhooks sent.');
  } finally {
    await browser.close();
  }
})().catch(e => {
  console.error(e);
  process.exit(1);
});
