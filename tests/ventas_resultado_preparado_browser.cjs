const {chromium}=require('playwright'),assert=require('node:assert/strict'),path=require('node:path'),fs=require('node:fs');
const base=process.env.IMPE_TEST_URL,fixture=process.env.IMPE_TEST_FIXTURE;
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_BINARY||'C:/Program Files/Google/Chrome/Application/chrome.exe'});
 try{
  for(const viewport of [{width:1366,height:900},{width:390,height:844}]){
   const page=await browser.newPage({viewport});const errors=[];page.on('pageerror',error=>errors.push(error.message));
   await page.route('https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js',r=>r.fulfill({contentType:'text/javascript',path:path.join(fixture,'assets/bootstrap.js')}));
   let complete;const pending=new Promise(resolve=>complete=resolve);
   await page.route('**/index.php?controller=telefonia&action=detalleVenta*',async route=>{await pending;await route.fulfill({json:{ok:true,llamada:{pbx_call_id:'out-browser-call-151',extension:'151',usuario_id:15,direccion:'Saliente',fecha:'2026-10-28 10:00:00',numero:'5555555555',nombre:'Prueba',finalizada:true,conectada:false,estado_pbx:'Cancelada',registro:null}}});});
   await page.goto(base+'/');await page.waitForFunction(()=>Boolean(window.IMPE_SALES_RESULT_FORM));
   assert.equal(await page.locator('#modalResultadoVenta').count(),1);
   await page.evaluate(()=>window.IMPE_SALES_RESULT_FORM.open({ref:'out-browser-call-151',extension:'151',sessionKey:'sdk-browser-session',automatic:true,direction:'outgoing'}));
   await page.locator('#modalResultadoVenta.show').waitFor({state:'visible',timeout:1000});
   assert.equal(await page.locator('[data-sales-submit]').isDisabled(),true);
   await page.locator('#salesNote').fill('Notas escritas antes de responder el servidor');
   complete();await page.waitForFunction(()=>!document.querySelector('[data-sales-submit]').disabled);
   assert.equal(await page.locator('#salesNote').inputValue(),'Notas escritas antes de responder el servidor');
   assert.equal(await page.locator('#salesResult option[value="CONVERSACION_PERSONA"]').isDisabled(),true);
   assert.equal(await page.locator('#salesResult option[value="BUZON_VOZ"]').isDisabled(),false);
   await page.locator('#salesResult').selectOption('CANCELADA_POR_ASESOR');await page.locator('#salesNote').fill('');
   assert.equal(await page.locator('#salesNote').evaluate(el=>el.checkValidity()),false);
   await page.locator('#salesNote').fill('Marqué un número equivocado.');
   assert.equal(await page.locator('#salesNote').evaluate(el=>el.checkValidity()),true);
   // Un consumidor que impida cerrar no puede dejar bloqueado el siguiente formulario.
   await page.evaluate(()=>{
    const el=document.getElementById('modalResultadoVenta');
    el.addEventListener('hide.bs.modal',e=>e.preventDefault(),{once:true});
    bootstrap.Modal.getInstance(el).hide();
   });
   assert.equal(await page.locator('#modalResultadoVenta').isVisible(),true);
   assert.equal(await page.evaluate(()=>window.IMPE_SALES_RESULT_FORM.open({ref:'out-browser-call-151',extension:'151',sessionKey:'sdk-browser-session',automatic:true})),true);
   await page.waitForFunction(()=>!document.querySelector('[data-sales-submit]').disabled);
   const color=await page.locator('[data-sales-submit]').evaluate(el=>getComputedStyle(el).backgroundColor);assert.notEqual(color,'rgba(0, 0, 0, 0)');
   const box=await page.locator('#modalResultadoVenta .modal-content').boundingBox();assert(box.x>=0&&box.x+box.width<=viewport.width+1);
   const output=path.resolve(__dirname,'../tmp/ventas-resultado-inmediato-'+viewport.width+'.png');await page.screenshot({path:output,fullPage:true});
   assert.deepEqual(errors,[]);await page.close();
  }
  console.log('PASS: Chrome real en escritorio y móvil, modal visible con HTTP pendiente, observaciones conservadas, opciones coherentes y motivo obligatorio; sin errores JS.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
