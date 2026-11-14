// Real loopback HTTP streams; synthetic PDF only. No production requests or database.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright-core');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const fixture = path.join(require('node:os').tmpdir(), 'simpel-transport-fixture.pdf');
require('node:child_process').execFileSync('php', [path.join(__dirname, 'create-preview-fixture.php'), fixture]);
const pdf = fs.readFileSync(fixture);
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/build/manifest.json')));
const entry = manifest['resources/js/app.js'].file;
let mode = 'body-stall';
let requests = 0;
let aborts = 0;
const html = `<!doctype html><html><head><meta name="csrf-token" content="test"></head><body>
<div data-document-preview data-url="/pdf" data-reviewable="1">
<button type="button" data-preview-retry hidden>Coba lagi</button><a data-preview-open hidden>Buka PDF</a>
<p data-preview-status></p><p data-preview-warnings hidden></p><span data-preview-page-count></span>
<div data-preview-pages hidden></div><iframe data-preview-frame hidden></iframe></div>
<input data-document-review-token><button data-document-submit disabled>Ajukan</button>
<a href="/other" id="away">Halaman lain</a><script type="module" src="/build/${entry}"></script></body></html>`;
const server = http.createServer((req,res)=>{
 const pathname = new URL(req.url, 'http://localhost').pathname;
 if(pathname === '/') { res.setHeader('Content-Type','text/html'); return res.end(html); }
 if(pathname === '/other') { res.setHeader('Content-Type','text/html'); return res.end('<p>Halaman lain</p>'); }
 if(pathname === '/pdf') {
  requests++;
  res.on('close',()=>{ if(!res.writableEnded) aborts++; });
  res.writeHead(200, {'Content-Type':'application/pdf','X-Document-Review':'receipt','X-Preview-Bytes':String(pdf.length),'Cache-Control':'private, no-store'});
  if(mode === 'valid') return res.end(pdf);
  res.write(pdf.subarray(0,256));
  if(mode === 'disconnect') setTimeout(()=>res.destroy(),50);
  // body-stall intentionally leaves the HTTP stream open until the client aborts.
  return;
 }
 if(pathname.startsWith('/build/') && !pathname.includes('..')) {
  const file = path.join(root,'public',pathname);
  if(fs.existsSync(file)) {
   res.setHeader('Content-Type',pathname.endsWith('.css')?'text/css':'text/javascript');
   return res.end(fs.readFileSync(file));
  }
 }
 res.writeHead(404);res.end();
});
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const origin = `http://127.0.0.1:${server.address().port}`;
 const browser = await chromium.launch({executablePath:process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true,ignoreDefaultArgs:['--disable-back-forward-cache']});
 const page = await browser.newPage();
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(()=>window.addEventListener('pageshow',e=>{window.persistedRestore=e.persisted;}));
 try {
  await page.clock.install();
  const message=text=>page.waitForFunction(text=>document.querySelector('[data-preview-status]').textContent.includes(text),text);
  const ready=()=>page.waitForFunction(()=>!document.querySelector('[data-document-submit]').disabled);
  await page.goto(origin,{waitUntil:'domcontentloaded'});
  await message('Mengunduh PDF');
  assert.equal(await page.locator('[data-document-submit]').isDisabled(),true);
  await page.clock.fastForward(30001);await message('Unduhan PDF terhenti');
  mode='valid';await page.locator('[data-preview-retry]').click();await ready();
  mode='disconnect';await page.reload({waitUntil:'domcontentloaded'});
  await message('koneksi terputus');
  assert.equal(await page.locator('[data-document-submit]').isDisabled(),true);
  mode='valid';await page.locator('[data-preview-retry]').click();await ready();
  const before=requests;
  await page.locator('#away').click();
  await page.goBack({waitUntil:'commit'});await ready();
  assert.equal(requests,before+1,'Return navigation reloads the preview once');
  const persisted=await page.evaluate(()=>window.persistedRestore);
  assert.deepEqual(errors,[]);
  assert.ok(aborts>=1,'Idle timeout actually aborted the open HTTP response');
  console.log(JSON.stringify({result:'PASS',body_idle_timeout:true,connection_drop_recovery:true,back_navigation:true,actual_bfcache_restore:persisted,aborted_streams:aborts}));
 } finally {
  await browser.close();server.closeAllConnections();await new Promise(resolve=>server.close(resolve));
  fs.unlinkSync(fixture);
 }
})().catch(e=>{console.error(e);server.closeAllConnections();server.close();process.exitCode=1;});
