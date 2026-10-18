// Vista PHP y Bootstrap reales; telefonía y red simuladas, sin llamadas reales.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const repo=path.resolve(__dirname,'..');const read=p=>fs.readFileSync(path.join(repo,p),'utf8');
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.dirname(require.resolve('bootstrap/package.json'));
const view=cp.execFileSync('php',[path.join(__dirname,'fixtures/analista_atencion_vista.php')],{encoding:'utf8'});
const css=['global.css','dashboard.css','marketing_selectores_uniformes.css','seguimiento_llamadas_expediente.css','seguimiento_llamadas_player.css','seguimiento_entrantes_analista.css','responsive_sistema.css'].map(p=>read('public/css/'+p)).join('\n');
const html=`<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'))}\n${css}</style></head><body><main class="admin-content">${view}</main><script>${fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'))}</script><script>${read('public/javascript/selectores_uniformes.js')}</script><script>${read('public/javascript/telefonia_atencion.js')}</script><script>${read('public/javascript/seguimiento_entrantes_analista.js')}</script></body></html>`;
(async()=>{const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});try{
 const page=await browser.newPage({viewport:{width:1366,height:900}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 let mode='error',release=null,saves=0,draftPosts=0,lastBody='',slowDraft=null,missingRequests=0;
 await page.route('http://atencion.test/**',async route=>{
   const req=route.request(),u=new URL(req.url());
   if(u.pathname.endsWith('borrador_llamada.php')){
      if(req.method()==='POST'){draftPosts++;return route.fulfill({json:{ok:true}});}
      if(slowDraft){await new Promise(r=>{slowDraft=r;});return route.fulfill({json:{ok:true,borrador:{saved_at:Date.now(),valores:{persona_atendio:'Respuesta vieja'}}}});}
      return route.fulfill({json:{ok:true,borrador:null}});
   }
   if(u.searchParams.get('action')==='buscarEntrantesSeguimientos')return route.fulfill({json:{ok:true,resultados:[{id:1,nombre_entidad:'Institución Uno',estado_nombre:'Morelos'}]}});
   if(u.searchParams.get('action')==='registrarLlamadaEntrante'){
      saves++;lastBody=req.postData();assert.match(lastBody,/test-csrf/);
      if(mode==='wait')await new Promise(r=>release=r);
      if(mode==='success')return route.fulfill({json:{ok:true,resultado:{seguimiento_id:1,verificacion_confirmada:true}}});
      return route.fulfill({status:422,json:{ok:false,mensaje:'La llamada ya fue registrada. Actualiza el historial.'}});
   }
   const focus=u.searchParams.get('autofocus');
   if(focus==='missing')missingRequests++;
   const focused=focus ? html.replace('data-auto-call=""','data-auto-call="'+(focus==='missing'?'sdk-missing-12345678':'sdk-confirmed-12345678')+'"') : html;
   return route.fulfill({contentType:'text/html',body:focused});
 });
 await page.goto('http://atencion.test/');
 const missed=page.locator('[data-incoming-row="transferencia-1-1"]');
 assert.match(await missed.textContent(),/Transferencia sin respuesta/);
 assert.match(await missed.textContent(),/Sin respuesta/);
 assert.equal(await missed.locator('button').count(),0,'sin respuesta no pide resultado ni ofrece audio');
 const modal=page.locator('#modalLlamadaEntranteAnalista'),form=modal.locator('form');
 const opened=()=>page.waitForFunction(()=>document.querySelector('#modalLlamadaEntranteAnalista.show')&&document.activeElement?.name==='decision');
 const closed=()=>page.waitForFunction(()=>!document.querySelector('.modal-backdrop'));
 const first=page.locator('[data-call-id="pbx-confirmed-001"]');
 await page.evaluate(()=>{
    sessionStorage.setItem('impe:crm:transfer-context:1:sdk-confirmed-12345678',JSON.stringify({call_id:'sdk-confirmed-12345678',seguimiento_id:1,institucion:'Institución Uno',estado:'Morelos',saved_at:Date.now()-600000}));
    sessionStorage.setItem('impe:crm:call-summary:1:sdk-confirmed-12345678','resumen de esta llamada');
    sessionStorage.setItem('impe:crm:call-summary:1:sdk-other-12345678','resumen de otra llamada');
 });
 await first.click();await opened();
 assert.equal(await form.locator('[data-incoming-confirmed]').isVisible(),true,'conserva contexto de Recepción de hace 10 minutos');
 assert.equal(await form.locator('[name=seguimiento_id]').inputValue(),'1','contexto exacto por alias SDK de una tarjeta PBX');
 assert.equal(await form.locator('[name=pbx_call_id]').inputValue(),'pbx-confirmed-001');
 assert.equal(await form.locator('[name=sdk_reference]').inputValue(),'sdk-confirmed-12345678');
 assert.equal(await modal.locator('.uniform-select').count(),0,'listas de Seguimiento sin menú que desborde');
 await form.locator('[name=decision]').selectOption('nueva');
 await form.locator('[name=nombre_entidad]').fill('Institución nueva');
 await form.locator('[name=estado_id]').selectOption('1');
 await form.locator('[name=resultado]').selectOption('CONTACTO_REFERIDO');
 await form.locator('[name=persona_atendio]').fill('Ana');
 await form.locator('[name=nuevo_contacto_nombre]').fill('Luis');
 await form.locator('[name=nuevo_contacto_cargo]').fill('Compras');
 for(const size of [{width:1366,height:900},{width:820,height:460},{width:390,height:600},{width:740,height:360}]){
    await page.setViewportSize(size);await modal.locator('.modal-body').evaluate(e=>e.scrollTop=0);
    const b=await modal.evaluate(e=>{const r=s=>{const b=e.querySelector(s).getBoundingClientRect();return{top:b.top,bottom:b.bottom,left:b.left,right:b.right}};return{header:r('.modal-header'),footer:r('.modal-footer'),overflow:e.scrollHeight>e.clientHeight};});
    assert.ok(b.header.top>=0&&b.footer.bottom<=size.height+1,`cabecera y Guardar accesibles ${size.width}x${size.height}`);
    assert.equal(b.overflow,false,'el modal entero no desborda');
    await modal.locator('.modal-body').hover();await page.mouse.wheel(0,500);
    await page.waitForFunction(()=>document.querySelector('#modalLlamadaEntranteAnalista .modal-body').scrollTop>0);
    const f=await modal.locator('.modal-footer').boundingBox();assert.equal(Math.round(f.y),Math.round(b.footer.top),'pie fijo al desplazar con rueda');
    if(process.env.ATENCION_BROWSER_OUTPUT){fs.mkdirSync(process.env.ATENCION_BROWSER_OUTPUT,{recursive:true});await page.screenshot({path:path.join(process.env.ATENCION_BROWSER_OUTPUT,`atencion-${size.width}x${size.height}.png`)});}
 }
 await page.setViewportSize({width:1366,height:900});
 await form.locator('[data-incoming-submit]').click();
 await page.locator('.system-toast-error').waitFor({state:'visible'});
 assert.equal(await form.locator('[name=nuevo_contacto_nombre]').inputValue(),'Luis','error conserva captura');
 assert.equal(await modal.evaluate(e=>e.classList.contains('show')),true);
 mode='wait';await form.locator('[data-incoming-submit]').click();await page.waitForFunction(()=>document.querySelector('[data-incoming-submit]').disabled);
 await page.keyboard.press('Escape');assert.equal(await modal.evaluate(e=>e.classList.contains('show')),true,'Escape no interrumpe guardado');
 await form.dispatchEvent('submit');assert.equal(saves,2,'doble envío bloqueado');
 assert.ok(release);release();await page.waitForFunction(()=>!document.querySelector('[data-incoming-submit]').disabled);
 await modal.locator('.btn-close').click();await closed();assert.equal(await first.evaluate(e=>document.activeElement===e),true,'devuelve foco');
 assert.ok(draftPosts>0,'autoguarda captura');
 await first.click();await opened();await page.waitForFunction(()=>document.querySelector('[name=nuevo_contacto_nombre]').value==='Luis');
 await modal.locator('.btn-close').click();await closed();
 // Una respuesta tardía al recuperar el borrador jamás pisa lo que ya escribió el analista.
 slowDraft=true;await page.locator('[data-call-id="sdk-pending-12345678"]').click();await opened();
 await form.locator('[name=decision]').selectOption('nueva');await form.locator('[name=persona_atendio]').fill('Captura actual');
 assert.equal(typeof slowDraft,'function');slowDraft();slowDraft=null;
 await page.waitForTimeout(80);assert.equal(await form.locator('[name=persona_atendio]').inputValue(),'Captura actual');
 await form.locator('[name=resultado]').selectOption('BUZON_VOZ');
 assert.equal(await form.locator('[name=confirmacion_manual_sdk]').evaluate(e=>e.required),false,'buzón no pide conversación humana');
 await form.locator('[name=resultado]').selectOption('CONTACTO_CORRECTO');assert.equal(await form.locator('[name=confirmacion_manual_sdk]').evaluate(e=>e.required),true);
 await modal.locator('.btn-close').click();await closed();
 assert.equal(await page.locator('[data-confirm-verification]').count(),1,'confirmación tardía explícita disponible');
 // El borrador de una saliente se importa al formulario sólo por referencia exacta.
 await page.evaluate(async()=>{
   const f=document.createElement('form');for(const name of ['persona_atendio','observacion','nuevo_contacto_nombre']){const i=document.createElement('input');i.name=name;f.append(i);}document.body.append(f);f.id='outgoing-draft-test';
   window.IMPE_CURRENT_USER_ID=1;window.IMPE_CALL_DRAFTS.localSave(1,['out-draft-00001'],{persona:'Ana',notas:'Dato obtenido',contacto_nombre:'Luis'});
   document.dispatchEvent(new CustomEvent('impe:call-result-form-opened',{detail:{form:f,ids:['out-draft-00001']}}));
 });
 await page.waitForFunction(()=>document.querySelector('#outgoing-draft-test [name=persona_atendio]').value==='Ana');
 assert.equal(await page.locator('#outgoing-draft-test [name=nuevo_contacto_nombre]').inputValue(),'Luis');
 mode='success';await first.click();await opened();
 await form.locator('[name=decision]').selectOption('nueva');await form.locator('[name=resultado]').selectOption('CONTACTO_REFERIDO');
 await form.locator('[data-incoming-submit]').click();
 await page.waitForURL(/action=detalle&id=1/);
 assert.match(lastBody,/pbx-confirmed-001/,'envía fuente PBX confirmada');
 assert.match(lastBody,/sdk-confirmed-12345678/,'conserva alias WebRTC al guardar');
 assert.equal(await page.locator('.modal-backdrop').count(),0,'navega tras cerrar el modal');
 assert.equal(await page.evaluate(()=>sessionStorage.getItem('impe:crm:transfer-context:1:sdk-confirmed-12345678')),null,'limpia el contexto guardado');
 assert.equal(await page.evaluate(()=>sessionStorage.getItem('impe:crm:call-summary:1:sdk-confirmed-12345678')),null,'limpia el resumen de esta llamada');
 assert.equal(await page.evaluate(()=>sessionStorage.getItem('impe:crm:call-summary:1:sdk-other-12345678')),'resumen de otra llamada','conserva los datos de otra llamada');
 await page.goto('http://atencion.test/?autofocus=found&impe_retry=1');await opened();
 assert.equal(new URL(page.url()).searchParams.has('impe_retry'),false,'limpia la marca de reintento al encontrar el alias SDK');
 await modal.locator('.btn-close').click();await closed();
 await page.goto('http://atencion.test/?autofocus=missing');
 await page.waitForURL(/impe_retry=1/);
 await page.getByText('La llamada se recibió, pero su registro aún no está disponible.',{exact:false}).waitFor();
 await page.waitForTimeout(1700);
 assert.equal(missingRequests,2,'un único reintento por URL, sin bucle de recargas');
 assert.deepEqual(errors,[],'sin errores de JavaScript');
 console.log('Atención analista/navegador: formularios, borradores, errores, doble envío, foco y scroll en 4 tamaños OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
