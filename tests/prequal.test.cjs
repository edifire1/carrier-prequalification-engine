// npm install --no-save playwright && node tests/prequal.test.cjs
// All external traffic is intercepted. This suite never contacts n8n or sends email.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
  const executable = process.env.CHROMIUM_PATH || (fs.existsSync('/usr/local/bin/chromium') ? '/usr/local/bin/chromium' : undefined);
  const browser = await chromium.launch({ ...(executable ? { executablePath: executable } : {}), args:['--no-sandbox'] });
  let checks = 0;
  try {
    for (const entry of ['index.html', 'wordpress-embed.html']) {
      for (const width of [390, 1280]) {
        const page = await browser.newPage({ viewport:{width,height:900} });
        let body, reply = {success:true,tier:'SUMMAR'}, mode='ok', posts=0;
        const errors=[];
        page.on('pageerror', e => errors.push(e.message));
        await page.route('**/*', async route => {
          const req=route.request();
          if (req.method()==='POST' && req.url().includes('/webhook/trucker-factoring-prequal')) {
            posts++; body=req.postDataJSON();
            if (mode==='network') return route.abort();
            if (mode==='delayed') await new Promise(r=>setTimeout(r,150));
            return route.fulfill({status:mode==='http'?500:200,contentType:'application/json',body:mode==='json'?'not json':JSON.stringify(reply)});
          }
          return route.abort();
        });
        const source=fs.readFileSync(path.join(__dirname,'../frontend',entry),'utf8');
        const load=async()=>{ await page.setContent(source); await page.waitForFunction(()=>document.getElementById('prequal-form')?.dataset.prequalInitialized); };
        await load();
        assert.equal(await page.locator('input[type=radio]:checked').count(),0); checks++;
        const groups=['authority_age','equipment','monthly_gross','factoring_status'];
        for (const name of groups) {
          const ids=await page.locator(`input[name="${name}"]`).evaluateAll(els=>els.map(el=>el.id));
          for (const id of ids) {
            await page.locator(`label[for="${id}"]`).click();
            assert.equal(await page.locator(`input[name="${name}"]:checked`).count(),1);
            const selected=await page.locator(`input[name="${name}"] + .prequal-option`).evaluateAll(els=>els.filter(el=>getComputedStyle(el).borderTopColor==='rgb(249, 115, 22)').length);
            assert.equal(selected,1); checks++;
          }
        }
        assert.equal(await page.locator('input:checked').count(),4); checks++;
        const equipment=page.locator('input[name=equipment]').first();
        await equipment.focus(); await page.keyboard.press('Space');
        await page.keyboard.press('ArrowRight');
        assert.equal(await page.locator('input[name=equipment]:checked').inputValue(),'Reefer (Temperature-Controlled)'); checks++;
        assert.equal(await equipment.locator('..').locator('.prequal-option').evaluate(el=>el.getBoundingClientRect().height>=44),true); checks++;
        await load();
        await page.locator('#submit-btn').click();
        assert.equal(posts,0); checks++;
        const fill=async()=>{
          for (const [k,v] of Object.entries({mc_dot:'MC-123456',company_name:'TEST ONLY',owner_name:'Test Driver',phone:'2025550100',email:'test@example.invalid'})) await page.locator('#'+k).fill(v);
          for (const name of groups) { const id=await page.locator(`input[name="${name}"]`).first().getAttribute('id'); await page.locator(`label[for="${id}"]`).click(); }
        };
        await fill(); mode='delayed';
        await page.locator('#prequal-form').evaluate(form=>{form.requestSubmit();form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));});
        await page.waitForFunction(()=>!document.getElementById('result-summar').hidden);
        assert.equal(posts,1); assert.equal(body.authority_age,'under_90_days');
        assert.equal(body.equipment,'Dry Van (53ft)'); assert.equal(body.monthly_gross,'under_10k'); assert.equal(body.factoring_status,'not_factoring'); checks+=5;
        assert.match(await page.locator('#result-summar [data-email-status]').innerText(),/not been confirmed/); checks++;
        await load(); await fill(); mode='ok'; reply={success:true,tier:'BASICBLOCK',email_sent:true,activation_url:'javascript:alert(1)'};
        await page.locator('#submit-btn').click(); await page.waitForFunction(()=>!document.getElementById('result-basicblock').hidden);
        assert.equal(await page.locator('#basicblock-activate-btn').getAttribute('href'),'https://na2.hubs.ly/H08PBVP0');
        assert.match(await page.locator('#result-basicblock [data-email-status]').innerText(),/server confirmed/); checks+=2;
        for (const failure of ['http','json','unknown','unsuccessful','network','timeout']) {
          await load(); await fill(); mode=failure;
          reply=failure==='unknown'?{success:true,tier:'UNKNOWN'}:{success:false,tier:'BASICBLOCK'};
          if(failure==='timeout') {
            await page.evaluate(()=>{ window.fetch=()=>Promise.reject(new DOMException('Test timeout','AbortError')); });
          }
          await page.locator('#submit-btn').click(); await page.waitForFunction(()=>!document.getElementById('prequal-error').hidden);
          assert.equal(await page.locator('#result-summar').evaluate(el=>el.hidden),true);
          assert.equal(await page.locator('#result-basicblock').evaluate(el=>el.hidden),true);
          assert.equal(await page.locator('#submit-btn').isDisabled(),false); checks+=3;
        }
        // Script inserted after DOMContentLoaded must initialize exactly once.
        await page.setContent('<main></main>');
        await page.evaluate(html=>{
          document.querySelector('main').innerHTML=html;
          const inline=[...document.querySelectorAll('main script:not([src])')].pop().textContent;
          (0,eval)(inline); (0,eval)(inline);
        },source);
        assert.equal(await page.locator('#prequal-form').getAttribute('data-prequal-initialized'),'true'); checks++;
        assert.deepEqual(errors,[]); checks++;
        console.log(`PASS ${entry} @ ${width}px`);
        await page.close();
      }
    }
    console.log(`PASS: ${checks} assertions; all external requests mocked/blocked; no production submissions.`);
  } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exit(1)});
