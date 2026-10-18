// Interfaz PHP real, navegador real, sin llamadas ni credenciales externas.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
const repo=path.resolve(__dirname,'..');
const html=cp.execFileSync('php',[path.join(__dirname,'fixtures/telefonia_flujo_shell.php')],{encoding:'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 try{
  const page=await browser.newPage({viewport:{width:1366,height:900}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  let calls=[],saveFails=true,saveCount=0,lastBody=null,transferBody='',availability=null;
  await page.route('**/*',async route=>{
   const u=new URL(route.request().url());
   if(u.hostname!=='llamadas.test')return route.abort();
   if(u.searchParams.get('action')==='destinosTransferencia')return route.fulfill({json:{ok:true,estados:[{id:17,nombre:'Morelos'}],destinos:[{usuario_id:8,extension:'101',estado_id:17,estado_nombre:'Morelos',nombre:'Analista',rol:'Analista de Datos'}],identificacion:[{seguimiento_id:99,responsable_id:8,extension:'101',estado_id:17,estado_nombre:'Morelos',nombre:'Institución sugerida',responsable:'Analista'}]}});
   if(u.searchParams.get('action')==='disponibilidad')return route.fulfill({json:{ok:true,solicitud:Number(new URLSearchParams(route.request().postData()||'').get('id'))>0?availability:null,entrante:null,devoluciones:0}});
   if(u.searchParams.get('action')==='autorizarTransferencia'){transferBody=route.request().postData();availability={id:1,version:1,estado:'PENDIENTE',referencia:'pbx-real-001',destino_extension:'101',numero:'5551234567',segundos:45};return route.fulfill({json:{ok:true,solicitud:availability}});}
   if(u.pathname.endsWith('/avisos_llamadas.php'))return route.fulfill({json:{ok:true,extension:'100',llamadas:calls}});
   if(u.pathname.endsWith('/resultado_recepcion_marketing.php')){
    saveCount++;lastBody=route.request().postData();
    if(saveFails)return route.fulfill({status:500,json:{ok:false,mensaje:'No se pudo guardar. Inténtalo nuevamente.'}});
    for(const c of calls)if(c.requiere_resultado)c.requiere_resultado=false;
    return route.fulfill({json:{ok:true,registro:{resultado:'CONTACTO'}}});
   }
   if(u.pathname.startsWith('/public/javascript/'))return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(path.join(repo,u.pathname),'utf8')});
   if(u.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
   return route.fulfill({contentType:'text/html',body:'<!doctype html><title>CRM</title>'});
  });
  await page.goto('http://llamadas.test/');
  await page.waitForFunction(()=>window.IMPE_CALL_CLOSURE && window.IMPE_TELEPHONY_SHELL);
  const push=(phase,active,id='pbx-real-001',extra={})=>page.evaluate(({phase,active,id,extra})=>{
   const state={userId:7,updatedAt:Date.now(),hostReady:true,active,phase,direction:'incoming',destination:'5551234567',pbxCallId:id,callToken:id,duration:phase==='in-progress'||!active?20:0,transferFrom:'100',...extra};
   localStorage.setItem('impe:telephony:state:7',JSON.stringify(state));
   window.dispatchEvent(new StorageEvent('storage',{key:'impe:telephony:state:7',newValue:JSON.stringify(state)}));
  },{phase,active,id,extra});
  await push('incoming-ringing',true);
  const panel=page.locator('[data-incoming-panel]');await panel.waitFor({state:'visible'});
  assert.equal(await page.locator('[data-incoming-origin]').textContent(),'Extensión 100');
  assert.equal(await page.locator('[data-incoming-origin-label]').textContent(),'Transferida desde:');
  const box=await panel.boundingBox();assert.ok(Math.abs(box.x+box.width/2-683)<2 && Math.abs(box.y+box.height/2-450)<2,'modal centrado al recibir');
  await push('in-progress',true);
  await page.locator('[data-shell-transfer-toggle]').click();
  await page.getByRole('button',{name:'No corresponde a estas instituciones'}).click();
  await page.locator('[data-routing-destination]').selectOption('8:101:17');await page.locator('[data-routing-search]').fill('Otra institución');
  await page.locator('[data-routing-attended]').click();
  await page.waitForFunction(()=>document.querySelector('[data-availability-source-title]').textContent.includes('Esperando confirmación'));
  assert.match(transferBody,/seguimiento_id=0/,'el teléfono compartido no obliga a atribuir un expediente');
  const help=await page.locator('.impe-routing-help').evaluate(e=>({font:parseFloat(getComputedStyle(e).fontSize),height:e.getBoundingClientRect().height}));
  assert.ok(help.font<=12 && help.height<80,'ayuda compacta');
  await push('in-progress',true,'pbx-real-001',{context:{transferRequest:{id:1,mode:'CONSULTADA',stage:'TIMBRANDO',extension:'101'}}});
  assert.equal(await page.locator('[data-shell-transfer-toggle]').isDisabled(),true,'una operación en curso bloquea nuevas transferencias');
  assert.equal(await page.locator('[data-shell-transfer-panel]').isVisible(),false,'la ficha se cierra al enviar la solicitud');
  assert.equal(await page.locator('[data-shell-transfer-toggle]').isDisabled(),true,'cerrar la ficha no elimina el bloqueo');
  await push('in-progress',true,'pbx-real-001',{context:{transferRequest:{id:1,mode:'CONSULTADA',stage:'CONSULTANDO',extension:'101'}}});
  assert.equal(await page.locator('[data-transfer-complete]').isVisible(),true);
  assert.match(await page.locator('[data-transfer-stage]').textContent(),/Completar transferencia/);
  await push('in-progress',true,'pbx-real-001',{context:{transferRequest:{id:1,mode:'CONSULTADA',stage:'DEVUELTA',extension:'101'}}});
  assert.match(await page.locator('[data-transfer-stage]').textContent(),/Retoma la conversación/);
  assert.equal(await page.locator('[data-transfer-complete]').isVisible(),false);
  await push('in-progress',true);
  calls=[{id:'pbx-real-001',aliases:[],numero:'5551234567',tipo:'Entrante',fecha_ts:Date.now()-30000,fin_ts:Date.now(),finalizada:true,atendida:true,requiere_resultado:true,transferida:false,perdida:false}];
  await push('incoming-finished',false);
  const modal=page.locator('[data-call-result-required]');await modal.waitFor({state:'visible'});
  if(process.env.FLUJO_SCREENSHOT)await page.screenshot({path:process.env.FLUJO_SCREENSHOT});
  await page.keyboard.press('Escape');assert.equal(await modal.isVisible(),true,'Escape no omite resultado obligatorio');
  await modal.locator('select').selectOption('CONTACTO');await modal.locator('textarea').fill('Atendió Ana');
  await modal.locator('button[type=submit]').click();
  await page.waitForFunction(()=>document.querySelector('[data-result-feedback]').textContent.includes('No se pudo guardar'));
  assert.equal(await modal.locator('textarea').inputValue(),'Atendió Ana','error conserva captura');
  assert.equal(await page.evaluate(()=>window.IMPE_CALL_CLOSURE.hasPending()),true);
  for(const size of [{width:390,height:600},{width:740,height:360},{width:1366,height:900}]){
   await page.setViewportSize(size);
   const bounds=await modal.boundingBox();assert.ok(bounds.y>=0 && bounds.y+bounds.height<=size.height+1,'modal dentro de pantalla');
   const footer=await modal.locator('.system-form-modal-footer').boundingBox();assert.ok(footer.y+footer.height<=size.height+1,'Guardar accesible');
  }
  await push('incoming-ringing',true,'pbx-next-002');await modal.waitFor({state:'hidden'});
  assert.equal(await panel.isVisible(),true,'entrante prioritaria puede contestarse');
  await push('incoming-finished',false,'pbx-next-002',{duration:0});await modal.waitFor({state:'visible'});
  assert.equal(await modal.locator('textarea').inputValue(),'Atendió Ana','nueva llamada no borra resultado anterior');
  saveFails=false;await modal.locator('button[type=submit]').click();await modal.waitFor({state:'hidden'});
  assert.equal(saveCount,2);assert.ok(lastBody.includes('test-csrf')&&lastBody.includes('pbx-real-001'),'CSRF y llamada exacta');
  // Una transición conocida como perdida se cierra automáticamente al llegar su registro.
  calls.push({id:'pbx-next-002',aliases:[],numero:'5551234567',tipo:'Entrante',fecha_ts:Date.now(),fin_ts:Date.now(),finalizada:true,atendida:false,requiere_resultado:false,perdida:true,transferida:false});
  await page.evaluate(()=>window.IMPE_CALL_CLOSURE.showPending());
  assert.equal(await page.locator('[data-missed-calls]').count(),0,'las perdidas pertenecen a la campana, sin aviso flotante permanente');
  assert.equal(await modal.isVisible(),false,'perdida no pide resultado');
  await push('dialing',true,'pbx-out-003',{direction:'outgoing',context:{type:'TELEPHONY_MANUAL'}});
  assert.equal(await page.locator('[data-incoming-title]').textContent(),'Marcando…');
  await push('ringing',true,'pbx-out-003',{direction:'outgoing',context:{type:'TELEPHONY_MANUAL'}});
  assert.equal(await page.locator('[data-incoming-title]').textContent(),'Timbrando…');
  calls.push({id:'pbx-out-003',aliases:[],numero:'5551234567',tipo:'Saliente',fecha_ts:Date.now(),fin_ts:Date.now(),finalizada:true,atendida:true,requiere_resultado:false,perdida:false,transferida:true,destino:'101'});
  await push('finished',false,'pbx-out-003',{direction:'outgoing',status:'transferred',context:{type:'TELEPHONY_MANUAL'}});
  await page.waitForFunction(()=>!window.IMPE_CALL_CLOSURE.hasPending());
  assert.equal(await modal.isVisible(),false,'transferida no pide resultado manual');
  // Persistencia de una conversación pendiente al recargar la sesión.
  await push('incoming-ringing',true,'pbx-reload-004');
  calls.push({id:'pbx-reload-004',aliases:[],numero:'5551234567',tipo:'Entrante',fecha_ts:Date.now(),fin_ts:Date.now(),finalizada:true,atendida:true,requiere_resultado:true,perdida:false,transferida:false});
  await push('incoming-finished',false,'pbx-reload-004');await modal.waitFor({state:'visible'});
  await modal.locator('textarea').fill('Conservar nota al recargar');
  await page.reload();await modal.waitFor({state:'visible'});
  assert.ok((await modal.textContent()).includes('5551234567'),'pendiente recuperado después de recargar');
  assert.equal(await modal.locator('textarea').inputValue(),'Conservar nota al recargar','captura del resultado recuperada al recargar');
  assert.deepEqual(errors,[]);
  console.log('Flujo real de interfaz: origen, centro, progreso, resultado obligatorio, reintento, entrante prioritaria, perdida, transferencia y recarga: OK');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
