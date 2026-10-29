const {chromium}=require('playwright'),assert=require('node:assert/strict'),path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BINARY||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  for(const viewport of [{width:1366,height:900},{width:390,height:844},{width:320,height:568},{width:844,height:390}]){
   const page=await browser.newPage({viewport}),errors=[],requests=[];page.setDefaultTimeout(7000);page.on('pageerror',e=>errors.push(e.message));
   let variant='normal',delayDetail=0,delayNotes=0;
   const call=key=>({persona:key==='venta:2'?'Persona Segunda':'Yazmin Sintética',numero:'5550000000',nombre:'Prospecto de prueba',extension:'151',fecha:'2026-10-29 08:19:45',direccion:'Saliente',resultado_texto:'Prospecto potencial · Se envió por WhatsApp',detalle_resultado:'',observacion:'Se solicita información sobre el programa.\nSe acordó volver a llamar.'});
   await page.route('**/*action=supervisionDetalle*',async route=>{
    const key=new URL(route.request().url()).searchParams.get('llamada'),mode=variant,wait=delayDetail;requests.push(['detail',key]);
    if(wait)await new Promise(r=>setTimeout(r,wait));
    if(mode==='forbidden')return route.fulfill({status:403,json:{ok:false}});
    const data=call(key);
    if(mode==='empty'){data.nombre='';data.observacion='';data.resultado_texto='Comunicación interrumpida · habló con una persona';data.detalle_resultado='El prospecto pidió que se le llamara después.';}
    if(mode==='long'){data.persona='Persona '.repeat(25);data.nombre='<img src=x onerror="window.INJECTED=true">';data.observacion=('Nota extensa '+ 'X'.repeat(180)+'\n').repeat(15);}
    return route.fulfill({json:{ok:true,llamada:data}});
   });
   await page.route('**/*action=observacionesVenta*',async route=>{
    const key=new URL(route.request().url()).searchParams.get('llamada'),mode=variant,wait=delayNotes;requests.push(['notes',key]);
    if(wait)await new Promise(r=>setTimeout(r,wait));
    if(mode==='notesError')return route.fulfill({status:503,json:{ok:false}});
    if(mode==='unavailable')return route.fulfill({json:{ok:true,disponible:false}});
    return route.fulfill({json:{ok:true,disponible:true,observaciones:mode==='empty'?[]:[{autor:'Asesor de prueba',fecha:'2026-10-29 09:20:00',nota:key==='venta:2'?'Nota Segunda':'Información complementaria.\nSe conserva la observación original.'}]}});
   });
   await page.route('**/*action=supervisionAudio*',r=>r.fulfill({json:{ok:true,disponible:true,segmentos:1,segmentos_info:[]}}));
   await page.goto(process.env.IMPE_TEST_URL);await page.evaluate(()=>document.fonts.ready);
   assert(!/Warning:|Fatal error|Parse error/.test(await page.locator('body').textContent()));
   const dialog=page.getByRole('dialog'),first=page.locator('[data-call-key="venta:1"] [data-supervision-detail]'),second=page.locator('[data-call-key="venta:2"] [data-supervision-detail]'),analyst=page.locator('[data-call-key="analista:3"] [data-supervision-detail]');
   const loaded=()=>page.getByText('Información complementaria.',{exact:false}).waitFor();
   const close=async()=>{await dialog.getByRole('button',{name:'Cerrar',exact:true}).click();await dialog.waitFor({state:'hidden'});};
   await first.click();await loaded();
   assert.equal(await dialog.locator('.supervision-detail-result').textContent(),'Prospecto potencial · Se envió por WhatsApp');
   assert((await dialog.textContent()).includes('29/10/2026 · 08:19:45'));
   assert.equal(await dialog.locator('input,select,textarea').count(),0,'Consulta sin edición');
   assert.equal(await dialog.locator('.supervision-detail-field').count(),4);
   assert.equal(await dialog.locator('.supervision-detail-note').count(),1);
   const bounds=await dialog.boundingBox();assert(bounds.x>=7&&bounds.y>=7&&bounds.x+bounds.width<=viewport.width-7&&bounds.y+bounds.height<=viewport.height-7);
   assert(await dialog.evaluate(e=>e.scrollWidth<=e.clientWidth),'Sin desbordamiento horizontal');
   for(const button of await dialog.locator('[data-supervision-close]').all()){
    const box=await button.boundingBox();assert(box.y>=bounds.y&&box.y+box.height<=bounds.y+bounds.height,'Cerrar visible');
   }
   assert((await dialog.locator('.system-form-modal-title').evaluate(e=>getComputedStyle(e).fontFamily)).includes('Manrope'));
   await dialog.screenshot({path:path.join(process.env.IMPE_TEST_OUT,'modal-'+viewport.width+'.png')});
   await dialog.locator('.supervision-detail-body').evaluate(e=>{e.scrollTop=e.scrollHeight;});
   assert(await dialog.locator('.supervision-detail-note').evaluate(e=>{const body=e.closest('.supervision-detail-body').getBoundingClientRect(),box=e.getBoundingClientRect();return box.bottom<=body.bottom+1;}),'Notas accesibles al desplazar el cuerpo');
   if(viewport.width===390)await dialog.screenshot({path:path.join(process.env.IMPE_TEST_OUT,'modal-390-notas.png')});
   await close();assert(await first.evaluate(e=>e===document.activeElement),'Restaura foco');
   variant='empty';await first.click();await dialog.getByText('Sin observaciones adicionales.',{exact:true}).waitFor();
   assert.equal(await dialog.locator('.supervision-detail-result').textContent(),'Comunicación interrumpida · habló con una persona','No dividir etiquetas históricas');
   assert((await dialog.textContent()).includes('Sin observaciones registradas.'));await page.keyboard.press('Escape');await dialog.waitFor({state:'hidden'});
   variant='long';await first.click();await loaded();assert.equal(await dialog.locator('img').count(),0);assert.equal(await page.evaluate(()=>window.INJECTED),undefined);
   assert(await dialog.evaluate(e=>e.scrollWidth<=e.clientWidth));await dialog.screenshot({path:path.join(process.env.IMPE_TEST_OUT,'modal-largo-'+viewport.width+'.png')});await close();
   variant='normal';const count=requests.filter(r=>r[0]==='notes').length;await analyst.click();await dialog.locator('.supervision-detail-result').waitFor();
   assert.equal(requests.filter(r=>r[0]==='notes').length,count,'Analista no solicita notas de ventas');assert(!(await dialog.textContent()).includes('Observaciones adicionales'));await dialog.getByRole('button',{name:'Cerrar detalle'}).click();
   variant='forbidden';await first.click();await dialog.getByText('Ya no tienes permiso para consultar esta llamada.').waitFor();assert.equal(await dialog.locator('.supervision-detail-summary').count(),0);await close();
   for(const mode of ['notesError','unavailable']){variant=mode;await first.click();await dialog.getByText(mode==='notesError'?'No se pudo consultar la información. Vuelve a intentarlo.':'Las observaciones adicionales aún no están instaladas.',{exact:true}).waitFor();assert.equal(await dialog.locator('.supervision-detail-result').count(),1);await close();}
   variant='normal';delayDetail=300;await first.click();await dialog.getByText('Consultando resultado de la llamada…').waitFor();await close();delayDetail=0;await second.click();await dialog.getByText('Nota Segunda',{exact:true}).waitFor();await page.waitForTimeout(400);assert.equal(await dialog.locator('.supervision-detail-field dd').first().textContent(),'Persona Segunda');await close();
   delayNotes=300;await first.click();await dialog.getByText('Consultando observaciones…',{exact:true}).waitFor();await close();delayNotes=0;await second.click();await dialog.getByText('Nota Segunda',{exact:true}).waitFor();await page.waitForTimeout(400);assert(!(await dialog.textContent()).includes('Información complementaria.'));await close();
   delayDetail=250;await first.click();await close();await page.waitForTimeout(350);assert.equal(await page.locator('dialog[open]').count(),0,'No reabre al llegar una respuesta tardía');delayDetail=0;
   await page.evaluate(()=>{window.IMPE_TELEPHONY_SEGMENTS={create:(panel)=>{const audio=document.createElement('audio');audio.controls=true;panel.append(audio);}};});
   const audio=page.locator('[data-call-key="venta:1"] [data-supervision-audio]');await audio.click();await page.locator('[data-call-key="venta:1"] audio').waitFor();await audio.click();assert.equal(await audio.getAttribute('aria-expanded'),'false');
   const navigation=page.waitForURL('**/*direccion=Saliente*');await page.selectOption('select[name=direccion]','Saliente');await navigation;
   assert.deepEqual(errors,[]);await page.close();
  }
  console.log('PASS: consulta asesores/analistas, dos resultados y etiquetas antiguas, notas, errores, Escape/foco, respuestas tardías, audio y filtros; escritorio, móvil y horizontal.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
