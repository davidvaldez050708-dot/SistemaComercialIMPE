const fs=require('node:fs'),cp=require('node:child_process'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const read=p=>fs.readFileSync(p,'utf8'),bootstrap=process.env.BOOTSTRAP_MODULE_PATH;
assert(bootstrap,'Define BOOTSTRAP_MODULE_PATH para usar el toast institucional real');
const view=`<!doctype html><style>${read(path.join(bootstrap,'dist/css/bootstrap.min.css'))}${read('public/css/dashboard.css')}</style><main class="admin-shell">CRM</main><script>${read(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'))}</script><script>${read('public/javascript/telefonia_atencion.js')}</script>`;
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});try{
 const styles=[];
 for(const sales of [false,true]){
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));let saves=0,capture={version:0,valores:{nombre:'Diana',telefono:'+527775551234',correo:'',programa:'',notas:''}};
  const html=cp.execFileSync('php',[sales?'tests/fixtures/telefonia_ventas_shell.php':'tests/fixtures/telefonia_flujo_shell.php',...(sales?[]:['analista'])],{encoding:'utf8'});
  await page.route('**/*',route=>{const u=new URL(route.request().url()),action=u.searchParams.get('action');if(u.pathname.startsWith('/public/javascript/'))return route.fulfill({contentType:'application/javascript',body:read(u.pathname.slice(1))});
   if(action==='datosProspectoVenta'){if(route.request().method()==='POST'){const p=Object.fromEntries(new URLSearchParams(route.request().postData()));assert.equal(p.pbx_call_id,'pbx-shared-123');assert.equal(p.extension,'150');assert(p.csrf_token);saves++;capture={version:saves,valores:Object.fromEntries(['nombre','telefono','correo','programa','notas'].map(k=>[k,p[k]]))};}return route.fulfill({json:{ok:true,captura:capture}});}
   if(action==='host')return route.fulfill({contentType:'text/html',body:'<body>Motor conservado</body>'});
   if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:html});if(action==='disponibilidad')return route.fulfill({json:{ok:true,entrante:null,devoluciones:0}});
   if(action||u.pathname.endsWith('.php'))return route.fulfill({contentType:'text/html',body:view});return route.abort();});
  await page.goto(sales?'http://ventas.test/':'http://llamadas.test/');await page.waitForFunction(()=>IMPE_TELEPHONY_SHELL);
  await page.evaluate(()=>{const state={userId:8,updatedAt:Date.now(),hostReady:true,active:true,phase:'in-progress',direction:'incoming',pbxCallId:'pbx-shared-123',callToken:'sdk-shared-123',destination:'+527775551234',context:{type:'SDK_INCOMING',transferReference:'pbx-shared-123'}};localStorage.setItem('impe:telephony:state:8',JSON.stringify(state));dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(state)}));});
  await page.locator(sales?'[data-sales-prospect]':'[data-shell-capture-toggle]').click();const panel=page.locator(sales?'[data-sales-prospect-dialog]':'[data-shell-capture-panel]');await panel.waitFor({state:'visible'});
  styles.push(await panel.evaluate(el=>{const selectors=['.impe-routing-heading strong','.impe-routing-field label','.impe-routing-field input','.impe-routing-save'];return selectors.map(selector=>{const s=getComputedStyle(el.querySelector(selector));return [s.fontFamily,s.fontSize,s.padding,s.borderRadius,s.backgroundColor];});}));
  for(const width of [1366,768,390]){await page.setViewportSize({width,height:800});await panel.locator('[type=submit]').scrollIntoViewIfNeeded();const box=await panel.locator('[type=submit]').boundingBox();assert(box.x>=0&&box.x+box.width<=width+1);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);}
  if(sales){const engine=await page.locator('[data-shell-engine]').getAttribute('src');await panel.locator('[name=notas]').fill('Nota sin terminar llamada');await panel.locator('[type=submit]').click();await page.frameLocator('#impeCrmFrame').locator('.system-toast.show').filter({hasText:'Datos guardados'}).waitFor();assert.equal(saves,1);assert(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.getPhoneStatus().active));assert.equal(await page.locator('[data-shell-engine]').getAttribute('src'),engine);await panel.locator('[data-sales-prospect-close]').click();await page.locator('[data-sales-prospect]').click();await panel.waitFor({state:'visible'});assert.equal(await panel.locator('[name=notas]').inputValue(),'Nota sin terminar llamada');}
  assert.deepEqual(errors,[]);await page.close();
 }
 assert.deepEqual(styles[0],styles[1],'Analista y Ventas comparten contenedor, tipografía, campos y botón');
 console.log('Captura compartida: CSS idéntico, responsive, guardado sin colgar y toast institucional real: OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
