const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const os = require('node:os');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright');
const root = path.resolve(__dirname, '..');
assert.match(process.env.IMPE_DB_NAME || '', /^impe_test_handly[a-z0-9_]*$/);
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'impe-internacionales-ui-'));
const router = path.join(temporary, 'router.php');
// Sesiones simuladas solo en servidor de pruebas local; router privado fuera del CRM.
fs.writeFileSync(router, `<?php
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/__aliados_test') { return false; }
require ${JSON.stringify(root.replaceAll('\\', '/') + '/app/controllers/AliadoController.php')};
$caso = $_GET['caso'] ?? 'admin';
$_SESSION = $caso === 'anonimo' ? [] : ['usuario_id'=>1,'rol_id'=>$caso === 'otro-rol' ? 2 : 1,'permisos'=>$caso === 'sin-permiso' ? [] : ['aliados.ver']];
(new AliadoController())->internacionales();
`);
const base = 'http://127.0.0.1:8098/';
const server = spawn(process.env.PHP_BINARY || 'php', ['-S','127.0.0.1:8098','-t',root,router], {
 cwd:root, env:{...process.env,APP_ENV:'testing',IMPE_BASE_URL:base}, stdio:'ignore'
});
(async()=>{
 let browser;
 try {
  for(let i=0;i<80;i++){try{await fetch(base+'__aliados_test');break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser = await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL ? {channel:process.env.PLAYWRIGHT_CHANNEL} : {})});
  const page = await browser.newPage();
  const bootstrap = process.env.BOOTSTRAP_MODULE_PATH || path.join(root,'../browser-deps/node_modules/bootstrap');
  await page.route('https://**/*', r => r.request().url().includes('bootstrap.bundle.min.js') ? r.fulfill({path:path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),contentType:'application/javascript'}) : r.request().url().includes('bootstrap.min.css')
   ? r.fulfill({path:path.join(bootstrap,'dist/css/bootstrap.min.css'),contentType:'text/css'}) : r.abort());
  for (const [caso,status] of [['anonimo',401],['sin-permiso',403],['otro-rol',403]]) {
   const response = await page.goto(base+'__aliados_test?caso='+caso);
   assert.equal(response.status(),status);
   assert.equal(await page.locator('[data-copy-ally-url]').count(),0);
   assert.doesNotMatch(await page.content(),/Adalid Kuribeña|7775635090|aliado=[a-f0-9]{64}/);
  }
  const response = await page.goto(base+'__aliados_test');
  assert.equal(response.status(),200);
  assert.match(response.headers()['cache-control'],/no-store/);
  const card = page.locator('article').filter({hasText:'Handly Lenguaje Center'});
  assert.equal(await card.count(),1);
  assert.match(await card.textContent(),/Adalid Kuribeña/);
  assert.match(await card.textContent(),/7775635090/);
  assert.match(await card.textContent(),/Bachillerato General \/ Titulación por experiencia laboral/);
  assert.doesNotMatch(await card.textContent(),/Inglés/);
  await card.getByRole('button',{name:'Ver detalles'}).click();
  const details=page.locator('#modalDetalleInternacional');await details.waitFor({state:'visible'});
  for (const tipo of ['registro','inscripcion']) {
   const href = await details.locator(`a[href*="action=${tipo}"]`).getAttribute('href');
   assert.match(href,/aliado=[a-f0-9]{64}/);
   assert.equal(await details.locator(`[data-copy-ally-url="${href}"]`).count(),1);
  }
  for (const width of [1440,390]) {
   await page.setViewportSize({width,height:900});
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth > innerWidth),false,'vista internacional sin desbordamiento');
  }
  console.log('OK: vista internacional, contacto, enlaces originales, permisos HTTP, no-store y responsive 1440/390');
 } finally {
  if(browser) await browser.close();
  server.kill();
  await new Promise(resolve=>server.exitCode !== null ? resolve() : server.once('exit',resolve));
  fs.rmSync(temporary,{recursive:true,force:true});
 }
})().catch(error=>{console.error(error);process.exitCode=1;});
