const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),artifacts=path.join(root,'tmp/whatsapp-diseno');fs.mkdirSync(artifacts,{recursive:true});
const render=mode=>cp.execFileSync('php',['tests/whatsapp_diseno_render.php',mode],{cwd:root,encoding:'utf8'});
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const assetDir=path.join(artifacts,'assets'),assetManifest=path.join(assetDir,'manifest.json');
const assets=fs.existsSync(assetManifest)?JSON.parse(fs.readFileSync(assetManifest,'utf8')):{};
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});try{
 const context=await browser.newContext({viewport:{width:1366,height:850}});let sends=0,configSends=0;const errors=[];
 await context.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url());
  if(assets[req.url()]){const a=assets[req.url()];return route.fulfill({contentType:a.type,body:fs.readFileSync(path.join(assetDir,a.file))});}
  if(url.hostname==='cdn.jsdelivr.net'){
   const file=url.pathname.includes('bootstrap-icons')?'../browser-legacy-deps/node_modules/bootstrap-icons/font/bootstrap-icons.css':'../browser-legacy-deps/node_modules/bootstrap/dist/css/bootstrap.min.css';
   return route.fulfill({contentType:'text/css',body:fs.existsSync(path.join(root,file))?read(file):''});
  }
  if(url.pathname.startsWith('/public/')){
   const p=url.pathname.slice(1);let body='';if(fs.existsSync(path.join(root,p)))body=read(p);
   else if(p==='public/javascript/whatsapp_asignacion.js')body=cp.execFileSync('git',['show','46742db:'+p],{cwd:root,encoding:'utf8'});
   return route.fulfill({contentType:p.endsWith('.js')?'text/javascript':'text/css',body});
  }
  const action=url.searchParams.get('action');
  if(action==='mensajes')return route.fulfill({json:{ok:true,mensajes:[],estados_salida:[],ventana_servicio_abierta:url.searchParams.get('open')==='1'}});
  if(req.method()==='POST'){
   if(action==='guardarPlantillaVentas'){configSends++;return route.fulfill({json:{ok:true,mensaje:'Plantilla validada (simulada).',plantilla:{cuerpo:'Texto aprobado de prueba'}}});}
   sends++;return route.fulfill({json:{ok:true,mensaje:'Envío simulado'}});
  }
  const mode=url.searchParams.get('mode')||(action==='index'?'inbox':action==='asignaciones'?'queue':'config');return route.fulfill({contentType:'text/html',body:render(mode)});
 });
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 for(const mode of ['config','queue','queue-filled','inbox']){
  await page.goto('https://wa.test/?mode='+mode);
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),mode+' horizontal overflow');
  if(mode==='config'){
   assert(await page.locator('a[href*="action=index"]').count());
   assert.equal(await page.locator('h2').evaluate(e=>parseFloat(getComputedStyle(e).fontSize)),20);
   await page.locator('[name=messaging_account_id]').fill('2586216875220299');await page.locator('[name=plantilla_nombre]').fill('inicio_atencion_comercial');
   await page.locator('button[type=submit]').click();await page.locator('[data-wa-ventas-preview]').filter({hasText:'Texto aprobado de prueba'}).waitFor();assert.equal(configSends,1);assert.equal(await page.locator('[data-wa-template-state]').textContent(),'Plantilla configurada');
  }
  if(mode.startsWith('queue')){assert(await page.locator('a[href*="action=index"]').count());if(mode==='queue')assert(await page.locator('[data-wa-assignment-empty]').isVisible());}
  if(mode==='inbox'){
   for(const row of await page.locator('.whatsapp-conversation-item').all())assert((await row.boundingBox()).height<=100,'chat card expands');
   assert(await page.locator('.wa-chat-tags').first().evaluate(e=>e.clientHeight<=20));
  }
  await page.evaluate(()=>document.fonts.ready);await page.screenshot({path:path.join(artifacts,mode+'.png')});
  await page.setViewportSize({width:390,height:844});await page.reload();assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),mode+' mobile overflow');
  if(mode==='config'||mode.startsWith('queue')){await page.locator('a[href*="action=index"]').first().click();await page.locator('[data-whatsapp-module]').waitFor();assert.equal(new URL(page.url()).searchParams.get('action'),'index');}
  await page.setViewportSize({width:1366,height:850});
 }
 // Real contextual launcher + actual iframe view; opening must never send.
 const outer=`<!doctype html><meta charset="utf-8"><style>${read('public/css/whatsapp_contextual.css')}</style><button data-whatsapp-contexto="prospecto" data-whatsapp-id="140">Abrir chat</button><script>window.IMPE_CSRF_TOKEN='fixture-only'</script><script data-whatsapp-contextual data-base-url="https://wa.test/">${read('public/javascript/whatsapp_contextual.js')}</script>`;
 await page.route('https://wa.test/embudo',r=>r.fulfill({contentType:'text/html',body:outer}));
 let panelMode='panel';
 await page.route('**/*action=contexto*',r=>r.fulfill({json:{ok:true,url:'https://wa.test/index.php?controller=whatsapp&action=panel'}}));
 await page.route('**/*action=panel*',r=>r.fulfill({contentType:'text/html',body:render(panelMode)}));
 for(const mode of ['panel','panel-approved','panel-open']){
  panelMode=mode;await page.goto('https://wa.test/embudo');await page.locator('button[data-whatsapp-contexto]').click();
  const frame=page.frameLocator('dialog iframe');await frame.locator('.whatsapp-chat-header').waitFor();
  assert.equal((await page.locator('dialog').boundingBox()).width,392);
  const metrics=await frame.locator('.whatsapp-chat-column').evaluate(e=>({doc:document.documentElement.scrollHeight,viewport:innerHeight,scroll:document.documentElement.scrollWidth,width:innerWidth,notice:e.querySelector('.whatsapp-window-notice').getBoundingClientRect().height,messages:e.querySelector('.whatsapp-messages').clientHeight}));
  assert(metrics.doc<=metrics.viewport+1&&metrics.scroll<=metrics.width+1,mode+' iframe overflow '+JSON.stringify(metrics));
  assert(metrics.messages>=180&&metrics.notice<=190,mode+' composer dominates '+JSON.stringify(metrics));
  if(mode==='panel-approved'){assert.equal(await frame.locator('details[open]').count(),0);await frame.locator('summary').click();await frame.locator('.wa-template-preview p').waitFor({state:'visible'});}
  await frame.locator('body').evaluate(()=>document.fonts.ready);await page.screenshot({path:path.join(artifacts,mode+'.png')});
  await page.setViewportSize({width:390,height:460});
  assert(await frame.locator('body').evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'small panel overflow');
  assert(await frame.locator('body').evaluate(()=>document.documentElement.scrollHeight<=innerHeight+1),'small panel vertical overflow');
  await page.setViewportSize({width:1366,height:850});
 }
 assert.equal(sends,0);assert.deepEqual(errors,[]);console.log('PASS diseño: panel compacto y móvil, avisos sin expansión, etiquetas en una línea, ficha estable, formularios, búsqueda, enlaces con acción explícita y cero mensajes enviados.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
