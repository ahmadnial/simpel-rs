// Run after the Office integration test and npm run build; all browser requests are intercepted.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright-core');
const fs = require('fs');
const assert = require('node:assert/strict');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const artifactDir = process.env.PREVIEW_FIXTURE_DIR || require('node:os').tmpdir();
const manifest = JSON.parse(fs.readFileSync(root + '/public/build/manifest.json', 'utf8'));
const entry = manifest['resources/js/app.js'].file;
const errors = [];
let fail = false;
let requests = 0;
let longDocument = false;
require('node:child_process').execFileSync('php', [path.join(__dirname, 'create-preview-fixture.php'), path.join(artifactDir, 'simpel-scroll-preview.pdf')]);
const styles = [manifest['resources/css/app.css'].file, ...(manifest['resources/js/app.js'].css || [])].map(file => `<link rel="stylesheet" href="/build/${file}">`).join('');
const html = `<!doctype html><html><head><meta name="csrf-token" content="test">${styles}<style>body{max-width:1000px;margin:16px auto;padding:0 12px}form input{max-width:100%}</style></head><body>
<div class="card document-preview-card"><div class="card-header"><span class="card-title">Pratinjau dokumen</span><span class="badge badge-indigo">Versi v1</span></div>
<p class="document-preview-filename">Pedoman pengorganisasian IBS 2026.docx</p>
<div class="naskah-preview-wrapper" data-document-preview data-url="http://localhost/pdf" data-reviewable="1" data-hide-toolbar="0">
<div class="document-preview-toolbar"><div class="document-preview-tools"><span class="document-preview-count" data-preview-page-count>PDF</span><label class="document-preview-toggle"><input type="checkbox" data-preview-markers checked>Tandai variabel</label></div>
<div class="document-preview-tools"><button class="btn btn-secondary btn-sm" data-preview-retry hidden>Coba lagi</button><a class="btn btn-secondary btn-sm" data-preview-open hidden>Buka PDF</a></div></div>
<p class="document-preview-status" data-preview-status role="status"></p><p class="document-preview-warning" data-preview-warnings hidden></p>
<div data-preview-pages hidden></div><iframe data-preview-frame hidden></iframe>
<p class="document-preview-note">Gulir untuk memeriksa dokumen. Variabel ditandai merah; tanggal variabel diberi kotak. QR draft hanya untuk memeriksa posisi.</p></div></div>
<form><input type="hidden" data-document-review-token><button data-document-submit disabled>Ajukan dokumen</button></form>
<form data-document-reupload><input type="file" name="file_dokumen"><button type="submit">Unggah dokumen</button></form>
<script type="module" src="/build/${entry}"></script></body></html>`;
(async () => {
 const browser = await chromium.launch({ executablePath:process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true });
 const page = await browser.newPage({viewport:{width:1200,height:1000}});
 page.on('pageerror', e=>errors.push(e.message));
 page.on('console', m=>{if(m.type()==='error' || m.type()==='warning') console.log('BROWSER',m.text());});
 await page.route('**/*', async route => {
  const url = new URL(route.request().url());
  if(url.pathname==='/') return route.fulfill({contentType:'text/html',body:html});
  if(url.pathname==='/pdf') {
   requests++;
   if(fail) return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'Layanan konversi sementara tidak tersedia.'})});
   const marked = url.searchParams.get('markers') !== '0';
   return route.fulfill({contentType:'application/pdf',headers:{'X-Document-Review':marked?'marked-receipt':'clean-receipt'},body:fs.readFileSync(longDocument ? path.join(artifactDir, 'simpel-scroll-preview.pdf') : `${artifactDir}/simpel-${marked?'marked':'clean'}-preview.pdf`)});
  }
  if(url.hostname !== 'localhost') return route.fulfill({contentType:'text/css',body:''});
  if(url.pathname.startsWith('/build/')) return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body:fs.readFileSync(root+'/public'+url.pathname)});
  return route.abort();
 });
 try {
  await page.goto('http://localhost/');
  await page.waitForFunction(()=>!document.querySelector('[data-document-submit]').disabled);
  assert.equal(await page.locator('[data-preview-retry]').isHidden(),true);
  assert.equal(await page.locator('[data-date-variable]').count(),1);
  assert.equal(await page.locator('[data-document-review-token]').inputValue(),'marked-receipt');
  await page.screenshot({path:path.join(artifactDir, 'simpel-preview-browser.png'),fullPage:true});
  await page.locator('[data-preview-markers]').uncheck();
  await page.waitForFunction(()=>document.querySelector('[data-document-review-token]').value==='clean-receipt');
  assert.equal(await page.locator('[data-date-variable]').count(),0);
  // Rapid changes must never revive a stale render or stale receipt.
  await page.locator('[data-preview-markers]').check();
  await page.locator('[data-preview-markers]').uncheck();
  await page.waitForFunction(()=>document.querySelector('[data-document-review-token]').value==='clean-receipt');
  await page.locator('input[type=file]').setInputFiles({name:'revisi.docx',mimeType:'application/octet-stream',buffer:Buffer.from('fixture')});
  assert.equal(await page.locator('[data-document-submit]').isDisabled(),true);
  const count = requests;
  await page.locator('[data-preview-markers]').check();
  await page.waitForTimeout(300);
  assert.equal(requests,count,'Selecting replacement must prevent reloading/submitting the old source');
  assert.equal(await page.locator('[data-document-submit]').isDisabled(),true);
  fail=true;
  await page.reload();
  await page.locator('[data-preview-retry]').waitFor({state:'visible'});
  assert.equal(await page.locator('[data-document-submit]').isDisabled(),true);
  assert.match(await page.locator('[data-preview-status]').innerText(),/sementara tidak tersedia/);
  fail=false;
  await page.locator('[data-preview-retry]').click();
  await page.waitForFunction(()=>!document.querySelector('[data-document-submit]').disabled);
  longDocument = true;
  await page.reload();
  await page.waitForFunction(()=>!document.querySelector('[data-document-submit]').disabled);
  assert.equal(await page.locator('[data-page-number]').count(),92);
  assert.equal(await page.getByRole('button', {name:'Berikutnya',exact:true}).count(),0);
  const scroll = page.locator('.document-preview-scroll');
  await scroll.evaluate(el=>{el.scrollTop=el.scrollHeight;});
  await page.locator('[data-page-number="92"] canvas').waitFor();
  await page.waitForFunction(()=>document.querySelector('[data-preview-page-count]').textContent==='Halaman 92 dari 92');
  await page.waitForFunction(()=>document.querySelectorAll('.document-preview-page canvas').length <= 5);
  assert.equal(await page.locator('[data-page-number="1"] canvas').count(),0);
  await scroll.evaluate(el=>{ const target=el.querySelector('[data-page-number="46"]'); el.scrollTop+=target.getBoundingClientRect().top-el.getBoundingClientRect().top-20; });
  await page.locator('[data-page-number="46"] canvas').waitFor();
  await scroll.evaluate(el=>{el.scrollTop=0;});
  await page.locator('[data-page-number="1"] canvas').waitFor();
  assert.equal(await page.locator('[data-document-submit]').isDisabled(),false);
  await page.screenshot({path:path.join(artifactDir, 'simpel-preview-scroll.png'),fullPage:true});
  for (const width of [320,375,414,768]) {
    await page.setViewportSize({width,height:900});
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth <= window.innerWidth),true, `No horizontal overflow at ${width}px`);
  }
  assert.deepEqual(errors,[]);
  console.log('PASS: automatic preview, date overlay, marker toggle, stale-render/replacement guards, failure/retry, 92-page scroll, bounded canvases and responsive widths.');
 } catch(e) { console.log('STATE',await page.locator('[data-preview-status]').innerText(), errors); await page.screenshot({path:path.join(artifactDir, 'simpel-preview-error.png'),fullPage:true}); throw e; } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
