'use strict';
// Saliente 100 -> 150: fin SIP, PBX tardia, formulario inmediato y guardado unico.
// No accede a Zadarma ni a produccion.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const bootstrap=process.env.BOOTSTRAP_MODULE_PATH||path.dirname(require.resolve('bootstrap/package.json'));
const js=fs.readFileSync(path.resolve(__dirname,'../public/javascript/telefonia_ventas_resultados.js'),'utf8');
const bs=fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),'utf8');
const html=ref=>'<html><head><meta charset="utf-8"></head><body>'
 +'<section data-sales-history data-auto-call="'+ref+'" data-auto-extension="150" data-detail-url="/index.php?action=detalleVenta" data-save-url="/index.php?action=guardarResultadoVenta"></section>'
 +'<div class="modal fade" id="modalResultadoVenta" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">'
 +'<form><div class="modal-body"><strong data-sales-form-number></strong><span data-sales-form-state></span><p data-sales-capture-summary hidden></p><div data-sales-fields>'
 +'<input type="hidden" name="csrf_token" value="token"><input type="hidden" name="pbx_call_id"><input type="hidden" name="extension">'
 +'<select name="resultado" required><option value="">Selecciona</option><option value="INTERESADO">Interesado</option></select>'
 +'<input name="prospecto_nombre"><input name="correo"><textarea name="observacion"></textarea>'
 +'<input type="checkbox" name="guardar_contacto" value="1"><input type="datetime-local" name="proxima_accion_at">'
 +'</div><p data-sales-feedback></p></div><div class="modal-footer"><button type="button" data-bs-dismiss="modal">Cerrar</button>'
 +'<button data-sales-submit type="submit">Guardar resultado</button></div></form></div></div></div>'
 +'<script>window.IMPE_CURRENT_USER_ID=8;window.IMPE_TELEPHONY_NOTIFY=(...args)=>{(window.salesNotices||=[]).push(args);};</script>'
 +'<script>'+bs+'</script><script>'+js+'</script></body></html>';
async function scenario(browser,provisional){
 const page=await browser.newPage(),errors=[],ref=provisional?'pbx-provisional-150':'pbx-transferido-150';
 let closed=false,materialized=!provisional,saves=0,requests=0,posted=null;
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://ventas.test/**',route=>{
  const url=new URL(route.request().url()),action=url.searchParams.get('action');
  if(action==='detalleVenta'){
   requests++;
   if(!materialized)return route.fulfill({status:409,json:{ok:false,mensaje:'Tramo pendiente'}});
   return route.fulfill({json:{ok:true,llamada:{pbx_call_id:ref,extension:'150',numero:'5550001234',
    nombre:'Persona transferida',origen_extension:'100',finalizada:closed,conectada:true,
    estado_pbx:closed?'Conectada':'En conversación',registro:null}}});
  }
  if(action==='guardarResultadoVenta'){
   saves++;posted=Object.fromEntries(new URLSearchParams(route.request().postData()));
   assert(closed,'No enviar resultado sin cierre PBX');assert(materialized,'No inventar registro comercial');
   return route.fulfill({json:{ok:true,registro:{resultado:posted.resultado}}});
  }
  return route.fulfill({contentType:'text/html',body:html(url.searchParams.get('pbx_call_id')||'')});
 });
 const address='http://ventas.test/index.php?controller=telefonia&action=misLlamadas&pbx_call_id='+ref+
  '&extension=150'+(provisional?'&esperando_pbx=1':'');
 await page.goto(address);
 const modal=page.locator('#modalResultadoVenta'),feedback=modal.locator('[data-sales-feedback]');
 await modal.waitFor({state:'visible'});
 assert(await modal.locator('[data-sales-fields]').isVisible(),'Campos visibles al terminar SIP sin END PBX');
 assert.equal(await modal.locator('[data-sales-recheck]').count(),0,'No existe Comprobar cierre');
 assert(await modal.locator('[data-sales-submit]').isVisible(),'Guardar permanece visible');
 await modal.locator('[name=resultado]').selectOption('INTERESADO');
 await modal.locator('[name=observacion]').fill('Enviar informacion; registro exacto 150');
 await modal.locator('[name=guardar_contacto]').check();
 await modal.locator('[data-sales-submit]').click();
 await page.waitForFunction(()=>document.querySelector('[data-sales-submit]').disabled);
 assert.equal(saves,0,'No guardar como confirmado sin PBX');
 assert.match(await feedback.textContent(),/todavía no está guardado/i);
 materialized=true;closed=true;
 for(let i=0;i<90&&saves!==1;i++)await new Promise(r=>setTimeout(r,100));
 assert.equal(saves,1,'Solo una accion registra una vez tras cierre');
 assert.equal(posted.pbx_call_id,ref);assert.equal(posted.extension,'150');
 assert.equal(posted.resultado,'INTERESADO');
 assert.equal(posted.observacion,'Enviar informacion; registro exacto 150');
 assert.equal(posted.guardar_contacto,'1');
 assert(requests>=2,'El servidor se reconsulto automaticamente');
 assert.deepEqual(errors,[]);
 await page.close();
}
(async()=>{const browser=await chromium.launch({headless:true});try{
 await scenario(browser,false);await scenario(browser,true);
 console.log('OK: Ventas captura SIP, cierre PBX tardio/409, guardado unico sin segundo modal.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
