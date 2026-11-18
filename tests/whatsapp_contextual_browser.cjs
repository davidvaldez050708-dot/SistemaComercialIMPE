const assert=require('node:assert/strict');
const fs=require('node:fs');const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const root=path.resolve(__dirname,'..'),render=process.env.IMPE_WA_RENDER_DIR;
const read=rel=>fs.readFileSync(path.join(root,rel),'utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});
 try{
  const context=await browser.newContext({viewport:{width:1366,height:850}});
  const state={opens:0,sends:0,denied:false,pending:false,hold:null,csrf:[],fail:false};const errors=[];
  const outer=`<!doctype html><meta charset="utf-8"><style>${read('public/css/whatsapp_contextual.css')}</style>
  <main style="min-height:1500px;background:#f5f7fa;padding:24px"><h1>Embudo comercial</h1><button id="board" onclick="window.boardClicks=(window.boardClicks||0)+1">Acción del tablero</button><button id="a" data-whatsapp-contexto="prospecto" data-whatsapp-id="1">WhatsApp A</button><button id="b" data-whatsapp-contexto="seguimiento" data-whatsapp-id="10">WhatsApp B</button></main>
  <script>window.IMPE_CSRF_TOKEN='browser-fixture-csrf-only'</script><script data-whatsapp-contextual data-base-url="https://wa.test/">${read('public/javascript/whatsapp_contextual.js')}</script>`;
  await context.route('**/*',async route=>{
   const req=route.request(),url=new URL(req.url());
   if(url.hostname==='cdn.jsdelivr.net'){
    if(url.pathname.includes('bootstrap.min.css'))return route.fulfill({contentType:'text/css',body:fs.readFileSync(path.join(process.env.BOOTSTRAP_MODULE_PATH,'dist/css/bootstrap.min.css'),'utf8')});
    return route.fulfill({body:'',contentType:'text/css'});
   }
   if(url.hostname!=='wa.test')return route.fulfill({body:''});
   if(url.pathname.startsWith('/public/'))return route.fulfill({body:read(url.pathname.slice(1)),contentType:url.pathname.endsWith('.js')?'text/javascript':'text/css'});
   const action=url.searchParams.get('action');
   if(action==='contexto'){
    state.opens++;assert.equal(req.method(),'POST');assert(req.postData().includes('browser-fixture-csrf-only'));
    if(state.hold)await state.hold;
    return route.fulfill({json:{ok:true,url:'https://wa.test/index.php?controller=whatsapp&action=panel&conversacion_id=1'}});
   }
   if(action==='panel')return route.fulfill({contentType:'text/html',body:fs.readFileSync(path.join(render,state.pending?'pendiente.html':'panel.html'),'utf8')});
   if(action==='mensajes')return route.fulfill({status:state.denied?403:200,json:state.denied?{ok:false,mensaje:'Tu acceso cambió.'}:{ok:true,mensajes:[],estados_salida:[],ventana_servicio_abierta:true}});
   if(action==='enviar'){
    state.sends++;state.csrf.push(req.headers()['x-csrf-token']);await new Promise(r=>setTimeout(r,180));
    return route.fulfill({status:state.fail?502:200,json:{ok:!state.fail,mensaje:state.fail?'Meta rechazó el envío de prueba.':'Envío de fixture confirmado.'}});
   }
   return route.fulfill({contentType:'text/html',body:outer});
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await page.goto('https://wa.test/embudo');await page.click('#a');
  const frame=page.frameLocator('dialog iframe');
  await frame.locator('[data-whatsapp-message-input]').fill('Borrador conservado');
  assert.equal(state.sends,0,'abrir sólo prepara el contexto');
  assert.equal(await page.locator('dialog').count(),1);
  const position=await page.locator('dialog').boundingBox();assert.equal(position.width,460);assert(position.height<=680 && position.y>=20);
  assert.equal(await page.locator('dialog').evaluate(el=>el.matches(':modal')),false);
  await page.click('#board');assert.equal(await page.evaluate(()=>window.boardClicks),1,'tablero accesible mientras se consulta el chat');
  await frame.locator('.whatsapp-messages').evaluate(el=>{
   const sample=el.querySelector('.whatsapp-message');
   for(let i=0;i<45;i++){const copy=sample.cloneNode(true);copy.querySelector('p').textContent='Mensaje de prueba para comprobar lectura y desplazamiento '+i;el.append(copy);}
  });
  const sizes=await frame.locator('.whatsapp-messages').evaluate(el=>({scroll:el.scrollHeight,client:el.clientHeight,font:parseFloat(getComputedStyle(el.querySelector('p')).fontSize),doc:document.documentElement.scrollHeight,viewport:innerHeight,body:document.body.scrollHeight}));
  assert(sizes.scroll>sizes.client && sizes.font>=14);
  assert(sizes.doc<=sizes.viewport+1 && sizes.body<=sizes.viewport+1,'sólo el historial desborda dentro del chat');
  assert(await page.locator('dialog').evaluate(el=>el.scrollHeight<=el.clientHeight+1),'ventana exterior sin segundo scroll');
  if(process.env.IMPE_WA_DESKTOP_SCREENSHOT)await page.screenshot({path:process.env.IMPE_WA_DESKTOP_SCREENSHOT});
  await frame.locator('[data-whatsapp-message-input]').press('Escape');
  assert.equal(page.url(),'https://wa.test/embudo');await page.waitForFunction(()=>!document.querySelector('iframe'));
  await page.click('#a');await frame.locator('[data-whatsapp-message-input]').waitFor();
  assert.equal(await frame.locator('[data-whatsapp-message-input]').inputValue(),'Borrador conservado');
  await frame.locator('[data-whatsapp-send-form]').evaluate(el=>{el.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));el.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));});
  await frame.locator('[data-whatsapp-message-input]').evaluate(async el=>{for(let i=0;i<100&&el.value;i++)await new Promise(r=>setTimeout(r,20));});
  assert.equal(state.sends,1);assert.equal(state.csrf[0],'browser-fixture-csrf-only');
  state.fail=true;await frame.locator('[data-whatsapp-message-input]').fill('Mensaje rechazado');
  await frame.locator('[data-whatsapp-send-button]').click();await frame.locator('[data-whatsapp-composer-feedback]').filter({hasText:'Meta rechazó'}).waitFor();
  assert.equal(await frame.locator('[data-whatsapp-message-input]').inputValue(),'Mensaje rechazado');
  state.denied=true;
  await frame.locator('[data-whatsapp-message-input]').evaluate(async el=>{for(let i=0;i<300&&!el.disabled;i++)await new Promise(r=>setTimeout(r,20));});
  assert(await frame.locator('[data-whatsapp-message-input]').isDisabled());assert.equal(await frame.locator('.whatsapp-message').count(),0);
  await page.locator('dialog button[aria-label="Cerrar conversación"]').click();
  state.denied=false;state.pending=true;await page.click('#b');await frame.locator('[role=status]').filter({hasText:'pendiente de configuración'}).waitFor();
  assert.equal(await frame.locator('[data-whatsapp-send-form]').count(),0);
  await page.locator('dialog button[aria-label="Cerrar conversación"]').click();
  await page.setViewportSize({width:390,height:844});await page.click('#a');await frame.locator('[data-whatsapp-module]').waitFor();
  const mobile=await page.locator('dialog').boundingBox();assert(mobile.width<=390 && mobile.x>=0 && mobile.height<=680);
  await page.setViewportSize({width:390,height:460});
  assert(await page.locator('dialog').evaluate(el=>el.scrollHeight<=el.clientHeight+1));
  const mobileSizes=await frame.locator('[data-whatsapp-module]').evaluate(el=>({doc:document.documentElement.scrollHeight,viewport:innerHeight,root:el.scrollHeight,height:el.clientHeight}));
  assert(mobileSizes.doc<=mobileSizes.viewport+1 && mobileSizes.root<=mobileSizes.height+1,'pantalla baja sin doble scroll');
  await page.setViewportSize({width:390,height:844});
  if(process.env.IMPE_WA_SCREENSHOT)await page.screenshot({path:process.env.IMPE_WA_SCREENSHOT,fullPage:true});
  assert.deepEqual(errors,[]);console.log('CHECK_OK navegador WhatsApp: ventana flotante compacta y móvil, texto legible, un scroll y tablero accesible, mismo módulo, borradores, CSRF, doble clic, error real visible, revocación de acceso, configuración pendiente y cero envíos al abrir.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
