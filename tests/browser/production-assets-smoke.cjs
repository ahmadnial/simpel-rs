// Static production assets only; HTML and synthetic PDF are intercepted. No authenticated application requests.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE);
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
const origin='https://simpel.rsnurrohmah.my.id';
const pdf=fs.readFileSync(path.join(require('node:os').tmpdir(),'simpel-scroll-preview.pdf'));
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
 const page=await browser.newPage(); const errors=[];const assets=[];
 page.on('pageerror',e=>errors.push(e.message));
 page.on('response',r=>{if(r.url().startsWith(origin+'/build/'))assets.push({file:new URL(r.url()).pathname,status:r.status()});});
 try {
  const response=await page.request.get(origin+'/build/manifest.json'); assert.equal(response.status(),200);
  const manifest=await response.json();const entry=manifest['resources/js/app.js'].file;
  const styles=[manifest['resources/css/app.css'].file,...(manifest['resources/js/app.js'].css||[])];
  const html=`<!doctype html><html><head><meta name="csrf-token" content="synthetic">${styles.map(f=>`<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><div data-document-preview data-url="/__simpel-smoke-pdf" data-reviewable="1"><button type="button" data-preview-retry hidden>Coba lagi</button><a data-preview-open hidden>Buka PDF</a><p data-preview-status></p><p data-preview-warnings hidden></p><span data-preview-page-count></span><div data-preview-pages hidden></div><iframe data-preview-frame hidden></iframe></div><input data-document-review-token><button data-document-submit disabled>Ajukan</button><script type="module" src="/build/${entry}"></script></body></html>`;
  await page.route(origin+'/__simpel-smoke',r=>r.fulfill({contentType:'text/html',body:html}));
  await page.route(origin+'/__simpel-smoke-pdf',r=>r.fulfill({contentType:'application/pdf',headers:{'X-Document-Review':'synthetic','X-Preview-Bytes':String(pdf.length)},body:pdf}));
  await page.goto(origin+'/__simpel-smoke');
  await page.waitForFunction(()=>!document.querySelector('[data-document-submit]').disabled);
  assert.equal(await page.locator('[data-page-number]').count(),92);
  await page.locator('.document-preview-scroll').evaluate(el=>el.scrollTop=el.scrollHeight);
  await page.locator('[data-page-number="92"] canvas').waitFor();
  assert.deepEqual(errors,[]);assert.ok(assets.length>=4);assert.ok(assets.every(a=>a.status===200));
  console.log(JSON.stringify({result:'PASS',entry,synthetic_pdf_pages:92,production_assets:assets,page_errors:errors}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
