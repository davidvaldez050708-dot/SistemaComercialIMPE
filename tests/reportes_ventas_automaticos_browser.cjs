const {chromium}=require('playwright'),assert=require('node:assert/strict'),path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  for(const viewport of [{width:1366,height:950},{width:390,height:844}]){
   const page=await browser.newPage({viewport}),errors=[],requests=[];page.setDefaultTimeout(8000);page.on('pageerror',e=>errors.push(e.message));
   page.on('request',r=>{if(r.isNavigationRequest()&&new URL(r.url()).pathname==='/index.php')requests.push(new URL(r.url()));});
   await page.goto(process.env.IMPE_TEST_URL+'shell.php');const frame=page.frames().find(f=>f.url().includes('index.php'));
   const form=()=>frame.locator('[data-sales-activity-form]'),pdf=()=>frame.locator('[data-sales-report-pdf]'),status=()=>frame.locator('[data-sales-activity-status]');
   const settled=async()=>{await form().waitFor();await form().getAttribute('data-sales-auto-initialized');await frame.waitForLoadState('networkidle');assert(!/Warning:|Fatal error|Parse error/.test(await frame.locator('body').textContent()));assert(!((await frame.locator('body').textContent()).includes('Los filtros cambiaron.')));};
   await settled();assert((await frame.locator('body').textContent()).includes('Buzones de voz'));assert.equal(await form().locator('button:visible').count(),0);assert(await pdf().isVisible());
   const initial=requests.length;await page.waitForTimeout(850);assert.equal(requests.length,initial,'No bucle de consultas al entrar');
   // Agrupar cambios consecutivos: una única navegación con el último corte.
   await form().locator('[name=persona_id]').selectOption('8');assert(await pdf().isHidden());
   await form().locator('[name=periodo]').selectOption('ayer');
   await form().locator('[name=resultado]').selectOption('WHATSAPP');
   await page.waitForFunction(()=>document.querySelector('#crmFrame').contentWindow.location.search.includes('resultado=WHATSAPP'));
   await settled();assert.equal(requests.length,initial+1);
   for(const [k,v] of Object.entries({persona_id:'8',periodo:'ayer',resultado:'WHATSAPP'}))assert.equal(new URL(await pdf().getAttribute('href')).searchParams.get(k),v,'PDF usa el corte aplicado');
   assert(!new URL(frame.url()).searchParams.has('fecha_desde'),'Un periodo normal no arrastra las fechas personalizadas ocultas');
   // Cancelar un cambio devolviendo el selector al corte aplicado restaura el PDF.
   const count=requests.length;await form().locator('[name=resultado]').selectOption('SMS');await form().locator('[name=resultado]').selectOption('WHATSAPP');assert(await pdf().isVisible());await page.waitForTimeout(750);assert.equal(requests.length,count);
   // Personalizado: no usar el rango prellenado del periodo anterior ni consultar rangos incompletos.
   await form().locator('[name=periodo]').selectOption('personalizado');assert(await pdf().isHidden());
   assert.equal(await form().locator('[name=fecha_desde]').inputValue(),'');assert.equal(await form().locator('[name=fecha_hasta]').inputValue(),'');
   const until=await form().locator('[name=fecha_hasta]').getAttribute('max');const from=new Date(until+'T00:00:00Z');from.setUTCDate(from.getUTCDate()-2);const first=from.toISOString().slice(0,10);
   await form().locator('[name=fecha_desde]').fill(first);await page.waitForTimeout(850);assert.equal(requests.length,count);assert((await status().textContent()).includes('Selecciona las fechas'));
   await form().locator('[name=fecha_hasta]').fill(until);
   await page.waitForFunction(()=>document.querySelector('#crmFrame').contentWindow.location.search.includes('periodo=personalizado'));
   await settled();assert.equal(requests.length,count+1);
   assert.equal(new URL(await pdf().getAttribute('href')).searchParams.get('fecha_desde'),first);assert.equal(new URL(await pdf().getAttribute('href')).searchParams.get('fecha_hasta'),until);
   // Rango invertido/futuro/excesivo: no enviar una consulta ni permitir PDF del corte anterior.
   let before=requests.length;const future=new Date(until+'T00:00:00Z');future.setUTCDate(future.getUTCDate()+1);
   await form().locator('[name=fecha_desde]').fill(future.toISOString().slice(0,10));await page.waitForTimeout(850);assert.equal(requests.length,before);assert(await pdf().isHidden());
   await form().locator('[name=fecha_desde]').fill('2000-01-01');await page.waitForTimeout(850);assert.equal(requests.length,before);
   await form().locator('[name=fecha_desde]').fill(first);await page.waitForTimeout(700);assert.equal(requests.length,before);assert(await pdf().isVisible());
   // Cambiar tipo también actualiza sin botón.
   await form().locator('[name=direccion]').selectOption('Saliente');await page.waitForFunction(()=>document.querySelector('#crmFrame').contentWindow.location.search.includes('direccion=Saliente'));await settled();assert.equal(requests.length,before+1);
   assert.equal(new URL(await pdf().getAttribute('href')).searchParams.get('direccion'),'Saliente');
   // Volver con Atrás conserva los filtros que corresponden al reporte mostrado.
   await page.evaluate(()=>history.back());await page.waitForFunction(()=>!document.querySelector('#crmFrame').contentWindow.location.search.includes('direccion=Saliente'));await settled();assert.equal(await form().locator('[name=direccion]').inputValue(),'');
   assert.equal(await page.evaluate(()=>window.phoneSentinel.token),'llamada-inalterada');assert(await page.evaluate(()=>window.phoneSentinel.active&&window.hostLoads===1));assert.equal((await browser.contexts()[0].pages()).length,1);
   assert.deepEqual(errors,[]);assert(await frame.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
   await form().screenshot({path:path.join(process.env.IMPE_TEST_AUTO_OUT,'filtros-'+viewport.width+'.png')});await page.close();
  }
  const withoutExport=await browser.newPage();await withoutExport.goto(process.env.IMPE_TEST_URL+'index.php?sin_exportar=1');assert.equal(await withoutExport.locator('[data-sales-report-pdf]').count(),0);
  await withoutExport.locator('[name=periodo]').selectOption('ayer');await withoutExport.waitForURL(/periodo=ayer/);await withoutExport.close();
  // Sin JS permanece una alternativa accesible; se genera inicialmente desde el servidor.
  const context=await browser.newContext({javaScriptEnabled:false});const page=await context.newPage();await page.goto(process.env.IMPE_TEST_URL+'index.php');assert.equal(await page.getByRole('button',{name:'Consultar reporte'}).count(),1);assert((await page.locator('body').textContent()).includes('Buzones de voz'));await context.close();
  console.log('PASS: generación inicial, filtros automáticos, debounce, rangos incompletos/inválidos, PDF coherente, Atrás, escritorio/móvil e iframe sin recargar el host del teléfono.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
