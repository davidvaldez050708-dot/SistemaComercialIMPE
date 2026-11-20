const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..');
function render(configured){
 const code=`define('BASE_URL','https://wa.test/'); $_SESSION=['csrf_token'=>'fixture-csrf']; $texto=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); $conversacionSeleccionada=['id'=>7]; $plantillaVentas=${configured?"['plantilla_nombre'=>'inicio','plantilla_cuerpo'=>'Hola <script>bad()</script>','plantilla_categoria'=>'MARKETING','plantilla_idioma'=>'es_MX']":"null"}; require 'app/views/whatsapp/ventas_inicio.php';`;
 return '<!doctype html><meta charset="utf-8"><div>'+cp.execFileSync('php',['-r',code],{cwd:root,encoding:'utf8'})+'</div>';
}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});
 try{
  const page=await browser.newPage();let sends=0,body='',errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://wa.test/**',async route=>{
   const req=route.request();
   if(req.url().includes('/public/'))return route.fulfill({contentType:'text/javascript',body:fs.readFileSync(path.join(root,'public/javascript/whatsapp_ventas.js'),'utf8')});
   if(req.method()==='POST'){sends++;body=req.postData();await new Promise(r=>setTimeout(r,120));return route.fulfill({json:{ok:true,mensaje:'Presentación enviada'}});}
   return route.fulfill({contentType:'text/html',body:render(!req.url().includes('pending'))});
  });
  await page.goto('https://wa.test/pending');assert.equal(await page.locator('button').count(),0);assert.equal(sends,0);
  await page.goto('https://wa.test/ready');assert.equal(await page.locator('script:not([src])').count(),0);
  await page.locator('[data-wa-ventas-inicio]').evaluate(b=>{b.click();b.click();});
  await page.waitForFunction(()=>document.querySelector('button').dataset.sent==='1');
  assert.equal(sends,1);assert(body.includes('fixture-csrf')&&body.includes('request_id'));assert(await page.locator('button').isDisabled());
  assert.deepEqual(errors,[]);console.log('PASS UI: plantilla pendiente, texto escapado, CSRF, doble clic y confirmación sin envíos reales.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
