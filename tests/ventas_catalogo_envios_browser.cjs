const {chromium}=require('playwright'),assert=require('node:assert/strict'),path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BINARY||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  for(const viewport of [{width:1366,height:900},{width:390,height:844}]){
   const page=await browser.newPage({viewport}),errors=[],saved=new Map(),posts=[];page.on('pageerror',e=>errors.push(e.message));
   await page.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js',r=>r.fulfill({path:path.join(process.env.IMPE_TEST_FIXTURE,'assets/bootstrap.js'),contentType:'text/javascript'}));
   const row=ref=>({pbx_call_id:ref,extension:'151',usuario_id:15,direccion:'Saliente',fecha:'2026-10-28 10:00:00',numero:'5555555555',nombre:'Persona de prueba',finalizada:true,conectada:!ref.includes('no-answer'),estado_pbx:ref.includes('no-answer')?'Sin respuesta':'Conectada',registro:saved.get(ref)||null});
   saved.set('out-legacy-151',{resultado:'INTERESADO',observacion:'Nota original histórica',prospecto_nombre:'Persona de prueba'});
   await page.route('**/index.php?controller=telefonia&action=detalleVenta*',r=>r.fulfill({json:{ok:true,llamada:row(new URL(r.request().url()).searchParams.get('pbx_call_id'))}}));
   await page.route('**/index.php?controller=telefonia&action=guardarResultadoVenta',r=>{
    const data=Object.fromEntries(new URLSearchParams(r.request().postData()));posts.push(data);saved.set(data.pbx_call_id,{...data,resultado_principal:data.resultado});return r.fulfill({json:{ok:true,registro:{pbx_call_id:data.pbx_call_id,extension:data.extension}}});
   });
   await page.goto(process.env.IMPE_TEST_URL+'/');await page.waitForFunction(()=>Boolean(window.IMPE_SALES_RESULT_FORM));
   const open=async ref=>{await page.evaluate(ref=>window.IMPE_SALES_RESULT_FORM.open({ref,extension:'151',sessionKey:ref,automatic:false}),ref);await page.locator('#modalResultadoVenta.show').waitFor({state:'visible'});await page.waitForFunction(()=>document.querySelector('[data-sales-submit]').hidden||!document.querySelector('[data-sales-submit]').disabled);};
   const close=async()=>{await page.locator('#modalResultadoVenta .btn-close').click();await page.locator('#modalResultadoVenta').waitFor({state:'hidden'});};
   await open('out-catalogo-151');
   assert.deepEqual(await page.locator('#salesResult option').evaluateAll(options=>options.map(o=>o.value).filter(Boolean)),['BUZON_VOZ','NUMERO_INEXISTENTE','NO_INTERESADO','PROSPECTO_POTENCIAL','FUERA_PRESUPUESTO','INSCRITO','NO_CUMPLE_REQUISITOS','NO_SOLICITO_INFORMACION','SIGUIENTE_APERTURA','SIN_RESPUESTA','COMUNICACION_INTERRUMPIDA']);
   await page.locator('#salesResult').selectOption('PROSPECTO_POTENCIAL');await page.locator('#salesEnvio').selectOption('WHATSAPP_SMS');
   await page.locator('#salesNote').fill('Se explicaron los programas y se compartió información.');
   assert.equal(await page.locator('#salesWhatsappReason').isVisible(),false);
   await page.locator('[data-sales-submit]').click();await page.locator('#modalResultadoVenta').waitFor({state:'hidden'});
   assert.equal(posts.length,1);assert.equal(posts[0].resultado,'PROSPECTO_POTENCIAL');assert.equal(posts[0].envio_informacion,'WHATSAPP_SMS');assert.equal(posts[0].catalogo_version,'2');
   await open('out-catalogo-151');assert.equal(await page.locator('#salesEnvio').inputValue(),'WHATSAPP_SMS');assert.equal(await page.locator('#salesEnvio').isDisabled(),true);await close();
   await open('out-legacy-151');assert.equal(await page.locator('#salesResult').inputValue(),'INTERESADO');assert.equal(await page.locator('#salesResult option:checked').textContent(),'Prospecto interesado');assert.equal(await page.locator('[data-sales-legacy-result]').isVisible(),true);assert.equal(await page.locator('#salesEnvio').isVisible(),false);assert.equal(await page.locator('#salesNote').inputValue(),'Nota original histórica');await close();
   await open('out-no-answer-151');assert.equal(await page.locator('#salesResult option[value="PROSPECTO_POTENCIAL"]').isDisabled(),true);
   await page.locator('#salesResult').selectOption('COMUNICACION_INTERRUMPIDA');await page.locator('#salesInterrupted').selectOption('SI');
   assert.equal(await page.locator('#salesInterrupted').evaluate(el=>el.checkValidity()),false);
   await page.locator('#salesInterrupted').selectOption('NO');await page.locator('#salesEnvio').selectOption('SMS');
   assert.equal(await page.locator('#salesWhatsappReason').isVisible(),true);assert.equal(await page.locator('#salesWhatsappReason').evaluate(el=>el.checkValidity()),false);
   await page.locator('#salesWhatsappReason').fill('El número no tiene WhatsApp.');await page.locator('#salesNote').fill('Observaciones del intento sin conversación.');
   await close();await page.reload();await page.waitForFunction(()=>Boolean(window.IMPE_SALES_RESULT_FORM));await open('out-no-answer-151');
   assert.equal(await page.locator('#salesEnvio').inputValue(),'SMS');assert.equal(await page.locator('#salesWhatsappReason').inputValue(),'El número no tiene WhatsApp.');assert.equal(await page.locator('#salesInterrupted').inputValue(),'NO');
   await page.locator('#salesResult').selectOption('SIN_RESPUESTA');assert.equal(await page.locator('#salesInterrupted').isVisible(),false);
   const box=await page.locator('#modalResultadoVenta .modal-content').boundingBox();assert(box.x>=0&&box.x+box.width<=viewport.width+1);assert.deepEqual(errors,[]);
   await page.screenshot({path:path.resolve(__dirname,'../tmp/ventas-catalogo-envios-'+viewport.width+'.png'),fullPage:true});await page.close();
  }
  console.log('PASS: Chrome escritorio/móvil, once resultados y envíos separados, POST único, consulta inmutable, histórico legible, excepción y corte validados, borrador conservado sin cambiar apertura/cierre.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
