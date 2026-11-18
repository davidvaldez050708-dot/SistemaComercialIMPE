// Interfaz de actividades: campos por etapa, CSRF, doble envío y refresco.
const assert=require('node:assert/strict');
const path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const origen='http://acciones-v3.test';
const tablero=path.join(__dirname,'../public/javascript/embudo_comercial_v3_operativo.js');
const fixture=require('./fixtures/seguimiento_acciones_v3_html.cjs');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||undefined});
 try{
  for(const modo of ['PROGRAMAR','REPROGRAMAR']) for(const etapa of ['NUEVO','CONTACTADO','POTENCIAL','INTERESADO','SEGUIMIENTO','PROCESO_INSCRIPCION']){
   const page=await browser.newPage();let guardados=0,navegaciones=0,documentos=0;
   page.on('framenavigated',frame=>{if(frame===page.mainFrame())documentos++;});
   await page.addInitScript(()=>{
    window.IMPE_CURRENT_USER_ID=22;window.IMPE_CSRF_TOKEN='fixture';
    window.IMPE_TELEPHONY_PERSISTENT={getState:()=>({active:false})};
   });
   await page.route(origen+'/**',async route=>{
    const req=route.request(),url=new URL(req.url());
    if(url.pathname==='/tablero.js')return route.fulfill({path:tablero});
    if(url.pathname.endsWith('.css') || url.pathname==='/bootstrap.js')return route.fulfill({body:''});
    if(url.pathname==='/index.php'){
     assert.equal(url.searchParams.get('action'),'seguimientoOperativoV3');
     assert.equal(req.method(),'POST');assert.equal(req.headers()['x-csrf-token'],'fixture');
     const b=new URLSearchParams(req.postData());
     assert.equal(b.get('etapa_actual'),etapa);assert.equal(b.get('operacion'),modo);
     assert.equal(b.get('fecha'),'2099-01-02T10:00');assert.equal(b.get('version'),'7');
     guardados++;
     await new Promise(r=>setTimeout(r,150));
     return route.fulfill({json:{ok:true}});
    }
    navegaciones++;return route.fulfill({body:fixture(etapa,modo),contentType:'text/html'});
   });
   await page.goto(origen);
   await page.locator('[data-v3-actividad]').click();
   const sub=page.locator('[data-actividad-subetapa]');
   assert.equal(await sub.isVisible(),etapa==='SEGUIMIENTO');
   await page.locator('[name=operacion]').selectOption(modo);
   if(modo==='PROGRAMAR')await page.locator('[name=tipo_actividad]').selectOption('LLAMADA');
   if(modo==='REPROGRAMAR'){
    assert.equal(await page.locator('[name=fecha]').inputValue(),'2099-01-01T10:00');
    await page.locator('[name=motivo]').fill('Sin cambio real de cita.');
    await page.locator('#impe-v3o-actividad-dialog [type=submit]').click();
    assert.equal(await page.locator('#impe-v3o-actividad-dialog[open]').count(),1);
    assert.match(await page.locator('[data-actividad-error]').innerText(),/fecha y hora son las mismas/);
    assert.equal(guardados,0);assert.equal(navegaciones,1);
    assert.equal(await page.locator('.system-toast.show').count(),0);
   }
   assert.equal(await sub.isVisible(),['CONTACTADO','POTENCIAL','INTERESADO','SEGUIMIENTO'].includes(etapa));
   await page.locator('[name=fecha]').fill('2099-01-02T10:00');
   await page.locator('[name=motivo]').fill('Próxima gestión acordada.');
   await page.locator('#impe-v3o-actividad-dialog [type=submit]').dblclick();
   await page.waitForFunction(()=>!document.querySelector('#impe-v3o-actividad-dialog').open);
   await page.locator('.system-toast.show').waitFor();
   assert.equal(guardados,1);assert.equal(navegaciones,2);assert.equal(documentos,1);
   console.log('CHECK_OK interfaz actividades',modo,etapa);await page.close();
  }
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
