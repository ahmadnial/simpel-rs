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
let mode = 'missing-token';
let busyCount = 0;
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
   if (mode === 'stalled') return;
   if (mode === 'busy' || (mode === 'busy-once' && busyCount++ === 0)) return route.fulfill({status:503,contentType:'application/json',headers:{'X-Preview-State':'processing','Retry-After':'2'},body:JSON.stringify({message:'PDF sedang diproses.'})});
   if (mode === 'incomplete') return route.fulfill({contentType:'application/pdf',headers:{'X-Document-Review':'receipt','X-Preview-Bytes':'999999'},body:fs.readFileSync(`${artifactDir}/simpel-marked-preview.pdf`)});
   if (mode === 'unauthorized') return route.fulfill({status:401,contentType:'application/json',body:'{}'});
   if (mode === 'truncated') return route.fulfill({contentType:'application/pdf',headers:{'X-Document-Review':'receipt'},body:fs.readFileSync(`${artifactDir}/simpel-marked-preview.pdf`).subarray(0,256)});
   if(fail) return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'Layanan konversi sementara tidak tersedia.'})});
   const marked = url.searchParams.get('markers') !== '0';
   return route.fulfill({contentType:'application/pdf',headers:mode === 'missing-token' ? {} : {'X-Document-Review':marked?'marked-receipt':'clean-receipt'},body:fs.readFileSync(longDocument ? path.join(artifactDir, 'simpel-scroll-preview.pdf') : `${artifactDir}/simpel-${marked?'marked':'clean'}-preview.pdf`)});
  }
  if(url.hostname !== 'localhost') return route.fulfill({contentType:'text/css',body:''});
  if(url.pathname.startsWith('/build/')) return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body:fs.readFileSync(root+'/public'+url.pathname)});
  return route.abort();
 });
 try {
  const ready = () => page.waitForFunction(()=>!document.querySelector('[data-document-submit]').disabled);
  const message = text => page.waitForFunction(text=>document.querySelector('[data-preview-status]').textContent.includes(text),text);
  const retry = () => page.locator('[data-preview-retry]').click();
  await page.goto('http://localhost/');
  await message('pengajuan belum diizinkan');
  assert.equal(await page.locator('[data-document-submit]').isDisabled(),true);
  assert.equal(await page.locator('[data-preview-retry]').isVisible(),true);
  mode='valid'; await retry(); await ready();
  mode='incomplete'; await page.reload(); await message('tidak lengkap');
  assert.equal(await page.locator('[data-document-submit]').isDisabled(),true);
  mode='valid'; await retry(); await ready();
  mode='truncated'; await page.reload();
  await page.waitForFunction(()=>document.querySelector('[data-preview-retry]').textContent==='Coba lagi');
  assert.equal(await page.locator('[data-document-submit]').isDisabled(),true);
  mode='valid'; await retry(); await ready();
  mode='stalled'; await page.reload({waitUntil:'domcontentloaded'});
  await message('Menyiapkan');
  assert.equal(await page.locator('[data-preview-retry]').isVisible(),true);
  mode='valid'; await retry(); await ready();
  await page.clock.install();
  mode='stalled'; await page.reload({waitUntil:'domcontentloaded'});
  await message('Menyiapkan');
  await page.clock.fastForward(150001); await message('terlalu lama');
  assert.equal(await page.locator('[data-document-submit]').isDisabled(),true);
  mode='valid'; await retry(); await ready();
  mode='busy-once'; busyCount=0; await page.reload(); await message('Mencoba lagi');
  await page.clock.fastForward(2001); await ready();
  mode='busy'; const start=requests; await page.reload();
  for(let i=0;i<3;i++) {
    await message('Mencoba lagi');
    await page.clock.fastForward(2001);
    await page.waitForFunction(()=>document.querySelector('[data-preview-status]').textContent!=='Menyiapkan PDF…');
  }
  await page.waitForFunction(()=>document.querySelector('[data-preview-retry]').textContent==='Coba lagi');
  assert.equal(requests-start,4,'Only three automatic retries after the initial request');
  mode='valid'; await retry(); await ready();
  mode='stalled'; await page.reload({waitUntil:'domcontentloaded'}); await message('Menyiapkan');
  mode='valid'; const before=requests;
  await page.evaluate(()=>{
    window.dispatchEvent(new PageTransitionEvent('pagehide',{persisted:true}));
    window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}));
  });
  await ready(); assert.equal(requests,before+1);
  await page.locator('input[type=file]').setInputFiles({name:'revisi.docx',mimeType:'application/octet-stream',buffer:Buffer.from('fixture')});
  const selectedCount=requests;
  await page.evaluate(()=>{
    document.querySelector('[data-document-reupload] button').disabled=true;
    window.dispatchEvent(new PageTransitionEvent('pagehide',{persisted:true}));
    window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}));
  });
  await message('Berkas pengganti');
  assert.equal(requests,selectedCount);
  assert.equal(await page.locator('[data-document-submit]').isDisabled(),true);
  assert.equal(await page.locator('[data-document-reupload] button').isEnabled(),true);
  await page.locator('input[type=file]').setInputFiles([]); await ready();
  mode='unauthorized'; await page.reload(); await message('Sesi telah berakhir');
  assert.equal(await page.locator('[data-document-submit]').isDisabled(),true);
  assert.deepEqual(errors,[]);
  console.log('PASS: missing receipt, incomplete/corrupt PDF, stalled download recovery, timeout, bounded busy retry, simulated lifecycle restore, replacement protection and expired session.');
 } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
