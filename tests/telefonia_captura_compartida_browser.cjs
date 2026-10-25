const fs=require('node:fs'),cp=require('node:child_process'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const read=p=>fs.readFileSync(p,'utf8'),bootstrap=process.env.BOOTSTRAP_MODULE_PATH;
assert(bootstrap,'Define BOOTSTRAP_MODULE_PATH para usar el toast institucional real');
const view=`<!doctype html><style>${read(path.join(bootstrap,'dist/css/bootstrap.min.css'))}${read('public/css/dashboard.css')}</style><main class="admin-shell">CRM</main><script>${read(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'))}</script><script>${read('public/javascript/telefonia_atencion.js')}</script>`;
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});try{
 const styles=[];
 for(const sales of [false,true]){
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));let saves=0,capture={version:0,valores:{nombre:'Diana',telefono:'+527775551234',correo:'',programa:'',notas:''}};
  let html=cp.execFileSync(process.env.PHP_BIN||'php',[sales?'tests/fixtures/telefonia_ventas_shell.php':'tests/fixtures/telefonia_flujo_shell.php',...(sales?[]:['analista'])],{encoding:'utf8'});
  const deps=path.dirname(bootstrap),font=name=>fs.readFileSync(name).toString('base64');
  const faces=[400,500,600,700,800].map(weight=>`@font-face{font-family:Manrope;font-style:normal;font-weight:${weight};src:url(data:font/woff2;base64,${font(path.join(deps,'@fontsource/manrope/files/manrope-latin-'+weight+'-normal.woff2'))}) format('woff2');}`).join('');
  const icons=read(path.join(deps,'bootstrap-icons/font/bootstrap-icons.css')).replace(/url\([^)]*bootstrap-icons\.woff2[^)]*\)/g,'url(data:font/woff2;base64,'+font(path.join(deps,'bootstrap-icons/font/fonts/bootstrap-icons.woff2'))+')');
  html=html.replace('</head>','<style>'+faces+icons+'</style></head>');
  await page.route('**/*',route=>{const u=new URL(route.request().url()),action=u.searchParams.get('action');if(u.pathname.startsWith('/public/javascript/'))return route.fulfill({contentType:'application/javascript',body:read(u.pathname.slice(1))});
   if(action==='datosProspectoVenta'){if(route.request().method()==='POST'){const p=Object.fromEntries(new URLSearchParams(route.request().postData()));assert.equal(p.pbx_call_id,'pbx-shared-123');assert.equal(p.extension,'150');assert(p.csrf_token);saves++;capture={version:saves,valores:Object.fromEntries(['nombre','telefono','correo','programa','notas'].map(k=>[k,p[k]]))};}return route.fulfill({json:{ok:true,captura:capture}});}
   if(action==='host')return route.fulfill({contentType:'text/html',body:'<body>Motor conservado</body>'});
   if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:html});if(action==='disponibilidad')return route.fulfill({json:{ok:true,entrante:null,devoluciones:0}});
   if(action||u.pathname.endsWith('.php'))return route.fulfill({contentType:'text/html',body:view});return route.abort();});
  await page.goto(sales?'http://ventas.test/':'http://llamadas.test/');await page.waitForFunction(()=>IMPE_TELEPHONY_SHELL);
  await page.evaluate(()=>{const state={userId:8,updatedAt:Date.now(),hostReady:true,active:true,phase:'in-progress',direction:'incoming',pbxCallId:'pbx-shared-123',callToken:'sdk-shared-123',destination:'+527775551234',context:{type:'SDK_INCOMING',transferReference:'pbx-shared-123'}};localStorage.setItem('impe:telephony:state:8',JSON.stringify(state));dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:8',newValue:JSON.stringify(state)}));});
  const trigger=page.locator(sales?'[data-sales-prospect]':'[data-shell-capture-toggle]');
  await trigger.waitFor({state:'visible'});
  const phoneBefore=await page.locator('[data-incoming-panel]').boundingBox();
  await trigger.click();const panel=page.locator(sales?'[data-sales-prospect-dialog]':'[data-shell-capture-panel]');await panel.waitFor({state:'visible'});
  assert(await panel.evaluate(el=>el.parentElement===document.body),'La captura debe salir del panel telefónico');
  assert.equal(await panel.evaluate(el=>getComputedStyle(el).position),'fixed');
  assert.deepEqual(await page.locator('[data-incoming-panel]').boundingBox(),phoneBefore,'Abrir captura no redimensiona ni desplaza el teléfono');
  styles.push(await panel.evaluate(el=>{const selectors=['.impe-routing-heading strong','.impe-routing-field label','.impe-routing-field input','.impe-routing-save'];return selectors.map(selector=>{const s=getComputedStyle(el.querySelector(selector));return [s.fontFamily,s.fontSize,s.padding,s.borderRadius,s.backgroundColor];});}));
  for(const width of [1366,768,390]){
   await page.setViewportSize({width,height:800});
   await panel.locator('[type=submit]').scrollIntoViewIfNeeded();
   const box=await panel.locator('[type=submit]').boundingBox(),pane=await panel.boundingBox();
   assert(box.x>=0&&box.x+box.width<=width+1);
   assert(pane.y>=0&&pane.y+pane.height<=801,'La ficha cabe en el viewport');
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
   if(width===1366){const phone=await page.locator('[data-incoming-panel]').boundingBox();assert(pane.x+pane.width<=phone.x||pane.x>=phone.x+phone.width,'En escritorio la ficha queda al lado del teléfono');}
   if(process.env.IMPE_CAPTURE_SCREENSHOTS){fs.mkdirSync(process.env.IMPE_CAPTURE_SCREENSHOTS,{recursive:true});await page.screenshot({path:path.join(process.env.IMPE_CAPTURE_SCREENSHOTS,(sales?'ventas':'analista')+'-'+width+'.png')});}
  }
  await page.evaluate(()=>{window.__captureViewport=Object.getOwnPropertyDescriptor(window,'visualViewport');Object.defineProperty(window,'visualViewport',{configurable:true,value:{width:390,height:350,offsetTop:0,offsetLeft:0}});dispatchEvent(new Event('resize'));});
  const reduced=await panel.boundingBox();assert(reduced.y>=0&&reduced.y+reduced.height<=351,'La captura se ajusta al teclado móvil');
  await panel.locator('[type=submit]').scrollIntoViewIfNeeded();assert(await panel.locator('[type=submit]').isVisible());
  await page.evaluate(()=>{Object.defineProperty(window,'visualViewport',__captureViewport);dispatchEvent(new Event('resize'));});
  if(sales){
   const engine=await page.locator('[data-shell-engine]').getAttribute('src');
   assert.deepEqual(await panel.locator('input,textarea').evaluateAll(els=>els.map(el=>el.name)),['nombre','telefono','correo','programa','notas']);
   await panel.locator('[name=notas]').fill('Borrador antes de guardar');
   await page.keyboard.press('Escape');assert(await panel.isHidden());assert.equal(await trigger.getAttribute('aria-expanded'),'false');
   await trigger.click();await panel.waitFor({state:'visible'});assert.equal(await panel.locator('[name=notas]').inputValue(),'Borrador antes de guardar');
   await panel.locator('[name=notas]').fill('Nota sin terminar llamada');await panel.locator('[type=submit]').click();
   await page.frameLocator('#impeCrmFrame').locator('.system-toast.show').filter({hasText:'Datos guardados'}).waitFor();
   assert.equal(saves,1);assert(await page.evaluate(()=>IMPE_TELEPHONY_SHELL.getPhoneStatus().active));
   assert.equal(await page.locator('[data-shell-engine]').getAttribute('src'),engine);
   await panel.locator('[data-sales-prospect-close]').click();assert.equal(await trigger.getAttribute('aria-expanded'),'false');
   await trigger.click();await panel.waitFor({state:'visible'});assert.equal(await panel.locator('[name=notas]').inputValue(),'Nota sin terminar llamada');
   await page.setViewportSize({width:1366,height:800});
   await trigger.click();assert(await panel.isHidden(),'El botón alterna apertura/cierre como en Analista');
  }
  assert.deepEqual(errors,[]);await page.close();
 }
 assert.deepEqual(styles[0],styles[1],'Analista y Ventas comparten contenedor, tipografía, campos y botón');
 console.log('Captura compartida: CSS idéntico, responsive, guardado sin colgar y toast institucional real: OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
