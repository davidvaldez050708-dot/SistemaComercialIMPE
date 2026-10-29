const {chromium}=require('playwright'),assert=require('node:assert/strict'),path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BINARY||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{for(const viewport of [{width:1366,height:900},{width:390,height:844}]){
  const page=await browser.newPage({viewport}),errors=[],saved=new Map(),posts=[];page.on('pageerror',e=>errors.push(e.message));
  saved.set('out-legacy-151',{resultado:'INTERESADO',observacion:'Nota original histórica'});
  saved.set('out-v2-151',{resultado:'PROSPECTO_POTENCIAL',resultado_principal:'PROSPECTO_POTENCIAL',envio_informacion:'WHATSAPP_SMS',catalogo_version:'2',observacion:'Registro anterior con ambos envíos'});
  saved.set('out-v2-reason-151',{resultado:'SIN_RESPUESTA',resultado_principal:'SIN_RESPUESTA',envio_informacion:'SIN_ENVIO',motivo_sin_whatsapp:'Número sin WhatsApp',catalogo_version:'2',observacion:'Observación conservada'});
  saved.set('out-v2-cut-151',{resultado:'COMUNICACION_INTERRUMPIDA_PERSONA',resultado_principal:'COMUNICACION_INTERRUMPIDA',envio_informacion:'SIN_ENVIO',contacto_antes_corte:'SI',catalogo_version:'2',observacion:'Sí se habló antes del corte'});
  await page.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js',r=>r.fulfill({path:path.join(process.env.IMPE_TEST_FIXTURE,'assets/bootstrap.js'),contentType:'text/javascript'}));
  let release;const pending=new Promise(resolve=>release=resolve);
  await page.route('**/index.php?controller=telefonia&action=detalleVenta*',async r=>{const ref=new URL(r.request().url()).searchParams.get('pbx_call_id');if(ref==='out-pending-151')await pending;
   await r.fulfill({json:{ok:true,llamada:{pbx_call_id:ref,extension:'151',usuario_id:15,direccion:'Saliente',fecha:'2026-10-28 10:00:00',numero:'5555555555',nombre:'Persona de prueba',finalizada:true,conectada:!ref.includes('no-answer'),estado_pbx:ref.includes('no-answer')?'Sin respuesta':'Conectada',registro:saved.get(ref)||null}}});});
  await page.route('**/index.php?controller=telefonia&action=guardarResultadoVenta',r=>{const d=Object.fromEntries(new URLSearchParams(r.request().postData()));posts.push(d);saved.set(d.pbx_call_id,{...d,resultados_seleccionados:JSON.parse(d.resultados_seleccionados)});return r.fulfill({json:{ok:true,registro:{pbx_call_id:d.pbx_call_id,extension:d.extension}}});});
  await page.goto(process.env.IMPE_TEST_URL+'/');await page.waitForFunction(()=>Boolean(window.IMPE_SALES_RESULT_FORM));
  const open=async(ref,wait=true)=>{await page.evaluate(ref=>window.IMPE_SALES_RESULT_FORM.open({ref,extension:'151',sessionKey:ref,automatic:false}),ref);await page.locator('#modalResultadoVenta.show').waitFor({state:'visible'});if(wait)await page.waitForFunction(()=>document.querySelector('[data-sales-submit]').hidden||!document.querySelector('[data-sales-submit]').disabled);};
  const close=async()=>{await page.locator('#modalResultadoVenta .btn-close').click();await page.locator('#modalResultadoVenta').waitFor({state:'hidden'});};
  const choose=async(code,checked=true)=>{const menu=page.locator('[data-sales-result-options]');if(!await menu.isVisible())await page.locator('#salesResultTrigger').click();await page.locator('[data-sales-result-choice][value="'+code+'"]').setChecked(checked);};
  const selected=()=>page.locator('[data-sales-result-choice]:checked').evaluateAll(nodes=>nodes.map(n=>n.value));
  await open('out-pending-151',false);assert.equal(await page.locator('[data-sales-submit]').isDisabled(),true);await page.locator('#salesNote').fill('Escrito antes de confirmar');release();await page.waitForFunction(()=>!document.querySelector('[data-sales-submit]').disabled);
  assert.equal(await page.locator('#salesNote').inputValue(),'Escrito antes de confirmar');assert.equal(await page.locator('[data-sales-result-choice]').count(),13);assert.equal(await page.locator('#salesEnvio,#salesWhatsappReason').count(),0);
  await choose('PROSPECTO_POTENCIAL');await choose('WHATSAPP');assert.equal(await page.locator('[data-sales-result-choice][value="SMS"]').isDisabled(),true);
  await page.locator('#salesResultTrigger').click();await page.locator('[data-sales-submit]').click();await page.locator('#modalResultadoVenta').waitFor({state:'hidden'});
  assert.equal(posts.length,1);assert.equal(posts[0].catalogo_version,'3');assert.deepEqual(JSON.parse(posts[0].resultados_seleccionados),['PROSPECTO_POTENCIAL','WHATSAPP']);assert(!('motivo_sin_whatsapp' in posts[0]));
  await open('out-pending-151');assert.deepEqual(await selected(),['PROSPECTO_POTENCIAL','WHATSAPP']);assert.equal(await page.locator('#salesResultTrigger').isDisabled(),true);await close();
  await open('out-v2-151');assert.deepEqual(await selected(),['PROSPECTO_POTENCIAL','WHATSAPP','SMS']);assert.equal(await page.locator('[data-sales-legacy-result]').isVisible(),true);await close();
  await open('out-v2-reason-151');assert.equal(await page.locator('[data-sales-previous-reason]').textContent(),'Motivo registrado anteriormente: Número sin WhatsApp');await close();
  await open('out-v2-cut-151');assert.equal(await page.locator('#salesInterrupted').isVisible(),true);assert.equal(await page.locator('#salesInterrupted').inputValue(),'SI');assert.equal(await page.locator('#salesInterrupted').isDisabled(),true);await close();
  await open('out-legacy-151');assert.deepEqual(await selected(),['INTERESADO']);assert.equal(await page.locator('[data-sales-result-text]').textContent(),'Prospecto interesado');assert.equal(await page.locator('#salesNote').inputValue(),'Nota original histórica');await close();
  await open('out-no-answer-151');assert.equal(await page.locator('[data-sales-result-choice][value="PROSPECTO_POTENCIAL"]').isDisabled(),true);await choose('SIN_RESPUESTA');
  await page.locator('#salesResultTrigger').click();await page.locator('[data-sales-submit]').click();await page.locator('#modalResultadoVenta').waitFor({state:'hidden'});
  assert.deepEqual(JSON.parse(posts[1].resultados_seleccionados),['SIN_RESPUESTA']);assert.equal(posts.length,2);
  await page.evaluate(()=>sessionStorage.setItem('impe:sales-result-draft:15:out-old-draft-151',JSON.stringify({at:Date.now(),values:{resultado:'PROSPECTO_POTENCIAL',envio_informacion:'WHATSAPP_SMS',observacion:'Borrador V2 conservado'}})));
  await open('out-old-draft-151');assert.equal((await selected()).length,3);assert.equal(await page.locator('#salesNote').inputValue(),'Borrador V2 conservado');
  await choose('SMS',false);await page.locator('#salesResultTrigger').click();await close();await page.reload();await page.waitForFunction(()=>Boolean(window.IMPE_SALES_RESULT_FORM));await open('out-old-draft-151');
  assert.deepEqual(await selected(),['PROSPECTO_POTENCIAL','WHATSAPP']);assert.equal(await page.locator('#salesNote').inputValue(),'Borrador V2 conservado');
  const box=await page.locator('#modalResultadoVenta .modal-content').boundingBox();assert(box.x>=0&&box.x+box.width<=viewport.width+1);assert.deepEqual(errors,[]);
  await page.screenshot({path:path.resolve(__dirname,'../tmp/ventas-lista-doble-'+viewport.width+'.png'),fullPage:true});await page.locator('#salesResultTrigger').click();
  const menuBox=await page.locator('[data-sales-result-options]').boundingBox();assert(menuBox.x>=0&&menuBox.x+menuBox.width<=viewport.width+1);
  await page.screenshot({path:path.resolve(__dirname,'../tmp/ventas-lista-doble-menu-'+viewport.width+'.png'),fullPage:true});await page.close();
 }console.log('PASS: lista única con 13 opciones, máximo dos, guardado de una sin motivo, HTTP pendiente, lectura V2/legado, tres históricos preservados, borrador y notas en escritorio/móvil.');}
 finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
