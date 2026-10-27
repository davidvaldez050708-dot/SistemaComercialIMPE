const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium,webkit}=require('playwright'),root=path.resolve(__dirname,'..'),bootstrap=path.dirname(require.resolve('bootstrap/package.json'));
const read=p=>fs.readFileSync(path.join(root,p),'utf8');
const call={pbx_call_id:'pbx-sales-call-001',extension:'150',numero:'5551112233',nombre:'Prospecto',finalizada:true,conectada:true,estado_pbx:'Conectada',registro:{resultado:'BUZON_VOZ',observacion:'Nota original',prospecto_nombre:'Prospecto'}};
const view=()=>`<!doctype html><html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${fs.readFileSync(path.join(bootstrap,'dist/css/bootstrap.min.css'),'utf8')+['global.css','dashboard.css','telefonia.css','responsive_sistema.css'].map(p=>read('public/css/'+p)).join('\n')}</style><body><script>window.IMPE_CURRENT_USER_ID=8;</script>${cp.execFileSync(process.env.PHP_BINARY||'php',[path.join(root,'tests/fixtures/telefonia_ventas_vista.php')],{encoding:'utf8'})}<script>${fs.readFileSync(path.join(bootstrap,'dist/js/bootstrap.bundle.min.js'),'utf8')}</script><script>${read('public/javascript/telefonia_ventas_resultados.js')}</script></body></html>`;
(async()=>{const browser=await(process.env.SALES_BROWSER==='webkit'?webkit:chromium).launch(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{});
try{
 const page=await browser.newPage(),errors=[],saved=[],tokens=new Map();let fail=false,lastToken='',requests=0,resultSaves=0,saveDelay=0,readDelay=0;
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://ventas.test/**',async route=>{
  const url=new URL(route.request().url()),action=url.searchParams.get('action');
  if(url.pathname.startsWith('/public/javascript/'))return route.fulfill({path:path.join(root,url.pathname)});
  if(action==='detalleVenta')return route.fulfill({json:{ok:true,llamada:call}});
  if(action==='observacionesVenta'){const snapshot=JSON.parse(JSON.stringify(saved));await new Promise(r=>setTimeout(r,readDelay));return route.fulfill({json:{ok:true,disponible:true,observaciones:snapshot}});}
  if(action==='guardarResultadoVenta'){
   resultSaves++;const p=Object.fromEntries(new URLSearchParams(route.request().postData()));assert.equal(p.resultado,'BUZON_VOZ');assert.equal(p.observacion,'Observación inicial de otra llamada');assert.equal(p.nota,undefined);
   call.registro={resultado:p.resultado,observacion:p.observacion};return route.fulfill({json:{ok:true,registro:{resultado:p.resultado}}});
  }
  if(action==='agregarObservacionVenta'){
   requests++;const p=Object.fromEntries(new URLSearchParams(route.request().postData()));
   assert.equal(p.pbx_call_id,call.pbx_call_id);assert.equal(p.extension,'150');assert(p.csrf_token);assert.equal(p.resultado,undefined);assert.equal(p.observacion,undefined);
   if(lastToken)assert.equal(p.token,lastToken,'Reintenta con el mismo token');lastToken=p.token;
   if(!tokens.has(p.token)){const note={id:saved.length+1,autor:'Asesora de prueba',fecha:'26/10/2026 17:15',nota:p.nota};tokens.set(p.token,note);saved.push(note);}
   if(fail){fail=false;return route.fulfill({status:500,json:{ok:false,mensaje:'No se pudo confirmar el guardado. Reintenta sin cambiar la nota.'}});}
   await new Promise(r=>setTimeout(r,saveDelay));return route.fulfill({json:{ok:true,observacion:tokens.get(p.token)}});
  }
  return route.fulfill({contentType:'text/html',body:view()});
 });
 for(const width of [1366,390]){
  await page.setViewportSize({width,height:900});await page.goto('http://ventas.test/');
  await page.locator('[data-sales-detail]').first().evaluate(el=>{el.dataset.salesDetail='pbx-sales-call-001';el.dataset.extension='150';});
  await page.locator('[data-sales-detail]').first().click();
  const modal=page.locator('#modalResultadoVenta');await modal.waitFor({state:'visible'});await modal.locator('[data-sales-note-open]').waitFor();
  assert.equal(await modal.locator('[name=resultado]').inputValue(),'BUZON_VOZ');assert(await modal.locator('[name=resultado]').isDisabled());
  assert.equal(await modal.locator('[name=observacion]').inputValue(),'Nota original');assert(await modal.locator('[name=observacion]').isDisabled());
  assert.equal(await modal.locator('[data-sales-submit]').isVisible(),false);
  await modal.locator('[data-sales-note-open]').click();const textarea=modal.locator('[data-sales-note-text]');assert(await textarea.isEnabled());
  if(width===1366){
   await modal.locator('[data-sales-note-save]').click();assert.equal(requests,0);
   await textarea.fill('Olvidé mencionar lo siguiente.\n<img src=x onerror=alert(1)>');fail=true;
   await modal.locator('[data-sales-note-save]').click();await modal.locator('[data-sales-notes-feedback]').filter({hasText:'Reintenta'}).waitFor();
   assert.equal(await textarea.inputValue(),'Olvidé mencionar lo siguiente.\n<img src=x onerror=alert(1)>');
   await modal.locator('[data-sales-note-save]').click();await modal.getByText('Observación guardada. El resultado permanece igual.').waitFor();
  }else await modal.locator('[data-sales-note-cancel]').click();
  assert.equal(await modal.locator('[data-note-id]').count(),1);assert.equal(await modal.locator('[data-note-id] img').count(),0);
  assert.equal(await modal.locator('[name=resultado]').inputValue(),'BUZON_VOZ');assert.equal(await modal.locator('[name=observacion]').inputValue(),'Nota original');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);
  const header=await modal.locator('.modal-header').boundingBox(),footer=await modal.locator('.modal-footer').boundingBox();assert(header.y>=0&&footer.y+footer.height<=900+1,'Cabecera y cierre accesibles en el viewport');
  if(process.env.SALES_SCREENSHOT_DIR){fs.mkdirSync(process.env.SALES_SCREENSHOT_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.SALES_SCREENSHOT_DIR,'observaciones_'+width+'.png')});}
  await modal.locator('.btn-close').click();await modal.waitFor({state:'hidden'});
 }
 // Cerrar y volver a abrir durante un envío no deja un borrador duplicable.
 await page.locator('[data-sales-detail]').first().click();let activeModal=page.locator('#modalResultadoVenta');await activeModal.locator('[data-sales-note-open]').waitFor();
 await activeModal.locator('[data-sales-note-open]').click();await activeModal.locator('[data-sales-note-text]').fill('Observación en envío');
 lastToken='';saveDelay=1200;await activeModal.locator('[data-sales-note-save]').click();await page.waitForFunction(()=>document.querySelector('[data-sales-note-save]').disabled);
 await activeModal.locator('.btn-close').click();await activeModal.waitFor({state:'hidden'});
 readDelay=1600;await page.locator('[data-sales-detail]').first().click();await activeModal.waitFor({state:'visible'});
 await page.waitForFunction(()=>document.querySelectorAll('[data-note-id]').length===2&&!document.querySelector('[data-sales-note-save]').disabled);
 assert.equal(await activeModal.locator('[data-sales-note-text]').inputValue(),'');assert.equal(saved.length,2);
 await activeModal.locator('.btn-close').click();await activeModal.waitFor({state:'hidden'});
 readDelay=0;saveDelay=0;
 // La captura inicial sigue usando exclusivamente el guardado original.
 call.pbx_call_id='pbx-sales-call-002';call.registro=null;
 await page.locator('[data-sales-detail]').last().click();const modal=page.locator('#modalResultadoVenta');await modal.waitFor({state:'visible'});
 assert(await modal.locator('[data-sales-observaciones]').isHidden());assert(await modal.locator('[name=resultado]').isEnabled());assert(await modal.locator('[name=observacion]').isEnabled());
 await modal.locator('[name=resultado]').selectOption('BUZON_VOZ');await modal.locator('[name=observacion]').fill('Observación inicial de otra llamada');
 await modal.locator('[data-sales-submit]').click();await modal.waitFor({state:'hidden'});
 assert.equal(resultSaves,1);assert.equal(requests,3);assert.deepEqual(errors,[]);
 console.log('Observaciones UI: resultado y nota original bloqueados, anexos, reintento sin duplicación, texto seguro y diseño 1366/390px OK');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
